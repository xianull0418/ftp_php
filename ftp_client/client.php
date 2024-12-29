<?php
if (!extension_loaded('sockets')) {
    die('PHP sockets 扩展未安装。请安装 PHP sockets 扩展后再试。');
}

class FTPClient {
    private $socket;
    private $loggedIn = false;
    private $timeout = 30;
    
    public function __construct($host, $port = 21) {
        $this->socket = @socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        if ($this->socket === false) {
            throw new Exception("无法创建socket: " . socket_strerror(socket_last_error()));
        }
        
        socket_set_option($this->socket, SOL_SOCKET, SO_RCVTIMEO, array('sec' => $this->timeout, 'usec' => 0));
        socket_set_option($this->socket, SOL_SOCKET, SO_SNDTIMEO, array('sec' => $this->timeout, 'usec' => 0));
        
        socket_set_option($this->socket, SOL_SOCKET, SO_KEEPALIVE, 1);
        
        if (@socket_connect($this->socket, $host, $port) === false) {
            throw new Exception("无法连接到服务器 $host:$port: " . socket_strerror(socket_last_error()));
        }
        
        $response = $this->readResponse();
        if (!$response || strpos($response, '220') !== 0) {
            throw new Exception("连接失败: " . $response);
        }
    }
    
    public function login($username, $password) {
        try {
            if (!$this->sendCommand("USER $username")) {
                throw new Exception("发送用户名命令失败");
            }
            
            $response = $this->readResponse();
            if (!$response) {
                throw new Exception("服务器没有响应");
            }
            if (strpos($response, '331') !== 0) {
                throw new Exception("用户名验证失败: " . $response);
            }
            
            if (!$this->sendCommand("PASS $password")) {
                throw new Exception("发送密码命令失败");
            }
            
            $response = $this->readResponse();
            if (!$response) {
                throw new Exception("服务器没有响应");
            }
            if (strpos($response, '230') !== 0) {
                throw new Exception("密码验证失败: " . $response);
            }
            
            $this->loggedIn = true;
            return true;
        } catch (Exception $e) {
            error_log("FTP登录错误: " . $e->getMessage());
            throw $e;
        }
    }
    
    public function listFiles() {
        if (!$this->loggedIn) {
            throw new Exception("未登录");
        }
        
        if (!$this->sendCommand("LIST")) {
            throw new Exception("发送LIST命令失败");
        }
        
        $response = $this->readResponse();
        if (strpos($response, '150') === 0) {
            $lines = explode("\n", $response);
            array_shift($lines); // 移除第一行（150响应）
            array_pop($lines);   // 移除最后一行（226响应）
            return array_filter($lines); // 返回文件列表
        }
        
        throw new Exception("获取文件列表失败: " . $response);
    }
    
    private function sendCommand($command) {
        error_log("发送FTP命令: $command");
        return @socket_write($this->socket, "$command\r\n") !== false;
    }
    
    private function readResponse() {
        $response = '';
        $startTime = time();
        
        while (true) {
            $line = @socket_read($this->socket, 1024, PHP_NORMAL_READ);
            if ($line === false) {
                $error = socket_last_error($this->socket);
                throw new Exception("读取响应失败: " . socket_strerror($error));
            }
            
            if ($line === '') {
                if (time() - $startTime > $this->timeout) {
                    throw new Exception("读取响应超时");
                }
                usleep(100000); // 等待100ms
                continue;
            }
            
            error_log("收到FTP响应: $line");
            $response .= $line;
            
            if (preg_match('/^[123456]\d{2}.*\r\n$/', $line)) {
                break;
            }
        }
        
        return $response;
    }
    
    public function __destruct() {
        if ($this->socket) {
            @socket_close($this->socket);
        }
    }
} 