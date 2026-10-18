/**
 * Academic Management System — Client-Side Scripts
 * 
 * Sidebar toggle, table search, grading weight validation,
 * confirmation modals, and utility functions.
 */

document.addEventListener('DOMContentLoaded', function () {

    // ─── Sidebar Toggle (Mobile) ────────────────────────
    const sidebarToggle = document.getElementById('sidebarToggle');
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('sidebarOverlay');

    if (sidebarToggle && sidebar) {
        sidebarToggle.addEventListener('click', function () {
            sidebar.classList.toggle('show');
            if (overlay) overlay.classList.toggle('show');
        });
    }
    if (overlay) {
        overlay.addEventListener('click', function () {
            sidebar.classList.remove('show');
            overlay.classList.remove('show');
        });
    }

    // ─── Global Search Shortcut (⌘K / Ctrl+K) ─────────────
    document.addEventListener('keydown', function (e) {
        if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 'k') {
            const searchInput = document.getElementById('globalHeaderSearch') || document.querySelector('.header-search-input');
            if (searchInput) {
                e.preventDefault();
                searchInput.focus();
                searchInput.select();
            }
        }
    });

    // ─── Table Search / Filter ──────────────────────────
    document.querySelectorAll('[data-table-search]').forEach(function (input) {
        const tableId = input.getAttribute('data-table-search');
        const table = document.getElementById(tableId);
        if (!table) return;

        input.addEventListener('input', function () {
            const query = this.value.toLowerCase();
            const rows = table.querySelectorAll('tbody tr');

            rows.forEach(function (row) {
                const text = row.textContent.toLowerCase();
                row.style.display = text.includes(query) ? '' : 'none';
            });
        });
    });

    // ─── Grading Weight Total Validator ─────────────────
    const weightInputs = document.querySelectorAll('.weight-input');
    const weightTotal = document.getElementById('weightTotal');
    const weightStatus = document.getElementById('weightStatus');

    function updateWeightTotal() {
        if (!weightTotal) return;
        let total = 0;
        weightInputs.forEach(function (input) {
            total += parseFloat(input.value) || 0;
        });
        weightTotal.textContent = total.toFixed(2) + '%';

        if (weightStatus) {
            if (Math.abs(total - 100) < 0.01) {
                weightTotal.className = 'text-success fw-bold';
                weightStatus.textContent = '✓ Valid';
                weightStatus.className = 'text-success small';
            } else {
                weightTotal.className = 'text-danger fw-bold';
                weightStatus.textContent = '✗ Must equal 100%';
                weightStatus.className = 'text-danger small';
            }
        }
    }

    weightInputs.forEach(function (input) {
        input.addEventListener('input', updateWeightTotal);
    });
    if (weightInputs.length > 0) updateWeightTotal();

    // ─── Score Range Validation ─────────────────────────
    document.querySelectorAll('.score-input').forEach(function (input) {
        input.addEventListener('change', function () {
            const max = parseFloat(this.getAttribute('data-max')) || 100;
            let val = parseFloat(this.value) || 0;
            if (val < 0) val = 0;
            if (val > max) val = max;
            this.value = val;
        });
    });

    // ─── Confirmation Dialogs ───────────────────────────
    document.querySelectorAll('[data-confirm]').forEach(function (el) {
        el.addEventListener('click', function (e) {
            const message = this.getAttribute('data-confirm') || 'Are you sure?';
            if (!confirm(message)) {
                e.preventDefault();
            }
        });
    });

    // ─── Mark All Present Shortcut ──────────────────────
    const markAllBtn = document.getElementById('markAllPresent');
    if (markAllBtn) {
        markAllBtn.addEventListener('click', function () {
            document.querySelectorAll('input[type="radio"][value="Present"]').forEach(function (radio) {
                radio.checked = true;
            });
        });
    }

    // ─── Fade-in Animation Trigger ──────────────────────
    const observer = new IntersectionObserver(function (entries) {
        entries.forEach(function (entry) {
            if (entry.isIntersecting) {
                entry.target.classList.add('fade-in-up');
                observer.unobserve(entry.target);
            }
        });
    }, { threshold: 0.1 });

    document.querySelectorAll('.animate-on-scroll').forEach(function (el) {
        observer.observe(el);
    });

});
