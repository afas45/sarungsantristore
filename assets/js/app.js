/**
 * Kasir Ibtidaiyah - Core JavaScript
 * Fungsi utilitas umum untuk seluruh aplikasi
 */

// =============================================
// SIDEBAR TOGGLE
// =============================================
function toggleSidebar() {
    if (window.innerWidth < 1024) {
        const sidebar = document.getElementById('sidebar');
        const overlay = document.getElementById('sidebarOverlay');
        
        if (sidebar) {
            sidebar.classList.toggle('open');
        }
        if (overlay) {
            overlay.classList.toggle('active');
        }
    } else {
        const layout = document.querySelector('.admin-layout');
        if (layout) {
            layout.classList.toggle('sidebar-collapsed');
            const isCollapsed = layout.classList.contains('sidebar-collapsed');
            localStorage.setItem('sidebar-collapsed', isCollapsed ? 'true' : 'false');
        }
    }
}

// Close sidebar when clicking outside on mobile
document.addEventListener('click', function(e) {
    const sidebar = document.getElementById('sidebar');
    const toggle = document.getElementById('sidebarToggle');
    
    if (sidebar && sidebar.classList.contains('open') && 
        !sidebar.contains(e.target) && 
        toggle && !toggle.contains(e.target)) {
        toggleSidebar();
    }
});

// =============================================
// DROPDOWN TOGGLE
// =============================================
function toggleDropdown(btn) {
    const dropdown = btn.closest('.dropdown');
    const menu = dropdown.querySelector('.dropdown-menu');
    
    // Close other dropdowns
    document.querySelectorAll('.dropdown-menu.active').forEach(m => {
        if (m !== menu) m.classList.remove('active');
    });
    
    menu.classList.toggle('active');
}

// Close dropdown when clicking outside
document.addEventListener('click', function(e) {
    if (!e.target.closest('.dropdown')) {
        document.querySelectorAll('.dropdown-menu.active').forEach(m => {
            m.classList.remove('active');
        });
    }
});

// =============================================
// TOAST NOTIFICATIONS
// =============================================
function showToast(message, type = 'success', duration = 4000) {
    const container = document.getElementById('toastContainer');
    if (!container) return;
    
    const toast = document.createElement('div');
    toast.className = `toast toast-${type}`;
    
    const icons = {
        success: '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#10b981" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>',
        error: '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#ef4444" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>',
        warning: '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#f59e0b" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>',
        info: '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#3b82f6" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>'
    };
    
    toast.innerHTML = `
        ${icons[type] || icons.info}
        <span class="toast-message">${message}</span>
        <button class="toast-close" onclick="this.parentElement.remove()">&times;</button>
    `;
    
    container.appendChild(toast);
    
    // Auto-remove
    setTimeout(() => {
        if (document.body.contains(toast)) {
            toast.style.opacity = '0';
            toast.style.transform = 'translateX(30px)';
            toast.style.transition = 'all 0.3s ease';
            setTimeout(() => toast.remove(), 300);
        }
    }, duration);
    
    // [Analytics] Trigger custom event when toast appears
    document.dispatchEvent(new CustomEvent('toastShown', { detail: { message, type } }));
}

