<?php
if (!extension_loaded('sockets')) {
    die('PHP sockets 扩展未安装。请安装 PHP sockets 扩展后再试。');
}

// 定义非阻塞模式的错误常量
if (!defined('EAGAIN')) {
    define('EAGAIN', 11);
}
if (!defined('EWOULDBLOCK')) {
    define('EWOULDBLOCK', 11);
}

class FTPServer {
    private $socket;
    private $running = true;
    private $clients = [];
    private $currentUser = null;
    private $clientIds = [];
    private $nextClientId = 1;
    private $baseDir = '/root/ftp_php';  // FTP根目录
    private $usersDir = '/root/ftp_php/users';  // 用户目录
    private $users = [
        'admin' => [
            'password' => 'admin123',
            'permissions' => ['read', 'write', 'delete', 'admin'],
            'root_dir' => '/root/ftp_php',
            'quota' => 1024 * 1024 * 1024, // 1GB配额
            'description' => '管理员账户'
        ],
        'user1' => [
            'password' => 'user123',
            'permissions' => ['read', 'write', 'delete'],
            'root_dir' => '/root/ftp_php/users/user1',
            'quota' => 500 * 1024 * 1024, // 500MB配额
            'description' => '普通用户'
        ],
        'guest' => [
            'password' => 'guest123',
            'permissions' => ['read'],
            'root_dir' => '/root/ftp_php/users/guest',
            'quota' => 100 * 1024 * 1024, // 100MB配额
            'description' => '访客账户'
        ]
    ];
    
