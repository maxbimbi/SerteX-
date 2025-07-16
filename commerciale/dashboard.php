<?php
/**
 * SerteX+ - Dashboard Commerciale
 */

require_once '../config/config.php';
require_once '../includes/functions.php';
require_once '../includes/security.php';

// Richiede autenticazione commerciale
requireAuth('commerciale');

// Carica classi necessarie
use SerteX\Invoice;

$db = getDatabase();

// Periodo di analisi (default: mese corrente)
$anno = $_GET['anno'] ?? date('Y');
$mese = $_GET['mese'] ?? date('m');

// Ottieni statistiche
$stats = getCommercialeStatistics($db, $anno, $mese);

// Funzione per ottenere statistiche commerciali
function getCommercialeStatistics($db, $anno, $mese) {
    $stats = ['anno' => $anno, 'mese' => $mese];
    
    try {
        // Fatturato del mese
        $stmt = $db->prepare("
            SELECT 
                COUNT(*) as num_fatture,
                COALESCE(SUM(importo_totale), 0) as imponibile,
                COALESCE(SUM(iva_totale), 0) as iva,
                COALESCE(SUM(importo_totale_ivato), 0) as totale
            FROM fatture 
            WHERE YEAR(data_emissione) = ? 
            AND MONTH(data_emissione) = ?
            AND stato != 'annullata'
        ");
        $stmt->execute([$anno, $mese]);
        $stats['fatturato_mese'] = $stmt->fetch();
        
        // Confronto mese precedente
        $mesePrecedente = $mese - 1;
        $annoPrecedente = $anno;
        if ($mesePrecedente < 1) {
            $mesePrecedente = 12;
            $annoPrecedente--;
        }
        
        $stmt = $db->prepare("
            SELECT COALESCE(SUM(importo_totale_ivato), 0) as totale
            FROM fatture 
            WHERE YEAR(data_emissione) = ? 
            AND MONTH(data_emissione) = ?
            AND stato != 'annullata'
        ");
        $stmt->execute([$annoPrecedente, $mesePrecedente]);
        $stats['fatturato_mese_precedente'] = $stmt->fetchColumn();
        
        // Calcola variazione percentuale
        if ($stats['fatturato_mese_precedente'] > 0) {
            $stats['variazione_mese'] = round(
                (($stats['fatturato_mese']['totale'] - $stats['fatturato_mese_precedente']) / 
                 $stats['fatturato_mese_precedente']) * 100, 1
            );
        } else {
            $stats['variazione_mese'] = 0;
        }
        
        // Fatturato anno
        $stmt = $db->prepare("
            SELECT 
                COUNT(*) as num_fatture,
                COALESCE(SUM(importo_totale_ivato), 0) as totale
            FROM fatture 
            WHERE YEAR(data_emissione) = ?
            AND stato != 'annullata'
        ");
        $stmt->execute([$anno]);
        $stats['fatturato_anno'] = $stmt->fetch();
        
        // Fatture per stato
        $stmt = $db->prepare("
            SELECT stato, COUNT(*) as count, COALESCE(SUM(importo_totale_ivato), 0) as totale
            FROM fatture 
            WHERE YEAR(data_emissione) = ? 
            AND MONTH(data_emissione) = ?
            GROUP BY stato
        ");
        $stmt->execute([$anno, $mese]);
        $stats['fatture_per_stato'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Top professionisti del mese
        $stmt = $db->prepare("
            SELECT 
                p.id,
                CONCAT(u.nome, ' ', u.cognome) as nome,
                COUNT(f.id) as num_fatture,
                COALESCE(SUM(f.importo_totale_ivato), 0) as totale
            FROM fatture f
            JOIN professionisti p ON f.professionista_id = p.id
            JOIN utenti u ON p.utente_id = u.id
            WHERE YEAR(f.data_emissione) = ? 
            AND MONTH(f.data_emissione) = ?
            AND f.stato != 'annullata'
            GROUP BY p.id
            ORDER BY totale DESC
            LIMIT 10
        ");
        $stmt->execute([$anno, $mese]);
        $stats['top_professionisti'] = $stmt->fetchAll();
        
        // Test fatturati vs non fatturati
        $stmt = $db->query("
            SELECT 
                SUM(CASE WHEN fatturato = 1 THEN 1 ELSE 0 END) as fatturati,
                SUM(CASE WHEN fatturato = 0 THEN 1 ELSE 0 END) as non_fatturati
            FROM test 
            WHERE stato IN ('refertato', 'firmato')
        ");
        $stats['test_fatturazione'] = $stmt->fetch();
        
        // Trend fatturato ultimi 12 mesi
        $stmt = $db->prepare("
            SELECT 
                MONTH(data_emissione) as mese,
                YEAR(data_emissione) as anno,
                COUNT(*) as num_fatture,
                COALESCE(SUM(importo_totale_ivato), 0) as totale
            FROM fatture 
            WHERE data_emissione >= DATE_SUB(CONCAT(?, '-', ?, '-01'), INTERVAL 11 MONTH)
            AND data_emissione < DATE_ADD(CONCAT(?, '-', ?, '-01'), INTERVAL 1 MONTH)
            AND stato != 'annullata'
            GROUP BY YEAR(data_emissione), MONTH(data_emissione)
            ORDER BY anno, mese
        ");
        $stmt->execute([$anno, $mese, $anno, $mese]);
        $stats['trend_12_mesi'] = $stmt->fetchAll();
        
        // Fatture in scadenza (non pagate)
        $stmt = $db->prepare("
            SELECT f.*, 
                   CONCAT(u.nome, ' ', u.cognome) as professionista_nome,
                   DATEDIFF(NOW(), f.data_emissione) as giorni_scadenza
            FROM fatture f
            JOIN professionisti p ON f.professionista_id = p.id
            JOIN utenti u ON p.utente_id = u.id
            WHERE f.stato = 'emessa'
            AND DATEDIFF(NOW(), f.data_emissione) > 30
            ORDER BY f.data_emissione
            LIMIT 10
        ");
        $stmt->execute();
        $stats['fatture_scadute'] = $stmt->fetchAll();
        
    } catch (Exception $e) {
        error_log("Errore statistiche commerciale: " . $e->getMessage());
    }
    
    return $stats;
}

// Gestione export
if (isset($_GET['export'])) {
    $format = $_GET['format'] ?? 'csv';
    
    // TODO: Implementare export dati
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="report_commerciale_' . $anno . '_' . $mese . '.csv"');
    
    // Output CSV
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
                <h1 class="h2">Dashboard Commerciale</h1>
                <div class="btn-toolbar mb-2 mb-md-0">
                    <!-- Selettore periodo -->
                    <div class="btn-group me-2">
                        <select class="form-select form-select-sm" id="monthSelector">
                            <?php for ($m = 1; $m <= 12; $m++): ?>
                            <option value="<?php echo $m; ?>" <?php echo $m == $mese ? 'selected' : ''; ?>>
                                <?php echo ucfirst(strftime('%B', mktime(0, 0, 0, $m, 1))); ?>
                            </option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div class="btn-group me-2">
                        <select class="form-select form-select-sm" id="yearSelector">
                            <?php for ($y = date('Y'); $y >= date('Y') - 3; $y--): ?>
                            <option value="<?php echo $y; ?>" <?php echo $y == $anno ? 'selected' : ''; ?>>
                                <?php echo $y; ?>
                            </option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div class="btn-group">
                        <a href="invoices.php?action=new" class="btn btn-sm btn-primary">
                            <i class="fas fa-plus"></i> Nuova Fattura
                        </a>
                        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="exportData()">
                            <i class="fas fa-download"></i> Esporta
                        </button>
                    </div>
                </div>
            </div>
            
            <!-- Alert fatture scadute -->
            <?php if (!empty($stats['fatture_scadute'])): ?>
            <div class="alert alert-warning alert-dismissible fade show" role="alert">
                <i class="fas fa-exclamation-triangle me-2"></i>
                <strong>Attenzione!</strong> Ci sono <?php echo count($stats['fatture_scadute']); ?> fatture non pagate da oltre 30 giorni.
                <a href="invoices.php?filter=scadute" class="alert-link">Visualizza</a>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>
            
            <!-- Cards statistiche -->
            <div class="row">
                <div class="col-xl-3 col-md-6 mb-4">
                    <div class="card bg-primary text-white shadow">
                        <div class="card-body">
                            <div class="row align-items-center">
                                <div class="col">
                                    <div class="text-xs font-weight-bold text-uppercase mb-1 opacity-75">
                                        Fatturato Mese
                                    </div>
                                    <div class="h4 mb-0 font-weight-bold">
                                        <?php echo formatCurrency($stats['fatturato_mese']['totale']); ?>
                                    </div>
                                    <small class="opacity-75">
                                        <?php if ($stats['variazione_mese'] > 0): ?>
                                            <i class="fas fa-arrow-up"></i> +<?php echo $stats['variazione_mese']; ?>%
                                        <?php elseif ($stats['variazione_mese'] < 0): ?>
                                            <i class="fas fa-arrow-down"></i> <?php echo $stats['variazione_mese']; ?>%
                                        <?php else: ?>
                                            <i class="fas fa-equals"></i> 0%
                                        <?php endif; ?>
                                        vs mese precedente
                                    </small>
                                </div>
                                <div class="col-auto">
                                    <i class="fas fa-euro-sign fa-3x opacity-25"></i>
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
                                    <div class="text-xs font-weight-bold text-uppercase mb-1 opacity-75">
                                        Fatture Mese
                                    </div>
                                    <div class="h4 mb-0 font-weight-bold">
                                        <?php echo number_format($stats['fatturato_mese']['num_fatture']); ?>
                                    </div>
                                    <small class="opacity-75">
                                        Media: <?php 
                                        $media = $stats['fatturato_mese']['num_fatture'] > 0 ? 
                                                $stats['fatturato_mese']['totale'] / $stats['fatturato_mese']['num_fatture'] : 0;
                                        echo formatCurrency($media); 
                                        ?>
                                    </small>
                                </div>
                                <div class="col-auto">
                                    <i class="fas fa-file-invoice fa-3x opacity-25"></i>
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
                                    <div class="text-xs font-weight-bold text-uppercase mb-1 opacity-75">
                                        Fatturato Anno
                                    </div>
                                    <div class="h4 mb-0 font-weight-bold">
                                        <?php echo formatCurrency($stats['fatturato_anno']['totale']); ?>
                                    </div>
                                    <small class="opacity-75">
                                        <?php echo $stats['fatturato_anno']['num_fatture']; ?> fatture
                                    </small>
                                </div>
                                <div class="col-auto">
                                    <i class="fas fa-calendar fa-3x opacity-25"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-xl-3 col-md-6 mb-4">
                    <div class="card bg-warning text-white shadow">
                        <div class="card-body">
                            <div class="row align-items-center">
                                <div class="col">
                                    <div class="text-xs font-weight-bold text-uppercase mb-1 opacity-75">
                                        Test da Fatturare
                                    </div>
                                    <div class="h4 mb-0 font-weight-bold">
                                        <?php echo number_format($stats['test_fatturazione']['non_fatturati'] ?? 0); ?>
                                    </div>
                                    <small class="opacity-75">
                                        Pronti per fatturazione
                                    </small>
                                </div>
                                <div class="col-auto">
                                    <i class="fas fa-exclamation-circle fa-3x opacity-25"></i>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Grafici -->
            <div class="row">
                <div class="col-xl-8">
                    <div class="card shadow mb-4">
                        <div class="card-header py-3">
                            <h6 class="m-0 font-weight-bold text-primary">Trend Fatturato (ultimi 12 mesi)</h6>
                        </div>
                        <div class="card-body">
                            <canvas id="trendChart" height="100"></canvas>
                        </div>
                    </div>
                </div>
                
                <div class="col-xl-4">
                    <div class="card shadow mb-4">
                        <div class="card-header py-3">
                            <h6 class="m-0 font-weight-bold text-primary">Fatture per Stato</h6>
                        </div>
                        <div class="card-body">
                            <canvas id="statusChart" height="150"></canvas>
                            <div class="mt-3">
                                <?php foreach ($stats['fatture_per_stato'] as $stato): ?>
                                <div class="d-flex justify-content-between align-items-center mb-2">
                                    <span class="badge bg-<?php echo getInvoiceStatusColor($stato['stato']); ?>">
                                        <?php echo ucfirst($stato['stato']); ?>
                                    </span>
                                    <span><?php echo formatCurrency($stato['totale']); ?></span>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Tabelle -->
            <div class="row">
                <!-- Top professionisti -->
                <div class="col-lg-6">
                    <div class="card shadow mb-4">
                        <div class="card-header py-3 d-flex justify-content-between align-items-center">
                            <h6 class="m-0 font-weight-bold text-primary">Top Professionisti del Mese</h6>
                            <a href="statistics.php?view=professionisti" class="btn btn-sm btn-primary">
                                Dettagli <i class="fas fa-arrow-right ms-1"></i>
                            </a>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead>
                                        <tr>
                                            <th>Professionista</th>
                                            <th class="text-center">Fatture</th>
                                            <th class="text-end">Totale</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($stats['top_professionisti'] as $prof): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($prof['nome']); ?></td>
                                            <td class="text-center">
                                                <span class="badge bg-secondary">
                                                    <?php echo $prof['num_fatture']; ?>
                                                </span>
                                            </td>
                                            <td class="text-end font-weight-bold">
                                                <?php echo formatCurrency($prof['totale']); ?>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                        
                                        <?php if (empty($stats['top_professionisti'])): ?>
                                        <tr>
                                            <td colspan="3" class="text-center text-muted py-4">
                                                Nessun dato disponibile
                                            </td>
                                        </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Fatture scadute -->
                <div class="col-lg-6">
                    <div class="card shadow mb-4">
                        <div class="card-header py-3 d-flex justify-content-between align-items-center">
                            <h6 class="m-0 font-weight-bold text-danger">Fatture Scadute</h6>
                            <a href="invoices.php?filter=scadute" class="btn btn-sm btn-danger">
                                Gestisci <i class="fas fa-arrow-right ms-1"></i>
                            </a>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead>
                                        <tr>
                                            <th>Numero</th>
                                            <th>Professionista</th>
                                            <th>Scadenza</th>
                                            <th>Importo</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($stats['fatture_scadute'] as $fattura): ?>
                                        <tr class="<?php echo $fattura['giorni_scadenza'] > 60 ? 'table-danger' : ''; ?>">
                                            <td>
                                                <a href="invoices.php?id=<?php echo $fattura['id']; ?>">
                                                    <?php echo htmlspecialchars($fattura['numero']); ?>
                                                </a>
                                            </td>
                                            <td><?php echo htmlspecialchars($fattura['professionista_nome']); ?></td>
                                            <td>
                                                <span class="badge bg-danger">
                                                    <?php echo $fattura['giorni_scadenza']; ?> giorni
                                                </span>
                                            </td>
                                            <td class="text-end">
                                                <?php echo formatCurrency($fattura['importo_totale_ivato']); ?>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                        
                                        <?php if (empty($stats['fatture_scadute'])): ?>
                                        <tr>
                                            <td colspan="4" class="text-center text-muted py-4">
                                                Nessuna fattura scaduta
                                            </td>
                                        </tr>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Azioni rapide -->
            <div class="card shadow mb-4">
                <div class="card-header py-3">
                    <h6 class="m-0 font-weight-bold text-primary">Azioni Rapide</h6>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-3 mb-3">
                            <button class="btn btn-primary btn-block w-100" onclick="generateMonthlyInvoices()">
                                <i class="fas fa-file-invoice me-2"></i>
                                Genera Fatture Mensili
                            </button>
                        </div>
                        <div class="col-md-3 mb-3">
                            <button class="btn btn-success btn-block w-100" onclick="sendReminders()">
                                <i class="fas fa-envelope me-2"></i>
                                Invia Solleciti
                            </button>
                        </div>
                        <div class="col-md-3 mb-3">
                            <a href="statistics.php" class="btn btn-info btn-block w-100">
                                <i class="fas fa-chart-bar me-2"></i>
                                Statistiche Avanzate
                            </a>
                        </div>
                        <div class="col-md-3 mb-3">
                            <button class="btn btn-warning btn-block w-100" onclick="generateXML()">
                                <i class="fas fa-file-code me-2"></i>
                                Genera XML SDI
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </main>
    </div>
</div>

<?php
// Funzioni helper
function getInvoiceStatusColor($stato) {
    $colors = [
        'bozza' => 'secondary',
        'emessa' => 'primary',
        'inviata' => 'info',
        'pagata' => 'success',
        'annullata' => 'danger'
    ];
    return $colors[$stato] ?? 'secondary';
}
?>

<!-- Chart.js -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<script>
// Selettori periodo
document.getElementById('monthSelector').addEventListener('change', function() {
    updatePeriod();
});

document.getElementById('yearSelector').addEventListener('change', function() {
    updatePeriod();
});

function updatePeriod() {
    const month = document.getElementById('monthSelector').value;
    const year = document.getElementById('yearSelector').value;
    window.location.href = `dashboard.php?mese=${month}&anno=${year}`;
}

// Grafico trend
const trendData = <?php echo json_encode($stats['trend_12_mesi']); ?>;
const trendCtx = document.getElementById('trendChart').getContext('2d');

new Chart(trendCtx, {
    type: 'line',
    data: {
        labels: trendData.map(d => {
            const months = ['Gen', 'Feb', 'Mar', 'Apr', 'Mag', 'Giu', 
                          'Lug', 'Ago', 'Set', 'Ott', 'Nov', 'Dic'];
            return months[d.mese - 1] + ' ' + d.anno;
        }),
        datasets: [{
            label: 'Fatturato',
            data: trendData.map(d => d.totale),
            borderColor: 'rgb(75, 192, 192)',
            backgroundColor: 'rgba(75, 192, 192, 0.2)',
            tension: 0.1,
            fill: true
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        scales: {
            y: {
                beginAtZero: true,
                ticks: {
                    callback: function(value) {
                        return '€ ' + value.toLocaleString('it-IT');
                    }
                }
            }
        },
        plugins: {
            tooltip: {
                callbacks: {
                    label: function(context) {
                        return context.dataset.label + ': € ' + context.parsed.y.toLocaleString('it-IT');
                    }
                }
            }
        }
    }
});

// Grafico stati
const statusData = <?php echo json_encode($stats['fatture_per_stato']); ?>;
const statusCtx = document.getElementById('statusChart').getContext('2d');

new Chart(statusCtx, {
    type: 'doughnut',
    data: {
        labels: statusData.map(s => s.stato.charAt(0).toUpperCase() + s.stato.slice(1)),
        datasets: [{
            data: statusData.map(s => s.count),
            backgroundColor: [
                'rgba(108, 117, 125, 0.8)',
                'rgba(13, 110, 253, 0.8)',
                'rgba(13, 202, 240, 0.8)',
                'rgba(25, 135, 84, 0.8)',
                'rgba(220, 53, 69, 0.8)'
            ]
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: {
                position: 'bottom'
            }
        }
    }
});

// Funzioni azioni
function generateMonthlyInvoices() {
    if (confirm('Vuoi generare le fatture mensili per tutti i professionisti?')) {
        fetch('api/generate-monthly-invoices.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': '<?php echo generateCSRFToken(); ?>'
            },
            body: JSON.stringify({
                month: <?php echo $mese; ?>,
                year: <?php echo $anno; ?>
            })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert(`Generate ${data.count} fatture con successo!`);
                location.reload();
            } else {
                alert('Errore: ' + data.error);
            }
        });
    }
}

function sendReminders() {
    if (confirm('Vuoi inviare i solleciti per le fatture scadute?')) {
        fetch('api/send-reminders.php', {
            method: 'POST',
            headers: {
                'X-CSRF-Token': '<?php echo generateCSRFToken(); ?>'
            }
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                alert(`Inviati ${data.count} solleciti!`);
            } else {
                alert('Errore: ' + data.error);
            }
        });
    }
}

function generateXML() {
    window.location.href = 'invoices.php?action=generate_xml&month=' + <?php echo $mese; ?> + '&year=' + <?php echo $anno; ?>;
}

function exportData() {
    window.location.href = 'dashboard.php?export=1&format=csv&mese=' + <?php echo $mese; ?> + '&anno=' + <?php echo $anno; ?>;
}
</script>

<style>
/* Custom styles per dashboard commerciale */
.opacity-25 {
    opacity: 0.25;
}

.opacity-75 {
    opacity: 0.75;
}

.card {
    transition: all 0.3s ease;
}

.card:hover {
    transform: translateY(-2px);
}

.btn-block {
    transition: all 0.3s ease;
}

.btn-block:hover {
    transform: translateY(-1px);
    box-shadow: 0 4px 8px rgba(0,0,0,0.1);
}
</style>

<?php include '../templates/footer.php'; ?>