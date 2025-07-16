<?php
/**
 * SerteX+ - Classe User
 * Gestione utenti del sistema
 */

namespace SerteX;

use PDO;
use Exception;

class User {
    private $db;
    private $id;
    private $data;
    
    // Tipi di utente
    const TYPE_ADMIN = 'amministratore';
    const TYPE_BIOLOGO = 'biologo';
    const TYPE_PROFESSIONISTA = 'professionista';
    const TYPE_COMMERCIALE = 'commerciale';
    
    /**
     * Costruttore
     * @param PDO $db
     * @param int|null $id
     */
    public function __construct(PDO $db, $id = null) {
        $this->db = $db;
        if ($id !== null) {
            $this->load($id);
        }
    }
    
    /**
     * Carica utente dal database
     * @param int $id
     * @return bool
     */
    public function load($id) {
        $stmt = $this->db->prepare("
            SELECT u.*, p.codice_sdi, p.pec, p.partita_iva, p.codice_fiscale, 
                   p.indirizzo, p.telefono, p.listino_id
            FROM utenti u
            LEFT JOIN professionisti p ON u.id = p.utente_id
            WHERE u.id = ?
        ");
        $stmt->execute([$id]);
        $data = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($data) {
            $this->id = $id;
            $this->data = $data;
            return true;
        }
        
        return false;
    }
    
    /**
     * Carica utente per username o email
     * @param string $username
     * @return bool
     */
    public function loadByUsername($username) {
        $stmt = $this->db->prepare("
            SELECT u.*, p.codice_sdi, p.pec, p.partita_iva, p.codice_fiscale, 
                   p.indirizzo, p.telefono, p.listino_id
            FROM utenti u
            LEFT JOIN professionisti p ON u.id = p.utente_id
            WHERE u.username = ? OR u.email = ?
            LIMIT 1
        ");
        $stmt->execute([$username, $username]);
        $data = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($data) {
            $this->id = $data['id'];
            $this->data = $data;
            return true;
        }
        
        return false;
    }
    
    /**
     * Crea nuovo utente
     * @param array $data
     * @return int|false ID utente creato
     */
    public function create($data) {
        try {
            $this->db->beginTransaction();
            
            // Hash password
            $hashedPassword = password_hash($data['password'], PASSWORD_DEFAULT);
            
            // Inserisci utente
            $stmt = $this->db->prepare("
                INSERT INTO utenti (username, password, email, nome, cognome, tipo_utente, attivo)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            
            $stmt->execute([
                $data['username'],
                $hashedPassword,
                $data['email'],
                $data['nome'],
                $data['cognome'],
                $data['tipo_utente'],
                $data['attivo'] ?? true
            ]);
            
            $userId = $this->db->lastInsertId();
            
            // Se è un professionista, inserisci dati aggiuntivi
            if ($data['tipo_utente'] === self::TYPE_PROFESSIONISTA) {
                $stmt = $this->db->prepare("
                    INSERT INTO professionisti 
                    (utente_id, codice_sdi, pec, partita_iva, codice_fiscale, indirizzo, telefono, listino_id)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ");
                
                $stmt->execute([
                    $userId,
                    $data['codice_sdi'] ?? null,
                    $data['pec'] ?? null,
                    $data['partita_iva'] ?? null,
                    $data['codice_fiscale'] ?? null,
                    $data['indirizzo'] ?? null,
                    $data['telefono'] ?? null,
                    $data['listino_id'] ?? $this->getDefaultListinoId()
                ]);
            }
            
            $this->db->commit();
            
            // Carica i dati dell'utente appena creato
            $this->load($userId);
            
            return $userId;
            
        } catch (Exception $e) {
            $this->db->rollBack();
            error_log("Errore creazione utente: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Aggiorna dati utente
     * @param array $data
     * @return bool
     */
    public function update($data) {
        if (!$this->id) {
            return false;
        }
        
        try {
            $this->db->beginTransaction();
            
            // Prepara query update per utenti
            $updates = [];
            $params = [];
            
            $allowedFields = ['email', 'nome', 'cognome', 'attivo', 'two_factor_enabled'];
            
            foreach ($allowedFields as $field) {
                if (isset($data[$field])) {
                    $updates[] = "$field = ?";
                    $params[] = $data[$field];
                }
            }
            
            // Update password se fornita
            if (!empty($data['password'])) {
                $updates[] = "password = ?";
                $params[] = password_hash($data['password'], PASSWORD_DEFAULT);
                $updates[] = "data_cambio_password = NOW()";
            }
            
            if (!empty($updates)) {
                $params[] = $this->id;
                $sql = "UPDATE utenti SET " . implode(', ', $updates) . " WHERE id = ?";
                $stmt = $this->db->prepare($sql);
                $stmt->execute($params);
            }
            
            // Update dati professionista se necessario
            if ($this->data['tipo_utente'] === self::TYPE_PROFESSIONISTA) {
                $profUpdates = [];
                $profParams = [];
                
                $profFields = ['codice_sdi', 'pec', 'partita_iva', 'codice_fiscale', 
                              'indirizzo', 'telefono', 'listino_id'];
                
                foreach ($profFields as $field) {
                    if (isset($data[$field])) {
                        $profUpdates[] = "$field = ?";
                        $profParams[] = $data[$field];
                    }
                }
                
                if (!empty($profUpdates)) {
                    $profParams[] = $this->id;
                    $sql = "UPDATE professionisti SET " . implode(', ', $profUpdates) . 
                           " WHERE utente_id = ?";
                    $stmt = $this->db->prepare($sql);
                    $stmt->execute($profParams);
                }
            }
            
            $this->db->commit();
            
            // Ricarica dati aggiornati
            $this->load($this->id);
            
            return true;
            
        } catch (Exception $e) {
            $this->db->rollBack();
            error_log("Errore aggiornamento utente: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Elimina utente (soft delete)
     * @return bool
     */
    public function delete() {
        if (!$this->id) {
            return false;
        }
        
        try {
            $stmt = $this->db->prepare("UPDATE utenti SET attivo = 0 WHERE id = ?");
            return $stmt->execute([$this->id]);
        } catch (Exception $e) {
            error_log("Errore eliminazione utente: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Verifica password
     * @param string $password
     * @return bool
     */
    public function verifyPassword($password) {
        if (!$this->data) {
            return false;
        }
        
        return password_verify($password, $this->data['password']);
    }
    
    /**
     * Cambia password
     * @param string $oldPassword
     * @param string $newPassword
     * @return bool
     */
    public function changePassword($oldPassword, $newPassword) {
        if (!$this->verifyPassword($oldPassword)) {
            return false;
        }
        
        return $this->update(['password' => $newPassword]);
    }
    
    /**
     * Reset password
     * @param string $newPassword
     * @return bool
     */
    public function resetPassword($newPassword) {
        return $this->update(['password' => $newPassword]);
    }
    
    /**
     * Incrementa tentativi di login falliti
     * @return bool
     */
    public function incrementFailedAttempts() {
        if (!$this->id) {
            return false;
        }
        
        try {
            $stmt = $this->db->prepare("
                UPDATE utenti 
                SET tentativi_falliti = tentativi_falliti + 1 
                WHERE id = ?
            ");
            $stmt->execute([$this->id]);
            
            // Blocca account se supera il limite (tranne admin)
            if ($this->data['tipo_utente'] !== self::TYPE_ADMIN) {
                $this->data['tentativi_falliti']++;
                if ($this->data['tentativi_falliti'] >= MAX_LOGIN_ATTEMPTS) {
                    $this->block();
                }
            }
            
            return true;
        } catch (Exception $e) {
            error_log("Errore incremento tentativi: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Reset tentativi di login falliti
     * @return bool
     */
    public function resetFailedAttempts() {
        if (!$this->id) {
            return false;
        }
        
        try {
            $stmt = $this->db->prepare("
                UPDATE utenti 
                SET tentativi_falliti = 0 
                WHERE id = ?
            ");
            return $stmt->execute([$this->id]);
        } catch (Exception $e) {
            error_log("Errore reset tentativi: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Blocca account utente
     * @return bool
     */
    public function block() {
        if (!$this->id) {
            return false;
        }
        
        try {
            $stmt = $this->db->prepare("UPDATE utenti SET bloccato = 1 WHERE id = ?");
            $result = $stmt->execute([$this->id]);
            
            if ($result) {
                $this->data['bloccato'] = 1;
                logActivity($this->id, 'account_blocked', 'Account bloccato per troppi tentativi');
            }
            
            return $result;
        } catch (Exception $e) {
            error_log("Errore blocco account: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Sblocca account utente
     * @return bool
     */
    public function unblock() {
        if (!$this->id) {
            return false;
        }
        
        try {
            $stmt = $this->db->prepare("
                UPDATE utenti 
                SET bloccato = 0, tentativi_falliti = 0 
                WHERE id = ?
            ");
            $result = $stmt->execute([$this->id]);
            
            if ($result) {
                $this->data['bloccato'] = 0;
                $this->data['tentativi_falliti'] = 0;
                logActivity($this->id, 'account_unblocked', 'Account sbloccato');
            }
            
            return $result;
        } catch (Exception $e) {
            error_log("Errore sblocco account: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Aggiorna ultimo accesso
     * @return bool
     */
    public function updateLastAccess() {
        if (!$this->id) {
            return false;
        }
        
        try {
            $stmt = $this->db->prepare("
                UPDATE utenti 
                SET data_ultimo_accesso = NOW() 
                WHERE id = ?
            ");
            return $stmt->execute([$this->id]);
        } catch (Exception $e) {
            error_log("Errore aggiornamento ultimo accesso: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Abilita 2FA
     * @param string $secret
     * @return bool
     */
    public function enable2FA($secret) {
        return $this->update([
            'two_factor_enabled' => true,
            'two_factor_secret' => $secret
        ]);
    }
    
    /**
     * Disabilita 2FA
     * @return bool
     */
    public function disable2FA() {
        return $this->update([
            'two_factor_enabled' => false,
            'two_factor_secret' => null
        ]);
    }
    
    /**
     * Verifica se la password è scaduta
     * @return bool
     */
    public function isPasswordExpired() {
        if ($this->data['tipo_utente'] === self::TYPE_ADMIN) {
            return false; // Admin esente da scadenza
        }
        
        if (!$this->data['data_cambio_password']) {
            return true; // Mai cambiata
        }
        
        $lastChange = strtotime($this->data['data_cambio_password']);
        $daysSinceChange = (time() - $lastChange) / 86400;
        
        return $daysSinceChange > PASSWORD_EXPIRY_DAYS;
    }
    
    /**
     * Ottiene lista pazienti del professionista
     * @param array $filters
     * @param int $limit
     * @param int $offset
     * @return array
     */
    public function getPatients($filters = [], $limit = 50, $offset = 0) {
        if ($this->data['tipo_utente'] !== self::TYPE_PROFESSIONISTA) {
            return [];
        }
        
        $sql = "SELECT * FROM pazienti WHERE professionista_id = ?";
        $params = [$this->id];
        
        // Applica filtri
        if (!empty($filters['search'])) {
            $sql .= " AND (nome LIKE ? OR cognome LIKE ? OR codice_fiscale LIKE ?)";
            $search = '%' . $filters['search'] . '%';
            $params[] = $search;
            $params[] = $search;
            $params[] = $search;
        }
        
        $sql .= " ORDER BY cognome, nome LIMIT ? OFFSET ?";
        $params[] = $limit;
        $params[] = $offset;
        
        try {
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("Errore recupero pazienti: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Ottiene statistiche utente
     * @return array
     */
    public function getStatistics() {
        $stats = [];
        
        try {
            switch ($this->data['tipo_utente']) {
                case self::TYPE_PROFESSIONISTA:
                    // Conta pazienti
                    $stmt = $this->db->prepare("
                        SELECT COUNT(*) FROM pazienti WHERE professionista_id = ?
                    ");
                    $stmt->execute([$this->id]);
                    $stats['pazienti_totali'] = $stmt->fetchColumn();
                    
                    // Conta test
                    $stmt = $this->db->prepare("
                        SELECT 
                            COUNT(*) as totali,
                            SUM(CASE WHEN stato = 'refertato' THEN 1 ELSE 0 END) as refertati,
                            SUM(CASE WHEN stato = 'richiesto' THEN 1 ELSE 0 END) as in_attesa
                        FROM test 
                        WHERE professionista_id = ?
                    ");
                    $stmt->execute([$this->id]);
                    $stats['test'] = $stmt->fetch(PDO::FETCH_ASSOC);
                    break;
                    
                case self::TYPE_BIOLOGO:
                    // Test da refertare
                    $stmt = $this->db->query("
                        SELECT COUNT(*) FROM test 
                        WHERE stato IN ('eseguito', 'in_lavorazione')
                    ");
                    $stats['test_da_refertare'] = $stmt->fetchColumn();
                    
                    // Test refertati oggi
                    $stmt = $this->db->query("
                        SELECT COUNT(*) FROM test 
                        WHERE stato = 'refertato' 
                        AND DATE(data_refertazione) = CURDATE()
                    ");
                    $stats['test_refertati_oggi'] = $stmt->fetchColumn();
                    break;
                    
                case self::TYPE_COMMERCIALE:
                    // Fatture del mese
                    $stmt = $this->db->query("
                        SELECT 
                            COUNT(*) as numero,
                            SUM(importo_totale_ivato) as totale
                        FROM fatture 
                        WHERE MONTH(data_emissione) = MONTH(CURDATE())
                        AND YEAR(data_emissione) = YEAR(CURDATE())
                    ");
                    $stats['fatture_mese'] = $stmt->fetch(PDO::FETCH_ASSOC);
                    break;
            }
            
            // Login totali
            $stmt = $this->db->prepare("
                SELECT COUNT(*) FROM log_attivita 
                WHERE utente_id = ? AND azione = 'login'
            ");
            $stmt->execute([$this->id]);
            $stats['login_totali'] = $stmt->fetchColumn();
            
        } catch (Exception $e) {
            error_log("Errore statistiche utente: " . $e->getMessage());
        }
        
        return $stats;
    }
    
    /**
     * Ottiene ID listino predefinito
     * @return int|null
     */
    private function getDefaultListinoId() {
        try {
            $stmt = $this->db->query("
                SELECT id FROM listini WHERE predefinito = 1 LIMIT 1
            ");
            return $stmt->fetchColumn() ?: null;
        } catch (Exception $e) {
            return null;
        }
    }
    
    /**
     * Verifica se l'utente ha un permesso specifico
     * @param string $permission
     * @return bool
     */
    public function hasPermission($permission) {
        // Mapping permessi per tipo utente
        $permissions = [
            self::TYPE_ADMIN => ['*'], // Admin ha tutti i permessi
            self::TYPE_BIOLOGO => [
                'view_tests', 'edit_tests', 'create_reports', 
                'view_reports', 'sign_reports'
            ],
            self::TYPE_PROFESSIONISTA => [
                'view_patients', 'edit_patients', 'create_patients',
                'view_tests', 'create_tests', 'view_reports'
            ],
            self::TYPE_COMMERCIALE => [
                'view_invoices', 'create_invoices', 'view_statistics',
                'export_data'
            ]
        ];
        
        $userType = $this->data['tipo_utente'] ?? null;
        
        if (!$userType || !isset($permissions[$userType])) {
            return false;
        }
        
        // Admin ha sempre tutti i permessi
        if ($userType === self::TYPE_ADMIN) {
            return true;
        }
        
        return in_array($permission, $permissions[$userType]);
    }
    
    /**
     * Ottiene tutti gli utenti (statico)
     * @param PDO $db
     * @param array $filters
     * @param int $limit
     * @param int $offset
     * @return array
     */
    public static function getAll(PDO $db, $filters = [], $limit = 50, $offset = 0) {
        $sql = "
            SELECT u.*, p.partita_iva, p.codice_fiscale
            FROM utenti u
            LEFT JOIN professionisti p ON u.id = p.utente_id
            WHERE 1=1
        ";
        $params = [];
        
        // Filtri
        if (!empty($filters['tipo_utente'])) {
            $sql .= " AND u.tipo_utente = ?";
            $params[] = $filters['tipo_utente'];
        }
        
        if (!empty($filters['attivo'])) {
            $sql .= " AND u.attivo = ?";
            $params[] = $filters['attivo'];
        }
        
        if (!empty($filters['search'])) {
            $sql .= " AND (u.nome LIKE ? OR u.cognome LIKE ? OR u.email LIKE ?)";
            $search = '%' . $filters['search'] . '%';
            $params[] = $search;
            $params[] = $search;
            $params[] = $search;
        }
        
        $sql .= " ORDER BY u.cognome, u.nome LIMIT ? OFFSET ?";
        $params[] = $limit;
        $params[] = $offset;
        
        try {
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("Errore recupero utenti: " . $e->getMessage());
            return [];
        }
    }
    
    // Getter magici
    public function __get($name) {
        return $this->data[$name] ?? null;
    }
    
    public function getId() {
        return $this->id;
    }
    
    public function getData() {
        return $this->data;
    }
    
    public function getFullName() {
        return $this->data['nome'] . ' ' . $this->data['cognome'];
    }
    
    public function toArray() {
        return $this->data;
    }
}