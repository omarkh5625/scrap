<?php

/**
 * Job Manager - Handles job creation, status tracking, and lifecycle
 */
class JobManager {
    
    private $jobsFile;
    
    public function __construct($jobsFile = null) {
        $this->jobsFile = $jobsFile ?? __DIR__ . '/../storage/jobs.json';
        
        // Ensure storage directory exists
        $dir = dirname($this->jobsFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        
        // Initialize jobs file if it doesn't exist
        if (!file_exists($this->jobsFile)) {
            file_put_contents($this->jobsFile, json_encode([]));
        }
    }
    
    /**
     * Create a new job
     * @param array $params Job parameters (keywords, search_engine, max_results, etc.)
     * @return array Job data
     */
    public function createJob($params) {
        $jobId = $this->generateJobId();
        
        $job = [
            'id' => $jobId,
            'status' => 'pending',
            'created_at' => time(),
            'updated_at' => time(),
            'params' => $params,
            'stats' => [
                'urls_processed' => 0,
                'emails_found' => 0,
                'emails_unique' => 0,
                'start_time' => null,
                'end_time' => null,
                'duration' => null
            ],
            'worker_pid' => null,
            'error' => null
        ];
        
        $this->saveJob($job);
        
        return $job;
    }
    
    /**
     * Get job by ID
     * @param string $jobId
     * @return array|null
     */
    public function getJob($jobId) {
        $jobs = $this->loadJobs();
        return $jobs[$jobId] ?? null;
    }
    
    /**
     * Update job
     * @param string $jobId
     * @param array $updates
     * @return bool
     */
    public function updateJob($jobId, $updates) {
        $jobs = $this->loadJobs();
        
        if (!isset($jobs[$jobId])) {
            return false;
        }
        
        $jobs[$jobId] = array_merge($jobs[$jobId], $updates);
        $jobs[$jobId]['updated_at'] = time();
        
        return $this->saveJobs($jobs);
    }
    
    /**
     * Update job status
     * @param string $jobId
     * @param string $status
     * @return bool
     */
    public function updateStatus($jobId, $status) {
        return $this->updateJob($jobId, ['status' => $status]);
    }
    
    /**
     * Update job stats
     * @param string $jobId
     * @param array $stats
     * @return bool
     */
    public function updateStats($jobId, $stats) {
        $job = $this->getJob($jobId);
        if (!$job) {
            return false;
        }
        
        $currentStats = $job['stats'] ?? [];
        $newStats = array_merge($currentStats, $stats);
        
        return $this->updateJob($jobId, ['stats' => $newStats]);
    }
    
    /**
     * Stop a job
     * @param string $jobId
     * @return bool
     */
    public function stopJob($jobId) {
        $job = $this->getJob($jobId);
        if (!$job) {
            return false;
        }
        
        // Kill worker process if running
        if (!empty($job['worker_pid'])) {
            $pid = (int)$job['worker_pid'];
            if ($pid > 0 && $this->isProcessRunning($pid)) {
                posix_kill($pid, SIGTERM);
            }
        }
        
        return $this->updateStatus($jobId, 'stopped');
    }
    
    /**
     * Get all jobs
     * @return array
     */
    public function getAllJobs() {
        return $this->loadJobs();
    }
    
    /**
     * Delete a job
     * @param string $jobId
     * @return bool
     */
    public function deleteJob($jobId) {
        $jobs = $this->loadJobs();
        
        if (!isset($jobs[$jobId])) {
            return false;
        }
        
        unset($jobs[$jobId]);
        return $this->saveJobs($jobs);
    }
    
    /**
     * Check if process is running
     * @param int $pid
     * @return bool
     */
    private function isProcessRunning($pid) {
        if (!function_exists('posix_kill')) {
            return false;
        }
        
        return posix_kill($pid, 0);
    }
    
    /**
     * Generate unique job ID
     * @return string
     */
    private function generateJobId() {
        return uniqid('job_', true);
    }
    
    /**
     * Load all jobs from storage
     * @return array
     */
    private function loadJobs() {
        if (!file_exists($this->jobsFile)) {
            return [];
        }
        
        $content = file_get_contents($this->jobsFile);
        $jobs = json_decode($content, true);
        
        return is_array($jobs) ? $jobs : [];
    }
    
    /**
     * Save all jobs to storage
     * @param array $jobs
     * @return bool
     */
    private function saveJobs($jobs) {
        $json = json_encode($jobs, JSON_PRETTY_PRINT);
        return file_put_contents($this->jobsFile, $json, LOCK_EX) !== false;
    }
    
    /**
     * Save single job
     * @param array $job
     * @return bool
     */
    private function saveJob($job) {
        $jobs = $this->loadJobs();
        $jobs[$job['id']] = $job;
        return $this->saveJobs($jobs);
    }
}
