<?php
/**
 * Template Email di Benvenuto
 * Variabili disponibili: $user, $password_temp, $login_url
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
            background-color: #1976d2;
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
            background-color: #1976d2;
            color: white;
            text-decoration: none;
            border-radius: 5px;
            margin: 20px 0;
        }
        .credentials {
            background-color: #f8f9fa;
            padding: 20px;
            border-radius: 5px;
            margin: 20px 0;
        }
        .credentials p {
            margin: 5px 0;
        }
        .footer {
            background-color: #f8f9fa;
            padding: 20px;
            text-align: center;
            font-size: 14px;
            color: #666;
        }
        .warning {
            background-color: #fff3cd;
            border: 1px solid #ffeaa7;
            padding: 15px;
            border-radius: 5px;
            margin: 20px 0;
            color: #856404;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Benvenuto in SerteX+</h1>
        </div>
        
        <div class="content">
            <p>Gentile <?php echo htmlspecialchars($user['nome'] . ' ' . $user['cognome']); ?>,</p>
            
            <p>Il tuo account è stato creato con successo nel portale SerteX+.</p>
            
            <div class="credentials">
                <p><strong>Le tue credenziali di accesso sono:</strong></p>
                <p>Username: <code><?php echo htmlspecialchars($user['username']); ?></code></p>
                <p>Password temporanea: <code><?php echo htmlspecialchars($password_temp); ?></code></p>
            </div>
            
            <div class="warning">
                <strong>Importante:</strong> Al primo accesso ti verrà richiesto di cambiare la password temporanea con una personale.
            </div>
            
            <p>Puoi accedere al portale cliccando sul pulsante qui sotto:</p>
            
            <div style="text-align: center;">
                <a href="<?php echo $login_url; ?>" class="button">Accedi al Portale</a>
            </div>
            
            <p><strong>Tipo account:</strong> <?php echo ucfirst($user['tipo_utente']); ?></p>
            
            <h3>Informazioni importanti:</h3>
            <ul>
                <li>La password deve essere cambiata ogni 90 giorni</li>
                <li>Dopo 5 tentativi di accesso falliti, l'account verrà bloccato</li>
                <li>Per sicurezza, consigliamo di attivare l'autenticazione a due fattori</li>
            </ul>
            
            <p>Per qualsiasi problema di accesso o domanda, non esitare a contattare il supporto tecnico.</p>
            
            <p>Cordiali saluti,<br>
            Il Team SerteX+</p>
        </div>
        
        <div class="footer">
            <p>Questa email è stata inviata automaticamente. Non rispondere a questo messaggio.</p>
            <p>© <?php echo date('Y'); ?> SerteX+ - Tutti i diritti riservati</p>
        </div>
    </div>
</body>
</html>