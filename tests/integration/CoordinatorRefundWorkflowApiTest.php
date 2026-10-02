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

    $openCase = static function (CompensationMethod $method, float $amount) use ($service, $incidentId, $machineId, $locationId): int {
        $case = $service->createCase(new CreateRefundRequestDTO(
            incidentId: $incidentId,
            machineId: $machineId,
            locationId: $locationId,
            claimantName: 'Laura Sanitaria',
            claimantContact: '600111222',
            claimedAmount: $amount,
            compensationMethod: $method,
            productAttempted: 'Café con leche carril 2',
            bizumPhone: $method->requiresBizumPhone() ? '600111222' : null,
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
        ($rowOf($inboxBody, $bizumCaseId)['bizum_phone'] ?? null) === '600111222'
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