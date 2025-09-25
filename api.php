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

// 允许的 package_name
$package_name_OK = "com.fongmi.android.tv";

// 获取参数
$package_name = isset($_GET['package_name']) ? trim($_GET['package_name']) : '';
$deviceId     = isset($_GET['device_id']) ? trim($_GET['device_id']) : '';

if ($package_name !== $package_name_OK) {
    echo json_encode(['error' => '参数错误'], JSON_UNESCAPED_UNICODE);
    exit;
}

$serverFile = '';

// 查找设备（并更新 last_login / 新设备插入并设置过期）
if ($deviceId !== '') {
    $stmt = $pdo->prepare("SELECT * FROM devices WHERE device_id = :device_id LIMIT 1");
    $stmt->execute([':device_id' => $deviceId]);
    $device = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($device) {
        $serverFile = trim($device['server']);

        // 更新 last_login
        $stmt = $pdo->prepare("UPDATE devices SET last_login = :last_login WHERE device_id = :device_id");
        $stmt->execute([
            ':last_login' => date('Y-m-d H:i:s'),
            ':device_id'  => $deviceId
        ]);
    } else {
        // 新设备插入，expire_at 默认为今天+7天
        $expireAt = date('Y-m-d', strtotime('+7 days'));
        $stmt = $pdo->prepare("INSERT INTO devices (device_id, created_at, last_login, server, remark, expire_at) VALUES (:device_id, :created_at, :last_login, '', '', :expire_at)");
        $stmt->execute([
            ':device_id'  => $deviceId,
            ':created_at' => date('Y-m-d H:i:s'),
            ':last_login' => date('Y-m-d H:i:s'),
            ':expire_at'  => $expireAt
        ]);
    }
}

// 计算 base URL（用于构建 vapi 链接）
$protocol = ( (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
            || (!empty($_SERVER['HTTP_FRONT_END_HTTPS']) && $_SERVER['HTTP_FRONT_END_HTTPS'] !== 'off')
            ) ? 'https' : 'http';

$host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : ($_SERVER['SERVER_NAME'] ?? 'localhost');
$scriptDir = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\'); // 例如 "/folder" 或 ""
$baseUrl = $protocol . '://' . $host . ($scriptDir === '' ? '' : $scriptDir); // 不含尾斜杠

// 返回 JSON
if ($serverFile !== '') {
    // 读取 duocang_data
    $stmt = $pdo->prepare("SELECT repos, attach_local FROM duocang_data WHERE ServerName = :ServerName LIMIT 1");
    $stmt->execute([':ServerName' => $serverFile]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        echo json_encode([
            'error'  => '对应的 server 配置不存在',
            'server' => $serverFile
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
            $result[] = [
                'name' => $name,
                'url'  => $baseUrl . '/vapi.php?server=' . urlencode($serverFile) . '&name=' . urlencode($name)
            ];
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
                    $result[] = [
                        'name' => $parentName,
                        'url'  => $baseUrl . '/vapi.php?server=' . urlencode($serverFile) . '&name=' . urlencode($parentName)
                    ];
                }
            }
        }
    }

    // 输出最终合并后的 urls（保持原有格式）
    echo json_encode(['urls' => $result], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
} else {
    echo json_encode([
        'error'     => '未配置 server',
        'device_id' => $deviceId
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
