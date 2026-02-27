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

// 编辑模式变量
$editMode = false;
$editData = [];

// 检查是否进入编辑模式
if (isset($_GET['edit'])) {
    $id = (int)$_GET['edit'];
    $stmt = $pdo->prepare("SELECT * FROM emby WHERE id = ?");
    $stmt->execute([$id]);
    $editData = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($editData) {
        $editMode = true;
    }
}

// 新增/更新 Emby 配置
if ($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['add_emby'])) {
    $name = trim($_POST['name']);
    $server = trim($_POST['server']);
    $username = trim($_POST['username']);
    $password = trim($_POST['password']);
    $editId = isset($_POST['edit_id']) ? (int)$_POST['edit_id'] : 0;
    
    if ($name && $server && $username && $password) {
        if ($editMode && $editId > 0) {
            // 更新现有配置
            $stmt = $pdo->prepare("UPDATE emby SET SERVER_NAME = :name, EMBY_SERVER = :server, EMBY_USERNAME = :username, EMBY_PASSWORD = :password WHERE id = :id");
            $stmt->execute([
                ':name' => $name,
                ':server' => $server,
                ':username' => $username,
                ':password' => $password,
                ':id' => $editId
            ]);
        } else {
            // 新增配置
            $stmt = $pdo->prepare("INSERT INTO emby (SERVER_NAME, EMBY_SERVER, EMBY_USERNAME, EMBY_PASSWORD) VALUES (:name, :server, :username, :password)");
            $stmt->execute([
                ':name' => $name,
                ':server' => $server,
                ':username' => $username,
                ':password' => $password
            ]);
        }
        header("Location: edit_emby.php");
        exit;
    }
}

// 取消编辑
if (isset($_GET['cancel'])) {
    header("Location: edit_emby.php");
    exit;
}

// 删除 Emby 配置
if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    $pdo->prepare("DELETE FROM emby WHERE id=?")->execute([$id]);
    header("Location: edit_emby.php"); 
    exit;
}

// 获取所有 Emby 配置
$stmt = $pdo->query("SELECT * FROM emby ORDER BY id ASC");
$embyConfigs = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Emby配置管理 - 系统管理</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .edit-mode-banner {
            background: #fff3cd;
            border: 1px solid #ffeaa7;
            color: #856404;
            padding: 15px;
            border-radius: var(--border-radius);
            margin-bottom: 20px;
            font-weight: 600;
        }
    </style>
