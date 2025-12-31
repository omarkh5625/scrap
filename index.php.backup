<?php
/**
 * Professional Email Extraction System using Serper Google Search API
 * Single-file PHP system for cPanel with real-time multi-job processing
 * 
 * Architecture:
 * - JobManager: Handles job lifecycle
 * - WorkerGovernor: Manages worker processes
 * - URLFilter, ContentFilter: Content validation
 * - EmailExtractor, EmailValidator: Email processing
 * - DomainLimiter: Throttles low-yield domains
 * - ConfidenceScorer: Scores emails
 * - DedupEngine: In-memory deduplication
 * - SearchScheduler: Dynamic query scheduling
 * - Supervisor/Watchdog: System health monitoring
 */

// Configuration
define('DB_FILE', __DIR__ . '/email_extraction.db');
define('MAX_WORKERS_PER_JOB', 5);
define('WORKER_TIMEOUT', 300);
define('API_RATE_LIMIT', 100);
define('DOMAIN_THROTTLE_THRESHOLD', 10);

// =====================================================================
// DATABASE LAYER
// =====================================================================

class Database {
    private static $instance = null;
    private $db;
    
    private function __construct() {
        $this->db = new PDO('sqlite:' . DB_FILE);
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->initSchema();
    }
    
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new Database();
        }
        return self::$instance;
    }
    
    public function getConnection() {
        return $this->db;
    }
    
    private function initSchema() {
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS config (
                key TEXT PRIMARY KEY,
                value TEXT NOT NULL,
                updated_at INTEGER DEFAULT (strftime('%s', 'now'))
            )
        ");
        
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS jobs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                search_terms TEXT NOT NULL,
                status TEXT DEFAULT 'active',
                created_at INTEGER DEFAULT (strftime('%s', 'now')),
                updated_at INTEGER DEFAULT (strftime('%s', 'now')),
                total_emails INTEGER DEFAULT 0,
                total_urls_processed INTEGER DEFAULT 0,
                last_error TEXT,
                settings TEXT
            )
        ");
        
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS emails (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                job_id INTEGER NOT NULL,
                email TEXT NOT NULL,
                domain TEXT NOT NULL,
                source_url TEXT,
                confidence_score REAL DEFAULT 0.0,
                extracted_at INTEGER DEFAULT (strftime('%s', 'now')),
                UNIQUE(job_id, email),
                FOREIGN KEY(job_id) REFERENCES jobs(id) ON DELETE CASCADE
            )
        ");
        
        $this->db->exec("
            CREATE INDEX IF NOT EXISTS idx_emails_job ON emails(job_id)
        ");
        
        $this->db->exec("
            CREATE INDEX IF NOT EXISTS idx_emails_domain ON emails(domain)
        ");
        
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS urls_processed (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                job_id INTEGER NOT NULL,
                url TEXT NOT NULL,
                processed_at INTEGER DEFAULT (strftime('%s', 'now')),
                emails_found INTEGER DEFAULT 0,
                UNIQUE(job_id, url),
                FOREIGN KEY(job_id) REFERENCES jobs(id) ON DELETE CASCADE
            )
        ");
        
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS error_log (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                job_id INTEGER,
                error_type TEXT NOT NULL,
                error_message TEXT NOT NULL,
                occurred_at INTEGER DEFAULT (strftime('%s', 'now')),
                resolved INTEGER DEFAULT 0
            )
        ");
        
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS domain_stats (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                job_id INTEGER NOT NULL,
                domain TEXT NOT NULL,
                urls_checked INTEGER DEFAULT 0,
                emails_found INTEGER DEFAULT 0,
                last_checked INTEGER DEFAULT (strftime('%s', 'now')),
                UNIQUE(job_id, domain),
                FOREIGN KEY(job_id) REFERENCES jobs(id) ON DELETE CASCADE
            )
        ");
    }
}

// =====================================================================
// CORE COMPONENTS
// =====================================================================

class DedupEngine {
    private $urlHashes = [];
    private $emailHashes = [];
    private $maxSize = 100000;
    
    public function isUrlProcessed($jobId, $url) {
        $key = $jobId . ':' . md5($url);
        return isset($this->urlHashes[$key]);
    }
    
    public function markUrlProcessed($jobId, $url) {
        $key = $jobId . ':' . md5($url);
        $this->urlHashes[$key] = true;
        $this->enforceLimit();
    }
    
    public function isEmailSeen($jobId, $email) {
        $key = $jobId . ':' . md5(strtolower($email));
        return isset($this->emailHashes[$key]);
    }
    
    public function markEmailSeen($jobId, $email) {
        $key = $jobId . ':' . md5(strtolower($email));
        $this->emailHashes[$key] = true;
        $this->enforceLimit();
    }
    
    private function enforceLimit() {
        if (count($this->urlHashes) > $this->maxSize) {
            $this->urlHashes = array_slice($this->urlHashes, -intval($this->maxSize * 0.8), null, true);
        }
        if (count($this->emailHashes) > $this->maxSize) {
            $this->emailHashes = array_slice($this->emailHashes, -intval($this->maxSize * 0.8), null, true);
        }
    }
    
    public function clearJobData($jobId) {
        $prefix = $jobId . ':';
        foreach ($this->urlHashes as $key => $val) {
            if (strpos($key, $prefix) === 0) {
                unset($this->urlHashes[$key]);
            }
        }
        foreach ($this->emailHashes as $key => $val) {
            if (strpos($key, $prefix) === 0) {
                unset($this->emailHashes[$key]);
            }
        }
    }
}

