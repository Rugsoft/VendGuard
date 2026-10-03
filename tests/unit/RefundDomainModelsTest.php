<?php

declare(strict_types=1);

/**
 * VendGuard - Módulo 08: Refunds and Unclaimed Cash Management
 *
 * Unit suite for the domain model of T-REF-02 (enums and entities).
 *
 * Covers the invariants named in the "Hecho cuando" criterion: name
 * anonymization, constant-time pickup PIN verification, the special
 * supervision rule, plus the blocking antifraud limits of RF-REF-03 and the
 * forced custody rule of RF-REF-05.
 */

$baseDir = dirname(__DIR__, 2);
require_once $baseDir . '/tests/bootstrap.php';

use VendGuard\Core\Domain\Model\CashCustodyAction;
use VendGuard\Core\Domain\Model\CompensationMethod;
use VendGuard\Core\Domain\Model\RefundRequest;
use VendGuard\Core\Domain\Model\RefundStatus;
use VendGuard\Core\Domain\Model\TechnicianFinding;
use VendGuard\Core\Domain\Model\UnclaimedCashFinding;

$assertions = 0;
$failures = 0;

$assert = function (string $label, bool $condition, string $detail = '') use (&$assertions, &$failures): void {
    $assertions++;
    if ($condition) {
        echo "  [PASS] {$label}\n";
    } else {
        $failures++;
        echo "  [FAIL] {$label}" . ($detail !== '' ? " -- {$detail}" : '') . "\n";
    }
};

$expectInvalid = function (string $label, callable $factory) use ($assert): void {
    try {
        $factory();
        $assert($label, false, 'La construcción debería haber sido rechazada');
    } catch (InvalidArgumentException $e) {
        $assert($label, true);
    }
};

$buildRefund = function (array $overrides = []): RefundRequest {
    return new RefundRequest(...array_merge([
        'id' => 1,
        'incidentId' => 10,
        'machineId' => 20,
        'locationId' => 30,
        'claimantName' => 'Laura Sanitaria',
        'claimantContact' => '600111222',
        'claimedAmount' => 2.00,
        'productAttempted' => 'Café con leche carril 2',
        'compensationMethod' => CompensationMethod::EN_MANO_SEDE,
        'bizumPhone' => null,
        'iban' => null,
        'pickupPin' => '4821',
        'trackingToken' => 'a1b2c3d4e5f6789012345678abcdef0123456789abcdef0123456789abcdef01',
        'status' => RefundStatus::PENDING_INSPECTION,
    ], $overrides));
};

echo "\n--- 1. Enums del módulo de reintegros ---\n";

$assert(
    '1.1 CompensationMethod expone los tres canales especificados',
    CompensationMethod::EN_MANO_SEDE->value === 'EN_MANO_SEDE'
    && CompensationMethod::BIZUM->value === 'BIZUM'
    && CompensationMethod::TRANSFERENCIA_BANCARIA->value === 'TRANSFERENCIA_BANCARIA'
);

$assert(
    '1.2 RefundStatus expone los ocho estados especificados',
    count(RefundStatus::cases()) === 8,
    'Casos detectados: ' . count(RefundStatus::cases())
);

$assert(
    '1.3 TechnicianFinding expone los tres dictámenes especificados',
    count(TechnicianFinding::cases()) === 3
);

$assert(
    '1.4 CashCustodyAction expone las dos decisiones de custodia',
    count(CashCustodyAction::cases()) === 2
);

$assert(
    '1.5 Sólo EN_MANO_SEDE es un canal presencial',
    !CompensationMethod::EN_MANO_SEDE->isDigital()
    && CompensationMethod::BIZUM->isDigital()
    && CompensationMethod::TRANSFERENCIA_BANCARIA->isDigital()
);

$assert(
    '1.6 BIZUM exige teléfono y la transferencia exige IBAN',
    CompensationMethod::BIZUM->requiresBizumPhone()
    && CompensationMethod::TRANSFERENCIA_BANCARIA->requiresIban()
    && !CompensationMethod::EN_MANO_SEDE->requiresIban()
);

