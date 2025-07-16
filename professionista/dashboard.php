<?php
/**
 * SerteX+ - Dashboard Professionista
 */

require_once '../config/config.php';
require_once '../includes/functions.php';
require_once '../includes/security.php';

// Richiede autenticazione professionista
requireAuth('professionista');

// Carica classi necessarie
use SerteX\User;
use SerteX\Patient;
use SerteX\Test;

$db = getDatabase();
$currentUser = new User($db, $_SESSION['user_id']);

// Ottieni ID professionista
$stmt = $db->prepare("SELECT id FROM professionisti WHERE utente_id = ?");
$stmt->execute([$_SESSION['user_id']]);
$professionistaId = $stmt->fetchColumn();

// Ottieni statistiche
$stats = getProfessionistaStatistics($db, $professionistaId);

// Funzione per ottenere statistiche professionista
function getProfessionistaStatistics($db, $professionistaId) {
    $stats = [];
    
    try {
        // Pazienti totali
        $stmt = $db->prepare("
            SELECT COUNT(*) FROM pazienti WHERE professionista_id = ?
        ");
        $stmt->execute([$professionistaId]);
        $stats['pazienti_totali'] = $stmt->fetchColumn();
        
        // Nuovi pazienti questo mese
        $stmt = $db->prepare("
            SELECT COUNT(*) FROM pazienti 
            WHERE professionista_id = ? 
            AND MONTH(data_creazione) = MONTH(CURDATE())
            AND YEAR(data_creazione) = YEAR(CURDATE())
        ");
        $stmt->execute([$professionistaId]);
        $stats['pazienti_mese'] = $stmt->fetchColumn();
        
        // Test totali
        $stmt = $db->prepare("
            SELECT COUNT(*) FROM test WHERE professionista_id = ?
        ");
        $stmt->execute([$professionistaId]);
        $stats['test_totali'] = $stmt->fetchColumn();
        
        // Test questo mese
        $stmt = $db->prepare("
            SELECT COUNT(*) FROM test 
            WHERE professionista_id = ? 
            AND MONTH(data_richiesta) = MONTH(CURDATE())
            AND YEAR(data_richiesta) = YEAR(CURDATE())
        ");
        $stmt->execute([$professionistaId]);
        $stats['test_mese'] = $stmt->fetchColumn();
        
        // Test per stato
        $stmt = $db->prepare("
            SELECT stato, COUNT(*) as count 
            FROM test 
            WHERE professionista_id = ?
            GROUP BY stato
        ");
        $stmt->execute([$professionistaId]);
        $stats['test_per_stato'] = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        
        // Referti disponibili
        $stmt = $db->prepare("
            SELECT COUNT(*) 
            FROM test t
            JOIN referti r ON t.id = r.test_id
            WHERE t.professionista_id = ? 
            AND t.stato IN ('refertato', 'firmato')
            AND r.file_path IS NOT NULL
        ");
        $stmt->execute([$professionistaId]);
        $stats['referti_disponibili'] = $stmt->fetchColumn();
        
        // Ultimi test
        $stmt = $db->prepare("
            SELECT t.*, p.nome as paziente_nome, p.cognome as paziente_cognome,
                   r.id as referto_id, r.file_path_firmato
            FROM test t
            JOIN pazienti p ON t.paziente_id = p.id
            LEFT JOIN referti r ON t.id = r.test_id
            WHERE t.professionista_id = ?
            ORDER BY t.data_richiesta DESC
            LIMIT 10
        ");
        $stmt->execute([$professionistaId]);
        $stats['ultimi_test'] = $stmt->fetchAll();
        
        // Top pazienti per numero di test
        $stmt = $db->prepare("
            SELECT p.id, p.nome, p.cognome, COUNT(t.id) as num_test
            FROM pazienti p
            LEFT JOIN test t ON p.id = t.paziente_id
            WHERE p.professionista_id = ?
            GROUP BY p.id
            ORDER BY num_test DESC
            LIMIT 5
        ");
        $stmt->execute([$professionistaId]);
        $stats['top_pazienti'] = $stmt->fetchAll();
        
    } catch (Exception $e) {
        error_log("Errore statistiche professionista: " . $e->getMessage());
    }
    
    return $stats;
}

// Gestione ricerca rapida paziente
if (isset($_GET['search_patient'])) {
    header('Content-Type: application/json');
    $search = $_GET['q'] ?? '';
    
    $stmt = $db->prepare("
        SELECT id, nome, cognome, codice_fiscale 
        FROM pazienti 
        WHERE professionista_id = ?
        AND (nome LIKE ? OR cognome LIKE ? OR codice_fiscale LIKE ?)
        LIMIT 10
    ");
    $searchTerm = "%$search%";
    $stmt->execute([$professionistaId, $searchTerm, $searchTerm, $searchTerm]);
    
    echo json_encode($stmt->fetchAll());
    exit;
}

// Includi header
include '../templates/header.php';
?>

<div class="container-fluid">
    <div class="row">
        <!-- Sidebar -->
        <?php include '../templates/sidebar.php'; ?>
        
        <!-- Main content -->
        <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
            <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                <h1 class="h2">Dashboard</h1>
                <div class="btn-toolbar mb-2 mb-md-0">
                    <div class="btn-group me-2">
                        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#newPatientModal">
                            <i class="fas fa-user-plus"></i> Nuovo Paziente
                        </button>
                        <button type="button" class="btn btn-success" data-bs-toggle="modal" data-bs-target="#newTestModal">
                            <i class="fas fa-vial"></i> Nuovo Test
                        </button>
                    </div>
                </div>
            </div>
            
            <!-- Ricerca rapida -->
            <div class="row mb-4">
                <div class="col-12">
                    <div class="card shadow-sm">
                        <div class="card-body">
                            <h5 class="card-title">
                                <i class="fas fa-search"></i> Ricerca Rapida Paziente
                            </h5>
                            <div class="input-group">
                                <input type="text" 
                                       class="form-control" 
                                       id="quickSearch" 
                                       placeholder="Cerca per nome, cognome o codice fiscale..."
                                       autocomplete="off">
                                <button class="btn btn-primary" type="button" onclick="performQuickSearch()">
                                    <i class="fas fa-search"></i> Cerca
                                </button>
                            </div>
                            <div id="searchResults" class="list-group mt-2" style="display: none;"></div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Cards statistiche -->
            <div class="row">
                <div class="col-xl-3 col-md-6 mb-4">
                    <div class="card border-left-primary shadow h-100 py-2">
                        <div class="card-body">
                            <div class="row no-gutters align-items-center">
                                <div class="col mr-2">
                                    <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">
                                        Pazienti Totali
                                    </div>
                                    <div class="h5 mb-0 font-weight-bold text-gray-800">
                                        <?php echo number_format($stats['pazienti_totali']); ?>
                                    </div>
                                    <small class="text-muted">
                                        +<?php echo $stats['pazienti_mese']; ?> questo mese
                                    </small>
                                </div>
                                <div class="col-auto">
                                    <i class="fas fa-users fa-2x text-gray-300"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-xl-3 col-md-6 mb-4">
                    <div class="card border-left-success shadow h-100 py-2">
                        <div class="card-body">
                            <div class="row no-gutters align-items-center">
                                <div class="col mr-2">
                                    <div class="text-xs font-weight-bold text-success text-uppercase mb-1">
                                        Test Totali
                                    </div>
                                    <div class="h5 mb-0 font-weight-bold text-gray-800">
                                        <?php echo number_format($stats['test_totali']); ?>
                                    </div>
                                    <small class="text-muted">
                                        <?php echo $stats['test_mese']; ?> questo mese
                                    </small>
                                </div>
                                <div class="col-auto">
                                    <i class="fas fa-vial fa-2x text-gray-300"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-xl-3 col-md-6 mb-4">
                    <div class="card border-left-info shadow h-100 py-2">
                        <div class="card-body">
                            <div class="row no-gutters align-items-center">
                                <div class="col mr-2">
                                    <div class="text-xs font-weight-bold text-info text-uppercase mb-1">
                                        Referti Disponibili
                                    </div>
                                    <div class="h5 mb-0 font-weight-bold text-gray-800">
                                        <?php echo number_format($stats['referti_disponibili']); ?>
                                    </div>
                                </div>
                                <div class="col-auto">
                                    <i class="fas fa-file-medical fa-2x text-gray-300"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-xl-3 col-md-6 mb-4">
                    <div class="card border-left-warning shadow h-100 py-2">
                        <div class="card-body">
                            <div class="row no-gutters align-items-center">
                                <div class="col mr-2">
                                    <div class="text-xs font-weight-bold text-warning text-uppercase mb-1">
                                        In Attesa
                                    </div>
                                    <div class="h5 mb-0 font-weight-bold text-gray-800">
                                        <?php 
                                        $inAttesa = ($stats['test_per_stato']['richiesto'] ?? 0) + 
                                                   ($stats['test_per_stato']['in_lavorazione'] ?? 0) +
                                                   ($stats['test_per_stato']['eseguito'] ?? 0);
                                        echo number_format($inAttesa);
                                        ?>
                                    </div>
                                </div>
                                <div class="col-auto">
                                    <i class="fas fa-clock fa-2x text-gray-300"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Grafici e tabelle -->
            <div class="row">
                <!-- Ultimi test -->
                <div class="col-lg-8">
                    <div class="card shadow mb-4">
                        <div class="card-header py-3 d-flex justify-content-between align-items-center">
                            <h6 class="m-0 font-weight-bold text-primary">Ultimi Test</h6>
                            <a href="tests.php" class="btn btn-sm btn-primary">
                                Vedi Tutti <i class="fas fa-arrow-right ms-1"></i>
                            </a>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead>
                                        <tr>
                                            <th>Codice</th>
                                            <th>Paziente</th>
                                            <th>Tipo</th>
                                            <th>Data</th>
                                            <th>Stato</th>
                                            <th>Azioni</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($stats['ultimi_test'] as $test): ?>
                                        <tr>
                                            <td>
                                                <a href="test-details.php?id=<?php echo $test['id']; ?>">
                                                    <?php echo htmlspecialchars($test['codice']); ?>
                                                </a>
                                            </td>
                                            <td>
                                                <?php echo htmlspecialchars($test['paziente_cognome'] . ' ' . $test['paziente_nome']); ?>
                                            </td>
                                            <td>
                                                <span class="badge bg-<?php echo getTestTypeColor($test['tipo_test']); ?>">
                                                    <?php echo getTestTypeLabel($test['tipo_test']); ?>
                                                </span>
                                            </td>
                                            <td><?php echo formatDate($test['data_richiesta']); ?></td>
                                            <td>
                                                <span class="badge bg-<?php echo getStatoBadgeClass($test['stato']); ?>">
                                                    <?php echo ucfirst($test['stato']); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php if ($test['referto_id'] && $test['file_path_firmato']): ?>
                                                    <a href="reports.php?action=download&id=<?php echo $test['referto_id']; ?>" 
                                                       class="btn btn-sm btn-success">
                                                        <i class="fas fa-download"></i>
                                                    </a>
                                                <?php elseif ($test['stato'] === 'richiesto'): ?>
                                                    <button class="btn btn-sm btn-warning" disabled>
                                                        <i class="fas fa-clock"></i>
                                                    </button>
                                                <?php else: ?>
                                                    <button class="btn btn-sm btn-info" disabled>
                                                        <i class="fas fa-flask"></i>
                                                    </button>
                                                <?php endif; ?>
                                            