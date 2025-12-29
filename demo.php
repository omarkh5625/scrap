#!/usr/bin/env php
<?php

/**
 * Demo Script - Demonstrates the email extraction system
 * Usage: php demo.php
 */

echo "=== Email Extraction System Demo ===\n\n";

require_once __DIR__ . '/public_html/core/JobManager.php';
require_once __DIR__ . '/public_html/core/WorkerManager.php';
require_once __DIR__ . '/public_html/core/Storage.php';
require_once __DIR__ . '/public_html/core/BloomFilter.php';
require_once __DIR__ . '/public_html/core/EmailHasher.php';
require_once __DIR__ . '/public_html/core/Extractor.php';
require_once __DIR__ . '/public_html/core/PageFilter.php';

echo "1. Testing Email Extraction from Text\n";
echo "=====================================\n";

$sampleText = <<<TEXT
Welcome to our company! 

For sales inquiries, contact sales@techcompany.com
For support, reach out to support@techcompany.com
General questions: info@techcompany.com

You can also find us at:
- CEO: ceo@techcompany.com
- HR: hr@businesscorp.com
- Marketing: marketing@digitalagency.net

Follow us on social media or send an email to hello@startup.io

Note: Please don't email test@example.com (that's fake!)
TEXT;

echo "Sample text:\n$sampleText\n\n";

$emails = EmailHasher::extractEmails($sampleText);
echo "Extracted emails: " . count($emails) . "\n";
foreach ($emails as $email) {
    $hash = EmailHasher::hashEmail($email);
    $domain = EmailHasher::extractDomain($email);
    echo "  - $email\n";
    echo "    Hash: " . substr($hash, 0, 16) . "...\n";
    echo "    Domain: $domain\n";
}

echo "\n2. Testing Bloom Filter\n";
echo "=======================\n";

$bloom = new BloomFilter(10000, 0.01, '/tmp/demo_bloom.bin');
$bloom->clear();

echo "Adding emails to Bloom filter...\n";
$uniqueCount = 0;
foreach ($emails as $email) {
    $hash = EmailHasher::hashEmail($email);
    if ($hash && !$bloom->contains($hash)) {
        $bloom->add($hash);
        $uniqueCount++;
        echo "  + Added: $email\n";
    } else {
        echo "  - Duplicate or invalid: $email\n";
    }
}

echo "Unique emails: $uniqueCount\n";
$bloom->save();

echo "\n3. Testing Storage\n";
echo "==================\n";

$storage = new Storage('/tmp/demo_emails.tmp', 5);
$storage->clear();

echo "Storing emails...\n";
foreach ($emails as $email) {
    $hash = EmailHasher::hashEmail($email);
    if ($hash) {
        $domain = EmailHasher::extractDomain($email);
        $storage->add($hash, $domain);
        echo "  Stored: $email -> $domain\n";
    }
}

$storage->flush();
echo "\nStored emails count: " . $storage->count() . "\n";

echo "\n4. Testing Page Filter\n";
echo "======================\n";

$testCases = [
    ['size' => 1000, 'content' => str_repeat('x', 1000), 'name' => 'Too small (1KB)'],
    ['size' => 3000, 'content' => str_repeat('x', 3000), 'name' => 'Valid (3KB)'],
    ['size' => 6000000, 'content' => str_repeat('x', 6000000), 'name' => 'Too large (6MB)'],
];

foreach ($testCases as $test) {
    $valid = PageFilter::isValidSize($test['content']);
    $formattedSize = PageFilter::formatSize($test['size']);
    $status = $valid ? '✓ Valid' : '✗ Invalid';
    echo "  {$test['name']} - $formattedSize - $status\n";
}

echo "\n5. Testing Parallel HTTP Fetcher\n";
echo "=================================\n";

echo "Fetching multiple URLs in parallel...\n";

// Test with some public APIs that return JSON/HTML
$testUrls = [
    'https://httpbin.org/html',
    'https://httpbin.org/robots.txt',
    'https://httpbin.org/get',
];

$extractor = new Extractor(3, 5);
$startTime = microtime(true);

$results = $extractor->fetchParallel($testUrls, function($result) {
    $status = $result['success'] ? '✓' : '✗';
    $size = strlen($result['content'] ?? '');
    $formatted = PageFilter::formatSize($size);
    echo "  $status {$result['url']} - {$result['http_code']} - $formatted\n";
});

$elapsed = round((microtime(true) - $startTime) * 1000, 2);
echo "\nFetched " . count($results) . " URLs in {$elapsed}ms\n";
echo "Average: " . round($elapsed / count($results), 2) . "ms per URL\n";

echo "\n6. Testing Job Manager\n";
echo "======================\n";

$jobManager = new JobManager('/tmp/demo_jobs.json');

echo "Creating a test job...\n";
$job = $jobManager->createJob([
    'keywords' => 'technology companies',
    'search_engine' => 'google',
    'max_results' => 100,
    'threads' => 40
]);

echo "Job created:\n";
echo "  ID: {$job['id']}\n";
echo "  Status: {$job['status']}\n";
echo "  Created: " . date('Y-m-d H:i:s', $job['created_at']) . "\n";

echo "\nUpdating job status...\n";
$jobManager->updateStatus($job['id'], 'running');
$jobManager->updateStats($job['id'], [
    'urls_processed' => 50,
    'emails_found' => 1234,
    'emails_unique' => 987
]);

$updated = $jobManager->getJob($job['id']);
echo "Job updated:\n";
echo "  Status: {$updated['status']}\n";
echo "  URLs processed: {$updated['stats']['urls_processed']}\n";
echo "  Emails found: {$updated['stats']['emails_found']}\n";
echo "  Unique emails: {$updated['stats']['emails_unique']}\n";

echo "\n=== Demo Complete! ===\n\n";

echo "Summary:\n";
echo "--------\n";
echo "✓ Email extraction from text\n";
echo "✓ Bloom filter for deduplication\n";
echo "✓ Batch storage with hash|domain format\n";
echo "✓ Page size filtering\n";
echo "✓ Parallel HTTP requests\n";
echo "✓ Job management system\n\n";

echo "Next steps:\n";
echo "-----------\n";
echo "1. Deploy to your web server\n";
echo "2. Configure domain and virtual host\n";
echo "3. Access the web UI at http://your-domain/\n";
echo "4. Start extraction jobs via UI or API\n";
echo "5. Monitor performance and results\n\n";

// Cleanup
$bloom->clear();
$storage->clear();
$jobManager->deleteJob($job['id']);

echo "Demo cleanup complete!\n";
