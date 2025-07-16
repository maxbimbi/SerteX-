<?php
session_start();
require_once '../includes/auth.php';
require_once '../includes/database.php';
require_once '../includes/functions.php';
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

// Verifica ID test
$test_id = (int)($_GET['id'] ?? 0);
if (!$test_id) {
    redirect('tests.php');
}

// Verifica che il test appartenga al professionista e sia genetico
$stmt = $db->prepare("
    SELECT t.*, p.nome AS paziente_nome, p.cognome AS paziente_cognome, p.codice_fiscale
    FROM test t
    JOIN pazienti p ON t.paziente_id = p.id
    WHERE t.id = ? AND t.professionista_id = ? AND t.tipo_test = 'genetico'
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
            case 'add_gene':
                $gene_id = (int)$_POST['gene_id'];
                
                // Recupera prezzo del gene dal listino
                $prezzo = getPrezzoGene($db, $gene_id, $professionista_id);
                
                // Inserisci dettaglio test
                $stmt = $db->prepare("
                    INSERT INTO test_genetici_dettagli (test_id, tipo_elemento, elemento_id, prezzo_unitario)
                    VALUES (?, 'gene', ?, ?)
                ");
                $stmt->execute([$test_id, $gene_id, $prezzo]);
                
                $_SESSION['success'] = "Gene aggiunto con successo.";
                break;
                
            case 'add_panel':
                $panel_id = (int)$_POST['panel_id'];
                $geni_aggiuntivi = $_POST['geni_aggiuntivi'] ?? [];
                
                // Recupera prezzo del pannello dal listino
                $prezzo_pannello = getPrezzoPannello($db, $panel_id, $professionista_id);
                
                // Inserisci pannello
                $stmt = $db->prepare("
                    INSERT INTO test_genetici_dettagli (test_id, tipo_elemento, elemento_id, prezzo_unitario)
                    VALUES (?, 'pannello', ?, ?)
                ");
                $stmt->execute([$test_id, $panel_id, $prezzo_pannello]);
                $dettaglio_id = $db->lastInsertId();
                
                // Aggiungi geni extra se presenti
                if (!empty($geni_aggiuntivi)) {
                    foreach ($geni_aggiuntivi as $gene_id) {
                        $prezzo_gene = getPrezzoGene($db, $gene_id, $professionista_id);
                        
                        $stmt = $db->prepare("
                            INSERT INTO test_genetici_geni_aggiuntivi (test_dettaglio_id, gene_id, prezzo_unitario)
                            VALUES (?, ?, ?)
                        ");
                        $stmt->execute([$dettaglio_id, $gene_id, $prezzo_gene]);
                    }
                }
                
                $_SESSION['success'] = "Pannello aggiunto con successo.";
                break;
                
            case 'remove_item':
                $dettaglio_id = (int)$_POST['dettaglio_id'];
                
                // Verifica che il dettaglio appartenga a questo test
                $stmt = $db->prepare("DELETE FROM test_genetici_dettagli WHERE id = ? AND test_id = ?");
                $stmt->execute([$dettaglio_id, $test_id]);
                
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
                // Conferma test e procedi
                $stmt = $db->prepare("UPDATE test SET stato = 'in_lavorazione' WHERE id = ?");
                $stmt->execute([$test_id]);
                
                $db->commit();
                $_SESSION['success'] = "Test confermato e inviato al laboratorio.";
                redirect('tests.php');
                break;
        }
        
                        // Ricalcola prezzi totali
                updateTestPrices($db, $test_id);
        }
        
        $db->commit();
    } catch (Exception $e) {
        $db->rollBack();
        $_SESSION['error'] = "Errore: " . $e->getMessage();
    }
    
    redirect('test_genetico_dettagli.php?id=' . $test_id);
}

// Recupera dettagli test correnti
$dettagli = getTestGeneticiDettagli($db, $test_id);

