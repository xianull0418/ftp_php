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
            color: white;
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

    <div id="loading">正在处理...</div>

    <script>
    // 显示加载中
    function showLoading() {
        document.getElementById('loading').style.display = 'flex';
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
                showFileSection();
            } else {
                alert(data.message || '登录失败');
            }
        } catch (error) {
            console.error('Error:', error);
            alert('连接错误: ' + error.message);
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
            
            const fileList = document.getElementById('file-list');
            if (data.success) {
                if (Array.isArray(data.files) && data.files.length > 0) {
                    fileList.innerHTML = data.files.map(file => 
                        `<div class="file-item">
                            <span>${file}</span>
                            <div class="file-actions">
                                <button onclick="downloadFile('${file}')" class="btn btn-small">下载</button>
                                <button onclick="deleteFile('${file}')" class="btn btn-small btn-danger">删除</button>
                            </div>
                        </div>`
                    ).join('');
                } else {
                    fileList.innerHTML = '<div class="empty-message">目录为空</div>';
                }
            } else {
                if (data.message === '未登录') {
                    document.getElementById('loginSection').style.display = 'block';
                    document.getElementById('fileSection').style.display = 'none';
                }
                fileList.innerHTML = `<div class="error-message">${data.message}</div>`;
            }
        } catch (error) {
            console.error('Error loading file list:', error);
            document.getElementById('file-list').innerHTML = 
                `<div class="error-message">加载文件列表失败: ${error.message}</div>`;
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

    // 处理上传表单提交
    document.getElementById('uploadForm').onsubmit = async function(e) {
        e.preventDefault();
        showLoading();
        
        try {
            const formData = new FormData(this);
            const response = await fetch('upload.php', {
                method: 'POST',
                body: formData
            });
            
            const data = await response.json();
            if (data.success) {
                alert('文件上传成功');
                loadFileList(); // 重新加载文件列表
            } else {
                alert(data.message || '上传失败');
            }
        } catch (error) {
            console.error('Error:', error);
            alert('上传错误: ' + error.message);
        } finally {
            hideLoading();
            this.reset(); // 重置表单
        }
    };
    </script>

    <style>
    .header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 20px;
    }
    .file-item {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 8px;
        border-bottom: 1px solid #eee;
    }
    .file-actions {
        display: flex;
        gap: 8px;
    }
    .btn-small {
        padding: 4px 8px;
        font-size: 12px;
    }
    .btn-danger {
        background-color: #dc3545;
    }
    .empty-message {
        padding: 20px;
        text-align: center;
        color: #666;
    }
    .error-message {
        padding: 20px;
        text-align: center;
        color: #dc3545;
    }
    </style>
</body>
</html> 