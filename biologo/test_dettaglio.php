<?php
session_start();
require_once '../includes/auth.php';
require_once '../includes/database.php';
require_once '../includes/functions.php';
require_once '../classes/Test.php';

// Verifica autenticazione
if (!Auth::check() || Auth::getUserType() !== 'biologo') {
    redirect('/index.php');
}

$db = Database::getInstance()->getConnection();
$user = Auth::getUser();

// Recupera ID test
$test_id = (int)($_GET['id'] ?? 0);
if (!$test_id) {
    $_SESSION['error'] = "Test non specificato.";
    redirect('tests.php');
}

// Recupera dettagli test con dati pseudonimizzati
$stmt = $db->prepare("
    SELECT 
        t.*,
        CONCAT(LEFT(p.nome, 1), REPEAT('*', LENGTH(p.nome) - 2), RIGHT(p.nome, 1)) AS paziente_nome,
        CONCAT(LEFT(p.cognome, 1), REPEAT('*', LENGTH(p.cognome) - 2), RIGHT(p.cognome, 1)) AS paziente_cognome,
        p.codice_fiscale,
        p.data_nascita,
        p.sesso,
        pr.utente_id AS professionista_utente_id,
        u.nome AS professionista_nome,
        u.cognome AS professionista_cognome,
        r.id AS referto_id,
        r.data_creazione AS data_refertazione
    FROM test t
    JOIN pazienti p ON t.paziente_id = p.id
    JOIN professionisti pr ON t.professionista_id = pr.id
    JOIN utenti u ON pr.utente_id = u.id
    LEFT JOIN referti r ON t.id = r.test_id
    WHERE t.id = ? AND t.stato IN ('in_lavorazione', 'eseguito', 'refertato', 'firmato')
");
$stmt->execute([$test_id]);
$test = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$test) {
    $_SESSION['error'] = "Test non trovato o non disponibile.";
    redirect('tests.php');
}

// Recupera dettagli specifici in base al tipo di test
$dettagli = [];
$can_insert_results = ($test['stato'] === 'in_lavorazione' || $test['stato'] === 'eseguito');

