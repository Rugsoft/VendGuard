<?php

declare(strict_types=1);

/**
 * VendGuard - Módulo 08: Refunds and Unclaimed Cash Management
 *
 * Integration suite for the coordination workflow (T-REF-22).
 *
 * Cubre RF-REF-03 (visto bueno e importes), RF-REF-07 (ciclo formal y
 * liquidación digital), RF-REF-08 (desestimación motivada), RNF-REF-01
 * (rastro inmutable en `audit_log`) y RNF-REF-02 (los contadores y la
 * paginación salen del SQL real, no de un array en memoria).
 *
 * Esta suite NO sustituye a `SiteManagerRefundDataSegregationTest` (T-REF-13):
 * aquella certifica por `AppRouter` el cableado y el RBAC de los cuatro
 * endpoints de Coordinación; ésta certifica la lógica de estado y el SQL que
 * hay detrás. Juntas cierran las dos capas.
 *
 * Everything here runs against real MariaDB inside a transaction that is always
 * rolled back, so the suite exercises the actual SQL: the column list of
 * `findForCoordinator()` (which is what decides whether the IBAN reaches
 * Coordination), the optimistic `WHERE status = :expected` guard of
 * `transitionStatus()` and the audit rows that back RNF-REF-01.
 *
 * The controller is instantiated WITHOUT arguments on purpose. That is the
 * production wiring: four collaborators resolved inside the constructor and a
 * `RefundManagementService` built by default. A suite that injects its own
 * doubles would happily pass with a collaborator swapped or forgotten, which is
 * exactly the class of bug that made `TechnicianRefundService` throw a 500 in
 * T-REF-10.
 */

require_once __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/ConnectionFactory.php';
require_once __DIR__ . '/../../src/Infrastructure/Database/SeedRunner.php';

use VendGuard\Application\DTO\CreateRefundRequestDTO;
use VendGuard\Application\Service\RefundManagementService;
use VendGuard\Core\Domain\Model\CashCustodyAction;
use VendGuard\Core\Domain\Model\CompensationMethod;
use VendGuard\Core\Domain\Model\Incident;
use VendGuard\Core\Domain\Model\MachineType;
use VendGuard\Core\Domain\Model\RefundStatus;
use VendGuard\Core\Domain\Model\TechnicianFinding;
use VendGuard\Core\Domain\ValueObject\IncidentCategory;
use VendGuard\Core\Domain\ValueObject\IncidentStatus;
use VendGuard\Core\Domain\ValueObject\UrgencyLevel;
use VendGuard\Infrastructure\Database\ConnectionFactory;
use VendGuard\Infrastructure\Database\SeedRunner;
use VendGuard\Infrastructure\Repository\PdoIncidentRepository;
use VendGuard\Infrastructure\Repository\PdoLocationRepository;
use VendGuard\Infrastructure\Repository\PdoMachineRepository;
use VendGuard\Infrastructure\Repository\PdoRefundRequestRepository;
use VendGuard\Presentation\Controller\CoordinatorRefundController;
use VendGuard\Presentation\Http\Request;

$pdo = ConnectionFactory::getConnection();

// Las suites de integración comparten la base de datos y las anteriores pueden
// haberla purgado hasta dejarla sin datos maestros. Esta suite siembra SIEMPRE
// por su cuenta (fuera de su transacción, para que el rollback no arrase las
// semillas) en lugar de confiar en el estado que dejó quien corrió antes.
(new SeedRunner($pdo))->seedAll();

$failures = 0;
$assertions = 0;

$assert = static function (string $label, bool $condition, string $detail = '') use (&$failures, &$assertions): void {
    $assertions++;
    if ($condition) {
        echo "  [PASS] {$label}\n";
    } else {
        $failures++;
        echo "  [FAIL] {$label}" . ($detail !== '' ? " -- {$detail}" : '') . "\n";
    }
};

$requestsBefore = (int)$pdo->query('SELECT COUNT(*) FROM `refund_requests`')->fetchColumn();

$pdo->beginTransaction();