class URLFilter {
    private static $excludeExtensions = [
        'pdf', 'jpg', 'jpeg', 'png', 'gif', 'zip', 'rar', 'exe', 'mp4', 'mp3', 'avi', 'mov',
        'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'csv', 'tar', 'gz', 'bz2', '7z',
        'iso', 'dmg', 'bin', 'dat', 'xml', 'json', 'svg', 'ico', 'woff', 'woff2', 'ttf', 'eot'
    ];
    private static $excludePatterns = ['/login', '/signin', '/signup', '/register', '/cart', '/checkout'];
    
    public static function isValid($url) {
        if (empty($url) || !filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }
        
        $ext = pathinfo(parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION);
        if (in_array(strtolower($ext), self::$excludeExtensions)) {
            return false;
        }
        
        foreach (self::$excludePatterns as $pattern) {
            if (stripos($url, $pattern) !== false) {
                return false;
            }
        }
        
        return true;
    }
}

class ContentFilter {
    public static function isValidContent($content) {
        if (empty($content) || strlen($content) < 100) {
            return false;
        }
        
        if (preg_match('/<html|<body|<div/i', $content)) {
            return true;
        }
        
        return strlen(strip_tags($content)) > 50;
    }
}

class EmailValidator {
    private static $blacklistedDomains = [
        'gmail.com', 'yahoo.com', 'hotmail.com', 'outlook.com', 'aol.com',
        'icloud.com', 'live.com', 'msn.com', 'mail.com', 'example.com',
        'test.com', 'sample.com', 'tempmail.com', 'guerrillamail.com',
        'facebook.com', 'google.com', 'amazon.com', 'microsoft.com',
        'apple.com', 'twitter.com', 'linkedin.com', 'instagram.com'
    ];
    
    private static $invalidPatterns = [
        '/noreply/i', '/no-reply/i', '/donotreply/i', '/support@/i',
        '/info@/i', '/admin@/i', '/webmaster@/i', '/postmaster@/i',
        '/abuse@/i', '/sales@/i', '/marketing@/i'
    ];
    
    public static function isValid($email) {
        $email = strtolower(trim($email));
        
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return false;
        }
        
        if (strlen($email) > 254 || strlen($email) < 6) {
            return false;
        }
        
        $parts = explode('@', $email);
        if (count($parts) !== 2) {
            return false;
        }
        
        list($local, $domain) = $parts;
        
        if (strlen($local) < 1 || strlen($local) > 64) {
            return false;
        }
        
        if (in_array($domain, self::$blacklistedDomains)) {
            return false;
        }
        
        foreach (self::$invalidPatterns as $pattern) {
            if (preg_match($pattern, $email)) {
                return false;
            }
        }
        
        // Strict pattern matching for high-quality emails only
        // Intentionally restrictive to filter out edge cases and ensure clean data
        if (!preg_match('/^[a-z0-9]+([._-][a-z0-9]+)*@[a-z0-9-]+(\.[a-z0-9-]+)*\.[a-z]{2,}$/', $email)) {
            return false;
        }
        
        return true;
    }
    
    public static function extractDomain($email) {
        $parts = explode('@', strtolower($email));
        return isset($parts[1]) ? $parts[1] : '';
    }
}

class EmailExtractor {
    public static function extractFromContent($content) {
        $emails = [];
        
        $pattern = '/\b[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Z|a-z]{2,}\b/';
        if (preg_match_all($pattern, $content, $matches)) {
            foreach ($matches[0] as $email) {
                $email = strtolower(trim($email));
                if (EmailValidator::isValid($email)) {
                    $emails[] = $email;
                }
            }
        }
        
        return array_unique($emails);
    }
}

class ConfidenceScorer {
    public static function score($email, $sourceUrl, $context = '') {
        $score = 50.0;
        
        $domain = EmailValidator::extractDomain($email);
        if (empty($domain)) {
            return 0.0;
        }
        
        if (filter_var('http://' . $domain, FILTER_VALIDATE_URL)) {
            $score += 10.0;
        }
        
        if (preg_match('/\.(com|org|net|edu|gov)$/', $domain)) {
            $score += 5.0;
        }
        
        if (!preg_match('/[0-9]{3,}/', $email)) {
            $score += 10.0;
        }
        
        $localPart = explode('@', $email)[0];
        if (strlen($localPart) >= 3 && strlen($localPart) <= 20) {
            $score += 10.0;
        }
        
        if (!empty($sourceUrl) && stripos($sourceUrl, $domain) !== false) {
            $score += 15.0;
        }
        
        return min(100.0, $score);
    }
}

class DomainLimiter {
    private $domainStats = [];
    private $threshold = DOMAIN_THROTTLE_THRESHOLD;
    
    public function shouldProcess($jobId, $domain) {
        $key = $jobId . ':' . $domain;
        
        if (!isset($this->domainStats[$key])) {
            $this->domainStats[$key] = ['checked' => 0, 'found' => 0];
            return true;
        }
        
        $stats = $this->domainStats[$key];
        
        if ($stats['checked'] < 5) {
            return true;
        }
        
        $yield = $stats['checked'] > 0 ? ($stats['found'] / $stats['checked']) : 0;
        
        if ($yield < 0.1 && $stats['checked'] >= $this->threshold) {
            return false;
        }
        
        return true;
    }
    
