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
require_once __DIR__ . '/../includes/engine.php';

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
$trash = Storage::getTrash();
$settings = Storage::getSettings();
$stats = Storage::getStats();
$builtinTemplates = Fetcher::getAllTemplates();
$csrfToken = Auth::getCsrfToken();

// Compute active & health counts and groups
$activeMonitors = [];
$inactiveMonitors = [];
$changesToday = $stats['checks_by_date'][date('Y-m-d')]['changes'] ?? 0;
$groups = Storage::getGroups();

// Calculate ungrouped count
$ungroupedCount = 0;
foreach ($monitors as $id => $m) {
    $rawGrp = trim($m['group'] ?? '');
    if ($rawGrp === '' || strcasecmp($rawGrp, 'ungrouped') === 0) {
        $ungroupedCount++;
    }
    if (($m['status'] ?? 'active') === 'active') {
        $activeMonitors[$id] = $m;
    } else {
        $inactiveMonitors[$id] = $m;
    }
}
$activeCount = count($activeMonitors);
$inactiveCount = count($inactiveMonitors);
$trashCount = count($trash);
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
                <!-- Theme Switcher Selector -->
                <div style="display: flex; align-items: center; gap: 0.35rem; background: var(--bg-subtle); padding: 0.2rem 0.5rem; border-radius: var(--radius-sm); border: 1px solid var(--border-color);">
                    <label for="themeSelect" style="font-size: 0.8rem; color: var(--text-muted); cursor: pointer;" title="Change UI Theme">🎨</label>
                    <select id="themeSelect" class="form-control" style="min-height: 30px; padding: 0.15rem 0.4rem; font-size: 0.8rem; width: auto; background: transparent; border: none; color: var(--text-primary); cursor: pointer;" aria-label="Select UI Theme">
                        <option value="dark">🌑 Dark Night (Default)</option>
                        <option value="light">☀️ Clean Light</option>
                        <option value="red">🔴 Crimson Red</option>
                        <option value="blue">🔵 Cobalt Blue</option>
                        <option value="emerald">🟢 Emerald Forest</option>
                        <option value="purple">🟣 Sunset Purple</option>
                        <option value="multicolor">🌈 Cyberpunk Neon</option>
                    </select>
                </div>

                <button class="btn btn-secondary btn-sm" id="runAllBtn">▶ Run All Checks</button>
                <button class="btn btn-secondary btn-sm" id="bulkAddBtn">➕ Bulk Add</button>
                <button class="btn btn-primary btn-sm" id="addMonitorBtn">+ Add Target</button>
                <a href="?route=logout" class="btn btn-secondary btn-sm" title="Logout">Logout</a>
            </div>
        </header>

        <!-- Navigation Tabs -->
        <nav class="tabs-nav">
            <button class="tab-btn active" data-tab="tab-monitors">🎯 Monitors (<?= count($monitors) ?>)</button>
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
            <div class="stat-card stat-warning stat-card-clickable" id="statCardChanges" title="Click to view all detected changes" style="cursor: pointer;">
                <div class="stat-label">Changes Detected 🔍</div>
                <div class="stat-value" id="statValChanges"><?= number_format($stats['total_changes'] ?? 0) ?></div>
                <div class="stat-desc"><?= $changesToday ?> changes detected today (click to inspect)</div>
            </div>
            <div class="stat-card stat-danger stat-card-clickable" id="statCardErrors" title="Click to view error log" style="cursor: pointer;">
                <div class="stat-label">Errors Encountered ⚠️</div>
                <div class="stat-value" id="statValErrors"><?= number_format($stats['total_errors'] ?? 0) ?></div>
                <div class="stat-desc">4xx / 5xx / Network timeouts (click to view)</div>
            </div>
        </div>

        <!-- Floating Bulk Actions Toolbar -->
        <div class="bulk-action-bar" id="bulkActionBar">
            <div class="bulk-selected-badge">
                <span id="bulkSelectedCount">0</span> selected
            </div>
            <div class="bulk-btn-group">
                <button type="button" class="btn btn-primary btn-sm" id="bulkEditBtn" title="Bulk edit settings for selected">✏️ Bulk Edit</button>
                <button type="button" class="btn btn-success btn-sm" id="bulkActivateBtn" title="Set selected to Active">▶️ Activate</button>
                <button type="button" class="btn btn-secondary btn-sm" id="bulkPauseBtn" title="Set selected to Paused">⏸️ Pause</button>
                <button type="button" class="btn btn-secondary btn-sm" id="bulkGroupBtn" title="Assign selected to Group">📁 Set Group</button>
                <button type="button" class="btn btn-secondary btn-sm" id="bulkRunBtn" title="Execute checks for selected">⚡ Run Now</button>
                <button type="button" class="btn btn-danger btn-sm" id="bulkDeleteBtn" title="Move selected to Trash">🗑️ Delete</button>
                <button type="button" class="btn btn-secondary btn-sm" id="bulkDeselectBtn" style="padding: 0.35rem 0.6rem;" title="Clear selection">&times; Clear</button>
            </div>
        </div>

        <!-- TAB 1: Monitors Panel (with Active / Inactive / Trash Sub-Tabs & Group Filter) -->
        <div class="tab-pane" id="tab-monitors">
            <div class="panel">
                <div class="panel-header" style="flex-wrap: wrap; gap: 0.75rem;">
                    <div>
                        <div class="panel-title">Monitored Targets</div>
                        <span style="font-size: 0.85rem; color: var(--text-muted);">Auto-refreshed on manual or cron check</span>
                    </div>

                    <!-- Sub-tabs for Active vs Inactive vs Trash -->
                    <div class="subtabs-nav">
                        <button type="button" class="subtab-btn active" data-subtab="subtab-active">
                            🎯 Active (<?= $activeCount ?>)
                        </button>
                        <button type="button" class="subtab-btn" data-subtab="subtab-inactive">
                            ⏸️ Inactive (<?= $inactiveCount ?>)
                        </button>
                        <button type="button" class="subtab-btn" data-subtab="subtab-trash">
                            🗑️ Trash / Deleted (<?= $trashCount ?>)
                        </button>
                    </div>
                </div>

                <!-- Group / Category Filter Pills -->
                <div class="group-filter-bar" id="groupFilterBar">
                    <span style="font-size: 0.8rem; font-weight: 600; color: var(--text-muted); display: inline-flex; align-items: center; gap: 0.25rem;">
                        📁 Group:
                    </span>
                    <button type="button" class="group-pill active" data-group="all">
                        All (<span class="group-count" data-count-group="all"><?= $activeCount ?></span>)
                    </button>
                    <button type="button" class="group-pill" data-group="ungrouped">
                        Ungrouped (<span class="group-count" data-count-group="ungrouped">0</span>)
                    </button>
                    <?php foreach ($groups as $grpName => $grpCount): ?>
                        <?php if (strcasecmp($grpName, 'ungrouped') === 0) continue; ?>
                        <button type="button" class="group-pill" data-group="<?= htmlspecialchars($grpName) ?>">
                            <?= htmlspecialchars($grpName) ?> (<span class="group-count" data-count-group="<?= htmlspecialchars($grpName) ?>">0</span>)
                        </button>
                    <?php endforeach; ?>
                </div>

                <!-- Sub-Tab Pane: Active Monitors -->
                <div class="subtab-pane" id="subtab-active">
                    <div class="table-responsive">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th class="th-checkbox">
                                        <input type="checkbox" class="custom-checkbox select-all-checkbox" data-target="active" title="Select all active targets">
                                    </th>
                                    <th>Status</th>
                                    <th>Target / Name</th>
                                    <th>Group</th>
                                    <th>Type & Selector</th>
                                    <th>Profile</th>
                                    <th>Last Check</th>
                                    <th>Next Check</th>
                                    <th>Changes</th>
                                    <th style="text-align: right;">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($activeMonitors)): ?>
                                    <tr class="empty-state-row">
                                        <td colspan="10" style="text-align: center; color: var(--text-muted); padding: 3rem 1rem;">
                                            No active targets. Click <strong>"+ Add Target"</strong> or <strong>"➕ Bulk Add"</strong> above or resume a paused monitor from the Inactive sub-tab.
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($activeMonitors as $id => $m): ?>
                                        <?php
                                        $intervalMins = Engine::getActiveIntervalMins($m);
                                        $lastCheckTs = !empty($m['last_check_at']) ? strtotime($m['last_check_at']) : 0;
                                        $nextCheckTs = $lastCheckTs > 0 ? ($lastCheckTs + ($intervalMins * 60)) : time();
                                        $isOverdue = time() >= $nextCheckTs;
                                        $rawGrp = trim($m['group'] ?? '');
                                        $grp = ($rawGrp === '' || strcasecmp($rawGrp, 'ungrouped') === 0) ? 'Ungrouped' : $rawGrp;
                                        ?>
                                        <tr data-id="<?= $id ?>" data-group="<?= htmlspecialchars($grp) ?>" class="monitor-row">
                                            <td class="td-checkbox">
                                                <input type="checkbox" class="custom-checkbox monitor-checkbox" value="<?= $id ?>" data-status="active" aria-label="Select monitor <?= htmlspecialchars($m['name']) ?>">
                                            </td>
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
                                                <span class="badge badge-group" title="Category Group">📁 <?= htmlspecialchars($grp) ?></span>
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
                                                <div class="next-check-timer" data-next-timestamp="<?= $nextCheckTs ?>" data-status="active" style="font-family: var(--font-mono); font-size: 0.85rem; color: var(--info); font-weight: 600;">
                                                    <?= $isOverdue ? '⚡ Due Now' : '⏳ in ' . round(($nextCheckTs - time()) / 60) . 'm' ?>
                                                </div>
                                                <div style="font-size: 0.725rem; color: var(--text-muted);">
                                                    Every <?= $intervalMins ?>m<?= !empty($m['peak_schedule_enabled']) ? ' (dynamic)' : '' ?>
                                                </div>
                                            </td>
                                            <td>
                                                <span onclick="openChangesExplorer('<?= $id ?>')" style="cursor: pointer;" title="Click to view detected changes for this target">
                                                    <strong class="badge <?= ((int)($m['change_count'] ?? 0) > 0) ? 'badge-changed' : '' ?>"><?= (int)($m['change_count'] ?? 0) ?></strong>
                                                </span>
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

                <!-- Sub-Tab Pane: Inactive / Paused Monitors -->
                <div class="subtab-pane" id="subtab-inactive" style="display: none;">
                    <div class="table-responsive">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th class="th-checkbox">
                                        <input type="checkbox" class="custom-checkbox select-all-checkbox" data-target="inactive" title="Select all inactive targets">
                                    </th>
                                    <th>Status</th>
                                    <th>Target / Name</th>
                                    <th>Group</th>
                                    <th>Type & Selector</th>
                                    <th>Profile</th>
                                    <th>Last Check</th>
                                    <th>Next Check</th>
                                    <th>Changes</th>
                                    <th style="text-align: right;">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($inactiveMonitors)): ?>
                                    <tr class="empty-state-row">
                                        <td colspan="10" style="text-align: center; color: var(--text-muted); padding: 3rem 1rem;">
                                            No inactive or paused monitors.
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($inactiveMonitors as $id => $m): ?>
                                        <?php
                                        $rawGrp = trim($m['group'] ?? '');
                                        $grp = ($rawGrp === '' || strcasecmp($rawGrp, 'ungrouped') === 0) ? 'Ungrouped' : $rawGrp;
                                        ?>
                                        <tr data-id="<?= $id ?>" data-group="<?= htmlspecialchars($grp) ?>" class="monitor-row">
                                            <td class="td-checkbox">
                                                <input type="checkbox" class="custom-checkbox monitor-checkbox" value="<?= $id ?>" data-status="paused" aria-label="Select monitor <?= htmlspecialchars($m['name']) ?>">
                                            </td>
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
                                                <span class="badge badge-group" title="Category Group">📁 <?= htmlspecialchars($grp) ?></span>
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
                                                <div style="font-size: 0.85rem; color: var(--text-muted);">
                                                    Paused
                                                </div>
                                                <div style="font-size: 0.725rem; color: var(--text-muted);">
                                                    Interval: <?= (int)($m['interval_mins'] ?? 15) ?>m
                                                </div>
                                            </td>
                                            <td>
                                                <span onclick="openChangesExplorer('<?= $id ?>')" style="cursor: pointer;" title="Click to view detected changes for this target">
                                                    <strong class="badge <?= ((int)($m['change_count'] ?? 0) > 0) ? 'badge-changed' : '' ?>"><?= (int)($m['change_count'] ?? 0) ?></strong>
                                                </span>
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

                <!-- Sub-Tab Pane: Trash / Deleted Monitors -->
                <div class="subtab-pane" id="subtab-trash" style="display: none;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; flex-wrap: wrap; gap: 0.75rem; padding: 0.75rem 1rem; background: var(--bg-subtle); border-radius: var(--radius-sm); border: 1px solid var(--border-color);">
                        <div>
                            <strong style="color: var(--danger);">🗑️ Deleted Targets Bin</strong>
                            <span style="font-size: 0.825rem; color: var(--text-muted); margin-left: 0.5rem;">Deleted monitors are kept here. Restore anytime or permanently purge.</span>
                        </div>
                        <div style="display: flex; gap: 0.5rem;">
                            <?php if (!empty($trash)): ?>
                                <button type="button" class="btn btn-secondary btn-sm" id="bulkRestoreTrashBtn">♻️ Restore Selected</button>
                                <button type="button" class="btn btn-danger btn-sm" id="emptyTrashBtn">🔥 Empty Trash</button>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="table-responsive">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th class="th-checkbox">
                                        <input type="checkbox" class="custom-checkbox select-all-trash-checkbox" title="Select all trash items">
                                    </th>
                                    <th>Deleted At</th>
                                    <th>Target / Name</th>
                                    <th>Group</th>
                                    <th>Extraction Type</th>
                                    <th>Browser Profile</th>
                                    <th style="text-align: right;">Actions</th>
                                </tr>
                            </thead>
                            <tbody id="trashTableBody">
                                <?php if (empty($trash)): ?>
                                    <tr class="empty-state-row">
                                        <td colspan="7" style="text-align: center; color: var(--text-muted); padding: 3rem 1rem;">
                                            Trash is empty. No deleted monitors found.
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($trash as $id => $m): ?>
                                        <?php
                                        $rawGrp = trim($m['group'] ?? '');
                                        $grp = ($rawGrp === '' || strcasecmp($rawGrp, 'ungrouped') === 0) ? 'Ungrouped' : $rawGrp;
                                        ?>
                                        <tr data-id="<?= $id ?>" class="trash-row">
                                            <td class="td-checkbox">
                                                <input type="checkbox" class="custom-checkbox trash-checkbox" value="<?= $id ?>" aria-label="Select deleted target <?= htmlspecialchars($m['name'] ?? '') ?>">
                                            </td>
                                            <td>
                                                <div style="font-size: 0.85rem; color: var(--danger); font-weight: 600;">
                                                    <?= !empty($m['deleted_at']) ? date('M d, Y H:i', strtotime($m['deleted_at'])) : '-' ?>
                                                </div>
                                            </td>
                                            <td>
                                                <strong><?= htmlspecialchars($m['name'] ?? 'Untitled') ?></strong>
                                                <div style="font-size: 0.775rem; color: var(--text-muted); word-break: break-all;">
                                                    <a href="<?= htmlspecialchars($m['url'] ?? '') ?>" target="_blank" rel="noopener noreferrer"><?= htmlspecialchars($m['url'] ?? '') ?></a>
                                                </div>
                                            </td>
                                            <td>
                                                <span class="badge badge-group">📁 <?= htmlspecialchars($grp) ?></span>
                                            </td>
                                            <td>
                                                <span class="badge badge-type"><?= htmlspecialchars($m['type'] ?? 'html_full') ?></span>
                                            </td>
                                            <td>
                                                <span style="font-size: 0.8rem; color: var(--text-secondary);">
                                                    <?= htmlspecialchars($builtinTemplates[$m['browser_template'] ?? 'chrome_mac']['name'] ?? ($m['browser_template'] ?? 'Chrome Mac')) ?>
                                                </span>
                                            </td>
                                            <td style="text-align: right; white-space: nowrap;">
                                                <button class="btn btn-primary btn-sm" onclick="restoreMonitor('<?= $id ?>', '<?= htmlspecialchars(addslashes($m['name'] ?? '')) ?>')" title="Restore to Active Monitors">♻️ Restore</button>
                                                <button class="btn btn-danger btn-sm" onclick="purgeDeletedMonitor('<?= $id ?>', '<?= htmlspecialchars(addslashes($m['name'] ?? '')) ?>')" title="Delete Forever">🗑️ Purge</button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
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
                                <th style="text-align: right;">Day-Wise Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $checksByDate = $stats['checks_by_date'] ?? [];
                            krsort($checksByDate);
                            if (empty($checksByDate)):
                            ?>
                                <tr>
                                    <td colspan="6" style="text-align: center; color: var(--text-muted); padding: 2rem;">No analytics logged yet. Run some checks to populate data.</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($checksByDate as $date => $row): ?>
                                    <?php
                                    $rate = $row['checks'] > 0 ? round((($row['checks'] - $row['errors']) / $row['checks']) * 100, 1) : 100;
                                    $chgCount = (int)($row['changes'] ?? 0);
                                    $errCount = (int)($row['errors'] ?? 0);
                                    ?>
                                    <tr>
                                        <td><strong><?= htmlspecialchars($date) ?></strong></td>
                                        <td><?= number_format($row['checks']) ?></td>
                                        <td><span class="badge badge-changed"><?= $chgCount ?></span></td>
                                        <td><?= $errCount > 0 ? "<span class='badge badge-error'>{$errCount}</span>" : '0' ?></td>
                                        <td><span class="badge badge-active"><?= $rate ?>%</span></td>
                                        <td style="text-align: right; white-space: nowrap;">
                                            <button type="button" class="btn btn-secondary btn-sm" onclick="openChangesForDate('<?= htmlspecialchars($date) ?>')" title="View detected changes for <?= htmlspecialchars($date) ?>" style="padding: 0.25rem 0.6rem; font-size: 0.775rem;">
                                                🔍 Changes (<?= $chgCount ?>)
                                            </button>
                                            <button type="button" class="btn btn-secondary btn-sm" onclick="openLogsForDate('<?= htmlspecialchars($date) ?>')" title="View logs & errors for <?= htmlspecialchars($date) ?>" style="padding: 0.25rem 0.6rem; font-size: 0.775rem; margin-left: 4px;">
                                                ⚠️ Logs (<?= $errCount ?>)
                                            </button>
                                        </td>
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
                    <div style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
                        <button class="btn btn-secondary btn-sm" id="refreshLogsBtn">🔄 Refresh</button>
                        <button class="btn btn-secondary btn-sm" id="deleteSelectedLogsBtn" style="display: none;">🗑️ Delete Selected (<span id="selectedLogsCount">0</span>)</button>
                        <button class="btn btn-danger btn-sm" id="clearLogsBtn">🔥 Clear All Logs</button>
                    </div>
                </div>

                <!-- Search & Filters Bar -->
                <div style="display: flex; gap: 0.75rem; flex-wrap: wrap; margin-bottom: 1.25rem; background: var(--bg-subtle); padding: 1rem; border-radius: var(--radius-md); border: 1px solid var(--border-color);">
                    <div style="flex: 1; min-width: 200px;">
                        <input type="text" id="logSearchInput" class="form-control" placeholder="🔍 Search message, error or monitor ID..." style="min-height: 38px;">
                    </div>
                    <div style="width: 150px;">
                        <input type="date" id="logDateInput" class="form-control" title="Filter logs by date" style="min-height: 38px;">
                    </div>
                    <div style="width: 140px;">
                        <select id="logLevelSelect" class="form-control" style="min-height: 38px;">
                            <option value="">All Levels</option>
                            <option value="ERROR">ERROR</option>
                            <option value="WARNING">WARNING</option>
                            <option value="INFO">INFO</option>
                        </select>
                    </div>
                    <div style="width: 140px;">
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
                                <th style="width: 40px;" class="th-checkbox">
                                    <input type="checkbox" id="selectAllLogsCheckbox" class="custom-checkbox" title="Select all logs">
                                </th>
                                <th style="width: 140px;">Timestamp</th>
                                <th style="width: 90px;">Level</th>
                                <th style="width: 110px;">Category</th>
                                <th>Message / Context</th>
                                <th style="width: 90px;">IP / Source</th>
                                <th style="width: 80px; text-align: right;">Action</th>
                            </tr>
                        </thead>
                        <tbody id="logsTableBody">
                            <tr>
                                <td colspan="7" style="text-align: center; color: var(--text-muted); padding: 2rem;">Loading logs...</td>
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
                        <div style="background: var(--bg-subtle); padding: 1.25rem; border-radius: var(--radius-md); border: 1px solid var(--border-color);">
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
                                <label class="form-label" for="settingBigChangeThreshold">Large Change Attachment Threshold (Lines)</label>
                                <input type="number" id="settingBigChangeThreshold" class="form-control" min="5" max="500" value="<?= (int)($settings['diff_big_change_threshold_lines'] ?? 30) ?>">
                                <p class="form-help">If changed lines exceed this threshold, separate color-highlighted HTML files are attached instead of inline text.</p>
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

                    <div style="margin-bottom: 2rem;">
                        <button type="submit" class="btn btn-primary">Save All Settings</button>
                    </div>
                </form>

                <!-- Full Single-File Backup & Restore -->
                <div style="background: var(--bg-subtle); padding: 1.25rem; border-radius: var(--radius-md); border: 1px solid var(--border-color); margin-bottom: 1.5rem;">
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
                <div style="background: var(--bg-subtle); padding: 1.25rem; border-radius: var(--radius-md); border: 1px solid var(--border-color); margin-bottom: 1.5rem;">
                    <h4 style="font-size: 0.95rem; margin-bottom: 0.5rem; color: var(--info);">🕒 Bluehost cPanel Cron Setup</h4>
                    <p style="font-size: 0.85rem; color: var(--text-secondary); margin-bottom: 0.5rem;">
                        To run automated background checks, add this command to <strong>cPanel &gt; Cron Jobs</strong> (e.g. every 15 minutes):
                    </p>
                    <pre style="background: var(--bg-code); padding: 0.75rem; border-radius: 4px; font-family: var(--font-mono); font-size: 0.8rem; overflow-x: auto; color: var(--info); border: 1px solid var(--border-color);">/usr/local/bin/php <?= htmlspecialchars(CM_ROOT) ?>/public/cron.php</pre>
                    <p style="font-size: 0.85rem; color: var(--text-secondary); margin-top: 0.5rem;">
                        Or trigger via secure Webhook URL:
                    </p>
                    <pre style="background: var(--bg-code); padding: 0.75rem; border-radius: 4px; font-family: var(--font-mono); font-size: 0.8rem; overflow-x: auto; color: var(--info); border: 1px solid var(--border-color);"><?= (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . dirname($_SERVER['REQUEST_URI'] ?? '') ?>/cron.php?token=<?= htmlspecialchars(cm_env('CRON_TOKEN', 'cron_secret_token_123')) ?></pre>
                </div>
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
                            <label class="form-label" for="monitorGroup">Group / Category</label>
                            <input type="text" id="monitorGroup" class="form-control" list="existingGroupsList" placeholder="e.g. E-Commerce, Competitors, APIs" value="General">
                            <datalist id="existingGroupsList">
                                <?php foreach (array_keys($groups) as $grpName): ?>
                                    <option value="<?= htmlspecialchars($grpName) ?>">
                                <?php endforeach; ?>
                            </datalist>
                        </div>
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
                    </div>

                    <div class="form-grid">
                        <div class="form-group">
                            <label class="form-label" for="monitorSelector">Selector / Path / Pattern</label>
                            <input type="text" id="monitorSelector" class="form-control" placeholder="e.g. //div[@class='price'] or data.status">
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="monitorTemplate">Browser Request Profile</label>
                            <select id="monitorTemplate" class="form-control">
                                <?php foreach ($builtinTemplates as $key => $tmpl): ?>
                                    <option value="<?= htmlspecialchars($key) ?>"><?= htmlspecialchars($tmpl['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="form-grid">
                        <div class="form-group">
                            <label class="form-label" for="monitorInterval">Standard Interval (Minutes)</label>
                            <input type="number" id="monitorInterval" class="form-control" min="1" value="15">
                        </div>
                    </div>

                    <!-- Dynamic Peak/Off-Peak Schedule Options -->
                    <div style="background: var(--bg-subtle); padding: 1rem; border-radius: var(--radius-sm); border: 1px solid var(--border-color); margin-bottom: 1.25rem;">
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
                                    <label class="form-label" for="monitorPeakInterval">Peak Check Interval (Mins)</label>
                                    <input type="number" id="monitorPeakInterval" class="form-control" min="1" value="5" placeholder="e.g. 5">
                                </div>
                                <div class="form-group">
                                    <label class="form-label" for="monitorOffpeakInterval">Off-Peak Check Interval (Mins)</label>
                                    <input type="number" id="monitorOffpeakInterval" class="form-control" min="1" value="60" placeholder="e.g. 60">
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
                            Strip HTML tags before comparing
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
                    <div id="previewOutput" style="display: none; margin-top: 1.5rem; background: var(--bg-code); padding: 1rem; border-radius: var(--radius-md); border: 1px solid var(--border-color);">
                        <div id="previewMeta" style="font-size: 0.8rem; margin-bottom: 0.5rem;"></div>
                        <div id="previewContent" style="font-family: var(--font-mono); font-size: 0.8rem; max-height: 180px; overflow-y: auto; color: var(--info); white-space: pre-wrap;"></div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" id="testPreviewBtn">Test & Live Preview</button>
                    <button type="submit" class="btn btn-primary">Save Target</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Bulk Add Targets Modal -->
    <div class="modal-backdrop" id="bulkAddModal">
        <div class="modal-dialog" style="max-width: 720px;">
            <div class="modal-header">
                <div class="modal-title">➕ Bulk Add New Targets</div>
                <button type="button" class="modal-close" onclick="closeModal('bulkAddModal')">&times;</button>
            </div>
            <form id="bulkAddForm">
                <div class="modal-body">
                    <div class="format-helper-box">
                        <strong>💡 Format Options (One target per line):</strong><br>
                        &bull; URL only: <code>https://example.com/product-page</code><br>
                        &bull; Name and URL: <code>Competitor Pricing, https://example.com/pricing</code><br>
                        &bull; Pipe delimiter: <code>Login Page | https://example.com/login</code>
                    </div>

                    <div class="form-group">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.35rem;">
                            <label class="form-label" for="bulkMonitorsText" style="margin-bottom: 0;">Target List *</label>
                            <span id="bulkLineCount" style="font-size: 0.775rem; color: var(--info); font-weight: 600;">0 targets detected</span>
                        </div>
                        <textarea id="bulkMonitorsText" class="form-control" rows="8" placeholder="https://example.com/page1&#10;My API, https://api.example.com/v1/health&#10;Store Front | https://store.example.com" style="font-family: var(--font-mono); font-size: 0.85rem;" required></textarea>
                    </div>

                    <h4 style="font-size: 0.95rem; color: var(--text-primary); margin: 1.25rem 0 0.75rem 0; padding-bottom: 0.35rem; border-bottom: 1px solid var(--border-color);">
                        ⚙️ Default Configuration for Added Targets
                    </h4>

                    <div class="form-grid">
                        <div class="form-group">
                            <label class="form-label" for="bulkDefaultGroup">Group / Category</label>
                            <input type="text" id="bulkDefaultGroup" class="form-control" list="existingGroupsList" placeholder="e.g. E-Commerce, APIs" value="General">
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="bulkDefaultType">Extraction Mode</label>
                            <select id="bulkDefaultType" class="form-control">
                                <option value="html_full">Full Page HTML / Text</option>
                                <option value="xpath">XPath Expression (DOM Node)</option>
                                <option value="css">CSS Selector (.price, #content)</option>
                                <option value="json">JSON API Dot-Path (data.items[0])</option>
                                <option value="regex">Regex Pattern</option>
                                <option value="headers">HTTP Response Headers</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-grid">
                        <div class="form-group">
                            <label class="form-label" for="bulkDefaultSelector">Selector / Path (Optional)</label>
                            <input type="text" id="bulkDefaultSelector" class="form-control" placeholder="Applied to all targets (optional)">
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="bulkDefaultTemplate">Browser Request Profile</label>
                            <select id="bulkDefaultTemplate" class="form-control">
                                <?php foreach ($builtinTemplates as $key => $tmpl): ?>
                                    <option value="<?= htmlspecialchars($key) ?>"><?= htmlspecialchars($tmpl['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="form-grid">
                        <div class="form-group">
                            <label class="form-label" for="bulkDefaultInterval">Check Interval (Minutes)</label>
                            <input type="number" id="bulkDefaultInterval" class="form-control" min="1" value="<?= (int)($settings['default_interval_mins'] ?? 15) ?>">
                        </div>
                        <div class="form-group">
                            <label class="form-label" for="bulkDefaultStatus">Initial Status</label>
                            <select id="bulkDefaultStatus" class="form-control">
                                <option value="active">Active (Monitored)</option>
                                <option value="paused">Paused (Inactive)</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-grid">
                        <div class="form-group">
                            <label class="form-label" for="bulkDefaultTimeout">Timeout (Seconds)</label>
                            <input type="number" id="bulkDefaultTimeout" class="form-control" min="5" max="60" value="25">
                        </div>
                    </div>

                    <div class="form-grid" style="margin-top: 0.5rem;">
                        <label class="checkbox-label">
                            <input type="checkbox" id="bulkStripTags" checked>
                            Strip HTML tags (clean text)
                        </label>
                        <label class="checkbox-label">
                            <input type="checkbox" id="bulkRunBaseline" checked>
                            <strong>⚡ Run initial baseline check immediately</strong>
                        </label>
                        <label class="checkbox-label">
                            <input type="checkbox" id="bulkNotifyChange" checked>
                            Notify on Change
                        </label>
                        <label class="checkbox-label">
                            <input type="checkbox" id="bulkNotifyError" checked>
                            Notify on Error
                        </label>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('bulkAddModal')">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="bulkSubmitBtn">➕ Create Targets</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Bulk Move / Assign Group Modal -->
    <div class="modal-backdrop" id="bulkGroupModal">
        <div class="modal-dialog" style="max-width: 440px;">
            <div class="modal-header">
                <div class="modal-title">📁 Move to Group / Category</div>
                <button type="button" class="modal-close" onclick="closeModal('bulkGroupModal')">&times;</button>
            </div>
            <form id="bulkGroupForm">
                <div class="modal-body">
                    <p style="font-size: 0.85rem; color: var(--text-secondary); margin-bottom: 1rem;">
                        Assign <strong id="bulkGroupTargetCount">0</strong> selected monitor(s) to a group:
                    </p>
                    <div class="form-group">
                        <label class="form-label" for="bulkGroupInput">Group Name</label>
                        <input type="text" id="bulkGroupInput" class="form-control" list="existingGroupsList" placeholder="Enter new group or pick existing..." required>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('bulkGroupModal')">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="bulkGroupSubmitBtn">📁 Assign Group</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Bulk Edit Targets Modal -->
    <div class="modal-backdrop" id="bulkEditModal">
        <div class="modal-dialog" style="max-width: 680px;">
            <div class="modal-header">
                <div class="modal-title">✏️ Bulk Edit Selected Targets</div>
                <button type="button" class="modal-close" onclick="closeModal('bulkEditModal')">&times;</button>
            </div>
            <form id="bulkEditForm">
                <div class="modal-body">
                    <div class="format-helper-box" style="margin-bottom: 1rem;">
                        💡 Check the box next to any setting you want to batch update across <strong id="bulkEditTargetCount">0</strong> selected monitor(s). Unchecked settings will remain untouched.
                    </div>

                    <!-- Field: Group -->
                    <div style="background: var(--bg-subtle); padding: 0.85rem; border-radius: var(--radius-sm); border: 1px solid var(--border-color); margin-bottom: 0.75rem;">
                        <label class="checkbox-label" style="font-weight: 600; color: var(--text-primary); margin-bottom: 0.5rem; min-height: auto;">
                            <input type="checkbox" id="bulkEditApplyGroup"> Update Group / Category
                        </label>
                        <div id="bulkEditGroupFields" style="display: none; margin-top: 0.5rem;">
                            <input type="text" id="bulkEditGroupVal" class="form-control" list="existingGroupsList" placeholder="Enter group name or 'Ungrouped'">
                        </div>
                    </div>

                    <!-- Field: Interval -->
                    <div style="background: var(--bg-subtle); padding: 0.85rem; border-radius: var(--radius-sm); border: 1px solid var(--border-color); margin-bottom: 0.75rem;">
                        <label class="checkbox-label" style="font-weight: 600; color: var(--text-primary); margin-bottom: 0.5rem; min-height: auto;">
                            <input type="checkbox" id="bulkEditApplyInterval"> Update Check Interval
                        </label>
                        <div id="bulkEditIntervalFields" style="display: none; margin-top: 0.5rem;">
                            <input type="number" id="bulkEditIntervalVal" class="form-control" min="1" value="15" placeholder="Interval in minutes">
                        </div>
                    </div>

                    <!-- Field: Browser Profile -->
                    <div style="background: var(--bg-subtle); padding: 0.85rem; border-radius: var(--radius-sm); border: 1px solid var(--border-color); margin-bottom: 0.75rem;">
                        <label class="checkbox-label" style="font-weight: 600; color: var(--text-primary); margin-bottom: 0.5rem; min-height: auto;">
                            <input type="checkbox" id="bulkEditApplyTemplate"> Update Request Browser Profile
                        </label>
                        <div id="bulkEditTemplateFields" style="display: none; margin-top: 0.5rem;">
                            <select id="bulkEditTemplateVal" class="form-control">
                                <?php foreach ($builtinTemplates as $key => $tmpl): ?>
                                    <option value="<?= htmlspecialchars($key) ?>"><?= htmlspecialchars($tmpl['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <!-- Field: Extraction Mode -->
                    <div style="background: var(--bg-subtle); padding: 0.85rem; border-radius: var(--radius-sm); border: 1px solid var(--border-color); margin-bottom: 0.75rem;">
                        <label class="checkbox-label" style="font-weight: 600; color: var(--text-primary); margin-bottom: 0.5rem; min-height: auto;">
                            <input type="checkbox" id="bulkEditApplyType"> Update Extraction Mode
                        </label>
                        <div id="bulkEditTypeFields" style="display: none; margin-top: 0.5rem;">
                            <select id="bulkEditTypeVal" class="form-control">
                                <option value="html_full">Full Page HTML / Text</option>
                                <option value="xpath">XPath Expression (DOM Node)</option>
                                <option value="css">CSS Selector (.price, #content)</option>
                                <option value="json">JSON API Dot-Path</option>
                                <option value="regex">Regex Pattern</option>
                                <option value="headers">HTTP Response Headers</option>
                            </select>
                        </div>
                    </div>

                    <!-- Field: Timeout -->
                    <div style="background: var(--bg-subtle); padding: 0.85rem; border-radius: var(--radius-sm); border: 1px solid var(--border-color); margin-bottom: 0.75rem;">
                        <label class="checkbox-label" style="font-weight: 600; color: var(--text-primary); margin-bottom: 0.5rem; min-height: auto;">
                            <input type="checkbox" id="bulkEditApplyTimeout"> Update Request Timeout
                        </label>
                        <div id="bulkEditTimeoutFields" style="display: none; margin-top: 0.5rem;">
                            <input type="number" id="bulkEditTimeoutVal" class="form-control" min="5" max="60" value="25" placeholder="Timeout in seconds">
                        </div>
                    </div>

                    <!-- Field: Peak/Off-Peak Request Frequency -->
                    <div style="background: var(--bg-subtle); padding: 0.85rem; border-radius: var(--radius-sm); border: 1px solid var(--border-color); margin-bottom: 0.75rem;">
                        <label class="checkbox-label" style="font-weight: 600; color: var(--text-primary); margin-bottom: 0.5rem; min-height: auto;">
                            <input type="checkbox" id="bulkEditApplyPeak"> Update Peak/Off-Peak Request Frequency
                        </label>
                        <div id="bulkEditPeakFields" style="display: none; margin-top: 0.5rem;">
                            <label class="checkbox-label" style="margin-bottom: 0.75rem;">
                                <input type="checkbox" id="bulkEditPeakEnabledVal" checked>
                                <strong>⚡ Enable Dynamic Peak/Off-Peak Schedule</strong>
                            </label>
                            <div id="bulkEditPeakConfigBox">
                                <div class="form-grid">
                                    <div class="form-group">
                                        <label class="form-label" for="bulkEditPeakStartVal">Peak Start Hour (24h)</label>
                                        <select id="bulkEditPeakStartVal" class="form-control">
                                            <?php for ($h = 0; $h < 24; $h++): ?>
                                                <option value="<?= $h ?>" <?= $h === 9 ? 'selected' : '' ?>><?= sprintf('%02d:00 (%s)', $h, date('g A', strtotime("$h:00"))) ?></option>
                                            <?php endfor; ?>
                                        </select>
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label" for="bulkEditPeakEndVal">Peak End Hour (24h)</label>
                                        <select id="bulkEditPeakEndVal" class="form-control">
                                            <?php for ($h = 0; $h < 24; $h++): ?>
                                                <option value="<?= $h ?>" <?= $h === 18 ? 'selected' : '' ?>><?= sprintf('%02d:00 (%s)', $h, date('g A', strtotime("$h:00"))) ?></option>
                                            <?php endfor; ?>
                                        </select>
                                    </div>
                                </div>
                                <div class="form-grid">
                                    <div class="form-group">
                                        <label class="form-label" for="bulkEditPeakIntervalVal">Peak Interval (Mins)</label>
                                        <input type="number" id="bulkEditPeakIntervalVal" class="form-control" min="1" value="5" placeholder="e.g. 5 mins">
                                    </div>
                                    <div class="form-group">
                                        <label class="form-label" for="bulkEditOffpeakIntervalVal">Off-Peak Interval (Mins)</label>
                                        <input type="number" id="bulkEditOffpeakIntervalVal" class="form-control" min="1" value="60" placeholder="e.g. 60 mins">
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Field: Notifications & Options -->
                    <div style="background: var(--bg-subtle); padding: 0.85rem; border-radius: var(--radius-sm); border: 1px solid var(--border-color); margin-bottom: 0.75rem;">
                        <label class="checkbox-label" style="font-weight: 600; color: var(--text-primary); margin-bottom: 0.5rem; min-height: auto;">
                            <input type="checkbox" id="bulkEditApplyNotifications"> Update Notification & Strip Tags Settings
                        </label>
                        <div id="bulkEditNotificationsFields" style="display: none; margin-top: 0.5rem;">
                            <div class="form-grid">
                                <label class="checkbox-label">
                                    <input type="checkbox" id="bulkEditStripTagsVal" checked> Strip HTML tags
                                </label>
                                <label class="checkbox-label">
                                    <input type="checkbox" id="bulkEditNotifyChangeVal" checked> Notify on Change
                                </label>
                                <label class="checkbox-label">
                                    <input type="checkbox" id="bulkEditNotifyErrorVal" checked> Notify on Error
                                </label>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('bulkEditModal')">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="bulkEditSubmitBtn">✏️ Apply Changes</button>
                </div>
            </form>
        </div>
    </div>

    <!-- History & Snapshot Modal (with Interactive Red/Green Diff View & Version Comparison) -->
    <div class="modal-backdrop" id="historyModal">
        <div class="modal-dialog" style="max-width: 960px;">
            <div class="modal-header">
                <div class="modal-title" id="historyModalTitle">History & Snapshots</div>
                <button type="button" class="modal-close" onclick="closeModal('historyModal')">&times;</button>
            </div>
            <div class="modal-body">
                <!-- Top Actions Bar -->
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.25rem; background: var(--bg-subtle); padding: 0.85rem 1rem; border-radius: var(--radius-md); border: 1px solid var(--border-color); flex-wrap: wrap; gap: 0.75rem;">
                    <div>
                        <div id="historyLogMeta" style="font-size: 0.85rem; font-weight: 600; color: var(--text-primary);"></div>
                        <div style="font-size: 0.75rem; color: var(--text-muted);">Timestamped execution events & change history</div>
                    </div>
                    <div style="display: flex; gap: 0.5rem; align-items: center;">
                        <a id="downloadHistoryBtn" href="#" class="btn btn-secondary btn-sm" download>
                            ⬇️ Download Log (.txt)
                        </a>
                    </div>
                </div>

                <!-- View Mode Toggle (Diff vs Raw Content) -->
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; flex-wrap: wrap; gap: 0.75rem; border-bottom: 1px solid var(--border-color); padding-bottom: 0.75rem;">
                    <div style="display: flex; gap: 0.5rem;" id="historyViewModeBtns">
                        <button type="button" class="btn btn-primary btn-sm" id="btnHistoryModeDiff">🔴/🟢 Red & Green Diff</button>
                        <button type="button" class="btn btn-secondary btn-sm" id="btnHistoryModeRaw">📄 Raw Content</button>
                        <button type="button" class="btn btn-secondary btn-sm" id="btnHistoryModeChanges">📋 Change Log (<span id="historyChangesCount">0</span>)</button>
                    </div>

                    <!-- Comparison Selectors (Diff Mode) -->
                    <div id="historyDiffControls" style="display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap;">
                        <label style="font-size: 0.8rem; color: var(--text-muted);">Compare:</label>
                        <select id="diffVersionOld" class="form-control" style="min-height: 32px; padding: 0.2rem 0.5rem; font-size: 0.8rem; width: auto;">
                            <option value="">Previous Snapshot</option>
                        </select>
                        <span style="font-size: 0.8rem; color: var(--text-muted);">vs</span>
                        <select id="diffVersionNew" class="form-control" style="min-height: 32px; padding: 0.2rem 0.5rem; font-size: 0.8rem; width: auto;">
                            <option value="">Latest Captured</option>
                        </select>
                        <button type="button" class="btn btn-secondary btn-sm" id="btnComputeHistoryDiff" style="padding: 0.25rem 0.6rem;">⚡ Diff</button>
                    </div>

                    <!-- Raw Snapshot Selector (Raw Mode) -->
                    <div id="historyRawControls" style="display: none; align-items: center; gap: 0.5rem;">
                        <label for="snapshotSelect" style="font-size: 0.8rem; color: var(--text-muted);">Version:</label>
                        <select id="snapshotSelect" class="form-control" style="min-height: 32px; padding: 0.2rem 0.5rem; font-size: 0.8rem; width: auto;">
                            <option value="">Latest Captured Snapshot</option>
                        </select>
                    </div>
                </div>

                <!-- Container 1: Red/Green Diff Output Table -->
                <div id="historyDiffViewContainer" style="max-height: 460px; overflow-y: auto; border: 1px solid var(--border-color); border-radius: var(--radius-md); background: var(--bg-code); padding: 0.75rem;">
                    <div id="historyDiffTable">Select snapshot versions to compute red/green diff...</div>
                </div>

                <!-- Container 2: Raw Text Snapshot -->
                <div class="diff-container" id="historySnapshot" style="max-height: 460px; display: none;"></div>

                <!-- Container 3: Monitor Change Events List -->
                <div id="historyChangesContainer" style="max-height: 460px; overflow-y: auto; display: none;">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Timestamp</th>
                                <th>Changes</th>
                                <th>Size</th>
                                <th style="text-align: right;">Action</th>
                            </tr>
                        </thead>
                        <tbody id="historyChangesTableBody">
                            <tr><td colspan="4" style="text-align: center; color: var(--text-muted); padding: 1.5rem;">No detected changes recorded yet.</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('historyModal')">Close</button>
            </div>
        </div>
    </div>

    <!-- All Detected Changes Explorer Modal -->
    <div class="modal-backdrop" id="changesExplorerModal">
        <div class="modal-dialog" style="max-width: 1040px;">
            <div class="modal-header">
                <div class="modal-title">🔍 Detected Changes Explorer</div>
                <button type="button" class="modal-close" onclick="closeModal('changesExplorerModal')">&times;</button>
            </div>
            <div class="modal-body">
                <!-- Filter Bar -->
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; flex-wrap: wrap; gap: 0.75rem; background: var(--bg-subtle); padding: 0.85rem 1rem; border-radius: var(--radius-md); border: 1px solid var(--border-color);">
                    <div style="display: flex; gap: 0.75rem; flex-wrap: wrap; align-items: center; flex: 1;">
                        <input type="text" id="changesSearchInput" class="form-control" placeholder="🔍 Search target name or group..." style="min-height: 36px; min-width: 200px; flex: 1;">
                        <input type="date" id="changesDateInput" class="form-control" title="Filter changes by date" style="min-height: 36px; width: 145px;">
                        <label class="checkbox-label" style="font-size: 0.8rem; min-height: auto; margin-bottom: 0;">
                            <input type="checkbox" id="changesShowArchived"> Show Archived
                        </label>
                    </div>
                    <div style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
                        <button type="button" class="btn btn-secondary btn-sm" id="changesRefreshBtn">🔄 Refresh</button>
                        <button type="button" class="btn btn-secondary btn-sm" id="changesArchiveSelectedBtn" style="display: none;">📦 Archive Selected (<span id="changesSelectedCount">0</span>)</button>
                        <button type="button" class="btn btn-secondary btn-sm" id="changesDeleteSelectedBtn" style="display: none;">🗑️ Delete Selected</button>
                        <button type="button" class="btn btn-danger btn-sm" id="changesClearAllBtn">🔥 Clear All Changes</button>
                    </div>
                </div>

                <!-- Changes Table -->
                <div class="table-responsive" style="max-height: 500px; overflow-y: auto;">
                    <table class="data-table" id="allChangesTable">
                        <thead>
                            <tr>
                                <th style="width: 40px;" class="th-checkbox">
                                    <input type="checkbox" id="selectAllChangesCheckbox" class="custom-checkbox" title="Select all changes">
                                </th>
                                <th style="width: 150px;">Timestamp</th>
                                <th>Target / Name</th>
                                <th>Group</th>
                                <th>Changed Lines</th>
                                <th>Snapshot Size</th>
                                <th style="text-align: right; width: 180px;">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="allChangesTableBody">
                            <tr>
                                <td colspan="7" style="text-align: center; color: var(--text-muted); padding: 2.5rem;">Loading detected changes...</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('changesExplorerModal')">Close</button>
            </div>
        </div>
    </div>

    <!-- Individual Change Event Detail Modal (Previous vs New Red/Green Preview) -->
    <div class="modal-backdrop" id="changeDetailModal">
        <div class="modal-dialog" style="max-width: 960px;">
            <div class="modal-header">
                <div class="modal-title" id="changeDetailTitle">Change Comparison</div>
                <button type="button" class="modal-close" onclick="closeModal('changeDetailModal')">&times;</button>
            </div>
            <div class="modal-body">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem; background: var(--bg-subtle); padding: 0.75rem 1rem; border-radius: var(--radius-md); border: 1px solid var(--border-color); flex-wrap: wrap; gap: 0.5rem;">
                    <div id="changeDetailMeta" style="font-size: 0.85rem;"></div>
                    <div style="display: flex; gap: 0.5rem;">
                        <button type="button" class="btn btn-secondary btn-sm" id="changeDetailArchiveBtn">📦 Archive</button>
                        <button type="button" class="btn btn-danger btn-sm" id="changeDetailDeleteBtn">🗑️ Delete</button>
                    </div>
                </div>

                <div id="changeDetailDiffContainer" style="max-height: 480px; overflow-y: auto; border: 1px solid var(--border-color); border-radius: var(--radius-md); background: var(--bg-code); padding: 0.75rem;">
                    <div id="changeDetailDiffContent">Loading change diff...</div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeModal('changeDetailModal')">Close</button>
            </div>
        </div>
    </div>

    <script src="assets/js/app.js"></script>
</body>
</html>
