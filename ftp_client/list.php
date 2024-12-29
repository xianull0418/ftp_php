<?php
session_start();
require_once 'client.php';
require_once 'FTPConnection.php';

if (!isset($_SESSION['logged_in'])) {
    echo json_encode(['success' => false, 'message' => '未登录']);
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
    
    $files = $client->listFiles();
    echo json_encode(['success' => true, 'files' => $files]);
} catch (Exception $e) {
    if (strpos($e->getMessage(), '未登录') !== false) {
        session_destroy();
    }
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
} 