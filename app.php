<?php declare(strict_types=1);

/**
 * Complete PHP Email Extraction System - Single File Application
 * PHP 8.0+ Required
 * 
 * Features:
 * - Setup Wizard
 * - Authentication System
 * - Dashboard
 * - Email Extraction Jobs
 * - Async Background Workers (1-1000)
 * - Email Type Filtering (Gmail, Yahoo, Business)
 * - Country Targeting
 * - Results Export
 * - Google Serper.dev Integration
 * - BloomFilter Deduplication
 * - CLI Worker Support
 * - Regex Email Extraction
 */

// ============================================================================
// CONFIGURATION SECTION - DO NOT EDIT MANUALLY AFTER INSTALLATION
// ============================================================================

define('CONFIG_START', true);

// Database configuration (filled by setup wizard)
$DB_CONFIG = [
    'host' => '',
    'database' => '',
    'username' => '',
    'password' => '',
    'installed' => false
];

define('CONFIG_END', true);

// ============================================================================
// APPLICATION BOOTSTRAP
// ============================================================================

error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/php_errors.log');
date_default_timezone_set('UTC');

// Performance optimizations for high-volume email extraction
ini_set('memory_limit', '512M'); // Increased for large batch operations
ini_set('max_execution_time', '600'); // 10 minutes for worker processes
ini_set('default_socket_timeout', '10');

// MySQL connection optimizations
ini_set('mysql.connect_timeout', '10');
ini_set('mysqli.reconnect', '1');

session_start();

// ============================================================================
// DATABASE CLASS
// ============================================================================

class Database {
    private static ?PDO $pdo = null;
    private static bool $migrated = false;
    
    public static function connect(): PDO {
        global $DB_CONFIG;
        
        if (self::$pdo === null) {
            try {
                $dsn = "mysql:host={$DB_CONFIG['host']};dbname={$DB_CONFIG['database']};charset=utf8mb4";
                self::$pdo = new PDO($dsn, $DB_CONFIG['username'], $DB_CONFIG['password'], [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false
                ]);
                
                // Run migrations if not already done
                if (!self::$migrated) {
                    self::runMigrations();
                    self::$migrated = true;
                }
            } catch (PDOException $e) {
                die('Database connection failed: ' . $e->getMessage());
            }
        }
        
        return self::$pdo;
    }
    
