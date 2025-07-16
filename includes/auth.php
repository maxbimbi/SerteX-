<?php
/**
 * Classe Auth - Gestione autenticazione utenti
 * SerteX+ Genetic Lab Portal
 */

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/session.php';
require_once __DIR__ . '/security.php';
require_once dirname(__DIR__) . '/classes/User.php';
require_once dirname(__DIR__) . '/classes/Logger.php';

class Auth {
    private $db;
    private $session;
    private $logger;
    private $maxLoginAttempts = 5;
    private $lockoutTime = 900; // 15 minuti in secondi
    
    public function __construct() {
        $this->db = Database::getInstance();
        $this->session = Session::getInstance();
        $this->logger = new Logger();
        
        // Carica configurazione tentativi login
        $config = $this->db->selectOne("SELECT valore FROM configurazione WHERE chiave = 'max_login_attempts'");
        if ($config) {
            $this->maxLoginAttempts = (int)$config['valore'];
        }
    }
    
    /**
     * Effettua il login
     * @param string $username
     * @param string $password
     * @param string $twoFactorCode Codice 2FA opzionale
     * @return array ['success' => bool, 'message' => string, 'user' => array|null]
     */
    public function login($username, $password, $twoFactorCode = null) {
        try {
            // Verifica se l'utente esiste
            $user = $this->db->selectOne(
                "SELECT * FROM utenti WHERE username = :username AND attivo = 1",
                ['username' => $username]
            );
            
            if (!$user) {
                $this->logger->log(null, 'login_fallito', "Username non trovato: {$username}");
                return [
                    'success' => false,
                    'message' => 'Credenziali non valide'
                ];
            }
            
            // Verifica se l'account è bloccato
            if ($user['bloccato']) {
                $this->logger->log($user['id'], 'login_bloccato', "Tentativo di accesso ad account bloccato");
                return [
                    'success' => false,
                    'message' => 'Account bloccato. Contattare l\'amministratore.'
                ];
            }
            
            // Verifica password
            if (!password_verify($password, $user['password'])) {
                // Incrementa tentativi falliti
                $this->incrementFailedAttempts($user['id']);
                
                $this->logger->log($user['id'], 'login_fallito', "Password errata");
                
                return [
                    'success' => false,
                    'message' => 'Credenziali non valide'
                ];
            }
            
            // Verifica 2FA se abilitato
            if ($user['two_factor_enabled']) {
                if (empty($twoFactorCode)) {
                    return [
                        'success' => false,
                        'message' => 'Codice di verifica richiesto',
                        'requires_2fa' => true
                    ];
                }
                
                if (!$this->verify2FA($user['two_factor_secret'], $twoFactorCode)) {
                    $this->logger->log($user['id'], '2fa_fallito', "Codice 2FA non valido");
                    return [
                        'success' => false,
                        'message' => 'Codice di verifica non valido'
                    ];
                }
            }
            
            // Verifica scadenza password (solo per non amministratori)
            if ($user['tipo_utente'] !== 'amministratore' && $this->isPasswordExpired($user)) {
                return [
                    'success' => false,
                    'message' => 'Password scaduta. È necessario cambiarla.',
                    'password_expired' => true,
                    'user_id' => $user['id']
                ];
            }
            
            // Login riuscito
            $this->createUserSession($user);
            
            // Reset tentativi falliti
            $this->db->update('utenti', [
                'tentativi_falliti' => 0,
                'data_ultimo_accesso' => date('Y-m-d H:i:s')
            ], ['id' => $user['id']]);
            
            $this->logger->log($user['id'], 'login', "Login effettuato con successo");
            
            return [
                'success' => true,
                'message' => 'Login effettuato con successo',
                'user' => [
                    'id' => $user['id'],
                    'username' => $user['username'],
                    'nome' => $user['nome'],
                    'cognome' => $user['cognome'],
                    'tipo_utente' => $user['tipo_utente']
                ]
            ];
            
        } catch (Exception $e) {
            error_log("Errore login: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Si è verificato un errore. Riprovare più tardi.'
            ];
        }
    }
    
