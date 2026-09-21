<?php
/**
 * Contextual Diff Formatter
 * Website Change Monitor
 *
 * Computes structured line-by-line diffs with JSON/HTML tree context,
 * line numbers, and color-coded formatting for Email, Telegram, WhatsApp, and standalone HTML files.
 */

declare(strict_types=1);

class DiffFormatter {
    /**
     * Compute a rich, structured contextual diff
     *
     * @param string $oldText Previous snapshot content
     * @param string $newText Current snapshot content
     * @param string $type Extraction type (json, html_full, xpath, css, regex, headers)
     * @param int $bigChangeLineThreshold Threshold of changed lines to qualify as "big"
     * @param int $bigChangeCharThreshold Threshold of changed characters to qualify as "big"
     * @return array
     */
    public static function computeContextualDiff(
        string $oldText,
        string $newText,
        string $type = 'html_full',
        int $bigChangeLineThreshold = 30,
        int $bigChangeCharThreshold = 2500
    ): array {
        $oldTextClean = str_replace("\r\n", "\n", str_replace("\r", "\n", $oldText));
        $newTextClean = str_replace("\r\n", "\n", str_replace("\r", "\n", $newText));

        $isJson = ($type === 'json' || self::isValidJson($oldTextClean) || self::isValidJson($newTextClean));

        if ($isJson) {
            return self::computeJsonDiff($oldTextClean, $newTextClean, $bigChangeLineThreshold, $bigChangeCharThreshold);
        }

        return self::computeTextDiff($oldTextClean, $newTextClean, $type, $bigChangeLineThreshold, $bigChangeCharThreshold);
    }

    /**
     * Helper to verify if string is valid JSON
     */
    private static function isValidJson(string $string): bool {
        $trimmed = trim($string);
        if ($trimmed === '' || (!str_starts_with($trimmed, '{') && !str_starts_with($trimmed, '['))) {
            return false;
        }
        json_decode($trimmed);
        return json_last_error() === JSON_ERROR_NONE;
    }

