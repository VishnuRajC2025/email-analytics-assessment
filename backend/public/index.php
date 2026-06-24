<?php

/**
 * Email Engagement Analytics API
 * 
 * Single entry point (front controller) for:
 *   POST /events          — ingest engagement events
 *   GET  /campaigns/{id}/stats — aggregated campaign stats
 */

declare(strict_types=1);

require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/QueueService.php';
require_once __DIR__ . '/../src/EventController.php';
require_once __DIR__ . '/../src/BatchEventController.php';
require_once __DIR__ . '/../src/StatsController.php';

// CORS headers for frontend access
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json');
header('Connection: close');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Simple router
$method = $_SERVER['REQUEST_METHOD'];
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// Strip the script directory prefix so routing works under any subdirectory
$scriptDir = dirname($_SERVER['SCRIPT_NAME']);
if ($scriptDir !== '/' && $scriptDir !== '\\') {
    $uri = substr($uri, strlen($scriptDir)) ?: '/';
}

// Remove trailing slash
$uri = rtrim($uri, '/');

try {
    // POST /events
    if ($method === 'POST' && $uri === '/events') {
        $controller = new EventController();
        $controller->handlePost();
        exit;
    }

    // POST /events/batch — high-throughput batch ingestion
    if ($method === 'POST' && $uri === '/events/batch') {
        $controller = new BatchEventController();
        $controller->handlePost();
        exit;
    }

    // GET /campaigns/{id}/stats
    if ($method === 'GET' && preg_match('#^/campaigns/([^/]+)/stats$#', $uri, $matches)) {
        $campaignId = $matches[1];
        $controller = new StatsController();
        $controller->handleGet($campaignId);
        exit;
    }

    // 404 — no matching route
    http_response_code(404);
    echo json_encode(['error' => 'Not found']);
} catch (\Throwable $e) {
    error_log('Unhandled exception: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Internal server error']);
}
