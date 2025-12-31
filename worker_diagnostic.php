<?php
/**
 * Worker Diagnostic Script
 * 
 * This script helps diagnose why workers might be failing to start.
 * Run this before starting workers to check system compatibility.
 */

// Start output buffering to prevent header issues
ob_start();

echo "=== Worker Environment Diagnostic ===\n\n";

// Check PHP version
echo "PHP Version: " . PHP_VERSION . "\n";
echo "PHP Binary: " . PHP_BINARY . "\n";
echo "PHP SAPI: " . php_sapi_name() . "\n\n";

// Check required functions
echo "=== Required Functions ===\n";
$requiredFunctions = ['proc_open', 'proc_close', 'exec', 'curl_init', 'mysqli_connect'];
$hasRequiredFunctions = true;
foreach ($requiredFunctions as $func) {
    $available = function_exists($func);
    $disabled = in_array($func, array_map('trim', explode(',', ini_get('disable_functions'))));
    $status = $available && !$disabled ? '✓ Available' : '✗ Not Available';
    if ($disabled) {
        $status .= ' (disabled in php.ini)';
    }
    echo sprintf("  %-20s %s\n", $func, $status);
    
    // Track if critical functions are missing
    if ($func === 'proc_open' && (!$available || $disabled)) {
        $hasRequiredFunctions = false;
    }
}

// Note about exec being optional
if (!function_exists('exec') || in_array('exec', array_map('trim', explode(',', ini_get('disable_functions'))))) {
    echo "\n  ℹ️  NOTE: 'exec' is disabled but that's OK - system will use 'proc_open' instead\n";
}
echo "\n";

// Check file permissions
echo "=== File Permissions ===\n";
$currentDir = __DIR__;
$appFile = $currentDir . '/app.php';
$testFile = $currentDir . '/test_parallel_workers.php';

if (file_exists($appFile)) {
    $perms = substr(sprintf('%o', fileperms($appFile)), -4);
    echo "  app.php: {$perms} " . (is_readable($appFile) ? '✓ Readable' : '✗ Not Readable') . "\n";
}

if (file_exists($testFile)) {
    $perms = substr(sprintf('%o', fileperms($testFile)), -4);
    echo "  test_parallel_workers.php: {$perms} " . (is_readable($testFile) ? '✓ Readable' : '✗ Not Readable') . "\n";
}

$logFile = $currentDir . '/test_worker_diagnostic.log';
$testWrite = @file_put_contents($logFile, "test\n");
if ($testWrite !== false) {
    echo "  Log directory: ✓ Writable\n";
    @unlink($logFile);
} else {
    echo "  Log directory: ✗ Not Writable\n";
}
echo "\n";

// Check database connectivity (if config exists)
echo "=== Database Connectivity ===\n";
if (file_exists($appFile)) {
    // Capture output before loading app.php
    $output = ob_get_clean();
    
    // Try to include and check database
    // Set a flag to prevent Router from running
    define('DIAGNOSTIC_MODE', true);
    
    try {
        // Suppress any output from app.php during load
        ob_start();
        require_once $appFile;
        ob_end_clean();
        
        // Resume our output
        echo $output;
        
        // Check if database is configured
        global $DB_CONFIG;
        if (isset($DB_CONFIG) && $DB_CONFIG['installed']) {
            try {
                $db = Database::connect();
                echo "  Database: ✓ Connected\n";
                
                // Check tables
                $tables = ['jobs', 'job_queue', 'workers', 'emails'];
                foreach ($tables as $table) {
                    $stmt = $db->query("SHOW TABLES LIKE '{$table}'");
                    $exists = $stmt && $stmt->fetch();
                    echo "  Table '{$table}': " . ($exists ? '✓ Exists' : '✗ Missing') . "\n";
                }
            } catch (Exception $e) {
                echo "  Database: ✗ Connection failed\n";
                echo "  Error: " . $e->getMessage() . "\n";
            }
        } else {
            echo "  Database: ⚠️  Not configured (run setup first)\n";
        }
    } catch (Exception $e) {
        // Resume our output even on error
        ob_end_clean();
        echo $output;
        echo "  App file: ✗ Cannot load\n";
        echo "  Error: " . $e->getMessage() . "\n";
    }
} else {
    $output = ob_get_clean();
    echo $output;
    echo "  App file: ✗ Not found\n";
}
echo "\n";

// Test worker spawn
echo "=== Test Worker Spawn ===\n";
if (function_exists('proc_open') && !in_array('proc_open', array_map('trim', explode(',', ini_get('disable_functions'))))) {
    $testScript = $currentDir . '/test_worker_spawn.php';
    file_put_contents($testScript, '<?php
error_log("Test worker started successfully");
file_put_contents(__DIR__ . "/test_worker_spawn.log", "Worker spawned at " . date("Y-m-d H:i:s") . "\n", FILE_APPEND);
exit(0);
');
    
    $nullDevice = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN' ? 'NUL' : '/dev/null';
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['file', $nullDevice, 'w'],
        2 => ['file', $nullDevice, 'w']
    ];
    
    $process = proc_open([PHP_BINARY, $testScript], $descriptors, $pipes);
    if (is_resource($process)) {
        if (isset($pipes[0])) {
            fclose($pipes[0]);
        }
        echo "  proc_open: ✓ Test worker spawned\n";
        sleep(1);
        proc_close($process);
        
        $spawnLog = $currentDir . '/test_worker_spawn.log';
        if (file_exists($spawnLog)) {
            echo "  Worker execution: ✓ Worker ran successfully\n";
            unlink($spawnLog);
        } else {
            echo "  Worker execution: ✗ Worker did not write log\n";
        }
        unlink($testScript);
    } else {
        echo "  proc_open: ✗ Failed to spawn test worker\n";
    }
} else {
    echo "  proc_open: ✗ Not available\n";
}
echo "\n";

// Memory and limits
echo "=== System Resources ===\n";
echo "  Memory Limit: " . ini_get('memory_limit') . "\n";
echo "  Max Execution Time: " . ini_get('max_execution_time') . "s\n";
echo "  Open Files Limit: ";
if (function_exists('posix_getrlimit')) {
    $limits = posix_getrlimit();
    echo $limits['soft openfiles'] . "\n";
} else {
    echo "N/A (posix not available)\n";
}
echo "\n";

echo "=== Diagnostic Complete ===\n";
echo "\nIf you see any ✗ or errors above, those need to be fixed before workers can start properly.\n";
echo "Common issues:\n";
echo "  - Database not configured: Run the setup wizard first\n";
echo "  - proc_open disabled: Contact your hosting provider\n";
echo "  - Log directory not writable: Check file permissions\n";
echo "  - Database connection failed: Check database credentials\n";
