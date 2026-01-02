<?php
/**
 * Professional Multi-Job Email Extraction System
 * 
 * Single-file PHP application for real-time email extraction using Serper Google Search API
 * Designed for cPanel environments with 24/7 continuous operation
 * 
 * Features:
 * - Multi-job concurrent execution with state separation
 * - Worker-based architecture using proc_open
 * - Domain-level throttling and adaptive scaling
 * - Multi-layered email validation (MX, content, confidence scoring)
 * - RAM-based deduplication and buffering
 * - SendGrid-styled UI with real-time monitoring
 * - Zombie worker detection and memory leak tracking
 * 
 * @author Email Extraction System
 * @version 1.0.0
 */

// Prevent direct execution in production without proper setup
error_reporting(0); // Disable in production
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('max_execution_time', 0);
ini_set('memory_limit', '512M');

// Session management
session_start();

// Load database configuration if it exists
$configFile = __DIR__ . '/config.php';
if (file_exists($configFile)) {
    require_once $configFile;
}

// Configuration
class Config {
    const VERSION = '1.0.0';
    const MAX_WORKERS_PER_JOB = 200; // Capped at 200 for optimal performance
    const MIN_WORKERS_PER_JOB = 1;
    const WORKER_SPAWN_BATCH_SIZE = 20; // Spawn workers in batches of 20
    const WORKER_SPAWN_BATCH_DELAY = 1; // Wait 1 second between batches
    const WORKER_TIMEOUT = 300; // 5 minutes
    const ZOMBIE_CHECK_INTERVAL = 60; // 1 minute
    const MEMORY_LIMIT_MB = 450; // Alert at 450MB
    const DOMAIN_THROTTLE_SECONDS = 2;
    const HTTP_429_BACKOFF_SECONDS = 60;
    const MAX_RETRIES = 3;
    const CONFIDENCE_THRESHOLD = 0.6;
    const DATA_DIR = '/tmp/email_extraction';
    const LOG_DIR = '/tmp/email_extraction/logs';
    const DB_RETRY_ATTEMPTS = 3;
    const DB_RETRY_DELAY = 2; // seconds
    const DEFAULT_MAX_PAGES = 1000; // Default max pages per query (increased for maximum coverage)
    const DEFAULT_WORKER_RUNTIME = 28800; // Default 8 hours (increased for continuous operation)
    const CHART_TIME_WINDOW_SECONDS = 600; // 10 minutes for email rate chart
}

// Database Connection Manager
class Database {
    private static $instance = null;
    private $pdo = null;
    private $isConfigured = false;
    
    private function __construct() {
        $this->isConfigured = defined('DB_HOST') && defined('DB_NAME') && defined('DB_USER');
        if ($this->isConfigured) {
            $this->connect();
        }
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
    
    private function connect() {
        if ($this->pdo !== null) {
            return;
        }
        
        $attempts = 0;
        $lastError = null;
        
        while ($attempts < Config::DB_RETRY_ATTEMPTS) {
            try {
                $dsn = sprintf(
                    'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
                    DB_HOST,
                    defined('DB_PORT') ? DB_PORT : '3306',
                    DB_NAME
                );
                
                $options = [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                    PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
                ];
                
                $this->pdo = new PDO($dsn, DB_USER, defined('DB_PASS') ? DB_PASS : '', $options);
                Utils::logMessage('INFO', 'Database connected successfully');
                return;
                
            } catch (PDOException $e) {
                $lastError = $e->getMessage();
                $attempts++;
                Utils::logMessage('WARNING', "Database connection attempt {$attempts} failed: {$lastError}");
                
                if ($attempts < Config::DB_RETRY_ATTEMPTS) {
                    sleep(Config::DB_RETRY_DELAY);
                }
            }
        }
        
        Utils::logMessage('ERROR', "Failed to connect to database after {$attempts} attempts: {$lastError}");
        throw new Exception("Database connection failed: {$lastError}");
    }
    
    public function getConnection() {
        if (!$this->isConfigured) {
            throw new Exception('Database not configured. Please run install.php first.');
        }
        
        // Ensure connection is alive
        if ($this->pdo === null) {
            $this->connect();
        }
        
        return $this->pdo;
    }
    
    public function query($sql, $params = []) {
        $attempts = 0;
        $lastError = null;
        
        while ($attempts < Config::DB_RETRY_ATTEMPTS) {
            try {
                $pdo = $this->getConnection();
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                return $stmt;
                
            } catch (PDOException $e) {
                $lastError = $e->getMessage();
                $attempts++;
                Utils::logMessage('WARNING', "Query attempt {$attempts} failed: {$lastError}");
                
                // Reset connection on failure
                $this->pdo = null;
                
                if ($attempts < Config::DB_RETRY_ATTEMPTS) {
                    sleep(Config::DB_RETRY_DELAY);
                }
            }
        }
        
        Utils::logMessage('ERROR', "Query failed after {$attempts} attempts: {$lastError}");
        throw new Exception("Database query failed: {$lastError}");
    }
    
    public function execute($sql, $params = []) {
        $stmt = $this->query($sql, $params);
        return $stmt->rowCount();
    }
    
    public function lastInsertId() {
        return $this->getConnection()->lastInsertId();
    }
}

// Utility Functions
class Utils {
    public static function generateId($prefix = '') {
        return $prefix . uniqid() . '_' . bin2hex(random_bytes(8));
    }
    
    public static function logMessage($level, $message, $context = []) {
        $timestamp = date('Y-m-d H:i:s');
        $contextStr = !empty($context) ? ' ' . json_encode($context) : '';
        $logEntry = "[{$timestamp}] [{$level}] {$message}{$contextStr}\n";
        
        @mkdir(Config::LOG_DIR, 0755, true);
        $logFile = Config::LOG_DIR . '/app_' . date('Y-m-d') . '.log';
        @file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
    }
    
    public static function formatBytes($bytes) {
        $units = ['B', 'KB', 'MB', 'GB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= (1 << (10 * $pow));
        return round($bytes, 2) . ' ' . $units[$pow];
    }
}

// Email Validator - Strict validation with MX verification
class EmailValidator {
    private static $disposableDomains = [
        'tempmail.com', 'throwaway.email', '10minutemail.com', 'guerrillamail.com',
        'mailinator.com', 'maildrop.cc', 'trashmail.com', 'yopmail.com'
    ];
    
    // File extensions to reject
    private static $fileExtensions = [
        '.png', '.jpg', '.jpeg', '.gif', '.bmp', '.svg', '.webp', '.ico',
        '.pdf', '.doc', '.docx', '.xls', '.xlsx', '.ppt', '.pptx',
        '.zip', '.rar', '.tar', '.gz', '.7z',
        '.mp3', '.mp4', '.avi', '.mov', '.wmv',
        '.exe', '.dll', '.bin'
    ];
    
    // Banks and major corporations to filter
    private static $corporateDomains = [
        'google.com', 'amazon.com', 'paypal.com', 'ebay.com', 'apple.com',
        'microsoft.com', 'facebook.com', 'twitter.com', 'instagram.com',
        'bankofamerica.com', 'chase.com', 'wellsfargo.com', 'citibank.com',
        'hsbc.com', 'barclays.com', 'jpmorgan.com'
    ];
    
    // System/automatic email patterns
    private static $systemEmailPatterns = [
        'noreply', 'no-reply', 'no_reply', 'donotreply', 'do-not-reply',
        'admin', 'administrator', 'support', 'info', 'notification',
        'alerts', 'notifications', 'automated', 'automatic', 'system',
        'webmaster', 'postmaster', 'mailer-daemon'
    ];
    
    public static function validate($email, $checkStrict = true) {
        // Basic format validation
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['valid' => false, 'reason' => 'Invalid email format'];
        }
        
        // Extract domain and local part
        $parts = explode('@', $email);
        if (count($parts) !== 2) {
            return ['valid' => false, 'reason' => 'Invalid email structure'];
        }
        
        $localPart = strtolower($parts[0]);
        $domain = strtolower($parts[1]);
        
        if ($checkStrict) {
            // Check for file extensions in email
            foreach (self::$fileExtensions as $ext) {
                if (strpos($email, $ext) !== false) {
                    return ['valid' => false, 'reason' => 'Contains file extension'];
                }
            }
            
            // Check against corporate/bank domains
            if (in_array($domain, self::$corporateDomains)) {
                return ['valid' => false, 'reason' => 'Corporate/bank domain'];
            }
            
            // Check against system email patterns
            foreach (self::$systemEmailPatterns as $pattern) {
                if (stripos($localPart, $pattern) !== false) {
                    return ['valid' => false, 'reason' => 'System/automated email'];
                }
            }
            
            // Check email length (too short or too long)
            if (strlen($localPart) < 3 || strlen($localPart) > 64) {
                return ['valid' => false, 'reason' => 'Invalid email length'];
            }
            
            // Check if looks human (at least some variety in characters)
            if (!preg_match('/[a-z]/i', $localPart) || !preg_match('/[a-z].*[a-z]/i', $localPart)) {
                return ['valid' => false, 'reason' => 'Does not look human'];
            }
        }
        
        // Check against disposable domains
        if (in_array($domain, self::$disposableDomains)) {
            return ['valid' => false, 'reason' => 'Disposable email domain'];
        }
        
        // Validate domain format
        if (!self::isValidDomain($domain)) {
            return ['valid' => false, 'reason' => 'Invalid domain format'];
        }
        
        return ['valid' => true, 'reason' => 'Valid'];
    }
    
    public static function validateWithMX($email) {
        $validation = self::validate($email);
        if (!$validation['valid']) {
            return $validation;
        }
        
        $parts = explode('@', $email);
        $domain = $parts[1];
        
        // MX record check
        $hasMX = checkdnsrr($domain, 'MX') || checkdnsrr($domain, 'A');
        if (!$hasMX) {
            return ['valid' => false, 'reason' => 'No MX records found'];
        }
        
        return ['valid' => true, 'reason' => 'Valid with MX'];
    }
    
    private static function isValidDomain($domain) {
        // Basic domain validation
        return preg_match('/^[a-z0-9]+([\-\.]{1}[a-z0-9]+)*\.[a-z]{2,}$/i', $domain);
    }
    
    public static function getQualityLabel($score) {
        if ($score >= 0.8) return 'High';
        if ($score >= 0.5) return 'Medium';
        return 'Low';
    }
}

// Confidence Scorer - Evaluates email quality
class ConfidenceScorer {
    public static function score($email, $context = []) {
        $score = 0.5; // Base score
        
        // Domain reputation
        $domain = explode('@', $email)[1] ?? '';
        if (self::isKnownDomain($domain)) {
            $score += 0.2;
        }
        
        // Context-based scoring
        if (!empty($context['found_in_contact_page'])) {
            $score += 0.15;
        }
        
        if (!empty($context['has_mailto_link'])) {
            $score += 0.1;
        }
        
        if (!empty($context['in_structured_data'])) {
            $score += 0.15;
        }
        
        // Penalize generic emails
        $localPart = explode('@', $email)[0] ?? '';
        $genericKeywords = ['noreply', 'no-reply', 'info', 'admin', 'support'];
        foreach ($genericKeywords as $keyword) {
            if (stripos($localPart, $keyword) !== false) {
                $score -= 0.1;
                break;
            }
        }
        
        return max(0, min(1, $score));
    }
    
    private static function isKnownDomain($domain) {
        $knownDomains = ['gmail.com', 'yahoo.com', 'outlook.com', 'hotmail.com'];
        return in_array(strtolower($domain), $knownDomains);
    }
}

// Deduplication Engine - RAM-based
class DedupEngine {
    private $seen = [];
    private $maxSize = 100000; // Max 100k entries in RAM
    
    public function add($email) {
        $key = strtolower($email);
        if (isset($this->seen[$key])) {
            return false; // Duplicate
        }
        
        // Prevent memory overflow
        if (count($this->seen) >= $this->maxSize) {
            $this->seen = array_slice($this->seen, $this->maxSize / 2, null, true);
        }
        
        $this->seen[$key] = time();
        return true;
    }
    
    public function isDuplicate($email) {
        $key = strtolower($email);
        return isset($this->seen[$key]);
    }
    
    public function getCount() {
        return count($this->seen);
    }
    
    public function clear() {
        $this->seen = [];
    }
}

// Buffer Manager - RAM-based buffering
class BufferManager {
    private $buffer = [];
    private $maxSize = 1000;
    
    public function add($item) {
        $this->buffer[] = $item;
        
        if (count($this->buffer) >= $this->maxSize) {
            return $this->flush();
        }
        
        return [];
    }
    
    public function flush() {
        $items = $this->buffer;
        $this->buffer = [];
        return $items;
    }
    
    public function getCount() {
        return count($this->buffer);
    }
}

// Domain Limiter - Throttling mechanism
class DomainLimiter {
    private $lastAccess = [];
    private $backoffUntil = [];
    
    public function canAccess($domain) {
        $now = time();
        
        // Check if in backoff period
        if (isset($this->backoffUntil[$domain]) && $this->backoffUntil[$domain] > $now) {
            return false;
        }
        
        // Check throttle
        if (isset($this->lastAccess[$domain])) {
            $elapsed = $now - $this->lastAccess[$domain];
            if ($elapsed < Config::DOMAIN_THROTTLE_SECONDS) {
                return false;
            }
        }
        
        return true;
    }
    
    public function recordAccess($domain) {
        $this->lastAccess[$domain] = time();
    }
    
    public function triggerBackoff($domain, $duration = null) {
        $duration = $duration ?? Config::HTTP_429_BACKOFF_SECONDS;
        $this->backoffUntil[$domain] = time() + $duration;
        Utils::logMessage('WARNING', "Domain backoff triggered: {$domain} for {$duration}s");
    }
    
    public function getStats() {
        return [
            'tracked_domains' => count($this->lastAccess),
            'backoff_count' => count($this->backoffUntil)
        ];
    }
}

// URL Filter - Validates and filters URLs
class URLFilter {
    // Block media files, documents, and resources
    private static $blockedExtensions = [
        '/\.(pdf|doc|docx|xls|xlsx|ppt|pptx)$/i',  // Documents
        '/\.(jpg|jpeg|png|gif|bmp|svg|webp|ico)$/i', // Images
        '/\.(mp3|mp4|avi|mov|wmv|flv|webm)$/i',     // Media
        '/\.(zip|rar|tar|gz|7z)$/i',                 // Archives
        '/\.(css|js|json|xml)$/i',                   // Resources
    ];
    
    // Block specific page types
    private static $blockedPatterns = [
        '/\/(login|signin|signup|register|logout)/',       // Auth pages
        '/\/(cart|checkout|payment|billing)/',             // Commerce pages
        '/\/(ad|ads|advertise|advertisement)/',            // Ad pages
        '/\/(shop|store|product|item|buy)[\/-]/i',         // Product pages
        '/^https?:\/\/[^\/]+\/?$/i',                       // Homepage only (root with no path)
    ];
    
    // Patterns that indicate good pages (forums, text content, documents)
    private static $goodPatterns = [
        '/\/(forum|discussion|thread|topic|post)/',
        '/\/(article|blog|news|press)/',
        '/\/(about|contact|team|staff)/',
        '/\/(directory|list|member)/',
    ];
    
    public static function isValid($url) {
        // Basic URL validation
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return ['valid' => false, 'reason' => 'Invalid URL format'];
        }
        
        // Check blocked extensions
        foreach (self::$blockedExtensions as $pattern) {
            if (preg_match($pattern, $url)) {
                return ['valid' => false, 'reason' => 'Media/document file'];
            }
        }
        
        // Check blocked patterns
        foreach (self::$blockedPatterns as $pattern) {
            if (preg_match($pattern, $url)) {
                return ['valid' => false, 'reason' => 'Blocked page type'];
            }
        }
        
        // Bonus: Check for good patterns (increase confidence)
        $hasGoodPattern = false;
        foreach (self::$goodPatterns as $pattern) {
            if (preg_match($pattern, $url)) {
                $hasGoodPattern = true;
                break;
            }
        }
        
        return ['valid' => true, 'reason' => 'Valid URL', 'has_good_pattern' => $hasGoodPattern];
    }
    
