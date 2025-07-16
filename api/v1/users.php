<?php
/**
 * API Users - Gestione utenti
 * SerteX+ Genetic Lab Portal
 */

require_once '../../includes/database.php';
require_once '../../includes/functions.php';
require_once '../../includes/security.php';
require_once '../../classes/User.php';
require_once '../../classes/Logger.php';

// Solo amministratori possono gestire utenti
requireRole('amministratore');

$db = Database::getInstance();
$logger = new Logger();
$method = $_SERVER['REQUEST_METHOD'];
$userId = $_GET['id'] ?? null;

switch ($method) {
    case 'GET':
        if ($userId) {
            handleGetUser($userId);
        } else {
            handleGetUsers();
        }
        break;
        
    case 'POST':
        handleCreateUser();
        break;
        
    case 'PUT':
        handleUpdateUser($userId);
        break;
        
    case 'DELETE':
        handleDeleteUser($userId);
        break;
        
    default:
        jsonResponse(['success' => false, 'error' => 'Metodo non supportato'], 405);
}

/**
 * Recupera dettagli utente
 */
function handleGetUser($userId) {
    global $db;
    
    $user = $db->selectOne("
        SELECT u.*, 
               COUNT(DISTINCT s.id) as sessioni_attive,
               MAX(l.timestamp) as ultimo_log
        FROM utenti u
        LEFT JOIN sessioni s ON u.id = s.utente_id
        LEFT JOIN log_attivita l ON u.id = l.utente_id
        WHERE u.id = :id
        GROUP BY u.id
    ", ['id' => $userId]);
    
    if (!$user) {
        jsonResponse(['success' => false, 'error' => 'Utente non trovato'], 404);
    }
    
    // Rimuovi password dall'output
    unset($user['password']);
    unset($user['two_factor_secret']);
    
    // Aggiungi dati specifici per tipo utente
    if ($user['tipo_utente'] === 'professionista') {
        $user['professionista'] = $db->selectOne(
            "SELECT * FROM professionisti WHERE utente_id = :user_id",
            ['user_id' => $userId]
        );
    }
    
    // Statistiche utente
    if ($user['tipo_utente'] === 'biologo') {
        $user['stats'] = [
            'test_refertati' => $db->count('referti', ['biologo_id' => $userId]),
            'test_in_corso' => $db->count('test', [
                'stato' => 'in_lavorazione',
                'biologo_id' => $userId
            ])
        ];
    } elseif ($user['tipo_utente'] === 'professionista') {
        $profId = $user['professionista']['id'] ?? 0;
        $user['stats'] = [
            'pazienti' => $db->count('pazienti', ['professionista_id' => $profId]),
            'test_totali' => $db->count('test', ['professionista_id' => $profId]),
            'test_mese' => $db->selectOne("
                SELECT COUNT(*) as count 
                FROM test 
                WHERE professionista_id = :prof_id 
                AND MONTH(data_richiesta) = MONTH(CURRENT_DATE())
                AND YEAR(data_richiesta) = YEAR(CURRENT_DATE())
            ", ['prof_id' => $profId])['count']
        ];
    }
    
    jsonResponse([
        'success' => true,
        'data' => $user
    ]);
}

/**
 * Lista utenti
 */
function handleGetUsers() {
    global $db;
    
    $query = "
        SELECT u.id, u.username, u.email, u.nome, u.cognome, 
               u.tipo_utente, u.attivo, u.data_creazione,
               u.data_ultimo_accesso, u.bloccato,
               COUNT(DISTINCT s.id) as sessioni_attive
        FROM utenti u
        LEFT JOIN sessioni s ON u.id = s.utente_id
        WHERE 1=1
    ";
    
    $params = [];
    
    // Filtri
    if (isset($_GET['tipo'])) {
        $query .= " AND u.tipo_utente = :tipo";
        $params['tipo'] = $_GET['tipo'];
    }
    
    if (isset($_GET['attivo'])) {
        $query .= " AND u.attivo = :attivo";
        $params['attivo'] = $_GET['attivo'] === 'true' ? 1 : 0;
    }
    
    if (isset($_GET['search'])) {
        $query .= " AND (u.username LIKE :search 
                        OR u.email LIKE :search 
                        OR CONCAT(u.nome, ' ', u.cognome) LIKE :search)";
        $params['search'] = '%' . $_GET['search'] . '%';
    }
    
    $query .= " GROUP BY u.id ORDER BY u.data_creazione DESC";
    
    // Paginazione
    $page = intval($_GET['page'] ?? 1);
    $limit = intval($_GET['limit'] ?? 50);
    $offset = ($page - 1) * $limit;
    
    // Conta totale
    $countQuery = "SELECT COUNT(DISTINCT u.id) as total FROM utenti u WHERE 1=1";
    if (isset($params['tipo'])) {
        $countQuery .= " AND u.tipo_utente = :tipo";
    }
    if (isset($params['attivo'])) {
        $countQuery .= " AND u.attivo = :attivo";
    }
    if (isset($params['search'])) {
        $countQuery .= " AND (u.username LIKE :search 
                             OR u.email LIKE :search 
                             OR CONCAT(u.nome, ' ', u.cognome) LIKE :search)";
    }
    
    $total = $db->selectOne($countQuery, $params)['total'];
    
    // Aggiungi limit
    $query .= " LIMIT :limit OFFSET :offset";
    $params['limit'] = $limit;
    $params['offset'] = $offset;
    
    $users = $db->select($query, $params);
    
    // Rimuovi dati sensibili
    foreach ($users as &$user) {
        unset($user['password']);
        unset($user['two_factor_secret']);
    }
    
    jsonResponse([
        'success' => true,
        'data' => $users,
        'pagination' => [
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
            'pages' => ceil($total / $limit)
        ]
    ]);
}

