<?php
session_start();
// 配置文件路径
$configFile = __DIR__ . '/config/config.php';

// 如果配置文件存在，跳转到 index.php 初始化
if (file_exists($configFile)) {
    header('Location: index.php');
    exit;
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $dbHost = trim($_POST['db_host']);
    $dbUser = trim($_POST['db_user']);
    $dbPass = trim($_POST['db_pass']);
    $dbName = trim($_POST['db_name']);
    $adminUser = trim($_POST['admin_user']);
    $adminPass = trim($_POST['admin_pass']);
    $adminPassConfirm = trim($_POST['admin_pass_confirm']);

    if (!$dbHost || !$dbUser || !$dbName || !$adminUser || !$adminPass || !$adminPassConfirm) {
        $error = "请填写完整信息";
    } elseif ($adminPass !== $adminPassConfirm) {
        $error = "两次输入的初始管理员密码不一致";
    } else {
        try {
            // 连接 MySQL（先不选数据库）
            $pdo = new PDO("mysql:host=".$dbHost.";charset=utf8mb4", $dbUser, $dbPass);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            // 创建数据库
            $pdo->exec("CREATE DATABASE IF NOT EXISTS `".$dbName."` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $pdo->exec("USE `".$dbName."`");

            // 创建表 devices
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS devices (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    device_id VARCHAR(255) NOT NULL UNIQUE,
                    server VARCHAR(255) DEFAULT NULL,
                    expire_at DATE DEFAULT NULL,
                    remark VARCHAR(255) DEFAULT NULL,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    last_login DATETIME DEFAULT NULL
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
            ");

            // 创建表 multi_repos
            $pdo->exec("
                -- 多仓配置表
				CREATE TABLE duocang_data (
					id INT AUTO_INCREMENT PRIMARY KEY,
					ServerName VARCHAR(255) UNIQUE NOT NULL,
					repos LONGTEXT NOT NULL,
					attach_local TINYINT(1) NOT NULL DEFAULT 0 COMMENT '是否附加本地资源',
					created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
					updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
				) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
            ");

            // 创建表 users
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS users (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    username VARCHAR(100) NOT NULL UNIQUE,
                    password VARCHAR(255) NOT NULL,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
            ");
			
			$pdo->exec("
			CREATE TABLE IF NOT EXISTS repos (
				id INT AUTO_INCREMENT PRIMARY KEY,
				name VARCHAR(255) NOT NULL UNIQUE,
				url TEXT NOT NULL,
				created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
				updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
			");
			
			$pdo->exec("
			CREATE TABLE local_files (
				id INT AUTO_INCREMENT PRIMARY KEY,
				filename VARCHAR(255) UNIQUE NOT NULL,
				created_at DATETIME DEFAULT CURRENT_TIMESTAMP
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
			");

            // 插入初始管理员
            $passwordHash = password_hash($adminPass, PASSWORD_BCRYPT);
            $stmt = $pdo->prepare("INSERT INTO users (username, password) VALUES (:username, :password)");
            $stmt->execute(array(':username'=>$adminUser, ':password'=>$passwordHash));

            // 写入 config 文件
            $configContent = "<?php\nreturn array(\n"
                . "    'db_host' => '".addslashes($dbHost)."',\n"
                . "    'db_user' => '".addslashes($dbUser)."',\n"
                . "    'db_pass' => '".addslashes($dbPass)."',\n"
                . "    'db_name' => '".addslashes($dbName)."'\n"
                . ");\n";

            if (!is_dir(__DIR__.'/config')) mkdir(__DIR__.'/config', 0755, true);
            file_put_contents(__DIR__.'/config/config.php', $configContent);
			
			if (!is_dir("local_repo")) {mkdir("local_repo", 0755, true);}
            // 初始化完成后直接跳转 index.php
            header("Location: download.php");
            exit;

        } catch (PDOException $e) {
            $error = "数据库错误: " . $e->getMessage();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="zh">
<head>
<meta charset="UTF-8">
<title>系统初始化</title>
<style>
body { font-family: "Segoe UI", Arial, sans-serif; display:flex; justify-content:center; align-items:center; height:100vh; background:#f9fafc; }
.box { background:#fff; padding:30px 50px; border-radius:10px; box-shadow:0 3px 12px rgba(0,0,0,0.15); width:400px; }
h2 { text-align:center; margin-bottom:20px; color:#2c3e50; }
input { width:100%; padding:10px; margin-bottom:15px; border-radius:6px; border:1px solid #ccc; box-sizing:border-box; }
button { width:100%; padding:10px; border:none; border-radius:6px; background:#4CAF50; color:#fff; font-size:15px; cursor:pointer; }
button:hover { opacity:0.9; }
.error { color:#f44336; margin-bottom:10px; text-align:center; }
.success { color:#4CAF50; margin-bottom:10px; text-align:center; }
</style>
</head>
<body>
<div class="box">
<h2>系统初始化</h2>
<?php if($error) echo "<div class='error'>$error</div>"; ?>
<?php if($success) echo "<div class='success'>$success</div>"; ?>
<form method="post">
<input type="text" name="db_host" placeholder="数据库地址" value="<?php echo isset($_POST['db_host'])?htmlspecialchars($_POST['db_host']):'localhost'; ?>" required>
<input type="text" name="db_user" placeholder="数据库用户名" value="<?php echo isset($_POST['db_user'])?htmlspecialchars($_POST['db_user']):''; ?>" required>
<input type="password" name="db_pass" placeholder="数据库密码" value="<?php echo isset($_POST['db_pass'])?htmlspecialchars($_POST['db_pass']):''; ?>">
<input type="text" name="db_name" placeholder="数据库名称" value="<?php echo isset($_POST['db_name'])?htmlspecialchars($_POST['db_name']):''; ?>" required>
<hr>
<input type="text" name="admin_user" placeholder="初始管理员用户名" value="<?php echo isset($_POST['admin_user'])?htmlspecialchars($_POST['admin_user']):'admin'; ?>" required>
<input type="password" name="admin_pass" placeholder="初始管理员密码" required>
<input type="password" name="admin_pass_confirm" placeholder="确认管理员密码" required>
<button type="submit">初始化系统</button>
</form>
</div>
</body>
</html>
