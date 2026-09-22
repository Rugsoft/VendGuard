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

        // -----------------------------------------------------------------
        // 3. Módulo de Portal de Ubicación / Sede (T-21, T-22, T-23)
        // -----------------------------------------------------------------
        $siteAuth = new \VendGuard\Presentation\Http\Middleware\SiteAuthMiddleware();
        $router->get('/api/locations/{site_code}/machines', [\VendGuard\Presentation\Controller\LocationPortalController::class, 'getMachines'], [$siteAuth]);
        $router->post('/api/incidents', [\VendGuard\Presentation\Controller\LocationPortalController::class, 'createIncident'], [$siteAuth]);
        $router->post('/api/incidents/{ticket_code}/comments', [\VendGuard\Presentation\Controller\LocationPortalController::class, 'addComment'], [$siteAuth]);
        $router->get('/api/incidents/{ticket_code}/comments', [\VendGuard\Presentation\Controller\LocationPortalController::class, 'getComments'], [$siteAuth]);
        $router->post('/api/incidents/{ticket_code}/reopen', [\VendGuard\Presentation\Controller\LocationPortalController::class, 'reopenIncident'], [$siteAuth]);

        // -----------------------------------------------------------------
        // 4. Módulo de Coordinación y Triaje (T-25, T-26, T-27)
        // -----------------------------------------------------------------
        $coordinatorAuth = new \VendGuard\Presentation\Http\Middleware\InternalAuthMiddleware(
            \VendGuard\Core\Domain\Model\UserRole::COORDINATOR
        );
        $router->get('/api/coordinator/incidents', [\VendGuard\Presentation\Controller\CoordinatorController::class, 'getIncidents'], [$coordinatorAuth]);
        $router->patch('/api/coordinator/incidents/{id}/assign', [\VendGuard\Presentation\Controller\CoordinatorController::class, 'assignTechnician'], [$coordinatorAuth]);
        $router->patch('/api/coordinator/incidents/{id}/cancel', [\VendGuard\Presentation\Controller\CoordinatorController::class, 'cancelIncident'], [$coordinatorAuth]);

        // -----------------------------------------------------------------
        // 5. Módulo de Técnico de Campo / "Mi Ruta" (T-28, T-29)
        // -----------------------------------------------------------------
        $technicianAuth = new \VendGuard\Presentation\Http\Middleware\InternalAuthMiddleware(
            \VendGuard\Core\Domain\Model\UserRole::TECHNICIAN
        );
        $router->get('/api/technician/my-route', [\VendGuard\Presentation\Controller\TechnicianController::class, 'getMyRoute'], [$technicianAuth]);
        $router->patch('/api/technician/incidents/{id}/start', [\VendGuard\Presentation\Controller\TechnicianController::class, 'startIntervention'], [$technicianAuth]);
        $router->patch('/api/technician/incidents/{id}/pause', [\VendGuard\Presentation\Controller\TechnicianController::class, 'pauseIntervention'], [$technicianAuth]);
        $router->post('/api/technician/incidents/{id}/resolve', [\VendGuard\Presentation\Controller\TechnicianController::class, 'resolveIncident'], [$technicianAuth]);
        $router->patch('/api/technician/{id}/start', [\VendGuard\Presentation\Controller\TechnicianController::class, 'startIntervention'], [$technicianAuth]);
        $router->patch('/api/technician/{id}/pause', [\VendGuard\Presentation\Controller\TechnicianController::class, 'pauseIntervention'], [$technicianAuth]);
        $router->post('/api/technician/{id}/resolve', [\VendGuard\Presentation\Controller\TechnicianController::class, 'resolveIncident'], [$technicianAuth]);

        // -----------------------------------------------------------------
        // 6. Módulo de Automatización y Tareas Cron (T-30)
        // -----------------------------------------------------------------
        $router->post('/api/cron/auto-close', [\VendGuard\Presentation\Controller\CronController::class, 'autoClose']);

        return $router;
    }
}