    public static function normalize($url) {
        $parsed = parse_url($url);
        $scheme = isset($parsed['scheme']) ? $parsed['scheme'] : 'http';
        $host = isset($parsed['host']) ? $parsed['host'] : '';
        $path = isset($parsed['path']) ? $parsed['path'] : '/';
        
        return $scheme . '://' . $host . $path;
    }
    
    public static function extractDomain($url) {
        $parsed = parse_url($url);
        return isset($parsed['host']) ? $parsed['host'] : '';
    }
}

// Content Filter - Filters extracted content
class ContentFilter {
    public static function extractEmails($content) {
        $pattern = '/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}/';
        preg_match_all($pattern, $content, $matches);
        
        $emails = [];
        foreach ($matches[0] as $email) {
            // Clean up the email
            $email = trim($email, '.,;:()[]{}"\' ');
            $validation = EmailValidator::validate($email, true);
            if ($validation['valid']) {
                $emails[] = $email;
            }
        }
        
        return array_unique($emails);
    }
    
    public static function analyzeContext($content, $email) {
        $context = [
            'found_in_contact_page' => stripos($content, 'contact') !== false,
            'has_mailto_link' => stripos($content, 'mailto:' . $email) !== false,
            'in_structured_data' => false
        ];
        
        // Check for structured data (JSON-LD, microdata)
        if (preg_match('/"email":\s*"' . preg_quote($email, '/') . '"/', $content)) {
            $context['in_structured_data'] = true;
        }
        
        return $context;
    }
}

// Search Scheduler - Serper API integration
class SearchScheduler {
    private $apiKey;
    private $baseUrl = 'https://google.serper.dev/search';
    private $rateLimiter;
    
    public function __construct($apiKey) {
        $this->apiKey = $apiKey;
        $this->rateLimiter = new DomainLimiter();
    }
    
    public function search($query, $options = []) {
        $params = [
            'q' => $query,
            'num' => $options['num'] ?? 10,
            'gl' => $options['gl'] ?? 'us',
            'hl' => $options['hl'] ?? 'en'
        ];
        
        $ch = curl_init($this->baseUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($params),
            CURLOPT_HTTPHEADER => [
                'X-API-KEY: ' . $this->apiKey,
                'Content-Type: application/json'
            ],
            CURLOPT_TIMEOUT => 30
        ]);
        
        $response = curl_exec($ch);
        $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            throw new Exception("Curl error: {$error}");
        }
        
        if ($statusCode === 429) {
            throw new Exception("Rate limit exceeded", 429);
        }
        
        if ($statusCode !== 200) {
            throw new Exception("API error: HTTP {$statusCode}");
        }
        
        $data = json_decode($response, true);
        if (!$data) {
            throw new Exception("Invalid JSON response");
        }
        
        return $this->parseResults($data);
    }
    
    private function parseResults($data) {
        $results = [];
        
        if (isset($data['organic'])) {
            foreach ($data['organic'] as $item) {
                $results[] = [
                    'title' => $item['title'] ?? '',
                    'url' => $item['link'] ?? '',
                    'snippet' => $item['snippet'] ?? '',
                    'position' => $item['position'] ?? 0
                ];
            }
        }
        
        return $results;
    }
}

// Worker Governor - Manages worker lifecycle
class WorkerGovernor {
    private $workers = [];
    private $jobId;
    private $maxWorkers;
    private $minWorkers;
    
    public function __construct($jobId, $maxWorkers = null, $minWorkers = null) {
        $this->jobId = $jobId;
        $this->maxWorkers = $maxWorkers ?? Config::MAX_WORKERS_PER_JOB;
        $this->minWorkers = $minWorkers ?? Config::MIN_WORKERS_PER_JOB;
    }
    
    public function spawnWorker($workerId, $config) {
        $workerScript = $this->generateWorkerScript($workerId, $config);
        
        $descriptorspec = [
            0 => ["pipe", "r"],  // stdin
            1 => ["pipe", "w"],  // stdout
            2 => ["pipe", "w"]   // stderr
        ];
        
        $process = proc_open(
            'php',
            $descriptorspec,
            $pipes,
            null,
            null,
            ['bypass_shell' => true]
        );
        
        if (!is_resource($process)) {
            throw new Exception("Failed to spawn worker {$workerId}");
        }
        
        // Send the worker script to stdin
        fwrite($pipes[0], $workerScript);
        fclose($pipes[0]);
        
        // Set non-blocking mode for stdout and stderr
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        
        $this->workers[$workerId] = [
            'id' => $workerId,
            'process' => $process,
            'pipes' => $pipes,
            'started_at' => time(),
            'last_heartbeat' => time(),
            'status' => 'running',
            'config' => $config
        ];
        
        Utils::logMessage('INFO', "Worker spawned: {$workerId} for job {$this->jobId}");
        
        return $workerId;
    }
    
    private function generateWorkerScript($workerId, $config) {
        $configJson = base64_encode(json_encode($config));
        $workerIdSafe = preg_replace('/[^a-zA-Z0-9_-]/', '', $workerId);
        $dataDir = Config::DATA_DIR;
        
        // Get database configuration
        $dbHost = defined('DB_HOST') ? DB_HOST : '';
        $dbPort = defined('DB_PORT') ? DB_PORT : '3306';
        $dbName = defined('DB_NAME') ? DB_NAME : '';
        $dbUser = defined('DB_USER') ? DB_USER : '';
        $dbPass = defined('DB_PASS') ? DB_PASS : '';
        
        return <<<PHP
<?php
// Worker Process - Real Serper API Integration with MySQL
\$config = json_decode(base64_decode('{$configJson}'), true);
\$workerId = '{$workerIdSafe}';
\$jobId = \$config['job_id'];
\$dataDir = '{$dataDir}';
\$apiKey = \$config['api_key'] ?? '';
\$query = \$config['query'] ?? '';
\$country = \$config['country'] ?? '';
\$language = \$config['language'] ?? 'en';
\$maxEmails = \$config['max_emails'] ?? 10000;
\$emailTypes = \$config['email_types'] ?? '';
\$customDomains = \$config['custom_domains'] ?? [];
\$keywords = \$config['keywords'] ?? [];
\$maxPages = \$config['max_pages'] ?? 200; // Configurable max pages per query
\$maxRunTime = \$config['max_run_time'] ?? 7200; // Configurable worker runtime

// Database configuration
\$dbHost = '{$dbHost}';
\$dbPort = '{$dbPort}';
\$dbName = '{$dbName}';
\$dbUser = '{$dbUser}';
\$dbPass = '{$dbPass}';

// Email storage file (fallback)
\$emailFile = \$dataDir . "/job_{\$jobId}_emails.json";

// Helper function to check if email matches selected types
function matchesEmailType(\$email, \$emailTypes, \$customDomains) {
    // If no types selected, accept all
    if (empty(\$emailTypes) && empty(\$customDomains)) {
        return true;
    }
    
    \$domain = strtolower(explode('@', \$email)[1] ?? '');
    
    // Check custom domains first
    if (!empty(\$customDomains)) {
        foreach (\$customDomains as \$customDomain) {
            if (strtolower(trim(\$customDomain)) === \$domain) {
                return true;
            }
        }
    }
    
    // Parse email types (comma-separated)
    \$types = array_filter(array_map('trim', explode(',', \$emailTypes)));
    
    if (empty(\$types)) {
        // Only custom domains specified, and we didn't match above
        return !empty(\$customDomains) ? false : true;
    }
    
    // Map email types to domain patterns
    \$domainMap = [
        'gmail' => ['gmail.com'],
        'yahoo' => ['yahoo.com'],
        'att' => ['att.net'],
        'sbcglobal' => ['sbcglobal.net'],
        'bellsouth' => ['bellsouth.net'],
        'aol' => ['aol.com'],
        'outlook' => ['outlook.com', 'hotmail.com', 'live.com'],
        'business' => [] // Special handling
    ];
    
    foreach (\$types as \$type) {
        if (\$type === 'business') {
            // Business emails are non-common domains
            \$commonDomains = ['gmail.com', 'yahoo.com', 'hotmail.com', 'outlook.com', 
                             'live.com', 'aol.com', 'att.net', 'sbcglobal.net', 'bellsouth.net'];
            if (!in_array(\$domain, \$commonDomains)) {
                return true;
            }
        } elseif (isset(\$domainMap[\$type])) {
            if (in_array(\$domain, \$domainMap[\$type])) {
                return true;
            }
        }
    }
    
    return false;
}

// Helper function to connect to database with retry
function getDBConnection(\$dbHost, \$dbPort, \$dbName, \$dbUser, \$dbPass, \$maxRetries = 3) {
    \$attempts = 0;
    while (\$attempts < \$maxRetries) {
        try {
            \$dsn = "mysql:host={\$dbHost};port={\$dbPort};dbname={\$dbName};charset=utf8mb4";
            \$pdo = new PDO(\$dsn, \$dbUser, \$dbPass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
            ]);
            return \$pdo;
        } catch (PDOException \$e) {
            \$attempts++;
            if (\$attempts < \$maxRetries) {
                sleep(2);
            }
        }
    }
    return null;
}

// Helper function to check if email already exists in database
function isEmailDuplicate(\$pdo, \$jobId, \$email) {
    try {
        \$stmt = \$pdo->prepare("SELECT COUNT(*) as count FROM emails WHERE job_id = :job_id AND email = :email");
        \$stmt->execute([':job_id' => \$jobId, ':email' => \$email]);
        \$result = \$stmt->fetch();
        return (int)\$result['count'] > 0;
    } catch (PDOException \$e) {
        return false;
    }
}

// Helper function to get current email count from database
function getCurrentEmailCount(\$pdo, \$jobId) {
    try {
        \$stmt = \$pdo->prepare("SELECT COUNT(*) as count FROM emails WHERE job_id = :job_id");
        \$stmt->execute([':job_id' => \$jobId]);
        \$result = \$stmt->fetch();
        return (int)\$result['count'];
    } catch (PDOException \$e) {
        return 0;
    }
}

// Helper function to save email to database
function saveEmailToDB(\$pdo, \$jobId, \$emailData) {
    try {
        \$sql = "INSERT INTO emails (job_id, email, quality, confidence, source_url, worker_id) 
                VALUES (:job_id, :email, :quality, :confidence, :source_url, :worker_id)
                ON DUPLICATE KEY UPDATE 
                    quality = VALUES(quality),
                    confidence = VALUES(confidence),
                    source_url = VALUES(source_url),
                    worker_id = VALUES(worker_id)";
        
        \$stmt = \$pdo->prepare(\$sql);
        \$stmt->execute([
            ':job_id' => \$jobId,
            ':email' => \$emailData['email'],
            ':quality' => \$emailData['quality'],
            ':confidence' => \$emailData['confidence'],
            ':source_url' => \$emailData['source_url'],
            ':worker_id' => \$emailData['worker_id']
        ]);
        return true;
    } catch (PDOException \$e) {
        error_log("Worker {\$emailData['worker_id']} DB error: " . \$e->getMessage());
        return false;
    }
}

// Helper function to save email to file (fallback)
function saveEmailToFile(\$emailFile, \$emailData) {
    if (!file_exists(dirname(\$emailFile))) {
        @mkdir(dirname(\$emailFile), 0755, true);
    }
    
    \$data = ['emails' => [], 'total' => 0, 'last_updated' => time()];
    if (file_exists(\$emailFile)) {
        \$existing = json_decode(file_get_contents(\$emailFile), true);
        if (\$existing) {
            \$data = \$existing;
        }
    }
    
    \$data['emails'][] = \$emailData;
    \$data['total'] = count(\$data['emails']);
    \$data['last_updated'] = time();
    
    file_put_contents(\$emailFile, json_encode(\$data), LOCK_EX);
}

// Initialize database connection
\$pdo = null;
if (!empty(\$dbHost) && !empty(\$dbName)) {
    \$pdo = getDBConnection(\$dbHost, \$dbPort, \$dbName, \$dbUser, \$dbPass);
}

// Serper API search function with pagination support
function searchSerper(\$apiKey, \$query, \$country, \$language, \$page = 1) {
    \$ch = curl_init('https://google.serper.dev/search');
    curl_setopt_array(\$ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode([
            'q' => \$query,
            'num' => 10,
            'page' => \$page,
            'gl' => \$country,
            'hl' => \$language
        ]),
        CURLOPT_HTTPHEADER => [
            'X-API-KEY: ' . \$apiKey,
            'Content-Type: application/json'
        ],
        CURLOPT_TIMEOUT => 30
    ]);
    
    \$response = curl_exec(\$ch);
    \$statusCode = curl_getinfo(\$ch, CURLINFO_HTTP_CODE);
    curl_close(\$ch);
    
    if (\$statusCode === 200 && \$response) {
        \$data = json_decode(\$response, true);
        if (isset(\$data['organic'])) {
            return \$data['organic'];
        }
    }
    
    return [];
}

// Email extraction functions
function extractEmailsFromContent(\$content) {
    \$pattern = '/[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\\.[a-zA-Z]{2,}/';
    preg_match_all(\$pattern, \$content, \$matches);
    
    \$emails = [];
    foreach (\$matches[0] as \$email) {
        \$email = trim(\$email, '.,;:()[]{}\"\\' ');
        if (filter_var(\$email, FILTER_VALIDATE_EMAIL)) {
            \$emails[] = \$email;
        }
    }
    
    return array_unique(\$emails);
}

function fetchUrlContent(\$url) {
    // Filter out non-HTML content (images, PDFs, etc.)
    \$blockedExtensions = ['.png', '.jpg', '.jpeg', '.gif', '.bmp', '.webp', '.svg', 
                           '.pdf', '.doc', '.docx', '.xls', '.xlsx', '.zip', '.rar',
                           '.mp4', '.avi', '.mov', '.mp3', '.wav', '.css', '.js', '.ico'];
    
    \$urlLower = strtolower(\$url);
    foreach (\$blockedExtensions as \$ext) {
        if (strpos(\$urlLower, \$ext) !== false) {
            return false; // Skip non-HTML resources
        }
    }
    
    \$ch = curl_init(\$url);
    curl_setopt_array(\$ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 2,
        CURLOPT_TIMEOUT => 5,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
        CURLOPT_HTTPHEADER => ['Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8']
    ]);
    
    \$content = curl_exec(\$ch);
    \$httpCode = curl_getinfo(\$ch, CURLINFO_HTTP_CODE);
    \$contentType = curl_getinfo(\$ch, CURLINFO_CONTENT_TYPE);
    curl_close(\$ch);
    
    // Only process HTML content
    if (\$httpCode >= 200 && \$httpCode < 300) {
        if (\$contentType && stripos(\$contentType, 'text/html') !== false) {
            return \$content;
        }
    }
    
    return false;
}

function getEmailQuality(\$email, \$content) {
    \$score = 0.5;
    
    // Check if found in contact page
    if (stripos(\$content, 'contact') !== false) {
        \$score += 0.15;
    }
    
    // Check for mailto link
    if (stripos(\$content, 'mailto:' . \$email) !== false) {
        \$score += 0.2;
    }
    
    // Check domain reputation
    \$domain = explode('@', \$email)[1] ?? '';
    \$knownDomains = ['gmail.com', 'yahoo.com', 'outlook.com', 'hotmail.com'];
    if (in_array(strtolower(\$domain), \$knownDomains)) {
        \$score += 0.15;
    }
    
    if (\$score >= 0.75) return 'high';
    if (\$score >= 0.55) return 'medium';
    return 'low';
}

