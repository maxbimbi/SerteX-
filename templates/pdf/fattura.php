<?php
/**
 * Template PDF per Fattura
 * Questo file genera l'HTML che verrà convertito in PDF
 */

// Verifica che le variabili necessarie siano definite
if (!isset($fattura) || !isset($professionista) || !isset($test_dettagli) || !isset($config)) {
    die('Dati mancanti per la generazione della fattura');
}

// Dati azienda
$nome_laboratorio = $config['nome_laboratorio'] ?? 'SerteX+';
$logo_path = $config['logo_path'] ?? '';
$dati_azienda = [
    'ragione_sociale' => $config['ragione_sociale'] ?? $nome_laboratorio,
    'indirizzo' => $config['indirizzo_azienda'] ?? '',
    'cap' => $config['cap_azienda'] ?? '',
    'citta' => $config['citta_azienda'] ?? '',
    'provincia' => $config['provincia_azienda'] ?? '',
    'partita_iva' => $config['partita_iva_azienda'] ?? '',
    'codice_fiscale' => $config['codice_fiscale_azienda'] ?? '',
    'telefono' => $config['telefono_azienda'] ?? '',
    'email' => $config['email_azienda'] ?? '',
    'pec' => $config['pec_azienda'] ?? '',
    'codice_sdi' => $config['codice_sdi_azienda'] ?? '',
    'iban' => $config['iban_azienda'] ?? ''
];
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <style>
        @page {
            margin: 1.5cm;
        }
        
        body {
            font-family: Arial, sans-serif;
            font-size: 10pt;
            line-height: 1.4;
            color: #333;
        }
        
        .header {
            margin-bottom: 30px;
        }
        
        .header-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .header-table td {
            vertical-align: top;
            padding: 0;
        }
        
        .logo {
            max-height: 60px;
            max-width: 200px;
        }
        
        .company-info {
            text-align: right;
            font-size: 9pt;
        }
        
        .company-name {
            font-size: 14pt;
            font-weight: bold;
            color: #1976d2;
            margin-bottom: 5px;
        }
        
        .invoice-title {
            font-size: 20pt;
            font-weight: bold;
            color: #333;
            margin: 20px 0;
            text-align: center;
        }
        
        .info-section {
            margin-bottom: 20px;
            width: 100%;
        }
        
        .info-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .info-table td {
            padding: 5px;
            vertical-align: top;
        }
        
        .info-box {
            border: 1px solid #ddd;
            padding: 10px;
            background-color: #f9f9f9;
        }
        
        .info-label {
            font-weight: bold;
            color: #555;
        }
        
        .details-table {
            width: 100%;
            border-collapse: collapse;
            margin: 20px 0;
        }
        
        .details-table th {
            background-color: #1976d2;
            color: white;
            padding: 10px;
            text-align: left;
            font-weight: bold;
        }
        
        .details-table td {
            padding: 8px;
            border-bottom: 1px solid #e0e0e0;
        }
        
        .details-table tr:nth-child(even) {
            background-color: #f9f9f9;
        }
        
        .totals-section {
            margin-top: 30px;
            page-break-inside: avoid;
        }
        
        .totals-table {
            width: 40%;
            margin-left: auto;
            border-collapse: collapse;
        }
        
        .totals-table td {
            padding: 5px 10px;
            border-bottom: 1px solid #e0e0e0;
        }
        
        .totals-table .label {
            text-align: right;
            font-weight: bold;
        }
        
        .totals-table .value {
            text-align: right;
        }
        
        .total-row {
            font-size: 12pt;
            font-weight: bold;
            background-color: #f0f0f0;
        }
        
        .payment-info {
            margin-top: 40px;
            padding: 15px;
            background-color: #e3f2fd;
            border: 1px solid #1976d2;
            page-break-inside: avoid;
        }
        
        .payment-info h4 {
            margin-top: 0;
            color: #1976d2;
        }
        
        .footer {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            text-align: center;
            font-size: 8pt;
            color: #666;
            border-top: 1px solid #ddd;
            padding-top: 10px;
        }
        
        .notes {
            margin-top: 20px;
            padding: 10px;
            background-color: #fff9c4;
            border: 1px solid #f9a825;
            font-size: 9pt;
        }
        
        .electronic-invoice {
            margin-top: 20px;
            font-size: 9pt;
            color: #666;
            text-align: center;
            padding: 10px;
            background-color: #f5f5f5;
        }
        
        @media print {
            .payment-info {
                page-break-inside: avoid;
            }
        }
    </style>
