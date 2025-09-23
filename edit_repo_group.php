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
        "mysql:host={$config['db_host']};dbname={$config['db_name']};charset=utf8mb4",
        $config['db_user'],
        $config['db_pass']
    );
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("数据库连接失败: " . $e->getMessage());
}

// 获取 duocang_data 列表
$stmt = $pdo->query("SELECT ServerName FROM duocang_data ORDER BY ServerName ASC");
$files = $stmt->fetchAll(PDO::FETCH_COLUMN);

// 新建分组
$newFileMessage = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['new_file'])) {
    $newFile = trim($_POST['new_file']);
    if ($newFile !== '') {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM duocang_data WHERE ServerName=:ServerName");
        $stmt->execute([':ServerName'=>$newFile]);
        if ($stmt->fetchColumn() == 0) {
            $stmt = $pdo->prepare("INSERT INTO duocang_data (ServerName, repos) VALUES (:ServerName, '')");
            $stmt->execute([':ServerName'=>$newFile]);
            $newFileMessage = "分组 {$newFile} 创建成功！";
            $files[] = $newFile;
        } else {
            $newFileMessage = "分组 {$newFile} 已存在！";
        }
    }
}

// 删除分组
if (isset($_GET['delete']) && $_GET['delete'] !== '') {
    $delFile = trim($_GET['delete']);
    $stmt = $pdo->prepare("DELETE FROM duocang_data WHERE ServerName=:ServerName");
    $stmt->execute([':ServerName'=>$delFile]);
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

// 获取所有 repos
$stmt = $pdo->query("SELECT name FROM repos ORDER BY name ASC");
$allRepos = $stmt->fetchAll(PDO::FETCH_COLUMN);

// 编辑分组
$editFile = isset($_GET['edit']) ? trim($_GET['edit']) : '';
$currentRepos = [];
$attachLocal = 0;
if ($editFile !== '') {
    $stmt = $pdo->prepare("SELECT repos, attach_local FROM duocang_data WHERE ServerName=:ServerName LIMIT 1");
    $stmt->execute([':ServerName'=>$editFile]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        if (!empty($row['repos'])) {
            $currentRepos = array_filter(explode(';', $row['repos']));
        }
        $attachLocal = (int)$row['attach_local'];
    }
}

// 保存编辑
$saveMessage = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_group'], $_POST['edit_file'])) {
    $editFilePost = trim($_POST['edit_file']);
    $reposSelected = isset($_POST['repos']) ? $_POST['repos'] : [];
    $reposList = implode(';', $reposSelected);
    if ($reposList !== '') $reposList .= ';';

    $attachLocalPost = isset($_POST['attach_local']) ? 1 : 0;

    $stmt = $pdo->prepare("UPDATE duocang_data SET repos=:repos, attach_local=:attach_local WHERE ServerName=:ServerName");
    $stmt->execute([
        ':repos' => $reposList,
        ':attach_local' => $attachLocalPost,
        ':ServerName' => $editFilePost
    ]);

    $saveMessage = "✅ 保存成功";
    $currentRepos = $reposSelected;
    $attachLocal = $attachLocalPost;
}
?>
<!DOCTYPE html>
<html lang="zh">
<head>
<meta charset="UTF-8">
<title>多仓分组管理</title>
<style>
.message { padding:10px; margin:15px 0; border-radius:5px; background:#eafaf1; color:#2d7a46; border:1px solid #c3e6cb; }
form { margin-bottom: 20px; display:inline-block; }
input[type=text], select { padding:8px; border-radius:5px; border:1px solid #ccc; width:220px; box-sizing:border-box; }
button { padding:8px 16px; border-radius:5px; border:none; cursor:pointer; font-size:14px; margin:2px; }
button:hover { opacity:0.9; }
.btn-green { background:#4caf50; color:white; }
.btn-blue { background:#2196F3; color:white; }
.btn-red { background:#f44336; color:white; }
.repo-list { margin-top:15px; background:#fff; padding:15px; border:1px solid #ddd; border-radius:6px; }
label { display:block; margin:4px 0; cursor:pointer; }
ul.sortable { list-style: none; padding-left: 0; }
ul.sortable li { margin: 4px 0; padding: 6px 8px; background: #f1f1f1; border: 1px solid #ccc; border-radius:4px; cursor: move; }
</style>
<script src="js/Sortable.min.js"></script>
<script>
function confirmDelete(file) {
    if (confirm("确定要删除分组 " + file + " 吗？")) {
        window.location = "?delete=" + encodeURIComponent(file);
    }
}

document.addEventListener('DOMContentLoaded', function(){
    var el = document.getElementById('sortable-list');
    if(el){
        new Sortable(el, {
            animation: 150,
            onEnd: function () {
                // 拖拽结束后重新排列隐藏 input
                var items = el.querySelectorAll('input[type=checkbox]');
                var form = el.closest('form');
                var reposContainer = form.querySelector('input[name=repos_order]');
                if(!reposContainer){
                    reposContainer = document.createElement('input');
                    reposContainer.type = 'hidden';
                    reposContainer.name = 'repos_order';
                    form.appendChild(reposContainer);
                }
                var order = [];
                items.forEach(function(input){ order.push(input.value); });
                reposContainer.value = order.join(';');
            }
        });
    }
});
</script>
</head>
<body>
<?php include "header.php"; ?>

<?php if($newFileMessage) echo "<div class='message'>{$newFileMessage}</div>"; ?>
<?php if($saveMessage) echo "<div class='message'>{$saveMessage}</div>"; ?>

<form method="post">
    <input type="text" name="new_file" placeholder="新分组名称">
    <button type="submit" class="btn-green">➕ 创建分组</button>
</form>

<h3>选择要编辑的分组</h3>
<form method="get">
    <select name="edit" onchange="this.form.submit()">
        <option value="">-- 请选择分组 --</option>
        <?php foreach($files as $file): ?>
            <option value="<?=htmlspecialchars($file)?>" <?=($editFile===$file)?'selected':''?>><?=htmlspecialchars($file)?></option>
        <?php endforeach; ?>
    </select>
</form>

<?php if($editFile!==''): ?>
    <button type="button" class="btn-red" onclick="confirmDelete('<?=htmlspecialchars($editFile)?>')">🗑 删除当前分组</button>
    <hr>
    <h3>✏️ 编辑分组：<?=htmlspecialchars($editFile)?></h3>
    <form method="post">
        <input type="hidden" name="edit_file" value="<?=htmlspecialchars($editFile)?>">
        <div class="repo-list">
            <ul id="sortable-list" class="sortable">
            <?php
            // 已勾选的按数据库顺序显示
            $sortedRepos = $currentRepos;
            $uncheckedRepos = array_diff($allRepos, $currentRepos);
            $finalRepos = array_merge($sortedRepos, $uncheckedRepos);
            foreach($finalRepos as $r):
                $checked = in_array($r, $currentRepos) ? 'checked' : '';
            ?>
                <li>
                    <label>
                        <input type="checkbox" name="repos[]" value="<?=htmlspecialchars($r)?>" <?=$checked?>>
                        <?=htmlspecialchars($r)?>
                    </label>
                </li>
            <?php endforeach; ?>
            </ul>
        </div>
        <div style="margin-top:10px;">
            <label>
                <input type="checkbox" name="attach_local" value="1" <?=$attachLocal ? 'checked' : ''?>>
                附加本地资源
            </label>
        </div>
        <br>
        <button type="submit" name="save_group" class="btn-green">💾 保存修改</button>
    </form>
<?php endif; ?>

</body>
</html>
