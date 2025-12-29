<?php declare(strict_types=1);

/**
 * Standalone Worker Script
 * 
 * This script processes email extraction jobs independently from the UI
 * Can be run from command line or spawned by the API/UI
 * 
 * Usage: php worker.php [worker_name]
 * Example: php worker.php worker_1
 */

// Prevent direct web access
if (php_sapi_name() !== 'cli' && !defined('WORKER_WEB_ALLOWED')) {
    die('This script must be run from command line');
}

// Include main app for class access
define('API_MODE', true); // Prevent UI rendering
require_once __DIR__ . '/app.php';

// Get worker name from command line or generate one
$workerName = $argv[1] ?? 'worker_' . uniqid();

// Performance settings for workers
ini_set('memory_limit', '256M'); // Lower than main app since workers are parallel
ini_set('max_execution_time', '300'); // 5 minutes max per worker

echo "🚀 Starting worker: {$workerName}\n";
echo "📅 Time: " . date('Y-m-d H:i:s') . "\n";
echo "🔧 PHP: " . PHP_VERSION . "\n";
echo "💾 Memory Limit: " . ini_get('memory_limit') . "\n";
echo str_repeat('=', 60) . "\n";

// Register worker in database
$workerId = Worker::register($workerName);
echo "✓ Registered as worker ID: {$workerId}\n";

// Main worker loop
$jobsProcessed = 0;
$maxJobs = 10; // Process up to 10 queue items before exiting (prevents workers running forever)
$startTime = time();

try {
    while ($jobsProcessed < $maxJobs) {
        // Get next job from queue
        $job = Worker::getNextJob();
        
        if (!$job) {
            echo "ℹ No jobs available in queue. Exiting.\n";
            break;
        }
        
        $jobId = $job['id'];
        $queueId = $job['queue_id'] ?? null;
        
        echo "\n" . str_repeat('-', 60) . "\n";
        echo "📋 Processing Job #{$jobId}: {$job['query']}\n";
        if ($queueId) {
            echo "   Queue Item: #{$queueId}\n";
        }
        echo "   Max Results: {$job['max_results']}\n";
        if ($job['country']) {
            echo "   Country: {$job['country']}\n";
        }
        if ($job['email_filter']) {
            echo "   Filter: {$job['email_filter']}\n";
        }
        echo str_repeat('-', 60) . "\n";
        
        // Update heartbeat
        Worker::updateHeartbeat($workerId, 'running', $jobId, 0, 0);
        
        try {
            // Process the job
            Worker::processJob($jobId);
            
            $jobsProcessed++;
            echo "✓ Job #{$jobId} completed successfully\n";
            
            // Mark queue item as complete if this was from queue
            if ($queueId) {
                Worker::markQueueItemComplete($queueId);
                Worker::checkAndUpdateJobCompletion($jobId);
            }
            
        } catch (Exception $e) {
            echo "✗ Error processing job #{$jobId}: {$e->getMessage()}\n";
            Worker::logError($workerId, $jobId, 'job_processing_error', $e->getMessage(), $e->getTraceAsString());
            
            // Mark queue item as failed if this was from queue
            if ($queueId) {
                Worker::markQueueItemFailed($queueId);
            }
        }
        
        // Small delay between jobs
        sleep(1);
    }
    
} catch (Exception $e) {
    echo "✗ Fatal worker error: {$e->getMessage()}\n";
    Worker::logError($workerId, null, 'worker_fatal_error', $e->getMessage(), $e->getTraceAsString(), 'critical');
}

// Worker cleanup
$endTime = time();
$runtime = $endTime - $startTime;

echo "\n" . str_repeat('=', 60) . "\n";
echo "🏁 Worker {$workerName} finished\n";
echo "📊 Statistics:\n";
echo "   Jobs Processed: {$jobsProcessed}\n";
echo "   Runtime: {$runtime} seconds\n";
echo "   Avg Time/Job: " . ($jobsProcessed > 0 ? round($runtime / $jobsProcessed, 2) : 0) . " seconds\n";
echo str_repeat('=', 60) . "\n";

// Update final status
Worker::updateHeartbeat($workerId, 'idle', null, 0, 0);

exit(0);
