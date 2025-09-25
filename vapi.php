<?php
// vapi.php
header('Content-Type: application/json; charset=utf-8');

// 配置文件路径
$configFile = __DIR__ . '/config/config.php';
if (!file_exists($configFile)) {
    http_response_code(500);
    echo json_encode(['error'=>'系统未初始化'], JSON_UNESCAPED_UNICODE);
    exit;
}
$config = require $configFile;

// 连接数据库
try {
    $pdo = new PDO(
        "mysql:host={$config['db_host']};dbname={$config['db_name']};charset=utf8mb4",
        $config['db_user'],
        $config['db_pass']
    );
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error'=>'数据库连接失败'], JSON_UNESCAPED_UNICODE);
    exit;
}

// 获取参数
$name = isset($_GET['name']) ? trim($_GET['name']) : '';
$ServerName = isset($_GET['server']) ? trim($_GET['server']) : '';

if ($name === '' || $ServerName === '' )  {
    echo json_encode(['error'=>'缺少参数'], JSON_UNESCAPED_UNICODE);
    exit;
}

// 直接查询 duocang_data，获取 attach_live
$attachLive = 0; // 默认 0
$stmt = $pdo->prepare("SELECT attach_live FROM duocang_data WHERE ServerName=:ServerName LIMIT 1");
$stmt->execute([':ServerName' => $ServerName]); // 用 $name 作为 ServerName
$attachLiveValue = $stmt->fetchColumn();

if ($attachLiveValue !== false && $attachLiveValue !== null) {
    $attachLive = (int)$attachLiveValue; // 确保是 0 或 1
}


// 判断来源：repo 还是 local
$stmt = $pdo->prepare("SELECT 1 FROM repos WHERE name=:name LIMIT 1");
$stmt->execute([':name' => $name]);
$isRepo = $stmt->fetchColumn() ? true : false;

if ($isRepo) {
    // 远程 repo，获取对应 url
    $stmt = $pdo->prepare("SELECT url FROM repos WHERE name=:name LIMIT 1");
    $stmt->execute([':name' => $name]);
    $repoUrl = $stmt->fetchColumn();
    $jsonContent = @file_get_contents($repoUrl);
} else {
    // 本地源，遍历 local_repo 找到对应目录
    $baseDir = 'local_repo';
    $jsonContent = '';
    $targetDir = '';

    if (is_dir($baseDir)) {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($baseDir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($it as $file) {
            if ($file->isDir() && basename($file->getPathname()) === $name) {
                $targetDir = $file->getPathname();
                break; // 找到对应目录就退出
            }
        }

        if ($targetDir) {
            // 查询 local_files 表，获取允许的文件名
            $stmt = $pdo->query("SELECT filename FROM local_files");
            $localFiles = $stmt->fetchAll(PDO::FETCH_COLUMN);

            foreach ($localFiles as $filename) {
                $filePath = $targetDir . '/' . $filename;
                if (file_exists($filePath) && is_file($filePath)) {
                    $jsonContent = file_get_contents($filePath);
					// 去掉开头可能的注释行
					$jsonContent = preg_replace('/^\s*\/\/.*$/m', '', $jsonContent);
					$data = json_decode($jsonContent, true);
					
                    break; // 找到第一个文件就退出
                }
            }
        }
    }

    if (!$jsonContent) {
        http_response_code(404);
        echo json_encode(['error'=>"本地源 {$name} 未找到对应文件"], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

if (!$jsonContent) {
    echo json_encode(['error'=>"未找到对应资源: {$name}"], JSON_UNESCAPED_UNICODE);
    exit;
}

// 解析 JSON
$data = json_decode($jsonContent, true);
if ($data === null) {
    echo json_encode(['error'=>"资源 {$name} 的 JSON 无效"], JSON_UNESCAPED_UNICODE);
    exit;
}

// 如果 attach_live=1，处理附加直播源逻辑
$replace = 0; // 1=替换，0=附加

if ($attachLive) {
    // 从数据库读取直播源
    $stmt = $pdo->query("SELECT name, url, ua FROM lives ORDER BY id ASC");
    $liveSources = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!empty($liveSources)) {
        if ($replace === 1) {
            // 替换原有 lives
            $data['lives'] = [];
        } elseif (!isset($data['lives']) || !is_array($data['lives'])) {
            // 追加模式下，如果原 JSON 没有 lives，则初始化
            $data['lives'] = [];
        }

        // 添加直播源
        foreach ($liveSources as $live) {
            $data['lives'][] = [
                'name' => $live['name'],
                'url'  => $live['url'],
                'ua'   => $live['ua']
            ];
        }
    } elseif ($replace === 1) {
        // 替换模式且数据库没有直播源，删除原有字段
        unset($data['lives']);
    }
}


// 输出最终结果
echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
exit;