    public function recordCheck($jobId, $domain, $emailsFound) {
        $key = $jobId . ':' . $domain;
        
        if (!isset($this->domainStats[$key])) {
            $this->domainStats[$key] = ['checked' => 0, 'found' => 0];
        }
        
        $this->domainStats[$key]['checked']++;
        $this->domainStats[$key]['found'] += $emailsFound;
    }
}

class SearchScheduler {
    private $jobTerms = [];
    private $termStats = [];
    
    public function addTerms($jobId, array $terms) {
        if (!isset($this->jobTerms[$jobId])) {
            $this->jobTerms[$jobId] = [];
        }
        foreach ($terms as $term) {
            $this->jobTerms[$jobId][] = $term;
            $key = $jobId . ':' . $term;
            if (!isset($this->termStats[$key])) {
                $this->termStats[$key] = ['queries' => 0, 'emails' => 0, 'priority' => 1.0];
            }
        }
    }
    
    public function getNextTerm($jobId) {
        if (!isset($this->jobTerms[$jobId]) || empty($this->jobTerms[$jobId])) {
            return null;
        }
        
        $terms = $this->jobTerms[$jobId];
        $bestTerm = null;
        $bestPriority = -1;
        
        foreach ($terms as $term) {
            $key = $jobId . ':' . $term;
            $priority = $this->termStats[$key]['priority'];
            
            if ($priority > $bestPriority) {
                $bestPriority = $priority;
                $bestTerm = $term;
            }
        }
        
        return $bestTerm;
    }
    
    public function recordResults($jobId, $term, $emailsFound) {
        $key = $jobId . ':' . $term;
        
        if (isset($this->termStats[$key])) {
            $this->termStats[$key]['queries']++;
            $this->termStats[$key]['emails'] += $emailsFound;
            
            $yield = $this->termStats[$key]['queries'] > 0 
                ? ($this->termStats[$key]['emails'] / $this->termStats[$key]['queries']) 
                : 0;
            
            $this->termStats[$key]['priority'] = max(0.1, min(2.0, $yield / 10));
        }
    }
}

class ErrorHandler {
    private static $errors = [];
    
    public static function log($jobId, $type, $message) {
        $error = [
            'job_id' => $jobId,
            'type' => $type,
            'message' => $message,
            'time' => time()
        ];
        
        self::$errors[] = $error;
        
        try {
            $db = Database::getInstance()->getConnection();
            $stmt = $db->prepare("INSERT INTO error_log (job_id, error_type, error_message) VALUES (?, ?, ?)");
            $stmt->execute([$jobId, $type, $message]);
        } catch (Exception $e) {
            error_log("Failed to log error: " . $e->getMessage());
        }
        
        if (count(self::$errors) > 1000) {
            self::$errors = array_slice(self::$errors, -500);
        }
    }
    
    public static function getRecentErrors($jobId = null, $limit = 50) {
        $filtered = $jobId === null 
            ? self::$errors 
            : array_filter(self::$errors, function($e) use ($jobId) {
                return $e['job_id'] == $jobId;
            });
        
        return array_slice($filtered, -$limit);
    }
    
    public static function shouldRetry($errorType) {
        $retryableErrors = ['HTTP_429', 'TIMEOUT', 'CONNECTION_ERROR', 'RATE_LIMIT'];
        return in_array($errorType, $retryableErrors);
    }
}

class WorkerGovernor {
    private $activeWorkers = [];
    private $maxWorkers = MAX_WORKERS_PER_JOB;
    
    public function canSpawnWorker($jobId) {
        $this->cleanupDeadWorkers();
        
        $jobWorkers = $this->getJobWorkers($jobId);
        return count($jobWorkers) < $this->maxWorkers;
    }
    
    public function spawnWorker($jobId, $command) {
        if (!$this->canSpawnWorker($jobId)) {
            return false;
        }
        
        // Validate jobId is numeric to prevent injection
        if (!is_numeric($jobId)) {
            ErrorHandler::log($jobId, 'WORKER_SPAWN_FAILED', 'Invalid job ID');
            return false;
        }
        
        // Ensure command is an array for safe execution
        if (!is_array($command)) {
            ErrorHandler::log($jobId, 'WORKER_SPAWN_FAILED', 'Command must be an array');
            return false;
        }
        
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w']
        ];
        
        $process = proc_open($command, $descriptors, $pipes);
        
        if (!is_resource($process)) {
            ErrorHandler::log($jobId, 'WORKER_SPAWN_FAILED', 'Failed to spawn worker');
            return false;
        }
        
        $status = proc_get_status($process);
        $workerId = uniqid('worker_', true);
        
        $this->activeWorkers[$workerId] = [
            'job_id' => $jobId,
            'process' => $process,
            'pipes' => $pipes,
            'pid' => $status['pid'],
            'started' => time(),
            'status' => 'running'
        ];
        
        foreach ($pipes as $pipe) {
            stream_set_blocking($pipe, false);
        }
        
