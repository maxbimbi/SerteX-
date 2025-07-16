<?php
session_start();
require_once '../includes/auth.php';
require_once '../includes/database.php';
require_once '../includes/functions.php';
require_once '../classes/Test.php';
require_once '../classes/Report.php';

// Verifica autenticazione
if (!Auth::check() || Auth::getUserType() !== 'professionista') {
    redirect('/index.php');
}

$db = Database::getInstance()->getConnection();
$user = Auth::getUser();
$professionista_id = getProfessionistaId($db, $user['id']);

// Recupera ID test
$test_id = (int)($_GET['id'] ?? 0);
if (!$test_id) {
    $_SESSION['error'] = "Test non specificato.";
    redirect('tests.php');
}

// Recupera dettagli test
$stmt = $db->prepare("
    SELECT t.*, 
           p.nome AS paziente_nome, 
           p.cognome AS paziente_cognome, 
           p.codice_fiscale,
           p.data_nascita,
           p.sesso,
           p.email AS paziente_email,
           p.telefono AS paziente_telefono,
           r.id AS referto_id,
           r.file_path,
           r.file_path_firmato,
           r.data_creazione AS data_refertazione,
           r.data_firma,
           u.nome AS biologo_nome,
           u.cognome AS biologo_cognome
    FROM test t
    JOIN pazienti p ON t.paziente_id = p.id
    LEFT JOIN referti r ON t.id = r.test_id
    LEFT JOIN utenti u ON r.biologo_id = u.id
    WHERE t.id = ? AND t.professionista_id = ?
");
$stmt->execute([$test_id, $professionista_id]);
$test = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$test) {
    $_SESSION['error'] = "Test non trovato o non autorizzato.";
    redirect('tests.php');
}

