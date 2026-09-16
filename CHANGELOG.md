# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).

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

