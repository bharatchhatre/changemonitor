# Website Change Monitor ⚡

A lightweight, zero-bloat, secure core PHP admin web application built for Bluehost shared hosting to monitor website and API changes, prevent bot detection, store change history in text files, and notify via Gmail, Telegram, and OpenWA.

---

## Features

- **Multi-Type Selectors**: Full HTML/Text, DOM XPath, CSS Selectors, JSON REST APIs (dot-notation), Regex, and HTTP Response Headers.
- **Anti-Bot & Request Profiles**: Built-in modern browser profiles (Chrome macOS/Win, Safari iOS, Firefox, Googlebot) with full realistic client-hint headers, custom cookies, and proxy support.
- **Live Preview & Test Runner**: Test selectors and preview extracted content directly in the admin modal before saving.
- **Text & Flat-File History**: Per-monitor change logs and timestamped snapshots stored in flat text files protected with atomic locks (`flock`).
- **Multi-Channel Notifications**:
  - **Gmail SMTP**: Socket TLS delivery with Google App Passwords.
  - **Telegram**: Instant messages with formatted diff snippets.
  - **OpenWA**: WhatsApp API integration.
- **Analytics**: 30-day rolling activity, uptime/success rates, change frequencies, and speed metrics.
- **Bluehost Cron**: Standalone `cron.php` executable via cPanel Cron Jobs or Webhook.
- **GitHub Actions**: Automated SFTP/FTP deployment workflow.

---

## Directory Structure

```
changemonitor/
├── .github/workflows/deploy.yml   # Bluehost FTP/SFTP deployment
├── data/                          # Data store (.htaccess protected)
│   ├── history/                   # Per-monitor flat text change logs & snapshots
│   ├── logs/                      # Error & access logs
│   ├── monitors.json              # Monitored targets
│   ├── settings.json              # Global notification & app config
│   └── stats.json                 # Analytics metrics
├── includes/
│   ├── auth.php                   # Authentication & CSRF
│   ├── config.php                 # Environment loader (.env)
│   ├── engine.php                 # Monitor execution engine
│   ├── extractor.php              # XPath/CSS/JSON/Regex/Diff extractor
│   ├── fetcher.php                # Smart cURL requester & browser profiles
│   ├── notifier.php               # Gmail, Telegram & OpenWA dispatcher
│   └── storage.php                # Flat-file database with flock
├── public/
│   ├── index.php                  # Admin dashboard UI & login
│   ├── api.php                    # AJAX endpoints
│   ├── cron.php                   # cPanel Cron runner
│   ├── assets/
│   │   ├── css/style.css          # Responsive mobile-first CSS
│   │   └── js/app.js              # Reactive UI & Live diff viewer
├── .env.example                   # Sample environment configuration
└── CHANGELOG.md                   # Version changelog
```

---

## Bluehost Setup & Installation

### 1. Configuration (`.env`)
Copy `.env.example` to `.env` in the root folder:
```bash
cp .env.example .env
```
Update your `ADMIN_PASSWORD`, `CRON_TOKEN`, and notification settings.

### 2. Bluehost cPanel Cron Job
In **cPanel &gt; Cron Jobs**, add a cron job (e.g., every 15 minutes):
```bash
/usr/local/bin/php /home/YOUR_CPANEL_USER/public_html/changemonitor/public/cron.php
```

### 3. GitHub Actions Deployment Setup
In your GitHub repository settings under **Secrets and variables &gt; Actions**, add:
- `FTP_SERVER`: Your Bluehost server hostname or IP (e.g. `ftp.yourdomain.com`).
- `FTP_USERNAME`: Your cPanel FTP username.
- `FTP_PASSWORD`: Your cPanel FTP password.
- `FTP_REMOTE_DIR`: Target path on Bluehost (e.g. `public_html/changemonitor/`).
