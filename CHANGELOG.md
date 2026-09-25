# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).

## [2026-09-25]
### Added
- **Ignore Part of Monitor (Noise & Dynamic Timestamp Filter)**:
  - Added `ignore_selector` support across all monitor types in [`Extractor`](file:///Users/bharat/Projects/changemonitor/includes/extractor.php), [`Engine`](file:///Users/bharat/Projects/changemonitor/includes/engine.php), [`Storage`](file:///Users/bharat/Projects/changemonitor/includes/storage.php), and [`api.php`](file:///Users/bharat/Projects/changemonitor/public/api.php).
  - Strips dynamic uninteresting paths before hashing and comparison (e.g. JSON `$.timestamp`, `data.meta.*`, HTML `.footer`, `.ad-banner`, XPath `//div[@id='ads']`, or Regex patterns) to eliminate false positive change alerts.
- **Multiple Selector / Path / Pattern Support**:
  - Monitors now support composite multiple selectors (separated by commas or newlines) for JSON (`status, data.items[0].price`), CSS (`.price, .desc`), XPath, and Regex.
- **Interactive Visual Quick Selector UI**:
  - Added **"🎯 Visual Quick Picker"** modal and toolbar buttons in the Add/Edit Monitor modal and Live Preview panel.
  - Interactively displays the response structure as an expandable, searchable JSON key tree or HTML DOM node tree with live click actions: **"🎯 Select"** and **"🚫 Ignore"**.
  - Interactive tags bar with instant removal chips for included and ignored paths.
- **Bulk Add & Bulk Edit Integration**:
  - Added default ignore selector field to **Bulk Add Targets** modal.
  - Added selector & ignore path batch update checkbox and inputs to **Bulk Edit Targets** modal.

### Fixed
- **Visual Quick Selector Target Cache Invalidation**:
  - Invalidate cached response body and URL when switching between different monitors or clicking "+ Add Target", ensuring the visual tree inspector always fetches and renders the live response structure for the currently active monitor.

## [2026-09-24]
### Added
- **Deployment Documentation**:
  - Added short, structured deployment guide to [README.md](file:///Users/bharat/Projects/changemonitor/README.md) covering automated GitHub Actions SFTP/FTP, manual cPanel upload, and local server testing.
- **Raw Content Beautifier & Visual HTML Preview**:
  - Added **✨ Beautify Code** button in the Raw Content toolbar to format/indent JSON, CSS, JavaScript, HTML, and XML.
  - Added **👁️ Preview HTML** button to toggle a sandboxed iframe visual render of the snapshot.
  - Added **📋 Copy** button to quickly copy formatted or raw content to the clipboard.

### Changed
- **Snapshot Storage Deduplication & Informative Version Labels**:
  - Checks without content changes no longer generate redundant timestamped archive snapshot files (`snap_*.txt`); only `latest.txt` and the single latest snapshot are preserved.
  - Added "Last Checked: [timestamp]" metadata in the History modal header.
  - Formatted snapshot dropdown options to dynamically compute sequential diffs and display accurate change tags (e.g., `2026-09-18 02:29:08 (113B) [Small Change] (Recent)`, `2026-09-18 02:06:11 (133B) [Small Change]`, `[No Change]`, `[Baseline]`).

### Fixed
- **Changes Detected Count Excludes Archived Records**:
  - Dashboard stat card "Changes Detected" (`#statValChanges`) and `get_stats` API now dynamically calculate unarchived/active changes via [`Storage::countActiveChanges()`](file:///Users/bharat/Projects/changemonitor/includes/storage.php#L1160-L1170), excluding archived items.
  - Live metric updates on change record archiving and unarchiving.

## [2026-09-22]
### Added
- **Paste cURL and Target Form Autofill**:
  - Added an intuitive **📋 Paste cURL Command** importer box inside the Add/Edit Target Monitor modal.
  - Automatically parses raw or multi-line cURL commands, browser "Copy as cURL" formats, URLs, headers (`-H`, `--header`), cookies (`-b`, `--cookie`), and user agents (`-A`, `--user-agent`).
  - Autofills Target Name, Target URL, Custom Request Headers, Cookies, and matching Browser Request Profiles with instant feedback and live paste detection.

## [2026-09-21]
### Fixed
- **Notification Inline Diff Formatting & Attachment Fix**:
  - Corrected `is_big_change` threshold evaluation so line-based diff criteria dominates document character length, ensuring small line changes (1 or 2 lines) render full inline diffs with line numbers and red/green highlights across Email, Telegram, and WhatsApp alerts.
  - Retained standalone HTML snapshot file attachments (`previous_snapshot_*.html` and `new_snapshot_*.html`) on change notifications.

### Added
- **Direct Website Link to Change Comparison Explorer**:
  - Added direct deep-link (`index.php?route=changes&change_id=chg_xxx&monitor_id=mon_yyy`) to Email HTML, Telegram Markdown, and WhatsApp notification bodies.
  - Automatic URL query parameter handling on initial page load in `app.js` to navigate directly to **Detected Changes Explorer** and automatically open the **Change Comparison** modal for the specific change event.

## [2026-09-19]
### Added
- **Mobile Navigation Drawer & Relocated Primary Controls**:
  - Replaced top tab bar on mobile screens (`<768px`) with an animated slide-out **Mobile App Navigation Drawer** triggered via header menu toggle (`☰ Menu`).
  - Relocated primary monitor actions (**▶ Run All Checks**, **➕ Bulk Add**, **+ Add Target**) directly into the Monitors panel header.
  - Relocated **🎨 UI Theme Switcher** dropdown to a page footer with copyright and engine info.
- **Unread Stat Card Pulse & Highlight System**:
  - Automated tracking of `last_seen` counts for `total_changes` and `total_errors` in `localStorage`.
  - Highlights **Changes Detected 🔍** and **Errors Encountered ⚠️** cards with glowing animated border pulse and a high-visibility `NEW` badge whenever new metrics exceed last seen values.
  - Automatically clears unread highlight badges once the user clicks or views the corresponding explorer/log tab.
- **"Remember Me" Persistent Authentication**:
  - Secure persistent session authentication on trusted browsers via HTTP-only, `SameSite=Lax` `cm_remember` cookie token.
  - Automatic session re-authentication upon session expiration using SHA-256 token verification stored in `data/auth.json`.
  - Automatic token rotation upon each auto-login to prevent replay attacks and token revocation upon explicit logout.
  - Added **"Keep me logged in on this browser"** checkbox to the login form.
- **Mobile UI & App-View Grid Density Optimization**:
  - Responsive 2x2 grid layout for stat cards on mobile screens (`<768px`) allowing twice as much dashboard context to be visible above the fold.
  - Compact padding, font sizes, and layout heights across app container, panels, and modal views.
  - Mobile touch target compliance (>= 44px) for all buttons, tab items, and login controls without horizontal overflow.

## [2026-09-18]
### Added
- **Automatic Version Selection in Raw Content on Diff Line Clicks**:
  - Clicking a 🔴 (removed / previous) diff row in the History & Diffs modal automatically switches to the **📄 Raw Content** tab and selects the previous version snapshot in the dropdown, loading its numbered content and highlighting the corresponding line.
  - Clicking a 🟢 (added / new) or unchanged diff row automatically selects the new / comparison version snapshot, loads its content, and highlights the corresponding line.
- **Dynamic Changes Detected & Errors Count Reset on Deletion**:
  - Automatically decrements and resets global and daily metrics in `stats.json` (`total_changes`, `total_errors`, and `checks_by_date[date]['changes']` / `checks_by_date[date]['errors']`) whenever change records or error logs are deleted individually, in bulk, or fully cleared.
  - Live client-side stat card updates (`#statValChanges`, `#statValErrors`) via `api.php?action=get_stats` upon record deletion without requiring page reload.
- **Day-Wise Breakdown & Date Filtering**:
  - Enhanced **📊 Analytics** tab with a Day-Wise Actions column providing one-click jump buttons (`🔍 Changes (N)` and `⚠️ Logs (N)`) to view specific dates.
  - Added date filtering (`<input type="date">`) and query parameter support (`date=YYYY-MM-DD`) in both **Detected Changes Explorer** and **Server Error & System Logs** tabs and API (`get_changes_list`, `get_logs`).
  - Added global helper functions `openChangesForDate(date)` and `openLogsForDate(date)` for seamless day-wise navigation.
- **Multi-Theme Engine & Instant Theme Switcher**:
  - Added 7 customizable color themes: **Dark Night (Default)**, **☀️ Clean Light**, **🔴 Crimson Red**, **🔵 Cobalt Blue**, **🟢 Emerald Forest**, **🟣 Sunset Purple**, and **🌈 Cyberpunk Neon**.
  - Header theme switcher dropdown with automatic `localStorage` persistence and instant client-side switching.
  - Full variable-driven color system for backgrounds, cards, text contrast, borders, and ambient glow.
- **Interactive Diff-to-Raw Navigation & Numbered Raw Snapshot Viewer**:
  - Clicking any 🔴/🟢 Red & Green diff row or line number automatically switches to the **📄 Raw Content** tab, scrolls smoothly to that specific line, and highlights it with an animated pulse glow.
  - Numbered raw snapshot code table with gutter line numbers, high-contrast typography, and version selection sync.
- **Interactive Red/Green Diff View in Monitor History**:
  - Modal toggle between **🔴/🟢 Red & Green Diff**, **📄 Raw Content**, and **📋 Change Log**.
  - Dynamic snapshot version comparison selector with instant on-demand diff computation.
- **Detected Changes Explorer & Drilldown**:
  - Clickable **Changes Detected** dashboard card and monitor change count badges opening a comprehensive Changes Explorer modal.
  - Searchable and filterable change event log with snapshot sizes and +/- change badges.
  - Single-change detail inspector showing line-by-line red/green previous vs. new diff table.
- **Change & Error Archive/Deletion Management**:
  - Single and bulk **Archive/Unarchive** (`archive_changes`) and **Delete** (`delete_changes`) for detected change events.
  - Single log entry deletion (`delete_logs`), bulk selected error log deletion, and full log purge (`clear_logs_by_filter`).
  - Clickable **Errors Encountered** dashboard card jumping directly to Server Error & System Logs tab.
- **Contextual Line Diff Notification System** (`DiffFormatter`):
  - **Small Changes**: Color-coded inline diffs across Gmail (SMTP HTML table with `#fee2e2` deletions / `#dcfce7` additions), Telegram (`🔴 - [L#]` and `🟢 + [L#]`), and WhatsApp (`~🔴 - [L#]~` and `*🟢 + [L#]*`).
  - **JSON & HTML Context Extraction**: Automatic detection and labeling of JSON property hierarchy/keys (e.g. `[status]`, `[rates.EUR]`) and HTML container tag contexts next to line numbers.
  - **Big Changes Fallback**: Automatically classifies large diffs exceeding configurable line threshold (`diff_big_change_threshold_lines`) or payload size limits.
  - **Standalone Snapshot File Dispatches**: For big changes, attaches self-contained dark-themed HTML snapshot files (`previous_snapshot_*.html` and `new_snapshot_*.html`) with highlighted red/green changes to Email (MIME `multipart/mixed`), Telegram (`sendDocument`), and WhatsApp (`sendOpenWAFile`).
  - Added configurable **Big Change Diff Threshold (lines)** setting in Admin Settings tab and API.
- Integrated **🗑️ Trash / Deleted** sub-tab directly beside Active and Inactive sub-tabs within the Monitors panel.
- Support for batch updating **⚡ Peak / Off-Peak Request Frequency** in the Bulk Edit modal and `Storage::bulkEditMonitors`.
- Tab and sub-tab state persistence using `sessionStorage` and URL hash ensuring active tab/sub-tab is preserved across form submits and reloads.
- Dedicated **Trash / Deleted Monitors** with soft-delete safeguards, single and bulk restore (`♻️ Restore Selected`), and permanent purge (`🔥 Empty Trash`).
- Explicit **Ungrouped** category filter pill and full group visibility ensuring all custom groups and unassigned monitors are displayed without omission.
- Comprehensive **✏️ Bulk Edit** modal and API (`bulk_edit`) to batch update Group, Check Interval, Request Profile, Extraction Mode, Timeout, Peak Schedules, and Notification flags across selected targets.
- Monitor grouping and category tagging system (e.g. E-Commerce, Competitors, Infrastructure) with group badges in monitor tables.
- Interactive category filter bar with target counts allowing instant client-side group filtering on active/inactive tables.
- Group input with datalist auto-suggestions in Add/Edit Monitor and Bulk Add modals.
- Bulk `📁 Set Group` action in floating toolbar to reassign multiple selected monitors to any group in batch.
- Bulk add targets modal allowing multi-line URL or Name/URL parsing with shared default profile, intervals, and immediate baseline checks.
- Checkbox selection in monitors table (row checkboxes and Select All master checkbox for active and inactive sub-tabs).
- Floating bulk actions toolbar with badge counter and one-click Bulk Activate, Bulk Pause, Bulk Run Now, and Bulk Delete actions.
- Backend bulk storage operations (`saveMonitorsBulk`, `bulkUpdateStatus`, `bulkDeleteMonitors`, `bulkAssignGroup`, `bulkEditMonitors`, `restoreMonitor`, `bulkRestoreMonitors`, `purgeDeletedMonitor`, `emptyTrash`) and API endpoints (`bulk_add_monitors`, `bulk_status`, `bulk_delete`, `bulk_run_check`, `bulk_set_group`, `bulk_edit`, `get_trash`, `restore_monitor`, `bulk_restore`, `purge_trash`, `empty_trash`).

### Fixed
- Fixed `TypeError: Argument #4 ($date) must be of type string, int given` in `Storage::getChangeEvents` by adding type union `string|int $date` with backward-compatible limit assignment and explicit named arguments in `get_history` API endpoint.
- Fixed `TypeError: Cannot set properties of null (setting 'checked')` in `window.editMonitor` when editing a target monitor by safely checking DOM elements and removing deprecated field references.
- Fixed DOM warning regarding multiple form actions by cleanly separating `#settingsForm` from standalone Backup & Restore and cPanel Cron information panels.
- Fixed brand-title and table timestamp text visibility in Clean Light theme by replacing hardcoded `#fff` with dynamic `var(--text-primary)`.
- Improved color contrast in light theme for group badges, table headers, secondary buttons, bulk selected badge, and link hover states.
- Fixed `ReferenceError: Cannot access 'bulkSelectedCount' before initialization` by moving core selection and group filtering functions before sub-tab initialization.
- Fixed category/group pill clicks by implementing event delegation on the group filter bar.
- Implemented dynamic sub-tab-aware group counts so group filter pills reflect active monitors on the Active sub-tab and inactive monitors on the Inactive sub-tab.
- Fixed bug where deleting a few filtered or selected monitors resulted in deleting all monitors due to global unconstrained checkbox querying and master checkbox targeting hidden group rows.
- Fixed cross-subtab checkbox bleeding when switching between Active, Inactive, and Trash sub-tabs.
- Fixed page reset to default tab upon saving or editing monitors, settings, and bulk operations.

## [2026-09-16]
### Added
- Core PHP engine and storage with flock locks for Bluehost shared hosting.
- HTTP Fetcher with realistic browser profile headers (Chrome, Firefox, Safari iOS, Googlebot) and anti-blocking measures.
- Multi-mode extractor for Full HTML, XPath, CSS selectors, JSON dot-paths, Regex, and HTTP response headers.
- Unified line-by-line diff computation and text-file history logger.
- Multi-channel notification dispatcher supporting Gmail SMTP (socket/TLS with App Passwords), Telegram Bot API, and OpenWA WhatsApp webhooks.
- Single-page responsive dark admin dashboard with analytics cards, live selector preview modal, history/snapshot viewer, and settings management.
- Standalone CLI / cPanel / Webhook cron runner `cron.php` with secret token authentication.
- Single-file full backup export and one-click restore (monitors, settings, notification tokens, stats, and text snapshot logs).
- Root `.htaccess` to transparently serve `/public/` from root directory `/changedetector/`.
- Extensionless `.php` routing and server `X-Powered-By` header removal.
- Searchable and filterable server error and system logs tab in admin dashboard.
- Configurable global application timezone with `Asia/Kolkata` (IST +5:30) as default.
- Target-specific peak vs off-peak request scheduling (e.g. higher frequency during custom business/market hours).
- Quick Enable / Disable (Pause / Resume) toggle buttons on monitors table.
- Added Government / Azure FrontDoor Portal anti-bot profile with automatic persistent cookie jar session management to prevent 403 blocks.
- Selectable snapshot version history dropdown inside the History & Snapshots modal.
- Replaced inline text history log box with a direct `.txt` download button and expanded snapshot viewer.
- Fixed missing `break` in `get_history` API endpoint preventing corrupted JSON output.
- Adjusted `gov_portal` browser profile headers and disabled `CURLOPT_AUTOREFERER` to eliminate Azure FrontDoor WAF 403 Forbidden / 400 Bad Request responses.
- Added `🎯 Active` and `⏸️ Inactive` sub-tabs inside the main Monitors tab with real-time target counts.
- Added live, second-by-second **Next Check** countdown timer column on active and inactive target tables.
- Added server CA bundle discovery and automatic SSL fallback retry for legacy government endpoints with missing local issuer certs.
- Added self-healing anti-bot retry with automatic cookie-jar reset and clean header fallback for Azure FrontDoor WAF 403/400 blocks.
- Added UTF-8 character sanitization with `JSON_INVALID_UTF8_SUBSTITUTE` for live extraction previews.
- Added automatic origin-derived Referer injection and Indian IP forwarding headers (`X-Forwarded-For`, `X-Real-IP`, `Client-IP`) to bypass Azure FrontDoor WAF 403 blocks on foreign datacenter IPs.
- Implemented multi-tier self-healing fallback with clean HTTP/1.1 API mode for SPA endpoints.


