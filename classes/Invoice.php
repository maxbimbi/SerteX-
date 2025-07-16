<?php
/**
 * SerteX+ - Classe Invoice
 * Gestione fatturazione e fattura elettronica
 */

namespace SerteX;

use PDO;
use Exception;
use DOMDocument;
use TCPDF;

class Invoice {
    private $db;
    private $id;
    private $data;
    private $items = [];
    
    // Stati fattura
    const STATO_BOZZA = 'bozza';
    const STATO_EMESSA = 'emessa';
    const STATO_INVIATA = 'inviata';
    const STATO_PAGATA = 'pagata';
    const STATO_ANNULLATA = 'annullata';
    
    /**
     * Costruttore
     * @param PDO $db
     * @param int|null $id
     */
    public function __construct(PDO $db, $id = null) {
        $this->db = $db;
        if ($id !== null) {
            $this->load($id);
        }
    }
    
    /**
     * Carica fattura dal database
     * @param int $id
     * @return bool
     */
    public function load($id) {
        $stmt = $this->db->prepare("
            SELECT f.*, 
                   p.partita_iva, p.codice_fiscale, p.codice_sdi, p.pec,
                   p.indirizzo, p.telefono,
                   CONCAT(u.nome, ' ', u.cognome) as professionista_nome,
                   u.email as professionista_email
            FROM fatture f
            JOIN professionisti p ON f.professionista_id = p.id
            JOIN utenti u ON p.utente_id = u.id
            WHERE f.id = ?
        ");
        $stmt->execute([$id]);
        $data = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($data) {
            $this->id = $id;
            $this->data = $data;
            $this->loadItems();
            return true;
        }
        
        return false;
    }
    
    /**
     * Carica voci fattura
     */
    private function loadItems() {
        if (!$this->id) {
            return;
        }
        
        $stmt = $this->db->prepare("
            SELECT t.*, 
                   p.nome as paziente_nome, p.cognome as paziente_cognome
            FROM test t
            JOIN pazienti p ON t.paziente_id = p.id
            WHERE t.fattura_id = ?
            ORDER BY t.data_richiesta
        ");
        $stmt->execute([$this->id]);
        $this->items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Crea nuova fattura
     * @param array $data
     * @return int|false ID fattura creata
     */
    public function create($data) {
        try {
            $this->db->beginTransaction();
            
            // Genera numero fattura
            $numero = $this->generateInvoiceNumber($data['data_emissione'] ?? date('Y-m-d'));
            
            // Calcola totali
            $totals = $this->calculateTotals($data['test_ids'] ?? []);
            
            // Inserisci fattura
            $stmt = $this->db->prepare("
                INSERT INTO fatture 
                (numero, data_emissione, professionista_id, 
                 importo_totale, iva_totale, importo_totale_ivato, stato, note)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $stmt->execute([
                $numero,
                $data['data_emissione'] ?? date('Y-m-d'),
                $data['professionista_id'],
                $totals['imponibile'],
                $totals['iva'],
                $totals['totale'],
                $data['stato'] ?? self::STATO_BOZZA,
                $data['note'] ?? null
            ]);
            
            $invoiceId = $this->db->lastInsertId();
            
            // Associa test alla fattura
            if (!empty($data['test_ids'])) {
                $stmt = $this->db->prepare("
                    UPDATE test SET fattura_id = ?, fatturato = 1 WHERE id = ?
                ");
                
                foreach ($data['test_ids'] as $testId) {
                    $stmt->execute([$invoiceId, $testId]);
                }
            }
            
            $this->db->commit();
            
            // Log attività
            logActivity($_SESSION['user_id'] ?? null, 'invoice_created', 
                       "Creata fattura $numero");
            
            // Carica dati
            $this->load($invoiceId);
            
            return $invoiceId;
            
        } catch (Exception $e) {
            $this->db->rollBack();
            error_log("Errore creazione fattura: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Aggiorna fattura
     * @param array $data
     * @return bool
     */
    public function update($data) {
        if (!$this->id) {
            return false;
        }
        
        // Verifica se la fattura può essere modificata
        if (!in_array($this->data['stato'], [self::STATO_BOZZA, self::STATO_EMESSA])) {
            error_log("Fattura non modificabile in stato: " . $this->data['stato']);
            return false;
        }
        
        try {
            $updates = [];
            $params = [];
            
            $allowedFields = ['data_emissione', 'stato', 'note'];
            
            foreach ($allowedFields as $field) {
                if (isset($data[$field])) {
                    $updates[] = "$field = ?";
                    $params[] = $data[$field];
                }
            }
            
            if (empty($updates)) {
                return true;
            }
            
            $params[] = $this->id;
            $sql = "UPDATE fatture SET " . implode(', ', $updates) . " WHERE id = ?";
            
            $stmt = $this->db->prepare($sql);
            $result = $stmt->execute($params);
            
            if ($result) {
                logActivity($_SESSION['user_id'] ?? null, 'invoice_updated', 
                           "Aggiornata fattura {$this->data['numero']}");
                
                // Ricarica dati
                $this->load($this->id);
            }
            
            return $result;
            
        } catch (Exception $e) {
            error_log("Errore aggiornamento fattura: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Annulla fattura
     * @param string $motivo
     * @return bool
     */
    public function cancel($motivo = '') {
        if (!$this->id) {
            return false;
        }
        
        try {
            $this->db->beginTransaction();
            
            // Aggiorna stato fattura
            $stmt = $this->db->prepare("
                UPDATE fatture 
                SET stato = ?, note = CONCAT(IFNULL(note, ''), '\nAnnullata: ', ?)
                WHERE id = ?
            ");
            $stmt->execute([self::STATO_ANNULLATA, $motivo, $this->id]);
            
            // Disassocia test
            $stmt = $this->db->prepare("
                UPDATE test SET fattura_id = NULL, fatturato = 0 WHERE fattura_id = ?
            ");
            $stmt->execute([$this->id]);
            
            $this->db->commit();
            
            logActivity($_SESSION['user_id'] ?? null, 'invoice_cancelled', 
                       "Annullata fattura {$this->data['numero']}: $motivo");
            
            return true;
            
        } catch (Exception $e) {
            $this->db->rollBack();
            error_log("Errore annullamento fattura: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Genera PDF fattura
     * @return string|false Path del file generato
     */
    public function generatePDF() {
        if (!$this->data) {
            return false;
        }
        
        try {
            // Inizializza TCPDF
            $pdf = new TCPDF();
            $pdf->SetCreator('SerteX+');
            $pdf->SetTitle('Fattura ' . $this->data['numero']);
            $pdf->setPrintHeader(false);
            $pdf->setPrintFooter(false);
            $pdf->SetMargins(15, 15, 15);
            $pdf->SetAutoPageBreak(true, 20);
            $pdf->AddPage();
            
            // Logo e intestazione
            $this->addInvoiceHeader($pdf);
            
            // Dati fattura
            $pdf->SetFont('helvetica', 'B', 16);
            $pdf->Cell(0, 10, 'FATTURA N. ' . $this->data['numero'], 0, 1);
            
            $pdf->SetFont('helvetica', '', 10);
            $pdf->Cell(0, 6, 'Data: ' . formatDate($this->data['data_emissione']), 0, 1);
            $pdf->Ln(10);
            
            // Dati emittente
            $pdf->SetFont('helvetica', 'B', 11);
            $pdf->Cell(95, 6, 'EMITTENTE', 0, 0);
            $pdf->Cell(0, 6, 'DESTINATARIO', 0, 1);
            
            $pdf->SetFont('helvetica', '', 10);
            
            // Colonna emittente
            $x = $pdf->GetX();
            $y = $pdf->GetY();
            
            $emittente = $this->getEmitenteData();
            $pdf->MultiCell(90, 5, $emittente, 0, 'L');
            
            // Colonna destinatario
            $pdf->SetXY($x + 95, $y);
            $destinatario = $this->getDestinatarioData();
            $pdf->MultiCell(0, 5, $destinatario, 0, 'L');
            
            $pdf->Ln(10);
            
            // Tabella dettagli
            $this->addInvoiceDetails($pdf);
            
            // Totali
            $this->addInvoiceTotals($pdf);
            
            // Note
            if (!empty($this->data['note'])) {
                $pdf->Ln(10);
                $pdf->SetFont('helvetica', 'B', 10);
                $pdf->Cell(0, 6, 'NOTE:', 0, 1);
                $pdf->SetFont('helvetica', '', 9);
                $pdf->MultiCell(0, 5, $this->data['note'], 0, 'L');
            }
            
            // Info pagamento e bollo
            $this->addPaymentInfo($pdf);
            
            // Salva PDF
            $filename = 'fattura_' . str_replace('/', '_', $this->data['numero']) . '.pdf';
            $filepath = UPLOAD_PATH . 'fatture/' . date('Y/m/', strtotime($this->data['data_emissione'])) . $filename;
            
            $dir = dirname($filepath);
            if (!file_exists($dir)) {
                mkdir($dir, 0755, true);
            }
            
            $pdf->Output($filepath, 'F');
            
            // Aggiorna database
            $stmt = $this->db->prepare("UPDATE fatture SET pdf_path = ? WHERE id = ?");
            $stmt->execute([$filepath, $this->id]);
            
            return $filepath;
            
        } catch (Exception $e) {
            error_log("Errore generazione PDF fattura: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Genera XML per fattura elettronica
     * @return string|false Path del file XML
     */
    public function generateXML() {
        if (!$this->data) {
            return false;
        }
        
        try {
            $xml = new DOMDocument('1.0', 'UTF-8');
            $xml->formatOutput = true;
            
            // Root element con namespace
            $root = $xml->createElementNS(
                'http://ivaservizi.agenziaentrate.gov.it/docs/xsd/fatture/v1.2',
                'p:FatturaElettronica'
            );
            $root->setAttribute('versione', 'FPR12'); // Fattura verso privati
            $xml->appendChild($root);
            
            // Header
            $header = $xml->createElement('FatturaElettronicaHeader');
            $root->appendChild($header);
            
            // Dati trasmissione
            $datiTrasmissione = $this->createDatiTrasmissione($xml);
            $header->appendChild($datiTrasmissione);
            
            // Cedente/Prestatore (laboratorio)
            $cedentePrestatore = $this->createCedentePrestatore($xml);
            $header->appendChild($cedentePrestatore);
            
            // Cessionario/Committente (professionista)
            $cessionarioCommittente = $this->createCessionarioCommittente($xml);
            $header->appendChild($cessionarioCommittente);
            
            // Body
            $body = $xml->createElement('FatturaElettronicaBody');
            $root->appendChild($body);
            
            // Dati generali documento
            $datiGenerali = $this->createDatiGenerali($xml);
            $body->appendChild($datiGenerali);
            
            // Dati beni/servizi
            $datiBeniServizi = $this->createDatiBeniServizi($xml);
            $body->appendChild($datiBeniServizi);
            
            // Dati pagamento
            $datiPagamento = $this->createDatiPagamento($xml);
            $body->appendChild($datiPagamento);
            
            // Salva XML
            $filename = 'IT' . getConfig('partita_iva_lab') . '_' . 
                       $this->getProgressivoInvio() . '.xml';
            $filepath = UPLOAD_PATH . 'fatture/xml/' . date('Y/m/') . $filename;
            
            $dir = dirname($filepath);
            if (!file_exists($dir)) {
                mkdir($dir, 0755, true);
            }
            
            $xml->save($filepath);
            
            // Aggiorna database
            $stmt = $this->db->prepare("UPDATE fatture SET xml_path = ? WHERE id = ?");
            $stmt->execute([$filepath, $this->id]);
            
            // Log
            logActivity($_SESSION['user_id'] ?? null, 'invoice_xml_generated', 
                       "Generato XML per fattura {$this->data['numero']}");
            
            return $filepath;
            
        } catch (Exception $e) {
            error_log("Errore generazione XML fattura: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Crea nodo DatiTrasmissione
     */
    private function createDatiTrasmissione($xml) {
        $node = $xml->createElement('DatiTrasmissione');
        
        // ID Trasmittente
        $idTrasmittente = $xml->createElement('IdTrasmittente');
        $idTrasmittente->appendChild($xml->createElement('IdPaese', 'IT'));
        $idTrasmittente->appendChild($xml->createElement('IdCodice', getConfig('partita_iva_lab')));
        $node->appendChild($idTrasmittente);
        
        // Progressivo invio
        $node->appendChild($xml->createElement('ProgressivoInvio', $this->getProgressivoInvio()));
        
        // Formato trasmissione
        $node->appendChild($xml->createElement('FormatoTrasmissione', 'FPR12'));
        
        // Codice destinatario
        $codiceDestinatario = $this->data['codice_sdi'] ?: '0000000';
        $node->appendChild($xml->createElement('CodiceDestinatario', $codiceDestinatario));
        
        // PEC destinatario se presente
        if (!empty($this->data['pec']) && $codiceDestinatario === '0000000') {
            $node->appendChild($xml->createElement('PECDestinatario', $this->data['pec']));
        }
        
        return $node;
    }
    
    /**
     * Crea nodo CedentePrestatore
     */
    private function createCedentePrestatore($xml) {
        $node = $xml->createElement('CedentePrestatore');
        
        // Dati anagrafici
        $datiAnagrafici = $xml->createElement('DatiAnagrafici');
        
        $idFiscaleIVA = $xml->createElement('IdFiscaleIVA');
        $idFiscaleIVA->appendChild($xml->createElement('IdPaese', 'IT'));
        $idFiscaleIVA->appendChild($xml->createElement('IdCodice', getConfig('partita_iva_lab')));
        $datiAnagrafici->appendChild($idFiscaleIVA);
        
        $datiAnagrafici->appendChild($xml->createElement('CodiceFiscale', getConfig('codice_fiscale_lab')));
        
        $anagrafica = $xml->createElement('Anagrafica');
        $anagrafica->appendChild($xml->createElement('Denominazione', getConfig('nome_laboratorio')));
        $datiAnagrafici->appendChild($anagrafica);
        
        $datiAnagrafici->appendChild($xml->createElement('RegimeFiscale', 'RF01')); // Ordinario
        
        $node->appendChild($datiAnagrafici);
        
        // Sede
        $sede = $xml->createElement('Sede');
        $sede->appendChild($xml->createElement('Indirizzo', getConfig('indirizzo_lab')));
        $sede->appendChild($xml->createElement('CAP', getConfig('cap_lab')));
        $sede->appendChild($xml->createElement('Comune', getConfig('comune_lab')));
        $sede->appendChild($xml->createElement('Provincia', getConfig('provincia_lab')));
        $sede->appendChild($xml->createElement('Nazione', 'IT'));
        
        $node->appendChild($sede);
        
        return $node;
    }
    
    /**
     * Crea nodo CessionarioCommittente
     */
    private function createCessionarioCommittente($xml) {
        $node = $xml->createElement('CessionarioCommittente');
        
        // Dati anagrafici
        $datiAnagrafici = $xml->createElement('DatiAnagrafici');
        
        if (!empty($this->data['partita_iva'])) {
            $idFiscaleIVA = $xml->createElement('IdFiscaleIVA');
            $idFiscaleIVA->appendChild($xml->createElement('IdPaese', 'IT'));
            $idFiscaleIVA->appendChild($xml->createElement('IdCodice', $this->data['partita_iva']));
            $datiAnagrafici->appendChild($idFiscaleIVA);
        }
        
        if (!empty($this->data['codice_fiscale'])) {
            $datiAnagrafici->appendChild($xml->createElement('CodiceFiscale', $this->data['codice_fiscale']));
        }
        
        $anagrafica = $xml->createElement('Anagrafica');
        $anagrafica->appendChild($xml->createElement('Denominazione', $this->data['professionista_nome']));
        $datiAnagrafici->appendChild($anagrafica);
        
        $node->appendChild($datiAnagrafici);
        
        // Sede
        if (!empty($this->data['indirizzo'])) {
            $sede = $xml->createElement('Sede');
            
            // Parsing indirizzo (semplificato)
            $parts = explode(',', $this->data['indirizzo']);
            $sede->appendChild($xml->createElement('Indirizzo', trim($parts[0])));
            
            if (isset($parts[1]) && preg_match('/\d{5}/', $parts[1], $matches)) {
                $sede->appendChild($xml->createElement('CAP', $matches[0]));
            }
            
            if (isset($parts[2])) {
                $sede->appendChild($xml->createElement('Comune', trim($parts[2])));
            }
            
            $sede->appendChild($xml->createElement('Nazione', 'IT'));
            
            $node->appendChild($sede);
        }
        
        return $node;
    }
    
    /**
     * Crea nodo DatiGenerali
     */
    private function createDatiGenerali($xml) {
        $node = $xml->createElement('DatiGenerali');
        
        $datiGeneraliDocumento = $xml->createElement('DatiGeneraliDocumento');
        $datiGeneraliDocumento->appendChild($xml->createElement('TipoDocumento', 'TD01')); // Fattura
        $datiGeneraliDocumento->appendChild($xml->createElement('Divisa', 'EUR'));
        $datiGeneraliDocumento->appendChild($xml->createElement('Data', $this->data['data_emissione']));
        $datiGeneraliDocumento->appendChild($xml->createElement('Numero', $this->data['numero']));
        
        // Importo totale
        $datiGeneraliDocumento->appendChild(
            $xml->createElement('ImportoTotaleDocumento', 
                              number_format($this->data['importo_totale_ivato'], 2, '.', ''))
        );
        
        $node->appendChild($datiGeneraliDocumento);
        
        return $node;
    }
    
    /**
     * Crea nodo DatiBeniServizi
     */
    private function createDatiBeniServizi($xml) {
        $node = $xml->createElement('DatiBeniServizi');
        
        $lineaNum = 1;
        foreach ($this->items as $item) {
            $dettaglioLinee = $xml->createElement('DettaglioLinee');
            
            $dettaglioLinee->appendChild($xml->createElement('NumeroLinea', $lineaNum++));
            
            // Descrizione
            $descrizione = "Test {$item['codice']} - {$item['paziente_cognome']} {$item['paziente_nome']}";
            $dettaglioLinee->appendChild($xml->createElement('Descrizione', $descrizione));
            
            // Quantità sempre 1
            $dettaglioLinee->appendChild($xml->createElement('Quantita', '1.00'));
            
            // Prezzo unitario
            $dettaglioLinee->appendChild(
                $xml->createElement('PrezzoUnitario', 
                                  number_format($item['prezzo_finale'], 2, '.', ''))
            );
            
            // Prezzo totale
            $dettaglioLinee->appendChild(
                $xml->createElement('PrezzoTotale', 
                                  number_format($item['prezzo_finale'], 2, '.', ''))
            );
            
            // Aliquota IVA
            $dettaglioLinee->appendChild(
                $xml->createElement('AliquotaIVA', 
                                  number_format($item['iva'], 2, '.', ''))
            );
            
            $node->appendChild($dettaglioLinee);
        }
        
        // Dati riepilogo
        $datiRiepilogo = $xml->createElement('DatiRiepilogo');
        $datiRiepilogo->appendChild(
            $xml->createElement('AliquotaIVA', 
                              number_format($this->items[0]['iva'] ?? 22, 2, '.', ''))
        );
        $datiRiepilogo->appendChild(
            $xml->createElement('ImponibileImporto', 
                              number_format($this->data['importo_totale'], 2, '.', ''))
        );
        $datiRiepilogo->appendChild(
            $xml->createElement('Imposta', 
                              number_format($this->data['iva_totale'], 2, '.', ''))
        );
        
        $node->appendChild($datiRiepilogo);
        
        return $node;
    }
    
    /**
     * Crea nodo DatiPagamento
     */
    private function createDatiPagamento($xml) {
        $node = $xml->createElement('DatiPagamento');
        
        $node->appendChild($xml->createElement('CondizioniPagamento', 'TP02')); // Pagamento completo
        
        $dettaglioPagamento = $xml->createElement('DettaglioPagamento');
        $dettaglioPagamento->appendChild($xml->createElement('ModalitaPagamento', 'MP05')); // Bonifico
        $dettaglioPagamento->appendChild(
            $xml->createElement('ImportoPagamento', 
                              number_format($this->data['importo_totale_ivato'], 2, '.', ''))
        );
        
        $node->appendChild($dettaglioPagamento);
        
        return $node;
    }
    
    /**
     * Genera numero fattura progressivo
     * @param string $data
     * @return string
     */
    private function generateInvoiceNumber($data) {
        $year = date('Y', strtotime($data));
        
        // Trova ultimo numero dell'anno
        $stmt = $this->db->prepare("
            SELECT numero FROM fatture 
            WHERE YEAR(data_emissione) = ? 
            ORDER BY id DESC 
            LIMIT 1
        ");
        $stmt->execute([$year]);
        $lastNumber = $stmt->fetchColumn();
        
        if ($lastNumber) {
            // Estrai numero progressivo
            preg_match('/(\d+)\//', $lastNumber, $matches);
            $progressive = isset($matches[1]) ? (int)$matches[1] + 1 : 1;
        } else {
            $progressive = 1;
        }
        
        return sprintf('%04d/%d', $progressive, $year);
    }
    
    /**
     * Ottiene progressivo invio per XML
     * @return string
     */
    private function getProgressivoInvio() {
        // Formato: ultimi 2 cifre anno + numero progressivo 5 cifre
        $year = substr($this->data['data_emissione'], 2, 2);
        preg_match('/(\d+)\//', $this->data['numero'], $matches);
        $progressive = isset($matches[1]) ? $matches[1] : '00001';
        
        return $year . sprintf('%05d', $progressive);
    }
    
    /**
     * Calcola totali da test IDs
     * @param array $testIds
     * @return array
     */
    private function calculateTotals($testIds) {
        if (empty($testIds)) {
            return ['imponibile' => 0, 'iva' => 0, 'totale' => 0];
        }
        
        $placeholders = str_repeat('?,', count($testIds) - 1) . '?';
        $stmt = $this->db->prepare("
            SELECT SUM(prezzo_finale) as imponibile,
                   SUM(prezzo_finale * iva / 100) as iva,
                   SUM(prezzo_finale * (1 + iva / 100)) as totale
            FROM test
            WHERE id IN ($placeholders)
        ");
        $stmt->execute($testIds);
        
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    /**
     * Ottiene dati emittente formattati
     * @return string
     */
    private function getEmitenteData() {
        $data = getConfig('nome_laboratorio') . "\n";
        $data .= getConfig('indirizzo_lab') . "\n";
        $data .= getConfig('cap_lab') . ' ' . getConfig('comune_lab') . ' (' . getConfig('provincia_lab') . ")\n";
        $data .= "P.IVA: " . getConfig('partita_iva_lab') . "\n";
        $data .= "C.F.: " . getConfig('codice_fiscale_lab') . "\n";
        
        if (getConfig('telefono_lab')) {
            $data .= "Tel: " . getConfig('telefono_lab') . "\n";
        }
        if (getConfig('email_lab')) {
            $data .= "Email: " . getConfig('email_lab') . "\n";
        }
        if (getConfig('pec_lab')) {
            $data .= "PEC: " . getConfig('pec_lab');
        }
        
        return $data;
    }
    
    /**
     * Ottiene dati destinatario formattati
     * @return string
     */
    private function getDestinatarioData() {
        $data = $this->data['professionista_nome'] . "\n";
        
        if ($this->data['indirizzo']) {
            $data .= $this->data['indirizzo'] . "\n";
        }
        
        if ($this->data['partita_iva']) {
            $data .= "P.IVA: " . $this->data['partita_iva'] . "\n";
        }
        if ($this->data['codice_fiscale']) {
            $data .= "C.F.: " . $this->data['codice_fiscale'] . "\n";
        }
        if ($this->data['codice_sdi']) {
            $data .= "Codice SDI: " . $this->data['codice_sdi'] . "\n";
        }
        if ($this->data['pec']) {
            $data .= "PEC: " . $this->data['pec'];
        }
        
        return $data;
    }
    
    /**
     * Aggiunge header fattura PDF
     */
    private function addInvoiceHeader($pdf) {
        // Logo
        $logo = getConfig('logo_path');
        if ($logo && file_exists(ROOT_PATH . $logo)) {
            $pdf->Image(ROOT_PATH . $logo, 15, 10, 30);
        }
        
        // Intestazione
        $pdf->SetFont('helvetica', 'B', 14);
        $pdf->SetX(50);
        $pdf->Cell(0, 7, getConfig('nome_laboratorio'), 0, 1);
        
        $pdf->SetFont('helvetica', '', 9);
        $pdf->SetX(50);
        $pdf->MultiCell(0, 4, $this->getEmitenteData(), 0, 'L');
        
        $pdf->Ln(5);
    }
    
    /**
     * Aggiunge dettagli fattura PDF
     */
    private function addInvoiceDetails($pdf) {
        // Header tabella
        $pdf->SetFont('helvetica', 'B', 10);
        $pdf->SetFillColor(240, 240, 240);
        
        $pdf->Cell(15, 8, 'N.', 1, 0, 'C', true);
        $pdf->Cell(100, 8, 'Descrizione', 1, 0, 'L', true);
        $pdf->Cell(20, 8, 'Quantità', 1, 0, 'C', true);
        $pdf->Cell(25, 8, 'Prezzo', 1, 0, 'R', true);
        $pdf->Cell(20, 8, 'IVA %', 1, 1, 'C', true);
        
        // Righe
        $pdf->SetFont('helvetica', '', 9);
        $num = 1;
        
        foreach ($this->items as $item) {
            $descrizione = "Analisi {$item['tipo_test']} - Test {$item['codice']}\n";
            $descrizione .= "Paziente: {$item['paziente_cognome']} {$item['paziente_nome']}";
            
            $height = $pdf->getStringHeight(100, $descrizione);
            $height = max($height, 8);
            
            $pdf->Cell(15, $height, $num++, 1, 0, 'C');
            $pdf->MultiCell(100, $height, $descrizione, 1, 'L', false, 0);
            $pdf->Cell(20, $height, '1', 1, 0, 'C');
            $pdf->Cell(25, $height, formatCurrency($item['prezzo_finale'], false), 1, 0, 'R');
            $pdf->Cell(20, $height, number_format($item['iva'], 0) . '%', 1, 1, 'C');
        }
    }
    
    /**
     * Aggiunge totali fattura PDF
     */
    private function addInvoiceTotals($pdf) {
        $pdf->Ln(5);
        
        // Box totali
        $x = 110;
        $pdf->SetX($x);
        
        $pdf->SetFont('helvetica', '', 10);
        $pdf->Cell(50, 6, 'Imponibile:', 1, 0, 'R');
        $pdf->Cell(30, 6, formatCurrency($this->data['importo_totale'], false), 1, 1, 'R');
        
        $pdf->SetX($x);
        $pdf->Cell(50, 6, 'IVA:', 1, 0, 'R');
        $pdf->Cell(30, 6, formatCurrency($this->data['iva_totale'], false), 1, 1, 'R');
        
        $pdf->SetX($x);
        $pdf->SetFont('helvetica', 'B', 11);
        $pdf->Cell(50, 8, 'TOTALE:', 1, 0, 'R');
        $pdf->Cell(30, 8, formatCurrency($this->data['importo_totale_ivato'], false), 1, 1, 'R');
    }
    
    /**
     * Aggiunge info pagamento
     */
    private function addPaymentInfo($pdf) {
        $pdf->SetY(-50);
        
        $pdf->SetFont('helvetica', 'B', 10);
        $pdf->Cell(0, 6, 'MODALITÀ DI PAGAMENTO', 0, 1);
        
        $pdf->SetFont('helvetica', '', 9);
        $pdf->Cell(0, 5, 'Bonifico Bancario', 0, 1);
        $pdf->Cell(0, 5, 'IBAN: ' . getConfig('iban_lab'), 0, 1);
        
        // Bollo virtuale se importo > 77.47
        if ($this->data['importo_totale_ivato'] > 77.47) {
            $pdf->Ln(3);
            $pdf->Cell(0, 5, 'Imposta di bollo assolta in modo virtuale', 0, 1);
        }
    }
    
    /**
     * Marca fattura come pagata
     * @param string $dataPagamento
     * @return bool
     */
    public function markAsPaid($dataPagamento = null) {
        if (!$this->id) {
            return false;
        }
        
        if ($dataPagamento === null) {
            $dataPagamento = date('Y-m-d');
        }
        
        try {
            $stmt = $this->db->prepare("
                UPDATE fatture 
                SET stato = ?, 
                    note = CONCAT(IFNULL(note, ''), '\nPagata in data: ', ?)
                WHERE id = ?
            ");
            
            $result = $stmt->execute([self::STATO_PAGATA, $dataPagamento, $this->id]);
            
            if ($result) {
                logActivity($_SESSION['user_id'] ?? null, 'invoice_paid', 
                           "Fattura {$this->data['numero']} marcata come pagata");
            }
            
            return $result;
            
        } catch (Exception $e) {
            error_log("Errore marcatura pagamento: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Invia fattura via PEC
     * @return bool
     */
    public function sendByPEC() {
        if (!$this->id || !$this->data['pec']) {
            return false;
        }
        
        // Genera XML se non esiste
        if (!$this->data['xml_path'] || !file_exists($this->data['xml_path'])) {
            $xmlPath = $this->generateXML();
            if (!$xmlPath) {
                return false;
            }
        }
        
        // TODO: Implementare invio PEC reale
        // Per ora simula invio
        
        $this->update(['stato' => self::STATO_INVIATA]);
        
        logActivity($_SESSION['user_id'] ?? null, 'invoice_sent', 
                   "Fattura {$this->data['numero']} inviata via PEC");
        
        return true;
    }
    
    /**
     * Genera fattura mensile per professionista
     * @param PDO $db
     * @param int $professionistaId
     * @param int $mese
     * @param int $anno
     * @return int|false
     */
    public static function generateMonthlyInvoice(PDO $db, $professionistaId, $mese, $anno) {
        try {
            // Trova test non fatturati del mese
            $stmt = $db->prepare("
                SELECT id FROM test 
                WHERE professionista_id = ? 
                AND MONTH(data_richiesta) = ? 
                AND YEAR(data_richiesta) = ?
                AND fatturato = 0
                AND stato IN ('refertato', 'firmato')
            ");
            $stmt->execute([$professionistaId, $mese, $anno]);
            $testIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
            
            if (empty($testIds)) {
                return false; // Nessun test da fatturare
            }
            
            // Crea fattura
            $invoice = new Invoice($db);
            $data = [
                'professionista_id' => $professionistaId,
                'test_ids' => $testIds,
                'data_emissione' => date('Y-m-d'),
                'stato' => self::STATO_BOZZA,
                'note' => "Fattura riepilogativa mese $mese/$anno"
            ];
            
            return $invoice->create($data);
            
        } catch (Exception $e) {
            error_log("Errore generazione fattura mensile: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Ottiene tutte le fatture
     * @param PDO $db
     * @param array $filters
     * @param int $limit
     * @param int $offset
     * @return array
     */
    public static function getAll(PDO $db, $filters = [], $limit = 50, $offset = 0) {
        $sql = "
            SELECT f.*, 
                   CONCAT(u.nome, ' ', u.cognome) as professionista_nome,
                   p.partita_iva, p.codice_fiscale,
                   COUNT(DISTINCT t.id) as num_test
            FROM fatture f
            JOIN professionisti p ON f.professionista_id = p.id
            JOIN utenti u ON p.utente_id = u.id
            LEFT JOIN test t ON f.id = t.fattura_id
            WHERE 1=1
        ";
        $params = [];
        
        // Filtri
        if (!empty($filters['professionista_id'])) {
            $sql .= " AND f.professionista_id = ?";
            $params[] = $filters['professionista_id'];
        }
        
        if (!empty($filters['stato'])) {
            $sql .= " AND f.stato = ?";
            $params[] = $filters['stato'];
        }
        
        if (!empty($filters['anno'])) {
            $sql .= " AND YEAR(f.data_emissione) = ?";
            $params[] = $filters['anno'];
        }
        
        if (!empty($filters['mese'])) {
            $sql .= " AND MONTH(f.data_emissione) = ?";
            $params[] = $filters['mese'];
        }
        
        if (!empty($filters['data_da'])) {
            $sql .= " AND f.data_emissione >= ?";
            $params[] = $filters['data_da'];
        }
        
        if (!empty($filters['data_a'])) {
            $sql .= " AND f.data_emissione <= ?";
            $params[] = $filters['data_a'];
        }
        
        if (!empty($filters['search'])) {
            $sql .= " AND (f.numero LIKE ? OR u.nome LIKE ? OR u.cognome LIKE ?)";
            $search = '%' . $filters['search'] . '%';
            $params[] = $search;
            $params[] = $search;
            $params[] = $search;
        }
        
        $sql .= " GROUP BY f.id ORDER BY f.data_emissione DESC, f.numero DESC";
        $sql .= " LIMIT ? OFFSET ?";
        $params[] = $limit;
        $params[] = $offset;
        
        try {
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Exception $e) {
            error_log("Errore recupero fatture: " . $e->getMessage());
            return [];
        }
    }
    
    /**
     * Ottiene statistiche fatturazione
     * @param PDO $db
     * @param array $filters
     * @return array
     */
    public static function getStatistics(PDO $db, $filters = []) {
        $stats = [];
        $params = [];
        
        $whereClause = "WHERE 1=1";
        
        if (!empty($filters['anno'])) {
            $whereClause .= " AND YEAR(data_emissione) = ?";
            $params[] = $filters['anno'];
        }
        
        try {
            // Totali per stato
            $sql = "
                SELECT stato, COUNT(*) as numero, SUM(importo_totale_ivato) as totale
                FROM fatture
                $whereClause
                GROUP BY stato
            ";
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $stats['per_stato'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Totali per mese
            $sql = "
                SELECT 
                    MONTH(data_emissione) as mese,
                    COUNT(*) as numero,
                    SUM(importo_totale) as imponibile,
                    SUM(iva_totale) as iva,
                    SUM(importo_totale_ivato) as totale
                FROM fatture
                $whereClause
                GROUP BY MONTH(data_emissione)
                ORDER BY mese
            ";
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $stats['per_mese'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Top professionisti
            $sql = "
                SELECT 
                    p.id,
                    CONCAT(u.nome, ' ', u.cognome) as nome,
                    COUNT(*) as num_fatture,
                    SUM(f.importo_totale_ivato) as totale
                FROM fatture f
                JOIN professionisti p ON f.professionista_id = p.id
                JOIN utenti u ON p.utente_id = u.id
                $whereClause
                GROUP BY p.id
                ORDER BY totale DESC
                LIMIT 10
            ";
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $stats['top_professionisti'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Totale generale
            $sql = "
                SELECT 
                    COUNT(*) as numero_totale,
                    SUM(importo_totale) as imponibile_totale,
                    SUM(iva_totale) as iva_totale,
                    SUM(importo_totale_ivato) as totale_generale
                FROM fatture
                $whereClause
            ";
            $stmt = $db->prepare($sql);
            $stmt->execute($params);
            $stats['totali'] = $stmt->fetch(PDO::FETCH_ASSOC);
            
        } catch (Exception $e) {
            error_log("Errore statistiche fatturazione: " . $e->getMessage());
        }
        
        return $stats;
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
    
    public function getItems() {
        return $this->items;
    }
    
    public function toArray() {
        return array_merge($this->data, ['items' => $this->items]);
    }
}