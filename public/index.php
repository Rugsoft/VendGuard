<?php

declare(strict_types=1);

/**
 * VendGuard MVP - Front Controller & Static Asset Dispatcher
 * 
 * Punto de entrada único para la aplicación web, assets estáticos y API REST.
 * Desarrollado bajo la metodología SDD y Dogma Vanilla (PHP 8.2+ puro).
 */

// 1. Despachar ficheros estáticos existentes (/assets/, /uploads/, etc.)
$requestUri = $_SERVER['REQUEST_URI'] ?? '/';
$path = parse_url($requestUri, PHP_URL_PATH);
$filePath = __DIR__ . $path;

if ($path !== '/' && $path !== '/index.php' && is_file($filePath)) {
    // Si corre en el servidor web embebido de PHP (php -S)
    if (php_sapi_name() === 'cli-server') {
        return false;
    }

    // Servir con cabecera MIME correcta para cualquier otro entorno
    $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
    $mimeTypes = [
        'js'   => 'application/javascript; charset=UTF-8',
        'mjs'  => 'application/javascript; charset=UTF-8',
        'css'  => 'text/css; charset=UTF-8',
        'svg'  => 'image/svg+xml',
        'png'  => 'image/png',
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'webp' => 'image/webp',
        'json' => 'application/json; charset=UTF-8',
        'ico'  => 'image/x-icon',
        'woff2'=> 'font/woff2',
    ];
    $contentType = $mimeTypes[$ext] ?? 'application/octet-stream';
    header("Content-Type: {$contentType}");
    header('Content-Length: ' . (string)filesize($filePath));
    readfile($filePath);
    exit;
}

require_once __DIR__ . '/../src/autoload.php';

use VendGuard\Presentation\Http\Request;
use VendGuard\Presentation\Routing\AppRouter;

// Cabeceras de seguridad y CORS
if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('X-XSS-Protection: 1; mode=block');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, PATCH, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
}

// Inicializar enrutador y despachar petición
$router = AppRouter::create();
$request = Request::fromGlobals();
$response = $router->dispatch($request);
$response->send();
