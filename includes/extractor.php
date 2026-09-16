<?php
/**
 * Extractor & Diff Engine for HTML, XPath, JSON, Text, and Regex
 * Website Change Monitor
 */

declare(strict_types=1);

class Extractor {
    /**
     * Extract specific target content based on selector mode
     *
     * @param string $rawBody The raw HTTP response body
     * @param string $type Mode: 'html_full', 'xpath', 'css', 'json', 'regex', 'text', 'headers'
     * @param string $selector The selector string (e.g. xpath expression, json dot-path, or regex)
     * @param array $headers Response headers (optional)
     * @param array $options Additional options: [ 'strip_tags' => bool, 'trim_whitespace' => bool, 'ignore_case' => bool ]
     * @return array [ 'success' => bool, 'extracted' => string, 'error' => ?string ]
     */
    public static function extract(string $rawBody, string $type, string $selector = '', array $headers = [], array $options = []): array {
        $stripTags = !empty($options['strip_tags']);
        $trimWhitespace = $options['trim_whitespace'] ?? true;

        try {
            $extracted = '';
            switch ($type) {
                case 'json':
                    $extracted = self::extractJson($rawBody, $selector);
                    break;

                case 'xpath':
                    $extracted = self::extractXPath($rawBody, $selector, $stripTags);
                    break;

                case 'css':
                    // Convert simple CSS selector to XPath and evaluate
                    $xpath = self::cssToXPath($selector);
                    $extracted = self::extractXPath($rawBody, $xpath, $stripTags);
                    break;

                case 'regex':
                    $extracted = self::extractRegex($rawBody, $selector);
                    break;

                case 'headers':
                    if (empty($selector)) {
                        $extracted = json_encode($headers, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                    } else {
                        $extracted = (string)($headers[$selector] ?? $headers[strtolower($selector)] ?? 'HEADER_NOT_FOUND');
                    }
                    break;

                case 'html_full':
                case 'text':
                default:
                    $extracted = $rawBody;
                    if ($stripTags) {
                        $extracted = strip_tags($extracted);
                    }
                    break;
            }

            if ($trimWhitespace) {
                // Normalize line breaks and multiple whitespace
                $extracted = preg_replace('/[ \t]+/', ' ', $extracted);
                $extracted = preg_replace('/\n\s*\n+/', "\n\n", $extracted);
                $extracted = trim((string)$extracted);
            }

            return [
                'success' => true,
                'extracted' => $extracted,
                'error' => null,
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'extracted' => '',
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Extract nested value from JSON via dot-notation (e.g. data.items[0].price or data.user.id)
     */
    private static function extractJson(string $jsonString, string $selector): string {
        $data = json_decode($jsonString, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException('Invalid JSON response: ' . json_last_error_msg());
        }

        $selector = trim($selector);
        if (empty($selector) || $selector === '$' || $selector === '.') {
            return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        // Normalize array brackets: data.items[0].price -> data.items.0.price
        $normalized = preg_replace('/\[(\d+)\]/', '.$1', $selector);
        $normalized = ltrim($normalized, '$.');
        $parts = explode('.', $normalized);

        $current = $data;
        foreach ($parts as $part) {
            if ($part === '') continue;
            if (is_array($current) && array_key_exists($part, $current)) {
                $current = $current[$part];
            } else {
                throw new \RuntimeException("JSON key '$part' not found in path '$selector'");
            }
        }

        if (is_array($current)) {
            return json_encode($current, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        return (string)$current;
    }

    /**
     * Extract element(s) using XPath
     */
    private static function extractXPath(string $html, string $xpathExpr, bool $stripTags = false): string {
        if (empty(trim($html))) {
            return '';
        }
        if (empty(trim($xpathExpr))) {
            return $stripTags ? strip_tags($html) : $html;
        }

        libxml_use_internal_errors(true);
        $dom = new \DOMDocument();
        // Load with UTF-8 hint
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        $xpath = new \DOMXPath($dom);
        $nodes = @$xpath->query($xpathExpr);

        if ($nodes === false) {
            throw new \RuntimeException("Invalid XPath query: $xpathExpr");
        }

        if ($nodes->length === 0) {
            throw new \RuntimeException("No elements matched XPath: $xpathExpr");
        }

        $results = [];
        foreach ($nodes as $node) {
            if ($node instanceof \DOMNode) {
                if ($stripTags) {
                    $results[] = trim($node->textContent);
                } else {
                    $results[] = trim($dom->saveHTML($node));
                }
            }
        }

        return implode("\n\n", $results);
    }

    /**
     * Regex extractor
     */
    private static function extractRegex(string $text, string $pattern): string {
        if (empty(trim($pattern))) {
            return $text;
        }
        // Ensure delimiters if missing
        if (!preg_match('/^([\/#~%]).*\1[imsxADSUXJu]*$/', $pattern)) {
            $pattern = '/' . str_replace('/', '\/', $pattern) . '/ims';
        }

        $matched = @preg_match_all($pattern, $text, $matches);
        if ($matched === false) {
            throw new \RuntimeException("Regex compilation failed: " . preg_last_error_msg());
        }
        if ($matched === 0) {
            throw new \RuntimeException("No matches found for regex pattern: $pattern");
        }

        // Return first capture group if exists, or entire match
        if (isset($matches[1]) && !empty($matches[1])) {
            return implode("\n", $matches[1]);
        }
        return implode("\n", $matches[0]);
    }

    /**
     * Basic CSS Selector to XPath Converter
     */
    public static function cssToXPath(string $css): string {
        $css = trim($css);
        if (empty($css)) return '';
        if (str_starts_with($css, '/') || str_starts_with($css, '(')) {
            return $css; // Already XPath
        }

        // #id
        if (preg_match('/^#([a-zA-Z0-9_\-]+)$/', $css, $m)) {
            return "//*[@id='{$m[1]}']";
        }
        // .class
        if (preg_match('/^\.([a-zA-Z0-9_\-]+)$/', $css, $m)) {
            return "//*[contains(concat(' ', normalize-space(@class), ' '), ' {$m[1]} ')]";
        }
        // tag#id
        if (preg_match('/^([a-zA-Z0-9]+)#([a-zA-Z0-9_\-]+)$/', $css, $m)) {
            return "//{$m[1]}[@id='{$m[2]}']";
        }
        // tag.class
        if (preg_match('/^([a-zA-Z0-9]+)\.([a-zA-Z0-9_\-]+)$/', $css, $m)) {
            return "//{$m[1]}[contains(concat(' ', normalize-space(@class), ' '), ' {$m[2]} ')]";
        }
        // tag[attr=val]
        if (preg_match('/^([a-zA-Z0-9_\-\*]+)?\[([a-zA-Z0-9_\-]+)=["\']?([^"\'\]]+)["\']?\]$/', $css, $m)) {
            $tag = !empty($m[1]) ? $m[1] : '*';
            return "//{$tag}[@{$m[2]}='{$m[3]}']";
        }
        // General tag
        if (preg_match('/^[a-zA-Z0-9_\-]+$/', $css)) {
            return "//{$css}";
        }

        // Fallback generic xpath query
        return "//*[self::{$css}]";
    }

    /**
     * Compute unified diff lines between old and new text
     */
    public static function computeDiff(string $oldText, string $newText): array {
        $oldLines = explode("\n", str_replace("\r", "", $oldText));
        $newLines = explode("\n", str_replace("\r", "", $newText));

        // Generate simple line-by-line diff
        $diffLines = [];
        $added = 0;
        $removed = 0;

        $max = max(count($oldLines), count($newLines));
        for ($i = 0; $i < $max; $i++) {
            $oldL = $oldLines[$i] ?? null;
            $newL = $newLines[$i] ?? null;

            if ($oldL === $newL) {
                if ($oldL !== null) {
                    $diffLines[] = ['type' => 'unchanged', 'text' => $oldL];
                }
            } else {
                if ($oldL !== null && !in_array($oldL, $newLines, true)) {
                    $diffLines[] = ['type' => 'removed', 'text' => $oldL];
                    $removed++;
                }
                if ($newL !== null && !in_array($newL, $oldLines, true)) {
                    $diffLines[] = ['type' => 'added', 'text' => $newL];
                    $added++;
                }
            }
        }

        $rawDiffString = "";
        foreach ($diffLines as $item) {
            $prefix = match($item['type']) {
                'added' => '+ ',
                'removed' => '- ',
                default => '  ',
            };
            $rawDiffString .= $prefix . $item['text'] . "\n";
        }

        return [
            'lines' => $diffLines,
            'raw' => $rawDiffString,
            'added_count' => $added,
            'removed_count' => $removed,
            'has_changes' => ($added > 0 || $removed > 0 || hash('sha256', $oldText) !== hash('sha256', $newText)),
        ];
    }
}
