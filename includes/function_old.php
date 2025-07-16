<?php
/**
 * SerteX+ - Funzioni di utilità generale
 */

// Previeni accesso diretto
if (!defined('ROOT_PATH')) {
    define('ROOT_PATH', dirname(__DIR__) . '/');
}

/**
 * Ottiene la connessione al database
 * @return PDO
 */
function getDatabase() {
    static $db = null;
    
    if ($db === null) {
        try {
            $dsn = "mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME . ";charset=utf8mb4";
            $db = new PDO($dsn, DB_USER, DB_PASS);
            $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            $db->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
        } catch (PDOException $e) {
            error_log("Errore connessione database: " . $e->getMessage());
            die("Errore di connessione al database. Contatta l'amministratore.");
        }
    }
    
    return $db;
}

/**
 * Registra un'attività nel log
 * @param int|null $userId
 * @param string $action
 * @param string $details
 */
function logActivity($userId, $action, $details = '') {
    try {
        $db = getDatabase();
        $stmt = $db->prepare("
            INSERT INTO log_attivita (utente_id, azione, dettagli, ip_address, user_agent) 
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $userId,
            $action,
            $details,
            $_SERVER['REMOTE_ADDR'] ?? null,
            $_SERVER['HTTP_USER_AGENT'] ?? null
        ]);
    } catch (Exception $e) {
        error_log("Errore log attività: " . $e->getMessage());
    }
}

/**
 * Verifica se l'utente è autenticato
 * @return bool
 */
function isAuthenticated() {
    return isset($_SESSION['user_id']) && isset($_SESSION['user_type']);
}

/**
 * Verifica se l'utente ha un determinato ruolo
 * @param string|array $roles
 * @return bool
 */
function hasRole($roles) {
    if (!isAuthenticated()) {
        return false;
    }
    
    if (!is_array($roles)) {
        $roles = [$roles];
    }
    
    return in_array($_SESSION['user_type'], $roles);
}

/**
 * Richiede autenticazione, altrimenti reindirizza al login
 * @param string|array|null $roles Ruoli richiesti (opzionale)
 */
function requireAuth($roles = null) {
    if (!isAuthenticated()) {
        $_SESSION['redirect_after_login'] = $_SERVER['REQUEST_URI'];
        header('Location: ' . ROOT_PATH . 'index.php');
        exit();
    }
    
    if ($roles !== null && !hasRole($roles)) {
        header('HTTP/1.0 403 Forbidden');
        die('Accesso negato. Non hai i permessi necessari per accedere a questa pagina.');
    }
    
    // Verifica timeout sessione
    if (isset($_SESSION['login_time'])) {
        $sessionAge = time() - $_SESSION['login_time'];
        if ($sessionAge > SESSION_LIFETIME) {
            logout();
            header('Location: ' . ROOT_PATH . 'index.php?timeout=1');
            exit();
        }
    }
}

/**
 * Effettua il logout
 */
function logout() {
    // Log attività
    if (isset($_SESSION['user_id'])) {
        logActivity($_SESSION['user_id'], 'logout', 'Logout effettuato');
    }
    
    // Elimina cookie remember me
    if (isset($_COOKIE['remember_token'])) {
        $token = $_COOKIE['remember_token'];
        $hashedToken = hash('sha256', $token);
        
        try {
            $db = getDatabase();
            $stmt = $db->prepare("DELETE FROM sessioni WHERE id = ?");
            $stmt->execute([$hashedToken]);
        } catch (Exception $e) {
            error_log("Errore eliminazione sessione: " . $e->getMessage());
        }
        
        setcookie('remember_token', '', time() - 3600, '/');
    }
    
    // Distruggi sessione
    $_SESSION = [];
    session_destroy();
}

/**
 * Genera un codice univoco per i test
 * @param string $prefix Prefisso opzionale
 * @return string
 */
function generateTestCode($prefix = 'T') {
    $date = date('ymd');
    $random = strtoupper(substr(md5(uniqid(mt_rand(), true)), 0, 6));
    return $prefix . $date . $random;
}

/**
 * Genera barcode per un codice
 * @param string $code
 * @return string Base64 del barcode
 */
function generateBarcode($code) {
    // TODO: Implementare generazione barcode con libreria appropriata
    return '';
}

/**
 * Genera QR code per un codice
 * @param string $code
 * @return string Base64 del QR code
 */
function generateQRCode($code) {
    // TODO: Implementare generazione QR code con bacon/bacon-qr-code
    return '';
}

/**
 * Formatta una data in formato italiano
 * @param string $date
 * @param string $format
 * @return string
 */
function formatDate($date, $format = 'd/m/Y') {
    if (empty($date)) {
        return '';
    }
    
    $timestamp = strtotime($date);
    if ($timestamp === false) {
        return $date;
    }
    
    return date($format, $timestamp);
}

/**
 * Formatta un importo in euro
 * @param float $amount
 * @param bool $showSymbol
 * @return string
 */
function formatCurrency($amount, $showSymbol = true) {
    $formatted = number_format($amount, 2, ',', '.');
    return $showSymbol ? '€ ' . $formatted : $formatted;
}

/**
 * Sanitizza input HTML
 * @param string $input
 * @return string
 */
function sanitizeInput($input) {
    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}

/**
 * Valida codice fiscale italiano
 * @param string $cf
 * @return bool
 */
function validateCodiceFiscale($cf) {
    $cf = strtoupper(trim($cf));
    
    if (strlen($cf) != 16) {
        return false;
    }
    
    if (!preg_match("/^[A-Z]{6}[0-9]{2}[A-Z][0-9]{2}[A-Z][0-9]{3}[A-Z]$/", $cf)) {
        return false;
    }
    
    // TODO: Implementare controllo completo del codice di controllo
    return true;
}

