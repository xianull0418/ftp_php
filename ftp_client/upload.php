<?php
session_start();
require_once 'client.php';

if (!isset($_SESSION['logged_in'])) {
    die('请先登录');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['file'])) {
    $server = $_SESSION['server'];
    $port = $_SESSION['port'];
    
    try {
        $client = new FTPClient($server, $port);
        if ($client->uploadFile($_FILES['file']['tmp_name'])) {
            echo json_encode(['success' => true, 'message' => '文件上传成功']);
        } else {
            echo json_encode(['success' => false, 'message' => '文件上传失败']);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
} 