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
    public static function add(string $email): void {
        $hash = self::normalize($email);
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
        $db = Database::connect();
        
        $stmt = $db->prepare("SELECT COUNT(*) as count FROM bloomfilter WHERE hash = ?");
        $stmt->execute([$hash]);
        $result = $stmt->fetch();
        
        return $result['count'] > 0;
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
// JOB CLASS
// ============================================================================

class Job {
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
}

// ============================================================================
// WORKER CLASS
// ============================================================================

class Worker {
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
    
    public static function updateHeartbeat(int $workerId, string $status, ?int $jobId = null): void {
        $db = Database::connect();
        $stmt = $db->prepare("UPDATE workers SET status = ?, current_job_id = ?, last_heartbeat = NOW() WHERE id = ?");
        $stmt->execute([$status, $jobId, $workerId]);
    }
    
    public static function getNextJob(): ?array {
        $db = Database::connect();
        
        // Use transaction to prevent race conditions
        $db->beginTransaction();
        
        try {
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
            return null;
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
    
    public static function processJob(int $jobId): void {
        $job = Job::getById($jobId);
        if (!$job) {
            return;
        }
        
        echo "Processing job #{$jobId}: {$job['query']}\n";
        
        $apiKey = $job['api_key'];
        $query = $job['query'];
        $maxResults = (int)$job['max_results'];
        $country = $job['country'];
        $emailFilter = $job['email_filter'];
        
        $processed = 0;
        $page = 1;
        
        while ($processed < $maxResults) {
            $data = self::searchSerper($apiKey, $query, $page, $country);
            
            if (!$data || !isset($data['organic'])) {
                break;
            }
            
            foreach ($data['organic'] as $result) {
                if ($processed >= $maxResults) {
                    break;
                }
                
                self::processResultWithDeepScraping($result, $jobId, $country, $emailFilter, $processed, $maxResults);
                
                $progress = (int)(($processed / $maxResults) * 100);
                Job::updateStatus($jobId, 'running', $progress);
                
                if ($processed > 0 && $processed % 10 === 0) {
                    echo "  - Progress: {$processed}/{$maxResults} emails\n";
                }
            }
            
            if (!isset($data['organic']) || count($data['organic']) === 0) {
                break;
            }
            
            $page++;
            
            // Rate limiting: use configurable setting or default 0.5 seconds
            $rateLimit = (float)(Settings::get('rate_limit', '0.5'));
            usleep((int)($rateLimit * 1000000));
        }
        
        Job::updateStatus($jobId, 'completed', 100);
        echo "Job #{$jobId} completed! Total emails: {$processed}\n";
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
        curl_close($ch);
        
        if ($httpCode === 200 && $response) {
            return json_decode($response, true);
        }
        
        return null;
    }
    
    public static function processJobImmediately(int $jobId, int $startOffset = 0, int $maxResults = 100): void {
        $job = Job::getById($jobId);
        if (!$job) {
            return;
        }
        
        $apiKey = $job['api_key'];
        $query = $job['query'];
        $country = $job['country'];
        $emailFilter = $job['email_filter'];
        
        $processed = 0;
        $page = (int)($startOffset / 10) + 1;
        
        while ($processed < $maxResults) {
            $data = self::searchSerper($apiKey, $query, $page, $country);
            
            if (!$data || !isset($data['organic'])) {
                break;
            }
            
            foreach ($data['organic'] as $result) {
                if ($processed >= $maxResults) {
                    break;
                }
                
                self::processResultWithDeepScraping($result, $jobId, $country, $emailFilter, $processed, $maxResults);
            }
            
            if (!isset($data['organic']) || count($data['organic']) === 0) {
                break;
            }
            
            $page++;
            
            // Rate limiting: use configurable setting or default 0.5 seconds
            $rateLimit = (float)(Settings::get('rate_limit', '0.5'));
            usleep((int)($rateLimit * 1000000));
        }
        
        // Update progress
        $progress = (int)((($startOffset + $processed) / $job['max_results']) * 100);
        
        // If this worker has completed its portion and progress is 100%, mark job as completed
        if ($progress >= 100) {
            Job::updateStatus($jobId, 'completed', 100);
        } else {
            Job::updateStatus($jobId, 'running', min($progress, 100));
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
            Worker::updateHeartbeat($workerId, 'idle');
            
            $job = Worker::getNextJob();
            
            if ($job) {
                Worker::updateHeartbeat($workerId, 'running', $job['id']);
                Worker::processJob($job['id']);
                Worker::updateHeartbeat($workerId, 'idle');
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
                
            case 'jobs':
                echo json_encode(Job::getAll(Auth::getUserId()));
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
        
        self::renderLayout('Dashboard', function() use ($jobs) {
            ?>
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
            </script>
            <?php
        });
    }
    
    private static function spawnParallelWorkers(int $jobId, int $workerCount): void {
        $job = Job::getById($jobId);
        if (!$job) {
            return;
        }
        
        $maxResults = (int)$job['max_results'];
        $resultsPerWorker = (int)ceil($maxResults / $workerCount);
        
        // Update job to running status immediately
        Job::updateStatus($jobId, 'running', 0);
        
        // Check if exec() is available
        $execAvailable = function_exists('exec') && !in_array('exec', array_map('trim', explode(',', ini_get('disable_functions'))));
        
        if ($execAvailable) {
            // Method 1: Spawn background PHP processes (preferred)
            self::spawnWorkersViaExec($jobId, $workerCount, $resultsPerWorker, $maxResults);
        } else {
            // Method 2: Use async HTTP requests (fallback for restricted hosting)
            self::spawnWorkersViaHttp($jobId, $workerCount, $resultsPerWorker, $maxResults);
        }
    }
    
    private static function spawnWorkersViaExec(int $jobId, int $workerCount, int $resultsPerWorker, int $maxResults): void {
        $phpBinary = PHP_BINARY;
        $scriptPath = __FILE__;
        
        for ($i = 0; $i < $workerCount; $i++) {
            $startOffset = $i * $resultsPerWorker;
            $workerMaxResults = min($resultsPerWorker, $maxResults - $startOffset);
            
            if ($workerMaxResults > 0) {
                // Build command to run worker
                $cmd = sprintf(
                    '%s %s process-job %d %d %d > /dev/null 2>&1 &',
                    escapeshellarg($phpBinary),
                    escapeshellarg($scriptPath),
                    $jobId,
                    $startOffset,
                    $workerMaxResults
                );
                
                // Execute in background
                if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
                    // Windows
                    pclose(popen("start /B " . $cmd, "r"));
                } else {
                    // Unix/Linux
                    exec($cmd);
                }
            }
        }
    }
    
    private static function spawnWorkersViaHttp(int $jobId, int $workerCount, int $resultsPerWorker, int $maxResults): void {
        // Get the current URL
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $scriptName = $_SERVER['SCRIPT_NAME'] ?? '/app.php';
        $baseUrl = $protocol . '://' . $host . $scriptName;
        
        error_log("Spawning {$workerCount} workers via HTTP for job {$jobId}");
        
        for ($i = 0; $i < $workerCount; $i++) {
            $startOffset = $i * $resultsPerWorker;
            $workerMaxResults = min($resultsPerWorker, $maxResults - $startOffset);
            
            if ($workerMaxResults > 0) {
                // Create async HTTP request to trigger worker
                $ch = curl_init($baseUrl . '?page=process-worker');
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
                    'job_id' => $jobId,
                    'start_offset' => $startOffset,
                    'max_results' => $workerMaxResults,
                    'worker_index' => $i,
                    'worker_token' => md5($jobId . $startOffset . 'secret')
                ]));
                curl_setopt($ch, CURLOPT_TIMEOUT, 5); // Give it 5 seconds to establish connection
                curl_setopt($ch, CURLOPT_NOSIGNAL, 1);
                
                // Execute async (don't wait for response)
                $result = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                
                if ($httpCode !== 200) {
                    error_log("Failed to spawn worker #{$i} for job {$jobId}, HTTP code: {$httpCode}");
                } else {
                    error_log("Successfully spawned worker #{$i} for job {$jobId}");
                }
                
                curl_close($ch);
                
                // Small delay between spawning workers to avoid overwhelming the server
                usleep(50000); // 0.05 seconds
            }
        }
        
        error_log("All {$workerCount} workers spawned for job {$jobId}");
    }
    
    private static function renderNewJob(): void {
        $success = false;
        $jobId = 0;
        $error = null;
        
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
                    
                    // Spawn parallel workers in background immediately
                    self::spawnParallelWorkers($jobId, $workerCount);
                    
                    $success = true;
                } else {
                    $error = 'Query and API Key are required';
                }
            } catch (Exception $e) {
                $error = 'Error creating job: ' . $e->getMessage();
                error_log('Job creation error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
            }
        }
        
        self::renderLayout('New Job', function() use ($success, $jobId, $error) {
            ?>
            <?php if ($error): ?>
                <div class="alert alert-error">
                    ✗ <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>
            
            <?php if ($success): ?>
                <div class="alert alert-success">
                    ✓ Job #<?php echo $jobId; ?> created and processing started immediately! <?php echo $_POST['worker_count'] ?? 5; ?> parallel workers are running in the background.
                    <a href="?page=results&job_id=<?php echo $jobId; ?>">View Job</a> | 
                    <a href="?page=dashboard">Go to Dashboard</a>
                </div>
            <?php endif; ?>
            
            <div class="card">
                <h2>Create New Email Extraction Job</h2>
                <p style="margin-bottom: 20px; color: #4a5568;">
                    ⚡ Jobs start processing immediately with parallel workers! No need to start CLI workers.
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
                        <small>Number of parallel workers to spawn (1-1000). More workers = faster processing</small>
                    </div>
                    
                    <button type="submit" class="btn btn-primary">Start Processing Immediately</button>
                    <a href="?page=dashboard" class="btn">Cancel</a>
                </form>
            </div>
            
            <div class="card">
                <h2>ℹ️ How It Works</h2>
                <ol style="padding-left: 20px;">
                    <li>Create a job with your search query and preferences</li>
                    <li>Parallel workers start immediately in the background</li>
                    <li>Emails are extracted from search results using regex patterns</li>
                    <li>Duplicates are automatically filtered out</li>
                    <li>Results appear in real-time on the dashboard</li>
                </ol>
                <p style="margin-top: 15px;">
                    <strong>Auto-Processing:</strong> Workers are spawned automatically when you click "Start Processing Immediately". No manual CLI worker setup needed! The UI returns instantly while workers process in the background.
                </p>
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
                        <input type="number" step="0.1" name="setting_rate_limit" value="<?php echo htmlspecialchars($settings['rate_limit'] ?? '0.5'); ?>">
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
            <div class="card">
                <h2>Active Workers</h2>
                <div id="workers-list">Loading...</div>
            </div>
            
            <div class="card">
                <h2>Start New Worker</h2>
                <p>To start a worker, run this command in your terminal:</p>
                <pre class="code-block">php <?php echo __FILE__; ?> worker-name</pre>
                <p>Example:</p>
                <pre class="code-block">php <?php echo __FILE__; ?> worker-1</pre>
            </div>
            
            <script>
                function updateWorkers() {
                    fetch('?page=api&action=workers')
                        .then(res => res.json())
                        .then(workers => {
                            const container = document.getElementById('workers-list');
                            
                            if (workers.length === 0) {
                                container.innerHTML = '<p class="empty-state">No workers running</p>';
                                return;
                            }
                            
                            let html = '<table class="data-table"><thead><tr><th>Worker</th><th>Status</th><th>Current Job</th><th>Last Heartbeat</th></tr></thead><tbody>';
                            
                            workers.forEach(worker => {
                                const lastHeartbeat = worker.last_heartbeat ? new Date(worker.last_heartbeat).toLocaleString() : 'Never';
                                const jobInfo = worker.current_job_id ? '#' + worker.current_job_id : '-';
                                
                                html += `<tr>
                                    <td>${worker.worker_name}</td>
                                    <td><span class="status-badge status-${worker.status}">${worker.status}</span></td>
                                    <td>${jobInfo}</td>
                                    <td>${lastHeartbeat}</td>
                                </tr>`;
                            });
                            
                            html += '</tbody></table>';
                            container.innerHTML = html;
                        });
                }
                
                updateWorkers();
                setInterval(updateWorkers, 3000);
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
                // Auto-refresh results if job is still running
                <?php if ($job['status'] === 'running' || $job['status'] === 'pending'): ?>
                setInterval(function() {
                    location.reload();
                }, 5000);
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
