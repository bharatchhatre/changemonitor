/**
 * Frontend JavaScript Controller for Change Monitor Admin
 */

document.addEventListener('DOMContentLoaded', () => {
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

    // ==========================================
    // --- THEME SWITCHER CONTROLLER ---
    // ==========================================
    const themeSelect = document.getElementById('themeSelect');
    const savedTheme = localStorage.getItem('cm_theme') || 'dark';

    function applyTheme(themeName) {
        if (themeName === 'dark') {
            document.documentElement.removeAttribute('data-theme');
        } else {
            document.documentElement.setAttribute('data-theme', themeName);
        }
        localStorage.setItem('cm_theme', themeName);
        if (themeSelect) themeSelect.value = themeName;
    }

    // Apply saved theme immediately
    applyTheme(savedTheme);

    if (themeSelect) {
        themeSelect.addEventListener('change', (e) => {
            applyTheme(e.target.value);
            showToast(`Theme changed to ${e.target.options[e.target.selectedIndex].text}`, 'info');
        });
    }

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

    // ==========================================
    // --- MOBILE DRAWER NAVIGATION CONTROLLER ---
    // ==========================================
    const mobileDrawerOpenBtn = document.getElementById('mobileDrawerOpenBtn');
    const mobileDrawerCloseBtn = document.getElementById('mobileDrawerCloseBtn');
    const mobileDrawerBackdrop = document.getElementById('mobileDrawerBackdrop');

    function openMobileDrawer() {
        if (mobileDrawerBackdrop) {
            mobileDrawerBackdrop.classList.add('show');
            document.body.style.overflow = 'hidden';
        }
    }

    function closeMobileDrawer() {
        if (mobileDrawerBackdrop) {
            mobileDrawerBackdrop.classList.remove('show');
            document.body.style.overflow = '';
        }
    }

    if (mobileDrawerOpenBtn) mobileDrawerOpenBtn.addEventListener('click', openMobileDrawer);
    if (mobileDrawerCloseBtn) mobileDrawerCloseBtn.addEventListener('click', closeMobileDrawer);

    if (mobileDrawerBackdrop) {
        mobileDrawerBackdrop.addEventListener('click', (e) => {
            if (e.target === mobileDrawerBackdrop) closeMobileDrawer();
        });
    }

    // ==========================================
    // --- UNREAD STAT CARD HIGHLIGHT SYSTEM ---
    // ==========================================
    const statCardTargets = document.getElementById('statCardTargets');
    const statCardChecks = document.getElementById('statCardChecks');
    const statCardChanges = document.getElementById('statCardChanges');
    const statCardErrors = document.getElementById('statCardErrors');
    const unreadBadgeChanges = document.getElementById('unreadBadgeChanges');
    const unreadBadgeErrors = document.getElementById('unreadBadgeErrors');
    const statValChanges = document.getElementById('statValChanges');
    const statValErrors = document.getElementById('statValErrors');

    if (statCardTargets) {
        statCardTargets.addEventListener('click', () => {
            activateTab('tab-monitors');
            activateSubtab('subtab-active');
        });
    }

    if (statCardChecks) {
        statCardChecks.addEventListener('click', () => {
            activateTab('tab-analytics');
        });
    }

    if (statCardChanges) {
        statCardChanges.addEventListener('click', () => {
            markChangesSeen();
            if (typeof openChangesExplorer === 'function') openChangesExplorer();
        });
    }
    if (statCardErrors) {
        statCardErrors.addEventListener('click', () => {
            markErrorsSeen();
            const logLevelSelect = document.getElementById('logLevelSelect');
            if (logLevelSelect) {
                logLevelSelect.value = 'ERROR';
            }
            activateTab('tab-logs');
            if (typeof loadLogs === 'function') {
                loadLogs();
            }
        });
    }

    function checkUnreadStats() {
        const currentChanges = parseInt((statValChanges?.textContent || '0').replace(/,/g, ''), 10) || 0;
        const currentErrors = parseInt((statValErrors?.textContent || '0').replace(/,/g, ''), 10) || 0;

        const lastSeenChanges = parseInt(localStorage.getItem('cm_last_seen_changes') || '-1', 10);
        const lastSeenErrors = parseInt(localStorage.getItem('cm_last_seen_errors') || '-1', 10);

        // If first visit, initialize last_seen to current
        if (lastSeenChanges === -1) {
            localStorage.setItem('cm_last_seen_changes', currentChanges.toString());
        } else if (currentChanges > lastSeenChanges && statCardChanges) {
            statCardChanges.classList.add('stat-card-unread');
            if (unreadBadgeChanges) unreadBadgeChanges.style.display = 'inline-block';
        }

        if (lastSeenErrors === -1) {
            localStorage.setItem('cm_last_seen_errors', currentErrors.toString());
        } else if (currentErrors > lastSeenErrors && statCardErrors) {
            statCardErrors.classList.add('stat-card-unread');
            if (unreadBadgeErrors) unreadBadgeErrors.style.display = 'inline-block';
        }
    }

    function markChangesSeen() {
        const currentChanges = parseInt((statValChanges?.textContent || '0').replace(/,/g, ''), 10) || 0;
        localStorage.setItem('cm_last_seen_changes', currentChanges.toString());
        if (statCardChanges) statCardChanges.classList.remove('stat-card-unread');
        if (unreadBadgeChanges) unreadBadgeChanges.style.display = 'none';
    }

    function markErrorsSeen() {
        const currentErrors = parseInt((statValErrors?.textContent || '0').replace(/,/g, ''), 10) || 0;
        localStorage.setItem('cm_last_seen_errors', currentErrors.toString());
        if (statCardErrors) statCardErrors.classList.remove('stat-card-unread');
        if (unreadBadgeErrors) unreadBadgeErrors.style.display = 'none';
    }

    checkUnreadStats();

    // --- Tab Navigation with State Persistence ---
    function activateTab(targetId) {
        if (!targetId) return;
        const targetPane = document.getElementById(targetId);
        if (targetPane) {
            document.querySelectorAll('.tab-btn').forEach(b => {
                if (b.getAttribute('data-tab') === targetId) b.classList.add('active');
                else b.classList.remove('active');
            });
            document.querySelectorAll('.drawer-item[data-tab]').forEach(d => {
                if (d.getAttribute('data-tab') === targetId) d.classList.add('active');
                else d.classList.remove('active');
            });
            document.querySelectorAll('.tab-pane').forEach(p => p.style.display = 'none');
            targetPane.style.display = 'block';

            // Mark error stat seen if logs tab opened
            if (targetId === 'tab-logs') {
                markErrorsSeen();
            }

            try {
                sessionStorage.setItem('active_tab', targetId);
                history.replaceState(null, '', '#' + targetId.replace('tab-', ''));
            } catch (e) {}
        }
    }

    // Global event delegation for desktop tab buttons and mobile drawer menu items
    document.addEventListener('click', (e) => {
        const tabBtn = e.target.closest('.tab-btn[data-tab]');
        if (tabBtn) {
            e.preventDefault();
            const targetId = tabBtn.getAttribute('data-tab');
            activateTab(targetId);
            return;
        }

        const drawerItem = e.target.closest('.drawer-item[data-tab]');
        if (drawerItem) {
            e.preventDefault();
            const targetId = drawerItem.getAttribute('data-tab');
            activateTab(targetId);
            closeMobileDrawer();
            return;
        }
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

    // Restore active tab & subtab on initial load, with support for URL query params
    const urlParams = new URLSearchParams(window.location.search);
    const urlRoute = urlParams.get('route');
    const urlChangeId = urlParams.get('change_id') || urlParams.get('event_id');
    const urlMonitorId = urlParams.get('monitor_id');

    const hash = window.location.hash.replace('#', '');
    let initialTab = null;

    if (urlChangeId || urlRoute === 'changes') {
        initialTab = 'tab-changes';
    } else if (hash) {
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

    // Auto-open Change Comparison modal if change_id parameter is present in URL
    if (urlChangeId) {
        setTimeout(() => {
            if (typeof window.openChangeDetail === 'function') {
                window.openChangeDetail(urlChangeId, urlMonitorId || null);
            }
        }, 300);
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

    // --- Helper: Parse cURL Command ---
    function parseCurlCommand(rawCurl) {
        if (!rawCurl || typeof rawCurl !== "string") return null;
        let str = rawCurl.trim();
        if (!str) return null;

        // Clean up multi-line backslash escapes
        str = str.replace(/\\\r?\n/g, " ");

        // Tokenize command line arguments respecting quotes
        const tokens = [];
        let curr = "";
        let inSingle = false;
        let inDouble = false;
        let isEscaped = false;

        for (let i = 0; i < str.length; i++) {
            const char = str[i];

            if (isEscaped) {
                curr += char;
                isEscaped = false;
                continue;
            }

            if (char === "\\" && !inSingle) {
                isEscaped = true;
                continue;
            }

            if (char === "$" && i + 1 < str.length && (str[i + 1] === "'" || str[i + 1] === '"') && !inSingle && !inDouble) {
                continue; // Skip the bash ANSI-C dollar prefix
            }

            if (char === "'" && !inDouble) {
                inSingle = !inSingle;
                continue;
            }

            if (char === '"' && !inSingle) {
                inDouble = !inDouble;
                continue;
            }

            if (/\s/.test(char) && !inSingle && !inDouble) {
                if (curr.length > 0) {
                    tokens.push(curr);
                    curr = "";
                }
            } else {
                curr += char;
            }
        }
        if (curr.length > 0) {
            tokens.push(curr);
        }

        if (tokens.length === 0) return null;

        let url = "";
        const headers = [];
        let cookies = "";
        let userAgent = "";
        let method = "GET";
        let data = "";

        for (let k = 0; k < tokens.length; k++) {
            const token = tokens[k];
            const next = tokens[k + 1] || "";

            if (token === "curl") {
                continue;
            } else if (token === "--url") {
                if (next) url = next;
                k++;
            } else if (token === "-X" || token === "--request") {
                method = next.toUpperCase();
                k++;
            } else if (token === "-H" || token === "--header") {
                if (next) {
                    const colonIdx = next.indexOf(":");
                    if (colonIdx > 0) {
                        const hKey = next.substring(0, colonIdx).trim();
                        const hVal = next.substring(colonIdx + 1).trim();
                        const hKeyLower = hKey.toLowerCase();
                        if (hKeyLower === "cookie") {
                            cookies = cookies ? (cookies + "; " + hVal) : hVal;
                        } else if (hKeyLower === "user-agent") {
                            userAgent = hVal;
                        } else {
                            headers.push(hKey + ": " + hVal);
                        }
                    } else {
                        headers.push(next.trim());
                    }
                }
                k++;
            } else if (token.startsWith("-H") && token.length > 2) {
                const headerVal = token.substring(2);
                const colonIdx2 = headerVal.indexOf(":");
                if (colonIdx2 > 0) {
                    const hk = headerVal.substring(0, colonIdx2).trim();
                    const hv = headerVal.substring(colonIdx2 + 1).trim();
                    if (hk.toLowerCase() === "cookie") {
                        cookies = cookies ? (cookies + "; " + hv) : hv;
                    } else if (hk.toLowerCase() === "user-agent") {
                        userAgent = hv;
                    } else {
                        headers.push(hk + ": " + hv);
                    }
                } else {
                    headers.push(headerVal.trim());
                }
            } else if (token === "-b" || token === "--cookie") {
                if (next) {
                    cookies = cookies ? (cookies + "; " + next) : next;
                }
                k++;
            } else if (token === "-A" || token === "--user-agent") {
                if (next) userAgent = next;
                k++;
            } else if (token === "-d" || token === "--data" || token === "--data-raw" || token === "--data-binary") {
                if (next) data = next;
                k++;
            } else if (token.startsWith("http://") || token.startsWith("https://")) {
                if (!url) url = token;
            } else if (!token.startsWith("-") && !url && k > 0) {
                const prev = tokens[k - 1];
                if (prev !== "-X" && prev !== "--request" && prev !== "-H" && prev !== "--header" && prev !== "-b" && prev !== "--cookie" && prev !== "-A" && prev !== "--user-agent" && prev !== "-d" && prev !== "--data" && prev !== "--data-raw" && prev !== "--data-binary") {
                    if (token.includes(".") && !token.includes(" ") && (token.startsWith("http") || token.includes("/"))) {
                        url = token.startsWith("http") ? token : ("https://" + token);
                    }
                }
            }
        }

        // Clean up stray quotes around URL
        if (url) {
            url = url.replace(/^['"]|['"]$/g, "");
        }

        // Determine suggested browser profile
        let suggestedTemplate = "";
        if (userAgent) {
            const ua = userAgent.toLowerCase();
            if (ua.includes("iphone") || ua.includes("mobile") || ua.includes("android")) {
                suggestedTemplate = "mobile_safari";
            } else if (ua.includes("firefox")) {
                suggestedTemplate = "firefox_win";
            } else if (ua.includes("macintosh") || ua.includes("mac os")) {
                suggestedTemplate = "chrome_mac";
            } else if (ua.includes("windows")) {
                suggestedTemplate = "chrome_win";
            }
        }

        // Generate friendly suggested name from domain/path
        let suggestedName = "";
        if (url) {
            try {
                const parsedUrl = new URL(url);
                const hostParts = parsedUrl.hostname.replace(/^www\./, "").split(".");
                const baseHost = hostParts.length >= 2 ? hostParts[hostParts.length - 2] : (hostParts[0] || parsedUrl.hostname);
                const domainName = baseHost ? (baseHost.charAt(0).toUpperCase() + baseHost.slice(1)) : parsedUrl.hostname;
                const pathEnd = parsedUrl.pathname && parsedUrl.pathname !== "/" ? (" - " + parsedUrl.pathname.split("/").filter(Boolean).pop()) : "";
                suggestedName = domainName + pathEnd;
            } catch (e) {
                suggestedName = url;
            }
        }

        return {
            url: url,
            name: suggestedName,
            headers: headers.join("\n"),
            cookies: cookies,
            userAgent: userAgent,
            template: suggestedTemplate,
            method: method
        };
    }

    // --- cURL Paste Autofill Handlers ---
    const curlPasteInput = document.getElementById("monitorCurlPaste");
    const applyCurlBtn = document.getElementById("applyCurlBtn");
    const clearCurlBtn = document.getElementById("clearCurlBtn");

    function applyParsedCurl(parsed) {
        if (!parsed) return;
        if (parsed.url) {
            const urlEl = document.getElementById("monitorUrl");
            if (urlEl) urlEl.value = parsed.url;
        }
        if (parsed.name) {
            const nameEl = document.getElementById("monitorName");
            if (nameEl && (!nameEl.value || nameEl.value.trim() === "")) {
                nameEl.value = parsed.name;
            }
        }
        if (parsed.headers) {
            const headersEl = document.getElementById("monitorHeaders");
            if (headersEl) {
                headersEl.value = parsed.headers;
            }
        }
        if (parsed.cookies) {
            const cookiesEl = document.getElementById("monitorCookies");
            if (cookiesEl) {
                cookiesEl.value = parsed.cookies;
            }
        }
        if (parsed.template) {
            const templateEl = document.getElementById("monitorTemplate");
            if (templateEl) {
                const optionExists = Array.from(templateEl.options).some(opt => opt.value === parsed.template);
                if (optionExists) templateEl.value = parsed.template;
            }
        }
    }

    if (applyCurlBtn) {
        applyCurlBtn.addEventListener("click", () => {
            const raw = curlPasteInput ? curlPasteInput.value.trim() : "";
            if (!raw) {
                showToast("Please paste a cURL command first", "error");
                return;
            }
            const parsed = parseCurlCommand(raw);
            if (!parsed || !parsed.url) {
                showToast("Could not extract a valid URL from the cURL command", "error");
                return;
            }
            applyParsedCurl(parsed);
            showToast("Form autofilled from cURL successfully!", "success");
        });
    }

    if (clearCurlBtn) {
        clearCurlBtn.addEventListener("click", () => {
            if (curlPasteInput) curlPasteInput.value = "";
        });
    }

    if (curlPasteInput) {
        curlPasteInput.addEventListener("paste", () => {
            setTimeout(() => {
                const text = curlPasteInput.value.trim();
                if (text && (text.startsWith("curl") || text.includes("http://") || text.includes("https://"))) {
                    const parsed = parseCurlCommand(text);
                    if (parsed && parsed.url) {
                        applyParsedCurl(parsed);
                        showToast("Detected cURL paste & autofilled form!", "success");
                    }
                }
            }, 50);
        });
    }

    // --- Add/Edit Monitor Modal ---
    const monitorForm = document.getElementById('monitorForm');
    const addMonitorBtn = document.getElementById('addMonitorBtn');

    if (addMonitorBtn) {
        addMonitorBtn.addEventListener('click', () => {
            if (monitorForm) monitorForm.reset();
            if (curlPasteInput) curlPasteInput.value = '';
            document.getElementById('monitorModalTitle').textContent = 'Add New Target Monitor';
            document.getElementById('monitorId').value = '';
            document.getElementById('previewOutput').style.display = 'none';
            openModal('monitorModal');
        });
    }

    window.editMonitor = function(data) {
        if (!data || !monitorForm) return;
        monitorForm.reset();
        if (curlPasteInput) curlPasteInput.value = '';
        
        const setVal = (id, val) => {
            const el = document.getElementById(id);
            if (el) el.value = val ?? '';
        };
        const setChecked = (id, bool) => {
            const el = document.getElementById(id);
            if (el) el.checked = !!bool;
        };

        const modalTitle = document.getElementById('monitorModalTitle');
        if (modalTitle) modalTitle.textContent = 'Edit Monitor';

        setVal('monitorId', data.id);
        setVal('monitorName', data.name);
        setVal('monitorUrl', data.url);
        setVal('monitorGroup', data.group || 'General');
        setVal('monitorType', data.type || 'html_full');
        setVal('monitorSelector', data.selector);
        setVal('monitorTemplate', data.browser_template || 'chrome_mac');
        setVal('monitorInterval', data.interval_mins || 15);
        setVal('monitorHeaders', data.custom_headers);
        setVal('monitorCookies', data.cookies);
        setChecked('monitorStripTags', data.strip_tags);
        setChecked('monitorNotifyChange', data.notify_on_change !== false);
        setChecked('monitorNotifyError', data.notify_on_error !== false);

        const peakEnabled = !!data.peak_schedule_enabled;
        const peakCheckbox = document.getElementById('monitorPeakScheduleEnabled');
        const peakFields = document.getElementById('peakScheduleFields');
        if (peakCheckbox) peakCheckbox.checked = peakEnabled;
        if (peakFields) peakFields.style.display = peakEnabled ? 'block' : 'none';

        setVal('monitorPeakStart', data.peak_start_hour ?? 9);
        setVal('monitorPeakEnd', data.peak_end_hour ?? 18);
        setVal('monitorPeakInterval', data.peak_interval_mins ?? 5);
        setVal('monitorOffpeakInterval', data.offpeak_interval_mins ?? 60);

        const previewOutput = document.getElementById('previewOutput');
        if (previewOutput) previewOutput.style.display = 'none';

        openModal('monitorModal');
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
            const origText = submitBtn ? submitBtn.innerHTML : 'Save Target';
            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.innerHTML = 'Saving...';
            }

            const payload = {
                id: document.getElementById('monitorId')?.value || '',
                name: document.getElementById('monitorName')?.value || '',
                url: document.getElementById('monitorUrl')?.value || '',
                group: document.getElementById('monitorGroup')?.value || 'General',
                type: document.getElementById('monitorType')?.value || 'html_full',
                selector: document.getElementById('monitorSelector')?.value || '',
                browser_template: document.getElementById('monitorTemplate')?.value || 'chrome_mac',
                interval_mins: parseInt(document.getElementById('monitorInterval')?.value || '15', 10),
                peak_schedule_enabled: document.getElementById('monitorPeakScheduleEnabled')?.checked || false,
                peak_start_hour: parseInt(document.getElementById('monitorPeakStart')?.value || '9', 10),
                peak_end_hour: parseInt(document.getElementById('monitorPeakEnd')?.value || '18', 10),
                peak_interval_mins: parseInt(document.getElementById('monitorPeakInterval')?.value || '5', 10),
                offpeak_interval_mins: parseInt(document.getElementById('monitorOffpeakInterval')?.value || '60', 10),
                custom_headers: document.getElementById('monitorHeaders')?.value || '',
                cookies: document.getElementById('monitorCookies')?.value || '',
                strip_tags: document.getElementById('monitorStripTags')?.checked || false,
                notify_on_change: document.getElementById('monitorNotifyChange')?.checked !== false,
                notify_on_error: document.getElementById('monitorNotifyError')?.checked !== false,
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
                if (submitBtn) {
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = origText;
                }
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
    let currentHistorySnapshots = [];
    let currentHistoryChanges = [];

    function renderRawContentWithLineNumbers(container, rawText) {
        if (!container) return;
        if (!rawText || rawText.trim() === '') {
            container.innerHTML = '<div style="color: var(--text-muted); text-align: center; padding: 2rem;">No snapshot content captured yet.</div>';
            return;
        }

        const lines = rawText.split('\n');
        let html = '<table class="raw-code-table"><tbody>';
        for (let i = 0; i < lines.length; i++) {
            const lineNum = i + 1;
            const lineText = lines[i];
            html += `<tr id="raw-line-${lineNum}" class="raw-code-row" data-line="${lineNum}">` +
                `<td class="raw-line-number" data-line="${lineNum}">${lineNum}</td>` +
                `<td class="raw-line-content">${escapeHtml(lineText) || '&nbsp;'}</td>` +
                `</tr>`;
        }
        html += '</tbody></table>';
        container.innerHTML = html;
    }

    let currentRawLoadedFile = null;

    async function loadRawSnapshotFile(file) {
        if (!currentHistoryMonitorId) return;
        const historySnapshot = document.getElementById('historySnapshot');
        const snapshotSelect = document.getElementById('snapshotSelect');

        const fetchFile = file || '';

        // Match and sync dropdown option
        if (snapshotSelect) {
            let matched = false;
            for (let i = 0; i < snapshotSelect.options.length; i++) {
                if (snapshotSelect.options[i].value === fetchFile) {
                    snapshotSelect.selectedIndex = i;
                    matched = true;
                    break;
                }
            }
            if (!matched) {
                if (!fetchFile || (currentHistorySnapshots[0] && fetchFile === currentHistorySnapshots[0].file)) {
                    snapshotSelect.selectedIndex = 0;
                }
            }
        }

        // Avoid re-fetching if already loaded in container
        if (currentRawLoadedFile === fetchFile && historySnapshot && historySnapshot.querySelector('.raw-code-table')) {
            return;
        }

        if (historySnapshot) {
            historySnapshot.innerHTML = '<div style="color: var(--text-muted); text-align: center; padding: 2rem;">Loading snapshot version...</div>';
        }

        try {
            const res = await fetch(`api.php?action=get_history&id=${encodeURIComponent(currentHistoryMonitorId)}&snapshot_file=${encodeURIComponent(fetchFile)}`);
            const data = await res.json();
            if (data.success && historySnapshot) {
                renderRawContentWithLineNumbers(historySnapshot, data.current_snapshot || data.latest_snapshot || '');
                currentRawLoadedFile = fetchFile;
            } else if (historySnapshot) {
                historySnapshot.innerHTML = `<div style="color: var(--danger); padding: 1.5rem;">Failed to load snapshot version: ${escapeHtml(data.error || 'Unknown error')}</div>`;
            }
        } catch (err) {
            if (historySnapshot) {
                historySnapshot.innerHTML = `<div style="color: var(--danger); padding: 1.5rem;">Network error loading snapshot version: ${escapeHtml(err.message)}</div>`;
            }
        }
    }

    function jumpAndHighlightRawLine(lineNo) {
        if (!lineNo || isNaN(lineNo)) return;
        requestAnimationFrame(() => {
            const targetRow = document.getElementById(`raw-line-${lineNo}`);
            const rawContainer = document.getElementById('historySnapshot');
            if (!targetRow || !rawContainer) return;

            // Scroll target line into view centered inside container
            targetRow.scrollIntoView({ behavior: 'smooth', block: 'center' });

            // Trigger flash highlight animation
            targetRow.classList.remove('raw-line-flash');
            void targetRow.offsetWidth; // Force DOM reflow
            targetRow.classList.add('raw-line-flash');

            showToast(`Navigated to Line ${lineNo} in Raw Content`, 'info');
        });
    }

    window.viewHistory = async function(id) {
        currentHistoryMonitorId = id;
        currentRawLoadedFile = '';
        openModal('historyModal');
        const historySnapshot = document.getElementById('historySnapshot');
        const historyDiffTable = document.getElementById('historyDiffTable');
        const historyTitle = document.getElementById('historyModalTitle');
        const snapshotSelect = document.getElementById('snapshotSelect');
        const diffVersionOld = document.getElementById('diffVersionOld');
        const diffVersionNew = document.getElementById('diffVersionNew');
        const historyLogMeta = document.getElementById('historyLogMeta');
        const historyChangesCount = document.getElementById('historyChangesCount');
        const downloadHistoryBtn = document.getElementById('downloadHistoryBtn');

        if (downloadHistoryBtn) {
            downloadHistoryBtn.href = `api.php?action=download_history_log&id=${encodeURIComponent(id)}`;
        }
        if (historySnapshot) historySnapshot.innerHTML = '<div style="color: var(--text-muted); text-align: center; padding: 2rem;">Loading snapshot...</div>';
        if (historyDiffTable) historyDiffTable.innerHTML = '<div style="color: var(--text-muted); text-align: center; padding: 2rem;">Loading diff comparison...</div>';
        if (snapshotSelect) snapshotSelect.innerHTML = '<option value="">Latest Captured Snapshot</option>';
        if (diffVersionOld) diffVersionOld.innerHTML = '<option value="">Previous Snapshot</option>';
        if (diffVersionNew) diffVersionNew.innerHTML = '<option value="">Latest Captured</option>';

        // Set default to Diff View mode
        setHistoryViewMode('diff');

        try {
            const res = await fetch(`api.php?action=get_history&id=${encodeURIComponent(id)}`);
            const data = await res.json();
            if (data.success) {
                historyTitle.textContent = `History & Diffs: ${data.monitor?.name || id}`;
                if (historySnapshot) {
                    renderRawContentWithLineNumbers(historySnapshot, data.current_snapshot || data.latest_snapshot || '');
                    currentRawLoadedFile = '';
                }
                
                if (historyLogMeta) {
                    historyLogMeta.textContent = `Total Checks: ${data.monitor?.check_count || 0} | Changes: ${data.monitor?.change_count || 0} | Group: ${data.monitor?.group || 'Ungrouped'}`;
                }

                currentHistorySnapshots = data.snapshots_list || [];
                currentHistoryChanges = data.changes || [];
                if (historyChangesCount) {
                    historyChangesCount.textContent = currentHistoryChanges.length;
                }

                // Populate Dropdowns
                if (currentHistorySnapshots.length > 0) {
                    let rawOpts = '<option value="">Latest Snapshot (Active)</option>';
                    let oldOpts = '<option value="">(Auto: Previous to New)</option>';
                    let newOpts = '<option value="">Latest Snapshot (Active)</option>';

                    currentHistorySnapshots.forEach((snap, idx) => {
                        const opt = `<option value="${escapeHtml(snap.file)}">${escapeHtml(snap.time)} (${snap.size}B)${idx === 0 ? ' [Latest]' : ''}</option>`;
                        rawOpts += opt;
                        newOpts += opt;
                        if (idx > 0) {
                            oldOpts += `<option value="${escapeHtml(snap.file)}">${escapeHtml(snap.time)} (${snap.size}B)${idx === 1 ? ' [Previous]' : ''}</option>`;
                        }
                    });

                    if (snapshotSelect) snapshotSelect.innerHTML = rawOpts;
                    if (diffVersionOld) diffVersionOld.innerHTML = oldOpts;
                    if (diffVersionNew) diffVersionNew.innerHTML = newOpts;
                }

                // Render changes list table inside modal
                renderHistoryChangesTable(currentHistoryChanges);

                // Auto-load latest diff
                loadHistoryDiff();
            } else {
                if (historyDiffTable) historyDiffTable.innerHTML = `<div style="color: var(--danger); padding: 1rem;">Failed to load history: ${escapeHtml(data.error)}</div>`;
            }
        } catch (e) {
            if (historyDiffTable) historyDiffTable.innerHTML = `<div style="color: var(--danger); padding: 1rem;">Network error: ${escapeHtml(e.message)}</div>`;
        }
    };

    function setHistoryViewMode(mode) {
        const diffBtn = document.getElementById('btnHistoryModeDiff');
        const rawBtn = document.getElementById('btnHistoryModeRaw');
        const changesBtn = document.getElementById('btnHistoryModeChanges');
        const diffControls = document.getElementById('historyDiffControls');
        const rawControls = document.getElementById('historyRawControls');
        const diffView = document.getElementById('historyDiffViewContainer');
        const rawView = document.getElementById('historySnapshot');
        const changesView = document.getElementById('historyChangesContainer');

        if (diffBtn) diffBtn.className = mode === 'diff' ? 'btn btn-primary btn-sm' : 'btn btn-secondary btn-sm';
        if (rawBtn) rawBtn.className = mode === 'raw' ? 'btn btn-primary btn-sm' : 'btn btn-secondary btn-sm';
        if (changesBtn) changesBtn.className = mode === 'changes' ? 'btn btn-primary btn-sm' : 'btn btn-secondary btn-sm';

        if (diffControls) diffControls.style.display = mode === 'diff' ? 'flex' : 'none';
        if (rawControls) rawControls.style.display = mode === 'raw' ? 'flex' : 'none';

        if (diffView) diffView.style.display = mode === 'diff' ? 'block' : 'none';
        if (rawView) rawView.style.display = mode === 'raw' ? 'block' : 'none';
        if (changesView) changesView.style.display = mode === 'changes' ? 'block' : 'none';
    }

    document.getElementById('btnHistoryModeDiff')?.addEventListener('click', () => setHistoryViewMode('diff'));
    document.getElementById('btnHistoryModeRaw')?.addEventListener('click', () => setHistoryViewMode('raw'));
    document.getElementById('btnHistoryModeChanges')?.addEventListener('click', () => setHistoryViewMode('changes'));

    async function loadHistoryDiff() {
        if (!currentHistoryMonitorId) return;
        const historyDiffTable = document.getElementById('historyDiffTable');
        const fileA = document.getElementById('diffVersionOld')?.value || (currentHistorySnapshots[1]?.file || '');
        const fileB = document.getElementById('diffVersionNew')?.value || (currentHistorySnapshots[0]?.file || '');

        if (!currentHistorySnapshots || currentHistorySnapshots.length === 0) {
            historyDiffTable.innerHTML = '<div style="color: var(--text-muted); text-align: center; padding: 2rem;">No snapshots captured yet for this target.</div>';
            return;
        }

        historyDiffTable.innerHTML = '<div style="color: var(--text-muted); text-align: center; padding: 2rem;">Computing line-by-line red/green diff...</div>';

        try {
            const params = new URLSearchParams({
                action: 'get_history_diff',
                id: currentHistoryMonitorId,
                file_a: fileA,
                file_b: fileB
            });
            const res = await fetch(`api.php?${params.toString()}`);
            const data = await res.json();
            if (data.success && data.html_table) {
                historyDiffTable.innerHTML = data.html_table;
            } else {
                historyDiffTable.innerHTML = `<div style="color: var(--danger); padding: 1.5rem;">${escapeHtml(data.error || 'Failed to compute diff')}</div>`;
            }
        } catch (e) {
            historyDiffTable.innerHTML = `<div style="color: var(--danger); padding: 1.5rem;">Network error computing diff: ${escapeHtml(e.message)}</div>`;
        }
    }

    document.getElementById('btnComputeHistoryDiff')?.addEventListener('click', loadHistoryDiff);
    document.getElementById('diffVersionOld')?.addEventListener('change', loadHistoryDiff);
    document.getElementById('diffVersionNew')?.addEventListener('change', loadHistoryDiff);

    // Global event listener for clicking a diff row to navigate to that line in raw content
    document.addEventListener('click', async (e) => {
        const diffRow = e.target.closest('.diff-table-row');
        if (!diffRow) return;
        const lineNo = parseInt(diffRow.getAttribute('data-line'), 10);
        const lineType = diffRow.getAttribute('data-type') || 'added';
        if (!lineNo || isNaN(lineNo)) return;

        const historyModal = document.getElementById('historyModal');
        if (historyModal && historyModal.classList.contains('open')) {
            const diffOld = document.getElementById('diffVersionOld')?.value;
            const diffNew = document.getElementById('diffVersionNew')?.value;

            // Resolve actual snapshot files for old (previous) vs new (latest) versions
            const selectedOld = diffOld || (currentHistorySnapshots[1]?.file || '');
            const selectedNew = diffNew || (currentHistorySnapshots[0]?.file || '');

            // Previous / removed line -> previous version snapshot
            // Added / new / unchanged line -> vs new version snapshot
            const targetFile = (lineType === 'removed') ? selectedOld : selectedNew;

            // Switch to raw content mode
            setHistoryViewMode('raw');

            // Load and select the target snapshot version
            await loadRawSnapshotFile(targetFile);

            // Navigate and highlight the target line
            jumpAndHighlightRawLine(lineNo);
        }
    });

    function renderHistoryChangesTable(changes) {
        const tbody = document.getElementById('historyChangesTableBody');
        if (!tbody) return;
        if (!changes || changes.length === 0) {
            tbody.innerHTML = '<tr><td colspan="4" style="text-align: center; color: var(--text-muted); padding: 2rem;">No detected changes recorded yet.</td></tr>';
            return;
        }

        let html = '';
        changes.forEach(chg => {
            const timeStr = chg.timestamp ? new Date(chg.timestamp).toLocaleString() : '-';
            const badge = `<span style="background: #dcfce7; color: #15803d; padding: 2px 6px; border-radius: 4px; font-size: 11px; font-weight: 600;">+${chg.added_count || 0}</span> ` +
                          `<span style="background: #fee2e2; color: #b91c1c; padding: 2px 6px; border-radius: 4px; font-size: 11px; font-weight: 600;">-${chg.removed_count || 0}</span>`;
            const sizeStr = `${chg.old_size || 0}B &rarr; ${chg.new_size || 0}B`;
            const archivedBadge = chg.archived ? '<span class="badge badge-paused" style="margin-left: 4px;">Archived</span>' : '';

            html += `
                <tr>
                    <td style="font-size: 0.8rem; color: var(--text-primary); white-space: nowrap;">${escapeHtml(timeStr)}${archivedBadge}</td>
                    <td>${badge}</td>
                    <td style="font-size: 0.775rem; color: var(--text-muted);">${sizeStr}</td>
                    <td style="text-align: right; white-space: nowrap;">
                        <button class="btn btn-primary btn-sm" onclick="openChangeDetail('${escapeHtml(chg.id)}', '${escapeHtml(chg.monitor_id)}')">🔍 Inspect Diff</button>
                    </td>
                </tr>
            `;
        });
        tbody.innerHTML = html;
    }

    // Snapshot Dropdown Change Handler (Raw Mode)
    const snapshotSelect = document.getElementById('snapshotSelect');
    if (snapshotSelect) {
        snapshotSelect.addEventListener('change', async (e) => {
            await loadRawSnapshotFile(e.target.value);
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
                diff_big_change_threshold_lines: parseInt(document.getElementById('settingBigChangeThreshold')?.value || '30', 10),
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
    const logDateInput = document.getElementById('logDateInput');
    const logLevelSelect = document.getElementById('logLevelSelect');
    const logCategorySelect = document.getElementById('logCategorySelect');
    const refreshLogsBtn = document.getElementById('refreshLogsBtn');
    const clearLogsBtn = document.getElementById('clearLogsBtn');
    const deleteSelectedLogsBtn = document.getElementById('deleteSelectedLogsBtn');
    const selectedLogsCount = document.getElementById('selectedLogsCount');
    const selectAllLogsCheckbox = document.getElementById('selectAllLogsCheckbox');

    async function refreshDashboardStats() {
        try {
            const res = await fetch('api.php?action=get_stats');
            const data = await res.json();
            if (data.success && data.stats) {
                const statValChanges = document.getElementById('statValChanges');
                const statValErrors = document.getElementById('statValErrors');
                if (statValChanges) statValChanges.textContent = Number(data.stats.total_changes || 0).toLocaleString();
                if (statValErrors) statValErrors.textContent = Number(data.stats.total_errors || 0).toLocaleString();
            }
        } catch (e) {
            console.error('Failed to refresh dashboard stats', e);
        }
    }

    function updateLogsSelectionUI() {
        const checked = document.querySelectorAll('.log-checkbox:checked');
        const count = checked.length;
        if (selectedLogsCount) selectedLogsCount.textContent = count;
        if (deleteSelectedLogsBtn) {
            deleteSelectedLogsBtn.style.display = count > 0 ? 'inline-flex' : 'none';
        }
    }

    if (selectAllLogsCheckbox) {
        selectAllLogsCheckbox.addEventListener('change', () => {
            const boxes = document.querySelectorAll('.log-checkbox');
            boxes.forEach(b => b.checked = selectAllLogsCheckbox.checked);
            updateLogsSelectionUI();
        });
    }

    async function loadLogs() {
        if (!logsTableBody) return;
        const search = logSearchInput ? logSearchInput.value.trim() : '';
        const level = logLevelSelect ? logLevelSelect.value : '';
        const category = logCategorySelect ? logCategorySelect.value : '';
        const date = logDateInput ? logDateInput.value.trim() : '';

        const params = new URLSearchParams({
            action: 'get_logs',
            search: search,
            level: level,
            category: category,
            date: date,
            limit: 200
        });

        try {
            const res = await fetch(`api.php?${params.toString()}`);
            const data = await res.json();
            if (!data.success) {
                logsTableBody.innerHTML = `<tr><td colspan="7" style="text-align: center; color: var(--danger); padding: 1.5rem;">Error: ${escapeHtml(data.error)}</td></tr>`;
                return;
            }

            if (!data.logs || data.logs.length === 0) {
                logsTableBody.innerHTML = `<tr><td colspan="7" style="text-align: center; color: var(--text-muted); padding: 2rem;">No logs found matching criteria${date ? ` for date ${escapeHtml(date)}` : ''}.</td></tr>`;
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
                        <td class="td-checkbox">
                            <input type="checkbox" class="custom-checkbox log-checkbox" value="${escapeHtml(row.id || '')}">
                        </td>
                        <td style="font-size: 0.8rem; color: var(--text-secondary); white-space: nowrap;">${escapeHtml(row.timestamp || '-')}</td>
                        <td>${levelBadge}</td>
                        <td><span class="badge badge-type">${escapeHtml(row.category || 'SYSTEM')}</span></td>
                        <td>
                            <div style="font-weight: 500;">${escapeHtml(row.message || '')}</div>
                            ${contextStr}
                        </td>
                        <td style="font-size: 0.775rem; color: var(--text-muted);">${escapeHtml(row.ip || '-')}</td>
                        <td style="text-align: right; white-space: nowrap;">
                            <button class="btn btn-danger btn-sm" onclick="deleteSingleLog('${escapeHtml(row.id || '')}')" style="padding: 0.2rem 0.5rem; font-size: 0.75rem;">🗑️</button>
                        </td>
                    </tr>
                `;
            });
            logsTableBody.innerHTML = html;

            document.querySelectorAll('.log-checkbox').forEach(cb => {
                cb.addEventListener('change', updateLogsSelectionUI);
            });
            if (selectAllLogsCheckbox) selectAllLogsCheckbox.checked = false;
            updateLogsSelectionUI();
        } catch (e) {
            logsTableBody.innerHTML = `<tr><td colspan="7" style="text-align: center; color: var(--danger); padding: 1.5rem;">Network error fetching logs</td></tr>`;
        }
    }

    window.deleteSingleLog = async function(id) {
        if (!id) return;
        try {
            const res = await fetch('api.php?action=delete_logs', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken },
                body: JSON.stringify({ log_ids: [id], csrf_token: csrfToken })
            });
            const data = await res.json();
            if (data.success) {
                showToast('Log entry deleted', 'success');
                loadLogs();
                refreshDashboardStats();
            } else {
                showToast(data.error || 'Failed to delete log', 'error');
            }
        } catch (e) {
            showToast('Network error deleting log', 'error');
        }
    };

    if (deleteSelectedLogsBtn) {
        deleteSelectedLogsBtn.addEventListener('click', async () => {
            const checked = Array.from(document.querySelectorAll('.log-checkbox:checked')).map(cb => cb.value);
            if (checked.length === 0) return;
            if (!confirm(`Delete ${checked.length} selected log entries?`)) return;

            try {
                const res = await fetch('api.php?action=delete_logs', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken },
                    body: JSON.stringify({ log_ids: checked, csrf_token: csrfToken })
                });
                const data = await res.json();
                if (data.success) {
                    showToast(`Deleted ${data.count} log entries`, 'success');
                    loadLogs();
                    refreshDashboardStats();
                } else {
                    showToast(data.error || 'Failed to delete logs', 'error');
                }
            } catch (e) {
                showToast('Network error deleting logs', 'error');
            }
        });
    }

    if (refreshLogsBtn) {
        refreshLogsBtn.addEventListener('click', loadLogs);
    }

    if (logDateInput) {
        logDateInput.addEventListener('change', loadLogs);
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

    window.openLogsForDate = function(dateStr) {
        if (logDateInput) {
            logDateInput.value = dateStr;
        }
        const logsTabBtn = document.querySelector('.tab-btn[data-tab="tab-logs"]');
        if (logsTabBtn) logsTabBtn.click();
        loadLogs();
    };

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
                    refreshDashboardStats();
                } else {
                    showToast(data.error || 'Failed to clear logs', 'error');
                }
            } catch (err) {
                showToast('Network error clearing logs', 'error');
            }
        });
    }

    // ==========================================
    // --- CHANGES DETECTED EXPLORER CONTROLLER ---
    // ==========================================
    const changesSearchInput = document.getElementById('changesSearchInput');
    const changesDateInput = document.getElementById('changesDateInput');
    const changesShowArchived = document.getElementById('changesShowArchived');
    const changesRefreshBtn = document.getElementById('changesRefreshBtn');
    const changesClearAllBtn = document.getElementById('changesClearAllBtn');
    const changesArchiveSelectedBtn = document.getElementById('changesArchiveSelectedBtn');
    const changesDeleteSelectedBtn = document.getElementById('changesDeleteSelectedBtn');
    const changesSelectedCount = document.getElementById('changesSelectedCount');
    const selectAllChangesCheckbox = document.getElementById('selectAllChangesCheckbox');
    const allChangesTableBody = document.getElementById('allChangesTableBody');

    let allLoadedChanges = [];
    let currentDetailEvent = null;

    // Click on stat card Changes Detected -> open explorer (handled via event listener near line 269)
    // Click on stat card Errors Encountered -> switch to Logs Tab (handled via event listener near line 272)

    window.openChangesExplorer = function(targetMonitorId = null) {
        openModal('changesExplorerModal');
        loadAllChanges(targetMonitorId);
    };

    window.openChangesForDate = function(dateStr) {
        if (changesDateInput) {
            changesDateInput.value = dateStr;
        }
        openChangesExplorer();
    };

    function updateChangesSelectionUI() {
        const checked = document.querySelectorAll('.change-row-checkbox:checked');
        const count = checked.length;
        if (changesSelectedCount) changesSelectedCount.textContent = count;
        if (changesArchiveSelectedBtn) changesArchiveSelectedBtn.style.display = count > 0 ? 'inline-flex' : 'none';
        if (changesDeleteSelectedBtn) changesDeleteSelectedBtn.style.display = count > 0 ? 'inline-flex' : 'none';
    }

    if (selectAllChangesCheckbox) {
        selectAllChangesCheckbox.addEventListener('change', () => {
            const boxes = document.querySelectorAll('.change-row-checkbox');
            boxes.forEach(b => b.checked = selectAllChangesCheckbox.checked);
            updateChangesSelectionUI();
        });
    }

    async function loadAllChanges(monitorId = null) {
        if (!allChangesTableBody) return;
        const search = changesSearchInput ? changesSearchInput.value.trim() : '';
        const date = changesDateInput ? changesDateInput.value.trim() : '';
        const includeArchived = changesShowArchived ? (changesShowArchived.checked ? '1' : '0') : '0';

        allChangesTableBody.innerHTML = '<tr><td colspan="7" style="text-align: center; color: var(--text-muted); padding: 2.5rem;">Loading detected changes...</td></tr>';

        const params = new URLSearchParams({
            action: 'get_changes_list',
            include_archived: includeArchived,
            search: search,
            date: date,
            limit: 200
        });
        if (monitorId) params.append('monitor_id', monitorId);

        try {
            const res = await fetch(`api.php?${params.toString()}`);
            const data = await res.json();
            if (!data.success) {
                allChangesTableBody.innerHTML = `<tr><td colspan="7" style="text-align: center; color: var(--danger); padding: 2rem;">Error: ${escapeHtml(data.error)}</td></tr>`;
                return;
            }

            allLoadedChanges = data.changes || [];
            if (allLoadedChanges.length === 0) {
                allChangesTableBody.innerHTML = `<tr><td colspan="7" style="text-align: center; color: var(--text-muted); padding: 2.5rem;">No detected changes recorded yet${date ? ` for date ${escapeHtml(date)}` : ''}.</td></tr>`;
                return;
            }

            let html = '';
            allLoadedChanges.forEach(chg => {
                const timeStr = chg.timestamp ? new Date(chg.timestamp).toLocaleString() : '-';
                const badge = `<span style="background: #dcfce7; color: #15803d; padding: 2px 7px; border-radius: 4px; font-size: 11px; font-weight: 600;">+${chg.added_count || 0}</span> ` +
                              `<span style="background: #fee2e2; color: #b91c1c; padding: 2px 7px; border-radius: 4px; font-size: 11px; font-weight: 600;">-${chg.removed_count || 0}</span>`;
                const sizeStr = `${chg.old_size || 0}B &rarr; ${chg.new_size || 0}B`;
                const isArchived = !empty(chg.archived);
                const archivedBadge = isArchived ? '<span class="badge badge-paused" style="margin-left: 6px;">Archived</span>' : '';

                html += `
                    <tr data-event-id="${escapeHtml(chg.id)}">
                        <td class="td-checkbox">
                            <input type="checkbox" class="custom-checkbox change-row-checkbox" value="${escapeHtml(chg.id)}">
                        </td>
                        <td style="font-size: 0.8rem; color: var(--text-primary); white-space: nowrap;">
                            ${escapeHtml(timeStr)}${archivedBadge}
                        </td>
                        <td>
                            <strong>${escapeHtml(chg.monitor_name || 'Target')}</strong>
                        </td>
                        <td>
                            <span class="badge badge-group">📁 ${escapeHtml(chg.group || 'Ungrouped')}</span>
                        </td>
                        <td>${badge}</td>
                        <td style="font-size: 0.775rem; color: var(--text-muted); font-family: var(--font-mono);">${sizeStr}</td>
                        <td style="text-align: right; white-space: nowrap;">
                            <button class="btn btn-primary btn-sm" onclick="openChangeDetail('${escapeHtml(chg.id)}', '${escapeHtml(chg.monitor_id)}')">🔍 Inspect Diff</button>
                            <button class="btn btn-secondary btn-sm" onclick="toggleArchiveChange('${escapeHtml(chg.id)}', ${isArchived ? 'false' : 'true'})" title="${isArchived ? 'Unarchive' : 'Archive'}">📦</button>
                            <button class="btn btn-danger btn-sm" onclick="deleteSingleChange('${escapeHtml(chg.id)}')" title="Delete Record">🗑️</button>
                        </td>
                    </tr>
                `;
            });
            allChangesTableBody.innerHTML = html;

            document.querySelectorAll('.change-row-checkbox').forEach(cb => {
                cb.addEventListener('change', updateChangesSelectionUI);
            });
            if (selectAllChangesCheckbox) selectAllChangesCheckbox.checked = false;
            updateChangesSelectionUI();
        } catch (e) {
            allChangesTableBody.innerHTML = `<tr><td colspan="7" style="text-align: center; color: var(--danger); padding: 2rem;">Network error fetching changes: ${escapeHtml(e.message)}</td></tr>`;
        }
    }

    function empty(val) {
        return !val || val === '0' || val === 0 || val === false;
    }

    if (changesRefreshBtn) changesRefreshBtn.addEventListener('click', () => loadAllChanges());
    if (changesDateInput) changesDateInput.addEventListener('change', () => loadAllChanges());
    if (changesShowArchived) changesShowArchived.addEventListener('change', () => loadAllChanges());
    if (changesSearchInput) {
        changesSearchInput.addEventListener('input', () => {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(() => loadAllChanges(), 300);
        });
    }

    window.toggleArchiveChange = async function(eventId, archive) {
        try {
            const res = await fetch('api.php?action=archive_changes', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken },
                body: JSON.stringify({ event_ids: [eventId], archive: archive, csrf_token: csrfToken })
            });
            const data = await res.json();
            if (data.success) {
                showToast(archive ? 'Change archived' : 'Change unarchived', 'success');
                loadAllChanges();
            } else {
                showToast(data.error || 'Failed to update archive state', 'error');
            }
        } catch (e) {
            showToast('Network error archiving change', 'error');
        }
    };

    window.deleteSingleChange = async function(eventId) {
        if (!confirm('Are you sure you want to permanently delete this change record?')) return;
        try {
            const res = await fetch('api.php?action=delete_changes', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken },
                body: JSON.stringify({ event_ids: [eventId], csrf_token: csrfToken })
            });
            const data = await res.json();
            if (data.success) {
                showToast('Change record deleted', 'success');
                loadAllChanges();
                refreshDashboardStats();
            } else {
                showToast(data.error || 'Failed to delete change', 'error');
            }
        } catch (e) {
            showToast('Network error deleting change', 'error');
        }
    };

    if (changesArchiveSelectedBtn) {
        changesArchiveSelectedBtn.addEventListener('click', async () => {
            const checked = Array.from(document.querySelectorAll('.change-row-checkbox:checked')).map(cb => cb.value);
            if (checked.length === 0) return;
            try {
                const res = await fetch('api.php?action=archive_changes', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken },
                    body: JSON.stringify({ event_ids: checked, archive: true, csrf_token: csrfToken })
                });
                const data = await res.json();
                if (data.success) {
                    showToast(`Archived ${data.count} change record(s)`, 'success');
                    loadAllChanges();
                } else {
                    showToast(data.error || 'Failed to archive changes', 'error');
                }
            } catch (e) {
                showToast('Network error archiving changes', 'error');
            }
        });
    }

    if (changesDeleteSelectedBtn) {
        changesDeleteSelectedBtn.addEventListener('click', async () => {
            const checked = Array.from(document.querySelectorAll('.change-row-checkbox:checked')).map(cb => cb.value);
            if (checked.length === 0) return;
            if (!confirm(`Permanently delete ${checked.length} change record(s)?`)) return;

            try {
                const res = await fetch('api.php?action=delete_changes', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken },
                    body: JSON.stringify({ event_ids: checked, csrf_token: csrfToken })
                });
                const data = await res.json();
                if (data.success) {
                    showToast(`Deleted ${data.count} change record(s)`, 'success');
                    loadAllChanges();
                    refreshDashboardStats();
                } else {
                    showToast(data.error || 'Failed to delete changes', 'error');
                }
            } catch (e) {
                showToast('Network error deleting changes', 'error');
            }
        });
    }

    if (changesClearAllBtn) {
        changesClearAllBtn.addEventListener('click', async () => {
            if (!confirm('Are you sure you want to delete ALL recorded change history across all monitors?')) return;
            try {
                const res = await fetch('api.php?action=clear_all_changes', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken },
                    body: JSON.stringify({ csrf_token: csrfToken })
                });
                const data = await res.json();
                if (data.success) {
                    showToast('All change records cleared', 'success');
                    loadAllChanges();
                    refreshDashboardStats();
                } else {
                    showToast(data.error || 'Failed to clear changes', 'error');
                }
            } catch (e) {
                showToast('Network error clearing changes', 'error');
            }
        });
    }

    // ==========================================
    // --- INDIVIDUAL CHANGE DETAIL MODAL CONTROLLER ---
    // ==========================================
    window.openChangeDetail = async function(eventId, monitorId) {
        openModal('changeDetailModal');
        const titleEl = document.getElementById('changeDetailTitle');
        const metaEl = document.getElementById('changeDetailMeta');
        const contentEl = document.getElementById('changeDetailDiffContent');
        const archiveBtn = document.getElementById('changeDetailArchiveBtn');
        const deleteBtn = document.getElementById('changeDetailDeleteBtn');

        if (contentEl) contentEl.innerHTML = '<div style="color: var(--text-muted); text-align: center; padding: 2rem;">Loading previous vs new diff preview...</div>';

        try {
            const params = new URLSearchParams({
                action: 'get_change_diff',
                event_id: eventId,
                monitor_id: monitorId
            });
            const res = await fetch(`api.php?${params.toString()}`);
            const data = await res.json();
            if (data.success) {
                currentDetailEvent = data.event;
                if (titleEl) titleEl.textContent = `Change Comparison: ${data.event.monitor_name || 'Target'}`;
                if (metaEl) {
                    const timeStr = data.event.timestamp ? new Date(data.event.timestamp).toLocaleString() : '-';
                    metaEl.innerHTML = `<strong>Detected:</strong> ${escapeHtml(timeStr)} | <strong>Target:</strong> ${escapeHtml(data.event.monitor_name)} (<code>${escapeHtml(data.event.group || 'Ungrouped')}</code>) | <strong>Summary:</strong> <span style="color: #22c55e;">+${data.diff_data?.added_count || 0} added</span> / <span style="color: #ef4444;">-${data.diff_data?.removed_count || 0} removed</span>`;
                }
                if (contentEl) {
                    contentEl.innerHTML = data.html_table || '<div style="color: var(--text-muted);">No diff content available.</div>';
                }

                if (archiveBtn) {
                    archiveBtn.textContent = data.event.archived ? '📦 Unarchive' : '📦 Archive';
                    archiveBtn.onclick = async () => {
                        await toggleArchiveChange(eventId, !data.event.archived);
                        closeModal('changeDetailModal');
                    };
                }

                if (deleteBtn) {
                    deleteBtn.onclick = async () => {
                        await deleteSingleChange(eventId);
                        closeModal('changeDetailModal');
                    };
                }
            } else {
                if (contentEl) contentEl.innerHTML = `<div style="color: var(--danger); padding: 1.5rem;">Error: ${escapeHtml(data.error)}</div>`;
            }
        } catch (e) {
            if (contentEl) contentEl.innerHTML = `<div style="color: var(--danger); padding: 1.5rem;">Network error loading diff preview: ${escapeHtml(e.message)}</div>`;
        }
    };

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




