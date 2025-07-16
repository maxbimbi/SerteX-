<?php
/**
 * Funzioni helper per SerteX+
 */

/**
 * Redirect a una URL
 */
function redirect($url) {
    header("Location: $url");
    exit();
}

/**
 * Sanitizza input utente
 */
function sanitizeInput($data) {
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data);
    return $data;
}

/**
 * Genera token CSRF
 */
function generateCSRFToken() {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Verifica token CSRF
 */
function verifyCSRFToken($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Log attività nel database
 */
function logActivity($db, $user_id, $action, $details = null) {
    try {
        $stmt = $db->prepare("
            INSERT INTO log_attivita (utente_id, azione, dettagli, ip_address, user_agent)
            VALUES (?, ?, ?, ?, ?)
        ");
        
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? null;
        
        $stmt->execute([$user_id, $action, $details, $ip, $user_agent]);
        return true;
    } catch (Exception $e) {
        error_log("Errore log attività: " . $e->getMessage());
        return false;
    }
}

/**
 * Genera password casuale
 */
function generateRandomPassword($length = 12) {
    $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*()';
    $password = '';
    for ($i = 0; $i < $length; $i++) {
        $password .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return $password;
}

/**
 * Formatta data italiana
 */
function formatDateIta($date, $format = 'd/m/Y') {
    if (empty($date)) return '';
    return date($format, strtotime($date));
}

/**
 * Genera token sicuro
 */
function generateSecureToken($length = 32) {
    return bin2hex(random_bytes($length));
}

/**
 * Genera codice test univoco
 */
function generateTestCode($db) {
    $prefix = 'TEST' . date('Ymd');
    
    // Trova l'ultimo codice per oggi
    $stmt = $db->prepare("SELECT codice FROM test WHERE codice LIKE ? ORDER BY codice DESC LIMIT 1");
    $stmt->execute([$prefix . '%']);
    $lastCode = $stmt->fetchColumn();
    
    if ($lastCode) {
        $lastNumber = intval(substr($lastCode, -4));
        $newNumber = $lastNumber + 1;
    } else {
        $newNumber = 1;
    }
    
    return $prefix . str_pad($newNumber, 4, '0', STR_PAD_LEFT);
}

/**
 * Genera barcode
 */
function generateBarcode($code) {
    // In produzione, qui si genererebbe un vero barcode
    // Per ora restituiamo una stringa placeholder
    return 'BARCODE_' . $code;
}

/**
 * Genera QR code
 */
function generateQRCode($code) {
    // In produzione, qui si genererebbe un vero QR code
    // Per ora restituiamo una stringa placeholder
    return 'QRCODE_' . $code;
}

/**
 * Ottieni ID professionista dall'ID utente
 */
function getProfessionistaId($db, $user_id) {
    $stmt = $db->prepare("SELECT id FROM professionisti WHERE utente_id = ?");
    $stmt->execute([$user_id]);
    return $stmt->fetchColumn();
}

/**
 * Pseudonimizza nome/cognome per biologo
 */
function pseudonymize($text, $type = 'name') {
    if (empty($text)) return '';
    
    $length = strlen($text);
    if ($length <= 2) {
        return str_repeat('*', $length);
    }
    
    // Mostra solo prima e ultima lettera
    return substr($text, 0, 1) . str_repeat('*', $length - 2) . substr($text, -1);
}

/**
 * Calcola hash SHA-256 di un file
 */
function calculateFileHash($filepath) {
    if (!file_exists($filepath)) {
        return null;
    }
    return hash_file('sha256', $filepath);
}

/**
 * Genera nome file sicuro
 */
function generateSecureFilename($originalName, $prefix = '') {
    $extension = pathinfo($originalName, PATHINFO_EXTENSION);
    $timestamp = time();
    $random = bin2hex(random_bytes(8));
    
    return $prefix . $timestamp . '_' . $random . '.' . $extension;
}

/**
 * Verifica e crea directory se non esiste
 */
function ensureDirectory($path) {
    if (!is_dir($path)) {
        mkdir($path, 0755, true);
    }
    
    // Aggiungi .htaccess per protezione
    $htaccess = $path . '/.htaccess';
    if (!file_exists($htaccess)) {
        file_put_contents($htaccess, "Deny from all");
    }
}

/**
 * Formatta prezzo
 */
function formatPrice($price) {
    return number_format($price, 2, ',', '.') . ' €';
}

/**
 * Calcola prezzo con IVA
 */
function calculatePriceWithVAT($price, $vat = 22) {
    return $price * (1 + $vat / 100);
}

/**
 * Invia email
 */
function sendEmail($to, $subject, $body, $attachments = []) {
    // In produzione qui si userebbe PHPMailer o simile
    // Per ora registriamo solo nel log
    error_log("Email inviata a: $to, Oggetto: $subject");
    return true;
}

/**
 * Genera numero fattura
 */
function generateInvoiceNumber($db, $year = null) {
    if (!$year) $year = date('Y');
    
    $stmt = $db->prepare("SELECT numero FROM fatture WHERE YEAR(data_emissione) = ? ORDER BY numero DESC LIMIT 1");
    $stmt->execute([$year]);
    $lastNumber = $stmt->fetchColumn();
    
    if ($lastNumber) {
        $number = intval(substr($lastNumber, -4)) + 1;
    } else {
        $number = 1;
    }
    
    return $year . '/' . str_pad($number, 4, '0', STR_PAD_LEFT);
}

/**
 * Verifica se un referto è scaduto
 */
function isReportExpired($creationDate, $expiryDays = 45) {
    $created = new DateTime($creationDate);
    $now = new DateTime();
    $diff = $now->diff($created);
    
    return $diff->days > $expiryDays;
}

/**
 * Ottieni configurazione
 */
function getConfig($db, $key, $default = null) {
    $stmt = $db->prepare("SELECT valore FROM configurazione WHERE chiave = ?");
    $stmt->execute([$key]);
    $value = $stmt->fetchColumn();
    
    return $value !== false ? $value : $default;
}

/**
 * Salva configurazione
 */
function setConfig($db, $key, $value, $type = 'string') {
    $stmt = $db->prepare("INSERT INTO configurazione (chiave, valore, tipo) VALUES (?, ?, ?) 
                         ON DUPLICATE KEY UPDATE valore = VALUES(valore), tipo = VALUES(tipo)");
    return $stmt->execute([$key, $value, $type]);
}

/**
 * Formatta dimensione file
 */
function formatFileSize($bytes) {
    if ($bytes === 0) return '0 Bytes';
    
    $k = 1024;
    $sizes = ['Bytes', 'KB', 'MB', 'GB'];
    $i = floor(log($bytes) / log($k));
    
    return round($bytes / pow($k, $i), 2) . ' ' . $sizes[$i];
}

/**
 * Valida codice fiscale italiano
 */
function validateCodiceFiscale($cf) {
    if (strlen($cf) != 16) return false;
    
    $cf = strtoupper($cf);
    if (!preg_match("/^[A-Z]{6}[0-9]{2}[A-Z][0-9]{2}[A-Z][0-9]{3}[A-Z]$/", $cf)) {
        return false;
    }
    
    // Ulteriori controlli potrebbero essere aggiunti qui
    return true;
}

/**
 * Valida email
 */
function validateEmail($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

/**
 * Valida partita IVA italiana
 */
function validatePartitaIVA($piva) {
    if (strlen($piva) != 11) return false;
    if (!ctype_digit($piva)) return false;
    
    // Algoritmo di controllo partita IVA
    $sum = 0;
    for ($i = 0; $i < 10; $i++) {
        $n = $piva[$i];
        if ($i % 2 == 0) {
            $sum += $n;
        } else {
            $double = $n * 2;
            $sum += $double > 9 ? $double - 9 : $double;
        }
    }
    
    $checkDigit = (10 - ($sum % 10)) % 10;
    return $checkDigit == $piva[10];
}

/**
 * Genera codice di verifica per 2FA
 */
function generate2FACode() {
    return str_pad(random_int(0, 999999), 6, '0', STR_PAD_LEFT);
}

/**
 * Cripta stringa
 */
function encryptString($string, $key = null) {
    if (!$key) $key = ENCRYPTION_KEY;
    
    $ivLength = openssl_cipher_iv_length('AES-256-CBC');
    $iv = openssl_random_pseudo_bytes($ivLength);
    $encrypted = openssl_encrypt($string, 'AES-256-CBC', $key, 0, $iv);
    
    return base64_encode($encrypted . '::' . $iv);
}

/**
 * Decripta stringa
 */
function decryptString($encrypted, $key = null) {
    if (!$key) $key = ENCRYPTION_KEY;
    
    list($encrypted_data, $iv) = explode('::', base64_decode($encrypted), 2);
    return openssl_decrypt($encrypted_data, 'AES-256-CBC', $key, 0, $iv);
}

/**
 * Ottieni tipo MIME sicuro
 */
function getSecureMimeType($filename) {
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $filename);
    finfo_close($finfo);
    return $mime;
}

/**
 * Controlla se l'utente ha il permesso
 */
function hasPermission($user_type, $required_types) {
    if (!is_array($required_types)) {
        $required_types = [$required_types];
    }
    return in_array($user_type, $required_types);
}

/**
 * Genera slug da stringa
 */
function generateSlug($string) {
    $string = trim($string);
    $string = mb_strtolower($string, 'UTF-8');
    $string = preg_replace('/[^a-z0-9-]/', '-', $string);
    $string = preg_replace('/-+/', '-', $string);
    return trim($string, '-');
}

/**
 * Ottieni IP reale utente
 */
function getRealIpAddr() {
    if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
        $ip = $_SERVER['HTTP_CLIENT_IP'];
    } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
    } else {
        $ip = $_SERVER['REMOTE_ADDR'];
    }
    return $ip;
}

/**
 * Sanitizza nome file
 */
function sanitizeFilename($filename) {
    $filename = preg_replace('/[^a-zA-Z0-9._-]/', '', $filename);
    $filename = preg_replace('/\.+/', '.', $filename);
    return $filename;
}

/**
 * Crea thumbnail immagine
 */
function createThumbnail($source, $destination, $width = 150, $height = 150) {
    list($orig_width, $orig_height, $type) = getimagesize($source);
    
    $ratio = min($width / $orig_width, $height / $orig_height);
    $new_width = round($orig_width * $ratio);
    $new_height = round($orig_height * $ratio);
    
    $thumb = imagecreatetruecolor($new_width, $new_height);
    
    switch ($type) {
        case IMAGETYPE_JPEG:
            $image = imagecreatefromjpeg($source);
            break;
        case IMAGETYPE_PNG:
            $image = imagecreatefrompng($source);
            imagealphablending($thumb, false);
            imagesavealpha($thumb, true);
            break;
        case IMAGETYPE_GIF:
            $image = imagecreatefromgif($source);
            break;
        default:
            return false;
    }
    
    imagecopyresampled($thumb, $image, 0, 0, 0, 0, $new_width, $new_height, $orig_width, $orig_height);
    
    switch ($type) {
        case IMAGETYPE_JPEG:
            imagejpeg($thumb, $destination, 85);
            break;
        case IMAGETYPE_PNG:
            imagepng($thumb, $destination, 8);
            break;
        case IMAGETYPE_GIF:
            imagegif($thumb, $destination);
            break;
    }
    
    imagedestroy($image);
    imagedestroy($thumb);
    
    return true;
}

/**
 * Calcola età da data di nascita
 */
function calculateAge($birthdate) {
    $birthDate = new DateTime($birthdate);
    $today = new DateTime();
    $age = $today->diff($birthDate);
    return $age->y;
}

/**
 * Genera CSV da array
 */
function arrayToCsv($data, $filename = 'export.csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    
    $output = fopen('php://output', 'w');
    
    // Headers
    if (!empty($data)) {
        fputcsv($output, array_keys($data[0]));
    }
    
    // Data
    foreach ($data as $row) {
        fputcsv($output, $row);
    }
    
    fclose($output);
    exit;
}