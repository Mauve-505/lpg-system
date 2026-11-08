<?php
// Test-only configuration. Uses a disposable database (lpg_test).
return [
    'env' => 'test',
    'debug' => true,
    'timezone' => 'Asia/Manila',
    'db' => [
        'host' => '127.0.0.1',
        'port' => 3306,
        'name' => 'lpg_test',
        'user' => 'root',
        'pass' => '',
        'charset' => 'utf8mb4',
    ],
    'session' => [
        'name' => 'lpg_test_session',
        'lifetime' => 7200,
        'secure' => false,
        'samesite' => 'Lax',
    ],
    'session_secret' => 'test-secret-not-for-production',
    'security' => [
        'csrf' => true,
        'password_min_length' => 8,
        'login_max_attempts' => 5,
        'login_max_user_attempts' => 10,
        'ip_max_attempts' => 30,
        'login_window_minutes' => 15,
        'lockout_minutes' => 15,
        'signup_max_attempts' => 5,
        'max_body_bytes' => 1048576,
    ],
    'bootstrap' => [
        'admin_username' => 'admin',
        'admin_password' => 'admin123',
    ],
    'otp' => [
        'provider' => '',
        'ttl_minutes' => 10,
        'max_attempts' => 5,
    ],
];
