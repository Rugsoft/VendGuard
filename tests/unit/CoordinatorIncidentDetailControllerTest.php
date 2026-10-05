<?php

declare(strict_types=1);

/**
 * CoordinatorIncidentDetailControllerTest
 *
 * Suite de pruebas unitarias de la tarea T-IDM-05 (módulo 09, endpoint de detalle
 * integral de incidencias en triaje). Valida la condición "Hecho cuando":
 * - `CoordinatorController::getIncidentDetail(Request $request)` responde 200 OK con
 *   la envolvente canónica JSON y el DTO enriquecido ante un ID o código de ticket válido.
 * - 404 Not Found ante tickets inexistentes o borrados lógicamente.
 * - 400 Bad Request ante un identificador de ruta inválido (vacío, negativo o con
 *   caracteres no admitidos).
 * - 401 Unauthorized sin identidad autenticada y 403 Forbidden con un rol distinto
 *   de COORDINATOR, sin llegar a consultar el expediente (Art. V.4).
 *
 * Dogma Vanilla: PHP 8.2 puro, sin dependencias externas y sin base de datos; el
 * repositorio se sustituye por un doble en memoria que implementa su contrato de
 * dominio y el controlador se compone sobre él (inyección por constructor).
 */

require_once __DIR__ . '/../../src/autoload.php';

use VendGuard\Core\Domain\Model\Incident;
use VendGuard\Core\Domain\Model\IncidentComment;
use VendGuard\Core\Domain\Repository\IncidentRepositoryInterface;
use VendGuard\Presentation\Controller\CoordinatorController;
use VendGuard\Presentation\Http\Request;

/**
 * Doble en memoria del contrato de repositorio: sirve el expediente enriquecido y
 * registra los identificadores consultados para auditar la delegación del controlador.
 */
final class CoordinatorIncidentDetailControllerStubRepo implements IncidentRepositoryInterface
{
    /** @var array<string, mixed>|null */
    public ?array $fixture = null;

    /** @var list<int|string> */
    public array $identifiers = [];

    public function findEnrichedDetailById(int|string $identifier): ?array
    {
        $this->identifiers[] = $identifier;

        return $this->fixture;
    }

