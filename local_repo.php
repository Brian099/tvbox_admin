<?php
session_start();

// 如果未登录则跳转
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
        "mysql:host={$config['db_host']};dbname={$config['db_name']};charset=utf8mb4",
        $config['db_user'],
        $config['db_pass']
    );
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("数据库连接失败: " . $e->getMessage());
}

// =======================
// 封装：更新 local_repos
// =======================
function updateLocalRepos(PDO $pdo) {
    $pdo->exec("TRUNCATE TABLE local_repos");

    $stmt = $pdo->query("SELECT filename FROM local_files");
    $allFiles = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if (empty($allFiles)) return;

    $baseDir = __DIR__ . '/local_repo';
    if (!is_dir($baseDir)) return;

    $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($baseDir));
    foreach ($rii as $file) {
        if ($file->isFile()) {
            $basename = $file->getFilename();
            if (in_array($basename, $allFiles)) {
                $relativePath = str_replace($baseDir . '/', '', $file->getPathname());
                
                // 取上级目录作为 name
                $parts = explode('/', $relativePath);
                $name = (count($parts) > 1) ? $parts[count($parts)-2] : '';

                $stmt = $pdo->prepare("INSERT IGNORE INTO local_repos (name, repos_local) VALUES (:name, :path)");
                $stmt->execute([
                    ':name' => $name,
                    ':path' => $relativePath
                ]);
            }
        }
    }
}


// 处理新增文件名
$message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['new_filename'])) {
    $newFile = trim($_POST['new_filename']);
    if ($newFile !== '') {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM local_files WHERE filename=:filename");
        $stmt->execute([':filename' => $newFile]);
        if ($stmt->fetchColumn() == 0) {
            $stmt = $pdo->prepare("INSERT INTO local_files (filename) VALUES (:filename)");
            $stmt->execute([':filename' => $newFile]);
            $message = "✅ 文件名 {$newFile} 已添加成功！";
        } else {
            $message = "⚠ 文件名 {$newFile} 已存在！";
        }
    }
    // 每次新增都更新 local_repos
    updateLocalRepos($pdo);
}

// 删除文件名
if (isset($_GET['delete']) && $_GET['delete'] !== '') {
    $delFile = trim($_GET['delete']);
    $stmt = $pdo->prepare("DELETE FROM local_files WHERE filename=:filename");
    $stmt->execute([':filename' => $delFile]);

    // 每次删除也更新 local_repos
    updateLocalRepos($pdo);

    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

// 获取所有本地文件名
$stmt = $pdo->query("SELECT filename FROM local_files ORDER BY filename ASC");
$files = $stmt->fetchAll(PDO::FETCH_COLUMN);
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>本地资源管理 - 系统管理</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <?php include "header.php"; ?>
    
    <div class="container">
        <h1 class="page-title">本地资源管理</h1>
        
        <div class="main-content fade-in">
            <?php if($message): ?>
                <div class="message <?= strpos($message, '⚠') !== false ? 'warning' : '' ?>">
                    <?= $message ?>
                </div>
            <?php endif; ?>
            
            <!-- 统计信息 -->
            <div class="stat-card success">
                <div class="stat-number"><?= count($files) ?></div>
                <div class="stat-label">已配置的本地文件</div>
            </div>

            
            <!-- 添加文件名表单 -->
            <div class="card">
                <h3 style="margin-bottom: 20px; color: var(--secondary-color);">添加本地文件名</h3>
                <form method="post">
                    <div class="form-row">
                        <div>
                            <label class="form-label">文件名</label>
                            <input type="text" name="new_filename" class="form-input" 
                                   placeholder="输入要遍历的本地文件名, 例如：api.json、jsm.json、FongMi.json" required>
                        </div>
                        <button type="submit" class="btn btn-success">
                            ➕ 添加文件名
                        </button>
                    </div>
                </form>
            </div>
            
            <!-- 文件列表 -->
            <div class="card">
                <h3 style="margin-bottom: 20px; color: var(--secondary-color);">
                    已添加的本地文件 (<?= count($files) ?>)
                </h3>
                
                <?php if (empty($files)): ?>
                    <div class="empty-state">
                        <div>📁</div>
                        <h3>暂无本地文件</h3>
                        <p>请添加第一个文件名以开始管理本地资源</p>
                    </div>
                <?php else: ?>
                    <div class="file-list">
                        <?php foreach($files as $f): ?>
                            <div class="file-item">
                                <div>
                                    <span class="file-icon">📄</span>
                                    <span class="file-name"><?=htmlspecialchars($f)?></span>
                                </div>
                                <button class="btn btn-danger" type="button" 
                                        onclick="confirmDelete('<?=htmlspecialchars($f)?>')">
                                    🗑 删除
                                </button>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
		 <!-- 使用说明 -->
            <div class="card">
                <h3 style="margin-bottom: 15px; color: var(--secondary-color);">使用说明</h3>
                <div style="color: var(--text-secondary); line-height: 1.6;">
                    <p>• 添加的文件名将用于系统遍历本地资源文件</p>
					<p>• 文件名需要自行去local_repo目录查找</p>
                    <p>• 支持多个json文件</p>
                    <p>• 系统会自动扫描这些文件并提取可用资源</p>
                    <p>• 删除文件名将停止对该文件的扫描和资源提取</p>
                </div>
            </div>
        </div>
    </div>
    </div>

    <script>
        function confirmDelete(file) {
            if (confirm("确定要删除文件 \"" + file + "\" 吗？此操作将停止对该文件的扫描。")) {
                window.location = "?delete=" + encodeURIComponent(file);
            }
        }
    </script>
</body>
</html>
