<?php
session_start();
require_once 'client.php';
require_once 'FTPConnection.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['logged_in'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => '未登录'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!isset($_POST['path'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => '未指定路径'], JSON_UNESCAPED_UNICODE);
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
    
    $path = $_POST['path'];
    
    if ($client->deleteFile($path)) {
        echo json_encode([
            'success' => true,
            'message' => '删除成功'
        ], JSON_UNESCAPED_UNICODE);
    } else {
        throw new Exception("删除失败");
    }
} catch (Exception $e) {
    error_log("删除文件错误: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => '删除失败: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
} 