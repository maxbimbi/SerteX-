<?php
/**
 * API Patients - Gestione pazienti
 * SerteX+ Genetic Lab Portal
 */

require_once '../../includes/database.php';
require_once '../../includes/functions.php';
require_once '../../classes/Patient.php';
require_once '../../classes/Logger.php';

// Richiede autenticazione
requireAuth();

$db = Database::getInstance();
$logger = new Logger();
$auth = new Auth();
$method = $_SERVER['REQUEST_METHOD'];
$patientId = $_GET['id'] ?? null;

// Solo professionisti e amministratori possono gestire pazienti
if (!$auth->hasRole(['professionista', 'amministratore'])) {
    jsonResponse(['success' => false, 'error' => 'Accesso negato'], 403);
}

switch ($method) {
    case 'GET':
        if ($patientId) {
            handleGetPatient($patientId);
        } else {
            handleGetPatients();
        }
        break;
        
    case 'POST':
        handleCreatePatient();
        break;
        
    case 'PUT':
        handleUpdatePatient($patientId);
        break;
        
    case 'DELETE':
        handleDeletePatient($patientId);
        break;
        
    default:
        jsonResponse(['success' => false, 'error' => 'Metodo non supportato'], 405);
}

/**
 * Recupera dettagli paziente
 */
