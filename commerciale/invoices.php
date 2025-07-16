<?php
/**
 * Gestione Fatturazione - Area Commerciale
 * SerteX+ Genetic Lab Portal
 */

require_once '../includes/auth.php';
require_once '../includes/database.php';
require_once '../includes/session.php';
require_once '../includes/functions.php';
require_once '../classes/Invoice.php';
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
$action = $_GET['action'] ?? 'list';
$invoiceId = intval($_GET['id'] ?? 0);

// Gestione azioni POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? '';
    
    if (!$session->validateCsrfToken($csrfToken)) {
        $session->setFlash('error', 'Token di sicurezza non valido');
    } else {
        switch ($_POST['action'] ?? '') {
            case 'create_single':
                // Fattura singolo test
                $testId = intval($_POST['test_id']);
                try {
                    $invoice = new Invoice();
                    $result = $invoice->createFromTest($testId);
                    
                    if ($result['success']) {
                        $logger->log($user->getId(), 'fattura_creata', 
                                   "Fattura {$result['numero']} per test ID: {$testId}");
                        $session->setFlash('success', "Fattura {$result['numero']} creata con successo");
                        header('Location: invoices.php?action=view&id=' . $result['id']);
                        exit;
                    } else {
                        $session->setFlash('error', $result['error']);
                    }
                } catch (Exception $e) {
                    $session->setFlash('error', 'Errore nella creazione della fattura');
                }
                break;
                
            case 'create_monthly':
                // Fattura mensile per professionista
                $professionistaId = intval($_POST['professionista_id']);
                $mese = intval($_POST['mese']);
                $anno = intval($_POST['anno']);
                
                try {
                    $invoice = new Invoice();
                    $result = $invoice->createMonthlyInvoice($professionistaId, $mese, $anno);
                    
                    if ($result['success']) {
                        $logger->log($user->getId(), 'fattura_mensile_creata', 
                                   "Fattura mensile {$result['numero']} per professionista ID: {$professionistaId}");
                        $session->setFlash('success', 
                                         "Fattura {$result['numero']} creata con {$result['num_test']} test");
                        header('Location: invoices.php?action=view&id=' . $result['id']);
                        exit;
                    } else {
                        $session->setFlash('error', $result['error']);
                    }
                } catch (Exception $e) {
                    $session->setFlash('error', 'Errore nella creazione della fattura mensile');
                }
                break;
                
            case 'update_status':
                // Aggiorna stato fattura
                $invoiceId = intval($_POST['invoice_id']);
                $newStatus = $_POST['new_status'];
                
                $validStatuses = ['bozza', 'emessa', 'inviata', 'pagata', 'annullata'];
                if (in_array($newStatus, $validStatuses)) {
                    try {
                        $db->update('fatture', ['stato' => $newStatus], ['id' => $invoiceId]);
                        $logger->log($user->getId(), 'fattura_stato_modificato', 
                                   "Fattura ID {$invoiceId} - nuovo stato: {$newStatus}");
                        $session->setFlash('success', 'Stato fattura aggiornato');
                    } catch (Exception $e) {
                        $session->setFlash('error', 'Errore nell\'aggiornamento dello stato');
                    }
                }
                break;
                
            case 'generate_xml':
                // Genera XML fattura elettronica
                $invoiceId = intval($_POST['invoice_id']);
                $invoice = new Invoice($invoiceId);
                
                if ($invoice->exists()) {
                    $result = $invoice->generateXML();
                    if ($result['success']) {
                        $session->setFlash('success', 'XML fattura elettronica generato');
                        // Download diretto
                        header('Content-Type: application/xml');
                        header('Content-Disposition: attachment; filename="' . $result['filename'] . '"');
                        readfile($result['path']);
                        exit;
                    } else {
                        $session->setFlash('error', 'Errore nella generazione XML: ' . $result['error']);
                    }
                }
                break;
        }
    }
}

// Carica dati per la vista
$filter = $_GET['filter'] ?? 'all';
$search = $_GET['search'] ?? '';
$professionistaId = intval($_GET['professionista_id'] ?? 0);
$dateFrom = $_GET['date_from'] ?? date('Y-m-01');
$dateTo = $_GET['date_to'] ?? date('Y-m-t');
$page = intval($_GET['page'] ?? 1);
$limit = 20;
$offset = ($page - 1) * $limit;

