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
    <title>登录 - 系统管理</title>
    <link rel="stylesheet" href="style.css">
</head>
<body class="login-body">
    <div class="login-box fade-in">
        <h2 class="login-title">管理页面登录</h2>
        
        <?php if($error): ?>
            <div class="login-error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        
        <form method="post">
            <div class="form-group">
                <input type="text" name="username" class="form-input" placeholder="用户名" required>
            </div>
            <div class="form-group">
                <input type="password" name="password" class="form-input" placeholder="密码" required>
            </div>
            <button type="submit" class="btn btn-primary btn-block">登录</button>
        </form>
    </div>
</body>
</html>
