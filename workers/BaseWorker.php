<?php
/**
 * Base Worker Class
 */

require_once __DIR__ . '/../db.php';

abstract class BaseWorker {
    protected $workerId;
    protected $workerType;
    protected $pdo;
    protected $running = true;
    
    public function __construct($workerId, $workerType) {
        $this->workerId = $workerId;
        $this->workerType = $workerType;
        $this->pdo = getDbConnection();
        
        // Register worker
        $this->register();
        
        // Setup signal handlers
        pcntl_signal(SIGTERM, [$this, 'shutdown']);
        pcntl_signal(SIGINT, [$this, 'shutdown']);
    }
    
    public function register() {
        try {
            $now = date('Y-m-d H:i:s');
            // Use REPLACE for SQLite or INSERT...ON DUPLICATE KEY for MySQL
            if (DB_TYPE === 'sqlite') {
                $stmt = $this->pdo->prepare("INSERT OR REPLACE INTO workers_status (worker_id, worker_type, status, started_at, last_heartbeat) VALUES (?, ?, 'active', ?, ?)");
            } else {
                $stmt = $this->pdo->prepare("INSERT INTO workers_status (worker_id, worker_type, status, started_at, last_heartbeat) VALUES (?, ?, 'active', ?, ?) ON DUPLICATE KEY UPDATE status='active', started_at=?, last_heartbeat=?");
            }
            $params = [$this->workerId, $this->workerType, $now, $now];
            if (DB_TYPE !== 'sqlite') {
                $params[] = $now;
                $params[] = $now;
            }
            $stmt->execute($params);
        } catch (Exception $e) {
            error_log("Worker registration failed: " . $e->getMessage());
        }
    }
    
    public function updateHeartbeat($taskId = null) {
        try {
            $now = date('Y-m-d H:i:s');
            $stmt = $this->pdo->prepare("UPDATE workers_status SET last_heartbeat = ?, current_task = ? WHERE worker_id = ?");
            $stmt->execute([$now, $taskId, $this->workerId]);
            
            // Check if we should stop
            $stmt = $this->pdo->prepare("SELECT status FROM workers_status WHERE worker_id = ?");
            $stmt->execute([$this->workerId]);
            $status = $stmt->fetchColumn();
            if ($status === 'stopped') {
                $this->running = false;
            }
        } catch (Exception $e) {
            error_log("Heartbeat update failed: " . $e->getMessage());
        }
    }
    
    public function getNextTask() {
        try {
            $this->pdo->beginTransaction();
            
            $stmt = $this->pdo->prepare("SELECT * FROM queue WHERE task_type = ? AND status = 'pending' ORDER BY priority DESC, created_at ASC LIMIT 1");
            $stmt->execute([$this->workerType]);
            $task = $stmt->fetch();
            
            if ($task) {
                $now = date('Y-m-d H:i:s');
                $stmt = $this->pdo->prepare("UPDATE queue SET status = 'processing', worker_id = ?, started_at = ? WHERE id = ?");
                $stmt->execute([$this->workerId, $now, $task['id']]);
            }
            
            $this->pdo->commit();
            return $task;
        } catch (Exception $e) {
            $this->pdo->rollBack();
            error_log("Get next task failed: " . $e->getMessage());
            return null;
        }
    }
    
    public function completeTask($taskId, $success = true, $error = null) {
        try {
            $status = $success ? 'completed' : 'failed';
            $now = date('Y-m-d H:i:s');
            $stmt = $this->pdo->prepare("UPDATE queue SET status = ?, completed_at = ?, error_message = ? WHERE id = ?");
            $stmt->execute([$status, $now, $error, $taskId]);
        } catch (Exception $e) {
            error_log("Complete task failed: " . $e->getMessage());
        }
    }
    
    public function log($level, $message) {
        logMessage($level, "[{$this->workerType}:{$this->workerId}] {$message}");
    }
    
    protected function checkJobCompletion($jobId) {
        try {
            // Get job details
            $stmt = $this->pdo->prepare("SELECT target_emails, time_limit, deadline, status FROM jobs WHERE id = ?");
            $stmt->execute([$jobId]);
            $job = $stmt->fetch();
            
            if (!$job || $job['status'] === 'completed') {
                return; // Job already completed
            }
            
            // Check if deadline passed
            if ($job['deadline'] && strtotime($job['deadline']) < time()) {
                $this->completeJob($jobId, 'Time limit reached');
                return;
            }
            
            // Check if target emails reached
            if ($job['target_emails'] > 0) {
                $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM results WHERE job_id = ?");
                $stmt->execute([$jobId]);
                $emailCount = $stmt->fetchColumn();
                
                if ($emailCount >= $job['target_emails']) {
                    $this->completeJob($jobId, 'Target email count reached');
                    return;
                }
            }
        } catch (Exception $e) {
            error_log("Check job completion failed: " . $e->getMessage());
        }
    }
    
    protected function completeJob($jobId, $reason) {
        try {
            $now = date('Y-m-d H:i:s');
            $stmt = $this->pdo->prepare("UPDATE jobs SET status = 'completed', completed_at = ? WHERE id = ?");
            $stmt->execute([$now, $jobId]);
            
            // Cancel pending tasks for this job
            $stmt = $this->pdo->prepare("UPDATE queue SET status = 'cancelled' WHERE job_id = ? AND status = 'pending'");
            $stmt->execute([$jobId]);
            
            $this->log('info', "Job #{$jobId} auto-completed: {$reason}");
        } catch (Exception $e) {
            error_log("Complete job failed: " . $e->getMessage());
        }
    }
    
    public function shutdown($signal = null) {
        $this->running = false;
        
        try {
            $stmt = $this->pdo->prepare("UPDATE workers_status SET status = 'stopped' WHERE worker_id = ?");
            $stmt->execute([$this->workerId]);
        } catch (Exception $e) {
            error_log("Worker shutdown failed: " . $e->getMessage());
        }
        
        exit(0);
    }
    
    public function run() {
        $this->log('info', 'Worker started');
        $idleCount = 0;
        $maxIdleIterations = 10; // Stop after 10 idle iterations (20 seconds)
        
        while ($this->running) {
            pcntl_signal_dispatch();
            
            $task = $this->getNextTask();
            
            if ($task) {
                $idleCount = 0; // Reset idle counter
                $this->updateHeartbeat($task['id']);
                $this->log('info', "Processing task #{$task['id']}");
                
                try {
                    $this->processTask($task);
                    $this->completeTask($task['id'], true);
                    $this->log('info', "✓ Task #{$task['id']} completed successfully");
                } catch (Exception $e) {
                    $this->completeTask($task['id'], false, $e->getMessage());
                    $this->log('error', "✗ Task #{$task['id']} failed: " . $e->getMessage());
                }
                
                $this->updateHeartbeat();
                
                // Check job completion after each task
                if (isset($task['job_id'])) {
                    $this->checkJobCompletion($task['job_id']);
                }
            } else {
                // No tasks available
                $idleCount++;
                
                if ($idleCount >= $maxIdleIterations) {
                    $this->log('info', "No tasks available after {$maxIdleIterations} checks. Worker auto-stopping to save resources.");
                    $this->running = false;
                    break;
                }
                
                // Sleep briefly (reduced from 2s to 1s for faster response)
                sleep(1);
                $this->updateHeartbeat();
            }
        }
        
        $this->log('info', 'Worker stopped');
        $this->shutdown();
    }
    
    abstract protected function processTask($task);
}