// Recupera dettagli specifici in base al tipo di test
$dettagli = [];
switch ($test['tipo_test']) {
    case 'genetico':
        $stmt = $db->prepare("
            SELECT 
                d.id,
                d.tipo_elemento,
                d.prezzo_unitario,
                CASE 
                    WHEN d.tipo_elemento = 'gene' THEN g.nome
                    WHEN d.tipo_elemento = 'pannello' THEN pg.nome
                END AS nome,
                CASE 
                    WHEN d.tipo_elemento = 'gene' THEN g.sigla
                    ELSE NULL
                END AS sigla,
                CASE 
                    WHEN d.tipo_elemento = 'gene' THEN g.descrizione
                    WHEN d.tipo_elemento = 'pannello' THEN pg.descrizione
                END AS descrizione
            FROM test_genetici_dettagli d
            LEFT JOIN geni g ON d.tipo_elemento = 'gene' AND d.elemento_id = g.id
            LEFT JOIN pannelli_genetici pg ON d.tipo_elemento = 'pannello' AND d.elemento_id = pg.id
            WHERE d.test_id = ?
        ");
        $stmt->execute([$test_id]);
        $dettagli = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Recupera risultati se disponibili
        if ($test['stato'] == 'refertato' || $test['stato'] == 'firmato') {
            $stmt = $db->prepare("
                SELECT rg.*, g.nome AS gene_nome, g.sigla AS gene_sigla, 
                       r.nome AS risultato_nome, r.tipo AS risultato_tipo
                FROM risultati_genetici rg
                JOIN geni g ON rg.gene_id = g.id
                JOIN risultati_geni r ON rg.risultato_id = r.id
                WHERE rg.test_id = ?
            ");
            $stmt->execute([$test_id]);
            $risultati = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        break;
        
    case 'microbiota':
        $stmt = $db->prepare("
            SELECT d.*, tm.nome, tm.descrizione
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
            SELECT d.*, pi.nome, pi.descrizione
            FROM test_intolleranze_dettagli d
            JOIN pannelli_intolleranze pi ON d.pannello_id = pi.id
            WHERE d.test_id = ?
        ");
        $stmt->execute([$test_id]);
        $dettagli = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Recupera risultati se disponibili
        if ($test['stato'] == 'refertato' || $test['stato'] == 'firmato') {
            $stmt = $db->prepare("
                SELECT ri.*, a.nome AS alimento_nome
                FROM risultati_intolleranze ri
                JOIN alimenti a ON ri.alimento_id = a.id
                WHERE ri.test_id = ?
                ORDER BY ri.grado DESC, a.nome
            ");
            $stmt->execute([$test_id]);
            $risultati_intolleranze = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        break;
}

$page_title = 'Dettaglio Test #' . $test['codice'];
require_once '../templates/header.php';
require_once '../templates/sidebar.php';
?>

<div class="container-fluid">
    <div class="row">
        <div class="col-md-12">
            <div class="d-flex justify-content-between align-items-center mb-4">
                <h1>Dettaglio Test</h1>
                <div>
                    <?php if ($test['referto_id'] && $test['file_path_firmato']): ?>
                        <a href="download_report.php?test_id=<?php echo $test_id; ?>&type=signed" 
                           class="btn btn-success">
                            <i class="fas fa-download"></i> Scarica Referto Firmato
                        </a>
                    <?php elseif ($test['referto_id']): ?>
                        <a href="download_report.php?test_id=<?php echo $test_id; ?>" 
                           class="btn btn-primary">
                            <i class="fas fa-download"></i> Scarica Referto
                        </a>
                    <?php endif; ?>
                    <a href="tests.php" class="btn btn-secondary">
                        <i class="fas fa-arrow-left"></i> Torna alla Lista
                    </a>
                </div>
            </div>

            <!-- Informazioni Test -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0">Informazioni Test</h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <table class="table table-sm">
                                <tr>
                                    <th width="40%">Codice Test:</th>
                                    <td><strong><?php echo htmlspecialchars($test['codice']); ?></strong></td>
                                </tr>
                                <tr>
                                    <th>Tipo Test:</th>
                                    <td>
                                        <?php 
                                        $tipi = [
                                            'genetico' => 'Test Genetico',
                                            'microbiota' => 'Analisi Microbiota',
                                            'intolleranze_cito' => 'Intolleranze Citotossiche',
                                            'intolleranze_elisa' => 'Intolleranze ELISA'
                                        ];
                                        echo $tipi[$test['tipo_test']] ?? $test['tipo_test'];
                                        ?>
                                    </td>
                                </tr>
                                <tr>
                                    <th>Stato:</th>
                                    <td>
                                        <?php
                                        $stati = [
                                            'richiesto' => ['badge' => 'warning', 'icon' => 'clock'],
                                            'in_lavorazione' => ['badge' => 'info', 'icon' => 'flask'],
                                            'eseguito' => ['badge' => 'primary', 'icon' => 'check-circle'],
                                            'refertato' => ['badge' => 'success', 'icon' => 'file-medical'],
                                            'firmato' => ['badge' => 'success', 'icon' => 'file-signature']
                                        ];
                                        $stato_info = $stati[$test['stato']] ?? ['badge' => 'secondary', 'icon' => 'question'];
                                        ?>
                                        <span class="badge bg-<?php echo $stato_info['badge']; ?>">
                                            <i class="fas fa-<?php echo $stato_info['icon']; ?>"></i>
                                            <?php echo ucfirst($test['stato']); ?>
                                        </span>
                                    </td>
                                </tr>
                                <tr>
                                    <th>Data Richiesta:</th>
                                    <td><?php echo formatDateIta($test['data_richiesta'], 'd/m/Y H:i'); ?></td>
                                </tr>
                                <?php if ($test['data_esecuzione']): ?>
                                <tr>
                                    <th>Data Esecuzione:</th>
                                    <td><?php echo formatDateIta($test['data_esecuzione'], 'd/m/Y'); ?></td>
                                </tr>
                                <?php endif; ?>
                                <?php if ($test['data_refertazione']): ?>
                                <tr>
                                    <th>Data Refertazione:</th>
                                    <td><?php echo formatDateIta($test['data_refertazione'], 'd/m/Y H:i'); ?></td>
                                </tr>
                                <?php endif; ?>
                            </table>
                        </div>
                        <div class="col-md-6">
                            <table class="table table-sm">
                                <tr>
                                    <th width="40%">Prezzo Base:</th>
                                    <td>€ <?php echo number_format($test['prezzo_totale'], 2, ',', '.'); ?></td>
                                </tr>
                                <?php if ($test['sconto'] > 0): ?>
                                <tr>
                                    <th>Sconto:</th>
                                    <td><?php echo number_format($test['sconto'], 2, ',', '.'); ?>%</td>
                                </tr>
                                <?php endif; ?>
                                <tr>
                                    <th>IVA:</th>
                                    <td><?php echo number_format($test['iva'], 0); ?>%</td>
                                </tr>
                                <tr>
                                    <th>Prezzo Finale:</th>
                                    <td><strong>€ <?php echo number_format($test['prezzo_finale'], 2, ',', '.'); ?></strong></td>
                                </tr>
                                <tr>
                                    <th>Fatturato:</th>
                                    <td>
                                        <?php if ($test['fatturato']): ?>
                                            <span class="badge bg-success">Sì</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary">No</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php if ($test['biologo_nome']): ?>
                                <tr>
                                    <th>Biologo Refertante:</th>
                                    <td>Dr. <?php echo htmlspecialchars($test['biologo_cognome'] . ' ' . $test['biologo_nome']); ?></td>
                                </tr>
                                <?php endif; ?>
                            </table>
                        </div>
                    </div>
                    
                    <?php if ($test['note']): ?>
                    <div class="mt-3">
                        <strong>Note:</strong>
                        <p class="mb-0"><?php echo nl2br(htmlspecialchars($test['note'])); ?></p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Informazioni Paziente -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0">Informazioni Paziente</h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <p><strong>Nome:</strong> <?php echo htmlspecialchars($test['paziente_nome']); ?></p>
                            <p><strong>Cognome:</strong> <?php echo htmlspecialchars($test['paziente_cognome']); ?></p>
                            <p><strong>Codice Fiscale:</strong> <?php echo htmlspecialchars($test['codice_fiscale']); ?></p>
                        </div>
                        <div class="col-md-6">
                            <p><strong>Data di Nascita:</strong> <?php echo formatDateIta($test['data_nascita']); ?></p>
                            <p><strong>Sesso:</strong> <?php echo $test['sesso'] === 'M' ? 'Maschile' : 'Femminile'; ?></p>
                            <?php if ($test['paziente_email']): ?>
                                <p><strong>Email:</strong> <?php echo htmlspecialchars($test['paziente_email']); ?></p>
                            <?php endif; ?>
                            <?php if ($test['paziente_telefono']): ?>
                                <p><strong>Telefono:</strong> <?php echo htmlspecialchars($test['paziente_telefono']); ?></p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Dettagli Analisi -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0">Dettagli Analisi</h5>
                </div>
                <div class="card-body">
                    <?php if (empty($dettagli)): ?>
                        <p class="text-muted">Nessun dettaglio disponibile.</p>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Elemento</th>
                                        <th>Descrizione</th>
                                        <th>Prezzo</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($dettagli as $dettaglio): ?>
                                        <tr>
                                            <td>
                                                <strong><?php echo htmlspecialchars($dettaglio['nome']); ?></strong>
                                                <?php if ($dettaglio['sigla']): ?>
                                                    <small class="text-muted">(<?php echo htmlspecialchars($dettaglio['sigla']); ?>)</small>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php echo htmlspecialchars($dettaglio['descrizione'] ?? 'N/D'); ?>
                                            </td>
                                            <td>€ <?php echo number_format($dettaglio['prezzo_unitario'], 2, ',', '.'); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Risultati (se disponibili) -->
            <?php if (isset($risultati) && !empty($risultati)): ?>
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0">Risultati Test Genetico</h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Gene</th>
                                        <th>Risultato</th>
                                        <th>Note</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($risultati as $risultato): ?>
                                        <tr>
                                            <td>
                                                <strong><?php echo htmlspecialchars($risultato['gene_nome']); ?></strong>
                                                <?php if ($risultato['gene_sigla']): ?>
                                                    <small class="text-muted">(<?php echo htmlspecialchars($risultato['gene_sigla']); ?>)</small>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php
                                                $badge_class = 'secondary';
                                                if ($risultato['risultato_tipo'] === 'positivo') {
                                                    $badge_class = 'danger';
                                                } elseif ($risultato['risultato_tipo'] === 'negativo') {
                                                    $badge_class = 'success';
                                                }
                                                ?>
                                                <span class="badge bg-<?php echo $badge_class; ?>">
                                                    <?php echo htmlspecialchars($risultato['risultato_nome']); ?>
                                                </span>
                                            </td>
                                            <td><?php echo htmlspecialchars($risultato['note'] ?? '-'); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <?php if (isset($risultati_intolleranze) && !empty($risultati_intolleranze)): ?>
                <div class="card mb-4">
                    <div class="card-header">
                        <h5 class="mb-0">Risultati Intolleranze</h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Alimento</th>
                                        <th>Grado</th>
                                        <?php if ($test['tipo_test'] === 'intolleranze_elisa'): ?>
                                            <th>Valore</th>
                                        <?php endif; ?>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($risultati_intolleranze as $risultato): ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($risultato['alimento_nome']); ?></td>
                                            <td>
                                                <?php
                                                $badge_classes = [
                                                    0 => 'success',
                                                    1 => 'warning',
                                                    2 => 'orange',
                                                    3 => 'danger'
                                                ];
                                                ?>
                                                <span class="badge bg-<?php echo $badge_classes[$risultato['grado']] ?? 'secondary'; ?>">
                                                    Grado <?php echo $risultato['grado']; ?>
                                                </span>
                                            </td>
                                            <?php if ($test['tipo_test'] === 'intolleranze_elisa'): ?>
                                                <td><?php echo number_format($risultato['valore_numerico'], 1); ?></td>
                                            <?php endif; ?>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Timeline Eventi -->
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">Timeline Eventi</h5>
                </div>
                <div class="card-body">
                    <div class="timeline">
                        <div class="timeline-item">
                            <div class="timeline-marker bg-primary"></div>
                            <div class="timeline-content">
                                <h6>Test Richiesto</h6>
                                <p class="text-muted mb-0"><?php echo formatDateIta($test['data_richiesta'], 'd/m/Y H:i'); ?></p>
                            </div>
                        </div>
                        
                        <?php if ($test['data_esecuzione']): ?>
                        <div class="timeline-item">
                            <div class="timeline-marker bg-info"></div>
                            <div class="timeline-content">
                                <h6>Test Eseguito</h6>
                                <p class="text-muted mb-0"><?php echo formatDateIta($test['data_esecuzione'], 'd/m/Y H:i'); ?></p>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <?php if ($test['data_refertazione']): ?>
                        <div class="timeline-item">
                            <div class="timeline-marker bg-success"></div>
                            <div class="timeline-content">
                                <h6>Referto Emesso</h6>
                                <p class="text-muted mb-0"><?php echo formatDateIta($test['data_refertazione'], 'd/m/Y H:i'); ?></p>
                                <p class="text-muted mb-0">Dr. <?php echo htmlspecialchars($test['biologo_cognome'] . ' ' . $test['biologo_nome']); ?></p>
                            </div>
                        </div>
                        <?php endif; ?>
                        
                        <?php if ($test['data_firma']): ?>
                        <div class="timeline-item">
                            <div class="timeline-marker bg-success"></div>
                            <div class="timeline-content">
                                <h6>Referto Firmato Digitalmente</h6>
                                <p class="text-muted mb-0"><?php echo formatDateIta($test['data_firma'], 'd/m/Y H:i'); ?></p>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
.timeline {
    position: relative;
    padding: 20px 0;
}
.timeline-item {
    position: relative;
    padding-left: 40px;
    margin-bottom: 20px;
}
.timeline-item:not(:last-child):after {
    content: '';
    position: absolute;
    left: 9px;
    top: 30px;
    height: calc(100% + 10px);
    width: 2px;
    background: #e9ecef;
}
.timeline-marker {
    position: absolute;
    left: 0;
    top: 0;
    width: 20px;
    height: 20px;
    border-radius: 50%;
    border: 3px solid #fff;
    box-shadow: 0 0 0 1px #dee2e6;
}
.timeline-content h6 {
    margin-bottom: 5px;
}
.badge.bg-orange {
    background-color: #ff9800;
}
</style>

<?php require_once '../templates/footer.php'; ?>