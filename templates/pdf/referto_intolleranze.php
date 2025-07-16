<?php
/**
 * Template PDF per Referto Intolleranze Alimentari
 * Questo file genera l'HTML che verrà convertito in PDF
 */

// Verifica che le variabili necessarie siano definite
if (!isset($test_data) || !isset($paziente) || !isset($risultati) || !isset($biologo) || !isset($pannello)) {
    die('Dati mancanti per la generazione del referto');
}

// Recupera configurazioni
$nome_laboratorio = $config['nome_laboratorio'] ?? 'SerteX+';
$logo_path = $config['logo_path'] ?? '';
$header_text = $template['header'] ?? '';
$footer_text = $template['footer'] ?? '';

// Determina tipo di test
$is_elisa = $test_data['tipo_test'] === 'intolleranze_elisa';
$test_type_label = $is_elisa ? 'ELISA' : 'Citotossico';
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <style>
        @page {
            margin: 2cm;
            @bottom-right {
                content: "Pagina " counter(page) " di " counter(pages);
            }
        }
        
        body {
            font-family: Arial, sans-serif;
            font-size: 11pt;
            line-height: 1.6;
            color: #333;
        }
        
        .header {
            text-align: center;
            margin-bottom: 30px;
            border-bottom: 2px solid #ff9800;
            padding-bottom: 20px;
        }
        
        .logo {
            max-height: 80px;
            margin-bottom: 10px;
        }
        
        .lab-name {
            font-size: 24pt;
            font-weight: bold;
            color: #ff9800;
            margin: 10px 0;
        }
        
        .report-title {
            font-size: 18pt;
            font-weight: bold;
            margin: 20px 0;
            text-align: center;
            color: #333;
        }
        
        .info-section {
            margin-bottom: 20px;
            background-color: #f8f9fa;
            padding: 15px;
            border-radius: 5px;
        }
        
        .info-row {
            display: flex;
            margin-bottom: 8px;
        }
        
        .info-label {
            font-weight: bold;
            width: 150px;
            color: #555;
        }
        
        .info-value {
            flex: 1;
        }
        
        .test-info {
            background-color: #fff3e0;
            border-left: 4px solid #ff9800;
            padding: 15px;
            margin: 20px 0;
        }
        
        .results-section {
            margin-top: 30px;
        }
        
        .section-title {
            font-size: 14pt;
            font-weight: bold;
            color: #ff9800;
            margin-bottom: 15px;
            border-bottom: 1px solid #ddd;
            padding-bottom: 5px;
        }
        
        .results-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
        
        .results-table th {
            background-color: #ff9800;
            color: white;
            padding: 10px;
            text-align: left;
            font-weight: bold;
        }
        
        .results-table td {
            padding: 8px;
            border-bottom: 1px solid #e0e0e0;
        }
        
        .results-table tr:nth-child(even) {
            background-color: #f9f9f9;
        }
        
        .grade-0 {
            background-color: #e8f5e9;
            color: #2e7d32;
            padding: 3px 8px;
            border-radius: 3px;
            font-weight: bold;
        }
        
        .grade-1 {
            background-color: #fff9c4;
            color: #f57f17;
            padding: 3px 8px;
            border-radius: 3px;
            font-weight: bold;
        }
        
        .grade-2 {
            background-color: #ffe0b2;
            color: #e65100;
            padding: 3px 8px;
            border-radius: 3px;
            font-weight: bold;
        }
        
        .grade-3 {
            background-color: #ffccbc;
            color: #bf360c;
            padding: 3px 8px;
            border-radius: 3px;
            font-weight: bold;
        }
        
        .legend {
            margin-top: 20px;
            padding: 15px;
            background-color: #f5f5f5;
            border-radius: 5px;
        }
        
        .legend-title {
            font-weight: bold;
            margin-bottom: 10px;
        }
        
        .legend-item {
            display: inline-block;
            margin-right: 20px;
            margin-bottom: 5px;
        }
        
        .summary-box {
            background-color: #e3f2fd;
            border: 1px solid #1976d2;
            padding: 15px;
            margin: 20px 0;
            border-radius: 5px;
        }
        
        .summary-box h4 {
            color: #1976d2;
            margin-top: 0;
        }
        
        .intolerance-list {
            columns: 2;
            column-gap: 20px;
        }
        
        .intolerance-list li {
            break-inside: avoid;
            margin-bottom: 5px;
        }
        
        .method-info {
            background-color: #f5f5f5;
            padding: 15px;
            border-radius: 5px;
            margin: 20px 0;
            font-size: 10pt;
        }
        
        .signature-section {
            margin-top: 50px;
            page-break-inside: avoid;
        }
        
        .signature-box {
            border-top: 1px solid #333;
            padding-top: 10px;
            width: 300px;
            margin: 0 auto;
            text-align: center;
        }
        
        .signature-image {
            max-height: 60px;
            margin-bottom: 10px;
        }
        
        .footer {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            text-align: center;
            font-size: 9pt;
            color: #666;
            border-top: 1px solid #ddd;
            padding-top: 10px;
        }
        
        .privacy-notice {
            margin-top: 20px;
            font-size: 9pt;
            color: #666;
            text-align: justify;
            padding: 10px;
            background-color: #f8f9fa;
            border: 1px solid #dee2e6;
        }
        
        .hash-info {
            font-size: 8pt;
            color: #999;
            margin-top: 20px;
            word-break: break-all;
        }
        
        @media print {
            .results-table {
                page-break-inside: auto;
            }
            .results-table tr {
                page-break-inside: avoid;
            }
        }
    </style>
