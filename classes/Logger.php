<?php
/**
 * SerteX+ - Classe Logger
 * Sistema di logging e audit trail
 */

namespace SerteX;

use PDO;
use Exception;

class Logger {
    private $db;
    private $logPath;
    private $currentLogFile;
    
    // Livelli di log
    const LEVEL_DEBUG = 'DEBUG';
    const LEVEL_INFO = 'INFO';
    const LEVEL_WARNING = 'WARNING';
    const LEVEL_ERROR = 'ERROR';
    const LEVEL_CRITICAL = 'CRITICAL';
    
    // Categorie di log
    const CATEGORY_AUTH = 'auth';
    const CATEGORY_USER = 'user';
    const CATEGORY_PATIENT = 'patient';
    const CATEGORY_TEST = 'test';
    const CATEGORY_REPORT = 'report';
    const CATEGORY_INVOICE = 'invoice';
    const CATEGORY_SYSTEM = 'system';
    const CATEGORY_SECURITY = 'security';
    const CATEGORY_API = 'api';
    
    /**
     * Costruttore
     * @param PDO $db
     * @param string|null $logPath
     */
    public function __construct(PDO $db, $logPath = null) {
        $this->db = $db;
        $this->logPath = $logPath ?: LOG_PATH;
        $this->ensureLogDirectory();
        $this->setCurrentLogFile();
    }
    
