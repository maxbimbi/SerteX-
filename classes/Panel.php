<?php
/**
 * SerteX+ - Classe Panel
 * Gestione pannelli (genetici e intolleranze)
 */

namespace SerteX;

use PDO;
use Exception;

class Panel {
    private $db;
    private $id;
    private $data;
    private $type; // 'genetic' o 'intolerance'
    
    const TYPE_GENETIC = 'genetic';
    const TYPE_INTOLERANCE = 'intolerance';
    
    /**
     * Costruttore
     * @param PDO $db
     * @param string $type Tipo di pannello
     * @param int|null $id
     */
    public function __construct(PDO $db, $type = self::TYPE_GENETIC, $id = null) {
        $this->db = $db;
        $this->type = $type;
        if ($id !== null) {
            $this->load($id);
        }
    }
    
    /**
     * Carica pannello dal database
     * @param int $id
     * @return bool
     */
    public function load($id) {
        if ($this->type === self::TYPE_GENETIC) {
            $stmt = $this->db->prepare("
                SELECT pg.*, COUNT(DISTINCT pgr.gene_id) as num_geni
                FROM pannelli_genetici pg
                LEFT JOIN pannelli_geni pgr ON pg.id = pgr.pannello_id
                WHERE pg.id = ?
                GROUP BY pg.id
            ");
        } else {
            $stmt = $this->db->prepare("
                SELECT pi.*, COUNT(DISTINCT pa.alimento_id) as num_alimenti
                FROM pannelli_intolleranze pi
                LEFT JOIN pannelli_alimenti pa ON pi.id = pa.pannello_id
                WHERE pi.id = ?
                GROUP BY pi.id
            ");
        }
        
        $stmt->execute([$id]);
        $data = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($data) {
            $this->id = $id;
            $this->data = $data;
            $this->loadElements();
            return true;
        }
        
        return false;
    }
    
    /**
     * Carica elementi del pannello (geni o alimenti)
     */
    private function loadElements() {
        if (!$this->id) {
            return;
        }
        
        if ($this->type === self::TYPE_GENETIC) {
            $stmt = $this->db->prepare("
                SELECT g.*, gg.nome as gruppo_nome
                FROM pannelli_geni pg
                JOIN geni g ON pg.gene_id = g.id
                LEFT JOIN gruppi_geni gg ON g.gruppo_id = gg.id
                WHERE pg.pannello_id = ?
                ORDER BY gg.ordine, g.sigla
            ");
            $stmt->execute([$this->id]);
            $this->data['geni'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $stmt = $this->db->prepare("
                SELECT a.*
                FROM pannelli_alimenti pa
                JOIN alimenti a ON pa.alimento_id = a.id
                WHERE pa.pannello_id = ?
                ORDER BY a.nome
            ");
            $stmt->execute([$this->id]);
            $this->data['alimenti'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    }
    
    /**
     * Crea nuovo pannello
     * @param array $data
     * @return int|false ID pannello creato
     */
    public function create($data) {
        try {
            $this->db->beginTransaction();
            
            if ($this->type === self::TYPE_GENETIC) {
                // Crea pannello genetico
                $stmt = $this->db->prepare("
                    INSERT INTO pannelli_genetici (nome, descrizione, prezzo, attivo)
                    VALUES (?, ?, ?, ?)
                ");
                
                $stmt->execute([
                    $data['nome'],
                    $data['descrizione'] ?? null,
                    $data['prezzo'] ?? 0,
                    $data['attivo'] ?? true
                ]);
                
                $panelId = $this->db->lastInsertId();
                
                // Aggiungi geni
                if (!empty($data['geni'])) {
                    $stmt = $this->db->prepare("
                        INSERT INTO pannelli_geni (pannello_id, gene_id) VALUES (?, ?)
                    ");
                    
                    foreach ($data['geni'] as $geneId) {
                        $stmt->execute([$panelId, $geneId]);
                    }
                }
                
                $logType = 'genetic_panel_created';
                
            } else {
                // Crea pannello intolleranze
                $stmt = $this->db->prepare("
                    INSERT INTO pannelli_intolleranze (nome, descrizione, tipo, prezzo, attivo)
                    VALUES (?, ?, ?, ?, ?)
                ");
                
                $stmt->execute([
                    $data['nome'],
                    $data['descrizione'] ?? null,
                    $data['tipo'], // 'citotossico' o 'elisa'
                    $data['prezzo'] ?? 0,
                    $data['attivo'] ?? true
                ]);
                
                $panelId = $this->db->lastInsertId();
                
                // Aggiungi alimenti
                if (!empty($data['alimenti'])) {
                    $stmt = $this->db->prepare("
                        INSERT INTO pannelli_alimenti (pannello_id, alimento_id) VALUES (?, ?)
                    ");
                    
                    foreach ($data['alimenti'] as $alimentoId) {
                        $stmt->execute([$panelId, $alimentoId]);
                    }
                }
                
                $logType = 'intolerance_panel_created';
            }
            
            $this->db->commit();
            
            // Log attività
            logActivity($_SESSION['user_id'] ?? null, $logType, 
                       "Creato pannello: " . $data['nome']);
            
            // Carica i dati
            $this->load($panelId);
            
            return $panelId;
            
        } catch (Exception $e) {
            $this->db->rollBack();
            error_log("Errore creazione pannello: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Aggiorna pannello
     * @param array $data
     * @return bool
     */
    public function update($data) {
        if (!$this->id) {
            return false;
        }
        
        try {
            $this->db->beginTransaction();
            
            // Update dati base
            $updates = [];
            $params = [];
            
            $allowedFields = ['nome', 'descrizione', 'prezzo', 'attivo'];
            if ($this->type === self::TYPE_INTOLERANCE) {
                $allowedFields[] = 'tipo';
            }
            
            foreach ($allowedFields as $field) {
                if (isset($data[$field])) {
                    $updates[] = "$field = ?";
                    $params[] = $data[$field];
                }
            }
            
            if (!empty($updates)) {
                $params[] = $this->id;
                $table = $this->type === self::TYPE_GENETIC ? 'pannelli_genetici' : 'pannelli_intolleranze';
                $sql = "UPDATE $table SET " . implode(', ', $updates) . " WHERE id = ?";
                $stmt = $this->db->prepare($sql);
                $stmt->execute($params);
            }
            
            // Update elementi se forniti
            if ($this->type === self::TYPE_GENETIC && isset($data['geni'])) {
                // Rimuovi geni esistenti
                $stmt = $this->db->prepare("DELETE FROM pannelli_geni WHERE pannello_id = ?");
                $stmt->execute([$this->id]);
                
                // Aggiungi nuovi geni
                if (!empty($data['geni'])) {
                    $stmt = $this->db->prepare("
                        INSERT INTO pannelli_geni (pannello_id, gene_id) VALUES (?, ?)
                    ");
                    
                    foreach ($data['geni'] as $geneId) {
                        $stmt->execute([$this->id, $geneId]);
                    }
                }
            } elseif ($this->type === self::TYPE_INTOLERANCE && isset($data['alimenti'])) {
                // Rimuovi alimenti esistenti
                $stmt = $this->db->prepare("DELETE FROM pannelli_alimenti WHERE pannello_id = ?");
                $stmt->execute([$this->id]);
                
                // Aggiungi nuovi alimenti
                if (!empty($data['alimenti'])) {
                    $stmt = $this->db->prepare("
                        INSERT INTO pannelli_alimenti (pannello_id, alimento_id) VALUES (?, ?)
                    ");
                    
                    foreach ($data['alimenti'] as $alimentoId) {
                        $stmt->execute([$this->id, $alimentoId]);
                    }
                }
            }
            
            $this->db->commit();
            
            // Log attività
            $logType = $this->type === self::TYPE_GENETIC ? 'genetic_panel_updated' : 'intolerance_panel_updated';
            logActivity($_SESSION['user_id'] ?? null, $logType, 
                       "Aggiornato pannello: " . $this->data['nome']);
            
            // Ricarica dati
            $this->load($this->id);
            
            return true;
            
        } catch (Exception $e) {
            $this->db->rollBack();
            error_log("Errore aggiornamento pannello: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Elimina pannello (soft delete)
     * @return bool
     */
    public function delete() {
        if (!$this->id) {
            return false;
        }
        
        try {
            // Verifica se il pannello è utilizzato in test
            if ($this->type === self::TYPE_GENETIC) {
                $stmt = $this->db->prepare("
                    SELECT COUNT(*) FROM test_genetici_dettagli 
                    WHERE tipo_elemento = 'pannello' AND elemento_id = ?
                ");
            } else {
                $stmt = $this->db->prepare("
                    SELECT COUNT(*) FROM test_intolleranze_dettagli 
                    WHERE pannello_id = ?
                ");
            }
            
            $stmt->execute([$this->id]);
            if ($stmt->fetchColumn() > 0) {
                throw new Exception("Pannello utilizzato in test");
            }
            
            // Soft delete
            $table = $this->type === self::TYPE_GENETIC ? 'pannelli_genetici' : 'pannelli_intolleranze';
            $stmt = $this->db->prepare("UPDATE $table SET attivo = 0 WHERE id = ?");
            $result = $stmt->execute([$this->id]);
            
            if ($result) {
                $logType = $this->type === self::TYPE_GENETIC ? 'genetic_panel_deleted' : 'intolerance_panel_deleted';
                logActivity($_SESSION['user_id'] ?? null, $logType, 
                           "Disattivato pannello: " . $this->data['nome']);
            }
            
            return $result;
            
        } catch (Exception $e) {
            error_log("Errore eliminazione pannello: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Clona pannello
     * @param string $newName
     * @return int|false ID del nuovo pannello
     */
    public function clone($newName) {
        if (!$this->data) {
            return false;
        }
        
        $data = $this->data;
        $data['nome'] = $newName;
        
        // Aggiungi elementi
        if ($this->type === self::TYPE_GENETIC) {
            $data['geni'] = array_column($this->data['geni'], 'id');
        } else {
            $data['alimenti'] = array_column($this->data['alimenti'], 'id');
        }
        
        $newPanel = new Panel($this->db, $this->type);
        return $newPanel->create($data);
    }
    
    /**
     * Calcola prezzo con listino
     * @param int|null $listinoId
     * @return float
     */
    public function calculatePrice($listinoId = null) {
        if (!$this->id) {
            return 0;
        }
        
        // Cerca prezzo nel listino
        if ($listinoId) {
            $tipoElemento = $this->type === self::TYPE_GENETIC ? 
                           'pannello_genetico' : 
                           ($this->data['tipo'] === 'citotossico' ? 'intolleranze_cito' : 'intolleranze_elisa');
            
            $stmt = $this->db->prepare("
                SELECT prezzo FROM listini_prezzi 
                WHERE listino_id = ? AND tipo_elemento = ? AND elemento_id = ?
            ");
            $stmt->execute([$listinoId, $tipoElemento, $this->id]);
            $prezzo = $stmt->fetchColumn();
            
            if ($prezzo !== false) {
                return (float)$prezzo;
            }
        }
        
        // Altrimenti usa prezzo base
        return (float)$this->data['prezzo'];
    }
    
    /**
     * Ottiene statistiche del pannello
     * @return array
     */
    public function getStatistics() {
        if (!$this->id) {
            return [];
        }
        
        $stats = [];
        
        try {
            if ($this->type === self::TYPE_GENETIC) {
                // Test con questo pannello
                $stmt = $this->db->prepare("
                    SELECT COUNT(*) FROM test_genetici_dettagli 
                    WHERE tipo_elemento = 'pannello' AND elemento_id = ?
                ");
                $stmt->execute([$this->id]);
                $stats['test_totali'] = $stmt->fetchColumn();
                
                // Geni più richiesti come aggiuntivi
                $stmt = $this->db->prepare("
                    SELECT g.sigla, g.nome, COUNT(*) as count
                    FROM test_genetici_geni_aggiuntivi ga
                    JOIN test_genetici_dettagli td ON ga.test_dettaglio_id = td.id
                    JOIN geni g ON ga.gene_id = g.id
                    WHERE td.elemento_id = ? AND td.tipo_elemento = 'pannello'
                    GROUP BY ga.gene_id
                    ORDER BY count DESC
                    LIMIT 10
                ");
                $stmt->execute([$this->id]);
                $stats['geni_aggiuntivi_frequenti'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
            } else {
                // Test con questo pannello
                $stmt = $this->db->prepare("
                    SELECT COUNT(*) FROM test_intolleranze_dettagli WHERE pannello_id = ?
                ");
                $stmt->execute([$this->id]);
                $stats['test_totali'] = $stmt->fetchColumn();
                
                // Alimenti più positivi
                $stmt = $this->db->prepare("
                    SELECT a.nome, 
                           SUM(CASE WHEN ri.grado > 0 THEN 1 ELSE 0 END) as positivi,
                           COUNT(*) as totali,
                           ROUND(SUM(CASE WHEN ri.grado > 0 THEN 1 ELSE 0 END) * 100.0 / COUNT(*), 2) as percentuale
                    FROM risultati_intolleranze ri
                    JOIN test t ON ri.test_id = t.id
                    JOIN test_intolleranze_dettagli tid ON t.id = tid.test_id
                    JOIN alimenti a ON ri.alimento_id = a.id
                    WHERE tid.pannello_id = ?
                    GROUP BY ri.alimento_id
                    HAVING positivi > 0
                    ORDER BY percentuale DESC
                    LIMIT 10
                ");
                $stmt->execute([$this->id]);
                $stats['alimenti_positivi_frequenti'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
            
        } catch (Exception $e) {
            error_log("Errore statistiche pannello: " . $e->getMessage());
        }
        
        return $stats;
    }
    
    /**
     * Ottiene tutti i pannelli
     * @param PDO $db
     * @param string $type
     * @param array $filters
     * @param int $limit
     * @param int $offset
     * @return array
     */
    public static function getAll(PDO $db, $type = self::TYPE_GENETIC, $filters = [], $limit = 50, $offset = 0) {
        if ($type === self::TYPE_GENETIC) {
            $sql = "
                SELECT pg.*, COUNT(DISTINCT pgr.gene_id) as num_elementi
                FROM pannelli_genetici pg
                LEFT JOIN pannelli_geni pgr ON pg.id = pgr.pannello_id
                WHERE 1=1
            ";
        } else {
            $sql = "
                SELECT pi.*, COUNT(DISTINCT pa.alimento_id) as num_elementi
                FROM pannelli_intolleranze pi
                LEFT JOIN pannelli_alimenti pa ON pi.id = pa.pannello_id
                WHERE 1=1
            ";
        }
        
        $params = [];
        
        // Filtri
        if (isset($filters['attivo'])) {
            $sql .= " AND " . ($type === self::TYPE_GENETIC ? "pg" : "pi") . ".attivo = ?";
            $params[] = $filters['attivo'];
        }
        
        if (!empty($filters['search'])) {
            $table = $type === self::TYPE_GENETIC ? "pg" : "pi";
            $sql .= " AND ($table.nome LIKE ? OR $table.descrizione LIKE ?)";
            $search = '%' . $filters['search'] . '%';
            $params[] = $search;
            $params[] = $search;
        }
        
        if ($type === self::TYPE_INTOLERANCE && !empty($filters['tipo'])) {
            $sql .= " AND pi.tipo = ?";
            $params[] = $filters['tipo'];
        }
        
        $sql .= " GROUP BY " . ($type === self::TYPE_GENETIC ? "pg.id" : "pi.id");
        
        // Ordinamento
        $orderBy = $filters['order_by'] ?? 'nome';
        $orderDir = $filters['order_dir'] ?? 'ASC';
        $table = $type === self::TYPE_GENETIC ? "pg" : "pi";
        $sql .= " ORDER BY $table.$orderBy $orderDir";
        
        $sql .= " LIMIT ? OFFSET ?";
        $params[] = $limit;
        $params[] = $offset;
        
        try {
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("Errore recupero pannelli: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Importa pannello da CSV
     * @param PDO $db
     * @param string $type
     * @param string $csvPath
     * @return array
     */
    public static function importFromCSV(PDO $db, $type, $csvPath) {
        $results = ['success' => 0, 'errors' => []];
        
        try {
            $csv = array_map('str_getcsv', file($csvPath));
            $header = array_shift($csv);
            
            foreach ($csv as $row) {
                $data = array_combine($header, $row);
                
                $panel = new Panel($db, $type);
                
                // Prepara dati pannello
                $panelData = [
                    'nome' => $data['nome'],
                    'descrizione' => $data['descrizione'] ?? null,
                    'prezzo' => (float)($data['prezzo'] ?? 0),
                    'attivo' => true
                ];
                
                if ($type === self::TYPE_INTOLERANCE) {
                    $panelData['tipo'] = $data['tipo']; // citotossico o elisa
                }
                
                // Aggiungi elementi
                if ($type === self::TYPE_GENETIC) {
                    // Cerca geni per sigla
                    $sigle = explode(',', $data['geni']);
                    $geniIds = [];
                    
                    foreach ($sigle as $sigla) {
                        $stmt = $db->prepare("SELECT id FROM geni WHERE sigla = ?");
                        $stmt->execute([trim($sigla)]);
                        $geneId = $stmt->fetchColumn();
                        
                        if ($geneId) {
                            $geniIds[] = $geneId;
                        }
                    }
                    
                    $panelData['geni'] = $geniIds;
                } else {
                    // Cerca alimenti per nome
                    $nomi = explode(',', $data['alimenti']);
                    $alimentiIds = [];
                    
                    foreach ($nomi as $nome) {
                        $stmt = $db->prepare("SELECT id FROM alimenti WHERE nome = ?");
                        $stmt->execute([trim($nome)]);
                        $alimentoId = $stmt->fetchColumn();
                        
                        if ($alimentoId) {
                            $alimentiIds[] = $alimentoId;
                        }
                    }
                    
                    $panelData['alimenti'] = $alimentiIds;
                }
                
                if ($panel->create($panelData)) {
                    $results['success']++;
                } else {
                    $results['errors'][] = "Errore importazione pannello: " . $data['nome'];
                }
            }
            
        } catch (Exception $e) {
            $results['errors'][] = "Errore lettura CSV: " . $e->getMessage();
        }
        
        return $results;
    }
    
    /**
     * Esporta pannelli in CSV
     * @param PDO $db
     * @param string $type
     * @param string $outputPath
     * @return bool
     */
    public static function exportToCSV(PDO $db, $type, $outputPath) {
        try {
            $panels = self::getAll($db, $type, ['attivo' => 1], 1000, 0);
            
            $fp = fopen($outputPath, 'w');
            
            // Header
            if ($type === self::TYPE_GENETIC) {
                fputcsv($fp, ['nome', 'descrizione', 'prezzo', 'geni']);
            } else {
                fputcsv($fp, ['nome', 'descrizione', 'tipo', 'prezzo', 'alimenti']);
            }
            
            foreach ($panels as $panelData) {
                $panel = new Panel($db, $type, $panelData['id']);
                
                if ($type === self::TYPE_GENETIC) {
                    $elementi = implode(',', array_column($panel->data['geni'], 'sigla'));
                    fputcsv($fp, [
                        $panel->data['nome'],
                        $panel->data['descrizione'],
                        $panel->data['prezzo'],
                        $elementi
                    ]);
                } else {
                    $elementi = implode(',', array_column($panel->data['alimenti'], 'nome'));
                    fputcsv($fp, [
                        $panel->data['nome'],
                        $panel->data['descrizione'],
                        $panel->data['tipo'],
                        $panel->data['prezzo'],
                        $elementi
                    ]);
                }
            }
            
            fclose($fp);
            return true;
            
        } catch (Exception $e) {
            error_log("Errore esportazione pannelli: " . $e->getMessage());
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
    
    public function getType() {
        return $this->type;
    }
    
    public function getData() {
        return $this->data;
    }
    
    public function toArray() {
        return $this->data;
    }
}