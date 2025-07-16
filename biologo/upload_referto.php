<?php
session_start();
require_once '../includes/auth.php';
require_once '../includes/database.php';
require_once '../includes/functions.php';
require_once '../includes/security.php';
require_once '../classes/Report.php';

// Verifica autenticazione
if (!Auth::check() || Auth::getUserType() !== 'biologo') {
    redirect('/index.php');
}

$db = Database::getInstance()->getConnection();
$user = Auth::getUser();

// Recupera test ID
$test_id = (int)($_GET['test_id'] ?? $_POST['test_id'] ?? 0);
if (!$test_id) {
    $_SESSION['error'] = "Test non specificato.";
    redirect('tests.php');
}

// Verifica che il test esista e sia di tipo microbiota
$stmt = $db->prepare("
    SELECT t.*, 
           CONCAT(LEFT(p.nome, 1), REPEAT('*', LENGTH(p.nome) - 2), RIGHT(p.nome, 1)) AS paziente_nome_pseudo,
           CONCAT(LEFT(p.cognome, 1), REPEAT('*', LENGTH(p.cognome) - 2), RIGHT(p.cognome, 1)) AS paziente_cognome_pseudo,
           p.codice_fiscale,
           r.id AS referto_esistente
    FROM test t
    JOIN pazienti p ON t.paziente_id = p.id
    LEFT JOIN referti r ON t.id = r.test_id
    WHERE t.id = ? AND t.tipo_test = 'microbiota' AND t.stato IN ('in_lavorazione', 'eseguito')
");
$stmt->execute([$test_id]);
$test = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$test) {
    $_SESSION['error'] = "Test non trovato o non autorizzato.";
    redirect('tests.php');
}

// Gestione upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['referto_file'])) {
    try {
        $file = $_FILES['referto_file'];
        
        // Validazione file
        if ($file['error'] !== UPLOAD_ERR_OK) {
            throw new Exception('Errore durante il caricamento del file.');
        }
        
        // Verifica tipo file (solo PDF)
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);
        
        if ($mime !== 'application/pdf') {
            throw new Exception('Solo file PDF sono ammessi.');
        }
        
        // Verifica dimensione (max 10MB)
        if ($file['size'] > 10485760) {
            throw new Exception('Il file non deve superare i 10MB.');
        }
        
        // Crea directory se non esiste
        $upload_dir = REFERTI_PATH . '/microbiota/' . date('Y/m');
        ensureDirectory($upload_dir);
        
        // Genera nome file sicuro
        $filename = generateSecureFilename($file['name'], 'microbiota_' . $test['codice']);
        $filepath = $upload_dir . '/' . $filename;
        
        // Sposta file
        if (!move_uploaded_file($file['tmp_name'], $filepath)) {
            throw new Exception('Impossibile salvare il file.');
        }
        
        // Calcola hash del file
        $file_hash = calculateFileHash($filepath);
        
        $db->beginTransaction();
        
        // Se esiste già un referto, aggiornalo
        if ($test['referto_esistente']) {
            // Elimina vecchio file se esiste
            $stmt = $db->prepare("SELECT file_path FROM referti WHERE id = ?");
            $stmt->execute([$test['referto_esistente']]);
            $old_file = $stmt->fetchColumn();
            
            if ($old_file && file_exists($old_file)) {
                unlink($old_file);
            }
            
            // Aggiorna record
            $stmt = $db->prepare("
                UPDATE referti 
                SET file_path = ?, hash_file = ?, data_creazione = NOW(), file_path_firmato = NULL, data_firma = NULL
                WHERE id = ?
            ");
            $stmt->execute([$filepath, $file_hash, $test['referto_esistente']]);
            
        } else {
            // Crea nuovo referto
            $stmt = $db->prepare("
                INSERT INTO referti (test_id, tipo_referto, file_path, biologo_id, hash_file, data_creazione)
                VALUES (?, 'microbiota', ?, ?, ?, NOW())
            ");
            $stmt->execute([$test_id, $filepath, $user['id'], $file_hash]);
        }
        
        // Aggiorna stato test
        $stmt = $db->prepare("UPDATE test SET stato = 'refertato', data_refertazione = NOW() WHERE id = ?");
        $stmt->execute([$test_id]);
        
        // Log attività
        logActivity($db, $user['id'], 'upload_referto_microbiota', 
                   "Upload referto microbiota test ID: $test_id, File: $filename");
        
        $db->commit();
        
        $_SESSION['success'] = "Referto caricato con successo. Ora puoi scaricarlo per la firma digitale.";
        redirect('reports.php');
        
    } catch (Exception $e) {
        if (isset($db) && $db->inTransaction()) {
            $db->rollBack();
        }
        if (isset($filepath) && file_exists($filepath)) {
            unlink($filepath);
        }
        $_SESSION['error'] = $e->getMessage();
    }
}

$page_title = 'Upload Referto Microbiota';
require_once '../templates/header.php';
require_once '../templates/sidebar.php';
?>

