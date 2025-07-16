<?php
/**
 * Gestione Referti - Area Biologo
 * SerteX+ Genetic Lab Portal
 */

require_once '../includes/auth.php';
require_once '../includes/database.php';
require_once '../includes/session.php';
require_once '../includes/functions.php';
require_once '../classes/Report.php';
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
$action = $_GET['action'] ?? 'list';
$testId = intval($_GET['test_id'] ?? 0);

// Gestione azioni POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? '';
    
    if (!$session->validateCsrfToken($csrfToken)) {
        $session->setFlash('error', 'Token di sicurezza non valido');
    } else {
        $postAction = $_POST['action'] ?? '';
        
        switch ($postAction) {
            case 'generate_report':
                $testId = intval($_POST['test_id']);
                $report = new Report();
                
                $result = $report->generateReport($testId, $user->getId());
                
                if ($result['success']) {
                    $logger->log($user->getId(), 'referto_generato', "Test ID: {$testId}");
                    $session->setFlash('success', 'Referto generato con successo');
                    
                    // Aggiorna stato test
                    $db->update('test', [
                        'stato' => 'refertato',
                        'data_refertazione' => date('Y-m-d H:i:s')
                    ], ['id' => $testId]);
                    
                    header('Location: reports.php?action=view&test_id=' . $testId);
                    exit;
                } else {
                    $session->setFlash('error', 'Errore nella generazione del referto: ' . $result['error']);
                }
                break;
                
            case 'upload_report':
                // Per test microbiota - upload referto esterno
                $testId = intval($_POST['test_id']);
                
                if (!empty($_FILES['referto']['name'])) {
                    $uploadResult = uploadFile($_FILES['referto'], 'referti', ['pdf']);
                    
                    if ($uploadResult['success']) {
                        // Cripta il file con il CF del paziente
                        $test = $db->selectOne("
                            SELECT p.codice_fiscale 
                            FROM test t 
                            INNER JOIN pazienti p ON t.paziente_id = p.id 
                            WHERE t.id = :id
                        ", ['id' => $testId]);
                        
                        if ($test) {
                            $encryptedPath = encryptPDF($uploadResult['path'], strtoupper($test['codice_fiscale']));
                            
                            if ($encryptedPath) {
                                // Salva referto nel database
                                $db->insert('referti', [
                                    'test_id' => $testId,
                                    'tipo_referto' => 'microbiota',
                                    'file_path' => $encryptedPath,
                                    'biologo_id' => $user->getId(),
                                    'hash_file' => hash_file('sha256', $encryptedPath)
                                ]);
                                
                                // Aggiorna stato test
                                $db->update('test', [
                                    'stato' => 'refertato',
                                    'data_refertazione' => date('Y-m-d H:i:s')
                                ], ['id' => $testId]);
                                
                                $logger->log($user->getId(), 'referto_caricato', "Test ID: {$testId}");
                                $session->setFlash('success', 'Referto caricato con successo');
                                
                                header('Location: reports.php');
                                exit;
                            }
                        }
                    }
                    
                    $session->setFlash('error', 'Errore nel caricamento del referto');
                }
                break;
                
            case 'upload_signed':
                // Upload referto firmato digitalmente
                $refertoId = intval($_POST['referto_id']);
                
                if (!empty($_FILES['referto_firmato']['name'])) {
                    $uploadResult = uploadFile($_FILES['referto_firmato'], 'referti', ['pdf', 'p7m']);
                    
                    if ($uploadResult['success']) {
                        // Aggiorna referto
                        $db->update('referti', [
                            'file_path_firmato' => $uploadResult['path'],
                            'data_firma' => date('Y-m-d H:i:s')
                        ], ['id' => $refertoId]);
                        
                        // Aggiorna stato test
                        $referto = $db->selectOne("SELECT test_id FROM referti WHERE id = :id", ['id' => $refertoId]);
                        if ($referto) {
                            $db->update('test', ['stato' => 'firmato'], ['id' => $referto['test_id']]);
                        }
                        
                        $logger->log($user->getId(), 'referto_firmato', "Referto ID: {$refertoId}");
                        $session->setFlash('success', 'Referto firmato caricato con successo');
                    } else {
                        $session->setFlash('error', 'Errore nel caricamento del file firmato');
                    }
                }
                break;
        }
    }
}

// Carica dati in base all'azione
$pageData = [];

