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

// 编辑 repo
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['edit_repo'])) {
    $id = (int)$_POST['id'];
    $name = trim($_POST['name']);
    $url  = trim($_POST['url']);
    if ($id && $name && $url) {
        $stmt = $pdo->prepare("UPDATE repos SET name=:name, url=:url WHERE id=:id");
        $stmt->execute([':name'=>$name, ':url'=>$url, ':id'=>$id]);
        header("Location: edit_repos.php");
        exit;
    }
}

// 新增 repo
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['add_repo'])) {
    $name = trim($_POST['name']);
    $url  = trim($_POST['url']);
    if ($name && $url) {
        $stmt = $pdo->prepare("INSERT INTO repos (name, url) VALUES (:name,:url) ON DUPLICATE KEY UPDATE url=VALUES(url)");
        $stmt->execute([':name'=>$name, ':url'=>$url]);
    }
}

// 删除 repo
if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    // 先获取要删除的name
    $stmt_del = $pdo->prepare("SELECT name FROM repos WHERE id=?");
    $stmt_del->execute([$id]);
    $repoRow = $stmt_del->fetch(PDO::FETCH_ASSOC);
    if ($repoRow) {
        $delName = $repoRow['name'];
        $pdo->prepare("DELETE FROM repos WHERE id=?")->execute([$id]);

        // 只删除 name;，无需 trim
        $delStr = $delName . ';';
        $likeStr = '%' . $delStr . '%';
        $sql = "UPDATE duocang_data 
                SET repos = REPLACE(repos, :delstr, '')
                WHERE repos LIKE :likeStr";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':delstr' => $delStr,
            ':likeStr' => $likeStr
        ]);
    }
    header("Location: edit_repos.php"); 
    exit;
}

// 获取所有 repo
$stmt = $pdo->query("SELECT * FROM repos ORDER BY id ASC");
$repos = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 获取要编辑的repo信息
$edit_repo = null;
if (isset($_GET['edit'])) {
    $edit_id = (int)$_GET['edit'];
    $stmt = $pdo->prepare("SELECT * FROM repos WHERE id = ?");
    $stmt->execute([$edit_id]);
    $edit_repo = $stmt->fetch(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>仓库源管理 - 系统管理</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <?php include "header.php"; ?>
    
    <div class="container">
        <h1 class="page-title">仓库源管理</h1>
        
        <div class="main-content fade-in">
            <!-- 统计信息 -->
            <div class="stat-card">
                <div class="stat-number"><?= count($repos) ?></div>
                <div class="stat-label">已配置的仓库源</div>
            </div>
            
            <!-- 编辑仓库源表单 -->
            <?php if ($edit_repo): ?>
            <div class="card">
                <div class="form-header">
                    <h3 class="form-title">编辑仓库源</h3>
                    <a href="edit_repos.php" class="btn btn-cancel">取消编辑</a>
                </div>
                <form method="post">
                    <input type="hidden" name="id" value="<?= $edit_repo['id'] ?>">
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">仓库名称</label>
                            <input type="text" name="name" class="form-input" 
                                   value="<?= htmlspecialchars($edit_repo['name']) ?>" 
                                   placeholder="输入仓库名称" required>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">仓库URL</label>
                            <input type="text" name="url" class="form-input" 
                                   value="<?= htmlspecialchars($edit_repo['url']) ?>" 
                                   placeholder="输入仓库链接地址" required>
                        </div>
                        
                        <div class="form-group">
                            <button type="submit" name="edit_repo" class="btn btn-primary">更新仓库源</button>
                        </div>
                    </div>
                </form>
            </div>
            <?php endif; ?>
            
            <!-- 新增仓库源表单 -->
            <div class="card">
                <h3 style="margin-bottom: 20px; color: var(--secondary-color);">添加/更新仓库源</h3>
                <form method="post">
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">仓库名称</label>
                            <input type="text" name="name" class="form-input" placeholder="输入仓库名称" required>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">仓库URL</label>
                            <input type="text" name="url" class="form-input" placeholder="输入仓库链接地址" required>
                        </div>
                        
                        <div class="form-group">
                            <button type="submit" name="add_repo" class="btn btn-primary">新增/更新</button>
                        </div>
                    </div>
                </form>
            </div>
            
            <!-- 仓库源列表 -->
            <div class="table-container">
                <?php if (empty($repos)): ?>
                    <div class="empty-state">
                        <div>📦</div>
                        <h3>暂无仓库源</h3>
                        <p>请添加第一个仓库源以开始使用</p>
                    </div>
                <?php else: ?>
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th class="id-cell">ID</th>
                                <th class="name-cell">名称</th>
                                <th>URL地址</th>
                                <th class="action-cell">操作</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($repos as $r): ?>
                            <tr>
                                <td class="id-cell"><?=$r['id']?></td>
                                <td class="name-cell"><strong><?=htmlspecialchars($r['name'])?></strong></td>
                                <td class="url-cell">
                                    <a href="<?=htmlspecialchars($r['url'])?>" target="_blank" class="url-link"
                                       title="<?=htmlspecialchars($r['url'])?>">
                                        <?=htmlspecialchars(mb_strlen($r['url']) > 60 ? mb_substr($r['url'], 0, 60).'...' : $r['url'])?>
                                    </a>
                                </td>
                                <td class="action-cell">
                                    <a href="?edit=<?=$r['id']?>" class="btn btn-warning" title="编辑仓库源">
                                        ✏️ 编辑
                                    </a>
                                    <a href="?delete=<?=$r['id']?>" class="btn btn-danger" 
                                       onclick="return confirm('确定要删除该仓库源吗？此操作不可撤销。')"
                                       title="删除仓库源">
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

    <script>
        // 增强确认对话框和交互
        document.addEventListener('DOMContentLoaded', function() {
            // 删除确认
            const deleteButtons = document.querySelectorAll('.btn-danger');
            deleteButtons.forEach(button => {
                button.addEventListener('click', function(e) {
                    if (!confirm('确定要删除该仓库源吗？此操作不可撤销。')) {
                        e.preventDefault();
                    }
                });
            });
            
            // 表单提交后清空输入框（仅新增表单）
            const addForm = document.querySelector('form[action*="edit_repos"]');
            if (addForm && !window.location.search.includes('edit=')) {
                addForm.addEventListener('submit', function() {
                    setTimeout(() => {
                        const nameInput = this.querySelector('input[name="name"]');
                        const urlInput = this.querySelector('input[name="url"]');
                        if (nameInput && urlInput) {
                            nameInput.value = '';
                            urlInput.value = '';
                        }
                    }, 100);
                });
            }
            
            // 添加输入框焦点效果
            const inputs = document.querySelectorAll('.form-input');
            inputs.forEach(input => {
                input.addEventListener('focus', function() {
                    this.parentElement.style.transform = 'translateY(-2px)';
                });
                
                input.addEventListener('blur', function() {
                    this.parentElement.style.transform = 'translateY(0)';
                });
            });
            
            // 表格行点击效果
            const tableRows = document.querySelectorAll('.data-table tr');
            tableRows.forEach(row => {
                row.addEventListener('click', function(e) {
                    if (!e.target.closest('a')) {
                        this.style.background = 'var(--hover-color)';
                        setTimeout(() => {
                            this.style.background = '';
                        }, 200);
                    }
                });
            });
            
            // 编辑模式下自动滚动到编辑表单
            if (window.location.search.includes('edit=')) {
                const editForm = document.querySelector('.edit-form');
                if (editForm) {
                    editForm.scrollIntoView({ behavior: 'smooth', block: 'start' });
                }
            }
        });
    </script>
</body>
</html>