</head>
<body>
    <!-- Header -->
    <div class="header">
        <?php if ($logo_path && file_exists($logo_path)): ?>
            <img src="<?php echo $logo_path; ?>" alt="Logo" class="logo">
        <?php endif; ?>
        <div class="lab-name"><?php echo htmlspecialchars($nome_laboratorio); ?></div>
        <?php if ($header_text): ?>
            <div><?php echo $header_text; ?></div>
        <?php endif; ?>
    </div>

    <h1 class="report-title">REFERTO INTOLLERANZE ALIMENTARI<br>
        <span style="font-size: 14pt;">Test <?php echo $test_type_label; ?></span>
    </h1>

    <!-- Informazioni Test e Paziente -->
    <div class="info-section">
        <div class="info-row">
            <span class="info-label">Codice Test:</span>
            <span class="info-value"><strong><?php echo htmlspecialchars($test_data['codice']); ?></strong></span>
        </div>
        <div class="info-row">
            <span class="info-label">Data Richiesta:</span>
            <span class="info-value"><?php echo date('d/m/Y', strtotime($test_data['data_richiesta'])); ?></span>
        </div>
        <div class="info-row">
            <span class="info-label">Data Esecuzione:</span>
            <span class="info-value"><?php echo date('d/m/Y', strtotime($test_data['data_esecuzione'])); ?></span>
        </div>
        <div class="info-row">
            <span class="info-label">Data Refertazione:</span>
            <span class="info-value"><?php echo date('d/m/Y H:i', strtotime($test_data['data_refertazione'])); ?></span>
        </div>
    </div>

    <div class="info-section">
        <div class="info-row">
            <span class="info-label">Paziente:</span>
            <span class="info-value"><strong><?php echo htmlspecialchars($paziente['cognome'] . ' ' . $paziente['nome']); ?></strong></span>
        </div>
        <div class="info-row">
            <span class="info-label">Codice Fiscale:</span>
            <span class="info-value"><?php echo htmlspecialchars($paziente['codice_fiscale']); ?></span>
        </div>
        <div class="info-row">
            <span class="info-label">Data di Nascita:</span>
            <span class="info-value"><?php echo date('d/m/Y', strtotime($paziente['data_nascita'])); ?></span>
        </div>
        <div class="info-row">
            <span class="info-label">Sesso:</span>
            <span class="info-value"><?php echo $paziente['sesso'] === 'M' ? 'Maschile' : 'Femminile'; ?></span>
        </div>
    </div>

    <!-- Informazioni Test -->
    <div class="test-info">
        <strong>Pannello:</strong> <?php echo htmlspecialchars($pannello['nome']); ?><br>
        <strong>Metodica:</strong> Test <?php echo $test_type_label; ?><br>
        <?php if ($pannello['descrizione']): ?>
            <small><?php echo htmlspecialchars($pannello['descrizione']); ?></small>
        <?php endif; ?>
    </div>

    <!-- Riepilogo -->
    <?php
    $intolleranze_gravi = [];
    $intolleranze_medie = [];
    $intolleranze_lievi = [];
    
    foreach ($risultati as $risultato) {
        if ($risultato['grado'] == 3) {
            $intolleranze_gravi[] = $risultato['alimento_nome'];
        } elseif ($risultato['grado'] == 2) {
            $intolleranze_medie[] = $risultato['alimento_nome'];
        } elseif ($risultato['grado'] == 1) {
            $intolleranze_lievi[] = $risultato['alimento_nome'];
        }
    }
    ?>
    
    <?php if (count($intolleranze_gravi) > 0 || count($intolleranze_medie) > 0 || count($intolleranze_lievi) > 0): ?>
        <div class="summary-box">
            <h4>Riepilogo Intolleranze Rilevate</h4>
            
            <?php if (count($intolleranze_gravi) > 0): ?>
                <p><strong>Intolleranze Gravi (Grado 3):</strong></p>
                <ul class="intolerance-list">
                    <?php foreach ($intolleranze_gravi as $alimento): ?>
                        <li><?php echo htmlspecialchars($alimento); ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
            
            <?php if (count($intolleranze_medie) > 0): ?>
                <p><strong>Intolleranze Medie (Grado 2):</strong></p>
                <ul class="intolerance-list">
                    <?php foreach ($intolleranze_medie as $alimento): ?>
                        <li><?php echo htmlspecialchars($alimento); ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
            
            <?php if (count($intolleranze_lievi) > 0): ?>
                <p><strong>Intolleranze Lievi (Grado 1):</strong></p>
                <ul class="intolerance-list">
                    <?php foreach ($intolleranze_lievi as $alimento): ?>
                        <li><?php echo htmlspecialchars($alimento); ?></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <!-- Risultati Dettagliati -->
    <div class="results-section">
        <h2 class="section-title">RISULTATI DETTAGLIATI</h2>
        
        <table class="results-table">
            <thead>
                <tr>
                    <th style="width: 40%;">Alimento</th>
                    <th style="width: 15%;">Grado</th>
                    <?php if ($is_elisa): ?>
                        <th style="width: 15%;">Valore</th>
                    <?php endif; ?>
                    <th style="width: <?php echo $is_elisa ? '30%' : '45%'; ?>">Interpretazione</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($risultati as $risultato): ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars($risultato['alimento_nome']); ?></strong></td>
                        <td>
                            <span class="grade-<?php echo $risultato['grado']; ?>">
                                Grado <?php echo $risultato['grado']; ?>
                            </span>
                        </td>
                        <?php if ($is_elisa): ?>
                            <td><?php echo number_format($risultato['valore_numerico'], 1); ?></td>
                        <?php endif; ?>
                        <td>
                            <?php
                            // Seleziona la descrizione appropriata basata sul grado
                            $desc_field = 'descrizione_grado_' . $risultato['grado'];
                            echo htmlspecialchars($risultato[$desc_field] ?? 'Non disponibile');
                            ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- Legenda -->
    <div class="legend">
        <div class="legend-title">Legenda Gradi di Intolleranza:</div>
        <div class="legend-item">
            <span class="grade-0">Grado 0</span> = Negativo (nessuna intolleranza)
        </div>
        <div class="legend-item">
            <span class="grade-1">Grado 1</span> = Intolleranza lieve
        </div>
        <div class="legend-item">
            <span class="grade-2">Grado 2</span> = Intolleranza media
        </div>
        <div class="legend-item">
            <span class="grade-3">Grado 3</span> = Intolleranza grave
        </div>
        
        <?php if ($is_elisa): ?>
            <div style="margin-top: 10px; font-size: 10pt;">
                <strong>Valori di riferimento ELISA:</strong> 0-10 (Grado 0), 11-20 (Grado 1), 21-30 (Grado 2), 31-100 (Grado 3)
            </div>
        <?php endif; ?>
    </div>

    <!-- Informazioni Metodica -->
    <div class="method-info">
        <strong>Metodica utilizzata:</strong> 
        <?php if ($is_elisa): ?>
            Test ELISA (Enzyme-Linked Immunosorbent Assay) per la rilevazione di IgG specifiche verso antigeni alimentari.
        <?php else: ?>
            Test citotossico per la valutazione della reazione leucocitaria in presenza di estratti alimentari.
        <?php endif; ?>
    </div>

    <!-- Note generali -->
    <?php if (!empty($test_data['note'])): ?>
        <div style="margin-top: 20px;">
            <h3 style="color: #ff9800;">Note del Laboratorio</h3>
            <p><?php echo nl2br(htmlspecialchars($test_data['note'])); ?></p>
        </div>
    <?php endif; ?>

    <!-- Avvertenze -->
    <div class="privacy-notice" style="background-color: #fff9c4; border-color: #f9a825;">
        <strong>Avvertenze:</strong> Le intolleranze alimentari rilevate sono di tipo transitorio e possono variare nel tempo. 
        Si consiglia di eliminare temporaneamente gli alimenti con grado 2 e 3, procedere con una dieta di rotazione 
        per gli alimenti di grado 1, e ripetere il test dopo 3-6 mesi. È importante consultare il proprio medico 
        o un nutrizionista prima di apportare modifiche significative alla dieta.
    </div>

    <!-- Firma -->
    <div class="signature-section">
        <div class="signature-box">
            <?php if ($biologo['firma_immagine'] && file_exists($biologo['firma_immagine'])): ?>
                <img src="<?php echo $biologo['firma_immagine']; ?>" alt="Firma" class="signature-image">
            <?php endif; ?>
            <div>
                <strong>Dr. <?php echo htmlspecialchars($biologo['cognome'] . ' ' . $biologo['nome']); ?></strong><br>
                <?php if ($biologo['firma_descrizione']): ?>
                    <?php echo htmlspecialchars($biologo['firma_descrizione']); ?><br>
                <?php endif; ?>
                Biologo responsabile
            </div>
        </div>
    </div>

    <!-- Privacy Notice -->
    <div class="privacy-notice">
        <strong>Informativa Privacy:</strong> I dati contenuti in questo referto sono trattati in conformità al Regolamento UE 2016/679 (GDPR) 
        e al D.Lgs. 196/2003. Il referto è strettamente confidenziale e destinato esclusivamente al paziente e al medico curante. 
        È vietata la divulgazione a terzi senza autorizzazione.
    </div>

    <!-- Hash per verifica -->
    <div class="hash-info">
        <strong>Hash SHA-256 per verifica autenticità:</strong><br>
        <?php echo $file_hash ?? 'Verrà generato dopo la creazione del PDF'; ?>
    </div>

    <!-- Footer -->
    <?php if ($footer_text): ?>
        <div class="footer">
            <?php echo $footer_text; ?>
        </div>
    <?php endif; ?>
</body>
</html>