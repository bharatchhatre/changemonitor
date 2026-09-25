<?php
/**
 * Storage & Flat-File Database Manager
 * Website Change Monitor
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';

class Storage {
    private static string $monitorsFile = CM_DATA_DIR . '/monitors.json';
    private static string $trashFile = CM_DATA_DIR . '/trash.json';
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
            $groupVal = trim($monitor['group'] ?? '');
            $monitor['group'] = ($groupVal === '' || strcasecmp($groupVal, 'ungrouped') === 0) ? 'Ungrouped' : $groupVal;
            $monitor['last_status_code'] = null;
            $monitor['last_check_at'] = null;
            $monitor['last_change_at'] = null;
            $monitor['last_snapshot'] = '';
            $monitor['last_hash'] = '';
            $monitor['last_error'] = null;
        } else {
            $existing = $monitors[$monitor['id']] ?? [];
            if (isset($monitor['group'])) {
                $groupVal = trim((string)$monitor['group']);
                $monitor['group'] = ($groupVal === '' || strcasecmp($groupVal, 'ungrouped') === 0) ? 'Ungrouped' : $groupVal;
            }
            $monitor = array_merge($existing, $monitor);
            $monitor['updated_at'] = date('c');
        }

        $monitors[$monitor['id']] = $monitor;
        self::writeJson(self::$monitorsFile, $monitors);
        return $monitor['id'];
    }

    public static function saveMonitorsBulk(array $monitorsList): array {
        $monitors = self::getMonitors();
        $savedIds = [];
        $now = date('c');

        foreach ($monitorsList as $monitor) {
            if (empty($monitor['url'])) {
                continue;
            }
            $groupVal = trim($monitor['group'] ?? '');
            $normalizedGroup = ($groupVal === '' || strcasecmp($groupVal, 'ungrouped') === 0) ? 'Ungrouped' : $groupVal;

            if (empty($monitor['id'])) {
                $id = 'mon_' . bin2hex(random_bytes(6));
                $monitor['id'] = $id;
                $monitor['created_at'] = $now;
                $monitor['check_count'] = 0;
                $monitor['change_count'] = 0;
                $monitor['status'] = $monitor['status'] ?? 'active';
                $monitor['group'] = $normalizedGroup;
                $monitor['last_status_code'] = null;
                $monitor['last_check_at'] = null;
                $monitor['last_change_at'] = null;
                $monitor['last_snapshot'] = '';
                $monitor['last_hash'] = '';
                $monitor['last_error'] = null;
            } else {
                $id = $monitor['id'];
                $existing = $monitors[$id] ?? [];
                $monitor['group'] = $normalizedGroup;
                $monitor = array_merge($existing, $monitor);
                $monitor['updated_at'] = $now;
            }

            $monitors[$id] = $monitor;
            $savedIds[] = $id;
        }

        if (!empty($savedIds)) {
            self::writeJson(self::$monitorsFile, $monitors);
        }

        return $savedIds;
    }

    public static function getGroups(): array {
        $monitors = self::getMonitors();
        $groups = [];
        foreach ($monitors as $m) {
            $rawGrp = trim($m['group'] ?? '');
            $grp = ($rawGrp === '' || strcasecmp($rawGrp, 'ungrouped') === 0) ? 'Ungrouped' : $rawGrp;
            $groups[$grp] = ($groups[$grp] ?? 0) + 1;
        }
        ksort($groups, SORT_NATURAL | SORT_FLAG_CASE);
        return $groups;
    }

    public static function bulkAssignGroup(array $ids, string $group): int {
        $rawGroup = trim($group);
        $group = ($rawGroup === '' || strcasecmp($rawGroup, 'ungrouped') === 0) ? 'Ungrouped' : $rawGroup;
        $monitors = self::getMonitors();
        $updated = 0;
        $now = date('c');

        foreach ($ids as $id) {
            if (isset($monitors[$id])) {
                $monitors[$id]['group'] = $group;
                $monitors[$id]['updated_at'] = $now;
                $updated++;
            }
        }

        if ($updated > 0) {
            self::writeJson(self::$monitorsFile, $monitors);
        }
        return $updated;
    }

    /**
     * Bulk Edit multiple monitor fields simultaneously
     */
    public static function bulkEditMonitors(array $ids, array $fields): int {
        $monitors = self::getMonitors();
        $updated = 0;
        $now = date('c');

        foreach ($ids as $id) {
            if (!isset($monitors[$id])) {
                continue;
            }

            if (isset($fields['group'])) {
                $rawGroup = trim((string)$fields['group']);
                $monitors[$id]['group'] = ($rawGroup === '' || strcasecmp($rawGroup, 'ungrouped') === 0) ? 'Ungrouped' : $rawGroup;
            }
            if (isset($fields['status']) && in_array($fields['status'], ['active', 'paused'], true)) {
                $monitors[$id]['status'] = $fields['status'];
            }
            if (isset($fields['interval_mins'])) {
                $monitors[$id]['interval_mins'] = max(1, (int)$fields['interval_mins']);
            }
            if (isset($fields['browser_template']) && !empty($fields['browser_template'])) {
                $monitors[$id]['browser_template'] = (string)$fields['browser_template'];
            }
            if (isset($fields['type']) && !empty($fields['type'])) {
                $monitors[$id]['type'] = (string)$fields['type'];
            }
            if (isset($fields['selector'])) {
                $monitors[$id]['selector'] = trim((string)$fields['selector']);
            }
            if (isset($fields['ignore_selector'])) {
                $monitors[$id]['ignore_selector'] = trim((string)$fields['ignore_selector']);
            }
            if (isset($fields['timeout'])) {
                $monitors[$id]['timeout'] = max(5, min(60, (int)$fields['timeout']));
            }
            if (isset($fields['strip_tags'])) {
                $monitors[$id]['strip_tags'] = !empty($fields['strip_tags']);
            }
            if (isset($fields['notify_on_change'])) {
                $monitors[$id]['notify_on_change'] = !empty($fields['notify_on_change']);
            }
            if (isset($fields['notify_on_error'])) {
                $monitors[$id]['notify_on_error'] = !empty($fields['notify_on_error']);
            }
            if (isset($fields['peak_schedule_enabled'])) {
                $monitors[$id]['peak_schedule_enabled'] = !empty($fields['peak_schedule_enabled']);
            }
            if (isset($fields['peak_start_hour'])) {
                $monitors[$id]['peak_start_hour'] = max(0, min(23, (int)$fields['peak_start_hour']));
            }
            if (isset($fields['peak_end_hour'])) {
                $monitors[$id]['peak_end_hour'] = max(0, min(23, (int)$fields['peak_end_hour']));
            }
            if (isset($fields['peak_interval_mins'])) {
                $monitors[$id]['peak_interval_mins'] = max(1, (int)$fields['peak_interval_mins']);
            }
            if (isset($fields['offpeak_interval_mins'])) {
                $monitors[$id]['offpeak_interval_mins'] = max(1, (int)$fields['offpeak_interval_mins']);
            }

            $monitors[$id]['updated_at'] = $now;
            $updated++;
        }

        if ($updated > 0) {
            self::writeJson(self::$monitorsFile, $monitors);
        }
        return $updated;
    }

    public static function bulkUpdateStatus(array $ids, string $status): int {
        if (!in_array($status, ['active', 'paused'], true)) {
            return 0;
        }
        $monitors = self::getMonitors();
        $updated = 0;
        $now = date('c');

        foreach ($ids as $id) {
            if (isset($monitors[$id])) {
                $monitors[$id]['status'] = $status;
                $monitors[$id]['updated_at'] = $now;
                $updated++;
            }
        }

        if ($updated > 0) {
            self::writeJson(self::$monitorsFile, $monitors);
        }
        return $updated;
    }

    // --- Soft Delete & Trash / Restore Functionality ---

    public static function getTrash(): array {
        return self::readJson(self::$trashFile, []);
    }

    public static function deleteMonitor(string $id): bool {
        $monitors = self::getMonitors();
        if (isset($monitors[$id])) {
            $deletedMonitor = $monitors[$id];
            $deletedMonitor['deleted_at'] = date('c');
            unset($monitors[$id]);
            self::writeJson(self::$monitorsFile, $monitors);

            // Move to trash
            $trash = self::getTrash();
            $trash[$id] = $deletedMonitor;
            self::writeJson(self::$trashFile, $trash);
            return true;
        }
        return false;
    }

    public static function bulkDeleteMonitors(array $ids): int {
        $monitors = self::getMonitors();
        $trash = self::getTrash();
        $deleted = 0;
        $now = date('c');

        foreach ($ids as $id) {
            if (isset($monitors[$id])) {
                $item = $monitors[$id];
                $item['deleted_at'] = $now;
                $trash[$id] = $item;
                unset($monitors[$id]);
                $deleted++;
            }
        }

        if ($deleted > 0) {
            self::writeJson(self::$monitorsFile, $monitors);
            self::writeJson(self::$trashFile, $trash);
        }
        return $deleted;
    }

    public static function restoreMonitor(string $id): bool {
        $trash = self::getTrash();
        if (isset($trash[$id])) {
            $item = $trash[$id];
            unset($item['deleted_at']);
            $item['updated_at'] = date('c');
            unset($trash[$id]);
            self::writeJson(self::$trashFile, $trash);

            $monitors = self::getMonitors();
            $monitors[$id] = $item;
            self::writeJson(self::$monitorsFile, $monitors);
            return true;
        }
        return false;
    }

    public static function bulkRestoreMonitors(array $ids): int {
        $trash = self::getTrash();
        $monitors = self::getMonitors();
        $restored = 0;
        $now = date('c');

        foreach ($ids as $id) {
            if (isset($trash[$id])) {
                $item = $trash[$id];
                unset($item['deleted_at']);
                $item['updated_at'] = $now;
                $monitors[$id] = $item;
                unset($trash[$id]);
                $restored++;
            }
        }

        if ($restored > 0) {
            self::writeJson(self::$trashFile, $trash);
            self::writeJson(self::$monitorsFile, $monitors);
        }
        return $restored;
    }

    public static function purgeDeletedMonitor(string $id): bool {
        $trash = self::getTrash();
        if (isset($trash[$id])) {
            unset($trash[$id]);
            self::writeJson(self::$trashFile, $trash);
            return true;
        }
        return false;
    }

    public static function emptyTrash(): int {
        $trash = self::getTrash();
        $count = count($trash);
        self::writeJson(self::$trashFile, []);
        return $count;
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
    public static function getLogs(string $search = '', string $level = '', string $category = '', string|int $date = '', int $limit = 200): array {
        if (is_int($date)) {
            $limit = $date;
            $date = '';
        }

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
        $date = trim((string)$date);

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

            // Filter Date
            if ($date !== '') {
                $rowDate = !empty($row['timestamp']) ? substr($row['timestamp'], 0, 10) : '';
                if ($rowDate !== $date) {
                    continue;
                }
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
     * Clear application error log and reset error statistics
     */
    public static function clearLogs(): bool {
        $logFile = CM_LOGS_DIR . '/error.log';
        if (file_exists($logFile)) {
            @unlink($logFile);
        }

        // Reset error stats
        $stats = self::getStats();
        $stats['total_errors'] = 0;
        if (isset($stats['checks_by_date']) && is_array($stats['checks_by_date'])) {
            foreach ($stats['checks_by_date'] as &$day) {
                $day['errors'] = 0;
            }
            unset($day);
        }
        if (isset($stats['monitors_stats']) && is_array($stats['monitors_stats'])) {
            foreach ($stats['monitors_stats'] as &$mStat) {
                $mStat['errors'] = 0;
            }
            unset($mStat);
        }
        self::writeJson(self::$statsFile, $stats);
        return true;
    }

    /**
     * Delete specific log entries by their unique IDs and decrement error stats
     */
    public static function deleteLogsByIds(array $logIds): int {
        $logFile = CM_LOGS_DIR . '/error.log';
        if (!file_exists($logFile) || empty($logIds)) {
            return 0;
        }

        $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!$lines) return 0;

        $idSet = array_flip($logIds);
        $keptLines = [];
        $deletedCount = 0;
        $deletedErrorDates = [];

        foreach ($lines as $line) {
            $row = json_decode($line, true);
            if ($row && isset($row['id']) && isset($idSet[$row['id']])) {
                $deletedCount++;
                $dt = !empty($row['timestamp']) ? substr($row['timestamp'], 0, 10) : date('Y-m-d');
                $deletedErrorDates[$dt] = ($deletedErrorDates[$dt] ?? 0) + 1;
                continue;
            }
            $keptLines[] = $line;
        }

        if ($deletedCount > 0) {
            file_put_contents($logFile, implode("\n", $keptLines) . (empty($keptLines) ? "" : "\n"), LOCK_EX);

            // Decrement total_errors and daily errors
            $stats = self::getStats();
            $stats['total_errors'] = max(0, ($stats['total_errors'] ?? 0) - $deletedCount);
            foreach ($deletedErrorDates as $date => $cnt) {
                if (isset($stats['checks_by_date'][$date]['errors'])) {
                    $stats['checks_by_date'][$date]['errors'] = max(0, $stats['checks_by_date'][$date]['errors'] - $cnt);
                }
            }
            self::writeJson(self::$statsFile, $stats);
        }

        return $deletedCount;
    }

    /**
     * Clear error logs by level or category and decrement error stats
     */
    public static function clearLogsByFilter(string $level = '', string $category = '', string $search = ''): int {
        $logFile = CM_LOGS_DIR . '/error.log';
        if (!file_exists($logFile)) {
            return 0;
        }

        $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!$lines) return 0;

        $search = strtolower(trim($search));
        $level = strtoupper(trim($level));
        $category = strtoupper(trim($category));

        $keptLines = [];
        $deletedCount = 0;
        $deletedErrorDates = [];

        foreach ($lines as $line) {
            $row = json_decode($line, true);
            if (!$row) continue;

            $match = true;
            if ($level !== '' && ($row['level'] ?? '') !== $level) {
                $match = false;
            }
            if ($category !== '' && ($row['category'] ?? '') !== $category) {
                $match = false;
            }
            if ($search !== '') {
                $rawString = strtolower(($row['message'] ?? '') . ' ' . json_encode($row['context'] ?? []));
                if (!str_contains($rawString, $search)) {
                    $match = false;
                }
            }

            if ($match) {
                $deletedCount++;
                $dt = !empty($row['timestamp']) ? substr($row['timestamp'], 0, 10) : date('Y-m-d');
                $deletedErrorDates[$dt] = ($deletedErrorDates[$dt] ?? 0) + 1;
            } else {
                $keptLines[] = $line;
            }
        }

        if ($deletedCount > 0) {
            file_put_contents($logFile, implode("\n", $keptLines) . (empty($keptLines) ? "" : "\n"), LOCK_EX);

            $stats = self::getStats();
            if ($level === '' && $category === '' && $search === '') {
                // All logs cleared
                $stats['total_errors'] = 0;
                if (isset($stats['checks_by_date'])) {
                    foreach ($stats['checks_by_date'] as &$day) {
                        $day['errors'] = 0;
                    }
                    unset($day);
                }
            } else {
                $stats['total_errors'] = max(0, ($stats['total_errors'] ?? 0) - $deletedCount);
                foreach ($deletedErrorDates as $date => $cnt) {
                    if (isset($stats['checks_by_date'][$date]['errors'])) {
                        $stats['checks_by_date'][$date]['errors'] = max(0, $stats['checks_by_date'][$date]['errors'] - $cnt);
                    }
                }
            }
            self::writeJson(self::$statsFile, $stats);
        }

        return $deletedCount;
    }

    public static function saveSnapshot(string $monitorId, string $content, bool $isChange = true): string {
        $dir = CM_HISTORY_DIR . '/' . $monitorId;
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        // Always save latest snapshot
        file_put_contents($dir . '/latest.txt', $content, LOCK_EX);

        // Only create new timestamped snapshot archive when change detected or initial
        if ($isChange) {
            $baseName = 'snap_' . date('Ymd_His') . '.txt';
            $archiveFile = $dir . '/' . $baseName;
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

            return $baseName;
        }

        // When no change, return latest existing snapshot file name or latest.txt
        $files = glob($dir . '/snap_*.txt');
        if ($files) {
            rsort($files);
            return basename($files[0]);
        }

        return 'latest.txt';
    }

    /**
     * Save structured change event record for a monitor
     */
    public static function saveChangeEvent(
        string $monitorId,
        string $oldSnapshot,
        string $newSnapshot,
        array $diffData,
        string $oldSnapFile = '',
        string $newSnapFile = ''
    ): array {
        $dir = CM_HISTORY_DIR . '/' . $monitorId;
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        $changesFile = $dir . '/changes.json';
        $changes = file_exists($changesFile) ? (json_decode(file_get_contents($changesFile), true) ?: []) : [];

        $monitor = self::getMonitor($monitorId) ?? ['name' => 'Target ' . $monitorId, 'group' => 'Ungrouped', 'type' => 'html_full'];

        $eventId = 'chg_' . uniqid('', true);
        $event = [
            'id' => $eventId,
            'monitor_id' => $monitorId,
            'monitor_name' => $monitor['name'] ?? 'Target',
            'group' => $monitor['group'] ?? 'Ungrouped',
            'type' => $monitor['type'] ?? 'html_full',
            'timestamp' => date('c'),
            'old_hash' => hash('sha256', $oldSnapshot),
            'new_hash' => hash('sha256', $newSnapshot),
            'old_snapshot_file' => $oldSnapFile,
            'new_snapshot_file' => $newSnapFile,
            'old_size' => strlen($oldSnapshot),
            'new_size' => strlen($newSnapshot),
            'added_count' => (int)($diffData['added_count'] ?? 0),
            'removed_count' => (int)($diffData['removed_count'] ?? 0),
            'total_changed' => (int)($diffData['total_changed'] ?? 0),
            'is_big_change' => !empty($diffData['is_big_change']),
            'archived' => false,
            'old_snapshot' => $oldSnapshot,
            'new_snapshot' => $newSnapshot,
        ];

        // Store event (max 50 per monitor)
        array_unshift($changes, $event);
        if (count($changes) > 50) {
            $changes = array_slice($changes, 0, 50);
        }

        file_put_contents($changesFile, json_encode($changes, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), LOCK_EX);
        return $event;
    }

    /**
     * Get list of detected change events (all monitors or specific monitor)
     */
    public static function getChangeEvents(
        ?string $monitorId = null,
        bool $includeArchived = false,
        string $search = '',
        string|int $date = '',
        int $limit = 100
    ): array {
        if (is_int($date)) {
            $limit = $date;
            $date = '';
        }

        $events = [];
        $monitors = self::getMonitors();

        if (!empty($monitorId)) {
            $targetDirs = [CM_HISTORY_DIR . '/' . $monitorId];
        } else {
            $targetDirs = glob(CM_HISTORY_DIR . '/*', GLOB_ONLYDIR) ?: [];
        }

        $search = strtolower(trim($search));
        $date = trim((string)$date);

        foreach ($targetDirs as $dir) {
            $mId = basename($dir);
            $changesFile = $dir . '/changes.json';
            if (!file_exists($changesFile)) {
                continue;
            }

            $mChanges = json_decode(file_get_contents($changesFile), true);
            if (!is_array($mChanges)) {
                continue;
            }

            $mInfo = $monitors[$mId] ?? null;

            foreach ($mChanges as $chg) {
                if (!$includeArchived && !empty($chg['archived'])) {
                    continue;
                }

                // Filter Date
                if ($date !== '') {
                    $itemDate = !empty($chg['timestamp']) ? substr($chg['timestamp'], 0, 10) : '';
                    if ($itemDate !== $date) {
                        continue;
                    }
                }

                // Sync current monitor metadata if changed
                if ($mInfo) {
                    $chg['monitor_name'] = $mInfo['name'] ?? $chg['monitor_name'];
                    $chg['group'] = $mInfo['group'] ?? $chg['group'];
                }

                if ($search !== '') {
                    $haystack = strtolower(($chg['monitor_name'] ?? '') . ' ' . ($chg['group'] ?? '') . ' ' . ($chg['id'] ?? ''));
                    if (!str_contains($haystack, $search)) {
                        continue;
                    }
                }

                // Don't include huge snapshot bodies in list overview for performance
                $listEvent = $chg;
                unset($listEvent['old_snapshot'], $listEvent['new_snapshot']);
                $events[] = $listEvent;
            }
        }

        // Sort by timestamp descending
        usort($events, function($a, $b) {
            return strcmp($b['timestamp'] ?? '', $a['timestamp'] ?? '');
        });

        if (count($events) > $limit) {
            $events = array_slice($events, 0, $limit);
        }

        return $events;
    }

    /**
     * Get a single change event by ID (including full old/new snapshots)
     */
    public static function getChangeEvent(string $eventId, ?string $monitorId = null): ?array {
        if (!empty($monitorId)) {
            $dirs = [CM_HISTORY_DIR . '/' . $monitorId];
        } else {
            $dirs = glob(CM_HISTORY_DIR . '/*', GLOB_ONLYDIR) ?: [];
        }

        foreach ($dirs as $dir) {
            $changesFile = $dir . '/changes.json';
            if (!file_exists($changesFile)) continue;
            $items = json_decode(file_get_contents($changesFile), true);
            if (!is_array($items)) continue;

            foreach ($items as $item) {
                if (($item['id'] ?? '') === $eventId) {
                    return $item;
                }
            }
        }

        return null;
    }

    /**
     * Archive or Unarchive change events
     */
    public static function archiveChangeEvents(array $eventIds, bool $archive = true): int {
        if (empty($eventIds)) return 0;
        $idSet = array_flip($eventIds);
        $updatedCount = 0;

        $dirs = glob(CM_HISTORY_DIR . '/*', GLOB_ONLYDIR) ?: [];
        foreach ($dirs as $dir) {
            $changesFile = $dir . '/changes.json';
            if (!file_exists($changesFile)) continue;
            $items = json_decode(file_get_contents($changesFile), true);
            if (!is_array($items)) continue;

            $modified = false;
            foreach ($items as &$item) {
                if (isset($idSet[$item['id'] ?? ''])) {
                    $item['archived'] = $archive;
                    $modified = true;
                    $updatedCount++;
                }
            }
            unset($item);

            if ($modified) {
                file_put_contents($changesFile, json_encode($items, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), LOCK_EX);
            }
        }

        return $updatedCount;
    }

    /**
     * Delete specific change events and decrement change stats
     */
    public static function deleteChangeEvents(array $eventIds): int {
        if (empty($eventIds)) return 0;
        $idSet = array_flip($eventIds);
        $deletedCount = 0;
        $deletedChangeDates = [];

        $dirs = glob(CM_HISTORY_DIR . '/*', GLOB_ONLYDIR) ?: [];
        foreach ($dirs as $dir) {
            $changesFile = $dir . '/changes.json';
            if (!file_exists($changesFile)) continue;
            $items = json_decode(file_get_contents($changesFile), true);
            if (!is_array($items)) continue;

            $kept = [];
            $modified = false;
            foreach ($items as $item) {
                if (isset($idSet[$item['id'] ?? ''])) {
                    $modified = true;
                    $deletedCount++;
                    $dt = !empty($item['timestamp']) ? substr($item['timestamp'], 0, 10) : date('Y-m-d');
                    $deletedChangeDates[$dt] = ($deletedChangeDates[$dt] ?? 0) + 1;
                } else {
                    $kept[] = $item;
                }
            }

            if ($modified) {
                file_put_contents($changesFile, json_encode($kept, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), LOCK_EX);
            }
        }

        if ($deletedCount > 0) {
            $stats = self::getStats();
            $stats['total_changes'] = max(0, ($stats['total_changes'] ?? 0) - $deletedCount);
            foreach ($deletedChangeDates as $date => $cnt) {
                if (isset($stats['checks_by_date'][$date]['changes'])) {
                    $stats['checks_by_date'][$date]['changes'] = max(0, $stats['checks_by_date'][$date]['changes'] - $cnt);
                }
            }
            self::writeJson(self::$statsFile, $stats);
        }

        return $deletedCount;
    }

    /**
     * Clear all change records for all or a specific monitor and reset change stats
     */
    public static function clearAllChanges(?string $monitorId = null): int {
        if (!empty($monitorId)) {
            $dirs = [CM_HISTORY_DIR . '/' . $monitorId];
        } else {
            $dirs = glob(CM_HISTORY_DIR . '/*', GLOB_ONLYDIR) ?: [];
        }

        $totalCleared = 0;
        foreach ($dirs as $dir) {
            $changesFile = $dir . '/changes.json';
            if (file_exists($changesFile)) {
                $items = json_decode(file_get_contents($changesFile), true) ?: [];
                $totalCleared += count($items);
                @unlink($changesFile);
            }
        }

        if ($totalCleared > 0) {
            $stats = self::getStats();
            if (empty($monitorId)) {
                $stats['total_changes'] = 0;
                if (isset($stats['checks_by_date'])) {
                    foreach ($stats['checks_by_date'] as &$day) {
                        $day['changes'] = 0;
                    }
                    unset($day);
                }
                if (isset($stats['monitors_stats'])) {
                    foreach ($stats['monitors_stats'] as &$mStat) {
                        $mStat['changes'] = 0;
                    }
                    unset($mStat);
                }
            } else {
                $stats['total_changes'] = max(0, ($stats['total_changes'] ?? 0) - $totalCleared);
                if (isset($stats['monitors_stats'][$monitorId])) {
                    $stats['monitors_stats'][$monitorId]['changes'] = 0;
                }
            }
            self::writeJson(self::$statsFile, $stats);
        }

        return $totalCleared;
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

        // Read change events to map snapshot files to change status
        $changesFile = $dir . '/changes.json';
        $changes = file_exists($changesFile) ? (json_decode(file_get_contents($changesFile), true) ?: []) : [];
        $fileChangeMap = [];
        foreach ($changes as $chg) {
            $newFile = $chg['new_snapshot_file'] ?? '';
            if (!empty($newFile)) {
                $status = !empty($chg['is_big_change']) ? 'Big Change' : 'Small Change';
                $fileChangeMap[$newFile] = $status;
            }
        }

        $list = [];
        $totalFiles = count($files);

        // Compute adjacent snapshot diffs to accurately identify change status
        $snapshotContents = [];
        foreach ($files as $filePath) {
            $baseName = basename($filePath);
            $snapshotContents[$baseName] = file_get_contents($filePath) ?: '';
        }

        require_once __DIR__ . '/diff_formatter.php';
        $monitor = self::getMonitor($monitorId) ?? ['type' => 'html_full'];
        $type = $monitor['type'] ?? 'html_full';

        foreach ($files as $idx => $filePath) {
            $baseName = basename($filePath);
            // snap_20260917_002530.txt -> formatted date
            if (preg_match('/^snap_(\d{4})(\d{2})(\d{2})_(\d{2})(\d{2})(\d{2})\.txt$/', $baseName, $m)) {
                $formattedTime = "{$m[1]}-{$m[2]}-{$m[3]} {$m[4]}:{$m[5]}:{$m[6]}";
            } else {
                $formattedTime = date('Y-m-d H:i:s', filemtime($filePath));
            }

            // Determine change status label
            if (isset($fileChangeMap[$baseName])) {
                $changeStatus = $fileChangeMap[$baseName];
            } elseif ($idx === $totalFiles - 1) {
                $changeStatus = 'Baseline';
            } else {
                // Compare with older adjacent snapshot ($idx + 1)
                $prevFile = basename($files[$idx + 1]);
                $currContent = $snapshotContents[$baseName] ?? '';
                $prevContent = $snapshotContents[$prevFile] ?? '';

                if (hash('sha256', $currContent) === hash('sha256', $prevContent)) {
                    $changeStatus = 'No Change';
                } else {
                    $diff = DiffFormatter::computeContextualDiff($prevContent, $currContent, $type, 30);
                    $changeStatus = !empty($diff['is_big_change']) ? 'Big Change' : 'Small Change';
                }
            }

            $list[] = [
                'file' => $baseName,
                'time' => $formattedTime,
                'size' => filesize($filePath),
                'change_status' => $changeStatus,
                'is_recent' => ($idx === 0),
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

    /**
     * Count unarchived / active detected changes
     */
    public static function countActiveChanges(?string $monitorId = null): int {
        $events = self::getChangeEvents(monitorId: $monitorId, includeArchived: false, limit: 10000);
        return count($events);
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

