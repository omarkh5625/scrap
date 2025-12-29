#!/usr/bin/env php
<?php

/**
 * Performance Benchmark Script
 * Tests system performance and validates targets
 * Usage: php benchmark.php
 */

echo "=== Email Extraction System Performance Benchmark ===\n\n";

require_once __DIR__ . '/public_html/core/BloomFilter.php';
require_once __DIR__ . '/public_html/core/EmailHasher.php';
require_once __DIR__ . '/public_html/core/Storage.php';
require_once __DIR__ . '/public_html/core/PageFilter.php';
require_once __DIR__ . '/public_html/core/Extractor.php';

// Performance targets
$TARGETS = [
    'bloom_ops_per_sec' => 100000,
    'hash_ops_per_sec' => 50000,
    'storage_ops_per_sec' => 10000,
    'email_extraction_per_sec' => 1000,
];

function benchmark($name, $iterations, $callback) {
    echo "Benchmarking: $name ($iterations iterations)...\n";
    
    $start = microtime(true);
    
    for ($i = 0; $i < $iterations; $i++) {
        $callback($i);
    }
    
    $elapsed = microtime(true) - $start;
    $opsPerSec = round($iterations / $elapsed);
    $timePerOp = round(($elapsed / $iterations) * 1000000, 2);
    
    echo "  Total time: " . round($elapsed, 3) . " seconds\n";
    echo "  Operations/sec: " . number_format($opsPerSec) . "\n";
    echo "  Time/operation: {$timePerOp} µs\n\n";
    
    return $opsPerSec;
}

// Benchmark 1: Bloom Filter
echo "1. Bloom Filter Performance\n";
echo "============================\n";

$bloom = new BloomFilter(100000, 0.01, '/tmp/bench_bloom.bin');
$bloom->clear();

$bloomAddOps = benchmark('Bloom Filter - Add operations', 10000, function($i) use ($bloom) {
    $bloom->add("email_$i");
});

$bloomCheckOps = benchmark('Bloom Filter - Contains operations', 10000, function($i) use ($bloom) {
    $bloom->contains("email_$i");
});

$target = $TARGETS['bloom_ops_per_sec'];
$status = $bloomCheckOps >= $target ? '✓ PASS' : '✗ FAIL';
echo "Target: " . number_format($target) . " ops/sec - $status\n\n";

// Benchmark 2: Email Hashing
echo "2. Email Hashing Performance\n";
echo "=============================\n";

$hashOps = benchmark('Email hashing', 10000, function($i) {
    EmailHasher::hashEmail("user{$i}@company.com");
});

$target = $TARGETS['hash_ops_per_sec'];
$status = $hashOps >= $target ? '✓ PASS' : '✗ FAIL';
echo "Target: " . number_format($target) . " ops/sec - $status\n\n";

// Benchmark 3: Storage
echo "3. Storage Performance\n";
echo "======================\n";

$storage = new Storage('/tmp/bench_emails.tmp', 100);
$storage->clear();

$storageOps = benchmark('Storage - Add operations', 10000, function($i) use ($storage) {
    $storage->add("hash_$i", "domain$i.com");
});

$storage->flush();
$storage->clear();

$target = $TARGETS['storage_ops_per_sec'];
$status = $storageOps >= $target ? '✓ PASS' : '✗ FAIL';
echo "Target: " . number_format($target) . " ops/sec - $status\n\n";

// Benchmark 4: Email Extraction
echo "4. Email Extraction Performance\n";
echo "================================\n";

$sampleText = <<<TEXT
Contact us at info@company.com, sales@business.org
Support: support@tech.net, help@service.io
Marketing: marketing@agency.com, ads@digital.co
TEXT;

$extractOps = benchmark('Email extraction from text', 5000, function($i) use ($sampleText) {
    EmailHasher::extractEmails($sampleText);
});

$target = $TARGETS['email_extraction_per_sec'];
$status = $extractOps >= $target ? '✓ PASS' : '✗ FAIL';
echo "Target: " . number_format($target) . " ops/sec - $status\n\n";

