<?php
class FTPConnection {
    private static $instance = null;
    private $client = null;
    private $currentServer = null;
    private $currentPort = null;
    private $currentUsername = null;
    
    private function __construct() {}
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function getClient($server, $port, $username, $password) {
        // 如果是新的连接参数，则创建新的连接
        if ($this->client === null || 
            $server !== $this->currentServer || 
            $port !== $this->currentPort || 
            $username !== $this->currentUsername) {
            
            // 关闭旧连接
            if ($this->client !== null) {
                $this->closeConnection();
            }
            
            try {
                $this->client = new FTPClient($server, $port);
                if (!$this->client->login($username, $password)) {
                    throw new Exception("用户名或密码错误");
                }
                
                $this->currentServer = $server;
                $this->currentPort = $port;
                $this->currentUsername = $username;
            } catch (Exception $e) {
                $this->client = null;
                throw new Exception("连接失败: " . $e->getMessage());
            }
        }
        
        return $this->client;
    }
    
    public function closeConnection() {
        if ($this->client !== null) {
            $this->client = null;
            $this->currentServer = null;
            $this->currentPort = null;
            $this->currentUsername = null;
        }
    }
} 