    private static function runMigrations(): void {
        try {
            // Migration: Add country and email_filter columns to jobs table if they don't exist
            $stmt = self::$pdo->query("SHOW COLUMNS FROM jobs LIKE 'country'");
            if ($stmt->rowCount() === 0) {
                self::$pdo->exec("ALTER TABLE jobs ADD COLUMN country VARCHAR(100) AFTER max_results");
                self::$pdo->exec("ALTER TABLE jobs ADD INDEX idx_country (country)");
            }
            
            $stmt = self::$pdo->query("SHOW COLUMNS FROM jobs LIKE 'email_filter'");
            if ($stmt->rowCount() === 0) {
                self::$pdo->exec("ALTER TABLE jobs ADD COLUMN email_filter VARCHAR(50) AFTER country");
            }
            
            // Migration: Create emails table if it doesn't exist (rename from results)
            $stmt = self::$pdo->query("SHOW TABLES LIKE 'emails'");
            if ($stmt->rowCount() === 0) {
                // Check if old results table exists
                $stmt = self::$pdo->query("SHOW TABLES LIKE 'results'");
                if ($stmt->rowCount() > 0) {
                    // Rename old table
                    self::$pdo->exec("RENAME TABLE results TO results_backup");
                }
                
                // Create new emails table
                self::$pdo->exec("
                    CREATE TABLE IF NOT EXISTS emails (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        job_id INT NOT NULL,
                        email VARCHAR(500) NOT NULL,
                        email_hash VARCHAR(64) UNIQUE,
                        domain VARCHAR(255),
                        country VARCHAR(100),
                        source_url VARCHAR(1000),
                        source_title TEXT,
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        FOREIGN KEY (job_id) REFERENCES jobs(id) ON DELETE CASCADE,
                        INDEX idx_job (job_id),
                        INDEX idx_hash (email_hash),
                        INDEX idx_domain (domain),
                        INDEX idx_country (country)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                ");
            }
            
            // Migration: Add worker statistics columns if they don't exist
            $stmt = self::$pdo->query("SHOW COLUMNS FROM workers LIKE 'pages_processed'");
            if ($stmt->rowCount() === 0) {
                self::$pdo->exec("ALTER TABLE workers ADD COLUMN pages_processed INT DEFAULT 0 AFTER last_heartbeat");
                self::$pdo->exec("ALTER TABLE workers ADD COLUMN emails_extracted INT DEFAULT 0 AFTER pages_processed");
                self::$pdo->exec("ALTER TABLE workers ADD COLUMN runtime_seconds INT DEFAULT 0 AFTER emails_extracted");
            }
            
            // Migration: Create job_queue table if it doesn't exist
            $stmt = self::$pdo->query("SHOW TABLES LIKE 'job_queue'");
            if ($stmt->rowCount() === 0) {
                self::$pdo->exec("
                    CREATE TABLE IF NOT EXISTS job_queue (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        job_id INT NOT NULL,
                        start_offset INT NOT NULL,
                        max_results INT NOT NULL,
                        status ENUM('pending', 'processing', 'completed', 'failed') DEFAULT 'pending',
                        worker_id INT NULL,
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        started_at TIMESTAMP NULL,
                        completed_at TIMESTAMP NULL,
                        FOREIGN KEY (job_id) REFERENCES jobs(id) ON DELETE CASCADE,
                        INDEX idx_status (status),
                        INDEX idx_job (job_id)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                ");
            }
            
            // Migration: Create worker_errors table for error tracking
            $stmt = self::$pdo->query("SHOW TABLES LIKE 'worker_errors'");
            if ($stmt->rowCount() === 0) {
                self::$pdo->exec("
                    CREATE TABLE IF NOT EXISTS worker_errors (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        worker_id INT NULL,
                        job_id INT NULL,
                        error_type VARCHAR(100),
                        error_message TEXT,
                        error_details TEXT,
                        severity ENUM('warning', 'error', 'critical') DEFAULT 'error',
                        resolved BOOLEAN DEFAULT FALSE,
                        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        INDEX idx_worker (worker_id),
                        INDEX idx_job (job_id),
                        INDEX idx_severity (severity),
                        INDEX idx_resolved (resolved)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                ");
            }
            
            // Migration: Add error_count column to workers table
            $stmt = self::$pdo->query("SHOW COLUMNS FROM workers LIKE 'error_count'");
            if ($stmt->rowCount() === 0) {
                self::$pdo->exec("ALTER TABLE workers ADD COLUMN error_count INT DEFAULT 0 AFTER runtime_seconds");
                self::$pdo->exec("ALTER TABLE workers ADD COLUMN last_error TEXT AFTER error_count");
            }
        } catch (PDOException $e) {
            error_log('Migration error: ' . $e->getMessage());
            // Don't die on migration errors, just log them
        }
    }
    
    public static function testConnection(string $host, string $db, string $user, string $pass): array {
        try {
            // Try different host formats for better compatibility
            $hosts = [$host];
            if ($host === 'localhost') {
                $hosts = ['localhost', '127.0.0.1', 'localhost:/tmp/mysql.sock', 'localhost:/var/run/mysqld/mysqld.sock'];
            }
            
            $lastError = '';
            $pdo = null;
            
            foreach ($hosts as $tryHost) {
                try {
                    $dsn = "mysql:host={$tryHost};charset=utf8mb4";
                    $pdo = new PDO($dsn, $user, $pass, [
                        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
                    ]);
                    break; // Connection successful
                } catch (PDOException $e) {
                    $lastError = $e->getMessage();
                    continue;
                }
            }
            
            if (!$pdo) {
                return ['success' => false, 'error' => 'Connection failed: ' . $lastError];
            }
            
            // Create database if not exists
            try {
                $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$db}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            } catch (PDOException $e) {
                return ['success' => false, 'error' => 'Cannot create database. User may lack CREATE DATABASE privilege: ' . $e->getMessage()];
            }
            
            $pdo->exec("USE `{$db}`");
            
            // Create tables
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS users (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    username VARCHAR(100) UNIQUE NOT NULL,
                    password VARCHAR(255) NOT NULL,
                    email VARCHAR(255),
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
            
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS jobs (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    user_id INT NOT NULL,
                    query VARCHAR(500) NOT NULL,
                    api_key VARCHAR(255) NOT NULL,
                    max_results INT DEFAULT 100,
                    country VARCHAR(100),
                    email_filter VARCHAR(50),
                    status ENUM('pending', 'running', 'completed', 'failed') DEFAULT 'pending',
                    progress INT DEFAULT 0,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                    INDEX idx_status (status),
                    INDEX idx_created (created_at),
                    INDEX idx_country (country)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
            
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS emails (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    job_id INT NOT NULL,
                    email VARCHAR(500) NOT NULL,
                    email_hash VARCHAR(64) UNIQUE,
                    domain VARCHAR(255),
                    country VARCHAR(100),
                    source_url VARCHAR(1000),
                    source_title TEXT,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (job_id) REFERENCES jobs(id) ON DELETE CASCADE,
                    INDEX idx_job (job_id),
                    INDEX idx_hash (email_hash),
                    INDEX idx_domain (domain),
                    INDEX idx_country (country)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
            
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS bloomfilter (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    hash VARCHAR(64) UNIQUE NOT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_hash (hash)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
            
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS workers (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    worker_name VARCHAR(100) UNIQUE NOT NULL,
                    status ENUM('idle', 'running', 'stopped') DEFAULT 'idle',
                    current_job_id INT NULL,
                    last_heartbeat TIMESTAMP NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    pages_processed INT DEFAULT 0,
                    emails_extracted INT DEFAULT 0,
                    runtime_seconds INT DEFAULT 0,
                    error_count INT DEFAULT 0,
                    last_error TEXT,
                    INDEX idx_status (status)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
            
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS settings (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    setting_key VARCHAR(100) UNIQUE NOT NULL,
                    setting_value TEXT,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
            
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS job_queue (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    job_id INT NOT NULL,
                    start_offset INT NOT NULL,
                    max_results INT NOT NULL,
                    status ENUM('pending', 'processing', 'completed', 'failed') DEFAULT 'pending',
                    worker_id INT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    started_at TIMESTAMP NULL,
                    completed_at TIMESTAMP NULL,
                    FOREIGN KEY (job_id) REFERENCES jobs(id) ON DELETE CASCADE,
                    INDEX idx_status (status),
                    INDEX idx_job (job_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
            
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS worker_errors (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    worker_id INT NULL,
                    job_id INT NULL,
                    error_type VARCHAR(100),
                    error_message TEXT,
                    error_details TEXT,
                    severity ENUM('warning', 'error', 'critical') DEFAULT 'error',
                    resolved BOOLEAN DEFAULT FALSE,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_worker (worker_id),
                    INDEX idx_job (job_id),
                    INDEX idx_severity (severity),
                    INDEX idx_resolved (resolved)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
            
            return ['success' => true, 'error' => null];
            
        } catch (PDOException $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    public static function install(string $host, string $db, string $user, string $pass, string $adminUser, string $adminPass, string $adminEmail): array {
        $result = self::testConnection($host, $db, $user, $pass);
        
        if (!$result['success']) {
            return $result;
        }
        
        // Create admin user
        try {
            // Try different host formats
            $hosts = [$host];
            if ($host === 'localhost') {
                $hosts = ['localhost', '127.0.0.1', 'localhost:/tmp/mysql.sock', 'localhost:/var/run/mysqld/mysqld.sock'];
            }
            
            $pdo = null;
            foreach ($hosts as $tryHost) {
                try {
                    $dsn = "mysql:host={$tryHost};dbname={$db};charset=utf8mb4";
                    $pdo = new PDO($dsn, $user, $pass, [
                        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
                    ]);
                    break;
                } catch (PDOException $e) {
                    continue;
                }
            }
            
            if (!$pdo) {
                return ['success' => false, 'error' => 'Could not reconnect to database after table creation'];
            }
            
            // Check if admin already exists
            $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM users WHERE username = ?");
            $stmt->execute([$adminUser]);
            $count = $stmt->fetch()['count'];
            
            if ($count > 0) {
                return ['success' => false, 'error' => 'Admin user already exists. System may already be installed.'];
            }
            
            $hashedPassword = password_hash($adminPass, PASSWORD_BCRYPT);
            $stmt = $pdo->prepare("INSERT INTO users (username, password, email) VALUES (?, ?, ?)");
            $stmt->execute([$adminUser, $hashedPassword, $adminEmail]);
            
            // Update config in file
            self::updateConfig($host, $db, $user, $pass);
            
            return ['success' => true, 'error' => null];
        } catch (PDOException $e) {
            return ['success' => false, 'error' => 'Failed to create admin user: ' . $e->getMessage()];
        } catch (Exception $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    private static function updateConfig(string $host, string $db, string $user, string $pass): void {
        $filePath = __FILE__;
        $content = file_get_contents($filePath);
        
        $newConfig = "\$DB_CONFIG = [\n";
        $newConfig .= "    'host' => '" . addslashes($host) . "',\n";
        $newConfig .= "    'database' => '" . addslashes($db) . "',\n";
        $newConfig .= "    'username' => '" . addslashes($user) . "',\n";
        $newConfig .= "    'password' => '" . addslashes($pass) . "',\n";
        $newConfig .= "    'installed' => true\n";
        $newConfig .= "];";
        
        $pattern = '/\$DB_CONFIG\s*=\s*\[.*?\];/s';
        $content = preg_replace($pattern, $newConfig, $content);
        
        if (file_put_contents($filePath, $content) === false) {
            throw new Exception('Failed to update configuration file. Please check file permissions.');
        }
    }
}

// ============================================================================
// AUTHENTICATION CLASS
// ============================================================================

class Auth {
    public static function login(string $username, string $password): bool {
        $db = Database::connect();
        $stmt = $db->prepare("SELECT id, username, password FROM users WHERE username = ?");
        $stmt->execute([$username]);
        $user = $stmt->fetch();
        
        if ($user && password_verify($password, $user['password'])) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            return true;
        }
        
        return false;
    }
    
    public static function logout(): void {
        session_destroy();
        session_start();
    }
    
    public static function isLoggedIn(): bool {
        return isset($_SESSION['user_id']);
    }
    
    public static function requireAuth(): void {
        if (!self::isLoggedIn()) {
            header('Location: ?page=login');
            exit;
        }
    }
    
    public static function getUserId(): ?int {
        return $_SESSION['user_id'] ?? null;
    }
}

// ============================================================================
// BLOOMFILTER CLASS
// ============================================================================

class BloomFilter {
    // Configuration constants
    private const DEFAULT_CACHE_SIZE = 10000;
    private const BULK_CHECK_BATCH_SIZE = 1000;
    
    private static array $cache = [];
    private static int $cacheSize = self::DEFAULT_CACHE_SIZE;
    
    /**
     * Set cache size (useful for tuning based on available memory)
     */
    public static function setCacheSize(int $size): void {
        self::$cacheSize = max(100, min($size, 100000)); // Limit between 100 and 100K
    }
    
    public static function add(string $email): void {
        $hash = self::normalize($email);
        
        // Add to in-memory cache
        self::$cache[$hash] = true;
        
        // Trim cache if too large
        if (count(self::$cache) > self::$cacheSize) {
            self::$cache = array_slice(self::$cache, -self::$cacheSize, null, true);
        }
        
        $db = Database::connect();
        
        try {
            $stmt = $db->prepare("INSERT IGNORE INTO bloomfilter (hash) VALUES (?)");
            $stmt->execute([$hash]);
        } catch (PDOException $e) {
            // Duplicate entry is fine
        }
    }
    
    public static function exists(string $email): bool {
        $hash = self::normalize($email);
        
        // Check in-memory cache first (much faster)
        if (isset(self::$cache[$hash])) {
            return true;
        }
        
        $db = Database::connect();
        
        $stmt = $db->prepare("SELECT COUNT(*) as count FROM bloomfilter WHERE hash = ?");
        $stmt->execute([$hash]);
        $result = $stmt->fetch();
        
        $exists = $result['count'] > 0;
        
        // Cache the result
        if ($exists) {
            self::$cache[$hash] = true;
        }
        
        return $exists;
    }
    
    /**
     * Bulk check multiple emails at once - much faster than individual checks
     */
    public static function filterExisting(array $emails): array {
        if (empty($emails)) {
            return [];
        }
        
        $unique = [];
        $hashes = [];
        
        foreach ($emails as $email) {
            $hash = self::normalize($email);
            
            // Skip if already in memory cache
            if (isset(self::$cache[$hash])) {
                continue;
            }
            
            $hashes[$hash] = $email;
        }
        
        if (empty($hashes)) {
            return [];
        }
        
        // Bulk query database in batches to avoid large IN clauses
        $db = Database::connect();
        $hashKeys = array_keys($hashes);
        $existingHashes = [];
        
        // Process in batches to avoid MySQL max_allowed_packet issues
        $batches = array_chunk($hashKeys, self::BULK_CHECK_BATCH_SIZE);
        
        foreach ($batches as $batch) {
            $placeholders = implode(',', array_fill(0, count($batch), '?'));
            $stmt = $db->prepare("SELECT hash FROM bloomfilter WHERE hash IN ($placeholders)");
            $stmt->execute($batch);
            
            while ($row = $stmt->fetch()) {
                $existingHashes[$row['hash']] = true;
                // Add to cache
                self::$cache[$row['hash']] = true;
            }
        }
        
        // Return emails that don't exist yet
        foreach ($hashes as $hash => $email) {
            if (!isset($existingHashes[$hash])) {
                $unique[] = $email;
            }
        }
        
        return $unique;
    }
    
    /**
     * Bulk add multiple emails at once
     */
    public static function addBulk(array $emails): void {
        if (empty($emails)) {
            return;
        }
        
        $hashes = [];
        foreach ($emails as $email) {
            $hash = self::normalize($email);
            $hashes[] = $hash;
            self::$cache[$hash] = true;
        }
        
        // Trim cache if too large
        if (count(self::$cache) > self::$cacheSize) {
            self::$cache = array_slice(self::$cache, -self::$cacheSize, null, true);
        }
        
        if (empty($hashes)) {
            return;
        }
        
        $db = Database::connect();
        
        try {
            // Build bulk insert
            $values = implode(',', array_fill(0, count($hashes), '(?)'));
            $stmt = $db->prepare("INSERT IGNORE INTO bloomfilter (hash) VALUES $values");
            $stmt->execute($hashes);
        } catch (PDOException $e) {
            // Silently fail - duplicates are expected
        }
    }
    
    private static function normalize(string $email): string {
        // Normalize email and return SHA-256 hash
        $email = strtolower(trim($email));
        
        return hash('sha256', $email);
    }
}

// ============================================================================
// EMAIL EXTRACTOR CLASS
// ============================================================================

class EmailExtractor {
    // Comprehensive email regex pattern
    private static string $emailPattern = '/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/';
    
    public static function extractEmails(string $text): array {
        $emails = [];
        preg_match_all(self::$emailPattern, $text, $matches);
        
        if (!empty($matches[0])) {
            foreach ($matches[0] as $email) {
                $email = strtolower(trim($email));
                // Validate email
                if (self::isValidEmail($email)) {
                    $emails[] = $email;
                }
            }
        }
        
        return array_unique($emails);
    }
    
    public static function extractEmailsFromUrl(string $url, int $timeout = 5): array {
        $emails = [];
        
        try {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
            curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36');
            
            $content = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            
            if ($httpCode === 200 && $content) {
                $emails = self::extractEmails($content);
            }
        } catch (Exception $e) {
            // Silently fail - page scraping is optional
        }
        
        return $emails;
    }
    
    /**
     * Extract emails from multiple URLs in parallel using curl_multi
     * Returns array with url => [emails] mapping
     */
    public static function extractEmailsFromUrlsParallel(array $urls, int $timeout = 5): array {
        $results = [];
        
        if (empty($urls)) {
            return $results;
        }
        
        $curlMulti = new CurlMultiManager(min(count($urls), 50));
        
        // Add all URLs to the multi handle
        foreach ($urls as $url) {
            $curlMulti->addUrl($url, [
                'timeout' => $timeout,
                'connect_timeout' => 3
            ], $url);
        }
        
        // Execute all requests in parallel
        $responses = $curlMulti->execute();
        
        // Process results
        foreach ($responses as $response) {
            $url = $response['user_data'];
            $results[$url] = [];
            
            if ($response['http_code'] === 200 && !empty($response['content'])) {
                $emails = self::extractEmails($response['content']);
                $results[$url] = $emails;
            }
        }
        
        $curlMulti->close();
        
        return $results;
    }
    
    public static function isValidEmail(string $email): bool {
        // Basic validation
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return false;
        }
        
        return true;
    }
    
    public static function getDomain(string $email): string {
        $parts = explode('@', $email);
        return isset($parts[1]) ? strtolower($parts[1]) : '';
    }
    
    public static function matchesFilter(?string $filter, string $email): bool {
        if (!$filter || $filter === 'all') {
            return true;
        }
        
        $domain = self::getDomain($email);
        
        switch ($filter) {
            case 'gmail':
                return $domain === 'gmail.com';
            case 'yahoo':
                return in_array($domain, ['yahoo.com', 'yahoo.co.uk', 'yahoo.fr', 'yahoo.de']);
            case 'business':
                // Not free email providers
                $freeProviders = ['gmail.com', 'yahoo.com', 'yahoo.co.uk', 'yahoo.fr', 'hotmail.com', 
                                 'outlook.com', 'aol.com', 'mail.com', 'protonmail.com'];
                return !in_array($domain, $freeProviders);
            default:
                return true;
        }
    }
}

// ============================================================================
// CURL MULTI MANAGER CLASS - For Parallel HTTP Requests
// ============================================================================

class CurlMultiManager {
    // Configuration constants
    private const DEFAULT_MAX_CONNECTIONS = 50;
    private const MAX_HOST_CONNECTIONS = 10;
    
    private $multiHandle;
    private array $handles = [];
    private array $handleData = [];
    private int $maxConnections;
    
    public function __construct(int $maxConnections = self::DEFAULT_MAX_CONNECTIONS) {
        $this->maxConnections = min($maxConnections, 100); // Cap at 100 for safety
        $this->multiHandle = curl_multi_init();
        
        // Set max total connections and max per host
        if (defined('CURLMOPT_MAX_TOTAL_CONNECTIONS')) {
            curl_multi_setopt($this->multiHandle, CURLMOPT_MAX_TOTAL_CONNECTIONS, $this->maxConnections);
        }
        if (defined('CURLMOPT_MAX_HOST_CONNECTIONS')) {
            curl_multi_setopt($this->multiHandle, CURLMOPT_MAX_HOST_CONNECTIONS, self::MAX_HOST_CONNECTIONS);
        }
    }
    
    /**
     * Add a URL to fetch in parallel
     */
    public function addUrl(string $url, array $options = [], $userData = null): void {
        $ch = curl_init($url);
        
        // Default options for performance
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $options['timeout'] ?? 10);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $options['connect_timeout'] ?? 5);
        curl_setopt($ch, CURLOPT_USERAGENT, $options['user_agent'] ?? 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36');
        curl_setopt($ch, CURLOPT_ENCODING, ''); // Enable compression
        curl_setopt($ch, CURLOPT_MAXREDIRS, 3);
        
        // SSL verification - configurable for environments with SSL issues
        // In production, keep SSL verification enabled for security
        $sslVerify = $options['ssl_verify'] ?? true;
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $sslVerify);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $sslVerify ? 2 : 0);
        
        // HTTP/2 for better performance (if available)
        if (defined('CURL_HTTP_VERSION_2_0')) {
            curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_2_0);
        }
        
        // Keep-alive for connection reuse
        curl_setopt($ch, CURLOPT_TCP_KEEPALIVE, 1);
        curl_setopt($ch, CURLOPT_TCP_KEEPIDLE, 120);
        curl_setopt($ch, CURLOPT_TCP_KEEPINTVL, 60);
        
        // POST request if needed
        if (isset($options['post']) && $options['post']) {
            curl_setopt($ch, CURLOPT_POST, true);
            if (isset($options['postfields'])) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $options['postfields']);
            }
        }
        
        // Custom headers
        if (isset($options['headers'])) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $options['headers']);
        }
        
        $handleId = (int)$ch;
        $this->handles[$handleId] = $ch;
        $this->handleData[$handleId] = [
            'url' => $url,
            'user_data' => $userData,
            'started' => microtime(true)
        ];
        
        curl_multi_add_handle($this->multiHandle, $ch);
    }
    
    /**
     * Execute all pending requests in parallel
     */
    public function execute(): array {
        $results = [];
        
        // Execute all handles
        do {
            $status = curl_multi_exec($this->multiHandle, $active);
            
            // Wait for activity on any curl handle
            if ($active) {
                curl_multi_select($this->multiHandle, 0.1);
            }
        } while ($active && $status == CURLM_OK);
        
        // Collect results
        foreach ($this->handles as $handleId => $ch) {
            $content = curl_multi_getcontent($ch);
            $info = curl_getinfo($ch);
            $error = curl_error($ch);
            
            $elapsed = microtime(true) - $this->handleData[$handleId]['started'];
            
            $results[] = [
                'url' => $this->handleData[$handleId]['url'],
                'user_data' => $this->handleData[$handleId]['user_data'],
                'content' => $content,
                'http_code' => $info['http_code'],
                'error' => $error,
                'elapsed' => $elapsed,
                'info' => $info
            ];
            
            curl_multi_remove_handle($this->multiHandle, $ch);
            curl_close($ch);
        }
        
        // Clear handles for next batch
        $this->handles = [];
        $this->handleData = [];
        
        return $results;
    }
    
    /**
     * Get number of handles currently registered
     */
    public function getHandleCount(): int {
        return count($this->handles);
    }
    
    /**
     * Check if we're at max capacity
     */
    public function isFull(): bool {
        return count($this->handles) >= $this->maxConnections;
    }
    
    /**
     * Close the multi handle
     */
    public function close(): void {
        if ($this->multiHandle) {
            curl_multi_close($this->multiHandle);
            $this->multiHandle = null;
        }
    }
    
    public function __destruct() {
        $this->close();
    }
}

// ============================================================================
// JOB CLASS
// ============================================================================

class Job {
    // Configuration constants
    private const BULK_INSERT_BATCH_SIZE = 1000;
    
    public static function create(int $userId, string $query, string $apiKey, int $maxResults, ?string $country = null, ?string $emailFilter = null): int {
        try {
            $db = Database::connect();
            $stmt = $db->prepare("INSERT INTO jobs (user_id, query, api_key, max_results, country, email_filter, status) VALUES (?, ?, ?, ?, ?, ?, 'pending')");
            $stmt->execute([$userId, $query, $apiKey, $maxResults, $country, $emailFilter]);
            
            $jobId = (int)$db->lastInsertId();
            
            return $jobId;
        } catch (PDOException $e) {
            error_log('Job creation database error: ' . $e->getMessage());
            throw new Exception('Failed to create job: ' . $e->getMessage());
        }
    }
    
    public static function getAll(int $userId): array {
        $db = Database::connect();
        $stmt = $db->prepare("SELECT * FROM jobs WHERE user_id = ? ORDER BY created_at DESC");
        $stmt->execute([$userId]);
        
        return $stmt->fetchAll();
    }
    
    public static function getById(int $jobId): ?array {
        $db = Database::connect();
        $stmt = $db->prepare("SELECT * FROM jobs WHERE id = ?");
        $stmt->execute([$jobId]);
        
        $result = $stmt->fetch();
        return $result ?: null;
    }
    
    public static function updateStatus(int $jobId, string $status, int $progress = 0): void {
        $db = Database::connect();
        $stmt = $db->prepare("UPDATE jobs SET status = ?, progress = ? WHERE id = ?");
        $stmt->execute([$status, $progress, $jobId]);
    }
    
    public static function getResults(int $jobId): array {
        $db = Database::connect();
        $stmt = $db->prepare("SELECT * FROM emails WHERE job_id = ? ORDER BY created_at DESC");
        $stmt->execute([$jobId]);
        
        return $stmt->fetchAll();
    }
    
    public static function addEmail(int $jobId, string $email, ?string $country = null, ?string $sourceUrl = null, ?string $sourceTitle = null): bool {
        if (BloomFilter::exists($email)) {
            return false; // Skip duplicates
        }
        
        $db = Database::connect();
        $emailHash = hash('sha256', strtolower($email));
        $domain = EmailExtractor::getDomain($email);
        
        try {
            $stmt = $db->prepare("INSERT INTO emails (job_id, email, email_hash, domain, country, source_url, source_title) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$jobId, $email, $emailHash, $domain, $country, $sourceUrl, $sourceTitle]);
            
            BloomFilter::add($email);
            return true;
        } catch (PDOException $e) {
            // Duplicate hash, skip
            return false;
        }
    }
    
    /**
     * Bulk add emails - much faster than individual inserts
     * Returns number of emails actually added
     */
    public static function addEmailsBulk(int $jobId, array $emails, ?string $country = null, array $sources = []): int {
        if (empty($emails)) {
            return 0;
        }
        
        $db = Database::connect();
        $added = 0;
        
        // Filter out duplicates using BloomFilter batch method (much faster)
        $uniqueEmails = BloomFilter::filterExisting($emails);
        
        if (empty($uniqueEmails)) {
            return 0;
        }
        
        // Process in batches to avoid MySQL max_allowed_packet issues
        $emailBatches = array_chunk($uniqueEmails, self::BULK_INSERT_BATCH_SIZE);
        
        foreach ($emailBatches as $batch) {
            // Build bulk insert query for this batch
            $values = [];
            $params = [];
            
            foreach ($batch as $email) {
                $emailHash = hash('sha256', strtolower($email));
                $domain = EmailExtractor::getDomain($email);
                $sourceUrl = $sources[$email]['url'] ?? null;
                $sourceTitle = $sources[$email]['title'] ?? null;
                
                $values[] = "(?, ?, ?, ?, ?, ?, ?)";
                $params[] = $jobId;
                $params[] = $email;
                $params[] = $emailHash;
                $params[] = $domain;
                $params[] = $country;
                $params[] = $sourceUrl;
                $params[] = $sourceTitle;
            }
            
            try {
                $sql = "INSERT IGNORE INTO emails (job_id, email, email_hash, domain, country, source_url, source_title) VALUES " . implode(", ", $values);
                $stmt = $db->prepare($sql);
                $stmt->execute($params);
                $added += $stmt->rowCount();
            } catch (PDOException $e) {
                error_log("Bulk insert error for batch: " . $e->getMessage());
                // Fallback to individual inserts for this batch if bulk fails
                foreach ($batch as $email) {
                    $sourceUrl = $sources[$email]['url'] ?? null;
                    $sourceTitle = $sources[$email]['title'] ?? null;
                    if (self::addEmail($jobId, $email, $country, $sourceUrl, $sourceTitle)) {
                        $added++;
                    }
                }
            }
        }
        
        // Add to BloomFilter in bulk (much faster)
        BloomFilter::addBulk($uniqueEmails);
        
        return $added;
    }
}

// ============================================================================
// WORKER CLASS
// ============================================================================

class Worker {
    // Configuration constants
    private const DEFAULT_RATE_LIMIT = 0.3; // seconds between API requests (optimized for parallel mode)
    
    public static function getAll(): array {
        $db = Database::connect();
        $stmt = $db->query("SELECT * FROM workers ORDER BY created_at DESC");
        
        return $stmt->fetchAll();
    }
    
    public static function register(string $name): int {
        $db = Database::connect();
        
        try {
            $stmt = $db->prepare("INSERT INTO workers (worker_name, status) VALUES (?, 'idle')");
            $stmt->execute([$name]);
            return (int)$db->lastInsertId();
        } catch (PDOException $e) {
            // Worker already exists
            $stmt = $db->prepare("SELECT id FROM workers WHERE worker_name = ?");
            $stmt->execute([$name]);
            $result = $stmt->fetch();
            return $result['id'];
        }
    }
    
    public static function logError(int $workerId, ?int $jobId, string $errorType, string $errorMessage, ?string $errorDetails = null, string $severity = 'error'): void {
        $db = Database::connect();
        
        try {
            // Log to worker_errors table
            $stmt = $db->prepare("INSERT INTO worker_errors (worker_id, job_id, error_type, error_message, error_details, severity) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$workerId, $jobId, $errorType, $errorMessage, $errorDetails, $severity]);
            
            // Update worker's error count and last error
            $stmt = $db->prepare("UPDATE workers SET error_count = error_count + 1, last_error = ? WHERE id = ?");
            $stmt->execute([$errorMessage, $workerId]);
            
            error_log("Worker #{$workerId} error logged: [{$errorType}] {$errorMessage}");
        } catch (PDOException $e) {
            error_log("Failed to log worker error: " . $e->getMessage());
        }
    }
    
    public static function getErrors(bool $unresolvedOnly = true, int $limit = 50): array {
        $db = Database::connect();
        
        $sql = "SELECT we.*, w.worker_name, j.query as job_query 
                FROM worker_errors we 
                LEFT JOIN workers w ON we.worker_id = w.id 
                LEFT JOIN jobs j ON we.job_id = j.id ";
        
        if ($unresolvedOnly) {
            $sql .= "WHERE we.resolved = FALSE ";
        }
        
        $sql .= "ORDER BY we.created_at DESC LIMIT ?";
        
        $stmt = $db->prepare($sql);
        $stmt->execute([$limit]);
        
        return $stmt->fetchAll();
    }
    
    public static function resolveError(int $errorId): void {
        $db = Database::connect();
        $stmt = $db->prepare("UPDATE worker_errors SET resolved = TRUE WHERE id = ?");
        $stmt->execute([$errorId]);
    }
    
    public static function detectStaleWorkers(int $timeoutSeconds = 300): array {
        $db = Database::connect();
        
        // Find workers that are marked as running but haven't sent heartbeat in timeout period
        $stmt = $db->prepare("
            SELECT * FROM workers 
            WHERE status = 'running' 
            AND last_heartbeat IS NOT NULL 
            AND TIMESTAMPDIFF(SECOND, last_heartbeat, NOW()) > ?
        ");
        $stmt->execute([$timeoutSeconds]);
        
        return $stmt->fetchAll();
    }
    
    public static function markWorkerAsCrashed(int $workerId, string $reason): void {
        $db = Database::connect();
        
        // Update worker status to stopped
        $stmt = $db->prepare("UPDATE workers SET status = 'stopped', last_error = ? WHERE id = ?");
        $stmt->execute([$reason, $workerId]);
        
        // Log the crash as a critical error
        self::logError($workerId, null, 'worker_crash', $reason, null, 'critical');
    }
    
    public static function updateHeartbeat(int $workerId, string $status, ?int $jobId = null, int $pagesProcessed = 0, int $emailsExtracted = 0): void {
        $db = Database::connect();
        
        // Simple runtime calculation based on created_at (cached in single query)
        $stmt = $db->prepare("
            UPDATE workers 
            SET status = ?, 
                current_job_id = ?, 
                last_heartbeat = NOW(), 
                pages_processed = pages_processed + ?, 
                emails_extracted = emails_extracted + ?, 
                runtime_seconds = TIMESTAMPDIFF(SECOND, created_at, NOW())
            WHERE id = ?
        ");
        $stmt->execute([$status, $jobId, $pagesProcessed, $emailsExtracted, $workerId]);
    }
    
    public static function getStats(): array {
        $db = Database::connect();
        
        // Get active workers count
        $stmt = $db->query("SELECT COUNT(*) as count FROM workers WHERE status = 'running'");
        $activeWorkers = $stmt->fetch()['count'];
        
        // Get idle workers count
        $stmt = $db->query("SELECT COUNT(*) as count FROM workers WHERE status = 'idle'");
        $idleWorkers = $stmt->fetch()['count'];
        
        // Get total pages processed
        $stmt = $db->query("SELECT SUM(pages_processed) as total FROM workers");
        $totalPages = $stmt->fetch()['total'] ?? 0;
        
        // Get total emails extracted
        $stmt = $db->query("SELECT SUM(emails_extracted) as total FROM workers");
        $totalEmails = $stmt->fetch()['total'] ?? 0;
        
        // Get average runtime and calculate extraction rate
        $stmt = $db->query("SELECT AVG(runtime_seconds) as avg, SUM(runtime_seconds) as total_runtime FROM workers WHERE runtime_seconds > 0");
        $runtimeData = $stmt->fetch();
        $avgRuntime = (int)($runtimeData['avg'] ?? 0);
        $totalRuntime = (int)($runtimeData['total_runtime'] ?? 0);
        
        // Calculate emails per minute rate
        $emailsPerMinute = 0;
        if ($totalRuntime > 0) {
            $emailsPerMinute = round(($totalEmails / $totalRuntime) * 60, 1);
        }
        
        return [
            'active_workers' => $activeWorkers,
            'idle_workers' => $idleWorkers,
            'total_pages' => $totalPages,
            'total_emails' => $totalEmails,
            'avg_runtime' => $avgRuntime,
            'emails_per_minute' => $emailsPerMinute
        ];
    }
    
    public static function getNextJob(): ?array {
        $db = Database::connect();
        
        // Use transaction to prevent race conditions
        $db->beginTransaction();
        
        try {
            // First check job_queue for pending chunks
            $stmt = $db->prepare("SELECT * FROM job_queue WHERE status = 'pending' ORDER BY created_at ASC LIMIT 1 FOR UPDATE");
            $stmt->execute();
            $queueItem = $stmt->fetch();
            
            if ($queueItem) {
                // Mark this queue item as processing
                $stmt = $db->prepare("UPDATE job_queue SET status = 'processing', started_at = NOW() WHERE id = ?");
                $stmt->execute([$queueItem['id']]);
                
                // Get the job details
                $stmt = $db->prepare("SELECT * FROM jobs WHERE id = ?");
                $stmt->execute([$queueItem['job_id']]);
                $job = $stmt->fetch();
                
                if ($job) {
                    // Add queue info to job
                    $job['queue_id'] = $queueItem['id'];
                    $job['queue_start_offset'] = $queueItem['start_offset'];
                    $job['queue_max_results'] = $queueItem['max_results'];
                }
                
                $db->commit();
                return $job ?: null;
            }
            
            // Fallback to old method: check for pending jobs without queue
            $stmt = $db->prepare("SELECT * FROM jobs WHERE status = 'pending' ORDER BY created_at ASC LIMIT 1 FOR UPDATE");
            $stmt->execute();
            $job = $stmt->fetch();
            
            if ($job) {
                $stmt = $db->prepare("UPDATE jobs SET status = 'running' WHERE id = ?");
                $stmt->execute([$job['id']]);
            }
            
            $db->commit();
            return $job ?: null;
        } catch (PDOException $e) {
            $db->rollBack();
            error_log("getNextJob error: " . $e->getMessage());
            return null;
        }
    }
    
    public static function markQueueItemComplete(int $queueId): void {
        $db = Database::connect();
        $stmt = $db->prepare("UPDATE job_queue SET status = 'completed', completed_at = NOW() WHERE id = ?");
        $stmt->execute([$queueId]);
        error_log("✓ Marked queue item {$queueId} as completed");
    }
    
    public static function markQueueItemFailed(int $queueId): void {
        $db = Database::connect();
        $stmt = $db->prepare("UPDATE job_queue SET status = 'failed', completed_at = NOW() WHERE id = ?");
        $stmt->execute([$queueId]);
        error_log("✗ Marked queue item {$queueId} as failed");
    }
    
    /**
     * Check if all queue items for a job are complete and update job status accordingly
     */
    public static function checkAndUpdateJobCompletion(int $jobId): void {
        $db = Database::connect();
        
        // Get total and completed queue items for this job
        $stmt = $db->prepare("
            SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed
            FROM job_queue 
            WHERE job_id = ?
        ");
        $stmt->execute([$jobId]);
        $counts = $stmt->fetch();
        
        $total = (int)$counts['total'];
        $completed = (int)$counts['completed'];
        $failed = (int)$counts['failed'];
        
        if ($total === 0) {
            error_log("  checkAndUpdateJobCompletion: No queue items found for job {$jobId}");
            return;
        }
        
        // Calculate progress percentage
        $progress = (int)round(($completed / $total) * 100);
        
        error_log("  checkAndUpdateJobCompletion: Job {$jobId} progress = {$progress}% ({$completed}/{$total} queue items completed)");
        
        // Update job status based on queue completion
        if ($completed === $total) {
            // All queue items completed
            Job::updateStatus($jobId, 'completed', 100);
            error_log("  checkAndUpdateJobCompletion: Job {$jobId} marked as COMPLETED");
        } elseif ($completed + $failed === $total) {
            // All queue items either completed or failed
            Job::updateStatus($jobId, 'completed', $progress);
            error_log("  checkAndUpdateJobCompletion: Job {$jobId} marked as COMPLETED (some items failed)");
        } else {
            // Still processing
            Job::updateStatus($jobId, 'running', $progress);
            error_log("  checkAndUpdateJobCompletion: Job {$jobId} status = running, progress = {$progress}%");
        }
    }
    
    public static function processResultWithDeepScraping(
        array $result,
        int $jobId,
        ?string $country,
        ?string $emailFilter,
        int &$processed,
        int $maxResults
    ): void {
        $title = $result['title'] ?? '';
        $link = $result['link'] ?? '';
        $snippet = $result['snippet'] ?? '';
        
        // Extract emails from title, link, and snippet
        $textToScan = $title . ' ' . $link . ' ' . $snippet;
        $emails = EmailExtractor::extractEmails($textToScan);
        
        // Deep scraping: fetch page content if enabled
        $deepScraping = (bool)(Settings::get('deep_scraping', '1'));
        $deepScrapingThreshold = (int)(Settings::get('deep_scraping_threshold', '5'));
        
        if ($deepScraping && $link && count($emails) < $deepScrapingThreshold) {
            $pageEmails = EmailExtractor::extractEmailsFromUrl($link);
            $emails = array_merge($emails, $pageEmails);
            $emails = array_unique($emails);
        }
        
        foreach ($emails as $email) {
            if ($processed >= $maxResults) {
                break;
            }
            
            // Apply email filter first before adding
            if (EmailExtractor::matchesFilter($emailFilter, $email)) {
                if (Job::addEmail($jobId, $email, $country, $link, $title)) {
                    $processed++;
                }
            }
        }
    }
    
    /**
     * Process multiple search results with parallel deep scraping
     * Much faster than processing one-by-one
     */
    public static function processResultsBatchWithParallelScraping(
        array $results,
        int $jobId,
        ?string $country,
        ?string $emailFilter,
        int &$processed,
        int $maxResults
    ): void {
        $deepScraping = (bool)(Settings::get('deep_scraping', '1'));
        $deepScrapingThreshold = (int)(Settings::get('deep_scraping_threshold', '5'));
        
        // First pass: extract emails from search results metadata
        $allEmails = [];
        $urlsToScrape = [];
        $sources = [];
        
        foreach ($results as $result) {
            $title = $result['title'] ?? '';
            $link = $result['link'] ?? '';
            $snippet = $result['snippet'] ?? '';
            
            // Extract from metadata
            $textToScan = $title . ' ' . $link . ' ' . $snippet;
            $emails = EmailExtractor::extractEmails($textToScan);
            
            foreach ($emails as $email) {
                $allEmails[] = $email;
                $sources[$email] = ['url' => $link, 'title' => $title];
            }
            
            // Determine if we need to deep scrape this URL
            if ($deepScraping && $link && count($emails) < $deepScrapingThreshold) {
                $urlsToScrape[] = $link;
            }
        }
        
        // Second pass: parallel deep scraping if enabled and needed
        if (!empty($urlsToScrape)) {
            $scrapedResults = EmailExtractor::extractEmailsFromUrlsParallel($urlsToScrape, 5);
            
            foreach ($scrapedResults as $url => $emails) {
                foreach ($emails as $email) {
                    $allEmails[] = $email;
                    if (!isset($sources[$email])) {
                        $sources[$email] = ['url' => $url, 'title' => ''];
                    }
                }
            }
        }
        
        // Remove duplicates
        $allEmails = array_unique($allEmails);
        
        // Filter and add emails in bulk
        $emailsToAdd = [];
        foreach ($allEmails as $email) {
            if ($processed >= $maxResults) {
                break;
            }
            
            if (EmailExtractor::matchesFilter($emailFilter, $email)) {
                $emailsToAdd[] = $email;
                $processed++;
            }
        }
        
        // Bulk insert for better performance
        if (!empty($emailsToAdd)) {
            Job::addEmailsBulk($jobId, $emailsToAdd, $country, $sources);
        }
    }
    
    public static function processJob(int $jobId): void {
        $job = Job::getById($jobId);
        if (!$job) {
            return;
        }
        
        echo "Processing job #{$jobId}: {$job['query']}\n";
        
        // Register this worker for tracking
        $workerName = 'cli-worker-' . getmypid();
        $workerId = self::register($workerName);
        self::updateHeartbeat($workerId, 'running', $jobId, 0, 0);
        
        $apiKey = $job['api_key'];
        $query = $job['query'];
        $maxResults = (int)$job['max_results'];
        $country = $job['country'];
        $emailFilter = $job['email_filter'];
        
        // Check if this is a queue-based job chunk
        $startOffset = isset($job['queue_start_offset']) ? (int)$job['queue_start_offset'] : 0;
        $maxToProcess = isset($job['queue_max_results']) ? (int)$job['queue_max_results'] : $maxResults;
        $queueId = isset($job['queue_id']) ? (int)$job['queue_id'] : null;
        
        $processed = 0;
        $pagesProcessed = 0;
        $page = (int)($startOffset / 10) + 1;
        
        while ($processed < $maxToProcess) {
            $data = self::searchSerper($apiKey, $query, $page, $country);
            
            if (!$data || !isset($data['organic'])) {
                break;
            }
            
            $pagesProcessed++;
            $emailsBefore = $processed;
            
            // Use batch processing for better performance
            if (isset($data['organic']) && count($data['organic']) > 0) {
                self::processResultsBatchWithParallelScraping($data['organic'], $jobId, $country, $emailFilter, $processed, $maxToProcess);
            }
            
            $emailsExtractedThisPage = $processed - $emailsBefore;
            
            // Update worker statistics
            self::updateHeartbeat($workerId, 'running', $jobId, 1, $emailsExtractedThisPage);
            
            if ($processed > 0 && $processed % 10 === 0) {
                echo "  - Progress: {$processed}/{$maxToProcess} emails\n";
            }
            
            if (!isset($data['organic']) || count($data['organic']) === 0) {
                break;
            }
            
            $page++;
            
            // Reduced rate limiting when using parallel scraping (already more efficient)
            $rateLimit = (float)(Settings::get('rate_limit', (string)self::DEFAULT_RATE_LIMIT));
            usleep((int)($rateLimit * 1000000));
        }
        
        // Mark queue item as complete
        if ($queueId) {
            self::markQueueItemComplete($queueId);
        }
        
        // Update overall job progress
        self::updateJobProgress($jobId);
        
        echo "Job chunk completed! Processed {$processed} emails\n";
        
        // Mark worker as idle
        self::updateHeartbeat($workerId, 'idle', null, 0, 0);
    }
    
    public static function updateJobProgress(int $jobId): void {
        $db = Database::connect();
        
        // Get total queue items and completed items
        $stmt = $db->prepare("SELECT COUNT(*) as total FROM job_queue WHERE job_id = ?");
        $stmt->execute([$jobId]);
        $total = $stmt->fetch()['total'];
        
        $stmt = $db->prepare("SELECT COUNT(*) as completed FROM job_queue WHERE job_id = ? AND status = 'completed'");
        $stmt->execute([$jobId]);
        $completed = $stmt->fetch()['completed'];
        
        if ($total > 0) {
            $progress = (int)(($completed / $total) * 100);
            
            // If all queue items are completed, mark job as completed
            if ($completed === $total) {
                Job::updateStatus($jobId, 'completed', 100);
            } else {
                Job::updateStatus($jobId, 'running', $progress);
            }
        }
    }
    
    private static function searchSerper(string $apiKey, string $query, int $page = 1, ?string $country = null): ?array {
        $url = 'https://google.serper.dev/search';
        
        $payload = [
            'q' => $query,
            'page' => $page,
            'num' => 10
        ];
        
        if ($country) {
            $payload['gl'] = $country;
        }
        
        $data = json_encode($payload);
        
        error_log("searchSerper: Calling API with query='{$query}', page={$page}, country={$country}");
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'X-API-KEY: ' . $apiKey,
            'Content-Type: application/json'
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);
        
        if ($curlError) {
            error_log("searchSerper: cURL error: {$curlError}");
            return null;
        }
        
        error_log("searchSerper: HTTP code={$httpCode}");
        
        if ($httpCode === 200 && $response) {
            $result = json_decode($response, true);
            if ($result) {
                error_log("searchSerper: Success, got " . (isset($result['organic']) ? count($result['organic']) : 0) . " organic results");
                return $result;
            } else {
                error_log("searchSerper: JSON decode failed");
            }
        } else {
            error_log("searchSerper: Non-200 response or empty body. Response: " . substr($response, 0, 500));
        }
        
        return null;
    }
    
    public static function processJobImmediately(int $jobId, int $startOffset = 0, int $maxResults = 100): void {
        error_log("processJobImmediately called: jobId={$jobId}, startOffset={$startOffset}, maxResults={$maxResults}");
        
        try {
            $job = Job::getById($jobId);
            if (!$job) {
                error_log("processJobImmediately: Job {$jobId} not found");
                return;
            }
            
            error_log("processJobImmediately: Processing job {$jobId}, query='{$job['query']}'");
            
            $apiKey = $job['api_key'];
            $query = $job['query'];
            $country = $job['country'];
            $emailFilter = $job['email_filter'];
            
            $processed = 0;
            $pagesProcessed = 0;
            $page = (int)($startOffset / 10) + 1;
            
            error_log("processJobImmediately: Starting from page {$page}, will process max {$maxResults} emails");
            
            while ($processed < $maxResults) {
                try {
                    error_log("processJobImmediately: Calling searchSerper, page={$page}");
                    $data = self::searchSerper($apiKey, $query, $page, $country);
                    
                    if (!$data) {
                        error_log("processJobImmediately: searchSerper returned null/false");
                        break;
                    }
                    
                    if (!isset($data['organic'])) {
                        error_log("processJobImmediately: No organic results in response");
                        break;
                    }
                    
                    error_log("processJobImmediately: Got " . count($data['organic']) . " organic results");
                    
                    $pagesProcessed++;
                    $emailsBefore = $processed;
                    
                    try {
                        // Use batch processing with parallel scraping for much better performance
                        self::processResultsBatchWithParallelScraping($data['organic'], $jobId, $country, $emailFilter, $processed, $maxResults);
                    } catch (Exception $e) {
                        error_log("Error processing batch: " . $e->getMessage());
                    }
                    
                    $emailsExtractedThisPage = $processed - $emailsBefore;
                    
                    error_log("processJobImmediately: Processed {$processed}/{$maxResults} emails so far");
                    
                    // Stop if we've reached the limit for this queue item
                    if ($processed >= $maxResults) {
                        error_log("processJobImmediately: Reached limit of {$maxResults} emails for this queue item");
                        break;
                    }
                    
                    if (count($data['organic']) === 0) {
                        break;
                    }
                    
                    $page++;
                    
                    // Reduced rate limiting when using parallel scraping
                    $rateLimit = (float)(Settings::get('rate_limit', (string)self::DEFAULT_RATE_LIMIT));
                    usleep((int)($rateLimit * 1000000));
                } catch (Exception $e) {
                    error_log("Error in page processing loop: " . $e->getMessage());
                    break;
                }
            }
            
            error_log("processJobImmediately: Completed. Processed {$processed} emails from {$pagesProcessed} pages");
            
        } catch (Exception $e) {
            error_log("Critical error in processJobImmediately: " . $e->getMessage());
            throw $e;
        }
    }
}

// ============================================================================
// SETTINGS CLASS
// ============================================================================

class Settings {
    public static function get(string $key, mixed $default = null): mixed {
        $db = Database::connect();
        $stmt = $db->prepare("SELECT setting_value FROM settings WHERE setting_key = ?");
        $stmt->execute([$key]);
        $result = $stmt->fetch();
        
        return $result ? $result['setting_value'] : $default;
    }
    
    public static function set(string $key, string $value): void {
        $db = Database::connect();
        $stmt = $db->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?");
        $stmt->execute([$key, $value, $value]);
    }
    
    public static function getAll(): array {
        $db = Database::connect();
        $stmt = $db->query("SELECT * FROM settings");
        
        $settings = [];
        foreach ($stmt->fetchAll() as $row) {
            $settings[$row['setting_key']] = $row['setting_value'];
        }
        
        return $settings;
    }
}

// ============================================================================
// ROUTER
// ============================================================================

class Router {
    public static function handleRequest(): void {
        global $DB_CONFIG;
        
        // CLI mode
        if (php_sapi_name() === 'cli') {
            self::handleCLI();
            return;
        }
        
        // Check if installed
        if (!$DB_CONFIG['installed']) {
            self::handleSetup();
            return;
        }
        
        // Handle login/logout
        $page = $_GET['page'] ?? 'dashboard';
        
        if ($page === 'login') {
            self::handleLogin();
            return;
        }
        
        if ($page === 'logout') {
            Auth::logout();
            header('Location: ?page=login');
            exit;
        }
        
        // Worker endpoints don't require authentication (they use internal spawning)
        // These are triggered by the server itself, not external users
        if ($page === 'process-queue-worker') {
            self::handleProcessQueueWorker();
            return;
        }
        
        // Require authentication for all other pages
        Auth::requireAuth();
        
        // Route to appropriate page
        switch ($page) {
            case 'dashboard':
                self::renderDashboard();
                break;
            case 'new-job':
                self::renderNewJob();
                break;
            case 'settings':
                self::renderSettings();
                break;
            case 'workers':
                self::renderWorkers();
                break;
            case 'results':
                self::renderResults();
                break;
            case 'export':
                self::handleExport();
                break;
            case 'process-worker':
                self::handleWorkerProcessing();
                break;
            case 'start-worker':
                self::handleStartWorker();
                break;
            case 'api':
                self::handleAPI();
                break;
            default:
                self::renderDashboard();
        }
    }
    
    private static function handleCLI(): void {
        global $argv;
        
        // Check if this is a process-job command (spawned by UI)
        if (isset($argv[1]) && $argv[1] === 'process-job') {
            // Direct job processing: php app.php process-job <jobId> <startOffset> <maxResults>
            $jobId = (int)($argv[2] ?? 0);
            $startOffset = (int)($argv[3] ?? 0);
            $maxResults = (int)($argv[4] ?? 100);
            
            if ($jobId > 0) {
                try {
                    Worker::processJobImmediately($jobId, $startOffset, $maxResults);
                } catch (Exception $e) {
                    error_log("Worker error for job {$jobId}: " . $e->getMessage());
                }
            }
            exit(0);
        }
        
        // Regular worker mode (polls for jobs)
        echo "=== PHP Email Extraction System Worker ===\n";
        
        $workerName = $argv[1] ?? 'worker-' . uniqid();
        echo "Worker: {$workerName}\n";
        
        $workerId = Worker::register($workerName);
        echo "Worker ID: {$workerId}\n";
        echo "Waiting for jobs...\n\n";
        
        // Get polling interval from settings or use default 5 seconds
        $pollingInterval = (int)(Settings::get('worker_polling_interval', '5'));
        
        while (true) {
            Worker::updateHeartbeat($workerId, 'idle', null, 0, 0);
            
            $job = Worker::getNextJob();
            
            if ($job) {
                Worker::updateHeartbeat($workerId, 'running', $job['id'], 0, 0);
                Worker::processJob($job['id']);
                Worker::updateHeartbeat($workerId, 'idle', null, 0, 0);
            }
            
            sleep($pollingInterval);
        }
    }
    
    private static function handleSetup(): void {
        if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
            $host = $_POST['db_host'] ?? '';
            $database = $_POST['db_name'] ?? '';
            $username = $_POST['db_user'] ?? '';
            $password = $_POST['db_pass'] ?? '';
            $adminUser = $_POST['admin_user'] ?? '';
            $adminPass = $_POST['admin_pass'] ?? '';
            $adminEmail = $_POST['admin_email'] ?? '';
            
            $result = Database::install($host, $database, $username, $password, $adminUser, $adminPass, $adminEmail);
            
            if ($result['success']) {
                header('Location: ?page=login');
                exit;
            } else {
                $error = $result['error'];
            }
        }
        
        self::renderSetup($error ?? null);
    }
    
    private static function handleLogin(): void {
        if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
            $username = $_POST['username'] ?? '';
            $password = $_POST['password'] ?? '';
            
            if (Auth::login($username, $password)) {
                header('Location: ?page=dashboard');
                exit;
            } else {
                $error = 'Invalid username or password';
            }
        }
        
        self::renderLogin($error ?? null);
    }
    
    private static function handleAPI(): void {
        header('Content-Type: application/json');
        
        $action = $_GET['action'] ?? '';
        
        switch ($action) {
            case 'stats':
                $userId = Auth::getUserId();
                $db = Database::connect();
                
                $stmt = $db->prepare("SELECT COUNT(*) as total FROM jobs WHERE user_id = ?");
                $stmt->execute([$userId]);
                $totalJobs = $stmt->fetch()['total'];
                
                $stmt = $db->prepare("SELECT COUNT(*) as total FROM jobs WHERE user_id = ? AND status = 'completed'");
                $stmt->execute([$userId]);
                $completedJobs = $stmt->fetch()['total'];
                
                $stmt = $db->prepare("SELECT COUNT(*) as total FROM emails r INNER JOIN jobs j ON r.job_id = j.id WHERE j.user_id = ?");
                $stmt->execute([$userId]);
                $totalResults = $stmt->fetch()['total'];
                
                echo json_encode([
                    'totalJobs' => $totalJobs,
                    'completedJobs' => $completedJobs,
                    'totalResults' => $totalResults
                ]);
                break;
                
            case 'workers':
                echo json_encode(Worker::getAll());
                break;
                
            case 'worker-stats':
                echo json_encode(Worker::getStats());
                break;
                
            case 'queue-stats':
                $db = Database::connect();
                $stmt = $db->query("SELECT COUNT(*) as pending FROM job_queue WHERE status = 'pending'");
                $pending = $stmt->fetch()['pending'];
                
                $stmt = $db->query("SELECT COUNT(*) as processing FROM job_queue WHERE status = 'processing'");
                $processing = $stmt->fetch()['processing'];
                
                $stmt = $db->query("SELECT COUNT(*) as completed FROM job_queue WHERE status = 'completed'");
                $completed = $stmt->fetch()['completed'];
                
                echo json_encode([
                    'pending' => $pending,
                    'processing' => $processing,
                    'completed' => $completed
                ]);
                break;
                
            case 'jobs':
                echo json_encode(Job::getAll(Auth::getUserId()));
                break;
                
            case 'worker-errors':
                $unresolvedOnly = isset($_GET['unresolved_only']) ? (bool)$_GET['unresolved_only'] : true;
                
                // Detect and mark stale workers before returning errors
                $staleWorkers = Worker::detectStaleWorkers(300);
                foreach ($staleWorkers as $worker) {
                    Worker::markWorkerAsCrashed($worker['id'], 'Worker has not sent heartbeat for over 5 minutes. Possible crash or timeout.');
                }
                
                echo json_encode(Worker::getErrors($unresolvedOnly));
                break;
                
            case 'job-worker-status':
                $jobId = (int)($_GET['job_id'] ?? 0);
                if ($jobId > 0) {
                    $db = Database::connect();
                    
                    // Get job info
                    $stmt = $db->prepare("SELECT * FROM jobs WHERE id = ?");
                    $stmt->execute([$jobId]);
                    $job = $stmt->fetch();
                    
                    // Get active workers for this job
                    $stmt = $db->prepare("SELECT * FROM workers WHERE current_job_id = ? AND status = 'running'");
                    $stmt->execute([$jobId]);
                    $activeWorkers = $stmt->fetchAll();
                    
                    // Get total emails collected
                    $stmt = $db->prepare("SELECT COUNT(*) as count FROM emails WHERE job_id = ?");
                    $stmt->execute([$jobId]);
                    $emailsCollected = $stmt->fetch()['count'];
                    
                    // Calculate completion percentage
                    $maxResults = $job['max_results'] ?? 1;
                    $completionPercentage = min(100, round(($emailsCollected / $maxResults) * 100, 2));
                    
                    // Get recent errors for this job
                    $stmt = $db->prepare("SELECT * FROM worker_errors WHERE job_id = ? AND resolved = FALSE ORDER BY created_at DESC LIMIT 5");
                    $stmt->execute([$jobId]);
                    $recentErrors = $stmt->fetchAll();
                    
                    // Detect stale workers
                    $staleWorkers = Worker::detectStaleWorkers(300);
                    
                    echo json_encode([
                        'job' => $job,
                        'active_workers' => count($activeWorkers),
                        'workers' => $activeWorkers,
                        'emails_collected' => $emailsCollected,
                        'emails_required' => $maxResults,
                        'completion_percentage' => $completionPercentage,
                        'recent_errors' => $recentErrors,
                        'stale_workers' => $staleWorkers
                    ]);
                } else {
                    echo json_encode(['error' => 'Invalid job ID']);
                }
                break;
                
            case 'process-job-workers':
                // Trigger worker processing for a job (called via AJAX after job creation)
                $jobId = (int)($_POST['job_id'] ?? 0);
                $workerCount = (int)($_POST['worker_count'] ?? 5);
                
                if ($jobId > 0) {
                    // Send immediate response, then process in background
                    ignore_user_abort(true);
                    set_time_limit(0);
                    
                    $response = json_encode(['success' => true, 'message' => 'Workers processing started']);
                    
                    header('Content-Type: application/json');
                    header('Content-Length: ' . strlen($response));
                    header('Connection: close');
                    echo $response;
                    
                    // Flush all output buffers
                    if (ob_get_level() > 0) {
                        ob_end_flush();
                    }
                    flush();
                    
                    // Close the session if it's open
                    if (session_id()) {
                        session_write_close();
                    }
                    
                    // Give time for connection to close
                    usleep(100000); // 0.1 seconds
                    
                    // Now process workers in background
                    try {
                        error_log("Starting background worker processing for job {$jobId}");
                        self::spawnParallelWorkers($jobId, $workerCount);
                        error_log("Completed background worker processing for job {$jobId}");
                    } catch (Exception $e) {
                        error_log("Error in background worker processing for job {$jobId}: " . $e->getMessage());
                    }
                } else {
                    echo json_encode(['error' => 'Invalid job ID']);
                }
                break;
                
            case 'resolve-error':
                $errorId = (int)($_POST['error_id'] ?? 0);
                if ($errorId > 0) {
                    Worker::resolveError($errorId);
                    echo json_encode(['success' => true]);
                } else {
                    echo json_encode(['error' => 'Invalid error ID']);
                }
                break;
                
            default:
                echo json_encode(['error' => 'Unknown action']);
        }
        
        exit;
    }
    
    private static function handleExport(): void {
        $jobId = (int)($_GET['job_id'] ?? 0);
        $format = $_GET['format'] ?? 'csv';
        
        $results = Job::getResults($jobId);
        
        if ($format === 'csv') {
            header('Content-Type: text/csv');
            header('Content-Disposition: attachment; filename="emails-' . $jobId . '.csv"');
            
            $output = fopen('php://output', 'w');
            fputcsv($output, ['Email', 'Domain', 'Country', 'Source URL', 'Source Title']);
            
            foreach ($results as $result) {
                fputcsv($output, [$result['email'], $result['domain'], $result['country'], $result['source_url'], $result['source_title']]);
            }
            
            fclose($output);
        } else {
            header('Content-Type: application/json');
            header('Content-Disposition: attachment; filename="emails-' . $jobId . '.json"');
            echo json_encode($results, JSON_PRETTY_PRINT);
        }
        
        exit;
    }
    
    private static function handleWorkerProcessing(): void {
        // This handles async HTTP worker requests (used when exec() is disabled)
        if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
            $jobId = (int)($_POST['job_id'] ?? 0);
            $startOffset = (int)($_POST['start_offset'] ?? 0);
            $maxResults = (int)($_POST['max_results'] ?? 100);
            $workerIndex = (int)($_POST['worker_index'] ?? 0);
            
            if ($jobId > 0) {
                // Log worker start
                error_log("Worker #{$workerIndex} started for job {$jobId}, offset {$startOffset}, max {$maxResults}");
                
                // Close connection immediately so client doesn't wait
                ignore_user_abort(true);
                set_time_limit(0);
                
                // Calculate content before sending
                $response = json_encode(['status' => 'processing', 'worker' => $workerIndex]);
                
                // Send minimal response
                header('Content-Type: application/json');
                header('Content-Length: ' . strlen($response));
                header('Connection: close');
                echo $response;
                
                // Flush all output buffers
                if (ob_get_level() > 0) {
                    ob_end_flush();
                }
                flush();
                
                // Close the session if it's open
                if (session_id()) {
                    session_write_close();
                }
                
                // Give time for connection to close
                usleep(100000); // 0.1 seconds
                
                // Now process the job in background
                try {
                    error_log("Worker #{$workerIndex} processing job {$jobId}");
                    Worker::processJobImmediately($jobId, $startOffset, $maxResults);
                    error_log("Worker #{$workerIndex} completed job {$jobId}");
                } catch (Exception $e) {
                    error_log("HTTP Worker #{$workerIndex} error for job {$jobId}: " . $e->getMessage());
                }
            }
        }
        exit;
    }
    
    private static function handleStartWorker(): void {
        // This starts a worker that polls the queue
        if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
            $workerName = $_POST['worker_name'] ?? 'http-worker-' . uniqid();
            $workerIndex = (int)($_POST['worker_index'] ?? 0);
            
            error_log("HTTP Worker {$workerName} starting...");
            
            // Close connection immediately so client doesn't wait
            ignore_user_abort(true);
            set_time_limit(0);
            
            // Calculate content before sending
            $response = json_encode(['status' => 'started', 'worker' => $workerName]);
            
            // Send minimal response
            header('Content-Type: application/json');
            header('Content-Length: ' . strlen($response));
            header('Connection: close');
            echo $response;
            
            // Flush all output buffers
            if (ob_get_level() > 0) {
                ob_end_flush();
            }
            flush();
            
            // Close the session if it's open
            if (session_id()) {
                session_write_close();
            }
            
            // Give time for connection to close
            usleep(100000); // 0.1 seconds
            
            // Now run worker loop in background
            try {
                error_log("Worker {$workerName} entering queue polling mode");
                
                $workerId = Worker::register($workerName);
                
                // Process queue items until empty or timeout
                $startTime = time();
                $maxRuntime = 300; // 5 minutes max per worker
                $itemsProcessed = 0;
                
                while ((time() - $startTime) < $maxRuntime) {
                    Worker::updateHeartbeat($workerId, 'idle', null, 0, 0);
                    
                    $job = Worker::getNextJob();
                    
                    if ($job) {
                        Worker::updateHeartbeat($workerId, 'running', $job['id'], 0, 0);
                        Worker::processJob($job['id']);
                        Worker::updateHeartbeat($workerId, 'idle', null, 0, 0);
                        $itemsProcessed++;
                    } else {
                        // No more jobs in queue, exit
                        break;
                    }
                    
                    // Small sleep between checks
                    sleep(1);
                }
                
                error_log("Worker {$workerName} completed. Processed {$itemsProcessed} items.");
            } catch (Exception $e) {
                error_log("HTTP Worker {$workerName} error: " . $e->getMessage());
            }
        }
        exit;
    }
    
    private static function handleProcessQueueWorker(): void {
        // This processes queue items for a specific job
        if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
            $jobId = (int)($_POST['job_id'] ?? 0);
            $workerName = $_POST['worker_name'] ?? 'queue-worker-' . uniqid();
            $workerIndex = (int)($_POST['worker_index'] ?? 0);
            
            if ($jobId <= 0) {
                echo json_encode(['status' => 'error', 'message' => 'Invalid job ID']);
                exit;
            }
            
            error_log("✓ Queue Worker {$workerName} starting for job {$jobId}...");
            
            // Register worker immediately
            $workerId = Worker::register($workerName);
            
            // Close connection immediately so client doesn't wait
            ignore_user_abort(true);
            set_time_limit(0);
            
            // Send success response immediately
            $response = json_encode([
                'status' => 'started', 
                'worker' => $workerName, 
                'worker_id' => $workerId,
                'job_id' => $jobId
            ]);
            header('Content-Type: application/json');
            header('Content-Length: ' . strlen($response));
            header('Connection: close');
            echo $response;
            
            // Flush all output buffers to send response
            if (ob_get_level() > 0) {
                ob_end_flush();
            }
            flush();
            
            // Close the session if it's open
            if (session_id()) {
                session_write_close();
            }
            
            // Small delay to ensure connection closes
            usleep(50000); // 0.05 seconds
            
            // Now process queue items for this job in background
            try {
                error_log("  Worker {$workerName} (ID: {$workerId}) starting to process queue for job {$jobId}");
                
                // Process queue items for this specific job
                $startTime = time();
                $maxRuntime = 600; // 10 minutes max per worker
                $itemsProcessed = 0;
                
                // Keep processing until we run out of queue items for this job
                while ((time() - $startTime) < $maxRuntime) {
                    // Get next queue item for this specific job
                    $job = Worker::getNextJob();
                    
                    // Check if we got a job and it's for our target job_id
                    if (!$job) {
                        error_log("  Worker {$workerName}: No queue items available. Exiting.");
                        break;
                    }
                    
                    $retrievedJobId = (int)$job['id'];
                    
                    // Only process if it's for our target job
                    if ($retrievedJobId != $jobId) {
                        error_log("  Worker {$workerName}: Queue item is for different job ({$retrievedJobId} != {$jobId}). Exiting.");
                        break;
                    }
                    
                    error_log("  Worker {$workerName}: Processing queue item for job {$jobId}");
                    Worker::updateHeartbeat($workerId, 'running', $retrievedJobId, 0, 0);
                    
                    // Process the job (this extracts emails)
                    Worker::processJob($retrievedJobId);
                    
                    $itemsProcessed++;
                    error_log("  Worker {$workerName}: Completed queue item {$itemsProcessed} for job {$jobId}");
                    
                    // Check if all queue items for this job are done
                    $db = Database::connect();
                    $stmt = $db->prepare("SELECT COUNT(*) as pending FROM job_queue WHERE job_id = ? AND status = 'pending'");
                    $stmt->execute([$jobId]);
                    $pendingCount = $stmt->fetch()['pending'];
                    
                    if ($pendingCount == 0) {
                        error_log("✓ Worker {$workerName}: All queue items completed for job {$jobId}. Exiting.");
                        break;
                    }
                    
                    // Small sleep between items to avoid race conditions
                    usleep(100000); // 0.1 seconds (reduced from 0.5)
                }
                
                error_log("✓ Worker {$workerName} completed successfully. Processed {$itemsProcessed} queue items for job {$jobId}.");
                Worker::updateHeartbeat($workerId, 'idle', null, 0, 0);
            } catch (Exception $e) {
                error_log("✗ Queue Worker {$workerName} error: " . $e->getMessage());
                Worker::logError($workerId, $jobId, 'worker_error', $e->getMessage(), $e->getTraceAsString(), 'error');
                Worker::updateHeartbeat($workerId, 'stopped', null, 0, 0);
            }
        }
        exit;
    }
    
    private static function renderSetup(?string $error): void {
        ?>
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Setup Wizard - PHP Email Extraction System</title>
            <style><?php self::getCSS(); ?></style>
        </head>
        <body class="setup-page">
            <div class="setup-container">
                <div class="setup-card">
                    <h1>🚀 Setup Wizard</h1>
                    <p class="subtitle">Welcome! Let's configure your email extraction system.</p>
                    
                    <?php if ($error): ?>
                        <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
                    <?php endif; ?>
                    
                    <form method="POST">
                        <div class="form-section">
                            <h3>Database Configuration</h3>
                            
                            <div class="form-group">
                                <label>Database Host</label>
                                <input type="text" name="db_host" value="localhost" required>
                            </div>
                            
                            <div class="form-group">
                                <label>Database Name</label>
                                <input type="text" name="db_name" required>
                            </div>
                            
                            <div class="form-group">
                                <label>Database Username</label>
                                <input type="text" name="db_user" required>
                            </div>
                            
                            <div class="form-group">
                                <label>Database Password</label>
                                <input type="password" name="db_pass">
                            </div>
                        </div>
                        
                        <div class="form-section">
                            <h3>Admin Account</h3>
                            
                            <div class="form-group">
                                <label>Admin Username</label>
                                <input type="text" name="admin_user" required>
                            </div>
                            
                            <div class="form-group">
                                <label>Admin Password</label>
                                <input type="password" name="admin_pass" required>
                            </div>
                            
                            <div class="form-group">
                                <label>Admin Email</label>
                                <input type="email" name="admin_email" required>
                            </div>
                        </div>
                        
                        <button type="submit" class="btn btn-primary btn-large">Install System</button>
                    </form>
                </div>
            </div>
        </body>
        </html>
        <?php
        exit;
    }
    
    private static function renderLogin(?string $error): void {
        ?>
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Login - PHP Email Extraction System</title>
            <style><?php self::getCSS(); ?></style>
        </head>
        <body class="login-page">
            <div class="login-container">
                <div class="login-card">
                    <h1>🔐 Login</h1>
                    <p class="subtitle">Access your email extraction dashboard</p>
                    
                    <?php if ($error): ?>
                        <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
                    <?php endif; ?>
                    
                    <form method="POST">
                        <div class="form-group">
                            <label>Username</label>
                            <input type="text" name="username" required autofocus>
                        </div>
                        
                        <div class="form-group">
                            <label>Password</label>
                            <input type="password" name="password" required>
                        </div>
                        
                        <button type="submit" class="btn btn-primary btn-large">Login</button>
                    </form>
                </div>
            </div>
        </body>
        </html>
        <?php
        exit;
    }
    
    private static function renderDashboard(): void {
        if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
            if ($_POST['action'] === 'delete_job') {
                $jobId = (int)$_POST['job_id'];
                $db = Database::connect();
                $stmt = $db->prepare("DELETE FROM jobs WHERE id = ? AND user_id = ?");
                $stmt->execute([$jobId, Auth::getUserId()]);
                header('Location: ?page=dashboard');
                exit;
            }
        }
        
        $jobs = Job::getAll(Auth::getUserId());
        $newJobId = (int)($_GET['job_id'] ?? 0); // Check if redirected from job creation
        
        self::renderLayout('Dashboard', function() use ($jobs, $newJobId) {
            ?>
            <?php if ($newJobId > 0): ?>
                <!-- Job Progress Widget -->
                <div id="job-progress-widget" class="card" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; margin-bottom: 20px;">
                    <h2 style="color: white; margin-top: 0;">📊 Job Processing</h2>
                    <div id="progress-content" style="font-size: 14px;">
                        <p>🔄 Starting workers...</p>
                    </div>
                    <div style="background: rgba(255,255,255,0.2); border-radius: 8px; height: 24px; margin: 15px 0; overflow: hidden;">
                        <div id="progress-bar" style="background: #10b981; height: 100%; width: 0%; transition: width 0.3s ease; display: flex; align-items: center; justify-content: center; font-weight: bold; font-size: 12px;">
                            <span id="progress-text">0%</span>
                        </div>
                    </div>
                    <div id="progress-details" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 10px; margin-top: 15px;">
                        <div style="background: rgba(255,255,255,0.1); padding: 10px; border-radius: 6px;">
                            <div style="font-size: 20px; font-weight: bold;" id="emails-collected">0</div>
                            <div style="font-size: 12px; opacity: 0.9;">Emails Collected</div>
                        </div>
                        <div style="background: rgba(255,255,255,0.1); padding: 10px; border-radius: 6px;">
                            <div style="font-size: 20px; font-weight: bold;" id="emails-required">-</div>
                            <div style="font-size: 12px; opacity: 0.9;">Target</div>
                        </div>
                        <div style="background: rgba(255,255,255,0.1); padding: 10px; border-radius: 6px;">
                            <div style="font-size: 20px; font-weight: bold;" id="active-workers-count">0</div>
                            <div style="font-size: 12px; opacity: 0.9;">Active Workers</div>
                        </div>
                        <div style="background: rgba(255,255,255,0.1); padding: 10px; border-radius: 6px;">
                            <div style="font-size: 20px; font-weight: bold;" id="job-status">pending</div>
                            <div style="font-size: 12px; opacity: 0.9;">Status</div>
                        </div>
                    </div>
                    <div style="margin-top: 15px; text-align: right;">
                        <a href="?page=results&job_id=<?php echo $newJobId; ?>" style="color: white; text-decoration: underline;">View Results</a>
                    </div>
                </div>
            <?php endif; ?>
            
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon">📊</div>
                    <div class="stat-content">
                        <div class="stat-value" id="total-jobs">-</div>
                        <div class="stat-label">Total Jobs</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">✅</div>
                    <div class="stat-content">
                        <div class="stat-value" id="completed-jobs">-</div>
                        <div class="stat-label">Completed</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">📧</div>
                    <div class="stat-content">
                        <div class="stat-value" id="total-results">-</div>
                        <div class="stat-label">Total Emails</div>
                    </div>
                </div>
            </div>
            
            <div class="card">
                <h2>Recent Jobs</h2>
                <?php if (empty($jobs)): ?>
                    <p class="empty-state">No jobs yet. <a href="?page=new-job">Create your first job</a></p>
                <?php else: ?>
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Query</th>
                                <th>Status</th>
                                <th>Progress</th>
                                <th>Emails</th>
                                <th>Filter</th>
                                <th>Created</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($jobs as $job): ?>
                                <tr>
                                    <td>#<?php echo $job['id']; ?></td>
                                    <td><?php echo htmlspecialchars($job['query']); ?></td>
                                    <td><span class="status-badge status-<?php echo $job['status']; ?>"><?php echo ucfirst($job['status']); ?></span></td>
                                    <td>
                                        <div class="progress-bar">
                                            <div class="progress-fill" style="width: <?php echo $job['progress']; ?>%"></div>
                                        </div>
                                        <span class="progress-text"><?php echo $job['progress']; ?>%</span>
                                    </td>
                                    <td>
                                        <?php
                                        $db = Database::connect();
                                        $stmt = $db->prepare("SELECT COUNT(*) as count FROM emails WHERE job_id = ?");
                                        $stmt->execute([$job['id']]);
                                        $count = $stmt->fetch()['count'];
                                        echo $count;
                                        ?>
                                    </td>
                                    <td><?php echo $job['email_filter'] ? ucfirst($job['email_filter']) : 'All'; ?></td>
                                    <td><?php echo date('Y-m-d H:i', strtotime($job['created_at'])); ?></td>
                                    <td>
                                        <a href="?page=results&job_id=<?php echo $job['id']; ?>" class="btn btn-sm">View</a>
                                        <form method="POST" style="display: inline;">
                                            <input type="hidden" name="action" value="delete_job">
                                            <input type="hidden" name="job_id" value="<?php echo $job['id']; ?>">
                                            <button type="submit" class="btn btn-sm btn-danger" onclick="return confirm('Delete this job?')">Delete</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
            
            <script>
                function updateStats() {
                    fetch('?page=api&action=stats')
                        .then(res => res.json())
                        .then(data => {
                            document.getElementById('total-jobs').textContent = data.totalJobs;
                            document.getElementById('completed-jobs').textContent = data.completedJobs;
                            document.getElementById('total-results').textContent = data.totalResults;
                        });
                }
                
                updateStats();
                setInterval(updateStats, 5000);
                
                // Job progress widget logic
                <?php if ($newJobId > 0): ?>
                const jobId = <?php echo $newJobId; ?>;
                let progressInterval = null;
                let processingStarted = false;
                
                // Function to update job progress
                function updateJobProgress() {
                    fetch('?page=api&action=job-worker-status&job_id=' + jobId)
                        .then(response => response.json())
                        .then(data => {
                            if (data.error) {
                                console.error('Error fetching job status:', data.error);
                                return;
                            }
                            
                            const job = data.job;
                            const emailsCollected = data.emails_collected;
                            const emailsRequired = data.emails_required;
                            const completionPercentage = data.completion_percentage;
                            const activeWorkers = data.active_workers;
                            
                            // Update progress bar
                            document.getElementById('progress-bar').style.width = completionPercentage + '%';
                            document.getElementById('progress-text').textContent = completionPercentage + '%';
                            
                            // Update details
                            document.getElementById('emails-collected').textContent = emailsCollected;
                            document.getElementById('emails-required').textContent = emailsRequired;
                            document.getElementById('active-workers-count').textContent = activeWorkers;
                            document.getElementById('job-status').textContent = job.status;
                            
                            // Update progress content message
                            let message = '';
                            if (job.status === 'completed') {
                                message = '✅ Job completed! ' + emailsCollected + ' emails extracted.';
                                if (progressInterval) {
                                    clearInterval(progressInterval);
                                    progressInterval = null;
                                }
                                // Reload stats after completion
                                updateStats();
                            } else if (job.status === 'running') {
                                message = '⚡ Processing... ' + activeWorkers + ' workers active.';
                            } else if (job.status === 'failed') {
                                message = '❌ Job failed. Check errors below.';
                                if (progressInterval) {
                                    clearInterval(progressInterval);
                                    progressInterval = null;
                                }
                            } else {
                                message = '🔄 Initializing workers...';
                            }
                            document.getElementById('progress-content').innerHTML = '<p>' + message + '</p>';
                        })
                        .catch(error => {
                            console.error('Error updating job progress:', error);
                        });
                }
                
                // Start processing workers via AJAX (non-blocking)
                function startJobProcessing() {
                    if (processingStarted) return;
                    processingStarted = true;
                    
                    fetch('?page=api&action=process-job-workers', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: 'job_id=' + jobId + '&worker_count=5'
                    })
                    .then(response => response.json())
                    .then(data => {
                        console.log('Worker processing triggered:', data);
                    })
                    .catch(error => {
                        console.error('Error starting workers:', error);
                        document.getElementById('progress-content').innerHTML = '<p style="color: #fca5a5;">❌ Error starting workers: ' + error.message + '</p>';
                    });
                }
                
                // Start progress monitoring immediately
                updateJobProgress();
                progressInterval = setInterval(updateJobProgress, 3000); // Update every 3 seconds
                
                // Trigger worker processing after a short delay (let UI render first)
                setTimeout(startJobProcessing, 500);
                <?php endif; ?>
            </script>
            <?php
        });
    }
    
    private static function spawnParallelWorkers(int $jobId, int $workerCount): void {
        $job = Job::getById($jobId);
        if (!$job) {
            error_log("✗ ERROR: Cannot spawn workers - Job {$jobId} not found");
            return;
        }
        
        $maxResults = (int)$job['max_results'];
        $resultsPerWorker = (int)ceil($maxResults / $workerCount);
        
        error_log("Creating queue for job {$jobId}: {$maxResults} total results, {$workerCount} workers, ~{$resultsPerWorker} per worker");
        
        // Create queue items for parallel processing
        $db = Database::connect();
        $queueItemsCreated = 0;
        
        for ($i = 0; $i < $workerCount; $i++) {
            $startOffset = $i * $resultsPerWorker;
            $workerMaxResults = min($resultsPerWorker, $maxResults - $startOffset);
            
            if ($workerMaxResults > 0) {
                // Insert queue item
                $stmt = $db->prepare("INSERT INTO job_queue (job_id, start_offset, max_results, status) VALUES (?, ?, ?, 'pending')");
                $stmt->execute([$jobId, $startOffset, $workerMaxResults]);
                $queueItemsCreated++;
                error_log("  Queue item {$i}: offset {$startOffset}, max {$workerMaxResults}");
            }
        }
        
        // Don't set to running yet - wait for workers to actually start
        error_log("✓ Created {$queueItemsCreated} queue items for job {$jobId}. Spawning workers now...");
        
        // Spawn workers directly to process queue items
        $successCount = self::spawnWorkersDirectly($jobId, $workerCount);
        
        if ($successCount > 0) {
            // At least one worker started successfully
            Job::updateStatus($jobId, 'running', 0);
            error_log("✓ Worker spawning completed for job {$jobId} - {$successCount}/{$workerCount} workers started");
        } else {
            // No workers started - mark job as failed
            Job::updateStatus($jobId, 'failed', 0);
            error_log("✗ Worker spawning FAILED for job {$jobId} - no workers could start");
            
            // Log error to worker_errors table for UI display
            $stmt = $db->prepare("INSERT INTO worker_errors (worker_id, job_id, error_type, error_message, severity) VALUES (NULL, ?, 'spawn_failed', ?, 'critical')");
            $stmt->execute([$jobId, "Failed to spawn any workers for job {$jobId}. Check server configuration and error logs."]);
        }
    }
    
    private static function spawnWorkersDirectly(int $jobId, int $workerCount): int {
        // For environments where HTTP loopback connections are blocked (common hosting restriction)
        // Process workers inline since curl to self fails
        
        error_log("Spawning {$workerCount} workers for job {$jobId} using inline processing");
        
        $successCount = 0;
        $errors = [];
        $db = Database::connect();
        
        // Process all queue items for this job inline
        // This works even when HTTP loopback is blocked
        for ($i = 0; $i < $workerCount; $i++) {
            $workerName = 'worker-' . $jobId . '-' . $i . '-' . time();
            
            try {
                error_log("✓ Starting inline worker {$i} for job {$jobId}");
                
                // Register worker
                $workerId = Worker::register($workerName);
                
                // Get next queue item for this job
                $job = Worker::getNextJob();
                
                if ($job && (int)$job['id'] == $jobId) {
                    // Process this queue item with queue parameters
                    Worker::updateHeartbeat($workerId, 'running', $jobId, 0, 0);
                    error_log("  Worker {$workerName}: Processing queue item for job {$jobId}");
                    
                    // Get queue parameters from the job
                    $startOffset = isset($job['queue_start_offset']) ? (int)$job['queue_start_offset'] : 0;
                    $maxResults = isset($job['queue_max_results']) ? (int)$job['queue_max_results'] : (int)$job['max_results'];
                    $queueId = isset($job['queue_id']) ? (int)$job['queue_id'] : null;
                    
                    error_log("    Queue item: offset={$startOffset}, max={$maxResults}, queue_id={$queueId}");
                    
                    // Process using the immediate method with queue parameters
                    Worker::processJobImmediately($jobId, $startOffset, $maxResults);
                    
                    // Mark queue item as complete if we have queue_id
                    if ($queueId) {
                        Worker::markQueueItemComplete($queueId);
                        
                        // Check if all queue items are complete and update job status
                        Worker::checkAndUpdateJobCompletion($jobId);
                    }
                    
                    Worker::updateHeartbeat($workerId, 'idle', null, 0, 0);
                    $successCount++;
                    error_log("✓ Worker {$i} completed processing queue item {$queueId} for job {$jobId}");
                } else {
                    // No queue item available for this job
                    $successCount++; // Still count as success - worker was ready
                    error_log("  Worker {$i} registered but no queue item available yet for job {$jobId}");
                }
                
            } catch (Exception $e) {
                $errorMsg = "Worker {$i} processing exception: " . $e->getMessage();
                $errors[] = $errorMsg;
                error_log("✗ {$errorMsg}");
                
                // Log to database for UI display
                $stmt = $db->prepare("INSERT INTO worker_errors (worker_id, job_id, error_type, error_message, severity) VALUES (NULL, ?, 'processing_error', ?, 'error')");
                $stmt->execute([$jobId, $errorMsg]);
            }
        }
        
        // Log summary
        if ($successCount > 0) {
            error_log("✓ Successfully processed {$successCount}/{$workerCount} queue items for job {$jobId}");
        } else if (!empty($errors)) {
            $summaryMsg = "All workers failed. Errors: " . implode("; ", $errors);
            error_log("✗ CRITICAL: {$summaryMsg}");
        }
        
        return $successCount;
    }
    
    private static function autoSpawnWorkers(int $workerCount): void {
        // Check if exec() is available
        $execAvailable = function_exists('exec') && !in_array('exec', array_map('trim', explode(',', ini_get('disable_functions'))));
        
        if ($execAvailable) {
            // Method 1: Spawn background PHP processes (preferred)
            self::spawnWorkersViaExec($workerCount);
        } else {
            // Method 2: Use async HTTP requests (fallback for restricted hosting)
            self::spawnWorkersViaHttp($workerCount);
        }
    }
    
    private static function spawnWorkersViaExec(int $workerCount): void {
        $phpBinary = PHP_BINARY;
        $scriptPath = __FILE__;
        
        for ($i = 0; $i < $workerCount; $i++) {
            // Build command to run worker in queue mode
            $workerName = 'auto-worker-' . uniqid() . '-' . $i;
            $cmd = sprintf(
                '%s %s %s > /dev/null 2>&1 &',
                escapeshellarg($phpBinary),
                escapeshellarg($scriptPath),
                escapeshellarg($workerName)
            );
            
            // Execute in background
            if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
                // Windows
                pclose(popen("start /B " . $cmd, "r"));
            } else {
                // Unix/Linux
                exec($cmd);
            }
            
            error_log("Spawned worker: {$workerName}");
        }
    }
    
    private static function spawnWorkersViaHttp(int $workerCount): void {
        // Get the current URL
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $scriptName = $_SERVER['SCRIPT_NAME'] ?? '/app.php';
        $baseUrl = $protocol . '://' . $host . $scriptName;
        
        error_log("Spawning {$workerCount} HTTP workers");
        
        for ($i = 0; $i < $workerCount; $i++) {
            $workerName = 'auto-worker-' . uniqid() . '-' . $i;
            
            // Create async HTTP request to trigger worker
            $ch = curl_init($baseUrl . '?page=start-worker');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
                'worker_name' => $workerName,
                'worker_index' => $i
            ]));
            curl_setopt($ch, CURLOPT_TIMEOUT, 2); // Short timeout to just start the worker
            curl_setopt($ch, CURLOPT_NOSIGNAL, 1);
            
            // Execute async (don't wait for response)
            curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            
            if ($httpCode === 200) {
                error_log("Successfully spawned HTTP worker: {$workerName}");
            } else {
                error_log("Failed to spawn HTTP worker #{$i}, HTTP code: {$httpCode}");
            }
            
            curl_close($ch);
            
            // Small delay between spawning workers
            usleep(50000); // 0.05 seconds
        }
    }
    
    private static function renderNewJob(): void {
        $success = false;
        $jobId = 0;
        $error = null;
        $warnings = [];
        
        if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
            try {
                $query = $_POST['query'] ?? '';
                $apiKey = $_POST['api_key'] ?? '';
                $maxResults = (int)($_POST['max_results'] ?? 100);
                $country = !empty($_POST['country']) ? $_POST['country'] : null;
                $emailFilter = $_POST['email_filter'] ?? 'all';
                $workerCount = (int)($_POST['worker_count'] ?? 5);
                
                if ($query && $apiKey) {
                    // Create job for immediate processing
                    $jobId = Job::create(Auth::getUserId(), $query, $apiKey, $maxResults, $country, $emailFilter);
                    
                    // Redirect immediately to dashboard with job ID
                    // Workers will be processed via AJAX polling from dashboard
                    header('Location: ?page=dashboard&job_id=' . $jobId);
                    exit;
                } else {
                    $error = 'Query and API Key are required';
                }
            } catch (Exception $e) {
                $error = 'Error creating job: ' . $e->getMessage();
                error_log('Job creation error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
            }
        }
        
        self::renderLayout('New Job', function() use ($success, $jobId, $error, $warnings) {
            ?>
            <?php if ($error): ?>
                <div class="alert alert-error" style="white-space: pre-wrap; word-wrap: break-word; max-height: 400px; overflow-y: auto; font-family: monospace; font-size: 12px;">
                    <strong style="font-size: 14px;">✗ Error:</strong><br>
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>
            
            <?php if (!empty($warnings)): ?>
                <?php foreach ($warnings as $warning): ?>
                    <div class="alert alert-warning" style="background: #fef3c7; border-color: #fbbf24; color: #92400e;">
                        ⚠️ <?php echo htmlspecialchars($warning); ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
            
            <?php if ($success && !$error): ?>
                <div class="alert alert-success">
                    ✓ Job #<?php echo $jobId; ?> created and workers started! 
                    <br><a href="?page=results&job_id=<?php echo $jobId; ?>">View Job</a> | 
                    <a href="?page=dashboard">Go to Dashboard</a> | 
                    <a href="?page=workers">View Workers & Status</a>
                </div>
            <?php endif; ?>
            
            <div class="card">
                <h2>Create New Email Extraction Job</h2>
                <p style="margin-bottom: 20px; color: #4a5568;">
                    ⚡ Workers start automatically when you create a job! Results appear in real-time.
                </p>
                <form method="POST">
                    <div class="form-group">
                        <label>Search Query *</label>
                        <input type="text" name="query" placeholder="e.g., real estate agents california" required>
                        <small>Enter search terms to find pages containing emails</small>
                    </div>
                    
                    <div class="form-group">
                        <label>Serper.dev API Key *</label>
                        <input type="text" name="api_key" placeholder="Your API key from serper.dev" required>
                        <small>Get your API key from <a href="https://serper.dev" target="_blank">serper.dev</a></small>
                    </div>
                    
                    <div class="form-group">
                        <label>Maximum Emails</label>
                        <input type="number" name="max_results" value="100" min="1" max="100000">
                        <small>Target number of emails to extract (1-100,000)</small>
                    </div>
                    
                    <div class="form-group">
                        <label>Country Target (Optional)</label>
                        <select name="country">
                            <option value="">All Countries</option>
                            <option value="us">United States</option>
                            <option value="uk">United Kingdom</option>
                            <option value="ca">Canada</option>
                            <option value="au">Australia</option>
                            <option value="de">Germany</option>
                            <option value="fr">France</option>
                            <option value="es">Spain</option>
                            <option value="it">Italy</option>
                            <option value="jp">Japan</option>
                            <option value="cn">China</option>
                            <option value="in">India</option>
                            <option value="br">Brazil</option>
                        </select>
                        <small>Target search results from a specific country</small>
                    </div>
                    
                    <div class="form-group">
                        <label>Email Type Filter</label>
                        <select name="email_filter">
                            <option value="all">All Email Types</option>
                            <option value="gmail">Gmail Only</option>
                            <option value="yahoo">Yahoo Only</option>
                            <option value="business">Business Domains Only</option>
                        </select>
                        <small>Filter extracted emails by domain type</small>
                    </div>
                    
                    <div class="form-group">
                        <label>Parallel Workers</label>
                        <input type="number" name="worker_count" value="5" min="1" max="1000">
                        <small>Number of parallel workers to spawn automatically (1-1000). More workers = faster processing</small>
                    </div>
                    
                    <button type="submit" class="btn btn-primary">Create Job & Start Processing</button>
                    <a href="?page=dashboard" class="btn">Cancel</a>
                </form>
            </div>
            
            <div class="card">
                <h2>ℹ️ How It Works</h2>
                <ol style="padding-left: 20px;">
                    <li>Create a job with your search query and preferences</li>
                    <li>Workers start automatically in the background</li>
                    <li>Job is split into chunks for parallel processing</li>
                    <li>Workers use <strong>curl_multi</strong> to fetch multiple URLs simultaneously</li>
                    <li>Emails are extracted and validated in batches</li>
                    <li>Duplicates are filtered using in-memory BloomFilter cache + database</li>
                    <li>Results appear in real-time on the dashboard</li>
                </ol>
                <p style="margin-top: 15px;">
                    <strong>⚡ Performance Optimizations:</strong>
                </p>
                <ul style="padding-left: 20px; color: #4a5568;">
                    <li><strong>Parallel HTTP Requests:</strong> Up to 50 simultaneous connections with curl_multi</li>
                    <li><strong>Batch Processing:</strong> URLs scraped in parallel, emails inserted in bulk</li>
                    <li><strong>Connection Reuse:</strong> HTTP keep-alive and HTTP/2 support for faster requests</li>
                    <li><strong>Memory Caching:</strong> BloomFilter cache reduces database queries by ~90%</li>
                    <li><strong>Optimized Rate Limiting:</strong> 0.3s default (vs 0.5s) with parallel processing</li>
                </ul>
                <p style="margin-top: 15px;">
                    <strong>Auto-Processing:</strong> Workers are spawned automatically when you click "Create Job & Start Processing". The UI returns instantly while workers process in the background. Works on all hosting environments including cPanel!
                </p>
            </div>
            <?php
        });
    }
    
    private static function renderSettings(): void {
        if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
            foreach ($_POST as $key => $value) {
                if (strpos($key, 'setting_') === 0) {
                    $settingKey = substr($key, 8);
                    Settings::set($settingKey, $value);
                }
            }
            $success = 'Settings saved successfully!';
        }
        
        $settings = Settings::getAll();
        $successMsg = $success ?? null;
        
        self::renderLayout('Settings', function() use ($settings, $successMsg) {
            ?>
            <?php if ($successMsg): ?>
                <div class="alert alert-success"><?php echo $successMsg; ?></div>
            <?php endif; ?>
            
            <div class="card">
                <h2>System Settings</h2>
                <form method="POST">
                    <div class="form-group">
                        <label>Default API Key</label>
                        <input type="text" name="setting_default_api_key" value="<?php echo htmlspecialchars($settings['default_api_key'] ?? ''); ?>">
                        <small>Used as default for new jobs</small>
                    </div>
                    
                    <div class="form-group">
                        <label>Default Max Results</label>
                        <input type="number" name="setting_default_max_results" value="<?php echo htmlspecialchars($settings['default_max_results'] ?? '100'); ?>">
                    </div>
                    
                    <div class="form-group">
                        <label>Rate Limit (seconds between requests)</label>
                        <!-- Default: 0.3 (see Worker::DEFAULT_RATE_LIMIT) -->
                        <input type="number" step="0.1" name="setting_rate_limit" value="<?php echo htmlspecialchars($settings['rate_limit'] ?? '0.3'); ?>">
                        <small>Optimized for parallel processing: 0.1-0.5 seconds recommended with curl_multi</small>
                    </div>
                    
                    <div class="form-group">
                        <label>Deep Scraping</label>
                        <select name="setting_deep_scraping">
                            <option value="1" <?php echo ($settings['deep_scraping'] ?? '1') === '1' ? 'selected' : ''; ?>>Enabled</option>
                            <option value="0" <?php echo ($settings['deep_scraping'] ?? '1') === '0' ? 'selected' : ''; ?>>Disabled</option>
                        </select>
                        <small>When enabled, workers will fetch and scan page content for emails (slower but more comprehensive)</small>
                    </div>
                    
                    <div class="form-group">
                        <label>Deep Scraping Threshold</label>
                        <input type="number" name="setting_deep_scraping_threshold" value="<?php echo htmlspecialchars($settings['deep_scraping_threshold'] ?? '5'); ?>" min="1" max="20">
                        <small>Only fetch page content if fewer than this many emails found in search result (1-20)</small>
                    </div>
                    
                    <button type="submit" class="btn btn-primary">Save Settings</button>
                </form>
            </div>
            
            <div class="card">
                <h2>System Information</h2>
                <table class="info-table">
                    <tr>
                        <th>PHP Version</th>
                        <td><?php echo PHP_VERSION; ?></td>
                    </tr>
                    <tr>
                        <th>Database</th>
                        <td>MySQL</td>
                    </tr>
                    <tr>
                        <th>Session ID</th>
                        <td><?php echo session_id(); ?></td>
                    </tr>
                    <tr>
                        <th>User</th>
                        <td><?php echo htmlspecialchars($_SESSION['username']); ?></td>
                    </tr>
                </table>
            </div>
            <?php
        });
    }
    
