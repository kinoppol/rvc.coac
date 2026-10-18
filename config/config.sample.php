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
    // RVC-ONE (SSO + user-sync API). Every key is optional: missing ones use the
    // values registered for this app (see src/Support/OaConfig.php).
    'oa' => [
        // 'authorize_url' => 'http://workspace.rvc.ac.th/oa/index.php',
        // 'verify_url'    => 'http://workspace.rvc.ac.th/oa/api/verify_token.php',
        // 'users_url'     => 'http://workspace.rvc.ac.th/oa/api/users.php',
        // 'client_id'     => 'cl_8edfa15f2a',
        // 'redirect_uri'  => 'https://coac.rvc.ac.th/web/auth/callback',
        'client_secret' => '', // or set env OA_CLIENT_SECRET (takes precedence). Never commit it.
        'sync_type' => '', // staff | student | all | '' (= staff + students, whatever RVC-ONE allows this app); the settings page overrides it
        'auto_provision' => false, // true = anyone signing in via RVC-ONE gets an account with default_role
        'default_role' => 'cashier',
    ],
];
