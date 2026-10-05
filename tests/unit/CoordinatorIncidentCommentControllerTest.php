<?php

declare(strict_types=1);

/**
 * CoordinatorIncidentCommentControllerTest
 *
 * Suite de pruebas unitarias de la tarea T-IDM-06 (módulo 09, endpoint de la
 * bitácora del modal de detalle en triaje). Valida la condición "Hecho cuando":
 * - `CoordinatorController::addComment(Request $request)` valida texto obligatorio
 *   (>= 5 caracteres reales) y rechaza con 422 los cuerpos inválidos.
 * - Persiste el comentario con la bandera `is_internal` (comentario público vs.
 *   nota interna de taller) en la bitácora de la incidencia.
 * - Registra el evento inmutable INCIDENT_COMMENT_ADDED en `audit_log` con el
 *   coordinador autenticado y responde 201 Created con el comentario anexado.
 * - Respeta el RBAC (401/403) y la máquina de estados: bitácora sellada en
 *   CLOSED/CANCELLED y ventana de 48 horas en RESOLVED (RF-07.2, Art. III y V.6).
 *
 * Dogma Vanilla: PHP 8.2 puro, sin dependencias externas y sin base de datos; los
 * repositorios de incidencias y de auditoría se sustituyen por dobles en memoria y
 * el AuditLogger real se compone sobre el doble del registro append-only.
 */

require_once __DIR__ . '/../../src/autoload.php';

use VendGuard\Application\Service\AuditLogger;
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
use VendGuard\Presentation\Controller\CoordinatorController;
use VendGuard\Presentation\Http\Request;

/**
 * Doble en memoria del contrato de repositorio de incidencias: sirve la entidad
 * cargada y registra tanto las consultas como los comentarios persistidos.
 */
final class CoordinatorIncidentCommentStubIncidentRepo implements IncidentRepositoryInterface
{
    public ?Incident $incident = null;

    /** @var list<int> */
    public array $findByIdCalls = [];

    /** @var list<string> */
    public array $findByTicketCodeCalls = [];

    /** @var list<IncidentComment> */
    public array $addedComments = [];

    public function findById(int $id): ?Incident
    {
        $this->findByIdCalls[] = $id;

        return $this->incident;
    }

    public function findByTicketCode(string $ticketCode): ?Incident
    {
        $this->findByTicketCodeCalls[] = $ticketCode;

        return $this->incident;
    }

