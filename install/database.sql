-- SerteX+ Database Schema
-- Portale per gestione analisi genetiche

-- Tabella utenti
CREATE TABLE IF NOT EXISTS utenti (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    email VARCHAR(100) UNIQUE NOT NULL,
    nome VARCHAR(50) NOT NULL,
    cognome VARCHAR(50) NOT NULL,
    tipo_utente ENUM('amministratore', 'biologo', 'professionista', 'commerciale') NOT NULL,
    attivo BOOLEAN DEFAULT TRUE,
    data_creazione TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    data_ultimo_accesso DATETIME,
    data_cambio_password DATETIME,
    tentativi_falliti INT DEFAULT 0,
    bloccato BOOLEAN DEFAULT FALSE,
    two_factor_enabled BOOLEAN DEFAULT FALSE,
    two_factor_secret VARCHAR(32),
    firma_descrizione TEXT,
    firma_immagine VARCHAR(255),
    INDEX idx_tipo_utente (tipo_utente)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Tabella professionisti (dettagli aggiuntivi)
CREATE TABLE IF NOT EXISTS professionisti (
    id INT AUTO_INCREMENT PRIMARY KEY,
    utente_id INT NOT NULL,
    codice_sdi VARCHAR(7),
    pec VARCHAR(100),
    partita_iva VARCHAR(11),
    codice_fiscale VARCHAR(16),
    indirizzo TEXT,
    telefono VARCHAR(20),
    listino_id INT,
    FOREIGN KEY (utente_id) REFERENCES utenti(id) ON DELETE CASCADE,
    INDEX idx_utente (utente_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Tabella listini prezzi
CREATE TABLE IF NOT EXISTS listini (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(100) NOT NULL,
    descrizione TEXT,
    predefinito BOOLEAN DEFAULT FALSE,
    attivo BOOLEAN DEFAULT TRUE,
    data_creazione TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Tabella geni
CREATE TABLE IF NOT EXISTS geni (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sigla VARCHAR(20),
    nome VARCHAR(100) NOT NULL,
    descrizione TEXT,
    prezzo DECIMAL(10,2) DEFAULT 0,
    gruppo_id INT,
    attivo BOOLEAN DEFAULT TRUE,
    data_creazione TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_gruppo (gruppo_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Tabella gruppi geni
CREATE TABLE IF NOT EXISTS gruppi_geni (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(100) NOT NULL,
    descrizione TEXT,
    ordine INT DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Tabella risultati possibili per geni
CREATE TABLE IF NOT EXISTS risultati_geni (
    id INT AUTO_INCREMENT PRIMARY KEY,
    gene_id INT NOT NULL,
    nome VARCHAR(50) NOT NULL,
    tipo ENUM('positivo', 'negativo', 'altro') NOT NULL,
    descrizione TEXT,
    ordine INT DEFAULT 0,
    FOREIGN KEY (gene_id) REFERENCES geni(id) ON DELETE CASCADE,
    INDEX idx_gene (gene_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Tabella pannelli genetici
CREATE TABLE IF NOT EXISTS pannelli_genetici (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(100) NOT NULL,
    descrizione TEXT,
    prezzo DECIMAL(10,2) DEFAULT 0,
    attivo BOOLEAN DEFAULT TRUE,
    data_creazione TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Tabella relazione pannelli-geni
CREATE TABLE IF NOT EXISTS pannelli_geni (
    pannello_id INT NOT NULL,
    gene_id INT NOT NULL,
    PRIMARY KEY (pannello_id, gene_id),
    FOREIGN KEY (pannello_id) REFERENCES pannelli_genetici(id) ON DELETE CASCADE,
    FOREIGN KEY (gene_id) REFERENCES geni(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Tabella prezzi personalizzati per listino
CREATE TABLE IF NOT EXISTS listini_prezzi (
    id INT AUTO_INCREMENT PRIMARY KEY,
    listino_id INT NOT NULL,
    tipo_elemento ENUM('gene', 'pannello_genetico', 'microbiota', 'intolleranze_cito', 'intolleranze_elisa') NOT NULL,
    elemento_id INT NOT NULL,
    prezzo DECIMAL(10,2) NOT NULL,
    FOREIGN KEY (listino_id) REFERENCES listini(id) ON DELETE CASCADE,
    UNIQUE KEY unique_listino_elemento (listino_id, tipo_elemento, elemento_id),
    INDEX idx_listino (listino_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Tabella tipi microbiota
CREATE TABLE IF NOT EXISTS tipi_microbiota (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(100) NOT NULL,
    descrizione TEXT,
    prezzo DECIMAL(10,2) DEFAULT 0,
    attivo BOOLEAN DEFAULT TRUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Tabella alimenti
CREATE TABLE IF NOT EXISTS alimenti (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(100) NOT NULL,
    descrizione TEXT,
    descrizione_grado_0 TEXT,
    descrizione_grado_1 TEXT,
    descrizione_grado_2 TEXT,
    descrizione_grado_3 TEXT,
    attivo BOOLEAN DEFAULT TRUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Tabella pannelli intolleranze
CREATE TABLE IF NOT EXISTS pannelli_intolleranze (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(100) NOT NULL,
    descrizione TEXT,
    tipo ENUM('citotossico', 'elisa') NOT NULL,
    prezzo DECIMAL(10,2) DEFAULT 0,
    attivo BOOLEAN DEFAULT TRUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Tabella relazione pannelli-alimenti
CREATE TABLE IF NOT EXISTS pannelli_alimenti (
    pannello_id INT NOT NULL,
    alimento_id INT NOT NULL,
    PRIMARY KEY (pannello_id, alimento_id),
    FOREIGN KEY (pannello_id) REFERENCES pannelli_intolleranze(id) ON DELETE CASCADE,
    FOREIGN KEY (alimento_id) REFERENCES alimenti(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Tabella pazienti
CREATE TABLE IF NOT EXISTS pazienti (
    id INT AUTO_INCREMENT PRIMARY KEY,
    professionista_id INT NOT NULL,
    nome VARCHAR(50) NOT NULL,
    cognome VARCHAR(50) NOT NULL,
    codice_fiscale VARCHAR(16) UNIQUE NOT NULL,
    data_nascita DATE,
    sesso ENUM('M', 'F'),
    email VARCHAR(100),
    telefono VARCHAR(20),
    indirizzo TEXT,
    data_creazione TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (professionista_id) REFERENCES professionisti(id),
    INDEX idx_professionista (professionista_id),
    INDEX idx_cf (codice_fiscale)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Tabella test (ordini di analisi)
CREATE TABLE IF NOT EXISTS test (
    id INT AUTO_INCREMENT PRIMARY KEY,
    codice VARCHAR(20) UNIQUE NOT NULL,
    paziente_id INT NOT NULL,
    professionista_id INT NOT NULL,
    tipo_test ENUM('genetico', 'microbiota', 'intolleranze_cito', 'intolleranze_elisa') NOT NULL,
    data_richiesta TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    data_esecuzione DATETIME,
    data_refertazione DATETIME,
    stato ENUM('richiesto', 'in_lavorazione', 'eseguito', 'refertato', 'firmato') DEFAULT 'richiesto',
    prezzo_totale DECIMAL(10,2) DEFAULT 0,
    sconto DECIMAL(5,2) DEFAULT 0,
    prezzo_finale DECIMAL(10,2) DEFAULT 0,
    iva DECIMAL(5,2) DEFAULT 22,
    note TEXT,
    fatturato BOOLEAN DEFAULT FALSE,
    fattura_id INT,
    barcode VARCHAR(255),
    qrcode VARCHAR(255),
    FOREIGN KEY (paziente_id) REFERENCES pazienti(id),
    FOREIGN KEY (professionista_id) REFERENCES professionisti(id),
    INDEX idx_codice (codice),
    INDEX idx_paziente (paziente_id),
    INDEX idx_professionista (professionista_id),
    INDEX idx_stato (stato),
    INDEX idx_data_richiesta (data_richiesta)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Tabella dettagli test genetici
CREATE TABLE IF NOT EXISTS test_genetici_dettagli (
    id INT AUTO_INCREMENT PRIMARY KEY,
    test_id INT NOT NULL,
    tipo_elemento ENUM('gene', 'pannello') NOT NULL,
    elemento_id INT NOT NULL,
    prezzo_unitario DECIMAL(10,2) DEFAULT 0,
    FOREIGN KEY (test_id) REFERENCES test(id) ON DELETE CASCADE,
    INDEX idx_test (test_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Tabella geni aggiuntivi per pannelli
CREATE TABLE IF NOT EXISTS test_genetici_geni_aggiuntivi (
    id INT AUTO_INCREMENT PRIMARY KEY,
    test_dettaglio_id INT NOT NULL,
    gene_id INT NOT NULL,
    prezzo_unitario DECIMAL(10,2) DEFAULT 0,
    FOREIGN KEY (test_dettaglio_id) REFERENCES test_genetici_dettagli(id) ON DELETE CASCADE,
    FOREIGN KEY (gene_id) REFERENCES geni(id),
    INDEX idx_dettaglio (test_dettaglio_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Tabella risultati test genetici
CREATE TABLE IF NOT EXISTS risultati_genetici (
    id INT AUTO_INCREMENT PRIMARY KEY,
    test_id INT NOT NULL,
    gene_id INT NOT NULL,
    risultato_id INT NOT NULL,
    note TEXT,
    FOREIGN KEY (test_id) REFERENCES test(id) ON DELETE CASCADE,
    FOREIGN KEY (gene_id) REFERENCES geni(id),
    FOREIGN KEY (risultato_id) REFERENCES risultati_geni(id),
    UNIQUE KEY unique_test_gene (test_id, gene_id),
    INDEX idx_test (test_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Tabella dettagli test microbiota
CREATE TABLE IF NOT EXISTS test_microbiota_dettagli (
    id INT AUTO_INCREMENT PRIMARY KEY,
    test_id INT NOT NULL,
    tipo_microbiota_id INT NOT NULL,
    prezzo_unitario DECIMAL(10,2) DEFAULT 0,
    FOREIGN KEY (test_id) REFERENCES test(id) ON DELETE CASCADE,
    FOREIGN KEY (tipo_microbiota_id) REFERENCES tipi_microbiota(id),
    INDEX idx_test (test_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Tabella dettagli test intolleranze
CREATE TABLE IF NOT EXISTS test_intolleranze_dettagli (
    id INT AUTO_INCREMENT PRIMARY KEY,
    test_id INT NOT NULL,
    pannello_id INT NOT NULL,
    prezzo_unitario DECIMAL(10,2) DEFAULT 0,
    FOREIGN KEY (test_id) REFERENCES test(id) ON DELETE CASCADE,
    FOREIGN KEY (pannello_id) REFERENCES pannelli_intolleranze(id),
    INDEX idx_test (test_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Tabella risultati intolleranze
CREATE TABLE IF NOT EXISTS risultati_intolleranze (
    id INT AUTO_INCREMENT PRIMARY KEY,
    test_id INT NOT NULL,
    alimento_id INT NOT NULL,
    grado INT NOT NULL CHECK (grado >= 0 AND grado <= 3),
    valore_numerico DECIMAL(5,2),
    FOREIGN KEY (test_id) REFERENCES test(id) ON DELETE CASCADE,
    FOREIGN KEY (alimento_id) REFERENCES alimenti(id),
    UNIQUE KEY unique_test_alimento (test_id, alimento_id),
    INDEX idx_test (test_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Tabella referti
CREATE TABLE IF NOT EXISTS referti (
    id INT AUTO_INCREMENT PRIMARY KEY,
    test_id INT NOT NULL,
    tipo_referto ENUM('genetico', 'microbiota', 'intolleranze') NOT NULL,
    file_path VARCHAR(255) NOT NULL,
    file_path_firmato VARCHAR(255),
    data_creazione TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    data_firma DATETIME,
    biologo_id INT NOT NULL,
    hash_file VARCHAR(64),
    FOREIGN KEY (test_id) REFERENCES test(id) ON DELETE CASCADE,
    FOREIGN KEY (biologo_id) REFERENCES utenti(id),
    UNIQUE KEY unique_test (test_id),
    INDEX idx_test (test_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Tabella fatture
CREATE TABLE IF NOT EXISTS fatture (
    id INT AUTO_INCREMENT PRIMARY KEY,
    numero VARCHAR(20) UNIQUE NOT NULL,
    data_emissione DATE NOT NULL,
    professionista_id INT NOT NULL,
    importo_totale DECIMAL(10,2) NOT NULL,
    iva_totale DECIMAL(10,2) NOT NULL,
    importo_totale_ivato DECIMAL(10,2) NOT NULL,
    stato ENUM('bozza', 'emessa', 'inviata', 'pagata', 'annullata') DEFAULT 'bozza',
    xml_path VARCHAR(255),
    pdf_path VARCHAR(255),
    note TEXT,
    FOREIGN KEY (professionista_id) REFERENCES professionisti(id),
    INDEX idx_professionista (professionista_id),
    INDEX idx_data (data_emissione),
    INDEX idx_stato (stato)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Tabella configurazione
CREATE TABLE IF NOT EXISTS configurazione (
    chiave VARCHAR(100) PRIMARY KEY,
    valore TEXT,
    tipo VARCHAR(20) DEFAULT 'string'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Tabella log attività
CREATE TABLE IF NOT EXISTS log_attivita (
    id INT AUTO_INCREMENT PRIMARY KEY,
    utente_id INT,
    azione VARCHAR(100) NOT NULL,
    dettagli TEXT,
    ip_address VARCHAR(45),
    user_agent TEXT,
    timestamp TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (utente_id) REFERENCES utenti(id) ON DELETE SET NULL,
    INDEX idx_utente (utente_id),
    INDEX idx_timestamp (timestamp)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Tabella sessioni
CREATE TABLE IF NOT EXISTS sessioni (
    id VARCHAR(128) PRIMARY KEY,
    utente_id INT NOT NULL,
    ip_address VARCHAR(45),
    user_agent TEXT,
    data_creazione TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    data_ultimo_accesso TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (utente_id) REFERENCES utenti(id) ON DELETE CASCADE,
    INDEX idx_utente (utente_id),
    INDEX idx_data_accesso (data_ultimo_accesso)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Tabella template documenti
CREATE TABLE IF NOT EXISTS template_documenti (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tipo ENUM('referto_genetico', 'referto_microbiota', 'referto_intolleranze', 'report_genetico', 'fattura') NOT NULL,
    nome VARCHAR(100) NOT NULL,
    header TEXT,
    footer TEXT,
    contenuto TEXT,
    attivo BOOLEAN DEFAULT TRUE,
    UNIQUE KEY unique_tipo_attivo (tipo, attivo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Tabella descrizioni report genetici
CREATE TABLE IF NOT EXISTS descrizioni_report (
    id INT AUTO_INCREMENT PRIMARY KEY,
    tipo ENUM('gene', 'gruppo') NOT NULL,
    elemento_id INT NOT NULL,
    descrizione_generale TEXT,
    data_modifica TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_tipo_elemento (tipo, elemento_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Tabella descrizioni risultati per report
CREATE TABLE IF NOT EXISTS descrizioni_risultati_report (
    id INT AUTO_INCREMENT PRIMARY KEY,
    risultato_gene_id INT NOT NULL,
    descrizione_report TEXT,
    FOREIGN KEY (risultato_gene_id) REFERENCES risultati_geni(id) ON DELETE CASCADE,
    UNIQUE KEY unique_risultato (risultato_gene_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Tabella notifiche
CREATE TABLE IF NOT EXISTS notifiche (
    id INT AUTO_INCREMENT PRIMARY KEY,
    utente_id INT NOT NULL,
    tipo VARCHAR(50) NOT NULL,
    titolo VARCHAR(200) NOT NULL,
    messaggio TEXT,
    link VARCHAR(255),
    letta BOOLEAN DEFAULT FALSE,
    data_creazione TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    data_lettura DATETIME,
    FOREIGN KEY (utente_id) REFERENCES utenti(id) ON DELETE CASCADE,
    INDEX idx_utente_letta (utente_id, letta),
    INDEX idx_data (data_creazione)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Inserimento dati iniziali
INSERT INTO configurazione (chiave, valore, tipo) VALUES
('nome_laboratorio', 'SerteX+', 'string'),
('logo_path', '', 'string'),
('colore_primario', '#1976d2', 'string'),
('colore_secondario', '#dc004e', 'string'),
('backup_locale_enabled', '1', 'boolean'),
('backup_cloud_enabled', '0', 'boolean'),
('backup_cloud_provider', '', 'string'),
('two_factor_enabled', '0', 'boolean'),
('password_expiry_days', '90', 'integer'),
('max_login_attempts', '5', 'integer'),
('session_timeout', '3600', 'integer'),
('referto_retention_days', '45', 'integer');

-- Creazione utente amministratore predefinito (password: admin123)
INSERT INTO utenti (username, password, email, nome, cognome, tipo_utente) VALUES
('admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin@sertexplus.it', 'Admin', 'Sistema', 'amministratore');

-- Creazione listino predefinito
INSERT INTO listini (nome, descrizione, predefinito) VALUES
('Listino Standard', 'Listino prezzi predefinito', TRUE);

-- Aggiungi foreign key per gruppo_id dopo la creazione della tabella
ALTER TABLE geni 
ADD CONSTRAINT fk_gene_gruppo 
FOREIGN KEY (gruppo_id) REFERENCES gruppi_geni(id) ON DELETE SET NULL;