// Query fatture
$query = "
    SELECT f.*, 
           p.nome as professionista_nome,
           p.cognome as professionista_cognome,
           pr.partita_iva,
           pr.codice_sdi,
           pr.pec,
           COUNT(t.id) as num_test
    FROM fatture f
    INNER JOIN professionisti pr ON f.professionista_id = pr.id
    INNER JOIN utenti p ON pr.utente_id = p.id
    LEFT JOIN test t ON t.fattura_id = f.id
    WHERE 1=1
";

$params = [];

// Filtri
if ($professionistaId) {
    $query .= " AND f.professionista_id = :prof_id";
    $params['prof_id'] = $professionistaId;
}

if ($filter !== 'all') {
    $query .= " AND f.stato = :stato";
    $params['stato'] = $filter;
}

if ($search) {
    $query .= " AND (f.numero LIKE :search OR p.nome LIKE :search OR p.cognome LIKE :search)";
    $params['search'] = "%{$search}%";
}

$query .= " AND f.data_emissione BETWEEN :date_from AND :date_to";
$params['date_from'] = $dateFrom;
$params['date_to'] = $dateTo;

$query .= " GROUP BY f.id ORDER BY f.data_emissione DESC, f.numero DESC LIMIT :limit OFFSET :offset";
$params['limit'] = $limit;
$params['offset'] = $offset;

$invoices = $db->select($query, $params);

// Conta totale per paginazione
$countQuery = "SELECT COUNT(DISTINCT f.id) as total FROM fatture f WHERE 1=1";
if ($professionistaId) $countQuery .= " AND f.professionista_id = :prof_id";
if ($filter !== 'all') $countQuery .= " AND f.stato = :stato";
if ($search) $countQuery .= " AND f.numero LIKE :search";
$countQuery .= " AND f.data_emissione BETWEEN :date_from AND :date_to";

unset($params['limit']);
unset($params['offset']);
$totalInvoices = $db->selectOne($countQuery, $params)['total'];
$totalPages = ceil($totalInvoices / $limit);