// =============================================
// TOAST ANALYTICS TRACKER
// =============================================
document.addEventListener('DOMContentLoaded', () => {
    const toastContainer = document.getElementById('toastContainer');
    if (!toastContainer) return;
    
    // Fungsi pengiriman log analitik
    const trackToastEvent = (action, toastContent, type) => {
        // Bisa diganti dengan endpoint API sungguhan jika dibutuhkan
        console.log(`[Analytics] Toast Event: ${action} | Type: ${type} | Msg: "${toastContent}"`);
    };

    // 1. Deteksi Klik dan Tutup (Dismiss)
    toastContainer.addEventListener('click', (e) => {
        const toast = e.target.closest('.toast');
        if (!toast) return;

        const message = toast.querySelector('.toast-message')?.innerText || 'Unknown';
        const type = Array.from(toast.classList).find(c => c.startsWith('toast-'))?.replace('toast-', '') || 'info';

        if (e.target.closest('.toast-close')) {
            trackToastEvent('dismissed', message, type);
            // Hapus toast
            toast.style.opacity = '0';
            setTimeout(() => toast.remove(), 300);
        } else {
            trackToastEvent('clicked', message, type);
        }
    });

    // 2. Deteksi Geser (Swipe)
    let touchStartX = 0;
    let touchEndX = 0;
    let activeToast = null;

    toastContainer.addEventListener('touchstart', (e) => {
        const toast = e.target.closest('.toast');
        if (toast) {
            touchStartX = e.changedTouches[0].screenX;
            activeToast = toast;
        } else {
            activeToast = null;
        }
    }, { passive: true });

    toastContainer.addEventListener('touchend', (e) => {
        if (!activeToast) return;
        touchEndX = e.changedTouches[0].screenX;
        
        const distance = touchEndX - touchStartX;
        if (Math.abs(distance) > 50) { // Threshold 50px
            const message = activeToast.querySelector('.toast-message')?.innerText || 'Unknown';
            const type = Array.from(activeToast.classList).find(c => c.startsWith('toast-'))?.replace('toast-', '') || 'info';
            
            const direction = distance > 0 ? 'swiped_right' : 'swiped_left';
            trackToastEvent(direction, message, type);
            
            // Hapus toast saat digeser
            activeToast.style.transform = `translateX(${distance > 0 ? 100 : -100}px)`;
            activeToast.style.opacity = '0';
            setTimeout(() => {
                if(activeToast) activeToast.remove();
            }, 300);
        }
        activeToast = null;
    }, { passive: true });
});

// =============================================
// CONFIRM DIALOG
// =============================================
function showCustomConfirm(message, callbackYes, callbackNo = null) {
    // Buat wadah overlay
    const overlay = document.createElement('div');
    overlay.className = 'modal-overlay active';
    overlay.style.zIndex = '99999';
    overlay.style.display = 'flex';
    overlay.style.alignItems = 'center';
    overlay.style.justifyContent = 'center';
    
    // Buat struktur modal
    overlay.innerHTML = `
        <div class="modal active" style="width: 400px; max-width: 90%; transform: scale(1); transition: all 0.2s; padding: 0;">
            <div style="padding: 24px; text-align: center;">
                <div style="width: 60px; height: 60px; background: #fee2e2; border-radius: 50%; margin: 0 auto 16px; display: flex; align-items: center; justify-content: center;">
                    <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="#ef4444" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                </div>
                <h3 style="margin-bottom: 12px; font-size: 20px; color: #1f2937;">Konfirmasi</h3>
                <p style="color: #6b7280; font-size: 15px; line-height: 1.5; margin-bottom: 24px;">${message}</p>
                <div style="display: flex; gap: 12px; justify-content: center;">
                    <button class="btn btn-outline" id="btnConfirmNo" style="flex: 1;">Batal</button>
                    <button class="btn btn-primary" id="btnConfirmYes" style="flex: 1; background: #ef4444; border-color: #ef4444; color: white;">Yakin</button>
                </div>
            </div>
        </div>
    `;
    
    document.body.appendChild(overlay);
    
    const cleanup = () => {
        overlay.style.opacity = '0';
        overlay.querySelector('.modal').style.transform = 'scale(0.9)';
        setTimeout(() => overlay.remove(), 200);
    };

    overlay.querySelector('#btnConfirmYes').addEventListener('click', () => {
        cleanup();
        if (callbackYes) callbackYes();
    });

    overlay.querySelector('#btnConfirmNo').addEventListener('click', () => {
        cleanup();
        if (callbackNo) callbackNo();
    });
}

function confirmAction(message, callback) {
    showCustomConfirm(message, callback);
}

function confirmDelete(formOrUrl, itemName = 'data ini') {
    showCustomConfirm(`Yakin ingin menghapus ${itemName}? Tindakan ini tidak dapat dibatalkan.`, () => {
        if (typeof formOrUrl === 'string') {
            window.location.href = formOrUrl;
        } else {
            formOrUrl.submit();
        }
    });
}

