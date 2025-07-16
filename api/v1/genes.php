<?php
/**
 * API Genes - Gestione geni
 * SerteX+ Genetic Lab Portal
 */

require_once '../../includes/database.php';
require_once '../../includes/functions.php';

// Richiede autenticazione
requireAuth();

$db = Database::getInstance();
$method = $_SERVER['REQUEST_METHOD'];
$geneId = $_GET['id'] ?? null;

switch ($method) {
    case 'GET':
        if ($geneId) {
            // Dettaglio singolo gene
            handleGetGene($geneId);
        } else {
            // Lista geni
            handleGetGenes();
        }
        break;
        
    default:
        jsonResponse(['success' => false, 'error' => 'Metodo non supportato'], 405);
}

/**
 * Recupera dettagli di un gene
 */
function handleGetGene($geneId) {
    global $db;
    
    $gene = $db->selectOne("
        SELECT g.*, gg.nome as gruppo_nome
        FROM geni g
        LEFT JOIN gruppi_geni gg ON g.gruppo_id = gg.id
        WHERE g.id = :id
    ", ['id' => $geneId]);
    
    if (!$gene) {
        jsonResponse(['success' => false, 'error' => 'Gene non trovato'], 404);
    }
    
    // Carica risultati possibili
    $gene['risultati'] = $db->select(
        "SELECT * FROM risultati_geni WHERE gene_id = :gene_id ORDER BY ordine",
        ['gene_id' => $geneId]
    );
    
    jsonResponse([
        'success' => true,
        'data' => $gene
    ]);
}

/**
 * Recupera lista geni
 */
function handleGetGenes() {
    global $db;
    
    $query = "
        SELECT g.*, gg.nome as gruppo_nome,
               COUNT(rg.id) as num_risultati
        FROM geni g
        LEFT JOIN gruppi_geni gg ON g.gruppo_id = gg.id
        LEFT JOIN risultati_geni rg ON g.id = rg.gene_id
        WHERE 1=1
    ";
    
    $params = [];
    
    // Filtro attivi
    if (isset($_GET['attivo'])) {
        $query .= " AND g.attivo = :attivo";
        $params['attivo'] = $_GET['attivo'] === 'true' ? 1 : 0;
    } else {
        // Di default mostra solo attivi
        $query .= " AND g.attivo = 1";
    }
    
    // Filtro gruppo
    if (isset($_GET['gruppo_id'])) {
        $query .= " AND g.gruppo_id = :gruppo_id";
        $params['gruppo_id'] = $_GET['gruppo_id'];
    }
    
    // Ricerca
    if (isset($_GET['search'])) {
        $query .= " AND (g.sigla LIKE :search OR g.nome LIKE :search)";
        $params['search'] = '%' . $_GET['search'] . '%';
    }
    
    $query .= " GROUP BY g.id ORDER BY gg.ordine, gg.nome, g.nome";
    
    $genes = $db->select($query, $params);
    
    // Se richiesto, carica anche i risultati
    if (isset($_GET['include_results']) && $_GET['include_results'] === 'true') {
        foreach ($genes as &$gene) {
            $gene['risultati'] = $db->select(
                "SELECT * FROM risultati_geni WHERE gene_id = :gene_id ORDER BY ordine",
                ['gene_id' => $gene['id']]
            );
        }
    }
    
    jsonResponse([
        'success' => true,
        'data' => $genes
    ]);
}
