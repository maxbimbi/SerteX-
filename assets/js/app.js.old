// SerteX+ - Main JavaScript

// Inizializzazione
document.addEventListener('DOMContentLoaded', function() {
    initializeSidebar();
    initializeTooltips();
    initializePopovers();
    initializeDataTables();
    initializeFileUploads();
    initializeFormValidation();
    initializeNotifications();
    initializeCharts();
    checkPasswordExpiry();
    setupAutoLogout();
});

// Gestione Sidebar
function initializeSidebar() {
    const toggleBtn = document.querySelector('.toggle-sidebar');
    const sidebar = document.querySelector('.sidebar');
    const mainContent = document.querySelector('.main-content');
    
    if (toggleBtn && sidebar && mainContent) {
        // Recupera stato sidebar da localStorage
        const isCollapsed = localStorage.getItem('sidebarCollapsed') === 'true';
        if (isCollapsed) {
            sidebar.classList.add('collapsed');
            mainContent.classList.add('sidebar-collapsed');
        }
        
        toggleBtn.addEventListener('click', function() {
            sidebar.classList.toggle('collapsed');
            mainContent.classList.toggle('sidebar-collapsed');
            
            // Salva stato in localStorage
            localStorage.setItem('sidebarCollapsed', sidebar.classList.contains('collapsed'));
        });
    }
    
    // Evidenzia menu attivo
    const currentPath = window.location.pathname;
    document.querySelectorAll('.sidebar-menu a').forEach(link => {
        if (link.getAttribute('href') === currentPath) {
            link.classList.add('active');
        }
    });
}

// Inizializza tooltips Bootstrap
function initializeTooltips() {
    const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });
}

// Inizializza popovers Bootstrap
function initializePopovers() {
    const popoverTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="popover"]'));
    popoverTriggerList.map(function (popoverTriggerEl) {
        return new bootstrap.Popover(popoverTriggerEl);
    });
}

// Inizializza DataTables
function initializeDataTables() {
    if (typeof $.fn.DataTable !== 'undefined') {
        $('.datatable').DataTable({
            language: {
                url: '//cdn.datatables.net/plug-ins/1.10.24/i18n/Italian.json'
            },
            pageLength: 25,
            responsive: true,
            dom: 'Bfrtip',
            buttons: [
                'copy', 'csv', 'excel', 'pdf', 'print'
            ]
        });
    }
}

// Gestione upload file
function initializeFileUploads() {
    const fileUploadAreas = document.querySelectorAll('.file-upload-area');
    
    fileUploadAreas.forEach(area => {
        const input = area.querySelector('input[type="file"]');
        
        area.addEventListener('click', () => input.click());
        
        area.addEventListener('dragover', (e) => {
            e.preventDefault();
            area.classList.add('dragging');
        });
        
        area.addEventListener('dragleave', () => {
            area.classList.remove('dragging');
        });
        
        area.addEventListener('drop', (e) => {
            e.preventDefault();
            area.classList.remove('dragging');
            
            const files = e.dataTransfer.files;
            if (files.length > 0) {
                input.files = files;
                handleFileSelect(input);
            }
        });
        
        input.addEventListener('change', () => handleFileSelect(input));
    });
}

// Gestione selezione file
function handleFileSelect(input) {
    const files = input.files;
    const preview = input.closest('.form-group').querySelector('.file-preview');
    
    if (preview) {
        preview.innerHTML = '';
        
        for (let file of files) {
            const fileItem = document.createElement('div');
            fileItem.className = 'file-item';
            fileItem.innerHTML = `
                <i class="fas fa-file"></i>
                <span>${file.name}</span>
                <small>(${formatFileSize(file.size)})</small>
            `;
            preview.appendChild(fileItem);
        }
    }
}

