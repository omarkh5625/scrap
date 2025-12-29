<?php declare(strict_types=1);

/**
 * Standalone API Layer for Email Extraction System
 * 
 * This provides a RESTful API interface completely separated from the UI
 * Can be used by external clients, mobile apps, or the web UI
 * 
 * Usage: api.php?action=<action>&param=value
 */

// Include the main app for access to classes (but we won't render UI)
define('API_MODE', true); // Flag to prevent UI rendering

require_once __DIR__ . '/app.php';

// Enable CORS
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
header('Content-Type: application/json; charset=utf-8');

// Handle preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Get action
$action = $_GET['action'] ?? $_POST['action'] ?? '';

try {
    // Route to appropriate handler
    switch ($action) {
        // Job Management
        case 'create_job':
            apiCreateJob();
            break;
        
        case 'get_jobs':
            apiGetJobs();
            break;
        
        case 'get_job':
            apiGetJob();
            break;
        
        case 'get_job_results':
            apiGetJobResults();
            break;
        
        // Worker Management
        case 'get_workers':
            apiGetWorkers();
            break;
        
        case 'get_worker_stats':
            apiGetWorkerStats();
            break;
        
        case 'spawn_workers':
            apiSpawnWorkers();
            break;
        
        // Queue Management
        case 'get_queue_stats':
            apiGetQueueStats();
            break;
        
        // Error Management
        case 'get_errors':
            apiGetErrors();
            break;
        
        case 'resolve_error':
            apiResolveError();
            break;
        
        // System Status
        case 'get_system_status':
            apiGetSystemStatus();
            break;
        
        // Health Check
        case 'health':
            apiHealth();
            break;
        
        default:
            apiError('Unknown action: ' . htmlspecialchars($action), 404);
    }
} catch (Exception $e) {
    apiError($e->getMessage(), 500);
}

// ============================================================================
// API Handlers
// ============================================================================

function apiCreateJob(): void {
    // Get input (support both JSON and form data)
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $data = array_merge($_POST, $input);
    
    $query = $data['query'] ?? '';
    $apiKey = $data['api_key'] ?? '';
    $maxResults = (int)($data['max_results'] ?? 100);
    $country = $data['country'] ?? null;
    $emailFilter = $data['email_filter'] ?? 'all';
    $workerCount = (int)($data['worker_count'] ?? 0);
    
    if (!$query || !$apiKey) {
        apiError('query and api_key are required', 400);
    }
    
    // Use a default user ID if not authenticated (for API access)
    // In production, you'd want proper API authentication
    $userId = Auth::getUserId() ?? 1;
    
    // Create job
    $jobId = Job::create($userId, $query, $apiKey, $maxResults, $country, $emailFilter);
    
    // Calculate optimal worker count if not specified
    if ($workerCount <= 0) {
        $workerCount = Worker::calculateOptimalWorkerCount($maxResults);
    }
    
    // Cap at 300 workers
    $workerCount = min($workerCount, 300);
    
    // Create queue items for parallel processing
    $db = Database::connect();
    $itemsPerWorker = (int)ceil($maxResults / $workerCount);
    
    for ($i = 0; $i < $workerCount; $i++) {
        $startOffset = $i * $itemsPerWorker;
        $stmt = $db->prepare("INSERT INTO job_queue (job_id, start_offset, max_results, status) VALUES (?, ?, ?, 'pending')");
        $stmt->execute([$jobId, $startOffset, $itemsPerWorker]);
    }
    
    apiSuccess([
        'job_id' => $jobId,
        'worker_count' => $workerCount,
        'message' => 'Job created successfully. Use spawn_workers action to start processing.'
    ]);
}

function apiGetJobs(): void {
    $userId = Auth::getUserId() ?? 1;
    $jobs = Job::getAll($userId);
    
    // Enrich with email counts
    foreach ($jobs as &$job) {
        $job['email_count'] = Job::getEmailCount($job['id']);
    }
    
    apiSuccess(['jobs' => $jobs]);
}

function apiGetJob(): void {
    $jobId = (int)($_GET['job_id'] ?? 0);
    if (!$jobId) {
        apiError('job_id parameter required', 400);
    }
    
    $job = Job::getById($jobId);
    if (!$job) {
        apiError('Job not found', 404);
    }
    
    $job['email_count'] = Job::getEmailCount($jobId);
    
    // Get active workers for this job
    $db = Database::connect();
    $stmt = $db->prepare("SELECT * FROM workers WHERE current_job_id = ? AND status = 'running'");
    $stmt->execute([$jobId]);
    $job['active_workers'] = $stmt->fetchAll();
    
    apiSuccess(['job' => $job]);
}

function apiGetJobResults(): void {
    $jobId = (int)($_GET['job_id'] ?? 0);
    $limit = (int)($_GET['limit'] ?? 100);
    $offset = (int)($_GET['offset'] ?? 0);
    
    if (!$jobId) {
        apiError('job_id parameter required', 400);
    }
    
    $db = Database::connect();
    $stmt = $db->prepare("SELECT * FROM emails WHERE job_id = ? ORDER BY created_at DESC LIMIT ? OFFSET ?");
    $stmt->execute([$jobId, $limit, $offset]);
    $results = $stmt->fetchAll();
    
    $total = Job::getEmailCount($jobId);
    
    apiSuccess([
        'results' => $results,
        'total' => $total,
        'limit' => $limit,
        'offset' => $offset
    ]);
}

