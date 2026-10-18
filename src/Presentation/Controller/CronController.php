<?php

declare(strict_types=1);

namespace VendGuard\Presentation\Controller;

use VendGuard\Application\Service\NotificationDispatcherService;
use VendGuard\Application\Service\SanitaryClockSweepService;
use VendGuard\Core\Domain\Model\Incident;
use VendGuard\Core\Domain\Repository\IncidentRepositoryInterface;
use VendGuard\Core\Service\AppPublicUrl;
use VendGuard\Infrastructure\Notification\NotificationDispatcherFactory;
use VendGuard\Infrastructure\Notification\Template\NotificationMessageRenderer;
use VendGuard\Infrastructure\Repository\PdoIncidentRepository;
use VendGuard\Presentation\Http\Request;
use VendGuard\Presentation\Http\Response;

/**
 * CronController
 * 
 * Controlador de Procesos Automatizados y Tareas Batch en Segundo Plano (RF-10 / EARS 10.1, 10.2).
 * Ejecuta el archivado definitivo a CERRADA de expedientes que superan la ventana de 48h en RESUELTA.
 * 
 * También consume el Reloj Sanitario Biológico en segundo plano (T-PAUSE-38, RF-03.5.1, Art. II):
 * activa la cuarentena sanitaria de las máquinas bajo vigilancia con más de 4 h naturales sin frío
 * confirmado, **sin** que nadie intente cerrar la avería, con la latencia acotada por la cadencia
 * del planificador (15 minutos ratificados).
 * 
 * Autenticación: la credencial la valida `CronAuthMiddleware` (hallazgo S-2), registrado en la
 * ruta `POST /api/cron/auto-close`. Este controlador es **fail-closed**: solo ejecuta el proceso
 * si el middleware marcó la petición como autorizada, de modo que una ruta mal registrada no
 * abre el proceso batch por descuido.
 *
 * Dogma Vanilla: PHP 8.2+ puro, PDO, cero dependencias externas.
 */
class CronController
{
    private IncidentRepositoryInterface $incidentRepo;

    /** Barrido de fondo del Reloj Sanitario Biológico (T-PAUSE-38); se resuelve en el primer uso. */
    private ?SanitaryClockSweepService $sweepService;

    /** Despachador del Centro de Notificaciones (Módulo 14: T-NOTIF-08); perezoso. */
    private ?NotificationDispatcherService $notificationDispatcher;

    public function __construct(
        ?IncidentRepositoryInterface $incidentRepo = null,
        ?SanitaryClockSweepService $sweepService = null,
        ?NotificationDispatcherService $notificationDispatcher = null
    ) {
        $this->incidentRepo = $incidentRepo ?? new PdoIncidentRepository();
        $this->sweepService = $sweepService;
        $this->notificationDispatcher = $notificationDispatcher;
    }

    /**
     * POST /api/cron/auto-close
     * 
     * Cierra automáticamente y archiva todas las incidencias que han permanecido
     * más de 48 horas en estado RESUELTA sin haber sido reabiertas (RF-10 / EARS 10.1, 10.2).
     * 
     * Requisito de entrada: contexto `cron_authenticated` inyectado por `CronAuthMiddleware`.
     */
    public function autoClose(Request $request): Response
    {
        // Defensa en profundidad: sin credencial verificada por el middleware no se ejecuta nada.
        if ($request->getAttribute('cron_authenticated') !== true) {
            return Response::error(
                'UNAUTHORIZED',
                'Acceso no autorizado al proceso cron. Se requiere cabecera X-Cron-Secret o token válido.',
                401
            );
        }

        // 2. Ejecutar auto-cierre de expedientes que superen la ventana de 48h
        $closedIncidents = $this->incidentRepo->autoCloseResolvedIncidents(48);

        // 3. Extraer códigos de ticket cerrados
        $closedTickets = array_map(
            fn(Incident $inc) => $inc->getTicketCode(),
            $closedIncidents
        );

        // 4. Devolver respuesta conforme al contrato técnico 6.1
        return Response::json([
            'closed_count'   => count($closedIncidents),
            'closed_tickets' => $closedTickets,
        ], 200);
    }

