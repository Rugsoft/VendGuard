<?php

declare(strict_types=1);

namespace VendGuard\Presentation\Routing;

use VendGuard\Presentation\Controller\CoordinatorRefundController;
use VendGuard\Presentation\Controller\CoordinatorRouteMapController;
use VendGuard\Presentation\Controller\LocationRefundController;
use VendGuard\Presentation\Controller\PublicRefundController;
use VendGuard\Presentation\Controller\TechnicianRefundController;
use VendGuard\Presentation\Controller\TechnicianRouteMapController;
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
        // Reintegros de sede (Módulo 08: T-REF-13); datos de pago protegidos por DTO y middleware.
        $router->get('/api/location/refunds', [LocationRefundController::class, 'index'], [$siteAuth]);
        $router->post('/api/location/refunds/{id}/deliver', [LocationRefundController::class, 'deliver'], [$siteAuth]);

        $router->get('/api/locations/{site_code}/machines', [\VendGuard\Presentation\Controller\LocationPortalController::class, 'getMachines'], [$siteAuth]);
        $router->post('/api/incidents', [\VendGuard\Presentation\Controller\LocationPortalController::class, 'createIncident'], [$siteAuth]);
        $router->post('/api/incidents/{ticket_code}/comments', [\VendGuard\Presentation\Controller\LocationPortalController::class, 'addComment'], [$siteAuth]);
        $router->get('/api/incidents/{ticket_code}/comments', [\VendGuard\Presentation\Controller\LocationPortalController::class, 'getComments'], [$siteAuth]);
        $router->post('/api/incidents/{ticket_code}/reopen', [\VendGuard\Presentation\Controller\LocationPortalController::class, 'reopenIncident'], [$siteAuth]);

        // Mantenimiento Preventivo y Certificados Sanitarios de Sede (Módulo 05: Sede - T-PREV-15)
        $router->get('/api/site/sanitary-status', [\VendGuard\Presentation\Controller\SiteSanitaryController::class, 'getSanitaryStatus'], [$siteAuth]);
        $router->get('/api/site/certificates/machine/{code}', [\VendGuard\Presentation\Controller\SiteSanitaryController::class, 'getMachineCertificate'], [$siteAuth]);
        $router->get('/api/site/certificates/global', [\VendGuard\Presentation\Controller\SiteSanitaryController::class, 'getGlobalCertificate'], [$siteAuth]);

        // -----------------------------------------------------------------
        // 4. Módulo de Coordinación y Triaje (T-25, T-26, T-27)
        // -----------------------------------------------------------------
        $coordinatorAuth = new \VendGuard\Presentation\Http\Middleware\InternalAuthMiddleware(
            \VendGuard\Core\Domain\Model\UserRole::COORDINATOR
        );
        // Bandeja de reintegros y decisiones financieras (Módulo 08: T-REF-13).
        // La protección del rol se aplica en middleware ANTES del controlador,
        // porque estos endpoints serializan IBAN y teléfono Bizum (Art. V.4).
        $router->get('/api/coordinator/refunds', [CoordinatorRefundController::class, 'index'], [$coordinatorAuth]);
        $router->post('/api/coordinator/refunds/{id}/approve', [CoordinatorRefundController::class, 'approve'], [$coordinatorAuth]);
        $router->post('/api/coordinator/refunds/{id}/pay', [CoordinatorRefundController::class, 'pay'], [$coordinatorAuth]);
        $router->post('/api/coordinator/refunds/{id}/reject', [CoordinatorRefundController::class, 'reject'], [$coordinatorAuth]);
        // Válvula de regularización: dictamina un expediente atascado en
        // PENDING_INSPECTION (avería cancelada o técnico ya fuera de la máquina).
        $router->post('/api/coordinator/refunds/{id}/regularize', [CoordinatorRefundController::class, 'regularize'], [$coordinatorAuth]);

        $router->get('/api/coordinator/map/active-incidents', [CoordinatorRouteMapController::class, 'getActiveIncidents'], [$coordinatorAuth]);
        $router->get('/api/coordinator/route/settings', [CoordinatorRouteMapController::class, 'getRouteSettings'], [$coordinatorAuth]);
        $router->put('/api/coordinator/route/settings', [CoordinatorRouteMapController::class, 'updateRouteSettings'], [$coordinatorAuth]);
        $router->get('/api/coordinator/incidents', [\VendGuard\Presentation\Controller\CoordinatorController::class, 'getIncidents'], [$coordinatorAuth]);
        $router->patch('/api/coordinator/incidents/{id}/assign', [\VendGuard\Presentation\Controller\CoordinatorController::class, 'assignTechnician'], [$coordinatorAuth]);
        $router->patch('/api/coordinator/incidents/{id}/cancel', [\VendGuard\Presentation\Controller\CoordinatorController::class, 'cancelIncident'], [$coordinatorAuth]);
        // Modal de detalle integral de triaje (Módulo 09: T-IDM-07). La ficha y su
        // bitácora exigen rol COORDINATOR en el middleware; el propio controlador
        // refuerza el blindaje y enmascara los datos de pago (Art. V.4).
        $router->get('/api/coordinator/incidents/{id}/detail', [\VendGuard\Presentation\Controller\CoordinatorController::class, 'getIncidentDetail'], [$coordinatorAuth]);
        $router->post('/api/coordinator/incidents/{id}/comments', [\VendGuard\Presentation\Controller\CoordinatorController::class, 'addComment'], [$coordinatorAuth]);
        // Rutas de Administración Integral (Módulo 04: Sedes, Máquinas y Personal)
        // Sedes (Locations)
        $router->get('/api/coordinator/locations', [\VendGuard\Presentation\Controller\CoordinatorAdminController::class, 'listLocations'], [$coordinatorAuth]);
        $router->post('/api/coordinator/locations', [\VendGuard\Presentation\Controller\CoordinatorAdminController::class, 'createLocation'], [$coordinatorAuth]);
        $router->get('/api/coordinator/locations/{id}', [\VendGuard\Presentation\Controller\CoordinatorAdminController::class, 'getLocation'], [$coordinatorAuth]);
        $router->patch('/api/coordinator/locations/{id}', [\VendGuard\Presentation\Controller\CoordinatorAdminController::class, 'updateLocation'], [$coordinatorAuth]);
        $router->put('/api/coordinator/locations/{id}', [\VendGuard\Presentation\Controller\CoordinatorAdminController::class, 'updateLocation'], [$coordinatorAuth]);
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

        // Mantenimiento Preventivo y Checklists Sanitarios (Módulo 05: Coordinación - T-PREV-13)
        $router->get('/api/coordinator/preventive/dashboard', [\VendGuard\Presentation\Controller\CoordinatorPreventiveController::class, 'getDashboard'], [$coordinatorAuth]);
        $router->get('/api/coordinator/preventive/orders', [\VendGuard\Presentation\Controller\CoordinatorPreventiveController::class, 'listOrders'], [$coordinatorAuth]);
        $router->get('/api/coordinator/preventive/orders/{id}/detail', [\VendGuard\Presentation\Controller\CoordinatorPreventiveController::class, 'getOrderDetail'], [$coordinatorAuth]);
        $router->post('/api/coordinator/preventive/orders', [\VendGuard\Presentation\Controller\CoordinatorPreventiveController::class, 'createOrder'], [$coordinatorAuth]);
        $router->post('/api/coordinator/preventive/generate-due', [\VendGuard\Presentation\Controller\CoordinatorPreventiveController::class, 'generateDue'], [$coordinatorAuth]);
        $router->patch('/api/coordinator/preventive/orders/{id}/assign', [\VendGuard\Presentation\Controller\CoordinatorPreventiveController::class, 'assignOrder'], [$coordinatorAuth]);
        $router->patch('/api/coordinator/preventive/orders/{id}/cancel', [\VendGuard\Presentation\Controller\CoordinatorPreventiveController::class, 'cancelOrder'], [$coordinatorAuth]);
        $router->get('/api/coordinator/preventive/settings', [\VendGuard\Presentation\Controller\CoordinatorPreventiveController::class, 'getSettings'], [$coordinatorAuth]);
        $router->patch('/api/coordinator/preventive/settings', [\VendGuard\Presentation\Controller\CoordinatorPreventiveController::class, 'updateSettings'], [$coordinatorAuth]);
        $router->get('/api/coordinator/machines/{id}/preventive-config', [\VendGuard\Presentation\Controller\CoordinatorPreventiveController::class, 'getMachinePreventiveConfig'], [$coordinatorAuth]);
        $router->patch('/api/coordinator/machines/{id}/preventive-config', [\VendGuard\Presentation\Controller\CoordinatorPreventiveController::class, 'updateMachinePreventiveConfig'], [$coordinatorAuth]);

        // Gestión de Repuestos y Trazabilidad (Módulo 06: Coordinación - T-SPARE-10, T-SPARE-13)
        $router->get('/api/coordinator/spare-parts', [\VendGuard\Presentation\Controller\CoordinatorSparePartsController::class, 'getCatalog'], [$coordinatorAuth]);
        $router->post('/api/coordinator/spare-parts', [\VendGuard\Presentation\Controller\CoordinatorSparePartsController::class, 'createPart'], [$coordinatorAuth]);
        $router->get('/api/coordinator/spare-parts/models', [\VendGuard\Presentation\Controller\CoordinatorSparePartsController::class, 'getModels'], [$coordinatorAuth]);
        $router->get('/api/coordinator/spare-parts/analytics', [\VendGuard\Presentation\Controller\CoordinatorSparePartsController::class, 'getAnalytics'], [$coordinatorAuth]);
        $router->get('/api/coordinator/spare-parts/export', [\VendGuard\Presentation\Controller\CoordinatorSparePartsController::class, 'exportCsv'], [$coordinatorAuth]);
        $router->get('/api/coordinator/spare-parts/requests/pending-review', [\VendGuard\Presentation\Controller\CoordinatorSparePartsController::class, 'getPendingReviewRequests'], [$coordinatorAuth]);
        $router->put('/api/coordinator/spare-parts/{id}', [\VendGuard\Presentation\Controller\CoordinatorSparePartsController::class, 'updatePart'], [$coordinatorAuth]);
        $router->patch('/api/coordinator/spare-parts/{id}/status', [\VendGuard\Presentation\Controller\CoordinatorSparePartsController::class, 'toggleStatus'], [$coordinatorAuth]);
        $router->get('/api/coordinator/spare-parts/{id}', [\VendGuard\Presentation\Controller\CoordinatorSparePartsController::class, 'getPart'], [$coordinatorAuth]);
        $router->delete('/api/coordinator/spare-parts/{id}', [\VendGuard\Presentation\Controller\CoordinatorSparePartsController::class, 'deletePart'], [$coordinatorAuth]);

        // -----------------------------------------------------------------
        // 5. Módulo de Técnico de Campo / "Mi Ruta" (T-28, T-29)
        // -----------------------------------------------------------------
        $technicianAuth = new \VendGuard\Presentation\Http\Middleware\InternalAuthMiddleware(
            \VendGuard\Core\Domain\Model\UserRole::TECHNICIAN
        );
        $router->get('/api/technician/route/map', [TechnicianRouteMapController::class, 'getRouteMap'], [$technicianAuth]);
        $router->get('/api/technician/my-route', [\VendGuard\Presentation\Controller\TechnicianController::class, 'getMyRoute'], [$technicianAuth]);
        $router->get('/api/technician/my-metrics', [\VendGuard\Presentation\Controller\TechnicianMetricsController::class, 'getMyMetrics'], [$technicianAuth]);
        $router->patch('/api/technician/incidents/{id}/start', [\VendGuard\Presentation\Controller\TechnicianController::class, 'startIntervention'], [$technicianAuth]);
        $router->patch('/api/technician/incidents/{id}/pause', [\VendGuard\Presentation\Controller\TechnicianController::class, 'pauseIntervention'], [$technicianAuth]);
        // Consulta del reintegro vinculado con DTO sin datos bancarios (Art. V.4).
        $router->get('/api/technician/incidents/{id}/refund', [TechnicianRefundController::class, 'show'], [$technicianAuth]);
        $router->post('/api/technician/incidents/{id}/resolve', [\VendGuard\Presentation\Controller\TechnicianController::class, 'resolveIncident'], [$technicianAuth]);
        $router->patch('/api/technician/{id}/start', [\VendGuard\Presentation\Controller\TechnicianController::class, 'startIntervention'], [$technicianAuth]);
        $router->patch('/api/technician/{id}/pause', [\VendGuard\Presentation\Controller\TechnicianController::class, 'pauseIntervention'], [$technicianAuth]);
        $router->post('/api/technician/{id}/resolve', [\VendGuard\Presentation\Controller\TechnicianController::class, 'resolveIncident'], [$technicianAuth]);

        // Mantenimiento Preventivo y Checklists Sanitarios (Módulo 05: Técnico - T-PREV-14)
        $router->get('/api/technician/preventive/route', [\VendGuard\Presentation\Controller\TechnicianPreventiveController::class, 'getRoute'], [$technicianAuth]);
        $router->post('/api/technician/preventive/orders/{id}/claim', [\VendGuard\Presentation\Controller\TechnicianPreventiveController::class, 'claimOrder'], [$technicianAuth]);
        $router->get('/api/technician/preventive/orders/{id}/checklist', [\VendGuard\Presentation\Controller\TechnicianPreventiveController::class, 'getChecklist'], [$technicianAuth]);
        $router->post('/api/technician/preventive/orders/{id}/start', [\VendGuard\Presentation\Controller\TechnicianPreventiveController::class, 'startInspection'], [$technicianAuth]);
        $router->post('/api/technician/preventive/orders/{id}/complete', [\VendGuard\Presentation\Controller\TechnicianPreventiveController::class, 'completeInspection'], [$technicianAuth]);
        $router->post('/api/technician/preventive/orders/{id}/reinspect', [\VendGuard\Presentation\Controller\TechnicianPreventiveController::class, 'reinspectOrder'], [$technicianAuth]);

        // Gestión de Repuestos y Trazabilidad en Movilidad (Módulo 06: Técnico - T-SPARE-11, T-SPARE-13)
        $router->get('/api/technician/spare-parts/catalog', [\VendGuard\Presentation\Controller\TechnicianSparePartsController::class, 'getCatalog'], [$technicianAuth]);

        // -----------------------------------------------------------------
        // 6. Módulo de Automatización y Tareas Cron (T-30)
        // -----------------------------------------------------------------
        $router->post('/api/cron/auto-close', [\VendGuard\Presentation\Controller\CronController::class, 'autoClose']);

        // -----------------------------------------------------------------
        // 7. Módulo de Códigos QR - Rutas Públicas (T-QR-10)
        // -----------------------------------------------------------------
        $router->get('/api/qr/scan/{code}', [\VendGuard\Presentation\Controller\QrScanController::class, 'scan']);
        $router->post('/api/qr/report', [\VendGuard\Presentation\Controller\QrScanController::class, 'report']);

        // Seguimiento público por token (Módulo 08: T-REF-13). No requiere
        // sesión: el token criptográfico es la credencial del consumidor.
        $router->get('/api/public/refunds/track', [PublicRefundController::class, 'track']);
        $router->patch('/api/public/refunds/track', [PublicRefundController::class, 'rectify']);

        return $router;
    }
}

