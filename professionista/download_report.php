<?php
/**
 * Download diretto report per professionista
 * Gestisce il download senza mostrare interfaccia
 */

session_start();
require_once '../includes/auth.php';
require_once '../includes/database.php';
require_once '../includes/functions.php';
require_once '../includes/security.php';

// Verifica autenticazione
if (!Auth::check() || Auth::getUserType() !== 'professionista') {
    redirect('/index.php');
}

$db = Database::getInstance()->getConnection();
$user = Auth::getUser();
$professionista_id = getProfessionistaId($db, $user['id']);

// Recupera parametri
$test_id = (int)($_GET['test_id'] ?? 0);
$type = $_GET['type'] ?? 'unsigned'; // unsigned o signed

if (!$test_id) {
    $_SESSION['error'] = "Test non specificato.";
    redirect('tests.php');
}

// Verifica che il test appartenga al professionista
$stmt = $db->prepare("
    SELECT 
        t.codice,
        t.stato,
        p.cognome,
        r.id AS referto_id,
        r.file_path,
        r.file_path_firmato,
        r.data_creazione,
        r.hash_file
    FROM test t
    JOIN pazienti p ON t.paziente_id = p.id
    LEFT JOIN referti r ON t.id = r.test_id
    WHERE t.id = ? AND t.professionista_id = ?
");
$stmt->execute([$test_id, $professionista_id]);
$test = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$test) {
    $_SESSION['error'] = "Test non trovato o non autorizzato.";
    redirect('tests.php');
}

if (!$test['referto_id']) {
    $_SESSION['error'] = "Referto non ancora disponibile.";
    redirect('test_dettaglio.php?id=' . $test_id);
}

// Verifica scadenza referto (45 giorni)
if (isReportExpired($test['data_creazione'])) {
    $_SESSION['error'] = "Il referto è scaduto (oltre 45 giorni). Contatta il laboratorio per richiederne una copia.";
    redirect('test_dettaglio.php?id=' . $test_id);
}

// Determina quale file scaricare
$file_path = null;
$file_suffix = '';

if ($type === 'signed' && $test['file_path_firmato']) {
    $file_path = $test['file_path_firmato'];
    $file_suffix = '_firmato';
} else {
    $file_path = $test['file_path'];
}

// Verifica esistenza file
if (!$file_path || !file_exists($file_path)) {
    $_SESSION['error'] = "File referto non trovato.";
    redirect('test_dettaglio.php?id=' . $test_id);
}

// Log download
logActivity($db, $user['id'], 'download_referto_professionista', 
           "Download referto test ID: $test_id, Tipo: $type");

// Prepara headers per download
$filename = 'Referto_' . $test['codice'] . '_' . $test['cognome'] . $file_suffix . '.pdf';
$filesize = filesize($file_path);

// Pulizia buffer output
if (ob_get_level()) {
    ob_end_clean();
}

// Headers per download
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . $filesize);
header('Cache-Control: private, max-age=0, must-revalidate');
header('Pragma: public');
header('Expires: 0');

// Output file
readfile($file_path);
exit;