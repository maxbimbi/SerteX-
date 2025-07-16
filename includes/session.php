<?php
/**
 * Classe Session - Gestione sessioni sicure
 * SerteX+ Genetic Lab Portal
 */

class Session {
    private static $instance = null;
    private $sessionName = 'SERTEXPLUS_SESSION';
    private $sessionLifetime = 3600; // 1 ora
    private $sessionPath = '/';
    private $sessionDomain = null;
    private $sessionSecure = false;
    private $sessionHttpOnly = true;
    private $sessionSameSite = 'Lax';
    
    /**
     * Costruttore privato per pattern Singleton
     */
    private function __construct() {
        // Carica configurazione se disponibile
        if (file_exists(dirname(__DIR__) . '/config/config.php')) {
            require_once dirname(__DIR__) . '/config/config.php';
            
            if (defined('SESSION_LIFETIME')) {
                $this->sessionLifetime = SESSION_LIFETIME;
            }
            
            // Imposta HTTPS se configurato
            $this->sessionSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
        }
        
        $this->initSession();
    }
    
    /**
     * Ottiene l'istanza singleton
     */
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Inizializza la sessione
     */
    private function initSession() {
        if (session_status() === PHP_SESSION_NONE) {
            // Configura parametri sessione
            ini_set('session.use_only_cookies', 1);
            ini_set('session.use_trans_sid', 0);
            ini_set('session.use_strict_mode', 1);
            ini_set('session.cookie_lifetime', $this->sessionLifetime);
            ini_set('session.gc_maxlifetime', $this->sessionLifetime);
            ini_set('session.cookie_httponly', 1);
            
            // Imposta cookie parameters
            $cookieParams = [
                'lifetime' => $this->sessionLifetime,
                'path' => $this->sessionPath,
                'domain' => $this->sessionDomain,
                'secure' => $this->sessionSecure,
                'httponly' => $this->sessionHttpOnly,
                'samesite' => $this->sessionSameSite
            ];
            
            session_set_cookie_params($cookieParams);
            session_name($this->sessionName);
            
            // Avvia sessione
            session_start();
            
            // Rigenera ID sessione periodicamente per sicurezza
            $this->checkSessionRegeneration();
            
            // Verifica timeout sessione
            $this->checkSessionTimeout();
            
            // Imposta/aggiorna timestamp ultima attività
            $_SESSION['last_activity'] = time();
        }
    }
    
    /**
     * Imposta un valore in sessione
     * @param string $key
     * @param mixed $value
     */
    public function set($key, $value) {
        $_SESSION[$key] = $value;
    }
    
    /**
     * Ottiene un valore dalla sessione
     * @param string $key
     * @param mixed $default Valore di default se la chiave non esiste
     * @return mixed
     */
    public function get($key, $default = null) {
        return isset($_SESSION[$key]) ? $_SESSION[$key] : $default;
    }
    
    /**
     * Verifica se una chiave esiste in sessione
     * @param string $key
     * @return bool
     */
    public function has($key) {
        return isset($_SESSION[$key]);
    }
    
    /**
     * Rimuove un valore dalla sessione
     * @param string $key
     */
    public function remove($key) {
        if (isset($_SESSION[$key])) {
            unset($_SESSION[$key]);
        }
    }
    
    /**
     * Pulisce tutti i dati della sessione
     */
    public function clear() {
        $_SESSION = [];
    }
    
    /**
     * Distrugge la sessione
     */
    public function destroy() {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $this->clear();
            
            // Elimina cookie di sessione
            if (ini_get("session.use_cookies")) {
                $params = session_get_cookie_params();
                setcookie(
                    session_name(),
                    '',
                    time() - 42000,
                    $params["path"],
                    $params["domain"],
                    $params["secure"],
                    $params["httponly"]
                );
            }
            
            session_destroy();
        }
    }
    
    /**
     * Rigenera l'ID di sessione
     * @param bool $deleteOldSession
     */
    public function regenerateId($deleteOldSession = true) {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id($deleteOldSession);
            $_SESSION['last_regeneration'] = time();
        }
    }
    
    /**
     * Ottiene l'ID di sessione corrente
     * @return string
     */
    public function getId() {
        return session_id();
    }
    
    /**
     * Imposta un messaggio flash
     * @param string $type Tipo di messaggio (success, error, warning, info)
     * @param string $message
     */
    public function setFlash($type, $message) {
        if (!isset($_SESSION['flash_messages'])) {
            $_SESSION['flash_messages'] = [];
        }
        
        $_SESSION['flash_messages'][] = [
            'type' => $type,
            'message' => $message
        ];
    }
    
    /**
     * Ottiene e rimuove i messaggi flash
     * @return array
     */
    public function getFlashMessages() {
        $messages = $this->get('flash_messages', []);
        $this->remove('flash_messages');
        return $messages;
    }
    
    /**
     * Verifica se ci sono messaggi flash
     * @return bool
     */
    public function hasFlashMessages() {
        return !empty($_SESSION['flash_messages']);
    }
    
    /**
     * Genera e memorizza un token CSRF
     * @return string
     */
    public function generateCsrfToken() {
        $token = bin2hex(random_bytes(32));
        $this->set('csrf_token', $token);
        $this->set('csrf_token_time', time());
        return $token;
    }
    
    /**
     * Verifica un token CSRF
     * @param string $token
     * @param int $maxAge Età massima del token in secondi (default: 3600)
     * @return bool
     */
    public function validateCsrfToken($token, $maxAge = 3600) {
        $sessionToken = $this->get('csrf_token');
        $tokenTime = $this->get('csrf_token_time', 0);
        
        if (!$sessionToken || !$token) {
            return false;
        }
        
        // Verifica che il token non sia scaduto
        if ((time() - $tokenTime) > $maxAge) {
            return false;
        }
        
        // Confronto sicuro dei token
        return hash_equals($sessionToken, $token);
    }
    
    /**
     * Verifica timeout sessione
     */
    private function checkSessionTimeout() {
        $lastActivity = $this->get('last_activity', 0);
        
        if ($lastActivity && (time() - $lastActivity) > $this->sessionLifetime) {
            // Sessione scaduta
            $this->destroy();
            $this->initSession();
        }
    }
    
    /**
     * Verifica e gestisce rigenerazione periodica ID sessione
     */
    private function checkSessionRegeneration() {
        $lastRegeneration = $this->get('last_regeneration', 0);
        $regenerationInterval = 300; // 5 minuti
        
        if (!$lastRegeneration) {
            $_SESSION['last_regeneration'] = time();
        } elseif ((time() - $lastRegeneration) > $regenerationInterval) {
            $this->regenerateId();
        }
    }
    
    /**
     * Previene la clonazione
     */
    private function __clone() {}
    
    /**
     * Previene la deserializzazione
     */
    public function __wakeup() {
        throw new Exception("Cannot unserialize singleton");
    }
}
