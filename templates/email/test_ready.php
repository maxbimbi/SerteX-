<?php
/**
 * Template Email Test Pronto
 * Variabili disponibili: $paziente, $test, $download_url, $scadenza_giorni
 */
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body {
            font-family: Arial, sans-serif;
            line-height: 1.6;
            color: #333;
            background-color: #f4f4f4;
            margin: 0;
            padding: 0;
        }
        .container {
            max-width: 600px;
            margin: 20px auto;
            background-color: #ffffff;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        .header {
            background-color: #4caf50;
            color: white;
            padding: 30px;
            text-align: center;
        }
        .header h1 {
            margin: 0;
            font-size: 28px;
        }
        .content {
            padding: 30px;
        }
        .button {
            display: inline-block;
            padding: 15px 40px;
            background-color: #4caf50;
            color: white;
            text-decoration: none;
            border-radius: 5px;
            margin: 20px 0;
            font-size: 18px;
            font-weight: bold;
        }
        .button:hover {
            background-color: #45a049;
        }
        .info-box {
            background-color: #e8f5e9;
            border: 1px solid #4caf50;
            padding: 20px;
            border-radius: 5px;
            margin: 20px 0;
        }
        .test-details {
            background-color: #f8f9fa;
            padding: 20px;
            border-radius: 5px;
            margin: 20px 0;
        }
        .test-details table {
            width: 100%;
            border-collapse: collapse;
        }
        .test-details td {
            padding: 8px 0;
            border-bottom: 1px solid #e0e0e0;
        }
        .test-details td:first-child {
            font-weight: bold;
            color: #555;
            width: 40%;
        }
        .instructions {
            background-color: #e3f2fd;
            border: 1px solid #1976d2;
            padding: 20px;
            border-radius: 5px;
            margin: 20px 0;
        }
        .warning {
            background-color: #fff3cd;
            border: 1px solid #ffeaa7;
            padding: 15px;
            border-radius: 5px;
            margin: 20px 0;
            color: #856404;
        }
        .footer {
            background-color: #f8f9fa;
            padding: 20px;
            text-align: center;
            font-size: 14px;
            color: #666;
        }
        .icon {
            display: inline-block;
            width: 60px;
            height: 60px;
            background-color: white;
            border-radius: 50%;
            margin-bottom: 20px;
            line-height: 60px;
            font-size: 30px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="icon">✓</div>
            <h1>Il Tuo Referto è Pronto!</h1>
        </div>
        
        <div class="content">
            <p>Gentile <?php echo htmlspecialchars($paziente['nome'] . ' ' . $paziente['cognome']); ?>,</p>
            
            <p>Siamo lieti di informarti che il referto delle tue analisi è ora disponibile per il download.</p>
            
            <div class="info-box">
                <h3 style="margin-top: 0; color: #2e7d32;">📋 Referto Disponibile</h3>
                <p>Il tuo referto è stato completato e validato dal nostro team di biologi.</p>
            </div>
            
            <div class="test-details">
                <h3>Dettagli del Test:</h3>
                <table>
                    <tr>
                        <td>Codice Test:</td>
                        <td><strong><?php echo htmlspecialchars($test['codice']); ?></strong></td>
                    </tr>
                    <tr>
                        <td>Tipo di Analisi:</td>
                        <td><?php 
                            $tipi = [
                                'genetico' => 'Test Genetico',
                                'microbiota' => 'Analisi Microbiota',
                                'intolleranze_cito' => 'Intolleranze Alimentari (Citotossico)',
                                'intolleranze_elisa' => 'Intolleranze Alimentari (ELISA)'
                            ];
                            echo $tipi[$test['tipo_test']] ?? $test['tipo_test'];
                        ?></td>
                    </tr>
                    <tr>
                        <td>Data Refertazione:</td>
                        <td><?php echo date('d/m/Y', strtotime($test['data_refertazione'])); ?></td>
                    </tr>
                </table>
            </div>
            
            <div style="text-align: center; margin: 30px 0;">
                <a href="<?php echo $download_url; ?>" class="button">
                    📥 Scarica il Referto
                </a>
            </div>
            
            <div class="instructions">
                <h3 style="margin-top: 0; color: #1565c0;">📌 Come scaricare il referto:</h3>
                <ol>
                    <li>Clicca sul pulsante "Scarica il Referto" qui sopra</li>
                    <li>Inserisci il <strong>codice del test</strong> e il tuo <strong>codice fiscale</strong></li>
                    <li>Il referto verrà scaricato in formato PDF</li>
                    <li>Per aprire il PDF, utilizza il tuo <strong>codice fiscale in MAIUSCOLO</strong> come password</li>
                </ol>
            </div>
            
            <div class="warning">
                <strong>⏰ Importante:</strong> Il referto sarà disponibile per il download per <?php echo $scadenza_giorni; ?> giorni 
                dalla data di emissione. Dopo tale periodo, dovrai contattare il laboratorio per richiederne una copia.
            </div>
            
            <h3>Cosa fare ora?</h3>
            <ul>
                <li>Scarica e salva il referto in un luogo sicuro</li>
                <li>Condividi il referto con il tuo medico curante</li>
                <li>Per domande sui risultati, contatta il tuo medico di riferimento</li>
            </ul>
            
            <p>Se riscontri problemi nel download o nell'apertura del referto, non esitare a contattarci:</p>
            <ul>
                <li>📧 Email: supporto@sertexplus.it</li>
                <li>📞 Telefono: 0584 123456</li>
                <li>🕐 Orari: Lun-Ven 9:00-18:00</li>
            </ul>
            
            <p>Grazie per aver scelto SerteX+ per le tue analisi.</p>
            
            <p>Cordiali saluti,<br>
            <strong>Il Team SerteX+</strong></p>
        </div>
        
        <div class="footer">
            <p><strong>Nota sulla Privacy:</strong> Questo messaggio contiene informazioni riservate. 
            Se non sei il destinatario, ti preghiamo di eliminare questa email.</p>
            <p>© <?php echo date('Y'); ?> SerteX+ - Laboratorio di Analisi Genetiche</p>
            <p style="font-size: 12px;">
                Questa email è stata inviata a <?php echo htmlspecialchars($paziente['email']); ?> 
                perché associata al test <?php echo htmlspecialchars($test['codice']); ?>
            </p>
        </div>
    </div>
</body>
</html>