$assert(
    '1.7 Sólo DEPOSITED_AT_RECEPTION espera la recogida con PIN',
    RefundStatus::DEPOSITED_AT_RECEPTION->awaitsPickup()
    && !RefundStatus::PAID_DIGITAL->awaitsPickup()
);

$assert(
    '1.8 Sólo PENDING_CONTACT admite rectificación de contacto',
    RefundStatus::PENDING_CONTACT->allowsContactRectification()
    && !RefundStatus::PAID_DIGITAL->allowsContactRectification()
);

$assert(
    '1.9 Los estados liquidados y el desestimado son terminales',
    RefundStatus::PAID_DIGITAL->isTerminal()
    && RefundStatus::REFUNDED_IN_HAND->isTerminal()
    && RefundStatus::REJECTED->isTerminal()
    && !RefundStatus::PENDING_INSPECTION->isTerminal()
);

$assert(
    '1.10 Cada estado expone su etiqueta en castellano',
    RefundStatus::DEPOSITED_AT_RECEPTION->label() === 'Efectivo depositado en conserjería'
    && RefundStatus::REFUNDED_IN_HAND->label() === 'Reembolsado en mano'
);

$assert(
    '1.11 El dictamen sin verificar exige escalado',
    TechnicianFinding::UNVERIFIED_NO_CASH->requiresEscalation()
    && !TechnicianFinding::CONFIRMED_NO_CASH->requiresEscalation()
);

$assert(
    '1.12 Sólo FOUND_PHYSICAL implica efectivo recuperado',
    TechnicianFinding::FOUND_PHYSICAL->recoveredCash()
    && !TechnicianFinding::UNVERIFIED_NO_CASH->recoveredCash()
);

echo "\n--- 2. Límites cuantitativos antifraude (RF-REF-03) ---\n";

$assert(
    '2.1 Se acepta un importe dentro del rango permitido',
    $buildRefund(['claimedAmount' => 2.00])->getClaimedAmount() === 2.00
);

$assert(
    '2.2 Se acepta el importe máximo exacto de 50,00 €',
    $buildRefund(['claimedAmount' => 50.00])->getClaimedAmount() === 50.00
);

$expectInvalid(
    '2.3 Se rechaza un importe de 0,00 €',
    fn () => $buildRefund(['claimedAmount' => 0.0])
);

$expectInvalid(
    '2.4 Se rechaza un importe negativo',
    fn () => $buildRefund(['claimedAmount' => -1.00])
);

$expectInvalid(
    '2.5 Se rechaza un importe superior al tope de 50,00 €',
    fn () => $buildRefund(['claimedAmount' => 50.01])
);

$expectInvalid(
    '2.6 Se rechaza un importe no finito',
    fn () => $buildRefund(['claimedAmount' => INF])
);

echo "\n--- 3. Regla de custodia forzada (RF-REF-05) ---\n";

$assert(
    '3.1 Se permite dejar en conserjería un importe presencial de 10,00 € exactos',
    $buildRefund([
        'claimedAmount' => 10.00,
        'cashCustodyAction' => CashCustodyAction::LEFT_AT_RECEPTION,
    ])->getCashCustodyAction() === CashCustodyAction::LEFT_AT_RECEPTION
);

$expectInvalid(
    '3.2 Se impide dejar en conserjería más de 10,00 €',
    fn () => $buildRefund([
        'claimedAmount' => 10.01,
        'cashCustodyAction' => CashCustodyAction::LEFT_AT_RECEPTION,
    ])
);

$expectInvalid(
    '3.3 Se impide dejar en conserjería un reembolso por Bizum',
    fn () => $buildRefund([
        'claimedAmount' => 2.00,
        'compensationMethod' => CompensationMethod::BIZUM,
        'bizumPhone' => '600111222',
        'cashCustodyAction' => CashCustodyAction::LEFT_AT_RECEPTION,
    ])
);

$assert(
    '3.4 Se permite custodiar en caja central cualquier cantidad',
    $buildRefund([
        'claimedAmount' => 40.00,
        'cashCustodyAction' => CashCustodyAction::HELD_FOR_CENTRAL,
    ])->getCashCustodyAction() === CashCustodyAction::HELD_FOR_CENTRAL
);

