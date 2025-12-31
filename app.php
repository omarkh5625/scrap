<?php
/**
 * EMAIL EXTRACTION SYSTEM
 *
 * Professional Email Extraction Tool using serper.dev API
 * 
 * Features:
 * - Database Installation Wizard
 * - Admin Authentication System
 * - Multi-worker parallel extraction
 * - Real-time job progress tracking
 * - API job profiles management
 * - Serper.dev API integration for email extraction
 * 
 * Enhanced with:
 * - First-time setup wizard with professional UI
 * - Secure admin login
 * - Automatic database table creation
 * - Efficient parallel worker system
 */

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/error.log');

// Custom error handler for better debugging
set_error_handler(function($errno, $errstr, $errfile, $errline) {
    $error_message = sprintf(
        "[%s] Error #%d: %s in %s on line %d",
        date('Y-m-d H:i:s'),
        $errno,
        $errstr,
        $errfile,
        $errline
    );
    error_log($error_message);
    
    // Display error in development mode ONLY if not an AJAX request
    if (ini_get('display_errors') && !isset($_POST['action']) && !isset($_GET['action'])) {
        echo "<div style='background:#fee; border:2px solid #c00; padding:20px; margin:10px; font-family:monospace;'>";
        echo "<strong>Error:</strong> $errstr<br>";
        echo "<strong>File:</strong> $errfile<br>";
        echo "<strong>Line:</strong> $errline<br>";
        echo "</div>";
    }
    return false;
});

// Exception handler
set_exception_handler(function($exception) {
    $error_message = sprintf(
        "[%s] Exception: %s in %s on line %d\nStack trace:\n%s",
        date('Y-m-d H:i:s'),
        $exception->getMessage(),
        $exception->getFile(),
        $exception->getLine(),
        $exception->getTraceAsString()
    );
    error_log($error_message);
    
    // Display exception in development mode ONLY if not an AJAX request
    if (ini_get('display_errors') && !isset($_POST['action']) && !isset($_GET['action'])) {
        echo "<div style='background:#fee; border:2px solid #c00; padding:20px; margin:10px; font-family:monospace;'>";
        echo "<strong>Exception:</strong> " . htmlspecialchars($exception->getMessage()) . "<br>";
        echo "<strong>File:</strong> " . htmlspecialchars($exception->getFile()) . "<br>";
        echo "<strong>Line:</strong> " . $exception->getLine() . "<br>";
        echo "<strong>Stack trace:</strong><br><pre>" . htmlspecialchars($exception->getTraceAsString()) . "</pre>";
        echo "</div>";
    }
});

///////////////////////
//  LOAD CONFIGURATION
///////////////////////
// Check if config file exists
$configFile = __DIR__ . '/config.php';
$configExists = file_exists($configFile);

// If config doesn't exist and not installing or testing connection, show installation wizard
if (!$configExists && (!isset($_GET['action']) || ($_GET['action'] !== 'install' && $_GET['action'] !== 'test_connection'))) {
    // Show installation wizard
    include_once 'install_wizard.php';
    exit;
}

// Load configuration if it exists
if ($configExists) {
    require_once $configFile;
    // Set globals for CLI workers
    $DB_HOST = defined('DB_HOST') ? DB_HOST : 'localhost';
    $DB_NAME = defined('DB_NAME') ? DB_NAME : '';
    $DB_USER = defined('DB_USER') ? DB_USER : '';
    $DB_PASS = defined('DB_PASS') ? DB_PASS : '';
} else {
    // Defaults for installation
    $DB_HOST = 'localhost';
    $DB_NAME = '';
    $DB_USER = '';
    $DB_PASS = '';
}

///////////////////////
//  CONSTANTS
///////////////////////
// Brand configuration
define('BRAND_NAME', 'EMAIL EXTRACTOR');

// Worker configuration defaults for extraction
define('DEFAULT_WORKERS', 4);
define('DEFAULT_EMAILS_PER_WORKER', 100);
define('MIN_WORKERS', 1);
// MAX_WORKERS removed - NO LIMIT on number of workers - accepts ANY value
define('MIN_EMAILS_PER_WORKER', 1);
define('MAX_EMAILS_PER_WORKER', 10000);
// IMPORTANT: This is ONLY for logging warnings - NOT a limit!
// Workers can be set to ANY number (100, 500, 1000+)
// This threshold just triggers informational log messages for monitoring
define('WORKERS_LOG_WARNING_THRESHOLD', 50);

// Progress update frequency (update progress every N emails extracted)
define('PROGRESS_UPDATE_FREQUENCY', 10);

// Cycle delay configuration (delay between worker processing cycles in milliseconds)
define('DEFAULT_CYCLE_DELAY_MS', 0);
define('MIN_CYCLE_DELAY_MS', 0);
define('MAX_CYCLE_DELAY_MS', 10000); // Max 10 seconds