/**
 * Crea nuovo utente
 */
function handleCreateUser() {
    global $db, $logger, $auth;
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    // Validazione campi obbligatori
    $required = ['username', 'password', 'email', 'nome', 'cognome', 'tipo_utente'];
    foreach ($required as $field) {
        if (empty($data[$field])) {
            jsonResponse([
                'success' => false,
                'error' => "Campo $field obbligatorio"
            ], 400);
        }
    }
    
    // Validazione username
    if (!preg_match('/^[a-zA-Z0-9_]{3,50}$/', $data['username'])) {
        jsonResponse([
            'success' => false,
            'error' => 'Username non valido (3-50 caratteri, solo lettere, numeri e underscore)'
        ], 400);
    }
    
    // Verifica unicità username
    if ($db->count('utenti', ['username' => $data['username']]) > 0) {
        jsonResponse([
            'success' => false,
            'error' => 'Username già in uso'
        ], 400);
    }
    
    // Validazione email
    if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
        jsonResponse([
            'success' => false,
            'error' => 'Email non valida'
        ], 400);
    }
    
    // Verifica unicità email
    if ($db->count('utenti', ['email' => $data['email']]) > 0) {
        jsonResponse([
            'success' => false,
            'error' => 'Email già in uso'
        ], 400);
    }
    
    // Validazione password
    $passwordValidation = Security::validatePassword($data['password']);
    if (!$passwordValidation['valid']) {
        jsonResponse([
            'success' => false,
            'error' => $passwordValidation['message']
        ], 400);
    }
    
    // Validazione tipo utente
    $tipiValidi = ['amministratore', 'biologo', 'professionista', 'commerciale'];
    if (!in_array($data['tipo_utente'], $tipiValidi)) {
        jsonResponse([
            'success' => false,
            'error' => 'Tipo utente non valido'
        ], 400);
    }
    
    try {
        $db->beginTransaction();
        
        // Crea utente
        $userData = [
            'username' => sanitizeInput($data['username']),
            'password' => password_hash($data['password'], PASSWORD_DEFAULT),
            'email' => sanitizeInput($data['email']),
            'nome' => sanitizeInput($data['nome']),
            'cognome' => sanitizeInput($data['cognome']),
            'tipo_utente' => $data['tipo_utente'],
            'attivo' => isset($data['attivo']) ? ($data['attivo'] ? 1 : 0) : 1,
            'data_cambio_password' => date('Y-m-d H:i:s')
        ];
        
        $userId = $db->insert('utenti', $userData);
        
        // Se professionista, crea record aggiuntivo
        if ($data['tipo_utente'] === 'professionista' && isset($data['professionista'])) {
            $profData = $data['professionista'];
            
            $professionistaData = [
                'utente_id' => $userId,
                'codice_sdi' => sanitizeInput($profData['codice_sdi'] ?? ''),
                'pec' => sanitizeInput($profData['pec'] ?? ''),
                'partita_iva' => sanitizeInput($profData['partita_iva'] ?? ''),
                'codice_fiscale' => sanitizeInput($profData['codice_fiscale'] ?? ''),
                'indirizzo' => sanitizeInput($profData['indirizzo'] ?? ''),
                'telefono' => sanitizeInput($profData['telefono'] ?? ''),
                'listino_id' => $profData['listino_id'] ?? null
            ];
            
            $db->insert('professionisti', $professionistaData);
        }
        
        $db->commit();
        
        // Log
        $currentUser = $auth->getCurrentUser();
        $logger->log($currentUser->getId(), 'utente_creato', 
                    "Creato utente: {$userData['username']} ({$userData['tipo_utente']})");
        
        // Invia email di benvenuto (TODO: implementare)
        // sendWelcomeEmail($userData['email'], $userData['nome'], $data['password']);
        
        jsonResponse([
            'success' => true,
            'message' => 'Utente creato con successo',
            'data' => ['id' => $userId]
        ], 201);
        
    } catch (Exception $e) {
        $db->rollback();
        error_log("Errore creazione utente: " . $e->getMessage());
        jsonResponse([
            'success' => false,
            'error' => 'Errore nella creazione dell\'utente'
        ], 500);
    }
}

