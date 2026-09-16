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
                'name' => 'Government / Azure FrontDoor Portal (Anti-Bot Bypass)',
                'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36',
                'headers' => [
                    'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,application/json,text/plain,*/*;q=0.8',
                    'Accept-Language' => 'en-US,en;q=0.9,hi;q=0.8',
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
            'json_api' => [
                'name' => 'REST API Client (JSON / Microservice)',
                'user_agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36',
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

        // Build Headers List
        $headersList = [];
        $mergedHeaders = $template['headers'] ?? [];

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
        foreach ($mergedHeaders as $k => $v) {
            $headersList[] = "$k: $v";
        }

        $userAgent = !empty($options['user_agent']) ? $options['user_agent'] : ($template['user_agent'] ?? 'ChangeMonitor/1.0');

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

        // SSL verification
        $verifySsl = $options['verify_ssl'] ?? true;
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $verifySsl);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $verifySsl ? 2 : 0);

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
        $cookieJarFile = CM_DATA_DIR . '/logs/cookies_' . md5(parse_url($url, PHP_URL_HOST) ?? 'host') . '.txt';
        curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieJarFile);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieJarFile);

        // Cookies explicitly supplied
        if (!empty($options['cookies'])) {
            curl_setopt($ch, CURLOPT_COOKIE, $options['cookies']);
        }

        // Auto Referer
        curl_setopt($ch, CURLOPT_AUTOREFERER, true);

        // Proxy
        if (!empty($options['proxy'])) {
            curl_setopt($ch, CURLOPT_PROXY, $options['proxy']);
        }

        $startTime = microtime(true);
        $rawResponse = curl_exec($ch);
        $durationMs = round((microtime(true) - $startTime) * 1000, 2);

        $curlError = curl_error($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
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
