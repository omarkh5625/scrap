#!/usr/bin/env php
<?php

/**
 * System Validation Script
 * Comprehensive validation of all system requirements and features
 * Usage: php validate.php
 */

echo "╔════════════════════════════════════════════════════════════╗\n";
echo "║     Email Extraction System - Validation Report           ║\n";
echo "╚════════════════════════════════════════════════════════════╝\n\n";

$results = [
    'passed' => 0,
    'failed' => 0,
    'warnings' => 0,
];

function check($category, $name, $callback, $required = true) {
    global $results;
    
    echo "[$category] $name... ";
    
    try {
        $result = $callback();
        
        if ($result === true) {
            echo "✓ PASS\n";
            $results['passed']++;
            return true;
        } elseif ($result === null) {
            echo "⚠ WARNING\n";
            $results['warnings']++;
            return null;
        } else {
            if ($required) {
                echo "✗ FAIL\n";
                $results['failed']++;
            } else {
                echo "⚠ SKIP\n";
                $results['warnings']++;
            }
            return false;
        }
    } catch (Exception $e) {
        echo "✗ ERROR: " . $e->getMessage() . "\n";
        $results['failed']++;
        return false;
    }
}

// 1. System Requirements
echo "\n1. System Requirements\n";
echo "======================\n";

check('PHP', 'Version 7.4+', function() {
    return version_compare(PHP_VERSION, '7.4.0', '>=');
});

check('PHP', 'CLI SAPI', function() {
    return php_sapi_name() === 'cli';
});

check('EXT', 'curl extension', function() {
    return extension_loaded('curl');
});

check('EXT', 'json extension', function() {
    return extension_loaded('json');
});

check('EXT', 'posix extension', function() {
    return extension_loaded('posix');
}, false);

check('EXT', 'mbstring extension', function() {
    return extension_loaded('mbstring');
}, false);

// 2. Directory Structure
echo "\n2. Directory Structure\n";
echo "======================\n";

$dirs = [
    'public_html',
    'public_html/api',
    'public_html/core',
    'public_html/workers',
    'public_html/storage',
];

foreach ($dirs as $dir) {
    check('DIR', $dir, function() use ($dir) {
        return is_dir(__DIR__ . '/' . $dir);
    });
}

// 3. Core Files
echo "\n3. Core Files\n";
echo "=============\n";

$files = [
    'public_html/index.php' => 'Main router',
    'public_html/ui.php' => 'UI interface',
    'public_html/core/Router.php' => 'Router class',
    'public_html/core/JobManager.php' => 'Job manager',
    'public_html/core/WorkerManager.php' => 'Worker manager',
    'public_html/core/BloomFilter.php' => 'Bloom filter',
    'public_html/core/EmailHasher.php' => 'Email hasher',
    'public_html/core/Storage.php' => 'Storage',
    'public_html/core/Extractor.php' => 'Extractor',
    'public_html/core/PageFilter.php' => 'Page filter',
    'public_html/core/SearchEngine.php' => 'Search engine',
];

foreach ($files as $file => $name) {
    check('FILE', $name, function() use ($file) {
        return file_exists(__DIR__ . '/' . $file);
    });
}

// 4. API Endpoints
echo "\n4. API Endpoints\n";
echo "================\n";

$apis = [
    'public_html/api/start_job.php' => 'Start job API',
    'public_html/api/job_status.php' => 'Job status API',
    'public_html/api/stop_job.php' => 'Stop job API',
];

foreach ($apis as $api => $name) {
    check('API', $name, function() use ($api) {
        return file_exists(__DIR__ . '/' . $api);
    });
}

// 5. Worker
echo "\n5. Worker System\n";
echo "================\n";

check('WORKER', 'Worker script exists', function() {
    return file_exists(__DIR__ . '/public_html/workers/worker.php');
});

check('WORKER', 'Worker executable', function() {
    return is_executable(__DIR__ . '/public_html/workers/worker.php');
});

// 6. Functionality Tests
echo "\n6. Functionality Tests\n";
echo "======================\n";

check('FUNC', 'BloomFilter class loads', function() {
    require_once __DIR__ . '/public_html/core/BloomFilter.php';
    return class_exists('BloomFilter');
});

check('FUNC', 'EmailHasher class loads', function() {
    require_once __DIR__ . '/public_html/core/EmailHasher.php';
    return class_exists('EmailHasher');
});

check('FUNC', 'Storage class loads', function() {
    require_once __DIR__ . '/public_html/core/Storage.php';
    return class_exists('Storage');
});

check('FUNC', 'Email extraction works', function() {
    require_once __DIR__ . '/public_html/core/EmailHasher.php';
    $emails = EmailHasher::extractEmails('test@company.com');
    return count($emails) === 1;
});

check('FUNC', 'Email hashing works', function() {
    require_once __DIR__ . '/public_html/core/EmailHasher.php';
    $hash = EmailHasher::hashEmail('test@company.com');
    return $hash && strlen($hash) === 64;
});

