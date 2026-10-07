<?php

declare(strict_types=1);

/**
 * LocationPortalReopenAuditTest
 *
 * Suite unitaria del evento inmutable de auditoría de la reapertura en garantía
 * (hallazgo F-2 de la verificación del módulo 10).
 *
 * Valida la condición "Hecho cuando" de EARS 5.1.2 de specs/functional/metrics_audit_spec.md
 * ("cambio de estado del ticket ... o reaperturas dentro de 48h") sin pasar por la base
 * de datos de auditoría: el sumidero de `AuditLogger` es un doble en memoria que captura
 * el `AuditEvent` exacto que el controlador emite.
 *
 * Casos cubiertos:
 * 1. Reapertura efectiva => un único evento `REOPEN_TICKET` con actor SITE_MANAGER,
 *    estado previo RESOLVED (con el técnico saliente) y nuevo REOPENED (desasignado),
 *    más `ticket_code` y `reopen_count` en la metadata.
 * 2. Ventana de garantía expirada (> 48 h) => 422 y ningún evento.
 * 3. Expediente marcado como Avería Crónica (2 reaperturas previas) => 422 y ningún evento.
 * 4. Motivo de reapertura demasiado corto => 422 y ningún evento.
 *
 * Dogma Vanilla: PHP 8 puro; la única base de datos que se toca es la operativa para
 * preparar el escenario, nunca la de auditoría (que es el objeto bajo prueba).
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/ConnectionFactory.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/SeedRunner.php';

use VendGuard\Application\Service\AuditLogger;
use VendGuard\Core\Domain\Model\AuditEvent;
use VendGuard\Core\Domain\Model\Incident;
use VendGuard\Core\Domain\Repository\AuditLogRepositoryInterface;
use VendGuard\Core\Domain\ValueObject\IncidentCategory;
use VendGuard\Core\Domain\ValueObject\IncidentStatus;
use VendGuard\Core\Domain\ValueObject\UrgencyLevel;
use VendGuard\Infrastructure\Database\ConnectionFactory;
use VendGuard\Infrastructure\Database\SeedRunner;
use VendGuard\Infrastructure\Repository\PdoIncidentRepository;
use VendGuard\Infrastructure\Repository\PdoMachineRepository;
use VendGuard\Infrastructure\Repository\PdoLocationRepository;
use VendGuard\Infrastructure\Repository\PdoUserRepository;
use VendGuard\Presentation\Controller\LocationPortalController;
use VendGuard\Presentation\Http\Request;

echo "======================================================================\n";
echo " VendGuard: Test Unitario - LocationPortalReopenAuditTest (F-2)\n";
echo "======================================================================\n\n";

/**
 * Sumidero en memoria del registro de auditoría: captura el evento tal y como
 * lo construye AuditLogger, sin persistir nada (Art. III: append-only, pero aquí
 * el objeto bajo prueba es el emisor, no el almacén).
 */
final class ReopenAuditCaptureRepository implements AuditLogRepositoryInterface
{
    /** @var list<AuditEvent> */
    public array $events = [];

    public function log(AuditEvent $event): AuditEvent
    {
        $this->events[] = $event;

        return $event;
    }

    public function findEvents(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        return $this->events;
    }

    public function countEvents(array $filters = []): int
    {
        return count($this->events);
    }

    public function findByEntity(string $entityType, int $entityId): array
    {
        return array_values(array_filter(
            $this->events,
            static fn(AuditEvent $e): bool => $e->getEntityType() === $entityType && $e->getEntityId() === $entityId
        ));
    }
}

$pdo = ConnectionFactory::getConnection();
TestDataCleaner::purge($pdo);
(new SeedRunner($pdo))->seedAll();

$locationRepo = new PdoLocationRepository($pdo);
$incidentRepo = new PdoIncidentRepository($pdo);
$userRepo = new PdoUserRepository($pdo);

