<?php
/**
 * Gestione Pazienti - Area Professionista
 * SerteX+ Genetic Lab Portal
 */

require_once '../includes/auth.php';
require_once '../includes/database.php';
require_once '../includes/session.php';
require_once '../includes/functions.php';
require_once '../classes/Patient.php';
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
$patientId = intval($_GET['id'] ?? 0);
$message = '';
$messageType = '';

// Gestione azioni POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? '';
    
    if (!$session->validateCsrfToken($csrfToken)) {
        $message = 'Token di sicurezza non valido';
        $messageType = 'error';
    } else {
        switch ($_POST['action'] ?? '') {
            case 'create':
                $patientData = [
                    'professionista_id' => $professionista['id'],
                    'nome' => sanitizeInput($_POST['nome']),
                    'cognome' => sanitizeInput($_POST['cognome']),
                    'codice_fiscale' => strtoupper(trim($_POST['codice_fiscale'])),
                    'data_nascita' => $_POST['data_nascita'] ?: null,
                    'sesso' => $_POST['sesso'] ?: null,
                    'email' => sanitizeInput($_POST['email'] ?? ''),
                    'telefono' => sanitizeInput($_POST['telefono'] ?? ''),
                    'indirizzo' => sanitizeInput($_POST['indirizzo'] ?? '')
                ];
                
                // Validazione CF
                if (!preg_match('/^[A-Z0-9]{16}$/', $patientData['codice_fiscale'])) {
                    $message = 'Codice fiscale non valido';
                    $messageType = 'error';
                } else {
                    // Verifica unicità CF per questo professionista
                    $existing = $db->count('pazienti', [
                        'codice_fiscale' => $patientData['codice_fiscale'],
                        'professionista_id' => $professionista['id']
                    ]);
                    
                    if ($existing > 0) {
                        $message = 'Paziente con questo codice fiscale già presente';
                        $messageType = 'error';
                    } else {
                        try {
                            $patientId = $db->insert('pazienti', $patientData);
                            $logger->log($user->getId(), 'paziente_creato', 
                                       "Creato paziente: {$patientData['nome']} {$patientData['cognome']}");
                            $session->setFlash('success', 'Paziente creato con successo');
                            header('Location: patients.php');
                            exit;
                        } catch (Exception $e) {
                            $message = 'Errore nella creazione del paziente';
                            $messageType = 'error';
                        }
                    }
                }
                break;
                
            case 'update':
                $patientId = intval($_POST['patient_id']);
                
                // Verifica che il paziente appartenga al professionista
                $patient = $db->selectOne(
                    "SELECT * FROM pazienti WHERE id = :id AND professionista_id = :prof_id",
                    ['id' => $patientId, 'prof_id' => $professionista['id']]
                );
                
                if (!$patient) {
                    $message = 'Paziente non trovato';
                    $messageType = 'error';
                } else {
                    $updateData = [
                        'nome' => sanitizeInput($_POST['nome']),
                        'cognome' => sanitizeInput($_POST['cognome']),
                        'data_nascita' => $_POST['data_nascita'] ?: null,
                        'sesso' => $_POST['sesso'] ?: null,
                        'email' => sanitizeInput($_POST['email'] ?? ''),
                        'telefono' => sanitizeInput($_POST['telefono'] ?? ''),
                        'indirizzo' => sanitizeInput($_POST['indirizzo'] ?? '')
                    ];
                    
                    try {
                        $db->update('pazienti', $updateData, ['id' => $patientId]);
                        $logger->log($user->getId(), 'paziente_modificato', "Modificato paziente ID: {$patientId}");
                        $session->setFlash('success', 'Paziente modificato con successo');
                        header('Location: patients.php');
                        exit;
                    } catch (Exception $e) {
                        $message = 'Errore nella modifica del paziente';
                        $messageType = 'error';
                    }
                }
                break;
                
            case 'delete':
                $patientId = intval($_POST['patient_id']);
                
                // Verifica che il paziente appartenga al professionista
                $patient = $db->selectOne(
                    "SELECT * FROM pazienti WHERE id = :id AND professionista_id = :prof_id",
                    ['id' => $patientId, 'prof_id' => $professionista['id']]
                );
                
                if (!$patient) {
                    $message = 'Paziente non trovato';
                    $messageType = 'error';
                } else {
                    // Verifica che non ci siano test associati
                    $testCount = $db->count('test', ['paziente_id' => $patientId]);
                    
                    if ($testCount > 0) {
                        $message = 'Impossibile eliminare il paziente perché ha test associati';
                        $messageType = 'error';
                    } else {
                        try {
                            $db->delete('pazienti', ['id' => $patientId]);
                            $logger->log($user->getId(), 'paziente_eliminato', 
                                       "Eliminato paziente: {$patient['nome']} {$patient['cognome']}");
                            $session->setFlash('success', 'Paziente eliminato con successo');
                            header('Location: patients.php');
                            exit;
                        } catch (Exception $e) {
                            $message = 'Errore nell\'eliminazione del paziente';
                            $messageType = 'error';
                        }
                    }
                }
                break;
        }
    }
}

