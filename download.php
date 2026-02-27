<?php
// 获取APK目录中的所有文件
$apk_dir = 'APKs';
$apk_files = [];

// 检查APK目录是否存在
if (is_dir($apk_dir)) {
    // 扫描目录中的所有文件
    $files = scandir($apk_dir);
    
    foreach ($files as $file) {
        // 跳过 . 和 .. 目录，以及非APK文件
        if ($file === '.' || $file === '..') continue;
        
        $file_path = $apk_dir . '/' . $file;
        $file_ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        
        // 只处理APK文件
        if ($file_ext === 'apk' && is_file($file_path)) {
            // 根据文件名自动识别类型
            $name = getApkDisplayName($file);
            
            $apk_files[] = [
                'name' => $name,
                'filename' => $file,
                'path' => $file_path,
                'raw_name' => $file // 保存原始文件名用于排序
            ];
        }
    }
    
    // 按名称排序APK文件
    usort($apk_files, function($a, $b) {
        return strcmp($a['raw_name'], $b['raw_name']);
    });
}

// 根据文件名自动生成显示名称
function getApkDisplayName($filename) {
    $name = pathinfo($filename, PATHINFO_FILENAME);
    
    // 定义关键词映射
    $keywords = [
        'leanback' => '电视端',
        'mobile' => '手机端',
        'arm64_v8a' => 'V8a',
        'armeabi_v7a' => 'V7a',
        'x86' => 'x86',
        'x86_64' => 'x64',
        'release' => '正式版',
        'debug' => '调试版',
        'beta' => '测试版',
        'alpha' => '内测版'
    ];
    
    // 替换关键词
    foreach ($keywords as $key => $value) {
        $name = str_replace($key, $value, $name);
    }
    
    // 清理多余的下划线和连字符
    $name = preg_replace('/[_-]+/', ' ', $name);
    $name = trim($name);
    
    // 如果名称太短或没有识别出类型，使用文件名
    if (strlen($name) < 3 || 
        (strpos($name, '电视端') === false && 
         strpos($name, '手机端') === false)) {
        $name = pathinfo($filename, PATHINFO_FILENAME);
    }
    
    return $name;
}

// 获取文件大小和修改时间
foreach ($apk_files as &$file) {
    $file_path = $_SERVER['DOCUMENT_ROOT'] . '/' . $file['path'];
    if (file_exists($file_path)) {
        $file['size'] = filesize($file_path);
        $file['time'] = filemtime($file_path);
        $file['exists'] = true;
    } else {
        $file['size'] = 0;
        $file['time'] = time();
        $file['exists'] = false;
    }
}
unset($file); // 断开引用

// 格式化文件大小
function formatSize($bytes) {
    if ($bytes == 0) return '0 B';
    $k = 1024;
    $sizes = ['B', 'KB', 'MB', 'GB'];
    $i = floor(log($bytes) / log($k));
    return round($bytes / pow($k, $i), 2) . ' ' . $sizes[$i];
}

