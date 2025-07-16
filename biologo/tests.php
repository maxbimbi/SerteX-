<?php
/**
 * Gestione Test - Area Biologo
 * SerteX+ Genetic Lab Portal
 */

require_once '../includes/auth.php';
require_once '../includes/database.php';
require_once '../includes/session.php';
require_once '../includes/functions.php';
require_once '../classes/Test.php';
require_once '../classes/Logger.php';

$auth = new Auth();
$session = Session::getInstance();
$db = Database::getInstance();
$logger = new Logger();

// Verifica autenticazione e ruolo
if (!$auth->isAuthenticated() || !$auth->hasRole('biologo')) {
    header('Location: ../index.php');
    exit;
}

$user = $auth->getCurrentUser();
$filter = $_GET['filter'] ?? 'pending';
$search = $_GET['search'] ?? '';
$tipo = $_GET['tipo'] ?? '';

// Gestione cambio stato test
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $csrfToken = $_POST['csrf_token'] ?? '';
    
    if (!$session->validateCsrfToken($csrfToken)) {
        $session->setFlash('error', 'Token di sicurezza non valido');
    } else {
        $testId = intval($_POST['test_id'] ?? 0);
        $action = $_POST['action'];
        
        switch ($action) {
            case 'start_processing':
                $db->update('test', [
                    'stato' => 'in_lavorazione',
                    'data_esecuzione' => date('Y-m-d H:i:s')
                ], ['id' => $testId]);
                
                $logger->log($user->getId(), 'test_in_lavorazione', "Test ID: {$testId}");
                $session->setFlash('success', 'Test preso in carico');
                break;
                
            case 'mark_completed':
                $db->update('test', [
                    'stato' => 'eseguito'
                ], ['id' => $testId]);
                
                $logger->log($user->getId(), 'test_completato', "Test ID: {$testId}");
                $session->setFlash('success', 'Test marcato come completato');
                break;
        }
        
        header('Location: tests.php?filter=' . $filter);
        exit;
    }
}

// Query base per i test
$query = "
    SELECT t.*, 
           p.nome as paziente_nome, 
           p.cognome as paziente_cognome,
           p.codice_fiscale,
           p.data_nascita,
           pr.nome as professionista_nome,
           pr.cognome as professionista_cognome,
           u_prof.email as professionista_email
    FROM test t
    INNER JOIN pazienti p ON t.paziente_id = p.id
    INNER JOIN professionisti prof ON t.professionista_id = prof.id
    INNER JOIN utenti u_prof ON prof.utente_id = u_prof.id
    INNER JOIN utenti pr ON prof.utente_id = pr.id
    WHERE 1=1
";

$params = [];

// Applica filtri
switch ($filter) {
    case 'pending':
        $query .= " AND t.stato IN ('richiesto', 'in_lavorazione')";
        break;
    case 'completed':
        $query .= " AND t.stato = 'eseguito'";
        break;
    case 'reported':
        $query .= " AND t.stato IN ('refertato', 'firmato')";
        break;
    case 'all':
        // Nessun filtro stato
        break;
}

// Filtro tipo test
if ($tipo) {
    $query .= " AND t.tipo_test = :tipo";
    $params['tipo'] = $tipo;
}

// Ricerca
if ($search) {
    $query .= " AND (t.codice LIKE :search 
                     OR CONCAT(p.nome, ' ', p.cognome) LIKE :search
                     OR p.codice_fiscale LIKE :search)";
    $params['search'] = "%{$search}%";
}

$query .= " ORDER BY 
    CASE t.stato 
        WHEN 'richiesto' THEN 1
        WHEN 'in_lavorazione' THEN 2
        WHEN 'eseguito' THEN 3
        WHEN 'refertato' THEN 4
        WHEN 'firmato' THEN 5
    END,
    t.data_richiesta DESC";

$tests = $db->select($query, $params);

// Pseudonimizza i dati dei pazienti
foreach ($tests as &$test) {
    $test['paziente_display'] = substr($test['paziente_nome'], 0, 1) . '*** ' . 
                                substr($test['paziente_cognome'], 0, 1) . '***';
    $test['cf_display'] = substr($test['codice_fiscale'], 0, 3) . '***' . 
                          substr($test['codice_fiscale'], -3);
}

// Conta test per stato
$stats = [
    'richiesti' => $db->count('test', ['stato' => 'richiesto']),
    'in_lavorazione' => $db->count('test', ['stato' => 'in_lavorazione']),
    'eseguiti' => $db->count('test', ['stato' => 'eseguito']),
    'refertati' => $db->count('test', ['stato' => 'refertato']) + 
                   $db->count('test', ['stato' => 'firmato'])
];

// Genera token CSRF
$csrfToken = $session->generateCsrfToken();

// Includi header
require_once '../templates/header.php';
?>

