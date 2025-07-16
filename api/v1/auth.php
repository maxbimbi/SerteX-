<?php
/**
 * API Auth - Autenticazione
 * SerteX+ Genetic Lab Portal
 */

require_once '../../includes/auth.php';
require_once '../../includes/database.php';
require_once '../../includes/session.php';
require_once '../../includes/functions.php';

$auth = new Auth();
$session = Session::getInstance();
$db = Database::getInstance();

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

switch ($method) {
    case 'POST':
        if ($action === 'login') {
            handleLogin();
        } elseif ($action === 'logout') {
            handleLogout();
        } elseif ($action === 'refresh') {
            handleRefreshToken();
        } elseif ($action === 'forgot-password') {
            handleForgotPassword();
        } else {
            jsonResponse(['success' => false, 'error' => 'Azione non valida'], 400);
        }
        break;
        
    case 'GET':
        if ($action === 'check') {
            handleCheckAuth();
        } elseif ($action === 'user') {
            handleGetCurrentUser();
        } else {
            jsonResponse(['success' => false, 'error' => 'Azione non valida'], 400);
        }
        break;
        
    case 'PUT':
        requireAuth();
        if ($action === 'change-password') {
            handleChangePassword();
        } elseif ($action === 'update-profile') {
            handleUpdateProfile();
        } else {
            jsonResponse(['success' => false, 'error' => 'Azione non valida'], 400);
        }
        break;
        
    default:
        jsonResponse(['success' => false, 'error' => 'Metodo non supportato'], 405);
}

/**
 * Gestisce il login
 */
function handleLogin() {
    global $auth, $db;
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    // Validazione input
    if (empty($data['username']) || empty($data['password'])) {
        jsonResponse([
            'success' => false,
            'error' => 'Username e password sono obbligatori'
        ], 400);
    }
    
    // Tenta il login
    $result = $auth->login(
        $data['username'],
        $data['password'],
        $data['two_factor_code'] ?? null
    );
    
    if ($result['success']) {
        // Genera token API (opzionale, per autenticazione stateless)
        $token = bin2hex(random_bytes(32));
        $expiresAt = date('Y-m-d H:i:s', strtotime('+24 hours'));
        
        // Salva token nel database
        $db->insert('api_tokens', [
            'token' => hash('sha256', $token),
            'utente_id' => $result['user']['id'],
            'expires_at' => $expiresAt,
            'created_at' => date('Y-m-d H:i:s')
        ]);
        
        jsonResponse([
            'success' => true,
            'message' => $result['message'],
            'user' => $result['user'],
            'token' => $token,
            'expires_at' => $expiresAt
        ]);
    } else {
        $statusCode = 401;
        
        if (isset($result['requires_2fa'])) {
            $statusCode = 200; // 2FA richiesto non è un errore
        } elseif (isset($result['password_expired'])) {
            $statusCode = 200; // Password scaduta richiede azione
        }
        
        jsonResponse($result, $statusCode);
    }
}

/**
 * Gestisce il logout
 */
function handleLogout() {
    global $auth, $db;
    
    // Rimuovi token API se presente
    $headers = getallheaders();
    if (isset($headers['Authorization'])) {
        $token = str_replace('Bearer ', '', $headers['Authorization']);
        $hashedToken = hash('sha256', $token);
        
        $db->delete('api_tokens', ['token' => $hashedToken]);
    }
    
    // Logout sessione
    $auth->logout();
    
    jsonResponse([
        'success' => true,
        'message' => 'Logout effettuato con successo'
    ]);
}

/**
 * Verifica stato autenticazione
 */
