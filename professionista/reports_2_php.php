<?php
session_start();
require_once '../includes/auth.php';
require_once '../includes/database.php';
require_once '../includes/functions.php';
require_once '../includes/security.php';
require_once '../classes/Report.php';

// Verifica autenticazione
if (!Auth::check() || Auth::getUserType() !== 'professionista') {
    redirect('/index.php');
}

$db = Database::getInstance()->getConnection();
$user = Auth::getUser();
$professionista_id = getProfessionistaId($db, $user['id']);

// Gestione download referto
if (isset($_GET['download']) && isset($_GET['id'])) {
    $referto_id = (int)$_GET['id'];
    $firmato = isset($_GET['firmato']) && $_GET['firmato'] === '1';
    
    // Verifica che il referto appartenga a un test del professionista
    $stmt = $db->prepare("
        SELECT r.*, t.codice, p.codice_fiscale, p.nome, p.cognome
        FROM referti r
        JOIN test t ON r.test_id = t.id
        JOIN pazienti p ON t.paziente_id = p.id
        WHERE r.id = ? AND t.professionista_id = ?
    ");
    $stmt->execute([$referto_id, $professionista_id]);
    $referto = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($referto) {
        // Verifica data scadenza (45 giorni)
        $data_creazione = new DateTime($referto['data_creazione']);
        $oggi = new DateTime();
        $diff = $oggi->diff($data_creazione);
        
        if ($diff->days <= 45) {
            $file_path = $firmato && $referto['file_path_firmato'] ? 
                        $referto['file_path_firmato'] : $referto['file_path'];
            
            if (file_exists($file_path)) {
                // Log download
                logActivity($db, $user['id'], 'download_referto', 
                           "Download referto ID: $referto_id, Firmato: " . ($firmato ? 'SI' : 'NO'));
                
                // Invia file
                header('Content-Type: application/pdf');
                header('Content-Disposition: attachment; filename="Referto_' . 
                       $referto['codice'] . '_' . 
                       $referto['cognome'] . '_' . 
                       ($firmato ? 'firmato' : 'non_firmato') . '.pdf"');
                header('Content-Length: ' . filesize($file_path));
                readfile($file_path);
                exit;
            }
        } else {
            $_SESSION['error'] = "Il referto è scaduto (oltre 45 giorni).";
        }
    }
    
    redirect('reports.php');
}

// Filtri
$filtro_paziente = $_GET['paziente'] ?? '';
$filtro_tipo = $_GET['tipo'] ?? '';
$filtro_data_da = $_GET['data_da'] ?? '';
$filtro_data_a = $_GET['data_a'] ?? '';

// Query referti
$query = "
    SELECT 
        r.*,
        t.codice AS test_codice,
        t.tipo_test,
        t.data_richiesta,
        t.stato AS test_stato,
        p.nome AS paziente_nome,
        p.cognome AS paziente_cognome,
        p.codice_fiscale,
        u.nome AS biologo_nome,
        u.cognome AS biologo_cognome
    FROM referti r
    JOIN test t ON r.test_id = t.id
    JOIN pazienti p ON t.paziente_id = p.id
    JOIN utenti u ON r.biologo_id = u.id
    WHERE t.professionista_id = ?
";

$params = [$professionista_id];

if ($filtro_paziente) {
    $query .= " AND (p.nome LIKE ? OR p.cognome LIKE ? OR p.codice_fiscale LIKE ?)";
    $search = "%$filtro_paziente%";
    $params[] = $search;
    $params[] = $search;
    $params[] = $search;
}

if ($filtro_tipo) {
    $query .= " AND r.tipo_referto = ?";
    $params[] = $filtro_tipo;
}

if ($filtro_data_da) {
    $query .= " AND DATE(r.data_creazione) >= ?";
    $params[] = $filtro_data_da;
}

if ($filtro_data_a) {
    $query .= " AND DATE(r.data_creazione) <= ?";
    $params[] = $filtro_data_a;
}

$query .= " ORDER BY r.data_creazione DESC";

$stmt = $db->prepare($query);
$stmt->execute($params);
$referti = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Calcola scadenze
foreach ($referti as &$referto) {
    $data_creazione = new DateTime($referto['data_creazione']);
    $oggi = new DateTime();
    $diff = $oggi->diff($data_creazione);
    $referto['giorni_rimanenti'] = max(0, 45 - $diff->days);
    $referto['scaduto'] = $diff->days > 45;
}

$page_title = 'Referti';
require_once '../templates/header.php';
require_once '../templates/sidebar.php';
?>

<div class="container-fluid">
    <div class="row">
        <div class="col-md-12">
            <h1 class="mb-4">Referti</h1>

            <!-- Filtri -->
            <div class="card mb-4">
                <div class="card-body">
                    <form method="GET" class="row g-3">
                        <div class="col-md-3">
                            <label class="form-label">Paziente</label>
                            <input type="text" name="paziente" class="form-control" 
                                   placeholder="Nome, cognome o CF" value="<?php echo htmlspecialchars($filtro_paziente); ?>">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Tipo</label>
                            <select name="tipo" class="form-select">
                                <option value="">Tutti</option>
                                <option value="genetico" <?php echo $filtro_tipo === 'genetico' ? 'selected' : ''; ?>>Genetico</option>
                                <option value="microbiota" <?php echo $filtro_tipo === 'microbiota' ? 'selected' : ''; ?>>Microbiota</option>
                                <option value="intolleranze" <?php echo $filtro_tipo === 'intolleranze' ? 'selected' : ''; ?>>Intolleranze</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Data da</label>
                            <input type="date" name="data_da" class="form-control" value="<?php echo $filtro_data_da; ?>">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Data a</label>
                            <input type="date" name="data_a" class="form-control" value="<?php echo $filtro_data_a; ?>">
                        </div>
                        <div class="col-md-1 d-flex align-items-end">
                            <button type="submit" class="btn btn-secondary w-100">Filtra</button>
                        </div>
                    </form>
                </div>
            </div>

            <?php if (isset($_SESSION['error'])): ?>
                <div class="alert alert-danger alert-dismissible fade show">
                    <?php 
                    echo $_SESSION['error'];
                    unset($_SESSION['error']);
                    ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <!-- Tabella referti -->
            <div class="card">
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-striped">
                            <thead>
                                <tr>
                                    <th>Codice Test</th>
                                    <th>Paziente</th>
                                    <th>Tipo</th>
                                    <th>Data Refertazione</th>
                                    <th>Biologo</th>
                                    <th>Stato</th>
                                    <th>Scadenza</th>
                                    <th>Azioni</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($referti as $referto): ?>
                                    <tr <?php echo $referto['scaduto'] ? 'class="table-secondary"' : ''; ?>>
                                        <td>
                                            <code><?php echo htmlspecialchars($referto['test_codice']); ?></code>
                                        </td>
                                        <td>
                                            <?php echo htmlspecialchars($referto['paziente_cognome'] . ' ' . $referto['paziente_nome']); ?><br>
                                            <small class="text-muted"><?php echo htmlspecialchars($referto['codice_fiscale']); ?></small>
                                        </td>
                                        <td>
                                            <?php 
                                            $tipo_labels = [
                                                'genetico' => 'Genetico',
                                                'microbiota' => 'Microbiota',
                                                'intolleranze' => 'Intolleranze'
                                            ];
                                            echo $tipo_labels[$referto['tipo_referto']] ?? $referto['tipo_referto'];
                                            ?>
                                        </td>
                                        <td><?php echo date('d/m/Y H:i', strtotime($referto['data_creazione'])); ?></td>
                                        <td><?php echo htmlspecialchars($referto['biologo_cognome'] . ' ' . $referto['biologo_nome']); ?></td>
                                        <td>
                                            <?php if ($referto['file_path_firmato']): ?>
                                                <span class="badge bg-success">Firmato</span>
                                            <?php else: ?>
                                                <span class="badge bg-warning">Non firmato</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($referto['scaduto']): ?>
                                                <span class="text-danger">Scaduto</span>
                                            <?php else: ?>
                                                <span class="<?php echo $referto['giorni_rimanenti'] < 7 ? 'text-warning' : ''; ?>">
                                                    <?php echo $referto['giorni_rimanenti']; ?> giorni
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if (!$referto['scaduto']): ?>
                                                <div class="btn-group btn-group-sm">
                                                    <a href="?download=1&id=<?php echo $referto['id']; ?>" 
                                                       class="btn btn-outline-primary" title="Scarica non firmato">
                                                        <i class="fas fa-download"></i>
                                                    </a>
                                                    <?php if ($referto['file_path_firmato']): ?>
                                                        <a href="?download=1&id=<?php echo $referto['id']; ?>&firmato=1" 
                                                           class="btn btn-outline-success" title="Scarica firmato">
                                                            <i class="fas fa-file-signature"></i>
                                                        </a>
                                                    <?php endif; ?>
                                                    <button class="btn btn-outline-info" 
                                                            onclick="sendToPatient(<?php echo $referto['test_id']; ?>)"
                                                            title="Invia al paziente">
                                                        <i class="fas fa-envelope"></i>
                                                    </button>
                                                </div>
                                            <?php else: ?>
                                                <span class="text-muted">Non disponibile</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Info scadenza -->
            <div class="alert alert-info mt-3">
                <i class="fas fa-info-circle"></i>
                I referti sono disponibili per il download per 45 giorni dalla data di emissione.
                I referti scaduti possono essere richiesti contattando il laboratorio.
            </div>
        </div>
    </div>
</div>

<!-- Modal invio email -->
<div class="modal fade" id="sendEmailModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form id="sendEmailForm">
                <input type="hidden" id="testIdEmail" name="test_id">
                <div class="modal-header">
                    <h5 class="modal-title">Invia Referto al Paziente</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p>Il paziente riceverà un'email con le istruzioni per scaricare il referto.</p>
                    <div class="alert alert-warning">
                        <i class="fas fa-exclamation-triangle"></i>
                        Il referto sarà protetto con il codice fiscale del paziente come password.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annulla</button>
                    <button type="submit" class="btn btn-primary">Invia Email</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function sendToPatient(testId) {
    document.getElementById('testIdEmail').value = testId;
    new bootstrap.Modal(document.getElementById('sendEmailModal')).show();
}

document.getElementById('sendEmailForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    
    const testId = document.getElementById('testIdEmail').value;
    const button = e.target.querySelector('button[type="submit"]');
    
    button.disabled = true;
    button.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Invio in corso...';
    
    try {
        const response = await fetch('/api/v1/reports.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                action: 'send_to_patient',
                test_id: testId
            })
        });
        
        const data = await response.json();
        
        if (data.success) {
            alert('Email inviata con successo!');
            bootstrap.Modal.getInstance(document.getElementById('sendEmailModal')).hide();
        } else {
            alert('Errore: ' + data.message);
        }
    } catch (error) {
        alert('Errore di rete: ' + error.message);
    } finally {
        button.disabled = false;
        button.innerHTML = 'Invia Email';
    }
});
</script>

<?php require_once '../templates/footer.php'; ?>