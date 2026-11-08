<?php
// Copy this file to config.php. Never commit config.php.
// Every value can also be overridden by an environment variable shown below.
return [
    'env' => getenv('LPG_ENV') ?: 'production',
    'debug' => filter_var(getenv('LPG_DEBUG') ?: 'false', FILTER_VALIDATE_BOOLEAN),
    'timezone' => getenv('LPG_TIMEZONE') ?: 'Asia/Manila',

    'db' => [
        'host' => getenv('LPG_DB_HOST') ?: '127.0.0.1',
        'port' => (int) (getenv('LPG_DB_PORT') ?: 3306),
        'name' => getenv('LPG_DB_NAME') ?: 'lpg_db',
        'user' => getenv('LPG_DB_USER') ?: 'root',
        'pass' => getenv('LPG_DB_PASS') ?: '',
        'charset' => 'utf8mb4',
        // Optional absolute path for database/migrate.php backups.
        'mysqldump_path' => getenv('LPG_MYSQLDUMP_PATH') ?: '',
    ],

    'session' => [
        'name' => getenv('LPG_SESSION_NAME') ?: 'lpg_session',
        'lifetime' => (int) (getenv('LPG_SESSION_LIFETIME') ?: 7200),
        'secure' => getenv('LPG_SESSION_SECURE') === false
            ? null
            : filter_var(getenv('LPG_SESSION_SECURE'), FILTER_VALIDATE_BOOLEAN),
        'samesite' => getenv('LPG_SESSION_SAMESITE') ?: 'Lax',
    ],

    // Generate with: php -r "echo bin2hex(random_bytes(32));"
    'session_secret' => getenv('LPG_SESSION_SECRET') ?: '',

    'security' => [
        'csrf' => true,
        'password_min_length' => 8,
        'login_max_attempts' => 5,
        'login_max_user_attempts' => (int) (getenv('LPG_LOGIN_MAX_USER_ATTEMPTS') ?: 10),
        'ip_max_attempts' => 30,
        'login_window_minutes' => 15,
        'lockout_minutes' => 15,
        'signup_max_attempts' => 5,
        'max_body_bytes' => 1048576,
    ],

    'bootstrap' => [
        'admin_username' => getenv('LPG_ADMIN_USERNAME') ?: 'admin',
        'admin_password' => getenv('LPG_ADMIN_PASSWORD') ?: 'admin123',
    ],

    'otp' => [
        'provider' => getenv('LPG_OTP_PROVIDER') ?: '',
        'ttl_minutes' => 10,
        'max_attempts' => 5,
    ],
];
