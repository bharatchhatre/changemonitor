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
    /**
     * Extract specific target content based on selector mode
     *
     * @param string $rawBody The raw HTTP response body
     * @param string $type Mode: 'html_full', 'xpath', 'css', 'json', 'regex', 'text', 'headers'
     * @param string $selector The selector string (supports multiple separated by comma or newlines)
     * @param array $headers Response headers (optional)
     * @param array $options Additional options: [ 'strip_tags' => bool, 'trim_whitespace' => bool, 'ignore_case' => bool, 'ignore_selector' => string ]
     * @return array [ 'success' => bool, 'extracted' => string, 'error' => ?string ]
     */
    public static function extract(string $rawBody, string $type, string $selector = '', array $headers = [], array $options = []): array {
        $stripTags = !empty($options['strip_tags']);
        $trimWhitespace = $options['trim_whitespace'] ?? true;
        $ignoreSelector = trim((string)($options['ignore_selector'] ?? ''));

        try {
            $extracted = '';
            switch ($type) {
                case 'json':
                    $extracted = self::extractJson($rawBody, $selector, $ignoreSelector);
                    break;

                case 'xpath':
                    $extracted = self::extractXPath($rawBody, $selector, $stripTags, $ignoreSelector);
                    break;

                case 'css':
                    $extracted = self::extractCss($rawBody, $selector, $stripTags, $ignoreSelector);
                    break;

                case 'regex':
                    $extracted = self::extractRegex($rawBody, $selector, $ignoreSelector);
                    break;

                case 'headers':
                    $extracted = self::extractHeaders($headers, $selector, $ignoreSelector);
                    break;

                case 'html_full':
                case 'text':
                default:
                    $extracted = self::extractFullHtmlOrText($rawBody, $stripTags, $ignoreSelector);
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
     * Split multiple selectors (comma-separated or newline-separated)
     */
    public static function parseMultiSelectors(string $selector): array {
        $trimmed = trim($selector);
        if ($trimmed === '') return [];

        // If it contains newlines, split by newline
        if (strpos($trimmed, "\n") !== false) {
            $parts = explode("\n", $trimmed);
        } elseif (strpos($trimmed, ',') !== false) {
            // Split by top-level commas, skipping commas inside quotes ('...' or "...") and parentheses/brackets
            $parts = [];
            $len = strlen($trimmed);
            $buf = '';
            $inSingleQuote = false;
            $inDoubleQuote = false;
            $parenDepth = 0;
            $bracketDepth = 0;

            for ($i = 0; $i < $len; $i++) {
                $char = $trimmed[$i];

                if ($char === "'" && !$inDoubleQuote) {
                    $inSingleQuote = !$inSingleQuote;
                } elseif ($char === '"' && !$inSingleQuote) {
                    $inDoubleQuote = !$inDoubleQuote;
                } elseif (!$inSingleQuote && !$inDoubleQuote) {
                    if ($char === '(') $parenDepth++;
                    elseif ($char === ')' && $parenDepth > 0) $parenDepth--;
                    elseif ($char === '[') $bracketDepth++;
                    elseif ($char === ']' && $bracketDepth > 0) $bracketDepth--;
                    elseif ($char === ',' && $parenDepth === 0 && $bracketDepth === 0) {
                        $parts[] = $buf;
                        $buf = '';
                        continue;
                    }
                }
                $buf .= $char;
            }
            if ($buf !== '') {
                $parts[] = $buf;
            }
        } else {
            $parts = [$trimmed];
        }

        $clean = [];
        foreach ($parts as $p) {
            $p = trim($p);
            if ($p !== '') {
                $clean[] = $p;
            }
        }
        return $clean;
    }

    /**
     * Extract nested value(s) from JSON via dot-notation (e.g. data.items[0].price or $.timestamp)
     * and strip ignore paths.
     */
    private static function extractJson(string $jsonString, string $selector, string $ignoreSelector = ''): string {
        $data = json_decode($jsonString, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException('Invalid JSON response: ' . json_last_error_msg());
        }

        // Apply ignore paths first if given
        $ignorePaths = self::parseMultiSelectors($ignoreSelector);
        if (!empty($ignorePaths) && is_array($data)) {
            foreach ($ignorePaths as $ignPath) {
                $data = self::removeJsonPath($data, $ignPath);
            }
        }

        $selectors = self::parseMultiSelectors($selector);
        if (empty($selectors) || (count($selectors) === 1 && in_array($selectors[0], ['$', '.', ''], true))) {
            return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        $results = [];
        foreach ($selectors as $singleSel) {
            $extractedItem = self::extractSingleJsonPath($data, $singleSel);
            if (count($selectors) === 1) {
                return is_array($extractedItem)
                    ? json_encode($extractedItem, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                    : (string)$extractedItem;
            }
            $results[$singleSel] = $extractedItem;
        }

        return json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Extract single dot-notation path from JSON array
     */
    private static function extractSingleJsonPath(mixed $data, string $selector): mixed {
        $normalized = preg_replace('/\[(\d+)\]/', '.$1', trim($selector));
        $normalized = ltrim($normalized, '$.');
        if ($normalized === '') {
            return $data;
        }
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
        return $current;
    }

    /**
     * Recursively remove a path or wildcards from JSON data
     */
    private static function removeJsonPath(mixed $data, string $path): mixed {
        if (!is_array($data)) {
            return $data;
        }
        $normalized = preg_replace('/\[(\d+)\]/', '.$1', trim($path));
        $normalized = ltrim($normalized, '$.');
        if ($normalized === '') {
            return $data;
        }

        $parts = explode('.', $normalized);
        return self::removeJsonPathRecursive($data, $parts);
    }

    private static function removeJsonPathRecursive(mixed $data, array $parts): mixed {
        if (!is_array($data) || empty($parts)) {
            return $data;
        }

        $key = array_shift($parts);

        // Wildcard key matching: e.g. items.*.timestamp or *.timestamp
        if ($key === '*') {
            $res = [];
            foreach ($data as $k => $val) {
                if (empty($parts)) {
                    // Remove all keys at this level
                    continue;
                }
                $res[$k] = self::removeJsonPathRecursive($val, $parts);
            }
            return $res;
        }

        // If array of items and key is numeric
        if (is_numeric($key) && array_key_exists((int)$key, $data)) {
            if (empty($parts)) {
                unset($data[(int)$key]);
                return array_values($data);
            }
            $data[(int)$key] = self::removeJsonPathRecursive($data[(int)$key], $parts);
            return $data;
        }

        // Direct key match
        if (array_key_exists($key, $data)) {
            if (empty($parts)) {
                unset($data[$key]);
            } else {
                $data[$key] = self::removeJsonPathRecursive($data[$key], $parts);
            }
            return $data;
        }

        // If key is not at current root level, search deeper into child associative arrays and lists
        $res = [];
        $partsWithKey = array_merge([$key], $parts);
        foreach ($data as $k => $val) {
            if (is_array($val)) {
                $res[$k] = self::removeJsonPathRecursive($val, $partsWithKey);
            } else {
                $res[$k] = $val;
            }
        }
        return $res;
    }

    /**
     * Extract full HTML or text with ignore selector stripping
     */
    private static function extractFullHtmlOrText(string $html, bool $stripTags = false, string $ignoreSelector = ''): string {
        if (empty(trim($html))) {
            return '';
        }

        if (!empty(trim($ignoreSelector))) {
            $dom = self::createHtmlDom($html);
            if ($dom) {
                self::stripIgnoredDomNodes($dom, $ignoreSelector);
                $html = $dom->saveHTML();
            }
        }

        return $stripTags ? strip_tags($html) : $html;
    }

    /**
     * Extract element(s) using CSS selectors
     */
    private static function extractCss(string $html, string $cssSelector, bool $stripTags = false, string $ignoreSelector = ''): string {
        $selectors = self::parseMultiSelectors($cssSelector);
        if (empty($selectors)) {
            return self::extractFullHtmlOrText($html, $stripTags, $ignoreSelector);
        }

        $xpathQueries = array_map([self::class, 'cssToXPath'], $selectors);
        $combinedXPath = implode("\n", $xpathQueries);

        // Convert CSS ignore selector if present
        $ignoreXPath = '';
        if (!empty(trim($ignoreSelector))) {
            $ignoreSelectors = self::parseMultiSelectors($ignoreSelector);
            $ignoreXPaths = array_map([self::class, 'cssToXPath'], $ignoreSelectors);
            $ignoreXPath = implode("\n", $ignoreXPaths);
        }

        return self::extractXPath($html, $combinedXPath, $stripTags, $ignoreXPath);
    }

    /**
     * Extract element(s) using XPath with ignore selector support
     */
    private static function extractXPath(string $html, string $xpathExpr, bool $stripTags = false, string $ignoreSelector = ''): string {
        if (empty(trim($html))) {
            return '';
        }
        if (empty(trim($xpathExpr))) {
            return self::extractFullHtmlOrText($html, $stripTags, $ignoreSelector);
        }

        $dom = self::createHtmlDom($html);
        if (!$dom) {
            throw new \RuntimeException("Failed to parse HTML document");
        }

        if (!empty(trim($ignoreSelector))) {
            self::stripIgnoredDomNodes($dom, $ignoreSelector);
        }

        $xpath = new \DOMXPath($dom);

        // Support multiple selectors separated by newline or pipe
        $selectors = self::parseMultiSelectors($xpathExpr);
        $allResults = [];

        foreach ($selectors as $expr) {
            $nodes = @$xpath->query($expr);
            if ($nodes === false) {
                throw new \RuntimeException("Invalid XPath query: $expr");
            }
            if ($nodes->length === 0) {
                // If single selector, error; if multi-selector, continue
                if (count($selectors) === 1) {
                    throw new \RuntimeException("No elements matched XPath: $expr");
                }
                continue;
            }

            foreach ($nodes as $node) {
                if ($node instanceof \DOMNode) {
                    if ($stripTags) {
                        $allResults[] = trim($node->textContent);
                    } else {
                        $allResults[] = trim($dom->saveHTML($node));
                    }
                }
            }
        }

        if (empty($allResults) && !empty($selectors)) {
            throw new \RuntimeException("No elements matched the specified selectors: $xpathExpr");
        }

        return implode("\n\n", $allResults);
    }

    /**
     * Helper to create DOMDocument safely
     */
    private static function createHtmlDom(string $html): ?\DOMDocument {
        libxml_use_internal_errors(true);
        $dom = new \DOMDocument();
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        return $dom;
    }

    /**
     * Strip matching DOM nodes according to CSS / XPath ignore selectors
     */
    private static function stripIgnoredDomNodes(\DOMDocument $dom, string $ignoreSelector): void {
        $selectors = self::parseMultiSelectors($ignoreSelector);
        if (empty($selectors)) return;

        $xpath = new \DOMXPath($dom);
        foreach ($selectors as $sel) {
            $xpathQuery = self::cssToXPath($sel);
            $nodes = @$xpath->query($xpathQuery);
            if ($nodes && $nodes->length > 0) {
                // Convert to array to avoid mutation during node deletion
                $nodeList = [];
                foreach ($nodes as $node) {
                    $nodeList[] = $node;
                }
                foreach ($nodeList as $node) {
                    if ($node->parentNode) {
                        $node->parentNode->removeChild($node);
                    }
                }
            }
        }
    }

    /**
     * Regex extractor with multiple patterns and ignore regex support
     */
    private static function extractRegex(string $text, string $pattern, string $ignorePattern = ''): string {
        if (!empty(trim($ignorePattern))) {
            $ignorePatterns = self::parseMultiSelectors($ignorePattern);
            foreach ($ignorePatterns as $ignPat) {
                $delIgn = self::normalizeRegexDelimiter($ignPat);
                $text = (string)@preg_replace($delIgn, '', $text);
            }
        }

        if (empty(trim($pattern))) {
            return $text;
        }

        $patterns = self::parseMultiSelectors($pattern);
        $results = [];

        foreach ($patterns as $singlePattern) {
            $delPattern = self::normalizeRegexDelimiter($singlePattern);
            $matched = @preg_match_all($delPattern, $text, $matches);
            if ($matched === false) {
                throw new \RuntimeException("Regex compilation failed for: $singlePattern - " . preg_last_error_msg());
            }
            if ($matched === 0) {
                if (count($patterns) === 1) {
                    throw new \RuntimeException("No matches found for regex pattern: $singlePattern");
                }
                continue;
            }

            if (isset($matches[1]) && !empty($matches[1])) {
                $results[] = implode("\n", $matches[1]);
            } else {
                $results[] = implode("\n", $matches[0]);
            }
        }

        if (empty($results) && !empty($patterns)) {
            throw new \RuntimeException("No matches found for regex pattern: $pattern");
        }

        return implode("\n\n", $results);
    }

    private static function normalizeRegexDelimiter(string $pattern): string {
        $pattern = trim($pattern);
        if (!preg_match('/^([\/#~%]).*\1[imsxADSUXJu]*$/', $pattern)) {
            return '/' . str_replace('/', '\/', $pattern) . '/ims';
        }
        return $pattern;
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
     * Response headers extractor with ignore support
     */
    private static function extractHeaders(array $headers, string $selector = '', string $ignoreSelector = ''): string {
        $ignoreKeys = array_map('strtolower', self::parseMultiSelectors($ignoreSelector));

        $filtered = [];
        foreach ($headers as $k => $v) {
            if (!in_array(strtolower((string)$k), $ignoreKeys, true)) {
                $filtered[$k] = $v;
            }
        }

        if (empty($selector)) {
            return json_encode($filtered, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        }

        $selectors = self::parseMultiSelectors($selector);
        $results = [];
        foreach ($selectors as $sel) {
            $val = $filtered[$sel] ?? $filtered[strtolower($sel)] ?? null;
            if ($val !== null) {
                $results[$sel] = $val;
            }
        }

        if (count($selectors) === 1) {
            $single = reset($results);
            return $single !== false ? (string)$single : 'HEADER_NOT_FOUND';
        }

        return json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
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
