<?php
// api.php

// 配置文件路径
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

// 允许的 package_name
$package_name_OK = "com.fongmi.android.tv";

// 获取参数
$package_name = isset($_GET['package_name']) ? trim($_GET['package_name']) : '';
$deviceId     = isset($_GET['device_id']) ? trim($_GET['device_id']) : '';

if ($package_name !== $package_name_OK) {
    echo "参数错误！";
    exit;
}

$serverFile = '';

// 查找设备
if ($deviceId !== '') {
    $stmt = $pdo->prepare("SELECT * FROM devices WHERE device_id = :device_id LIMIT 1");
    $stmt->execute(array(':device_id'=>$deviceId));
    $device = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($device) {
        $serverFile = trim($device['server']);

        // 更新 last_login
        $stmt = $pdo->prepare("UPDATE devices SET last_login = :last_login WHERE device_id = :device_id");
        $stmt->execute(array(
            ':last_login'=>date('Y-m-d H:i:s'),
            ':device_id'=>$deviceId
        ));
    } else {
    // 新设备插入，expire_at 默认为今天+7天
    $expireAt = date('Y-m-d', strtotime('+7 days'));

    $stmt = $pdo->prepare("INSERT INTO devices (device_id, created_at, last_login, server, remark, expire_at) VALUES (:device_id, :created_at, :last_login, '', '', :expire_at)");
    $stmt->execute(array(
        ':device_id'=>$deviceId,
        ':created_at'=>date('Y-m-d H:i:s'),
        ':last_login'=>date('Y-m-d H:i:s'),
        ':expire_at'=>$expireAt
    ));
}

}

// 返回 JSON
header('Content-Type: application/json; charset=utf-8');

if ($serverFile !== '') {
    // 从数据库读取 repos
    $stmt = $pdo->prepare("SELECT repos FROM duocang_data WHERE ServerName = :ServerName LIMIT 1");
    $stmt->execute(array(':ServerName'=>$serverFile));
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($row && !empty($row['repos'])) {
        $str = $row['repos'];
        $result = array();
        $items = explode(';', $str);
        foreach ($items as $item) {
            if (trim($item) === '') continue;
            list($name,$url) = explode(',', $item,2);
            $result[] = array(
                'name' => $name,
                'url'  => $url
            );
        }
        // 返回 JSON，不转义 URL
        echo json_encode(['urls'=>$result], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        exit;
    } else {
        echo json_encode(array(
            "error"  => "对应的 server 配置不存在",
            "server" => $serverFile
        ), JSON_UNESCAPED_UNICODE);
        exit;
    }
} else {
    echo json_encode(array(
        "error"     => "未配置 server",
        "device_id" => $deviceId
    ), JSON_UNESCAPED_UNICODE);
    exit;
}
