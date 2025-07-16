<?php
/**
 * Gestione Test - Area Professionista
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
if (!$auth->isAuthenticated() || !$auth->hasRole('professionista')) {
    header('Location: ../index.php');
    exit;
}

$user = $auth->getCurrentUser();

// Recupera ID professionista
$professionista = $db->selectOne(
    "SELECT * FROM professionisti WHERE utente_id = :user_id",
    ['user_id' => $user->getId()]
);

if (!$professionista) {
    die("Errore: profilo professionista non trovato");
}

$action = $_GET['action'] ?? 'list';
$patientId = intval($_GET['patient_id'] ?? 0);
$testId = intval($_GET['test_id'] ?? 0);

// Per nuova richiesta, verifica paziente
if ($action === 'new' && $patientId) {
    $patient = $db->selectOne(
        "SELECT * FROM pazienti WHERE id = :id AND professionista_id = :prof_id",
        ['id' => $patientId, 'prof_id' => $professionista['id']]
    );
    
    if (!$patient) {
        $session->setFlash('error', 'Paziente non trovato');
        header('Location: tests.php');
        exit;
    }
}

// Gestione creazione test
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'new') {
    $csrfToken = $_POST['csrf_token'] ?? '';
    
    if (!$session->validateCsrfToken($csrfToken)) {
        $session->setFlash('error', 'Token di sicurezza non valido');
    } else {
        try {
            $db->beginTransaction();
            
            // Genera codice univoco
            $codice = 'T' . date('Y') . str_pad(mt_rand(1, 999999), 6, '0', STR_PAD_LEFT);
            
            // Crea test base
            $testData = [
                'codice' => $codice,
                'paziente_id' => intval($_POST['paziente_id']),
                'professionista_id' => $professionista['id'],
                'tipo_test' => $_POST['tipo_test'],
                'stato' => 'richiesto',
                'note' => sanitizeInput($_POST['note'] ?? ''),
                'prezzo_totale' => 0,
                'sconto' => floatval($_POST['sconto'] ?? 0),
                'prezzo_finale' => 0,
                'iva' => floatval($_POST['iva'] ?? 22)
            ];
            
            $testId = $db->insert('test', $testData);
            $prezzoTotale = 0;
            
            // Gestisci dettagli in base al tipo
            switch ($_POST['tipo_test']) {
                case 'genetico':
                    // Geni singoli
                    if (!empty($_POST['geni']) && is_array($_POST['geni'])) {
                        foreach ($_POST['geni'] as $geneId) {
                            $gene = $db->selectOne(
                                "SELECT prezzo FROM geni WHERE id = :id AND attivo = 1",
                                ['id' => $geneId]
                            );
                            if ($gene) {
                                $db->insert('test_genetici_dettagli', [
                                    'test_id' => $testId,
                                    'tipo_elemento' => 'gene',
                                    'elemento_id' => $geneId,
                                    'prezzo_unitario' => $gene['prezzo']
                                ]);
                                $prezzoTotale += $gene['prezzo'];
                            }
                        }
                    }
                    
                    // Pannelli
                    if (!empty($_POST['pannelli']) && is_array($_POST['pannelli'])) {
                        foreach ($_POST['pannelli'] as $pannelloId) {
                            $pannello = $db->selectOne(
                                "SELECT prezzo FROM pannelli_genetici WHERE id = :id AND attivo = 1",
                                ['id' => $pannelloId]
                            );
                            if ($pannello) {
                                $dettaglioId = $db->insert('test_genetici_dettagli', [
                                    'test_id' => $testId,
                                    'tipo_elemento' => 'pannello',
                                    'elemento_id' => $pannelloId,
                                    'prezzo_unitario' => $pannello['prezzo']
                                ]);
                                $prezzoTotale += $pannello['prezzo'];
                                
                                // Geni aggiuntivi per questo pannello
                                $geniAggKey = 'geni_aggiuntivi_' . $pannelloId;
                                if (!empty($_POST[$geniAggKey]) && is_array($_POST[$geniAggKey])) {
                                    foreach ($_POST[$geniAggKey] as $geneAggId) {
                                        $geneAgg = $db->selectOne(
                                            "SELECT prezzo FROM geni WHERE id = :id AND attivo = 1",
                                            ['id' => $geneAggId]
                                        );
                                        if ($geneAgg) {
                                            $db->insert('test_genetici_geni_aggiuntivi', [
                                                'test_dettaglio_id' => $dettaglioId,
                                                'gene_id' => $geneAggId,
                                                'prezzo_unitario' => $geneAgg['prezzo']
                                            ]);
                                            $prezzoTotale += $geneAgg['prezzo'];
                                        }
                                    }
                                }
                            }
                        }
                    }
                    break;
                    
                case 'microbiota':
                    if (!empty($_POST['tipi_microbiota']) && is_array($_POST['tipi_microbiota'])) {
                        foreach ($_POST['tipi_microbiota'] as $tipoId) {
                            $tipo = $db->selectOne(
                                "SELECT prezzo FROM tipi_microbiota WHERE id = :id AND attivo = 1",
                                ['id' => $tipoId]
                            );
                            if ($tipo) {
                                $db->insert('test_microbiota_dettagli', [
                                    'test_id' => $testId,
                                    'tipo_microbiota_id' => $tipoId,
                                    'prezzo_unitario' => $tipo['prezzo']
                                ]);
                                $prezzoTotale += $tipo['prezzo'];
                            }
                        }
                    }
                    break;
                    
                case 'intolleranze_cito':
                case 'intolleranze_elisa':
                    if (!empty($_POST['pannello_intolleranze'])) {
                        $pannello = $db->selectOne(
                            "SELECT prezzo FROM pannelli_intolleranze 
                             WHERE id = :id AND tipo = :tipo AND attivo = 1",
                            [
                                'id' => $_POST['pannello_intolleranze'],
                                'tipo' => $_POST['tipo_test'] === 'intolleranze_cito' ? 'citotossico' : 'elisa'
                            ]
                        );
                        if ($pannello) {
                            $db->insert('test_intolleranze_dettagli', [
                                'test_id' => $testId,
                                'pannello_id' => $_POST['pannello_intolleranze'],
                                'prezzo_unitario' => $pannello['prezzo']
                            ]);
                            $prezzoTotale += $pannello['prezzo'];
                        }
                    }
                    break;
            }
            
            // Applica listino personalizzato se presente
            if ($professionista['listino_id']) {
                // TODO: Implementare calcolo con listino personalizzato
            }
            
            // Calcola prezzo finale con sconto
            $sconto = floatval($_POST['sconto'] ?? 0);
            $prezzoFinale = $prezzoTotale - ($prezzoTotale * $sconto / 100);
            
            // Aggiorna prezzi
            $db->update('test', [
                'prezzo_totale' => $prezzoTotale,
                'prezzo_finale' => $prezzoFinale
            ], ['id' => $testId]);
            
            // Genera barcode
            $barcodePath = generateBarcode($codice);
            if ($barcodePath) {
                $db->update('test', ['barcode' => $barcodePath], ['id' => $testId]);
            }
            
            $db->commit();
            
            $logger->log($user->getId(), 'test_creato', "Creato test {$codice} per paziente ID: {$_POST['paziente_id']}");
            $session->setFlash('success', "Test creato con successo. Codice: {$codice}");
            
            header('Location: tests.php?highlight=' . $testId);
            exit;
            
        } catch (Exception $e) {
            $db->rollback();
            $session->setFlash('error', 'Errore nella creazione del test');
            error_log("Errore creazione test: " . $e->getMessage());
        }
    }
}

// Carica dati per lista test
$filter = $_GET['filter'] ?? 'all';
$search = $_GET['search'] ?? '';
$page = intval($_GET['page'] ?? 1);
$limit = 20;
$offset = ($page - 1) * $limit;

// Query test
$query = "
    SELECT t.*, 
           p.nome as paziente_nome,
           p.cognome as paziente_cognome,
           p.codice_fiscale,
           COUNT(DISTINCT CASE WHEN t.tipo_test = 'genetico' THEN tgd.id END) as num_analisi_genetiche,
           COUNT(DISTINCT CASE WHEN t.tipo_test = 'microbiota' THEN tmd.id END) as num_microbiota,
           COUNT(DISTINCT CASE WHEN t.tipo_test IN ('intolleranze_cito', 'intolleranze_elisa') THEN tid.id END) as num_intolleranze
    FROM test t
    INNER JOIN pazienti p ON t.paziente_id = p.id
    LEFT JOIN test_genetici_dettagli tgd ON t.id = tgd.test_id
    LEFT JOIN test_microbiota_dettagli tmd ON t.id = tmd.test_id
    LEFT JOIN test_intolleranze_dettagli tid ON t.id = tid.test_id
    WHERE t.professionista_id = :prof_id
";

$params = ['prof_id' => $professionista['id']];

// Filtro per paziente specifico
if ($patientId) {
    $query .= " AND t.paziente_id = :paziente_id";
    $params['paziente_id'] = $patientId;
}

// Filtri stato
switch ($filter) {
    case 'pending':
        $query .= " AND t.stato IN ('richiesto', 'in_lavorazione', 'eseguito')";
        break;
    case 'completed':
        $query .= " AND t.stato IN ('refertato', 'firmato')";
        break;
    case 'invoiced':
        $query .= " AND t.fatturato = 1";
        break;
}

// Ricerca
if ($search) {
    $query .= " AND (t.codice LIKE :search 
                     OR p.nome LIKE :search 
                     OR p.cognome LIKE :search
                     OR p.codice_fiscale LIKE :search)";
    $params['search'] = "%{$search}%";
}

$query .= " GROUP BY t.id ORDER BY t.data_richiesta DESC LIMIT :limit OFFSET :offset";
$params['limit'] = $limit;
$params['offset'] = $offset;

$tests = $db->select($query, $params);

// Conta totale per paginazione
$countQuery = "SELECT COUNT(DISTINCT t.id) as total FROM test t ";
if ($patientId) {
    $countQuery .= "WHERE t.professionista_id = :prof_id AND t.paziente_id = :paziente_id";
} else {
    $countQuery .= "WHERE t.professionista_id = :prof_id";
}
$countParams = ['prof_id' => $professionista['id']];
if ($patientId) $countParams['paziente_id'] = $patientId;

$totalTests = $db->selectOne($countQuery, $countParams)['total'];
$totalPages = ceil($totalTests / $limit);

// Se action=new, carica dati necessari per il form
if ($action === 'new') {
    // Carica geni disponibili
    $geni = $db->select("
        SELECT g.*, gg.nome as gruppo_nome 
        FROM geni g 
        LEFT JOIN gruppi_geni gg ON g.gruppo_id = gg.id 
        WHERE g.attivo = 1 
        ORDER BY gg.ordine, gg.nome, g.nome
    ");
    
    // Carica pannelli genetici
    $pannelliGenetici = $db->select("
        SELECT pg.*, COUNT(pgn.gene_id) as num_geni
        FROM pannelli_genetici pg
        LEFT JOIN pannelli_geni pgn ON pg.id = pgn.pannello_id
        WHERE pg.attivo = 1
        GROUP BY pg.id
        ORDER BY pg.nome
    ");
    
    // Carica tipi microbiota
    $tipiMicrobiota = $db->select("
        SELECT * FROM tipi_microbiota WHERE attivo = 1 ORDER BY nome
    ");
    
    // Carica pannelli intolleranze
    $pannelliCito = $db->select("
        SELECT pi.*, COUNT(pa.alimento_id) as num_alimenti
        FROM pannelli_intolleranze pi
        LEFT JOIN pannelli_alimenti pa ON pi.id = pa.pannello_id
        WHERE pi.tipo = 'citotossico' AND pi.attivo = 1
        GROUP BY pi.id
        ORDER BY pi.nome
    ");
    
    $pannelliElisa = $db->select("
        SELECT pi.*, COUNT(pa.alimento_id) as num_alimenti
        FROM pannelli_intolleranze pi
        LEFT JOIN pannelli_alimenti pa ON pi.id = pa.pannello_id
        WHERE pi.tipo = 'elisa' AND pi.attivo = 1
        GROUP BY pi.id
        ORDER BY pi.nome
    ");
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
            <?php if ($action === 'list'): ?>
                <!-- Lista Test -->
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h2">
                        <?php if ($patientId && isset($patient)): ?>
                            Test di <?php echo htmlspecialchars($patient['nome'] . ' ' . $patient['cognome']); ?>
                        <?php else: ?>
                            Gestione Test
                        <?php endif; ?>
                    </h1>
                    <div class="btn-toolbar mb-2 mb-md-0">
                        <?php if ($patientId): ?>
                            <a href="patients.php" class="btn btn-sm btn-secondary me-2">
                                <i class="bi bi-arrow-left"></i> Torna ai pazienti
                            </a>
                        <?php endif; ?>
                        <a href="patients.php" class="btn btn-sm btn-primary">
                            <i class="bi bi-plus-circle"></i> Nuovo Test
                        </a>
                    </div>
                </div>
                
                <?php foreach ($session->getFlashMessages() as $flash): ?>
                    <div class="alert alert-<?php echo $flash['type'] === 'error' ? 'danger' : $flash['type']; ?> alert-dismissible fade show" role="alert">
                        <?php echo htmlspecialchars($flash['message']); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endforeach; ?>
                
                <!-- Filtri -->
                <div class="card mb-3">
                    <div class="card-body">
                        <form method="get" class="row g-3">
                            <?php if ($patientId): ?>
                                <input type="hidden" name="patient_id" value="<?php echo $patientId; ?>">
                            <?php endif; ?>
                            
                            <div class="col-md-3">
                                <select class="form-select" name="filter" onchange="this.form.submit()">
                                    <option value="all" <?php echo $filter === 'all' ? 'selected' : ''; ?>>
                                        Tutti i test
                                    </option>
                                    <option value="pending" <?php echo $filter === 'pending' ? 'selected' : ''; ?>>
                                        In attesa/lavorazione
                                    </option>
                                    <option value="completed" <?php echo $filter === 'completed' ? 'selected' : ''; ?>>
                                        Completati
                                    </option>
                                    <option value="invoiced" <?php echo $filter === 'invoiced' ? 'selected' : ''; ?>>
                                        Fatturati
                                    </option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <input type="text" class="form-control" name="search" 
                                       placeholder="Cerca per codice test o paziente..." 
                                       value="<?php echo htmlspecialchars($search); ?>">
                            </div>
                            <div class="col-md-3">
                                <button type="submit" class="btn btn-primary w-100">
                                    <i class="bi bi-search"></i> Cerca
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
                
                <!-- Tabella test -->
                <div class="card">
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Codice</th>
                                        <th>Data</th>
                                        <th>Paziente</th>
                                        <th>Tipo Test</th>
                                        <th>Analisi</th>
                                        <th>Stato</th>
                                        <th>Prezzo</th>
                                        <th>Azioni</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($tests as $test): ?>
                                        <?php
                                        $highlight = isset($_GET['highlight']) && $_GET['highlight'] == $test['id'];
                                        ?>
                                        <tr <?php echo $highlight ? 'class="table-warning"' : ''; ?>>
                                            <td>
                                                <strong><?php echo htmlspecialchars($test['codice']); ?></strong>
                                            </td>
                                            <td><?php echo date('d/m/Y', strtotime($test['data_richiesta'])); ?></td>
                                            <td>
                                                <?php echo htmlspecialchars($test['paziente_cognome'] . ' ' . $test['paziente_nome']); ?>
                                                <br>
                                                <small class="text-muted">
                                                    CF: <?php echo htmlspecialchars($test['codice_fiscale']); ?>
                                                </small>
                                            </td>
                                            <td>
                                                <?php
                                                $tipoLabel = [
                                                    'genetico' => '<i class="bi bi-dna"></i> Genetico',
                                                    'microbiota' => '<i class="bi bi-bug"></i> Microbiota',
                                                    'intolleranze_cito' => '<i class="bi bi-egg-fried"></i> Intoll. Cito',
                                                    'intolleranze_elisa' => '<i class="bi bi-egg"></i> Intoll. ELISA'
                                                ];
                                                echo $tipoLabel[$test['tipo_test']] ?? $test['tipo_test'];
                                                ?>
                                            </td>
                                            <td>
                                                <?php
                                                $numAnalisi = $test['num_analisi_genetiche'] + 
                                                             $test['num_microbiota'] + 
                                                             $test['num_intolleranze'];
                                                ?>
                                                <span class="badge bg-info"><?php echo $numAnalisi; ?> analisi</span>
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
                                                <?php if ($test['fatturato']): ?>
                                                    <span class="badge bg-secondary">
                                                        <i class="bi bi-receipt"></i> Fatturato
                                                    </span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                € <?php echo number_format($test['prezzo_finale'], 2, ',', '.'); ?>
                                                <?php if ($test['sconto'] > 0): ?>
                                                    <br>
                                                    <small class="text-muted">
                                                        <del>€ <?php echo number_format($test['prezzo_totale'], 2, ',', '.'); ?></del>
                                                        -<?php echo $test['sconto']; ?>%
                                                    </small>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <div class="btn-group btn-group-sm" role="group">
                                                    <button type="button" class="btn btn-info" 
                                                            onclick="viewTestDetails(<?php echo $test['id']; ?>)"
                                                            title="Dettagli">
                                                        <i class="bi bi-eye"></i>
                                                    </button>
                                                    
                                                    <?php if (in_array($test['stato'], ['refertato', 'firmato'])): ?>
                                                        <a href="reports.php?test_id=<?php echo $test['id']; ?>" 
                                                           class="btn btn-success" title="Scarica referto">
                                                            <i class="bi bi-file-earmark-medical"></i>
                                                        </a>
                                                    <?php endif; ?>
                                                    
                                                    <?php if ($test['barcode']): ?>
                                                        <button type="button" class="btn btn-secondary" 
                                                                onclick="printBarcode('<?php echo htmlspecialchars($test['barcode']); ?>')"
                                                                title="Stampa etichetta">
                                                            <i class="bi bi-upc"></i>
                                                        </button>
                                                    <?php endif; ?>
                                                    
                                                    <?php if ($test['stato'] === 'richiesto'): ?>
                                                        <button type="button" class="btn btn-danger" 
                                                                onclick="cancelTest(<?php echo $test['id']; ?>)"
                                                                title="Annulla">
                                                            <i class="bi bi-x-circle"></i>
                                                        </button>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                    
                                    <?php if (empty($tests)): ?>
                                        <tr>
                                            <td colspan="8" class="text-center text-muted py-4">
                                                Nessun test trovato
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                        
                        <!-- Paginazione -->
                        <?php if ($totalPages > 1): ?>
                            <nav aria-label="Navigazione pagine">
                                <ul class="pagination justify-content-center">
                                    <!-- Link pagine come in patients.php -->
                                </ul>
                            </nav>
                        <?php endif; ?>
                    </div>
                </div>
                
            <?php elseif ($action === 'new'): ?>
                <!-- Form Nuovo Test -->
                <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                    <h1 class="h2">Nuova Richiesta Test</h1>
                    <div class="btn-toolbar mb-2 mb-md-0">
                        <a href="tests.php" class="btn btn-sm btn-secondary">
                            <i class="bi bi-arrow-left"></i> Annulla
                        </a>
                    </div>
                </div>
                
                <form method="post" id="newTestForm">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                    <input type="hidden" name="paziente_id" value="<?php echo $patient['id']; ?>">
                    
                    <!-- Info Paziente -->
                    <div class="card mb-3">
                        <div class="card-header">
                            <h5 class="mb-0">Dati Paziente</h5>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-4">
                                    <strong>Nome:</strong> <?php echo htmlspecialchars($patient['nome'] . ' ' . $patient['cognome']); ?>
                                </div>
                                <div class="col-md-4">
                                    <strong>Codice Fiscale:</strong> <?php echo htmlspecialchars($patient['codice_fiscale']); ?>
                                </div>
                                <div class="col-md-4">
                                    <strong>Data Nascita:</strong> 
                                    <?php 
                                    if ($patient['data_nascita']) {
                                        echo date('d/m/Y', strtotime($patient['data_nascita']));
                                        $eta = date_diff(date_create($patient['data_nascita']), date_create())->y;
                                        echo " ({$eta} anni)";
                                    }
                                    ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Selezione Tipo Test -->
                    <div class="card mb-3">
                        <div class="card-header">
                            <h5 class="mb-0">Tipo di Test</h5>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-3">
                                    <div class="form-check">
                                        <input class="form-check-input" type="radio" name="tipo_test" 
                                               id="tipo_genetico" value="genetico" checked>
                                        <label class="form-check-label" for="tipo_genetico">
                                            <i class="bi bi-dna"></i> Test Genetico
                                        </label>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="form-check">
                                        <input class="form-check-input" type="radio" name="tipo_test" 
                                               id="tipo_microbiota" value="microbiota">
                                        <label class="form-check-label" for="tipo_microbiota">
                                            <i class="bi bi-bug"></i> Microbiota
                                        </label>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="form-check">
                                        <input class="form-check-input" type="radio" name="tipo_test" 
                                               id="tipo_cito" value="intolleranze_cito">
                                        <label class="form-check-label" for="tipo_cito">
                                            <i class="bi bi-egg-fried"></i> Intolleranze Citotossico
                                        </label>
                                    </div>
                                </div>
                                <div class="col-md-3">
                                    <div class="form-check">
                                        <input class="form-check-input" type="radio" name="tipo_test" 
                                               id="tipo_elisa" value="intolleranze_elisa">
                                        <label class="form-check-label" for="tipo_elisa">
                                            <i class="bi bi-egg"></i> Intolleranze ELISA
                                        </label>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Dettagli Test Genetico -->
                    <div class="test-details" id="details_genetico">
                        <div class="card mb-3">
                            <div class="card-header">
                                <h5 class="mb-0">Selezione Analisi Genetiche</h5>
                            </div>
                            <div class="card-body">
                                <!-- Tab pannelli/geni -->
                                <ul class="nav nav-tabs mb-3" role="tablist">
                                    <li class="nav-item">
                                        <a class="nav-link active" data-bs-toggle="tab" href="#pannelli">
                                            Pannelli Genetici
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a class="nav-link" data-bs-toggle="tab" href="#geni_singoli">
                                            Geni Singoli
                                        </a>
                                    </li>
                                </ul>
                                
                                <div class="tab-content">
                                    <!-- Pannelli -->
                                    <div class="tab-pane fade show active" id="pannelli">
                                        <div class="row">
                                            <?php foreach ($pannelliGenetici as $pannello): ?>
                                                <div class="col-md-6 mb-3">
                                                    <div class="card h-100">
                                                        <div class="card-body">
                                                            <div class="form-check">
                                                                <input class="form-check-input pannello-check" 
                                                                       type="checkbox" 
                                                                       name="pannelli[]" 
                                                                       value="<?php echo $pannello['id']; ?>"
                                                                       id="pannello_<?php echo $pannello['id']; ?>"
                                                                       data-prezzo="<?php echo $pannello['prezzo']; ?>"
                                                                       data-nome="<?php echo htmlspecialchars($pannello['nome']); ?>">
                                                                <label class="form-check-label" 
                                                                       for="pannello_<?php echo $pannello['id']; ?>">
                                                                    <strong><?php echo htmlspecialchars($pannello['nome']); ?></strong>
                                                                    <span class="badge bg-info">
                                                                        <?php echo $pannello['num_geni']; ?> geni
                                                                    </span>
                                                                    <span class="badge bg-success">
                                                                        € <?php echo number_format($pannello['prezzo'], 2, ',', '.'); ?>
                                                                    </span>
                                                                </label>
                                                            </div>
                                                            <?php if ($pannello['descrizione']): ?>
                                                                <small class="text-muted d-block mt-1">
                                                                    <?php echo htmlspecialchars($pannello['descrizione']); ?>
                                                                </small>
                                                            <?php endif; ?>
                                                            <button type="button" 
                                                                    class="btn btn-sm btn-link p-0 mt-1"
                                                                    onclick="showPanelGenes(<?php echo $pannello['id']; ?>)">
                                                                <i class="bi bi-eye"></i> Vedi geni inclusi
                                                            </button>
                                                        </div>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                    
                                    <!-- Geni Singoli -->
                                    <div class="tab-pane fade" id="geni_singoli">
                                        <div class="mb-3">
                                            <input type="text" class="form-control" id="searchGenes" 
                                                   placeholder="Cerca gene...">
                                        </div>
                                        
                                        <div style="max-height: 400px; overflow-y: auto;">
                                            <?php
                                            $currentGroup = '';
                                            foreach ($geni as $gene):
                                                if ($gene['gruppo_nome'] != $currentGroup):
                                                    if ($currentGroup != '') echo '</div>';
                                                    $currentGroup = $gene['gruppo_nome'];
                                            ?>
                                                <div class="gene-group mb-3">
                                                    <h6 class="text-muted">
                                                        <?php echo htmlspecialchars($currentGroup ?: 'Altri Geni'); ?>
                                                    </h6>
                                            <?php endif; ?>
                                                
                                                <div class="form-check gene-item" 
                                                     data-gene-name="<?php echo htmlspecialchars(strtolower($gene['nome'] . ' ' . $gene['sigla'])); ?>">
                                                    <input class="form-check-input gene-check" 
                                                           type="checkbox" 
                                                           name="geni[]" 
                                                           value="<?php echo $gene['id']; ?>"
                                                           id="gene_<?php echo $gene['id']; ?>"
                                                           data-prezzo="<?php echo $gene['prezzo']; ?>"
                                                           data-nome="<?php echo htmlspecialchars($gene['nome']); ?>">
                                                    <label class="form-check-label" 
                                                           for="gene_<?php echo $gene['id']; ?>">
                                                        <?php if ($gene['sigla']): ?>
                                                            <strong><?php echo htmlspecialchars($gene['sigla']); ?></strong> -
                                                        <?php endif; ?>
                                                        <?php echo htmlspecialchars($gene['nome']); ?>
                                                        <span class="badge bg-success">
                                                            € <?php echo number_format($gene['prezzo'], 2, ',', '.'); ?>
                                                        </span>
                                                    </label>
                                                </div>
                                                
                                            <?php endforeach; ?>
                                            <?php if ($currentGroup != '') echo '</div>'; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Dettagli Test Microbiota -->
                    <div class="test-details" id="details_microbiota" style="display: none;">
                        <div class="card mb-3">
                            <div class="card-header">
                                <h5 class="mb-0">Selezione Tipo Microbiota</h5>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <?php foreach ($tipiMicrobiota as $tipo): ?>
                                        <div class="col-md-4 mb-3">
                                            <div class="form-check">
                                                <input class="form-check-input microbiota-check" 
                                                       type="checkbox" 
                                                       name="tipi_microbiota[]" 
                                                       value="<?php echo $tipo['id']; ?>"
                                                       id="microbiota_<?php echo $tipo['id']; ?>"
                                                       data-prezzo="<?php echo $tipo['prezzo']; ?>"
                                                       data-nome="<?php echo htmlspecialchars($tipo['nome']); ?>">
                                                <label class="form-check-label" 
                                                       for="microbiota_<?php echo $tipo['id']; ?>">
                                                    <?php echo htmlspecialchars($tipo['nome']); ?>
                                                    <span class="badge bg-success">
                                                        € <?php echo number_format($tipo['prezzo'], 2, ',', '.'); ?>
                                                    </span>
                                                </label>
                                                <?php if ($tipo['descrizione']): ?>
                                                    <small class="text-muted d-block">
                                                        <?php echo htmlspecialchars($tipo['descrizione']); ?>
                                                    </small>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Dettagli Test Intolleranze Citotossico -->
                    <div class="test-details" id="details_intolleranze_cito" style="display: none;">
                        <div class="card mb-3">
                            <div class="card-header">
                                <h5 class="mb-0">Selezione Pannello Intolleranze Citotossico</h5>
                            </div>
                            <div class="card-body">
                                <?php foreach ($pannelliCito as $pannello): ?>
                                    <div class="form-check mb-3">
                                        <input class="form-check-input intolleranze-check" 
                                               type="radio" 
                                               name="pannello_intolleranze" 
                                               value="<?php echo $pannello['id']; ?>"
                                               id="cito_<?php echo $pannello['id']; ?>"
                                               data-prezzo="<?php echo $pannello['prezzo']; ?>"
                                               data-nome="<?php echo htmlspecialchars($pannello['nome']); ?>">
                                        <label class="form-check-label" 
                                               for="cito_<?php echo $pannello['id']; ?>">
                                            <strong><?php echo htmlspecialchars($pannello['nome']); ?></strong>
                                            <span class="badge bg-info">
                                                <?php echo $pannello['num_alimenti']; ?> alimenti
                                            </span>
                                            <span class="badge bg-success">
                                                € <?php echo number_format($pannello['prezzo'], 2, ',', '.'); ?>
                                            </span>
                                        </label>
                                        <?php if ($pannello['descrizione']): ?>
                                            <small class="text-muted d-block">
                                                <?php echo htmlspecialchars($pannello['descrizione']); ?>
                                            </small>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Dettagli Test Intolleranze ELISA -->
                    <div class="test-details" id="details_intolleranze_elisa" style="display: none;">
                        <div class="card mb-3">
                            <div class="card-header">
                                <h5 class="mb-0">Selezione Pannello Intolleranze ELISA</h5>
                            </div>
                            <div class="card-body">
                                <?php foreach ($pannelliElisa as $pannello): ?>
                                    <div class="form-check mb-3">
                                        <input class="form-check-input intolleranze-check" 
                                               type="radio" 
                                               name="pannello_intolleranze" 
                                               value="<?php echo $pannello['id']; ?>"
                                               id="elisa_<?php echo $pannello['id']; ?>"
                                               data-prezzo="<?php echo $pannello['prezzo']; ?>"
                                               data-nome="<?php echo htmlspecialchars($pannello['nome']); ?>">
                                        <label class="form-check-label" 
                                               for="elisa_<?php echo $pannello['id']; ?>">
                                            <strong><?php echo htmlspecialchars($pannello['nome']); ?></strong>
                                            <span class="badge bg-info">
                                                <?php echo $pannello['num_alimenti']; ?> alimenti
                                            </span>
                                            <span class="badge bg-success">
                                                € <?php echo number_format($pannello['prezzo'], 2, ',', '.'); ?>
                                            </span>
                                        </label>
                                        <?php if ($pannello['descrizione']): ?>
                                            <small class="text-muted d-block">
                                                <?php echo htmlspecialchars($pannello['descrizione']); ?>
                                            </small>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Riepilogo e Note -->
                    <div class="row">
                        <div class="col-md-8">
                            <div class="card mb-3">
                                <div class="card-header">
                                    <h5 class="mb-0">Note Aggiuntive</h5>
                                </div>
                                <div class="card-body">
                                    <textarea class="form-control" name="note" rows="3" 
                                              placeholder="Inserisci eventuali note o richieste specifiche..."></textarea>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-md-4">
                            <div class="card mb-3 border-primary">
                                <div class="card-header bg-primary text-white">
                                    <h5 class="mb-0">Riepilogo Ordine</h5>
                                </div>
                                <div class="card-body">
                                    <div id="orderSummary">
                                        <p class="text-muted">Nessuna analisi selezionata</p>
                                    </div>
                                    
                                    <hr>
                                    
                                    <div class="mb-3">
                                        <label for="sconto" class="form-label">Sconto %</label>
                                        <input type="number" class="form-control" id="sconto" name="sconto" 
                                               min="0" max="100" step="0.01" value="0"
                                               onchange="updatePriceSummary()">
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label for="iva" class="form-label">IVA %</label>
                                        <input type="number" class="form-control" id="iva" name="iva" 
                                               value="22" readonly>
                                    </div>
                                    
                                    <hr>
                                    
                                    <div class="d-flex justify-content-between">
                                        <strong>Totale:</strong>
                                        <strong id="totalPrice">€ 0,00</strong>
                                    </div>
                                    <div class="d-flex justify-content-between text-muted">
                                        <small>Totale con IVA:</small>
                                        <small id="totalPriceIva">€ 0,00</small>
                                    </div>
                                </div>
                            </div>
                            
                            <button type="submit" class="btn btn-primary w-100" id="submitBtn" disabled>
                                <i class="bi bi-check-circle"></i> Conferma Richiesta
                            </button>
                        </div>
                    </div>
                </form>
                
            <?php endif; ?>
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

<!-- Modal Geni Pannello -->
<div class="modal fade" id="panelGenesModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="panelGenesTitle">Geni inclusi nel pannello</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="panelGenesContent">
                <!-- Contenuto caricato via AJAX -->
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Chiudi</button>
            </div>
        </div>
    </div>
</div>

<script>
// Gestione cambio tipo test
document.querySelectorAll('input[name="tipo_test"]').forEach(radio => {
    radio.addEventListener('change', function() {
        // Nascondi tutti i dettagli
        document.querySelectorAll('.test-details').forEach(detail => {
            detail.style.display = 'none';
        });
        
        // Mostra dettagli selezionati
        document.getElementById('details_' + this.value).style.display = '';
        
        // Reset selezioni
        resetSelections();
        updatePriceSummary();
    });
});

// Ricerca geni
document.getElementById('searchGenes')?.addEventListener('keyup', function() {
    const searchText = this.value.toLowerCase();
    document.querySelectorAll('.gene-item').forEach(item => {
        const geneName = item.dataset.geneName;
        item.style.display = geneName.includes(searchText) ? '' : 'none';
    });
});

// Aggiorna riepilogo quando cambiano le selezioni
document.querySelectorAll('.pannello-check, .gene-check, .microbiota-check, .intolleranze-check').forEach(check => {
    check.addEventListener('change', updatePriceSummary);
});

// Funzione per aggiornare riepilogo prezzi
function updatePriceSummary() {
    const selectedItems = [];
    let totalPrice = 0;
    
    // Raccolta elementi selezionati
    document.querySelectorAll('input[type="checkbox"]:checked, input[type="radio"]:checked').forEach(input => {
        if (input.dataset.prezzo && input.dataset.nome) {
            const price = parseFloat(input.dataset.prezzo);
            selectedItems.push({
                nome: input.dataset.nome,
                prezzo: price
            });
            totalPrice += price;
        }
    });
    
    // Aggiorna riepilogo
    const summaryDiv = document.getElementById('orderSummary');
    if (selectedItems.length === 0) {
        summaryDiv.innerHTML = '<p class="text-muted">Nessuna analisi selezionata</p>';
        document.getElementById('submitBtn').disabled = true;
    } else {
        let html = '<ul class="list-unstyled mb-0">';
        selectedItems.forEach(item => {
            html += `<li class="d-flex justify-content-between">
                        <span>${item.nome}</span>
                        <span>€ ${item.prezzo.toFixed(2).replace('.', ',')}</span>
                     </li>`;
        });
        html += '</ul>';
        summaryDiv.innerHTML = html;
        document.getElementById('submitBtn').disabled = false;
    }
    
    // Calcola sconto
    const sconto = parseFloat(document.getElementById('sconto').value) || 0;
    const prezzoScontato = totalPrice - (totalPrice * sconto / 100);
    
    // Calcola IVA
    const iva = parseFloat(document.getElementById('iva').value) || 22;
    const prezzoConIva = prezzoScontato + (prezzoScontato * iva / 100);
    
    // Aggiorna totali
    document.getElementById('totalPrice').textContent = '€ ' + prezzoScontato.toFixed(2).replace('.', ',');
    document.getElementById('totalPriceIva').textContent = '€ ' + prezzoConIva.toFixed(2).replace('.', ',');
}

// Reset selezioni
function resetSelections() {
    document.querySelectorAll('input[type="checkbox"]:checked, input[type="radio"]:checked').forEach(input => {
        if (input.name !== 'tipo_test') {
            input.checked = false;
        }
    });
}

// Visualizza dettagli test
function viewTestDetails(testId) {
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
                                <tr><th>Codice:</th><td>${test.codice}</td></tr>
                                <tr><th>Tipo:</th><td>${test.tipo_test}</td></tr>
                                <tr><th>Data Richiesta:</th><td>${new Date(test.data_richiesta).toLocaleString('it-IT')}</td></tr>
                                <tr><th>Stato:</th><td>${test.stato}</td></tr>
                                <tr><th>Prezzo Finale:</th><td>€ ${test.prezzo_finale}</td></tr>
                            </table>
                        </div>
                        <div class="col-md-6">
                            <h6>Analisi Richieste</h6>
                            <ul>`;
                
                if (test.dettagli) {
                    test.dettagli.forEach(item => {
                        html += `<li>${item.nome} (€ ${item.prezzo_unitario})</li>`;
                    });
                }
                
                html += `</ul>
                            ${test.note ? `<h6>Note:</h6><p>${test.note}</p>` : ''}
                        </div>
                    </div>`;
                
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

// Visualizza geni pannello
function showPanelGenes(panelId) {
    fetch(`../api/v1/panels.php?id=${panelId}&details=true`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const panel = data.data;
                document.getElementById('panelGenesTitle').textContent = 'Geni inclusi in ' + panel.nome;
                
                let html = '<ul class="list-group">';
                panel.geni.forEach(gene => {
                    html += `<li class="list-group-item">
                                ${gene.sigla ? `<strong>${gene.sigla}</strong> - ` : ''}
                                ${gene.nome}
                                <span class="badge bg-secondary float-end">€ ${gene.prezzo}</span>
                             </li>`;
                });
                html += '</ul>';
                
                document.getElementById('panelGenesContent').innerHTML = html;
                const modal = new bootstrap.Modal(document.getElementById('panelGenesModal'));
                modal.show();
            }
        });
}

// Stampa barcode
function printBarcode(barcodePath) {
    window.open(barcodePath, '_blank');
}

// Annulla test
function cancelTest(testId) {
    if (confirm('Sei sicuro di voler annullare questo test? L\'operazione non può essere annullata.')) {
        // TODO: Implementare chiamata API per annullamento
        alert('Funzione in sviluppo');
    }
}

// Validazione form nuovo test
document.getElementById('newTestForm')?.addEventListener('submit', function(e) {
    const hasSelection = document.querySelectorAll('input[type="checkbox"]:checked, input[type="radio"][name="pannello_intolleranze"]:checked').length > 0;
    
    if (!hasSelection) {
        e.preventDefault();
        alert('Seleziona almeno un\'analisi');
        return false;
    }
});
</script>

<?php require_once '../templates/footer.php'; ?>