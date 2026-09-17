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

// 6. Test Bulk Storage Operations
$bulkTargets = [
    ['name' => 'Bulk Target 1', 'url' => 'https://httpbin.org/get?q=1'],
    ['name' => 'Bulk Target 2', 'url' => 'https://httpbin.org/get?q=2'],
    ['name' => 'Bulk Target 3', 'url' => 'https://httpbin.org/get?q=3'],
];
$savedBulkIds = Storage::saveMonitorsBulk($bulkTargets);
assert(count($savedBulkIds) === 3, "Bulk save failed to save 3 monitors");
echo "✅ Bulk Save Test Passed (Saved " . count($savedBulkIds) . " monitors)\n";

$pausedCount = Storage::bulkUpdateStatus($savedBulkIds, 'paused');
assert($pausedCount === 3, "Bulk pause failed");
$m1 = Storage::getMonitor($savedBulkIds[0]);
assert($m1['status'] === 'paused', "Monitor 1 status was not set to paused");
echo "✅ Bulk Pause Test Passed\n";

$activatedCount = Storage::bulkUpdateStatus($savedBulkIds, 'active');
assert($activatedCount === 3, "Bulk activate failed");
$m1 = Storage::getMonitor($savedBulkIds[0]);
assert($m1['status'] === 'active', "Monitor 1 status was not set to active");
echo "✅ Bulk Activate Test Passed\n";

// 7. Test Monitor Groups & Categorization
$groupTargets = [
    ['name' => 'API Health', 'url' => 'https://httpbin.org/get?q=api', 'group' => 'Infrastructure'],
    ['name' => 'Competitor A', 'url' => 'https://httpbin.org/get?q=comp', 'group' => 'Competitors'],
];
$savedGroupIds = Storage::saveMonitorsBulk($groupTargets);
$groups = Storage::getGroups();
assert(isset($groups['Infrastructure']) && isset($groups['Competitors']), "getGroups failed to return new groups");
echo "✅ Monitor Groups Detection Test Passed (" . count($groups) . " groups detected)\n";

$reassignedCount = Storage::bulkAssignGroup($savedGroupIds, 'Production');
assert($reassignedCount === 2, "bulkAssignGroup failed");
$mG1 = Storage::getMonitor($savedGroupIds[0]);
assert($mG1['group'] === 'Production', "Monitor group was not updated to Production");
echo "✅ Bulk Reassign Group Test Passed\n";

// 8. Test Bulk Edit
$bulkEditIds = Storage::saveMonitorsBulk([
    ['name' => 'Bulk Edit 1', 'url' => 'https://httpbin.org/get?be=1'],
    ['name' => 'Bulk Edit 2', 'url' => 'https://httpbin.org/get?be=2']
]);
$editedCount = Storage::bulkEditMonitors($bulkEditIds, [
    'group' => 'CustomGroup',
    'interval_mins' => 30,
    'timeout' => 45,
    'type' => 'xpath',
    'status' => 'paused'
]);
assert($editedCount === 2, "bulkEditMonitors count failed");
$be1 = Storage::getMonitor($bulkEditIds[0]);
assert($be1['group'] === 'CustomGroup' && $be1['interval_mins'] === 30 && $be1['timeout'] === 45 && $be1['type'] === 'xpath' && $be1['status'] === 'paused', "bulkEditMonitors field update failed");
echo "✅ Bulk Edit Monitors Test Passed\n";

// 8b. Test Bulk Edit Peak Schedule
Storage::bulkEditMonitors($bulkEditIds, [
    'peak_schedule_enabled' => true,
    'peak_start_hour' => 8,
    'peak_end_hour' => 20,
    'peak_interval_mins' => 3,
    'offpeak_interval_mins' => 45
]);
$pe1 = Storage::getMonitor($bulkEditIds[0]);
assert($pe1['peak_schedule_enabled'] === true && $pe1['peak_start_hour'] === 8 && $pe1['peak_end_hour'] === 20 && $pe1['peak_interval_mins'] === 3 && $pe1['offpeak_interval_mins'] === 45, "Bulk edit peak schedule failed");
echo "✅ Bulk Edit Peak Schedule Test Passed\n";

// 9. Test Soft Delete & Trash / Restore
$deletedCount = Storage::bulkDeleteMonitors($bulkEditIds);
assert($deletedCount === 2, "bulkDeleteMonitors failed");
$trashItems = Storage::getTrash();
assert(isset($trashItems[$bulkEditIds[0]]) && isset($trashItems[$bulkEditIds[1]]), "Trash missing deleted items");
assert(Storage::getMonitor($bulkEditIds[0]) === null, "Deleted monitor still present in active monitors");
echo "✅ Soft Delete to Trash Test Passed\n";

// Test Restore single and bulk
$restoredSingle = Storage::restoreMonitor($bulkEditIds[0]);
assert($restoredSingle === true, "restoreMonitor failed");
assert(Storage::getMonitor($bulkEditIds[0]) !== null, "Restored monitor not present in monitors");
echo "✅ Single Monitor Restore Test Passed\n";

$restoredBulk = Storage::bulkRestoreMonitors([$bulkEditIds[1]]);
assert($restoredBulk === 1, "bulkRestoreMonitors failed");
assert(Storage::getMonitor($bulkEditIds[1]) !== null, "Bulk restored monitor not present in monitors");
echo "✅ Bulk Restore Monitors Test Passed\n";

// Clean up test items
Storage::bulkDeleteMonitors($bulkEditIds);
Storage::emptyTrash();
Storage::bulkDeleteMonitors($savedBulkIds);
Storage::emptyTrash();

echo "=== All Tests Passed Successfully! ===\n";