///////////////////////
//  DATABASE CONNECTION
///////////////////////
$pdo = null;
if ($DB_NAME !== '' && $DB_USER !== '') {
    try {
        $pdo = new PDO(
            "mysql:host={$DB_HOST};dbname={$DB_NAME};charset=utf8mb4",
            $DB_USER,
            $DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
    } catch (Exception $e) {
        if (PHP_SAPI === 'cli') {
            fwrite(STDERR, "DB connection error: " . $e->getMessage() . PHP_EOL);
            exit(1);
        }
        // If web request and not installing, show error
        if (!isset($_GET['action']) || $_GET['action'] !== 'install') {
            die('Database connection error. Please check your configuration.');
        }
    }
}

if (PHP_SAPI === 'cli' && isset($argv) && count($argv) > 1) {
    if ($argv[1] === '--bg-send-profile') {
        $cid = isset($argv[2]) ? (int)$argv[2] : 0;
        $pid = isset($argv[3]) ? (int)$argv[3] : 0;
        $tmpfile = isset($argv[4]) ? $argv[4] : '';
        $recipientsText = '';
        $overrides = [];
        if ($tmpfile && is_readable($tmpfile)) {
            $raw = file_get_contents($tmpfile);
            $dec = json_decode($raw, true);
            if (is_array($dec) && isset($dec['recipients'])) {
                // support recipients as array or string
                if (is_array($dec['recipients'])) {
                    $recipientsText = implode("\n", $dec['recipients']);
                } else {
                    $recipientsText = (string)$dec['recipients'];
                }
                $overrides = isset($dec['overrides']) && is_array($dec['overrides']) ? $dec['overrides'] : [];
            } else {
                $recipientsText = $raw;
            }
        }
        try {
            $campaign = get_campaign($pdo, $cid);
            if ($campaign) {
                send_campaign_real($pdo, $campaign, $recipientsText, false, $pid, $overrides);
            }
        } catch (Exception $e) {
            // nothing to do
        }
        if ($tmpfile) @unlink($tmpfile);
        exit(0);
    }

    if ($argv[1] === '--bg-send') {
        $cid = isset($argv[2]) ? (int)$argv[2] : 0;
        $tmpfile = isset($argv[3]) ? $argv[3] : '';
        $recipientsText = '';
        $overrides = [];
        if ($tmpfile && is_readable($tmpfile)) {
            $raw = file_get_contents($tmpfile);
            $dec = json_decode($raw, true);
            if (is_array($dec) && isset($dec['recipients'])) {
                if (is_array($dec['recipients'])) {
                    $recipientsText = implode("\n", $dec['recipients']);
                } else {
                    $recipientsText = (string)$dec['recipients'];
                }
                $overrides = isset($dec['overrides']) && is_array($dec['overrides']) ? $dec['overrides'] : [];
            } else {
                $recipientsText = $raw;
            }
        }
        try {
            $campaign = get_campaign($pdo, $cid);
            if ($campaign) {
                send_campaign_real($pdo, $campaign, $recipientsText, false, null, $overrides);
            }
        } catch (Exception $e) {
            // nothing to do
        }
        if ($tmpfile) @unlink($tmpfile);
        exit(0);
    }

    if ($argv[1] === '--bg-scan-bounces') {
        try {
            process_imap_bounces($pdo);
        } catch (Exception $e) {
            // ignore
        }
        exit(0);
    }
    
    if ($argv[1] === '--bg-extract') {
        // Background extraction worker
        $jobId = isset($argv[2]) ? (int)$argv[2] : 0;
        if ($jobId > 0) {
            try {
                $job = get_job($pdo, $jobId);
                if ($job && !empty($job['profile_id'])) {
                    // Get profile information
                    $profile_stmt = $pdo->prepare("SELECT * FROM job_profiles WHERE id = ?");
                    $profile_stmt->execute([$job['profile_id']]);
                    $profile = $profile_stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($profile) {
                        // Perform extraction using the shared function
                        perform_extraction($pdo, $jobId, $job, $profile);
                    }
                }
            } catch (Exception $e) {
                error_log("Background extraction worker failed for job {$jobId}: " . $e->getMessage());
                try {
                    $stmt = $pdo->prepare("UPDATE jobs SET status = 'draft', progress_status = 'error' WHERE id = ?");
                    $stmt->execute([$jobId]);
                } catch (Exception $e2) {}
            }
        }
        exit(0);
    }
}

///////////////////////
//  EARLY API HANDLERS (before session)
///////////////////////
// Handle test_connection API endpoint early to avoid session/auth overhead
// This must run before session_start() to prevent any output buffering issues
if (isset($_GET['action']) && $_GET['action'] === 'test_connection') {
    // Suppress display of errors but keep logging enabled
    @ini_set('display_errors', 0);
    @ini_set('log_errors', 1);  // Keep logging enabled for debugging
    // Don't suppress error_reporting completely - still log errors
    
    // Clear ALL output buffers that might exist
    while (ob_get_level()) @ob_end_clean();
    @ob_start();
    
    header('Content-Type: application/json; charset=utf-8');
    
    try {
        // Accept parameters from both POST (direct API calls) and GET (profile list)
        $profile_id = isset($_POST['profile_id']) ? (int)$_POST['profile_id'] : (isset($_GET['profile_id']) ? (int)$_GET['profile_id'] : 0);
        $api_key = trim($_POST['api_key'] ?? $_GET['api_key'] ?? '');
        $search_query = trim($_POST['search_query'] ?? $_GET['search_query'] ?? '');
        
        // If profile_id provided, fetch from database
        if ($profile_id > 0 && $pdo !== null) {
            $stmt = $pdo->prepare("SELECT api_key, search_query FROM job_profiles WHERE id = ?");
            $stmt->execute([$profile_id]);
            $profile = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($profile) {
                $api_key = $profile['api_key'];
                $search_query = $profile['search_query'];
            }
        }
        
        if (empty($api_key)) {
            @ob_end_clean();
            echo json_encode(['success' => false, 'error' => 'API key is required'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        
        if (empty($search_query)) {
            @ob_end_clean();
            echo json_encode(['success' => false, 'error' => 'Search query is required'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        
        // Test API call
        $start_time = microtime(true);
        $ch = curl_init('https://google.serper.dev/search');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'X-API-KEY: ' . $api_key,
            'Content-Type: application/json'
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
            'q' => $search_query,
            'num' => 10
        ]));
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);
        curl_close($ch);
        
        $elapsed_time = round((microtime(true) - $start_time) * 1000); // ms
        
        if ($curl_error) {
            @ob_end_clean();
            echo json_encode([
                'success' => false,
                'error' => 'Connection error: ' . $curl_error,
                'http_code' => 0,
                'elapsed_ms' => $elapsed_time
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        
        $response_preview = substr($response, 0, 500);
        
        if ($http_code === 200) {
            $data = json_decode($response, true);
            $result_count = isset($data['organic']) ? count($data['organic']) : 0;
            
            @ob_end_clean();
            echo json_encode([
                'success' => true,
                'message' => '✓ Connection successful!',
                'http_code' => $http_code,
                'elapsed_ms' => $elapsed_time,
                'result_count' => $result_count,
                'response_preview' => $response_preview
            ], JSON_UNESCAPED_UNICODE);
        } else {
            @ob_end_clean();
            echo json_encode([
                'success' => false,
                'error' => 'API returned HTTP ' . $http_code,
                'http_code' => $http_code,
                'elapsed_ms' => $elapsed_time,
                'response_preview' => $response_preview
            ], JSON_UNESCAPED_UNICODE);
        }
    } catch (Exception $e) {
        @ob_end_clean();
        echo json_encode([
            'success' => false,
            'error' => 'Internal error: ' . $e->getMessage()
        ], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

session_start();

///////////////////////
//  AUTHENTICATION SYSTEM
///////////////////////
// Check if user is logged in (skip for login, logout, install, and tracking actions)
$publicActions = ['login', 'do_login', 'logout', 'install', 'do_install'];
$trackingRequests = ['t' => ['open', 'click', 'unsubscribe']];
$isPublicAction = isset($_GET['action']) && in_array($_GET['action'], $publicActions);
$isTrackingRequest = isset($_GET['t']) && in_array($_GET['t'], $trackingRequests['t']);
$isApiRequest = isset($_GET['api']);

if (!$isPublicAction && !$isTrackingRequest && !$isApiRequest && PHP_SAPI !== 'cli') {
    // Check if installation is complete
    if (!defined('INSTALLED') || !INSTALLED) {
        // Redirect to installation wizard
        if (!isset($_GET['action']) || $_GET['action'] !== 'install') {
            header('Location: ' . $_SERVER['SCRIPT_NAME'] . '?action=install');
            exit;
        }
    } elseif (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
        // Not logged in, show login page
        if (!isset($_GET['action']) || $_GET['action'] !== 'login') {
            header('Location: ' . $_SERVER['SCRIPT_NAME'] . '?action=login');
            exit;
        }
    }
}

///////////////////////
//  AUTHENTICATION HANDLERS
///////////////////////
if (isset($_GET['action']) && $_GET['action'] === 'do_login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    
    if (defined('ADMIN_USERNAME') && defined('ADMIN_PASSWORD_HASH')) {
        if ($username === ADMIN_USERNAME && password_verify($password, ADMIN_PASSWORD_HASH)) {
            $_SESSION['admin_logged_in'] = true;
            $_SESSION['admin_username'] = $username;
            header('Location: ' . $_SERVER['SCRIPT_NAME']);
            exit;
        }
    }
    
    // Login failed
    header('Location: ' . $_SERVER['SCRIPT_NAME'] . '?action=login&error=1');
    exit;
}

if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    session_destroy();
    header('Location: ' . $_SERVER['SCRIPT_NAME'] . '?action=login&logged_out=1');
    exit;
}

// Show login page
if (isset($_GET['action']) && $_GET['action'] === 'login') {
    include_once 'login.php';
    exit;
}

// Show installation wizard
if (isset($_GET['action']) && $_GET['action'] === 'install') {
    include_once 'install_wizard.php';
    exit;
}

///////////////////////
//  OPTIONAL SCHEMA (Job Profiles + Extraction Jobs)
///////////////////////
// Only run schema updates if PDO is connected and user is authenticated
if ($pdo !== null && (isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true)) {
    try {
        // Create extracted_emails table to store extracted emails
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS extracted_emails (
                id INT AUTO_INCREMENT PRIMARY KEY,
                job_id INT NOT NULL,
                email VARCHAR(255) NOT NULL,
                source VARCHAR(500) DEFAULT NULL,
                extracted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX(job_id),
                INDEX(email)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        // Create global settings table
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS settings (
                setting_key VARCHAR(100) PRIMARY KEY,
                setting_value TEXT,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        // Ensure job_profiles table exists (replacing sending_profiles)
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS job_profiles (
                id INT AUTO_INCREMENT PRIMARY KEY,
                profile_name VARCHAR(255) NOT NULL,
                api_key TEXT NOT NULL,
                search_query TEXT NOT NULL,
                target_count INT DEFAULT 100,
                filter_business_only TINYINT(1) DEFAULT 1,
                country VARCHAR(100) DEFAULT '',
                workers INT NOT NULL DEFAULT " . DEFAULT_WORKERS . ",
                emails_per_worker INT NOT NULL DEFAULT " . DEFAULT_EMAILS_PER_WORKER . ",
                cycle_delay_ms INT NOT NULL DEFAULT " . DEFAULT_CYCLE_DELAY_MS . ",
                active TINYINT(1) DEFAULT 1,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        // Ensure jobs table exists (renamed from campaigns)
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS jobs (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(255) NOT NULL,
                status VARCHAR(50) DEFAULT 'draft',
                profile_id INT DEFAULT NULL,
                target_count INT DEFAULT 100,
                progress_extracted INT NOT NULL DEFAULT 0,
                progress_total INT NOT NULL DEFAULT 0,
                progress_status VARCHAR(50) DEFAULT 'draft',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                started_at TIMESTAMP NULL DEFAULT NULL,
                completed_at TIMESTAMP NULL DEFAULT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        // Add job progress tracking fields if they don't exist
        try { 
            $pdo->exec("ALTER TABLE jobs ADD COLUMN IF NOT EXISTS progress_extracted INT NOT NULL DEFAULT 0");
            $pdo->exec("ALTER TABLE jobs ADD COLUMN IF NOT EXISTS progress_total INT NOT NULL DEFAULT 0");
            $pdo->exec("ALTER TABLE jobs ADD COLUMN IF NOT EXISTS progress_status VARCHAR(50) DEFAULT 'draft'");
        } catch (Exception $e) {}

        // Update rotation settings for extraction
        try {
            $pdo->exec("ALTER TABLE rotation_settings ADD COLUMN IF NOT EXISTS workers INT NOT NULL DEFAULT " . DEFAULT_WORKERS);
            $pdo->exec("ALTER TABLE rotation_settings ADD COLUMN IF NOT EXISTS emails_per_worker INT NOT NULL DEFAULT " . DEFAULT_EMAILS_PER_WORKER);
        } catch (Exception $e) {}
        
        // RETROACTIVE FIX: Convert stuck jobs to 'completed'
        // Fix jobs that are 100% complete but stuck at 'extracting' status
        try {
            $pdo->exec("
                UPDATE jobs 
                SET status = 'completed', 
                    completed_at = COALESCE(completed_at, NOW()),
                    progress_status = 'completed'
                WHERE status IN ('extracting', 'queued')
                  AND progress_total > 0
                  AND progress_extracted >= progress_total
                  AND progress_extracted > 0
            ");
            
            // Also fix jobs where progress_status is 'completed' but main status is not 'completed'
            // Only apply to jobs that have actually completed extraction (completed_at is set)
            $pdo->exec("
                UPDATE jobs 
                SET status = 'completed'
                WHERE progress_status = 'completed'
                  AND status != 'completed'
                  AND completed_at IS NOT NULL
            ");
        } catch (Exception $e) {
            error_log("Retroactive job fix error: " . $e->getMessage());
        }
    } catch (Exception $e) {
        // Log error but don't stop execution
        error_log("Schema update error: " . $e->getMessage());
    }
}

///////////////////////
//  HELPERS
///////////////////////
function h($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function uuidv4()
{
    $data = random_bytes(16);
    $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
    $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

function base64url_encode(string $data): string {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function base64url_decode(string $data): string {
    $data = strtr($data, '-_', '+/');
    return base64_decode($data);
}

function get_base_url(): string {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $script = $_SERVER['SCRIPT_NAME'] ?? '/mailer.php';
    return $scheme . '://' . $host . $script;
}

function encode_mime_header(string $str): string {
    if ($str === '') return '';
    
    // Check if string contains any non-ASCII characters or special characters that need encoding
    // According to RFC 2047, we need to encode if there are:
    // - Non-ASCII characters (outside \x20-\x7E range)
    // - Special characters like quotes, parentheses, backslashes, etc.
    // - Characters that have special meaning in email headers
    
    // Check for any character that requires encoding:
    // - Any byte > 127 (non-ASCII, includes UTF-8 multibyte chars, emojis, symbols)
    // - Special RFC 5322 characters: ( ) < > @ , ; : \ " [ ]
    // - Control characters (< \x20)
    if (preg_match('/[\x00-\x1F\x7F-\xFF()<>@,;:\\\"\[\]]/', $str)) {
        // Use RFC 2047 Base64 encoding for the entire string
        // Format: =?charset?encoding?encoded-text?=
        // We use UTF-8 charset and Base64 (B) encoding
        
        // RFC 2047 recommends that encoded-words should not be longer than 75 characters
        // If the string is very long, we may need to split it, but for sender names
        // this is typically not an issue as they're usually short
        
        $encoded = base64_encode($str);
        
        // Check if we need to split into multiple encoded-words
        // Each encoded-word has overhead: =?UTF-8?B? (11 chars) + ?= (2 chars) = 13 chars
        // So we have 75 - 13 = 62 characters available for the encoded content
        $maxEncodedLength = 62;
        
        if (strlen($encoded) <= $maxEncodedLength) {
            // Single encoded-word is sufficient
            return '=?UTF-8?B?' . $encoded . '?=';
        }
        
        // For very long strings, split into multiple encoded-words
        // Each chunk should be on a valid UTF-8 boundary
        // Use mb_substr for safe UTF-8 character boundary detection
        
        // Calculate chunk size in original bytes that fits in maxEncodedLength base64 chars
        // Base64 encoding increases size by 4/3, so original size = maxEncodedLength * 3/4
        $chunkSize = (int)floor($maxEncodedLength * 3 / 4);
        
        // Split the string using mb_substr to respect UTF-8 character boundaries
        $chunks = [];
        $strLenChars = mb_strlen($str, 'UTF-8');
        $offset = 0;
        
        while ($offset < $strLenChars) {
            // Calculate how many UTF-8 characters we can fit
            // Start with an estimate and adjust based on byte length
            $chunkChars = $chunkSize;
            $chunk = mb_substr($str, $offset, $chunkChars, 'UTF-8');
            
            // If chunk is too large in bytes, reduce character count
            while (strlen($chunk) > $chunkSize && $chunkChars > 1) {
                $chunkChars--;
                $chunk = mb_substr($str, $offset, $chunkChars, 'UTF-8');
            }
            
            // Ensure we make progress (at least 1 character)
            if (mb_strlen($chunk, 'UTF-8') === 0) {
                $chunk = mb_substr($str, $offset, 1, 'UTF-8');
            }
            
            $chunks[] = $chunk;
            $offset += mb_strlen($chunk, 'UTF-8');
        }
        
        // Encode each chunk and join with CRLF + space for RFC 5322 compliance
        // This ensures proper folding for long headers
        $encodedWords = [];
        foreach ($chunks as $chunk) {
            $encodedWords[] = '=?UTF-8?B?' . base64_encode($chunk) . '?=';
        }
        
        // Use space as separator (simpler and works for most email clients)
        // For extremely long headers, CRLF folding would be: implode("\r\n ", $encodedWords)
        return implode(' ', $encodedWords);
    }
    
    // String contains only safe ASCII characters, return as-is
    return $str;
}

function is_unsubscribed(PDO $pdo, string $email): bool {
    if ($email === '') return false;
    try {
        $stmt = $pdo->prepare("SELECT 1 FROM unsubscribes WHERE email = ? LIMIT 1");
        $stmt->execute([strtolower($email)]);
        return (bool)$stmt->fetchColumn();
    } catch (Exception $e) {
        return false;
    }
}

function add_to_unsubscribes(PDO $pdo, string $email) {
    if ($email === '') return;
    try {
        $stmt = $pdo->prepare("INSERT IGNORE INTO unsubscribes (email) VALUES (?)");
        $stmt->execute([strtolower($email)]);
    } catch (Exception $e) {
        // ignore
    }
}

/**
 * Try to execute a background command using several strategies.
 * Returns true if a background launch was attempted and likely succeeded.
 */
function try_background_exec(string $cmd): bool {
    $cmd = trim($cmd);
    if ($cmd === '') return false;

    // Windows: try start /B
    if (stripos(PHP_OS, 'WIN') === 0) {
        // Note: Windows background execution is less reliable here; we attempt it.
        $winCmd = "start /B " . $cmd;
        @pclose(@popen($winCmd, "r"));
        return true;
    }

    // Prefer proc_open if available
    if (function_exists('proc_open')) {
        $descriptors = [
            0 => ["pipe", "r"],
            1 => ["file", "/dev/null", "a"],
            2 => ["file", "/dev/null", "a"],
        ];
        $process = @proc_open($cmd, $descriptors, $pipes);
        if (is_resource($process)) {
            @proc_close($process);
            return true;
        }
    }

    // Try exec with async redirection
    if (function_exists('exec')) {
        @exec($cmd . " > /dev/null 2>&1 &");
        return true;
    }

    // Try shell_exec
    if (function_exists('shell_exec')) {
        @shell_exec($cmd . " > /dev/null 2>&1 &");
        return true;
    }

    // Try popen
    if (function_exists('popen')) {
        @pclose(@popen($cmd . " > /dev/null 2>&1 &", "r"));
        return true;
    }

    // No method available
    return false;
}

/**
 * Spawn a detached background PHP process that runs this script with --bg-send
 * Returns true if spawn was likely successful.
 */
function spawn_background_send(PDO $pdo, int $campaignId, string $recipientsText, array $overrides = []): bool {
    $tmpDir = sys_get_temp_dir();
    $tmpFile = tempnam($tmpDir, 'ss_send_');
    if ($tmpFile === false) return false;
    $payload = ['recipients' => $recipientsText, 'overrides' => $overrides];
    if (file_put_contents($tmpFile, json_encode($payload)) === false) {
        @unlink($tmpFile);
        return false;
    }

    $php = PHP_BINARY ?: 'php';
    $script = $_SERVER['SCRIPT_FILENAME'] ?? __FILE__;
    // Build command for UNIX-like systems; quoted args
    $cmd = escapeshellcmd($php) . ' -f ' . escapeshellarg($script)
         . ' -- ' . '--bg-send ' . escapeshellarg((string)$campaignId) . ' ' . escapeshellarg($tmpFile);

    // Try variants: with nohup, with & redirect, etc.
    $candidates = [
        $cmd . " > /dev/null 2>&1 &",
        "nohup " . $cmd . " > /dev/null 2>&1 &",
        $cmd, // fallback
    ];

    foreach ($candidates as $c) {
        if (try_background_exec($c)) {
            // the CLI worker will remove the tmpfile after processing; but if spawn failed and we decide to fallback
            return true;
        }
    }

    // if none worked, cleanup and return false (caller will fallback to synchronous send)
    @unlink($tmpFile);
    return false;
}

/**
 * Spawn a detached background PHP process that runs this script with --bg-send-profile <campaignId> <profileId> <tmpfile>
 * Returns true if spawn was likely successful.
 *
 * Accepts $overrides array which will be written to the tmpfile JSON so the CLI worker can apply runtime overrides
 * such as 'send_rate' (messages/sec).
 */
function spawn_background_send_profile(PDO $pdo, int $campaignId, int $profileId, string $recipientsText, array $overrides = []): bool {
    $tmpDir = sys_get_temp_dir();
    $tmpFile = tempnam($tmpDir, 'ss_send_pf_');
    if ($tmpFile === false) return false;
    $payload = ['recipients' => $recipientsText, 'overrides' => $overrides];
    if (file_put_contents($tmpFile, json_encode($payload)) === false) {
        @unlink($tmpFile);
        return false;
    }

    $php = PHP_BINARY ?: 'php';
    $script = $_SERVER['SCRIPT_FILENAME'] ?? __FILE__;
    $cmd = escapeshellcmd($php) . ' -f ' . escapeshellarg($script)
         . ' -- ' . '--bg-send-profile ' . escapeshellarg((string)$campaignId) . ' ' . escapeshellarg((string)$profileId) . ' ' . escapeshellarg($tmpFile);

    $candidates = [
        $cmd . " > /dev/null 2>&1 &",
        "nohup " . $cmd . " > /dev/null 2>&1 &",
        $cmd,
    ];

    foreach ($candidates as $c) {
        if (try_background_exec($c)) {
            return true;
        }
    }

    @unlink($tmpFile);
    return false;
}

/**
 * Spawn multiple parallel workers for sending emails
 * Spawns the specified number of workers (or fewer if there are fewer recipients than workers)
 * Distributes recipients evenly across workers
 */
function spawn_parallel_workers(PDO $pdo, int $campaignId, array $recipients, int $workers, int $messagesPerWorker, ?int $profileId = null, array $overrides = [], int $cycleDelayMs = 0): array {
    $totalRecipients = count($recipients);
    if ($totalRecipients === 0) return ['success' => true, 'workers' => 0];
    
    // Validate workers parameter - NO MAXIMUM LIMIT - accepts ANY value (1, 100, 500, 1000+)
    // Only enforces minimum of 1 worker
    $workers = max(MIN_WORKERS, $workers);
    
    // OPTIONAL logging for monitoring (NOT a limit - just informational)
    if ($workers >= WORKERS_LOG_WARNING_THRESHOLD) {
        error_log("Info: Large worker count ($workers) for campaign $campaignId. This is NOT an error - just monitoring.");
    }
    
    $messagesPerWorker = max(MIN_MESSAGES_PER_WORKER, $messagesPerWorker);
    $cycleDelayMs = max(MIN_CYCLE_DELAY_MS, min(MAX_CYCLE_DELAY_MS, $cycleDelayMs));
    
    // Spawn up to $workers parallel workers (but not more than total recipients)
    $actualWorkers = min($workers, $totalRecipients);
    
    // Distribute recipients across workers in chunks of messagesPerWorker
    // If messagesPerWorker is small, each worker gets multiple small batches in round-robin fashion
    // If messagesPerWorker is large, we may need fewer workers
    
    $spawnedWorkers = 0;
    $failures = [];
    
    // Distribute all recipients across actualWorkers in a round-robin fashion
    // Each "round" gives each worker up to messagesPerWorker emails
    $workerRecipients = array_fill(0, $actualWorkers, []);
    
    $recipientIdx = 0;
    $roundCount = 0;
    while ($recipientIdx < $totalRecipients) {
        // Apply cycle delay between rounds (except for the first round)
        if ($roundCount > 0 && $cycleDelayMs > 0) {
            usleep($cycleDelayMs * 1000); // Convert milliseconds to microseconds
        }
        
        for ($workerIdx = 0; $workerIdx < $actualWorkers && $recipientIdx < $totalRecipients; $workerIdx++) {
            // Give this worker up to messagesPerWorker emails in this round
            $chunkSize = min($messagesPerWorker, $totalRecipients - $recipientIdx);
            $chunk = array_slice($recipients, $recipientIdx, $chunkSize);
            $workerRecipients[$workerIdx] = array_merge($workerRecipients[$workerIdx], $chunk);
            $recipientIdx += $chunkSize;
        }
        
        $roundCount++;
    }
    
    // Now spawn workers with their allocated recipients
    foreach ($workerRecipients as $workerIdx => $recips) {
        if (empty($recips)) continue;
        
        $chunkText = implode("\n", $recips);
        
        if ($profileId !== null) {
            $spawned = spawn_background_send_profile($pdo, $campaignId, $profileId, $chunkText, $overrides);
        } else {
            $spawned = spawn_background_send($pdo, $campaignId, $chunkText, $overrides);
        }
        
        if ($spawned) {
            $spawnedWorkers++;
        } else {
            $failures[] = ['chunk' => $recips, 'profileId' => $profileId, 'overrides' => $overrides];
        }
    }
    
    return [
        'success' => empty($failures),
        'workers' => $spawnedWorkers,
        'failures' => $failures
    ];
}

/**
 * Spawn a detached background PHP process to scan IMAP bounce mailboxes
 */
function spawn_bounce_scan(PDO $pdo): bool {
    $php = PHP_BINARY ?: 'php';
    $script = $_SERVER['SCRIPT_FILENAME'] ?? __FILE__;
    $cmd = escapeshellcmd($php) . ' -f ' . escapeshellarg($script)
         . ' -- ' . '--bg-scan-bounces';

    $candidates = [
        $cmd . " > /dev/null 2>&1 &",
        "nohup " . $cmd . " > /dev/null 2>&1 &",
        $cmd,
    ];

    foreach ($candidates as $c) {
        if (try_background_exec($c)) {
            return true;
        }
    }
    return false;
}

/**
 * Spawn a detached background PHP process to extract emails for a job
 * Returns true if spawn was likely successful.
 */
function spawn_extraction_worker(PDO $pdo, int $jobId): bool {
    $php = PHP_BINARY ?: 'php';
    $script = $_SERVER['SCRIPT_FILENAME'] ?? __FILE__;
    
    // Build command for background extraction
    $cmd = escapeshellcmd($php) . ' -f ' . escapeshellarg($script)
         . ' -- ' . '--bg-extract ' . escapeshellarg((string)$jobId);
    
    // Try variants: with nohup, with & redirect, etc.
    $candidates = [
        $cmd . " > /dev/null 2>&1 &",
        "nohup " . $cmd . " > /dev/null 2>&1 &",
        $cmd,
    ];
    
    foreach ($candidates as $c) {
        if (try_background_exec($c)) {
            return true;
        }
    }
    return false;
}

/**
 * Perform email extraction for a job (called from fastcgi_finish_request or background worker)
 * This is the core extraction logic, similar to send_campaign_real in the old system
 */
function perform_extraction(PDO $pdo, int $jobId, array $job, array $profile): void {
    error_log("PERFORM_EXTRACTION: ========== STARTING for job_id={$jobId} ==========");
    error_log("PERFORM_EXTRACTION: Job data: " . json_encode($job));
    error_log("PERFORM_EXTRACTION: Profile data: " . json_encode($profile));
    
    try {
        // Update status to extracting
        error_log("PERFORM_EXTRACTION: Updating job status to 'extracting'");
        $stmt = $pdo->prepare("UPDATE jobs SET status = 'extracting', progress_status = 'extracting' WHERE id = ?");
        $stmt->execute([$jobId]);
        error_log("PERFORM_EXTRACTION: Job status updated successfully");
        
        // Get extraction parameters
        $apiKey = $profile['api_key'] ?? '';
        $searchQuery = $profile['search_query'] ?? '';
        $country = $profile['country'] ?? '';
        $businessOnly = !empty($profile['business_only']);
        $targetCount = !empty($job['target_count']) ? (int)$job['target_count'] : (int)($profile['target_count'] ?? 100);
        
        error_log("PERFORM_EXTRACTION: Parameters - apiKey=" . (empty($apiKey) ? 'EMPTY' : 'SET') . ", searchQuery='{$searchQuery}', country='{$country}', businessOnly=" . ($businessOnly ? 'YES' : 'NO') . ", targetCount={$targetCount}");
        
        if (empty($apiKey) || empty($searchQuery)) {
            error_log("PERFORM_EXTRACTION: ERROR - API key or search query is missing");
            throw new Exception("API key or search query is missing");
        }
        
        // Call serper.dev API to extract emails
        error_log("PERFORM_EXTRACTION: Calling extract_emails_serper()...");
        $result = extract_emails_serper($apiKey, $searchQuery, $country, $businessOnly, $targetCount);
        error_log("PERFORM_EXTRACTION: extract_emails_serper() returned: success=" . ($result['success'] ? 'YES' : 'NO'));
        
        if (isset($result['log']) && is_array($result['log'])) {
            foreach ($result['log'] as $logEntry) {
                error_log("PERFORM_EXTRACTION: API LOG: " . $logEntry);
            }
        }
        
        if (!$result['success']) {
            // Mark job as failed and store error message
            $errorMsg = $result['error'] ?? 'Unknown error';
            error_log("PERFORM_EXTRACTION: Extraction failed: {$errorMsg}");
            error_log("PERFORM_EXTRACTION: Full error details: " . json_encode($result));
            // Store complete error message + full API log for UI display
            $apiLog = isset($result['log']) ? implode("\n", $result['log']) : '';
            $fullError = $errorMsg . "\n\n--- API Log ---\n" . $apiLog;
            
            $stmt = $pdo->prepare("UPDATE jobs SET status = 'draft', error_message = ? WHERE id = ?");
            $stmt->execute([$fullError, $jobId]);
            error_log("PERFORM_EXTRACTION: Job {$jobId} extraction failed: " . implode('; ', $result['log'] ?? []));
            throw new Exception("API extraction failed: {$errorMsg}");
        }
        
        $extractedEmails = $result['emails'] ?? [];
        $extractedCount = 0;
        error_log("PERFORM_EXTRACTION: Got " . count($extractedEmails) . " emails from serper.dev");
        
        // Check if any emails were found
        if (empty($extractedEmails)) {
            error_log("PERFORM_EXTRACTION: ERROR - No emails found in search results");
            $errorMsg = "No emails found in search results. This could be because:\n";
            $errorMsg .= "- The search query '{$searchQuery}' returned no results with email addresses\n";
            $errorMsg .= "- All emails were filtered out (only business emails are extracted, free providers like gmail.com are excluded)\n";
            $errorMsg .= "- The search results don't contain visible email addresses\n\n";
            $errorMsg .= "Suggestions:\n";
            $errorMsg .= "- Try a more specific search query (e.g., 'real estate agents california contact')\n";
            $errorMsg .= "- Try a different location or industry\n";
            $errorMsg .= "- Disable 'Business Only' filter if you want all email addresses\n\n";
            $errorMsg .= "--- API Log ---\n" . implode("\n", $result['log'] ?? []);
            
            $stmt = $pdo->prepare("UPDATE jobs SET status = 'draft', progress_status = 'error', error_message = ? WHERE id = ?");
            $stmt->execute([$errorMsg, $jobId]);
            error_log("PERFORM_EXTRACTION: Job marked as draft with error message");
            throw new Exception("No emails found in search results");
        }
        
        // Store extracted emails in database
        foreach ($extractedEmails as $emailData) {
            try {
                $stmt = $pdo->prepare("INSERT INTO extracted_emails (job_id, email, source, extracted_at) VALUES (?, ?, ?, NOW())");
                $stmt->execute([
                    $jobId,
                    $emailData['email'],
                    $emailData['source'] ?? ''
                ]);
                $extractedCount++;
                
                // Update progress every 10 emails
                if ($extractedCount % 10 === 0) {
                    $stmt = $pdo->prepare("UPDATE jobs SET progress_extracted = ? WHERE id = ?");
                    $stmt->execute([$extractedCount, $jobId]);
                    error_log("PERFORM_EXTRACTION: Progress update - {$extractedCount} emails extracted so far");
                }
            } catch (Exception $e) {
                // Skip duplicate emails (unique constraint violation)
                error_log("PERFORM_EXTRACTION: Skipping duplicate email: " . ($emailData['email'] ?? 'unknown'));
                continue;
            }
        }
        
        // Final check: if no emails were actually inserted (all were duplicates)
        if ($extractedCount === 0) {
            error_log("PERFORM_EXTRACTION: ERROR - All emails were duplicates, 0 new emails inserted");
            $errorMsg = "All emails found were duplicates (already extracted in a previous job).\n";
            $errorMsg .= "Found " . count($extractedEmails) . " email(s) but all were already in the database.\n\n";
            $errorMsg .= "Try a different search query to find new email addresses.";
            
            $stmt = $pdo->prepare("UPDATE jobs SET status = 'draft', progress_status = 'error', error_message = ? WHERE id = ?");
            $stmt->execute([$errorMsg, $jobId]);
            error_log("PERFORM_EXTRACTION: Job marked as draft - all duplicates");
            throw new Exception("All emails were duplicates");
        }
        
        // Mark job as completed
        error_log("PERFORM_EXTRACTION: Marking job as completed");
        $stmt = $pdo->prepare("UPDATE jobs SET status = 'completed', progress_status = 'completed', progress_extracted = ?, completed_at = NOW() WHERE id = ?");
        $stmt->execute([$extractedCount, $jobId]);
        
        error_log("PERFORM_EXTRACTION: Job {$jobId} completed successfully. Extracted {$extractedCount} emails.");
        error_log("PERFORM_EXTRACTION: ========== FINISHED ==========");
        
    } catch (Throwable $e) {
        // Mark job as failed
        error_log("PERFORM_EXTRACTION: EXCEPTION CAUGHT - " . $e->getMessage());
        error_log("PERFORM_EXTRACTION: Exception file: " . $e->getFile() . " line: " . $e->getLine());
        error_log("PERFORM_EXTRACTION: Stack trace: " . $e->getTraceAsString());
        
        try {
            $stmt = $pdo->prepare("UPDATE jobs SET status = 'draft', error_message = ? WHERE id = ?");
            $stmt->execute([$e->getMessage(), $jobId]);
            error_log("PERFORM_EXTRACTION: Job marked as draft due to error");
        } catch (Exception $e2) {
            error_log("PERFORM_EXTRACTION: Failed to mark job as draft: " . $e2->getMessage());
        }
        error_log("PERFORM_EXTRACTION: Job {$jobId} extraction error: " . $e->getMessage());
        throw $e;
    }
}

/**
 * Get a global setting value
 */
function get_setting(PDO $pdo, string $key, string $default = ''): string {
    try {
        $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = ?");
        $stmt->execute([$key]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result ? $result['setting_value'] : $default;
    } catch (Exception $e) {
        return $default;
    }
}

/**
 * Set a global setting value
 */
function set_setting(PDO $pdo, string $key, string $value): bool {
    try {
        $stmt = $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?, updated_at = CURRENT_TIMESTAMP");
        $stmt->execute([$key, $value, $value]);
        return true;
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Inject open & click tracking inside HTML
 */
function build_tracked_html(array $campaign, string $recipientEmail = ''): string
{
    global $pdo;
    
    $html = $campaign['html'] ?? '';
    if ($html === '') {
        return '';
    }

    $cid  = (int)$campaign['id'];
    $base = get_base_url();

    $rParam = '';
    if ($recipientEmail !== '') {
        $rParam = '&r=' . rawurlencode(base64url_encode(strtolower($recipientEmail)));
    }

    $unsubscribeEnabled = !empty($campaign['unsubscribe_enabled']) ? true : false;
    
    // Use global tracking settings
    $openTrackingEnabled = (get_setting($pdo, 'open_tracking_enabled', '1') === '1');
    $clickTrackingEnabled = (get_setting($pdo, 'click_tracking_enabled', '1') === '1');

    // Only inject open tracking if enabled
    // Use CSS with inline base64 image to avoid spam filters
    if ($openTrackingEnabled) {
        $openUrl = $base . '?t=open&cid=' . $cid . $rParam;
        // Use CSS with a transparent 1x1 GIF as background - less likely to be flagged as spam
        // Also add a fallback IMG tag for email clients that don't support CSS backgrounds
        $pixel   = '<div style="width:0;height:0;line-height:0;font-size:0;background:transparent url(\'' . $openUrl . '\') no-repeat center;"></div>';

        if (stripos($html, '</body>') !== false) {
            $html = preg_replace('~</body>~i', $pixel . '</body>', $html, 1);
        } else {
            $html .= $pixel;
        }
    }

    $pattern_unsub = '~<a\b([^>]*?)\bhref\s*=\s*(["\'])(.*?)\2([^>]*)>(.*?)</a>~is';
    $foundUnsubAnchor = false;
    $html = preg_replace_callback($pattern_unsub, function ($m) use ($base, $cid, $rParam, $unsubscribeEnabled, &$foundUnsubAnchor) {
        $beforeAttrs = $m[1];
        $quote       = $m[2];
        $href        = $m[3];
        $afterAttrs  = $m[4];
        $innerText   = $m[5];

        if (stripos($innerText, 'unsubscribe') !== false) {
            if (!$unsubscribeEnabled) {
                return '';
            }
            $foundUnsubAnchor = true;
            $unsubUrl = $base . '?t=unsubscribe&cid=' . $cid . $rParam;
            return '<a' . $beforeAttrs . ' href=' . $quote . $unsubUrl . $quote . $afterAttrs . '>' . $innerText . '</a>';
        }
        return $m[0];
    }, $html);

    if ($unsubscribeEnabled && !$foundUnsubAnchor) {
        $unsubUrl = $base . '?t=unsubscribe&cid=' . $cid . $rParam;
        $unsubBlock = '<div style="text-align:center;margin-top:18px;color:#777;font-size:13px;">'
                     . '<a href="' . $unsubUrl . '" style="color:#1A82E2;">Unsubscribe</a>'
                     . '</div>';
        if (stripos($html, '</body>') !== false) {
            $html = preg_replace('~</body>~i', $unsubBlock . '</body>', $html, 1);
        } else {
            $html .= $unsubBlock;
        }
    }

    // Only wrap links with click tracking if enabled
    if ($clickTrackingEnabled) {
        $pattern = '~<a\b([^>]*?)\bhref\s*=\s*(["\'])(.*?)\2([^>]*)>~i';

        $html = preg_replace_callback($pattern, function ($m) use ($base, $cid, $rParam) {
            $beforeAttrs = $m[1];
            $quote       = $m[2];
            $href        = $m[3];
            $afterAttrs  = $m[4];

            $trimHref = trim($href);

            if (
                $trimHref === '' ||
                stripos($trimHref, 'mailto:') === 0 ||
                stripos($trimHref, 'javascript:') === 0 ||
                $trimHref[0] === '#'
            ) {
                return $m[0];
            }

            if (stripos($trimHref, '?t=click') !== false && stripos($trimHref, 'cid=') !== false) {
                return $m[0];
            }
            if (stripos($trimHref, '?t=unsubscribe') !== false && stripos($trimHref, 'cid=') !== false) {
                return $m[0];
            }

            // Check if href contains template tags (e.g., {{email}}, {{name}}, etc.)
            // If it does, preserve them by not encoding the URL yet
            if (preg_match('/\{\{[^}]+\}\}/', $trimHref)) {
                // URL contains template tags - store it without encoding to preserve tags
                // We'll use a special marker to indicate this URL needs tag preservation
                $encodedUrl = base64url_encode($trimHref);
                $trackUrl   = $base . '?t=click&cid=' . $cid . '&u=' . rawurlencode($encodedUrl) . $rParam;
            } else {
                // Normal URL without template tags - encode as usual
                $encodedUrl = base64url_encode($trimHref);
                $trackUrl   = $base . '?t=click&cid=' . $cid . '&u=' . rawurlencode($encodedUrl) . $rParam;
            }

            return '<a' . $beforeAttrs . ' href=' . $quote . $trackUrl . $quote . $afterAttrs . '>';
        }, $html);
    }

    return $html;
}

/**
 * Extract emails using serper.dev API
 * @param string $apiKey The serper.dev API key
 * @param string $query Search query (e.g., "real estate agents california")
 * @param string $country Optional country code (e.g., "us")
 * @param bool $businessOnly Filter for business emails only
 * @param int $numResults Number of results to fetch (default: 10)
 * @return array Array with 'success' boolean and 'emails' array or 'error' string
 */
function extract_emails_serper(string $apiKey, string $query, string $country = '', bool $businessOnly = true, int $numResults = 10): array {
    $log = [];
    
    if ($apiKey === '' || $query === '') {
        return ['success' => false, 'error' => 'Missing API key or search query', 'log' => $log];
    }
    
    $apiUrl = 'https://google.serper.dev/search';
    
    // Build request payload
    $payload = [
        'q' => $query,
        'num' => min($numResults, 100) // serper.dev typically supports up to 100 results
    ];
    
    if ($country !== '') {
        $payload['gl'] = $country;
    }
    
    $headers = [
        'X-API-KEY: ' . $apiKey,
        'Content-Type: application/json'
    ];
    
    $log[] = 'Calling serper.dev API...';
    $log[] = 'Query: ' . $query;
    if ($country) $log[] = 'Country: ' . $country;
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $apiUrl);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    
    if ($response === false) {
        $error = curl_error($ch);
        curl_close($ch);
        $log[] = 'cURL error: ' . $error;
        return ['success' => false, 'error' => 'API request failed: ' . $error, 'log' => $log];
    }
    
    curl_close($ch);
    
    $log[] = 'HTTP Code: ' . $httpCode;
    $log[] = 'Response length: ' . strlen($response) . ' bytes';
    $log[] = 'First 500 chars of response: ' . substr($response, 0, 500);
    
    if ($httpCode !== 200) {
        $log[] = 'FULL ERROR RESPONSE: ' . $response;
        return ['success' => false, 'error' => "HTTP $httpCode: " . substr($response, 0, 200), 'log' => $log];
    }
    
    $data = json_decode($response, true);
    if (!is_array($data)) {
        $log[] = 'Invalid JSON response';
        return ['success' => false, 'error' => 'Invalid API response', 'log' => $log];
    }
    
    // Extract emails from search results
    $emails = [];
    // More robust email pattern that prevents multiple consecutive dots
    $emailPattern = '/[a-zA-Z0-9][a-zA-Z0-9._%+-]*@[a-zA-Z0-9][a-zA-Z0-9.-]*\.[a-zA-Z]{2,}/';
    
    // Check organic results
    if (isset($data['organic']) && is_array($data['organic'])) {
        foreach ($data['organic'] as $result) {
            $text = '';
            if (isset($result['snippet'])) $text .= ' ' . $result['snippet'];
            if (isset($result['title'])) $text .= ' ' . $result['title'];
            if (isset($result['link'])) $text .= ' ' . $result['link'];
            
            // Extract all emails from text
            if (preg_match_all($emailPattern, $text, $matches)) {
                foreach ($matches[0] as $email) {
                    $email = strtolower($email);
                    
                    // Additional validation: check for @ symbol
                    $atPos = strpos($email, '@');
                    if ($atPos === false || $atPos === 0 || $atPos === strlen($email) - 1) {
                        continue; // Invalid email format
                    }
                    
                    // Filter business emails if requested
                    if ($businessOnly) {
                        // Skip common free email providers
                        $freeProviders = ['gmail.com', 'yahoo.com', 'hotmail.com', 'outlook.com', 'aol.com', 'icloud.com'];
                        $domain = substr($email, $atPos + 1);
                        if (in_array($domain, $freeProviders)) {
                            continue;
                        }
                    }
                    
                    // Check if email already added
                    $emailExists = false;
                    foreach ($emails as $existingEmail) {
                        if ($existingEmail['email'] === $email) {
                            $emailExists = true;
                            break;
                        }
                    }
                    
                    if (!$emailExists) {
                        $emails[] = [
                            'email' => $email,
                            'source' => $result['link'] ?? $query
                        ];
                    }
                }
            }
        }
    }
    
    $log[] = 'Extracted ' . count($emails) . ' unique emails';
    
    return [
        'success' => true,
        'emails' => $emails,
        'log' => $log
    ];
}

function smtp_check_connection(array $profile): array {
    $log = [];

    $host = trim($profile['host'] ?? '');
    $port = (int)($profile['port'] ?? 587);
    $user = trim($profile['username'] ?? '');
    $pass = (string)($profile['password'] ?? '');

    if ($host === '') {
        return ['ok'=>false,'msg'=>'Missing SMTP host','log'=>$log];
    }

    $remoteHost = ($port === 465 ? "ssl://{$host}" : $host);

    $errno = 0;
    $errstr = '';
    $socket = @fsockopen($remoteHost, $port, $errno, $errstr, 10);
    if (!$socket) {
        $log[] = "connect_error: {$errno} {$errstr}";
        return ['ok'=>false,'msg'=>"SMTP connect error: {$errno} {$errstr}", 'log'=>$log];
    }
    stream_set_timeout($socket, 10);

    $read = function() use ($socket, &$log) {
        $data = '';
        while ($str = fgets($socket, 515)) {
            $data .= $str;
            $log[] = rtrim($str, "\r\n");
            if (strlen($str) < 4) break;
            if (substr($str, 3, 1) === ' ') break;
        }
        $code = isset($data[0]) ? substr($data, 0, 3) : null;
        $msg  = isset($data[4]) ? trim(substr($data, 4)) : trim($data);
        return [$code, $msg, $data];
    };
    $write = function(string $cmd) use ($socket, $read, &$log) {
        fputs($socket, $cmd . "\r\n");
        $log[] = 'C: ' . $cmd;
        return $read();
    };

    list($gcode, $gmsg) = $read();
    if (!is_string($gcode) || substr($gcode,0,1) !== '2') {
        fclose($socket);
        $log[] = 'banner_failed';
        return ['ok'=>false,'msg'=>"SMTP banner failed: {$gcode} {$gmsg}", 'log'=>$log];
    }

    list($ecode, $emsg) = $write('EHLO localhost');
    if (!is_string($ecode) || substr($ecode,0,1) !== '2') {
        list($hcode, $hmsg) = $write('HELO localhost');
        if (!is_string($hcode) || substr($hcode,0,1) !== '2') {
            fclose($socket);
            $log[] = 'ehlo_failed';
            return ['ok'=>false,'msg'=>"EHLO/HELO failed: {$ecode} / {$hcode}", 'log'=>$log];
        } else {
            $ecode = $hcode; $emsg = $hmsg;
        }
    }

    if ($user !== '') {
        list($acode, $amsg) = $write('AUTH LOGIN');
        if (!is_string($acode) || substr($acode,0,1) !== '3') {
            fclose($socket);
            return ['ok'=>true,'msg'=>'Connected; AUTH not required/accepted','log'=>$log];
        }
        list($ucode, $umsg) = $write(base64_encode($user));
        if (!is_string($ucode) || substr($ucode,0,1) !== '3') {
            fclose($socket);
            return ['ok'=>false,'msg'=>"AUTH username rejected: {$ucode} {$umsg}", 'log'=>$log];
        }
        list($pcode, $pmsg) = $write(base64_encode($pass));
        if (!is_string($pcode) || substr($pcode,0,1) !== '2') {
            fclose($socket);
            return ['ok'=>false,'msg'=>"AUTH password rejected: {$pcode} {$pmsg}", 'log'=>$log];
        }
    }

    $write('QUIT');
    fclose($socket);

    return ['ok'=>true,'msg'=>'SMTP connect OK','log'=>$log];
}

function api_check_connection(array $profile): array {
    $apiUrl = trim($profile['api_url'] ?? '');
    $apiKey = trim($profile['api_key'] ?? '');
    $provider = trim($profile['provider'] ?? '');

    $log = [];

    if ($apiUrl === '') {
        return ['ok'=>false,'msg'=>'Missing API URL','log'=>$log];
    }

    $log[] = 'Testing connection to: ' . $apiUrl;
    $log[] = 'Provider: ' . ($provider ?: 'Generic');

    // Prepare headers
    $headers = ['Content-Type: application/json'];
    if ($apiKey !== '') {
        $headers[] = 'Authorization: Bearer ' . $apiKey;
        $log[] = 'Using API key: ' . substr($apiKey, 0, 10) . '...';
    } else {
        $log[] = 'No API key provided';
    }
    
    if (!empty($profile['headers_json'])) {
        $extra = json_decode($profile['headers_json'], true);
        if (is_array($extra)) {
            foreach ($extra as $k => $v) {
                $headers[] = "{$k}: {$v}";
                $log[] = 'Custom header: ' . $k;
            }
        }
    }

    // Build provider-specific test payload
    $testCampaign = [
        'subject' => 'Connection Test',
        'sender_name' => 'Test Sender'
    ];
    $testPayload = api_build_payload($provider, 'test@example.com', 'test@example.com', $testCampaign, '<p>Test</p>');
    
    if ($testPayload === null) {
        $testPayload = ['test' => 'connection'];
    }

    $log[] = 'Attempting POST request with provider-specific payload...';

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $apiUrl);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($testPayload));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_MAXREDIRS, 3);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    
    // Handle SSL certificate issues (common with APIs)
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
    
    // Get verbose information
    curl_setopt($ch, CURLOPT_VERBOSE, false);

    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlInfo = curl_getinfo($ch);
    
    if ($resp === false) {
        $err = curl_error($ch);
        $errNo = curl_errno($ch);
        curl_close($ch);
        $log[] = 'cURL error #' . $errNo . ': ' . $err;
        
        // If SSL error, try without verification (fallback)
        if ($errNo === 60 || $errNo === 77) {
            $log[] = 'SSL verification failed, retrying without SSL verification...';
            
            $ch2 = curl_init();
            curl_setopt($ch2, CURLOPT_URL, $apiUrl);
            curl_setopt($ch2, CURLOPT_POST, true);
            curl_setopt($ch2, CURLOPT_POSTFIELDS, $testPayload);
            curl_setopt($ch2, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch2, CURLOPT_TIMEOUT, 15);
            curl_setopt($ch2, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch2, CURLOPT_MAXREDIRS, 3);
            curl_setopt($ch2, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch2, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch2, CURLOPT_SSL_VERIFYHOST, 0);
            
            $resp = curl_exec($ch2);
            $code = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
            
            if ($resp === false) {
                $err2 = curl_error($ch2);
                curl_close($ch2);
                $log[] = 'Retry also failed: ' . $err2;
                return ['ok'=>false,'msg'=>"cURL error: {$err}", 'log'=>$log];
            }
            curl_close($ch2);
            $log[] = 'SSL verification bypass succeeded';
        } else {
            return ['ok'=>false,'msg'=>"cURL error: {$err}", 'log'=>$log];
        }
    } else {
        curl_close($ch);
    }

    $log[] = 'HTTP CODE: ' . $code;
    $log[] = 'Response preview: ' . substr($resp, 0, 150) . (strlen($resp) > 150 ? '...' : '');
    $log[] = 'Effective URL: ' . ($curlInfo['url'] ?? $apiUrl);
    
    // Very permissive acceptance criteria for connection testing
    // The goal is to verify the endpoint exists and is reachable, not to validate the full request
    if ($code >= 200 && $code < 300) {
        // 2xx - perfect success
        $log[] = 'Success: API accepted the request';
        return ['ok'=>true,'msg'=>'API connection successful (HTTP ' . $code . ')','log'=>$log];
    } elseif ($code >= 300 && $code < 400) {
        // 3xx - redirect is acceptable
        $log[] = 'Success: API endpoint found (redirect)';
        return ['ok'=>true,'msg'=>'API endpoint found (HTTP ' . $code . ' redirect)','log'=>$log];
    } elseif ($code === 400 || $code === 401 || $code === 403 || $code === 422) {
        // These codes mean endpoint exists but rejected our test request - this is GOOD
        $log[] = 'Success: Endpoint exists and responded (rejected test data as expected)';
        return ['ok'=>true,'msg'=>'API endpoint is reachable and working (HTTP ' . $code . ')','log'=>$log];
    } elseif ($code === 405) {
        // Method not allowed - the endpoint exists but doesn't accept POST
        // Try GET as fallback
        $log[] = 'POST not allowed, trying GET method...';
        
        $ch3 = curl_init();
        curl_setopt($ch3, CURLOPT_URL, $apiUrl);
        curl_setopt($ch3, CURLOPT_HTTPGET, true);
        curl_setopt($ch3, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch3, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch3, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch3, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch3, CURLOPT_SSL_VERIFYHOST, 0);
        
        $resp3 = curl_exec($ch3);
        $code3 = curl_getinfo($ch3, CURLINFO_HTTP_CODE);
        curl_close($ch3);
        
        $log[] = 'GET method returned HTTP ' . $code3;
        
        if ($code3 >= 200 && $code3 < 500 && $code3 !== 404) {
            return ['ok'=>true,'msg'=>'API endpoint is reachable (HTTP ' . $code3 . ' via GET)','log'=>$log];
        }
    } elseif ($code === 404) {
        // 404 - this usually means the URL path is wrong
        $log[] = 'Error: 404 means the endpoint URL path is incorrect';
        $log[] = 'Please verify the complete API URL including the path (e.g., /v1/send)';
        return ['ok'=>false,'msg'=>'API endpoint not found (HTTP 404). Verify the complete URL including path.','log'=>$log];
    } elseif ($code >= 500) {
        // 5xx server error - endpoint exists but server has issues
        $log[] = 'Warning: Server error - endpoint exists but API server has problems';
        return ['ok'=>false,'msg'=>'API server error (HTTP ' . $code . '). The endpoint exists but the server has issues.','log'=>$log];
    } else {
        // Other codes
        $log[] = 'Unexpected HTTP code: ' . $code;
        return ['ok'=>false,'msg'=>'API returned HTTP ' . $code,'log'=>$log];
    }
    
    return ['ok'=>false,'msg'=>'API check completed with HTTP ' . $code,'log'=>$log];
}

/**
 * SMTP send (returns structured array)
 */
function smtp_send_mail(array $profile, array $campaign, string $to, string $html): array
{
    $log = [];

    $host = trim($profile['host'] ?? '');
    $port = (int)($profile['port'] ?? 587);
    $user = trim($profile['username'] ?? '');
    $pass = (string)($profile['password'] ?? '');

    $from = trim($campaign['from_email'] ?? '');

    if ($host === '' || $from === '' || $to === '') {
        return [
            'ok' => false,
            'type' => 'bounce',
            'code' => null,
            'msg' => 'SMTP: missing host/from/to',
            'stage' => 'connect',
            'log' => $log,
        ];
    }

    $remoteHost = ($port === 465 ? "ssl://{$host}" : $host);

    $errno = 0;
    $errstr = '';
    $socket = @fsockopen($remoteHost, $port, $errno, $errstr, 25);
    if (!$socket) {
        return [
            'ok' => false,
            'type' => 'bounce',
            'code' => null,
            'msg' => "SMTP connect error: {$errno} {$errstr}",
            'stage' => 'connect',
            'log' => $log,
        ];
    }
    stream_set_timeout($socket, 25);

    $read = function() use ($socket, &$log) {
        $data = '';
        while ($str = fgets($socket, 515)) {
            $data .= $str;
            $log[] = rtrim($str, "\r\n");
            if (strlen($str) < 4) break;
            if (substr($str, 3, 1) === ' ') break;
        }
        $code = isset($data[0]) ? substr($data, 0, 3) : null;
        $msg  = isset($data[4]) ? trim(substr($data, 4)) : trim($data);
        return [$code, $msg, $data];
    };
    $write = function(string $cmd) use ($socket, $read, &$log) {
        fputs($socket, $cmd . "\r\n");
        $log[] = 'C: ' . $cmd;
        return $read();
    };

    list($gcode, $gmsg) = $read();
    if (!is_string($gcode) || substr($gcode,0,1) !== '2') {
        fclose($socket);
        return [
            'ok' => false,
            'type' => 'bounce',
            'code' => $gcode,
            'msg' => $gmsg,
            'stage' => 'banner',
            'log' => $log,
        ];
    }

    list($ecode, $emsg) = $write('EHLO localhost');
    if (!is_string($ecode) || substr($ecode,0,1) !== '2') {
        list($hcode, $hmsg) = $write('HELO localhost');
        if (!is_string($hcode) || substr($hcode,0,1) !== '2') {
            fclose($socket);
            return [
                'ok' => false,
                'type' => 'bounce',
                'code' => $ecode ?: $hcode,
                'msg' => $emsg ?: $hmsg,
                'stage' => 'ehlo',
                'log' => $log,
            ];
        } else {
            $ecode = $hcode; $emsg = $hmsg;
        }
    }

    if ($port !== 465 && is_string($emsg) && stripos(implode("\n", $log), 'STARTTLS') !== false) {
        list($tcode, $tmsg) = $write('STARTTLS');
        if (!is_string($tcode) || substr($tcode, 0, 3) !== '220') {
            fclose($socket);
            return [
                'ok' => false,
                'type' => 'bounce',
                'code' => $tcode,
                'msg' => $tmsg,
                'stage' => 'starttls',
                'log' => $log,
            ];
        }
        $cryptoMethod = defined('STREAM_CRYPTO_METHOD_TLS_CLIENT')
            ? STREAM_CRYPTO_METHOD_TLS_CLIENT
            : (STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT
               | STREAM_CRYPTO_METHOD_TLSv1_1_CLIENT
               | STREAM_CRYPTO_METHOD_TLSv1_0_CLIENT);

        if (!stream_socket_enable_crypto($socket, true, $cryptoMethod)) {
            fclose($socket);
            return [
                'ok' => false,
                'type' => 'bounce',
                'code' => null,
                'msg' => 'Unable to start TLS crypto',
                'stage' => 'starttls',
                'log' => $log,
            ];
        }
        list($ecode, $emsg) = $write('EHLO localhost');
    }

    if ($user !== '') {
        list($acode, $amsg) = $write('AUTH LOGIN');
        if (!is_string($acode) || substr($acode,0,1) !== '3') {
            fclose($socket);
            return ['ok'=>false, 'type'=>'bounce', 'code'=>$acode, 'msg'=>$amsg, 'stage'=>'auth', 'log'=>$log];
        }
        list($ucode, $umsg) = $write(base64_encode($user));
        if (!is_string($ucode) || substr($ucode,0,1) !== '3') {
            fclose($socket);
            return ['ok'=>false, 'type'=>'bounce', 'code'=>$ucode, 'msg'=>$umsg, 'stage'=>'auth_user', 'log'=>$log];
        }
        list($pcode, $pmsg) = $write(base64_encode($pass));
        if (!is_string($pcode) || substr($pcode,0,1) !== '2') {
            fclose($socket);
            return ['ok'=>false, 'type'=>'bounce', 'code'=>$pcode, 'msg'=>$pmsg, 'stage'=>'auth_pass', 'log'=>$log];
        }
    }

    list($mcode, $mmsg) = $write('MAIL FROM: <' . $from . '>');
    if (!is_string($mcode)) $mcode = null;
    if ($mcode !== null && $mcode !== '' && $mcode[0] === '5') {
        fclose($socket);
        return ['ok'=>false,'type'=>'bounce','code'=>$mcode,'msg'=>$mmsg,'stage'=>'mail_from','log'=>$log];
    } elseif ($mcode !== null && $mcode !== '' && $mcode[0] === '4') {
        fclose($socket);
        return ['ok'=>false,'type'=>'deferred','code'=>$mcode,'msg'=>$mmsg,'stage'=>'mail_from','log'=>$log];
    } elseif ($mcode === null) {
        fclose($socket);
        return ['ok'=>false,'type'=>'bounce','code'=>null,'msg'=>'MAIL FROM unknown response','stage'=>'mail_from','log'=>$log];
    }

    list($rcode, $rmsg) = $write('RCPT TO: <' . $to . '>');
    if (!is_string($rcode)) $rcode = null;
    if ($rcode !== null && $rcode[0] === '5') {
        fclose($socket);
        return ['ok'=>false,'type'=>'bounce','code'=>$rcode,'msg'=>$rmsg,'stage'=>'rcpt_to','log'=>$log];
    } elseif ($rcode !== null && $rcode[0] === '4') {
        fclose($socket);
        return ['ok'=>false,'type'=>'deferred','code'=>$rcode,'msg'=>$rmsg,'stage'=>'rcpt_to','log'=>$log];
    } elseif ($rcode === null) {
        fclose($socket);
        return ['ok'=>false,'type'=>'bounce','code'=>null,'msg'=>'RCPT TO unknown response','stage'=>'rcpt_to','log'=>$log];
    }

    list($dcode, $dmsg) = $write('DATA');
    if (!is_string($dcode) || substr($dcode,0,1) !== '3') {
        fclose($socket);
        return ['ok'=>false,'type'=>'bounce','code'=>$dcode,'msg'=>$dmsg,'stage'=>'data_cmd','log'=>$log];
    }

    $subject = encode_mime_header($campaign['subject'] ?? '');

    $headers = '';
    $headers .= "Date: " . gmdate('D, d M Y H:i:s T') . "\r\n";

    $fromDisplay = '';
    if (!empty($campaign['sender_name'])) {
        $fromDisplay = encode_mime_header($campaign['sender_name']) . " <{$from}>";
    } else {
        $fromDisplay = "<{$from}>";
    }
    $headers .= "From: {$fromDisplay}\r\n";

    $headers .= "To: <{$to}>\r\n";
    if ($subject !== '') {
        $headers .= "Subject: {$subject}\r\n";
    }

    $headers .= "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
    $headers .= "Content-Transfer-Encoding: base64\r\n\r\n";

    $body = chunk_split(base64_encode($html), 76, "\r\n");

    $message = $headers . $body . "\r\n.\r\n";
    fputs($socket, $message);
    $log[] = 'C: <message data...>';

    list($finalCode, $finalMsg) = $read();

    if (!is_string($finalCode) || $finalCode[0] !== '2') {
        fclose($socket);
        $type = 'bounce';
        if (is_string($finalCode) && $finalCode[0] === '4') $type = 'deferred';
        return ['ok'=>false,'type'=>$type,'code'=>$finalCode,'msg'=>$finalMsg,'stage'=>'data_end','log'=>$log];
    }

    $write('QUIT');
    fclose($socket);

    return [
        'ok'    => true,
        'type'  => 'delivered',
        'code'  => $finalCode,
        'msg'   => $finalMsg,
        'stage' => 'done',
        'log'   => $log,
    ];
}

/**
 * Send multiple emails using a single SMTP connection (batch sending)
 * This improves performance by reusing the connection instead of opening/closing for each email
 * 
 * @param array $profile SMTP profile configuration
 * @param array $campaign Campaign data
 * @param array $recipients Array of recipient email addresses
 * @param array $htmlMap Optional map of recipient => custom HTML (if not provided, same HTML for all)
 * @return array Results with 'results' array containing per-recipient results
 */
function smtp_send_batch(array $profile, array $campaign, array $recipients, array $htmlMap = []): array
{
    $log = [];
    $results = [];
    
    if (empty($recipients)) {
        return [
            'ok' => false,
            'error' => 'No recipients provided',
            'log' => $log,
            'results' => []
        ];
    }

    $host = trim($profile['host'] ?? '');
    $port = (int)($profile['port'] ?? 587);
    $user = trim($profile['username'] ?? '');
    $pass = (string)($profile['password'] ?? '');
    $from = trim($campaign['from_email'] ?? '');

    if ($host === '' || $from === '') {
        return [
            'ok' => false,
            'error' => 'Missing SMTP host or from address',
            'log' => $log,
            'results' => []
        ];
    }

    $remoteHost = ($port === 465 ? "ssl://{$host}" : $host);
    
    // Open connection
    $errno = 0;
    $errstr = '';
    $socket = @fsockopen($remoteHost, $port, $errno, $errstr, 25);
    if (!$socket) {
        $error = "SMTP connect error: {$errno} {$errstr}";
        $log[] = $error;
        return [
            'ok' => false,
            'error' => $error,
            'log' => $log,
            'results' => []
        ];
    }
    stream_set_timeout($socket, 25);

    $read = function() use ($socket, &$log) {
        $data = '';
        while ($str = fgets($socket, 515)) {
            $data .= $str;
            $log[] = rtrim($str, "\r\n");
            if (strlen($str) < 4) break;
            if (substr($str, 3, 1) === ' ') break;
        }
        $code = isset($data[0]) ? substr($data, 0, 3) : null;
        $msg  = isset($data[4]) ? trim(substr($data, 4)) : trim($data);
        return [$code, $msg, $data];
    };
    
    $write = function(string $cmd) use ($socket, $read, &$log) {
        fputs($socket, $cmd . "\r\n");
        $log[] = 'C: ' . $cmd;
        return $read();
    };

    // Banner
    list($gcode, $gmsg) = $read();
    if (!is_string($gcode) || substr($gcode,0,1) !== '2') {
        fclose($socket);
        return [
            'ok' => false,
            'error' => "SMTP banner failed: {$gcode} {$gmsg}",
            'log' => $log,
            'results' => []
        ];
    }

    // EHLO/HELO
    list($ecode, $emsg) = $write('EHLO localhost');
    if (!is_string($ecode) || substr($ecode,0,1) !== '2') {
        list($hcode, $hmsg) = $write('HELO localhost');
        if (!is_string($hcode) || substr($hcode,0,1) !== '2') {
            fclose($socket);
            return [
                'ok' => false,
                'error' => "EHLO/HELO failed: {$ecode} / {$hcode}",
                'log' => $log,
                'results' => []
            ];
        } else {
            $ecode = $hcode; $emsg = $hmsg;
        }
    }

    // STARTTLS if needed
    if ($port !== 465 && is_string($emsg) && stripos(implode("\n", $log), 'STARTTLS') !== false) {
        list($tcode, $tmsg) = $write('STARTTLS');
        if (!is_string($tcode) || substr($tcode, 0, 3) !== '220') {
            fclose($socket);
            return [
                'ok' => false,
                'error' => "STARTTLS failed: {$tcode} {$tmsg}",
                'log' => $log,
                'results' => []
            ];
        }
        $cryptoMethod = defined('STREAM_CRYPTO_METHOD_TLS_CLIENT')
            ? STREAM_CRYPTO_METHOD_TLS_CLIENT
            : (STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT
               | STREAM_CRYPTO_METHOD_TLSv1_1_CLIENT
               | STREAM_CRYPTO_METHOD_TLSv1_0_CLIENT);

        if (!stream_socket_enable_crypto($socket, true, $cryptoMethod)) {
            fclose($socket);
            return [
                'ok' => false,
                'error' => 'Unable to start TLS crypto',
                'log' => $log,
                'results' => []
            ];
        }
        list($ecode, $emsg) = $write('EHLO localhost');
    }

    // AUTH
    if ($user !== '') {
        list($acode, $amsg) = $write('AUTH LOGIN');
        if (!is_string($acode) || substr($acode,0,1) !== '3') {
            fclose($socket);
            return [
                'ok' => false,
                'error' => "AUTH LOGIN failed: {$acode} {$amsg}",
                'log' => $log,
                'results' => []
            ];
        }
        list($ucode, $umsg) = $write(base64_encode($user));
        if (!is_string($ucode) || substr($ucode,0,1) !== '3') {
            fclose($socket);
            return [
                'ok' => false,
                'error' => "AUTH username rejected: {$ucode} {$umsg}",
                'log' => $log,
                'results' => []
            ];
        }
        list($pcode, $pmsg) = $write(base64_encode($pass));
        if (!is_string($pcode) || substr($pcode,0,1) !== '2') {
            fclose($socket);
            return [
                'ok' => false,
                'error' => "AUTH password rejected: {$pcode} {$pmsg}",
                'log' => $log,
                'results' => []
            ];
        }
    }

    // Now send each email using the same connection
    foreach ($recipients as $to) {
        $to = trim($to);
        if ($to === '') continue;
        
        // Determine HTML for this recipient
        $html = isset($htmlMap[$to]) ? $htmlMap[$to] : (isset($htmlMap['default']) ? $htmlMap['default'] : '');

        // MAIL FROM
        list($mcode, $mmsg) = $write('MAIL FROM: <' . $from . '>');
        if (!is_string($mcode)) $mcode = null;
        if ($mcode !== null && $mcode !== '' && $mcode[0] === '5') {
            $results[$to] = [
                'ok' => false,
                'type' => 'bounce',
                'code' => $mcode,
                'msg' => $mmsg,
                'stage' => 'mail_from',
                'log' => []
            ];
            // Try to continue with next recipient (some servers allow this)
            continue;
        } elseif ($mcode !== null && $mcode !== '' && $mcode[0] === '4') {
            $results[$to] = [
                'ok' => false,
                'type' => 'deferred',
                'code' => $mcode,
                'msg' => $mmsg,
                'stage' => 'mail_from',
                'log' => []
            ];
            continue;
        } elseif ($mcode === null) {
            $results[$to] = [
                'ok' => false,
                'type' => 'bounce',
                'code' => null,
                'msg' => 'MAIL FROM unknown response',
                'stage' => 'mail_from',
                'log' => []
            ];
            continue;
        }

        // RCPT TO
        list($rcode, $rmsg) = $write('RCPT TO: <' . $to . '>');
        if (!is_string($rcode)) $rcode = null;
        if ($rcode !== null && $rcode[0] === '5') {
            $results[$to] = [
                'ok' => false,
                'type' => 'bounce',
                'code' => $rcode,
                'msg' => $rmsg,
                'stage' => 'rcpt_to',
                'log' => []
            ];
            continue;
        } elseif ($rcode !== null && $rcode[0] === '4') {
            $results[$to] = [
                'ok' => false,
                'type' => 'deferred',
                'code' => $rcode,
                'msg' => $rmsg,
                'stage' => 'rcpt_to',
                'log' => []
            ];
            continue;
        } elseif ($rcode === null) {
            $results[$to] = [
                'ok' => false,
                'type' => 'bounce',
                'code' => null,
                'msg' => 'RCPT TO unknown response',
                'stage' => 'rcpt_to',
                'log' => []
            ];
            continue;
        }

        // DATA command
        list($dcode, $dmsg) = $write('DATA');
        if (!is_string($dcode) || substr($dcode,0,1) !== '3') {
            $results[$to] = [
                'ok' => false,
                'type' => 'bounce',
                'code' => $dcode,
                'msg' => $dmsg,
                'stage' => 'data_cmd',
                'log' => []
            ];
            continue;
        }

        // Build message
        $subject = encode_mime_header($campaign['subject'] ?? '');
        $headers = '';
        $headers .= "Date: " . gmdate('D, d M Y H:i:s T') . "\r\n";

        $fromDisplay = '';
        if (!empty($campaign['sender_name'])) {
            $fromDisplay = encode_mime_header($campaign['sender_name']) . " <{$from}>";
        } else {
            $fromDisplay = "<{$from}>";
        }
        $headers .= "From: {$fromDisplay}\r\n";
        $headers .= "To: <{$to}>\r\n";
        if ($subject !== '') {
            $headers .= "Subject: {$subject}\r\n";
        }
        $headers .= "MIME-Version: 1.0\r\n";
        $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
        $headers .= "Content-Transfer-Encoding: base64\r\n\r\n";

        $body = chunk_split(base64_encode($html), 76, "\r\n");
        
        // Send message data
        fputs($socket, $headers . $body);
        // Send termination sequence (CRLF.CRLF)
        fputs($socket, ".\r\n");
        $log[] = 'C: <message data...>';

        list($finalCode, $finalMsg) = $read();

        if (!is_string($finalCode) || $finalCode[0] !== '2') {
            $type = 'bounce';
            if (is_string($finalCode) && $finalCode[0] === '4') $type = 'deferred';
            $results[$to] = [
                'ok' => false,
                'type' => $type,
                'code' => $finalCode,
                'msg' => $finalMsg,
                'stage' => 'data_end',
                'log' => []
            ];
        } else {
            $results[$to] = [
                'ok' => true,
                'type' => 'delivered',
                'code' => $finalCode,
                'msg' => $finalMsg,
                'stage' => 'done',
                'log' => []
            ];
        }
    }

    // Close connection
    $write('QUIT');
    fclose($socket);

    // Check overall success
    $successCount = 0;
    foreach ($results as $result) {
        if (!empty($result['ok'])) $successCount++;
    }

    return [
        'ok' => $successCount > 0,
        'total' => count($recipients),
        'success' => $successCount,
        'failed' => count($recipients) - $successCount,
        'log' => $log,
        'results' => $results
    ];
}

function api_send_mail(array $profile, array $campaign, string $to, string $html): array
{
    $apiUrl = trim($profile['api_url'] ?? '');
    $apiKey = trim($profile['api_key'] ?? '');
    $from   = trim($campaign['from_email'] ?? '');
    $provider = trim($profile['provider'] ?? '');

    $log = [];

    if ($apiUrl === '' || $from === '' || $to === '') {
        return [
            'ok' => false,
            'type' => 'bounce',
            'code' => null,
            'msg' => 'API: missing api_url/from/to',
            'stage' => 'api',
            'log' => $log,
        ];
    }

    $headers = [
        'Content-Type: application/json',
    ];
    if ($apiKey !== '') {
        $headers[] = 'Authorization: Bearer ' . $apiKey;
    }

    if (!empty($profile['headers_json'])) {
        $extra = json_decode($profile['headers_json'], true);
        if (is_array($extra)) {
            foreach ($extra as $k => $v) {
                $headers[] = $k . ': ' . $v;
            }
        }
    }

    // Build provider-specific payload
    $payload = api_build_payload($provider, $from, $to, $campaign, $html);
    
    if ($payload === null) {
        return [
            'ok' => false,
            'type' => 'bounce',
            'code' => null,
            'msg' => 'API: unsupported provider format',
            'stage' => 'api',
            'log' => $log,
        ];
    }

    $log[] = 'API POST ' . $apiUrl . ' Provider: ' . $provider;
    $log[] = 'Payload: ' . substr(json_encode($payload), 0, 500);

    $ch = curl_init($apiUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_TIMEOUT, 25);

    $resp = curl_exec($ch);
    if ($resp === false) {
        $err = curl_error($ch);
        curl_close($ch);
        $log[] = 'CURL ERROR: ' . $err;
        return [
            'ok' => false,
            'type' => 'bounce',
            'code' => null,
            'msg' => 'API cURL error: ' . $err,
            'stage' => 'api',
            'log' => $log,
        ];
    }

    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $log[] = 'API RESPONSE CODE: ' . $code . ' BODY: ' . substr($resp,0,1000);

    if ($code >= 200 && $code < 300) {
        return [
            'ok' => true,
            'type' => 'delivered',
            'code' => (string)$code,
            'msg'  => substr($resp,0,1000),
            'stage'=> 'api',
            'log'  => $log,
        ];
    }

    $etype = ($code >= 500) ? 'bounce' : 'deferred';
    return [
        'ok' => false,
        'type' => $etype,
        'code' => (string)$code,
        'msg'  => substr($resp,0,1000),
        'stage'=> 'api',
        'log'  => $log,
    ];
}

/**
 * Build API payload based on provider
 */
function api_build_payload(string $provider, string $from, string $to, array $campaign, string $html): ?array
{
    $subject = $campaign['subject'] ?? '';
    $fromName = $campaign['sender_name'] ?? '';
    
    // Detect provider from URL if not explicitly set
    $providerLower = strtolower($provider);
    
    // SparkPost format
    if ($providerLower === 'sparkpost' || strpos($providerLower, 'sparkpost') !== false) {
        return [
            'options' => [
                'open_tracking' => false,
                'click_tracking' => false,
            ],
            'content' => [
                'from' => [
                    'email' => $from,
                    'name' => $fromName ?: $from,
                ],
                'subject' => $subject,
                'html' => $html,
            ],
            'recipients' => [
                ['address' => $to]
            ]
        ];
    }
    
    // SendGrid format
    if ($providerLower === 'sendgrid api' || strpos($providerLower, 'sendgrid') !== false) {
        $payload = [
            'personalizations' => [
                [
                    'to' => [
                        ['email' => $to]
                    ]
                ]
            ],
            'from' => [
                'email' => $from
            ],
            'subject' => $subject,
            'content' => [
                [
                    'type' => 'text/html',
                    'value' => $html
                ]
            ]
        ];
        
        if ($fromName) {
            $payload['from']['name'] = $fromName;
        }
        
        return $payload;
    }
    
    // Mailgun format
    if ($providerLower === 'mailgun' || strpos($providerLower, 'mailgun') !== false) {
        // Note: Mailgun typically uses form-data, not JSON
        // This is a JSON approximation - actual implementation may need form-data
        return [
            'from' => $fromName ? "{$fromName} <{$from}>" : $from,
            'to' => $to,
            'subject' => $subject,
            'html' => $html,
        ];
    }
    
    // MailJet format
    if ($providerLower === 'mailjet api' || strpos($providerLower, 'mailjet') !== false) {
        return [
            'Messages' => [
                [
                    'From' => [
                        'Email' => $from,
                        'Name' => $fromName ?: $from,
                    ],
                    'To' => [
                        [
                            'Email' => $to
                        ]
                    ],
                    'Subject' => $subject,
                    'HTMLPart' => $html,
                ]
            ]
        ];
    }
    
    // Generic/fallback format (similar to SendGrid)
    return [
        'personalizations' => [
            [
                'to' => [
                    ['email' => $to]
                ]
            ]
        ],
        'from' => [
            'email' => $from,
            'name' => $fromName ?: $from
        ],
        'subject' => $subject,
        'content' => [
            [
                'type' => 'text/html',
                'value' => $html
            ]
        ]
    ];
}

/**
 * Send emails via API (sequential processing)
 * Processes multiple email sends through the API endpoint sequentially.
 * For parallel high-speed sending, use the existing spawn_parallel_workers() infrastructure.
 * 
 * @param array $profile API profile configuration
 * @param array $campaign Campaign data
 * @param array $recipients Array of recipient email addresses
 * @param array $htmlMap Optional map of recipient => custom HTML (if not provided, same HTML for all)
 * @return array Results with 'results' array containing per-recipient results
 */
function api_send_batch(array $profile, array $campaign, array $recipients, array $htmlMap = []): array
{
    $results = [];
    
    if (empty($recipients)) {
        return [
            'ok' => false,
            'error' => 'No recipients provided',
            'results' => []
        ];
    }

    $apiUrl = trim($profile['api_url'] ?? '');
    $apiKey = trim($profile['api_key'] ?? '');
    $from   = trim($campaign['from_email'] ?? '');
    $provider = trim($profile['provider'] ?? '');

    if ($apiUrl === '' || $from === '') {
        return [
            'ok' => false,
            'error' => 'Missing API URL or from address',
            'results' => []
        ];
    }

    // Prepare common headers
    $headers = [
        'Content-Type: application/json',
    ];
    if ($apiKey !== '') {
        $headers[] = 'Authorization: Bearer ' . $apiKey;
    }
    if (!empty($profile['headers_json'])) {
        $extra = json_decode($profile['headers_json'], true);
        if (is_array($extra)) {
            foreach ($extra as $k => $v) {
                $headers[] = $k . ': ' . $v;
            }
        }
    }

    // Process each email using provider-specific payload format
    foreach ($recipients as $to) {
        $html = $htmlMap[$to] ?? $htmlMap['default'] ?? '';
        
        // Build provider-specific payload (SparkPost, SendGrid, Mailgun, MailJet, etc.)
        $payload = api_build_payload($provider, $from, $to, $campaign, $html);
        
        if ($payload === null) {
            $results[$to] = [
                'ok' => false,
                'type' => 'bounce',
                'code' => null,
                'msg' => 'API: unsupported provider format',
                'stage' => 'api',
                'log' => []
            ];
            continue;
        }

        $ch = curl_init($apiUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_TIMEOUT, 25);

        $resp = curl_exec($ch);
        if ($resp === false) {
            $err = curl_error($ch);
            curl_close($ch);
            $results[$to] = [
                'ok' => false,
                'type' => 'bounce',
                'code' => null,
                'msg' => 'API cURL error: ' . $err,
                'stage' => 'api',
                'log' => []
            ];
            continue;
        }

        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code >= 200 && $code < 300) {
            $results[$to] = [
                'ok' => true,
                'type' => 'delivered',
                'code' => (string)$code,
                'msg'  => substr($resp, 0, 1000),
                'stage'=> 'api',
                'log'  => []
            ];
        } else {
            $etype = ($code >= 500) ? 'bounce' : 'deferred';
            $results[$to] = [
                'ok' => false,
                'type' => $etype,
                'code' => (string)$code,
                'msg'  => substr($resp, 0, 1000),
                'stage'=> 'api',
                'log'  => []
            ];
        }
    }

    return [
        'ok' => true,
        'results' => $results
    ];
}

function get_job(PDO $pdo, int $id) {
    $stmt = $pdo->prepare("SELECT * FROM jobs WHERE id = ?");
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: false;
}

// Keep get_campaign as alias for backward compatibility during transition
function get_campaign(PDO $pdo, int $id) {
    return get_job($pdo, $id);
}

function get_job_stats(PDO $pdo, int $id) {
    $stats = [
        'extracted'   => 0,
        'queries_processed' => 0,
        'target' => 0,
        'progress_extracted' => 0,
        'progress_total' => 0,
    ];

    try {
        // Get job details from jobs table
        $stmt = $pdo->prepare("SELECT target_count, progress_extracted, progress_total FROM jobs WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        $job = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($job) {
            $stats['target'] = (int)($job['target_count'] ?? 0);
            $stats['progress_extracted'] = (int)($job['progress_extracted'] ?? 0);
            $stats['progress_total'] = (int)($job['progress_total'] ?? 0);
        }
        
        // Get actual count of extracted emails from extracted_emails table
        $stmt2 = $pdo->prepare("SELECT COUNT(*) FROM extracted_emails WHERE job_id = ?");
        $stmt2->execute([$id]);
        $stats['extracted'] = (int)$stmt2->fetchColumn();
        
        // Queries processed is same as progress_extracted for now
        $stats['queries_processed'] = $stats['progress_extracted'];
        
    } catch (Exception $e) {
        // If tables don't exist yet, return default stats
    }

    return $stats;
}

/**
 * Update campaign progress for real-time stats
 */
function update_campaign_progress(PDO $pdo, int $campaignId, int $sent, int $total, string $status = 'sending') {
    try {
        // When updating to 'completed' status or 'sending' status, use GREATEST to ensure we don't overwrite
        // a higher value that workers may have incremented to
        if ($status === 'completed' || $status === 'sending') {
            $stmt = $pdo->prepare("UPDATE campaigns SET progress_sent = GREATEST(progress_sent, ?), progress_total = ?, progress_status = ? WHERE id = ?");
            $stmt->execute([$sent, $total, $status, $campaignId]);
        } else {
            // For other statuses (queued, draft), just set the values directly
            $stmt = $pdo->prepare("UPDATE campaigns SET progress_sent = ?, progress_total = ?, progress_status = ? WHERE id = ?");
            $stmt->execute([$sent, $total, $status, $campaignId]);
        }
    } catch (Exception $e) {
        // Ignore errors gracefully (column might not exist in older schemas)
        error_log("Failed to update campaign progress: " . $e->getMessage());
    }
}

/**
 * Get campaign progress for real-time stats
 */
function get_campaign_progress(PDO $pdo, int $campaignId): array {
    try {
        $stmt = $pdo->prepare("SELECT progress_sent, progress_total, progress_status, status FROM campaigns WHERE id = ?");
        $stmt->execute([$campaignId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            return [
                'sent' => (int)$row['progress_sent'],
                'total' => (int)$row['progress_total'],
                'status' => $row['progress_status'] ?? 'draft',
                'campaign_status' => $row['status'] ?? 'draft',
                'percentage' => $row['progress_total'] > 0 ? round(($row['progress_sent'] / $row['progress_total']) * 100, 1) : 0
            ];
        }
    } catch (Exception $e) {
        // Graceful fallback for older schemas without progress columns
        error_log("Failed to get campaign progress: " . $e->getMessage());
    }
    return ['sent' => 0, 'total' => 0, 'status' => 'draft', 'campaign_status' => 'draft', 'percentage' => 0];
}

function get_profiles(PDO $pdo) {
    $stmt = $pdo->query("SELECT * FROM job_profiles ORDER BY id ASC");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function get_rotation_settings(PDO $pdo) {
    $stmt = $pdo->query("SELECT * FROM rotation_settings WHERE id = 1");
    $row  = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        $pdo->exec("INSERT INTO rotation_settings(id) VALUES(1)");
        $stmt = $pdo->query("SELECT * FROM rotation_settings WHERE id = 1");
        $row  = $stmt->fetch(PDO::FETCH_ASSOC);
    }
    return $row;
}

function update_rotation_settings(PDO $pdo, array $data) {
    // Only update columns that exist in the schema (rotation_enabled, workers, emails_per_worker)
    $stmt = $pdo->prepare("
        UPDATE rotation_settings
        SET rotation_enabled = :rotation_enabled,
            workers = :workers,
            emails_per_worker = :emails_per_worker
        WHERE id = 1
    ");
    $stmt->execute([
        ':rotation_enabled'      => $data['rotation_enabled'],
        ':workers'               => $data['workers'] ?? 4,
        ':emails_per_worker'     => $data['emails_per_worker'] ?? $data['messages_per_worker'] ?? 100,
    ]);
}

function get_contact_lists(PDO $pdo) {
    $sql = "
        SELECT l.*, COUNT(c.id) AS contact_count
        FROM contact_lists l
        LEFT JOIN contacts c ON c.list_id = l.id
        GROUP BY l.id
        ORDER BY l.created_at DESC
    ";
    $stmt = $pdo->query($sql);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function get_contact_list(PDO $pdo, int $id) {
    $stmt = $pdo->prepare("SELECT * FROM contact_lists WHERE id = ?");
    $stmt->execute([$id]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

function get_contacts_for_list(PDO $pdo, int $listId) {
    $stmt = $pdo->prepare("SELECT * FROM contacts WHERE list_id = ? ORDER BY created_at DESC");
    $stmt->execute([$listId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function pick_next_profile(PDO $pdo) {
    $settings = get_rotation_settings($pdo);
    if ((int)$settings['rotation_enabled'] !== 1) {
        return null;
    }

    $profiles = get_profiles($pdo);
    $active = array_values(array_filter($profiles, function ($p) {
        return (int)$p['active'] === 1;
    }));

    if (empty($active)) return null;

    if ($settings['mode'] === 'random') {
        return $active[array_rand($active)];
    }

    $lastId = (int)$settings['last_profile_id'];
    $next = null;
    if ($lastId === 0) {
        $next = $active[0];
    } else {
        $foundIndex = null;
        foreach ($active as $i => $p) {
            if ((int)$p['id'] === $lastId) {
                $foundIndex = $i;
                break;
            }
        }
        if ($foundIndex === null || $foundIndex === (count($active)-1)) {
            $next = $active[0];
        } else {
            $next = $active[$foundIndex+1];
        }
    }

    if ($next) {
        $stmt = $pdo->prepare("UPDATE rotation_settings SET last_profile_id = ? WHERE id = 1");
        $stmt->execute([$next['id']]);
    }

    return $next;
}

function find_profile_for_campaign(PDO $pdo, array $campaign) {
    $from = trim($campaign['from_email'] ?? '');
    $profiles = get_profiles($pdo);

    if ($from !== '') {
        foreach ($profiles as $p) {
            if ((int)$p['active'] === 1 && strtolower($p['from_email']) === strtolower($from)) {
                return $p;
            }
        }
    }
    foreach ($profiles as $p) {
        if ((int)$p['active'] === 1) {
            return $p;
        }
    }
    return null;
}

/**
 * Check if an 'open' event exists for a campaign/recipient (safe PHP-based check)
 */
function has_open_event_for_rcpt(PDO $pdo, int $campaignId, string $rcpt): bool {
    if ($rcpt === '') return false;
    try {
        $stmt = $pdo->prepare("SELECT details FROM events WHERE campaign_id = ? AND event_type = 'open' LIMIT 2000");
        $stmt->execute([$campaignId]);
        foreach ($stmt as $row) {
            $d = json_decode($row['details'], true);
            if (is_array($d) && isset($d['rcpt']) && strtolower($d['rcpt']) === strtolower($rcpt)) {
                return true;
            }
        }
    } catch (Exception $e) {}
    return false;
}

/**
 * Buffered event logger for improved performance during high-volume sends
 * Batches event inserts to reduce database round-trips
 */
class BufferedEventLogger {
    private $pdo;
    private $buffer = [];
    private $bufferSize = 50; // Insert every 50 events
    private $campaignId;
    
    public function __construct(PDO $pdo, int $campaignId, int $bufferSize = 50) {
        $this->pdo = $pdo;
        $this->campaignId = $campaignId;
        $this->bufferSize = max(1, $bufferSize);
    }
    
    /**
     * Add an event to the buffer
     */
    public function log(string $eventType, array $details) {
        $this->buffer[] = [
            'campaign_id' => $this->campaignId,
            'event_type' => $eventType,
            'details' => json_encode($details)
        ];
        
        // Flush if buffer is full
        if (count($this->buffer) >= $this->bufferSize) {
            $this->flush();
        }
    }
    
    /**
     * Flush all buffered events to database
     */
    public function flush() {
        if (empty($this->buffer)) {
            return;
        }
        
        try {
            // Build batch insert
            $values = [];
            $params = [];
            foreach ($this->buffer as $event) {
                $values[] = "(?, ?, ?)";
                $params[] = $event['campaign_id'];
                $params[] = $event['event_type'];
                $params[] = $event['details'];
            }
            
            $sql = "INSERT INTO events (campaign_id, event_type, details) VALUES " . implode(", ", $values);
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            
            // Clear buffer
            $this->buffer = [];
        } catch (Exception $e) {
            error_log("BufferedEventLogger flush error: " . $e->getMessage());
            // Don't throw - we don't want to stop sending if logging fails
        }
    }
    
    /**
     * Destructor ensures buffer is flushed
     */
    public function __destruct() {
        $this->flush();
    }
}

/**
 * Main send function — unchanged semantics but tolerant to forced profile and structured results from senders.
 *
 * Added $profileOverrides parameter (associative array) that may contain per-profile runtime overrides:
 * e.g. ['send_rate' => <messages_per_second>]
 */
function send_campaign_real(PDO $pdo, array $campaign, string $recipientsText, bool $isTest = false, ?int $forceProfileId = null, array $profileOverrides = [])
{
    $recipients = array_filter(array_map('trim', preg_split("/\r\n|\n|\r|,/", $recipientsText)));
    if (empty($recipients)) {
        return;
    }

    $rotSettings      = get_rotation_settings($pdo);
    $rotationEnabled  = (int)$rotSettings['rotation_enabled'] === 1;

    $total = count($recipients);
    if (!$isTest) {
        try {
            $stmt = $pdo->prepare("UPDATE campaigns SET status='sending', total_recipients=? WHERE id=?");
            $stmt->execute([$total, $campaign['id']]);
            // Initialize progress tracking
            update_campaign_progress($pdo, $campaign['id'], 0, $total, 'sending');
        } catch (Exception $e) {}
    }

    $ins = $pdo->prepare("INSERT INTO events (campaign_id, event_type, details) VALUES (?,?,?)");

    $ok = 0;
    $failed = 0;

    $attempted = [];

    foreach ($recipients as $email) {
        $emailLower = strtolower(trim($email));
        if ($emailLower === '') continue;

        if (in_array($emailLower, $attempted, true)) {
            continue;
        }
        $attempted[] = $emailLower;

        try {
            if (is_unsubscribed($pdo, $emailLower)) {
                $ins->execute([
                    $campaign['id'],
                    'skipped_unsubscribe',
                    json_encode([
                        'rcpt' => $emailLower,
                        'reason' => 'recipient in unsubscribes table',
                        'test' => $isTest ? 1 : 0,
                    ])
                ]);
                $failed++;
                // increment sends_used? No - we didn't attempt to send.
                continue;
            }

            if ($forceProfileId !== null) {
                $stmtpf = $pdo->prepare("SELECT * FROM sending_profiles WHERE id = ? LIMIT 1");
                $stmtpf->execute([$forceProfileId]);
                $profile = $stmtpf->fetch(PDO::FETCH_ASSOC);
                if (!$profile) {
                    throw new Exception("Forced profile not found: {$forceProfileId}");
                }
                // Apply runtime overrides if provided (e.g., send_rate)
                if (!empty($profileOverrides) && isset($profileOverrides['send_rate'])) {
                    $profile['send_rate'] = (int)$profileOverrides['send_rate'];
                }
            } else {
                if ($rotationEnabled) {
                    $profile = pick_next_profile($pdo);
                } else {
                    $profile = find_profile_for_campaign($pdo, $campaign);
                }
            }

            if (!$profile) {
                throw new Exception("No active sending profile configured.");
            }

            // Refresh sends_used & max_sends from DB to ensure we don't exceed limit (protect concurrent runs)
            if (!empty($profile['id'])) {
                try {
                    $stmtSU = $pdo->prepare("SELECT COALESCE(sends_used,0) as su FROM sending_profiles WHERE id = ? LIMIT 1");
                    $stmtSU->execute([$profile['id']]);
                    $profile['sends_used'] = (int)$stmtSU->fetchColumn();
                } catch (Exception $e) {
                    $profile['sends_used'] = (int)($profile['sends_used'] ?? 0);
                }
            } else {
                $profile['sends_used'] = 0;
            }

            $maxSends = max(0, (int)($profile['max_sends'] ?? 0));
            if ($maxSends > 0 && $profile['sends_used'] >= $maxSends) {
                // profile exhausted - record skipped event and continue
                $ins->execute([
                    $campaign['id'],
                    'skipped_max_sends',
                    json_encode([
                        'rcpt' => $emailLower,
                        'profile_id' => $profile['id'] ?? null,
                        'reason' => 'profile reached max_sends',
                        'test' => $isTest ? 1 : 0,
                    ])
                ]);
                $failed++;
                continue;
            }

            // Resolve FROM address:
            // - If a profile is forced (worker per-profile) --> always use that profile's from_email.
            // - Else if rotation is enabled --> use the profile's from_email.
            // - Else (rotation disabled) --> prefer campaign's from_email, fallback to profile's from_email.
            $fromToUse = '';
            if ($forceProfileId !== null) {
                // Forced per-profile send: use profile's from
                $fromToUse = trim($profile['from_email'] ?? '');
            } elseif ($rotationEnabled) {
                // Global rotation enabled: each profile should send with its own From
                $fromToUse = trim($profile['from_email'] ?? '');
            } else {
                // Rotation disabled: keep the campaign-level From if provided, else fallback to profile
                $fromToUse = trim($campaign['from_email'] ?? '');
                if ($fromToUse === '') {
                    $fromToUse = trim($profile['from_email'] ?? '');
                }
            }

            if ($fromToUse === '') {
                throw new Exception("No FROM address resolved for this send.");
            }

            $campaignSend = $campaign;
            $campaignSend['from_email'] = $fromToUse;

            $campaignSend['sender_name'] = trim($profile['sender_name'] ?? '');
            if ($campaignSend['sender_name'] === '') {
                $campaignSend['sender_name'] = trim($campaign['sender_name'] ?? '');
            }

            $htmlTracked = build_tracked_html($campaignSend, $emailLower);

            // Respect overrides for send_rate (profileOverrides wins, then profile setting)
            $effectiveSendRate = 0;
            if (!empty($profileOverrides) && isset($profileOverrides['send_rate'])) {
                $effectiveSendRate = (int)$profileOverrides['send_rate'];
            } elseif (!empty($profile['send_rate'])) {
                $effectiveSendRate = (int)$profile['send_rate'];
            } else {
                $effectiveSendRate = 0;
            }

            if (isset($profile['type']) && $profile['type'] === 'api') {
                $res = api_send_mail($profile, $campaignSend, $emailLower, $htmlTracked);
            } else {
                $res = smtp_send_mail($profile, $campaignSend, $emailLower, $htmlTracked);
            }

            // After attempting send, increment sends_used (persist)
            if (!empty($profile['id'])) {
                try {
                    $stmtInc = $pdo->prepare("UPDATE sending_profiles SET sends_used = COALESCE(sends_used,0) + 1 WHERE id = ?");
                    $stmtInc->execute([$profile['id']]);
                } catch (Exception $ex) {
                    // ignore
                }
            }

            if (!is_array($res)) {
                $ins->execute([
                    $campaign['id'],
                    'bounce',
                    json_encode([
                        'rcpt'  => $emailLower,
                        'error' => 'Invalid send function response',
                        'profile_id' => isset($profile['id']) ? $profile['id'] : null,
                        'via'   => isset($profile['type']) ? $profile['type'] : null,
                        'test'  => $isTest ? 1 : 0,
                        'mode'  => 'sync',
                    ])
                ]);
                add_to_unsubscribes($pdo, $emailLower);
                try {
                    if (!empty($profile) && isset($profile['id']) && ($profile['type'] ?? '') === 'smtp') {
                        $stmt = $pdo->prepare("UPDATE sending_profiles SET active = 0 WHERE id = ?");
                        $stmt->execute([$profile['id']]);
                        $ins->execute([
                            $campaign['id'],
                            'profile_disabled',
                            json_encode([
                                'profile_id' => $profile['id'],
                                'reason' => 'invalid send function response',
                                'test' => $isTest ? 1 : 0,
                            ])
                        ]);
                    }
                } catch (Exception $ex) {}
                $failed++;
                continue;
            }

            if (!empty($res['ok']) && ($res['type'] ?? '') === 'delivered') {
                $ins->execute([
                    $campaign['id'],
                    'delivered',
                    json_encode([
                        'rcpt'       => $emailLower,
                        'profile_id' => $profile['id'] ?? null,
                        'via'        => $profile['type'] ?? null,
                        'smtp_code'  => $res['code'] ?? null,
                        'smtp_msg'   => $res['msg'] ?? '',
                        'stage'      => $res['stage'] ?? 'done',
                        'test'       => $isTest ? 1 : 0,
                        'mode'       => 'sync',
                    ])
                ]);
                $ok++;
            } else {
                $etype = ($res['type'] === 'deferred') ? 'deferred' : 'bounce';

                $ins->execute([
                    $campaign['id'],
                    $etype,
                    json_encode([
                        'rcpt'       => $emailLower,
                        'profile_id' => $profile['id'] ?? null,
                        'via'        => $profile['type'] ?? null,
                        'smtp_code'  => $res['code'] ?? null,
                        'smtp_msg'   => $res['msg'] ?? '',
                        'stage'      => $res['stage'] ?? 'unknown',
                        'test'       => $isTest ? 1 : 0,
                        'mode'       => 'sync',
                        'log'        => $res['log'] ?? [],
                    ])
                ]);

                if ($etype === 'bounce') {
                    add_to_unsubscribes($pdo, $emailLower);
                }

                try {
                    if (!empty($profile) && isset($profile['id']) && ($profile['type'] ?? '') === 'smtp' && $etype === 'bounce') {
                        $stmt = $pdo->prepare("UPDATE sending_profiles SET active = 0 WHERE id = ?");
                        $stmt->execute([$profile['id']]);

                        $ins->execute([
                            $campaign['id'],
                            'profile_disabled',
                            json_encode([
                                'profile_id' => $profile['id'],
                                'reason' => 'bounce/connection failure during send',
                                'test' => $isTest ? 1 : 0,
                            ])
                        ]);
                    }
                } catch (Exception $ex) { /* ignore */ }

                $failed++;
            }
        } catch (Exception $e) {
            $ins->execute([
                $campaign['id'],
                'bounce',
                json_encode([
                    'rcpt'        => $emailLower,
                    'error'       => $e->getMessage(),
                    'profile_id'  => isset($profile['id']) ? $profile['id'] : null,
                    'via'         => isset($profile['type']) ? $profile['type'] : null,
                    'test'        => $isTest ? 1 : 0,
                    'mode'        => 'exception',
                ])
            ]);
            add_to_unsubscribes($pdo, $emailLower);

            try {
                if (!empty($profile) && isset($profile['id']) && ($profile['type'] ?? '') === 'smtp') {
                    $stmt = $pdo->prepare("UPDATE sending_profiles SET active = 0 WHERE id = ?");
                    $stmt->execute([$profile['id']]);
                }
            } catch (Exception $ex) {}

            // increment sends_used even on exception to count attempt
            if (!empty($profile['id'])) {
                try {
                    $stmtInc = $pdo->prepare("UPDATE sending_profiles SET sends_used = COALESCE(sends_used,0) + 1 WHERE id = ?");
                    $stmtInc->execute([$profile['id']]);
                } catch (Exception $_) {}
            }

            $failed++;
        }

        // Throttle according to effective send rate (overrides or profile)
        $sendRate = 0;
        if (!empty($profileOverrides) && isset($profileOverrides['send_rate'])) {
            $sendRate = (int)$profileOverrides['send_rate'];
        } elseif (!empty($profile) && isset($profile['send_rate'])) {
            $sendRate = (int)$profile['send_rate'];
        } else {
            $sendRate = 0;
        }

        if ($sendRate > 0) {
            $micro = (int)(1000000 / max(1, $sendRate));
            if ($micro > 0) {
                usleep($micro);
            }
        }
        
        // Update progress periodically (every 10 emails)
        if (!$isTest && ($ok + $failed) % PROGRESS_UPDATE_FREQUENCY === 0) {
            update_campaign_progress($pdo, $campaign['id'], $ok + $failed, $total, 'sending');
        }
    }

    // Final progress and status update - CRITICAL: This must always execute
    if (!$isTest) {
        // Mark progress as completed
        update_campaign_progress($pdo, $campaign['id'], $ok + $failed, $total, 'completed');
        
        // Update main campaign status to 'sent'
        try {
            $stmt = $pdo->prepare("UPDATE campaigns SET status='sent', sent_at = COALESCE(sent_at, NOW()) WHERE id=?");
            $result = $stmt->execute([$campaign['id']]);
            if (!$result) {
                error_log("Failed to update campaign status to sent for campaign ID: {$campaign['id']}");
                // Fallback: retry status update with fresh prepared statement
                $fallbackStmt = $pdo->prepare("UPDATE campaigns SET status='sent', sent_at = COALESCE(sent_at, NOW()) WHERE id=?");
                $fallbackStmt->execute([$campaign['id']]);
            } else {
                error_log("Successfully updated campaign {$campaign['id']} status to sent (simple mode)");
            }
        } catch (Exception $e) {
            error_log("Exception updating campaign status to sent (simple): " . $e->getMessage());
            // Final fallback: attempt one last status update
            try {
                $fallbackStmt = $pdo->prepare("UPDATE campaigns SET status='sent', sent_at = COALESCE(sent_at, NOW()) WHERE id=?");
                $fallbackStmt->execute([$campaign['id']]);
                error_log("Fallback status update successful for campaign {$campaign['id']}");
            } catch (Exception $e2) {
                error_log("Fallback status update also failed: " . $e2->getMessage());
            }
        }
    }
}

///////////////////////
//  CONCURRENT EMAIL SENDING ARCHITECTURE
///////////////////////
/**
 * CONCURRENT EMAIL SENDING SYSTEM
 * ================================
 * 
 * This system implements high-performance concurrent email sending using PHP's pcntl extension
 * for process forking. When pcntl is not available, it falls back to sequential processing.
 * 
 * ARCHITECTURE OVERVIEW:
 * 
 * 1. MULTI-LEVEL PARALLELIZATION
 *    - Level 1: Profile-level parallelization (rotation mode)
 *      When rotation is enabled with multiple profiles, each profile gets a separate process
 *    - Level 2: Connection-level parallelization (within each profile)
 *      Each profile spawns N workers based on connection_numbers setting
 *    - Level 3: Batch-level persistent connections (within each worker)
 *      Each worker sends batch_size emails before reconnecting to SMTP
 * 
 * 2. PROCESS HIERARCHY
 *    Main Process
 *    ├── Profile Worker 1 (if rotation enabled)
 *    │   ├── Connection Worker 1
 *    │   ├── Connection Worker 2
 *    │   └── Connection Worker N
 *    ├── Profile Worker 2
 *    │   └── ...
 *    └── Profile Worker M
 * 
 * 3. KEY FUNCTIONS
 *    - send_campaign_with_rotation(): Entry point for multi-profile campaigns
 *    - send_campaign_with_connections(): Entry point for single-profile campaigns
 *    - send_campaign_with_connections_for_profile_concurrent(): Spawns connection workers
 *    - process_worker_batch(): Worker function that runs in forked process
 * 
 * 4. CONFIGURATION
 *    - connection_numbers: Number of concurrent SMTP connections (1-40, default 5)
 *    - batch_size: Emails per connection before reconnecting (1-500, default 50)
 *    - rotation_enabled: Use multiple profiles concurrently
 * 
 * 5. THREAD SAFETY
 *    - Each worker creates its own database connection
 *    - Workers log events independently to avoid contention
 *    - Parent processes count results via database queries
 *    - Worker PIDs tracked in event details for debugging
 * 
 * 6. PERFORMANCE CHARACTERISTICS
 *    Example: 10,000 emails, 2 profiles, 10 workers per profile, batch size 50
 *    - 2 profile processes (parallel)
 *    - Each profile spawns 10 workers (parallel within profile)
 *    - Total: 20 concurrent workers in this example (2 profiles × 10 workers)
 *    - Each worker processes emails in batches
 *    - Worker count is unlimited - can be scaled based on system resources
 *    - Theoretical: ~Nx speedup vs sequential (where N = total workers)
 *    - Actual speedup limited by: system resources (CPU, memory), SMTP server limits,
 *      network capacity, and PHP process management overhead
 * 
 * 7. FALLBACK BEHAVIOR
 *    - If pcntl_fork unavailable: Sequential processing with same batch optimization
 *    - If fork fails: Logs error and continues with remaining workers
 *    - Test mode: Always sequential for predictability
 */

/**
 * Worker function to process a batch of emails in a separate process
 * This runs in a forked child process and exits when done
 * 
 * @param array $dbConfig Database configuration
 * @param array $profile SMTP profile
 * @param array $campaign Campaign data
 * @param array $recipients Recipients for this worker
 * @param int $batchSize Batch size for reconnecting
 * @param bool $isTest Whether this is a test send
 */
function process_worker_batch(array $dbConfig, array $profile, array $campaign, array $recipients, bool $isTest = false) {
    // Create new PDO connection for this worker process
    try {
        $pdo = new PDO(
            "mysql:host={$dbConfig['host']};dbname={$dbConfig['name']};charset=utf8mb4",
            $dbConfig['user'],
            $dbConfig['pass'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
    } catch (Exception $e) {
        error_log("Worker PDO error: " . $e->getMessage());
        exit(1);
    }
    
    $campaignId = (int)$campaign['id'];
    $ins = $pdo->prepare("INSERT INTO events (campaign_id, event_type, details) VALUES (?,?,?)");
    
    $sent = 0;
    $failed = 0;
    $processedCount = 0;
    $lastProgressUpdate = 0; // Track when we last updated progress
    
    // Prepare campaign for sending
    $campaignSend = $campaign;
    $campaignSend['from_email'] = trim($profile['from_email'] ?? $campaign['from_email']);
    $campaignSend['sender_name'] = trim($profile['sender_name'] ?? $campaign['sender_name']);
    
    // Process all recipients assigned to this worker
    // Build HTML map for each recipient
    $htmlMap = [];
    foreach ($recipients as $emailLower) {
        $htmlMap[$emailLower] = build_tracked_html($campaignSend, $emailLower);
    }
    
    try {
        if (isset($profile['type']) && $profile['type'] === 'api') {
            // API sending - send individually
            foreach ($recipients as $emailLower) {
                $res = api_send_mail($profile, $campaignSend, $emailLower, $htmlMap[$emailLower]);
                
                if (!empty($res['ok']) && ($res['type'] ?? '') === 'delivered') {
                    $ins->execute([
                        $campaignId,
                        'delivered',
                        json_encode([
                            'rcpt' => $emailLower,
                            'profile_id' => $profile['id'] ?? null,
                            'via' => 'worker_' . ($profile['type'] ?? 'api'),
                            'smtp_code' => $res['code'] ?? null,
                            'test' => $isTest ? 1 : 0,
                            'worker_pid' => getmypid(),
                        ])
                    ]);
                    $sent++;
                } else {
                    $etype = ($res['type'] === 'deferred') ? 'deferred' : 'bounce';
                    $ins->execute([
                        $campaignId,
                        $etype,
                        json_encode([
                            'rcpt' => $emailLower,
                            'profile_id' => $profile['id'] ?? null,
                            'via' => 'worker_' . ($profile['type'] ?? 'api'),
                            'smtp_code' => $res['code'] ?? null,
                            'smtp_msg' => $res['msg'] ?? '',
                            'test' => $isTest ? 1 : 0,
                            'worker_pid' => getmypid(),
                        ])
                    ]);
                    if ($etype === 'bounce') {
                        add_to_unsubscribes($pdo, $emailLower);
                    }
                    $failed++;
                }
                
                // Update progress periodically
                $processedCount++;
                if ($processedCount - $lastProgressUpdate >= PROGRESS_UPDATE_FREQUENCY) {
                    try {
                        $pdo->exec("UPDATE campaigns SET progress_sent = progress_sent + " . ($processedCount - $lastProgressUpdate) . " WHERE id = " . $campaignId);
                        $lastProgressUpdate = $processedCount;
                    } catch (Exception $e) {}
                }
            }
        } else {
            // SMTP batch sending with persistent connection
            $batchResult = smtp_send_batch($profile, $campaignSend, $recipients, $htmlMap);
            
            if (isset($batchResult['results']) && is_array($batchResult['results'])) {
                foreach ($batchResult['results'] as $recipientEmail => $res) {
                    if (!empty($res['ok']) && ($res['type'] ?? '') === 'delivered') {
                        $ins->execute([
                            $campaignId,
                            'delivered',
                            json_encode([
                                'rcpt' => $recipientEmail,
                                'profile_id' => $profile['id'] ?? null,
                                'via' => 'worker_smtp',
                                'smtp_code' => $res['code'] ?? null,
                                'test' => $isTest ? 1 : 0,
                                'worker_pid' => getmypid(),
                            ])
                        ]);
                        $sent++;
                    } else {
                        $etype = ($res['type'] === 'deferred') ? 'deferred' : 'bounce';
                        $ins->execute([
                            $campaignId,
                            $etype,
                            json_encode([
                                'rcpt' => $recipientEmail,
                                'profile_id' => $profile['id'] ?? null,
                                'via' => 'worker_smtp',
                                'smtp_code' => $res['code'] ?? null,
                                'smtp_msg' => $res['msg'] ?? '',
                                'test' => $isTest ? 1 : 0,
                                'worker_pid' => getmypid(),
                            ])
                        ]);
                        if ($etype === 'bounce') {
                            add_to_unsubscribes($pdo, $recipientEmail);
                        }
                        $failed++;
                    }
                    
                    // Update progress periodically
                    $processedCount++;
                    if ($processedCount - $lastProgressUpdate >= PROGRESS_UPDATE_FREQUENCY) {
                        try {
                            $pdo->exec("UPDATE campaigns SET progress_sent = progress_sent + " . ($processedCount - $lastProgressUpdate) . " WHERE id = " . $campaignId);
                            $lastProgressUpdate = $processedCount;
                        } catch (Exception $e) {}
                    }
                }
            } else {
                // Batch failed entirely
                foreach ($recipients as $emailLower) {
                    $ins->execute([
                        $campaignId,
                        'bounce',
                        json_encode([
                            'rcpt' => $emailLower,
                            'error' => $batchResult['error'] ?? 'Worker connection failed',
                            'profile_id' => $profile['id'] ?? null,
                            'via' => 'worker_smtp',
                            'test' => $isTest ? 1 : 0,
                            'worker_pid' => getmypid(),
                        ])
                    ]);
                    add_to_unsubscribes($pdo, $emailLower);
                    $failed++;
                }
            }
        }
    } catch (Exception $e) {
        foreach ($recipients as $emailLower) {
            $ins->execute([
                $campaignId,
                'bounce',
                json_encode([
                    'rcpt' => $emailLower,
                    'error' => 'Worker exception: ' . $e->getMessage(),
                    'profile_id' => $profile['id'] ?? null,
                    'test' => $isTest ? 1 : 0,
                    'worker_pid' => getmypid(),
                ])
            ]);
            add_to_unsubscribes($pdo, $emailLower);
            $failed++;
        }
    }
    
    // Final progress update for any remaining emails not yet reported
    if (!$isTest && $processedCount > $lastProgressUpdate) {
        $remaining = $processedCount - $lastProgressUpdate;
        try {
            $stmt = $pdo->prepare("UPDATE campaigns SET progress_sent = progress_sent + ? WHERE id = ?");
            $stmt->execute([$remaining, $campaignId]);
        } catch (Exception $e) {
            error_log("Worker final progress update error: " . $e->getMessage());
        }
    }
    
    exit(0);
}

/**
 * Concurrent version of send_campaign_with_connections_for_profile
 * Uses process forking to send via multiple concurrent connections
 */
function send_campaign_with_connections_for_profile_concurrent(PDO $pdo, array $campaign, array $recipients, array $profile, bool $isTest = false) {
    global $DB_HOST, $DB_NAME, $DB_USER, $DB_PASS;
    
    if (empty($recipients)) {
        return ['sent' => 0, 'failed' => 0];
    }
    
    $campaignId = (int)$campaign['id'];
    
    // Get workers count from profile - NO MAXIMUM LIMIT - accepts ANY value
    $workers = max(MIN_WORKERS, (int)($profile['workers'] ?? DEFAULT_WORKERS));
    
    // OPTIONAL logging for monitoring (NOT a limit)
    if ($workers >= WORKERS_LOG_WARNING_THRESHOLD) {
        error_log("Info: Profile {$profile['id']} using $workers workers for campaign $campaignId (NOT an error)");
    }
    
    $messagesPerWorker = max(MIN_MESSAGES_PER_WORKER, (int)($profile['messages_per_worker'] ?? DEFAULT_MESSAGES_PER_WORKER));
    
    $ins = $pdo->prepare("INSERT INTO events (campaign_id, event_type, details) VALUES (?,?,?)");
    
    // Filter out unsubscribed recipients
    $validRecipients = [];
    $failed = 0;
    foreach ($recipients as $email) {
        $emailLower = strtolower(trim($email));
        if ($emailLower === '' || in_array($emailLower, $validRecipients, true)) {
            continue;
        }
        
        if (is_unsubscribed($pdo, $emailLower)) {
            $ins->execute([
                $campaignId,
                'skipped_unsubscribe',
                json_encode([
                    'rcpt' => $emailLower,
                    'reason' => 'unsubscribed',
                    'test' => $isTest ? 1 : 0,
                ])
            ]);
            $failed++;
            continue;
        }
        
        $validRecipients[] = $emailLower;
    }
    
    // Divide recipients across workers using round-robin with messagesPerWorker as batch size
    $totalValidRecipients = count($validRecipients);
    if ($totalValidRecipients === 0) {
        return ['sent' => 0, 'failed' => $failed];
    }
    
    // Spawn up to $workers parallel processes (but not more than total recipients)
    $actualWorkers = min($workers, $totalValidRecipients);
    
    // Distribute recipients using round-robin with messagesPerWorker as batch size
    // This matches the spawn_parallel_workers logic
    $workerRecipients = array_fill(0, $actualWorkers, []);
    $cycleDelayMs = (int)($profile['cycle_delay_ms'] ?? DEFAULT_CYCLE_DELAY_MS);
    
    $recipientIdx = 0;
    $roundCount = 0;
    while ($recipientIdx < $totalValidRecipients) {
        // Apply cycle delay between rounds (except for the first round)
        if ($roundCount > 0 && $cycleDelayMs > 0) {
            usleep($cycleDelayMs * 1000);
        }
        
        for ($workerIdx = 0; $workerIdx < $actualWorkers && $recipientIdx < $totalValidRecipients; $workerIdx++) {
            $chunkSize = min($messagesPerWorker, $totalValidRecipients - $recipientIdx);
            $chunk = array_slice($validRecipients, $recipientIdx, $chunkSize);
            $workerRecipients[$workerIdx] = array_merge($workerRecipients[$workerIdx], $chunk);
            $recipientIdx += $chunkSize;
        }
        
        $roundCount++;
    }
    
    // Convert to worker batches array (filter out empty)
    $workerBatches = [];
    foreach ($workerRecipients as $recips) {
        if (!empty($recips)) {
            $workerBatches[] = $recips;
        }
    }
    
    // Check if pcntl extension is available for true concurrency
    $canFork = function_exists('pcntl_fork') && function_exists('pcntl_waitpid');
    
    if (!$canFork) {
        // Fallback to sequential processing if pcntl not available
        return send_campaign_with_connections_for_profile($pdo, $campaign, $recipients, $profile, $isTest);
    }
    
    // Prepare database config for workers
    $dbConfig = [
        'host' => $DB_HOST,
        'name' => $DB_NAME,
        'user' => $DB_USER,
        'pass' => $DB_PASS,
    ];
    
    $workerPids = [];
    
    // Spawn worker processes for each worker batch
    foreach ($workerBatches as $workerIdx => $batchRecipients) {
        if (empty($batchRecipients)) continue;
        
        $pid = pcntl_fork();
        
        if ($pid === -1) {
            // Fork failed - log error and continue with next
            error_log("Failed to fork worker process for worker $workerIdx");
            continue;
        } elseif ($pid === 0) {
            // Child process - process this batch
            process_worker_batch($dbConfig, $profile, $campaign, $batchRecipients, $isTest);
            // process_worker_batch calls exit(), so we never reach here
        } else {
            // Parent process - store child PID
            $workerPids[] = $pid;
        }
    }
    
    // Parent process: wait for all workers to complete
    $sent = 0;
    foreach ($workerPids as $pid) {
        $status = 0;
        pcntl_waitpid($pid, $status);
        // Note: We can't easily get sent/failed counts from child processes
        // The database events table will have the accurate data
    }
    
    // Count results from database for accurate totals
    try {
        $stmt = $pdo->prepare("
            SELECT COUNT(*) 
            FROM events 
            WHERE campaign_id = ? 
            AND event_type = 'delivered' 
            AND JSON_EXTRACT(details, '$.worker_pid') IS NOT NULL
            AND created_at >= DATE_SUB(NOW(), INTERVAL 10 MINUTE)
        ");
        $stmt->execute([$campaignId]);
        $sent = (int)$stmt->fetchColumn();
    } catch (Exception $e) {
        $sent = 0;
    }
    
    return ['sent' => $sent, 'failed' => $failed];
}

/**
 * Multi-profile sending function for rotation mode
 * 
 * When rotation is enabled, this function distributes recipients evenly across ALL active profiles.
 * Each profile then uses its own connection_numbers to further parallelize sending.
 * 
 * Example: 1000 emails, 4 active profiles → 250 emails per profile
 * If profile 1 has 10 connections → 25 emails per connection within profile 1
 * 
 * Status flow: draft → queued (orange) → sending (yellow) → sent (green)
 */
function send_campaign_with_rotation(PDO $pdo, array $campaign, array $recipients, array $activeProfiles, bool $isTest = false) {
    try {
        if (empty($recipients) || empty($activeProfiles)) {
            error_log("send_campaign_with_rotation called with empty recipients or profiles");
            return ['sent' => 0, 'failed' => count($recipients)];
        }
        
        $total = count($recipients);
        $campaignId = (int)$campaign['id'];
        
        error_log("Starting rotation send for campaign {$campaignId} with {$total} recipients and " . count($activeProfiles) . " profiles");
    
    // PHASE 1: QUEUED - Set status to queued and prepare batches across profiles
    if (!$isTest) {
        update_campaign_progress($pdo, $campaignId, 0, $total, 'queued');
        try {
            $stmt = $pdo->prepare("UPDATE campaigns SET status='queued', total_recipients=? WHERE id=?");
            $stmt->execute([$total, $campaignId]);
        } catch (Exception $e) {}
    }
    
    // Divide recipients evenly across all active profiles
    $profileCount = count($activeProfiles);
    $recipientsPerProfile = ceil($total / $profileCount);
    
    $profileBatches = [];
    for ($i = 0; $i < $profileCount; $i++) {
        $start = $i * $recipientsPerProfile;
        if ($start >= $total) break;
        
        $profileRecipients = array_slice($recipients, $start, $recipientsPerProfile);
        if (!empty($profileRecipients)) {
            $profileBatches[] = [
                'profile' => $activeProfiles[$i],
                'recipients' => $profileRecipients
            ];
        }
    }
    
    // PHASE 2: SENDING - Update status to sending
    if (!$isTest) {
        update_campaign_progress($pdo, $campaignId, 0, $total, 'sending');
        try {
            $stmt = $pdo->prepare("UPDATE campaigns SET status='sending', total_recipients=? WHERE id=?");
            $stmt->execute([$total, $campaignId]);
        } catch (Exception $e) {}
    }
    
    // Check if we can fork processes for concurrent profile sending
    $canFork = function_exists('pcntl_fork') && function_exists('pcntl_waitpid');
    
    $totalSent = 0;
    $totalFailed = 0;
    
    if ($canFork && !$isTest) {
        // CONCURRENT MODE: Fork a process for each profile
        global $DB_HOST, $DB_NAME, $DB_USER, $DB_PASS;
        
        $profilePids = [];
        
        foreach ($profileBatches as $batch) {
            $profile = $batch['profile'];
            $profileRecipients = $batch['recipients'];
            
            $pid = pcntl_fork();
            
            if ($pid === -1) {
                // Fork failed - fall back to sequential for this profile
                error_log("Failed to fork process for profile {$profile['id']}");
                $result = send_campaign_with_connections_for_profile_concurrent(
                    $pdo, 
                    $campaign, 
                    $profileRecipients, 
                    $profile, 
                    $isTest
                );
                $totalSent += $result['sent'];
                $totalFailed += $result['failed'];
            } elseif ($pid === 0) {
                // Child process - handle this profile
                // Create new PDO connection for child
                try {
                    $childPdo = new PDO(
                        "mysql:host={$DB_HOST};dbname={$DB_NAME};charset=utf8mb4",
                        $DB_USER,
                        $DB_PASS,
                        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
                    );
                } catch (Exception $e) {
                    error_log("Child PDO error: " . $e->getMessage());
                    exit(1);
                }
                
                // Process this profile's recipients with concurrent connections
                send_campaign_with_connections_for_profile_concurrent(
                    $childPdo, 
                    $campaign, 
                    $profileRecipients, 
                    $profile, 
                    $isTest
                );
                
                exit(0);
            } else {
                // Parent process - store child PID
                $profilePids[] = $pid;
            }
        }
        
        // Wait for all profile workers to complete
        foreach ($profilePids as $pid) {
            $status = 0;
            pcntl_waitpid($pid, $status);
        }
        
        // Count results from database
        try {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM events WHERE campaign_id = ? AND event_type = 'delivered'");
            $stmt->execute([$campaignId]);
            $totalSent = (int)$stmt->fetchColumn();
            
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM events WHERE campaign_id = ? AND event_type IN ('bounce', 'deferred', 'skipped_unsubscribe')");
            $stmt->execute([$campaignId]);
            $totalFailed = (int)$stmt->fetchColumn();
            
            // Get the actual progress_sent value that workers have been updating
            $stmt = $pdo->prepare("SELECT progress_sent FROM campaigns WHERE id = ?");
            $stmt->execute([$campaignId]);
            $actualProgressSent = (int)$stmt->fetchColumn();
            
            // Use the actual progress value if it's higher (workers may have updated it)
            $totalProcessed = max($totalSent + $totalFailed, $actualProgressSent);
        } catch (Exception $e) {
            error_log("Error counting results: " . $e->getMessage());
            // Use whatever values we have, defaulting to 0 if undefined
            $totalProcessed = (isset($totalSent) ? $totalSent : 0) + (isset($totalFailed) ? $totalFailed : 0);
        }
    } else {
        // SEQUENTIAL MODE: Process each profile one by one (fallback or test mode)
        foreach ($profileBatches as $batch) {
            $profile = $batch['profile'];
            $profileRecipients = $batch['recipients'];
            
            // Use concurrent connections within each profile
            $result = send_campaign_with_connections_for_profile_concurrent(
                $pdo, 
                $campaign, 
                $profileRecipients, 
                $profile, 
                $isTest
            );
            
            $totalSent += $result['sent'];
            $totalFailed += $result['failed'];
        }
    }
    
    // Final status update - CRITICAL: This must always execute
    // Wrapped in try-finally to ensure execution even if errors occur
    try {
        if (!$isTest) {
            // For concurrent mode, use the actual total processed from workers
            $progressToUpdate = isset($totalProcessed) ? $totalProcessed : ($totalSent + $totalFailed);
            
            // Mark as completed in progress tracker
            update_campaign_progress($pdo, $campaignId, $progressToUpdate, $total, 'completed');
            
            // Update main campaign status to 'sent'
            try {
                $stmt = $pdo->prepare("UPDATE campaigns SET status='sent', sent_at = COALESCE(sent_at, NOW()) WHERE id=?");
                $result = $stmt->execute([$campaignId]);
                if (!$result) {
                    error_log("Failed to update campaign status to sent for campaign ID: {$campaignId}");
                    // Force update as fallback (using prepared statement)
                    $fallbackStmt = $pdo->prepare("UPDATE campaigns SET status='sent', sent_at = COALESCE(sent_at, NOW()) WHERE id=?");
                    $fallbackStmt->execute([$campaignId]);
                } else {
                    error_log("Successfully updated campaign {$campaignId} status to sent (rotation mode)");
                }
            } catch (Exception $e) {
                error_log("Exception updating campaign status to sent (rotation): " . $e->getMessage());
                // Force update as last resort (using prepared statement)
                try {
                    $fallbackStmt = $pdo->prepare("UPDATE campaigns SET status='sent', sent_at = COALESCE(sent_at, NOW()) WHERE id=?");
                    $fallbackStmt->execute([$campaignId]);
                    error_log("Fallback status update successful for campaign {$campaignId}");
                } catch (Exception $e2) {
                    error_log("Fallback status update also failed: " . $e2->getMessage());
                }
            }
        }
    } finally {
        // Ensure we always log completion regardless of any errors
        error_log("Campaign {$campaignId} rotation send completed: sent={$totalSent}, failed={$totalFailed}, total={$total}");
    }
    
    return ['sent' => $totalSent, 'failed' => $totalFailed];
    
    } catch (Throwable $e) {
        error_log("CRITICAL ERROR in send_campaign_with_rotation: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
        error_log("Stack trace: " . $e->getTraceAsString());
        return ['sent' => 0, 'failed' => isset($total) ? $total : 0];
    }
}

/**
 * Helper function for sending with a specific profile (used by multi-profile rotation)
 * Does NOT update campaign status - that's handled by the caller
 */
function send_campaign_with_connections_for_profile(PDO $pdo, array $campaign, array $recipients, array $profile, bool $isTest = false) {
    if (empty($recipients)) {
        return ['sent' => 0, 'failed' => 0];
    }
    
    $campaignId = (int)$campaign['id'];
    
    // Get workers count from profile - NO MAXIMUM LIMIT - accepts ANY value
    $workers = max(MIN_WORKERS, (int)($profile['workers'] ?? DEFAULT_WORKERS));
    
    // OPTIONAL logging for monitoring (NOT a limit)
    if ($workers >= WORKERS_LOG_WARNING_THRESHOLD) {
        error_log("Info: Profile {$profile['id']} using $workers workers for campaign $campaignId (non-concurrent mode)");
    }
    
    $ins = $pdo->prepare("INSERT INTO events (campaign_id, event_type, details) VALUES (?,?,?)");
    
    $sent = 0;
    $failed = 0;
    $attempted = [];
    
    // Filter out unsubscribed and prepare recipients
    $validRecipients = [];
    foreach ($recipients as $email) {
        $emailLower = strtolower(trim($email));
        if ($emailLower === '' || in_array($emailLower, $attempted, true)) {
            continue;
        }
        $attempted[] = $emailLower;
        
        // Check unsubscribed
        if (is_unsubscribed($pdo, $emailLower)) {
            $ins->execute([
                $campaignId,
                'skipped_unsubscribe',
                json_encode([
                    'rcpt' => $emailLower,
                    'reason' => 'unsubscribed',
                    'test' => $isTest ? 1 : 0,
                ])
            ]);
            $failed++;
            continue;
        }
        
        $validRecipients[] = $emailLower;
    }
    
    // Process all valid recipients with this profile
    // (No batching needed - send all emails directly)
    
    // Prepare campaign for sending
    $campaignSend = $campaign;
    $campaignSend['from_email'] = trim($profile['from_email'] ?? $campaign['from_email']);
    $campaignSend['sender_name'] = trim($profile['sender_name'] ?? $campaign['sender_name']);
    
    // Build HTML map for each recipient
    $htmlMap = [];
    foreach ($validRecipients as $emailLower) {
        $htmlMap[$emailLower] = build_tracked_html($campaignSend, $emailLower);
    }
    
    try {
        // Send all recipients
        if (isset($profile['type']) && $profile['type'] === 'api') {
            // API sending - send individually
            foreach ($validRecipients as $emailLower) {
                $res = api_send_mail($profile, $campaignSend, $emailLower, $htmlMap[$emailLower]);
                
                if (!empty($res['ok']) && ($res['type'] ?? '') === 'delivered') {
                    $ins->execute([
                        $campaignId,
                        'delivered',
                        json_encode([
                            'rcpt' => $emailLower,
                            'profile_id' => $profile['id'] ?? null,
                            'via' => $profile['type'] ?? null,
                            'smtp_code' => $res['code'] ?? null,
                            'test' => $isTest ? 1 : 0,
                        ])
                    ]);
                    $sent++;
                } else {
                    $etype = ($res['type'] === 'deferred') ? 'deferred' : 'bounce';
                    $ins->execute([
                        $campaignId,
                        $etype,
                        json_encode([
                            'rcpt' => $emailLower,
                            'profile_id' => $profile['id'] ?? null,
                            'via' => $profile['type'] ?? null,
                            'smtp_code' => $res['code'] ?? null,
                            'smtp_msg' => $res['msg'] ?? '',
                            'test' => $isTest ? 1 : 0,
                        ])
                    ]);
                    
                    if ($etype === 'bounce') {
                        add_to_unsubscribes($pdo, $emailLower);
                    }
                    $failed++;
                }
            }
        } else {
            // SMTP batch sending with persistent connection
            $batchResult = smtp_send_batch($profile, $campaignSend, $validRecipients, $htmlMap);
            
            if (isset($batchResult['results']) && is_array($batchResult['results'])) {
                foreach ($batchResult['results'] as $recipientEmail => $res) {
                    if (!empty($res['ok']) && ($res['type'] ?? '') === 'delivered') {
                        $ins->execute([
                            $campaignId,
                            'delivered',
                            json_encode([
                                'rcpt' => $recipientEmail,
                                'profile_id' => $profile['id'] ?? null,
                                'via' => 'smtp_batch',
                                'smtp_code' => $res['code'] ?? null,
                                'test' => $isTest ? 1 : 0,
                            ])
                        ]);
                        $sent++;
                    } else {
                        $etype = ($res['type'] === 'deferred') ? 'deferred' : 'bounce';
                        $ins->execute([
                            $campaignId,
                            $etype,
                            json_encode([
                                'rcpt' => $recipientEmail,
                                'profile_id' => $profile['id'] ?? null,
                                'via' => 'smtp_batch',
                                'smtp_code' => $res['code'] ?? null,
                                'smtp_msg' => $res['msg'] ?? '',
                                'test' => $isTest ? 1 : 0,
                            ])
                        ]);
                        
                        if ($etype === 'bounce') {
                            add_to_unsubscribes($pdo, $recipientEmail);
                        }
                        $failed++;
                    }
                }
            } else {
                // Batch failed entirely (connection error)
                foreach ($validRecipients as $emailLower) {
                    $ins->execute([
                        $campaignId,
                        'bounce',
                        json_encode([
                            'rcpt' => $emailLower,
                            'error' => $batchResult['error'] ?? 'Connection failed',
                            'profile_id' => $profile['id'] ?? null,
                            'via' => 'smtp_batch',
                            'test' => $isTest ? 1 : 0,
                        ])
                    ]);
                    add_to_unsubscribes($pdo, $emailLower);
                    $failed++;
                }
            }
        }
    } catch (Exception $e) {
        // Handle exception
        foreach ($validRecipients as $emailLower) {
            $ins->execute([
                $campaignId,
                'bounce',
                json_encode([
                    'rcpt' => $emailLower,
                    'error' => 'Exception: ' . $e->getMessage(),
                    'profile_id' => $profile['id'] ?? null,
                    'test' => $isTest ? 1 : 0,
                ])
            ]);
            add_to_unsubscribes($pdo, $emailLower);
            $failed++;
        }
    }
    
    return ['sent' => $sent, 'failed' => $failed];
}

/**
 * Optimized sending function using connection-based batching with persistent connections
 * 
 * Implements a two-phase process:
 * 1. QUEUED phase: Divides recipients evenly across connection_numbers (e.g., 40 connections = 40 batches)
 * 2. SENDING phase: Sends emails using persistent SMTP connections, reconnecting every batch_size emails
 * 
 * Status flow: draft → queued (orange) → sending (yellow) → sent (green)
 */
function send_campaign_with_connections(PDO $pdo, array $campaign, array $recipients, ?int $profileId = null, bool $isTest = false) {
    if (empty($recipients)) {
        return ['sent' => 0, 'failed' => 0];
    }
    
    $total = count($recipients);
    $campaignId = (int)$campaign['id'];
    
    // Get profile first to determine connection numbers
    $profile = null;
    if ($profileId) {
        $stmt = $pdo->prepare("SELECT * FROM sending_profiles WHERE id = ? LIMIT 1");
        $stmt->execute([$profileId]);
        $profile = $stmt->fetch(PDO::FETCH_ASSOC);
    } else {
        $profile = find_profile_for_campaign($pdo, $campaign);
    }
    
    if (!$profile) {
        return ['sent' => 0, 'failed' => $total, 'error' => 'No active profile'];
    }
    
    // PHASE 1: QUEUED - Set status to queued and prepare batches
    if (!$isTest) {
        update_campaign_progress($pdo, $campaignId, 0, $total, 'queued');
        try {
            $stmt = $pdo->prepare("UPDATE campaigns SET status='queued', total_recipients=? WHERE id=?");
            $stmt->execute([$total, $campaignId]);
        } catch (Exception $e) {}
    }
    
    // Get workers count from profile - NO MAXIMUM LIMIT - accepts ANY value
    $workers = max(MIN_WORKERS, (int)($profile['workers'] ?? DEFAULT_WORKERS));
    
    // OPTIONAL logging for monitoring (NOT a limit)
    if ($workers >= WORKERS_LOG_WARNING_THRESHOLD) {
        error_log("Info: Profile {$profile['id']} using $workers workers for campaign $campaignId (concurrent mode)");
    }
    
    $messagesPerWorker = max(MIN_MESSAGES_PER_WORKER, (int)($profile['messages_per_worker'] ?? DEFAULT_MESSAGES_PER_WORKER));
    
    $ins = $pdo->prepare("INSERT INTO events (campaign_id, event_type, details) VALUES (?,?,?)");
    
    $sent = 0;
    $failed = 0;
    $attempted = [];
    
    // Filter out unsubscribed and prepare recipients
    $validRecipients = [];
    foreach ($recipients as $email) {
        $emailLower = strtolower(trim($email));
        if ($emailLower === '' || in_array($emailLower, $attempted, true)) {
            continue;
        }
        $attempted[] = $emailLower;
        
        // Check unsubscribed
        if (is_unsubscribed($pdo, $emailLower)) {
            $ins->execute([
                $campaignId,
                'skipped_unsubscribe',
                json_encode([
                    'rcpt' => $emailLower,
                    'reason' => 'unsubscribed',
                    'test' => $isTest ? 1 : 0,
                ])
            ]);
            $failed++;
            continue;
        }
        
        $validRecipients[] = $emailLower;
    }
    
    // WORKER MECHANISM: Divide recipients across workers evenly
    $totalValidRecipients = count($validRecipients);
    
    // Spawn up to $workers parallel processes (but not more than total recipients)
    $actualWorkers = min($workers, $totalValidRecipients);
    
    // Distribute recipients using round-robin with messagesPerWorker as batch size
    // This matches the spawn_parallel_workers logic
    $workerRecipients = array_fill(0, $actualWorkers, []);
    $cycleDelayMs = (int)($profile['cycle_delay_ms'] ?? DEFAULT_CYCLE_DELAY_MS);
    
    $recipientIdx = 0;
    $roundCount = 0;
    while ($recipientIdx < $totalValidRecipients) {
        // Apply cycle delay between rounds (except for the first round)
        if ($roundCount > 0 && $cycleDelayMs > 0) {
            usleep($cycleDelayMs * 1000);
        }
        
        for ($workerIdx = 0; $workerIdx < $actualWorkers && $recipientIdx < $totalValidRecipients; $workerIdx++) {
            $chunkSize = min($messagesPerWorker, $totalValidRecipients - $recipientIdx);
            $chunk = array_slice($validRecipients, $recipientIdx, $chunkSize);
            $workerRecipients[$workerIdx] = array_merge($workerRecipients[$workerIdx], $chunk);
            $recipientIdx += $chunkSize;
        }
        
        $roundCount++;
    }
    
    // Convert to worker batches array (filter out empty)
    $workerBatches = [];
    foreach ($workerRecipients as $recips) {
        if (!empty($recips)) {
            $workerBatches[] = $recips;
        }
    }
    
    // PHASE 2: SENDING - Update status to sending when we start actual sending
    if (!$isTest) {
        update_campaign_progress($pdo, $campaignId, 0, $total, 'sending');
        try {
            $stmt = $pdo->prepare("UPDATE campaigns SET status='sending', total_recipients=? WHERE id=?");
            $stmt->execute([$total, $campaignId]);
        } catch (Exception $e) {}
    }
    
    // Prepare campaign for sending
    $campaignSend = $campaign;
    $campaignSend['from_email'] = trim($profile['from_email'] ?? $campaign['from_email']);
    $campaignSend['sender_name'] = trim($profile['sender_name'] ?? $campaign['sender_name']);
    
    // Check if we can use concurrent processing
    $canFork = function_exists('pcntl_fork') && function_exists('pcntl_waitpid');
    
    if ($canFork && !$isTest && count($workerBatches) > 1) {
        // CONCURRENT MODE: Fork a worker for each batch
        global $DB_HOST, $DB_NAME, $DB_USER, $DB_PASS;
        
        $dbConfig = [
            'host' => $DB_HOST,
            'name' => $DB_NAME,
            'user' => $DB_USER,
            'pass' => $DB_PASS,
        ];
        
        $workerPids = [];
        
        foreach ($workerBatches as $workerIdx => $workerRecipients) {
            $pid = pcntl_fork();
            
            if ($pid === -1) {
                // Fork failed - log and continue with sequential fallback
                error_log("Failed to fork worker #$workerIdx");
                continue;
            } elseif ($pid === 0) {
                // Child process - send this batch
                process_worker_batch($dbConfig, $profile, $campaignSend, $workerRecipients, $isTest);
                // process_worker_batch calls exit()
            } else {
                // Parent process
                $workerPids[] = $pid;
            }
        }
        
        // Wait for all workers
        foreach ($workerPids as $pid) {
            $status = 0;
            pcntl_waitpid($pid, $status);
        }
        
        // Count results from database (both sent and failed)
        try {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM events WHERE campaign_id = ? AND event_type = 'delivered'");
            $stmt->execute([$campaignId]);
            $sent = (int)$stmt->fetchColumn();
            
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM events WHERE campaign_id = ? AND event_type IN ('bounce', 'deferred', 'skipped_unsubscribe')");
            $stmt->execute([$campaignId]);
            $failed = (int)$stmt->fetchColumn();
            
            // Get the actual progress_sent value that workers have been updating
            $stmt = $pdo->prepare("SELECT progress_sent FROM campaigns WHERE id = ?");
            $stmt->execute([$campaignId]);
            $actualProgressSent = (int)$stmt->fetchColumn();
            
            // Use the actual progress value if it's higher (workers may have updated it)
            $totalProcessed = max($sent + $failed, $actualProgressSent);
        } catch (Exception $e) {
            $sent = 0;
            $failed = 0;
            $totalProcessed = 0;
        }
    } else {
        // SEQUENTIAL MODE: Process all recipients sequentially (fallback or test mode)
        // Build HTML map for all recipients
        $htmlMap = [];
        foreach ($validRecipients as $emailLower) {
            $htmlMap[$emailLower] = build_tracked_html($campaignSend, $emailLower);
        }
        
        try {
            // Send all recipients
            if (isset($profile['type']) && $profile['type'] === 'api') {
                // API sending - send individually
                foreach ($validRecipients as $emailLower) {
                    $res = api_send_mail($profile, $campaignSend, $emailLower, $htmlMap[$emailLower]);
                    
                    if (!empty($res['ok']) && ($res['type'] ?? '') === 'delivered') {
                        $ins->execute([
                            $campaignId,
                            'delivered',
                            json_encode([
                                'rcpt' => $emailLower,
                                'profile_id' => $profile['id'] ?? null,
                                'via' => $profile['type'] ?? null,
                                'smtp_code' => $res['code'] ?? null,
                                'test' => $isTest ? 1 : 0,
                            ])
                        ]);
                        $sent++;
                    } else {
                        $etype = ($res['type'] === 'deferred') ? 'deferred' : 'bounce';
                        $ins->execute([
                            $campaignId,
                            $etype,
                            json_encode([
                                'rcpt' => $emailLower,
                                'profile_id' => $profile['id'] ?? null,
                                'via' => $profile['type'] ?? null,
                                'smtp_code' => $res['code'] ?? null,
                                'smtp_msg' => $res['msg'] ?? '',
                                'test' => $isTest ? 1 : 0,
                            ])
                        ]);
                        
                        if ($etype === 'bounce') {
                            add_to_unsubscribes($pdo, $emailLower);
                        }
                        $failed++;
                    }
                }
            } else {
                // SMTP batch sending with persistent connection
                $batchResult = smtp_send_batch($profile, $campaignSend, $validRecipients, $htmlMap);
                
                if (isset($batchResult['results']) && is_array($batchResult['results'])) {
                    foreach ($batchResult['results'] as $recipientEmail => $res) {
                        if (!empty($res['ok']) && ($res['type'] ?? '') === 'delivered') {
                            $ins->execute([
                                $campaignId,
                                'delivered',
                                json_encode([
                                    'rcpt' => $recipientEmail,
                                    'profile_id' => $profile['id'] ?? null,
                                    'via' => 'smtp_batch',
                                    'smtp_code' => $res['code'] ?? null,
                                    'test' => $isTest ? 1 : 0,
                                ])
                            ]);
                            $sent++;
                        } else {
                            $etype = ($res['type'] === 'deferred') ? 'deferred' : 'bounce';
                            $ins->execute([
                                $campaignId,
                                $etype,
                                json_encode([
                                    'rcpt' => $recipientEmail,
                                    'profile_id' => $profile['id'] ?? null,
                                    'via' => 'smtp_batch',
                                    'smtp_code' => $res['code'] ?? null,
                                    'smtp_msg' => $res['msg'] ?? '',
                                    'test' => $isTest ? 1 : 0,
                                ])
                            ]);
                            
                            if ($etype === 'bounce') {
                                add_to_unsubscribes($pdo, $recipientEmail);
                            }
                            $failed++;
                        }
                    }
                } else {
                    // Batch failed entirely (connection error)
                    foreach ($validRecipients as $emailLower) {
                        $ins->execute([
                            $campaignId,
                            'bounce',
                            json_encode([
                                'rcpt' => $emailLower,
                                'error' => $batchResult['error'] ?? 'Connection failed',
                                'profile_id' => $profile['id'] ?? null,
                                'via' => 'smtp_batch',
                                'test' => $isTest ? 1 : 0,
                            ])
                        ]);
                        add_to_unsubscribes($pdo, $emailLower);
                        $failed++;
                    }
                }
            }
        } catch (Exception $e) {
            // Handle exception
            foreach ($validRecipients as $emailLower) {
                $ins->execute([
                    $campaignId,
                    'bounce',
                    json_encode([
                        'rcpt' => $emailLower,
                        'error' => 'Exception: ' . $e->getMessage(),
                        'profile_id' => $profile['id'] ?? null,
                        'test' => $isTest ? 1 : 0,
                    ])
                ]);
                add_to_unsubscribes($pdo, $emailLower);
                $failed++;
            }
        }
        
        // Update progress periodically
        if (!$isTest && ($sent + $failed) % PROGRESS_UPDATE_FREQUENCY === 0) {
            update_campaign_progress($pdo, $campaignId, $sent + $failed, $total, 'sending');
        }
    } // End of if-else concurrent/sequential
    
    // Final status update - CRITICAL: This must always execute
    // Wrapped in try-finally to ensure execution even if errors occur
    try {
        if (!$isTest) {
            // For concurrent mode, use the actual total processed from workers
            $progressToUpdate = isset($totalProcessed) ? $totalProcessed : ($sent + $failed);
            
            // Mark as completed in progress tracker
            update_campaign_progress($pdo, $campaignId, $progressToUpdate, $total, 'completed');
            
            // Update main campaign status to 'sent'
            try {
                $stmt = $pdo->prepare("UPDATE campaigns SET status='sent', sent_at = COALESCE(sent_at, NOW()) WHERE id=?");
                $result = $stmt->execute([$campaignId]);
                if (!$result) {
                    error_log("Failed to update campaign status to sent for campaign ID: {$campaignId}");
                    // Force update as fallback (using prepared statement)
                    $fallbackStmt = $pdo->prepare("UPDATE campaigns SET status='sent', sent_at = COALESCE(sent_at, NOW()) WHERE id=?");
                    $fallbackStmt->execute([$campaignId]);
                } else {
                    error_log("Successfully updated campaign {$campaignId} status to sent");
                }
            } catch (Exception $e) {
                error_log("Exception updating campaign status to sent: " . $e->getMessage());
                // Force update as last resort (using prepared statement)
                try {
                    $fallbackStmt = $pdo->prepare("UPDATE campaigns SET status='sent', sent_at = COALESCE(sent_at, NOW()) WHERE id=?");
                    $fallbackStmt->execute([$campaignId]);
                    error_log("Fallback status update successful for campaign {$campaignId}");
                } catch (Exception $e2) {
                    error_log("Fallback status update also failed: " . $e2->getMessage());
                }
            }
        }
    } finally {
        // Ensure we always log completion regardless of any errors
        error_log("Campaign {$campaignId} send process completed: sent={$sent}, failed={$failed}, total={$total}");
    }
    
    return ['sent' => $sent, 'failed' => $failed];
}

/**
 * IMAP bounce processing — unchanged
 */
function process_imap_bounces(PDO $pdo) {
    if (!function_exists('imap_open')) {
        return;
    }

    $profiles = get_profiles($pdo);
    $ins = $pdo->prepare("INSERT INTO events (campaign_id, event_type, details) VALUES (?,?,?)");

    foreach ($profiles as $p) {
        $server = trim($p['bounce_imap_server'] ?? '');
        $user   = trim($p['bounce_imap_user'] ?? '');
        $pass   = trim($p['bounce_imap_pass'] ?? '');

        if ($server === '' || $user === '' || $pass === '') continue;

        $mailbox = $server;
        if (stripos($mailbox, '{') !== 0) {
            $hostOnly = $mailbox;
            $mailbox = "{" . $hostOnly . ":993/imap/ssl}INBOX";
        }

        try {
            $mbox = @imap_open($mailbox, $user, $pass, 0, 1);
            if (!$mbox) {
                @imap_close($mbox);
                continue;
            }

            $msgs = @imap_search($mbox, 'UNSEEN');
            if ($msgs === false) {
                $msgs = @imap_search($mbox, 'ALL');
                if ($msgs === false) $msgs = [];
            }

            foreach ($msgs as $msgno) {
                $header = @imap_headerinfo($mbox, $msgno);
                $body = @imap_body($mbox, $msgno);

                $foundEmails = [];

                if ($body) {
                    if (preg_match_all('/Final-Recipient:\s*.*?;\s*([^\s;<>"]+)/i', $body, $m1)) {
                        foreach ($m1[1] as $e) $foundEmails[] = $e;
                    }
                    if (preg_match_all('/Original-Recipient:\s*.*?;\s*([^\s;<>"]+)/i', $body, $m2)) {
                        foreach ($m2[1] as $e) $foundEmails[] = $e;
                    }
                }

                $hdrText = imap_fetchheader($mbox, $msgno);
                if ($hdrText) {
                    if (preg_match_all('/(?:To|Delivered-To|X-Original-To):\s*(.*)/i', $hdrText, $mt)) {
                        foreach ($mt[1] as $chunk) {
                            if (preg_match_all('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', $chunk, $emails)) {
                                foreach ($emails[0] as $e) $foundEmails[] = $e;
                            }
                        }
                    }
                }

                if ($body) {
                    if (preg_match_all('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', $body, $eb)) {
                        foreach ($eb[0] as $e) $foundEmails[] = $e;
                    }
                }

                $foundEmails = array_unique(array_map('strtolower', array_filter(array_map('trim', $foundEmails))));
                if (!empty($foundEmails)) {
                    foreach ($foundEmails as $rcpt) {
                        try {
                            $details = [
                                'rcpt' => $rcpt,
                                'profile_id' => $p['id'],
                                'source' => 'imap_bounce',
                                'subject' => isset($header->subject) ? (string)$header->subject : '',
                            ];
                            $ins->execute([0, 'bounce', json_encode($details)]);
                        } catch (Exception $e) {}

                        try {
                            add_to_unsubscribes($pdo, $rcpt);
                        } catch (Exception $e) {}
                    }
                }

                @imap_setflag_full($mbox, $msgno, "\\Seen");
            }

            @imap_close($mbox);
        } catch (Exception $e) {}
    }
}

///////////////////////
//  API ENDPOINT FOR REAL-TIME CAMPAIGN PROGRESS
///////////////////////
if (isset($_GET['api']) && $_GET['api'] === 'progress' && isset($_GET['campaign_id'])) {
    header('Content-Type: application/json');
    $campaignId = (int)$_GET['campaign_id'];
    $progress = get_campaign_progress($pdo, $campaignId);
    $stats = get_campaign_stats($pdo, $campaignId);
    
    echo json_encode([
        'success' => true,
        'progress' => $progress,
        'stats' => [
            'delivered' => $stats['delivered'],
            'bounce' => $stats['bounce'],
            'open' => $stats['open'],
            'click' => $stats['click'],
            'unsubscribe' => $stats['unsubscribe']
        ]
    ]);
    exit;
}

///////////////////////
//  TRACKING ENDPOINTS (open, click, unsubscribe)
///////////////////////
if (isset($_GET['t']) && in_array($_GET['t'], ['open','click','unsubscribe'], true)) {
    $t   = $_GET['t'];
    $cid = isset($_GET['cid']) ? (int)$_GET['cid'] : 0;

    if ($cid > 0) {
        $rcpt = '';
        if (!empty($_GET['r'])) {
            $rcptDecoded = base64url_decode($_GET['r']);
            if (is_string($rcptDecoded)) {
                $rcpt = $rcptDecoded;
            }
        }

        $details = [
            'rcpt' => $rcpt,
            'ip'   => $_SERVER['REMOTE_ADDR'] ?? '',
            'ua'   => $_SERVER['HTTP_USER_AGENT'] ?? '',
        ];

        $url = '';
        if ($t === 'click') {
            $uParam = $_GET['u'] ?? '';
            if ($uParam !== '') {
                $decoded = base64url_decode($uParam);
                if (is_string($decoded)) {
                    $url = $decoded;
                }
            }
            $details['url'] = $url;
        }

        $eventType = $t === 'open' ? 'open' : ($t === 'click' ? 'click' : 'unsubscribe');

        try {
            $stmt = $pdo->prepare("INSERT INTO events (campaign_id, event_type, details) VALUES (?,?,?)");
            $stmt->execute([$cid, $eventType, json_encode($details)]);
        } catch (Exception $e) {}

        if ($t === 'unsubscribe' && $rcpt !== '') {
            try {
                add_to_unsubscribes($pdo, $rcpt);
            } catch (Exception $e) { /* ignore */ }
        }

        // Special enhancement: if this is a click and we don't have an 'open' recorded for this rcpt+campaign,
        // create an estimated open event. This helps when clients block images (Yahoo/AOL etc.).
        if ($t === 'click' && $rcpt !== '') {
            try {
                if (!has_open_event_for_rcpt($pdo, $cid, $rcpt)) {
                    $estimatedOpen = [
                        'rcpt' => $rcpt,
                        'ip'   => $_SERVER['REMOTE_ADDR'] ?? '',
                        'ua'   => $_SERVER['HTTP_USER_AGENT'] ?? '',
                        'estimated' => 1,
                        'source' => 'click_fallback',
                    ];
                    $stmt2 = $pdo->prepare("INSERT INTO events (campaign_id, event_type, details) VALUES (?,?,?)");
                    $stmt2->execute([$cid, 'open', json_encode($estimatedOpen)]);
                }
            } catch (Exception $e) {}
        }
    }

    if ($t === 'open') {
        header('Content-Type: image/gif');
        echo base64_decode('R0lGODlhAQABAPAAAP///wAAACH5BAAAAAAALAAAAAABAAEAAAICRAEAOw==');
        exit;
    }

    if ($t === 'click') {
        if (!empty($url) && preg_match('~^https?://~i', $url)) {
            // Link tracking enhancement: Replace {{email}} placeholder with actual recipient email
            // This allows tracking links to pass the recipient's email to destination URLs
            // Security: Validates email format and URL-encodes for safety
            if (!empty($rcpt) && strpos($url, '{{email}}') !== false) {
                // Validate email format to prevent malicious input
                if (filter_var($rcpt, FILTER_VALIDATE_EMAIL) !== false) {
                    // URL-encode the email to ensure it's safe for use in URL parameters
                    // This prevents injection attacks and ensures proper URL formatting
                    $url = str_replace('{{email}}', urlencode($rcpt), $url);
                }
                // If email is invalid, the placeholder remains in the URL (fault-tolerant)
            }
            header('Location: ' . $url, true, 302);
        } else {
            header('Content-Type: text/plain; charset=utf-8');
            echo "OK";
        }
        exit;
    }

    if ($t === 'unsubscribe') {
        header('Content-Type: text/html; charset=utf-8');
        echo "<!doctype html><html><head><meta charset='utf-8'><title>Unsubscribed</title></head><body style='font-family:system-ui, -apple-system, sans-serif;padding:24px;'>";
        echo "<h2>Unsubscribed</h2>";
        echo "<p>You have been unsubscribed. If this was a mistake, please contact the sender.</p>";
        echo "</body></html>";
        exit;
    }
}

if (isset($_GET['ajax']) && $_GET['ajax'] === 'campaign_stats' && isset($_GET['id'])) {
    $id = (int)$_GET['id'];
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(get_campaign_stats($pdo, $id));
    exit;
}

///////////////////////
//  ACTIONS
///////////////////////
// Check both POST and GET for action parameter (API endpoints use GET, forms use POST)
$action = $_POST['action'] ?? $_GET['action'] ?? null;
$page   = $_GET['page'] ?? 'list';

if ($action === 'check_connection_profile') {
    $pid = (int)($_POST['profile_id'] ?? 0);
    header('Content-Type: application/json; charset=utf-8');

    if ($pid <= 0) {
        echo json_encode(['ok'=>false,'msg'=>'Invalid profile id']);
        exit;
    }

    $stmt = $pdo->prepare("SELECT * FROM sending_profiles WHERE id = ? LIMIT 1");
    $stmt->execute([$pid]);
    $profile = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$profile) {
        echo json_encode(['ok'=>false,'msg'=>'Profile not found']);
        exit;
    }

    try {
        if (($profile['type'] ?? 'smtp') === 'api') {
            $res = api_check_connection($profile);
        } else {
            $res = smtp_check_connection($profile);
        }
        echo json_encode($res);
        exit;
    } catch (Exception $e) {
        echo json_encode(['ok'=>false,'msg'=>$e->getMessage()]);
        exit;
    }
}

// AJAX endpoint for real-time campaign progress
if ($action === 'get_campaign_progress') {
    $cid = (int)($_GET['campaign_id'] ?? $_POST['campaign_id'] ?? 0);
    header('Content-Type: application/json; charset=utf-8');
    
    if ($cid <= 0) {
        echo json_encode(['ok'=>false,'error'=>'Invalid campaign ID']);
        exit;
    }
    
    try {
        $campaign = get_campaign($pdo, $cid);
        if (!$campaign) {
            echo json_encode(['ok'=>false,'error'=>'Campaign not found']);
            exit;
        }
        
        // Get event counts
        $stmt = $pdo->prepare("
            SELECT 
                SUM(CASE WHEN event_type = 'delivered' THEN 1 ELSE 0 END) as delivered,
                SUM(CASE WHEN event_type = 'bounce' THEN 1 ELSE 0 END) as bounced,
                SUM(CASE WHEN event_type = 'deferred' THEN 1 ELSE 0 END) as deferred,
                SUM(CASE WHEN event_type = 'skipped_unsubscribe' THEN 1 ELSE 0 END) as skipped,
                COUNT(*) as total_events
            FROM events 
            WHERE campaign_id = ?
            AND event_type IN ('delivered', 'bounce', 'deferred', 'skipped_unsubscribe')
        ");
        $stmt->execute([$cid]);
        $stats = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Get active worker count (workers that logged events in last 10 seconds)
        $stmt = $pdo->prepare("
            SELECT COUNT(DISTINCT JSON_EXTRACT(details, '$.worker_pid')) as active_workers
            FROM events 
            WHERE campaign_id = ?
            AND JSON_EXTRACT(details, '$.worker_pid') IS NOT NULL
            AND created_at >= DATE_SUB(NOW(), INTERVAL 10 SECOND)
        ");
        $stmt->execute([$cid]);
        $workerCount = (int)$stmt->fetchColumn();
        
        $response = [
            'ok' => true,
            'campaign_id' => $cid,
            'status' => $campaign['status'],
            'progress_status' => $campaign['progress_status'] ?? 'draft',
            'total_recipients' => (int)$campaign['total_recipients'],
            'progress_sent' => (int)$campaign['progress_sent'],
            'progress_total' => (int)$campaign['progress_total'],
            'delivered' => (int)($stats['delivered'] ?? 0),
            'bounced' => (int)($stats['bounced'] ?? 0),
            'deferred' => (int)($stats['deferred'] ?? 0),
            'skipped' => (int)($stats['skipped'] ?? 0),
            'total_processed' => (int)($stats['total_events'] ?? 0),
            'active_workers' => $workerCount,
            'percentage' => 0,
        ];
        
        if ($response['total_recipients'] > 0) {
            $response['percentage'] = round(($response['total_processed'] / $response['total_recipients']) * 100, 1);
        }
        
        echo json_encode($response);
        exit;
    } catch (Exception $e) {
        echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
        exit;
    }
}

if ($action === 'create_job' || $action === 'create_campaign') {
    $name = trim($_POST['name'] ?? '');
    if ($name === '') {
        $name = 'New Extraction Job';
    }
    $stmt = $pdo->prepare("
        INSERT INTO jobs (name, status)
        VALUES (?, 'draft')
    ");
    $stmt->execute([$name]);
    $newId = (int)$pdo->lastInsertId();
    header("Location: ?page=editor&id={$newId}");
    exit;
}

if ($action === 'duplicate_job' || $action === 'duplicate_campaign') {
    $jid = (int)($_POST['job_id'] ?? $_POST['campaign_id'] ?? 0);
    if ($jid > 0) {
        try {
            $orig = get_job($pdo, $jid);
            if ($orig) {
                $newName = $orig['name'] . ' (Copy)';
                // Create new job with clean state - no timestamps, no progress, no errors
                $stmt = $pdo->prepare("
                    INSERT INTO jobs (name, profile_id, target_count, status, progress_status, progress_extracted, progress_total, started_at, completed_at, error_message)
                    VALUES (?, ?, ?, 'draft', NULL, 0, 0, NULL, NULL, NULL)
                ");
                $stmt->execute([
                    $newName,
                    $orig['profile_id'] ?? null,
                    $orig['target_count'] ?? 100,
                ]);
                $newId = (int)$pdo->lastInsertId();
                header("Location: ?page=editor&id={$newId}");
                exit;
            }
        } catch (Exception $e) {}
    }
    header("Location: ?page=list");
    exit;
}

if ($action === 'bulk_jobs' || $action === 'bulk_campaigns') {
    $ids = $_POST['job_ids'] ?? $_POST['campaign_ids'] ?? [];
    $bulk_action = $_POST['bulk_action'] ?? '';
    if (!is_array($ids) || empty($ids)) {
        header("Location: ?page=list");
        exit;
    }
    $ids = array_map('intval', $ids);
    if ($bulk_action === 'delete_selected') {
        foreach ($ids as $id) {
            try {
                $stmt = $pdo->prepare("DELETE FROM extracted_emails WHERE job_id = ?");
                $stmt->execute([$id]);
                $stmt = $pdo->prepare("DELETE FROM jobs WHERE id = ?");
                $stmt->execute([$id]);
            } catch (Exception $e) {}
        }
    } elseif ($bulk_action === 'duplicate_selected') {
        foreach ($ids as $id) {
            try {
                $orig = get_job($pdo, $id);
                if ($orig) {
                    $newName = $orig['name'] . ' (Copy)';
                    $stmt = $pdo->prepare("
                        INSERT INTO jobs (name, profile_id, target_count, status)
                        VALUES (?, ?, ?, 'draft')
                    ");
                    $stmt->execute([
                        $newName,
                        $orig['profile_id'] ?? null,
                        $orig['target_count'] ?? 100,
                    ]);
                }
            } catch (Exception $e) {}
        }
    }
    header("Location: ?page=list");
    exit;
}

if ($action === 'delete_unsubscribes') {
    // Option A: delete all unsubscribes
    try {
        $pdo->beginTransaction();
        $pdo->exec("DELETE FROM unsubscribes");
        $pdo->commit();
    } catch (Exception $e) {
        try { $pdo->rollBack(); } catch (Exception $_) {}
    }
    header("Location: ?page=activity&cleared_unsubscribes=1");
    exit;
}

if ($action === 'delete_bounces') {
    // Delete all events of type 'bounce'
    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("DELETE FROM events WHERE event_type = ?");
        $stmt->execute(['bounce']);
        $pdo->commit();
    } catch (Exception $e) {
        try { $pdo->rollBack(); } catch (Exception $_) {}
    }
    header("Location: ?page=activity&cleared_bounces=1");
    exit;
}

if ($action === 'delete_job' || $action === 'delete_campaign') {
    $jid = (int)($_POST['job_id'] ?? $_POST['campaign_id'] ?? 0);
    if ($jid > 0) {
        try {
            $stmt = $pdo->prepare("DELETE FROM extracted_emails WHERE job_id = ?");
            $stmt->execute([$jid]);
            $stmt = $pdo->prepare("DELETE FROM jobs WHERE id = ?");
            $stmt->execute([$jid]);
        } catch (Exception $e) {
            // ignore
        }
    }
    header("Location: ?page=list");
    exit;
}

if ($action === 'save_job' || $action === 'save_campaign') {
    $id        = (int)($_POST['id'] ?? 0);
    $name      = trim($_POST['name'] ?? '');
    $profile_id = (int)($_POST['profile_id'] ?? 0);
    $target_count = (int)($_POST['target_count'] ?? 0);
    $start_immediately = isset($_POST['start_immediately']) && $_POST['start_immediately'];
    
    error_log("SAVE_JOB ACTION: id={$id}, name='{$name}', profile_id={$profile_id}, target_count={$target_count}, start_immediately=" . ($start_immediately ? 'YES' : 'NO'));

    try {
        if ($target_count > 0) {
            $stmt = $pdo->prepare("
                UPDATE jobs
                SET name = ?, profile_id = ?, target_count = ?
                WHERE id = ?
            ");
            $stmt->execute([$name, $profile_id, $target_count, $id]);
            error_log("SAVE_JOB ACTION: Job {$id} updated with target_count");
        } else {
            $stmt = $pdo->prepare("
                UPDATE jobs
                SET name = ?, profile_id = ?
                WHERE id = ?
            ");
            $stmt->execute([$name, $profile_id, $id]);
            error_log("SAVE_JOB ACTION: Job {$id} updated without target_count");
        }
        
        // Verify save was successful
        $verify = $pdo->prepare("SELECT id, name, profile_id, target_count, status FROM jobs WHERE id = ?");
        $verify->execute([$id]);
        $saved_job = $verify->fetch(PDO::FETCH_ASSOC);
        error_log("SAVE_JOB ACTION: Verified job after save: " . json_encode($saved_job));
        
        // Check if should start immediately
        if ($start_immediately) {
            error_log("SAVE_JOB ACTION: start_immediately flag detected, auto-submitting to start_job action");
            // Auto-submit form to start_job action in hidden iframe (no visible intermediate page)
            echo '<iframe name="hiddenFrame" style="display:none;"></iframe>';
            echo '<form id="startForm" method="POST" action="?page=list" target="hiddenFrame">';
            echo '<input type="hidden" name="action" value="start_job">';
            echo '<input type="hidden" name="job_id" value="' . (int)$id . '">';
            echo '</form>';
            echo '<script>';
            echo 'console.log("SAVE_JOB: Auto-submitting start_job form for job_id=' . (int)$id . '");';
            echo 'document.getElementById("startForm").submit();';
            echo 'setTimeout(function() { window.location.href = "?page=list"; }, 500);';
            echo '</script>';
            exit;
        } else {
            error_log("SAVE_JOB ACTION: start_immediately flag NOT set, normal save flow");
        }
    } catch (Exception $e) {
        error_log("Failed to save job id {$id}: " . $e->getMessage());
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) || strpos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false) {
            http_response_code(500);
            echo json_encode(['error' => 'Failed to save job']);
            exit;
        }
        header("Location: ?page=editor&id={$id}&save_error=1");
        exit;
    }

    if (isset($_POST['go_to_review'])) {
        header("Location: ?page=review&id={$id}");
    } else {
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) || strpos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => 1, 'id' => $id]);
            exit;
        }
        header("Location: ?page=editor&id={$id}&saved=1");
    }
    exit;
}

// Start extraction job action
if ($action === 'start_job') {
    $job_id = (int)($_POST['job_id'] ?? $_GET['job_id'] ?? 0);
    error_log("START_JOB ACTION: ========== STARTING ==========");
    error_log("START_JOB ACTION: Received job_id=" . $job_id);
    error_log("START_JOB ACTION: POST data: " . json_encode($_POST));
    error_log("START_JOB ACTION: GET data: " . json_encode($_GET));
    
    if ($job_id <= 0) {
        error_log("START_JOB ACTION: ERROR - Invalid job_id (<=0)");
        header("Location: ?page=list&error=invalid_job_id");
        exit;
    }
    
    if ($job_id > 0) {
        error_log("START_JOB ACTION: Calling get_job() for job_id={$job_id}");
        $job = get_job($pdo, $job_id);
        error_log("START_JOB ACTION: Job fetched: " . ($job ? "YES" : "NO"));
        
        if ($job) {
            error_log("START_JOB ACTION: Job details: " . json_encode($job));
            error_log("START_JOB ACTION: profile_id = " . ($job['profile_id'] ?? 'NULL') . " (empty=" . (empty($job['profile_id']) ? 'YES' : 'NO') . ")");
        }
        
        if (!$job) {
            error_log("START_JOB ACTION: ERROR - Job not found in database");
            header("Location: ?page=list&error=job_not_found");
            exit;
        }
        
        if (empty($job['profile_id'])) {
            error_log("START_JOB ACTION: ERROR - Job has no profile_id assigned");
            header("Location: ?page=list&error=no_profile");
            exit;
        }
        
        if ($job && !empty($job['profile_id'])) {
            error_log("START_JOB ACTION: Job and profile_id valid, continuing...");
            try {
                // Get profile information
                $profile_stmt = $pdo->prepare("SELECT * FROM job_profiles WHERE id = ?");
                $profile_stmt->execute([$job['profile_id']]);
                $profile = $profile_stmt->fetch(PDO::FETCH_ASSOC);
                
                error_log("START_JOB ACTION: Profile fetched: " . ($profile ? "YES (id=" . $profile['id'] . ")" : "NO"));
                
                if ($profile) {
                    // Use target from job or profile
                    $targetCount = !empty($job['target_count']) ? (int)$job['target_count'] : (int)$profile['target_count'];
                    
                    // Update job status to extracting immediately (like old "sending" status)
                    $stmt = $pdo->prepare("UPDATE jobs SET status = 'extracting', progress_status = 'extracting', progress_total = ?, started_at = NOW() WHERE id = ?");
                    $stmt->execute([$targetCount, $job_id]);
                    error_log("START_JOB ACTION: Job {$job_id} status updated to 'extracting', progress_total={$targetCount}");
                    
                    $redirect = "?page=list&started_job=" . (int)$job_id;
                    
                    // Close session to allow immediate redirect
                    session_write_close();
                    
                    // Use fastcgi_finish_request if available (like old email sending system)
                    if (function_exists('fastcgi_finish_request')) {
                        error_log("START_JOB ACTION: Using fastcgi_finish_request method");
                        header("Location: $redirect");
                        echo "<!doctype html><html><body>Extracting emails in background... Redirecting.</body></html>";
                        @ob_end_flush();
                        @flush();
                        fastcgi_finish_request();
                        
                        ignore_user_abort(true);
                        set_time_limit(0);
                        
                        // Execute extraction in same process (non-blocking for user)
                        try {
                            error_log("START_JOB ACTION: Starting perform_extraction for job {$job_id}");
                            perform_extraction($pdo, $job_id, $job, $profile);
                            error_log("START_JOB ACTION: perform_extraction completed for job {$job_id}");
                        } catch (Throwable $e) {
                            error_log("Job extraction error: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
                            error_log("Stack trace: " . $e->getTraceAsString());
                        }
                        exit;
                    }
                    
                    // Fallback: Try background worker (if fastcgi not available)
                    error_log("START_JOB ACTION: fastcgi not available, trying background worker");
                    $spawned = spawn_extraction_worker($pdo, $job_id);
                    error_log("START_JOB ACTION: Worker spawn result: " . ($spawned ? "SUCCESS" : "FAILED"));
                    
                    if ($spawned) {
                        header("Location: $redirect");
                        exit;
                    }
                    
                    // Last resort: synchronous extraction (blocking but works everywhere)
                    error_log("START_JOB ACTION: Using synchronous (blocking) method");
                    header("Location: $redirect");
                    echo "<!doctype html><html><body>Extracting emails... Please wait.</body></html>";
                    @ob_end_flush();
                    @flush();
                    
                    ignore_user_abort(true);
                    set_time_limit(0);
                    
                    try {
                        perform_extraction($pdo, $job_id, $job, $profile);
                        error_log("START_JOB ACTION: Synchronous extraction completed for job {$job_id}");
                    } catch (Throwable $e) {
                        error_log("Job extraction error (blocking): " . $e->getMessage());
                    }
                    exit;
                }
                error_log("START_JOB ACTION: Profile not found for job {$job_id}");
            } catch (Exception $e) {
                error_log("Failed to start job {$job_id}: " . $e->getMessage());
                header("Location: ?page=editor&id={$job_id}&error=" . urlencode($e->getMessage()));
                exit;
            }
        } else {
            error_log("START_JOB ACTION: Job check failed - job exists: " . ($job ? "YES" : "NO") . ", has profile_id: " . (!empty($job['profile_id']) ? "YES" : "NO"));
        }
    } else {
        error_log("START_JOB ACTION: Invalid job_id: " . $job_id);
    }
    error_log("START_JOB ACTION: Redirecting to list page (fallback)");
    header("Location: ?page=list");
    exit;
}

if ($action === 'send_test_message') {
    $id = (int)($_POST['id'] ?? 0);
    $addresses = trim($_POST['test_addresses'] ?? '');
    $campaign = get_campaign($pdo, $id);
    if ($campaign && $addresses !== '') {
        $toUpdate = false;
        $updFields = [];
        $updVals = [];

        if (isset($_POST['subject'])) {
            $toUpdate = true;
            $updFields[] = "subject = ?";
            $updVals[] = trim($_POST['subject']);
        }
        if (isset($_POST['preheader'])) {
            $toUpdate = true;
            $updFields[] = "preheader = ?";
            $updVals[] = trim($_POST['preheader']);
        }
        if (isset($_POST['sender_name'])) {
            $toUpdate = true;
            $updFields[] = "sender_name = ?";
            $updVals[] = trim($_POST['sender_name']);
        }
        $unsubscribe_enabled = isset($_POST['unsubscribe_enabled']) ? 1 : 0;
        if ($unsubscribe_enabled != ($campaign['unsubscribe_enabled'] ?? 0)) {
            $toUpdate = true;
            $updFields[] = "unsubscribe_enabled = ?";
            $updVals[] = $unsubscribe_enabled;
        }

        if ($toUpdate && !empty($updFields)) {
            $updVals[] = $id;
            $sql = "UPDATE campaigns SET " . implode(',', $updFields) . " WHERE id = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($updVals);
            $campaign = get_campaign($pdo, $id);
        }

        $parts = array_filter(array_map('trim', preg_split("/\r\n|\n|\r|,/", $addresses)));
        $valid = [];
        foreach ($parts as $p) {
            if (filter_var($p, FILTER_VALIDATE_EMAIL)) $valid[] = $p;
        }
        if (!empty($valid)) {
            $recipientsText = implode("\n", $valid);

            session_write_close();

            if (function_exists('fastcgi_finish_request')) {
                header("Location: ?page=review&id={$id}&test_sent=1");
                echo "<!doctype html><html><body>Sending test in background... Redirecting.</body></html>";
                @ob_end_flush();
                @flush();
                fastcgi_finish_request();

                try {
                    send_campaign_real($pdo, $campaign, $recipientsText, true);
                } catch (Exception $e) {}
                exit;
            }

            // fallback: try spawn; if spawn fails do synchronous send (web request will wait)
            $spawned = spawn_background_send($pdo, $id, $recipientsText);
            if (!$spawned) {
                // synchronous fallback (guarantees it actually sends)
                ignore_user_abort(true);
                set_time_limit(0);
                try {
                    send_campaign_real($pdo, $campaign, $recipientsText, true);
                } catch (Exception $e) {}
            }
        }
    }
    header("Location: ?page=review&id={$id}&test_sent=1");
    exit;
}

if ($action === 'send_campaign') {
    $id = (int)($_POST['id'] ?? 0);
    $testRecipients = $_POST['test_recipients'] ?? '';
    $audience_select = trim($_POST['audience_select'] ?? '');
    $campaign = get_campaign($pdo, $id);
    if ($campaign) {
        $toUpdate = false;
        $updFields = [];
        $updVals = [];

        if (isset($_POST['subject'])) {
            $toUpdate = true;
            $updFields[] = "subject = ?";
            $updVals[] = trim($_POST['subject']);
        }
        if (isset($_POST['preheader'])) {
            $toUpdate = true;
            $updFields[] = "preheader = ?";
            $updVals[] = trim($_POST['preheader']);
        }
        if (isset($_POST['sender_name'])) {
            $toUpdate = true;
            $updFields[] = "sender_name = ?";
            $updVals[] = trim($_POST['sender_name']);
        }
        $unsubscribe_enabled = isset($_POST['unsubscribe_enabled']) ? 1 : 0;
        if ($unsubscribe_enabled != ($campaign['unsubscribe_enabled'] ?? 0)) {
            $toUpdate = true;
            $updFields[] = "unsubscribe_enabled = ?";
            $updVals[] = $unsubscribe_enabled;
        }

        // If rotation is OFF, allow overriding the campaign.from_email from the Review form select
        $rotSettings = get_rotation_settings($pdo);
        $rotationEnabled = (int)$rotSettings['rotation_enabled'] === 1;
        if (!$rotationEnabled && isset($_POST['from_email'])) {
            $fromOverride = trim($_POST['from_email']);
            if ($fromOverride !== '') {
                $toUpdate = true;
                $updFields[] = "from_email = ?";
                $updVals[] = $fromOverride;
                $campaign['from_email'] = $fromOverride; // keep in-memory
            }
        }

        if ($toUpdate && !empty($updFields)) {
            $updVals[] = $id;
            $sql = "UPDATE campaigns SET " . implode(',', $updFields) . " WHERE id = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($updVals);
            $campaign = get_campaign($pdo, $id);
        }

        $allRecipients = [];

        if ($audience_select !== '' && strpos($audience_select, 'list:') === 0) {
            $lid = (int)substr($audience_select, strlen('list:'));
            if ($lid > 0) {
                $contacts = get_contacts_for_list($pdo, $lid);
                foreach ($contacts as $ct) {
                    $em = trim((string)($ct['email'] ?? ''));
                    if ($em === '') continue;
                    if (!filter_var($em, FILTER_VALIDATE_EMAIL)) continue;
                    if (is_unsubscribed($pdo, $em)) continue;
                    $allRecipients[] = strtolower($em);
                }
            }
        } else {
            $parts = array_filter(array_map('trim', preg_split("/\r\n|\n|\r|,/", $testRecipients)));
            foreach ($parts as $p) {
                if (!filter_var($p, FILTER_VALIDATE_EMAIL)) continue;
                if (is_unsubscribed($pdo, $p)) continue;
                $allRecipients[] = strtolower($p);
            }
        }

        $allRecipients = array_values(array_unique($allRecipients));

        if (empty($allRecipients)) {
            header("Location: ?page=review&id={$id}&no_recipients=1");
            exit;
        }

        try {
            $totalRecipients = count($allRecipients);
            $stmt = $pdo->prepare("UPDATE campaigns SET status='sending', total_recipients=? WHERE id=?");
            $stmt->execute([$totalRecipients, $campaign['id']]);
        } catch (Exception $e) {}

        $recipientsTextFull = implode("\n", $allRecipients);

        $redirect = "?page=list&sent=1&sent_campaign=" . (int)$campaign['id'];

        session_write_close();

        $rotSettings = get_rotation_settings($pdo);
        $rotationEnabled = (int)$rotSettings['rotation_enabled'] === 1;
        $activeProfiles = array_values(array_filter(get_profiles($pdo), function($p){ return (int)$p['active'] === 1; }));

        // Check if we should use multi-profile rotation or single profile
        if ($rotationEnabled && count($activeProfiles) > 1) {
            // ROTATION MODE: Use ALL active profiles and distribute emails among them
            // Use fastcgi_finish_request if available to send in background
            if (function_exists('fastcgi_finish_request')) {
                header("Location: $redirect");
                echo "<!doctype html><html><body>Sending in background... Redirecting.</body></html>";
                @ob_end_flush();
                @flush();
                fastcgi_finish_request();

                ignore_user_abort(true);
                set_time_limit(0);

                // Send using multi-profile rotation function
                try {
                    send_campaign_with_rotation($pdo, $campaign, $allRecipients, $activeProfiles, false);
                } catch (Throwable $e) {
                    error_log("Campaign send error (rotation fastcgi): " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
                    error_log("Stack trace: " . $e->getTraceAsString());
                }
                exit;
            }

            // Fallback: send synchronously (blocking)
            header("Location: $redirect");
            echo "<!doctype html><html><body>Sending campaign... Please wait.</body></html>";
            @ob_end_flush();
            @flush();

            ignore_user_abort(true);
            set_time_limit(0);

            try {
                send_campaign_with_rotation($pdo, $campaign, $allRecipients, $activeProfiles, false);
            } catch (Throwable $e) {
                error_log("Campaign send error (rotation blocking): " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
                error_log("Stack trace: " . $e->getTraceAsString());
            }
            exit;
        }

        // SINGLE PROFILE MODE: Use one profile (rotation disabled or only 1 active profile)
        // Determine which profile to use
        $profile = null;
        if (!empty($activeProfiles)) {
            // No rotation or only one profile: find profile matching campaign's from_email or use first active
            foreach ($activeProfiles as $p) {
                if (strtolower($p['from_email']) === strtolower($campaign['from_email'])) {
                    $profile = $p;
                    break;
                }
            }
            if (!$profile) $profile = $activeProfiles[0];
        }

        if (!$profile) {
            header("Location: ?page=review&id={$id}&send_err=no_profile");
            exit;
        }

        // Use fastcgi_finish_request if available to send in background
        if (function_exists('fastcgi_finish_request')) {
            header("Location: $redirect");
            echo "<!doctype html><html><body>Sending in background... Redirecting.</body></html>";
            @ob_end_flush();
            @flush();
            fastcgi_finish_request();

            ignore_user_abort(true);
            set_time_limit(0);

            // Send using new connection-based function
            try {
                send_campaign_with_connections($pdo, $campaign, $allRecipients, (int)$profile['id'], false);
            } catch (Exception $e) {
                error_log("Campaign send error: " . $e->getMessage());
            }
            exit;
        }

        // Fallback: send synchronously (blocking)
        header("Location: $redirect");
        echo "<!doctype html><html><body>Sending campaign... Please wait.</body></html>";
        @ob_end_flush();
        @flush();

        ignore_user_abort(true);
        set_time_limit(0);

        try {
            send_campaign_with_connections($pdo, $campaign, $allRecipients, (int)$profile['id'], false);
        } catch (Exception $e) {
            error_log("Campaign send error: " . $e->getMessage());
        }
        exit;

        // OLD COMPLEX LOGIC REMOVED - keeping below for reference but not used
        /*
        // If rotation enabled and multiple active profiles -> batch-wise distribution per profile with parallel workers
        if ($rotationEnabled && count($activeProfiles) > 0) {
            $globalDefaultBatch = max(1, (int)($rotSettings['batch_size'] ?? 1));
            $globalTargetPerMinute = max(1, (int)($rotSettings['target_per_minute'] ?? 1000));
            $globalWorkers = max(MIN_WORKERS, (int)($rotSettings['workers'] ?? DEFAULT_WORKERS));
            
            // OPTIONAL logging for monitoring (NOT a limit)
            if ($globalWorkers >= WORKERS_LOG_WARNING_THRESHOLD) {
                error_log("Info: Rotation settings using $globalWorkers workers (NOT an error)");
            }
            
            $globalMessagesPerWorker = max(MIN_MESSAGES_PER_WORKER, (int)($rotSettings['messages_per_worker'] ?? DEFAULT_MESSAGES_PER_WORKER));

            // prepare profiles meta
            $profilesMeta = [];
            foreach ($activeProfiles as $i => $p) {
                $meta = [];
                $meta['profile'] = $p;
                $meta['batch_size'] = max(1, (int)($p['batch_size'] ?? $globalDefaultBatch));
                $meta['send_rate'] = max(0, (int)($p['send_rate'] ?? 0)); // msg / second (0 = no throttle)
                $meta['max_sends'] = max(0, (int)($p['max_sends'] ?? 0)); // 0 = unlimited
                $meta['used'] = max(0, (int)($p['sends_used'] ?? 0));
                $profilesMeta[] = $meta;
            }
            $pcount = count($profilesMeta);

            // compute default per-profile send_rate if not set (distribute globalTargetPerMinute)
            $perProfilePerMin = (int)ceil($globalTargetPerMinute / max(1, $pcount));
            $defaultPerProfilePerSec = max(1, (int)ceil($perProfilePerMin / 60));

            // Distribution algorithm: sequentially assign profile.batch_size per profile, skipping profiles that reached max_sends
            $parts = array_fill(0, $pcount, []);
            $n = count($allRecipients);
            $idx = 0; // pointer into recipients
            $pi = 0;  // profile index pointer
            $roundsWithoutAssign = 0;
            while ($idx < $n) {
                $pm = &$profilesMeta[$pi];
                // remaining capacity for this profile
                if ($pm['max_sends'] > 0) {
                    $capacity = max(0, $pm['max_sends'] - $pm['used']);
                } else {
                    $capacity = PHP_INT_MAX;
                }

                if ($capacity <= 0) {
                    // skip this profile (can't accept more)
                    $pi = ($pi + 1) % $pcount;
                    $roundsWithoutAssign++;
                    if ($roundsWithoutAssign >= $pcount) {
                        // no profile can accept more sends -> break to avoid infinite loop
                        break;
                    }
                    continue;
                }

                $toTake = min($pm['batch_size'], $capacity, $n - $idx);
                $slice = array_slice($allRecipients, $idx, $toTake);
                if (!empty($slice)) {
                    $parts[$pi] = array_merge($parts[$pi], $slice);
                    $pm['used'] += count($slice);
                    $idx += count($slice);
                    $roundsWithoutAssign = 0;
                } else {
                    $roundsWithoutAssign++;
                }

                // move to next profile (respect mode)
                if (($rotSettings['mode'] ?? 'sequential') === 'random') {
                    $pi = rand(0, $pcount - 1);
                } else {
                    $pi = ($pi + 1) % $pcount;
                }
            }
            // If some recipients remain unassigned (e.g., all profiles exhausted), append them to last profile fallback
            if ($idx < $n) {
                // find any profile with capacity or fallback to first active
                $assigned = false;
                foreach ($profilesMeta as $j => $pmf) {
                    if ($pmf['max_sends'] === 0 || $pmf['max_sends'] > $pmf['used']) {
                        $parts[$j] = array_merge($parts[$j], array_slice($allRecipients, $idx));
                        $assigned = true; break;
                    }
                }
                if (!$assigned) {
                    $parts[0] = array_merge($parts[0], array_slice($allRecipients, $idx));
                }
            }

            // Now spawn per-profile workers / or send in-process. For each profile, determine effective send_rate (override)
            $spawnFailures = [];
            if (function_exists('fastcgi_finish_request')) {
                // detach response then do sends with workers if configured
                header("Location: $redirect");
                echo "<!doctype html><html><body>Sending in background... Redirecting.</body></html>";
                @ob_end_flush();
                @flush();
                fastcgi_finish_request();

                ignore_user_abort(true);
                set_time_limit(0);

                foreach ($profilesMeta as $idx => $pm) {
                    $p = $pm['profile'];
                    $recips = array_values(array_unique(array_map('trim', $parts[$idx])));
                    if (empty($recips)) continue;
                    $recipsText = implode("\n", $recips);
                    $effectiveRate = $pm['send_rate'] > 0 ? $pm['send_rate'] : $defaultPerProfilePerSec;
                    $overrides = ['send_rate' => $effectiveRate];
                    try {
                        // Use concurrent sending if workers configured and pcntl available
                        if ($globalWorkers > 1 && function_exists('pcntl_fork')) {
                            send_campaign_with_connections_for_profile_concurrent($pdo, $campaign, $recips, $p, false);
                        } else {
                            send_campaign_real($pdo, $campaign, $recipsText, false, (int)$p['id'], $overrides);
                        }
                    } catch (Exception $e) {
                        // continue
                    }
                }

                exit;
            }

            // Otherwise attempt to spawn parallel workers per profile (passing overrides so each worker can throttle)
            foreach ($profilesMeta as $idx => $pm) {
                $p = $pm['profile'];
                $recips = array_values(array_unique(array_map('trim', $parts[$idx])));
                if (empty($recips)) continue;
                $effectiveRate = $pm['send_rate'] > 0 ? $pm['send_rate'] : $defaultPerProfilePerSec;
                $overrides = ['send_rate' => $effectiveRate];
                $cycleDelay = (int)($p['cycle_delay_ms'] ?? DEFAULT_CYCLE_DELAY_MS);
                
                // Spawn parallel workers for this profile's recipients
                $result = spawn_parallel_workers($pdo, (int)$campaign['id'], $recips, $globalWorkers, $globalMessagesPerWorker, (int)$p['id'], $overrides, $cycleDelay);
                
                if (!$result['success']) {
                    // Add failures for synchronous fallback
                    foreach ($result['failures'] as $failure) {
                        $recipsText = implode("\n", $failure['chunk']);
                        $spawnFailures[] = ['profile' => $p, 'recips' => $recipsText, 'overrides' => $overrides];
                    }
                }
            }

            // If any spawn failed, fallback to synchronous sending for those profiles (guarantees actual send)
            if (!empty($spawnFailures)) {
                // send redirect first so UI isn't waiting too long
                header("Location: $redirect");
                echo "<!doctype html><html><body>Sending (fallback) in progress... Please wait if your browser does not redirect.</body></html>";
                @ob_end_flush();
                @flush();

                ignore_user_abort(true);
                set_time_limit(0);

                foreach ($spawnFailures as $fail) {
                    try {
                        send_campaign_real($pdo, $campaign, $fail['recips'], false, (int)$fail['profile']['id'], $fail['overrides']);
                    } catch (Exception $e) {
                        // continue
                    }
                }

                exit;
            }

            // All spawns succeeded -> safe to redirect immediately
            header("Location: $redirect");
            exit;
        } else {
            // Rotation disabled: send entire campaign with the selected SMTP using parallel workers
            // Get the profile for this campaign (either forced or first active)
            $profile = null;
            if (!empty($activeProfiles)) {
                // Try to find profile matching campaign's from_email
                foreach ($activeProfiles as $p) {
                    if (strtolower($p['from_email']) === strtolower($campaign['from_email'])) {
                        $profile = $p;
                        break;
                    }
                }
                // Fallback to first active profile
                if (!$profile) $profile = $activeProfiles[0];
            }
            
            $workers = DEFAULT_WORKERS;
            $messagesPerWorker = DEFAULT_MESSAGES_PER_WORKER;
            
            if ($profile) {
                $workers = max(MIN_WORKERS, (int)($profile['workers'] ?? DEFAULT_WORKERS));
                
                // OPTIONAL logging for monitoring (NOT a limit)
                if ($workers >= WORKERS_LOG_WARNING_THRESHOLD) {
                    error_log("Info: Profile {$profile['id']} using $workers workers for campaign " . (int)$campaign['id']);
                }
                
                $messagesPerWorker = max(MIN_MESSAGES_PER_WORKER, (int)($profile['messages_per_worker'] ?? DEFAULT_MESSAGES_PER_WORKER));
            }
            
            if (function_exists('fastcgi_finish_request')) {
                header("Location: $redirect");
                echo "<!doctype html><html><body>Sending in background... Redirecting.</body></html>";
                @ob_end_flush();
                @flush();
                fastcgi_finish_request();

                ignore_user_abort(true);
                set_time_limit(0);

                try {
                    // Use concurrent sending if workers > 1 and profile is configured
                    if ($workers > 1 && $profile && function_exists('pcntl_fork')) {
                        send_campaign_with_connections_for_profile_concurrent($pdo, $campaign, $allRecipients, $profile, false);
                    } else {
                        send_campaign_real($pdo, $campaign, $recipientsTextFull);
                    }
                } catch (Exception $e) {}
                exit;
            }

            // Try to spawn parallel workers
            $cycleDelay = $profile ? (int)($profile['cycle_delay_ms'] ?? DEFAULT_CYCLE_DELAY_MS) : DEFAULT_CYCLE_DELAY_MS;
            $result = spawn_parallel_workers($pdo, (int)$campaign['id'], $allRecipients, $workers, $messagesPerWorker, $profile ? (int)$profile['id'] : null, [], $cycleDelay);
            if (!$result['success'] || $result['workers'] === 0) {
                // synchronous fallback (web request will wait) to ensure delivery instead of remaining 'sending'
                header("Location: $redirect");
                echo "<!doctype html><html><body>Sending (fallback) in progress... Please wait if your browser does not redirect.</body></html>";
                @ob_end_flush();
                @flush();
                
                ignore_user_abort(true);
                set_time_limit(0);
                try {
                    send_campaign_real($pdo, $campaign, $recipientsTextFull);
                } catch (Exception $e) {}
                exit;
            }

            // All workers spawned successfully
            header("Location: $redirect");
            exit;
        }
        */
        // END OLD COMPLEX LOGIC

    }
    header("Location: $redirect");
    exit;
}

if ($action === 'save_rotation') {
    $rotation_enabled      = isset($_POST['rotation_enabled']) ? 1 : 0;
    
    // Read workers from POST - NO MAXIMUM LIMIT - accepts ANY value (1, 100, 500, 1000+)
    $workers               = max(MIN_WORKERS, (int)($_POST['workers'] ?? DEFAULT_WORKERS));
    
    // OPTIONAL logging for monitoring (NOT a limit)
    if ($workers >= WORKERS_LOG_WARNING_THRESHOLD) {
        error_log("Info: Rotation settings saved with $workers workers (NOT an error)");
    }
    
    $emails_per_worker   = max(MIN_EMAILS_PER_WORKER, min(MAX_EMAILS_PER_WORKER, (int)($_POST['emails_per_worker'] ?? $_POST['messages_per_worker'] ?? DEFAULT_EMAILS_PER_WORKER)));
    
    update_rotation_settings($pdo, [
        'rotation_enabled'      => $rotation_enabled,
        'workers'               => $workers,
        'emails_per_worker'     => $emails_per_worker,
    ]);

    header("Location: ?page=list&rot_saved=1");
    exit;
}


if ($action === 'save_profile') {
    $profile_id   = (int)($_POST['profile_id'] ?? 0);
    $profile_name = trim($_POST['profile_name'] ?? '');
    $api_key      = trim($_POST['api_key'] ?? '');
    $search_query = trim($_POST['search_query'] ?? '');
    $target_count = max(1, min(10000, (int)($_POST['target_count'] ?? 100)));
    $country      = trim($_POST['country'] ?? '');
    $filter_business_only = isset($_POST['filter_business_only']) ? 1 : 0;
    $active       = isset($_POST['active']) ? 1 : 0;
    
    // Workers field - NO MAXIMUM LIMIT - accepts ANY value (1, 100, 500, 1000+)
    $profile_workers = max(MIN_WORKERS, (int)($_POST['workers'] ?? DEFAULT_WORKERS));
    
    // OPTIONAL logging for monitoring (NOT a limit)
    if ($profile_workers >= WORKERS_LOG_WARNING_THRESHOLD) {
        error_log("Info: Job profile saved with $profile_workers workers (NOT an error)");
    }
    
    // Emails per worker field - controls maximum number of emails each worker extracts
    $profile_emails_per_worker = max(MIN_EMAILS_PER_WORKER, min(MAX_EMAILS_PER_WORKER, (int)($_POST['emails_per_worker'] ?? DEFAULT_EMAILS_PER_WORKER)));
    
    // Cycle delay in milliseconds - delay between worker processing cycles
    $profile_cycle_delay_ms = max(MIN_CYCLE_DELAY_MS, min(MAX_CYCLE_DELAY_MS, (int)($_POST['cycle_delay_ms'] ?? DEFAULT_CYCLE_DELAY_MS)));

    try {
        if ($profile_id > 0) {
            $stmt = $pdo->prepare("
                UPDATE job_profiles SET
                  profile_name=?, api_key=?, search_query=?, target_count=?, 
                  filter_business_only=?, country=?, active=?,
                  workers=?, emails_per_worker=?, cycle_delay_ms=?
                WHERE id=?
            ");
            $stmt->execute([
                $profile_name, $api_key, $search_query, $target_count,
                $filter_business_only, $country, $active,
                $profile_workers, $profile_emails_per_worker, $profile_cycle_delay_ms,
                $profile_id
            ]);
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO job_profiles
                  (profile_name, api_key, search_query, target_count,
                   filter_business_only, country, active,
                   workers, emails_per_worker, cycle_delay_ms)
                VALUES (?,?,?,?,?,?,?,?,?,?)
            ");
            $stmt->execute([
                $profile_name, $api_key, $search_query, $target_count,
                $filter_business_only, $country, $active,
                $profile_workers, $profile_emails_per_worker, $profile_cycle_delay_ms
            ]);
        }
    } catch (Exception $e) {
        error_log("Failed to save job profile: " . $e->getMessage());
    }

    header("Location: ?page=list&profiles=1");
    exit;
}

if ($action === 'delete_profile') {
    $pid = (int)($_POST['profile_id'] ?? 0);
    if ($pid > 0) {
        $stmt = $pdo->prepare("DELETE FROM job_profiles WHERE id = ?");
        $stmt->execute([$pid]);
    }
    header("Location: ?page=list&profiles=1");
    exit;
}

///////////////////////
//  CONTACTS ACTIONS
///////////////////////
if ($action === 'create_contact_list') {
    $listName = trim($_POST['list_name'] ?? '');
    if ($listName !== '') {
        $stmt = $pdo->prepare("INSERT INTO contact_lists (name, type) VALUES (?, 'list')");
        $stmt->execute([$listName]);
    }
    header("Location: ?page=contacts");
    exit;
}

if ($action === 'delete_contact_list') {
    $lid = (int)($_POST['list_id'] ?? 0);
    if ($lid > 0) {
        $stmt = $pdo->prepare("DELETE FROM contacts WHERE list_id = ?");
        $stmt->execute([$lid]);
        $stmt = $pdo->prepare("DELETE FROM contact_lists WHERE id = ?");
        $stmt->execute([$lid]);
    }
    header("Location: ?page=contacts");
    exit;
}

if ($action === 'add_contact_manual') {
    $lid    = (int)($_POST['list_id'] ?? 0);
    $email  = trim($_POST['email'] ?? '');
    $first  = trim($_POST['first_name'] ?? '');
    $last   = trim($_POST['last_name'] ?? '');
    if ($lid > 0 && filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $stmt = $pdo->prepare("INSERT INTO contacts (list_id, email, first_name, last_name) VALUES (?, ?, ?, ?)");
        $stmt->execute([$lid, strtolower($email), $first, $last]);
    }
    header("Location: ?page=contacts&list_id=".$lid);
    exit;
}

if ($action === 'upload_contacts_csv') {
    $lid = (int)($_POST['list_id'] ?? 0);
    if ($lid > 0 && isset($_FILES['csv_file']) && is_uploaded_file($_FILES['csv_file']['tmp_name'])) {
        $path = $_FILES['csv_file']['tmp_name'];
        if (($fh = fopen($path, 'r')) !== false) {
            $header = fgetcsv($fh);
            $rows   = [];
            $emailCol = 0;

            if ($header !== false) {
                $found = null;
                foreach ($header as $i => $col) {
                    if (stripos($col, 'email') !== false) {
                        $found = $i;
                        break;
                    }
                }
                if ($found === null) {
                    $emailCol = 0;
                    $rows[] = $header;
                } else {
                    $emailCol = $found;
                }

                while (($row = fgetcsv($fh)) !== false) {
                    $rows[] = $row;
                }
            }
            fclose($fh);

            $ins = $pdo->prepare("INSERT INTO contacts (list_id, email) VALUES (?, ?)");
            foreach ($rows as $row) {
                if (!isset($row[$emailCol])) continue;
                $email = trim($row[$emailCol]);
                if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    if (is_unsubscribed($pdo, $email)) continue;
                    $ins->execute([$lid, strtolower($email)]);
                }
            }
        }
    }
    header("Location: ?page=contacts&list_id=".$lid);
    exit;
}

///////////////////////
//  DATA FOR PAGES
///////////////////////
// Check if PDO is available before querying database
if ($pdo === null) {
    // PDO not initialized - database not configured yet
    // Redirect to installation if not already there
    if (!isset($_GET['action']) || !in_array($_GET['action'], ['install', 'do_install'])) {
        header('Location: ' . $_SERVER['SCRIPT_NAME'] . '?action=install');
        exit;
    }
    // Set empty defaults for installation page
    $rotationSettings = ['rotation_enabled' => 0];
    $profiles = [];
    $contactLists = [];
} else {
    // PDO is available, fetch data
    $rotationSettings = get_rotation_settings($pdo);
    $profiles         = get_profiles($pdo);
    $contactLists     = []; // Not used in email extraction system
}

$editProfile      = null;
if ($page === 'list' && isset($_GET['edit_profile'])) {
    $eid = (int)$_GET['edit_profile'];
    foreach ($profiles as $p) {
        if ((int)$p['id'] === $eid) {
            $editProfile = $p;
            break;
        }
    }
}

$isSingleSendsPage = in_array($page, ['list','editor','review','stats'], true);


?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>MAFIA MAILER - Professional Email Marketing</title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <link href="https://fonts.googleapis.com/css2?family=Open+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
  <style>
    :root {
      --sg-blue: #1A82E2;
      --sg-blue-dark: #0F5BB5;
      --sg-blue-light: #E3F2FD;
      --sg-bg: #F6F8FB;
      --sg-border: #E3E8F0;
      --sg-text: #1F2933;
      --sg-muted: #6B778C;
      --sg-success: #12B886;
      --sg-success-light: #D3F9E9;
      --sg-danger: #FA5252;
      --sg-danger-light: #FFE5E5;
      --sg-warning: #FAB005;
      --sg-warning-light: #FFF4DB;
      --sg-shadow-sm: 0 1px 3px 0 rgba(0, 0, 0, 0.1), 0 1px 2px 0 rgba(0, 0, 0, 0.06);
      --sg-shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
      --sg-shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
    }
    * {
      box-sizing: border-box;
    }
    html {
      scroll-behavior: smooth;
    }
    body {
      margin: 0;
      font-family: "Open Sans", system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
      background: var(--sg-bg);
      color: var(--sg-text);
      line-height: 1.6;
      -webkit-font-smoothing: antialiased;
      -moz-osx-font-smoothing: grayscale;
    }
    a { 
      color: var(--sg-blue-dark); 
      text-decoration: none;
      transition: color 0.15s ease;
    }
    a:hover { 
      text-decoration: underline;
      color: var(--sg-blue);
    }

    .app-shell {
      display: flex;
      min-height: 100vh;
      overflow: hidden;
    }

    /* MAIN LEFT NAV (SendGrid-style) */
    .nav-main {
      width: 220px;
      background: #ffffff;
      border-right: 1px solid var(--sg-border);
      display: flex;
      flex-direction: column;
    }
    .nav-header {
      padding: 16px 16px 12px;
      border-bottom: 1px solid var(--sg-border);
      display: flex;
      align-items: center;
      gap: 10px;
    }
    .nav-avatar {
      width: 32px;
      height: 32px;
      border-radius: 999px;
      background: var(--sg-blue);
      color: #fff;
      display: flex;
      align-items: center;
      justify-content: center;
      font-weight: 600;
      font-size: 14px;
    }
    .nav-user-info {
      display: flex;
      flex-direction: column;
      gap: 2px;
    }
    .nav-user-email {
      font-size: 13px;
      font-weight: 600;
    }
    .nav-user-sub {
      font-size: 11px;
      color: var(--sg-muted);
    }
    .nav-scroll {
      flex: 1;
      overflow-y: auto;
      padding: 10px 8px 16px;
    }
    .nav-section-title {
      font-size: 11px;
      font-weight: 600;
      text-transform: uppercase;
      color: var(--sg-muted);
      letter-spacing: .05em;
      margin: 10px 8px 4px;
    }
    .nav-link {
      display: flex;
      align-items: center;
      padding: 8px 12px;
      margin: 2px 4px;
      border-radius: 6px;
      font-size: 14px;
      color: var(--sg-muted);
      transition: all 0.15s ease;
      font-weight: 500;
    }
    .nav-link span {
      flex: 1;
    }
    .nav-link.active {
      background: linear-gradient(135deg, var(--sg-blue-light) 0%, #E9F3FF 100%);
      color: var(--sg-blue-dark);
      font-weight: 600;
      box-shadow: var(--sg-shadow-sm);
    }
    .nav-link:hover {
      background: var(--sg-bg);
      text-decoration: none;
      color: var(--sg-blue);
      transform: translateX(2px);
    }
    .nav-link.active:hover {
      transform: translateX(0);
    }
    .nav-footer {
      padding: 10px 12px;
      border-top: 1px solid var(--sg-border);
      font-size: 11px;
      color: var(--sg-muted);
    }

    /* Sending profiles slide panel */
    .sidebar-sp {
      position: relative;
      width: 0;
      flex-shrink: 0;
      transition: width 0.25s ease;
      overflow: hidden;
      pointer-events: none;
    }
    .sidebar-sp.open {
      width: 400px;
      pointer-events: auto;
    }
    .sidebar-inner {
      position: fixed;
      left: 220px;
      top: 0;
      bottom: 0;
      width: 400px;
      background: #fff;
      border-right: 1px solid var(--sg-border);
      box-shadow: 4px 0 16px rgba(15, 91, 181, 0.12);
      display: flex;
      flex-direction: column;
      transform: translateX(-100%);
      transition: transform 0.25s ease, visibility 0.2s ease;
      z-index: 30;
      visibility: hidden;
      pointer-events: none;
    }
    .sidebar-sp.open .sidebar-inner {
      transform: translateX(0);
      visibility: visible;
      pointer-events: auto;
    }

    .page-wrapper {
      flex: 1;
      transition: transform 0.25s ease;
      display: flex;
      flex-direction: column;
    }
    .page-wrapper.shifted {
      transform: translateX(400px);
    }

    .topbar {
      height: 64px;
      background: #fff;
      border-bottom: 1px solid var(--sg-border);
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding: 0 32px;
      box-shadow: var(--sg-shadow-sm);
      /* Sticky positioning keeps topbar visible while scrolling - intentional for better navigation */
      position: sticky;
      top: 0;
      z-index: 100;
    }
    .topbar .brand {
      font-weight: 700;
      font-size: 18px;
      display: flex;
      align-items: center;
      gap: 10px;
      color: var(--sg-text);
    }
    .topbar .brand-dot {
      width: 12px;
      height: 12px;
      border-radius: 50%;
      background: linear-gradient(135deg, var(--sg-blue) 0%, var(--sg-blue-dark) 100%);
      box-shadow: 0 0 0 3px rgba(26,130,226,0.2);
      animation: pulse-dot 2s ease-in-out infinite;
    }
    @keyframes pulse-dot {
      0%, 100% {
        transform: scale(1);
      }
      50% {
        transform: scale(1.1);
      }
    }
    .topbar-actions {
      display: flex;
      align-items: center;
      gap: 8px;
    }
    .btn {
      border-radius: 6px;
      border: 1px solid transparent;
      padding: 8px 16px;
      font-size: 14px;
      font-weight: 500;
      cursor: pointer;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 6px;
      background: #fff;
      color: var(--sg-text);
      transition: all 0.2s ease;
      text-decoration: none;
    }
    .btn:hover {
      text-decoration: none;
      transform: translateY(-1px);
      box-shadow: var(--sg-shadow-sm);
    }
    .btn-primary {
      background: var(--sg-blue);
      color: #fff;
      border-color: var(--sg-blue);
      box-shadow: var(--sg-shadow-sm);
    }
    .btn-primary:hover {
      background: var(--sg-blue-dark);
      border-color: var(--sg-blue-dark);
      box-shadow: var(--sg-shadow-md);
    }
    .btn-outline {
      background: #fff;
      border-color: var(--sg-border);
      color: var(--sg-text);
    }
    .btn-outline:hover {
      border-color: var(--sg-blue);
      color: var(--sg-blue);
      background: var(--sg-blue-light);
    }
    .btn-danger {
      background: var(--sg-danger);
      border-color: var(--sg-danger);
      color: #fff;
      box-shadow: var(--sg-shadow-sm);
    }
    .btn-danger:hover {
      background: #E03131;
      border-color: #E03131;
      box-shadow: var(--sg-shadow-md);
    }

    .main-content {
      padding: 32px 40px 40px;
      flex: 1;
      overflow-y: auto;
      max-width: 1400px;
      margin: 0 auto;
      width: 100%;
    }

    .page-title {
      font-size: 28px;
      font-weight: 700;
      margin-bottom: 8px;
      color: var(--sg-text);
      letter-spacing: -0.02em;
    }
    .page-subtitle {
      font-size: 14px;
      color: var(--sg-muted);
      margin-bottom: 24px;
      line-height: 1.5;
    }

    .card {
      background: #fff;
      border-radius: 8px;
      border: 1px solid var(--sg-border);
      padding: 20px 24px;
      margin-bottom: 20px;
      box-shadow: var(--sg-shadow-sm);
      transition: box-shadow 0.2s ease, transform 0.2s ease;
    }
    .card:hover {
      box-shadow: var(--sg-shadow-md);
    }
    .card-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 16px;
      padding-bottom: 12px;
      border-bottom: 1px solid var(--sg-border);
    }
    .card-title {
      font-weight: 600;
      font-size: 16px;
      color: var(--sg-text);
    }
    .card-subtitle {
      font-size: 13px;
      color: var(--sg-muted);
      margin-top: 4px;
      line-height: 1.4;
    }

    .table {
      width: 100%;
      border-collapse: collapse;
      font-size: 14px;
    }
    .table th, .table td {
      padding: 14px 12px;
      border-bottom: 1px solid var(--sg-border);
      text-align: left;
    }
    .table th {
      font-size: 12px;
      text-transform: uppercase;
      letter-spacing: 0.05em;
      color: var(--sg-muted);
      font-weight: 600;
      background-color: var(--sg-bg);
    }
    .table tbody tr {
      transition: background-color 0.15s ease;
    }
    .table tbody tr:hover {
      background-color: var(--sg-bg);
    }
    .table tbody tr:last-child td {
      border-bottom: none;
    }
    .status-dot {
      width: 10px;
      height: 10px;
      border-radius: 50%;
      display: inline-block;
      margin-right: 8px;
    }
    @keyframes pulse {
      0%, 100% {
        opacity: 1;
      }
      50% {
        opacity: .7;
      }
    }
    .status-dot.status-draft { 
      background: #CED4DA; /* Grey */
    }
    .status-dot.status-queued { 
      background: #FD7E14; /* Orange */
      animation: pulse 2s cubic-bezier(0.4, 0, 0.6, 1) infinite;
    }
    .status-dot.status-sending { 
      background: #FCC419; /* Yellow */
      animation: pulse 2s cubic-bezier(0.4, 0, 0.6, 1) infinite;
    }
    .status-dot.status-sent { 
      background: var(--sg-success); /* Green */
    }

    .badge {
      display: inline-flex;
      align-items: center;
      padding: 4px 10px;
      border-radius: 999px;
      font-size: 12px;
      font-weight: 500;
      background: var(--sg-blue-light);
      color: var(--sg-blue-dark);
      border: 1px solid var(--sg-blue);
    }

    .form-row {
      display: flex;
      gap: 12px;
      margin-bottom: 12px;
    }
    .form-group {
      margin-bottom: 12px;
      flex: 1;
    }
    .form-group label {
      font-size: 12px;
      font-weight: 600;
      display: block;
      margin-bottom: 4px;
    }
    .form-group small {
      display: block;
      font-size: 11px;
      color: var(--sg-muted);
    }
    input[type="text"],
    input[type="email"],
    input[type="number"],
    input[type="password"],
    textarea,
    select {
      width: 100%;
      padding: 10px 12px;
      font-size: 14px;
      border-radius: 6px;
      border: 1px solid var(--sg-border);
      outline: none;
      transition: all 0.2s ease;
      background: #fff;
      color: var(--sg-text);
    }
    input:focus, textarea:focus, select:focus {
      border-color: var(--sg-blue);
      box-shadow: 0 0 0 3px rgba(26,130,226,0.1);
      background: #fff;
    }
    input:hover, textarea:hover, select:hover {
      border-color: var(--sg-blue);
    }
    textarea {
      min-height: 160px;
      resize: vertical;
      font-family: inherit;
    }
    input::placeholder,
    textarea::placeholder {
      color: var(--sg-muted);
    }

    .sidebar-header {
      padding: 14px 16px;
      border-bottom: 1px solid var(--sg-border);
      display: flex;
      align-items: center;
      justify-content: space-between;
    }
    .sidebar-header-title {
      font-weight: 600;
      font-size: 14px;
    }

    .sidebar-body {
      padding: 14px 16px;
      overflow-y: auto;
      flex: 1;
    }
    .sidebar-section-title {
      font-size: 12px;
      font-weight: 600;
      margin-bottom: 6px;
      color: var(--sg-muted);
      text-transform: uppercase;
      letter-spacing: .05em;
    }
    .sidebar-foot {
      padding: 10px 16px 14px;
      border-top: 1px solid var(--sg-border);
    }

    .sp-rotation-card {
      border-radius: 6px;
      border: 1px solid var(--sg-border);
      padding: 10px 10px 12px;
      background: var(--sg-bg);
      margin-bottom: 14px;
    }
    .checkbox-row {
      display: flex;
      align-items: center;
      gap: 8px;
      margin-bottom: 8px;
      font-size: 13px;
    }
    .radio-row {
      display: flex;
      gap: 12px;
      margin-bottom: 8px;
      font-size: 13px;
    }

    .profiles-list {
      display: flex;
      flex-direction: column;
      gap: 8px;
      margin-bottom: 12px;
    }
    .profile-card {
      border-radius: 8px;
      border: 1px solid var(--sg-border);
      padding: 14px 16px;
      font-size: 13px;
      background: #fff;
      transition: all 0.2s ease;
      box-shadow: var(--sg-shadow-sm);
    }
    .profile-card:hover {
      border-color: var(--sg-blue);
      box-shadow: var(--sg-shadow-md);
      transform: translateY(-2px);
    }
    .profile-card-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 4px;
    }
    .profile-name {
      font-weight: 600;
      font-size: 13px;
    }
    .profile-meta {
      font-size: 11px;
      color: var(--sg-muted);
      display: flex;
      flex-wrap: wrap;
      gap: 8px;
    }
    .profile-actions {
      display: flex;
      gap: 6px;
      margin-top: 4px;
    }
    .btn-mini {
      font-size: 11px;
      padding: 2px 6px;
      border-radius: 4px;
      border: 1px solid var(--sg-border);
      background: #fff;
      cursor: pointer;
    }

    .sp-form {
      border-radius: 6px;
      border: 1px solid var(--sg-border);
      padding: 10px 10px 12px;
      margin-bottom: 10px;
    }
    .hint {
      font-size: 11px;
      color: var(--sg-muted);
    }
    .pill {
      display: inline-flex;
      align-items: center;
      padding: 2px 6px;
      border-radius: 999px;
      font-size: 11px;
      background: #EFF3F9;
      color: #4B5563;
    }

    /* Header stats (new simplified look) */
    .header-stats {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
      gap: 20px;
      background: #fff;
      border: 1px solid var(--sg-border);
      border-radius: 8px;
      padding: 24px;
      margin-bottom: 24px;
      box-shadow: var(--sg-shadow-sm);
    }
    .stat-item {
      text-align: center;
      padding: 12px;
      border-radius: 6px;
      transition: background-color 0.2s ease, transform 0.2s ease;
    }
    .stat-item:hover {
      background-color: var(--sg-bg);
      transform: translateY(-2px);
    }
    .stat-item .stat-num {
      font-size: 32px;
      font-weight: 700;
      color: var(--sg-blue-dark);
      line-height: 1;
      margin-bottom: 8px;
    }
    .stat-item .stat-label {
      font-size: 12px;
      color: var(--sg-muted);
      text-transform: uppercase;
      letter-spacing: .05em;
      font-weight: 600;
    }

    .stats-grid {
      display: grid;
      grid-template-columns: repeat(4, minmax(0, 1fr));
      gap: 12px;
      margin-bottom: 12px;
    }
    .stat-box {
      background: #fff;
      border-radius: 6px;
      border: 1px solid var(--sg-border);
      padding: 10px 12px;
    }
    .stat-label {
      font-size: 11px;
      text-transform: uppercase;
      color: var(--sg-muted);
      margin-bottom: 4px;
    }
    .stat-value {
      font-size: 18px;
      font-weight: 600;
    }
    .stat-sub {
      font-size: 11px;
      color: var(--sg-muted);
    }

    /* Contacts modal */
    .modal-backdrop {
      position: fixed;
      inset: 0;
      background: rgba(15,23,42,0.5);
      display: flex;
      justify-content: flex-end;
      z-index: 40;
      animation: fadeIn 0.2s ease;
    }
    @supports (backdrop-filter: blur(4px)) {
      .modal-backdrop {
        backdrop-filter: blur(4px);
      }
    }
    @keyframes fadeIn {
      from {
        opacity: 0;
      }
      to {
        opacity: 1;
      }
    }
    .modal-panel {
      width: 480px;
      max-width: 100%;
      background: #fff;
      padding: 28px 32px;
      box-shadow: var(--sg-shadow-lg);
      display: flex;
      flex-direction: column;
      animation: slideInRight 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    }
    @keyframes slideInRight {
      from {
        transform: translateX(100%);
      }
      to {
        transform: translateX(0);
      }
    }

    /* HTML Editor modal (fixed center) */
    .html-editor-backdrop {
      display: none; /* hidden initially */
      position: fixed;
      inset: 0;
      background: rgba(15,23,42,0.6);
      display: none;
      align-items: center;
      justify-content: center;
      z-index: 80;
      animation: fadeIn 0.2s ease;
    }
    @supports (backdrop-filter: blur(4px)) {
      .html-editor-backdrop {
        backdrop-filter: blur(4px);
      }
    }
    .html-editor-backdrop.show {
      display: flex;
    }
    .html-editor-panel {
      width: 900px;
      max-width: calc(100% - 40px);
      max-height: 85vh;
      overflow: auto;
      background: #fff;
      padding: 24px;
      border-radius: 12px;
      box-shadow: var(--sg-shadow-lg);
      display: flex;
      flex-direction: column;
      gap: 12px;
      animation: scaleIn 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    }
    @keyframes scaleIn {
      from {
        transform: scale(0.95);
        opacity: 0;
      }
      to {
        transform: scale(1);
        opacity: 1;
      }
    }
    .html-editor-panel textarea {
      min-height: 420px;
      width: 100%;
      padding: 12px;
      font-family: monospace;
      font-size: 13px;
      border: 1px solid #e6edf3;
      border-radius: 6px;
      resize: vertical;
    }
    .html-editor-actions {
      display: flex;
      gap: 8px;
      justify-content: flex-end;
    }

    .toast {
      position: fixed;
      right: 24px;
      top: 24px;
      z-index: 200;
      padding: 14px 18px;
      border-radius: 8px;
      color: #fff;
      font-weight: 600;
      font-size: 14px;
      box-shadow: var(--sg-shadow-lg);
      display: none;
      opacity: 0;
      transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
      transform: translateY(-10px);
    }
    .toast.show { 
      display: block; 
      opacity: 1;
      transform: translateY(0);
    }
    .toast.success { 
      background: var(--sg-success);
      border: 1px solid var(--sg-success);
    }
    .toast.error { 
      background: var(--sg-danger);
      border: 1px solid var(--sg-danger);
    }

    /* ========== REVIEW PAGE ENHANCEMENTS ========== */
    /* Make the Review page resemble SendGrid layout: left details, right preview (wider preview) */
    
    /* Status indicators for concurrent mode */
    .concurrent-badge {
      display: inline-block;
      padding: 2px 6px;
      font-size: 10px;
      font-weight: 600;
      background: #4CAF50;
      color: white;
      border-radius: 3px;
      margin-left: 8px;
      text-transform: uppercase;
    }
    .concurrent-badge.sequential {
      background: #9e9e9e;
    }
    
    .review-grid {
      display: grid;
      grid-template-columns: 1fr 680px; /* bigger preview panel */
      gap: 18px;
      align-items: start;
    }
    .review-summary {
      background: #fff;
      border-radius: 6px;
      border: 1px solid var(--sg-border);
      padding: 16px;
    }
    .review-row {
      display: flex;
      justify-content: space-between;
      padding: 10px 6px;
      border-bottom: 1px solid #f1f5f9;
    }
    .review-row .left { color: var(--sg-muted); font-weight:600; width:40%; }
    .review-row .right { width:58%; text-align:right; color:var(--sg-text); }

    .test-send-card {
      margin-top: 14px;
      background: #fff;
      border-radius: 6px;
      border: 1px solid var(--sg-border);
      padding: 14px;
    }
    .test-send-card small.hint { display:block; margin-top:8px; color:var(--sg-muted); }

    .preview-box {
      border:1px solid var(--sg-border);
      border-radius:6px;
      background:#fff;
      padding:10px;
      min-height:640px; /* taller preview */
      overflow:auto;
    }

    /*
      ============================
      NEW / FIXED STYLES FOR EDITOR
      These styles make the left "modules" area show tiles (like Image 2)
      and style the canvas placeholder and blocks for a nicer visual layout.
      ============================
    */
    .editor-shell {
      display: flex;
      gap: 18px;
      align-items: flex-start;
    }
    .editor-left {
      width: 320px; /* make left sidebar a fixed column like SendGrid */
      min-width: 260px;
      max-width: 360px;
      background: transparent;
      padding: 12px;
    }

    /* Modules grid: tiles with icons */
    .modules-grid {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 12px;
      align-items: start;
      margin-bottom: 10px;
    }
    .module-tile {
      background: #fff;
      border: 1px solid var(--sg-border);
      border-radius: 6px;
      padding: 18px 12px;
      text-align: center;
      cursor: pointer;
      color: var(--sg-muted);
      display: flex;
      flex-direction: column;
      align-items: center;
      gap: 8px;
      min-height: 84px;
      transition: box-shadow .15s ease, border-color .15s ease, transform .08s ease;
    }
    .module-tile .icon {
      font-size: 22px;
      color: var(--sg-blue);
      line-height: 1;
    }
    .module-tile:hover {
      border-color: var(--sg-blue);
      box-shadow: 0 6px 16px rgba(26,130,226,0.08);
      color: var(--sg-text);
      transform: translateY(-2px);
    }
    .modules-pane .sidebar-section-title {
      margin-top: 6px;
      margin-bottom: 12px;
    }

    /* Canvas and placeholder */
    .editor-right {
      flex: 1;
      display: flex;
      flex-direction: column;
      gap: 12px;
      padding-left: 12px;
    }
    .canvas-area {
      border-radius: 6px;
      border: 1px solid var(--sg-border);
      background: #fff;
      min-height: 420px;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 30px;
      position: relative;
      overflow: auto;
    }
    .drag-placeholder {
      background: #FBFCFD;
      border: 1px dashed #EEF2F6;
      padding: 60px 40px;
      color: #9AA6B2;
      border-radius: 6px;
      width: 70%;
      text-align: center;
      font-size: 15px;
    }

    /* Canvas block wrapper */
    .canvas-block {
      background: #fff;
      border: 1px solid #f1f5f9;
      border-radius: 6px;
      padding: 12px;
      margin-bottom: 12px;
      width: 100%;
      position: relative;
      box-shadow: none;
    }
    .canvas-block + .canvas-block {
      margin-top: 12px;
    }
    .block-remove {
      position: absolute;
      top: 8px;
      right: 8px;
      background: #fff;
      border: 1px solid var(--sg-border);
      border-radius: 4px;
      width: 28px;
      height: 28px;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      cursor: pointer;
      font-size: 14px;
      color: #666;
    }
    .block-remove:hover {
      background: #fff;
      border-color: var(--sg-blue);
      color: var(--sg-blue-dark);
    }
    .block-content {
      min-height: 40px;
    }

    /* Code module visual improvements */
    .code-module {
      border: 1px dashed #eef2f6;
      border-radius: 6px;
      overflow: hidden;
    }
    .code-module-header {
      background: #1f6f9f;
      color: #fff;
      padding: 8px 10px;
      display: flex;
      justify-content: space-between;
      align-items: center;
    }
    .code-module-body {
      padding: 12px;
      background: #fff;
      color: #333;
    }
    .code-placeholder {
      color: #9aa6b2;
      font-style: normal;
    }

    /* Responsive design improvements */
    @media (max-width: 1400px) {
      .review-grid { grid-template-columns: 1fr 520px; }
      .main-content {
        padding: 28px 32px 32px;
      }
    }

    @media (max-width: 1200px) {
      .editor-left { width: 300px; height: auto; }
      .drag-placeholder { width: 100%; }
      .canvas-block, .drag-placeholder { width: 100%; }
      .modules-grid { grid-template-columns: repeat(1, minmax(0,1fr)); }
      .header-stats {
        grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
        gap: 16px;
        padding: 20px;
      }
    }

    @media (max-width: 900px) {
      .form-row {
        flex-direction: column;
      }
      .stats-grid {
        grid-template-columns: repeat(2, minmax(0, 1fr));
      }
      .header-stats {
        grid-template-columns: repeat(2, 1fr);
        gap: 12px;
        padding: 16px;
      }
      .stat-item .stat-num {
        font-size: 24px;
      }
      .nav-main {
        display: none;
      }
      .sidebar-inner {
        left: 0;
        width: 100%;
      }
      .sidebar-sp.open {
        width: 100%;
      }
      .page-wrapper.shifted {
        transform: none;
      }
      .editor-shell {
        flex-direction: column;
      }
      .editor-left, .editor-right { width: 100%; }
      .review-grid { grid-template-columns: 1fr; }
      .preview-box { min-height:420px; }
      .main-content {
        padding: 20px 16px 24px;
      }
      .page-title {
        font-size: 24px;
      }
      .card {
        padding: 16px 18px;
      }
    }
    
    @media (max-width: 600px) {
      .header-stats {
        grid-template-columns: 1fr;
      }
      .card-header .btn {
        width: 100%;
        justify-content: center;
      }
      .card-header {
        flex-direction: column;
        align-items: flex-start;
        gap: 12px;
      }
      .page-title {
        font-size: 20px;
      }
    }
  </style>
</head>
<body>
<div class="app-shell">

  <!-- LEFT NAV (SendGrid-style) -->
  <aside class="nav-main">
    <div class="nav-header">
      <div class="nav-avatar"><?php echo strtoupper(substr($_SESSION['admin_username'] ?? 'A', 0, 1)); ?></div>
      <div class="nav-user-info">
        <div class="nav-user-email"><?php echo htmlspecialchars($_SESSION['admin_username'] ?? 'Admin'); ?></div>
        <div class="nav-user-sub">EMAIL EXTRACTOR</div>
      </div>
    </div>
    <div class="nav-scroll">
      <div class="nav-section-title">Email Extraction</div>

      <div class="nav-section-title">Jobs</div>
      <a href="?page=list" class="nav-link <?php echo $isSingleSendsPage ? 'active' : ''; ?>">
        <span>Extraction Jobs</span>
      </a>
      <a href="#" class="nav-link"><span>Export Data</span></a>

      <div class="nav-section-title">Analytics</div>
      <a href="?page=stats" class="nav-link"><span>Stats</span></a>
    </div>
    <div class="nav-footer">
      <a href="?action=logout" style="color: var(--sg-danger); display: block; padding: 8px 0; font-weight: 600;">🚪 Logout</a>
      EMAIL EXTRACTOR v1.0<br>Professional Email Extraction
    </div>
  </aside>

  <!-- LEFT SLIDE PANEL: Job Profiles -->
  <div class="sidebar-sp" id="spSidebar">
    <div class="sidebar-inner">
      <div class="sidebar-header">
        <div class="sidebar-header-title">Job Profiles</div>
        <button class="btn btn-outline" type="button" id="spCloseBtn">✕</button>
      </div>
      <div class="sidebar-body">
        <!-- Rotation settings -->
        <form method="post" class="sp-rotation-card">
          <input type="hidden" name="action" value="save_rotation">
          <div class="sidebar-section-title">Extraction Settings</div>
          <div class="checkbox-row">
            <input type="checkbox" name="rotation_enabled" id="rot_enabled" <?php if ($rotationSettings['rotation_enabled']) echo 'checked'; ?>>
            <label for="rot_enabled">Enable Profile Rotation</label>
          </div>
          <div class="form-group">
            <label>Workers</label>
            <input type="number" name="workers" min="<?php echo MIN_WORKERS; ?>" value="<?php echo (int)($rotationSettings['workers'] ?? DEFAULT_WORKERS); ?>">
            <small class="hint">⚠️ NO LIMIT - accepts ANY number (1, 100, 500, 1000+). Minimum: <?php echo MIN_WORKERS; ?>. Recommended: 4-10 for typical volume, 10-50 for high volume.</small>
          </div>
          <div class="form-group">
            <label>Emails Per Worker</label>
            <input type="number" name="emails_per_worker" min="<?php echo MIN_EMAILS_PER_WORKER; ?>" max="<?php echo MAX_EMAILS_PER_WORKER; ?>" value="<?php echo (int)($rotationSettings['emails_per_worker'] ?? DEFAULT_EMAILS_PER_WORKER); ?>">
            <small class="hint">Number of emails each worker extracts (<?php echo MIN_EMAILS_PER_WORKER; ?>-<?php echo MAX_EMAILS_PER_WORKER; ?>, default: <?php echo DEFAULT_EMAILS_PER_WORKER; ?>). Controls distribution granularity across workers.</small>
          </div>
          <div class="form-group">
            <small class="hint" style="display:block; margin-top:8px;">
              When enabled, jobs will rotate through all active extraction profiles. 
              Configure individual profiles with Workers to control parallel extraction speed.
              <?php if (function_exists('pcntl_fork')): ?>
                <br><strong style="color:#4CAF50;">✓ Parallel Mode Available:</strong> Multiple workers will extract in parallel for maximum speed.
              <?php else: ?>
                <br><strong style="color:#ff9800;">⚠ Sequential Mode:</strong> PHP pcntl extension not available. Workers will process sequentially.
              <?php endif; ?>
            </small>
          </div>
          <button class="btn btn-primary" type="submit">Save Settings</button>
        </form>

        <!-- Profiles list -->
        <div class="profiles-list">
          <div class="sidebar-section-title">Extraction Profiles</div>
          <?php if (empty($profiles)): ?>
            <div class="hint">No profiles yet. Create your first extraction profile below.</div>
          <?php else: ?>
            <?php foreach ($profiles as $p): ?>
              <div class="profile-card" id="profile-card-<?php echo (int)$p['id']; ?>">
                <div class="profile-card-header">
                  <div class="profile-name"><?php echo h($p['profile_name']); ?></div>
                  <div class="pill">SERPER.DEV</div>
                </div>
                <div class="profile-meta">
                  <span>Query: <?php echo h(substr($p['search_query'] ?? '', 0, 50)); ?><?php echo strlen($p['search_query'] ?? '') > 50 ? '...' : ''; ?></span>
                  <span>Target: <?php echo (int)($p['target_count'] ?? 100); ?> emails</span>
                  <span>Status: <?php echo $p['active'] ? 'Active' : 'Disabled'; ?></span>
                  <?php if (!empty($p['workers'])): ?>
                    <span>Workers: <?php echo (int)$p['workers']; ?></span>
                  <?php endif; ?>
                </div>
                <div class="profile-actions">
                  <button type="button" class="btn-mini" onclick="testProfileConnection(<?php echo (int)$p['id']; ?>)">🔌 Test</button>
                  <a href="?page=list&edit_profile=<?php echo (int)$p['id']; ?>" class="btn-mini">Edit</a>
                  <form method="post" style="display:inline;">
                    <input type="hidden" name="action" value="delete_profile">
                    <input type="hidden" name="profile_id" value="<?php echo (int)$p['id']; ?>">
                    <button type="submit" class="btn-mini" onclick="return confirm('Delete this profile?');">Delete</button>
                  </form>
                </div>
                <div style="margin-top:8px; font-size:12px; color:var(--sg-muted);" id="profile-conn-status-<?php echo (int)$p['id']; ?>"></div>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>

        <!-- Add / Edit profile -->
        <div class="sidebar-section-title">
          <?php echo $editProfile ? 'Edit Profile' : 'Add New Profile'; ?>
        </div>
        <form method="post" class="sp-form" id="profileForm">
          <input type="hidden" name="action" value="save_profile">
          <input type="hidden" name="profile_id" value="<?php echo $editProfile ? (int)$editProfile['id'] : 0; ?>">
          <div class="form-group">
            <label>Profile Name</label>
            <input type="text" name="profile_name" required value="<?php echo $editProfile ? h($editProfile['profile_name']) : ''; ?>">
          </div>
          <div class="form-group">
            <label>Serper.dev API Key</label>
            <input type="text" name="api_key" id="profile_api_key" required value="<?php echo $editProfile ? h($editProfile['api_key']) : ''; ?>">
            <small class="hint">Your Serper.dev API key for email extraction</small>
          </div>
          <div class="form-group">
            <label>Search Query</label>
            <textarea name="search_query" id="profile_search_query" rows="3" required><?php echo $editProfile ? h($editProfile['search_query']) : ''; ?></textarea>
            <small class="hint">e.g., "real estate agents california"</small>
          </div>
          
          <!-- Test Connection Button & Result -->
          <div class="form-group">
            <button type="button" class="btn btn-outline" id="testConnectionBtn" style="width:100%;">
              🔌 Test Connection
            </button>
            <div id="connectionTestResult" style="margin-top:10px; display:none; padding:12px; border-radius:4px; font-size:13px;"></div>
          </div>
          <div class="form-group">
            <label>Target Email Count</label>
            <input type="number" name="target_count" min="1" max="10000" value="<?php echo $editProfile ? (int)$editProfile['target_count'] : 100; ?>">
            <small class="hint">Number of emails to extract (1-10000)</small>
          </div>
          <div class="form-group">
            <label>Country (optional)</label>
            <input type="text" name="country" placeholder="us" value="<?php echo $editProfile ? h($editProfile['country']) : ''; ?>">
            <small class="hint">ISO country code (e.g., us, uk, ca)</small>
          </div>
          <div class="checkbox-row">
            <input type="checkbox" name="filter_business_only" id="pf_business" <?php if (!$editProfile || $editProfile['filter_business_only']) echo 'checked'; ?>>
            <label for="pf_business">Business Emails Only (filter out free providers)</label>
          </div>
          <div class="form-group">
            <label>Workers</label>
            <input type="number" name="workers" min="<?php echo MIN_WORKERS; ?>" value="<?php echo $editProfile ? (int)$editProfile['workers'] : DEFAULT_WORKERS; ?>">
            <small class="hint">Number of parallel extraction workers</small>
          </div>
          <div class="form-group">
            <label>Emails Per Worker</label>
            <input type="number" name="emails_per_worker" min="<?php echo MIN_EMAILS_PER_WORKER; ?>" max="<?php echo MAX_EMAILS_PER_WORKER; ?>" value="<?php echo $editProfile ? (int)$editProfile['emails_per_worker'] : DEFAULT_EMAILS_PER_WORKER; ?>">
            <small class="hint">Emails per worker cycle</small>
          </div>
          <div class="checkbox-row">
            <input type="checkbox" name="active" id="pf_active" <?php if (!$editProfile || $editProfile['active']) echo 'checked'; ?>>
            <label for="pf_active">Active</label>
          </div>
          <div style="display:flex; gap:8px; justify-content:flex-end;">
            <?php if ($editProfile): ?>
              <a href="?page=list" class="btn btn-outline">Cancel</a>
            <?php endif; ?>
            <button class="btn btn-primary" type="submit"><?php echo $editProfile ? 'Update' : 'Create'; ?> Profile</button>
          </div>
        </form>
      </div>
    </div>
  </div>

  <!-- MAIN PAGE -->
  <div class="page-wrapper" id="pageWrapper">
    <div class="topbar">
      <div class="brand">
        <img src="mafia-single-sends-logo.png" alt="Logo" style="width:250px;height:100px;">
      </div>
      <div class="topbar-actions">
        <?php if ($page === 'list'): ?>
          <button class="btn btn-outline" id="spOpenBtn" type="button">Job Profiles ⚙</button>
        <?php elseif ($page === 'contacts'): ?>
          <a href="?page=list" class="btn btn-outline">Extraction Jobs</a>
        <?php else: ?>
          <a href="?page=list" class="btn btn-outline">Extraction Jobs</a>
        <?php endif; ?>
      </div>
    </div>

    <div class="main-content">
      <?php if ($page === 'list'): ?>
        <?php
          // Check if PDO is available
          if ($pdo === null) {
              echo '<div class="page-title">Database Error</div>';
              echo '<div class="card"><div style="padding: 20px;">Database connection not available. Please check your configuration.</div></div>';
          } else {
              $stmt = $pdo->query("SELECT * FROM jobs ORDER BY created_at DESC");
              $campaigns = $stmt->fetchAll(PDO::FETCH_ASSOC);
        ?>
        <div class="page-title">Extraction Jobs</div>
        <div class="page-subtitle">Create and manage email extraction jobs using serper.dev API.</div>

        <div class="card">
          <div class="card-header">
            <div>
              <div class="card-title">Extraction Jobs</div>
              <div class="card-subtitle">Create and manage email extraction jobs.</div>
            </div>
            <form method="post" style="display:flex; gap:8px; align-items:center;">
              <input type="hidden" name="action" value="create_job">
              <input type="text" name="name" placeholder="Job name" style="font-size:13px; padding:6px 8px;">
              <button type="submit" class="btn btn-primary">+ Create Extraction Job</button>
            </form>
          </div>

          <!-- Bulk actions toolbar -->
          <div style="display:flex; gap:8px; align-items:center; margin-bottom:8px;">
            <form method="post" id="bulkActionsForm" style="display:flex; gap:8px; align-items:center;">
              <input type="hidden" name="action" value="bulk_jobs">
              <select name="bulk_action" id="bulkActionSelect" style="padding:6px;">
                <option value="">Bulk actions</option>
                <option value="delete_selected">Delete selected</option>
                <option value="duplicate_selected">Duplicate selected</option>
              </select>
              <button type="submit" class="btn btn-outline">Apply</button>
            </form>
          </div>

          <form method="post" id="jobsTableForm">
            <input type="hidden" name="action" value="bulk_jobs">
            <table class="table">
              <thead>
                <tr>
                  <th style="width:36px;"><input type="checkbox" id="chkAll"></th>
                  <th>Job Name</th>
                  <th>Status</th>
                  <th>Profile</th>
                  <th>Extracted</th>
                  <th>Target</th>
                  <th>Progress</th>
                  <th>Created</th>
                  <th>Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php if (empty($campaigns)): ?>
                  <tr>
                    <td colspan="9" style="text-align:center; padding:20px; color:var(--sg-muted);">
                      No jobs yet. Create your first extraction job above.
                    </td>
                  </tr>
                <?php else: ?>
                  <?php foreach ($campaigns as $c):
                    $stats = get_job_stats($pdo, (int)$c['id']);
                    $status = $c['status'];
                    // Support statuses: Grey (draft) → Orange (queued) → Yellow (extracting) → Green (completed)
                    if ($status === 'completed' || $status === 'sent') {
                        $dotClass = 'status-sent';
                        $statusLabel = 'Completed';
                    } elseif ($status === 'extracting' || $status === 'sending') {
                        $dotClass = 'status-sending';
                        $statusLabel = 'Extracting';
                    } elseif ($status === 'queued') {
                        $dotClass = 'status-queued';
                        $statusLabel = 'Queued';
                    } else {
                        $dotClass = 'status-draft';
                        $statusLabel = 'Draft';
                    }
                    $link = ($status === 'completed' || $status === 'sent' || $status === 'extracting' || $status === 'sending' || $status === 'queued') ? '?page=stats&id='.$c['id'] : '?page=editor&id='.$c['id'];
                    
                    $profileName = 'N/A';
                    if (!empty($c['profile_id'])) {
                        foreach ($profiles as $p) {
                            if ($p['id'] == $c['profile_id']) {
                                $profileName = $p['profile_name'];
                                break;
                            }
                        }
                    }
                    
                    $extracted = (int)($c['progress_extracted'] ?? 0);
                    $target = (int)($c['progress_total'] ?? $c['target_count'] ?? 0);
                    $progress = $target > 0 ? round(($extracted / $target) * 100) : 0;
                  ?>
                    <tr data-cid="<?php echo (int)$c['id']; ?>">
                      <td><input type="checkbox" name="job_ids[]" value="<?php echo (int)$c['id']; ?>" class="job-checkbox"></td>
                      <td>
                        <a href="<?php echo $link; ?>">
                          <span class="status-dot <?php echo $dotClass; ?>"></span>
                          <?php echo h($c['name']); ?>
                        </a>
                      </td>
                      <td><?php echo ucfirst($status); ?>
                        <?php if ($status === 'sending' || $status === 'queued' || $status === 'extracting'): ?>
                          <div id="progress-bar-<?php echo (int)$c['id']; ?>" style="margin-top:4px;">
                            <div style="background:#eee; height:4px; border-radius:2px; overflow:hidden;">
                              <div id="progress-fill-<?php echo (int)$c['id']; ?>" style="background:#4CAF50; height:100%; width:<?php echo $progress; ?>%; transition:width 0.3s;"></div>
                            </div>
                            <div id="progress-text-<?php echo (int)$c['id']; ?>" style="font-size:11px; color:var(--sg-muted); margin-top:2px;">
                              <?php echo $progress; ?>% • <?php echo $extracted; ?>/<?php echo $target; ?>
                            </div>
                          </div>
                        <?php endif; ?>
                      </td>
                      <td><?php echo h($profileName); ?></td>
                      <td><span id="extracted-<?php echo (int)$c['id']; ?>"><?php echo $extracted; ?></span></td>
                      <td><?php echo $target; ?></td>
                      <td><?php echo $progress; ?>%</td>
                      <td><?php echo h($c['created_at'] ?? ''); ?></td>
                      <td>
                        <a class="btn-mini" href="<?php echo $link; ?>">Open</a>
                        <form method="post" style="display:inline;">
                          <input type="hidden" name="action" value="duplicate_job">
                          <input type="hidden" name="job_id" value="<?php echo (int)$c['id']; ?>">
                          <button type="submit" class="btn-mini">Duplicate</button>
                        </form>
                        <form method="post" style="display:inline;">
                          <input type="hidden" name="action" value="delete_job">
                          <input type="hidden" name="job_id" value="<?php echo (int)$c['id']; ?>">
                          <button type="submit" class="btn-mini" onclick="return confirm('Delete this job and its extracted emails?');">Delete</button>
                        </form>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </form>
        </div>

        <script>
          (function(){
            // If URL contains sent_campaign=<id>, start polling its stats to show incremental delivered updates
            function getQueryParam(name) {
              var params = new URLSearchParams(window.location.search);
              return params.get(name);
            }
            var sentCampaign = getQueryParam('sent_campaign');
            if (sentCampaign) {
              var cid = sentCampaign;
              var interval = 2000;
              var timer = setInterval(function(){
                fetch(window.location.pathname + '?ajax=campaign_stats&id=' + encodeURIComponent(cid))
                  .then(function(r){ return r.json(); })
                  .then(function(data){
                    if (!data) return;
                    var d = document.getElementById('delivered-' + cid);
                    var o = document.getElementById('open-' + cid);
                    var c = document.getElementById('click-' + cid);
                    if (d) d.innerText = data.delivered || 0;
                    if (o) o.innerText = data.open || 0;
                    if (c) c.innerText = data.click || 0;
                  }).catch(function(){});
              }, interval);
              // stop polling after 10 minutes as safety
              setTimeout(function(){ clearInterval(timer); }, 10 * 60 * 1000);
            }

            // Real-time progress polling for sending/queued campaigns
            var sendingCampaigns = document.querySelectorAll('[data-cid]');
            var progressPollers = {};
            
            sendingCampaigns.forEach(function(row){
              var cid = row.getAttribute('data-cid');
              var statusDot = row.querySelector('.status-dot');
              
              if (statusDot && (statusDot.classList.contains('status-sending') || statusDot.classList.contains('status-queued'))) {
                // Start polling progress for this campaign
                progressPollers[cid] = setInterval(function(){
                  var fd = new FormData();
                  fd.append('action', 'get_campaign_progress');
                  fd.append('campaign_id', cid);
                  
                  fetch(window.location.pathname, {
                    method: 'POST',
                    body: fd,
                    credentials: 'same-origin'
                  })
                  .then(function(r){ return r.json(); })
                  .then(function(data){
                    if (!data || !data.ok) return;
                    
                    // Update progress bar
                    var fillEl = document.getElementById('progress-fill-' + cid);
                    var textEl = document.getElementById('progress-text-' + cid);
                    var deliveredEl = document.getElementById('delivered-' + cid);
                    var bounceEl = document.getElementById('bounce-' + cid);
                    
                    if (fillEl) {
                      fillEl.style.width = data.percentage + '%';
                    }
                    if (textEl) {
                      var workersText = data.active_workers + ' worker' + (data.active_workers !== 1 ? 's' : '');
                      textEl.innerText = data.percentage + '% • ' + data.total_processed + '/' + data.total_recipients + ' • ' + workersText;
                    }
                    if (deliveredEl) {
                      deliveredEl.innerText = data.delivered || 0;
                    }
                    if (bounceEl) {
                      bounceEl.innerText = data.bounced || 0;
                    }
                    
                    // If campaign finished, stop polling and reload page
                    // Check multiple conditions to ensure campaign is truly complete
                    var isProcessingComplete = data.progress_status === 'completed' ||
                                             (data.total_recipients > 0 && data.total_processed >= data.total_recipients);
                    
                    if (data.status === 'sent') {
                      // Status is already 'sent', safe to reload immediately
                      clearInterval(progressPollers[cid]);
                      delete progressPollers[cid];
                      
                      setTimeout(function(){
                        window.location.reload();
                      }, 1000);
                    } else if (isProcessingComplete) {
                      // Processing is complete but status not yet updated
                      // Start waiting timer if not already started
                      if (!progressPollers[cid + '_waiting']) {
                        progressPollers[cid + '_waiting'] = Date.now();
                        console.log('Campaign ' + cid + ' processing complete, waiting for status update...');
                      }
                      
                      // If we've been waiting more than 10 seconds, reload anyway
                      var waitTime = Date.now() - progressPollers[cid + '_waiting'];
                      if (waitTime > 10000) {
                        console.log('Campaign ' + cid + ' timeout waiting for status, reloading...');
                        clearInterval(progressPollers[cid]);
                        delete progressPollers[cid];
                        delete progressPollers[cid + '_waiting'];
                        window.location.reload();
                      }
                    }
                  })
                  .catch(function(err){
                    console.error('Progress poll error:', err);
                  });
                }, 2000); // Poll every 2 seconds
                
                // Stop polling after 20 minutes as safety
                setTimeout(function(){
                  if (progressPollers[cid]) {
                    clearInterval(progressPollers[cid]);
                    delete progressPollers[cid];
                  }
                }, 20 * 60 * 1000);
              }
            });

            // select all checkbox
            var chkAll = document.getElementById('chkAll');
            if (chkAll) {
              chkAll.addEventListener('change', function(){
                var boxes = document.querySelectorAll('.campaign-checkbox');
                boxes.forEach(function(b){ b.checked = chkAll.checked; });
              });
            }

            // bulk actions form should copy selected ids into its own form when submitted
            var bulkForm = document.getElementById('bulkActionsForm');
            bulkForm.addEventListener('submit', function(e){
              var sel = document.querySelectorAll('.campaign-checkbox:checked');
              if (sel.length === 0) {
                alert('No campaigns selected.');
                e.preventDefault();
                return false;
              }
              // create hidden inputs for each selected id
              // remove previous if any
              Array.from(bulkForm.querySelectorAll('input[name="campaign_ids[]"]')).forEach(function(n){ n.remove(); });
              sel.forEach(function(cb){
                var inp = document.createElement('input');
                inp.type = 'hidden';
                inp.name = 'campaign_ids[]';
                inp.value = cb.value;
                bulkForm.appendChild(inp);
              });
            });
          })();
        </script>
        <?php } // End PDO check ?>

      <?php elseif ($page === 'editor'): ?>
        <?php
          if ($pdo === null) {
              echo '<div class="page-title">Database Error</div>';
              echo '<div class="card"><div style="padding: 20px;">Database connection not available. Please check your configuration.</div></div>';
          } else {
              $id = (int)($_GET['id'] ?? 0);
              $job = get_job($pdo, $id);
              if (!$job) {
                echo "<p>Job not found.</p>";
              } else {
        ?>
          <div class="page-title">Job Configuration — <?php echo h($job['name']); ?></div>
          <div class="page-subtitle">Configure your email extraction job and select profiles to execute.</div>

          <!-- Status Message Display -->
          <div id="extraction-status" style="display:none; margin-bottom:20px; padding:15px; border-radius:5px; position:relative;">
            <button type="button" onclick="document.getElementById('extraction-status').style.display='none'" 
                    style="position:absolute; top:10px; right:10px; background:none; border:none; font-size:20px; cursor:pointer; color:inherit; opacity:0.7;">
              ×
            </button>
            <div style="display:flex; align-items:flex-start; gap:12px;">
              <div id="status-icon" style="font-size:24px; line-height:1;"></div>
              <div style="flex:1;">
                <strong id="status-title" style="display:block; margin-bottom:8px; font-size:16px;"></strong>
                <p id="status-message" style="margin:0; line-height:1.5;"></p>
                <div id="status-details-toggle" style="margin-top:10px; display:none;">
                  <a href="#" onclick="event.preventDefault(); document.getElementById('status-details').style.display = document.getElementById('status-details').style.display === 'none' ? 'block' : 'none'; this.textContent = document.getElementById('status-details').style.display === 'none' ? '▼ Show Technical Details' : '▲ Hide Technical Details';" style="color:inherit; opacity:0.8; text-decoration:underline; font-size:13px;">▼ Show Technical Details</a>
                </div>
                <pre id="status-details" style="display:none; margin:10px 0 0 0; padding:10px; background:rgba(0,0,0,0.05); border-radius:4px; font-size:12px; max-height:200px; overflow:auto; white-space:pre-wrap; word-wrap:break-word;"></pre>
              </div>
            </div>
          </div>

          <form method="post" id="jobForm">
            <input type="hidden" name="action" value="save_job">
            <input type="hidden" name="id" value="<?php echo (int)$job['id']; ?>">

            <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
              <div style="display:flex; gap:12px; align-items:center;">
                <button class="btn btn-outline" type="submit">Save Configuration</button>
              </div>
              <div>
                <a href="?page=list" class="btn btn-outline">Cancel</a>
                <button class="btn btn-primary" type="button" id="startJobBtn">Start Extraction →</button>
              </div>
            </div>

            <div class="card">
              <div class="card-header">
                <div>
                  <div class="card-title">Job Settings</div>
                  <div class="card-subtitle">Configure extraction parameters for this job.</div>
                </div>
              </div>

              <div class="form-group">
                <label>Job Name</label>
                <input type="text" name="name" value="<?php echo h($job['name']); ?>" required>
              </div>

              <div class="form-group">
                <label>Select Extraction Profile</label>
                <select name="profile_id" id="profileSelect" required>
                  <option value="">Select a profile...</option>
                  <?php foreach ($profiles as $p): ?>
                    <option value="<?php echo (int)$p['id']; ?>" 
                      <?php if ($job['profile_id'] == $p['id']) echo 'selected'; ?>
                      data-query="<?php echo h($p['search_query']); ?>"
                      data-target="<?php echo (int)$p['target_count']; ?>">
                      <?php echo h($p['profile_name']); ?> — Target: <?php echo (int)$p['target_count']; ?> emails
                    </option>
                  <?php endforeach; ?>
                </select>
                <small class="hint">Choose a profile with serper.dev API configuration</small>
              </div>

              <div id="profileDetails" style="display: none; margin-top: 12px; padding: 12px; background: #f8f9fa; border-radius: 4px;">
                <div style="font-weight: 600; margin-bottom: 8px;">Profile Details:</div>
                <div id="profileQuery" style="color: var(--sg-muted);"></div>
                <div id="profileTarget" style="color: var(--sg-muted); margin-top: 4px;"></div>
              </div>

              <div class="form-group">
                <label>Target Email Count (Optional Override)</label>
                <input type="number" name="target_count" min="1" max="10000" 
                  value="<?php echo (int)($job['target_count'] ?? 0); ?>" 
                  placeholder="Leave empty to use profile default">
                <small class="hint">Override the target count for this specific job</small>
              </div>

              <div class="checkbox-row">
                <input type="checkbox" name="start_immediately" id="startImmediately">
                <label for="startImmediately">Start extraction immediately after saving</label>
              </div>
            </div>
          </form>

          <script>
            (function(){
              var profileSelect = document.getElementById('profileSelect');
              var profileDetails = document.getElementById('profileDetails');
              var profileQuery = document.getElementById('profileQuery');
              var profileTarget = document.getElementById('profileTarget');
              var startJobBtn = document.getElementById('startJobBtn');

              // Function to show status messages
              function showStatus(type, title, message, details) {
                var statusBox = document.getElementById('extraction-status');
                var statusIcon = document.getElementById('status-icon');
                var statusTitle = document.getElementById('status-title');
                var statusMessage = document.getElementById('status-message');
                var statusDetails = document.getElementById('status-details');
                var statusDetailsToggle = document.getElementById('status-details-toggle');
                
                // Set colors based on type
                if (type === 'success') {
                  statusBox.style.backgroundColor = '#d4edda';
                  statusBox.style.borderLeft = '4px solid #28a745';
                  statusBox.style.color = '#155724';
                  statusIcon.innerHTML = '✓';
                  statusIcon.style.color = '#28a745';
                } else if (type === 'error') {
                  statusBox.style.backgroundColor = '#f8d7da';
                  statusBox.style.borderLeft = '4px solid #dc3545';
                  statusBox.style.color = '#721c24';
                  statusIcon.innerHTML = '✗';
                  statusIcon.style.color = '#dc3545';
                } else if (type === 'info') {
                  statusBox.style.backgroundColor = '#d1ecf1';
                  statusBox.style.borderLeft = '4px solid #17a2b8';
                  statusBox.style.color = '#0c5460';
                  statusIcon.innerHTML = 'ℹ';
                  statusIcon.style.color = '#17a2b8';
                }
                
                statusTitle.textContent = title;
                statusMessage.textContent = message;
                
                if (details) {
                  statusDetails.textContent = details;
                  statusDetailsToggle.style.display = 'block';
                } else {
                  statusDetailsToggle.style.display = 'none';
                }
                
                statusBox.style.display = 'block';
                statusBox.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
              }

              function updateProfileDetails() {
                var selectedOption = profileSelect.options[profileSelect.selectedIndex];
                if (selectedOption.value) {
                  var query = selectedOption.getAttribute('data-query');
                  var target = selectedOption.getAttribute('data-target');
                  profileQuery.innerText = 'Query: ' + query;
                  profileTarget.innerText = 'Target: ' + target + ' emails';
                  profileDetails.style.display = '';
                } else {
                  profileDetails.style.display = 'none';
                }
              }

              if (profileSelect) {
                profileSelect.addEventListener('change', updateProfileDetails);
                updateProfileDetails(); // Initial update
              }

              // Start job button handler
              if (startJobBtn) {
                startJobBtn.addEventListener('click', function(e) {
                  e.preventDefault();
                  console.log('START EXTRACTION BUTTON: Button clicked');
                  console.log('START EXTRACTION BUTTON: Profile selected:', profileSelect.value);
                  
                  // Hide any previous status messages
                  document.getElementById('extraction-status').style.display = 'none';
                  
                  if (!profileSelect.value) {
                    console.log('START EXTRACTION BUTTON: ERROR - No profile selected');
                    showStatus('error', 
                      '✗ Error: No Profile Selected', 
                      'Please select an extraction profile from the dropdown before starting the job.',
                      'START_EXTRACTION: profile_id is empty or invalid');
                    return;
                  }
                  
                  if (confirm('Start extraction job now?')) {
                    console.log('START EXTRACTION BUTTON: User confirmed, proceeding...');
                    
                    var jobForm = document.getElementById('jobForm');
                    if (!jobForm) {
                      console.log('START EXTRACTION BUTTON: ERROR - Job form not found');
                      showStatus('error', 
                        '✗ Error: Form Not Found', 
                        'Unable to locate the job configuration form. Please refresh the page and try again.',
                        'START_EXTRACTION: document.getElementById("jobForm") returned null');
                      return;
                    }
                    
                    console.log('START EXTRACTION BUTTON: Job form found');
                    
                    // Show loading message
                    var selectedOption = profileSelect.options[profileSelect.selectedIndex];
                    var profileName = selectedOption.text.split(' — ')[0];
                    showStatus('info', 
                      '⏳ Starting Extraction...', 
                      'Saving job configuration and starting extraction with profile: ' + profileName,
                      null);
                    
                    // Create hidden input to trigger start after save
                    var startInput = document.createElement('input');
                    startInput.type = 'hidden';
                    startInput.name = 'start_immediately';
                    startInput.value = '1';
                    jobForm.appendChild(startInput);
                    
                    console.log('START EXTRACTION BUTTON: Added start_immediately=1 input');
                    console.log('START EXTRACTION BUTTON: Submitting form...');
                    
                    // Submit the form (will save job and redirect to start)
                    jobForm.submit();
                    
                    // Show success message (form submission will redirect, but show optimistic message)
                    setTimeout(function() {
                      showStatus('success', 
                        '✓ Extraction Started Successfully!', 
                        'Job has been saved and extraction is starting. Redirecting to job list...',
                        null);
                    }, 100);
                    
                  } else {
                    console.log('START EXTRACTION BUTTON: User cancelled');
                  }
                });
              } else {
                console.log('START EXTRACTION BUTTON: ERROR - Button not found');
              }
            })();
          </script>

        <?php } // End job check ?>
        <?php } // End PDO check ?>

      <?php elseif ($page === 'review'): ?>
          <?php elseif ($page === 'review'): ?>
            <?php
              if ($pdo === null) {
                  echo '<div class="page-title">Database Error</div>';
                  echo '<div class="card"><div style="padding: 20px;">Database connection not available. Please check your configuration.</div></div>';
              } else {
                  $id = (int)($_GET['id'] ?? 0);
                  $campaign = get_campaign($pdo, $id);
                  if (!$campaign) {
                    echo "<p>Campaign not found.</p>";
                  } else {
                    // Prepare preview HTML (raw)
                    $previewHtml = $campaign['html'] ?: '<div style="padding:24px;color:#6B778C;">(No content yet — add modules in the Editor)</div>';
                    if (!empty($campaign['unsubscribe_enabled'])) {
                    $basePreviewUrl = get_base_url() . '?t=unsubscribe&cid=' . (int)$campaign['id'];
                    $previewHtml .= '<div style="text-align:center;margin-top:20px;color:#1F2933;font-size:13px;">';
                    $previewHtml .= '<a href="' . $basePreviewUrl . '" style="color:#1A82E2;margin-right:8px;">Unsubscribe</a>';
                    $previewHtml .= '<span style="color:#6B778C;">-</span>';
                    $previewHtml .= '<a href="' . $basePreviewUrl . '" style="color:#1A82E2;margin-left:8px;">Unsubscribe Preferences</a>';
                    $previewHtml .= '</div>';
                }
                $testSent = isset($_GET['test_sent']);
                $sentFlag = isset($_GET['sent']);
                $sendOk = isset($_GET['send_ok']);
                $sendErr = isset($_GET['send_err']);
            ?>
              <div class="page-title">Review &amp; Send — <?php echo h($campaign['name']); ?></div>
              <div class="page-subtitle">Confirm your envelope settings and preview the content before sending.</div>

              <div class="review-grid">
                <div>
                  <div class="review-summary">
                    <form method="post" id="sendForm">
                      <input type="hidden" name="action" value="send_campaign">
                      <input type="hidden" name="id" value="<?php echo (int)$campaign['id']; ?>">

                      <div class="form-group">
                        <label>Single Send Name</label>
                        <input type="text" name="name" value="<?php echo h($campaign['name']); ?>" disabled>
                      </div>

                      <div class="form-group">
                        <label>From Sender</label>
                        <select name="from_email">
                          <option value=""><?php echo $campaign['from_email'] ? h($campaign['from_email']) : 'Select sender'; ?></option>
                          <?php foreach ($profiles as $p): ?>
                            <option value="<?php echo h($p['from_email']); ?>" <?php if ($campaign['from_email'] === $p['from_email']) echo 'selected'; ?>>
                              <?php echo h($p['from_email'] . ' — ' . $p['profile_name']); ?>
                            </option>
                          <?php endforeach; ?>
                        </select>
                      </div>

                      <div class="form-group">
                        <label>Subject</label>
                        <input type="text" name="subject" value="<?php echo h($campaign['subject']); ?>">
                      </div>

                      <div class="form-group">
                        <label>Preheader</label>
                        <input type="text" name="preheader" value="<?php echo h($campaign['preheader']); ?>">
                      </div>

                      <div class="form-group">
                        <label>Unsubscribe Group</label>
                        <div style="display:flex; align-items:center; gap:10px;">
                          <select name="unsubscribe_group" disabled>
                            <option value="">Default</option>
                          </select>
                          <div class="checkbox-row" style="margin:0;">
                            <input type="checkbox" id="rv_unsub" name="unsubscribe_enabled" <?php if (!empty($campaign['unsubscribe_enabled'])) echo 'checked'; ?>>
                            <label for="rv_unsub" style="font-weight:600;margin:0;">Enable Unsubscribe</label>
                          </div>
                        </div>
                      </div>

                      <div class="form-group">
                        <label>Schedule</label>
                        <input type="text" value="Send Immediately" disabled>
                      </div>

                      <div class="form-group">
                        <label>Send To Recipients</label>
                        <select name="audience_select" id="audienceSelect" style="width:100%; padding:8px;">
                          <option value="">Select recipients</option>
                          <?php foreach ($contactLists as $cl):
                            $val = 'list:'.$cl['id'];
                          ?>
                            <option value="<?php echo h($val); ?>"><?php echo h($cl['name']).' — '.(int)$cl['contact_count'].' contacts'; ?></option>
                          <?php endforeach; ?>
                          <option value="manual">Manual - Enter emails</option>
                        </select>
                      </div>

                      <div id="manualRecipientsWrap" style="display:none; margin-top:10px;">
                        <label style="font-weight:600; display:block; margin-bottom:6px;">Manual recipients (one per line)</label>
                        <textarea name="test_recipients" placeholder="test1@example.com&#10;test2@example.com" rows="6" style="width:100%;"></textarea>
                      </div>

                      <div style="display:flex; justify-content:flex-end; gap:8px; margin-top:12px;">
                        <a href="?page=editor&id=<?php echo (int)$campaign['id']; ?>" class="btn btn-outline">Back to Editor</a>
                        <button type="submit" class="btn btn-primary">Send Immediately</button>
                      </div>
                    </form>

                    <div style="margin-top:18px;">
                      <div style="font-weight:600; margin-bottom:8px;">Test Your Email</div>
                      <div style="color:var(--sg-muted); margin-bottom:8px;">Test your email before sending to your recipients.</div>

                      <form method="post" style="margin-top:12px;" id="testForm">
                        <input type="hidden" name="action" value="send_test_message">
                        <input type="hidden" name="id" value="<?php echo (int)$campaign['id']; ?>">
                        <div>
                          <label style="display:block; font-weight:600; margin-bottom:6px;">Send a Test Email</label>
                          <input type="text" name="test_addresses" placeholder="Enter up to 10 email addresses, separated by commas" style="width:100%; padding:10px;">
                        </div>
                        <div style="display:flex; justify-content:space-between; align-items:center; margin-top:10px;">
                          <div style="color:var(--sg-muted); font-size:12px;">You can send test messages to up to 10 addresses.</div>
                          <button class="btn btn-primary" type="submit">Send Test Message</button>
                        </div>
                      </form>

                      <small class="hint">Test messages are sent immediately and recorded as test events (they won't change campaign status).</small>
                    </div>

                  </div>
                </div>

                <div>
                  <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:12px;">
                    <div style="font-weight:600;">Content preview</div>
                    <div style="color:var(--sg-muted); font-size:12px;">Desktop | Mobile | Plain Text</div>
                  </div>
                  <div class="preview-box" id="previewBox">
                    <?php echo $previewHtml; ?>
                  </div>
                </div>
              </div>

              <script>
                (function(){
                  var aud = document.getElementById('audienceSelect');
                  var manualWrap = document.getElementById('manualRecipientsWrap');

                  function updateManualVisibility() {
                    if (aud && manualWrap) {
                      if (aud.value === 'manual') {
                        manualWrap.style.display = '';
                      } else {
                        manualWrap.style.display = 'none';
                      }
                    }
                  }
                  if (aud) {
                    aud.addEventListener('change', updateManualVisibility);
                    updateManualVisibility();
                  }

                  var rvUnsub = document.getElementById('rv_unsub');
                  var previewBox = document.getElementById('previewBox');
                  if (rvUnsub && previewBox) {
                    rvUnsub.addEventListener('change', function(){
                      var existing = previewBox.querySelector('.preview-unsubscribe-block');
                      if (rvUnsub.checked) {
                        if (!existing) {
                          var div = document.createElement('div');
                          div.className = 'preview-unsubscribe-block';
                          div.style.textAlign = 'center';
                          div.style.marginTop = '20px';
                          div.innerHTML = '<a href="<?php echo get_base_url() . '?t=unsubscribe&cid=' . (int)$campaign['id']; ?>" style="color:#1A82E2;margin-right:8px;">Unsubscribe</a> - <a href="<?php echo get_base_url() . '?t=unsubscribe&cid=' . (int)$campaign['id']; ?>" style="color:#1A82E2;margin-left:8px;">Unsubscribe Preferences</a>';
                          previewBox.appendChild(div);
                        }
                      } else {
                        if (existing) existing.remove();
                      }
                    });
                  }

                  // Show toast if test was sent or campaign sent flag present
                  <?php if ($testSent): ?>
                    document.addEventListener('DOMContentLoaded', function(){ showToast('Test message sent', 'success'); });
                  <?php endif; ?>
                })();
              </script>

            <?php } // End campaign check ?>
            <?php } // End PDO check ?>

          <?php elseif ($page === 'stats'): ?>
            <?php
              if ($pdo === null) {
                  echo '<div class="page-title">Database Error</div>';
                  echo '<div class="card"><div style="padding: 20px;">Database connection not available. Please check your configuration.</div></div>';
              } else {
                  $id = (int)($_GET['id'] ?? 0);
                  $job = get_job($pdo, $id);
                  if (!$job) {
                    echo "<p>Job not found.</p>";
                  } else {
                    $stats = get_job_stats($pdo, $id);
                    
                    // Get profile information
                    $profile = null;
                    if (!empty($job['profile_id'])) {
                        $profile_stmt = $pdo->prepare("SELECT * FROM job_profiles WHERE id = ?");
                        $profile_stmt->execute([$job['profile_id']]);
                        $profile = $profile_stmt->fetch(PDO::FETCH_ASSOC);
                    }
                    
                    $extracted = (int)($job['progress_extracted'] ?? 0);
                    $target = (int)($job['progress_total'] ?? $job['target_count'] ?? 0);
                    $progress = $target > 0 ? round(($extracted / $target) * 100) : 0;
            ?>
              <div class="page-title">Job Stats — <?php echo h($job['name']); ?></div>
              <div class="page-subtitle">Extraction results and progress for this job.</div>

              <!-- Error Alert Display - Shows Actual Error Message -->
              <?php if (!empty($job['error_message'])): ?>
              <div class="card" style="margin-bottom:20px; border-left:4px solid #dc3545; background:#f8d7da;">
                <div style="padding:20px;">
                  <h3 style="margin:0 0 15px 0; color:#721c24; font-size:20px;">⚠️ Extraction Failed - Complete Diagnostic Information</h3>
                  
                  <!-- Full Error Message + API Log Box -->
                  <div style="background:#fff; padding:15px; border:1px solid #f5c6cb; border-radius:4px; margin:15px 0; max-height:400px; overflow-y:auto;">
                    <strong style="display:block; margin-bottom:10px; color:#721c24;">Full Error Details + API Log:</strong>
                    <pre style="color:#721c24; margin:0; padding:10px; background:#f9f9f9; border:1px solid #e0e0e0; border-radius:3px; font-family:monospace; font-size:13px; line-height:1.5; white-space:pre-wrap; word-wrap:break-word;"><?php echo htmlspecialchars($job['error_message']); ?></pre>
                  </div>
                  
                  <!-- Collapsible Error Code Meanings -->
                  <details style="margin:15px 0; cursor:pointer;">
                    <summary style="font-weight:bold; color:#721c24; cursor:pointer; padding:10px; background:#fef5f6; border-radius:3px; border:1px solid #f5c6cb;">📖 Error Code Meanings (Click to expand)</summary>
                    <ul style="margin:10px 0 0 0; padding:10px 10px 10px 35px; background:#fff; border-radius:3px; color:#721c24;">
                      <li style="margin:6px 0;"><strong>HTTP 401</strong> = Invalid or expired API key</li>
                      <li style="margin:6px 0;"><strong>HTTP 429</strong> = Rate limit exceeded (too many requests)</li>
                      <li style="margin:6px 0;"><strong>HTTP 500</strong> = serper.dev server error</li>
                      <li style="margin:6px 0;"><strong>cURL error</strong> = Network connectivity problem</li>
                      <li style="margin:6px 0;"><strong>Invalid JSON</strong> = Malformed API response</li>
                      <li style="margin:6px 0;"><strong>Connection timeout</strong> = Server can't reach serper.dev (firewall/network issue)</li>
                    </ul>
                  </details>
                  
                  <div style="margin:15px 0;">
                    <strong style="display:block; margin-bottom:8px; color:#721c24;">🔧 Troubleshooting Steps:</strong>
                    <ol style="margin:0; padding-left:25px; color:#721c24;">
                      <li style="margin:5px 0;">Copy the full error details shown above</li>
                      <li style="margin:5px 0;">Verify your API key at <a href="https://serper.dev/dashboard" target="_blank" style="color:#721c24; text-decoration:underline;">serper.dev/dashboard</a></li>
                      <li style="margin:5px 0;">Test your search query at <a href="https://serper.dev/playground" target="_blank" style="color:#721c24; text-decoration:underline;">serper.dev/playground</a></li>
                      <li style="margin:5px 0;">Check your API usage and remaining quota</li>
                      <li style="margin:5px 0;">Verify your server has internet connectivity to serper.dev</li>
                      <li style="margin:5px 0;">Check error.log file on server for additional technical details</li>
                    </ol>
                  </div>
                </div>
              </div>
              <?php endif; ?>

              <div class="card" style="margin-bottom:18px;">
                <div class="card-header">
                  <div>
                    <div class="card-title">Summary</div>
                  </div>
                  <a href="?page=list" class="btn btn-outline">← Back to Jobs</a>
                </div>
                <div class="form-row">
                  <div class="form-group">
                    <label>Profile</label>
                    <div><?php echo $profile ? h($profile['profile_name']) : 'N/A'; ?></div>
                  </div>
                  <div class="form-group">
                    <label>Search Query</label>
                    <div><?php echo $profile ? h($profile['search_query']) : 'N/A'; ?></div>
                  </div>
                  <div class="form-group">
                    <label>Started at</label>
                    <div><?php echo $job['started_at'] ? h($job['started_at']) : '<span class="hint">Not started</span>'; ?></div>
                  </div>
                  <div class="form-group">
                    <label>Completed at</label>
                    <div><?php echo $job['completed_at'] ? h($job['completed_at']) : '<span class="hint">Not completed</span>'; ?></div>
                  </div>
                </div>
              </div>

              <!-- Last API Response Details (Debug Info) -->
              <?php if (!empty($job['error_message']) && strpos($job['error_message'], 'API LOG:') !== false): ?>
              <div class="card" style="margin-bottom:18px;">
                <div class="card-header">
                  <div>
                    <div class="card-title">🔍 Last API Response</div>
                    <div class="card-subtitle">Diagnostic information from the last API call attempt</div>
                  </div>
                </div>
                <div style="padding:16px;">
                  <?php
                    // Parse error message to extract API log details
                    $errorLines = explode("\n", $job['error_message']);
                    $httpCode = null;
                    $responseLength = null;
                    $responsePreview = null;
                    
                    foreach ($errorLines as $line) {
                      if (preg_match('/HTTP Code:\s*(\d+)/', $line, $matches)) {
                        $httpCode = $matches[1];
                      }
                      if (preg_match('/Response length:\s*([\d,]+)\s*bytes/', $line, $matches)) {
                        $responseLength = $matches[1];
                      }
                      if (preg_match('/First \d+ chars of response:\s*(.+)/', $line, $matches)) {
                        $responsePreview = $matches[1];
                      }
                      if (preg_match('/FULL ERROR RESPONSE:\s*(.+)/', $line, $matches)) {
                        $responsePreview = $matches[1];
                      }
                    }
                  ?>
                  
                  <div class="form-row">
                    <?php if ($httpCode): ?>
                    <div class="form-group">
                      <label>HTTP Status Code</label>
                      <div style="font-family:monospace; font-size:14px; font-weight:600; color:<?php echo ($httpCode == '200') ? '#15803d' : '#dc2626'; ?>;">
                        <?php echo h($httpCode); ?>
                        <?php 
                          if ($httpCode == '200') echo ' <span style="color:#15803d;">✓ OK</span>';
                          elseif ($httpCode == '401') echo ' <span style="color:#dc2626;">❌ Unauthorized</span>';
                          elseif ($httpCode == '429') echo ' <span style="color:#dc2626;">❌ Rate Limit</span>';
                          else echo ' <span style="color:#dc2626;">❌ Error</span>';
                        ?>
                      </div>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($responseLength): ?>
                    <div class="form-group">
                      <label>Response Size</label>
                      <div style="font-family:monospace; font-size:14px;"><?php echo h($responseLength); ?> bytes</div>
                    </div>
                    <?php endif; ?>
                    
                    <div class="form-group">
                      <label>Extraction Time</label>
                      <div style="font-family:monospace; font-size:14px;">
                        <?php 
                          if ($job['started_at'] && $job['completed_at']) {
                            $start = new DateTime($job['started_at']);
                            $end = new DateTime($job['completed_at']);
                            $diff = $start->diff($end);
                            echo $diff->s . ' seconds';
                          } else {
                            echo '<span class="hint">N/A</span>';
                          }
                        ?>
                      </div>
                    </div>
                  </div>
                  
                  <?php if ($responsePreview): ?>
                  <div style="margin-top:16px;">
                    <label style="display:block; margin-bottom:8px; font-weight:600;">API Response Preview:</label>
                    <pre style="background:#f9f9f9; border:1px solid #e0e0e0; border-radius:4px; padding:12px; margin:0; font-size:12px; line-height:1.4; max-height:200px; overflow-y:auto; white-space:pre-wrap; word-wrap:break-word;"><?php echo htmlspecialchars(substr($responsePreview, 0, 1000)); ?></pre>
                  </div>
                  <?php endif; ?>
                  
                  <div style="margin-top:16px; padding:12px; background:#eff6ff; border:1px solid #bfdbfe; border-radius:4px; font-size:13px; color:#1e40af;">
                    <strong>💡 Tip:</strong> Use the "Check Connection" button in Job Profiles to test your API key and search query before running extraction.
                  </div>
                </div>
              </div>
              <?php endif; ?>

              <!-- Real-time Job Progress -->
              <?php 
                $jobStatus = $job['status'];
                if ($jobStatus === 'queued' || $jobStatus === 'extracting'):
              ?>
              <div class="card" style="margin-bottom:18px;" id="progressCard">
                <div class="card-header">
                  <div>
                    <div class="card-title">Extraction Progress</div>
                    <div class="card-subtitle">Real-time extraction progress (updates automatically)</div>
                  </div>
                </div>
                <div style="padding:16px;">
                  <div style="margin-bottom:12px; font-size:18px; font-weight:600;">
                    <span id="progressText"><?php echo $extracted; ?> / <?php echo $target; ?> extracted (<?php echo $progress; ?>%)</span>
                  </div>
                  <div style="background:#E4E7EB; border-radius:4px; height:24px; overflow:hidden; position:relative;">
                    <div id="progressBar" style="background:linear-gradient(90deg, #1A82E2, #3B9EF3); height:100%; width:<?php echo $progress; ?>%; transition:width 0.3s;"></div>
                  </div>
                  <div style="margin-top:8px; color:#6B778C; font-size:13px;" id="progressStatus">Status: <?php echo ucfirst($jobStatus); ?></div>
                </div>
              </div>
              <?php endif; ?>

              <!-- Simplified header stats for extraction -->
              <div class="header-stats">
                <div class="stat-item">
                  <div class="stat-num"><?php echo $target; ?></div>
                  <div class="stat-label">Target Emails</div>
                </div>
                <div class="stat-item">
                  <div class="stat-num"><?php echo $extracted; ?></div>
                  <div class="stat-label">Extracted</div>
                </div>
                <div class="stat-item">
                  <div class="stat-num"><?php echo $progress; ?>%</div>
                  <div class="stat-label">Progress</div>
                </div>
                <div class="stat-item">
                  <div class="stat-num"><?php echo $jobStatus === 'completed' ? 'Complete' : ucfirst($jobStatus); ?></div>
                  <div class="stat-label">Status</div>
                </div>
              </div>

              <div class="card">
                <div class="card-header">
                  <div>
                    <div class="card-title">Extracted Emails</div>
                    <div class="card-subtitle">List of all emails extracted for this job (most recent first).</div>
                  </div>
                  <div>
                    <a href="?page=list" class="btn btn-outline">Download CSV</a>
                  </div>
                </div>
                <table class="table">
                  <thead>
                    <tr>
                      <th>Email</th>
                      <th>Source Query</th>
                      <th>Extracted At</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php
                      $stmt = $pdo->prepare("SELECT * FROM extracted_emails WHERE job_id = ? ORDER BY extracted_at DESC LIMIT 100");
                      $stmt->execute([$id]);
                      $emails = $stmt->fetchAll(PDO::FETCH_ASSOC);
                      if (empty($emails)):
                    ?>
                      <tr>
                        <td colspan="3" style="text-align:center; padding:16px; color:var(--sg-muted);">
                          No emails extracted yet.
                        </td>
                      </tr>
                    <?php else: ?>
                      <?php foreach ($emails as $em): ?>
                        <tr>
                          <td><?php echo h($em['email']); ?></td>
                          <td><?php echo h($em['source'] ?? 'N/A'); ?></td>
                          <td><?php echo h($em['extracted_at']); ?></td>
                        </tr>
                      <?php endforeach; ?>
                      <?php if (count($emails) >= 100): ?>
                        <tr>
                          <td colspan="3" style="text-align:center; padding:12px; color:var(--sg-muted);">
                            Showing first 100 results. Export to CSV for full list.
                          </td>
                        </tr>
                      <?php endif; ?>
                    <?php endif; ?>
                  </tbody>
                </table>
              </div>

              <!-- Real-time Progress JavaScript -->
              <script>
                (function(){
                  var campaignId = <?php echo (int)$id; ?>;
                  var progressCard = document.getElementById('progressCard');
                  var progressBar = document.getElementById('progressBar');
                  var progressText = document.getElementById('progressText');
                  var progressStatus = document.getElementById('progressStatus');
                  
                  if (progressCard) {
                    // Poll progress API every 2 seconds
                    var pollInterval = setInterval(function(){
                      fetch('?api=progress&campaign_id=' + campaignId)
                        .then(function(r){ return r.json(); })
                        .then(function(data){
                          if (data.success && data.progress) {
                            var prog = data.progress;
                            var stats = data.stats || {};
                            
                            // Update progress bar
                            if (progressBar) {
                              progressBar.style.width = prog.percentage + '%';
                            }
                            
                            // Update progress text
                            if (progressText) {
                              progressText.textContent = prog.sent + ' / ' + prog.total + ' sent (' + prog.percentage + '%)';
                            }
                            
                            // Update status
                            if (progressStatus) {
                              progressStatus.textContent = 'Status: ' + prog.status.charAt(0).toUpperCase() + prog.status.slice(1);
                            }
                            
                            // Update stats in header
                            var statItems = document.querySelectorAll('.stat-item .stat-num');
                            if (statItems.length >= 7) {
                              // statItems[0] is Emails Triggered (don't update)
                              if (statItems[1]) statItems[1].textContent = stats.delivered || 0;
                              if (statItems[2]) statItems[2].textContent = stats.open || 0;
                              if (statItems[3]) statItems[3].textContent = stats.click || 0;
                              if (statItems[4]) statItems[4].textContent = stats.bounce || 0;
                              if (statItems[5]) statItems[5].textContent = stats.unsubscribe || 0;
                              // statItems[6] is Spam Reports (don't update from this API)
                            }
                            
                            // Stop polling and reload page if campaign is completed
                            // Check both progress_status (completed) and campaign status (sent)
                            if (prog.status === 'completed' || prog.campaign_status === 'sent') {
                              clearInterval(pollInterval);
                              // Reload page after 2 seconds to show final "sent" status
                              setTimeout(function(){
                                window.location.reload();
                              }, 2000);
                            }
                          }
                        })
                        .catch(function(err){
                          console.error('Progress poll error:', err);
                        });
                    }, 2000);
                  }
                })();
              </script>

            <?php } // End campaign check ?>
            <?php } // End PDO check for stats ?>

          <?php elseif ($page === 'contacts'): ?>
            <?php
              // Contacts feature not available in email extraction system
              // Redirect to jobs list
              header('Location: ?page=list');
              exit;
            ?>

          <?php elseif ($page === 'activity'): ?>
            <?php
              // Activity page not available in email extraction system
              // Redirect to jobs list
              header('Location: ?page=list');
              exit;
            ?>

          <?php elseif ($page === 'tracking'): ?>
            <?php
              // Tracking page not available in email extraction system
              // Redirect to jobs list
              header('Location: ?page=list');
              exit;
            ?>
          <?php else: ?>
            <p>Unknown page.</p>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <script>
      (function(){
        var spOpenBtn = document.getElementById('spOpenBtn');
        var spCloseBtn = document.getElementById('spCloseBtn');
        var spSidebar = document.getElementById('spSidebar');
        var pageWrapper = document.getElementById('pageWrapper');

        function openSidebar() {
          spSidebar.classList.add('open');
          pageWrapper.classList.add('shifted');
        }
        function closeSidebar() {
          spSidebar.classList.remove('open');
          pageWrapper.classList.remove('shifted');
        }

        if (spOpenBtn) spOpenBtn.addEventListener('click', openSidebar);
        if (spCloseBtn) spCloseBtn.addEventListener('click', closeSidebar);

        // Toggle profile fields when switching type
        var pfType = document.getElementById('pf_type');
        if (pfType) {
          pfType.addEventListener('change', function() {
            var v = pfType.value;
            var smtpFields = document.getElementById('pf_smtp_fields');
            var apiFields = document.getElementById('pf_api_fields');
            if (v === 'api') {
              smtpFields.style.display = 'none';
              apiFields.style.display = '';
            } else {
              smtpFields.style.display = '';
              apiFields.style.display = 'none';
            }
          });
        }

        // Open sidebar if editing profile
        <?php if ($editProfile): ?>
          openSidebar();
        <?php endif; ?>

        // Toast helper exposed to global scope
        window.showToast = function(msg, type){
          var t = document.getElementById('globalToast');
          if(!t) return;
          t.innerText = msg;
          t.className = 'toast show ' + (type === 'error' ? 'error' : 'success');
          setTimeout(function(){
            t.className = 'toast';
          }, 2000);
        };

        // Show notifications for query params (e.g., test_sent, sent)
        (function(){
          var params = new URLSearchParams(window.location.search);
          if (params.has('test_sent')) {
            showToast('Test message(s) sent', 'success');
          }
          if (params.has('sent')) {
            showToast('Campaign queued for sending', 'success');
          }
          if (params.has('save_error')) {
            showToast('Save error', 'error');
          }
          if (params.has('no_recipients')) {
            showToast('No recipients to send to (all invalid or unsubscribed)', 'error');
          }
          if (params.has('cleared_unsubscribes')) {
            showToast('All unsubscribes cleared', 'success');
          }
          if (params.has('cleared_bounces')) {
            showToast('All bounces cleared', 'success');
          }
        })();

        // Wire up Check Connection buttons (AJAX)
        document.querySelectorAll('.check-conn-btn').forEach(function(btn){
          btn.addEventListener('click', function(){
            var pid = btn.getAttribute('data-pid');
            var statusEl = document.getElementById('profile-conn-status-' + pid);
            if (!statusEl) return;
            statusEl.innerText = 'Checking...';
            var fd = new FormData();
            fd.append('action', 'check_connection_profile');
            fd.append('profile_id', pid);

            fetch(window.location.pathname + window.location.search, {
              method: 'POST',
              body: fd,
              credentials: 'same-origin'
            }).then(function(r){ return r.json(); })
              .then(function(json){
                if (json && json.ok) {
                  statusEl.style.color = 'green';
                  statusEl.innerText = json.msg || 'OK';
                  showToast('Connection OK', 'success');
                } else {
                  statusEl.style.color = 'red';
                  statusEl.innerText = (json && json.msg) ? json.msg : 'Connection failed';
                  showToast('Connection failed', 'error');
                }
              }).catch(function(err){
                statusEl.style.color = 'red';
                statusEl.innerText = 'Connection error';
                showToast('Connection error', 'error');
              });
          });
        });

      })();
      
      // Test Connection Button Handler
      (function() {
        const testBtn = document.getElementById('testConnectionBtn');
        const resultDiv = document.getElementById('connectionTestResult');
        const apiKeyInput = document.getElementById('profile_api_key');
        const searchQueryInput = document.getElementById('profile_search_query');
        
        if (testBtn && resultDiv && apiKeyInput && searchQueryInput) {
          testBtn.addEventListener('click', function() {
            const apiKey = apiKeyInput.value.trim();
            const searchQuery = searchQueryInput.value.trim();
            
            if (!apiKey) {
              resultDiv.style.display = 'block';
              resultDiv.style.background = '#fee';
              resultDiv.style.color = '#c00';
              resultDiv.style.border = '1px solid #fcc';
              resultDiv.innerHTML = '❌ Please enter an API key';
              return;
            }
            
            if (!searchQuery) {
              resultDiv.style.display = 'block';
              resultDiv.style.background = '#fee';
              resultDiv.style.color = '#c00';
              resultDiv.style.border = '1px solid #fcc';
              resultDiv.innerHTML = '❌ Please enter a search query';
              return;
            }
            
            // Show loading state
            testBtn.disabled = true;
            testBtn.innerHTML = '⏳ Testing...';
            resultDiv.style.display = 'block';
            resultDiv.style.background = '#eff6ff';
            resultDiv.style.color = '#1e40af';
            resultDiv.style.border = '1px solid #bfdbfe';
            resultDiv.innerHTML = '⏳ Connecting to serper.dev...';
            
            // Test connection
            fetch('?action=test_connection', {
              method: 'POST',
              headers: {'Content-Type': 'application/x-www-form-urlencoded'},
              body: 'api_key=' + encodeURIComponent(apiKey) + '&search_query=' + encodeURIComponent(searchQuery)
            })
            .then(r => {
              // Check if response is OK and is JSON
              if (!r.ok) {
                throw new Error('HTTP ' + r.status + ': ' + r.statusText);
              }
              const contentType = r.headers.get('content-type');
              if (!contentType || !contentType.includes('application/json')) {
                // Response is not JSON, likely an error page
                return r.text().then(text => {
                  throw new Error('Server returned non-JSON response. Check server logs for errors.');
                });
              }
              return r.json();
            })
            .then(data => {
              testBtn.disabled = false;
              testBtn.innerHTML = '🔌 Test Connection';
              
              if (data.success) {
                resultDiv.style.background = '#f0fdf4';
                resultDiv.style.color = '#15803d';
                resultDiv.style.border = '1px solid #bbf7d0';
                resultDiv.innerHTML = 
                  '<strong>✓ Connection Successful!</strong><br>' +
                  '<small>HTTP ' + data.http_code + ' | ' +
                  data.elapsed_ms + 'ms | ' +
                  data.result_count + ' results</small>';
              } else {
                resultDiv.style.background = '#fee';
                resultDiv.style.color = '#c00';
                resultDiv.style.border = '1px solid #fcc';
                resultDiv.innerHTML = 
                  '<strong>❌ Connection Failed</strong><br>' +
                  '<small>' + (data.error || 'Unknown error') + '</small>' +
                  (data.http_code ? '<br><small>HTTP ' + data.http_code + ' | ' + data.elapsed_ms + 'ms</small>' : '');
              }
            })
            .catch(err => {
              testBtn.disabled = false;
              testBtn.innerHTML = '🔌 Test Connection';
              resultDiv.style.background = '#fee';
              resultDiv.style.color = '#c00';
              resultDiv.style.border = '1px solid #fcc';
              resultDiv.innerHTML = '❌ Network error: ' + err.message;
            });
          });
        }
      })();
      
      // Test connection for profile in list
      function testProfileConnection(profileId) {
        const statusDiv = document.getElementById('profile-conn-status-' + profileId);
        if (!statusDiv) return;
        
        // Show loading state
        statusDiv.style.display = 'block';
        statusDiv.style.padding = '8px';
        statusDiv.style.borderRadius = '4px';
        statusDiv.style.background = '#eff6ff';
        statusDiv.style.color = '#1e40af';
        statusDiv.style.border = '1px solid #bfdbfe';
        statusDiv.innerHTML = '⏳ Testing connection...';
        
        // Test connection
        fetch('?action=test_connection', {
          method: 'POST',
          headers: {'Content-Type': 'application/x-www-form-urlencoded'},
          body: 'profile_id=' + profileId
        })
        .then(r => {
          // Check if response is OK and is JSON
          if (!r.ok) {
            throw new Error('HTTP ' + r.status + ': ' + r.statusText);
          }
          const contentType = r.headers.get('content-type');
          if (!contentType || !contentType.includes('application/json')) {
            // Response is not JSON, likely an error page
            return r.text().then(text => {
              throw new Error('Server returned non-JSON response. Check server logs for errors.');
            });
          }
          return r.json();
        })
        .then(data => {
          if (data.success) {
            statusDiv.style.background = '#f0fdf4';
            statusDiv.style.color = '#15803d';
            statusDiv.style.border = '1px solid #bbf7d0';
            statusDiv.innerHTML = 
              '<strong>✓ Connection OK</strong> | HTTP ' + data.http_code + ' | ' +
              data.elapsed_ms + 'ms | ' + data.result_count + ' results';
          } else {
            statusDiv.style.background = '#fee';
            statusDiv.style.color = '#c00';
            statusDiv.style.border = '1px solid #fcc';
            statusDiv.innerHTML = 
              '<strong>❌ Failed:</strong> ' + (data.error || 'Unknown error') +
              (data.http_code ? ' | HTTP ' + data.http_code : '');
          }
          
          // Auto-hide after 10 seconds
          setTimeout(() => {
            statusDiv.style.display = 'none';
          }, 10000);
        })
        .catch(err => {
          statusDiv.style.background = '#fee';
          statusDiv.style.color = '#c00';
          statusDiv.style.border = '1px solid #fcc';
          statusDiv.innerHTML = '❌ Network error: ' + err.message;
          
          // Auto-hide after 10 seconds
          setTimeout(() => {
            statusDiv.style.display = 'none';
          }, 10000);
        });
      }
    </script>

    </body>
    </html>
