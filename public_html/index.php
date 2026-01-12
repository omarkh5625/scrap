<?php

/**
 * Main Router - Entry point for all requests
 */

require_once __DIR__ . '/core/Router.php';

$router = new Router();

// UI Routes
$router->get('/', function() {
    require __DIR__ . '/ui.php';
});

$router->get('/ui', function() {
    require __DIR__ . '/ui.php';
});

// API Routes
$router->post('/api/start_job', function() {
    require __DIR__ . '/api/start_job.php';
});

$router->get('/api/job_status', function() {
    require __DIR__ . '/api/job_status.php';
});

$router->post('/api/stop_job', function() {
    require __DIR__ . '/api/stop_job.php';
});

// Dispatch request
$router->dispatch();
