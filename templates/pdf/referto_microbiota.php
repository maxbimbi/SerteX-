<?php
/**
 * Template PDF per Referto Microbiota
 * Questo file genera l'HTML che verrà convertito in PDF
 */

// Verifica che le variabili necessarie siano definite
if (!isset($test_data) || !isset($paziente) || !isset($biologo) || !isset($tipo_microbiota)) {
    die('Dati mancanti per la generazione del referto');
}

// Recupera configurazioni
$nome_laboratorio = $config['nome_laboratorio'] ?? 'SerteX+';
$logo_path = $config['logo_path'] ?? '';
$header_text = $template['header'] ?? '';
$footer_text = $template['footer'] ?? '';
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
            border-bottom: 2px solid #4caf50;
            padding-bottom: 20px;
        }
        
        .logo {
            max-height: 80px;
            margin-bottom: 10px;
        }
        
        .lab-name {
            font-size: 24pt;
            font-weight: bold;
            color: #4caf50;
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
        
        .analysis-type {
            background-color: #e8f5e9;
            border-left: 4px solid #4caf50;
            padding: 15px;
            margin: 20px 0;
            font-size: 14pt;
        }
        
        .content-section {
            margin-top: 30px;
            page-break-before: auto;
        }
        
        .section-title {
            font-size: 14pt;
            font-weight: bold;
            color: #4caf50;
            margin-bottom: 15px;
            border-bottom: 1px solid #ddd;
            padding-bottom: 5px;
        }
        
        .notice-box {
            background-color: #fff9c4;
            border: 1px solid #f9a825;
            border-radius: 5px;
            padding: 15px;
            margin: 20px 0;
        }
        
        .notice-box strong {
            color: #f57c00;
        }
        
        .attachment-notice {
            background-color: #e3f2fd;
            border: 2px solid #1976d2;
            border-radius: 5px;
            padding: 20px;
            margin: 30px 0;
            text-align: center;
            font-size: 12pt;
        }
        
        .attachment-notice h3 {
            color: #1976d2;
            margin-top: 0;
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
        
        .method-info {
            background-color: #f5f5f5;
            padding: 15px;
            border-radius: 5px;
            margin: 20px 0;
        }
        
        .method-info h4 {
            margin-top: 0;
            color: #555;
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

    <h1 class="report-title">REFERTO ANALISI MICROBIOTA</h1>

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

    <!-- Tipo di Analisi -->
    <div class="analysis-type">
        <strong>Tipo di Analisi:</strong> <?php echo htmlspecialchars($tipo_microbiota['nome']); ?>
        <?php if ($tipo_microbiota['descrizione']): ?>
            <br><small><?php echo htmlspecialchars($tipo_microbiota['descrizione']); ?></small>
        <?php endif; ?>
    </div>

    <!-- Metodologia -->
    <div class="method-info">
        <h4>Metodologia di Analisi</h4>
        <p>L'analisi del microbiota è stata eseguita mediante sequenziamento di nuova generazione (NGS) 
        del gene 16S rRNA batterico. La metodica permette l'identificazione e la quantificazione 
        delle diverse specie batteriche presenti nel campione.</p>
    </div>

    <!-- Avviso Allegato -->
    <div class="attachment-notice">
        <h3>RISULTATI DELL'ANALISI</h3>
        <p><strong>I risultati dettagliati dell'analisi del microbiota sono disponibili 
        nel documento allegato al presente referto.</strong></p>
        <p>Il documento allegato contiene:</p>
        <ul style="text-align: left; display: inline-block;">
            <li>Composizione dettagliata del microbiota</li>
            <li>Grafici di distribuzione delle specie batteriche</li>
            <li>Indici di diversità microbica</li>
            <li>Confronto con valori di riferimento</li>
            <li>Interpretazione clinica dei risultati</li>
            <li>Eventuali raccomandazioni personalizzate</li>
        </ul>
    </div>

    <!-- Note importanti -->
    <div class="notice-box">
        <strong>Note Importanti:</strong>
        <ul>
            <li>I risultati devono essere interpretati dal medico curante nel contesto clinico del paziente</li>
            <li>L'analisi del microbiota rappresenta una fotografia della composizione batterica al momento del prelievo</li>
            <li>Fattori come dieta, farmaci e stile di vita possono influenzare la composizione del microbiota</li>
        </ul>
    </div>

    <!-- Note generali -->
    <?php if (!empty($test_data['note'])): ?>
        <div class="content-section">
            <h3 class="section-title">Note del Laboratorio</h3>
            <p><?php echo nl2br(htmlspecialchars($test_data['note'])); ?></p>
        </div>
    <?php endif; ?>

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