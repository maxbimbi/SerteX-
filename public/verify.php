<?php
session_start();
require_once '../includes/database.php';
require_once '../includes/functions.php';
require_once '../includes/security.php';

$db = Database::getInstance()->getConnection();
$result = null;
$error = '';

// Gestione form di verifica
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $codice_test = sanitizeInput($_POST['codice_test'] ?? '');
    $hash_file = sanitizeInput($_POST['hash_file'] ?? '');
    
    if (empty($codice_test) || empty($hash_file)) {
        $error = 'Compilare tutti i campi.';
    } else {
        // Verifica referto
        $stmt = $db->prepare("
            SELECT 
                t.codice,
                t.data_richiesta,
                t.tipo_test,
                p.nome,
                p.cognome,
                r.data_creazione AS data_refertazione,
                r.data_firma,
                r.hash_file,
                r.tipo_referto,
                u.nome AS biologo_nome,
                u.cognome AS biologo_cognome
            FROM test t
            JOIN pazienti p ON t.paziente_id = p.id
            JOIN referti r ON t.id = r.test_id
            JOIN utenti u ON r.biologo_id = u.id
            WHERE t.codice = ? AND r.hash_file = ?
        ");
        
        $stmt->execute([$codice_test, $hash_file]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$result) {
            $error = 'Referto non trovato o hash non valido.';
            // Log tentativo fallito
            logActivity($db, null, 'verifica_referto_fallita', 
                       "Verifica fallita - Codice: $codice_test, Hash: $hash_file, IP: " . $_SERVER['REMOTE_ADDR']);
        } else {
            // Log verifica riuscita
            logActivity($db, null, 'verifica_referto', 
                       "Verifica referto - Codice: $codice_test, IP: " . $_SERVER['REMOTE_ADDR']);
        }
    }
}

