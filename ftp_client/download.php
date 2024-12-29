<?php
session_start();
require_once 'client.php';
require_once 'FTPConnection.php';

// 设置错误报告
error_reporting(E_ALL);
ini_set('display_errors', 1);

// 禁用输出缓冲
while (ob_get_level()) {
    ob_end_clean();
}

if (!isset($_SESSION['logged_in'])) {
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => '未登录'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!isset($_POST['path'])) {
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'message' => '未指定文件'], JSON_UNESCAPED_UNICODE);
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
    $filename = basename($path);
    
    // 创建临时文件
    $tempFile = tempnam(sys_get_temp_dir(), 'ftp_');
    
    // 下载文件
    if ($client->downloadFile($path, $tempFile)) {
        // 获取文件MIME类型
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $tempFile);
        finfo_close($finfo);
        
        // 获取文件大小
        $fileSize = filesize($tempFile);
        
        // 设置响应头
        header('Content-Type: ' . $mimeType);
        header('Content-Disposition: attachment; filename="' . rawurlencode($filename) . '"');
        header('Content-Length: ' . $fileSize);
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Cache-Control: post-check=0, pre-check=0', false);
        header('Pragma: no-cache');
        
        // 输出文件内容
        $fp = fopen($tempFile, 'rb');
        while (!feof($fp)) {
            echo fread($fp, 8192);
            flush();
        }
        fclose($fp);
        
        // 删除临时文件
        unlink($tempFile);
        exit;
    } else {
        throw new Exception("下载失败");
    }
} catch (Exception $e) {
    error_log("下载文件错误: " . $e->getMessage());
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => false,
        'message' => '下载失败: ' . $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
} 