    public function addComment(IncidentComment $comment): IncidentComment
    {
        $this->addedComments[] = $comment;

        return new IncidentComment(
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
    }

    public function create(Incident $incident, ?int $userId = null, ?string $initialNote = null): Incident { throw new LogicException('Not used.'); }
    public function findActiveByMachineId(int $machineId): ?Incident { return null; }
    public function findActiveOrResolvedByMachineId(int $machineId): ?Incident { return null; }
    public function findAllByLocation(int $locationId, bool $activeOnly = false): array { return []; }
    public function findAll(array $filters = []): array { return []; }
    public function findAssignedToTechnician(int $technicianId, array $statuses = []): array { return []; }
    public function findEnrichedDetailById(int|string $identifier): ?array { return null; }
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
echo " VendGuard: Suite Unitaria - Endpoint de Comentarios de Incidencia (T-IDM-06)\n";
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
// ARNÉS: controlador sobre dobles en memoria y fábrica de incidencias
// =====================================================================
$incidentRepo = new CoordinatorIncidentCommentStubIncidentRepo();
$auditRepo = new CoordinatorIncidentCommentStubAuditRepo();

$controller = new CoordinatorController(
    incidentRepo: $incidentRepo,
    auditLogger: new AuditLogger($auditRepo)
);

$makeIncident = function (
    IncidentStatus $status,
    ?string $resolvedAt = null,
    ?string $updatedAt = null
): Incident {
    return new Incident(
        id: 142,
        ticketCode: 'INC-2026-0142',
        machineId: 10,
        locationId: 1,
        category: IncidentCategory::TEMPERATURE_COLD,
        description: 'El compresor no arranca y los sándwiches superan los 9°C.',
        urgency: UrgencyLevel::CRITICAL,
        status: $status,
        resolvedAt: $resolvedAt,
        updatedAt: $updatedAt,
        createdAt: '2026-10-01 08:15:00'
    );
};

$coordinatorAttributes = ['user_id' => 2, 'user_role' => 'COORDINATOR'];

/**
 * Construye la petición POST tal y como la entrega el enrutador: ruta con `{id}`
 * resuelto, cuerpo deserializado y atributos del actor autenticado.
 *
 * @param array<string, mixed> $body
 * @param array<string, mixed> $attributes
 */
$makeRequest = function (string $routeIdentifier, array $body, array $attributes = null): Request {
    $attributes ??= ['user_id' => 2, 'user_role' => 'COORDINATOR'];

    $request = new Request(
        'POST',
        '/api/coordinator/incidents/' . rawurlencode($routeIdentifier) . '/comments',
        [],
        $body
    );
    $request->setRouteParams(['id' => $routeIdentifier]);

    foreach ($attributes as $key => $value) {
        $request->setAttribute($key, $value);
    }

    return $request;
};

$resetHarness = function () use ($incidentRepo, $auditRepo): void {
    $incidentRepo->findByIdCalls = [];
    $incidentRepo->findByTicketCodeCalls = [];
    $incidentRepo->addedComments = [];
    $auditRepo->events = [];
};

$activeIncident = $makeIncident(IncidentStatus::PENDING_PARTS, null, '2026-10-05 09:00:00');

// =====================================================================
// CASO 1: Control de acceso RBAC (401 / 403) sin persistencia ni auditoría
// =====================================================================
echo "--- Caso 1: Control de acceso RBAC (401 / 403) ---\n";

$incidentRepo->incident = $activeIncident;
$resetHarness();

$resNoIdentity = $controller->addComment($makeRequest('142', ['comment_text' => 'Comentario válido.'], []));
$assert(
    '1.1 Sin identidad autenticada responde HTTP 401 Unauthorized',
    $resNoIdentity->getStatusCode() === 401 && ($resNoIdentity->getDecodedBody()['error']['code'] ?? '') === 'UNAUTHORIZED'
);

$resTechnician = $controller->addComment($makeRequest('142', ['comment_text' => 'Comentario válido.'], ['user_id' => 4, 'user_role' => 'TECHNICIAN']));
$assert(
    '1.2 Rol TECHNICIAN responde HTTP 403 Forbidden',
    $resTechnician->getStatusCode() === 403 && ($resTechnician->getDecodedBody()['error']['code'] ?? '') === 'FORBIDDEN'
);

$assert(
    '1.3 Ninguna petición no autorizada persistió comentario ni evento de auditoría',
    $incidentRepo->addedComments === [] && $auditRepo->events === []
);

// =====================================================================
// CASO 2: Validación temprana del cuerpo (texto >= 5 caracteres, is_internal)
// =====================================================================
echo "\n--- Caso 2: Validación del cuerpo ---\n";

$resetHarness();

$resMissingText = $controller->addComment($makeRequest('142', []));
$assert(
    '2.1 Sin comment_text responde HTTP 422 MISSING_COMMENT_TEXT',
    $resMissingText->getStatusCode() === 422 && ($resMissingText->getDecodedBody()['error']['code'] ?? '') === 'MISSING_COMMENT_TEXT'
);

$resBlankText = $controller->addComment($makeRequest('142', ['comment_text' => '    ']));
$assert(
    '2.2 Texto en blanco responde HTTP 422 MISSING_COMMENT_TEXT',
    $resBlankText->getStatusCode() === 422 && ($resBlankText->getDecodedBody()['error']['code'] ?? '') === 'MISSING_COMMENT_TEXT'
);

$resShortText = $controller->addComment($makeRequest('142', ['comment_text' => 'Ok.']));
$assert(
    '2.3 Texto de 3 caracteres responde HTTP 422 COMMENT_TOO_SHORT',
    $resShortText->getStatusCode() === 422 && ($resShortText->getDecodedBody()['error']['code'] ?? '') === 'COMMENT_TOO_SHORT'
);

$resInvalidFlag = $controller->addComment($makeRequest('142', ['comment_text' => 'Nota de taller válida.', 'is_internal' => 'quizá']));
$assert(
    '2.4 is_internal no booleano responde HTTP 422 INVALID_IS_INTERNAL',
    $resInvalidFlag->getStatusCode() === 422 && ($resInvalidFlag->getDecodedBody()['error']['code'] ?? '') === 'INVALID_IS_INTERNAL'
);

$resInvalidId = $controller->addComment($makeRequest('INC 2026/01', ['comment_text' => 'Nota de taller válida.']));
$assert(
    '2.5 Identificador de ruta inválido responde HTTP 400 INVALID_INCIDENT_IDENTIFIER',
    $resInvalidId->getStatusCode() === 400 && ($resInvalidId->getDecodedBody()['error']['code'] ?? '') === 'INVALID_INCIDENT_IDENTIFIER'
);

$assert(
    '2.6 Ningún cuerpo inválido persistió comentario ni evento de auditoría',
    $incidentRepo->addedComments === [] && $auditRepo->events === []
);

// =====================================================================
// CASO 3: Existencia y máquina de estados (sellado y ventana de 48 h)
// =====================================================================
echo "\n--- Caso 3: Existencia y ventana de comentarios ---\n";

$incidentRepo->incident = null;
$resetHarness();
$resNotFound = $controller->addComment($makeRequest('INC-2099-0000', ['comment_text' => 'Comentario válido.']));
$assert(
    '3.1 Ticket inexistente responde HTTP 404 INCIDENT_NOT_FOUND',
    $resNotFound->getStatusCode() === 404 && ($resNotFound->getDecodedBody()['error']['code'] ?? '') === 'INCIDENT_NOT_FOUND'
);

$incidentRepo->incident = $makeIncident(IncidentStatus::CLOSED, null, '2026-10-05 10:00:00');
$resetHarness();
$resClosed = $controller->addComment($makeRequest('142', ['comment_text' => 'Comentario válido.']));
$assert(
    '3.2 Ticket CLOSED responde HTTP 422 COMMENT_WINDOW_CLOSED (bitácora sellada)',
    $resClosed->getStatusCode() === 422
        && ($resClosed->getDecodedBody()['error']['code'] ?? '') === 'COMMENT_WINDOW_CLOSED'
        && str_contains((string)($resClosed->getDecodedBody()['error']['message'] ?? ''), 'sellada')
);

$incidentRepo->incident = $makeIncident(IncidentStatus::CANCELLED, null, '2026-10-05 10:00:00');
$resetHarness();
$resCancelled = $controller->addComment($makeRequest('142', ['comment_text' => 'Comentario válido.']));
$assert(
    '3.3 Ticket CANCELLED responde HTTP 422 COMMENT_WINDOW_CLOSED',
    $resCancelled->getStatusCode() === 422 && ($resCancelled->getDecodedBody()['error']['code'] ?? '') === 'COMMENT_WINDOW_CLOSED'
);

$expiredResolvedAt = (new DateTimeImmutable('-49 hours'))->format('Y-m-d H:i:s');
$incidentRepo->incident = $makeIncident(IncidentStatus::RESOLVED, $expiredResolvedAt, '2026-10-05 09:00:00');
$resetHarness();
$resExpired = $controller->addComment($makeRequest('142', ['comment_text' => 'Comentario válido.']));
$assert(
    '3.4 RESOLVED fuera de la garantía de 48 h responde HTTP 422 (ventana finalizada)',
    $resExpired->getStatusCode() === 422
        && ($resExpired->getDecodedBody()['error']['code'] ?? '') === 'COMMENT_WINDOW_CLOSED'
        && str_contains((string)($resExpired->getDecodedBody()['error']['message'] ?? ''), '48 horas')
);

$freshResolvedAt = (new DateTimeImmutable('-1 hour'))->format('Y-m-d H:i:s');
$incidentRepo->incident = $makeIncident(IncidentStatus::RESOLVED, $freshResolvedAt, '2026-10-05 09:00:00');
$resetHarness();
$resWithinWindow = $controller->addComment($makeRequest('142', ['comment_text' => 'Comentario válido.']));
$assert(
    '3.5 RESOLVED dentro de la garantía de 48 h responde HTTP 201',
    $resWithinWindow->getStatusCode() === 201
);

$assert(
    '3.6 Los rechazos de la máquina de estados no persistieron nada',
    count($incidentRepo->addedComments) === 1
);

// =====================================================================
// CASO 4: Camino feliz público — 201 Created + auditoría inmutable
// =====================================================================
echo "\n--- Caso 4: Comentario público (201 Created + audit_log) ---\n";

$incidentRepo->incident = $activeIncident;
$resetHarness();

$resCreated = $controller->addComment($makeRequest('142', [
    'comment_text' => 'Revisado compresor en taller; pieza en camino desde almacén central.',
    'is_internal' => false,
]));

$createdBody = $resCreated->getDecodedBody();
$assert(
    '4.1 Responde HTTP 201 Created con la envolvente canónica',
    $resCreated->getStatusCode() === 201
        && ($createdBody['success'] ?? null) === true
        && isset($createdBody['data']) && is_array($createdBody['data'])
);

$assert(
    '4.2 La respuesta incluye el mensaje explícito de la operación',
    isset($createdBody['message']) && str_contains((string)$createdBody['message'], 'Comentario añadido')
);

$assert(
    '4.3 El comentario persistido lleva la autoría del coordinador autenticado',
    count($incidentRepo->addedComments) === 1
        && $incidentRepo->addedComments[0]->getAuthorType() === 'COORDINATOR'
        && $incidentRepo->addedComments[0]->getUserId() === 2
        && $incidentRepo->addedComments[0]->getAuthorName() === 'Coordinación'
        && $incidentRepo->addedComments[0]->getIncidentId() === 142
        && $incidentRepo->addedComments[0]->getTicketCode() === 'INC-2026-0142'
);

$assert(
    '4.4 El comentario público viaja con is_internal false y su identificador persistido',
    $incidentRepo->addedComments[0]->isInternal() === false
        && ($createdBody['data']['id'] ?? null) === 901
        && ($createdBody['data']['is_internal'] ?? null) === false
        && ($createdBody['data']['comment_text'] ?? null) === 'Revisado compresor en taller; pieza en camino desde almacén central.'
        && ($createdBody['data']['created_at'] ?? null) === '2026-10-05 12:00:00'
);

$assert(
    '4.5 Se emitió exactamente un evento INCIDENT_COMMENT_ADDED en audit_log',
    count($auditRepo->events) === 1
        && $auditRepo->events[0]->getAction() === 'INCIDENT_COMMENT_ADDED'
        && $auditRepo->events[0]->getEntityType() === AuditEvent::ENTITY_TICKET
        && $auditRepo->events[0]->getEntityId() === 142
);

$auditEvent = $auditRepo->events[0] ?? null;
$assert(
    '4.6 El evento inmutable registra al coordinador autenticado (Art. III.3)',
    $auditEvent !== null
        && $auditEvent->getUserId() === 2
        && $auditEvent->getUserRole() === 'COORDINATOR'
        && $auditEvent->getUserName() === 'Coordinación'
);

$assert(
    '4.7 El evento documenta el comentario y su visibilidad pública',
    $auditEvent !== null
        && ($auditEvent->getNewState()['comment_id'] ?? null) === 901
        && ($auditEvent->getNewState()['is_internal'] ?? null) === false
        && ($auditEvent->getNewState()['author_type'] ?? null) === 'COORDINATOR'
        && ($auditEvent->getMetadata()['visibility'] ?? null) === 'PUBLIC'
        && ($auditEvent->getMetadata()['ticket_code'] ?? null) === 'INC-2026-0142'
        && ($auditEvent->getPreviousState()['status'] ?? null) === 'PENDING_PARTS'
);

// =====================================================================
// CASO 5: Nota interna de taller (is_internal true)
// =====================================================================
echo "\n--- Caso 5: Nota interna de taller ---\n";

$resetHarness();
$resInternal = $controller->addComment($makeRequest('142', [
    'comment_text' => 'Nota confidencial: el técnico sospecha manipulación del monedero.',
    'is_internal' => true,
]));

$assert(
    '5.1 La nota interna responde HTTP 201 y se persiste con is_internal true',
    $resInternal->getStatusCode() === 201
        && ($resInternal->getDecodedBody()['data']['is_internal'] ?? null) === true
        && $incidentRepo->addedComments[0]->isInternal() === true
);

$assert(
    '5.2 La auditoría marca la visibilidad INTERNAL y su bandera is_internal',
    count($auditRepo->events) === 1
        && ($auditRepo->events[0]->getMetadata()['visibility'] ?? null) === 'INTERNAL'
        && ($auditRepo->events[0]->getNewState()['is_internal'] ?? null) === true
);

$resetHarness();
$resStringFlag = $controller->addComment($makeRequest('142', [
    'comment_text' => 'Nota interna enviada como cadena booleana.',
    'is_internal' => 'true',
]));
$assert(
    '5.3 Un booleano en texto ("true") se acepta como nota interna',
    $resStringFlag->getStatusCode() === 201 && $incidentRepo->addedComments[0]->isInternal() === true
);

// =====================================================================
// CASO 6: Identificador de ruta (ID o código de ticket) y actor autenticado
// =====================================================================
echo "\n--- Caso 6: Identificador y actor autenticado ---\n";

$resetHarness();
$controller->addComment($makeRequest('142', ['comment_text' => 'Comentario válido.']));
$assert(
    '6.1 Un ID numérico se delega como entero positivo a findById',
    $incidentRepo->findByIdCalls === [142] && $incidentRepo->findByTicketCodeCalls === []
);

$resetHarness();
$controller->addComment($makeRequest('INC-2026-0142', ['comment_text' => 'Comentario válido.']));
$assert(
    '6.2 Un código de ticket se delega normalizado a findByTicketCode',
    $incidentRepo->findByTicketCodeCalls === ['INC-2026-0142'] && $incidentRepo->findByIdCalls === []
);

$resetHarness();
$controller->addComment($makeRequest('#inc-2026-0142', ['comment_text' => 'Comentario válido.']));
$assert(
    '6.3 El código con prefijo # y minúsculas se normaliza antes de consultar',
    $incidentRepo->findByTicketCodeCalls === ['INC-2026-0142']
);

$resetHarness();
$coordinatorUser = new User(
    id: 7,
    name: 'Marta Ferrer',
    email: 'marta.coordinacion@vendguard.internal',
    passwordHash: 'hash-de-prueba',
    role: UserRole::COORDINATOR
);
$controller->addComment($makeRequest('142', ['comment_text' => 'Comentario válido.'], [
    'authenticated_user' => $coordinatorUser,
    'user_id' => 7,
    'user_role' => 'COORDINATOR',
]));
$assert(
    '6.4 Con usuario autenticado en la petición, la bitácora usa su nombre y rol reales',
    $incidentRepo->addedComments[0]->getUserId() === 7
        && $incidentRepo->addedComments[0]->getAuthorName() === 'Marta Ferrer'
        && ($auditRepo->events[0]->getUserRole() ?? null) === 'COORDINATOR'
);

// =====================================================================
// RESUMEN DE EJECUCIÓN
// =====================================================================
echo "\n======================================================================\n";
echo " Total Aserciones: {$assertions} | Exitosas: " . ($assertions - $failures) . " | Fallidas: {$failures}\n";

if ($failures === 0) {
    echo " RESULTADO: 100% EN VERDE. CONDICIÓN T-IDM-06 CUMPLIDA.\n";
    echo "======================================================================\n";
    exit(0);
}

echo " RESULTADO: FALLO EN {$failures} ASERCIONES.\n";
echo "======================================================================\n";
exit(1);