echo "\n--- 4. Regla de supervisión especial (RF-REF-03) ---\n";

$assert(
    '4.1 Un importe igual al umbral NO exige supervisión especial',
    $buildRefund(['claimedAmount' => 10.00])->requiresSpecialSupervision() === false
);

$assert(
    '4.2 Un importe superior al umbral SÍ exige supervisión especial',
    $buildRefund(['claimedAmount' => 10.01])->requiresSpecialSupervision() === true
);

$assert(
    '4.3 Una discrepancia dentro del 20% tolerado NO exige supervisión',
    $buildRefund([
        'claimedAmount' => 10.00,
        'recoveredAmount' => 8.50,
    ])->hasMaterialDiscrepancy() === false
);

$assert(
    '4.4 Una discrepancia del 20% exacto se considera tolerada',
    $buildRefund([
        'claimedAmount' => 10.00,
        'recoveredAmount' => 8.00,
    ])->hasMaterialDiscrepancy() === false
);

$assert(
    '4.5 Una discrepancia superior al 20% exige supervisión especial',
    $buildRefund([
        'claimedAmount' => 10.00,
        'recoveredAmount' => 7.99,
    ])->requiresSpecialSupervision() === true
);

$assert(
    '4.6 El dictamen sin verificar exige supervisión aunque el importe sea bajo',
    $buildRefund([
        'claimedAmount' => 2.00,
        'technicianFinding' => TechnicianFinding::UNVERIFIED_NO_CASH,
    ])->requiresSpecialSupervision() === true
);

$assert(
    '4.7 Sin importe recuperado no hay discrepancia calculable',
    $buildRefund(['claimedAmount' => 2.00])->discrepancyRatio() === null
);

$assert(
    '4.8 El ratio de discrepancia se calcula sobre el importe reclamado',
    abs($buildRefund([
        'claimedAmount' => 10.00,
        'recoveredAmount' => 4.00,
    ])->discrepancyRatio() - 0.60) < 0.0001
);

echo "\n--- 5. Anonimización del nombre del reclamante (Art. V.4) ---\n";

$assert(
    '5.1 "Laura Sanitaria" se anonimiza como "Laura S."',
    $buildRefund(['claimantName' => 'Laura Sanitaria'])->getAnonymizedClaimantName() === 'Laura S.'
);

$assert(
    '5.2 "Marc Rius Prohens" se anonimiza como "Marc R."',
    $buildRefund(['claimantName' => 'Marc Rius Prohens'])->getAnonymizedClaimantName() === 'Marc R.'
);

$assert(
    '5.3 Un nombre con espacios sobrantes se normaliza',
    $buildRefund(['claimantName' => '  Laura   Sanitaria  '])->getAnonymizedClaimantName() === 'Laura S.'
);

$assert(
    '5.4 Un nombre compuesto por un único token se conserva intacto',
    $buildRefund(['claimantName' => 'Laura'])->getAnonymizedClaimantName() === 'Laura'
);

$assert(
    '5.5 La anonimización nunca filtra los apellidos completos',
    !str_contains(
        $buildRefund(['claimantName' => 'Laura Sanitaria Ruiz'])->getAnonymizedClaimantName(),
        'Ruiz'
    )
    && !str_contains(
        $buildRefund(['claimantName' => 'Laura Sanitaria'])->getAnonymizedClaimantName(),
        'Sanitaria'
    )
);

$expectInvalid(
    '5.6 Se rechaza un expediente sin nombre de reclamante',
    fn () => $buildRefund(['claimantName' => '   '])
);

echo "\n--- 6. Verificación segura del PIN de recogida (RF-REF-06) ---\n";

$assert(
    '6.1 El PIN correcto se verifica',
    $buildRefund(['pickupPin' => '4821'])->verifyPickupPin('4821') === true
);

$assert(
    '6.2 El PIN incorrecto se rechaza',
    $buildRefund(['pickupPin' => '4821'])->verifyPickupPin('1234') === false
);

$assert(
    '6.3 El PIN se compara tolerando espacios del teclado',
    $buildRefund(['pickupPin' => '4821'])->verifyPickupPin('  4821 ') === true
);

