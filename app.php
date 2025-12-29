<?php
/**
 * Complete PHP Scraping System - Single File Application
 * PHP 8.0+ Required
 * 
 * Features:
 * - Setup Wizard
 * - Authentication System
 * - Dashboard
 * - Job Management
 * - Workers Control
 * - Results Export
 * - Google Serper.dev Integration
 * - BloomFilter Deduplication
 * - CLI Worker Support
 */

declare(strict_types=1);

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
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', __DIR__ . '/php_errors.log');
date_default_timezone_set('UTC');

session_start();

// ============================================================================
// DATABASE CLASS
// ============================================================================

class Database {
    private static ?PDO $pdo = null;
    
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
            } catch (PDOException $e) {
                die('Database connection failed: ' . $e->getMessage());
            }
        }
        
        return self::$pdo;
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
                    status ENUM('pending', 'running', 'completed', 'failed') DEFAULT 'pending',
                    progress INT DEFAULT 0,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                    INDEX idx_status (status),
                    INDEX idx_created (created_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
            
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS results (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    job_id INT NOT NULL,
                    title TEXT,
                    link VARCHAR(1000),
                    snippet TEXT,
                    url_hash VARCHAR(64) UNIQUE,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (job_id) REFERENCES jobs(id) ON DELETE CASCADE,
                    INDEX idx_job (job_id),
                    INDEX idx_hash (url_hash)
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
        
        file_put_contents($filePath, $content);
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
    public static function add(string $url): void {
        $hash = self::normalize($url);
        $db = Database::connect();
        
        try {
            $stmt = $db->prepare("INSERT IGNORE INTO bloomfilter (hash) VALUES (?)");
            $stmt->execute([$hash]);
        } catch (PDOException $e) {
            // Duplicate entry is fine
        }
    }
    
    public static function exists(string $url): bool {
        $hash = self::normalize($url);
        $db = Database::connect();
        
        $stmt = $db->prepare("SELECT COUNT(*) as count FROM bloomfilter WHERE hash = ?");
        $stmt->execute([$hash]);
        $result = $stmt->fetch();
        
        return $result['count'] > 0;
    }
    
    private static function normalize(string $url): string {
        // Normalize URL and return SHA-256 hash
        $url = strtolower(trim($url));
        $url = preg_replace('/^https?:\/\/(www\.)?/', '', $url);
        $url = rtrim($url, '/');
        
        return hash('sha256', $url);
    }
}

// ============================================================================
// JOB CLASS
// ============================================================================

class Job {
    public static function create(int $userId, string $query, string $apiKey, int $maxResults): int {
        $db = Database::connect();
        $stmt = $db->prepare("INSERT INTO jobs (user_id, query, api_key, max_results) VALUES (?, ?, ?, ?)");
        $stmt->execute([$userId, $query, $apiKey, $maxResults]);
        
        $jobId = (int)$db->lastInsertId();
        
        // Automatically spawn a worker to process this job
        self::spawnWorker();
        
        return $jobId;
    }
    
    private static function spawnWorker(): void {
        // Only spawn workers in web mode, not CLI
        if (php_sapi_name() === 'cli') {
            return;
        }
        
        $workerName = 'auto-worker-' . uniqid();
        $phpBinary = PHP_BINARY;
        $scriptPath = __FILE__;
        
        // Check OS type
        $isWindows = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';
        
        // Log attempt for debugging
        error_log("Attempting to spawn worker: {$workerName}");
        
        try {
            if ($isWindows) {
                // Windows: use popen with START command
                $command = "start /B \"{$phpBinary}\" \"{$scriptPath}\" {$workerName}";
                $handle = popen($command, 'r');
                if ($handle) {
                    pclose($handle);
                    error_log("Worker spawned successfully on Windows");
                }
            } else {
                // Unix/Linux: Try multiple methods in order of preference
                
                // Method 1: proc_open (best for detaching)
                if (function_exists('proc_open') && !in_array('proc_open', explode(',', ini_get('disable_functions')))) {
                    $descriptors = [
                        0 => ['file', '/dev/null', 'r'],  // stdin
                        1 => ['file', '/dev/null', 'w'],  // stdout
                        2 => ['file', '/dev/null', 'w']   // stderr
                    ];
                    
                    $process = @proc_open(
                        "{$phpBinary} {$scriptPath} {$workerName} &",
                        $descriptors,
                        $pipes,
                        null,
                        null,
                        ['bypass_shell' => false]
                    );
                    
                    if (is_resource($process)) {
                        // Don't wait - this allows the process to run independently
                        error_log("Worker spawned successfully with proc_open");
                        return; // Success
                    }
                }
                
                // Method 2: exec (fallback)
                if (function_exists('exec') && !in_array('exec', explode(',', ini_get('disable_functions')))) {
                    @exec("{$phpBinary} {$scriptPath} {$workerName} > /dev/null 2>&1 &");
                    error_log("Worker spawned successfully with exec");
                    return; // Success
                }
                
                // Method 3: shell_exec (another fallback)
                if (function_exists('shell_exec') && !in_array('shell_exec', explode(',', ini_get('disable_functions')))) {
                    @shell_exec("{$phpBinary} {$scriptPath} {$workerName} > /dev/null 2>&1 &");
                    error_log("Worker spawned successfully with shell_exec");
                    return; // Success
                }
                
                // If we reach here, all methods failed
                error_log("WARNING: Could not spawn worker - all process execution functions are disabled or failed");
            }
        } catch (Exception $e) {
            error_log("ERROR spawning worker: " . $e->getMessage());
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
        $stmt = $db->prepare("SELECT * FROM results WHERE job_id = ? ORDER BY created_at DESC");
        $stmt->execute([$jobId]);
        
        return $stmt->fetchAll();
    }
    
    public static function addResult(int $jobId, string $title, string $link, string $snippet): void {
        if (BloomFilter::exists($link)) {
            return; // Skip duplicates
        }
        
        $db = Database::connect();
        $urlHash = hash('sha256', $link);
        
        try {
            $stmt = $db->prepare("INSERT INTO results (job_id, title, link, snippet, url_hash) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$jobId, $title, $link, $snippet, $urlHash]);
            
            BloomFilter::add($link);
        } catch (PDOException $e) {
            // Duplicate hash, skip
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
    
    public static function processJob(int $jobId): void {
        $job = Job::getById($jobId);
        if (!$job) {
            return;
        }
        
        echo "Processing job #{$jobId}: {$job['query']}\n";
        
        $apiKey = $job['api_key'];
        $query = $job['query'];
        $maxResults = (int)$job['max_results'];
        
        $processed = 0;
        $page = 1;
        
        while ($processed < $maxResults) {
            $data = self::searchSerper($apiKey, $query, $page);
            
            if (!$data || !isset($data['organic'])) {
                break;
            }
            
            foreach ($data['organic'] as $result) {
                if ($processed >= $maxResults) {
                    break;
                }
                
                $title = $result['title'] ?? '';
                $link = $result['link'] ?? '';
                $snippet = $result['snippet'] ?? '';
                
                if ($link) {
                    Job::addResult($jobId, $title, $link, $snippet);
                    $processed++;
                    
                    $progress = (int)(($processed / $maxResults) * 100);
                    Job::updateStatus($jobId, 'running', $progress);
                    
                    echo "  - Added: {$title}\n";
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
        echo "Job #{$jobId} completed!\n";
    }
    
    private static function searchSerper(string $apiKey, string $query, int $page = 1): ?array {
        $url = 'https://google.serper.dev/search';
        
        $data = json_encode([
            'q' => $query,
            'page' => $page,
            'num' => 10
        ]);
        
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
            case 'api':
                self::handleAPI();
                break;
            default:
                self::renderDashboard();
        }
    }
    
    private static function handleCLI(): void {
        global $argv;
        
        echo "=== PHP Scraping System Worker ===\n";
        
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
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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
                
                $stmt = $db->prepare("SELECT COUNT(*) as total FROM results r INNER JOIN jobs j ON r.job_id = j.id WHERE j.user_id = ?");
                $stmt->execute([$userId]);
                $totalResults = $stmt->fetch()['total'];
                
                $stmt = $db->query("SELECT COUNT(*) as total FROM workers WHERE status = 'running'");
                $activeWorkers = $stmt->fetch()['total'];
                
                echo json_encode([
                    'totalJobs' => $totalJobs,
                    'completedJobs' => $completedJobs,
                    'totalResults' => $totalResults,
                    'activeWorkers' => $activeWorkers
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
            header('Content-Disposition: attachment; filename="results-' . $jobId . '.csv"');
            
            $output = fopen('php://output', 'w');
            fputcsv($output, ['Title', 'Link', 'Snippet']);
            
            foreach ($results as $result) {
                fputcsv($output, [$result['title'], $result['link'], $result['snippet']]);
            }
            
            fclose($output);
        } else {
            header('Content-Type: application/json');
            header('Content-Disposition: attachment; filename="results-' . $jobId . '.json"');
            echo json_encode($results, JSON_PRETTY_PRINT);
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
            <title>Setup Wizard - PHP Scraping System</title>
            <style><?php self::getCSS(); ?></style>
        </head>
        <body class="setup-page">
            <div class="setup-container">
                <div class="setup-card">
                    <h1>🚀 Setup Wizard</h1>
                    <p class="subtitle">Welcome! Let's configure your scraping system.</p>
                    
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
            <title>Login - PHP Scraping System</title>
            <style><?php self::getCSS(); ?></style>
        </head>
        <body class="login-page">
            <div class="login-container">
                <div class="login-card">
                    <h1>🔐 Login</h1>
                    <p class="subtitle">Access your scraping dashboard</p>
                    
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
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
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
                    <div class="stat-icon">🔍</div>
                    <div class="stat-content">
                        <div class="stat-value" id="total-results">-</div>
                        <div class="stat-label">Total Results</div>
                    </div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon">⚙️</div>
                    <div class="stat-content">
                        <div class="stat-value" id="active-workers">-</div>
                        <div class="stat-label">Active Workers</div>
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
                                <th>Results</th>
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
                                        $stmt = $db->prepare("SELECT COUNT(*) as count FROM results WHERE job_id = ?");
                                        $stmt->execute([$job['id']]);
                                        $count = $stmt->fetch()['count'];
                                        echo $count;
                                        ?>
                                    </td>
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
                            document.getElementById('active-workers').textContent = data.activeWorkers;
                        });
                }
                
                updateStats();
                setInterval(updateStats, 5000);
            </script>
            <?php
        });
    }
    
    private static function renderNewJob(): void {
        $success = false;
        $canSpawnWorkers = false;
        
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $query = $_POST['query'] ?? '';
            $apiKey = $_POST['api_key'] ?? '';
            $maxResults = (int)($_POST['max_results'] ?? 100);
            
            if ($query && $apiKey) {
                Job::create(Auth::getUserId(), $query, $apiKey, $maxResults);
                $success = true;
            }
        }
        
        // Check if we can spawn workers automatically
        $disabledFunctions = explode(',', ini_get('disable_functions'));
        $canSpawnWorkers = function_exists('proc_open') && !in_array('proc_open', $disabledFunctions)
                        || function_exists('exec') && !in_array('exec', $disabledFunctions)
                        || function_exists('shell_exec') && !in_array('shell_exec', $disabledFunctions);
        
        self::renderLayout('New Job', function() use ($success, $canSpawnWorkers) {
            ?>
            <?php if ($success): ?>
                <div class="alert alert-success">
                    ✓ Job created successfully! 
                    <?php if ($canSpawnWorkers): ?>
                        Worker started automatically to process your job.
                    <?php else: ?>
                        Please start a worker manually to process the job.
                    <?php endif; ?>
                    <a href="?page=dashboard">Go to Dashboard</a>
                </div>
            <?php endif; ?>
            
            <?php if (!$canSpawnWorkers): ?>
                <div class="card" style="background: #fff5e5; border: 1px solid #ffa500; margin-bottom: 20px;">
                    <h3 style="color: #d68910; margin-bottom: 10px;">⚠️ Manual Worker Required</h3>
                    <p style="margin-bottom: 10px;">
                        Automatic worker spawning is disabled on this server. You need to start a worker manually to process jobs.
                    </p>
                    <p style="margin-bottom: 5px;"><strong>Run this command in terminal:</strong></p>
                    <pre class="code-block" style="margin-bottom: 10px;">php <?php echo __FILE__; ?> worker-1</pre>
                    <p style="font-size: 14px; color: #666;">
                        <strong>Tip:</strong> Keep the worker running in the background or use a cron job to start workers automatically.
                    </p>
                </div>
            <?php endif; ?>
            
            <div class="card">
                <h2>Create New Job</h2>
                <?php if ($canSpawnWorkers): ?>
                    <p style="margin-bottom: 20px; color: #4a5568;">
                        ⚡ Workers will start automatically when you create a job - no need to run them manually!
                    </p>
                <?php endif; ?>
                <form method="POST">
                    <div class="form-group">
                        <label>Search Query *</label>
                        <input type="text" name="query" placeholder="e.g., best php tutorials" required>
                    </div>
                    
                    <div class="form-group">
                        <label>Serper.dev API Key *</label>
                        <input type="text" name="api_key" placeholder="Your API key from serper.dev" required>
                        <small>Get your API key from <a href="https://serper.dev" target="_blank">serper.dev</a></small>
                    </div>
                    
                    <div class="form-group">
                        <label>Maximum Results</label>
                        <input type="number" name="max_results" value="100" min="1" max="1000">
                    </div>
                    
                    <button type="submit" class="btn btn-primary">Create Job & Auto-Start Worker</button>
                    <a href="?page=dashboard" class="btn">Cancel</a>
                </form>
            </div>
            <?php
        });
    }
    
    private static function renderSettings(): void {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
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
                    <span>Results: <?php echo count($results); ?></span>
                </div>
                
                <div class="action-bar">
                    <a href="?page=export&job_id=<?php echo $jobId; ?>&format=csv" class="btn">Export CSV</a>
                    <a href="?page=export&job_id=<?php echo $jobId; ?>&format=json" class="btn">Export JSON</a>
                    <a href="?page=dashboard" class="btn">Back to Dashboard</a>
                </div>
            </div>
            
            <div class="card">
                <h2>Results</h2>
                <?php if (empty($results)): ?>
                    <p class="empty-state">No results yet</p>
                <?php else: ?>
                    <div class="results-list">
                        <?php foreach ($results as $result): ?>
                            <div class="result-item">
                                <h3><a href="<?php echo htmlspecialchars($result['link']); ?>" target="_blank"><?php echo htmlspecialchars($result['title']); ?></a></h3>
                                <p class="result-snippet"><?php echo htmlspecialchars($result['snippet']); ?></p>
                                <p class="result-url"><?php echo htmlspecialchars($result['link']); ?></p>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
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
            <title><?php echo htmlspecialchars($title); ?> - PHP Scraping System</title>
            <style><?php self::getCSS(); ?></style>
        </head>
        <body>
            <div class="sidebar">
                <div class="logo">
                    <h1>🔍 Scraper</h1>
                </div>
                <nav class="nav">
                    <a href="?page=dashboard" class="nav-item <?php echo ($_GET['page'] ?? 'dashboard') === 'dashboard' ? 'active' : ''; ?>">
                        📊 Dashboard
                    </a>
                    <a href="?page=new-job" class="nav-item <?php echo ($_GET['page'] ?? '') === 'new-job' ? 'active' : ''; ?>">
                        ➕ New Job
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
