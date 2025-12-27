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
            $stmt = $this->pdo->prepare("INSERT OR REPLACE INTO workers_status (worker_id, worker_type, status, started_at, last_heartbeat) VALUES (?, ?, 'active', datetime('now'), datetime('now'))");
            $stmt->execute([$this->workerId, $this->workerType]);
        } catch (Exception $e) {
            error_log("Worker registration failed: " . $e->getMessage());
        }
    }
    
    public function updateHeartbeat($taskId = null) {
        try {
            $stmt = $this->pdo->prepare("UPDATE workers_status SET last_heartbeat = datetime('now'), current_task = ? WHERE worker_id = ?");
            $stmt->execute([$taskId, $this->workerId]);
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
                $stmt = $this->pdo->prepare("UPDATE queue SET status = 'processing', worker_id = ?, started_at = datetime('now') WHERE id = ?");
                $stmt->execute([$this->workerId, $task['id']]);
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
            $stmt = $this->pdo->prepare("UPDATE queue SET status = ?, completed_at = datetime('now'), error_message = ? WHERE id = ?");
            $stmt->execute([$status, $error, $taskId]);
        } catch (Exception $e) {
            error_log("Complete task failed: " . $e->getMessage());
        }
    }
    
    public function log($level, $message) {
        logMessage($level, "[{$this->workerType}:{$this->workerId}] {$message}");
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
        
        while ($this->running) {
            pcntl_signal_dispatch();
            
            $task = $this->getNextTask();
            
            if ($task) {
                $this->updateHeartbeat($task['id']);
                $this->log('info', "Processing task #{$task['id']}");
                
                try {
                    $this->processTask($task);
                    $this->completeTask($task['id'], true);
                    $this->log('info', "Task #{$task['id']} completed");
                } catch (Exception $e) {
                    $this->completeTask($task['id'], false, $e->getMessage());
                    $this->log('error', "Task #{$task['id']} failed: " . $e->getMessage());
                }
                
                $this->updateHeartbeat();
            } else {
                // No tasks available, sleep briefly
                sleep(2);
                $this->updateHeartbeat();
            }
        }
        
        $this->log('info', 'Worker stopped');
    }
    
    abstract protected function processTask($task);
}
