<?php
/**
 * Notification Dispatcher (Gmail SMTP, Telegram, OpenWA)
 * Website Change Monitor
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/storage.php';

class Notifier {
    /**
     * Dispatch notification across all configured channels
     *
     * @param string $subject Notification title/subject
     * @param string $message Plaintext/Markdown message body
     * @param array $context Additional details (monitor info, diff, error)
     * @return array [ 'channel_name' => [ 'success' => bool, 'error' => ?string ] ]
     */
    public static function dispatch(string $subject, string $message, array $context = []): array {
        $settings = Storage::getSettings();
        $results = [];

        $hasConfiguredChannel = false;

        // 1. Telegram
        if (!empty($settings['telegram_bot_token']) && !empty($settings['telegram_chat_id'])) {
            $hasConfiguredChannel = true;
            $results['telegram'] = self::sendTelegram($settings['telegram_bot_token'], $settings['telegram_chat_id'], $subject, $message, $context);
            if (!$results['telegram']['success']) {
                Storage::logAppEvent('ERROR', 'NOTIFIER', 'Telegram alert failed: ' . ($results['telegram']['error'] ?? 'Unknown error'), ['subject' => $subject]);
            } else {
                Storage::logAppEvent('INFO', 'NOTIFIER', 'Telegram alert delivered', ['subject' => $subject]);
            }
        }

        // 2. OpenWA (WhatsApp)
        if (!empty($settings['openwa_api_url']) && !empty($settings['openwa_chat_id'])) {
            $hasConfiguredChannel = true;
            $results['openwa'] = self::sendOpenWA($settings['openwa_api_url'], $settings['openwa_api_key'] ?? '', $settings['openwa_chat_id'], $subject, $message, $context);
            if (!$results['openwa']['success']) {
                Storage::logAppEvent('ERROR', 'NOTIFIER', 'OpenWA alert failed: ' . ($results['openwa']['error'] ?? 'Unknown error'), ['subject' => $subject]);
            } else {
                Storage::logAppEvent('INFO', 'NOTIFIER', 'OpenWA alert delivered', ['subject' => $subject]);
            }
        }

        // 3. Gmail SMTP
        if (!empty($settings['gmail_smtp_user']) && !empty($settings['gmail_smtp_pass']) && !empty($settings['alert_email_to'])) {
            $hasConfiguredChannel = true;
            $results['email'] = self::sendGmailSmtp($settings, $subject, $message, $context);
            if (!$results['email']['success']) {
                Storage::logAppEvent('ERROR', 'NOTIFIER', 'Email alert failed: ' . ($results['email']['error'] ?? 'Unknown error'), ['subject' => $subject]);
            } else {
                Storage::logAppEvent('INFO', 'NOTIFIER', 'Email alert delivered', ['subject' => $subject]);
            }
        }

        if (!$hasConfiguredChannel) {
            Storage::logAppEvent('WARNING', 'NOTIFIER', 'Change detected but no notification channel is configured (Telegram, OpenWA, or Gmail SMTP are empty in Settings).', ['subject' => $subject]);
        }

        return $results;
    }

    /**
     * Send message via Telegram Bot API
     */
    public static function sendTelegram(string $botToken, string $chatId, string $subject, string $message, array $context = []): array {
        $url = "https://api.telegram.org/bot{$botToken}/sendMessage";
        
        $text = "🔔 *{$subject}*\n\n" . $message;
        if (!empty($context['diff'])) {
            $diffSnippet = substr($context['diff'], 0, 1000);
            $text .= "\n\n```diff\n" . $diffSnippet . "\n```";
        }
        if (!empty($context['url'])) {
            $text .= "\n🔗 Target: " . $context['url'];
        }

        $payload = [
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'Markdown',
            'disable_web_page_preview' => true,
        ];

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        $data = json_decode((string)$resp, true);
        $success = ($code === 200 && !empty($data['ok']));

        // If markdown parsing failed (Telegram 400 Bad Request: can't parse entities), retry as plain text / HTML
        if (!$success && $code === 400) {
            $plainText = "🔔 [{$subject}]\n\n" . strip_tags($message);
            if (!empty($context['url'])) {
                $plainText .= "\nTarget: " . $context['url'];
            }
            if (!empty($context['diff'])) {
                $plainText .= "\n\nDiff:\n" . substr($context['diff'], 0, 800);
            }

            $ch2 = curl_init($url);
            curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch2, CURLOPT_POST, true);
            curl_setopt($ch2, CURLOPT_POSTFIELDS, json_encode([
                'chat_id' => $chatId,
                'text' => $plainText,
                'disable_web_page_preview' => true,
            ]));
            curl_setopt($ch2, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            curl_setopt($ch2, CURLOPT_TIMEOUT, 15);
            $resp2 = curl_exec($ch2);
            $code2 = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
            curl_close($ch2);

            $data2 = json_decode((string)$resp2, true);
            if ($code2 === 200 && !empty($data2['ok'])) {
                $success = true;
            }
        }

        return [
            'success' => $success,
            'error' => $success ? null : ($data['description'] ?? $err ?? "HTTP $code Error"),
        ];
    }

    /**
     * Send message via OpenWA (WhatsApp HTTP REST API)
     */
    public static function sendOpenWA(string $apiUrl, string $apiKey, string $chatId, string $subject, string $message, array $context = []): array {
        // Standard OpenWA /sendText or custom webhook
        $endpoint = rtrim($apiUrl, '/') . '/sendText';
        if (!str_contains($apiUrl, '/sendText') && !str_contains($apiUrl, '/sendMessage')) {
            $endpoint = rtrim($apiUrl, '/') . '/sendText';
        } else {
            $endpoint = $apiUrl;
        }

        // Format whatsapp chat ID (e.g. 1234567890@c.us or group @g.us)
        if (!str_contains($chatId, '@')) {
            $chatId .= '@c.us';
        }

        $fullMsg = "*[{$subject}]*\n\n" . $message;
        if (!empty($context['diff'])) {
            $fullMsg .= "\n\n*Diff Snippet:*\n" . substr($context['diff'], 0, 800);
        }
        if (!empty($context['url'])) {
            $fullMsg .= "\n\nURL: " . $context['url'];
        }

        $payload = [
            'chatId' => $chatId,
            'to' => $chatId,
            'content' => $fullMsg,
            'message' => $fullMsg,
        ];

        $headers = ['Content-Type: application/json'];
        if (!empty($apiKey)) {
            $headers[] = 'api_key: ' . $apiKey;
            $headers[] = 'Authorization: Bearer ' . $apiKey;
        }

        $ch = curl_init($endpoint);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        $success = ($code >= 200 && $code < 300);
        return [
            'success' => $success,
            'error' => $success ? null : ($err ?: "HTTP $code from OpenWA: " . substr((string)$resp, 0, 100)),
        ];
    }

    /**
     * Send email via Gmail SMTP using socket TLS handshake
     * (Zero external dependency, works on all Bluehost shared hosting with sockets enabled)
     */
    public static function sendGmailSmtp(array $settings, string $subject, string $message, array $context = []): array {
        $host = $settings['gmail_smtp_host'] ?? 'smtp.gmail.com';
        $port = (int)($settings['gmail_smtp_port'] ?? 587);
        $user = $settings['gmail_smtp_user'] ?? '';
        $pass = str_replace(' ', '', $settings['gmail_smtp_pass'] ?? ''); // Remove spaces from Google App Password
        $to = $settings['alert_email_to'] ?? '';

        if (empty($user) || empty($pass) || empty($to)) {
            return ['success' => false, 'error' => 'Missing SMTP configuration or destination email.'];
        }

        $htmlBody = "<h2>" . htmlspecialchars($subject) . "</h2>";
        $htmlBody .= "<p style='font-size:14px; line-height:1.5;'>" . nl2br(htmlspecialchars($message)) . "</p>";
        if (!empty($context['url'])) {
            $htmlBody .= "<p><strong>Target URL:</strong> <a href='" . htmlspecialchars($context['url']) . "'>" . htmlspecialchars($context['url']) . "</a></p>";
        }
        if (!empty($context['diff'])) {
            $htmlBody .= "<h3>Detected Change Diff</h3>";
            $htmlBody .= "<pre style='background:#f4f6f8; padding:12px; border-radius:6px; font-family:monospace; font-size:12px; border-left:4px solid #0284c7; overflow-x:auto;'>" . htmlspecialchars($context['diff']) . "</pre>";
        }
        $htmlBody .= "<hr><p style='font-size:12px; color:#888;'>Sent by Website Change Monitor - Bluehost</p>";

        try {
            $socket = @fsockopen($host, $port, $errno, $errstr, 15);
            if (!$socket) {
                // Fallback to native PHP mail() if direct socket fails
                $headers = "MIME-Version: 1.0\r\nContent-type:text/html;charset=UTF-8\r\nFrom: <$user>\r\n";
                $mailSent = @mail($to, $subject, $htmlBody, $headers);
                return [
                    'success' => $mailSent,
                    'error' => $mailSent ? null : "Socket connection failed: $errstr ($errno) and mail() fallback failed",
                ];
            }

            stream_set_timeout($socket, 15);
            $response = fgets($socket, 515);

            $sendCmd = function($cmd) use ($socket) {
                fputs($socket, $cmd . "\r\n");
                $reply = '';
                while ($str = fgets($socket, 515)) {
                    $reply .= $str;
                    if (substr($str, 3, 1) === ' ') break;
                }
                return $reply;
            };

            $sendCmd("EHLO " . ($_SERVER['SERVER_NAME'] ?? 'localhost'));

            if ($port === 587) {
                $sendCmd("STARTTLS");
                $crypto = stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
                if (!$crypto) {
                    fclose($socket);
                    return ['success' => false, 'error' => 'TLS encryption handshake failed'];
                }
                $sendCmd("EHLO " . ($_SERVER['SERVER_NAME'] ?? 'localhost'));
            }

            $authResp = $sendCmd("AUTH LOGIN");
            $userResp = $sendCmd(base64_encode($user));
            $passResp = $sendCmd(base64_encode($pass));

            if (!str_starts_with($passResp, '235')) {
                fclose($socket);
                return ['success' => false, 'error' => 'SMTP Authentication failed. Check your Gmail App Password. Response: ' . trim($passResp)];
            }

            $sendCmd("MAIL FROM: <$user>");
            $sendCmd("RCPT TO: <$to>");
            $sendCmd("DATA");

            $boundary = "==Multipart_Boundary_x" . md5((string)time()) . "x";
            $headers = "From: Change Monitor <$user>\r\n";
            $headers .= "To: <$to>\r\n";
            $headers .= "Subject: =?UTF-8?B?" . base64_encode($subject) . "?=\r\n";
            $headers .= "MIME-Version: 1.0\r\n";
            $headers .= "Content-Type: text/html; charset=UTF-8\r\n\r\n";

            $emailContent = $headers . $htmlBody . "\r\n.\r\n";
            fputs($socket, $emailContent);
            $dataResp = fgets($socket, 515);
            $sendCmd("QUIT");
            fclose($socket);

            $success = str_starts_with((string)$dataResp, '250');
            return [
                'success' => $success,
                'error' => $success ? null : 'SMTP Error: ' . trim((string)$dataResp),
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }
}