$assert(
    '6.4 Un PIN con longitud distinta se rechaza sin excepción',
    $buildRefund(['pickupPin' => '4821'])->verifyPickupPin('482') === false
    && $buildRefund(['pickupPin' => '4821'])->verifyPickupPin('48211') === false
);

$assert(
    '6.5 Un expediente sin PIN nunca acepta la recogida',
    $buildRefund(['pickupPin' => null])->verifyPickupPin('4821') === false
    && $buildRefund(['pickupPin' => null])->verifyPickupPin('') === false
);

$expectInvalid(
    '6.6 Se rechaza un PIN que no tiene exactamente 4 dígitos',
    fn () => $buildRefund(['pickupPin' => '12345'])
);

$expectInvalid(
    '6.7 Se rechaza un PIN no numérico',
    fn () => $buildRefund(['pickupPin' => 'abcd'])
);

$assert(
    '6.8 La comparación del PIN usa hash_equals en tiempo constante',
    str_contains(
        (string)file_get_contents($baseDir . '/src/Core/Domain/Model/RefundRequest.php'),
        'hash_equals'
    ),
    'El dominio debe comparar el PIN con hash_equals()'
);

echo "\n--- 7. Invariantes generales de la entidad ---\n";

$expectInvalid(
    '7.1 Se rechaza un identificador de expediente no positivo',
    fn () => $buildRefund(['id' => 0])
);

$expectInvalid(
    '7.2 Se rechaza un expediente sin contacto del reclamante',
    fn () => $buildRefund(['claimantContact' => ''])
);

$expectInvalid(
    '7.3 Se rechaza un expediente sin token de seguimiento',
    fn () => $buildRefund(['trackingToken' => ''])
);

$expectInvalid(
    '7.4 Se rechaza un importe recuperado negativo',
    fn () => $buildRefund(['recoveredAmount' => -0.01])
);

$expectInvalid(
    '7.5 Se rechaza un importe aprobado negativo',
    fn () => $buildRefund(['approvedAmount' => -0.01])
);

$expectInvalid(
    '7.6 Bizum exige teléfono de contacto',
    fn () => $buildRefund([
        'compensationMethod' => CompensationMethod::BIZUM,
        'bizumPhone' => null,
    ])
);

$expectInvalid(
    '7.7 La transferencia bancaria exige IBAN',
    fn () => $buildRefund([
        'compensationMethod' => CompensationMethod::TRANSFERENCIA_BANCARIA,
        'iban' => null,
    ])
);

$assert(
    '7.8 El importe obligatorio para inspección se expone',
    $buildRefund(['status' => RefundStatus::PENDING_INSPECTION])->awaitsInspection() === true
    && $buildRefund(['status' => RefundStatus::PAID_DIGITAL])->awaitsInspection() === false
);

echo "\n--- 8. Proyección segregada por rol (Art. V.4 / RF-REF-10) ---\n";

$restricted = $buildRefund([
    'compensationMethod' => CompensationMethod::TRANSFERENCIA_BANCARIA,
    'iban' => 'ES9121000418450200051332',
    'bizumPhone' => '600111222',
    'pickupPin' => '4821',
])->toRestrictedArray();

$restrictedFlat = json_encode($restricted, JSON_UNESCAPED_UNICODE) ?: '';

$assert(
    '8.1 La proyección restringida anonimiza al reclamante',
    $restricted['claimant_name'] === 'Laura S.'
);

$assert(
    '8.2 La proyección restringida NUNCA proyecta el IBAN (Art. V.4)',
    !str_contains($restrictedFlat, 'ES9121000418450200051332')
    && !array_key_exists('iban', $restricted)
);

$assert(
    '8.3 La proyección restringida NUNCA proyecta el teléfono Bizum (Art. V.4)',
    !str_contains($restrictedFlat, '600111222')
    && !array_key_exists('bizum_phone', $restricted)
);

$assert(
    '8.4 La proyección restringida NUNCA proyecta el PIN de recogida',
    !str_contains($restrictedFlat, '4821')
    && !array_key_exists('pickup_pin', $restricted)
);

