<?php
/**
 * Professional Multi-Job Email Extraction Engine
 * Single-file PHP system for cPanel with Serper API
 * COMPLETE WORKING IMPLEMENTATION WITH ACTUAL EMAIL COLLECTION
 */

// Configuration
define('DB_FILE', __DIR__ . '/email_extraction.db');
define('MAX_WORKERS_PER_JOB', 5);
define('WORKER_TIMEOUT', 300);
define('WORKER_SPAWN_DELAY', 2);

// Check if running as CLI worker or engine
if (php_sapi_name() === 'cli') {
    if (isset($argv[1]) && $argv[1] === 'worker' && isset($argv[2])) {
        require_once __FILE__;
        $worker = new Worker($argv[2]);
        $worker->run();
        exit(0);
    } elseif (isset($argv[1]) && $argv[1] === 'engine' && isset($argv[2])) {
        require_once __FILE__;
        $engine = new JobEngine($argv[2]);
        $engine->start();
        exit(0);
    }
}

// =====================================================================
// DATABASE
// =====================================================================

class Database {
    private static $instance = null;
    private $db;
    
    private function __construct() {
        $this->db = new PDO('sqlite:' . DB_FILE);
        $this->db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->db->exec('PRAGMA journal_mode = WAL');
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
                value TEXT NOT NULL
            )
        ");
        
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS jobs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                search_terms TEXT NOT NULL,
                status TEXT DEFAULT 'stopped',
                created_at INTEGER DEFAULT (strftime('%s', 'now')),
                total_emails INTEGER DEFAULT 0,
                total_urls INTEGER DEFAULT 0,
                emails_high INTEGER DEFAULT 0,
                emails_medium INTEGER DEFAULT 0,
                emails_low INTEGER DEFAULT 0
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
                confidence_level TEXT,
                extracted_at INTEGER DEFAULT (strftime('%s', 'now')),
                UNIQUE(job_id, email),
                FOREIGN KEY(job_id) REFERENCES jobs(id) ON DELETE CASCADE
            )
        ");
        
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS urls_processed (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                job_id INTEGER NOT NULL,
                url TEXT NOT NULL,
                processed_at INTEGER DEFAULT (strftime('%s', 'now')),
                UNIQUE(job_id, url),
                FOREIGN KEY(job_id) REFERENCES jobs(id) ON DELETE CASCADE
            )
        ");
        
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS search_queries (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                job_id INTEGER NOT NULL,
                query TEXT NOT NULL,
                last_run INTEGER DEFAULT 0,
                run_count INTEGER DEFAULT 0,
                emails_found INTEGER DEFAULT 0,
                cooldown INTEGER DEFAULT 0,
                UNIQUE(job_id, query),
                FOREIGN KEY(job_id) REFERENCES jobs(id) ON DELETE CASCADE
            )
        ");
    }
}

// =====================================================================
// FILTERS AND VALIDATORS
// =====================================================================

class URLFilter {
    private static $excludeExtensions = [
        'png', 'jpg', 'jpeg', 'webp', 'gif', 'svg',
        'pdf', 'doc', 'docx', 'xls', 'xlsx',
        'zip', 'rar', 'css', 'js', 'json', 'xml'
    ];
    
    public static function isValid($url) {
        if (empty($url) || !filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }
        
        $path = parse_url($url, PHP_URL_PATH);
        $ext = pathinfo($path, PATHINFO_EXTENSION);
        
        if ($ext && in_array(strtolower($ext), self::$excludeExtensions)) {
            return false;
        }
        
        return true;
    }
}

class EmailValidator {
    private static $blacklistedDomains = [
        'gmail.com', 'yahoo.com', 'hotmail.com', 'outlook.com',
        'google.com', 'apple.com', 'amazon.com', 'microsoft.com',
        'facebook.com', 'meta.com', 'paypal.com', 'visa.com',
        'mastercard.com', 'stripe.com', 'chase.com', 'citi.com',
        'hsbc.com', 'wellsfargo.com', 'bankofamerica.com'
    ];
    
    public static function isValid($email) {
        $email = strtolower(trim($email));
        
        if (preg_match('/\.(png|jpg|pdf|gif|svg|css|js)/', $email)) {
            return false;
        }
        
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return false;
        }
        
