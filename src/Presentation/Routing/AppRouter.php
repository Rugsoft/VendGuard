<?php

declare(strict_types=1);

namespace VendGuard\Presentation\Routing;

use VendGuard\Presentation\Http\Request;
use VendGuard\Presentation\Http\Response;

/**
 * AppRouter
 * 
 * Factoría central de rutas de la aplicación VendGuard.
 * Registra endpoints del portal de sede, panel de coordinación, ruta técnica y cron.
 */
class AppRouter
{
    public static function create(): Router
    {
        $router = new Router();

        // -----------------------------------------------------------------
        // 1. Rutas de Diagnóstico y Health Check (T-01)
        // -----------------------------------------------------------------
        $healthHandler = function (Request $request): Response {
            return Response::json([
                'name' => 'VendGuard API',
                'version' => '1.0.0-mvp',
                'status' => 'operational',
                'message' => 'Servidor local VendGuard inicializado correctamente.',
                'timestamp' => date('c'),
                'environment' => [
                    'php_version' => PHP_VERSION,
                    'sapi' => PHP_SAPI,
                ],
            ]);
        };

        $router->get('/', $healthHandler);
        $router->get('/api/health', $healthHandler);

        // -----------------------------------------------------------------
        // 2. Módulo de Autenticación y Acceso (T-20)
        // -----------------------------------------------------------------
        $router->post('/api/auth/site-login', [\VendGuard\Presentation\Controller\AuthController::class, 'siteLogin']);
        $router->post('/api/auth/login', [\VendGuard\Presentation\Controller\AuthController::class, 'login']);

        return $router;
    }
}
