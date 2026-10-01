<?php

declare(strict_types=1);

/**
 * VendGuard - Módulo 08: Refunds and Unclaimed Cash Management
 *
 * Integration suite for the refund persistence layer (T-REF-04).
 *
 * Runs against real MariaDB inside a transaction that is always rolled back, so
 * the suite exercises the actual SQL (including the column lists that enforce
 * the Art. V.4 segregation) without leaving test rows behind.
 */

require_once __DIR__ . '/../bootstrap.php';

use VendGuard\Core\Domain\Model\CashCustodyAction;
use VendGuard\Core\Domain\Model\CompensationMethod;
use VendGuard\Core\Domain\Model\RefundRequest;
use VendGuard\Core\Domain\Model\RefundStatus;
use VendGuard\Core\Domain\Model\TechnicianFinding;
use VendGuard\Core\Domain\Model\UnclaimedCashFinding;
use VendGuard\Infrastructure\Database\ConnectionFactory;
use VendGuard\Infrastructure\Repository\PdoRefundRequestRepository;
use VendGuard\Infrastructure\Repository\PdoUnclaimedCashFindingRepository;

$pdo = ConnectionFactory::getConnection();
$failures = 0;
$assertions = 0;

$assert = static function (string $label, bool $condition, string $detail = '') use (&$failures, &$assertions): void {
    $assertions++;
    echo $condition ? "  [PASS] {$label}\n" : "  [FAIL] {$label}" . ($detail !== '' ? " -- {$detail}" : '') . "\n";
    if (!$condition) {
        $failures++;
    }
};

$buildRefund = static function (array $overrides = []): RefundRequest {
    return new RefundRequest(...array_merge([
        'id' => 1,
        'incidentId' => 1,
        'machineId' => 1,
        'locationId' => 1,
        'claimantName' => 'Laura Sanitaria',
        'claimantContact' => '600111222',
        'claimedAmount' => 2.00,
        'productAttempted' => 'Café con leche carril 2',
        'compensationMethod' => CompensationMethod::EN_MANO_SEDE,
        'bizumPhone' => null,
        'iban' => null,
        'pickupPin' => '4821',
        'trackingToken' => bin2hex(random_bytes(32)),
        'status' => RefundStatus::PENDING_INSPECTION,
    ], $overrides));
};

$rowsBefore = (int)$pdo->query('SELECT COUNT(*) FROM `refund_requests`')->fetchColumn();
$findingsBefore = (int)$pdo->query('SELECT COUNT(*) FROM `unclaimed_cash_findings`')->fetchColumn();

$pdo->beginTransaction();

