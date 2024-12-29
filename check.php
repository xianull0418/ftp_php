<?php
echo "已安装的扩展：\n";
print_r(get_loaded_extensions());

echo "\n是否安装 mbstring：";
echo extension_loaded('mbstring') ? "是" : "否"; 