// 格式化时间
function formatTime($timestamp) {
    return date('Y-m-d H:i:s', $timestamp);
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>APK文件下载</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            line-height: 1.6;
            color: #333;
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            min-height: 100vh;
            padding: 20px;
        }
        
        .container {
            max-width: 1200px;
            margin: 0 auto;
        }
        
        header {
            text-align: center;
            margin-bottom: 40px;
            padding: 30px 0;
            background: white;
            border-radius: 12px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.08);
        }
        
        h1 {
            color: #2c3e50;
            margin-bottom: 15px;
            font-size: 2.5rem;
        }
        
        .description {
            color: #7f8c8d;
            font-size: 1.2rem;
            max-width: 600px;
            margin: 0 auto;
        }
        
        .stats {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            margin: 20px auto;
            max-width: 500px;
            font-size: 1rem;
        }
        
        .download-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 25px;
            margin-bottom: 40px;
        }
        
        .download-card {
            background: white;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.08);
            transition: transform 0.3s ease, box-shadow 0.3s ease;
            display: flex;
            flex-direction: column;
        }
        
        .download-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 15px 30px rgba(0, 0, 0, 0.15);
        }
        
        .card-header {
            background: linear-gradient(135deg, #3498db 0%, #2c3e50 100%);
            color: white;
            padding: 20px;
            text-align: center;
        }
        
        .card-body {
            padding: 25px;
            flex-grow: 1;
            display: flex;
            flex-direction: column;
        }
        
        .file-info {
            margin-bottom: 25px;
            flex-grow: 1;
        }
        
        .info-item {
            display: flex;
            justify-content: space-between;
            margin-bottom: 12px;
            padding-bottom: 10px;
            border-bottom: 1px solid #eee;
        }
        
        .info-label {
            font-weight: 600;
            color: #7f8c8d;
        }
        
        .info-value {
            color: #2c3e50;
            text-align: right;
            word-break: break-all;
        }
        
        .download-btn {
            display: block;
            width: 100%;
            padding: 16px;
            background: linear-gradient(135deg, #2ecc71 0%, #27ae60 100%);
            color: white;
            text-align: center;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 600;
            font-size: 16px;
            transition: all 0.3s ease;
            border: none;
            cursor: pointer;
            margin-top: auto;
        }
        
        .download-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(46, 204, 113, 0.4);
        }
        
        .download-btn:disabled {
            background: linear-gradient(135deg, #95a5a6 0%, #7f8c8d 100%);
            cursor: not-allowed;
        }
        
        .download-btn:disabled:hover {
            transform: none;
            box-shadow: none;
        }
        
        .file-missing {
            color: #e74c3c;
            font-weight: 600;
            text-align: center;
            margin-top: 10px;
        }
        
        .empty-state {
            text-align: center;
            padding: 60px 40px;
            color: #7f8c8d;
            background: white;
            border-radius: 12px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.08);
        }
        
        .empty-state i {
            font-size: 64px;
            margin-bottom: 20px;
            opacity: 0.5;
        }
        
        footer {
            text-align: center;
            margin-top: 50px;
            padding: 25px;
            color: #7f8c8d;
            font-size: 14px;
            background: white;
            border-radius: 12px;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.08);
        }
        
        @media (max-width: 768px) {
            .download-grid {
                grid-template-columns: 1fr;
            }
            
            header {
                margin-bottom: 25px;
                padding: 20px 15px;
            }
            
            h1 {
                font-size: 2rem;
            }
            
            .description {
                font-size: 1rem;
            }
            
            .card-body {
                padding: 20px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <header>
            <h1>APK文件下载中心</h1>
            <p class="description">选择适合您设备的版本进行下载，系统自动检测APK目录中的所有文件</p>
            <p class="description">一般都是使用V8a版本的，如果机型较老安装V8a失败可以尝试V7a</p>
            
            <?php if (!empty($apk_files)): ?>
                <div class="stats">
                    共找到 <strong><?php echo count($apk_files); ?></strong> 个APK文件 | 
                    最后更新: <?php echo date('Y-m-d H:i:s'); ?>
                </div>
            <?php endif; ?>
        </header>
        
        <div class="download-grid">
            <?php if (!empty($apk_files)): ?>
                <?php foreach ($apk_files as $file): ?>
                <div class="download-card">
                    <div class="card-header">
                        <h2><?php echo htmlspecialchars($file['name']); ?></h2>
                    </div>
                    <div class="card-body">
                        <div class="file-info">
                            <div class="info-item">
                                <span class="info-label">文件名称:</span>
                                <span class="info-value"><?php echo htmlspecialchars($file['filename']); ?></span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">文件大小:</span>
                                <span class="info-value"><?php echo formatSize($file['size']); ?></span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">更新时间:</span>
                                <span class="info-value"><?php echo formatTime($file['time']); ?></span>
                            </div>
                        </div>
                        
                        <?php if ($file['exists']): ?>
                            <a href="<?php echo $file['path']; ?>" class="download-btn" download>
                                📥 下载APK
                            </a>
                        <?php else: ?>
                            <button class="download-btn" disabled>文件不存在</button>
                            <p class="file-missing">该文件暂时不可用</p>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="empty-state">
                    <div>📁</div>
                    <h3>未找到APK文件</h3>
                    <p>请在网站根目录创建 <code>APKs</code> 文件夹，并将APK文件放入其中</p>
                    <p>当前检测的目录: <code><?php echo realpath($apk_dir); ?></code></p>
                </div>
            <?php endif; ?>
        </div>
        
        <footer>
            <p>© <?php echo date('Y'); ?> APK下载页面 - 所有版本仅供参考</p>
            <p>页面自动更新 | 检测时间: <?php echo date('Y-m-d H:i:s'); ?></p>
        </footer>
    </div>
</body>
</html>