<div class="container-fluid">
    <div class="row">
        <?php require_once '../templates/sidebar.php'; ?>
        
        <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
            <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                <h1 class="h2">Gestione Test</h1>
            </div>
            
            <?php foreach ($session->getFlashMessages() as $flash): ?>
                <div class="alert alert-<?php echo $flash['type'] === 'error' ? 'danger' : $flash['type']; ?> alert-dismissible fade show" role="alert">
                    <?php echo htmlspecialchars($flash['message']); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endforeach; ?>
            
            <!-- Statistiche rapide -->
            <div class="row mb-4">
                <div class="col-md-3">
                    <div class="card text-white bg-warning">
                        <div class="card-body">
                            <h5 class="card-title"><?php echo $stats['richiesti']; ?></h5>
                            <p class="card-text">Test Richiesti</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card text-white bg-info">
                        <div class="card-body">
                            <h5 class="card-title"><?php echo $stats['in_lavorazione']; ?></h5>
                            <p class="card-text">In Lavorazione</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card text-white bg-success">
                        <div class="card-body">
                            <h5 class="card-title"><?php echo $stats['eseguiti']; ?></h5>
                            <p class="card-text">Completati</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card text-white bg-primary">
                        <div class="card-body">
                            <h5 class="card-title"><?php echo $stats['refertati']; ?></h5>
                            <p class="card-text">Refertati</p>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Filtri -->
            <div class="card mb-4">
                <div class="card-body">
                    <form method="get" class="row g-3">
                        <div class="col-md-3">
                            <label class="form-label">Stato</label>
                            <select class="form-select" name="filter" onchange="this.form.submit()">
                                <option value="pending" <?php echo $filter === 'pending' ? 'selected' : ''; ?>>
                                    Da processare
                                </option>
                                <option value="completed" <?php echo $filter === 'completed' ? 'selected' : ''; ?>>
                                    Completati
                                </option>
                                <option value="reported" <?php echo $filter === 'reported' ? 'selected' : ''; ?>>
                                    Refertati
                                </option>
                                <option value="all" <?php echo $filter === 'all' ? 'selected' : ''; ?>>
                                    Tutti
                                </option>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Tipo Test</label>
                            <select class="form-select" name="tipo" onchange="this.form.submit()">
                                <option value="">Tutti i tipi</option>
                                <option value="genetico" <?php echo $tipo === 'genetico' ? 'selected' : ''; ?>>
                                    Genetico
                                </option>
                                <option value="microbiota" <?php echo $tipo === 'microbiota' ? 'selected' : ''; ?>>
                                    Microbiota
                                </option>
                                <option value="intolleranze_cito" <?php echo $tipo === 'intolleranze_cito' ? 'selected' : ''; ?>>
                                    Intolleranze Citotossico
                                </option>
                                <option value="intolleranze_elisa" <?php echo $tipo === 'intolleranze_elisa' ? 'selected' : ''; ?>>
                                    Intolleranze ELISA
                                </option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Ricerca</label>
                            <input type="text" class="form-control" name="search" 
                                   placeholder="Codice test, paziente o CF..." 
                                   value="<?php echo htmlspecialchars($search); ?>">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">&nbsp;</label>
                            <button type="submit" class="btn btn-primary w-100">
                                <i class="bi bi-search"></i> Cerca
                            </button>
                        </div>
                    </form>
                </div>
            </div>
            
            <!-- Tabella test -->
            <div class="table-responsive">
                <table class="table table-striped table-hover">
                    <thead>
                        <tr>
                            <th>Codice</th>
                            <th>Data Richiesta</th>
                            <th>Tipo</th>
                            <th>Paziente</th>
                            <th>Età</th>
                            <th>Professionista</th>
                            <th>Stato</th>
                            <th>Azioni</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($tests as $test): ?>
                        <tr>
                            <td>
                                <strong><?php echo htmlspecialchars($test['codice']); ?></strong>
                                <?php if ($test['barcode']): ?>
                                    <br>
                                    <img src="<?php echo htmlspecialchars($test['barcode']); ?>" 
                                         alt="Barcode" style="height: 30px;">
                                <?php endif; ?>
                            </td>
                            <td><?php echo date('d/m/Y H:i', strtotime($test['data_richiesta'])); ?></td>
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
                                <span title="<?php echo htmlspecialchars($test['cf_display']); ?>">
                                    <?php echo htmlspecialchars($test['paziente_display']); ?>
                                </span>
                            </td>
                            <td>
                                <?php 
                                if ($test['data_nascita']) {
                                    $eta = date_diff(date_create($test['data_nascita']), date_create())->y;
                                    echo $eta . ' anni';
                                } else {
                                    echo '-';
                                }
                                ?>
                            </td>
                            <td>
                                <small>
                                    <?php echo htmlspecialchars($test['professionista_nome'] . ' ' . $test['professionista_cognome']); ?>
                                </small>
                            </td>
                            <td>
                                <?php
                                $statoBadge = [
                                    'richiesto' => 'warning',
                                    'in_lavorazione' => 'info',
                                    'eseguito' => 'success',
                                    'refertato' => 'primary',
                                    'firmato' => 'dark'
                                ];
                                $statoLabel = [
                                    'richiesto' => 'Richiesto',
                                    'in_lavorazione' => 'In Lavorazione',
                                    'eseguito' => 'Eseguito',
                                    'refertato' => 'Refertato',
                                    'firmato' => 'Firmato'
                                ];
                                ?>
                                <span class="badge bg-<?php echo $statoBadge[$test['stato']] ?? 'secondary'; ?>">
                                    <?php echo $statoLabel[$test['stato']] ?? $test['stato']; ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($test['stato'] === 'richiesto'): ?>
                                    <form method="post" class="d-inline">
                                        <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                                        <input type="hidden" name="action" value="start_processing">
                                        <input type="hidden" name="test_id" value="<?php echo $test['id']; ?>">
                                        <button type="submit" class="btn btn-sm btn-primary" 
                                                title="Prendi in carico">
                                            <i class="bi bi-play-circle"></i>
                                        </button>
                                    </form>
                                <?php endif; ?>
                                
                                <?php if (in_array($test['stato'], ['richiesto', 'in_lavorazione'])): ?>
                                    <a href="results.php?test_id=<?php echo $test['id']; ?>" 
                                       class="btn btn-sm btn-success" title="Inserisci risultati">
                                        <i class="bi bi-pencil-square"></i>
                                    </a>
                                <?php endif; ?>
                                
                                <?php if ($test['stato'] === 'eseguito'): ?>
                                    <a href="reports.php?action=create&test_id=<?php echo $test['id']; ?>" 
                                       class="btn btn-sm btn-warning" title="Genera referto">
                                        <i class="bi bi-file-earmark-medical"></i>
                                    </a>
                                <?php endif; ?>
                                
                                <?php if (in_array($test['stato'], ['refertato', 'firmato'])): ?>
                                    <a href="reports.php?action=view&test_id=<?php echo $test['id']; ?>" 
                                       class="btn btn-sm btn-info" title="Visualizza referto">
                                        <i class="bi bi-eye"></i>
                                    </a>
                                <?php endif; ?>
                                
                                <button type="button" class="btn btn-sm btn-secondary" 
                                        onclick="viewTestDetails(<?php echo $test['id']; ?>)"
                                        title="Dettagli test">
                                    <i class="bi bi-info-circle"></i>
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        
                        <?php if (empty($tests)): ?>
                        <tr>
                            <td colspan="8" class="text-center text-muted py-4">
                                Nessun test trovato con i criteri selezionati
                            </td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </main>
    </div>
