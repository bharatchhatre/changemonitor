<?php
/**
 * Notification Dispatcher (Gmail SMTP, Telegram, OpenWA)
 * Website Change Monitor
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/storage.php';
require_once __DIR__ . '/diff_formatter.php';

class Notifier {
    /**
     * Dispatch notification across all configured channels
     *
     * @param string $subject Notification title/subject
     * @param string $message Plaintext/Markdown message body
     * @param array $context Additional details (monitor info, old_snapshot, new_snapshot, diff, error)
     * @return array [ 'channel_name' => [ 'success' => bool, 'error' => ?string ] ]
     */
    public static function dispatch(string $subject, string $message, array $context = []): array {
        $settings = Storage::getSettings();
        $results = [];

        $hasConfiguredChannel = false;

        // Compute contextual diff if old and new snapshots are present
        $diffData = $context['diff_data'] ?? null;
        if (!$diffData && isset($context['old_snapshot']) && isset($context['new_snapshot'])) {
            $type = $context['type'] ?? ($context['monitor']['type'] ?? 'html_full');
            $lineThreshold = (int)($settings['diff_big_change_threshold_lines'] ?? 30);
            $charThreshold = 2500;
            $diffData = DiffFormatter::computeContextualDiff(
                (string)$context['old_snapshot'],
                (string)$context['new_snapshot'],
                (string)$type,
                $lineThreshold,
                $charThreshold
            );
            $context['diff_data'] = $diffData;
        }

        $isBigChange = !empty($diffData['is_big_change']);
        $url = $context['url'] ?? '';

        // Generate attachments for big changes
        $attachments = [];
        if ($isBigChange && !empty($diffData)) {
            $monitorName = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $context['monitor']['name'] ?? 'target');
            $ts = date('Ymd_His');
            $meta = [
                'name' => $context['monitor']['name'] ?? 'Target',
                'url' => $url,
                'timestamp' => date('Y-m-d H:i:s'),
            ];

            $oldHtml = DiffFormatter::generateStandaloneSnapshotHtml(
                (string)($context['old_snapshot'] ?? $diffData['old_text'] ?? ''),
                $diffData['items'] ?? [],
                'old',
                $meta
            );

            $newHtml = DiffFormatter::generateStandaloneSnapshotHtml(
                (string)($context['new_snapshot'] ?? $diffData['new_text'] ?? ''),
                $diffData['items'] ?? [],
                'new',
                $meta
            );

            $attachments = [
                [
                    'filename' => "previous_snapshot_{$monitorName}_{$ts}.html",
                    'content' => $oldHtml,
                    'mime_type' => 'text/html',
                    'caption' => '🔴 Previous Snapshot (Removed in Red)',
                ],
                [
                    'filename' => "new_snapshot_{$monitorName}_{$ts}.html",
                    'content' => $newHtml,
                    'mime_type' => 'text/html',
                    'caption' => '🟢 New Snapshot (Added in Green)',
                ],
            ];
            $context['attachments'] = $attachments;
        }

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
        $diffData = $context['diff_data'] ?? null;
        $isBigChange = !empty($diffData['is_big_change']);
        $targetUrl = $context['url'] ?? '';

        if (!empty($diffData) && !$isBigChange) {
            // Small change: Inline contextual diff with red/green emoji highlights
            $text = DiffFormatter::formatTelegramText($subject, $message, $diffData, $targetUrl);
        } else {
            $text = "🔔 *{$subject}*\n\n" . $message;
            if ($isBigChange) {
                $text .= "\n\n📦 *Large Change Detected:* Complete previous (red) and new (green) snapshot files are attached below.";
            } elseif (!empty($context['diff'])) {
                $text .= "\n\n```diff\n" . substr($context['diff'], 0, 1000) . "\n```";
            }
            if (!empty($targetUrl)) {
                $text .= "\n🔗 Target: " . $targetUrl;
            }
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

        // Fallback if markdown parsing fails
        if (!$success && $code === 400) {
            $plainText = "🔔 [{$subject}]\n\n" . strip_tags($message);
            if (!empty($targetUrl)) $plainText .= "\nTarget: " . $targetUrl;
            if (!empty($context['diff'])) $plainText .= "\n\nDiff:\n" . substr($context['diff'], 0, 800);

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

        // If Big Change and message succeeded, send document attachments
        if ($success && $isBigChange && !empty($context['attachments'])) {
            foreach ($context['attachments'] as $att) {
                self::sendTelegramDocument($botToken, $chatId, $att['filename'], $att['content'], $att['caption'] ?? '');
            }
        }

        return [
            'success' => $success,
            'error' => $success ? null : ($data['description'] ?? $err ?? "HTTP $code Error"),
        ];
    }

    /**
     * Send document / file via Telegram Bot API
     */
    public static function sendTelegramDocument(string $botToken, string $chatId, string $filename, string $fileContent, string $caption = ''): array {
        $url = "https://api.telegram.org/bot{$botToken}/sendDocument";

        $tmpFile = tempnam(sys_get_temp_dir(), 'tg_doc_');
        file_put_contents($tmpFile, $fileContent);

        $postFields = [
            'chat_id' => $chatId,
            'caption' => $caption,
            'document' => new \CURLFile($tmpFile, 'text/html', $filename)
        ];

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
        curl_setopt($ch, CURLOPT_TIMEOUT, 25);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        @unlink($tmpFile);

        $data = json_decode((string)$resp, true);
        $success = ($code === 200 && !empty($data['ok']));
        return [
            'success' => $success,
            'error' => $success ? null : ($data['description'] ?? $err ?? "HTTP $code Error"),
        ];
    }

    /**
     * Send message via OpenWA (WhatsApp HTTP REST API)
     */
    public static function sendOpenWA(string $apiUrl, string $apiKey, string $chatId, string $subject, string $message, array $context = []): array {
        $endpoint = rtrim($apiUrl, '/') . '/sendText';
        if (str_contains($apiUrl, '/sendText') || str_contains($apiUrl, '/sendMessage')) {
            $endpoint = $apiUrl;
        }

        if (!str_contains($chatId, '@')) {
            $chatId .= '@c.us';
        }

        $diffData = $context['diff_data'] ?? null;
        $isBigChange = !empty($diffData['is_big_change']);
        $targetUrl = $context['url'] ?? '';

        if (!empty($diffData) && !$isBigChange) {
            $fullMsg = DiffFormatter::formatWhatsAppText($subject, $message, $diffData, $targetUrl);
        } else {
            $fullMsg = "*[{$subject}]*\n\n" . $message;
            if ($isBigChange) {
                $fullMsg .= "\n\n📦 *Large Change Detected:* Separate old & new snapshot files sent below.";
            } elseif (!empty($context['diff'])) {
                $fullMsg .= "\n\n*Diff Snippet:*\n" . substr($context['diff'], 0, 800);
            }
            if (!empty($targetUrl)) {
                $fullMsg .= "\n\nURL: " . $targetUrl;
            }
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

        // If Big Change, dispatch files
        if ($success && $isBigChange && !empty($context['attachments'])) {
            foreach ($context['attachments'] as $att) {
                self::sendOpenWAFile($apiUrl, $apiKey, $chatId, $att['filename'], $att['content'], $att['caption'] ?? '');
            }
        }

        return [
            'success' => $success,
            'error' => $success ? null : ($err ?: "HTTP $code from OpenWA: " . substr((string)$resp, 0, 100)),
        ];
    }

    /**
     * Send file via OpenWA
     */
    public static function sendOpenWAFile(string $apiUrl, string $apiKey, string $chatId, string $filename, string $fileContent, string $caption = ''): array {
        $baseUrl = preg_replace('/(\/sendText|\/sendMessage|\/sendFile)$/', '', rtrim($apiUrl, '/'));
        $endpoint = $baseUrl . '/sendFile';

        if (!str_contains($chatId, '@')) {
            $chatId .= '@c.us';
        }

        $base64 = base64_encode($fileContent);
        $dataUri = 'data:text/html;base64,' . $base64;

        $payload = [
            'chatId' => $chatId,
            'to' => $chatId,
            'file' => $dataUri,
            'filename' => $filename,
            'caption' => $caption,
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
        curl_setopt($ch, CURLOPT_TIMEOUT, 25);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        $success = ($code >= 200 && $code < 300);
        return [
            'success' => $success,
            'error' => $success ? null : ($err ?: "HTTP $code from OpenWA file dispatch"),
        ];
    }

    /**
     * Send email via Gmail SMTP using socket TLS handshake with multipart MIME attachments
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

        $diffData = $context['diff_data'] ?? null;
        $isBigChange = !empty($diffData['is_big_change']);
        $attachments = $context['attachments'] ?? [];

        $htmlBody = "<div style='font-family:-apple-system,BlinkMacSystemFont,\"Segoe UI\",Roboto,sans-serif; color:#1e293b; max-width:720px; margin:0 auto;'>";
        $htmlBody .= "<h2 style='color:#0f172a; border-bottom:2px solid #e2e8f0; padding-bottom:8px;'>" . htmlspecialchars($subject) . "</h2>";
        $htmlBody .= "<p style='font-size:14px; line-height:1.5; color:#334155;'>" . nl2br(htmlspecialchars($message)) . "</p>";

        if (!empty($context['url'])) {
            $htmlBody .= "<p style='font-size:13px;'><strong>Target URL:</strong> <a href='" . htmlspecialchars($context['url']) . "' style='color:#0284c7;'>" . htmlspecialchars($context['url']) . "</a></p>";
        }

        if (!empty($diffData)) {
            if (!$isBigChange) {
                // Small Change: Inline contextual HTML table with red/green highlights & line numbers
                $htmlBody .= "<h3>Detected Changes (Inline Diff)</h3>";
                $htmlBody .= DiffFormatter::formatHtmlInline($diffData);
            } else {
                // Big Change: Summary notice + attached HTML files
                $htmlBody .= "<div style='background:#fef2f2; border:1px solid #fecaca; border-radius:6px; padding:12px 16px; margin:16px 0;'>";
                $htmlBody .= "<strong style='color:#991b1b;'>📦 Large Change Detected (" . ($diffData['total_changed'] ?? 0) . " lines changed)</strong>";
                $htmlBody .= "<p style='font-size:13px; color:#7f1d1d; margin:6px 0 0 0;'>Due to the large volume of changes, the previous snapshot (with removals highlighted in red) and new snapshot (with additions highlighted in green) have been attached as standalone HTML files for your review.</p>";
                $htmlBody .= "</div>";
            }
        } elseif (!empty($context['diff'])) {
            $htmlBody .= "<h3>Detected Change Diff</h3>";
            $htmlBody .= "<pre style='background:#f4f6f8; padding:12px; border-radius:6px; font-family:monospace; font-size:12px; border-left:4px solid #0284c7; overflow-x:auto;'>" . htmlspecialchars($context['diff']) . "</pre>";
        }

        $htmlBody .= "<hr style='border:none; border-top:1px solid #e2e8f0; margin-top:24px;'>";
        $htmlBody .= "<p style='font-size:11px; color:#94a3b8;'>Sent by Website Change Monitor - Bluehost</p>";
        $htmlBody .= "</div>";

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

            $boundary = "==Multipart_Boundary_x" . md5((string)time() . uniqid()) . "x";
            $headers = "From: Change Monitor <$user>\r\n";
            $headers .= "To: <$to>\r\n";
            $headers .= "Subject: =?UTF-8?B?" . base64_encode($subject) . "?=\r\n";
            $headers .= "MIME-Version: 1.0\r\n";

            if (!empty($attachments)) {
                $headers .= "Content-Type: multipart/mixed; boundary=\"{$boundary}\"\r\n\r\n";

                $emailContent = $headers;
                // HTML body part
                $emailContent .= "--{$boundary}\r\n";
                $emailContent .= "Content-Type: text/html; charset=UTF-8\r\n";
                $emailContent .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
                $emailContent .= $htmlBody . "\r\n\r\n";

                // Attachment parts
                foreach ($attachments as $att) {
                    $filename = $att['filename'];
                    $content = $att['content'];
                    $mime = $att['mime_type'] ?? 'text/html';
                    $base64 = chunk_split(base64_encode($content));

                    $emailContent .= "--{$boundary}\r\n";
                    $emailContent .= "Content-Type: {$mime}; name=\"{$filename}\"\r\n";
                    $emailContent .= "Content-Transfer-Encoding: base64\r\n";
                    $emailContent .= "Content-Disposition: attachment; filename=\"{$filename}\"\r\n\r\n";
                    $emailContent .= $base64 . "\r\n\r\n";
                }

                $emailContent .= "--{$boundary}--\r\n";
            } else {
                $headers .= "Content-Type: text/html; charset=UTF-8\r\n\r\n";
                $emailContent = $headers . $htmlBody . "\r\n";
            }

            $emailContent .= ".\r\n";
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
