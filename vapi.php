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

// 参数
$device_id = isset($_GET['device_id']) ? trim($_GET['device_id']) : '';
$name = isset($_GET['name']) ? trim($_GET['name']) : '';

if ($device_id === '') {
    http_response_code(400);
    echo json_encode(['error'=>'缺少参数 device_id'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($name === '') {
    http_response_code(400);
    echo json_encode(['error'=>'缺少参数 name'], JSON_UNESCAPED_UNICODE);
    exit;
}

// ===============================
// 检查设备授权状态
// ===============================
$stmt = $pdo->prepare("SELECT server, expire_at FROM devices WHERE device_id = :device_id LIMIT 1");
$stmt->execute([':device_id' => $device_id]);
$device = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$device) {
    http_response_code(403);
    echo json_encode(['error'=>'设备未注册或不存在'], JSON_UNESCAPED_UNICODE);
    exit;
}

// 检查设备是否过期
$expire_at = $device['expire_at'];
if ($expire_at && strtotime($expire_at) < time()) {
    http_response_code(403);
    echo json_encode(['error'=>'设备授权已过期'], JSON_UNESCAPED_UNICODE);
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
// 获取内容
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
} else {
    $content = file_get_contents($url);
}

// 去掉单行注释（//）和多行注释（/* ... */）
$content = preg_replace('/^\s*\/\/.*$/m', '', $content);
$content = preg_replace('/\/\*.*?\*\//s', '', $content);

// 去掉 UTF-8 BOM
$content = preg_replace('/^\x{FEFF}/u', '', $content);

// ===============================
// 本地源 spider 路径替换
// ===============================
if (!$isRemote && $localRepoPath) {
    // 假设域名和端口由配置文件提供
    $baseUrl = rtrim($config['domain'], '/'); 
    // 替换 spider 路径
    $content = preg_replace_callback(
        '/("spider"\s*:\s*")([^"]+)(")/i',
        function($matches) use ($baseUrl, $localRepoPath) {
            $filename = basename($matches[2]);
            $encodedPath = implode('/', array_map('rawurlencode', explode('/', $localRepoPath)));
            return $matches[1] . "{$baseUrl}/local_repo/{$encodedPath}/{$filename}" . $matches[3];
        },
        $content
    );
}

// 解析 JSON
$data = json_decode($content, true);
if ($data === null) {
    http_response_code(500);
    echo json_encode(['error'=>"JSON 解析失败: ".json_last_error_msg()], JSON_UNESCAPED_UNICODE);
    exit;
}

// ===============================
// 获取并合并 Emby 站点数据
// ===============================
$embySitesData = [];
try {
    $embySitesUrl = $config['domain'] . '/emby/emby_sites.php';
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
$stmt->execute([':ServerName' => $name]);
$attachLiveValue = $stmt->fetchColumn();
if ($attachLiveValue !== false) {
    $attachLive = (int)$attachLiveValue;
}

$replace = 0; // 1=替换，0=附加
if ($attachLive) {
    $stmt = $pdo->query("SELECT name, url, ua FROM lives ORDER BY id ASC");
    $liveSources = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!empty($liveSources)) {
        if ($replace === 1) {
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
    } elseif ($replace === 1) {
        unset($data['lives']);
    }
}

// ===============================
// 检测调试模式和UA
// ===============================
$debugMode = isset($config['debug']) ? (int)$config['debug'] : 0;
$userAgent = $_SERVER['HTTP_USER_AGENT'] ?? '';

// 如果调试模式关闭且UA中不包含okhttp，则输出提示文字
if ($debugMode === 0 && stripos($userAgent, 'okhttp') === false) {
    $message = "请使用官方APP访问本接口，禁止直接通过浏览器访问。";
    
    // 可以选择输出纯文本或JSON格式的错误信息
    header('Content-Type: text/plain; charset=utf-8');
    echo $message;
    exit;
}

// 输出 JSON
echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
exit;
?>