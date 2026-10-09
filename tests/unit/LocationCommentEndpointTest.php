<?php

declare(strict_types=1);

/**
 * LocationCommentEndpointTest
 *
 * Suite de pruebas unitarias de la tarea T-COM-05 (módulo 10, endpoints de Sede
 * para el hilo de conversación). Valida la condición "Hecho cuando" sobre
 * `LocationPortalController::getComments()` y `LocationPortalController::addComment()`
 * orquestados mediante `IncidentCommentService`:
 * - GET responde 200 OK con el `IncidentCommentThreadDto` completo: cabecera
 *   contextual del expediente, cursor de paginación e hilo proyectado.
 * - Segregación estricta en servidor (Art. V.4 / RNF-01): el payload de Sede
 *   jamás contiene notas internas ni el campo `is_internal`, y el técnico
 *   aparece enmascarado como "Servicio Técnico Oficial (Operador #XX)".
 * - POST acepta multipart/form-data con foto opcional, responde 201 Created
 *   con el hilo actualizado y siempre publica como público: ningún valor del
 *   cuerpo puede forzar `is_internal = true` (RF-03.2).
 * - Máquina de estados (RF-05.3): expediente CLOSED se rechaza con
 *   HTTP 403 Forbidden (CONVERSATION_SEALED), sin persistir ni auditar nada.
 * - Límites de 5 a 1.000 caracteres descriptivos (RF-03.1) y validación
 *   binaria de la fotografía delegada en LocalFileUploader (Art. V.5).
 * - Aislamiento de sedes (403 SITE_MISMATCH) y expediente inexistente (404).
 *
 * Dogma Vanilla: PHP 8.2 puro, sin dependencias externas y sin base de datos;
 * el repositorio de incidencias, el gestor de subidas y el registro de
 * auditoría se sustituyen por dobles en memoria.
 */

require_once __DIR__ . '/../../src/autoload.php';

use VendGuard\Application\DTO\IncidentCommentThreadDto;
use VendGuard\Application\Service\AuditLogger;
use VendGuard\Application\Service\IncidentCommentService;
use VendGuard\Core\Domain\Model\AuditEvent;
use VendGuard\Core\Domain\Model\Incident;
use VendGuard\Core\Domain\Model\IncidentComment;
use VendGuard\Core\Domain\Model\Location;
use VendGuard\Core\Domain\Repository\AuditLogRepositoryInterface;
use VendGuard\Core\Domain\Repository\IncidentRepositoryInterface;
use VendGuard\Core\Domain\ValueObject\IncidentCategory;
use VendGuard\Core\Domain\ValueObject\IncidentStatus;
use VendGuard\Core\Domain\ValueObject\UrgencyLevel;
use VendGuard\Infrastructure\Storage\LocalFileUploader;
use VendGuard\Presentation\Controller\LocationPortalController;
use VendGuard\Presentation\Http\Request;

/**
 * Doble en memoria del repositorio de incidencias: alimenta el detalle
 * enriquecido del expediente y captura los comentarios persistidos.
 */
final class LocationCommentStubIncidentRepo implements IncidentRepositoryInterface
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

        return array_slice($rows, -$limit, $limit);
    }

    public function countComments(int $incidentId, bool $includeInternal): int
    {
        return count(array_filter(
            $this->storedComments,
            fn(IncidentComment $c) => $c->getIncidentId() === $incidentId
                && ($includeInternal || !$c->isInternal())
        ));
    }

    public function addComment(IncidentComment $comment): IncidentComment
    {
        $reflection = new ReflectionProperty($comment, 'id');
        $reflection->setValue($comment, 900 + count($this->storedComments));
        $this->storedComments[] = $comment;

        return $comment;
    }

    public function recordAudit(array $payload): void
    {
        $this->auditTrail[] = $payload;
    }

    public function findById(int $id): ?Incident
    {
        if ($this->detail === null || (int)($this->detail['incident']['id'] ?? 0) !== $id) {
            return null;
        }

        return $this->buildIncidentFromDetail();
    }

    public function findByTicketCode(string $ticketCode): ?Incident
    {
        if ($this->detail === null) {
            return null;
        }
        $expected = strtoupper(ltrim($ticketCode, '#'));
        if ((string)($this->detail['incident']['ticket_code'] ?? '') !== $expected) {
            return null;
        }

        return $this->buildIncidentFromDetail();
    }

    /**
     * Reconstruye la entidad de dominio desde el detalle enriquecido para
     * las vías de resolución del controlador (ID o código de ticket).
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
            description: 'Expediente de prueba del hilo de comentarios.',
            urgency: UrgencyLevel::CRITICAL,
            status: IncidentStatus::tryFrom((string)($incidentRow['status'] ?? '')) ?? IncidentStatus::REGISTERED,
            resolvedAt: $incidentRow['resolved_at'] ?? null
        );
    }
    public function create(Incident $incident, ?int $userId = null, ?string $initialNote = null): Incident { throw new LogicException('Not used.'); }
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
 * Doble del repositorio de auditoría: captura los eventos INCIDENT_COMMENT_ADDED.
 */
