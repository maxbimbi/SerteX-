<?php
/**
 * SerteX+ - Sistema di Gestione Laboratorio Analisi Genetiche
 * File principale con sistema di login
 */

session_start();

// Verifica installazione
if (!file_exists('config/installed.lock')) {
    header('Location: install.php');
    exit();
}

// Carica configurazione
require_once 'config/config.php';
require_once 'includes/functions.php';
require_once 'includes/security.php';

// Imposta timezone
date_default_timezone_set(TIMEZONE);

// Se l'utente è già loggato, reindirizza alla sua dashboard
if (isset($_SESSION['user_id']) && isset($_SESSION['user_type'])) {
    $redirect = getUserDashboard($_SESSION['user_type']);
    header("Location: $redirect");
    exit();
}

// Gestione logout
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    logout();
}

// Variabili per messaggi
$error = '';
$success = '';

// Gestione form login
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $remember = isset($_POST['remember']);
    
    // Validazione base
    if (empty($username) || empty($password)) {
        $error = 'Inserisci username e password';
    } else {
        // Tentativo di login
        $loginResult = attemptLogin($username, $password, $remember);
        
        if ($loginResult['success']) {
            // Login riuscito - log attività
            logActivity($loginResult['user_id'], 'login', 'Login effettuato con successo');
            
            // Reindirizza alla dashboard appropriata
            $redirect = getUserDashboard($loginResult['user_type']);
            header("Location: $redirect");
            exit();
        } else {
            $error = $loginResult['error'];
            
            // Log tentativo fallito
            logActivity(null, 'login_failed', "Tentativo di login fallito per: $username");
        }
    }
}

