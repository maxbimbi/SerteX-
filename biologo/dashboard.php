<?php
/**
 * SerteX+ - Dashboard Biologo
 */

require_once '../config/config.php';
require_once '../includes/functions.php';
require_once '../includes/security.php';

// Richiede autenticazione biologo
requireAuth('biologo');

// Carica classi necessarie
use SerteX\Test;
use SerteX\Report;
use SerteX\Patient;

$db = getDatabase();

// Ottieni statistiche
$stats = getBiologoStatistics($db);

// Funzione per ottenere statistiche biologo
function getBiologoStatistics($db) {
    $stats = [];
    
    try {
        // Test da processare per stato
        $stmt = $db->query("
            SELECT stato, COUNT(*) as count 
            FROM test 
            WHERE stato IN ('richiesto', 'in_lavorazione', 'eseguito')
            GROUP BY stato
        ");
        $stats['test_da_processare'] = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        
        // Test refertati oggi
        $stmt = $db->query("
            SELECT COUNT(*) FROM test 
            WHERE DATE(data_refertazione) = CURDATE()
        ");
        $stats['refertati_oggi'] = $stmt->fetchColumn();
        
        // Test refertati questa settimana
        $stmt = $db->query("
            SELECT COUNT(*) FROM test 
            WHERE YEARWEEK(data_refertazione) = YEARWEEK(CURDATE())
        ");
        $stats['refertati_settimana'] = $stmt->fetchColumn();
        
        // Test per tipologia da processare
        $stmt = $db->query("
            SELECT tipo_test, COUNT(*) as count 
            FROM test 
            WHERE stato IN ('richiesto', 'in_lavorazione', 'eseguito')
            GROUP BY tipo_test
        ");
        $stats['per_tipologia'] = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        
        // Test urgenti (più di 3 giorni)
        $stmt = $db->query("
            SELECT COUNT(*) FROM test 
            WHERE stato IN ('richiesto', 'in_lavorazione', 'eseguito')
            AND DATEDIFF(NOW(), data_richiesta) > 3
        ");
        $stats['test_urgenti'] = $stmt->fetchColumn();
        
        // Ultimi test processati
        $stmt = $db->query("
            SELECT t.*, 
                   p.iniziali, p.sesso, p.eta,
                   r.id as referto_id, r.file_path_firmato
            FROM test t
            JOIN (
                SELECT paziente_id,
                       CONCAT(LEFT(nome, 1), '.', LEFT(cognome, 1), '.') as iniziali,
                       sesso,
                       YEAR(CURDATE()) - YEAR(data_nascita) as eta
                FROM pazienti
            ) p ON t.paziente_id = p.paziente_id
            LEFT JOIN referti r ON t.id = r.test_id
            WHERE t.stato IN ('refertato', 'firmato')
            AND t.data_refertazione >= DATE_SUB(NOW(), INTERVAL 7 DAY)
            ORDER BY t.data_refertazione DESC
            LIMIT 10
        ");
        $stats['ultimi_refertati'] = $stmt->fetchAll();
        
    } catch (Exception $e) {
        error_log("Errore statistiche biologo: " . $e->getMessage());
    }
    
    return $stats;
}

// Gestione azioni
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    requireCSRF();
    
    switch ($_POST['action']) {
        case 'start_processing':
            $testId = validateId($_POST['test_id']);
            if ($testId) {
                $test = new Test($db, $testId);
                if ($test->updateStato(Test::STATO_IN_LAVORAZIONE)) {
                    $_SESSION['success_message'] = "Test avviato in lavorazione";
                }
            }
            break;
    }
    
    header('Location: dashboard.php');
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
                <h1 class="h2">Dashboard Biologo</h1>
                <div class="btn-toolbar mb-2 mb-md-0">
                    <div class="btn-group me-2">
                        <a href="tests.php" class="btn btn-sm btn-outline-primary">
                            <i class="fas fa-vial"></i> Tutti i Test
                        </a>
                        <a href="results.php" class="btn btn-sm btn-outline-success">
                            <i class="fas fa-edit"></i> Inserisci Risultati
                        </a>
                    </div>
                </div>
            </div>
            
            <?php if (isset($_SESSION['success_message'])): ?>
                <div class="alert alert-success alert-dismissible fade show">
                    <?php 
                    echo htmlspecialchars($_SESSION['success_message']);
                    unset($_SESSION['success_message']);
                    ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
            
            <!-- Alert test urgenti -->
            <?php if ($stats['test_urgenti'] > 0): ?>
                <div class="alert alert-warning alert-dismissible fade show" role="alert">
                    <i class="fas fa-exclamation-triangle me-2"></i>
                    <strong>Attenzione!</strong> Ci sono <?php echo $stats['test_urgenti']; ?> test in attesa da più di 3 giorni.
                    <a href="tests.php?filter=urgent" class="alert-link">Visualizza</a>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
            
            <!-- Cards statistiche -->
            <div class="row">
                <div class="col-xl-3 col-md-6 mb-4">
                    <div class="card bg-warning text-white shadow">
                        <div class="card-body">
                            <div class="row align-items-center">
                                <div class="col">
                                    <div class="text-xs font-weight-bold text-uppercase mb-1">
                                        Da Processare
                                    </div>
                                    <div class="h5 mb-0 font-weight-bold">
                                        <?php echo array_sum($stats['test_da_processare']); ?>
                                    </div>
                                </div>
                                <div class="col-auto">
                                    <i class="fas fa-clock fa-2x opacity-50"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-xl-3 col-md-6 mb-4">
                    <div class="card bg-success text-white shadow">
                        <div class="card-body">
                            <div class="row align-items-center">
                                <div class="col">
                                    <div class="text-xs font-weight-bold text-uppercase mb-1">
                                        Refertati Oggi
                                    </div>
                                    <div class="h5 mb-0 font-weight-bold">
                                        <?php echo $stats['refertati_oggi']; ?>
                                    </div>
                                </div>
                                <div class="col-auto">
                                    <i class="fas fa-check-circle fa-2x opacity-50"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-xl-3 col-md-6 mb-4">
                    <div class="card bg-info text-white shadow">
                        <div class="card-body">
                            <div class="row align-items-center">
                                <div class="col">
                                    <div class="text-xs font-weight-bold text-uppercase mb-1">
                                        Settimana
                                    </div>
                                    <div class="h5 mb-0 font-weight-bold">
                                        <?php echo $stats['refertati_settimana']; ?>
                                    </div>
                                </div>
                                <div class="col-auto">
                                    <i class="fas fa-calendar-week fa-2x opacity-50"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-xl-3 col-md-6 mb-4">
                    <div class="card bg-danger text-white shadow">
                        <div class="card-body">
                            <div class="row align-items-center">
                                <div class="col">
                                    <div class="text-xs font-weight-bold text-uppercase mb-1">
                                        Urgenti
                                    </div>
                                    <div class="h5 mb-0 font-weight-bold">
                                        <?php echo $stats['test_urgenti']; ?>
                                    </div>
                                </div>
                                <div class="col-auto">
                                    <i class="fas fa-exclamation-circle fa-2x opacity-50"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Test da processare per tipo -->
            <div class="row">
                <div class="col-lg-6">
                    <div class="card shadow mb-4">
                        <div class="card-header py-3">
                            <h6 class="m-0 font-weight-bold text-primary">Test da Processare per Stato</h6>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <?php foreach ($stats['test_da_processare'] as $stato => $count): ?>
                                <div class="col-4 text-center mb-3">
                                    <div class="h1 font-weight-bold text-<?php echo getStatoColor($stato); ?>">
                                        <?php echo $count; ?>
                                    </div>
                                    <div class="text-uppercase text-muted small">
                                        <?php echo str_replace('_', ' ', $stato); ?>
                                    </div>
                                    <a href="tests.php?stato=<?php echo $stato; ?>" class="btn btn-sm btn-outline-primary mt-2">
                                        Visualizza
                                    </a>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-lg-6">
                    <div class="card shadow mb-4">
                        <div class="card-header py-3">
                            <h6 class="m-0 font-weight-bold text-primary">Test da Processare per Tipologia</h6>
                        </div>
                        <div class="card-body">
                            <canvas id="testByTypeChart" height="150"></canvas>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Test da processare -->
            <div class="card shadow mb-4">
                <div class="card-header py-3 d-flex justify-content-between align-items-center">
                    <h6 class="m-0 font-weight-bold text-primary">Test in Attesa</h6>
                    <a href="tests.php?stato=richiesto" class="btn btn-sm btn-primary">
                        Vedi Tutti <i class="fas fa-arrow-right ms-1"></i>
                    </a>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>Codice</th>
                                    <th>Tipo</th>
                                    <th>Paziente</th>
                                    <th>Data Richiesta</th>
                                    <th>Giorni</th>
                                    <th>Azioni</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                // Recupera test in attesa
                                $stmt = $db->query("
                                    SELECT t.*, 
                                           p.iniziali, p.sesso, p.eta,
                                           DATEDIFF(NOW(), t.data_richiesta) as giorni_attesa
                                    FROM test t
                                    JOIN (
                                        SELECT id as paziente_id,
                                               CONCAT(LEFT(nome, 1), '.', LEFT(cognome, 1), '.') as iniziali,
                                               sesso,
                                               YEAR(CURDATE()) - YEAR(data_nascita) as eta
                                        FROM pazienti
                                    ) p ON t.paziente_id = p.paziente_id
                                    WHERE t.stato = 'richiesto'
                                    ORDER BY t.data_richiesta ASC
                                    LIMIT 10
                                ");
                                $testInAttesa = $stmt->fetchAll();
                                
                                foreach ($testInAttesa as $test):
                                ?>
                                <tr class="<?php echo $test['giorni_attesa'] > 3 ? 'table-warning' : ''; ?>">
                                    <td>
                                        <a href="test-details.php?id=<?php echo $test['id']; ?>">
                                            <?php echo htmlspecialchars($test['codice']); ?>
                                        </a>
                                    </td>
                                    <td>
                                        <span class="badge bg-<?php echo getTestTypeColor($test['tipo_test']); ?>">
                                            <?php echo getTestTypeLabel($test['tipo_test']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php echo htmlspecialchars($test['iniziali']); ?>
                                        <small class="text-muted">
                                            (<?php echo $test['sesso']; ?>, <?php echo $test['eta']; ?> anni)
                                        </small>
                                    </td>
                                    <td><?php echo formatDate($test['data_richiesta']); ?></td>
                                    <td>
                                        <?php if ($test['giorni_attesa'] > 3): ?>
                                            <span class="badge bg-danger"><?php echo $test['giorni_attesa']; ?> giorni</span>
                                        <?php else: ?>
                                            <?php echo $test['giorni_attesa']; ?> giorni
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <form method="post" class="d-inline">
                                            <?php echo csrfInput(); ?>
                                            <input type="hidden" name="action" value="start_processing">
                                            <input type="hidden" name="test_id" value="<?php echo $test['id']; ?>">
                                            <button type="submit" class="btn btn-sm btn-primary">
                                                <i class="fas fa-play"></i> Avvia
                                            </button>
                                        </form>
                                        <a href="results.php?test_id=<?php echo $test['id']; ?>" 
                                           class="btn btn-sm btn-success">
                                            <i class="fas fa-edit"></i> Risultati
                                        </a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                
                                <?php if (empty($testInAttesa)): ?>
                                <tr>
                                    <td colspan="6" class="text-center text-muted py-4">
                                        Nessun test in attesa
                                    </td>
                                </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            
            <!-- Ultimi refertati -->
            <div class="card shadow mb-4">
                <div class="card-header py-3">
                    <h6 class="m-0 font-weight-bold text-primary">Ultimi Test Refertati</h6>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>Codice</th>
                                    <th>Tipo</th>
                                    <th>Paziente</th>
                                    <th>Data Refertazione</th>
                                    <th>Stato</th>
                                    <th>Azioni</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($stats['ultimi_refertati'] as $test): ?>
                                <tr>
                                    <td>
                                        <a href="test-details.php?id=<?php echo $test['id']; ?>">
                                            <?php echo htmlspecialchars($test['codice']); ?>
                                        </a>
                                    </td>
                                    <td>
                                        <span class="badge bg-<?php echo getTestTypeColor($test['tipo_test']); ?>">
                                            <?php echo getTestTypeLabel($test['tipo_test']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php echo htmlspecialchars($test['iniziali']); ?>
                                        <small class="text-muted">
                                            (<?php echo $test['sesso']; ?>, <?php echo $test['eta']; ?> anni)
                                        </small>
                                    </td>
                                    <td><?php echo formatDate($test['data_refertazione'], 'd/m/Y H:i'); ?></td>
                                    <td>
                                        <?php if ($test['file_path_firmato']): ?>
                                            <span class="badge bg-success">Firmato</span>
                                        <?php else: ?>
                                            <span class="badge bg-primary">Refertato</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <a href="reports.php?action=view&id=<?php echo $test['referto_id']; ?>" 
                                           class="btn btn-sm btn-info">
                                            <i class="fas fa-file-pdf"></i> Referto
                                        </a>
                                        <?php if (!$test['file_path_firmato']): ?>
                                            <a href="reports.php?action=sign&id=<?php echo $test['referto_id']; ?>" 
                                               class="btn btn-sm btn-warning">
                                                <i class="fas fa-signature"></i> Firma
                                            </a>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </main>
    </div>
</div>

<?php
// Funzioni helper
function getStatoColor($stato) {
    $colors = [
        'richiesto' => 'secondary',
        'in_lavorazione' => 'warning',
        'eseguito' => 'info'
    ];
    return $colors[$stato] ?? 'secondary';
}

function getTestTypeColor($tipo) {
    $colors = [
        'genetico' => 'primary',
        'microbiota' => 'success',
        'intolleranze_cito' => 'warning',
        'intolleranze_elisa' => 'info'
    ];
    return $colors[$tipo] ?? 'secondary';
}

function getTestTypeLabel($tipo) {
    $labels = [
        'genetico' => 'Genetico',
        'microbiota' => 'Microbiota',
        'intolleranze_cito' => 'Intoll. Cito',
        'intolleranze_elisa' => 'Intoll. ELISA'
    ];
    return $labels[$tipo] ?? $tipo;
}
?>

<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<script>
// Grafico test per tipologia
const testData = <?php echo json_encode($stats['per_tipologia']); ?>;
const ctx = document.getElementById('testByTypeChart').getContext('2d');

new Chart(ctx, {
    type: 'bar',
    data: {
        labels: Object.keys(testData).map(type => {
            const labels = {
                'genetico': 'Genetico',
                'microbiota': 'Microbiota',
                'intolleranze_cito': 'Intoll. Cito',
                'intolleranze_elisa': 'Intoll. ELISA'
            };
            return labels[type] || type;
        }),
        datasets: [{
            label: 'Test da processare',
            data: Object.values(testData),
            backgroundColor: [
                'rgba(54, 162, 235, 0.8)',
                'rgba(75, 192, 192, 0.8)',
                'rgba(255, 206, 86, 0.8)',
                'rgba(153, 102, 255, 0.8)'
            ],
            borderColor: [
                'rgba(54, 162, 235, 1)',
                'rgba(75, 192, 192, 1)',
                'rgba(255, 206, 86, 1)',
                'rgba(153, 102, 255, 1)'
            ],
            borderWidth: 1
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        scales: {
            y: {
                beginAtZero: true,
                ticks: {
                    stepSize: 1
                }
            }
        }
    }
});
</script>

<style>
/* Custom styles per dashboard biologo */
.card {
    transition: all 0.3s ease;
}

.card:hover {
    transform: translateY(-2px);
}

.opacity-50 {
    opacity: 0.5;
}

.table-hover tbody tr:hover {
    background-color: rgba(0,0,0,.02);
}
</style>

<?php include '../templates/footer.php'; ?>