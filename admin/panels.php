<?php
/**
 * Gestione Pannelli Genetici - Area Amministratore
 * SerteX+ Genetic Lab Portal
 */

require_once '../includes/auth.php';
require_once '../includes/database.php';
require_once '../includes/session.php';
require_once '../includes/functions.php';
require_once '../classes/Panel.php';
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
                $panelData = [
                    'nome' => sanitizeInput($_POST['nome']),
                    'descrizione' => sanitizeInput($_POST['descrizione'] ?? ''),
                    'prezzo' => floatval($_POST['prezzo'] ?? 0),
                    'attivo' => isset($_POST['attivo']) ? 1 : 0
                ];
                
                try {
                    $db->beginTransaction();
                    
                    // Crea pannello
                    $panelId = $db->insert('pannelli_genetici', $panelData);
                    
                    // Associa geni
                    if (!empty($_POST['geni']) && is_array($_POST['geni'])) {
                        foreach ($_POST['geni'] as $geneId) {
                            $db->insert('pannelli_geni', [
                                'pannello_id' => $panelId,
                                'gene_id' => intval($geneId)
                            ]);
                        }
                    }
                    
                    $db->commit();
                    
                    $logger->log($user->getId(), 'pannello_creato', "Creato pannello: {$panelData['nome']}");
                    $session->setFlash('success', 'Pannello creato con successo');
                    header('Location: panels.php');
                    exit;
                    
                } catch (Exception $e) {
                    $db->rollback();
                    $message = 'Errore nella creazione del pannello';
                    $messageType = 'error';
                }
                break;
                
            case 'update':
                $panelId = intval($_POST['panel_id']);
                
                $panelData = [
                    'nome' => sanitizeInput($_POST['nome']),
                    'descrizione' => sanitizeInput($_POST['descrizione'] ?? ''),
                    'prezzo' => floatval($_POST['prezzo'] ?? 0),
                    'attivo' => isset($_POST['attivo']) ? 1 : 0
                ];
                
                try {
                    $db->beginTransaction();
                    
                    // Aggiorna pannello
                    $db->update('pannelli_genetici', $panelData, ['id' => $panelId]);
                    
                    // Rimuovi associazioni esistenti
                    $db->delete('pannelli_geni', ['pannello_id' => $panelId]);
                    
                    // Aggiungi nuove associazioni
                    if (!empty($_POST['geni']) && is_array($_POST['geni'])) {
                        foreach ($_POST['geni'] as $geneId) {
                            $db->insert('pannelli_geni', [
                                'pannello_id' => $panelId,
                                'gene_id' => intval($geneId)
                            ]);
                        }
                    }
                    
                    $db->commit();
                    
                    $logger->log($user->getId(), 'pannello_modificato', "Modificato pannello ID: {$panelId}");
                    $session->setFlash('success', 'Pannello modificato con successo');
                    header('Location: panels.php');
                    exit;
                    
                } catch (Exception $e) {
                    $db->rollback();
                    $message = 'Errore nella modifica del pannello';
                    $messageType = 'error';
                }
                break;
                
            case 'delete':
                $panelId = intval($_POST['panel_id']);
                
                try {
                    // Verifica che il pannello non sia utilizzato
                    $usage = $db->count('test_genetici_dettagli', [
                        'tipo_elemento' => 'pannello',
                        'elemento_id' => $panelId
                    ]);
                    
                    if ($usage > 0) {
                        $message = 'Impossibile eliminare il pannello perché è utilizzato in test esistenti';
                        $messageType = 'error';
                    } else {
                        $db->delete('pannelli_genetici', ['id' => $panelId]);
                        $logger->log($user->getId(), 'pannello_eliminato', "Eliminato pannello ID: {$panelId}");
                        $session->setFlash('success', 'Pannello eliminato con successo');
                        header('Location: panels.php');
                        exit;
                    }
                } catch (Exception $e) {
                    $message = 'Errore nell\'eliminazione del pannello';
                    $messageType = 'error';
                }
                break;
        }
    }
}