    private static function renderWorkers(): void {
        self::renderLayout('Workers Control', function() {
            ?>
            <!-- Worker Statistics Dashboard -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon">🚀</div>
                    <div class="stat-content">
                        <div class="stat-value" id="active-workers">-</div>
                        <div class="stat-label">Active Workers</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">💤</div>
                    <div class="stat-content">
                        <div class="stat-value" id="idle-workers">-</div>
                        <div class="stat-label">Idle Workers</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">📋</div>
                    <div class="stat-content">
                        <div class="stat-value" id="pending-queue">-</div>
                        <div class="stat-label">Pending in Queue</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">⚙️</div>
                    <div class="stat-content">
                        <div class="stat-value" id="processing-queue">-</div>
                        <div class="stat-label">Processing Now</div>
                    </div>
                </div>
            </div>
            
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon">📄</div>
                    <div class="stat-content">
                        <div class="stat-value" id="total-pages">-</div>
                        <div class="stat-label">Pages Processed</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">📧</div>
                    <div class="stat-content">
                        <div class="stat-value" id="total-worker-emails">-</div>
                        <div class="stat-label">Emails Extracted</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">⚡</div>
                    <div class="stat-content">
                        <div class="stat-value" id="extraction-rate">-</div>
                        <div class="stat-label">Emails/Min Rate</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">⏱️</div>
                    <div class="stat-content">
                        <div class="stat-value" id="avg-runtime">-</div>
                        <div class="stat-label">Avg Runtime</div>
                    </div>
                </div>
            </div>
            
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-icon">✅</div>
                    <div class="stat-content">
                        <div class="stat-value" id="completed-queue">-</div>
                        <div class="stat-label">Completed</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">📊</div>
                    <div class="stat-content">
                        <div class="stat-value" id="queue-rate">-</div>
                        <div class="stat-label">Queue Progress</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">🕐</div>
                    <div class="stat-content">
                        <div class="stat-value" id="last-update">-</div>
                        <div class="stat-label">Last Update</div>
                    </div>
                </div>
                <div class="stat-card" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white;">
                    <div class="stat-icon" style="opacity: 0.9;">🚀</div>
                    <div class="stat-content">
                        <div class="stat-value" style="color: white;">curl_multi</div>
                        <div class="stat-label" style="color: rgba(255,255,255,0.9);">Parallel Mode</div>
                    </div>
                </div>
            </div>
            
            <div class="card">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                    <h2>Active Workers</h2>
                    <div class="worker-status-indicator">
                        <span class="status-dot" id="worker-status-dot"></span>
                        <span id="worker-status-text">Monitoring...</span>
                    </div>
                </div>
                <div id="workers-list">Loading...</div>
            </div>
            
            <div class="card">
                <h2>🚨 System Alerts & Errors</h2>
                <div id="worker-errors-list">Loading...</div>
            </div>
            
            <div class="card">
                <h2>Start New Worker</h2>
                <p>To start a worker, run this command in your terminal:</p>
                <pre class="code-block">php <?php echo __FILE__; ?> worker-name</pre>
                <p>Example:</p>
                <pre class="code-block">php <?php echo __FILE__; ?> worker-1</pre>
                <p style="margin-top: 15px; color: #718096;">
                    <strong>Performance Features:</strong>
                </p>
                <ul style="color: #718096; padding-left: 20px;">
                    <li>✅ <strong>curl_multi</strong> for parallel HTTP requests (up to 50 simultaneous)</li>
                    <li>✅ <strong>Bulk operations</strong> for database inserts and email validation</li>
                    <li>✅ <strong>In-memory caching</strong> for BloomFilter (10K item cache)</li>
                    <li>✅ <strong>HTTP keep-alive</strong> and connection reuse</li>
                    <li>✅ Automatic performance tracking and error logging</li>
                </ul>
            </div>
            
            <script>
                function formatRuntime(seconds) {
                    if (seconds < 60) return seconds + 's';
                    if (seconds < 3600) return Math.floor(seconds / 60) + 'm';
                    return Math.floor(seconds / 3600) + 'h ' + Math.floor((seconds % 3600) / 60) + 'm';
                }
                
                function updateWorkerErrors() {
                    fetch('?page=api&action=worker-errors&unresolved_only=1')
                        .then(res => res.json())
                        .then(errors => {
                            const container = document.getElementById('worker-errors-list');
                            
                            if (errors.length === 0) {
                                container.innerHTML = '<p class="empty-state" style="padding: 20px;">✓ No unresolved errors. All systems running smoothly!</p>';
                                return;
                            }
                            
                            let html = '';
                            errors.forEach(error => {
                                const severity = error.severity || 'error';
                                let icon = '⚠️';
                                let alertClass = 'alert-error';
                                
                                if (severity === 'critical') {
                                    icon = '🚨';
                                    alertClass = 'alert-critical';
                                } else if (severity === 'warning') {
                                    icon = '⚠️';
                                    alertClass = 'alert-warning';
                                }
                                
                                html += `
                                    <div class="alert ${alertClass}" style="margin-bottom: 10px;">
                                        <div style="display: flex; justify-content: space-between; align-items: start;">
                                            <div style="flex: 1;">
                                                <strong>${icon} ${error.error_type || 'Error'}</strong><br>
                                                ${error.error_message || 'Unknown error'}
                                                ${error.worker_name ? '<br><small>Worker: ' + error.worker_name + '</small>' : ''}
                                                ${error.job_query ? '<br><small>Job: ' + error.job_query + '</small>' : ''}
                                                ${error.created_at ? '<br><small>Time: ' + new Date(error.created_at).toLocaleString() + '</small>' : ''}
                                                ${error.error_details ? '<br><small style="color: #666;">Details: ' + error.error_details + '</small>' : ''}
                                            </div>
                                            <button class="btn btn-sm" onclick="resolveWorkerError(${error.id})" style="margin-left: 10px;">Resolve</button>
                                        </div>
                                    </div>
                                `;
                            });
                            
                            container.innerHTML = html;
                        })
                        .catch(err => {
                            console.error('Error fetching worker errors:', err);
                        });
                }
                
                function resolveWorkerError(errorId) {
                    fetch('?page=api&action=resolve-error', {
                        method: 'POST',
                        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                        body: 'error_id=' + errorId
                    })
                    .then(res => res.json())
                    .then(data => {
                        if (data.success) {
                            updateWorkerErrors();
                        }
                    })
                    .catch(err => console.error('Error resolving error:', err));
                }
                
                function updateWorkerStats() {
                    fetch('?page=api&action=worker-stats')
                        .then(res => res.json())
                        .then(stats => {
                            document.getElementById('active-workers').textContent = stats.active_workers;
                            document.getElementById('idle-workers').textContent = stats.idle_workers;
                            document.getElementById('total-pages').textContent = stats.total_pages;
                            document.getElementById('total-worker-emails').textContent = stats.total_emails;
                            document.getElementById('avg-runtime').textContent = formatRuntime(stats.avg_runtime);
                            
                            // Display extraction rate
                            if (stats.emails_per_minute > 0) {
                                document.getElementById('extraction-rate').textContent = stats.emails_per_minute;
                            } else {
                                document.getElementById('extraction-rate').textContent = '-';
                            }
                            
                            // Update status indicator
                            const statusDot = document.getElementById('worker-status-dot');
                            const statusText = document.getElementById('worker-status-text');
                            
                            if (stats.active_workers > 0) {
                                statusDot.style.background = '#48bb78';
                                statusText.textContent = stats.active_workers + ' worker(s) active';
                            } else if (stats.idle_workers > 0) {
                                statusDot.style.background = '#ecc94b';
                                statusText.textContent = 'Workers idle';
                            } else {
                                statusDot.style.background = '#cbd5e0';
                                statusText.textContent = 'No workers';
                            }
                        })
                        .catch(err => console.error('Error fetching worker stats:', err));
                    
                    // Update queue stats
                    fetch('?page=api&action=queue-stats')
                        .then(res => res.json())
                        .then(queue => {
                            document.getElementById('pending-queue').textContent = queue.pending;
                            document.getElementById('processing-queue').textContent = queue.processing;
                            document.getElementById('completed-queue').textContent = queue.completed;
                            
                            // Calculate processing rate
                            const total = queue.pending + queue.processing + queue.completed;
                            if (total > 0) {
                                const rate = Math.round((queue.completed / total) * 100) + '%';
                                document.getElementById('queue-rate').textContent = rate;
                            } else {
                                document.getElementById('queue-rate').textContent = '0%';
                            }
                        })
                        .catch(err => console.error('Error fetching queue stats:', err));
                }
                
                function updateWorkers() {
                    fetch('?page=api&action=workers')
                        .then(res => res.json())
                        .then(workers => {
                            const container = document.getElementById('workers-list');
                            
                            if (workers.length === 0) {
                                container.innerHTML = '<p class="empty-state">No workers registered yet. Start a worker using the command below.</p>';
                                return;
                            }
                            
                            let html = '<table class="data-table"><thead><tr><th>Worker</th><th>Status</th><th>Current Job</th><th>Pages</th><th>Emails</th><th>Runtime</th><th>Last Heartbeat</th></tr></thead><tbody>';
                            
                            workers.forEach(worker => {
                                const lastHeartbeat = worker.last_heartbeat ? new Date(worker.last_heartbeat).toLocaleString() : 'Never';
                                const jobInfo = worker.current_job_id ? '<a href="?page=results&job_id=' + worker.current_job_id + '">#' + worker.current_job_id + '</a>' : '-';
                                const runtime = formatRuntime(worker.runtime_seconds || 0);
                                
                                html += `<tr>
                                    <td><strong>${worker.worker_name}</strong></td>
                                    <td><span class="status-badge status-${worker.status}">${worker.status}</span></td>
                                    <td>${jobInfo}</td>
                                    <td>${worker.pages_processed || 0}</td>
                                    <td>${worker.emails_extracted || 0}</td>
                                    <td>${runtime}</td>
                                    <td>${lastHeartbeat}</td>
                                </tr>`;
                            });
                            
                            html += '</tbody></table>';
                            container.innerHTML = html;
                            
                            // Update last update time
                            document.getElementById('last-update').textContent = new Date().toLocaleTimeString();
                        })
                        .catch(err => {
                            console.error('Error fetching workers:', err);
                        });
                }
                
                // Initial load
                updateWorkerStats();
                updateWorkers();
                updateWorkerErrors();
                
                // Update every 3 seconds
                setInterval(() => {
                    updateWorkerStats();
                    updateWorkers();
                    updateWorkerErrors();
                }, 3000);
            </script>
            <?php
        });
    }
    