// Carica dati per la vista
$search = $_GET['search'] ?? '';
$page = intval($_GET['page'] ?? 1);
$limit = 20;
$offset = ($page - 1) * $limit;

// Query pazienti
$query = "
    SELECT p.*, 
           COUNT(DISTINCT t.id) as num_test,
           MAX(t.data_richiesta) as ultimo_test,
           SUM(CASE WHEN t.stato IN ('richiesto', 'in_lavorazione') THEN 1 ELSE 0 END) as test_in_corso
    FROM pazienti p
    LEFT JOIN test t ON p.id = t.paziente_id
    WHERE p.professionista_id = :prof_id
";

$params = ['prof_id' => $professionista['id']];

if ($search) {
    $query .= " AND (p.nome LIKE :search 
                     OR p.cognome LIKE :search 
                     OR p.codice_fiscale LIKE :search
                     OR p.email LIKE :search
                     OR p.telefono LIKE :search)";
    $params['search'] = "%{$search}%";
}

$query .= " GROUP BY p.id ORDER BY p.cognome, p.nome LIMIT :limit OFFSET :offset";
$params['limit'] = $limit;
$params['offset'] = $offset;

$patients = $db->select($query, $params);

// Conta totale per paginazione
$countQuery = "SELECT COUNT(*) as total FROM pazienti WHERE professionista_id = :prof_id";
$countParams = ['prof_id' => $professionista['id']];
if ($search) {
    $countQuery .= " AND (nome LIKE :search OR cognome LIKE :search OR codice_fiscale LIKE :search 
                          OR email LIKE :search OR telefono LIKE :search)";
    $countParams['search'] = "%{$search}%";
}
$totalPatients = $db->selectOne($countQuery, $countParams)['total'];
$totalPages = ceil($totalPatients / $limit);

