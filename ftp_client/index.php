<!DOCTYPE html>
<html>
<head>
    <title>FTP客户端</title>
    <meta charset="utf-8">
    <style>
        body { 
            font-family: Arial, sans-serif;
            margin: 20px;
        }
        .container {
            max-width: 800px;
            margin: 0 auto;
        }
        .form-group {
            margin-bottom: 15px;
        }
        .form-group input {
            margin-bottom: 10px;
            padding: 5px;
            width: 200px;
        }
        .btn {
            padding: 8px 15px;
            background-color: #007bff;
            color: white;
            border: none;
            cursor: pointer;
        }
        #loading {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            display: none;
            justify-content: center;
            align-items: center;
            flex-direction: column;
            z-index: 1000;
        }
        .loading-content {
            background: white;
            padding: 20px;
            border-radius: 8px;
            text-align: center;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .spinner {
            width: 40px;
            height: 40px;
            margin: 10px auto;
            border: 4px solid #f3f3f3;
            border-top: 4px solid #3498db;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        .upload-progress {
            display: none;
            margin-top: 10px;
            text-align: center;
        }
        .progress-bar {
            width: 200px;
            height: 6px;
            background-color: #f3f3f3;
            border-radius: 3px;
            margin: 10px auto;
            overflow: hidden;
        }
        .progress-bar-fill {
            height: 100%;
            background-color: #4CAF50;
            width: 0%;
            transition: width 0.3s ease;
        }
        .file-list {
            margin: 20px 0;
            border: 1px solid #ddd;
            border-radius: 4px;
            overflow: hidden;
        }
        
        .file-list-header {
            display: grid;
            grid-template-columns: 3fr 2fr 1fr 1fr 1fr;
            gap: 15px;
            background-color: #f8f9fa;
            padding: 12px 15px;
            font-weight: bold;
            border-bottom: 2px solid #dee2e6;
            align-items: center;
        }
        
        .file-item {
            display: grid;
            grid-template-columns: 3fr 2fr 1fr 1fr 1fr;
            gap: 15px;
            padding: 12px 15px;
            border-bottom: 1px solid #eee;
            transition: background-color 0.2s;
            align-items: center;
        }
        
        .file-item:hover {
            background-color: #f8f9fa;
        }
        
        .file-name {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .file-icon {
            width: 24px;
            text-align: center;
            font-size: 1.2em;
        }
        
        .file-date, .file-type, .file-size {
            color: #666;
        }
        
        .file-actions {
            display: flex;
            gap: 8px;
            justify-content: flex-end;
        }
        
        .btn-small {
            padding: 4px 8px;
            font-size: 12px;
            border-radius: 3px;
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }
        
        .btn-primary { background-color: #007bff; }
        .btn-download { background-color: #28a745; }
        .btn-delete { background-color: #dc3545; }
        
        .icon-directory { color: #ffd700; }
        .icon-image { color: #28a745; }
        .icon-document { color: #007bff; }
        .icon-archive { color: #6c757d; }
        .icon-file { color: #495057; }
        
        .empty-message {
            padding: 30px;
            text-align: center;
            color: #666;
            background-color: #f8f9fa;
        }
        
        .directory-path {
            margin-bottom: 15px;
            padding: 10px;
            background-color: #f8f9fa;
            border-radius: 4px;
            color: #666;
        }
    </style>
</head>
<body>
    <div class="container">
        <h2>FTP客户端</h2>
        
        <div class="form-group" id="loginSection">
            <h3>登录</h3>
            <form id="loginForm">
                <input type="text" name="server" placeholder="服务器IP地址" required><br>
                <input type="number" name="port" placeholder="端口号" value="21" required><br>
                <input type="text" name="username" placeholder="用户名" required><br>
                <input type="password" name="password" placeholder="密码" required><br>
                <button type="submit" class="btn">登录</button>
            </form>
        </div>
        
        <div class="form-group" id="fileSection" style="display: none;">
            <div class="header">
                <h3>已连接到FTP服务器 <span id="serverInfo"></span></h3>
                <button onclick="logout()" class="btn">退出登录</button>
            </div>
            <h3>文件列表</h3>
            <div id="file-list">
                <!-- 文件列表将通过AJAX动态加载 -->
            </div>
        
            <h3>上传文件</h3>
            <form id="uploadForm" enctype="multipart/form-data">
                <input type="file" name="file" required>
                <button type="submit" class="btn">上传</button>
            </form>
        </div>
    </div>

    <div id="loading">
        <div class="loading-content">
            <div class="spinner"></div>
            <div id="loading-text">处理中...</div>
            <div class="upload-progress" id="upload-progress">
                <div class="progress-bar">
                    <div class="progress-bar-fill" id="progress-bar-fill"></div>
                </div>
                <div id="progress-text">正在上传: 0%</div>
            </div>
        </div>
    </div>

    <script>
    let currentPath = '/';  // 添加全局变量跟踪当前路径

    function getCurrentPath() {
        return currentPath || '/';
    }

    // 显示加载中
    function showLoading(message = '处理中...') {
        document.getElementById('loading').style.display = 'flex';
        document.getElementById('loading-text').textContent = message;
        document.getElementById('upload-progress').style.display = 'none';
    }

    // 隐藏加载中
    function hideLoading() {
        document.getElementById('loading').style.display = 'none';
    }

    // 检查登录状态
    async function checkLoginStatus() {
        try {
            const response = await fetch('check_login.php');
            const data = await response.json();
            if (data.success) {
                showFileSection();
            }
        } catch (error) {
            console.error('检查登录状态失败:', error);
        }
    }

    // 页面加载时检查登录状态
    window.onload = checkLoginStatus;

    // 处理登录表单提交
    document.getElementById('loginForm').onsubmit = async function(e) {
        e.preventDefault();
        showLoading();
        
        try {
            const formData = new FormData(this);
            const response = await fetch('login.php', {
                method: 'POST',
                body: formData
            });
            
            const data = await response.json();
            if (data.success) {
                sessionStorage.setItem('server', formData.get('server'));
                showFileSection();
            } else {
                alert(data.message || '登录失败');
            }
        } catch (error) {
            console.error('Error:', error);
            alert('登录错误: ' + error.message);
        } finally {
            hideLoading();
        }
    };

    // 显示文件区域
    function showFileSection() {
        document.getElementById('loginSection').style.display = 'none';
        document.getElementById('fileSection').style.display = 'block';
        loadFileList();
    }

    // 加载文件列表
    async function loadFileList() {
        showLoading();
        try {
            const response = await fetch('list.php');
            const data = await response.json();
            if (data.success) {
                currentPath = data.current_path || '/';
                renderFileList(data.files);
                document.getElementById('serverInfo').textContent = 
                    `(${data.username}@${sessionStorage.getItem('server')})`;
            } else {
                alert(data.message || '加载文件列表失败');
            }
        } catch (error) {
            console.error('Error:', error);
            alert('加载文件列表失败: ' + error.message);
        } finally {
            hideLoading();
        }
    }

    // 登出
    async function logout() {
        showLoading();
        try {
            await fetch('logout.php');
            document.getElementById('loginSection').style.display = 'block';
            document.getElementById('fileSection').style.display = 'none';
        } catch (error) {
            console.error('Logout error:', error);
        } finally {
            hideLoading();
        }
    }

    // 修改上传表单处理
    document.getElementById('uploadForm').onsubmit = async function(e) {
        e.preventDefault();
        showUploadProgress();
        
        try {
            const formData = new FormData(this);
            formData.append('path', currentPath);
            
            const xhr = new XMLHttpRequest();
            xhr.upload.onprogress = function(e) {
                if (e.lengthComputable) {
                    const percent = Math.round((e.loaded / e.total) * 100);
                    updateProgress(percent);
                }
            };
            
            xhr.onload = async function() {
                if (xhr.status === 200) {
                    const data = JSON.parse(xhr.responseText);
                    if (data.success) {
                        alert('文件上传成功');
                        await enterDirectory(currentPath);
                    } else {
                        alert(data.message || '上传失败');
                    }
                } else {
                    alert('上传失败');
                }
                hideLoading();
                e.target.reset();
            };
            
            xhr.onerror = function() {
                alert('上传错误');
                hideLoading();
                e.target.reset();
            };
            
            xhr.open('POST', 'upload.php', true);
            xhr.send(formData);
        } catch (error) {
            console.error('Error:', error);
            alert('上传错误: ' + error.message);
            hideLoading();
            this.reset();
        }
    };

    // 修改文件列表渲染逻辑
    function renderFileList(files) {
        const fileList = document.getElementById('file-list');
        if (!files || files.length === 0) {
            fileList.innerHTML = '<div class="empty-message">目录为空</div>';
            return;
        }

        // 确保files是数组
        if (typeof files === 'string') {
            try {
                files = JSON.parse(files);
            } catch (e) {
                console.error('解析文件列表失败:', e);
                fileList.innerHTML = '<div class="error-message">加载文件列表失败</div>';
                return;
            }
        }

        fileList.innerHTML = `
            <div class="directory-path">
                <i class="fas fa-folder-open"></i> 
                当前目录: ${getCurrentPath()}
                ${currentPath !== '/' ? `
                    <button onclick="goToParentDirectory()" class="btn btn-small">
                        <i class="fas fa-level-up-alt"></i> 返回上级
                    </button>
                ` : ''}
            </div>
            <div class="file-list">
                <div class="file-list-header">
                    <div>文件名</div>
                    <div>修改时间</div>
                    <div>类型</div>
                    <div>大小</div>
                    <div>操作</div>
                </div>
                ${files.map(file => {
                    // 确保file是对象
                    if (typeof file === 'string') {
                        try {
                            file = JSON.parse(file);
                        } catch (e) {
                            console.error('解析文件数据失败:', e);
                            return '';
                        }
                    }
                    
                    return `
                        <div class="file-item">
                            <div class="file-name">
                                ${getFileIcon(file)}
                                <span>${file.name}</span>
                            </div>
                            <div class="file-date">${file.mtime}</div>
                            <div class="file-type">${getFileTypeText(file)}</div>
                            <div class="file-size">${file.is_dir ? '-' : file.size}</div>
                            <div class="file-actions">
                                ${file.is_dir ? `
                                    <button onclick="enterDirectory('${file.path}')" class="btn btn-small btn-primary">
                                        <i class="fas fa-folder-open"></i> 打开
                                    </button>
                                ` : `
                                    <button onclick="downloadFile('${file.path}')" class="btn btn-small btn-download">
                                        <i class="fas fa-download"></i> 下载
                                    </button>
                                `}
                                <button onclick="deleteFile('${file.path}')" class="btn btn-small btn-delete">
                                    <i class="fas fa-trash"></i> 删除
                                </button>
                            </div>
                        </div>
                    `;
                }).join('')}
            </div>
        `;
    }

    function getFileIcon(file) {
        const icons = {
            'directory': 'fa-folder',
            'image': 'fa-file-image',
            'document': 'fa-file-alt',
            'archive': 'fa-file-archive',
            'file': 'fa-file'
        };
        
        const iconClass = icons[file.type] || icons.file;
        const colorClass = `icon-${file.type}`;
        return `<i class="fas ${iconClass} ${colorClass}"></i>`;
    }

    // 添加目录导航功能
    async function enterDirectory(path) {
        showLoading();
        try {
            // 保存上一个路径，以便返回
            const prevPath = currentPath;
            
            const response = await fetch(`list.php?path=${encodeURIComponent(path)}`);
            const data = await response.json();
            if (data.success) {
                currentPath = data.current_path || path;  // 更新当前路径
                renderFileList(data.files);
                
                // 更新浏览器历史记录
                const state = { path: currentPath };
                const title = `FTP - ${currentPath}`;
                window.history.pushState(state, title, `?path=${encodeURIComponent(currentPath)}`);
            } else {
                alert(data.message || '无法打开目录');
                currentPath = prevPath;  // 恢复之前的路径
            }
        } catch (error) {
            console.error('Error:', error);
            alert('打开目录失败: ' + error.message);
        } finally {
            hideLoading();
        }
    }

    // 添加返回上级目录功能
    function goToParentDirectory() {
        if (currentPath === '/') return;
        
        const parentPath = currentPath.split('/').slice(0, -1).join('/') || '/';
        enterDirectory(parentPath);
    }

    function getFileTypeText(file) {
        const types = {
            'directory': '文件夹',
            'image': '图片',
            'document': '文档',
            'archive': '压缩包',
            'file': '文件'
        };
        return types[file.type] || '文件';
    }

    function showUploadProgress() {
        document.getElementById('loading').style.display = 'flex';
        document.getElementById('upload-progress').style.display = 'block';
        document.getElementById('loading-text').textContent = '文件上传中';
    }

    function updateProgress(percent) {
        document.getElementById('progress-bar-fill').style.width = `${percent}%`;
        document.getElementById('progress-text').textContent = `正在上传: ${percent}%`;
    }
    </script>

    <!-- 添加 Font Awesome 图标库 -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
</body>
</html> 