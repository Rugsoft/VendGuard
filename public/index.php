<?php

declare(strict_types=1);

/**
 * VendGuard MVP - Front Controller
 * 
 * Punto de entrada único para la aplicación web y API REST.
 * Desarrollado bajo la metodología SDD y Dogma Vanilla (PHP 8.2+ puro).
 * 
 * @author VendGuard Engineering Team
 * @license Proprietary
 */

// Configuración de cabeceras de respuesta estándar
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');

// Establecer código de respuesta HTTP 200 OK
http_response_code(200);

// Respuesta inicial de comprobación de salud (Health Check)
echo json_encode([
    'success' => true,
    'name' => 'VendGuard API',
    'version' => '1.0.0-mvp',
    'status' => 'operational',
    'message' => 'Servidor local VendGuard inicializado correctamente.',
    'timestamp' => date('c'),
    'environment' => [
        'php_version' => PHP_VERSION,
        'sapi' => PHP_SAPI,
    ]
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