// Carica dati per form modifica
$editPatient = null;
if ($action === 'edit' && $patientId) {
    $editPatient = $db->selectOne(
        "SELECT * FROM pazienti WHERE id = :id AND professionista_id = :prof_id",
        ['id' => $patientId, 'prof_id' => $professionista['id']]
    );
    
    if (!$editPatient) {
        header('Location: patients.php');
        exit;
    }
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
                <h1 class="h2">Gestione Pazienti</h1>
                <div class="btn-toolbar mb-2 mb-md-0">
                    <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#patientModal">
                        <i class="bi bi-person-plus"></i> Nuovo Paziente
                    </button>
                </div>
            </div>
            
            <?php if ($message): ?>
                <div class="alert alert-<?php echo $messageType === 'error' ? 'danger' : $messageType; ?> alert-dismissible fade show" role="alert">
                    <?php echo htmlspecialchars($message); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
            
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
                            <h5 class="card-title"><?php echo $totalPatients; ?></h5>
                            <p class="card-text text-muted">Pazienti Totali</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card">
                        <div class="card-body">
                            <h5 class="card-title">
                                <?php 
                                $activePatients = $db->selectOne("
                                    SELECT COUNT(DISTINCT p.id) as count
                                    FROM pazienti p
                                    INNER JOIN test t ON p.id = t.paziente_id
                                    WHERE p.professionista_id = :prof_id
                                    AND t.data_richiesta >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
                                ", ['prof_id' => $professionista['id']])['count'];
                                echo $activePatients;
                                ?>
                            </h5>
                            <p class="card-text text-muted">Pazienti Attivi (6 mesi)</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card">
                        <div class="card-body">
                            <h5 class="card-title">
                                <?php 
                                $newPatients = $db->selectOne("
                                    SELECT COUNT(*) as count
                                    FROM pazienti
                                    WHERE professionista_id = :prof_id
                                    AND data_creazione >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                                ", ['prof_id' => $professionista['id']])['count'];
                                echo $newPatients;
                                ?>
                            </h5>
                            <p class="card-text text-muted">Nuovi (30 giorni)</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card">
                        <div class="card-body">
                            <h5 class="card-title">
                                <?php 
                                $testsInProgress = $db->selectOne("
                                    SELECT COUNT(*) as count
                                    FROM test t
                                    INNER JOIN pazienti p ON t.paziente_id = p.id
                                    WHERE p.professionista_id = :prof_id
                                    AND t.stato IN ('richiesto', 'in_lavorazione')
                                ", ['prof_id' => $professionista['id']])['count'];
                                echo $testsInProgress;
                                ?>
                            </h5>
                            <p class="card-text text-muted">Test in Corso</p>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Ricerca -->
            <div class="card mb-3">
                <div class="card-body">
                    <form method="get" class="row g-3">
                        <div class="col-md-9">
                            <input type="text" class="form-control" name="search" 
                                   placeholder="Cerca per nome, cognome, codice fiscale, email o telefono..." 
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
            
            <!-- Tabella pazienti -->
            <div class="card">
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>Nome e Cognome</th>
                                    <th>Codice Fiscale</th>
                                    <th>Data Nascita</th>
                                    <th>Contatti</th>
                                    <th>Test</th>
                                    <th>Ultimo Test</th>
                                    <th>Azioni</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($patients as $patient): ?>
                                    <?php
                                    // Calcola età
                                    $eta = '';
                                    if ($patient['data_nascita']) {
                                        $dataNascita = new DateTime($patient['data_nascita']);
                                        $oggi = new DateTime();
                                        $eta = $oggi->diff($dataNascita)->y . ' anni';
                                    }
                                    ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo htmlspecialchars($patient['cognome'] . ' ' . $patient['nome']); ?></strong>
                                            <?php if ($patient['sesso']): ?>
                                                <span class="badge bg-secondary"><?php echo $patient['sesso']; ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <code><?php echo htmlspecialchars($patient['codice_fiscale']); ?></code>
                                        </td>
                                        <td>
                                            <?php if ($patient['data_nascita']): ?>
                                                <?php echo date('d/m/Y', strtotime($patient['data_nascita'])); ?>
                                                <small class="text-muted">(<?php echo $eta; ?>)</small>
                                            <?php else: ?>
                                                -
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($patient['email']): ?>
                                                <i class="bi bi-envelope"></i> <?php echo htmlspecialchars($patient['email']); ?><br>
                                            <?php endif; ?>
                                            <?php if ($patient['telefono']): ?>
                                                <i class="bi bi-telephone"></i> <?php echo htmlspecialchars($patient['telefono']); ?>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="badge bg-primary"><?php echo $patient['num_test']; ?> totali</span>
                                            <?php if ($patient['test_in_corso'] > 0): ?>
                                                <span class="badge bg-warning"><?php echo $patient['test_in_corso']; ?> in corso</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($patient['ultimo_test']): ?>
                                                <?php echo date('d/m/Y', strtotime($patient['ultimo_test'])); ?>
                                            <?php else: ?>
                                                <span class="text-muted">Mai</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="btn-group btn-group-sm" role="group">
                                                <a href="tests.php?action=new&patient_id=<?php echo $patient['id']; ?>" 
                                                   class="btn btn-success" title="Nuovo test">
                                                    <i class="bi bi-plus-circle"></i>
                                                </a>
                                                <a href="tests.php?patient_id=<?php echo $patient['id']; ?>" 
                                                   class="btn btn-info" title="Visualizza test">
                                                    <i class="bi bi-list"></i>
                                                </a>
                                                <button type="button" class="btn btn-primary" 
                                                        onclick="editPatient(<?php echo $patient['id']; ?>)"
                                                        title="Modifica">
                                                    <i class="bi bi-pencil"></i>
                                                </button>
                                                <?php if ($patient['num_test'] == 0): ?>
                                                    <button type="button" class="btn btn-danger" 
                                                            onclick="deletePatient(<?php echo $patient['id']; ?>, '<?php echo htmlspecialchars($patient['nome'] . ' ' . $patient['cognome']); ?>')"
                                                            title="Elimina">
                                                        <i class="bi bi-trash"></i>
                                                    </button>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                
                                <?php if (empty($patients)): ?>
                                    <tr>
                                        <td colspan="7" class="text-center text-muted py-4">
                                            <?php if ($search): ?>
                                                Nessun paziente trovato con i criteri di ricerca
                                            <?php else: ?>
                                                Nessun paziente registrato. Clicca su "Nuovo Paziente" per iniziare.
                                            <?php endif; ?>
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
                                    <a class="page-link" href="?page=<?php echo $page - 1; ?>&search=<?php echo urlencode($search); ?>">
                                        Precedente
                                    </a>
                                </li>
                                
                                <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                                    <?php if ($i == 1 || $i == $totalPages || ($i >= $page - 2 && $i <= $page + 2)): ?>
                                        <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                                            <a class="page-link" href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>">
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
                                    <a class="page-link" href="?page=<?php echo $page + 1; ?>&search=<?php echo urlencode($search); ?>">
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

<!-- Modal Nuovo/Modifica Paziente -->
<div class="modal fade" id="patientModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="patientModalTitle">Nuovo Paziente</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="post" id="patientForm">
                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                <input type="hidden" name="action" value="create">
                <input type="hidden" name="patient_id" id="patient_id">
                
                <div class="modal-body">
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label for="nome" class="form-label">Nome *</label>
                            <input type="text" class="form-control" id="nome" name="nome" required maxlength="50">
                        </div>
                        <div class="col-md-6">
                            <label for="cognome" class="form-label">Cognome *</label>
                            <input type="text" class="form-control" id="cognome" name="cognome" required maxlength="50">
                        </div>
                    </div>
                    
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label for="codice_fiscale" class="form-label">Codice Fiscale *</label>
                            <input type="text" class="form-control text-uppercase" id="codice_fiscale" 
                                   name="codice_fiscale" required maxlength="16" pattern="[A-Z0-9]{16}"
                                   placeholder="RSSMRA85T10A562S">
                            <div class="form-text">16 caratteri alfanumerici</div>
                        </div>
                        <div class="col-md-3">
                            <label for="data_nascita" class="form-label">Data di Nascita</label>
                            <input type="date" class="form-control" id="data_nascita" name="data_nascita" 
                                   max="<?php echo date('Y-m-d'); ?>">
                        </div>
                        <div class="col-md-3">
                            <label for="sesso" class="form-label">Sesso</label>
                            <select class="form-select" id="sesso" name="sesso">
                                <option value="">-</option>
                                <option value="M">Maschio</option>
                                <option value="F">Femmina</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label for="email" class="form-label">Email</label>
                            <input type="email" class="form-control" id="email" name="email" maxlength="100">
                        </div>
                        <div class="col-md-6">
                            <label for="telefono" class="form-label">Telefono</label>
                            <input type="tel" class="form-control" id="telefono" name="telefono" maxlength="20">
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label for="indirizzo" class="form-label">Indirizzo</label>
                        <textarea class="form-control" id="indirizzo" name="indirizzo" rows="2"></textarea>
                    </div>
                    
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle"></i>
                        I dati inseriti sono protetti secondo la normativa GDPR. 
                        Il codice fiscale verrà utilizzato come password per l'accesso ai referti.
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annulla</button>
                    <button type="submit" class="btn btn-primary">Salva</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal Conferma Eliminazione -->
<div class="modal fade" id="deleteModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Conferma Eliminazione</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p>Sei sicuro di voler eliminare il paziente <strong id="deletePatientName"></strong>?</p>
                <p class="text-danger">Questa azione non può essere annullata.</p>
            </div>
            <div class="modal-footer">
                <form method="post" id="deleteForm">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="patient_id" id="deletePatientId">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annulla</button>
                    <button type="submit" class="btn btn-danger">Elimina</button>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
// Modifica paziente
function editPatient(id) {
    // Carica dati paziente via AJAX
    fetch(`../api/v1/patients.php?id=${id}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const patient = data.data;
                
                // Popola form
                document.getElementById('patient_id').value = patient.id;
                document.getElementById('nome').value = patient.nome;
                document.getElementById('cognome').value = patient.cognome;
                document.getElementById('codice_fiscale').value = patient.codice_fiscale;
                document.getElementById('codice_fiscale').readOnly = true; // CF non modificabile
                document.getElementById('data_nascita').value = patient.data_nascita || '';
                document.getElementById('sesso').value = patient.sesso || '';
                document.getElementById('email').value = patient.email || '';
                document.getElementById('telefono').value = patient.telefono || '';
                document.getElementById('indirizzo').value = patient.indirizzo || '';
                
                // Cambia action e titolo
                document.querySelector('#patientForm input[name="action"]').value = 'update';
                document.getElementById('patientModalTitle').textContent = 'Modifica Paziente';
                
                // Mostra modal
                const modal = new bootstrap.Modal(document.getElementById('patientModal'));
                modal.show();
            }
        })
        .catch(error => {
            console.error('Errore:', error);
            alert('Errore nel caricamento dei dati del paziente');
        });
}

// Elimina paziente
function deletePatient(id, nome) {
    document.getElementById('deletePatientId').value = id;
    document.getElementById('deletePatientName').textContent = nome;
    
    const modal = new bootstrap.Modal(document.getElementById('deleteModal'));
    modal.show();
}

// Reset form quando si chiude il modal
document.getElementById('patientModal').addEventListener('hidden.bs.modal', function () {
    document.getElementById('patientForm').reset();
    document.getElementById('patient_id').value = '';
    document.getElementById('codice_fiscale').readOnly = false;
    document.querySelector('#patientForm input[name="action"]').value = 'create';
    document.getElementById('patientModalTitle').textContent = 'Nuovo Paziente';
});

// Formattazione automatica CF
document.getElementById('codice_fiscale').addEventListener('input', function() {
    this.value = this.value.toUpperCase().replace(/[^A-Z0-9]/g, '');
});

// Validazione form
document.getElementById('patientForm').addEventListener('submit', function(e) {
    const cf = document.getElementById('codice_fiscale').value;
    if (cf.length !== 16) {
        e.preventDefault();
        alert('Il codice fiscale deve essere di 16 caratteri');
        return;
    }
    
    // Validazione email se presente
    const email = document.getElementById('email').value;
    if (email && !email.match(/^[^\s@]+@[^\s@]+\.[^\s@]+$/)) {
        e.preventDefault();
        alert('Email non valida');
        return;
    }
});
</script>

<?php
// Se in modalità edit, apri automaticamente il modal
if ($editPatient): ?>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const patient = <?php echo json_encode($editPatient); ?>;
    
    // Popola form
    document.getElementById('patient_id').value = patient.id;
    document.getElementById('nome').value = patient.nome;
    document.getElementById('cognome').value = patient.cognome;
    document.getElementById('codice_fiscale').value = patient.codice_fiscale;
    document.getElementById('codice_fiscale').readOnly = true;
    document.getElementById('data_nascita').value = patient.data_nascita || '';
    document.getElementById('sesso').value = patient.sesso || '';
    document.getElementById('email').value = patient.email || '';
    document.getElementById('telefono').value = patient.telefono || '';
    document.getElementById('indirizzo').value = patient.indirizzo || '';
    
    // Cambia action e titolo
    document.querySelector('#patientForm input[name="action"]').value = 'update';
    document.getElementById('patientModalTitle').textContent = 'Modifica Paziente';
    
    // Mostra modal
    const modal = new bootstrap.Modal(document.getElementById('patientModal'));
    modal.show();
});
</script>
<?php endif; ?>

<?php require_once '../templates/footer.php'; ?>
