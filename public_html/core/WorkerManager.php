<?php

/**
 * Worker Manager - Manages CLI worker processes
 */
class WorkerManager {
    
    private $workerScript;
    private $phpBinary;
    
    public function __construct($workerScript = null) {
        $this->workerScript = $workerScript ?? __DIR__ . '/../workers/worker.php';
        $this->phpBinary = PHP_BINARY;
    }
    
    /**
     * Start a worker for a job
     * @param string $jobId
     * @param int $threads Number of parallel threads
     * @return array Result with success status and PID
     */
    public function startWorker($jobId, $threads = 40) {
        if (!file_exists($this->workerScript)) {
            return [
                'success' => false,
                'error' => 'Worker script not found',
                'pid' => null
            ];
        }
        
        // Build command
        $command = sprintf(
            '%s %s --job=%s --threads=%d > /dev/null 2>&1 & echo $!',
            escapeshellarg($this->phpBinary),
            escapeshellarg($this->workerScript),
            escapeshellarg($jobId),
            (int)$threads
        );
        
        // Execute in background
        $output = [];
        exec($command, $output);
        
        $pid = isset($output[0]) ? (int)$output[0] : null;
        
        if ($pid > 0) {
            return [
                'success' => true,
                'pid' => $pid,
                'error' => null
            ];
        }
        
        return [
            'success' => false,
            'error' => 'Failed to start worker',
            'pid' => null
        ];
    }
    
    /**
     * Check if worker is running
     * @param int $pid
     * @return bool
     */
    public function isWorkerRunning($pid) {
        if (!$pid || $pid <= 0) {
            return false;
        }
        
        if (function_exists('posix_kill')) {
            return posix_kill($pid, 0);
        }
        
        // Fallback: check using ps command
        $output = [];
        exec("ps -p $pid", $output);
        return count($output) > 1;
    }
    
    /**
     * Stop a worker
     * @param int $pid
     * @return bool
     */
    public function stopWorker($pid) {
        if (!$pid || $pid <= 0) {
            return false;
        }
        
        if (function_exists('posix_kill')) {
            return posix_kill($pid, SIGTERM);
        }
        
        // Fallback: use kill command
        exec("kill -15 $pid 2>/dev/null", $output, $returnCode);
        return $returnCode === 0;
    }
    
    /**
     * Get worker status
     * @param int $pid
     * @return array
     */
    public function getWorkerStatus($pid) {
        $running = $this->isWorkerRunning($pid);
        
        return [
            'pid' => $pid,
            'running' => $running,
            'status' => $running ? 'running' : 'stopped'
        ];
    }
}
