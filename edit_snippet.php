<?php
// excerpt_manager.php - 片段管理页面
session_start();
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: login.php'); 
    exit;
}

header('Content-Type: text/html; charset=utf-8');

// 配置
$EXCERPT_DIR = __DIR__ . '/snippet';
$ALLOWED_EXTENSIONS = ['js', 'json'];

// 处理表单提交
$message = '';
$message_type = '';

// 编辑模式相关变量
$edit_mode = false;
$editing_snippet = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        $snippet_name = trim($_POST['snippet_name'] ?? '');
        
        switch ($_POST['action']) {
            case 'upload':
                if (empty($snippet_name)) {
                    $message = '片段名称不能为空';
                    $message_type = 'error';
                    break;
                }
                
                // 检查片段是否已存在（编辑模式下除外）
                $snippet_dir = $EXCERPT_DIR . '/' . $snippet_name;
                $is_edit = isset($_POST['original_name']);
                
                if (!$is_edit && is_dir($snippet_dir)) {
                    $message = '片段名称已存在';
                    $message_type = 'error';
                    break;
                }
                
                // 如果是编辑模式且修改了名称，需要重命名目录
                if ($is_edit) {
                    $original_name = trim($_POST['original_name']);
                    $original_dir = $EXCERPT_DIR . '/' . $original_name;
                    
                    if ($snippet_name !== $original_name) {
                        // 重命名目录
                        if (!rename($original_dir, $snippet_dir)) {
                            $message = '重命名片段目录失败';
                            $message_type = 'error';
                            break;
                        }
                    }
                } else {
                    // 创建新片段目录
                    if (!mkdir($snippet_dir, 0755, true)) {
                        $message = '创建片段目录失败，请检查权限';
                        $message_type = 'error';
                        break;
                    }
                }
                
                // 保存JS文件（如果有上传新文件）
                if (isset($_FILES['js_file']) && $_FILES['js_file']['error'] === UPLOAD_ERR_OK) {
                    $js_content = file_get_contents($_FILES['js_file']['tmp_name']);
                    $js_file = $snippet_dir . '/' . $snippet_name . '.js';
                    if (!file_put_contents($js_file, $js_content)) {
                        $message = '保存JS文件失败';
                        $message_type = 'error';
                        if (!$is_edit) rmdir($snippet_dir);
                        break;
                    }
                } elseif (!$is_edit) {
                    // 新增模式下必须上传JS文件
                    $message = '请上传JS文件';
                    $message_type = 'error';
                    rmdir($snippet_dir);
                    break;
                }
                
                // 保存JSON文件
                $json_content = trim($_POST['json_content'] ?? '');
                if (!empty($json_content)) {
                    // 验证JSON格式
                    json_decode($json_content);
                    if (json_last_error() !== JSON_ERROR_NONE) {
                        $message = 'JSON格式无效';
                        $message_type = 'error';
                        if (!$is_edit) {
                            if (isset($js_file)) unlink($js_file);
                            rmdir($snippet_dir);
                        }
                        break;
                    }
                    
                    $json_file = $snippet_dir . '/' . $snippet_name . '.json';
                    if (!file_put_contents($json_file, $json_content)) {
                        $message = '保存JSON文件失败';
                        $message_type = 'error';
                        if (!$is_edit) {
                            if (isset($js_file)) unlink($js_file);
                            rmdir($snippet_dir);
                        }
                        break;
                    }
                } else {
                    $message = 'JSON内容不能为空';
                    $message_type = 'error';
                    if (!$is_edit) {
                        if (isset($js_file)) unlink($js_file);
                        rmdir($snippet_dir);
                    }
                    break;
                }
                
                $message = $is_edit ? '片段更新成功' : '片段上传成功';
                $message_type = 'success';
                break;
                
            case 'toggle':
                $snippet_dir = $EXCERPT_DIR . '/' . $snippet_name;
                $enable_file = $snippet_dir . '/enable';
                
                if (!is_dir($snippet_dir)) {
                    $message = '片段不存在';
                    $message_type = 'error';
                    break;
                }
                
                if (file_exists($enable_file)) {
                    // 停用 - 删除enable文件
                    if (unlink($enable_file)) {
                        $message = '片段已停用';
                        $message_type = 'success';
                    } else {
                        $message = '停用失败，请检查文件权限';
                        $message_type = 'error';
                    }
                } else {
                    // 启用 - 创建enable文件
                    $result = file_put_contents($enable_file, '');
                    if ($result !== false) {
                        $message = '片段已启用';
                        $message_type = 'success';
                    } else {
                        $message = '启用失败，请检查文件权限';
                        $message_type = 'error';
                    }
                }
                break;
                
            case 'delete':
                $snippet_dir = $EXCERPT_DIR . '/' . $snippet_name;
                
                if (!is_dir($snippet_dir)) {
                    $message = '片段不存在';
                    $message_type = 'error';
                    break;
                }
                
                // 递归删除目录
                $files = array_diff(scandir($snippet_dir), ['.', '..']);
                $delete_success = true;
                
                foreach ($files as $file) {
                    $file_path = $snippet_dir . '/' . $file;
                    if (is_dir($file_path)) {
                        if (!rmdir($file_path)) {
                            $delete_success = false;
                        }
                    } else {
                        if (!unlink($file_path)) {
                            $delete_success = false;
                        }
                    }
                }
                
                if ($delete_success && rmdir($snippet_dir)) {
                    $message = '片段删除成功';
                    $message_type = 'success';
                } else {
                    $message = '删除失败，请检查文件权限';
                    $message_type = 'error';
                }
                break;
                
            case 'edit':
                // 设置编辑模式
                $snippet_name = trim($_POST['snippet_name'] ?? '');
                $snippet_dir = $EXCERPT_DIR . '/' . $snippet_name;
                
                if (!is_dir($snippet_dir)) {
                    $message = '片段不存在';
                    $message_type = 'error';
                    break;
                }
                
                // 查找JSON文件内容
                $json_file = null;
                $js_file = null;
                $files = scandir($snippet_dir);
                foreach ($files as $file) {
                    if ($file === '.' || $file === '..' || $file === 'enable') continue;
                    
                    $ext = pathinfo($file, PATHINFO_EXTENSION);
                    if ($ext === 'json') {
                        $json_file = $file;
                    } elseif ($ext === 'js') {
                        $js_file = $file;
                    }
                }
                
                if ($json_file) {
                    $json_content = file_get_contents($snippet_dir . '/' . $json_file);
                    $editing_snippet = [
                        'name' => $snippet_name,
                        'json_content' => $json_content,
                        'js_file' => $js_file
                    ];
                    $edit_mode = true;
                }
                break;
        }
    }
}

