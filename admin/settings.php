<?php
/**
 * Impostazioni Sistema - Area Amministratore
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
if (!$auth->isAuthenticated() || !$auth->hasRole('amministratore')) {
    header('Location: ../index.php');
    exit;
}

$user = $auth->getCurrentUser();
$activeTab = $_GET['tab'] ?? 'general';
$message = '';
$messageType = '';

// Gestione salvataggio impostazioni
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? '';
    
    if (!$session->validateCsrfToken($csrfToken)) {
        $message = 'Token di sicurezza non valido';
        $messageType = 'error';
    } else {
        $section = $_POST['section'] ?? '';
        
        switch ($section) {
            case 'general':
                // Impostazioni generali
                $settings = [
                    'nome_laboratorio' => sanitizeInput($_POST['nome_laboratorio']),
                    'colore_primario' => sanitizeInput($_POST['colore_primario']),
                    'colore_secondario' => sanitizeInput($_POST['colore_secondario'])
                ];
                
                // Gestione logo
                if (!empty($_FILES['logo']['name'])) {
                    $uploadResult = uploadFile($_FILES['logo'], 'images', ['jpg', 'jpeg', 'png', 'gif']);
                    if ($uploadResult['success']) {
                        $settings['logo_path'] = $uploadResult['path'];
                    } else {
                        $message = $uploadResult['error'];
                        $messageType = 'error';
                    }
                }
                
                if (!$message) {
                    foreach ($settings as $key => $value) {
                        $db->query(
                            "INSERT INTO configurazione (chiave, valore) VALUES (:key, :value) 
                             ON DUPLICATE KEY UPDATE valore = :value",
                            ['key' => $key, 'value' => $value]
                        );
                    }
                    $message = 'Impostazioni generali salvate con successo';
                    $messageType = 'success';
                    $logger->log($user->getId(), 'impostazioni_generali_modificate', '');
                }
                break;
                
            case 'security':
                // Impostazioni sicurezza
                $settings = [
                    'two_factor_enabled' => isset($_POST['two_factor_enabled']) ? '1' : '0',
                    'password_expiry_days' => intval($_POST['password_expiry_days']),
                    'max_login_attempts' => intval($_POST['max_login_attempts']),
                    'session_timeout' => intval($_POST['session_timeout'])
                ];
                
                foreach ($settings as $key => $value) {
                    $db->query(
                        "INSERT INTO configurazione (chiave, valore) VALUES (:key, :value) 
                         ON DUPLICATE KEY UPDATE valore = :value",
                        ['key' => $key, 'value' => $value]
                    );
                }
                
                $message = 'Impostazioni di sicurezza salvate con successo';
                $messageType = 'success';
                $logger->log($user->getId(), 'impostazioni_sicurezza_modificate', '');
                break;
                
            case 'backup':
                // Impostazioni backup
                $settings = [
                    'backup_locale_enabled' => isset($_POST['backup_locale_enabled']) ? '1' : '0',
                    'backup_cloud_enabled' => isset($_POST['backup_cloud_enabled']) ? '1' : '0',
                    'backup_cloud_provider' => sanitizeInput($_POST['backup_cloud_provider'] ?? '')
                ];
                
                // Valida credenziali cloud se abilitato
                if ($settings['backup_cloud_enabled'] === '1' && empty($settings['backup_cloud_provider'])) {
                    $message = 'Selezionare un provider cloud per abilitare il backup cloud';
                    $messageType = 'error';
                } else {
                    foreach ($settings as $key => $value) {
                        $db->query(
                            "INSERT INTO configurazione (chiave, valore) VALUES (:key, :value) 
                             ON DUPLICATE KEY UPDATE valore = :value",
                            ['key' => $key, 'value' => $value]
                        );
                    }
                    
                    // Salva credenziali cloud se fornite
                    if (!empty($_POST['cloud_access_key'])) {
                        $db->query(
                            "INSERT INTO configurazione (chiave, valore) VALUES ('cloud_access_key', :value) 
                             ON DUPLICATE KEY UPDATE valore = :value",
                            ['value' => encrypt($_POST['cloud_access_key'])]
                        );
                    }
                    
                    if (!empty($_POST['cloud_secret_key'])) {
                        $db->query(
                            "INSERT INTO configurazione (chiave, valore) VALUES ('cloud_secret_key', :value) 
                             ON DUPLICATE KEY UPDATE valore = :value",
                            ['value' => encrypt($_POST['cloud_secret_key'])]
                        );
                    }
                    
                    $message = 'Impostazioni backup salvate con successo';
                    $messageType = 'success';
                    $logger->log($user->getId(), 'impostazioni_backup_modificate', '');
                }
                break;
                
            case 'retention':
                // Impostazioni retention
                $settings = [
                    'referto_retention_days' => intval($_POST['referto_retention_days'])
                ];
                
                foreach ($settings as $key => $value) {
                    $db->query(
                        "INSERT INTO configurazione (chiave, valore) VALUES (:key, :value) 
                         ON DUPLICATE KEY UPDATE valore = :value",
                        ['key' => $key, 'value' => $value]
                    );
                }
                
                $message = 'Impostazioni retention salvate con successo';
                $messageType = 'success';
                $logger->log($user->getId(), 'impostazioni_retention_modificate', '');
                break;
                
            case 'groups':
                // Gestione gruppi geni
                if ($_POST['action'] === 'add_group') {
                    $groupData = [
                        'nome' => sanitizeInput($_POST['nome']),
                        'descrizione' => sanitizeInput($_POST['descrizione'] ?? ''),
                        'ordine' => intval($_POST['ordine'] ?? 0)
                    ];
                    
                    $db->insert('gruppi_geni', $groupData);
                    $message = 'Gruppo aggiunto con successo';
                    $messageType = 'success';
                    $logger->log($user->getId(), 'gruppo_geni_creato', "Gruppo: {$groupData['nome']}");
                    
                } elseif ($_POST['action'] === 'delete_group') {
                    $groupId = intval($_POST['group_id']);
                    
                    // Verifica che non ci siano geni associati
                    $count = $db->count('geni', ['gruppo_id' => $groupId]);
                    if ($count > 0) {
                        $message = 'Impossibile eliminare il gruppo perché contiene geni';
                        $messageType = 'error';
                    } else {
                        $db->delete('gruppi_geni', ['id' => $groupId]);
                        $message = 'Gruppo eliminato con successo';
                        $messageType = 'success';
                        $logger->log($user->getId(), 'gruppo_geni_eliminato', "Gruppo ID: {$groupId}");
                    }
                }
                break;
        }
        
        // Redirect per evitare re-submit
        if ($messageType === 'success') {
            $session->setFlash($messageType, $message);
            header("Location: settings.php?tab={$activeTab}");
            exit;
        }
    }
}

// Carica configurazioni attuali
$config = [];
$configRows = $db->select("SELECT chiave, valore FROM configurazione");
foreach ($configRows as $row) {
    $config[$row['chiave']] = $row['valore'];
}

// Carica gruppi geni
$gruppiGeni = $db->select("
    SELECT gg.*, COUNT(g.id) as num_geni 
    FROM gruppi_geni gg 
    LEFT JOIN geni g ON gg.id = g.gruppo_id 
    GROUP BY gg.id 
    ORDER BY gg.ordine, gg.nome
");

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
                <h1 class="h2">Impostazioni Sistema</h1>
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
            
            <!-- Tabs -->
            <ul class="nav nav-tabs" role="tablist">
                <li class="nav-item">
                    <a class="nav-link <?php echo $activeTab === 'general' ? 'active' : ''; ?>" 
                       href="?tab=general">Generali</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo $activeTab === 'security' ? 'active' : ''; ?>" 
                       href="?tab=security">Sicurezza</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo $activeTab === 'backup' ? 'active' : ''; ?>" 
                       href="?tab=backup">Backup</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo $activeTab === 'retention' ? 'active' : ''; ?>" 
                       href="?tab=retention">Retention</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo $activeTab === 'groups' ? 'active' : ''; ?>" 
                       href="?tab=groups">Gruppi Geni</a>
                </li>
            </ul>
            
            <div class="tab-content mt-4">
                <!-- Tab Impostazioni Generali -->
                <?php if ($activeTab === 'general'): ?>
                <div class="tab-pane active">
                    <form method="post" enctype="multipart/form-data">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                        <input type="hidden" name="section" value="general">
                        
                        <div class="row">
                            <div class="col-md-6">
                                <h5>Informazioni Laboratorio</h5>
                                
                                <div class="mb-3">
                                    <label for="nome_laboratorio" class="form-label">Nome Laboratorio</label>
                                    <input type="text" class="form-control" id="nome_laboratorio" 
                                           name="nome_laboratorio" 
                                           value="<?php echo htmlspecialchars($config['nome_laboratorio'] ?? ''); ?>" 
                                           required>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="logo" class="form-label">Logo</label>
                                    <?php if (!empty($config['logo_path'])): ?>
                                        <div class="mb-2">
                                            <img src="../<?php echo htmlspecialchars($config['logo_path']); ?>" 
                                                 alt="Logo" style="max-height: 100px;">
                                        </div>
                                    <?php endif; ?>
                                    <input type="file" class="form-control" id="logo" name="logo" 
                                           accept="image/*">
                                    <small class="text-muted">Formati accettati: JPG, PNG, GIF</small>
                                </div>
                            </div>
                            
                            <div class="col-md-6">
                                <h5>Personalizzazione Interfaccia</h5>
                                
                                <div class="mb-3">
                                    <label for="colore_primario" class="form-label">Colore Primario</label>
                                    <div class="input-group">
                                        <input type="color" class="form-control form-control-color" 
                                               id="colore_primario" name="colore_primario" 
                                               value="<?php echo htmlspecialchars($config['colore_primario'] ?? '#1976d2'); ?>">
                                        <input type="text" class="form-control" 
                                               value="<?php echo htmlspecialchars($config['colore_primario'] ?? '#1976d2'); ?>" 
                                               readonly>
                                    </div>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="colore_secondario" class="form-label">Colore Secondario</label>
                                    <div class="input-group">
                                        <input type="color" class="form-control form-control-color" 
                                               id="colore_secondario" name="colore_secondario" 
                                               value="<?php echo htmlspecialchars($config['colore_secondario'] ?? '#dc004e'); ?>">
                                        <input type="text" class="form-control" 
                                               value="<?php echo htmlspecialchars($config['colore_secondario'] ?? '#dc004e'); ?>" 
                                               readonly>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <button type="submit" class="btn btn-primary">Salva Impostazioni</button>
                    </form>
                </div>
                <?php endif; ?>
                
                <!-- Tab Sicurezza -->
                <?php if ($activeTab === 'security'): ?>
                <div class="tab-pane active">
                    <form method="post">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                        <input type="hidden" name="section" value="security">
                        
                        <div class="row">
                            <div class="col-md-6">
                                <h5>Autenticazione</h5>
                                
                                <div class="mb-3">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="two_factor_enabled" 
                                               name="two_factor_enabled" 
                                               <?php echo ($config['two_factor_enabled'] ?? '0') == '1' ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="two_factor_enabled">
                                            Abilita autenticazione a due fattori (2FA)
                                        </label>
                                    </div>
                                    <small class="text-muted">
                                        Richiede un codice aggiuntivo per l'accesso
                                    </small>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="max_login_attempts" class="form-label">
                                        Tentativi di login massimi
                                    </label>
                                    <input type="number" class="form-control" id="max_login_attempts" 
                                           name="max_login_attempts" min="3" max="10" 
                                           value="<?php echo htmlspecialchars($config['max_login_attempts'] ?? '5'); ?>" 
                                           required>
                                    <small class="text-muted">
                                        Dopo questo numero di tentativi falliti, l'account viene bloccato
                                    </small>
                                </div>
                            </div>
                            
                            <div class="col-md-6">
                                <h5>Password e Sessioni</h5>
                                
                                <div class="mb-3">
                                    <label for="password_expiry_days" class="form-label">
                                        Scadenza password (giorni)
                                    </label>
                                    <input type="number" class="form-control" id="password_expiry_days" 
                                           name="password_expiry_days" min="0" max="365" 
                                           value="<?php echo htmlspecialchars($config['password_expiry_days'] ?? '90'); ?>" 
                                           required>
                                    <small class="text-muted">
                                        0 = nessuna scadenza. Non si applica agli amministratori
                                    </small>
                                </div>
                                
                                <div class="mb-3">
                                    <label for="session_timeout" class="form-label">
                                        Timeout sessione (secondi)
                                    </label>
                                    <input type="number" class="form-control" id="session_timeout" 
                                           name="session_timeout" min="600" max="86400" step="60"
                                           value="<?php echo htmlspecialchars($config['session_timeout'] ?? '3600'); ?>" 
                                           required>
                                    <small class="text-muted">
                                        Tempo di inattività prima del logout automatico
                                    </small>
                                </div>
                            </div>
                        </div>
                        
                        <button type="submit" class="btn btn-primary">Salva Impostazioni</button>
                    </form>
                </div>
                <?php endif; ?>
                
                <!-- Tab Backup -->
                <?php if ($activeTab === 'backup'): ?>
                <div class="tab-pane active">
                    <form method="post">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                        <input type="hidden" name="section" value="backup">
                        
                        <div class="row">
                            <div class="col-md-6">
                                <h5>Backup Locale</h5>
                                
                                <div class="mb-3">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="backup_locale_enabled" 
                                               name="backup_locale_enabled" 
                                               <?php echo ($config['backup_locale_enabled'] ?? '1') == '1' ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="backup_locale_enabled">
                                            Abilita backup locale automatico
                                        </label>
                                    </div>
                                    <small class="text-muted">
                                        Salva backup giornalieri del database e dei file
                                    </small>
                                </div>
                                
                                <div class="alert alert-info">
                                    <i class="bi bi-info-circle"></i>
                                    Directory backup: <code>/backup/</code>
                                </div>
                            </div>
                            
                            <div class="col-md-6">
                                <h5>Backup Cloud</h5>
                                
                                <div class="mb-3">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="backup_cloud_enabled" 
                                               name="backup_cloud_enabled" 
                                               <?php echo ($config['backup_cloud_enabled'] ?? '0') == '1' ? 'checked' : ''; ?>
                                               onchange="toggleCloudSettings()">
                                        <label class="form-check-label" for="backup_cloud_enabled">
                                            Abilita backup cloud automatico
                                        </label>
                                    </div>
                                </div>
                                
                                <div id="cloudSettings" style="<?php echo ($config['backup_cloud_enabled'] ?? '0') == '1' ? '' : 'display: none;'; ?>">
                                    <div class="mb-3">
                                        <label for="backup_cloud_provider" class="form-label">Provider Cloud</label>
                                        <select class="form-select" id="backup_cloud_provider" name="backup_cloud_provider">
                                            <option value="">Seleziona provider</option>
                                            <option value="aws" <?php echo ($config['backup_cloud_provider'] ?? '') == 'aws' ? 'selected' : ''; ?>>
                                                Amazon S3
                                            </option>
                                            <option value="google" <?php echo ($config['backup_cloud_provider'] ?? '') == 'google' ? 'selected' : ''; ?>>
                                                Google Cloud Storage
                                            </option>
                                            <option value="azure" <?php echo ($config['backup_cloud_provider'] ?? '') == 'azure' ? 'selected' : ''; ?>>
                                                Microsoft Azure
                                            </option>
                                        </select>
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label for="cloud_access_key" class="form-label">Access Key</label>
                                        <input type="password" class="form-control" id="cloud_access_key" 
                                               name="cloud_access_key" placeholder="Lascia vuoto per non modificare">
                                    </div>
                                    
                                    <div class="mb-3">
                                        <label for="cloud_secret_key" class="form-label">Secret Key</label>
                                        <input type="password" class="form-control" id="cloud_secret_key" 
                                               name="cloud_secret_key" placeholder="Lascia vuoto per non modificare">
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <button type="submit" class="btn btn-primary">Salva Impostazioni</button>
                    </form>
                </div>
                <?php endif; ?>
                
                <!-- Tab Retention -->
                <?php if ($activeTab === 'retention'): ?>
                <div class="tab-pane active">
                    <form method="post">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                        <input type="hidden" name="section" value="retention">
                        
                        <h5>Politiche di Retention</h5>
                        
                        <div class="alert alert-warning">
                            <i class="bi bi-exclamation-triangle"></i>
                            <strong>Attenzione:</strong> Queste impostazioni determinano per quanto tempo 
                            i dati rimangono accessibili ai pazienti e professionisti.
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="referto_retention_days" class="form-label">
                                        Retention referti per pazienti (giorni)
                                    </label>
                                    <input type="number" class="form-control" id="referto_retention_days" 
                                           name="referto_retention_days" min="30" max="365" 
                                           value="<?php echo htmlspecialchars($config['referto_retention_days'] ?? '45'); ?>" 
                                           required>
                                    <small class="text-muted">
                                        Dopo questo periodo, i referti non saranno più scaricabili dai pazienti
                                    </small>
                                </div>
                            </div>
                        </div>
                        
                        <div class="alert alert-info">
                            <i class="bi bi-info-circle"></i>
                            I referti rimangono sempre disponibili per i biologi nell'archivio interno.
                        </div>
                        
                        <button type="submit" class="btn btn-primary">Salva Impostazioni</button>
                    </form>
                </div>
                <?php endif; ?>
                
                <!-- Tab Gruppi Geni -->
                <?php if ($activeTab === 'groups'): ?>
                <div class="tab-pane active">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h5>Gruppi Geni</h5>
                        <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" 
                                data-bs-target="#addGroupModal">
                            <i class="bi bi-plus-circle"></i> Nuovo Gruppo
                        </button>
                    </div>
                    
                    <div class="table-responsive">
                        <table class="table table-striped">
                            <thead>
                                <tr>
                                    <th>Nome</th>
                                    <th>Descrizione</th>
                                    <th>Ordine</th>
                                    <th>N° Geni</th>
                                    <th>Azioni</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($gruppiGeni as $gruppo): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($gruppo['nome']); ?></td>
                                    <td><?php echo htmlspecialchars($gruppo['descrizione'] ?? '-'); ?></td>
                                    <td><?php echo $gruppo['ordine']; ?></td>
                                    <td>
                                        <span class="badge bg-secondary">
                                            <?php echo $gruppo['num_geni']; ?> geni
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($gruppo['num_geni'] == 0): ?>
                                        <form method="post" class="d-inline" 
                                              onsubmit="return confirm('Eliminare il gruppo?');">
                                            <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                                            <input type="hidden" name="section" value="groups">
                                            <input type="hidden" name="action" value="delete_group">
                                            <input type="hidden" name="group_id" value="<?php echo $gruppo['id']; ?>">
                                            <button type="submit" class="btn btn-sm btn-danger">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </form>
                                        <?php else: ?>
                                        <button type="button" class="btn btn-sm btn-secondary" disabled 
                                                title="Gruppo con geni associati">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </main>
    </div>
</div>

<!-- Modal Aggiungi Gruppo -->
<div class="modal fade" id="addGroupModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Nuovo Gruppo Geni</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                <input type="hidden" name="section" value="groups">
                <input type="hidden" name="action" value="add_group">
                
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="nome" class="form-label">Nome *</label>
                        <input type="text" class="form-control" id="nome" name="nome" required>
                    </div>
                    
                    <div class="mb-3">
                        <label for="descrizione" class="form-label">Descrizione</label>
                        <textarea class="form-control" id="descrizione" name="descrizione" rows="3"></textarea>
                    </div>
                    
                    <div class="mb-3">
                        <label for="ordine" class="form-label">Ordine</label>
                        <input type="number" class="form-control" id="ordine" name="ordine" 
                               value="0" min="0">
                        <small class="text-muted">Ordine di visualizzazione nei referti</small>
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

<script>
// Gestione colori
document.getElementById('colore_primario').addEventListener('input', function() {
    this.nextElementSibling.value = this.value;
});

document.getElementById('colore_secondario').addEventListener('input', function() {
    this.nextElementSibling.value = this.value;
});

// Toggle impostazioni cloud
function toggleCloudSettings() {
    const enabled = document.getElementById('backup_cloud_enabled').checked;
    document.getElementById('cloudSettings').style.display = enabled ? '' : 'none';
}
</script>

<?php require_once '../templates/footer.php'; ?>