    private static function renderResults(): void {
        $jobId = (int)($_GET['job_id'] ?? 0);
        
        if (!$jobId) {
            header('Location: ?page=dashboard');
            exit;
        }
        
        $job = Job::getById($jobId);
        $results = Job::getResults($jobId);
        
        self::renderLayout('Results', function() use ($job, $results, $jobId) {
            ?>
            <div class="card">
                <h2>Job #<?php echo $jobId; ?>: <?php echo htmlspecialchars($job['query']); ?></h2>
                
                <div class="job-info">
                    <span class="status-badge status-<?php echo $job['status']; ?>"><?php echo ucfirst($job['status']); ?></span>
                    <span>Progress: <?php echo $job['progress']; ?>%</span>
                    <span>Emails: <?php echo count($results); ?></span>
                    <?php if ($job['country']): ?>
                        <span>Country: <?php echo strtoupper($job['country']); ?></span>
                    <?php endif; ?>
                    <?php if ($job['email_filter']): ?>
                        <span>Filter: <?php echo ucfirst($job['email_filter']); ?></span>
                    <?php endif; ?>
                </div>
                
                <!-- Progress Bar -->
                <div style="margin: 20px 0;">
                    <div class="progress-bar" style="height: 30px; border-radius: 5px;">
                        <div class="progress-fill" style="width: <?php echo $job['progress']; ?>%; height: 100%; background: linear-gradient(90deg, #4CAF50, #8BC34A); border-radius: 5px; display: flex; align-items: center; justify-content: center; color: white; font-weight: bold;">
                            <?php if ($job['progress'] > 0): ?>
                                <?php echo $job['progress']; ?>%
                            <?php endif; ?>
                        </div>
                    </div>
                    <small style="color: #666; margin-top: 5px; display: block;">
                        <?php if ($job['status'] === 'running'): ?>
                            ⚡ Workers are processing... Check php_errors.log for detailed progress
                        <?php elseif ($job['status'] === 'completed'): ?>
                            ✓ Job completed successfully
                        <?php elseif ($job['status'] === 'pending'): ?>
                            ⏳ Waiting for workers to start...
                        <?php endif; ?>
                    </small>
                </div>
                
                <div class="action-bar">
                    <a href="?page=export&job_id=<?php echo $jobId; ?>&format=csv" class="btn">Export CSV</a>
                    <a href="?page=export&job_id=<?php echo $jobId; ?>&format=json" class="btn">Export JSON</a>
                    <a href="?page=dashboard" class="btn">Back to Dashboard</a>
                </div>
            </div>
            
            <!-- Worker Searcher Status Section -->
            <div class="card">
                <h2>⚙️ Worker Searcher Status</h2>
                
                <!-- Alerts Section -->
                <div id="worker-alerts" style="margin-bottom: 20px;"></div>
                
                <div class="stats-grid" style="margin-bottom: 20px;">
                    <div class="stat-card">
                        <div class="stat-icon">👥</div>
                        <div class="stat-content">
                            <div class="stat-value" id="job-active-workers">-</div>
                            <div class="stat-label">Active Workers</div>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon">📧</div>
                        <div class="stat-content">
                            <div class="stat-value" id="job-emails-collected">-</div>
                            <div class="stat-label">Emails Collected</div>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon">🎯</div>
                        <div class="stat-content">
                            <div class="stat-value" id="job-emails-required">-</div>
                            <div class="stat-label">Emails Required</div>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon">📊</div>
                        <div class="stat-content">
                            <div class="stat-value" id="job-completion-percentage">-</div>
                            <div class="stat-label">Completion %</div>
                        </div>
                    </div>
                </div>
                
                <!-- Active Workers Details -->
                <div id="job-workers-details"></div>
            </div>
            
            <div class="card">
                <h2>Extracted Emails</h2>
                <?php if (empty($results)): ?>
                    <p class="empty-state">No emails extracted yet. Workers are processing in the background...</p>
                <?php else: ?>
                    <div class="results-list">
                        <?php foreach ($results as $result): ?>
                            <div class="result-item">
                                <h3>📧 <?php echo htmlspecialchars($result['email']); ?></h3>
                                <p class="result-snippet">
                                    <strong>Domain:</strong> <?php echo htmlspecialchars($result['domain']); ?>
                                    <?php if ($result['country']): ?>
                                        | <strong>Country:</strong> <?php echo strtoupper($result['country']); ?>
                                    <?php endif; ?>
                                </p>
                                <?php if ($result['source_title']): ?>
                                    <p class="result-snippet"><strong>Source:</strong> <?php echo htmlspecialchars($result['source_title']); ?></p>
                                <?php endif; ?>
                                <?php if ($result['source_url']): ?>
                                    <p class="result-url"><a href="<?php echo htmlspecialchars($result['source_url']); ?>" target="_blank"><?php echo htmlspecialchars($result['source_url']); ?></a></p>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
            
            <script>
                const jobId = <?php echo $jobId; ?>;
                
                function updateJobWorkerStatus() {
                    fetch(`?page=api&action=job-worker-status&job_id=${jobId}`)
                        .then(res => res.json())
                        .then(data => {
                            if (data.error) {
                                console.error('Error fetching worker status:', data.error);
                                return;
                            }
                            
                            // Update stats
                            document.getElementById('job-active-workers').textContent = data.active_workers || 0;
                            document.getElementById('job-emails-collected').textContent = data.emails_collected || 0;
                            document.getElementById('job-emails-required').textContent = data.emails_required || 0;
                            document.getElementById('job-completion-percentage').textContent = data.completion_percentage + '%';
                            
                            // Display alerts for errors
                            const alertsDiv = document.getElementById('worker-alerts');
                            if (data.recent_errors && data.recent_errors.length > 0) {
                                let alertsHtml = '';
                                data.recent_errors.forEach(error => {
                                    const severity = error.severity || 'error';
                                    let icon = '⚠️';
                                    if (severity === 'critical') icon = '🚨';
                                    else if (severity === 'warning') icon = '⚠️';
                                    
                                    alertsHtml += `
                                        <div class="alert alert-${severity}" style="margin-bottom: 10px;">
                                            <div style="display: flex; justify-content: space-between; align-items: start;">
                                                <div>
                                                    <strong>${icon} ${error.error_type || 'Error'}</strong><br>
                                                    ${error.error_message || 'Unknown error'}
                                                    ${error.worker_name ? '<br><small>Worker: ' + error.worker_name + '</small>' : ''}
                                                    ${error.created_at ? '<br><small>Time: ' + new Date(error.created_at).toLocaleString() + '</small>' : ''}
                                                </div>
                                                <button class="btn btn-sm" onclick="resolveError(${error.id})">Resolve</button>
                                            </div>
                                        </div>
                                    `;
                                });
                                alertsDiv.innerHTML = alertsHtml;
                            } else if (data.stale_workers && data.stale_workers.length > 0) {
                                let alertsHtml = '<div class="alert alert-warning">⚠️ <strong>Stale Workers Detected</strong><br>';
                                data.stale_workers.forEach(worker => {
                                    alertsHtml += `Worker "${worker.worker_name}" has not sent heartbeat recently. It may have crashed.<br>`;
                                });
                                alertsHtml += '</div>';
                                alertsDiv.innerHTML = alertsHtml;
                            } else {
                                alertsDiv.innerHTML = '';
                            }
                            
                            // Display active workers details
                            const workersDiv = document.getElementById('job-workers-details');
                            if (data.workers && data.workers.length > 0) {
                                let html = '<h3 style="margin: 20px 0 10px 0;">Active Workers</h3>';
                                html += '<table class="data-table"><thead><tr><th>Worker</th><th>Pages</th><th>Emails</th><th>Last Heartbeat</th></tr></thead><tbody>';
                                
                                data.workers.forEach(worker => {
                                    const lastHeartbeat = worker.last_heartbeat ? new Date(worker.last_heartbeat).toLocaleString() : 'Never';
                                    html += `<tr>
                                        <td><strong>${worker.worker_name}</strong></td>
                                        <td>${worker.pages_processed || 0}</td>
                                        <td>${worker.emails_extracted || 0}</td>
                                        <td>${lastHeartbeat}</td>
                                    </tr>`;
                                });
                                
                                html += '</tbody></table>';
                                workersDiv.innerHTML = html;
                            } else {
                                workersDiv.innerHTML = '<p style="color: #718096; margin-top: 10px;">No active workers currently processing this job.</p>';
                            }
                        })
                        .catch(err => {
                            console.error('Error fetching job worker status:', err);
                        });
                }
                
                function resolveError(errorId) {
                    fetch('?page=api&action=resolve-error', {
                        method: 'POST',
                        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                        body: 'error_id=' + errorId
                    })
                    .then(res => res.json())
                    .then(data => {
                        if (data.success) {
                            updateJobWorkerStatus();
                        }
                    })
                    .catch(err => console.error('Error resolving error:', err));
                }
                
                // Initial load
                updateJobWorkerStatus();
                
                // Update every 3 seconds
                setInterval(updateJobWorkerStatus, 3000);
                
                // Auto-refresh results if job is still running
                <?php if ($job['status'] === 'running' || $job['status'] === 'pending'): ?>
                setInterval(function() {
                    location.reload();
                }, 30000); // Reload every 30 seconds instead of 5
                <?php endif; ?>
            </script>
            <?php
        });
    }
    