final class LocationCommentStubAuditRepo implements AuditLogRepositoryInterface
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

echo "======================================================================\n";
echo " VendGuard: Suite Unitaria - Endpoints de Sede del Hilo de Comentarios (Módulo 10, T-COM-05)\n";
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

// ─────────────────────────────────────────────────────────────────────────────
// Arnés: sede autenticada, controlador y fábricas de datos
// ─────────────────────────────────────────────────────────────────────────────

$authLocation = new Location(
    id: 3,
    siteCode: 'SEDE-BCN-01',
    name: 'Hospital del Mar - Edificio Central',
    address: 'Passeig Marítim 25, Barcelona',
    contactName: 'Laura Sanitaria'
);

$makeDetail = function (string $status, ?string $resolvedAt = null): array {
    return [
        'incident' => [
            'id' => 142,
            'ticket_code' => 'TICK-2026-00142',
            'status' => $status,
            'resolved_at' => $resolvedAt,
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
        ticketCode: 'TICK-2026-00142'
    );
};

$seedThread = function () use ($makeComment): array {
    return [
        $makeComment(12, 'REPORTER', 'Conserjería Principal (Juan Gómez)', 'La máquina está en la 3ª planta junto a los ascensores B.', false, null, '2026-10-06 10:15:30'),
        $makeComment(35, 'TECHNICIAN', 'Carlos Pérez', 'Ojo: fusible de fuente recalentado, posible corto en electroválvula.', true, 55, '2026-10-06 10:45:00'),
        $makeComment(48, 'TECHNICIAN', 'Carlos Pérez', 'Llegando al edificio. Accedo por conserjería en 10 minutos.', false, 55, '2026-10-06 11:30:12'),
        $makeComment(49, 'COORDINATOR', 'Sara Coordinadora', 'Priorizad esta avería en la ruta de esta tarde.', true, 9, '2026-10-06 11:40:00'),
        $makeComment(50, 'COORDINATOR', 'Sara Coordinadora', 'La avería quedó registrada y será atendida hoy mismo.', false, 9, '2026-10-06 11:50:00'),
    ];
};

$buildHarness = function (?array $detail, array $comments = []): array {
    $repo = new LocationCommentStubIncidentRepo();
    $repo->detail = $detail;
    $repo->storedComments = $comments;

    $auditRepo = new LocationCommentStubAuditRepo();
    // LocalFileUploader real: certifica la validación binaria auténtica con
    // finfo y el formato hash de los nombres en disco (Art. V.5 / RF-04.3).
    $uploadsDir = sys_get_temp_dir() . '/vg_uploads_' . uniqid('', true);
    $uploader = new LocalFileUploader($uploadsDir);
    $controller = new LocationPortalController(
        machineRepo: null,
        locationRepo: null,
        incidentRepo: $repo,
        fileUploader: $uploader,
        refundService: null,
        ibanValidator: null,
        commentService: new IncidentCommentService($repo, new AuditLogger($auditRepo), $uploader)
    );

    return [$controller, $repo, $auditRepo, $uploader, $uploadsDir];
};

$makeGetRequest = function (?string $routeIdentifier, array $query = [], ?Location $location = null): Request {
    $request = new Request(
        'GET',
        '/api/location/incidents/' . ($routeIdentifier ?? '142') . '/comments',
        $query
    );
    if ($routeIdentifier !== null) {
        $request->setRouteParams(['id' => $routeIdentifier]);
    }
    $request->setAttribute('authenticated_location', $location ?? $GLOBALS['authLocation']);

    return $request;
};

$makePostRequest = function (array $body = [], array $files = [], ?string $routeIdentifier = '142', ?Location $location = null): Request {
    $request = new Request(
        'POST',
        '/api/location/incidents/' . ($routeIdentifier ?? '142') . '/comments',
        [],
        $body,
        [],
        $files
    );
    if ($routeIdentifier !== null) {
        $request->setRouteParams(['id' => $routeIdentifier]);
    }
    $request->setAttribute('authenticated_location', $location ?? $GLOBALS['authLocation']);

    return $request;
};

$otherLocation = new Location(
    id: 4,
    siteCode: 'SEDE-BCN-02',
    name: 'Hospital del Mar - Edificio Norte',
    address: 'Passeig Marítim 27, Barcelona'
);

// =====================================================================
// CASO 1: GET 200 OK con el DTO del hilo segregado para la Sede (RF-01.2, RF-02.1)
// =====================================================================
echo "--- Caso 1: GET devuelve el IncidentCommentThreadDto segregado (Art. V.4) ---\n";

[$controller, $repo] = $buildHarness($makeDetail('IN_PROGRESS'), $seedThread());
$resGet = $controller->getComments($makeGetRequest('142'));
$bodyGet = $resGet->getDecodedBody();

$assert('1.1 GET responde HTTP 200 OK con la envolvente canónica', $resGet->getStatusCode() === 200 && ($bodyGet['success'] ?? false) === true);
$assert('1.2 La cabecera contextual del expediente viaja completa (RF-01.4)',
    ($bodyGet['data']['incident']['id'] ?? null) === 142
    && ($bodyGet['data']['incident']['ticket_code'] ?? '') === 'TICK-2026-00142'
    && ($bodyGet['data']['incident']['machine_code'] ?? '') === 'VEN-BCN-001'
    && ($bodyGet['data']['incident']['location_name'] ?? '') === 'Hospital del Mar'
    && ($bodyGet['data']['incident']['is_sealed'] ?? null) === false
);
$assert('1.3 El cursor de paginación informa has_more_before y los extremos del bloque (RF-01.3)',
    ($bodyGet['data']['pagination']['total_comments'] ?? null) === 3
    && ($bodyGet['data']['pagination']['loaded_count'] ?? null) === 3
    && ($bodyGet['data']['pagination']['has_more_before'] ?? null) === false
    && ($bodyGet['data']['pagination']['oldest_id'] ?? null) === 12
    && ($bodyGet['data']['pagination']['latest_id'] ?? null) === 50
);

$getJson = (string)json_encode($bodyGet, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$assert('1.4 Cero fugas: el payload no contiene is_internal en ninguna forma (RNF-01)', !str_contains($getJson, 'is_internal'));
$assert('1.5 Cero fugas: la identidad real del técnico jamás sale del servidor (Art. V.4)', !str_contains($getJson, 'Carlos Pérez'));
$assert('1.6 El técnico aparece enmascarado como Servicio Técnico Oficial (Operador #55) (RF-02.2)',
    str_contains($getJson, 'Servicio Técnico Oficial (Operador #55)')
);
$assert('1.7 La coordinación aparece enmascarada como Coordinación Central de Operaciones (RF-02.2)',
    str_contains($getJson, 'Coordinación Central de Operaciones')
);
$assert('1.8 La marca is_own_message identifica los mensajes de la propia Sede (REPORTER)',
    ($bodyGet['data']['comments'][0]['is_own_message'] ?? null) === true
    && ($bodyGet['data']['comments'][1]['is_own_message'] ?? null) === false
);
$assert('1.9 El total transmitido a la Sede es el recuento público (3), no el real (5)',
    ($bodyGet['data']['pagination']['total_comments'] ?? null) === 3 && count($bodyGet['data']['comments']) === 3
);

// =====================================================================
// CASO 2: POST 201 Created publica público forzoso y emite auditoría (RF-03.2, RF-06.3)
// =====================================================================
echo "\n--- Caso 2: POST publica siempre comentario público con auditoría ---\n";

[$controller, $repo, $auditRepo] = $buildHarness($makeDetail('IN_PROGRESS'), $seedThread());
$resPost = $controller->addComment($makePostRequest([
    'comment_text' => 'El ascensor de servicio estará cortado mañana por la mañana.',
    'is_internal' => true,
]));
$bodyPost = $resPost->getDecodedBody();

$assert('2.1 POST responde HTTP 201 Created con la envolvente canónica', $resPost->getStatusCode() === 201 && ($bodyPost['success'] ?? false) === true);
$assert('2.2 La respuesta devuelve el hilo actualizado del expediente (RF-03.4)',
    isset($bodyPost['data']['incident'], $bodyPost['data']['pagination'], $bodyPost['data']['comments'])
    && ($bodyPost['data']['pagination']['loaded_count'] ?? null) === 4
);
$persisted = end($repo->storedComments);
$assert('2.3 El mensaje se persiste con autoría corporativa de la Sede (RF-02.1)',
    $persisted !== false
    && $persisted->getAuthorType() === 'REPORTER'
    && $persisted->getUserId() === null
    && str_starts_with($persisted->getAuthorName(), 'Responsable de Sede · Hospital del Mar')
);
$assert('2.4 El campo is_internal=true del cuerpo se ignora: la Sede publica SIEMPRE público (RF-03.2)',
    $persisted !== false && $persisted->isInternal() === false
);
$assert('2.5 El comentario viaja vinculado al expediente y su ticket',
    $persisted !== false && $persisted->getIncidentId() === 142 && $persisted->getTicketCode() === 'TICK-2026-00142'
);
$assert('2.6 Se emitió exactamente un evento INCIDENT_COMMENT_ADDED en auditoría (RF-06.3)',
    count($auditRepo->events) === 1 && ($auditRepo->events[0]->getAction() ?? '') === 'INCIDENT_COMMENT_ADDED'
);
$auditJson = (string)json_encode($auditRepo->events[0] ?? null, JSON_UNESCAPED_UNICODE);
$assert('2.7 El evento documenta el expediente y la clasificación pública del mensaje',
    str_contains($auditJson, 'TICK-2026-00142') && str_contains($auditJson, 'is_internal')
);

// =====================================================================
// CASO 3: Multipart/form-data con fotografía opcional (RF-04.1 / Art. V.5)
// =====================================================================
echo "\n--- Caso 3: POST con fotografía adjunta valida en servidor ---\n";

[$controller, $repo, , , $uploadsDir] = $buildHarness($makeDetail('IN_PROGRESS'), $seedThread());
$validJpgBytes = hex2bin('ffd8ffe000104a46494600010101004800480000ffdb004300080606070605080707070909080a0c140d0c0b0b0c1912130f141d1a1f1e1d1a1c1c20242e2720222c231c1c2837292c30313434341f27393d38323c2e333432ffc0000b080001000101011100ffda0008010100003f00d2cf20ffd9');
$tempJpg = tempnam(sys_get_temp_dir(), 'loc_comment_') . '.jpg';
file_put_contents($tempJpg, $validJpgBytes);

$resPhoto = $controller->addComment($makePostRequest(
    ['comment_text' => 'Adjunto fotografía de la etiqueta de la máquina.'],
    ['photo' => ['name' => 'pantalla_error_e04.jpg', 'type' => 'image/jpeg', 'tmp_name' => $tempJpg, 'error' => UPLOAD_ERR_OK, 'size' => filesize($tempJpg)]]
));
$persistedPhoto = end($repo->storedComments);

$assert('3.1 POST multipart con foto responde HTTP 201 Created', $resPhoto->getStatusCode() === 201);
$assert('3.2 El mensaje persiste la evidencia con nombre hash inmutable (RF-04.3)',
    $persistedPhoto !== false
    && preg_match('#^/uploads/[a-f0-9]{32}\\.jpg$#', (string)$persistedPhoto->getPhotoPath()) === 1
);
$assert('3.3 El archivo físico existe en el directorio de subidas del gestor (Art. V.5)',
    $persistedPhoto !== false
    && file_exists($uploadsDir . '/' . basename((string)$persistedPhoto->getPhotoPath()))
);
if (file_exists($tempJpg)) {
    unlink($tempJpg);
}

[$controller, $repo] = $buildHarness($makeDetail('IN_PROGRESS'), $seedThread());
$fakeFile = tempnam(sys_get_temp_dir(), 'loc_fake_') . '.txt';
file_put_contents($fakeFile, 'No soy una imagen.');
$resFake = $controller->addComment($makePostRequest(
    ['comment_text' => 'Texto preservado ante fallo de subida'],
    ['photo' => ['name' => 'falso.jpg', 'type' => 'image/jpeg', 'tmp_name' => $fakeFile, 'error' => UPLOAD_ERR_OK, 'size' => filesize($fakeFile)]]
));
$bodyFake = $resFake->getDecodedBody();
$assert('3.4 Archivo no gráfico => HTTP 422 INVALID_FILE_TYPE sin persistir (Art. V.5)',
    $resFake->getStatusCode() === 422
    && ($bodyFake['error']['code'] ?? '') === 'INVALID_FILE_TYPE'
    && count($repo->storedComments) === 5
);
$assert('3.5 RF-07.1: los datos de texto se devuelven íntegros para el reintento',
    ($bodyFake['error']['details']['form_data']['comment_text'] ?? '') === 'Texto preservado ante fallo de subida'
);
if (file_exists($fakeFile)) {
    unlink($fakeFile);
}

// =====================================================================
// CASO 4: Máquina de estados — sellado con 403 Forbidden (RF-05.3)
// =====================================================================
echo "\n--- Caso 4: Expediente CLOSED se rechaza con HTTP 403 (CONVERSATION_SEALED) ---\n";

[$controller, $repo, $auditRepo] = $buildHarness($makeDetail('CLOSED'), $seedThread());
$resSealed = $controller->addComment($makePostRequest(['comment_text' => 'Intento sobre expediente archivado.']));
$bodySealed = $resSealed->getDecodedBody();

$assert('4.1 POST sobre CLOSED responde HTTP 403 Forbidden (no 422)',
    $resSealed->getStatusCode() === 403 && ($bodySealed['error']['code'] ?? '') === 'CONVERSATION_SEALED'
);
$assert('4.2 El rechazo no persiste comentario ni emite evento de auditoría',
    count($repo->storedComments) === 5 && $auditRepo->events === []
);

[$controller, $repo] = $buildHarness($makeDetail('CLOSED'), $seedThread());
$resSealedGet = $controller->getComments($makeGetRequest('142'));
$bodySealedGet = $resSealedGet->getDecodedBody();
$assert('4.3 El GET de un expediente sellado conserva la consulta y marca is_sealed=true (RF-05.3)',
    $resSealedGet->getStatusCode() === 200
    && ($bodySealedGet['data']['incident']['is_sealed'] ?? null) === true
);

$expiredResolvedAt = (new DateTimeImmutable('-72 hours'))->format('Y-m-d H:i:s');
[$controller, $repo] = $buildHarness($makeDetail('RESOLVED', $expiredResolvedAt), $seedThread());
$resExpired = $controller->addComment($makePostRequest(['comment_text' => 'Intento fuera de la ventana de garantía.']));
$assert('4.4 RESOLVED fuera de la garantía de 48 h también queda sellado con 403 (Art. V.6)',
    $resExpired->getStatusCode() === 403 && ($resExpired->getDecodedBody()['error']['code'] ?? '') === 'CONVERSATION_SEALED'
);

// =====================================================================
// CASO 5: Límites de 5 a 1.000 caracteres descriptivos (RF-03.1)
// =====================================================================
echo "\n--- Caso 5: Límites de longitud 5 a 1.000 caracteres ---\n";

[$controller, $repo] = $buildHarness($makeDetail('IN_PROGRESS'), $seedThread());
$resShort = $controller->addComment($makePostRequest(['comment_text' => 'ok']));
$assert('5.1 Texto de 2 caracteres => HTTP 422 INVALID_COMMENT_LENGTH',
    $resShort->getStatusCode() === 422 && ($resShort->getDecodedBody()['error']['code'] ?? '') === 'INVALID_COMMENT_LENGTH'
);

$resTooLong = $controller->addComment($makePostRequest(['comment_text' => str_repeat('a', 1001)]));
$assert('5.2 Texto de 1.001 caracteres => HTTP 422 INVALID_COMMENT_LENGTH',
    $resTooLong->getStatusCode() === 422 && ($resTooLong->getDecodedBody()['error']['code'] ?? '') === 'INVALID_COMMENT_LENGTH'
);

$assert('5.3 Ningún rechazo de longitud persistió mensaje adicional', count($repo->storedComments) === 5);

[$controller, $repo] = $buildHarness($makeDetail('IN_PROGRESS'), $seedThread());
$resExact = $controller->addComment($makePostRequest(['comment_text' => '12345']));
$assert('5.4 El límite inferior de 5 caracteres exactos se acepta de forma inclusiva',
    $resExact->getStatusCode() === 201 && count($repo->storedComments) === 6
);

// =====================================================================
// CASO 6: Autorización — aislamiento de sedes e inexistente (Art. V.4)
// =====================================================================
echo "\n--- Caso 6: Aislamiento de sedes y expediente inexistente ---\n";

[$controller, $repo] = $buildHarness($makeDetail('IN_PROGRESS'), $seedThread());
$resMismatchPost = $controller->addComment($makePostRequest(
    ['comment_text' => 'Intento no autorizado de otra sede.'],
    [],
    '142',
    $otherLocation
));
$resMismatchGet = $controller->getComments($makeGetRequest('142', [], $otherLocation));
$assert('6.1 POST desde otra sede => HTTP 403 SITE_MISMATCH',
    $resMismatchPost->getStatusCode() === 403 && ($resMismatchPost->getDecodedBody()['error']['code'] ?? '') === 'SITE_MISMATCH'
);
$assert('6.2 GET desde otra sede => HTTP 403 SITE_MISMATCH', $resMismatchGet->getStatusCode() === 403);
$assert('6.3 El intento cruzado no persistió nada', count($repo->storedComments) === 5);

[$controller, $repo] = $buildHarness(null, []);
$resNotFoundPost = $controller->addComment($makePostRequest(['comment_text' => 'Comentario a nadie.'], [], '999999'));
$resNotFoundGet = $controller->getComments($makeGetRequest('999999'));
$assert('6.4 Expediente inexistente => HTTP 404 INCIDENT_NOT_FOUND en POST y GET',
    $resNotFoundPost->getStatusCode() === 404
    && ($resNotFoundPost->getDecodedBody()['error']['code'] ?? '') === 'INCIDENT_NOT_FOUND'
    && $resNotFoundGet->getStatusCode() === 404
);

// =====================================================================
// CASO 7: Alias retrocompatible por código de ticket y paginación cursorizada
// =====================================================================
echo "\n--- Caso 7: Alias {ticket_code} y paginación cursorizada (RF-01.3) ---\n";

[$controller, $repo] = $buildHarness($makeDetail('IN_PROGRESS'), $seedThread());
$resAliasGet = $controller->getComments($makeGetRequest('TICK-2026-00142'));
$resAliasPost = $controller->addComment($makePostRequest(
    ['comment_text' => 'Mensaje enviado por el alias retrocompatible de ticket.'],
    [],
    'TICK-2026-00142'
));
$assert('7.1 El alias /api/incidents/{ticket_code}/comments funciona en GET y POST (plan.md §2.1.A)',
    $resAliasGet->getStatusCode() === 200 && $resAliasPost->getStatusCode() === 201
);
$assert('7.2 El POST por alias persistió exactamente un mensaje nuevo',
    count($repo->storedComments) === 6
);

[$controller, $repo] = $buildHarness($makeDetail('IN_PROGRESS'), $seedThread());
$resPage1 = $controller->getComments($makeGetRequest('142', ['limit' => '2']));
$bodyPage1 = $resPage1->getDecodedBody();
$assert('7.3 limit=2 devuelve el bloque más reciente con has_more_before=true',
    ($bodyPage1['data']['pagination']['loaded_count'] ?? null) === 2
    && ($bodyPage1['data']['pagination']['has_more_before'] ?? null) === true
    && ($bodyPage1['data']['pagination']['oldest_id'] ?? null) === 48
);
$resPage2 = $controller->getComments($makeGetRequest('142', ['limit' => '2', 'before_id' => '48']));
$bodyPage2 = $resPage2->getDecodedBody();
$assert('7.4 before_id=48 recupera el bloque anterior sin duplicados',
    ($bodyPage2['data']['pagination']['oldest_id'] ?? null) === 12
    && ($bodyPage2['data']['pagination']['has_more_before'] ?? null) === false
    && count($bodyPage2['data']['comments']) === 1
);

// Limpieza de los directorios temporales de subida creados por el arnés.
foreach (glob(sys_get_temp_dir() . '/vg_uploads_*') ?: [] as $tempUploadsDir) {
    foreach (glob((string)$tempUploadsDir . '/*') ?: [] as $tempUploadedFile) {
        @unlink((string)$tempUploadedFile);
    }
    @rmdir((string)$tempUploadsDir);
}

// ---------------------------------------------------------------------
// RESUMEN DE EJECUCIÓN
// ---------------------------------------------------------------------
echo "\n======================================================================\n";
echo " Total Aserciones: {$assertions} | Exitosas: " . ($assertions - $failures) . " | Fallidas: {$failures}\n";

if ($failures === 0) {
    echo " RESULTADO: 100% EN VERDE. CONDICIÓN T-COM-05 CUMPLIDA.\n";
    echo "======================================================================\n";
    exit(0);
}

echo " RESULTADO: FALLO EN {$failures} ASERCIONES.\n";
echo "======================================================================\n";
exit(1);