// Batch save emails to database for better performance
function saveBatchToDB(\$pdo, \$jobId, \$emailBatch) {
    if (empty(\$emailBatch)) return 0;
    
    try {
        \$pdo->beginTransaction();
        \$sql = "INSERT IGNORE INTO emails (job_id, email, quality, confidence, source_url, worker_id) 
                VALUES (:job_id, :email, :quality, :confidence, :source_url, :worker_id)";
        \$stmt = \$pdo->prepare(\$sql);
        
        \$saved = 0;
        foreach (\$emailBatch as \$emailData) {
            \$stmt->execute([
                ':job_id' => \$jobId,
                ':email' => \$emailData['email'],
                ':quality' => \$emailData['quality'],
                ':confidence' => \$emailData['confidence'],
                ':source_url' => \$emailData['source_url'],
                ':worker_id' => \$emailData['worker_id']
            ]);
            \$saved += \$stmt->rowCount();
        }
        
        \$pdo->commit();
        return \$saved;
    } catch (PDOException \$e) {
        \$pdo->rollBack();
        error_log("Batch save error: " . \$e->getMessage());
        return 0;
    }
}

// Fast JSON batch save for when database is not available
function saveBatchToJSON(\$emailFile, \$emailBatch) {
    if (empty(\$emailBatch)) return 0;
    
    // Read existing data
    \$existingData = [];
    if (file_exists(\$emailFile)) {
        \$content = file_get_contents(\$emailFile);
        \$json = json_decode(\$content, true);
        if (\$json && isset(\$json['emails'])) {
            \$existingData = \$json['emails'];
        }
    }
    
    // Merge new emails (with deduplication)
    \$existingEmails = array_column(\$existingData, 'email');
    \$saved = 0;
    foreach (\$emailBatch as \$emailData) {
        if (!in_array(\$emailData['email'], \$existingEmails)) {
            \$existingData[] = \$emailData;
            \$existingEmails[] = \$emailData['email'];
            \$saved++;
        }
    }
    
    // Write back to file
    \$data = ['emails' => \$existingData, 'count' => count(\$existingData)];
    file_put_contents(\$emailFile, json_encode(\$data, JSON_PRETTY_PRINT));
    
    return \$saved;
}

// Extraction work with REAL email scraping - OPTIMIZED
\$startTime = time();
\$extractedCount = 0;
\$urlsToProcess = [];
\$processedUrls = [];
\$seenEmails = []; // In-memory deduplication cache for this worker
\$currentQueryIndex = 0;
\$currentPage = 1;
\$emailBatch = []; // Batch buffer for database inserts
\$lastCountCheck = time(); // For periodic count checking
\$localEmailCount = 0; // Local counter

// Build query list: main query + keywords
\$queryList = [];
if (!empty(\$query)) {
    \$queryList[] = \$query;
}
if (!empty(\$keywords)) {
    foreach (\$keywords as \$keyword) {
        if (!empty(\$keyword)) {
            \$queryList[] = !empty(\$query) ? \$query . ' ' . \$keyword : \$keyword;
        }
    }
}
if (empty(\$queryList)) {
    \$queryList[] = \$query;
}

\$activeQuery = \$queryList[\$currentQueryIndex % count(\$queryList)];

while ((time() - \$startTime) < \$maxRunTime) {
    // Check target limit periodically (every 10 seconds) instead of every iteration
    if (\$pdo && (time() - \$lastCountCheck) >= 10) {
        \$currentTotal = getCurrentEmailCount(\$pdo, \$jobId);
        \$lastCountCheck = time();
        if (\$currentTotal >= \$maxEmails) {
            // Save any remaining emails in batch
            if (!empty(\$emailBatch)) {
                saveBatchToDB(\$pdo, \$jobId, \$emailBatch);
            }
            echo json_encode(['type' => 'target_reached', 'worker_id' => \$workerId, 'total' => \$currentTotal]) . "\\n";
            break;
        }
    }
    
    // Output heartbeat less frequently
    if (\$extractedCount % 50 == 0) {
        echo json_encode(['type' => 'heartbeat', 'worker_id' => \$workerId, 'time' => time()]) . "\\n";
        flush();
    }
    
    // Fetch URLs from Serper API - fetch more at once and keep cycling through queries
    if (count(\$urlsToProcess) < 50 && !empty(\$apiKey)) {
        // Try fetching from current page if we haven't exceeded max pages
        if (\$currentPage <= \$maxPages) {
            \$results = searchSerper(\$apiKey, \$activeQuery, \$country, \$language, \$currentPage);
            if (!empty(\$results)) {
                foreach (\$results as \$result) {
                    if (isset(\$result['link'])) {
                        \$url = \$result['link'];
                        // Use hash for O(1) lookup instead of in_array
                        if (!isset(\$processedUrls[\$url])) {
                            \$urlsToProcess[] = \$url;
                            \$processedUrls[\$url] = true;
                        }
                    }
                }
                \$currentPage++;
            } else {
                // No results, try next query
                if (count(\$queryList) > 1) {
                    \$currentQueryIndex++;
                    \$activeQuery = \$queryList[\$currentQueryIndex % count(\$queryList)];
                    \$currentPage = 1;
                    echo json_encode(['type' => 'query_switch', 'worker_id' => \$workerId, 'new_query' => \$activeQuery]) . "\\n";
                    flush();
                }
            }
        } else {
            // Reached max pages for current query, cycle to next query and reset page count
            if (count(\$queryList) > 1) {
                \$currentQueryIndex++;
                \$activeQuery = \$queryList[\$currentQueryIndex % count(\$queryList)];
                \$currentPage = 1;
                echo json_encode(['type' => 'query_cycle', 'worker_id' => \$workerId, 'new_query' => \$activeQuery]) . "\\n";
                flush();
            } else {
                // Single query and max pages reached, reset to page 1 to keep working
                \$currentPage = 1;
            }
        }
    }
    
    // Process URLs and extract REAL emails
    if (empty(\$urlsToProcess)) {
        // Don't sleep, just continue to fetch more URLs
        continue;
    }
    
    \$url = array_shift(\$urlsToProcess);
    
    // Fetch and parse the URL content
    \$content = fetchUrlContent(\$url);
    if (\$content === false) {
        continue; // Skip failed URLs quickly
    }
    
    // Extract emails from the page content
    \$extractedEmails = extractEmailsFromContent(\$content);
    \$foundCount = 0;
    
    foreach (\$extractedEmails as \$email) {
        // Check local counter for quick exit
        if (\$localEmailCount >= \$maxEmails) {
            break 2;
        }
        
        // Deduplicate using memory cache only (much faster)
        \$emailLower = strtolower(\$email);
        if (isset(\$seenEmails[\$emailLower])) {
            continue;
        }
        
        // Filter by email type
        if (!matchesEmailType(\$email, \$emailTypes, \$customDomains)) {
            continue;
        }
        
        // Mark as seen
        \$seenEmails[\$emailLower] = true;
        
        // Prevent memory overflow
        if (count(\$seenEmails) > 50000) {
            \$seenEmails = array_slice(\$seenEmails, 25000, null, true);
        }
        
        // Determine email quality
        \$quality = getEmailQuality(\$email, \$content);
        
        \$emailData = [
            'email' => \$email,
            'quality' => \$quality,
            'source_url' => \$url,
            'timestamp' => time(),
            'confidence' => 0.85,
            'worker_id' => \$workerId
        ];
        
        // Add to batch instead of immediate save
        \$emailBatch[] = \$emailData;
        \$extractedCount++;
        \$localEmailCount++;
        \$foundCount++;
        
        // Flush batch when it reaches 100 emails
        if (count(\$emailBatch) >= 100) {
            if (\$pdo) {
                \$saved = saveBatchToDB(\$pdo, \$jobId, \$emailBatch);
                if (\$saved > 0) {
                    echo json_encode([
                        'type' => 'batch_saved',
                        'worker_id' => \$workerId,
                        'count' => \$saved,
                        'time' => time()
                    ]) . "\\n";
                    flush();
                }
            } else {
                // Use fast JSON batch save instead of one-by-one
                \$saved = saveBatchToJSON(\$emailFile, \$emailBatch);
                if (\$saved > 0) {
                    echo json_encode([
                        'type' => 'batch_saved_json',
                        'worker_id' => \$workerId,
                        'count' => \$saved,
                        'time' => time()
                    ]) . "\\n";
                    flush();
                }
            }
            \$emailBatch = [];
        }
    }
    
    if (\$foundCount > 0 && \$foundCount % 20 == 0) {
        echo json_encode([
            'type' => 'emails_found',
            'worker_id' => \$workerId,
            'count' => \$foundCount,
            'url' => \$url,
            'time' => time()
        ]) . "\\n";
        flush();
    }
    
    // No delay - process URLs as fast as possible
}

// Save any remaining emails in batch
if (!empty(\$emailBatch)) {
    if (\$pdo) {
        saveBatchToDB(\$pdo, \$jobId, \$emailBatch);
    } else {
        // Use fast JSON batch save
        saveBatchToJSON(\$emailFile, \$emailBatch);
    }
}

echo json_encode(['type' => 'completed', 'worker_id' => \$workerId, 'total_extracted' => \$extractedCount]) . "\\n";
PHP;
    }
    
    public function checkWorkers() {
        $now = time();
        
        foreach ($this->workers as $workerId => $worker) {
            // Read output from worker
            if (is_resource($worker['pipes'][1])) {
                $output = stream_get_contents($worker['pipes'][1]);
                if ($output) {
                    $this->processWorkerOutput($workerId, $output);
                }
            }
            
            // Check for zombie workers
            $status = proc_get_status($worker['process']);
            if (!$status['running']) {
                $this->workers[$workerId]['status'] = 'completed';
                Utils::logMessage('INFO', "Worker completed: {$workerId}");
            } elseif (($now - $worker['last_heartbeat']) > Config::WORKER_TIMEOUT) {
                $this->terminateWorker($workerId, 'timeout');
            }
        }
        
        return $this->getStats();
    }
    
    private function processWorkerOutput($workerId, $output) {
        $lines = explode("\n", trim($output));
        
        foreach ($lines as $line) {
            if (empty($line)) continue;
            
            $data = json_decode($line, true);
            if (!$data) continue;
            
            if ($data['type'] === 'heartbeat') {
                $this->workers[$workerId]['last_heartbeat'] = time();
            } elseif ($data['type'] === 'emails_found') {
                // Update job stats by reading email file
                $this->updateJobStatsFromFile();
                Utils::logMessage('INFO', "Worker {$workerId} found {$data['count']} emails");
            }
        }
    }
    
    private function updateJobStatsFromFile() {
        // Read the email file and update job statistics
        $emailFile = Config::DATA_DIR . "/job_{$this->jobId}_emails.json";
        if (file_exists($emailFile)) {
            $data = json_decode(file_get_contents($emailFile), true);
            if ($data && isset($data['total'])) {
                // This will be picked up by JobManager when it checks jobs
                touch($emailFile); // Update modification time
            }
        }
    }
    
    public function terminateWorker($workerId, $reason = 'manual') {
        if (!isset($this->workers[$workerId])) {
            return false;
        }
        
        $worker = $this->workers[$workerId];
        
        // Close pipes
        foreach ($worker['pipes'] as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        
        // Terminate process
        if (is_resource($worker['process'])) {
            proc_terminate($worker['process']);
            proc_close($worker['process']);
        }
        
        unset($this->workers[$workerId]);
        
        Utils::logMessage('INFO', "Worker terminated: {$workerId} (reason: {$reason})");
        
        return true;
    }
    
    public function spawnWorkersBatch($count, $config) {
        $spawned = 0;
        $current = count($this->workers);
        
        // Cap at max workers
        $actualCount = min($count, $this->maxWorkers - $current);
        
        if ($actualCount <= 0) {
            Utils::logMessage('WARNING', "Batch spawn requested for {$count} workers, but maximum capacity reached or invalid count (current: {$current}, max: {$this->maxWorkers})");
            return 0;
        }
        
        // Calculate batches
        $batchSize = Config::WORKER_SPAWN_BATCH_SIZE;
        $totalBatches = ceil($actualCount / $batchSize);
        
        Utils::logMessage('INFO', "Starting batch spawn: {$actualCount} workers in {$totalBatches} batches");
        
        for ($batchNum = 0; $batchNum < $totalBatches; $batchNum++) {
            $workersInThisBatch = min($batchSize, $actualCount - $spawned);
            
            for ($i = 0; $i < $workersInThisBatch; $i++) {
                $workerId = Utils::generateId('worker_');
                try {
                    $this->spawnWorker($workerId, $config);
                    $spawned++;
                } catch (Exception $e) {
                    $errorMsg = "Failed to spawn worker {$workerId}: {$e->getMessage()}";
                    Utils::logMessage('ERROR', $errorMsg);
                }
            }
            
            // Sleep between batches to avoid CPU spikes, but not after the last batch
            if ($batchNum < $totalBatches - 1) {
                $completedBatch = $batchNum + 1;
                $nextBatch = $completedBatch + 1;
                $delay = Config::WORKER_SPAWN_BATCH_DELAY;
                Utils::logMessage('DEBUG', sprintf("Completed batch %d/%d, sleeping for %d second(s) before starting batch %d", $completedBatch, $totalBatches, $delay, $nextBatch));
                sleep(Config::WORKER_SPAWN_BATCH_DELAY);
            }
        }
        
        Utils::logMessage('INFO', "Batch spawn complete: {$spawned} workers spawned");
        
        return $spawned;
    }
    
    public function scaleUp($count = 1) {
        $current = count($this->workers);
        
        if ($current >= $this->maxWorkers) {
            return 0;
        }
        
        $toSpawn = min($count, $this->maxWorkers - $current);
        $spawned = 0;
        
        for ($i = 0; $i < $toSpawn; $i++) {
            $workerId = Utils::generateId('worker_');
            try {
                $this->spawnWorker($workerId, ['job_id' => $this->jobId]);
                $spawned++;
            } catch (Exception $e) {
                Utils::logMessage('ERROR', "Failed to spawn worker: {$e->getMessage()}");
            }
        }
        
        return $spawned;
    }
    
    public function scaleDown($count = 1) {
        $current = count($this->workers);
        
        if ($current <= $this->minWorkers) {
            return 0;
        }
        
        $toTerminate = min($count, $current - $this->minWorkers);
        $terminated = 0;
        
        $workerIds = array_keys($this->workers);
        for ($i = 0; $i < $toTerminate; $i++) {
            if (isset($workerIds[$i])) {
                $this->terminateWorker($workerIds[$i], 'scale_down');
                $terminated++;
            }
        }
        
        return $terminated;
    }
    
    public function terminateAll() {
        $workerIds = array_keys($this->workers);
        foreach ($workerIds as $workerId) {
            $this->terminateWorker($workerId, 'job_stopped');
        }
    }
    
    public function getStats() {
        $stats = [
            'total' => count($this->workers),
            'running' => 0,
            'completed' => 0
        ];
        
        foreach ($this->workers as $worker) {
            if ($worker['status'] === 'running') {
                $stats['running']++;
            } else {
                $stats['completed']++;
            }
        }
        
        return $stats;
    }
    
    public function getWorkers() {
        return $this->workers;
    }
}

// Job Manager - Manages multiple jobs
class JobManager {
    private $jobs = [];
    private $dataDir;
    private $db;
    
    public function __construct() {
        $this->dataDir = Config::DATA_DIR;
        @mkdir($this->dataDir, 0755, true);
        $this->db = Database::getInstance();
        $this->loadJobs();
    }
    
    public function createJob($name, $apiKey, $query, $options = []) {
        $jobId = Utils::generateId('job_');
        
        $job = [
            'id' => $jobId,
            'name' => $name,
            'api_key' => $apiKey,
            'query' => $query,
            'options' => $options,
            'status' => 'created',
            'created_at' => time(),
            'started_at' => null,
            'completed_at' => null,
            'emails_found' => 0,
            'emails_accepted' => 0,
            'emails_rejected' => 0,
            'urls_processed' => 0,
            'errors' => 0,
            'error_messages' => [], // Store error messages for UI display
            'worker_governor' => null,
            'hourly_stats' => [], // Track emails per hour
            'worker_count' => 0, // Persistent worker count
            'workers_running' => 0, // Persistent running worker count
            'target_emails' => $options['target_emails'] ?? 10000 // Target email count for progress tracking
        ];
        
        $this->jobs[$jobId] = $job;
        $this->saveJob($jobId);
        
        Utils::logMessage('INFO', "Job created: {$jobId} - {$name}");
        
        return $jobId;
    }
    
