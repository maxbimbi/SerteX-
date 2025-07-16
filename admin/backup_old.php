<?php
session_start();
require_once '../includes/auth.php';
require_once '../includes/database.php';
require_once '../includes/functions.php';
require_once '../includes/security.php';

// Verifica autenticazione e autorizzazione
if (!Auth::check() || Auth::getUserType() !== 'amministratore') {
    redirect('/index.php');
}

$db = Database::getInstance()->getConnection();
$user = Auth::getUser();

// Gestione azioni
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    switch ($action) {
        case 'create_backup':
            $tipo = $_POST['tipo'] ?? 'completo';
            $result = createBackup($db, $tipo);
            
            if ($result['success']) {
                $_SESSION['success'] = "Backup creato con successo: " . $result['filename'];
                logActivity($db, $user['id'], 'backup_created', "Tipo: $tipo, File: " . $result['filename']);
            } else {
                $_SESSION['error'] = "Errore durante il backup: " . $result['error'];
            }
            break;
            
        case 'restore_backup':
            $filename = $_POST['filename'] ?? '';
            if (!isAuthorizedBackupFile($filename)) {
                $_SESSION['error'] = "File di backup non valido.";
            } else {
                $result = restoreBackup($db, $filename);
                if ($result['success']) {
                    $_SESSION['success'] = "Backup ripristinato con successo.";
                    logActivity($db, $user['id'], 'backup_restored', "File: $filename");
                } else {
                    $_SESSION['error'] = "Errore durante il ripristino: " . $result['error'];
                }
            }
            break;
            
        case 'delete_backup':
            $filename = $_POST['filename'] ?? '';
            if (!isAuthorizedBackupFile($filename)) {
                $_SESSION['error'] = "File di backup non valido.";
            } else {
                $filepath = BACKUP_PATH . '/' . $filename;
                if (file_exists($filepath) && unlink($filepath)) {
                    $_SESSION['success'] = "Backup eliminato con successo.";
                    logActivity($db, $user['id'], 'backup_deleted', "File: $filename");
                } else {
                    $_SESSION['error'] = "Errore durante l'eliminazione del backup.";
                }
            }
            break;
            
        case 'upload_to_cloud':
            $filename = $_POST['filename'] ?? '';
            if (BACKUP_CLOUD_ENABLED && isAuthorizedBackupFile($filename)) {
                $result = uploadBackupToCloud($filename);
                if ($result['success']) {
                    $_SESSION['success'] = "Backup caricato sul cloud con successo.";
                    logActivity($db, $user['id'], 'backup_cloud_upload', "File: $filename");
                } else {
                    $_SESSION['error'] = "Errore durante il caricamento: " . $result['error'];
                }
            }
            break;
            
        case 'update_settings':
            $settings = [
                'backup_locale_enabled' => isset($_POST['backup_locale_enabled']) ? '1' : '0',
                'backup_cloud_enabled' => isset($_POST['backup_cloud_enabled']) ? '1' : '0',
                'backup_schedule' => $_POST['backup_schedule'] ?? 'daily',
                'backup_retention_days' => (int)($_POST['backup_retention_days'] ?? 30),
                'backup_cloud_provider' => $_POST['backup_cloud_provider'] ?? '',
                'backup_cloud_access_key' => $_POST['backup_cloud_access_key'] ?? '',
                'backup_cloud_secret_key' => $_POST['backup_cloud_secret_key'] ?? '',
                'backup_cloud_bucket' => $_POST['backup_cloud_bucket'] ?? ''
            ];
            
            foreach ($settings as $key => $value) {
                setConfig($db, $key, $value);
            }
            
            $_SESSION['success'] = "Impostazioni backup aggiornate.";
            logActivity($db, $user['id'], 'backup_settings_updated');
            break;
    }
    
    redirect('backup.php');
}

// Recupera lista backup
$backups = getBackupsList();

