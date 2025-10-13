
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>系统管理</title>
    <link rel="stylesheet" href="style.css">
</head>

    <header class="nav-header">
        <div class="container">
            <div class="nav-container">
                <nav>
                    <ul class="nav-menu">
                        <li><a href="index.php" class="nav-link" id="link-user">用户管理</a></li>
                        <li class="nav-separator"></li>
                        <li><a href="edit_repo_group.php" class="nav-link" id="link-repo-group">多仓管理</a></li>
                        <li class="nav-separator"></li>
                        <li><a href="edit_repos.php" class="nav-link" id="link-repos">远程仓</a></li>
                        <li class="nav-separator"></li>
                        <li><a href="local_repo.php" class="nav-link" id="link-local">本地仓</a></li>
						<li class="nav-separator"></li>
                        <li><a href="edit_emby.php" class="nav-link" id="link-local">EMBY</a></li>
                        <li class="nav-separator"></li>
                        <li><a href="edit_lives.php" class="nav-link" id="link-lives">直播源</a></li>
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
            document.getElementById('current-page').textContent = pageTitle;
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
