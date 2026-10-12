<?php

declare(strict_types=1);

namespace VendGuard\Presentation\Controller;

use VendGuard\Application\Service\SanitaryClockSweepService;
use VendGuard\Core\Domain\Model\Incident;
use VendGuard\Core\Domain\Repository\IncidentRepositoryInterface;
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

    public function __construct(
        ?IncidentRepositoryInterface $incidentRepo = null,
        ?SanitaryClockSweepService $sweepService = null
    ) {
        $this->incidentRepo = $incidentRepo ?? new PdoIncidentRepository();
        $this->sweepService = $sweepService;
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
     * Barrido de fondo del Reloj Sanitario Biológico, resuelto de forma perezosa.
     */
    private function sweeps(): SanitaryClockSweepService
    {
        return $this->sweepService ??= new SanitaryClockSweepService();
    }
}
