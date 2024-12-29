<?php
session_start();
require_once 'client.php';
require_once 'FTPConnection.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['logged_in'])) {
    echo json_encode(['success' => false, 'message' => '请先登录']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['file'])) {
    try {
        $connection = FTPConnection::getInstance();
        $client = $connection->getClient(
            $_SESSION['server'],
            $_SESSION['port'],
            $_SESSION['username'],
            $_SESSION['password']
        );
        
        $result = $client->uploadFile(
            $_FILES['file']['tmp_name'],
            $_FILES['file']['name']
        );
        
        echo json_encode([
            'success' => true,
            'message' => '文件上传成功'
        ], JSON_UNESCAPED_UNICODE);
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => $e->getMessage()
        ], JSON_UNESCAPED_UNICODE);
    }
} 