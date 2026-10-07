<?php

declare(strict_types=1);

/**
 * CoordinatorIncidentCommentControllerTest
 *
 * Suite de pruebas unitarias del hilo de conversación en la bitácora del modal de
 * triaje. Migrada en la tarea T-COM-07 (módulo 10) desde el contrato del módulo 09
 * (T-IDM-06) al contrato unificado del hilo de comentarios, ahora orquestado por
 * `IncidentCommentService`. Valida la condición "Hecho cuando":
 * - `CoordinatorController::getComments(Request $request)` responde 200 OK con el
 *   hilo ÍNTEGRO: comentarios públicos y notas internas de taller, `is_internal`
 *   expuesto y nombres reales del equipo (RF-01.2, RF-02.3).
 * - `CoordinatorController::addComment(Request $request)` publica tanto mensajes
 *   públicos como notas internas de coordinación, con el selector de privacidad
 *   `is_internal` PRESELECCIONADO a `true` cuando el cuerpo no lo trae (RF-03.3),
 *   soporte `multipart/form-data` y validación binaria de la fotografía (Art. V.5).
 * - Cada escritura emite el evento inmutable INCIDENT_COMMENT_ADDED en `audit_log`
 *   con el coordinador autenticado, el código del ticket y la visibilidad (Art. III.3).
 * - Máquina de estados: CLOSED/CANCELLED y RESOLVED fuera de la garantía de 48 h
 *   quedan sellados con 403 CONVERSATION_SEALED (Art. V.6), mientras que un ticket
 *   activo o resuelto dentro de la ventana admite mensajes.
 * - RBAC en servidor (401/403) y validación temprana del cuerpo (400 texto ausente,
 *   422 longitud fuera de rango, 422 is_internal ilegible, 400 identificador inválido).
 *
 * Dogma Vanilla: PHP 8.2 puro, sin dependencias externas y sin base de datos; los
 * repositorios de incidencias y de auditoría se sustituyen por dobles en memoria y el
 * `LocalFileUploader` es real sobre un directorio temporal para certificar `finfo`.
 */

require_once __DIR__ . '/../../src/autoload.php';

use VendGuard\Application\Service\AuditLogger;
use VendGuard\Application\Service\IncidentCommentService;
use VendGuard\Core\Domain\Model\AuditEvent;
use VendGuard\Core\Domain\Model\Incident;
use VendGuard\Core\Domain\Model\IncidentComment;
use VendGuard\Core\Domain\Model\User;
use VendGuard\Core\Domain\Model\UserRole;
use VendGuard\Core\Domain\Repository\AuditLogRepositoryInterface;
use VendGuard\Core\Domain\Repository\IncidentRepositoryInterface;
use VendGuard\Core\Domain\ValueObject\IncidentCategory;
use VendGuard\Core\Domain\ValueObject\IncidentStatus;
use VendGuard\Core\Domain\ValueObject\UrgencyLevel;
use VendGuard\Infrastructure\Storage\LocalFileUploader;
use VendGuard\Presentation\Controller\CoordinatorController;
use VendGuard\Presentation\Http\Request;

/**
 * Doble en memoria del contrato de repositorio de incidencias: sirve el detalle
 * enriquecido del expediente, registra los identificadores consultados y captura
 * los comentarios persistidos y los recuentos segregados del hilo.
 */
final class CoordinatorIncidentCommentStubIncidentRepo implements IncidentRepositoryInterface
{
    public ?array $detail = null;

    /** @var list<int|string> */
    public array $detailIdentifierCalls = [];

    /** @var list<int> */
    public array $findByIdCalls = [];

    /** @var list<string> */
    public array $findByTicketCodeCalls = [];

    /** @var list<IncidentComment> */
    public array $addedComments = [];

    public function findEnrichedDetailById(int|string $identifier): ?array
    {
        $this->detailIdentifierCalls[] = $identifier;

        return $this->detail;
    }

    public function getCommentsPaged(int $incidentId, bool $includeInternal, int $limit = 50, ?int $beforeId = null): array
    {
        $rows = array_values(array_filter(
            $this->addedComments,
            fn(IncidentComment $c) => $c->getIncidentId() === $incidentId
                && ($includeInternal || !$c->isInternal())
        ));
        usort($rows, fn(IncidentComment $a, IncidentComment $b) => [$a->getCreatedAt(), $a->getId()] <=> [$b->getCreatedAt(), $b->getId()]);
        if ($beforeId !== null) {
            $rows = array_values(array_filter($rows, fn(IncidentComment $c) => (int)$c->getId() < $beforeId));
        }

        return array_slice($rows, -$limit, $limit);
    }

