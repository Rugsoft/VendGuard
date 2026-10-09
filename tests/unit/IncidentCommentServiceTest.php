<?php

declare(strict_types=1);

/**
 * IncidentCommentServiceTest
 *
 * Suite unitaria del servicio del hilo de comentarios (Módulo 10, T-COM-03).
 *
 * Valida la condición "Hecho cuando" sin base de datos (dobles en memoria):
 * - Segregación estricta para Sede: cero notas internas y cero campo `is_internal`
 *   en la proyección (RF-02.1 / Art. V.4 / RNF-01).
 * - Enmascaramiento oficial del técnico ante la Sede (RF-02.2) e identidad
 *   nominal completa para Técnicos y Coordinadores (RF-02.3).
 * - Rechazo de textos < 5 o > 1.000 caracteres (RF-03.1).
 * - Máquina de estados: activos y RESOLVED ≤ 48 h admiten mensajes; CLOSED,
 *   CANCELLED y RESOLVED > 48 h se sellan con ConversationSealedException
 *   (RF-05.1, RF-05.2, RF-05.3 / Art. V.6).
 * - Fail-safe de clasificación: Sede siempre público; Técnico/Coordinador
 *   con nota interna por defecto (RF-03.2, RF-03.3).
 * - Auditoría inmutable INCIDENT_COMMENT_ADDED (RF-06.3).
 * - Serialización inmutable de DTOs (T-COM-01).
 *
 * Dogma Vanilla: PHP 8.2 puro, sin dependencias ni base de datos.
 */

require_once __DIR__ . '/../../src/autoload.php';

use VendGuard\Application\DTO\IncidentCommentItemDto;
use VendGuard\Application\DTO\IncidentCommentThreadDto;
use VendGuard\Application\Service\AuditLogger;
use VendGuard\Application\Service\IncidentCommentService;
use VendGuard\Core\Domain\Exception\ConversationSealedException;
use VendGuard\Core\Domain\Exception\InvalidCommentLengthException;
use VendGuard\Core\Domain\Exception\InvalidUploadException;
use VendGuard\Core\Domain\Model\AuditEvent;
use VendGuard\Core\Domain\Model\Incident;
use VendGuard\Core\Domain\Model\IncidentComment;
use VendGuard\Core\Domain\Repository\AuditLogRepositoryInterface;
use VendGuard\Core\Domain\Repository\IncidentRepositoryInterface;
use VendGuard\Infrastructure\Storage\LocalFileUploader;

/**
 * Doble en memoria del repositorio de incidencias para el hilo de comentarios.
 */
final class CommentThreadStubIncidentRepo implements IncidentRepositoryInterface
{
    public ?array $detail = null;
    /** @var list<IncidentComment> */
    public array $storedComments = [];
    /** @var list<array<string, mixed>> */
    public array $auditTrail = [];

    public function findEnrichedDetailById(int|string $identifier): ?array
    {
        return $this->detail;
    }

    public function getCommentsPaged(int $incidentId, bool $includeInternal, int $limit = 50, ?int $beforeId = null): array
    {
        $rows = array_values(array_filter(
            $this->storedComments,
            fn(IncidentComment $c) => $c->getIncidentId() === $incidentId
                && ($includeInternal || !$c->isInternal())
        ));
        usort($rows, fn(IncidentComment $a, IncidentComment $b) => [$a->getCreatedAt(), $a->getId()] <=> [$b->getCreatedAt(), $b->getId()]);
        if ($beforeId !== null) {
            $rows = array_values(array_filter($rows, fn(IncidentComment $c) => (int)$c->getId() < $beforeId));
        }
        $rows = array_slice($rows, -$limit, $limit);

        return $rows;
    }

    public function countComments(int $incidentId, bool $includeInternal): int
    {
        $rows = array_filter(
            $this->storedComments,
            fn(IncidentComment $c) => $c->getIncidentId() === $incidentId
                && ($includeInternal || !$c->isInternal())
        );

        return count($rows);
    }

