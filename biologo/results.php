<?php
/**
 * Inserimento Risultati Test - Area Biologo
 * SerteX+ Genetic Lab Portal
 */

require_once '../includes/auth.php';
require_once '../includes/database.php';
require_once '../includes/session.php';
require_once '../includes/functions.php';
require_once '../classes/Test.php';
require_once '../classes/Logger.php';

$auth = new Auth();
$session = Session::getInstance();
$db = Database::getInstance();
$logger = new Logger();

// Verifica autenticazione e ruolo
if (!$auth->isAuthenticated() || !$auth->hasRole('biologo')) {
    header('Location: ../index.php');
    exit;
}

$user = $auth->getCurrentUser();
$testId = intval($_GET['test_id'] ?? 0);

// Carica informazioni test
$test = $db->selectOne("
    SELECT t.*, 
           p.nome as paziente_nome, 
           p.cognome as paziente_cognome,
           p.codice_fiscale,
           p.data_nascita,
           p.sesso
    FROM test t
    INNER JOIN pazienti p ON t.paziente_id = p.id
    WHERE t.id = :id
", ['id' => $testId]);

if (!$test) {
    $session->setFlash('error', 'Test non trovato');
    header('Location: tests.php');
    exit;
}

// Verifica che il test sia in uno stato modificabile
if (!in_array($test['stato'], ['richiesto', 'in_lavorazione', 'eseguito'])) {
    $session->setFlash('error', 'Il test non può essere modificato nello stato attuale');
    header('Location: tests.php');
    exit;
}

// Pseudonimizza dati paziente
$test['paziente_display'] = substr($test['paziente_nome'], 0, 1) . '*** ' . 
                           substr($test['paziente_cognome'], 0, 1) . '***';

// Gestione salvataggio risultati
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? '';
    
    if (!$session->validateCsrfToken($csrfToken)) {
        $session->setFlash('error', 'Token di sicurezza non valido');
    } else {
        try {
            $db->beginTransaction();
            
            // Aggiorna stato test se necessario
            if ($test['stato'] === 'richiesto') {
                $db->update('test', [
                    'stato' => 'in_lavorazione',
                    'data_esecuzione' => date('Y-m-d H:i:s')
                ], ['id' => $testId]);
            }
            
            // Salva risultati in base al tipo di test
            switch ($test['tipo_test']) {
                case 'genetico':
                    // Elimina risultati esistenti
                    $db->delete('risultati_genetici', ['test_id' => $testId]);
                    
                    // Inserisci nuovi risultati
                    if (isset($_POST['risultati']) && is_array($_POST['risultati'])) {
                        foreach ($_POST['risultati'] as $geneId => $risultatoId) {
                            if ($risultatoId) {
                                $db->insert('risultati_genetici', [
                                    'test_id' => $testId,
                                    'gene_id' => intval($geneId),
                                    'risultato_id' => intval($risultatoId),
                                    'note' => sanitizeInput($_POST['note'][$geneId] ?? '')
                                ]);
                            }
                        }
                    }
                    break;
                    
                case 'intolleranze_cito':
                case 'intolleranze_elisa':
                    // Elimina risultati esistenti
                    $db->delete('risultati_intolleranze', ['test_id' => $testId]);
                    
                    // Inserisci nuovi risultati
                    if (isset($_POST['gradi']) && is_array($_POST['gradi'])) {
                        foreach ($_POST['gradi'] as $alimentoId => $grado) {
                            $valoreNumerico = null;
                            if ($test['tipo_test'] === 'intolleranze_elisa' && isset($_POST['valori'][$alimentoId])) {
                                $valoreNumerico = floatval($_POST['valori'][$alimentoId]);
                            }
                            
                            $db->insert('risultati_intolleranze', [
                                'test_id' => $testId,
                                'alimento_id' => intval($alimentoId),
                                'grado' => intval($grado),
                                'valore_numerico' => $valoreNumerico
                            ]);
                        }
                    }
                    break;
                    
                case 'microbiota':
                    // Per il microbiota, il referto viene caricato esternamente
                    // Qui possiamo solo aggiornare lo stato
                    break;
            }
            
            // Aggiorna stato test se completato
            if (isset($_POST['completa_test'])) {
                $db->update('test', ['stato' => 'eseguito'], ['id' => $testId]);
                $logger->log($user->getId(), 'test_completato', "Test ID: {$testId}");
                $session->setFlash('success', 'Test completato con successo');
            } else {
                $logger->log($user->getId(), 'risultati_salvati', "Test ID: {$testId}");
                $session->setFlash('success', 'Risultati salvati con successo');
            }
            
            $db->commit();
            
            if (isset($_POST['completa_test'])) {
                header('Location: tests.php');
            } else {
                header('Location: results.php?test_id=' . $testId);
            }
            exit;
            
        } catch (Exception $e) {
            $db->rollback();
            $session->setFlash('error', 'Errore nel salvataggio dei risultati');
        }
    }
}

