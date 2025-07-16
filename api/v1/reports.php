<?php
/**
 * API Reports - Gestione referti
 * SerteX+ Genetic Lab Portal
 */

require_once '../../includes/database.php';
require_once '../../includes/functions.php';
require_once '../../classes/Report.php';
require_once '../../classes/Logger.php';

// Richiede autenticazione
requireAuth();

$db = Database::getInstance();
$logger = new Logger();
$auth = new Auth();
$method = $_SERVER['REQUEST_METHOD'];
$reportId = $_GET['id'] ?? null;

switch ($method) {
    case 'GET':
        if ($reportId) {
            handleGetReport($reportId);
        } else {
            handleGetReports();
        }
        break;
        
    case 'POST':
        requireRole(['biologo', 'amministratore']);
        handleGenerateReport();
        break;
        
    case 'PUT':
        requireRole(['biologo', 'amministratore']);
        handleUpdateReport($reportId);
        break;
        
    case 'DELETE':
        requireRole('amministratore');
        handleDeleteReport($reportId);
        break;
        
    default:
        jsonResponse(['success' => false, 'error' => 'Metodo non supportato'], 405);
}

/**
 * Recupera dettagli referto
 */
function handleGetReport($reportId) {
    global $db, $auth;
    
    $report = $db->selectOne("
        SELECT r.*, 
               t.codice as test_codice,
               t.tipo_test,
               t.stato as test_stato,
               p.nome as paziente_nome,
               p.cognome as paziente_cognome,
               p.codice_fiscale,
               b.nome as biologo_nome,
               b.cognome as biologo_cognome
        FROM referti r
        INNER JOIN test t ON r.test_id = t.id
        INNER JOIN pazienti p ON t.paziente_id = p.id
        INNER JOIN utenti b ON r.biologo_id = b.id
        WHERE r.id = :id
    ", ['id' => $reportId]);
    
    if (!$report) {
        jsonResponse(['success' => false, 'error' => 'Referto non trovato'], 404);
    }
    
    // Controllo accesso
    if ($auth->hasRole('professionista')) {
        $user = $auth->getCurrentUser();
        $professionista = $db->selectOne(
            "SELECT p.professionista_id 
             FROM test t 
             INNER JOIN pazienti p ON t.paziente_id = p.id
             WHERE t.id = :test_id",
            ['test_id' => $report['test_id']]
        );
        
        $userProf = $db->selectOne(
            "SELECT id FROM professionisti WHERE utente_id = :user_id",
            ['user_id' => $user->getId()]
        );
        
        if (!$userProf || $professionista['professionista_id'] != $userProf['id']) {
            jsonResponse(['success' => false, 'error' => 'Accesso negato'], 403);
        }
    }
    
    // Verifica integrità file
    if ($report['hash_file']) {
        $currentHash = hash_file('sha256', '../' . $report['file_path']);
        $report['file_integrity'] = $currentHash === $report['hash_file'];
    }
    
    // Informazioni download
    $report['download_url'] = '/api/v1/reports/' . $reportId . '/download';
    if ($report['file_path_firmato']) {
        $report['download_signed_url'] = '/api/v1/reports/' . $reportId . '/download?signed=true';
    }
    
    // Pseudonimizza per biologi
    if ($auth->hasRole('biologo')) {
        $report['paziente_nome'] = substr($report['paziente_nome'], 0, 1) . '***';
        $report['paziente_cognome'] = substr($report['paziente_cognome'], 0, 1) . '***';
        $report['codice_fiscale'] = substr($report['codice_fiscale'], 0, 3) . '***' . 
                                   substr($report['codice_fiscale'], -3);
    }
    
    jsonResponse([
        'success' => true,
        'data' => $report
    ]);
}

/**
 * Lista referti
 */
function handleGetReports() {
    global $db, $auth;
    
    $query = "
        SELECT r.id, r.test_id, r.tipo_referto, r.data_creazione, r.data_firma,
               r.file_path IS NOT NULL as has_file,
               r.file_path_firmato IS NOT NULL as is_signed,
               t.codice as test_codice,
               t.tipo_test,
               t.stato as test_stato,
               t.data_richiesta,
               p.nome as paziente_nome,
               p.cognome as paziente_cognome,
               p.codice_fiscale,
               b.nome as biologo_nome,
               b.cognome as biologo_cognome
        FROM referti r
        INNER JOIN test t ON r.test_id = t.id
        INNER JOIN pazienti p ON t.paziente_id = p.id
        INNER JOIN utenti b ON r.biologo_id = b.id
        WHERE 1=1
    ";
    
    $params = [];
    
    // Filtro per professionista
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
    
    // Filtri
    if (isset($_GET['test_id'])) {
        $query .= " AND r.test_id = :test_id";
        $params['test_id'] = $_GET['test_id'];
    }
    
    if (isset($_GET['tipo_referto'])) {
        $query .= " AND r.tipo_referto = :tipo_referto";
        $params['tipo_referto'] = $_GET['tipo_referto'];
    }
    
    if (isset($_GET['firmato'])) {
        if ($_GET['firmato'] === 'true') {
            $query .= " AND r.file_path_firmato IS NOT NULL";
        } else {
            $query .= " AND r.file_path_firmato IS NULL";
        }
    }
    
    if (isset($_GET['biologo_id'])) {
        $query .= " AND r.biologo_id = :biologo_id";
        $params['biologo_id'] = $_GET['biologo_id'];
    }
    
    // Ricerca
    if (isset($_GET['search'])) {
        $query .= " AND (t.codice LIKE :search 
                        OR CONCAT(p.nome, ' ', p.cognome) LIKE :search
                        OR p.codice_fiscale LIKE :search)";
        $params['search'] = '%' . $_GET['search'] . '%';
    }
    
    // Date range
    if (isset($_GET['data_da'])) {
        $query .= " AND r.data_creazione >= :data_da";
        $params['data_da'] = $_GET['data_da'] . ' 00:00:00';
    }
    
    if (isset($_GET['data_a'])) {
        $query .= " AND r.data_creazione <= :data_a";
        $params['data_a'] = $_GET['data_a'] . ' 23:59:59';
    }
    
    // Ordinamento
    $query .= " ORDER BY r.data_creazione DESC";
    
    // Paginazione
    $page = intval($_GET['page'] ?? 1);
    $limit = intval($_GET['limit'] ?? 50);
    $offset = ($page - 1) * $limit;
    
    $query .= " LIMIT :limit OFFSET :offset";
    $params['limit'] = $limit;
    $params['offset'] = $offset;
    
    $reports = $db->select($query, $params);
    
    // Pseudonimizza per biologi
    if ($auth->hasRole('biologo')) {
        foreach ($reports as &$report) {
            $report['paziente_nome'] = substr($report['paziente_nome'], 0, 1) . '***';
            $report['paziente_cognome'] = substr($report['paziente_cognome'], 0, 1) . '***';
            $report['codice_fiscale'] = substr($report['codice_fiscale'], 0, 3) . '***' . 
                                       substr($report['codice_fiscale'], -3);
        }
    }
    
    // Aggiungi URL download
    foreach ($reports as &$report) {
        $report['download_url'] = '/api/v1/reports/' . $report['id'] . '/download';
        if ($report['is_signed']) {
            $report['download_signed_url'] = '/api/v1/reports/' . $report['id'] . '/download?signed=true';
        }
    }
    
    jsonResponse([
        'success' => true,
        'data' => $reports,
        'pagination' => [
            'page' => $page,
            'limit' => $limit,
            'total' => $db->count('referti', $params)
        ]
    ]);
}

