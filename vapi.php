<?php
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
    echo json_encode(['error'=>'数据库连接失败: '.$e->getMessage()], JSON_UNESCAPED_UNICODE);
    exit;
}

// 模式与参数
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
$name = isset($_GET['name']) ? trim($_GET['name']) : '';
$serverName = '';
if ($authMode === '1') {
    $deviceId = isset($_GET['device_id']) ? trim($_GET['device_id']) : '';
    if ($deviceId === '') {
        http_response_code(400);
        echo json_encode(['error'=>'缺少参数 device_id'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $deviceNameParam = isset($_GET['device_name']) ? trim($_GET['device_name']) : '';
    try {
        $dc = $pdo->query("SHOW COLUMNS FROM devices LIKE 'device_name'")->fetch(PDO::FETCH_ASSOC);
        if (!$dc) { $pdo->exec("ALTER TABLE devices ADD COLUMN device_name VARCHAR(255) DEFAULT NULL AFTER device_id"); }
    } catch (Exception $e) {}
    $stmt = $pdo->prepare("SELECT * FROM devices WHERE device_id = :device_id LIMIT 1");
    $stmt->execute([':device_id' => $deviceId]);
    $device = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($device) {
        $sql = "UPDATE devices SET last_login = :last_login";
        $params = [
            ':last_login' => date('Y-m-d H:i:s'),
            ':device_id'  => $deviceId
        ];
        if ($deviceNameParam !== '') {
            $sql .= ", device_name = :device_name";
            $params[':device_name'] = $deviceNameParam;
        }
        $sql .= " WHERE device_id = :device_id";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
    } else {
        $expireAt = date('Y-m-d', strtotime('+7 days'));
        $stmt = $pdo->prepare("INSERT INTO devices (device_id, device_name, created_at, last_login, server, remark, expire_at) VALUES (:device_id, :device_name, :created_at, :last_login, '', '', :expire_at)");
        $stmt->execute([
            ':device_id'  => $deviceId,
            ':device_name'=> $deviceNameParam !== '' ? $deviceNameParam : null,
            ':created_at' => date('Y-m-d H:i:s'),
            ':last_login' => date('Y-m-d H:i:s'),
            ':expire_at'  => $expireAt
        ]);
        $device = ['server' => '', 'expire_at' => $expireAt];
    }
    if (!empty($device['expire_at']) && strtotime($device['expire_at']) < strtotime(date('Y-m-d'))) {
        http_response_code(403);
        echo json_encode(['error'=>'设备授权已过期'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $serverName = trim($device['server'] ?? '');
    if ($serverName === '') {
        http_response_code(403);
        echo json_encode(['error'=>'设备未绑定分组'], JSON_UNESCAPED_UNICODE);
        exit;
    }
} else {
    $serverName = isset($_GET['ServerName']) ? trim($_GET['ServerName']) : '';
    if ($serverName === '') {
        http_response_code(400);
        echo json_encode(['error'=>'缺少参数 ServerName'], JSON_UNESCAPED_UNICODE);
        exit;
    }
}
if ($name === '') {
    http_response_code(400);
    echo json_encode(['error'=>'缺少参数 name'], JSON_UNESCAPED_UNICODE);
    exit;
}

// ===============================
// 判断远程源还是本地源
// ===============================
$isRemote = false;
$url = '';
$localRepoPath = '';

// 先查 remote repos 表
$stmt = $pdo->prepare("SELECT url FROM repos WHERE name = :name LIMIT 1");
$stmt->execute([':name' => $name]);
$repoUrl = $stmt->fetchColumn();
if ($repoUrl) {
    $isRemote = true;
    $url = $repoUrl;
} else {
    // 查 local_repos 表
    $stmt = $pdo->prepare("SELECT repos_local FROM local_repos WHERE name = :name LIMIT 1");
    $stmt->execute([':name' => $name]);
    $reposLocal = $stmt->fetchColumn();

    if (!$reposLocal) {
        http_response_code(404);
        echo json_encode(['error'=>"未找到 name={$name} 对应的资源"], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $localRepoPath = $reposLocal;
    $filePath = __DIR__ . '/local_repo/' . $reposLocal;
    if (!file_exists($filePath)) {
        http_response_code(404);
        echo json_encode(['error'=>"文件不存在: {$filePath}"], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $url = $filePath;
}

// ===============================
// 获取内容并处理相对路径
// ===============================
if ($isRemote) {
    // 第一次尝试获取内容
    $options = [
        'http' => [
            'method' => 'GET',
            'header' => "User-Agent: okhttp/5.0.0-alpha.14\r\n"
        ]
    ];
    $context = stream_context_create($options);
    $content = @file_get_contents($url, false, $context);
    
    // 如果第一次获取失败且URL包含中文，尝试Punycode转换后再次获取
    if ($content === false && preg_match('/[\\x{4e00}-\\x{9fa5}]/u', $url)) {
        // 解析URL并转换主机名为Punycode
        $urlParts = parse_url($url);
        
        if (isset($urlParts['host'])) {
            if (function_exists('idn_to_ascii')) {
                $host = idn_to_ascii($urlParts['host'], IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            } else {
                $host = $urlParts['host'];
            }
            
            if ($host !== false) {
                $urlParts['host'] = $host;
                // 重新构建URL
                $punycodeUrl = (isset($urlParts['scheme']) ? $urlParts['scheme'] . '://' : '') .
                              $urlParts['host'] .
                              (isset($urlParts['port']) ? ':' . $urlParts['port'] : '') .
                              (isset($urlParts['path']) ? $urlParts['path'] : '') .
                              (isset($urlParts['query']) ? '?' . $urlParts['query'] : '') .
                              (isset($urlParts['fragment']) ? '#' . $urlParts['fragment'] : '');
                
                // 第二次尝试获取内容（使用Punycode URL）
                $content = @file_get_contents($punycodeUrl, false, $context);
            }
        }
    }
    
    // 如果仍然获取失败，直接重定向到原始URL
    if ($content === false) {
        header("Location: $url");
        exit;
    }
    
    // 处理远程源的相对路径
    $baseRemoteUrl = preg_replace('#/[^/]*$#', '', $url); // 去掉最后一个/后面的内容，获取基础URL
    
    // 匹配多个可能的相对路径字段（只处理以 ./ 或 ../ 开头的路径）
    $content = preg_replace_callback(
        '/("(?:spider|jar|api|ext|json)"\s*:\s*")([^"]+)(")/i',
        function($matches) use ($baseRemoteUrl) {
            $relativePath = $matches[2];
            // 如果不是以 http 开头，且以 ./ 或 ../ 开头，则补全为绝对路径
            if (!preg_match('/^https?:\/\//', $relativePath) && preg_match('/^(\.\.?\/)/', $relativePath)) {
                // 去掉开头的 ./ 
                $relativePath = preg_replace('/^\.\//', '', $relativePath);
                
                // 确保 baseRemoteUrl 不以 / 结尾，relativePath 不以 / 开头
                $baseRemoteUrl = rtrim($baseRemoteUrl, '/');
                $relativePath = ltrim($relativePath, '/');
                $fullPath = $baseRemoteUrl . '/' . $relativePath;
                return $matches[1] . $fullPath . $matches[3];
            }
            // 如果已经是绝对路径或者是普通字符串（如 "csp_Config"），保持原样
            return $matches[0];
        },
        $content
    );
} else {
    $content = file_get_contents($url);
    
    // 本地源的路径处理
    if ($localRepoPath) {
        // 计算基础URL
        $protocol = ( (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                    || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
                    || (!empty($_SERVER['HTTP_FRONT_END_HTTPS']) && $_SERVER['HTTP_FRONT_END_HTTPS'] !== 'off')
                    ) ? 'https' : 'http';
        $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : ($_SERVER['SERVER_NAME'] ?? 'localhost');
        $scriptDir = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
        $baseUrl = $protocol . '://' . $host . ($scriptDir === '' ? '' : $scriptDir);
        
        // 从 $localRepoPath 中提取目录路径（去掉文件名）
        $directoryPath = dirname($localRepoPath);
        
        // 匹配多个可能的路径字段（只处理以 ./ 或 ../ 开头的路径）
        $content = preg_replace_callback(
            '/("(?:spider|jar|api|ext|json)"\s*:\s*")([^"]+)(")/i',
            function($matches) use ($baseUrl, $directoryPath) {
                $filePath = $matches[2];
                
                // 只处理以 ./ 或 ../ 开头的相对路径
                if (preg_match('/^(\.\.?\/)/', $filePath)) {
                    // 去掉开头的 ./
                    $filePath = preg_replace('/^\.\//', '', $filePath);
                    
                    // 如果目录路径是 "." 或空，表示文件在根目录
                    if ($directoryPath === '.' || $directoryPath === '') {
                        $encodedPath = '';
                    } else {
                        $encodedPath = implode('/', array_map('rawurlencode', explode('/', $directoryPath))) . '/';
                    }
                    
                    // 对文件路径进行编码（保留目录结构）
                    $encodedFilePath = implode('/', array_map('rawurlencode', explode('/', $filePath)));
                    
                    return $matches[1] . "{$baseUrl}/local_repo/{$encodedPath}{$encodedFilePath}" . $matches[3];
                }
                // 如果不是相对路径（如 "csp_Config"），保持原样
                return $matches[0];
            },
            $content
        );
    }
}

// 去掉单行注释（//）和多行注释（/* ... */）
$content = preg_replace('/^\s*\/\/.*$/m', '', $content);
$content = preg_replace('/\/\*.*?\*\//s', '', $content);

// 去掉 UTF-8 BOM
$content = preg_replace('/^\x{FEFF}/u', '', $content);

// 解析 JSON
$data = json_decode($content, true);
if ($data === null) {
    http_response_code(500);
    echo json_encode(['error'=>"JSON 解析失败: ".json_last_error_msg()], JSON_UNESCAPED_UNICODE);
    exit;
}

// ===============================
// 获取并合并激活的片段数据
// ===============================
$snippetDir = __DIR__ . '/snippet';
$enabledSnippets = [];

// 计算基础URL（移到外部以供后续使用）
$protocol = ( (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
            || (!empty($_SERVER['HTTP_FRONT_END_HTTPS']) && $_SERVER['HTTP_FRONT_END_HTTPS'] !== 'off')
            ) ? 'https' : 'http';
$host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : ($_SERVER['SERVER_NAME'] ?? 'localhost');
$scriptDir = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
$baseUrl = $protocol . '://' . $host . ($scriptDir === '' ? '' : $scriptDir);

if (is_dir($snippetDir)) {
    $items = scandir($snippetDir);
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        
        $snippetItemDir = $snippetDir . '/' . $item;
        if (!is_dir($snippetItemDir)) continue;
        
        // 检查是否有 enable 文件
        $enableFile = $snippetItemDir . '/enable';
        if (!file_exists($enableFile)) continue;
        
        // 查找 JSON 文件
        $jsonFile = null;
        $jsFile = null;
        $files = scandir($snippetItemDir);
        foreach ($files as $file) {
            if ($file === '.' || $file === '..' || $file === 'enable') continue;
            
            $ext = pathinfo($file, PATHINFO_EXTENSION);
            if ($ext === 'json') {
                $jsonFile = $file;
            } elseif ($ext === 'js') {
                $jsFile = $file;
            }
        }
        
        if ($jsonFile && $jsFile) {
            $enabledSnippets[] = [
                'name' => $item,
                'json_file' => $jsonFile,
                'js_file' => $jsFile,
                'directory' => $snippetItemDir
            ];
        }
    }
}

// 处理并合并激活的片段
if (!empty($enabledSnippets)) {
    // 确保主数据中有 sites 数组
    if (!isset($data['sites']) || !is_array($data['sites'])) {
        $data['sites'] = [];
    }
    
    foreach ($enabledSnippets as $snippet) {
        $jsonFilePath = $snippet['directory'] . '/' . $snippet['json_file'];
        if (file_exists($jsonFilePath)) {
            $snippetContent = file_get_contents($jsonFilePath);
            $snippetData = json_decode($snippetContent, true);
            
            if ($snippetData !== null && is_array($snippetData)) {
                // 处理片段中的相对路径
                $processedSnippetData = processSnippetPaths($snippetData, $baseUrl, $snippet['name'], $snippet['js_file']);
                
                // 直接添加到 sites 数组
                $data['sites'][] = $processedSnippetData;
            }
        }
    }
}

// ===============================
// 获取并合并 Emby 站点数据
// ===============================
$embySitesData = [];
try {
    $embySitesUrl = $baseUrl . '/emby/emby_api.php';
    $embyContent = @file_get_contents($embySitesUrl, false, stream_context_create([
        'http' => ['timeout' => 5]
    ]));
    
    if ($embyContent !== false) {
        $embySitesData = json_decode($embyContent, true);
    }
} catch (Exception $e) {
    // 忽略 Emby 站点获取错误，继续处理
}

// 合并 Emby 站点数据到主数据
if (!empty($embySitesData) && isset($embySitesData['sites']) && is_array($embySitesData['sites'])) {
    // 如果主数据中没有 sites 数组，创建它
    if (!isset($data['sites']) || !is_array($data['sites'])) {
        $data['sites'] = [];
    }
    
    // 合并 Emby 站点到主站点列表
    $data['sites'] = array_merge($data['sites'], $embySitesData['sites']);
    
    // 如果 Emby 数据中有 logo 且主数据中没有，也合并 logo
    if (isset($embySitesData['logo']) && !isset($data['logo'])) {
        $data['logo'] = $embySitesData['logo'];
    }
}

// ===============================
// 附加直播源逻辑
// ===============================
$attachLive = 0; // 默认不附加
$stmt = $pdo->prepare("SELECT attach_live FROM duocang_data WHERE ServerName=:ServerName LIMIT 1");
$stmt->execute([':ServerName' => $serverName]);
$attachLiveValue = $stmt->fetchColumn();
if ($attachLiveValue !== false) {
    $attachLive = (int)$attachLiveValue;
}

// 从数据库读取直播源处理方式
$live_merge_method = 'merge'; // 默认值
$stmt = $pdo->query("SELECT LiveMergeMethod FROM setting LIMIT 1");
$result = $stmt->fetch(PDO::FETCH_ASSOC);
if ($result && isset($result['LiveMergeMethod'])) {
    $live_merge_method = $result['LiveMergeMethod'];
}

if ($attachLive) {
    $stmt = $pdo->query("SELECT name, url, ua FROM lives ORDER BY id ASC");
    $liveSources = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!empty($liveSources)) {
        if ($live_merge_method === 'replace') {
            $data['lives'] = [];
        } elseif (!isset($data['lives']) || !is_array($data['lives'])) {
            $data['lives'] = [];
        }

        foreach ($liveSources as $live) {
            $data['lives'][] = [
                'name' => $live['name'],
                'url'  => $live['url'],
                'ua'   => $live['ua']
            ];
        }
    } elseif ($live_merge_method === 'replace') {
        unset($data['lives']);
    }
}

// 输出 JSON
echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
exit;

/**
 * 处理片段中的路径，将相对路径转换为绝对路径
 */
function processSnippetPaths($snippetData, $baseUrl, $snippetName, $jsFileName) {
    if (is_array($snippetData)) {
        foreach ($snippetData as $key => $value) {
            if (is_array($value)) {
                $snippetData[$key] = processSnippetPaths($value, $baseUrl, $snippetName, $jsFileName);
            } elseif ($key === 'api' && is_string($value)) {
                // 处理 api 字段中的相对路径
                if (preg_match('/^(\.\.?\/)/', $value)) {
                    // 去掉开头的 ./
                    $relativePath = preg_replace('/^\.\//', '', $value);
                    // 构建绝对路径
                    $snippetData[$key] = $baseUrl . '/snippet/' . rawurlencode($snippetName) . '/' . rawurlencode($relativePath);
                } elseif ($value === $jsFileName) {
                    // 如果 api 字段直接是 JS 文件名，也转换为绝对路径
                    $snippetData[$key] = $baseUrl . '/snippet/' . rawurlencode($snippetName) . '/' . rawurlencode($jsFileName);
                }
            } elseif (in_array($key, ['spider', 'jar', 'ext', 'json']) && is_string($value)) {
                // 处理其他可能的路径字段
                if (preg_match('/^(\.\.?\/)/', $value)) {
                    $relativePath = preg_replace('/^\.\//', '', $value);
                    $snippetData[$key] = $baseUrl . '/snippet/' . rawurlencode($snippetName) . '/' . rawurlencode($relativePath);
                }
            }
        }
    }
    return $snippetData;
}
?>