// Recupera impostazioni
$settings = [
    'backup_locale_enabled' => getConfig($db, 'backup_locale_enabled', '1'),
    'backup_cloud_enabled' => getConfig($db, 'backup_cloud_enabled', '0'),
    'backup_schedule' => getConfig($db, 'backup_schedule', 'daily'),
    'backup_retention_days' => getConfig($db, 'backup_retention_days', 30),
    'backup_cloud_provider' => getConfig($db, 'backup_cloud_provider', ''),
    'backup_last_run' => getConfig($db, 'backup_last_run', ''),
    'backup_next_run' => getConfig($db, 'backup_next_run', '')
];

// Funzioni helper
function createBackup($db, $tipo = 'completo') {
    ensureDirectory(BACKUP_PATH);
    
    $timestamp = date('Ymd_His');
    $backup_name = "backup_{$tipo}_{$timestamp}";
    $backup_dir = BACKUP_PATH . '/' . $backup_name;
    
    try {
        mkdir($backup_dir, 0755, true);
        
        // Backup database
        $db_file = $backup_dir . '/database.sql';
        $db_result = backupDatabase($db, $db_file);
        if (!$db_result['success']) {
            throw new Exception($db_result['error']);
        }
        
        // Backup files se completo
        if ($tipo === 'completo') {
            // Backup uploads
            $uploads_result = backupDirectory(UPLOAD_PATH, $backup_dir . '/uploads');
            if (!$uploads_result['success']) {
                throw new Exception($uploads_result['error']);
            }
            
            // Backup config
            copy(APP_PATH . '/config/config.php', $backup_dir . '/config.php');
        }
        
        // Crea archivio ZIP
        $zip_file = BACKUP_PATH . '/' . $backup_name . '.zip';
        $zip_result = createZipArchive($backup_dir, $zip_file);
        
        // Rimuovi directory temporanea
        removeDirectory($backup_dir);
        
        if (!$zip_result['success']) {
            throw new Exception($zip_result['error']);
        }
        
        // Pulizia vecchi backup
        cleanOldBackups();
        
        return [
            'success' => true,
            'filename' => $backup_name . '.zip',
            'size' => filesize($zip_file)
        ];
        
    } catch (Exception $e) {
        if (is_dir($backup_dir)) {
            removeDirectory($backup_dir);
        }
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

function backupDatabase($db, $output_file) {
    try {
        $tables = [];
        $result = $db->query("SHOW TABLES");
        while ($row = $result->fetch(PDO::FETCH_NUM)) {
            $tables[] = $row[0];
        }
        
        $output = "-- SerteX+ Database Backup\n";
        $output .= "-- Generated: " . date('Y-m-d H:i:s') . "\n\n";
        
        foreach ($tables as $table) {
            // Struttura tabella
            $result = $db->query("SHOW CREATE TABLE `$table`");
            $row = $result->fetch(PDO::FETCH_NUM);
            $output .= "\n-- Table structure for `$table`\n";
            $output .= "DROP TABLE IF EXISTS `$table`;\n";
            $output .= $row[1] . ";\n\n";
            
            // Dati tabella
            $result = $db->query("SELECT * FROM `$table`");
            $num_fields = $result->columnCount();
            
            if ($result->rowCount() > 0) {
                $output .= "-- Data for table `$table`\n";
                $output .= "INSERT INTO `$table` VALUES\n";
                
                $rows = [];
                while ($row = $result->fetch(PDO::FETCH_NUM)) {
                    $values = [];
                    for ($i = 0; $i < $num_fields; $i++) {
                        if ($row[$i] === null) {
                            $values[] = 'NULL';
                        } elseif (is_numeric($row[$i])) {
                            $values[] = $row[$i];
                        } else {
                            $values[] = "'" . addslashes($row[$i]) . "'";
                        }
                    }
                    $rows[] = "(" . implode(", ", $values) . ")";
                }
                $output .= implode(",\n", $rows) . ";\n";
            }
        }
        
        file_put_contents($output_file, $output);
        return ['success' => true];
        
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

function backupDirectory($source, $destination) {
    try {
        if (!is_dir($source)) {
            return ['success' => false, 'error' => 'Directory sorgente non trovata'];
        }
        
        mkdir($destination, 0755, true);
        
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        
        foreach ($iterator as $item) {
            $dest_path = $destination . '/' . $iterator->getSubPathName();
            if ($item->isDir()) {
                mkdir($dest_path, 0755, true);
            } else {
                copy($item, $dest_path);
            }
        }
        
        return ['success' => true];
        
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

function createZipArchive($source, $destination) {
    try {
        $zip = new ZipArchive();
        if ($zip->open($destination, ZipArchive::CREATE) !== TRUE) {
            return ['success' => false, 'error' => 'Impossibile creare archivio ZIP'];
        }
        
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        
        foreach ($iterator as $file) {
            $file = realpath($file);
            if (is_dir($file)) {
                $zip->addEmptyDir(str_replace($source . '/', '', $file . '/'));
            } elseif (is_file($file)) {
                $zip->addFromString(str_replace($source . '/', '', $file), file_get_contents($file));
            }
        }
        
        $zip->close();
        return ['success' => true];
        
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

function restoreBackup($db, $filename) {
    $filepath = BACKUP_PATH . '/' . $filename;
    
    if (!file_exists($filepath)) {
        return ['success' => false, 'error' => 'File di backup non trovato'];
    }
    
    try {
        // Estrai ZIP in directory temporanea
        $temp_dir = BACKUP_PATH . '/restore_temp_' . time();
        mkdir($temp_dir, 0755, true);
        
        $zip = new ZipArchive();
        if ($zip->open($filepath) !== TRUE) {
            throw new Exception('Impossibile aprire file ZIP');
        }
        
        $zip->extractTo($temp_dir);
        $zip->close();
        
        // Ripristina database
        $sql_file = $temp_dir . '/database.sql';
        if (file_exists($sql_file)) {
            $sql = file_get_contents($sql_file);
            $db->exec($sql);
        }
        
        // Ripristina files se presenti
        if (is_dir($temp_dir . '/uploads')) {
            // Backup directory corrente
            $backup_current = UPLOAD_PATH . '_backup_' . time();
            rename(UPLOAD_PATH, $backup_current);
            
            // Ripristina nuovi files
            rename($temp_dir . '/uploads', UPLOAD_PATH);
        }
        
        // Rimuovi directory temporanea
        removeDirectory($temp_dir);
        
        return ['success' => true];
        
    } catch (Exception $e) {
        if (isset($temp_dir) && is_dir($temp_dir)) {
            removeDirectory($temp_dir);
        }
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

function getBackupsList() {
    $backups = [];
    
    if (!is_dir(BACKUP_PATH)) {
        return $backups;
    }
    
    $files = glob(BACKUP_PATH . '/backup_*.zip');
    foreach ($files as $file) {
        $filename = basename($file);
        $backups[] = [
            'filename' => $filename,
            'size' => filesize($file),
            'date' => filemtime($file),
            'type' => strpos($filename, 'completo') !== false ? 'Completo' : 'Database'
        ];
    }
    
    // Ordina per data decrescente
    usort($backups, function($a, $b) {
        return $b['date'] - $a['date'];
    });
    
    return $backups;
}

function isAuthorizedBackupFile($filename) {
    return preg_match('/^backup_(completo|database)_\d{8}_\d{6}\.zip$/', $filename);
}

function cleanOldBackups() {
    $retention_days = (int)getConfig($GLOBALS['db'], 'backup_retention_days', 30);
    $cutoff_time = time() - ($retention_days * 86400);
    
    $files = glob(BACKUP_PATH . '/backup_*.zip');
    foreach ($files as $file) {
        if (filemtime($file) < $cutoff_time) {
            unlink($file);
        }
    }
}

function removeDirectory($dir) {
    if (!is_dir($dir)) return;
    
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    
    foreach ($iterator as $file) {
        if ($file->isDir()) {
            rmdir($file->getRealPath());
        } else {
            unlink($file->getRealPath());
        }
    }
    
    rmdir($dir);
}

function uploadBackupToCloud($filename) {
    // Implementazione specifica per provider cloud
    // AWS S3, Google Cloud Storage, Azure Blob Storage, etc.
    return ['success' => false, 'error' => 'Funzionalità non ancora implementata'];
}

$page_title = 'Gestione Backup';
require_once '../templates/header.php';
require_once '../templates/sidebar.php';
?>

<div class="container-fluid">
    <div class="row">
        <div class="col-md-12">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h1>Gestione Backup</h1>
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createBackupModal">
                    <i class="fas fa-plus"></i> Crea Backup
                </button>
            </div>

            <?php if (isset($_SESSION['success'])): ?>
                <div class="alert alert-success alert-dismissible fade show">
                    <?php 
                    echo $_SESSION['success'];
                    unset($_SESSION['success']);
                    ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <?php if (isset($_SESSION['error'])): ?>
                <div class="alert alert-danger alert-dismissible fade show">
                    <?php 
                    echo $_SESSION['error'];
                    unset($_SESSION['error']);
                    ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <!-- Impostazioni Backup -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0">Impostazioni Backup</h5>
                </div>
                <div class="card-body">
                    <form method="POST">
                        <input type="hidden" name="action" value="update_settings">
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-check mb-3">
                                    <input type="checkbox" class="form-check-input" id="backup_locale_enabled" 
                                           name="backup_locale_enabled" value="1"
                                           <?php echo $settings['backup_locale_enabled'] ? 'checked' : ''; ?>>
                                    <label class="form-check-label" for="backup_locale_enabled">
                                        Backup locale abilitato
                                    </label>
                                </div>
                                
                                <div class="mb-3">
                                    <label class="form-label">Frequenza backup automatico</label>
                                    <select name="backup_schedule" class="form-select">
                                        <option value="daily" <?php echo $settings['backup_schedule'] === 'daily' ? 'selected' : ''; ?>>Giornaliero</option>
                                        <option value="weekly" <?php echo $settings['backup_schedule'] === 'weekly' ? 'selected' : ''; ?>>Settimanale</option>
                                        <option value="monthly" <?php echo $settings['backup_schedule'] === 'monthly' ? 'selected' : ''; ?>>Mensile</option>
                                    </select>
                                </div>
                                
                                <div class="mb-3">
                                    <label class="form-label">Giorni di conservazione</label>
                                    <input type="number" name="backup_retention_days" class="form-control" 
                                           value="<?php echo $settings['backup_retention_days']; ?>" min="1" max="365">
                                </div>
                            </div>
                            
                            <div class="col-md-6">
                                <div class="form-check mb-3">
                                    <input type="checkbox" class="form-check-input" id="backup_cloud_enabled" 
                                           name="backup_cloud_enabled" value="1"
                                           <?php echo $settings['backup_cloud_enabled'] ? 'checked' : ''; ?>>
                                    <label class="form-check-label" for="backup_cloud_enabled">
                                        Backup cloud abilitato
                                    </label>
                                </div>
                                
                                <div class="mb-3">
                                    <label class="form-label">Provider Cloud</label>
                                    <select name="backup_cloud_provider" class="form-select">
                                        <option value="">-- Seleziona --</option>
                                        <option value="aws" <?php echo $settings['backup_cloud_provider'] === 'aws' ? 'selected' : ''; ?>>Amazon S3</option>
                                        <option value="google" <?php echo $settings['backup_cloud_provider'] === 'google' ? 'selected' : ''; ?>>Google Cloud Storage</option>
                                        <option value="azure" <?php echo $settings['backup_cloud_provider'] === 'azure' ? 'selected' : ''; ?>>Azure Blob Storage</option>
                                    </select>
                                </div>
                                
                                <?php if ($settings['backup_last_run']): ?>
                                    <p class="text-muted">
                                        <i class="fas fa-clock"></i> Ultimo backup: 
                                        <?php echo date('d/m/Y H:i', strtotime($settings['backup_last_run'])); ?>
                                    </p>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <div class="text-end">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save"></i> Salva Impostazioni
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Lista Backup -->
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">Backup Disponibili</h5>
                </div>
                <div class="card-body">
                    <?php if (empty($backups)): ?>
                        <p class="text-muted text-center">Nessun backup disponibile.</p>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-striped">
                                <thead>
                                    <tr>
                                        <th>Nome File</th>
                                        <th>Tipo</th>
                                        <th>Dimensione</th>
                                        <th>Data</th>
                                        <th>Azioni</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($backups as $backup): ?>
                                        <tr>
                                            <td>
                                                <i class="fas fa-file-archive text-primary"></i>
                                                <?php echo htmlspecialchars($backup['filename']); ?>
                                            </td>
                                            <td>
                                                <span class="badge bg-<?php echo $backup['type'] === 'Completo' ? 'success' : 'info'; ?>">
                                                    <?php echo $backup['type']; ?>
                                                </span>
                                            </td>
                                            <td><?php echo formatFileSize($backup['size']); ?></td>
                                            <td><?php echo date('d/m/Y H:i', $backup['date']); ?></td>
                                            <td>
                                                <div class="btn-group btn-group-sm">
                                                    <a href="<?php echo BACKUP_PATH . '/' . $backup['filename']; ?>" 
                                                       class="btn btn-outline-primary" download title="Download">
                                                        <i class="fas fa-download"></i>
                                                    </a>
                                                    
                                                    <button class="btn btn-outline-warning" 
                                                            onclick="restoreBackup('<?php echo $backup['filename']; ?>')"
                                                            title="Ripristina">
                                                        <i class="fas fa-undo"></i>
                                                    </button>
                                                    
                                                    <?php if ($settings['backup_cloud_enabled']): ?>
                                                        <form method="POST" style="display: inline;">
                                                            <input type="hidden" name="action" value="upload_to_cloud">
                                                            <input type="hidden" name="filename" value="<?php echo $backup['filename']; ?>">
                                                            <button type="submit" class="btn btn-outline-info" title="Upload su Cloud">
                                                                <i class="fas fa-cloud-upload-alt"></i>
                                                            </button>
                                                        </form>
                                                    <?php endif; ?>
                                                    
                                                    <button class="btn btn-outline-danger" 
                                                            onclick="deleteBackup('<?php echo $backup['filename']; ?>')"
                                                            title="Elimina">
                                                        <i class="fas fa-trash"></i>
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal Crea Backup -->
<div class="modal fade" id="createBackupModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" value="create_backup">
                <div class="modal-header">
                    <h5 class="modal-title">Crea Nuovo Backup</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Tipo di Backup</label>
                        <select name="tipo" class="form-select" required>
                            <option value="completo">Completo (Database + Files)</option>
                            <option value="database">Solo Database</option>
                        </select>
                    </div>
                    
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle"></i>
                        <strong>Nota:</strong> Il backup completo include database, referti, uploads e configurazioni.
                        Può richiedere alcuni minuti a seconda della dimensione dei dati.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annulla</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-play"></i> Avvia Backup
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function restoreBackup(filename) {
    if (!confirm('ATTENZIONE: Il ripristino sovrascriverà tutti i dati correnti.\n\nSei sicuro di voler procedere?')) {
        return;
    }
    
    const form = document.createElement('form');
    form.method = 'POST';
    form.innerHTML = `
        <input type="hidden" name="action" value="restore_backup">
        <input type="hidden" name="filename" value="${filename}">
    `;
    document.body.appendChild(form);
    form.submit();
}

function deleteBackup(filename) {
    if (!confirm('Sei sicuro di voler eliminare questo backup?')) {
        return;
    }
    
    const form = document.createElement('form');
    form.method = 'POST';
    form.innerHTML = `
        <input type="hidden" name="action" value="delete_backup">
        <input type="hidden" name="filename" value="${filename}">
    `;
    document.body.appendChild(form);
    form.submit();
}
</script>

<?php require_once '../templates/footer.php'; ?>