// Benchmark 5: Page Filter
echo "5. Page Filter Performance\n";
echo "===========================\n";

$validContent = str_repeat('x', 3000);
$filterOps = benchmark('Page size validation', 10000, function($i) use ($validContent) {
    PageFilter::isValidSize($validContent);
});

echo "Page filter ops/sec: " . number_format($filterOps) . "\n\n";

// Benchmark 6: Parallel HTTP (if internet available)
echo "6. Parallel HTTP Performance\n";
echo "=============================\n";

echo "Testing parallel request capability...\n";

// Create mock URLs
$urls = [];
for ($i = 0; $i < 10; $i++) {
    $urls[] = "https://httpbin.org/delay/0";
}

$extractor = new Extractor(10, 5);
$start = microtime(true);

try {
    $results = $extractor->fetchParallel($urls);
    $elapsed = microtime(true) - $start;
    
    $successCount = count(array_filter($results, function($r) {
        return $r['success'];
    }));
    
    echo "  Fetched: " . count($results) . " URLs\n";
    echo "  Successful: $successCount\n";
    echo "  Total time: " . round($elapsed, 2) . " seconds\n";
    echo "  Average: " . round($elapsed / count($urls) * 1000, 2) . " ms/URL\n";
    echo "  Requests/sec: " . round(count($urls) / $elapsed) . "\n\n";
} catch (Exception $e) {
    echo "  ⚠ HTTP test skipped (no internet or blocked)\n\n";
}

// Summary
echo "=== Benchmark Summary ===\n\n";

$results = [
    'Bloom Filter (check)' => [$bloomCheckOps, $TARGETS['bloom_ops_per_sec']],
    'Email Hashing' => [$hashOps, $TARGETS['hash_ops_per_sec']],
    'Storage' => [$storageOps, $TARGETS['storage_ops_per_sec']],
    'Email Extraction' => [$extractOps, $TARGETS['email_extraction_per_sec']],
];

$allPassed = true;
foreach ($results as $name => $data) {
    list($actual, $target) = $data;
    $percentage = round(($actual / $target) * 100);
    $status = $actual >= $target ? '✓' : '✗';
    $allPassed = $allPassed && ($actual >= $target);
    
    echo sprintf("  %s %-25s: %10s / %10s ops/sec (%3d%%) %s\n",
        $status,
        $name,
        number_format($actual),
        number_format($target),
        $percentage,
        $percentage >= 100 ? '🚀' : '⚠️'
    );
}

echo "\n";

if ($allPassed) {
    echo "✓ All benchmarks passed! System meets performance targets.\n";
} else {
    echo "⚠ Some benchmarks below target. Consider:\n";
    echo "  - Running on a more powerful server\n";
    echo "  - Enabling PHP OPcache\n";
    echo "  - Using PHP 8.x for better performance\n";
    echo "  - Adjusting PHP memory_limit\n";
}

echo "\nEstimated performance:\n";
echo "----------------------\n";

// Calculate estimated emails per minute
$emailsPerSec = min(
    $bloomCheckOps / 2,  // Bloom filter overhead
    $hashOps / 2,        // Hashing overhead
    $storageOps / 10     // Storage overhead (batch)
);

$emailsPerMin = $emailsPerSec * 60;
echo "Estimated emails/minute: ~" . number_format(round($emailsPerMin)) . "\n";

$targetEmailsPerMin = 35000;
if ($emailsPerMin >= $targetEmailsPerMin) {
    echo "✓ Meets target of " . number_format($targetEmailsPerMin) . " emails/minute\n";
} else {
    $shortfall = $targetEmailsPerMin - $emailsPerMin;
    echo "⚠ Below target by ~" . number_format(round($shortfall)) . " emails/minute\n";
}

// Cleanup
$bloom->clear();
$storage->clear();

echo "\n=== Benchmark Complete ===\n";
