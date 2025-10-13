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

// 从数据库获取所有授权文件名
$stmt = $pdo->query("SELECT ServerName FROM duocang_data ORDER BY created_at DESC");
$jsonFiles = $stmt->fetchAll(PDO::FETCH_COLUMN);

// 删除设备
if (isset($_GET['delete'])) {
    $deleteId = trim($_GET['delete']);
    $stmt = $pdo->prepare("DELETE FROM devices WHERE device_id = :device_id");
    $stmt->execute(array(':device_id'=>$deleteId));
    header("Location: " . strtok($_SERVER['REQUEST_URI'], '?'));
    exit;
}

// 保存单行修改
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['old_device_id'])) {
    $oldId = trim($_POST['old_device_id']);
    $device_id = trim($_POST['device_id']);
    $server = trim($_POST['server']);
    $remark = trim($_POST['remark']);
    $expire_at = trim($_POST['expire_at']);

    $stmt = $pdo->prepare("UPDATE devices SET device_id=:device_id, server=:server, remark=:remark, expire_at=:expire_at WHERE device_id=:old_device_id");
    $stmt->execute(array(
        ':device_id'=>$device_id,
        ':server'=>$server,
        ':remark'=>$remark,
        ':expire_at'=>$expire_at,
        ':old_device_id'=>$oldId
    ));

    header("Location: " . $_SERVER['PHP_SELF']);
    exit;
}

// ===== 搜索和分页 =====
$search = trim($_GET['search'] ?? '');
$page = max(1, intval($_GET['page'] ?? 1));
$perPage = 10;

$where = '';
$params = array();
if ($search !== '') {
    $where = "WHERE device_id LIKE :search OR remark LIKE :search";
    $params[':search'] = "%".$search."%";
}

// 统计总数
$stmt = $pdo->prepare("SELECT COUNT(*) FROM devices $where");
$stmt->execute($params);
$total = intval($stmt->fetchColumn());

// 获取分页数据
$start = ($page - 1) * $perPage;
$stmt = $pdo->prepare("SELECT * FROM devices $where ORDER BY created_at DESC LIMIT :start, :perPage");
foreach ($params as $k=>$v) {
    $stmt->bindValue($k, $v, PDO::PARAM_STR);
}
$stmt->bindValue(':start', $start, PDO::PARAM_INT);
$stmt->bindValue(':perPage', $perPage, PDO::PARAM_INT);
$stmt->execute();
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 统计过期数
$expiredCount = 0;
$today = date('Y-m-d');
foreach ($logs as $log) {
    if (!empty($log['expire_at']) && $log['expire_at'] < $today) {
        $expiredCount++;
    }
}

$totalPages = ceil($total / $perPage);

