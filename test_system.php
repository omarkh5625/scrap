#!/usr/bin/env php
<?php

/**
 * Test Script - Verify system functionality
 * Usage: php test_system.php
 */

echo "=== Email Extraction System Tests ===\n\n";

$baseDir = __DIR__ . '/public_html';
$passed = 0;
$failed = 0;

function test($name, $callback) {
    global $passed, $failed;
    echo "Testing: $name... ";
    try {
        $result = $callback();
        if ($result) {
            echo "✓ PASSED\n";
            $passed++;
        } else {
            echo "✗ FAILED\n";
            $failed++;
        }
    } catch (Exception $e) {
        echo "✗ FAILED: " . $e->getMessage() . "\n";
        $failed++;
    }
}

// Test 1: Check directory structure
test("Directory structure", function() use ($baseDir) {
    $dirs = ['api', 'core', 'workers', 'storage'];
    foreach ($dirs as $dir) {
        if (!is_dir("$baseDir/$dir")) {
            return false;
        }
    }
    return true;
});

// Test 2: Check core files exist
test("Core files exist", function() use ($baseDir) {
    $files = [
        'core/BloomFilter.php',
        'core/EmailHasher.php',
        'core/PageFilter.php',
        'core/Storage.php',
        'core/Extractor.php',
        'core/JobManager.php',
        'core/WorkerManager.php',
        'core/SearchEngine.php',
        'core/Router.php'
    ];
    foreach ($files as $file) {
        if (!file_exists("$baseDir/$file")) {
            return false;
        }
    }
    return true;
});

// Test 3: Test BloomFilter
test("BloomFilter functionality", function() use ($baseDir) {
    require_once "$baseDir/core/BloomFilter.php";
    
    $bloom = new BloomFilter(1000, 0.01, '/tmp/test_bloom.bin');
    
    // Add some items
    $bloom->add('test1');
    $bloom->add('test2');
    
    // Check contains
    if (!$bloom->contains('test1')) return false;
    if (!$bloom->contains('test2')) return false;
    if ($bloom->contains('test3')) return false; // Should not contain
    
    // Save and load
    $bloom->save();
    $bloom2 = new BloomFilter(1000, 0.01, '/tmp/test_bloom.bin');
    if (!$bloom2->contains('test1')) return false;
    
    // Cleanup
    $bloom->clear();
    
    return true;
});

// Test 4: Test EmailHasher
test("EmailHasher functionality", function() use ($baseDir) {
    require_once "$baseDir/core/EmailHasher.php";
    
    // Valid email with valid domain
    $hash = EmailHasher::hashEmail('test@validcompany.com');
    if (!$hash || strlen($hash) !== 64) return false; // SHA256 = 64 chars
    
    // Invalid email
    if (EmailHasher::hashEmail('invalid') !== null) return false;
    
    // Fake domain - should return null
    if (EmailHasher::hashEmail('test@example.com') !== null) return false;
    
    // Extract domain
    $domain = EmailHasher::extractDomain('user@validcompany.com');
    if ($domain !== 'validcompany.com') return false;
    
    // Extract emails from text
    $text = 'Contact us at info@company.com or sales@business.org';
    $emails = EmailHasher::extractEmails($text);
    if (count($emails) < 1) return false;
    
    return true;
});

// Test 5: Test PageFilter
test("PageFilter functionality", function() use ($baseDir) {
    require_once "$baseDir/core/PageFilter.php";
    
    // Valid size
    $content = str_repeat('x', 3000);
    if (!PageFilter::isValidSize($content)) return false;
    
    // Too small
    $content = str_repeat('x', 1000);
    if (PageFilter::isValidSize($content)) return false;
    
    // Too large
    $content = str_repeat('x', 6000000);
    if (PageFilter::isValidSize($content)) return false;
    
    // Valid content type
    if (!PageFilter::isValidContentType('text/html')) return false;
    if (PageFilter::isValidContentType('application/pdf')) return false;
    
    return true;
});

// Test 6: Test Storage
test("Storage functionality", function() use ($baseDir) {
    require_once "$baseDir/core/Storage.php";
    
    $storage = new Storage('/tmp/test_emails.tmp', 10);
    
    // Add emails
    $storage->add('hash1', 'domain1.com');
    $storage->add('hash2', 'domain2.com');
    $storage->flush();
    
    // Read emails
    $emails = $storage->readAll();
    if (count($emails) !== 2) return false;
    if ($emails[0]['hash'] !== 'hash1') return false;
    if ($emails[1]['domain'] !== 'domain2.com') return false;
    
    // Count
    if ($storage->count() !== 2) return false;
    
    // Clear
    $storage->clear();
    if ($storage->count() !== 0) return false;
    
    return true;
});

// Test 7: Test JobManager
test("JobManager functionality", function() use ($baseDir) {
    require_once "$baseDir/core/JobManager.php";
    
    $manager = new JobManager('/tmp/test_jobs.json');
    
    // Create job
    $job = $manager->createJob([
        'keywords' => 'test',
        'search_engine' => 'google'
    ]);
    
    if (empty($job['id'])) return false;
    if ($job['status'] !== 'pending') return false;
    
    // Get job
    $retrieved = $manager->getJob($job['id']);
    if ($retrieved['id'] !== $job['id']) return false;
    
    // Update status
    $manager->updateStatus($job['id'], 'running');
    $updated = $manager->getJob($job['id']);
    if ($updated['status'] !== 'running') return false;
    
    // Update stats
    $manager->updateStats($job['id'], ['emails_found' => 100]);
    $withStats = $manager->getJob($job['id']);
    if ($withStats['stats']['emails_found'] !== 100) return false;
    
    // Delete job
    $manager->deleteJob($job['id']);
    if ($manager->getJob($job['id']) !== null) return false;
    
    return true;
});

// Test 8: Test SearchEngine
test("SearchEngine functionality", function() use ($baseDir) {
    require_once "$baseDir/core/SearchEngine.php";
    
    // Generate Google URLs
    $urls = SearchEngine::getSearchUrls('test', 'google', 50);
    if (count($urls) < 1) return false;
    if (strpos($urls[0], 'google.com') === false) return false;
    
    // Generate Bing URLs
    $urls = SearchEngine::getSearchUrls('test', 'bing', 50);
    if (count($urls) < 1) return false;
    if (strpos($urls[0], 'bing.com') === false) return false;
    
    return true;
});

// Test 9: Check worker is executable
test("Worker script executable", function() use ($baseDir) {
    $workerPath = "$baseDir/workers/worker.php";
    return file_exists($workerPath) && is_executable($workerPath);
});

// Test 10: Check API files
test("API endpoints exist", function() use ($baseDir) {
    $files = [
        'api/start_job.php',
        'api/job_status.php',
        'api/stop_job.php'
    ];
    foreach ($files as $file) {
        if (!file_exists("$baseDir/$file")) {
            return false;
        }
    }
    return true;
});

echo "\n=== Test Results ===\n";
echo "Passed: $passed\n";
echo "Failed: $failed\n";
echo "Total: " . ($passed + $failed) . "\n";

if ($failed === 0) {
    echo "\n✓ All tests passed!\n";
    exit(0);
} else {
    echo "\n✗ Some tests failed!\n";
    exit(1);
}
