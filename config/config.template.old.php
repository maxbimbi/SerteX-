<?php
/**
 * SerteX+ - File di Configurazione Template
 * 
 * Questo file viene utilizzato durante l'installazione per generare config.php
 * NON modificare questo file direttamente, ma config.php dopo l'installazione
 */

// Configurazione Database
define('DB_HOST', '{{DB_HOST}}');
define('DB_NAME', '{{DB_NAME}}');
define('DB_USER', '{{DB_USER}}');
define('DB_PASS', '{{DB_PASS}}');
define('DB_CHARSET', 'utf8mb4');

// Configurazione Applicazione
define('APP_NAME', 'SerteX+');
define('APP_VERSION', '1.0.0');
define('APP_URL', '{{APP_URL}}');
define('APP_PATH', dirname(__DIR__));
define('APP_ENV', 'production'); // development, production

// Configurazione Sicurezza
define('ENCRYPTION_KEY', '{{ENCRYPTION_KEY}}'); // Generata durante installazione
define('SESSION_NAME', 'sertex_session');
define('SESSION_LIFETIME', 3600); // 1 ora
define('REMEMBER_ME_LIFETIME', 2592000); // 30 giorni
define('MAX_LOGIN_ATTEMPTS', 5);
define('LOCKOUT_TIME', 900); // 15 minuti
define('PASSWORD_MIN_LENGTH', 8);
define('PASSWORD_EXPIRY_DAYS', 90);
define('TWO_FACTOR_ENABLED', false);

// Configurazione Email
define('MAIL_ENABLED', true);
define('MAIL_HOST', '{{MAIL_HOST}}');
define('MAIL_PORT', 587);
define('MAIL_ENCRYPTION', 'tls'); // tls, ssl
define('MAIL_USERNAME', '{{MAIL_USERNAME}}');
define('MAIL_PASSWORD', '{{MAIL_PASSWORD}}');
define('MAIL_FROM_ADDRESS', '{{MAIL_FROM_ADDRESS}}');
define('MAIL_FROM_NAME', APP_NAME);

// Configurazione Upload
define('UPLOAD_PATH', APP_PATH . '/uploads');
define('UPLOAD_MAX_SIZE', 10485760); // 10MB
define('ALLOWED_FILE_TYPES', ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx']);

// Configurazione Referti
define('REFERTI_PATH', UPLOAD_PATH . '/referti');
define('REFERTI_EXPIRY_DAYS', 45);
define('REFERTI_ENCRYPTION_ENABLED', true);

// Configurazione Backup
define('BACKUP_PATH', APP_PATH . '/backup');
define('BACKUP_LOCAL_ENABLED', true);
define('BACKUP_CLOUD_ENABLED', false);
define('BACKUP_RETENTION_DAYS', 30);
define('BACKUP_SCHEDULE', 'daily'); // daily, weekly, monthly

// Configurazione Cloud Storage (opzionale)
define('CLOUD_PROVIDER', ''); // aws, google, azure
define('CLOUD_ACCESS_KEY', '');
define('CLOUD_SECRET_KEY', '');
define('CLOUD_BUCKET', '');
define('CLOUD_REGION', '');

// Configurazione Log
define('LOG_PATH', APP_PATH . '/logs');
define('LOG_LEVEL', 'info'); // debug, info, warning, error
define('LOG_ROTATION', 'daily'); // daily, weekly, monthly
define('LOG_MAX_FILES', 30);

// Configurazione Cache
define('CACHE_ENABLED', true);
define('CACHE_DRIVER', 'file'); // file, redis, memcached
define('CACHE_PATH', APP_PATH . '/temp/cache');
define('CACHE_LIFETIME', 3600);

// Configurazione API
define('API_ENABLED', true);
define('API_RATE_LIMIT', 60); // richieste per minuto
define('API_VERSION', 'v1');

// Configurazione PDF
define('PDF_GENERATOR', 'dompdf'); // dompdf, tcpdf, mpdf
define('PDF_PAPER_SIZE', 'A4');
define('PDF_ORIENTATION', 'portrait');
define('PDF_DPI', 96);

// Configurazione Fatturazione
define('INVOICE_PREFIX', date('Y') . '/');
define('INVOICE_START_NUMBER', 1);
define('VAT_RATE', 22); // IVA predefinita
define('ELECTRONIC_INVOICE_ENABLED', true);

// Configurazione Locale
define('LOCALE', 'it_IT');
define('TIMEZONE', 'Europe/Rome');
define('DATE_FORMAT', 'd/m/Y');
define('TIME_FORMAT', 'H:i');
define('CURRENCY', 'EUR');
define('CURRENCY_SYMBOL', '€');
define('DECIMAL_SEPARATOR', ',');
define('THOUSAND_SEPARATOR', '.');

// Configurazione Debug (disabilitare in produzione)
define('DEBUG', false);
define('SHOW_ERRORS', false);
define('LOG_QUERIES', false);

// Configurazione Manutenzione
define('MAINTENANCE_MODE', false);
define('MAINTENANCE_MESSAGE', 'Il sistema è in manutenzione. Riprova più tardi.');
define('MAINTENANCE_ALLOWED_IPS', []); // IP autorizzati durante manutenzione

// Path pubblici
define('ASSETS_URL', APP_URL . '/assets');
define('CSS_URL', ASSETS_URL . '/css');
define('JS_URL', ASSETS_URL . '/js');
define('IMG_URL', ASSETS_URL . '/images');

// Altre configurazioni
define('PAGINATION_LIMIT', 25);
define('SEARCH_MIN_LENGTH', 3);
define('AUTOCOMPLETE_LIMIT', 10);

// Non modificare oltre questa riga
date_default_timezone_set(TIMEZONE);
setlocale(LC_ALL, LOCALE . '.UTF-8');

// Verifica configurazione
if (!defined('DB_HOST') || DB_HOST === '{{DB_HOST}}') {
    die('Configurazione non completata. Eseguire install.php');
}