$assert(
    '8.5 La proyección restringida NUNCA proyecta el token de seguimiento',
    !array_key_exists('tracking_token', $restricted)
);

$assert(
    '8.6 La proyección restringida sí expone importe y estado',
    $restricted['claimed_amount'] === 2.00
    && $restricted['status'] === RefundStatus::PENDING_INSPECTION->value
);

$full = json_encode($buildRefund()->toArray(), JSON_UNESCAPED_UNICODE) ?: '';

$assert(
    '8.7 La proyección completa sólo se usa internamente y conserva los datos de pago',
    str_contains($full, 'tracking_token')
);

echo "\n--- 9. Entidad de hallazgos de efectivo de oficio (RF-REF-04) ---\n";

$finding = new UnclaimedCashFinding(
    id: 1,
    incidentId: 10,
    machineId: 20,
    technicianId: 5,
    amount: 3.50,
    notes: 'Monedas atascadas en el selector de billetes.',
    createdAt: '2026-10-01 11:45:00'
);

$assert(
    '9.1 El hallazgo conserva su importe y su técnico actuante',
    $finding->getAmount() === 3.50 && $finding->getTechnicianId() === 5
);

$assert(
    '9.2 El hallazgo se serializa con su traza completa',
    $finding->toArray() === [
        'id' => 1,
        'incident_id' => 10,
        'machine_id' => 20,
        'technician_id' => 5,
        'amount' => 3.50,
        'notes' => 'Monedas atascadas en el selector de billetes.',
        'created_at' => '2026-10-01 11:45:00',
    ]
);

$assert(
    '9.3 El hallazgo se serializa como JSON',
    json_encode($finding, JSON_UNESCAPED_UNICODE) !== false
);

$invalidFinding = function (array $overrides = []): UnclaimedCashFinding {
    return new UnclaimedCashFinding(...array_merge([
        'id' => 1,
        'incidentId' => 10,
        'machineId' => 20,
        'technicianId' => 5,
        'amount' => 3.50,
        'notes' => '',
        'createdAt' => '2026-10-01 11:45:00',
    ], $overrides));
};

$expectInvalid(
    '9.4 Se rechaza un hallazgo con importe cero',
    fn () => $invalidFinding(['amount' => 0.0])
);

$expectInvalid(
    '9.5 Se rechaza un hallazgo con importe negativo',
    fn () => $invalidFinding(['amount' => -1.0])
);

$expectInvalid(
    '9.6 Se rechaza un hallazgo sin técnico actuante',
    fn () => $invalidFinding(['technicianId' => 0])
);

$expectInvalid(
    '9.7 Se rechaza un hallazgo sin marca temporal',
    fn () => $invalidFinding(['createdAt' => ''])
);

$assert(
    '9.8 Se admite un hallazgo con importe máximo de 50,00 €',
    $invalidFinding(['amount' => 50.00])->getAmount() === 50.00
);

echo "\n--- 10. Inmutabilidad y tipado estricto ---\n";

foreach (['RefundRequest', 'UnclaimedCashFinding'] as $entity) {
    $path = $baseDir . "/src/Core/Domain/Model/{$entity}.php";
    $source = (string)file_get_contents($path);

    $assert(
        "10.x {$entity} declara strict_types",
        str_contains($source, 'declare(strict_types=1);')
    );

    $assert(
        "10.x {$entity} es final e inmutable (readonly)",
        str_contains($source, 'final readonly class')
    );

    $assert(
        "10.x {$entity} no expone setters",
        !preg_match('/function\s+set[A-Z]\w*\s*\(/', $source)
    );
}

echo "\n" . str_repeat('=', 90) . "\n";
echo " Total Aserciones: {$assertions}\n";

if ($failures === 0) {
    echo " RESULTADO: 100% EN VERDE. Condición T-REF-02 CUMPLIDA SATISFACTORIAMENTE.\n";
} else {
    echo " RESULTADO: {$failures} FALLO(S) DETECTADO(S).\n";
}

echo str_repeat('=', 90) . "\n";

exit($failures === 0 ? 0 : 1);