        $parts = explode('@', $email);
        if (count($parts) !== 2) {
            return false;
        }
        
        list($local, $domain) = $parts;
        
        if (strlen($local) < 3 || strlen($local) > 30) {
            return false;
        }
        
        if (in_array($domain, self::$blacklistedDomains)) {
            return false;
        }
        
        if (preg_match('/(noreply|no-reply|support|info|admin|billing)@/i', $email)) {
            return false;
        }
        
        return true;
    }
    
    public static function extractDomain($email) {
        $parts = explode('@', strtolower($email));
        return isset($parts[1]) ? $parts[1] : '';
    }
    
    public static function checkMX($domain) {
        return checkdnsrr($domain, 'A') || checkdnsrr($domain, 'MX');
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
                    $domain = EmailValidator::extractDomain($email);
                    if ($domain && EmailValidator::checkMX($domain)) {
                        $emails[] = $email;
                    }
                }
            }
        }
        
        return array_unique($emails);
    }
}

class ConfidenceScorer {
    public static function score($email, $sourceUrl = '') {
        $score = 50.0;
        $domain = EmailValidator::extractDomain($email);
        
        if (preg_match('/\.(com|org|net|edu|gov)$/', $domain)) {
            $score += 15.0;
        }
        
        $localPart = explode('@', $email)[0];
        if (strlen($localPart) >= 4 && strlen($localPart) <= 20) {
            $score += 15.0;
        }
        
        if (!preg_match('/[0-9]{3,}/', $email)) {
            $score += 10.0;
        }
        
        if (!empty($sourceUrl) && stripos($sourceUrl, $domain) !== false) {
            $score += 10.0;
        }
        
        return min(100.0, max(0.0, $score));
    }
    
    public static function getLevel($score) {
        if ($score >= 70) return 'high';
        if ($score >= 50) return 'medium';
        return 'low';
    }
}

// =====================================================================
// SERPER API CLIENT
// =====================================================================

class SerperAPI {
    private $apiKey;
    
    public function __construct($apiKey) {
        $this->apiKey = $apiKey;
    }
    
    public function search($query, $num = 10) {
        $ch = curl_init('https://google.serper.dev/search');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['q' => $query, 'num' => $num]));
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'X-API-KEY: ' . $this->apiKey,
            'Content-Type: application/json'
        ]);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
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
            $this->search('test', 1);
            return ['success' => true, 'message' => 'API connection successful'];
        } catch (Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
}

// =====================================================================
// WORKER (Runs as separate CLI process)
// =====================================================================

class Worker {
    private $jobId;
    private $db;
    
    public function __construct($jobId) {
        $this->jobId = $jobId;
        $this->db = Database::getInstance()->getConnection();
    }
    
    public function run() {
        $stmt = $this->db->prepare("SELECT status FROM jobs WHERE id = ?");
        $stmt->execute([$this->jobId]);
        $job = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$job || $job['status'] !== 'active') {
            return;
        }
        
        $stmt = $this->db->prepare("SELECT value FROM config WHERE key = 'serper_api_key'");
        $stmt->execute();
        $apiKey = $stmt->fetchColumn();
        
        if (!$apiKey) {
            return;
        }
        
        $api = new SerperAPI($apiKey);
        $startTime = time();
        
        while (time() - $startTime < WORKER_TIMEOUT) {
            $stmt = $this->db->prepare("SELECT status FROM jobs WHERE id = ?");
            $stmt->execute([$this->jobId]);
            $job = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$job || $job['status'] !== 'active') {
                break;
            }
            
            $query = $this->getNextQuery();
            if (!$query) {
                sleep(5);
                continue;
            }
            
            try {
                $results = $api->search($query, 10);
                $emailsFound = 0;
                
                if (isset($results['organic'])) {
                    foreach ($results['organic'] as $result) {
                        $url = $result['link'] ?? '';
                        
                        if (!URLFilter::isValid($url) || $this->isUrlProcessed($url)) {
                            continue;
                        }
                        
                        $content = $this->fetchURL($url);
                        if (!$content) {
                            continue;
                        }
                        
                        $emails = EmailExtractor::extractFromContent($content);
                        
                        foreach ($emails as $email) {
                            $score = ConfidenceScorer::score($email, $url);
                            $level = ConfidenceScorer::getLevel($score);
                            
                            if ($this->saveEmail($email, $url, $score, $level)) {
                                $emailsFound++;
                            }
                        }
                        
                        $this->markUrlProcessed($url);
                    }
                }
                
                $this->recordQueryResults($query, $emailsFound);
                
            } catch (Exception $e) {
                if (strpos($e->getMessage(), '429') !== false) {
                    sleep(60);
                }
            }
            
