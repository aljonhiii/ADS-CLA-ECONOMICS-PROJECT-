/**
 * Academic Management System — Client-Side Scripts (UI/UX PRO MAX)
 * 
 * High-performance interaction design:
 * - Spreadsheet-like keyboard navigation (Arrow keys, Enter, Shift+Enter, Esc)
 * - Rapid roll-call attendance shortcuts (P, A, L, E or 1, 2, 3, 4)
 * - Active row highlighting & non-destructive focus traversal
 * - Real-time auto-saving (zero browser reload)
 * - Toast notification feedback system
 * - WCAG 2.2 AA accessibility and reduced motion compliance
 */

document.addEventListener('DOMContentLoaded', function () {

    // ─── CSRF Token Helper ──────────────────────────────
    function getCsrfToken() {
        return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
    }

    // ─── Toast Notification System ──────────────────────
    function showToast(message, type = 'success', duration = 3000) {
        let container = document.getElementById('toastContainer');
        if (!container) {
            container = document.createElement('div');
            container.id = 'toastContainer';
            container.className = 'ams-toast-container';
            document.body.appendChild(container);
        }

        const toast = document.createElement('div');
        toast.className = `ams-toast ${type}`;

        const iconClass = type === 'success' ? 'bi-check-circle-fill text-success' :
                          type === 'danger'  ? 'bi-exclamation-octagon-fill text-danger' :
                          type === 'warning' ? 'bi-exclamation-triangle-fill text-warning' :
                                               'bi-info-circle-fill text-info';

        toast.innerHTML = `
            <i class="bi ${iconClass} fs-5 flex-shrink-0" aria-hidden="true"></i>
            <span class="flex-grow-1">${message}</span>
            <button type="button" class="btn-close btn-close-white btn-sm ms-2" style="font-size:0.7rem;" aria-label="Close"></button>
        `;

        toast.querySelector('.btn-close').addEventListener('click', function () {
            toast.classList.add('fade-out');
            setTimeout(() => toast.remove(), 250);
        });

        container.appendChild(toast);

        setTimeout(function () {
            toast.classList.add('fade-out');
            setTimeout(() => toast.remove(), 250);
        }, duration);
    }

    // Expose showToast globally
    window.amsToast = showToast;

    // ─── Auto-Dismiss Flash Alerts (Vanish after 3.5 seconds) ───
    const flashAlerts = document.querySelectorAll('.alert.alert-dismissible');
    flashAlerts.forEach(function (alertEl) {
        const timeoutMs = alertEl.classList.contains('alert-danger') ? 5000 : 3500;
        setTimeout(function () {
            if (!document.body.contains(alertEl)) return;
            alertEl.style.transition = 'opacity 0.4s ease, transform 0.4s ease, max-height 0.4s ease, margin 0.4s ease, padding 0.4s ease';
            alertEl.style.opacity = '0';
            alertEl.style.transform = 'translateY(-8px)';
            setTimeout(function () {
                if (typeof bootstrap !== 'undefined' && bootstrap.Alert) {
                    try {
                        const bsAlert = bootstrap.Alert.getOrCreateInstance(alertEl);
                        if (bsAlert) bsAlert.close();
                    } catch (e) {
                        alertEl.remove();
                    }
                } else {
                    alertEl.remove();
                }
            }, 400);
        }, timeoutMs);
    });

    // ─── Plain CSS Dropdown Support ([data-dropdown]) ───
    function closeAllDropdowns(exceptMenu) {
        document.querySelectorAll('.dropdown-menu.show').forEach(function (menu) {
            if (menu === exceptMenu) return;
            menu.classList.remove('show');
            const toggle = menu.parentElement?.querySelector('[data-dropdown]');
            if (toggle) toggle.setAttribute('aria-expanded', 'false');
        });
    }

    document.querySelectorAll('[data-dropdown]').forEach(function (toggle) {
        toggle.addEventListener('click', function (e) {
            e.stopPropagation();
            const menu = toggle.parentElement?.querySelector('.dropdown-menu');
            if (!menu) return;
            const willOpen = !menu.classList.contains('show');
            closeAllDropdowns(menu);
            menu.classList.toggle('show', willOpen);
            toggle.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
        });
    });

    document.addEventListener('click', function (e) {
        if (!e.target.closest('.dropdown-menu')) {
            closeAllDropdowns();
        }
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            closeAllDropdowns();
            const sb = document.getElementById('sidebar');
            const ov = document.getElementById('sidebarOverlay');
            if (sb) sb.classList.remove('show');
            if (ov) ov.classList.remove('show');
        }
    });

    // ─── Confirmation Prompts ([data-confirm]) ──────────
    document.querySelectorAll('[data-confirm]').forEach(function (el) {
        el.addEventListener('click', function (e) {
            if (!confirm(this.getAttribute('data-confirm') || 'Are you sure?')) {
                e.preventDefault();
            }
        });
    });

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

    // ─── Global Keyboard Shortcuts (Ctrl+S / ⌘S / Slash / Ctrl+K) ───
    document.addEventListener('keydown', function (e) {
        // Ctrl+S / ⌘S: Submit grades form
        if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 's') {
            e.preventDefault();
            const matrixForm = document.getElementById('gradesMatrixForm');
            if (matrixForm) {
                matrixForm.submit();
            }
        }

        // Quick Global Search (/ or Ctrl+K) when not in an input
        const isInputFocused = ['INPUT', 'TEXTAREA', 'SELECT'].includes(document.activeElement?.tagName);
        if (!isInputFocused && (e.key === '/' || ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k'))) {
            const searchInput = document.querySelector('[data-table-search]') || document.getElementById('globalHeaderSearch');
            if (searchInput) {
                e.preventDefault();
                searchInput.focus();
                searchInput.select();
            }
        }
    });

    // ─── Live Table Search / Instant Filter ───────────────
    document.querySelectorAll('[data-table-search]').forEach(function (input) {
        const tableId = input.getAttribute('data-table-search');
        const table = document.getElementById(tableId);
        if (!table) return;

        input.addEventListener('input', function () {
            const query = this.value.toLowerCase().trim();
            const rows = table.querySelectorAll('tbody tr');
            let matchCount = 0;

            rows.forEach(function (row) {
                const text = row.textContent.toLowerCase();
                const matches = text.includes(query);
                row.style.display = matches ? '' : 'none';
                if (matches) matchCount++;
            });

            // Update badge if exists
            const badge = document.querySelector(`[data-count-for="${tableId}"]`);
            if (badge) {
                badge.textContent = `${matchCount} shown`;
            }
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
                weightStatus.textContent = '✓ 100% Valid';
                weightStatus.className = 'badge bg-success-subtle text-success border border-success small';
            } else {
                weightTotal.className = 'text-danger fw-bold';
                weightStatus.textContent = '✗ Must equal 100%';
                weightStatus.className = 'badge bg-danger-subtle text-danger border border-danger small';
            }
        }
    }

    weightInputs.forEach(function (input) {
        input.addEventListener('input', updateWeightTotal);
    });
    if (weightInputs.length > 0) updateWeightTotal();

    // ═══════════════════════════════════════════════════════════
    // 1. SPREADSHEET GRADE MATRIX (Excel-like Arrows & Auto-Save)
    // ═══════════════════════════════════════════════════════════
    const scoreInputs = document.querySelectorAll('.score-input');
    const saveTimeouts = new Map();

    function recalculateStudentRow(row) {
        if (!row) return;
        const inputs = row.querySelectorAll('.score-input');
        const threshold = parseFloat(row.getAttribute('data-threshold')) || 75.0;
        const badgeEl = row.querySelector('.student-status-badge');
        if (!badgeEl) return;

        let totalWeighted = 0;
        let allGraded = true;
        let hasAnyScore = false;

        inputs.forEach(function (inp) {
            const max = parseFloat(inp.getAttribute('data-max')) || 100;
            const weight = parseFloat(inp.getAttribute('data-weight')) || 0;
            const raw = inp.value.trim();

            if (raw !== '') {
                hasAnyScore = true;
                const val = parseFloat(raw);
                if (!isNaN(val) && max > 0) {
                    totalWeighted += (val / max) * weight;
                }
            } else {
                allGraded = false;
            }
        });

        totalWeighted = Math.round(totalWeighted * 100) / 100;

        if (allGraded && inputs.length > 0) {
            if (totalWeighted >= threshold) {
                badgeEl.className = 'badge bg-success student-status-badge';
                badgeEl.innerHTML = `<i class="bi bi-check-lg me-1" aria-hidden="true"></i>Pass (${totalWeighted.toFixed(1)}%)`;
            } else {
                badgeEl.className = 'badge bg-danger student-status-badge';
                badgeEl.innerHTML = `<i class="bi bi-x-lg me-1" aria-hidden="true"></i>Fail (${totalWeighted.toFixed(1)}%)`;
            }
        } else if (hasAnyScore) {
            badgeEl.className = 'badge bg-warning text-dark student-status-badge';
            badgeEl.innerHTML = `<i class="bi bi-clock me-1" aria-hidden="true"></i>Progress (${totalWeighted.toFixed(1)}%)`;
        } else {
            badgeEl.className = 'badge bg-secondary student-status-badge';
            badgeEl.textContent = 'Unfinalized';
        }
    }

    scoreInputs.forEach(function (input) {
        // Focus state: Active row highlight & auto-select
        input.addEventListener('focus', function () {
            this.setAttribute('data-orig-val', this.value);
            const row = this.closest('tr');
            if (row) {
                document.querySelectorAll('tr.is-active-row').forEach(r => r.classList.remove('is-active-row'));
                row.classList.add('is-active-row');
            }
            this.select();
        });

        input.addEventListener('blur', function () {
            const row = this.closest('tr');
            if (row) {
                row.classList.remove('is-active-row');
            }
        });

        // Range validation on input & live calculation preview (no auto-save)
        input.addEventListener('input', function () {
            const max = parseFloat(this.getAttribute('data-max')) || 100;
            const val = parseFloat(this.value);
            if (val > max || val < 0) {
                this.classList.add('is-error');
            } else {
                this.classList.remove('is-error');
            }

            const row = this.closest('tr');
            recalculateStudentRow(row);
        });

        // SPREADSHEET KEYBOARD NAVIGATION (Arrow keys, Enter, Shift+Enter, Esc)
        input.addEventListener('keydown', function (e) {
            const row = this.closest('tr');
            const compId = this.getAttribute('data-comp-id');

            // 1. Move Down: ArrowDown or Enter (without shift)
            if (e.key === 'ArrowDown' || (e.key === 'Enter' && !e.shiftKey)) {
                e.preventDefault();
                let nextRow = row.nextElementSibling;
                while (nextRow && nextRow.style.display === 'none') {
                    nextRow = nextRow.nextElementSibling;
                }
                if (nextRow) {
                    const target = nextRow.querySelector(`.score-input[data-comp-id="${compId}"]`);
                    if (target) {
                        target.focus();
                        target.select();
                    }
                }
            }

            // 2. Move Up: ArrowUp or Shift+Enter
            if (e.key === 'ArrowUp' || (e.key === 'Enter' && e.shiftKey)) {
                e.preventDefault();
                let prevRow = row.previousElementSibling;
                while (prevRow && prevRow.style.display === 'none') {
                    prevRow = prevRow.previousElementSibling;
                }
                if (prevRow) {
                    const target = prevRow.querySelector(`.score-input[data-comp-id="${compId}"]`);
                    if (target) {
                        target.focus();
                        target.select();
                    }
                }
            }

            // 3. Move Right: ArrowRight (if cursor at end or selected)
            if (e.key === 'ArrowRight' && (this.selectionStart === this.value.length || this.selectionStart === 0)) {
                const cell = this.closest('td');
                const nextCell = cell?.nextElementSibling;
                const nextInput = nextCell?.querySelector('.score-input');
                if (nextInput) {
                    e.preventDefault();
                    nextInput.focus();
                    nextInput.select();
                }
            }

            // 4. Move Left: ArrowLeft (if cursor at start or selected)
            if (e.key === 'ArrowLeft' && this.selectionStart === 0) {
                const cell = this.closest('td');
                const prevCell = cell?.previousElementSibling;
                const prevInput = prevCell?.querySelector('.score-input');
                if (prevInput) {
                    e.preventDefault();
                    prevInput.focus();
                    prevInput.select();
                }
            }

            // 5. Escape: Revert to original score
            if (e.key === 'Escape') {
                const orig = this.getAttribute('data-orig-val') || '';
                this.value = orig;
                this.classList.remove('is-error');
                recalculateStudentRow(row);
                showToast('Score edit reverted.', 'info', 1500);
            }
        });
    });

    // ═══════════════════════════════════════════════════════════
    // 2. RAPID ROLL-CALL ATTENDANCE (Shortcuts: P, A, L, E / 1-4)
    // ═══════════════════════════════════════════════════════════
    const rosterRows = document.querySelectorAll('tr[data-attendance-row]');
    let activeRosterIndex = 0;

    function setActiveRosterRow(index) {
        if (!rosterRows.length) return;
        if (index < 0) index = 0;
        if (index >= rosterRows.length) index = rosterRows.length - 1;

        rosterRows.forEach(r => r.classList.remove('roster-active-row'));
        const row = rosterRows[index];
        if (row) {
            row.classList.add('roster-active-row');
            activeRosterIndex = index;
            row.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }
    }

    if (rosterRows.length > 0) {
        // Click on student row activates it
        rosterRows.forEach((r, idx) => {
            r.addEventListener('click', function () {
                setActiveRosterRow(idx);
            });
        });
        setActiveRosterRow(0);
    }

    function recordRosterStatus(row, status) {
        if (!row) return;
        const pillBtn = row.querySelector(`.attendance-pill-btn[data-status="${status}"]`);
        if (pillBtn) {
            pillBtn.click();
            // Automatically advance to the next student
            if (activeRosterIndex < rosterRows.length - 1) {
                setActiveRosterRow(activeRosterIndex + 1);
            }
        }
    }

    // Global Key Listener for Rapid Roll Call
    document.addEventListener('keydown', function (e) {
        // Only trigger roll call keys when NOT focused in an input/textarea
        const tag = document.activeElement?.tagName;
        if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT') return;
        if (!rosterRows.length) return;

        const key = e.key.toUpperCase();
        const currentRow = rosterRows[activeRosterIndex];

        if (key === 'P' || key === '1') {
            e.preventDefault();
            recordRosterStatus(currentRow, 'Present');
        } else if (key === 'A' || key === '2') {
            e.preventDefault();
            recordRosterStatus(currentRow, 'Absent');
        } else if (key === 'L' || key === '3') {
            e.preventDefault();
            recordRosterStatus(currentRow, 'Late');
        } else if (key === 'E' || key === '4') {
            e.preventDefault();
            recordRosterStatus(currentRow, 'Excused');
        } else if (key === 'ARROWDOWN') {
            e.preventDefault();
            setActiveRosterRow(activeRosterIndex + 1);
        } else if (key === 'ARROWUP') {
            e.preventDefault();
            setActiveRosterRow(activeRosterIndex - 1);
        }
    });

    function recalculateLocalAttendanceKpis() {
        let pres = 0, abs = 0, late = 0, exc = 0;
        const rows = document.querySelectorAll('tr[data-attendance-row]');
        rows.forEach(function (row) {
            const activePill = row.querySelector('.attendance-pill-btn.active');
            if (activePill) {
                const s = activePill.getAttribute('data-status');
                if (s === 'Present') pres++;
                else if (s === 'Absent') abs++;
                else if (s === 'Late') late++;
                else if (s === 'Excused') exc++;
            }
        });
        const total = rows.length;
        const rate = total > 0 ? Math.round(((pres + late) / total) * 1000) / 10 : 0;

        const elPres = document.getElementById('metricPresent');
        const elAbs  = document.getElementById('metricAbsent');
        const elLate = document.getElementById('metricLate');
        const elExc  = document.getElementById('metricExcused');
        const elRate = document.getElementById('metricRate');

        if (elPres) elPres.textContent = pres;
        if (elAbs) elAbs.textContent = abs;
        if (elLate) elLate.textContent = late;
        if (elExc) elExc.textContent = exc;
        if (elRate) elRate.textContent = rate + '%';
    }

    // Status pill clicks (Updates selection & form radios without auto-saving)
    document.querySelectorAll('.attendance-pill-btn').forEach(function (btn) {
        btn.addEventListener('click', function (e) {
            e.preventDefault();
            const status = this.getAttribute('data-status');
            const group = this.closest('.attendance-pills-group');

            // Set UI active immediately
            if (group) {
                group.querySelectorAll('.attendance-pill-btn').forEach(b => b.classList.remove('active'));
                this.classList.add('active');

                // Update hidden radio for form submission
                const radio = group.closest('tr')?.querySelector(`input[type="radio"][value="${status}"]`);
                if (radio) radio.checked = true;
            }

            recalculateLocalAttendanceKpis();
        });
    });

    // Mark All Present (Updates all rows locally without auto-saving)
    const markAllBtn = document.getElementById('markAllPresent');
    if (markAllBtn) {
        markAllBtn.addEventListener('click', function (e) {
            e.preventDefault();
            document.querySelectorAll('.attendance-pill-btn[data-status="Present"]').forEach(btn => {
                const group = btn.closest('.attendance-pills-group');
                if (group) {
                    group.querySelectorAll('.attendance-pill-btn').forEach(b => b.classList.remove('active'));
                    btn.classList.add('active');
                }
            });

            document.querySelectorAll('input[type="radio"][value="Present"]').forEach(r => r.checked = true);

            recalculateLocalAttendanceKpis();
            showToast('All students marked <strong>Present</strong>. Click Save Attendance to commit.', 'info', 3000);
        });
    }

    // ─── Preset Templates Loader (Grading Components) ───
    document.querySelectorAll('[data-preset-weights]').forEach(function (btn) {
        btn.addEventListener('click', function (e) {
            e.preventDefault();
            const nameInput = document.querySelector('input[name="component_name"]');
            const weightInput = document.querySelector('input[name="weight_percentage"]');
            const maxInput = document.querySelector('input[name="maximum_score"]');

            const name = this.getAttribute('data-name');
            const weight = this.getAttribute('data-weight');
            const max = this.getAttribute('data-max') || '100';

            if (nameInput) nameInput.value = name;
            if (weightInput) weightInput.value = weight;
            if (maxInput) maxInput.value = max;

            updateWeightTotal();
            if (nameInput) nameInput.focus();
            showToast(`Preset loaded: <strong>${name} (${weight}%)</strong>. Click 'Add' to save.`, 'info', 2500);
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
