<?php

/**
 * Router for PHP built-in development server.
 * 
 * Usage: php -S localhost:8080 -t public public/router.php
 * 
 * This routes all requests to index.php unless the file exists on disk.
 */

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// Serve static files directly if they exist
if ($uri !== '/' && file_exists(__DIR__ . $uri)) {
    return false;
}

// Route everything else to the front controller
require __DIR__ . '/index.php';