switch ($action) {
    case 'create':
        // Verifica che il test esista e sia pronto per la refertazione
        $test = $db->selectOne("
            SELECT t.*, tt.tipo_test,
                   p.nome as paziente_nome, 
                   p.cognome as paziente_cognome,
                   p.codice_fiscale,
                   p.data_nascita,
                   p.sesso
            FROM test t
            INNER JOIN pazienti p ON t.paziente_id = p.id
            WHERE t.id = :id AND t.stato = 'eseguito'
        ", ['id' => $testId]);
        
        if (!$test) {
            $session->setFlash('error', 'Test non trovato o non pronto per la refertazione');
            header('Location: reports.php');
            exit;
        }
        
        $pageData['test'] = $test;
        break;
        
    case 'view':
        // Carica referto esistente
        $referto = $db->selectOne("
            SELECT r.*, t.codice as test_codice, t.tipo_test,
                   p.nome as paziente_nome, p.cognome as paziente_cognome
            FROM referti r
            INNER JOIN test t ON r.test_id = t.id
            INNER JOIN pazienti p ON t.paziente_id = p.id
            WHERE r.test_id = :test_id
        ", ['test_id' => $testId]);
        
        if (!$referto) {
            $session->setFlash('error', 'Referto non trovato');
            header('Location: reports.php');
            exit;
        }
        
        $pageData['referto'] = $referto;
        break;
        
    case 'list':
    default:
        // Lista referti
        $filter = $_GET['filter'] ?? 'all';
        $search = $_GET['search'] ?? '';
        
        $query = "
            SELECT r.*, t.codice as test_codice, t.tipo_test, t.stato,
                   p.nome as paziente_nome, p.cognome as paziente_cognome,
                   u.nome as biologo_nome, u.cognome as biologo_cognome
            FROM referti r
            INNER JOIN test t ON r.test_id = t.id
            INNER JOIN pazienti p ON t.paziente_id = p.id
            INNER JOIN utenti u ON r.biologo_id = u.id
            WHERE 1=1
        ";
        
        $params = [];
        
        if ($filter === 'unsigned') {
            $query .= " AND r.file_path_firmato IS NULL";
        } elseif ($filter === 'signed') {
            $query .= " AND r.file_path_firmato IS NOT NULL";
        }
        
        if ($search) {
            $query .= " AND (t.codice LIKE :search OR 
                            CONCAT(p.nome, ' ', p.cognome) LIKE :search)";
            $params['search'] = "%{$search}%";
        }
        
        $query .= " ORDER BY r.data_creazione DESC";
        
        $pageData['referti'] = $db->select($query, $params);
        
        // Pseudonimizza nomi pazienti
        foreach ($pageData['referti'] as &$referto) {
            $referto['paziente_display'] = substr($referto['paziente_nome'], 0, 1) . '*** ' . 
                                          substr($referto['paziente_cognome'], 0, 1) . '***';
        }
        break;
}

// Lista test da refertare
$testsToReport = $db->select("
    SELECT t.*, p.nome, p.cognome, p.codice_fiscale
    FROM test t
    INNER JOIN pazienti p ON t.paziente_id = p.id
    WHERE t.stato = 'eseguito'
    ORDER BY t.data_esecuzione ASC
");

// Pseudonimizza
foreach ($testsToReport as &$test) {
    $test['paziente_display'] = substr($test['nome'], 0, 1) . '*** ' . 
                               substr($test['cognome'], 0, 1) . '***';
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
            <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                <h1 class="h2">Gestione Referti</h1>
                <div class="btn-toolbar mb-2 mb-md-0">
                    <?php if ($action !== 'list'): ?>
                        <a href="reports.php" class="btn btn-sm btn-secondary">
                            <i class="bi bi-arrow-left"></i> Torna alla lista
                        </a>
                    <?php endif; ?>
                </div>
            </div>
            
            <?php foreach ($session->getFlashMessages() as $flash): ?>
                <div class="alert alert-<?php echo $flash['type'] === 'error' ? 'danger' : $flash['type']; ?> alert-dismissible fade show" role="alert">
                    <?php echo htmlspecialchars($flash['message']); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endforeach; ?>
            
            <?php if ($action === 'list'): ?>
                <!-- Test da refertare -->
                <?php if (!empty($testsToReport)): ?>
                <div class="card mb-4">
                    <div class="card-header bg-warning text-dark">
                        <h5 class="mb-0">
                            <i class="bi bi-exclamation-triangle"></i> 
                            Test in attesa di refertazione (<?php echo count($testsToReport); ?>)
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-sm">
                                <thead>
                                    <tr>
                                        <th>Codice</th>
                                        <th>Tipo</th>
                                        <th>Paziente</th>
                                        <th>Data Esecuzione</th>
                                        <th>Azioni</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($testsToReport as $test): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($test['codice']); ?></td>
                                        <td><?php echo htmlspecialchars($test['tipo_test']); ?></td>
                                        <td><?php echo htmlspecialchars($test['paziente_display']); ?></td>
                                        <td><?php echo $test['data_esecuzione'] ? date('d/m/Y', strtotime($test['data_esecuzione'])) : '-'; ?></td>
                                        <td>
                                            <?php if ($test['tipo_test'] === 'microbiota'): ?>
                                                <button type="button" class="btn btn-sm btn-primary" 
                                                        data-bs-toggle="modal" 
                                                        data-bs-target="#uploadModal"
                                                        onclick="setUploadTestId(<?php echo $test['id']; ?>)">
                                                    <i class="bi bi-upload"></i> Carica Referto
                                                </button>
                                            <?php else: ?>
                                                <a href="reports.php?action=create&test_id=<?php echo $test['id']; ?>" 
                                                   class="btn btn-sm btn-primary">
                                                    <i class="bi bi-file-earmark-medical"></i> Genera Referto
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
                <?php endif; ?>
                
                <!-- Filtri referti -->
                <div class="card mb-3">
                    <div class="card-body">
                        <form method="get" class="row g-3">
                            <div class="col-md-3">
                                <select class="form-select" name="filter" onchange="this.form.submit()">
                                    <option value="all" <?php echo ($_GET['filter'] ?? 'all') === 'all' ? 'selected' : ''; ?>>
                                        Tutti i referti
                                    </option>
                                    <option value="unsigned" <?php echo ($_GET['filter'] ?? '') === 'unsigned' ? 'selected' : ''; ?>>
                                        Da firmare
                                    </option>
                                    <option value="signed" <?php echo ($_GET['filter'] ?? '') === 'signed' ? 'selected' : ''; ?>>
                                        Firmati
                                    </option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <input type="text" class="form-control" name="search" 
                                       placeholder="Cerca per codice test o paziente..." 
                                       value="<?php echo htmlspecialchars($_GET['search'] ?? ''); ?>">
                            </div>
                            <div class="col-md-3">
                                <button type="submit" class="btn btn-primary">
                                    <i class="bi bi-search"></i> Cerca
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
                
                <!-- Lista referti -->
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">Referti Emessi</h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-striped">
                                <thead>
                                    <tr>
                                        <th>Codice Test</th>
                                        <th>Tipo</th>
                                        <th>Paziente</th>
                                        <th>Data Referto</th>
                                        <th>Biologo</th>
                                        <th>Stato</th>
                                        <th>Azioni</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($pageData['referti'] as $referto): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($referto['test_codice']); ?></td>
                                        <td><?php echo htmlspecialchars($referto['tipo_referto']); ?></td>
                                        <td><?php echo htmlspecialchars($referto['paziente_display']); ?></td>
                                        <td><?php echo date('d/m/Y H:i', strtotime($referto['data_creazione'])); ?></td>
                                        <td>
                                            <?php echo htmlspecialchars($referto['biologo_nome'] . ' ' . $referto['biologo_cognome']); ?>
                                        </td>
                                        <td>
                                            <?php if ($referto['file_path_firmato']): ?>
                                                <span class="badge bg-success">Firmato</span>
                                            <?php else: ?>
                                                <span class="badge bg-warning">Da firmare</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <a href="../<?php echo htmlspecialchars($referto['file_path']); ?>" 
                                               class="btn btn-sm btn-info" target="_blank">
                                                <i class="bi bi-download"></i> Download
                                            </a>
                                            
                                            <?php if (!$referto['file_path_firmato']): ?>
                                                <button type="button" class="btn btn-sm btn-primary" 
                                                        data-bs-toggle="modal" 
                                                        data-bs-target="#uploadSignedModal"
                                                        onclick="setUploadRefertoId(<?php echo $referto['id']; ?>)">
                                                    <i class="bi bi-pen"></i> Carica Firmato
                                                </button>
                                            <?php else: ?>
                                                <a href="../<?php echo htmlspecialchars($referto['file_path_firmato']); ?>" 
                                                   class="btn btn-sm btn-success" target="_blank">
                                                    <i class="bi bi-download"></i> Firmato
                                                </a>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                    
                                    <?php if (empty($pageData['referti'])): ?>
                                    <tr>
                                        <td colspan="7" class="text-center text-muted py-3">
                                            Nessun referto trovato
                                        </td>
                                    </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                
            <?php elseif ($action === 'create'): ?>
                <!-- Genera referto -->
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">Genera Referto</h5>
                    </div>
                    <div class="card-body">
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <h6>Informazioni Test</h6>
                                <table class="table table-sm">
                                    <tr>
                                        <th>Codice:</th>
                                        <td><?php echo htmlspecialchars($pageData['test']['codice']); ?></td>
                                    </tr>
                                    <tr>
                                        <th>Tipo:</th>
                                        <td><?php echo htmlspecialchars($pageData['test']['tipo_test']); ?></td>
                                    </tr>
                                    <tr>
                                        <th>Paziente:</th>
                                        <td>
                                            <?php echo htmlspecialchars($pageData['test']['paziente_nome'] . ' ' . 
                                                                      $pageData['test']['paziente_cognome']); ?>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th>Data Nascita:</th>
                                        <td><?php echo date('d/m/Y', strtotime($pageData['test']['data_nascita'])); ?></td>
                                    </tr>
                                </table>
                            </div>
                            <div class="col-md-6">
                                <h6>Anteprima Risultati</h6>
                                <?php
                                // Mostra anteprima risultati in base al tipo
                                if ($pageData['test']['tipo_test'] === 'genetico') {
                                    $risultati = $db->select("
                                        SELECT g.nome as gene_nome, rg.nome as risultato_nome, rg.tipo
                                        FROM risultati_genetici r
                                        INNER JOIN geni g ON r.gene_id = g.id
                                        INNER JOIN risultati_geni rg ON r.risultato_id = rg.id
                                        WHERE r.test_id = :test_id
                                        LIMIT 5
                                    ", ['test_id' => $testId]);
                                    
                                    if ($risultati) {
                                        echo '<ul class="small">';
                                        foreach ($risultati as $ris) {
                                            $badge = $ris['tipo'] === 'positivo' ? 'danger' : 
                                                    ($ris['tipo'] === 'negativo' ? 'success' : 'secondary');
                                            echo '<li>' . htmlspecialchars($ris['gene_nome']) . ': ';
                                            echo '<span class="badge bg-' . $badge . '">' . 
                                                 htmlspecialchars($ris['risultato_nome']) . '</span></li>';
                                        }
                                        if (count($risultati) >= 5) {
                                            echo '<li>...</li>';
                                        }
                                        echo '</ul>';
                                    }
                                } elseif (in_array($pageData['test']['tipo_test'], ['intolleranze_cito', 'intolleranze_elisa'])) {
                                    $intolleranze = $db->select("
                                        SELECT a.nome, ri.grado
                                        FROM risultati_intolleranze ri
                                        INNER JOIN alimenti a ON ri.alimento_id = a.id
                                        WHERE ri.test_id = :test_id AND ri.grado > 0
                                        ORDER BY ri.grado DESC
                                        LIMIT 5
                                    ", ['test_id' => $testId]);
                                    
                                    if ($intolleranze) {
                                        echo '<ul class="small">';
                                        foreach ($intolleranze as $int) {
                                            $badge = $int['grado'] == 3 ? 'danger' : 
                                                    ($int['grado'] == 2 ? 'warning' : 'info');
                                            echo '<li>' . htmlspecialchars($int['nome']) . ': ';
                                            echo '<span class="badge bg-' . $badge . '">Grado ' . 
                                                 $int['grado'] . '</span></li>';
                                        }
                                        echo '</ul>';
                                    } else {
                                        echo '<p class="text-muted small">Nessuna intolleranza rilevata</p>';
                                    }
                                }
                                ?>
                            </div>
                        </div>
                        
                        <hr>
                        
                        <form method="post">
                            <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                            <input type="hidden" name="action" value="generate_report">
                            <input type="hidden" name="test_id" value="<?php echo $testId; ?>">
                            
                            <div class="alert alert-info">
                                <i class="bi bi-info-circle"></i>
                                Il referto verrà generato automaticamente utilizzando i template configurati 
                                e sarà crittografato con il codice fiscale del paziente.
                            </div>
                            
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-file-earmark-medical"></i> Genera Referto
                            </button>
                            <a href="reports.php" class="btn btn-secondary">Annulla</a>
                        </form>
                    </div>
                </div>
                
            <?php elseif ($action === 'view'): ?>
                <!-- Visualizza referto -->
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">Dettagli Referto</h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <table class="table table-sm">
                                    <tr>
                                        <th>Codice Test:</th>
                                        <td><?php echo htmlspecialchars($pageData['referto']['test_codice']); ?></td>
                                    </tr>
                                    <tr>
                                        <th>Tipo:</th>
                                        <td><?php echo htmlspecialchars($pageData['referto']['tipo_referto']); ?></td>
                                    </tr>
                                    <tr>
                                        <th>Paziente:</th>
                                        <td>
                                            <?php echo htmlspecialchars($pageData['referto']['paziente_nome'] . ' ' . 
                                                                      $pageData['referto']['paziente_cognome']); ?>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th>Data Creazione:</th>
                                        <td><?php echo date('d/m/Y H:i', strtotime($pageData['referto']['data_creazione'])); ?></td>
                                    </tr>
                                </table>
                            </div>
                            <div class="col-md-6">
                                <table class="table table-sm">
                                    <tr>
                                        <th>Stato:</th>
                                        <td>
                                            <?php if ($pageData['referto']['file_path_firmato']): ?>
                                                <span class="badge bg-success">Firmato Digitalmente</span>
                                            <?php else: ?>
                                                <span class="badge bg-warning">In attesa di firma</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                    <tr>
                                        <th>Hash File:</th>
                                        <td>
                                            <small class="font-monospace">
                                                <?php echo substr($pageData['referto']['hash_file'], 0, 16); ?>...
                                            </small>
                                        </td>
                                    </tr>
                                    <?php if ($pageData['referto']['data_firma']): ?>
                                    <tr>
                                        <th>Data Firma:</th>
                                        <td><?php echo date('d/m/Y H:i', strtotime($pageData['referto']['data_firma'])); ?></td>
                                    </tr>
                                    <?php endif; ?>
                                </table>
                            </div>
                        </div>
                        
                        <hr>
                        
                        <div class="d-flex gap-2">
                            <a href="../<?php echo htmlspecialchars($pageData['referto']['file_path']); ?>" 
                               class="btn btn-primary" target="_blank">
                                <i class="bi bi-download"></i> Scarica Referto
                            </a>
                            
                            <?php if ($pageData['referto']['file_path_firmato']): ?>
                                <a href="../<?php echo htmlspecialchars($pageData['referto']['file_path_firmato']); ?>" 
                                   class="btn btn-success" target="_blank">
                                    <i class="bi bi-download"></i> Scarica Referto Firmato
                                </a>
                            <?php else: ?>
                                <button type="button" class="btn btn-warning" 
                                        data-bs-toggle="modal" 
                                        data-bs-target="#uploadSignedModal"
                                        onclick="setUploadRefertoId(<?php echo $pageData['referto']['id']; ?>)">
                                    <i class="bi bi-pen"></i> Carica Referto Firmato
                                </button>
                            <?php endif; ?>
                            
                            <a href="reports.php" class="btn btn-secondary">
                                <i class="bi bi-arrow-left"></i> Torna alla lista
                            </a>
                        </div>
                        
                        <div class="alert alert-info mt-3">
                            <h6>Istruzioni per la firma digitale:</h6>
                            <ol class="mb-0">
                                <li>Scarica il referto PDF</li>
                                <li>Firma digitalmente il documento con il tuo certificato</li>
                                <li>Carica il file firmato (.p7m) utilizzando il pulsante sopra</li>
                            </ol>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </main>
    </div>
</div>

<!-- Modal Upload Referto Microbiota -->
<div class="modal fade" id="uploadModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Carica Referto Microbiota</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="post" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                <input type="hidden" name="action" value="upload_report">
                <input type="hidden" name="test_id" id="upload_test_id">
                
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="referto" class="form-label">File Referto (PDF)</label>
                        <input type="file" class="form-control" id="referto" name="referto" 
                               accept=".pdf" required>
                        <small class="text-muted">
                            Il file verrà crittografato automaticamente con il CF del paziente
                        </small>
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annulla</button>
                    <button type="submit" class="btn btn-primary">Carica</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal Upload Referto Firmato -->
<div class="modal fade" id="uploadSignedModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Carica Referto Firmato Digitalmente</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="post" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                <input type="hidden" name="action" value="upload_signed">
                <input type="hidden" name="referto_id" id="upload_referto_id">
                
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="referto_firmato" class="form-label">File Firmato (.pdf.p7m)</label>
                        <input type="file" class="form-control" id="referto_firmato" name="referto_firmato" 
                               accept=".p7m,.pdf" required>
                        <small class="text-muted">
                            Caricare il file PDF firmato digitalmente
                        </small>
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annulla</button>
                    <button type="submit" class="btn btn-primary">Carica</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function setUploadTestId(testId) {
    document.getElementById('upload_test_id').value = testId;
}

function setUploadRefertoId(refertoId) {
    document.getElementById('upload_referto_id').value = refertoId;
}
</script>

<?php require_once '../templates/footer.php'; ?>