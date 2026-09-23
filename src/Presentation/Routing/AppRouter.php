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
            // Si la petición proviene de un navegador web (HTML), sirve el frontend SPA
            $accept = (string)$request->getHeader('Accept');
            $htmlPath = dirname(__DIR__, 3) . '/public/index.html';
            if (str_contains($accept, 'text/html') && file_exists($htmlPath)) {
                return new Response((string)file_get_contents($htmlPath), 200, ['Content-Type' => 'text/html; charset=UTF-8']);
            }

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
        // Rutas de Administración Integral (Módulo 04: Sedes, Máquinas y Personal)
        // Sedes (Locations)
        $router->get('/api/coordinator/locations', [\VendGuard\Presentation\Controller\CoordinatorAdminController::class, 'listLocations'], [$coordinatorAuth]);
        $router->post('/api/coordinator/locations', [\VendGuard\Presentation\Controller\CoordinatorAdminController::class, 'createLocation'], [$coordinatorAuth]);
        $router->get('/api/coordinator/locations/{id}', [\VendGuard\Presentation\Controller\CoordinatorAdminController::class, 'getLocation'], [$coordinatorAuth]);
        $router->patch('/api/coordinator/locations/{id}', [\VendGuard\Presentation\Controller\CoordinatorAdminController::class, 'updateLocation'], [$coordinatorAuth]);
        $router->patch('/api/coordinator/locations/{id}/deactivate', [\VendGuard\Presentation\Controller\CoordinatorAdminController::class, 'deactivateLocation'], [$coordinatorAuth]);
        $router->patch('/api/coordinator/locations/{id}/reactivate', [\VendGuard\Presentation\Controller\CoordinatorAdminController::class, 'reactivateLocation'], [$coordinatorAuth]);

        // Máquinas (Machines)
        $router->get('/api/coordinator/machines', [\VendGuard\Presentation\Controller\CoordinatorAdminController::class, 'listMachines'], [$coordinatorAuth]);
        $router->post('/api/coordinator/machines', [\VendGuard\Presentation\Controller\CoordinatorAdminController::class, 'createMachine'], [$coordinatorAuth]);
        $router->get('/api/coordinator/machines/{id}', [\VendGuard\Presentation\Controller\CoordinatorAdminController::class, 'getMachine'], [$coordinatorAuth]);
        $router->patch('/api/coordinator/machines/{id}', [\VendGuard\Presentation\Controller\CoordinatorAdminController::class, 'updateMachine'], [$coordinatorAuth]);
        $router->patch('/api/coordinator/machines/{id}/transfer', [\VendGuard\Presentation\Controller\CoordinatorAdminController::class, 'transferMachine'], [$coordinatorAuth]);
        $router->patch('/api/coordinator/machines/{id}/deactivate', [\VendGuard\Presentation\Controller\CoordinatorAdminController::class, 'deactivateMachine'], [$coordinatorAuth]);
        $router->patch('/api/coordinator/machines/{id}/reactivate', [\VendGuard\Presentation\Controller\CoordinatorAdminController::class, 'reactivateMachine'], [$coordinatorAuth]);

        // Personal Interno (Users)
        $router->get('/api/coordinator/users', [\VendGuard\Presentation\Controller\CoordinatorAdminController::class, 'listUsers'], [$coordinatorAuth]);
        $router->post('/api/coordinator/users', [\VendGuard\Presentation\Controller\CoordinatorAdminController::class, 'createUser'], [$coordinatorAuth]);
        $router->get('/api/coordinator/users/{id}', [\VendGuard\Presentation\Controller\CoordinatorAdminController::class, 'getUser'], [$coordinatorAuth]);
        $router->patch('/api/coordinator/users/{id}', [\VendGuard\Presentation\Controller\CoordinatorAdminController::class, 'updateUser'], [$coordinatorAuth]);
        $router->patch('/api/coordinator/users/{id}/reset-password', [\VendGuard\Presentation\Controller\CoordinatorAdminController::class, 'resetUserPassword'], [$coordinatorAuth]);
        $router->patch('/api/coordinator/users/{id}/deactivate', [\VendGuard\Presentation\Controller\CoordinatorAdminController::class, 'deactivateUser'], [$coordinatorAuth]);
        $router->patch('/api/coordinator/users/{id}/reactivate', [\VendGuard\Presentation\Controller\CoordinatorAdminController::class, 'reactivateUser'], [$coordinatorAuth]);

        // Rutas existentes de Parque y QR
        $router->get('/api/coordinator/locations/{id}/machines', [\VendGuard\Presentation\Controller\CoordinatorController::class, 'getLocationMachines'], [$coordinatorAuth]);
        $router->get('/api/coordinator/machines/{id}/qr-label', [\VendGuard\Presentation\Controller\QrLabelController::class, 'getMachineLabel'], [$coordinatorAuth]);
        $router->get('/api/coordinator/locations/{id}/qr-batch', [\VendGuard\Presentation\Controller\QrLabelController::class, 'getLocationBatch'], [$coordinatorAuth]);
        $router->get('/api/coordinator/metrics/summary', [\VendGuard\Presentation\Controller\CoordinatorMetricsController::class, 'getSummary'], [$coordinatorAuth]);
        $router->get('/api/coordinator/metrics/breakdown', [\VendGuard\Presentation\Controller\CoordinatorMetricsController::class, 'getBreakdown'], [$coordinatorAuth]);
        $router->get('/api/coordinator/metrics/export', [\VendGuard\Presentation\Controller\CoordinatorMetricsController::class, 'exportMetrics'], [$coordinatorAuth]);
        $router->get('/api/coordinator/audit-log', [\VendGuard\Presentation\Controller\CoordinatorMetricsController::class, 'getAuditLog'], [$coordinatorAuth]);
        $router->get('/api/coordinator/audit-log/export', [\VendGuard\Presentation\Controller\CoordinatorMetricsController::class, 'exportAuditLog'], [$coordinatorAuth]);

        // -----------------------------------------------------------------
        // 5. Módulo de Técnico de Campo / "Mi Ruta" (T-28, T-29)
        // -----------------------------------------------------------------
        $technicianAuth = new \VendGuard\Presentation\Http\Middleware\InternalAuthMiddleware(
            \VendGuard\Core\Domain\Model\UserRole::TECHNICIAN
        );
        $router->get('/api/technician/my-route', [\VendGuard\Presentation\Controller\TechnicianController::class, 'getMyRoute'], [$technicianAuth]);
        $router->get('/api/technician/my-metrics', [\VendGuard\Presentation\Controller\TechnicianMetricsController::class, 'getMyMetrics'], [$technicianAuth]);
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

        // -----------------------------------------------------------------
        // 7. Módulo de Códigos QR - Rutas Públicas (T-QR-10)
        // -----------------------------------------------------------------
        $router->get('/api/qr/scan/{code}', [\VendGuard\Presentation\Controller\QrScanController::class, 'scan']);
        $router->post('/api/qr/report', [\VendGuard\Presentation\Controller\QrScanController::class, 'report']);

        return $router;
    }
}

