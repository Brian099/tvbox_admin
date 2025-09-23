<?php
// notice.php

// 配置文件
$configFile = __DIR__ . '/config/config.php';
if (!file_exists($configFile)) {
    http_response_code(500);
    echo json_encode(['error'=>'系统未初始化']);
    exit;
}
$config = require $configFile;

// 连接数据库
try {
    $pdo = new PDO(
        "mysql:host=".$config['db_host'].";dbname=".$config['db_name'].";charset=utf8mb4",
        $config['db_user'],
        $config['db_pass']
    );
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error'=>'数据库连接失败']);
    exit;
}

// 获取参数
$device_id = isset($_REQUEST['device_id']) ? trim($_REQUEST['device_id']) : '';
if ($device_id === '') {
    http_response_code(400);
    echo json_encode(['error'=>'device_id is required']);
    exit;
}

// 路径
$noServerFile  = __DIR__ . '/notice/no_server.json';
$endServerFile = __DIR__ . '/notice/end_server.json';

// 查询设备
$stmt = $pdo->prepare("SELECT * FROM devices WHERE device_id = :device_id LIMIT 1");
$stmt->execute(array(':device_id'=>$device_id));
$device = $stmt->fetch(PDO::FETCH_ASSOC);

header('Content-Type: application/json; charset=utf-8');

// 未找到用户或 server 为空
if (!$device || empty($device['server'])) {
    if (file_exists($noServerFile)) {
        echo file_get_contents($noServerFile);
    }
    exit;
}

// 检查是否过期
$today = date('Y-m-d');
if (!empty($device['expire_at']) && $device['expire_at'] < $today) {
    if (file_exists($endServerFile)) {
        echo file_get_contents($endServerFile);
    }
    exit;
}

// 用户存在且未过期 → 不返回任何内容
exit;