    /**
     * Registra un evento
     * @param string $level
     * @param string $category
     * @param string $action
     * @param string $message
     * @param array $context
     * @param int|null $userId
     * @return bool
     */
    public function log($level, $category, $action, $message, $context = [], $userId = null) {
        try {
            // Log su file
            $this->logToFile($level, $category, $action, $message, $context);
            
            // Log su database per eventi importanti
            if ($this->shouldLogToDatabase($level, $category)) {
                $this->logToDatabase($category, $action, $message, $context, $userId);
            }
            
            // Notifica per eventi critici
            if ($level === self::LEVEL_CRITICAL) {
                $this->sendCriticalAlert($category, $action, $message);
            }
            
            return true;
            
        } catch (Exception $e) {
            error_log("Errore logger: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Log di debug
     * @param string $category
     * @param string $message
     * @param array $context
     */
    public function debug($category, $message, $context = []) {
        $this->log(self::LEVEL_DEBUG, $category, 'debug', $message, $context);
    }
    
    /**
     * Log informativo
     * @param string $category
     * @param string $action
     * @param string $message
     * @param array $context
     */
    public function info($category, $action, $message, $context = []) {
        $userId = $_SESSION['user_id'] ?? null;
        $this->log(self::LEVEL_INFO, $category, $action, $message, $context, $userId);
    }
    
    /**
     * Log di warning
     * @param string $category
     * @param string $action
     * @param string $message
     * @param array $context
     */
    public function warning($category, $action, $message, $context = []) {
        $userId = $_SESSION['user_id'] ?? null;
        $this->log(self::LEVEL_WARNING, $category, $action, $message, $context, $userId);
    }
    
    /**
     * Log di errore
     * @param string $category
     * @param string $action
     * @param string $message
     * @param array $context
     */
    public function error($category, $action, $message, $context = []) {
        $userId = $_SESSION['user_id'] ?? null;
        $this->log(self::LEVEL_ERROR, $category, $action, $message, $context, $userId);
    }
    
    /**
     * Log critico
     * @param string $category
     * @param string $action
     * @param string $message
     * @param array $context
     */
    public function critical($category, $action, $message, $context = []) {
        $userId = $_SESSION['user_id'] ?? null;
        $this->log(self::LEVEL_CRITICAL, $category, $action, $message, $context, $userId);
    }
    
    /**
     * Log attività utente
     * @param int $userId
     * @param string $action
     * @param string $details
     * @return bool
     */
    public function logActivity($userId, $action, $details = '') {
        return $this->logToDatabase(self::CATEGORY_USER, $action, $details, [], $userId);
    }
    
    /**
     * Log accesso
     * @param int|null $userId
     * @param bool $success
     * @param string $username
     * @return bool
     */
    public function logAccess($userId, $success, $username = '') {
        $action = $success ? 'login_success' : 'login_failed';
        $details = $success ? 'Login effettuato' : "Tentativo fallito per: $username";
        
        return $this->log(
            $success ? self::LEVEL_INFO : self::LEVEL_WARNING,
            self::CATEGORY_AUTH,
            $action,
            $details,
            [
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
            ],
            $userId
        );
    }
    
    /**
     * Log operazione su paziente
     * @param string $operation
     * @param int $patientId
     * @param array $data
     * @return bool
     */
    public function logPatientOperation($operation, $patientId, $data = []) {
        return $this->info(
            self::CATEGORY_PATIENT,
            "patient_$operation",
            "Operazione $operation su paziente ID: $patientId",
            array_merge(['patient_id' => $patientId], $data)
        );
    }
    
    /**
     * Log operazione su test
     * @param string $operation
     * @param int $testId
     * @param array $data
     * @return bool
     */
    public function logTestOperation($operation, $testId, $data = []) {
        return $this->info(
            self::CATEGORY_TEST,
            "test_$operation",
            "Operazione $operation su test ID: $testId",
            array_merge(['test_id' => $testId], $data)
        );
    }
    
    /**
     * Log evento di sicurezza
     * @param string $event
     * @param string $details
     * @param array $context
     * @return bool
     */
    public function logSecurityEvent($event, $details, $context = []) {
        return $this->warning(
            self::CATEGORY_SECURITY,
            $event,
            $details,
            array_merge([
                'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
                'request_uri' => $_SERVER['REQUEST_URI'] ?? 'unknown'
            ], $context)
        );
    }
    
    /**
     * Log su file
     * @param string $level
     * @param string $category
     * @param string $action
     * @param string $message
     * @param array $context
     */
    private function logToFile($level, $category, $action, $message, $context = []) {
        $logEntry = sprintf(
            "[%s] %s | %s.%s | %s | %s\n",
            date('Y-m-d H:i:s'),
            $level,
            $category,
            $action,
            $message,
            !empty($context) ? json_encode($context) : '-'
        );
        
        // File di log principale
        file_put_contents($this->currentLogFile, $logEntry, FILE_APPEND | LOCK_EX);
        
        // File di log per categoria (per eventi importanti)
        if ($level !== self::LEVEL_DEBUG) {
            $categoryFile = $this->logPath . $category . '_' . date('Y-m') . '.log';
            file_put_contents($categoryFile, $logEntry, FILE_APPEND | LOCK_EX);
        }
    }
    
    /**
     * Log su database
     * @param string $category
     * @param string $action
     * @param string $details
     * @param array $context
     * @param int|null $userId
     * @return bool
     */
    private function logToDatabase($category, $action, $details, $context = [], $userId = null) {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO log_attivita 
                (utente_id, azione, dettagli, ip_address, user_agent)
                VALUES (?, ?, ?, ?, ?)
            ");
            
            $fullAction = $category . '.' . $action;
            $fullDetails = $details;
            
            if (!empty($context)) {
                $fullDetails .= ' | Context: ' . json_encode($context);
            }
            
            return $stmt->execute([
                $userId,
                $fullAction,
                $fullDetails,
                $_SERVER['REMOTE_ADDR'] ?? null,
                $_SERVER['HTTP_USER_AGENT'] ?? null
            ]);
            
        } catch (Exception $e) {
            error_log("Errore log database: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Determina se loggare su database
     * @param string $level
     * @param string $category
     * @return bool
     */
    private function shouldLogToDatabase($level, $category) {
        // Sempre su DB per warning e superiori
        if (in_array($level, [self::LEVEL_WARNING, self::LEVEL_ERROR, self::LEVEL_CRITICAL])) {
            return true;
        }
        
        // Sempre su DB per categorie critiche
        if (in_array($category, [self::CATEGORY_AUTH, self::CATEGORY_SECURITY, self::CATEGORY_INVOICE])) {
            return true;
        }
        
        // Info solo per alcune categorie
        if ($level === self::LEVEL_INFO) {
            return in_array($category, [self::CATEGORY_USER, self::CATEGORY_TEST, self::CATEGORY_REPORT]);
        }
        
        return false;
    }
    
    /**
     * Invia alert per eventi critici
     * @param string $category
     * @param string $action
     * @param string $message
     */
    private function sendCriticalAlert($category, $action, $message) {
        // Email amministratori
        $admins = $this->getAdminEmails();
        
        if (empty($admins)) {
            return;
        }
        
        $subject = "[CRITICO] SerteX+ - $category.$action";
        $body = "Si è verificato un evento critico nel sistema SerteX+\n\n";
        $body .= "Categoria: $category\n";
        $body .= "Azione: $action\n";
        $body .= "Messaggio: $message\n";
        $body .= "Data/Ora: " . date('Y-m-d H:i:s') . "\n";
        $body .= "IP: " . ($_SERVER['REMOTE_ADDR'] ?? 'unknown') . "\n";
        
        foreach ($admins as $email) {
            sendEmail($email, $subject, $body);
        }
    }
    
    /**
     * Ottiene email amministratori
     * @return array
     */
    private function getAdminEmails() {
        try {
            $stmt = $this->db->query("
                SELECT u.email 
                FROM utenti u 
                WHERE u.tipo_utente = 'amministratore' 
                AND u.attivo = 1
            ");
            return $stmt->fetchAll(PDO::FETCH_COLUMN);
        } catch (Exception $e) {
            return [];
        }
    }
    
    /**
     * Assicura che la directory di log esista
     */
    private function ensureLogDirectory() {
        if (!file_exists($this->logPath)) {
            mkdir($this->logPath, 0755, true);
        }
        
        // Proteggi con .htaccess
        $htaccess = $this->logPath . '.htaccess';
        if (!file_exists($htaccess)) {
            file_put_contents($htaccess, "Deny from all");
        }
    }
    
    /**
     * Imposta il file di log corrente
     */
    private function setCurrentLogFile() {
        $this->currentLogFile = $this->logPath . 'sertex_' . date('Y-m-d') . '.log';
    }
    
    /**
     * Ruota i log
     * @param int $daysToKeep
     * @return int Numero di file eliminati
     */
    public function rotateLogs($daysToKeep = 90) {
        $deleted = 0;
        $cutoffTime = time() - ($daysToKeep * 86400);
        
        $files = glob($this->logPath . '*.log');
        
        foreach ($files as $file) {
            if (filemtime($file) < $cutoffTime) {
                if (unlink($file)) {
                    $deleted++;
                }
            }
        }
        
        $this->info(self::CATEGORY_SYSTEM, 'log_rotation', 
                   "Rotazione log completata. File eliminati: $deleted");
        
        return $deleted;
    }
    
    /**
     * Pulisce log dal database
     * @param int $daysToKeep
     * @return int Numero di record eliminati
     */
    public function cleanDatabaseLogs($daysToKeep = 365) {
        try {
            $stmt = $this->db->prepare("
                DELETE FROM log_attivita 
                WHERE timestamp < DATE_SUB(NOW(), INTERVAL ? DAY)
            ");
            $stmt->execute([$daysToKeep]);
            
            $deleted = $stmt->rowCount();
            
            $this->info(self::CATEGORY_SYSTEM, 'db_log_cleanup', 
                       "Pulizia log database completata. Record eliminati: $deleted");
            
            return $deleted;
            
        } catch (Exception $e) {
            $this->error(self::CATEGORY_SYSTEM, 'db_log_cleanup_error', 
                        "Errore pulizia log database: " . $e->getMessage());
            return 0;
        }
    }
    
    /**
     * Cerca nei log
     * @param array $criteria
     * @param int $limit
     * @param int $offset
     * @return array
     */
    public function searchLogs($criteria = [], $limit = 100, $offset = 0) {
        $sql = "
            SELECT l.*, 
                   CONCAT(u.nome, ' ', u.cognome) as utente_nome
            FROM log_attivita l
            LEFT JOIN utenti u ON l.utente_id = u.id
            WHERE 1=1
        ";
        $params = [];
        
        // Criteri di ricerca
        if (!empty($criteria['utente_id'])) {
            $sql .= " AND l.utente_id = ?";
            $params[] = $criteria['utente_id'];
        }
        
        if (!empty($criteria['azione'])) {
            $sql .= " AND l.azione LIKE ?";
            $params[] = '%' . $criteria['azione'] . '%';
        }
        
        if (!empty($criteria['categoria'])) {
            $sql .= " AND l.azione LIKE ?";
            $params[] = $criteria['categoria'] . '.%';
        }
        
        if (!empty($criteria['data_da'])) {
            $sql .= " AND DATE(l.timestamp) >= ?";
            $params[] = $criteria['data_da'];
        }
        
        if (!empty($criteria['data_a'])) {
            $sql .= " AND DATE(l.timestamp) <= ?";
            $params[] = $criteria['data_a'];
        }
        
        if (!empty($criteria['ip_address'])) {
            $sql .= " AND l.ip_address = ?";
            $params[] = $criteria['ip_address'];
        }
        
        if (!empty($criteria['search'])) {
            $sql .= " AND (l.azione LIKE ? OR l.dettagli LIKE ?)";
            $search = '%' . $criteria['search'] . '%';
            $params[] = $search;
            $params[] = $search;
        }
        
        $sql .= " ORDER BY l.timestamp DESC LIMIT ? OFFSET ?";
        $params[] = $limit;
        $params[] = $offset;
        
        try {
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            $this->error(self::CATEGORY_SYSTEM, 'log_search_error', 
                        "Errore ricerca log: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Genera report attività
     * @param string $startDate
     * @param string $endDate
     * @param string|null $category
     * @return array
     */
    public function generateActivityReport($startDate, $endDate, $category = null) {
        $report = [
            'period' => [
                'start' => $startDate,
                'end' => $endDate
            ],
            'summary' => [],
            'by_user' => [],
            'by_action' => [],
            'by_day' => [],
            'security_events' => []
        ];
        
        try {
            // Riepilogo generale
            $sql = "
                SELECT 
                    COUNT(*) as total_events,
                    COUNT(DISTINCT utente_id) as unique_users,
                    COUNT(DISTINCT ip_address) as unique_ips,
                    COUNT(DISTINCT DATE(timestamp)) as active_days
                FROM log_attivita
                WHERE DATE(timestamp) BETWEEN ? AND ?
            ";
            
            if ($category) {
                $sql .= " AND azione LIKE ?";
            }
            
            $stmt = $this->db->prepare($sql);
            $params = [$startDate, $endDate];
            if ($category) {
                $params[] = $category . '.%';
            }
            $stmt->execute($params);
            $report['summary'] = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Per utente
            $sql = "
                SELECT 
                    u.id,
                    CONCAT(u.nome, ' ', u.cognome) as nome,
                    u.tipo_utente,
                    COUNT(*) as azioni,
                    MIN(l.timestamp) as prima_azione,
                    MAX(l.timestamp) as ultima_azione
                FROM log_attivita l
                JOIN utenti u ON l.utente_id = u.id
                WHERE DATE(l.timestamp) BETWEEN ? AND ?
            ";
            
            if ($category) {
                $sql .= " AND l.azione LIKE ?";
            }
            
            $sql .= " GROUP BY u.id ORDER BY azioni DESC LIMIT 20";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $report['by_user'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Per azione
            $sql = "
                SELECT 
                    azione,
                    COUNT(*) as count,
                    COUNT(DISTINCT utente_id) as unique_users
                FROM log_attivita
                WHERE DATE(timestamp) BETWEEN ? AND ?
            ";
            
            if ($category) {
                $sql .= " AND azione LIKE ?";
            }
            
            $sql .= " GROUP BY azione ORDER BY count DESC LIMIT 30";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $report['by_action'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Per giorno
            $sql = "
                SELECT 
                    DATE(timestamp) as data,
                    COUNT(*) as eventi,
                    COUNT(DISTINCT utente_id) as utenti_attivi
                FROM log_attivita
                WHERE DATE(timestamp) BETWEEN ? AND ?
            ";
            
            if ($category) {
                $sql .= " AND azione LIKE ?";
            }
            
            $sql .= " GROUP BY DATE(timestamp) ORDER BY data";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute($params);
            $report['by_day'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Eventi di sicurezza
            $sql = "
                SELECT *
                FROM log_attivita
                WHERE DATE(timestamp) BETWEEN ? AND ?
                AND azione LIKE 'security.%'
                ORDER BY timestamp DESC
                LIMIT 50
            ";
            
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$startDate, $endDate]);
            $report['security_events'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
        } catch (Exception $e) {
            $this->error(self::CATEGORY_SYSTEM, 'activity_report_error', 
                        "Errore generazione report: " . $e->getMessage());
        }
        
        return $report;
    }
    
    /**
     * Esporta log in CSV
     * @param array $criteria
     * @param string $outputPath
     * @return bool
     */
    public function exportToCSV($criteria, $outputPath) {
        try {
            $logs = $this->searchLogs($criteria, 10000, 0);
            
            $fp = fopen($outputPath, 'w');
            
            // Header
            fputcsv($fp, [
                'Data/Ora',
                'Utente',
                'Azione',
                'Dettagli',
                'IP',
                'User Agent'
            ]);
            
            // Righe
            foreach ($logs as $log) {
                fputcsv($fp, [
                    $log['timestamp'],
                    $log['utente_nome'] ?? 'N/A',
                    $log['azione'],
                    $log['dettagli'],
                    $log['ip_address'],
                    $log['user_agent']
                ]);
            }
            
            fclose($fp);
            
            $this->info(self::CATEGORY_SYSTEM, 'log_export', 
                       "Esportati " . count($logs) . " log in CSV");
            
            return true;
            
        } catch (Exception $e) {
            $this->error(self::CATEGORY_SYSTEM, 'log_export_error', 
                        "Errore esportazione log: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Ottiene statistiche di sistema
     * @return array
     */
    public function getSystemStats() {
        $stats = [];
        
        try {
            // Eventi oggi
            $stmt = $this->db->query("
                SELECT COUNT(*) FROM log_attivita 
                WHERE DATE(timestamp) = CURDATE()
            ");
            $stats['events_today'] = $stmt->fetchColumn();
            
            // Utenti attivi oggi
            $stmt = $this->db->query("
                SELECT COUNT(DISTINCT utente_id) FROM log_attivita 
                WHERE DATE(timestamp) = CURDATE() AND utente_id IS NOT NULL
            ");
            $stats['active_users_today'] = $stmt->fetchColumn();
            
            // Tentativi di login falliti oggi
            $stmt = $this->db->query("
                SELECT COUNT(*) FROM log_attivita 
                WHERE DATE(timestamp) = CURDATE() 
                AND azione = 'auth.login_failed'
            ");
            $stats['failed_logins_today'] = $stmt->fetchColumn();
            
            // Dimensione log
            $logFiles = glob($this->logPath . '*.log');
            $totalSize = 0;
            foreach ($logFiles as $file) {
                $totalSize += filesize($file);
            }
            $stats['log_size_mb'] = round($totalSize / 1048576, 2);
            
            // Record nel database
            $stmt = $this->db->query("SELECT COUNT(*) FROM log_attivita");
            $stats['db_records'] = $stmt->fetchColumn();
            
        } catch (Exception $e) {
            $this->error(self::CATEGORY_SYSTEM, 'system_stats_error', 
                        "Errore recupero statistiche: " . $e->getMessage());
        }
        
        return $stats;
    }
}