    public function create(Incident $incident, ?int $userId = null, ?string $initialNote = null): Incident { throw new LogicException('Not used.'); }
    public function findById(int $id): ?Incident { return null; }
    public function findByTicketCode(string $ticketCode): ?Incident { return null; }
    public function findActiveByMachineId(int $machineId): ?Incident { return null; }
    public function findActiveOrResolvedByMachineId(int $machineId): ?Incident { return null; }
    public function findAllByLocation(int $locationId, bool $activeOnly = false): array { return []; }
    public function findAll(array $filters = []): array { return []; }
    public function findAssignedToTechnician(int $technicianId, array $statuses = []): array { return []; }
    public function update(Incident $incident): bool { return false; }
    public function softDelete(int $id): bool { return false; }
    public function insertHistory(int $incidentId, ?int $userId, ?string $fromStatus, string $toStatus, ?string $actionNote = null): int { return 1; }
    public function getHistory(int $incidentId): array { return []; }
    public function addComment(IncidentComment $comment): IncidentComment { throw new LogicException('Not used.'); }
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

echo "======================================================================\n";
echo " VendGuard: Suite Unitaria - Endpoint de Detalle de Incidencia (T-IDM-05)\n";
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
// ARNÉS: expediente enriquecido (plan §2.1) y controlador sobre el doble
// =====================================================================
$fixture = [
    'incident' => [
        'id' => 142, 'ticket_code' => 'TICK-2026-00142', 'machine_id' => 10, 'location_id' => 1,
        'assigned_technician_id' => 4, 'status' => 'PENDING_PARTS', 'urgency' => 'CRITICAL',
        'description' => 'El compresor no arranca y los sándwiches superan los 9°C.',
        'photo_path' => '/uploads/evidence/evidence_142.jpg',
        'pending_parts_reason' => 'Fallo en condensador de arranque y relé térmico del compresor.',
        'resolution_diagnosis' => null, 'resolution_action' => null,
        'reopen_reason' => 'La máquina volvió a fallar 2 horas después de la reparación del técnico.',
        'reopened_at' => '2026-10-02 11:30:00', 'resolved_at' => null, 'closed_at' => null,
        'cancelled_at' => null, 'cancellation_reason' => null,
        'assigned_at' => '2026-10-01 08:30:00', 'started_at' => '2026-10-01 09:10:00',
        'machine_type_snapshot' => 'PERISHABLE_FOOD',
        'created_at' => '2026-10-01 08:15:00', 'updated_at' => '2026-10-02 12:00:00',
    ],
    'machine' => ['id' => 10, 'location_id' => 1, 'code' => 'VEND-0101', 'model' => 'FAS Perla Fast Cold', 'machine_type' => 'PERISHABLE_FOOD', 'floor_wing' => 'Planta Baja - Urgencias', 'sanitary_status' => 'OK', 'notes' => null, 'is_active' => 1],
    'location' => ['id' => 1, 'site_code' => 'SEDE-BCN-01', 'name' => 'Hospital del Mar', 'address' => 'Passeig Marítim 25-29, Barcelona', 'has_physical_reception' => 1, 'latitude' => '41.3850640', 'longitude' => '2.1734035'],
    'technician' => ['id' => 4, 'name' => 'Jordi Cruz', 'operator_code' => 'OP-BCN-04', 'role' => 'TECHNICIAN', 'is_active' => 1],
    'history' => [
        ['id' => 1, 'user_id' => null, 'from_status' => null, 'to_status' => 'REGISTERED', 'action_note' => 'Aviso registrado mediante código QR', 'created_at' => '2026-10-01 08:15:00', 'user_name' => null, 'user_role' => null],
        ['id' => 2, 'user_id' => 2, 'from_status' => 'REGISTERED', 'to_status' => 'ASSIGNED', 'action_note' => 'Incidencia asignada al técnico ID 4.', 'created_at' => '2026-10-01 08:30:00', 'user_name' => 'Coordinación Central', 'user_role' => 'COORDINATOR'],
        ['id' => 3, 'user_id' => 4, 'from_status' => 'ASSIGNED', 'to_status' => 'PENDING_PARTS', 'action_note' => 'Pausa técnica por repuestos pendientes.', 'created_at' => '2026-10-01 09:45:00', 'user_name' => 'Jordi Cruz', 'user_role' => 'TECHNICIAN'],
    ],
    'comments' => [
        ['id' => 85, 'author_type' => 'REPORTER', 'user_id' => null, 'author_name' => 'Conserjería Hospital', 'comment_text' => 'El agua gotea por debajo de la máquina.', 'photo_path' => null, 'is_internal' => '0', 'created_at' => '2026-10-01 08:20:00'],
        ['id' => 86, 'author_type' => 'TECHNICIAN', 'user_id' => 4, 'author_name' => 'Jordi Cruz', 'comment_text' => 'Comprobada fuga en bandeja de desescarche.', 'photo_path' => null, 'is_internal' => '1', 'created_at' => '2026-10-01 09:46:00'],
    ],
    'requested_parts' => [
        ['id' => 1, 'spare_part_id' => 12, 'is_out_of_catalog' => '0', 'custom_part_description' => null, 'quantity' => '1', 'status' => 'PENDING', 'created_at' => '2026-10-01 09:45:00', 'part_code' => 'SP-FAS-RELAY-01', 'part_name' => 'Relé Térmico Compresor 230V'],
        ['id' => 2, 'spare_part_id' => null, 'is_out_of_catalog' => '1', 'custom_part_description' => 'Abrazadera reforzada antivibración para circuito de cobre', 'quantity' => '1', 'status' => 'PENDING', 'created_at' => '2026-10-01 09:45:00', 'part_code' => null, 'part_name' => null],
    ],
    'replaced_parts' => [
        ['id' => 9, 'spare_part_id' => 12, 'is_out_of_catalog' => '0', 'custom_part_name' => null, 'quantity' => '1', 'unit_cost_snapshot' => '28.50', 'total_cost_snapshot' => '28.50', 'old_part_destination' => 'DESGUACE', 'notes' => 'Enviada a desguace según protocolo.', 'installed_at' => '2026-10-01 09:40:00', 'created_at' => '2026-10-01 09:40:00', 'part_code' => 'SP-FAS-RELAY-01', 'part_name' => 'Relé Térmico Compresor 230V'],
    ],
    'refund' => ['id' => 5, 'incident_id' => 142, 'claimed_amount' => '2.50', 'compensation_method' => 'BIZUM', 'bizum_phone' => '612345789', 'iban' => null, 'status' => 'REQUIRES_COORDINATOR_APPROVAL', 'technician_finding' => 'FOUND_PHYSICAL', 'cash_custody_action' => 'HELD_FOR_CENTRAL', 'technician_justification' => 'Moneda de 2€ y 0.50€ retenidas en selector.', 'created_at' => '2026-10-01 08:20:00'],
];

$repo = new CoordinatorIncidentDetailControllerStubRepo();
$repo->fixture = $fixture;

$controller = new CoordinatorController(incidentRepo: $repo);

$coordinatorAttributes = ['user_id' => 2, 'user_role' => 'COORDINATOR'];

/**
 * Construye la petición tal y como la entrega el enrutador: método GET, ruta con
 * `{id}` resuelto, atributos del actor autenticado inyectados por el middleware.
 *
 * @param array<string, mixed> $attributes
 */
$makeRequest = function (string $routeIdentifier, array $attributes = []): Request {
    $request = new Request('GET', '/api/coordinator/incidents/' . rawurlencode($routeIdentifier) . '/detail');
    $request->setRouteParams(['id' => $routeIdentifier]);

    foreach ($attributes as $key => $value) {
        $request->setAttribute($key, $value);
    }

    return $request;
};

// =====================================================================
// CASO 1: Control de acceso RBAC (401 / 403) sin lectura del expediente
// =====================================================================
echo "--- Caso 1: Control de acceso RBAC (401 / 403) ---\n";

$resNoIdentity = $controller->getIncidentDetail($makeRequest('142'));
$assert(
    '1.1 Sin identidad autenticada responde HTTP 401 Unauthorized',
    $resNoIdentity->getStatusCode() === 401 && ($resNoIdentity->getDecodedBody()['error']['code'] ?? '') === 'UNAUTHORIZED',
    'Código obtenido: ' . $resNoIdentity->getStatusCode()
);

$resInvalidIdentity = $controller->getIncidentDetail($makeRequest('142', ['user_id' => 'abc', 'user_role' => 'COORDINATOR']));
$assert(
    '1.2 Identidad no numérica responde HTTP 401 Unauthorized',
    $resInvalidIdentity->getStatusCode() === 401 && ($resInvalidIdentity->getDecodedBody()['success'] ?? null) === false
);

$resTechnician = $controller->getIncidentDetail($makeRequest('142', ['user_id' => 4, 'user_role' => 'TECHNICIAN']));
$assert(
    '1.3 Rol TECHNICIAN responde HTTP 403 Forbidden',
    $resTechnician->getStatusCode() === 403 && ($resTechnician->getDecodedBody()['error']['code'] ?? '') === 'FORBIDDEN'
);

$resSite = $controller->getIncidentDetail($makeRequest('142', ['user_id' => 9, 'user_role' => 'LOCATION_MANAGER']));
$assert(
    '1.4 Rol de sede responde HTTP 403 Forbidden (Art. V.4)',
    $resSite->getStatusCode() === 403 && ($resSite->getDecodedBody()['error']['code'] ?? '') === 'FORBIDDEN'
);

$resDeniedInvalid = $controller->getIncidentDetail($makeRequest(''));
$assert(
    '1.5 La denegación de acceso precede a la validación del identificador',
    $resDeniedInvalid->getStatusCode() === 401
);

$assert(
    '1.6 Ninguna petición no autorizada consultó el expediente en el repositorio',
    $repo->identifiers === [],
    'Identificadores consultados: ' . json_encode($repo->identifiers)
);

// =====================================================================
// CASO 2: 200 OK con envolvente canónica y DTO enriquecido por ID primario
// =====================================================================
echo "\n--- Caso 2: ID primario válido ---\n";

$resById = $controller->getIncidentDetail($makeRequest('142', $coordinatorAttributes));
$assert(
    '2.1 ID numérico válido responde HTTP 200 OK',
    $resById->getStatusCode() === 200,
    'Código obtenido: ' . $resById->getStatusCode()
);

$assert(
    '2.2 El ID se delega al dominio como entero positivo',
    $repo->identifiers === [142],
    'Identificadores consultados: ' . json_encode($repo->identifiers)
);

$assert(
    '2.3 Emite cabecera Content-Type JSON',
    str_contains((string)$resById->getHeader('Content-Type'), 'application/json')
);

$bodyById = $resById->getDecodedBody();
$assert(
    '2.4 Envolvente canónica: success true y bloque data presente',
    is_array($bodyById) && ($bodyById['success'] ?? null) === true && isset($bodyById['data']) && is_array($bodyById['data'])
);

$detail = is_array($bodyById) ? ($bodyById['data'] ?? []) : [];
$assert(
    '2.5 Los diez bloques del contrato del modal, en su orden',
    array_keys($detail) === ['incident', 'location', 'machine', 'technician', 'timeline', 'sla', 'technical_intervention', 'comments', 'refund', 'permissions'],
    'Bloques obtenidos: ' . json_encode(array_keys($detail))
);

$assert(
    '2.6 Cabecera del expediente: ticket, estado, urgencia y reapertura',
    ($detail['incident']['id'] ?? null) === 142
        && ($detail['incident']['ticket_code'] ?? null) === 'TICK-2026-00142'
        && ($detail['incident']['status'] ?? null) === 'PENDING_PARTS'
        && ($detail['incident']['urgency'] ?? null) === 'CRITICAL'
        && ($detail['incident']['is_reopened'] ?? null) === true
);

$assert(
    '2.7 Ubicación y máquina perecedera con SLA de frío de 4 h',
    ($detail['location']['name'] ?? null) === 'Hospital del Mar'
        && ($detail['machine']['has_perishables'] ?? null) === true
        && ($detail['sla']['has_sla_limit'] ?? null) === true
        // El round-trip JSON puede entregar 4 (int) donde el DTO publica 4.0 (float).
        && (float)($detail['sla']['sla_limit_hours'] ?? 0) === 4.0
        && ($detail['sla']['is_active_countdown'] ?? null) === true,
    'location.name=' . json_encode($detail['location']['name'] ?? null)
        . ' machine.has_perishables=' . json_encode($detail['machine']['has_perishables'] ?? null)
        . ' sla=' . json_encode($detail['sla'] ?? null)
);

$assert(
    '2.8 Intervención técnica: pausa con piezas de catálogo y fuera de catálogo',
    ($detail['technical_intervention']['pause']['is_paused'] ?? null) === true
        && count($detail['technical_intervention']['pause']['requested_parts'] ?? []) === 2
        && ($detail['technical_intervention']['pause']['requested_parts'][1]['is_out_of_catalog'] ?? null) === true
);

$assert(
    '2.9 Bitácora con nota pública y nota interna diferenciadas',
    count($detail['comments'] ?? []) === 2
        && ($detail['comments'][0]['is_internal'] ?? null) === false
        && ($detail['comments'][1]['is_internal'] ?? null) === true
);

$assert(
    '2.10 Permisos operativos del estado en pausa según la máquina de estados',
    ($detail['permissions'] ?? null) === ['can_assign' => false, 'can_reassign' => true, 'can_cancel' => true, 'can_add_comment' => true]
);

$rawBody = $resById->getBody();
$assert(
    '2.11 El payload enmascara el teléfono Bizum y nunca expone el completo (Art. V.4)',
    !str_contains($rawBody, '612345789') && ($detail['refund']['contact_phone_masked'] ?? null) === '6** *** 789'
);

// =====================================================================
// CASO 3: 200 OK por código de ticket (con '#' opcional)
// =====================================================================
echo "\n--- Caso 3: Código de ticket válido ---\n";

$resByCode = $controller->getIncidentDetail($makeRequest('INC-2026-0001', $coordinatorAttributes));
$assert(
    '3.1 Código de ticket responde HTTP 200 OK',
    $resByCode->getStatusCode() === 200
);
$assert(
    '3.2 El código de ticket se delega literalmente al dominio',
    $repo->identifiers === [142, 'INC-2026-0001'],
    'Identificadores consultados: ' . json_encode($repo->identifiers)
);
$assert(
    '3.3 El código viaja igualmente en la envolvente y el DTO',
    ($resByCode->getDecodedBody()['data']['incident']['ticket_code'] ?? null) === 'TICK-2026-00142'
);

$resByHashCode = $controller->getIncidentDetail($makeRequest('#INC-2026-0001', $coordinatorAttributes));
$assert(
    '3.4 Código con prefijo # (URL-encoded en la ruta) responde HTTP 200 OK',
    $resByHashCode->getStatusCode() === 200 && $repo->identifiers === [142, 'INC-2026-0001', '#INC-2026-0001']
);

// =====================================================================
// CASO 4: Ticket inexistente -> 404 Not Found
// =====================================================================
echo "\n--- Caso 4: Ticket inexistente ---\n";

$repo->fixture = null;
$repo->identifiers = [];

$resMissing = $controller->getIncidentDetail($makeRequest('INC-2099-9999', $coordinatorAttributes));
$missingBody = $resMissing->getDecodedBody();
$assert(
    '4.1 Ticket inexistente responde HTTP 404 Not Found',
    $resMissing->getStatusCode() === 404 && ($missingBody['error']['code'] ?? '') === 'INCIDENT_NOT_FOUND',
    'Código obtenido: ' . $resMissing->getStatusCode()
);
$assert(
    '4.2 La envolvente de error marca success false',
    ($missingBody['success'] ?? null) === false
);
$assert(
    '4.3 El mensaje identifica el ticket buscado',
    str_contains((string)($missingBody['error']['message'] ?? ''), 'INC-2099-9999')
);
$assert(
    '4.4 El 404 se decide tras delegar la búsqueda en el repositorio',
    $repo->identifiers === ['INC-2099-9999'],
    'Identificadores consultados: ' . json_encode($repo->identifiers)
);

// =====================================================================
// CASO 5: Identificador de ruta inválido -> 400 Bad Request
// =====================================================================
echo "\n--- Caso 5: Identificador de ruta inválido ---\n";

$repo->fixture = $fixture;
$repo->identifiers = [];

$invalidIdentifiers = [
    '5.1 Identificador vacío',
    '5.2 Identificador cero',
    '5.3 Identificador negativo',
    '5.4 Identificador con caracteres no admitidos',
];

$invalidValues = ['', '0', '-5', 'INC 2026/01'];

foreach ($invalidValues as $index => $invalidValue) {
    $resInvalid = $controller->getIncidentDetail($makeRequest($invalidValue, $coordinatorAttributes));
    $assert(
        $invalidIdentifiers[$index] . ' responde HTTP 400 Bad Request',
        $resInvalid->getStatusCode() === 400 && ($resInvalid->getDecodedBody()['error']['code'] ?? '') === 'INVALID_INCIDENT_IDENTIFIER',
        "Valor '{$invalidValue}' devolvió " . $resInvalid->getStatusCode()
    );
}

$assert(
    '5.5 Un identificador inválido nunca consulta el repositorio',
    $repo->identifiers === [],
    'Identificadores consultados: ' . json_encode($repo->identifiers)
);

// =====================================================================
// RESUMEN DE EJECUCIÓN
// =====================================================================
echo "\n======================================================================\n";
echo " Total Aserciones: {$assertions} | Exitosas: " . ($assertions - $failures) . " | Fallidas: {$failures}\n";

if ($failures === 0) {
    echo " RESULTADO: 100% EN VERDE. CONDICIÓN T-IDM-05 CUMPLIDA.\n";
    echo "======================================================================\n";
    exit(0);
}

echo " RESULTADO: FALLO EN {$failures} ASERCIONES.\n";
echo "======================================================================\n";
exit(1);