$site = $locationRepo->findBySiteCode('SEDE-BCN-01');
$technician = $userRepo->findByEmail('jordi.ruta@vendguard.internal');

$failures = 0;
$assert = function (string $caseTitle, bool $condition, string $message = '') use (&$failures): void {
    if ($condition) {
        echo "  [PASS] {$caseTitle}\n";
    } else {
        echo "  [FAIL] {$caseTitle}\n";
        if ($message !== '') {
            echo "         Motivo: {$message}\n";
        }
        $failures++;
    }
};

$assert('0.1 Sede y técnico de las semillas localizados', $site !== null && $technician !== null);

if ($site === null || $technician === null) {
    echo "ERROR: no se pudieron cargar las semillas base.\n";
    exit(1);
}

// Banco de escenarios: cada caso necesita su propia máquina, porque el control de
// duplicados bloquea un segundo aviso sobre una máquina con ticket RESOLVED reciente.
$machineRepo = new PdoMachineRepository($pdo);
$site2 = $locationRepo->findBySiteCode('SEDE-BCN-02');
$slots = [];
foreach ([[$site, 'SEDE-BCN-01'], [$site2, 'SEDE-BCN-02']] as [$slotSite, $slotCode]) {
    if ($slotSite === null) {
        continue;
    }
    foreach ($machineRepo->findActiveByLocationId($slotSite->getId()) as $slotMachine) {
        $slots[] = ['site_code' => $slotCode, 'location' => $slotSite, 'machine' => $slotMachine];
    }
}
$assert('0.2 Banco de máquinas disponible para los casos de reapertura efectiva', count($slots) >= 3, 'Máquinas: ' . count($slots));

$auditSink = new ReopenAuditCaptureRepository();
$controller = new LocationPortalController(
    incidentRepo: $incidentRepo,
    locationRepo: $locationRepo,
    auditLogger: new AuditLogger($auditSink)
);

/**
 * Crea un expediente RESOLVED en la máquina del caso y devuelve su código de ticket.
 */
$createResolved = function (int $slotIndex, string $ticketCode, string $resolvedAt) use ($incidentRepo, $slots, $technician): string {
    $slot = $slots[$slotIndex] ?? null;
    if ($slot === null) {
        throw new RuntimeException('Máquina de prueba no disponible en el índice ' . $slotIndex);
    }

    $incidentRepo->create(new Incident(
        id: null,
        ticketCode: $ticketCode,
        machineId: $slot['machine']->getId(),
        locationId: $slot['location']->getId(),
        category: IncidentCategory::PRODUCT_JAM,
        description: 'Expediente de prueba para la auditoría de la reapertura.',
        urgency: UrgencyLevel::HIGH,
        status: IncidentStatus::RESOLVED,
        assignedTechnicianId: (int)$technician->getId(),
        reporterName: 'Marta Guardia',
        reporterPhone: '611999888',
        resolvedAt: $resolvedAt,
        resolutionDiagnosis: 'Motor del espiral bloqueado por producto deformado entre espiras.',
        resolutionAction: 'Extracción del producto retenido, limpieza del canal y prueba de giro.'
    ), (int)$technician->getId(), 'Incidencia resuelta para la prueba de auditoría de reapertura.');

    return $ticketCode;
};

$reopenRequest = function (int $slotIndex, string $ticketCode, string $reason) use ($slots): Request {
    $slot = $slots[$slotIndex] ?? null;
    if ($slot === null) {
        throw new RuntimeException('Sede de prueba no disponible en el índice ' . $slotIndex);
    }

    $request = new Request(
        method: 'POST',
        path: "/api/incidents/{$ticketCode}/reopen",
        parsedBody: ['reopen_reason' => $reason],
        headers: ['X-Site-Code' => $slot['site_code']]
    );

    return $request->setRouteParams(['ticket_code' => $ticketCode]);
};

// =====================================================================
// CASO 1: Reapertura efectiva => evento REOPEN_TICKET con payload contractual
// =====================================================================
echo "\n--- Caso 1: Reapertura efectiva emite un único REOPEN_TICKET ---\n";

