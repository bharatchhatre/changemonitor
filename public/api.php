<?php
/**
 * AJAX API Router
 * Website Change Monitor
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/storage.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/fetcher.php';
require_once __DIR__ . '/../includes/extractor.php';
require_once __DIR__ . '/../includes/notifier.php';
require_once __DIR__ . '/../includes/engine.php';

header('Content-Type: application/json; charset=utf-8');

// Ensure admin is authenticated for all API calls
Auth::requireAuth();

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$input = json_decode(file_get_contents('php://input'), true) ?: $_POST;

// Verify CSRF for mutating POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $input['csrf_token'] ?? null;
    if (!Auth::verifyCsrfToken($token)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Invalid or expired CSRF security token']);
        exit;
    }
}

try {
    switch ($action) {
        // --- Test & Live Preview Selector ---
        case 'test_preview':
            $url = trim($input['url'] ?? '');
            if (empty($url)) {
                throw new \InvalidArgumentException('URL is required for preview');
            }
            $type = $input['type'] ?? 'html_full';
            $selector = $input['selector'] ?? '';
            $template = $input['browser_template'] ?? 'chrome_mac';
            $customHeaders = $input['custom_headers'] ?? '';
            $cookies = $input['cookies'] ?? '';
            $stripTags = !empty($input['strip_tags']);

            $fetchResult = Fetcher::request([
                'url' => $url,
                'method' => $input['method'] ?? 'GET',
                'template' => $template,
                'custom_headers' => $customHeaders,
                'cookies' => $cookies,
                'proxy' => $input['proxy'] ?? '',
                'timeout' => 20,
            ]);

            if (!$fetchResult['success']) {
                echo json_encode([
                    'success' => false,
                    'http_code' => $fetchResult['http_code'],
                    'duration_ms' => $fetchResult['duration_ms'],
                    'error' => $fetchResult['error'] ?? 'HTTP Fetch Failed',
                ]);
                exit;
            }

            $extractResult = Extractor::extract(
                $fetchResult['body'],
                $type,
                $selector,
                $fetchResult['headers'],
                ['strip_tags' => $stripTags, 'trim_whitespace' => true]
            );

            $extracted = $extractResult['extracted'] ?? '';
            // Ensure valid UTF-8
            if (!mb_check_encoding($extracted, 'UTF-8')) {
                $extracted = mb_convert_encoding($extracted, 'UTF-8', 'UTF-8, ISO-8859-1, WINDOWS-1252');
            }

            echo json_encode([
                'success' => $extractResult['success'],
                'http_code' => $fetchResult['http_code'],
                'content_type' => $fetchResult['content_type'],
                'duration_ms' => $fetchResult['duration_ms'],
                'extracted' => $extracted,
                'extracted_length' => strlen($extracted),
                'error' => $extractResult['error'],
            ], JSON_INVALID_UTF8_SUBSTITUTE);
            break;

        // --- Save / Create Monitor ---
        case 'save_monitor':
            $name = trim($input['name'] ?? '');
            $url = trim($input['url'] ?? '');
            if (empty($name) || empty($url)) {
                throw new \InvalidArgumentException('Monitor Name and Target URL are required');
            }

            $monitorData = [
                'id' => $input['id'] ?? null,
                'name' => $name,
                'url' => $url,
                'group' => trim($input['group'] ?? 'General') ?: 'General',
                'type' => $input['type'] ?? 'html_full',
                'selector' => trim($input['selector'] ?? ''),
                'browser_template' => $input['browser_template'] ?? 'chrome_mac',
                'custom_headers' => $input['custom_headers'] ?? '',
                'cookies' => $input['cookies'] ?? '',
                'interval_mins' => max(1, (int)($input['interval_mins'] ?? 15)),
                'peak_schedule_enabled' => !empty($input['peak_schedule_enabled']),
                'peak_start_hour' => max(0, min(23, (int)($input['peak_start_hour'] ?? 9))),
                'peak_end_hour' => max(0, min(23, (int)($input['peak_end_hour'] ?? 18))),
                'peak_interval_mins' => max(1, (int)($input['peak_interval_mins'] ?? 5)),
                'offpeak_interval_mins' => max(1, (int)($input['offpeak_interval_mins'] ?? 60)),
                'timeout' => max(5, min(60, (int)($input['timeout'] ?? 25))),
                'strip_tags' => !empty($input['strip_tags']),
                'simulate_delay' => !empty($input['simulate_delay']),
                'notify_on_change' => !empty($input['notify_on_change']),
                'notify_on_error' => !empty($input['notify_on_error']),
                'status' => $input['status'] ?? 'active',
            ];

            $savedId = Storage::saveMonitor($monitorData);

            // If new, execute initial baseline check immediately
            if (empty($input['id'])) {
                Engine::run($savedId, true);
            }

            echo json_encode(['success' => true, 'id' => $savedId, 'message' => 'Monitor saved successfully']);
            break;

        // --- Bulk Add Monitors ---
        case 'bulk_add_monitors':
            $rawItems = $input['monitors'] ?? [];
            if (empty($rawItems) || !is_array($rawItems)) {
                throw new \InvalidArgumentException('No monitor items provided');
            }

            $defaults = [
                'group' => trim($input['default_group'] ?? 'General') ?: 'General',
                'type' => $input['default_type'] ?? 'html_full',
                'selector' => trim($input['default_selector'] ?? ''),
                'browser_template' => $input['default_browser_template'] ?? 'chrome_mac',
                'interval_mins' => max(1, (int)($input['default_interval_mins'] ?? 15)),
                'timeout' => max(5, min(60, (int)($input['default_timeout'] ?? 25))),
                'strip_tags' => isset($input['default_strip_tags']) ? !empty($input['default_strip_tags']) : true,
                'simulate_delay' => !empty($input['default_simulate_delay']),
                'notify_on_change' => isset($input['default_notify_on_change']) ? !empty($input['default_notify_on_change']) : true,
                'notify_on_error' => isset($input['default_notify_on_error']) ? !empty($input['default_notify_on_error']) : true,
                'status' => $input['default_status'] ?? 'active',
            ];

            $monitorsToSave = [];
            foreach ($rawItems as $item) {
                $url = trim($item['url'] ?? '');
                if (empty($url)) {
                    continue;
                }
                $name = trim($item['name'] ?? '');
                if (empty($name)) {
                    $parsed = parse_url($url);
                    $name = ($parsed['host'] ?? $url) . ($parsed['path'] ?? '');
                    $name = rtrim($name, '/');
                }

                $monitorsToSave[] = [
                    'name' => $name,
                    'url' => $url,
                    'group' => trim($item['group'] ?? $defaults['group']) ?: 'General',
                    'type' => $item['type'] ?? $defaults['type'],
                    'selector' => trim($item['selector'] ?? $defaults['selector']),
                    'browser_template' => $item['browser_template'] ?? $defaults['browser_template'],
                    'custom_headers' => $item['custom_headers'] ?? '',
                    'cookies' => $item['cookies'] ?? '',
                    'interval_mins' => max(1, (int)($item['interval_mins'] ?? $defaults['interval_mins'])),
                    'peak_schedule_enabled' => !empty($item['peak_schedule_enabled']),
                    'peak_start_hour' => max(0, min(23, (int)($item['peak_start_hour'] ?? 9))),
                    'peak_end_hour' => max(0, min(23, (int)($item['peak_end_hour'] ?? 18))),
                    'peak_interval_mins' => max(1, (int)($item['peak_interval_mins'] ?? 5)),
                    'offpeak_interval_mins' => max(1, (int)($item['offpeak_interval_mins'] ?? 60)),
                    'timeout' => max(5, min(60, (int)($item['timeout'] ?? $defaults['timeout']))),
                    'strip_tags' => isset($item['strip_tags']) ? !empty($item['strip_tags']) : $defaults['strip_tags'],
                    'simulate_delay' => isset($item['simulate_delay']) ? !empty($item['simulate_delay']) : $defaults['simulate_delay'],
                    'notify_on_change' => isset($item['notify_on_change']) ? !empty($item['notify_on_change']) : $defaults['notify_on_change'],
                    'notify_on_error' => isset($item['notify_on_error']) ? !empty($item['notify_on_error']) : $defaults['notify_on_error'],
                    'status' => $item['status'] ?? $defaults['status'],
                ];
            }

            if (empty($monitorsToSave)) {
                throw new \InvalidArgumentException('No valid monitor URLs found to add');
            }

            $savedIds = Storage::saveMonitorsBulk($monitorsToSave);

            // Trigger initial baseline checks if requested
            $runBaseline = !empty($input['run_baseline']);
            if ($runBaseline) {
                foreach ($savedIds as $id) {
                    Engine::run($id, true);
                }
            }

            echo json_encode([
                'success' => true,
                'count' => count($savedIds),
                'ids' => $savedIds,
                'message' => count($savedIds) . ' monitor(s) successfully created' . ($runBaseline ? ' and baseline checks executed' : '')
            ]);
            break;

        // --- Bulk Set Group / Category ---
        case 'bulk_set_group':
            $ids = $input['ids'] ?? [];
            $group = trim($input['group'] ?? 'Ungrouped');
            if (empty($ids) || !is_array($ids)) {
                throw new \InvalidArgumentException('Monitor IDs array is required');
            }
            $updatedCount = Storage::bulkAssignGroup($ids, $group);
            echo json_encode([
                'success' => true,
                'updated_count' => $updatedCount,
                'group' => $group,
                'message' => "Assigned {$updatedCount} monitor(s) to group \"{$group}\""
            ]);
            break;

        // --- Bulk Edit Monitors ---
        case 'bulk_edit':
            $ids = $input['ids'] ?? [];
            if (empty($ids) || !is_array($ids)) {
                throw new \InvalidArgumentException('Monitor IDs array is required');
            }
            $fields = $input['fields'] ?? [];
            if (empty($fields) || !is_array($fields)) {
                throw new \InvalidArgumentException('Edit fields are required');
            }
            $updatedCount = Storage::bulkEditMonitors($ids, $fields);
            echo json_encode([
                'success' => true,
                'updated_count' => $updatedCount,
                'message' => "Successfully updated {$updatedCount} monitor(s)"
            ]);
            break;

        // --- Bulk Update Status (Activate / Pause) ---
        case 'bulk_status':
            $ids = $input['ids'] ?? [];
            $status = $input['status'] ?? 'active';
            if (empty($ids) || !is_array($ids)) {
                throw new \InvalidArgumentException('Monitor IDs array is required');
            }
            if (!in_array($status, ['active', 'paused'], true)) {
                throw new \InvalidArgumentException('Status must be "active" or "paused"');
            }
            $updatedCount = Storage::bulkUpdateStatus($ids, $status);
            echo json_encode([
                'success' => true,
                'updated_count' => $updatedCount,
                'status' => $status,
                'message' => "Successfully set {$updatedCount} monitor(s) to " . ($status === 'active' ? 'Active' : 'Paused')
            ]);
            break;

        // --- Bulk Delete Monitors (Move to Trash) ---
        case 'bulk_delete':
            $ids = $input['ids'] ?? [];
            if (empty($ids) || !is_array($ids)) {
                throw new \InvalidArgumentException('Monitor IDs array is required');
            }
            $deletedCount = Storage::bulkDeleteMonitors($ids);
            echo json_encode([
                'success' => true,
                'deleted_count' => $deletedCount,
                'message' => "Moved {$deletedCount} monitor(s) to Trash"
            ]);
            break;

        // --- Bulk Run Check ---
        case 'bulk_run_check':
            $ids = $input['ids'] ?? [];
            if (empty($ids) || !is_array($ids)) {
                throw new \InvalidArgumentException('Monitor IDs array is required');
            }
            $results = [];
            foreach ($ids as $id) {
                $results[$id] = Engine::run((string)$id, true);
            }
            echo json_encode([
                'success' => true,
                'count' => count($results),
                'results' => $results,
                'message' => "Executed checks for " . count($results) . " monitor(s)"
            ]);
            break;

        // --- Delete Monitor (Move to Trash) ---
        case 'delete_monitor':
            $id = $input['id'] ?? '';
            if (empty($id)) {
                throw new \InvalidArgumentException('Monitor ID is required');
            }
            $deleted = Storage::deleteMonitor($id);
            echo json_encode(['success' => $deleted, 'message' => 'Monitor moved to Trash']);
            break;

        // --- Trash & Restore Endpoints ---
        case 'get_trash':
            $trash = Storage::getTrash();
            echo json_encode(['success' => true, 'trash' => array_values($trash)]);
            break;

        case 'restore_monitor':
            $id = $input['id'] ?? '';
            if (empty($id)) {
                throw new \InvalidArgumentException('Monitor ID is required');
            }
            $restored = Storage::restoreMonitor($id);
            echo json_encode(['success' => $restored, 'message' => $restored ? 'Monitor restored successfully' : 'Monitor not found in Trash']);
            break;

        case 'bulk_restore':
            $ids = $input['ids'] ?? [];
            if (empty($ids) || !is_array($ids)) {
                throw new \InvalidArgumentException('Monitor IDs array is required');
            }
            $restoredCount = Storage::bulkRestoreMonitors($ids);
            echo json_encode(['success' => true, 'restored_count' => $restoredCount, 'message' => "Restored {$restoredCount} monitor(s)"]);
            break;

        case 'purge_trash':
            $id = $input['id'] ?? '';
            if (empty($id)) {
                throw new \InvalidArgumentException('Monitor ID is required');
            }
            $purged = Storage::purgeDeletedMonitor($id);
            echo json_encode(['success' => $purged, 'message' => 'Monitor permanently removed']);
            break;

        case 'empty_trash':
            $count = Storage::emptyTrash();
            echo json_encode(['success' => true, 'count' => $count, 'message' => "Permanently deleted {$count} monitor(s)"]);
            break;

        // --- Toggle Status (Active / Paused) ---
        case 'toggle_status':
            $id = $input['id'] ?? '';
            $monitor = Storage::getMonitor($id);
            if (!$monitor) {
                throw new \RuntimeException('Monitor not found');
            }
            $monitor['status'] = ($monitor['status'] === 'active') ? 'paused' : 'active';
            Storage::saveMonitor($monitor);
            echo json_encode(['success' => true, 'status' => $monitor['status']]);
            break;

        // --- Run Instant Manual Check ---
        case 'run_check':
            $id = $input['id'] ?? '';
            if (empty($id)) {
                throw new \InvalidArgumentException('Monitor ID is required');
            }
            $result = Engine::run($id, true);
            echo json_encode(['success' => true, 'result' => $result]);
            break;

        // --- Run All Monitors ---
        case 'run_all':
            $results = Engine::runAll(true);
            echo json_encode(['success' => true, 'results' => $results]);
            break;

        // --- View History & Snapshot Log ---
        case 'get_history':
            $id = $_GET['id'] ?? $input['id'] ?? '';
            if (empty($id)) {
                throw new \InvalidArgumentException('Monitor ID is required');
            }
            $snapshotFile = $_GET['snapshot_file'] ?? $input['snapshot_file'] ?? '';
            $history = Storage::getHistoryLog($id);
            $snapshots = Storage::getSnapshotList($id);
            
            $snapshotContent = !empty($snapshotFile) 
                ? Storage::getSnapshotContent($id, $snapshotFile)
                : Storage::getLatestSnapshot($id);

            $monitor = Storage::getMonitor($id);
            $changes = Storage::getChangeEvents(monitorId: $id, includeArchived: true, limit: 50);

            echo json_encode([
                'success' => true,
                'monitor' => $monitor,
                'history_log' => $history,
                'snapshots_list' => $snapshots,
                'current_snapshot' => $snapshotContent,
                'latest_snapshot' => $snapshotContent,
                'changes' => $changes,
            ]);
            break;

        // --- Compute Diff between Two Snapshots or a Snapshot vs Previous ---
        case 'get_history_diff':
            $id = $_GET['id'] ?? $input['id'] ?? '';
            if (empty($id)) {
                throw new \InvalidArgumentException('Monitor ID is required');
            }
            $fileA = $_GET['file_a'] ?? $input['file_a'] ?? '';
            $fileB = $_GET['file_b'] ?? $input['file_b'] ?? '';

            $monitor = Storage::getMonitor($id);
            $type = $monitor['type'] ?? 'html_full';

            $contentA = !empty($fileA) ? Storage::getSnapshotContent($id, $fileA) : '';
            $contentB = !empty($fileB) ? Storage::getSnapshotContent($id, $fileB) : Storage::getLatestSnapshot($id);

            require_once __DIR__ . '/../includes/diff_formatter.php';
            $diffData = DiffFormatter::computeContextualDiff($contentA, $contentB, $type, 30);
            $htmlTable = DiffFormatter::formatHtmlInline($diffData, ['max_lines' => 150]);

            echo json_encode([
                'success' => true,
                'diff_data' => $diffData,
                'html_table' => $htmlTable,
                'old_len' => strlen($contentA),
                'new_len' => strlen($contentB),
            ]);
            break;

        // --- Get List of All Detected Changes Across Monitors ---
        case 'get_changes_list':
            $monitorId = $_GET['monitor_id'] ?? $input['monitor_id'] ?? null;
            $includeArchived = !empty($_GET['include_archived'] ?? $input['include_archived']);
            $search = $_GET['search'] ?? $input['search'] ?? '';
            $date = $_GET['date'] ?? $input['date'] ?? '';
            $limit = (int)($_GET['limit'] ?? $input['limit'] ?? 100);

            $changes = Storage::getChangeEvents(
                monitorId: !empty($monitorId) ? (string)$monitorId : null,
                includeArchived: $includeArchived,
                search: $search,
                date: (string)$date,
                limit: $limit
            );

            echo json_encode([
                'success' => true,
                'changes' => $changes,
                'count' => count($changes),
            ]);
            break;

        // --- Get Detailed Diff for an Individual Change Event ---
        case 'get_change_diff':
            $eventId = $_GET['event_id'] ?? $input['event_id'] ?? '';
            $monitorId = $_GET['monitor_id'] ?? $input['monitor_id'] ?? null;
            if (empty($eventId)) {
                throw new \InvalidArgumentException('Change Event ID is required');
            }

            $event = Storage::getChangeEvent($eventId, $monitorId);
            if (!$event) {
                throw new \InvalidArgumentException('Change Event not found');
            }

            require_once __DIR__ . '/../includes/diff_formatter.php';
            $oldContent = $event['old_snapshot'] ?? '';
            $newContent = $event['new_snapshot'] ?? '';
            $type = $event['type'] ?? 'html_full';

            $diffData = DiffFormatter::computeContextualDiff($oldContent, $newContent, $type, 30);
            $htmlTable = DiffFormatter::formatHtmlInline($diffData, ['max_lines' => 200]);

            echo json_encode([
                'success' => true,
                'event' => $event,
                'diff_data' => $diffData,
                'html_table' => $htmlTable,
            ]);
            break;

        // --- Archive / Unarchive Change Events ---
        case 'archive_changes':
            $eventIds = $input['event_ids'] ?? [];
            $archive = isset($input['archive']) ? (bool)$input['archive'] : true;
            if (empty($eventIds) || !is_array($eventIds)) {
                throw new \InvalidArgumentException('Event IDs array is required');
            }

            $count = Storage::archiveChangeEvents($eventIds, $archive);
            echo json_encode([
                'success' => true,
                'count' => $count,
                'message' => ($archive ? 'Archived' : 'Unarchived') . " {$count} change record(s)",
            ]);
            break;

        // --- Delete Change Events ---
        case 'delete_changes':
            $eventIds = $input['event_ids'] ?? [];
            if (empty($eventIds) || !is_array($eventIds)) {
                throw new \InvalidArgumentException('Event IDs array is required');
            }

            $count = Storage::deleteChangeEvents($eventIds);
            echo json_encode([
                'success' => true,
                'count' => $count,
                'message' => "Permanently deleted {$count} change record(s)",
            ]);
            break;

        // --- Clear All Detected Changes ---
        case 'clear_all_changes':
            $monitorId = $input['monitor_id'] ?? null;
            $count = Storage::clearAllChanges(!empty($monitorId) ? (string)$monitorId : null);
            echo json_encode([
                'success' => true,
                'count' => $count,
                'message' => "Cleared all change records ({$count} items removed)",
            ]);
            break;

        // --- Delete Specific Logs by IDs ---
        case 'delete_logs':
            $logIds = $input['log_ids'] ?? [];
            if (empty($logIds) || !is_array($logIds)) {
                throw new \InvalidArgumentException('Log IDs array is required');
            }

            $count = Storage::deleteLogsByIds($logIds);
            echo json_encode([
                'success' => true,
                'count' => $count,
                'message' => "Deleted {$count} log entry(s)",
            ]);
            break;

        // --- Clear Error Logs by Filter ---
        case 'clear_logs_by_filter':
            $level = $input['level'] ?? '';
            $category = $input['category'] ?? '';
            $search = $input['search'] ?? '';

            $count = Storage::clearLogsByFilter($level, $category, $search);
            echo json_encode([
                'success' => true,
                'count' => $count,
                'message' => "Cleared {$count} log entry(s)",
            ]);
            break;

        // --- Download History Log Text File ---
        case 'download_history_log':
            $id = $_GET['id'] ?? $input['id'] ?? '';
            if (empty($id)) {
                throw new \InvalidArgumentException('Monitor ID is required');
            }
            $history = Storage::getHistoryLog($id, 2000);
            $filename = 'monitor_' . $id . '_history_' . date('Ymd_His') . '.log';

            header('Content-Type: text/plain; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            echo $history;
            exit;

        // --- Save Global Settings ---
        case 'save_settings':
            $settingsData = [
                'app_timezone' => trim($input['app_timezone'] ?? 'Asia/Kolkata'),
                'gmail_smtp_host' => trim($input['gmail_smtp_host'] ?? 'smtp.gmail.com'),
                'gmail_smtp_port' => (int)($input['gmail_smtp_port'] ?? 587),
                'gmail_smtp_user' => trim($input['gmail_smtp_user'] ?? ''),
                'gmail_smtp_pass' => trim($input['gmail_smtp_pass'] ?? ''),
                'alert_email_to' => trim($input['alert_email_to'] ?? ''),
                'telegram_bot_token' => trim($input['telegram_bot_token'] ?? ''),
                'telegram_chat_id' => trim($input['telegram_chat_id'] ?? ''),
                'openwa_api_url' => trim($input['openwa_api_url'] ?? ''),
                'openwa_api_key' => trim($input['openwa_api_key'] ?? ''),
                'openwa_chat_id' => trim($input['openwa_chat_id'] ?? ''),
                'notify_on_change' => !empty($input['notify_on_change']),
                'notify_on_error' => !empty($input['notify_on_error']),
                'default_interval_mins' => (int)($input['default_interval_mins'] ?? 15),
            ];

            if (!empty($input['new_password'])) {
                Auth::updatePassword(trim($input['new_password']));
            }

            // Save custom browser templates if present
            if (isset($input['user_browser_templates']) && is_array($input['user_browser_templates'])) {
                $settingsData['user_browser_templates'] = $input['user_browser_templates'];
            }

            Storage::saveSettings($settingsData);
            echo json_encode(['success' => true, 'message' => 'Settings saved successfully']);
            break;

        // --- Test Notification Channels ---
        case 'test_notification':
            $channel = $input['channel'] ?? 'all';
            $testSubject = "🧪 ChangeMonitor Test Notification";
            $testMsg = "This is a test notification from your Website Change Monitor.\nSystem Time: " . date('Y-m-d H:i:s');
            
            $testOld = "{\n  \"status\": \"active\",\n  \"product\": {\n    \"name\": \"Pro Plan Subscription\",\n    \"price\": 99,\n    \"currency\": \"USD\",\n    \"stock\": 15\n  }\n}";
            $testNew = "{\n  \"status\": \"active\",\n  \"product\": {\n    \"name\": \"Pro Plan Subscription (Updated)\",\n    \"price\": 129,\n    \"currency\": \"USD\",\n    \"stock\": 8\n  }\n}";
            $testDiffData = DiffFormatter::computeContextualDiff($testOld, $testNew, 'json');

            $testContext = [
                'url' => 'https://example.com/pricing',
                'old_snapshot' => $testOld,
                'new_snapshot' => $testNew,
                'diff_data' => $testDiffData,
                'monitor' => ['name' => 'Test Product Monitor', 'type' => 'json'],
            ];

            $results = [];
            $settings = Storage::getSettings();

            if ($channel === 'telegram' || $channel === 'all') {
                $token = $input['telegram_bot_token'] ?? $settings['telegram_bot_token'];
                $chatId = $input['telegram_chat_id'] ?? $settings['telegram_chat_id'];
                if (!empty($token) && !empty($chatId)) {
                    $results['telegram'] = Notifier::sendTelegram($token, $chatId, $testSubject, $testMsg, $testContext);
                } else {
                    $results['telegram'] = ['success' => false, 'error' => 'Telegram token or chat ID is empty'];
                }
            }

            if ($channel === 'openwa' || $channel === 'all') {
                $apiUrl = $input['openwa_api_url'] ?? $settings['openwa_api_url'];
                $apiKey = $input['openwa_api_key'] ?? $settings['openwa_api_key'];
                $chatId = $input['openwa_chat_id'] ?? $settings['openwa_chat_id'];
                if (!empty($apiUrl) && !empty($chatId)) {
                    $results['openwa'] = Notifier::sendOpenWA($apiUrl, $apiKey, $chatId, $testSubject, $testMsg, $testContext);
                } else {
                    $results['openwa'] = ['success' => false, 'error' => 'OpenWA URL or chat ID is empty'];
                }
            }

            if ($channel === 'email' || $channel === 'all') {
                $smtpSettings = [
                    'gmail_smtp_host' => $input['gmail_smtp_host'] ?? $settings['gmail_smtp_host'],
                    'gmail_smtp_port' => (int)($input['gmail_smtp_port'] ?? $settings['gmail_smtp_port']),
                    'gmail_smtp_user' => $input['gmail_smtp_user'] ?? $settings['gmail_smtp_user'],
                    'gmail_smtp_pass' => $input['gmail_smtp_pass'] ?? $settings['gmail_smtp_pass'],
                    'alert_email_to' => $input['alert_email_to'] ?? $settings['alert_email_to'],
                ];
                $results['email'] = Notifier::sendGmailSmtp($smtpSettings, $testSubject, $testMsg, $testContext);
            }

            echo json_encode(['success' => true, 'results' => $results]);
            break;

        // --- Export Full Backup JSON ---
        case 'export_backup':
            $backupData = Storage::createBackupData();
            $filename = 'changemonitor_backup_' . date('Y-m-d_His') . '.json';
            
            header('Content-Type: application/json; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            echo json_encode($backupData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            exit;

        // --- Restore Full Backup JSON ---
        case 'restore_backup':
            $jsonContent = '';
            if (isset($_FILES['backup_file']) && $_FILES['backup_file']['error'] === UPLOAD_ERR_OK) {
                $jsonContent = file_get_contents($_FILES['backup_file']['tmp_name']);
            } elseif (!empty($input['backup_data'])) {
                $jsonContent = is_string($input['backup_data']) ? $input['backup_data'] : json_encode($input['backup_data']);
            }

            if (empty($jsonContent)) {
                throw new \InvalidArgumentException('No backup file or data provided.');
            }

            $backupArray = json_decode($jsonContent, true);
            if (!$backupArray) {
                throw new \InvalidArgumentException('Uploaded file is not a valid JSON backup: ' . json_last_error_msg());
            }

            Storage::restoreBackupData($backupArray);
            echo json_encode(['success' => true, 'message' => 'Backup restored successfully!']);
            break;

        // --- Search & Filter Application Logs ---
        case 'get_logs':
            $search = $_GET['search'] ?? $input['search'] ?? '';
            $level = $_GET['level'] ?? $input['level'] ?? '';
            $category = $_GET['category'] ?? $input['category'] ?? '';
            $date = $_GET['date'] ?? $input['date'] ?? '';
            $limit = (int)($_GET['limit'] ?? $input['limit'] ?? 200);

            $logs = Storage::getLogs(search: $search, level: $level, category: $category, date: (string)$date, limit: $limit);
            echo json_encode(['success' => true, 'logs' => $logs, 'count' => count($logs)]);
            break;

        // --- Get Stats & Analytics ---
        case 'get_stats':
            $stats = Storage::getStats();
            echo json_encode(['success' => true, 'stats' => $stats]);
            break;

        // --- Clear Application Logs ---
        case 'clear_logs':
            Storage::clearLogs();
            echo json_encode(['success' => true, 'message' => 'Logs cleared successfully']);
            break;

        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Invalid or missing API action']);
            break;
    }
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

