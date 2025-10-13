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
        /* 页面特定样式 */
        .form-card {
            background: var(--surface-color);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow);
            padding: 25px;
            margin-bottom: 30px;
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
        
        .form-row {
            display: grid;
            grid-template-columns: 1fr 2fr 2fr auto;
            gap: 15px;
            align-items: end;
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
            display: inline-block;
            text-align: center;
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
        
        .btn-danger {
            background: var(--danger-color);
            color: white;
            padding: 8px 16px;
            font-size: 12px;
        }
        
        .btn-danger:hover {
            background: #c0392b;
            transform: translateY(-1px);
        }
        
        .table-container {
            background: var(--surface-color);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow);
            overflow: hidden;
        }
        
        .data-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
        }
        
        .data-table th {
            background: var(--secondary-color);
            color: white;
            padding: 15px 12px;
            text-align: left;
            font-weight: 600;
        }
        
        .data-table td {
            padding: 12px;
            border-bottom: 1px solid var(--border-color);
        }
        
        .data-table tr:hover {
            background: var(--hover-color);
        }
        
        .data-table tr:last-child td {
            border-bottom: none;
        }
        
        .url-cell {
            max-width: 300px;
            word-break: break-all;
        }
        
        .action-cell {
            white-space: nowrap;
            text-align: center;
        }
        
        .empty-state {
            text-align: center;
            padding: 40px;
            color: var(--text-secondary);
        }
        
        .empty-state i {
            font-size: 48px;
            margin-bottom: 15px;
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
        
        @media (max-width: 768px) {
            .form-row {
                grid-template-columns: 1fr;
                gap: 10px;
            }
            
            .data-table {
                font-size: 12px;
            }
            
            .data-table th,
            .data-table td {
                padding: 8px 6px;
            }
            
            .url-cell {
                max-width: 150px;
            }
        }
    </style>
</head>
<body>
    <?php include "header.php"; ?>
    
    <div class="container">
        <h1 class="page-title">直播源管理</h1>
        <!-- 统计信息 -->
            <div class="stats-card">
                <div class="stats-number"><?= count($lives) ?></div>
                <div class="stats-label">直播源数量</div>
            </div>
        <div class="main-content fade-in">
            <!-- 新增直播源表单 -->
            <div class="form-card">
                <h3 style="margin-bottom: 20px; color: var(--secondary-color);">添加/更新直播源</h3>
                <form method="post">
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">名称</label>
                            <input type="text" name="name" class="form-input" placeholder="输入直播源名称" required>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">直播链接</label>
                            <input type="text" name="url" class="form-input" placeholder="输入直播源URL" required>
                        </div>
                        
                        <div class="form-group">
                            <label class="form-label">User Agent</label>
                            <input type="text" name="ua" class="form-input" placeholder="输入User Agent" required>
                        </div>
                        
                        <div class="form-group">
                            <button type="submit" name="add_live" class="btn btn-primary">新增/更新</button>
                        </div>
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
                                    <a href="<?=htmlspecialchars($r['ua'])?>" target="_blank" 
                                       title="<?=htmlspecialchars($r['ua'])?>">
                                        <?=htmlspecialchars(mb_strlen($r['ua']) > 50 ? mb_substr($r['ua'], 0, 50).'...' : $r['ua'])?>
                                    </a>
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

    <script>
        // 增强确认对话框
        document.addEventListener('DOMContentLoaded', function() {
            const deleteButtons = document.querySelectorAll('.btn-danger');
            deleteButtons.forEach(button => {
                button.addEventListener('click', function(e) {
                    if (!confirm('确定要删除该直播源吗？此操作不可撤销。')) {
                        e.preventDefault();
                    }
                });
            });
            
            // 表单提交后清空输入框
            const form = document.querySelector('form');
            form.addEventListener('submit', function() {
                setTimeout(() => {
                    if (this.querySelector('input[name="name"]')) {
                        this.querySelector('input[name="name"]').value = '';
                        this.querySelector('input[name="url"]').value = '';
                        this.querySelector('input[name="ua"]').value = '';
                    }
                }, 100);
            });
        });
    </script>
</body>
</html>