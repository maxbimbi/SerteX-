<?php
session_start();

// Verifica se l'installazione è già stata completata
if (file_exists('config/installed.lock')) {
    header('Location: index.php');
    exit();
}

// Gestione step installazione
$step = isset($_GET['step']) ? (int)$_GET['step'] : 1;
$error = '';
$success = '';

// Funzioni di utilità
function testDatabaseConnection($host, $user, $pass, $name, $port = 3306) {
    try {
        $dsn = "mysql:host=$host;port=$port;dbname=$name;charset=utf8mb4";
        $pdo = new PDO($dsn, $user, $pass);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        return ['success' => true, 'pdo' => $pdo];
    } catch (PDOException $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

function createDirectories() {
    $directories = [
        'uploads',
        'uploads/referti',
        'uploads/referti/genetici',
        'uploads/referti/microbiota',
        'uploads/referti/intolleranze',
        'uploads/referti/firmati',
        'uploads/report',
        'uploads/fatture',
        'uploads/firme',
        'backup',
        'backup/database',
        'backup/files',
        'logs',
        'temp',
        'config'
    ];
    
    $errors = [];
    foreach ($directories as $dir) {
        if (!file_exists($dir)) {
            if (!mkdir($dir, 0755, true)) {
                $errors[] = "Impossibile creare la directory: $dir";
            }
        }
        // Crea .htaccess per proteggere le directory
        if (in_array($dir, ['uploads', 'backup', 'logs', 'config', 'temp'])) {
            $htaccess = $dir . '/.htaccess';
            if (!file_exists($htaccess)) {
                file_put_contents($htaccess, "Deny from all");
            }
        }
    }
    
    return $errors;
}

function generateSecureKey($length = 32) {
    return bin2hex(random_bytes($length));
}

// Gestione form POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    switch ($step) {
        case 2: // Test connessione database
            $db_host = $_POST['db_host'] ?? 'localhost';
            $db_port = $_POST['db_port'] ?? '3306';
            $db_name = $_POST['db_name'] ?? '';
            $db_user = $_POST['db_user'] ?? '';
            $db_pass = $_POST['db_pass'] ?? '';
            
            $test = testDatabaseConnection($db_host, $db_user, $db_pass, $db_name, $db_port);
            
            if ($test['success']) {
                $_SESSION['db_config'] = [
                    'host' => $db_host,
                    'port' => $db_port,
                    'name' => $db_name,
                    'user' => $db_user,
                    'pass' => $db_pass
                ];
                header('Location: install.php?step=3');
                exit();
            } else {
                $error = 'Errore connessione database: ' . $test['error'];
            }
            break;
            
        case 3: // Creazione struttura database
            if (isset($_SESSION['db_config'])) {
                $config = $_SESSION['db_config'];
                $test = testDatabaseConnection($config['host'], $config['user'], $config['pass'], $config['name'], $config['port']);
                
                if ($test['success']) {
                    try {
                        // Leggi il file SQL
                        $sql = file_get_contents('install/database.sql');
                        
                        // Esegui le query
                        $test['pdo']->exec($sql);
                        
                        header('Location: install.php?step=4');
                        exit();
                    } catch (Exception $e) {
                        $error = 'Errore creazione database: ' . $e->getMessage();
                    }
                } else {
                    $error = 'Errore connessione database';
                }
            }
            break;
            
        case 4: // Configurazione sistema
            $_SESSION['system_config'] = [
                'site_name' => $_POST['site_name'] ?? 'SerteX+',
                'site_url' => $_POST['site_url'] ?? '',
                'admin_email' => $_POST['admin_email'] ?? '',
                'timezone' => $_POST['timezone'] ?? 'Europe/Rome'
            ];
            header('Location: install.php?step=5');
            exit();
            break;
            
        case 5: // Creazione amministratore
            $username = $_POST['username'] ?? '';
            $password = $_POST['password'] ?? '';
            $password_confirm = $_POST['password_confirm'] ?? '';
            $email = $_POST['email'] ?? '';
            $nome = $_POST['nome'] ?? '';
            $cognome = $_POST['cognome'] ?? '';
            
            if ($password !== $password_confirm) {
                $error = 'Le password non coincidono';
            } elseif (strlen($password) < 8) {
                $error = 'La password deve essere di almeno 8 caratteri';
            } else {
                // Crea l'amministratore
                if (isset($_SESSION['db_config'])) {
                    $config = $_SESSION['db_config'];
                    $test = testDatabaseConnection($config['host'], $config['user'], $config['pass'], $config['name'], $config['port']);
                    
                    if ($test['success']) {
                        try {
                            $pdo = $test['pdo'];
                            
                            // Verifica se esiste già un admin
                            $stmt = $pdo->prepare("SELECT id FROM utenti WHERE username = ? OR email = ?");
                            $stmt->execute([$username, $email]);
                            
                            if ($stmt->rowCount() > 0) {
                                $error = 'Username o email già esistenti';
                            } else {
                                // Crea l'admin
                                $hash = password_hash($password, PASSWORD_DEFAULT);
                                $stmt = $pdo->prepare("INSERT INTO utenti (username, password, email, nome, cognome, tipo_utente) VALUES (?, ?, ?, ?, ?, 'amministratore')");
                                $stmt->execute([$username, $hash, $email, $nome, $cognome]);
                                
                                header('Location: install.php?step=6');
                                exit();
                            }
                        } catch (Exception $e) {
                            $error = 'Errore creazione amministratore: ' . $e->getMessage();
                        }
                    }
                }
            }
            break;
            
        case 6: // Finalizzazione
            // Crea le directory
            $dirErrors = createDirectories();
            
            if (empty($dirErrors)) {
                // Genera chiavi di sicurezza
                $encryptionKey = generateSecureKey();
                $sessionKey = generateSecureKey();
                
                // Crea file di configurazione
                $configContent = "<?php\n";
                $configContent .= "// SerteX+ Configuration File\n";
                $configContent .= "// Generated on " . date('Y-m-d H:i:s') . "\n\n";
                
                // Database config
                $configContent .= "// Database Configuration\n";
                $configContent .= "define('DB_HOST', '" . $_SESSION['db_config']['host'] . "');\n";
                $configContent .= "define('DB_PORT', '" . $_SESSION['db_config']['port'] . "');\n";
                $configContent .= "define('DB_NAME', '" . $_SESSION['db_config']['name'] . "');\n";
                $configContent .= "define('DB_USER', '" . $_SESSION['db_config']['user'] . "');\n";
                $configContent .= "define('DB_PASS', '" . $_SESSION['db_config']['pass'] . "');\n\n";
                
                // System config
                $configContent .= "// System Configuration\n";
                $configContent .= "define('SITE_NAME', '" . $_SESSION['system_config']['site_name'] . "');\n";
                $configContent .= "define('SITE_URL', '" . $_SESSION['system_config']['site_url'] . "');\n";
                $configContent .= "define('ADMIN_EMAIL', '" . $_SESSION['system_config']['admin_email'] . "');\n";
                $configContent .= "define('TIMEZONE', '" . $_SESSION['system_config']['timezone'] . "');\n\n";
                
                // Security config
                $configContent .= "// Security Configuration\n";
                $configContent .= "define('ENCRYPTION_KEY', '" . $encryptionKey . "');\n";
                $configContent .= "define('SESSION_KEY', '" . $sessionKey . "');\n";
                $configContent .= "define('SECURE_COOKIES', true);\n";
                $configContent .= "define('SESSION_LIFETIME', 3600);\n\n";
                
                // Paths
                $configContent .= "// Path Configuration\n";
                $configContent .= "define('ROOT_PATH', dirname(__FILE__) . '/../');\n";
                $configContent .= "define('UPLOAD_PATH', ROOT_PATH . 'uploads/');\n";
                $configContent .= "define('BACKUP_PATH', ROOT_PATH . 'backup/');\n";
                $configContent .= "define('LOG_PATH', ROOT_PATH . 'logs/');\n";
                
                // Salva il file di configurazione
                file_put_contents('config/config.php', $configContent);
                
                // Crea file lock
                file_put_contents('config/installed.lock', date('Y-m-d H:i:s'));
                
                // Pulisci sessione
                session_destroy();
                
                $success = 'Installazione completata con successo!';
            } else {
                $error = 'Errore creazione directory: ' . implode('<br>', $dirErrors);
            }
            break;
    }
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SerteX+ - Installazione</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f5f5f5;
            color: #333;
            line-height: 1.6;
        }
        
        .container {
            max-width: 800px;
            margin: 50px auto;
            background: white;
            padding: 0;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .header {
            background: #1976d2;
            color: white;
            padding: 30px;
            text-align: center;
            border-radius: 8px 8px 0 0;
        }
        
        .header h1 {
            font-size: 2.5em;
            margin-bottom: 10px;
        }
        
        .progress {
            display: flex;
            justify-content: space-between;
            padding: 20px 30px;
            background: #f8f9fa;
            border-bottom: 1px solid #e9ecef;
        }
        
        .progress-step {
            flex: 1;
            text-align: center;
            position: relative;
        }
        
        .progress-step:not(:last-child)::after {
            content: '';
            position: absolute;
            top: 15px;
            right: -50%;
            width: 100%;
            height: 2px;
            background: #e9ecef;
        }
        
        .progress-step.active::after,
        .progress-step.completed::after {
            background: #1976d2;
        }
        
        .progress-step .step-number {
            width: 30px;
            height: 30px;
            background: #e9ecef;
            border-radius: 50%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            margin-bottom: 5px;
        }
        
        .progress-step.active .step-number,
        .progress-step.completed .step-number {
            background: #1976d2;
            color: white;
        }
        
        .content {
            padding: 40px;
        }
        
        .form-group {
            margin-bottom: 25px;
        }
        
        label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: #555;
        }
        
        input[type="text"],
        input[type="email"],
        input[type="password"],
        input[type="number"],
        select {
            width: 100%;
            padding: 12px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 16px;
            transition: border-color 0.3s;
        }
        
        input:focus,
        select:focus {
            outline: none;
            border-color: #1976d2;
        }
        
        .help-text {
            font-size: 14px;
            color: #666;
            margin-top: 5px;
        }
        
        .button-group {
            display: flex;
            justify-content: space-between;
            margin-top: 40px;
        }
        
        .btn {
            padding: 12px 30px;
            border: none;
            border-radius: 4px;
            font-size: 16px;
            cursor: pointer;
            transition: all 0.3s;
            text-decoration: none;
            display: inline-block;
        }
        
        .btn-primary {
            background: #1976d2;
            color: white;
        }
        
        .btn-primary:hover {
            background: #1565c0;
        }
        
        .btn-secondary {
            background: #6c757d;
            color: white;
        }
        
        .btn-secondary:hover {
            background: #5a6268;
        }
        
        .alert {
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 4px;
        }
        
        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        
        .alert-success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        
        .requirements {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 4px;
            margin-bottom: 30px;
        }
        
        .requirements h3 {
            margin-bottom: 15px;
            color: #333;
        }
        
        .requirement-item {
            display: flex;
            align-items: center;
            margin-bottom: 10px;
        }
        
        .requirement-item .status {
            width: 20px;
            height: 20px;
            margin-right: 10px;
            border-radius: 50%;
        }
        
        .requirement-item .status.ok {
            background: #28a745;
        }
        
        .requirement-item .status.error {
            background: #dc3545;
        }
        
        .requirement-item .status.warning {
            background: #ffc107;
        }
        
        .completed-icon {
            font-size: 100px;
            color: #28a745;
            text-align: center;
            margin: 30px 0;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>SerteX+</h1>
            <p>Installazione guidata</p>
        </div>
        
        <div class="progress">
            <div class="progress-step <?php echo $step >= 1 ? 'active' : ''; ?> <?php echo $step > 1 ? 'completed' : ''; ?>">
                <div class="step-number">1</div>
                <div>Benvenuto</div>
            </div>
            <div class="progress-step <?php echo $step >= 2 ? 'active' : ''; ?> <?php echo $step > 2 ? 'completed' : ''; ?>">
                <div class="step-number">2</div>
                <div>Database</div>
            </div>
            <div class="progress-step <?php echo $step >= 3 ? 'active' : ''; ?> <?php echo $step > 3 ? 'completed' : ''; ?>">
                <div class="step-number">3</div>
                <div>Struttura</div>
            </div>
            <div class="progress-step <?php echo $step >= 4 ? 'active' : ''; ?> <?php echo $step > 4 ? 'completed' : ''; ?>">
                <div class="step-number">4</div>
                <div>Configurazione</div>
            </div>
            <div class="progress-step <?php echo $step >= 5 ? 'active' : ''; ?> <?php echo $step > 5 ? 'completed' : ''; ?>">
                <div class="step-number">5</div>
                <div>Amministratore</div>
            </div>
            <div class="progress-step <?php echo $step >= 6 ? 'active' : ''; ?> <?php echo $step > 6 ? 'completed' : ''; ?>">
                <div class="step-number">6</div>
                <div>Completamento</div>
            </div>
        </div>
        
        <div class="content">
            <?php if ($error): ?>
                <div class="alert alert-error"><?php echo $error; ?></div>
            <?php endif; ?>
            
            <?php if ($success): ?>
                <div class="alert alert-success"><?php echo $success; ?></div>
            <?php endif; ?>
            
            <?php switch($step): 
                case 1: // Benvenuto e requisiti ?>
                    <h2>Benvenuto in SerteX+</h2>
                    <p>Questa procedura guidata ti aiuterà a configurare il sistema di gestione per il laboratorio di analisi genetiche.</p>
                    
                    <div class="requirements">
                        <h3>Requisiti di sistema</h3>
                        <?php
                        $requirements = [
                            'PHP >= 7.4' => version_compare(PHP_VERSION, '7.4.0', '>='),
                            'PDO MySQL' => extension_loaded('pdo_mysql'),
                            'OpenSSL' => extension_loaded('openssl'),
                            'JSON' => extension_loaded('json'),
                            'Session' => extension_loaded('session'),
                            'FileInfo' => extension_loaded('fileinfo'),
                            'GD Library' => extension_loaded('gd'),
                            'Zip' => extension_loaded('zip')
                        ];
                        ?>
                        <?php foreach ($requirements as $req => $status): ?>
                            <div class="requirement-item">
                                <div class="status <?php echo $status ? 'ok' : 'error'; ?>"></div>
                                <span><?php echo $req; ?> <?php echo $status ? '✓' : '✗'; ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    
                    <form method="get">
                        <div class="button-group">
                            <span></span>
                            <button type="submit" name="step" value="2" class="btn btn-primary">Avanti</button>
                        </div>
                    </form>
                    <?php break;
                    
                case 2: // Configurazione database ?>
                    <h2>Configurazione Database</h2>
                    <p>Inserisci i dati di connessione al database MySQL.</p>
                    
                    <form method="post">
                        <div class="form-group">
                            <label for="db_host">Host Database</label>
                            <input type="text" id="db_host" name="db_host" value="localhost" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="db_port">Porta</label>
                            <input type="number" id="db_port" name="db_port" value="3306" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="db_name">Nome Database</label>
                            <input type="text" id="db_name" name="db_name" placeholder="sertexplus" required>
                            <p class="help-text">Il database deve essere già creato</p>
                        </div>
                        
                        <div class="form-group">
                            <label for="db_user">Username</label>
                            <input type="text" id="db_user" name="db_user" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="db_pass">Password</label>
                            <input type="password" id="db_pass" name="db_pass">
                        </div>
                        
                        <div class="button-group">
                            <a href="install.php?step=1" class="btn btn-secondary">Indietro</a>
                            <button type="submit" class="btn btn-primary">Test Connessione</button>
                        </div>
                    </form>
                    <?php break;
                    
                case 3: // Creazione struttura database ?>
                    <h2>Creazione Struttura Database</h2>
                    <p>Verrà ora creata la struttura del database con tutte le tabelle necessarie.</p>
                    
                    <div class="requirements">
                        <h3>Operazioni da eseguire:</h3>
                        <ul>
                            <li>Creazione tabelle utenti e permessi</li>
                            <li>Creazione tabelle geni e pannelli</li>
                            <li>Creazione tabelle test e risultati</li>
                            <li>Creazione tabelle referti e fatture</li>
                            <li>Inserimento dati di configurazione base</li>
                        </ul>
                    </div>
                    
                    <form method="post">
                        <div class="button-group">
                            <a href="install.php?step=2" class="btn btn-secondary">Indietro</a>
                            <button type="submit" class="btn btn-primary">Crea Database</button>
                        </div>
                    </form>
                    <?php break;
                    
                case 4: // Configurazione sistema ?>
                    <h2>Configurazione Sistema</h2>
                    <p>Configura i parametri principali del sistema.</p>
                    
                    <form method="post">
                        <div class="form-group">
                            <label for="site_name">Nome del Sistema</label>
                            <input type="text" id="site_name" name="site_name" value="SerteX+" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="site_url">URL del Sistema</label>
                            <input type="text" id="site_url" name="site_url" 
                                   value="<?php echo 'http' . (isset($_SERVER['HTTPS']) ? 's' : '') . '://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['REQUEST_URI']); ?>" 
                                   required>
                            <p class="help-text">URL completo senza slash finale</p>
                        </div>
                        
                        <div class="form-group">
                            <label for="admin_email">Email Amministratore</label>
                            <input type="email" id="admin_email" name="admin_email" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="timezone">Fuso Orario</label>
                            <select id="timezone" name="timezone" required>
                                <option value="Europe/Rome" selected>Europe/Rome</option>
                                <option value="Europe/London">Europe/London</option>
                                <option value="Europe/Paris">Europe/Paris</option>
                                <option value="Europe/Berlin">Europe/Berlin</option>
                                <option value="Europe/Madrid">Europe/Madrid</option>
                            </select>
                        </div>
                        
                        <div class="button-group">
                            <a href="install.php?step=3" class="btn btn-secondary">Indietro</a>
                            <button type="submit" class="btn btn-primary">Avanti</button>
                        </div>
                    </form>
                    <?php break;
                    
                case 5: // Creazione amministratore ?>
                    <h2>Creazione Amministratore</h2>
                    <p>Crea l'account amministratore principale del sistema.</p>
                    
                    <form method="post">
                        <div class="form-group">
                            <label for="username">Username</label>
                            <input type="text" id="username" name="username" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="email">Email</label>
                            <input type="email" id="email" name="email" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="nome">Nome</label>
                            <input type="text" id="nome" name="nome" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="cognome">Cognome</label>
                            <input type="text" id="cognome" name="cognome" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="password">Password</label>
                            <input type="password" id="password" name="password" required minlength="8">
                            <p class="help-text">Minimo 8 caratteri</p>
                        </div>
                        
                        <div class="form-group">
                            <label for="password_confirm">Conferma Password</label>
                            <input type="password" id="password_confirm" name="password_confirm" required>
                        </div>
                        
                        <div class="button-group">
                            <a href="install.php?step=4" class="btn btn-secondary">Indietro</a>
                            <button type="submit" class="btn btn-primary">Crea Amministratore</button>
                        </div>
                    </form>
                    <?php break;
                    
                case 6: // Completamento ?>
                    <h2>Installazione Completata</h2>
                    
                    <?php if (empty($error)): ?>
                        <div class="completed-icon">✓</div>
                        
                        <p>L'installazione di SerteX+ è stata completata con successo!</p>
                        
                        <div class="requirements">
                            <h3>Prossimi passi:</h3>
                            <ul>
                                <li>Elimina la cartella <code>/install</code> per motivi di sicurezza</li>
                                <li>Configura i permessi delle cartelle secondo le tue necessità</li>
                                <li>Configura il backup automatico dal pannello amministrazione</li>
                                <li>Personalizza il layout e i colori del sistema</li>
                                <li>Aggiungi i primi geni, pannelli e test</li>
                            </ul>
                        </div>
                        
                        <form method="post">
                            <div class="button-group">
                                <span></span>
                                <button type="submit" class="btn btn-primary">Finalizza Installazione</button>
                            </div>
                        </form>
                        
                        <?php if ($success): ?>
                            <div style="text-align: center; margin-top: 30px;">
                                <a href="index.php" class="btn btn-primary">Vai al Login</a>
                            </div>
                        <?php endif; ?>
                    <?php else: ?>
                        <div class="button-group">
                            <a href="install.php?step=5" class="btn btn-secondary">Indietro</a>
                        </div>
                    <?php endif; ?>
                    <?php break;
            endswitch; ?>
        </div>
    </div>
</body>
</html>