<?php
/**
 * SerteX+ - Funzioni di sicurezza
 */

// Previeni accesso diretto
if (!defined('ROOT_PATH')) {
    define('ROOT_PATH', dirname(__DIR__) . '/');
}

// Costanti di sicurezza
if (!defined('MAX_LOGIN_ATTEMPTS')) {
    define('MAX_LOGIN_ATTEMPTS', 5);
}

if (!defined('PASSWORD_EXPIRY_DAYS')) {
    define('PASSWORD_EXPIRY_DAYS', 90);
}

if (!defined('SESSION_LIFETIME')) {
    define('SESSION_LIFETIME', 3600); // 1 ora
}

/**
 * Previene attacchi CSRF generando e verificando token
 * @return string
 */
function generateCSRFToken() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Verifica token CSRF
 * @param string $token
 * @return bool
 */
function verifyCSRFToken($token) {
    if (empty($_SESSION['csrf_token']) || empty($token)) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Input CSRF token per form
 * @return string
 */
function csrfInput() {
    return '<input type="hidden" name="csrf_token" value="' . generateCSRFToken() . '">';
}

/**
 * Verifica CSRF per richieste POST
 * @param bool $ajax
 */
function requireCSRF($ajax = false) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        
        if (!verifyCSRFToken($token)) {
            if ($ajax) {
                header('Content-Type: application/json');
                http_response_code(403);
                echo json_encode(['error' => 'Token CSRF non valido']);
            } else {
                die('Errore di sicurezza: Token CSRF non valido');
            }
            exit();
        }
    }
}

/**
 * Sanitizza input per prevenire XSS
 * @param mixed $data
 * @param bool $stripTags
 * @return mixed
 */
function sanitize($data, $stripTags = true) {
    if (is_array($data)) {
        return array_map(function($item) use ($stripTags) {
            return sanitize($item, $stripTags);
        }, $data);
    }
    
    $data = trim($data);
    
    if ($stripTags) {
        $data = strip_tags($data);
    }
    
    return htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
}

/**
 * Valida e sanitizza ID numerico
 * @param mixed $id
 * @return int|false
 */
function validateId($id) {
    $id = filter_var($id, FILTER_VALIDATE_INT);
    return $id !== false && $id > 0 ? $id : false;
}

/**
 * Genera salt sicuro
 * @param int $length
 * @return string
 */
function generateSalt($length = 16) {
    return bin2hex(random_bytes($length));
}

/**
 * Hash password con salt
 * @param string $password
 * @param string|null $salt
 * @return array
 */
function hashPassword($password, $salt = null) {
    if ($salt === null) {
        $salt = generateSalt();
    }
    
    $hash = password_hash($password . $salt, PASSWORD_BCRYPT, ['cost' => 12]);
    
    return [
        'hash' => $hash,
        'salt' => $salt
    ];
}

/**
 * Verifica password con salt
 * @param string $password
 * @param string $hash
 * @param string $salt
 * @return bool
 */
function verifyPassword($password, $hash, $salt) {
    return password_verify($password . $salt, $hash);
}

/**
 * Cripta dati sensibili
 * @param string $data
 * @param string|null $key
 * @return string
 */
function encrypt($data, $key = null) {
    if ($key === null) {
        $key = ENCRYPTION_KEY;
    }
    
    $iv = random_bytes(16);
    $encrypted = openssl_encrypt($data, 'AES-256-CBC', $key, 0, $iv);
    
    return base64_encode($iv . $encrypted);
}

/**
 * Decripta dati
 * @param string $data
 * @param string|null $key
 * @return string|false
 */
function decrypt($data, $key = null) {
    if ($key === null) {
        $key = ENCRYPTION_KEY;
    }
    
    $data = base64_decode($data);
    $iv = substr($data, 0, 16);
    $encrypted = substr($data, 16);
    
    return openssl_decrypt($encrypted, 'AES-256-CBC', $key, 0, $iv);
}