    private function checkSystemRequirements() {
        $errors = [];
        
        // Check if proc_open is available
        if (!function_exists('proc_open')) {
            $errors[] = 'proc_open function is not available. Enable it in php.ini';
        }
        
        // Check if proc_close is available
        if (!function_exists('proc_close')) {
            $errors[] = 'proc_close function is not available. Enable it in php.ini';
        }
        
        // Check memory
        $memoryLimit = ini_get('memory_limit');
        $memoryUsage = memory_get_usage(true) / 1024 / 1024;
        if ($memoryUsage > 400) {
            $errors[] = "High memory usage: {$memoryUsage}MB. Consider restarting PHP";
        }
        
        // Check if we can write to data directory
        if (!is_writable(Config::DATA_DIR) && !@mkdir(Config::DATA_DIR, 0755, true)) {
            $errors[] = 'Cannot write to data directory: ' . Config::DATA_DIR;
        }
        
        return $errors;
    }
    
    private function addJobError($jobId, $error) {
        if (!isset($this->jobs[$jobId])) {
            return;
        }
        
        if (!isset($this->jobs[$jobId]['error_messages'])) {
            $this->jobs[$jobId]['error_messages'] = [];
        }
        
        $this->jobs[$jobId]['error_messages'][] = [
            'time' => time(),
            'message' => $error
        ];
        
        // Keep only last 10 errors
        if (count($this->jobs[$jobId]['error_messages']) > 10) {
            $this->jobs[$jobId]['error_messages'] = array_slice(
                $this->jobs[$jobId]['error_messages'], 
                -10
            );
        }
        
        $this->jobs[$jobId]['errors']++;
        
        // Save error to database if configured
        if ($this->db->isConfigured()) {
            try {
                $this->db->execute(
                    "INSERT INTO job_errors (job_id, error_message) VALUES (:job_id, :error)",
                    [':job_id' => $jobId, ':error' => $error]
                );
            } catch (Exception $e) {
                Utils::logMessage('ERROR', "Failed to save error to database: {$e->getMessage()}");
            }
        }
        
        $this->saveJob($jobId);
    }
    
    public function updateJobApiKey($jobId, $apiKey) {
        if (!isset($this->jobs[$jobId])) {
            throw new Exception("Job not found: {$jobId}");
        }
        
        $this->jobs[$jobId]['api_key'] = $apiKey;
        Utils::logMessage('DEBUG', "API key updated for job {$jobId}");
    }
    
    public function startJob($jobId) {
        if (!isset($this->jobs[$jobId])) {
            throw new Exception("Job not found: {$jobId}");
        }
        
        $job = &$this->jobs[$jobId];
        
        if ($job['status'] === 'running') {
            throw new Exception("Job already running: {$jobId}");
        }
        
        // Check system requirements
        $systemErrors = $this->checkSystemRequirements();
        if (!empty($systemErrors)) {
            foreach ($systemErrors as $error) {
                $this->addJobError($jobId, $error);
            }
            throw new Exception("System requirements not met: " . implode(', ', $systemErrors));
        }
        
        // Validate API key exists
        if (empty($job['api_key'])) {
            $this->addJobError($jobId, "API key is missing");
            throw new Exception("API key is required to start job");
        }
        
        // Validate query exists
        if (empty($job['query'])) {
            $this->addJobError($jobId, "Search query is missing");
            throw new Exception("Search query is required to start job");
        }
        
        try {
            // Initialize worker governor with job configuration
            $workerConfig = [
                'job_id' => $jobId,
                'api_key' => $job['api_key'],
                'query' => $job['query'],
                'country' => $job['options']['country'] ?? '',
                'language' => $job['options']['language'] ?? 'en',
                'max_emails' => $job['options']['target_emails'] ?? 10000, // Use target_emails as the stop condition
                'max_run_time' => $job['options']['max_run_time'] ?? Config::DEFAULT_WORKER_RUNTIME,
                'max_pages' => $job['options']['max_pages'] ?? Config::DEFAULT_MAX_PAGES,
                'email_types' => $job['options']['email_types'] ?? '',
                'custom_domains' => $job['options']['custom_domains'] ?? [],
                'keywords' => $job['options']['keywords'] ?? []
            ];
            
            // Get the desired number of workers from job options
            $desiredWorkers = isset($job['options']['max_workers']) ? 
                min((int)$job['options']['max_workers'], Config::MAX_WORKERS_PER_JOB) : 
                Config::MIN_WORKERS_PER_JOB;
            
            $governor = new WorkerGovernor($jobId, $desiredWorkers);
            $job['worker_governor'] = $governor;
            $job['status'] = 'running';
            $job['started_at'] = time();
            
            // Spawn all requested workers in batches for optimal performance
            $workersSpawned = $governor->spawnWorkersBatch($desiredWorkers, $workerConfig);
            
            if ($workersSpawned === 0) {
                $job['status'] = 'error';
                $errorMsg = "Failed to spawn any workers. Check: 1) System resources (memory, CPU), 2) PHP configuration (verify proc_open is not in disable_functions in php.ini), 3) Try reducing worker count.";
                $this->addJobError($jobId, $errorMsg);
                $this->saveJob($jobId);
                throw new Exception($errorMsg);
            }
            
            // Update persistent worker counts
            $job['worker_count'] = $workersSpawned;
            $job['workers_running'] = $workersSpawned;
            
            $this->saveJob($jobId);
            Utils::logMessage('INFO', "Job started: {$jobId} with {$workersSpawned}/{$desiredWorkers} workers");
            
            return true;
        } catch (Exception $e) {
            $job['status'] = 'error';
            $this->addJobError($jobId, "Start failed: " . $e->getMessage());
            $this->saveJob($jobId);
            throw $e;
        }
    }
    
    public function stopJob($jobId) {
        if (!isset($this->jobs[$jobId])) {
            throw new Exception("Job not found: {$jobId}");
        }
        
        $job = &$this->jobs[$jobId];
        
        if ($job['worker_governor']) {
            $job['worker_governor']->terminateAll();
        }
        
        $job['status'] = 'stopped';
        $this->saveJob($jobId);
        
        Utils::logMessage('INFO', "Job stopped: {$jobId}");
        
        return true;
    }
    
    public function deleteJob($jobId) {
        if (!isset($this->jobs[$jobId])) {
            return false;
        }
        
        // Stop the job first
        if ($this->jobs[$jobId]['status'] === 'running') {
            $this->stopJob($jobId);
        }
        
        // Delete from database if configured
        if ($this->db->isConfigured()) {
            try {
                // Delete related emails first
                $this->db->execute("DELETE FROM emails WHERE job_id = :job_id", [':job_id' => $jobId]);
                
                // Delete job from database (cascade will delete job_errors)
                $this->db->execute("DELETE FROM jobs WHERE id = :job_id", [':job_id' => $jobId]);
                
                Utils::logMessage('INFO', "Job {$jobId} deleted from database");
            } catch (Exception $e) {
                Utils::logMessage('ERROR', "Failed to delete job from database: {$e->getMessage()}");
                // Continue with memory/file deletion even if database deletion fails
            }
        }
        
        unset($this->jobs[$jobId]);
        
        // Delete job file
        $jobFile = $this->dataDir . "/job_{$jobId}.json";
        @unlink($jobFile);
        
        // Delete email file
        $emailFile = $this->dataDir . "/job_{$jobId}_emails.json";
        @unlink($emailFile);
        
        Utils::logMessage('INFO', "Job deleted: {$jobId}");
        
        return true;
    }
    
    public function getJob($jobId) {
        if (!isset($this->jobs[$jobId])) {
            return null;
        }
        
        $job = $this->jobs[$jobId];
        
        // Get worker stats if running (prefer live stats, fall back to persistent)
        if ($job['status'] === 'running') {
            if ($job['worker_governor']) {
                $job['worker_stats'] = $job['worker_governor']->getStats();
                $job['workers'] = $job['worker_governor']->getWorkers();
            } else {
                // Use persistent counts if governor doesn't exist yet
                $job['worker_stats'] = [
                    'total' => $job['worker_count'] ?? 0,
                    'running' => $job['workers_running'] ?? 0,
                    'completed' => 0
                ];
            }
        }
        
        // Remove sensitive data
        unset($job['api_key']);
        unset($job['worker_governor']);
        
        return $job;
    }
    
    public function getAllJobs() {
        $jobs = [];
        
        foreach ($this->jobs as $jobId => $job) {
            $jobs[] = $this->getJob($jobId);
        }
        
        return $jobs;
    }
    
    public function updateJobStats($jobId, $stats) {
        if (!isset($this->jobs[$jobId])) {
            return false;
        }
        
        foreach ($stats as $key => $value) {
            if (isset($this->jobs[$jobId][$key])) {
                $this->jobs[$jobId][$key] += $value;
            }
        }
        
        $this->saveJob($jobId);
        return true;
    }
    
    public function checkAllJobs() {
        foreach ($this->jobs as $jobId => $job) {
            if ($job['status'] === 'running') {
                // Skip jobs without API key only if they also have no workers
                // Jobs with workers are actively running and should continue
                if (empty($job['api_key'])) {
                    if (isset($job['workers_running']) && $job['workers_running'] > 0) {
                        // Job has workers running, skip the API key check
                        // This happens during normal operation after DB reload
                        Utils::logMessage('DEBUG', "Job {$jobId} has {$job['workers_running']} workers running, continuing without API key check");
                    } else {
                        // Job has no API key and no workers - stop it
                        Utils::logMessage('WARNING', "Stopping job {$jobId} - no API key and no workers");
                        $this->jobs[$jobId]['status'] = 'stopped';
                        $this->jobs[$jobId]['worker_count'] = 0;
                        $this->jobs[$jobId]['workers_running'] = 0;
                        $this->saveJob($jobId);
                        continue;
                    }
                }
                
                // Restore worker governor if it doesn't exist
                if (!$job['worker_governor']) {
                    try {
                        // Get the desired number of workers from job options
                        $desiredWorkers = isset($job['options']['max_workers']) ? 
                            min((int)$job['options']['max_workers'], Config::MAX_WORKERS_PER_JOB) : 
                            Config::MIN_WORKERS_PER_JOB;
                        
                        $governor = new WorkerGovernor($jobId, $desiredWorkers);
                        $this->jobs[$jobId]['worker_governor'] = $governor;
                        
                        // Respawn workers up to desired count with full configuration
                        $workerConfig = [
                            'job_id' => $jobId,
                            'api_key' => $job['api_key'],
                            'query' => $job['query'],
                            'country' => $job['options']['country'] ?? '',
                            'language' => $job['options']['language'] ?? 'en',
                            'max_emails' => $job['options']['target_emails'] ?? 10000, // Use target_emails as the stop condition
                            'max_run_time' => $job['options']['max_run_time'] ?? Config::DEFAULT_WORKER_RUNTIME,
                            'max_pages' => $job['options']['max_pages'] ?? Config::DEFAULT_MAX_PAGES,
                            'email_types' => $job['options']['email_types'] ?? '',
                            'custom_domains' => $job['options']['custom_domains'] ?? [],
                            'keywords' => $job['options']['keywords'] ?? []
                        ];
                        
                        $currentWorkers = count($governor->getWorkers());
                        $neededWorkers = $desiredWorkers - $currentWorkers;
                        
                        Utils::logMessage('INFO', "Restoring job {$jobId}: spawning {$neededWorkers} workers to reach {$desiredWorkers} total");
                        
                        // Use batch spawning for restoration as well
                        $workersSpawned = $governor->spawnWorkersBatch($neededWorkers, $workerConfig);
                        
                        // Update persistent worker count
                        if (!isset($this->jobs[$jobId]['worker_count'])) {
                            $this->jobs[$jobId]['worker_count'] = 0;
                        }
                        $this->jobs[$jobId]['worker_count'] += $workersSpawned;
                        $this->saveJob($jobId);
                    } catch (Exception $e) {
                        Utils::logMessage('ERROR', "Failed to restore worker governor for job {$jobId}: {$e->getMessage()}");
                        $this->addJobError($jobId, "Worker restoration failed: {$e->getMessage()}");
                    }
                }
                
                // Check existing workers
                if ($this->jobs[$jobId]['worker_governor']) {
                    $this->jobs[$jobId]['worker_governor']->checkWorkers();
                    
                    // Update persistent worker stats
                    $stats = $this->jobs[$jobId]['worker_governor']->getStats();
                    $this->jobs[$jobId]['worker_count'] = $stats['total'];
                    $this->jobs[$jobId]['workers_running'] = $stats['running'];
                    
                    // Update email counts from file
                    $this->updateEmailCountsFromFile($jobId);
                    
                    // Check if job has reached 100% progress and auto-complete
                    $targetEmails = $this->jobs[$jobId]['target_emails'] ?? 
                                  ($this->jobs[$jobId]['options']['target_emails'] ?? 10000);
                    $emailsAccepted = $this->jobs[$jobId]['emails_accepted'] ?? 0;
                    
                    if ($emailsAccepted >= $targetEmails) {
                        Utils::logMessage('INFO', "Job {$jobId} reached target ({$emailsAccepted}/{$targetEmails}). Auto-completing...");
                        
                        // Stop all workers
                        $this->jobs[$jobId]['worker_governor']->terminateAll();
                        
                        // Mark job as completed
                        $this->jobs[$jobId]['status'] = 'completed';
                        $this->jobs[$jobId]['worker_count'] = 0;
                        $this->jobs[$jobId]['workers_running'] = 0;
                        $this->jobs[$jobId]['completed_at'] = time();
                        
                        // Save the completed job
                        $this->saveJob($jobId);
                        
                        Utils::logMessage('INFO', "Job {$jobId} auto-completed successfully");
                    }
                }
            }
        }
    }
    
    private function updateEmailCountsFromFile($jobId) {
        // First try to get from database
        if ($this->db->isConfigured()) {
            try {
                $stmt = $this->db->query(
                    "SELECT COUNT(*) as count FROM emails WHERE job_id = :job_id",
                    [':job_id' => $jobId]
                );
                $result = $stmt->fetch();
                if ($result) {
                    $this->jobs[$jobId]['emails_accepted'] = (int)$result['count'];
                    $this->jobs[$jobId]['emails_found'] = (int)$result['count'];
                    $this->jobs[$jobId]['urls_processed'] = (int)$result['count'] * 2; // Estimate
                    $this->saveJob($jobId);
                    return;
                }
            } catch (Exception $e) {
                Utils::logMessage('WARNING', "Failed to get email count from database: {$e->getMessage()}");
            }
        }
        
        // Fallback to file-based counting
        $emailFile = $this->dataDir . "/job_{$jobId}_emails.json";
        if (file_exists($emailFile)) {
            $data = json_decode(file_get_contents($emailFile), true);
            if ($data && isset($data['emails'])) {
                $this->jobs[$jobId]['emails_accepted'] = count($data['emails']);
                $this->jobs[$jobId]['emails_found'] = count($data['emails']);
                $this->jobs[$jobId]['urls_processed'] = count($data['emails']) * 2; // Estimate
                
                // Save updated stats
                $this->saveJob($jobId);
            }
        }
    }
    
