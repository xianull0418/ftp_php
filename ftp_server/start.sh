#!/bin/bash

# 检查是否有旧进程
OLD_PID=$(sudo lsof -t -i:21)
if [ ! -z "$OLD_PID" ]; then
    echo "发现旧的FTP服务器进程 (PID: $OLD_PID)，正在关闭..."
    sudo kill $OLD_PID
    sleep 2
    
    # 如果进程仍然存在，强制关闭
    if ps -p $OLD_PID > /dev/null; then
        echo "强制关闭进程..."
        sudo kill -9 $OLD_PID
    fi
fi

# 启动新服务器
php server.php 