<?php

/**
 * API: Job Status
 * Get status of a job
 */

require_once __DIR__ . '/../core/JobManager.php';
require_once __DIR__ . '/../core/Router.php';

header('Content-Type: application/json');

// Get job ID from query string
$jobId = $_GET['job_id'] ?? null;

if (empty($jobId)) {
    Router::json(['error' => 'Job ID is required'], 400);
}

// Get job
$jobManager = new JobManager();
$job = $jobManager->getJob($jobId);

if (!$job) {
    Router::json(['error' => 'Job not found'], 404);
}

Router::json([
    'success' => true,
    'job' => $job
]);