function handleGetPatient($patientId) {
    global $db, $auth;
    
    $patient = $db->selectOne("
        SELECT p.*, 
               pr.nome as professionista_nome,
               pr.cognome as professionista_cognome,
               COUNT(DISTINCT t.id) as num_test,
               MAX(t.data_richiesta) as ultimo_test
        FROM pazienti p
        INNER JOIN professionisti prof ON p.professionista_id = prof.id
        INNER JOIN utenti pr ON prof.utente_id = pr.id
        LEFT JOIN test t ON p.id = t.paziente_id
        WHERE p.id = :id
        GROUP BY p.id
    ", ['id' => $patientId]);
    
    if (!$patient) {
        jsonResponse(['success' => false, 'error' => 'Paziente non trovato'], 404);
    }
    
    // Verifica accesso
    if ($auth->hasRole('professionista')) {
        $user = $auth->getCurrentUser();
        $professionista = $db->selectOne(
            "SELECT id FROM professionisti WHERE utente_id = :user_id",
            ['user_id' => $user->getId()]
        );
        
        if (!$professionista || $patient['professionista_id'] != $professionista['id']) {
            jsonResponse(['success' => false, 'error' => 'Accesso negato'], 403);
        }
    }
    
    // Calcola età
    if ($patient['data_nascita']) {
        $dataNascita = new DateTime($patient['data_nascita']);
        $oggi = new DateTime();
        $patient['eta'] = $oggi->diff($dataNascita)->y;
    }
    
    // Test recenti
    $patient['test_recenti'] = $db->select("
        SELECT id, codice, tipo_test, data_richiesta, stato
        FROM test
        WHERE paziente_id = :paziente_id
        ORDER BY data_richiesta DESC
        LIMIT 5
    ", ['paziente_id' => $patientId]);
    
    jsonResponse([
        'success' => true,
        'data' => $patient
    ]);
}

/**
 * Lista pazienti
 */
function handleGetPatients() {
    global $db, $auth;
    
    $query = "
        SELECT p.*, 
               COUNT(DISTINCT t.id) as num_test,
               MAX(t.data_richiesta) as ultimo_test
        FROM pazienti p
        LEFT JOIN test t ON p.id = t.paziente_id
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
            $query .= " AND p.professionista_id = :prof_id";
            $params['prof_id'] = $professionista['id'];
        } else {
            jsonResponse(['success' => false, 'error' => 'Professionista non trovato'], 400);
        }
    }
    
    // Ricerca
    if (isset($_GET['search'])) {
        $query .= " AND (p.nome LIKE :search 
                        OR p.cognome LIKE :search 
                        OR p.codice_fiscale LIKE :search
                        OR p.email LIKE :search
                        OR p.telefono LIKE :search)";
        $params['search'] = '%' . $_GET['search'] . '%';
    }
    
    // Filtro sesso
    if (isset($_GET['sesso'])) {
        $query .= " AND p.sesso = :sesso";
        $params['sesso'] = $_GET['sesso'];
    }
    
    // Filtro per pazienti con test recenti
    if (isset($_GET['con_test_recenti']) && $_GET['con_test_recenti'] === 'true') {
        $query .= " AND EXISTS (
            SELECT 1 FROM test t2 
            WHERE t2.paziente_id = p.id 
            AND t2.data_richiesta >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
        )";
    }
    
    $query .= " GROUP BY p.id";
    
    // Ordinamento
    $orderBy = $_GET['order_by'] ?? 'cognome';
    $orderDir = $_GET['order_dir'] ?? 'ASC';
    
    $allowedOrderBy = ['nome', 'cognome', 'data_creazione', 'ultimo_test', 'num_test'];
    if (in_array($orderBy, $allowedOrderBy)) {
        $query .= " ORDER BY {$orderBy} {$orderDir}";
    } else {
        $query .= " ORDER BY p.cognome ASC, p.nome ASC";
    }
    
    // Paginazione
    $page = intval($_GET['page'] ?? 1);
    $limit = intval($_GET['limit'] ?? 50);
    $offset = ($page - 1) * $limit;
    
    // Conta totale
    $countQuery = "
        SELECT COUNT(DISTINCT p.id) as total 
        FROM pazienti p 
        WHERE 1=1
    ";
    
    if (isset($params['prof_id'])) {
        $countQuery .= " AND p.professionista_id = :prof_id";
    }
    if (isset($params['search'])) {
        $countQuery .= " AND (p.nome LIKE :search 
                             OR p.cognome LIKE :search 
                             OR p.codice_fiscale LIKE :search
                             OR p.email LIKE :search
                             OR p.telefono LIKE :search)";
    }
    if (isset($params['sesso'])) {
        $countQuery .= " AND p.sesso = :sesso";
    }
    
    $total = $db->selectOne($countQuery, $params)['total'];
    
    // Aggiungi limit
    $query .= " LIMIT :limit OFFSET :offset";
    $params['limit'] = $limit;
    $params['offset'] = $offset;
    
    $patients = $db->select($query, $params);
    
    // Calcola età per ogni paziente
    foreach ($patients as &$patient) {
        if ($patient['data_nascita']) {
            $dataNascita = new DateTime($patient['data_nascita']);
            $oggi = new DateTime();
            $patient['eta'] = $oggi->diff($dataNascita)->y;
        }
    }
    
    jsonResponse([
        'success' => true,
        'data' => $patients,
        'pagination' => [
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
            'pages' => ceil($total / $limit)
        ]
    ]);
}

/**
 * Crea nuovo paziente
 */