        return $workerId;
    }
    
    public function getJobWorkers($jobId) {
        $this->cleanupDeadWorkers();
        
        $workers = [];
        foreach ($this->activeWorkers as $workerId => $worker) {
            if ($worker['job_id'] == $jobId && $worker['status'] === 'running') {
                $workers[$workerId] = $worker;
            }
        }
        return $workers;
    }
    
    public function killWorker($workerId) {
        if (!isset($this->activeWorkers[$workerId])) {
            return false;
        }
        
        $worker = $this->activeWorkers[$workerId];
        
        foreach ($worker['pipes'] as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        
        if (is_resource($worker['process'])) {
            proc_terminate($worker['process'], 9);
            proc_close($worker['process']);
        }
        
        unset($this->activeWorkers[$workerId]);
        return true;
    }
    
    private function cleanupDeadWorkers() {
        foreach ($this->activeWorkers as $workerId => $worker) {
            if (time() - $worker['started'] > WORKER_TIMEOUT) {
                $this->killWorker($workerId);
                continue;
            }
            
            if (is_resource($worker['process'])) {
                $status = proc_get_status($worker['process']);
                if (!$status['running']) {
                    $this->killWorker($workerId);
                }
            } else {
                unset($this->activeWorkers[$workerId]);
            }
        }
    }
    
    public function getActiveWorkersCount($jobId = null) {
        $this->cleanupDeadWorkers();
        
        if ($jobId === null) {
            return count($this->activeWorkers);
        }
        
        return count($this->getJobWorkers($jobId));
    }
}