/**
 * Genera token sicuro per reset password, API, ecc.
 * @param int $length
 * @return string
 */
function generateSecureToken($length = 32) {
    return bin2hex(random_bytes($length));
}

/**
 * Verifica forza password
 * @param string $password
 * @return array
 */
function checkPasswordStrength($password) {
    $strength = 0;
    $feedback = [];
    
    if (strlen($password) < 8) {
        $feedback[] = 'La password deve essere di almeno 8 caratteri';
    } else {
        $strength++;
    }
    
    if (!preg_match('/[a-z]/', $password)) {
        $feedback[] = 'Deve contenere almeno una lettera minuscola';
    } else {
        $strength++;
    }
    
    if (!preg_match('/[A-Z]/', $password)) {
        $feedback[] = 'Deve contenere almeno una lettera maiuscola';
    } else {
        $strength++;
    }
    
    if (!preg_match('/[0-9]/', $password)) {
        $feedback[] = 'Deve contenere almeno un numero';
    } else {
        $strength++;
    }
    
    if (!preg_match('/[^a-zA-Z0-9]/', $password)) {
        $feedback[] = 'Deve contenere almeno un carattere speciale';
    } else {
        $strength++;
    }
    
    return [
        'strength' => $strength,
        'score' => ($strength / 5) * 100,
        'feedback' => $feedback,
        'valid' => empty($feedback)
    ];
}

/**
 * Rate limiting per prevenire brute force
 * @param string $action
 * @param string $identifier
 * @param int $maxAttempts
 * @param int $window
 * @return bool
 */
function checkRateLimit($action, $identifier, $maxAttempts = 5, $window = 300) {
    $key = 'rate_limit_' . $action . '_' . $identifier;
    $attempts = $_SESSION[$key] ?? [];
    
    // Rimuovi tentativi vecchi
    $cutoff = time() - $window;
    $attempts = array_filter($attempts, function($timestamp) use ($cutoff) {
        return $timestamp > $cutoff;
    });
    
    if (count($attempts) >= $maxAttempts) {
        return false;
    }
    
    $attempts[] = time();
    $_SESSION[$key] = $attempts;
    
    return true;
}

/**
 * Verifica se l'IP è nella whitelist
 * @param string|null $ip
 * @return bool
 */
function isIPWhitelisted($ip = null) {
    if ($ip === null) {
        $ip = $_SERVER['REMOTE_ADDR'];
    }
    
    $whitelist = [
        '127.0.0.1',
        '::1', // IPv6 localhost
    ];
    
    // Carica whitelist dal database se disponibile
    try {
        $db = getDatabase();
        $stmt = $db->query("SELECT valore FROM configurazione WHERE chiave = 'ip_whitelist'");
        $dbWhitelist = $stmt->fetchColumn();
        
        if ($dbWhitelist) {
            $whitelist = array_merge($whitelist, explode(',', $dbWhitelist));
        }
    } catch (Exception $e) {
        // Ignora errori
    }
    
    return in_array($ip, $whitelist);
}

/**
 * Verifica se l'IP è nella blacklist
 * @param string|null $ip
 * @return bool
 */