</div>

<!-- Modal Dettagli Test -->
<div class="modal fade" id="testDetailsModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Dettagli Test</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="testDetailsContent">
                <!-- Contenuto caricato via AJAX -->
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Chiudi</button>
            </div>
        </div>
    </div>
</div>

<script>
function viewTestDetails(testId) {
    // Carica dettagli test via AJAX
    fetch(`../api/v1/tests.php?id=${testId}&details=true`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const test = data.data;
                let html = `
                    <div class="row">
                        <div class="col-md-6">
                            <h6>Informazioni Test</h6>
                            <table class="table table-sm">
                                <tr>
                                    <th>Codice:</th>
                                    <td>${test.codice}</td>
                                </tr>
                                <tr>
                                    <th>Tipo:</th>
                                    <td>${test.tipo_test}</td>
                                </tr>
                                <tr>
                                    <th>Data Richiesta:</th>
                                    <td>${new Date(test.data_richiesta).toLocaleString('it-IT')}</td>
                                </tr>
                                <tr>
                                    <th>Stato:</th>
                                    <td><span class="badge bg-${getStatoBadgeClass(test.stato)}">${test.stato}</span></td>
                                </tr>
                                <tr>
                                    <th>Prezzo:</th>
                                    <td>€ ${test.prezzo_finale}</td>
                                </tr>
                            </table>
                        </div>
                        <div class="col-md-6">
                            <h6>Note</h6>
                            <p>${test.note || '<em>Nessuna nota</em>'}</p>
                        </div>
                    </div>
                `;
                
                // Aggiungi dettagli specifici per tipo test
                if (test.tipo_test === 'genetico' && test.dettagli) {
                    html += '<hr><h6>Analisi Richieste</h6><ul>';
                    test.dettagli.forEach(item => {
                        html += `<li>${item.nome} (${item.tipo})</li>`;
                    });
                    html += '</ul>';
                }
                
                document.getElementById('testDetailsContent').innerHTML = html;
                const modal = new bootstrap.Modal(document.getElementById('testDetailsModal'));
                modal.show();
            }
        })
        .catch(error => {
            console.error('Errore:', error);
            alert('Errore nel caricamento dei dettagli');
        });
}

function getStatoBadgeClass(stato) {
    const classes = {
        'richiesto': 'warning',
        'in_lavorazione': 'info',
        'eseguito': 'success',
        'refertato': 'primary',
        'firmato': 'dark'
    };
    return classes[stato] || 'secondary';
}
</script>

<?php require_once '../templates/footer.php'; ?>
