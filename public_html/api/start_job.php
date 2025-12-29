<?php

/**
 * API: Start Job
 * Creates a new extraction job and starts a worker
 */

require_once __DIR__ . '/../core/JobManager.php';
require_once __DIR__ . '/../core/WorkerManager.php';
require_once __DIR__ . '/../core/Router.php';

header('Content-Type: application/json');

// Get POST data
$input = json_decode(file_get_contents('php://input'), true);

// Validate input
if (empty($input['keywords'])) {
    Router::json(['error' => 'Keywords are required'], 400);
}

$keywords = $input['keywords'];
$searchEngine = $input['search_engine'] ?? 'google';
$maxResults = isset($input['max_results']) ? (int)$input['max_results'] : 100;
$threads = isset($input['threads']) ? (int)$input['threads'] : 40;

// Validate search engine
$validEngines = ['google', 'bing', 'duckduckgo', 'yahoo'];
if (!in_array($searchEngine, $validEngines)) {
    Router::json(['error' => 'Invalid search engine'], 400);
}

// Create job
$jobManager = new JobManager();
$job = $jobManager->createJob([
    'keywords' => $keywords,
    'search_engine' => $searchEngine,
    'max_results' => $maxResults,
    'threads' => $threads
]);

// Start worker
$workerManager = new WorkerManager();
$result = $workerManager->startWorker($job['id'], $threads);

if (!$result['success']) {
    Router::json([
        'error' => 'Failed to start worker',
        'details' => $result['error']
    ], 500);
}

// Update job with worker PID
$jobManager->updateJob($job['id'], [
    'worker_pid' => $result['pid']
]);

Router::json([
    'success' => true,
    'job' => [
        'id' => $job['id'],
        'status' => $job['status'],
        'worker_pid' => $result['pid']
    ]
]);