switch ($test['tipo_test']) {
    case 'genetico':
        // Dettagli test genetico
        $stmt = $db->prepare("
            SELECT 
                d.*,
                CASE 
                    WHEN d.tipo_elemento = 'gene' THEN g.nome
                    WHEN d.tipo_elemento = 'pannello' THEN pg.nome
                END AS nome,
                CASE 
                    WHEN d.tipo_elemento = 'gene' THEN g.sigla
                    ELSE NULL
                END AS sigla
            FROM test_genetici_dettagli d
            LEFT JOIN geni g ON d.tipo_elemento = 'gene' AND d.elemento_id = g.id
            LEFT JOIN pannelli_genetici pg ON d.tipo_elemento = 'pannello' AND d.elemento_id = pg.id
            WHERE d.test_id = ?
        ");
        $stmt->execute([$test_id]);
        $dettagli = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Lista geni da analizzare
        $geni_da_analizzare = [];
        foreach ($dettagli as $dettaglio) {
            if ($dettaglio['tipo_elemento'] === 'gene') {
                $geni_da_analizzare[] = $dettaglio['elemento_id'];
            } else {
                // È un pannello, recupera i geni
                $stmt = $db->prepare("SELECT gene_id FROM pannelli_geni WHERE pannello_id = ?");
                $stmt->execute([$dettaglio['elemento_id']]);
                $geni_pannello = $stmt->fetchAll(PDO::FETCH_COLUMN);
                $geni_da_analizzare = array_merge($geni_da_analizzare, $geni_pannello);
            }
        }
        $geni_da_analizzare = array_unique($geni_da_analizzare);
        break;
        
    case 'microbiota':
        $stmt = $db->prepare("
            SELECT d.*, tm.nome
            FROM test_microbiota_dettagli d
            JOIN tipi_microbiota tm ON d.tipo_microbiota_id = tm.id
            WHERE d.test_id = ?
        ");
        $stmt->execute([$test_id]);
        $dettagli = $stmt->fetchAll(PDO::FETCH_ASSOC);
        break;
        
    case 'intolleranze_cito':
    case 'intolleranze_elisa':
        $stmt = $db->prepare("
            SELECT d.*, pi.nome, pi.tipo
            FROM test_intolleranze_dettagli d
            JOIN pannelli_intolleranze pi ON d.pannello_id = pi.id
            WHERE d.test_id = ?
        ");
        $stmt->execute([$test_id]);
        $dettagli = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Lista alimenti da testare
        $alimenti_da_testare = [];
        foreach ($dettagli as $dettaglio) {
            $stmt = $db->prepare("
                SELECT a.* 
                FROM pannelli_alimenti pa
                JOIN alimenti a ON pa.alimento_id = a.id
                WHERE pa.pannello_id = ?
                ORDER BY a.nome
            ");
            $stmt->execute([$dettaglio['pannello_id']]);
            $alimenti = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $alimenti_da_testare = array_merge($alimenti_da_testare, $alimenti);
        }
        break;
}

$page_title = 'Dettaglio Test - ' . $test['codice'];
require_once '../templates/header.php';
require_once '../templates/sidebar.php';
?>

<div class="container-fluid">
    <div class="row">
        <div class="col-md-12">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h1>Dettaglio Test</h1>
                <div>
                    <?php if ($can_insert_results): ?>
                        <?php if ($test['tipo_test'] === 'genetico'): ?>
                            <a href="results.php?test_id=<?php echo $test_id; ?>" 
                               class="btn btn-primary">
                                <i class="fas fa-edit"></i> Inserisci Risultati
                            </a>
                        <?php elseif ($test['tipo_test'] === 'microbiota'): ?>
                            <a href="upload_referto.php?test_id=<?php echo $test_id; ?>" 
                               class="btn btn-primary">
                                <i class="fas fa-upload"></i> Carica Referto
                            </a>
                        <?php elseif (in_array($test['tipo_test'], ['intolleranze_cito', 'intolleranze_elisa'])): ?>
                            <a href="results_intolleranze.php?test_id=<?php echo $test_id; ?>" 
                               class="btn btn-primary">
                                <i class="fas fa-edit"></i> Inserisci Risultati
                            </a>
                        <?php endif; ?>
                    <?php endif; ?>
                    
                    <?php if ($test['referto_id']): ?>
                        <a href="reports.php?test_id=<?php echo $test_id; ?>" 
                           class="btn btn-success">
                            <i class="fas fa-file-medical"></i> Gestisci Referto
                        </a>
                    <?php endif; ?>
                    
                    <a href="tests.php" class="btn btn-secondary">
                        <i class="fas fa-arrow-left"></i> Torna alla Lista
                    </a>
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

            <!-- Informazioni Test -->
            <div class="card mb-4">
                <div class="card-header bg-primary text-white">
                    <h5 class="mb-0">Informazioni Test</h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <p><strong>Codice Test:</strong> <?php echo htmlspecialchars($test['codice']); ?></p>
                            <p><strong>Tipo:</strong> 
                                <?php 
                                $tipi = [
                                    'genetico' => 'Test Genetico',
                                    'microbiota' => 'Analisi Microbiota',
                                    'intolleranze_cito' => 'Intolleranze Citotossiche',
                                    'intolleranze_elisa' => 'Intolleranze ELISA'
                                ];
                                echo $tipi[$test['tipo_test']] ?? $test['tipo_test'];
                                ?>
                            </p>
                            <p><strong>Stato:</strong> 
                                <?php
                                $stati = [
                                    'in_lavorazione' => ['badge' => 'info', 'text' => 'In Lavorazione'],
                                    'eseguito' => ['badge' => 'primary', 'text' => 'Eseguito'],
                                    'refertato' => ['badge' => 'success', 'text' => 'Refertato'],
                                    'firmato' => ['badge' => 'success', 'text' => 'Firmato']
                                ];
                                $stato_info = $stati[$test['stato']] ?? ['badge' => 'secondary', 'text' => ucfirst($test['stato'])];
                                ?>
                                <span class="badge bg-<?php echo $stato_info['badge']; ?>">
                                    <?php echo $stato_info['text']; ?>
                                </span>
                            </p>
                        </div>
                        <div class="col-md-6">
                            <p><strong>Data Richiesta:</strong> <?php echo formatDateIta($test['data_richiesta']); ?></p>
                            <p><strong>Professionista:</strong> Dr. <?php echo htmlspecialchars($test['professionista_cognome'] . ' ' . $test['professionista_nome']); ?></p>
                            <?php if ($test['data_refertazione']): ?>
                                <p><strong>Data Refertazione:</strong> <?php echo formatDateIta($test['data_refertazione']); ?></p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Informazioni Paziente (Pseudonimizzate) -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0">Informazioni Paziente</h5>
                </div>
                <div class="card-body">
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle"></i> I dati del paziente sono pseudonimizzati per proteggere la privacy
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <p><strong>Nome:</strong> <span class="pseudonymized"><?php echo htmlspecialchars($test['paziente_nome']); ?></span></p>
                            <p><strong>Cognome:</strong> <span class="pseudonymized"><?php echo htmlspecialchars($test['paziente_cognome']); ?></span></p>
                            <p><strong>Codice Fiscale:</strong> <?php echo htmlspecialchars($test['codice_fiscale']); ?></p>
                        </div>
                        <div class="col-md-6">
                            <p><strong>Data di Nascita:</strong> <?php echo formatDateIta($test['data_nascita']); ?></p>
                            <p><strong>Età:</strong> <?php echo calculateAge($test['data_nascita']); ?> anni</p>
                            <p><strong>Sesso:</strong> <?php echo $test['sesso'] === 'M' ? 'Maschile' : 'Femminile'; ?></p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Dettagli Analisi -->
            <?php if ($test['tipo_test'] === 'genetico' && !empty($geni_da_analizzare)): ?>
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0">Geni da Analizzare</h5>
                    </div>
                    <div class="card-body">
                        <p>Totale geni: <strong><?php echo count($geni_da_analizzare); ?></strong></p>
                        
                        <?php
                        // Recupera dettagli geni
                        $placeholders = str_repeat('?,', count($geni_da_analizzare) - 1) . '?';
                        $stmt = $db->prepare("
                            SELECT g.*, gg.nome AS gruppo_nome
                            FROM geni g
                            LEFT JOIN gruppi_geni gg ON g.gruppo_id = gg.id
                            WHERE g.id IN ($placeholders)
                            ORDER BY gg.ordine, gg.nome, g.nome
                        ");
                        $stmt->execute($geni_da_analizzare);
                        $geni = $stmt->fetchAll(PDO::FETCH_ASSOC);
                        
                        // Raggruppa per gruppo
                        $geni_per_gruppo = [];
                        foreach ($geni as $gene) {
                            $gruppo = $gene['gruppo_nome'] ?? 'Altri';
                            if (!isset($geni_per_gruppo[$gruppo])) {
                                $geni_per_gruppo[$gruppo] = [];
                            }
                            $geni_per_gruppo[$gruppo][] = $gene;
                        }
                        ?>
                        
                        <?php foreach ($geni_per_gruppo as $gruppo => $geni_gruppo): ?>
                            <h6 class="mt-3"><?php echo htmlspecialchars($gruppo); ?></h6>
                            <div class="table-responsive">
                                <table class="table table-sm">
                                    <thead>
                                        <tr>
                                            <th>Gene</th>
                                            <th>Sigla</th>
                                            <th>Descrizione</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($geni_gruppo as $gene): ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($gene['nome']); ?></td>
                                                <td><?php echo htmlspecialchars($gene['sigla'] ?? '-'); ?></td>
                                                <td><?php echo htmlspecialchars($gene['descrizione'] ?? '-'); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($test['tipo_test'] === 'microbiota'): ?>
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0">Tipo di Analisi Microbiota</h5>
                    </div>
                    <div class="card-body">
                        <?php foreach ($dettagli as $dettaglio): ?>
                            <p><strong><?php echo htmlspecialchars($dettaglio['nome']); ?></strong></p>
                        <?php endforeach; ?>
                        
                        <div class="alert alert-warning mt-3">
                            <i class="fas fa-exclamation-triangle"></i>
                            Per questo tipo di test, il referto dettagliato deve essere caricato come file PDF esterno.
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (in_array($test['tipo_test'], ['intolleranze_cito', 'intolleranze_elisa']) && !empty($alimenti_da_testare)): ?>
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0">Alimenti da Testare</h5>
                    </div>
                    <div class="card-body">
                        <p>Totale alimenti: <strong><?php echo count($alimenti_da_testare); ?></strong></p>
                        
                        <div class="row">
                            <?php 
                            $alimenti_unici = [];
                            foreach ($alimenti_da_testare as $alimento) {
                                $alimenti_unici[$alimento['id']] = $alimento['nome'];
                            }
                            asort($alimenti_unici);
                            
                            $columns = array_chunk($alimenti_unici, ceil(count($alimenti_unici) / 3), true);
                            foreach ($columns as $column): 
                            ?>
                                <div class="col-md-4">
                                    <ul class="list-unstyled">
                                        <?php foreach ($column as $id => $nome): ?>
                                            <li><i class="fas fa-check text-success"></i> <?php echo htmlspecialchars($nome); ?></li>
                                        <?php endforeach; ?>
                                    </ul>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        
                        <?php if ($test['tipo_test'] === 'intolleranze_elisa'): ?>
                            <div class="alert alert-info mt-3">
                                <i class="fas fa-info-circle"></i>
                                <strong>Metodica ELISA:</strong> I valori saranno espressi numericamente da 0 a 100.
                                <br>Classificazione: 0-10 (Grado 0), 11-20 (Grado 1), 21-30 (Grado 2), 31-100 (Grado 3)
                            </div>
                        <?php else: ?>
                            <div class="alert alert-info mt-3">
                                <i class="fas fa-info-circle"></i>
                                <strong>Metodica Citotossica:</strong> I risultati saranno classificati in gradi da 0 a 3
                                basati sulla reazione leucocitaria.
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Note -->
            <?php if ($test['note']): ?>
                <div class="card">
                    <div class="card-header">
                        <h5 class="mb-0">Note</h5>
                    </div>
                    <div class="card-body">
                        <p class="mb-0"><?php echo nl2br(htmlspecialchars($test['note'])); ?></p>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require_once '../templates/footer.php'; ?>