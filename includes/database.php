<?php
/**
 * Classe Database - Gestione connessione database con PDO
 * SerteX+ Genetic Lab Portal
 */

class Database {
    private static $instance = null;
    private $connection = null;
    private $host;
    private $database;
    private $username;
    private $password;
    private $charset = 'utf8mb4';
    
    /**
     * Costruttore privato per pattern Singleton
     */
    private function __construct() {
        // Carica configurazione
        if (!file_exists(dirname(__DIR__) . '/config/config.php')) {
            throw new Exception('File di configurazione non trovato. Eseguire prima l\'installazione.');
        }
        
        require_once dirname(__DIR__) . '/config/config.php';
        
        $this->host = DB_HOST;
        $this->database = DB_NAME;
        $this->username = DB_USER;
        $this->password = DB_PASS;
        
        $this->connect();
    }
    
    /**
     * Ottiene l'istanza singleton del database
     */
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Stabilisce la connessione al database
     */
    private function connect() {
        try {
            $dsn = "mysql:host={$this->host};dbname={$this->database};charset={$this->charset}";
            
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES {$this->charset}"
            ];
            
            $this->connection = new PDO($dsn, $this->username, $this->password, $options);
            
        } catch (PDOException $e) {
            error_log("Errore connessione database: " . $e->getMessage());
            throw new Exception("Errore di connessione al database");
        }
    }
    
    /**
     * Ottiene la connessione PDO
     */
    public function getConnection() {
        if ($this->connection === null) {
            $this->connect();
        }
        return $this->connection;
    }
    
    /**
     * Esegue una query SELECT
     * @param string $sql Query SQL
     * @param array $params Parametri da bindare
     * @return array Risultati
     */
    public function select($sql, $params = []) {
        try {
            $stmt = $this->getConnection()->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            error_log("Errore query SELECT: " . $e->getMessage());
            throw new Exception("Errore nell'esecuzione della query");
        }
    }
    
    /**
     * Esegue una query SELECT e ritorna un singolo record
     * @param string $sql Query SQL
     * @param array $params Parametri da bindare
     * @return array|false Record o false se non trovato
     */
    public function selectOne($sql, $params = []) {
        try {
            $stmt = $this->getConnection()->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetch();
        } catch (PDOException $e) {
            error_log("Errore query SELECT ONE: " . $e->getMessage());
            throw new Exception("Errore nell'esecuzione della query");
        }
    }
    
    /**
     * Esegue una query INSERT
     * @param string $table Nome tabella
     * @param array $data Dati da inserire
     * @return int ID dell'ultimo record inserito
     */
    public function insert($table, $data) {
        try {
            $fields = array_keys($data);
            $values = array_map(function($field) { return ':' . $field; }, $fields);
            
            $sql = "INSERT INTO {$table} (" . implode(', ', $fields) . ") 
                    VALUES (" . implode(', ', $values) . ")";
            
            $stmt = $this->getConnection()->prepare($sql);
            
            foreach ($data as $key => $value) {
                $stmt->bindValue(':' . $key, $value);
            }
            
            $stmt->execute();
            return $this->connection->lastInsertId();
            
        } catch (PDOException $e) {
            error_log("Errore query INSERT: " . $e->getMessage());
            throw new Exception("Errore nell'inserimento dei dati");
        }
    }
    
    /**
     * Esegue una query UPDATE
     * @param string $table Nome tabella
     * @param array $data Dati da aggiornare
     * @param array $where Condizioni WHERE
     * @return int Numero di righe modificate
     */
    public function update($table, $data, $where) {
        try {
            $setParts = [];
            foreach ($data as $key => $value) {
                $setParts[] = "{$key} = :{$key}";
            }
            
            $whereParts = [];
            foreach ($where as $key => $value) {
                $whereParts[] = "{$key} = :where_{$key}";
            }
            
            $sql = "UPDATE {$table} SET " . implode(', ', $setParts) . 
                   " WHERE " . implode(' AND ', $whereParts);
            
            $stmt = $this->getConnection()->prepare($sql);
            
            // Bind valori SET
            foreach ($data as $key => $value) {
                $stmt->bindValue(':' . $key, $value);
            }
            
            // Bind valori WHERE
            foreach ($where as $key => $value) {
                $stmt->bindValue(':where_' . $key, $value);
            }
            
            $stmt->execute();
            return $stmt->rowCount();
            
        } catch (PDOException $e) {
            error_log("Errore query UPDATE: " . $e->getMessage());
            throw new Exception("Errore nell'aggiornamento dei dati");
        }
    }
    
    /**
     * Esegue una query DELETE
     * @param string $table Nome tabella
     * @param array $where Condizioni WHERE
     * @return int Numero di righe eliminate
     */
    public function delete($table, $where) {
        try {
            $whereParts = [];
            foreach ($where as $key => $value) {
                $whereParts[] = "{$key} = :{$key}";
            }
            
            $sql = "DELETE FROM {$table} WHERE " . implode(' AND ', $whereParts);
            
            $stmt = $this->getConnection()->prepare($sql);
            
            foreach ($where as $key => $value) {
                $stmt->bindValue(':' . $key, $value);
            }
            
            $stmt->execute();
            return $stmt->rowCount();
            
        } catch (PDOException $e) {
            error_log("Errore query DELETE: " . $e->getMessage());
            throw new Exception("Errore nell'eliminazione dei dati");
        }
    }
    
    /**
     * Esegue una query generica
     * @param string $sql Query SQL
     * @param array $params Parametri da bindare
     * @return PDOStatement
     */
    public function query($sql, $params = []) {
        try {
            $stmt = $this->getConnection()->prepare($sql);
            $stmt->execute($params);
            return $stmt;
        } catch (PDOException $e) {
            error_log("Errore query: " . $e->getMessage());
            throw new Exception("Errore nell'esecuzione della query");
        }
    }
    
    /**
     * Inizia una transazione
     */
    public function beginTransaction() {
        return $this->getConnection()->beginTransaction();
    }
    
    /**
     * Conferma una transazione
     */
    public function commit() {
        return $this->getConnection()->commit();
    }
    
    /**
     * Annulla una transazione
     */
    public function rollback() {
        return $this->getConnection()->rollback();
    }
    
    /**
     * Verifica se una tabella esiste
     * @param string $table Nome tabella
     * @return bool
     */
    public function tableExists($table) {
        try {
            $sql = "SHOW TABLES LIKE :table";
            $stmt = $this->getConnection()->prepare($sql);
            $stmt->execute(['table' => $table]);
            return $stmt->rowCount() > 0;
        } catch (PDOException $e) {
            return false;
        }
    }
    
    /**
     * Conta i record in una tabella
     * @param string $table Nome tabella
     * @param array $where Condizioni WHERE opzionali
     * @return int Numero di record
     */
    public function count($table, $where = []) {
        try {
            $sql = "SELECT COUNT(*) as count FROM {$table}";
            
            if (!empty($where)) {
                $whereParts = [];
                foreach ($where as $key => $value) {
                    $whereParts[] = "{$key} = :{$key}";
                }
                $sql .= " WHERE " . implode(' AND ', $whereParts);
            }
            
            $stmt = $this->getConnection()->prepare($sql);
            
            foreach ($where as $key => $value) {
                $stmt->bindValue(':' . $key, $value);
            }
            
            $stmt->execute();
            $result = $stmt->fetch();
            return (int)$result['count'];
            
        } catch (PDOException $e) {
            error_log("Errore COUNT: " . $e->getMessage());
            return 0;
        }
    }
    
    /**
     * Escape di una stringa per query LIKE
     * @param string $string
     * @return string
     */
    public function escapeLike($string) {
        return str_replace(['%', '_'], ['\\%', '\\_'], $string);
    }
    
    /**
     * Chiude la connessione
     */
    public function close() {
        $this->connection = null;
    }
    
    /**
     * Distruttore
     */
    public function __destruct() {
        $this->close();
    }
    
    /**
     * Previene la clonazione dell'oggetto
     */
    private function __clone() {}
    
    /**
     * Previene la deserializzazione
     */
    public function __wakeup() {
        throw new Exception("Cannot unserialize singleton");
    }
}
