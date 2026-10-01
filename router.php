<?php

declare(strict_types=1);

/**
 * VendGuard - PHP built-in dev server router (development only).
 *
 * Usage: php -S 127.0.0.1:8099 -t public router.php
 *
 * Static assets (JS/CSS/images/fonts) are served manually with
 * `Cache-Control: no-cache, must-revalidate` so iterative changes are picked up on a
 * plain reload instead of lingering in the browser heuristic cache (which previously
 * left operators testing stale map code after updates). Everything else (PHP
 * front-controller, API routes) is delegated to the built-in server with
 * `return false`.
 */

$path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

if (preg_match('/\.(?:js|mjs|css|png|jpe?g|svg|gif|ico|woff2?|ttf)$/i', $path)) {
    $docroot = (string) ($_SERVER['DOCUMENT_ROOT'] ?? getcwd());
    $file = realpath($docroot . $path);
    if ($file !== false && str_starts_with($file, realpath($docroot)) && is_file($file)) {
        $types = [
            'js' => 'application/javascript',
            'mjs' => 'application/javascript',
            'css' => 'text/css',
            'png' => 'image/png',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'svg' => 'image/svg+xml',
            'gif' => 'image/gif',
            'ico' => 'image/x-icon',
            'woff' => 'font/woff',
            'woff2' => 'font/woff2',
            'ttf' => 'font/ttf'
        ];
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        header('Content-Type: ' . ($types[$ext] ?? 'application/octet-stream'));
        header('Cache-Control: no-cache, must-revalidate');
        readfile($file);
        return true;
    }
}

return false;
