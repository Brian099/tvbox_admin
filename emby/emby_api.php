<?php
// 配置文件路径
$configFile = __DIR__ . '/../config/config.php';

// 计算基础URL
$protocol = ( (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
            || (!empty($_SERVER['HTTP_FRONT_END_HTTPS']) && $_SERVER['HTTP_FRONT_END_HTTPS'] !== 'off')
            ) ? 'https' : 'http';
$host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : ($_SERVER['SERVER_NAME'] ?? 'localhost');
$baseUrl = $protocol . '://' . $host;

if (!file_exists($configFile)) {
    die(json_encode([
        'logo' => $baseUrl . '/emby/emby.png',
        'sites' => [],
        'error' => '未找到配置文件'
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}

$config = require $configFile;

try {
    $pdo = new PDO("mysql:host={$config['db_host']};dbname={$config['db_name']};charset=utf8mb4", $config['db_user'], $config['db_pass']);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Exception $e) {
    die(json_encode([
        'logo' => $baseUrl . '/emby/emby.png',
        'sites' => [],
        'error' => '数据库连接失败: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}

// 从数据库中获取所有 Emby 配置
try {
    $stmt = $pdo->query("SELECT SERVER_NAME, EMBY_SERVER, EMBY_USERNAME FROM emby ORDER BY id ASC");
    $embyConfigs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (!$embyConfigs) {
        // 如果没有配置，返回空数组
        $sites = [];
    } else {
        $sites = [];
        foreach ($embyConfigs as $config) {
            $serverName = $config['SERVER_NAME'];
            $sites[] = [
                "key" => "emby_" . strtolower($serverName),
                "name" => $serverName,
                "type" => 4,
                "api" => $baseUrl . "/emby/emby.php?server=" . urlencode($serverName),
                "style" => [
                    "type" => "rect",
                    "ratio" => 1.33
                ],
                "searchable" => 1,
                "changeable" => 1
            ];
        }
    }
    
    $output = [
        "logo" => $baseUrl . "/emby/emby.png",
        "sites" => $sites
    ];
    
    // 输出 JSON
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($output, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    
} catch (Exception $e) {
    die(json_encode([
        'logo' => $baseUrl . '/emby/emby.png',
        'sites' => [],
        'error' => '获取Emby配置失败: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}
?>