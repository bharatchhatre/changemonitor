<?php
/**
 * Main Web Dashboard & Admin UI
 * Website Change Monitor
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/storage.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/fetcher.php';

Auth::startSession();

$route = $_GET['route'] ?? 'dashboard';

// Handle Login POST
$loginError = '';
if (($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) || ($route === 'login' && $_SERVER['REQUEST_METHOD'] === 'POST')) {
    $password = $_POST['password'] ?? '';
    if (Auth::login($password)) {
        header('Location: ./');
        exit;
    } else {
        $loginError = 'Invalid admin password or account temporarily locked.';
    }
}

// Handle Logout
if ($route === 'logout') {
    Auth::logout();
    header('Location: ./?route=login');
    exit;
}

// Show Login View if not authenticated
if (!Auth::check()) {
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Login &bull; <?= htmlspecialchars(cm_env('APP_NAME', 'Website Change Monitor')) ?></title>
        <link rel="stylesheet" href="assets/css/style.css">
        <style>
            .login-wrapper {
                min-height: 100vh;
                display: flex;
                align-items: center;
                justify-content: center;
                padding: 1rem;
            }
            .login-card {
                width: 100%;
                max-width: 420px;
                background: var(--bg-secondary);
                border: 1px solid var(--border-color);
                border-radius: var(--radius-lg);
                padding: 2.25rem 2rem;
                box-shadow: var(--shadow-lg);
            }
        </style>
    </head>
    <body>
        <div class="login-wrapper">
            <div class="login-card">
                <div style="text-align: center; margin-bottom: 2rem;">
                    <div class="brand-icon" style="margin: 0 auto 1rem; width: 48px; height: 48px; font-size: 1.5rem;">⚡</div>
                    <h1 style="font-size: 1.4rem; font-weight: 700;"><?= htmlspecialchars(cm_env('APP_NAME', 'Website Change Monitor')) ?></h1>
                    <p style="color: var(--text-muted); font-size: 0.85rem; margin-top: 0.25rem;">Admin Access Portal</p>
                </div>

                <?php if (!empty($loginError)): ?>
                    <div style="background: var(--danger-bg); color: var(--danger); padding: 0.75rem 1rem; border-radius: var(--radius-sm); font-size: 0.875rem; margin-bottom: 1.25rem; border: 1px solid rgba(239, 68, 68, 0.3);">
                        <?= htmlspecialchars($loginError) ?>
                    </div>
                <?php endif; ?>

                <form method="POST" action="">
                    <div class="form-group">
                        <label class="form-label" for="password">Admin Password</label>
                        <input type="password" id="password" name="password" class="form-control" placeholder="Enter password..." required autofocus>
                    </div>
                    <button type="submit" class="btn btn-primary" style="width: 100%; margin-top: 1rem;">Unlock Dashboard</button>
                </form>
            </div>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// Authenticated Admin Dashboard
$monitors = Storage::getMonitors();
$settings = Storage::getSettings();
$stats = Storage::getStats();
$builtinTemplates = Fetcher::getAllTemplates();
$csrfToken = Auth::getCsrfToken();

// Compute active & health counts
$activeMonitors = [];
$inactiveMonitors = [];
$changesToday = $stats['checks_by_date'][date('Y-m-d')]['changes'] ?? 0;
foreach ($monitors as $id => $m) {
    if (($m['status'] ?? 'active') === 'active') {
        $activeMonitors[$id] = $m;
    } else {
        $inactiveMonitors[$id] = $m;
    }
}
$activeCount = count($activeMonitors);
$inactiveCount = count($inactiveMonitors);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= htmlspecialchars($csrfToken) ?>">
    <title><?= htmlspecialchars(cm_env('APP_NAME', 'Website Change Monitor')) ?> &bull; Dashboard</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
    <div class="app-container">
        <!-- Header -->
        <header class="app-header">
            <div class="brand-area">
                <div class="brand-icon">⚡</div>
                <div>
                    <div class="brand-title"><?= htmlspecialchars(cm_env('APP_NAME', 'Website Change Monitor')) ?></div>
                    <div class="brand-subtitle">Bluehost Shared Hosting Engine &bull; v<?= CM_VERSION ?></div>
                </div>
            </div>
            <div class="nav-actions">
                <button class="btn btn-secondary btn-sm" id="runAllBtn">▶ Run All Checks</button>
                <button class="btn btn-primary btn-sm" id="addMonitorBtn">+ Add Target</button>
                <a href="?route=logout" class="btn btn-secondary btn-sm" title="Logout">Logout</a>
            </div>
        </header>

        <!-- Navigation Tabs -->
        <nav class="tabs-nav">
            <button class="tab-btn active" data-tab="tab-active-monitors">🎯 Active Targets (<?= $activeCount ?>)</button>
            <button class="tab-btn" data-tab="tab-inactive-monitors">⏸️ Inactive / Paused (<?= $inactiveCount ?>)</button>
            <button class="tab-btn" data-tab="tab-analytics">📊 Analytics</button>
            <button class="tab-btn" data-tab="tab-logs">📋 Error & System Logs</button>
            <button class="tab-btn" data-tab="tab-templates">🛡️ Request Profiles</button>
            <button class="tab-btn" data-tab="tab-settings">⚙️ Settings & Notifications</button>
        </nav>

        <!-- Stats Grid -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-label">Total Targets</div>
                <div class="stat-value"><?= count($monitors) ?></div>
                <div class="stat-desc"><?= $activeCount ?> active monitoring</div>
            </div>
            <div class="stat-card stat-success">
                <div class="stat-label">Total Checks</div>
                <div class="stat-value"><?= number_format($stats['total_checks'] ?? 0) ?></div>
                <div class="stat-desc">Avg speed: <?= $stats['avg_response_time_ms'] ?? 0 ?>ms</div>
            </div>
            <div class="stat-card stat-warning">
                <div class="stat-label">Changes Detected</div>
                <div class="stat-value"><?= number_format($stats['total_changes'] ?? 0) ?></div>
                <div class="stat-desc"><?= $changesToday ?> changes detected today</div>
            </div>
            <div class="stat-card stat-danger">
                <div class="stat-label">Errors Encountered</div>
                <div class="stat-value"><?= number_format($stats['total_errors'] ?? 0) ?></div>
                <div class="stat-desc">4xx / 5xx / Network timeouts</div>
            </div>
        </div>

        <!-- TAB 1: Active Monitors List -->
        <div class="tab-pane" id="tab-active-monitors">
            <div class="panel">
                <div class="panel-header">
                    <div class="panel-title">Active Monitored Targets (<?= $activeCount ?>)</div>
                    <span style="font-size: 0.85rem; color: var(--text-muted);">Scheduled & running checks</span>
                </div>

                <div class="table-responsive">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Status</th>
                                <th>Target / Name</th>
                                <th>Type & Selector</th>
                                <th>Profile</th>
                                <th>Last Check</th>
                                <th>Changes</th>
                                <th style="text-align: right;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($activeMonitors)): ?>
                                <tr>
                                    <td colspan="7" style="text-align: center; color: var(--text-muted); padding: 3rem 1rem;">
                                        No active targets. Click <strong>"+ Add Target"</strong> above or resume a paused monitor from the Inactive tab.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($activeMonitors as $id => $m): ?>
                                    <tr>
                                        <td>
                                            <?php if (!empty($m['last_error'])): ?>
                                                <span class="badge badge-error">Error</span>
                                            <?php else: ?>
                                                <span class="badge badge-active">Active</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <strong><?= htmlspecialchars($m['name']) ?></strong>
                                            <div style="font-size: 0.775rem; color: var(--text-muted); word-break: break-all;">
                                                <a href="<?= htmlspecialchars($m['url']) ?>" target="_blank" rel="noopener noreferrer"><?= htmlspecialchars($m['url']) ?></a>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="badge badge-type"><?= htmlspecialchars($m['type'] ?? 'html_full') ?></span>
                                            <?php if (!empty($m['selector'])): ?>
                                                <div style="font-family: var(--font-mono); font-size: 0.75rem; color: var(--text-muted); margin-top: 0.25rem;">
                                                    <?= htmlspecialchars(substr($m['selector'], 0, 30)) ?><?= strlen($m['selector']) > 30 ? '...' : '' ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span style="font-size: 0.8rem; color: var(--text-secondary);">
                                                <?= htmlspecialchars($builtinTemplates[$m['browser_template'] ?? 'chrome_mac']['name'] ?? $m['browser_template']) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if (!empty($m['last_check_at'])): ?>
                                                <div style="font-size: 0.85rem;"><?= date('M d, H:i', strtotime($m['last_check_at'])) ?></div>
                                                <div style="font-size: 0.75rem; color: var(--text-muted);">
                                                    HTTP <?= $m['last_status_code'] ?? '-' ?>
                                                </div>
                                            <?php else: ?>
                                                <span style="color: var(--text-muted);">Never</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <strong><?= (int)($m['change_count'] ?? 0) ?></strong>
                                            <?php if (!empty($m['last_change_at'])): ?>
                                                <div style="font-size: 0.75rem; color: var(--warning);">
                                                    <?= date('M d, H:i', strtotime($m['last_change_at'])) ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td style="text-align: right; white-space: nowrap;">
                                            <button class="btn btn-secondary btn-sm" onclick="toggleStatus('<?= $id ?>', this)" title="Pause / Disable Monitor" style="color: var(--warning);">⏸️</button>
                                            <button class="btn btn-secondary btn-sm" onclick="runCheck('<?= $id ?>', this)" title="Run Check Now">⚡</button>
                                            <button class="btn btn-secondary btn-sm" onclick="viewHistory('<?= $id ?>')" title="View History & Snapshots">📜</button>
                                            <button class="btn btn-secondary btn-sm" onclick='editMonitor(<?= json_encode($m, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)' title="Edit Monitor">✏️</button>
                                            <button class="btn btn-danger btn-sm" onclick="deleteMonitor('<?= $id ?>', '<?= htmlspecialchars(addslashes($m['name'])) ?>')" title="Delete">🗑️</button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- TAB 2: Inactive / Paused Monitors List -->
        <div class="tab-pane" id="tab-inactive-monitors" style="display: none;">
            <div class="panel">
                <div class="panel-header">
                    <div class="panel-title">Inactive / Paused Targets (<?= $inactiveCount ?>)</div>
                    <span style="font-size: 0.85rem; color: var(--text-muted);">Skipped during automated cron runs</span>
                </div>

                <div class="table-responsive">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Status</th>
                                <th>Target / Name</th>
                                <th>Type & Selector</th>
                                <th>Profile</th>
                                <th>Last Check</th>
                                <th>Changes</th>
                                <th style="text-align: right;">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($inactiveMonitors)): ?>
                                <tr>
                                    <td colspan="7" style="text-align: center; color: var(--text-muted); padding: 3rem 1rem;">
                                        No inactive or paused monitors.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($inactiveMonitors as $id => $m): ?>
                                    <tr>
                                        <td>
                                            <span class="badge badge-paused">Paused</span>
                                        </td>
                                        <td>
                                            <strong><?= htmlspecialchars($m['name']) ?></strong>
                                            <div style="font-size: 0.775rem; color: var(--text-muted); word-break: break-all;">
                                                <a href="<?= htmlspecialchars($m['url']) ?>" target="_blank" rel="noopener noreferrer"><?= htmlspecialchars($m['url']) ?></a>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="badge badge-type"><?= htmlspecialchars($m['type'] ?? 'html_full') ?></span>
                                            <?php if (!empty($m['selector'])): ?>
                                                <div style="font-family: var(--font-mono); font-size: 0.75rem; color: var(--text-muted); margin-top: 0.25rem;">
                                                    <?= htmlspecialchars(substr($m['selector'], 0, 30)) ?><?= strlen($m['selector']) > 30 ? '...' : '' ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span style="font-size: 0.8rem; color: var(--text-secondary);">
                                                <?= htmlspecialchars($builtinTemplates[$m['browser_template'] ?? 'chrome_mac']['name'] ?? $m['browser_template']) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if (!empty($m['last_check_at'])): ?>
                                                <div style="font-size: 0.85rem;"><?= date('M d, H:i', strtotime($m['last_check_at'])) ?></div>
                                                <div style="font-size: 0.75rem; color: var(--text-muted);">
                                                    HTTP <?= $m['last_status_code'] ?? '-' ?>
                                                </div>
                                            <?php else: ?>
                                                <span style="color: var(--text-muted);">Never</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <strong><?= (int)($m['change_count'] ?? 0) ?></strong>
                                            <?php if (!empty($m['last_change_at'])): ?>
                                                <div style="font-size: 0.75rem; color: var(--warning);">
                                                    <?= date('M d, H:i', strtotime($m['last_change_at'])) ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td style="text-align: right; white-space: nowrap;">
                                            <button class="btn btn-secondary btn-sm" onclick="toggleStatus('<?= $id ?>', this)" title="Resume / Enable Monitor" style="color: var(--success);">▶️ Resume</button>
                                            <button class="btn btn-secondary btn-sm" onclick="runCheck('<?= $id ?>', this)" title="Run Check Now">⚡</button>
                                            <button class="btn btn-secondary btn-sm" onclick="viewHistory('<?= $id ?>')" title="View History & Snapshots">📜</button>
                                            <button class="btn btn-secondary btn-sm" onclick='editMonitor(<?= json_encode($m, JSON_HEX_APOS | JSON_HEX_QUOT) ?>)' title="Edit Monitor">✏️</button>
                                            <button class="btn btn-danger btn-sm" onclick="deleteMonitor('<?= $id ?>', '<?= htmlspecialchars(addslashes($m['name'])) ?>')" title="Delete">🗑️</button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- TAB 2: Analytics -->
        <div class="tab-pane" id="tab-analytics" style="display: none;">
            <div class="panel">
                <div class="panel-header">
                    <div class="panel-title">Check Activity & Change Analytics (Last 30 Days)</div>
                </div>
                <div class="table-responsive">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Date</th>
                                <th>Total Checks</th>
                                <th>Changes Detected</th>
                                <th>Errors</th>
                                <th>Success Rate</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $checksByDate = $stats['checks_by_date'] ?? [];
                            krsort($checksByDate);
                            if (empty($checksByDate)):
                            ?>
                                <tr>
                                    <td colspan="5" style="text-align: center; color: var(--text-muted); padding: 2rem;">No analytics logged yet. Run some checks to populate data.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($checksByDate as $date => $row): ?>
                                    <?php
                                    $rate = $row['checks'] > 0 ? round((($row['checks'] - $row['errors']) / $row['checks']) * 100, 1) : 100;
                                    ?>
                                    <tr>
                                        <td><strong><?= htmlspecialchars($date) ?></strong></td>
                                        <td><?= number_format($row['checks']) ?></td>
                                        <td><span class="badge badge-changed"><?= $row['changes'] ?></span></td>
                                        <td><?= $row['errors'] > 0 ? "<span class='badge badge-error'>{$row['errors']}</span>" : '0' ?></td>
                                        <td><span class="badge badge-active"><?= $rate ?>%</span></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- TAB: Error & System Logs (Searchable & Filterable) -->
        <div class="tab-pane" id="tab-logs" style="display: none;">
            <div class="panel">
                <div class="panel-header">
                    <div class="panel-title">Server Error & System Logs</div>
                    <div style="display: flex; gap: 0.5rem;">
                        <button class="btn btn-secondary btn-sm" id="refreshLogsBtn">🔄 Refresh</button>
                        <button class="btn btn-danger btn-sm" id="clearLogsBtn">🗑️ Clear Logs</button>
                    </div>
                </div>

                <!-- Search & Filters Bar -->
                <div style="display: flex; gap: 0.75rem; flex-wrap: wrap; margin-bottom: 1.25rem; background: rgba(15, 23, 42, 0.6); padding: 1rem; border-radius: var(--radius-md); border: 1px solid var(--border-color);">
                    <div style="flex: 1; min-width: 200px;">
                        <input type="text" id="logSearchInput" class="form-control" placeholder="🔍 Search message, error or monitor ID..." style="min-height: 38px;">
                    </div>
                    <div style="width: 150px;">
                        <select id="logLevelSelect" class="form-control" style="min-height: 38px;">
                            <option value="">All Levels</option>
                            <option value="ERROR">ERROR</option>
                            <option value="WARNING">WARNING</option>
                            <option value="INFO">INFO</option>
                        </select>
                    </div>
                    <div style="width: 150px;">
                        <select id="logCategorySelect" class="form-control" style="min-height: 38px;">
                            <option value="">All Categories</option>
                            <option value="MONITOR">MONITOR</option>
                            <option value="FETCHER">FETCHER</option>
                            <option value="AUTH">AUTH</option>
                            <option value="NOTIFIER">NOTIFIER</option>
                            <option value="SYSTEM">SYSTEM</option>
                        </select>
                    </div>
                </div>

                <div class="table-responsive">
                    <table class="data-table" id="logsTable">
                        <thead>
                            <tr>
                                <th style="width: 140px;">Timestamp</th>
                                <th style="width: 90px;">Level</th>
                                <th style="width: 110px;">Category</th>
                                <th>Message / Context</th>
                                <th style="width: 90px;">IP / Source</th>
                            </tr>
                        </thead>
                        <tbody id="logsTableBody">
                            <tr>
                                <td colspan="5" style="text-align: center; color: var(--text-muted); padding: 2rem;">Loading logs...</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- TAB 3: Request Profiles & Templates -->
        <div class="tab-pane" id="tab-templates" style="display: none;">
            <div class="panel">
                <div class="panel-header">
                    <div class="panel-title">Smart Request Profiles & Anti-Bot Protection</div>
                </div>
                <p style="color: var(--text-secondary); font-size: 0.9rem; margin-bottom: 1.5rem;">
                    These profiles send complete modern browser request headers (including Sec-Ch-Ua, Accept-Language, and Keep-Alive) to prevent cloud firewalls and anti-scraping filters from blocking requests.
                </p>

                <div class="form-grid">
                    <?php foreach ($builtinTemplates as $key => $tmpl): ?>
                        <div style="background: rgba(15, 23, 42, 0.6); padding: 1.25rem; border-radius: var(--radius-md); border: 1px solid var(--border-color);">
                            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem;">
                                <strong><?= htmlspecialchars($tmpl['name']) ?></strong>
                                <span class="badge badge-type"><?= htmlspecialchars($key) ?></span>
                            </div>
                            <div style="font-size: 0.775rem; color: var(--text-muted); font-family: var(--font-mono); word-break: break-all; margin-bottom: 0.75rem;">
                                UA: <?= htmlspecialchars($tmpl['user_agent'] ?? 'Default') ?>
                            </div>
                            <div style="font-size: 0.75rem; color: var(--text-secondary);">
                                <strong>Headers:</strong> <?= count($tmpl['headers'] ?? []) ?> default emulation headers
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- TAB 4: Settings & Notifications -->
        <div class="tab-pane" id="tab-settings" style="display: none;">
            <div class="panel">
                <div class="panel-header">
                    <div class="panel-title">Multi-Channel Notifications & System Settings</div>
                </div>

                <form id="settingsForm">
                    <!-- Telegram Settings -->
                    <div style="margin-bottom: 2rem;">
                        <h3 style="font-size: 1rem; color: var(--info); margin-bottom: 1rem; display: flex; align-items: center; justify-content: space-between;">
                            <span>✈️ Telegram Bot Alerts</span>
                            <button type="button" class="btn btn-secondary btn-sm test-notify-btn" data-channel="telegram">Test Telegram</button>
                        </h3>
                        <div class="form-grid">
                            <div class="form-group">
                                <label class="form-label" for="settingTelegramToken">Bot API Token</label>
                                <input type="text" id="settingTelegramToken" class="form-control" placeholder="123456789:ABCdefGHIjklMNO..." value="<?= htmlspecialchars($settings['telegram_bot_token'] ?? '') ?>">
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="settingTelegramChatId">Chat / Channel ID</label>
                                <input type="text" id="settingTelegramChatId" class="form-control" placeholder="-100123456789 or @channel" value="<?= htmlspecialchars($settings['telegram_chat_id'] ?? '') ?>">
                            </div>
                        </div>
                    </div>

                    <!-- OpenWA (WhatsApp) Settings -->
                    <div style="margin-bottom: 2rem;">
                        <h3 style="font-size: 1rem; color: var(--success); margin-bottom: 1rem; display: flex; align-items: center; justify-content: space-between;">
                            <span>💬 OpenWA (WhatsApp Webhook) Alerts</span>
                            <button type="button" class="btn btn-secondary btn-sm test-notify-btn" data-channel="openwa">Test OpenWA</button>
                        </h3>
                        <div class="form-grid">
                            <div class="form-group">
                                <label class="form-label" for="settingOpenwaUrl">OpenWA Server / Webhook URL</label>
                                <input type="text" id="settingOpenwaUrl" class="form-control" placeholder="http://your-server:8080/sendText" value="<?= htmlspecialchars($settings['openwa_api_url'] ?? '') ?>">
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="settingOpenwaKey">API Key / Token (Optional)</label>
                                <input type="text" id="settingOpenwaKey" class="form-control" placeholder="Bearer or API key..." value="<?= htmlspecialchars($settings['openwa_api_key'] ?? '') ?>">
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="settingOpenwaChatId">WhatsApp Number / Group ID</label>
                                <input type="text" id="settingOpenwaChatId" class="form-control" placeholder="1234567890 (no + sign or @c.us)" value="<?= htmlspecialchars($settings['openwa_chat_id'] ?? '') ?>">
                            </div>
                        </div>
                    </div>

                    <!-- Gmail SMTP Settings -->
                    <div style="margin-bottom: 2rem;">
                        <h3 style="font-size: 1rem; color: var(--warning); margin-bottom: 1rem; display: flex; align-items: center; justify-content: space-between;">
                            <span>📧 Gmail SMTP Email Alerts</span>
                            <button type="button" class="btn btn-secondary btn-sm test-notify-btn" data-channel="email">Test Email</button>
                        </h3>
                        <div class="form-grid">
                            <div class="form-group">
                                <label class="form-label" for="settingSmtpHost">SMTP Host</label>
                                <input type="text" id="settingSmtpHost" class="form-control" value="<?= htmlspecialchars($settings['gmail_smtp_host'] ?? 'smtp.gmail.com') ?>">
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="settingSmtpPort">SMTP Port</label>
                                <input type="number" id="settingSmtpPort" class="form-control" value="<?= (int)($settings['gmail_smtp_port'] ?? 587) ?>">
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="settingSmtpUser">Gmail Address (Sender)</label>
                                <input type="email" id="settingSmtpUser" class="form-control" placeholder="youremail@gmail.com" value="<?= htmlspecialchars($settings['gmail_smtp_user'] ?? '') ?>">
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="settingSmtpPass">Google App Password (16 chars)</label>
                                <input type="password" id="settingSmtpPass" class="form-control" placeholder="xxxx xxxx xxxx xxxx" value="<?= htmlspecialchars($settings['gmail_smtp_pass'] ?? '') ?>">
                                <p class="form-help">Generate in Google Account &gt; Security &gt; 2-Step Verification &gt; App Passwords.</p>
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="settingAlertEmail">Recipient Email Address</label>
                                <input type="email" id="settingAlertEmail" class="form-control" placeholder="alerts@yourdomain.com" value="<?= htmlspecialchars($settings['alert_email_to'] ?? '') ?>">
                            </div>
                        </div>
                    </div>

                    <!-- Notification Conditions & General Settings -->
                    <div style="margin-bottom: 2rem;">
                        <h3 style="font-size: 1rem; margin-bottom: 1rem;">⚙️ System Settings & Security</h3>
                        <div class="form-grid">
                            <div class="form-group">
                                <label class="form-label" for="settingAppTimezone">Application Timezone</label>
                                <select id="settingAppTimezone" class="form-control">
                                    <?php
                                    $currentTimezone = $settings['app_timezone'] ?? 'Asia/Kolkata';
                                    $commonTimezones = [
                                        'Asia/Kolkata' => 'Asia/Kolkata (IST +5:30) [Default]',
                                        'UTC' => 'UTC (+0:00)',
                                        'America/New_York' => 'America/New York (EST/EDT)',
                                        'America/Chicago' => 'America/Chicago (CST/CDT)',
                                        'America/Los_Angeles' => 'America/Los Angeles (PST/PDT)',
                                        'Europe/London' => 'Europe/London (GMT/BST)',
                                        'Europe/Paris' => 'Europe/Paris (CET/CEST)',
                                        'Asia/Dubai' => 'Asia/Dubai (GST +4:00)',
                                        'Asia/Singapore' => 'Asia/Singapore (SGT +8:00)',
                                        'Asia/Tokyo' => 'Asia/Tokyo (JST +9:00)',
                                        'Australia/Sydney' => 'Australia/Sydney (AEST/AEDT)',
                                    ];
                                    foreach ($commonTimezones as $tzKey => $tzLabel):
                                    ?>
                                        <option value="<?= htmlspecialchars($tzKey) ?>" <?= $currentTimezone === $tzKey ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($tzLabel) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="form-help">Current Server Time: <strong><?= date('Y-m-d H:i:s T') ?></strong></p>
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="settingDefaultInterval">Default Check Interval (Minutes)</label>
                                <input type="number" id="settingDefaultInterval" class="form-control" min="1" value="<?= (int)($settings['default_interval_mins'] ?? 15) ?>">
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="settingNewPassword">Update Admin Password</label>
                                <input type="password" id="settingNewPassword" class="form-control" placeholder="Leave blank to keep current password">
                            </div>
                        </div>

                        <div class="form-grid" style="margin-top: 1rem;">
                            <label class="checkbox-label">
                                <input type="checkbox" id="settingNotifyChange" <?= !empty($settings['notify_on_change']) ? 'checked' : '' ?>>
                                Notify on Detected Changes
                            </label>
                            <label class="checkbox-label">
                                <input type="checkbox" id="settingNotifyError" <?= !empty($settings['notify_on_error']) ? 'checked' : '' ?>>
                                Notify on Check Errors (4xx/5xx/Timeouts)
                            </label>
                        </div>
                    </div>

                    <!-- Full Single-File Backup & Restore -->
                    <div style="background: rgba(15, 23, 42, 0.6); padding: 1.25rem; border-radius: var(--radius-md); border: 1px solid var(--border-color); margin-bottom: 1.5rem;">
                        <h4 style="font-size: 0.95rem; margin-bottom: 0.5rem; color: var(--success);">💾 Single-File Full Backup & Restore</h4>
                        <p style="font-size: 0.85rem; color: var(--text-secondary); margin-bottom: 1rem;">
                            Export everything (all target monitors, settings, notification tokens, stats, and text history snapshot logs) into a single downloadable JSON backup file, or restore your entire configuration in one click.
                        </p>
                        <div style="display: flex; gap: 1rem; flex-wrap: wrap; align-items: center;">
                            <a href="api.php?action=export_backup" class="btn btn-secondary btn-sm" download>⬇️ Download Full Backup (.json)</a>
                            
                            <label class="btn btn-primary btn-sm" style="margin: 0; cursor: pointer;">
                                ⬆️ Restore Backup File
                                <input type="file" id="restoreFileInput" accept=".json" style="display: none;">
                            </label>
                        </div>
                    </div>

                    <!-- Bluehost cPanel Cron Setup Info -->
                    <div style="background: rgba(15, 23, 42, 0.6); padding: 1.25rem; border-radius: var(--radius-md); border: 1px solid var(--border-color); margin-bottom: 1.5rem;">
                        <h4 style="font-size: 0.95rem; margin-bottom: 0.5rem; color: var(--info);">🕒 Bluehost cPanel Cron Setup</h4>
                        <p style="font-size: 0.85rem; color: var(--text-secondary); margin-bottom: 0.5rem;">
                            To run automated background checks, add this command to <strong>cPanel &gt; Cron Jobs</strong> (e.g. every 15 minutes):
                        </p>
                        <pre style="background: #000; padding: 0.75rem; border-radius: 4px; font-family: var(--font-mono); font-size: 0.8rem; overflow-x: auto; color: #a5f3fc;">/usr/local/bin/php <?= htmlspecialchars(CM_ROOT) ?>/public/cron.php</pre>
                        <p style="font-size: 0.85rem; color: var(--text-secondary); margin-top: 0.5rem;">
                            Or trigger via secure Webhook URL:
                        </p>
                        <pre style="background: #000; padding: 0.75rem; border-radius: 4px; font-family: var(--font-mono); font-size: 0.8rem; overflow-x: auto; color: #a5f3fc;"><?= (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . dirname($_SERVER['REQUEST_URI'] ?? '') ?>/cron.php?token=<?= htmlspecialchars(cm_env('CRON_TOKEN', 'cron_secret_token_123')) ?></pre>
                    </div>

                    <button type="submit" class="btn btn-primary">Save All Settings</button>
                </form>
            </div>
        </div>
    </div>

    <!-- Add/Edit Target Modal -->
    <div class="modal-backdrop" id="monitorModal">
        <div class="modal-dialog">
            <div class="modal-header">
                <div class="modal-title" id="monitorModalTitle">Add New Target Monitor</div>
                <button type="button" class="modal-close" onclick="closeModal('monitorModal')">&times;</button>
            </div>
            <form id="monitorForm">
                <input type="hidden" id="monitorId" value="">
                <div class="modal-body">
                    <div class="form-grid">
                        <div class="form-group">
                            <label class="form-label" for="monitorName">Target Name *</label>
                            <input type="text" id="monitorName" class="form-control" placeholder="e.g. Competitor Pricing / API Health" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="monitorUrl">Target URL *</label>
                            <input type="url" id="monitorUrl" class="form-control" placeholder="https://example.com/page or /api" required>
                        </div>
                    </div>

                    <div class="form-grid">
                        <div class="form-group">
                            <label class="form-label" for="monitorType">Extraction Mode</label>
                            <select id="monitorType" class="form-control">
                                <option value="html_full">Full Page HTML / Text</option>
                                <option value="xpath">XPath Expression (DOM Node)</option>
                                <option value="css">CSS Selector (.price, #content)</option>
                                <option value="json">JSON API Dot-Path (data.items[0].price)</option>
                                <option value="regex">Regex Pattern (capture groups)</option>
                                <option value="headers">HTTP Response Headers</option>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="monitorSelector">Selector / Path / Pattern</label>
                            <input type="text" id="monitorSelector" class="form-control" placeholder="e.g. //div[@class='price'] or data.status">
                        </div>
                    </div>

                    <div class="form-grid">
                        <div class="form-group">
                            <label class="form-label" for="monitorTemplate">Browser Request Profile</label>
                            <select id="monitorTemplate" class="form-control">
                                <?php foreach ($builtinTemplates as $key => $tmpl): ?>
                                    <option value="<?= htmlspecialchars($key) ?>"><?= htmlspecialchars($tmpl['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="monitorInterval">Standard Interval (Minutes)</label>
                            <input type="number" id="monitorInterval" class="form-control" min="1" value="15">
                        </div>
                    </div>

                    <!-- Dynamic Peak/Off-Peak Schedule Options -->
                    <div style="background: rgba(15, 23, 42, 0.7); padding: 1rem; border-radius: var(--radius-sm); border: 1px solid var(--border-color); margin-bottom: 1.25rem;">
                        <label class="checkbox-label" style="min-height: auto; margin-bottom: 0.75rem;">
                            <input type="checkbox" id="monitorPeakScheduleEnabled">
                            <strong>⚡ Enable Peak/Off-Peak Request Frequency</strong>
                        </label>
                        <p style="font-size: 0.75rem; color: var(--text-muted); margin-bottom: 0.75rem;">
                            Send more frequent checks during high-priority hours (e.g. market/business hours) and fewer checks during off-hours.
                        </p>
                        <div id="peakScheduleFields" style="display: none;">
                            <div class="form-grid">
                                <div class="form-group">
                                    <label class="form-label" for="monitorPeakStart">Peak Start Hour (24h)</label>
                                    <select id="monitorPeakStart" class="form-control">
                                        <?php for ($h = 0; $h < 24; $h++): ?>
                                            <option value="<?= $h ?>" <?= $h === 9 ? 'selected' : '' ?>><?= sprintf('%02d:00', $h) ?></option>
                                        <?php endfor; ?>
                                    </select>
                                </div>
                                <div class="form-group">
                                    <label class="form-label" for="monitorPeakEnd">Peak End Hour (24h)</label>
                                    <select id="monitorPeakEnd" class="form-control">
                                        <?php for ($h = 0; $h < 24; $h++): ?>
                                            <option value="<?= $h ?>" <?= $h === 18 ? 'selected' : '' ?>><?= sprintf('%02d:00', $h) ?></option>
                                        <?php endfor; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="form-grid">
                                <div class="form-group">
                                    <label class="form-label" for="monitorPeakInterval">Peak Interval (More Frequent)</label>
                                    <input type="number" id="monitorPeakInterval" class="form-control" min="1" value="5" placeholder="e.g. 5 mins">
                                </div>
                                <div class="form-group">
                                    <label class="form-label" for="monitorOffpeakInterval">Off-Peak Interval (Less Frequent)</label>
                                    <input type="number" id="monitorOffpeakInterval" class="form-control" min="1" value="60" placeholder="e.g. 60 mins">
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="monitorHeaders">Custom Request Headers (One per line)</label>
                        <textarea id="monitorHeaders" class="form-control" placeholder="Authorization: Bearer mytoken&#10;X-Custom-Header: value"></textarea>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="monitorCookies">Cookies</label>
                        <input type="text" id="monitorCookies" class="form-control" placeholder="session_id=xyz; auth=1">
                    </div>

                    <div class="form-grid">
                        <label class="checkbox-label">
                            <input type="checkbox" id="monitorStripTags" checked>
                            Strip HTML tags (extract clean text)
                        </label>
                        <label class="checkbox-label">
                            <input type="checkbox" id="monitorSimulateDelay">
                            Simulate human random delay
                        </label>
                        <label class="checkbox-label">
                            <input type="checkbox" id="monitorNotifyChange" checked>
                            Notify on Change
                        </label>
                        <label class="checkbox-label">
                            <input type="checkbox" id="monitorNotifyError" checked>
                            Notify on Error
                        </label>
                    </div>

                    <!-- Live Test Preview Output Box -->
                    <div id="previewOutput" style="display: none; margin-top: 1.5rem; background: #000; padding: 1rem; border-radius: var(--radius-md); border: 1px solid var(--border-color);">
                        <div id="previewMeta" style="font-size: 0.8rem; margin-bottom: 0.5rem;"></div>
                        <div id="previewContent" style="font-family: var(--font-mono); font-size: 0.8rem; max-height: 180px; overflow-y: auto; color: #a5f3fc; white-space: pre-wrap;"></div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" id="testPreviewBtn">Test & Live Preview</button>
                    <button type="submit" class="btn btn-primary">Save Target</button>
                </div>
            </form>
        </div>
    </div>

    <!-- History & Snapshot Modal -->
    <div class="modal-backdrop" id="historyModal">
        <div class="modal-dialog" style="max-width: 900px;">
            <div class="modal-header">
                <div class="modal-title" id="historyModalTitle">History & Snapshots</div>
                <button type="button" class="modal-close" onclick="closeModal('historyModal')">&times;</button>
            </div>
            <div class="modal-body">
                <!-- Top Actions Bar -->
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.25rem; background: rgba(15, 23, 42, 0.6); padding: 0.85rem 1rem; border-radius: var(--radius-md); border: 1px solid var(--border-color); flex-wrap: wrap; gap: 0.75rem;">
                    <div>
                        <div id="historyLogMeta" style="font-size: 0.85rem; font-weight: 600; color: #fff;"></div>
                        <div style="font-size: 0.75rem; color: var(--text-muted);">Timestamped execution events & change history</div>
                    </div>
                    <a id="downloadHistoryBtn" href="#" class="btn btn-secondary btn-sm" download>
                        ⬇️ Download History Log (.txt)
                    </a>
                </div>

                <!-- Snapshot History Explorer -->
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem; flex-wrap: wrap; gap: 0.5rem;">
                    <h4 style="font-size: 0.95rem; color: var(--success); margin: 0; display: flex; align-items: center; gap: 0.4rem;">
                        <span>📸 Captured Snapshot View</span>
                    </h4>
                    <div style="display: flex; align-items: center; gap: 0.5rem;">
                        <label for="snapshotSelect" style="font-size: 0.8rem; color: var(--text-muted);">Version:</label>
                        <select id="snapshotSelect" class="form-control" style="min-height: 34px; padding: 0.25rem 0.65rem; font-size: 0.825rem; width: auto;">
                            <option value="">Latest Captured Snapshot</option>
                        </select>
                    </div>
                </div>
                <div class="diff-container" id="historySnapshot" style="max-height: 420px;"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('historyModal')">Close</button>
            </div>
        </div>
    </div>

    <script src="assets/js/app.js"></script>
</body>
</html>
