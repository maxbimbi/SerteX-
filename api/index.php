<?php
/**
 * API REST - Endpoint principale
 * SerteX+ Genetic Lab Portal
 */

// Headers CORS e JSON
header("Access-Control-Allow-Origin: *");
header("Content-Type: application/json; charset=UTF-8");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Max-Age: 3600");
header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");

// Gestione richieste OPTIONS per CORS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Include necessari
require_once '../includes/auth.php';
require_once '../includes/session.php';

// Inizializza autenticazione
$auth = new Auth();
$session = Session::getInstance();

// Funzione per inviare risposta JSON
function jsonResponse($data, $statusCode = 200) {
    http_response_code($statusCode);
    echo json_encode($data);
    exit;
}

// Funzione per verificare autenticazione API
function requireAuth() {
    global $auth;
    
    if (!$auth->isAuthenticated()) {
        jsonResponse([
            'success' => false,
            'error' => 'Non autorizzato'
        ], 401);
    }
}

// Funzione per verificare ruolo
function requireRole($roles) {
    global $auth;
    
    requireAuth();
    
    if (!$auth->hasRole($roles)) {
        jsonResponse([
            'success' => false,
            'error' => 'Accesso negato'
        ], 403);
    }
}

// Parse del percorso richiesto
$requestUri = $_SERVER['REQUEST_URI'];
$basePath = dirname($_SERVER['SCRIPT_NAME']);
$path = str_replace($basePath, '', $requestUri);
$path = parse_url($path, PHP_URL_PATH);
$path = trim($path, '/');

// Route alla versione API
if (strpos($path, 'v1/') === 0) {
    $endpoint = str_replace('v1/', '', $path);
    $endpoint = explode('/', $endpoint)[0];
    
    $apiFile = __DIR__ . '/v1/' . $endpoint . '.php';
    
    if (file_exists($apiFile)) {
        require_once $apiFile;
    } else {
        jsonResponse([
            'success' => false,
            'error' => 'Endpoint non trovato'
        ], 404);
    }
} else {
    // Info API
    jsonResponse([
        'name' => 'SerteX+ API',
        'version' => '1.0',
        'endpoints' => [
            '/api/v1/auth' => 'Autenticazione',
            '/api/v1/users' => 'Gestione utenti',
            '/api/v1/patients' => 'Gestione pazienti',
            '/api/v1/tests' => 'Gestione test',
            '/api/v1/genes' => 'Gestione geni',
            '/api/v1/panels' => 'Gestione pannelli',
            '/api/v1/reports' => 'Gestione referti'
        ]
    ]);
}
