<?php
session_start();
require_once 'client.php';
require_once 'FTPConnection.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['logged_in'])) {
    echo json_encode(['success' => false, 'message' => '未登录'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $connection = FTPConnection::getInstance();
    $client = $connection->getClient(
        $_SESSION['server'],
        $_SESSION['port'],
        $_SESSION['username'],
        $_SESSION['password']
    );
    
    // 获取请求的路径
    $path = isset($_GET['path']) ? $_GET['path'] : '';
    
    // 发送LIST命令时包含路径
    $files = $client->listFiles($path);
    
    // 保存当前路径到会话
    $_SESSION['current_path'] = $path;
    
    echo json_encode([
        'success' => true, 
        'files' => $files,
        'username' => $_SESSION['username'],
        'current_path' => $path
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    error_log("获取文件列表错误: " . $e->getMessage());
    echo json_encode([
        'success' => false, 
        'message' => '获取文件列表失败: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
} 