// Carica geni disponibili
$availableGenes = $db->select("
    SELECT g.*, gg.nome as gruppo_nome 
    FROM geni g 
    LEFT JOIN gruppi_geni gg ON g.gruppo_id = gg.id 
    WHERE g.attivo = 1 
    ORDER BY gg.ordine, gg.nome, g.nome
");

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
                <h1 class="h2">Gestione Pannelli Genetici</h1>
                <div class="btn-toolbar mb-2 mb-md-0">
                    <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#panelModal">
                        <i class="bi bi-plus-circle"></i> Nuovo Pannello
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
            
            <!-- Filtri -->
            <div class="row mb-3">
                <div class="col-md-6">
                    <input type="text" class="form-control" id="searchPanels" placeholder="Cerca pannello...">
                </div>
                <div class="col-md-3">
                    <select class="form-select" id="filterStatus">
                        <option value="">Tutti gli stati</option>
                        <option value="1">Attivi</option>
                        <option value="0">Non attivi</option>
                    </select>
                </div>
            </div>
            
            <!-- Tabella pannelli -->
            <div class="table-responsive">
                <table class="table table-striped table-hover" id="panelsTable">
                    <thead>
                        <tr>
                            <th>Nome</th>
                            <th>Descrizione</th>
                            <th>N° Geni</th>
                            <th>Prezzo</th>
                            <th>Stato</th>
                            <th>Azioni</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $panels = $db->select("
                            SELECT pg.*, COUNT(pgn.gene_id) as num_geni
                            FROM pannelli_genetici pg
                            LEFT JOIN pannelli_geni pgn ON pg.id = pgn.pannello_id
                            GROUP BY pg.id
                            ORDER BY pg.nome
                        ");
                        
                        foreach ($panels as $panel):
                        ?>
                        <tr data-stato="<?php echo $panel['attivo']; ?>">
                            <td>
                                <strong><?php echo htmlspecialchars($panel['nome']); ?></strong>
                            </td>
                            <td><?php echo htmlspecialchars($panel['descrizione'] ?? '-'); ?></td>
                            <td>
                                <span class="badge bg-info">
                                    <?php echo $panel['num_geni']; ?> geni
                                </span>
                            </td>
                            <td>€ <?php echo number_format($panel['prezzo'], 2, ',', '.'); ?></td>
                            <td>
                                <?php if ($panel['attivo']): ?>
                                    <span class="badge bg-success">Attivo</span>
                                <?php else: ?>
                                    <span class="badge bg-danger">Non attivo</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <button type="button" class="btn btn-sm btn-info" 
                                        onclick="viewPanel(<?php echo $panel['id']; ?>)">
                                    <i class="bi bi-eye"></i>
                                </button>
                                <button type="button" class="btn btn-sm btn-primary" 
                                        onclick="editPanel(<?php echo $panel['id']; ?>)">
                                    <i class="bi bi-pencil"></i>
                                </button>
                                <button type="button" class="btn btn-sm btn-danger" 
                                        onclick="deletePanel(<?php echo $panel['id']; ?>, '<?php echo htmlspecialchars($panel['nome']); ?>')">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </main>
    </div>
</div>

<!-- Modal Creazione/Modifica Pannello -->
<div class="modal fade" id="panelModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="panelModalTitle">Nuovo Pannello</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="post" id="panelForm">
                <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                <input type="hidden" name="action" value="create">
                <input type="hidden" name="panel_id" id="panel_id">
                
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="nome" class="form-label">Nome *</label>
                        <input type="text" class="form-control" id="nome" name="nome" required maxlength="100">
                    </div>
                    
                    <div class="mb-3">
                        <label for="descrizione" class="form-label">Descrizione</label>
                        <textarea class="form-control" id="descrizione" name="descrizione" rows="3"></textarea>
                    </div>
                    
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <label for="prezzo" class="form-label">Prezzo (€)</label>
                            <input type="number" class="form-control" id="prezzo" name="prezzo" 
                                   step="0.01" min="0" value="0">
                        </div>
                        <div class="col-md-6">
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
                        <label class="form-label">Geni inclusi nel pannello</label>
                        <div class="row">
                            <div class="col-md-6">
                                <input type="text" class="form-control mb-2" id="searchGenesList" 
                                       placeholder="Cerca geni...">
                                <div class="genes-available" style="max-height: 300px; overflow-y: auto;">
                                    <?php
                                    $currentGroup = '';
                                    foreach ($availableGenes as $gene):
                                        if ($gene['gruppo_nome'] != $currentGroup):
                                            if ($currentGroup != '') echo '</div>';
                                            $currentGroup = $gene['gruppo_nome'];
                                    ?>
                                        <div class="gene-group mb-3">
                                            <h6 class="text-muted"><?php echo htmlspecialchars($currentGroup ?: 'Senza gruppo'); ?></h6>
                                    <?php endif; ?>
                                    
                                        <div class="form-check gene-item" data-gene-name="<?php echo htmlspecialchars(strtolower($gene['nome'])); ?>">
                                            <input class="form-check-input gene-checkbox" type="checkbox" 
                                                   name="geni[]" value="<?php echo $gene['id']; ?>" 
                                                   id="gene_<?php echo $gene['id']; ?>">
                                            <label class="form-check-label" for="gene_<?php echo $gene['id']; ?>">
                                                <?php if ($gene['sigla']): ?>
                                                    <strong><?php echo htmlspecialchars($gene['sigla']); ?></strong> -
                                                <?php endif; ?>
                                                <?php echo htmlspecialchars($gene['nome']); ?>
                                                <small class="text-muted">
                                                    (€ <?php echo number_format($gene['prezzo'], 2, ',', '.'); ?>)
                                                </small>
                                            </label>
                                        </div>
                                    
                                    <?php endforeach; ?>
                                    <?php if ($currentGroup != '') echo '</div>'; ?>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="selected-genes-summary">
                                    <h6>Riepilogo Selezione</h6>
                                    <div class="mb-2">
                                        <span class="badge bg-primary">
                                            <span id="selectedCount">0</span> geni selezionati
                                        </span>
                                    </div>
                                    <div id="selectedGenesList" class="small" style="max-height: 250px; overflow-y: auto;">
                                        <p class="text-muted">Nessun gene selezionato</p>
                                    </div>
                                    <div class="mt-2">
                                        <strong>Costo totale geni: € <span id="totalCost">0,00</span></strong>
                                    </div>
                                </div>
                            </div>
                        </div>
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

<!-- Modal Visualizzazione Pannello -->
<div class="modal fade" id="viewPanelModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Dettagli Pannello</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="viewPanelContent">
                <!-- Contenuto caricato via AJAX -->
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Chiudi</button>
            </div>
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
                <p>Sei sicuro di voler eliminare il pannello <strong id="deletePanelName"></strong>?</p>
                <p class="text-danger">Questa azione non può essere annullata.</p>
            </div>
            <div class="modal-footer">
                <form method="post" id="deleteForm">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="panel_id" id="deletePanelId">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annulla</button>
                    <button type="submit" class="btn btn-danger">Elimina</button>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
// Gestione selezione geni
document.addEventListener('DOMContentLoaded', function() {
    const checkboxes = document.querySelectorAll('.gene-checkbox');
    checkboxes.forEach(checkbox => {
        checkbox.addEventListener('change', updateSelectedGenes);
    });
    
    // Ricerca geni nella lista
    document.getElementById('searchGenesList').addEventListener('keyup', function() {
        const searchText = this.value.toLowerCase();
        const geneItems = document.querySelectorAll('.gene-item');
        
        geneItems.forEach(item => {
            const geneName = item.dataset.geneName;
            item.style.display = geneName.includes(searchText) ? '' : 'none';
        });
    });
});

// Aggiorna lista geni selezionati
function updateSelectedGenes() {
    const selected = document.querySelectorAll('.gene-checkbox:checked');
    const selectedCount = document.getElementById('selectedCount');
    const selectedList = document.getElementById('selectedGenesList');
    const totalCost = document.getElementById('totalCost');
    
    selectedCount.textContent = selected.length;
    
    if (selected.length === 0) {
        selectedList.innerHTML = '<p class="text-muted">Nessun gene selezionato</p>';
        totalCost.textContent = '0,00';
    } else {
        let html = '<ul class="list-unstyled">';
        let total = 0;
        
        selected.forEach(checkbox => {
            const label = checkbox.nextElementSibling;
            const priceMatch = label.textContent.match(/€\s*([\d,]+)/);
            if (priceMatch) {
                total += parseFloat(priceMatch[1].replace(',', '.'));
            }
            html += `<li>• ${label.textContent.trim()}</li>`;
        });
        
        html += '</ul>';
        selectedList.innerHTML = html;
        totalCost.textContent = total.toFixed(2).replace('.', ',');
    }
}

// Visualizza pannello
function viewPanel(id) {
    fetch(`../api/v1/panels.php?id=${id}&details=true`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const panel = data.data;
                let html = `
                    <h5>${panel.nome}</h5>
                    <p>${panel.descrizione || 'Nessuna descrizione'}</p>
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <strong>Prezzo:</strong> € ${panel.prezzo.toFixed(2).replace('.', ',')}
                        </div>
                        <div class="col-md-6">
                            <strong>Stato:</strong> 
                            <span class="badge bg-${panel.attivo ? 'success' : 'danger'}">
                                ${panel.attivo ? 'Attivo' : 'Non attivo'}
                            </span>
                        </div>
                    </div>
                    <h6>Geni inclusi (${panel.geni.length})</h6>
                    <div class="table-responsive">
                        <table class="table table-sm">
                            <thead>
                                <tr>
                                    <th>Sigla</th>
                                    <th>Nome</th>
                                    <th>Gruppo</th>
                                    <th>Prezzo</th>
                                </tr>
                            </thead>
                            <tbody>
                `;
                
                let totalGeni = 0;
                panel.geni.forEach(gene => {
                    totalGeni += parseFloat(gene.prezzo);
                    html += `
                        <tr>
                            <td>${gene.sigla || '-'}</td>
                            <td>${gene.nome}</td>
                            <td>${gene.gruppo_nome || '-'}</td>
                            <td>€ ${gene.prezzo.toFixed(2).replace('.', ',')}</td>
                        </tr>
                    `;
                });
                
                html += `
                            </tbody>
                            <tfoot>
                                <tr>
                                    <th colspan="3">Totale geni</th>
                                    <th>€ ${totalGeni.toFixed(2).replace('.', ',')}</th>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                `;
                
                document.getElementById('viewPanelContent').innerHTML = html;
                const modal = new bootstrap.Modal(document.getElementById('viewPanelModal'));
                modal.show();
            }
        })
        .catch(error => {
            console.error('Errore:', error);
            alert('Errore nel caricamento dei dati del pannello');
        });
}