// Carica professionisti per filtro
$professionisti = $db->select("
    SELECT p.id, u.nome, u.cognome, COUNT(DISTINCT f.id) as num_fatture
    FROM professionisti p
    INNER JOIN utenti u ON p.utente_id = u.id
    LEFT JOIN fatture f ON p.id = f.professionista_id
    GROUP BY p.id
    ORDER BY u.cognome, u.nome
");

// Se action = new, carica dati per nuova fattura
if ($action === 'new') {
    // Test non fatturati
    $testNonFatturati = $db->select("
        SELECT t.*, 
               p.nome as paziente_nome,
               p.cognome as paziente_cognome,
               pr.nome as professionista_nome,
               pr.cognome as professionista_cognome,
               prof.id as professionista_id
        FROM test t
        INNER JOIN pazienti p ON t.paziente_id = p.id
        INNER JOIN professionisti prof ON t.professionista_id = prof.id
        INNER JOIN utenti pr ON prof.utente_id = pr.id
        WHERE t.stato IN ('refertato', 'firmato')
        AND t.fatturato = 0
        ORDER BY t.professionista_id, t.data_richiesta
    ");
    
    // Raggruppa per professionista
    $testPerProfessionista = [];
    foreach ($testNonFatturati as $test) {
        $profId = $test['professionista_id'];
        if (!isset($testPerProfessionista[$profId])) {
            $testPerProfessionista[$profId] = [
                'professionista' => $test['professionista_nome'] . ' ' . $test['professionista_cognome'],
                'tests' => []
            ];
        }
        $testPerProfessionista[$profId]['tests'][] = $test;
    }
}

// Se action = view, carica dettagli fattura
if ($action === 'view' && $invoiceId) {
    $invoice = new Invoice($invoiceId);
    if (!$invoice->exists()) {
        $session->setFlash('error', 'Fattura non trovata');
        header('Location: invoices.php');
        exit;
    }
    $invoiceData = $invoice->getDetails();
}

// Statistiche
$stats = [
    'totale_mese' => $db->selectOne("
        SELECT SUM(importo_totale_ivato) as total
        FROM fatture
        WHERE MONTH(data_emissione) = MONTH(CURRENT_DATE())
        AND YEAR(data_emissione) = YEAR(CURRENT_DATE())
        AND stato != 'annullata'
    ")['total'] ?? 0,
    
    'da_pagare' => $db->selectOne("
        SELECT SUM(importo_totale_ivato) as total
        FROM fatture
        WHERE stato IN ('emessa', 'inviata')
    ")['total'] ?? 0,
    
    'fatture_mese' => $db->count('fatture', [
        'MONTH(data_emissione)' => date('n'),
        'YEAR(data_emissione)' => date('Y')
    ]),
    
    'test_da_fatturare' => $db->count('test', [
        'fatturato' => 0,
        'stato' => ['refertato', 'firmato']
    ])
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
            <?php if ($action === 'list'): ?>
                <!-- Lista Fatture -->
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h2">Gestione Fatturazione</h1>
                    <div class="btn-toolbar mb-2 mb-md-0">
                        <a href="invoices.php?action=new" class="btn btn-sm btn-primary">
                            <i class="bi bi-plus-circle"></i> Nuova Fattura
                        </a>
                    </div>
                </div>
                
                <?php foreach ($session->getFlashMessages() as $flash): ?>
                    <div class="alert alert-<?php echo $flash['type'] === 'error' ? 'danger' : $flash['type']; ?> alert-dismissible fade show" role="alert">
                        <?php echo htmlspecialchars($flash['message']); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endforeach; ?>
                
                <!-- Statistiche -->
                <div class="row mb-4">
                    <div class="col-md-3">
                        <div class="card">
                            <div class="card-body">
                                <h5 class="card-title">€ <?php echo number_format($stats['totale_mese'], 2, ',', '.'); ?></h5>
                                <p class="card-text text-muted">Fatturato Mese</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card">
                            <div class="card-body">
                                <h5 class="card-title">€ <?php echo number_format($stats['da_pagare'], 2, ',', '.'); ?></h5>
                                <p class="card-text text-muted">Da Incassare</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card">
                            <div class="card-body">
                                <h5 class="card-title"><?php echo $stats['fatture_mese']; ?></h5>
                                <p class="card-text text-muted">Fatture Mese</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-3">
                        <div class="card">
                            <div class="card-body">
                                <h5 class="card-title"><?php echo $stats['test_da_fatturare']; ?></h5>
                                <p class="card-text text-muted">Test da Fatturare</p>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Filtri -->
                <div class="card mb-3">
                    <div class="card-body">
                        <form method="get" class="row g-3">
                            <div class="col-md-2">
                                <label class="form-label">Periodo</label>
                                <input type="date" class="form-control" name="date_from" 
                                       value="<?php echo $dateFrom; ?>">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">&nbsp;</label>
                                <input type="date" class="form-control" name="date_to" 
                                       value="<?php echo $dateTo; ?>">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Stato</label>
                                <select class="form-select" name="filter">
                                    <option value="all">Tutti</option>
                                    <option value="bozza" <?php echo $filter === 'bozza' ? 'selected' : ''; ?>>Bozza</option>
                                    <option value="emessa" <?php echo $filter === 'emessa' ? 'selected' : ''; ?>>Emessa</option>
                                    <option value="inviata" <?php echo $filter === 'inviata' ? 'selected' : ''; ?>>Inviata</option>
                                    <option value="pagata" <?php echo $filter === 'pagata' ? 'selected' : ''; ?>>Pagata</option>
                                    <option value="annullata" <?php echo $filter === 'annullata' ? 'selected' : ''; ?>>Annullata</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Professionista</label>
                                <select class="form-select" name="professionista_id">
                                    <option value="">Tutti</option>
                                    <?php foreach ($professionisti as $prof): ?>
                                        <option value="<?php echo $prof['id']; ?>" 
                                                <?php echo $professionistaId == $prof['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($prof['cognome'] . ' ' . $prof['nome']); ?>
                                            (<?php echo $prof['num_fatture']; ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Ricerca</label>
                                <input type="text" class="form-control" name="search" 
                                       placeholder="Numero..." value="<?php echo htmlspecialchars($search); ?>">
                            </div>
                            <div class="col-md-1">
                                <label class="form-label">&nbsp;</label>
                                <button type="submit" class="btn btn-primary w-100">
                                    <i class="bi bi-search"></i>
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
                
                <!-- Tabella fatture -->
                <div class="card">
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Numero</th>
                                        <th>Data</th>
                                        <th>Professionista</th>
                                        <th>Test</th>
                                        <th>Imponibile</th>
                                        <th>IVA</th>
                                        <th>Totale</th>
                                        <th>Stato</th>
                                        <th>Azioni</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($invoices as $invoice): ?>
                                        <tr>
                                            <td>
                                                <strong><?php echo htmlspecialchars($invoice['numero']); ?></strong>
                                            </td>
                                            <td><?php echo date('d/m/Y', strtotime($invoice['data_emissione'])); ?></td>
                                            <td>
                                                <?php echo htmlspecialchars($invoice['professionista_cognome'] . ' ' . $invoice['professionista_nome']); ?>
                                                <br>
                                                <small class="text-muted">
                                                    P.IVA: <?php echo htmlspecialchars($invoice['partita_iva']); ?>
                                                </small>
                                            </td>
                                            <td>
                                                <span class="badge bg-info"><?php echo $invoice['num_test']; ?></span>
                                            </td>
                                            <td>€ <?php echo number_format($invoice['importo_totale'], 2, ',', '.'); ?></td>
                                            <td>€ <?php echo number_format($invoice['iva_totale'], 2, ',', '.'); ?></td>
                                            <td>
                                                <strong>€ <?php echo number_format($invoice['importo_totale_ivato'], 2, ',', '.'); ?></strong>
                                            </td>
                                            <td>
                                                <?php
                                                $statoBadge = [
                                                    'bozza' => 'secondary',
                                                    'emessa' => 'primary',
                                                    'inviata' => 'info',
                                                    'pagata' => 'success',
                                                    'annullata' => 'danger'
                                                ];
                                                ?>
                                                <span class="badge bg-<?php echo $statoBadge[$invoice['stato']] ?? 'secondary'; ?>">
                                                    <?php echo ucfirst($invoice['stato']); ?>
                                                </span>
                                            </td>
                                            <td>
                                                <div class="btn-group btn-group-sm" role="group">
                                                    <a href="invoices.php?action=view&id=<?php echo $invoice['id']; ?>" 
                                                       class="btn btn-info" title="Visualizza">
                                                        <i class="bi bi-eye"></i>
                                                    </a>
                                                    
                                                    <?php if ($invoice['pdf_path']): ?>
                                                        <a href="../<?php echo $invoice['pdf_path']; ?>" 
                                                           class="btn btn-primary" target="_blank" title="Download PDF">
                                                            <i class="bi bi-file-pdf"></i>
                                                        </a>
                                                    <?php endif; ?>
                                                    
                                                    <?php if ($invoice['stato'] === 'emessa'): ?>
                                                        <button type="button" class="btn btn-success" 
                                                                onclick="generateXML(<?php echo $invoice['id']; ?>)"
                                                                title="Genera XML">
                                                            <i class="bi bi-file-code"></i>
                                                        </button>
                                                    <?php endif; ?>
                                                    
                                                    <button type="button" class="btn btn-secondary dropdown-toggle" 
                                                            data-bs-toggle="dropdown">
                                                        <i class="bi bi-three-dots"></i>
                                                    </button>
                                                    <ul class="dropdown-menu">
                                                        <?php if ($invoice['stato'] === 'bozza'): ?>
                                                            <li>
                                                                <a class="dropdown-item" href="#" 
                                                                   onclick="updateStatus(<?php echo $invoice['id']; ?>, 'emessa')">
                                                                    <i class="bi bi-check"></i> Emetti
                                                                </a>
                                                            </li>
                                                        <?php endif; ?>
                                                        
                                                        <?php if ($invoice['stato'] === 'emessa'): ?>
                                                            <li>
                                                                <a class="dropdown-item" href="#" 
                                                                   onclick="updateStatus(<?php echo $invoice['id']; ?>, 'inviata')">
                                                                    <i class="bi bi-send"></i> Segna come inviata
                                                                </a>
                                                            </li>
                                                        <?php endif; ?>
                                                        
                                                        <?php if (in_array($invoice['stato'], ['emessa', 'inviata'])): ?>
                                                            <li>
                                                                <a class="dropdown-item" href="#" 
                                                                   onclick="updateStatus(<?php echo $invoice['id']; ?>, 'pagata')">
                                                                    <i class="bi bi-cash"></i> Segna come pagata
                                                                </a>
                                                            </li>
                                                        <?php endif; ?>
                                                        
                                                        <?php if ($invoice['stato'] !== 'annullata'): ?>
                                                            <li><hr class="dropdown-divider"></li>
                                                            <li>
                                                                <a class="dropdown-item text-danger" href="#" 
                                                                   onclick="updateStatus(<?php echo $invoice['id']; ?>, 'annullata')">
                                                                    <i class="bi bi-x-circle"></i> Annulla
                                                                </a>
                                                            </li>
                                                        <?php endif; ?>
                                                    </ul>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                    
                                    <?php if (empty($invoices)): ?>
                                        <tr>
                                            <td colspan="9" class="text-center text-muted py-4">
                                                Nessuna fattura trovata
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                
            <?php elseif ($action === 'new'): ?>
                <!-- Nuova Fattura -->
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h2">Nuova Fattura</h1>
                    <div class="btn-toolbar mb-2 mb-md-0">
                        <a href="invoices.php" class="btn btn-sm btn-secondary">
                            <i class="bi bi-arrow-left"></i> Torna alla lista
                        </a>
                    </div>
                </div>
                
                <div class="row">
                    <!-- Fatturazione singola -->
                    <div class="col-md-6">
                        <div class="card mb-4">
                            <div class="card-header">
                                <h5 class="mb-0">Fatturazione Singolo Test</h5>
                            </div>
                            <div class="card-body">
                                <p>Seleziona un test specifico da fatturare:</p>
                                
                                <div style="max-height: 400px; overflow-y: auto;">
                                    <?php foreach ($testNonFatturati as $test): ?>
                                        <div class="border rounded p-2 mb-2">
                                            <div class="form-check">
                                                <input class="form-check-input test-single" type="radio" 
                                                       name="test_singolo" value="<?php echo $test['id']; ?>"
                                                       data-prezzo="<?php echo $test['prezzo_finale']; ?>">
                                                <label class="form-check-label">
                                                    <strong><?php echo htmlspecialchars($test['codice']); ?></strong> - 
                                                    <?php echo htmlspecialchars($test['paziente_cognome'] . ' ' . $test['paziente_nome']); ?>
                                                    <br>
                                                    <small class="text-muted">
                                                        <?php echo htmlspecialchars($test['professionista_cognome'] . ' ' . $test['professionista_nome']); ?> |
                                                        <?php echo date('d/m/Y', strtotime($test['data_richiesta'])); ?> |
                                                        € <?php echo number_format($test['prezzo_finale'], 2, ',', '.'); ?>
                                                    </small>
                                                </label>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                    
                                    <?php if (empty($testNonFatturati)): ?>
                                        <p class="text-muted">Nessun test da fatturare</p>
                                    <?php endif; ?>
                                </div>
                                
                                <form method="post" class="mt-3">
                                    <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                                    <input type="hidden" name="action" value="create_single">
                                    <input type="hidden" name="test_id" id="single_test_id">
                                    
                                    <button type="submit" class="btn btn-primary" id="btnSingleInvoice" disabled>
                                        <i class="bi bi-file-text"></i> Crea Fattura Singola
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Fatturazione mensile -->
                    <div class="col-md-6">
                        <div class="card mb-4">
                            <div class="card-header">
                                <h5 class="mb-0">Fatturazione Mensile</h5>
                            </div>
                            <div class="card-body">
                                <p>Fattura tutti i test di un professionista per il mese selezionato:</p>
                                
                                <form method="post">
                                    <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                                    <input type="hidden" name="action" value="create_monthly">
                                    
                                    <div class="mb-3">
                                        <label class="form-label">Professionista</label>
                                        <select class="form-select" name="professionista_id" id="monthly_prof" required>
                                            <option value="">Seleziona...</option>
                                            <?php foreach ($testPerProfessionista as $profId => $data): ?>
                                                <option value="<?php echo $profId; ?>">
                                                    <?php echo htmlspecialchars($data['professionista']); ?>
                                                    (<?php echo count($data['tests']); ?> test)
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    
                                    <div class="row mb-3">
                                        <div class="col">
                                            <label class="form-label">Mese</label>
                                            <select class="form-select" name="mese" required>
                                                <?php for ($m = 1; $m <= 12; $m++): ?>
                                                    <option value="<?php echo $m; ?>" 
                                                            <?php echo $m == date('n') ? 'selected' : ''; ?>>
                                                        <?php echo date('F', mktime(0, 0, 0, $m, 1)); ?>
                                                    </option>
                                                <?php endfor; ?>
                                            </select>
                                        </div>
                                        <div class="col">
                                            <label class="form-label">Anno</label>
                                            <select class="form-select" name="anno" required>
                                                <?php for ($y = date('Y'); $y >= date('Y') - 2; $y--): ?>
                                                    <option value="<?php echo $y; ?>"><?php echo $y; ?></option>
                                                <?php endfor; ?>
                                            </select>
                                        </div>
                                    </div>
                                    
                                    <div id="monthlyPreview" class="alert alert-info" style="display: none;">
                                        <!-- Preview dinamico -->
                                    </div>
                                    
                                    <button type="submit" class="btn btn-primary" id="btnMonthlyInvoice" disabled>
                                        <i class="bi bi-calendar-month"></i> Crea Fattura Mensile
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
                
            <?php elseif ($action === 'view' && isset($invoiceData)): ?>
                <!-- Visualizza Fattura -->
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h2">Fattura <?php echo htmlspecialchars($invoiceData['numero']); ?></h1>
                    <div class="btn-toolbar mb-2 mb-md-0">
                        <div class="btn-group me-2">
                            <?php if ($invoiceData['pdf_path']): ?>
                                <a href="../<?php echo $invoiceData['pdf_path']; ?>" 
                                   class="btn btn-sm btn-primary" target="_blank">
                                    <i class="bi bi-file-pdf"></i> PDF
                                </a>
                            <?php else: ?>
                                <button type="button" class="btn btn-sm btn-primary" onclick="generatePDF(<?php echo $invoiceData['id']; ?>)">
                                    <i class="bi bi-file-pdf"></i> Genera PDF
                                </button>
                            <?php endif; ?>
                            
                            <?php if ($invoiceData['stato'] === 'emessa'): ?>
                                <button type="button" class="btn btn-sm btn-success" onclick="generateXML(<?php echo $invoiceData['id']; ?>)">
                                    <i class="bi bi-file-code"></i> XML
                                </button>
                            <?php endif; ?>
                        </div>
                        <a href="invoices.php" class="btn btn-sm btn-secondary">
                            <i class="bi bi-arrow-left"></i> Torna alla lista
                        </a>
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-md-8">
                        <div class="card">
                            <div class="card-body">
                                <!-- Intestazione -->
                                <div class="row mb-4">
                                    <div class="col-md-6">
                                        <h5>Emittente</h5>
                                        <strong><?php echo htmlspecialchars($invoiceData['laboratorio_nome']); ?></strong><br>
                                        <?php echo nl2br(htmlspecialchars($invoiceData['laboratorio_indirizzo'])); ?><br>
                                        P.IVA: <?php echo htmlspecialchars($invoiceData['laboratorio_piva']); ?><br>
                                        C.F.: <?php echo htmlspecialchars($invoiceData['laboratorio_cf']); ?>
                                    </div>
                                    <div class="col-md-6 text-end">
                                        <h5>Destinatario</h5>
                                        <strong><?php echo htmlspecialchars($invoiceData['professionista_nome']); ?></strong><br>
                                        <?php echo nl2br(htmlspecialchars($invoiceData['professionista_indirizzo'])); ?><br>
                                        P.IVA: <?php echo htmlspecialchars($invoiceData['professionista_piva']); ?><br>
                                        C.F.: <?php echo htmlspecialchars($invoiceData['professionista_cf']); ?><br>
                                        <?php if ($invoiceData['professionista_sdi']): ?>
                                            SDI: <?php echo htmlspecialchars($invoiceData['professionista_sdi']); ?><br>
                                        <?php endif; ?>
                                        <?php if ($invoiceData['professionista_pec']): ?>
                                            PEC: <?php echo htmlspecialchars($invoiceData['professionista_pec']); ?>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                
                                <hr>
                                
                                <!-- Dettagli fattura -->
                                <div class="row mb-4">
                                    <div class="col-md-3">
                                        <strong>Numero:</strong><br>
                                        <?php echo htmlspecialchars($invoiceData['numero']); ?>
                                    </div>
                                    <div class="col-md-3">
                                        <strong>Data:</strong><br>
                                        <?php echo date('d/m/Y', strtotime($invoiceData['data_emissione'])); ?>
                                    </div>
                                    <div class="col-md-3">
                                        <strong>Stato:</strong><br>
                                        <span class="badge bg-<?php echo $statoBadge[$invoiceData['stato']] ?? 'secondary'; ?>">
                                            <?php echo ucfirst($invoiceData['stato']); ?>
                                        </span>
                                    </div>
                                    <div class="col-md-3">
                                        <strong>Modalità pagamento:</strong><br>
                                        Bonifico 30gg
                                    </div>
                                </div>
                                
                                <!-- Righe fattura -->
                                <h6>Dettaglio prestazioni</h6>
                                <div class="table-responsive">
                                    <table class="table table-sm">
                                        <thead>
                                            <tr>
                                                <th>Codice Test</th>
                                                <th>Paziente</th>
                                                <th>Data</th>
                                                <th>Tipo</th>
                                                <th class="text-end">Importo</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($invoiceData['tests'] as $test): ?>
                                                <tr>
                                                    <td><?php echo htmlspecialchars($test['codice']); ?></td>
                                                    <td><?php echo htmlspecialchars($test['paziente']); ?></td>
                                                    <td><?php echo date('d/m/Y', strtotime($test['data'])); ?></td>
                                                    <td><?php echo htmlspecialchars($test['tipo']); ?></td>
                                                    <td class="text-end">
                                                        € <?php echo number_format($test['importo'], 2, ',', '.'); ?>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                        <tfoot>
                                            <tr>
                                                <th colspan="4" class="text-end">Imponibile:</th>
                                                <th class="text-end">
                                                    € <?php echo number_format($invoiceData['importo_totale'], 2, ',', '.'); ?>
                                                </th>
                                            </tr>
                                            <tr>
                                                <th colspan="4" class="text-end">IVA 22%:</th>
                                                <th class="text-end">
                                                    € <?php echo number_format($invoiceData['iva_totale'], 2, ',', '.'); ?>
                                                </th>
                                            </tr>
                                            <tr class="table-primary">
                                                <th colspan="4" class="text-end">Totale:</th>
                                                <th class="text-end">
                                                    € <?php echo number_format($invoiceData['importo_totale_ivato'], 2, ',', '.'); ?>
                                                </th>
                                            </tr>
                                        </tfoot>
                                    </table>
                                </div>
                                
                                <?php if ($invoiceData['note']): ?>
                                    <div class="mt-3">
                                        <strong>Note:</strong><br>
                                        <?php echo nl2br(htmlspecialchars($invoiceData['note'])); ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    
                    <div class="col-md-4">
                        <!-- Azioni -->
                        <div class="card mb-3">
                            <div class="card-header">
                                <h6 class="mb-0">Azioni</h6>
                            </div>
                            <div class="card-body">
                                <?php if ($invoiceData['stato'] === 'bozza'): ?>
                                    <button class="btn btn-success w-100 mb-2" 
                                            onclick="updateStatus(<?php echo $invoiceData['id']; ?>, 'emessa')">
                                        <i class="bi bi-check-circle"></i> Emetti Fattura
                                    </button>
                                <?php endif; ?>
                                
                                <?php if ($invoiceData['stato'] === 'emessa'): ?>
                                    <button class="btn btn-info w-100 mb-2" 
                                            onclick="updateStatus(<?php echo $invoiceData['id']; ?>, 'inviata')">
                                        <i class="bi bi-send"></i> Segna come Inviata
                                    </button>
                                    <button class="btn btn-success w-100 mb-2" 
                                            onclick="generateXML(<?php echo $invoiceData['id']; ?>)">
                                        <i class="bi bi-file-code"></i> Genera XML FatturaPA
                                    </button>
                                <?php endif; ?>
                                
                                <?php if (in_array($invoiceData['stato'], ['emessa', 'inviata'])): ?>
                                    <button class="btn btn-primary w-100 mb-2" 
                                            onclick="updateStatus(<?php echo $invoiceData['id']; ?>, 'pagata')">
                                        <i class="bi bi-cash"></i> Registra Pagamento
                                    </button>
                                <?php endif; ?>
                                
                                <?php if (!in_array($invoiceData['stato'], ['annullata', 'pagata'])): ?>
                                    <hr>
                                    <button class="btn btn-danger w-100" 
                                            onclick="if(confirm('Annullare la fattura?')) updateStatus(<?php echo $invoiceData['id']; ?>, 'annullata')">
                                        <i class="bi bi-x-circle"></i> Annulla Fattura
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <!-- Timeline -->
                        <div class="card">
                            <div class="card-header">
                                <h6 class="mb-0">Cronologia</h6>
                            </div>
                            <div class="card-body">
                                <ul class="list-unstyled">
                                    <li>
                                        <small class="text-muted">
                                            <?php echo date('d/m/Y H:i', strtotime($invoiceData['data_creazione'])); ?>
                                        </small><br>
                                        Fattura creata
                                    </li>
                                    <?php if ($invoiceData['data_emissione'] != $invoiceData['data_creazione']): ?>
                                        <li class="mt-2">
                                            <small class="text-muted">
                                                <?php echo date('d/m/Y H:i', strtotime($invoiceData['data_emissione'])); ?>
                                            </small><br>
                                            Fattura emessa
                                        </li>
                                    <?php endif; ?>
                                    <?php
                                    // Carica log fattura
                                    $logs = $db->select("
                                        SELECT l.*, u.nome, u.cognome
                                        FROM log_attivita l
                                        INNER JOIN utenti u ON l.utente_id = u.id
                                        WHERE l.azione LIKE :azione
                                        AND l.dettagli LIKE :dettagli
                                        ORDER BY l.timestamp DESC
                                    ", [
                                        'azione' => 'fattura_%',
                                        'dettagli' => '%ID ' . $invoiceData['id'] . '%'
                                    ]);
                                    
                                    foreach ($logs as $log): ?>
                                        <li class="mt-2">
                                            <small class="text-muted">
                                                <?php echo date('d/m/Y H:i', strtotime($log['timestamp'])); ?>
                                            </small><br>
                                            <?php echo htmlspecialchars($log['dettagli']); ?>
                                            <small class="text-muted">
                                                - <?php echo htmlspecialchars($log['nome'] . ' ' . $log['cognome']); ?>
                                            </small>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>
                
            <?php endif; ?>
        </main>
    </div>
</div>

<!-- Form nascosti per azioni -->
<form method="post" id="statusForm" style="display: none;">
    <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
    <input type="hidden" name="action" value="update_status">
    <input type="hidden" name="invoice_id" id="status_invoice_id">
    <input type="hidden" name="new_status" id="new_status">
</form>

<form method="post" id="xmlForm" style="display: none;">
    <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
    <input type="hidden" name="action" value="generate_xml">
    <input type="hidden" name="invoice_id" id="xml_invoice_id">
</form>

<script>
// Selezione test singolo
document.querySelectorAll('.test-single').forEach(radio => {
    radio.addEventListener('change', function() {
        document.getElementById('single_test_id').value = this.value;
        document.getElementById('btnSingleInvoice').disabled = false;
    });
});

// Preview fattura mensile
document.getElementById('monthly_prof')?.addEventListener('change', function() {
    const profId = this.value;
    if (!profId) {
        document.getElementById('monthlyPreview').style.display = 'none';
        document.getElementById('btnMonthlyInvoice').disabled = true;
        return;
    }
    
    // Mostra preview
    const testsData = <?php echo json_encode($testPerProfessionista); ?>;
    const profData = testsData[profId];
    
    if (profData) {
        let total = 0;
        let count = 0;
        
        profData.tests.forEach(test => {
            total += parseFloat(test.prezzo_finale);
            count++;
        });
        
        const preview = document.getElementById('monthlyPreview');
        preview.innerHTML = `
            <strong>Riepilogo:</strong><br>
            Test da fatturare: ${count}<br>
            Totale imponibile: € ${total.toFixed(2).replace('.', ',')}<br>
            IVA 22%: € ${(total * 0.22).toFixed(2).replace('.', ',')}<br>
            <strong>Totale fattura: € ${(total * 1.22).toFixed(2).replace('.', ',')}</strong>
        `;
        preview.style.display = '';
        document.getElementById('btnMonthlyInvoice').disabled = false;
    }
});

// Aggiorna stato fattura
function updateStatus(invoiceId, newStatus) {
    document.getElementById('status_invoice_id').value = invoiceId;
    document.getElementById('new_status').value = newStatus;
    document.getElementById('statusForm').submit();
}

// Genera XML
function generateXML(invoiceId) {
    document.getElementById('xml_invoice_id').value = invoiceId;
    document.getElementById('xmlForm').submit();
}

// Genera PDF (da implementare)
function generatePDF(invoiceId) {
    alert('Generazione PDF in sviluppo');
}
</script>

<?php require_once '../templates/footer.php'; ?>
