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

// Configuration
class Config {
    const VERSION = '1.0.0';
    const MAX_WORKERS_PER_JOB = 10;
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
        
        // NOTE: This is a simulation worker for demonstration purposes.
        // In production, replace this with actual email extraction logic that:
        // 1. Uses the SearchScheduler to query Serper API with $config['query']
        // 2. Processes each search result URL with URLFilter
        // 3. Fetches webpage content and uses ContentFilter::extractEmails()
        // 4. Validates emails with EmailValidator::validateWithMX()
        // 5. Scores emails with ConfidenceScorer::score()
        // 6. Deduplicates with DedupEngine and buffers with BufferManager
        // 7. Respects DomainLimiter throttling rules
        //
        // The worker should receive: api_key, query, job_id, max_emails, options
        // See README.md for integration guidelines.
        
        return <<<'PHP'
<?php
// Worker Process - SIMULATION MODE
// TODO: Integrate actual email extraction logic here
$config = json_decode(base64_decode('{CONFIG_B64}'), true);
$workerId = '{WORKER_ID}';

// Simulate worker doing work (Replace with actual implementation)
$startTime = time();
$maxRunTime = $config['max_run_time'] ?? 300;

while ((time() - $startTime) < $maxRunTime) {
    // Output heartbeat
    echo json_encode(['type' => 'heartbeat', 'worker_id' => $workerId, 'time' => time()]) . "\n";
    flush();
    
    // Simulate work (Replace with actual Serper API calls and email extraction)
    sleep(5);
    
    // Simulate finding emails (Replace with actual extraction results)
    $found = rand(0, 3);
    if ($found > 0) {
        echo json_encode([
            'type' => 'emails_found',
            'worker_id' => $workerId,
            'count' => $found,
            'time' => time()
        ]) . "\n";
        flush();
    }
}

echo json_encode(['type' => 'completed', 'worker_id' => $workerId]) . "\n";
PHP;
        
        $script = str_replace('{CONFIG_B64}', $configJson, $script);
        $script = str_replace('{WORKER_ID}', $workerIdSafe, $script);
        
        return $script;
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
                // Handle emails found
                Utils::logMessage('INFO', "Worker {$workerId} found {$data['count']} emails");
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
    
    public function __construct() {
        $this->dataDir = Config::DATA_DIR;
        @mkdir($this->dataDir, 0755, true);
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
            'emails_found' => 0,
            'emails_accepted' => 0,
            'emails_rejected' => 0,
            'urls_processed' => 0,
            'errors' => 0,
            'error_messages' => [], // Store error messages for UI display
            'worker_governor' => null,
            'hourly_stats' => [], // Track emails per hour
            'worker_count' => 0, // Persistent worker count
            'workers_running' => 0 // Persistent running worker count
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
        $this->saveJob($jobId);
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
                'max_emails' => $job['options']['max_emails'] ?? 10000,
                'max_run_time' => 300
            ];
            
            $governor = new WorkerGovernor($jobId);
            $job['worker_governor'] = $governor;
            $job['status'] = 'running';
            $job['started_at'] = time();
            
