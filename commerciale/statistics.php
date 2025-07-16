<?php
/**
 * Statistiche Commerciali - Area Commerciale
 * SerteX+ Genetic Lab Portal
 */

require_once '../includes/auth.php';
require_once '../includes/database.php';
require_once '../includes/session.php';
require_once '../includes/functions.php';

$auth = new Auth();
$session = Session::getInstance();
$db = Database::getInstance();

// Verifica autenticazione e ruolo
if (!$auth->isAuthenticated() || !$auth->hasRole('commerciale')) {
    header('Location: ../index.php');
    exit;
}

$user = $auth->getCurrentUser();

// Parametri filtri
$anno = intval($_GET['anno'] ?? date('Y'));
$mese = intval($_GET['mese'] ?? date('n'));
$tipo = $_GET['tipo'] ?? 'generale';
$professionistaId = intval($_GET['professionista_id'] ?? 0);

// Calcola date inizio/fine per query
$dataInizio = "$anno-01-01";
$dataFine = "$anno-12-31";

if ($tipo === 'mensile') {
    $dataInizio = "$anno-$mese-01";
    $dataFine = date('Y-m-t', strtotime($dataInizio));
}

// Query base per statistiche
$whereClause = "WHERE f.data_emissione BETWEEN :data_inizio AND :data_fine 
                AND f.stato != 'annullata'";
$params = ['data_inizio' => $dataInizio, 'data_fine' => $dataFine];

if ($professionistaId) {
    $whereClause .= " AND f.professionista_id = :prof_id";
    $params['prof_id'] = $professionistaId;
}

// Statistiche generali
$stats = [];