</head>
<body>
    <!-- Header -->
    <div class="header">
        <table class="header-table">
            <tr>
                <td style="width: 50%;">
                    <?php if ($logo_path && file_exists($logo_path)): ?>
                        <img src="<?php echo $logo_path; ?>" alt="Logo" class="logo">
                    <?php else: ?>
                        <div class="company-name"><?php echo htmlspecialchars($dati_azienda['ragione_sociale']); ?></div>
                    <?php endif; ?>
                </td>
                <td style="width: 50%;" class="company-info">
                    <strong><?php echo htmlspecialchars($dati_azienda['ragione_sociale']); ?></strong><br>
                    <?php echo htmlspecialchars($dati_azienda['indirizzo']); ?><br>
                    <?php echo htmlspecialchars($dati_azienda['cap'] . ' ' . $dati_azienda['citta'] . ' (' . $dati_azienda['provincia'] . ')'); ?><br>
                    P.IVA: <?php echo htmlspecialchars($dati_azienda['partita_iva']); ?><br>
                    C.F.: <?php echo htmlspecialchars($dati_azienda['codice_fiscale']); ?><br>
                    Tel: <?php echo htmlspecialchars($dati_azienda['telefono']); ?><br>
                    Email: <?php echo htmlspecialchars($dati_azienda['email']); ?>
                </td>
            </tr>
        </table>
    </div>

    <h1 class="invoice-title">FATTURA N. <?php echo htmlspecialchars($fattura['numero']); ?></h1>

    <!-- Info Fattura e Cliente -->
    <table class="info-table">
        <tr>
            <td style="width: 50%; padding-right: 10px;">
                <div class="info-box">
                    <div class="info-label">Data Emissione:</div>
                    <div><?php echo date('d/m/Y', strtotime($fattura['data_emissione'])); ?></div>
                    
                    <?php if ($fattura['modalita_pagamento']): ?>
                        <div class="info-label" style="margin-top: 10px;">Modalità di Pagamento:</div>
                        <div><?php echo htmlspecialchars($fattura['modalita_pagamento']); ?></div>
                    <?php endif; ?>
                    
                    <?php if ($fattura['scadenza_pagamento']): ?>
                        <div class="info-label" style="margin-top: 10px;">Scadenza Pagamento:</div>
                        <div><?php echo date('d/m/Y', strtotime($fattura['scadenza_pagamento'])); ?></div>
                    <?php endif; ?>
                </div>
            </td>
            <td style="width: 50%; padding-left: 10px;">
                <div class="info-box">
                    <div class="info-label">Cliente:</div>
                    <div>
                        <strong><?php echo htmlspecialchars($professionista['ragione_sociale'] ?? $professionista['cognome'] . ' ' . $professionista['nome']); ?></strong><br>
                        <?php if ($professionista['indirizzo']): ?>
                            <?php echo htmlspecialchars($professionista['indirizzo']); ?><br>
                        <?php endif; ?>
                        <?php if ($professionista['partita_iva']): ?>
                            P.IVA: <?php echo htmlspecialchars($professionista['partita_iva']); ?><br>
                        <?php endif; ?>
                        <?php if ($professionista['codice_fiscale']): ?>
                            C.F.: <?php echo htmlspecialchars($professionista['codice_fiscale']); ?><br>
                        <?php endif; ?>
                        <?php if ($professionista['codice_sdi']): ?>
                            Codice SDI: <?php echo htmlspecialchars($professionista['codice_sdi']); ?><br>
                        <?php endif; ?>
                        <?php if ($professionista['pec']): ?>
                            PEC: <?php echo htmlspecialchars($professionista['pec']); ?>
                        <?php endif; ?>
                    </div>
                </div>
            </td>
        </tr>
    </table>

    <!-- Dettagli Fattura -->
    <table class="details-table">
        <thead>
            <tr>
                <th style="width: 10%;">Codice</th>
                <th style="width: 40%;">Descrizione</th>
                <th style="width: 10%;">Quantità</th>
                <th style="width: 15%;">Prezzo Unit.</th>
                <th style="width: 10%;">Sconto</th>
                <th style="width: 15%;">Importo</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($test_dettagli as $dettaglio): ?>
                <tr>
                    <td><?php echo htmlspecialchars($dettaglio['codice_test']); ?></td>
                    <td>
                        <?php echo htmlspecialchars($dettaglio['descrizione']); ?>
                        <?php if ($dettaglio['paziente']): ?>
                            <br><small>Paziente: <?php echo htmlspecialchars($dettaglio['paziente']); ?></small>
                        <?php endif; ?>
                        <?php if ($dettaglio['data_test']): ?>
                            <br><small>Data: <?php echo date('d/m/Y', strtotime($dettaglio['data_test'])); ?></small>
                        <?php endif; ?>
                    </td>
                    <td style="text-align: center;">1</td>
                    <td style="text-align: right;">€ <?php echo number_format($dettaglio['prezzo_unitario'], 2, ',', '.'); ?></td>
                    <td style="text-align: center;">
                        <?php if ($dettaglio['sconto'] > 0): ?>
                            <?php echo number_format($dettaglio['sconto'], 2, ',', '.'); ?>%
                        <?php else: ?>
                            -
                        <?php endif; ?>
                    </td>
                    <td style="text-align: right;">€ <?php echo number_format($dettaglio['importo'], 2, ',', '.'); ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <!-- Totali -->
    <div class="totals-section">
        <table class="totals-table">
            <tr>
                <td class="label">Imponibile:</td>
                <td class="value">€ <?php echo number_format($fattura['importo_totale'], 2, ',', '.'); ?></td>
            </tr>
            <?php if ($fattura['sconto_totale'] > 0): ?>
                <tr>
                    <td class="label">Sconto:</td>
                    <td class="value">- € <?php echo number_format($fattura['sconto_totale'], 2, ',', '.'); ?></td>
                </tr>
            <?php endif; ?>
            <tr>
                <td class="label">IVA (<?php echo number_format($fattura['aliquota_iva'], 0); ?>%):</td>
                <td class="value">€ <?php echo number_format($fattura['iva_totale'], 2, ',', '.'); ?></td>
            </tr>
            <tr class="total-row">
                <td class="label">TOTALE FATTURA:</td>
                <td class="value">€ <?php echo number_format($fattura['importo_totale_ivato'], 2, ',', '.'); ?></td>
            </tr>
        </table>
    </div>

    <!-- Note -->
    <?php if ($fattura['note']): ?>
        <div class="notes">
            <strong>Note:</strong><br>
            <?php echo nl2br(htmlspecialchars($fattura['note'])); ?>
        </div>
    <?php endif; ?>

    <!-- Info Pagamento -->
    <div class="payment-info">
        <h4>Modalità di Pagamento</h4>
        <p>
            <?php if ($dati_azienda['iban']): ?>
                <strong>IBAN:</strong> <?php echo htmlspecialchars($dati_azienda['iban']); ?><br>
            <?php endif; ?>
            <strong>Intestatario:</strong> <?php echo htmlspecialchars($dati_azienda['ragione_sociale']); ?><br>
            <strong>Causale:</strong> Fattura n. <?php echo htmlspecialchars($fattura['numero']); ?>
        </p>
    </div>

    <!-- Info Fattura Elettronica -->
    <div class="electronic-invoice">
        Documento emesso secondo le disposizioni previste dall'articolo 1, commi 209-214, 
        della legge 24 dicembre 2007, n. 244 (Fatturazione Elettronica)
        <?php if ($fattura['progressivo_invio']): ?>
            <br>Progressivo di invio: <?php echo htmlspecialchars($fattura['progressivo_invio']); ?>
        <?php endif; ?>
    </div>

    <!-- Footer -->
    <div class="footer">
        <?php echo htmlspecialchars($dati_azienda['ragione_sociale']); ?> - 
        P.IVA <?php echo htmlspecialchars($dati_azienda['partita_iva']); ?> - 
        Pagina <span class="pagenum"></span>
    </div>
</body>
</html>