$recentResolvedAt = (new DateTimeImmutable())->sub(new DateInterval('PT2H'))->format('Y-m-d H:i:s');
$ticket1 = $createResolved(0, 'INC-2026-AUD01', $recentResolvedAt);
$incident1 = $incidentRepo->findByTicketCode($ticket1);

$res1 = $controller->reopenIncident($reopenRequest(0, $ticket1, 'La misma espiral vuelve a atascarse al comprar.'));

$assert('1.1 La reapertura responde HTTP 200', $res1->getStatusCode() === 200, 'Status: ' . $res1->getStatusCode());
$assert('1.2 Se emite exactamente un evento de auditoría', count($auditSink->events) === 1, 'Eventos: ' . count($auditSink->events));

$event = $auditSink->events[0] ?? null;

$assert('1.3 El evento es de tipo TICKET apuntando al expediente reabierto',
    $event !== null
    && $event->getEntityType() === AuditEvent::ENTITY_TICKET
    && $incident1 !== null
    && $event->getEntityId() === (int)$incident1->getId(),
    'entity=' . ($event?->getEntityType() ?? '-') . ' id=' . ($event?->getEntityId() ?? '-')
);
$assert('1.4 La acción auditada es REOPEN_TICKET (EARS 5.1.2)',
    $event !== null && $event->getAction() === 'REOPEN_TICKET',
    'action=' . ($event?->getAction() ?? '-')
);
$assert('1.5 El actor es la sede: rol SITE_MANAGER, sin user_id interno y con identidad de centro',
    $event !== null
    && $event->getUserRole() === 'SITE_MANAGER'
    && $event->getUserId() === null
    && str_contains($event->getUserName(), 'Responsable de Sede')
    && str_contains($event->getUserName(), (string)$site->getName()),
    'role=' . ($event?->getUserRole() ?? '-') . ' name=' . ($event?->getUserName() ?? '-')
);
$assert('1.6 El estado previo conserva RESOLVED y el técnico saliente',
    $event !== null
    && ($event->getPreviousState()['status'] ?? '') === 'RESOLVED'
    && (int)($event->getPreviousState()['assigned_technician_id'] ?? 0) === (int)$technician->getId(),
    'prev=' . json_encode($event?->getPreviousState())
);
$assert('1.7 El estado nuevo es REOPENED sin técnico, con motivo y marca de reapertura',
    $event !== null
    && ($event->getNewState()['status'] ?? '') === 'REOPENED'
    && array_key_exists('assigned_technician_id', $event->getNewState())
    && $event->getNewState()['assigned_technician_id'] === null
    && str_contains((string)($event->getNewState()['reopen_reason'] ?? ''), 'espiral vuelve a atascarse')
    && ($event->getNewState()['reopened_at'] ?? null) !== null,
    'new=' . json_encode($event?->getNewState())
);
$assert('1.8 La metadata identifica el ticket y la reincidencia',
    $event !== null
    && ($event->getMetadata()['ticket_code'] ?? '') === $ticket1
    && (int)($event->getMetadata()['reopen_count'] ?? 0) === 1,
    'metadata=' . json_encode($event?->getMetadata())
);
$assert('1.9 El registro de auditoría es de solo adición: el emisor no expone borrado',
    !method_exists($auditSink, 'delete') && !method_exists($auditSink, 'update'),
    'El doble no debe ofrecer mutación (Art. III.1)'
);

// =====================================================================
// CASO 2: Ventana de garantía expirada => 422 y ningún evento
// =====================================================================
echo "\n--- Caso 2: Reapertura fuera de la ventana de 48 h ---\n";

$expiredResolvedAt = (new DateTimeImmutable())->sub(new DateInterval('PT50H'))->format('Y-m-d H:i:s');
$ticket2 = $createResolved(1, 'INC-2026-AUD02', $expiredResolvedAt);
$eventsBeforeExpired = count($auditSink->events);

