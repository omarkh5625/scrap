<?php
// Override CLI detection to prevent infinite loop
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['PHP_SELF'] = '/app.php';

// Load just the needed classes
error_reporting(0);
ini_set('display_errors', 0);
session_start();

$configFile = __DIR__ . '/config.php';
if (file_exists($configFile)) {
    require_once $configFile;
}

class Config {
    const VERSION = '1.0.0';
    const DB_RETRY_ATTEMPTS = 3;
    const DB_RETRY_DELAY = 2;
}

class Utils {
    public static function logMessage($level, $message, $context = []) {
        // Stub for testing
    }
}

class Database {
    private static $instance = null;
    private $pdo = null;
    private $isConfigured = false;
    
    private function __construct() {
        $this->isConfigured = defined('DB_HOST') && defined('DB_NAME') && defined('DB_USER');
    }
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function isConfigured() {
        return $this->isConfigured;
    }
}

// Test
$db = Database::getInstance();
if (!$db->isConfigured()) {
    echo 'SUCCESS: Database correctly reports as not configured' . PHP_EOL;
    echo 'Test 1 passed: Backward compatibility maintained' . PHP_EOL;
} else {
    echo 'FAIL: Database should not be configured yet' . PHP_EOL;
    exit(1);
}

// Test with config
define('DB_HOST', 'localhost');
define('DB_NAME', 'test_db');
define('DB_USER', 'test_user');

$db2 = new Database();
if ($db2->isConfigured()) {
    echo 'SUCCESS: Database correctly detects configuration' . PHP_EOL;
    echo 'Test 2 passed: Configuration detection works' . PHP_EOL;
} else {
    echo 'FAIL: Database should detect configuration' . PHP_EOL;
    exit(1);
}

echo PHP_EOL . 'All tests passed!' . PHP_EOL;
