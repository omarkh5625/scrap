<?php

/**
 * Configuration File
 * Copy this to config.php and customize for your environment
 */

return [
    // Storage paths
    'storage' => [
        'jobs_file' => __DIR__ . '/public_html/storage/jobs.json',
        'emails_file' => __DIR__ . '/public_html/storage/emails.tmp',
        'bloom_file' => __DIR__ . '/public_html/storage/bloom.bin',
    ],
    
    // Performance settings
    'performance' => [
        'max_parallel_requests' => 240,  // Maximum parallel HTTP requests
        'default_threads' => 40,         // Default worker threads
        'timeout' => 10,                  // HTTP timeout in seconds
        'connect_timeout' => 5,          // HTTP connect timeout in seconds
        'batch_size' => 1000,            // Storage batch size
    ],
    
    // Bloom filter settings
    'bloom_filter' => [
        'expected_elements' => 1000000,  // Expected number of emails
        'false_positive_rate' => 0.01,   // 1% false positive rate
    ],
    
    // Page filter settings
    'page_filter' => [
        'min_size' => 2048,              // 2 KB minimum
        'max_size' => 5242880,           // 5 MB maximum
    ],
    
    // Search engines
    'search_engines' => [
        'default' => 'google',
        'available' => ['google', 'bing', 'duckduckgo', 'yahoo'],
    ],
    
    // Fake domains to ignore
    'fake_domains' => [
        'example.com', 'example.org', 'example.net',
        'test.com', 'test.org', 'test.net',
        'domain.com', 'domain.org', 'domain.net',
        'sample.com', 'sample.org', 'sample.net',
        'demo.com', 'demo.org', 'demo.net',
        'localhost', 'localhost.localdomain',
        'invalid', 'invalid.invalid',
        'yoursite.com', 'yourdomain.com',
        'email.com', 'mail.com'
    ],
    
    // Worker settings
    'worker' => [
        'script_path' => __DIR__ . '/public_html/workers/worker.php',
        'php_binary' => PHP_BINARY,
    ],
    
    // Security settings
    'security' => [
        'hash_algorithm' => 'sha256',
        'ssl_verify' => false,  // Disabled for performance
    ],
    
    // UI settings
    'ui' => [
        'title' => 'نظام استخراج الإيميلات',
        'subtitle' => 'نظام احترافي لاستخراج 100,000+ إيميل في أقل من 3 دقائق',
        'language' => 'ar',
        'direction' => 'rtl',
    ],
    
    // API settings
    'api' => [
        'base_path' => '/api',
        'rate_limit' => false,  // Set to true to enable rate limiting
    ],
    
    // Logging
    'logging' => [
        'enabled' => true,
        'level' => 'info',  // debug, info, warning, error
        'file' => __DIR__ . '/public_html/storage/app.log',
    ],
    
    // Performance targets
    'targets' => [
        'emails_per_minute' => 35000,
        'parallel_requests' => 240,
        'time_for_100k' => 180,  // 3 minutes in seconds
    ],
];