// =============================================
// GLOBAL CONFIRM INTERCEPTOR & AUTO-SEARCH
// =============================================
document.addEventListener('DOMContentLoaded', () => {
    // 1. Auto-Search Form (Debounce 500ms)
    const searchInputs = document.querySelectorAll('form .search-box input[type="text"], form input[name="search"]');
    searchInputs.forEach(input => {
        let debounceTimer;
        input.addEventListener('input', function() {
            clearTimeout(debounceTimer);
            debounceTimer = setTimeout(() => {
                const form = this.closest('form');
                if (form) form.submit();
            }, 500);
        });
    });

    // 2. Intercept form onsubmit (Custom Confirm)
    const forms = document.querySelectorAll('form[onsubmit*="return confirm"]');
    forms.forEach(form => {
        const attr = form.getAttribute('onsubmit');
        const match = attr.match(/confirm\(['"](.*?)['"]\)/);
        if (match) {
            const msg = match[1];
            form.removeAttribute('onsubmit');
            form.addEventListener('submit', function(e) {
                e.preventDefault();
                showCustomConfirm(msg, () => {
                    form.submit();
                });
            });
        }
    });

    // Intercept onclick (links/buttons)
    const clicks = document.querySelectorAll('[onclick*="return confirm"]');
    clicks.forEach(el => {
        const attr = el.getAttribute('onclick');
        const match = attr.match(/confirm\(['"](.*?)['"]\)/);
        if (match) {
            const msg = match[1];
            el.removeAttribute('onclick');
            el.addEventListener('click', function(e) {
                e.preventDefault();
                showCustomConfirm(msg, () => {
                    if (el.tagName === 'A' && el.href) {
                        window.location.href = el.href;
                    } else if (el.tagName === 'BUTTON' && el.type === 'submit') {
                        const form = el.closest('form');
                        if(form) form.submit();
                    }
                });
            });
        }
    });
});

// =============================================
// MODAL MANAGEMENT
// =============================================
function openModal(modalId) {
    const modal = document.getElementById(modalId);
    if (modal) {
        modal.classList.add('active');
        document.body.style.overflow = 'hidden';
    }
}

function closeModal(modalId) {
    const modal = document.getElementById(modalId);
    if (modal) {
        modal.classList.remove('active');
        document.body.style.overflow = '';
    }
}

// Close modal on overlay click
document.addEventListener('click', function(e) {
    if (e.target.classList.contains('modal-overlay') && e.target.classList.contains('active')) {
        e.target.classList.remove('active');
        document.body.style.overflow = '';
    }
});

// Close modal on Escape
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        document.querySelectorAll('.modal-overlay.active').forEach(modal => {
            modal.classList.remove('active');
        });
        document.body.style.overflow = '';
    }
});

// =============================================
// AJAX HELPER
// =============================================
async function fetchAPI(url, options = {}) {
    const defaultOptions = {
        headers: {
            'X-Requested-With': 'XMLHttpRequest',
        },
    };
    
    if (options.body && !(options.body instanceof FormData)) {
        defaultOptions.headers['Content-Type'] = 'application/json';
        options.body = JSON.stringify(options.body);
    }
    
    const mergedOptions = {
        ...defaultOptions,
        ...options,
        headers: { ...defaultOptions.headers, ...options.headers }
    };
    
    try {
        const response = await fetch(url, mergedOptions);
        const data = await response.json();
        
        if (!response.ok) {
            throw new Error(data.message || 'Terjadi kesalahan pada server.');
        }
        
        return data;
    } catch (error) {
        console.error('API Error:', error);
        throw error;
    }
}

// =============================================
// FORMAT HELPERS
// =============================================
function formatRupiah(amount) {
    return 'Rp ' + new Intl.NumberFormat('id-ID').format(amount);
}

function formatNumber(num) {
    return new Intl.NumberFormat('id-ID').format(num);
}

function parseRupiah(str) {
    return parseInt(str.replace(/[^\d]/g, '')) || 0;
}

// =============================================
// FORM HELPERS
// =============================================

// Auto-format input Rupiah
function initRupiahInput(selector) {
    document.querySelectorAll(selector).forEach(input => {
        input.addEventListener('input', function() {
            let val = this.value.replace(/[^\d]/g, '');
            this.value = val ? new Intl.NumberFormat('id-ID').format(val) : '';
        });
    });
}

// Auto-dismiss flash alerts & restore sidebar state
document.addEventListener('DOMContentLoaded', function() {
    // Restore sidebar state on desktop
    if (window.innerWidth > 768) {
        const isCollapsed = localStorage.getItem('sidebar-collapsed') === 'true';
        const layout = document.querySelector('.admin-layout');
        if (layout && isCollapsed) {
            layout.classList.add('sidebar-collapsed');
        }
    }

    const flashAlert = document.getElementById('flashAlert');
    if (flashAlert) {
        setTimeout(() => {
            flashAlert.style.opacity = '0';
            flashAlert.style.transform = 'translateY(-12px)';
            flashAlert.style.transition = 'all 0.3s ease';
            setTimeout(() => flashAlert.remove(), 300);
        }, 5000);
    }
});

// Debounce function
function debounce(func, wait = 300) {
    let timeout;
    return function executedFunction(...args) {
        const later = () => {
            clearTimeout(timeout);
            func(...args);
        };
        clearTimeout(timeout);
        timeout = setTimeout(later, wait);
    };
}

// =============================================
// TABLE HELPERS
// =============================================
function selectAllCheckboxes(masterCheckbox) {
    const table = masterCheckbox.closest('table');
    const checkboxes = table.querySelectorAll('tbody input[type="checkbox"]');
    checkboxes.forEach(cb => cb.checked = masterCheckbox.checked);
}

// =============================================
// PRINT HELPERS
// =============================================
function printElement(elementId) {
    const printContent = document.getElementById(elementId);
    if (!printContent) return;
    
    const printWindow = window.open('', '_blank');
    printWindow.document.write(`
        <html>
        <head>
            <title>Cetak</title>
            <style>
                body { font-family: 'Inter', sans-serif; padding: 20px; color: #333; }
                table { width: 100%; border-collapse: collapse; margin: 10px 0; }
                th, td { border: 1px solid #ddd; padding: 8px; text-align: left; font-size: 13px; }
                th { background: #f5f5f5; font-weight: 600; }
                h1, h2, h3 { margin: 0 0 10px; }
                .text-right { text-align: right; }
                .text-center { text-align: center; }
                .text-bold { font-weight: 700; }
                @media print { body { padding: 0; } }
            </style>
        </head>
        <body>${printContent.innerHTML}</body>
        </html>
    `);
    printWindow.document.close();
    printWindow.onload = function() {
        printWindow.print();
        printWindow.close();
    };
}

// =============================================
// SHOP MOBILE MENU TOGGLE
// Hamburger menu untuk halaman toko di < 640px
// =============================================
(function() {
    'use strict';

    function initShopMobileMenu() {
        const toggle = document.getElementById('shopMobileToggle');
        const menu   = document.getElementById('shopMobileMenu');

        if (!toggle || !menu) return;

        const iconOpen  = toggle.querySelector('.icon-menu');
        const iconClose = toggle.querySelector('.icon-close');

        function openMenu() {
            menu.style.display = 'flex';
            // Force reflow agar transisi berjalan
            menu.offsetHeight;
            menu.classList.add('open');
            menu.style.opacity = '1';
            menu.style.transform = 'translateY(0)';
            toggle.setAttribute('aria-expanded', 'true');
            document.body.style.overflow = 'hidden'; // Cegah scroll background
            if (iconOpen)  iconOpen.style.display  = 'none';
            if (iconClose) iconClose.style.display = 'block';
        }

        function closeMenu() {
            menu.classList.remove('open');
            menu.style.opacity = '0';
            menu.style.transform = 'translateY(-8px)';
            
            setTimeout(() => {
                if (!menu.classList.contains('open')) {
                    menu.style.display = '';
                    menu.style.opacity = '';
                    menu.style.transform = '';
                }
            }, 200); // 200ms sesuai durasi transisi CSS
            
            toggle.setAttribute('aria-expanded', 'false');
            document.body.style.overflow = '';
            if (iconOpen)  iconOpen.style.display  = 'block';
            if (iconClose) iconClose.style.display = 'none';
        }

        toggle.addEventListener('click', function(e) {
            e.stopPropagation();
            if (menu.classList.contains('open')) {
                closeMenu();
            } else {
                openMenu();
            }
        });

        // Tutup menu saat klik di luar
        document.addEventListener('click', function(e) {
            if (menu.classList.contains('open') &&
                !menu.contains(e.target) &&
                !toggle.contains(e.target)) {
                closeMenu();
            }
        });

        // Tutup menu dengan tombol Escape
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && menu.classList.contains('open')) {
                closeMenu();
                toggle.focus();
            }
        });

        // Tutup menu saat resize ke desktop
        window.addEventListener('resize', function() {
            if (window.innerWidth > 640 && menu.classList.contains('open')) {
                closeMenu();
            }
        });
    }

    // Inisialisasi saat DOM siap
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initShopMobileMenu);
    } else {
        initShopMobileMenu();
    }
})();