    public function __construct($port = 21) {
        // 检查是否支持 pcntl
        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGTERM, [$this, 'handleSignal']);
            pcntl_signal(SIGINT, [$this, 'handleSignal']);
        }
        
        error_reporting(E_ALL);
        ini_set('display_errors', 1);
        
        $this->socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        socket_set_option($this->socket, SOL_SOCKET, SO_REUSEADDR, 1);
        
        // 尝试绑定端口
        $maxRetries = 3;
        $retryDelay = 2;
        
        for ($i = 0; $i < $maxRetries; $i++) {
            if (@socket_bind($this->socket, '0.0.0.0', $port)) {
                break;
            }
            
            echo "端口 $port 被占用，等待 $retryDelay 秒后重试...\n";
            sleep($retryDelay);
            
            if ($i === $maxRetries - 1) {
                die('无法绑定到端口 ' . $port . ': ' . socket_strerror(socket_last_error()));
            }
        }
        
        if (!socket_listen($this->socket, 5)) {
            die('无法监听: ' . socket_strerror(socket_last_error()));
        }
        
        echo "FTP服务器启动在端口 $port\n";

        // 初始化基础目录
        if (!is_dir($this->baseDir)) {
            if (!mkdir($this->baseDir, 0755, true)) {
                die("无法创建基础目录 {$this->baseDir}");
            }
            echo "已创建基础目录: {$this->baseDir}\n";
        }
        
        // 初始化用户目录
        foreach ($this->users as $username => $userInfo) {
            if (!is_dir($userInfo['root_dir'])) {
                if (!mkdir($userInfo['root_dir'], 0755, true)) {
                    die("无法创建用户目录 {$userInfo['root_dir']}");
                }
                echo "已创建用户 $username 的目录: {$userInfo['root_dir']}\n";
                
                // 设置目录权限
                chmod($userInfo['root_dir'], 0755);
                chown($userInfo['root_dir'], 'www-data');  // 或其他适当的用户
            }
        }
    }
    
    public function handleSignal($signo) {
        echo "\n收到信号 $signo，正在关闭服务器...\n";
        $this->running = false;
    }
    
    public function cleanup() {
        echo "正在清理资源...\n";
        
        // 关闭所有客户端连接
        foreach ($this->clients as $clientId => $username) {
            $this->cleanupClient($clientId);
        }
        
        // 关闭服务器socket
        if ($this->socket) {
            socket_close($this->socket);
        }
        
        echo "服务器已关闭\n";
    }
    
    public function run() {
        while ($this->running) {
            // 检查信号
            if (function_exists('pcntl_signal_dispatch')) {
                pcntl_signal_dispatch();
            }
            
            // 设置非阻塞模式
            socket_set_nonblock($this->socket);
            
            $client = @socket_accept($this->socket);
            $lastError = socket_last_error($this->socket);
            
            if ($client === false) {
                // 在非阻塞模式下，没有连接时会返回错误，这是正常的
                if ($lastError === EAGAIN || $lastError === EWOULDBLOCK) {
                    usleep(100000); // 100ms
                    continue;
                }
                
                // 其他错误才需要报告
                if ($lastError !== 0) {
                    echo "接受连接失败: " . socket_strerror($lastError) . "\n";
                }
                continue;
            }
            
            // 恢复阻塞模式
            socket_set_block($client);
            socket_set_block($this->socket);  // 主socket也恢复阻塞模式
            
            socket_getpeername($client, $address, $port);
            echo "新客户端连接: $address:$port\n";
            $this->handleClient($client);
        }
        
        $this->cleanup();
    }
    
    private function getClientId($client) {
        // 获取客户端的IP地址和端口
        socket_getpeername($client, $address, $port);
        $clientKey = "$address:$port";
        
        if (!isset($this->clientIds[$clientKey])) {
            $this->clientIds[$clientKey] = $this->nextClientId++;
        }
        return $this->clientIds[$clientKey];
    }
    
    private function handleClient($client) {
        try {
            $clientId = $this->getClientId($client);
            socket_getpeername($client, $address, $port);
            
            // 设置socket选项
            socket_set_option($client, SOL_SOCKET, SO_RCVTIMEO, array('sec' => 30, 'usec' => 0));
            socket_set_option($client, SOL_SOCKET, SO_SNDTIMEO, array('sec' => 30, 'usec' => 0));
            socket_set_option($client, SOL_SOCKET, SO_KEEPALIVE, 1);
            
            $this->sendResponse($client, "220 欢迎使用FTP服务器\r\n");
            
            while ($this->running) {
                $command = @socket_read($client, 1024, PHP_NORMAL_READ);
                
                if ($command === false) {
                    $error = socket_last_error($client);
                    if ($error === EAGAIN || $error === EWOULDBLOCK) {
                        continue;
                    }
                    throw new Exception("读取命令失败: " . socket_strerror($error));
                }
                
                if ($command === '') {
                    throw new Exception("客户端断开连接");
                }
                
                $command = trim($command);
                if (!empty($command)) {
                    echo "收到命令 ($address:$port): $command\n";
                    $this->handleCommand($client, $command);
                }
            }
        } catch (Exception $e) {
            error_log("客户端处理错误 ($address:$port): " . $e->getMessage());
        } finally {
            $this->cleanupClient($client);
            @socket_close($client);
        }
    }
    
    private function cleanupClient($client) {
        try {
            if (!is_resource($client)) {
                return;
            }
            
            socket_getpeername($client, $address, $port);
            $clientKey = "$address:$port";
            
            if (isset($this->clientIds[$clientKey])) {
                $clientId = $this->clientIds[$clientKey];
                unset($this->clients[$clientId]);
                unset($this->clientIds[$clientKey]);
                echo "清理客户端: $address:$port\n";
            }
        } catch (Exception $e) {
            error_log("清理客户端时发生错误: " . $e->getMessage());
        }
    }
    
    private function sendResponse($client, $message) {
        try {
            socket_getpeername($client, $address, $port);
            echo "发送响应到 $address:$port: $message";
            
            // 如果有mbstring扩展，使用它来处理编码
            if (function_exists('mb_convert_encoding')) {
                $message = mb_convert_encoding($message, 'UTF-8', mb_detect_encoding($message));
            }
            
            // 分块发送大消息
            $messageLength = strlen($message);
            $offset = 0;
            $chunkSize = 1024;
            
            while ($offset < $messageLength) {
                $chunk = substr($message, $offset, $chunkSize);
                $sent = @socket_write($client, $chunk, strlen($chunk));
                
                if ($sent === false) {
                    throw new Exception("发送响应失败: " . socket_strerror(socket_last_error($client)));
                }
                
                $offset += $sent;
            }
        } catch (Exception $e) {
            error_log("发送响应错误: " . $e->getMessage());
            throw $e;
        }
    }
    
    private function checkPermission($username, $permission) {
        if (!isset($this->users[$username])) {
            return false;
        }
        
        // admin用户拥有所有权限
        if (in_array('admin', $this->users[$username]['permissions'])) {
            return true;
        }
        
        return in_array($permission, $this->users[$username]['permissions']);
    }
    
    private function checkQuota($username, $size) {
        $userDir = $this->users[$username]['root_dir'];
        $quota = $this->users[$username]['quota'];
        
        $currentSize = $this->getDirSize($userDir);
        return ($currentSize + $size) <= $quota;
    }
    
    private function getDirSize($dir) {
        $size = 0;
        foreach (glob(rtrim($dir, '/').'/*', GLOB_NOSORT) as $each) {
            $size += is_file($each) ? filesize($each) : $this->getDirSize($each);
        }
        return $size;
    }
    
    private function handleCommand($client, $command) {
        if (empty($command)) {
            return;
        }
        
        $cmd = explode(' ', $command);
        $action = strtoupper($cmd[0]);
        $clientId = $this->getClientId($client);
        
        echo "处理命令: $action\n";
        
        switch ($action) {
            case 'USER':
                $username = isset($cmd[1]) ? $cmd[1] : '';
                if (isset($this->users[$username])) {
                    $this->currentUser = $username;
                    $this->sendResponse($client, "331 请输入密码\r\n");
                } else {
                    $this->sendResponse($client, "530 用户名不存在\r\n");
                }
                break;
                
            case 'PASS':
                $password = isset($cmd[1]) ? $cmd[1] : '';
                if (!isset($this->currentUser)) {
                    $this->sendResponse($client, "503 请先输入用户名\r\n");
                } elseif ($this->users[$this->currentUser]['password'] === $password) {
                    $this->clients[$clientId] = $this->currentUser;
                    $this->sendResponse($client, "230 登录成功\r\n");
                } else {
                    $this->sendResponse($client, "530 密码错误\r\n");
                }
                break;
                
            case 'LIST':
                if (!isset($this->clients[$clientId])) {
                    $this->sendResponse($client, "530 请先登录\r\n");
                    break;
                }
                $username = $this->clients[$clientId];
                
                if (!$this->checkPermission($username, 'read')) {
                    $this->sendResponse($client, "550 没有读取权限\r\n");
                    break;
                }
                
                // 获取请求的目录路径
                $requestPath = isset($cmd[1]) ? trim($cmd[1]) : '';
                $rootDir = $this->users[$username]['root_dir'];
                
                // 构建完整路径
                $targetDir = $rootDir;
                if (!empty($requestPath)) {
                    // 确保路径安全，防止目录遍历攻击
                    $requestPath = str_replace(['..', '\\'], ['', '/'], $requestPath);
                    $targetDir = $rootDir . '/' . ltrim($requestPath, '/');
                }
                
                if (!is_dir($targetDir)) {
                    $this->sendResponse($client, "550 目录不存在\r\n");
                    break;
                }
                
                try {
                    $this->sendResponse($client, "150 开始传输文件列表\r\n");
                    
                    $files = $this->getFileList($targetDir, $username, $requestPath);
                    foreach ($files as $file) {
                        $this->sendResponse($client, json_encode($file, JSON_UNESCAPED_UNICODE) . "\r\n");
                    }
                    
                    usleep(100000);
                    $this->sendResponse($client, "226 传输完成\r\n");
                } catch (Exception $e) {
                    $this->sendResponse($client, "550 获取文件列表失败: " . $e->getMessage() . "\r\n");
                }
                break;
                
            case 'STOR':
                if (!isset($this->clients[$clientId])) {
                    $this->sendResponse($client, "530 请先登录\r\n");
                    break;
                }
                $username = $this->clients[$clientId];
                
                if (!$this->checkPermission($username, 'write')) {
                    $this->sendResponse($client, "550 没有写入权限\r\n");
                    break;
                }
                
                $filename = isset($cmd[1]) ? $cmd[1] : '';
                if (empty($filename)) {
                    $this->sendResponse($client, "553 无效的文件名\r\n");
                    break;
                }
                
                // 构建完整的文件路径
                $filepath = $this->users[$username]['root_dir'] . '/' . $filename;
                $dirpath = dirname($filepath);
                
                // 确保目录存在
                if (!is_dir($dirpath)) {
                    if (!mkdir($dirpath, 0755, true)) {
                        $this->sendResponse($client, "550 无法创建目录\r\n");
                        break;
                    }
                }
                
                $this->sendResponse($client, "150 准备接收文件\r\n");
                
                try {
                    $fp = fopen($filepath, 'wb');
                    if (!$fp) {
                        throw new Exception("无法创建文件");
                    }
                    
                    $startTime = time();
                    $timeout = 30;
                    $bufferSize = 8192;
                    $dataReceived = false;
                    
                    // 设置socket为非阻塞模式
                    socket_set_nonblock($client);
                    
                    while (true) {
                        $data = @socket_read($client, $bufferSize, PHP_BINARY_READ);
                        if ($data === false) {
                            $error = socket_last_error($client);
                            if ($error === EAGAIN || $error === EWOULDBLOCK) {
                                if ($dataReceived && time() - $startTime > 1) {
                                    // 如果已经接收到数据并且超过1秒没有新数据，认为传输完成
                                    break;
                                }
                                if (time() - $startTime > $timeout) {
                                    throw new Exception("接收数据超时");
                                }
                                usleep(10000); // 10ms
                                continue;
                            }
                            throw new Exception("读取数据失败: " . socket_strerror($error));
                        }
                        
                        if ($data === '') {
                            if ($dataReceived) {
                                break;
                            }
                            if (time() - $startTime > $timeout) {
                                throw new Exception("接收数据超时");
                            }
                            usleep(10000);
                            continue;
                        }
                        
                        $dataReceived = true;
                        $startTime = time(); // 重置超时计时器
                        fwrite($fp, $data);
                    }
                    
                    // 恢复阻塞模式
                    socket_set_block($client);
                    
                    fclose($fp);
                    chmod($filepath, 0644);
                    
                    // 发送完成响应
                    $this->sendResponse($client, "226 文件传输完成\r\n");
                } catch (Exception $e) {
                    if (isset($fp)) {
                        fclose($fp);
                    }
                    @unlink($filepath);
                    socket_set_block($client);
                    $this->sendResponse($client, "550 文件上传失败: " . $e->getMessage() . "\r\n");
                }
                break;
                
            case 'RETR':
                if (!isset($this->clients[$clientId])) {
                    $this->sendResponse($client, "530 请先登录\r\n");
                    break;
                }
                $username = $this->clients[$clientId];
                
                if (!$this->checkPermission($username, 'read')) {
                    $this->sendResponse($client, "550 没有读取权限\r\n");
                    break;
                }
                
                $filename = isset($cmd[1]) ? $cmd[1] : '';
                if (empty($filename)) {
                    $this->sendResponse($client, "553 无效的文件名\r\n");
                    break;
                }
                
                $filepath = $this->users[$username]['root_dir'] . '/' . $filename;
                if (!file_exists($filepath) || !is_file($filepath)) {
                    $this->sendResponse($client, "550 文件不存在\r\n");
                    break;
                }
                
                $this->sendResponse($client, "150 开始传输文件\r\n");
                
                try {
                    $fp = fopen($filepath, 'rb');
                    if (!$fp) {
                        throw new Exception("无法打开文件");
                    }
                    
                    // 设置socket为非阻塞模式
                    socket_set_nonblock($client);
                    
                    while (!feof($fp)) {
                        $data = fread($fp, 8192);
                        $dataLength = strlen($data);
                        $sent = 0;
                        
                        // 循环发送直到所有数据都发送完成
                        while ($sent < $dataLength) {
                            $chunk = @socket_write($client, substr($data, $sent));
                            if ($chunk === false) {
                                $error = socket_last_error($client);
                                if ($error === EAGAIN || $error === EWOULDBLOCK) {
                                    usleep(1000);
                                    continue;
                                }
                                throw new Exception("发送数据失败: " . socket_strerror($error));
                            }
                            $sent += $chunk;
                        }
                        
                        // 每发送1MB数据休息1ms
                        if (ftell($fp) % (1024 * 1024) === 0) {
                            usleep(1000);
                        }
                    }
                    
                    fclose($fp);
                    
                    // 恢复阻塞模式并发送完成响应
                    socket_set_block($client);
                    $this->sendResponse($client, "226 文件传输完成\r\n");
                } catch (Exception $e) {
                    if (isset($fp)) {
                        fclose($fp);
                    }
                    socket_set_block($client);
                    $this->sendResponse($client, "550 文件传输失败: " . $e->getMessage() . "\r\n");
                }
                break;
                
            case 'DELE':
                if (!isset($this->clients[$clientId])) {
                    $this->sendResponse($client, "530 请先登录\r\n");
                    break;
                }
                $username = $this->clients[$clientId];
                
                if (!$this->checkPermission($username, 'delete')) {
                    $this->sendResponse($client, "550 没有删除权限\r\n");
                    break;
                }
                
                $filename = isset($cmd[1]) ? $cmd[1] : '';
                if (empty($filename)) {
                    $this->sendResponse($client, "550 未指定文件名\r\n");
                    break;
                }
                
                $filepath = $this->users[$username]['root_dir'] . '/' . $filename;
                
                // 检查文件是否存在且在用户目录下
                if (!file_exists($filepath) || !is_file($filepath)) {
                    $this->sendResponse($client, "550 文件不存在\r\n");
                    break;
                }
                
                // 检查文件是否在用户的根目录下
                if (!$this->checkPermission($username, 'admin') && 
                    strpos($filepath, $this->users[$username]['root_dir']) !== 0) {
                    $this->sendResponse($client, "550 没有权限删除此文件\r\n");
                    break;
                }
                
                if (@unlink($filepath)) {
                    $this->sendResponse($client, "250 文件删除成功\r\n");
                } else {
                    $this->sendResponse($client, "550 删除失败\r\n");
                }
                break;

            case 'RMD':
                if (!isset($this->clients[$clientId])) {
                    $this->sendResponse($client, "530 请先登录\r\n");
                    break;
                }
                $username = $this->clients[$clientId];
                
                if (!$this->checkPermission($username, 'delete')) {
                    $this->sendResponse($client, "550 没有删除权限\r\n");
                    break;
                }
                
                $dirname = isset($cmd[1]) ? $cmd[1] : '';
                if (empty($dirname)) {
                    $this->sendResponse($client, "550 未指定目录名\r\n");
                    break;
                }
                
                $dirpath = $this->users[$username]['root_dir'] . '/' . $dirname;
                
                // 检查目录是否存在且在用户目录下
                if (!is_dir($dirpath)) {
                    $this->sendResponse($client, "550 目录不存在\r\n");
                    break;
                }
                
                // 检查目录是否在用户的根目录下
                if (!$this->checkPermission($username, 'admin') && 
                    strpos($dirpath, $this->users[$username]['root_dir']) !== 0) {
                    $this->sendResponse($client, "550 没有权限删除此目录\r\n");
                    break;
                }
                
                // 递归删除目录
                if ($this->removeDirectory($dirpath)) {
                    $this->sendResponse($client, "250 目录删除成功\r\n");
                } else {
                    $this->sendResponse($client, "550 删除失败\r\n");
                }
                break;
                
            default:
                if (!empty($action)) {
                    $this->sendResponse($client, "500 未知命令\r\n");
                }
                break;
        }
    }
    
    private function getFileList($dir, $username, $currentPath = '') {
        $files = [];
        $items = scandir($dir);
        
        // 如果不是根目录，添加返回上级目录的选项
        if (!empty($currentPath)) {
            $parentPath = dirname($currentPath);
            if ($parentPath == '.') $parentPath = '';
            $files[] = [
                'name' => '..',
                'path' => $parentPath,
                'size' => '-',
                'raw_size' => 0,
                'mtime' => '-',
                'is_dir' => true,
                'type' => 'directory'
            ];
        }
        
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            
            $path = $dir . '/' . $item;
            $relativePath = $currentPath . '/' . $item;
            $relativePath = ltrim($relativePath, '/');
            
            if ($this->checkPermission($username, 'admin') || 
                strpos($path, $this->users[$username]['root_dir']) === 0) {
                
                $stat = stat($path);
                $size = $stat['size'];
                $sizeStr = $this->formatFileSize($size);
                
                $files[] = [
                    'name' => $item,
                    'path' => $relativePath,
                    'size' => $sizeStr,
                    'raw_size' => $size,
                    'mtime' => date('Y-m-d H:i:s', $stat['mtime']),
                    'is_dir' => is_dir($path),
                    'type' => is_dir($path) ? 'directory' : $this->getFileType($item)
                ];
            }
        }
        
        // 排序：目录在前，文件在后，按名称排序
        usort($files, function($a, $b) {
            if ($a['name'] === '..') return -1;
            if ($b['name'] === '..') return 1;
            if ($a['is_dir'] !== $b['is_dir']) {
                return $b['is_dir'] - $a['is_dir'];
            }
            return strcasecmp($a['name'], $b['name']);
        });
        
        return $files;
    }
    
    private function formatFileSize($size) {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        while ($size >= 1024 && $i < count($units) - 1) {
            $size /= 1024;
            $i++;
        }
        return round($size, 2) . ' ' . $units[$i];
    }
    
    private function getFileType($filename) {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $imageTypes = ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'webp'];
        $docTypes = ['doc', 'docx', 'pdf', 'txt', 'rtf', 'odt'];
        $archiveTypes = ['zip', 'rar', '7z', 'tar', 'gz'];
        
        if (in_array($ext, $imageTypes)) return 'image';
        if (in_array($ext, $docTypes)) return 'document';
        if (in_array($ext, $archiveTypes)) return 'archive';
        return 'file';
    }
    
    private function getRelativePath($path, $rootDir) {
        return str_replace($rootDir . '/', '', $path);
    }
    
    private function removeDirectory($dir) {
        if (!is_dir($dir)) {
            return false;
        }
        
        $files = array_diff(scandir($dir), array('.', '..'));
        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }
        return rmdir($dir);
    }
}

// 创建服务器实例
$server = new FTPServer();

// 注册关闭函数
register_shutdown_function(function() use ($server) {
    $server->cleanup();
});

// 运行服务器
$server->run(); 