            sleep(2);
        }
    }
    
    private function getNextQuery() {
        $stmt = $this->db->prepare("
            SELECT query FROM search_queries
            WHERE job_id = ? AND cooldown < strftime('%s', 'now')
            ORDER BY last_run ASC
            LIMIT 1
        ");
        $stmt->execute([$this->jobId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ? $result['query'] : null;
    }
    
    private function isUrlProcessed($url) {
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM urls_processed WHERE job_id = ? AND url = ?");
        $stmt->execute([$this->jobId, $url]);
        return $stmt->fetchColumn() > 0;
    }
    
    private function fetchURL($url) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0');
        
        $content = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        return ($httpCode === 200 && $content) ? $content : null;
    }
    
    private function saveEmail($email, $sourceUrl, $score, $level) {
        try {
            $domain = EmailValidator::extractDomain($email);
            
            $stmt = $this->db->prepare("
                INSERT OR IGNORE INTO emails (job_id, email, domain, source_url, confidence_score, confidence_level)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $result = $stmt->execute([$this->jobId, $email, $domain, $sourceUrl, $score, $level]);
            
            if ($result && $stmt->rowCount() > 0) {
                $stmt = $this->db->prepare("
                    UPDATE jobs 
                    SET total_emails = total_emails + 1,
                        emails_$level = emails_$level + 1
                    WHERE id = ?
                ");
                $stmt->execute([$this->jobId]);
                return true;
            }
            return false;
        } catch (Exception $e) {
            return false;
        }
    }
    
    private function markUrlProcessed($url) {
        try {
            $stmt = $this->db->prepare("INSERT OR IGNORE INTO urls_processed (job_id, url) VALUES (?, ?)");
            $result = $stmt->execute([$this->jobId, $url]);
            
            if ($result && $stmt->rowCount() > 0) {
                $stmt = $this->db->prepare("UPDATE jobs SET total_urls = total_urls + 1 WHERE id = ?");
                $stmt->execute([$this->jobId]);
            }
        } catch (Exception $e) {
        }
    }
    
    private function recordQueryResults($query, $emailsFound) {
        $stmt = $this->db->prepare("
            UPDATE search_queries
            SET last_run = strftime('%s', 'now'),
                run_count = run_count + 1,
                emails_found = emails_found + ?,
                cooldown = strftime('%s', 'now') + 300
            WHERE job_id = ? AND query = ?
        ");
        $stmt->execute([$emailsFound, $this->jobId, $query]);
    }
}

// =====================================================================
// JOB ENGINE (Spawns and manages workers)
// =====================================================================

class JobEngine {
    private $jobId;
    private $db;
    
    public function __construct($jobId) {
        $this->jobId = $jobId;
        $this->db = Database::getInstance()->getConnection();
    }
    
    public function start() {
        while (true) {
            $stmt = $this->db->prepare("SELECT status FROM jobs WHERE id = ?");
            $stmt->execute([$this->jobId]);
            $job = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$job || $job['status'] === 'stopped') {
                $this->killAllWorkers();
                break;
            }
            
            if ($job['status'] === 'active') {
                $activeWorkers = $this->getActiveWorkersCount();
                
                while ($activeWorkers < MAX_WORKERS_PER_JOB) {
                    $this->spawnWorker();
                    $activeWorkers++;
                    sleep(WORKER_SPAWN_DELAY);
                }
            } elseif ($job['status'] === 'paused') {
                $this->killAllWorkers();
            }
            
            sleep(10);
        }
    }
    
    private function getActiveWorkersCount() {
        $output = shell_exec("ps aux | grep 'worker {$this->jobId}' | grep -v grep | wc -l");
        return (int)trim($output);
    }
    
    private function spawnWorker() {
        $phpBinary = PHP_BINARY;
        $scriptPath = __FILE__;
        shell_exec("$phpBinary $scriptPath worker {$this->jobId} > /dev/null 2>&1 &");
    }
    
    private function killAllWorkers() {
        $output = shell_exec("ps aux | grep 'worker {$this->jobId}' | grep -v grep | awk '{print \$2}'");
        $pids = array_filter(explode("\n", trim($output)));
        foreach ($pids as $pid) {
            shell_exec("kill -9 $pid 2>/dev/null");
        }
    }
}

// =====================================================================
// JOB MANAGER
// =====================================================================

class JobManager {
    private $db;
    
    public function __construct() {
        $this->db = Database::getInstance()->getConnection();
    }
    
    public function createJob($name, $searchTerms) {
        try {
            $stmt = $this->db->prepare("INSERT INTO jobs (name, search_terms) VALUES (?, ?)");
            $stmt->execute([$name, json_encode($searchTerms)]);
            
            $jobId = $this->db->lastInsertId();
            
            foreach ($searchTerms as $term) {
                $stmt = $this->db->prepare("INSERT INTO search_queries (job_id, query) VALUES (?, ?)");
                $stmt->execute([$jobId, trim($term)]);
            }
            
            return $jobId;
        } catch (Exception $e) {
            return false;
        }
    }
    
    public function updateJob($jobId, $status) {
        try {
            $stmt = $this->db->prepare("UPDATE jobs SET status = ? WHERE id = ?");
            return $stmt->execute([$status, $jobId]);
        } catch (Exception $e) {
            return false;
        }
    }
    
    public function getAllJobs() {
        try {
            $stmt = $this->db->query("SELECT * FROM jobs ORDER BY created_at DESC");
            $jobs = [];
            
            while ($job = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $job['search_terms'] = json_decode($job['search_terms'], true);
                $job['active_workers'] = $this->getActiveWorkersCount($job['id']);
                $jobs[] = $job;
            }
            
            return $jobs;
        } catch (Exception $e) {
            return [];
        }
    }
    
    public function deleteJob($jobId) {
        try {
            $this->killJobWorkers($jobId);
            $stmt = $this->db->prepare("DELETE FROM jobs WHERE id = ?");
            return $stmt->execute([$jobId]);
        } catch (Exception $e) {
            return false;
        }
    }
    
    private function getActiveWorkersCount($jobId) {
        $output = shell_exec("ps aux | grep 'worker {$jobId}' | grep -v grep | wc -l");
        return (int)trim($output);
    }
    
    private function killJobWorkers($jobId) {
        $output = shell_exec("ps aux | grep 'worker {$jobId}' | grep -v grep | awk '{print \$2}'");
        $pids = array_filter(explode("\n", trim($output)));
        foreach ($pids as $pid) {
            shell_exec("kill -9 $pid 2>/dev/null");
        }
        
        $output = shell_exec("ps aux | grep 'engine {$jobId}' | grep -v grep | awk '{print \$2}'");
        $pids = array_filter(explode("\n", trim($output)));
        foreach ($pids as $pid) {
            shell_exec("kill -9 $pid 2>/dev/null");
        }
    }
}

// =====================================================================
// WEB INTERFACE
// =====================================================================

$jobManager = new JobManager();

if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    $action = $_GET['action'];
    $response = ['success' => false];
    
    switch ($action) {
        case 'get_jobs':
            $response = ['success' => true, 'jobs' => $jobManager->getAllJobs()];
            break;
            
        case 'create_job':
            $name = $_POST['name'] ?? '';
            $terms = $_POST['search_terms'] ?? '';
            
            if (!empty($name) && !empty($terms)) {
                $searchTerms = array_filter(array_map('trim', explode("\n", $terms)));
                $jobId = $jobManager->createJob($name, $searchTerms);
                
                if ($jobId) {
                    $jobManager->updateJob($jobId, 'active');
                    $phpBinary = PHP_BINARY;
                    $scriptPath = __FILE__;
                    shell_exec("$phpBinary $scriptPath engine $jobId > /dev/null 2>&1 &");
                }
                
                $response = [
                    'success' => $jobId !== false,
                    'message' => $jobId ? 'Job created and started' : 'Failed'
                ];
            }
            break;
            
        case 'update_job':
            $jobId = $_POST['job_id'] ?? 0;
            $status = $_POST['status'] ?? '';
            if ($jobId && $status) {
                $result = $jobManager->updateJob($jobId, $status);
                $response = ['success' => $result];
            }
            break;
            
        case 'delete_job':
            $jobId = $_POST['job_id'] ?? 0;
            if ($jobId) {
                $result = $jobManager->deleteJob($jobId);
                $response = ['success' => $result];
            }
            break;
            
        case 'save_api_key':
            $apiKey = $_POST['api_key'] ?? '';
            if (!empty($apiKey)) {
                $db = Database::getInstance()->getConnection();
                $stmt = $db->prepare("INSERT OR REPLACE INTO config (key, value) VALUES ('serper_api_key', ?)");
                $stmt->execute([$apiKey]);
                $response = ['success' => true, 'message' => 'API key saved'];
            }
            break;
            
        case 'test_api':
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
            break;
            
        case 'export_emails':
            $jobId = $_GET['job_id'] ?? 0;
            if ($jobId) {
                $db = Database::getInstance()->getConnection();
                $stmt = $db->prepare("SELECT email, domain, source_url, confidence_score, confidence_level FROM emails WHERE job_id = ? ORDER BY confidence_score DESC");
                $stmt->execute([$jobId]);
                
                header('Content-Type: text/csv');
                header('Content-Disposition: attachment; filename="emails_' . $jobId . '.csv"');
                
                $output = fopen('php://output', 'w');
                fputcsv($output, ['Email', 'Domain', 'Source', 'Score', 'Level']);
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    fputcsv($output, $row);
                }
                fclose($output);
                exit;
            }
            break;
            
        case 'get_stats':
            $response = [
                'success' => true,
                'memory_usage' => round(memory_get_usage() / 1024 / 1024, 2) . ' MB'
            ];
            break;
    }
    
    echo json_encode($response);
    exit;
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Email Extraction System</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: #0a0e27; color: #e1e4e8; }
        .container { display: flex; height: 100vh; }
        .sidebar { width: 320px; background: #161b2e; border-right: 1px solid #2d3748; padding: 20px; overflow-y: auto; }
        .main-content { flex: 1; padding: 30px; overflow-y: auto; }
        .logo { font-size: 24px; font-weight: bold; color: #4c9aff; margin-bottom: 30px; border-bottom: 1px solid #2d3748; padding-bottom: 20px; }
        .section { margin-bottom: 30px; }
        .section-title { font-size: 14px; font-weight: 600; color: #8b949e; text-transform: uppercase; margin-bottom: 15px; }
        label { display: block; font-size: 14px; margin-bottom: 8px; }
        input, textarea { width: 100%; padding: 10px; background: #0d1117; border: 1px solid #30363d; border-radius: 6px; color: #e1e4e8; font-size: 14px; }
        textarea { min-height: 80px; resize: vertical; }
        .btn { padding: 10px 20px; background: #4c9aff; color: #fff; border: none; border-radius: 6px; cursor: pointer; width: 100%; }
        .btn:hover { background: #3a7bd5; }
        .btn-group { display: flex; gap: 10px; margin-top: 10px; }
        .btn-group .btn { flex: 1; }
        .btn-success { background: #28a745; }
        .btn-warning { background: #ffc107; color: #000; }
        .btn-danger { background: #d73a49; }
        .btn-secondary { background: #2d3748; }
        .stats { display: grid; grid-template-columns: repeat(4, 1fr); gap: 20px; margin-bottom: 30px; }
        .stat-card { background: #161b2e; border: 1px solid #2d3748; border-radius: 8px; padding: 20px; }
        .stat-value { font-size: 32px; font-weight: bold; color: #4c9aff; }
        .stat-label { font-size: 14px; color: #8b949e; margin-top: 5px; }
        .jobs-grid { display: grid; gap: 20px; }
        .job-card { background: #161b2e; border: 1px solid #2d3748; border-radius: 8px; padding: 20px; }
        .job-header { display: flex; justify-content: space-between; margin-bottom: 15px; }
        .job-name { font-size: 18px; font-weight: 600; }
        .job-status { padding: 4px 12px; border-radius: 12px; font-size: 12px; }
        .status-active { background: rgba(40, 167, 69, 0.2); color: #28a745; }
        .status-paused { background: rgba(255, 193, 7, 0.2); color: #ffc107; }
        .status-stopped { background: rgba(215, 58, 73, 0.2); color: #d73a49; }
        .job-stats { display: grid; grid-template-columns: repeat(3, 1fr); gap: 15px; margin: 15px 0; }
        .job-stat { text-align: center; }
        .job-stat-value { font-size: 20px; font-weight: bold; color: #4c9aff; }
        .job-stat-label { font-size: 12px; color: #8b949e; }
        .confidence-breakdown { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; margin: 15px 0; padding: 15px; background: #0d1117; border-radius: 6px; }
        .confidence-item { text-align: center; }
        .confidence-value { font-size: 18px; font-weight: bold; }
        .confidence-high { color: #28a745; }
        .confidence-medium { color: #ffc107; }
        .confidence-low { color: #6c757d; }
        .job-actions { display: flex; gap: 10px; margin-top: 15px; }
        .job-actions .btn { flex: 1; padding: 8px; font-size: 13px; }
        .alert { padding: 12px; border-radius: 6px; margin-top: 15px; }
        .alert-success { background: rgba(40, 167, 69, 0.1); border: 1px solid rgba(40, 167, 69, 0.3); color: #28a745; }
        .alert-error { background: rgba(215, 58, 73, 0.1); border: 1px solid rgba(215, 58, 73, 0.3); color: #d73a49; }
    </style>
</head>
<body>
    <div class="container">
        <div class="sidebar">
            <div class="logo">📧 Email Extractor Pro</div>
            <div class="section">
                <div class="section-title">API Configuration</div>
                <label>Serper API Key</label>
                <input type="password" id="api_key" placeholder="Enter API key">
                <div class="btn-group">
                    <button class="btn btn-success" onclick="saveApiKey()">Save</button>
                    <button class="btn btn-secondary" onclick="testApi()">Test</button>
                </div>
                <div id="api-alert"></div>
            </div>
            <div class="section">
                <div class="section-title">Create Job</div>
                <label>Job Name</label>
                <input type="text" id="job_name" placeholder="Tech Companies">
                <label style="margin-top: 15px;">Search Terms (one per line)</label>
                <textarea id="search_terms" placeholder="tech startup email&#10;software company contact"></textarea>
                <button class="btn" style="margin-top: 10px;" onclick="createJob()">Create & Start</button>
                <div id="create-alert"></div>
            </div>
        </div>
        <div class="main-content">
            <h1 style="margin-bottom: 30px;">Dashboard</h1>
            <div class="stats">
                <div class="stat-card">
                    <div class="stat-value" id="total-jobs">0</div>
                    <div class="stat-label">Active Jobs</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value" id="total-emails">0</div>
                    <div class="stat-label">Emails Extracted</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value" id="total-workers">0</div>
                    <div class="stat-label">Active Workers</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value" id="memory">0 MB</div>
                    <div class="stat-label">RAM Usage</div>
                </div>
            </div>
            <div class="section-title">Jobs</div>
            <div id="jobs-container">Loading...</div>
        </div>
    </div>
    <script>
        function showAlert(id, msg, type) {
            document.getElementById(id).innerHTML = `<div class="alert alert-${type}">${msg}</div>`;
            setTimeout(() => document.getElementById(id).innerHTML = '', 5000);
        }
        
        async function saveApiKey() {
            const key = document.getElementById('api_key').value.trim();
            if (!key) return showAlert('api-alert', 'Enter API key', 'error');
            const fd = new FormData();
            fd.append('api_key', key);
            const r = await fetch('?action=save_api_key', {method: 'POST', body: fd});
            const j = await r.json();
            showAlert('api-alert', j.message, j.success ? 'success' : 'error');
        }
        
        async function testApi() {
            showAlert('api-alert', 'Testing...', 'success');
            const r = await fetch('?action=test_api');
            const j = await r.json();
            showAlert('api-alert', j.message, j.success ? 'success' : 'error');
        }
        
        async function createJob() {
            const name = document.getElementById('job_name').value.trim();
            const terms = document.getElementById('search_terms').value.trim();
            if (!name || !terms) return showAlert('create-alert', 'Fill all fields', 'error');
            const fd = new FormData();
            fd.append('name', name);
            fd.append('search_terms', terms);
            const r = await fetch('?action=create_job', {method: 'POST', body: fd});
            const j = await r.json();
            if (j.success) {
                document.getElementById('job_name').value = '';
                document.getElementById('search_terms').value = '';
                showAlert('create-alert', j.message, 'success');
                loadJobs();
            } else {
                showAlert('create-alert', j.message, 'error');
            }
        }
        
        async function updateJob(id, status) {
            const fd = new FormData();
            fd.append('job_id', id);
            fd.append('status', status);
            await fetch('?action=update_job', {method: 'POST', body: fd});
            loadJobs();
        }
        
        async function deleteJob(id) {
            if (!confirm('Delete job?')) return;
            const fd = new FormData();
            fd.append('job_id', id);
            await fetch('?action=delete_job', {method: 'POST', body: fd});
            loadJobs();
        }
        
        function exportEmails(id) {
            window.location.href = `?action=export_emails&job_id=${id}`;
        }
        
        async function loadJobs() {
            const r = await fetch('?action=get_jobs');
            const j = await r.json();
            if (j.success && j.jobs) {
                displayJobs(j.jobs);
                updateStats(j.jobs);
            }
            const sr = await fetch('?action=get_stats');
            const sj = await sr.json();
            if (sj.success) {
                document.getElementById('memory').textContent = sj.memory_usage;
            }
        }
        
        function displayJobs(jobs) {
            if (jobs.length === 0) {
                document.getElementById('jobs-container').innerHTML = '<p style="text-align:center;color:#8b949e;">No jobs yet</p>';
                return;
            }
            const html = jobs.map(j => `
                <div class="job-card">
                    <div class="job-header">
                        <div class="job-name">${j.name}</div>
                        <div class="job-status status-${j.status}">${j.status.toUpperCase()}</div>
                    </div>
                    <div class="job-stats">
                        <div class="job-stat">
                            <div class="job-stat-value">${j.total_emails || 0}</div>
                            <div class="job-stat-label">Emails</div>
                        </div>
                        <div class="job-stat">
                            <div class="job-stat-value">${j.total_urls || 0}</div>
                            <div class="job-stat-label">URLs</div>
                        </div>
                        <div class="job-stat">
                            <div class="job-stat-value">${j.active_workers || 0}</div>
                            <div class="job-stat-label">Workers</div>
                        </div>
                    </div>
                    <div class="confidence-breakdown">
                        <div class="confidence-item">
                            <div class="confidence-value confidence-high">${j.emails_high || 0}</div>
                            <div class="job-stat-label">High</div>
                        </div>
                        <div class="confidence-item">
                            <div class="confidence-value confidence-medium">${j.emails_medium || 0}</div>
                            <div class="job-stat-label">Medium</div>
                        </div>
                        <div class="confidence-item">
                            <div class="confidence-value confidence-low">${j.emails_low || 0}</div>
                            <div class="job-stat-label">Low</div>
                        </div>
                    </div>
                    <div class="job-actions">
                        ${j.status === 'active' ? 
                            `<button class="btn btn-warning" onclick="updateJob(${j.id}, 'paused')">⏸ Pause</button>` :
                            `<button class="btn btn-success" onclick="updateJob(${j.id}, 'active')">▶ Start</button>`
                        }
                        <button class="btn btn-secondary" onclick="exportEmails(${j.id})">📥 Export</button>
                        <button class="btn btn-danger" onclick="updateJob(${j.id}, 'stopped')">🛑 Stop</button>
                        <button class="btn btn-danger" onclick="deleteJob(${j.id})">🗑</button>
                    </div>
                </div>
            `).join('');
            document.getElementById('jobs-container').innerHTML = html;
        }
        
        function updateStats(jobs) {
            const active = jobs.filter(j => j.status === 'active').length;
            const emails = jobs.reduce((s, j) => s + (j.total_emails || 0), 0);
            const workers = jobs.reduce((s, j) => s + (j.active_workers || 0), 0);
            document.getElementById('total-jobs').textContent = active;
            document.getElementById('total-emails').textContent = emails.toLocaleString();
            document.getElementById('total-workers').textContent = workers;
        }
        
        loadJobs();
        setInterval(loadJobs, 5000);
    </script>
</body>
</html>
