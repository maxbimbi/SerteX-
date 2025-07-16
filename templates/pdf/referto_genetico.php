<?php
/**
 * Template PDF per Referto Genetico
 * Questo file genera l'HTML che verrà convertito in PDF
 */

// Verifica che le variabili necessarie siano definite
if (!isset($test_data) || !isset($paziente) || !isset($risultati) || !isset($biologo)) {
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
            border-bottom: 2px solid #1976d2;
            padding-bottom: 20px;
        }
        
        .logo {
            max-height: 80px;
            margin-bottom: 10px;
        }
        
        .lab-name {
            font-size: 24pt;
            font-weight: bold;
            color: #1976d2;
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
        
        .results-section {
            margin-top: 30px;
        }
        
        .section-title {
            font-size: 14pt;
            font-weight: bold;
            color: #1976d2;
            margin-bottom: 15px;
            border-bottom: 1px solid #ddd;
            padding-bottom: 5px;
        }
        
        .gene-group {
            margin-bottom: 25px;
            page-break-inside: avoid;
        }
        
        .group-title {
            font-size: 12pt;
            font-weight: bold;
            color: #333;
            margin-bottom: 10px;
            background-color: #e3f2fd;
            padding: 8px;
            border-left: 3px solid #1976d2;
        }
        
        .gene-result {
            margin-bottom: 15px;
            padding: 10px;
            background-color: #fff;
            border: 1px solid #e0e0e0;
            border-radius: 3px;
        }
        
        .gene-name {
            font-weight: bold;
            color: #555;
            margin-bottom: 5px;
        }
        
        .gene-sigla {
            color: #888;
            font-size: 10pt;
        }
        
        .result-value {
            font-size: 12pt;
            padding: 5px 10px;
            border-radius: 3px;
            display: inline-block;
            margin: 5px 0;
        }
        
        .result-positive {
            background-color: #ffebee;
            color: #c62828;
            border: 1px solid #ef5350;
        }
        
        .result-negative {
            background-color: #e8f5e9;
            color: #2e7d32;
            border: 1px solid #66bb6a;
        }
        
        .result-other {
            background-color: #fff3e0;
            color: #e65100;
            border: 1px solid #ffb74d;
        }
        
        .notes-section {
            margin-top: 30px;
            padding: 15px;
            background-color: #f5f5f5;
            border-radius: 5px;
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
        
        .qr-code {
            float: right;
            margin-top: -50px;
        }
        
        .hash-info {
            font-size: 8pt;
            color: #999;
            margin-top: 20px;
            word-break: break-all;
        }
        
        @media print {
            .gene-group {
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

    <h1 class="report-title">REFERTO ANALISI GENETICHE</h1>

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

    <!-- Risultati -->
    <div class="results-section">
        <h2 class="section-title">RISULTATI DELLE ANALISI</h2>
        
        <?php
        // Raggruppa risultati per gruppo
        $risultati_per_gruppo = [];
        foreach ($risultati as $risultato) {
            $gruppo = $risultato['gruppo_nome'] ?? 'Altri test';
            if (!isset($risultati_per_gruppo[$gruppo])) {
                $risultati_per_gruppo[$gruppo] = [];
            }
            $risultati_per_gruppo[$gruppo][] = $risultato;
        }
        
        // Visualizza risultati per gruppo
        foreach ($risultati_per_gruppo as $gruppo_nome => $gruppo_risultati):
        ?>
            <div class="gene-group">
                <?php if ($gruppo_nome !== 'Altri test'): ?>
                    <div class="group-title"><?php echo htmlspecialchars($gruppo_nome); ?></div>
                <?php endif; ?>
                
                <?php foreach ($gruppo_risultati as $risultato): ?>
                    <div class="gene-result">
                        <div class="gene-name">
                            <?php echo htmlspecialchars($risultato['gene_nome']); ?>
                            <?php if ($risultato['gene_sigla']): ?>
                                <span class="gene-sigla">(<?php echo htmlspecialchars($risultato['gene_sigla']); ?>)</span>
                            <?php endif; ?>
                        </div>
                        
                        <?php if ($risultato['gene_descrizione']): ?>
                            <div style="font-size: 10pt; color: #666; margin-bottom: 5px;">
                                <?php echo htmlspecialchars($risultato['gene_descrizione']); ?>
                            </div>
                        <?php endif; ?>
                        
                        <div>
                            <strong>Risultato:</strong>
                            <?php
                            $result_class = 'result-other';
                            if ($risultato['risultato_tipo'] === 'positivo') {
                                $result_class = 'result-positive';
                            } elseif ($risultato['risultato_tipo'] === 'negativo') {
                                $result_class = 'result-negative';
                            }
                            ?>
                            <span class="result-value <?php echo $result_class; ?>">
                                <?php echo htmlspecialchars($risultato['risultato_nome']); ?>
                            </span>
                        </div>
                        
                        <?php if ($risultato['note']): ?>
                            <div style="margin-top: 5px; font-size: 10pt; color: #666;">
                                <em>Note: <?php echo htmlspecialchars($risultato['note']); ?></em>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- Note generali -->
    <?php if (!empty($test_data['note'])): ?>
        <div class="notes-section">
            <h3 style="margin-top: 0;">Note</h3>
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