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

// Verifica che il test appartenga al professionista e sia intolleranze
$stmt = $db->prepare("
    SELECT t.*, p.nome AS paziente_nome, p.cognome AS paziente_cognome, p.codice_fiscale
    FROM test t
    JOIN pazienti p ON t.paziente_id = p.id
    WHERE t.id = ? AND t.professionista_id = ? AND t.tipo_test IN ('intolleranze_cito', 'intolleranze_elisa')
");
$stmt->execute([$test_id, $professionista_id]);
$test = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$test) {
    $_SESSION['error'] = "Test non trovato o non autorizzato.";
    redirect('tests.php');
}

$is_elisa = $test['tipo_test'] === 'intolleranze_elisa';

// Gestione form
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    try {
        $db->beginTransaction();
        
        switch ($action) {
            case 'add_panel':
                $pannello_id = (int)$_POST['pannello_id'];
                
                // Verifica che non sia già stato aggiunto
                $stmt = $db->prepare("
                    SELECT COUNT(*) FROM test_intolleranze_dettagli 
                    WHERE test_id = ? AND pannello_id = ?
                ");
                $stmt->execute([$test_id, $pannello_id]);
                
                if ($stmt->fetchColumn() > 0) {
                    throw new Exception("Questo pannello è già stato aggiunto al test.");
                }
                
                // Verifica che il pannello sia del tipo corretto
                $stmt = $db->prepare("SELECT tipo FROM pannelli_intolleranze WHERE id = ?");
                $stmt->execute([$pannello_id]);
                $tipo_pannello = $stmt->fetchColumn();
                
                $tipo_richiesto = $is_elisa ? 'elisa' : 'citotossico';
                if ($tipo_pannello !== $tipo_richiesto) {
                    throw new Exception("Il pannello selezionato non è compatibile con questo tipo di test.");
                }
                
                // Recupera prezzo dal listino
                $prezzo = getPrezzoPannelloIntolleranze($db, $pannello_id, $professionista_id);
                
                // Inserisci dettaglio
                $stmt = $db->prepare("
                    INSERT INTO test_intolleranze_dettagli (test_id, pannello_id, prezzo_unitario)
                    VALUES (?, ?, ?)
                ");
                $stmt->execute([$test_id, $pannello_id, $prezzo]);
                
                // Aggiorna prezzo totale
                updateTestPricesIntolleranze($db, $test_id);
                
                $_SESSION['success'] = "Pannello intolleranze aggiunto con successo.";
                break;
                
            case 'remove_panel':
                $dettaglio_id = (int)$_POST['dettaglio_id'];
                
                $stmt = $db->prepare("DELETE FROM test_intolleranze_dettagli WHERE id = ? AND test_id = ?");
                $stmt->execute([$dettaglio_id, $test_id]);
                
                updateTestPricesIntolleranze($db, $test_id);
                
                $_SESSION['success'] = "Pannello rimosso con successo.";
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
                // Verifica che ci sia almeno un pannello
                $stmt = $db->prepare("SELECT COUNT(*) FROM test_intolleranze_dettagli WHERE test_id = ?");
                $stmt->execute([$test_id]);
                
                if ($stmt->fetchColumn() == 0) {
                    throw new Exception("Aggiungi almeno un pannello prima di confermare il test.");
                }
                
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
    
    redirect('test_intolleranze_dettagli.php?id=' . $test_id);
}

// Recupera dettagli test
$stmt = $db->prepare("
    SELECT d.*, p.nome AS pannello_nome, p.descrizione,
           (SELECT COUNT(*) FROM pannelli_alimenti WHERE pannello_id = p.id) AS num_alimenti
    FROM test_intolleranze_dettagli d
    JOIN pannelli_intolleranze p ON d.pannello_id = p.id
    WHERE d.test_id = ?
");
$stmt->execute([$test_id]);
$dettagli = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Per ogni pannello, recupera gli alimenti
foreach ($dettagli as &$dettaglio) {
    $stmt = $db->prepare("
        SELECT a.nome 
        FROM pannelli_alimenti pa
        JOIN alimenti a ON pa.alimento_id = a.id
        WHERE pa.pannello_id = ?
        ORDER BY a.nome
    ");
    $stmt->execute([$dettaglio['pannello_id']]);
    $dettaglio['alimenti'] = $stmt->fetchAll(PDO::FETCH_COLUMN);
}

// Recupera pannelli disponibili
$tipo_pannello = $is_elisa ? 'elisa' : 'citotossico';
$stmt = $db->prepare("
    SELECT p.*, COUNT(pa.alimento_id) AS num_alimenti
    FROM pannelli_intolleranze p
    LEFT JOIN pannelli_alimenti pa ON p.id = pa.pannello_id
    WHERE p.tipo = ? AND p.attivo = 1
    GROUP BY p.id
    ORDER BY p.nome
");
$stmt->execute([$tipo_pannello]);
$pannelli_disponibili = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Funzioni helper
function getPrezzoPannelloIntolleranze($db, $pannello_id, $professionista_id) {
    $tipo_elemento = $_POST['tipo_test'] === 'intolleranze_elisa' ? 'intolleranze_elisa' : 'intolleranze_cito';
    
    // Prima cerca nel listino del professionista
    $stmt = $db->prepare("
        SELECT lp.prezzo 
        FROM listini_prezzi lp
        JOIN professionisti p ON lp.listino_id = p.listino_id
        WHERE p.id = ? AND lp.tipo_elemento = ? AND lp.elemento_id = ?
    ");
    $stmt->execute([$professionista_id, $tipo_elemento, $pannello_id]);
    $prezzo = $stmt->fetchColumn();
    
    if ($prezzo !== false) return $prezzo;
    
    // Altrimenti usa prezzo predefinito
    $stmt = $db->prepare("SELECT prezzo FROM pannelli_intolleranze WHERE id = ?");
    $stmt->execute([$pannello_id]);
    return $stmt->fetchColumn() ?: 0;
}

function updateTestPricesIntolleranze($db, $test_id) {
    $stmt = $db->prepare("
        SELECT SUM(prezzo_unitario) FROM test_intolleranze_dettagli WHERE test_id = ?
    ");
    $stmt->execute([$test_id]);
    $totale = $stmt->fetchColumn() ?: 0;
    
    $stmt = $db->prepare("UPDATE test SET prezzo_totale = ?, prezzo_finale = ? WHERE id = ?");
    $stmt->execute([$totale, $totale, $test_id]);
}

$page_title = 'Dettagli Test Intolleranze ' . ($is_elisa ? 'ELISA' : 'Citotossico');
require_once '../templates/header.php';
require_once '../templates/sidebar.php';
?>

<div class="container-fluid">
    <div class="row">
        <div class="col-md-12">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h1>Dettagli Test Intolleranze <span class="badge bg-warning"><?php echo $is_elisa ? 'ELISA' : 'Citotossico'; ?></span></h1>
                <a href="tests.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Torna ai Test
                </a>
            </div>

            <!-- Info Test -->
            <div class="card mb-4">
                <div class="card-header bg-warning">
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
                            <span class="badge bg-secondary"><?php echo ucfirst($test['stato']); ?></span>
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

            <!-- Pannelli Intolleranze -->
            <div class="card mb-4">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">Pannelli Intolleranze</h5>
                    <?php if ($test['stato'] === 'richiesto'): ?>
                        <button class="btn btn-warning btn-sm" data-bs-toggle="modal" data-bs-target="#addPanelModal">
                            <i class="fas fa-plus"></i> Aggiungi Pannello
                        </button>
                    <?php endif; ?>
                </div>
                <div class="card-body">
                    <?php if (empty($dettagli)): ?>
                        <p class="text-muted text-center">
                            Nessun pannello aggiunto. Seleziona un pannello di intolleranze alimentari.
                        </p>
                    <?php else: ?>
                        <?php foreach ($dettagli as $dettaglio): ?>
                            <div class="card mb-3">
                                <div class="card-header d-flex justify-content-between align-items-center">
                                    <h6 class="mb-0">
                                        <i class="fas fa-flask"></i> 
                                        <?php echo htmlspecialchars($dettaglio['pannello_nome']); ?>
                                        <span class="badge bg-info ms-2"><?php echo $dettaglio['num_alimenti']; ?> alimenti</span>
                                    </h6>
                                    <div>
                                        <span class="me-3">
                                            <strong>€ <?php echo number_format($dettaglio['prezzo_unitario'], 2, ',', '.'); ?></strong>
                                        </span>
                                        <?php if ($test['stato'] === 'richiesto'): ?>
                                            <form method="POST" style="display: inline;">
                                                <input type="hidden" name="action" value="remove_panel">
                                                <input type="hidden" name="dettaglio_id" value="<?php echo $dettaglio['id']; ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-danger" 
                                                        onclick="return confirm('Rimuovere questo pannello?')">
                                                    <i class="fas fa-trash"></i>
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="card-body">
                                    <?php if ($dettaglio['descrizione']): ?>
                                        <p class="text-muted mb-2"><?php echo htmlspecialchars($dettaglio['descrizione']); ?></p>
                                    <?php endif; ?>
                                    
                                    <div class="mt-2">
                                        <strong>Alimenti testati:</strong>
                                        <div class="mt-1" style="columns: 3; column-gap: 20px;">
                                            <?php foreach ($dettaglio['alimenti'] as $alimento): ?>
                                                <div style="break-inside: avoid;">
                                                    <small><i class="fas fa-check text-success"></i> <?php echo htmlspecialchars($alimento); ?></small>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Info Metodica -->
            <div class="alert alert-info">
                <h6><i class="fas fa-info-circle"></i> Informazioni sulla Metodica</h6>
                <?php if ($is_elisa): ?>
                    <p class="mb-0">
                        <strong>Test ELISA:</strong> Ricerca di IgG specifiche verso antigeni alimentari. 
                        I valori vengono espressi numericamente (0-100) e classificati in 4 gradi di intolleranza.
                        Valori di riferimento: 0-10 (Grado 0), 11-20 (Grado 1), 21-30 (Grado 2), 31-100 (Grado 3).
                    </p>
                <?php else: ?>
                    <p class="mb-0">
                        <strong>Test Citotossico:</strong> Valutazione della reazione leucocitaria in presenza di estratti alimentari.
                        I risultati vengono classificati in 4 gradi basati sulla percentuale di cellule danneggiate:
                        Grado 0 (nessuna reazione), Grado 1 (lieve), Grado 2 (media), Grado 3 (grave).
                    </p>
                <?php endif; ?>
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

<!-- Modal Aggiungi Pannello -->
<div class="modal fade" id="addPanelModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" value="add_panel">
                <input type="hidden" name="tipo_test" value="<?php echo $test['tipo_test']; ?>">
                <div class="modal-header">
                    <h5 class="modal-title">Aggiungi Pannello Intolleranze</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <?php if (empty($pannelli_disponibili)): ?>
                        <div class="alert alert-warning">
                            <i class="fas fa-exclamation-triangle"></i>
                            Non ci sono pannelli <?php echo $tipo_pannello; ?> disponibili.
                        </div>
                    <?php else: ?>
                        <div class="mb-3">
                            <label class="form-label">Seleziona Pannello</label>
                            <select name="pannello_id" class="form-select" required id="panelSelect">
                                <option value="">-- Seleziona --</option>
                                <?php foreach ($pannelli_disponibili as $pannello): ?>
                                    <option value="<?php echo $pannello['id']; ?>" 
                                            data-alimenti="<?php echo $pannello['num_alimenti']; ?>"
                                            data-desc="<?php echo htmlspecialchars($pannello['descrizione'] ?? ''); ?>">
                                        <?php echo htmlspecialchars($pannello['nome']); ?>
                                        (<?php echo $pannello['num_alimenti']; ?> alimenti)
                                        - € <?php echo number_format(getPrezzoPannelloIntolleranze($db, $pannello['id'], $professionista_id), 2, ',', '.'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div id="panelInfo" style="display: none;">
                            <div class="alert alert-info">
                                <h6 class="mb-1">Informazioni Pannello</h6>
                                <p id="panelDesc" class="mb-2"></p>
                                <p class="mb-0">
                                    <strong>Numero alimenti:</strong> <span id="panelAlimenti"></span>
                                </p>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annulla</button>
                    <button type="submit" class="btn btn-primary" <?php echo empty($pannelli_disponibili) ? 'disabled' : ''; ?>>
                        Aggiungi
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// Mostra info pannello selezionato
document.getElementById('panelSelect').addEventListener('change', function() {
    const selectedOption = this.options[this.selectedIndex];
    const infoDiv = document.getElementById('panelInfo');
    
    if (this.value) {
        document.getElementById('panelDesc').textContent = selectedOption.dataset.desc || 'Nessuna descrizione disponibile';
        document.getElementById('panelAlimenti').textContent = selectedOption.dataset.alimenti;
        infoDiv.style.display = 'block';
    } else {
        infoDiv.style.display = 'none';
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