<?php
/**
 * Gestione Ordini e Proforma - Area Commerciale
 * SerteX+ Genetic Lab Portal
 */

require_once '../includes/auth.php';
require_once '../includes/database.php';
require_once '../includes/session.php';
require_once '../includes/functions.php';
require_once '../classes/Logger.php';

$auth = new Auth();
$session = Session::getInstance();
$db = Database::getInstance();
$logger = new Logger();

// Verifica autenticazione e ruolo
if (!$auth->isAuthenticated() || !$auth->hasRole('commerciale')) {
    header('Location: ../index.php');
    exit;
}

$user = $auth->getCurrentUser();
$action = $_GET['action'] ?? 'dashboard';

// Dashboard ordini e test
if ($action === 'dashboard') {
    // Test in vari stati
    $testStats = $db->selectOne("
        SELECT 
            COUNT(CASE WHEN stato = 'richiesto' THEN 1 END) as richiesti,
            COUNT(CASE WHEN stato = 'in_lavorazione' THEN 1 END) as in_lavorazione,
            COUNT(CASE WHEN stato = 'eseguito' THEN 1 END) as eseguiti,
            COUNT(CASE WHEN stato IN ('refertato', 'firmato') AND fatturato = 0 THEN 1 END) as da_fatturare,
            COUNT(CASE WHEN stato IN ('refertato', 'firmato') AND fatturato = 1 THEN 1 END) as fatturati,
            COUNT(*) as totali
        FROM test
        WHERE data_richiesta >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    ");
    
    // Valore test da fatturare
    $valoreDaFatturare = $db->selectOne("
        SELECT 
            SUM(t.prezzo_finale) as totale,
            COUNT(DISTINCT t.professionista_id) as num_professionisti
        FROM test t
        WHERE t.stato IN ('refertato', 'firmato')
        AND t.fatturato = 0
    ");
    
    // Test recenti
    $testRecenti = $db->select("
        SELECT 
            t.*,
            p.nome as paziente_nome,
            p.cognome as paziente_cognome,
            pr.nome as professionista_nome,
            pr.cognome as professionista_cognome
        FROM test t
        INNER JOIN pazienti p ON t.paziente_id = p.id
        INNER JOIN professionisti prof ON t.professionista_id = prof.id
        INNER JOIN utenti pr ON prof.utente_id = pr.id
        ORDER BY t.data_richiesta DESC
        LIMIT 20
    ");
    
    // Professionisti con test da fatturare
    $professionistiDaFatturare = $db->select("
        SELECT 
            prof.id,
            u.nome,
            u.cognome,
            pr.partita_iva,
            COUNT(t.id) as num_test,
            SUM(t.prezzo_finale) as totale,
            MIN(t.data_richiesta) as primo_test,
            MAX(t.data_richiesta) as ultimo_test
        FROM test t
        INNER JOIN professionisti prof ON t.professionista_id = prof.id
        INNER JOIN utenti u ON prof.utente_id = u.id
        INNER JOIN professionisti pr ON pr.id = prof.id
        WHERE t.stato IN ('refertato', 'firmato')
        AND t.fatturato = 0
        GROUP BY prof.id
        HAVING num_test > 0
        ORDER BY totale DESC
    ");
    
} elseif ($action === 'proforma') {
    // Gestione proforma
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $csrfToken = $_POST['csrf_token'] ?? '';
        
        if (!$session->validateCsrfToken($csrfToken)) {
            $session->setFlash('error', 'Token di sicurezza non valido');
        } else {
            // Genera proforma
            $professionistaId = intval($_POST['professionista_id']);
            $includiTest = $_POST['test_ids'] ?? [];
            
            if ($professionistaId && !empty($includiTest)) {
                try {
                    // Recupera dati professionista
                    $professionista = $db->selectOne("
                        SELECT p.*, u.nome, u.cognome, u.email
                        FROM professionisti p
                        INNER JOIN utenti u ON p.utente_id = u.id
                        WHERE p.id = :id
                    ", ['id' => $professionistaId]);
                    
                    // Recupera test selezionati
                    $placeholders = implode(',', array_fill(0, count($includiTest), '?'));
                    $tests = $db->select("
                        SELECT t.*, p.nome as paziente_nome, p.cognome as paziente_cognome
                        FROM test t
                        INNER JOIN pazienti p ON t.paziente_id = p.id
                        WHERE t.id IN ($placeholders)
                        AND t.professionista_id = ?
                        AND t.fatturato = 0
                    ", array_merge($includiTest, [$professionistaId]));
                    
                    if (!empty($tests)) {
                        // Genera proforma (PDF)
                        $proformaData = [
                            'numero' => 'PRO-' . date('Y') . '-' . str_pad(mt_rand(1, 9999), 4, '0', STR_PAD_LEFT),
                            'data' => date('Y-m-d'),
                            'professionista' => $professionista,
                            'tests' => $tests,
                            'totale' => array_sum(array_column($tests, 'prezzo_finale'))
                        ];
                        
                        // TODO: Generare PDF proforma
                        $logger->log($user->getId(), 'proforma_generata', 
                                   "Proforma {$proformaData['numero']} per professionista ID: {$professionistaId}");
                        
                        $session->setFlash('success', 
                                         "Proforma {$proformaData['numero']} generata con successo");
                        
                        // Per ora mostra solo i dati
                        $_SESSION['proforma_data'] = $proformaData;
                        header('Location: orders.php?action=view_proforma');
                        exit;
                    }
                } catch (Exception $e) {
                    $session->setFlash('error', 'Errore nella generazione della proforma');
                }
            }
        }
    }
    
} elseif ($action === 'view_proforma' && isset($_SESSION['proforma_data'])) {
    // Visualizza proforma generata
    $proformaData = $_SESSION['proforma_data'];
    unset($_SESSION['proforma_data']);
}

// Genera token CSRF
$csrfToken = $session->generateCsrfToken();

// Includi header
require_once '../templates/header.php';
?>

<div class="container-fluid">
    <div class="row">
        <?php require_once '../templates/sidebar.php'; ?>
        
        <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
            <?php if ($action === 'dashboard'): ?>
                <!-- Dashboard Ordini -->
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h2">Gestione Ordini</h1>
                    <div class="btn-toolbar mb-2 mb-md-0">
                        <a href="invoices.php?action=new" class="btn btn-sm btn-primary">
                            <i class="bi bi-file-text"></i> Nuova Fattura
                        </a>
                    </div>
                </div>
                
                <?php foreach ($session->getFlashMessages() as $flash): ?>
                    <div class="alert alert-<?php echo $flash['type'] === 'error' ? 'danger' : $flash['type']; ?> alert-dismissible fade show" role="alert">
                        <?php echo htmlspecialchars($flash['message']); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endforeach; ?>
                
                <!-- Statistiche Test -->
                <h5 class="mb-3">Stato Test (ultimi 30 giorni)</h5>
                <div class="row mb-4">
                    <div class="col-md-2">
                        <div class="card">
                            <div class="card-body">
                                <h5 class="card-title"><?php echo $testStats['richiesti']; ?></h5>
                                <p class="card-text text-muted">Richiesti</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="card">
                            <div class="card-body">
                                <h5 class="card-title"><?php echo $testStats['in_lavorazione']; ?></h5>
                                <p class="card-text text-muted">In Lavorazione</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="card">
                            <div class="card-body">
                                <h5 class="card-title"><?php echo $testStats['eseguiti']; ?></h5>
                                <p class="card-text text-muted">Eseguiti</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="card text-white bg-warning">
                            <div class="card-body">
                                <h5 class="card-title"><?php echo $testStats['da_fatturare']; ?></h5>
                                <p class="card-text">Da Fatturare</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="card text-white bg-success">
                            <div class="card-body">
                                <h5 class="card-title"><?php echo $testStats['fatturati']; ?></h5>
                                <p class="card-text">Fatturati</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-2">
                        <div class="card text-white bg-primary">
                            <div class="card-body">
                                <h5 class="card-title">€ <?php echo number_format($valoreDaFatturare['totale'] ?? 0, 0, ',', '.'); ?></h5>
                                <p class="card-text">Valore da Fatt.</p>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Professionisti con test da fatturare -->
                <div class="card mb-4">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">Professionisti con Test da Fatturare</h5>
                        <span class="badge bg-info">
                            <?php echo count($professionistiDaFatturare); ?> professionisti
                        </span>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($professionistiDaFatturare)): ?>
                            <div class="table-responsive">
                                <table class="table table-hover">
                                    <thead>
                                        <tr>
                                            <th>Professionista</th>
                                            <th>P.IVA</th>
                                            <th class="text-center">Test</th>
                                            <th>Periodo</th>
                                            <th class="text-end">Totale</th>
                                            <th>Azioni</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($professionistiDaFatturare as $prof): ?>
                                            <tr>
                                                <td>
                                                    <strong><?php echo htmlspecialchars($prof['cognome'] . ' ' . $prof['nome']); ?></strong>
                                                </td>
                                                <td><?php echo htmlspecialchars($prof['partita_iva']); ?></td>
                                                <td class="text-center">
                                                    <span class="badge bg-warning"><?php echo $prof['num_test']; ?></span>
                                                </td>
                                                <td>
                                                    <?php echo date('d/m', strtotime($prof['primo_test'])); ?> - 
                                                    <?php echo date('d/m/Y', strtotime($prof['ultimo_test'])); ?>
                                                </td>
                                                <td class="text-end">
                                                    <strong>€ <?php echo number_format($prof['totale'], 2, ',', '.'); ?></strong>
                                                </td>
                                                <td>
                                                    <div class="btn-group btn-group-sm" role="group">
                                                        <button type="button" class="btn btn-info" 
                                                                onclick="viewProfTests(<?php echo $prof['id']; ?>)"
                                                                title="Dettagli">
                                                            <i class="bi bi-eye"></i>
                                                        </button>
                                                        <button type="button" class="btn btn-warning" 
                                                                onclick="generateProforma(<?php echo $prof['id']; ?>)"
                                                                title="Genera Proforma">
                                                            <i class="bi bi-file-earmark-text"></i>
                                                        </button>
                                                        <form method="post" action="invoices.php" class="d-inline">
                                                            <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                                                            <input type="hidden" name="action" value="create_monthly">
                                                            <input type="hidden" name="professionista_id" value="<?php echo $prof['id']; ?>">
                                                            <input type="hidden" name="mese" value="<?php echo date('n'); ?>">
                                                            <input type="hidden" name="anno" value="<?php echo date('Y'); ?>">
                                                            <button type="submit" class="btn btn-success" 
                                                                    title="Fattura Tutti">
                                                                <i class="bi bi-receipt"></i>
                                                            </button>
                                                        </form>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php else: ?>
                            <p class="text-muted text-center py-3">
                                Nessun test da fatturare al momento
                            </p>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Test Recenti -->
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">Test Recenti</h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-sm table-hover">
                                <thead>
                                    <tr>
                                        <th>Codice</th>
                                        <th>Data</th>
                                        <th>Paziente</th>
                                        <th>Professionista</th>
                                        <th>Tipo</th>
                                        <th>Stato</th>
                                        <th class="text-end">Prezzo</th>
                                        <th>Fatturato</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($testRecenti as $test): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($test['codice']); ?></td>
                                            <td><?php echo date('d/m/Y', strtotime($test['data_richiesta'])); ?></td>
                                            <td>
                                                <?php echo htmlspecialchars($test['paziente_cognome'] . ' ' . $test['paziente_nome']); ?>
                                            </td>
                                            <td>
                                                <?php echo htmlspecialchars($test['professionista_cognome'] . ' ' . $test['professionista_nome']); ?>
                                            </td>
                                            <td>
                                                <?php
                                                $tipoLabel = [
                                                    'genetico' => 'Genetico',
                                                    'microbiota' => 'Microbiota',
                                                    'intolleranze_cito' => 'Intoll. Cito',
                                                    'intolleranze_elisa' => 'Intoll. ELISA'
                                                ];
                                                echo $tipoLabel[$test['tipo_test']] ?? $test['tipo_test'];
                                                ?>
                                            </td>
                                            <td>
                                                <?php
                                                $statoBadge = [
                                                    'richiesto' => 'warning',
                                                    'in_lavorazione' => 'info',
                                                    'eseguito' => 'primary',
                                                    'refertato' => 'success',
                                                    'firmato' => 'dark'
                                                ];
                                                ?>
                                                <span class="badge bg-<?php echo $statoBadge[$test['stato']] ?? 'secondary'; ?>">
                                                    <?php echo ucfirst($test['stato']); ?>
                                                </span>
                                            </td>
                                            <td class="text-end">
                                                € <?php echo number_format($test['prezzo_finale'], 2, ',', '.'); ?>
                                            </td>
                                            <td>
                                                <?php if ($test['fatturato']): ?>
                                                    <i class="bi bi-check-circle text-success"></i>
                                                <?php else: ?>
                                                    <i class="bi bi-x-circle text-danger"></i>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                
            <?php elseif ($action === 'view_proforma' && isset($proformaData)): ?>
                <!-- Visualizza Proforma -->
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h2">Proforma <?php echo htmlspecialchars($proformaData['numero']); ?></h1>
                    <div class="btn-toolbar mb-2 mb-md-0">
                        <button type="button" class="btn btn-sm btn-primary" onclick="window.print()">
                            <i class="bi bi-printer"></i> Stampa
                        </button>
                        <a href="orders.php" class="btn btn-sm btn-secondary ms-2">
                            <i class="bi bi-arrow-left"></i> Torna
                        </a>
                    </div>
                </div>
                
                <div class="card">
                    <div class="card-body">
                        <div class="row mb-4">
                            <div class="col-md-6">
                                <h5>Destinatario</h5>
                                <strong>
                                    <?php echo htmlspecialchars($proformaData['professionista']['cognome'] . ' ' . 
                                                              $proformaData['professionista']['nome']); ?>
                                </strong><br>
                                <?php if ($proformaData['professionista']['indirizzo']): ?>
                                    <?php echo nl2br(htmlspecialchars($proformaData['professionista']['indirizzo'])); ?><br>
                                <?php endif; ?>
                                P.IVA: <?php echo htmlspecialchars($proformaData['professionista']['partita_iva']); ?><br>
                                Email: <?php echo htmlspecialchars($proformaData['professionista']['email']); ?>
                            </div>
                            <div class="col-md-6 text-end">
                                <h5>Proforma</h5>
                                <strong>N°: <?php echo htmlspecialchars($proformaData['numero']); ?></strong><br>
                                Data: <?php echo date('d/m/Y', strtotime($proformaData['data'])); ?><br>
                                <br>
                                <span class="badge bg-secondary">DOCUMENTO NON FISCALE</span>
                            </div>
                        </div>
                        
                        <h6>Dettaglio Prestazioni</h6>
                        <div class="table-responsive">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Codice Test</th>
                                        <th>Paziente</th>
                                        <th>Tipo</th>
                                        <th>Data</th>
                                        <th class="text-end">Importo</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($proformaData['tests'] as $test): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($test['codice']); ?></td>
                                            <td>
                                                <?php echo htmlspecialchars($test['paziente_cognome'] . ' ' . 
                                                                          $test['paziente_nome']); ?>
                                            </td>
                                            <td><?php echo htmlspecialchars($test['tipo_test']); ?></td>
                                            <td><?php echo date('d/m/Y', strtotime($test['data_richiesta'])); ?></td>
                                            <td class="text-end">
                                                € <?php echo number_format($test['prezzo_finale'], 2, ',', '.'); ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                                <tfoot>
                                    <tr>
                                        <th colspan="4" class="text-end">Totale Imponibile:</th>
                                        <th class="text-end">
                                            € <?php echo number_format($proformaData['totale'], 2, ',', '.'); ?>
                                        </th>
                                    </tr>
                                    <tr>
                                        <th colspan="4" class="text-end">IVA 22%:</th>
                                        <th class="text-end">
                                            € <?php echo number_format($proformaData['totale'] * 0.22, 2, ',', '.'); ?>
                                        </th>
                                    </tr>
                                    <tr class="table-primary">
                                        <th colspan="4" class="text-end">Totale:</th>
                                        <th class="text-end">
                                            € <?php echo number_format($proformaData['totale'] * 1.22, 2, ',', '.'); ?>
                                        </th>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                        
                        <div class="alert alert-info mt-4">
                            <i class="bi bi-info-circle"></i>
                            Questo è un documento proforma non valido ai fini fiscali. 
                            Per procedere con la fatturazione, utilizzare la funzione "Nuova Fattura" 
                            selezionando i test indicati.
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </main>
    </div>
</div>

<!-- Modal Dettagli Test Professionista -->
<div class="modal fade" id="profTestsModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Test da Fatturare</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="profTestsContent">
                <!-- Contenuto caricato via AJAX -->
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Chiudi</button>
                <button type="button" class="btn btn-primary" onclick="submitProforma()">
                    Genera Proforma Selezionati
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Form nascosto per proforma -->
<form method="post" id="proformaForm" action="orders.php?action=proforma">
    <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
    <input type="hidden" name="professionista_id" id="proforma_prof_id">
    <div id="proforma_tests"></div>
</form>

<script>
// Visualizza test del professionista
function viewProfTests(profId) {
    // Carica test via AJAX (simulato)
    // In produzione, fare chiamata AJAX reale
    
    fetch(`../api/v1/tests.php?professionista_id=${profId}&fatturato=0&stato=refertato,firmato`)
        .then(response => response.json())
        .then(data => {
            if (data.success && data.data) {
                let html = `
                    <form id="selectTestsForm">
                        <div class="table-responsive">
                            <table class="table table-sm">
                                <thead>
                                    <tr>
                                        <th>
                                            <input type="checkbox" id="selectAll" onchange="toggleAll(this)">
                                        </th>
                                        <th>Codice</th>
                                        <th>Paziente</th>
                                        <th>Data</th>
                                        <th>Tipo</th>
                                        <th class="text-end">Importo</th>
                                    </tr>
                                </thead>
                                <tbody>
                `;
                
                let total = 0;
                data.data.forEach(test => {
                    html += `
                        <tr>
                            <td>
                                <input type="checkbox" class="test-check" value="${test.id}" 
                                       data-price="${test.prezzo_finale}" checked>
                            </td>
                            <td>${test.codice}</td>
                            <td>${test.paziente_cognome} ${test.paziente_nome}</td>
                            <td>${new Date(test.data_richiesta).toLocaleDateString('it-IT')}</td>
                            <td>${test.tipo_test}</td>
                            <td class="text-end">€ ${test.prezzo_finale}</td>
                        </tr>
                    `;
                    total += parseFloat(test.prezzo_finale);
                });
                
                html += `
                                </tbody>
                                <tfoot>
                                    <tr class="table-info">
                                        <th colspan="5" class="text-end">Totale Selezionati:</th>
                                        <th class="text-end" id="selectedTotal">€ ${total.toFixed(2).replace('.', ',')}</th>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </form>
                `;
                
                document.getElementById('profTestsContent').innerHTML = html;
                document.getElementById('proforma_prof_id').value = profId;
                
                // Aggiungi event listeners
                document.querySelectorAll('.test-check').forEach(cb => {
                    cb.addEventListener('change', updateTotal);
                });
                
                const modal = new bootstrap.Modal(document.getElementById('profTestsModal'));
                modal.show();
            }
        })
        .catch(error => {
            console.error('Errore:', error);
            alert('Errore nel caricamento dei test');
        });
}

// Toggle tutti i checkbox
function toggleAll(checkbox) {
    document.querySelectorAll('.test-check').forEach(cb => {
        cb.checked = checkbox.checked;
    });
    updateTotal();
}

// Aggiorna totale selezionati
function updateTotal() {
    let total = 0;
    document.querySelectorAll('.test-check:checked').forEach(cb => {
        total += parseFloat(cb.dataset.price);
    });
    document.getElementById('selectedTotal').textContent = '€ ' + total.toFixed(2).replace('.', ',');
}

// Genera proforma diretta
function generateProforma(profId) {
    if (confirm('Generare proforma per tutti i test del professionista?')) {
        const form = document.getElementById('proformaForm');
        document.getElementById('proforma_prof_id').value = profId;
        
        // Aggiungi input hidden per tutti i test
        // In produzione, recuperare via AJAX
        form.submit();
    }
}

// Submit proforma con test selezionati
function submitProforma() {
    const selected = document.querySelectorAll('.test-check:checked');
    if (selected.length === 0) {
        alert('Selezionare almeno un test');
        return;
    }
    
    // Pulisci precedenti
    document.getElementById('proforma_tests').innerHTML = '';
    
    // Aggiungi test selezionati
    selected.forEach(cb => {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'test_ids[]';
        input.value = cb.value;
        document.getElementById('proforma_tests').appendChild(input);
    });
    
    // Chiudi modal e invia form
    bootstrap.Modal.getInstance(document.getElementById('profTestsModal')).hide();
    document.getElementById('proformaForm').submit();
}
</script>

<?php require_once '../templates/footer.php'; ?>
