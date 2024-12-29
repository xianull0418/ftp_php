<?php
session_start();
require_once 'client.php';
require_once 'FTPConnection.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!isset($_POST['server']) || !isset($_POST['port']) || 
            !isset($_POST['username']) || !isset($_POST['password'])) {
            throw new Exception('缺少必要的登录参数');
        }

        $server = $_POST['server'];
        $port = $_POST['port'];
        $username = $_POST['username'];
        $password = $_POST['password'];
        
        $connection = FTPConnection::getInstance();
        $client = $connection->getClient($server, $port, $username, $password);
        
        $_SESSION['logged_in'] = true;
        $_SESSION['server'] = $server;
        $_SESSION['port'] = $port;
        $_SESSION['username'] = $username;
        $_SESSION['password'] = $password;
        
        echo json_encode([
            'success' => true,
            'message' => '登录成功'
        ]);
    } catch (Exception $e) {
        error_log("FTP登录错误: " . $e->getMessage());
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }
} else {
    echo json_encode([
        'success' => false,
        'message' => '无效的请求方法'
    ]);
} 