// Modifica pannello
function editPanel(id) {
    fetch(`../api/v1/panels.php?id=${id}`)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                const panel = data.data;
                
                // Reset form
                document.getElementById('panelForm').reset();
                
                // Popola form
                document.getElementById('panel_id').value = panel.id;
                document.getElementById('nome').value = panel.nome;
                document.getElementById('descrizione').value = panel.descrizione || '';
                document.getElementById('prezzo').value = panel.prezzo;
                document.getElementById('attivo').checked = panel.attivo == 1;
                
                // Deseleziona tutti i geni
                document.querySelectorAll('.gene-checkbox').forEach(cb => cb.checked = false);
                
                // Seleziona i geni del pannello
                if (panel.gene_ids) {
                    panel.gene_ids.forEach(geneId => {
                        const checkbox = document.getElementById(`gene_${geneId}`);
                        if (checkbox) checkbox.checked = true;
                    });
                }
                
                // Aggiorna conteggio
                updateSelectedGenes();
                
                // Cambia action e titolo
                document.querySelector('#panelForm input[name="action"]').value = 'update';
                document.getElementById('panelModalTitle').textContent = 'Modifica Pannello';
                
                // Mostra modal
                const modal = new bootstrap.Modal(document.getElementById('panelModal'));
                modal.show();
            }
        })
        .catch(error => {
            console.error('Errore:', error);
            alert('Errore nel caricamento dei dati del pannello');
        });
}