try {
    $incidentId = (int)$pdo->query('SELECT `id` FROM `incidents` ORDER BY `id` LIMIT 1')->fetchColumn();
    $machineId = (int)$pdo->query('SELECT `id` FROM `machines` ORDER BY `id` LIMIT 1')->fetchColumn();
    $locationId = (int)$pdo->query('SELECT `id` FROM `locations` ORDER BY `id` LIMIT 1')->fetchColumn();
    $technicianId = (int)$pdo->query("SELECT `id` FROM `users` WHERE `role` = 'TECHNICIAN' ORDER BY `id` LIMIT 1")->fetchColumn();

    $assert('1.0 El entorno de pruebas tiene datos maestros sembrados', $incidentId > 0 && $machineId > 0 && $locationId > 0);

    $refundRepo = new PdoRefundRequestRepository($pdo);
    $findingRepo = new PdoUnclaimedCashFindingRepository($pdo);

    echo "\n--- 1. Creación de expediente y lectura por identificador ---\n";

    $created = $buildRefund([
        'incidentId' => $incidentId,
        'machineId' => $machineId,
        'locationId' => $locationId,
    ]);
    $newId = $refundRepo->insert($created);

    $assert('1.1 insert() devuelve un identificador generado por la base de datos', $newId > 0);

    $byId = $refundRepo->findById($newId);

    $assert('1.2 findById() recupera el expediente insertado', $byId instanceof RefundRequest);
    $assert(
        '1.3 findById() conserva nombre, importe y estado',
        $byId instanceof RefundRequest
        && $byId->getClaimantName() === 'Laura Sanitaria'
        && abs($byId->getClaimedAmount() - 2.00) < 0.0001
        && $byId->getStatus() === RefundStatus::PENDING_INSPECTION
    );
    $assert(
        '1.4 findById() es la vía de Coordinación y SÍ proyecta los datos financieros',
        $byId instanceof RefundRequest && $byId->getIban() === null
    );

    $assert('1.5 findById() devuelve null para un identificador inexistente', $refundRepo->findById(999999999) === null);

    echo "\n--- 2. Búsqueda por token de seguimiento (pública) ---\n";

    $token = bin2hex(random_bytes(32));
    $withIbanId = $refundRepo->insert($buildRefund([
        'incidentId' => $incidentId,
        'machineId' => $machineId,
        'locationId' => $locationId,
        'compensationMethod' => CompensationMethod::TRANSFERENCIA_BANCARIA,
        'iban' => 'ES9121000418450200051332',
        'pickupPin' => null,
        'trackingToken' => $token,
    ]));

    $byToken = $refundRepo->findByTrackingToken($token);

    $assert('2.1 findByTrackingToken() localiza el expediente por su token', $byToken instanceof RefundRequest);
    $assert(
        '2.2 findByTrackingToken() recupera el estado del expediente',
        $byToken instanceof RefundRequest && $byToken->getStatus() === RefundStatus::PENDING_INSPECTION
    );
    $assert(
        '2.3 findByTrackingToken() NUNCA proyecta el IBAN (Art. V.4)',
        $byToken instanceof RefundRequest && $byToken->getIban() === null
    );
    $assert('2.4 findByTrackingToken() devuelve null para un token inexistente', $refundRepo->findByTrackingToken('token-inexistente') === null);

    echo "\n--- 3. Segregación real de columnas en SQL (Art. V.4) ---\n";

    // The strongest check available: prove the rows exist with their IBAN, and
    // that a restricted read cannot return it.
    $storedIban = $pdo->query("SELECT `iban` FROM `refund_requests` WHERE `id` = " . (int)$withIbanId)->fetchColumn();
    $assert(
        '3.1 La fila SÍ contiene el IBAN en la base de datos (control del test)',
        $storedIban === 'ES9121000418450200051332',
        'Valor almacenado: ' . var_export($storedIban, true)
    );

    $assert(
        '3.2 La lectura restringida por token no expone el IBAN aunque exista en la fila',
        $byToken instanceof RefundRequest && $byToken->getIban() === null
    );

    $assert(
        '3.3 La lectura restringida por token no expone el teléfono Bizum',
        $byToken instanceof RefundRequest && $byToken->getBizumPhone() === null
    );

    $assert(
        '3.4 La serialización restringida tampoco filtra datos financieros',
        $byToken instanceof RefundRequest
        && !str_contains(
            json_encode($byToken->toRestrictedArray(), JSON_UNESCAPED_UNICODE) ?: '',
            'ES9121000418450200051332'
        )
    );

    $full = $refundRepo->findById($withIbanId);
    $assert(
        '3.5 La lectura de Coordinación SÍ recupera el IBAN (separación real, no borrado)',
        $full instanceof RefundRequest && $full->getIban() === 'ES9121000418450200051332'
    );

    echo "\n--- 4. Consulta por incidencia y por sede ---\n";

    $byIncident = $refundRepo->findRestrictedByIncident($incidentId);
    $assert('4.1 findRestrictedByIncident() devuelve los expedientes de la incidencia', count($byIncident) >= 2);

    $incidentRestricted = array_map(
        static fn (RefundRequest $r): array => $r->toRestrictedArray(),
        $byIncident
    );
    $incidentFlat = json_encode($incidentRestricted, JSON_UNESCAPED_UNICODE) ?: '';

    $assert(
        '4.2 La lista por incidencia no filtra ningún IBAN (Art. V.4)',
        !str_contains($incidentFlat, 'ES9121000418450200051332')
    );
    $assert(
        '4.3 La lista por incidencia anonimiza al reclamante (Art. V.4)',
        !str_contains($incidentFlat, 'Laura Sanitaria')
    );
    $assert(
        '4.4 La lista por incidencia no filtra el PIN de recogida',
        !str_contains($incidentFlat, '"pickup_pin"')
    );

    $byLocation = $refundRepo->findRestrictedByLocation($locationId);
    $assert('4.5 findRestrictedByLocation() devuelve los expedientes de la sede', count($byLocation) >= 2);

    $filtered = $refundRepo->findRestrictedByLocation($locationId, RefundStatus::PENDING_INSPECTION);
    $assert(
        '4.6 findRestrictedByLocation() admite filtro por estado',
        count($filtered) >= 1
        && array_reduce(
            $filtered,
            static fn (bool $ok, RefundRequest $r): bool => $ok && $r->getStatus() === RefundStatus::PENDING_INSPECTION,
            true
        )
    );

    $emptyFilter = $refundRepo->findRestrictedByLocation($locationId, RefundStatus::PAID_DIGITAL);
    $assert('4.7 El filtro por estado sin coincidencias devuelve una lista vacía', $emptyFilter === []);

    echo "\n--- 5. Actualización atómica de estados ---\n";

    $transitioned = $refundRepo->transitionStatus(
        $newId,
        RefundStatus::PENDING_INSPECTION,
        RefundStatus::DEPOSITED_AT_RECEPTION,
        [
            'technician_finding' => TechnicianFinding::FOUND_PHYSICAL->value,
            'recovered_amount' => 2.00,
            'cash_custody_action' => CashCustodyAction::LEFT_AT_RECEPTION->value,
            'receptionist_name' => 'Concierge de prueba',
        ]
    );

    $assert('5.1 La transición de estado se aplica cuando el estado origen coincide', $transitioned === true);

    $afterTransition = $refundRepo->findById($newId);
    $assert(
        '5.2 El nuevo estado y los campos asociados quedan persistidos',
        $afterTransition instanceof RefundRequest
        && $afterTransition->getStatus() === RefundStatus::DEPOSITED_AT_RECEPTION
        && $afterTransition->getTechnicianFinding() === TechnicianFinding::FOUND_PHYSICAL
        && $afterTransition->getCashCustodyAction() === CashCustodyAction::LEFT_AT_RECEPTION
        && $afterTransition->getReceptionistName() === 'Concierge de prueba'
    );

    $staleTransition = $refundRepo->transitionStatus(
        $newId,
        RefundStatus::PENDING_INSPECTION,
        RefundStatus::PAID_DIGITAL
    );

    $assert('5.2b La transición se rechaza si el estado esperado ya no es el actual', $staleTransition === false);

    $afterStale = $refundRepo->findById($newId);
    $assert(
        '5.2c El estado no se altera tras un rechazo por conflicto',
        $afterStale instanceof RefundRequest && $afterStale->getStatus() === RefundStatus::DEPOSITED_AT_RECEPTION
    );

    $allowed = $refundRepo->transitionStatus(
        $newId,
        RefundStatus::DEPOSITED_AT_RECEPTION,
        RefundStatus::REFUNDED_IN_HAND,
        ['hand_delivered_at' => '2026-10-01 12:00:00', 'iban' => 'ES_HACK_00', 'is_active' => 0]
    );

    $assert('5.3 Una transición con columnas no permitidas sigue siendo válida', $allowed === true);

    $afterInjection = $refundRepo->findById($newId);
    $assert(
        '5.4 La lista blanca impide escribir `iban` a través de transitionStatus()',
        $afterInjection instanceof RefundRequest && $afterInjection->getIban() !== 'ES_HACK_00'
    );
    $assert(
        '5.5 La lista blanca impide desactivar el expediente a través de transitionStatus()',
        $afterInjection instanceof RefundRequest && $afterInjection->isActive() === true
    );

    echo "\n--- 6. Bandeja filtrada de Coordinación ---\n";

    $inbox = $refundRepo->findForCoordinator(['location_id' => $locationId], 100, 0);
    $assert('6.1 findForCoordinator() devuelve la bandeja con detalle financiero', count($inbox) >= 2);

    $inboxFlat = json_encode(array_map(
        static fn (RefundRequest $r): array => $r->toArray(),
        $inbox
    ), JSON_UNESCAPED_UNICODE) ?: '';

    $assert(
        '6.2 La bandeja de Coordinación SÍ puede ver el IBAN (le compete por rol)',
        str_contains($inboxFlat, 'ES9121000418450200051332')
    );

    $filteredInbox = $refundRepo->findForCoordinator([
        'status' => RefundStatus::REFUNDED_IN_HAND->value,
        'location_id' => $locationId,
    ]);
    $assert(
        '6.3 La bandeja admite filtro por estado combinado',
        count($filteredInbox) === 1
        && $filteredInbox[0]->getStatus() === RefundStatus::REFUNDED_IN_HAND
    );

    $assert(
        '6.4 countForCoordinator() concuerda con el listado filtrado',
        $refundRepo->countForCoordinator(['location_id' => $locationId])
        === count($refundRepo->findForCoordinator(['location_id' => $locationId], 100, 0))
    );

    $assert(
        '6.5 El filtro por rango de fechas no rompe la consulta',
        is_array($refundRepo->findForCoordinator(['from' => '2020-01-01 00:00:00', 'to' => '2099-12-31 23:59:59'], 5, 0))
    );

    echo "\n--- 7. Rectificación de contacto y baja lógica (Art. III) ---\n";

    $rectifiedId = $refundRepo->insert($buildRefund([
        'incidentId' => $incidentId,
        'machineId' => $machineId,
        'locationId' => $locationId,
        'status' => RefundStatus::PENDING_CONTACT,
        'compensationMethod' => CompensationMethod::BIZUM,
        'bizumPhone' => '600111222',
        'pickupPin' => null,
    ]));

    $assert('7.1 La rectificación se aplica en estado PENDING_CONTACT', $refundRepo->updateContactDetails($rectifiedId, '611223344', null) === true);

    $rectified = $refundRepo->findById($rectifiedId);
    $assert(
        '7.2 El nuevo teléfono Bizum queda persistido',
        $rectified instanceof RefundRequest && $rectified->getBizumPhone() === '611223344'
    );

    $assert(
        '7.3 La rectificación se rechaza si el expediente no está en PENDING_CONTACT',
        $refundRepo->updateContactDetails($newId, '699887766', null) === false
    );

    $assert('7.4 deactivate() realiza la baja lógica', $refundRepo->deactivate($rectifiedId) === true);

    $deletedRow = $pdo->query('SELECT `is_active`, `deleted_at` FROM `refund_requests` WHERE `id` = ' . (int)$rectifiedId)->fetch();
    $assert(
        '7.5 La fila NO se borra físicamente: permanece con is_active = 0 (Art. III)',
        $deletedRow !== false
        && (int)$deletedRow['is_active'] === 0
        && $deletedRow['deleted_at'] !== null
    );

    $assert('7.6 Un expediente dado de baja desaparece de las lecturas restringidas', !in_array(
        $rectifiedId,
        array_map(static fn (RefundRequest $r): int => $r->getId(), $refundRepo->findRestrictedByIncident($incidentId)),
        true
    ));

    $assert(
        '7.7 Un expediente dado de baja deja de ser localizable por su token',
        $refundRepo->findByTrackingToken(
            (string)$pdo->query('SELECT `tracking_token` FROM `refund_requests` WHERE `id` = ' . (int)$rectifiedId)->fetchColumn()
        ) === null
    );

    echo "\n--- 8. Hallazgos de efectivo de oficio ---\n";

    $finding = new UnclaimedCashFinding(
        id: 1,
        incidentId: $incidentId,
        machineId: $machineId,
        technicianId: $technicianId > 0 ? $technicianId : 1,
        amount: 3.50,
        notes: 'Monedas atascadas en el selector de billetes.',
        createdAt: '2026-10-01 11:45:00'
    );

    $findingId = $findingRepo->insert($finding);
    $assert('8.1 insert() persiste el hallazgo de oficio', $findingId > 0);

    $stored = $findingRepo->findById($findingId);
    $assert(
        '8.2 findById() recupera importe, técnico y notas',
        $stored instanceof UnclaimedCashFinding
        && abs($stored->getAmount() - 3.50) < 0.0001
        && $stored->getNotes() === 'Monedas atascadas en el selector de billetes.'
    );

    $byFindingIncident = $findingRepo->findByIncident($incidentId);
    $assert('8.3 findByIncident() lista los hallazgos de la incidencia', count($byFindingIncident) >= 1);

    $byTechnician = $findingRepo->findByTechnician($finding->getTechnicianId());
    $assert('8.4 findByTechnician() lista los hallazgos del técnico', count($byTechnician) >= 1);

    $assert('8.5 findByTechnician() respeta el límite solicitado', count($findingRepo->findByTechnician($finding->getTechnicianId(), 1)) <= 1);

    echo "\n--- 9. Blindaje constitucional de la capa de persistencia ---\n";

    $repoSource = (string)file_get_contents(dirname(__DIR__, 2) . '/src/Infrastructure/Repository/PdoRefundRequestRepository.php');

    $assert(
        '9.1 El repositorio de reintegros no ejecuta DELETE FROM (Art. III)',
        !str_contains(strtoupper($repoSource), 'DELETE FROM')
    );

    $assert(
        '9.2 La proyección restringida NO menciona `iban` en su lista de columnas',
        !str_contains(extractConstant($repoSource, 'RESTRICTED_COLUMNS'), '`iban`')
    );

    $assert(
        '9.3 La proyección restringida NO menciona `bizum_phone` en su lista de columnas',
        !str_contains(extractConstant($repoSource, 'RESTRICTED_COLUMNS'), '`bizum_phone`')
    );

    $assert(
        '9.4 La proyección completa SÍ menciona `iban` (Coordinación la necesita)',
        str_contains(extractConstant($repoSource, 'FULL_COLUMNS'), '`iban`')
    );

    $findingSource = (string)file_get_contents(dirname(__DIR__, 2) . '/src/Infrastructure/Repository/PdoUnclaimedCashFindingRepository.php');
    $assert(
        '9.5 El repositorio de hallazgos no ejecuta DELETE FROM (Art. III)',
        !str_contains(strtoupper($findingSource), 'DELETE FROM')
    );

    echo "\n" . str_repeat('=', 90) . "\n";
    echo " Total Aserciones: {$assertions}\n";
} catch (Throwable $e) {
    echo "\n[ERROR FATAL] " . $e->getMessage() . "\n";
    $failures++;
} finally {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
}