function handleCreatePatient() {
    global $db, $logger, $auth;
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    // Validazione campi obbligatori
    $required = ['nome', 'cognome', 'codice_fiscale'];
    foreach ($required as $field) {
        if (empty($data[$field])) {
            jsonResponse([
                'success' => false,
                'error' => "Campo $field obbligatorio"
            ], 400);
        }
    }
    
    // Validazione codice fiscale
    $cf = strtoupper(trim($data['codice_fiscale']));
    if (!isValidCodiceFiscale($cf)) {
        jsonResponse([
            'success' => false,
            'error' => 'Codice fiscale non valido'
        ], 400);
    }
    
    // Recupera ID professionista
    $user = $auth->getCurrentUser();
    if ($auth->hasRole('professionista')) {
        $professionista = $db->selectOne(
            "SELECT id FROM professionisti WHERE utente_id = :user_id",
            ['user_id' => $user->getId()]
        );
        
        if (!$professionista) {
            jsonResponse(['success' => false, 'error' => 'Professionista non trovato'], 400);
        }
        
        $professionistaId = $professionista['id'];
    } else {
        // Admin deve specificare il professionista
        if (empty($data['professionista_id'])) {
            jsonResponse([
                'success' => false,
                'error' => 'ID professionista obbligatorio'
            ], 400);
        }
        $professionistaId = $data['professionista_id'];
    }
    
    // Verifica unicità CF per professionista
    $existing = $db->selectOne(
        "SELECT id FROM pazienti WHERE codice_fiscale = :cf AND professionista_id = :prof_id",
        ['cf' => $cf, 'prof_id' => $professionistaId]
    );
    
    if ($existing) {
        jsonResponse([
            'success' => false,
            'error' => 'Paziente con questo codice fiscale già presente'
        ], 400);
    }
    
    // Validazione email se fornita
    if (!empty($data['email']) && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
        jsonResponse([
            'success' => false,
            'error' => 'Email non valida'
        ], 400);
    }
    
    // Validazione data nascita
    if (!empty($data['data_nascita'])) {
        $dataNascita = DateTime::createFromFormat('Y-m-d', $data['data_nascita']);
        if (!$dataNascita || $dataNascita > new DateTime()) {
            jsonResponse([
                'success' => false,
                'error' => 'Data di nascita non valida'
            ], 400);
        }
    }
    
    // Validazione sesso
    if (!empty($data['sesso']) && !in_array($data['sesso'], ['M', 'F'])) {
        jsonResponse([
            'success' => false,
            'error' => 'Sesso non valido (M o F)'
        ], 400);
    }
    
    try {
        // Prepara dati
        $patientData = [
            'professionista_id' => $professionistaId,
            'nome' => sanitizeInput($data['nome']),
            'cognome' => sanitizeInput($data['cognome']),
            'codice_fiscale' => $cf,
            'data_nascita' => $data['data_nascita'] ?? null,
            'sesso' => $data['sesso'] ?? null,
            'email' => sanitizeInput($data['email'] ?? ''),
            'telefono' => sanitizeInput($data['telefono'] ?? ''),
            'indirizzo' => sanitizeInput($data['indirizzo'] ?? '')
        ];
        
        $patientId = $db->insert('pazienti', $patientData);
        
        // Log
        $logger->log($user->getId(), 'paziente_creato', 
                    "Creato paziente: {$patientData['nome']} {$patientData['cognome']} (CF: {$cf})");
        
        jsonResponse([
            'success' => true,
            'message' => 'Paziente creato con successo',
            'data' => ['id' => $patientId]
        ], 201);
        
    } catch (Exception $e) {
        error_log("Errore creazione paziente: " . $e->getMessage());
        jsonResponse([
            'success' => false,
            'error' => 'Errore nella creazione del paziente'
        ], 500);
    }
}

/**
 * Aggiorna paziente
 */