    public function countComments(int $incidentId, bool $includeInternal): int
    {
        return count(array_filter(
            $this->addedComments,
            fn(IncidentComment $c) => $c->getIncidentId() === $incidentId
                && ($includeInternal || !$c->isInternal())
        ));
    }

    public function addComment(IncidentComment $comment): IncidentComment
    {
        $reflection = new ReflectionProperty($comment, 'id');
        $reflection->setValue($comment, 900 + count($this->addedComments));
        $stored = new IncidentComment(
            id: 900 + count($this->addedComments),
            incidentId: $comment->getIncidentId(),
            authorType: $comment->getAuthorType(),
            userId: $comment->getUserId(),
            authorName: $comment->getAuthorName(),
            commentText: $comment->getCommentText(),
            photoPath: $comment->getPhotoPath(),
            isInternal: $comment->isInternal(),
            createdAt: '2026-10-05 12:00:00',
            ticketCode: $comment->getTicketCode()
        );
        $this->addedComments[] = $stored;

        return $stored;
    }

    public function findById(int $id): ?Incident
    {
        $this->findByIdCalls[] = $id;

        return $this->detail === null ? null : $this->buildIncidentFromDetail();
    }

    public function findByTicketCode(string $ticketCode): ?Incident
    {
        $this->findByTicketCodeCalls[] = $ticketCode;

        return $this->detail === null ? null : $this->buildIncidentFromDetail();
    }

    /**
     * Reconstruye la entidad de dominio desde el detalle enriquecido para las vías
     * de resolución legacy del controlador.
     */
    private function buildIncidentFromDetail(): Incident
    {
        $incidentRow = $this->detail['incident'];

        return new Incident(
            id: (int)$incidentRow['id'],
            ticketCode: (string)$incidentRow['ticket_code'],
            machineId: (int)($this->detail['machine']['id'] ?? 0),
            locationId: (int)($this->detail['location']['id'] ?? 0),
            category: IncidentCategory::TEMPERATURE_COLD,
            description: 'El compresor no arranca y los sándwiches superan los 9°C.',
            urgency: UrgencyLevel::CRITICAL,
            status: IncidentStatus::tryFrom((string)($incidentRow['status'] ?? '')) ?? IncidentStatus::REGISTERED,
            resolvedAt: $incidentRow['resolved_at'] ?? null,
            updatedAt: '2026-10-05 09:00:00',
            createdAt: '2026-10-01 08:15:00'
        );
    }

    public function create(Incident $incident, ?int $userId = null, ?string $initialNote = null): Incident { throw new LogicException('Not used.'); }
    public function findActiveByMachineId(int $machineId): ?Incident { return null; }
    public function findActiveOrResolvedByMachineId(int $machineId): ?Incident { return null; }
    public function findAllByMachineId(int $machineId, array $excludeStatuses = ['CANCELLED']): array { return []; }
    public function findAllByLocation(int $locationId, bool $activeOnly = false): array { return []; }
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
}

/**
 * Doble en memoria del registro append-only de auditoría: captura cada evento
 * emitido para poder certificar su contenido sin tocar MariaDB.
 */
final class CoordinatorIncidentCommentStubAuditRepo implements AuditLogRepositoryInterface
{
    /** @var list<AuditEvent> */
    public array $events = [];

    public function log(AuditEvent $event): AuditEvent
    {
        $this->events[] = $event;

        return $event;
    }

    public function findEvents(array $filters = [], int $limit = 50, int $offset = 0): array { return []; }
    public function countEvents(array $filters = []): int { return count($this->events); }
    public function findByEntity(string $entityType, int $entityId): array { return []; }
}

echo "======================================================================\n";
echo " VendGuard: Suite Unitaria - Hilo de Comentarios del Coordinador (Módulo 10, T-COM-07)\n";
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

// =====================================================================
// ARNÉS: controlador sobre dobles en memoria y fábrica de expedientes
// =====================================================================

$incidentRepo = new CoordinatorIncidentCommentStubIncidentRepo();
$auditRepo    = new CoordinatorIncidentCommentStubAuditRepo();
$uploadsDir   = sys_get_temp_dir() . '/vg_uploads_' . uniqid('', true);
$uploader     = new LocalFileUploader($uploadsDir);

$controller = new CoordinatorController(
    incidentRepo: $incidentRepo,
    auditLogger: new AuditLogger($auditRepo),
    fileUploader: $uploader,
    commentService: new IncidentCommentService($incidentRepo, new AuditLogger($auditRepo), $uploader)
);

/**
 * Detalle enriquecido del expediente tal y como lo entrega el repositorio PDO.
 */
