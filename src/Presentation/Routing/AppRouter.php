<?php

declare(strict_types=1);

namespace VendGuard\Presentation\Routing;

use VendGuard\Presentation\Controller\CoordinatorRefundController;
use VendGuard\Presentation\Controller\CoordinatorRootCauseController;
use VendGuard\Presentation\Controller\CoordinatorRouteMapController;
use VendGuard\Presentation\Controller\CoordinatorVanRestockOrderController;
use VendGuard\Presentation\Controller\CoordinatorZoneController;
use VendGuard\Presentation\Controller\HealthController;
use VendGuard\Presentation\Controller\LocationRefundController;
use VendGuard\Presentation\Controller\PublicRefundController;
use VendGuard\Presentation\Controller\TechnicianOpportunisticController;
use VendGuard\Presentation\Controller\TechnicianOfflineSyncController;
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

        // Diagnóstico técnico con verificación real contra el motor de persistencia
        // (RF-07, EARS-07.1 a EARS-07.5; T-HYBRID-14). Es una ruta NUEVA a propósito: el
        // contrato vivo de `/api/health` —envoltura JSON de identificación del servicio y
        // servicio del documento de la aplicación ante `Accept: text/html`— queda intacto,
        // porque cinco suites lo usan como sonda de arranque y ningún consumidor se merece
        // que le cambien la carga útil debajo. La ausencia de credencial batch no rechaza
        // la petición: sólo reduce la respuesta a su proyección pública.
        $router->get('/api/status', [HealthController::class, 'check']);

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
        // Hilo de conversación del expediente (Módulo 10: T-COM-08). El portal de sede
        // jamás recibe notas internas: el filtrado y el enmascaramiento viven en el
        // servicio de aplicación, detrás de la sesión de centro (Art. V.4 / RNF-01).
        $router->get('/api/location/incidents/{id}/comments', [\VendGuard\Presentation\Controller\LocationPortalController::class, 'getComments'], [$siteAuth]);
        $router->post('/api/location/incidents/{id}/comments', [\VendGuard\Presentation\Controller\LocationPortalController::class, 'addComment'], [$siteAuth]);
        // Alias retrocompatible por código de ticket del portal de sede.
        $router->post('/api/incidents/{ticket_code}/comments', [\VendGuard\Presentation\Controller\LocationPortalController::class, 'addComment'], [$siteAuth]);
        $router->get('/api/incidents/{ticket_code}/comments', [\VendGuard\Presentation\Controller\LocationPortalController::class, 'getComments'], [$siteAuth]);
        $router->post('/api/incidents/{ticket_code}/reopen', [\VendGuard\Presentation\Controller\LocationPortalController::class, 'reopenIncident'], [$siteAuth]);
        // Encuesta de satisfacción post-resolución (Módulo 20). La sede valorada sale
        // de la sesión —el cuerpo no declara centro— y ninguna respuesta lleva técnico
        // ni importe (Art. V.4). El listado se pagina a 20 y publica el total real.
        $router->get('/api/location/satisfaction-surveys/pending', [\VendGuard\Presentation\Controller\LocationSatisfactionController::class, 'pending'], [$siteAuth]);
        $router->post('/api/location/incidents/{id}/satisfaction-survey', [\VendGuard\Presentation\Controller\LocationSatisfactionController::class, 'submit'], [$siteAuth]);

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
        // Recomendador de idoneidad (Módulo 17, T-ZONE-10): ranking explicado del
        // despacho asistido. Comparte puerta con la asignación, porque el panel y la
        // salvaguarda del Art. V.1 deben evaluar el mismo ranking.
        $router->get('/api/coordinator/incidents/{id}/suggested-technicians', [\VendGuard\Presentation\Controller\CoordinatorController::class, 'getSuggestedTechnicians'], [$coordinatorAuth]);
        $router->patch('/api/coordinator/incidents/{id}/assign', [\VendGuard\Presentation\Controller\CoordinatorController::class, 'assignTechnician'], [$coordinatorAuth]);
        $router->patch('/api/coordinator/incidents/{id}/cancel', [\VendGuard\Presentation\Controller\CoordinatorController::class, 'cancelIncident'], [$coordinatorAuth]);
        // Resolución administrativa manual (Módulo 25, QA 3): exige causa raíz y
        // solución estandarizada y congela los cinco snapshots en el expediente.
        $router->post('/api/coordinator/incidents/{id}/resolve', [\VendGuard\Presentation\Controller\CoordinatorController::class, 'resolveIncident'], [$coordinatorAuth]);
        // Pausa por bloqueo de sede, reanudación manual y cancelación por inactividad
        // (Módulo 11: T-PAUSE-13). La coordinación puede pausar cualquier ticket activo y
        // elegir si la reanudación devuelve la avería a "Asignada" o a "En curso"; la
        // cancelación ejecuta el protocolo completo de las 72 horas hábiles (RF-04.3-04.5).
        $router->post('/api/coordinator/incidents/{id}/pause-pending-info', [\VendGuard\Presentation\Controller\CoordinatorController::class, 'pausePendingInfo'], [$coordinatorAuth]);
        $router->post('/api/coordinator/incidents/{id}/resume-pending-info', [\VendGuard\Presentation\Controller\CoordinatorController::class, 'resumePendingInfo'], [$coordinatorAuth]);
        $router->post('/api/coordinator/incidents/{id}/cancel-inactivity', [\VendGuard\Presentation\Controller\CoordinatorController::class, 'cancelInactivity'], [$coordinatorAuth]);
        // Modal de detalle integral de triaje (Módulo 09: T-IDM-07). La ficha y su
        // bitácora exigen rol COORDINATOR en el middleware; el propio controlador
        // refuerza el blindaje y enmascara los datos de pago (Art. V.4).
        $router->get('/api/coordinator/incidents/{id}/detail', [\VendGuard\Presentation\Controller\CoordinatorController::class, 'getIncidentDetail'], [$coordinatorAuth]);
        // Hilo de conversación del expediente (Módulo 10: T-COM-08). Inspección total
        // del diálogo y publicación de notas internas o públicas bajo rol COORDINATOR.
        $router->get('/api/coordinator/incidents/{id}/comments', [\VendGuard\Presentation\Controller\CoordinatorController::class, 'getComments'], [$coordinatorAuth]);
        $router->post('/api/coordinator/incidents/{id}/comments', [\VendGuard\Presentation\Controller\CoordinatorController::class, 'addComment'], [$coordinatorAuth]);
        // Albarán de servicio firmado (Módulo 19: T-SIGN-05). La coordinación lee
        // cualquier expediente y reimprime el papel; la lectura queda auditada (RF-05.6).
        $router->get('/api/coordinator/incidents/{id}/service-sheet', [\VendGuard\Presentation\Controller\CoordinatorController::class, 'serviceSheet'], [$coordinatorAuth]);
        // Rutas de Administración Integral (Módulo 04: Sedes, Máquinas y Personal)
        // Sedes (Locations)
        $router->get('/api/coordinator/locations', [\VendGuard\Presentation\Controller\CoordinatorAdminController::class, 'listLocations'], [$coordinatorAuth]);
        $router->post('/api/coordinator/locations', [\VendGuard\Presentation\Controller\CoordinatorAdminController::class, 'createLocation'], [$coordinatorAuth]);
        $router->get('/api/coordinator/locations/{id}', [\VendGuard\Presentation\Controller\CoordinatorAdminController::class, 'getLocation'], [$coordinatorAuth]);
        $router->patch('/api/coordinator/locations/{id}', [\VendGuard\Presentation\Controller\CoordinatorAdminController::class, 'updateLocation'], [$coordinatorAuth]);
        $router->put('/api/coordinator/locations/{id}', [\VendGuard\Presentation\Controller\CoordinatorAdminController::class, 'updateLocation'], [$coordinatorAuth]);
        $router->patch('/api/coordinator/locations/{id}/deactivate', [\VendGuard\Presentation\Controller\CoordinatorAdminController::class, 'deactivateLocation'], [$coordinatorAuth]);
        $router->patch('/api/coordinator/locations/{id}/reactivate', [\VendGuard\Presentation\Controller\CoordinatorAdminController::class, 'reactivateLocation'], [$coordinatorAuth]);
        $router->post('/api/coordinator/locations/{id}/access-code', [\VendGuard\Presentation\Controller\CoordinatorAdminController::class, 'reissueLocationAccessCode'], [$coordinatorAuth]);

        // Zonificación operativa (Módulo 17, T-ZONE-09): catálogo territorial, CP y
        // cuadrante. Todas las rutas exigen Coordinación y las zonas nunca se exponen
        // al portal de sede (Art. V.4, RF-07.1).
        $router->get('/api/coordinator/zones', [CoordinatorZoneController::class, 'listZones'], [$coordinatorAuth]);
        $router->post('/api/coordinator/zones', [CoordinatorZoneController::class, 'createZone'], [$coordinatorAuth]);
        $router->get('/api/coordinator/zones/{id}', [CoordinatorZoneController::class, 'getZone'], [$coordinatorAuth]);
        $router->put('/api/coordinator/zones/{id}', [CoordinatorZoneController::class, 'updateZone'], [$coordinatorAuth]);
        $router->post('/api/coordinator/zones/{id}/postal-codes', [CoordinatorZoneController::class, 'assignPostalCodes'], [$coordinatorAuth]);
        $router->post('/api/coordinator/zones/{id}/technicians', [CoordinatorZoneController::class, 'assignTechnician'], [$coordinatorAuth]);
        $router->delete('/api/coordinator/zones/{id}/technicians/{tech_id}', [CoordinatorZoneController::class, 'removeTechnician'], [$coordinatorAuth]);
        // Lectura del cuadrante (RF-02.3, QA 15). `technicians/quadrants` se registra antes
        // de cualquier ruta `{id}` de técnico porque el `Router` resuelve por orden de
        // registro y un segmento dinámico la interpretaría como identificador.
        $router->get('/api/coordinator/technicians/quadrants', [CoordinatorZoneController::class, 'listTechnicianQuadrants'], [$coordinatorAuth]);
        // Ausencias y turnos de técnicos (Módulo 21, T-ASH-07).
        // Las rutas estáticas (/absences) se registran ANTES de los segmentos dinámicos ({id}).
        $router->get('/api/coordinator/technicians/absences', [\VendGuard\Presentation\Controller\CoordinatorAbsenceController::class, 'listAbsences'], [$coordinatorAuth]);
        $router->post('/api/coordinator/technicians/{id}/absences', [\VendGuard\Presentation\Controller\CoordinatorAbsenceController::class, 'createAbsence'], [$coordinatorAuth]);
        $router->delete('/api/coordinator/absences/{id}', [\VendGuard\Presentation\Controller\CoordinatorAbsenceController::class, 'cancelAbsence'], [$coordinatorAuth]);
        $router->get('/api/coordinator/technicians/{id}/shift', [\VendGuard\Presentation\Controller\CoordinatorShiftController::class, 'getShift'], [$coordinatorAuth]);
        $router->post('/api/coordinator/technicians/{id}/shifts/force-close', [\VendGuard\Presentation\Controller\CoordinatorShiftController::class, 'forceClose'], [$coordinatorAuth]);
        // Registro de jornada y horas efectivas (Módulo 22, contrato §5.3 y §5.4).
        // La bitácora es de solo lectura: `technician_shift_logs` no se edita ni se
        // borra nunca (Art. III.1 y III.3), solo se rectifica el turno con motivo auditado.
        $router->patch('/api/coordinator/shifts/{id}/adjust', [\VendGuard\Presentation\Controller\CoordinatorShiftController::class, 'adjustShift'], [$coordinatorAuth]);
        $router->get('/api/coordinator/shifts/{id}/logs', [\VendGuard\Presentation\Controller\CoordinatorShiftController::class, 'shiftLogs'], [$coordinatorAuth]);

        $router->get('/api/coordinator/zones/{id}/technicians', [CoordinatorZoneController::class, 'listZoneTechnicians'], [$coordinatorAuth]);
        $router->post('/api/coordinator/locations/{id}/override-zone', [CoordinatorZoneController::class, 'overrideLocationZone'], [$coordinatorAuth]);

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
        // Guardia de alertas críticas de frío (Módulo 14: T-NOTIF-08, RF-02.5).
        // Sólo Técnicos de Campo en activo pueden lucir la marca; la baja de personal
        // ya la retira en el repositorio y cancela sus avisos pendientes (RF-02.6).
        $router->patch('/api/coordinator/users/{id}/on-call', [\VendGuard\Presentation\Controller\CoordinatorAdminController::class, 'updateUserOnCall'], [$coordinatorAuth]);

        // Centro de Notificaciones: bandeja de salida, supervisión y canal corporativo
        // (Módulo 14: T-NOTIF-08, RF-06). La ruta estática de reintento masivo se
        // registra antes que la paramétrica para que "{id}" no la capture.
        $router->get('/api/coordinator/notifications/outbox', [\VendGuard\Presentation\Controller\CoordinatorNotificationController::class, 'listOutbox'], [$coordinatorAuth]);
        $router->post('/api/coordinator/notifications/outbox/retry-all-failed', [\VendGuard\Presentation\Controller\CoordinatorNotificationController::class, 'retryAllFailed'], [$coordinatorAuth]);
        $router->post('/api/coordinator/notifications/outbox/{id}/retry', [\VendGuard\Presentation\Controller\CoordinatorNotificationController::class, 'retryItem'], [$coordinatorAuth]);
        $router->post('/api/coordinator/notifications/outbox/{id}/cancel', [\VendGuard\Presentation\Controller\CoordinatorNotificationController::class, 'cancelItem'], [$coordinatorAuth]);
        $router->get('/api/coordinator/notifications/settings', [\VendGuard\Presentation\Controller\CoordinatorNotificationController::class, 'getSettings'], [$coordinatorAuth]);
        $router->put('/api/coordinator/notifications/settings', [\VendGuard\Presentation\Controller\CoordinatorNotificationController::class, 'updateSettings'], [$coordinatorAuth]);
        $router->post('/api/coordinator/notifications/settings/test-webhook', [\VendGuard\Presentation\Controller\CoordinatorNotificationController::class, 'testWebhook'], [$coordinatorAuth]);

        // Rutas existentes de Parque y QR
        $router->get('/api/coordinator/locations/{id}/machines', [\VendGuard\Presentation\Controller\CoordinatorController::class, 'getLocationMachines'], [$coordinatorAuth]);
        $router->get('/api/coordinator/machines/{id}/qr-label', [\VendGuard\Presentation\Controller\QrLabelController::class, 'getMachineLabel'], [$coordinatorAuth]);
        $router->get('/api/coordinator/locations/{id}/qr-batch', [\VendGuard\Presentation\Controller\QrLabelController::class, 'getLocationBatch'], [$coordinatorAuth]);
        $router->get('/api/coordinator/metrics/summary', [\VendGuard\Presentation\Controller\CoordinatorMetricsController::class, 'getSummary'], [$coordinatorAuth]);
        $router->get('/api/coordinator/metrics/breakdown', [\VendGuard\Presentation\Controller\CoordinatorMetricsController::class, 'getBreakdown'], [$coordinatorAuth]);
        // La satisfacción se agrega sobre `resolution_at`, no sobre la fecha de valoración:
        // el servicio cuenta en el periodo en que se resolvió (contratos §6.4).
        $router->get('/api/coordinator/metrics/csat', [\VendGuard\Presentation\Controller\CoordinatorMetricsController::class, 'getCsat'], [$coordinatorAuth]);
        $router->get('/api/coordinator/metrics/export', [\VendGuard\Presentation\Controller\CoordinatorMetricsController::class, 'exportMetrics'], [$coordinatorAuth]);
        // Pareto 80/20 de causas raíz (Módulo 25, RF-05). La exportación se registra
        // antes que el informe porque el Router resuelve por orden de declaración.
        $router->get('/api/coordinator/metrics/root-causes-pareto/export', [\VendGuard\Presentation\Controller\CoordinatorMetricsController::class, 'exportRootCausesParetoCsv'], [$coordinatorAuth]);
        $router->get('/api/coordinator/metrics/root-causes-pareto', [\VendGuard\Presentation\Controller\CoordinatorMetricsController::class, 'getRootCausesPareto'], [$coordinatorAuth]);

        // Catálogo maestro de causas raíz y soluciones estandarizadas (Módulo 25,
        // RF-01, QA 1 y QA 5). Todas las rutas exigen COORDINATOR: el técnico y la
        // sede reciben 403 y el catálogo nunca se publica fuera de Coordinación.
        $router->get('/api/coordinator/root-causes', [CoordinatorRootCauseController::class, 'listCatalog'], [$coordinatorAuth]);
        $router->post('/api/coordinator/root-causes', [CoordinatorRootCauseController::class, 'createRootCause'], [$coordinatorAuth]);
        $router->put('/api/coordinator/root-causes/{id}', [CoordinatorRootCauseController::class, 'updateRootCause'], [$coordinatorAuth]);
        $router->post('/api/coordinator/root-causes/{id}/solutions', [CoordinatorRootCauseController::class, 'createSolution'], [$coordinatorAuth]);
        $router->put('/api/coordinator/root-causes/{id}/solutions/{solutionId}', [CoordinatorRootCauseController::class, 'updateSolution'], [$coordinatorAuth]);
        // Registro de jornada y horas efectivas (Módulo 22, contrato §5.1 y §5.2).
        // El router resuelve por orden de registro: la descarga se registra antes que
        // el informe para que `/work-hours/export` no se interprete como otra ruta.
        $router->get('/api/coordinator/metrics/work-hours/export', [\VendGuard\Presentation\Controller\CoordinatorShiftController::class, 'exportWorkHoursCsv'], [$coordinatorAuth]);
        $router->get('/api/coordinator/metrics/work-hours', [\VendGuard\Presentation\Controller\CoordinatorShiftController::class, 'workHoursReport'], [$coordinatorAuth]);
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
        // Superficie operativa del barrido sanitario (Módulo 11: T-PAUSE-41, RF-07).
        // Solo lectura: el latido del barrido, la tendencia de 24 h, los expedientes
        // aplazados por contención de fila y el nivel de escalado cuando el aplazamiento
        // persiste. Instrumentación interna: no se publica en sede ni en el canal
        // ciudadano (Art. V.4).
        $router->get('/api/coordinator/sanitary-sweep/contention', [\VendGuard\Presentation\Controller\CoordinatorSanitarySweepController::class, 'getContention'], [$coordinatorAuth]);

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

        // Stock de repuestos de las furgonetas (Módulo 18, RF-03 / RF-04.2)
        $router->get('/api/coordinator/van-stocks', [\VendGuard\Presentation\Controller\CoordinatorVanStockController::class, 'getInventory'], [$coordinatorAuth]);
        $router->put('/api/coordinator/van-stocks', [\VendGuard\Presentation\Controller\CoordinatorVanStockController::class, 'upsertStock'], [$coordinatorAuth]);

        // Órdenes de carga de furgoneta (Módulo 23, T-RESTOCK-10, contrato §5.2 a §5.9).
        // Las rutas estáticas se registran antes de los segmentos dinámicos `{id}` para
        // que «proposals» y «generate-batch» no se interpreten como identificadores.
        $router->get('/api/coordinator/van-restock-orders/proposals', [CoordinatorVanRestockOrderController::class, 'getProposals'], [$coordinatorAuth]);
        $router->post('/api/coordinator/van-restock-orders/generate-batch', [CoordinatorVanRestockOrderController::class, 'generateBatch'], [$coordinatorAuth]);
        $router->get('/api/coordinator/van-restock-orders', [CoordinatorVanRestockOrderController::class, 'listOrders'], [$coordinatorAuth]);
        $router->post('/api/coordinator/van-restock-orders', [CoordinatorVanRestockOrderController::class, 'createOrder'], [$coordinatorAuth]);
        $router->get('/api/coordinator/van-restock-orders/{id}', [CoordinatorVanRestockOrderController::class, 'getOrderDetail'], [$coordinatorAuth]);
        $router->put('/api/coordinator/van-restock-orders/{id}', [CoordinatorVanRestockOrderController::class, 'updateOrder'], [$coordinatorAuth]);
        $router->post('/api/coordinator/van-restock-orders/{id}/dispatch', [CoordinatorVanRestockOrderController::class, 'dispatchOrder'], [$coordinatorAuth]);
        $router->post('/api/coordinator/van-restock-orders/{id}/cancel', [CoordinatorVanRestockOrderController::class, 'cancelOrder'], [$coordinatorAuth]);

        // -----------------------------------------------------------------
        // 5. Módulo de Técnico de Campo / "Mi Ruta" (T-28, T-29)
        // -----------------------------------------------------------------
        $technicianAuth = new \VendGuard\Presentation\Http\Middleware\InternalAuthMiddleware(
            \VendGuard\Core\Domain\Model\UserRole::TECHNICIAN
        );
        $router->get('/api/technician/route/map', [TechnicianRouteMapController::class, 'getRouteMap'], [$technicianAuth]);

        // Modo Sin Conexión e Instalabilidad Móvil (Módulo 24: T-OFFLINE-08).
        // El paquete de ruta y los dos volcados de auditoría son supervivencia del
        // dispositivo, no acciones de parada: el token acredita al técnico y aquí
        // sólo se lee o se archiva su cola (RF-02.1, RF-05.3, RF-06.4).
        $router->get('/api/technician/offline/bootstrap', [TechnicianOfflineSyncController::class, 'getBootstrap'], [$technicianAuth]);
        $router->post('/api/technician/offline/conflicts/dump', [TechnicianOfflineSyncController::class, 'dumpConflict'], [$technicianAuth]);
        // El volcado por baja de cuenta se autentica con el token del propio técnico
        // desactivado: el middleware verifica firma y rol (permitiendo cuenta inactiva),
        // y el controlador comprueba `users.is_active` para responder 409 si sigue activa.
        $technicianDeactivatedAuth = new \VendGuard\Presentation\Http\Middleware\InternalAuthMiddleware(
            \VendGuard\Core\Domain\Model\UserRole::TECHNICIAN,
            allowInactive: true
        );
        $router->post('/api/technician/offline/deactivated-account-dump', [TechnicianOfflineSyncController::class, 'dumpDeactivatedAccount'], [$technicianDeactivatedAuth]);

        $router->get('/api/technician/my-route', [\VendGuard\Presentation\Controller\TechnicianController::class, 'getMyRoute'], [$technicianAuth]);
        // Catálogo activo de causas raíz para el selector de resolución (Módulo 25,
        // RF-02.3). La ruta acredita a cualquier usuario interno y el controlador
        // restringe el acceso a TECHNICIAN y COORDINATOR.
        $internalAuthenticated = new \VendGuard\Presentation\Http\Middleware\InternalAuthMiddleware();
        $router->get('/api/technician/root-causes', [\VendGuard\Presentation\Controller\TechnicianController::class, 'listActiveRootCauses'], [$internalAuthenticated]);
        // Avería Oportunista In Situ (Módulo 16: T-OPP-09, RF-01 a RF-05).
        // El catálogo exige que la sede esté habilitada para este técnico; el alta y
        // la asunción revalidan esa habilitación en el servicio, bajo su propia
        // transacción, para que el catálogo pintado nunca sea la última palabra.
        $router->get('/api/technician/locations/{location_id}/opportunistic-machines', [TechnicianOpportunisticController::class, 'getLocationCatalog'], [$technicianAuth]);
        $router->post('/api/technician/opportunistic/incidents', [TechnicianOpportunisticController::class, 'createIncident'], [$technicianAuth]);
        $router->post('/api/technician/opportunistic/incidents/{id}/assume', [TechnicianOpportunisticController::class, 'assumeIncident'], [$technicianAuth]);
        // Historial de averías de una máquina de la ruta (solo lectura, Art. V.4).
        $router->get('/api/technician/machines/{id}/history', [\VendGuard\Presentation\Controller\TechnicianController::class, 'getMachineHistory'], [$technicianAuth]);
        $router->get('/api/technician/my-metrics', [\VendGuard\Presentation\Controller\TechnicianMetricsController::class, 'getMyMetrics'], [$technicianAuth]);
        // Valoración CSAT propia (incremento 20.1, RF-20.1.1): endpoint separado para que
        // un fallo del agregado no vacíe el resto del panel.
        $router->get('/api/technician/my-metrics/csat', [\VendGuard\Presentation\Controller\TechnicianMetricsController::class, 'getMyCsat'], [$technicianAuth]);
        $router->patch('/api/technician/incidents/{id}/start', [\VendGuard\Presentation\Controller\TechnicianController::class, 'startIntervention'], [$technicianAuth]);
        $router->patch('/api/technician/incidents/{id}/pause', [\VendGuard\Presentation\Controller\TechnicianController::class, 'pauseIntervention'], [$technicianAuth]);
        // Pausa por bloqueo de sede y reanudación manual in situ (Módulo 11: T-PAUSE-12).
        // La pausa congela el reloj contractual de SLA; la reanudación lo reactiva con el
        // vencimiento desplazado en horario comercial y devuelve el ticket a "En curso".
        $router->post('/api/technician/incidents/{id}/pause-pending-info', [\VendGuard\Presentation\Controller\TechnicianController::class, 'pausePendingInfo'], [$technicianAuth]);
        $router->post('/api/technician/incidents/{id}/resume-pending-info', [\VendGuard\Presentation\Controller\TechnicianController::class, 'resumePendingInfo'], [$technicianAuth]);
        // Hilo de conversación del expediente (Módulo 10: T-COM-08). El técnico de la
        // ruta ve el hilo íntegro, notas internas incluidas, y publica con el selector
        // de privacidad preseleccionado en "Nota Interna de Taller" (RF-02.3, RF-03.3).
        $router->get('/api/technician/incidents/{id}/comments', [\VendGuard\Presentation\Controller\TechnicianController::class, 'getComments'], [$technicianAuth]);
        $router->post('/api/technician/incidents/{id}/comments', [\VendGuard\Presentation\Controller\TechnicianController::class, 'addComment'], [$technicianAuth]);
        // Albarán de servicio firmado (Módulo 19: T-SIGN-05). Lectura auditada del
        // documento congelado; el técnico solo alcanza lo asignado o lo que firmó (RD-17).
        $router->get('/api/technician/incidents/{id}/service-sheet', [\VendGuard\Presentation\Controller\TechnicianController::class, 'serviceSheet'], [$technicianAuth]);
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

        // Inventario de la furgoneta del propio técnico (Módulo 18, RF-04.1 / RF-04.3)
        // y recepción de la orden de carga despachada (Módulo 23, RF-05.1 a RF-05.3).
        $router->get('/api/technician/van-stock', [\VendGuard\Presentation\Controller\TechnicianVanStockController::class, 'getInventory'], [$technicianAuth]);
        $router->post('/api/technician/van-stock/restock-orders/{id}/confirm', [\VendGuard\Presentation\Controller\TechnicianVanStockController::class, 'confirmRestockOrder'], [$technicianAuth]);

        // Ciclo de jornada y relevo de ruta (Módulo 21, T-ASH-08).
        // Compañeros activos para el relevo: endpoint propio del técnico, sin exponer
        // el cuadrante interno de Coordinación ni datos de contacto.
        $router->get('/api/technician/handover-targets', [\VendGuard\Presentation\Controller\TechnicianShiftController::class, 'handoverTargets'], [$technicianAuth]);
        $router->get('/api/technician/shift/status', [\VendGuard\Presentation\Controller\TechnicianShiftController::class, 'status'], [$technicianAuth]);
        // Horas propias del técnico autenticado (Módulo 22, contrato §5.6, Art. V.4).
        // El alcance sale del token; el Portal de Sede no tiene ruta equivalente.
        $router->get('/api/technician/shift/my-hours', [\VendGuard\Presentation\Controller\TechnicianShiftController::class, 'myHours'], [$technicianAuth]);
        $router->post('/api/technician/shift/start', [\VendGuard\Presentation\Controller\TechnicianShiftController::class, 'start'], [$technicianAuth]);
        $router->post('/api/technician/shift/pause-meal', [\VendGuard\Presentation\Controller\TechnicianShiftController::class, 'pauseMeal'], [$technicianAuth]);
        $router->post('/api/technician/shift/resume', [\VendGuard\Presentation\Controller\TechnicianShiftController::class, 'resume'], [$technicianAuth]);
        $router->post('/api/technician/shift/close-handover', [\VendGuard\Presentation\Controller\TechnicianShiftController::class, 'closeAndHandover'], [$technicianAuth]);

        // -----------------------------------------------------------------
        // 6. Módulo de Automatización y Tareas Cron (T-30)
        // -----------------------------------------------------------------
        // El proceso batch ya no cuelga de una ruta "desnuda": la credencial la valida
        // CronAuthMiddleware y el controlador exige la marca de autorización (hallazgo S-2).
        $cronAuth = new \VendGuard\Presentation\Http\Middleware\CronAuthMiddleware();
        $router->post('/api/cron/auto-close', [\VendGuard\Presentation\Controller\CronController::class, 'autoClose'], [$cronAuth]);
        // Barrido de fondo del Reloj Sanitario Biológico (T-PAUSE-38, RF-03.5.1, Art. II): activa
        // la cuarentena automática sin que nadie intente cerrar la avería. Misma credencial de
        // máquina y mismo controlador fail-closed que el auto-cierre; la cadencia ratificada es
        // de 15 minutos.
        $router->post('/api/cron/sanitary-clock-sweep', [\VendGuard\Presentation\Controller\CronController::class, 'sweepSanitaryClock'], [$cronAuth]);
        // Despachador del Centro de Notificaciones (Módulo 14: T-NOTIF-08, RF-01.3,
        // RF-01.4, RF-04): misma credencial de máquina y mismo controlador fail-closed
        // que el resto de procesos batch. Una pasada por invocación.
        $router->post('/api/cron/notifications-dispatch', [\VendGuard\Presentation\Controller\CronController::class, 'dispatchNotifications'], [$cronAuth]);

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