<div class="container-fluid">
    <div class="row">
        <div class="col-md-8 mx-auto">
            <div class="card">
                <div class="card-header bg-success text-white">
                    <h4 class="mb-0">
                        <i class="fas fa-upload"></i> Upload Referto Microbiota
                    </h4>
                </div>
                <div class="card-body">
                    <!-- Info Test -->
                    <div class="alert alert-info">
                        <div class="row">
                            <div class="col-md-6">
                                <strong>Codice Test:</strong> <?php echo htmlspecialchars($test['codice']); ?><br>
                                <strong>Paziente:</strong> <?php echo htmlspecialchars($test['paziente_cognome_pseudo'] . ' ' . $test['paziente_nome_pseudo']); ?>
                            </div>
                            <div class="col-md-6">
                                <strong>Data Richiesta:</strong> <?php echo date('d/m/Y', strtotime($test['data_richiesta'])); ?><br>
                                <strong>Stato:</strong> <span class="badge bg-warning"><?php echo ucfirst($test['stato']); ?></span>
                            </div>
                        </div>
                    </div>

                    <?php if (isset($_SESSION['error'])): ?>
                        <div class="alert alert-danger alert-dismissible fade show">
                            <?php 
                            echo $_SESSION['error'];
                            unset($_SESSION['error']);
                            ?>
                            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                        </div>
                    <?php endif; ?>

                    <?php if ($test['referto_esistente']): ?>
                        <div class="alert alert-warning">
                            <i class="fas fa-exclamation-triangle"></i>
                            <strong>Attenzione:</strong> È già presente un referto per questo test. 
                            Caricando un nuovo file, quello esistente verrà sostituito.
                        </div>
                    <?php endif; ?>

                    <!-- Form Upload -->
                    <form method="POST" enctype="multipart/form-data" id="uploadForm">
                        <input type="hidden" name="test_id" value="<?php echo $test_id; ?>">
                        
                        <div class="mb-4">
                            <label for="referto_file" class="form-label">
                                <strong>Seleziona il file PDF del referto</strong>
                            </label>
                            <input type="file" 
                                   class="form-control form-control-lg" 
                                   id="referto_file" 
                                   name="referto_file" 
                                   accept=".pdf"
                                   required>
                            <div class="form-text">
                                Formati accettati: PDF. Dimensione massima: 10MB
                            </div>
                        </div>

                        <div class="file-upload-area text-center p-5 mb-4" 
                             style="border: 2px dashed #dee2e6; border-radius: 10px; cursor: pointer;"
                             onclick="document.getElementById('referto_file').click()">
                            <i class="fas fa-cloud-upload-alt fa-4x text-muted mb-3"></i>
                            <p class="mb-0">Clicca qui o trascina il file PDF del referto</p>
                            <p class="text-muted small">Il file deve contenere i risultati completi dell'analisi del microbiota</p>
                        </div>

                        <div id="fileInfo" class="alert alert-success" style="display: none;">
                            <i class="fas fa-file-pdf"></i>
                            <span id="fileName"></span>
                            <span id="fileSize" class="ms-2"></span>
                        </div>

                        <!-- Istruzioni -->
                        <div class="alert alert-light">
                            <h6>Istruzioni:</h6>
                            <ol class="mb-0">
                                <li>Carica il file PDF contenente il referto completo dell'analisi del microbiota</li>
                                <li>Dopo il caricamento, il sistema genererà un hash per la verifica dell'autenticità</li>
                                <li>Potrai scaricare il referto per apporre la firma digitale</li>
                                <li>Una volta firmato, ricarica il file firmato per completare il processo</li>
                            </ol>
                        </div>

                        <div class="d-flex justify-content-between">
                            <a href="tests.php" class="btn btn-secondary">
                                <i class="fas fa-arrow-left"></i> Annulla
                            </a>
                            <button type="submit" class="btn btn-success btn-lg" id="uploadBtn" disabled>
                                <i class="fas fa-upload"></i> Carica Referto
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// Gestione file upload
document.getElementById('referto_file').addEventListener('change', function(e) {
    const file = e.target.files[0];
    const fileInfo = document.getElementById('fileInfo');
    const uploadBtn = document.getElementById('uploadBtn');
    
    if (file) {
        // Verifica tipo
        if (file.type !== 'application/pdf') {
            alert('Solo file PDF sono ammessi!');
            this.value = '';
            fileInfo.style.display = 'none';
            uploadBtn.disabled = true;
            return;
        }
        
        // Verifica dimensione
        if (file.size > 10485760) { // 10MB
            alert('Il file è troppo grande! Massimo 10MB.');
            this.value = '';
            fileInfo.style.display = 'none';
            uploadBtn.disabled = true;
            return;
        }
        
        // Mostra info file
        document.getElementById('fileName').textContent = file.name;
        document.getElementById('fileSize').textContent = '(' + formatFileSize(file.size) + ')';
        fileInfo.style.display = 'block';
        uploadBtn.disabled = false;
    } else {
        fileInfo.style.display = 'none';
        uploadBtn.disabled = true;
    }
});

// Drag & Drop
const uploadArea = document.querySelector('.file-upload-area');

uploadArea.addEventListener('dragover', (e) => {
    e.preventDefault();
    uploadArea.style.backgroundColor = '#e9ecef';
});

uploadArea.addEventListener('dragleave', () => {
    uploadArea.style.backgroundColor = 'transparent';
});

uploadArea.addEventListener('drop', (e) => {
    e.preventDefault();
    uploadArea.style.backgroundColor = 'transparent';
    
    const files = e.dataTransfer.files;
    if (files.length > 0) {
        document.getElementById('referto_file').files = files;
        document.getElementById('referto_file').dispatchEvent(new Event('change'));
    }
});

// Formatta dimensione file
function formatFileSize(bytes) {
    if (bytes === 0) return '0 Bytes';
    const k = 1024;
    const sizes = ['Bytes', 'KB', 'MB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
}

// Conferma prima di inviare
document.getElementById('uploadForm').addEventListener('submit', function(e) {
    if (!confirm('Confermi di voler caricare questo referto?')) {
        e.preventDefault();
    }
});
</script>

<?php require_once '../templates/footer.php'; ?>