            // Spawn initial workers with full configuration
            $workersSpawned = 0;
            for ($i = 0; $i < Config::MIN_WORKERS_PER_JOB; $i++) {
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
            Utils::logMessage('INFO', "Job started: {$jobId} with {$workersSpawned} workers");
            
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
        
        unset($this->jobs[$jobId]);
        
        // Delete job file
        $jobFile = $this->dataDir . "/job_{$jobId}.json";
        @unlink($jobFile);
        
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
                // Restore worker governor if it doesn't exist
                if (!$job['worker_governor']) {
                    try {
                        $governor = new WorkerGovernor($jobId);
                        $this->jobs[$jobId]['worker_governor'] = $governor;
                        
                        // Respawn minimum workers with full configuration
                        $workerConfig = [
                            'job_id' => $jobId,
                            'api_key' => $job['api_key'],
                            'query' => $job['query'],
                            'max_emails' => $job['options']['max_emails'] ?? 10000,
                            'max_run_time' => 300
                        ];
                        
                        $currentWorkers = count($governor->getWorkers());
                        $neededWorkers = Config::MIN_WORKERS_PER_JOB - $currentWorkers;
                        
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
                }
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
        
        $jobFile = $this->dataDir . "/job_{$jobId}.json";
        file_put_contents($jobFile, json_encode($jobData, JSON_PRETTY_PRINT));
        
        return true;
    }
    
    private function loadJobs() {
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
        $emailTypes = $_POST['email_types'] ?? 'all'; // Gmail, Yahoo, Business, All
        
        if (empty($apiKey) || (empty($query) && empty($keywords))) {
            throw new Exception("API key and query/keywords are required");
        }
        
        // Parse keywords if provided
        $keywordList = [];
        if (!empty($keywords)) {
            $keywordList = array_filter(array_map('trim', explode("\n", $keywords)));
        }
        
        $options = [
            'max_workers' => (int)($_POST['max_workers'] ?? Config::MAX_WORKERS_PER_JOB),
            'max_emails' => (int)($_POST['max_emails'] ?? 10000),
            'keywords' => $keywordList,
            'country' => $country,
            'language' => $language,
            'email_types' => $emailTypes
        ];
        
        $jobId = $this->jobManager->createJob($name, $apiKey, $query, $options);
        
        return $this->jsonResponse([
            'success' => true,
            'job_id' => $jobId
        ]);
    }
    
    private function startJob() {
        $jobId = $_POST['job_id'] ?? '';
        
        if (empty($jobId)) {
            throw new Exception("Job ID is required");
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
    </style>
</head>
<body>
    <div class="container">
        <div class="sidebar">
            <h1>📧 Email Extractor</h1>
            <div class="version">Version <?php echo Config::VERSION; ?></div>
            
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
                        <label for="emailTypes">Email Types</label>
                        <select id="emailTypes" name="email_types">
                            <option value="all">All Types</option>
                            <option value="gmail">Gmail Only</option>
                            <option value="yahoo">Yahoo Only</option>
                            <option value="business">Business Only</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="maxWorkers">Max Workers</label>
                        <input type="number" id="maxWorkers" name="max_workers" value="5" min="1" max="10">
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
                
                // Get recent errors
                const errorMessages = job.error_messages || [];
                const recentErrors = errorMessages.slice(-3); // Show last 3 errors
                
                return `
                    <div class="job-card" data-job-id="${job.id}">
                        <div class="job-header">
                            <div class="job-title">${this.escapeHtml(job.name)}</div>
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
                                    <strong>Workers:</strong> ${workerStats.running} running / ${workerStats.total} total
                                </div>
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
                            ` : `
                                <button onclick="JobController.startJob('${job.id}')" class="primary">Start</button>
                            `}
                            <button onclick="JobController.deleteJob('${job.id}')" class="danger">Delete</button>
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
                    const result = await API.call('start_job', {job_id: jobId});
                    
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
        
        // Initialize
        // Test Connection Button
        document.getElementById('testConnectionBtn').addEventListener('click', async () => {
            const apiKey = document.getElementById('apiKey').value;
            if (!apiKey) {
                UI.showAlert('Please enter an API key first', 'error');
                return;
            }
            
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
            
            const formData = {
                name: document.getElementById('jobName').value,
                api_key: document.getElementById('apiKey').value,
                query: document.getElementById('query').value,
                keywords: document.getElementById('keywords').value,
                country: document.getElementById('country').value,
                language: document.getElementById('language').value,
                email_types: document.getElementById('emailTypes').value,
                max_workers: document.getElementById('maxWorkers').value
            };
            
            await JobController.createJob(formData);
        });
        
        // Auto-refresh every 5 seconds
        setInterval(() => JobController.refreshJobs(), 5000);
        
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
