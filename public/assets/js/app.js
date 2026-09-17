/**
 * Frontend JavaScript Controller for Change Monitor Admin
 */

document.addEventListener('DOMContentLoaded', () => {
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

    // ==========================================
    // --- CORE SELECTION & GROUP CONTROLLERS ---
    // ==========================================

    let currentActiveGroup = 'all';

    function getSelectedMonitorIds() {
        const activeSubtab = document.querySelector('.subtab-pane:not([style*="display: none"])') || document;
        const checked = activeSubtab.querySelectorAll('.monitor-checkbox:checked');
        return Array.from(checked).filter(cb => {
            const row = cb.closest('tr');
            return row && row.style.display !== 'none';
        }).map(cb => cb.value);
    }

    function updateBulkToolbar() {
        const selectedIds = getSelectedMonitorIds();
        const count = selectedIds.length;
        const bulkSelectedCount = document.getElementById('bulkSelectedCount');
        const bulkActionBar = document.getElementById('bulkActionBar');

        if (bulkSelectedCount) {
            bulkSelectedCount.textContent = count;
        }

        if (bulkActionBar) {
            if (count > 0) {
                bulkActionBar.classList.add('show');
            } else {
                bulkActionBar.classList.remove('show');
            }
        }

        // Update row highlight
        document.querySelectorAll('.monitor-checkbox').forEach(cb => {
            const row = cb.closest('tr');
            if (row) {
                if (cb.checked) {
                    row.classList.add('row-selected');
                } else {
                    row.classList.remove('row-selected');
                }
            }
        });

        // Update Select All master checkboxes state against visible rows
        ['active', 'inactive'].forEach(target => {
            const master = document.querySelector(`.select-all-checkbox[data-target="${target}"]`);
            if (master) {
                const subtabPane = document.getElementById(`subtab-${target}`);
                if (subtabPane) {
                    const visibleRowBoxes = Array.from(subtabPane.querySelectorAll('.monitor-checkbox')).filter(cb => {
                        const row = cb.closest('tr');
                        return row && row.style.display !== 'none';
                    });
                    const visibleCheckedBoxes = visibleRowBoxes.filter(cb => cb.checked);
                    if (visibleRowBoxes.length > 0 && visibleCheckedBoxes.length === visibleRowBoxes.length) {
                        master.checked = true;
                        master.indeterminate = false;
                    } else if (visibleCheckedBoxes.length > 0) {
                        master.checked = false;
                        master.indeterminate = true;
                    } else {
                        master.checked = false;
                        master.indeterminate = false;
                    }
                }
            }
        });
    }

    // Dynamic Group Pill Counts (Active tab counts active monitors; Inactive tab counts inactive monitors)
    function updateGroupPillCounts() {
        const activeSubtab = document.querySelector('.subtab-pane:not([style*="display: none"])');
        if (!activeSubtab || activeSubtab.id === 'subtab-trash') return;

        const rows = Array.from(activeSubtab.querySelectorAll('.monitor-row'));
        const totalCount = rows.length;
        const groupCounts = {};

        rows.forEach(row => {
            const rawGrp = (row.getAttribute('data-group') || '').trim();
            const normGrp = (!rawGrp || rawGrp.toLowerCase() === 'ungrouped') ? 'ungrouped' : rawGrp.toLowerCase();
            groupCounts[normGrp] = (groupCounts[normGrp] || 0) + 1;
        });

        document.querySelectorAll('.group-pill').forEach(pill => {
            const grp = (pill.getAttribute('data-group') || 'all').trim();
            const normGrp = grp.toLowerCase();
            let count = 0;

            if (normGrp === 'all') {
                count = totalCount;
            } else if (normGrp === 'ungrouped') {
                count = groupCounts['ungrouped'] || 0;
            } else {
                count = groupCounts[normGrp] || 0;
            }

            const countEl = pill.querySelector('.group-count');
            if (countEl) {
                countEl.textContent = count;
            } else {
                const label = (grp === 'all') ? 'All' : (grp === 'ungrouped' ? 'Ungrouped' : grp);
                pill.innerHTML = `${label} (<span class="group-count" data-count-group="${grp}">${count}</span>)`;
            }
        });
    }

    // Group Filter Row Visibility
    function filterRowsByGroup(groupName) {
        currentActiveGroup = groupName || 'all';

        document.querySelectorAll('.group-pill').forEach(pill => {
            if (pill.getAttribute('data-group') === currentActiveGroup) {
                pill.classList.add('active');
            } else {
                pill.classList.remove('active');
            }
        });

        document.querySelectorAll('.monitor-row').forEach(row => {
            const rawRowGroup = (row.getAttribute('data-group') || '').trim();
            const normalizedRowGroup = (!rawRowGroup || rawRowGroup.toLowerCase() === 'ungrouped') ? 'ungrouped' : rawRowGroup.toLowerCase();

            let show = false;
            if (currentActiveGroup === 'all') {
                show = true;
            } else if (currentActiveGroup === 'ungrouped') {
                show = (normalizedRowGroup === 'ungrouped');
            } else if (normalizedRowGroup === currentActiveGroup.toLowerCase()) {
                show = true;
            }

            if (show) {
                row.style.display = '';
            } else {
                row.style.display = 'none';
                const cb = row.querySelector('.monitor-checkbox');
                if (cb) cb.checked = false;
            }
        });

        updateBulkToolbar();
    }

    // Event delegation on group filter bar
    const groupFilterBar = document.getElementById('groupFilterBar');
    if (groupFilterBar) {
        groupFilterBar.addEventListener('click', (e) => {
            const pill = e.target.closest('.group-pill');
            if (!pill) return;
            const grp = pill.getAttribute('data-group') || 'all';
            filterRowsByGroup(grp);
        });
    }

    // --- Tab Navigation with State Persistence ---
    const tabButtons = document.querySelectorAll('.tab-btn');
    const tabPanes = document.querySelectorAll('.tab-pane');

    function activateTab(targetId) {
        if (!targetId) return;
        const btn = document.querySelector(`.tab-btn[data-tab="${targetId}"]`);
        const targetPane = document.getElementById(targetId);
        if (btn && targetPane) {
            tabButtons.forEach(b => b.classList.remove('active'));
            tabPanes.forEach(p => p.style.display = 'none');
            btn.classList.add('active');
            targetPane.style.display = 'block';
            try {
                sessionStorage.setItem('active_tab', targetId);
                history.replaceState(null, '', '#' + targetId.replace('tab-', ''));
            } catch (e) {}
        }
    }

    tabButtons.forEach(btn => {
        btn.addEventListener('click', () => {
            const targetId = btn.getAttribute('data-tab');
            activateTab(targetId);
        });
    });

    // --- Sub-Tab Navigation (Active vs Inactive vs Trash) with State Persistence ---
    const subtabButtons = document.querySelectorAll('.subtab-btn');

    function activateSubtab(targetId) {
        if (!targetId) return;
        const btn = document.querySelector(`.subtab-btn[data-subtab="${targetId}"]`);
        const targetPane = document.getElementById(targetId);
        if (btn && targetPane) {
            const container = btn.closest('.panel') || document;
            container.querySelectorAll('.subtab-btn').forEach(b => b.classList.remove('active'));
            container.querySelectorAll('.subtab-pane').forEach(p => p.style.display = 'none');
            btn.classList.add('active');
            targetPane.style.display = 'block';

            // Show or hide groupFilterBar depending on subtab
            if (groupFilterBar) {
                groupFilterBar.style.display = (targetId === 'subtab-trash') ? 'none' : 'flex';
            }

            try {
                sessionStorage.setItem('active_subtab', targetId);
            } catch (e) {}

            // Uncheck any monitor checkboxes to avoid accidental cross-tab bulk action bleed
            document.querySelectorAll('.monitor-checkbox').forEach(cb => { cb.checked = false; });
            document.querySelectorAll('.select-all-checkbox').forEach(cb => { cb.checked = false; cb.indeterminate = false; });

            // Refresh group pill counts for this subtab and apply group filter
            updateGroupPillCounts();
            filterRowsByGroup(currentActiveGroup);
        }
    }

    subtabButtons.forEach(btn => {
        btn.addEventListener('click', () => {
            const targetId = btn.getAttribute('data-subtab');
            activateSubtab(targetId);
        });
    });

    // Restore active tab & subtab on initial load
    const hash = window.location.hash.replace('#', '');
    let initialTab = null;
    if (hash) {
        if (document.getElementById(`tab-${hash}`)) initialTab = `tab-${hash}`;
        else if (document.getElementById(hash)) initialTab = hash;
    }
    if (!initialTab) {
        try {
            initialTab = sessionStorage.getItem('active_tab');
        } catch (e) {}
    }
    if (initialTab && document.getElementById(initialTab)) {
        activateTab(initialTab);
    }

    let initialSubtab = null;
    try {
        initialSubtab = sessionStorage.getItem('active_subtab');
    } catch (e) {}
    if (initialSubtab && document.getElementById(initialSubtab)) {
        activateSubtab(initialSubtab);
    } else {
        // Initial pill count update on active subtab
        updateGroupPillCounts();
    }

    // --- Live Next Check Countdown Timer Ticker ---
    function updateNextCheckTimers() {
        const now = Math.floor(Date.now() / 1000);
        document.querySelectorAll('.next-check-timer').forEach(el => {
            const nextTs = parseInt(el.getAttribute('data-next-timestamp'), 10);
            if (!nextTs || isNaN(nextTs)) return;

            const diff = nextTs - now;
            if (diff <= 0) {
                el.innerHTML = '<span style="color: var(--success);">⚡ Due Now</span>';
            } else {
                const hours = Math.floor(diff / 3600);
                const mins = Math.floor((diff % 3600) / 60);
                const secs = diff % 60;

                let formatted = '⏳ in ';
                if (hours > 0) {
                    formatted += `${hours}h ${mins}m`;
                } else if (mins > 0) {
                    formatted += `${mins}m ${secs.toString().padStart(2, '0')}s`;
                } else {
                    formatted += `${secs}s`;
                }
                el.innerHTML = formatted;
            }
        });
    }
    updateNextCheckTimers();
    setInterval(updateNextCheckTimers, 1000);

    // --- Toast Notifications ---
    window.showToast = function(message, type = 'info') {
        let container = document.querySelector('.toast-container');
        if (!container) {
            container = document.createElement('div');
            container.className = 'toast-container';
            document.body.appendChild(container);
        }

        const toast = document.createElement('div');
        toast.className = `toast toast-${type}`;
        toast.innerHTML = `<span>${escapeHtml(message)}</span>`;
        container.appendChild(toast);

        setTimeout(() => {
            toast.style.opacity = '0';
            toast.style.transform = 'translateX(50px)';
            toast.style.transition = 'all 0.3s ease';
            setTimeout(() => toast.remove(), 300);
        }, 4000);
    };

    function escapeHtml(str) {
        if (!str) return '';
        const div = document.createElement('div');
        div.textContent = str;
        return div.innerHTML;
    }

    // --- Modals ---
    window.openModal = function(modalId) {
        const modal = document.getElementById(modalId);
        if (modal) {
            modal.classList.add('open');
            document.body.style.overflow = 'hidden';
        }
    };

    window.closeModal = function(modalId) {
        const modal = document.getElementById(modalId);
        if (modal) {
            modal.classList.remove('open');
            document.body.style.overflow = '';
        }
    };

    document.querySelectorAll('.modal-backdrop').forEach(modal => {
        modal.addEventListener('click', (e) => {
            if (e.target === modal) {
                modal.classList.remove('open');
                document.body.style.overflow = '';
            }
        });
    });

    // --- Add/Edit Monitor Modal ---
    const monitorForm = document.getElementById('monitorForm');
    const addMonitorBtn = document.getElementById('addMonitorBtn');

    if (addMonitorBtn) {
        addMonitorBtn.addEventListener('click', () => {
            if (monitorForm) monitorForm.reset();
            document.getElementById('monitorModalTitle').textContent = 'Add New Target Monitor';
            document.getElementById('monitorId').value = '';
            document.getElementById('previewOutput').style.display = 'none';
            openModal('monitorModal');
        });
    }

    window.editMonitor = function(data) {
        if (monitorForm) {
            monitorForm.reset();
            document.getElementById('monitorModalTitle').textContent = 'Edit Monitor';
            document.getElementById('monitorId').value = data.id || '';
            document.getElementById('monitorName').value = data.name || '';
            document.getElementById('monitorUrl').value = data.url || '';
            document.getElementById('monitorGroup').value = data.group || 'General';
            document.getElementById('monitorType').value = data.type || 'html_full';
            document.getElementById('monitorSelector').value = data.selector || '';
            document.getElementById('monitorTemplate').value = data.browser_template || 'chrome_mac';
            document.getElementById('monitorInterval').value = data.interval_mins || 15;
            document.getElementById('monitorHeaders').value = data.custom_headers || '';
            document.getElementById('monitorCookies').value = data.cookies || '';
            document.getElementById('monitorStripTags').checked = !!data.strip_tags;
            document.getElementById('monitorSimulateDelay').checked = !!data.simulate_delay;
            document.getElementById('monitorNotifyChange').checked = (data.notify_on_change !== false);
            document.getElementById('monitorNotifyError').checked = (data.notify_on_error !== false);

            const peakEnabled = !!data.peak_schedule_enabled;
            const peakCheckbox = document.getElementById('monitorPeakScheduleEnabled');
            const peakFields = document.getElementById('peakScheduleFields');
            if (peakCheckbox) peakCheckbox.checked = peakEnabled;
            if (peakFields) peakFields.style.display = peakEnabled ? 'block' : 'none';

            if (document.getElementById('monitorPeakStart')) document.getElementById('monitorPeakStart').value = data.peak_start_hour ?? 9;
            if (document.getElementById('monitorPeakEnd')) document.getElementById('monitorPeakEnd').value = data.peak_end_hour ?? 18;
            if (document.getElementById('monitorPeakInterval')) document.getElementById('monitorPeakInterval').value = data.peak_interval_mins ?? 5;
            if (document.getElementById('monitorOffpeakInterval')) document.getElementById('monitorOffpeakInterval').value = data.offpeak_interval_mins ?? 60;

            document.getElementById('previewOutput').style.display = 'none';
            openModal('monitorModal');
        }
    };

    // Toggle Peak Schedule UI
    const monitorPeakScheduleEnabled = document.getElementById('monitorPeakScheduleEnabled');
    if (monitorPeakScheduleEnabled) {
        monitorPeakScheduleEnabled.addEventListener('change', (e) => {
            const peakFields = document.getElementById('peakScheduleFields');
            if (peakFields) {
                peakFields.style.display = e.target.checked ? 'block' : 'none';
            }
        });
    }

    // --- Save Monitor Form ---
    if (monitorForm) {
        monitorForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            const submitBtn = monitorForm.querySelector('button[type="submit"]');
            const origText = submitBtn.innerHTML;
            submitBtn.disabled = true;
            submitBtn.innerHTML = 'Saving...';

            const payload = {
                id: document.getElementById('monitorId').value,
                name: document.getElementById('monitorName').value,
                url: document.getElementById('monitorUrl').value,
                group: document.getElementById('monitorGroup')?.value || 'General',
                type: document.getElementById('monitorType').value,
                selector: document.getElementById('monitorSelector').value,
                browser_template: document.getElementById('monitorTemplate').value,
                interval_mins: parseInt(document.getElementById('monitorInterval').value, 10),
                peak_schedule_enabled: document.getElementById('monitorPeakScheduleEnabled')?.checked || false,
                peak_start_hour: parseInt(document.getElementById('monitorPeakStart')?.value || '9', 10),
                peak_end_hour: parseInt(document.getElementById('monitorPeakEnd')?.value || '18', 10),
                peak_interval_mins: parseInt(document.getElementById('monitorPeakInterval')?.value || '5', 10),
                offpeak_interval_mins: parseInt(document.getElementById('monitorOffpeakInterval')?.value || '60', 10),
                custom_headers: document.getElementById('monitorHeaders').value,
                cookies: document.getElementById('monitorCookies').value,
                strip_tags: document.getElementById('monitorStripTags').checked,
                simulate_delay: document.getElementById('monitorSimulateDelay').checked,
                notify_on_change: document.getElementById('monitorNotifyChange').checked,
                notify_on_error: document.getElementById('monitorNotifyError').checked,
                csrf_token: csrfToken,
            };

            try {
                const res = await fetch('api.php?action=save_monitor', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken },
                    body: JSON.stringify(payload)
                });
                const data = await res.json();
                if (data.success) {
                    showToast('Monitor saved successfully!', 'success');
                    closeModal('monitorModal');
                    setTimeout(() => location.reload(), 600);
                } else {
                    showToast(data.error || 'Failed to save monitor', 'error');
                }
            } catch (err) {
                showToast('Network error while saving', 'error');
            } finally {
                submitBtn.disabled = false;
                submitBtn.innerHTML = origText;
            }
        });
    }

    // --- Test & Live Preview Selector ---
    const testPreviewBtn = document.getElementById('testPreviewBtn');
    if (testPreviewBtn) {
        testPreviewBtn.addEventListener('click', async () => {
            const url = document.getElementById('monitorUrl').value;
            if (!url) {
                showToast('Please enter a target URL first', 'error');
                return;
            }

            const previewOutput = document.getElementById('previewOutput');
            const previewContent = document.getElementById('previewContent');
            const previewMeta = document.getElementById('previewMeta');

            testPreviewBtn.disabled = true;
            testPreviewBtn.innerHTML = 'Fetching & Extracting...';
            previewOutput.style.display = 'block';
            previewContent.textContent = 'Loading snapshot preview...';

            const payload = {
                url: url,
                type: document.getElementById('monitorType').value,
                selector: document.getElementById('monitorSelector').value,
                browser_template: document.getElementById('monitorTemplate').value,
                custom_headers: document.getElementById('monitorHeaders').value,
                cookies: document.getElementById('monitorCookies').value,
                strip_tags: document.getElementById('monitorStripTags').checked,
                csrf_token: csrfToken,
            };

            try {
                const res = await fetch('api.php?action=test_preview', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken },
                    body: JSON.stringify(payload)
                });
                const text = await res.text();
                let data;
                try {
                    data = JSON.parse(text);
                } catch (e) {
                    throw new Error(text.trim() || 'Invalid response from server');
                }

                if (data.success) {
                    previewMeta.innerHTML = `<span class="badge badge-active">HTTP ${data.http_code}</span> &bull; Extracted Size: ${data.extracted_length} chars &bull; Speed: ${data.duration_ms}ms`;
                    previewContent.textContent = data.extracted || '(Empty match result)';
                    showToast('Extraction preview successful!', 'success');
                } else {
                    previewMeta.innerHTML = `<span class="badge badge-error">Failed (${data.http_code || 'Error'})</span> &bull; Speed: ${data.duration_ms || 0}ms`;
                    previewContent.textContent = 'Error: ' + (data.error || 'Unknown extraction error');
                    showToast(data.error || 'Extraction failed', 'error');
                }
            } catch (err) {
                previewContent.textContent = 'Request error: ' + err.message;
                showToast('Failed to fetch preview: ' + err.message, 'error');
            } finally {
                testPreviewBtn.disabled = false;
                testPreviewBtn.innerHTML = 'Test & Live Preview';
            }
        });
    }

    // --- Action: Run Single Monitor ---
    window.runCheck = async function(id, btn) {
        if (btn) {
            btn.disabled = true;
            btn.innerHTML = '⏳';
        }
        try {
            const res = await fetch('api.php?action=run_check', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken },
                body: JSON.stringify({ id: id, csrf_token: csrfToken })
            });
            const data = await res.json();
            if (data.success) {
                const r = data.result;
                if (r.changed) {
                    showToast(`🚨 Change detected on monitor! (${r.duration_ms}ms)`, 'error');
                } else if (r.success) {
                    showToast(`✅ Check passed. No changes detected (${r.duration_ms}ms)`, 'success');
                } else {
                    showToast(`⚠️ Check failed: ${r.error}`, 'error');
                }
                setTimeout(() => location.reload(), 1000);
            } else {
                showToast(data.error || 'Check failed', 'error');
            }
        } catch (e) {
            showToast('Network error running check', 'error');
        } finally {
            if (btn) {
                btn.disabled = false;
                btn.innerHTML = '▶';
            }
        }
    };

    // --- Action: Run All Monitors ---
    const runAllBtn = document.getElementById('runAllBtn');
    if (runAllBtn) {
        runAllBtn.addEventListener('click', async () => {
            runAllBtn.disabled = true;
            runAllBtn.innerHTML = 'Running checks...';
            try {
                const res = await fetch('api.php?action=run_all', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken },
                    body: JSON.stringify({ csrf_token: csrfToken })
                });
                const data = await res.json();
                if (data.success) {
                    showToast('All monitors checked successfully!', 'success');
                    setTimeout(() => location.reload(), 800);
                } else {
                    showToast(data.error || 'Failed to run all', 'error');
                }
            } catch (err) {
                showToast('Network error running all checks', 'error');
            } finally {
                runAllBtn.disabled = false;
                runAllBtn.innerHTML = '▶ Run All Checks';
            }
        });
    }

    // --- Action: Toggle Status (Enable/Disable Monitor) ---
    window.toggleStatus = async function(id, btn) {
        if (btn) {
            btn.disabled = true;
            btn.innerHTML = '⏳';
        }
        try {
            const res = await fetch('api.php?action=toggle_status', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken },
                body: JSON.stringify({ id: id, csrf_token: csrfToken })
            });
            const data = await res.json();
            if (data.success) {
                showToast(`Monitor ${data.status === 'active' ? 'enabled & active' : 'disabled & paused'}`, 'success');
                setTimeout(() => location.reload(), 400);
            } else {
                showToast(data.error || 'Failed to toggle monitor status', 'error');
            }
        } catch (e) {
            showToast('Network error updating status', 'error');
        } finally {
            if (btn) btn.disabled = false;
        }
    };

    // --- Action: Delete Monitor ---
    window.deleteMonitor = async function(id, name) {
        if (!confirm(`Are you sure you want to delete monitor "${name}"?`)) {
            return;
        }
        try {
            const res = await fetch('api.php?action=delete_monitor', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken },
                body: JSON.stringify({ id: id, csrf_token: csrfToken })
            });
            const data = await res.json();
            if (data.success) {
                showToast('Monitor deleted', 'success');
                setTimeout(() => location.reload(), 500);
            } else {
                showToast(data.error || 'Failed to delete', 'error');
            }
        } catch (e) {
            showToast('Network error deleting monitor', 'error');
        }
    };

    // --- Action: View History & Snapshot ---
    let currentHistoryMonitorId = null;
    window.viewHistory = async function(id) {
        currentHistoryMonitorId = id;
        openModal('historyModal');
        const historySnapshot = document.getElementById('historySnapshot');
        const historyTitle = document.getElementById('historyModalTitle');
        const snapshotSelect = document.getElementById('snapshotSelect');
        const historyLogMeta = document.getElementById('historyLogMeta');
        const downloadHistoryBtn = document.getElementById('downloadHistoryBtn');

        if (downloadHistoryBtn) {
            downloadHistoryBtn.href = `api.php?action=download_history_log&id=${encodeURIComponent(id)}`;
        }
        historySnapshot.textContent = 'Loading snapshot...';
        if (snapshotSelect) snapshotSelect.innerHTML = '<option value="">Latest Captured Snapshot</option>';

        try {
            const res = await fetch(`api.php?action=get_history&id=${encodeURIComponent(id)}`);
            const data = await res.json();
            if (data.success) {
                historyTitle.textContent = `History: ${data.monitor?.name || id}`;
                historySnapshot.textContent = data.current_snapshot || data.latest_snapshot || 'No snapshot captured yet.';
                
                if (historyLogMeta) {
                    historyLogMeta.textContent = `Total Checks: ${data.monitor?.check_count || 0} | Changes: ${data.monitor?.change_count || 0}`;
                }

                // Populate Snapshots Dropdown
                if (snapshotSelect && data.snapshots_list && data.snapshots_list.length > 0) {
                    let opts = '<option value="">Latest Snapshot (Active)</option>';
                    data.snapshots_list.forEach((snap, idx) => {
                        opts += `<option value="${escapeHtml(snap.file)}">${escapeHtml(snap.time)} (${snap.size} bytes)${idx === 0 ? ' [Newest]' : ''}</option>`;
                    });
                    snapshotSelect.innerHTML = opts;
                }
            } else {
                historySnapshot.textContent = 'Failed to load history: ' + data.error;
            }
        } catch (e) {
            historySnapshot.textContent = 'Network error loading history: ' + e.message;
        }
    };

    // Snapshot Dropdown Change Handler
    const snapshotSelect = document.getElementById('snapshotSelect');
    if (snapshotSelect) {
        snapshotSelect.addEventListener('change', async (e) => {
            if (!currentHistoryMonitorId) return;
            const historySnapshot = document.getElementById('historySnapshot');
            const selectedFile = e.target.value;
            historySnapshot.textContent = 'Loading selected snapshot version...';

            try {
                const res = await fetch(`api.php?action=get_history&id=${encodeURIComponent(currentHistoryMonitorId)}&snapshot_file=${encodeURIComponent(selectedFile)}`);
                const data = await res.json();
                if (data.success) {
                    historySnapshot.textContent = data.current_snapshot || data.latest_snapshot || '(Empty content)';
                } else {
                    historySnapshot.textContent = 'Failed to load snapshot version: ' + data.error;
                }
            } catch (err) {
                historySnapshot.textContent = 'Network error loading snapshot version: ' + err.message;
            }
        });
    }

    // --- Settings Form ---
    const settingsForm = document.getElementById('settingsForm');
    if (settingsForm) {
        settingsForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            const submitBtn = settingsForm.querySelector('button[type="submit"]');
            submitBtn.disabled = true;
            submitBtn.innerHTML = 'Saving Settings...';

            const payload = {
                app_timezone: document.getElementById('settingAppTimezone')?.value || 'Asia/Kolkata',
                gmail_smtp_host: document.getElementById('settingSmtpHost').value,
                gmail_smtp_port: parseInt(document.getElementById('settingSmtpPort').value, 10),
                gmail_smtp_user: document.getElementById('settingSmtpUser').value,
                gmail_smtp_pass: document.getElementById('settingSmtpPass').value,
                alert_email_to: document.getElementById('settingAlertEmail').value,
                telegram_bot_token: document.getElementById('settingTelegramToken').value,
                telegram_chat_id: document.getElementById('settingTelegramChatId').value,
                openwa_api_url: document.getElementById('settingOpenwaUrl').value,
                openwa_api_key: document.getElementById('settingOpenwaKey').value,
                openwa_chat_id: document.getElementById('settingOpenwaChatId').value,
                notify_on_change: document.getElementById('settingNotifyChange').checked,
                notify_on_error: document.getElementById('settingNotifyError').checked,
                default_interval_mins: parseInt(document.getElementById('settingDefaultInterval').value, 10),
                new_password: document.getElementById('settingNewPassword').value,
                csrf_token: csrfToken,
            };

            try {
                const res = await fetch('api.php?action=save_settings', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken },
                    body: JSON.stringify(payload)
                });
                const data = await res.json();
                if (data.success) {
                    showToast('Settings saved successfully!', 'success');
                    document.getElementById('settingNewPassword').value = '';
                } else {
                    showToast(data.error || 'Failed to save settings', 'error');
                }
            } catch (err) {
                showToast('Network error saving settings', 'error');
            } finally {
                submitBtn.disabled = false;
                submitBtn.innerHTML = 'Save All Settings';
            }
        });
    }

    // --- Test Notification Buttons ---
    document.querySelectorAll('.test-notify-btn').forEach(btn => {
        btn.addEventListener('click', async () => {
            const channel = btn.getAttribute('data-channel');
            btn.disabled = true;
            const orig = btn.innerHTML;
            btn.innerHTML = 'Testing...';

            const payload = {
                channel: channel,
                telegram_bot_token: document.getElementById('settingTelegramToken')?.value,
                telegram_chat_id: document.getElementById('settingTelegramChatId')?.value,
                openwa_api_url: document.getElementById('settingOpenwaUrl')?.value,
                openwa_api_key: document.getElementById('settingOpenwaKey')?.value,
                openwa_chat_id: document.getElementById('settingOpenwaChatId')?.value,
                gmail_smtp_host: document.getElementById('settingSmtpHost')?.value,
                gmail_smtp_port: document.getElementById('settingSmtpPort')?.value,
                gmail_smtp_user: document.getElementById('settingSmtpUser')?.value,
                gmail_smtp_pass: document.getElementById('settingSmtpPass')?.value,
                alert_email_to: document.getElementById('settingAlertEmail')?.value,
                csrf_token: csrfToken,
            };

            try {
                const res = await fetch('api.php?action=test_notification', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken },
                    body: JSON.stringify(payload)
                });
                const data = await res.json();
                if (data.success) {
                    const result = data.results[channel];
                    if (result && result.success) {
                        showToast(`✅ ${channel.toUpperCase()} notification delivered!`, 'success');
                    } else {
                        showToast(`❌ ${channel.toUpperCase()} error: ${result?.error || 'Failed'}`, 'error');
                    }
                } else {
                    showToast(data.error || 'Test notification failed', 'error');
                }
            } catch (err) {
                showToast('Network error sending test notification', 'error');
            } finally {
                btn.disabled = false;
                btn.innerHTML = orig;
            }
        });
    });

    // --- Single-File Backup Restore Handler ---
    const restoreFileInput = document.getElementById('restoreFileInput');
    if (restoreFileInput) {
        restoreFileInput.addEventListener('change', async (e) => {
            const file = e.target.files[0];
            if (!file) return;

            if (!confirm(`Are you sure you want to restore "${file.name}"? This will overwrite existing targets and settings with the backup file data.`)) {
                restoreFileInput.value = '';
                return;
            }

            const formData = new FormData();
            formData.append('backup_file', file);
            formData.append('csrf_token', csrfToken);

            showToast('Restoring backup file...', 'info');

            try {
                const res = await fetch('api.php?action=restore_backup', {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': csrfToken },
                    body: formData
                });
                const data = await res.json();
                if (data.success) {
                    showToast('✅ Full backup restored successfully!', 'success');
                    setTimeout(() => location.reload(), 1000);
                } else {
                    showToast('❌ Restore failed: ' + (data.error || 'Unknown error'), 'error');
                }
            } catch (err) {
                showToast('Network error while restoring backup file', 'error');
            } finally {
                restoreFileInput.value = '';
            }
        });
    }

    // --- Searchable & Filterable Error Logs Controller ---
    const logsTableBody = document.getElementById('logsTableBody');
    const logSearchInput = document.getElementById('logSearchInput');
    const logLevelSelect = document.getElementById('logLevelSelect');
    const logCategorySelect = document.getElementById('logCategorySelect');
    const refreshLogsBtn = document.getElementById('refreshLogsBtn');
    const clearLogsBtn = document.getElementById('clearLogsBtn');

    async function loadLogs() {
        if (!logsTableBody) return;
        const search = logSearchInput ? logSearchInput.value.trim() : '';
        const level = logLevelSelect ? logLevelSelect.value : '';
        const category = logCategorySelect ? logCategorySelect.value : '';

        const params = new URLSearchParams({
            action: 'get_logs',
            search: search,
            level: level,
            category: category,
            limit: 200
        });

        try {
            const res = await fetch(`api.php?${params.toString()}`);
            const data = await res.json();
            if (!data.success) {
                logsTableBody.innerHTML = `<tr><td colspan="5" style="text-align: center; color: var(--danger); padding: 1.5rem;">Error: ${escapeHtml(data.error)}</td></tr>`;
                return;
            }

            if (!data.logs || data.logs.length === 0) {
                logsTableBody.innerHTML = `<tr><td colspan="5" style="text-align: center; color: var(--text-muted); padding: 2rem;">No logs found matching criteria.</td></tr>`;
                return;
            }

            let html = '';
            data.logs.forEach(row => {
                const levelBadge = row.level === 'ERROR' ? '<span class="badge badge-error">ERROR</span>'
                    : (row.level === 'WARNING' ? '<span class="badge badge-changed">WARN</span>'
                    : '<span class="badge badge-active">INFO</span>');

                let contextStr = '';
                if (row.context && Object.keys(row.context).length > 0) {
                    contextStr = `<div style="font-family: var(--font-mono); font-size: 0.75rem; color: var(--text-muted); margin-top: 0.25rem;">${escapeHtml(JSON.stringify(row.context))}</div>`;
                }

                html += `
                    <tr>
                        <td style="font-size: 0.8rem; color: var(--text-secondary); white-space: nowrap;">${escapeHtml(row.timestamp || '-')}</td>
                        <td>${levelBadge}</td>
                        <td><span class="badge badge-type">${escapeHtml(row.category || 'SYSTEM')}</span></td>
                        <td>
                            <div style="font-weight: 500;">${escapeHtml(row.message || '')}</div>
                            ${contextStr}
                        </td>
                        <td style="font-size: 0.775rem; color: var(--text-muted);">${escapeHtml(row.ip || '-')}</td>
                    </tr>
                `;
            });
            logsTableBody.innerHTML = html;
        } catch (e) {
            logsTableBody.innerHTML = `<tr><td colspan="5" style="text-align: center; color: var(--danger); padding: 1.5rem;">Network error fetching logs</td></tr>`;
        }
    }

    if (refreshLogsBtn) {
        refreshLogsBtn.addEventListener('click', loadLogs);
    }

    if (logLevelSelect) {
        logLevelSelect.addEventListener('change', loadLogs);
    }

    if (logCategorySelect) {
        logCategorySelect.addEventListener('change', loadLogs);
    }

    let searchTimeout = null;
    if (logSearchInput) {
        logSearchInput.addEventListener('input', () => {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(loadLogs, 300);
        });
    }

    // Load logs automatically when tab is clicked
    document.querySelectorAll('.tab-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            if (btn.getAttribute('data-tab') === 'tab-logs') {
                loadLogs();
            }
        });
    });

    if (clearLogsBtn) {
        clearLogsBtn.addEventListener('click', async () => {
            if (!confirm('Are you sure you want to clear all server logs?')) return;
            try {
                const res = await fetch('api.php?action=clear_logs', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken },
                    body: JSON.stringify({ csrf_token: csrfToken })
                });
                const data = await res.json();
                if (data.success) {
                    showToast('Logs cleared', 'success');
                    loadLogs();
                } else {
                    showToast(data.error || 'Failed to clear logs', 'error');
                }
            } catch (err) {
                showToast('Network error clearing logs', 'error');
            }
        });
    }

    // ==========================================
    // --- BULK ACTION BUTTON EVENT LISTENERS ---
    // ==========================================

    const bulkActivateBtn = document.getElementById('bulkActivateBtn');
    const bulkPauseBtn = document.getElementById('bulkPauseBtn');
    const bulkRunBtn = document.getElementById('bulkRunBtn');
    const bulkDeleteBtn = document.getElementById('bulkDeleteBtn');
    const bulkDeselectBtn = document.getElementById('bulkDeselectBtn');
    const selectAllCheckboxes = document.querySelectorAll('.select-all-checkbox');
    const monitorCheckboxes = document.querySelectorAll('.monitor-checkbox');

    // Individual checkbox click
    monitorCheckboxes.forEach(cb => {
        cb.addEventListener('change', updateBulkToolbar);
    });

    // Select All master checkboxes (only check/uncheck visible rows)
    selectAllCheckboxes.forEach(master => {
        master.addEventListener('change', (e) => {
            const target = master.getAttribute('data-target');
            const subtabPane = document.getElementById(`subtab-${target}`);
            if (subtabPane) {
                const boxes = subtabPane.querySelectorAll('.monitor-checkbox');
                boxes.forEach(b => {
                    const row = b.closest('tr');
                    if (row && row.style.display !== 'none') {
                        b.checked = e.target.checked;
                    }
                });
                updateBulkToolbar();
            }
        });
    });

    // Deselect all button
    if (bulkDeselectBtn) {
        bulkDeselectBtn.addEventListener('click', () => {
            monitorCheckboxes.forEach(cb => { cb.checked = false; });
            selectAllCheckboxes.forEach(cb => { cb.checked = false; cb.indeterminate = false; });
            updateBulkToolbar();
        });
    }

    // Bulk Action: Activate
    if (bulkActivateBtn) {
        bulkActivateBtn.addEventListener('click', async () => {
            const ids = getSelectedMonitorIds();
            if (ids.length === 0) return;

            bulkActivateBtn.disabled = true;
            const orig = bulkActivateBtn.innerHTML;
            bulkActivateBtn.innerHTML = '⏳ Activating...';

            try {
                const res = await fetch('api.php?action=bulk_status', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken },
                    body: JSON.stringify({ ids: ids, status: 'active', csrf_token: csrfToken })
                });
                const data = await res.json();
                if (data.success) {
                    showToast(data.message || `Activated ${data.updated_count} monitors`, 'success');
                    setTimeout(() => location.reload(), 600);
                } else {
                    showToast(data.error || 'Failed to activate monitors', 'error');
                }
            } catch (err) {
                showToast('Network error in bulk activation', 'error');
            } finally {
                bulkActivateBtn.disabled = false;
                bulkActivateBtn.innerHTML = orig;
            }
        });
    }

    // Bulk Action: Pause / Inactivate
    if (bulkPauseBtn) {
        bulkPauseBtn.addEventListener('click', async () => {
            const ids = getSelectedMonitorIds();
            if (ids.length === 0) return;

            bulkPauseBtn.disabled = true;
            const orig = bulkPauseBtn.innerHTML;
            bulkPauseBtn.innerHTML = '⏳ Pausing...';

            try {
                const res = await fetch('api.php?action=bulk_status', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken },
                    body: JSON.stringify({ ids: ids, status: 'paused', csrf_token: csrfToken })
                });
                const data = await res.json();
                if (data.success) {
                    showToast(data.message || `Paused ${data.updated_count} monitors`, 'success');
                    setTimeout(() => location.reload(), 600);
                } else {
                    showToast(data.error || 'Failed to pause monitors', 'error');
                }
            } catch (err) {
                showToast('Network error in bulk pause', 'error');
            } finally {
                bulkPauseBtn.disabled = false;
                bulkPauseBtn.innerHTML = orig;
            }
        });
    }

    // Bulk Action: Run Now
    if (bulkRunBtn) {
        bulkRunBtn.addEventListener('click', async () => {
            const ids = getSelectedMonitorIds();
            if (ids.length === 0) return;

            bulkRunBtn.disabled = true;
            const orig = bulkRunBtn.innerHTML;
            bulkRunBtn.innerHTML = '⏳ Checking...';

            try {
                const res = await fetch('api.php?action=bulk_run_check', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken },
                    body: JSON.stringify({ ids: ids, csrf_token: csrfToken })
                });
                const data = await res.json();
                if (data.success) {
                    showToast(data.message || `Executed checks for ${data.count} monitors`, 'success');
                    setTimeout(() => location.reload(), 800);
                } else {
                    showToast(data.error || 'Failed to run checks', 'error');
                }
            } catch (err) {
                showToast('Network error running bulk checks', 'error');
            } finally {
                bulkRunBtn.disabled = false;
                bulkRunBtn.innerHTML = orig;
            }
        });
    }

    // Bulk Action: Delete
    if (bulkDeleteBtn) {
        bulkDeleteBtn.addEventListener('click', async () => {
            const ids = getSelectedMonitorIds();
            if (ids.length === 0) return;

            if (!confirm(`Are you sure you want to permanently delete ${ids.length} selected monitor(s)?`)) {
                return;
            }

            bulkDeleteBtn.disabled = true;
            const orig = bulkDeleteBtn.innerHTML;
            bulkDeleteBtn.innerHTML = '⏳ Deleting...';

            try {
                const res = await fetch('api.php?action=bulk_delete', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken },
                    body: JSON.stringify({ ids: ids, csrf_token: csrfToken })
                });
                const data = await res.json();
                if (data.success) {
                    showToast(data.message || `Deleted ${data.deleted_count} monitors`, 'success');
                    setTimeout(() => location.reload(), 600);
                } else {
                    showToast(data.error || 'Failed to delete monitors', 'error');
                }
            } catch (err) {
                showToast('Network error deleting monitors', 'error');
            } finally {
                bulkDeleteBtn.disabled = false;
                bulkDeleteBtn.innerHTML = orig;
            }
        });
    }

    // Bulk Group Assign Modal & Submit
    const bulkGroupBtn = document.getElementById('bulkGroupBtn');
    const bulkGroupModal = document.getElementById('bulkGroupModal');
    const bulkGroupForm = document.getElementById('bulkGroupForm');
    const bulkGroupTargetCount = document.getElementById('bulkGroupTargetCount');
    const bulkGroupInput = document.getElementById('bulkGroupInput');

    if (bulkGroupBtn) {
        bulkGroupBtn.addEventListener('click', () => {
            const ids = getSelectedMonitorIds();
            if (ids.length === 0) return;
            if (bulkGroupTargetCount) bulkGroupTargetCount.textContent = ids.length;
            if (bulkGroupInput) bulkGroupInput.value = '';
            openModal('bulkGroupModal');
        });
    }

    if (bulkGroupForm) {
        bulkGroupForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            const ids = getSelectedMonitorIds();
            const groupName = bulkGroupInput.value.trim() || 'Ungrouped';

            if (ids.length === 0) {
                showToast('No monitors selected', 'error');
                return;
            }

            const submitBtn = document.getElementById('bulkGroupSubmitBtn');
            submitBtn.disabled = true;
            submitBtn.innerHTML = 'Assigning...';

            try {
                const res = await fetch('api.php?action=bulk_set_group', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken },
                    body: JSON.stringify({ ids: ids, group: groupName, csrf_token: csrfToken })
                });
                const data = await res.json();
                if (data.success) {
                    showToast(data.message || `Moved ${data.updated_count} monitors to "${groupName}"`, 'success');
                    closeModal('bulkGroupModal');
                    setTimeout(() => location.reload(), 600);
                } else {
                    showToast(data.error || 'Failed to assign group', 'error');
                }
            } catch (err) {
                showToast('Network error assigning group', 'error');
            } finally {
                submitBtn.disabled = false;
                submitBtn.innerHTML = '📁 Assign Group';
            }
        });
    }

    // ==========================================
    // --- BULK EDIT MONITORS MODAL LOGIC ---
    // ==========================================

    const bulkEditBtn = document.getElementById('bulkEditBtn');
    const bulkEditModal = document.getElementById('bulkEditModal');
    const bulkEditForm = document.getElementById('bulkEditForm');
    const bulkEditTargetCount = document.getElementById('bulkEditTargetCount');

    // Toggle conditional bulk edit fields on checkbox changes
    const bulkEditCheckboxes = [
        { check: 'bulkEditApplyGroup', field: 'bulkEditGroupFields' },
        { check: 'bulkEditApplyInterval', field: 'bulkEditIntervalFields' },
        { check: 'bulkEditApplyTemplate', field: 'bulkEditTemplateFields' },
        { check: 'bulkEditApplyType', field: 'bulkEditTypeFields' },
        { check: 'bulkEditApplyTimeout', field: 'bulkEditTimeoutFields' },
        { check: 'bulkEditApplyPeak', field: 'bulkEditPeakFields' },
        { check: 'bulkEditApplyNotifications', field: 'bulkEditNotificationsFields' }
    ];

    bulkEditCheckboxes.forEach(item => {
        const cb = document.getElementById(item.check);
        const container = document.getElementById(item.field);
        if (cb && container) {
            cb.addEventListener('change', () => {
                container.style.display = cb.checked ? 'block' : 'none';
            });
        }
    });

    const bulkEditPeakEnabledVal = document.getElementById('bulkEditPeakEnabledVal');
    const bulkEditPeakConfigBox = document.getElementById('bulkEditPeakConfigBox');
    if (bulkEditPeakEnabledVal && bulkEditPeakConfigBox) {
        bulkEditPeakEnabledVal.addEventListener('change', (e) => {
            bulkEditPeakConfigBox.style.display = e.target.checked ? 'block' : 'none';
        });
    }

    if (bulkEditBtn) {
        bulkEditBtn.addEventListener('click', () => {
            const ids = getSelectedMonitorIds();
            if (ids.length === 0) return;
            if (bulkEditTargetCount) bulkEditTargetCount.textContent = ids.length;
            if (bulkEditForm) bulkEditForm.reset();
            bulkEditCheckboxes.forEach(item => {
                const container = document.getElementById(item.field);
                if (container) container.style.display = 'none';
            });
            openModal('bulkEditModal');
        });
    }

    if (bulkEditForm) {
        bulkEditForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            const ids = getSelectedMonitorIds();
            if (ids.length === 0) {
                showToast('No monitors selected', 'error');
                return;
            }

            const fields = {};
            if (document.getElementById('bulkEditApplyGroup')?.checked) {
                fields.group = document.getElementById('bulkEditGroupVal')?.value || 'Ungrouped';
            }
            if (document.getElementById('bulkEditApplyInterval')?.checked) {
                fields.interval_mins = parseInt(document.getElementById('bulkEditIntervalVal')?.value || '15', 10);
            }
            if (document.getElementById('bulkEditApplyTemplate')?.checked) {
                fields.browser_template = document.getElementById('bulkEditTemplateVal')?.value;
            }
            if (document.getElementById('bulkEditApplyType')?.checked) {
                fields.type = document.getElementById('bulkEditTypeVal')?.value;
            }
            if (document.getElementById('bulkEditApplyTimeout')?.checked) {
                fields.timeout = parseInt(document.getElementById('bulkEditTimeoutVal')?.value || '25', 10);
            }
            if (document.getElementById('bulkEditApplyPeak')?.checked) {
                const peakEnabled = document.getElementById('bulkEditPeakEnabledVal')?.checked || false;
                fields.peak_schedule_enabled = peakEnabled;
                if (peakEnabled) {
                    fields.peak_start_hour = parseInt(document.getElementById('bulkEditPeakStartVal')?.value || '9', 10);
                    fields.peak_end_hour = parseInt(document.getElementById('bulkEditPeakEndVal')?.value || '18', 10);
                    fields.peak_interval_mins = parseInt(document.getElementById('bulkEditPeakIntervalVal')?.value || '5', 10);
                    fields.offpeak_interval_mins = parseInt(document.getElementById('bulkEditOffpeakIntervalVal')?.value || '60', 10);
                }
            }
            if (document.getElementById('bulkEditApplyNotifications')?.checked) {
                fields.strip_tags = document.getElementById('bulkEditStripTagsVal')?.checked;
                fields.notify_on_change = document.getElementById('bulkEditNotifyChangeVal')?.checked;
                fields.notify_on_error = document.getElementById('bulkEditNotifyErrorVal')?.checked;
            }

            if (Object.keys(fields).length === 0) {
                showToast('Please check at least one setting to update', 'warning');
                return;
            }

            const submitBtn = document.getElementById('bulkEditSubmitBtn');
            submitBtn.disabled = true;
            submitBtn.innerHTML = 'Applying...';

            try {
                const res = await fetch('api.php?action=bulk_edit', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken },
                    body: JSON.stringify({ ids: ids, fields: fields, csrf_token: csrfToken })
                });
                const data = await res.json();
                if (data.success) {
                    showToast(data.message || `Successfully updated ${data.updated_count} monitors!`, 'success');
                    closeModal('bulkEditModal');
                    setTimeout(() => location.reload(), 600);
                } else {
                    showToast(data.error || 'Failed to bulk edit monitors', 'error');
                }
            } catch (err) {
                showToast('Network error while bulk editing', 'error');
            } finally {
                submitBtn.disabled = false;
                submitBtn.innerHTML = '✏️ Apply Changes';
            }
        });
    }

    // ==========================================
    // --- TRASH & RESTORE CONTROLLER ---
    // ==========================================

    window.restoreMonitor = async function(id, name) {
        if (!confirm(`Restore "${name || id}" back to active monitoring?`)) return;

        try {
            const res = await fetch('api.php?action=restore_monitor', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken },
                body: JSON.stringify({ id: id, csrf_token: csrfToken })
            });
            const data = await res.json();
            if (data.success) {
                showToast(data.message || 'Target restored successfully!', 'success');
                setTimeout(() => location.reload(), 600);
            } else {
                showToast(data.error || 'Failed to restore target', 'error');
            }
        } catch (e) {
            showToast('Network error restoring target', 'error');
        }
    };

    window.purgeDeletedMonitor = async function(id, name) {
        if (!confirm(`Are you sure you want to permanently delete "${name || id}"? This action cannot be undone.`)) return;

        try {
            const res = await fetch('api.php?action=purge_trash', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken },
                body: JSON.stringify({ id: id, csrf_token: csrfToken })
            });
            const data = await res.json();
            if (data.success) {
                showToast(data.message || 'Target permanently removed', 'success');
                setTimeout(() => location.reload(), 600);
            } else {
                showToast(data.error || 'Failed to purge target', 'error');
            }
        } catch (e) {
            showToast('Network error purging target', 'error');
        }
    };

    // Bulk Trash Actions
    const selectAllTrashCheckbox = document.querySelector('.select-all-trash-checkbox');
    const trashCheckboxes = document.querySelectorAll('.trash-checkbox');
    const bulkRestoreTrashBtn = document.getElementById('bulkRestoreTrashBtn');
    const emptyTrashBtn = document.getElementById('emptyTrashBtn');

    if (selectAllTrashCheckbox) {
        selectAllTrashCheckbox.addEventListener('change', (e) => {
            trashCheckboxes.forEach(cb => { cb.checked = e.target.checked; });
        });
    }

    function getSelectedTrashIds() {
        return Array.from(document.querySelectorAll('.trash-checkbox:checked')).map(cb => cb.value);
    }

    if (bulkRestoreTrashBtn) {
        bulkRestoreTrashBtn.addEventListener('click', async () => {
            const ids = getSelectedTrashIds();
            if (ids.length === 0) {
                showToast('Please select at least one deleted target to restore', 'warning');
                return;
            }

            bulkRestoreTrashBtn.disabled = true;
            bulkRestoreTrashBtn.innerHTML = 'Restoring...';

            try {
                const res = await fetch('api.php?action=bulk_restore', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken },
                    body: JSON.stringify({ ids: ids, csrf_token: csrfToken })
                });
                const data = await res.json();
                if (data.success) {
                    showToast(data.message || `Restored ${data.restored_count} targets!`, 'success');
                    setTimeout(() => location.reload(), 600);
                } else {
                    showToast(data.error || 'Failed to restore targets', 'error');
                }
            } catch (err) {
                showToast('Network error restoring targets', 'error');
            } finally {
                bulkRestoreTrashBtn.disabled = false;
                bulkRestoreTrashBtn.innerHTML = '♻️ Restore Selected';
            }
        });
    }

    if (emptyTrashBtn) {
        emptyTrashBtn.addEventListener('click', async () => {
            if (!confirm('Are you sure you want to permanently delete all items in Trash? This cannot be undone.')) return;

            emptyTrashBtn.disabled = true;
            emptyTrashBtn.innerHTML = 'Emptying...';

            try {
                const res = await fetch('api.php?action=empty_trash', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken },
                    body: JSON.stringify({ csrf_token: csrfToken })
                });
                const data = await res.json();
                if (data.success) {
                    showToast(data.message || 'Trash emptied', 'success');
                    setTimeout(() => location.reload(), 600);
                } else {
                    showToast(data.error || 'Failed to empty trash', 'error');
                }
            } catch (err) {
                showToast('Network error emptying trash', 'error');
            } finally {
                emptyTrashBtn.disabled = false;
                emptyTrashBtn.innerHTML = '🔥 Empty Trash';
            }
        });
    }

    // ==========================================
    // --- BULK ADD MONITORS MODAL LOGIC ---
    // ==========================================

    const bulkAddBtn = document.getElementById('bulkAddBtn');
    const bulkAddModal = document.getElementById('bulkAddModal');
    const bulkAddForm = document.getElementById('bulkAddForm');
    const bulkMonitorsText = document.getElementById('bulkMonitorsText');
    const bulkLineCount = document.getElementById('bulkLineCount');

    function parseBulkMonitors(rawText) {
        if (!rawText) return [];
        const lines = rawText.split('\n');
        const items = [];

        lines.forEach(line => {
            const trimmed = line.trim();
            if (!trimmed || trimmed.startsWith('#')) return;

            let name = '';
            let url = '';

            if (trimmed.includes('|')) {
                const parts = trimmed.split('|');
                name = parts[0].trim();
                url = parts.slice(1).join('|').trim();
            } else if (trimmed.includes(',') && (trimmed.startsWith('http://') || trimmed.startsWith('https://') || trimmed.indexOf('http') > 0)) {
                const firstComma = trimmed.indexOf(',');
                const part1 = trimmed.substring(0, firstComma).trim();
                const part2 = trimmed.substring(firstComma + 1).trim();

                if (part1.startsWith('http://') || part1.startsWith('https://')) {
                    url = part1;
                    name = part2;
                } else {
                    name = part1;
                    url = part2;
                }
            } else {
                url = trimmed;
            }

            if (url) {
                if (!url.startsWith('http://') && !url.startsWith('https://')) {
                    url = 'https://' + url;
                }
                items.push({ name: name, url: url });
            }
        });

        return items;
    }

    if (bulkMonitorsText && bulkLineCount) {
        bulkMonitorsText.addEventListener('input', () => {
            const items = parseBulkMonitors(bulkMonitorsText.value);
            bulkLineCount.textContent = `${items.length} target(s) detected`;
        });
    }

    if (bulkAddBtn) {
        bulkAddBtn.addEventListener('click', () => {
            if (bulkAddForm) bulkAddForm.reset();
            if (bulkLineCount) bulkLineCount.textContent = '0 targets detected';
            openModal('bulkAddModal');
        });
    }

    if (bulkAddForm) {
        bulkAddForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            const text = bulkMonitorsText.value;
            const items = parseBulkMonitors(text);

            if (items.length === 0) {
                showToast('Please enter at least one valid target URL', 'error');
                return;
            }

            const submitBtn = document.getElementById('bulkSubmitBtn');
            submitBtn.disabled = true;
            const orig = submitBtn.innerHTML;
            submitBtn.innerHTML = `Creating ${items.length} targets...`;

            const payload = {
                monitors: items,
                default_group: document.getElementById('bulkDefaultGroup')?.value || 'General',
                default_type: document.getElementById('bulkDefaultType').value,
                default_selector: document.getElementById('bulkDefaultSelector').value,
                default_browser_template: document.getElementById('bulkDefaultTemplate').value,
                default_interval_mins: parseInt(document.getElementById('bulkDefaultInterval').value, 10),
                default_status: document.getElementById('bulkDefaultStatus').value,
                default_timeout: parseInt(document.getElementById('bulkDefaultTimeout').value, 10),
                default_strip_tags: document.getElementById('bulkStripTags').checked,
                run_baseline: document.getElementById('bulkRunBaseline').checked,
                default_notify_on_change: document.getElementById('bulkNotifyChange').checked,
                default_notify_on_error: document.getElementById('bulkNotifyError').checked,
                csrf_token: csrfToken
            };

            try {
                const res = await fetch('api.php?action=bulk_add_monitors', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken },
                    body: JSON.stringify(payload)
                });
                const data = await res.json();
                if (data.success) {
                    showToast(data.message || `Successfully created ${data.count} targets!`, 'success');
                    closeModal('bulkAddModal');
                    setTimeout(() => location.reload(), 700);
                } else {
                    showToast(data.error || 'Failed to bulk add targets', 'error');
                }
            } catch (err) {
                showToast('Network error bulk adding targets', 'error');
            } finally {
                submitBtn.disabled = false;
                submitBtn.innerHTML = orig;
            }
        });
    }
});




