<?php $authMode='0'; $cfgp=__DIR__.'/config/config.php'; if (file_exists($cfgp)) { $cfg=require $cfgp; try { $pdo=new PDO("mysql:host=".$cfg['db_host'].";dbname=".$cfg['db_name'].";charset=utf8mb4",$cfg['db_user'],$cfg['db_pass']); $pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION); $st=$pdo->query("SHOW COLUMNS FROM setting LIKE 'AuthMode'"); $has=$st->fetch(PDO::FETCH_ASSOC); if($has){ $rs=$pdo->query("SELECT AuthMode FROM setting LIMIT 1"); $authModeVal=$rs->fetch(PDO::FETCH_ASSOC); if($authModeVal&&isset($authModeVal['AuthMode'])){$authMode=$authModeVal['AuthMode'];} else {$authMode='0';} } else { $authMode=isset($cfg['auth_mode'])?$cfg['auth_mode']:'0'; } } catch(Exception $e){ $authMode=isset($cfg['auth_mode'])?$cfg['auth_mode']:'0'; } } ?>
<header class="nav-header">
        <div class="container">
            <div class="nav-container">
                <nav>
                    <ul class="nav-menu">
						<li><a href="index.php" class="nav-link" id="link-repo-group">主页</a></li>
                        <li class="nav-separator"></li>
                        <li><a href="edit_repo_group.php" class="nav-link" id="link-repo-group">多仓</a></li>
                        <li class="nav-separator"></li>
                        <li><a href="edit_repos.php" class="nav-link" id="link-repos">远程仓</a></li>
                        <li class="nav-separator"></li>
                        <li><a href="local_repo.php" class="nav-link" id="link-local">本地仓</a></li>
						<li class="nav-separator"></li>
						<li><a href="edit_snippet.php" class="nav-link" id="link-local">片段</a></li>
						<li class="nav-separator"></li>
                        <li><a href="edit_emby.php" class="nav-link" id="link-local">EMBY</a></li>
                        <li class="nav-separator"></li>
                        <li><a href="edit_lives.php" class="nav-link" id="link-lives">直播源</a></li>
						<li class="nav-separator"></li>
                        <?php if($authMode==='1'): ?>
                        <li><a href="devices.php" class="nav-link" id="link-devices">设备</a></li>
                        <li class="nav-separator"></li>
                        <?php endif; ?>
                        <li><a href="settings.php" class="nav-link" id="link-settings">设置</a></li>
                        <li class="nav-separator"></li>
                        <li><a href="download.php" class="nav-link" id="link-download" target="_blank">下载应用</a></li>
                    </ul>
                </nav>
            </div>
        </div>
    </header>
    <script>
        // 获取当前页面文件名
        function getCurrentPage() {
            const path = window.location.pathname;
            return path.substring(path.lastIndexOf('/') + 1);
        }
        
        // 设置活动链接
        function setActiveLink() {
            const currentPage = getCurrentPage();
            const links = document.querySelectorAll('.nav-link');
            let pageTitle = '系统管理';
            
            links.forEach(link => {
                const href = link.getAttribute('href');
                const linkPage = href.substring(href.lastIndexOf('/') + 1);
                
                if (currentPage === linkPage || (currentPage === '' && linkPage === 'index.php')) {
                    link.classList.add('active');
                    pageTitle = link.textContent;
                }
            });
            
            // 更新页面标题
            var titleEl = document.getElementById('current-page') || document.querySelector('.page-title');
            if (titleEl) { titleEl.textContent = pageTitle; }
            document.title = pageTitle + ' - 系统管理';
        }
        
        // 页面加载完成后设置活动链接
        document.addEventListener('DOMContentLoaded', function() {
            setActiveLink();
            
            // 添加平滑滚动效果
            document.querySelectorAll('.nav-link').forEach(link => {
                link.addEventListener('click', function(e) {
                    if (this.getAttribute('href').startsWith('#')) {
                        e.preventDefault();
                        const target = document.querySelector(this.getAttribute('href'));
                        if (target) {
                            target.scrollIntoView({ behavior: 'smooth' });
                        }
                    }
                });
            });
        });
    </script>
