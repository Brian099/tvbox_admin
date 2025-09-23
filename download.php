<?php
// 定义APK文件信息
$apk_files = [
    [
        'name' => '电视端V8a',
        'filename' => 'leanback-arm64_v8a-release.apk',
        'path' => 'APKs/leanback-arm64_v8a-release.apk'
    ],
    [
        'name' => '电视端V7a',
        'filename' => 'leanback-armeabi_v7a-release.apk',
        'path' => 'APKs/leanback-armeabi_v7a-release.apk'
    ],
    [
        'name' => '手机端V8a',
        'filename' => 'mobile-arm64_v8a-release.apk',
        'path' => 'APKs/mobile-arm64_v8a-release.apk'
    ],
    [
        'name' => '手机端V7a',
        'filename' => 'mobile-armeabi_v7a-release.apk',
        'path' => 'APKs/mobile-armeabi_v7a-release.apk'
    ]
];

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
            <p class="description">选择适合您设备的版本进行下载，请确保下载正确的架构版本</p>
			<p class="description">一般都是使用V8a版本的，如果机型较老安装V8a失败可以尝试V7a</p>
        </header>
        
        <div class="download-grid">
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
                        <a href="<?php echo $file['path']; ?>" class="download-btn" download>下载APK</a>
                    <?php else: ?>
                        <button class="download-btn" disabled>文件不存在</button>
                        <p class="file-missing">该文件暂时不可用</p>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        
        <footer>
            <p>© <?php echo date('Y'); ?> APK下载页面 - 所有版本仅供参考</p>
            <p>页面最后更新于: <?php echo date('Y-m-d H:i:s'); ?></p>
        </footer>
    </div>
</body>
</html>