$makeDetail = function (string $status, ?string $resolvedAt = null): array {
    return [
        'incident' => [
            'id' => 142,
            'ticket_code' => 'INC-2026-0142',
            'status' => $status,
            'resolved_at' => $resolvedAt,
        ],
        'machine' => ['id' => 10, 'code' => 'VEN-BCN-007', 'model' => 'SnackMax 4000'],
        'location' => ['id' => 1, 'name' => 'Hospital del Mar'],
        'comments' => [],
    ];
};

$makeComment = function (int $id, string $authorType, string $authorName, string $text, bool $isInternal, ?int $userId, string $createdAt): IncidentComment {
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
        ticketCode: 'INC-2026-0142'
    );
};

/** @return list<IncidentComment> */
$seedThread = function () use ($makeComment): array {
    return [
        $makeComment(12, 'REPORTER', 'Conserjería Principal (Juan Gómez)', 'La máquina está junto a los ascensores B.', false, null, '2026-10-06 10:15:30'),
        $makeComment(35, 'TECHNICIAN', 'Carlos Pérez', 'Fusible de fuente recalentado, posible corto.', true, 55, '2026-10-06 10:45:00'),
        $makeComment(48, 'TECHNICIAN', 'Carlos Pérez', 'Llegando al edificio en 10 minutos.', false, 55, '2026-10-06 11:30:12'),
        $makeComment(49, 'COORDINATOR', 'Marta Ferrer', 'Priorizad esta avería en la ruta de esta tarde.', true, 2, '2026-10-06 11:40:00'),
    ];
};

$resetHarness = function () use ($incidentRepo, $auditRepo): void {
    $incidentRepo->detailIdentifierCalls = [];
    $incidentRepo->findByIdCalls = [];
    $incidentRepo->findByTicketCodeCalls = [];
    $incidentRepo->addedComments = [];
    $auditRepo->events = [];
};

$makeGetRequest = function (string $routeIdentifier, array $query = [], array $attributes = null): Request {
    $attributes ??= ['user_id' => 2, 'user_role' => 'COORDINATOR'];

    $request = new Request(
        'GET',
        '/api/coordinator/incidents/' . rawurlencode($routeIdentifier) . '/comments',
        $query
    );
    $request->setRouteParams(['id' => $routeIdentifier]);

    foreach ($attributes as $key => $value) {
        $request->setAttribute($key, $value);
    }

    return $request;
};

/**
 * Construye la petición POST tal y como la entrega el enrutador: ruta con `{id}`
 * resuelto, cuerpo deserializado, archivos adjuntos y atributos del actor autenticado.
 *
 * @param array<string, mixed> $body
 * @param array<string, mixed> $files
 * @param array<string, mixed>|null $attributes
 */
$makePostRequest = function (string $routeIdentifier, array $body, array $attributes = null, array $files = []): Request {
    $attributes ??= ['user_id' => 2, 'user_role' => 'COORDINATOR'];

    $request = new Request(
        'POST',
        '/api/coordinator/incidents/' . rawurlencode($routeIdentifier) . '/comments',
        [],
        $body,
        [],
        $files
    );
    $request->setRouteParams(['id' => $routeIdentifier]);

    foreach ($attributes as $key => $value) {
        $request->setAttribute($key, $value);
    }

    return $request;
};

// =====================================================================
// CASO 1: Control de acceso RBAC (401 / 403) sin persistencia ni auditoría
// =====================================================================
echo "--- Caso 1: Control de acceso RBAC (401 / 403) ---\n";

$incidentRepo->detail = $makeDetail('PENDING_PARTS');
$resetHarness();

$resNoIdentity = $controller->addComment($makePostRequest('142', ['comment_text' => 'Comentario válido.'], []));
$assert(
    '1.1 POST sin identidad autenticada responde HTTP 401 Unauthorized',
    $resNoIdentity->getStatusCode() === 401 && ($resNoIdentity->getDecodedBody()['error']['code'] ?? '') === 'UNAUTHORIZED'
);

$resTechnician = $controller->addComment($makePostRequest('142', ['comment_text' => 'Comentario válido.'], ['user_id' => 4, 'user_role' => 'TECHNICIAN']));
$assert(
    '1.2 POST con rol TECHNICIAN responde HTTP 403 Forbidden',
    $resTechnician->getStatusCode() === 403 && ($resTechnician->getDecodedBody()['error']['code'] ?? '') === 'FORBIDDEN'
);

$resNoIdentityGet = $controller->getComments($makeGetRequest('142', [], []));
$resTechnicianGet = $controller->getComments($makeGetRequest('142', [], ['user_id' => 4, 'user_role' => 'TECHNICIAN']));
$assert(
    '1.3 GET también exige identidad y rol de Coordinación (401 / 403)',
    $resNoIdentityGet->getStatusCode() === 401 && $resTechnicianGet->getStatusCode() === 403
);