// Formatta dimensione file
function formatFileSize(bytes) {
    if (bytes === 0) return '0 Bytes';
    
    const k = 1024;
    const sizes = ['Bytes', 'KB', 'MB', 'GB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    
    return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
}

// Validazione form
function initializeFormValidation() {
    const forms = document.querySelectorAll('.needs-validation');
    
    Array.from(forms).forEach(form => {
        form.addEventListener('submit', event => {
            if (!form.checkValidity()) {
                event.preventDefault();
                event.stopPropagation();
            }
            
            form.classList.add('was-validated');
        }, false);
    });
    
    // Validazione codice fiscale
    const cfInputs = document.querySelectorAll('input[data-validate="codice-fiscale"]');
    cfInputs.forEach(input => {
        input.addEventListener('blur', function() {
            const cf = this.value.toUpperCase();
            if (cf && !validateCodiceFiscale(cf)) {
                this.setCustomValidity('Codice fiscale non valido');
            } else {
                this.setCustomValidity('');
            }
        });
    });
}

// Validazione codice fiscale
function validateCodiceFiscale(cf) {
    if (cf.length !== 16) return false;
    
    const pattern = /^[A-Z]{6}[0-9]{2}[A-Z][0-9]{2}[A-Z][0-9]{3}[A-Z]$/;
    if (!pattern.test(cf)) return false;
    
    // Ulteriori controlli potrebbero essere aggiunti qui
    return true;
}

// Sistema notifiche
function initializeNotifications() {
    // Check per nuove notifiche ogni 30 secondi
    if (document.querySelector('.notification-bell')) {
        setInterval(checkNotifications, 30000);
    }
}

async function checkNotifications() {
    try {
        const response = await fetch('/api/v1/notifications.php');
        const data = await response.json();
        
        const bell = document.querySelector('.notification-bell');
        const badge = bell.querySelector('.notification-badge');
        
        if (data.count > 0) {
            badge.textContent = data.count;
            badge.style.display = 'block';
        } else {
            badge.style.display = 'none';
        }
    } catch (error) {
        console.error('Errore nel controllo notifiche:', error);
    }
}

// Mostra notifica toast
function showNotification(message, type = 'info') {
    const toastHtml = `
        <div class="toast align-items-center text-white bg-${type} border-0" role="alert">
            <div class="d-flex">
                <div class="toast-body">${message}</div>
                <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
            </div>
        </div>
    `;
    
    const toastContainer = document.querySelector('.toast-container') || createToastContainer();
    toastContainer.insertAdjacentHTML('beforeend', toastHtml);
    
    const toastElement = toastContainer.lastElementChild;
    const toast = new bootstrap.Toast(toastElement);
    toast.show();
    
    toastElement.addEventListener('hidden.bs.toast', () => toastElement.remove());
}

function createToastContainer() {
    const container = document.createElement('div');
    container.className = 'toast-container position-fixed bottom-0 end-0 p-3';
    document.body.appendChild(container);
    return container;
}

// Inizializza grafici
function initializeCharts() {
    // Grafico dashboard
    const dashboardChart = document.getElementById('dashboardChart');
    if (dashboardChart && typeof Chart !== 'undefined') {
        new Chart(dashboardChart, {
            type: 'line',
            data: {
                labels: getLastNDays(30),
                datasets: [{
                    label: 'Test Completati',
                    data: generateRandomData(30, 10, 50),
                    borderColor: '#1976d2',
                    backgroundColor: 'rgba(25, 118, 210, 0.1)',
                    tension: 0.4
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        display: false
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true
                    }
                }
            }
        });
    }
}

// Utility per grafici
function getLastNDays(n) {
    const dates = [];
    for (let i = n - 1; i >= 0; i--) {
        const date = new Date();
        date.setDate(date.getDate() - i);
        dates.push(date.toLocaleDateString('it-IT', { day: 'numeric', month: 'short' }));
    }
    return dates;
}

function generateRandomData(count, min, max) {
    return Array.from({ length: count }, () => 
        Math.floor(Math.random() * (max - min + 1)) + min
    );
}

// Controllo scadenza password
function checkPasswordExpiry() {
    const expiryWarning = document.getElementById('password-expiry-warning');
    if (expiryWarning) {
        const daysRemaining = parseInt(expiryWarning.dataset.days);
        if (daysRemaining <= 7 && daysRemaining > 0) {
            showNotification(
                `La tua password scadrà tra ${daysRemaining} giorni. Ti consigliamo di cambiarla.`,
                'warning'
            );
        }
    }
}

// Auto logout per inattività
function setupAutoLogout() {
    let timeout;
    const logoutTime = 30 * 60 * 1000; // 30 minuti
    
    function resetTimer() {
        clearTimeout(timeout);
        timeout = setTimeout(() => {
            showNotification('Sessione scaduta per inattività', 'warning');
            setTimeout(() => {
                window.location.href = '/logout.php';
            }, 3000);
        }, logoutTime);
    }
    
    // Eventi che resettano il timer
    ['mousedown', 'keypress', 'scroll', 'touchstart'].forEach(event => {
        document.addEventListener(event, resetTimer, true);
    });
    
    resetTimer();
}

// Funzioni utility globali
window.SerteX = {
    showSpinner: function() {
        const spinner = document.createElement('div');
        spinner.className = 'spinner-overlay';
        spinner.innerHTML = '<div class="spinner-border text-light" style="width: 3rem; height: 3rem;"></div>';
        document.body.appendChild(spinner);
    },
    
    hideSpinner: function() {
        const spinner = document.querySelector('.spinner-overlay');
        if (spinner) spinner.remove();
    },
    
    confirm: function(message, callback) {
        const modal = new bootstrap.Modal(document.getElementById('confirmModal'));
        document.querySelector('#confirmModal .modal-body').textContent = message;
        
        document.getElementById('confirmButton').onclick = function() {
            modal.hide();
            callback(true);
        };
        
        modal.show();
    },
    
    formatCurrency: function(amount) {
        return new Intl.NumberFormat('it-IT', {
            style: 'currency',
            currency: 'EUR'
        }).format(amount);
    },
    
    formatDate: function(date, format = 'short') {
        const options = format === 'short' 
            ? { day: '2-digit', month: '2-digit', year: 'numeric' }
            : { day: '2-digit', month: 'long', year: 'numeric', hour: '2-digit', minute: '2-digit' };
            
        return new Date(date).toLocaleDateString('it-IT', options);
    },
    
    copyToClipboard: function(text) {
        navigator.clipboard.writeText(text).then(() => {
            showNotification('Copiato negli appunti!', 'success');
        }).catch(() => {
            showNotification('Errore nella copia', 'danger');
        });
    },
    
    printElement: function(elementId) {
        const element = document.getElementById(elementId);
        const printWindow = window.open('', '', 'height=600,width=800');
        
        printWindow.document.write('<html><head><title>Stampa</title>');
        printWindow.document.write('<link rel="stylesheet" href="/assets/css/style.css">');
        printWindow.document.write('<link rel="stylesheet" href="/assets/css/bootstrap.min.css">');
        printWindow.document.write('</head><body>');
        printWindow.document.write(element.innerHTML);
        printWindow.document.write('</body></html>');
        
        printWindow.document.close();
        
        printWindow.onload = function() {
            printWindow.print();
            printWindow.close();
        };
    }
};