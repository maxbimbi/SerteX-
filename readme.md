# SerteX+ - Sistema Gestionale per Laboratori di Analisi Genetiche

## 📋 Descrizione

SerteX+ è un portale web completo per la gestione di laboratori di analisi genetiche, sviluppato in PHP e MySQL. Il sistema gestisce quattro tipologie principali di analisi:

- **Test Genetici**: Analisi di singoli geni o pannelli
- **Analisi Microbiota**: Test del microbiota intestinale, vaginale, oculare, etc.
- **Intolleranze Alimentari Citotossiche**: Test leucocitotossico
- **Intolleranze Alimentari ELISA**: Test con metodica ELISA

## 🚀 Caratteristiche Principali

### Gestione Multi-Ruolo
- **Amministratore**: Gestione completa del sistema, utenti, configurazioni
- **Biologo**: Inserimento risultati, refertazione, firma digitale
- **Professionista**: Gestione pazienti, richiesta test, download referti
- **Commerciale**: Fatturazione, statistiche, gestione economica

### Funzionalità Chiave
- ✅ Sistema di autenticazione sicuro con 2FA opzionale
- ✅ Gestione completa test genetici (geni singoli e pannelli)
- ✅ Upload e gestione referti PDF
- ✅ Download protetto referti (password = Codice Fiscale)
- ✅ Verifica autenticità referti con hash SHA-256
- ✅ Sistema di backup automatico (locale e cloud)
- ✅ Fatturazione elettronica XML
- ✅ Gestione listini prezzi personalizzati
- ✅ Sistema di notifiche email
- ✅ Log completo attività
- ✅ Interfaccia responsive
- ✅ Conformità GDPR e normative privacy

## 📁 Struttura del Progetto

```
sertexplus/
├── admin/                 # Area amministratore
├── api/                   # Endpoint API REST
├── assets/               # CSS, JS, immagini
├── backup/               # Directory backup
├── biologo/              # Area biologo
├── classes/              # Classi PHP
├── commerciale/          # Area commerciale
├── config/               # File configurazione
├── cron/                 # Script automatici
├── error/                # Pagine errore
├── includes/             # File inclusione
├── install/              # Sistema installazione
├── logs/                 # File di log
├── professionista/       # Area professionista
├── public/               # Area pubblica
├── templates/            # Template HTML/PDF
├── uploads/              # File caricati
├── vendor/               # Dipendenze Composer
├── .htaccess            # Configurazione Apache
├── composer.json         # Dipendenze PHP
├── index.php            # Entry point
├── install.php          # Installer
└── README.md            # Documentazione
```

## 🛠️ Requisiti di Sistema

### Server
- PHP 7.4 o superiore
- MySQL 5.7 o superiore / MariaDB 10.3+
- Apache 2.4+ con mod_rewrite
- Estensioni PHP richieste:
  - PDO e PDO_MySQL
  - GD o ImageMagick
  - OpenSSL
  - Zip
  - FileInfo
  - MBString

### Librerie PHP (via Composer)
- dompdf/dompdf: Generazione PDF
- phpmailer/phpmailer: Invio email
- firebase/php-jwt: Token JWT
- setasign/fpdi: Manipolazione PDF

## 📥 Installazione

1. **Clona o scarica il repository**
   ```bash
   git clone https://github.com/yourusername/sertexplus.git
   cd sertexplus
   ```

2. **Installa le dipendenze**
   ```bash
   composer install
   ```

3. **Configura il web server**
   - Punta il DocumentRoot alla directory del progetto
   - Assicurati che mod_rewrite sia abilitato

4. **Imposta i permessi**
   ```bash
   chmod 755 uploads/ backup/ logs/ temp/
   ```

5. **Avvia l'installer**
   - Naviga su `http://tuodominio.com/install.php`
   - Segui la procedura guidata
   - L'installer creerà il database e il file di configurazione

6. **Rimuovi l'installer**
   ```bash
   rm install.php
   ```

## ⚙️ Configurazione

### Backup Automatici
Aggiungi al crontab per backup giornalieri:
```bash
0 2 * * * /usr/bin/php /path/to/sertexplus/cron/backup.php >> /path/to/logs/cron_backup.log 2>&1
```

### Email
Configura i parametri SMTP nel pannello amministratore o in `config/config.php`:
```php
define('MAIL_HOST', 'smtp.tuoserver.com');
define('MAIL_USERNAME', 'noreply@tuodominio.com');
define('MAIL_PASSWORD', 'password');
```

### Fatturazione Elettronica
Inserisci i dati aziendali nel pannello amministratore per generare XML validi.

## 🔐 Sicurezza

- Password hash con bcrypt
- Protezione CSRF su tutti i form
- Prepared statements per prevenire SQL injection
- Validazione input lato server
- Sessioni sicure con rigenerazione ID
- Limite tentativi di login
- Log di tutte le attività sensibili
- Backup automatici criptati

## 📊 Workflow Tipico

1. **Professionista**
   - Registra nuovo paziente
   - Crea richiesta test
   - Seleziona analisi da eseguire
   - Conferma e invia al laboratorio

2. **Biologo**
   - Visualizza test in attesa
   - Inserisce risultati analisi
   - Genera referto PDF
   - Scarica per firma digitale
   - Ricarica referto firmato

3. **Paziente**
   - Riceve email con link download
   - Inserisce codice test e CF
   - Scarica referto (password = CF)

4. **Commerciale**
   - Genera fatture singole o mensili
   - Esporta XML fattura elettronica
   - Visualizza statistiche e report

## 🐛 Troubleshooting

### Errore 500
- Controlla i log PHP in `logs/php_errors.log`
- Verifica permessi directory
- Controlla configurazione database

### Upload file non funziona
- Verifica `upload_max_filesize` in php.ini
- Controlla permessi directory uploads/
- Verifica spazio disco disponibile

### PDF non si generano
- Assicurati che dompdf sia installato via Composer
- Controlla i log per errori di memoria
- Aumenta `memory_limit` se necessario

## 📝 License

Questo progetto è proprietario e confidenziale. Tutti i diritti riservati.

## 👥 Supporto

Per assistenza tecnica:
- Email: support@sertexplus.it
- Documentazione: docs.sertexplus.it

## 🔄 Changelog

### v1.0.0 (2024-01)
- Release iniziale
- Gestione completa test genetici
- Sistema refertazione
- Fatturazione base

---

**SerteX+** - Eccellenza nelle analisi genetiche