<?php
/**
 * API Tests - Gestione test
 * SerteX+ Genetic Lab Portal
 */

require_once '../../includes/database.php';
require_once '../../includes/functions.php';
require_once '../../classes/Test.php';

// Richiede autenticazione
requireAuth();

$db = Database::getInstance();
$method = $_SERVER['REQUEST_METHOD'];
$testId = $_GET['id'] ?? null;

switch ($method) {
    case 'GET':
        if ($testId) {
            // Dettaglio singolo test
            handleGetTest($testId);
        } else {
            // Lista test
            handleGetTests();
        }
        break;
        
    case 'POST':
        // Crea nuovo test
        requireRole(['professionista', 'amministratore']);
        handleCreateTest();
        break;
        
    case 'PUT':
        // Aggiorna test
        requireRole(['biologo', 'amministratore']);
        handleUpdateTest($testId);
        break;
        
    case 'DELETE':
        // Elimina test
        requireRole('amministratore');
        handleDeleteTest($testId);
        break;
        
    default:
        jsonResponse(['success' => false, 'error' => 'Metodo non supportato'], 405);
}

/**
 * Recupera dettagli di un test
 */
function handleGetTest($testId) {
    global $db, $auth;
    
    $test = $db->selectOne("
        SELECT t.*, 
               p.nome as paziente_nome, 
               p.cognome as paziente_cognome,
               p.codice_fiscale,
               pr.nome as professionista_nome,
               pr.cognome as professionista_cognome
        FROM test t
        INNER JOIN pazienti p ON t.paziente_id = p.id
        INNER JOIN professionisti prof ON t.professionista_id = prof.id
        INNER JOIN utenti pr ON prof.utente_id = pr.id
        WHERE t.id = :id
    ", ['id' => $testId]);
    
    if (!$test) {
        jsonResponse(['success' => false, 'error' => 'Test non trovato'], 404);
    }
    
    // Controllo accesso in base al ruolo
    if ($auth->hasRole('professionista')) {
        $user = $auth->getCurrentUser();
        $professionista = $db->selectOne(
            "SELECT id FROM professionisti WHERE utente_id = :user_id",
            ['user_id' => $user->getId()]
        );
        
        if (!$professionista || $professionista['id'] != $test['professionista_id']) {
            jsonResponse(['success' => false, 'error' => 'Accesso negato'], 403);
        }
    }
    
    // Carica dettagli specifici per tipo test
    $details = isset($_GET['details']) && $_GET['details'] === 'true';
    
    if ($details) {
        switch ($test['tipo_test']) {
            case 'genetico':
                $test['dettagli'] = $db->select("
                    SELECT 
                        CASE 
                            WHEN tgd.tipo_elemento = 'gene' THEN g.nome
                            WHEN tgd.tipo_elemento = 'pannello' THEN pg.nome
                        END as nome,
                        tgd.tipo_elemento as tipo,
                        tgd.prezzo_unitario
                    FROM test_genetici_dettagli tgd
                    LEFT JOIN geni g ON (tgd.tipo_elemento = 'gene' AND tgd.elemento_id = g.id)
                    LEFT JOIN pannelli_genetici pg ON (tgd.tipo_elemento = 'pannello' AND tgd.elemento_id = pg.id)
                    WHERE tgd.test_id = :test_id
                ", ['test_id' => $testId]);
                break;
                
            case 'microbiota':
                $test['dettagli'] = $db->select("
                    SELECT tm.nome, tmd.prezzo_unitario
                    FROM test_microbiota_dettagli tmd
                    INNER JOIN tipi_microbiota tm ON tmd.tipo_microbiota_id = tm.id
                    WHERE tmd.test_id = :test_id
                ", ['test_id' => $testId]);
                break;
                
            case 'intolleranze_cito':
            case 'intolleranze_elisa':
                $test['dettagli'] = $db->select("
                    SELECT pi.nome, tid.prezzo_unitario
                    FROM test_intolleranze_dettagli tid
                    INNER JOIN pannelli_intolleranze pi ON tid.pannello_id = pi.id
                    WHERE tid.test_id = :test_id
                ", ['test_id' => $testId]);
                break;
        }
    }
    
    // Pseudonimizza per biologi
    if ($auth->hasRole('biologo')) {
        $test['paziente_nome'] = substr($test['paziente_nome'], 0, 1) . '***';
        $test['paziente_cognome'] = substr($test['paziente_cognome'], 0, 1) . '***';
        $test['codice_fiscale'] = substr($test['codice_fiscale'], 0, 3) . '***' . substr($test['codice_fiscale'], -3);
    }
    
    jsonResponse([
        'success' => true,
        'data' => $test
    ]);
}

/**
 * Recupera lista test
 */
function handleGetTests() {
    global $db, $auth;
    
    $query = "
        SELECT t.*, 
               p.nome as paziente_nome, 
               p.cognome as paziente_cognome,
               pr.nome as professionista_nome,
               pr.cognome as professionista_cognome
        FROM test t
        INNER JOIN pazienti p ON t.paziente_id = p.id
        INNER JOIN professionisti prof ON t.professionista_id = prof.id
        INNER JOIN utenti pr ON prof.utente_id = pr.id
        WHERE 1=1
    ";
    
    $params = [];
    
    // Filtri per professionista
    if ($auth->hasRole('professionista')) {
        $user = $auth->getCurrentUser();
        $professionista = $db->selectOne(
            "SELECT id FROM professionisti WHERE utente_id = :user_id",
            ['user_id' => $user->getId()]
        );
        
        if ($professionista) {
            $query .= " AND t.professionista_id = :prof_id";
            $params['prof_id'] = $professionista['id'];
        }
    }
    
    // Altri filtri
    if (isset($_GET['stato'])) {
        $query .= " AND t.stato = :stato";
        $params['stato'] = $_GET['stato'];
    }
    
    if (isset($_GET['tipo'])) {
        $query .= " AND t.tipo_test = :tipo";
        $params['tipo'] = $_GET['tipo'];
    }
    
    if (isset($_GET['paziente_id'])) {
        $query .= " AND t.paziente_id = :paziente_id";
        $params['paziente_id'] = $_GET['paziente_id'];
    }
    
    // Ordinamento
    $query .= " ORDER BY t.data_richiesta DESC";
    
    // Paginazione
    $page = intval($_GET['page'] ?? 1);
    $limit = intval($_GET['limit'] ?? 50);
    $offset = ($page - 1) * $limit;
    
    $query .= " LIMIT :limit OFFSET :offset";
    $params['limit'] = $limit;
    $params['offset'] = $offset;
    
    $tests = $db->select($query, $params);
    
    // Pseudonimizza per biologi
    if ($auth->hasRole('biologo')) {
        foreach ($tests as &$test) {
            $test['paziente_nome'] = substr($test['paziente_nome'], 0, 1) . '***';
            $test['paziente_cognome'] = substr($test['paziente_cognome'], 0, 1) . '***';
        }
    }
    
    jsonResponse([
        'success' => true,
        'data' => $tests,
        'pagination' => [
            'page' => $page,
            'limit' => $limit,
            'total' => $db->count('test', ['professionista_id' => $params['prof_id'] ?? null])
        ]
    ]);
}

/**
 * Crea nuovo test
 */
function handleCreateTest() {
    global $db, $auth;
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    // Validazione dati
    $required = ['paziente_id', 'tipo_test'];
    foreach ($required as $field) {
        if (empty($data[$field])) {
            jsonResponse(['success' => false, 'error' => "Campo $field obbligatorio"], 400);
        }
    }
    
    // Recupera ID professionista
    $user = $auth->getCurrentUser();
    $professionista = $db->selectOne(
        "SELECT id FROM professionisti WHERE utente_id = :user_id",
        ['user_id' => $user->getId()]
    );
    
    if (!$professionista) {
        jsonResponse(['success' => false, 'error' => 'Professionista non trovato'], 400);
    }
    
    // Verifica che il paziente appartenga al professionista
    $paziente = $db->selectOne(
        "SELECT id FROM pazienti WHERE id = :id AND professionista_id = :prof_id",
        ['id' => $data['paziente_id'], 'prof_id' => $professionista['id']]
    );
    
    if (!$paziente) {
        jsonResponse(['success' => false, 'error' => 'Paziente non trovato'], 404);
    }
    
    try {
        $db->beginTransaction();
        
        // Genera codice test univoco
        $codice = generateTestCode();
        
        // Crea test
        $testData = [
            'codice' => $codice,
            'paziente_id' => $data['paziente_id'],
            'professionista_id' => $professionista['id'],
            'tipo_test' => $data['tipo_test'],
            'stato' => 'richiesto',
            'note' => $data['note'] ?? null,
            'prezzo_totale' => 0,
            'prezzo_finale' => 0
        ];
        
        $testId = $db->insert('test', $testData);
        
        // Gestisci dettagli specifici per tipo test
        $prezzoTotale = 0;
        
        switch ($data['tipo_test']) {
            case 'genetico':
                if (!empty($data['geni'])) {
                    foreach ($data['geni'] as $geneId) {
                        $gene = $db->selectOne(
                            "SELECT prezzo FROM geni WHERE id = :id",
                            ['id' => $geneId]
                        );
                        if ($gene) {
                            $db->insert('test_genetici_dettagli', [
                                'test_id' => $testId,
                                'tipo_elemento' => 'gene',
                                'elemento_id' => $geneId,
                                'prezzo_unitario' => $gene['prezzo']
                            ]);
                            $prezzoTotale += $gene['prezzo'];
                        }
                    }
                }
                
                if (!empty($data['pannelli'])) {
                    foreach ($data['pannelli'] as $pannelloData) {
                        $pannello = $db->selectOne(
                            "SELECT prezzo FROM pannelli_genetici WHERE id = :id",
                            ['id' => $pannelloData['id']]
                        );
                        if ($pannello) {
                            $dettaglioId = $db->insert('test_genetici_dettagli', [
                                'test_id' => $testId,
                                'tipo_elemento' => 'pannello',
                                'elemento_id' => $pannelloData['id'],
                                'prezzo_unitario' => $pannello['prezzo']
                            ]);
                            $prezzoTotale += $pannello['prezzo'];
                            
                            // Geni aggiuntivi per pannello
                            if (!empty($pannelloData['geni_aggiuntivi'])) {
                                foreach ($pannelloData['geni_aggiuntivi'] as $geneId) {
                                    $gene = $db->selectOne(
                                        "SELECT prezzo FROM geni WHERE id = :id",
                                        ['id' => $geneId]
                                    );
                                    if ($gene) {
                                        $db->insert('test_genetici_geni_aggiuntivi', [
                                            'test_dettaglio_id' => $dettaglioId,
                                            'gene_id' => $geneId,
                                            'prezzo_unitario' => $gene['prezzo']
                                        ]);
                                        $prezzoTotale += $gene['prezzo'];
                                    }
                                }
                            }
                        }
                    }
                }
                break;
                
            case 'microbiota':
                if (!empty($data['tipi_microbiota'])) {
                    foreach ($data['tipi_microbiota'] as $tipoId) {
                        $tipo = $db->selectOne(
                            "SELECT prezzo FROM tipi_microbiota WHERE id = :id",
                            ['id' => $tipoId]
                        );
                        if ($tipo) {
                            $db->insert('test_microbiota_dettagli', [
                                'test_id' => $testId,
                                'tipo_microbiota_id' => $tipoId,
                                'prezzo_unitario' => $tipo['prezzo']
                            ]);
                            $prezzoTotale += $tipo['prezzo'];
                        }
                    }
                }
                break;
                
            case 'intolleranze_cito':
            case 'intolleranze_elisa':
                if (!empty($data['pannello_id'])) {
                    $pannello = $db->selectOne(
                        "SELECT prezzo FROM pannelli_intolleranze WHERE id = :id",
                        ['id' => $data['pannello_id']]
                    );
                    if ($pannello) {
                        $db->insert('test_intolleranze_dettagli', [
                            'test_id' => $testId,
                            'pannello_id' => $data['pannello_id'],
                            'prezzo_unitario' => $pannello['prezzo']
                        ]);
                        $prezzoTotale += $pannello['prezzo'];
                    }
                }
                break;
        }
        
        // Calcola prezzo finale con sconto
        $sconto = floatval($data['sconto'] ?? 0);
        $prezzoFinale = $prezzoTotale - ($prezzoTotale * $sconto / 100);
        
        // Aggiorna prezzi
        $db->update('test', [
            'prezzo_totale' => $prezzoTotale,
            'sconto' => $sconto,
            'prezzo_finale' => $prezzoFinale
        ], ['id' => $testId]);
        
        // Genera barcode/QR code
        $barcodePath = generateBarcode($codice);
        $qrcodePath = generateQRCode($codice);
        
        $db->update('test', [
            'barcode' => $barcodePath,
            'qrcode' => $qrcodePath
        ], ['id' => $testId]);
        
        $db->commit();
        
        jsonResponse([
            'success' => true,
            'data' => [
                'id' => $testId,
                'codice' => $codice
            ]
        ], 201);
        
    } catch (Exception $e) {
        $db->rollback();
        jsonResponse(['success' => false, 'error' => 'Errore nella creazione del test'], 500);
    }
}

/**
 * Aggiorna test
 */
function handleUpdateTest($testId) {
    global $db;
    
    if (!$testId) {
        jsonResponse(['success' => false, 'error' => 'ID test mancante'], 400);
    }
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    // Verifica che il test esista
    $test = $db->selectOne("SELECT * FROM test WHERE id = :id", ['id' => $testId]);
    if (!$test) {
        jsonResponse(['success' => false, 'error' => 'Test non trovato'], 404);
    }
    
    // Aggiorna solo campi consentiti
    $allowedFields = ['stato', 'note', 'sconto'];
    $updateData = [];
    
    foreach ($allowedFields as $field) {
        if (isset($data[$field])) {
            $updateData[$field] = $data[$field];
        }
    }
    
    if (!empty($updateData)) {
        // Ricalcola prezzo finale se cambia lo sconto
        if (isset($updateData['sconto'])) {
            $updateData['prezzo_finale'] = $test['prezzo_totale'] - 
                                          ($test['prezzo_totale'] * $updateData['sconto'] / 100);
        }
        
        $db->update('test', $updateData, ['id' => $testId]);
    }
    
    jsonResponse(['success' => true]);
}

/**
 * Elimina test
 */
function handleDeleteTest($testId) {
    global $db;
    
    if (!$testId) {
        jsonResponse(['success' => false, 'error' => 'ID test mancante'], 400);
    }
    
    // Verifica che il test possa essere eliminato
    $test = $db->selectOne("SELECT stato FROM test WHERE id = :id", ['id' => $testId]);
    
    if (!$test) {
        jsonResponse(['success' => false, 'error' => 'Test non trovato'], 404);
    }
    
    if (!in_array($test['stato'], ['richiesto'])) {
        jsonResponse(['success' => false, 'error' => 'Il test non può essere eliminato in questo stato'], 400);
    }
    
    $db->delete('test', ['id' => $testId]);
    
    jsonResponse(['success' => true]);
}

/**
 * Genera codice test univoco
 */
function generateTestCode() {
    global $db;
    
    do {
        $code = 'T' . date('Y') . str_pad(mt_rand(1, 999999), 6, '0', STR_PAD_LEFT);
        $exists = $db->count('test', ['codice' => $code]) > 0;
    } while ($exists);
    
    return $code;
}
