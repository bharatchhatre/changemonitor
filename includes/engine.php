<?php
/**
 * Monitor Execution Engine & Runner
 * Website Change Monitor
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/storage.php';
require_once __DIR__ . '/fetcher.php';
require_once __DIR__ . '/extractor.php';
require_once __DIR__ . '/notifier.php';

class Engine {
    /**
     * Calculate active interval for a monitor based on peak/active hours
     */
    public static function getActiveIntervalMins(array $monitor): int {
        $baseInterval = (int)($monitor['interval_mins'] ?? 15);
        
        // If peak schedule is enabled for this monitor
        if (!empty($monitor['peak_schedule_enabled'])) {
            $currentHour = (int)date('G'); // 0-23 in configured app timezone
            $peakStart = (int)($monitor['peak_start_hour'] ?? 9);
            $peakEnd = (int)($monitor['peak_end_hour'] ?? 18);
            $peakInterval = max(1, (int)($monitor['peak_interval_mins'] ?? 5));
            $offPeakInterval = max(1, (int)($monitor['offpeak_interval_mins'] ?? 60));

            $isPeak = false;
            if ($peakStart <= $peakEnd) {
                // e.g. 9:00 to 18:00
                $isPeak = ($currentHour >= $peakStart && $currentHour < $peakEnd);
            } else {
                // Overnight e.g. 22:00 to 6:00
                $isPeak = ($currentHour >= $peakStart || $currentHour < $peakEnd);
            }

            return $isPeak ? $peakInterval : $offPeakInterval;
        }

        return max(1, $baseInterval);
    }

    /**
     * Execute a single monitor check
     *
     * @param string $monitorId
     * @param bool $forceCheck
     * @return array [ 'success' => bool, 'changed' => bool, 'error' => ?string, 'diff' => ?string, 'duration_ms' => float ]
     */
    public static function run(string $monitorId, bool $forceCheck = false): array {
        $monitor = Storage::getMonitor($monitorId);
        if (!$monitor) {
            return ['success' => false, 'changed' => false, 'error' => 'Monitor not found'];
        }

        // If not force check, verify dynamic interval
        if (!$forceCheck) {
            $effectiveInterval = self::getActiveIntervalMins($monitor);
            $lastCheck = !empty($monitor['last_check_at']) ? strtotime($monitor['last_check_at']) : 0;
            if (time() - $lastCheck < ($effectiveInterval * 60)) {
                return ['success' => true, 'changed' => false, 'skipped' => true, 'message' => "Interval not elapsed yet ({$effectiveInterval}m dynamic schedule)"];
            }
        }

        $url = $monitor['url'];
        $type = $monitor['type'] ?? 'html_full';
        $selector = $monitor['selector'] ?? '';
        $template = $monitor['browser_template'] ?? 'chrome_mac';
        $customHeaders = $monitor['custom_headers'] ?? '';
        $cookies = $monitor['cookies'] ?? '';
        $postData = $monitor['post_data'] ?? '';
        $method = $monitor['method'] ?? 'GET';
        $proxy = $monitor['proxy'] ?? '';

        // Perform HTTP Request
        $fetchResult = Fetcher::request([
            'url' => $url,
            'method' => $method,
            'template' => $template,
            'custom_headers' => $customHeaders,
            'cookies' => $cookies,
            'post_data' => $postData,
            'proxy' => $proxy,
            'timeout' => (int)($monitor['timeout'] ?? 25),
            'follow_redirects' => !empty($monitor['follow_redirects'] ?? true),
            'simulate_delay' => !empty($monitor['simulate_delay'] ?? false),
        ]);

        $httpCode = $fetchResult['http_code'];
        $durationMs = $fetchResult['duration_ms'];
        $now = date('c');

        $settings = Storage::getSettings();

        // Handle HTTP Fetch Error
        if (!$fetchResult['success']) {
            $errorMsg = $fetchResult['error'] ?? "HTTP Request failed ($httpCode)";
            $monitor['last_check_at'] = $now;
            $monitor['last_status_code'] = $httpCode;
            $monitor['last_error'] = $errorMsg;
            $monitor['check_count'] = ($monitor['check_count'] ?? 0) + 1;
            Storage::saveMonitor($monitor);

            Storage::logHistory($monitorId, 'ERROR', "HTTP $httpCode - $errorMsg");
            Storage::recordCheckStats($monitorId, false, false, $durationMs, $httpCode);

            // Send error notification if enabled
            if (!empty($settings['notify_on_error']) && !empty($monitor['notify_on_error'])) {
                Notifier::dispatch(
                    "⚠️ Monitor Error: {$monitor['name']}",
                    "Failed to check target [{$monitor['name']}]\nURL: {$url}\nStatus Code: {$httpCode}\nError: {$errorMsg}",
                    ['url' => $url]
                );
            }

            return [
                'success' => false,
                'changed' => false,
                'http_code' => $httpCode,
                'error' => $errorMsg,
                'duration_ms' => $durationMs,
            ];
        }

        // Perform Content Extraction
        $extractOptions = [
            'strip_tags' => !empty($monitor['strip_tags']),
            'trim_whitespace' => true,
        ];
        $extractResult = Extractor::extract($fetchResult['body'], $type, $selector, $fetchResult['headers'], $extractOptions);

        if (!$extractResult['success']) {
            $errorMsg = "Extraction Error: " . $extractResult['error'];
            $monitor['last_check_at'] = $now;
            $monitor['last_status_code'] = $httpCode;
            $monitor['last_error'] = $errorMsg;
            $monitor['check_count'] = ($monitor['check_count'] ?? 0) + 1;
            Storage::saveMonitor($monitor);

            Storage::logHistory($monitorId, 'EXTRACTION_ERROR', $errorMsg);
            Storage::recordCheckStats($monitorId, false, false, $durationMs, $httpCode);

            if (!empty($settings['notify_on_error']) && !empty($monitor['notify_on_error'])) {
                Notifier::dispatch(
                    "⚠️ Selector Error: {$monitor['name']}",
                    "Target extraction failed for [{$monitor['name']}]\nURL: {$url}\nError: {$errorMsg}",
                    ['url' => $url]
                );
            }

            return [
                'success' => false,
                'changed' => false,
                'http_code' => $httpCode,
                'error' => $errorMsg,
                'duration_ms' => $durationMs,
            ];
        }

        $newSnapshot = $extractResult['extracted'];
        $newHash = hash('sha256', $newSnapshot);
        $oldSnapshot = $monitor['last_snapshot'] ?? '';
        $oldHash = $monitor['last_hash'] ?? '';

        $isInitial = empty($oldHash);
        $hasChanged = !$isInitial && ($newHash !== $oldHash);

        $diff = null;
        if ($hasChanged) {
            $diffResult = Extractor::computeDiff($oldSnapshot, $newSnapshot);
            $diff = $diffResult['raw'];
        }

        // Update Monitor State
        $monitor['last_check_at'] = $now;
        $monitor['last_status_code'] = $httpCode;
        $monitor['last_error'] = null;
        $monitor['check_count'] = ($monitor['check_count'] ?? 0) + 1;
        $monitor['last_snapshot'] = $newSnapshot;
        $monitor['last_hash'] = $newHash;

        if ($hasChanged) {
            $monitor['change_count'] = ($monitor['change_count'] ?? 0) + 1;
            $monitor['last_change_at'] = $now;
        }

        Storage::saveMonitor($monitor);
        $newSnapFile = Storage::saveSnapshot($monitorId, $newSnapshot);
        Storage::recordCheckStats($monitorId, true, $hasChanged, $durationMs, $httpCode);

        // History Logging & Notifications
        if ($isInitial) {
            Storage::logHistory($monitorId, 'INITIALIZED', "Initial baseline snapshot captured (size: " . strlen($newSnapshot) . " chars)");
        } elseif ($hasChanged) {
            // Compute rich contextual diff
            require_once __DIR__ . '/diff_formatter.php';
            $threshold = (int)($settings['diff_big_change_threshold_lines'] ?? 30);
            $diffData = DiffFormatter::computeContextualDiff($oldSnapshot, $newSnapshot, $type, $threshold);

            // Save structured change event
            Storage::saveChangeEvent($monitorId, $oldSnapshot, $newSnapshot, $diffData, '', $newSnapFile);

            Storage::logHistory($monitorId, 'CHANGE_DETECTED', "Change detected. New hash: " . substr($newHash, 0, 10), $diff);

            if (!empty($settings['notify_on_change']) && !empty($monitor['notify_on_change'] ?? true)) {
                $msg = "Change detected on target: *{$monitor['name']}*\nChecked At: " . date('Y-m-d H:i:s') . "\nExtraction Mode: {$type}";
                Notifier::dispatch(
                    "🚨 Change Detected: {$monitor['name']}",
                    $msg,
                    [
                        'url' => $url,
                        'diff' => $diff,
                        'old_snapshot' => $oldSnapshot,
                        'new_snapshot' => $newSnapshot,
                        'diff_data' => $diffData,
                        'type' => $type,
                        'monitor' => $monitor,
                    ]
                );
            }
        } else {
            Storage::logHistory($monitorId, 'NO_CHANGE', "Check passed. Content identical (hash: " . substr($newHash, 0, 10) . ")");
        }

        return [
            'success' => true,
            'changed' => $hasChanged,
            'is_initial' => $isInitial,
            'http_code' => $httpCode,
            'duration_ms' => $durationMs,
            'diff' => $diff,
            'snapshot_length' => strlen($newSnapshot),
            'extracted_preview' => substr($newSnapshot, 0, 500),
        ];
    }

    /**
     * Run all active monitors sequentially
     */
    public static function runAll(bool $forceCheck = false): array {
        $monitors = Storage::getMonitors();
        $results = [];

        foreach ($monitors as $id => $mon) {
            if (($mon['status'] ?? 'active') !== 'active') {
                continue;
            }
            $results[$id] = self::run($id, $forceCheck);
            // Small pause between targets to prevent shared host spikes
            usleep(200000);
        }

        return $results;
    }
}