// Funzione per tentare il login
function attemptLogin($username, $password, $remember = false) {
    try {
        $db = getDatabase();
        
        // Cerca l'utente
        $stmt = $db->prepare("
            SELECT u.*, p.listino_id 
            FROM utenti u 
            LEFT JOIN professionisti p ON u.id = p.utente_id 
            WHERE u.username = ? OR u.email = ?
            LIMIT 1
        ");
        $stmt->execute([$username, $username]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$user) {
            return ['success' => false, 'error' => 'Credenziali non valide'];
        }
        
        // Verifica se l'account è bloccato
        if ($user['bloccato']) {
            return ['success' => false, 'error' => 'Account bloccato. Contatta l\'amministratore'];
        }
        
        // Verifica se l'account è attivo
        if (!$user['attivo']) {
            return ['success' => false, 'error' => 'Account non attivo'];
        }
        
        // Verifica password
        if (!password_verify($password, $user['password'])) {
            // Incrementa tentativi falliti
            $stmt = $db->prepare("
                UPDATE utenti 
                SET tentativi_falliti = tentativi_falliti + 1 
                WHERE id = ?
            ");
            $stmt->execute([$user['id']]);
            
            // Blocca dopo MAX_LOGIN_ATTEMPTS tentativi
            if ($user['tentativi_falliti'] + 1 >= MAX_LOGIN_ATTEMPTS && $user['tipo_utente'] !== 'amministratore') {
                $stmt = $db->prepare("UPDATE utenti SET bloccato = 1 WHERE id = ?");
                $stmt->execute([$user['id']]);
                return ['success' => false, 'error' => 'Account bloccato per troppi tentativi falliti'];
            }
            
            return ['success' => false, 'error' => 'Credenziali non valide'];
        }
        
        // Verifica scadenza password (solo per non amministratori)
        if ($user['tipo_utente'] !== 'amministratore' && $user['data_cambio_password']) {
            $passwordAge = (time() - strtotime($user['data_cambio_password'])) / 86400;
            if ($passwordAge > PASSWORD_EXPIRY_DAYS) {
                $_SESSION['temp_user_id'] = $user['id'];
                header('Location: change_password.php?expired=1');
                exit();
            }
        }
        
        // Login riuscito - resetta tentativi falliti e aggiorna ultimo accesso
        $stmt = $db->prepare("
            UPDATE utenti 
            SET tentativi_falliti = 0, 
                data_ultimo_accesso = NOW() 
            WHERE id = ?
        ");
        $stmt->execute([$user['id']]);
        
        // Crea sessione
        session_regenerate_id(true);
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['user_type'] = $user['tipo_utente'];
        $_SESSION['user_name'] = $user['nome'] . ' ' . $user['cognome'];
        $_SESSION['login_time'] = time();
        
        // Dati aggiuntivi per professionisti
        if ($user['tipo_utente'] === 'professionista' && $user['listino_id']) {
            $_SESSION['listino_id'] = $user['listino_id'];
        }
        
        // Gestione "Ricordami"
        if ($remember) {
            $token = bin2hex(random_bytes(32));
            $hashedToken = hash('sha256', $token);
            
            // Salva token nel database
            $stmt = $db->prepare("
                INSERT INTO sessioni (id, utente_id, ip_address, user_agent) 
                VALUES (?, ?, ?, ?)
            ");
            $stmt->execute([
                $hashedToken,
                $user['id'],
                $_SERVER['REMOTE_ADDR'],
                $_SERVER['HTTP_USER_AGENT']
            ]);
            
            // Imposta cookie sicuro
            setcookie('remember_token', $token, [
                'expires' => time() + (30 * 24 * 60 * 60), // 30 giorni
                'path' => '/',
                'secure' => isset($_SERVER['HTTPS']),
                'httponly' => true,
                'samesite' => 'Strict'
            ]);
        }
        
        return [
            'success' => true,
            'user_id' => $user['id'],
            'user_type' => $user['tipo_utente']
        ];
        
    } catch (Exception $e) {
        error_log("Errore login: " . $e->getMessage());
        return ['success' => false, 'error' => 'Errore di sistema. Riprova più tardi'];
    }
}

// Funzione per ottenere la dashboard dell'utente
function getUserDashboard($userType) {
    switch ($userType) {
        case 'amministratore':
            return 'admin/dashboard.php';
        case 'biologo':
            return 'biologo/dashboard.php';
        case 'professionista':
            return 'professionista/dashboard.php';
        case 'commerciale':
            return 'commerciale/dashboard.php';
        default:
            return 'index.php';
    }
}

// Recupera messaggio dalla sessione se presente
if (isset($_SESSION['success_message'])) {
    $success = $_SESSION['success_message'];
    unset($_SESSION['success_message']);
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="SerteX+ - Sistema di gestione per laboratorio di analisi genetiche">
    <meta name="robots" content="noindex, nofollow">
    <title><?php echo SITE_NAME; ?> - Login</title>
    
    <!-- CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    
    <style>
        :root {
            --primary-color: #1976d2;
            --secondary-color: #dc004e;
            --dark-bg: #1a1a1a;
            --light-bg: #f5f5f5;
        }
        
        body {
            background: linear-gradient(135deg, var(--primary-color) 0%, var(--secondary-color) 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
        }
        
        .login-container {
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.1);
            overflow: hidden;
            width: 100%;
            max-width: 1000px;
            display: flex;
            min-height: 600px;
        }
        
        .login-left {
            flex: 1;
            background: var(--dark-bg);
            color: white;
            padding: 60px;
            display: flex;
            flex-direction: column;
            justify-content: center;
            position: relative;
            overflow: hidden;
        }
        
        .login-left::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(25,118,210,0.1) 0%, transparent 70%);
            animation: pulse 15s ease-in-out infinite;
        }
        
        @keyframes pulse {
            0%, 100% { transform: scale(1); opacity: 0.5; }
            50% { transform: scale(1.1); opacity: 0.3; }
        }
        
        .login-right {
            flex: 1;
            padding: 60px;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }
        
        .logo {
            font-size: 3rem;
            font-weight: bold;
            margin-bottom: 20px;
            position: relative;
            z-index: 1;
        }
        
        .logo i {
            margin-right: 15px;
            color: var(--primary-color);
        }
        
        .tagline {
            font-size: 1.2rem;
            opacity: 0.8;
            margin-bottom: 40px;
            position: relative;
            z-index: 1;
        }
        
        .feature-list {
            list-style: none;
            padding: 0;
            position: relative;
            z-index: 1;
        }
        
        .feature-list li {
            margin-bottom: 15px;
            display: flex;
            align-items: center;
        }
        
        .feature-list i {
            margin-right: 10px;
            color: var(--primary-color);
        }
        
        .login-form h2 {
            color: var(--dark-bg);
            margin-bottom: 30px;
            font-weight: 600;
        }
        
        .form-floating {
            margin-bottom: 20px;
        }
        
        .form-control {
            border: 2px solid #e0e0e0;
            border-radius: 10px;
            padding: 15px;
            font-size: 16px;
            transition: all 0.3s ease;
        }
        
        .form-control:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 0.2rem rgba(25,118,210,0.1);
        }
        
        .btn-login {
            background: var(--primary-color);
            color: white;
            border: none;
            border-radius: 10px;
            padding: 15px;
            font-size: 18px;
            font-weight: 600;
            width: 100%;
            transition: all 0.3s ease;
            margin-top: 20px;
        }
        
        .btn-login:hover {
            background: #1565c0;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(25,118,210,0.3);
        }
        
        .alert {
            border-radius: 10px;
            padding: 15px 20px;
            margin-bottom: 20px;
            border: none;
        }
        
        .alert-danger {
            background: #ffebee;
            color: #c62828;
        }
        
        .alert-success {
            background: #e8f5e9;
            color: #2e7d32;
        }
        
        .form-check {
            margin-top: 15px;
        }
        
        .links {
            text-align: center;
            margin-top: 30px;
        }
        
        .links a {
            color: var(--primary-color);
            text-decoration: none;
            margin: 0 10px;
            transition: all 0.3s ease;
        }
        
        .links a:hover {
            text-decoration: underline;
        }
        
        @media (max-width: 768px) {
            .login-container {
                flex-direction: column;
                max-width: 400px;
            }
            
            .login-left {
                padding: 40px;
                min-height: 200px;
            }
            
            .login-right {
                padding: 40px;
            }
            
            .logo {
                font-size: 2rem;
            }
            
            .feature-list {
                display: none;
            }
        }
        
        .password-toggle {
            position: absolute;
            right: 15px;
            top: 50%;
            transform: translateY(-50%);
            cursor: pointer;
            color: #666;
            z-index: 10;
        }
        
        .password-toggle:hover {
            color: var(--primary-color);
        }
        
        .input-group {
            position: relative;
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-left">
            <div class="logo">
                <i class="fas fa-dna"></i>SerteX+
            </div>
            <p class="tagline">Sistema di Gestione per Laboratorio di Analisi Genetiche</p>
            <ul class="feature-list">
                <li><i class="fas fa-check-circle"></i> Gestione completa test genetici</li>
                <li><i class="fas fa-check-circle"></i> Analisi microbiota e intolleranze</li>
                <li><i class="fas fa-check-circle"></i> Referti digitali crittografati</li>
                <li><i class="fas fa-check-circle"></i> Fatturazione elettronica integrata</li>
                <li><i class="fas fa-check-circle"></i> Conformità GDPR e privacy</li>
            </ul>
        </div>
        
        <div class="login-right">
            <form method="post" class="login-form" id="loginForm">
                <h2>Accedi al Sistema</h2>
                
                <?php if ($error): ?>
                    <div class="alert alert-danger alert-dismissible fade show" role="alert">
                        <i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($error); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>
                
                <?php if ($success): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($success); ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                <?php endif; ?>
                
                <div class="form-floating">
                    <input type="text" 
                           class="form-control" 
                           id="username" 
                           name="username" 
                           placeholder="Username o Email"
                           value="<?php echo isset($_POST['username']) ? htmlspecialchars($_POST['username']) : ''; ?>"
                           required 
                           autofocus>
                    <label for="username"><i class="fas fa-user me-2"></i>Username o Email</label>
                </div>
                
                <div class="form-floating">
                    <div class="input-group">
                        <input type="password" 
                               class="form-control" 
                               id="password" 
                               name="password" 
                               placeholder="Password"
                               required>
                        <span class="password-toggle" onclick="togglePassword()">
                            <i class="fas fa-eye" id="toggleIcon"></i>
                        </span>
                    </div>
                    <label for="password"><i class="fas fa-lock me-2"></i>Password</label>
                </div>
                
                <div class="form-check">
                    <input class="form-check-input" 
                           type="checkbox" 
                           id="remember" 
                           name="remember"
                           <?php echo isset($_POST['remember']) ? 'checked' : ''; ?>>
                    <label class="form-check-label" for="remember">
                        Ricordami per 30 giorni
                    </label>
                </div>
                
                <button type="submit" class="btn btn-login">
                    <i class="fas fa-sign-in-alt me-2"></i>Accedi
                </button>
                
                <div class="links">
                    <a href="forgot_password.php">Password dimenticata?</a>
                    <span class="text-muted">•</span>
                    <a href="public/download.php">Area Pazienti</a>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Scripts -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Toggle password visibility
        function togglePassword() {
            const passwordField = document.getElementById('password');
            const toggleIcon = document.getElementById('toggleIcon');
            
            if (passwordField.type === 'password') {
                passwordField.type = 'text';
                toggleIcon.classList.remove('fa-eye');
                toggleIcon.classList.add('fa-eye-slash');
            } else {
                passwordField.type = 'password';
                toggleIcon.classList.remove('fa-eye-slash');
                toggleIcon.classList.add('fa-eye');
            }
        }
        
        // Auto-hide alerts after 5 seconds
        setTimeout(() => {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(alert => {
                const bsAlert = new bootstrap.Alert(alert);
                bsAlert.close();
            });
        }, 5000);
        
        // Prevent form resubmission on refresh
        if (window.history.replaceState) {
            window.history.replaceState(null, null, window.location.href);
        }
    </script>
</body>
</html>