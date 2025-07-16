<?php
/**
 * SerteX+ - Gestione Utenti (Admin)
 */

require_once '../config/config.php';
require_once '../includes/functions.php';
require_once '../includes/security.php';

// Richiede autenticazione admin
requireAuth('amministratore');

use SerteX\User;
use SerteX\Logger;

$db = getDatabase();
$logger = new Logger($db);

// Gestione azioni
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCSRF();
    
    $action = $_POST['action'] ?? '';
    
    switch ($action) {
        case 'create':
            $userData = [
                'username' => sanitize($_POST['username']),
                'email' => sanitize($_POST['email']),
                'password' => $_POST['password'],
                'nome' => sanitize($_POST['nome']),
                'cognome' => sanitize($_POST['cognome']),
                'tipo_utente' => $_POST['tipo_utente'],
                'attivo' => isset($_POST['attivo']) ? 1 : 0
            ];
            
            // Dati aggiuntivi per professionista
            if ($userData['tipo_utente'] === 'professionista') {
                $userData['codice_fiscale'] = strtoupper($_POST['codice_fiscale'] ?? '');
                $userData['partita_iva'] = $_POST['partita_iva'] ?? '';
                $userData['codice_sdi'] = $_POST['codice_sdi'] ?? '';
                $userData['pec'] = $_POST['pec'] ?? '';
                $userData['telefono'] = $_POST['telefono'] ?? '';
                $userData['indirizzo'] = $_POST['indirizzo'] ?? '';
                $userData['listino_id'] = $_POST['listino_id'] ?? null;
            }
            
            $user = new User($db);
            $userId = $user->create($userData);
            
            if ($userId) {
                $logger->info('user', 'user_created', "Creato utente: {$userData['username']}");
                redirectWithMessage('users.php', 'Utente creato con successo', 'success');
            } else {
                redirectWithMessage('users.php', 'Errore nella creazione dell\'utente', 'error');
            }
            break;
            
        case 'update':
            $userId = validateId($_POST['user_id']);
            if (!$userId) break;
            
            $user = new User($db, $userId);
            
            $updateData = [
                'email' => sanitize($_POST['email']),
                'nome' => sanitize($_POST['nome']),
                'cognome' => sanitize($_POST['cognome']),
                'attivo' => isset($_POST['attivo']) ? 1 : 0
            ];
            
            // Password solo se fornita
            if (!empty($_POST['password'])) {
                $updateData['password'] = $_POST['password'];
            }
            
            // Dati professionista
            if ($user->tipo_utente === 'professionista') {
                $updateData['codice_fiscale'] = strtoupper($_POST['codice_fiscale'] ?? '');
                $updateData['partita_iva'] = $_POST['partita_iva'] ?? '';
                $updateData['codice_sdi'] = $_POST['codice_sdi'] ?? '';
                $updateData['pec'] = $_POST['pec'] ?? '';
                $updateData['telefono'] = $_POST['telefono'] ?? '';
                $updateData['indirizzo'] = $_POST['indirizzo'] ?? '';
                $updateData['listino_id'] = $_POST['listino_id'] ?? null;
            }
            
            if ($user->update($updateData)) {
                $logger->info('user', 'user_updated', "Aggiornato utente ID: $userId");
                redirectWithMessage('users.php', 'Utente aggiornato con successo', 'success');
            } else {
                redirectWithMessage('users.php', 'Errore nell\'aggiornamento', 'error');
            }
            break;
            
        case 'delete':
            $userId = validateId($_POST['user_id']);
            if (!$userId) break;
            
            $user = new User($db, $userId);
            if ($user->delete()) {
                $logger->info('user', 'user_deleted', "Eliminato utente ID: $userId");
                redirectWithMessage('users.php', 'Utente eliminato con successo', 'success');
            }
            break;
            
        case 'unblock':
            $userId = validateId($_POST['user_id']);
            if (!$userId) break;
            
            $user = new User($db, $userId);
            if ($user->unblock()) {
                redirectWithMessage('users.php', 'Utente sbloccato con successo', 'success');
            }
            break;
            
        case 'reset_password':
            $userId = validateId($_POST['user_id']);
            if (!$userId) break;
            
            $user = new User($db, $userId);
            $newPassword = generatePassword();
            
            if ($user->resetPassword($newPassword)) {
                // Invia email con nuova password
                $subject = "Reset Password - SerteX+";
                $body = "La tua password è stata resettata.\n\nNuova password: $newPassword\n\n";
                $body .= "Ti consigliamo di cambiarla al primo accesso.";
                
                sendEmail($user->email, $subject, $body);
                
                $logger->info('user', 'password_reset', "Reset password utente ID: $userId");
                redirectWithMessage('users.php', 'Password resettata e inviata via email', 'success');
            }
            break;
    }
}

