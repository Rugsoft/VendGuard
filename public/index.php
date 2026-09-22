<?php

declare(strict_types=1);

/**
 * VendGuard MVP - Front Controller
 * 
 * Punto de entrada único para la aplicación web y API REST.
 * Desarrollado bajo la metodología SDD y Dogma Vanilla (PHP 8.2+ puro).
 */

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