/**
 * Valida partita IVA italiana
 * @param string $piva
 * @return bool
 */
function validatePartitaIVA($piva) {
    $piva = trim($piva);
    
    if (strlen($piva) != 11) {
        return false;
    }
    
    if (!ctype_digit($piva)) {
        return false;
    }
    
    // Algoritmo di controllo partita IVA
    $sum = 0;
    for ($i = 0; $i < 11; $i++) {
        $digit = (int)$piva[$i];
        if ($i % 2 == 0) {
            $sum += $digit;
        } else {
            $double = $digit * 2;
            $sum += $double > 9 ? $double - 9 : $double;
        }
    }
    
    return $sum % 10 == 0;
}

/**
 * Valida email
 * @param string $email
 * @return bool
 */
function validateEmail($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

/**
 * Genera password casuale sicura
 * @param int $length
 * @return string
 */
function generatePassword($length = 12) {
    $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*()_+-=';
    $password = '';
    
    for ($i = 0; $i < $length; $i++) {
        $password .= $chars[random_int(0, strlen($chars) - 1)];
    }
    
    return $password;
}

/**
 * Cripta un file PDF con password
 * @param string $inputPath
 * @param string $outputPath
 * @param string $password
 * @return bool
 */
function encryptPDF($inputPath, $outputPath, $password) {
    // TODO: Implementare con TCPDF o altra libreria
    return true;
}

/**
 * Invia email
 * @param string $to
 * @param string $subject
 * @param string $body
 * @param array $attachments
 * @return bool
 */
function sendEmail($to, $subject, $body, $attachments = []) {
    // TODO: Implementare con PHPMailer
    return true;
}

/**
 * Ottiene le configurazioni dal database
 * @param string|null $key
 * @return mixed
 */
function getConfig($key = null) {
    static $config = null;
    
    if ($config === null) {
        try {
            $db = getDatabase();
            $stmt = $db->query("SELECT chiave, valore, tipo FROM configurazione");
            $config = [];
            
            while ($row = $stmt->fetch()) {
                $value = $row['valore'];
                
                // Converti al tipo appropriato
                switch ($row['tipo']) {
                    case 'integer':
                        $value = (int)$value;
                        break;
                    case 'boolean':
                        $value = (bool)$value;
                        break;
                    case 'float':
                        $value = (float)$value;
                        break;
                }
                
                $config[$row['chiave']] = $value;
            }
        } catch (Exception $e) {
            error_log("Errore caricamento configurazione: " . $e->getMessage());
            $config = [];
        }
    }
    
    if ($key === null) {
        return $config;
    }
    
    return $config[$key] ?? null;
}

/**
 * Salva una configurazione nel database
 * @param string $key
 * @param mixed $value
 * @param string $type
 * @return bool
 */
function setConfig($key, $value, $type = 'string') {
    try {
        $db = getDatabase();
        $stmt = $db->prepare("
            INSERT INTO configurazione (chiave, valore, tipo) 
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE valore = VALUES(valore), tipo = VALUES(tipo)
        ");
        return $stmt->execute([$key, $value, $type]);
    } catch (Exception $e) {
        error_log("Errore salvataggio configurazione: " . $e->getMessage());
        return false;
    }
}

/**
 * Crea backup del database
 * @param string $outputPath
 * @return bool
 */
function backupDatabase($outputPath = null) {
    if ($outputPath === null) {
        $outputPath = BACKUP_PATH . 'database/backup_' . date('Ymd_His') . '.sql';
    }
    
    // TODO: Implementare backup database
    return true;
}

/**
 * Upload file sicuro
 * @param array $file
 * @param string $destination
 * @param array $allowedTypes
 * @param int $maxSize
 * @return array
 */
function uploadFile($file, $destination, $allowedTypes = [], $maxSize = 10485760) {
    // Verifica errori
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['success' => false, 'error' => 'Errore durante l\'upload'];
    }
    
    // Verifica dimensione
    if ($file['size'] > $maxSize) {
        return ['success' => false, 'error' => 'File troppo grande'];
    }
    
    // Verifica tipo MIME
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    
    if (!empty($allowedTypes) && !in_array($mimeType, $allowedTypes)) {
        return ['success' => false, 'error' => 'Tipo file non permesso'];
    }
    
    // Genera nome file sicuro
    $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
    $filename = uniqid() . '_' . bin2hex(random_bytes(8)) . '.' . $extension;
    $filepath = $destination . $filename;
    
    // Sposta il file
    if (!move_uploaded_file($file['tmp_name'], $filepath)) {
        return ['success' => false, 'error' => 'Impossibile salvare il file'];
    }
    
    return [
        'success' => true,
        'filename' => $filename,
        'filepath' => $filepath,
        'mime_type' => $mimeType
    ];
}

/**
 * Genera hash sicuro per file
 * @param string $filepath
 * @return string
 */
function generateFileHash($filepath) {
    return hash_file('sha256', $filepath);
}

/**
 * Ottiene l'URL base del sito
 * @return string
 */
function getBaseUrl() {
    if (defined('SITE_URL') && SITE_URL) {
        return rtrim(SITE_URL, '/') . '/';
    }
    
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'];
    $path = dirname($_SERVER['SCRIPT_NAME']);
    
    return $protocol . '://' . $host . rtrim($path, '/') . '/';
}

/**
 * Redirect con messaggio
 * @param string $url
 * @param string $message
 * @param string $type
 */
function redirectWithMessage($url, $message, $type = 'success') {
    $_SESSION[$type . '_message'] = $message;
    header('Location: ' . $url);
    exit();
}