function apiGetWorkers(): void {
    $workers = Worker::getAll();
    apiSuccess(['workers' => $workers]);
}

function apiGetWorkerStats(): void {
    $stats = Worker::getStats();
    apiSuccess($stats);
}

function apiSpawnWorkers(): void {
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $data = array_merge($_POST, $input);
    
    $workerCount = (int)($data['worker_count'] ?? 10);
    $workerCount = max(1, min($workerCount, 300)); // Clamp between 1-300
    
    // Spawn workers using background processes
    $spawned = 0;
    for ($i = 0; $i < $workerCount; $i++) {
        $workerName = 'api_worker_' . uniqid() . '_' . $i;
        
        // Spawn in background
        $phpBinary = PHP_BINARY;
        $workerScript = __DIR__ . '/worker.php';
        $command = sprintf(
            '%s %s %s > /dev/null 2>&1 &',
            escapeshellarg($phpBinary),
            escapeshellarg($workerScript),
            escapeshellarg($workerName)
        );
        
        exec($command);
        $spawned++;
        
        // Small delay to prevent overwhelming system
        usleep(50000); // 50ms
    }
    
    apiSuccess([
        'spawned' => $spawned,
        'message' => "{$spawned} workers spawned in background"
    ]);
}

function apiGetQueueStats(): void {
    $db = Database::connect();
    $stmt = $db->query("
        SELECT 
            COUNT(CASE WHEN status = 'pending' THEN 1 END) as pending,
            COUNT(CASE WHEN status = 'processing' THEN 1 END) as processing,
            COUNT(CASE WHEN status = 'completed' THEN 1 END) as completed,
            COUNT(CASE WHEN status = 'failed' THEN 1 END) as failed,
            COUNT(*) as total
        FROM job_queue
    ");
    
    $stats = $stmt->fetch();
    $stats['completion_rate'] = $stats['total'] > 0 
        ? round(($stats['completed'] / $stats['total']) * 100, 2) 
        : 0;
    
    apiSuccess($stats);
}

function apiGetErrors(): void {
    $unresolvedOnly = ($_GET['unresolved_only'] ?? '1') === '1';
    $limit = (int)($_GET['limit'] ?? 50);
    
    $errors = Worker::getErrors($unresolvedOnly, $limit);
    apiSuccess(['errors' => $errors]);
}

function apiResolveError(): void {
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $data = array_merge($_POST, $input);
    
    $errorId = (int)($data['error_id'] ?? 0);
    if (!$errorId) {
        apiError('error_id required', 400);
    }
    
    Worker::resolveError($errorId);
    apiSuccess(['message' => 'Error resolved']);
}

function apiGetSystemStatus(): void {
    $db = Database::connect();
    
    // Get counts
    $jobsStmt = $db->query("SELECT COUNT(*) as count FROM jobs");
    $emailsStmt = $db->query("SELECT COUNT(*) as count FROM emails");
    $workersStmt = $db->query("SELECT COUNT(*) as count FROM workers");
    $activeWorkersStmt = $db->query("SELECT COUNT(*) as count FROM workers WHERE status = 'running'");
    
    $workerStats = Worker::getStats();
    $queueStmt = $db->query("SELECT COUNT(*) as count FROM job_queue WHERE status = 'pending'");
    
    apiSuccess([
        'total_jobs' => $jobsStmt->fetch()['count'],
        'total_emails' => $emailsStmt->fetch()['count'],
        'total_workers' => $workersStmt->fetch()['count'],
        'active_workers' => $activeWorkersStmt->fetch()['count'],
        'pending_queue_items' => $queueStmt->fetch()['count'],
        'worker_stats' => $workerStats,
        'php_version' => PHP_VERSION,
        'memory_usage' => memory_get_usage(true),
        'peak_memory' => memory_get_peak_usage(true)
    ]);
}

function apiHealth(): void {
    $db = Database::connect();
    
    // Test database
    try {
        $db->query("SELECT 1");
        $dbStatus = 'ok';
    } catch (Exception $e) {
        $dbStatus = 'error: ' . $e->getMessage();
    }
    
    apiSuccess([
        'status' => 'healthy',
        'database' => $dbStatus,
        'timestamp' => time()
    ]);
}

// ============================================================================
// Helper Functions
// ============================================================================

function apiSuccess($data, int $code = 200): void {
    http_response_code($code);
    echo json_encode([
        'success' => true,
        'data' => $data,
        'timestamp' => time()
    ], JSON_PRETTY_PRINT);
    exit;
}

function apiError(string $message, int $code = 400): void {
    http_response_code($code);
    echo json_encode([
        'success' => false,
        'error' => $message,
        'timestamp' => time()
    ], JSON_PRETTY_PRINT);
    exit;
}