    /**
     * Compute diff for JSON payloads with key hierarchy and property paths
     */
    private static function computeJsonDiff(
        string $oldText,
        string $newText,
        int $lineThreshold,
        int $charThreshold
    ): array {
        $oldDecoded = json_decode($oldText, true);
        $newDecoded = json_decode($newText, true);

        // Pretty print for stable line diffing
        $oldPretty = ($oldDecoded !== null) ? json_encode($oldDecoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : $oldText;
        $newPretty = ($newDecoded !== null) ? json_encode($newDecoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : $newText;

        $oldLines = explode("\n", (string)$oldPretty);
        $newLines = explode("\n", (string)$newPretty);

        $lineDiff = self::diffLines($oldLines, $newLines);

        // Attempt key path extraction
        $items = [];
        $addedCount = 0;
        $removedCount = 0;

        foreach ($lineDiff as $item) {
            $type = $item['type'];
            $text = $item['text'];
            $lineNo = $item['line_no'];

            if ($type === 'added') $addedCount++;
            if ($type === 'removed') $removedCount++;

            // Extract property key from JSON line e.g. "price": 99,
            $keyContext = '';
            if (preg_match('/^\s*"([^"]+)"\s*:\s*(.*)$/', $text, $m)) {
                $keyContext = $m[1];
            }

            $items[] = [
                'type' => $type,
                'line_no' => $lineNo,
                'context' => $keyContext,
                'text' => $text,
            ];
        }

        $totalChangedLines = $addedCount + $removedCount;
        $isBig = ($totalChangedLines > $lineThreshold);

        return [
            'is_json' => true,
            'is_big_change' => $isBig,
            'added_count' => $addedCount,
            'removed_count' => $removedCount,
            'total_changed' => $totalChangedLines,
            'items' => $items,
            'old_text' => (string)$oldPretty,
            'new_text' => (string)$newPretty,
        ];
    }

    /**
     * Compute diff for HTML and raw text
     */
    private static function computeTextDiff(
        string $oldText,
        string $newText,
        string $contentType,
        int $lineThreshold,
        int $charThreshold
    ): array {
        $oldLines = explode("\n", $oldText);
        $newLines = explode("\n", $newText);

        $lineDiff = self::diffLines($oldLines, $newLines);

        $items = [];
        $addedCount = 0;
        $removedCount = 0;

        $currentTagContext = '';

        foreach ($lineDiff as $item) {
            $type = $item['type'];
            $text = $item['text'];
            $lineNo = $item['line_no'];

            if ($type === 'added') $addedCount++;
            if ($type === 'removed') $removedCount++;

            // Detect prominent HTML tag/header context
            if (preg_match('/<([a-zA-Z0-9\-]+)([^>]*)>/', $text, $m)) {
                $tagName = $m[1];
                $classes = '';
                if (preg_match('/class=["\']([^"\']+)["\']/', $m[2], $cm)) {
                    $classes = '.' . implode('.', array_filter(explode(' ', trim($cm[1]))));
                }
                $id = '';
                if (preg_match('/id=["\']([^"\']+)["\']/', $m[2], $im)) {
                    $id = '#' . trim($im[1]);
                }
                $currentTagContext = "<{$tagName}{$id}{$classes}>";
            }

            $items[] = [
                'type' => $type,
                'line_no' => $lineNo,
                'context' => $currentTagContext,
                'text' => $text,
            ];
        }

        $totalChangedLines = $addedCount + $removedCount;
        $isBig = ($totalChangedLines > $lineThreshold);

        return [
            'is_json' => false,
            'is_big_change' => $isBig,
            'added_count' => $addedCount,
            'removed_count' => $removedCount,
            'total_changed' => $totalChangedLines,
            'items' => $items,
            'old_text' => $oldText,
            'new_text' => $newText,
        ];
    }

    /**
     * Line-by-line diff algorithm
     */
    private static function diffLines(array $oldLines, array $newLines): array {
        $max = max(count($oldLines), count($newLines));
        $diff = [];

        $oldIndex = 0;
        $newIndex = 0;

        while ($oldIndex < count($oldLines) || $newIndex < count($newLines)) {
            $oldL = $oldLines[$oldIndex] ?? null;
            $newL = $newLines[$newIndex] ?? null;

            if ($oldL === $newL) {
                if ($oldL !== null) {
                    $diff[] = [
                        'type' => 'unchanged',
                        'line_no' => $newIndex + 1,
                        'text' => $oldL,
                    ];
                }
                $oldIndex++;
                $newIndex++;
            } else {
                // Check if old line was removed
                if ($oldL !== null && !in_array($oldL, array_slice($newLines, $newIndex, 5), true)) {
                    $diff[] = [
                        'type' => 'removed',
                        'line_no' => $oldIndex + 1,
                        'text' => $oldL,
                    ];
                    $oldIndex++;
                } elseif ($newL !== null) {
                    $diff[] = [
                        'type' => 'added',
                        'line_no' => $newIndex + 1,
                        'text' => $newL,
                    ];
                    $newIndex++;
                } else {
                    $oldIndex++;
                }
            }
        }

        return $diff;
    }

    /**
     * Format inline HTML Diff Table for Email (with red/green highlights and line numbers)
     */
    public static function formatHtmlInline(array $diffData, array $options = []): string {
        $items = $diffData['items'] ?? [];
        $addedCount = (int)($diffData['added_count'] ?? 0);
        $removedCount = (int)($diffData['removed_count'] ?? 0);

        $html = '<div style="font-family: -apple-system, BlinkMacSystemFont, \'Segoe UI\', Roboto, Helvetica, Arial, sans-serif; margin: 16px 0;">';
        
        // Summary bar
        $html .= '<div style="display: flex; align-items: center; gap: 8px; margin-bottom: 12px; font-size: 13px; color: #475569;">';
        $html .= '<strong>Change Summary:</strong> ';
        $html .= '<span style="background: #dcfce7; color: #15803d; padding: 2px 8px; border-radius: 4px; font-weight: 600; font-size: 12px;">+' . $addedCount . ' added</span> ';
        $html .= '<span style="background: #fee2e2; color: #b91c1c; padding: 2px 8px; border-radius: 4px; font-weight: 600; font-size: 12px;">-' . $removedCount . ' removed</span>';
        $html .= '</div>';

        // Diff Table
        $html .= '<table style="width: 100%; border-collapse: collapse; font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace; font-size: 12px; line-height: 1.5; border: 1px solid #e2e8f0; border-radius: 6px; overflow: hidden; background: #ffffff;">';
        $html .= '<thead style="background: #f8fafc; border-bottom: 1px solid #e2e8f0; color: #64748b; font-size: 11px; text-transform: uppercase;">';
        $html .= '<tr>';
        $html .= '<th style="padding: 6px 10px; width: 45px; text-align: right; border-right: 1px solid #e2e8f0;">Line</th>';
        $html .= '<th style="padding: 6px 10px; width: 120px; text-align: left; border-right: 1px solid #e2e8f0;">Context / Key</th>';
        $html .= '<th style="padding: 6px 10px; text-align: left;">Content</th>';
        $html .= '</tr>';
        $html .= '</thead>';
        $html .= '<tbody>';

        // Filter only changed items and adjacent context lines
        $displayItems = [];
        $totalItems = count($items);
        for ($i = 0; $i < $totalItems; $i++) {
            $item = $items[$i];
            if ($item['type'] === 'added' || $item['type'] === 'removed') {
                // Include 1 preceding unchanged line if exists
                if ($i > 0 && ($items[$i - 1]['type'] ?? '') === 'unchanged' && !isset($displayItems[$i - 1])) {
                    $displayItems[$i - 1] = $items[$i - 1];
                }
                $displayItems[$i] = $item;
                // Include 1 following unchanged line if exists
                if ($i < $totalItems - 1 && ($items[$i + 1]['type'] ?? '') === 'unchanged') {
                    $displayItems[$i + 1] = $items[$i + 1];
                }
            }
        }
        ksort($displayItems);

        $limit = $options['max_lines'] ?? 50;
        $count = 0;

        foreach ($displayItems as $item) {
            if ($count >= $limit) {
                $html .= '<tr><td colspan="3" style="text-align: center; color: #64748b; padding: 8px; background: #f8fafc; font-style: italic;">... (diff truncated, ' . ($totalItems - $count) . ' more lines) ...</td></tr>';
                break;
            }
            $count++;

            $type = $item['type'];
            $lineNo = $item['line_no'];
            $ctx = htmlspecialchars($item['context'] ?? '');
            $text = htmlspecialchars($item['text'] ?? '');

            if ($type === 'added') {
                $bg = '#dcfce7'; // green
                $textColor = '#14532d';
                $sign = '<strong style="color: #16a34a; font-size: 14px;">+ </strong>';
                $titleText = "Click to view Line {$lineNo} in New Version (Raw Content)";
            } elseif ($type === 'removed') {
                $bg = '#fee2e2'; // red
                $textColor = '#7f1d1d';
                $sign = '<strong style="color: #dc2626; font-size: 14px;">- </strong>';
                $titleText = "Click to view Line {$lineNo} in Previous Version (Raw Content)";
            } else {
                $bg = '#ffffff';
                $textColor = '#475569';
                $sign = '&nbsp;&nbsp;';
                $titleText = "Click to view Line {$lineNo} in Raw Content";
            }

            $html .= "<tr class='diff-table-row diff-row-{$type}' data-line='{$lineNo}' data-type='{$type}' style='background: {$bg}; border-bottom: 1px solid #f1f5f9; cursor: pointer;' title='{$titleText}'>";
            $html .= "<td style='padding: 4px 8px; text-align: right; color: #94a3b8; font-size: 11px; border-right: 1px solid #e2e8f0; user-select: none; font-weight: 600;' class='diff-line-no'>{$lineNo}</td>";
            $html .= "<td style='padding: 4px 8px; color: #64748b; font-size: 11px; border-right: 1px solid #e2e8f0; word-break: break-all;'>{$ctx}</td>";
            $html .= "<td style='padding: 4px 10px; color: {$textColor}; white-space: pre-wrap; word-break: break-word;'>{$sign}{$text}</td>";
            $html .= "</tr>";
        }

        $html .= '</tbody>';
        $html .= '</table>';
        $html .= '</div>';

        return $html;
    }

    /**
     * Format Telegram Notification (with 🔴 - and 🟢 + and key/path tree indicators)
     */
    public static function formatTelegramText(string $subject, string $message, array $diffData, string $url = ''): string {
        $added = (int)($diffData['added_count'] ?? 0);
        $removed = (int)($diffData['removed_count'] ?? 0);

        $text = "🚨 *{$subject}*\n";
        $text .= $message . "\n";
        if (!empty($url)) {
            $text .= "🔗 *URL:* {$url}\n";
        }

        $text .= "\n📊 *Summary:* `+{$added} added` | ` -{$removed} removed`\n";
        $text .= "━━━━━━━━━━━━━━━━━━━━\n";

        $items = $diffData['items'] ?? [];
        $changedLines = array_filter($items, fn($i) => $i['type'] === 'added' || $i['type'] === 'removed');

        $lineCount = 0;
        foreach ($changedLines as $item) {
            if ($lineCount >= 15) {
                $text .= "\n_... (" . (count($changedLines) - $lineCount) . " more changes) ..._\n";
                break;
            }
            $lineCount++;

            $lineNo = $item['line_no'];
            $ctx = !empty($item['context']) ? " `[{$item['context']}]`" : "";
            $lineContent = trim((string)$item['text']);
            if (strlen($lineContent) > 120) {
                $lineContent = substr($lineContent, 0, 117) . '...';
            }

            if ($item['type'] === 'added') {
                $text .= "🟢 *+ [L{$lineNo}]*{$ctx}: `{$lineContent}`\n";
            } elseif ($item['type'] === 'removed') {
                $text .= "🔴 *- [L{$lineNo}]*{$ctx}: `{$lineContent}`\n";
            }
        }

        return $text;
    }

    /**
     * Format WhatsApp Notification (with ~🔴 -~ and *🟢 +* and tree paths)
     */
    public static function formatWhatsAppText(string $subject, string $message, array $diffData, string $url = ''): string {
        $added = (int)($diffData['added_count'] ?? 0);
        $removed = (int)($diffData['removed_count'] ?? 0);

        $text = "*🚨 {$subject}*\n\n";
        $text .= $message . "\n";
        if (!empty($url)) {
            $text .= "🔗 URL: {$url}\n";
        }

        $text .= "\n*📊 Summary:* +{$added} added | -{$removed} removed\n";
        $text .= "------------------------------------\n";

        $items = $diffData['items'] ?? [];
        $changedLines = array_filter($items, fn($i) => $i['type'] === 'added' || $i['type'] === 'removed');

        $lineCount = 0;
        foreach ($changedLines as $item) {
            if ($lineCount >= 15) {
                $text .= "\n_... (" . (count($changedLines) - $lineCount) . " more changes) ..._\n";
                break;
            }
            $lineCount++;

            $lineNo = $item['line_no'];
            $ctx = !empty($item['context']) ? " [{$item['context']}]" : "";
            $lineContent = trim((string)$item['text']);
            if (strlen($lineContent) > 120) {
                $lineContent = substr($lineContent, 0, 117) . '...';
            }

            if ($item['type'] === 'added') {
                $text .= "🟢 *+ [L{$lineNo}]{$ctx}:* {$lineContent}\n";
            } elseif ($item['type'] === 'removed') {
                $text .= "🔴 ~- [L{$lineNo}]{$ctx}: {$lineContent}~\n";
            }
        }

        return $text;
    }

    /**
     * Generate standalone self-contained HTML file for attachments
     *
     * @param string $content Snapshot text content
     * @param array $diffItems Diff items to highlight
     * @param string $mode 'old' (highlights removed lines in red) or 'new' (highlights added lines in green)
     * @param array $meta Additional target metadata (name, url, timestamp)
     * @return string Valid HTML5 document content
     */
    public static function generateStandaloneSnapshotHtml(
        string $content,
        array $diffItems,
        string $mode = 'new',
        array $meta = []
    ): string {
        $targetName = htmlspecialchars($meta['name'] ?? 'Target');
        $targetUrl = htmlspecialchars($meta['url'] ?? '');
        $timestamp = htmlspecialchars($meta['timestamp'] ?? date('Y-m-d H:i:s'));
        $title = ($mode === 'old') ? "Previous Snapshot (Removed in Red)" : "New Snapshot (Added in Green)";
        $badgeColor = ($mode === 'old') ? '#dc2626' : '#16a34a';

        // Index changed lines
        $highlightMap = [];
        foreach ($diffItems as $item) {
            if ($mode === 'old' && $item['type'] === 'removed') {
                $highlightMap[$item['line_no']] = ['type' => 'removed', 'context' => $item['context'] ?? ''];
            } elseif ($mode === 'new' && $item['type'] === 'added') {
                $highlightMap[$item['line_no']] = ['type' => 'added', 'context' => $item['context'] ?? ''];
            }
        }

        $lines = explode("\n", str_replace("\r", "", $content));

        $html = "<!DOCTYPE html>\n<html lang='en'>\n<head>\n<meta charset='UTF-8'>\n";
        $html .= "<meta name='viewport' content='width=device-width, initial-scale=1.0'>\n";
        $html .= "<title>{$title} - {$targetName}</title>\n";
        $html .= "<style>
            body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #0f172a; color: #e2e8f0; margin: 0; padding: 20px; }
            .header { background: #1e293b; padding: 16px 20px; border-radius: 8px; border: 1px solid #334155; margin-bottom: 20px; }
            .title { font-size: 18px; font-weight: 700; color: #fff; margin-bottom: 6px; }
            .badge { display: inline-block; padding: 3px 10px; border-radius: 4px; font-size: 12px; font-weight: 600; color: #fff; background: {$badgeColor}; margin-right: 10px; }
            .meta { font-size: 13px; color: #94a3b8; }
            .meta a { color: #38bdf8; text-decoration: none; }
            .code-table { width: 100%; border-collapse: collapse; font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace; font-size: 12px; background: #0b1120; border: 1px solid #1e293b; border-radius: 6px; overflow: hidden; }
            .code-table td { padding: 3px 8px; line-height: 1.5; }
            .line-no { width: 50px; text-align: right; color: #475569; border-right: 1px solid #1e293b; user-select: none; }
            .line-ctx { width: 130px; color: #64748b; font-size: 11px; border-right: 1px solid #1e293b; word-break: break-all; }
            .line-content { white-space: pre-wrap; word-break: break-all; }
            .highlight-removed { background: rgba(239, 68, 68, 0.25) !important; color: #fca5a5 !important; }
            .highlight-added { background: rgba(34, 197, 94, 0.25) !important; color: #86efac !important; }
        </style>\n</head>\n<body>\n";

        $html .= "<div class='header'>
            <div class='title'><span class='badge'>{$title}</span> {$targetName}</div>
            <div class='meta'>Captured: <strong>{$timestamp}</strong> | Target: <a href='{$targetUrl}' target='_blank'>{$targetUrl}</a></div>
        </div>\n";

        $html .= "<table class='code-table'>\n";
        foreach ($lines as $idx => $line) {
            $lineNo = $idx + 1;
            $isHighlighted = isset($highlightMap[$lineNo]);
            $highlightClass = '';
            $sign = '&nbsp;&nbsp;';
            $ctx = '';

            if ($isHighlighted) {
                $type = $highlightMap[$lineNo]['type'];
                $ctx = htmlspecialchars((string)$highlightMap[$lineNo]['context']);
                if ($type === 'removed') {
                    $highlightClass = 'highlight-removed';
                    $sign = '<strong style="color:#ef4444;">- </strong>';
                } elseif ($type === 'added') {
                    $highlightClass = 'highlight-added';
                    $sign = '<strong style="color:#22c55e;">+ </strong>';
                }
            }

            $safeLine = htmlspecialchars($line);
            $html .= "<tr class='{$highlightClass}'>
                <td class='line-no'>{$lineNo}</td>
                <td class='line-ctx'>{$ctx}</td>
                <td class='line-content'>{$sign}{$safeLine}</td>
            </tr>\n";
        }
        $html .= "</table>\n</body>\n</html>";

        return $html;
    }
}
