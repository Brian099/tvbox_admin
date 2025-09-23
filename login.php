<?php
session_start();

// 配置文件路径
$configFile = __DIR__ . '/config/config.php';

// 如果配置文件不存在，跳转到 init.php 初始化
if (!file_exists($configFile)) {
    header('Location: init.php');
    exit;
}

// 加载数据库配置
$config = require $configFile;

try {
    $pdo = new PDO(
        "mysql:host=".$config['db_host'].";dbname=".$config['db_name'].";charset=utf8mb4",
        $config['db_user'],
        $config['db_pass']
    );
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("数据库连接失败: " . $e->getMessage());
}

// 处理登出
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    session_destroy();
    header('Location: login.php');
    exit;
}

// 如果已登录，直接跳转 index.php
if (isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true) {
    header('Location: index.php');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['username'], $_POST['password'])) {
    $username = trim($_POST['username']);
    $password = trim($_POST['password']);

    $stmt = $pdo->prepare("SELECT * FROM users WHERE username = :username LIMIT 1");
    $stmt->execute(array(':username'=>$username));
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user && password_verify($password, $user['password'])) {
        $_SESSION['logged_in'] = true;
        header('Location: index.php');
        exit;
    } else {
        $error = "用户名或密码错误";
    }
}

?>
<!DOCTYPE html>
<html lang="zh">
<head>
<meta charset="UTF-8">
<title>登录</title>
<style>
body { font-family: "Segoe UI", Arial, sans-serif; display: flex; justify-content: center; align-items: center; height: 100vh; background:#f9fafc; }
.login-box { background: #fff; padding: 30px 50px; border-radius: 10px; box-shadow: 0 3px 12px rgba(0,0,0,0.15); }
h2 { margin-bottom: 20px; color:#2c3e50; }
input { display: block; padding: 10px; margin-bottom: 15px; width: 240px; border-radius: 6px; border:1px solid #ccc; box-sizing: border-box; }
button { padding: 10px; width: 100%; background: #2196F3; color: #fff; border: none; border-radius:6px; cursor: pointer; font-size:15px; }
button:hover { opacity:0.9; }
.error { color: #f44336; margin-bottom: 10px; text-align:center; }
</style>
</head>
<body>
<div class="login-box">
<div class="error"><?php echo htmlspecialchars($error); ?></div>
<form method="post">
<h2>管理页面登录</h2>
<input type="text" name="username" placeholder="用户名" required>
<input type="password" name="password" placeholder="密码" required>
<button type="submit">登录</button>
</form>
</div>
</body>
</html>
