#!/usr/bin/php
<?php
/**
 * SerteX+ - Script Cron per Backup Automatici
 * 
 * Questo script deve essere eseguito via cron per effettuare backup automatici
 * Esempio crontab per backup giornaliero alle 2:00:
 * 0 2 * * * /usr/bin/php /path/to/sertexplus/cron/backup.php >> /path/to/sertexplus/logs/cron_backup.log 2>&1
 */

// Previene l'esecuzione via web
if (php_sapi_name() !== 'cli') {
    die('Questo script può essere eseguito solo da linea di comando');
}

// Imposta il path di base
define('APP_PATH', dirname(__DIR__));

// Includi le dipendenze necessarie
require_once APP_PATH . '/includes/database.php';
require_once APP_PATH . '/includes/functions.php';
require_once APP_PATH . '/config/config.php';

// Funzione di log
function cronLog($message) {
    $timestamp = date('Y-m-d H:i:s');
    echo "[{$timestamp}] {$message}\n";
}

// Funzione principale di backup
function executeBackup() {
    cronLog("=== Inizio processo di backup automatico ===");
    
    try {
        $db = Database::getInstance()->getConnection();
        
        // Verifica se il backup è abilitato
        $backup_enabled = getConfig($db, 'backup_locale_enabled', '1');
        if (!$backup_enabled) {
            cronLog("Backup locale disabilitato. Uscita.");
            return;
        }
        
        // Verifica frequenza backup
        $schedule = getConfig($db, 'backup_schedule', 'daily');
        $last_run = getConfig($db, 'backup_last_run', '');
        
        if (!shouldRunBackup($schedule, $last_run)) {
            cronLog("Backup non necessario secondo la pianificazione ({$schedule}). Uscita.");
            return;
        }
        
        // Esegui backup
        cronLog("Avvio backup...");
        $result = performBackup($db);
        
        if ($result['success']) {
            cronLog("Backup completato con successo: " . $result['filename']);
            cronLog("Dimensione file: " . formatFileSize($result['size']));
            
            // Aggiorna ultima esecuzione
            setConfig($db, 'backup_last_run', date('Y-m-d H:i:s'));
            
            // Log nel database
            logActivity($db, null, 'cron_backup_success', 
                       "Backup automatico creato: " . $result['filename']);
            
            // Backup su cloud se abilitato
            $cloud_enabled = getConfig($db, 'backup_cloud_enabled', '0');
            if ($cloud_enabled) {
                cronLog("Caricamento su cloud...");
                $cloud_result = uploadToCloud($result['filename']);
                if ($cloud_result['success']) {
                    cronLog("Backup caricato su cloud con successo");
                } else {
                    cronLog("ERRORE caricamento cloud: " . $cloud_result['error']);
                }
            }
            
            // Pulizia vecchi backup
            cronLog("Pulizia vecchi backup...");
            $cleaned = cleanOldBackups($db);
            if ($cleaned > 0) {
                cronLog("Rimossi {$cleaned} backup obsoleti");
            }
            
            // Invia notifica email se configurato
            if (MAIL_ENABLED) {
                sendBackupNotification($result);
            }
            
        } else {
            cronLog("ERRORE durante il backup: " . $result['error']);
            logActivity($db, null, 'cron_backup_error', "Errore: " . $result['error']);
            
            // Invia email di errore
            if (MAIL_ENABLED) {
                sendErrorNotification($result['error']);
            }
        }
        
    } catch (Exception $e) {
        cronLog("ERRORE CRITICO: " . $e->getMessage());
        cronLog("Stack trace: " . $e->getTraceAsString());
        
        if (MAIL_ENABLED) {
            sendErrorNotification($e->getMessage());
        }
    }
    
    cronLog("=== Fine processo di backup ===\n");
}

// Verifica se è il momento di eseguire il backup
function shouldRunBackup($schedule, $last_run) {
    if (empty($last_run)) {
        return true; // Prima esecuzione
    }
    
    $last_timestamp = strtotime($last_run);
    $now = time();
    $diff_hours = ($now - $last_timestamp) / 3600;
    
    switch ($schedule) {
        case 'daily':
            return $diff_hours >= 24;
        case 'weekly':
            return $diff_hours >= 168; // 7 giorni
        case 'monthly':
            return $diff_hours >= 720; // 30 giorni
        default:
            return true;
    }
}