    private static function renderLayout(string $title, callable $content): void {
        ?>
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title><?php echo htmlspecialchars($title); ?> - PHP Email Extraction System</title>
            <style><?php self::getCSS(); ?></style>
        </head>
        <body>
            <div class="sidebar">
                <div class="logo">
                    <h1>📧 Email Scraper</h1>
                </div>
                <nav class="nav">
                    <a href="?page=dashboard" class="nav-item <?php echo ($_GET['page'] ?? 'dashboard') === 'dashboard' ? 'active' : ''; ?>">
                        📊 Dashboard
                    </a>
                    <a href="?page=new-job" class="nav-item <?php echo ($_GET['page'] ?? '') === 'new-job' ? 'active' : ''; ?>">
                        ➕ New Email Job
                    </a>
                    <a href="?page=workers" class="nav-item <?php echo ($_GET['page'] ?? '') === 'workers' ? 'active' : ''; ?>">
                        👥 Workers
                    </a>
                    <a href="?page=settings" class="nav-item <?php echo ($_GET['page'] ?? '') === 'settings' ? 'active' : ''; ?>">
                        🔧 Settings
                    </a>
                    <a href="?page=logout" class="nav-item">
                        🚪 Logout
                    </a>
                </nav>
                <div class="sidebar-footer">
                    <small>User: <?php echo htmlspecialchars($_SESSION['username']); ?></small>
                </div>
            </div>
            
            <div class="main-content">
                <header class="header">
                    <h1><?php echo htmlspecialchars($title); ?></h1>
                </header>
                
                <div class="content">
                    <?php $content(); ?>
                </div>
            </div>
        </body>
        </html>
        <?php
    }
    