/**
 * Aggiorna utente
 */
function handleUpdateUser($userId) {
    global $db, $logger, $auth;
    
    if (!$userId) {
        jsonResponse(['success' => false, 'error' => 'ID utente mancante'], 400);
    }
    
    // Verifica che l'utente esista
    $user = $db->selectOne("SELECT * FROM utenti WHERE id = :id", ['id' => $userId]);
    if (!$user) {
        jsonResponse(['success' => false, 'error' => 'Utente non trovato'], 404);
    }
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    // Campi aggiornabili
    $allowedFields = ['email', 'nome', 'cognome', 'attivo'];
    $updateData = [];
    
    foreach ($allowedFields as $field) {
        if (isset($data[$field])) {
            if ($field === 'email') {
                // Validazione email
                if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
                    jsonResponse([
                        'success' => false,
                        'error' => 'Email non valida'
                    ], 400);
                }
                
                // Verifica unicità
                $existing = $db->selectOne(
                    "SELECT id FROM utenti WHERE email = :email AND id != :id",
                    ['email' => $data['email'], 'id' => $userId]
                );
                
                if ($existing) {
                    jsonResponse([
                        'success' => false,
                        'error' => 'Email già in uso'
                    ], 400);
                }
            }
            
            $updateData[$field] = $field === 'attivo' ? ($data[$field] ? 1 : 0) : sanitizeInput($data[$field]);
        }
    }
    
    try {
        $db->beginTransaction();
        
        // Aggiorna utente
        if (!empty($updateData)) {
            $db->update('utenti', $updateData, ['id' => $userId]);
        }
        
        // Reset password se richiesto
        if (!empty($data['reset_password'])) {
            $newPassword = generateRandomPassword();
            $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
            
            $db->update('utenti', [
                'password' => $hashedPassword,
                'data_cambio_password' => date('Y-m-d H:i:s'),
                'tentativi_falliti' => 0,
                'bloccato' => 0
            ], ['id' => $userId]);
            
            // TODO: Invia email con nuova password
            // sendPasswordResetEmail($user['email'], $newPassword);
            
            $updateData['password_reset'] = true;
        }
        
        // Sblocca account se richiesto
        if (isset($data['sblocca']) && $data['sblocca']) {
            $db->update('utenti', [
                'tentativi_falliti' => 0,
                'bloccato' => 0
            ], ['id' => $userId]);
        }
        
        // Aggiorna dati professionista
        if ($user['tipo_utente'] === 'professionista' && isset($data['professionista'])) {
            $profData = $data['professionista'];
            $profAllowedFields = [
                'codice_sdi', 'pec', 'partita_iva', 
                'codice_fiscale', 'indirizzo', 'telefono', 'listino_id'
            ];
            
            $profUpdateData = [];
            foreach ($profAllowedFields as $field) {
                if (isset($profData[$field])) {
                    $profUpdateData[$field] = sanitizeInput($profData[$field]);
                }
            }
            
            if (!empty($profUpdateData)) {
                $db->update('professionisti', $profUpdateData, ['utente_id' => $userId]);
            }
        }
        
        $db->commit();
        
        // Log
        $currentUser = $auth->getCurrentUser();
        $logger->log($currentUser->getId(), 'utente_modificato', 
                    "Modificato utente ID: {$userId}");
        
        jsonResponse([
            'success' => true,
            'message' => 'Utente aggiornato con successo'
        ]);
        
    } catch (Exception $e) {
        $db->rollback();
        error_log("Errore aggiornamento utente: " . $e->getMessage());
        jsonResponse([
            'success' => false,
            'error' => 'Errore nell\'aggiornamento dell\'utente'
        ], 500);
    }
}

