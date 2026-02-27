<?php
// api.php
header('Content-Type: application/json; charset=utf-8');

// 配置文件路径
$configFile = __DIR__ . '/config/config.php';
if (!file_exists($configFile)) {
    http_response_code(500);
    echo json_encode(['error' => '系统未初始化'], JSON_UNESCAPED_UNICODE);
    exit;
}
$config = require $configFile;

// 连接数据库
try {
    $pdo = new PDO(
        "mysql:host=" . $config['db_host'] . ";dbname=" . $config['db_name'] . ";charset=utf8mb4",
        $config['db_user'],
        $config['db_pass']
    );
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => '数据库连接失败'], JSON_UNESCAPED_UNICODE);
    exit;
}

// 模式
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

// 获取参数
$deviceId = isset($_GET['device_id']) ? trim($_GET['device_id']) : '';
$deviceName = isset($_GET['device_name']) ? trim($_GET['device_name']) : '';
$serverName = isset($_GET['ServerName']) ? trim($_GET['ServerName']) : '';

if ($authMode === '1') {
    if ($deviceId === '') {
        echo json_encode(['error' => '缺少 device_id 参数'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    // 设备表结构增强
    try {
        $dc = $pdo->query("SHOW COLUMNS FROM devices LIKE 'device_name'")->fetch(PDO::FETCH_ASSOC);
        if (!$dc) { $pdo->exec("ALTER TABLE devices ADD COLUMN device_name VARCHAR(255) DEFAULT NULL AFTER device_id"); }
    } catch (Exception $e) {}
    // 查找设备
    $stmt = $pdo->prepare("SELECT * FROM devices WHERE device_id = :device_id LIMIT 1");
    $stmt->execute([':device_id' => $deviceId]);
    $device = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($device) {
        $sql = "UPDATE devices SET last_login = :last_login";
        $params = [
            ':last_login' => date('Y-m-d H:i:s'),
            ':device_id'  => $deviceId
        ];
        if ($deviceName !== '') {
            $sql .= ", device_name = :device_name";
            $params[':device_name'] = $deviceName;
        }
        $sql .= " WHERE device_id = :device_id";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $serverName = (string)($device['server'] ?? '');
    } else {
        // 自动注册新设备
        $expireAt = date('Y-m-d', strtotime('+7 days'));
        $stmt = $pdo->prepare("INSERT INTO devices (device_id, device_name, created_at, last_login, server, remark, expire_at) VALUES (:device_id, :device_name, :created_at, :last_login, '', '', :expire_at)");
        $stmt->execute([
            ':device_id'  => $deviceId,
            ':device_name'=> $deviceName !== '' ? $deviceName : null,
            ':created_at' => date('Y-m-d H:i:s'),
            ':last_login' => date('Y-m-d H:i:s'),
            ':expire_at'  => $expireAt
        ]);
        $serverName = '';
    }
    // 若未绑定分组，返回提示但设备已记录
    if ($serverName === '') {
        echo json_encode(['error' => '设备未绑定分组'], JSON_UNESCAPED_UNICODE);
        exit;
    }
} else {
    if ($serverName === '') {
        echo json_encode(['error' => '缺少 ServerName 参数'], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// 计算 base URL（用于构建 vapi 链接）
$scheme = 'http';
if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
    $scheme = 'https';
} elseif (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
    $protoHeader = strtolower(trim($_SERVER['HTTP_X_FORWARDED_PROTO']));
    $proto = explode(',', $protoHeader)[0];
    if ($proto === 'https') {
        $scheme = 'https';
    }
} elseif (!empty($_SERVER['HTTP_X_FORWARDED_SSL']) && strtolower($_SERVER['HTTP_X_FORWARDED_SSL']) === 'on') {
    $scheme = 'https';
} elseif (!empty($_SERVER['REQUEST_SCHEME'])) {
    $scheme = strtolower($_SERVER['REQUEST_SCHEME']) === 'https' ? 'https' : 'http';
} elseif (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443) {
    $scheme = 'https';
}
$host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : ($_SERVER['SERVER_NAME'] ?? 'localhost');
$scriptDir = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
$baseUrl = $scheme . '://' . $host . ($scriptDir === '' ? '' : $scriptDir);

// 读取 duocang_data 配置
$stmt = $pdo->prepare("SELECT repos, attach_local FROM duocang_data WHERE ServerName = :ServerName LIMIT 1");
$stmt->execute([':ServerName' => $serverName]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row) {
    echo json_encode([
        'error'  => '对应的分组配置不存在',
        'server' => $serverName
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$result = [];
$existingNames = []; // 用于去重（避免本地与远程同名重复）

// 1) 按保存顺序处理 duocang_data.repos（存储的是仓库名称，以 ; 分隔）
if (!empty($row['repos'])) {
    // 支持分号分隔并去掉空项
    $repoNames = array_values(array_filter(array_map('trim', explode(';', $row['repos'])), function($v){ return $v !== ''; }));
    foreach ($repoNames as $name) {
        // 忽略空 name
        if ($name === '') continue;
        // 记录并输出指向 vapi 的统一入口：vapi.php?name=NAME
        $existingNames[$name] = true;
        if ($authMode === '1') {
            $result[] = [
                'name' => $name,
                'url'  => $baseUrl . '/vapi.php?device_id=' . urlencode($deviceId) . '&name=' . urlencode($name)
            ];
        } else {
            $result[] = [
                'name' => $name,
                'url'  => $baseUrl . '/vapi.php?ServerName=' . urlencode($serverName) . '&name=' . urlencode($name)
            ];
        }
    }
}

// 2) 如果需要附加本地源，遍历 local_files 表并查找 local_repo 中的匹配文件
if (!empty($row['attach_local'])) {
    // 读取需要查找的文件名列表
    $stmt2 = $pdo->query("SELECT filename FROM local_files");
    $localFiles = $stmt2->fetchAll(PDO::FETCH_COLUMN);

    if (!empty($localFiles)) {
        $baseDir = __DIR__ . '/local_repo';
        if (is_dir($baseDir)) {
            // 为效率，将 localFiles 转为快速查找键数组
            $targets = array_flip($localFiles);

            // 递归遍历 local_repo
            $it = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($baseDir, FilesystemIterator::SKIP_DOTS)
            );

            foreach ($it as $file) {
                if (!$file->isFile()) continue;
                $filename = $file->getFilename();
                if (!isset($targets[$filename])) continue;

                // 父目录名作为 name（例如 local_repo/tvboxqq/OK/api.json -> name = OK）
                $parentName = basename($file->getPathInfo()->getFilename() === '' ? dirname($file->getPathname()) : $file->getPathInfo()->getFilename());
                // 上述获取 parentName 更稳妥的方法：
                $parentName = basename(dirname($file->getPathname()));
                if ($parentName === '') continue;

                // 如果该 name 已存在于分组（远程仓库或已添加本地），则跳过以避免重复
                if (isset($existingNames[$parentName])) {
                    continue;
                }

                // 否则加入结果，url 也指向 vapi.php?name=NAME（vapi 内再判断该 name 是本地还是 repo）
                $existingNames[$parentName] = true;
                if ($authMode === '1') {
                    $result[] = [
                        'name' => $parentName,
                        'url'  => $baseUrl . '/vapi.php?device_id=' . urlencode($deviceId) . '&name=' . urlencode($parentName)
                    ];
                } else {
                    $result[] = [
                        'name' => $parentName,
                        'url'  => $baseUrl . '/vapi.php?ServerName=' . urlencode($serverName) . '&name=' . urlencode($parentName)
                    ];
                }
            }
        }
    }
}

// 输出（授权模式下进行加密/混淆）
$data = json_encode(['urls' => $result], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if ($authMode === '1') {
    // 自定义 base64 字母表
    $obfuscate = function(string $payload): string {
        $custom_b64 = function(string $d): string {
            $b64 = base64_encode($d);
            return strtr($b64, 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/', 'QWERTYUIOPASDFGHJKLZXCVBNMpoiuytrewqasdfghjklmnbvcxz1234567890-_');
        };
        $insert_noise = function(string $str, array $noiseArray = ['#','@','&','%','*'], int $interval = 5): string {
            $out = '';
            $len = strlen($str);
            $noiseCount = count($noiseArray);
            for ($i=0; $i<$len; $i++) {
                $out .= $str[$i];
                if (($i+1) % $interval === 0 && $i+1 !== $len) {
                    $out .= $noiseArray[random_int(0, $noiseCount - 1)];
                }
            }
            return $out;
        };
        $b64 = $custom_b64($payload);
        $rev = strrev($b64);
        $noisy = $insert_noise($rev);
        return bin2hex($noisy);
    };
    echo $obfuscate($data);
    exit;
} else {
    echo $data;
    exit;
}
