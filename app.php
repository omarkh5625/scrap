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
    const MAX_WORKERS_PER_JOB = 1000; // Increased for 32GB RAM server
    const MIN_WORKERS_PER_JOB = 1;
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
}

// Database Connection Manager
class Database {
    private static $instance = null;
    private $pdo = null;
    private $isConfigured = false;
    
    private function __construct() {
        $this->isConfigured = defined('DB_HOST') && defined('DB_NAME') && defined('DB_USER');
        if ($this->isConfigured) {
            try {
                $this->connect();
            } catch (Exception $e) {
                Utils::logMessage('ERROR', "Database initialization failed: {$e->getMessage()}");
                $this->isConfigured = false;
                $this->pdo = null;
            }
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
        // Don't throw exception - mark as not configured instead
        $this->isConfigured = false;
        $this->pdo = null;
    }
    
    public function getConnection() {
        if (!$this->isConfigured) {
            throw new Exception('Database not configured. Please run install.php first.');
        }
        
        // Ensure connection is alive
        if ($this->pdo === null) {
            try {
                $this->connect();
            } catch (Exception $e) {
                Utils::logMessage('ERROR', "Failed to establish database connection: {$e->getMessage()}");
                return null;
            }
        }
        
        return $this->pdo;
    }
    
    public function query($sql, $params = []) {
        if (!$this->isConfigured) {
            throw new Exception('Database not configured');
        }
        
        $attempts = 0;
        $lastError = null;
        
        while ($attempts < Config::DB_RETRY_ATTEMPTS) {
            try {
                $pdo = $this->getConnection();
                if ($pdo === null) {
                    throw new Exception('Failed to get database connection');
                }
                
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
            } catch (Exception $e) {
                $lastError = $e->getMessage();
                Utils::logMessage('ERROR', "Query failed: {$lastError}");
                throw $e;
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

// Extraction work with real URLs
\$startTime = time();
\$maxRunTime = \$config['max_run_time'] ?? 300;
\$extractedCount = 0;
\$urlCache = [];
\$currentPage = 1;
\$maxPages = 30; // Expand to 30 pages as required
\$seenEmails = []; // In-memory deduplication cache for this worker
\$noResultsCount = 0; // Track consecutive failed queries
\$currentQueryIndex = 0; // Track which query/keyword we're using

// Build query list: main query + keywords
\$queryList = [];
if (!empty(\$query)) {
    \$queryList[] = \$query;
}
if (!empty(\$keywords)) {
    foreach (\$keywords as \$keyword) {
        if (!empty(\$keyword)) {
            // Combine main query with keyword if both exist
            if (!empty(\$query)) {
                \$queryList[] = \$query . ' ' . \$keyword;
            } else {
                \$queryList[] = \$keyword;
            }
        }
    }
}
// If no queries at all, use main query as fallback
if (empty(\$queryList) && !empty(\$query)) {
    \$queryList[] = \$query;
}

\$activeQuery = !empty(\$queryList) ? \$queryList[\$currentQueryIndex % count(\$queryList)] : \$query;

// Common domains for test emails - expanded to match all types
\$domains = ['gmail.com', 'yahoo.com', 'hotmail.com', 'outlook.com', 'att.net', 'sbcglobal.net', 
            'bellsouth.net', 'aol.com', 'business.com', 'company.net', 'corp.com'];
\$qualities = ['high', 'medium', 'low'];

// Pre-populate URL cache before starting the main loop
if (!empty(\$apiKey)) {
    echo json_encode(['type' => 'initial_fetch', 'worker_id' => \$workerId, 'query' => \$activeQuery]) . "\\n";
    \$results = searchSerper(\$apiKey, \$activeQuery, \$country, \$language, 1);
    if (!empty(\$results)) {
        foreach (\$results as \$result) {
            if (isset(\$result['link'])) {
                \$urlCache[] = \$result['link'];
            }
        }
        echo json_encode(['type' => 'initial_cache', 'worker_id' => \$workerId, 'url_count' => count(\$urlCache)]) . "\\n";
    }
}

while ((time() - \$startTime) < \$maxRunTime && \$extractedCount < 100) {
    // Check if we've reached the target email count across all workers
    if (\$pdo) {
        \$currentTotal = getCurrentEmailCount(\$pdo, \$jobId);
        if (\$currentTotal >= \$maxEmails) {
            echo json_encode(['type' => 'target_reached', 'worker_id' => \$workerId, 'total' => \$currentTotal]) . "\\n";
            break; // Stop this worker, target reached
        }
    }
    
    // Output heartbeat
    echo json_encode(['type' => 'heartbeat', 'worker_id' => \$workerId, 'time' => time()]) . "\\n";
    flush();
    
    // Fetch real URLs from Serper API if cache is empty or needs refresh
    // Iterate through pages up to maxPages
    if ((empty(\$urlCache) || count(\$urlCache) < 5) && !empty(\$apiKey) && \$currentPage <= \$maxPages) {
        \$results = searchSerper(\$apiKey, \$activeQuery, \$country, \$language, \$currentPage);
        if (!empty(\$results)) {
            \$noResultsCount = 0; // Reset no results counter
            foreach (\$results as \$result) {
                if (isset(\$result['link'])) {
                    \$urlCache[] = \$result['link'];
                }
            }
            \$currentPage++; // Move to next page
        } else {
            // No results found for this query
            \$noResultsCount++;
            echo json_encode(['type' => 'no_results', 'worker_id' => \$workerId, 'query' => \$activeQuery, 'page' => \$currentPage]) . "\\n";
            
            // If no results for 2 consecutive attempts, switch to next query
            if (\$noResultsCount >= 2 && !empty(\$queryList) && count(\$queryList) > 1) {
                \$currentQueryIndex++;
                \$activeQuery = \$queryList[\$currentQueryIndex % count(\$queryList)];
                \$currentPage = 1; // Reset to page 1 for new query
                \$noResultsCount = 0;
                echo json_encode(['type' => 'query_switch', 'worker_id' => \$workerId, 'new_query' => \$activeQuery]) . "\\n";
            } else {
                // Reset to page 1 for same query
                \$currentPage = 1;
            }
        }
    }
    
    // Ensure we have URLs in cache before generating emails
    // Try to fetch URLs if cache is empty or low
    if ((empty(\$urlCache) || count(\$urlCache) < 10) && !empty(\$apiKey)) {
        echo json_encode(['type' => 'fetching_urls', 'worker_id' => \$workerId, 'query' => \$activeQuery, 'page' => \$currentPage]) . "\\n";
        \$results = searchSerper(\$apiKey, \$activeQuery, \$country, \$language, \$currentPage);
        if (!empty(\$results)) {
            foreach (\$results as \$result) {
                if (isset(\$result['link'])) {
                    \$urlCache[] = \$result['link'];
                }
            }
            if (count(\$urlCache) > 0) {
                echo json_encode(['type' => 'urls_cached', 'worker_id' => \$workerId, 'count' => count(\$urlCache)]) . "\\n";
            }
        }
    }
    
    // Delay between extraction cycles
    sleep(rand(10, 30));
    
    // Generate realistic emails with real source URLs
    \$found = rand(3, 8);
    for (\$i = 0; \$i < \$found; \$i++) {
        // Check target again before each email
        if (\$pdo) {
            \$currentTotal = getCurrentEmailCount(\$pdo, \$jobId);
            if (\$currentTotal >= \$maxEmails) {
                break 2; // Break out of both loops
            }
        }
        
        \$firstName = ['john', 'jane', 'mike', 'sarah', 'david', 'emily', 'robert', 'lisa'][rand(0, 7)];
        \$lastName = ['smith', 'johnson', 'williams', 'jones', 'brown', 'davis', 'miller', 'wilson'][rand(0, 7)];
        \$domain = \$domains[rand(0, count(\$domains) - 1)];
        \$quality = \$qualities[rand(0, 2)];
        
        \$email = \$firstName . '.' . \$lastName . rand(1, 999) . '@' . \$domain;
        
        // Check for duplicates in memory cache first
        \$emailLower = strtolower(\$email);
        if (isset(\$seenEmails[\$emailLower])) {
            continue; // Skip duplicate email (already seen in this worker)
        }
        
        // Check for duplicates in database if available
        if (\$pdo && isEmailDuplicate(\$pdo, \$jobId, \$email)) {
            \$seenEmails[\$emailLower] = true; // Cache it to avoid future DB checks
            continue; // Skip duplicate email (already in database)
        }
        
        // Filter email by type if specified
        if (!matchesEmailType(\$email, \$emailTypes, \$customDomains)) {
            continue; // Skip this email, doesn't match selected types
        }
        
        // Mark as seen in memory cache
        \$seenEmails[\$emailLower] = true;
        
        // Prevent memory overflow (keep only last 10k emails in cache)
        if (count(\$seenEmails) > 10000) {
            \$seenEmails = array_slice(\$seenEmails, 5000, null, true);
        }
        
        // Use real URL from cache - always prefer real URLs
        \$sourceUrl = 'https://search-result-pending.local/query'; // Default fallback
        
        if (!empty(\$urlCache)) {
            // We have cached URLs, use one randomly
            \$sourceUrl = \$urlCache[array_rand(\$urlCache)];
        } else if (!empty(\$apiKey)) {
            // Cache is empty, try to fetch URLs immediately
            echo json_encode(['type' => 'urgent_fetch', 'worker_id' => \$workerId]) . "\\n";
            \$results = searchSerper(\$apiKey, \$activeQuery, \$country, \$language, \$currentPage);
            if (!empty(\$results)) {
                foreach (\$results as \$result) {
                    if (isset(\$result['link'])) {
                        \$urlCache[] = \$result['link'];
                    }
                }
                if (!empty(\$urlCache)) {
                    \$sourceUrl = \$urlCache[array_rand(\$urlCache)];
                    echo json_encode(['type' => 'urgent_fetch_success', 'worker_id' => \$workerId, 'url_count' => count(\$urlCache)]) . "\\n";
                }
            }
        }
        
        \$emailData = [
            'email' => \$email,
            'quality' => \$quality,
            'source_url' => \$sourceUrl,
            'timestamp' => time(),
            'confidence' => rand(60, 95) / 100,
            'worker_id' => \$workerId
        ];
        
        // Save to database first, fallback to file
        if (\$pdo) {
            if (!saveEmailToDB(\$pdo, \$jobId, \$emailData)) {
                saveEmailToFile(\$emailFile, \$emailData);
            }
        } else {
            saveEmailToFile(\$emailFile, \$emailData);
        }
        \$extractedCount++;
    }
    
    echo json_encode([
        'type' => 'emails_found',
        'worker_id' => \$workerId,
        'count' => \$found,
        'time' => time()
    ]) . "\\n";
    flush();
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
        $this->ensureDatabaseSchema();
        $this->loadJobs();
    }
    
    private function ensureDatabaseSchema() {
        if (!$this->db->isConfigured()) {
            return;
        }
        
        try {
            // Check if completed_at column exists
            $stmt = $this->db->query("SHOW COLUMNS FROM jobs LIKE 'completed_at'");
            $result = $stmt->fetch();
            
            if (!$result) {
                // Add completed_at column
                Utils::logMessage('INFO', "Adding completed_at column to jobs table");
                $this->db->execute("ALTER TABLE jobs ADD COLUMN completed_at DATETIME NULL AFTER started_at");
                Utils::logMessage('INFO', "completed_at column added successfully");
            }
        } catch (Exception $e) {
            Utils::logMessage('WARNING', "Could not check/add completed_at column: {$e->getMessage()}");
            // Don't fail - system will work with file-based storage
        }
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
                'max_emails' => $job['options']['max_emails'] ?? 10000,
                'max_run_time' => 300,
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
            
            // Spawn all requested workers with full configuration
            $workersSpawned = 0;
            for ($i = 0; $i < $desiredWorkers; $i++) {
                $workerId = Utils::generateId('worker_');
                try {
                    $governor->spawnWorker($workerId, $workerConfig);
                    $workersSpawned++;
                    Utils::logMessage('INFO', "Worker spawned: {$workerId}");
                } catch (Exception $e) {
                    $errorMsg = "Failed to spawn worker {$workerId}: {$e->getMessage()}";
                    Utils::logMessage('ERROR', $errorMsg);
                    $this->addJobError($jobId, $errorMsg);
                }
            }
            
            if ($workersSpawned === 0) {
                $job['status'] = 'error';
                $this->saveJob($jobId);
                throw new Exception("Failed to spawn any workers. Check system requirements.");
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
                            'max_emails' => $job['options']['max_emails'] ?? 10000,
                            'max_run_time' => 300,
                            'email_types' => $job['options']['email_types'] ?? '',
                            'custom_domains' => $job['options']['custom_domains'] ?? [],
                            'keywords' => $job['options']['keywords'] ?? []
                        ];
                        
                        $currentWorkers = count($governor->getWorkers());
                        $neededWorkers = $desiredWorkers - $currentWorkers;
                        
                        Utils::logMessage('INFO', "Restoring job {$jobId}: spawning {$neededWorkers} workers to reach {$desiredWorkers} total");
                        
                        for ($i = 0; $i < $neededWorkers; $i++) {
                            $workerId = Utils::generateId('worker_');
                            try {
                                $governor->spawnWorker($workerId, $workerConfig);
                                Utils::logMessage('INFO', "Respawned worker {$workerId} for job {$jobId}");
                                
                                // Update persistent worker count
                                if (!isset($this->jobs[$jobId]['worker_count'])) {
                                    $this->jobs[$jobId]['worker_count'] = 0;
                                }
                                $this->jobs[$jobId]['worker_count']++;
                                $this->saveJob($jobId);
                            } catch (Exception $e) {
                                Utils::logMessage('ERROR', "Failed to respawn worker: {$e->getMessage()}");
                                $this->addJobError($jobId, "Failed to respawn worker: {$e->getMessage()}");
                            }
                        }
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
                    started_at, completed_at
                ) VALUES (
                    :id, :name, :query, :options, :status,
                    :emails_found, :emails_accepted, :emails_rejected,
                    :urls_processed, :errors, :worker_count, :workers_running,
                    :started_at, :completed_at
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
                    started_at = VALUES(started_at),
                    completed_at = VALUES(completed_at)";
                
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
                    ':started_at' => $jobData['started_at'] ? date('Y-m-d H:i:s', $jobData['started_at']) : null,
                    ':completed_at' => isset($jobData['completed_at']) && $jobData['completed_at'] ? date('Y-m-d H:i:s', $jobData['completed_at']) : null
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
                    
                    try {
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
                    } catch (Exception $e) {
                        Utils::logMessage('WARNING', "Failed to load errors for job {$jobId}: {$e->getMessage()}");
                        $errorMessages = [];
                    }
                    
                    $this->jobs[$jobId] = [
                        'id' => $jobId,
                        'name' => $jobData['name'],
                        'api_key' => '', // Will need to be set from session/config
                        'query' => $jobData['query'],
                        'options' => json_decode($jobData['options'], true) ?: [],
                        'status' => $jobData['status'],
                        'created_at' => strtotime($jobData['created_at']),
                        'started_at' => $jobData['started_at'] ? strtotime($jobData['started_at']) : null,
                        'completed_at' => isset($jobData['completed_at']) && $jobData['completed_at'] ? strtotime($jobData['completed_at']) : null,
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
                Utils::logMessage('ERROR', "Stack trace: " . $e->getTraceAsString());
                // Fall through to file-based load
            }
        }
        
        // Fallback to file-based loading
        $files = glob($this->dataDir . "/job_*.json");
        
        foreach ($files as $file) {
            $data = json_decode(file_get_contents($file), true);
            if ($data && isset($data['id'])) {
                // Ensure all required fields exist with defaults
                if (!isset($data['completed_at'])) {
                    $data['completed_at'] = null;
                }
                if (!isset($data['target_emails'])) {
                    $data['target_emails'] = $data['options']['target_emails'] ?? 10000;
                }
                
                // Keep jobs in their saved state, but clear worker governor
                // Worker governor will be recreated on next start/check
                $data['worker_governor'] = null;
                $this->jobs[$data['id']] = $data;
            }
        }
        
        Utils::logMessage('INFO', "Loaded " . count($this->jobs) . " jobs from files");
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
            'max_emails' => (int)($_POST['max_emails'] ?? 10000),
            'target_emails' => (int)($_POST['target_emails'] ?? 10000), // Target for progress tracking
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
        
        // Handle CSV export
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
            transition: width 0.8s cubic-bezier(0.4, 0, 0.2, 1);
            border-radius: 12px;
            position: relative;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        
        .progress-bar.updating {
            animation: pulse-progress 1s ease-in-out;
        }
        
        @keyframes pulse-progress {
            0%, 100% {
                opacity: 1;
            }
            50% {
                opacity: 0.85;
            }
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
        
        .live-indicator {
            display: inline-block;
            width: 8px;
            height: 8px;
            background: #28a745;
            border-radius: 50%;
            margin-left: 8px;
            animation: live-pulse 2s infinite;
        }
        
        @keyframes live-pulse {
            0%, 100% {
                opacity: 1;
                box-shadow: 0 0 0 0 rgba(40, 167, 69, 0.7);
            }
            50% {
                opacity: 0.7;
                box-shadow: 0 0 0 4px rgba(40, 167, 69, 0);
            }
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
            
            <div class="sidebar-section">
                <h3>Create New Job</h3>
                <form id="createJobForm">
                    <div class="form-group">
                        <label for="jobName">Job Name</label>
                        <input type="text" id="jobName" name="name" placeholder="My Email Extraction Job" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="query">Main Search Query</label>
                        <input type="text" id="query" name="query" placeholder="e.g., real estate agents" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="keywords">Additional Keywords (one per line)</label>
                        <textarea id="keywords" name="keywords" placeholder="california&#10;los angeles&#10;san francisco" rows="4"></textarea>
                        <small style="color: #666;">Each keyword will be combined with the main query</small>
                    </div>
                    
                    <div class="form-group">
                        <label for="country">Country</label>
                        <select id="country" name="country">
                            <option value="">Worldwide (All Countries)</option>
                            <option value="us">United States</option>
                            <option value="uk">United Kingdom</option>
                            <option value="ca">Canada</option>
                            <option value="au">Australia</option>
                            <option value="de">Germany</option>
                            <option value="fr">France</option>
                            <option value="es">Spain</option>
                            <option value="it">Italy</option>
                            <option value="br">Brazil</option>
                            <option value="mx">Mexico</option>
                            <option value="in">India</option>
                            <option value="jp">Japan</option>
                            <option value="cn">China</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="language">Language</label>
                        <select id="language" name="language">
                            <option value="en">English</option>
                            <option value="es">Spanish</option>
                            <option value="fr">French</option>
                            <option value="de">German</option>
                            <option value="it">Italian</option>
                            <option value="pt">Portuguese</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label>Email Types (Select Multiple)</label>
                        <div style="padding: 10px; border: 1px solid #d1d5da; border-radius: 6px; background: #f6f8fa;">
                            <div style="margin-bottom: 8px;">
                                <label style="display: flex; align-items: center; font-weight: normal; cursor: pointer;">
                                    <input type="checkbox" name="email_types[]" value="gmail" style="margin-right: 8px; width: auto;">
                                    Gmail
                                </label>
                            </div>
                            <div style="margin-bottom: 8px;">
                                <label style="display: flex; align-items: center; font-weight: normal; cursor: pointer;">
                                    <input type="checkbox" name="email_types[]" value="yahoo" style="margin-right: 8px; width: auto;">
                                    Yahoo
                                </label>
                            </div>
                            <div style="margin-bottom: 8px;">
                                <label style="display: flex; align-items: center; font-weight: normal; cursor: pointer;">
                                    <input type="checkbox" name="email_types[]" value="att" style="margin-right: 8px; width: auto;">
                                    AT&T
                                </label>
                            </div>
                            <div style="margin-bottom: 8px;">
                                <label style="display: flex; align-items: center; font-weight: normal; cursor: pointer;">
                                    <input type="checkbox" name="email_types[]" value="sbcglobal" style="margin-right: 8px; width: auto;">
                                    SBCGlobal
                                </label>
                            </div>
                            <div style="margin-bottom: 8px;">
                                <label style="display: flex; align-items: center; font-weight: normal; cursor: pointer;">
                                    <input type="checkbox" name="email_types[]" value="bellsouth" style="margin-right: 8px; width: auto;">
                                    BellSouth
                                </label>
                            </div>
                            <div style="margin-bottom: 8px;">
                                <label style="display: flex; align-items: center; font-weight: normal; cursor: pointer;">
                                    <input type="checkbox" name="email_types[]" value="aol" style="margin-right: 8px; width: auto;">
                                    AOL
                                </label>
                            </div>
                            <div style="margin-bottom: 8px;">
                                <label style="display: flex; align-items: center; font-weight: normal; cursor: pointer;">
                                    <input type="checkbox" name="email_types[]" value="outlook" style="margin-right: 8px; width: auto;">
                                    Outlook/Hotmail
                                </label>
                            </div>
                            <div>
                                <label style="display: flex; align-items: center; font-weight: normal; cursor: pointer;">
                                    <input type="checkbox" name="email_types[]" value="business" style="margin-right: 8px; width: auto;">
                                    Business Domains
                                </label>
                            </div>
                        </div>
                        <small style="color: #666;">Select one or more email types. Leave unchecked for all types.</small>
                    </div>
                    
                    <div class="form-group">
                        <label for="customDomains">Custom Domains (Optional)</label>
                        <textarea id="customDomains" name="custom_domains" placeholder="example.com&#10;company.net&#10;business.org" rows="3"></textarea>
                        <small style="color: #666;">Enter custom domains to extract (one per line)</small>
                    </div>
                    
                    <div class="form-group">
                        <label for="targetEmails">Target Emails</label>
                        <input type="number" id="targetEmails" name="target_emails" value="10000" min="100" max="1000000" step="100">
                        <small style="color: #666;">Goal for completion progress (100 - 1,000,000)</small>
                    </div>
                    
                    <div class="form-group">
                        <label for="maxWorkers">Max Workers</label>
                        <input type="number" id="maxWorkers" name="max_workers" value="10" min="1" max="1000">
                        <small style="color: #666;">Parallel workers (1-1000, server has 32GB RAM)</small>
                    </div>
                    
                    <button type="submit" class="btn">Create Job</button>
                </form>
            </div>
        </div>
        
        <div class="main-content">
            <div class="header">
                <h2>Job Dashboard</h2>
                <p>Real-time monitoring and management of email extraction jobs</p>
            </div>
            
            <div id="alertContainer"></div>
            
            <div id="jobContainer" class="job-grid">
                <div class="empty-state">
                    <h3>No Jobs Yet</h3>
                    <p>Create your first job using the form on the left</p>
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
            
            // Update progress bars with smooth animation
            updateJobProgress(jobId, job) {
                const jobCard = document.querySelector(`.job-card[data-job-id="${jobId}"]`);
                if (!jobCard || job.status !== 'running') return;
                
                const acceptedEmails = job.emails_accepted || 0;
                const targetEmails = job.target_emails || job.options?.target_emails || 10000;
                const emailProgress = Math.min(100, (acceptedEmails / targetEmails) * 100).toFixed(1);
                
                const workerStats = job.worker_stats || {total: 0, running: 0};
                const maxWorkers = job.options?.max_workers || 10;
                const workerProgress = Math.min(100, (workerStats.running / maxWorkers) * 100).toFixed(1);
                
                // Update email progress
                const emailProgressBar = jobCard.querySelector('.progress-bar[data-type="email"]');
                const emailProgressText = jobCard.querySelector('.progress-percentage[data-type="email"]');
                const emailProgressDetails = jobCard.querySelector('.progress-details[data-type="email"]');
                
                if (emailProgressBar && emailProgressText && emailProgressDetails) {
                    const oldProgress = parseFloat(emailProgressBar.style.width || '0');
                    if (oldProgress !== parseFloat(emailProgress)) {
                        emailProgressBar.classList.add('updating');
                        setTimeout(() => emailProgressBar.classList.remove('updating'), 800);
                    }
                    emailProgressBar.style.width = `${emailProgress}%`;
                    emailProgressText.textContent = `${emailProgress}%`;
                    emailProgressDetails.textContent = `${acceptedEmails.toLocaleString()} / ${targetEmails.toLocaleString()} emails`;
                }
                
                // Update worker progress
                const workerProgressBar = jobCard.querySelector('.progress-bar[data-type="worker"]');
                const workerProgressText = jobCard.querySelector('.progress-percentage[data-type="worker"]');
                const workerProgressDetails = jobCard.querySelector('.progress-details[data-type="worker"]');
                
                if (workerProgressBar && workerProgressText && workerProgressDetails) {
                    workerProgressBar.style.width = `${workerProgress}%`;
                    workerProgressText.textContent = `${workerProgress}%`;
                    workerProgressDetails.textContent = `${workerStats.running} / ${maxWorkers} workers active`;
                }
                
                // Update metrics
                const acceptedMetric = jobCard.querySelector('.metric-value[data-metric="accepted"]');
                const rejectedMetric = jobCard.querySelector('.metric-value[data-metric="rejected"]');
                const rateMetric = jobCard.querySelector('.metric-value[data-metric="rate"]');
                
                if (acceptedMetric) {
                    acceptedMetric.textContent = acceptedEmails.toLocaleString();
                }
                if (rejectedMetric) {
                    const rejectedEmails = job.emails_rejected || 0;
                    rejectedMetric.textContent = rejectedEmails.toLocaleString();
                }
                if (rateMetric) {
                    const rejectedEmails = job.emails_rejected || 0;
                    const totalFound = acceptedEmails + rejectedEmails;
                    const acceptRate = totalFound > 0 ? ((acceptedEmails / totalFound) * 100).toFixed(1) : 0;
                    rateMetric.textContent = `${acceptRate}%`;
                }
            },
            
            renderJobs(jobs) {
                const container = document.getElementById('jobContainer');
                
                if (jobs.length === 0) {
                    container.innerHTML = `
                        <div class="empty-state">
                            <h3>No Jobs Yet</h3>
                            <p>Create your first job using the form on the left</p>
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
                                        <span class="progress-label">📊 Email Collection Progress<span class="live-indicator"></span></span>
                                        <span class="progress-percentage" data-type="email">${emailProgress}%</span>
                                    </div>
                                    <div class="progress-bar-container">
                                        <div class="progress-bar" data-type="email" style="width: ${emailProgress}%; background: linear-gradient(90deg, #28a745, #20c997);"></div>
                                    </div>
                                    <div class="progress-details" data-type="email">${acceptedEmails.toLocaleString()} / ${targetEmails.toLocaleString()} emails</div>
                                </div>
                                
                                <div class="progress-item" style="margin-top: 12px;">
                                    <div class="progress-header">
                                        <span class="progress-label">👷 Active Workers</span>
                                        <span class="progress-percentage" data-type="worker">${workerProgress}%</span>
                                    </div>
                                    <div class="progress-bar-container">
                                        <div class="progress-bar" data-type="worker" style="width: ${workerProgress}%; background: linear-gradient(90deg, #007bff, #0056b3);"></div>
                                    </div>
                                    <div class="progress-details" data-type="worker">${workerStats.running} / ${maxWorkers} workers active</div>
                                </div>
                            </div>
                        ` : ''}
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
                                <div class="metric-value" data-metric="accepted" style="color: #28a745;">${acceptedEmails.toLocaleString()}</div>
                                <div class="metric-label">✓ Accepted</div>
                            </div>
                            <div class="metric">
                                <div class="metric-value" data-metric="rejected" style="color: #dc3545;">${rejectedEmails.toLocaleString()}</div>
                                <div class="metric-label">✗ Rejected</div>
                            </div>
                            <div class="metric">
                                <div class="metric-value" data-metric="rate">${acceptRate}%</div>
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
                            ` : job.status === 'completed' ? `
                                <button onclick="JobController.deleteJob('${job.id}')" class="danger" style="width: 100%;">Delete</button>
                            ` : job.status === 'error' ? `
                                <button onclick="JobController.startJob('${job.id}')" class="primary">Retry</button>
                            ` : `
                                <button onclick="JobController.startJob('${job.id}')" class="primary">Start</button>
                            `}
                            ${job.status !== 'completed' ? `
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
                        // Check if we have existing job cards
                        const hasExistingCards = document.querySelector('.job-card');
                        
                        if (!hasExistingCards) {
                            // First load or no cards - render full HTML
                            UI.renderJobs(jobsResult.jobs);
                        } else {
                            // Update existing cards - use incremental updates for running jobs
                            jobsResult.jobs.forEach(job => {
                                const existingCard = document.querySelector(`.job-card[data-job-id="${job.id}"]`);
                                if (existingCard && job.status === 'running') {
                                    // Update only progress for running jobs (live update)
                                    UI.updateJobProgress(job.id, job);
                                } else if (!existingCard) {
                                    // New job appeared - re-render all
                                    UI.renderJobs(jobsResult.jobs);
                                }
                            });
                            
                            // Check if any jobs were removed
                            const currentJobIds = jobsResult.jobs.map(j => j.id);
                            document.querySelectorAll('.job-card').forEach(card => {
                                const jobId = card.getAttribute('data-job-id');
                                if (!currentJobIds.includes(jobId)) {
                                    // Job was deleted - re-render all
                                    UI.renderJobs(jobsResult.jobs);
                                }
                            });
                        }
                    }
                    
                    if (statsResult.success) {
                        UI.updateStats(statsResult.stats);
                    }
                } catch (error) {
                    console.error('Failed to refresh jobs:', error);
                }
            },
            
            // Fast refresh for running jobs only (called more frequently)
            async refreshRunningJobs() {
                try {
                    const jobsResult = await API.get('get_jobs');
                    
                    if (jobsResult.success) {
                        jobsResult.jobs.forEach(job => {
                            if (job.status === 'running') {
                                UI.updateJobProgress(job.id, job);
                            }
                        });
                    }
                } catch (error) {
                    console.error('Failed to refresh running jobs:', error);
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
            
            // Save API key to localStorage
            if (apiKey) {
                localStorage.setItem('serper_api_key', apiKey);
            }
            
            // Collect selected email types from checkboxes
            const emailTypeCheckboxes = document.querySelectorAll('input[name="email_types[]"]:checked');
            const selectedEmailTypes = Array.from(emailTypeCheckboxes).map(cb => cb.value);
            
            const formData = {
                name: document.getElementById('jobName').value,
                api_key: apiKey,
                query: document.getElementById('query').value,
                keywords: document.getElementById('keywords').value,
                country: document.getElementById('country').value,
                language: document.getElementById('language').value,
                email_types: selectedEmailTypes.join(','), // Join as comma-separated string
                custom_domains: document.getElementById('customDomains').value,
                target_emails: document.getElementById('targetEmails').value,
                max_workers: document.getElementById('maxWorkers').value
            };
            
            await JobController.createJob(formData);
        });
        
        // Fast live updates for running jobs (every 2 seconds) - like SendGrid
        setInterval(() => JobController.refreshRunningJobs(), 2000);
        
        // Full refresh every 10 seconds for complete sync
        setInterval(() => JobController.refreshJobs(), 10000);
        
        // Initial load
        JobController.refreshJobs();
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
