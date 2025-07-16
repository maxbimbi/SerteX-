<?php
/**
 * SerteX+ - Classe Gene
 * Gestione geni e risultati genetici
 */

namespace SerteX;

use PDO;
use Exception;

class Gene {
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
     * Carica gene dal database
     * @param int $id
     * @return bool
     */
    public function load($id) {
        $stmt = $this->db->prepare("
            SELECT g.*, gg.nome as gruppo_nome
            FROM geni g
            LEFT JOIN gruppi_geni gg ON g.gruppo_id = gg.id
            WHERE g.id = ?
        ");
        $stmt->execute([$id]);
        $data = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($data) {
            $this->id = $id;
            $this->data = $data;
            $this->loadPossibleResults();
            return true;
        }
        
        return false;
    }
    
    /**
     * Carica gene per sigla
     * @param string $sigla
     * @return bool
     */
    public function loadBySigla($sigla) {
        $stmt = $this->db->prepare("
            SELECT g.*, gg.nome as gruppo_nome
            FROM geni g
            LEFT JOIN gruppi_geni gg ON g.gruppo_id = gg.id
            WHERE g.sigla = ?
            LIMIT 1
        ");
        $stmt->execute([$sigla]);
        $data = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($data) {
            $this->id = $data['id'];
            $this->data = $data;
            $this->loadPossibleResults();
            return true;
        }
        
        return false;
    }
    
    /**
     * Carica risultati possibili del gene
     */
    private function loadPossibleResults() {
        if (!$this->id) {
            return;
        }
        
        $stmt = $this->db->prepare("
            SELECT rg.*, drr.descrizione_report
            FROM risultati_geni rg
            LEFT JOIN descrizioni_risultati_report drr ON rg.id = drr.risultato_gene_id
            WHERE rg.gene_id = ?
            ORDER BY rg.ordine, rg.nome
        ");
        $stmt->execute([$this->id]);
        $this->data['risultati_possibili'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Crea nuovo gene
     * @param array $data
     * @return int|false ID gene creato
     */
    public function create($data) {
        try {
            $this->db->beginTransaction();
            
            // Verifica unicità sigla
            if (!empty($data['sigla'])) {
                $stmt = $this->db->prepare("SELECT COUNT(*) FROM geni WHERE sigla = ?");
                $stmt->execute([$data['sigla']]);
                if ($stmt->fetchColumn() > 0) {
                    throw new Exception("Sigla già esistente");
                }
            }
            
            // Inserisci gene
            $stmt = $this->db->prepare("
                INSERT INTO geni (sigla, nome, descrizione, prezzo, gruppo_id, attivo)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            
            $stmt->execute([
                $data['sigla'] ?? null,
                $data['nome'],
                $data['descrizione'] ?? null,
                $data['prezzo'] ?? 0,
                $data['gruppo_id'] ?? null,
                $data['attivo'] ?? true
            ]);
            
            $geneId = $this->db->lastInsertId();
            
            // Aggiungi risultati possibili
            if (!empty($data['risultati'])) {
                foreach ($data['risultati'] as $index => $risultato) {
                    $stmt = $this->db->prepare("
                        INSERT INTO risultati_geni (gene_id, nome, tipo, descrizione, ordine)
                        VALUES (?, ?, ?, ?, ?)
                    ");
                    
                    $stmt->execute([
                        $geneId,
                        $risultato['nome'],
                        $risultato['tipo'] ?? 'altro',
                        $risultato['descrizione'] ?? null,
                        $risultato['ordine'] ?? $index
                    ]);
                    
                    // Aggiungi descrizione per report se fornita
                    if (!empty($risultato['descrizione_report'])) {
                        $risultatoId = $this->db->lastInsertId();
                        $stmt = $this->db->prepare("
                            INSERT INTO descrizioni_risultati_report 
                            (risultato_gene_id, descrizione_report)
                            VALUES (?, ?)
                        ");
                        $stmt->execute([$risultatoId, $risultato['descrizione_report']]);
                    }
                }
            }
            
            $this->db->commit();
            
            // Log attività
            logActivity($_SESSION['user_id'] ?? null, 'gene_created', 
                       "Creato gene: " . ($data['sigla'] ?? $data['nome']));
            
            // Carica i dati
            $this->load($geneId);
            
            return $geneId;
            
        } catch (Exception $e) {
            $this->db->rollBack();
            error_log("Errore creazione gene: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Aggiorna gene
     * @param array $data
     * @return bool
     */
    public function update($data) {
        if (!$this->id) {
            return false;
        }
        
        try {
            $this->db->beginTransaction();
            
            // Verifica unicità sigla se cambiata
            if (!empty($data['sigla']) && $data['sigla'] !== $this->data['sigla']) {
                $stmt = $this->db->prepare("
                    SELECT COUNT(*) FROM geni WHERE sigla = ? AND id != ?
                ");
                $stmt->execute([$data['sigla'], $this->id]);
                if ($stmt->fetchColumn() > 0) {
                    throw new Exception("Sigla già esistente");
                }
            }
            
            // Update gene
            $updates = [];
            $params = [];
            
            $allowedFields = ['sigla', 'nome', 'descrizione', 'prezzo', 'gruppo_id', 'attivo'];
            
            foreach ($allowedFields as $field) {
                if (isset($data[$field])) {
                    $updates[] = "$field = ?";
                    $params[] = $data[$field];
                }
            }
            
            if (!empty($updates)) {
                $params[] = $this->id;
                $sql = "UPDATE geni SET " . implode(', ', $updates) . " WHERE id = ?";
                $stmt = $this->db->prepare($sql);
                $stmt->execute($params);
            }
            
            // Aggiorna risultati se forniti
            if (isset($data['risultati'])) {
                // Rimuovi risultati esistenti
                $stmt = $this->db->prepare("DELETE FROM risultati_geni WHERE gene_id = ?");
                $stmt->execute([$this->id]);
                
                // Aggiungi nuovi risultati
                foreach ($data['risultati'] as $index => $risultato) {
                    $stmt = $this->db->prepare("
                        INSERT INTO risultati_geni (gene_id, nome, tipo, descrizione, ordine)
                        VALUES (?, ?, ?, ?, ?)
                    ");
                    
                    $stmt->execute([
                        $this->id,
                        $risultato['nome'],
                        $risultato['tipo'] ?? 'altro',
                        $risultato['descrizione'] ?? null,
                        $risultato['ordine'] ?? $index
                    ]);
                    
                    // Aggiungi descrizione per report
                    if (!empty($risultato['descrizione_report'])) {
                        $risultatoId = $this->db->lastInsertId();
                        $stmt = $this->db->prepare("
                            INSERT INTO descrizioni_risultati_report 
                            (risultato_gene_id, descrizione_report)
                            VALUES (?, ?)
                        ");
                        $stmt->execute([$risultatoId, $risultato['descrizione_report']]);
                    }
                }
            }
            
            $this->db->commit();
            
            // Log attività
            logActivity($_SESSION['user_id'] ?? null, 'gene_updated', 
                       "Aggiornato gene: " . ($this->data['sigla'] ?? $this->data['nome']));
            
            // Ricarica dati
            $this->load($this->id);
            
            return true;
            
        } catch (Exception $e) {
            $this->db->rollBack();
            error_log("Errore aggiornamento gene: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Elimina gene (soft delete)
     * @return bool
     */
    public function delete() {
        if (!$this->id) {
            return false;
        }
        
        try {
            // Verifica se il gene è utilizzato in pannelli o test
            $stmt = $this->db->prepare("
                SELECT COUNT(*) FROM pannelli_geni WHERE gene_id = ?
            ");
            $stmt->execute([$this->id]);
            if ($stmt->fetchColumn() > 0) {
                throw new Exception("Gene utilizzato in pannelli");
            }
            
            $stmt = $this->db->prepare("
                SELECT COUNT(*) FROM test_genetici_dettagli 
                WHERE tipo_elemento = 'gene' AND elemento_id = ?
            ");
            $stmt->execute([$this->id]);
            if ($stmt->fetchColumn() > 0) {
                throw new Exception("Gene utilizzato in test");
            }
            
            // Soft delete
            $stmt = $this->db->prepare("UPDATE geni SET attivo = 0 WHERE id = ?");
            $result = $stmt->execute([$this->id]);
            
            if ($result) {
                logActivity($_SESSION['user_id'] ?? null, 'gene_deleted', 
                           "Disattivato gene: " . ($this->data['sigla'] ?? $this->data['nome']));
            }
            
            return $result;
            
        } catch (Exception $e) {
            error_log("Errore eliminazione gene: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Salva risultato di un test
     * @param int $testId
     * @param int $risultatoId
     * @param string|null $note
     * @return bool
     */
    public function saveTestResult($testId, $risultatoId, $note = null) {
        if (!$this->id) {
            return false;
        }
        
        try {
            // Verifica che il risultato appartenga a questo gene
            $stmt = $this->db->prepare("
                SELECT COUNT(*) FROM risultati_geni 
                WHERE id = ? AND gene_id = ?
            ");
            $stmt->execute([$risultatoId, $this->id]);
            
            if ($stmt->fetchColumn() == 0) {
                throw new Exception("Risultato non valido per questo gene");
            }
            
            // Inserisci o aggiorna risultato
            $stmt = $this->db->prepare("
                INSERT INTO risultati_genetici (test_id, gene_id, risultato_id, note)
                VALUES (?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE risultato_id = ?, note = ?
            ");
            
            $result = $stmt->execute([
                $testId, $this->id, $risultatoId, $note,
                $risultatoId, $note
            ]);
            
            if ($result) {
                logActivity($_SESSION['user_id'] ?? null, 'test_result_saved', 
                           "Salvato risultato gene {$this->data['sigla']} per test ID: $testId");
            }
            
            return $result;
            
        } catch (Exception $e) {
            error_log("Errore salvataggio risultato test: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Ottiene risultato di un test
     * @param int $testId
     * @return array|null
     */
    public function getTestResult($testId) {
        if (!$this->id) {
            return null;
        }
        
        $stmt = $this->db->prepare("
            SELECT rg.*, r.nome as risultato_nome, r.tipo as risultato_tipo
            FROM risultati_genetici rg
            JOIN risultati_geni r ON rg.risultato_id = r.id
            WHERE rg.test_id = ? AND rg.gene_id = ?
        ");
        $stmt->execute([$testId, $this->id]);
        
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    
    /**
     * Ottiene pannelli che contengono questo gene
     * @return array
     */
    public function getPanels() {
        if (!$this->id) {
            return [];
        }
        
        $stmt = $this->db->prepare("
            SELECT p.*
            FROM pannelli_genetici p
            JOIN pannelli_geni pg ON p.id = pg.pannello_id
            WHERE pg.gene_id = ? AND p.attivo = 1
            ORDER BY p.nome
        ");
        $stmt->execute([$this->id]);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Ottiene statistiche del gene
     * @return array
     */
    public function getStatistics() {
        if (!$this->id) {
            return [];
        }
        
        $stats = [];
        
        try {
            // Numero di test con questo gene
            $stmt = $this->db->prepare("
                SELECT COUNT(DISTINCT test_id) 
                FROM test_genetici_dettagli 
                WHERE tipo_elemento = 'gene' AND elemento_id = ?
            ");
            $stmt->execute([$this->id]);
            $stats['test_totali'] = $stmt->fetchColumn();
            
            // Distribuzione risultati
            $stmt = $this->db->prepare("
                SELECT r.nome, r.tipo, COUNT(*) as count
                FROM risultati_genetici rg
                JOIN risultati_geni r ON rg.risultato_id = r.id
                WHERE rg.gene_id = ?
                GROUP BY rg.risultato_id
                ORDER BY count DESC
            ");
            $stmt->execute([$this->id]);
            $stats['distribuzione_risultati'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Pannelli che lo contengono
            $stmt = $this->db->prepare("
                SELECT COUNT(*) FROM pannelli_geni WHERE gene_id = ?
            ");
            $stmt->execute([$this->id]);
            $stats['pannelli_totali'] = $stmt->fetchColumn();
            
        } catch (Exception $e) {
            error_log("Errore statistiche gene: " . $e->getMessage());
        }
        
        return $stats;
    }
    
    /**
     * Ottiene tutti i geni
     * @param PDO $db
     * @param array $filters
     * @param int $limit
     * @param int $offset
     * @return array
     */
    public static function getAll(PDO $db, $filters = [], $limit = 50, $offset = 0) {
        $sql = "
            SELECT g.*, gg.nome as gruppo_nome,
                   COUNT(DISTINCT pg.pannello_id) as num_pannelli
            FROM geni g
            LEFT JOIN gruppi_geni gg ON g.gruppo_id = gg.id
            LEFT JOIN pannelli_geni pg ON g.id = pg.gene_id
            WHERE 1=1
        ";
        $params = [];
        
        // Filtri
        if (!empty($filters['gruppo_id'])) {
            $sql .= " AND g.gruppo_id = ?";
            $params[] = $filters['gruppo_id'];
        }
        
        if (isset($filters['attivo'])) {
            $sql .= " AND g.attivo = ?";
            $params[] = $filters['attivo'];
        }
        
        if (!empty($filters['search'])) {
            $sql .= " AND (g.sigla LIKE ? OR g.nome LIKE ? OR g.descrizione LIKE ?)";
            $search = '%' . $filters['search'] . '%';
            $params[] = $search;
            $params[] = $search;
            $params[] = $search;
        }
        
        $sql .= " GROUP BY g.id";
        
        // Ordinamento
        $orderBy = $filters['order_by'] ?? 'g.sigla';
        $orderDir = $filters['order_dir'] ?? 'ASC';
        $sql .= " ORDER BY $orderBy $orderDir";
        
        $sql .= " LIMIT ? OFFSET ?";
        $params[] = $limit;
        $params[] = $offset;
        
        try {
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("Errore recupero geni: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Ottiene tutti i gruppi di geni
     * @param PDO $db
     * @return array
     */
    public static function getAllGroups(PDO $db) {
        try {
            $stmt = $db->query("
                SELECT gg.*, COUNT(g.id) as num_geni
                FROM gruppi_geni gg
                LEFT JOIN geni g ON gg.id = g.gruppo_id AND g.attivo = 1
                GROUP BY gg.id
                ORDER BY gg.ordine, gg.nome
            ");
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("Errore recupero gruppi geni: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Crea nuovo gruppo
     * @param PDO $db
     * @param array $data
     * @return int|false
     */
    public static function createGroup(PDO $db, $data) {
        try {
            $stmt = $db->prepare("
                INSERT INTO gruppi_geni (nome, descrizione, ordine)
                VALUES (?, ?, ?)
            ");
            
            $stmt->execute([
                $data['nome'],
                $data['descrizione'] ?? null,
                $data['ordine'] ?? 0
            ]);
            
            $groupId = $db->lastInsertId();
            
            logActivity($_SESSION['user_id'] ?? null, 'gene_group_created', 
                       "Creato gruppo geni: " . $data['nome']);
            
            return $groupId;
            
        } catch (Exception $e) {
            error_log("Errore creazione gruppo geni: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Aggiorna descrizione report per gruppo
     * @param PDO $db
     * @param int $gruppoId
     * @param string $descrizione
     * @return bool
     */
    public static function updateGroupReportDescription(PDO $db, $gruppoId, $descrizione) {
        try {
            $stmt = $db->prepare("
                INSERT INTO descrizioni_report (tipo, elemento_id, descrizione_generale)
                VALUES ('gruppo', ?, ?)
                ON DUPLICATE KEY UPDATE descrizione_generale = ?
            ");
            
            return $stmt->execute([$gruppoId, $descrizione, $descrizione]);
            
        } catch (Exception $e) {
            error_log("Errore aggiornamento descrizione gruppo: " . $e->getMessage());
            return false;
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
    
    public function toArray() {
        return $this->data;
    }
}