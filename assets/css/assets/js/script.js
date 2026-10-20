/**
 * BSE-SPMS — client-side scripts (plain JavaScript, no framework)
 *
 * Sidebar, user menu, dismissible alerts, table search, weight validator,
 * score entry helpers, attendance helpers, confirm prompts, scroll fade-in.
 */
document.addEventListener('DOMContentLoaded', function () {

    // ─── Sidebar (mobile) ───────────────────────────────
    const sidebarToggle = document.getElementById('sidebarToggle');
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('sidebarOverlay');

    function setSidebar(open) {
        if (!sidebar) return;
        sidebar.classList.toggle('show', open);
        if (overlay) overlay.classList.toggle('show', open);
        if (sidebarToggle) sidebarToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    }
    if (sidebarToggle) sidebarToggle.addEventListener('click', function () {
        setSidebar(!sidebar.classList.contains('show'));
    });
    if (overlay) overlay.addEventListener('click', function () { setSidebar(false); });

    // ─── Dropdown menus (replaces Bootstrap's dropdown) ──
    // Markup: <div class="dropdown"><button data-dropdown>…</button><ul class="dropdown-menu">…</ul></div>
    function closeDropdowns(except) {
        document.querySelectorAll('.dropdown-menu.show').forEach(function (menu) {
            if (menu === except) return;
            menu.classList.remove('show');
            const t = menu.parentElement.querySelector('[data-dropdown]');
            if (t) t.setAttribute('aria-expanded', 'false');
        });
    }
    document.querySelectorAll('[data-dropdown]').forEach(function (toggle) {
        toggle.addEventListener('click', function (e) {
            e.stopPropagation();
            const menu = toggle.parentElement.querySelector('.dropdown-menu');
            if (!menu) return;
            const willOpen = !menu.classList.contains('show');
            closeDropdowns(menu);
            menu.classList.toggle('show', willOpen);
            toggle.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
        });
    });
    document.addEventListener('click', function (e) {
        if (!e.target.closest('.dropdown-menu')) closeDropdowns();
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') { closeDropdowns(); setSidebar(false); }
    });

    // ─── Dismissible alerts ─────────────────────────────
    // Markup: <button class="btn-close" data-dismiss="alert">
    document.querySelectorAll('[data-dismiss="alert"]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const alertEl = btn.closest('.alert');
            if (alertEl) alertEl.classList.add('is-hidden');
        });
    });

    // ─── Global search shortcut (Ctrl/⌘ + K) ────────────
    document.addEventListener('keydown', function (e) {
        if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 'k') {
            const searchInput = document.getElementById('globalHeaderSearch') || document.querySelector('.header-search-input');
            if (searchInput) { e.preventDefault(); searchInput.focus(); searchInput.select(); }
        }
    });

    // ─── Table search / filter ──────────────────────────
    document.querySelectorAll('[data-table-search]').forEach(function (input) {
        const table = document.getElementById(input.getAttribute('data-table-search'));
        if (!table) return;
        input.addEventListener('input', function () {
            const query = this.value.toLowerCase();
            table.querySelectorAll('tbody tr').forEach(function (row) {
                row.style.display = row.textContent.toLowerCase().includes(query) ? '' : 'none';
            });
        });
    });

    // ─── Grading weight total validator ─────────────────
    const weightInputs = document.querySelectorAll('.weight-input');
    const weightTotal = document.getElementById('weightTotal');
    const weightStatus = document.getElementById('weightStatus');

    function updateWeightTotal() {
        if (!weightTotal) return;
        let total = 0;
        weightInputs.forEach(function (i) { total += parseFloat(i.value) || 0; });
        weightTotal.textContent = total.toFixed(2) + '%';
        const ok = Math.abs(total - 100) < 0.01;
        weightTotal.className = (ok ? 'text-success' : 'text-danger') + ' fw-bold';
        if (weightStatus) {
            weightStatus.textContent = ok ? '✓ Valid' : '✗ Must equal 100%';
            weightStatus.className = (ok ? 'text-success' : 'text-danger') + ' small';
        }
    }
    weightInputs.forEach(function (i) { i.addEventListener('input', updateWeightTotal); });
    if (weightInputs.length > 0) updateWeightTotal();

    // ─── Score entry: live range check + Enter moves down the column ──
    const scoreInputs = Array.from(document.querySelectorAll('.score-input'));

    scoreInputs.forEach(function (input) {
        const max = parseFloat(input.getAttribute('data-max')) || 100;

        // red highlight while typing a value that is out of range
        input.addEventListener('input', function () {
            const v = parseFloat(this.value);
            this.classList.toggle('is-invalid', !isNaN(v) && (v > max || v < 0));
        });
        // clamp when the field loses focus
        input.addEventListener('change', function () {
            if (this.value === '') { this.classList.remove('is-invalid'); return; }
            let v = parseFloat(this.value);
            if (isNaN(v) || v < 0) v = 0;
            if (v > max) v = max;
            this.value = v;
            this.classList.remove('is-invalid');
        });
        // Enter jumps to the same column in the next row (like a spreadsheet)
        input.addEventListener('keydown', function (e) {
            if (e.key !== 'Enter') return;
            e.preventDefault();
            const cell = this.closest('td');
            const row = cell && cell.parentElement;
            if (!row) return;
            const col = Array.prototype.indexOf.call(row.children, cell);
            let next = row.nextElementSibling;
            while (next && !(next.children[col] && next.children[col].querySelector('.score-input'))) {
                next = next.nextElementSibling;
            }
            if (next) {
                const target = next.children[col].querySelector('.score-input');
                target.focus(); target.select();
            }
        });
    });

    // ─── Attendance: mark-all shortcut + live tally ─────
    const markAllBtn = document.getElementById('markAllPresent');
    if (markAllBtn) {
        markAllBtn.addEventListener('click', function () {
            document.querySelectorAll('input[type="radio"][value="Present"]').forEach(function (r) { r.checked = true; });
            updateAttendanceTally();
        });
    }
    const tally = document.getElementById('attendanceTally');
    function updateAttendanceTally() {
        if (!tally) return;
        ['Present', 'Absent', 'Late', 'Excused'].forEach(function (s) {
            const el = tally.querySelector('[data-count="' + s + '"]');
            if (el) el.textContent = document.querySelectorAll('input[type="radio"][value="' + s + '"]:checked').length;
        });
    }
    if (tally) {
        document.querySelectorAll('input[type="radio"][name^="status["]').forEach(function (r) {
            r.addEventListener('change', updateAttendanceTally);
        });
        updateAttendanceTally();
    }

    // ─── Confirmation prompts ───────────────────────────
    document.querySelectorAll('[data-confirm]').forEach(function (el) {
        el.addEventListener('click', function (e) {
            if (!confirm(this.getAttribute('data-confirm') || 'Are you sure?')) e.preventDefault();
        });
    });

    // ─── Fade-in on scroll ──────────────────────────────
    if ('IntersectionObserver' in window) {
        const observer = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (entry.isIntersecting) {
                    entry.target.classList.add('fade-in-up');
                    observer.unobserve(entry.target);
                }
            });
        }, { threshold: 0.1 });
        document.querySelectorAll('.animate-on-scroll').forEach(function (el) { observer.observe(el); });
    }
});
