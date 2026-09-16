<?php
/**
 * CLI Test Suite for Change Monitor
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/storage.php';
require_once __DIR__ . '/../includes/fetcher.php';
require_once __DIR__ . '/../includes/extractor.php';
require_once __DIR__ . '/../includes/engine.php';

echo "=== Running ChangeMonitor Unit & Integration Tests ===\n";

// 1. Test Extractor JSON
$sampleJson = '{"status":"ok","data":{"items":[{"id":1,"price":"$99"},{"id":2,"price":"$149"}]}}';
$jsonExtracted = Extractor::extract($sampleJson, 'json', 'data.items[0].price');
assert($jsonExtracted['extracted'] === '$99', "JSON extraction test failed");
echo "✅ JSON Extraction Test Passed: " . $jsonExtracted['extracted'] . "\n";

// 2. Test Extractor XPath
$sampleHtml = '<html><body><div id="content"><span class="price">$45.00</span><p class="desc">Sample product</p></div></body></html>';
$xpathExtracted = Extractor::extract($sampleHtml, 'xpath', '//span[@class="price"]', [], ['strip_tags' => true]);
assert($xpathExtracted['extracted'] === '$45.00', "XPath extraction test failed");
echo "✅ XPath Extraction Test Passed: " . $xpathExtracted['extracted'] . "\n";

// 3. Test Extractor CSS Selector
$cssExtracted = Extractor::extract($sampleHtml, 'css', '.desc', [], ['strip_tags' => true]);
assert($cssExtracted['extracted'] === 'Sample product', "CSS extraction test failed");
echo "✅ CSS Extraction Test Passed: " . $cssExtracted['extracted'] . "\n";

// 4. Test Diff Engine
$diff = Extractor::computeDiff("Line 1\nPrice: $10\nLine 3", "Line 1\nPrice: $20\nLine 3");
assert($diff['has_changes'] === true, "Diff engine change detection failed");
assert($diff['added_count'] === 1 && $diff['removed_count'] === 1, "Diff count failed");
echo "✅ Diff Engine Test Passed\n";

// 5. Test Storage CRUD & Monitor Engine
$testMonitorId = Storage::saveMonitor([
    'name' => 'Test HTTP Target',
    'url' => 'https://httpbin.org/get',
    'type' => 'json',
    'selector' => 'url',
    'interval_mins' => 1,
]);
assert(!empty($testMonitorId), "Storage saveMonitor failed");
echo "✅ Storage Monitor Saved with ID: $testMonitorId\n";

$fetchResult = Engine::run($testMonitorId, true);
assert($fetchResult['success'] === true, "Engine run failed: " . ($fetchResult['error'] ?? ''));
echo "✅ Engine Run Test Passed (HTTP " . $fetchResult['http_code'] . " in " . $fetchResult['duration_ms'] . "ms)\n";

// Clean up test monitor
Storage::deleteMonitor($testMonitorId);
echo "✅ Cleanup Test Monitor Passed\n";

echo "=== All Tests Passed Successfully! ===\n";