function isIPBlacklisted($ip = null) {
    if ($ip === null) {
        $ip = $_SERVER['REMOTE_ADDR'];
    }
    
    try {
        $db = getDatabase();
        $stmt = $db->prepare("
            SELECT COUNT(*) FROM log_attivita 
            WHERE ip_address = ? 
            AND azione LIKE 'login_failed%' 
            AND timestamp > DATE_SUB(NOW(), INTERVAL 1 HOUR)
        ");
        $stmt->execute([$ip]);
        
        $failedAttempts = $stmt->fetchColumn();
        
        // Blocca IP dopo 20 tentativi falliti in un'ora
        return $failedAttempts > 20;
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Genera codice 2FA
 * @return string
 */
function generate2FACode() {
    return sprintf('%06d', random_int(0, 999999));
}

/**
 * Genera secret per Google Authenticator
 * @return string
 */
function generate2FASecret() {
    $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $secret = '';
    
    for ($i = 0; $i < 16; $i++) {
        $secret .= $chars[random_int(0, 31)];
    }
    
    return $secret;
}

/**
 * Headers di sicurezza
 */
function setSecurityHeaders() {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('X-XSS-Protection: 1; mode=block');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
    
    if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}

/**
 * Previeni clickjacking
 * @param string $origin
 */
function preventClickjacking($origin = 'SAMEORIGIN') {
    header("X-Frame-Options: $origin");
    header("Content-Security-Policy: frame-ancestors 'self'");
}

/**
 * Valida upload file in modo sicuro
 * @param array $file
 * @param array $config
 * @return array
 */
function validateFileUpload($file, $config = []) {
    $defaults = [
        'max_size' => 10 * 1024 * 1024, // 10MB
        'allowed_types' => ['pdf', 'jpg', 'jpeg', 'png'],
        'allowed_mimes' => [
            'application/pdf',
            'image/jpeg',
            'image/png'
        ]
    ];
    
    $config = array_merge($defaults, $config);
    $errors = [];
    
    // Verifica errori upload
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $errors[] = 'Errore durante l\'upload del file';
        return ['valid' => false, 'errors' => $errors];
    }
    
    // Verifica dimensione
    if ($file['size'] > $config['max_size']) {
        $errors[] = 'Il file supera la dimensione massima consentita';
    }
    
    // Verifica estensione
    $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($extension, $config['allowed_types'])) {
        $errors[] = 'Tipo di file non consentito';
    }
    
    // Verifica MIME type reale
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    
    if (!in_array($mimeType, $config['allowed_mimes'])) {
        $errors[] = 'Il contenuto del file non corrisponde all\'estensione';
    }
    
    // Verifica contenuto per immagini
    if (in_array($extension, ['jpg', 'jpeg', 'png'])) {
        $imageInfo = getimagesize($file['tmp_name']);
        if ($imageInfo === false) {
            $errors[] = 'Il file non è un\'immagine valida';
        }
    }
    
    return [
        'valid' => empty($errors),
        'errors' => $errors,
        'extension' => $extension,
        'mime_type' => $mimeType
    ];
}

/**
 * Genera nome file sicuro
 * @param string $originalName
 * @return string
 */
function generateSafeFilename($originalName) {
    $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $basename = pathinfo($originalName, PATHINFO_FILENAME);
    
    // Rimuovi caratteri non sicuri
    $basename = preg_replace('/[^a-zA-Z0-9_-]/', '', $basename);
    $basename = substr($basename, 0, 50); // Limita lunghezza
    
    // Genera nome univoco
    $uniqueId = uniqid() . '_' . bin2hex(random_bytes(4));
    
    return $uniqueId . '_' . $basename . '.' . $extension;
}

/**
 * Proteggi contro SQL injection (per query dinamiche)
 * @param string $value
 * @return string
 */
function escapeSql($value) {
    // Preferire sempre prepared statements!
    // Questa funzione è solo per casi eccezionali
    return addslashes($value);
}

/**
 * Registra tentativo di sicurezza sospetto
 * @param string $type
 * @param string $details
 */
function logSecurityEvent($type, $details) {
    $userId = $_SESSION['user_id'] ?? null;
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
    
    $logEntry = sprintf(
        "[%s] Security Event: %s | User: %s | IP: %s | UA: %s | Details: %s\n",
        date('Y-m-d H:i:s'),
        $type,
        $userId ?: 'anonymous',
        $ip,
        $userAgent,
        $details
    );
    
    error_log($logEntry, 3, LOG_PATH . 'security.log');
    
    // Log anche nel database
    try {
        logActivity($userId, 'security_' . $type, $details);
    } catch (Exception $e) {
        // Ignora errori di log
    }
}