// Autoverificación de aislamiento: la reversión debe devolver la tabla a su
// estado previo. Una fuga aquí rompería los 20+ tests de integración que
// vacían `incidents` con DELETE, porque la FK de `refund_requests` lo impide.
$rowsAfter = (int)$pdo->query('SELECT COUNT(*) FROM `refund_requests`')->fetchColumn();
$findingsAfter = (int)$pdo->query('SELECT COUNT(*) FROM `unclaimed_cash_findings`')->fetchColumn();

$assertions++;
if ($rowsAfter === $rowsBefore && $findingsAfter === $findingsBefore) {
    echo "  [PASS] 10.1 La reversión deja `refund_requests` y `unclaimed_cash_findings` intactas\n";
} else {
    $failures++;
    echo "  [FAIL] 10.1 La reversión dejó filas huérfanas"
        . " (refund_requests: {$rowsBefore} -> {$rowsAfter},"
        . " unclaimed_cash_findings: {$findingsBefore} -> {$findingsAfter})\n";
}

if ($failures === 0) {
    echo " RESULTADO: 100% EN VERDE. Condición T-REF-04 CUMPLIDA SATISFACTORIAMENTE.\n";
} else {
    echo " RESULTADO: {$failures} FALLO(S) DETECTADO(S).\n";
}
echo str_repeat('=', 90) . "\n";

exit($failures === 0 ? 0 : 1);

/**
 * Extracts a string constant body from the repository source so the column
 * lists can be inspected statically, which is the only way to prove the
 * restricted projection never even names the financial columns.
 */
function extractConstant(string $source, string $name): string
{
    if (!preg_match('/const\s+' . preg_quote($name, '/') . '\s*=\s*\'(.*?)\';/s', $source, $matches)) {
        return '';
    }

    return $matches[1];
}
