<?php
session_start();
require_once 'client.php';
require_once 'FTPConnection.php';

header('Content-Type: application/json; charset=utf-8');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('无效的请求方法');
    }
    
    if (!isset($_POST['server']) || !isset($_POST['port']) || 
        !isset($_POST['username']) || !isset($_POST['password'])) {
        throw new Exception('缺少必要的登录参数');
    }
    
    $server = $_POST['server'];
    $port = (int)$_POST['port'];
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
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    error_log("FTP登录错误: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
} 