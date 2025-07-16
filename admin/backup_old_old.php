<?php
/**
 * Gestione Backup - Area Amministratore
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
$message = '';
$messageType = '';

// Directory backup
$backupDir = dirname(__DIR__) . '/backup/';
$dbBackupDir = $backupDir . 'database/';
$filesBackupDir = $backupDir . 'files/';

// Crea directory se non esistono
if (!is_dir($dbBackupDir)) {
    mkdir($dbBackupDir, 0755, true);
}
if (!is_dir($filesBackupDir)) {
    mkdir($filesBackupDir, 0755, true);
}

// Gestione azioni
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? '';
    
    if (!$session->validateCsrfToken($csrfToken)) {
        $message = 'Token di sicurezza non valido';
        $messageType = 'error';
    } else {
        $action = $_POST['action'] ?? '';
        
        switch ($action) {
            case 'backup_database':
                $result = backupDatabase();
                if ($result['success']) {
                    $message = 'Backup database creato con successo: ' . $result['filename'];
                    $messageType = 'success';
                    $logger->log($user->getId(), 'backup_database', $result['filename']);
                } else {
                    $message = 'Errore nel backup database: ' . $result['error'];
                    $messageType = 'error';
                }
                break;
                
            case 'backup_files':
                $result = backupFiles();
                if ($result['success']) {
                    $message = 'Backup file creato con successo: ' . $result['filename'];
                    $messageType = 'success';
                    $logger->log($user->getId(), 'backup_files', $result['filename']);
                } else {
                    $message = 'Errore nel backup file: ' . $result['error'];
                    $messageType = 'error';
                }
                break;
                
            case 'backup_full':
                $dbResult = backupDatabase();
                $filesResult = backupFiles();
                
                if ($dbResult['success'] && $filesResult['success']) {
                    $message = 'Backup completo creato con successo';
                    $messageType = 'success';
                    $logger->log($user->getId(), 'backup_completo', '');
                } else {
                    $errors = [];
                    if (!$dbResult['success']) $errors[] = 'Database: ' . $dbResult['error'];
                    if (!$filesResult['success']) $errors[] = 'File: ' . $filesResult['error'];
                    $message = 'Errori nel backup: ' . implode(', ', $errors);
                    $messageType = 'error';
                }
                break;
                
            case 'delete_backup':
                $filename = $_POST['filename'] ?? '';
                $type = $_POST['type'] ?? '';
                
                if ($type === 'database') {
                    $filepath = $dbBackupDir . basename($filename);
                } else {
                    $filepath = $filesBackupDir . basename($filename);
                }
                
                if (file_exists($filepath) && unlink($filepath)) {
                    $message = 'Backup eliminato con successo';
                    $messageType = 'success';
                    $logger->log($user->getId(), 'backup_eliminato', $filename);
                } else {
                    $message = 'Errore nell\'eliminazione del backup';
                    $messageType = 'error';
                }
                break;
                
            case 'download_backup':
                $filename = $_POST['filename'] ?? '';
                $type = $_POST['type'] ?? '';
                
                if ($type === 'database') {
                    $filepath = $dbBackupDir . basename($filename);
                } else {
                    $filepath = $filesBackupDir . basename($filename);
                }
                
                if (file_exists($filepath)) {
                    header('Content-Type: application/octet-stream');
                    header('Content-Disposition: attachment; filename="' . basename($filepath) . '"');
                    header('Content-Length: ' . filesize($filepath));
                    readfile($filepath);
                    exit;
                } else {
                    $message = 'File backup non trovato';
                    $messageType = 'error';
                }
                break;
        }
    }
}

// Funzione backup database
function backupDatabase() {
    global $db, $dbBackupDir;
    
    try {
        // Carica configurazione database
        require dirname(__DIR__) . '/config/config.php';
        
        $filename = 'db_backup_' . date('Y-m-d_His') . '.sql';
        $filepath = $dbBackupDir . $filename;
        
        // Comando mysqldump
        $command = sprintf(
            'mysqldump --user=%s --password=%s --host=%s %s > %s 2>&1',
            escapeshellarg(DB_USER),
            escapeshellarg(DB_PASS),
            escapeshellarg(DB_HOST),
            escapeshellarg(DB_NAME),
            escapeshellarg($filepath)
        );
        
        exec($command, $output, $return);
        
        if ($return === 0 && file_exists($filepath) && filesize($filepath) > 0) {
            // Comprimi il file
            $gzFile = $filepath . '.gz';
            $fp = gzopen($gzFile, 'w9');
            gzwrite($fp, file_get_contents($filepath));
            gzclose($fp);
            
            // Rimuovi file non compresso
            unlink($filepath);
            
            return ['success' => true, 'filename' => $filename . '.gz'];
        } else {
            return ['success' => false, 'error' => 'Comando mysqldump fallito'];
        }
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

// Funzione backup file
function backupFiles() {
    global $filesBackupDir;
    
    try {
        $filename = 'files_backup_' . date('Y-m-d_His') . '.zip';
        $filepath = $filesBackupDir . $filename;
        
        $zip = new ZipArchive();
        if ($zip->open($filepath, ZipArchive::CREATE) !== TRUE) {
            return ['success' => false, 'error' => 'Impossibile creare archivio ZIP'];
        }
        
        // Directory da includere nel backup
        $directories = [
            'uploads/referti',
            'uploads/report', 
            'uploads/fatture',
            'uploads/firme'
        ];
        
        $baseDir = dirname(__DIR__) . '/';
        
        foreach ($directories as $dir) {
            $fullPath = $baseDir . $dir;
            if (is_dir($fullPath)) {
                addDirectoryToZip($zip, $fullPath, $dir);
            }
        }
        
        $zip->close();
        
        if (file_exists($filepath) && filesize($filepath) > 0) {
            return ['success' => true, 'filename' => $filename];
        } else {
            return ['success' => false, 'error' => 'Archivio ZIP vuoto'];
        }
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

// Funzione helper per aggiungere directory a ZIP
function addDirectoryToZip($zip, $dir, $zipPath) {
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir),
        RecursiveIteratorIterator::LEAVES_ONLY
    );
    
    foreach ($files as $file) {
        if (!$file->isDir()) {
            $filePath = $file->getRealPath();
            $relativePath = $zipPath . '/' . substr($filePath, strlen($dir) + 1);
            $zip->addFile($filePath, $relativePath);
        }
    }
}

// Carica lista backup esistenti
$dbBackups = [];
$fileBackups = [];

// Backup database
if (is_dir($dbBackupDir)) {
    $files = scandir($dbBackupDir);
    foreach ($files as $file) {
        if (preg_match('/^db_backup_.*\.(sql\.gz|sql)$/', $file)) {
            $dbBackups[] = [
                'filename' => $file,
                'size' => filesize($dbBackupDir . $file),
                'date' => filemtime($dbBackupDir . $file)
            ];
        }
    }
}

// Backup file
if (is_dir($filesBackupDir)) {
    $files = scandir($filesBackupDir);
    foreach ($files as $file) {
        if (preg_match('/^files_backup_.*\.zip$/', $file)) {
            $fileBackups[] = [
                'filename' => $file,
                'size' => filesize($filesBackupDir . $file),
                'date' => filemtime($filesBackupDir . $file)
            ];
        }
    }
}

// Ordina per data decrescente
usort($dbBackups, function($a, $b) { return $b['date'] - $a['date']; });
usort($fileBackups, function($a, $b) { return $b['date'] - $a['date']; });

// Carica configurazione backup
$config = [];
$configRows = $db->select("SELECT chiave, valore FROM configurazione WHERE chiave LIKE 'backup_%'");
foreach ($configRows as $row) {
    $config[$row['chiave']] = $row['valore'];
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
                <h1 class="h2">Gestione Backup</h1>
            </div>
            
            <?php if ($message): ?>
                <div class="alert alert-<?php echo $messageType === 'error' ? 'danger' : $messageType; ?> alert-dismissible fade show" role="alert">
                    <?php echo htmlspecialchars($message); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
            
            <!-- Stato backup -->
            <div class="row mb-4">
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-body">
                            <h5 class="card-title">
                                <i class="bi bi-hdd"></i> Backup Locale
                            </h5>
                            <p class="card-text">
                                Stato: 
                                <?php if (($config['backup_locale_enabled'] ?? '1') == '1'): ?>
                                    <span class="badge bg-success">Abilitato</span>
                                <?php else: ?>
                                    <span class="badge bg-danger">Disabilitato</span>
                                <?php endif; ?>
                            </p>
                            <p class="card-text">
                                <small class="text-muted">
                                    Backup database: <?php echo count($dbBackups); ?><br>
                                    Backup file: <?php echo count($fileBackups); ?>
                                </small>
                            </p>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-body">
                            <h5 class="card-title">
                                <i class="bi bi-cloud"></i> Backup Cloud
                            </h5>
                            <p class="card-text">
                                Stato: 
                                <?php if (($config['backup_cloud_enabled'] ?? '0') == '1'): ?>
                                    <span class="badge bg-success">Abilitato</span>
                                    <small class="text-muted">
                                        (<?php echo htmlspecialchars($config['backup_cloud_provider'] ?? ''); ?>)
                                    </small>
                                <?php else: ?>
                                    <span class="badge bg-danger">Disabilitato</span>
                                <?php endif; ?>
                            </p>
                            <p class="card-text">
                                <small class="text-muted">
                                    Configurare nelle impostazioni sistema
                                </small>
                            </p>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Azioni backup -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0">Crea Nuovo Backup</h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-4">
                            <form method="post" class="d-inline">
                                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                                <input type="hidden" name="action" value="backup_database">
                                <button type="submit" class="btn btn-primary w-100">
                                    <i class="bi bi-database"></i> Backup Database
                                </button>
                            </form>
                        </div>
                        <div class="col-md-4">
                            <form method="post" class="d-inline">
                                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                                <input type="hidden" name="action" value="backup_files">
                                <button type="submit" class="btn btn-primary w-100">
                                    <i class="bi bi-file-earmark-zip"></i> Backup File
                                </button>
                            </form>
                        </div>
                        <div class="col-md-4">
                            <form method="post" class="d-inline">
                                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                                <input type="hidden" name="action" value="backup_full">
                                <button type="submit" class="btn btn-success w-100">
                                    <i class="bi bi-arrow-repeat"></i> Backup Completo
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Lista backup database -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0">Backup Database</h5>
                </div>
                <div class="card-body">
                    <?php if (empty($dbBackups)): ?>
                        <p class="text-muted">Nessun backup database presente</p>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-sm">
                                <thead>
                                    <tr>
                                        <th>File</th>
                                        <th>Data</th>
                                        <th>Dimensione</th>
                                        <th>Azioni</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($dbBackups as $backup): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($backup['filename']); ?></td>
                                        <td><?php echo date('d/m/Y H:i', $backup['date']); ?></td>
                                        <td><?php echo formatFileSize($backup['size']); ?></td>
                                        <td>
                                            <form method="post" class="d-inline">
                                                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                                                <input type="hidden" name="action" value="download_backup">
                                                <input type="hidden" name="type" value="database">
                                                <input type="hidden" name="filename" value="<?php echo htmlspecialchars($backup['filename']); ?>">
                                                <button type="submit" class="btn btn-sm btn-info" title="Download">
                                                    <i class="bi bi-download"></i>
                                                </button>
                                            </form>
                                            <form method="post" class="d-inline" 
                                                  onsubmit="return confirm('Eliminare questo backup?');">
                                                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                                                <input type="hidden" name="action" value="delete_backup">
                                                <input type="hidden" name="type" value="database">
                                                <input type="hidden" name="filename" value="<?php echo htmlspecialchars($backup['filename']); ?>">
                                                <button type="submit" class="btn btn-sm btn-danger" title="Elimina">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Lista backup file -->
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">Backup File</h5>
                </div>
                <div class="card-body">
                    <?php if (empty($fileBackups)): ?>
                        <p class="text-muted">Nessun backup file presente</p>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-sm">
                                <thead>
                                    <tr>
                                        <th>File</th>
                                        <th>Data</th>
                                        <th>Dimensione</th>
                                        <th>Azioni</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($fileBackups as $backup): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($backup['filename']); ?></td>
                                        <td><?php echo date('d/m/Y H:i', $backup['date']); ?></td>
                                        <td><?php echo formatFileSize($backup['size']); ?></td>
                                        <td>
                                            <form method="post" class="d-inline">
                                                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                                                <input type="hidden" name="action" value="download_backup">
                                                <input type="hidden" name="type" value="files">
                                                <input type="hidden" name="filename" value="<?php echo htmlspecialchars($backup['filename']); ?>">
                                                <button type="submit" class="btn btn-sm btn-info" title="Download">
                                                    <i class="bi bi-download"></i>
                                                </button>
                                            </form>
                                            <form method="post" class="d-inline" 
                                                  onsubmit="return confirm('Eliminare questo backup?');">
                                                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                                                <input type="hidden" name="action" value="delete_backup">
                                                <input type="hidden" name="type" value="files">
                                                <input type="hidden" name="filename" value="<?php echo htmlspecialchars($backup['filename']); ?>">
                                                <button type="submit" class="btn btn-sm btn-danger" title="Elimina">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="alert alert-info mt-4">
                <i class="bi bi-info-circle"></i>
                <strong>Nota:</strong> Si consiglia di eseguire backup regolari e di conservare copie 
                dei backup in luoghi sicuri. I backup automatici, se abilitati, vengono eseguiti 
                giornalmente alle 2:00 di notte.
            </div>
        </main>
    </div>
</div>

<?php require_once '../templates/footer.php'; ?>