$assert(
    '1.4 Ninguna petición no autorizada persistió comentario ni evento de auditoría',
    $incidentRepo->addedComments === [] && $auditRepo->events === []
);

// =====================================================================
// CASO 2: Validación temprana del cuerpo y del identificador de ruta
// =====================================================================
echo "\n--- Caso 2: Validación del cuerpo y del identificador ---\n";

$resetHarness();

$resMissingText = $controller->addComment($makePostRequest('142', []));
$assert(
    '2.1 Sin comment_text responde HTTP 400 MISSING_COMMENT_TEXT',
    $resMissingText->getStatusCode() === 400 && ($resMissingText->getDecodedBody()['error']['code'] ?? '') === 'MISSING_COMMENT_TEXT'
);

$resBlankText = $controller->addComment($makePostRequest('142', ['comment_text' => '    ']));
$assert(
    '2.2 Texto en blanco responde HTTP 400 MISSING_COMMENT_TEXT',
    $resBlankText->getStatusCode() === 400 && ($resBlankText->getDecodedBody()['error']['code'] ?? '') === 'MISSING_COMMENT_TEXT'
);

$resShortText = $controller->addComment($makePostRequest('142', ['comment_text' => 'Ok.']));
$assert(
    '2.3 Texto de 3 caracteres responde HTTP 422 INVALID_COMMENT_LENGTH (RF-03.1)',
    $resShortText->getStatusCode() === 422 && ($resShortText->getDecodedBody()['error']['code'] ?? '') === 'INVALID_COMMENT_LENGTH'
);

$resLongText = $controller->addComment($makePostRequest('142', ['comment_text' => str_repeat('a', 1001)]));
$assert(
    '2.4 Texto de 1.001 caracteres responde HTTP 422 INVALID_COMMENT_LENGTH',
    $resLongText->getStatusCode() === 422 && ($resLongText->getDecodedBody()['error']['code'] ?? '') === 'INVALID_COMMENT_LENGTH'
);

$resInvalidFlag = $controller->addComment($makePostRequest('142', ['comment_text' => 'Nota de taller válida.', 'is_internal' => 'quizá']));
$assert(
    '2.5 is_internal no booleano responde HTTP 422 INVALID_IS_INTERNAL',
    $resInvalidFlag->getStatusCode() === 422 && ($resInvalidFlag->getDecodedBody()['error']['code'] ?? '') === 'INVALID_IS_INTERNAL'
);

$resInvalidId = $controller->addComment($makePostRequest('INC 2026/01', ['comment_text' => 'Nota de taller válida.']));
$resInvalidIdGet = $controller->getComments($makeGetRequest('INC 2026/01'));
$assert(
    '2.6 Identificador de ruta inválido responde HTTP 400 INVALID_INCIDENT_IDENTIFIER en POST y GET',
    $resInvalidId->getStatusCode() === 400
        && ($resInvalidId->getDecodedBody()['error']['code'] ?? '') === 'INVALID_INCIDENT_IDENTIFIER'
        && $resInvalidIdGet->getStatusCode() === 400
);

$assert(
    '2.7 Ningún cuerpo inválido persistió comentario ni evento de auditoría',
    $incidentRepo->addedComments === [] && $auditRepo->events === []
);

// =====================================================================
// CASO 3: Existencia y máquina de estados (sellado y ventana de 48 h)
// =====================================================================
echo "\n--- Caso 3: Existencia y ventana de comentarios ---\n";

$incidentRepo->detail = null;
$resetHarness();
$resNotFound = $controller->addComment($makePostRequest('INC-2099-0000', ['comment_text' => 'Comentario válido.']));
$resNotFoundGet = $controller->getComments($makeGetRequest('INC-2099-0000'));
$assert(
    '3.1 Ticket inexistente responde HTTP 404 INCIDENT_NOT_FOUND en POST y GET',
    $resNotFound->getStatusCode() === 404
        && ($resNotFound->getDecodedBody()['error']['code'] ?? '') === 'INCIDENT_NOT_FOUND'
        && $resNotFoundGet->getStatusCode() === 404
);

$incidentRepo->detail = $makeDetail('CLOSED');
$resetHarness();
$resClosed = $controller->addComment($makePostRequest('142', ['comment_text' => 'Comentario válido.']));
$assert(
    '3.2 Ticket CLOSED responde HTTP 403 CONVERSATION_SEALED (bitácora sellada)',
    $resClosed->getStatusCode() === 403
        && ($resClosed->getDecodedBody()['error']['code'] ?? '') === 'CONVERSATION_SEALED'
        && str_contains((string)($resClosed->getDecodedBody()['error']['message'] ?? ''), 'sellad')
);

