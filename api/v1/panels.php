<?php
/**
 * API Endpoint per i pannelli genetici
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
$action = $_GET['action'] ?? '';

try {
    switch ($action) {
        case 'get_genes':
            // Recupera geni di un pannello
            $panel_id = (int)($_GET['panel_id'] ?? 0);
            
            if (!$panel_id) {
                throw new Exception('ID pannello mancante');
            }
            
            $stmt = $db->prepare("
                SELECT g.id, g.nome, g.sigla, g.descrizione
                FROM pannelli_geni pg
                JOIN geni g ON pg.gene_id = g.id
                WHERE pg.pannello_id = ?
                ORDER BY g.nome
            ");
            $stmt->execute([$panel_id]);
            $genes = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'success' => true,
                'genes' => $genes
            ]);
            break;
            
        case 'search':
            // Ricerca pannelli
            $query = $_GET['q'] ?? '';
            
            $stmt = $db->prepare("
                SELECT p.*, COUNT(pg.gene_id) as num_genes
                FROM pannelli_genetici p
                LEFT JOIN pannelli_geni pg ON p.id = pg.pannello_id
                WHERE p.attivo = 1 AND p.nome LIKE ?
                GROUP BY p.id
                ORDER BY p.nome
                LIMIT 20
            ");
            $stmt->execute(["%$query%"]);
            $panels = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'success' => true,
                'panels' => $panels
            ]);
            break;
            
        case 'get_price':
            // Recupera prezzo pannello per professionista
            $panel_id = (int)($_GET['panel_id'] ?? 0);
            $professionista_id = (int)($_GET['professionista_id'] ?? 0);
            
            if (!$panel_id || !$professionista_id) {
                throw new Exception('Parametri mancanti');
            }
            
            // Verifica autorizzazione
            if ($user['tipo_utente'] !== 'amministratore') {
                $user_prof_id = getProfessionistaId($db, $user['id']);
                if ($user_prof_id != $professionista_id) {
                    throw new Exception('Non autorizzato');
                }
            }
            
            // Cerca prezzo nel listino
            $stmt = $db->prepare("
                SELECT lp.prezzo 
                FROM listini_prezzi lp
                JOIN professionisti p ON lp.listino_id = p.listino_id
                WHERE p.id = ? AND lp.tipo_elemento = 'pannello_genetico' AND lp.elemento_id = ?
            ");
            $stmt->execute([$professionista_id, $panel_id]);
            $prezzo_listino = $stmt->fetchColumn();
            
            // Se non trovato, usa prezzo base
            if ($prezzo_listino === false) {
                $stmt = $db->prepare("SELECT prezzo FROM pannelli_genetici WHERE id = ?");
                $stmt->execute([$panel_id]);
                $prezzo_listino = $stmt->fetchColumn();
            }
            
            echo json_encode([
                'success' => true,
                'price' => $prezzo_listino ?: 0
            ]);
            break;
            
        case 'create':
            // Crea nuovo pannello (solo admin)
            if ($user['tipo_utente'] !== 'amministratore') {
                throw new Exception('Non autorizzato');
            }
            
            $data = json_decode(file_get_contents('php://input'), true);
            
            $db->beginTransaction();
            
            // Crea pannello
            $stmt = $db->prepare("
                INSERT INTO pannelli_genetici (nome, descrizione, prezzo, attivo)
                VALUES (?, ?, ?, 1)
            ");
            $stmt->execute([
                $data['nome'],
                $data['descrizione'] ?? '',
                $data['prezzo'] ?? 0
            ]);
            
            $panel_id = $db->lastInsertId();
            
            // Aggiungi geni
            if (!empty($data['geni'])) {
                $stmt = $db->prepare("
                    INSERT INTO pannelli_geni (pannello_id, gene_id) VALUES (?, ?)
                ");
                
                foreach ($data['geni'] as $gene_id) {
                    $stmt->execute([$panel_id, $gene_id]);
                }
            }
            
            $db->commit();
            
            echo json_encode([
                'success' => true,
                'panel_id' => $panel_id
            ]);
            break;
            
        case 'update':
            // Aggiorna pannello (solo admin)
            if ($user['tipo_utente'] !== 'amministratore') {
                throw new Exception('Non autorizzato');
            }
            
            $panel_id = (int)($_GET['id'] ?? 0);
            $data = json_decode(file_get_contents('php://input'), true);
            
            if (!$panel_id) {
                throw new Exception('ID pannello mancante');
            }
            
            $db->beginTransaction();
            
            // Aggiorna pannello
            $stmt = $db->prepare("
                UPDATE pannelli_genetici 
                SET nome = ?, descrizione = ?, prezzo = ?
                WHERE id = ?
            ");
            $stmt->execute([
                $data['nome'],
                $data['descrizione'] ?? '',
                $data['prezzo'] ?? 0,
                $panel_id
            ]);
            
            // Aggiorna geni se forniti
            if (isset($data['geni'])) {
                // Rimuovi geni esistenti
                $stmt = $db->prepare("DELETE FROM pannelli_geni WHERE pannello_id = ?");
                $stmt->execute([$panel_id]);
                
                // Aggiungi nuovi geni
                $stmt = $db->prepare("
                    INSERT INTO pannelli_geni (pannello_id, gene_id) VALUES (?, ?)
                ");
                
                foreach ($data['geni'] as $gene_id) {
                    $stmt->execute([$panel_id, $gene_id]);
                }
            }
            
            $db->commit();
            
            echo json_encode(['success' => true]);
            break;
            
        case 'delete':
            // Elimina pannello (solo admin)
            if ($user['tipo_utente'] !== 'amministratore') {
                throw new Exception('Non autorizzato');
            }
            
            $panel_id = (int)($_GET['id'] ?? 0);
            
            if (!$panel_id) {
                throw new Exception('ID pannello mancante');
            }
            
            // Soft delete
            $stmt = $db->prepare("UPDATE pannelli_genetici SET attivo = 0 WHERE id = ?");
            $stmt->execute([$panel_id]);
            
            echo json_encode(['success' => true]);
            break;
            
        default:
            // Lista pannelli
            $stmt = $db->prepare("
                SELECT p.*, COUNT(pg.gene_id) as num_genes
                FROM pannelli_genetici p
                LEFT JOIN pannelli_geni pg ON p.id = pg.pannello_id
                WHERE p.attivo = 1
                GROUP BY p.id
                ORDER BY p.nome
            ");
            $stmt->execute();
            $panels = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode([
                'success' => true,
                'panels' => $panels
            ]);
    }
} catch (Exception $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}