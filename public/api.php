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

            echo json_encode([
                'success' => $extractResult['success'],
                'http_code' => $fetchResult['http_code'],
                'content_type' => $fetchResult['content_type'],
                'duration_ms' => $fetchResult['duration_ms'],
                'extracted' => $extractResult['extracted'],
                'extracted_length' => strlen($extractResult['extracted']),
                'raw_body_snippet' => substr($fetchResult['body'], 0, 1500),
                'error' => $extractResult['error'],
            ]);
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

        // --- Delete Monitor ---
        case 'delete_monitor':
            $id = $input['id'] ?? '';
            if (empty($id)) {
                throw new \InvalidArgumentException('Monitor ID is required');
            }
            $deleted = Storage::deleteMonitor($id);
            echo json_encode(['success' => $deleted]);
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

            echo json_encode([
                'success' => true,
                'monitor' => $monitor,
                'history_log' => $history,
                'snapshots_list' => $snapshots,
                'current_snapshot' => $snapshotContent,
                'latest_snapshot' => $snapshotContent,
            ]);
            break;

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
            $testMsg = "This is a test notification from your Website Change Monitor installed on Bluehost shared hosting.\nSystem Time: " . date('Y-m-d H:i:s');
            $testContext = ['url' => 'https://example.com', 'diff' => "+ Added line for notification test\n- Removed line for notification test"];

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
            $limit = (int)($_GET['limit'] ?? $input['limit'] ?? 200);

            $logs = Storage::getLogs(search: $search, level: $level, category: $category, limit: $limit);
            echo json_encode(['success' => true, 'logs' => $logs, 'count' => count($logs)]);
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