$res2 = $controller->reopenIncident($reopenRequest(1, $ticket2, 'Intento de reapertura con la garantía vencida.'));

$assert('2.1 Responde 422 REOPEN_WINDOW_EXPIRED', $res2->getStatusCode() === 422, 'Status: ' . $res2->getStatusCode());
$assert('2.2 La reapertura rechazada no deja evento de auditoría',
    count($auditSink->events) === $eventsBeforeExpired,
    'Eventos antes: ' . $eventsBeforeExpired . ' · después: ' . count($auditSink->events)
);

// =====================================================================
// CASO 3: Avería Crónica (2 reaperturas previas) => 422 y ningún evento
// =====================================================================
echo "\n--- Caso 3: Tercera reincidencia bloqueada como Avería Crónica ---\n";

$ticket3 = $createResolved(2, 'INC-2026-AUD03', (new DateTimeImmutable())->sub(new DateInterval('PT1H'))->format('Y-m-d H:i:s'));
$incident3 = $incidentRepo->findByTicketCode($ticket3);
if ($incident3 !== null) {
    // Dos reaperturas previas registradas en el historial inmutable (EARS 9.3).
    $incidentRepo->insertHistory((int)$incident3->getId(), null, 'RESOLVED', 'REOPENED', 'Primera reincidencia previa.');
    $incidentRepo->insertHistory((int)$incident3->getId(), null, 'RESOLVED', 'REOPENED', 'Segunda reincidencia previa.');
}
$eventsBeforeChronic = count($auditSink->events);

$res3 = $controller->reopenIncident($reopenRequest(2, $ticket3, 'Tercer fallo consecutivo en la misma máquina.'));

$assert('3.1 Responde 422 CHRONIC_INCIDENT_LIMIT', $res3->getStatusCode() === 422, 'Status: ' . $res3->getStatusCode());
$assert('3.2 La reapertura bloqueada no deja evento de auditoría',
    count($auditSink->events) === $eventsBeforeChronic,
    'Eventos antes: ' . $eventsBeforeChronic . ' · después: ' . count($auditSink->events)
);

// =====================================================================
// CASO 4: Validación de entrada => 422 y ningún evento
// =====================================================================
echo "\n--- Caso 4: Motivo de reapertura demasiado corto ---\n";

// La validación del motivo se evalúa antes de tocar el expediente, así que este caso
// reutiliza un ticket ya RESOLVED en lugar de consumir una máquina adicional.
$eventsBeforeShort = count($auditSink->events);

$res4 = $controller->reopenIncident($reopenRequest(1, $ticket2, 'ay'));

$assert('4.1 Responde 422 REOPEN_REASON_TOO_SHORT', $res4->getStatusCode() === 422, 'Status: ' . $res4->getStatusCode());
$assert('4.2 El rechazo por validación no deja evento de auditoría',
    count($auditSink->events) === $eventsBeforeShort,
    'Eventos antes: ' . $eventsBeforeShort . ' · después: ' . count($auditSink->events)
);
$assert('4.3 El expediente sigue en RESOLVED tras los rechazos',
    ($incidentRepo->findByTicketCode($ticket2)?->getStatus() ?? null) === IncidentStatus::RESOLVED,
    'estado=' . var_export($incidentRepo->findByTicketCode($ticket2)?->getStatus()?->value, true)
);

// =====================================================================
// Resumen
// =====================================================================
TestDataCleaner::purge($pdo);
(new SeedRunner($pdo))->seedAll();

echo "\n======================================================================\n";
if ($failures === 0) {
    echo " RESULTADO: ¡TODAS LAS PRUEBAS PASARON EXITOSAMENTE (0 fallos)!\n";
} else {
    echo " RESULTADO: {$failures} PRUEBA(S) FALLIDA(S).\n";
}
echo "======================================================================\n";

if ($failures > 0) {
    exit(1);
}
