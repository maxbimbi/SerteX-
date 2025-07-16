<?php
session_start();
require_once '../includes/auth.php';
require_once '../includes/database.php';
require_once '../includes/functions.php';
require_once '../classes/Test.php';

// Verifica autenticazione
if (!Auth::check() || Auth::getUserType() !== 'professionista') {
    redirect('/index.php');
}

$db = Database::getInstance()->getConnection();
$user = Auth::getUser();
$professionista_id = getProfessionistaId($db, $user['id']);

// Verifica ID test
$test_id = (int)($_GET['id'] ?? 0);
if (!$test_id) {
    redirect('tests.php');
}

// Verifica che il test appartenga al professionista e sia microbiota
$stmt = $db->prepare("
    SELECT t.*, p.nome AS paziente_nome, p.cognome AS paziente_cognome, p.codice_fiscale
    FROM test t
    JOIN pazienti p ON t.paziente_id = p.id
    WHERE t.id = ? AND t.professionista_id = ? AND t.tipo_test = 'microbiota'
");
$stmt->execute([$test_id, $professionista_id]);
$test = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$test) {
    $_SESSION['error'] = "Test non trovato o non autorizzato.";
    redirect('tests.php');
}

// Gestione form
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    try {
        $db->beginTransaction();
        
        switch ($action) {
            case 'add_microbiota':
                $tipo_microbiota_id = (int)$_POST['tipo_microbiota_id'];
                
                // Verifica che non sia già stato aggiunto
                $stmt = $db->prepare("
                    SELECT COUNT(*) FROM test_microbiota_dettagli 
                    WHERE test_id = ? AND tipo_microbiota_id = ?
                ");
                $stmt->execute([$test_id, $tipo_microbiota_id]);
                
                if ($stmt->fetchColumn() > 0) {
                    throw new Exception("Questo tipo di analisi è già stato aggiunto al test.");
                }
                
                // Recupera prezzo dal listino
                $prezzo = getPrezzoMicrobiota($db, $tipo_microbiota_id, $professionista_id);
                
                // Inserisci dettaglio
                $stmt = $db->prepare("
                    INSERT INTO test_microbiota_dettagli (test_id, tipo_microbiota_id, prezzo_unitario)
                    VALUES (?, ?, ?)
                ");
                $stmt->execute([$test_id, $tipo_microbiota_id, $prezzo]);
                
                // Aggiorna prezzo totale
                updateTestPricesMicrobiota($db, $test_id);
                
                $_SESSION['success'] = "Analisi microbiota aggiunta con successo.";
                break;
                
            case 'remove_item':
                $dettaglio_id = (int)$_POST['dettaglio_id'];
                
                $stmt = $db->prepare("DELETE FROM test_microbiota_dettagli WHERE id = ? AND test_id = ?");
                $stmt->execute([$dettaglio_id, $test_id]);
                
                updateTestPricesMicrobiota($db, $test_id);
                
                $_SESSION['success'] = "Elemento rimosso con successo.";
                break;
                
            case 'update_prices':
                $prezzo_totale = (float)$_POST['prezzo_totale'];
                $sconto = (float)$_POST['sconto'];
                $iva = (float)$_POST['iva'];
                $note = $_POST['note'] ?? '';
                
                $prezzo_finale = $prezzo_totale - ($prezzo_totale * $sconto / 100);
                
                $stmt = $db->prepare("
                    UPDATE test 
                    SET prezzo_totale = ?, sconto = ?, prezzo_finale = ?, iva = ?, note = ?
                    WHERE id = ?
                ");
                $stmt->execute([$prezzo_totale, $sconto, $prezzo_finale, $iva, $note, $test_id]);
                
                $_SESSION['success'] = "Prezzi aggiornati con successo.";
                break;
                
            case 'confirm_test':
                $stmt = $db->prepare("UPDATE test SET stato = 'in_lavorazione' WHERE id = ?");
                $stmt->execute([$test_id]);
                
                $db->commit();
                $_SESSION['success'] = "Test confermato e inviato al laboratorio.";
                redirect('tests.php');
                break;
        }
        
        $db->commit();
    } catch (Exception $e) {
        $db->rollBack();
        $_SESSION['error'] = "Errore: " . $e->getMessage();
    }
    
    redirect('test_microbiota_dettagli.php?id=' . $test_id);
}

// Recupera dettagli test
$stmt = $db->prepare("
    SELECT d.*, tm.nome AS tipo_nome, tm.descrizione
    FROM test_microbiota_dettagli d
    JOIN tipi_microbiota tm ON d.tipo_microbiota_id = tm.id
    WHERE d.test_id = ?
");
$stmt->execute([$test_id]);
$dettagli = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Recupera tipi microbiota disponibili
$stmt = $db->prepare("SELECT * FROM tipi_microbiota WHERE attivo = 1 ORDER BY nome");
$stmt->execute();
$tipi_microbiota = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Funzioni helper
function getPrezzoMicrobiota($db, $tipo_id, $professionista_id) {
    // Prima cerca nel listino del professionista
    $stmt = $db->prepare("
        SELECT lp.prezzo 
        FROM listini_prezzi lp
        JOIN professionisti p ON lp.listino_id = p.listino_id
        WHERE p.id = ? AND lp.tipo_elemento = 'microbiota' AND lp.elemento_id = ?
    ");
    $stmt->execute([$professionista_id, $tipo_id]);
    $prezzo = $stmt->fetchColumn();
    
    if ($prezzo !== false) return $prezzo;
    
    // Altrimenti usa prezzo predefinito
    $stmt = $db->prepare("SELECT prezzo FROM tipi_microbiota WHERE id = ?");
    $stmt->execute([$tipo_id]);
    return $stmt->fetchColumn() ?: 0;
}

function updateTestPricesMicrobiota($db, $test_id) {
    $stmt = $db->prepare("
        SELECT SUM(prezzo_unitario) FROM test_microbiota_dettagli WHERE test_id = ?
    ");
    $stmt->execute([$test_id]);
    $totale = $stmt->fetchColumn() ?: 0;
    
    $stmt = $db->prepare("UPDATE test SET prezzo_totale = ?, prezzo_finale = ? WHERE id = ?");
    $stmt->execute([$totale, $totale, $test_id]);
}

$page_title = 'Dettagli Test Microbiota';
require_once '../templates/header.php';
require_once '../templates/sidebar.php';
?>

<div class="container-fluid">
    <div class="row">
        <div class="col-md-12">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h1>Dettagli Test Microbiota</h1>
                <a href="tests.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Torna ai Test
                </a>
            </div>

            <!-- Info Test -->
            <div class="card mb-4">
                <div class="card-header bg-success text-white">
                    <h5 class="mb-0">Informazioni Test</h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-3">
                            <strong>Codice Test:</strong> <?php echo htmlspecialchars($test['codice']); ?>
                        </div>
                        <div class="col-md-3">
                            <strong>Paziente:</strong> <?php echo htmlspecialchars($test['paziente_cognome'] . ' ' . $test['paziente_nome']); ?>
                        </div>
                        <div class="col-md-3">
                            <strong>CF:</strong> <?php echo htmlspecialchars($test['codice_fiscale']); ?>
                        </div>
                        <div class="col-md-3">
                            <strong>Stato:</strong> 
                            <span class="badge bg-warning"><?php echo ucfirst($test['stato']); ?></span>
                        </div>
                    </div>
                </div>
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

            <!-- Tipi di Analisi -->
            <div class="card mb-4">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">Analisi Microbiota</h5>
                    <?php if ($test['stato'] === 'richiesto'): ?>
                        <button class="btn btn-success btn-sm" data-bs-toggle="modal" data-bs-target="#addMicrobiotaModal">
                            <i class="fas fa-plus"></i> Aggiungi Analisi
                        </button>
                    <?php endif; ?>
                </div>
                <div class="card-body">
                    <?php if (empty($dettagli)): ?>
                        <p class="text-muted text-center">
                            Nessuna analisi aggiunta. Seleziona il tipo di analisi microbiota da eseguire.
                        </p>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Tipo Analisi</th>
                                        <th>Descrizione</th>
                                        <th>Prezzo</th>
                                        <th>Azioni</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($dettagli as $dettaglio): ?>
                                        <tr>
                                            <td>
                                                <strong><?php echo htmlspecialchars($dettaglio['tipo_nome']); ?></strong>
                                            </td>
                                            <td>
                                                <?php echo htmlspecialchars($dettaglio['descrizione'] ?? 'N/D'); ?>
                                            </td>
                                            <td>
                                                € <?php echo number_format($dettaglio['prezzo_unitario'], 2, ',', '.'); ?>
                                            </td>
                                            <td>
                                                <?php if ($test['stato'] === 'richiesto'): ?>
                                                    <form method="POST" style="display: inline;">
                                                        <input type="hidden" name="action" value="remove_item">
                                                        <input type="hidden" name="dettaglio_id" value="<?php echo $dettaglio['id']; ?>">
                                                        <button type="submit" class="btn btn-sm btn-outline-danger" 
                                                                onclick="return confirm('Rimuovere questa analisi?')">
                                                            <i class="fas fa-trash"></i>
                                                        </button>
                                                    </form>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Info Importante -->
            <div class="alert alert-info">
                <i class="fas fa-info-circle"></i>
                <strong>Nota:</strong> Per le analisi del microbiota, il referto dettagliato verrà generato 
                esternamente e caricato successivamente dal biologo responsabile.
            </div>

            <!-- Riepilogo Prezzi -->
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">Riepilogo Prezzi</h5>
                </div>
                <div class="card-body">
                    <form method="POST">
                        <input type="hidden" name="action" value="update_prices">
                        <div class="row">
                            <div class="col-md-3">
                                <label class="form-label">Prezzo Totale</label>
                                <div class="input-group">
                                    <span class="input-group-text">€</span>
                                    <input type="number" name="prezzo_totale" class="form-control" 
                                           value="<?php echo $test['prezzo_totale']; ?>" 
                                           step="0.01" required>
                                </div>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Sconto %</label>
                                <input type="number" name="sconto" class="form-control" 
                                       value="<?php echo $test['sconto']; ?>" 
                                       min="0" max="100" step="0.01">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">IVA %</label>
                                <input type="number" name="iva" class="form-control" 
                                       value="<?php echo $test['iva']; ?>" 
                                       step="0.01" required>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Prezzo Finale</label>
                                <div class="input-group">
                                    <span class="input-group-text">€</span>
                                    <input type="text" class="form-control" 
                                           value="<?php echo number_format($test['prezzo_finale'], 2, ',', '.'); ?>" 
                                           readonly>
                                </div>
                            </div>
                            <div class="col-md-2 d-flex align-items-end">
                                <button type="submit" class="btn btn-secondary w-100">
                                    <i class="fas fa-sync"></i> Aggiorna
                                </button>
                            </div>
                        </div>
                        <div class="row mt-3">
                            <div class="col-md-12">
                                <label class="form-label">Note</label>
                                <textarea name="note" class="form-control" rows="2"><?php echo htmlspecialchars($test['note'] ?? ''); ?></textarea>
                            </div>
                        </div>
                    </form>
                </div>
                <div class="card-footer text-end">
                    <?php if ($test['stato'] === 'richiesto' && !empty($dettagli)): ?>
                        <form method="POST" style="display: inline;">
                            <input type="hidden" name="action" value="confirm_test">
                            <button type="submit" class="btn btn-success" 
                                    onclick="return confirm('Confermare il test e inviarlo al laboratorio?')">
                                <i class="fas fa-check"></i> Conferma e Invia Test
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal Aggiungi Microbiota -->
<div class="modal fade" id="addMicrobiotaModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" value="add_microbiota">
                <div class="modal-header">
                    <h5 class="modal-title">Aggiungi Analisi Microbiota</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Tipo di Analisi</label>
                        <select name="tipo_microbiota_id" class="form-select" required>
                            <option value="">-- Seleziona --</option>
                            <?php foreach ($tipi_microbiota as $tipo): ?>
                                <option value="<?php echo $tipo['id']; ?>">
                                    <?php echo htmlspecialchars($tipo['nome']); ?>
                                    - € <?php echo number_format(getPrezzoMicrobiota($db, $tipo['id'], $professionista_id), 2, ',', '.'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div id="tipoDescription" class="alert alert-info" style="display: none;">
                        <small id="descriptionText"></small>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annulla</button>
                    <button type="submit" class="btn btn-primary">Aggiungi</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// Mostra descrizione tipo selezionato
document.querySelector('select[name="tipo_microbiota_id"]').addEventListener('change', function() {
    const selectedOption = this.options[this.selectedIndex];
    const descDiv = document.getElementById('tipoDescription');
    const descText = document.getElementById('descriptionText');
    
    if (this.value) {
        // In produzione, qui si farebbe una chiamata AJAX per recuperare la descrizione
        descText.textContent = 'Analisi del microbiota ' + selectedOption.text.split(' - ')[0].toLowerCase();
        descDiv.style.display = 'block';
    } else {
        descDiv.style.display = 'none';
    }
});

// Calcola prezzi automaticamente
document.querySelector('input[name="sconto"]').addEventListener('input', function() {
    const totale = parseFloat(document.querySelector('input[name="prezzo_totale"]').value) || 0;
    const sconto = parseFloat(this.value) || 0;
    const finale = totale - (totale * sconto / 100);
    
    document.querySelector('input[readonly]').value = finale.toFixed(2).replace('.', ',');
});
</script>

<?php require_once '../templates/footer.php'; ?>