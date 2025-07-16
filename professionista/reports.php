<?php
/**
 * Visualizzazione Referti - Area Professionista
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

$testId = intval($_GET['test_id'] ?? 0);
$action = $_GET['action'] ?? 'list';

// Se richiesto download diretto
if ($action === 'download' && $testId) {
    $referto = $db->selectOne("
        SELECT r.*, t.professionista_id
        FROM referti r
        INNER JOIN test t ON r.test_id = t.id
        WHERE r.test_id = :test_id AND t.professionista_id = :prof_id
    ", ['test_id' => $testId, 'prof_id' => $professionista['id']]);
    
    if ($referto) {
        $filePath = $referto['file_path_firmato'] ?: $referto['file_path'];
        if ($filePath && file_exists('../' . $filePath)) {
            header('Content-Type: application/pdf');
            header('Content-Disposition: attachment; filename="referto_' . $testId . '.pdf"');
            header('Content-Length: ' . filesize('../' . $filePath));
            readfile('../' . $filePath);
            
            // Log download
            $logger->log($user->getId(), 'referto_scaricato', "Test ID: {$testId}");
            exit;
        }
    }
    
    $session->setFlash('error', 'Referto non trovato');
    header('Location: reports.php');
    exit;
}

// Carica lista referti
$filter = $_GET['filter'] ?? 'all';
$search = $_GET['search'] ?? '';
$patientId = intval($_GET['patient_id'] ?? 0);
$page = intval($_GET['page'] ?? 1);
$limit = 20;
$offset = ($page - 1) * $limit;

// Query referti
$query = "
    SELECT r.*, 
           t.codice as test_codice,
           t.tipo_test,
           t.data_richiesta,
           t.stato as test_stato,
           p.nome as paziente_nome,
           p.cognome as paziente_cognome,
           p.codice_fiscale,
           p.data_nascita,
           u.nome as biologo_nome,
           u.cognome as biologo_cognome
    FROM referti r
    INNER JOIN test t ON r.test_id = t.id
    INNER JOIN pazienti p ON t.paziente_id = p.id
    INNER JOIN utenti u ON r.biologo_id = u.id
    WHERE t.professionista_id = :prof_id
";

$params = ['prof_id' => $professionista['id']];

// Filtri
if ($patientId) {
    $query .= " AND p.id = :patient_id";
    $params['patient_id'] = $patientId;
}

switch ($filter) {
    case 'signed':
        $query .= " AND r.file_path_firmato IS NOT NULL";
        break;
    case 'unsigned':
        $query .= " AND r.file_path_firmato IS NULL";
        break;
    case 'recent':
        $query .= " AND r.data_creazione >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
        break;
}

if ($search) {
    $query .= " AND (t.codice LIKE :search 
                     OR p.nome LIKE :search 
                     OR p.cognome LIKE :search
                     OR p.codice_fiscale LIKE :search)";
    $params['search'] = "%{$search}%";
}

$query .= " ORDER BY r.data_creazione DESC LIMIT :limit OFFSET :offset";
$params['limit'] = $limit;
$params['offset'] = $offset;

$referti = $db->select($query, $params);

// Conta totale per paginazione
$countQuery = "
    SELECT COUNT(*) as total 
    FROM referti r
    INNER JOIN test t ON r.test_id = t.id
    WHERE t.professionista_id = :prof_id
";
$countParams = ['prof_id' => $professionista['id']];

if ($patientId) {
    $countQuery .= " AND t.paziente_id = :patient_id";
    $countParams['patient_id'] = $patientId;
}

$totalReferti = $db->selectOne($countQuery, $countParams)['total'];
$totalPages = ceil($totalReferti / $limit);

// Carica dati paziente se filtrato
$patient = null;
if ($patientId) {
    $patient = $db->selectOne(
        "SELECT * FROM pazienti WHERE id = :id AND professionista_id = :prof_id",
        ['id' => $patientId, 'prof_id' => $professionista['id']]
    );
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
                <h1 class="h2">
                    <?php if ($patient): ?>
                        Referti di <?php echo htmlspecialchars($patient['nome'] . ' ' . $patient['cognome']); ?>
                    <?php else: ?>
                        Referti Disponibili
                    <?php endif; ?>
                </h1>
                <div class="btn-toolbar mb-2 mb-md-0">
                    <?php if ($patientId): ?>
                        <a href="reports.php" class="btn btn-sm btn-secondary">
                            <i class="bi bi-arrow-left"></i> Tutti i referti
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
            
            <!-- Statistiche -->
            <div class="row mb-4">
                <div class="col-md-3">
                    <div class="card">
                        <div class="card-body">
                            <h5 class="card-title"><?php echo $totalReferti; ?></h5>
                            <p class="card-text text-muted">Referti Totali</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card">
                        <div class="card-body">
                            <h5 class="card-title">
                                <?php 
                                $firmati = $db->selectOne("
                                    SELECT COUNT(*) as count
                                    FROM referti r
                                    INNER JOIN test t ON r.test_id = t.id
                                    WHERE t.professionista_id = :prof_id
                                    AND r.file_path_firmato IS NOT NULL
                                ", ['prof_id' => $professionista['id']])['count'];
                                echo $firmati;
                                ?>
                            </h5>
                            <p class="card-text text-muted">Firmati Digitalmente</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card">
                        <div class="card-body">
                            <h5 class="card-title">
                                <?php 
                                $ultimi30 = $db->selectOne("
                                    SELECT COUNT(*) as count
                                    FROM referti r
                                    INNER JOIN test t ON r.test_id = t.id
                                    WHERE t.professionista_id = :prof_id
                                    AND r.data_creazione >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                                ", ['prof_id' => $professionista['id']])['count'];
                                echo $ultimi30;
                                ?>
                            </h5>
                            <p class="card-text text-muted">Ultimi 30 giorni</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card">
                        <div class="card-body">
                            <h5 class="card-title">
                                <?php 
                                $inScadenza = $db->selectOne("
                                    SELECT COUNT(*) as count
                                    FROM referti r
                                    INNER JOIN test t ON r.test_id = t.id
                                    WHERE t.professionista_id = :prof_id
                                    AND r.data_creazione >= DATE_SUB(NOW(), INTERVAL 40 DAY)
                                    AND r.data_creazione <= DATE_SUB(NOW(), INTERVAL 30 DAY)
                                ", ['prof_id' => $professionista['id']])['count'];
                                echo $inScadenza;
                                ?>
                            </h5>
                            <p class="card-text text-muted">In scadenza (40+ giorni)</p>
                        </div>
                    </div>
                </div>
            </div>
            
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
                                    Tutti i referti
                                </option>
                                <option value="signed" <?php echo $filter === 'signed' ? 'selected' : ''; ?>>
                                    Solo firmati
                                </option>
                                <option value="unsigned" <?php echo $filter === 'unsigned' ? 'selected' : ''; ?>>
                                    Non firmati
                                </option>
                                <option value="recent" <?php echo $filter === 'recent' ? 'selected' : ''; ?>>
                                    Ultimi 30 giorni
                                </option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <input type="text" class="form-control" name="search" 
                                   placeholder="Cerca per codice test, paziente o CF..." 
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
            
            <!-- Info importante -->
            <div class="alert alert-info">
                <i class="bi bi-info-circle"></i>
                <strong>Importante:</strong> I referti sono disponibili per il download per 45 giorni dalla data di emissione. 
                I pazienti possono scaricarli autonomamente dal portale usando il codice test e il proprio codice fiscale.
            </div>
            
            <!-- Tabella referti -->
            <div class="card">
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>Codice Test</th>
                                    <th>Data</th>
                                    <th>Paziente</th>
                                    <th>Tipo</th>
                                    <th>Biologo</th>
                                    <th>Stato</th>
                                    <th>Disponibilità</th>
                                    <th>Azioni</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($referti as $referto): ?>
                                    <?php
                                    // Calcola giorni rimanenti
                                    $dataCreazione = new DateTime($referto['data_creazione']);
                                    $oggi = new DateTime();
                                    $giorniPassati = $oggi->diff($dataCreazione)->days;
                                    $giorniRimanenti = 45 - $giorniPassati;
                                    $inScadenza = $giorniRimanenti <= 10 && $giorniRimanenti > 0;
                                    $scaduto = $giorniRimanenti <= 0;
                                    ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo htmlspecialchars($referto['test_codice']); ?></strong>
                                        </td>
                                        <td>
                                            <?php echo date('d/m/Y', strtotime($referto['data_creazione'])); ?>
                                            <br>
                                            <small class="text-muted">
                                                <?php echo date('H:i', strtotime($referto['data_creazione'])); ?>
                                            </small>
                                        </td>
                                        <td>
                                            <a href="reports.php?patient_id=<?php echo $referto['paziente_id'] ?? 0; ?>">
                                                <?php echo htmlspecialchars($referto['paziente_cognome'] . ' ' . $referto['paziente_nome']); ?>
                                            </a>
                                            <br>
                                            <small class="text-muted">
                                                CF: <?php echo htmlspecialchars($referto['codice_fiscale']); ?>
                                            </small>
                                        </td>
                                        <td>
                                            <?php
                                            $tipoLabel = [
                                                'genetico' => '<i class="bi bi-dna"></i> Genetico',
                                                'microbiota' => '<i class="bi bi-bug"></i> Microbiota',
                                                'intolleranze' => '<i class="bi bi-egg"></i> Intolleranze'
                                            ];
                                            echo $tipoLabel[$referto['tipo_referto']] ?? $referto['tipo_referto'];
                                            ?>
                                        </td>
                                        <td>
                                            Dr. <?php echo htmlspecialchars($referto['biologo_nome'] . ' ' . $referto['biologo_cognome']); ?>
                                            <?php if ($referto['data_firma']): ?>
                                                <br>
                                                <small class="text-muted">
                                                    Firmato: <?php echo date('d/m/Y', strtotime($referto['data_firma'])); ?>
                                                </small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($referto['file_path_firmato']): ?>
                                                <span class="badge bg-success">
                                                    <i class="bi bi-check-circle"></i> Firmato
                                                </span>
                                            <?php else: ?>
                                                <span class="badge bg-warning">
                                                    <i class="bi bi-clock"></i> Non firmato
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($scaduto): ?>
                                                <span class="badge bg-danger">Scaduto</span>
                                            <?php elseif ($inScadenza): ?>
                                                <span class="badge bg-warning">
                                                    <?php echo $giorniRimanenti; ?> giorni
                                                </span>
                                            <?php else: ?>
                                                <span class="badge bg-success">
                                                    <?php echo $giorniRimanenti; ?> giorni
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="btn-group btn-group-sm" role="group">
                                                <a href="reports.php?action=download&test_id=<?php echo $referto['test_id']; ?>" 
                                                   class="btn btn-primary" title="Scarica referto">
                                                    <i class="bi bi-download"></i>
                                                </a>
                                                <button type="button" class="btn btn-info" 
                                                        onclick="showReportInfo(<?php echo $referto['test_id']; ?>)"
                                                        title="Info per paziente">
                                                    <i class="bi bi-info-circle"></i>
                                                </button>
                                                <button type="button" class="btn btn-secondary" 
                                                        onclick="sendReportEmail(<?php echo $referto['test_id']; ?>)"
                                                        title="Invia per email">
                                                    <i class="bi bi-envelope"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                
                                <?php if (empty($referti)): ?>
                                    <tr>
                                        <td colspan="8" class="text-center text-muted py-4">
                                            Nessun referto trovato
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
                                <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                                    <a class="page-link" href="?page=<?php echo $page - 1; ?>&filter=<?php echo $filter; ?>&search=<?php echo urlencode($search); ?>">
                                        Precedente
                                    </a>
                                </li>
                                
                                <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                                    <?php if ($i == 1 || $i == $totalPages || ($i >= $page - 2 && $i <= $page + 2)): ?>
                                        <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                                            <a class="page-link" href="?page=<?php echo $i; ?>&filter=<?php echo $filter; ?>&search=<?php echo urlencode($search); ?>">
                                                <?php echo $i; ?>
                                            </a>
                                        </li>
                                    <?php elseif ($i == $page - 3 || $i == $page + 3): ?>
                                        <li class="page-item disabled">
                                            <span class="page-link">...</span>
                                        </li>
                                    <?php endif; ?>
                                <?php endfor; ?>
                                
                                <li class="page-item <?php echo $page >= $totalPages ? 'disabled' : ''; ?>">
                                    <a class="page-link" href="?page=<?php echo $page + 1; ?>&filter=<?php echo $filter; ?>&search=<?php echo urlencode($search); ?>">
                                        Successiva
                                    </a>
                                </li>
                            </ul>
                        </nav>
                    <?php endif; ?>
                </div>
            </div>
        </main>
    </div>
</div>

<!-- Modal Info Referto -->
<div class="modal fade" id="reportInfoModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Informazioni per il Paziente</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="reportInfoContent">
                <!-- Contenuto dinamico -->
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Chiudi</button>
                <button type="button" class="btn btn-primary" onclick="copyToClipboard()">
                    <i class="bi bi-clipboard"></i> Copia
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Modal Invio Email -->
<div class="modal fade" id="sendEmailModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Invia Referto per Email</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="post" id="sendEmailForm">
                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                <input type="hidden" name="action" value="send_email">
                <input type="hidden" name="test_id" id="email_test_id">
                
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="email_to" class="form-label">Email destinatario</label>
                        <input type="email" class="form-control" id="email_to" name="email_to" required>
                    </div>
                    
                    <div class="mb-3">
                        <label for="email_message" class="form-label">Messaggio aggiuntivo (opzionale)</label>
                        <textarea class="form-control" id="email_message" name="email_message" rows="3"></textarea>
                    </div>
                    
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle"></i>
                        Verrà inviata un'email con le istruzioni per scaricare il referto dal portale.
                        Il referto NON sarà allegato all'email per motivi di sicurezza.
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annulla</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-send"></i> Invia
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// Mostra info referto per paziente
function showReportInfo(testId) {
    fetch(`../api/v1/tests.php?id=${testId}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const test = data.data;
                const info = `
                    <div class="alert alert-info">
                        <h6>Istruzioni per il download del referto:</h6>
                        <ol>
                            <li>Accedere al sito: <strong><?php echo SITE_URL; ?>/public/download.php</strong></li>
                            <li>Inserire il codice test: <strong>${test.codice}</strong></li>
                            <li>Inserire il proprio codice fiscale in MAIUSCOLO</li>
                            <li>Il referto sarà scaricato in formato PDF</li>
                        </ol>
                        <p class="mb-0 mt-3">
                            <strong>Password PDF:</strong> Il codice fiscale in MAIUSCOLO<br>
                            <strong>Disponibile fino al:</strong> ${new Date(new Date(test.data_refertazione).getTime() + 45*24*60*60*1000).toLocaleDateString('it-IT')}
                        </p>
                    </div>
                    <div class="text-center mt-3">
                        <strong>Codice Test: ${test.codice}</strong>
                    </div>
                `;
                
                document.getElementById('reportInfoContent').innerHTML = info;
                window.currentReportInfo = info; // Per la copia
                
                const modal = new bootstrap.Modal(document.getElementById('reportInfoModal'));
                modal.show();
            }
        });
}

// Copia info negli appunti
function copyToClipboard() {
    const content = document.getElementById('reportInfoContent').innerText;
    navigator.clipboard.writeText(content).then(() => {
        alert('Informazioni copiate negli appunti');
    });
}

// Invia email
function sendReportEmail(testId) {
    document.getElementById('email_test_id').value = testId;
    
    // Carica email paziente se disponibile
    fetch(`../api/v1/tests.php?id=${testId}`)
        .then(response => response.json())
        .then(data => {
            if (data.success && data.data.paziente_email) {
                document.getElementById('email_to').value = data.data.paziente_email;
            }
        });
    
    const modal = new bootstrap.Modal(document.getElementById('sendEmailModal'));
    modal.show();
}

// Gestione invio email
document.getElementById('sendEmailForm')?.addEventListener('submit', function(e) {
    e.preventDefault();
    
    // TODO: Implementare invio email via API
    alert('Funzione in sviluppo. L\'invio email verrà implementato con il sistema di notifiche.');
    
    bootstrap.Modal.getInstance(document.getElementById('sendEmailModal')).hide();
});
</script>

<?php require_once '../templates/footer.php'; ?>
