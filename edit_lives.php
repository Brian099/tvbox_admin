<?php
session_start();
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: login.php'); 
    exit;
}

$config = require __DIR__.'/config/config.php';
try {
    $pdo = new PDO("mysql:host={$config['db_host']};dbname={$config['db_name']};charset=utf8mb4", $config['db_user'], $config['db_pass']);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Exception $e) {
    die("数据库连接失败: ".$e->getMessage());
}

// 获取当前设置
$live_merge_method = 'merge'; // 默认值
$stmt = $pdo->query("SELECT LiveMergeMethod FROM setting LIMIT 1");
$result = $stmt->fetch(PDO::FETCH_ASSOC);
if ($result && isset($result['LiveMergeMethod'])) {
    $live_merge_method = $result['LiveMergeMethod'];
}

// 更新设置
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['update_setting'])) {
    $new_method = $_POST['live_merge_method'] === 'replace' ? 'replace' : 'merge';
    
    // 检查是否存在记录
    $stmt = $pdo->query("SELECT COUNT(*) FROM setting");
    $count = $stmt->fetchColumn();
    
    if ($count > 0) {
        $stmt = $pdo->prepare("UPDATE setting SET LiveMergeMethod = ?");
    } else {
        $stmt = $pdo->prepare("INSERT INTO setting (LiveMergeMethod) VALUES (?)");
    }
    $stmt->execute([$new_method]);
    
    $live_merge_method = $new_method;
    $success_msg = "设置已更新！";
}

// 新增 live
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['add_live'])) {
    $name = trim($_POST['name']);
    $url  = trim($_POST['url']);
    $ua  = trim($_POST['ua']);
    if ($name && $url && $ua) {
        $stmt = $pdo->prepare("INSERT INTO lives (name, url, ua) VALUES (:name,:url,:ua) ON DUPLICATE KEY UPDATE url=VALUES(url)");
        $stmt->execute([':name'=>$name, ':url'=>$url, ':ua'=>$ua]);
    }
}

// 删除 live
if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    $pdo->prepare("DELETE FROM lives WHERE id=?")->execute([$id]);
    header("Location: edit_lives.php"); 
    exit;
}

// 获取所有 live
$stmt = $pdo->query("SELECT * FROM lives ORDER BY id ASC");
$lives = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>直播源管理 - 系统管理</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .form-row { display: grid; grid-template-columns: 1fr 2fr 2fr auto; gap: 15px; align-items: end; }
        @media (max-width: 768px) { .form-row { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
    <?php include "header.php"; ?>
    
    <div class="container">
        <h1 class="page-title">直播源管理</h1>
        
        <div class="main-content fade-in">
            <!-- 统计信息 -->
            <div class="stat-card">
                <div class="stat-number"><?= count($lives) ?></div>
                <div class="stat-label">直播源数量</div>
            </div>
            
            <!-- 设置卡片 -->
            <div class="card">
                <h3 style="margin-bottom: 20px; color: var(--secondary-color);">合并设置</h3>
                <?php if (isset($success_msg)): ?>
                    <div class="message"><?= $success_msg ?></div>
                <?php endif; ?>
                <form method="post">
                    <input type="hidden" name="update_setting" value="1">
                    <div class="setting-row">
                        <div class="radio-group">
                            <label class="radio-option">
                                <input type="radio" name="live_merge_method" value="merge" <?= $live_merge_method === 'merge' ? 'checked' : '' ?>>
                                <span>合并模式</span>
                            </label>
                            <label class="radio-option">
                                <input type="radio" name="live_merge_method" value="replace" <?= $live_merge_method === 'replace' ? 'checked' : '' ?>>
                                <span>替换模式</span>
                            </label>
                        </div>
                        <button type="submit" class="btn btn-secondary">保存设置</button>
                    </div>
                </form>
            </div>
            
            <!-- 新增直播源表单 -->
            <div class="card">
                <h3 style="margin-bottom: 20px; color: var(--secondary-color);">添加/更新直播源</h3>
                <form method="post">
                    <input type="hidden" name="add_live" value="1">
                    <div class="form-row">
                        <div class="form-group" style="margin-bottom:0">
                            <label class="form-label">名称</label>
                            <input type="text" name="name" class="form-input" placeholder="输入直播源名称" required>
                        </div>
                        
                        <div class="form-group" style="margin-bottom:0">
                            <label class="form-label">直播链接</label>
                            <input type="text" name="url" class="form-input" placeholder="输入直播源URL" required>
                        </div>
                        
                        <div class="form-group" style="margin-bottom:0">
                            <label class="form-label">User Agent</label>
                            <input type="text" name="ua" class="form-input" placeholder="输入User Agent" required>
                        </div>
                        
                        <button type="submit" class="btn btn-primary">新增/更新</button>
                    </div>
                </form>
            </div>
            
            <!-- 直播源列表 -->
            <div class="table-container">
                <?php if (empty($lives)): ?>
                    <div class="empty-state">
                        <div>📺</div>
                        <h3>暂无直播源</h3>
                        <p>请添加第一个直播源</p>
                    </div>
                <?php else: ?>
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th width="80">ID</th>
                                <th width="150">名称</th>
                                <th>直播链接</th>
                                <th>User Agent</th>
                                <th width="100">操作</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($lives as $r): ?>
                            <tr>
                                <td><?=$r['id']?></td>
                                <td><strong><?=htmlspecialchars($r['name'])?></strong></td>
                                <td class="url-cell">
                                    <a href="<?=htmlspecialchars($r['url'])?>" target="_blank" 
                                       title="<?=htmlspecialchars($r['url'])?>">
                                        <?=htmlspecialchars(mb_strlen($r['url']) > 50 ? mb_substr($r['url'], 0, 50).'...' : $r['url'])?>
                                    </a>
                                </td>
                                <td class="url-cell">
                                    <span title="<?=htmlspecialchars($r['ua'])?>">
                                        <?=htmlspecialchars(mb_strlen($r['ua']) > 50 ? mb_substr($r['ua'], 0, 50).'...' : $r['ua'])?>
                                    </span>
                                </td>
                                <td class="action-cell">
                                    <a href="?delete=<?=$r['id']?>" class="btn btn-danger" 
                                       onclick="return confirm('确定要删除该直播源吗？此操作不可撤销。')">
                                        🗑 删除
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
    </div>
</body>
</html>