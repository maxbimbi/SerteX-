<?php
/**
 * Gestione Geni - Area Amministratore
 * SerteX+ Genetic Lab Portal
 */

require_once '../includes/auth.php';
require_once '../includes/database.php';
require_once '../includes/session.php';
require_once '../includes/functions.php';
require_once '../classes/Gene.php';
require_once '../classes/Logger.php';

$auth = new Auth();
$session = Session::getInstance();
$db = Database::getInstance();
$logger = new Logger();

// Verifica autenticazione e ruolo
if (!$auth->isAuthenticated() || !$auth->hasRole('amministratore')) {
    header('Location: ../index.php');
    exit;
}

$user = $auth->getCurrentUser();
$action = $_GET['action'] ?? 'list';
$message = '';
$messageType = '';

// Gestione azioni POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf_token'] ?? '';
    
    if (!$session->validateCsrfToken($csrfToken)) {
        $message = 'Token di sicurezza non valido';
        $messageType = 'error';
    } else {
        switch ($_POST['action'] ?? '') {
            case 'create':
                $geneData = [
                    'sigla' => sanitizeInput($_POST['sigla'] ?? ''),
                    'nome' => sanitizeInput($_POST['nome']),
                    'descrizione' => sanitizeInput($_POST['descrizione'] ?? ''),
                    'prezzo' => floatval($_POST['prezzo'] ?? 0),
                    'gruppo_id' => !empty($_POST['gruppo_id']) ? intval($_POST['gruppo_id']) : null,
                    'attivo' => isset($_POST['attivo']) ? 1 : 0
                ];
                
                try {
                    $geneId = $db->insert('geni', $geneData);
                    
                    // Inserisci risultati possibili
                    if (!empty($_POST['risultati']) && is_array($_POST['risultati'])) {
                        foreach ($_POST['risultati'] as $index => $risultato) {
                            if (!empty($risultato['nome'])) {
                                $db->insert('risultati_geni', [
                                    'gene_id' => $geneId,
                                    'nome' => sanitizeInput($risultato['nome']),
                                    'tipo' => sanitizeInput($risultato['tipo']),
                                    'descrizione' => sanitizeInput($risultato['descrizione'] ?? ''),
                                    'ordine' => $index
                                ]);
                            }
                        }
                    }
                    
                    $logger->log($user->getId(), 'gene_creato', "Creato gene: {$geneData['nome']}");
                    $session->setFlash('success', 'Gene creato con successo');
                    header('Location: genes.php');
                    exit;
                    
                } catch (Exception $e) {
                    $message = 'Errore nella creazione del gene';
                    $messageType = 'error';
                }
                break;
                
            case 'update':
                $geneId = intval($_POST['gene_id']);
                $gene = new Gene($geneId);
                
                if ($gene->exists()) {
                    $geneData = [
                        'sigla' => sanitizeInput($_POST['sigla'] ?? ''),
                        'nome' => sanitizeInput($_POST['nome']),
                        'descrizione' => sanitizeInput($_POST['descrizione'] ?? ''),
                        'prezzo' => floatval($_POST['prezzo'] ?? 0),
                        'gruppo_id' => !empty($_POST['gruppo_id']) ? intval($_POST['gruppo_id']) : null,
                        'attivo' => isset($_POST['attivo']) ? 1 : 0
                    ];
                    
                    try {
                        $db->beginTransaction();
                        
                        // Aggiorna gene
                        $db->update('geni', $geneData, ['id' => $geneId]);
                        
                        // Elimina risultati esistenti
                        $db->delete('risultati_geni', ['gene_id' => $geneId]);
                        
                        // Inserisci nuovi risultati
                        if (!empty($_POST['risultati']) && is_array($_POST['risultati'])) {
                            foreach ($_POST['risultati'] as $index => $risultato) {
                                if (!empty($risultato['nome'])) {
                                    $db->insert('risultati_geni', [
                                        'gene_id' => $geneId,
                                        'nome' => sanitizeInput($risultato['nome']),
                                        'tipo' => sanitizeInput($risultato['tipo']),
                                        'descrizione' => sanitizeInput($risultato['descrizione'] ?? ''),
                                        'ordine' => $index
                                    ]);
                                }
                            }
                        }
                        
                        $db->commit();
                        
                        $logger->log($user->getId(), 'gene_modificato', "Modificato gene ID: {$geneId}");
                        $session->setFlash('success', 'Gene modificato con successo');
                        header('Location: genes.php');
                        exit;
                        
                    } catch (Exception $e) {
                        $db->rollback();
                        $message = 'Errore nella modifica del gene';
                        $messageType = 'error';
                    }
                }
                break;
                
            case 'delete':
                $geneId = intval($_POST['gene_id']);
                
                try {
                    // Verifica che il gene non sia utilizzato
                    $usage = $db->count('pannelli_geni', ['gene_id' => $geneId]);
                    $usage += $db->count('risultati_genetici', ['gene_id' => $geneId]);
                    
                    if ($usage > 0) {
                        $message = 'Impossibile eliminare il gene perché è utilizzato in pannelli o test';
                        $messageType = 'error';
                    } else {
                        $db->delete('geni', ['id' => $geneId]);
                        $logger->log($user->getId(), 'gene_eliminato', "Eliminato gene ID: {$geneId}");
                        $session->setFlash('success', 'Gene eliminato con successo');
                        header('Location: genes.php');
                        exit;
                    }
                } catch (Exception $e) {
                    $message = 'Errore nell\'eliminazione del gene';
                    $messageType = 'error';
                }
                break;
        }
    }
}

