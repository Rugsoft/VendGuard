<?php

declare(strict_types=1);

namespace VendGuard\Presentation\Controller;

use VendGuard\Application\Service\AuthService;
use VendGuard\Core\Domain\Model\Incident;
use VendGuard\Core\Domain\Model\UserRole;
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
 * Protegido mediante token secreto configurable (X-Cron-Secret o Bearer token de Coordinador).
 * Dogma Vanilla: PHP 8.2+ puro, PDO, cero dependencias externas.
 */
class CronController
{
    public const DEFAULT_CRON_SECRET = 'vendguard-cron-secret-key-2026';

    private IncidentRepositoryInterface $incidentRepo;
    private AuthService $authService;
    private string $cronSecret;

    public function __construct(
        ?IncidentRepositoryInterface $incidentRepo = null,
        ?AuthService $authService = null,
        ?string $cronSecret = null
    ) {
        $this->incidentRepo = $incidentRepo ?? new PdoIncidentRepository();
        $this->authService  = $authService ?? new AuthService();
        $this->cronSecret   = $cronSecret ?? (getenv('CRON_SECRET') ?: self::DEFAULT_CRON_SECRET);
    }

    /**
     * POST /api/cron/auto-close
     * 
     * Cierra automáticamente y archiva todas las incidencias que han permanecido
     * más de 48 horas en estado RESUELTA sin haber sido reabiertas (RF-10 / EARS 10.1, 10.2).
     * 
     * Cabecera de seguridad aceptada:
     * - X-Cron-Secret: <secreto>
     * - Authorization: Bearer <secreto> o Bearer <token_coordinador>
     */
    public function autoClose(Request $request): Response
    {
        // 1. Validar autenticación del proceso cron
        if (!$this->isAuthorized($request)) {
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
     * Comprueba si la petición está autorizada para ejecutar tareas cron.
     */
    private function isAuthorized(Request $request): bool
    {
        // A. Cabecera directa X-Cron-Secret
        $headerSecret = $request->getHeader('X-Cron-Secret');
        if ($headerSecret !== null && hash_equals($this->cronSecret, $headerSecret)) {
            return true;
        }

        // B. Bearer token igual al secreto cron
        $bearerToken = $request->getBearerToken();
        if ($bearerToken !== null && hash_equals($this->cronSecret, $bearerToken)) {
            return true;
        }

        // C. Bearer token válido de un usuario con rol COORDINATOR
        if ($bearerToken !== null) {
            $verified = $this->authService->validateInternalToken($bearerToken);
            if ($verified !== null && ($verified['role'] ?? '') === UserRole::COORDINATOR->value) {
                return true;
            }
        }

        return false;
    }
}