    private static function getCSS(): void {
        ?>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            background: #f5f7fa;
            color: #2d3748;
            line-height: 1.6;
        }
        
        /* Sidebar */
        .sidebar {
            position: fixed;
            left: 0;
            top: 0;
            bottom: 0;
            width: 260px;
            background: #1a202c;
            color: white;
            display: flex;
            flex-direction: column;
        }
        
        .logo {
            padding: 30px 20px;
            border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        }
        
        .logo h1 {
            font-size: 24px;
            font-weight: 700;
        }
        
        .nav {
            flex: 1;
            padding: 20px 0;
        }
        
        .nav-item {
            display: block;
            padding: 12px 20px;
            color: #a0aec0;
            text-decoration: none;
            transition: all 0.3s;
        }
        
        .nav-item:hover {
            background: rgba(255, 255, 255, 0.05);
            color: white;
        }
        
        .nav-item.active {
            background: #3182ce;
            color: white;
        }
        
        .sidebar-footer {
            padding: 20px;
            border-top: 1px solid rgba(255, 255, 255, 0.1);
            color: #a0aec0;
        }
        
        /* Main Content */
        .main-content {
            margin-left: 260px;
            min-height: 100vh;
        }
        
        .header {
            background: white;
            padding: 30px 40px;
            border-bottom: 1px solid #e2e8f0;
        }
        
