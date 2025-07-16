<?php
session_start();
require_once '../includes/auth.php';
require_once '../includes/database.php';
require_once '../includes/functions.php';
require_once '../classes/User.php';
require_once '../classes/Patient.php';
require_once '../classes/Test.php';
require_once '../classes/Gene.php';
require_once '../classes/Panel.php';

// Verifica autenticazione
if (!Auth::check() || Auth::getUserType() !== 'professionista') {
    redirect('/index.php');
}

$db = Database::getInstance()->getConnection();
$user = Auth::getUser();
$professionista_id = getProfessionistaId($db, $user['id']);

// Gestione azioni
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    switch ($action) {
        case 'add_test':
            $paziente_id = (int)$_POST['paziente_id'];
            $tipo_test = $_POST['tipo_test'];
            
            // Verifica che il paziente appartenga al professionista
            $stmt = $db->prepare("SELECT id FROM pazienti WHERE id = ? AND professionista_id = ?");
            $stmt->execute([$paziente_id, $professionista_id]);
            
            if ($stmt->rowCount() > 0) {
                $test = new Test($db);
                $codice = generateTestCode($db);
                
                $test_data = [
                    'codice' => $codice,
                    'paziente_id' => $paziente_id,
                    'professionista_id' => $professionista_id,
                    'tipo_test' => $tipo_test,
                    'stato' => 'richiesto',
                    'barcode' => generateBarcode($codice),
                    'qrcode' => generateQRCode($codice)
                ];
                
                if ($test->create($test_data)) {
                    $test_id = $db->lastInsertId();
                    $_SESSION['test_id'] = $test_id;
                    
                    // Redirect alla pagina specifica per aggiungere dettagli
                    switch ($tipo_test) {
                        case 'genetico':
                            redirect('test_genetico_dettagli.php?id=' . $test_id);
                            break;
                        case 'microbiota':
                            redirect('test_microbiota_dettagli.php?id=' . $test_id);
                            break;
                        case 'intolleranze_cito':
                        case 'intolleranze_elisa':
                            redirect('test_intolleranze_dettagli.php?id=' . $test_id);
                            break;
                    }
                }
            }
            break;
            
        case 'delete_test':
            $test_id = (int)$_POST['test_id'];
            
            // Verifica che il test appartenga al professionista e non sia già refertato
            $stmt = $db->prepare("
                SELECT t.id 
                FROM test t 
                WHERE t.id = ? 
                AND t.professionista_id = ? 
                AND t.stato IN ('richiesto', 'in_lavorazione')
            ");
            $stmt->execute([$test_id, $professionista_id]);
            
            if ($stmt->rowCount() > 0) {
                $test = new Test($db);
                $test->delete($test_id);
                $_SESSION['success'] = "Test eliminato con successo.";
            } else {
                $_SESSION['error'] = "Impossibile eliminare il test.";
            }
            redirect('tests.php');
            break;
    }
}

// Recupera filtri
$filtro_paziente = $_GET['paziente'] ?? '';
$filtro_stato = $_GET['stato'] ?? '';
$filtro_tipo = $_GET['tipo'] ?? '';
$filtro_data_da = $_GET['data_da'] ?? '';
$filtro_data_a = $_GET['data_a'] ?? '';

// Query per recuperare i test
$query = "
    SELECT 
        t.*,
        p.nome AS paziente_nome,
        p.cognome AS paziente_cognome,
        p.codice_fiscale,
        COUNT(r.id) AS has_report
    FROM test t
    JOIN pazienti p ON t.paziente_id = p.id
    LEFT JOIN referti r ON t.id = r.test_id
    WHERE t.professionista_id = ?
";

$params = [$professionista_id];

if ($filtro_paziente) {
    $query .= " AND (p.nome LIKE ? OR p.cognome LIKE ? OR p.codice_fiscale LIKE ?)";
    $search = "%$filtro_paziente%";
    $params[] = $search;
    $params[] = $search;
    $params[] = $search;
}

if ($filtro_stato) {
    $query .= " AND t.stato = ?";
    $params[] = $filtro_stato;
}

if ($filtro_tipo) {
    $query .= " AND t.tipo_test = ?";
    $params[] = $filtro_tipo;
}

if ($filtro_data_da) {
    $query .= " AND DATE(t.data_richiesta) >= ?";
    $params[] = $filtro_data_da;
}

if ($filtro_data_a) {
    $query .= " AND DATE(t.data_richiesta) <= ?";
    $params[] = $filtro_data_a;
}

$query .= " GROUP BY t.id ORDER BY t.data_richiesta DESC";