// Carica dati specifici per tipo test
$testData = [];
$existingResults = [];

switch ($test['tipo_test']) {
    case 'genetico':
        // Carica geni richiesti
        $testData = $db->select("
            SELECT DISTINCT g.*, gg.nome as gruppo_nome
            FROM test_genetici_dettagli tgd
            LEFT JOIN geni g ON (tgd.tipo_elemento = 'gene' AND tgd.elemento_id = g.id)
            LEFT JOIN pannelli_genetici pg ON (tgd.tipo_elemento = 'pannello' AND tgd.elemento_id = pg.id)
            LEFT JOIN pannelli_geni pgn ON pg.id = pgn.pannello_id
            LEFT JOIN geni g2 ON (pgn.gene_id = g2.id OR g.id = g2.id)
            LEFT JOIN test_genetici_geni_aggiuntivi tgga ON tgd.id = tgga.test_dettaglio_id
            LEFT JOIN geni g3 ON tgga.gene_id = g3.id
            LEFT JOIN gruppi_geni gg ON COALESCE(g.gruppo_id, g2.gruppo_id, g3.gruppo_id) = gg.id
            WHERE tgd.test_id = :test_id AND (g.id IS NOT NULL OR g2.id IS NOT NULL OR g3.id IS NOT NULL)
            ORDER BY gg.ordine, gg.nome, COALESCE(g.nome, g2.nome, g3.nome)
        ", ['test_id' => $testId]);
        
        // Carica risultati possibili per ogni gene
        foreach ($testData as &$gene) {
            $gene['risultati_possibili'] = $db->select(
                "SELECT * FROM risultati_geni WHERE gene_id = :gene_id ORDER BY ordine",
                ['gene_id' => $gene['id']]
            );
        }
        
        // Carica risultati esistenti
        $existingResults = $db->select(
            "SELECT * FROM risultati_genetici WHERE test_id = :test_id",
            ['test_id' => $testId]
        );
        
        // Mappa risultati per gene_id
        $resultsMap = [];
        foreach ($existingResults as $result) {
            $resultsMap[$result['gene_id']] = $result;
        }
        $existingResults = $resultsMap;
        break;
        
    case 'intolleranze_cito':
    case 'intolleranze_elisa':
        // Carica alimenti del pannello
        $testData = $db->select("
            SELECT DISTINCT a.*
            FROM test_intolleranze_dettagli tid
            INNER JOIN pannelli_intolleranze pi ON tid.pannello_id = pi.id
            INNER JOIN pannelli_alimenti pa ON pi.id = pa.pannello_id
            INNER JOIN alimenti a ON pa.alimento_id = a.id
            WHERE tid.test_id = :test_id
            ORDER BY a.nome
        ", ['test_id' => $testId]);
        
        // Carica risultati esistenti
        $existingResults = $db->select(
            "SELECT * FROM risultati_intolleranze WHERE test_id = :test_id",
            ['test_id' => $testId]
        );
        
        // Mappa risultati per alimento_id
        $resultsMap = [];
        foreach ($existingResults as $result) {
            $resultsMap[$result['alimento_id']] = $result;
        }
        $existingResults = $resultsMap;
        break;
        
    case 'microbiota':
        // Per il microbiota mostra solo info per upload referto
        $testData = $db->select("
            SELECT tm.nome FROM test_microbiota_dettagli tmd
            INNER JOIN tipi_microbiota tm ON tmd.tipo_microbiota_id = tm.id
            WHERE tmd.test_id = :test_id
        ", ['test_id' => $testId]);
        break;
}

// Genera token CSRF
$csrfToken = $session->generateCsrfToken();

// Includi header
require_once '../templates/header.php';
?>

<div class="container-fluid">
    <div class="row">
        <?php require_once '../templates/sidebar.php'; ?>
        
        <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
            <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                <h1 class="h2">Inserimento Risultati</h1>
                <div class="btn-toolbar mb-2 mb-md-0">
                    <a href="tests.php" class="btn btn-sm btn-secondary">
                        <i class="bi bi-arrow-left"></i> Torna alla lista
                    </a>
                </div>
            </div>
            
            <?php foreach ($session->getFlashMessages() as $flash): ?>
                <div class="alert alert-<?php echo $flash['type'] === 'error' ? 'danger' : $flash['type']; ?> alert-dismissible fade show" role="alert">
                    <?php echo htmlspecialchars($flash['message']); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endforeach; ?>
            
            <!-- Info Test -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0">Informazioni Test</h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-3">
                            <strong>Codice Test:</strong><br>
                            <?php echo htmlspecialchars($test['codice']); ?>
                        </div>
                        <div class="col-md-3">
                            <strong>Paziente:</strong><br>
                            <?php echo htmlspecialchars($test['paziente_display']); ?>
                        </div>
                        <div class="col-md-3">
                            <strong>Tipo Test:</strong><br>
                            <?php echo htmlspecialchars($test['tipo_test']); ?>
                        </div>
                        <div class="col-md-3">
                            <strong>Data Richiesta:</strong><br>
                            <?php echo date('d/m/Y', strtotime($test['data_richiesta'])); ?>
                        </div>
                    </div>
                    <?php if ($test['note']): ?>
                        <div class="mt-3">
                            <strong>Note:</strong><br>
                            <?php echo nl2br(htmlspecialchars($test['note'])); ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Form risultati -->
            <?php if ($test['tipo_test'] === 'genetico'): ?>
                <form method="post" id="resultsForm">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                    
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0">Risultati Test Genetici</h5>
                        </div>
                        <div class="card-body">
                            <?php
                            $currentGroup = '';
                            foreach ($testData as $gene):
                                if ($gene['gruppo_nome'] != $currentGroup):
                                    if ($currentGroup != '') echo '</div>';
                                    $currentGroup = $gene['gruppo_nome'];
                            ?>
                                <div class="gene-group mb-4">
                                    <h6 class="text-primary border-bottom pb-2">
                                        <?php echo htmlspecialchars($currentGroup ?: 'Altri Geni'); ?>
                                    </h6>
                            <?php endif; ?>
                                
                                <div class="row mb-3 align-items-center">
                                    <div class="col-md-3">
                                        <strong>
                                            <?php if ($gene['sigla']): ?>
                                                <?php echo htmlspecialchars($gene['sigla']); ?> -
                                            <?php endif; ?>
                                            <?php echo htmlspecialchars($gene['nome']); ?>
                                        </strong>
                                    </div>
                                    <div class="col-md-4">
                                        <select class="form-select" name="risultati[<?php echo $gene['id']; ?>]" required>
                                            <option value="">Seleziona risultato...</option>
                                            <?php foreach ($gene['risultati_possibili'] as $risultato): ?>
                                                <option value="<?php echo $risultato['id']; ?>"
                                                    <?php echo (isset($existingResults[$gene['id']]) && 
                                                                $existingResults[$gene['id']]['risultato_id'] == $risultato['id']) 
                                                                ? 'selected' : ''; ?>
                                                    data-tipo="<?php echo $risultato['tipo']; ?>">
                                                    <?php echo htmlspecialchars($risultato['nome']); ?>
                                                    (<?php echo $risultato['tipo']; ?>)
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-md-5">
                                        <input type="text" class="form-control" 
                                               name="note[<?php echo $gene['id']; ?>]"
                                               placeholder="Note opzionali..."
                                               value="<?php echo htmlspecialchars($existingResults[$gene['id']]['note'] ?? ''); ?>">
                                    </div>
                                </div>
                            <?php endforeach; ?>
                            <?php if ($currentGroup != '') echo '</div>'; ?>
                        </div>
                    </div>
                </form>
                
            <?php elseif (in_array($test['tipo_test'], ['intolleranze_cito', 'intolleranze_elisa'])): ?>
                <form method="post" id="resultsForm">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                    
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0">
                                Risultati Test Intolleranze 
                                <?php echo $test['tipo_test'] === 'intolleranze_elisa' ? 'ELISA' : 'Citotossico'; ?>
                            </h5>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table class="table table-striped">
                                    <thead>
                                        <tr>
                                            <th>Alimento</th>
                                            <?php if ($test['tipo_test'] === 'intolleranze_elisa'): ?>
                                                <th width="150">Valore (0-100)</th>
                                            <?php endif; ?>
                                            <th width="200">Grado Intolleranza</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($testData as $alimento): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($alimento['nome']); ?></td>
                                            <?php if ($test['tipo_test'] === 'intolleranze_elisa'): ?>
                                            <td>
                                                <input type="number" class="form-control valore-elisa" 
                                                       name="valori[<?php echo $alimento['id']; ?>]"
                                                       min="0" max="100" step="0.1"
                                                       value="<?php echo htmlspecialchars($existingResults[$alimento['id']]['valore_numerico'] ?? ''); ?>"
                                                       data-alimento="<?php echo $alimento['id']; ?>"
                                                       required>
                                            </td>
                                            <?php endif; ?>
                                            <td>
                                                <select class="form-select" name="gradi[<?php echo $alimento['id']; ?>]" 
                                                        id="grado_<?php echo $alimento['id']; ?>" required>
                                                    <option value="0" <?php echo (!isset($existingResults[$alimento['id']]) || 
                                                                                  $existingResults[$alimento['id']]['grado'] == 0) 
                                                                                  ? 'selected' : ''; ?>>
                                                        Grado 0 - Negativo
                                                    </option>
                                                    <option value="1" <?php echo (isset($existingResults[$alimento['id']]) && 
                                                                                  $existingResults[$alimento['id']]['grado'] == 1) 
                                                                                  ? 'selected' : ''; ?>>
                                                        Grado 1 - Leggera
                                                    </option>
                                                    <option value="2" <?php echo (isset($existingResults[$alimento['id']]) && 
                                                                                  $existingResults[$alimento['id']]['grado'] == 2) 
                                                                                  ? 'selected' : ''; ?>>
                                                        Grado 2 - Media
                                                    </option>
                                                    <option value="3" <?php echo (isset($existingResults[$alimento['id']]) && 
                                                                                  $existingResults[$alimento['id']]['grado'] == 3) 
                                                                                  ? 'selected' : ''; ?>>
                                                        Grado 3 - Grave
                                                    </option>
                                                </select>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </form>
                
            <?php elseif ($test['tipo_test'] === 'microbiota'): ?>
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">Test Microbiota</h5>
                    </div>
                    <div class="card-body">
                        <div class="alert alert-info">
                            <i class="bi bi-info-circle"></i>
                            Per i test del microbiota, il referto deve essere generato esternamente e caricato 
                            nella sezione <a href="reports.php">Gestione Referti</a>.
                        </div>
                        
                        <h6>Tipologie richieste:</h6>
                        <ul>
                            <?php foreach ($testData as $tipo): ?>
                                <li><?php echo htmlspecialchars($tipo['nome']); ?></li>
                            <?php endforeach; ?>
                        </ul>
                        
                        <form method="post">
                            <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                            <input type="hidden" name="completa_test" value="1">
                            <button type="submit" class="btn btn-primary">
                                Marca come Completato
                            </button>
                        </form>
                    </div>
                </div>
            <?php endif; ?>
            
            <!-- Pulsanti azione -->
            <?php if ($test['tipo_test'] !== 'microbiota'): ?>
            <div class="mt-4 d-flex justify-content-between">
                <div>
                    <button type="submit" form="resultsForm" class="btn btn-primary">
                        <i class="bi bi-save"></i> Salva Risultati
                    </button>
                    <button type="button" class="btn btn-secondary" onclick="resetForm()">
                        <i class="bi bi-x-circle"></i> Reset
                    </button>
                </div>
                <div>
                    <button type="submit" form="resultsForm" name="completa_test" value="1" 
                            class="btn btn-success">
                        <i class="bi bi-check-circle"></i> Salva e Completa Test
                    </button>
                </div>
            </div>
            <?php endif; ?>
        </main>
    </div>
</div>

<script>
<?php if ($test['tipo_test'] === 'intolleranze_elisa'): ?>
// Auto-calcolo grado per ELISA
document.querySelectorAll('.valore-elisa').forEach(input => {
    input.addEventListener('input', function() {
        const valore = parseFloat(this.value);
        const alimentoId = this.dataset.alimento;
        const gradoSelect = document.getElementById('grado_' + alimentoId);
        
        if (!isNaN(valore)) {
            if (valore <= 10) {
                gradoSelect.value = '0';
            } else if (valore <= 20) {
                gradoSelect.value = '1';
            } else if (valore <= 30) {
                gradoSelect.value = '2';
            } else {
                gradoSelect.value = '3';
            }
        }
    });
});
<?php endif; ?>

function resetForm() {
    if (confirm('Sei sicuro di voler resettare tutti i risultati inseriti?')) {
        document.getElementById('resultsForm').reset();
    }
}

// Validazione form
document.getElementById('resultsForm')?.addEventListener('submit', function(e) {
    const selects = this.querySelectorAll('select[required]');
    let valid = true;
    
    selects.forEach(select => {
        if (!select.value) {
            select.classList.add('is-invalid');
            valid = false;
        } else {
            select.classList.remove('is-invalid');
        }
    });
    
    if (!valid) {
        e.preventDefault();
        alert('Compilare tutti i campi obbligatori');
    }
});
</script>

<?php require_once '../templates/footer.php'; ?>