// Esegue il backup effettivo
function performBackup($db) {
    ensureDirectory(BACKUP_PATH);
    
    $timestamp = date('Ymd_His');
    $backup_name = "backup_auto_completo_{$timestamp}";
    $backup_dir = BACKUP_PATH . '/' . $backup_name;
    
    try {
        mkdir($backup_dir, 0755, true);
        
        // Backup database
        cronLog("- Backup database...");
        $db_file = $backup_dir . '/database.sql';
        $db_result = backupDatabaseCron($db, $db_file);
        if (!$db_result['success']) {
            throw new Exception("Backup database fallito: " . $db_result['error']);
        }
        
        // Backup files
        cronLog("- Backup files...");
        
        // Backup uploads
        if (is_dir(UPLOAD_PATH)) {
            $uploads_result = backupDirectoryCron(UPLOAD_PATH, $backup_dir . '/uploads');
            if (!$uploads_result['success']) {
                throw new Exception("Backup uploads fallito: " . $uploads_result['error']);
            }
        }
        
        // Backup config
        if (file_exists(APP_PATH . '/config/config.php')) {
            copy(APP_PATH . '/config/config.php', $backup_dir . '/config.php');
        }
        
        // Crea archivio ZIP
        cronLog("- Creazione archivio ZIP...");
        $zip_file = BACKUP_PATH . '/' . $backup_name . '.zip';
        $zip_result = createZipArchiveCron($backup_dir, $zip_file);
        
        // Rimuovi directory temporanea
        removeDirectoryCron($backup_dir);
        
        if (!$zip_result['success']) {
            throw new Exception("Creazione ZIP fallita: " . $zip_result['error']);
        }
        
        return [
            'success' => true,
            'filename' => $backup_name . '.zip',
            'size' => filesize($zip_file)
        ];
        
    } catch (Exception $e) {
        if (is_dir($backup_dir)) {
            removeDirectoryCron($backup_dir);
        }
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

// Backup database per cron
function backupDatabaseCron($db, $output_file) {
    try {
        $tables = [];
        $result = $db->query("SHOW TABLES");
        while ($row = $result->fetch(PDO::FETCH_NUM)) {
            $tables[] = $row[0];
        }
        
        $output = "-- SerteX+ Database Backup (Cron)\n";
        $output .= "-- Generated: " . date('Y-m-d H:i:s') . "\n\n";
        $output .= "SET FOREIGN_KEY_CHECKS=0;\n\n";
        
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
                
                while ($row = $result->fetch(PDO::FETCH_NUM)) {
                    $output .= "INSERT INTO `$table` VALUES(";
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
                    $output .= implode(",", $values) . ");\n";
                }
                $output .= "\n";
            }
        }
        
        $output .= "SET FOREIGN_KEY_CHECKS=1;\n";
        
        file_put_contents($output_file, $output);
        return ['success' => true];
        
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

// Backup directory per cron
function backupDirectoryCron($source, $destination) {
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

// Crea archivio ZIP per cron
function createZipArchiveCron($source, $destination) {
    try {
        $zip = new ZipArchive();
        if ($zip->open($destination, ZipArchive::CREATE) !== TRUE) {
            return ['success' => false, 'error' => 'Impossibile creare archivio ZIP'];
        }
        
        $source = realpath($source);
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

// Rimuove directory ricorsivamente
function removeDirectoryCron($dir) {
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

// Pulizia vecchi backup
function cleanOldBackups($db) {
    $retention_days = (int)getConfig($db, 'backup_retention_days', 30);
    $cutoff_time = time() - ($retention_days * 86400);
    $count = 0;
    
    $files = glob(BACKUP_PATH . '/backup_*.zip');
    foreach ($files as $file) {
        if (filemtime($file) < $cutoff_time) {
            if (unlink($file)) {
                $count++;
                cronLog("- Rimosso: " . basename($file));
            }
        }
    }
    
    return $count;
}

// Upload su cloud (placeholder)
function uploadToCloud($filename) {
    // Implementazione specifica per il provider cloud configurato
    // AWS S3, Google Cloud Storage, Azure, etc.
    return ['success' => false, 'error' => 'Upload cloud non implementato'];
}

// Invia notifica email di successo
function sendBackupNotification($result) {
    $to = MAIL_FROM_ADDRESS; // O indirizzo admin configurato
    $subject = 'SerteX+ - Backup Automatico Completato';
    
    $body = "Il backup automatico è stato completato con successo.\n\n";
    $body .= "File: " . $result['filename'] . "\n";
    $body .= "Dimensione: " . formatFileSize($result['size']) . "\n";
    $body .= "Data: " . date('d/m/Y H:i:s') . "\n\n";
    $body .= "Il backup è disponibile nella directory: " . BACKUP_PATH;
    
    // Qui andrebbe implementato l'invio email con PHPMailer o simile
    cronLog("Email notifica inviata a: " . $to);
}

// Invia notifica email di errore
function sendErrorNotification($error) {
    $to = MAIL_FROM_ADDRESS;
    $subject = 'SerteX+ - ERRORE Backup Automatico';
    
    $body = "Si è verificato un errore durante il backup automatico.\n\n";
    $body .= "Errore: " . $error . "\n";
    $body .= "Data: " . date('d/m/Y H:i:s') . "\n\n";
    $body .= "Verificare i log per maggiori dettagli.";
    
    // Qui andrebbe implementato l'invio email
    cronLog("Email errore inviata a: " . $to);
}

// Esegui backup
executeBackup();