function handleCheckAuth() {
    global $auth, $db;
    
    $isAuthenticated = false;
    $user = null;
    
    // Verifica token API
    $headers = getallheaders();
    if (isset($headers['Authorization'])) {
        $token = str_replace('Bearer ', '', $headers['Authorization']);
        $hashedToken = hash('sha256', $token);
        
        $tokenData = $db->selectOne("
            SELECT t.*, u.id, u.username, u.nome, u.cognome, u.tipo_utente
            FROM api_tokens t
            INNER JOIN utenti u ON t.utente_id = u.id
            WHERE t.token = :token AND t.expires_at > NOW()
        ", ['token' => $hashedToken]);
        
        if ($tokenData) {
            $isAuthenticated = true;
            $user = [
                'id' => $tokenData['id'],
                'username' => $tokenData['username'],
                'nome' => $tokenData['nome'],
                'cognome' => $tokenData['cognome'],
                'tipo_utente' => $tokenData['tipo_utente']
            ];
            
            // Aggiorna ultimo accesso
            $db->update('api_tokens', [
                'last_used_at' => date('Y-m-d H:i:s')
            ], ['token' => $hashedToken]);
        }
    } 
    // Altrimenti verifica sessione
    elseif ($auth->isAuthenticated()) {
        $isAuthenticated = true;
        $currentUser = $auth->getCurrentUser();
        if ($currentUser) {
            $user = [
                'id' => $currentUser->getId(),
                'username' => $currentUser->getUsername(),
                'nome' => $currentUser->getNome(),
                'cognome' => $currentUser->getCognome(),
                'tipo_utente' => $currentUser->getTipoUtente()
            ];
        }
    }
    
    jsonResponse([
        'success' => true,
        'authenticated' => $isAuthenticated,
        'user' => $user
    ]);
}

/**
 * Ottiene utente corrente
 */
function handleGetCurrentUser() {
    requireAuth();
    
    global $auth, $db;
    
    $user = $auth->getCurrentUser();
    
    if (!$user) {
        jsonResponse(['success' => false, 'error' => 'Utente non trovato'], 404);
    }
    
    $userData = [
        'id' => $user->getId(),
        'username' => $user->getUsername(),
        'email' => $user->getEmail(),
        'nome' => $user->getNome(),
        'cognome' => $user->getCognome(),
        'tipo_utente' => $user->getTipoUtente(),
        'attivo' => $user->isAttivo(),
        'two_factor_enabled' => $user->hasTwoFactorEnabled(),
        'data_ultimo_accesso' => $user->getDataUltimoAccesso(),
        'data_cambio_password' => $user->getDataCambioPassword()
    ];
    
    // Aggiungi dati specifici per tipo utente
    if ($user->getTipoUtente() === 'professionista') {
        $professionista = $db->selectOne(
            "SELECT * FROM professionisti WHERE utente_id = :user_id",
            ['user_id' => $user->getId()]
        );
        
        if ($professionista) {
            $userData['professionista'] = [
                'id' => $professionista['id'],
                'codice_sdi' => $professionista['codice_sdi'],
                'pec' => $professionista['pec'],
                'partita_iva' => $professionista['partita_iva'],
                'codice_fiscale' => $professionista['codice_fiscale'],
                'indirizzo' => $professionista['indirizzo'],
                'telefono' => $professionista['telefono']
            ];
        }
    } elseif ($user->getTipoUtente() === 'biologo') {
        // Carica firma biologo
        $userData['firma_descrizione'] = $user->getFirmaDescrizione();
        $userData['firma_immagine'] = $user->getFirmaImmagine();
    }
    
    jsonResponse([
        'success' => true,
        'data' => $userData
    ]);
}

/**
 * Cambia password
 */
function handleChangePassword() {
    global $auth;
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    // Validazione
    if (empty($data['old_password']) || empty($data['new_password'])) {
        jsonResponse([
            'success' => false,
            'error' => 'Password attuale e nuova password sono obbligatorie'
        ], 400);
    }
    
    if ($data['new_password'] !== ($data['confirm_password'] ?? '')) {
        jsonResponse([
            'success' => false,
            'error' => 'Le password non coincidono'
        ], 400);
    }
    
    $user = $auth->getCurrentUser();
    $result = $auth->changePassword(
        $user->getId(),
        $data['old_password'],
        $data['new_password']
    );
    
    if ($result['success']) {
        jsonResponse($result);
    } else {
        jsonResponse($result, 400);
    }
}

/**
 * Aggiorna profilo utente
 */
function handleUpdateProfile() {
    global $auth, $db;
    
    $user = $auth->getCurrentUser();
    $data = json_decode(file_get_contents('php://input'), true);
    
    // Campi aggiornabili
    $allowedFields = ['nome', 'cognome', 'email'];
    $updateData = [];
    
    foreach ($allowedFields as $field) {
        if (isset($data[$field]) && !empty($data[$field])) {
            $updateData[$field] = sanitizeInput($data[$field]);
        }
    }
    
    // Validazione email se fornita
    if (isset($updateData['email'])) {
        if (!filter_var($updateData['email'], FILTER_VALIDATE_EMAIL)) {
            jsonResponse([
                'success' => false,
                'error' => 'Email non valida'
            ], 400);
        }
        
        // Verifica unicità email
        $existingEmail = $db->selectOne(
            "SELECT id FROM utenti WHERE email = :email AND id != :id",
            ['email' => $updateData['email'], 'id' => $user->getId()]
        );
        
        if ($existingEmail) {
            jsonResponse([
                'success' => false,
                'error' => 'Email già in uso'
            ], 400);
        }
    }
    
    // Gestione 2FA
    if (isset($data['two_factor_enabled'])) {
        $result = $auth->toggle2FA($user->getId(), $data['two_factor_enabled']);
        if (!$result['success']) {
            jsonResponse($result, 500);
        }
        
        if ($data['two_factor_enabled'] && isset($result['secret'])) {
            $updateData['two_factor_secret'] = $result['secret'];
        }
    }
    
    // Aggiorna profilo biologo
    if ($user->getTipoUtente() === 'biologo') {
        if (isset($data['firma_descrizione'])) {
            $updateData['firma_descrizione'] = sanitizeInput($data['firma_descrizione']);
        }
        
        // Gestione upload firma immagine
        if (!empty($_FILES['firma_immagine'])) {
            $uploadResult = uploadFile($_FILES['firma_immagine'], 'firme', ['jpg', 'jpeg', 'png']);
            if ($uploadResult['success']) {
                $updateData['firma_immagine'] = $uploadResult['path'];
            }
        }
    }
    
    // Aggiorna dati professionista
    if ($user->getTipoUtente() === 'professionista' && isset($data['professionista'])) {
        $profData = $data['professionista'];
        $profAllowedFields = [
            'codice_sdi', 'pec', 'partita_iva', 
            'codice_fiscale', 'indirizzo', 'telefono'
        ];
        
        $profUpdateData = [];
        foreach ($profAllowedFields as $field) {
            if (isset($profData[$field])) {
                $profUpdateData[$field] = sanitizeInput($profData[$field]);
            }
        }
        
        if (!empty($profUpdateData)) {
            $db->update('professionisti', $profUpdateData, ['utente_id' => $user->getId()]);
        }
    }
    
    // Aggiorna utente
    if (!empty($updateData)) {
        $db->update('utenti', $updateData, ['id' => $user->getId()]);
    }
    
    jsonResponse([
        'success' => true,
        'message' => 'Profilo aggiornato con successo'
    ]);
}

/**
 * Gestisce richiesta reset password
 */
function handleForgotPassword() {
    global $db;
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (empty($data['email'])) {
        jsonResponse([
            'success' => false,
            'error' => 'Email obbligatoria'
        ], 400);
    }
    
    $user = $db->selectOne(
        "SELECT id, nome, email FROM utenti WHERE email = :email AND attivo = 1",
        ['email' => $data['email']]
    );
    
    if ($user) {
        // Genera token reset
        $token = bin2hex(random_bytes(32));
        $expires = date('Y-m-d H:i:s', strtotime('+1 hour'));
        
        // Salva token
        $db->insert('password_reset_tokens', [
            'utente_id' => $user['id'],
            'token' => hash('sha256', $token),
            'expires_at' => $expires
        ]);
        
        // Invia email (da implementare)
        $resetLink = SITE_URL . '/reset-password.php?token=' . $token;
        
        // Per ora logga solo il link
        error_log("Reset password link for {$user['email']}: {$resetLink}");
        
        // TODO: Implementare invio email reale
        // sendEmail($user['email'], 'Reset Password', ...);
    }
    
    // Rispondi sempre con successo per sicurezza
    jsonResponse([
        'success' => true,
        'message' => 'Se l\'email esiste, riceverai le istruzioni per il reset'
    ]);
}

/**
 * Aggiorna token API
 */
function handleRefreshToken() {
    global $db;
    
    $headers = getallheaders();
    if (!isset($headers['Authorization'])) {
        jsonResponse([
            'success' => false,
            'error' => 'Token mancante'
        ], 401);
    }
    
    $oldToken = str_replace('Bearer ', '', $headers['Authorization']);
    $hashedOldToken = hash('sha256', $oldToken);
    
    // Verifica token esistente
    $tokenData = $db->selectOne(
        "SELECT * FROM api_tokens WHERE token = :token",
        ['token' => $hashedOldToken]
    );
    
    if (!$tokenData || strtotime($tokenData['expires_at']) < time()) {
        jsonResponse([
            'success' => false,
            'error' => 'Token non valido o scaduto'
        ], 401);
    }
    
    // Genera nuovo token
    $newToken = bin2hex(random_bytes(32));
    $expiresAt = date('Y-m-d H:i:s', strtotime('+24 hours'));
    
    // Aggiorna token
    $db->update('api_tokens', [
        'token' => hash('sha256', $newToken),
        'expires_at' => $expiresAt,
        'refreshed_at' => date('Y-m-d H:i:s')
    ], ['id' => $tokenData['id']]);
    
    jsonResponse([
        'success' => true,
        'token' => $newToken,
        'expires_at' => $expiresAt
    ]);
}
