<?php
session_start();

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
        "mysql:host={$config['db_host']};dbname={$config['db_name']};charset=utf8mb4",
        $config['db_user'],
        $config['db_pass']
    );
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("数据库连接失败: " . $e->getMessage());
}

// 获取所有分组数据
$stmt = $pdo->query("SELECT * FROM duocang_data ORDER BY ServerName ASC");
$groups = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 获取所有 repos
$stmt = $pdo->query("SELECT name FROM repos ORDER BY name ASC");
$allRepos = $stmt->fetchAll(PDO::FETCH_COLUMN);

// 新建分组
$newFileMessage = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['new_file'])) {
    $newFile = trim($_POST['new_file']);
    if ($newFile !== '') {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM duocang_data WHERE ServerName=:ServerName");
        $stmt->execute([':ServerName'=>$newFile]);
        if ($stmt->fetchColumn() == 0) {
            $stmt = $pdo->prepare("INSERT INTO duocang_data (ServerName, repos) VALUES (:ServerName, '')");
            $stmt->execute([':ServerName'=>$newFile]);
            $newFileMessage = "分组 {$newFile} 创建成功！";
            // 重新获取分组数据
            $stmt = $pdo->query("SELECT * FROM duocang_data ORDER BY ServerName ASC");
            $groups = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $newFileMessage = "分组 {$newFile} 已存在！";
        }
    }
}

