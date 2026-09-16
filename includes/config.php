<?php
/**
 * Configuration & Environment Loader
 * Website Change Monitor
 */

declare(strict_types=1);

define('CM_VERSION', '1.0.0');
define('CM_ROOT', dirname(__DIR__));

// Load .env file if present
function cm_load_env(string $filePath): void {
    if (!file_exists($filePath)) {
        return;
    }
    $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return;
    }
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        $parts = explode('=', $line, 2);
        if (count($parts) === 2) {
            $key = trim($parts[0]);
            $val = trim($parts[1]);
            // Strip matching surrounding quotes
            if ((str_starts_with($val, '"') && str_ends_with($val, '"')) ||
                (str_starts_with($val, "'") && str_ends_with($val, "'"))) {
                $val = substr($val, 1, -1);
            }
            if (!array_key_exists($key, $_SERVER) && !array_key_exists($key, $_ENV)) {
                putenv("$key=$val");
                $_ENV[$key] = $val;
                $_SERVER[$key] = $val;
            }
        }
    }
}

// Load .env
cm_load_env(CM_ROOT . '/.env');

// Helper to get environment config with fallback
function cm_env(string $key, mixed $default = null): mixed {
    $val = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
    if ($val === false || $val === null || $val === '') {
        return $default;
    }
    return match (strtolower((string)$val)) {
        'true', '(true)' => true,
        'false', '(false)' => false,
        'null', '(null)' => null,
        'empty', '(empty)' => '',
        default => $val,
    };
}

// Directory constants
$customDataPath = cm_env('DATA_PATH');
if ($customDataPath && !str_starts_with($customDataPath, '/')) {
    define('CM_DATA_DIR', realpath(CM_ROOT . '/' . $customDataPath) ?: (CM_ROOT . '/' . $customDataPath));
} elseif ($customDataPath) {
    define('CM_DATA_DIR', $customDataPath);
} else {
    define('CM_DATA_DIR', CM_ROOT . '/data');
}

define('CM_HISTORY_DIR', CM_DATA_DIR . '/history');
define('CM_LOGS_DIR', CM_DATA_DIR . '/logs');

// Ensure directories exist
if (!is_dir(CM_DATA_DIR)) {
    @mkdir(CM_DATA_DIR, 0755, true);
}
if (!is_dir(CM_HISTORY_DIR)) {
    @mkdir(CM_HISTORY_DIR, 0755, true);
}
if (!is_dir(CM_LOGS_DIR)) {
    @mkdir(CM_LOGS_DIR, 0755, true);
}

// Global App Config Array
function cm_get_config(): array {
    return [
        'app_name' => cm_env('APP_NAME', 'Website Change Monitor'),
        'app_env' => cm_env('APP_ENV', 'production'),
        'app_secret' => cm_env('APP_SECRET', 'default_change_monitor_secret_key_123'),
        'admin_password' => cm_env('ADMIN_PASSWORD', 'admin'),
        'cron_token' => cm_env('CRON_TOKEN', 'cron_secret_token_123'),
        'data_dir' => CM_DATA_DIR,
        'history_dir' => CM_HISTORY_DIR,
        'logs_dir' => CM_LOGS_DIR,
    ];
}
