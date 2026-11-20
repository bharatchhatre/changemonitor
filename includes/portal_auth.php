<?php
/**
 * Automated Authentication & CSRF Management for Government Housing Portals
 * Supports MHADA (bookmyhome) and CIDCO (cidcofcfs)
 * Website Change Monitor
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';

class PortalAuth {
    private static array $portalConfigs = [
        'mhada' => [
            'name' => 'MHADA',
            'hosts' => ['bookmyhome.mhada.gov.in', 'bookmyhomeapplication.mhada.gov.in', 'bookmyhomeregistration.mhada.gov.in'],
            'login_url' => 'https://bookmyhomeregistration.mhada.gov.in/open/login',
            'origin' => 'https://bookmyhome.mhada.gov.in',
            'referer' => 'https://bookmyhome.mhada.gov.in/',
            'env_pan' => 'MHADA_PAN',
            'env_pass' => 'MHADA_PASSWORD',
            'env_proxy' => 'MHADA_PROXY',
            'xsrf_header_name' => 'X-XSRF-TOKEN',
        ],
        'cidco' => [
            'name' => 'CIDCO',
            'hosts' => ['cidcofcfs.cidcoindia.com', 'cidcofcfsapplication.cidcoindia.com', 'cidcofcfsregistration.cidcoindia.com'],
            'login_url' => 'https://cidcofcfsregistration.cidcoindia.com/open/login',
            'origin' => 'https://cidcofcfs.cidcoindia.com',
            'referer' => 'https://cidcofcfs.cidcoindia.com/',
            'env_pan' => 'CIDCO_PAN',
            'env_pass' => 'CIDCO_PASSWORD',
            'env_proxy' => 'CIDCO_PROXY',
            'xsrf_header_name' => 'X-XSRF-TOKEN',
        ],
    ];

    /**
     * Identify if target host belongs to a supported government portal
     */
    public static function detectPortal(string $host): ?string {
        $hostLower = strtolower($host);
        foreach (self::$portalConfigs as $key => $conf) {
            foreach ($conf['hosts'] as $portalHost) {
                if ($hostLower === $portalHost || str_ends_with($hostLower, '.' . $portalHost)) {
                    return $key;
                }
            }
            if (str_contains($hostLower, $key)) {
                return $key;
            }
        }
        return null;
    }

    /**
     * Get portal configuration
     */
    public static function getPortalConfig(string $portalKey): ?array {
        return self::$portalConfigs[$portalKey] ?? null;
    }

    /**
     * Get session storage path
     */
    private static function getSessionFilePath(string $portalKey): string {
        $sessionDir = CM_DATA_DIR . '/sessions';
        if (!is_dir($sessionDir)) {
            @mkdir($sessionDir, 0755, true);
        }
        return $sessionDir . '/' . $portalKey . '_session.json';
    }

    /**
     * Read cached session from disk
     */
    public static function getCachedSession(string $portalKey): ?array {
        $path = self::getSessionFilePath($portalKey);
        if (!file_exists($path)) {
            return null;
        }

        $content = @file_get_contents($path);
        if (!$content) {
            return null;
        }

        $data = json_decode($content, true);
        if (!$data || empty($data['auth_token'])) {
            return null;
        }

        // Check expiration (default 4 hours safety window before max age)
        $expiresAt = (int)($data['expires_at'] ?? 0);
        if ($expiresAt > 0 && time() >= $expiresAt - 300) {
            return null; // Expired or expiring within 5 minutes
        }

        return $data;
    }

    /**
     * Save session data to disk
     */
    public static function saveSession(string $portalKey, array $data): bool {
        $path = self::getSessionFilePath($portalKey);
        return (bool)file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT), LOCK_EX);
    }

    /**
     * Clear cached session for a portal
     */
    public static function clearSession(string $portalKey): void {
        $path = self::getSessionFilePath($portalKey);
        if (file_exists($path)) {
            @unlink($path);
        }
    }

    /**
     * Perform login and retrieve fresh credentials + CSRF tokens
     */
    public static function authenticate(string $portalKey, bool $force = false): array {
        if (!$force) {
            $cached = self::getCachedSession($portalKey);
            if ($cached !== null) {
                return ['success' => true, 'session' => $cached, 'cached' => true];
            }
        }

        $conf = self::getPortalConfig($portalKey);
        if (!$conf) {
            return ['success' => false, 'error' => "Unsupported portal: $portalKey"];
        }

        $pan = strtoupper(trim(getenv($conf['env_pan']) ?: ''));
        $pass = trim(getenv($conf['env_pass']) ?: '');
        $proxy = trim(getenv($conf['env_proxy']) ?: (getenv('GOV_PROXY') ?: ''));

        if (empty($pan) || empty($pass)) {
            return [
                'success' => false,
                'error' => "Missing {$conf['name']} credentials in .env ({$conf['env_pan']} and {$conf['env_pass']})",
            ];
        }

        // Portals expect password as hex string of characters
        $hexPassword = bin2hex($pass);

        $payload = json_encode([
            'panNo' => $pan,
            'password' => $hexPassword,
            'emailOtp' => null,
            'loginType' => 'password',
        ]);

        $headers = [
            'Content-Type: application/json',
            'Accept: application/json, text/plain, */*',
            'Origin: ' . $conf['origin'],
            'Referer: ' . $conf['referer'],
            'User-Agent: Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36',
            'X-Forwarded-For: 103.21.124.1, 103.21.124.10',
            'X-Real-IP: 103.21.124.1',
            'Client-IP: 103.21.124.1',
        ];

        $ch = curl_init($conf['login_url']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);

        if (!empty($proxy)) {
            curl_setopt($ch, CURLOPT_PROXY, $proxy);
        }

        $rawResponse = curl_exec($ch);
        $curlError = curl_error($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        if ($rawResponse === false || !empty($curlError)) {
            return ['success' => false, 'error' => "cURL login failure: $curlError (HTTP $httpCode)"];
        }

        $rawHeaders = substr($rawResponse, 0, $headerSize);
        $body = substr($rawResponse, $headerSize);

        $responseJson = json_decode($body, true);
        if (!$responseJson || ($responseJson['status'] ?? '') !== 'SUCCESS') {
            $msg = $responseJson['errorMessage'] ?? 'Authentication failed';
            return ['success' => false, 'error' => "{$conf['name']} login rejected: $msg (HTTP $httpCode)"];
        }

        // Parse Cookies from response headers
        $cookies = [];
        preg_match_all('/^set-cookie:\s*([^;]+)/mi', $rawHeaders, $cookieMatches);
        if (!empty($cookieMatches[1])) {
            foreach ($cookieMatches[1] as $cStr) {
                if (str_contains($cStr, '=')) {
                    [$cKey, $cVal] = explode('=', $cStr, 2);
                    $cookies[trim($cKey)] = trim($cVal);
                }
            }
        }

        $authToken = $cookies['AUTH_TOKEN'] ?? null;
        $xsrf1 = $cookies['XSRF-TOKEN1'] ?? null;
        $xsrf2 = $cookies['XSRF-TOKEN2'] ?? null;

        // Default expiration: 8 hours or parsed from JWT payload
        $expiresAt = time() + (8 * 3600);
        if (!empty($authToken)) {
            $jwtParts = explode('.', $authToken);
            if (isset($jwtParts[1])) {
                $payloadJson = json_decode(base64_decode(strtr($jwtParts[1], '-_', '+/')), true);
                if (!empty($payloadJson['exp'])) {
                    $expiresAt = (int)$payloadJson['exp'];
                }
            }
        }

        $sessionData = [
            'portal' => $portalKey,
            'pan' => $pan,
            'auth_token' => $authToken,
            'xsrf_token1' => $xsrf1,
            'xsrf_token2' => $xsrf2,
            'cookies' => $cookies,
            'created_at' => time(),
            'expires_at' => $expiresAt,
        ];

        self::saveSession($portalKey, $sessionData);

        return ['success' => true, 'session' => $sessionData, 'cached' => false];
    }

    /**
     * Decorate request headers and cookie string for an outgoing monitor call
     * Ensures CSRF tokens (X-XSRF-TOKEN) and AUTH_TOKEN are synchronized
     */
    public static function prepareRequest(string $url, array &$headers, string &$cookieString): bool {
        $host = parse_url($url, PHP_URL_HOST) ?: '';
        $portalKey = self::detectPortal($host);
        if (!$portalKey) {
            return false;
        }

        $conf = self::getPortalConfig($portalKey);
        if (!$conf) {
            return false;
        }

        // Auto-inject Indian Geo-bypass headers for all government portals
        if (!isset($headers['X-Forwarded-For'])) {
            $headers['X-Forwarded-For'] = '103.21.124.1, 103.21.124.10';
        }
        if (!isset($headers['X-Real-IP'])) {
            $headers['X-Real-IP'] = '103.21.124.1';
        }
        if (!isset($headers['Client-IP'])) {
            $headers['Client-IP'] = '103.21.124.1';
        }
        if (!isset($headers['Origin'])) {
            $headers['Origin'] = $conf['origin'];
        }
        if (!isset($headers['Referer'])) {
            $headers['Referer'] = $conf['referer'];
        }
        if (!isset($headers['isrefreshtoken'])) {
            $headers['isrefreshtoken'] = 'true';
        }

        // Check if credentials exist for automatic login
        $pan = getenv($conf['env_pan']);
        if (empty($pan)) {
            // No credentials, but auto-sync existing CSRF cookie to X-XSRF-TOKEN header if present in cookies
            self::syncCsrfHeaderFromCookies($host, $headers, $cookieString);
            return false;
        }

        $authResult = self::authenticate($portalKey);
        if (!$authResult['success'] || empty($authResult['session'])) {
            self::syncCsrfHeaderFromCookies($host, $headers, $cookieString);
            return false;
        }

        $session = $authResult['session'];
        $cookiesList = [];

        // Parse user-supplied cookies first
        if (!empty($cookieString)) {
            $parts = explode(';', $cookieString);
            foreach ($parts as $p) {
                $p = trim($p);
                if (empty($p)) continue;
                [$k, $v] = explode('=', $p, 2) + ['', ''];
                if ($k !== '') $cookiesList[trim($k)] = trim($v);
            }
        }

        // Inject portal session cookies
        if (!empty($session['auth_token'])) {
            $cookiesList['AUTH_TOKEN'] = $session['auth_token'];
        }
        if (!empty($session['xsrf_token1']) && !isset($cookiesList['XSRF-TOKEN1'])) {
            $cookiesList['XSRF-TOKEN1'] = $session['xsrf_token1'];
        }
        if (!empty($session['xsrf_token2']) && !isset($cookiesList['XSRF-TOKEN2'])) {
            $cookiesList['XSRF-TOKEN2'] = $session['xsrf_token2'];
        }

        // Reassemble cookie string
        $cookieParts = [];
        foreach ($cookiesList as $ck => $cv) {
            $cookieParts[] = "$ck=$cv";
        }
        $cookieString = implode('; ', $cookieParts);

        // Synchronize X-XSRF-TOKEN header
        self::syncCsrfHeaderFromCookies($host, $headers, $cookieString);

        return true;
    }

    /**
     * Inspect cookie string and attach X-XSRF-TOKEN matching application or registration rules
     */
    private static function syncCsrfHeaderFromCookies(string $host, array &$headers, string $cookieString): void {
        if (empty($cookieString)) {
            return;
        }

        $cookies = [];
        $parts = explode(';', $cookieString);
        foreach ($parts as $p) {
            $p = trim($p);
            if (empty($p)) continue;
            [$k, $v] = explode('=', $p, 2) + ['', ''];
            if ($k !== '') $cookies[trim($k)] = trim($v);
        }

        $targetToken = null;
        if (str_contains($host, 'registration') && !empty($cookies['XSRF-TOKEN1'])) {
            $targetToken = $cookies['XSRF-TOKEN1'];
        } elseif (!empty($cookies['XSRF-TOKEN2'])) {
            $targetToken = $cookies['XSRF-TOKEN2'];
        } elseif (!empty($cookies['XSRF-TOKEN1'])) {
            $targetToken = $cookies['XSRF-TOKEN1'];
        } elseif (!empty($cookies['XSRF-TOKEN'])) {
            $targetToken = $cookies['XSRF-TOKEN'];
        }

        if ($targetToken !== null && !isset($headers['X-XSRF-TOKEN']) && !isset($headers['x-xsrf-token'])) {
            $headers['X-XSRF-TOKEN'] = $targetToken;
        }
    }
}
