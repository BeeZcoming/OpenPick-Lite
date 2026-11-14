<?php
/** 复制为 config.php（已在 .gitignore，绝不入库）。
 *  生成密码哈希：php -r "echo password_hash('你的强密码', PASSWORD_DEFAULT), PHP_EOL;"
 */
return [
    'admins' => [
        'admin' => '$2y$10$REPLACE_WITH_YOUR_OWN_PASSWORD_HASH',
    ],
    'session_ttl' => 7200,

    // PHP CLI 路径。宝塔 PHP 8.3 一般是这个；用 ls /www/server/php/*/bin/php 确认。
    // 留空或填 'php' 则走 PATH。PHP-FPM 下 PHP_BINARY 指向 php-fpm，不能直接用。
    'php_bin' => '/www/server/php/83/bin/php',
];