</head>
<body>
    <?php include "header.php"; ?>
    
    <div class="container">
        <h1 class="page-title">Emby配置管理</h1>
        
        <!-- 编辑模式提示 -->
        <?php if ($editMode): ?>
            <div class="edit-mode-banner">
                📝 正在编辑: <?= htmlspecialchars($editData['SERVER_NAME']) ?> (ID: <?= $editData['id'] ?>)
            </div>
        <?php endif; ?>
        
        <!-- 统计信息 -->
        <div class="stat-card purple">
            <div class="stat-number"><?= count($embyConfigs) ?></div>
            <div class="stat-label">Emby配置数量</div>
        </div>
        
        <div class="main-content fade-in">
            <!-- 信息提示 -->
            <div class="info-text">
                💡 <strong>提示：</strong> Emby配置用于系统连接Emby媒体服务器。每个配置包含服务器地址、用户名和密码。
            </div>
            
            <!-- 新增/编辑Emby配置表单 -->
            <div class="card">
                <h3 style="margin-bottom: 20px; color: var(--secondary-color);">
                    <?= $editMode ? '编辑Emby配置' : '添加Emby配置' ?>
                </h3>
                <form method="post">
                    <?php if ($editMode): ?>
                        <input type="hidden" name="edit_id" value="<?= $editData['id'] ?>">
                    <?php endif; ?>
                    <div class="emby-form-row">
                        <div class="form-group" style="margin-bottom:0">
                            <label class="form-label">服务器名称</label>
                            <input type="text" name="name" class="form-input" 
                                   placeholder="我的emby" 
                                   value="<?= $editMode ? htmlspecialchars($editData['SERVER_NAME']) : '' ?>" 
                                   required>
                        </div>
                        <div class="form-group" style="margin-bottom:0">
                            <label class="form-label">服务器地址</label>
                            <input type="text" name="server" class="form-input" 
                                   placeholder="https://your-emby-server.com:8096" 
                                   value="<?= $editMode ? htmlspecialchars($editData['EMBY_SERVER']) : '' ?>" 
                                   required>
                        </div>
                        
                        <div class="form-group" style="margin-bottom:0">
                            <label class="form-label">用户名</label>
                            <input type="text" name="username" class="form-input" 
                                   placeholder="输入Emby用户名" 
                                   value="<?= $editMode ? htmlspecialchars($editData['EMBY_USERNAME']) : '' ?>" 
                                   required>
                        </div>
                        
                        <div class="form-group" style="margin-bottom:0">
                            <label class="form-label">密码</label>
                            <input type="password" name="password" class="form-input" 
                                   placeholder="输入Emby密码" 
                                   value="<?= $editMode ? htmlspecialchars($editData['EMBY_PASSWORD']) : '' ?>" 
                                   required>
                        </div>
                        
                        <div class="form-group" style="margin-bottom:0">
                            <button type="submit" name="add_emby" class="btn btn-primary">
                                <?= $editMode ? '更新配置' : '新增配置' ?>
                            </button>
                            <?php if ($editMode): ?>
                                <a href="?cancel" class="btn btn-secondary" style="margin-left: 10px;">取消编辑</a>
                            <?php endif; ?>
                        </div>
                    </div>
                </form>
            </div>
            
            <!-- Emby配置列表 -->
            <div class="table-container">
                <?php if (empty($embyConfigs)): ?>
                    <div class="empty-state">
                        <div>🎬</div>
                        <h3>暂无Emby配置</h3>
                        <p>请添加第一个Emby配置</p>
                    </div>
                <?php else: ?>
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th width="60">ID</th>
                                <th width="150">服务器名称</th>
                                <th width="200">服务器地址</th>
                                <th width="120">用户名</th>
                                <th width="150">密码</th>
                                <th width="120">创建时间</th>
                                <th width="150">操作</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($embyConfigs as $config): ?>
                            <tr>
                                <td><?=$config['id']?></td>
                                <td><strong><?=htmlspecialchars($config['SERVER_NAME'])?></strong></td>
                                <td class="url-cell">
                                    <a href="<?=htmlspecialchars($config['EMBY_SERVER'])?>" target="_blank" 
                                       title="<?=htmlspecialchars($config['EMBY_SERVER'])?>">
                                        <?=htmlspecialchars(mb_strlen($config['EMBY_SERVER']) > 30 ? mb_substr($config['EMBY_SERVER'], 0, 30).'...' : $config['EMBY_SERVER'])?>
                                    </a>
                                </td>
                                <td><?=htmlspecialchars($config['EMBY_USERNAME'])?></td>
                                <td class="password-cell">
                                    <span class="password-mask">••••••••</span>
                                    <button type="button" class="password-toggle" onclick="togglePassword(this)">显示</button>
                                    <span class="actual-password" style="display: none;"><?=htmlspecialchars($config['EMBY_PASSWORD'])?></span>
                                </td>
                                <td><?=$config['created_at']?></td>
                                <td class="action-cell">
                                    <a href="?edit=<?=$config['id']?>" class="btn btn-warning">✏️ 编辑</a>
                                    <a href="?delete=<?=$config['id']?>" class="btn btn-danger" 
                                       onclick="return confirm('确定要删除该Emby配置吗？此操作不可撤销。')">
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
        // 增强确认对话框
        document.addEventListener('DOMContentLoaded', function() {
            const deleteButtons = document.querySelectorAll('.btn-danger');
            deleteButtons.forEach(button => {
                button.addEventListener('click', function(e) {
                    if (!confirm('确定要删除该Emby配置吗？此操作不可撤销。')) {
                        e.preventDefault();
                    }
                });
            });
            
            // 表单提交后清空输入框（仅在新增模式下）
            const form = document.querySelector('form');
            const editMode = <?= $editMode ? 'true' : 'false' ?>;
            
            if (!editMode) {
                form.addEventListener('submit', function() {
                    setTimeout(() => {
                        if (this.querySelector('input[name="name"]')) {
                            this.querySelector('input[name="name"]').value = '';
                            this.querySelector('input[name="server"]').value = '';
                            this.querySelector('input[name="username"]').value = '';
                            this.querySelector('input[name="password"]').value = '';
                        }
                    }, 100);
                });
            }
        });
        
        // 密码显示/隐藏切换
        function togglePassword(button) {
            const cell = button.parentElement;
            const mask = cell.querySelector('.password-mask');
            const actual = cell.querySelector('.actual-password');
            
            if (mask.style.display !== 'none') {
                mask.style.display = 'none';
                actual.style.display = 'inline';
                button.textContent = '隐藏';
            } else {
                mask.style.display = 'inline';
                actual.style.display = 'none';
                button.textContent = '显示';
            }
        }
    </script>
</body>
</html>