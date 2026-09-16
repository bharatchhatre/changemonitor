<?php
/**
 * Storage & Flat-File Database Manager
 * Website Change Monitor
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';

class Storage {
    private static string $monitorsFile = CM_DATA_DIR . '/monitors.json';
    private static string $settingsFile = CM_DATA_DIR . '/settings.json';
    private static string $statsFile = CM_DATA_DIR . '/stats.json';
    private static string $authFile = CM_DATA_DIR . '/auth.json';

    /**
     * Read JSON file with shared file lock
     */
    public static function readJson(string $filePath, array $default = []): array {
        if (!file_exists($filePath)) {
            return $default;
        }
        $fp = fopen($filePath, 'r');
        if (!$fp) {
            return $default;
        }
        flock($fp, LOCK_SH);
        $content = stream_get_contents($fp);
        flock($fp, LOCK_UN);
        fclose($fp);

        if (empty($content)) {
            return $default;
        }
        $data = json_decode($content, true);
        return is_array($data) ? $data : $default;
    }

    /**
     * Write JSON file with exclusive lock and atomic replace
     */
    public static function writeJson(string $filePath, array $data): bool {
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            return false;
        }
        $tempFile = $filePath . '.' . uniqid('tmp_', true);
        $fp = fopen($tempFile, 'w');
        if (!$fp) {
            return false;
        }
        flock($fp, LOCK_EX);
        fwrite($fp, $json);
        fflush($fp);
        flock($fp, LOCK_UN);
        fclose($fp);

        return rename($tempFile, $filePath);
    }

    // --- Monitors CRUD ---

    public static function getMonitors(): array {
        return self::readJson(self::$monitorsFile, []);
    }

    public static function getMonitor(string $id): ?array {
        $monitors = self::getMonitors();
        return $monitors[$id] ?? null;
    }

    public static function saveMonitor(array $monitor): string {
        $monitors = self::getMonitors();
        if (empty($monitor['id'])) {
            $monitor['id'] = 'mon_' . bin2hex(random_bytes(6));
            $monitor['created_at'] = date('c');
            $monitor['check_count'] = 0;
            $monitor['change_count'] = 0;
            $monitor['status'] = 'active'; // active, paused
            $monitor['last_status_code'] = null;
            $monitor['last_check_at'] = null;
            $monitor['last_change_at'] = null;
            $monitor['last_snapshot'] = '';
            $monitor['last_hash'] = '';
            $monitor['last_error'] = null;
        } else {
            $existing = $monitors[$monitor['id']] ?? [];
            $monitor = array_merge($existing, $monitor);
            $monitor['updated_at'] = date('c');
        }

        $monitors[$monitor['id']] = $monitor;
        self::writeJson(self::$monitorsFile, $monitors);
        return $monitor['id'];
    }

    public static function deleteMonitor(string $id): bool {
        $monitors = self::getMonitors();
        if (isset($monitors[$id])) {
            unset($monitors[$id]);
            self::writeJson(self::$monitorsFile, $monitors);
            return true;
        }
        return false;
    }

    // --- Settings & Presets ---

    public static function getSettings(): array {
        $defaults = [
            'app_timezone' => cm_env('APP_TIMEZONE', 'Asia/Kolkata'),
            'gmail_smtp_host' => cm_env('GMAIL_SMTP_HOST', 'smtp.gmail.com'),
            'gmail_smtp_port' => (int)cm_env('GMAIL_SMTP_PORT', 587),
            'gmail_smtp_user' => cm_env('GMAIL_SMTP_USER', ''),
            'gmail_smtp_pass' => cm_env('GMAIL_SMTP_PASS', ''),
            'alert_email_to' => cm_env('ALERT_EMAIL_TO', ''),
            'telegram_bot_token' => cm_env('TELEGRAM_BOT_TOKEN', ''),
            'telegram_chat_id' => cm_env('TELEGRAM_CHAT_ID', ''),
            'openwa_api_url' => cm_env('OPENWA_API_URL', ''),
            'openwa_api_key' => cm_env('OPENWA_API_KEY', ''),
            'openwa_chat_id' => cm_env('OPENWA_CHAT_ID', ''),
            'notify_on_change' => true,
            'notify_on_error' => true,
            'default_interval_mins' => 15,
            'user_browser_templates' => [],
        ];
        $saved = self::readJson(self::$settingsFile, []);
        $merged = array_merge($defaults, $saved);

        // Keep runtime PHP timezone synchronized
        if (!empty($merged['app_timezone'])) {
            @date_default_timezone_set($merged['app_timezone']);
        }

        return $merged;
    }

    public static function saveSettings(array $newSettings): bool {
        $current = self::getSettings();
        $merged = array_merge($current, $newSettings);
        if (!empty($merged['app_timezone'])) {
            @date_default_timezone_set($merged['app_timezone']);
        }
        return self::writeJson(self::$settingsFile, $merged);
    }

    // --- History Text Log & Snapshots ---

    public static function logHistory(string $monitorId, string $event, string $details = '', ?string $diff = null): void {
        $dir = CM_HISTORY_DIR . '/' . $monitorId;
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $logFile = $dir . '/history.log';
        $timestamp = date('Y-m-d H:i:s T');
        $entry = sprintf("[%s] [%s] %s\n", $timestamp, strtoupper($event), $details);
        if ($diff !== null && $diff !== '') {
            $entry .= "--- DIFF ---\n" . $diff . "\n--- END DIFF ---\n";
        }
        $entry .= "\n";

        file_put_contents($logFile, $entry, FILE_APPEND | LOCK_EX);

        // Also record to centralized structured log
        self::logAppEvent(
            level: ($event === 'ERROR' || $event === 'EXTRACTION_ERROR') ? 'ERROR' : ($event === 'CHANGE_DETECTED' ? 'WARNING' : 'INFO'),
            category: 'MONITOR',
            message: $details,
            context: ['monitor_id' => $monitorId, 'event' => $event]
        );
    }

    /**
     * Log centralized application or system error
     */
    public static function logAppEvent(string $level, string $category, string $message, array $context = []): void {
        $logFile = CM_LOGS_DIR . '/error.log';
        $entry = [
            'id' => uniqid('log_', true),
            'timestamp' => date('Y-m-d H:i:s'),
            'level' => strtoupper($level), // ERROR, WARNING, INFO, DEBUG
            'category' => strtoupper($category), // SYSTEM, MONITOR, AUTH, NOTIFIER, FETCHER
            'message' => $message,
            'context' => $context,
            'ip' => $_SERVER['REMOTE_ADDR'] ?? 'CLI',
        ];

        $line = json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
        @file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
    }

    /**
     * Get searchable and filterable system logs
     *
     * @param string $search Search query substring in message or context
     * @param string $level Filter by level (ERROR, WARNING, INFO)
     * @param string $category Filter by category (SYSTEM, MONITOR, AUTH, etc.)
     * @param int $limit Max rows to return
     * @return array
     */
    public static function getLogs(string $search = '', string $level = '', string $category = '', int $limit = 200): array {
        $logFile = CM_LOGS_DIR . '/error.log';
        if (!file_exists($logFile)) {
            return [];
        }

        $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!$lines) {
            return [];
        }

        $search = strtolower(trim($search));
        $level = strtoupper(trim($level));
        $category = strtoupper(trim($category));

        $results = [];
        // Read backwards for latest first
        for ($i = count($lines) - 1; $i >= 0; $i--) {
            $row = json_decode($lines[$i], true);
            if (!$row || !is_array($row)) {
                // Fallback for plain lines
                $row = [
                    'id' => 'raw_' . $i,
                    'timestamp' => '',
                    'level' => 'INFO',
                    'category' => 'RAW',
                    'message' => $lines[$i],
                    'context' => [],
                    'ip' => '',
                ];
            }

            // Filter Level
            if ($level !== '' && ($row['level'] ?? '') !== $level) {
                continue;
            }

            // Filter Category
            if ($category !== '' && ($row['category'] ?? '') !== $category) {
                continue;
            }

            // Filter Search Term
            if ($search !== '') {
                $rawString = strtolower(($row['message'] ?? '') . ' ' . json_encode($row['context'] ?? []));
                if (!str_contains($rawString, $search)) {
                    continue;
                }
            }

            $results[] = $row;
            if (count($results) >= $limit) {
                break;
            }
        }

        return $results;
    }

    /**
     * Clear application error log
     */
    public static function clearLogs(): bool {
        $logFile = CM_LOGS_DIR . '/error.log';
        if (file_exists($logFile)) {
            return @unlink($logFile);
        }
        return true;
    }

    public static function saveSnapshot(string $monitorId, string $content): void {
        $dir = CM_HISTORY_DIR . '/' . $monitorId;
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        // Save current snapshot
        file_put_contents($dir . '/latest.txt', $content, LOCK_EX);
        // Save timestamped archive
        $archiveFile = $dir . '/snap_' . date('Ymd_His') . '.txt';
        file_put_contents($archiveFile, $content, LOCK_EX);

        // Keep at most 30 recent snapshot files
        $files = glob($dir . '/snap_*.txt');
        if ($files && count($files) > 30) {
            sort($files);
            $toDelete = array_slice($files, 0, count($files) - 30);
            foreach ($toDelete as $oldFile) {
                @unlink($oldFile);
            }
        }
    }

    public static function getHistoryLog(string $monitorId, int $maxLines = 200): string {
        $logFile = CM_HISTORY_DIR . '/' . $monitorId . '/history.log';
        if (!file_exists($logFile)) {
            return "No history log available yet.";
        }
        $lines = file($logFile);
        if (!$lines) {
            return "Empty history log.";
        }
        if (count($lines) > $maxLines) {
            $lines = array_slice($lines, -$maxLines);
        }
        return implode('', $lines);
    }

    public static function getLatestSnapshot(string $monitorId): string {
        $file = CM_HISTORY_DIR . '/' . $monitorId . '/latest.txt';
        return file_exists($file) ? (file_get_contents($file) ?: '') : '';
    }

    /**
     * Get list of all timestamped snapshot files for a monitor
     */
    public static function getSnapshotList(string $monitorId): array {
        $dir = CM_HISTORY_DIR . '/' . $monitorId;
        if (!is_dir($dir)) {
            return [];
        }

        $files = glob($dir . '/snap_*.txt');
        if (!$files) {
            return [];
        }

        rsort($files); // Newest first
        $list = [];
        foreach ($files as $filePath) {
            $baseName = basename($filePath);
            // snap_20260917_002530.txt -> formatted date
            if (preg_match('/^snap_(\d{4})(\d{2})(\d{2})_(\d{2})(\d{2})(\d{2})\.txt$/', $baseName, $m)) {
                $formattedTime = "{$m[1]}-{$m[2]}-{$m[3]} {$m[4]}:{$m[5]}:{$m[6]}";
            } else {
                $formattedTime = date('Y-m-d H:i:s', filemtime($filePath));
            }

            $list[] = [
                'file' => $baseName,
                'time' => $formattedTime,
                'size' => filesize($filePath),
            ];
        }

        return $list;
    }

    /**
     * Get specific snapshot file content
     */
    public static function getSnapshotContent(string $monitorId, string $filename): string {
        // Sanitize filename to prevent path traversal
        $cleanFilename = basename($filename);
        $file = CM_HISTORY_DIR . '/' . $monitorId . '/' . $cleanFilename;
        if (file_exists($file) && is_file($file)) {
            return file_get_contents($file) ?: '';
        }
        return self::getLatestSnapshot($monitorId);
    }

    // --- Analytics & Stats ---

    public static function recordCheckStats(string $monitorId, bool $isSuccess, bool $isChange, float $durationMs, ?int $httpCode = null): void {
        $stats = self::readJson(self::$statsFile, [
            'total_checks' => 0,
            'total_changes' => 0,
            'total_errors' => 0,
            'avg_response_time_ms' => 0,
            'checks_by_date' => [],
            'monitors_stats' => [],
        ]);

        $stats['total_checks']++;
        if ($isChange) {
            $stats['total_changes']++;
        }
        if (!$isSuccess) {
            $stats['total_errors']++;
        }

        // Rolling average response time
        $currAvg = $stats['avg_response_time_ms'] ?? 0;
        $total = $stats['total_checks'];
        $stats['avg_response_time_ms'] = round((($currAvg * ($total - 1)) + $durationMs) / $total, 2);

        $today = date('Y-m-d');
        if (!isset($stats['checks_by_date'][$today])) {
            $stats['checks_by_date'][$today] = ['checks' => 0, 'changes' => 0, 'errors' => 0];
        }
        $stats['checks_by_date'][$today]['checks']++;
        if ($isChange) $stats['checks_by_date'][$today]['changes']++;
        if (!$isSuccess) $stats['checks_by_date'][$today]['errors']++;

        // Keep only last 30 days of analytics
        if (count($stats['checks_by_date']) > 30) {
            ksort($stats['checks_by_date']);
            $stats['checks_by_date'] = array_slice($stats['checks_by_date'], -30, null, true);
        }

        // Monitor specific stats
        if (!isset($stats['monitors_stats'][$monitorId])) {
            $stats['monitors_stats'][$monitorId] = [
                'checks' => 0,
                'changes' => 0,
                'errors' => 0,
                'avg_duration_ms' => 0,
                'last_checked' => date('c'),
            ];
        }
        $mStat = &$stats['monitors_stats'][$monitorId];
        $mStat['checks']++;
        if ($isChange) $mStat['changes']++;
        if (!$isSuccess) $mStat['errors']++;
        $mStat['avg_duration_ms'] = round((($mStat['avg_duration_ms'] * ($mStat['checks'] - 1)) + $durationMs) / $mStat['checks'], 2);
        $mStat['last_checked'] = date('c');

        self::writeJson(self::$statsFile, $stats);
    }

    public static function getStats(): array {
        return self::readJson(self::$statsFile, [
            'total_checks' => 0,
            'total_changes' => 0,
            'total_errors' => 0,
            'avg_response_time_ms' => 0,
            'checks_by_date' => [],
            'monitors_stats' => [],
        ]);
    }

    // --- Authentication Storage ---

    public static function getAuthInfo(): array {
        $default = [
            'password_hash' => password_hash(cm_env('ADMIN_PASSWORD', 'admin'), PASSWORD_DEFAULT),
            'last_login' => null,
            'failed_attempts' => 0,
            'locked_until' => null,
        ];
        return self::readJson(self::$authFile, $default);
    }

    public static function saveAuthInfo(array $auth): bool {
        return self::writeJson(self::$authFile, $auth);
    }

    // --- Full Backup & Restore ---

    /**
     * Create a complete backup bundle array (monitors, settings, stats, auth, history, and snapshots)
     */
    public static function createBackupData(): array {
        $backup = [
            'version' => CM_VERSION,
            'exported_at' => date('c'),
            'monitors' => self::getMonitors(),
            'settings' => self::getSettings(),
            'stats' => self::getStats(),
            'history' => [],
        ];

        // Include text history files and snapshots
        if (is_dir(CM_HISTORY_DIR)) {
            $dirs = glob(CM_HISTORY_DIR . '/*', GLOB_ONLYDIR);
            if ($dirs) {
                foreach ($dirs as $mDir) {
                    $mId = basename($mDir);
                    $backup['history'][$mId] = [
                        'log' => file_exists("$mDir/history.log") ? file_get_contents("$mDir/history.log") : '',
                        'latest' => file_exists("$mDir/latest.txt") ? file_get_contents("$mDir/latest.txt") : '',
                    ];
                }
            }
        }

        return $backup;
    }

    /**
     * Restore database and text files from backup data array
     */
    public static function restoreBackupData(array $backup): bool {
        if (empty($backup['version']) || !isset($backup['monitors'])) {
            throw new \InvalidArgumentException('Invalid backup archive structure.');
        }

        // Restore monitors
        if (isset($backup['monitors']) && is_array($backup['monitors'])) {
            self::writeJson(self::$monitorsFile, $backup['monitors']);
        }

        // Restore settings (preserve if not provided)
        if (isset($backup['settings']) && is_array($backup['settings'])) {
            self::writeJson(self::$settingsFile, $backup['settings']);
        }

        // Restore stats
        if (isset($backup['stats']) && is_array($backup['stats'])) {
            self::writeJson(self::$statsFile, $backup['stats']);
        }

        // Restore history logs and snapshots
        if (isset($backup['history']) && is_array($backup['history'])) {
            foreach ($backup['history'] as $mId => $item) {
                // Sanitize directory name
                $cleanId = preg_replace('/[^a-zA-Z0-9_\-]/', '', (string)$mId);
                if (empty($cleanId)) continue;

                $targetDir = CM_HISTORY_DIR . '/' . $cleanId;
                if (!is_dir($targetDir)) {
                    @mkdir($targetDir, 0755, true);
                }
                if (isset($item['log'])) {
                    file_put_contents("$targetDir/history.log", (string)$item['log'], LOCK_EX);
                }
                if (isset($item['latest'])) {
                    file_put_contents("$targetDir/latest.txt", (string)$item['latest'], LOCK_EX);
                }
            }
        }

        return true;
    }
}

