<?php

declare(strict_types=1);

namespace VendGuard\Infrastructure\Notification;

use PDO;
use VendGuard\Application\Service\HandoverTargetService;
use VendGuard\Application\Service\NotificationOutboxEnqueuerService;
use VendGuard\Application\Service\ShiftHandoverService;
use VendGuard\Infrastructure\Database\ConnectionFactory;
use VendGuard\Infrastructure\Repository\PdoAuditLogRepository;
use VendGuard\Infrastructure\Repository\PdoIncidentRepository;
use VendGuard\Infrastructure\Repository\PdoMachineRepository;
use VendGuard\Infrastructure\Repository\PdoTechnicianAbsenceRepository;
use VendGuard\Infrastructure\Repository\PdoTechnicianShiftRepository;
use VendGuard\Infrastructure\Repository\PdoUserRepository;

/**
 * ShiftHandoverServiceFactory — Composición del motor único de cierre.
 *
 * Los dos controladores que cierran turnos (técnico y coordinación) deben usar
 * exactamente el mismo grafo de dependencias. Si cada uno compusiera su propio
 * servicio, el cierre forzado podría volver a quedarse sin el encolador de
 * notificaciones y el protocolo sanitario dejaría de avisar en silencio.
 *
 * La factoría no abre conexión ni ejecuta consultas: sólo construye objetos.
 */
final class ShiftHandoverServiceFactory
{
    public static function create(
        ?PDO $pdo = null,
        ?NotificationOutboxEnqueuerService $notifications = null
    ): ShiftHandoverService {
        $pdo ??= ConnectionFactory::getConnection();
        $users = new PdoUserRepository($pdo);
        $shifts = new PdoTechnicianShiftRepository($pdo);
        $absences = new PdoTechnicianAbsenceRepository($pdo);

        return new ShiftHandoverService(
            pdo: $pdo,
            shiftRepo: $shifts,
            userRepo: $users,
            incidentRepo: new PdoIncidentRepository($pdo),
            machineRepo: new PdoMachineRepository($pdo),
            onCallRepo: $users,
            auditRepo: new PdoAuditLogRepository($pdo),
            handoverTargets: new HandoverTargetService($users, $shifts, $absences),
            notifications: $notifications ?? NotificationOutboxEnqueuerFactory::create($pdo)
        );
    }
}
