<?php
session_start();
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: login.php');
    exit;
}
$config = require __DIR__ . '/config/config.php';
try {
    $pdo = new PDO(
        "mysql:host=".$config['db_host'].";dbname=".$config['db_name'].";charset=utf8mb4",
        $config['db_user'],
        $config['db_pass']
    );
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die('数据库连接失败');
}
$authMode = '0';
try {
    $col = $pdo->query("SHOW COLUMNS FROM setting LIKE 'AuthMode'")->fetch(PDO::FETCH_ASSOC);
    if ($col) {
        $row = $pdo->query("SELECT AuthMode FROM setting LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        if ($row && isset($row['AuthMode'])) { $authMode = (string)$row['AuthMode']; }
    } else {
        $authMode = isset($config['auth_mode']) ? (string)$config['auth_mode'] : '0';
    }
} catch (Exception $e) {
    $authMode = isset($config['auth_mode']) ? (string)$config['auth_mode'] : '0';
}
if ($authMode !== '1') {
    header('Location: index.php');
    exit;
}
$pdo->exec("CREATE TABLE IF NOT EXISTS devices (
    id INT AUTO_INCREMENT PRIMARY KEY,
    device_id VARCHAR(255) NOT NULL UNIQUE,
    device_name VARCHAR(255) DEFAULT NULL,
    server VARCHAR(255) DEFAULT NULL,
    expire_at DATE DEFAULT NULL,
    remark VARCHAR(255) DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    last_login DATETIME DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");
$col = $pdo->query("SHOW COLUMNS FROM devices LIKE 'device_name'")->fetch(PDO::FETCH_ASSOC);
if (!$col) { $pdo->exec("ALTER TABLE devices ADD COLUMN device_name VARCHAR(255) DEFAULT NULL AFTER device_id"); }
$stmt = $pdo->query("SELECT ServerName FROM duocang_data ORDER BY ServerName ASC");
$servers = $stmt->fetchAll(PDO::FETCH_COLUMN);
$msg=''; $err='';
if (isset($_GET['delete'])) {
    $did = trim($_GET['delete']);
    $stmt = $pdo->prepare("DELETE FROM devices WHERE device_id = :id");
    $stmt->execute([':id'=>$did]);
    header("Location: devices.php");
    exit;
}
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['edit_device'])) {
    $old = trim($_POST['old_device_id']);
    $deviceId = trim($_POST['device_id']);
    $deviceName = trim($_POST['device_name'] ?? '');
    $server = trim($_POST['server']);
    $remark = trim($_POST['remark']);
    $expire = trim($_POST['expire_at']);
    if ($deviceId==='') { $err='Device ID不能为空'; }
    else {
        $stmt = $pdo->prepare("UPDATE devices SET device_id=:d, device_name=:dn, server=:s, remark=:r, expire_at=:e WHERE device_id=:o");
        $stmt->execute([':d'=>$deviceId, ':dn'=>$deviceName!==''?$deviceName:null, ':s'=>$server, ':r'=>$remark, ':e'=>$expire!==''?$expire:null, ':o'=>$old]);
        $msg='设备已更新';
    }
}
$logs = $pdo->query("SELECT * FROM devices ORDER BY created_at DESC")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>设备管理 - 系统管理</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
<?php include "header.php"; ?>
<div class="container">
    <h1 class="page-title">设备管理</h1>
    <div class="main-content fade-in">
        <?php if($msg):?><div class="message"><?=$msg?></div><?php endif;?>
        <?php if($err):?><div class="message error"><?=$err?></div><?php endif;?>
        
        <div class="table-container">
            <?php if (empty($logs)): ?>
                <div class="message">暂无设备</div>
            <?php else: ?>
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>设备ID</th>
                            <th>设备名</th>
                            <th>授权分组</th>
                            <th>备注</th>
                            <th>到期时间</th>
                            <th>创建时间</th>
                            <th>最后登录</th>
                            <th>操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($logs as $log):
                            $expire = $log['expire_at'] ?? '';
                            $daysText = '永久';
                            $badgeClass = 'status-permanent';
                            if ($expire !== '') {
                                $diff = intval((strtotime($expire) - strtotime(date('Y-m-d'))) / 86400);
                                if ($diff >= 0) {
                                    $daysText = '剩余 ' . $diff . ' 天';
                                    $badgeClass = 'status-active';
                                } else {
                                    $daysText = '已过期 ' . abs($diff) . ' 天';
                                    $badgeClass = 'status-expired';
                                }
                            }
                        ?>
                        <tr>
                            <td>
                                <input type="hidden" name="old_device_id" value="<?=htmlspecialchars($log['device_id'])?>" form="f-<?=htmlspecialchars($log['device_id'])?>">
                                <input type="text" name="device_id" class="form-input" value="<?=htmlspecialchars($log['device_id'])?>" form="f-<?=htmlspecialchars($log['device_id'])?>">
                            </td>
                            <td>
                                <input type="text" name="device_name" class="form-input" value="<?=htmlspecialchars($log['device_name'] ?? '')?>" form="f-<?=htmlspecialchars($log['device_id'])?>">
                            </td>
                            <td>
                                <select name="server" class="form-select" form="f-<?=htmlspecialchars($log['device_id'])?>">
                                    <option value="">未选择</option>
                                    <?php foreach($servers as $s): ?>
                                        <option value="<?=htmlspecialchars($s)?>" <?=($log['server']??'')===$s?'selected':''?>><?=htmlspecialchars($s)?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td>
                                <input type="text" name="remark" class="form-input" value="<?=htmlspecialchars($log['remark'] ?? '')?>" form="f-<?=htmlspecialchars($log['device_id'])?>">
                            </td>
                            <td>
                                <div class="date-wrap">
                                    <input type="date" name="expire_at" class="form-input date-input" value="<?=htmlspecialchars($expire)?>" form="f-<?=htmlspecialchars($log['device_id'])?>">
                                    <span class="status-badge <?=$badgeClass?>"><?=$daysText?></span>
                                </div>
                            </td>
                            <td><span style="color:var(--text-secondary);"><?=htmlspecialchars($log['created_at'] ?? '')?></span></td>
                            <td><span style="color:var(--text-secondary);"><?=htmlspecialchars($log['last_login'] ?? '')?></span></td>
                            <td style="white-space:nowrap;">
                                <form method="post" id="f-<?=htmlspecialchars($log['device_id'])?>">
                                    <input type="hidden" name="edit_device" value="1">
                                </form>
                                <button type="submit" class="btn btn-success" form="f-<?=htmlspecialchars($log['device_id'])?>">保存</button>
                                <a href="?delete=<?=urlencode($log['device_id'])?>" class="btn btn-danger" onclick="return confirm('确定删除该设备吗？')">删除</a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <!--测试模块
        <div class="card">
            <h3 style="margin-bottom:20px;color:var(--secondary-color);">测试区域（Debug）</h3>
            <div class="form-row">
                <div class="form-group">
                    <label class="form-label">测试 Device ID</label>
                    <input type="text" id="testDeviceId" class="form-input" placeholder="请输入要测试的 Device ID">
                </div>
                <div class="form-group">
                    <label class="form-label">仓库名（可选，用于测试 vapi）</label>
                    <input type="text" id="testRepoName" class="form-input" placeholder="例如：某仓库的 name">
                </div>
                <div class="form-group">
                    <button type="button" class="btn btn-primary" onclick="openApi()">测试列表 API</button>
                    <button type="button" class="btn btn-success" onclick="openVapi()" style="margin-left: 10px;">测试仓库 vapi</button>
                </div>
            </div>
            <p class="help-text">说明：开启授权模式后，列表接口为 api.php?device_id=ID；仓库接口为 vapi.php?device_id=ID&name=仓库名。</p>
        </div>
        测试模块-->
    </div>
</div>
<script>
function openApi() {
    var id = document.getElementById('testDeviceId').value.trim();
    if (!id) {
        alert('请输入 Device ID');
        return;
    }
    window.open('api.php?device_id=' + encodeURIComponent(id), '_blank');
}
function openVapi() {
    var id = document.getElementById('testDeviceId').value.trim();
    var name = document.getElementById('testRepoName').value.trim();
    if (!id) {
        alert('请输入 Device ID');
        return;
    }
    if (!name) {
        alert('请输入仓库名（name）用于测试 vapi');
        return;
    }
    window.open('vapi.php?device_id=' + encodeURIComponent(id) + '&name=' + encodeURIComponent(name), '_blank');
}
</script>
</body>
</html>