        .header h1 {
            font-size: 28px;
            font-weight: 600;
        }
        
        .content {
            padding: 40px;
        }
        
        /* Cards */
        .card {
            background: white;
            border-radius: 8px;
            padding: 30px;
            margin-bottom: 30px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
        }
        
        .card h2 {
            font-size: 20px;
            font-weight: 600;
            margin-bottom: 20px;
        }
        
        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background: white;
            border-radius: 8px;
            padding: 25px;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
            display: flex;
            align-items: center;
            gap: 20px;
        }
        
        .stat-icon {
            font-size: 36px;
        }
        
        .stat-value {
            font-size: 32px;
            font-weight: 700;
            color: #2d3748;
        }
        
        .stat-label {
            font-size: 14px;
            color: #718096;
        }
        
        /* Forms */
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group label {
            display: block;
            font-weight: 600;
            margin-bottom: 8px;
        }
        
        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 10px 15px;
            border: 1px solid #e2e8f0;
            border-radius: 6px;
            font-size: 14px;
        }
        
        .form-group small {
            display: block;
            margin-top: 5px;
            color: #718096;
            font-size: 13px;
        }
        
        .form-section {
            margin-bottom: 30px;
        }
        
        .form-section h3 {
            margin-bottom: 15px;
            color: #2d3748;
        }
        
        /* Buttons */
        .btn {
            display: inline-block;
            padding: 10px 20px;
            background: #e2e8f0;
            color: #2d3748;
            text-decoration: none;
            border-radius: 6px;
            border: none;
            cursor: pointer;
            font-size: 14px;
            font-weight: 500;
            transition: all 0.3s;
        }
        
        .btn:hover {
            background: #cbd5e0;
        }
        
        .btn-primary {
            background: #3182ce;
            color: white;
        }
        
        .btn-primary:hover {
            background: #2c5282;
        }
        
        .btn-danger {
            background: #e53e3e;
            color: white;
        }
        
        .btn-danger:hover {
            background: #c53030;
        }
        
        .btn-large {
            width: 100%;
            padding: 15px;
            font-size: 16px;
        }
        
        .btn-sm {
            padding: 6px 12px;
            font-size: 13px;
        }
        
        /* Tables */
        .data-table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .data-table th,
        .data-table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #e2e8f0;
        }
        
        .data-table th {
            font-weight: 600;
            background: #f7fafc;
            color: #4a5568;
        }
        
        .data-table tr:hover {
            background: #f7fafc;
        }
        
        .info-table {
            width: 100%;
        }
        
        .info-table th,
        .info-table td {
            padding: 12px;
            border-bottom: 1px solid #e2e8f0;
        }
        
        .info-table th {
            text-align: left;
            font-weight: 600;
            width: 200px;
        }
        
        /* Status Badges */
        .status-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .status-pending {
            background: #fef5e7;
            color: #d68910;
        }
        
        .status-running {
            background: #ebf8ff;
            color: #2c5282;
        }
        
        .status-completed {
            background: #f0fff4;
            color: #22543d;
        }
        
        .status-failed {
            background: #fff5f5;
            color: #c53030;
        }
        
        .status-idle {
            background: #f7fafc;
            color: #4a5568;
        }
        
        .status-stopped {
            background: #fff5f5;
            color: #c53030;
        }
        
        /* Progress Bar */
        .progress-bar {
            display: inline-block;
            width: 100px;
            height: 8px;
            background: #e2e8f0;
            border-radius: 4px;
            overflow: hidden;
            vertical-align: middle;
        }
        
        .progress-fill {
            height: 100%;
            background: #3182ce;
            transition: width 0.3s;
        }
        
        .progress-text {
            display: inline-block;
            margin-left: 10px;
            font-size: 13px;
            color: #718096;
        }
        
        /* Alerts */
        .alert {
            padding: 15px;
            border-radius: 6px;
            margin-bottom: 20px;
        }
        
        .alert-error {
            background: #fff5f5;
            color: #c53030;
            border: 1px solid #feb2b2;
        }
        
        .alert-success {
            background: #f0fff4;
            color: #22543d;
            border: 1px solid #9ae6b4;
        }
        
        .alert-warning {
            background: #fffbeb;
            color: #b7791f;
            border: 1px solid #fbd38d;
        }
        
        .alert-critical {
            background: #fff5f5;
            color: #c53030;
            border: 2px solid #e53e3e;
            font-weight: bold;
        }
        
        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 40px;
            color: #718096;
        }
        
        /* Setup & Login Pages */
        .setup-page,
        .login-page {
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }
        
        .setup-container,
        .login-container {
            width: 100%;
            max-width: 500px;
            padding: 20px;
        }
        
        .setup-card,
        .login-card {
            background: white;
            border-radius: 12px;
            padding: 40px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
        }
        
        .setup-card h1,
        .login-card h1 {
            font-size: 32px;
            margin-bottom: 10px;
        }
        
        .subtitle {
            color: #718096;
            margin-bottom: 30px;
        }
        
        /* Results */
        .results-list {
            display: flex;
            flex-direction: column;
            gap: 20px;
        }
        
        .result-item {
            padding: 20px;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            transition: all 0.3s;
        }
        
        .result-item:hover {
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }
        
        .result-item h3 {
            font-size: 18px;
            margin-bottom: 10px;
        }
        
        .result-item h3 a {
            color: #3182ce;
            text-decoration: none;
        }
        
        .result-item h3 a:hover {
            text-decoration: underline;
        }
        
        .result-snippet {
            color: #4a5568;
            margin-bottom: 8px;
        }
        
        .result-url {
            font-size: 13px;
            color: #22863a;
        }
        
        /* Job Info */
        .job-info {
            display: flex;
            gap: 20px;
            align-items: center;
            margin: 20px 0;
        }
        
        /* Action Bar */
        .action-bar {
            display: flex;
            gap: 10px;
            margin-top: 20px;
        }
        
        /* Code Block */
        .code-block {
            background: #2d3748;
            color: #e2e8f0;
            padding: 15px;
            border-radius: 6px;
            font-family: 'Courier New', monospace;
            overflow-x: auto;
        }
        
        /* Worker Status Indicator */
        .worker-status-indicator {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .status-dot {
            width: 12px;
            height: 12px;
            border-radius: 50%;
            background: #48bb78;
            animation: pulse 2s infinite;
        }
        
        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.5; }
        }
        
        /* Metrics Grid */
        .metrics-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
        }
        
        .metric-item {
            padding: 15px;
            background: #f7fafc;
            border-radius: 6px;
        }
        
        .metric-label {
            font-size: 13px;
            color: #718096;
            margin-bottom: 5px;
        }
        
        .metric-value {
            font-size: 24px;
            font-weight: 700;
            color: #2d3748;
        }
        
        .worker-detail {
            margin-top: 10px;
            padding: 10px;
            background: #f7fafc;
            border-radius: 4px;
            font-size: 13px;
            color: #4a5568;
        }
        
        /* Responsive */
        @media (max-width: 768px) {
            .sidebar {
                width: 0;
                overflow: hidden;
            }
            
            .main-content {
                margin-left: 0;
            }
            
            .stats-grid {
                grid-template-columns: 1fr;
            }
        }
        <?php
    }
}

// ============================================================================
// APPLICATION ENTRY POINT
// ============================================================================

Router::handleRequest();
