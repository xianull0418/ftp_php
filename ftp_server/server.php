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
    private $users = [
        'admin' => [
            'password' => 'admin123',
            'root_dir' => '/root/ftp_php'
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
            
            socket_set_option($client, SOL_SOCKET, SO_RCVTIMEO, array('sec' => 5, 'usec' => 0));
            socket_set_option($client, SOL_SOCKET, SO_SNDTIMEO, array('sec' => 5, 'usec' => 0));
            
            $this->sendResponse($client, "220 欢迎使用FTP服务器\r\n");
            
            while ($this->running) {
                $command = @socket_read($client, 1024, PHP_NORMAL_READ);
                if ($command === false) {
                    $error = socket_last_error($client);
                    if ($error === EAGAIN || $error === EWOULDBLOCK) {
                        usleep(100000); // 100ms
                        continue;
                    }
                    echo "读取命令失败: " . socket_strerror($error) . "\n";
                    break;
                }
                
                if ($command === '') {
                    echo "客户端断开连接: $address:$port\n";
                    break;
                }
                
                $command = trim($command);
                if (!empty($command)) {
                    echo "收到命令 ($address:$port): $command\n";
                    $this->handleCommand($client, $command);
                }
            }
        } catch (Exception $e) {
            echo "处理客户端时发生错误: " . $e->getMessage() . "\n";
        } finally {
            $this->cleanupClient($client);
            @socket_close($client);
        }
    }
    
    private function cleanupClient($client) {
        try {
            socket_getpeername($client, $address, $port);
            $clientKey = "$address:$port";
            
            if (isset($this->clientIds[$clientKey])) {
                $clientId = $this->clientIds[$clientKey];
                unset($this->clients[$clientId]);
                unset($this->clientIds[$clientKey]);
                echo "清理客户端: $address:$port\n";
            }
        } catch (Exception $e) {
            echo "清理客户端时发生错误: " . $e->getMessage() . "\n";
        }
    }
    
    private function sendResponse($client, $message) {
        socket_getpeername($client, $address, $port);
        echo "发送响应到 $address:$port: $message";
        @socket_write($client, $message, strlen($message));
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
                $rootDir = $this->users[$username]['root_dir'];
                
                if (!is_dir($rootDir)) {
                    mkdir($rootDir, 0755, true);
                }
                
                $files = scandir($rootDir);
                $fileList = implode("\n", array_filter($files, function($f) { return $f != '.' && $f != '..'; }));
                $this->sendResponse($client, "150 开始传输文件列表\r\n$fileList\r\n226 传输完成\r\n");
                break;
                
            default:
                if (!empty($action)) {
                    $this->sendResponse($client, "500 未知命令\r\n");
                }
                break;
        }
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