// Filtri
$filters = [
    'tipo_utente' => $_GET['tipo'] ?? '',
    'attivo' => $_GET['attivo'] ?? '',
    'search' => $_GET['search'] ?? ''
];

// Paginazione
$page = (int)($_GET['page'] ?? 1);
$limit = 20;
$offset = ($page - 1) * $limit;

// Recupera utenti
$users = User::getAll($db, $filters, $limit, $offset);

// Conta totale per paginazione
$totalUsers = count(User::getAll($db, $filters, 1000, 0));
$totalPages = ceil($totalUsers / $limit);

// Recupera listini per form
$stmt = $db->query("SELECT id, nome FROM listini WHERE attivo = 1 ORDER BY nome");
$listini = $stmt->fetchAll();

$pageTitle = 'Gestione Utenti';
include '../templates/header.php';
?>

<div class="container-fluid">
    <div class="row">
        <?php include '../templates/sidebar.php'; ?>
        
        <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
            <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                <h1 class="h2">Gestione Utenti</h1>
                <div class="btn-toolbar mb-2 mb-md-0">
                    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#userModal">
                        <i class="fas fa-user-plus"></i> Nuovo Utente
                    </button>
                </div>
            </div>
            
            <!-- Messaggi -->
            <?php if (isset($_SESSION['success_message'])): ?>
                <div class="alert alert-success alert-dismissible fade show">
                    <i class="fas fa-check-circle me-2"></i>
                    <?php 
                    echo htmlspecialchars($_SESSION['success_message']);
                    unset($_SESSION['success_message']);
                    ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
            
            <?php if (isset($_SESSION['error_message'])): ?>
                <div class="alert alert-danger alert-dismissible fade show">
                    <i class="fas fa-exclamation-circle me-2"></i>
                    <?php 
                    echo htmlspecialchars($_SESSION['error_message']);
                    unset($_SESSION['error_message']);
                    ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
            
            <!-- Filtri -->
            <div class="card mb-4">
                <div class="card-body">
                    <form method="get" class="row g-3">
                        <div class="col-md-3">
                            <label class="form-label">Tipo Utente</label>
                            <select name="tipo" class="form-select">
                                <option value="">Tutti</option>
                                <option value="amministratore" <?php echo $filters['tipo_utente'] === 'amministratore' ? 'selected' : ''; ?>>
                                    Amministratore
                                </option>
                                <option value="biologo" <?php echo $filters['tipo_utente'] === 'biologo' ? 'selected' : ''; ?>>
                                    Biologo
                                </option>
                                <option value="professionista" <?php echo $filters['tipo_utente'] === 'professionista' ? 'selected' : ''; ?>>
                                    Professionista
                                </option>
                                <option value="commerciale" <?php echo $filters['tipo_utente'] === 'commerciale' ? 'selected' : ''; ?>>
                                    Commerciale
                                </option>
                            </select>
                        </div>
                        
                        <div class="col-md-3">
                            <label class="form-label">Stato</label>
                            <select name="attivo" class="form-select">
                                <option value="">Tutti</option>
                                <option value="1" <?php echo $filters['attivo'] === '1' ? 'selected' : ''; ?>>Attivi</option>
                                <option value="0" <?php echo $filters['attivo'] === '0' ? 'selected' : ''; ?>>Disattivi</option>
                            </select>
                        </div>
                        
                        <div class="col-md-4">
                            <label class="form-label">Cerca</label>
                            <input type="text" name="search" class="form-control" 
                                   placeholder="Nome, cognome, username, email..."
                                   value="<?php echo htmlspecialchars($filters['search']); ?>">
                        </div>
                        
                        <div class="col-md-2 d-flex align-items-end">
                            <button type="submit" class="btn btn-primary w-100">
                                <i class="fas fa-search"></i> Filtra
                            </button>
                        </div>
                    </form>
                </div>
            </div>
            
            <!-- Tabella utenti -->
            <div class="card">
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>Username</th>
                                    <th>Nome</th>
                                    <th>Email</th>
                                    <th>Tipo</th>
                                    <th>Ultimo Accesso</th>
                                    <th>Stato</th>
                                    <th>Azioni</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($users as $user): ?>
                                <tr>
                                    <td>
                                        <strong><?php echo htmlspecialchars($user['username']); ?></strong>
                                        <?php if ($user['bloccato']): ?>
                                            <span class="badge bg-danger ms-1">Bloccato</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($user['cognome'] . ' ' . $user['nome']); ?></td>
                                    <td>
                                        <?php echo htmlspecialchars($user['email']); ?>
                                        <?php if ($user['tipo_utente'] === 'professionista' && $user['partita_iva']): ?>
                                            <br>
                                            <small class="text-muted">P.IVA: <?php echo $user['partita_iva']; ?></small>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge bg-<?php echo getUserTypeBadgeClass($user['tipo_utente']); ?>">
                                            <?php echo ucfirst($user['tipo_utente']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($user['data_ultimo_accesso']): ?>
                                            <?php echo formatDate($user['data_ultimo_accesso'], 'd/m/Y H:i'); ?>
                                        <?php else: ?>
                                            <span class="text-muted">Mai</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($user['attivo']): ?>
                                            <span class="badge bg-success">Attivo</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary">Disattivo</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="btn-group btn-group-sm">
                                            <button type="button" class="btn btn-outline-primary" 
                                                    onclick="editUser(<?php echo $user['id']; ?>)"
                                                    title="Modifica">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            
                                            <div class="btn-group btn-group-sm" role="group">
                                                <button type="button" class="btn btn-outline-secondary dropdown-toggle" 
                                                        data-bs-toggle="dropdown">
                                                    <i class="fas fa-ellipsis-v"></i>
                                                </button>
                                                <ul class="dropdown-menu">
                                                    <?php if ($user['bloccato']): ?>
                                                    <li>
                                                        <form method="post" class="d-inline">
                                                            <?php echo csrfInput(); ?>
                                                            <input type="hidden" name="action" value="unblock">
                                                            <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                                            <button type="submit" class="dropdown-item">
                                                                <i class="fas fa-unlock me-2"></i> Sblocca
                                                            </button>
                                                        </form>
                                                    </li>
                                                    <?php endif; ?>
                                                    
                                                    <li>
                                                        <form method="post" class="d-inline">
                                                            <?php echo csrfInput(); ?>
                                                            <input type="hidden" name="action" value="reset_password">
                                                            <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                                            <button type="submit" class="dropdown-item" 
                                                                    onclick="return confirm('Resettare la password?')">
                                                                <i class="fas fa-key me-2"></i> Reset Password
                                                            </button>
                                                        </form>
                                                    </li>
                                                    
                                                    <li><hr class="dropdown-divider"></li>
                                                    
                                                    <li>
                                                        <form method="post" class="d-inline">
                                                            <?php echo csrfInput(); ?>
                                                            <input type="hidden" name="action" value="delete">
                                                            <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                                            <button type="submit" class="dropdown-item text-danger confirm-delete"
                                                                    data-message="Eliminare l'utente <?php echo htmlspecialchars($user['username']); ?>?">
                                                                <i class="fas fa-trash me-2"></i> Elimina
                                                            </button>
                                                        </form>
                                                    </li>
                                                </ul>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                
                                <?php if (empty($users)): ?>
                                <tr>
                                    <td colspan="7" class="text-center py-4 text-muted">
                                        Nessun utente trovato
                                    </td>
                                </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <!-- Paginazione -->
                    <?php if ($totalPages > 1): ?>
                    <nav>
                        <ul class="pagination justify-content-center">
                            <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                                <a class="page-link" href="?page=<?php echo $page - 1; ?>&<?php echo http_build_query($filters); ?>">
                                    Precedente
                                </a>
                            </li>
                            
                            <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                            <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                                <a class="page-link" href="?page=<?php echo $i; ?>&<?php echo http_build_query($filters); ?>">
                                    <?php echo $i; ?>
                                </a>
                            </li>
                            <?php endfor; ?>
                            
                            <li class="page-item <?php echo $page >= $totalPages ? 'disabled' : ''; ?>">
                                <a class="page-link" href="?page=<?php echo $page + 1; ?>&<?php echo http_build_query($filters); ?>">
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

<!-- Modal Utente -->
<div class="modal fade" id="userModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="userModalTitle">Nuovo Utente</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="post" class="needs-validation" novalidate>
                <div class="modal-body">
                    <?php echo csrfInput(); ?>
                    <input type="hidden" name="action" id="userAction" value="create">
                    <input type="hidden" name="user_id" id="userId">
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Username *</label>
                                <input type="text" class="form-control" name="username" id="username" required>
                                <div class="invalid-feedback">Username richiesto</div>
                            </div>
                        </div>
                        
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Email *</label>
                                <input type="email" class="form-control" name="email" id="email" required>
                                <div class="invalid-feedback">Email valida richiesta</div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Nome *</label>
                                <input type="text" class="form-control" name="nome" id="nome" required>
                                <div class="invalid-feedback">Nome richiesto</div>
                            </div>
                        </div>
                        
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Cognome *</label>
                                <input type="text" class="form-control" name="cognome" id="cognome" required>
                                <div class="invalid-feedback">Cognome richiesto</div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Password <span id="passwordRequired">*</span></label>
                                <input type="password" class="form-control" name="password" id="password">
                                <div class="invalid-feedback">Password richiesta (min. 8 caratteri)</div>
                                <small class="text-muted" id="passwordHelp" style="display:none;">
                                    Lascia vuoto per mantenere la password attuale
                                </small>
                            </div>
                        </div>
                        
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Tipo Utente *</label>
                                <select class="form-select" name="tipo_utente" id="tipoUtente" required onchange="toggleProfessionistaFields()">
                                    <option value="">Seleziona...</option>
                                    <option value="amministratore">Amministratore</option>
                                    <option value="biologo">Biologo</option>
                                    <option value="professionista">Professionista</option>
                                    <option value="commerciale">Commerciale</option>
                                </select>
                                <div class="invalid-feedback">Seleziona un tipo utente</div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- Campi aggiuntivi professionista -->
                    <div id="professionistaFields" style="display:none;">
                        <hr>
                        <h6 class="mb-3">Dati Professionista</h6>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Codice Fiscale</label>
                                    <input type="text" class="form-control text-uppercase" name="codice_fiscale" 
                                           id="codiceFiscale" maxlength="16">
                                </div>
                            </div>
                            
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Partita IVA</label>
                                    <input type="text" class="form-control" name="partita_iva" 
                                           id="partitaIva" maxlength="11">
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label class="form-label">Codice SDI</label>
                                    <input type="text" class="form-control text-uppercase" name="codice_sdi" 
                                           id="codiceSdi" maxlength="7">
                                </div>
                            </div>
                            
                            <div class="col-md-8">
                                <div class="mb-3">
                                    <label class="form-label">PEC</label>
                                    <input type="email" class="form-control" name="pec" id="pec">
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-4">
                                <div class="mb-3">
                                    <label class="form-label">Telefono</label>
                                    <input type="tel" class="form-control" name="telefono" id="telefono">
                                </div>
                            </div>
                            
                            <div class="col-md-8">
                                <div class="mb-3">
                                    <label class="form-label">Indirizzo</label>
                                    <input type="text" class="form-control" name="indirizzo" id="indirizzo">
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label class="form-label">Listino Prezzi</label>
                                    <select class="form-select" name="listino_id" id="listinoId">
                                        <option value="">Listino predefinito</option>
                                        <?php foreach ($listini as $listino): ?>
                                        <option value="<?php echo $listino['id']; ?>">
                                            <?php echo htmlspecialchars($listino['nome']); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="attivo" id="attivo" checked>
                        <label class="form-check-label" for="attivo">
                            Utente attivo
                        </label>
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annulla</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save me-2"></i>Salva
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php
// Funzione helper per badge tipo utente
function getUserTypeBadgeClass($tipo) {
    $classes = [
        'amministratore' => 'danger',
        'biologo' => 'primary',
        'professionista' => 'success',
        'commerciale' => 'warning'
    ];
    return $classes[$tipo] ?? 'secondary';
}
?>

<script>
// Toggle campi professionista
function toggleProfessionistaFields() {
    const tipo = document.getElementById('tipoUtente').value;
    const fields = document.getElementById('professionistaFields');
    fields.style.display = tipo === 'professionista' ? 'block' : 'none';
}

// Edit user
function editUser(userId) {
    // Reset form
    document.getElementById('userModalTitle').textContent = 'Modifica Utente';
    document.getElementById('userAction').value = 'update';
    document.getElementById('userId').value = userId;
    document.getElementById('username').disabled = true;
    document.getElementById('tipoUtente').disabled = true;
    document.getElementById('password').required = false;
    document.getElementById('passwordRequired').style.display = 'none';
    document.getElementById('passwordHelp').style.display = 'block';
    
    // Carica dati utente
    fetch(`api/get-user.php?id=${userId}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const user = data.user;
                document.getElementById('username').value = user.username;
                document.getElementById('email').value = user.email;
                document.getElementById('nome').value = user.nome;
                document.getElementById('cognome').value = user.cognome;
                document.getElementById('tipoUtente').value = user.tipo_utente;
                document.getElementById('attivo').checked = user.attivo == 1;
                
                // Campi professionista
                if (user.tipo_utente === 'professionista') {
                    toggleProfessionistaFields();
                    document.getElementById('codiceFiscale').value = user.codice_fiscale || '';
                    document.getElementById('partitaIva').value = user.partita_iva || '';
                    document.getElementById('codiceSdi').value = user.codice_sdi || '';
                    document.getElementById('pec').value = user.pec || '';
                    document.getElementById('telefono').value = user.telefono || '';
                    document.getElementById('indirizzo').value = user.indirizzo || '';
                    document.getElementById('listinoId').value = user.listino_id || '';
                }
                
                // Mostra modal
                const modal = new bootstrap.Modal(document.getElementById('userModal'));
                modal.show();
            }
        });
}

// Reset modal on close
document.getElementById('userModal').addEventListener('hidden.bs.modal', function () {
    document.getElementById('userModalTitle').textContent = 'Nuovo Utente';
    document.getElementById('userAction').value = 'create';
    document.getElementById('userId').value = '';
    document.getElementById('username').disabled = false;
    document.getElementById('tipoUtente').disabled = false;
    document.getElementById('password').required = true;
    document.getElementById('passwordRequired').style.display = 'inline';
    document.getElementById('passwordHelp').style.display = 'none';
    
    // Reset form
    this.querySelector('form').reset();
    this.querySelector('form').classList.remove('was-validated');
    toggleProfessionistaFields();
});
</script>

<?php include '../templates/footer.php'; ?>