    private function saveJob($jobId) {
        if (!isset($this->jobs[$jobId])) {
            return false;
        }
        
        $job = $this->jobs[$jobId];
        
        // Remove non-serializable objects
        $jobData = $job;
        unset($jobData['worker_governor']);
        unset($jobData['api_key']); // Don't store API key in DB
        
        // Save to database if configured
        if ($this->db->isConfigured()) {
            try {
                $sql = "INSERT INTO jobs (
                    id, name, query, options, status, 
                    emails_found, emails_accepted, emails_rejected, 
                    urls_processed, errors, worker_count, workers_running,
                    started_at
                ) VALUES (
                    :id, :name, :query, :options, :status,
                    :emails_found, :emails_accepted, :emails_rejected,
                    :urls_processed, :errors, :worker_count, :workers_running,
                    :started_at
                ) ON DUPLICATE KEY UPDATE
                    name = VALUES(name),
                    query = VALUES(query),
                    options = VALUES(options),
                    status = VALUES(status),
                    emails_found = VALUES(emails_found),
                    emails_accepted = VALUES(emails_accepted),
                    emails_rejected = VALUES(emails_rejected),
                    urls_processed = VALUES(urls_processed),
                    errors = VALUES(errors),
                    worker_count = VALUES(worker_count),
                    workers_running = VALUES(workers_running),
                    started_at = VALUES(started_at)";
                
                $this->db->execute($sql, [
                    ':id' => $jobId,
                    ':name' => $jobData['name'],
                    ':query' => $jobData['query'],
                    ':options' => json_encode($jobData['options']),
                    ':status' => $jobData['status'],
                    ':emails_found' => $jobData['emails_found'],
                    ':emails_accepted' => $jobData['emails_accepted'],
                    ':emails_rejected' => $jobData['emails_rejected'],
                    ':urls_processed' => $jobData['urls_processed'],
                    ':errors' => $jobData['errors'],
                    ':worker_count' => $jobData['worker_count'],
                    ':workers_running' => $jobData['workers_running'],
                    ':started_at' => $jobData['started_at'] ? date('Y-m-d H:i:s', $jobData['started_at']) : null
                ]);
                
                Utils::logMessage('DEBUG', "Job {$jobId} saved to database");
                return true;
            } catch (Exception $e) {
                Utils::logMessage('ERROR', "Failed to save job to database: {$e->getMessage()}");
                // Fall through to file-based save
            }
        }
        
        // Fallback to file-based storage
        $jobFile = $this->dataDir . "/job_{$jobId}.json";
        file_put_contents($jobFile, json_encode($jobData, JSON_PRETTY_PRINT));
        
        return true;
    }
    
    private function loadJobs() {
        // Load from database if configured
        if ($this->db->isConfigured()) {
            try {
                $stmt = $this->db->query("SELECT * FROM jobs ORDER BY created_at DESC");
                $dbJobs = $stmt->fetchAll();
                
                foreach ($dbJobs as $jobData) {
                    $jobId = $jobData['id'];
                    
                    // Load recent errors for this job
                    $errorStmt = $this->db->query(
                        "SELECT error_message, UNIX_TIMESTAMP(created_at) as time 
                         FROM job_errors 
                         WHERE job_id = :job_id 
                         ORDER BY created_at DESC 
                         LIMIT 10",
                        [':job_id' => $jobId]
                    );
                    $errors = $errorStmt->fetchAll();
                    $errorMessages = array_map(function($err) {
                        return ['time' => $err['time'], 'message' => $err['error_message']];
                    }, $errors);
                    
                    $this->jobs[$jobId] = [
                        'id' => $jobId,
                        'name' => $jobData['name'],
                        'api_key' => '', // Will need to be set from session/config
                        'query' => $jobData['query'],
                        'options' => json_decode($jobData['options'], true) ?: [],
                        'status' => $jobData['status'],
                        'created_at' => strtotime($jobData['created_at']),
                        'started_at' => $jobData['started_at'] ? strtotime($jobData['started_at']) : null,
                        'emails_found' => (int)$jobData['emails_found'],
                        'emails_accepted' => (int)$jobData['emails_accepted'],
                        'emails_rejected' => (int)$jobData['emails_rejected'],
                        'urls_processed' => (int)$jobData['urls_processed'],
                        'errors' => (int)$jobData['errors'],
                        'error_messages' => $errorMessages,
                        'worker_governor' => null,
                        'hourly_stats' => [],
                        'worker_count' => (int)$jobData['worker_count'],
                        'workers_running' => (int)$jobData['workers_running'],
                        'target_emails' => isset($jobData['options']) ? 
                            (json_decode($jobData['options'], true)['target_emails'] ?? 10000) : 10000
                    ];
                    
                    // If job is running but has no API key (after true page refresh),
                    // mark it as stopped ONLY if it has no workers running.
                    // Jobs with workers_running > 0 are actively working and should not be stopped.
                    if ($this->jobs[$jobId]['status'] === 'running' && 
                        empty($this->jobs[$jobId]['api_key']) && 
                        $this->jobs[$jobId]['workers_running'] == 0) {
                        $this->jobs[$jobId]['status'] = 'stopped';
                        $this->jobs[$jobId]['worker_count'] = 0;
                        $this->saveJob($jobId);
                        Utils::logMessage('INFO', "Job {$jobId} auto-stopped on load (missing API key and no active workers)");
                    }
                }
                
                Utils::logMessage('INFO', "Loaded " . count($this->jobs) . " jobs from database");
                return;
            } catch (Exception $e) {
                Utils::logMessage('ERROR', "Failed to load jobs from database: {$e->getMessage()}");
                // Fall through to file-based load
            }
        }
        
        // Fallback to file-based loading
        $files = glob($this->dataDir . "/job_*.json");
        
        foreach ($files as $file) {
            $data = json_decode(file_get_contents($file), true);
            if ($data && isset($data['id'])) {
                // Keep jobs in their saved state, but clear worker governor
                // Worker governor will be recreated on next start/check
                $data['worker_governor'] = null;
                $this->jobs[$data['id']] = $data;
            }
        }
    }
}

// API Handler
class APIHandler {
    private $jobManager;
    
    public function __construct($jobManager) {
        $this->jobManager = $jobManager;
    }
    
    public function handle() {
        $method = $_SERVER['REQUEST_METHOD'];
        $action = $_POST['action'] ?? $_GET['action'] ?? '';
        
        try {
            switch ($action) {
                case 'create_job':
                    return $this->createJob();
                    
                case 'start_job':
                    return $this->startJob();
                    
                case 'stop_job':
                    return $this->stopJob();
                    
                case 'delete_job':
                    return $this->deleteJob();
                    
                case 'get_job':
                    return $this->getJob();
                    
                case 'get_jobs':
                    return $this->getJobs();
                    
                case 'get_stats':
                    return $this->getStats();
                    
                case 'scale_workers':
                    return $this->scaleWorkers();
                    
                case 'test_connection':
                    return $this->testConnection();
                    
                case 'get_email_rate':
                    return $this->getEmailRate();
                    
                default:
                    throw new Exception("Unknown action: {$action}");
            }
        } catch (Exception $e) {
            return $this->jsonResponse([
                'success' => false,
                'error' => $e->getMessage()
            ], 400);
        }
    }
    
    private function createJob() {
        $name = $_POST['name'] ?? 'Unnamed Job';
        $apiKey = $_POST['api_key'] ?? '';
        $query = $_POST['query'] ?? '';
        $keywords = $_POST['keywords'] ?? ''; // Multiple keywords, one per line
        $country = $_POST['country'] ?? 'us';
        $language = $_POST['language'] ?? 'en';
        $emailTypes = $_POST['email_types'] ?? ''; // Comma-separated email types
        $customDomains = $_POST['custom_domains'] ?? ''; // Custom domains, one per line
        
        if (empty($apiKey) || (empty($query) && empty($keywords))) {
            throw new Exception("API key and query/keywords are required");
        }
        
        // Parse keywords if provided
        $keywordList = [];
        if (!empty($keywords)) {
            $keywordList = array_filter(array_map('trim', explode("\n", $keywords)));
        }
        
        // Parse custom domains if provided
        $customDomainList = [];
        if (!empty($customDomains)) {
            $customDomainList = array_filter(array_map('trim', explode("\n", $customDomains)));
        }
        
        $options = [
            'max_workers' => (int)($_POST['max_workers'] ?? Config::MAX_WORKERS_PER_JOB),
            'target_emails' => (int)($_POST['target_emails'] ?? 10000),
            'max_emails' => (int)($_POST['target_emails'] ?? 10000), // Same as target_emails for worker stop condition
            'keywords' => $keywordList,
            'country' => $country,
            'language' => $language,
            'email_types' => $emailTypes,
            'custom_domains' => $customDomainList
        ];
        
        $jobId = $this->jobManager->createJob($name, $apiKey, $query, $options);
        
        return $this->jsonResponse([
            'success' => true,
            'job_id' => $jobId
        ]);
    }
    
    private function startJob() {
        $jobId = $_POST['job_id'] ?? '';
        $apiKey = $_POST['api_key'] ?? '';
        
        if (empty($jobId)) {
            throw new Exception("Job ID is required");
        }
        
        // Update the job's API key if provided
        if (!empty($apiKey)) {
            $this->jobManager->updateJobApiKey($jobId, $apiKey);
        }
        
        $this->jobManager->startJob($jobId);
        
        return $this->jsonResponse([
            'success' => true,
            'message' => 'Job started successfully'
        ]);
    }
    
    private function stopJob() {
        $jobId = $_POST['job_id'] ?? '';
        
        if (empty($jobId)) {
            throw new Exception("Job ID is required");
        }
        
        $this->jobManager->stopJob($jobId);
        
        return $this->jsonResponse([
            'success' => true,
            'message' => 'Job stopped successfully'
        ]);
    }
    
    private function deleteJob() {
        $jobId = $_POST['job_id'] ?? '';
        
        if (empty($jobId)) {
            throw new Exception("Job ID is required");
        }
        
        $this->jobManager->deleteJob($jobId);
        
        return $this->jsonResponse([
            'success' => true,
            'message' => 'Job deleted successfully'
        ]);
    }
    
    private function getJob() {
        $jobId = $_GET['job_id'] ?? '';
        
        if (empty($jobId)) {
            throw new Exception("Job ID is required");
        }
        
        $job = $this->jobManager->getJob($jobId);
        
        if (!$job) {
            throw new Exception("Job not found");
        }
        
        return $this->jsonResponse([
            'success' => true,
            'job' => $job
        ]);
    }
    
    private function getJobs() {
        $jobs = $this->jobManager->getAllJobs();
        
        return $this->jsonResponse([
            'success' => true,
            'jobs' => $jobs
        ]);
    }
    
    private function getStats() {
        $jobs = $this->jobManager->getAllJobs();
        
        $stats = [
            'total_jobs' => count($jobs),
            'running_jobs' => 0,
            'total_emails' => 0,
            'total_urls' => 0,
            'memory_usage' => memory_get_usage(true),
            'peak_memory' => memory_get_peak_usage(true)
        ];
        
        foreach ($jobs as $job) {
            if ($job['status'] === 'running') {
                $stats['running_jobs']++;
            }
            $stats['total_emails'] += $job['emails_found'];
            $stats['total_urls'] += $job['urls_processed'];
        }
        
        return $this->jsonResponse([
            'success' => true,
            'stats' => $stats
        ]);
    }
    
    private function scaleWorkers() {
        $jobId = $_POST['job_id'] ?? '';
        $direction = $_POST['direction'] ?? 'up';
        $count = (int)($_POST['count'] ?? 1);
        
        if (empty($jobId)) {
            throw new Exception("Job ID is required");
        }
        
        $job = $this->jobManager->getJob($jobId);
        if (!$job) {
            throw new Exception("Job not found");
        }
        
        if ($job['status'] !== 'running') {
            throw new Exception("Job is not running");
        }
        
        // This would require accessing the worker governor directly
        // For now, return success
        return $this->jsonResponse([
            'success' => true,
            'message' => "Worker scaling requested: {$direction} by {$count}"
        ]);
    }
    
    private function testConnection() {
        $apiKey = $_POST['api_key'] ?? $_GET['api_key'] ?? '';
        
        if (empty($apiKey)) {
            throw new Exception("API key is required");
        }
        
        try {
            // Test connection with a simple query
            $scheduler = new SearchScheduler($apiKey);
            $results = $scheduler->search('test', ['num' => 1]);
            
            return $this->jsonResponse([
                'success' => true,
                'message' => 'Connection successful',
                'results_count' => count($results)
            ]);
        } catch (Exception $e) {
            return $this->jsonResponse([
                'success' => false,
                'message' => 'Connection failed: ' . $e->getMessage()
            ], 400);
        }
    }
    
    private function getEmailRate() {
        $jobId = $_GET['job_id'] ?? '';
        
        if (empty($jobId)) {
            throw new Exception("Job ID is required");
        }
        
        $job = $this->jobManager->getJob($jobId);
        
        if (!$job) {
            throw new Exception("Job not found");
        }
        
        // Calculate emails per minute for the last 10 minutes
        $emailsPerMinute = [];
        $now = time();
        $db = Database::getInstance();
        
        if ($db->isConfigured()) {
            try {
                // Get emails grouped by minute for the last 10 minutes
                $stmt = $db->query(
                    "SELECT 
                        FLOOR(UNIX_TIMESTAMP(created_at) / 60) * 60 as minute_timestamp,
                        COUNT(*) as count
                    FROM emails
                    WHERE job_id = :job_id 
                        AND created_at >= FROM_UNIXTIME(:since)
                    GROUP BY minute_timestamp
                    ORDER BY minute_timestamp ASC",
                    [':job_id' => $jobId, ':since' => $now - Config::CHART_TIME_WINDOW_SECONDS]
                );
                
                $results = $stmt->fetchAll();
                foreach ($results as $row) {
                    $emailsPerMinute[] = [
                        'timestamp' => (int)$row['minute_timestamp'],
                        'count' => (int)$row['count']
                    ];
                }
            } catch (Exception $e) {
                Utils::logMessage('ERROR', "Failed to get email rate: {$e->getMessage()}");
            }
        }
        
        return $this->jsonResponse([
            'success' => true,
            'job_id' => $jobId,
            'emails_per_minute' => $emailsPerMinute
        ]);
    }
    
    private function jsonResponse($data, $statusCode = 200) {
        header('Content-Type: application/json');
        http_response_code($statusCode);
        echo json_encode($data);
        exit;
    }
}

// Main Application
class Application {
    private $jobManager;
    private $apiHandler;
    
    public function __construct() {
        $this->jobManager = new JobManager();
        $this->apiHandler = new APIHandler($this->jobManager);
    }
    
    public function run() {
        // Handle API requests
        if (isset($_REQUEST['action'])) {
            $this->apiHandler->handle();
            return;
        }
        
        // Handle mode-based requests (for backward compatibility and simpler URLs)
        if (isset($_GET['mode'])) {
            $mode = $_GET['mode'];
            $jobId = $_GET['job_id'] ?? '';
            
            switch ($mode) {
                case 'start_job':
                    $this->handleStartJob();
                    return;
                    
                case 'stop':
                    $this->handleStopJob($jobId);
                    return;
                    
                case 'download_emails':
                    $this->downloadEmails($jobId);
                    return;
                    
                case 'download_emails_with_urls':
                    $this->downloadEmailsWithUrls($jobId);
                    return;
                    
                case 'download_urls_with_emails':
                    $this->downloadUrlsWithEmails($jobId);
                    return;
                    
                case 'download_urls_without_emails':
                    $this->downloadUrlsWithoutEmails($jobId);
                    return;
            }
        }
        
        // Handle CSV export (legacy)
        if (isset($_GET['export']) && $_GET['export'] === 'csv' && isset($_GET['job_id'])) {
            $this->exportJobEmails($_GET['job_id']);
            return;
        }
        
        // Handle results view
        if (isset($_GET['view']) && $_GET['view'] === 'results' && isset($_GET['job_id'])) {
            $this->renderResultsPage($_GET['job_id']);
            return;
        }
        
        // Background job checking (for cron or continuous operation)
        if (isset($_GET['cron'])) {
            $this->runCron();
            return;
        }
        
        // Run background tasks before rendering UI
        $this->runBackgroundTasks();
        
        // Render UI
        $this->renderUI();
    }
    
    public function runBackgroundTasks() {
        // Check all jobs and maintain workers
        $this->jobManager->checkAllJobs();
        
        // Memory check
        $memoryUsage = memory_get_usage(true) / 1024 / 1024;
        if ($memoryUsage > Config::MEMORY_LIMIT_MB) {
            Utils::logMessage('WARNING', "High memory usage: " . round($memoryUsage, 2) . " MB");
        }
    }
    
    private function runCron() {
        $this->runBackgroundTasks();
        echo "OK";
        exit;
    }
    
    private function renderUI() {
        $jobs = $this->jobManager->getAllJobs();
        $stats = $this->getSystemStats();
        
        $this->outputHTML();
    }
    
