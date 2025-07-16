<?php
/**
 * SerteX+ - Template Sidebar
 * Menu laterale dinamico basato sul ruolo utente
 */

$currentPage = basename($_SERVER['PHP_SELF']);
$userType = $_SESSION['user_type'] ?? '';
?>

<!-- Sidebar -->
<nav id="sidebar" class="col-md-3 col-lg-2 d-md-block bg-light sidebar">
    <div class="position-sticky">
        <!-- Logo -->
        <div class="sidebar-header">
            <div class="logo-wrapper">
                <i class="fas fa-dna logo-icon"></i>
                <h4 class="logo-text"><?php echo SITE_NAME; ?></h4>
            </div>
        </div>
        
        <!-- Menu Items -->
        <ul class="nav flex-column">
            <?php if ($userType === 'amministratore'): ?>
                <!-- Menu Amministratore -->
                <li class="nav-item">
                    <a class="nav-link <?php echo $currentPage == 'dashboard.php' ? 'active' : ''; ?>" 
                       href="<?php echo ROOT_PATH; ?>admin/dashboard.php">
                        <i class="fas fa-tachometer-alt"></i>
                        <span>Dashboard</span>
                    </a>
                </li>
                
                <li class="nav-item">
                    <a class="nav-link <?php echo $currentPage == 'users.php' ? 'active' : ''; ?>" 
                       href="<?php echo ROOT_PATH; ?>admin/users.php">
                        <i class="fas fa-users"></i>
                        <span>Gestione Utenti</span>
                    </a>
                </li>
                
                <li class="nav-item">
                    <a class="nav-link" href="#testMenu" data-bs-toggle="collapse">
                        <i class="fas fa-vial"></i>
                        <span>Configurazione Test</span>
                        <i class="fas fa-chevron-down ms-auto"></i>
                    </a>
                    <div class="collapse <?php echo in_array($currentPage, ['genes.php', 'panels.php', 'foods.php']) ? 'show' : ''; ?>" 
                         id="testMenu">
                        <ul class="nav flex-column ms-3">
                            <li class="nav-item">
                                <a class="nav-link <?php echo $currentPage == 'genes.php' ? 'active' : ''; ?>" 
                                   href="<?php echo ROOT_PATH; ?>admin/genes.php">
                                    <i class="fas fa-dna"></i> Geni
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link <?php echo $currentPage == 'panels.php' ? 'active' : ''; ?>" 
                                   href="<?php echo ROOT_PATH; ?>admin/panels.php">
                                    <i class="fas fa-layer-group"></i> Pannelli
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link <?php echo $currentPage == 'foods.php' ? 'active' : ''; ?>" 
                                   href="<?php echo ROOT_PATH; ?>admin/foods.php">
                                    <i class="fas fa-utensils"></i> Alimenti
                                </a>
                            </li>
                        </ul>
                    </div>
                </li>
                
                <li class="nav-item">
                    <a class="nav-link <?php echo $currentPage == 'price-lists.php' ? 'active' : ''; ?>" 
                       href="<?php echo ROOT_PATH; ?>admin/price-lists.php">
                        <i class="fas fa-tags"></i>
                        <span>Listini Prezzi</span>
                    </a>
                </li>
                
                <li class="nav-item">
                    <a class="nav-link <?php echo $currentPage == 'reports-config.php' ? 'active' : ''; ?>" 
                       href="<?php echo ROOT_PATH; ?>admin/reports-config.php">
                        <i class="fas fa-file-alt"></i>
                        <span>Template Documenti</span>
                    </a>
                </li>
                
                <li class="nav-item">
                    <a class="nav-link" href="#systemMenu" data-bs-toggle="collapse">
                        <i class="fas fa-cogs"></i>
                        <span>Sistema</span>
                        <i class="fas fa-chevron-down ms-auto"></i>
                    </a>
                    <div class="collapse <?php echo in_array($currentPage, ['settings.php', 'backup.php', 'logs.php']) ? 'show' : ''; ?>" 
                         id="systemMenu">
                        <ul class="nav flex-column ms-3">
                            <li class="nav-item">
                                <a class="nav-link <?php echo $currentPage == 'settings.php' ? 'active' : ''; ?>" 
                                   href="<?php echo ROOT_PATH; ?>admin/settings.php">
                                    <i class="fas fa-sliders-h"></i> Impostazioni
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link <?php echo $currentPage == 'backup.php' ? 'active' : ''; ?>" 
                                   href="<?php echo ROOT_PATH; ?>admin/backup.php">
                                    <i class="fas fa-download"></i> Backup
                                </a>
                            </li>
                            <li class="nav-item">
                                <a class="nav-link <?php echo $currentPage == 'logs.php' ? 'active' : ''; ?>" 
                                   href="<?php echo ROOT_PATH; ?>admin/logs.php">
                                    <i class="fas fa-history"></i> Log Sistema
                                </a>
                            </li>
                        </ul>
                    </div>
                </li>
                
            <?php elseif ($userType === 'biologo'): ?>
                <!-- Menu Biologo -->
                <li class="nav-item">
                    <a class="nav-link <?php echo $currentPage == 'dashboard.php' ? 'active' : ''; ?>" 
                       href="<?php echo ROOT_PATH; ?>biologo/dashboard.php">
                        <i class="fas fa-tachometer-alt"></i>
                        <span>Dashboard</span>
                    </a>
                </li>
                
                <li class="nav-item">
                    <a class="nav-link <?php echo $currentPage == 'tests.php' ? 'active' : ''; ?>" 
                       href="<?php echo ROOT_PATH; ?>biologo/tests.php">
                        <i class="fas fa-vial"></i>
                        <span>Test da Processare</span>
                        <?php
                        $pendingTests = getPendingTestsCount();
                        if ($pendingTests > 0):
                        ?>
                        <span class="badge bg-warning rounded-pill ms-auto"><?php echo $pendingTests; ?></span>
                        <?php endif; ?>
                    </a>
                </li>
                
                <li class="nav-item">
                    <a class="nav-link <?php echo $currentPage == 'results.php' ? 'active' : ''; ?>" 
                       href="<?php echo ROOT_PATH; ?>biologo/results.php">
                        <i class="fas fa-edit"></i>
                        <span>Inserisci Risultati</span>
                    </a>
                </li>
                
                <li class="nav-item">
                    <a class="nav-link <?php echo $currentPage == 'reports.php' ? 'active' : ''; ?>" 
                       href="<?php echo ROOT_PATH; ?>biologo/reports.php">
                        <i class="fas fa-file-medical"></i>
                        <span>Referti</span>
                    </a>
                </li>
                
                <li class="nav-item">
                    <a class="nav-link <?php echo $currentPage == 'statistics.php' ? 'active' : ''; ?>" 
                       href="<?php echo ROOT_PATH; ?>biologo/statistics.php">
                        <i class="fas fa-chart-bar"></i>
                        <span>Statistiche</span>
                    </a>
                </li>
                
            <?php elseif ($userType === 'professionista'): ?>
                <!-- Menu Professionista -->
                <li class="nav-item">
                    <a class="nav-link <?php echo $currentPage == 'dashboard.php' ? 'active' : ''; ?>" 
                       href="<?php echo ROOT_PATH; ?>professionista/dashboard.php">
                        <i class="fas fa-tachometer-alt"></i>
                        <span>Dashboard</span>
                    </a>
                </li>
                
                <li class="nav-item">
                    <a class="nav-link <?php echo $currentPage == 'patients.php' ? 'active' : ''; ?>" 
                       href="<?php echo ROOT_PATH; ?>professionista/patients.php">
                        <i class="fas fa-users"></i>
                        <span>I Miei Pazienti</span>
                    </a>
                </li>
                
                <li class="nav-item">
                    <a class="nav-link <?php echo $currentPage == 'tests.php' ? 'active' : ''; ?>" 
                       href="<?php echo ROOT_PATH; ?>professionista/tests.php">
                        <i class="fas fa-vial"></i>
                        <span>Test</span>
                    </a>
                </li>
                
                <li class="nav-item">
                    <a class="nav-link <?php echo $currentPage == 'reports.php' ? 'active' : ''; ?>" 
                       href="<?php echo ROOT_PATH; ?>professionista/reports.php">
                        <i class="fas fa-file-pdf"></i>
                        <span>Referti</span>
                        <?php
                        $newReports = getNewReportsCount();
                        if ($newReports > 0):
                        ?>
                        <span class="badge bg-success rounded-pill ms-auto"><?php echo $newReports; ?></span>
                        <?php endif; ?>
                    </a>
                </li>
                
                <li class="nav-item">
                    <a class="nav-link <?php echo $currentPage == 'invoices.php' ? 'active' : ''; ?>" 
                       href="<?php echo ROOT_PATH; ?>professionista/invoices.php">
                        <i class="fas fa-file-invoice"></i>
                        <span>Le Mie Fatture</span>
                    </a>
                </li>
                
            <?php elseif ($userType === 'commerciale'): ?>
                <!-- Menu Commerciale -->
                <li class="nav-item">
                    <a class="nav-link <?php echo $currentPage == 'dashboard.php' ? 'active' : ''; ?>" 
                       href="<?php echo ROOT_PATH; ?>commerciale/dashboard.php">
                        <i class="fas fa-tachometer-alt"></i>
                        <span>Dashboard</span>
                    </a>
                </li>
                
                <li class="nav-item">
                    <a class="nav-link <?php echo $currentPage == 'invoices.php' ? 'active' : ''; ?>" 
                       href="<?php echo ROOT_PATH; ?>commerciale/invoices.php">
                        <i class="fas fa-file-invoice-dollar"></i>
                        <span>Fatture</span>
                    </a>
                </li>
                
                <li class="nav-item">
                    <a class="nav-link <?php echo $currentPage == 'orders.php' ? 'active' : ''; ?>" 
                       href="<?php echo ROOT_PATH; ?>commerciale/orders.php">
                        <i class="fas fa-shopping-cart"></i>
                        <span>Ordini</span>
                    </a>
                </li>
                
                <li class="nav-item">
                    <a class="nav-link <?php echo $currentPage == 'statistics.php' ? 'active' : ''; ?>" 
                       href="<?php echo ROOT_PATH; ?>commerciale/statistics.php">
                        <i class="fas fa-chart-line"></i>
                        <span>Statistiche</span>
                    </a>
                </li>
                
                <li class="nav-item">
                    <a class="nav-link <?php echo $currentPage == 'reports.php' ? 'active' : ''; ?>" 
                       href="<?php echo ROOT_PATH; ?>commerciale/reports.php">
                        <i class="fas fa-file-excel"></i>
                        <span>Report</span>
                    </a>
                </li>
            <?php endif; ?>
            
            <!-- Menu comune a tutti -->
            <li class="nav-item mt-3 pt-3 border-top">
                <a class="nav-link <?php echo $currentPage == 'profile.php' ? 'active' : ''; ?>" 
                   href="<?php echo ROOT_PATH; ?>profile.php">
                    <i class="fas fa-user-circle"></i>
                    <span>Il Mio Profilo</span>
                </a>
            </li>
            
            <li class="nav-item">
                <a class="nav-link <?php echo $currentPage == 'help.php' ? 'active' : ''; ?>" 
                   href="<?php echo ROOT_PATH; ?>help.php">
                    <i class="fas fa-question-circle"></i>
                    <span>Aiuto</span>
                </a>
            </li>
        </ul>
        
        <!-- Footer Sidebar -->
        <div class="sidebar-footer">
            <div class="small text-muted px-3">
                Versione 1.0.0<br>
                &copy; <?php echo date('Y'); ?> SerteX+
            </div>
        </div>
    </div>
