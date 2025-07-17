<?php
// SerteX+ Configuration File
// Generated on 2025-07-17 09:49:50

// Database Configuration
define('DB_HOST', 'localhost');
define('DB_PORT', '3306');
define('DB_NAME', 'sertexplus');
define('DB_USER', 'root');
define('DB_PASS', '');

// System Configuration
define('SITE_NAME', 'SerteX+');
define('SITE_URL', 'http://127.0.0.1/');
define('ADMIN_EMAIL', 'pippo@gmail.com');
define('TIMEZONE', 'Europe/Rome');

// Security Configuration
define('ENCRYPTION_KEY', '269217d4dfdf37be2708c873fccca72fe7c59d941fd1e3bda55a79e1df89914b');
define('SESSION_KEY', 'edc7023b6a53305ce1a2af234ab1f3f32701fe69eebab77b10f17c4ed6182ee1');
define('SECURE_COOKIES', true);
#define('SESSION_LIFETIME', 3600);

// Path Configuration
define('ROOT_PATH', dirname(__FILE__) . '/../');
define('UPLOAD_PATH', ROOT_PATH . 'uploads/');
define('BACKUP_PATH', ROOT_PATH . 'backup/');
define('LOG_PATH', ROOT_PATH . 'logs/');
