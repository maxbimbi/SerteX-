<!-- Footer -->
    <footer class="footer mt-auto py-3 bg-light">
        <div class="container-fluid">
            <div class="row">
                <div class="col-md-6">
                    <span class="text-muted">
                        &copy; <?php echo date('Y'); ?> <?php echo SITE_NAME; ?> - 
                        Tutti i diritti riservati
                    </span>
                </div>
                <div class="col-md-6 text-md-end">
                    <span class="text-muted">
                        <i class="fas fa-shield-alt"></i> Sistema sicuro e conforme GDPR | 
                        <a href="<?php echo ROOT_PATH; ?>privacy.php" class="text-muted">Privacy</a> | 
                        <a href="<?php echo ROOT_PATH; ?>terms.php" class="text-muted">Termini</a>
                    </span>
                </div>
            </div>
        </div>
    </footer>
    
    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <!-- jQuery (opzionale, per compatibilità) -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    
    <!-- Common Scripts -->
    <script>
        // Toggle sidebar
        function toggleSidebar() {
            const sidebar = document.getElementById('sidebar');
            sidebar.classList.toggle('show');
            
            // Save state
            localStorage.setItem('sidebarCollapsed', sidebar.classList.contains('show') ? 'false' : 'true');
        }
        
        // Restore sidebar state
        document.addEventListener('DOMContentLoaded', function() {
            const sidebarCollapsed = localStorage.getItem('sidebarCollapsed');
            if (sidebarCollapsed === 'true' && window.innerWidth > 768) {
                document.getElementById('sidebar').classList.remove('show');
            }
        });
        
        // Quick search
        function openQuickSearch() {
            const modal = new bootstrap.Modal(document.getElementById('quickSearchModal'));
            modal.show();
            
            // Focus on search input when modal opens
            document.getElementById('quickSearchModal').addEventListener('shown.bs.modal', function () {
                document.getElementById('globalSearch').focus();
            });
        }
        
        // Global search functionality
        let searchTimeout;
        document.getElementById('globalSearch')?.addEventListener('input', function(e) {
            clearTimeout(searchTimeout);
            const query = e.target.value.trim();
            const resultsDiv = document.getElementById('globalSearchResults');
            
            if (query.length < 2) {
                resultsDiv.innerHTML = '';
                return;
            }
            
            searchTimeout = setTimeout(() => {
                // Show loading
                resultsDiv.innerHTML = '<div class="text-center"><div class="spinner-border" role="status"></div></div>';
                
                // Perform search
                fetch(`<?php echo ROOT_PATH; ?>api/global-search.php?q=${encodeURIComponent(query)}`)
                    .then(response => response.json())
                    .then(data => {
                        let html = '';
                        
                        // Patients results
                        if (data.patients && data.patients.length > 0) {
                            html += '<h6 class="text-muted mb-2">Pazienti</h6>';
                            html += '<div class="list-group mb-3">';
                            data.patients.forEach(patient => {
                                html += `
                                    <a href="${ROOT_PATH}patients.php?id=${patient.id}" class="list-group-item list-group-item-action">
                                        <div class="d-flex justify-content-between">
                                            <div>
                                                <strong>${patient.cognome} ${patient.nome}</strong>
                                                <br>
                                                <small class="text-muted">CF: ${patient.codice_fiscale}</small>
                                            </div>
                                            <small class="text-muted">Paziente</small>
                                        </div>
                                    </a>
                                `;
                            });
                            html += '</div>';
                        }
                        
                        // Tests results
                        if (data.tests && data.tests.length > 0) {
                            html += '<h6 class="text-muted mb-2">Test</h6>';
                            html += '<div class="list-group mb-3">';
                            data.tests.forEach(test => {
                                html += `
                                    <a href="${ROOT_PATH}test-details.php?id=${test.id}" class="list-group-item list-group-item-action">
                                        <div class="d-flex justify-content-between">
                                            <div>
                                                <strong>${test.codice}</strong>
                                                <br>
                                                <small class="text-muted">${test.tipo_test} - ${test.paziente_nome}</small>
                                            </div>
                                            <small class="text-muted">${test.stato}</small>
                                        </div>
                                    </a>
                                `;
                            });
                            html += '</div>';
                        }
                        
                        if (!data.patients?.length && !data.tests?.length) {
                            html = '<div class="text-center text-muted py-4">Nessun risultato trovato</div>';
                        }
                        
                        resultsDiv.innerHTML = html;
                    })
                    .catch(error => {
                        resultsDiv.innerHTML = '<div class="alert alert-danger">Errore nella ricerca</div>';
                    });
            }, 300);
        });
        
        // Tooltips initialization
        var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
        var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
            return new bootstrap.Tooltip(tooltipTriggerEl);
        });
        
        // Auto-hide alerts
        setTimeout(() => {
            const alerts = document.querySelectorAll('.alert-dismissible');
            alerts.forEach(alert => {
                if (!alert.classList.contains('no-auto-dismiss')) {
                    const bsAlert = new bootstrap.Alert(alert);
                    setTimeout(() => bsAlert.close(), 5000);
                }
            });
        }, 1000);
        
        // Confirm delete actions
        document.addEventListener('click', function(e) {
            if (e.target.classList.contains('confirm-delete')) {
                e.preventDefault();
                const message = e.target.dataset.message || 'Sei sicuro di voler eliminare questo elemento?';
                if (confirm(message)) {
                    if (e.target.tagName === 'A') {
                        window.location.href = e.target.href;
                    } else if (e.target.tagName === 'BUTTON') {
                        e.target.closest('form').submit();
                    }
                }
            }
        });
        
        // Form validation
        (function() {
            'use strict';
            
            // Fetch all forms that need validation
            var forms = document.querySelectorAll('.needs-validation');
            
            // Loop over them and prevent submission
            Array.prototype.slice.call(forms).forEach(function(form) {
                form.addEventListener('submit', function(event) {
                    if (!form.checkValidity()) {
                        event.preventDefault();
                        event.stopPropagation();
                    }
                    
                    form.classList.add('was-validated');
                }, false);
            });
        })();
        
        // Session timeout warning
        let sessionTimeout;
        let warningTimeout;
        const sessionDuration = <?php echo SESSION_LIFETIME * 1000; ?>; // Convert to milliseconds
        const warningTime = 5 * 60 * 1000; // 5 minutes before timeout
        
        function resetSessionTimer() {
            clearTimeout(sessionTimeout);
            clearTimeout(warningTimeout);
            
            // Set warning
            warningTimeout = setTimeout(() => {
                if (confirm('La tua sessione sta per scadere. Vuoi continuare?')) {
                    // Refresh session
                    fetch('<?php echo ROOT_PATH; ?>api/refresh-session.php')
                        .then(() => resetSessionTimer());
                }
            }, sessionDuration - warningTime);
            
            // Set timeout
            sessionTimeout = setTimeout(() => {
                alert('Sessione scaduta. Verrai reindirizzato alla pagina di login.');
                window.location.href = '<?php echo ROOT_PATH; ?>index.php?timeout=1';
            }, sessionDuration);
        }
        
        // Reset timer on user activity
        ['click', 'mousemove', 'keypress', 'scroll', 'touchstart'].forEach(event => {
            document.addEventListener(event, resetSessionTimer, true);
        });
        
        resetSessionTimer();
        
        // Print functionality
        function printContent(elementId) {
            const content = document.getElementById(elementId);
            const printWindow = window.open('', '', 'height=600,width=800');
            
            printWindow.document.write('<html><head><title>Stampa</title>');
            printWindow.document.write('<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">');
            printWindow.document.write('</head><body>');
            printWindow.document.write(content.innerHTML);
            printWindow.document.write('</body></html>');
            
            printWindow.document.close();
            printWindow.focus();
            
            setTimeout(() => {
                printWindow.print();
                printWindow.close();
            }, 250);
        }
        
        // Export table to CSV
        function exportTableToCSV(tableId, filename) {
            const table = document.getElementById(tableId);
            let csv = [];
            
            // Get headers
            const headers = [];
            table.querySelectorAll('thead th').forEach(th => {
                headers.push('"' + th.innerText.replace(/"/g, '""') + '"');
            });
            csv.push(headers.join(','));
            
            // Get rows
            table.querySelectorAll('tbody tr').forEach(tr => {
                const row = [];
                tr.querySelectorAll('td').forEach(td => {
                    row.push('"' + td.innerText.replace(/"/g, '""') + '"');
                });
                csv.push(row.join(','));
            });
            
            // Download
            const csvContent = '\ufeff' + csv.join('\n'); // UTF-8 BOM
            const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
            const link = document.createElement('a');
            
            if (navigator.msSaveBlob) { // IE 10+
                navigator.msSaveBlob(blob, filename);
            } else {
                link.href = URL.createObjectURL(blob);
                link.download = filename;
                link.style.display = 'none';
                document.body.appendChild(link);
                link.click();
                document.body.removeChild(link);
            }
        }
    </script>
    
    <?php if (isset($additionalScripts)): ?>
        <?php echo $additionalScripts; ?>
    <?php endif; ?>
    
    <!-- Performance monitoring -->
    <script>
        window.addEventListener('load', function() {
            const loadTime = performance.timing.loadEventEnd - performance.timing.navigationStart;
            console.log('Page load time:', loadTime + 'ms');
            
            // Send to analytics if needed
            if (typeof gtag !== 'undefined') {
                gtag('event', 'timing_complete', {
                    'name': 'load',
                    'value': loadTime
                });
            }
        });
    </script>
</body>
</html>