/**
 * Frontend JavaScript Controller for Change Monitor Admin
 */

document.addEventListener('DOMContentLoaded', () => {
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

    // --- Tab Navigation ---
    const tabButtons = document.querySelectorAll('.tab-btn');
    const tabPanes = document.querySelectorAll('.tab-pane');

    tabButtons.forEach(btn => {
        btn.addEventListener('click', () => {
            const targetId = btn.getAttribute('data-tab');
            tabButtons.forEach(b => b.classList.remove('active'));
            tabPanes.forEach(p => p.style.display = 'none');

            btn.classList.add('active');
            const targetPane = document.getElementById(targetId);
            if (targetPane) targetPane.style.display = 'block';
        });
    });

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
                const data = await res.json();
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
                showToast('Failed to fetch preview', 'error');
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
});


