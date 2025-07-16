<?php
/**
 * SerteX+ - Dashboard Amministratore
 */

require_once '../config/config.php';
require_once '../includes/functions.php';
require_once '../includes/security.php';

// Richiede autenticazione admin
requireAuth('amministratore');

// Carica classi necessarie
use SerteX\User;
use SerteX\Test;
use SerteX\Logger;

$db = getDatabase();
$logger = new Logger($db);

// Ottieni statistiche
$stats = getAdminStatistics($db);

// Funzione per ottenere statistiche admin
function getAdminStatistics($db) {
    $stats = [];
    
    try {
        // Utenti totali per tipo
        $stmt = $db->query("
            SELECT tipo_utente, COUNT(*) as count 
            FROM utenti 
            WHERE attivo = 1 
            GROUP BY tipo_utente
        ");
        $stats['utenti'] = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        
        // Test di oggi
        $stmt = $db->query("
            SELECT COUNT(*) FROM test WHERE DATE(data_richiesta) = CURDATE()
        ");
        $stats['test_oggi'] = $stmt->fetchColumn();
        
        // Test totali del mese
        $stmt = $db->query("
            SELECT COUNT(*) FROM test 
            WHERE MONTH(data_richiesta) = MONTH(CURDATE()) 
            AND YEAR(data_richiesta) = YEAR(CURDATE())
        ");
        $stats['test_mese'] = $stmt->fetchColumn();
        
        // Fatturato del mese
        $stmt = $db->query("
            SELECT COALESCE(SUM(importo_totale_ivato), 0) 
            FROM fatture 
            WHERE MONTH(data_emissione) = MONTH(CURDATE()) 
            AND YEAR(data_emissione) = YEAR(CURDATE())
            AND stato != 'annullata'
        ");
        $stats['fatturato_mese'] = $stmt->fetchColumn();
        
        // Test per stato
        $stmt = $db->query("
            SELECT stato, COUNT(*) as count 
            FROM test 
            GROUP BY stato
        ");
        $stats['test_per_stato'] = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        
        // Ultimi accessi
        $stmt = $db->query("
            SELECT u.*, l.timestamp as ultimo_accesso
            FROM utenti u
            LEFT JOIN (
                SELECT utente_id, MAX(timestamp) as timestamp
                FROM log_attivita
                WHERE azione = 'auth.login_success'
                GROUP BY utente_id
            ) l ON u.id = l.utente_id
            WHERE u.attivo = 1
            ORDER BY l.timestamp DESC
            LIMIT 10
        ");
        $stats['ultimi_accessi'] = $stmt->fetchAll();
        
        // Alert sistema
        $stats['alerts'] = getSystemAlerts($db);
        
    } catch (Exception $e) {
        error_log("Errore statistiche admin: " . $e->getMessage());
    }
    
    return $stats;
}

// Funzione per ottenere alert di sistema
function getSystemAlerts($db) {
    $alerts = [];
    
    // Password in scadenza
    $stmt = $db->query("
        SELECT COUNT(*) FROM utenti 
        WHERE tipo_utente != 'amministratore'
        AND attivo = 1
        AND DATEDIFF(NOW(), data_cambio_password) > 80
    ");
    $expiring = $stmt->fetchColumn();
    if ($expiring > 0) {
        $alerts[] = ['type' => 'warning', 'message' => "$expiring utenti con password in scadenza"];
    }
    
    // Spazio disco
    $freeSpace = disk_free_space('/') / 1073741824; // GB
    if ($freeSpace < 5) {
        $alerts[] = ['type' => 'danger', 'message' => "Spazio disco insufficiente: " . round($freeSpace, 1) . " GB liberi"];
    }
    
    // Backup
    $lastBackup = getConfig('ultimo_backup');
    if ($lastBackup && (time() - strtotime($lastBackup)) > 86400) {
        $alerts[] = ['type' => 'warning', 'message' => "Ultimo backup effettuato più di 24 ore fa"];
    }
    
    return $alerts;
}

// Gestione azioni AJAX
if (isset($_GET['action']) && $_GET['action'] === 'chart_data') {
    header('Content-Type: application/json');
    
    $type = $_GET['type'] ?? '';
    $data = [];
    
    switch ($type) {
        case 'test_trend':
            // Trend test ultimi 30 giorni
            $stmt = $db->query("
                SELECT DATE(data_richiesta) as data, COUNT(*) as count
                FROM test
                WHERE data_richiesta >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                GROUP BY DATE(data_richiesta)
                ORDER BY data
            ");
            $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
            break;
            
        case 'test_by_type':
            // Test per tipologia
            $stmt = $db->query("
                SELECT tipo_test, COUNT(*) as count
                FROM test
                WHERE MONTH(data_richiesta) = MONTH(CURDATE())
                GROUP BY tipo_test
            ");
            $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
            break;
    }
    
    echo json_encode($data);
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
                <h1 class="h2">Dashboard Amministratore</h1>
                <div class="btn-toolbar mb-2 mb-md-0">
                    <div class="btn-group me-2">
                        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="location.reload()">
                            <i class="fas fa-sync-alt"></i> Aggiorna
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="exportDashboard()">
                            <i class="fas fa-download"></i> Esporta
                        </button>
                    </div>
                </div>
            </div>
            
            <!-- Alerts -->
            <?php if (!empty($stats['alerts'])): ?>
                <?php foreach ($stats['alerts'] as $alert): ?>
                    <div class="alert alert-<?php echo $alert['type']; ?> alert-dismissible fade show" role="alert">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        <?php echo htmlspecialchars($alert['message']); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
            
            <!-- Cards statistiche -->
            <div class="row">
                <div class="col-xl-3 col-md-6 mb-4">
                    <div class="card border-left-primary shadow h-100 py-2">
                        <div class="card-body">
                            <div class="row no-gutters align-items-center">
                                <div class="col mr-2">
                                    <div class="text-xs font-weight-bold text-primary text-uppercase mb-1">
                                        Test Oggi
                                    </div>
                                    <div class="h5 mb-0 font-weight-bold text-gray-800">
                                        <?php echo number_format($stats['test_oggi']); ?>
                                    </div>
                                </div>
                                <div class="col-auto">
                                    <i class="fas fa-vial fa-2x text-gray-300"></i>
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
                                        Fatturato Mensile
                                    </div>
                                    <div class="h5 mb-0 font-weight-bold text-gray-800">
                                        <?php echo formatCurrency($stats['fatturato_mese']); ?>
                                    </div>
                                </div>
                                <div class="col-auto">
                                    <i class="fas fa-euro-sign fa-2x text-gray-300"></i>
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
                                        Test del Mese
                                    </div>
                                    <div class="h5 mb-0 font-weight-bold text-gray-800">
                                        <?php echo number_format($stats['test_mese']); ?>
                                    </div>
                                </div>
                                <div class="col-auto">
                                    <i class="fas fa-calendar fa-2x text-gray-300"></i>
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
                                        Utenti Attivi
                                    </div>
                                    <div class="h5 mb-0 font-weight-bold text-gray-800">
                                        <?php echo array_sum($stats['utenti']); ?>
                                    </div>
                                </div>
                                <div class="col-auto">
                                    <i class="fas fa-users fa-2x text-gray-300"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Grafici -->
            <div class="row">
                <div class="col-xl-8 col-lg-7">
                    <div class="card shadow mb-4">
                        <div class="card-header py-3 d-flex flex-row align-items-center justify-content-between">
                            <h6 class="m-0 font-weight-bold text-primary">Trend Test (ultimi 30 giorni)</h6>
                        </div>
                        <div class="card-body">
                            <div class="chart-area">
                                <canvas id="testTrendChart"></canvas>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-xl-4 col-lg-5">
                    <div class="card shadow mb-4">
                        <div class="card-header py-3 d-flex flex-row align-items-center justify-content-between">
                            <h6 class="m-0 font-weight-bold text-primary">Test per Tipologia</h6>
                        </div>
                        <div class="card-body">
                            <div class="chart-pie pt-4 pb-2">
                                <canvas id="testTypeChart"></canvas>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Tabelle -->
            <div class="row">
                <div class="col-lg-6">
                    <div class="card shadow mb-4">
                        <div class="card-header py-3">
                            <h6 class="m-0 font-weight-bold text-primary">Stato Test</h6>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-bordered" width="100%">
                                    <thead>
                                        <tr>
                                            <th>Stato</th>
                                            <th>Numero</th>
                                            <th>%</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php 
                                        $totalTest = array_sum($stats['test_per_stato']);
                                        foreach ($stats['test_per_stato'] as $stato => $count): 
                                            $percentage = $totalTest > 0 ? ($count / $totalTest * 100) : 0;
                                        ?>
                                        <tr>
                                            <td>
                                                <span class="badge bg-<?php echo getStatoBadgeClass($stato); ?>">
                                                    <?php echo ucfirst($stato); ?>
                                                </span>
                                            </td>
                                            <td><?php echo number_format($count); ?></td>
                                            <td>
                                                <div class="progress" style="height: 20px;">
                                                    <div class="progress-bar" style="width: <?php echo $percentage; ?>%">
                                                        <?php echo number_format($percentage, 1); ?>%
                                                    </div>
                                                </div>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-lg-6">
                    <div class="card shadow mb-4">
                        <div class="card-header py-3">
                            <h6 class="m-0 font-weight-bold text-primary">Ultimi Accessi</h6>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead>
                                        <tr>
                                            <th>Utente</th>
                                            <th>Tipo</th>
                                            <th>Ultimo Accesso</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($stats['ultimi_accessi'] as $user): ?>
                                        <tr>
                                            <td>
                                                <?php echo htmlspecialchars($user['nome'] . ' ' . $user['cognome']); ?>
                                                <br>
                                                <small class="text-muted"><?php echo htmlspecialchars($user['username']); ?></small>
                                            </td>
                                            <td>
                                                <span class="badge bg-secondary">
                                                    <?php echo ucfirst($user['tipo_utente']); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php if ($user['ultimo_accesso']): ?>
                                                    <?php echo formatDate($user['ultimo_accesso'], 'd/m/Y H:i'); ?>
                                                <?php else: ?>
                                                    <span class="text-muted">Mai</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Quick Actions -->
            <div class="row">
                <div class="col-12">
                    <div class="card shadow mb-4">
                        <div class="card-header py-3">
                            <h6 class="m-0 font-weight-bold text-primary">Azioni Rapide</h6>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-3 mb-3">
                                    <a href="users.php?action=new" class="btn btn-primary btn-block">
                                        <i class="fas fa-user-plus"></i> Nuovo Utente
                                    </a>
                                </div>
                                <div class="col-md-3 mb-3">
                                    <a href="genes.php?action=new" class="btn btn-success btn-block">
                                        <i class="fas fa-dna"></i> Nuovo Gene
                                    </a>
                                </div>
                                <div class="col-md-3 mb-3">
                                    <a href="panels.php?action=new" class="btn btn-info btn-block">
                                        <i class="fas fa-layer-group"></i> Nuovo Pannello
                                    </a>
                                </div>
                                <div class="col-md-3 mb-3">
                                    <a href="backup.php" class="btn btn-warning btn-block">
                                        <i class="fas fa-download"></i> Backup Sistema
                                    </a>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-3 mb-3">
                                    <a href="settings.php" class="btn btn-secondary btn-block">
                                        <i class="fas fa-cog"></i> Impostazioni
                                    </a>
                                </div>
                                <div class="col-md-3 mb-3">
                                    <a href="logs.php" class="btn btn-dark btn-block">
                                        <i class="fas fa-list"></i> Log Sistema
                                    </a>
                                </div>
                                <div class="col-md-3 mb-3">
                                    <a href="reports.php" class="btn btn-light btn-block">
                                        <i class="fas fa-chart-bar"></i> Report
                                    </a>
                                </div>
                                <div class="col-md-3 mb-3">
                                    <button onclick="clearCache()" class="btn btn-outline-danger btn-block">
                                        <i class="fas fa-broom"></i> Pulisci Cache
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>
</div>

<?php
// Funzione helper per badge stato
function getStatoBadgeClass($stato) {
    $classes = [
        'richiesto' => 'secondary',
        'in_lavorazione' => 'warning',
        'eseguito' => 'info',
        'refertato' => 'primary',
        'firmato' => 'success'
    ];
    return $classes[$stato] ?? 'secondary';
}
?>

<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<script>
// Configurazione grafici
document.addEventListener('DOMContentLoaded', function() {
    // Trend test
    fetch('dashboard.php?action=chart_data&type=test_trend')
        .then(response => response.json())
        .then(data => {
            const ctx = document.getElementById('testTrendChart').getContext('2d');
            new Chart(ctx, {
                type: 'line',
                data: {
                    labels: data.map(d => d.data),
                    datasets: [{
                        label: 'Test',
                        data: data.map(d => d.count),
                        borderColor: 'rgb(75, 192, 192)',
                        backgroundColor: 'rgba(75, 192, 192, 0.2)',
                        tension: 0.1
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: {
                        y: {
                            beginAtZero: true
                        }
                    }
                }
            });
        });
    
    // Test per tipo
    fetch('dashboard.php?action=chart_data&type=test_by_type')
        .then(response => response.json())
        .then(data => {
            const ctx = document.getElementById('testTypeChart').getContext('2d');
            new Chart(ctx, {
                type: 'doughnut',
                data: {
                    labels: data.map(d => d.tipo_test),
                    datasets: [{
                        data: data.map(d => d.count),
                        backgroundColor: [
                            'rgba(255, 99, 132, 0.8)',
                            'rgba(54, 162, 235, 0.8)',
                            'rgba(255, 205, 86, 0.8)',
                            'rgba(75, 192, 192, 0.8)'
                        ]
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false
                }
            });
        });
});

// Funzioni utility
function exportDashboard() {
    window.location.href = 'export.php?type=dashboard&format=pdf';
}

function clearCache() {
    if (confirm('Sei sicuro di voler pulire la cache del sistema?')) {
        fetch('api/clear-cache.php', {method: 'POST'})
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('Cache pulita con successo');
                    location.reload();
                }
            });
    }
}
</script>

<style>
/* Custom styles per dashboard */
.border-left-primary {
    border-left: 4px solid #4e73df;
}

.border-left-success {
    border-left: 4px solid #1cc88a;
}

.border-left-info {
    border-left: 4px solid #36b9cc;
}

.border-left-warning {
    border-left: 4px solid #f6c23e;
}

.card {
    transition: all 0.3s ease;
}

.card:hover {
    transform: translateY(-2px);
    box-shadow: 0 .5rem 1rem rgba(0,0,0,.15)!important;
}

.chart-area {
    position: relative;
    height: 300px;
    overflow: hidden;
}

.chart-pie {
    position: relative;
    height: 250px;
}

.progress {
    background-color: #eaecf4;
}

.btn-block {
    display: block;
    width: 100%;
}
</style>

<?php include '../templates/footer.php'; ?>