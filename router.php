<?php
/**
 * router.php — Dev server router for PHP built-in server
 *
 * Maps /api/cv/* requests to api.php and serves static files directly.
 * Usage: php -S localhost:8000 router.php
 */

$uri = $_SERVER['REQUEST_URI'];
$path = parse_url($uri, PHP_URL_PATH);

// Route API requests to api.php
if (str_starts_with($path, '/api/')) {
    require __DIR__ . '/api.php';
    return true;
}

// Serve static files directly (html, css, js, etc.)
if ($path !== '/' && file_exists(__DIR__ . $path)) {
    return false; // let the built-in server handle it
}

// Default: serve index.html
require __DIR__ . '/index.html';