    /**
     * Effettua il logout
     */
    public function logout() {
        $userId = $this->session->get('user_id');
        
        if ($userId) {
            // Rimuovi sessione dal database
            $sessionId = session_id();
            $this->db->delete('sessioni', ['id' => $sessionId]);
            
            $this->logger->log($userId, 'logout', "Logout effettuato");
        }
        
        // Distruggi sessione
        $this->session->destroy();
    }
    
    /**
     * Verifica se l'utente è autenticato
     * @return bool
     */
    public function isAuthenticated() {
        if (!$this->session->get('user_id')) {
            return false;
        }
        
        // Verifica che la sessione sia ancora valida nel database
        $sessionId = session_id();
        $session = $this->db->selectOne(
            "SELECT * FROM sessioni WHERE id = :id",
            ['id' => $sessionId]
        );
        
        if (!$session) {
            $this->session->destroy();
            return false;
        }
        
        // Aggiorna ultimo accesso
        $this->db->update('sessioni', [
            'data_ultimo_accesso' => date('Y-m-d H:i:s')
        ], ['id' => $sessionId]);
        
        return true;
    }
    
    /**
     * Verifica se l'utente ha un determinato ruolo
     * @param string|array $roles Ruolo o array di ruoli
     * @return bool
     */
    public function hasRole($roles) {
        if (!$this->isAuthenticated()) {
            return false;
        }
        
        $userRole = $this->session->get('tipo_utente');
        
        if (is_string($roles)) {
            return $userRole === $roles;
        }
        
        if (is_array($roles)) {
            return in_array($userRole, $roles);
        }
        
        return false;
    }
    
    /**
     * Ottiene l'utente corrente
     * @return User|null
     */
    public function getCurrentUser() {
        if (!$this->isAuthenticated()) {
            return null;
        }
        
        $userId = $this->session->get('user_id');
        return new User($userId);
    }
    
    /**
     * Cambia password
     * @param int $userId
     * @param string $oldPassword
     * @param string $newPassword
     * @return array ['success' => bool, 'message' => string]
     */
    public function changePassword($userId, $oldPassword, $newPassword) {
        try {
            $user = $this->db->selectOne(
                "SELECT password FROM utenti WHERE id = :id",
                ['id' => $userId]
            );
            
            if (!$user) {
                return [
                    'success' => false,
                    'message' => 'Utente non trovato'
                ];
            }
            
            // Verifica password attuale
            if (!password_verify($oldPassword, $user['password'])) {
                $this->logger->log($userId, 'cambio_password_fallito', "Password attuale errata");
                return [
                    'success' => false,
                    'message' => 'Password attuale non corretta'
                ];
            }
            
            // Valida nuova password
            $validation = Security::validatePassword($newPassword);
            if (!$validation['valid']) {
                return [
                    'success' => false,
                    'message' => $validation['message']
                ];
            }
            
            // Aggiorna password
            $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
            $this->db->update('utenti', [
                'password' => $hashedPassword,
                'data_cambio_password' => date('Y-m-d H:i:s')
            ], ['id' => $userId]);
            
            $this->logger->log($userId, 'cambio_password', "Password cambiata con successo");
            
            return [
                'success' => true,
                'message' => 'Password cambiata con successo'
            ];
            
        } catch (Exception $e) {
            error_log("Errore cambio password: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Si è verificato un errore'
            ];
        }
    }
    
    /**
     * Reset password (da admin)
     * @param int $userId
     * @param string $newPassword
     * @return array ['success' => bool, 'message' => string]
     */
    public function resetPassword($userId, $newPassword) {
        try {
            // Valida password
            $validation = Security::validatePassword($newPassword);
            if (!$validation['valid']) {
                return [
                    'success' => false,
                    'message' => $validation['message']
                ];
            }
            
            // Aggiorna password
            $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
            $this->db->update('utenti', [
                'password' => $hashedPassword,
                'data_cambio_password' => date('Y-m-d H:i:s'),
                'tentativi_falliti' => 0,
                'bloccato' => 0
            ], ['id' => $userId]);
            
            $adminId = $this->session->get('user_id');
            $this->logger->log($adminId, 'reset_password', "Reset password per utente ID: {$userId}");
            
            return [
                'success' => true,
                'message' => 'Password resettata con successo'
            ];
            
        } catch (Exception $e) {
            error_log("Errore reset password: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Si è verificato un errore'
            ];
        }
    }
    