// 删除分组
if (isset($_GET['delete'])) {
    $delFile = trim($_GET['delete']);
    $stmt = $pdo->prepare("DELETE FROM duocang_data WHERE ServerName=:ServerName");
    $stmt->execute([':ServerName'=>$delFile]);
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

// 保存编辑
$saveMessage = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_group'])) {
    $serverName = trim($_POST['server_name']);
    $reposSelected = isset($_POST['repos']) ? $_POST['repos'] : [];

    // 优先使用排序后的顺序
    if (!empty($_POST['repos_order'])) {
        $order = explode(';', $_POST['repos_order']);
        // 只保留勾选的
        $reposListArr = array_intersect($order, $reposSelected);
    } else {
        $reposListArr = $reposSelected;
    }

    $reposList = implode(';', $reposListArr);
    if ($reposList !== '') $reposList .= ';';

    $attachLocal = isset($_POST['attach_local']) ? 1 : 0;
    $attachLive  = isset($_POST['attach_live']) ? 1 : 0;

    $stmt = $pdo->prepare("UPDATE duocang_data SET repos=:repos, attach_local=:attach_local, attach_live=:attach_live WHERE ServerName=:ServerName");
    $stmt->execute([
        ':repos' => $reposList,
        ':attach_local' => $attachLocal,
        ':attach_live'  => $attachLive,
        ':ServerName' => $serverName
    ]);
	// 🔄 保存成功后立即刷新页面
    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>多仓分组管理 - 系统管理</title>
    <link rel="stylesheet" href="style.css">
    <style>
        /* 页面特定样式 */
        .card {
            background: var(--surface-color);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow);
            padding: 25px;
            margin-bottom: 25px;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: var(--secondary-color);
        }
        
        .form-input {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid var(--border-color);
            border-radius: var(--border-radius);
            font-size: 14px;
            transition: var(--transition);
            background: var(--surface-color);
        }
        
        .form-input:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(52, 152, 219, 0.1);
        }
        
        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: var(--border-radius);
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        
        .btn-primary {
            background: var(--primary-color);
            color: white;
        }
        
        .btn-primary:hover {
            background: var(--primary-dark);
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(52, 152, 219, 0.3);
        }
        
        .btn-success {
            background: var(--success-color);
            color: white;
        }
        
        .btn-success:hover {
            background: #219653;
            transform: translateY(-1px);
        }
        
        .btn-danger {
            background: var(--danger-color);
            color: white;
        }
        
        .btn-danger:hover {
            background: #c0392b;
            transform: translateY(-1px);
        }
        
        .btn-sm {
            padding: 8px 16px;
            font-size: 12px;
        }
        
        .message {
            padding: 15px 20px;
            margin: 20px 0;
            border-radius: var(--border-radius);
            border-left: 4px solid var(--success-color);
            background: rgba(39, 174, 96, 0.1);
            color: var(--success-color);
            font-weight: 500;
        }
        
        .message.error {
            border-left-color: var(--danger-color);
            background: rgba(231, 76, 60, 0.1);
            color: var(--danger-color);
        }
        
        .group-list {
            display: grid;
            gap: 20px;
        }
        
        .group-item {
            background: var(--surface-color);
            border: 2px solid var(--border-color);
            border-radius: var(--border-radius);
            padding: 20px;
            transition: var(--transition);
        }
        
        .group-item:hover {
            border-color: var(--primary-color);
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }
        
        .group-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
            flex-wrap: wrap;
            gap: 15px;
        }
        
        .group-title {
            font-size: 1.2rem;
            font-weight: 600;
            color: var(--secondary-color);
            margin: 0;
        }
        
        .group-actions {
            display: flex;
            gap: 10px;
        }
        
        .repo-tags {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin: 15px 0;
        }
        
        .repo-tag {
            background: var(--primary-color);
            color: white;
            padding: 4px 12px;
            border-radius: 16px;
            font-size: 12px;
            font-weight: 500;
        }
        
        .repo-tag.empty {
            background: var(--text-secondary);
            opacity: 0.7;
        }
        
        .feature-badges {
            display: flex;
            gap: 15px;
            margin: 15px 0;
        }
        
        .feature-badge {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 13px;
            color: var(--text-secondary);
        }
        
        .feature-badge.active {
            color: var(--success-color);
            font-weight: 600;
        }
        
        .feature-badge i {
            font-size: 16px;
        }
        
        .edit-form {
            background: var(--background-color);
            border-radius: var(--border-radius);
            padding: 20px;
            margin-top: 15px;
            display: none;
        }
        
        .edit-form.active {
            display: block;
            animation: slideDown 0.3s ease;
        }
        
        .repo-checkboxes {
			list-style-type: none;
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 10px;
            margin: 15px 0;
        }
        
        .checkbox-item {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 8px;
            border-radius: var(--border-radius);
            transition: var(--transition);
            cursor: pointer;
        }
        
        .checkbox-item:hover {
            background: var(--hover-color);
        }
        
        .checkbox-item input[type="checkbox"] {
            width: 16px;
            height: 16px;
            cursor: pointer;
        }
        
        .form-actions {
            display: flex;
            gap: 10px;
            margin-top: 20px;
        }
        
        .empty-state {
            text-align: center;
            padding: 60px 40px;
            color: var(--text-secondary);
        }
        
        .empty-state i {
            font-size: 64px;
            margin-bottom: 20px;
            opacity: 0.5;
        }
        
        .stats-card {
            background: linear-gradient(135deg, var(--primary-color), var(--primary-dark));
            color: white;
            border-radius: var(--border-radius);
            padding: 20px;
            margin-bottom: 20px;
            text-align: center;
        }
        
        .stats-number {
            font-size: 2.5rem;
            font-weight: 700;
            margin-bottom: 5px;
        }
        
        .stats-label {
            font-size: 0.9rem;
            opacity: 0.9;
        }
        
        @keyframes slideDown {
            from { opacity: 0; transform: translateY(-10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        @media (max-width: 768px) {
            .card {
                padding: 20px 15px;
            }
            
            .group-header {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .group-actions {
                width: 100%;
                justify-content: flex-end;
            }
            
            .repo-checkboxes {
                grid-template-columns: 1fr;
            }
            
            .feature-badges {
                flex-direction: column;
                gap: 8px;
            }
        }
    </style>
</head>
<body>
    <?php include "header.php"; ?>
    
    <div class="container">
        <h1 class="page-title">多仓分组管理</h1>
        
        <div class="main-content fade-in">
            <?php if($newFileMessage): ?>
                <div class="message"><?= $newFileMessage ?></div>
            <?php endif; ?>
            
            <?php if($saveMessage): ?>
                <div class="message"><?= $saveMessage ?></div>
            <?php endif; ?>
            
            <!-- 统计信息 -->
            <div class="stats-card">
                <div class="stats-number"><?= count($groups) ?></div>
                <div class="stats-label">分组数量</div>
            </div>
            
            <!-- 创建新分组 -->
            <div class="card">
                <h3 style="margin-bottom: 20px; color: var(--secondary-color);">创建新分组</h3>
                <form method="post" class="form-group">
                    <div style="display: grid; grid-template-columns: 1fr auto; gap: 15px; align-items: end;">
                        <div>
                            <label class="form-label">分组名称</label>
                            <input type="text" name="new_file" class="form-input" placeholder="输入新分组名称" required>
                        </div>
                        <button type="submit" class="btn btn-success">
                            ➕ 创建分组
                        </button>
                    </div>
                </form>
            </div>
            
            <!-- 分组列表 -->
            <div class="card">
                <h3 style="margin-bottom: 20px; color: var(--secondary-color);">
                    分组列表 (<?= count($groups) ?>)
                </h3>
                
                <?php if (empty($groups)): ?>
                    <div class="empty-state">
                        <div>📁</div>
                        <h3>暂无分组</h3>
                        <p>请创建第一个分组以开始管理</p>
                    </div>
                <?php else: ?>
                    <div class="group-list">
                        <?php foreach($groups as $group): 
                            $currentRepos = !empty($group['repos']) ? array_filter(explode(';', $group['repos'])) : [];
                            $attachLocal = (int)$group['attach_local'];
                            $attachLive = (int)$group['attach_live'];
                        ?>
                            <div class="group-item" id="group-<?= htmlspecialchars($group['ServerName']) ?>">
                                <div class="group-header">
                                    <h4 class="group-title"><?= htmlspecialchars($group['ServerName']) ?></h4>
                                    <div class="group-actions">
                                        <button type="button" class="btn btn-primary btn-sm" 
                                                onclick="toggleEditForm('<?= htmlspecialchars($group['ServerName']) ?>')">
                                            ✏️ 编辑
                                        </button>
                                        <button type="button" class="btn btn-danger btn-sm" 
                                                onclick="confirmDelete('<?= htmlspecialchars($group['ServerName']) ?>')">
                                            🗑 删除
                                        </button>
                                    </div>
                                </div>
                                
                                <!-- 分组信息展示 -->
                                <div class="repo-tags">
                                    <?php if (!empty($currentRepos)): ?>
                                        <?php foreach(array_slice($currentRepos, 0, 5) as $repo): ?>
                                            <span class="repo-tag"><?= htmlspecialchars($repo) ?></span>
                                        <?php endforeach; ?>
                                        <?php if (count($currentRepos) > 5): ?>
                                            <span class="repo-tag">+<?= count($currentRepos) - 5 ?> 更多</span>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="repo-tag empty">未选择仓库</span>
                                    <?php endif; ?>
                                </div>
                                
                                <div class="feature-badges">
                                    <span class="feature-badge <?= $attachLocal ? 'active' : '' ?>">
                                        <?= $attachLocal ? '已附加本地资源' : '未附加本地资源' ?>
                                    </span>
                                    <span class="feature-badge <?= $attachLive ? 'active' : '' ?>">
                                        <?= $attachLive ? '已附加直播源' : '未附加直播源' ?>
                                    </span>
                                </div>
                                
                                <!-- 编辑表单 -->
<div class="edit-form" id="edit-form-<?= htmlspecialchars($group['ServerName']) ?>">
    <form method="post">
        <input type="hidden" name="server_name" value="<?= htmlspecialchars($group['ServerName']) ?>">
        <input type="hidden" name="repos_order" value=""> <!-- 用于保存排序结果 -->

        <h5 style="margin-bottom: 15px; color: var(--secondary-color);">选择仓库 (可拖拽排序)</h5>
        <ul class="repo-checkboxes sortable-list" id="sortable-<?= htmlspecialchars($group['ServerName']) ?>">
            <?php foreach($allRepos as $repo): ?>
                <li>
                    <label class="checkbox-item">
                        <input type="checkbox" name="repos[]" value="<?= htmlspecialchars($repo) ?>" 
                               <?= in_array($repo, $currentRepos) ? 'checked' : '' ?>>
                        <span><?= htmlspecialchars($repo) ?></span>
                    </label>
                </li>
            <?php endforeach; ?>
        </ul>

        <div class="feature-badges">
            <label class="checkbox-item">
                <input type="checkbox" name="attach_local" value="1" <?= $attachLocal ? 'checked' : '' ?>>
                <span>附加本地资源</span>
            </label>
            <label class="checkbox-item">
                <input type="checkbox" name="attach_live" value="1" <?= $attachLive ? 'checked' : '' ?>>
                <span>附加专用直播源</span>
            </label>
        </div>

        <div class="form-actions">
            <button type="submit" name="save_group" class="btn btn-success">
                💾 保存修改
            </button>
            <button type="button" class="btn btn-danger" 
                    onclick="toggleEditForm('<?= htmlspecialchars($group['ServerName']) ?>')">
                ❌ 取消
            </button>
        </div>
    </form>
</div>

                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script>
        function confirmDelete(file) {
            if (confirm("确定要删除分组 \"" + file + "\" 吗？此操作不可撤销。")) {
                window.location = "?delete=" + encodeURIComponent(file);
            }
        }
        
        function toggleEditForm(groupName) {
            const form = document.getElementById('edit-form-' + groupName);
            const isVisible = form.style.display === 'block';
            
            // 隐藏所有编辑表单
            document.querySelectorAll('.edit-form').forEach(f => {
                f.classList.remove('active');
            });
            
            // 切换当前表单
            if (!isVisible) {
                form.classList.add('active');
            }
        }
        
        document.addEventListener('DOMContentLoaded', function() {
            // 点击分组项外部关闭编辑表单
            document.addEventListener('click', function(e) {
                if (!e.target.closest('.edit-form') && !e.target.closest('.group-actions')) {
                    document.querySelectorAll('.edit-form').forEach(f => {
                        f.classList.remove('active');
                    });
                }
            });
            
            // 增强复选框交互
            const checkboxes = document.querySelectorAll('input[type="checkbox"]');
            checkboxes.forEach(checkbox => {
                checkbox.addEventListener('change', function() {
                    const label = this.closest('.checkbox-item');
                    if (this.checked) {
                        label.style.background = 'rgba(52, 152, 219, 0.1)';
                        label.style.border = '1px solid var(--primary-color)';
                    } else {
                        label.style.background = '';
                        label.style.border = '1px solid transparent';
                    }
                });
                
                // 初始化样式
                const label = checkbox.closest('.checkbox-item');
                if (checkbox.checked && label) {
                    label.style.background = 'rgba(52, 152, 219, 0.1)';
                    label.style.border = '1px solid var(--primary-color)';
                }
            });
        });
    </script>
	<script src="js/Sortable.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.sortable-list').forEach(function(el){
        new Sortable(el, {
            animation: 150,
            onEnd: function (evt) {
                var form = el.closest('form');
                var reposOrderInput = form.querySelector('input[name="repos_order"]');
                var items = el.querySelectorAll('input[type="checkbox"]');
                var order = [];
                items.forEach(function(input){ order.push(input.value); });
                reposOrderInput.value = order.join(';');
            }
        });
    });
});
</script>

</body>
</html>