function handleUpdatePatient($patientId) {
    global $db, $logger, $auth;
    
    if (!$patientId) {
        jsonResponse(['success' => false, 'error' => 'ID paziente mancante'], 400);
    }
    
    // Verifica che il paziente esista
    $patient = $db->selectOne(
        "SELECT * FROM pazienti WHERE id = :id",
        ['id' => $patientId]
    );
    
    if (!$patient) {
        jsonResponse(['success' => false, 'error' => 'Paziente non trovato'], 404);
    }
    
    // Verifica accesso
    if ($auth->hasRole('professionista')) {
        $user = $auth->getCurrentUser();
        $professionista = $db->selectOne(
            "SELECT id FROM professionisti WHERE utente_id = :user_id",
            ['user_id' => $user->getId()]
        );
        
        if (!$professionista || $patient['professionista_id'] != $professionista['id']) {
            jsonResponse(['success' => false, 'error' => 'Accesso negato'], 403);
        }
    }
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    // Campi aggiornabili
    $allowedFields = ['nome', 'cognome', 'data_nascita', 'sesso', 'email', 'telefono', 'indirizzo'];
    $updateData = [];
    
    foreach ($allowedFields as $field) {
        if (isset($data[$field])) {
            // Validazioni specifiche
            if ($field === 'email' && !empty($data['email'])) {
                if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
                    jsonResponse([
                        'success' => false,
                        'error' => 'Email non valida'
                    ], 400);
                }
            }
            
            if ($field === 'data_nascita' && !empty($data['data_nascita'])) {
                $dataNascita = DateTime::createFromFormat('Y-m-d', $data['data_nascita']);
                if (!$dataNascita || $dataNascita > new DateTime()) {
                    jsonResponse([
                        'success' => false,
                        'error' => 'Data di nascita non valida'
                    ], 400);
                }
            }
            
            if ($field === 'sesso' && !empty($data['sesso'])) {
                if (!in_array($data['sesso'], ['M', 'F'])) {
                    jsonResponse([
                        'success' => false,
                        'error' => 'Sesso non valido (M o F)'
                    ], 400);
                }
            }
            
            $updateData[$field] = $field === 'data_nascita' || $field === 'sesso' ? 
                                  $data[$field] : sanitizeInput($data[$field]);
        }
    }
    
    if (empty($updateData)) {
        jsonResponse([
            'success' => false,
            'error' => 'Nessun dato da aggiornare'
        ], 400);
    }
    
    try {
        $db->update('pazienti', $updateData, ['id' => $patientId]);
        
        // Log
        $user = $auth->getCurrentUser();
        $logger->log($user->getId(), 'paziente_modificato', 
                    "Modificato paziente ID: {$patientId}");
        
        jsonResponse([
            'success' => true,
            'message' => 'Paziente aggiornato con successo'
        ]);
        
    } catch (Exception $e) {
        error_log("Errore aggiornamento paziente: " . $e->getMessage());
        jsonResponse([
            'success' => false,
            'error' => 'Errore nell\'aggiornamento del paziente'
        ], 500);
    }
}

/**
 * Elimina paziente
 */
function handleDeletePatient($patientId) {
    global $db, $logger, $auth;
    
    if (!$patientId) {
        jsonResponse(['success' => false, 'error' => 'ID paziente mancante'], 400);
    }
    
    // Verifica che il paziente esista
    $patient = $db->selectOne(
        "SELECT * FROM pazienti WHERE id = :id",
        ['id' => $patientId]
    );
    
    if (!$patient) {
        jsonResponse(['success' => false, 'error' => 'Paziente non trovato'], 404);
    }
    
    // Verifica accesso
    if ($auth->hasRole('professionista')) {
        $user = $auth->getCurrentUser();
        $professionista = $db->selectOne(
            "SELECT id FROM professionisti WHERE utente_id = :user_id",
            ['user_id' => $user->getId()]
        );
        
        if (!$professionista || $patient['professionista_id'] != $professionista['id']) {
            jsonResponse(['success' => false, 'error' => 'Accesso negato'], 403);
        }
    }
    
    // Verifica che non ci siano test associati
    $testCount = $db->count('test', ['paziente_id' => $patientId]);
    
    if ($testCount > 0) {
        jsonResponse([
            'success' => false,
            'error' => 'Impossibile eliminare il paziente perché ha test associati'
        ], 400);
    }
    
    try {
        $db->delete('pazienti', ['id' => $patientId]);
        
        // Log
        $user = $auth->getCurrentUser();
        $logger->log($user->getId(), 'paziente_eliminato', 
                    "Eliminato paziente: {$patient['nome']} {$patient['cognome']} (ID: {$patientId})");
        
        jsonResponse([
            'success' => true,
            'message' => 'Paziente eliminato con successo'
        ]);
        
    } catch (Exception $e) {
        error_log("Errore eliminazione paziente: " . $e->getMessage());
        jsonResponse([
            'success' => false,
            'error' => 'Errore nell\'eliminazione del paziente'
        ], 500);
    }
}

/**
 * Validazione codice fiscale italiano
 */
function isValidCodiceFiscale($cf) {
    // Validazione base: 16 caratteri alfanumerici
    if (!preg_match('/^[A-Z0-9]{16}$/', $cf)) {
        return false;
    }
    
    // TODO: Implementare validazione completa del CF con checksum
    // Per ora accetta qualsiasi stringa di 16 caratteri
    
    return true;
}