// Carica gruppi per select
$gruppi = $db->select("SELECT * FROM gruppi_geni ORDER BY ordine, nome");

// Genera token CSRF
$csrfToken = $session->generateCsrfToken();

// Includi header
require_once '../templates/header.php';
?>

<div class="container-fluid">
    <div class="row">
        <?php require_once '../templates/sidebar.php'; ?>
        
        <main class="col-md-9 ms-sm-auto col-lg-10 px-md-4">
            <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pt-3 pb-2 mb-3 border-bottom">
                <h1 class="h2">Gestione Geni</h1>
                <div class="btn-toolbar mb-2 mb-md-0">
                    <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#geneModal">
                        <i class="bi bi-plus-circle"></i> Nuovo Gene
                    </button>
                </div>
            </div>
            
            <?php if ($message): ?>
                <div class="alert alert-<?php echo $messageType === 'error' ? 'danger' : $messageType; ?> alert-dismissible fade show" role="alert">
                    <?php echo htmlspecialchars($message); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>
            
            <?php foreach ($session->getFlashMessages() as $flash): ?>
                <div class="alert alert-<?php echo $flash['type'] === 'error' ? 'danger' : $flash['type']; ?> alert-dismissible fade show" role="alert">
                    <?php echo htmlspecialchars($flash['message']); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endforeach; ?>
            
            <?php if ($action === 'list'): ?>
                <!-- Filtri -->
                <div class="row mb-3">
                    <div class="col-md-6">
                        <input type="text" class="form-control" id="searchGenes" placeholder="Cerca per sigla o nome...">
                    </div>
                    <div class="col-md-3">
                        <select class="form-select" id="filterGroup">
                            <option value="">Tutti i gruppi</option>
                            <?php foreach ($gruppi as $gruppo): ?>
                                <option value="<?php echo $gruppo['id']; ?>">
                                    <?php echo htmlspecialchars($gruppo['nome']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <select class="form-select" id="filterStatus">
                            <option value="">Tutti gli stati</option>
                            <option value="1">Attivi</option>
                            <option value="0">Non attivi</option>
                        </select>
                    </div>
                </div>
                
                <!-- Tabella geni -->
                <div class="table-responsive">
                    <table class="table table-striped table-hover" id="genesTable">
                        <thead>
                            <tr>
                                <th>Sigla</th>
                                <th>Nome</th>
                                <th>Gruppo</th>
                                <th>Prezzo</th>
                                <th>Risultati</th>
                                <th>Stato</th>
                                <th>Azioni</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $genes = $db->select("
                                SELECT g.*, gg.nome as gruppo_nome,
                                       COUNT(rg.id) as num_risultati
                                FROM geni g
                                LEFT JOIN gruppi_geni gg ON g.gruppo_id = gg.id
                                LEFT JOIN risultati_geni rg ON g.id = rg.gene_id
                                GROUP BY g.id
                                ORDER BY g.nome
                            ");
                            
                            foreach ($genes as $gene):
                            ?>
                            <tr data-gruppo="<?php echo $gene['gruppo_id'] ?? ''; ?>" 
                                data-stato="<?php echo $gene['attivo']; ?>">
                                <td><?php echo htmlspecialchars($gene['sigla'] ?? ''); ?></td>
                                <td><?php echo htmlspecialchars($gene['nome']); ?></td>
                                <td><?php echo htmlspecialchars($gene['gruppo_nome'] ?? '-'); ?></td>
                                <td>€ <?php echo number_format($gene['prezzo'], 2, ',', '.'); ?></td>
                                <td>
                                    <span class="badge bg-secondary">
                                        <?php echo $gene['num_risultati']; ?> risultati
                                    </span>
                                </td>
                                <td>
                                    <?php if ($gene['attivo']): ?>
                                        <span class="badge bg-success">Attivo</span>
                                    <?php else: ?>
                                        <span class="badge bg-danger">Non attivo</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <button type="button" class="btn btn-sm btn-primary" 
                                            onclick="editGene(<?php echo $gene['id']; ?>)">
                                        <i class="bi bi-pencil"></i>
                                    </button>
                                    <button type="button" class="btn btn-sm btn-danger" 
                                            onclick="deleteGene(<?php echo $gene['id']; ?>, '<?php echo htmlspecialchars($gene['nome']); ?>')">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </main>
    </div>
</div>

<!-- Modal Creazione/Modifica Gene -->
<div class="modal fade" id="geneModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="geneModalTitle">Nuovo Gene</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="post" id="geneForm">
                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                <input type="hidden" name="action" value="create">
                <input type="hidden" name="gene_id" id="gene_id">
                
                <div class="modal-body">
                    <div class="row mb-3">
                        <div class="col-md-4">
                            <label for="sigla" class="form-label">Sigla</label>
                            <input type="text" class="form-control" id="sigla" name="sigla" maxlength="20">
                        </div>
                        <div class="col-md-8">
                            <label for="nome" class="form-label">Nome *</label>
                            <input type="text" class="form-control" id="nome" name="nome" required maxlength="100">
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label for="descrizione" class="form-label">Descrizione</label>
                        <textarea class="form-control" id="descrizione" name="descrizione" rows="3"></textarea>
                    </div>
                    
                    <div class="row mb-3">
                        <div class="col-md-4">
                            <label for="prezzo" class="form-label">Prezzo (€)</label>
                            <input type="number" class="form-control" id="prezzo" name="prezzo" 
                                   step="0.01" min="0" value="0">
                        </div>
                        <div class="col-md-5">
                            <label for="gruppo_id" class="form-label">Gruppo</label>
                            <select class="form-select" id="gruppo_id" name="gruppo_id">
                                <option value="">Nessun gruppo</option>
                                <?php foreach ($gruppi as $gruppo): ?>
                                    <option value="<?php echo $gruppo['id']; ?>">
                                        <?php echo htmlspecialchars($gruppo['nome']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">&nbsp;</label>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="attivo" name="attivo" checked>
                                <label class="form-check-label" for="attivo">
                                    Attivo
                                </label>
                            </div>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">Risultati Possibili</label>
                        <div id="risultatiContainer">
                            <div class="risultato-row mb-2">
                                <div class="row">
                                    <div class="col-md-3">
                                        <input type="text" class="form-control" name="risultati[${index}][descrizione]" 
                                           value="${risultato.descrizione || ''}" placeholder="Descrizione">
                                </div>
                                <div class="col-md-1">
                                    <button type="button" class="btn btn-sm btn-danger" onclick="removeRisultato(this)">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </div>
                            </div>
                        `;
                        container.appendChild(row);
                        risultatoIndex = index + 1;
                    });
                } else {
                    // Aggiungi almeno un risultato vuoto
                    addRisultato();
                }
                
                // Mostra modal
                const modal = new bootstrap.Modal(document.getElementById('geneModal'));
                modal.show();
            }
        })
        .catch(error => {
            console.error('Errore:', error);
            alert('Errore nel caricamento dei dati del gene');
        });
}

// Elimina gene
function deleteGene(id, nome) {
    document.getElementById('deleteGeneId').value = id;
    document.getElementById('deleteGeneName').textContent = nome;
    
    const modal = new bootstrap.Modal(document.getElementById('deleteModal'));
    modal.show();
}

// Reset form quando si chiude il modal
document.getElementById('geneModal').addEventListener('hidden.bs.modal', function () {
    document.getElementById('geneForm').reset();
    document.getElementById('gene_id').value = '';
    document.querySelector('#geneForm input[name="action"]').value = 'create';
    document.getElementById('geneModalTitle').textContent = 'Nuovo Gene';
    
    // Reset risultati
    const container = document.getElementById('risultatiContainer');
    container.innerHTML = '';
    risultatoIndex = 0;
    addRisultato();
});

// Filtri tabella
document.getElementById('searchGenes').addEventListener('keyup', filterTable);
document.getElementById('filterGroup').addEventListener('change', filterTable);
document.getElementById('filterStatus').addEventListener('change', filterTable);

function filterTable() {
    const searchText = document.getElementById('searchGenes').value.toLowerCase();
    const filterGroup = document.getElementById('filterGroup').value;
    const filterStatus = document.getElementById('filterStatus').value;
    
    const rows = document.querySelectorAll('#genesTable tbody tr');
    
    rows.forEach(row => {
        const sigla = row.cells[0].textContent.toLowerCase();
        const nome = row.cells[1].textContent.toLowerCase();
        const gruppo = row.dataset.gruppo;
        const stato = row.dataset.stato;
        
        let visible = true;
        
        // Filtro testo
        if (searchText && !sigla.includes(searchText) && !nome.includes(searchText)) {
            visible = false;
        }
        
        // Filtro gruppo
        if (filterGroup && gruppo !== filterGroup) {
            visible = false;
        }
        
        // Filtro stato
        if (filterStatus !== '' && stato !== filterStatus) {
            visible = false;
        }
        
        row.style.display = visible ? '' : 'none';
    });
}
</script>

<?php require_once '../templates/footer.php'; ?>form-control" name="risultati[0][nome]" 
                                               placeholder="Nome risultato" required>
                                    </div>
                                    <div class="col-md-3">
                                        <select class="form-select" name="risultati[0][tipo]" required>
                                            <option value="positivo">Positivo</option>
                                            <option value="negativo">Negativo</option>
                                            <option value="altro">Altro</option>
                                        </select>
                                    </div>
                                    <div class="col-md-5">
                                        <input type="text" class="form-control" name="risultati[0][descrizione]" 
                                               placeholder="Descrizione">
                                    </div>
                                    <div class="col-md-1">
                                        <button type="button" class="btn btn-sm btn-danger" onclick="removeRisultato(this)">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <button type="button" class="btn btn-sm btn-secondary" onclick="addRisultato()">
                            <i class="bi bi-plus"></i> Aggiungi Risultato
                        </button>
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annulla</button>
                    <button type="submit" class="btn btn-primary">Salva</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal Conferma Eliminazione -->
<div class="modal fade" id="deleteModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Conferma Eliminazione</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p>Sei sicuro di voler eliminare il gene <strong id="deleteGeneName"></strong>?</p>
                <p class="text-danger">Questa azione non può essere annullata.</p>
            </div>
            <div class="modal-footer">
                <form method="post" id="deleteForm">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="gene_id" id="deleteGeneId">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annulla</button>
                    <button type="submit" class="btn btn-danger">Elimina</button>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
// Contatore risultati
let risultatoIndex = 1;

// Aggiungi risultato
function addRisultato() {
    const container = document.getElementById('risultatiContainer');
    const newRow = document.createElement('div');
    newRow.className = 'risultato-row mb-2';
    newRow.innerHTML = `
        <div class="row">
            <div class="col-md-3">
                <input type="text" class="form-control" name="risultati[${risultatoIndex}][nome]" 
                       placeholder="Nome risultato" required>
            </div>
            <div class="col-md-3">
                <select class="form-select" name="risultati[${risultatoIndex}][tipo]" required>
                    <option value="positivo">Positivo</option>
                    <option value="negativo">Negativo</option>
                    <option value="altro">Altro</option>
                </select>
            </div>
            <div class="col-md-5">
                <input type="text" class="form-control" name="risultati[${risultatoIndex}][descrizione]" 
                       placeholder="Descrizione">
            </div>
            <div class="col-md-1">
                <button type="button" class="btn btn-sm btn-danger" onclick="removeRisultato(this)">
                    <i class="bi bi-trash"></i>
                </button>
            </div>
        </div>
    `;
    container.appendChild(newRow);
    risultatoIndex++;
}

// Rimuovi risultato
function removeRisultato(button) {
    const row = button.closest('.risultato-row');
    if (document.querySelectorAll('.risultato-row').length > 1) {
        row.remove();
    }
}

// Modifica gene
function editGene(id) {
    // Carica dati gene via AJAX
    fetch(`../api/v1/genes.php?id=${id}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const gene = data.data;
                
                // Popola form
                document.getElementById('gene_id').value = gene.id;
                document.getElementById('sigla').value = gene.sigla || '';
                document.getElementById('nome').value = gene.nome;
                document.getElementById('descrizione').value = gene.descrizione || '';
                document.getElementById('prezzo').value = gene.prezzo;
                document.getElementById('gruppo_id').value = gene.gruppo_id || '';
                document.getElementById('attivo').checked = gene.attivo == 1;
                
                // Cambia action e titolo
                document.querySelector('#geneForm input[name="action"]').value = 'update';
                document.getElementById('geneModalTitle').textContent = 'Modifica Gene';
                
                // Popola risultati
                const container = document.getElementById('risultatiContainer');
                container.innerHTML = '';
                risultatoIndex = 0;
                
                if (gene.risultati && gene.risultati.length > 0) {
                    gene.risultati.forEach((risultato, index) => {
                        const row = document.createElement('div');
                        row.className = 'risultato-row mb-2';
                        row.innerHTML = `
                            <div class="row">
                                <div class="col-md-3">
                                    <input type="text" class="form-control" name="risultati[${index}][nome]" 
                                           value="${risultato.nome}" placeholder="Nome risultato" required>
                                </div>
                                <div class="col-md-3">
                                    <select class="form-select" name="risultati[${index}][tipo]" required>
                                        <option value="positivo" ${risultato.tipo === 'positivo' ? 'selected' : ''}>Positivo</option>
                                        <option value="negativo" ${risultato.tipo === 'negativo' ? 'selected' : ''}>Negativo</option>
                                        <option value="altro" ${risultato.tipo === 'altro' ? 'selected' : ''}>Altro</option>
                                    </select>
                                </div>
                                <div class="col-md-5">
                                    <input type="text" class="