/**
 * Elimina utente
 */
function handleDeleteUser($userId) {
    global $db, $logger, $auth;
    
    if (!$userId) {
        jsonResponse(['success' => false, 'error' => 'ID utente mancante'], 400);
    }
    
    // Non permettere eliminazione dell'utente corrente
    $currentUser = $auth->getCurrentUser();
    if ($currentUser->getId() == $userId) {
        jsonResponse([
            'success' => false,
            'error' => 'Non puoi eliminare il tuo stesso account'
        ], 400);
    }
    
    // Verifica che l'utente esista
    $user = $db->selectOne(
        "SELECT username, tipo_utente FROM utenti WHERE id = :id",
        ['id' => $userId]
    );
    
    if (!$user) {
        jsonResponse(['success' => false, 'error' => 'Utente non trovato'], 404);
    }
    
    // Non permettere eliminazione di amministratori se è l'ultimo
    if ($user['tipo_utente'] === 'amministratore') {
        $adminCount = $db->count('utenti', ['tipo_utente' => 'amministratore']);
        if ($adminCount <= 1) {
            jsonResponse([
                'success' => false,
                'error' => 'Impossibile eliminare l\'ultimo amministratore'
            ], 400);
        }
    }
    
    try {
        // Invece di eliminare fisicamente, disattiva l'utente
        $db->update('utenti', [
            'attivo' => 0,
            'bloccato' => 1,
            'data_cancellazione' => date('Y-m-d H:i:s')
        ], ['id' => $userId]);
        
        // Invalida tutte le sessioni
        $db->delete('sessioni', ['utente_id' => $userId]);
        $db->delete('api_tokens', ['utente_id' => $userId]);
        
        // Log
        $logger->log($currentUser->getId(), 'utente_eliminato', 
                    "Eliminato utente: {$user['username']}");
        
        jsonResponse([
            'success' => true,
            'message' => 'Utente eliminato con successo'
        ]);
        
    } catch (Exception $e) {
        error_log("Errore eliminazione utente: " . $e->getMessage());
        jsonResponse([
            'success' => false,
            'error' => 'Errore nell\'eliminazione dell\'utente'
        ], 500);
    }
}

/**
 * Genera password casuale
 */
function generateRandomPassword($length = 12) {
    $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*';
    $password = '';
    
    for ($i = 0; $i < $length; $i++) {
        $password .= $chars[random_int(0, strlen($chars) - 1)];
    }
    
    return $password;
}