/**
 * Genera nuovo referto
 */
function handleGenerateReport() {
    global $db, $logger, $auth;
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (empty($data['test_id'])) {
        jsonResponse([
            'success' => false,
            'error' => 'ID test obbligatorio'
        ], 400);
    }
    
    // Verifica che il test esista e sia pronto
    $test = $db->selectOne(
        "SELECT * FROM test WHERE id = :id AND stato = 'eseguito'",
        ['id' => $data['test_id']]
    );
    
    if (!$test) {
        jsonResponse([
            'success' => false,
            'error' => 'Test non trovato o non pronto per la refertazione'
        ], 404);
    }
    
    // Verifica che non esista già un referto
    $existingReport = $db->selectOne(
        "SELECT id FROM referti WHERE test_id = :test_id",
        ['test_id' => $data['test_id']]
    );
    
    if ($existingReport) {
        jsonResponse([
            'success' => false,
            'error' => 'Referto già esistente per questo test'
        ], 400);
    }
    
    try {
        $report = new Report();
        $user = $auth->getCurrentUser();
        
        // Genera referto
        $result = $report->generateReport($data['test_id'], $user->getId());
        
        if (!$result['success']) {
            jsonResponse([
                'success' => false,
                'error' => 'Errore nella generazione del referto: ' . $result['error']
            ], 500);
        }
        
        // Aggiorna stato test
        $db->update('test', [
            'stato' => 'refertato',
            'data_refertazione' => date('Y-m-d H:i:s')
        ], ['id' => $data['test_id']]);
        
        // Log
        $logger->log($user->getId(), 'referto_generato', "Test ID: {$data['test_id']}");
        
        // Recupera referto creato
        $newReport = $db->selectOne(
            "SELECT * FROM referti WHERE test_id = :test_id",
            ['test_id' => $data['test_id']]
        );
        
        jsonResponse([
            'success' => true,
            'message' => 'Referto generato con successo',
            'data' => [
                'id' => $newReport['id'],
                'file_path' => $newReport['file_path']
            ]
        ], 201);
        
    } catch (Exception $e) {
        error_log("Errore generazione referto: " . $e->getMessage());
        jsonResponse([
            'success' => false,
            'error' => 'Errore nella generazione del referto'
        ], 500);
    }
}

