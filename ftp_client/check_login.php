<?php
session_start();
require_once 'FTPConnection.php';

if (isset($_SESSION['logged_in']) && $_SESSION['logged_in']) {
    try {
        $connection = FTPConnection::getInstance();
        $client = $connection->getClient(
            $_SESSION['server'],
            $_SESSION['port'],
            $_SESSION['username'],
            $_SESSION['password']
        );
        echo json_encode(['success' => true]);
    } catch (Exception $e) {
        session_destroy();
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
} else {
    echo json_encode(['success' => false, 'message' => '未登录']);
} 