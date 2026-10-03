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
    // El despacho de estáticos NO delega en el servidor web embebido con
    // `return false`: ese atajo es justo el que usa el contenedor de producción
    // (`php -S ... public/index.php`, ver Dockerfile), y al ceder el fichero al
    // servidor se perdía la cabecera `Cache-Control` que se añade más abajo. El
    // resultado era que el fallback no protegía el escenario para el que existe.
    // Servimos el fichero nosotros mismos, que además garantiza el MIME correcto.

    // Servir con cabecera MIME correcta
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

    // Sin `Cache-Control`, el navegador puede seguir sirviendo un módulo JS
    // antiguo después de un despliegue, de modo que una corrección desplegada no
    // se ve hasta que el usuario purga la caché a mano. El código fuente se sirve
    // tal cual (Dogma Vanilla: sin empaquetador ni hash de contenido), así que la
    // política honesta es revalidar en cada carga.
    if (in_array($ext, ['js', 'mjs', 'css'], true)) {
        header('Cache-Control: no-cache, must-revalidate');
    }
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
