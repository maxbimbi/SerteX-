<?php
/**
 * SerteX+ - Classe Report
 * Generazione referti e report
 */

namespace SerteX;

use PDO;
use Exception;
use TCPDF;

class Report {
    private $db;
    private $id;
    private $data;
    private $test;
    
    /**
     * Costruttore
     * @param PDO $db
     * @param int|null $id ID referto
     */
    public function __construct(PDO $db, $id = null) {
        $this->db = $db;
        if ($id !== null) {
            $this->load($id);
        }
    }
    
    /**
     * Carica referto dal database
     * @param int $id
     * @return bool
     */
    public function load($id) {
        $stmt = $this->db->prepare("
            SELECT r.*, t.*, 
                   p.nome as paziente_nome, p.cognome as paziente_cognome,
                   p.codice_fiscale, p.data_nascita, p.sesso,
                   CONCAT(u.nome, ' ', u.cognome) as biologo_nome,
                   u.firma_descrizione, u.firma_immagine
            FROM referti r
            JOIN test t ON r.test_id = t.id
            JOIN pazienti p ON t.paziente_id = p.id
            JOIN utenti u ON r.biologo_id = u.id
            WHERE r.id = ?
        ");
        $stmt->execute([$id]);
        $data = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($data) {
            $this->id = $id;
            $this->data = $data;
            $this->test = new Test($this->db, $data['test_id']);
            return true;
        }
        
        return false;
    }
    
    /**
     * Carica referto per test ID
     * @param int $testId
     * @return bool
     */
    public function loadByTestId($testId) {
        $stmt = $this->db->prepare("SELECT id FROM referti WHERE test_id = ? LIMIT 1");
        $stmt->execute([$testId]);
        $id = $stmt->fetchColumn();
        
        if ($id) {
            return $this->load($id);
        }
        
        return false;
    }
    
    /**
     * Genera referto per un test
     * @param int $testId
     * @param int $biologoId
     * @return int|false ID referto creato
     */
    public function generate($testId, $biologoId) {
        try {
            // Carica test
            $this->test = new Test($this->db, $testId);
            if (!$this->test->getData()) {
                throw new Exception("Test non trovato");
            }
            
            // Verifica stato test
            if ($this->test->stato !== Test::STATO_ESEGUITO) {
                throw new Exception("Il test deve essere in stato 'eseguito' per generare il referto");
            }
            
            // Determina tipo referto
            $tipoReferto = $this->getTipoReferto($this->test->tipo_test);
            
            // Genera PDF
            $pdfPath = $this->generatePDF();
            if (!$pdfPath) {
                throw new Exception("Errore generazione PDF");
            }
            
            // Calcola hash del file
            $hashFile = generateFileHash($pdfPath);
            
            // Inserisci record referto
            $stmt = $this->db->prepare("
                INSERT INTO referti 
                (test_id, tipo_referto, file_path, biologo_id, hash_file)
                VALUES (?, ?, ?, ?, ?)
            ");
            
            $stmt->execute([
                $testId,
                $tipoReferto,
                $pdfPath,
                $biologoId,
                $hashFile
            ]);
            
            $refertoId = $this->db->lastInsertId();
            
            // Aggiorna stato test
            $this->test->updateStato(Test::STATO_REFERTATO);
            
            // Log attività
            logActivity($biologoId, 'report_generated', 
                       "Generato referto per test {$this->test->codice}");
            
            // Carica dati referto
            $this->load($refertoId);
            
            return $refertoId;
            
        } catch (Exception $e) {
            error_log("Errore generazione referto: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Genera il PDF del referto
     * @return string|false Path del file generato
     */
    private function generatePDF() {
        try {
            // Inizializza TCPDF
            $pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
            
            // Impostazioni documento
            $pdf->SetCreator('SerteX+');
            $pdf->SetAuthor(SITE_NAME);
            $pdf->SetTitle('Referto ' . $this->test->codice);
            
            // Rimuovi header/footer predefiniti
            $pdf->setPrintHeader(false);
            $pdf->setPrintFooter(false);
            
            // Margini
            $pdf->SetMargins(15, 15, 15);
            $pdf->SetAutoPageBreak(true, 20);
            
            // Font
            $pdf->SetFont('helvetica', '', 10);
            
            // Aggiungi pagina
            $pdf->AddPage();
            
            // Genera contenuto in base al tipo
            switch ($this->test->tipo_test) {
                case Test::TIPO_GENETICO:
                    $this->generateGeneticReport($pdf);
                    break;
                    
                case Test::TIPO_MICROBIOTA:
                    $this->generateMicrobiotaReport($pdf);
                    break;
                    
                case Test::TIPO_INTOLLERANZE_CITO:
                case Test::TIPO_INTOLLERANZE_ELISA:
                    $this->generateIntoleranceReport($pdf);
                    break;
            }
            
            // Genera nome file
            $filename = 'referto_' . $this->test->codice . '_' . date('YmdHis') . '.pdf';
            $filepath = UPLOAD_PATH . 'referti/' . $this->getTipoReferto($this->test->tipo_test) . 
                       '/' . date('Y/m/') . $filename;
            
            // Crea directory se non esiste
            $dir = dirname($filepath);
            if (!file_exists($dir)) {
                mkdir($dir, 0755, true);
            }
            
            // Salva PDF
            $pdf->Output($filepath, 'F');
            
            // Cripta il PDF con il codice fiscale
            $encryptedPath = $this->encryptPDF($filepath);
            
            return $encryptedPath;
            
        } catch (Exception $e) {
            error_log("Errore generazione PDF: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Genera contenuto referto genetico
     * @param TCPDF $pdf
     */
    private function generateGeneticReport($pdf) {
        // Header con logo e intestazione
        $this->addReportHeader($pdf);
        
        // Dati paziente
        $this->addPatientInfo($pdf);
        
        // Titolo referto
        $pdf->SetFont('helvetica', 'B', 14);
        $pdf->Cell(0, 10, 'REFERTO ANALISI GENETICHE', 0, 1, 'C');
        $pdf->Ln(5);
        
        // Info test
        $pdf->SetFont('helvetica', '', 10);
        $pdf->Cell(50, 6, 'Codice Test:', 0, 0);
        $pdf->SetFont('helvetica', 'B', 10);
        $pdf->Cell(0, 6, $this->test->codice, 0, 1);
        
        $pdf->SetFont('helvetica', '', 10);
        $pdf->Cell(50, 6, 'Data Prelievo:', 0, 0);
        $pdf->Cell(0, 6, formatDate($this->test->data_richiesta), 0, 1);
        
        $pdf->Cell(50, 6, 'Data Refertazione:', 0, 0);
        $pdf->Cell(0, 6, formatDate($this->test->data_refertazione), 0, 1);
        $pdf->Ln(10);
        
        // Risultati
        $pdf->SetFont('helvetica', 'B', 12);
        $pdf->Cell(0, 8, 'RISULTATI', 0, 1);
        $pdf->Ln(2);
        
        // Recupera dettagli e risultati
        $details = $this->test->getDetails();
        $risultati = $this->getGeneticResults();
        
        // Tabella risultati
        $pdf->SetFont('helvetica', '', 9);
        
        // Organizza per gruppo
        $gruppi = [];
        foreach ($risultati as $risultato) {
            $gruppo = $risultato['gruppo_nome'] ?? 'Altri';
            if (!isset($gruppi[$gruppo])) {
                $gruppi[$gruppo] = [];
            }
            $gruppi[$gruppo][] = $risultato;
        }
        
        foreach ($gruppi as $nomeGruppo => $geniGruppo) {
            // Nome gruppo
            $pdf->SetFont('helvetica', 'B', 10);
            $pdf->Cell(0, 6, $nomeGruppo, 0, 1);
            $pdf->SetFont('helvetica', '', 9);
            
            // Header tabella
            $pdf->SetFillColor(240, 240, 240);
            $pdf->Cell(30, 6, 'Gene', 1, 0, 'L', true);
            $pdf->Cell(80, 6, 'Descrizione', 1, 0, 'L', true);
            $pdf->Cell(40, 6, 'Risultato', 1, 0, 'C', true);
            $pdf->Cell(30, 6, 'Note', 1, 1, 'L', true);
            
            // Righe risultati
            foreach ($geniGruppo as $gene) {
                $pdf->Cell(30, 6, $gene['sigla'], 1, 0);
                $pdf->Cell(80, 6, $this->truncateText($gene['nome'], 40), 1, 0);
                
                // Colora in base al tipo di risultato
                $color = $this->getResultColor($gene['risultato_tipo']);
                $pdf->SetTextColor($color[0], $color[1], $color[2]);
                $pdf->Cell(40, 6, $gene['risultato_nome'], 1, 0, 'C');
                $pdf->SetTextColor(0, 0, 0);
                
                $pdf->Cell(30, 6, $this->truncateText($gene['note'] ?? '', 15), 1, 1);
            }
            
            $pdf->Ln(5);
        }
        
        // Note e conclusioni
        if (!empty($this->test->note)) {
            $pdf->Ln(5);
            $pdf->SetFont('helvetica', 'B', 10);
            $pdf->Cell(0, 6, 'NOTE:', 0, 1);
            $pdf->SetFont('helvetica', '', 9);
            $pdf->MultiCell(0, 5, $this->test->note, 0, 'L');
        }
        
        // Firma biologo
        $this->addBiologistSignature($pdf);
        
        // Footer
        $this->addReportFooter($pdf);
    }
    
    /**
     * Genera contenuto referto microbiota
     * @param TCPDF $pdf
     */
    private function generateMicrobiotaReport($pdf) {
        // Per il microbiota, il referto viene caricato esternamente
        // Questa funzione genera solo una pagina di riepilogo
        
        $this->addReportHeader($pdf);
        $this->addPatientInfo($pdf);
        
        $pdf->SetFont('helvetica', 'B', 14);
        $pdf->Cell(0, 10, 'REFERTO ANALISI MICROBIOTA', 0, 1, 'C');
        $pdf->Ln(5);
        
        // Info test
        $details = $this->test->getDetails();
        
        $pdf->SetFont('helvetica', '', 10);
        $pdf->Cell(50, 6, 'Codice Test:', 0, 0);
        $pdf->SetFont('helvetica', 'B', 10);
        $pdf->Cell(0, 6, $this->test->codice, 0, 1);
        
        $pdf->SetFont('helvetica', '', 10);
        $pdf->Cell(50, 6, 'Tipo Analisi:', 0, 0);
        $pdf->Cell(0, 6, $details['nome'] ?? '', 0, 1);
        
        $pdf->Cell(50, 6, 'Data Prelievo:', 0, 0);
        $pdf->Cell(0, 6, formatDate($this->test->data_richiesta), 0, 1);
        
        $pdf->Ln(10);
        
        $pdf->SetFont('helvetica', '', 11);
        $pdf->MultiCell(0, 6, 
            'Il referto dettagliato dell\'analisi del microbiota è disponibile come allegato. ' .
            'Per visualizzarlo, accedere all\'area riservata o contattare il laboratorio.',
            0, 'L'
        );
        
        $this->addBiologistSignature($pdf);
        $this->addReportFooter($pdf);
    }
    
    /**
     * Genera contenuto referto intolleranze
     * @param TCPDF $pdf
     */
    private function generateIntoleranceReport($pdf) {
        $this->addReportHeader($pdf);
        $this->addPatientInfo($pdf);
        
        $tipoTest = $this->test->tipo_test === Test::TIPO_INTOLLERANZE_CITO ? 
                   'CITOTOSSICO' : 'ELISA';
        
        $pdf->SetFont('helvetica', 'B', 14);
        $pdf->Cell(0, 10, "REFERTO INTOLLERANZE ALIMENTARI - $tipoTest", 0, 1, 'C');
        $pdf->Ln(5);
        
        // Info test
        $pdf->SetFont('helvetica', '', 10);
        $pdf->Cell(50, 6, 'Codice Test:', 0, 0);
        $pdf->SetFont('helvetica', 'B', 10);
        $pdf->Cell(0, 6, $this->test->codice, 0, 1);
        
        $pdf->SetFont('helvetica', '', 10);
        $pdf->Cell(50, 6, 'Data Esecuzione:', 0, 0);
        $pdf->Cell(0, 6, formatDate($this->test->data_esecuzione), 0, 1);
        $pdf->Ln(10);
        
        // Risultati
        $risultati = $this->getIntoleranceResults();
        
        // Legenda
        $pdf->SetFont('helvetica', 'B', 10);
        $pdf->Cell(0, 6, 'LEGENDA:', 0, 1);
        $pdf->SetFont('helvetica', '', 9);
        
        $pdf->SetFillColor(0, 255, 0);
        $pdf->Rect($pdf->GetX(), $pdf->GetY() + 1, 4, 4, 'F');
        $pdf->Cell(30, 6, '    Grado 0: Negativo', 0, 0);
        
        $pdf->SetFillColor(255, 255, 0);
        $pdf->Rect($pdf->GetX(), $pdf->GetY() + 1, 4, 4, 'F');
        $pdf->Cell(40, 6, '    Grado 1: Intolleranza lieve', 0, 0);
        
        $pdf->SetFillColor(255, 165, 0);
        $pdf->Rect($pdf->GetX(), $pdf->GetY() + 1, 4, 4, 'F');
        $pdf->Cell(40, 6, '    Grado 2: Intolleranza media', 0, 0);
        
        $pdf->SetFillColor(255, 0, 0);
        $pdf->Rect($pdf->GetX(), $pdf->GetY() + 1, 4, 4, 'F');
        $pdf->Cell(0, 6, '    Grado 3: Intolleranza grave', 0, 1);
        
        $pdf->Ln(5);
        
        // Tabella risultati
        $pdf->SetFont('helvetica', 'B', 10);
        $pdf->Cell(0, 6, 'RISULTATI:', 0, 1);
        
        // Organizza per grado
        $perGrado = [
            3 => ['titolo' => 'INTOLLERANZE GRAVI', 'alimenti' => []],
            2 => ['titolo' => 'INTOLLERANZE MEDIE', 'alimenti' => []],
            1 => ['titolo' => 'INTOLLERANZE LIEVI', 'alimenti' => []],
            0 => ['titolo' => 'ALIMENTI TOLLERATI', 'alimenti' => []]
        ];
        
        foreach ($risultati as $risultato) {
            $perGrado[$risultato['grado']]['alimenti'][] = $risultato;
        }
        
        // Mostra risultati per grado
        foreach ([3, 2, 1] as $grado) {
            if (!empty($perGrado[$grado]['alimenti'])) {
                $pdf->Ln(3);
                $pdf->SetFont('helvetica', 'B', 9);
                $pdf->SetTextColor($this->getGradeColor($grado));
                $pdf->Cell(0, 6, $perGrado[$grado]['titolo'], 0, 1);
                $pdf->SetTextColor(0, 0, 0);
                $pdf->SetFont('helvetica', '', 9);
                
                // Lista alimenti
                $alimenti = array_column($perGrado[$grado]['alimenti'], 'nome');
                $text = implode(', ', $alimenti);
                $pdf->MultiCell(0, 5, $text, 0, 'L');
            }
        }
        
        // Raccomandazioni
        $pdf->Ln(5);
        $pdf->SetFont('helvetica', 'B', 10);
        $pdf->Cell(0, 6, 'RACCOMANDAZIONI:', 0, 1);
        $pdf->SetFont('helvetica', '', 9);
        $pdf->MultiCell(0, 5, 
            'Si consiglia di eliminare dalla dieta gli alimenti con grado 3 per almeno 3 mesi, ' .
            'ridurre il consumo di quelli con grado 2 e limitare quelli con grado 1. ' .
            'Dopo il periodo di eliminazione, reintrodurre gradualmente un alimento alla volta. ' .
            'Si raccomanda di consultare un nutrizionista per un piano alimentare personalizzato.',
            0, 'L'
        );
        
        $this->addBiologistSignature($pdf);
        $this->addReportFooter($pdf);
    }
    
    /**
     * Aggiunge header del referto
     * @param TCPDF $pdf
     */
    private function addReportHeader($pdf) {
        // Logo
        $logo = getConfig('logo_path');
        if ($logo && file_exists(ROOT_PATH . $logo)) {
            $pdf->Image(ROOT_PATH . $logo, 15, 10, 30);
        }
        
        // Intestazione laboratorio
        $pdf->SetFont('helvetica', 'B', 16);
        $pdf->SetX(50);
        $pdf->Cell(0, 8, getConfig('nome_laboratorio') ?? 'SerteX+', 0, 1);
        
        $pdf->SetFont('helvetica', '', 10);
        $pdf->SetX(50);
        $pdf->Cell(0, 5, 'Laboratorio di Analisi Genetiche', 0, 1);
        
        // Contatti
        $template = $this->getTemplate('header');
        if ($template) {
            $pdf->SetX(50);
            $pdf->writeHTML($template, true, false, true, false, '');
        }
        
        $pdf->Ln(10);
        
        // Linea separatrice
        $pdf->SetDrawColor(200, 200, 200);
        $pdf->Line(15, $pdf->GetY(), 195, $pdf->GetY());
        $pdf->Ln(5);
    }
    
    /**
     * Aggiunge informazioni paziente
     * @param TCPDF $pdf
     */
    private function addPatientInfo($pdf) {
        $pdf->SetFont('helvetica', 'B', 11);
        $pdf->Cell(0, 6, 'DATI PAZIENTE', 0, 1);
        
        $pdf->SetFont('helvetica', '', 10);
        
        // Due colonne
        $col1_x = $pdf->GetX();
        $col2_x = 100;
        
        $pdf->Cell(30, 5, 'Cognome:', 0, 0);
        $pdf->SetFont('helvetica', 'B', 10);
        $pdf->Cell(60, 5, $this->test->paziente_cognome, 0, 0);
        
        $pdf->SetX($col2_x);
        $pdf->SetFont('helvetica', '', 10);
        $pdf->Cell(30, 5, 'Nome:', 0, 0);
        $pdf->SetFont('helvetica', 'B', 10);
        $pdf->Cell(0, 5, $this->test->paziente_nome, 0, 1);
        
        $pdf->SetFont('helvetica', '', 10);
        $pdf->Cell(30, 5, 'Codice Fiscale:', 0, 0);
        $pdf->Cell(60, 5, $this->test->paziente_cf, 0, 0);
        
        $pdf->SetX($col2_x);
        $pdf->Cell(30, 5, 'Data Nascita:', 0, 0);
        $pdf->Cell(0, 5, formatDate($this->data['data_nascita']), 0, 1);
        
        $pdf->Cell(30, 5, 'Sesso:', 0, 0);
        $pdf->Cell(60, 5, $this->data['sesso'], 0, 1);
        
        $pdf->Ln(5);
    }
    
    /**
     * Aggiunge firma del biologo
     * @param TCPDF $pdf
     */
    private function addBiologistSignature($pdf) {
        $pdf->Ln(15);
        
        // Posizione firma
        $x = 120;
        $y = $pdf->GetY();
        
        $pdf->SetX($x);
        $pdf->SetFont('helvetica', '', 10);
        $pdf->Cell(0, 5, 'Il Biologo Responsabile', 0, 1);
        
        // Immagine firma se presente
        if (!empty($this->data['firma_immagine']) && 
            file_exists(UPLOAD_PATH . 'firme/' . $this->data['firma_immagine'])) {
            $pdf->Image(UPLOAD_PATH . 'firme/' . $this->data['firma_immagine'], 
                       $x, $y + 8, 50, 20, '', '', '', false, 300, '', false, false, 0);
            $y += 30;
        } else {
            $y += 15;
        }
        
        $pdf->SetY($y);
        $pdf->SetX($x);
        $pdf->SetFont('helvetica', 'B', 11);
        $pdf->Cell(0, 5, $this->data['biologo_nome'], 0, 1);
        
        if (!empty($this->data['firma_descrizione'])) {
            $pdf->SetX($x);
            $pdf->SetFont('helvetica', '', 9);
            $pdf->MultiCell(75, 4, $this->data['firma_descrizione'], 0, 'L');
        }
    }
    
    /**
     * Aggiunge footer del referto
     * @param TCPDF $pdf
     */
    private function addReportFooter($pdf) {
        $pdf->SetY(-30);
        
        // Linea separatrice
        $pdf->SetDrawColor(200, 200, 200);
        $pdf->Line(15, $pdf->GetY(), 195, $pdf->GetY());
        $pdf->Ln(3);
        
        // Footer template
        $template = $this->getTemplate('footer');
        if ($template) {
            $pdf->SetFont('helvetica', '', 8);
            $pdf->writeHTML($template, true, false, true, false, '');
        }
        
        // Numero pagina
        $pdf->SetFont('helvetica', 'I', 8);
        $pdf->Cell(0, 5, 'Pagina ' . $pdf->getPageNumGroupAlias() . '/' . $pdf->getPageGroupAlias(), 
                  0, 0, 'C');
    }
    
    /**
     * Ottiene risultati genetici
     * @return array
     */
    private function getGeneticResults() {
        $stmt = $this->db->prepare("
            SELECT g.sigla, g.nome, g.descrizione, gg.nome as gruppo_nome,
                   r.nome as risultato_nome, r.tipo as risultato_tipo,
                   rg.note
            FROM risultati_genetici rg
            JOIN geni g ON rg.gene_id = g.id
            JOIN risultati_geni r ON rg.risultato_id = r.id
            LEFT JOIN gruppi_geni gg ON g.gruppo_id = gg.id
            WHERE rg.test_id = ?
            ORDER BY gg.ordine, g.sigla
        ");
        $stmt->execute([$this->test->id]);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Ottiene risultati intolleranze
     * @return array
     */
    private function getIntoleranceResults() {
        $stmt = $this->db->prepare("
            SELECT a.nome, ri.grado, ri.valore_numerico
            FROM risultati_intolleranze ri
            JOIN alimenti a ON ri.alimento_id = a.id
            WHERE ri.test_id = ?
            ORDER BY ri.grado DESC, a.nome
        ");
        $stmt->execute([$this->test->id]);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Cripta il PDF con password
     * @param string $inputPath
     * @return string Path del file criptato
     */
    private function encryptPDF($inputPath) {
        $outputPath = str_replace('.pdf', '_encrypted.pdf', $inputPath);
        $password = strtoupper($this->test->paziente_cf);
        
        // Usa TCPDF per crittografare
        // O in alternativa usa strumenti esterni come qpdf
        if (encryptPDF($inputPath, $outputPath, $password)) {
            // Elimina file non criptato
            unlink($inputPath);
            return $outputPath;
        }
        
        return $inputPath;
    }
    
    /**
     * Firma digitalmente il referto
     * @param string $certificatePath
     * @param string $certificatePassword
     * @return bool
     */
    public function signDigitally($certificatePath, $certificatePassword) {
        if (!$this->id || !$this->data['file_path']) {
            return false;
        }
        
        try {
            $inputPath = $this->data['file_path'];
            $outputPath = str_replace('_encrypted.pdf', '_signed.pdf', $inputPath);
            
            // TODO: Implementare firma digitale con libreria appropriata
            // Per ora copia semplicemente il file
            copy($inputPath, $outputPath);
            
            // Aggiorna database
            $stmt = $this->db->prepare("
                UPDATE referti 
                SET file_path_firmato = ?, data_firma = NOW() 
                WHERE id = ?
            ");
            $stmt->execute([$outputPath, $this->id]);
            
            // Aggiorna stato test
            $this->test->updateStato(Test::STATO_FIRMATO);
            
            // Log attività
            logActivity($_SESSION['user_id'] ?? null, 'report_signed', 
                       "Firmato referto per test {$this->test->codice}");
            
            return true;
            
        } catch (Exception $e) {
            error_log("Errore firma digitale: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Genera report riassuntivo per paziente
     * @param int $testId
     * @return string|false Path del report
     */
    public function generateSummaryReport($testId) {
        try {
            $test = new Test($this->db, $testId);
            if (!$test->getData() || $test->tipo_test !== Test::TIPO_GENETICO) {
                return false;
            }
            
            // Inizializza PDF
            $pdf = new TCPDF();
            $pdf->SetCreator('SerteX+');
            $pdf->SetTitle('Report ' . $test->codice);
            $pdf->setPrintHeader(false);
            $pdf->setPrintFooter(false);
            $pdf->SetMargins(15, 15, 15);
            $pdf->SetFont('helvetica', '', 11);
            $pdf->AddPage();
            
            // Header
            $pdf->SetFont('helvetica', 'B', 16);
            $pdf->Cell(0, 10, 'REPORT ANALISI GENETICHE', 0, 1, 'C');
            $pdf->Ln(5);
            
            // Info paziente
            $pdf->SetFont('helvetica', '', 12);
            $pdf->Cell(0, 7, $test->paziente_cognome . ' ' . $test->paziente_nome, 0, 1);
            $pdf->Cell(0, 7, 'Data: ' . formatDate($test->data_refertazione), 0, 1);
            $pdf->Ln(10);
            
            // Descrizione generale
            $stmt = $this->db->prepare("
                SELECT descrizione_generale 
                FROM descrizioni_report 
                WHERE tipo = 'generale' 
                LIMIT 1
            ");
            $stmt->execute();
            $descrizioneGenerale = $stmt->fetchColumn();
            
            if ($descrizioneGenerale) {
                $pdf->SetFont('helvetica', '', 11);
                $pdf->MultiCell(0, 6, $descrizioneGenerale, 0, 'J');
                $pdf->Ln(10);
            }
            
            // Risultati per gruppo/gene
            $risultati = $this->getGeneticResultsWithDescriptions($testId);
            
            foreach ($risultati as $risultato) {
                $pdf->SetFont('helvetica', 'B', 13);
                $pdf->Cell(0, 8, $risultato['titolo'], 0, 1);
                
                if ($risultato['descrizione_generale']) {
                    $pdf->SetFont('helvetica', '', 11);
                    $pdf->MultiCell(0, 6, $risultato['descrizione_generale'], 0, 'J');
                    $pdf->Ln(3);
                }
                
                // Risultato specifico
                $pdf->SetFont('helvetica', '', 11);
                $pdf->Cell(40, 7, 'Il tuo risultato:', 0, 0);
                $pdf->SetFont('helvetica', 'B', 11);
                $pdf->Cell(0, 7, $risultato['risultato_nome'], 0, 1);
                
                if ($risultato['descrizione_risultato']) {
                    $pdf->SetFont('helvetica', '', 11);
                    $pdf->MultiCell(0, 6, $risultato['descrizione_risultato'], 0, 'J');
                }
                
                $pdf->Ln(8);
            }
            
            // Salva report
            $filename = 'report_' . $test->codice . '_' . date('YmdHis') . '.pdf';
            $filepath = UPLOAD_PATH . 'report/' . date('Y/m/') . $filename;
            
            $dir = dirname($filepath);
            if (!file_exists($dir)) {
                mkdir($dir, 0755, true);
            }
            
            $pdf->Output($filepath, 'F');
            
            // Cripta con CF
            $encryptedPath = $this->encryptPDF($filepath);
            
            return $encryptedPath;
            
        } catch (Exception $e) {
            error_log("Errore generazione report riassuntivo: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Ottiene risultati con descrizioni per report
     * @param int $testId
     * @return array
     */
    private function getGeneticResultsWithDescriptions($testId) {
        $stmt = $this->db->prepare("
            SELECT 
                COALESCE(gg.nome, g.nome) as titolo,
                COALESCE(drg.descrizione_generale, drge.descrizione_generale) as descrizione_generale,
                r.nome as risultato_nome,
                drr.descrizione_report as descrizione_risultato
            FROM risultati_genetici rg
            JOIN geni g ON rg.gene_id = g.id
            JOIN risultati_geni r ON rg.risultato_id = r.id
            LEFT JOIN gruppi_geni gg ON g.gruppo_id = gg.id
            LEFT JOIN descrizioni_report drg ON drg.tipo = 'gruppo' AND drg.elemento_id = gg.id
            LEFT JOIN descrizioni_report drge ON drge.tipo = 'gene' AND drge.elemento_id = g.id
            LEFT JOIN descrizioni_risultati_report drr ON drr.risultato_gene_id = r.id
            WHERE rg.test_id = ?
            ORDER BY gg.ordine, g.sigla
        ");
        $stmt->execute([$testId]);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Ottiene template documento
     * @param string $tipo
     * @return string|null
     */
    private function getTemplate($tipo) {
        $tipoReferto = $this->getTipoReferto($this->test->tipo_test);
        
        $stmt = $this->db->prepare("
            SELECT $tipo 
            FROM template_documenti 
            WHERE tipo = ? AND attivo = 1 
            LIMIT 1
        ");
        $stmt->execute([$tipoReferto]);
        
        return $stmt->fetchColumn() ?: null;
    }
    
    /**
     * Determina tipo referto
     * @param string $tipoTest
     * @return string
     */
    private function getTipoReferto($tipoTest) {
        switch ($tipoTest) {
            case Test::TIPO_GENETICO:
                return 'referto_genetico';
            case Test::TIPO_MICROBIOTA:
                return 'referto_microbiota';
            case Test::TIPO_INTOLLERANZE_CITO:
            case Test::TIPO_INTOLLERANZE_ELISA:
                return 'referto_intolleranze';
            default:
                return 'referto_generico';
        }
    }
    
    /**
     * Tronca testo
     * @param string $text
     * @param int $length
     * @return string
     */
    private function truncateText($text, $length) {
        if (strlen($text) <= $length) {
            return $text;
        }
        return substr($text, 0, $length - 3) . '...';
    }
    
    /**
     * Ottiene colore per tipo risultato
     * @param string $tipo
     * @return array RGB
     */
    private function getResultColor($tipo) {
        switch ($tipo) {
            case 'positivo':
                return [255, 0, 0]; // Rosso
            case 'negativo':
                return [0, 128, 0]; // Verde
            default:
                return [0, 0, 0]; // Nero
        }
    }
    
    /**
     * Ottiene colore per grado intolleranza
     * @param int $grado
     * @return array RGB
     */
    private function getGradeColor($grado) {
        switch ($grado) {
            case 3:
                return [255, 0, 0]; // Rosso
            case 2:
                return [255, 165, 0]; // Arancione
            case 1:
                return [255, 255, 0]; // Giallo
            default:
                return [0, 255, 0]; // Verde
        }
    }
    
    /**
     * Invia referto via email
     * @param string $email
     * @return bool
     */
    public function sendByEmail($email) {
        if (!$this->id || !$this->data['file_path_firmato']) {
            return false;
        }
        
        try {
            $subject = 'Referto Analisi - ' . $this->test->codice;
            
            $body = "Gentile {$this->test->paziente_nome} {$this->test->paziente_cognome},\n\n";
            $body .= "Le inviamo in allegato il referto delle sue analisi.\n";
            $body .= "Codice test: {$this->test->codice}\n";
            $body .= "Data refertazione: " . formatDate($this->test->data_refertazione) . "\n\n";
            $body .= "Il referto è protetto da password. ";
            $body .= "La password è il suo codice fiscale in MAIUSCOLO.\n\n";
            $body .= "Cordiali saluti,\n" . getConfig('nome_laboratorio');
            
            $attachments = [$this->data['file_path_firmato']];
            
            return sendEmail($email, $subject, $body, $attachments);
            
        } catch (Exception $e) {
            error_log("Errore invio email referto: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Verifica integrità del referto
     * @return bool
     */
    public function verifyIntegrity() {
        if (!$this->id || !$this->data['file_path']) {
            return false;
        }
        
        $currentHash = generateFileHash($this->data['file_path']);
        return $currentHash === $this->data['hash_file'];
    }
    
    /**
     * Ottiene tutti i referti
     * @param PDO $db
     * @param array $filters
     * @param int $limit
     * @param int $offset
     * @return array
     */
    public static function getAll(PDO $db, $filters = [], $limit = 50, $offset = 0) {
        $sql = "
            SELECT r.*, t.codice as test_codice, t.tipo_test,
                   p.nome as paziente_nome, p.cognome as paziente_cognome,
                   CONCAT(u.nome, ' ', u.cognome) as biologo_nome
            FROM referti r
            JOIN test t ON r.test_id = t.id
            JOIN pazienti p ON t.paziente_id = p.id
            JOIN utenti u ON r.biologo_id = u.id
            WHERE 1=1
        ";
        $params = [];
        
        // Filtri
        if (!empty($filters['tipo_referto'])) {
            $sql .= " AND r.tipo_referto = ?";
            $params[] = $filters['tipo_referto'];
        }
        
        if (!empty($filters['biologo_id'])) {
            $sql .= " AND r.biologo_id = ?";
            $params[] = $filters['biologo_id'];
        }
        
        if (!empty($filters['data_da'])) {
            $sql .= " AND DATE(r.data_creazione) >= ?";
            $params[] = $filters['data_da'];
        }
        
        if (!empty($filters['data_a'])) {
            $sql .= " AND DATE(r.data_creazione) <= ?";
            $params[] = $filters['data_a'];
        }
        
        if (!empty($filters['firmato'])) {
            $sql .= " AND r.file_path_firmato IS " . ($filters['firmato'] ? "NOT NULL" : "NULL");
        }
        
        if (!empty($filters['search'])) {
            $sql .= " AND (t.codice LIKE ? OR p.nome LIKE ? OR p.cognome LIKE ?)";
            $search = '%' . $filters['search'] . '%';
            $params[] = $search;
            $params[] = $search;
            $params[] = $search;
        }
        
        $sql .= " ORDER BY r.data_creazione DESC LIMIT ? OFFSET ?";
        $params[] = $limit;
        $params[] = $offset;
        
        try {
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("Errore recupero referti: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Carica referto microbiota esterno
     * @param int $testId
     * @param array $file
     * @param int $biologoId
     * @return int|false
     */
    public static function uploadMicrobiotaReport(PDO $db, $testId, $file, $biologoId) {
        try {
            // Valida file
            $validation = validateFileUpload($file, [
                'allowed_types' => ['pdf'],
                'allowed_mimes' => ['application/pdf'],
                'max_size' => 20 * 1024 * 1024 // 20MB
            ]);
            
            if (!$validation['valid']) {
                throw new Exception(implode(', ', $validation['errors']));
            }
            
            // Upload file
            $uploadResult = uploadFile($file, UPLOAD_PATH . 'referti/microbiota/' . date('Y/m/'));
            
            if (!$uploadResult['success']) {
                throw new Exception($uploadResult['error']);
            }
            
            // Cripta il file
            $test = new Test($db, $testId);
            $password = strtoupper($test->paziente_cf);
            $encryptedPath = str_replace('.pdf', '_encrypted.pdf', $uploadResult['filepath']);
            
            if (!encryptPDF($uploadResult['filepath'], $encryptedPath, $password)) {
                throw new Exception("Errore crittografia file");
            }
            
            // Elimina file non criptato
            unlink($uploadResult['filepath']);
            
            // Crea record referto
            $stmt = $db->prepare("
                INSERT INTO referti 
                (test_id, tipo_referto, file_path, biologo_id, hash_file)
                VALUES (?, 'referto_microbiota', ?, ?, ?)
            ");
            
            $stmt->execute([
                $testId,
                $encryptedPath,
                $biologoId,
                generateFileHash($encryptedPath)
            ]);
            
            $refertoId = $db->lastInsertId();
            
            // Aggiorna stato test
            $test->updateStato(Test::STATO_REFERTATO);
            
            logActivity($biologoId, 'microbiota_report_uploaded', 
                       "Caricato referto microbiota per test {$test->codice}");
            
            return $refertoId;
            
        } catch (Exception $e) {
            error_log("Errore upload referto microbiota: " . $e->getMessage());
            return false;
        }
    }
    
    // Getter magici
    public function __get($name) {
        return $this->data[$name] ?? null;
    }
    
    public function getId() {
        return $this->id;
    }
    
    public function getData() {
        return $this->data;
    }
    
    public function getTest() {
        return $this->test;
    }
    
    public function toArray() {
        return $this->data;
    }
}