// HTML della pagina
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verifica Referto - SerteX+</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        body {
            background-color: #f8f9fa;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        
        .verify-container {
            max-width: 600px;
            width: 100%;
            background: white;
            border-radius: 10px;
            box-shadow: 0 0 20px rgba(0,0,0,0.1);
            overflow: hidden;
        }
        
        .verify-header {
            background: linear-gradient(135deg, #4caf50 0%, #43a047 100%);
            color: white;
            padding: 2rem;
            text-align: center;
        }
        
        .verify-body {
            padding: 2rem;
        }
        
        .logo {
            font-size: 2rem;
            font-weight: bold;
            margin-bottom: 0.5rem;
        }
        
        .form-control:focus {
            border-color: #4caf50;
            box-shadow: 0 0 0 0.2rem rgba(76, 175, 80, 0.25);
        }
        
        .btn-success {
            background-color: #4caf50;
            border-color: #4caf50;
        }
        
        .btn-success:hover {
            background-color: #43a047;
            border-color: #43a047;
        }
        
        .info-box {
            background-color: #e8f5e9;
            border-left: 4px solid #4caf50;
            padding: 1rem;
            margin-bottom: 1.5rem;
        }
        
        .result-box {
            background-color: #f5f5f5;
            border-radius: 8px;
            padding: 1.5rem;
            margin-top: 1.5rem;
        }
        
        .result-valid {
            border-left: 4px solid #4caf50;
            background-color: #e8f5e9;
        }
        
        .result-invalid {
            border-left: 4px solid #f44336;
            background-color: #ffebee;
        }
        
        .detail-row {
            padding: 0.5rem 0;
            border-bottom: 1px solid #e0e0e0;
        }
        
        .detail-row:last-child {
            border-bottom: none;
        }
        
        .detail-label {
            font-weight: 600;
            color: #555;
        }
    </style>
</head>
<body>
    <div class="verify-container">
        <div class="verify-header">
            <div class="logo">
                <i class="fas fa-check-circle"></i> SerteX+
            </div>
            <h4 class="mb-0">Verifica Autenticità Referto</h4>
        </div>
        
        <div class="verify-body">
            <div class="info-box">
                <i class="fas fa-info-circle"></i>
                <strong>Come verificare:</strong> Inserisci il codice del test e l'hash SHA-256 
                che trovi sul referto per verificarne l'autenticità.
            </div>
            
            <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show">
                    <i class="fas fa-exclamation-triangle"></i>
                    <?php echo htmlspecialchars($error); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
            
            <?php if ($result): ?>
                <div class="result-box result-valid">
                    <h5 class="text-success mb-3">
                        <i class="fas fa-check-circle"></i> Referto Autentico
                    </h5>
                    
                    <div class="detail-row">
                        <span class="detail-label">Codice Test:</span>
                        <strong><?php echo htmlspecialchars($result['codice']); ?></strong>
                    </div>
                    
                    <div class="detail-row">
                        <span class="detail-label">Paziente:</span>
                        <?php echo htmlspecialchars($result['cognome'] . ' ' . $result['nome']); ?>
                    </div>
                    
                    <div class="detail-row">
                        <span class="detail-label">Tipo Test:</span>
                        <?php 
                        $tipi = [
                            'genetico' => 'Test Genetico',
                            'microbiota' => 'Analisi Microbiota',
                            'intolleranze_cito' => 'Intolleranze Citotossiche',
                            'intolleranze_elisa' => 'Intolleranze ELISA'
                        ];
                        echo $tipi[$result['tipo_test']] ?? $result['tipo_test'];
                        ?>
                    </div>
                    
                    <div class="detail-row">
                        <span class="detail-label">Data Richiesta:</span>
                        <?php echo date('d/m/Y', strtotime($result['data_richiesta'])); ?>
                    </div>
                    
                    <div class="detail-row">
                        <span class="detail-label">Data Refertazione:</span>
                        <?php echo date('d/m/Y H:i', strtotime($result['data_refertazione'])); ?>
                    </div>
                    
                    <?php if ($result['data_firma']): ?>
                        <div class="detail-row">
                            <span class="detail-label">Data Firma Digitale:</span>
                            <?php echo date('d/m/Y H:i', strtotime($result['data_firma'])); ?>
                        </div>
                    <?php endif; ?>
                    
                    <div class="detail-row">
                        <span class="detail-label">Biologo Refertante:</span>
                        Dr. <?php echo htmlspecialchars($result['biologo_cognome'] . ' ' . $result['biologo_nome']); ?>
                    </div>
                    
                    <div class="mt-3 p-3 bg-light rounded">
                        <small class="text-muted">
                            <i class="fas fa-shield-alt"></i>
                            Questo referto è stato emesso dal laboratorio SerteX+ ed è autentico.
                            L'hash SHA-256 corrisponde a quello registrato nel nostro sistema.
                        </small>
                    </div>
                </div>
            <?php else: ?>
                <form method="POST" autocomplete="off">
                    <div class="mb-3">
                        <label for="codice_test" class="form-label">
                            <i class="fas fa-barcode"></i> Codice Test
                        </label>
                        <input type="text" 
                               class="form-control" 
                               id="codice_test" 
                               name="codice_test" 
                               placeholder="Es: TEST202501150001"
                               required
                               autocomplete="off">
                    </div>
                    
                    <div class="mb-4">
                        <label for="hash_file" class="form-label">
                            <i class="fas fa-fingerprint"></i> Hash SHA-256
                        </label>
                        <input type="text" 
                               class="form-control" 
                               id="hash_file" 
                               name="hash_file" 
                               placeholder="Es: 3b4c5d6e7f8a9b0c1d2e3f4g5h6i7j8k9l0m1n2o3p4q5r6s7t8u9v0w1x2y3z4"
                               pattern="[a-fA-F0-9]{64}"
                               title="Inserire un hash SHA-256 valido (64 caratteri esadecimali)"
                               required
                               autocomplete="off">
                        <div class="form-text">
                            L'hash si trova in fondo al referto, dopo la firma del biologo.
                        </div>
                    </div>
                    
                    <button type="submit" class="btn btn-success btn-lg w-100">
                        <i class="fas fa-search"></i> Verifica Autenticità
                    </button>
                </form>
            <?php endif; ?>
            
            <div class="text-center mt-4">
                <a href="download.php" class="text-decoration-none">
                    <i class="fas fa-download"></i> Scarica referto
                </a>
                <span class="mx-2">|</span>
                <a href="/" class="text-decoration-none">
                    <i class="fas fa-home"></i> Home
                </a>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>