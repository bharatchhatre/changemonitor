# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).

## [2026-09-18]
### Added
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


