<?php
/**
 * Authentication & Security Manager
 * Website Change Monitor
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/storage.php';

class Auth {
    public static function startSession(): void {
        if (session_status() === PHP_SESSION_NONE) {
            // Set secure session cookie parameters
            $isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443);
            session_set_cookie_params([
                'lifetime' => 86400 * 7, // 7 days
                'path' => '/',
                'domain' => '',
                'secure' => $isSecure,
                'httponly' => true,
                'samesite' => 'Lax'
            ]);
            session_start();
        }
    }

    public static function check(): bool {
        self::startSession();
        return !empty($_SESSION['cm_authenticated']) && $_SESSION['cm_authenticated'] === true;
    }

    public static function requireAuth(): void {
        if (!self::check()) {
            if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
                http_response_code(401);
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'error' => 'Unauthorized. Please log in.']);
                exit;
            }
            header('Location: index.php?route=login');
            exit;
        }
    }

    public static function login(string $password): bool {
        self::startSession();
        $auth = Storage::getAuthInfo();

        // Check lock status
        if (!empty($auth['locked_until']) && strtotime($auth['locked_until']) > time()) {
            return false;
        }

        $isValid = password_verify($password, $auth['password_hash']);
        
        // Also support password reset via .env ADMIN_PASSWORD if user changes it in .env
        $envPassword = cm_env('ADMIN_PASSWORD');
        if (!$isValid && $envPassword && $password === $envPassword) {
            $isValid = true;
            $auth['password_hash'] = password_hash($password, PASSWORD_DEFAULT);
        }

        if ($isValid) {
            $_SESSION['cm_authenticated'] = true;
            $_SESSION['cm_login_time'] = time();
            $auth['failed_attempts'] = 0;
            $auth['locked_until'] = null;
            $auth['last_login'] = date('c');
            Storage::saveAuthInfo($auth);
            return true;
        }

        $auth['failed_attempts'] = ($auth['failed_attempts'] ?? 0) + 1;
        if ($auth['failed_attempts'] >= 5) {
            $auth['locked_until'] = date('c', time() + (15 * 60)); // 15 min lockout
        }
        Storage::saveAuthInfo($auth);
        return false;
    }

    public static function updatePassword(string $newPassword): bool {
        self::requireAuth();
        $auth = Storage::getAuthInfo();
        $auth['password_hash'] = password_hash($newPassword, PASSWORD_DEFAULT);
        return Storage::saveAuthInfo($auth);
    }

    public static function logout(): void {
        self::startSession();
        $_SESSION = [];
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params["path"], $params["domain"],
                $params["secure"], $params["httponly"]
            );
        }
        session_destroy();
    }

    public static function getCsrfToken(): string {
        self::startSession();
        if (empty($_SESSION['cm_csrf_token'])) {
            $_SESSION['cm_csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['cm_csrf_token'];
    }

    public static function verifyCsrfToken(?string $token): bool {
        self::startSession();
        if (empty($token) || empty($_SESSION['cm_csrf_token'])) {
            return false;
        }
        return hash_equals($_SESSION['cm_csrf_token'], $token);
    }
}
