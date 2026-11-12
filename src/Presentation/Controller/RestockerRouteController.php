<?php

declare(strict_types=1);

namespace VendGuard\Presentation\Controller;

use PDO;
use Throwable;
use VendGuard\Application\Service\ShiftLifecycleService;
use VendGuard\Core\Domain\Repository\OperationalZoneRepositoryInterface;
use VendGuard\Core\Domain\Repository\TechnicianLockRepositoryInterface;
use VendGuard\Core\Domain\Repository\UserRepositoryInterface;
use VendGuard\Infrastructure\Database\ConnectionFactory;
use VendGuard\Infrastructure\Database\PdoTransactionManager;
use VendGuard\Infrastructure\Repository\PdoAuditLogRepository;
use VendGuard\Infrastructure\Repository\PdoOperationalZoneRepository;
use VendGuard\Infrastructure\Repository\PdoTechnicianAbsenceRepository;
use VendGuard\Infrastructure\Repository\PdoTechnicianShiftLogRepository;
use VendGuard\Infrastructure\Repository\PdoTechnicianShiftRepository;
use VendGuard\Infrastructure\Repository\PdoUserRepository;
use VendGuard\Presentation\Http\Request;
use VendGuard\Presentation\Http\Response;

/**
 * RestockerRouteController
 *
 * Bootstrap operativo de la vista móvil `RestockerRouteView.js` (Módulo 31,
 * `D1`, `RF-04.1`). Publica el perfil del reponedor autenticado, sus zonas vivas
 * (`PRIMARY` y `BACKUP`), el estado de su turno y el resumen del día; los
 * parques de carga llegarán con las Features 24/26/27 y hoy viajan vacíos.
 *
 * El reponedor sale siempre del `user_id` que inyecta el middleware tras
 * validar el token: no se acepta ningún identificador de la query ni del
 * cuerpo (Art. V.4). La ruta es exclusiva de `RESTOCKER`; Coordinación y los
 * técnicos SAT reciben `403`.
 *
 * Dogma Vanilla: PHP 8.2+ puro, PDO detrás de los puertos, cero dependencias.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */
final class RestockerRouteController
{
    private UserRepositoryInterface $userRepo;

    private OperationalZoneRepositoryInterface $zoneRepo;

    private ShiftLifecycleService $lifecycleService;

    public function __construct(
        ?PDO $pdo = null,
        ?UserRepositoryInterface $userRepo = null,
        ?OperationalZoneRepositoryInterface $zoneRepo = null,
        ?ShiftLifecycleService $lifecycleService = null
    ) {
        $pdo ??= ConnectionFactory::getConnection();
        $this->userRepo = $userRepo ?? new PdoUserRepository($pdo);
        $this->zoneRepo = $zoneRepo ?? new PdoOperationalZoneRepository($pdo);

        $lockRepo = $this->userRepo instanceof TechnicianLockRepositoryInterface
            ? $this->userRepo
            : new PdoUserRepository($pdo);

        $this->lifecycleService = $lifecycleService ?? new ShiftLifecycleService(
            new PdoTechnicianShiftRepository($pdo),
            new PdoTechnicianAbsenceRepository($pdo),
            $this->userRepo,
            new PdoAuditLogRepository($pdo),
            new PdoTransactionManager($pdo),
            $lockRepo,
            new PdoTechnicianShiftLogRepository($pdo)
        );
    }

    /**
     * GET /api/restocker/my-route
     */
    public function myRoute(Request $request): Response
    {
        $restockerId = (int)($request->getAttribute('user_id') ?? 0);
        $restocker = $restockerId > 0 ? $this->userRepo->findById($restockerId) : null;
        if ($restocker === null) {
            return Response::error('UNAUTHORIZED', 'La sesión no identifica a un reponedor activo.', 401);
        }

        try {
            $quadrant = $this->findQuadrant($restockerId);

            $primaryZone = null;
            $backupZones = [];
            foreach ($quadrant['zones'] ?? [] as $zone) {
                $projected = [
                    'id' => (int)$zone['zone_id'],
                    'code' => (string)$zone['code'],
                    'name' => (string)$zone['name'],
                    'color_hex' => isset($zone['color_hex']) ? (string)$zone['color_hex'] : null,
                    'role_in_zone' => (string)$zone['role_in_zone'],
                ];

                if (!empty($zone['is_primary'])) {
                    $primaryZone = $projected;
                } else {
                    $backupZones[] = $projected;
                }
            }

            $shift = $this->lifecycleService->getCurrentShift($restockerId);

            return Response::json([
                'restocker' => [
                    'id' => $restocker->getId(),
                    'name' => $restocker->getName(),
                    'email' => $restocker->getEmail(),
                    'operator_code' => $quadrant['operator_code'] ?? null,
                    'role' => $restocker->getRole()->value,
                ],
                'zones' => [
                    'primary_zone' => $primaryZone,
                    'backup_zones' => $backupZones,
                ],
                'shift' => [
                    'status' => $shift->status,
                    'active_shift_id' => $shift->id,
                    'started_at' => $shift->startedAt,
                    'today_effective_seconds' => (int)($shift->todaySummary['effective_seconds'] ?? 0),
                ],
                'route_stops' => [],
            ], 200);
        } catch (Throwable $exception) {
            error_log('[vendguard] Fallo al construir la ruta de carga del reponedor: ' . $exception);

            return Response::error('INTERNAL_SERVER_ERROR', 'No se pudo cargar la ruta de carga.', 500);
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findQuadrant(int $restockerId): ?array
    {
        foreach ($this->zoneRepo->findRestockerQuadrantsByTechnician() as $row) {
            if ((int)$row['restocker_id'] === $restockerId) {
                return $row;
            }
        }

        return null;
    }
}
