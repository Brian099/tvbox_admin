<?php
session_start();

// 配置文件路径
$configFile = __DIR__ . '/config/config.php';

$error = '';
$success = '';
$dbName = '';
$config = [];

if (file_exists($configFile)) {
    $config = require $configFile;
    $dbName = $config['db_name'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $deleteDb   = isset($_POST['delete_db']) ? true : false;
    $deleteRepo = isset($_POST['delete_repo']) ? true : false;
    $inputPass  = trim($_POST['db_pass']);

    if (!file_exists($configFile)) {
        $error = "未找到配置文件，可能系统尚未初始化。";
    } elseif ($inputPass === '') {
        $error = "请填写数据库密码";
    } else {
        try {
            // 使用用户输入的密码尝试连接
            $pdo = new PDO(
                "mysql:host=" . $config['db_host'] . ";charset=utf8mb4",
                $config['db_user'],
                $inputPass
            );
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            if ($deleteDb) {
                $pdo->exec("DROP DATABASE `" . $config['db_name'] . "`");
            } else {
                $pdo->exec("USE `" . $config['db_name'] . "`");
                $tables = ['devices', 'duocang_data', 'users', 'repos', 'lives', 'local_files'];
                foreach ($tables as $table) {
                    $pdo->exec("DROP TABLE IF EXISTS `" . $table . "`");
                }
            }

            // 删除配置文件
            if (file_exists($configFile)) {
                unlink($configFile);
            }

            // 删除 local_repo
            if ($deleteRepo && is_dir(__DIR__ . '/local_repo')) {
                function rrmdir($dir) {
                    foreach (glob($dir . '/*') as $file) {
                        if (is_dir($file)) {
                            rrmdir($file);
                        } else {
                            unlink($file);
                        }
                    }
                    rmdir($dir);
                }
                rrmdir(__DIR__ . '/local_repo');
            }

            $success = "系统卸载完成。";

        } catch (PDOException $e) {
            $error = "数据库连接失败: " . $e->getMessage();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="zh">
<head>
<meta charset="UTF-8">
<title>系统卸载</title>
<style>
body { font-family:"Segoe UI",Arial,sans-serif; background:#f9fafc; display:flex; justify-content:center; align-items:center; height:100vh; }
.box { background:#fff; padding:30px 50px; border-radius:10px; box-shadow:0 3px 12px rgba(0,0,0,0.15); width:420px; }
h2 { text-align:center; margin-bottom:20px; color:#c0392b; }
button { width:100%; padding:10px; border:none; border-radius:6px; background:#e74c3c; color:#fff; font-size:15px; cursor:pointer; }
button:hover { opacity:0.9; }
.error { color:#f44336; margin-bottom:10px; text-align:center; }
.success { color:#4CAF50; margin-bottom:10px; text-align:center; }
label { display:block; margin-bottom:10px; }
input[type="password"] { width:100%; padding:10px; margin:10px 0 20px; border-radius:6px; border:1px solid #ccc; box-sizing:border-box; }
</style>
</head>
<body>
<div class="box">
<h2>系统卸载</h2>
<?php if($error) echo "<div class='error'>$error</div>"; ?>
<?php if($success) echo "<div class='success'>$success</div>"; ?>

<?php if(!$success && $dbName): ?>
<p style="text-align:center;color:#333;">当前数据库：<b><?php echo htmlspecialchars($dbName); ?></b></p>
<form method="post" onsubmit="return confirm('确定要卸载系统吗？此操作不可恢复！');">
    <input type="password" name="db_pass" placeholder="请输入数据库密码进行确认" required>
    <label><input type="checkbox" name="delete_db"> 删除整个数据库 (<?php echo htmlspecialchars($dbName); ?>)</label>
    <label><input type="checkbox" name="delete_repo"> 删除 local_repo 目录</label>
    <button type="submit">确认卸载</button>
</form>
<?php elseif(!$dbName): ?>
<p style="color:#666; text-align:center;">未检测到数据库配置，系统似乎未初始化。</p>
<?php endif; ?>
</div>
</body>
</html>