// 获取所有片段
$snippets = [];
if (is_dir($EXCERPT_DIR)) {
    $items = scandir($EXCERPT_DIR);
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        
        $snippet_dir = $EXCERPT_DIR . '/' . $item;
        if (!is_dir($snippet_dir)) continue;
        
        // 检查是否有JS和JSON文件
        $js_file = null;
        $json_file = null;
        
        $files = scandir($snippet_dir);
        foreach ($files as $file) {
            if ($file === '.' || $file === '..' || $file === 'enable') continue;
            
            $ext = pathinfo($file, PATHINFO_EXTENSION);
            if ($ext === 'js') {
                $js_file = $file;
            } elseif ($ext === 'json') {
                $json_file = $file;
            }
        }
        
        if ($js_file && $json_file) {
            $enable_file = $snippet_dir . '/enable';
            $snippets[] = [
                'name' => $item,
                'js_file' => $js_file,
                'json_file' => $json_file,
                'enabled' => file_exists($enable_file),
                'created_at' => date('Y-m-d H:i:s', filemtime($snippet_dir))
            ];
        }
    }
}
?>

<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>片段管理 - 系统管理</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <?php include "header.php"; ?>
    
    <div class="container">
        <h1 class="page-title">片段管理</h1>
        
        <div class="main-content fade-in">
            <!-- 统计信息 -->
            <div class="stat-card">
                <div class="stat-number"><?= count($snippets) ?></div>
                <div class="stat-label">已配置的片段</div>
            </div>
            
            <?php if (!empty($message)): ?>
                <div class="message <?php echo $message_type === 'error' ? 'error' : ''; ?>">
                    <?php echo htmlspecialchars($message); ?>
                </div>
            <?php endif; ?>
            
            <!-- 添加片段按钮 -->
            <div class="toggle-section">
                <button class="btn btn-primary" onclick="toggleUploadSection()">
                    <?php echo $edit_mode ? '✏️ 编辑片段' : '➕ 添加新片段'; ?>
                </button>
                <?php if ($edit_mode): ?>
                    <button class="btn" onclick="cancelEdit()" style="margin-left: 10px;">取消编辑</button>
                <?php endif; ?>
            </div>
            
            <!-- 新增/编辑片段表单 -->
            <div class="card upload-section <?php echo $edit_mode || isset($_POST['action']) && $_POST['action'] === 'upload' ? 'active' : ''; ?>" id="uploadSection">

                <h3 style="margin-bottom: 20px; color: var(--secondary-color);">
                    <?php echo $edit_mode ? '编辑片段' : '上传新片段'; ?>
                </h3>
                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="upload">
                    <?php if ($edit_mode): ?>
                        <input type="hidden" name="original_name" value="<?php echo htmlspecialchars($editing_snippet['name']); ?>">
                    <?php endif; ?>
                    
                    <div class="form-group">
                        <label class="form-label">片段名称</label>
                        <input type="text" name="snippet_name" class="form-input" 
                               placeholder="输入片段名称" 
                               value="<?php echo $edit_mode ? htmlspecialchars($editing_snippet['name']) : ''; ?>" 
                               required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">JS 文件</label>
                        <input type="file" name="js_file" class="form-input file-input" accept=".js" 
                               <?php echo !$edit_mode ? 'required' : ''; ?>>
                        <?php if ($edit_mode): ?>
                            <div class="file-hint">
                                当前文件: <?php echo htmlspecialchars($editing_snippet['js_file']); ?><br>
                                如不需要更新JS文件，请留空
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label">JSON 内容</label>
                        <textarea name="json_content" class="form-input form-textarea" placeholder="请输入有效的JSON内容..." required><?php echo $edit_mode ? htmlspecialchars($editing_snippet['json_content']) : ''; ?></textarea>
                    </div>
                    
                    <div class="form-actions">
                        <button type="submit" class="btn btn-success">
                            <?php echo $edit_mode ? '更新片段' : '上传片段'; ?>
                        </button>
                        <button type="button" class="btn" onclick="toggleUploadSection()">取消</button>
                    </div>
                </form>
            </div>
            
            <!-- 片段列表 -->
            <?php if (empty($snippets)): ?>
                <div class="empty-state">
                    <div class="empty-state-icon">📝</div>
                    <h3>暂无片段</h3>
                    <p>点击"添加新片段"按钮上传第一个片段</p>
                </div>
            <?php else: ?>
                <div class="snippets-grid">
                    <?php foreach ($snippets as $snippet): ?>
                        <div class="snippet-card <?php echo $snippet['enabled'] ? 'enabled' : 'disabled'; ?>">
                            <div class="snippet-header">
                                <h3 class="snippet-name"><?php echo htmlspecialchars($snippet['name']); ?></h3>
                                <span class="snippet-status <?php echo $snippet['enabled'] ? 'status-enabled' : 'status-disabled'; ?>">
                                    <?php echo $snippet['enabled'] ? '已启用' : '已停用'; ?>
                                </span>
                            </div>
                            
                            <div class="snippet-info">
                                <div class="info-item">
                                    <span class="info-label">JS文件:</span>
                                    <span class="info-value"><?php echo htmlspecialchars($snippet['js_file']); ?></span>
                                </div>
                                <div class="info-item">
                                    <span class="info-label">JSON文件:</span>
                                    <span class="info-value"><?php echo htmlspecialchars($snippet['json_file']); ?></span>
                                </div>
                                <div class="info-item">
                                    <span class="info-label">创建时间:</span>
                                    <span class="info-value"><?php echo htmlspecialchars($snippet['created_at']); ?></span>
                                </div>
                            </div>
                            
                            <div class="snippet-actions">
                                <form method="POST" style="display: inline;">
                                    <input type="hidden" name="action" value="edit">
                                    <input type="hidden" name="snippet_name" value="<?php echo htmlspecialchars($snippet['name']); ?>">
                                    <button type="submit" class="btn btn-sm btn-info">编辑</button>
                                </form>
                                
                                <form method="POST" style="display: inline;">
                                    <input type="hidden" name="action" value="toggle">
                                    <input type="hidden" name="snippet_name" value="<?php echo htmlspecialchars($snippet['name']); ?>">
                                    <button type="submit" class="btn btn-sm <?php echo $snippet['enabled'] ? 'btn-warning' : 'btn-success'; ?>">
                                        <?php echo $snippet['enabled'] ? '停用' : '启用'; ?>
                                    </button>
                                </form>
                                
                                <form method="POST" style="display: inline;" 
                                      onsubmit="return confirm('确定要删除片段 <?php echo htmlspecialchars($snippet['name']); ?> 吗？此操作不可撤销。');">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="snippet_name" value="<?php echo htmlspecialchars($snippet['name']); ?>">
                                    <button type="submit" class="btn btn-sm btn-danger">删除</button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
        // 切换上传表单显示
        function toggleUploadSection() {
            const uploadSection = document.getElementById('uploadSection');
            uploadSection.classList.toggle('active');
        }
        
        // 取消编辑模式
        function cancelEdit() {
            window.location.href = window.location.pathname;
        }
        
        // 页面加载后的交互效果
        document.addEventListener('DOMContentLoaded', function() {
            // 自动隐藏消息提示
            setTimeout(() => {
                const alerts = document.querySelectorAll('.alert');
                alerts.forEach(alert => {
                    alert.style.opacity = '0';
                    alert.style.transition = 'opacity 0.5s ease-in-out';
                    setTimeout(() => {
                        if (alert.parentNode) {
                            alert.parentNode.removeChild(alert);
                        }
                    }, 500);
                });
            }, 5000);
            
            // 表单输入框焦点效果
            const inputs = document.querySelectorAll('.form-input');
            inputs.forEach(input => {
                input.addEventListener('focus', function() {
                    this.parentElement.style.transform = 'translateY(-2px)';
                });
                
                input.addEventListener('blur', function() {
                    this.parentElement.style.transform = 'translateY(0)';
                });
            });
            
            // 文件输入框样式增强
            const fileInputs = document.querySelectorAll('.file-input');
            fileInputs.forEach(input => {
                input.addEventListener('change', function() {
                    if (this.files.length > 0) {
                        this.style.borderColor = 'var(--success-color)';
                        this.style.background = 'var(--success-light)';
                    }
                });
            });
            
            // 卡片点击效果
            const cards = document.querySelectorAll('.snippet-card');
            cards.forEach(card => {
                card.addEventListener('click', function(e) {
                    if (!e.target.closest('form') && !e.target.closest('button')) {
                        this.style.transform = 'scale(0.98)';
                        setTimeout(() => {
                            this.style.transform = '';
                        }, 150);
                    }
                });
            });
            
            // JSON 格式验证
            const jsonTextarea = document.querySelector('textarea[name="json_content"]');
            if (jsonTextarea) {
                jsonTextarea.addEventListener('blur', function() {
                    try {
                        if (this.value.trim()) {
                            JSON.parse(this.value);
                            this.style.borderColor = 'var(--success-color)';
                        }
                    } catch (e) {
                        this.style.borderColor = 'var(--danger-color)';
                    }
                });
            }
            
            // 如果是编辑模式，自动显示表单
            <?php if ($edit_mode): ?>
                const uploadSection = document.getElementById('uploadSection');
                if (uploadSection) {
                    uploadSection.classList.add('active');
                }
            <?php endif; ?>
        });
    </script>
</body>
</html>