    private function getSystemStats() {
        $jobs = $this->jobManager->getAllJobs();
        
        return [
            'total_jobs' => count($jobs),
            'running_jobs' => count(array_filter($jobs, function($j) { return $j['status'] === 'running'; })),
            'total_emails' => array_sum(array_column($jobs, 'emails_found')),
            'memory_usage' => Utils::formatBytes(memory_get_usage(true)),
            'peak_memory' => Utils::formatBytes(memory_get_peak_usage(true))
        ];
    }
    
    private function exportJobEmails($jobId) {
        $job = $this->jobManager->getJob($jobId);
        if (!$job) {
            http_response_code(404);
            echo "Job not found";
            return;
        }
        
        $emails = $this->loadJobEmails($jobId);
        $jobName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $job['name']);
        $filename = "job_{$jobName}_emails_" . date('Y-m-d') . ".csv";
        
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Pragma: no-cache');
        header('Expires: 0');
        
        $output = fopen('php://output', 'w');
        fputcsv($output, ['Email', 'Quality', 'Source URL', 'Timestamp']);
        
        foreach ($emails as $item) {
            fputcsv($output, [
                $item['email'] ?? '',
                $item['quality'] ?? 'medium',
                $item['source_url'] ?? '',
                date('Y-m-d H:i:s', $item['timestamp'] ?? time())
            ]);
        }
        
