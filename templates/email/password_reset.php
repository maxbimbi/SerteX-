<?php
/**
 * Template Email Reset Password
 * Variabili disponibili: $user, $reset_token, $reset_url, $expiry_time
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
            background-color: #dc004e;
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
            padding: 12px 30px;
            background-color: #dc004e;
            color: white;
            text-decoration: none;
            border-radius: 5px;
            margin: 20px 0;
        }
        .button:hover {
            background-color: #b91c3c;
        }
        .security-info {
            background-color: #e3f2fd;
            border: 1px solid #1976d2;
            padding: 15px;
            border-radius: 5px;
            margin: 20px 0;
            color: #0d47a1;
        }
        .footer {
            background-color: #f8f9fa;
            padding: 20px;
            text-align: center;
            font-size: 14px;
            color: #666;
        }
        .warning {
            background-color: #ffebee;
            border: 1px solid #ef5350;
            padding: 15px;
            border-radius: 5px;
            margin: 20px 0;
            color: #c62828;
        }
        .code-box {
            background-color: #f5f5f5;
            padding: 15px;
            border-radius: 5px;
            text-align: center;
            margin: 20px 0;
        }
        .code {
            font-size: 24px;
            font-weight: bold;
            letter-spacing: 2px;
            color: #1976d2;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Reset Password - SerteX+</h1>
        </div>
        
        <div class="content">
            <p>Gentile <?php echo htmlspecialchars($user['nome'] . ' ' . $user['cognome']); ?>,</p>
            
            <p>Abbiamo ricevuto una richiesta di reset password per il tuo account SerteX+.</p>
            
            <div class="warning">
                <strong>⚠️ Attenzione:</strong> Se non hai richiesto tu il reset della password, ignora questa email e contatta immediatamente l'amministratore di sistema.
            </div>
            
            <p>Per procedere con il reset della password, clicca sul pulsante qui sotto:</p>
            
            <div style="text-align: center;">
                <a href="<?php echo $reset_url; ?>" class="button">Reset Password</a>
            </div>
            
            <p>Oppure copia e incolla questo link nel tuo browser:</p>
            <p style="word-break: break-all; background-color: #f5f5f5; padding: 10px; border-radius: 5px;">
                <?php echo $reset_url; ?>
            </p>
            
            <?php if (isset($reset_token)): ?>
            <div class="code-box">
                <p>Il tuo codice di verifica è:</p>
                <p class="code"><?php echo $reset_token; ?></p>
            </div>
            <?php endif; ?>
            
            <div class="security-info">
                <strong>📌 Informazioni di sicurezza:</strong>
                <ul style="margin: 10px 0; padding-left: 20px;">
                    <li>Questo link scadrà tra <?php echo $expiry_time; ?> ore</li>
                    <li>Il link può essere utilizzato una sola volta</li>
                    <li>La nuova password deve essere diversa dalle ultime 5 utilizzate</li>
                    <li>La password deve contenere almeno 8 caratteri, includendo maiuscole, minuscole, numeri e simboli</li>
                </ul>
            </div>
            
            <h3>Cosa fare dopo il reset:</h3>
            <ol>
                <li>Accedi con la nuova password</li>
                <li>Verifica le impostazioni di sicurezza del tuo account</li>
                <li>Considera l'attivazione dell'autenticazione a due fattori</li>
            </ol>
            
            <p>Per la tua sicurezza, questa richiesta è stata registrata con i seguenti dettagli:</p>
            <ul style="font-size: 14px; color: #666;">
                <li>Data e ora: <?php echo date('d/m/Y H:i:s'); ?></li>
                <li>IP richiedente: <?php echo $_SERVER['REMOTE_ADDR'] ?? 'Non disponibile'; ?></li>
            </ul>
            
            <p>Se hai bisogno di assistenza, contatta il supporto tecnico.</p>
            
            <p>Cordiali saluti,<br>
            Il Team di Sicurezza SerteX+</p>
        </div>
        
        <div class="footer">
            <p>Questa email è stata inviata automaticamente. Non rispondere a questo messaggio.</p>
            <p>Per segnalare attività sospette, contatta: security@sertexplus.it</p>
            <p>© <?php echo date('Y'); ?> SerteX+ - Tutti i diritti riservati</p>
        </div>
    </div>
</body>
</html>