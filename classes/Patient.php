<?php
/**
 * SerteX+ - Classe Patient
 * Gestione pazienti
 */

namespace SerteX;

use PDO;
use Exception;

class Patient {
    private $db;
    private $id;
    private $data;
    
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
     * Carica paziente dal database
     * @param int $id
     * @return bool
     */
    public function load($id) {
        $stmt = $this->db->prepare("
            SELECT p.*, 
                   CONCAT(u.nome, ' ', u.cognome) as professionista_nome
            FROM pazienti p
            JOIN professionisti prof ON p.professionista_id = prof.id
            JOIN utenti u ON prof.utente_id = u.id
            WHERE p.id = ?
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
     * Carica paziente per codice fiscale
     * @param string $cf
     * @return bool
     */
    public function loadByCodiceFiscale($cf) {
        $stmt = $this->db->prepare("
            SELECT p.*, 
                   CONCAT(u.nome, ' ', u.cognome) as professionista_nome
            FROM pazienti p
            JOIN professionisti prof ON p.professionista_id = prof.id
            JOIN utenti u ON prof.utente_id = u.id
            WHERE p.codice_fiscale = ?
            LIMIT 1
        ");
        $stmt->execute([strtoupper($cf)]);
        $data = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($data) {
            $this->id = $data['id'];
            $this->data = $data;
            return true;
        }
        
        return false;
    }
    
    /**
     * Crea nuovo paziente
     * @param array $data
     * @return int|false ID paziente creato
     */
    public function create($data) {
        try {
            // Valida dati obbligatori
            if (empty($data['nome']) || empty($data['cognome']) || 
                empty($data['codice_fiscale']) || empty($data['professionista_id'])) {
                throw new Exception("Dati obbligatori mancanti");
            }
            
            // Valida codice fiscale
            if (!validateCodiceFiscale($data['codice_fiscale'])) {
                throw new Exception("Codice fiscale non valido");
            }
            
            // Verifica se esiste già
            $stmt = $this->db->prepare("
                SELECT id FROM pazienti WHERE codice_fiscale = ?
            ");
            $stmt->execute([strtoupper($data['codice_fiscale'])]);
            
            if ($stmt->fetchColumn()) {
                throw new Exception("Paziente con questo codice fiscale già esistente");
            }
            
            // Inserisci paziente
            $stmt = $this->db->prepare("
                INSERT INTO pazienti 
                (professionista_id, nome, cognome, codice_fiscale, data_nascita, 
                 sesso, email, telefono, indirizzo)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $stmt->execute([
                $data['professionista_id'],
                $data['nome'],
                $data['cognome'],
                strtoupper($data['codice_fiscale']),
                $data['data_nascita'] ?? null,
                $data['sesso'] ?? null,
                $data['email'] ?? null,
                $data['telefono'] ?? null,
                $data['indirizzo'] ?? null
            ]);
            
            $patientId = $this->db->lastInsertId();
            
            // Log attività
            logActivity($_SESSION['user_id'] ?? null, 'patient_created', 
                       "Creato paziente ID: $patientId");
            
            // Carica i dati
            $this->load($patientId);
            
            return $patientId;
            
        } catch (Exception $e) {
            error_log("Errore creazione paziente: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Aggiorna dati paziente
     * @param array $data
     * @return bool
     */
    public function update($data) {
        if (!$this->id) {
            return false;
        }
        
        try {
            $updates = [];
            $params = [];
            
            $allowedFields = ['nome', 'cognome', 'data_nascita', 'sesso', 
                            'email', 'telefono', 'indirizzo'];
            
            foreach ($allowedFields as $field) {
                if (isset($data[$field])) {
                    $updates[] = "$field = ?";
                    $params[] = $data[$field];
                }
            }
            
            if (empty($updates)) {
                return true; // Niente da aggiornare
            }
            
            $params[] = $this->id;
            $sql = "UPDATE pazienti SET " . implode(', ', $updates) . " WHERE id = ?";
            
            $stmt = $this->db->prepare($sql);
            $result = $stmt->execute($params);
            
            if ($result) {
                // Log attività
                logActivity($_SESSION['user_id'] ?? null, 'patient_updated', 
                           "Aggiornato paziente ID: {$this->id}");
                
                // Ricarica dati
                $this->load($this->id);
            }
            
            return $result;
            
        } catch (Exception $e) {
            error_log("Errore aggiornamento paziente: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Elimina paziente (soft delete)
     * @return bool
     */
    public function delete() {
        if (!$this->id) {
            return false;
        }
        
        try {
            // Verifica se ci sono test associati
            $stmt = $this->db->prepare("
                SELECT COUNT(*) FROM test WHERE paziente_id = ?
            ");
            $stmt->execute([$this->id]);
            
            if ($stmt->fetchColumn() > 0) {
                throw new Exception("Impossibile eliminare: paziente con test associati");
            }
            
            // Elimina paziente
            $stmt = $this->db->prepare("DELETE FROM pazienti WHERE id = ?");
            $result = $stmt->execute([$this->id]);
            
            if ($result) {
                logActivity($_SESSION['user_id'] ?? null, 'patient_deleted', 
                           "Eliminato paziente ID: {$this->id}");
            }
            
            return $result;
            
        } catch (Exception $e) {
            error_log("Errore eliminazione paziente: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Ottiene test del paziente
     * @param array $filters
     * @param int $limit
     * @param int $offset
     * @return array
     */
    public function getTests($filters = [], $limit = 50, $offset = 0) {
        if (!$this->id) {
            return [];
        }
        
        $sql = "
            SELECT t.*, 
                   COUNT(DISTINCT tgd.id) as num_analisi,
                   r.id as referto_id,
                   r.file_path as referto_path,
                   r.file_path_firmato as referto_firmato_path
            FROM test t
            LEFT JOIN test_genetici_dettagli tgd ON t.id = tgd.test_id
            LEFT JOIN referti r ON t.id = r.test_id
            WHERE t.paziente_id = ?
        ";
        $params = [$this->id];
        
        // Filtri
        if (!empty($filters['tipo_test'])) {
            $sql .= " AND t.tipo_test = ?";
            $params[] = $filters['tipo_test'];
        }
        
        if (!empty($filters['stato'])) {
            $sql .= " AND t.stato = ?";
            $params[] = $filters['stato'];
        }
        
        if (!empty($filters['data_da'])) {
            $sql .= " AND DATE(t.data_richiesta) >= ?";
            $params[] = $filters['data_da'];
        }
        
        if (!empty($filters['data_a'])) {
            $sql .= " AND DATE(t.data_richiesta) <= ?";
            $params[] = $filters['data_a'];
        }
        
        $sql .= " GROUP BY t.id ORDER BY t.data_richiesta DESC LIMIT ? OFFSET ?";
        $params[] = $limit;
        $params[] = $offset;
        
        try {
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("Errore recupero test paziente: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Ottiene referti scaricabili del paziente
     * @return array
     */
    public function getDownloadableReports() {
        if (!$this->id) {
            return [];
        }
        
        $sql = "
            SELECT t.codice, t.tipo_test, t.data_refertazione,
                   r.id as referto_id, r.file_path_firmato, r.data_creazione
            FROM test t
            JOIN referti r ON t.id = r.test_id
            WHERE t.paziente_id = ? 
            AND t.stato = 'firmato'
            AND r.file_path_firmato IS NOT NULL
            AND DATEDIFF(NOW(), r.data_creazione) <= ?
            ORDER BY t.data_refertazione DESC
        ";
        
        try {
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$this->id, getConfig('referto_retention_days') ?? 45]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("Errore recupero referti scaricabili: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Genera dati pseudonimizzati per il biologo
     * @return array
     */
    public function getPseudonymizedData() {
        if (!$this->data) {
            return [];
        }
        
        return [
            'id' => $this->id,
            'iniziali' => substr($this->data['nome'], 0, 1) . '.' . 
                         substr($this->data['cognome'], 0, 1) . '.',
            'sesso' => $this->data['sesso'],
            'eta' => $this->getAge(),
            'codice_hash' => substr(hash('sha256', $this->data['codice_fiscale']), 0, 8)
        ];
    }
    
    /**
     * Calcola età del paziente
     * @return int|null
     */
    public function getAge() {
        if (!$this->data || !$this->data['data_nascita']) {
            return null;
        }
        
        $birthDate = new \DateTime($this->data['data_nascita']);
        $now = new \DateTime();
        $age = $now->diff($birthDate)->y;
        
        return $age;
    }
    
    /**
     * Verifica se il paziente può scaricare un referto
     * @param string $testCode
     * @param string $cf
     * @return array
     */
    public function canDownloadReport($testCode, $cf) {
        // Verifica codice fiscale
        if (strtoupper($cf) !== $this->data['codice_fiscale']) {
            return ['allowed' => false, 'error' => 'Codice fiscale non corrispondente'];
        }
        
        // Cerca il test
        $stmt = $this->db->prepare("
            SELECT t.*, r.file_path_firmato, r.data_creazione
            FROM test t
            JOIN referti r ON t.id = r.test_id
            WHERE t.codice = ? AND t.paziente_id = ?
            LIMIT 1
        ");
        $stmt->execute([$testCode, $this->id]);
        $test = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$test) {
            return ['allowed' => false, 'error' => 'Test non trovato'];
        }
        
        if ($test['stato'] !== 'firmato') {
            return ['allowed' => false, 'error' => 'Referto non ancora disponibile'];
        }
        
        if (!$test['file_path_firmato']) {
            return ['allowed' => false, 'error' => 'Referto non firmato'];
        }
        
        // Verifica scadenza
        $daysSinceCreation = (time() - strtotime($test['data_creazione'])) / 86400;
        $retentionDays = getConfig('referto_retention_days') ?? 45;
        
        if ($daysSinceCreation > $retentionDays) {
            return ['allowed' => false, 'error' => 'Referto non più disponibile'];
        }
        
        return [
            'allowed' => true,
            'file_path' => $test['file_path_firmato'],
            'test_code' => $test['codice']
        ];
    }
    
    /**
     * Ottiene statistiche del paziente
     * @return array
     */
    public function getStatistics() {
        if (!$this->id) {
            return [];
        }
        
        try {
            $stats = [];
            
            // Totale test
            $stmt = $this->db->prepare("
                SELECT 
                    COUNT(*) as totali,
                    SUM(CASE WHEN stato = 'refertato' THEN 1 ELSE 0 END) as refertati,
                    SUM(CASE WHEN stato = 'firmato' THEN 1 ELSE 0 END) as firmati,
                    SUM(CASE WHEN tipo_test = 'genetico' THEN 1 ELSE 0 END) as genetici,
                    SUM(CASE WHEN tipo_test = 'microbiota' THEN 1 ELSE 0 END) as microbiota,
                    SUM(CASE WHEN tipo_test = 'intolleranze_cito' THEN 1 ELSE 0 END) as intolleranze_cito,
                    SUM(CASE WHEN tipo_test = 'intolleranze_elisa' THEN 1 ELSE 0 END) as intolleranze_elisa
                FROM test 
                WHERE paziente_id = ?
            ");
            $stmt->execute([$this->id]);
            $stats['test'] = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Ultimo test
            $stmt = $this->db->prepare("
                SELECT data_richiesta, tipo_test, stato 
                FROM test 
                WHERE paziente_id = ? 
                ORDER BY data_richiesta DESC 
                LIMIT 1
            ");
            $stmt->execute([$this->id]);
            $stats['ultimo_test'] = $stmt->fetch(PDO::FETCH_ASSOC);
            
            return $stats;
            
        } catch (Exception $e) {
            error_log("Errore statistiche paziente: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Ottiene tutti i pazienti di un professionista
     * @param PDO $db
     * @param int $professionistaId
     * @param array $filters
     * @param int $limit
     * @param int $offset
     * @return array
     */
    public static function getByProfessionista(PDO $db, $professionistaId, 
                                               $filters = [], $limit = 50, $offset = 0) {
        $sql = "
            SELECT p.*, 
                   COUNT(DISTINCT t.id) as num_test,
                   MAX(t.data_richiesta) as ultimo_test
            FROM pazienti p
            LEFT JOIN test t ON p.id = t.paziente_id
            WHERE p.professionista_id = ?
        ";
        $params = [$professionistaId];
        
        // Filtri
        if (!empty($filters['search'])) {
            $sql .= " AND (p.nome LIKE ? OR p.cognome LIKE ? OR p.codice_fiscale LIKE ?)";
            $search = '%' . $filters['search'] . '%';
            $params[] = $search;
            $params[] = $search;
            $params[] = $search;
        }
        
        if (!empty($filters['sesso'])) {
            $sql .= " AND p.sesso = ?";
            $params[] = $filters['sesso'];
        }
        
        $sql .= " GROUP BY p.id ORDER BY p.cognome, p.nome LIMIT ? OFFSET ?";
        $params[] = $limit;
        $params[] = $offset;
        
        try {
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("Errore recupero pazienti professionista: " . $e->getMessage());
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