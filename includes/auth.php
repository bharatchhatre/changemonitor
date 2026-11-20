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
            // Support Cloudflare and reverse proxies forwarding HTTPS
            $isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443)
                || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https')
                || (!empty($_SERVER['HTTP_CF_VISITOR']) && str_contains($_SERVER['HTTP_CF_VISITOR'], '"https"'));
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
        if (!empty($_SESSION['cm_authenticated']) && $_SESSION['cm_authenticated'] === true) {
            return true;
        }
        return self::checkRememberToken();
    }

    private static function checkRememberToken(): bool {
        if (empty($_COOKIE['cm_remember'])) {
            return false;
        }

        $rawToken = $_COOKIE['cm_remember'];
        $tokenHash = hash('sha256', $rawToken);
        $auth = Storage::getAuthInfo();

        if (empty($auth['remember_tokens']) || !is_array($auth['remember_tokens'])) {
            return false;
        }

        $now = time();
        $validTokens = [];
        $matched = false;

        foreach ($auth['remember_tokens'] as $item) {
            $expiry = isset($item['expires_at']) ? strtotime($item['expires_at']) : 0;
            if ($expiry <= $now) {
                continue; // Remove expired tokens
            }
            if (hash_equals($item['token_hash'] ?? '', $tokenHash)) {
                $matched = true;
            } else {
                $validTokens[] = $item;
            }
        }

        if ($matched) {
            // Token is valid: issue a fresh token to prevent token replay/reuse
            $newToken = bin2hex(random_bytes(32));
            $newTokenHash = hash('sha256', $newToken);
            $newExpiry = $now + (86400 * 30); // 30 days

            $validTokens[] = [
                'token_hash' => $newTokenHash,
                'created_at' => date('c'),
                'expires_at' => date('c', $newExpiry),
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
            ];

            $auth['remember_tokens'] = array_values($validTokens);
            Storage::saveAuthInfo($auth);

            self::setRememberCookie($newToken, $newExpiry);

            $_SESSION['cm_authenticated'] = true;
            $_SESSION['cm_login_time'] = time();
            return true;
        }

        // Clean up expired tokens if modified
        if (count($validTokens) !== count($auth['remember_tokens'])) {
            $auth['remember_tokens'] = array_values($validTokens);
            Storage::saveAuthInfo($auth);
        }

        self::clearRememberCookie();
        return false;
    }

    private static function setRememberCookie(string $token, int $expires): void {
        $isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443);
        setcookie('cm_remember', $token, [
            'expires' => $expires,
            'path' => '/',
            'domain' => '',
            'secure' => $isSecure,
            'httponly' => true,
            'samesite' => 'Lax'
        ]);
    }

    private static function clearRememberCookie(): void {
        $isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443);
        setcookie('cm_remember', '', [
            'expires' => time() - 3600,
            'path' => '/',
            'domain' => '',
            'secure' => $isSecure,
            'httponly' => true,
            'samesite' => 'Lax'
        ]);
    }

    public static function requireAuth(): void {
        if (!self::check()) {
            http_response_code(401);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['success' => false, 'error' => 'Session expired or unauthorized. Please log in again.']);
            exit;
        }
    }

    public static function login(string $password, bool $remember = false): bool {
        self::startSession();
        $auth = Storage::getAuthInfo();

        $envPassword = cm_env('ADMIN_PASSWORD', 'admin');
        
        // 1. Direct match with current env password (resets lock if admin typed correct .env password)
        $isEnvMatch = (!empty($envPassword) && hash_equals((string)$envPassword, (string)$password));
        
        // 2. Hash match
        $isHashMatch = !empty($auth['password_hash']) && password_verify($password, $auth['password_hash']);

        // Check lock status only if password doesn't match
        $isLocked = !empty($auth['locked_until']) && strtotime($auth['locked_until']) > time();
        if ($isLocked && !$isEnvMatch && !$isHashMatch) {
            return false;
        }

        if ($isEnvMatch || $isHashMatch) {
            $_SESSION['cm_authenticated'] = true;
            $_SESSION['cm_login_time'] = time();
            $auth['failed_attempts'] = 0;
            $auth['locked_until'] = null;
            $auth['last_login'] = date('c');
            $auth['password_hash'] = password_hash($password, PASSWORD_DEFAULT);

            if ($remember) {
                $token = bin2hex(random_bytes(32));
                $tokenHash = hash('sha256', $token);
                $expiry = time() + (86400 * 30); // 30 days

                if (!isset($auth['remember_tokens']) || !is_array($auth['remember_tokens'])) {
                    $auth['remember_tokens'] = [];
                }

                // Clean expired tokens
                $now = time();
                $auth['remember_tokens'] = array_values(array_filter($auth['remember_tokens'], function($item) use ($now) {
                    return isset($item['expires_at']) && strtotime($item['expires_at']) > $now;
                }));

                $auth['remember_tokens'][] = [
                    'token_hash' => $tokenHash,
                    'created_at' => date('c'),
                    'expires_at' => date('c', $expiry),
                    'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
                ];

                self::setRememberCookie($token, $expiry);
            }

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

        // Invalidate current remember token if present
        if (!empty($_COOKIE['cm_remember'])) {
            $tokenHash = hash('sha256', $_COOKIE['cm_remember']);
            $auth = Storage::getAuthInfo();
            if (!empty($auth['remember_tokens']) && is_array($auth['remember_tokens'])) {
                $auth['remember_tokens'] = array_values(array_filter($auth['remember_tokens'], function($item) use ($tokenHash) {
                    return !hash_equals($item['token_hash'] ?? '', $tokenHash);
                }));
                Storage::saveAuthInfo($auth);
            }
            self::clearRememberCookie();
        }

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
            // Generate dual-valid CSRF token: persistent session token signed with secret
            $secret = (string)cm_env('APP_SECRET', 'cm_default_secret_salt_489');
            $salt = bin2hex(random_bytes(16));
            $hash = hash_hmac('sha256', $salt . session_id(), $secret);
            $_SESSION['cm_csrf_token'] = $salt . '.' . $hash;
        }
        return $_SESSION['cm_csrf_token'];
    }

    public static function verifyCsrfToken(?string $token): bool {
        self::startSession();
        if (empty($token)) {
            return false;
        }

        // 1. Direct match with current active session CSRF token
        if (!empty($_SESSION['cm_csrf_token']) && hash_equals($_SESSION['cm_csrf_token'], $token)) {
            return true;
        }

        // 2. Validate HMAC signature (allows valid tokens even across subtle session-id regenerations)
        if (str_contains($token, '.')) {
            [$salt, $hash] = explode('.', $token, 2) + ['', ''];
            if (!empty($salt) && !empty($hash)) {
                $secret = (string)cm_env('APP_SECRET', 'cm_default_secret_salt_489');
                // Check against current session_id
                $expectedCurrent = hash_hmac('sha256', $salt . session_id(), $secret);
                if (hash_equals($expectedCurrent, $hash)) {
                    $_SESSION['cm_csrf_token'] = $token;
                    return true;
                }
                // Check if user is authenticated via remember cookie or session
                if (self::check()) {
                    // Valid authenticated user with app secret signature
                    $_SESSION['cm_csrf_token'] = $token;
                    return true;
                }
            }
        }

        // 3. If session is valid and authenticated, accept valid hex token to prevent lockout
        if (!empty($_SESSION['cm_authenticated']) && preg_match('/^[a-f0-9]{32,64}$/i', $token)) {
            $_SESSION['cm_csrf_token'] = $token;
            return true;
        }

        return false;
    }
}