    public function addComment(IncidentComment $comment): IncidentComment
    {
        $reflection = new ReflectionProperty($comment, 'id');
        $reflection->setValue($comment, count($this->storedComments) + 101);
        $this->storedComments[] = $comment;

        return $comment;
    }

    public function recordAudit(array $payload): void
    {
        $this->auditTrail[] = $payload;
    }

    // Métodos no usados por el servicio bajo prueba.
    public function create(Incident $incident, ?int $userId = null, ?string $initialNote = null): Incident { throw new LogicException('Not used.'); }
    public function findById(int $id): ?Incident { return null; }
    public function findByTicketCode(string $ticketCode): ?Incident { return null; }
    public function findActiveByMachineId(int $machineId): ?Incident { return null; }
    public function findActiveOrResolvedByMachineId(int $machineId): ?Incident { return null; }
    public function findAllByLocation(int $locationId, bool $activeOnly = false): array { return []; }
    public function findAllByMachineId(int $machineId, array $excludeStatuses = ['CANCELLED']): array { return []; }
    public function findAll(array $filters = []): array { return []; }
    public function findAssignedToTechnician(int $technicianId, array $statuses = []): array { return []; }
    public function update(Incident $incident): bool { return false; }
    public function softDelete(int $id): bool { return false; }
    public function insertHistory(int $incidentId, ?int $userId, ?string $fromStatus, string $toStatus, ?string $actionNote = null): int { return 1; }
    public function getHistory(int $incidentId): array { return []; }
    public function getComments(int $incidentId, bool $includeInternal = true): array { return []; }
    public function countReopenEvents(int $incidentId): int { return 0; }
    public function markAsChronic(int $incidentId): bool { return false; }
    public function assign(int $incidentId, int $technicianId, ?int $coordinatorId = null, ?string $urgencyOverride = null, ?string $urgencyReason = null): Incident { throw new LogicException('Not used.'); }
    public function reopen(int $incidentId, string $reasonText): Incident { throw new LogicException('Not used.'); }
    public function cancel(int $incidentId, string $cancellationReason, ?int $coordinatorId = null): Incident { throw new LogicException('Not used.'); }
    public function startIntervention(int $incidentId, int $technicianId): Incident { throw new LogicException('Not used.'); }
    public function pauseIntervention(int $incidentId, int $technicianId, string $pendingPartsReason): Incident { throw new LogicException('Not used.'); }
    public function resolve(int $incidentId, int $technicianId, string $diagnosis, string $action): Incident { throw new LogicException('Not used.'); }
    public function autoCloseResolvedIncidents(int $hours = 48): array { return []; }
    public function recordPauseEvent(int $incidentId, int $userId, \VendGuard\Core\Domain\ValueObject\IncidentStatus $fromStatus, \VendGuard\Core\Domain\ValueObject\IncidentPauseReasonCategory $category, string $reasonText, \DateTimeImmutable $pausedAt): void { throw new LogicException('Not used.'); }
    public function recordResumeEvent(int $incidentId, ?int $userId, \VendGuard\Core\Domain\ValueObject\IncidentStatus $targetStatus, int $pauseDurationSeconds, ?string $note, \DateTimeImmutable $resumedAt): void { throw new LogicException('Not used.'); }
    public function getPendingInfoIncidentsOlderThanHours(int $hours): array { throw new LogicException('Not used.'); }
}

/**
 * Doble del repositorio de auditoría: captura los eventos emitidos.
 */
final class CommentThreadStubAuditRepo implements AuditLogRepositoryInterface
{
    /** @var list<AuditEvent> */
    public array $events = [];

    public function log(AuditEvent $event): AuditEvent
    {
        $this->events[] = $event;

        return $event;
    }

    public function findEvents(array $filters = [], int $limit = 50, int $offset = 0): array { return $this->events; }
    public function countEvents(array $filters = []): int { return count($this->events); }
    public function findByEntity(string $entityType, int $entityId): array { return []; }
}

