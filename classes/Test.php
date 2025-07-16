<?php
/**
 * SerteX+ - Classe Test
 * Gestione test e analisi
 */

namespace SerteX;

use PDO;
use Exception;

class Test {
    private $db;
    private $id;
    private $data;
    
    // Stati del test
    const STATO_RICHIESTO = 'richiesto';
    const STATO_IN_LAVORAZIONE = 'in_lavorazione';
    const STATO_ESEGUITO = 'eseguito';
    const STATO_REFERTATO = 'refertato';
    const STATO_FIRMATO = 'firmato';
    
    // Tipi di test
    const TIPO_GENETICO = 'genetico';
    const TIPO_MICROBIOTA = 'microbiota';
    const TIPO_INTOLLERANZE_CITO = 'intolleranze_cito';
    const TIPO_INTOLLERANZE_ELISA = 'intolleranze_elisa';
    
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
     * Carica test dal database
     * @param int $id
     * @return bool
     */
    public function load($id) {
        $stmt = $this->db->prepare("
            SELECT t.*, 
                   p.nome as paziente_nome, p.cognome as paziente_cognome,
                   p.codice_fiscale as paziente_cf,
                   CONCAT(u.nome, ' ', u.cognome) as professionista_nome
            FROM test t
            JOIN pazienti p ON t.paziente_id = p.id
            JOIN professionisti prof ON t.professionista_id = prof.id
            JOIN utenti u ON prof.utente_id = u.id
            WHERE t.id = ?
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
     * Carica test per codice
     * @param string $codice
     * @return bool
     */
    public function loadByCodice($codice) {
        $stmt = $this->db->prepare("
            SELECT t.*, 
                   p.nome as paziente_nome, p.cognome as paziente_cognome,
                   p.codice_fiscale as paziente_cf,
                   CONCAT(u.nome, ' ', u.cognome) as professionista_nome
            FROM test t
            JOIN pazienti p ON t.paziente_id = p.id
            JOIN professionisti prof ON t.professionista_id = prof.id
            JOIN utenti u ON prof.utente_id = u.id
            WHERE t.codice = ?
            LIMIT 1
        ");
        $stmt->execute([$codice]);
        $data = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($data) {
            $this->id = $data['id'];
            $this->data = $data;
            return true;
        }
        
        return false;
    }
    
    /**
     * Crea nuovo test
     * @param array $data
     * @return int|false ID test creato
     */
    public function create($data) {
        try {
            $this->db->beginTransaction();
            
            // Genera codice univoco
            $codice = generateTestCode();
            
            // Verifica unicità codice
            $stmt = $this->db->prepare("SELECT COUNT(*) FROM test WHERE codice = ?");
            $stmt->execute([$codice]);
            
            while ($stmt->fetchColumn() > 0) {
                $codice = generateTestCode();
                $stmt->execute([$codice]);
            }
            
            // Calcola prezzo totale iniziale
            $prezzoTotale = 0;
            
            // Inserisci test principale
            $stmt = $this->db->prepare("
                INSERT INTO test 
                (codice, paziente_id, professionista_id, tipo_test, 
                 prezzo_totale, iva, note, barcode, qrcode)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $stmt->execute([
                $codice,
                $data['paziente_id'],
                $data['professionista_id'],
                $data['tipo_test'],
                $prezzoTotale,
                $data['iva'] ?? 22,
                $data['note'] ?? null,
                generateBarcode($codice),
                generateQRCode($codice)
            ]);
            
            $testId = $this->db->lastInsertId();
            
            // Aggiungi dettagli in base al tipo di test
            switch ($data['tipo_test']) {
                case self::TIPO_GENETICO:
                    $prezzoTotale = $this->addGeneticDetails($testId, $data);
                    break;
                    
                case self::TIPO_MICROBIOTA:
                    $prezzoTotale = $this->addMicrobiotaDetails($testId, $data);
                    break;
                    
                case self::TIPO_INTOLLERANZE_CITO:
                case self::TIPO_INTOLLERANZE_ELISA:
                    $prezzoTotale = $this->addIntoleranceDetails($testId, $data);
                    break;
            }
            
            // Aggiorna prezzo totale e finale
            $prezzoFinale = $prezzoTotale;
            if (!empty($data['sconto'])) {
                $prezzoFinale = $prezzoTotale * (1 - $data['sconto'] / 100);
            }
            
            $stmt = $this->db->prepare("
                UPDATE test 
                SET prezzo_totale = ?, prezzo_finale = ? 
                WHERE id = ?
            ");
            $stmt->execute([$prezzoTotale, $prezzoFinale, $testId]);
            
            $this->db->commit();
            
            // Log attività
            logActivity($_SESSION['user_id'] ?? null, 'test_created', 
                       "Creato test $codice (ID: $testId)");
            
            // Carica i dati
            $this->load($testId);
            
            return $testId;
            
        } catch (Exception $e) {
            $this->db->rollBack();
            error_log("Errore creazione test: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Aggiunge dettagli test genetico
     * @param int $testId
     * @param array $data
     * @return float Prezzo totale
     */
    private function addGeneticDetails($testId, $data) {
        $prezzoTotale = 0;
        $professionistaId = $data['professionista_id'];
        
        // Ottieni listino del professionista
        $listinoId = $this->getProfessionistaListino($professionistaId);
        
        // Aggiungi geni singoli
        if (!empty($data['geni'])) {
            foreach ($data['geni'] as $geneId) {
                $prezzo = $this->getPrezzo('gene', $geneId, $listinoId);
                
                $stmt = $this->db->prepare("
                    INSERT INTO test_genetici_dettagli 
                    (test_id, tipo_elemento, elemento_id, prezzo_unitario)
                    VALUES (?, 'gene', ?, ?)
                ");
                $stmt->execute([$testId, $geneId, $prezzo]);
                
                $prezzoTotale += $prezzo;
            }
        }
        
        // Aggiungi pannelli
        if (!empty($data['pannelli'])) {
            foreach ($data['pannelli'] as $pannelloData) {
                $pannelloId = $pannelloData['id'];
                $prezzo = $this->getPrezzo('pannello_genetico', $pannelloId, $listinoId);
                
                $stmt = $this->db->prepare("
                    INSERT INTO test_genetici_dettagli 
                    (test_id, tipo_elemento, elemento_id, prezzo_unitario)
                    VALUES (?, 'pannello', ?, ?)
                ");
                $stmt->execute([$testId, $pannelloId, $prezzo]);
                
                $dettaglioId = $this->db->lastInsertId();
                $prezzoTotale += $prezzo;
                
                // Aggiungi geni aggiuntivi al pannello
                if (!empty($pannelloData['geni_aggiuntivi'])) {
                    foreach ($pannelloData['geni_aggiuntivi'] as $geneId) {
                        $prezzoGene = $this->getPrezzo('gene', $geneId, $listinoId);
                        
                        $stmt = $this->db->prepare("
                            INSERT INTO test_genetici_geni_aggiuntivi 
                            (test_dettaglio_id, gene_id, prezzo_unitario)
                            VALUES (?, ?, ?)
                        ");
                        $stmt->execute([$dettaglioId, $geneId, $prezzoGene]);
                        
                        $prezzoTotale += $prezzoGene;
                    }
                }
            }
        }
        
        return $prezzoTotale;
    }
    
    /**
     * Aggiunge dettagli test microbiota
     * @param int $testId
     * @param array $data
     * @return float Prezzo totale
     */
    private function addMicrobiotaDetails($testId, $data) {
        if (empty($data['tipo_microbiota_id'])) {
            throw new Exception("Tipo microbiota non specificato");
        }
        
        $listinoId = $this->getProfessionistaListino($data['professionista_id']);
        $prezzo = $this->getPrezzo('microbiota', $data['tipo_microbiota_id'], $listinoId);
        
        $stmt = $this->db->prepare("
            INSERT INTO test_microbiota_dettagli 
            (test_id, tipo_microbiota_id, prezzo_unitario)
            VALUES (?, ?, ?)
        ");
        $stmt->execute([$testId, $data['tipo_microbiota_id'], $prezzo]);
        
        return $prezzo;
    }
    
    /**
     * Aggiunge dettagli test intolleranze
     * @param int $testId
     * @param array $data
     * @return float Prezzo totale
     */
    private function addIntoleranceDetails($testId, $data) {
        if (empty($data['pannello_intolleranze_id'])) {
            throw new Exception("Pannello intolleranze non specificato");
        }
        
        $listinoId = $this->getProfessionistaListino($data['professionista_id']);
        $tipoPrezzo = $data['tipo_test'] === self::TIPO_INTOLLERANZE_CITO ? 
                      'intolleranze_cito' : 'intolleranze_elisa';
        
        $prezzo = $this->getPrezzo($tipoPrezzo, $data['pannello_intolleranze_id'], $listinoId);
        
        $stmt = $this->db->prepare("
            INSERT INTO test_intolleranze_dettagli 
            (test_id, pannello_id, prezzo_unitario)
            VALUES (?, ?, ?)
        ");
        $stmt->execute([$testId, $data['pannello_intolleranze_id'], $prezzo]);
        
        return $prezzo;
    }
    
    /**
     * Ottiene il prezzo da listino
     * @param string $tipo
     * @param int $elementoId
     * @param int|null $listinoId
     * @return float
     */
    private function getPrezzo($tipo, $elementoId, $listinoId = null) {
        // Prima cerca nel listino specifico
        if ($listinoId) {
            $stmt = $this->db->prepare("
                SELECT prezzo FROM listini_prezzi 
                WHERE listino_id = ? AND tipo_elemento = ? AND elemento_id = ?
            ");
            $stmt->execute([$listinoId, $tipo, $elementoId]);
            $prezzo = $stmt->fetchColumn();
            
            if ($prezzo !== false) {
                return (float)$prezzo;
            }
        }
        
        // Altrimenti usa il prezzo base
        switch ($tipo) {
            case 'gene':
                $stmt = $this->db->prepare("SELECT prezzo FROM geni WHERE id = ?");
                break;
            case 'pannello_genetico':
                $stmt = $this->db->prepare("SELECT prezzo FROM pannelli_genetici WHERE id = ?");
                break;
            case 'microbiota':
                $stmt = $this->db->prepare("SELECT prezzo FROM tipi_microbiota WHERE id = ?");
                break;
            case 'intolleranze_cito':
            case 'intolleranze_elisa':
                $stmt = $this->db->prepare("SELECT prezzo FROM pannelli_intolleranze WHERE id = ?");
                break;
            default:
                return 0;
        }
        
        $stmt->execute([$elementoId]);
        return (float)($stmt->fetchColumn() ?: 0);
    }
    
    /**
     * Ottiene listino del professionista
     * @param int $professionistaId
     * @return int|null
     */
    private function getProfessionistaListino($professionistaId) {
        $stmt = $this->db->prepare("
            SELECT listino_id FROM professionisti WHERE id = ?
        ");
        $stmt->execute([$professionistaId]);
        return $stmt->fetchColumn() ?: null;
    }
    
    /**
     * Aggiorna stato del test
     * @param string $nuovoStato
     * @return bool
     */
    public function updateStato($nuovoStato) {
        if (!$this->id) {
            return false;
        }
        
        // Verifica transizione di stato valida
        $transizioniValide = [
            self::STATO_RICHIESTO => [self::STATO_IN_LAVORAZIONE],
            self::STATO_IN_LAVORAZIONE => [self::STATO_ESEGUITO],
            self::STATO_ESEGUITO => [self::STATO_REFERTATO],
            self::STATO_REFERTATO => [self::STATO_FIRMATO]
        ];
        
        $statoAttuale = $this->data['stato'];
        
        if (!isset($transizioniValide[$statoAttuale]) || 
            !in_array($nuovoStato, $transizioniValide[$statoAttuale])) {
            error_log("Transizione di stato non valida: $statoAttuale -> $nuovoStato");
            return false;
        }
        
        try {
            $updates = ['stato = ?'];
            $params = [$nuovoStato];
            
            // Aggiungi timestamp in base allo stato
            switch ($nuovoStato) {
                case self::STATO_ESEGUITO:
                    $updates[] = 'data_esecuzione = NOW()';
                    break;
                case self::STATO_REFERTATO:
                    $updates[] = 'data_refertazione = NOW()';
                    break;
            }
            
            $params[] = $this->id;
            $sql = "UPDATE test SET " . implode(', ', $updates) . " WHERE id = ?";
            
            $stmt = $this->db->prepare($sql);
            $result = $stmt->execute($params);
            
            if ($result) {
                $this->data['stato'] = $nuovoStato;
                logActivity($_SESSION['user_id'] ?? null, 'test_status_changed', 
                           "Test {$this->data['codice']}: $statoAttuale -> $nuovoStato");
            }
            
            return $result;
            
        } catch (Exception $e) {
            error_log("Errore aggiornamento stato test: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Aggiorna prezzo del test
     * @param float $nuovoPrezzo
     * @param float|null $sconto
     * @return bool
     */
    public function updatePrezzo($nuovoPrezzo, $sconto = null) {
        if (!$this->id) {
            return false;
        }
        
        try {
            $prezzoFinale = $nuovoPrezzo;
            
            if ($sconto !== null && $sconto > 0) {
                $prezzoFinale = $nuovoPrezzo * (1 - $sconto / 100);
            }
            
            $stmt = $this->db->prepare("
                UPDATE test 
                SET prezzo_totale = ?, sconto = ?, prezzo_finale = ?
                WHERE id = ?
            ");
            
            $result = $stmt->execute([
                $nuovoPrezzo,
                $sconto,
                $prezzoFinale,
                $this->id
            ]);
            
            if ($result) {
                $this->data['prezzo_totale'] = $nuovoPrezzo;
                $this->data['sconto'] = $sconto;
                $this->data['prezzo_finale'] = $prezzoFinale;
                
                logActivity($_SESSION['user_id'] ?? null, 'test_price_updated', 
                           "Aggiornato prezzo test {$this->data['codice']}");
            }
            
            return $result;
            
        } catch (Exception $e) {
            error_log("Errore aggiornamento prezzo test: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Ottiene dettagli del test
     * @return array
     */
    public function getDetails() {
        if (!$this->id) {
            return [];
        }
        
        $details = [];
        
        try {
            switch ($this->data['tipo_test']) {
                case self::TIPO_GENETICO:
                    $details = $this->getGeneticDetails();
                    break;
                    
                case self::TIPO_MICROBIOTA:
                    $details = $this->getMicrobiotaDetails();
                    break;
                    
                case self::TIPO_INTOLLERANZE_CITO:
                case self::TIPO_INTOLLERANZE_ELISA:
                    $details = $this->getIntoleranceDetails();
                    break;
            }
        } catch (Exception $e) {
            error_log("Errore recupero dettagli test: " . $e->getMessage());
        }
        
        return $details;
    }
    
    /**
     * Ottiene dettagli test genetico
     * @return array
     */
    private function getGeneticDetails() {
        $details = [
            'geni' => [],
            'pannelli' => []
        ];
        
        // Recupera geni singoli
        $stmt = $this->db->prepare("
            SELECT tgd.*, g.sigla, g.nome, g.descrizione, g.gruppo_id,
                   gg.nome as gruppo_nome
            FROM test_genetici_dettagli tgd
            JOIN geni g ON tgd.elemento_id = g.id
            LEFT JOIN gruppi_geni gg ON g.gruppo_id = gg.id
            WHERE tgd.test_id = ? AND tgd.tipo_elemento = 'gene'
            ORDER BY gg.ordine, g.sigla
        ");
        $stmt->execute([$this->id]);
        $details['geni'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Recupera pannelli con geni aggiuntivi
        $stmt = $this->db->prepare("
            SELECT tgd.*, pg.nome, pg.descrizione
            FROM test_genetici_dettagli tgd
            JOIN pannelli_genetici pg ON tgd.elemento_id = pg.id
            WHERE tgd.test_id = ? AND tgd.tipo_elemento = 'pannello'
        ");
        $stmt->execute([$this->id]);
        $pannelli = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($pannelli as &$pannello) {
            // Geni del pannello
            $stmt = $this->db->prepare("
                SELECT g.id, g.sigla, g.nome
                FROM pannelli_geni pg
                JOIN geni g ON pg.gene_id = g.id
                WHERE pg.pannello_id = ?
                ORDER BY g.sigla
            ");
            $stmt->execute([$pannello['elemento_id']]);
            $pannello['geni'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Geni aggiuntivi
            $stmt = $this->db->prepare("
                SELECT ga.*, g.sigla, g.nome
                FROM test_genetici_geni_aggiuntivi ga
                JOIN geni g ON ga.gene_id = g.id
                WHERE ga.test_dettaglio_id = ?
                ORDER BY g.sigla
            ");
            $stmt->execute([$pannello['id']]);
            $pannello['geni_aggiuntivi'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        
        $details['pannelli'] = $pannelli;
        
        return $details;
    }
    
    /**
     * Ottiene dettagli test microbiota
     * @return array
     */
    private function getMicrobiotaDetails() {
        $stmt = $this->db->prepare("
            SELECT tmd.*, tm.nome, tm.descrizione
            FROM test_microbiota_dettagli tmd
            JOIN tipi_microbiota tm ON tmd.tipo_microbiota_id = tm.id
            WHERE tmd.test_id = ?
        ");
        $stmt->execute([$this->id]);
        
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }
    
    /**
     * Ottiene dettagli test intolleranze
     * @return array
     */
    private function getIntoleranceDetails() {
        $stmt = $this->db->prepare("
            SELECT tid.*, pi.nome, pi.descrizione, pi.tipo
            FROM test_intolleranze_dettagli tid
            JOIN pannelli_intolleranze pi ON tid.pannello_id = pi.id
            WHERE tid.test_id = ?
        ");
        $stmt->execute([$this->id]);
        $pannello = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($pannello) {
            // Recupera alimenti del pannello
            $stmt = $this->db->prepare("
                SELECT a.*
                FROM pannelli_alimenti pa
                JOIN alimenti a ON pa.alimento_id = a.id
                WHERE pa.pannello_id = ?
                ORDER BY a.nome
            ");
            $stmt->execute([$pannello['pannello_id']]);
            $pannello['alimenti'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        
        return $pannello ?: [];
    }
    
    /**
     * Genera etichetta per stampa
     * @return array
     */
    public function generateLabel() {
        if (!$this->data) {
            return ['success' => false, 'error' => 'Test non caricato'];
        }
        
        $label = [
            'codice' => $this->data['codice'],
            'barcode' => $this->data['barcode'],
            'qrcode' => $this->data['qrcode'],
            'paziente' => $this->data['paziente_cognome'] . ' ' . $this->data['paziente_nome'],
            'data' => formatDate($this->data['data_richiesta']),
            'tipo' => $this->getTipoTestLabel()
        ];
        
        return ['success' => true, 'label' => $label];
    }
    
    /**
     * Ottiene label del tipo di test
     * @return string
     */
    private function getTipoTestLabel() {
        $labels = [
            self::TIPO_GENETICO => 'Test Genetico',
            self::TIPO_MICROBIOTA => 'Analisi Microbiota',
            self::TIPO_INTOLLERANZE_CITO => 'Intolleranze Citotossico',
            self::TIPO_INTOLLERANZE_ELISA => 'Intolleranze ELISA'
        ];
        
        return $labels[$this->data['tipo_test']] ?? 'Test';
    }
    
    /**
     * Ottiene tutti i test con filtri
     * @param PDO $db
     * @param array $filters
     * @param int $limit
     * @param int $offset
     * @return array
     */
    public static function getAll(PDO $db, $filters = [], $limit = 50, $offset = 0) {
        $sql = "
            SELECT t.*, 
                   p.nome as paziente_nome, p.cognome as paziente_cognome,
                   CONCAT(u.nome, ' ', u.cognome) as professionista_nome,
                   r.id as referto_id,
                   r.file_path_firmato as referto_firmato
            FROM test t
            JOIN pazienti p ON t.paziente_id = p.id
            JOIN professionisti prof ON t.professionista_id = prof.id
            JOIN utenti u ON prof.utente_id = u.id
            LEFT JOIN referti r ON t.id = r.test_id
            WHERE 1=1
        ";
        $params = [];
        
        // Filtri
        if (!empty($filters['professionista_id'])) {
            $sql .= " AND t.professionista_id = ?";
            $params[] = $filters['professionista_id'];
        }
        
        if (!empty($filters['paziente_id'])) {
            $sql .= " AND t.paziente_id = ?";
            $params[] = $filters['paziente_id'];
        }
        
        if (!empty($filters['tipo_test'])) {
            $sql .= " AND t.tipo_test = ?";
            $params[] = $filters['tipo_test'];
        }
        
        if (!empty($filters['stato'])) {
            $sql .= " AND t.stato = ?";
            $params[] = $filters['stato'];
        }
        
        if (!empty($filters['codice'])) {
            $sql .= " AND t.codice LIKE ?";
            $params[] = '%' . $filters['codice'] . '%';
        }
        
        if (!empty($filters['data_da'])) {
            $sql .= " AND DATE(t.data_richiesta) >= ?";
            $params[] = $filters['data_da'];
        }
        
        if (!empty($filters['data_a'])) {
            $sql .= " AND DATE(t.data_richiesta) <= ?";
            $params[] = $filters['data_a'];
        }
        
        if (!empty($filters['search'])) {
            $sql .= " AND (t.codice LIKE ? OR p.nome LIKE ? OR p.cognome LIKE ?)";
            $search = '%' . $filters['search'] . '%';
            $params[] = $search;
            $params[] = $search;
            $params[] = $search;
        }
        
        // Ordinamento
        $orderBy = $filters['order_by'] ?? 'data_richiesta';
        $orderDir = $filters['order_dir'] ?? 'DESC';
        $sql .= " ORDER BY t.$orderBy $orderDir";
        
        $sql .= " LIMIT ? OFFSET ?";
        $params[] = $limit;
        $params[] = $offset;
        
        try {
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("Errore recupero test: " . $e->getMessage());
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