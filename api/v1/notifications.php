<?php
/**
 * API Endpoint per le notifiche
 */

require_once '../../includes/auth.php';
require_once '../../includes/database.php';
require_once '../../includes/functions.php';

// Abilita CORS se necessario
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Verifica autenticazione
if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['error' => 'Non autorizzato']);
    exit;
}

$db = Database::getInstance()->getConnection();
$user = Auth::getUser();
$method = $_SERVER['REQUEST_METHOD'];

try {
    switch ($method) {
        case 'GET':
            // Recupera notifiche non lette
            $stmt = $db->prepare("
                SELECT COUNT(*) as count
                FROM notifiche 
                WHERE utente_id = ? AND letta = 0
            ");
            $stmt->execute([$user['id']]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Recupera ultime notifiche
            $stmt = $db->prepare("
                SELECT * FROM notifiche 
                WHERE utente_id = ? 
                ORDER BY data_creazione DESC 
                LIMIT 10
            ");
            $stmt->execute([$user['id']]);
            $notifications = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'success' => true,
                'count' => $result['count'],
                'notifications' => $notifications
            ]);
            break;
            
        case 'POST':
            // Segna notifica come letta
            $data = json_decode(file_get_contents('php://input'), true);
            $notification_id = $data['notification_id'] ?? null;
            
            if ($notification_id) {
                $stmt = $db->prepare("
                    UPDATE notifiche 
                    SET letta = 1, data_lettura = NOW() 
                    WHERE id = ? AND utente_id = ?
                ");
                $stmt->execute([$notification_id, $user['id']]);
                
                echo json_encode(['success' => true]);
            } else {
                // Segna tutte come lette
                $stmt = $db->prepare("
                    UPDATE notifiche 
                    SET letta = 1, data_lettura = NOW() 
                    WHERE utente_id = ? AND letta = 0
                ");
                $stmt->execute([$user['id']]);
                
                echo json_encode([
                    'success' => true,
                    'updated' => $stmt->rowCount()
                ]);
            }
            break;
            
        case 'DELETE':
            // Elimina notifica
            $notification_id = $_GET['id'] ?? null;
            
            if ($notification_id) {
                $stmt = $db->prepare("
                    DELETE FROM notifiche 
                    WHERE id = ? AND utente_id = ?
                ");
                $stmt->execute([$notification_id, $user['id']]);
                
                echo json_encode(['success' => true]);
            } else {
                http_response_code(400);
                echo json_encode(['error' => 'ID notifica mancante']);
            }
            break;
            
        default:
            http_response_code(405);
            echo json_encode(['error' => 'Metodo non permesso']);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Errore server: ' . $e->getMessage()]);
}

// Funzione helper per creare notifica
function createNotification($db, $user_id, $type, $title, $message, $link = null) {
    $stmt = $db->prepare("
        INSERT INTO notifiche (utente_id, tipo, titolo, messaggio, link, data_creazione)
        VALUES (?, ?, ?, ?, ?, NOW())
    ");
    return $stmt->execute([$user_id, $type, $title, $message, $link]);
}