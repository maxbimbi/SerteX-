# SerteX+ - Struttura del Progetto

## Struttura delle Directory

```
sertexplus/
├── index.php              FATTO          # Entry point principale
├── install.php            FATTO          # Sistema di installazione
├── install/
│   └── database.sql       FATTO          # Schema database (copia dal primo artifact)
├── config/
│   ├── config.php                        # File di configurazione (generato)
│   └── installed.lock                    # File lock installazione (generato)
├── includes/
│   ├── database.php       FATTO          # Classe gestione database
│   ├── auth.php           FATTO          # Classe autenticazione
│   ├── functions.php      FATTO          # Funzioni utility
│   ├── session.php        FATTO          # Gestione sessioni
│   └── security.php       FATTO          # Funzioni sicurezza
├── classes/
│   ├── User.php           FATTO          # Classe utente
│   ├── Patient.php        FATTO          # Classe paziente
│   ├── Test.php           FATTO          # Classe test
│   ├── Gene.php           FATTO          # Classe gene
│   ├── Panel.php          FATTO          # Classe pannello
│   ├── Report.php         FATTO          # Classe referto
│   ├── Invoice.php        FATTO          # Classe fattura
│   └── Logger.php         FATTO          # Classe logging
├── api/
│   ├── index.php          FATTO          # API REST endpoint
│   └── v1/                               # Versione 1 API
│       ├── auth.php       FATTO
│       ├── users.php      FATTO
│       ├── patients.php   FATTO
│       ├── tests.php      FATTO
│       └── reports.php    FATTO
├── admin/                                # Area amministratore
│   ├── index.php          FATTO
│   ├── dashboard.php      FATTO
│   ├── users.php          FATTO
│   ├── genes.php          FATTO
│   ├── panels.php         FATTO
│   ├── settings.php       FATTO
│   └── backup.php         FATTO
├── biologo/                              # Area biologo
│   ├── index.php          FATTO
│   ├── dashboard.php      FATTO
│   ├── tests.php          FATTO
│   ├── results.php        FATTO
│   └── reports.php        FATTO
├── professionista/                       # Area professionista
│   ├── index.php          FATTO
│   ├── dashboard.php      FATTO
│   ├── patients.php       FATTO
│   ├── tests.php          FATTO
│   └── reports.php        FATTO
├── commerciale/                          # Area commerciale
│   ├── index.php          FATTO
│   ├── dashboard.php      FATTO
│   ├── invoices.php       FATTO
│   ├── statistics.php     FATTO
│   └── orders.php         FATTO
├── public/                               # Area pubblica
│   ├── download.php       FATTO          # Download referti pazienti
│   └── verify.php         FATTO          # Verifica referti
├── assets/
│   ├── css/
│   │   ├── style.css      FATTO
│   │   └── bootstrap.min.css
│   ├── js/
│   │   ├── app.js         FATTO
│   │   ├── jquery.min.js
│   │   └── bootstrap.bundle.min.js
│   ├── images/
│   └── fonts/
├── templates/                            # Template HTML/PDF
│   ├── header.php         FATTO
│   ├── footer.php         FATTO
│   ├── sidebar.php        FATTO
│   ├── pdf/
│   │   ├── referto_genetico.php       FATTO
│   │   ├── referto_microbiota.php     FATTO
│   │   ├── referto_intolleranze.php   FATTO
│   │   └── fattura.php                FATTO
│   └── email/
│       ├── welcome.php                 FATTO
│       ├── password_reset.php          FATTO
│       └── test_ready.php              FATTO
├── uploads/                              # File caricati (protetto)
│   ├── referti/
│   ├── report/
│   ├── fatture/
│   └── firme/
├── backup/                               # Backup (protetto)
│   ├── database/
│   └── files/
├── logs/                                 # Log di sistema (protetto)
├── temp/                                 # File temporanei (protetto)
├── vendor/                               # Dipendenze Composer
├── composer.json          FATTO          # Dipendenze PHP
├── .htaccess              FATTO          # Configurazione Apache
└── README.md                             # Documentazione