    /**
     * POST /api/cron/sanitary-clock-sweep
     * 
     * Consume el Reloj Sanitario Biológico en segundo plano (T-PAUSE-38, RF-03.5.1, Art. II):
     * activa la cuarentena sanitaria de las máquinas bajo vigilancia con más de 4 h naturales
     * sin frío confirmado, **sin** que nadie intente cerrar la avería. Es el consumidor que
     * materializa la activación automática del punto 1 de RF-03.5 y el que acota la latencia
     * de detección a la cadencia del planificador (15 minutos ratificados).
     * 
     * El controlador no decide nada: entrega cada candidato al reloj, que es la única autoridad
     * de la regla y el único punto que escribe el estado y su rastro inmutable; aquí sólo se
     * publica el resumen canónico (`SanitaryClockSweepResultDto`).
     * 
     * Requisito de entrada: contexto `cron_authenticated` inyectado por `CronAuthMiddleware`.
     */
    public function sweepSanitaryClock(Request $request): Response
    {
        // Defensa en profundidad idéntica a la del auto-cierre: sin credencial verificada por
        // el middleware no se ejecuta el proceso batch (hallazgo S-2).
        if ($request->getAttribute('cron_authenticated') !== true) {
            return Response::error(
                'UNAUTHORIZED',
                'Acceso no autorizado al proceso cron. Se requiere cabecera X-Cron-Secret o token válido.',
                401
            );
        }

        return Response::json($this->sweeps()->sweep()->toArray(), 200);
    }

    /**
     * POST /api/cron/notifications-dispatch
     *
     * Pasada única del despachador del Centro de Notificaciones (Módulo 14:
     * T-NOTIF-08, RF-01.3, RF-01.4, RF-04 y RF-06): rescata los envíos atrapados
     * en tránsito, vigila los umbrales de SLA sanitario de 2 h y 3 h, reclama el
     * lote con prioridad `CRITICAL_SANITARY` y entrega con backoff progresivo.
     *
     * Es el mismo `runPass()` que ejecuta el worker CLI: el controlador no
     * duplica reglas ni transportes, solo publica el resumen canónico del DTO.
     *
     * Requisito de entrada: contexto `cron_authenticated` inyectado por `CronAuthMiddleware`.
     */
    public function dispatchNotifications(Request $request): Response
    {
        // Defensa en profundidad idéntica a la del auto-cierre: sin credencial verificada
        // por el middleware no se ejecuta el proceso batch (hallazgo S-2).
        if ($request->getAttribute('cron_authenticated') !== true) {
            return Response::error(
                'UNAUTHORIZED',
                'Acceso no autorizado al proceso cron. Se requiere cabecera X-Cron-Secret o token válido.',
                401
            );
        }

        $rawBatchSize = $request->getQuery('batch_size') ?? $request->getBodyParam('batch_size');
        $batchSize = NotificationDispatcherService::DEFAULT_BATCH_SIZE;
        if ($rawBatchSize !== null && trim((string)$rawBatchSize) !== '') {
            $candidate = trim((string)$rawBatchSize);
            if (!ctype_digit($candidate) || (int)$candidate < 1 || (int)$candidate > 200) {
                return Response::error(
                    'INVALID_BATCH_SIZE',
                    'El tamaño del lote de despacho debe ser un número entero entre 1 y 200 notificaciones.',
                    422
                );
            }
            $batchSize = (int)$candidate;
        }

        $this->notificationDispatcher ??= NotificationDispatcherFactory::create(
            $this->notificationRenderer($request)
        );

        return Response::json($this->notificationDispatcher->runPass($batchSize)->toArray(), 200);
    }

    /**
     * Renderizador de correos con el host público canónico de los enlaces.
     *
     * Precedencia del contrato de enlaces: `APP_BASE_URL` (la vía del
     * despliegue) y, si no está declarada, el host efectivo de esta petición
     * —el planificador llama al endpoint por su URL pública—. El correo no
     * puede llevar rutas relativas: el cliente de correo las resolvería contra
     * el dominio del proveedor y el botón aterrizaría en una web ajena.
     */
    private function notificationRenderer(Request $request): NotificationMessageRenderer
    {
        return new NotificationMessageRenderer(
            AppPublicUrl::fromHttpOrigin($request->getScheme(), (string)$request->getHeader('Host'))
        );
    }

    /**
     * Barrido de fondo del Reloj Sanitario Biológico, resuelto de forma perezosa.
     */
    private function sweeps(): SanitaryClockSweepService
    {
        return $this->sweepService ??= new SanitaryClockSweepService(
            notificationEnqueuer: \VendGuard\Infrastructure\Notification\NotificationOutboxEnqueuerFactory::createOrNull()
        );
    }
}
