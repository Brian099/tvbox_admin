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
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>仓库源管理 - 系统管理</title>
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
            grid-template-columns: 1fr 2fr auto;
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
            max-width: 400px;
            word-break: break-all;
        }
        
        .url-link {
            color: var(--primary-color);
            text-decoration: none;
            transition: var(--transition);
        }
        
        .url-link:hover {
            color: var(--primary-dark);
            text-decoration: underline;
        }
        
        .action-cell {
            white-space: nowrap;
            text-align: center;
        }
        
        .id-cell {
            width: 80px;
            text-align: center;
            font-weight: 600;
            color: var(--text-secondary);
        }
        
        .name-cell {
            width: 200px;
            font-weight: 600;
            color: var(--secondary-color);
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
        
        .empty-state h3 {
            margin-bottom: 10px;
            color: var(--text-secondary);
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
                padding: 10px 8px;
            }
            
            .id-cell, .name-cell {
                width: auto;
            }
            
            .url-cell {
                max-width: 200px;
            }
            
            .stats-card {
                padding: 15px;
            }
            
            .stats-number {
                font-size: 2rem;
            }
        }
    </style>
</head>
<body>
    <?php include "header.php"; ?>
    
    <div class="container">
        <h1 class="page-title">仓库源管理</h1>
        
        <div class="main-content fade-in">
            <!-- 统计信息 -->
            <div class="stats-card">
                <div class="stats-number"><?= count($repos) ?></div>
                <div class="stats-label">已配置的仓库源</div>
            </div>
            
            <!-- 新增仓库源表单 -->
            <div class="form-card">
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
                                    <a href="?delete=<?=$r['id']?>" class="btn btn-danger" 
                                       onclick="return confirm('确定要删除该仓库源吗？此操作不可撤销。')">
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
            
            // 表单提交后清空输入框
            const form = document.querySelector('form');
            form.addEventListener('submit', function() {
                setTimeout(() => {
                    const nameInput = this.querySelector('input[name="name"]');
                    const urlInput = this.querySelector('input[name="url"]');
                    if (nameInput && urlInput) {
                        nameInput.value = '';
                        urlInput.value = '';
                    }
                }, 100);
            });
            
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
        });
    </script>
</body>
</html>