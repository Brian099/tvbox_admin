<?php
session_start();

// 如果未登录则跳转到 login.php
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
        "mysql:host=".$config['db_host'].";dbname=".$config['db_name'].";charset=utf8mb4",
        $config['db_user'],
        $config['db_pass']
    );
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("数据库连接失败: " . $e->getMessage());
}

// 获取多仓列表
$stmt = $pdo->query("SELECT ServerName FROM duocang_data ORDER BY ServerName ASC");
$files = $stmt->fetchAll(PDO::FETCH_COLUMN);

// 新建文件
$newFileMessage = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['new_file'])) {
    $newFile = trim($_POST['new_file']);
    if ($newFile !== '') {
        
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM duocang_data WHERE ServerName=:ServerName");
        $stmt->execute([':ServerName'=>$newFile]);
        if ($stmt->fetchColumn() == 0) {
            $stmt = $pdo->prepare("INSERT INTO duocang_data (ServerName, repos) VALUES (:ServerName, '')");
            $stmt->execute([':ServerName'=>$newFile]);
            $newFileMessage = "文件 {$newFile} 创建成功！";
            $files[] = $newFile;
        } else {
            $newFileMessage = "文件 {$newFile} 已存在！";
        }
    }
}

// 删除文件
if (isset($_GET['delete']) && $_GET['delete'] !== '') {
    $delFile = trim($_GET['delete']);
    $stmt = $pdo->prepare("DELETE FROM duocang_data WHERE ServerName=:ServerName");
    $stmt->execute([':ServerName'=>$delFile]);
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

// 编辑文件
$editFile = isset($_GET['edit']) ? trim($_GET['edit']) : '';
$data = ['urls'=>[]];
if ($editFile !== '') {
    $stmt = $pdo->prepare("SELECT repos FROM duocang_data WHERE ServerName=:ServerName LIMIT 1");
    $stmt->execute([':ServerName'=>$editFile]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $str = $row['repos'];
        if ($str) {
            $items = explode(';', $str);
            foreach ($items as $item) {
                if (trim($item) === '') continue;
                list($name,$url) = explode(',', $item,2);
                $data['urls'][] = ['name'=>$name,'url'=>$url];
            }
        }
    }
}

// 保存编辑
$saveMessage = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_edit'], $_POST['name'], $_POST['url'], $_POST['edit_file'])) {
    $names = $_POST['name'];
    $urls  = $_POST['url'];
    $editFilePost = trim($_POST['edit_file']);

    $contentArr = [];
    foreach ($names as $i => $n) {
        $name = trim($n);
        $url  = trim($urls[$i]);
        if ($name === '' && $url === '') continue;
        $contentArr[] = $name.','.$url;
    }
    $dbContent = implode(';', $contentArr);
    if (!empty($dbContent)) $dbContent .= ';';

    $stmt = $pdo->prepare("UPDATE duocang_data SET repos=:repos WHERE ServerName=:ServerName");
    $stmt->execute([
        ':repos' => $dbContent,
        ':ServerName'     => $editFilePost
    ]);

    $saveMessage = "✅ 保存成功";
    // 更新 $data 重新渲染表单
    $data['urls'] = [];
    foreach ($contentArr as $item) {
        list($n,$u) = explode(',', $item,2);
        $data['urls'][] = ['name'=>$n,'url'=>$u];
    }
}
?>
<!DOCTYPE html>
<html lang="zh">
<head>
<meta charset="UTF-8">
<title>多仓管理</title>
<style>
body { font-family: "Segoe UI", Arial, sans-serif; margin: 30px; background:#f9fafc; color:#333; }
h2 { margin-bottom: 20px; color:#2c3e50; }
.message { padding:10px; margin:15px 0; border-radius:5px; background:#eafaf1; color:#2d7a46; border:1px solid #c3e6cb; }
form { margin-bottom: 20px; display:inline-block; }
input[type=text], select { padding:8px; border-radius:5px; border:1px solid #ccc; width:220px; box-sizing:border-box; }
button { padding:8px 16px; border-radius:5px; border:none; cursor:pointer; font-size:14px; margin:2px; }
button:hover { opacity:0.9; }
.btn-green { background:#4caf50; color:white; }
.btn-blue { background:#2196F3; color:white; }
.btn-red { background:#f44336; color:white; }
table { border-collapse: collapse; width:100%; margin-top:15px; background:#fff; border-radius:6px; overflow:hidden; }
th, td { border:1px solid #ddd; padding:10px; text-align:center; }
th { background:#f4f6f8; color:#333; }
tr:hover { background:#f9f9f9; }
</style>
<script>
function confirmDelete(file) {
    if (confirm("确定要删除文件 " + file + " 吗？")) {
        window.location = "?delete=" + encodeURIComponent(file);
    }
}
function addRow() {
    const table = document.querySelector('table');
    const row = table.insertRow(-1);
    row.innerHTML = `
        <td><input type="text" name="name[]" placeholder="名称"></td>
        <td><input type="text" name="url[]" placeholder="网址"></td>
        <td><button type="button" class="btn-red" onclick="this.parentNode.parentNode.remove()">🗑 删除</button></td>
    `;
}
</script>
</head>
<body>
<h2>多仓管理 | <a href="index.php">返回用户管理</a></h2>

<?php if($newFileMessage) echo "<div class='message'>{$newFileMessage}</div>"; ?>
<?php if($saveMessage) echo "<div class='message'>{$saveMessage}</div>"; ?>

<form method="post">
    <input type="text" name="new_file" placeholder="新 JSON 文件名">
    <button type="submit" class="btn-green">➕ 创建文件</button>
</form>

<h3>选择要编辑的文件</h3>
<form method="get">
    <select name="edit" onchange="this.form.submit()">
        <option value="">-- 请选择文件 --</option>
        <?php foreach($files as $file): ?>
            <option value="<?=htmlspecialchars($file)?>" <?=($editFile===$file)?'selected':''?>><?=htmlspecialchars($file)?></option>
        <?php endforeach; ?>
    </select>
</form>

<?php if($editFile!==''): ?>
    <button type="button" class="btn-red" onclick="confirmDelete('<?=htmlspecialchars($editFile)?>')">🗑 删除当前文件</button>
    <hr>
    <h3>✏️ 编辑文件：<?=htmlspecialchars($editFile)?></h3>
    <form method="post">
        <input type="hidden" name="edit_file" value="<?=htmlspecialchars($editFile)?>">
        <table>
        <tr>
            <th>名称</th>
            <th>网址</th>
            <th>操作</th>
        </tr>
        <?php foreach ($data['urls'] as $i => $item): ?>
        <tr>
            <td><input type="text" name="name[]" value="<?=htmlspecialchars($item['name'])?>"></td>
            <td><input type="text" name="url[]" value="<?=htmlspecialchars($item['url'])?>"></td>
            <td><button type="button" class="btn-red" onclick="this.parentNode.parentNode.remove()">🗑 删除</button></td>
        </tr>
        <?php endforeach; ?>
        </table>
        <br>
        <button type="button" class="btn-blue" onclick="addRow()">➕ 新增一行</button>
        <button type="submit" name="save_edit" class="btn-green">💾 保存修改</button>
    </form>
<?php endif; ?>

</body>
</html>
