<?php

/**
 * API: Stop Job
 * Stop a running job
 */

require_once __DIR__ . '/../core/JobManager.php';
require_once __DIR__ . '/../core/Router.php';

header('Content-Type: application/json');

// Get POST data
$input = json_decode(file_get_contents('php://input'), true);

// Get job ID
$jobId = $input['job_id'] ?? $_GET['job_id'] ?? null;

if (empty($jobId)) {
    Router::json(['error' => 'Job ID is required'], 400);
}

// Stop job
$jobManager = new JobManager();
$result = $jobManager->stopJob($jobId);

if (!$result) {
    Router::json(['error' => 'Failed to stop job or job not found'], 400);
}

Router::json([
    'success' => true,
    'message' => 'Job stopped successfully'
]);