$resClosedGet = $controller->getComments($makeGetRequest('142'));
$assert(
    '3.3 El GET de un expediente sellado conserva la consulta y marca is_sealed=true (RF-05.3)',
    $resClosedGet->getStatusCode() === 200
        && ($resClosedGet->getDecodedBody()['data']['incident']['is_sealed'] ?? null) === true
);

$incidentRepo->detail = $makeDetail('CANCELLED');
$resetHarness();
$resCancelled = $controller->addComment($makePostRequest('142', ['comment_text' => 'Comentario válido.']));
$assert(
    '3.4 Ticket CANCELLED responde HTTP 403 CONVERSATION_SEALED',
    $resCancelled->getStatusCode() === 403 && ($resCancelled->getDecodedBody()['error']['code'] ?? '') === 'CONVERSATION_SEALED'
);

$expiredResolvedAt = (new DateTimeImmutable('-49 hours'))->format('Y-m-d H:i:s');
$incidentRepo->detail = $makeDetail('RESOLVED', $expiredResolvedAt);
$resetHarness();
$resExpired = $controller->addComment($makePostRequest('142', ['comment_text' => 'Comentario válido.']));
$assert(
    '3.5 RESOLVED fuera de la garantía de 48 h responde HTTP 403 (ventana finalizada)',
    $resExpired->getStatusCode() === 403 && ($resExpired->getDecodedBody()['error']['code'] ?? '') === 'CONVERSATION_SEALED'
);

$freshResolvedAt = (new DateTimeImmutable('-1 hour'))->format('Y-m-d H:i:s');
$incidentRepo->detail = $makeDetail('RESOLVED', $freshResolvedAt);
$resetHarness();
$resWithinWindow = $controller->addComment($makePostRequest('142', ['comment_text' => 'Seguimiento dentro de la garantía.']));
$assert(
    '3.6 RESOLVED dentro de la garantía de 48 h responde HTTP 201 (RF-05.2)',
    $resWithinWindow->getStatusCode() === 201
);

$assert(
    '3.7 Los rechazos de la máquina de estados no persistieron nada más allá del caso válido',
    count($incidentRepo->addedComments) === 1
);

// =====================================================================
// CASO 4: Comentario público explícito — 201 Created + auditoría inmutable
// =====================================================================
echo "\n--- Caso 4: Comentario público (201 Created + audit_log) ---\n";

$incidentRepo->detail = $makeDetail('PENDING_PARTS');
$resetHarness();

$resCreated = $controller->addComment($makePostRequest('142', [
    'comment_text' => 'Revisado compresor en taller; pieza en camino desde almacén central.',
    'is_internal' => false,
]));

$createdBody = $resCreated->getDecodedBody();
$assert(
    '4.1 Responde HTTP 201 Created con la envolvente canónica y el hilo del expediente',
    $resCreated->getStatusCode() === 201
        && ($createdBody['success'] ?? null) === true
        && isset($createdBody['data']['incident'], $createdBody['data']['pagination'], $createdBody['data']['comments'])
);
$assert(
    '4.2 La respuesta incluye el mensaje explícito de la operación',
    isset($createdBody['message']) && str_contains((string)$createdBody['message'], 'Comentario publicado')
);
$assert(
    '4.3 El hilo devuelto incorpora el mensaje recién publicado (RF-03.4)',
    ($createdBody['data']['pagination']['total_comments'] ?? null) === 1
        && ($createdBody['data']['comments'][0]['comment_text'] ?? '') === 'Revisado compresor en taller; pieza en camino desde almacén central.'
        && ($createdBody['data']['comments'][0]['is_internal'] ?? null) === false
);
$assert(
    '4.4 El comentario persistido lleva la autoría del coordinador autenticado',
    count($incidentRepo->addedComments) === 1
        && $incidentRepo->addedComments[0]->getAuthorType() === 'COORDINATOR'
        && $incidentRepo->addedComments[0]->getUserId() === 2
        && $incidentRepo->addedComments[0]->getIncidentId() === 142
        && $incidentRepo->addedComments[0]->getTicketCode() === 'INC-2026-0142'
);
$assert(
    '4.5 El comentario público viaja con is_internal false',
    $incidentRepo->addedComments[0]->isInternal() === false
);

$assert(
    '4.6 Se emitió exactamente un evento INCIDENT_COMMENT_ADDED en audit_log (Art. III.3)',
    count($auditRepo->events) === 1
        && $auditRepo->events[0]->getAction() === 'INCIDENT_COMMENT_ADDED'
        && $auditRepo->events[0]->getEntityType() === AuditEvent::ENTITY_TICKET
        && $auditRepo->events[0]->getEntityId() === 142
);

