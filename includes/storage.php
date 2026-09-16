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
        return array_merge($defaults, $saved);
    }

    public static function saveSettings(array $newSettings): bool {
        $current = self::getSettings();
        $merged = array_merge($current, $newSettings);
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
}