    /**
     * Abilita/disabilita 2FA
     * @param int $userId
     * @param bool $enable
     * @return array ['success' => bool, 'message' => string, 'secret' => string|null]
     */
    public function toggle2FA($userId, $enable) {
        try {
            if ($enable) {
                // Genera secret
                $secret = $this->generate2FASecret();
                
                $this->db->update('utenti', [
                    'two_factor_enabled' => 1,
                    'two_factor_secret' => $secret
                ], ['id' => $userId]);
                
                $this->logger->log($userId, '2fa_abilitato', "2FA abilitato");
                
                return [
                    'success' => true,
                    'message' => '2FA abilitato con successo',
                    'secret' => $secret
                ];
            } else {
                $this->db->update('utenti', [
                    'two_factor_enabled' => 0,
                    'two_factor_secret' => null
                ], ['id' => $userId]);
                
                $this->logger->log($userId, '2fa_disabilitato', "2FA disabilitato");
                
                return [
                    'success' => true,
                    'message' => '2FA disabilitato con successo'
                ];
            }
        } catch (Exception $e) {
            error_log("Errore toggle 2FA: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Si è verificato un errore'
            ];
        }
    }
    
    /**
     * Crea sessione utente
     * @param array $user
     */
    private function createUserSession($user) {
        // Imposta dati sessione
        $this->session->set('user_id', $user['id']);
        $this->session->set('username', $user['username']);
        $this->session->set('nome', $user['nome']);
        $this->session->set('cognome', $user['cognome']);
        $this->session->set('tipo_utente', $user['tipo_utente']);
        
        // Salva sessione nel database
        $sessionData = [
            'id' => session_id(),
            'utente_id' => $user['id'],
            'ip_address' => $_SERVER['REMOTE_ADDR'] ?? '',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? ''
        ];
        
        $this->db->insert('sessioni', $sessionData);
    }
    
    /**
     * Incrementa tentativi di login falliti
     * @param int $userId
     */
    private function incrementFailedAttempts($userId) {
        $this->db->query(
            "UPDATE utenti SET tentativi_falliti = tentativi_falliti + 1 WHERE id = :id",
            ['id' => $userId]
        );
        
        // Verifica se bloccare l'account
        $user = $this->db->selectOne(
            "SELECT tentativi_falliti FROM utenti WHERE id = :id",
            ['id' => $userId]
        );
        
        if ($user && $user['tentativi_falliti'] >= $this->maxLoginAttempts) {
            $this->db->update('utenti', ['bloccato' => 1], ['id' => $userId]);
            $this->logger->log($userId, 'account_bloccato', "Account bloccato per troppi tentativi");
        }
    }
    
    /**
     * Verifica se la password è scaduta
     * @param array $user
     * @return bool
     */
    private function isPasswordExpired($user) {
        if (empty($user['data_cambio_password'])) {
            return true;
        }
        
        // Ottieni giorni di scadenza dalla configurazione
        $config = $this->db->selectOne(
            "SELECT valore FROM configurazione WHERE chiave = 'password_expiry_days'"
        );
        
        $expiryDays = $config ? (int)$config['valore'] : 90;
        
        $lastChange = new DateTime($user['data_cambio_password']);
        $now = new DateTime();
        $diff = $now->diff($lastChange);
        
        return $diff->days >= $expiryDays;
    }
    
    /**
     * Genera secret per 2FA
     * @return string
     */
    private function generate2FASecret() {
        return Security::generateRandomString(32);
    }
    
    /**
     * Verifica codice 2FA
     * @param string $secret
     * @param string $code
     * @return bool
     */
    private function verify2FA($secret, $code) {
        // Implementazione semplificata - in produzione usare una libreria come Google Authenticator
        // Per ora verifica solo che il codice sia di 6 cifre
        return preg_match('/^\d{6}$/', $code);
    }
}
