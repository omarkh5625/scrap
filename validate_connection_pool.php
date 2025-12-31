#!/usr/bin/env php
<?php
/**
 * Connection Pool Validator
 * 
 * Simple script to validate connection pool implementation
 * Run this to check if connection throttling is working
 */

echo "Connection Pool Validation\n";
echo "==========================\n\n";

// Check lock file location
$lockFile = sys_get_temp_dir() . '/scrap_connection_pool.lock';
echo "Lock file location: {$lockFile}\n";

// Check if lock file exists
if (file_exists($lockFile)) {
    echo "✓ Lock file exists\n";
    
    // Read contents
    $contents = file_get_contents($lockFile);
    $data = json_decode($contents, true);
    
    if ($data) {
        echo "\nCurrent Pool Status:\n";
        echo "  Active: {$data['active']}\n";
        echo "  Waiting: {$data['waiting']}\n";
        echo "  Peak: {$data['peak']}\n";
        echo "  Last Update: " . date('Y-m-d H:i:s', $data['last_update']) . "\n";
        
        // Validate limits
        if ($data['active'] <= 150) {
            echo "\n✓ Connection count within limit (150)\n";
        } else {
            echo "\n✗ WARNING: Connection count exceeds limit ({$data['active']} > 150)\n";
        }
    } else {
        echo "✗ Could not parse lock file\n";
    }
} else {
    echo "⚠ Lock file does not exist yet (will be created on first connection)\n";
}

echo "\nTo monitor in real-time, run:\n";
echo "  watch -n 1 cat {$lockFile}\n";
echo "\nOr via API (if app is running):\n";
echo "  curl 'http://your-app-url/?page=api&action=connection-pool-stats'\n";