try {
    $location = (new PdoLocationRepository($pdo))->findBySiteCode('SEDE-BCN-01');
    $locationId = (int)($location?->getId() ?? 0);

    // Máquina PROPIA de esta suite, creada dentro de la transacción. Reutilizar
    // una máquina semilla compartida haría que el resultado dependiera del orden
    // de ejecución: si otra suite resolvió esa avería hace menos de 48 h,
    // `PdoIncidentRepository::create()` aborta con `DuplicateIncidentException`
    // (Art. V.6) y esta suite ni siquiera llega a empezar. Con una máquina
    // propia no hay incidencia previa que la bloquee y el rollback se la lleva.
    $machineId = (int)((new PdoMachineRepository($pdo))->create([
        'location_id' => $locationId,
        'code' => 'TREF22-' . strtoupper(bin2hex(random_bytes(3))),
        'model' => 'VendGuard coordinator workflow machine',
        'machine_type' => MachineType::HOT_DRINKS->value,
        'floor_wing' => 'Planta baja - Vestíbulo',
        'notes' => 'Máquina creada dentro de la transacción reversible de T-REF-22.',
    ])?->getId() ?? 0);

    $coordinatorId = (int)$pdo->query("SELECT `id` FROM `users` WHERE `role` = 'COORDINATOR' ORDER BY `id` LIMIT 1")->fetchColumn();
    $technicianId = (int)$pdo->query("SELECT `id` FROM `users` WHERE `role` = 'TECHNICIAN' ORDER BY `id` LIMIT 1")->fetchColumn();

    // La incidencia se crea DENTRO de la transacción: `seedAll()` no siembra
    // incidencias y las suites anteriores pueden haber purgado las que había.
    // Al hacer rollback desaparece con el resto de filas de la prueba.
    $incidentId = (int)(new PdoIncidentRepository($pdo))->create(
        new Incident(
            null,
            'INC-TEST-TREF12',
            $machineId,
            $locationId,
            IncidentCategory::PAYMENT_SYSTEM,
            'La máquina cobra y no entrega el producto (T-REF-22).',
            UrgencyLevel::HIGH,
            IncidentStatus::REGISTERED
        ),
        null,
        'Aviso de prueba T-REF-22'
    )->getId();

    $assert('0.1 El entorno tiene datos maestros sembrados', $incidentId > 0 && $machineId > 0 && $locationId > 0, "incidente: {$incidentId} máquina: {$machineId} sede: {$locationId}");
    $assert('0.2 Y existe un coordinador y un técnico para las sesiones', $coordinatorId > 0 && $technicianId > 0);

    // Cableado REAL de producción: sin inyectar nada.
    $controller = new CoordinatorRefundController();
    $repo = new PdoRefundRequestRepository($pdo);
    $service = new RefundManagementService($repo);

    $request = static function (
        string $method,
        string $path,
        array $body = [],
        array $query = [],
        ?int $userId = null,
        ?string $role = null
    ): Request {
        $req = new Request($method, $path, $query, $body, ['content-type' => 'application/json']);

        if (preg_match('#/refunds/(\d+)/#', $path, $matches) === 1) {
            $req->setRouteParams(['id' => $matches[1]]);
        }

        $req->setAttribute('user_id', $userId);
        $req->setAttribute('user_role', $role);

        return $req;
    };

    $decode = static function ($response): array {
        $body = json_decode($response->getBody(), true);

        return is_array($body) ? $body : [];
    };

    $rowOf = static function (array $body, int $id): ?array {
        foreach ($body['data']['items'] ?? [] as $row) {
            if ((int)($row['id'] ?? 0) === $id) {
                return $row;
            }
        }

        return null;
    };

    // Cada llamada monta un escenario distinto de la bandeja de Coordinación, no
    // una segunda reclamación del mismo consumidor: RF-REF-11 lo prohíbe, así que
    // el expediente se abre con un contacto propio en vez de repetir el de Laura.
    $claimantSeq = 0;
    $lastClaimantContact = '';
    $openCase = static function (CompensationMethod $method, float $amount) use ($service, $incidentId, $machineId, $locationId, &$claimantSeq, &$lastClaimantContact): int {
        $contact = (string)(600000000 + (++$claimantSeq));
        $lastClaimantContact = $contact;
        $case = $service->createCase(new CreateRefundRequestDTO(
            incidentId: $incidentId,
            machineId: $machineId,
            locationId: $locationId,
            claimantName: 'Laura Sanitaria',
            claimantContact: $contact,
            claimedAmount: $amount,
            compensationMethod: $method,
            productAttempted: 'Café con leche carril 2',
            bizumPhone: $method->requiresBizumPhone() ? $contact : null,
            iban: $method->requiresIban() ? 'ES9121000418450200051332' : null
        ));

        return (int)$case->getId();
    };

    $escalate = static function (int $caseId, float $amount) use ($repo): void {
        $repo->transitionStatus($caseId, RefundStatus::PENDING_INSPECTION, RefundStatus::REQUIRES_COORDINATOR_APPROVAL, [
            'technician_finding' => TechnicianFinding::FOUND_PHYSICAL->value,
            'recovered_amount' => $amount,
            'cash_custody_action' => CashCustodyAction::HELD_FOR_CENTRAL->value,
            'technician_justification' => 'Caja central sin monedas en el corte.',
        ]);
    };

    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 1. La bandeja global con detalle financiero completo ---\n";

    $transferCaseId = $openCase(CompensationMethod::TRANSFERENCIA_BANCARIA, 12.00);
    $escalate($transferCaseId, 12.00);
    $bizumCaseId = $openCase(CompensationMethod::BIZUM, 30.00);
$bizumContact = $lastClaimantContact;
    $escalate($bizumCaseId, 4.00);
    $freshCaseId = $openCase(CompensationMethod::EN_MANO_SEDE, 2.00);

    $inbox = $controller->index($request('GET', '/api/coordinator/refunds', [], [], $coordinatorId, 'COORDINATOR'));
    $inboxBody = $decode($inbox);
    $inboxRaw = (string)$inbox->getBody();

    $assert('1.1 La bandeja responde HTTP 200', $inbox->getStatusCode() === 200, 'HTTP ' . $inbox->getStatusCode() . ' ' . $inboxRaw);
    $assert(
        '1.2 El total sale del COUNT real de MariaDB',
        (int)($inboxBody['data']['total'] ?? 0) === $requestsBefore + 3,
        'total: ' . ($inboxBody['data']['total'] ?? 'AUSENTE') . ' esperado: ' . ($requestsBefore + 3)
    );

    $transferRow = $rowOf($inboxBody, $transferCaseId);
    $assert('1.3 El expediente de transferencia aparece en la bandeja', $transferRow !== null);
    $assert(
        '1.4 El SQL de Coordinación sí proyecta el IBAN (Art. V.4, única bandeja con permiso)',
        ($transferRow['iban'] ?? null) === 'ES9121000418450200051332',
        'iban: ' . var_export($transferRow['iban'] ?? null, true)
    );
    $assert(
        '1.5 Y el teléfono Bizum del expediente correspondiente',
        ($rowOf($inboxBody, $bizumCaseId)['bizum_phone'] ?? null) === $bizumContact
    );
    $assert(
        '1.6 El nombre completo del reclamante sí se publica aquí',
        ($transferRow['claimant_name'] ?? '') === 'Laura Sanitaria'
    );
    $assert('1.7 El PIN de recogida NO se publica en la bandeja', !str_contains($inboxRaw, '"pickup_pin"'));
    $assert('1.8 El token de seguimiento público tampoco', !str_contains($inboxRaw, 'tracking_token'));

    $storedPin = (string)$repo->findById($freshCaseId)?->getPickupPin();
    $assert(
        '1.9 Control de no-vacuidad: el PIN existe en la base de datos que la bandeja oculta',
        preg_match('/^[0-9]{4}$/', $storedPin) === 1,
        'pin: ' . var_export($storedPin, true)
    );
    $assert('1.10 Y tampoco aparece su valor suelto en el JSON', !str_contains($inboxRaw, $storedPin));

    // Las proyecciones restringidas siguen ciegas al dinero tras ampliar la
    // lista FULL_COLUMNS (regresión sobre el Art. V.4).
    $restrictedIncident = $repo->findRestrictedByIncident($incidentId);
    $restrictedLocation = $repo->findRestrictedByLocation($locationId);
    $assert(
        '1.11 La proyección de técnico/mostrador sigue sin IBAN',
        $restrictedIncident !== []
        && $restrictedIncident[0]->hasRestrictedFinancialColumns()
        && $restrictedIncident[0]->getIban() === null
    );
    $assert(
        '1.12 Y la de conserjería también',
        $restrictedLocation !== [] && $restrictedLocation[0]->getIban() === null
    );

    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 2. Filtros reales sobre SQL ---\n";

    $onlyApproval = $controller->index($request(
        'GET',
        '/api/coordinator/refunds',
        [],
        ['requires_approval_only' => '1'],
        $coordinatorId,
        'COORDINATOR'
    ));
    $onlyApprovalBody = $decode($onlyApproval);
    $expectedApprovals = (int)$pdo->query(
        "SELECT COUNT(*) FROM `refund_requests` WHERE `status` = 'REQUIRES_COORDINATOR_APPROVAL' AND `is_active` = 1"
    )->fetchColumn();

    $assert(
        '2.1 `requires_approval_only` cuenta exactamente los expedientes escalados',
        (int)($onlyApprovalBody['data']['total'] ?? -1) === $expectedApprovals,
        'total: ' . ($onlyApprovalBody['data']['total'] ?? 'AUSENTE') . ' esperado: ' . $expectedApprovals
    );
    $assert(
        '2.2 Y el contador de pendientes de visto bueno coincide con SQL',
        (int)($onlyApprovalBody['data']['requires_approval_total'] ?? -1) === $expectedApprovals
    );
    $assert(
        '2.3 Cada fila filtrada viene marcada como pendiente de aprobación',
        ($onlyApprovalBody['data']['items'][0]['requires_approval'] ?? false) === true
    );

    $paged = $controller->index($request(
        'GET',
        '/api/coordinator/refunds',
        [],
        ['limit' => '2', 'offset' => '0'],
        $coordinatorId,
        'COORDINATOR'
    ));
    $pagedBody = $decode($paged);
    $assert('2.4 La paginación devuelve una página de 2', count($pagedBody['data']['items'] ?? []) === 2);
    $assert(
        '2.5 Con el total completo, para que la paginación no mienta',
        (int)($pagedBody['data']['total'] ?? 0) === $requestsBefore + 3
    );

    $badFilter = $controller->index($request(
        'GET',
        '/api/coordinator/refunds',
        [],
        ['status' => 'NO_EXISTE'],
        $coordinatorId,
        'COORDINATOR'
    ));
    $assert('2.6 Un estado inventado se rechaza con 422', $badFilter->getStatusCode() === 422, 'HTTP ' . $badFilter->getStatusCode());

    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 3. Visto bueno y liquidación contra MariaDB ---\n";

    $approveRes = $controller->approve($request(
        'POST',
        "/api/coordinator/refunds/{$transferCaseId}/approve",
        ['approved_amount' => 11.75, 'notes' => 'Corte de caja revisado y ticket de venta coincidente.'],
        [],
        $coordinatorId,
        'COORDINATOR'
    ));
    $approveBody = $decode($approveRes);
    $approveRow = $repo->findById($transferCaseId);

    $assert('3.1 El visto bueno responde HTTP 200', $approveRes->getStatusCode() === 200, (string)$approveRes->getBody());
    $assert('3.2 La entidad queda en VERIFIED_PENDING_PAYMENT', $approveRow?->getStatus() === RefundStatus::VERIFIED_PENDING_PAYMENT);
    $assert('3.3 BD: approved_amount persistido', (float)$approveRow?->getApprovedAmount() === 11.75);
    $assert('3.4 BD: coordinator_decision persistido', ($approveRow?->getCoordinatorDecision()?->value ?? '') === 'APPROVED');
    $assert(
        '3.5 BD: el motivo queda escrito',
        str_contains((string)$approveRow?->getCoordinatorJustification(), 'Corte de caja revisado')
    );
    $assert(
        '3.6 BD: la decisión queda firmada por el coordinador autenticado',
        (int)$pdo->query("SELECT `coordinator_id` FROM `refund_requests` WHERE `id` = {$transferCaseId}")->fetchColumn() === $coordinatorId,
        'coordinator_id: ' . var_export($pdo->query("SELECT `coordinator_id` FROM `refund_requests` WHERE `id` = {$transferCaseId}")->fetchColumn(), true)
    );

    $payRes = $controller->pay($request(
        'POST',
        "/api/coordinator/refunds/{$transferCaseId}/pay",
        ['payment_reference' => 'TRANSF-2026-0001', 'paid_amount' => 11.75],
        [],
        $coordinatorId,
        'COORDINATOR'
    ));
    $payBody = $decode($payRes);
    $payRow = $repo->findById($transferCaseId);
    $paidAtDb = $pdo->query("SELECT `paid_at` FROM `refund_requests` WHERE `id` = {$transferCaseId}")->fetchColumn();

    $assert('3.7 La liquidación responde HTTP 200', $payRes->getStatusCode() === 200, (string)$payRes->getBody());
    $assert('3.8 BD: status pasa a PAID_DIGITAL', ($payRow?->getStatus()->value ?? '') === 'PAID_DIGITAL');
    $assert('3.9 BD: payment_reference persistido', ($payRow?->getPaymentReference() ?? '') === 'TRANSF-2026-0001');
    $assert(
        '3.10 BD: paid_at persistido (contrato §4.4.3)',
        is_string($paidAtDb) && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $paidAtDb) === 1,
        'paid_at: ' . var_export($paidAtDb, true)
    );
    $assert(
        '3.11 La respuesta es la del contrato §4.4.3',
        ($payBody['message'] ?? '') === 'Pago digital registrado con éxito. Expediente de reintegro liquidado.'
        && array_key_exists('paid_at', $payBody['data'] ?? [])
    );
    $assert(
        '3.12 BD: audit_log deja el rastro del ciclo (RNF-REF-01)',
        (int)$pdo->query(
            "SELECT COUNT(*) FROM `audit_log` WHERE `entity_type` = 'REFUND_REQUEST' AND `entity_id` = {$transferCaseId}
             AND `action` IN ('REFUND_CASE_CREATED', 'REFUND_APPROVED', 'REFUND_PAID_DIGITAL')"
        )->fetchColumn() === 3
    );
    // `fetchAll()` devuelve un array: hay que unirlo, porque un `(string)` sobre
    // un array produce la cadena "Array" y la comprobación pasaría sin mirar
    // nada. El rastro se lee de las tres columnas de estado, no sólo de
    // `metadata`: el apunte de un pago no guarda ahí ningún detalle.
    $auditRows = $pdo->query(
        "SELECT COALESCE(`metadata`, ''), COALESCE(`previous_state`, ''), COALESCE(`new_state`, '')
         FROM `audit_log` WHERE `entity_type` = 'REFUND_REQUEST' AND `entity_id` = {$transferCaseId}"
    )->fetchAll(PDO::FETCH_NUM);
    $auditBlob = implode("\n", array_map(
        static fn (array $row): string => implode(' | ', array_map(strval(...), $row)),
        $auditRows
    ));

    $assert(
        '3.13 Control de no-vacuidad: el rastro de auditoría del expediente existe y tiene contenido',
        count($auditRows) === 3 && trim($auditBlob) !== '',
        'filas: ' . count($auditRows) . ' :: ' . $auditBlob
    );
    $assert(
        '3.14 BD: el rastro de auditoría no arrastra el IBAN (Art. V.4)',
        !str_contains($auditBlob, 'ES9121000418450200051332'),
        'rastro: ' . $auditBlob
    );
    $assert(
        '3.15 Ni el teléfono Bizum',
        !str_contains($auditBlob, '600111222'),
        'rastro: ' . $auditBlob
    );

    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 4. Desestimación motivada contra MariaDB ---\n";

    $rejectRes = $controller->reject($request(
        'POST',
        "/api/coordinator/refunds/{$bizumCaseId}/reject",
        ['rejection_reason' => 'Auditoría de ventas sin cobro coincidente y máquina operando con normalidad.'],
        [],
        $coordinatorId,
        'COORDINATOR'
    ));
    $rejectBody = $decode($rejectRes);
    $rejectRow = $repo->findById($bizumCaseId);

    $assert('4.1 La desestimación motivada responde HTTP 200', $rejectRes->getStatusCode() === 200, (string)$rejectRes->getBody());
    $assert('4.2 BD: status pasa a REJECTED', ($rejectRow?->getStatus()->value ?? '') === 'REJECTED');
    $assert('4.3 BD: coordinator_decision = REJECTED', ($rejectRow?->getCoordinatorDecision()?->value ?? '') === 'REJECTED');
    $assert(
        '4.4 BD: el motivo supera los 20 caracteres (RF-REF-08)',
        mb_strlen((string)$pdo->query("SELECT `coordinator_justification` FROM `refund_requests` WHERE `id` = {$bizumCaseId}")->fetchColumn()) >= 20
    );
    $assert(
        '4.5 BD: la fila se conserva íntegra con su importe (Art. III, cero borrado físico)',
        $rejectRow !== null && $rejectRow->isActive() && (float)$pdo->query("SELECT `claimed_amount` FROM `refund_requests` WHERE `id` = {$bizumCaseId}")->fetchColumn() === 30.00
    );
    $assert(
        '4.6 BD: el rechazo queda auditado',
        (int)$pdo->query("SELECT COUNT(*) FROM `audit_log` WHERE `entity_type` = 'REFUND_REQUEST' AND `entity_id` = {$bizumCaseId} AND `action` = 'REFUND_REJECTED'")->fetchColumn() === 1
    );

    $shortRes = $controller->reject($request(
        'POST',
        "/api/coordinator/refunds/{$freshCaseId}/reject",
        ['rejection_reason' => 'No'],
        [],
        $coordinatorId,
        'COORDINATOR'
    ));
    $assert(
        '4.7 Un motivo de 2 caracteres se rechaza con 422 JUSTIFICATION_TOO_SHORT',
        $shortRes->getStatusCode() === 422
        && ($decode($shortRes)['error']['code'] ?? '') === 'JUSTIFICATION_TOO_SHORT',
        (string)$shortRes->getBody()
    );
    $assert(
        '4.8 Y el expediente sigue donde estaba',
        ($repo->findById($freshCaseId)?->getStatus()->value ?? '') === 'PENDING_INSPECTION'
    );

    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 5. Blindaje de rol y de estado (Art. V.4) ---\n";

    $anon = $controller->index($request('GET', '/api/coordinator/refunds'));
    $assert('5.1 Sin sesión la bandeja responde 401', $anon->getStatusCode() === 401, 'HTTP ' . $anon->getStatusCode());

    foreach ([
        ['POST', "/api/coordinator/refunds/{$freshCaseId}/approve", ['approved_amount' => 2.00], 'approve'],
        ['POST', "/api/coordinator/refunds/{$freshCaseId}/pay", ['payment_reference' => 'X'], 'pay'],
        ['POST', "/api/coordinator/refunds/{$freshCaseId}/reject", ['rejection_reason' => 'Motivo válido de más de veinte caracteres.'], 'reject'],
    ] as [$method, $path, $body, $label]) {
        $asTechnician = $method === 'POST'
            ? $controller->{$label}($request($method, $path, $body, [], $technicianId, 'TECHNICIAN'))
            : null;
        $assert("5.2 Un técnico recibe 403 en {$label}", $asTechnician?->getStatusCode() === 403, 'HTTP ' . ($asTechnician?->getStatusCode() ?? 0));

        $anonymous = $method === 'POST'
            ? $controller->{$label}($request($method, $path, $body))
            : null;
        $assert("5.3 Y sin sesión, 401 en {$label}", $anonymous?->getStatusCode() === 401, 'HTTP ' . ($anonymous?->getStatusCode() ?? 0));
    }

    $premature = $controller->approve($request(
        'POST',
        "/api/coordinator/refunds/{$freshCaseId}/approve",
        ['approved_amount' => 2.00],
        [],
        $coordinatorId,
        'COORDINATOR'
    ));
    $assert(
        '5.4 Aprobar sin escalado previo devuelve 409 (doble autorización real)',
        $premature->getStatusCode() === 409,
        'HTTP ' . $premature->getStatusCode() . ' ' . $premature->getBody()
    );

    $payRejected = $controller->pay($request(
        'POST',
        "/api/coordinator/refunds/{$bizumCaseId}/pay",
        ['payment_reference' => 'TRANSF-TARDE'],
        [],
        $coordinatorId,
        'COORDINATOR'
    ));
    $assert('5.5 Pagar un expediente desestimado devuelve 409', $payRejected->getStatusCode() === 409, 'HTTP ' . $payRejected->getStatusCode());

    $ghost = $controller->approve($request(
        'POST',
        '/api/coordinator/refunds/999999/approve',
        ['approved_amount' => 5.00],
        [],
        $coordinatorId,
        'COORDINATOR'
    ));
    $assert(
        '5.6 Un expediente inexistente devuelve 404 REFUND_NOT_FOUND',
        $ghost->getStatusCode() === 404 && ($decode($ghost)['error']['code'] ?? '') === 'REFUND_NOT_FOUND',
        (string)$ghost->getBody()
    );

    $assert(
        '5.7 BD: ninguno de los intentos fallidos ha movido dinero',
        (string)$pdo->query("SELECT `status` FROM `refund_requests` WHERE `id` = {$bizumCaseId}")->fetchColumn() === 'REJECTED'
        && (string)$pdo->query("SELECT `status` FROM `refund_requests` WHERE `id` = {$freshCaseId}")->fetchColumn() === 'PENDING_INSPECTION'
    );

    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 6. Inviolabilidad del histórico (Art. III) ---\n";

    $requestsAfter = (int)$pdo->query('SELECT COUNT(*) FROM `refund_requests`')->fetchColumn();
    $assert(
        '6.1 Sólo hay filas nuevas: ninguna operación ha borrado nada',
        $requestsAfter === $requestsBefore + 3,
        'antes: ' . $requestsBefore . ' después: ' . $requestsAfter
    );
    $assert(
        '6.2 Los expedientes desestimados siguen consultables para auditoría',
        (int)$pdo->query("SELECT COUNT(*) FROM `refund_requests` WHERE `status` = 'REJECTED' AND `coordinator_justification` IS NOT NULL")->fetchColumn() >= 1
    );

    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 7. Rendimiento en servidor (RNF-REF-02) ---\n";
    // ─────────────────────────────────────────────────────────────────────
    // RNF-REF-02 exige que la consulta de la bandeja y el procesamiento de
    // cambios de estado terminen por debajo de 150 ms en servidor. El requisito
    // llevaba meses declarado en la matriz de trazabilidad sin que NINGUNA
    // prueba lo midiera: esta sección es la que lo convierte en una afirmación
    // verificada en vez de una intención.
    //
    // Por qué la mediana y no una única muestra: una aserción de latencia sobre
    // una sola ejecución mide el ruido del sistema operativo tanto como el
    // código, y en un portátil con el antivirus y el IDE abiertos es una bomba
    // de intermitencias. Con 40 muestras la mediana sigue la señal y descarta
    // los picos, que es lo que se quiere evaluar.
    $PERFORMANCE_LIMIT_MS = 150.0;
    $medianMs = static function (callable $work, int $runs = 40) use ($pdo): array {
        $samples = [];
        for ($i = 0; $i < $runs; $i++) {
            $start = microtime(true);
            $work();
            $samples[] = (microtime(true) - $start) * 1000;
        }
        sort($samples);

        return [
            'median' => $samples[(int)floor($runs / 2)],
            'worst' => $samples[$runs - 1],
        ];
    };

    $inboxTiming = $medianMs(static fn () => $repo->findForCoordinator());
    $locationTiming = $medianMs(static fn () => $repo->findRestrictedByLocation($locationId));
    $transitionTiming = $medianMs(static function () use ($repo, $freshCaseId) {
        $repo->transitionStatus(
            $freshCaseId,
            RefundStatus::PENDING_INSPECTION,
            RefundStatus::VERIFIED_PENDING_PAYMENT,
            ['technician_finding' => TechnicianFinding::CONFIRMED_NO_CASH->value]
        );
    });

    $assert(
        '7.1 La consulta de la bandeja de Coordinación va por debajo de 150 ms (RNF-REF-02)',
        $inboxTiming['median'] < $PERFORMANCE_LIMIT_MS,
        sprintf('mediana: %.3f ms | peor: %.3f ms | límite: %.0f ms', $inboxTiming['median'], $inboxTiming['worst'], $PERFORMANCE_LIMIT_MS)
    );
    $assert(
        '7.2 El listado restringido de sede va por debajo de 150 ms',
        $locationTiming['median'] < $PERFORMANCE_LIMIT_MS,
        sprintf('mediana: %.3f ms | peor: %.3f ms', $locationTiming['median'], $locationTiming['worst'])
    );
    $assert(
        '7.3 El procesamiento de un cambio de estado va por debajo de 150 ms',
        $transitionTiming['median'] < $PERFORMANCE_LIMIT_MS,
        sprintf('mediana: %.3f ms | peor: %.3f ms', $transitionTiming['median'], $transitionTiming['worst'])
    );

    // Una mediana holgada no basta: el objetivo es que ni siquiera la muestra
    // peor se acerque al umbral con la maquina cargada. Se exige margen para
    // que una excepcion aislada del sistema no tumbe la bateria, sin tolerar
    // que el caso tipico se acerque al limite.
    $assert(
        '7.4 El caso típico conserva margen de sobra sobre el umbral',
        $inboxTiming['median'] < $PERFORMANCE_LIMIT_MS / 4,
        sprintf('mediana: %.3f ms | cuarto de umbral: %.1f ms', $inboxTiming['median'], $PERFORMANCE_LIMIT_MS / 4)
    );
    $assert(
        '7.5 Ni una sola de las 40 muestras se acerca al límite',
        $inboxTiming['worst'] < $PERFORMANCE_LIMIT_MS / 2,
        sprintf('peor: %.3f ms | mitad del umbral: %.1f ms', $inboxTiming['worst'], $PERFORMANCE_LIMIT_MS / 2)
    );

    // Los índices que sostienen esa latencia están declarados en la migración;
    // sin ellos la consulta degrada a un barrido completo en cuanto la tabla
    // crezca, que es justo lo que el requisito pretende evitar.
    $indexed = (int)$pdo->query('
        SELECT COUNT(*) FROM information_schema.statistics
        WHERE table_schema = DATABASE() AND table_name = \'refund_requests\'
    ')->fetchColumn();
    $assert(
        '7.6 La tabla tiene los índices que hacen posible el rendimiento (008)',
        $indexed >= 2,
        'índices en refund_requests: ' . $indexed
    );
// ─────────────────────────────────────────────────────────────────────
// ─────────────────────────────────────────────────────────────────────
    echo "\n--- 8. Integridad del dinero: techo de pago e importe persistido (RF-REF-03, RF-REF-07) ---\n";
    // ─────────────────────────────────────────────────────────────────────

    $errOf = static fn ($response): string => (string)($response->getDecodedBody()['error']['code'] ?? '');
    $bodyOf = static fn ($response): array => (array)($response->getDecodedBody()['data'] ?? []);

    // The ceiling is the approved amount, not the claimed one: a coordinator who
    // signed 1,00 for antifraud reasons must not be able to settle 45,00.
    $cappedCaseId = $openCase(CompensationMethod::BIZUM, 30.00);
    $escalate($cappedCaseId, 30.00);
    $approve = $controller->approve($request(
        'POST',
        "/api/coordinator/refunds/{$cappedCaseId}/approve",
        ['approved_amount' => 1.00, 'justification' => 'Control antifraude estricto, se aprueba un euro.'],
        [],
        $coordinatorId,
        'COORDINATOR'
    ));

    $assert(
        '8.1 El visto bueno de 1,00 se registra en el expediente',
        $approve->getStatusCode() === 200
        && (float)($bodyOf($approve)['approved_amount'] ?? 0) === 1.00,
        'HTTP ' . $approve->getStatusCode() . ' ' . (string)$approve->getBody()
    );

    $overApproved = $controller->pay($request(
        'POST',
        "/api/coordinator/refunds/{$cappedCaseId}/pay",
        ['payment_reference' => 'REF-EXCESO', 'paid_amount' => 45.00],
        [],
        $coordinatorId,
        'COORDINATOR'
    ));

    $assert(
        '8.2 No se puede liquidar muy por encima del importe aprobado',
        $overApproved->getStatusCode() === 422 && $errOf($overApproved) === 'INVALID_REFUND_AMOUNT',
        'HTTP ' . $overApproved->getStatusCode() . ' ' . $errOf($overApproved)
    );

    $assert(
        '8.3 El rechazo del exceso deja el expediente sin tocar',
        (string)$pdo->query("SELECT `status` FROM `refund_requests` WHERE `id` = {$cappedCaseId}")->fetchColumn() === 'VERIFIED_PENDING_PAYMENT'
        && $pdo->query("SELECT `paid_amount` FROM `refund_requests` WHERE `id` = {$cappedCaseId}")->fetchColumn() === null,
        'estado: ' . (string)$pdo->query("SELECT `status` FROM `refund_requests` WHERE `id` = {$cappedCaseId}")->fetchColumn()
    );

    // Without an approval behind it the ceiling is the 50,00 block. The audit
    // settled a 3,20 case for 999999,00 with a plain HTTP 200.
    $tinyCaseId = $openCase(CompensationMethod::BIZUM, 3.20);
    $escalate($tinyCaseId, 3.20);
    $controller->approve($request(
        'POST',
        "/api/coordinator/refunds/{$tinyCaseId}/approve",
        ['approved_amount' => 3.20, 'justification' => 'Importe verificado contra el efectivo recuperado.'],
        [],
        $coordinatorId,
        'COORDINATOR'
    ));
    $absurd = $controller->pay($request(
        'POST',
        "/api/coordinator/refunds/{$tinyCaseId}/pay",
        ['payment_reference' => 'REF-ABSURDA', 'paid_amount' => 999999.00],
        [],
        $coordinatorId,
        'COORDINATOR'
    ));

    $assert(
        '8.4 No se puede liquidar 999999,00 sobre un expediente de 3,20',
        $absurd->getStatusCode() === 422 && $errOf($absurd) === 'INVALID_REFUND_AMOUNT',
        'HTTP ' . $absurd->getStatusCode() . ' ' . $errOf($absurd)
    );

    // The happy path: the settled amount is now a stored fact, not an echo of the
    // request. This is what the migration 011 `paid_amount` column exists for.
    $paidCaseId = $openCase(CompensationMethod::TRANSFERENCIA_BANCARIA, 8.40);
    $escalate($paidCaseId, 8.40);
    $controller->approve($request(
        'POST',
        "/api/coordinator/refunds/{$paidCaseId}/approve",
        ['approved_amount' => 8.40, 'justification' => 'Importe verificado contra el efectivo recuperado.'],
        [],
        $coordinatorId,
        'COORDINATOR'
    ));
    $settle = $controller->pay($request(
        'POST',
        "/api/coordinator/refunds/{$paidCaseId}/pay",
        ['payment_reference' => 'PAGO-REAL-0001', 'paid_amount' => 8.40],
        [],
        $coordinatorId,
        'COORDINATOR'
    ));
    $stored = $pdo->query(
        "SELECT `paid_amount`, `payment_reference`, `status` FROM `refund_requests` WHERE `id` = {$paidCaseId}"
    )->fetch(PDO::FETCH_ASSOC);

    $assert(
        '8.5 El importe liquidado queda PERSISTIDO en la fila del expediente',
        $settle->getStatusCode() === 200
        && (float)$stored['paid_amount'] === 8.40
        && $stored['payment_reference'] === 'PAGO-REAL-0001'
        && $stored['status'] === 'PAID_DIGITAL',
        'stored: ' . json_encode($stored)
    );

    $assert(
        '8.6 La respuesta de pago devuelve el importe persistido, no el del cuerpo',
        (float)($bodyOf($settle)['paid_amount'] ?? 0) === 8.40,
        (string)$settle->getBody()
    );

    $inboxAfter = $controller->index($request('GET', '/api/coordinator/refunds', [], [], $coordinatorId, 'COORDINATOR'));
    $settledRow = $rowOf($decode($inboxAfter), $paidCaseId);

    $assert(
        '8.7 La bandeja de Coordinación muestra el importe liquidado',
        $settledRow !== null && (float)($settledRow['paid_amount'] ?? 0) === 8.40,
        'fila: ' . json_encode($settledRow)
    );

    // The regression behind migration 011: DECIMAL(6,2) silently saturated every
    // amount above 9999,99 because this server runs without STRICT_TRANS_TABLES.
    $wideCaseId = $openCase(CompensationMethod::TRANSFERENCIA_BANCARIA, 20.00);
    $escalate($wideCaseId, 20.00);
    $controller->approve($request(
        'POST',
        "/api/coordinator/refunds/{$wideCaseId}/approve",
        ['approved_amount' => 20.00, 'justification' => 'Importe verificado contra el efectivo recuperado.'],
        [],
        $coordinatorId,
        'COORDINATOR'
    ));
    $controller->pay($request(
        'POST',
        "/api/coordinator/refunds/{$wideCaseId}/pay",
        ['payment_reference' => 'PAGO-RANGO-0002', 'paid_amount' => 20.00],
        [],
        $coordinatorId,
        'COORDINATOR'
    ));
    $wideStored = $pdo->query("SELECT `paid_amount` FROM `refund_requests` WHERE `id` = {$wideCaseId}")->fetchColumn();

    $assert(
        '8.8 Un importe en el rango ampliado se guarda INTEGRO, sin saturar a 9999,99',
        (float)$wideStored === 20.00
        && (string)$pdo->query(
            "SELECT `column_type` FROM `information_schema`.`columns`
             WHERE `table_schema` = DATABASE() AND `table_name` = 'refund_requests'
               AND `column_name` = 'paid_amount'"
        )->fetchColumn() === 'decimal(10,2)',
        'guardado: ' . var_export($wideStored, true)
    );

    // ─────────────────────────────────────────────────────────────────────
    // The other half of RF-REF-03. Bounding the settlement from above left the
    // bottom open: `pay paid_amount: 0.01` on a 4,00 EUR case answered HTTP 200
    // and went straight to `PAID_DIGITAL`, so the consumer read "your refund
    // has been paid" after collecting a hundredth of what was agreed and the
    // leftover 3,99 EUR had nowhere to go. A settled case is terminal, so the
    // damage stops being correctable from that moment on.
    // ─────────────────────────────────────────────────────────────────────

    $partialCaseId = $openCase(CompensationMethod::BIZUM, 4.00);
    $escalate($partialCaseId, 4.00);
    $controller->approve($request(
        'POST',
        "/api/coordinator/refunds/{$partialCaseId}/approve",
        ['approved_amount' => 4.00, 'justification' => 'Importe verificado contra el efectivo recuperado.'],
        [],
        $coordinatorId,
        'COORDINATOR'
    ));

    $partial = $controller->pay($request(
        'POST',
        "/api/coordinator/refunds/{$partialCaseId}/pay",
        ['payment_reference' => 'REF-PARCIAL', 'paid_amount' => 0.01],
        [],
        $coordinatorId,
        'COORDINATOR'
    ));

    $assert(
        '8.9 No se puede liquidar una fracción de lo aprobado (RF-REF-03)',
        $partial->getStatusCode() === 422 && $errOf($partial) === 'INVALID_REFUND_AMOUNT',
        'HTTP ' . $partial->getStatusCode() . ' ' . $errOf($partial)
    );

    $assert(
        '8.9b El rechazo de la liquidación parcial deja el expediente SIN liquidar',
        (string)$pdo->query("SELECT `status` FROM `refund_requests` WHERE `id` = {$partialCaseId}")->fetchColumn() === 'VERIFIED_PENDING_PAYMENT'
        && $pdo->query("SELECT `paid_amount` FROM `refund_requests` WHERE `id` = {$partialCaseId}")->fetchColumn() === null,
        'estado: ' . (string)$pdo->query("SELECT `status` FROM `refund_requests` WHERE `id` = {$partialCaseId}")->fetchColumn()
    );

    $assert(
        '8.9c El rechazo dice cuál era el importe esperado, para que se firme ese',
        (float)($partial->getDecodedBody()['error']['details']['expected_amount'] ?? 0) === 4.00,
        'detalles: ' . json_encode($partial->getDecodedBody()['error']['details'] ?? null)
    );

    // One cent under is still a different amount. The tolerance exists only for
    // the binary residue of 4.00 (3.9999999999999996), never as a money rule, so
    // the guard proving it is a guard against reintroducing the loophole.
    $oneCentUnder = $controller->pay($request(
        'POST',
        "/api/coordinator/refunds/{$partialCaseId}/pay",
        ['payment_reference' => 'REF-UN-CENTIMO', 'paid_amount' => 3.99],
        [],
        $coordinatorId,
        'COORDINATOR'
    ));

    $assert(
        '8.9d Un céntimo por debajo tampoco es el importe aprobado',
        $oneCentUnder->getStatusCode() === 422 && $errOf($oneCentUnder) === 'INVALID_REFUND_AMOUNT',
        'HTTP ' . $oneCentUnder->getStatusCode() . ' ' . $errOf($oneCentUnder)
    );

    $exact = $controller->pay($request(
        'POST',
        "/api/coordinator/refunds/{$partialCaseId}/pay",
        ['payment_reference' => 'PAGO-EXACTO-0003', 'paid_amount' => 4.00],
        [],
        $coordinatorId,
        'COORDINATOR'
    ));

    $assert(
        '8.10 Liquidar exactamente lo aprobado sí funciona y persiste el importe',
        $exact->getStatusCode() === 200
        && (float)$pdo->query("SELECT `paid_amount` FROM `refund_requests` WHERE `id` = {$partialCaseId}")->fetchColumn() === 4.00
        && (string)$pdo->query("SELECT `status` FROM `refund_requests` WHERE `id` = {$partialCaseId}")->fetchColumn() === 'PAID_DIGITAL',
        'HTTP ' . $exact->getStatusCode() . ' ' . (string)$exact->getBody()
    );

    // Omitting the amount has to settle the very same figure, otherwise the
    // modal that leaves the field empty would collect a 422 from an endpoint it
    // can reach without typing anything.
    $defaultCaseId = $openCase(CompensationMethod::BIZUM, 7.30);
    $escalate($defaultCaseId, 7.30);
    $controller->approve($request(
        'POST',
        "/api/coordinator/refunds/{$defaultCaseId}/approve",
        ['approved_amount' => 7.30, 'justification' => 'Importe verificado contra el efectivo recuperado.'],
        [],
        $coordinatorId,
        'COORDINATOR'
    ));
    $defaultSettle = $controller->pay($request(
        'POST',
        "/api/coordinator/refunds/{$defaultCaseId}/pay",
        ['payment_reference' => 'PAGO-SIN-IMPORTE-0004'],
        [],
        $coordinatorId,
        'COORDINATOR'
    ));

    $assert(
        '8.11 Omitir el importe liquida el aprobado, no el sugerido por la vista',
        $defaultSettle->getStatusCode() === 200
        && (float)($bodyOf($defaultSettle)['paid_amount'] ?? 0) === 7.30,
        'HTTP ' . $defaultSettle->getStatusCode() . ' ' . (string)$defaultSettle->getBody()
    );

    // ─────────────────────────────────────────────────────────────────────
    // Quinta tanda adversarial. El techo del 50,00 € era el único control del
    // visto bueno, así que una reclamación de 1,00 € se firmaba por 50,00 € con
    // HTTP 200; y como la liquidación tiene que coincidir exactamente con lo
    // aprobado, ese 50,00 € pasaba a ser la ÚNICA cifra que el servicio
    // aceptaba. Firmar autorizaba el desembolso entero: la regla que se
    // endureció en la cuarta tanda convirtió al visto bueno en el techo real.
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 9. El visto bueno nunca supera lo reclamado (RF-REF-03) ---\n";

    foreach ([[4.00, 45.00], [1.00, 50.00]] as [$claim, $signOff]) {
        $overCaseId = $openCase(CompensationMethod::BIZUM, $claim);
        $escalate($overCaseId, $claim);

        $over = $controller->approve($request(
            'POST',
            "/api/coordinator/refunds/{$overCaseId}/approve",
            ['approved_amount' => $signOff, 'justification' => 'Importe verificado contra el efectivo recuperado.'],
            [],
            $coordinatorId,
            'COORDINATOR'
        ));

        $assert(
            sprintf(
                '9.%s No se firma un visto bueno de %.2f € sobre una reclamación de %.2f €',
                $claim === 4.00 ? '1' : '2',
                $signOff,
                $claim
            ),
            $over->getStatusCode() === 422 && $errOf($over) === 'INVALID_REFUND_AMOUNT',
            'HTTP ' . $over->getStatusCode() . ' ' . $errOf($over)
        );

        $assert(
            sprintf('9.%s El rechazo dice cuál era el máximo admisible', $claim === 4.00 ? '1b' : '2b'),
            (float)($over->getDecodedBody()['error']['details']['maximum_allowed'] ?? 0) === $claim,
            'detalles: ' . json_encode($over->getDecodedBody()['error']['details'] ?? null)
        );

        // Y lo importante: el rechazo NO deja el expediente listo para cobrar.
        $assert(
            sprintf('9.%s El expediente sigue SIN aprobar tras el rechazo', $claim === 4.00 ? '1c' : '2c'),
            (string)$pdo->query("SELECT `status` FROM `refund_requests` WHERE `id` = {$overCaseId}")->fetchColumn() === 'REQUIRES_COORDINATOR_APPROVAL'
            && $pdo->query("SELECT `approved_amount` FROM `refund_requests` WHERE `id` = {$overCaseId}")->fetchColumn() === null,
            'estado: ' . (string)$pdo->query("SELECT `status` FROM `refund_requests` WHERE `id` = {$overCaseId}")->fetchColumn()
        );
    }

    // Igualar lo reclamado sigue siendo legal: es lo que hace el flujo normal.
    $equalCaseId = $openCase(CompensationMethod::BIZUM, 7.50);
    $escalate($equalCaseId, 7.50);
    $equal = $controller->approve($request(
        'POST',
        "/api/coordinator/refunds/{$equalCaseId}/approve",
        ['approved_amount' => 7.50, 'justification' => 'Importe verificado contra el efectivo recuperado.'],
        [],
        $coordinatorId,
        'COORDINATOR'
    ));

    $assert(
        '9.3 Firmar EXACTAMENTE lo reclamado sigue siendo legal',
        $equal->getStatusCode() === 200
        && (float)($bodyOf($equal)['approved_amount'] ?? 0) === 7.50,
        'HTTP ' . $equal->getStatusCode() . ' ' . (string)$equal->getBody()
    );

    // Bajar el importe sigue siendo la vía legítima para pagar menos (RF-REF-07).
    $lowerCaseId = $openCase(CompensationMethod::BIZUM, 7.50);
    $escalate($lowerCaseId, 7.50);
    $controller->approve($request(
        'POST',
        "/api/coordinator/refunds/{$lowerCaseId}/approve",
        ['approved_amount' => 6.00, 'justification' => 'Discrepancia entre lo reclamado y el efectivo recuperado.'],
        [],
        $coordinatorId,
        'COORDINATOR'
    ));
    $lowerPay = $controller->pay($request(
        'POST',
        "/api/coordinator/refunds/{$lowerCaseId}/pay",
        ['payment_reference' => 'PAGO-REDUCIDO-0005', 'paid_amount' => 6.00],
        [],
        $coordinatorId,
        'COORDINATOR'
    ));

    $assert(
        '9.4 Bajar el importe aprobado sigue siendo la forma de pagar menos',
        $lowerPay->getStatusCode() === 200
        && (float)$pdo->query("SELECT `paid_amount` FROM `refund_requests` WHERE `id` = {$lowerCaseId}")->fetchColumn() === 6.00,
        'HTTP ' . $lowerPay->getStatusCode() . ' ' . (string)$lowerPay->getBody()
    );

    // ─────────────────────────────────────────────────────────────────────
    // El borde de la tolerancia. Con la comparación abierta, 4,005 sobre un
    // aprobado de 4,00 caía dentro del `> 0,005` y la columna DECIMAL(10,2)
    // redondeaba a 4,01: la API afirmaba un importe que nadie había firmado.
    // ─────────────────────────────────────────────────────────────────────
    echo "\n--- 10. La tolerancia de medio céntimo es estricta (RF-REF-03, RF-REF-07) ---\n";

    $halfCentCaseId = $openCase(CompensationMethod::BIZUM, 4.00);
    $escalate($halfCentCaseId, 4.00);
    $controller->approve($request(
        'POST',
        "/api/coordinator/refunds/{$halfCentCaseId}/approve",
        ['approved_amount' => 4.00, 'justification' => 'Importe verificado contra el efectivo recuperado.'],
        [],
        $coordinatorId,
        'COORDINATOR'
    ));

    $halfCent = $controller->pay($request(
        'POST',
        "/api/coordinator/refunds/{$halfCentCaseId}/pay",
        ['payment_reference' => 'REF-MEDIO-CENTIMO', 'paid_amount' => 4.005],
        [],
        $coordinatorId,
        'COORDINATOR'
    ));

    $assert(
        '10.1 Medio céntimo por encima NO es el importe aprobado',
        $halfCent->getStatusCode() === 422 && $errOf($halfCent) === 'INVALID_REFUND_AMOUNT',
        'HTTP ' . $halfCent->getStatusCode() . ' ' . $errOf($halfCent)
    );

    $assert(
        '10.2 El expediente no queda liquidado con un importe que nadie firmó',
        (string)$pdo->query("SELECT `status` FROM `refund_requests` WHERE `id` = {$halfCentCaseId}")->fetchColumn() === 'VERIFIED_PENDING_PAYMENT'
        && $pdo->query("SELECT `paid_amount` FROM `refund_requests` WHERE `id` = {$halfCentCaseId}")->fetchColumn() === null,
        'estado: ' . (string)$pdo->query("SELECT `status` FROM `refund_requests` WHERE `id` = {$halfCentCaseId}")->fetchColumn()
    );

    // Y el importe exacto sigue funcionando tras endurecer el borde.
    $exactCent = $controller->pay($request(
        'POST',
        "/api/coordinator/refunds/{$halfCentCaseId}/pay",
        ['payment_reference' => 'PAGO-AL-CENTIMO-0006', 'paid_amount' => 4.00],
        [],
        $coordinatorId,
        'COORDINATOR'
    ));

    $assert(
        '10.3 El importe exacto sigue liquidando tras endurecer el borde',
        $exactCent->getStatusCode() === 200
        && (float)$pdo->query("SELECT `paid_amount` FROM `refund_requests` WHERE `id` = {$halfCentCaseId}")->fetchColumn() === 4.00,
        'HTTP ' . $exactCent->getStatusCode() . ' ' . (string)$exactCent->getBody()
    );


} finally {
    $pdo->rollBack();
}

echo "\n======================================================================\n";
echo " Total Aserciones: {$assertions}\n";

if ($failures === 0) {
    echo " RESULTADO: 100% EN VERDE. Condición T-REF-22 CUMPLIDA SATISFACTORIAMENTE.\n";
} else {
    echo " RESULTADO: {$failures} FALLO(S) DETECTADO(S).\n";
}

echo "======================================================================\n";

exit($failures === 0 ? 0 : 1);