/**
 * Aggiorna referto (carica file firmato)
 */
function handleUpdateReport($reportId) {
    global $db, $logger, $auth;
    
    if (!$reportId) {
        jsonResponse(['success' => false, 'error' => 'ID referto mancante'], 400);
    }
    
    $report = $db->selectOne(
        "SELECT * FROM referti WHERE id = :id",
        ['id' => $reportId]
    );
    
    if (!$report) {
        jsonResponse(['success' => false, 'error' => 'Referto non trovato'], 404);
    }
    
    // Verifica che sia il biologo che ha creato il referto o un admin
    $user = $auth->getCurrentUser();
    if (!$auth->hasRole('amministratore') && $report['biologo_id'] != $user->getId()) {
        jsonResponse(['success' => false, 'error' => 'Accesso negato'], 403);
    }
    
    // Gestione upload file firmato
    if (!empty($_FILES['referto_firmato'])) {
        $allowedTypes = ['pdf', 'p7m'];
        $fileExt = strtolower(pathinfo($_FILES['referto_firmato']['name'], PATHINFO_EXTENSION));
        
        if (!in_array($fileExt, $allowedTypes)) {
            jsonResponse([
                'success' => false,
                'error' => 'Tipo file non consentito. Usare PDF o P7M'
            ], 400);
        }
        
        $uploadResult = uploadFile($_FILES['referto_firmato'], 'referti', $allowedTypes);
        
        if (!$uploadResult['success']) {
            jsonResponse([
                'success' => false,
                'error' => 'Errore upload file: ' . $uploadResult['error']
            ], 500);
        }
        
        // Aggiorna referto
        $db->update('referti', [
            'file_path_firmato' => $uploadResult['path'],
            'data_firma' => date('Y-m-d H:i:s')
        ], ['id' => $reportId]);
        
        // Aggiorna stato test
        $db->update('test', ['stato' => 'firmato'], ['id' => $report['test_id']]);
        
        // Log
        $logger->log($user->getId(), 'referto_firmato', "Referto ID: {$reportId}");
        
        jsonResponse([
            'success' => true,
            'message' => 'Referto firmato caricato con successo'
        ]);
        
    } else {
        jsonResponse([
            'success' => false,
            'error' => 'Nessun file caricato'
        ], 400);
    }
}