// 检查调试模式
$debugMode = isset($config['debug']) ? (int)$config['debug'] : 0;
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>用户管理 - 系统管理</title>
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
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 25px;
        }
        
        .stat-card {
            background: linear-gradient(135deg, var(--primary-color), var(--primary-dark));
            color: white;
            border-radius: var(--border-radius);
            padding: 20px;
            text-align: center;
            transition: var(--transition);
        }
        
        .stat-card.warning {
            background: linear-gradient(135deg, var(--warning-color), #e67e22);
        }
        
        .stat-card.success {
            background: linear-gradient(135deg, var(--success-color), #219653);
        }
        
        .stat-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
        }
        
        .stat-number {
            font-size: 2.5rem;
            font-weight: 700;
            margin-bottom: 5px;
        }
        
        .stat-label {
            font-size: 0.9rem;
            opacity: 0.9;
        }
        
        .search-form {
            display: flex;
            gap: 15px;
            margin-bottom: 20px;
            align-items: end;
        }
        
        .form-group {
            flex: 1;
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
        
        .form-select {
            width: 100%;
            padding: 12px 15px;
            border: 2px solid var(--border-color);
            border-radius: var(--border-radius);
            font-size: 14px;
            background: var(--surface-color);
            cursor: pointer;
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
        
        .data-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
            margin: 20px 0;
        }
        
        .data-table th {
            background: var(--secondary-color);
            color: white;
            padding: 15px 12px;
            text-align: left;
            font-weight: 600;
            position: sticky;
            top: 0;
        }
        
        .data-table td {
            padding: 12px;
            border-bottom: 1px solid var(--border-color);
            vertical-align: middle;
        }
        
        .data-table tr:hover {
            background: var(--hover-color);
        }
        
        .data-table tr.expired {
            background: rgba(231, 76, 60, 0.1);
        }
        
        .data-table tr.expired:hover {
            background: rgba(231, 76, 60, 0.15);
        }
        
        .expired-badge {
            background: var(--danger-color);
            color: white;
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
        }
        
        .active-badge {
            background: var(--success-color);
            color: white;
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
        }
        
        .pagination {
            display: flex;
            justify-content: center;
            gap: 5px;
            margin: 30px 0;
        }
        
        .pagination a {
            padding: 8px 12px;
            border: 2px solid var(--border-color);
            border-radius: var(--border-radius);
            text-decoration: none;
            color: var(--text-primary);
            transition: var(--transition);
        }
        
        .pagination a:hover {
            border-color: var(--primary-color);
            color: var(--primary-color);
        }
        
        .pagination a.current {
            background: var(--primary-color);
            color: white;
            border-color: var(--primary-color);
        }
        
        .test-section {
            background: var(--surface-color);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow);
            padding: 20px;
            margin-top: 30px;
        }
        
        .test-form {
            display: flex;
            gap: 15px;
            align-items: end;
        }
        
        .action-buttons {
            display: flex;
            gap: 10px;
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
        
        @media (max-width: 768px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .search-form {
                flex-direction: column;
            }
            
            .data-table {
                font-size: 12px;
            }
            
            .data-table th,
            .data-table td {
                padding: 10px 8px;
            }
            
            .test-form {
                flex-direction: column;
            }
            
            .action-buttons {
                justify-content: center;
            }
        }
    </style>
</head>
<body>
    <?php include "header.php"; ?>
    
    <div class="container">
        <h1 class="page-title">用户管理</h1>
        
        <div class="main-content fade-in">
            <!-- 统计信息 -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-number"><?= $total ?></div>
                    <div class="stat-label">总用户数</div>
                </div>
                <div class="stat-card warning">
                    <div class="stat-number"><?= $expiredCount ?></div>
                    <div class="stat-label">已过期用户</div>
                </div>
                <div class="stat-card success">
                    <div class="stat-number"><?= max(0, $total - $expiredCount) ?></div>
                    <div class="stat-label">有效用户</div>
                </div>
            </div>
            
            <!-- 搜索栏 -->
            <div class="card">
                <form class="search-form" method="get">
                    <div class="form-group">
                        <label class="form-label">搜索用户</label>
                        <input type="text" name="search" class="form-input" 
                               placeholder="搜索 Device ID 或备注" value="<?=htmlspecialchars($search)?>">
                    </div>
                    <button type="submit" class="btn btn-primary">
                        🔍 搜索
                    </button>
                    <?php if ($search): ?>
                        <a href="?" class="btn btn-danger">❌ 清除搜索</a>
                    <?php endif; ?>
                </form>
            </div>
            
            <!-- 用户列表 -->
            <div class="card">
                <?php if (empty($logs)): ?>
                    <div class="empty-state">
                        <div>👥</div>
                        <h3><?= $search ? '未找到匹配的用户' : '暂无用户数据' ?></h3>
                        <p><?= $search ? '请尝试其他搜索关键词' : '系统尚未有用户注册' ?></p>
                    </div>
                <?php else: ?>
                    <div style="overflow-x: auto;">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th>设备ID</th>
                                    <th>授权文件</th>
                                    <th>备注</th>
                                    <th>到期时间</th>
                                    <th>状态</th>
                                    <th>创建时间</th>
                                    <th>最后登录</th>
                                    <th>操作</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($logs as $log):
                                    $expireAt = $log['expire_at'] ?? '';
                                    $isExpired = ($expireAt !== '' && $expireAt < date('Y-m-d'));
                                    $daysText = "未设置";
                                    if ($expireAt !== '') {
                                        $diff = (strtotime($expireAt) - strtotime(date('Y-m-d'))) / 86400;
                                        $daysText = $diff >= 0 ? "剩余 ".intval($diff)." 天" : "过期 ".abs(intval($diff))." 天";
                                    }
                                ?>
                                <tr class="<?= $isExpired ? 'expired' : '' ?>">
                                    <form method="post">
                                        <input type="hidden" name="old_device_id" value="<?=htmlspecialchars($log['device_id'])?>">
                                        <td>
                                            <input type="text" name="device_id" class="form-input" 
                                                   value="<?=htmlspecialchars($log['device_id'])?>" required>
                                        </td>
                                        <td>
                                            <select name="server" class="form-select">
                                                <option value="">-- 未选择 --</option>
                                                <?php foreach ($jsonFiles as $jsonFile): ?>
                                                    <option value="<?=$jsonFile?>" <?=($log['server'] ?? '') === $jsonFile ? 'selected':''?>>
                                                        <?=$jsonFile?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </td>
                                        <td>
                                            <input type="text" name="remark" class="form-input" 
                                                   value="<?=htmlspecialchars($log['remark'] ?? '')?>" 
                                                   placeholder="添加备注">
                                        </td>
                                        <td>
                                            <input type="date" name="expire_at" class="form-input" 
                                                   value="<?=htmlspecialchars($expireAt)?>">
                                        </td>
                                        <td>
                                            <?php if ($expireAt === ''): ?>
                                                <span class="active-badge">永久</span>
                                            <?php else: ?>
                                                <span class="<?= $isExpired ? 'expired-badge' : 'active-badge' ?>">
                                                    <?= $daysText ?>
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?=htmlspecialchars($log['created_at'] ?? '')?></td>
                                        <td><?=htmlspecialchars($log['last_login'] ?? '')?></td>
                                        <td>
                                            <div class="action-buttons">
                                                <button type="submit" class="btn btn-success btn-sm">💾 保存</button>
                                                <a class="btn btn-danger btn-sm" 
                                                   href="?delete=<?=urlencode($log['device_id'])?>" 
                                                   onclick="return confirm('确定删除此设备吗？此操作不可撤销。')">
                                                    ✖ 删除
                                                </a>
                                            </div>
                                        </td>
                                    </form>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <!-- 分页 -->
                    <?php if ($totalPages > 1): ?>
                    <div class="pagination">
                        <?php for ($p=1; $p<=$totalPages; $p++):
                            $class = $p === $page ? 'current' : '';
                            $url = '?page='.$p.($search ? '&search='.urlencode($search) : '');
                        ?>
                            <a href="<?=$url?>" class="<?=$class?>"><?=$p?></a>
                        <?php endfor; ?>
                    </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
            
            <!-- 测试区域 - 仅在调试模式下显示 -->
            <?php if ($debugMode === 1): ?>
            <div class="test-section">
                <h3 style="margin-bottom: 15px; color: var(--secondary-color);">🔧 功能测试</h3>
                <div class="test-form">
                    <div class="form-group" style="flex: 1;">
                        <label class="form-label">测试 Device ID</label>
                        <input type="text" id="testDeviceId" class="form-input" placeholder="请输入要测试的 Device ID">
                    </div>
                    <div class="action-buttons">
                        <button type="button" class="btn btn-primary" onclick="openApi()">测试授权API</button>
                        <button type="button" class="btn btn-success" onclick="openNotice()">测试通知</button>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        function openApi() {
            var id = document.getElementById('testDeviceId').value.trim();
            if (!id) {
                alert("请输入 Device ID");
                return;
            }
            window.open("api.php?package_name=com.fongmi.android.tv&device_id=" + encodeURIComponent(id), "_blank");
        }
        
        function openNotice() {
            var id = document.getElementById('testDeviceId').value.trim();
            if (!id) {
                alert("请输入 Device ID");
                return;
            }
            window.open("notice.php?device_id=" + encodeURIComponent(id), "_blank");
        }

        // 增强交互效果
        document.addEventListener('DOMContentLoaded', function() {
            // 表单提交效果
            const forms = document.querySelectorAll('form');
            forms.forEach(form => {
                form.addEventListener('submit', function() {
                    const submitBtn = this.querySelector('button[type="submit"]');
                    if (submitBtn) {
                        submitBtn.innerHTML = '⏳ 保存中...';
                        submitBtn.disabled = true;
                    }
                });
            });
            
            // 日期输入框动态效果
            const dateInputs = document.querySelectorAll('input[type="date"]');
            dateInputs.forEach(input => {
                input.addEventListener('change', function() {
                    const row = this.closest('tr');
                    const expireDate = new Date(this.value);
                    const today = new Date();
                    today.setHours(0, 0, 0, 0);
                    
                    if (this.value && expireDate < today) {
                        row.classList.add('expired');
                    } else {
                        row.classList.remove('expired');
                    }
                });
            });
            
            // 搜索框回车提交
            const searchInput = document.querySelector('input[name="search"]');
            if (searchInput) {
                searchInput.addEventListener('keypress', function(e) {
                    if (e.key === 'Enter') {
                        this.form.submit();
                    }
                });
            }
        });
    </script>
</body>
</html>