$stmt = $db->prepare($query);
$stmt->execute($params);
$tests = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Recupera pazienti per select
$stmt = $db->prepare("SELECT id, nome, cognome, codice_fiscale FROM pazienti WHERE professionista_id = ? ORDER BY cognome, nome");
$stmt->execute([$professionista_id]);
$pazienti = $stmt->fetchAll(PDO::FETCH_ASSOC);

$page_title = 'Gestione Test';
require_once '../templates/header.php';
require_once '../templates/sidebar.php';
?>

<div class="container-fluid">
    <div class="row">
        <div class="col-md-12">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h1>Gestione Test</h1>
                <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addTestModal">
                    <i class="fas fa-plus"></i> Nuovo Test
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

            <!-- Filtri -->
            <div class="card mb-4">
                <div class="card-body">
                    <form method="GET" class="row g-3">
                        <div class="col-md-3">
                            <label class="form-label">Paziente</label>
                            <input type="text" name="paziente" class="form-control" 
                                   placeholder="Nome, cognome o CF" value="<?php echo htmlspecialchars($filtro_paziente); ?>">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Stato</label>
                            <select name="stato" class="form-select">
                                <option value="">Tutti</option>
                                <option value="richiesto" <?php echo $filtro_stato === 'richiesto' ? 'selected' : ''; ?>>Richiesto</option>
                                <option value="in_lavorazione" <?php echo $filtro_stato === 'in_lavorazione' ? 'selected' : ''; ?>>In lavorazione</option>
                                <option value="eseguito" <?php echo $filtro_stato === 'eseguito' ? 'selected' : ''; ?>>Eseguito</option>
                                <option value="refertato" <?php echo $filtro_stato === 'refertato' ? 'selected' : ''; ?>>Refertato</option>
                                <option value="firmato" <?php echo $filtro_stato === 'firmato' ? 'selected' : ''; ?>>Firmato</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Tipo</label>
                            <select name="tipo" class="form-select">
                                <option value="">Tutti</option>
                                <option value="genetico" <?php echo $filtro_tipo === 'genetico' ? 'selected' : ''; ?>>Genetico</option>
                                <option value="microbiota" <?php echo $filtro_tipo === 'microbiota' ? 'selected' : ''; ?>>Microbiota</option>
                                <option value="intolleranze_cito" <?php echo $filtro_tipo === 'intolleranze_cito' ? 'selected' : ''; ?>>Intolleranze Cito</option>
                                <option value="intolleranze_elisa" <?php echo $filtro_tipo === 'intolleranze_elisa' ? 'selected' : ''; ?>>Intolleranze ELISA</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Data da</label>
                            <input type="date" name="data_da" class="form-control" value="<?php echo $filtro_data_da; ?>">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">Data a</label>
                            <input type="date" name="data_a" class="form-control" value="<?php echo $filtro_data_a; ?>">
                        </div>
                        <div class="col-md-1 d-flex align-items-end">
                            <button type="submit" class="btn btn-secondary w-100">Filtra</button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Tabella test -->
            <div class="card">
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-striped">
                            <thead>
                                <tr>
                                    <th>Codice</th>
                                    <th>Paziente</th>
                                    <th>Tipo Test</th>
                                    <th>Data Richiesta</th>
                                    <th>Stato</th>
                                    <th>Prezzo</th>
                                    <th>Azioni</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($tests as $test): ?>
                                    <tr>
                                        <td>
                                            <code><?php echo htmlspecialchars($test['codice']); ?></code>
                                            <button class="btn btn-sm btn-outline-secondary ms-2" 
                                                    onclick="showBarcode('<?php echo $test['codice']; ?>')">
                                                <i class="fas fa-barcode"></i>
                                            </button>
                                        </td>
                                        <td>
                                            <?php echo htmlspecialchars($test['paziente_cognome'] . ' ' . $test['paziente_nome']); ?><br>
                                            <small class="text-muted"><?php echo htmlspecialchars($test['codice_fiscale']); ?></small>
                                        </td>
                                        <td>
                                            <?php 
                                            $tipo_labels = [
                                                'genetico' => 'Genetico',
                                                'microbiota' => 'Microbiota',
                                                'intolleranze_cito' => 'Intolleranze Cito',
                                                'intolleranze_elisa' => 'Intolleranze ELISA'
                                            ];
                                            echo $tipo_labels[$test['tipo_test']] ?? $test['tipo_test'];
                                            ?>
                                        </td>
                                        <td><?php echo date('d/m/Y H:i', strtotime($test['data_richiesta'])); ?></td>
                                        <td>
                                            <?php
                                            $stato_badges = [
                                                'richiesto' => 'warning',
                                                'in_lavorazione' => 'info',
                                                'eseguito' => 'primary',
                                                'refertato' => 'success',
                                                'firmato' => 'success'
                                            ];
                                            $badge_class = $stato_badges[$test['stato']] ?? 'secondary';
                                            ?>
                                            <span class="badge bg-<?php echo $badge_class; ?>">
                                                <?php echo ucfirst($test['stato']); ?>
                                            </span>
                                        </td>
                                        <td>€ <?php echo number_format($test['prezzo_finale'] ?: $test['prezzo_totale'], 2, ',', '.'); ?></td>
                                        <td>
                                            <div class="btn-group btn-group-sm">
                                                <a href="test_dettaglio.php?id=<?php echo $test['id']; ?>" 
                                                   class="btn btn-outline-primary" title="Visualizza">
                                                    <i class="fas fa-eye"></i>
                                                </a>
                                                
                                                <?php if ($test['has_report'] > 0): ?>
                                                    <a href="download_report.php?test_id=<?php echo $test['id']; ?>" 
                                                       class="btn btn-outline-success" title="Scarica Referto">
                                                        <i class="fas fa-download"></i>
                                                    </a>
                                                <?php endif; ?>
                                                
                                                <?php if (in_array($test['stato'], ['richiesto', 'in_lavorazione'])): ?>
                                                    <button class="btn btn-outline-danger" 
                                                            onclick="deleteTest(<?php echo $test['id']; ?>)"
                                                            title="Elimina">
                                                        <i class="fas fa-trash"></i>
                                                    </button>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal Nuovo Test -->