</nav>

<style>
/* Sidebar Styles */
.sidebar {
    position: fixed;
    top: var(--header-height);
    bottom: 0;
    left: 0;
    z-index: 100;
    padding: 0;
    box-shadow: inset -1px 0 0 rgba(0, 0, 0, .1);
    width: var(--sidebar-width);
    overflow-y: auto;
    background-color: #fff !important;
}

.sidebar-header {
    padding: 20px;
    border-bottom: 1px solid #e9ecef;
    background-color: #f8f9fa;
}

.logo-wrapper {
    display: flex;
    align-items: center;
    gap: 10px;
}

.logo-icon {
    font-size: 28px;
    color: var(--primary-color);
}

.logo-text {
    margin: 0;
    font-weight: 700;
    color: #2c3e50;
}

.sidebar .nav {
    padding: 15px 0;
}

.sidebar .nav-link {
    color: #666;
    padding: 10px 20px;
    display: flex;
    align-items: center;
    gap: 12px;
    transition: all 0.3s ease;
    position: relative;
    text-decoration: none;
}

.sidebar .nav-link:hover {
    color: var(--primary-color);
    background-color: #f8f9fa;
}

.sidebar .nav-link.active {
    color: var(--primary-color);
    background-color: rgba(25, 118, 210, 0.1);
    font-weight: 500;
}

