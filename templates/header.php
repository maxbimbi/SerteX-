<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="SerteX+ - Sistema di gestione per laboratorio di analisi genetiche">
    <title><?php echo isset($pageTitle) ? $pageTitle . ' - ' : ''; ?><?php echo SITE_NAME; ?></title>
    
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Font Awesome -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    
    <!-- Custom CSS -->
    <style>
        :root {
            --primary-color: <?php echo getConfig('colore_primario') ?? '#1976d2'; ?>;
            --secondary-color: <?php echo getConfig('colore_secondario') ?? '#dc004e'; ?>;
            --sidebar-width: 280px;
            --header-height: 60px;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            background-color: #f8f9fa;
        }
        
        /* Header */
        .main-header {
            background-color: #fff;
            box-shadow: 0 2px 4px rgba(0,0,0,.04);
            position: fixed;
            top: 0;
            right: 0;
            left: 0;
            z-index: 1030;
            height: var(--header-height);
        }
        
        .header-content {
            display: flex;
            align-items: center;
            justify-content: space-between;
            height: 100%;
            padding: 0 20px;
        }
        
        .header-left {
            display: flex;
            align-items: center;
        }
        
        .sidebar-toggle {
            background: none;
            border: none;
            font-size: 20px;
            color: #666;
            padding: 8px 12px;
            margin-right: 15px;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .sidebar-toggle:hover {
            background-color: #f0f0f0;
            border-radius: 4px;
        }
        
        .header-right {
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .header-nav {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .header-nav-item {
            position: relative;
        }
        
        .header-nav-link {
            display: flex;
            align-items: center;
            padding: 8px 12px;
            color: #666;
            text-decoration: none;
            border-radius: 4px;
            transition: all 0.3s ease;
        }
        
        .header-nav-link:hover {
            background-color: #f0f0f0;
            color: var(--primary-color);
        }
        
        .notification-badge {
            position: absolute;
            top: 4px;
            right: 4px;
            background-color: #dc3545;
            color: white;
            font-size: 11px;
            padding: 2px 5px;
            border-radius: 10px;
            min-width: 18px;
            text-align: center;
        }
        
        .user-menu {
            position: relative;
        }
        
        .user-menu-toggle {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 6px 12px;
            background-color: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 20px;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .user-menu-toggle:hover {
            background-color: #e9ecef;
        }
        
        .user-avatar {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background-color: var(--primary-color);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: bold;
            font-size: 14px;
        }
        
        /* Main layout */
        main {
            margin-top: var(--header-height);
            padding-top: 20px;
            min-height: calc(100vh - var(--header-height));
        }
        
        /* Utilities */
        .shadow {
            box-shadow: 0 0.125rem 0.25rem rgba(0,0,0,.075)!important;
        }
        
        .shadow-sm {
            box-shadow: 0 .125rem .25rem rgba(0,0,0,.04)!important;
        }
        
        /* Responsive */
        @media (max-width: 768px) {
            .sidebar {
                margin-left: -280px;
            }
            
            .sidebar.show {
                margin-left: 0;
            }
            
            main {
                margin-left: 0 !important;
                width: 100% !important;
            }
        }
        
        /* Print styles */
        @media print {
            .main-header,
            .sidebar,
            .no-print {
                display: none !important;
            }
            
            main {
                margin: 0 !important;
                padding: 0 !important;
            }
        }
    </style>
    
    <?php if (isset($additionalStyles)): ?>
        <?php echo $additionalStyles; ?>
    <?php endif; ?>
</head>
<body>
    <!-- Header -->
    <header class="main-header">
        <div class="header-content">
            <div class="header-left">
                <button class="sidebar-toggle" onclick="toggleSidebar()">
                    <i class="fas fa-bars"></i>
                </button>
                
                <h5 class="mb-0 d-none d-md-block">
                    <?php echo isset($pageTitle) ? $pageTitle : 'Dashboard'; ?>
                </h5>
            </div>
            
            <div class="header-right">
                <nav class="header-nav">
                    <!-- Notifiche -->
                    <div class="header-nav-item dropdown">
                        <a class="header-nav-link" href="#" data-bs-toggle="dropdown">
                            <i class="fas fa-bell"></i>
                            <?php
                            $notifications = getUnreadNotifications();
                            if ($notifications > 0):
                            ?>
                            <span class="notification-badge"><?php echo $notifications; ?></span>
                            <?php endif; ?>
                        </a>
                        <div class="dropdown-menu dropdown-menu-end">
                            <h6 class="dropdown-header">Notifiche</h6>
                            <div class="dropdown-divider"></div>
                            <?php if ($notifications > 0): ?>
                                <!-- Lista notifiche -->
                                <a class="dropdown-item" href="#">
                                    <i class="fas fa-vial text-primary me-2"></i>
                                    Nuovo test da processare
                                </a>
                            <?php else: ?>
                                <div class="dropdown-item-text text-muted">
                                    Nessuna nuova notifica
                                </div>
                            <?php endif; ?>
                            <div class="dropdown-divider"></div>
                            <a class="dropdown-item text-center" href="notifications.php">
                                Vedi tutte
                            </a>
                        </div>
                    </div>
                    
                    <!-- Quick Actions -->
                    <div class="header-nav-item">
                        <a class="header-nav-link" href="#" onclick="openQuickSearch()">
                            <i class="fas fa-search"></i>
                        </a>
                    </div>
                </nav>
                
                <!-- User Menu -->
                <div class="user-menu dropdown">
                    <div class="user-menu-toggle" data-bs-toggle="dropdown">
                        <div class="user-avatar">
                            <?php 
                            $initials = substr($_SESSION['user_name'], 0, 1) . 
                                       substr(strstr($_SESSION['user_name'], ' '), 1, 1);
                            echo strtoupper($initials);
                            ?>
                        </div>
                        <span class="d-none d-md-block">
                            <?php echo htmlspecialchars($_SESSION['user_name']); ?>
                        </span>
                        <i class="fas fa-chevron-down"></i>
                    </div>
                    
                    <div class="dropdown-menu dropdown-menu-end">
                        <h6 class="dropdown-header">
                            <?php echo htmlspecialchars($_SESSION['username']); ?>
                            <br>
                            <small class="text-muted">
                                <?php echo ucfirst($_SESSION['user_type']); ?>
                            </small>
                        </h6>
                        <div class="dropdown-divider"></div>
                        
                        <a class="dropdown-item" href="<?php echo ROOT_PATH; ?>profile.php">
                            <i class="fas fa-user me-2"></i> Il mio profilo
                        </a>
                        <a class="dropdown-item" href="<?php echo ROOT_PATH; ?>settings.php">
                            <i class="fas fa-cog me-2"></i> Impostazioni
                        </a>
                        
                        <?php if ($_SESSION['user_type'] === 'amministratore'): ?>
                        <div class="dropdown-divider"></div>
                        <a class="dropdown-item" href="<?php echo ROOT_PATH; ?>admin/settings.php">
                            <i class="fas fa-tools me-2"></i> Amministrazione
                        </a>
                        <?php endif; ?>
                        
                        <div class="dropdown-divider"></div>
                        <a class="dropdown-item text-danger" href="<?php echo ROOT_PATH; ?>index.php?action=logout">
                            <i class="fas fa-sign-out-alt me-2"></i> Esci
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </header>
    
    <!-- Quick Search Modal -->
    <div class="modal fade" id="quickSearchModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Ricerca Rapida</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="text" class="form-control form-control-lg" id="globalSearch" 
                           placeholder="Cerca pazienti, test, referti..." autofocus>
                    <div id="globalSearchResults" class="mt-3"></div>
                </div>
            </div>
        </div>
    </div>

<?php
// Funzione per ottenere notifiche non lette
function getUnreadNotifications() {
    // TODO: Implementare sistema notifiche
    return 0;
}
?>