<div class="modal fade" id="addTestModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" value="add_test">
                <div class="modal-header">
                    <h5 class="modal-title">Nuovo Test</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Paziente*</label>
                        <select name="paziente_id" class="form-select" required>
                            <option value="">Seleziona paziente...</option>
                            <?php foreach ($pazienti as $paziente): ?>
                                <option value="<?php echo $paziente['id']; ?>">
                                    <?php echo htmlspecialchars($paziente['cognome'] . ' ' . $paziente['nome'] . ' - ' . $paziente['codice_fiscale']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Tipo Test*</label>
                        <select name="tipo_test" class="form-select" required>
                            <option value="">Seleziona tipo...</option>
                            <option value="genetico">Test Genetico</option>
                            <option value="microbiota">Analisi Microbiota</option>
                            <option value="intolleranze_cito">Intolleranze Citotossiche</option>
                            <option value="intolleranze_elisa">Intolleranze ELISA</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annulla</button>
                    <button type="submit" class="btn btn-primary">Procedi</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal Barcode -->
<div class="modal fade" id="barcodeModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Codice a Barre</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body text-center">
                <div id="barcodeContainer"></div>
                <div id="qrcodeContainer" class="mt-3"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-primary" onclick="printBarcode()">
                    <i class="fas fa-print"></i> Stampa
                </button>
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Chiudi</button>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.5/dist/JsBarcode.all.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/qrcodejs@1.0.0/qrcode.min.js"></script>
<script>
function deleteTest(testId) {
    if (confirm('Sei sicuro di voler eliminare questo test?')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `
            <input type="hidden" name="action" value="delete_test">
            <input type="hidden" name="test_id" value="${testId}">
        `;
        document.body.appendChild(form);
        form.submit();
    }
}

function showBarcode(code) {
    // Genera barcode
    const barcodeContainer = document.getElementById('barcodeContainer');
    barcodeContainer.innerHTML = '<svg id="barcode"></svg>';
    JsBarcode("#barcode", code, {
        width: 2,
        height: 100,
        displayValue: true
    });
    
    // Genera QR code
    const qrcodeContainer = document.getElementById('qrcodeContainer');
    qrcodeContainer.innerHTML = '';
    new QRCode(qrcodeContainer, {
        text: code,
        width: 200,
        height: 200
    });
    
    // Mostra modal
    new bootstrap.Modal(document.getElementById('barcodeModal')).show();
}

function printBarcode() {
    const content = document.getElementById('barcodeContainer').innerHTML + 
                   document.getElementById('qrcodeContainer').innerHTML;
    const printWindow = window.open('', '', 'height=400,width=600');
    printWindow.document.write('<html><head><title>Stampa Codice</title></head><body>');
    printWindow.document.write(content);
    printWindow.document.write('</body></html>');
    printWindow.document.close();
    printWindow.print();
}
</script>

<?php require_once '../templates/footer.php'; ?>