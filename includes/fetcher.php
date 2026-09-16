<?php
/**
 * Smart HTTP Fetcher with Browser Emulation & Anti-Bot Prevention
 * Website Change Monitor
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/storage.php';

class Fetcher {
    /**
     * Built-in Browser Templates / Emulation Profiles
     */
    public static function getBuiltinTemplates(): array {
        return [
            'chrome_mac' => [
                'name' => 'Chrome macOS (Modern Desktop)',
                'user_agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36',
                'headers' => [
                    'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8,application/signed-exchange;v=b3;q=0.7',
                    'Accept-Language' => 'en-US,en;q=0.9',
                    'Sec-Ch-Ua' => '"Chromium";v="128", "Not;A=Brand";v="24", "Google Chrome";v="128"',
                    'Sec-Ch-Ua-Mobile' => '?0',
                    'Sec-Ch-Ua-Platform' => '"macOS"',
                    'Sec-Fetch-Dest' => 'document',
                    'Sec-Fetch-Mode' => 'navigate',
                    'Sec-Fetch-Site' => 'none',
                    'Sec-Fetch-User' => '?1',
                    'Upgrade-Insecure-Requests' => '1',
                ],
            ],
            'chrome_win' => [
                'name' => 'Chrome Windows 11',
                'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36',
                'headers' => [
                    'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8',
                    'Accept-Language' => 'en-US,en;q=0.9',
                    'Sec-Ch-Ua' => '"Chromium";v="128", "Not;A=Brand";v="24", "Google Chrome";v="128"',
                    'Sec-Ch-Ua-Mobile' => '?0',
                    'Sec-Ch-Ua-Platform' => '"Windows"',
                    'Sec-Fetch-Dest' => 'document',
                    'Sec-Fetch-Mode' => 'navigate',
                    'Sec-Fetch-Site' => 'none',
                    'Sec-Fetch-User' => '?1',
                    'Upgrade-Insecure-Requests' => '1',
                ],
            ],
            'firefox_win' => [
                'name' => 'Firefox Windows 11',
                'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:129.0) Gecko/20100101 Firefox/129.0',
                'headers' => [
                    'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8',
                    'Accept-Language' => 'en-US,en;q=0.5',
                    'Sec-Fetch-Dest' => 'document',
                    'Sec-Fetch-Mode' => 'navigate',
                    'Sec-Fetch-Site' => 'none',
                    'Sec-Fetch-User' => '?1',
                    'Upgrade-Insecure-Requests' => '1',
                ],
            ],
            'safari_ios' => [
                'name' => 'Safari iPhone iOS 17',
                'user_agent' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_6_1 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.6 Mobile/15E148 Safari/604.1',
                'headers' => [
                    'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                    'Accept-Language' => 'en-US,en;q=0.9',
                    'Sec-Fetch-Dest' => 'document',
                    'Sec-Fetch-Mode' => 'navigate',
                    'Sec-Fetch-Site' => 'none',
                ],
            ],
            'gov_portal' => [
                'name' => 'Government / Azure FrontDoor Portal (Anti-Bot & Geo Bypass)',
                'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36',
                'headers' => [
                    'Accept' => 'application/json, text/html, application/xhtml+xml, */*;q=0.8',
                    'Accept-Language' => 'en-US,en;q=0.9,hi;q=0.8,mr;q=0.7',
                    'X-Forwarded-For' => '103.21.124.1, 103.21.124.10',
                    'X-Real-IP' => '103.21.124.1',
                    'Client-IP' => '103.21.124.1',
                ],
            ],
            'json_api' => [
                'name' => 'REST API Client (JSON / Microservice)',
                'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36',
                'headers' => [
                    'Accept' => 'application/json, text/plain, */*',
                    'Accept-Language' => 'en-US,en;q=0.9',
                ],
            ],
            'googlebot' => [
                'name' => 'Googlebot (Search Engine Crawler)',
                'user_agent' => 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)',
                'headers' => [
                    'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                ],
            ],
        ];
    }

    /**
     * Get all templates (built-in + user defined)
     */
    public static function getAllTemplates(): array {
        $builtin = self::getBuiltinTemplates();
        $settings = Storage::getSettings();
        $userTemplates = $settings['user_browser_templates'] ?? [];
        return array_merge($builtin, $userTemplates);
    }

    /**
     * Fetch URL content with configured options
     *
     * @param array $options [
     *   'url' => string,
     *   'method' => 'GET'|'POST'|'HEAD',
     *   'template' => 'chrome_mac'|'custom',
     *   'custom_headers' => array|string,
     *   'cookies' => string,
     *   'post_data' => string|array,
     *   'proxy' => string,
     *   'timeout' => int (seconds, default 25),
     *   'follow_redirects' => bool (default true),
     *   'verify_ssl' => bool (default true),
     *   'simulate_delay' => bool (default false),
     * ]
     * @return array [
     *   'success' => bool,
     *   'http_code' => int,
     *   'content_type' => string,
     *   'effective_url' => string,
     *   'body' => string,
     *   'headers' => array,
     *   'duration_ms' => float,
     *   'error' => ?string,
     * ]
     */
    public static function request(array $options): array {
        $url = trim($options['url'] ?? '');
        if (empty($url) || !filter_var($url, FILTER_VALIDATE_URL)) {
            return [
                'success' => false,
                'http_code' => 0,
                'content_type' => '',
                'effective_url' => $url,
                'body' => '',
                'headers' => [],
                'duration_ms' => 0,
                'error' => 'Invalid or missing target URL',
            ];
        }

        // Optional random jitter/delay (100ms - 800ms) to avoid robotic lockstep patterns
        if (!empty($options['simulate_delay'])) {
            usleep(random_int(150000, 750000));
        }

        $method = strtoupper($options['method'] ?? 'GET');
        $templateKey = $options['template'] ?? 'chrome_mac';
        $allTemplates = self::getAllTemplates();
        $template = $allTemplates[$templateKey] ?? $allTemplates['chrome_mac'] ?? [];

        $parsedUrl = parse_url($url);
        $scheme = $parsedUrl['scheme'] ?? 'https';
        $host = $parsedUrl['host'] ?? '';
        $origin = "$scheme://$host";

        // Build Headers List
        $mergedHeaders = $template['headers'] ?? [];

        // Automatic Referer derivation if not present
        if (!isset($mergedHeaders['Referer']) && !isset($mergedHeaders['referer']) && !empty($host)) {
            $mergedHeaders['Referer'] = "$origin/";
        }

        // Check if Indian Gov or Azure FrontDoor portal - inject Geo-bypass headers automatically
        if (str_ends_with($host, '.gov.in') || str_ends_with($host, '.nic.in') || str_contains($host, 'mhada') || str_contains($host, 'cidco')) {
            if (!isset($mergedHeaders['X-Forwarded-For'])) {
                $mergedHeaders['X-Forwarded-For'] = '103.21.124.1, 103.21.124.10';
            }
            if (!isset($mergedHeaders['X-Real-IP'])) {
                $mergedHeaders['X-Real-IP'] = '103.21.124.1';
            }
            if (!isset($mergedHeaders['Client-IP'])) {
                $mergedHeaders['Client-IP'] = '103.21.124.1';
            }
        }

        // Parse custom headers
        if (!empty($options['custom_headers'])) {
            if (is_string($options['custom_headers'])) {
                $lines = explode("\n", str_replace("\r", "", $options['custom_headers']));
                foreach ($lines as $line) {
                    $line = trim($line);
                    if ($line === '' || str_starts_with($line, '#')) continue;
                    $parts = explode(':', $line, 2);
                    if (count($parts) === 2) {
                        $mergedHeaders[trim($parts[0])] = trim($parts[1]);
                    }
                }
            } elseif (is_array($options['custom_headers'])) {
                $mergedHeaders = array_merge($mergedHeaders, $options['custom_headers']);
            }
        }

        // Format for cURL
        $headersList = [];
        foreach ($mergedHeaders as $k => $v) {
            $headersList[] = "$k: $v";
        }

        $userAgent = !empty($options['user_agent']) ? $options['user_agent'] : ($template['user_agent'] ?? 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36');

        $ch = curl_init();

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_USERAGENT, $userAgent);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headersList);
        curl_setopt($ch, CURLOPT_ENCODING, ''); // Accepts gzip, deflate, br

        $timeout = (int)($options['timeout'] ?? 25);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout > 0 ? $timeout : 25);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);

        // Redirects
        $followRedirects = $options['follow_redirects'] ?? true;
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, $followRedirects);
        curl_setopt($ch, CURLOPT_MAXREDIRS, 5);

        // SSL verification (graceful fallback for missing local root CA bundles on shared hosting)
        $verifySsl = array_key_exists('verify_ssl', $options) ? (bool)$options['verify_ssl'] : true;
        if ($verifySsl) {
            // Check common server CA bundle paths
            $caBundlePaths = [
                '/etc/pki/tls/certs/ca-bundle.crt',
                '/etc/ssl/certs/ca-certificates.crt',
                '/usr/local/share/certs/ca-root-nss.crt',
                '/etc/ssl/cert.pem',
                '/etc/pki/ca-trust/extracted/pem/tls-ca-bundle.pem',
            ];
            foreach ($caBundlePaths as $caPath) {
                if (file_exists($caPath) && is_readable($caPath)) {
                    curl_setopt($ch, CURLOPT_CAINFO, $caPath);
                    break;
                }
            }
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        } else {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
        }

        // Method & Post Data
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            $postData = $options['post_data'] ?? '';
            if (is_array($postData)) {
                $postData = json_encode($postData);
            }
            curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
        } elseif ($method === 'HEAD') {
            curl_setopt($ch, CURLOPT_NOBODY, true);
        }

        // Automatic Cookie Jar per Host / Target to retain session cookies (like ASLBSA / Cloudflare / Azure FrontDoor tokens)
        $cookieLogDir = CM_DATA_DIR . '/logs';
        if (!is_dir($cookieLogDir)) {
            @mkdir($cookieLogDir, 0755, true);
        }
        $cookieJarFile = $cookieLogDir . '/cookies_' . md5($host ?: 'host') . '.txt';
        curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieJarFile);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieJarFile);

        // Cookies explicitly supplied
        if (!empty($options['cookies'])) {
            curl_setopt($ch, CURLOPT_COOKIE, $options['cookies']);
        }

        // Proxy
        if (!empty($options['proxy'])) {
            curl_setopt($ch, CURLOPT_PROXY, $options['proxy']);
        }

        $startTime = microtime(true);
        $rawResponse = curl_exec($ch);
        $curlError = curl_error($ch);
        $curlErrno = curl_errno($ch);

        // If SSL certificate verification failed due to missing local issuer / root CA bundle on shared host, retry once safely
        if ($rawResponse === false && in_array($curlErrno, [60, 77, 35, 51], true)) {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
            $rawResponse = curl_exec($ch);
            $curlError = curl_error($ch);
        }

        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);

        // Intelligent Anti-Bot & Azure FrontDoor WAF Self-Healing Fallback
        // If target returns 403 Forbidden or 400 Bad Request, execute multi-tier self-healing
        if ($httpCode === 403 || $httpCode === 400) {
            if (file_exists($cookieJarFile)) {
                @unlink($cookieJarFile);
            }

            // Retry Tier 1: Clean REST API / SPA mode with HTTP/1.1, host Referer, and Indian IP Forwarding
            $apiFallbackHeaders = [
                'Accept: application/json, text/plain, */*',
                'Accept-Language: en-US,en;q=0.9,hi;q=0.8',
                "Referer: $origin/",
                'X-Forwarded-For: 103.21.124.1, 103.21.124.10',
                'X-Real-IP: 103.21.124.1',
                'Client-IP: 103.21.124.1',
                'Connection: close',
            ];
            curl_setopt($ch, CURLOPT_HTTPHEADER, $apiFallbackHeaders);
            curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_1_1);
            curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36');
            curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieJarFile);
            curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieJarFile);
            usleep(150000); // 150ms backoff
            $rawResponse = curl_exec($ch);
            $curlError = curl_error($ch);
            $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);

            // Retry Tier 2: Clean Browser Document Navigation mode if Tier 1 was not 200-399
            if ($httpCode === 403 || $httpCode === 400) {
                $docFallbackHeaders = [
                    'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                    'Accept-Language: en-US,en;q=0.9',
                    "Referer: $origin/",
                    'X-Forwarded-For: 103.21.124.1, 103.21.124.10',
                    'X-Real-IP: 103.21.124.1',
                    'Client-IP: 103.21.124.1',
                    'Upgrade-Insecure-Requests: 1',
                    'Connection: close',
                ];
                curl_setopt($ch, CURLOPT_HTTPHEADER, $docFallbackHeaders);
                curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_NONE);
                $rawResponse = curl_exec($ch);
                $curlError = curl_error($ch);
                $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            }
        }

        $durationMs = round((microtime(true) - $startTime) * 1000, 2);
        $contentType = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $effectiveUrl = (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);

        $responseHeaders = [];
        $responseBody = '';

        if ($rawResponse !== false) {
            $rawHeaders = substr($rawResponse, 0, $headerSize);
            $responseBody = substr($rawResponse, $headerSize);

            // Parse response headers into array
            $headerLines = explode("\r\n", $rawHeaders);
            foreach ($headerLines as $line) {
                if (str_contains($line, ':')) {
                    [$hk, $hv] = explode(':', $line, 2);
                    $responseHeaders[trim($hk)] = trim($hv);
                }
            }
        }

        curl_close($ch);

        $isSuccess = ($httpCode >= 200 && $httpCode < 400) && empty($curlError);

        return [
            'success' => $isSuccess,
            'http_code' => $httpCode,
            'content_type' => $contentType,
            'effective_url' => $effectiveUrl,
            'body' => $responseBody,
            'headers' => $responseHeaders,
            'duration_ms' => $durationMs,
            'error' => !empty($curlError) ? $curlError : ($httpCode >= 400 ? "HTTP $httpCode Error" : null),
        ];
    }
}
