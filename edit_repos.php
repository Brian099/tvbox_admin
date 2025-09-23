<?php
session_start();
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: login.php'); exit;
}

$config = require __DIR__.'/config/config.php';
try {
    $pdo = new PDO("mysql:host={$config['db_host']};dbname={$config['db_name']};charset=utf8mb4", $config['db_user'], $config['db_pass']);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Exception $e) {
    die("数据库连接失败: ".$e->getMessage());
}

// 新增 repo
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['add_repo'])) {
    $name = trim($_POST['name']);
    $url  = trim($_POST['url']);
    if ($name && $url) {
        $stmt = $pdo->prepare("INSERT INTO repos (name, url) VALUES (:name,:url) ON DUPLICATE KEY UPDATE url=VALUES(url)");
        $stmt->execute([':name'=>$name, ':url'=>$url]);
    }
}

// 删除 repo
if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    $pdo->prepare("DELETE FROM repos WHERE id=?")->execute([$id]);
    header("Location: edit_repos.php"); exit;
}

// 获取所有 repo
$stmt = $pdo->query("SELECT * FROM repos ORDER BY id ASC");
$repos = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>仓库源管理</title>
<style>
table{border-collapse:collapse;width:100%;margin-top:10px;}
th,td{border:1px solid #ccc;padding:8px;text-align:left;}
th{background:#f0f0f0;}
button{padding:6px 12px;margin:2px;}
</style>
</head>
<body>
<?php include "header.php"; ?>
<h2>仓库源管理</h2>
<form method="post">
    名称: <input type="text" name="name" required>
    链接: <input type="text" name="url" style="width:300px" required>
    <button type="submit" name="add_repo">新增/更新</button>
</form>

<table>
<tr><th>ID</th><th>名称</th><th>URL</th><th>操作</th></tr>
<?php foreach($repos as $r): ?>
<tr>
  <td><?=$r['id']?></td>
  <td><?=htmlspecialchars($r['name'])?></td>
  <td><a href="<?=htmlspecialchars($r['url'])?>" target="_blank"><?=htmlspecialchars($r['url'])?></a></td>
  <td><a href="?delete=<?=$r['id']?>" onclick="return confirm('删除该源?')">🗑 删除</a></td>
</tr>
<?php endforeach; ?>
</table>

</body>
</html>