.sidebar .nav-link.active::before {
    content: '';
    position: absolute;
    left: 0;
    top: 0;
    bottom: 0;
    width: 3px;
    background-color: var(--primary-color);
}

.sidebar .nav-link i {
    width: 20px;
    text-align: center;
    font-size: 16px;
}

.sidebar .nav-link span {
    flex: 1;
}

.sidebar .badge {
    font-size: 11px;
}

/* Collapse menu */
.sidebar .collapse .nav-link {
    padding-left: 48px;
    font-size: 14px;
}

.sidebar .collapse .nav-link::before {
    display: none;
}

/* Sidebar footer */
.sidebar-footer {
    position: absolute;
    bottom: 0;
    width: 100%;
    padding: 20px;
    border-top: 1px solid #e9ecef;
    background-color: #f8f9fa;
}

/* Scrollbar customization */
.sidebar::-webkit-scrollbar {
    width: 6px;
}

.sidebar::-webkit-scrollbar-track {
    background: #f1f1f1;
}

.sidebar::-webkit-scrollbar-thumb {
    background: #888;
    border-radius: 3px;
}

.sidebar::-webkit-scrollbar-thumb:hover {
    background: #555;
}

/* Responsive */
@media (max-width: 768px) {
    .sidebar {
        width: 100%;
        height: 100%;
        transform: translateX(-100%);
        transition: transform 0.3s ease;
    }
    
    .sidebar.show {
        transform: translateX(0);
    }
}
</style>

<?php
// Funzioni helper per badge
function getPendingTestsCount() {
    global $db;
    $stmt = $db->query("
        SELECT COUNT(*) FROM test 
        WHERE stato IN ('richiesto', 'in_lavorazione', 'eseguito')
    ");
    return $stmt->fetchColumn();
}

function getNewReportsCount() {
    global $db, $professionistaId;
    $stmt = $db->prepare("
        SELECT COUNT(*) 
        FROM test t
        JOIN referti r ON t.id = r.test_id
        WHERE t.professionista_id = ? 
        AND t.stato = 'firmato'
        AND DATEDIFF(NOW(), r.data_firma) <= 7
    ");
    $stmt->execute([$professionistaId ?? 0]);
    return $stmt->fetchColumn();
}
?>