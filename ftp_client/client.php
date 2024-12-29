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
            if (empty($response)) {
                throw new Exception("服务器没有响应");
            }
            
            if (strpos($response, '331') !== 0) {
                throw new Exception("用户名验证失败: " . trim($response));
            }
            
            if (!$this->sendCommand("PASS $password")) {
                throw new Exception("发送密码命令失败");
            }
            
            $response = $this->readResponse();
            if (empty($response)) {
                throw new Exception("服务器没有响应");
            }
            
            if (strpos($response, '230') !== 0) {
                throw new Exception("密码验证失败: " . trim($response));
            }
            
            $this->loggedIn = true;
            return true;
        } catch (Exception $e) {
            error_log("FTP登录错误: " . $e->getMessage());
            throw $e;
        }
    }
    
    public function listFiles($path = '') {
        if (!$this->loggedIn) {
            throw new Exception("未登录");
        }
        
        // 发送LIST命令，如果有路径则包含路径
        $command = empty($path) ? "LIST" : "LIST " . $path;
        if (!$this->sendCommand($command)) {
            throw new Exception("发送LIST命令失败");
        }
        
        $response = $this->readResponse();
        if (strpos($response, '150') !== 0) {
            throw new Exception("获取文件列表失败: " . $response);
        }
        
        // 读取文件列表
        $files = [];
        $buffer = '';
        $startTime = time();
        $timeout = 30;
        
        while (true) {
            $data = @socket_read($this->socket, 8192, PHP_NORMAL_READ);
            if ($data === false) {
                $error = socket_last_error($this->socket);
                if ($error === EAGAIN || $error === EWOULDBLOCK) {
                    if (time() - $startTime > $timeout) {
                        throw new Exception("读取文件列表超时");
                    }
                    usleep(100000);
                    continue;
                }
                throw new Exception("读取文件列表失败: " . socket_strerror($error));
            }
            
            if ($data === '') {
                if (time() - $startTime > $timeout) {
                    throw new Exception("读取文件列表超时");
                }
                usleep(100000);
                continue;
            }
            
            $buffer .= $data;
            
            // 检查是否收到完整的响应
            if (strpos($buffer, "226") !== false) {
                break;
            }
        }
        
        // 解析文件列表
        $lines = explode("\r\n", $buffer);
        foreach ($lines as $line) {
            if (empty($line)) continue;
            if (strpos($line, '150') === 0) continue;
            if (strpos($line, '226') === 0) continue;
            
            try {
                $fileInfo = json_decode($line, true);
                if ($fileInfo !== null) {
                    $files[] = $fileInfo;
                }
            } catch (Exception $e) {
                error_log("解析文件信息失败: " . $line);
            }
        }
        
        // 等待最后的226响应（如果还没收到）
        if (strpos($buffer, "226") === false) {
            $completion = $this->readResponse();
            if (strpos($completion, '226') !== 0) {
                throw new Exception("文件列表传输未正常完成: " . $completion);
            }
        }
        
        return $files;
    }
    
    private function sendCommand($command) {
        error_log("发送FTP命令: $command");
        return @socket_write($this->socket, "$command\r\n") !== false;
    }
    
    private function readResponse() {
        $response = '';
        $startTime = time();
        $buffer = '';
        $shortTimeout = 5; // 缩短单次读取的超时时间
        
        while (true) {
            $chunk = @socket_read($this->socket, 8192, PHP_NORMAL_READ);
            if ($chunk === false) {
                $error = socket_last_error($this->socket);
                if ($error === EAGAIN || $error === EWOULDBLOCK) {
                    if (time() - $startTime > $shortTimeout) {
                        // 如果有部分响应，返回它
                        if (!empty($buffer) && preg_match('/^[123456]\d{2}.*\r\n$/s', $buffer)) {
                            return $buffer;
                        }
                        throw new Exception("读取响应超时");
                    }
                    usleep(10000); // 减少等待时间到10ms
                    continue;
                }
                throw new Exception("读取响应失败: " . socket_strerror($error));
            }
            
            if ($chunk === '') {
                if (time() - $startTime > $shortTimeout) {
                    if (!empty($buffer) && preg_match('/^[123456]\d{2}.*\r\n$/s', $buffer)) {
                        return $buffer;
                    }
                }
                usleep(10000);
                continue;
            }
            
            $buffer .= $chunk;
            
            // 检查是否收到完整的响应
            if (preg_match('/^[123456]\d{2}.*\r\n$/s', $buffer)) {
                return $buffer;
            }
        }
    }
    
    public function __destruct() {
        if ($this->socket) {
            @socket_close($this->socket);
        }
    }
    
    public function uploadFile($localFile, $remoteFile) {
        if (!$this->loggedIn) {
            throw new Exception("未登录");
        }
        
        if (!file_exists($localFile)) {
            throw new Exception("本地文件不存在");
        }
        
        // 发送STOR命令
        if (!$this->sendCommand("STOR " . $remoteFile)) {
            throw new Exception("发送STOR命令失败");
        }
        
        $response = $this->readResponse();
        if (strpos($response, '150') !== 0) {
            throw new Exception("服务器拒绝接收文件: " . $response);
        }
        
        try {
            // 发送文件数据
            $fp = fopen($localFile, 'rb');
            $fileSize = filesize($localFile);
            $totalSent = 0;
            $bufferSize = 65536; // 增加缓冲区大小到64KB
            
            // 设置socket为非阻塞模式
            socket_set_nonblock($this->socket);
            
            while (!feof($fp)) {
                $data = fread($fp, $bufferSize);
                $dataLength = strlen($data);
                $sent = 0;
                
                // 循环发送直到所有数据都发送完成
                while ($sent < $dataLength) {
                    $chunk = @socket_write($this->socket, substr($data, $sent));
                    if ($chunk === false) {
                        $error = socket_last_error($this->socket);
                        if ($error === EAGAIN || $error === EWOULDBLOCK) {
                            usleep(1000); // 等待1ms
                            continue;
                        }
                        fclose($fp);
                        socket_set_block($this->socket);
                        throw new Exception("发送文件数据失败: " . socket_strerror($error));
                    }
                    $sent += $chunk;
                    $totalSent += $chunk;
                }
                
                // 每发送1MB数据休息1ms，避免占用过多CPU
                if ($totalSent % (1024 * 1024) === 0) {
                    usleep(1000);
                }
            }
            fclose($fp);
            
            // 恢复阻塞模式
            socket_set_block($this->socket);
            
            // 等待服务器的完成响应
            $startTime = time();
            $timeout = 30;
            $responseReceived = false;
            
            while (!$responseReceived && (time() - $startTime < $timeout)) {
                try {
                    $response = $this->readResponse();
                    if (strpos($response, '226') === 0) {
                        $responseReceived = true;
                        break;
                    }
                } catch (Exception $e) {
                    // 如果是超时错误，继续等待
                    if (strpos($e->getMessage(), "timeout") !== false) {
                        usleep(100000); // 等待100ms
                        continue;
                    }
                    throw $e;
                }
            }
            
            if (!$responseReceived) {
                throw new Exception("文件传输可能已完成，但未收到服务器确认");
            }
            
            return true;
        } catch (Exception $e) {
            // 确保恢复阻塞模式
            socket_set_block($this->socket);
            throw new Exception("上传失败: " . $e->getMessage());
        }
    }
    
    public function downloadFile($remoteFile, $localFile) {
        if (!$this->loggedIn) {
            throw new Exception("未登录");
        }
        
        // 发送RETR命令
        if (!$this->sendCommand("RETR " . $remoteFile)) {
            throw new Exception("发送RETR命令失败");
        }
        
        $response = $this->readResponse();
        if (strpos($response, '150') !== 0) {
            throw new Exception("服务器拒绝发送文件: " . $response);
        }
        
        try {
            // 接收文件数据
            $fp = fopen($localFile, 'wb');
            if (!$fp) {
                throw new Exception("无法创建本地文件");
            }
            
            $startTime = time();
            $timeout = 10; // 减少超时时间
            $bufferSize = 65536; // 增加缓冲区大小到64KB
            $dataReceived = false;
            $lastDataTime = time();
            
            while (true) {
                $data = @socket_read($this->socket, $bufferSize, PHP_BINARY_READ);
                
                if ($data === false) {
                    $error = socket_last_error($this->socket);
                    if ($error === EAGAIN || $error === EWOULDBLOCK) {
                        // 检查是否已经接收完数据
                        if ($dataReceived && (time() - $lastDataTime > 2)) {
                            break;
                        }
                        // 检查总体超时
                        if (time() - $startTime > $timeout) {
                            throw new Exception("接收数据超时");
                        }
                        usleep(10000); // 10ms
                        continue;
                    }
                    throw new Exception("读取数据失败: " . socket_strerror($error));
                }
                
                if ($data === '') {
                    // 如果已经接收到数据并且超过2秒没有新数据，认为传输完成
                    if ($dataReceived && (time() - $lastDataTime > 2)) {
                        break;
                    }
                    // 检查总体超时
                    if (time() - $startTime > $timeout) {
                        throw new Exception("接收数据超时");
                    }
                    usleep(10000);
                    continue;
                }
                
                $dataReceived = true;
                $lastDataTime = time();
                fwrite($fp, $data);
            }
            
            fclose($fp);
            
            // 尝试读取完成响应，但不强制要求
            try {
                $response = $this->readResponse();
                if (strpos($response, '226') !== 0) {
                    error_log("警告：未收到预期的完成响应：" . $response);
                }
            } catch (Exception $e) {
                error_log("警告：读取完成响应失败：" . $e->getMessage());
            }
            
            return true;
        } catch (Exception $e) {
            if (isset($fp)) {
                fclose($fp);
            }
            @unlink($localFile);
            throw new Exception("下载失败: " . $e->getMessage());
        }
    }
} 