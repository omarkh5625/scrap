#!/usr/bin/env php
<?php

/**
 * Email Extraction Worker - CLI Only
 * Usage: php worker.php --job=JOB_ID --threads=40
 */

// Ensure CLI only
if (php_sapi_name() !== 'cli') {
    die('This script can only be run from command line');
}

// Load dependencies
require_once __DIR__ . '/../core/JobManager.php';
require_once __DIR__ . '/../core/Storage.php';
require_once __DIR__ . '/../core/BloomFilter.php';
require_once __DIR__ . '/../core/EmailHasher.php';
require_once __DIR__ . '/../core/Extractor.php';
require_once __DIR__ . '/../core/SearchEngine.php';
require_once __DIR__ . '/../core/PageFilter.php';

// Parse command line arguments
$options = getopt('', ['job:', 'threads::']);

if (!isset($options['job'])) {
    die("Error: --job parameter is required\n");
}

$jobId = $options['job'];
$threads = isset($options['threads']) ? (int)$options['threads'] : 40;

// Initialize components
$jobManager = new JobManager();
$storage = new Storage();
$bloomFilter = new BloomFilter();
$extractor = new Extractor($threads);

// Get job
$job = $jobManager->getJob($jobId);
if (!$job) {
    die("Error: Job {$jobId} not found\n");
}

// Update job status
$jobManager->updateStatus($jobId, 'running');
$jobManager->updateJob($jobId, ['worker_pid' => getmypid()]);
$jobManager->updateStats($jobId, ['start_time' => time()]);

echo "Worker started for job {$jobId} with {$threads} threads\n";

try {
    // Extract job parameters
    $keywords = $job['params']['keywords'] ?? '';
    $searchEngine = $job['params']['search_engine'] ?? 'google';
    $maxResults = $job['params']['max_results'] ?? 100;
    
    echo "Keywords: {$keywords}\n";
    echo "Search Engine: {$searchEngine}\n";
    echo "Max Results: {$maxResults}\n\n";
    
    // Step 1: Get search result URLs
    echo "Step 1: Fetching search results...\n";
    $searchUrls = SearchEngine::getSearchUrls($keywords, $searchEngine, $maxResults);
    echo "Generated " . count($searchUrls) . " search URLs\n";
    
    $targetUrls = [];
    $extractor->fetchParallel($searchUrls, function($result) use (&$targetUrls, $searchEngine) {
        if ($result['success']) {
            $urls = SearchEngine::parseSearchResults($result['content'], $searchEngine);
            $targetUrls = array_merge($targetUrls, $urls);
            echo ".";
        }
    });
    
    $targetUrls = array_unique($targetUrls);
    echo "\nFound " . count($targetUrls) . " target URLs to scrape\n\n";
    
    // Step 2: Extract emails from target URLs
    echo "Step 2: Extracting emails from target URLs...\n";
    
    $emailsFound = 0;
    $emailsUnique = 0;
    $urlsProcessed = 0;
    
    $extractor->fetchParallel($targetUrls, function($result) use (
        &$emailsFound, 
        &$emailsUnique, 
        &$urlsProcessed,
        $bloomFilter,
        $storage,
        $jobManager,
        $jobId
    ) {
        $urlsProcessed++;
        
        if ($result['success'] && !empty($result['emails'])) {
            foreach ($result['emails'] as $email) {
                $emailsFound++;
                
                // Hash email
                $emailHash = EmailHasher::hashEmail($email);
                if (!$emailHash) {
                    continue;
                }
                
                // Check bloom filter
                if (!$bloomFilter->contains($emailHash)) {
                    $bloomFilter->add($emailHash);
                    $emailsUnique++;
                    
                    // Store email
                    $domain = EmailHasher::extractDomain($email);
                    $storage->add($emailHash, $domain);
                    
                    echo "+";
                } else {
                    echo ".";
                }
            }
        } else {
            echo "-";
        }
        
        // Update stats every 10 URLs
        if ($urlsProcessed % 10 === 0) {
            $jobManager->updateStats($jobId, [
                'urls_processed' => $urlsProcessed,
                'emails_found' => $emailsFound,
                'emails_unique' => $emailsUnique
            ]);
        }
    });
    
    // Flush remaining emails
    $storage->flush();
    $bloomFilter->save();
    
    echo "\n\nExtraction complete!\n";
    echo "URLs processed: {$urlsProcessed}\n";
    echo "Emails found: {$emailsFound}\n";
    echo "Unique emails: {$emailsUnique}\n";
    
    // Update final stats
    $endTime = time();
    $startTime = $job['stats']['start_time'];
    $duration = $endTime - $startTime;
    
    $jobManager->updateStats($jobId, [
        'urls_processed' => $urlsProcessed,
        'emails_found' => $emailsFound,
        'emails_unique' => $emailsUnique,
        'end_time' => $endTime,
        'duration' => $duration
    ]);
    
    $jobManager->updateStatus($jobId, 'completed');
    
    echo "Duration: {$duration} seconds\n";
    
    // Calculate rate
    if ($duration > 0) {
        $rate = round($emailsUnique / ($duration / 60));
        echo "Rate: {$rate} emails/minute\n";
    }
    
} catch (Exception $e) {
    echo "\nError: " . $e->getMessage() . "\n";
    
    $jobManager->updateJob($jobId, [
        'status' => 'failed',
        'error' => $e->getMessage()
    ]);
    
    $storage->flush();
    $bloomFilter->save();
    
    exit(1);
}

exit(0);