// Recupera tutti i geni disponibili
$stmt = $db->prepare("
    SELECT g.*, gg.nome AS gruppo_nome 
    FROM geni g 
    LEFT JOIN gruppi_geni gg ON g.gruppo_id = gg.id 
    WHERE g.attivo = 1 
    ORDER BY gg.ordine, gg.nome, g.nome
");
$stmt->execute();
$geni = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Recupera tutti i pannelli disponibili
$stmt = $db->prepare("
    SELECT p.*, COUNT(pg.gene_id) AS num_geni 
    FROM pannelli_genetici p 
    LEFT JOIN pannelli_geni pg ON p.id = pg.pannello_id 
    WHERE p.attivo = 1 
    GROUP BY p.id 
    ORDER BY p.nome
");
$stmt->execute();
$pannelli = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Funzioni helper
function getTestGeneticiDettagli($db, $test_id) {
    $stmt = $db->prepare("
        SELECT 
            d.*,
            CASE 
                WHEN d.tipo_elemento = 'gene' THEN g.nome
                WHEN d.tipo_elemento = 'pannello' THEN p.nome
            END AS elemento_nome,
            CASE 
                WHEN d.tipo_elemento = 'gene' THEN g.sigla
                ELSE NULL
            END AS gene_sigla
        FROM test_genetici_dettagli d
        LEFT JOIN geni g ON d.tipo_elemento = 'gene' AND d.elemento_id = g.id
        LEFT JOIN pannelli_genetici p ON d.tipo_elemento = 'pannello' AND d.elemento_id = p.id
        WHERE d.test_id = ?
    ");
    $stmt->execute([$test_id]);
    $dettagli = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Per ogni pannello, recupera geni aggiuntivi
    foreach ($dettagli as &$dettaglio) {
        if ($dettaglio['tipo_elemento'] === 'pannello') {
            // Geni del pannello
            $stmt = $db->prepare("
                SELECT g.nome, g.sigla 
                FROM pannelli_geni pg 
                JOIN geni g ON pg.gene_id = g.id 
                WHERE pg.pannello_id = ?
            ");
            $stmt->execute([$dettaglio['elemento_id']]);
            $dettaglio['geni_pannello'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Geni aggiuntivi
            $stmt = $db->prepare("
                SELECT ga.*, g.nome, g.sigla 
                FROM test_genetici_geni_aggiuntivi ga
                JOIN geni g ON ga.gene_id = g.id
                WHERE ga.test_dettaglio_id = ?
            ");
            $stmt->execute([$dettaglio['id']]);
            $dettaglio['geni_aggiuntivi'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    }
    
    return $dettagli;
}

function getPrezzoGene($db, $gene_id, $professionista_id) {
    // Prima cerca nel listino del professionista
    $stmt = $db->prepare("
        SELECT lp.prezzo 
        FROM listini_prezzi lp
        JOIN professionisti p ON lp.listino_id = p.listino_id
        WHERE p.id = ? AND lp.tipo_elemento = 'gene' AND lp.elemento_id = ?
    ");
    $stmt->execute([$professionista_id, $gene_id]);
    $prezzo = $stmt->fetchColumn();
    
    if ($prezzo !== false) return $prezzo;
    
    // Altrimenti usa prezzo predefinito del gene
    $stmt = $db->prepare("SELECT prezzo FROM geni WHERE id = ?");
    $stmt->execute([$gene_id]);
    return $stmt->fetchColumn() ?: 0;
}

function getPrezzoPannello($db, $pannello_id, $professionista_id) {
    // Prima cerca nel listino del professionista
    $stmt = $db->prepare("
        SELECT lp.prezzo 
        FROM listini_prezzi lp
        JOIN professionisti p ON lp.listino_id = p.listino_id
        WHERE p.id = ? AND lp.tipo_elemento = 'pannello_genetico' AND lp.elemento_id = ?
    ");
    $stmt->execute([$professionista_id, $pannello_id]);
    $prezzo = $stmt->fetchColumn();
    
    if ($prezzo !== false) return $prezzo;
    
    // Altrimenti usa prezzo predefinito del pannello
    $stmt = $db->prepare("SELECT prezzo FROM pannelli_genetici WHERE id = ?");
    $stmt->execute([$pannello_id]);
    return $stmt->fetchColumn() ?: 0;
}

function updateTestPrices($db, $test_id) {
    // Calcola prezzo totale sommando tutti gli elementi
    $stmt = $db->prepare("
        SELECT 
            SUM(d.prezzo_unitario) + COALESCE(SUM(ga.prezzo_unitario), 0) AS totale
        FROM test_genetici_dettagli d
        LEFT JOIN test_genetici_geni_aggiuntivi ga ON d.id = ga.test_dettaglio_id
        WHERE d.test_id = ?
    ");
    $stmt->execute([$test_id]);
    $totale = $stmt->fetchColumn() ?: 0;
    
    // Aggiorna prezzo totale nel test
    $stmt = $db->prepare("UPDATE test SET prezzo_totale = ?, prezzo_finale = ? WHERE id = ?");
    $stmt->execute([$totale, $totale, $test_id]);
}

$page_title = 'Dettagli Test Genetico';
require_once '../templates/header.php';
require_once '../templates/sidebar.php';
?>

<div class="container-fluid">
    <div class="row">
        <div class="col-md-12">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h1>Dettagli Test Genetico</h1>
                <a href="tests.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Torna ai Test
                </a>
            </div>

            <!-- Info Test -->
            <div class="card mb-4">
                <div class="card-header">
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

            <!-- Elementi del Test -->
            <div class="card mb-4">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">Elementi del Test</h5>
                    <div>
                        <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addGeneModal">
                            <i class="fas fa-plus"></i> Aggiungi Gene
                        </button>
                        <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addPanelModal">
                            <i class="fas fa-plus"></i> Aggiungi Pannello
                        </button>
                    </div>
                </div>
                <div class="card-body">
                    <?php if (empty($dettagli)): ?>
                        <p class="text-muted text-center">Nessun elemento aggiunto al test. Aggiungi geni o pannelli per procedere.</p>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Tipo</th>
                                        <th>Nome</th>
                                        <th>Dettagli</th>
                                        <th>Prezzo</th>
                                        <th>Azioni</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($dettagli as $dettaglio): ?>
                                        <tr>
                                            <td>
                                                <?php if ($dettaglio['tipo_elemento'] === 'gene'): ?>
                                                    <span class="badge bg-info">Gene</span>
                                                <?php else: ?>
                                                    <span class="badge bg-primary">Pannello</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <strong><?php echo htmlspecialchars($dettaglio['elemento_nome']); ?></strong>
                                                <?php if ($dettaglio['gene_sigla']): ?>
                                                    <small class="text-muted">(<?php echo htmlspecialchars($dettaglio['gene_sigla']); ?>)</small>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($dettaglio['tipo_elemento'] === 'pannello'): ?>
                                                    <small>
                                                        Geni inclusi: 
                                                        <?php 
                                                        $nomi_geni = array_column($dettaglio['geni_pannello'], 'nome');
                                                        echo htmlspecialchars(implode(', ', $nomi_geni));
                                                        ?>
                                                    </small>
                                                    <?php if (!empty($dettaglio['geni_aggiuntivi'])): ?>
                                                        <br>
                                                        <small class="text-success">
                                                            + Geni extra: 
                                                            <?php 
                                                            $nomi_extra = array_column($dettaglio['geni_aggiuntivi'], 'nome');
                                                            echo htmlspecialchars(implode(', ', $nomi_extra));
                                                            ?>
                                                        </small>
                                                    <?php endif; ?>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                € <?php echo number_format($dettaglio['prezzo_unitario'], 2, ',', '.'); ?>
                                                <?php if (!empty($dettaglio['geni_aggiuntivi'])): ?>
                                                    <br>
                                                    <small class="text-success">
                                                        + € <?php 
                                                        $totale_extra = array_sum(array_column($dettaglio['geni_aggiuntivi'], 'prezzo_unitario'));
                                                        echo number_format($totale_extra, 2, ',', '.'); 
                                                        ?>
                                                    </small>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($test['stato'] === 'richiesto'): ?>
                                                    <form method="POST" style="display: inline;">
                                                        <input type="hidden" name="action" value="remove_item">
                                                        <input type="hidden" name="dettaglio_id" value="<?php echo $dettaglio['id']; ?>">
                                                        <button type="submit" class="btn btn-sm btn-outline-danger" 
                                                                onclick="return confirm('Rimuovere questo elemento?')">
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

<!-- Modal Aggiungi Gene -->
<div class="modal fade" id="addGeneModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" value="add_gene">
                <div class="modal-header">
                    <h5 class="modal-title">Aggiungi Gene</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Seleziona Gene</label>
                        <select name="gene_id" class="form-select" required>
                            <option value="">-- Seleziona --</option>
                            <?php 
                            $current_group = '';
                            foreach ($geni as $gene): 
                                if ($gene['gruppo_nome'] !== $current_group):
                                    if ($current_group !== '') echo '</optgroup>';
                                    $current_group = $gene['gruppo_nome'];
                                    if ($current_group):
                            ?>
                                        <optgroup label="<?php echo htmlspecialchars($current_group); ?>">
                                    <?php endif;
                                endif;
                            ?>
                                <option value="<?php echo $gene['id']; ?>">
                                    <?php echo htmlspecialchars($gene['nome']); ?>
                                    <?php if ($gene['sigla']): ?>
                                        (<?php echo htmlspecialchars($gene['sigla']); ?>)
                                    <?php endif; ?>
                                    - € <?php echo number_format(getPrezzoGene($db, $gene['id'], $professionista_id), 2, ',', '.'); ?>
                                </option>
                            <?php endforeach; ?>
                            <?php if ($current_group !== '') echo '</optgroup>'; ?>
                        </select>
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

<!-- Modal Aggiungi Pannello -->
<div class="modal fade" id="addPanelModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="action" value="add_panel">
                <div class="modal-header">
                    <h5 class="modal-title">Aggiungi Pannello</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label">Seleziona Pannello</label>
                        <select name="panel_id" class="form-select" required id="panelSelect">
                            <option value="">-- Seleziona --</option>
                            <?php foreach ($pannelli as $pannello): ?>
                                <option value="<?php echo $pannello['id']; ?>">
                                    <?php echo htmlspecialchars($pannello['nome']); ?>
                                    (<?php echo $pannello['num_geni']; ?> geni)
                                    - € <?php echo number_format(getPrezzoPannello($db, $pannello['id'], $professionista_id), 2, ',', '.'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div id="panelGenesInfo" style="display: none;">
                        <div class="alert alert-info">
                            <h6>Geni inclusi nel pannello:</h6>
                            <div id="panelGenesList"></div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Aggiungi geni extra (opzionale)</label>
                            <select name="geni_aggiuntivi[]" class="form-select" multiple size="5">
                                <?php foreach ($geni as $gene): ?>
                                    <option value="<?php echo $gene['id']; ?>">
                                        <?php echo htmlspecialchars($gene['nome']); ?>
                                        <?php if ($gene['sigla']): ?>
                                            (<?php echo htmlspecialchars($gene['sigla']); ?>)
                                        <?php endif; ?>
                                        - € <?php echo number_format(getPrezzoGene($db, $gene['id'], $professionista_id), 2, ',', '.'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <small class="form-text text-muted">Tieni premuto Ctrl per selezionare più geni</small>
                        </div>
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
// Mostra geni del pannello selezionato
document.getElementById('panelSelect').addEventListener('change', async function() {
    const panelId = this.value;
    const infoDiv = document.getElementById('panelGenesInfo');
    const listDiv = document.getElementById('panelGenesList');
    
    if (!panelId) {
        infoDiv.style.display = 'none';
        return;
    }
    
    try {
        const response = await fetch(`/api/v1/panels.php?action=get_genes&panel_id=${panelId}`);
        const data = await response.json();
        
        if (data.success) {
            listDiv.innerHTML = data.genes.map(g => 
                `${g.nome} ${g.sigla ? '(' + g.sigla + ')' : ''}`
            ).join(', ');
            infoDiv.style.display = 'block';
        }
    } catch (error) {
        console.error('Errore nel recupero geni:', error);
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