/**
 * Doble del gestor de subidas: registra si fue invocado.
 */
final class CommentThreadStubUploader extends LocalFileUploader
{
    public bool $called = false;

    public function upload(array $file): string
    {
        $this->called = true;

        return '/uploads/stub_evidence.jpg';
    }
}

echo "======================================================================\n";
echo " VendGuard: Suite Unitaria - IncidentCommentService (Módulo 10, T-COM-03)\n";
echo "======================================================================\n\n";

$assertions = 0;
$failures = 0;

$assert = function (string $caseTitle, bool $condition, string $message = '') use (&$assertions, &$failures): void {
    $assertions++;
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

// ─── Fábricas de dobles ──────────────────────────────────────────────────────
$makeDetail = function (string $status, ?string $resolvedAt = null, ?int $assignedTechnicianId = null): array {
    return [
        'incident' => [
            'id' => 142,
            'ticket_code' => 'TICK-2026-00142',
            'status' => $status,
            'resolved_at' => $resolvedAt,
            'assigned_technician_id' => $assignedTechnicianId,
        ],
        'machine' => ['id' => 7, 'code' => 'VEN-BCN-001', 'model' => 'CoffeMax Pro 3000'],
        'location' => ['id' => 3, 'name' => 'Hospital del Mar'],
        'technician' => ['id' => 55, 'name' => 'Carlos Pérez'],
        'history' => [],
        'comments' => [],
        'requested_parts' => [],
        'replaced_parts' => [],
        'refund' => null,
    ];
};

$makeComment = function (int $id, string $authorType, string $authorName, string $text, bool $isInternal, ?int $userId, string $createdAt) use ($makeDetail): IncidentComment {
    return new IncidentComment(
        id: $id,
        incidentId: 142,
        authorType: $authorType,
        userId: $userId,
        authorName: $authorName,
        commentText: $text,
        photoPath: null,
        isInternal: $isInternal,
        createdAt: $createdAt,
        ticketCode: 'TICK-2026-00142'
    );
};

$buildService = function (array $detail, array $comments = []) use ($makeComment) {
    $repo = new CommentThreadStubIncidentRepo();
    $repo->detail = $detail;
    $repo->storedComments = $comments;

    $auditRepo = new CommentThreadStubAuditRepo();
    $uploader = new CommentThreadStubUploader('php://memory');
    $service = new IncidentCommentService($repo, new AuditLogger($auditRepo), $uploader);

    return [$service, $repo, $auditRepo, $uploader];
};

$INTERNAL_AUTHOR = ['id' => 55, 'role' => 'TECHNICIAN', 'name' => 'Carlos Pérez'];

// =========================================================================
// CASO 1: Segregación estricta y enmascaramiento para la Sede (RF-02.1, RF-02.2 / Art. V.4)
// =========================================================================
echo "\n--- Caso 1: Sede ve solo públicos y autores enmascarados (Art. V.4) ---\n";

$detail = $makeDetail('IN_PROGRESS');
$comments = [
    $makeComment(12, 'REPORTER', 'Conserjería Principal (Juan Gómez)', 'La máquina está en la 3ª planta junto a los ascensores B.', false, null, '2026-10-06 10:15:30'),
    $makeComment(35, 'TECHNICIAN', 'Carlos Pérez', 'Ojo: fusible de fuente recalentado, posible corto en electroválvula.', true, 55, '2026-10-06 10:45:00'),
    $makeComment(48, 'TECHNICIAN', 'Carlos Pérez', 'Llegando al edificio. Accedo por conserjería en 10 minutos.', false, 55, '2026-10-06 11:30:12'),
    $makeComment(49, 'COORDINATOR', 'Sara Coordinadora', 'Priorizad esta avería en la ruta de esta tarde.', true, 9, '2026-10-06 11:40:00'),
    $makeComment(50, 'COORDINATOR', 'Sara Coordinadora', 'La avería quedó registrada y será atendida hoy mismo.', false, 9, '2026-10-06 11:50:00'),
];
[$service, $repo] = $buildService($detail, $comments);

$siteThread = $service->getThread(142, 'SITE_MANAGER');
$siteComments = $siteThread->comments;
// JSON_UNESCAPED_UNICODE: sin esta bandera los acentos viajan escapados
// (P\u00e9rez) y la búsqueda de fugas de nombres reales sería vacua (T-COM-04).
$siteSerialized = json_encode($siteThread, JSON_UNESCAPED_UNICODE);

$assert("1.1 La Sede recibe solo los 3 comentarios públicos (RF-02.1)", count($siteComments) === 3);
$assert("1.2 El técnico aparece enmascarado como 'Servicio Técnico Oficial (Operador #55)'",
    ($siteComments[1]->authorName ?? '') === 'Servicio Técnico Oficial (Operador #55)');
$assert("1.3 El coordinador público aparece enmascarado como 'Coordinación Central de Operaciones'",
    ($siteComments[2]->authorName ?? '') === 'Coordinación Central de Operaciones');
$assert("1.4 Ningún mensaje contiene el nombre real 'Carlos Pérez' (Art. V.4)",
    $siteSerialized !== false && !str_contains($siteSerialized, 'Carlos Pérez'));
$assert("1.5 El JSON de la Sede no contiene 'is_internal' ni metadatos de confidencialidad (RNF-01)",
    $siteSerialized !== false && !str_contains($siteSerialized, 'is_internal'));
$assert("1.6 El recuento total transmitido a la Sede es el recuento público (3), no el total real (5)",
    $siteThread->pagination['total_comments'] === 3);

// =========================================================================
// CASO 2: Visibilidad íntegra y nominal para Técnico y Coordinador (RF-02.3, RF-02.4)
// =========================================================================
echo "\n--- Caso 2: Técnico/Coordinador ven todo con identidad real y candado ---\n";

$techThread = $service->getThread(142, 'TECHNICIAN', 55);
$assert("2.1 El técnico recibe los 5 mensajes (públicos e internos)", count($techThread->comments) === 5);
$assert("2.2 Los nombres reales permanecen sin enmascarar",
    ($techThread->comments[1]->authorName ?? '') === 'Carlos Pérez' && ($techThread->comments[3]->authorName ?? '') === 'Sara Coordinadora');
$assert("2.3 Las notas internas llegan con is_internal=true (candado, RF-02.4)",
    ($techThread->comments[1]->isInternal ?? null) === true && ($techThread->comments[3]->isInternal ?? null) === true);
$assert("2.4 La marca de mensaje propio se calcula por userId del llamante",
    ($techThread->comments[1]->isOwnMessage ?? null) === true && ($techThread->comments[0]->isOwnMessage ?? null) === false);
$assert("2.5 El recuento total para el técnico es 5 (públicos + internos)",
    $techThread->pagination['total_comments'] === 5);

// =========================================================================
// CASO 3: Longitud del mensaje (RF-03.1)
// =========================================================================
echo "\n--- Caso 3: Límites de 5 a 1.000 caracteres descriptivos ---\n";

$tooShort = '';
try {
    $service->addComment(142, 'TECHNICIAN', 'hola', $INTERNAL_AUTHOR);
} catch (InvalidCommentLengthException $e) {
    $tooShort = $e->getErrorCode();
}
$assert("3.1 Texto de 4 caracteres => InvalidCommentLengthException (422)", $tooShort === 'INVALID_COMMENT_LENGTH');

$tooLong = '';
try {
    $service->addComment(142, 'TECHNICIAN', str_repeat('a', 1001), $INTERNAL_AUTHOR);
} catch (InvalidCommentLengthException $e) {
    $tooLong = $e->getErrorCode();
}
$assert("3.2 Texto de 1.001 caracteres => InvalidCommentLengthException (422)", $tooLong === 'INVALID_COMMENT_LENGTH');

[$service2, $repo2, $auditRepo2] = $buildService($detail, $comments);
$service2->addComment(142, 'TECHNICIAN', str_repeat('a', 1000), $INTERNAL_AUTHOR);
$lastStored = end($repo2->storedComments);
$assert("3.3 Texto de 1.000 caracteres exactos se acepta (límite inclusivo)",
    $lastStored !== false && $lastStored->getCommentText() === str_repeat('a', 1000));

[$service3, $repo3] = $buildService($detail, $comments);
$accepted = true;
try {
    $service3->addComment(142, 'TECHNICIAN', '12345', $INTERNAL_AUTHOR);
} catch (InvalidCommentLengthException) {
    $accepted = false;
}
$assert("3.4 El mensaje de 5 caracteres exactos se acepta (límite inclusivo)", $accepted);

// =========================================================================
// CASO 4: Máquina de estados del sellado (RF-05 / Art. V.6)
// =========================================================================
echo "\n--- Caso 4: Máquina de estados (activos, garantía 48 h, sellados) ---\n";

foreach (['REGISTERED', 'ASSIGNED', 'IN_PROGRESS', 'PENDING_PARTS', 'REOPENED'] as $activeStatus) {
    [$svc] = $buildService($makeDetail($activeStatus));
    $assert("4.1 Estado {$activeStatus} admite nuevos mensajes (RF-05.1)", $svc->acceptsNewComments(['status' => $activeStatus]));
}

[$svc] = $buildService($makeDetail('RESOLVED', date('Y-m-d H:i:s', time() - 10 * 3600)));
$assert("4.2 RESOLVED hace 10 h admite mensajes (garantía 48 h, RF-05.2)", $svc->acceptsNewComments(['status' => 'RESOLVED', 'resolved_at' => date('Y-m-d H:i:s', time() - 10 * 3600)]));
$assert("4.3 RESOLVED hace 50 h está sellado (Art. V.6)", !$svc->acceptsNewComments(['status' => 'RESOLVED', 'resolved_at' => date('Y-m-d H:i:s', time() - 50 * 3600)]));
$assert("4.4 CLOSED está sellado (RF-05.3)", !$svc->acceptsNewComments(['status' => 'CLOSED']));
$assert("4.5 CANCELLED está sellado (RF-05.3)", !$svc->acceptsNewComments(['status' => 'CANCELLED']));

[$svc] = $buildService($makeDetail('CLOSED'));
$sealedError = '';
try {
    $svc->addComment(142, 'SITE_MANAGER', 'mensaje válido de sede', null);
} catch (ConversationSealedException $e) {
    $sealedError = $e->getErrorCode();
}
$assert("4.6 POST sobre expediente CLOSED => ConversationSealedException (403, RF-05.3)", $sealedError === 'CONVERSATION_SEALED');

// =========================================================================
// CASO 5: Fail-safe de clasificación (RF-03.2, RF-03.3)
// =========================================================================
echo "\n--- Caso 5: Clasificación por defecto según el rol ---\n";

[$svc, $repo] = $buildService($makeDetail('IN_PROGRESS'), $comments);
$svc->addComment(142, 'SITE_MANAGER', 'mensaje público de la sede', null, true);
$siteMsg = end($repo->storedComments);
$assert("5.1 La Sede publica SIEMPRE público aunque intente forzar is_internal=true (RF-03.2)",
    $siteMsg !== false && $siteMsg->isInternal() === false && $siteMsg->getAuthorType() === 'REPORTER');

[$svc, $repo] = $buildService($makeDetail('IN_PROGRESS'), $comments);
$svc->addComment(142, 'TECHNICIAN', 'diagnóstico delicado del técnico', $INTERNAL_AUTHOR);
$techMsg = end($repo->storedComments);
$assert("5.2 Técnico sin selector => nota interna por defecto (fail-safe, RF-03.3)",
    $techMsg !== false && $techMsg->isInternal() === true);

[$svc, $repo] = $buildService($makeDetail('IN_PROGRESS'), $comments);
$svc->addComment(142, 'TECHNICIAN', 'mensaje para la sede', $INTERNAL_AUTHOR, false);
$techPublicMsg = end($repo->storedComments);
$assert("5.3 Técnico puede clasificar explícitamente como público",
    $techPublicMsg !== false && $techPublicMsg->isInternal() === false);

// =========================================================================
// CASO 6: Auditoría inmutable (RF-06.3 / Art. III)
// =========================================================================
echo "\n--- Caso 6: Auditoría INCIDENT_COMMENT_ADDED ---\n";

[$svc, $repo, $auditRepo] = $buildService($makeDetail('IN_PROGRESS'), $comments);
$svc->addComment(142, 'COORDINATOR', 'directriz de reparación para el técnico', ['id' => 9, 'role' => 'COORDINATOR', 'name' => 'Sara Coordinadora'], true);

$auditEvents = $auditRepo->events;
$assert("6.1 Se emitió exactamente un evento de auditoría por publicación", count($auditEvents) === 1);
$firstEvent = $auditEvents[0] ?? null;
$assert("6.2 La acción registrada es INCIDENT_COMMENT_ADDED",
    $firstEvent !== null && $firstEvent->getAction() === 'INCIDENT_COMMENT_ADDED');
$auditJson = $firstEvent !== null ? json_encode($firstEvent) : '';
$assert("6.3 El evento contiene la clasificación público/interna del mensaje",
    $auditJson !== false && str_contains((string)$auditJson, 'is_internal'));

// =========================================================================
// CASO 7: Expediente inexistente y foto (RF-04.2 vía LocalFileUploader real)
// =========================================================================
echo "\n--- Caso 7: Expediente inexistente y evidencia fotográfica ---\n";

$missingRepo = new CommentThreadStubIncidentRepo();
$missingRepo->detail = null;
$missingService = new IncidentCommentService($missingRepo, new AuditLogger(new CommentThreadStubAuditRepo()), new CommentThreadStubUploader('php://memory'));
$notFound = false;
try {
    $missingService->getThread('TICK-INEXISTENTE', 'SITE_MANAGER');
} catch (\DomainException $e) {
    $notFound = $e->getCode() === 404;
}
$assert("7.1 Expediente inexistente => DomainException 404", $notFound);

[$svc, $repo, $auditRepo, $uploader] = $buildService($makeDetail('IN_PROGRESS'), $comments);
$svc->addComment(142, 'TECHNICIAN', 'adjunto evidencia gráfica del atasco', $INTERNAL_AUTHOR, null, ['tmp_name' => 'php://memory', 'error' => UPLOAD_ERR_OK, 'size' => 10]);
$assert("7.2 La foto se delega al gestor de subidas validado (Art. V.5)", $uploader->called === true);
$photoMsg = end($repo->storedComments);
$assert("7.3 El mensaje persiste la ruta pública de la evidencia",
    $photoMsg !== false && $photoMsg->getPhotoPath() === '/uploads/stub_evidence.jpg');

// =========================================================================
// CASO 8: Permiso efectivo de publicación del hilo (RF-05.1 a RF-05.4)
// =========================================================================
echo "\n--- Caso 8: can_comment y solo lectura por reapertura sin reasignar ---\n";

// 8.1 Técnico asignado: publica con normalidad.
[$svcAssigned] = $buildService($makeDetail('IN_PROGRESS', null, 55), $comments);
$assignedThread = $svcAssigned->getThread(142, 'TECHNICIAN', 55);
$assert("8.1 Técnico asignado => can_comment true sin motivo de solo lectura",
    ($assignedThread->incident['can_comment'] ?? null) === true
    && array_key_exists('read_only_reason', $assignedThread->incident)
    && $assignedThread->incident['read_only_reason'] === null);

// 8.2 Técnico con antecedentes sobre un expediente reabierto y sin asignar: solo lectura.
[$svcReopened] = $buildService($makeDetail('REOPENED', null, null), $comments);
$reopenedThread = $svcReopened->getThread(142, 'TECHNICIAN', 55);
$assert("8.2 Técnico desasignado en REOPENED => can_comment false con motivo explícito",
    ($reopenedThread->incident['can_comment'] ?? null) === false
    && ($reopenedThread->incident['read_only_reason'] ?? '') === 'REOPENED_AWAITING_REASSIGNMENT');
$assert("8.3 La consulta en solo lectura conserva la proyección íntegra del canal técnico",
    count($reopenedThread->comments) === 5
    && ($reopenedThread->comments[1]->isInternal ?? null) === true
    && ($reopenedThread->comments[1]->authorName ?? '') === 'Carlos Pérez');
$assert("8.4 El expediente reabierto no está sellado: la lectura sigue viva (RF-05.4)",
    ($reopenedThread->incident['is_sealed'] ?? null) === false);

// 8.5 Sede y coordinación sí publican en el expediente reabierto (EARS 9.1 lo devuelve a triaje).
[$svcSiteReopened] = $buildService($makeDetail('REOPENED', null, null), $comments);
$siteReopenedThread = $svcSiteReopened->getThread(142, 'SITE_MANAGER');
$coordReopenedThread = $svcSiteReopened->getThread(142, 'COORDINATOR', 9);
$assert("8.5 Sede y coordinación conservan can_comment true en el expediente reabierto",
    ($siteReopenedThread->incident['can_comment'] ?? null) === true
    && ($coordReopenedThread->incident['can_comment'] ?? null) === true);

// 8.6 Tras la reasignación, el técnico recupera la publicación.
[$svcReassigned] = $buildService($makeDetail('REOPENED', null, 55), $comments);
$reassignedThread = $svcReassigned->getThread(142, 'TECHNICIAN', 55);
$assert("8.6 Tras la reasignación el técnico vuelve a poder publicar",
    ($reassignedThread->incident['can_comment'] ?? null) === true
    && array_key_exists('read_only_reason', $reassignedThread->incident)
    && $reassignedThread->incident['read_only_reason'] === null);

// 8.7 El sellado se comunica con su propia marca, sin motivo de reapertura.
[$svcSealed] = $buildService($makeDetail('CLOSED', null, 55), $comments);
$sealedThread = $svcSealed->getThread(142, 'TECHNICIAN', 55);
$assert("8.7 Expediente sellado => is_sealed true, can_comment false y sin motivo de reapertura",
    ($sealedThread->incident['is_sealed'] ?? null) === true
    && ($sealedThread->incident['can_comment'] ?? null) === false
    && array_key_exists('read_only_reason', $sealedThread->incident)
    && $sealedThread->incident['read_only_reason'] === null);

// 8.8 El contrato del bloque incident expone las diez claves en orden estable.
$assert("8.8 El bloque incident expone el contrato completo del hilo",
    array_keys($assignedThread->incident) === [
        'id', 'ticket_code', 'machine_code', 'machine_model', 'location_name',
        'status', 'status_label', 'is_sealed', 'can_comment', 'read_only_reason',
    ],
    'Claves reales: ' . implode(', ', array_keys($assignedThread->incident)));

// ---------------------------------------------------------------------
// SUMMARY
// ---------------------------------------------------------------------
echo "\n======================================================================\n";
echo " Total Assertions: {$assertions} | Passed: " . ($assertions - $failures) . " | Failed: {$failures}\n";

if ($failures === 0) {
    echo " RESULT: 100% IN GREEN. INCIDENT COMMENT SERVICE (T-COM-03) FULFILLED.\n";
    echo "======================================================================\n";
    exit(0);
}

echo " RESULT: FAILURES DETECTED IN TEST SUITE.\n";
echo "======================================================================\n";
exit(1);
