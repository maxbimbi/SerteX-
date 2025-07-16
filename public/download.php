<?php
session_start();
require_once '../includes/database.php';
require_once '../includes/functions.php';
require_once '../includes/security.php';
require_once '../vendor/autoload.php';

use setasign\Fpdi\Fpdi;
use setasign\Fpdi\PdfParser\CrossReference\CrossReferenceException;

$db = Database::getInstance()->getConnection();
$error = '';
$success = false;

// Gestione form di download
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $codice_test = sanitizeInput($_POST['codice_test'] ?? '');
    $codice_fiscale = strtoupper(sanitizeInput($_POST['codice_fiscale'] ?? ''));
    
    if (empty($codice_test) || empty($codice_fiscale)) {
        $error = 'Compilare tutti i campi.';
    } else {
        // Verifica test e paziente
        $stmt = $db->prepare("
            SELECT 
                t.id AS test_id,
                t.codice,
                t.stato,
                p.nome,
                p.cognome,
                p.codice_fiscale,
                r.id AS referto_id,
                r.file_path,
                r.file_path_firmato,
                r.data_creazione,
                r.tipo_referto
            FROM test t
            JOIN pazienti p ON t.paziente_id = p.id
            LEFT JOIN referti r ON t.id = r.test_id
            WHERE t.codice = ? AND p.codice_fiscale = ?
        ");
        
        $stmt->execute([$codice_test, $codice_fiscale]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$result) {
            $error = 'Codice test o codice fiscale non validi.';
            // Log tentativo fallito
            logActivity($db, null, 'download_referto_fallito', 
                       "Tentativo fallito - Codice: $codice_test, CF: $codice_fiscale, IP: " . $_SERVER['REMOTE_ADDR']);
        } elseif (!$result['referto_id']) {
            $error = 'Il referto non è ancora disponibile.';
        } else {
            // Verifica scadenza (45 giorni)
            $data_creazione = new DateTime($result['data_creazione']);
            $oggi = new DateTime();
            $diff = $oggi->diff($data_creazione);
            
            if ($diff->days > 45) {
                $error = 'Il referto è scaduto. Contattare il laboratorio per richiederne una copia.';
            } else {
                // Determina quale file usare (preferisci firmato se disponibile)
                $file_path = $result['file_path_firmato'] ?: $result['file_path'];
                
                if (!file_exists($file_path)) {
                    $error = 'File referto non trovato. Contattare il laboratorio.';
                } else {
                    try {
                        // Crea PDF protetto da password
                        $pdf = new Fpdi();
                        
                        // Aggiungi tutte le pagine del PDF originale
                        $pageCount = $pdf->setSourceFile($file_path);
                        
                        for ($pageNo = 1; $pageNo <= $pageCount; $pageNo++) {
                            $templateId = $pdf->importPage($pageNo);
                            $pdf->AddPage();
                            $pdf->useTemplate($templateId);
                        }
                        
                        // Imposta protezione con password (codice fiscale)
                        $pdf->SetProtection(
                            ['print', 'copy'], // Permessi
                            $codice_fiscale,   // Password utente
                            null,              // Password proprietario
                            1                  // Tipo di crittografia
                        );
                        
                        // Log download
                        logActivity($db, null, 'download_referto_pubblico', 
                                   "Download pubblico referto ID: {$result['referto_id']}, Test: $codice_test, IP: " . $_SERVER['REMOTE_ADDR']);
                        
                        // Output del PDF
                        header('Content-Type: application/pdf');
                        header('Content-Disposition: attachment; filename="Referto_' . 
                               $result['codice'] . '_' . 
                               $result['cognome'] . '.pdf"');
                        $pdf->Output('D');
                        exit;
                        
                    } catch (Exception $e) {
                        $error = 'Errore nella generazione del PDF protetto. Riprovare più tardi.';
                        error_log("Errore PDF: " . $e->getMessage());
                    }
                }
            }
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
    <title>Download Referto - SerteX+</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        body {
            background-color: #f8f9fa;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .download-container {
            max-width: 500px;
            width: 100%;
            background: white;
            border-radius: 10px;
            box-shadow: 0 0 20px rgba(0,0,0,0.1);
            overflow: hidden;
        }
        
        .download-header {
            background: linear-gradient(135deg, #1976d2 0%, #1565c0 100%);
            color: white;
            padding: 2rem;
            text-align: center;
        }
        
        .download-body {
            padding: 2rem;
        }
        
        .logo {
            font-size: 2rem;
            font-weight: bold;
            margin-bottom: 0.5rem;
        }
        
        .form-control:focus {
            border-color: #1976d2;
            box-shadow: 0 0 0 0.2rem rgba(25, 118, 210, 0.25);
        }
        
        .btn-primary {
            background-color: #1976d2;
            border-color: #1976d2;
        }
        
        .btn-primary:hover {
            background-color: #1565c0;
            border-color: #1565c0;
        }
        
        .info-box {
            background-color: #e3f2fd;
            border-left: 4px solid #1976d2;
            padding: 1rem;
            margin-bottom: 1.5rem;
        }
        
        .privacy-notice {
            font-size: 0.875rem;
            color: #666;
            margin-top: 1.5rem;
            padding-top: 1.5rem;
            border-top: 1px solid #dee2e6;
        }
    </style>
</head>
<body>
    <div class="download-container">
        <div class="download-header">
            <div class="logo">
                <i class="fas fa-dna"></i> SerteX+
            </div>
            <h4 class="mb-0">Download Referto</h4>
        </div>
        
        <div class="download-body">
            <div class="info-box">
                <i class="fas fa-info-circle"></i>
                <strong>Importante:</strong> Il referto sarà protetto con il tuo codice fiscale come password.
                I referti sono disponibili per 45 giorni dalla data di emissione.
            </div>
            
            <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show">
                    <i class="fas fa-exclamation-triangle"></i>
                    <?php echo htmlspecialchars($error); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
            
            <form method="POST" autocomplete="off">
                <div class="mb-3">
                    <label for="codice_test" class="form-label">
                        <i class="fas fa-barcode"></i> Codice Test
                    </label>
                    <input type="text" 
                           class="form-control form-control-lg" 
                           id="codice_test" 
                           name="codice_test" 
                           placeholder="Es: TEST202501150001"
                           required
                           autocomplete="off">
                    <div class="form-text">
                        Il codice test ti è stato fornito dal tuo medico o dal laboratorio.
                    </div>
                </div>
                
                <div class="mb-4">
                    <label for="codice_fiscale" class="form-label">
                        <i class="fas fa-id-card"></i> Codice Fiscale
                    </label>
                    <input type="text" 
                           class="form-control form-control-lg" 
                           id="codice_fiscale" 
                           name="codice_fiscale" 
                           placeholder="Es: RSSMRA85T10A562S"
                           pattern="[A-Za-z]{6}[0-9]{2}[A-Za-z][0-9]{2}[A-Za-z][0-9]{3}[A-Za-z]"
                           title="Inserire un codice fiscale valido"
                           required
                           autocomplete="off"
                           style="text-transform: uppercase;">
                    <div class="form-text">
                        Il codice fiscale verrà utilizzato come password per aprire il PDF.
                    </div>
                </div>
                
                <button type="submit" class="btn btn-primary btn-lg w-100">
                    <i class="fas fa-download"></i> Scarica Referto
                </button>
            </form>
            
            <div class="privacy-notice">
                <i class="fas fa-shield-alt"></i>
                <strong>Privacy e Sicurezza:</strong> I tuoi dati sono protetti secondo la normativa GDPR. 
                Il download viene registrato per motivi di sicurezza. 
                Per assistenza, contatta il laboratorio.
            </div>
            
            <div class="text-center mt-3">
                <a href="verify.php" class="text-decoration-none">
                    <i class="fas fa-check-circle"></i> Verifica autenticità referto
                </a>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Converti automaticamente il codice fiscale in maiuscolo
        document.getElementById('codice_fiscale').addEventListener('input', function(e) {
            e.target.value = e.target.value.toUpperCase();
        });
    </script>
</body>
</html>