        fclose($output);
        exit;
    }
    
    private function loadJobEmails($jobId) {
        $db = Database::getInstance();
        
        // Try loading from database first
        if ($db->isConfigured()) {
            try {
                $stmt = $db->query(
                    "SELECT email, quality, confidence, source_url, UNIX_TIMESTAMP(created_at) as timestamp, worker_id 
                     FROM emails 
                     WHERE job_id = :job_id 
                     ORDER BY created_at DESC",
                    [':job_id' => $jobId]
                );
                $emails = $stmt->fetchAll();
                
                // Convert to the expected format
                return array_map(function($row) {
                    return [
                        'email' => $row['email'],
                        'quality' => $row['quality'],
                        'confidence' => (float)$row['confidence'],
                        'source_url' => $row['source_url'],
                        'timestamp' => (int)$row['timestamp'],
                        'worker_id' => $row['worker_id']
                    ];
                }, $emails);
            } catch (Exception $e) {
                Utils::logMessage('ERROR', "Failed to load emails from database: {$e->getMessage()}");
                // Fall through to file-based loading
            }
        }
        
        // Fallback to file-based loading
        $emailFile = Config::DATA_DIR . "/job_{$jobId}_emails.json";
        if (!file_exists($emailFile)) {
            return [];
        }
        
        $data = json_decode(file_get_contents($emailFile), true);
        return $data['emails'] ?? [];
    }
    
    private function handleStartJob() {
        header('Content-Type: application/json');
        
        try {
            $name = $_POST['name'] ?? 'Unnamed Job';
            $apiKey = $_POST['api_key'] ?? '';
            $query = $_POST['query'] ?? '';
            $keywords = $_POST['keywords'] ?? '';
            
            if (empty($apiKey)) {
                throw new Exception("API key is required");
            }
            
            if (empty($query) && empty($keywords)) {
                throw new Exception("Either main query or keywords are required");
            }
            
            $keywordList = [];
            if (!empty($keywords)) {
                $keywordList = array_filter(array_map('trim', explode("\n", $keywords)));
            }
            
            $customDomainList = [];
            if (!empty($_POST['custom_domains'])) {
                $customDomainList = array_filter(array_map('trim', explode("\n", $_POST['custom_domains'])));
            }
            
            $options = [
                'max_workers' => (int)($_POST['max_workers'] ?? Config::MAX_WORKERS_PER_JOB),
                'target_emails' => (int)($_POST['target_emails'] ?? 10000),
                'max_emails' => (int)($_POST['target_emails'] ?? 10000),
                'keywords' => $keywordList,
                'country' => $_POST['country'] ?? 'us',
                'language' => $_POST['language'] ?? 'en',
                'email_types' => $_POST['email_types'] ?? '',
                'custom_domains' => $customDomainList
            ];
            
            $jobId = $this->jobManager->createJob($name, $apiKey, $query, $options);
            $this->jobManager->startJob($jobId);
            
            echo json_encode([
                'success' => true,
                'job_id' => $jobId
            ]);
        } catch (Exception $e) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'error' => $e->getMessage()
            ]);
        }
        exit;
    }
    
    private function handleStopJob($jobId) {
        header('Content-Type: application/json');
        
        try {
            if (empty($jobId)) {
                throw new Exception("Job ID is required");
            }
            
            $this->jobManager->stopJob($jobId);
            
            echo json_encode([
                'success' => true,
                'message' => 'Job stopped successfully'
            ]);
        } catch (Exception $e) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'error' => $e->getMessage()
            ]);
        }
        exit;
    }
    
    private function downloadEmails($jobId) {
        $job = $this->jobManager->getJob($jobId);
        if (!$job) {
            http_response_code(404);
            echo "Job not found";
            return;
        }
        
        $emails = $this->loadJobEmails($jobId);
        $jobName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $job['name']);
        $filename = "job_{$jobName}_emails_only_" . date('Y-m-d') . ".csv";
        
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Pragma: no-cache');
        header('Expires: 0');
        
        $output = fopen('php://output', 'w');
        fputcsv($output, ['Email']);
        
        foreach ($emails as $item) {
            fputcsv($output, [$item['email'] ?? '']);
        }
        
        fclose($output);
        exit;
    }
    
    private function downloadEmailsWithUrls($jobId) {
        $job = $this->jobManager->getJob($jobId);
        if (!$job) {
            http_response_code(404);
            echo "Job not found";
            return;
        }
        
        $emails = $this->loadJobEmails($jobId);
        $jobName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $job['name']);
        $filename = "job_{$jobName}_emails_with_urls_" . date('Y-m-d') . ".csv";
        
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Pragma: no-cache');
        header('Expires: 0');
        
        $output = fopen('php://output', 'w');
        fputcsv($output, ['Email', 'Source URL']);
        
        foreach ($emails as $item) {
            fputcsv($output, [
                $item['email'] ?? '',
                $item['source_url'] ?? ''
            ]);
        }
        
        fclose($output);
        exit;
    }
    
    private function downloadUrlsWithEmails($jobId) {
        $job = $this->jobManager->getJob($jobId);
        if (!$job) {
            http_response_code(404);
            echo "Job not found";
            return;
        }
        
        $emails = $this->loadJobEmails($jobId);
        $jobName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $job['name']);
        $filename = "job_{$jobName}_urls_with_emails_" . date('Y-m-d') . ".csv";
        
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Pragma: no-cache');
        header('Expires: 0');
        
        $output = fopen('php://output', 'w');
        fputcsv($output, ['Source URL', 'Email']);
        
        foreach ($emails as $item) {
            fputcsv($output, [
                $item['source_url'] ?? '',
                $item['email'] ?? ''
            ]);
        }
        
        fclose($output);
        exit;
    }
    
    private function downloadUrlsWithoutEmails($jobId) {
        $job = $this->jobManager->getJob($jobId);
        if (!$job) {
            http_response_code(404);
            echo "Job not found";
            return;
        }
        
        $emails = $this->loadJobEmails($jobId);
        $jobName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $job['name']);
        $filename = "job_{$jobName}_urls_only_" . date('Y-m-d') . ".csv";
        
        // Filter empty URLs first, then get unique URLs for better performance
        $urls = array_filter(array_map(function($item) {
            return $item['source_url'] ?? '';
        }, $emails));
        
        $urls = array_unique($urls);
        
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Pragma: no-cache');
        header('Expires: 0');
        
        $output = fopen('php://output', 'w');
        fputcsv($output, ['Source URL']);
        
        foreach ($urls as $url) {
            fputcsv($output, [$url]);
        }
        
        fclose($output);
        exit;
    }
    
    private function renderResultsPage($jobId) {
        $job = $this->jobManager->getJob($jobId);
        if (!$job) {
            echo "Job not found";
            return;
        }
        
        $page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
        $perPage = 50;
        
        $db = Database::getInstance();
        
        // Try loading from database with pagination
        if ($db->isConfigured()) {
            try {
                // Get total count
                $countStmt = $db->query(
                    "SELECT COUNT(*) as total FROM emails WHERE job_id = :job_id",
                    [':job_id' => $jobId]
                );
                $countResult = $countStmt->fetch();
                $total = (int)$countResult['total'];
                
                $totalPages = max(1, ceil($total / $perPage));
                $page = min($page, $totalPages);
                $offset = ($page - 1) * $perPage;
                
                // Get paginated emails with properly bound parameters
                $pdo = $db->getConnection();
                $stmt = $pdo->prepare(
                    "SELECT email, quality, confidence, source_url, UNIX_TIMESTAMP(created_at) as timestamp, worker_id 
                     FROM emails 
                     WHERE job_id = :job_id 
                     ORDER BY created_at DESC 
                     LIMIT :limit OFFSET :offset"
                );
                $stmt->bindValue(':job_id', $jobId, PDO::PARAM_STR);
                $stmt->bindValue(':limit', (int)$perPage, PDO::PARAM_INT);
                $stmt->bindValue(':offset', (int)$offset, PDO::PARAM_INT);
                $stmt->execute();
                
                $emailsPage = array_map(function($row) {
                    return [
                        'email' => $row['email'],
                        'quality' => $row['quality'],
                        'confidence' => (float)$row['confidence'],
                        'source_url' => $row['source_url'],
                        'timestamp' => (int)$row['timestamp'],
                        'worker_id' => $row['worker_id']
                    ];
                }, $stmt->fetchAll());
                
                $this->outputResultsHTML($job, $emailsPage, $page, $totalPages, $total);
                return;
            } catch (Exception $e) {
                Utils::logMessage('ERROR', "Failed to load emails from database: {$e->getMessage()}");
                // Fall through to file-based loading
            }
        }
        
        // Fallback to file-based loading
        $allEmails = $this->loadJobEmails($jobId);
        $total = count($allEmails);
        $totalPages = max(1, ceil($total / $perPage));
        $page = min($page, $totalPages);
        
        $offset = ($page - 1) * $perPage;
        $emailsPage = array_slice($allEmails, $offset, $perPage);
        
        $this->outputResultsHTML($job, $emailsPage, $page, $totalPages, $total);
    }
    
    private function outputResultsHTML($job, $emails, $page, $totalPages, $total) {
        ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Job Results - <?php echo htmlspecialchars($job['name']); ?></title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f5f7fa; padding: 20px; }
        .container { max-width: 1200px; margin: 0 auto; background: white; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); }
        .header { padding: 20px 30px; border-bottom: 1px solid #e5e7eb; }
        .back-btn { display: inline-block; color: #3b82f6; text-decoration: none; font-size: 14px; margin-bottom: 15px; }
        .back-btn:hover { text-decoration: underline; }
        .job-title { font-size: 24px; font-weight: 600; color: #1f2937; margin-bottom: 8px; }
        .job-meta { color: #6b7280; font-size: 14px; }
        .status-badge { display: inline-block; padding: 4px 12px; border-radius: 12px; font-size: 12px; font-weight: 500; margin-left: 10px; }
        .status-running { background: #d1fae5; color: #065f46; }
        .status-stopped { background: #fee2e2; color: #991b1b; }
        .actions { padding: 20px 30px; border-bottom: 1px solid #e5e7eb; }
        .export-btn { background: #3b82f6; color: white; padding: 10px 20px; border-radius: 6px; text-decoration: none; display: inline-block; font-size: 14px; font-weight: 500; }
        .export-btn:hover { background: #2563eb; }
        .results-table { width: 100%; }
        .results-table th { background: #f9fafb; padding: 12px 30px; text-align: left; font-size: 12px; font-weight: 600; color: #6b7280; text-transform: uppercase; letter-spacing: 0.05em; }
        .results-table td { padding: 16px 30px; border-top: 1px solid #e5e7eb; font-size: 14px; color: #374151; }
        .quality-badge { padding: 3px 10px; border-radius: 12px; font-size: 12px; font-weight: 500; display: inline-block; }
        .quality-high { background: #d1fae5; color: #065f46; }
        .quality-medium { background: #fef3c7; color: #92400e; }
        .quality-low { background: #fed7aa; color: #9a3412; }
        .url-cell { max-width: 300px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; color: #3b82f6; }
        .pagination { padding: 20px 30px; display: flex; justify-content: space-between; align-items: center; }
        .page-info { color: #6b7280; font-size: 14px; }
        .page-nav { display: flex; gap: 10px; }
        .page-btn { padding: 8px 16px; border-radius: 6px; text-decoration: none; font-size: 14px; background: #f3f4f6; color: #374151; }
        .page-btn:hover:not(.disabled) { background: #e5e7eb; }
        .page-btn.disabled { opacity: 0.5; pointer-events: none; }
        .no-results { padding: 60px 30px; text-align: center; color: #9ca3af; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <a href="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>" class="back-btn">← Back to Dashboard</a>
            <div class="job-title">
                <?php echo htmlspecialchars($job['name']); ?>
                <span class="status-badge status-<?php echo $job['status']; ?>">
                    <?php echo strtoupper($job['status']); ?>
                </span>
            </div>
            <div class="job-meta">
                <?php echo $total; ?> emails extracted
                <?php if ($job['status'] === 'running'): ?>
                    • Currently running
                <?php endif; ?>
            </div>
        </div>
        
        <div class="actions">
            <a href="?export=csv&job_id=<?php echo urlencode($job['id']); ?>" class="export-btn">
                📥 Export to CSV
            </a>
        </div>
        
        <?php if (empty($emails)): ?>
            <div class="no-results">
                <p>No emails extracted yet. Workers are still processing...</p>
            </div>
        <?php else: ?>
            <table class="results-table">
                <thead>
                    <tr>
                        <th>Email</th>
                        <th>Quality</th>
                        <th>Source URL</th>
                        <th>Timestamp</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($emails as $item): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($item['email'] ?? ''); ?></td>
                            <td>
                                <span class="quality-badge quality-<?php echo strtolower($item['quality'] ?? 'medium'); ?>">
                                    <?php echo ucfirst($item['quality'] ?? 'Medium'); ?>
                                </span>
                            </td>
                            <td class="url-cell" title="<?php echo htmlspecialchars($item['source_url'] ?? ''); ?>">
                                <?php echo htmlspecialchars($item['source_url'] ?? ''); ?>
                            </td>
                            <td><?php echo date('m/d/Y H:i', $item['timestamp'] ?? time()); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            
            <div class="pagination">
                <div class="page-info">
                    Page <?php echo $page; ?> of <?php echo $totalPages; ?> (<?php echo $total; ?> total)
                </div>
                <div class="page-nav">
                    <?php if ($page > 1): ?>
                        <a href="?view=results&job_id=<?php echo urlencode($job['id']); ?>&page=<?php echo $page - 1; ?>" class="page-btn">← Previous</a>
                    <?php else: ?>
                        <span class="page-btn disabled">← Previous</span>
                    <?php endif; ?>
                    
                    <?php if ($page < $totalPages): ?>
                        <a href="?view=results&job_id=<?php echo urlencode($job['id']); ?>&page=<?php echo $page + 1; ?>" class="page-btn">Next →</a>
                    <?php else: ?>
                        <span class="page-btn disabled">Next →</span>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
        <?php
    }
    
    private function outputHTML() {
        ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Email Extraction System - v<?php echo Config::VERSION; ?></title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.js"></script>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            background: #f7f9fc;
            color: #1a1a1a;
        }
        
        .container {
            display: flex;
            min-height: 100vh;
        }
        
        .sidebar {
            width: 280px;
            background: #fff;
            border-right: 1px solid #e1e4e8;
            padding: 20px;
            position: fixed;
            height: 100vh;
            overflow-y: auto;
        }
        
        .sidebar h1 {
            font-size: 20px;
            margin-bottom: 10px;
            color: #0066ff;
        }
        
        .sidebar .version {
            font-size: 12px;
            color: #666;
            margin-bottom: 30px;
        }
        
        .sidebar-section {
            margin-bottom: 30px;
        }
        
        .sidebar-section h3 {
            font-size: 14px;
            font-weight: 600;
            margin-bottom: 15px;
            text-transform: uppercase;
            color: #666;
        }
        
        .form-group {
            margin-bottom: 15px;
        }
        
        .form-group label {
            display: block;
            font-size: 13px;
            font-weight: 500;
            margin-bottom: 5px;
            color: #333;
        }
        
        .form-group input,
        .form-group textarea,
        .form-group select {
            width: 100%;
            padding: 10px;
            border: 1px solid #d1d5da;
            border-radius: 6px;
            font-size: 14px;
            font-family: inherit;
            transition: border-color 0.2s;
        }
        
        .form-group input:focus,
        .form-group textarea:focus,
        .form-group select:focus {
            outline: none;
            border-color: #0066ff;
            box-shadow: 0 0 0 3px rgba(0, 102, 255, 0.1);
        }
        
        .form-group textarea {
            resize: vertical;
            min-height: 80px;
        }
        
        .form-group small {
            display: block;
            margin-top: 5px;
            font-size: 12px;
        }
        
        .btn {
            width: 100%;
            padding: 12px;
            background: #0066ff;
            color: white;
            border: none;
            border-radius: 6px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.2s;
        }
        
        .btn:hover {
            background: #0052cc;
        }
        
        .btn:disabled {
            background: #ccc;
            cursor: not-allowed;
        }
        
        .btn-secondary {
            background: #6c757d;
        }
        
        .btn-secondary:hover {
            background: #5a6268;
        }
        
        .stats {
            background: #f6f8fa;
            padding: 15px;
            border-radius: 6px;
            font-size: 13px;
        }
        
        .stats-item {
            display: flex;
            justify-content: space-between;
            margin-bottom: 8px;
        }
        
        .stats-item:last-child {
            margin-bottom: 0;
        }
        
        .stats-label {
            color: #666;
        }
        
        .stats-value {
            font-weight: 600;
            color: #0066ff;
        }
        
        .create-job-panel {
            background: white;
            border: 1px solid #e1e4e8;
            border-radius: 8px;
            padding: 25px;
            margin-bottom: 30px;
        }
        
        .create-job-panel h3 {
            font-size: 20px;
            margin-bottom: 20px;
            color: #1a1a1a;
        }
        
        .form-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 15px;
        }
        
        .form-grid .form-group {
            margin-bottom: 0;
        }
        
        .form-actions {
            margin-top: 20px;
            display: flex;
            gap: 10px;
            justify-content: flex-end;
        }
        
        .chart-container {
            background: white;
            border: 1px solid #e1e4e8;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 30px;
        }
        
        .chart-container h3 {
            font-size: 18px;
            margin-bottom: 15px;
            color: #1a1a1a;
        }
        
        .chart-wrapper {
            position: relative;
            height: 300px;
        }
        
        .download-menu {
            position: relative;
            display: inline-block;
        }
        
        .download-btn {
            background: #28a745;
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 6px;
            font-size: 13px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .download-btn:hover {
            background: #218838;
        }
        
        .download-dropdown {
            display: none;
            position: absolute;
            top: 100%;
            right: 0;
            margin-top: 5px;
            background: white;
            border: 1px solid #e1e4e8;
            border-radius: 6px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
            z-index: 1000;
            min-width: 220px;
        }
        
        .download-dropdown.show {
            display: block;
        }
        
        .download-dropdown a {
            display: block;
            padding: 10px 15px;
            color: #1a1a1a;
            text-decoration: none;
            font-size: 13px;
            transition: background 0.2s;
        }
        
        .download-dropdown a:hover {
            background: #f6f8fa;
        }
        
        .download-dropdown a:first-child {
            border-radius: 6px 6px 0 0;
        }
        
        .download-dropdown a:last-child {
            border-radius: 0 0 6px 6px;
        }
        
        @media (max-width: 768px) {
            .container {
                flex-direction: column;
            }
            
            .sidebar {
                position: relative;
                width: 100%;
                height: auto;
                border-right: none;
                border-bottom: 1px solid #e1e4e8;
            }
            
            .main-content {
                margin-left: 0;
            }
            
            .form-grid {
                grid-template-columns: 1fr;
            }
        }
        
        .main-content {
            margin-left: 280px;
            flex: 1;
            padding: 30px;
        }
        
        .header {
            margin-bottom: 30px;
        }
        
        .header h2 {
            font-size: 28px;
            margin-bottom: 10px;
        }
        
        .header p {
            color: #666;
            font-size: 14px;
        }
        
        .job-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .job-card {
            background: white;
            border: 1px solid #e1e4e8;
            border-radius: 8px;
            padding: 20px;
            transition: box-shadow 0.2s;
        }
        
        .job-card:hover {
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
        }
        
        .job-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 15px;
        }
        
        .job-title {
            font-size: 18px;
            font-weight: 600;
            color: #1a1a1a;
        }
        
        .job-title-link {
            text-decoration: none;
            color: inherit;
        }
        
        .job-title-link:hover .job-title {
            color: #3b82f6;
            text-decoration: underline;
        }
        
        .job-status {
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .job-status.running {
            background: #d4edda;
            color: #155724;
        }
        
        .job-status.stopped {
            background: #f8d7da;
            color: #721c24;
        }
        
        .job-status.completed {
            background: #cfe2ff;
            color: #084298;
        }
        
        .job-status.created {
            background: #d1ecf1;
            color: #0c5460;
        }
        
        .job-status.error {
            background: #f8d7da;
            color: #721c24;
            animation: pulse 2s infinite;
        }
        
        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.7; }
        }
        
        .job-errors {
            background: #fff3cd;
            border: 1px solid #ffc107;
            border-radius: 6px;
            padding: 12px;
            margin-bottom: 15px;
        }
        
        .error-item {
            display: flex;
            gap: 10px;
            padding: 5px 0;
            font-size: 12px;
            border-bottom: 1px solid #ffe69c;
        }
        
        .error-item:last-child {
            border-bottom: none;
        }
        
        .error-time {
            color: #856404;
            font-weight: 600;
            white-space: nowrap;
        }
        
        .error-message {
            color: #856404;
            flex: 1;
        }
        
        .progress-section {
            margin: 15px 0;
            padding: 15px;
            background: #f8f9fa;
            border-radius: 8px;
            border: 1px solid #e9ecef;
        }
        
        .progress-item {
            margin-bottom: 8px;
        }
        
        .progress-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 8px;
        }
        
        .progress-label {
            font-size: 13px;
            font-weight: 600;
            color: #24292e;
        }
        
        .progress-percentage {
            font-size: 13px;
            font-weight: 700;
            color: #0066ff;
        }
        
        .progress-bar-container {
            width: 100%;
            height: 24px;
            background: #e9ecef;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: inset 0 1px 3px rgba(0,0,0,0.1);
        }
        
        .progress-bar {
            height: 100%;
            transition: width 0.3s ease;
            border-radius: 12px;
            position: relative;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        .progress-bar::after {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: linear-gradient(90deg, 
                rgba(255,255,255,0.2) 0%, 
                rgba(255,255,255,0.1) 50%, 
                rgba(255,255,255,0.2) 100%);
            animation: shimmer 2s infinite;
        }
        
        @keyframes shimmer {
            0% {
                transform: translateX(-100%);
            }
            100% {
                transform: translateX(100%);
            }
        }
        
        .progress-details {
            font-size: 12px;
            color: #666;
            margin-top: 6px;
            text-align: center;
        }
        
        .job-info {
            margin-bottom: 15px;
            font-size: 13px;
            color: #666;
        }
        
        .job-info-item {
            margin-bottom: 8px;
        }
        
        .job-metrics {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 10px;
            margin-bottom: 15px;
        }
        
        .metric {
            text-align: center;
            padding: 10px;
            background: #f6f8fa;
            border-radius: 6px;
        }
        
        .metric-value {
            font-size: 20px;
            font-weight: 600;
            color: #0066ff;
        }
        
        .metric-label {
            font-size: 11px;
            color: #666;
            text-transform: uppercase;
            margin-top: 4px;
        }
        
        .job-actions {
            display: flex;
            gap: 10px;
        }
        
        .job-actions button {
            flex: 1;
            padding: 8px;
            border: 1px solid #d1d5da;
            background: white;
            border-radius: 6px;
            font-size: 13px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .job-actions button:hover {
            background: #f6f8fa;
        }
        
        .job-actions button.primary {
            background: #0066ff;
            color: white;
            border-color: #0066ff;
        }
        
        .job-actions button.primary:hover {
            background: #0052cc;
        }
        
        .job-actions button.danger {
            background: #dc3545;
            color: white;
            border-color: #dc3545;
        }
        
        .job-actions button.danger:hover {
            background: #c82333;
        }
        
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            background: white;
            border: 2px dashed #d1d5da;
            border-radius: 8px;
        }
        
        .empty-state h3 {
            font-size: 20px;
            margin-bottom: 10px;
            color: #666;
        }
        
        .empty-state p {
            color: #999;
            font-size: 14px;
        }
        
        .alert {
            padding: 15px 20px;
            border-radius: 6px;
            margin-bottom: 20px;
            font-size: 14px;
        }
        
        .alert-success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        
        .alert-error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        
        .worker-list {
            margin-top: 10px;
            padding: 10px;
            background: #f6f8fa;
            border-radius: 6px;
            font-size: 12px;
        }
        
        .worker-item {
            padding: 5px 0;
            border-bottom: 1px solid #e1e4e8;
        }
        
        .worker-item:last-child {
            border-bottom: none;
        }
        
        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.5; }
        }
        
        .pulse {
            animation: pulse 2s infinite;
        }
        
        .db-status {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 10px;
            background: #f6f8fa;
            border-radius: 6px;
            margin-top: 15px;
            font-size: 12px;
        }
        
        .db-indicator {
            width: 10px;
            height: 10px;
            border-radius: 50%;
        }
        
        .db-indicator.connected {
            background: #28a745;
            box-shadow: 0 0 8px rgba(40, 167, 69, 0.6);
        }
        
        .db-indicator.disconnected {
            background: #dc3545;
        }
        
        .db-status-text {
            flex: 1;
            color: #666;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="sidebar">
            <h1>📧 Email Extractor</h1>
            <div class="version">Version <?php echo Config::VERSION; ?></div>
            
            <?php 
            $db = Database::getInstance();
            $dbConfigured = $db->isConfigured();
            ?>
            
            <?php if (!$dbConfigured): ?>
                <div style="background: #fff3cd; border: 1px solid #ffc107; padding: 15px; border-radius: 6px; margin: 15px 0; font-size: 13px;">
                    <strong>⚠️ Database Not Configured</strong><br>
                    <p style="margin-top: 8px; color: #856404;">
                        Please run <a href="install.php" style="color: #0066ff; text-decoration: underline;">install.php</a> to set up MySQL database for persistent email storage.
                    </p>
                </div>
            <?php endif; ?>
            
            <div class="sidebar-section">
                <h3>System Stats</h3>
                <div class="stats">
                    <div class="stats-item">
                        <span class="stats-label">Total Jobs</span>
                        <span class="stats-value" id="stat-total-jobs">0</span>
                    </div>
                    <div class="stats-item">
                        <span class="stats-label">Running</span>
                        <span class="stats-value" id="stat-running-jobs">0</span>
                    </div>
                    <div class="stats-item">
                        <span class="stats-label">Total Emails</span>
                        <span class="stats-value" id="stat-total-emails">0</span>
                    </div>
                    <div class="stats-item">
                        <span class="stats-label">Memory</span>
                        <span class="stats-value" id="stat-memory">0 MB</span>
                    </div>
                </div>
                <div class="db-status">
                    <div class="db-indicator <?php echo $dbConfigured ? 'connected' : 'disconnected'; ?>"></div>
                    <div class="db-status-text">
                        <?php if ($dbConfigured): ?>
                            <strong>Database:</strong> MySQL Connected
                        <?php else: ?>
                            <strong>Database:</strong> File Storage (Temporary)
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <div class="sidebar-section">
                <h3>API Settings</h3>
                <div class="form-group">
                    <label for="apiKey">Serper API Key</label>
                    <input type="password" id="apiKey" name="api_key" placeholder="Enter your API key">
                </div>
                <button id="testConnectionBtn" class="btn btn-secondary">Test Connection</button>
                <div id="connectionStatus" style="margin-top: 10px; display: none;"></div>
            </div>
        </div>
        
        <div class="main-content">
            <div class="header">
                <h2>Email Extraction Dashboard</h2>
                <p>Create jobs and monitor email extraction in real-time</p>
            </div>
            
            <div id="alertContainer"></div>
            
            <!-- Create Job Panel (TOP) -->
            <div class="create-job-panel">
                <h3>🚀 Create New Job</h3>
                <form id="createJobForm">
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="jobName">Job Name *</label>
                            <input type="text" id="jobName" name="name" placeholder="My Email Extraction Job" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="query">Main Search Query *</label>
                            <input type="text" id="query" name="query" placeholder="e.g., real estate agents" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="keywords">Keywords (one per line)</label>
                            <textarea id="keywords" name="keywords" placeholder="california&#10;los angeles" rows="3"></textarea>
                        </div>
                        
                        <div class="form-group">
                            <label for="emailTypes">Email Type</label>
                            <select id="emailTypes" name="email_types" multiple style="height: 60px;">
                                <option value="gmail">Gmail</option>
                                <option value="yahoo">Yahoo</option>
                                <option value="outlook">Outlook/Hotmail</option>
                                <option value="att">AT&T</option>
                                <option value="sbcglobal">SBCGlobal</option>
                                <option value="bellsouth">BellSouth</option>
                                <option value="aol">AOL</option>
                                <option value="business">Business Domains</option>
                            </select>
                            <small style="color: #666;">Hold Ctrl/Cmd to select multiple</small>
                        </div>
                        
                        <div class="form-group">
                            <label for="country">Location</label>
                            <select id="country" name="country">
                                <option value="">Worldwide</option>
                                <option value="us">United States</option>
                                <option value="uk">United Kingdom</option>
                                <option value="ca">Canada</option>
                                <option value="au">Australia</option>
                                <option value="de">Germany</option>
                                <option value="fr">France</option>
                                <option value="es">Spain</option>
                                <option value="it">Italy</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="maxWorkers">Requested Workers</label>
                            <input type="number" id="maxWorkers" name="max_workers" value="10" min="1" max="200">
                        </div>
                        
                        <div class="form-group">
                            <label for="targetEmails">Target Emails</label>
                            <input type="number" id="targetEmails" name="target_emails" value="10000" min="100" max="1000000" step="100">
                        </div>
                    </div>
                    
                    <div class="form-actions">
                        <button type="submit" class="btn">Create Job</button>
                    </div>
                </form>
            </div>
            
            <!-- Email Rate Chart -->
            <div class="chart-container" style="display: none;" id="chartContainer">
                <h3>📈 Emails Processed Per Minute</h3>
                <div class="chart-wrapper">
                    <canvas id="emailRateChart"></canvas>
                </div>
            </div>
            
            <!-- Multi Jobs Dashboard (BELOW) -->
            <div>
                <h3 style="margin-bottom: 20px; font-size: 20px;">Active Jobs</h3>
                <div id="jobContainer" class="job-grid">
                    <div class="empty-state">
                        <h3>No Jobs Yet</h3>
                        <p>Create your first job using the form above</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        // API Client
        const API = {
            async call(action, data = {}) {
                const formData = new FormData();
                formData.append('action', action);
                
                for (const [key, value] of Object.entries(data)) {
                    formData.append(key, value);
                }
                
                const response = await fetch(window.location.pathname, {
                    method: 'POST',
                    body: formData
                });
                
                return await response.json();
            },
            
            async get(action, params = {}) {
                const queryString = new URLSearchParams({action, ...params}).toString();
                const response = await fetch(`${window.location.pathname}?${queryString}`);
                return await response.json();
            }
        };
        
        // UI Controller
        const UI = {
            showAlert(message, type = 'success') {
                const alertContainer = document.getElementById('alertContainer');
                const alert = document.createElement('div');
                alert.className = `alert alert-${type}`;
                alert.textContent = message;
                alertContainer.appendChild(alert);
                
                setTimeout(() => alert.remove(), 5000);
            },
            
            updateStats(stats) {
                document.getElementById('stat-total-jobs').textContent = stats.total_jobs;
                document.getElementById('stat-running-jobs').textContent = stats.running_jobs;
                document.getElementById('stat-total-emails').textContent = stats.total_emails.toLocaleString();
                document.getElementById('stat-memory').textContent = stats.memory_usage;
            },
            
            renderJobs(jobs) {
                const container = document.getElementById('jobContainer');
                
                if (jobs.length === 0) {
                    container.innerHTML = `
                        <div class="empty-state">
                            <h3>No Jobs Yet</h3>
                            <p>Create your first job using the form above</p>
                        </div>
                    `;
                    return;
                }
                
                container.innerHTML = jobs.map(job => this.renderJob(job)).join('');
            },
            
            renderJob(job) {
                const statusClass = job.status;
                const workerStats = job.worker_stats || {total: 0, running: 0};
                const acceptedEmails = job.emails_accepted || 0;
                const rejectedEmails = job.emails_rejected || 0;
                const totalFound = acceptedEmails + rejectedEmails;
                const acceptRate = totalFound > 0 ? ((acceptedEmails / totalFound) * 100).toFixed(1) : 0;
                
                // Calculate emails per hour
                const runtime = job.started_at ? ((Date.now() / 1000) - job.started_at) / 3600 : 0;
                const emailsPerHour = runtime > 0 ? Math.round(acceptedEmails / runtime) : 0;
                
                // Calculate progress percentages
                const targetEmails = job.target_emails || job.options?.target_emails || 10000;
                const emailProgress = Math.min(100, (acceptedEmails / targetEmails) * 100).toFixed(1);
                const maxWorkers = job.options?.max_workers || 10;
                const workerProgress = Math.min(100, (workerStats.running / maxWorkers) * 100).toFixed(1);
                
                // Get recent errors
                const errorMessages = job.error_messages || [];
                const recentErrors = errorMessages.slice(-3); // Show last 3 errors
                
                return `
                    <div class="job-card" data-job-id="${job.id}">
                        <div class="job-header">
                            <a href="?view=results&job_id=${job.id}" class="job-title-link">
                                <div class="job-title">${this.escapeHtml(job.name)}</div>
                            </a>
                            <div class="job-status ${statusClass}">${job.status}</div>
                        </div>
                        
                        ${recentErrors.length > 0 ? `
                            <div class="job-errors">
                                <strong style="color: #dc3545;">⚠ Recent Errors:</strong>
                                ${recentErrors.map(err => `
                                    <div class="error-item">
                                        <span class="error-time">${new Date(err.time * 1000).toLocaleTimeString()}</span>
                                        <span class="error-message">${this.escapeHtml(err.message)}</span>
                                    </div>
                                `).join('')}
                            </div>
                        ` : ''}
                        
                        ${job.status === 'running' ? `
                            <div class="progress-section">
                                <div class="progress-item">
                                    <div class="progress-header">
                                        <span class="progress-label">📊 Email Collection Progress</span>
                                        <span class="progress-percentage">${emailProgress}%</span>
                                    </div>
                                    <div class="progress-bar-container">
                                        <div class="progress-bar" style="width: ${emailProgress}%; background: linear-gradient(90deg, #28a745, #20c997);"></div>
                                    </div>
                                    <div class="progress-details">${acceptedEmails.toLocaleString()} / ${targetEmails.toLocaleString()} emails</div>
                                </div>
                                
                                <div class="progress-item" style="margin-top: 12px;">
                                    <div class="progress-header">
                                        <span class="progress-label">👷 Active Workers</span>
                                        <span class="progress-percentage">${workerProgress}%</span>
                                    </div>
                                    <div class="progress-bar-container">
                                        <div class="progress-bar" style="width: ${workerProgress}%; background: linear-gradient(90deg, #007bff, #0056b3);"></div>
                                    </div>
                                    <div class="progress-details">${workerStats.running} / ${maxWorkers} workers active</div>
                                </div>
                            </div>
                        ` : ''}
                        
                        <div class="job-info">
                            <div class="job-info-item">
                                <strong>Query:</strong> ${this.escapeHtml(job.query)}
                            </div>
                            ${job.options && job.options.country ? `
                                <div class="job-info-item">
                                    <strong>Target:</strong> ${job.options.country.toUpperCase()} / ${job.options.language.toUpperCase()}
                                </div>
                            ` : ''}
                            <div class="job-info-item">
                                <strong>Created:</strong> ${new Date(job.created_at * 1000).toLocaleString()}
                            </div>
                            ${job.status === 'running' ? `
                                <div class="job-info-item">
                                    <strong>Rate:</strong> ${emailsPerHour} emails/hour
                                </div>
                            ` : ''}
                        </div>
                        
                        <div class="job-metrics">
                            <div class="metric">
                                <div class="metric-value" style="color: #28a745;">${acceptedEmails.toLocaleString()}</div>
                                <div class="metric-label">✓ Accepted</div>
                            </div>
                            <div class="metric">
                                <div class="metric-value" style="color: #dc3545;">${rejectedEmails.toLocaleString()}</div>
                                <div class="metric-label">✗ Rejected</div>
                            </div>
                            <div class="metric">
                                <div class="metric-value">${acceptRate}%</div>
                                <div class="metric-label">Accept Rate</div>
                            </div>
                        </div>
                        
                        <div class="job-metrics" style="margin-top: 10px;">
                            <div class="metric">
                                <div class="metric-value">${job.urls_processed.toLocaleString()}</div>
                                <div class="metric-label">URLs</div>
                            </div>
                            <div class="metric">
                                <div class="metric-value">${job.errors}</div>
                                <div class="metric-label">Errors</div>
                            </div>
                            <div class="metric">
                                <div class="metric-value">${emailsPerHour}</div>
                                <div class="metric-label">Per Hour</div>
                            </div>
                        </div>
                        
                        <div class="job-actions">
                            ${job.status === 'running' ? `
                                <button onclick="JobController.stopJob('${job.id}')" class="danger">Stop</button>
                            ` : job.status === 'error' ? `
                                <button onclick="JobController.startJob('${job.id}')" class="primary">Retry</button>
                            ` : job.status === 'stopped' || job.status === 'created' ? `
                                <button onclick="JobController.startJob('${job.id}')" class="primary">Start</button>
                            ` : ''}
                            
                            ${acceptedEmails > 0 ? `
                                <div class="download-menu">
                                    <button class="download-btn" onclick="toggleDownload('${job.id}')">📥 Download</button>
                                    <div class="download-dropdown" id="download-${job.id}">
                                        <a href="?mode=download_emails&job_id=${job.id}">Download Emails</a>
                                        <a href="?mode=download_emails_with_urls&job_id=${job.id}">Download Emails with URLs</a>
                                        <a href="?mode=download_urls_with_emails&job_id=${job.id}">Download URLs with Emails</a>
                                        <a href="?mode=download_urls_without_emails&job_id=${job.id}">Download URLs without Emails</a>
                                    </div>
                                </div>
                            ` : ''}
                            
                            ${job.status === 'completed' || job.status === 'stopped' ? `
                                <button onclick="JobController.deleteJob('${job.id}')" class="danger">Delete</button>
                            ` : ''}
                        </div>
                    </div>
                `;
            },
            
            escapeHtml(text) {
                const div = document.createElement('div');
                div.textContent = text;
                return div.innerHTML;
            }
        };
        
        // Job Controller
        const JobController = {
            async createJob(formData) {
                try {
                    const result = await API.call('create_job', formData);
                    
                    if (result.success) {
                        UI.showAlert('Job created successfully!');
                        document.getElementById('createJobForm').reset();
                        this.refreshJobs();
                    } else {
                        UI.showAlert(result.error || 'Failed to create job', 'error');
                    }
                } catch (error) {
                    UI.showAlert('Error: ' + error.message, 'error');
                }
            },
            
            async startJob(jobId) {
                try {
                    // Get API key from localStorage
                    const apiKey = localStorage.getItem('serper_api_key') || document.getElementById('apiKey').value;
                    
                    if (!apiKey) {
                        UI.showAlert('Please enter your API key first', 'error');
                        return;
                    }
                    
                    const result = await API.call('start_job', {
                        job_id: jobId,
                        api_key: apiKey
                    });
                    
                    if (result.success) {
                        UI.showAlert('Job started successfully!');
                        this.refreshJobs();
                    } else {
                        UI.showAlert(result.error || 'Failed to start job', 'error');
                    }
                } catch (error) {
                    UI.showAlert('Error: ' + error.message, 'error');
                }
            },
            
            async stopJob(jobId) {
                try {
                    const result = await API.call('stop_job', {job_id: jobId});
                    
                    if (result.success) {
                        UI.showAlert('Job stopped successfully!');
                        this.refreshJobs();
                    } else {
                        UI.showAlert(result.error || 'Failed to stop job', 'error');
                    }
                } catch (error) {
                    UI.showAlert('Error: ' + error.message, 'error');
                }
            },
            
            async deleteJob(jobId) {
                if (!confirm('Are you sure you want to delete this job?')) {
                    return;
                }
                
                try {
                    const result = await API.call('delete_job', {job_id: jobId});
                    
                    if (result.success) {
                        UI.showAlert('Job deleted successfully!');
                        this.refreshJobs();
                    } else {
                        UI.showAlert(result.error || 'Failed to delete job', 'error');
                    }
                } catch (error) {
                    UI.showAlert('Error: ' + error.message, 'error');
                }
            },
            
            async refreshJobs() {
                try {
                    const [jobsResult, statsResult] = await Promise.all([
                        API.get('get_jobs'),
                        API.get('get_stats')
                    ]);
                    
                    if (jobsResult.success) {
                        UI.renderJobs(jobsResult.jobs);
                    }
                    
                    if (statsResult.success) {
                        UI.updateStats(statsResult.stats);
                    }
                } catch (error) {
                    console.error('Failed to refresh jobs:', error);
                }
            }
        };
        
        // API Key persistence
        const apiKeyInput = document.getElementById('apiKey');
        
        // Load saved API key from localStorage
        const savedApiKey = localStorage.getItem('serper_api_key');
        if (savedApiKey) {
            apiKeyInput.value = savedApiKey;
        }
        
        // Save API key to localStorage when it changes
        apiKeyInput.addEventListener('change', () => {
            const apiKey = apiKeyInput.value.trim();
            if (apiKey) {
                localStorage.setItem('serper_api_key', apiKey);
            }
        });
        
        // Initialize
        // Test Connection Button
        document.getElementById('testConnectionBtn').addEventListener('click', async () => {
            const apiKey = document.getElementById('apiKey').value;
            if (!apiKey) {
                UI.showAlert('Please enter an API key first', 'error');
                return;
            }
            
            // Save API key when testing
            localStorage.setItem('serper_api_key', apiKey);
            
            const btn = document.getElementById('testConnectionBtn');
            const status = document.getElementById('connectionStatus');
            
            btn.disabled = true;
            btn.textContent = 'Testing...';
            
            try {
                const result = await API.call('test_connection', { api_key: apiKey });
                
                if (result.success) {
                    status.style.display = 'block';
                    status.style.color = '#28a745';
                    status.innerHTML = '✓ Connection successful!';
                    UI.showAlert('API connection successful!', 'success');
                } else {
                    status.style.display = 'block';
                    status.style.color = '#dc3545';
                    status.innerHTML = '✗ Connection failed: ' + result.message;
                    UI.showAlert('API connection failed', 'error');
                }
            } catch (error) {
                status.style.display = 'block';
                status.style.color = '#dc3545';
                status.innerHTML = '✗ Connection failed: ' + error.message;
                UI.showAlert('Error testing connection', 'error');
            } finally {
                btn.disabled = false;
                btn.textContent = 'Test Connection';
            }
        });
        
        // Create Job Form
        document.getElementById('createJobForm').addEventListener('submit', async (e) => {
            e.preventDefault();
            
            const apiKey = document.getElementById('apiKey').value;
            
            if (!apiKey) {
                UI.showAlert('Please enter your Serper API key first', 'error');
                return;
            }
            
            // Save API key to localStorage
            localStorage.setItem('serper_api_key', apiKey);
            
            // Collect selected email types from multi-select
            const emailTypeSelect = document.getElementById('emailTypes');
            const selectedEmailTypes = Array.from(emailTypeSelect.selectedOptions).map(opt => opt.value);
            
            const formData = {
                name: document.getElementById('jobName').value,
                api_key: apiKey,
                query: document.getElementById('query').value,
                keywords: document.getElementById('keywords').value,
                country: document.getElementById('country').value,
                language: 'en',
                email_types: selectedEmailTypes.join(','),
                target_emails: document.getElementById('targetEmails').value,
                max_workers: document.getElementById('maxWorkers').value
            };
            
            await JobController.createJob(formData);
        });
        
        // Download dropdown toggle
        window.toggleDownload = function(jobId) {
            const dropdown = document.getElementById('download-' + jobId);
            dropdown.classList.toggle('show');
            
            // Close when clicking outside
            setTimeout(() => {
                document.addEventListener('click', function closeDropdown(e) {
                    if (!e.target.closest('.download-menu')) {
                        dropdown.classList.remove('show');
                        document.removeEventListener('click', closeDropdown);
                    }
                });
            }, 10);
        };
        
        // Chart.js initialization
        let emailRateChart = null;
        let chartData = {
            labels: [],
            datasets: [{
                label: 'Emails/Minute',
                data: [],
                borderColor: '#0066ff',
                backgroundColor: 'rgba(0, 102, 255, 0.1)',
                fill: true,
                tension: 0.4
            }]
        };
        
        async function updateChart() {
            const jobs = await API.get('get_jobs');
            if (!jobs.success || jobs.jobs.length === 0) {
                document.getElementById('chartContainer').style.display = 'none';
                return;
            }
            
            // Find first running job to display
            // Note: Currently shows only the first running job. For multiple simultaneous jobs,
            // consider adding a job selector dropdown or aggregating data from all running jobs.
            const runningJob = jobs.jobs.find(j => j.status === 'running');
            if (!runningJob) {
                document.getElementById('chartContainer').style.display = 'none';
                return;
            }
            
            // Show chart container
            document.getElementById('chartContainer').style.display = 'block';
            
            // Get email rate data
            const rateData = await API.get('get_email_rate', { job_id: runningJob.id });
            if (rateData.success && rateData.emails_per_minute.length > 0) {
                chartData.labels = rateData.emails_per_minute.map(d => {
                    const date = new Date(d.timestamp * 1000);
                    return date.toLocaleTimeString();
                });
                chartData.datasets[0].data = rateData.emails_per_minute.map(d => d.count);
                
                if (!emailRateChart) {
                    const ctx = document.getElementById('emailRateChart').getContext('2d');
                    emailRateChart = new Chart(ctx, {
                        type: 'line',
                        data: chartData,
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            plugins: {
                                legend: {
                                    display: true,
                                    position: 'top'
                                }
                            },
                            scales: {
                                y: {
                                    beginAtZero: true,
                                    ticks: {
                                        precision: 0
                                    }
                                }
                            }
                        }
                    });
                } else {
                    emailRateChart.data = chartData;
                    emailRateChart.update();
                }
            }
        }
        
        // Auto-refresh every 5 seconds
        setInterval(() => {
            JobController.refreshJobs();
            updateChart();
        }, 5000);
        
        // Initial load
        JobController.refreshJobs();
        updateChart();
    </script>
</body>
</html>
        <?php
    }
}

// CLI Mode
if (php_sapi_name() === 'cli') {
    $app = new Application();
    
    // Continuous operation mode
    echo "Email Extraction System - Starting...\n";
    
    while (true) {
        try {
            $app->runBackgroundTasks();
            sleep(5); // Check every 5 seconds
        } catch (Exception $e) {
            Utils::logMessage('ERROR', "Application error: {$e->getMessage()}");
            sleep(10); // Wait before retry
        }
    }
} else {
    // Web Mode
    $app = new Application();
    $app->run();
}