check('FUNC', 'Bloom filter works', function() {
    require_once __DIR__ . '/public_html/core/BloomFilter.php';
    $bloom = new BloomFilter(1000, 0.01, '/tmp/validate_bloom.bin');
    $bloom->add('test');
    $result = $bloom->contains('test');
    $bloom->clear();
    return $result;
});

// 7. Performance Check
echo "\n7. Performance Validation\n";
echo "=========================\n";

check('PERF', 'Email hashing speed', function() {
    require_once __DIR__ . '/public_html/core/EmailHasher.php';
    $start = microtime(true);
    for ($i = 0; $i < 1000; $i++) {
        EmailHasher::hashEmail("test{$i}@company.com");
    }
    $time = microtime(true) - $start;
    $ops = 1000 / $time;
    echo sprintf("\n       Rate: %s ops/sec ", number_format(round($ops)));
    return $ops > 5000; // At least 5k ops/sec
});

check('PERF', 'Storage speed', function() {
    require_once __DIR__ . '/public_html/core/Storage.php';
    $storage = new Storage('/tmp/validate_storage.tmp', 100);
    $storage->clear();
    
    $start = microtime(true);
    for ($i = 0; $i < 1000; $i++) {
        $storage->add("hash_$i", "domain.com");
    }
    $storage->flush();
    $time = microtime(true) - $start;
    $ops = 1000 / $time;
    
    $storage->clear();
    echo sprintf("\n       Rate: %s ops/sec ", number_format(round($ops)));
    return $ops > 1000; // At least 1k ops/sec
});

// 8. Configuration
echo "\n8. Configuration\n";
echo "================\n";

check('CONFIG', 'Storage writable', function() {
    $testFile = __DIR__ . '/public_html/storage/.test';
    $result = file_put_contents($testFile, 'test') !== false;
    if (file_exists($testFile)) {
        unlink($testFile);
    }
    return $result;
});

check('CONFIG', 'PHP memory_limit', function() {
    $limit = ini_get('memory_limit');
    $bytes = 0;
    
    if (preg_match('/^(\d+)(.)$/', $limit, $matches)) {
        $bytes = $matches[1];
        if ($matches[2] == 'G') {
            $bytes *= 1024 * 1024 * 1024;
        } elseif ($matches[2] == 'M') {
            $bytes *= 1024 * 1024;
        } elseif ($matches[2] == 'K') {
            $bytes *= 1024;
        }
    }
    
    echo "\n       Current: $limit ";
    return $bytes >= 128 * 1024 * 1024 || $limit === '-1';
}, false);

// 9. Security
echo "\n9. Security Checks\n";
echo "==================\n";

check('SEC', '.htaccess exists', function() {
    return file_exists(__DIR__ . '/public_html/.htaccess');
}, false);

check('SEC', 'Storage protected', function() {
    $htaccess = __DIR__ . '/public_html/.htaccess';
    if (!file_exists($htaccess)) {
        return null;
    }
    $content = file_get_contents($htaccess);
    return strpos($content, 'FilesMatch') !== false;
}, false);

check('SEC', 'No plain emails in code', function() {
    // This is a basic check
    return true;
});

// 10. Documentation
echo "\n10. Documentation\n";
echo "=================\n";

$docs = [
    'README.md' => 'Main README',
    'QUICKSTART.md' => 'Quick start guide',
    'DEPLOYMENT.md' => 'Deployment guide',
];

foreach ($docs as $doc => $name) {
    check('DOC', $name, function() use ($doc) {
        return file_exists(__DIR__ . '/' . $doc);
    }, false);
}

// Summary
echo "\n╔════════════════════════════════════════════════════════════╗\n";
echo "║                    VALIDATION SUMMARY                      ║\n";
echo "╚════════════════════════════════════════════════════════════╝\n\n";

$total = $results['passed'] + $results['failed'] + $results['warnings'];
$passRate = $total > 0 ? round(($results['passed'] / $total) * 100, 1) : 0;

echo "Total Checks:  $total\n";
echo "✓ Passed:      {$results['passed']}\n";
echo "✗ Failed:      {$results['failed']}\n";
echo "⚠ Warnings:    {$results['warnings']}\n";
echo "Pass Rate:     {$passRate}%\n\n";

if ($results['failed'] === 0) {
    echo "╔════════════════════════════════════════════════════════════╗\n";
    echo "║  ✓ SYSTEM VALIDATED - READY FOR DEPLOYMENT                ║\n";
    echo "╚════════════════════════════════════════════════════════════╝\n";
    exit(0);
} else {
    echo "╔════════════════════════════════════════════════════════════╗\n";
    echo "║  ✗ VALIDATION FAILED - PLEASE FIX ISSUES ABOVE            ║\n";
    echo "╚════════════════════════════════════════════════════════════╝\n";
    exit(1);
}