$auditEvent = $auditRepo->events[0] ?? null;
$assert(
    '4.7 El evento inmutable registra al coordinador autenticado y el código del ticket',
    $auditEvent !== null
        && $auditEvent->getUserId() === 2
        && $auditEvent->getUserRole() === 'COORDINATOR'
        && $auditEvent->getUserName() === 'Coordinación'
        && ($auditEvent->getNewState()['ticket_code'] ?? null) === 'INC-2026-0142'
        && ($auditEvent->getNewState()['comment_id'] ?? null) === $incidentRepo->addedComments[0]->getId()
);

$assert(
    '4.8 El evento documenta la visibilidad pública del mensaje',
    $auditEvent !== null
        && ($auditEvent->getNewState()['is_internal'] ?? null) === false
        && ($auditEvent->getNewState()['has_photo'] ?? null) === false
);

// =====================================================================
// CASO 5: Nota interna de taller — selector preseleccionado por defecto
// =====================================================================
echo "\n--- Caso 5: Nota interna de taller (is_internal por defecto) ---\n";

$resetHarness();
$resInternal = $controller->addComment($makePostRequest('142', [
    'comment_text' => 'Nota confidencial: el técnico sospecha manipulación del monedero.',
]));

$assert(
    '5.1 Sin selector explícito el mensaje se persiste como nota interna (RF-03.3)',
    $resInternal->getStatusCode() === 201
        && count($incidentRepo->addedComments) === 1
        && $incidentRepo->addedComments[0]->isInternal() === true
);
$assert(
    '5.2 La respuesta interna expone is_internal=true en el hilo (RF-02.3)',
    ($resInternal->getDecodedBody()['data']['comments'][0]['is_internal'] ?? null) === true
        && str_contains((string)$resInternal->getDecodedBody()['message'], 'Nota interna')
);
$assert(
    '5.3 La auditoría marca la visibilidad interna y su bandera is_internal',
    count($auditRepo->events) === 1
        && ($auditRepo->events[0]->getNewState()['is_internal'] ?? null) === true
);

$resetHarness();
$resStringFlag = $controller->addComment($makePostRequest('142', [
    'comment_text' => 'Nota interna enviada como cadena booleana.',
    'is_internal' => 'true',
]));
$assert(
    '5.4 Un booleano en texto ("true") se acepta como nota interna',
    $resStringFlag->getStatusCode() === 201 && $incidentRepo->addedComments[0]->isInternal() === true
);

$resetHarness();
$resZeroFlag = $controller->addComment($makePostRequest('142', [
    'comment_text' => 'Mensaje público enviado como cadena "0".',
    'is_internal' => '0',
]));
$assert(
    '5.5 El valor de formulario "0" se interpreta como mensaje público',
    $resZeroFlag->getStatusCode() === 201 && $incidentRepo->addedComments[0]->isInternal() === false
);

// =====================================================================
// CASO 6: GET inspección total del diálogo (RF-01.2, RF-02.3)
// =====================================================================
echo "\n--- Caso 6: GET inspección total del diálogo ---\n";

$incidentRepo->detail = $makeDetail('IN_PROGRESS');
$incidentRepo->addedComments = $seedThread();
$auditRepo->events = [];

