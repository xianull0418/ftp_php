<?php
session_start();
require_once 'FTPConnection.php';

FTPConnection::getInstance()->closeConnection();
session_destroy();
echo json_encode(['success' => true]); 