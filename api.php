<?php
/**
 * API Endpoint for AJAX Progress Updates
 */

require_once 'auth.php';
require_once 'db.php';

header('Content-Type: application/json');

$user = requireAuth();
$pdo = getDbConnection();

$action = $_GET['action'] ?? '';

try {
    switch ($action) {
        case 'stats':
            // Get overall statistics
            $stats = [
                'total_jobs' => 0,
                'active_jobs' => 0,
                'total_emails' => 0,
                'active_workers' => 0,
                'pending_tasks' => 0,
                'processing_tasks' => 0,
                'completed_tasks' => 0,
                'emails_per_minute' => 0
            ];
            
            $stmt = $pdo->query("SELECT COUNT(*) as count FROM jobs");
            $stats['total_jobs'] = $stmt->fetch()['count'];
            
            $stmt = $pdo->query("SELECT COUNT(*) as count FROM jobs WHERE status IN ('running', 'pending')");
            $stats['active_jobs'] = $stmt->fetch()['count'];
            
            $stmt = $pdo->query("SELECT COUNT(*) as count FROM emails");
            $stats['total_emails'] = $stmt->fetch()['count'];
            
            $stmt = $pdo->query("SELECT COUNT(*) as count FROM workers_status WHERE status = 'active'");
            $stats['active_workers'] = $stmt->fetch()['count'];
            
            $stmt = $pdo->query("SELECT COUNT(*) as count FROM queue WHERE status = 'pending'");
            $stats['pending_tasks'] = $stmt->fetch()['count'];
            
            $stmt = $pdo->query("SELECT COUNT(*) as count FROM queue WHERE status = 'processing'");
            $stats['processing_tasks'] = $stmt->fetch()['count'];
            
            $stmt = $pdo->query("SELECT COUNT(*) as count FROM queue WHERE status = 'completed'");
            $stats['completed_tasks'] = $stmt->fetch()['count'];
            
            // Calculate emails per minute (last 5 minutes)
            $stmt = $pdo->query("SELECT COUNT(*) as count FROM emails WHERE created_at >= datetime('now', '-5 minutes')");
            $recentEmails = $stmt->fetch()['count'];
            $stats['emails_per_minute'] = round($recentEmails / 5, 1);
            
            echo json_encode(['success' => true, 'data' => $stats]);
            break;
            
        case 'job_progress':
            $jobId = $_GET['job_id'] ?? 0;
            
            if (!$jobId) {
                echo json_encode(['success' => false, 'error' => 'Job ID required']);
                exit;
            }
            
            // Get job details
            $stmt = $pdo->prepare("SELECT * FROM jobs WHERE id = ?");
            $stmt->execute([$jobId]);
            $job = $stmt->fetch();
            
            if (!$job) {
                echo json_encode(['success' => false, 'error' => 'Job not found']);
                exit;
            }
            
            // Get task counts
            $stmt = $pdo->prepare("SELECT status, COUNT(*) as count FROM queue WHERE job_id = ? GROUP BY status");
            $stmt->execute([$jobId]);
            $taskCounts = [];
            while ($row = $stmt->fetch()) {
                $taskCounts[$row['status']] = $row['count'];
            }
            
            $totalTasks = array_sum($taskCounts);
            $completedTasks = $taskCounts['completed'] ?? 0;
            $progress = $totalTasks > 0 ? round(($completedTasks / $totalTasks) * 100, 1) : 0;
            
            // Update job progress
            $stmt = $pdo->prepare("UPDATE jobs SET progress = ? WHERE id = ?");
            $stmt->execute([$progress, $jobId]);
            
            $data = [
                'job' => $job,
                'tasks' => $taskCounts,
                'total_tasks' => $totalTasks,
                'completed_tasks' => $completedTasks,
                'progress' => $progress,
                'emails_collected' => $job['total_emails']
            ];
            
            echo json_encode(['success' => true, 'data' => $data]);
            break;
            
        case 'workers':
            // Get worker status
            $stmt = $pdo->query("SELECT * FROM workers_status ORDER BY worker_type, worker_id");
            $workers = $stmt->fetchAll();
            
            $workersByType = [
                'discover' => 0,
                'extract' => 0,
                'generate' => 0
            ];
            
            foreach ($workers as $worker) {
                if ($worker['status'] === 'active') {
                    $workersByType[$worker['worker_type']]++;
                }
            }
            
            echo json_encode(['success' => true, 'data' => [
                'workers' => $workers,
                'counts' => $workersByType
            ]]);
            break;
            
        case 'recent_emails':
            $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 10;
            
            $stmt = $pdo->prepare("SELECT e.*, j.name as job_name FROM emails e LEFT JOIN jobs j ON e.job_id = j.id ORDER BY e.created_at DESC LIMIT ?");
            $stmt->execute([$limit]);
            $emails = $stmt->fetchAll();
            
            echo json_encode(['success' => true, 'data' => $emails]);
            break;
            
        default:
            echo json_encode(['success' => false, 'error' => 'Invalid action']);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
