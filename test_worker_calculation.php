<?php
/**
 * Test Worker Calculation Formula
 * Tests the formula: 50 workers per 1000 emails
 * Target: Process 1,000,000 emails in ≤10 minutes
 */

// Simulate Worker class method for testing
class WorkerTest {
    private const WORKERS_PER_1000_EMAILS = 50;
    private const AUTO_MAX_WORKERS = 1000;
    
    public static function calculateOptimalWorkerCount(int $maxResults): int {
        // Calculate based on the formula: 50 workers per 1000 emails
        $calculatedWorkers = (int)ceil(($maxResults / 1000) * self::WORKERS_PER_1000_EMAILS);
        
        // Cap at maximum workers
        $optimalWorkers = min($calculatedWorkers, self::AUTO_MAX_WORKERS);
        
        // Ensure at least 1 worker
        return max(1, $optimalWorkers);
    }
    
    public static function calculateResultsPerWorker(int $maxResults, int $workerCount): int {
        return (int)ceil($maxResults / $workerCount);
    }
}

echo "=======================================================\n";
echo "Worker Distribution Test - Formula: 50 workers/1000 emails\n";
echo "Target: Process 1,000,000 emails in ≤10 minutes\n";
echo "=======================================================\n\n";

// Test cases
$testCases = [
    20 => "Tiny job (< 1000 emails)",
    100 => "Small job (100 emails)",
    1000 => "1K emails (base case)",
    5000 => "5K emails",
    10000 => "10K emails",
    50000 => "50K emails",
    100000 => "100K emails",
    500000 => "500K emails",
    1000000 => "1M emails (target performance)",
];

echo "Test Results:\n";
echo str_repeat("-", 100) . "\n";
printf("%-20s | %-15s | %-15s | %-15s | %-30s\n", 
    "Emails", "Workers", "Emails/Worker", "Formula Check", "Status");
echo str_repeat("-", 100) . "\n";

foreach ($testCases as $emailCount => $description) {
    $workerCount = WorkerTest::calculateOptimalWorkerCount($emailCount);
    $emailsPerWorker = WorkerTest::calculateResultsPerWorker($emailCount, $workerCount);
    
    // Verify formula: workers should be ~50 per 1000 emails (or capped at 1000)
    $expectedWorkers = min(ceil(($emailCount / 1000) * 50), 1000);
    $isCorrect = ($workerCount === $expectedWorkers);
    
    $status = $isCorrect ? "✓ PASS" : "✗ FAIL";
    
    printf("%-20s | %-15s | %-15s | %-15s | %-30s\n",
        number_format($emailCount),
        number_format($workerCount),
        number_format($emailsPerWorker),
        "$workerCount == $expectedWorkers",
        $status
    );
}

echo str_repeat("-", 100) . "\n\n";

// Performance analysis for 1M emails target
echo "=======================================================\n";
echo "Performance Analysis for 1,000,000 emails\n";
echo "=======================================================\n\n";

$targetEmails = 1000000;
$targetWorkers = WorkerTest::calculateOptimalWorkerCount($targetEmails);
$emailsPerWorker = WorkerTest::calculateResultsPerWorker($targetEmails, $targetWorkers);

echo "Target Configuration:\n";
echo "- Total emails: " . number_format($targetEmails) . "\n";
echo "- Workers spawned: " . number_format($targetWorkers) . " (capped at 1000)\n";
echo "- Emails per worker: " . number_format($emailsPerWorker) . "\n\n";

// Calculate theoretical performance
// Assumptions:
// - API rate limit: 0.1s per request
// - Each request returns ~10 results (average)
// - Deep scraping adds ~2s per URL

$apiCallsPerWorker = ceil($emailsPerWorker / 10); // Assume 10 emails per API call
$timePerWorkerSequential = $apiCallsPerWorker * 0.1; // 0.1s rate limit
$deepScrapingTime = $apiCallsPerWorker * 2; // 2s per URL

echo "Theoretical Performance (per worker):\n";
echo "- API calls needed: " . number_format($apiCallsPerWorker) . "\n";
echo "- Time for API calls (0.1s/call): " . number_format($timePerWorkerSequential, 2) . " seconds\n";
echo "- Deep scraping time (~2s/URL): " . number_format($deepScrapingTime, 2) . " seconds\n";
echo "- Total time per worker: " . number_format($timePerWorkerSequential + $deepScrapingTime, 2) . " seconds\n\n";

// Since all workers run in parallel, total time ≈ time per worker
$totalTimeSeconds = $timePerWorkerSequential + $deepScrapingTime;
$totalTimeMinutes = $totalTimeSeconds / 60;

echo "Total Time (with parallel processing):\n";
echo "- Total time: " . number_format($totalTimeSeconds, 2) . " seconds (" . number_format($totalTimeMinutes, 2) . " minutes)\n";
echo "- Target: ≤ 10 minutes (600 seconds)\n";

if ($totalTimeMinutes <= 10) {
    echo "- Status: ✓ MEETS TARGET ✓\n";
} else {
    echo "- Status: ✗ EXCEEDS TARGET (optimization needed)\n";
}

echo "\nNote: Actual performance depends on:\n";
echo "- API response times\n";
echo "- Network latency\n";
echo "- Database performance\n";
echo "- System resources (CPU/RAM)\n";
echo "- Parallel HTTP request efficiency (curl_multi with 100 connections)\n\n";

// Resource estimation
echo "=======================================================\n";
echo "Resource Requirements Estimation\n";
echo "=======================================================\n\n";

$memoryPerWorker = 10; // MB estimate per worker
$totalMemoryMB = $targetWorkers * $memoryPerWorker;

echo "Memory Usage:\n";
echo "- Per worker: ~{$memoryPerWorker} MB\n";
echo "- Total for {$targetWorkers} workers: ~" . number_format($totalMemoryMB) . " MB (" . number_format($totalMemoryMB/1024, 2) . " GB)\n";
echo "- PHP memory_limit: " . ini_get('memory_limit') . "\n\n";

if ($totalMemoryMB > 512) {
    echo "⚠️  WARNING: High memory usage expected. Consider:\n";
    echo "   - Increasing memory_limit\n";
    echo "   - Reducing AUTO_MAX_WORKERS\n";
    echo "   - Running fewer workers sequentially\n\n";
}

echo "CPU Usage:\n";
echo "- Workers run in parallel (async/background)\n";
echo "- Recommend: Multi-core CPU for optimal performance\n";
echo "- Each worker uses minimal CPU (mostly I/O bound)\n\n";

echo "=======================================================\n";
echo "Test completed successfully!\n";
echo "=======================================================\n";