$resGet = $controller->getComments($makeGetRequest('142'));
$bodyGet = $resGet->getDecodedBody();
$getJson = (string)json_encode($bodyGet, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

$assert('6.1 GET responde HTTP 200 OK con la envolvente canónica', $resGet->getStatusCode() === 200 && ($bodyGet['success'] ?? false) === true);
$assert(
    '6.2 La cabecera contextual del expediente viaja completa (RF-01.4)',
    ($bodyGet['data']['incident']['id'] ?? null) === 142
        && ($bodyGet['data']['incident']['ticket_code'] ?? '') === 'INC-2026-0142'
        && ($bodyGet['data']['incident']['machine_code'] ?? '') === 'VEN-BCN-007'
        && ($bodyGet['data']['incident']['location_name'] ?? '') === 'Hospital del Mar'
        && ($bodyGet['data']['incident']['is_sealed'] ?? null) === false
);
$assert(
    '6.3 El recuento total incluye las notas internas (4 mensajes)',
    ($bodyGet['data']['pagination']['total_comments'] ?? null) === 4
        && ($bodyGet['data']['pagination']['loaded_count'] ?? null) === 4
        && ($bodyGet['data']['pagination']['oldest_id'] ?? null) === 12
        && ($bodyGet['data']['pagination']['latest_id'] ?? null) === 49
);
$assert(
    '6.4 El coordinador recibe la nota interna con el candado is_internal=true (RF-02.3)',
    ($bodyGet['data']['comments'][1]['is_internal'] ?? null) === true
        && ($bodyGet['data']['comments'][3]['is_internal'] ?? null) === true
);
$assert(
    '6.5 La identidad nominal real del equipo viaja sin enmascarar (RF-02.3)',
    str_contains($getJson, 'Carlos Pérez')
        && str_contains($getJson, 'Marta Ferrer')
        && !str_contains($getJson, 'Servicio Técnico Oficial')
);
$assert(
    '6.6 La marca is_own_message identifica los mensajes del propio coordinador',
    ($bodyGet['data']['comments'][3]['is_own_message'] ?? null) === true
        && ($bodyGet['data']['comments'][1]['is_own_message'] ?? null) === false
);

$resPage = $controller->getComments($makeGetRequest('142', ['limit' => '2']));
$bodyPage = $resPage->getDecodedBody();
$assert(
    '6.7 limit=2 devuelve el bloque más reciente con has_more_before=true (RF-01.3)',
    ($bodyPage['data']['pagination']['loaded_count'] ?? null) === 2
        && ($bodyPage['data']['pagination']['has_more_before'] ?? null) === true
        && ($bodyPage['data']['pagination']['oldest_id'] ?? null) === 48
        && ($bodyPage['data']['pagination']['total_comments'] ?? null) === 4
);
$resPagePrev = $controller->getComments($makeGetRequest('142', ['limit' => '2', 'before_id' => '48']));
$assert(
    '6.8 before_id=48 recupera el bloque anterior sin duplicados',
    count($resPagePrev->getDecodedBody()['data']['comments'] ?? []) === 2
        && ($resPagePrev->getDecodedBody()['data']['pagination']['oldest_id'] ?? null) === 12
        && ($resPagePrev->getDecodedBody()['data']['pagination']['has_more_before'] ?? null) === false
);

$resBadLimit = $controller->getComments($makeGetRequest('142', ['limit' => '0']));
$resBadBefore = $controller->getComments($makeGetRequest('142', ['before_id' => 'abc']));
$assert(
    '6.9 Cursor inválido responde HTTP 400 INVALID_LIMIT / INVALID_BEFORE_ID',
    $resBadLimit->getStatusCode() === 400
        && ($resBadLimit->getDecodedBody()['error']['code'] ?? '') === 'INVALID_LIMIT'
        && $resBadBefore->getStatusCode() === 400
        && ($resBadBefore->getDecodedBody()['error']['code'] ?? '') === 'INVALID_BEFORE_ID'
);

// =====================================================================
// CASO 7: Identificador de ruta (ID o código de ticket) y actor autenticado
// =====================================================================
echo "\n--- Caso 7: Identificador y actor autenticado ---\n";

$incidentRepo->addedComments = $seedThread();
$auditRepo->events = [];

$resetHarness();
$controller->addComment($makePostRequest('142', ['comment_text' => 'Comentario válido.']));
$assert(
    '7.1 Un ID numérico se delega como entero positivo al detalle enriquecido',
    ($incidentRepo->detailIdentifierCalls[0] ?? null) === 142
);

$resetHarness();
$controller->addComment($makePostRequest('INC-2026-0142', ['comment_text' => 'Comentario válido.']));
$assert(
    '7.2 Un código de ticket se delega tal cual al detalle enriquecido',
    ($incidentRepo->detailIdentifierCalls[0] ?? null) === 'INC-2026-0142'
);

$resetHarness();
$controller->addComment($makePostRequest('#inc-2026-0142', ['comment_text' => 'Comentario válido.']));
$assert(
    '7.3 El código con prefijo # y minúsculas se admite como identificador',
    ($incidentRepo->detailIdentifierCalls[0] ?? null) === '#inc-2026-0142'
);

$resetHarness();
$coordinatorUser = new User(
    id: 7,
    name: 'Marta Ferrer',
    email: 'marta.coordinacion@vendguard.internal',
    passwordHash: 'hash-de-prueba',
    role: UserRole::COORDINATOR
);
$controller->addComment($makePostRequest('142', ['comment_text' => 'Comentario válido.'], [
    'authenticated_user' => $coordinatorUser,
    'user_id' => 7,
    'user_role' => 'COORDINATOR',
]));
$assert(
    '7.4 Con usuario autenticado en la petición, la bitácora usa su identidad y rol reales',
    $incidentRepo->addedComments[0]->getUserId() === 7
        && $incidentRepo->addedComments[0]->getAuthorName() === 'Marta Ferrer'
        && ($auditRepo->events[0]->getUserRole() ?? null) === 'COORDINATOR'
);

// =====================================================================
// CASO 8: Evidencia fotográfica multipart (RF-04.1 / Art. V.5)
// =====================================================================
echo "\n--- Caso 8: Fotografía adjunta en la nota de taller ---\n";

$incidentRepo->addedComments = $seedThread();
$auditRepo->events = [];

$validJpgBytes = hex2bin('ffd8ffe000104a46494600010101004800480000ffdb004300080606070605080707070909080a0c140d0c0b0b0c1912130f141d1a1f1e1d1a1c1c20242e2720222c231c1c2837292c30313434341f27393d38323c2e333432ffc0000b080001000101011100ffda0008010100003f00d2cf20ffd9');
$tempJpg = tempnam(sys_get_temp_dir(), 'coord_comment_') . '.jpg';
file_put_contents($tempJpg, $validJpgBytes);

$resPhoto = $controller->addComment($makePostRequest(
    '142',
    ['comment_text' => 'Adjunto el estado del contador durante la supervisión.'],
    null,
    ['photo' => ['name' => 'contador.jpg', 'type' => 'image/jpeg', 'tmp_name' => $tempJpg, 'error' => UPLOAD_ERR_OK, 'size' => filesize($tempJpg)]]
));
$lastComment = $incidentRepo->addedComments[count($incidentRepo->addedComments) - 1] ?? null;

$assert('8.1 POST multipart con foto responde HTTP 201 Created', $resPhoto->getStatusCode() === 201);
$assert(
    '8.2 La evidencia se persiste con nombre hash inmutable (RF-04.3)',
    $lastComment !== null && preg_match('#^/uploads/[a-f0-9]{32}\.jpg$#', (string)$lastComment->getPhotoPath()) === 1
);
$assert(
    '8.3 El archivo físico existe en el directorio de subidas del gestor (Art. V.5)',
    $lastComment !== null && file_exists($uploadsDir . '/' . basename((string)$lastComment->getPhotoPath()))
);
$assert(
    '8.4 La auditoría documenta que la nota interna lleva evidencia fotográfica',
    ($auditRepo->events[0]->getNewState()['has_photo'] ?? null) === true
);
if (file_exists($tempJpg)) {
    unlink($tempJpg);
}

$incidentRepo->addedComments = $seedThread();
$auditRepo->events = [];
$fakeFile = tempnam(sys_get_temp_dir(), 'coord_fake_') . '.txt';
file_put_contents($fakeFile, 'No soy una imagen.');
$resFake = $controller->addComment($makePostRequest(
    '142',
    ['comment_text' => 'Texto de taller preservado ante fallo de subida', 'is_internal' => false],
    null,
    ['photo' => ['name' => 'falso.jpg', 'type' => 'image/jpeg', 'tmp_name' => $fakeFile, 'error' => UPLOAD_ERR_OK, 'size' => filesize($fakeFile)]]
));
$bodyFake = $resFake->getDecodedBody();
$assert(
    '8.5 Archivo no gráfico => HTTP 422 INVALID_FILE_TYPE sin persistir (Art. V.5)',
    $resFake->getStatusCode() === 422
        && ($bodyFake['error']['code'] ?? '') === 'INVALID_FILE_TYPE'
        && count($incidentRepo->addedComments) === 4
);
$assert(
    '8.6 RF-07.1: el texto y la clasificación del borrador se devuelven íntegros',
    ($bodyFake['error']['details']['form_data']['comment_text'] ?? '') === 'Texto de taller preservado ante fallo de subida'
        && ($bodyFake['error']['details']['form_data']['is_internal'] ?? null) === false
);
if (file_exists($fakeFile)) {
    unlink($fakeFile);
}

// Limpieza de los directorios temporales de subida creados por el arnés.
foreach (glob(sys_get_temp_dir() . '/vg_uploads_*') ?: [] as $tempUploadsDir) {
    foreach (glob((string)$tempUploadsDir . '/*') ?: [] as $tempUploadedFile) {
        @unlink((string)$tempUploadedFile);
    }
    @rmdir((string)$tempUploadsDir);
}

// =====================================================================
// RESUMEN DE EJECUCIÓN
// =====================================================================
echo "\n======================================================================\n";
echo " Total Aserciones: {$assertions} | Exitosas: " . ($assertions - $failures) . " | Fallidas: {$failures}\n";

if ($failures === 0) {
    echo " RESULTADO: 100% EN VERDE. CONDICIÓN T-COM-07 CUMPLIDA.\n";
    echo "======================================================================\n";
    exit(0);
}

echo " RESULTADO: FALLO EN {$failures} ASERCIONES.\n";
echo "======================================================================\n";
exit(1);
