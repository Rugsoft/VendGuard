<?php

declare(strict_types=1);

namespace VendGuard\Presentation\Controller;

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

    public function __construct(?IncidentRepositoryInterface $incidentRepo = null)
    {
        $this->incidentRepo = $incidentRepo ?? new PdoIncidentRepository();
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
}
