<?php
session_start();
// 配置文件路径
$configFile = __DIR__ . '/config/config.php';

// 如果配置文件不存在，跳转到 init.php 初始化
if (!file_exists($configFile)) {
    header('Location: init.php');
    exit;
}

// 如果未登录则跳转到 login.php
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: login.php');
    exit;
}

// 处理登出
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    session_destroy();
    header('Location: login.php');
    exit;
}

// 加载数据库配置
$config = require __DIR__ . '/config/config.php';

try {
    $pdo = new PDO(
        "mysql:host=".$config['db_host'].";dbname=".$config['db_name'].";charset=utf8mb4",
        $config['db_user'],
        $config['db_pass']
    );
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("数据库连接失败: " . $e->getMessage());
}

// 检查授权模式
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

// 获取统计数据
// 1. 多仓分组统计
$stmt = $pdo->query("SELECT COUNT(*) as total, 
                     SUM(attach_local) as with_local, 
                     SUM(attach_live) as with_live 
                     FROM duocang_data");
$groupStats = $stmt->fetch(PDO::FETCH_ASSOC);

// 2. 仓库统计
$stmt = $pdo->query("SELECT COUNT(*) as total FROM repos");
$repoStats = $stmt->fetch(PDO::FETCH_ASSOC);

// 3. 直播源统计
$stmt = $pdo->query("SELECT COUNT(*) as total FROM lives");
$liveStats = $stmt->fetch(PDO::FETCH_ASSOC);

// 4. 本地文件统计
$stmt = $pdo->query("SELECT COUNT(*) as total FROM local_files");
$localFileStats = $stmt->fetch(PDO::FETCH_ASSOC);

// 5. Emby配置统计
$stmt = $pdo->query("SELECT COUNT(*) as total FROM emby");
$embyStats = $stmt->fetch(PDO::FETCH_ASSOC);

// 6. 设备统计（仅在授权模式开启时）
$deviceStats = ['total' => 0];
if ($authMode === '1') {
    try {
        // 检查 devices 表是否存在
        $tableExists = $pdo->query("SHOW TABLES LIKE 'devices'")->fetch();
        if ($tableExists) {
            $stmt = $pdo->query("SELECT COUNT(*) as total FROM devices");
            $deviceStats = $stmt->fetch(PDO::FETCH_ASSOC);
        }
    } catch (Exception $e) {
        // 忽略错误
    }
}

// 7. 系统信息
$systemInfo = [
    'php_version' => PHP_VERSION,
    'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? '未知',
    'mysql_version' => $pdo->getAttribute(PDO::ATTR_SERVER_VERSION),
    'upload_max_filesize' => ini_get('upload_max_filesize'),
    'memory_limit' => ini_get('memory_limit'),
    'max_execution_time' => ini_get('max_execution_time'),
    'timezone' => date_default_timezone_get()
];
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>系统概览 - 多仓管理系统</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <?php include "header.php"; ?>
    
    <div class="container">
        <h1 class="page-title">系统概览</h1>
        
        <div class="main-content fade-in">
            <!-- 核心统计 -->
            <div class="dashboard-section">
                <h2 class="section-title">📊 核心统计</h2>
                <div class="stats-grid">
                    <div class="stat-card" onclick="location.href='duocang.php'">
                        <div class="stat-number"><?= $groupStats['total'] ?? 0 ?></div>
                        <div class="stat-label">多仓分组</div>
                        <div class="stat-subtext">
                            <?= $groupStats['with_local'] ?? 0 ?> 含本地资源 | 
                            <?= $groupStats['with_live'] ?? 0 ?> 含直播源
                        </div>
                    </div>
                    
                    <div class="stat-card info" onclick="location.href='repo.php'">
                        <div class="stat-number"><?= $repoStats['total'] ?? 0 ?></div>
                        <div class="stat-label">远程仓库</div>
                        <div class="stat-subtext">可用的数据源</div>
                    </div>
                    
                    <div class="stat-card purple" onclick="location.href='live.php'">
                        <div class="stat-number"><?= $liveStats['total'] ?? 0 ?></div>
                        <div class="stat-label">直播源</div>
                        <div class="stat-subtext">直播频道配置</div>
                    </div>
                    
                    <div class="stat-card orange" onclick="location.href='local.php'">
                        <div class="stat-number"><?= $localFileStats['total'] ?? 0 ?></div>
                        <div class="stat-label">本地文件</div>
                        <div class="stat-subtext">本地资源文件</div>
                    </div>
                    
                    <div class="stat-card teal" onclick="location.href='emby.php'">
                        <div class="stat-number"><?= $embyStats['total'] ?? 0 ?></div>
                        <div class="stat-label">Emby配置</div>
                        <div class="stat-subtext">媒体服务器配置</div>
                    </div>

                    <?php if ($authMode === '1'): ?>
                    <div class="stat-card info" onclick="location.href='devices.php'">
                        <div class="stat-number"><?= $deviceStats['total'] ?? 0 ?></div>
                        <div class="stat-label">授权设备</div>
                        <div class="stat-subtext">已登记的设备</div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- 快速操作 -->
            <div class="dashboard-section">
                <h2 class="section-title">🚀 快速操作</h2>
                <div class="quick-actions">
                    <a href="edit_repo_group.php" class="action-btn">
                        <span class="action-icon">📁</span>
                        <span>多仓分组管理</span>
                    </a>
                    <a href="edit_repos.php" class="action-btn">
                        <span class="action-icon">📦</span>
                        <span>远程仓库管理</span>
                    </a>
                    <a href="edit_lives.php" class="action-btn">
                        <span class="action-icon">📺</span>
                        <span>直播源管理</span>
                    </a>
                    <a href="local_repo.php" class="action-btn">
                        <span class="action-icon">💾</span>
                        <span>本地文件管理</span>
                    </a>
                    <a href="edit_emby.php" class="action-btn">
                        <span class="action-icon">🎬</span>
                        <span>Emby配置</span>
                    </a>
                    <a href="download.php" class="action-btn">
                        <span class="action-icon">📱</span>
                        <span>APK下载</span>
                    </a>
                </div>
            </div>
            
            <!-- 系统信息 -->
            <div class="dashboard-section">
                <h2 class="section-title">⚙️ 系统信息</h2>
                <div class="card">
                    <div class="system-info-grid">
                        <!-- 服务器信息组 -->
                        <div class="info-group">
                            <div class="info-group-title">
                                <span>🖥️</span>
                                <span>服务器信息</span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">PHP版本</span>
                                <span class="info-value"><?= $systemInfo['php_version'] ?></span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">服务器软件</span>
                                <span class="info-value"><?= $systemInfo['server_software'] ?></span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">时区设置</span>
                                <span class="info-value"><?= $systemInfo['timezone'] ?></span>
                            </div>
                        </div>
                        
                        <!-- 数据库信息组 -->
                        <div class="info-group">
                            <div class="info-group-title">
                                <span>🗄️</span>
                                <span>数据库信息</span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">MySQL版本</span>
                                <span class="info-value"><?= $systemInfo['mysql_version'] ?></span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">数据库名称</span>
                                <span class="info-value"><?= $config['db_name'] ?></span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">数据库主机</span>
                                <span class="info-value"><?= $config['db_host'] ?></span>
                            </div>
                        </div>
                        
                        <!-- 系统配置组 -->
                        <div class="info-group">
                            <div class="info-group-title">
                                <span>⚡</span>
                                <span>系统配置</span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">内存限制</span>
                                <span class="info-value"><?= $systemInfo['memory_limit'] ?></span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">上传限制</span>
                                <span class="info-value"><?= $systemInfo['upload_max_filesize'] ?></span>
                            </div>
                            <div class="info-item">
                                <span class="info-label">执行超时</span>
                                <span class="info-value"><?= $systemInfo['max_execution_time'] ?> 秒</span>
                            </div>
                        </div>
                    </div>
                    
                    <!-- 时间显示 -->
                    <div class="time-display">
                        <div class="time-label">服务器当前时间</div>
                        <div class="time-value" id="currentTime"><?= date('Y-m-d H:i:s') ?></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        // 增强交互效果
        document.addEventListener('DOMContentLoaded', function() {
            // 统计卡片点击效果
            const statCards = document.querySelectorAll('.stat-card');
            statCards.forEach(card => {
                card.addEventListener('click', function() {
                    this.style.transform = 'scale(0.95)';
                    setTimeout(() => {
                        this.style.transform = '';
                    }, 150);
                });
            });
            
            // 实时更新时间
            function updateTime() {
                const now = new Date();
                document.getElementById('currentTime').textContent = 
                    now.toLocaleString('zh-CN', { 
                        year: 'numeric', 
                        month: '2-digit', 
                        day: '2-digit',
                        hour: '2-digit',
                        minute: '2-digit',
                        second: '2-digit'
                    });
            }
            
            // 每秒更新一次时间
            setInterval(updateTime, 1000);
        });
    </script>
</body>
</html>
