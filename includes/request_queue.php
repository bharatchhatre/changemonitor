<?php
/**
 * Request/Response Queue Manager for WhatsApp Connector
 * Supports human-in-the-loop manual review with automatic OCR fallback.
 */

declare(strict_types=1);

if (file_exists(__DIR__ . '/../includes/config.php')) {
    require_once __DIR__ . '/../includes/config.php';
}

class RequestQueue {
    private static function getStorageDir(): string {
        $dir = defined('CM_DATA_DIR') ? CM_DATA_DIR . '/whatsapp_queue' : __DIR__ . '/../data/whatsapp_queue';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        return $dir;
    }

    /**
     * Create a pending request item
     */
    public static function createRequest(string $imageBytes, string $format = 'json'): array {
        $id = 'req_' . bin2hex(random_bytes(8));
        $now = microtime(true);
        $dir = self::getStorageDir();

        // Save image to disk for display in the admin UI
        $imageFilename = $id . '.png';
        $imagePath = $dir . '/' . $imageFilename;
        file_put_contents($imagePath, $imageBytes);

        $record = [
            'id' => $id,
            'created_at' => date('Y-m-d H:i:s'),
            'timestamp' => $now,
            'status' => 'pending', // pending, answered, fallback, expired
            'format' => $format,
            'image_file' => $imageFilename,
            'response_text' => null,
            'answered_at' => null,
            'source' => null // 'manual' or 'auto'
        ];

        file_put_contents($dir . '/' . $id . '.json', json_encode($record, JSON_PRETTY_PRINT));
        return $record;
    }

    /**
     * Get a specific request record
     */
    public static function getRequest(string $id): ?array {
        $file = self::getStorageDir() . '/' . $id . '.json';
        if (!file_exists($file)) {
            return null;
        }
        $data = json_decode((string)file_get_contents($file), true);
        return is_array($data) ? $data : null;
    }

    /**
     * Provide a manual response to a pending request
     */
    public static function submitResponse(string $id, string $text): bool {
        $file = self::getStorageDir() . '/' . $id . '.json';
        if (!file_exists($file)) {
            return false;
        }

        $data = json_decode((string)file_get_contents($file), true);
        if (!$data) {
            return false;
        }

        $data['status'] = 'answered';
        $data['response_text'] = trim($text);
        $data['answered_at'] = date('Y-m-d H:i:s');
        $data['source'] = 'manual';

        return (bool)file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT));
    }

    /**
     * Mark request as resolved by automatic fallback
     */
    public static function markFallback(string $id, string $text): bool {
        $file = self::getStorageDir() . '/' . $id . '.json';
        if (!file_exists($file)) {
            return false;
        }

        $data = json_decode((string)file_get_contents($file), true);
        if (!$data) {
            return false;
        }

        $data['status'] = 'fallback';
        $data['response_text'] = trim($text);
        $data['answered_at'] = date('Y-m-d H:i:s');
        $data['source'] = 'auto';

        return (bool)file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT));
    }

    /**
     * List recent requests (sorted newest first)
     */
    public static function listRequests(int $limit = 30): array {
        $dir = self::getStorageDir();
        $files = glob($dir . '/req_*.json') ?: [];
        $items = [];

        foreach ($files as $file) {
            $data = json_decode((string)file_get_contents($file), true);
            if ($data && isset($data['id'])) {
                $items[] = $data;
            }
        }

        usort($items, function($a, $b) {
            return ($b['timestamp'] ?? 0) <=> ($a['timestamp'] ?? 0);
        });

        return array_slice($items, 0, $limit);
    }

    /**
     * Get image raw bytes or path for a request
     */
    public static function getImagePath(string $id): ?string {
        $path = self::getStorageDir() . '/' . $id . '.png';
        return file_exists($path) ? $path : null;
    }

    /**
     * Get auto response setting (defaults to true)
     */
    public static function isAutoResponseEnabled(): bool {
        $file = self::getStorageDir() . '/auto_response_setting.json';
        if (!file_exists($file)) {
            return true;
        }
        $data = json_decode((string)file_get_contents($file), true);
        return isset($data['enabled']) ? (bool)$data['enabled'] : true;
    }

    /**
     * Set auto response setting on/off
     */
    public static function setAutoResponseEnabled(bool $enabled): bool {
        $file = self::getStorageDir() . '/auto_response_setting.json';
        return (bool)file_put_contents($file, json_encode([
            'enabled' => $enabled,
            'updated_at' => date('Y-m-d H:i:s')
        ], JSON_PRETTY_PRINT));
    }

    /**
     * Clean up old requests older than 24 hours
     */
    public static function cleanupOld(int $maxAgeSeconds = 86400): void {
        $dir = self::getStorageDir();
        $now = time();
        foreach (glob($dir . '/req_*.*') ?: [] as $file) {
            if ($now - filemtime($file) > $maxAgeSeconds) {
                @unlink($file);
            }
        }
    }
}
