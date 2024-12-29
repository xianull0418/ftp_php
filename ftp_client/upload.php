<?php
session_start();
require_once 'client.php';
require_once 'FTPConnection.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['logged_in'])) {
    echo json_encode(['success' => false, 'message' => '未登录'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!isset($_FILES['file'])) {
    echo json_encode(['success' => false, 'message' => '没有选择文件'], JSON_UNESCAPED_UNICODE);
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
    
    $file = $_FILES['file'];
    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('文件上传失败: ' . $file['error']);
    }
    
    // 获取当前路径
    $currentPath = isset($_POST['path']) ? $_POST['path'] : '';
    $remoteFile = $currentPath . '/' . basename($file['name']);
    $remoteFile = ltrim($remoteFile, '/');
    
    // 上传文件
    $client->uploadFile($file['tmp_name'], $remoteFile);
    
    echo json_encode([
        'success' => true,
        'message' => '文件上传成功',
        'file' => $remoteFile
    ], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    error_log("上传文件错误: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => '上传失败: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
} 