/**
 * Elimina referto
 */
function handleDeleteReport($reportId) {
    global $db, $logger, $auth;
    
    if (!$reportId) {
        jsonResponse(['success' => false, 'error' => 'ID referto mancante'], 400);
    }
    
    $report = $db->selectOne(
        "SELECT * FROM referti WHERE id = :id",
        ['id' => $reportId]
    );
    
    if (!$report) {
        jsonResponse(['success' => false, 'error' => 'Referto non trovato'], 404);
    }
    
    try {
        // Elimina file fisici
        if ($report['file_path'] && file_exists('../' . $report['file_path'])) {
            unlink('../' . $report['file_path']);
        }
        
        if ($report['file_path_firmato'] && file_exists('../' . $report['file_path_firmato'])) {
            unlink('../' . $report['file_path_firmato']);
        }
        
        // Elimina record database
        $db->delete('referti', ['id' => $reportId]);
        
        // Aggiorna stato test
        $db->update('test', [
            'stato' => 'eseguito',
            'data_refertazione' => null
        ], ['id' => $report['test_id']]);
        
        // Log
        $user = $auth->getCurrentUser();
        $logger->log($user->getId(), 'referto_eliminato', "Referto ID: {$reportId}");
        
        jsonResponse([
            'success' => true,
            'message' => 'Referto eliminato con successo'
        ]);
        
    } catch (Exception $e) {
        error_log("Errore eliminazione referto: " . $e->getMessage());
        jsonResponse([
            'success' => false,
            'error' => 'Errore nell\'eliminazione del referto'
        ], 500);
    }
}

/**
 * Download referto
 * Gestito separatamente per permettere download diretto
 */
if (isset($_GET['action']) && $_GET['action'] === 'download') {
    requireAuth();
    
    $reportId = $_GET['id'] ?? null;
    if (!$reportId) {
        jsonResponse(['success' => false, 'error' => 'ID referto mancante'], 400);
    }
    
    $report = $db->selectOne(
        "SELECT r.*, t.professionista_id, p.codice_fiscale
         FROM referti r
         INNER JOIN test t ON r.test_id = t.id
         INNER JOIN pazienti p ON t.paziente_id = p.id
         WHERE r.id = :id",
        ['id' => $reportId]
    );
    
    if (!$report) {
        jsonResponse(['success' => false, 'error' => 'Referto non trovato'], 404);
    }
    
    // Controllo accesso
    if ($auth->hasRole('professionista')) {
        $user = $auth->getCurrentUser();
        $userProf = $db->selectOne(
            "SELECT id FROM professionisti WHERE utente_id = :user_id",
            ['user_id' => $user->getId()]
        );
        
        if (!$userProf || $report['professionista_id'] != $userProf['id']) {
            jsonResponse(['success' => false, 'error' => 'Accesso negato'], 403);
        }
    }
    
    // Determina quale file scaricare
    $signed = isset($_GET['signed']) && $_GET['signed'] === 'true';
    $filePath = $signed && $report['file_path_firmato'] ? 
                $report['file_path_firmato'] : $report['file_path'];
    
    if (!$filePath || !file_exists('../' . $filePath)) {
        jsonResponse(['success' => false, 'error' => 'File non trovato'], 404);
    }
    
    // Invia file
    $fileName = 'referto_' . $report['test_id'] . '_' . date('Ymd', strtotime($report['data_creazione']));
    $fileName .= $signed ? '_firmato' : '';
    $fileName .= '.' . pathinfo($filePath, PATHINFO_EXTENSION);
    
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $fileName . '"');
    header('Content-Length: ' . filesize('../' . $filePath));
    header('Cache-Control: private, max-age=0, must-revalidate');
    header('Pragma: public');
    
    readfile('../' . $filePath);
    exit;
}