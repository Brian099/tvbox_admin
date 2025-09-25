<?php
session_start();
// 配置文件路径
$configFile = __DIR__ . '/config/config.php';

// 如果配置文件不存在，跳转到 init.php 初始化
if (!file_exists($configFile)) {
    header('Location: init.php');
    exit;
}

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

// 从数据库获取所有授权文件名
$stmt = $pdo->query("SELECT ServerName FROM duocang_data ORDER BY created_at DESC");
$jsonFiles = $stmt->fetchAll(PDO::FETCH_COLUMN);

// 删除设备
if (isset($_GET['delete'])) {
    $deleteId = trim($_GET['delete']);
    $stmt = $pdo->prepare("DELETE FROM devices WHERE device_id = :device_id");
    $stmt->execute(array(':device_id'=>$deleteId));
    header("Location: " . strtok($_SERVER['REQUEST_URI'], '?'));
    exit;
}

// 保存单行修改
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['old_device_id'])) {
    $oldId = trim($_POST['old_device_id']);
    $device_id = trim($_POST['device_id']);
    $server = trim($_POST['server']);
    $remark = trim($_POST['remark']);
    $expire_at = trim($_POST['expire_at']);

    $stmt = $pdo->prepare("UPDATE devices SET device_id=:device_id, server=:server, remark=:remark, expire_at=:expire_at WHERE device_id=:old_device_id");
    $stmt->execute(array(
        ':device_id'=>$device_id,
        ':server'=>$server,
        ':remark'=>$remark,
        ':expire_at'=>$expire_at,
        ':old_device_id'=>$oldId
    ));

    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

// ===== 搜索和分页 =====
$search = trim($_GET['search'] ?? '');
$page = max(1, intval($_GET['page'] ?? 1));
$perPage = 10;

$where = '';
$params = array();
if ($search !== '') {
    $where = "WHERE device_id LIKE :search OR remark LIKE :search";
    $params[':search'] = "%".$search."%";
}

// 统计总数
$stmt = $pdo->prepare("SELECT COUNT(*) FROM devices $where");
$stmt->execute($params);
$total = intval($stmt->fetchColumn());

// 获取分页数据
$start = ($page - 1) * $perPage;
$stmt = $pdo->prepare("SELECT * FROM devices $where ORDER BY created_at DESC LIMIT :start, :perPage");
foreach ($params as $k=>$v) {
    $stmt->bindValue($k, $v, PDO::PARAM_STR);
}
$stmt->bindValue(':start', $start, PDO::PARAM_INT);
$stmt->bindValue(':perPage', $perPage, PDO::PARAM_INT);
$stmt->execute();
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 统计过期数
$expiredCount = 0;
$today = date('Y-m-d');
foreach ($logs as $log) {
    if (!empty($log['expire_at']) && $log['expire_at'] < $today) {
        $expiredCount++;
    }
}

$totalPages = ceil($total / $perPage);
?>
<!DOCTYPE html>
<html lang="zh">
<head>
<meta charset="UTF-8">
<title>用户管理</title>
<style>
/* 保留原样式 */
input[type=text], input[type=date], select { padding:8px; border-radius:5px; border:1px solid #ccc; width:160px; box-sizing:border-box; }
button, .btn { padding:6px 12px; border-radius:6px; border:none; cursor:pointer; font-size:13px; margin:2px; text-decoration: none; }
button:hover, .btn:hover { opacity:0.9; }
.btn-green { background:#4caf50; color:white; }
.btn-blue { background:#2196F3; color:white; }
.btn-red { background:#f44336; color:white; }
.logout { float:right; text-decoration:none; margin-bottom:15px; }
table { border-collapse: collapse; width:100%; margin-top:15px; background:#fff; border-radius:6px; overflow:hidden; box-shadow:0 1px 5px rgba(0,0,0,0.1); }
th, td { border:1px solid #ddd; padding:8px; text-align:center; }
th { background:#f4f6f8; color:#333; }
tr:hover { background:#f9f9f9; }
.pagination a { margin: 0 3px; text-decoration: none; padding: 4px 9px; border: 1px solid #ccc; border-radius: 4px; }
.pagination a.current { background: #2196F3; color: #fff; border: 1px solid #2196F3; }
.search-form { margin-bottom: 15px; }
.expired { background: #ffe6e6; color: #d9534f; font-weight: bold; }
.stats { margin-top: 20px; padding:10px; background:#fff; border-radius:6px; box-shadow:0 1px 5px rgba(0,0,0,0.1);}
</style>
</head>
<body>
<?php include "header.php"; ?>
<a class="btn btn-red logout" href="?action=logout">✈ 退出登录</a>

<form class="search-form" method="get">
    <input type="text" name="search" placeholder="搜索 Device ID 或备注" value="<?=htmlspecialchars($search)?>">
    <button type="submit" class="btn btn-blue">🔍 搜索</button>
</form>

<table>
<tr>
    <th>设备ID</th>
    <th>授权文件</th>
    <th>备注</th>
    <th>到期时间</th>
    <th>剩余天数</th>
    <th>创建时间</th>
    <th>最后登录</th>
    <th>操作</th>
</tr>
<?php foreach ($logs as $log):
    $expireAt = $log['expire_at'] ?? '';
    $isExpired = ($expireAt !== '' && $expireAt < $today);
    $daysText = "未设置";
    if ($expireAt !== '') {
        $diff = (strtotime($expireAt) - strtotime($today)) / 86400;
        $daysText = $diff >=0 ? "还剩 ".intval($diff)." 天" : "已过期 ".abs(intval($diff))." 天";
    }
?>
<tr>
    <form method="post">
        <input type="hidden" name="old_device_id" value="<?=htmlspecialchars($log['device_id'])?>">
        <td><input type="text" name="device_id" value="<?=htmlspecialchars($log['device_id'])?>"></td>
        <td>
            <select name="server">
                <option value="">-- 未选择 --</option>
                <?php foreach ($jsonFiles as $jsonFile): ?>
                    <option value="<?=$jsonFile?>" <?=($log['server'] ?? '') === $jsonFile ? 'selected':''?>><?=$jsonFile?></option>
                <?php endforeach; ?>
            </select>
        </td>
        <td><input type="text" name="remark" value="<?=htmlspecialchars($log['remark'] ?? '')?>"></td>
        <td class="<?=$isExpired ? 'expired' : ''?>">
            <input type="date" name="expire_at" value="<?=htmlspecialchars($expireAt)?>">
        </td>
        <td class="<?=$isExpired ? 'expired' : ''?>"><?=$daysText?></td>
        <td><?=htmlspecialchars($log['created_at'] ?? '')?></td>
        <td><?=htmlspecialchars($log['last_login'] ?? '')?></td>
        <td>
            <button type="submit" class="btn btn-green">💾 保存</button>
            <a class="btn btn-red" href="?delete=<?=urlencode($log['device_id'])?>" onclick="return confirm('确定删除此设备吗？')">✖ 删除</a>
        </td>
    </form>
</tr>
<?php endforeach; ?>
</table>

<?php if ($totalPages > 1): ?>
<div class="pagination">
    <?php for ($p=1; $p<=$totalPages; $p++):
        $class = $p === $page ? 'current' : '';
        $url = '?page='.$p.'&search='.urlencode($search);
    ?>
        <a href="<?=$url?>" class="<?=$class?>"><?=$p?></a>
    <?php endfor; ?>
</div>
<?php endif; ?>

<div class="stats">
    <strong>📊 用户统计：</strong><br>
    总用户数：<?=$total?><br>
    已过期用户数：<?=$expiredCount?>
</div>
测试：
<a href="api.php?package_name=com.fongmi.android.tv&device_id=123456789" target="_block">授权</a>
<a href="notice.php?device_id=123456789" target="_block">通知</a>
</body>
</html>
