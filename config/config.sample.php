<?php
// This file is a template. install.php writes the real config to config/config.php.
return [
    'db' => [
        'host' => '127.0.0.1',
        'port' => '3306',
        'database' => 'rvc_coac',
        'username' => 'root',
        'password' => '',
        'charset' => 'utf8mb4',
    ],
    'app' => [
        'key' => '', // random secret, used to sign consignor portal tokens & CSRF
        'name' => 'ระบบฝากขาย ร้านค้าสหการ',
        'timezone' => 'Asia/Bangkok',
    ],
];
