<?php
/**
 * Standalone Cron Job Runner (CLI & Webhook)
 * Website Change Monitor
 *
 * cPanel Cron Command Example:
 * /usr/local/bin/php /home/username/public_html/cron.php
 * Or via Webhook:
 * https://yourdomain.com/cron.php?token=YOUR_CRON_TOKEN
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/storage.php';
require_once __DIR__ . '/../includes/fetcher.php';
require_once __DIR__ . '/../includes/extractor.php';
require_once __DIR__ . '/../includes/notifier.php';
require_once __DIR__ . '/../includes/engine.php';

$isCli = (php_sapi_name() === 'cli' || defined('STDIN'));

if (!$isCli) {
    // Authenticate Webhook Trigger via Secret Token
    $expectedToken = cm_env('CRON_TOKEN', 'cron_secret_token_123');
    $providedToken = $_GET['token'] ?? $_SERVER['HTTP_X_CRON_TOKEN'] ?? '';

    if (empty($providedToken) || !hash_equals($expectedToken, $providedToken)) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Forbidden: Invalid or missing cron token']);
        exit;
    }

    header('Content-Type: application/json');
}

$startTime = microtime(true);
$results = Engine::runAll(false); // only checks if interval has elapsed
$duration = round(microtime(true) - $startTime, 2);

$summary = [
    'status' => 'completed',
    'timestamp' => date('Y-m-d H:i:s T'),
    'duration_seconds' => $duration,
    'monitors_checked' => count($results),
    'details' => $results,
];

if ($isCli) {
    echo "[" . date('Y-m-d H:i:s') . "] Cron completed in {$duration}s. Checked " . count($results) . " monitors.\n";
} else {
    echo json_encode($summary, JSON_PRETTY_PRINT);
}
