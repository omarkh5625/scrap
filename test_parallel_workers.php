<?php
/**
 * Test Script - Parallel Worker Execution Validation
 * 
 * This script validates that workers can spawn and run in parallel.
 * It creates a simple test that spawns multiple workers and verifies
 * they all run simultaneously (not sequentially).
 */

echo "=== Parallel Worker Execution Test ===\n\n";

// Test configuration
$testWorkerCount = 10; // Start with 10 workers for testing
$logFile = __DIR__ . '/test_parallel_workers.log';

// Clear old log file
if (file_exists($logFile)) {
    unlink($logFile);
}

echo "Test Configuration:\n";
echo "  - Workers to spawn: {$testWorkerCount}\n";
echo "  - Log file: {$logFile}\n\n";

// Create test worker script that logs start and end times
// NOTE: This is a test-only script - in production, workers are CLI processes
// that directly run the main application code, not separate script files
$workerScript = __DIR__ . '/test_worker_script.php';

// Secure worker script content with input validation
$workerScriptContent = '<?php
// Input validation: ensure worker ID is alphanumeric plus dash/underscore
$workerId = $argv[1] ?? "unknown";
if (!preg_match("/^[a-zA-Z0-9_-]+$/", $workerId)) {
    exit(1); // Exit silently if invalid worker ID
}

$logFile = __DIR__ . "/test_parallel_workers.log";

// Log start time
$startTime = microtime(true);
file_put_contents($logFile, sprintf("[%s] Worker %s STARTED at %.4f\n", date("Y-m-d H:i:s"), $workerId, $startTime), FILE_APPEND | LOCK_EX);

// Simulate work (2 seconds)
sleep(2);

// Log end time
$endTime = microtime(true);
file_put_contents($logFile, sprintf("[%s] Worker %s FINISHED at %.4f (duration: %.2f sec)\n", date("Y-m-d H:i:s"), $workerId, $endTime, $endTime - $startTime), FILE_APPEND | LOCK_EX);
';

// Write worker script with restricted permissions
file_put_contents($workerScript, $workerScriptContent);
chmod($workerScript, 0700); // Owner can read/write/execute only

echo "Phase 1: Testing proc_open parallel execution...\n";
$testStartTime = microtime(true);

// Spawn workers using proc_open (similar to the production code)
$processes = [];
$nullDevice = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN' ? 'NUL' : '/dev/null';

for ($i = 1; $i <= $testWorkerCount; $i++) {
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['file', $nullDevice, 'w'],
        2 => ['file', $nullDevice, 'w']
    ];
    
    $process = proc_open([PHP_BINARY, $workerScript, "worker-{$i}"], $descriptors, $pipes, null, null, ['bypass_shell' => true]);
    
    if (is_resource($process)) {
        if (isset($pipes[0])) {
            fclose($pipes[0]);
        }
        $processes[] = $process;
        echo "  ✓ Spawned worker-{$i}\n";
    } else {
        echo "  ✗ Failed to spawn worker-{$i}\n";
    }
}

echo "\nAll workers spawned in " . number_format((microtime(true) - $testStartTime) * 1000, 2) . " ms\n";
echo "Waiting for workers to complete...\n\n";

// Wait for all workers to finish
sleep(4); // Give workers time to complete (2 sec work + buffer)

// Close process handles
foreach ($processes as $process) {
    proc_close($process);
}

echo "Phase 2: Analyzing results...\n\n";

// Read and analyze log file
if (!file_exists($logFile)) {
    echo "✗ ERROR: Log file not found! Workers may not have started.\n";
    exit(1);
}

$logContent = file_get_contents($logFile);
$lines = explode("\n", trim($logContent));

$startTimes = [];
$endTimes = [];

foreach ($lines as $line) {
    if (preg_match('/Worker (worker-\d+) STARTED at ([\d.]+)/', $line, $matches)) {
        $startTimes[$matches[1]] = floatval($matches[2]);
    }
    if (preg_match('/Worker (worker-\d+) FINISHED at ([\d.]+)/', $line, $matches)) {
        $endTimes[$matches[1]] = floatval($matches[2]);
    }
}

echo "Results:\n";
echo "  - Workers started: " . count($startTimes) . " / {$testWorkerCount}\n";
echo "  - Workers finished: " . count($endTimes) . " / {$testWorkerCount}\n\n";

if (count($startTimes) < $testWorkerCount) {
    echo "✗ FAIL: Not all workers started!\n";
    exit(1);
}

// Check if workers ran in parallel by comparing start times
$minStartTime = min($startTimes);
$maxStartTime = max($startTimes);
$startTimeSpread = $maxStartTime - $minStartTime;

echo "Parallel Execution Analysis:\n";
echo "  - First worker started: " . number_format($minStartTime, 4) . "\n";
echo "  - Last worker started: " . number_format($maxStartTime, 4) . "\n";
echo "  - Start time spread: " . number_format($startTimeSpread, 4) . " seconds\n\n";

// If workers started within 1 second of each other, they ran in parallel
if ($startTimeSpread <= 1.0) {
    echo "✓✓✓ SUCCESS: Workers started in PARALLEL (spread: " . number_format($startTimeSpread * 1000, 2) . " ms)\n";
    echo "✓✓✓ All {$testWorkerCount} workers ran simultaneously!\n\n";
    $testPassed = true;
} else {
    echo "✗ FAIL: Workers started SEQUENTIALLY (spread: " . number_format($startTimeSpread, 2) . " sec)\n";
    echo "✗ This indicates sequential execution, not parallel!\n\n";
    $testPassed = false;
}

// Check completion times
if (count($endTimes) === $testWorkerCount) {
    $minEndTime = min($endTimes);
    $maxEndTime = max($endTimes);
    $totalExecutionTime = $maxEndTime - $minStartTime;
    
    echo "Execution Time Analysis:\n";
    echo "  - Total execution time: " . number_format($totalExecutionTime, 2) . " seconds\n";
    echo "  - Expected (sequential): " . ($testWorkerCount * 2) . " seconds\n";
    echo "  - Expected (parallel): ~2 seconds\n\n";
    
    if ($totalExecutionTime < 5) {
        echo "✓ PERFORMANCE: Execution time confirms parallel processing!\n";
    } else {
        echo "✗ WARNING: Execution time suggests sequential processing!\n";
        $testPassed = false;
    }
}

// Cleanup - remove temporary test files
unlink($workerScript);

// Also remove log file after test
// Note: Commented out to allow inspection, uncomment for automatic cleanup
// unlink($logFile);

echo "\n=== Test Complete ===\n";
echo "Log file saved: {$logFile}\n";
echo "(To enable automatic cleanup of log files, uncomment the unlink() line in the script)\n";

if ($testPassed) {
    echo "\n✓✓✓ PARALLEL EXECUTION TEST PASSED! ✓✓✓\n";
    exit(0);
} else {
    echo "\n✗✗✗ PARALLEL EXECUTION TEST FAILED! ✗✗✗\n";
    exit(1);
}