// Fatturato totale
$stats['fatturato_totale'] = $db->selectOne("
    SELECT 
        SUM(f.importo_totale) as imponibile,
        SUM(f.iva_totale) as iva,
        SUM(f.importo_totale_ivato) as totale,
        COUNT(DISTINCT f.id) as num_fatture
    FROM fatture f
    $whereClause
", $params);

// Fatturato per stato
$stats['per_stato'] = $db->select("
    SELECT 
        f.stato,
        COUNT(*) as num_fatture,
        SUM(f.importo_totale_ivato) as totale
    FROM fatture f
    $whereClause
    GROUP BY f.stato
", $params);

// Top professionisti
$stats['top_professionisti'] = $db->select("
    SELECT 
        p.id,
        CONCAT(u.cognome, ' ', u.nome) as professionista,
        COUNT(DISTINCT f.id) as num_fatture,
        COUNT(DISTINCT t.id) as num_test,
        SUM(f.importo_totale) as imponibile,
        SUM(f.importo_totale_ivato) as totale
    FROM fatture f
    INNER JOIN professionisti p ON f.professionista_id = p.id
    INNER JOIN utenti u ON p.utente_id = u.id
    LEFT JOIN test t ON t.fattura_id = f.id
    $whereClause
    GROUP BY p.id
    ORDER BY totale DESC
    LIMIT 10
", $params);

// Statistiche per tipo test
$stats['per_tipo_test'] = $db->select("
    SELECT 
        t.tipo_test,
        COUNT(DISTINCT t.id) as num_test,
        SUM(t.prezzo_finale) as totale,
        AVG(t.prezzo_finale) as media
    FROM test t
    INNER JOIN fatture f ON t.fattura_id = f.id
    $whereClause
    GROUP BY t.tipo_test
    ORDER BY totale DESC
", $params);

// Trend mensile (ultimi 12 mesi)
$stats['trend_mensile'] = [];
for ($i = 11; $i >= 0; $i--) {
    $meseData = date('Y-m', strtotime("-$i months"));
    $result = $db->selectOne("
        SELECT 
            COUNT(DISTINCT f.id) as num_fatture,
            SUM(f.importo_totale_ivato) as totale
        FROM fatture f
        WHERE DATE_FORMAT(f.data_emissione, '%Y-%m') = :mese
        AND f.stato != 'annullata'
    ", ['mese' => $meseData]);
    
    $stats['trend_mensile'][] = [
        'mese' => $meseData,
        'label' => date('M Y', strtotime($meseData . '-01')),
        'fatture' => $result['num_fatture'] ?? 0,
        'totale' => $result['totale'] ?? 0
    ];
}

// Statistiche pagamenti
$stats['pagamenti'] = $db->selectOne("
    SELECT 
        SUM(CASE WHEN f.stato = 'pagata' THEN f.importo_totale_ivato ELSE 0 END) as incassato,
        SUM(CASE WHEN f.stato IN ('emessa', 'inviata') THEN f.importo_totale_ivato ELSE 0 END) as da_incassare,
        COUNT(CASE WHEN f.stato IN ('emessa', 'inviata') THEN 1 END) as fatture_aperte
    FROM fatture f
    WHERE YEAR(f.data_emissione) = :anno
", ['anno' => $anno]);

// Test per periodo
$stats['test_periodo'] = $db->selectOne("
    SELECT 
        COUNT(DISTINCT t.id) as totali,
        COUNT(DISTINCT CASE WHEN t.stato = 'richiesto' THEN t.id END) as richiesti,
        COUNT(DISTINCT CASE WHEN t.stato = 'in_lavorazione' THEN t.id END) as in_lavorazione,
        COUNT(DISTINCT CASE WHEN t.stato = 'eseguito' THEN t.id END) as eseguiti,
        COUNT(DISTINCT CASE WHEN t.stato IN ('refertato', 'firmato') THEN t.id END) as completati,
        COUNT(DISTINCT CASE WHEN t.fatturato = 1 THEN t.id END) as fatturati
    FROM test t
    WHERE t.data_richiesta BETWEEN :data_inizio AND :data_fine
", ['data_inizio' => $dataInizio, 'data_fine' => $dataFine]);

// Carica lista professionisti
$professionisti = $db->select("
    SELECT p.id, CONCAT(u.cognome, ' ', u.nome) as nome
    FROM professionisti p
    INNER JOIN utenti u ON p.utente_id = u.id
    WHERE u.attivo = 1
    ORDER BY u.cognome, u.nome
");

// Genera dati per grafici
$chartData = [
    'trend' => [
        'labels' => array_column($stats['trend_mensile'], 'label'),
        'values' => array_column($stats['trend_mensile'], 'totale')
    ],
    'tipi' => [
        'labels' => array_column($stats['per_tipo_test'], 'tipo_test'),
        'values' => array_column($stats['per_tipo_test'], 'totale')
    ]
];

// Includi header
require_once '../templates/header.php';
?>

<div class="container-fluid">
    <div class="row">
        <?php require_once '../templates/sidebar.php'; ?>
        
        <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
            <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                <h1 class="h2">Statistiche Commerciali</h1>
                <div class="btn-toolbar mb-2 mb-md-0">
                    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="window.print()">
                        <i class="bi bi-printer"></i> Stampa
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-secondary ms-2" onclick="exportData()">
                        <i class="bi bi-download"></i> Esporta
                    </button>
                </div>
            </div>
            
            <!-- Filtri -->
            <div class="card mb-4">
                <div class="card-body">
                    <form method="get" class="row g-3">
                        <div class="col-md-2">
                            <label class="form-label">Tipo Report</label>
                            <select class="form-select" name="tipo" onchange="toggleMonthSelect(this.value)">
                                <option value="generale" <?php echo $tipo === 'generale' ? 'selected' : ''; ?>>
                                    Annuale
                                </option>
                                <option value="mensile" <?php echo $tipo === 'mensile' ? 'selected' : ''; ?>>
                                    Mensile
                                </option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Anno</label>
                            <select class="form-select" name="anno">
                                <?php for ($y = date('Y'); $y >= date('Y') - 5; $y--): ?>
                                    <option value="<?php echo $y; ?>" <?php echo $anno == $y ? 'selected' : ''; ?>>
                                        <?php echo $y; ?>
                                    </option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div class="col-md-2" id="monthSelect" style="<?php echo $tipo === 'generale' ? 'display:none' : ''; ?>">
                            <label class="form-label">Mese</label>
                            <select class="form-select" name="mese">
                                <?php for ($m = 1; $m <= 12; $m++): ?>
                                    <option value="<?php echo $m; ?>" <?php echo $mese == $m ? 'selected' : ''; ?>>
                                        <?php echo date('F', mktime(0, 0, 0, $m, 1)); ?>
                                    </option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Professionista</label>
                            <select class="form-select" name="professionista_id">
                                <option value="">Tutti</option>
                                <?php foreach ($professionisti as $prof): ?>
                                    <option value="<?php echo $prof['id']; ?>" 
                                            <?php echo $professionistaId == $prof['id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($prof['nome']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">&nbsp;</label>
                            <button type="submit" class="btn btn-primary w-100">
                                <i class="bi bi-search"></i> Filtra
                            </button>
                        </div>
                    </form>
                </div>
            </div>
            
            <!-- KPI Principali -->
            <div class="row mb-4">
                <div class="col-md-3">
                    <div class="card text-white bg-success">
                        <div class="card-body">
                            <h3 class="card-title">
                                € <?php echo number_format($stats['pagamenti']['incassato'] ?? 0, 2, ',', '.'); ?>
                            </h3>
                            <p class="card-text">Incassato</p>
                            <small>Anno <?php echo $anno; ?></small>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card text-white bg-warning">
                        <div class="card-body">
                            <h3 class="card-title">
                                € <?php echo number_format($stats['pagamenti']['da_incassare'] ?? 0, 2, ',', '.'); ?>
                            </h3>
                            <p class="card-text">Da Incassare</p>
                            <small><?php echo $stats['pagamenti']['fatture_aperte'] ?? 0; ?> fatture aperte</small>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card text-white bg-info">
                        <div class="card-body">
                            <h3 class="card-title">
                                <?php echo $stats['test_periodo']['fatturati'] ?? 0; ?>/<?php echo $stats['test_periodo']['totali'] ?? 0; ?>
                            </h3>
                            <p class="card-text">Test Fatturati</p>
                            <small><?php echo $stats['test_periodo']['totali'] - $stats['test_periodo']['fatturati']; ?> da fatturare</small>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Grafici -->
            <div class="row mb-4">
                <div class="col-md-8">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0">Trend Fatturato (ultimi 12 mesi)</h5>
                        </div>
                        <div class="card-body">
                            <canvas id="trendChart" height="100"></canvas>
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0">Distribuzione per Tipo Test</h5>
                        </div>
                        <div class="card-body">
                            <canvas id="tipiChart" height="200"></canvas>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Tabelle dettagli -->
            <div class="row">
                <div class="col-md-6">
                    <div class="card mb-4">
                        <div class="card-header">
                            <h5 class="mb-0">Top 10 Professionisti</h5>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-sm table-hover">
                                    <thead>
                                        <tr>
                                            <th>Professionista</th>
                                            <th class="text-center">Test</th>
                                            <th class="text-center">Fatture</th>
                                            <th class="text-end">Fatturato</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($stats['top_professionisti'] as $prof): ?>
                                            <tr>
                                                <td>
                                                    <a href="?tipo=<?php echo $tipo; ?>&anno=<?php echo $anno; ?>&mese=<?php echo $mese; ?>&professionista_id=<?php echo $prof['id']; ?>">
                                                        <?php echo htmlspecialchars($prof['professionista']); ?>
                                                    </a>
                                                </td>
                                                <td class="text-center"><?php echo $prof['num_test']; ?></td>
                                                <td class="text-center"><?php echo $prof['num_fatture']; ?></td>
                                                <td class="text-end">
                                                    € <?php echo number_format($prof['totale'], 2, ',', '.'); ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-6">
                    <div class="card mb-4">
                        <div class="card-header">
                            <h5 class="mb-0">Riepilogo per Tipo Test</h5>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-sm table-hover">
                                    <thead>
                                        <tr>
                                            <th>Tipo Test</th>
                                            <th class="text-center">Quantità</th>
                                            <th class="text-end">Media</th>
                                            <th class="text-end">Totale</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($stats['per_tipo_test'] as $tipo): ?>
                                            <tr>
                                                <td>
                                                    <?php
                                                    $tipoLabel = [
                                                        'genetico' => 'Test Genetico',
                                                        'microbiota' => 'Microbiota',
                                                        'intolleranze_cito' => 'Intoll. Citotossico',
                                                        'intolleranze_elisa' => 'Intoll. ELISA'
                                                    ];
                                                    echo $tipoLabel[$tipo['tipo_test']] ?? $tipo['tipo_test'];
                                                    ?>
                                                </td>
                                                <td class="text-center"><?php echo $tipo['num_test']; ?></td>
                                                <td class="text-end">
                                                    € <?php echo number_format($tipo['media'], 2, ',', '.'); ?>
                                                </td>
                                                <td class="text-end">
                                                    € <?php echo number_format($tipo['totale'], 2, ',', '.'); ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                    <tfoot>
                                        <tr class="table-primary">
                                            <th>Totale</th>
                                            <th class="text-center">
                                                <?php echo array_sum(array_column($stats['per_tipo_test'], 'num_test')); ?>
                                            </th>
                                            <th></th>
                                            <th class="text-end">
                                                € <?php echo number_format(array_sum(array_column($stats['per_tipo_test'], 'totale')), 2, ',', '.'); ?>
                                            </th>
                                        </tr>
                                    </tfoot>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Riepilogo Stati Fatture -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0">Riepilogo Stati Fatture</h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <?php foreach ($stats['per_stato'] as $stato): ?>
                            <div class="col-md-3 mb-3">
                                <div class="border rounded p-3">
                                    <h6><?php echo ucfirst($stato['stato']); ?></h6>
                                    <div class="d-flex justify-content-between">
                                        <span><?php echo $stato['num_fatture']; ?> fatture</span>
                                        <strong>€ <?php echo number_format($stato['totale'], 2, ',', '.'); ?></strong>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            
            <!-- Report Dettagliato -->
            <?php if ($professionistaId): ?>
                <?php
                // Dettaglio professionista selezionato
                $dettagliProf = $db->selectOne("
                    SELECT 
                        u.nome,
                        u.cognome,
                        u.email,
                        p.partita_iva,
                        p.codice_fiscale,
                        p.telefono
                    FROM professionisti p
                    INNER JOIN utenti u ON p.utente_id = u.id
                    WHERE p.id = :id
                ", ['id' => $professionistaId]);
                
                // Fatture del professionista nel periodo
                $fattureProf = $db->select("
                    SELECT 
                        f.*,
                        COUNT(t.id) as num_test
                    FROM fatture f
                    LEFT JOIN test t ON t.fattura_id = f.id
                    WHERE f.professionista_id = :prof_id
                    AND f.data_emissione BETWEEN :data_inizio AND :data_fine
                    GROUP BY f.id
                    ORDER BY f.data_emissione DESC
                ", array_merge($params, ['prof_id' => $professionistaId]));
                ?>
                
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">
                            Dettaglio: <?php echo htmlspecialchars($dettagliProf['cognome'] . ' ' . $dettagliProf['nome']); ?>
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="row mb-3">
                            <div class="col-md-4">
                                <strong>Email:</strong> <?php echo htmlspecialchars($dettagliProf['email']); ?><br>
                                <strong>Telefono:</strong> <?php echo htmlspecialchars($dettagliProf['telefono']); ?>
                            </div>
                            <div class="col-md-4">
                                <strong>P.IVA:</strong> <?php echo htmlspecialchars($dettagliProf['partita_iva']); ?><br>
                                <strong>C.F:</strong> <?php echo htmlspecialchars($dettagliProf['codice_fiscale']); ?>
                            </div>
                            <div class="col-md-4">
                                <strong>Periodo:</strong> 
                                <?php 
                                if ($tipo === 'mensile') {
                                    echo date('F Y', strtotime("$anno-$mese-01"));
                                } else {
                                    echo $anno;
                                }
                                ?>
                            </div>
                        </div>
                        
                        <h6>Fatture nel periodo</h6>
                        <div class="table-responsive">
                            <table class="table table-sm">
                                <thead>
                                    <tr>
                                        <th>Numero</th>
                                        <th>Data</th>
                                        <th>Test</th>
                                        <th>Imponibile</th>
                                        <th>IVA</th>
                                        <th>Totale</th>
                                        <th>Stato</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($fattureProf as $fattura): ?>
                                        <tr>
                                            <td>
                                                <a href="invoices.php?action=view&id=<?php echo $fattura['id']; ?>">
                                                    <?php echo htmlspecialchars($fattura['numero']); ?>
                                                </a>
                                            </td>
                                            <td><?php echo date('d/m/Y', strtotime($fattura['data_emissione'])); ?></td>
                                            <td><?php echo $fattura['num_test']; ?></td>
                                            <td>€ <?php echo number_format($fattura['importo_totale'], 2, ',', '.'); ?></td>
                                            <td>€ <?php echo number_format($fattura['iva_totale'], 2, ',', '.'); ?></td>
                                            <td>€ <?php echo number_format($fattura['importo_totale_ivato'], 2, ',', '.'); ?></td>
                                            <td>
                                                <span class="badge bg-<?php echo $fattura['stato'] === 'pagata' ? 'success' : 'warning'; ?>">
                                                    <?php echo ucfirst($fattura['stato']); ?>
                                                </span>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                                <tfoot>
                                    <tr class="table-primary">
                                        <th colspan="3">Totali</th>
                                        <th>€ <?php echo number_format(array_sum(array_column($fattureProf, 'importo_totale')), 2, ',', '.'); ?></th>
                                        <th>€ <?php echo number_format(array_sum(array_column($fattureProf, 'iva_totale')), 2, ',', '.'); ?></th>
                                        <th>€ <?php echo number_format(array_sum(array_column($fattureProf, 'importo_totale_ivato')), 2, ',', '.'); ?></th>
                                        <th></th>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </main>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js"></script>
<script>
// Configurazione grafici
const chartData = <?php echo json_encode($chartData); ?>;

// Grafico trend
const trendCtx = document.getElementById('trendChart').getContext('2d');
new Chart(trendCtx, {
    type: 'line',
    data: {
        labels: chartData.trend.labels,
        datasets: [{
            label: 'Fatturato',
            data: chartData.trend.values,
            borderColor: 'rgb(75, 192, 192)',
            backgroundColor: 'rgba(75, 192, 192, 0.2)',
            tension: 0.1
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: {
                display: false
            }
        },
        scales: {
            y: {
                beginAtZero: true,
                ticks: {
                    callback: function(value) {
                        return '€ ' + value.toLocaleString('it-IT');
                    }
                }
            }
        }
    }
});

// Grafico tipi test
const tipiCtx = document.getElementById('tipiChart').getContext('2d');
new Chart(tipiCtx, {
    type: 'doughnut',
    data: {
        labels: chartData.tipi.labels.map(label => {
            const labels = {
                'genetico': 'Genetico',
                'microbiota': 'Microbiota',
                'intolleranze_cito': 'Intoll. Cito',
                'intolleranze_elisa': 'Intoll. ELISA'
            };
            return labels[label] || label;
        }),
        datasets: [{
            data: chartData.tipi.values,
            backgroundColor: [
                'rgb(255, 99, 132)',
                'rgb(54, 162, 235)',
                'rgb(255, 205, 86)',
                'rgb(75, 192, 192)'
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

// Toggle selezione mese
function toggleMonthSelect(tipo) {
    document.getElementById('monthSelect').style.display = tipo === 'mensile' ? '' : 'none';
}

// Export dati
function exportData() {
    const params = new URLSearchParams(window.location.search);
    params.append('export', 'csv');
    window.location.href = 'statistics.php?' + params.toString();
}
</script>

<?php require_once '../templates/footer.php'; ?>">
                    <div class="card text-white bg-primary">
                        <div class="card-body">
                            <h3 class="card-title">
                                € <?php echo number_format($stats['fatturato_totale']['totale'] ?? 0, 2, ',', '.'); ?>
                            </h3>
                            <p class="card-text">Fatturato Totale</p>
                            <small><?php echo $stats['fatturato_totale']['num_fatture'] ?? 0; ?> fatture</small>
                        </div>
                    </div>
                </div>
                <div class="col-md-3