// Elimina pannello
function deletePanel(id, nome) {
    document.getElementById('deletePanelId').value = id;
    document.getElementById('deletePanelName').textContent = nome;
    
    const modal = new bootstrap.Modal(document.getElementById('deleteModal'));
    modal.show();
}

// Reset form quando si chiude il modal
document.getElementById('panelModal').addEventListener('hidden.bs.modal', function () {
    document.getElementById('panelForm').reset();
    document.getElementById('panel_id').value = '';
    document.querySelector('#panelForm input[name="action"]').value = 'create';
    document.getElementById('panelModalTitle').textContent = 'Nuovo Pannello';
    
    // Deseleziona tutti i geni
    document.querySelectorAll('.gene-checkbox').forEach(cb => cb.checked = false);
    updateSelectedGenes();
});

// Filtri tabella
document.getElementById('searchPanels').addEventListener('keyup', filterTable);
document.getElementById('filterStatus').addEventListener('change', filterTable);

function filterTable() {
    const searchText = document.getElementById('searchPanels').value.toLowerCase();
    const filterStatus = document.getElementById('filterStatus').value;
    
    const rows = document.querySelectorAll('#panelsTable tbody tr');
    
    rows.forEach(row => {
        const nome = row.cells[0].textContent.toLowerCase();
        const descrizione = row.cells[1].textContent.toLowerCase();
        const stato = row.dataset.stato;
        
        let visible = true;
        
        // Filtro testo
        if (searchText && !nome.includes(searchText) && !descrizione.includes(searchText)) {
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

<?php require_once '../templates/footer.php'; ?>