class JobManager {
    private $db;
    private $dedupEngine;
    private $domainLimiter;
    private $searchScheduler;
    private $workerGovernor;
    
    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
        $this->dedupEngine = new DedupEngine();
        $this->domainLimiter = new DomainLimiter();
        $this->searchScheduler = new SearchScheduler();
        $this->workerGovernor = new WorkerGovernor();
    }
    
    public function createJob($name, $searchTerms, $settings = []) {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO jobs (name, search_terms, settings) 
                VALUES (?, ?, ?)
            ");
            $stmt->execute([
                $name,
                json_encode($searchTerms),
                json_encode($settings)
            ]);
            
            $jobId = $this->db->lastInsertId();
            $this->searchScheduler->addTerms($jobId, $searchTerms);
            
            return $jobId;
        } catch (Exception $e) {
            ErrorHandler::log(null, 'JOB_CREATE_FAILED', $e->getMessage());
            return false;
        }
    }
    
    public function updateJob($jobId, $data) {
        try {
            $fields = [];
            $values = [];
            
            foreach ($data as $key => $value) {
                if (in_array($key, ['name', 'status', 'search_terms', 'settings', 'last_error'])) {
                    $fields[] = "$key = ?";
                    $values[] = is_array($value) ? json_encode($value) : $value;
                }
            }
            
            if (empty($fields)) {
                return false;
            }
            
            $values[] = time();
            $values[] = $jobId;
            
            $sql = "UPDATE jobs SET " . implode(', ', $fields) . ", updated_at = ? WHERE id = ?";
            $stmt = $this->db->prepare($sql);
            return $stmt->execute($values);
        } catch (Exception $e) {
            ErrorHandler::log($jobId, 'JOB_UPDATE_FAILED', $e->getMessage());
            return false;
        }
    }
    
    public function getJob($jobId) {
        try {
            $stmt = $this->db->prepare("SELECT * FROM jobs WHERE id = ?");
            $stmt->execute([$jobId]);
            $job = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($job) {
                $job['search_terms'] = json_decode($job['search_terms'], true);
                $job['settings'] = json_decode($job['settings'], true);
            }
            
            return $job;
        } catch (Exception $e) {
            ErrorHandler::log($jobId, 'JOB_FETCH_FAILED', $e->getMessage());
            return null;
        }
    }
    
    public function getAllJobs() {
        try {
            $stmt = $this->db->query("SELECT * FROM jobs ORDER BY created_at DESC");
            $jobs = [];
            
            while ($job = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $job['search_terms'] = json_decode($job['search_terms'], true);
                $job['settings'] = json_decode($job['settings'], true);
                $job['active_workers'] = $this->workerGovernor->getActiveWorkersCount($job['id']);
                $jobs[] = $job;
            }
            
            return $jobs;
        } catch (Exception $e) {
            ErrorHandler::log(null, 'JOBS_FETCH_FAILED', $e->getMessage());
            return [];
        }
    }
    
    public function deleteJob($jobId) {
        try {
            $workers = $this->workerGovernor->getJobWorkers($jobId);
            foreach ($workers as $workerId => $worker) {
                $this->workerGovernor->killWorker($workerId);
            }
            
            $this->dedupEngine->clearJobData($jobId);
            
            $stmt = $this->db->prepare("DELETE FROM jobs WHERE id = ?");
            return $stmt->execute([$jobId]);
        } catch (Exception $e) {
            ErrorHandler::log($jobId, 'JOB_DELETE_FAILED', $e->getMessage());
            return false;
        }
    }
    
    public function saveEmail($jobId, $email, $sourceUrl, $confidenceScore) {
        try {
            if ($this->dedupEngine->isEmailSeen($jobId, $email)) {
                return false;
            }
            
            $domain = EmailValidator::extractDomain($email);
            
            $stmt = $this->db->prepare("
                INSERT OR IGNORE INTO emails (job_id, email, domain, source_url, confidence_score) 
                VALUES (?, ?, ?, ?, ?)
            ");
            $result = $stmt->execute([$jobId, $email, $domain, $sourceUrl, $confidenceScore]);
            
            if ($result) {
                $this->dedupEngine->markEmailSeen($jobId, $email);
                
                $stmt = $this->db->prepare("
                    UPDATE jobs 
                    SET total_emails = total_emails + 1 
                    WHERE id = ?
                ");
                $stmt->execute([$jobId]);
            }
            
            return $result;
        } catch (Exception $e) {
            ErrorHandler::log($jobId, 'EMAIL_SAVE_FAILED', $e->getMessage());
            return false;
        }
    }
    
    public function markUrlProcessed($jobId, $url, $emailsFound) {
        try {
            if ($this->dedupEngine->isUrlProcessed($jobId, $url)) {
                return false;
            }
            
            $stmt = $this->db->prepare("
                INSERT OR IGNORE INTO urls_processed (job_id, url, emails_found) 
                VALUES (?, ?, ?)
            ");
            $result = $stmt->execute([$jobId, $url, $emailsFound]);
            
            if ($result) {
                $this->dedupEngine->markUrlProcessed($jobId, $url);
                
                $stmt = $this->db->prepare("
                    UPDATE jobs 
                    SET total_urls_processed = total_urls_processed + 1 
                    WHERE id = ?
                ");
                $stmt->execute([$jobId]);
            }
            
            return $result;
        } catch (Exception $e) {
            ErrorHandler::log($jobId, 'URL_MARK_FAILED', $e->getMessage());
            return false;
        }
    }
    
    public function getDedupEngine() {
        return $this->dedupEngine;
    }
    
    public function getDomainLimiter() {
        return $this->domainLimiter;
    }
    
    public function getSearchScheduler() {
        return $this->searchScheduler;
    }
    
    public function getWorkerGovernor() {
        return $this->workerGovernor;
    }
}

class Supervisor {
    private $jobManager;
    private $lastCheck = 0;
    private $checkInterval = 30;
    
    public function __construct(JobManager $jobManager) {
        $this->jobManager = $jobManager;
    }
    
    public function monitor() {
        if (time() - $this->lastCheck < $this->checkInterval) {
            return;
        }
        
        $this->lastCheck = time();
        
        $jobs = $this->jobManager->getAllJobs();
        
        foreach ($jobs as $job) {
            if ($job['status'] !== 'active') {
                continue;
            }
            
            $workerCount = $this->jobManager->getWorkerGovernor()->getActiveWorkersCount($job['id']);
            
            if ($workerCount === 0) {
                $this->spawnWorkerForJob($job['id']);
            }
        }
    }
    
    private function spawnWorkerForJob($jobId) {
        // Worker spawning is handled externally (e.g., via cron job or external daemon)
        // This method logs the need for a worker, which can be monitored
        // In a production environment, this would trigger an external process manager
        // or queue system to spawn the actual worker process
        ErrorHandler::log($jobId, 'WORKER_SPAWN_NEEDED', 'Job requires worker process');
    }
}

// =====================================================================
// API INTEGRATION
// =====================================================================

class SerperAPI {
    private $apiKey;
    private $baseUrl = 'https://google.serper.dev/search';
    
    public function __construct($apiKey) {
        $this->apiKey = $apiKey;
    }
    
    public function search($query, $num = 10) {
        $data = [
            'q' => $query,
            'num' => $num
        ];
        
        $ch = curl_init($this->baseUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'X-API-KEY: ' . $this->apiKey,
            'Content-Type: application/json'
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        
        if ($error) {
            throw new Exception("CURL Error: $error");
        }
        
        if ($httpCode === 429) {
            throw new Exception("HTTP_429");
        }
        
        if ($httpCode !== 200) {
            throw new Exception("HTTP Error: $httpCode");
        }
        
        return json_decode($response, true);
    }
    
    public function testConnection() {
        try {
            $result = $this->search('test', 1);
            return ['success' => true, 'message' => 'API connection successful'];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
}

// =====================================================================
// WEB INTERFACE
// =====================================================================

$jobManager = new JobManager();
$supervisor = new Supervisor($jobManager);

// Handle AJAX requests
if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    
    $action = $_GET['action'];
    $response = ['success' => false];
    
    switch ($action) {
        case 'get_jobs':
            $response = [
                'success' => true,
                'jobs' => $jobManager->getAllJobs()
            ];
            break;
            
        case 'create_job':
            $name = $_POST['name'] ?? '';
            $terms = $_POST['search_terms'] ?? '';
            
            if (!empty($name) && !empty($terms)) {
                $searchTerms = array_filter(array_map('trim', explode("\n", $terms)));
                $jobId = $jobManager->createJob($name, $searchTerms);
                
                $response = [
                    'success' => $jobId !== false,
                    'job_id' => $jobId,
                    'message' => $jobId !== false ? 'Job created successfully' : 'Failed to create job'
                ];
            } else {
                $response = ['success' => false, 'message' => 'Name and search terms are required'];
            }
            break;
            
        case 'update_job':
            $jobId = $_POST['job_id'] ?? 0;
            $status = $_POST['status'] ?? '';
            
            if ($jobId && $status) {
                $result = $jobManager->updateJob($jobId, ['status' => $status]);
                $response = [
                    'success' => $result,
                    'message' => $result ? 'Job updated successfully' : 'Failed to update job'
                ];
            }
            break;
            
        case 'delete_job':
            $jobId = $_POST['job_id'] ?? 0;
            
            if ($jobId) {
                $result = $jobManager->deleteJob($jobId);
                $response = [
                    'success' => $result,
                    'message' => $result ? 'Job deleted successfully' : 'Failed to delete job'
                ];
            }
            break;
            
        case 'save_api_key':
            $apiKey = $_POST['api_key'] ?? '';
            
            if (!empty($apiKey)) {
                try {
                    $db = Database::getInstance()->getConnection();
                    $stmt = $db->prepare("INSERT OR REPLACE INTO config (key, value) VALUES ('serper_api_key', ?)");
                    $stmt->execute([$apiKey]);
                    
                    $response = [
                        'success' => true,
                        'message' => 'API key saved successfully'
                    ];
                } catch (Exception $e) {
                    $response = ['success' => false, 'message' => $e->getMessage()];
                }
            }
            break;
            
        case 'test_api':
            try {
                $db = Database::getInstance()->getConnection();
                $stmt = $db->prepare("SELECT value FROM config WHERE key = 'serper_api_key'");
                $stmt->execute();
                $apiKey = $stmt->fetchColumn();
                
                if (!$apiKey) {
                    $response = ['success' => false, 'message' => 'API key not configured'];
                } else {
                    $api = new SerperAPI($apiKey);
                    $response = $api->testConnection();
                }
            } catch (Exception $e) {
                $response = ['success' => false, 'message' => $e->getMessage()];
            }
            break;
            
        case 'get_job_details':
            $jobId = $_GET['job_id'] ?? 0;
            
            if ($jobId) {
                $job = $jobManager->getJob($jobId);
                
                if ($job) {
                    $db = Database::getInstance()->getConnection();
                    
                    $stmt = $db->prepare("SELECT COUNT(*) FROM emails WHERE job_id = ?");
                    $stmt->execute([$jobId]);
                    $emailCount = $stmt->fetchColumn();
                    
                    $stmt = $db->prepare("SELECT * FROM emails WHERE job_id = ? ORDER BY extracted_at DESC LIMIT 100");
                    $stmt->execute([$jobId]);
                    $recentEmails = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    
                    $response = [
                        'success' => true,
                        'job' => $job,
                        'email_count' => $emailCount,
                        'recent_emails' => $recentEmails
                    ];
                } else {
                    $response = ['success' => false, 'message' => 'Job not found'];
                }
            }
            break;
            
        case 'export_emails':
            $jobId = $_GET['job_id'] ?? 0;
            
            if ($jobId) {
                try {
                    $db = Database::getInstance()->getConnection();
                    $stmt = $db->prepare("SELECT email, domain, source_url, confidence_score FROM emails WHERE job_id = ? ORDER BY confidence_score DESC");
                    $stmt->execute([$jobId]);
                    $emails = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    
                    header('Content-Type: text/csv');
                    header('Content-Disposition: attachment; filename="emails_job_' . $jobId . '.csv"');
                    
                    $output = fopen('php://output', 'w');
                    fputcsv($output, ['Email', 'Domain', 'Source URL', 'Confidence Score']);
                    
                    foreach ($emails as $email) {
                        fputcsv($output, $email);
                    }
                    
                    fclose($output);
                    exit;
                } catch (Exception $e) {
                    $response = ['success' => false, 'message' => $e->getMessage()];
                }
            }
            break;
    }
    
    echo json_encode($response);
    exit;
}

// Monitor system health
$supervisor->monitor();

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Professional Email Extraction System</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            background: #0a0e27;
            color: #e1e4e8;
            line-height: 1.6;
        }
        
        .container {
            display: flex;
            height: 100vh;
        }
        
        .sidebar {
            width: 320px;
            background: #161b2e;
            border-right: 1px solid #2d3748;
            padding: 20px;
            overflow-y: auto;
        }
        
        .main-content {
            flex: 1;
            padding: 30px;
            overflow-y: auto;
        }
        
        .logo {
            font-size: 24px;
            font-weight: bold;
            color: #4c9aff;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 1px solid #2d3748;
        }
        
        .section {
            margin-bottom: 30px;
        }
        
        .section-title {
            font-size: 14px;
            font-weight: 600;
            color: #8b949e;
            text-transform: uppercase;
            margin-bottom: 15px;
            letter-spacing: 0.5px;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        label {
            display: block;
            font-size: 14px;
            font-weight: 500;
            margin-bottom: 8px;
            color: #c9d1d9;
        }
        
        input[type="text"],
        textarea {
            width: 100%;
            padding: 10px 12px;
            background: #0d1117;
            border: 1px solid #30363d;
            border-radius: 6px;
            color: #e1e4e8;
            font-size: 14px;
            transition: border-color 0.2s;
        }
        
        input[type="text"]:focus,
        textarea:focus {
            outline: none;
            border-color: #4c9aff;
        }
        
        textarea {
            resize: vertical;
            min-height: 80px;
        }
        
        .btn {
            padding: 10px 20px;
            background: #4c9aff;
            color: #fff;
            border: none;
            border-radius: 6px;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            transition: background 0.2s;
            width: 100%;
        }
        
        .btn:hover {
            background: #3a7bd5;
        }
        
        .btn-secondary {
            background: #2d3748;
        }
        
        .btn-secondary:hover {
            background: #3d4758;
        }
        
        .btn-danger {
            background: #d73a49;
        }
        
        .btn-danger:hover {
            background: #cb2431;
        }
        
        .btn-success {
            background: #28a745;
        }
        
        .btn-success:hover {
            background: #22863a;
        }
        
        .btn-group {
            display: flex;
            gap: 10px;
            margin-top: 10px;
        }
        
        .btn-group .btn {
            flex: 1;
        }
        
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
        }
        
        .header h1 {
            font-size: 28px;
            font-weight: 600;
        }
        
        .stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background: #161b2e;
            border: 1px solid #2d3748;
            border-radius: 8px;
            padding: 20px;
        }
        
        .stat-value {
            font-size: 32px;
            font-weight: bold;
            color: #4c9aff;
            margin-bottom: 5px;
        }
        
        .stat-label {
            font-size: 14px;
            color: #8b949e;
        }
        
        .jobs-grid {
            display: grid;
            gap: 20px;
        }
        
        .job-card {
            background: #161b2e;
            border: 1px solid #2d3748;
            border-radius: 8px;
            padding: 20px;
            transition: border-color 0.2s;
        }
        
        .job-card:hover {
            border-color: #4c9aff;
        }
        
        .job-header {
            display: flex;
            justify-content: space-between;
            align-items: start;
            margin-bottom: 15px;
        }
        
        .job-name {
            font-size: 18px;
            font-weight: 600;
            color: #e1e4e8;
        }
        
        .job-status {
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 500;
        }
        
        .status-active {
            background: rgba(40, 167, 69, 0.2);
            color: #28a745;
        }
        
        .status-paused {
            background: rgba(255, 193, 7, 0.2);
            color: #ffc107;
        }
        
        .status-stopped {
            background: rgba(215, 58, 73, 0.2);
            color: #d73a49;
        }
        
        .job-stats {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 15px;
            margin: 15px 0;
        }
        
        .job-stat {
            text-align: center;
        }
        
        .job-stat-value {
            font-size: 20px;
            font-weight: bold;
            color: #4c9aff;
        }
        
        .job-stat-label {
            font-size: 12px;
            color: #8b949e;
        }
        
        .job-actions {
            display: flex;
            gap: 10px;
            margin-top: 15px;
        }
        
        .job-actions .btn {
            flex: 1;
            padding: 8px 16px;
        }
        
        .alert {
            padding: 12px 16px;
            border-radius: 6px;
            margin-bottom: 20px;
            font-size: 14px;
        }
        
        .alert-success {
            background: rgba(40, 167, 69, 0.1);
            border: 1px solid rgba(40, 167, 69, 0.3);
            color: #28a745;
        }
        
        .alert-error {
            background: rgba(215, 58, 73, 0.1);
            border: 1px solid rgba(215, 58, 73, 0.3);
            color: #d73a49;
        }
        
        .alert-info {
            background: rgba(76, 154, 255, 0.1);
            border: 1px solid rgba(76, 154, 255, 0.3);
            color: #4c9aff;
        }
        
        .hidden {
            display: none;
        }
        
        .loading {
            text-align: center;
            padding: 40px;
            color: #8b949e;
        }
        
        .spinner {
            display: inline-block;
            width: 20px;
            height: 20px;
            border: 3px solid rgba(76, 154, 255, 0.3);
            border-radius: 50%;
            border-top-color: #4c9aff;
            animation: spin 1s ease-in-out infinite;
        }
        
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #8b949e;
        }
        
        .empty-state-icon {
            font-size: 48px;
            margin-bottom: 20px;
            opacity: 0.5;
        }
        
        .empty-state-text {
            font-size: 16px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="sidebar">
            <div class="logo">📧 Email Extractor</div>
            
            <div class="section">
                <div class="section-title">API Configuration</div>
                <div class="form-group">
                    <label for="api_key">Serper API Key</label>
                    <input type="text" id="api_key" placeholder="Enter your API key">
                </div>
                <div class="btn-group">
                    <button class="btn btn-success" onclick="saveApiKey()">Save Key</button>
                    <button class="btn btn-secondary" onclick="testApi()">Test API</button>
                </div>
                <div id="api-alert"></div>
            </div>
            
            <div class="section">
                <div class="section-title">Create New Job</div>
                <div class="form-group">
                    <label for="job_name">Job Name</label>
                    <input type="text" id="job_name" placeholder="e.g., Tech Companies">
                </div>
                <div class="form-group">
                    <label for="search_terms">Search Terms (one per line)</label>
                    <textarea id="search_terms" placeholder="e.g.,&#10;tech startup email&#10;software company contact&#10;developer email"></textarea>
                </div>
                <button class="btn" onclick="createJob()">Create Job</button>
                <div id="create-job-alert"></div>
            </div>
        </div>
        
        <div class="main-content">
            <div class="header">
                <h1>Dashboard</h1>
            </div>
            
            <div class="stats">
                <div class="stat-card">
                    <div class="stat-value" id="total-jobs">0</div>
                    <div class="stat-label">Active Jobs</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value" id="total-emails">0</div>
                    <div class="stat-label">Total Emails Extracted</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value" id="total-workers">0</div>
                    <div class="stat-label">Active Workers</div>
                </div>
            </div>
            
            <div class="section">
                <div class="section-title">Jobs</div>
                <div id="jobs-container">
                    <div class="loading">
                        <div class="spinner"></div>
                        <p>Loading jobs...</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        let refreshInterval = null;
        
        function showAlert(containerId, message, type = 'info') {
            const container = document.getElementById(containerId);
            container.innerHTML = `<div class="alert alert-${type}">${message}</div>`;
            setTimeout(() => {
                container.innerHTML = '';
            }, 5000);
        }
        
        async function saveApiKey() {
            const apiKey = document.getElementById('api_key').value.trim();
            
            if (!apiKey) {
                showAlert('api-alert', 'Please enter an API key', 'error');
                return;
            }
            
            try {
                const formData = new FormData();
                formData.append('api_key', apiKey);
                
                const response = await fetch('?action=save_api_key', {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                
                if (result.success) {
                    showAlert('api-alert', 'API key saved successfully!', 'success');
                } else {
                    showAlert('api-alert', result.message || 'Failed to save API key', 'error');
                }
            } catch (error) {
                showAlert('api-alert', 'Error: ' + error.message, 'error');
            }
        }
        
        async function testApi() {
            try {
                showAlert('api-alert', 'Testing API connection...', 'info');
                
                const response = await fetch('?action=test_api');
                const result = await response.json();
                
                if (result.success) {
                    showAlert('api-alert', 'API connection successful!', 'success');
                } else {
                    showAlert('api-alert', result.message || 'API test failed', 'error');
                }
            } catch (error) {
                showAlert('api-alert', 'Error: ' + error.message, 'error');
            }
        }
        
        async function createJob() {
            const name = document.getElementById('job_name').value.trim();
            const terms = document.getElementById('search_terms').value.trim();
            
            if (!name || !terms) {
                showAlert('create-job-alert', 'Please fill in all fields', 'error');
                return;
            }
            
            try {
                const formData = new FormData();
                formData.append('name', name);
                formData.append('search_terms', terms);
                
                const response = await fetch('?action=create_job', {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                
                if (result.success) {
                    showAlert('create-job-alert', 'Job created successfully!', 'success');
                    document.getElementById('job_name').value = '';
                    document.getElementById('search_terms').value = '';
                    loadJobs();
                } else {
                    showAlert('create-job-alert', result.message || 'Failed to create job', 'error');
                }
            } catch (error) {
                showAlert('create-job-alert', 'Error: ' + error.message, 'error');
            }
        }
        
        async function updateJobStatus(jobId, status) {
            try {
                const formData = new FormData();
                formData.append('job_id', jobId);
                formData.append('status', status);
                
                const response = await fetch('?action=update_job', {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                
                if (result.success) {
                    loadJobs();
                } else {
                    alert('Failed to update job: ' + (result.message || 'Unknown error'));
                }
            } catch (error) {
                alert('Error: ' + error.message);
            }
        }
        
        async function deleteJob(jobId) {
            if (!confirm('Are you sure you want to delete this job? All associated data will be removed.')) {
                return;
            }
            
            try {
                const formData = new FormData();
                formData.append('job_id', jobId);
                
                const response = await fetch('?action=delete_job', {
                    method: 'POST',
                    body: formData
                });
                
                const result = await response.json();
                
                if (result.success) {
                    loadJobs();
                } else {
                    alert('Failed to delete job: ' + (result.message || 'Unknown error'));
                }
            } catch (error) {
                alert('Error: ' + error.message);
            }
        }
        
        function exportEmails(jobId) {
            window.location.href = `?action=export_emails&job_id=${jobId}`;
        }
        
        async function loadJobs() {
            try {
                const response = await fetch('?action=get_jobs');
                const result = await response.json();
                
                if (result.success && result.jobs) {
                    displayJobs(result.jobs);
                    updateStats(result.jobs);
                } else {
                    document.getElementById('jobs-container').innerHTML = `
                        <div class="empty-state">
                            <div class="empty-state-icon">📭</div>
                            <div class="empty-state-text">No jobs found. Create one to get started!</div>
                        </div>
                    `;
                }
            } catch (error) {
                document.getElementById('jobs-container').innerHTML = `
                    <div class="alert alert-error">Error loading jobs: ${error.message}</div>
                `;
            }
        }
        
        function displayJobs(jobs) {
            if (jobs.length === 0) {
                document.getElementById('jobs-container').innerHTML = `
                    <div class="empty-state">
                        <div class="empty-state-icon">📭</div>
                        <div class="empty-state-text">No jobs found. Create one to get started!</div>
                    </div>
                `;
                return;
            }
            
            const html = jobs.map(job => `
                <div class="job-card">
                    <div class="job-header">
                        <div class="job-name">${escapeHtml(job.name)}</div>
                        <div class="job-status status-${job.status}">${job.status.toUpperCase()}</div>
                    </div>
                    <div class="job-stats">
                        <div class="job-stat">
                            <div class="job-stat-value">${job.total_emails || 0}</div>
                            <div class="job-stat-label">Emails</div>
                        </div>
                        <div class="job-stat">
                            <div class="job-stat-value">${job.total_urls_processed || 0}</div>
                            <div class="job-stat-label">URLs</div>
                        </div>
                        <div class="job-stat">
                            <div class="job-stat-value">${job.active_workers || 0}</div>
                            <div class="job-stat-label">Workers</div>
                        </div>
                    </div>
                    <div class="job-actions">
                        ${job.status === 'active' ? 
                            `<button class="btn btn-secondary" onclick="updateJobStatus(${job.id}, 'paused')">Pause</button>` :
                            `<button class="btn btn-success" onclick="updateJobStatus(${job.id}, 'active')">Resume</button>`
                        }
                        <button class="btn btn-secondary" onclick="exportEmails(${job.id})">Export</button>
                        <button class="btn btn-danger" onclick="deleteJob(${job.id})">Delete</button>
                    </div>
                </div>
            `).join('');
            
            document.getElementById('jobs-container').innerHTML = `<div class="jobs-grid">${html}</div>`;
        }
        
        function updateStats(jobs) {
            const activeJobs = jobs.filter(j => j.status === 'active').length;
            const totalEmails = jobs.reduce((sum, j) => sum + (j.total_emails || 0), 0);
            const totalWorkers = jobs.reduce((sum, j) => sum + (j.active_workers || 0), 0);
            
            document.getElementById('total-jobs').textContent = activeJobs;
            document.getElementById('total-emails').textContent = totalEmails.toLocaleString();
            document.getElementById('total-workers').textContent = totalWorkers;
        }
        
        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
        
        // Initial load
        loadJobs();
        
        // Auto-refresh every 5 seconds
        refreshInterval = setInterval(loadJobs, 5000);
    </script>
</body>
</html>
