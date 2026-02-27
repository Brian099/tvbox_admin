<?php
session_start();
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: login.php');
    exit;
}
$configFile = __DIR__ . '/config/config.php';
if (!file_exists($configFile)) {
    die('未初始化');
}
$config = require $configFile;
try {
    $pdo = new PDO(
        "mysql:host=".$config['db_host'].";dbname=".$config['db_name'].";charset=utf8mb4",
        $config['db_user'],
        $config['db_pass']
    );
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die('数据库连接失败');
}
$msg = '';
$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['update_config'])) {
        $domain = trim($_POST['domain'] ?? '');
        $authMode = isset($_POST['auth_mode']) && $_POST['auth_mode'] === '1' ? '1' : '0';
        if ($domain === '') {
            $err = '域名不能为空';
        } else {
            $new = $config;
            $new['domain'] = $domain;
            $content = "<?php\nreturn array(\n"
                . "    'db_host' => '".addslashes($new['db_host'])."',\n"
                . "    'db_user' => '".addslashes($new['db_user'])."',\n"
                . "    'db_pass' => '".addslashes($new['db_pass'])."',\n"
                . "    'db_name' => '".addslashes($new['db_name'])."',\n"
                . "    'domain' => '".addslashes($new['domain'])."'\n"
                . ");\n";
            if (!is_dir(__DIR__.'/config')) {
                mkdir(__DIR__.'/config', 0755, true);
            }
            file_put_contents($configFile, $content);
            try {
                $col = $pdo->query("SHOW COLUMNS FROM setting LIKE 'AuthMode'")->fetch(PDO::FETCH_ASSOC);
                if (!$col) {
                    $pdo->exec("ALTER TABLE setting ADD COLUMN AuthMode VARCHAR(10) NOT NULL DEFAULT '0'");
                }
                $count = $pdo->query("SELECT COUNT(*) FROM setting")->fetchColumn();
                if ($count > 0) {
                    $st = $pdo->prepare("UPDATE setting SET AuthMode = :m LIMIT 1");
                    $st->execute([':m'=>$authMode]);
                } else {
                    $st = $pdo->prepare("INSERT INTO setting (LiveMergeMethod, AuthMode) VALUES ('merge', :m)");
                    $st->execute([':m'=>$authMode]);
                }
            } catch (Exception $e) {}
            clearstatcache();
            header('Location: settings.php?saved=1');
            exit;
        }
    } elseif (isset($_POST['update_account'])) {
        $currentUser = trim($_POST['current_username'] ?? '');
        $currentPass = trim($_POST['current_password'] ?? '');
        $newUser = trim($_POST['new_username'] ?? '');
        $newPass = trim($_POST['new_password'] ?? '');
        $newPass2 = trim($_POST['new_password_confirm'] ?? '');
        if ($currentUser === '' || $currentPass === '' || $newUser === '' || $newPass === '' || $newPass2 === '') {
            $err = '请完整填写账号字段';
        } elseif ($newPass !== $newPass2) {
            $err = '两次输入的新密码不一致';
        } else {
            $stmt = $pdo->prepare("SELECT * FROM users WHERE username = :u LIMIT 1");
            $stmt->execute([':u' => $currentUser]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$user || !password_verify($currentPass, $user['password'])) {
                $err = '当前用户名或密码错误';
            } else {
                $hash = password_hash($newPass, PASSWORD_BCRYPT);
                $stmt = $pdo->prepare("UPDATE users SET username=:nu, password=:np WHERE id=:id");
                $stmt->execute([':nu'=>$newUser, ':np'=>$hash, ':id'=>$user['id']]);
                header('Location: settings.php?account=1');
                exit;
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>系统设置 - 系统管理</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <?php include "header.php"; ?>
    <div class="container">
        <h1 class="page-title">系统设置</h1>
        <div class="main-content fade-in">
            <?php if ($msg): ?><div class="message"><?=$msg?></div><?php endif; ?>
            <?php if ($err): ?><div class="message error"><?=$err?></div><?php endif; ?>
            <div class="card">
                <h3 style="margin-bottom: 20px; color: var(--secondary-color);">基础配置</h3>
                <form method="post">
                    <input type="hidden" name="update_config" value="1">
                    <div class="form-group">
                        <label class="form-label">系统域名</label>
                        <input type="text" name="domain" class="form-input" placeholder="例如：https://example.com" value="<?=htmlspecialchars($config['domain'] ?? '')?>" required>
                    </div>
                    <div class="form-group">
                        <label class="form-label">授权模式</label>
                        <?php
                        $authModeVal = '0';
                        try {
                            $col = $pdo->query("SHOW COLUMNS FROM setting LIKE 'AuthMode'")->fetch(PDO::FETCH_ASSOC);
                            if ($col) {
                                $row = $pdo->query("SELECT AuthMode FROM setting LIMIT 1")->fetch(PDO::FETCH_ASSOC);
                                if ($row && isset($row['AuthMode'])) { $authModeVal = (string)$row['AuthMode']; }
                            }
                        } catch (Exception $e) {}
                        ?>
                        <select name="auth_mode" class="form-select">
                            <option value="0" <?= $authModeVal==='1' ? '' : 'selected' ?>>关闭</option>
                            <option value="1" <?= $authModeVal==='1' ? 'selected' : '' ?>>开启</option>
                        </select>
                    </div>
                    <button type="submit" class="btn btn-primary">保存配置</button>
                </form>
            </div>
            <div class="card">
                <h3 style="margin-bottom: 20px; color: var(--secondary-color);">账户设置</h3>
                <form method="post">
                    <input type="hidden" name="update_account" value="1">
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">当前用户名</label>
                            <input type="text" name="current_username" class="form-input" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">当前密码</label>
                            <input type="password" name="current_password" class="form-input" required>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label">新用户名</label>
                            <input type="text" name="new_username" class="form-input" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">新密码</label>
                            <input type="password" name="new_password" class="form-input" required>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="form-label">确认新密码</label>
                        <input type="password" name="new_password_confirm" class="form-input" required>
                    </div>
                    <button type="submit" class="btn btn-primary">保存账户</button>
                </form>
            </div>
        </div>
    </div>
</body>
</html>
