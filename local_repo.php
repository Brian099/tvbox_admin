<?php
session_start();

// 如果未登录则跳转
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: login.php');
    exit;
}

// 处理登出
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    session_destroy();
    header('Location: login.php');
    exit;
}

// 加载数据库配置
$config = require __DIR__ . '/config/config.php';

try {
    $pdo = new PDO(
        "mysql:host={$config['db_host']};dbname={$config['db_name']};charset=utf8mb4",
        $config['db_user'],
        $config['db_pass']
    );
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("数据库连接失败: " . $e->getMessage());
}

// 处理新增文件名
$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['new_filename'])) {
    $newFile = trim($_POST['new_filename']);
    if ($newFile !== '') {
        // 判断是否存在
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM local_files WHERE filename=:filename");
        $stmt->execute([':filename'=>$newFile]);
        if ($stmt->fetchColumn() == 0) {
            $stmt = $pdo->prepare("INSERT INTO local_files (filename) VALUES (:filename)");
            $stmt->execute([':filename'=>$newFile]);
            $message = "✅ 文件名 {$newFile} 已添加成功！";
        } else {
            $message = "⚠ 文件名 {$newFile} 已存在！";
        }
    }
}

// 删除文件名
if (isset($_GET['delete']) && $_GET['delete'] !== '') {
    $delFile = trim($_GET['delete']);
    $stmt = $pdo->prepare("DELETE FROM local_files WHERE filename=:filename");
    $stmt->execute([':filename'=>$delFile]);
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

// 获取所有本地文件名
$stmt = $pdo->query("SELECT filename FROM local_files ORDER BY filename ASC");
$files = $stmt->fetchAll(PDO::FETCH_COLUMN);

?>
<!DOCTYPE html>
<html lang="zh">
<head>
<meta charset="UTF-8">
<title>本地资源管理</title>
<style>
h2 { margin-bottom: 20px; color:#2c3e50; }
.message { padding:10px; margin:15px 0; border-radius:5px; background:#eafaf1; color:#2d7a46; border:1px solid #c3e6cb; }
input[type=text] { padding:8px; border-radius:5px; border:1px solid #ccc; width:250px; box-sizing:border-box; margin-right:5px; }
button { padding:8px 16px; border-radius:5px; border:none; cursor:pointer; font-size:14px; margin:2px; }
button:hover { opacity:0.9; }
.btn-green { background:#4caf50; color:white; }
.btn-red { background:#f44336; color:white; }
ul { padding-left:20px; }
li { margin-bottom:5px; }
</style>
<script>
function confirmDelete(file) {
    if (confirm("确定要删除文件名 " + file + " 吗？")) {
        window.location = "?delete=" + encodeURIComponent(file);
    }
}
</script>
</head>
<body>
<?php include "header.php"; ?>

<?php if($message) echo "<div class='message'>{$message}</div>"; ?>

<form method="post">
    <input type="text" name="new_filename" placeholder="输入要遍历的文件名，例如 api.json" required>
    <button type="submit" class="btn-green">➕ 添加文件名</button>
</form>

<h3>已添加的本地文件名</h3>
<ul>
<?php foreach($files as $f): ?>
    <li><?=htmlspecialchars($f)?> <button class="btn-red" type="button" onclick="confirmDelete('<?=htmlspecialchars($f)?>')">删除</button></li>
<?php endforeach; ?>
</ul>

</body>
</html>
