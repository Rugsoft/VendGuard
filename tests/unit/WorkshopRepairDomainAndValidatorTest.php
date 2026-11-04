<?php

declare(strict_types=1);

/**
 * WorkshopRepairDomainAndValidatorTest · T-WORKSHOP-05
 *
 * Certifica, sin motor y sin dependencias, las reglas que sostienen la bandeja de
 * taller antes de que exista un repositorio:
 *
 *   1. **Evidencia técnica (Art. V.1, `QA 1`).** `BenchDiagnosisValidator` mide el
 *      texto normalizado: vacío, corto, largo y relleno de símbolos se rechazan;
 *      un diagnóstico con tres palabras reales se acepta y se devuelve colapsado.
 *   2. **Ciclo de vida (`C1`, `C5`, `D1`).** Recepción, banco y dictamen; el atajo
 *      `RECEIVED → terminal` sella `bench_started_at = resolved_at`; el extravío
 *      desde `PENDING_DELIVERY` deja recepción y banco nulos; un terminal no muta
 *      y responde `409 WORKSHOP_ITEM_ALREADY_RESOLVED`.
 *   3. **Ahorro y tasa (`C2`, `QA 1`).** Suelo cero en el ahorro, aviso cuando el
 *      coste iguala o supera el valor de reposición y `recovery_rate_pct` con
 *      denominador de unidades evaluadas en banco.
 *   4. **Contratos de entrada (`D2`, `D3`).** Los tres DTOs acotan estado,
 *      categoría, paginación, nota de recepción, dictamen, destino y coste.
 *
 * CATEGORÍA: unitaria pura. No necesita `PG_TEST_DSN` ni red.
 *
 * Uso:
 *   php tests/unit/WorkshopRepairDomainAndValidatorTest.php
 *
 * Dogma Vanilla: PHP 8.2+ puro, cero dependencias externas.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */

require_once __DIR__ . '/../../src/autoload.php';

use VendGuard\Application\DTO\ReceiveWorkshopItemRequestDTO;
use VendGuard\Application\DTO\ResolveWorkshopItemRequestDTO;
use VendGuard\Application\DTO\WorkshopTrayQueryDTO;
use VendGuard\Core\Domain\Entity\WorkshopRepairItem;
use VendGuard\Core\Domain\Exception\WorkshopRepairValidationException;
use VendGuard\Core\Domain\Service\BenchDiagnosisValidator;
use VendGuard\Core\Domain\ValueObject\WorkshopRepairStatus;
use VendGuard\Core\Domain\ValueObject\WorkshopRestockDestination;

echo "======================================================================\n";
echo " VENDGUARD · T-WORKSHOP-05 · Dominio y validador del taller central\n";
echo "======================================================================\n";

$assertions = 0;
$failures = 0;

$assert = static function (string $label, bool $ok, string $detail = '') use (&$assertions, &$failures): void {
    $assertions++;
    echo ($ok ? '  [OK]  ' : '  [FAIL]') . " {$label}" . ($detail !== '' ? " · {$detail}" : '') . "\n";
    if (!$ok) {
        $failures++;
    }
};

/**
 * Comprueba que una llamada lanza el código de negocio esperado.
 */
$expectCode = static function (
    string $label,
    callable $call,
    string $expectedCode,
    int $expectedStatus
) use ($assert): void {
    try {
        $call();
        $assert($label, false, 'no lanzó ninguna excepción');
    } catch (WorkshopRepairValidationException $exception) {
        $assert(
            $label,
            $exception->getErrorCode() === $expectedCode && $exception->getHttpStatusCode() === $expectedStatus,
            $exception->getErrorCode() . ' · ' . $exception->getHttpStatusCode()
        );
    } catch (Throwable $exception) {
        $assert($label, false, get_class($exception) . ': ' . $exception->getMessage());
    }
};

$makeItem = static fn (bool $outOfCatalog = false, float $unitCost = 185.00): WorkshopRepairItem => new WorkshopRepairItem(
    7,
    10,
    1,
    2,
    'INCIDENT',
    4,
    null,
    2,
    1,
    2,
    $outOfCatalog ? null : 1,
    $outOfCatalog,
    $outOfCatalog ? 'SIN-CAT' : 'PAY-MDB-001',
    $outOfCatalog ? 'Selector recuperado sin catálogo' : 'Selector de Monedas MDB',
    $outOfCatalog ? 'OTHER' : 'PAYMENT',
    'VM-MAD-002',
    'Jofemar Vision',
    'Hospital Universitario Central',
    'Carlos Ruiz',
    $unitCost
);

// ─────────────────────────────────────────────────────────────────────
// 1. BenchDiagnosisValidator (Art. V.1)
// ─────────────────────────────────────────────────────────────────────
echo "\n--- 1. Evidencia técnica de banco (Art. V.1) ---\n";

$expectCode(
    '1.1 El texto vacío se rechaza',
    static fn (): string => BenchDiagnosisValidator::normalizeAndValidate(''),
    WorkshopRepairValidationException::BENCH_DIAGNOSIS_TOO_SHORT,
    422
);

$expectCode(
    '1.2 Un texto de 11 caracteres se rechaza',
    static fn (): string => BenchDiagnosisValidator::normalizeAndValidate('Limpieza ok'),
    WorkshopRepairValidationException::BENCH_DIAGNOSIS_TOO_SHORT,
    422
);

$expectCode(
    '1.3 El relleno de guiones no acredita una revisión',
    static fn (): string => BenchDiagnosisValidator::normalizeAndValidate(str_repeat('-', 40)),
    WorkshopRepairValidationException::BENCH_DIAGNOSIS_TOO_SHORT,
    422
);

$expectCode(
    '1.4 Un texto de 1001 caracteres se rechaza',
    static fn (): string => BenchDiagnosisValidator::normalizeAndValidate(str_repeat('sensor ', 144)),
    WorkshopRepairValidationException::BENCH_DIAGNOSIS_TOO_LONG,
    422
);

$normalized = BenchDiagnosisValidator::normalizeAndValidate(
    "  Placa\trevisada\n\n y   calibrada en banco de pruebas  "
);
$assert(
    '1.5 Un diagnóstico válido se normaliza y conserva sus palabras',
    $normalized === 'Placa revisada y calibrada en banco de pruebas'
    && mb_strlen($normalized, 'UTF-8') === 46,
    $normalized
);

$assert(
    '1.6 Los saltos de línea no roban palabras: tres tokens bastan',
    BenchDiagnosisValidator::normalizeAndValidate("Sensor\nóptico\nrevisado ok") === 'Sensor óptico revisado ok'
);

$expectCode(
    '1.7 Un diagnóstico de dos palabras deja de ser evidencia',
    static fn (): string => BenchDiagnosisValidator::normalizeAndValidate('Sensoróptico revisado'),
    WorkshopRepairValidationException::BENCH_DIAGNOSIS_TOO_SHORT,
    422
);

// ─────────────────────────────────────────────────────────────────────
// 2. Transiciones del ciclo
// ─────────────────────────────────────────────────────────────────────
echo "\n--- 2. Ciclo de vida del expediente (C1, C5, D1) ---\n";

$pending = $makeItem();
$assert(
    '2.1 El alta nace en PENDING_DELIVERY y no es terminal',
    $pending->getStatus() === WorkshopRepairStatus::PENDING_DELIVERY && !$pending->isTerminal()
);
$assert('2.2 La insignia unitaria es 1/2', $pending->getUnitBadge() === '1/2');

$received = $pending->markReceivedInWorkshop(1, '  Entregada sin daños  ', '2026-11-01T17:30:00+01:00');
$assert(
    '2.3 La recepción normaliza la nota y no muta la instancia original',
    $received->getStatus() === WorkshopRepairStatus::RECEIVED_IN_WORKSHOP
    && $received->getReceptionNotes() === 'Entregada sin daños'
    && $pending->getStatus() === WorkshopRepairStatus::PENDING_DELIVERY
);

$bench = $received->startBenchTest('2026-11-02T09:15:00+01:00');
$assert(
    '2.4 El inicio de banco sella bench_started_at',
    $bench->getStatus() === WorkshopRepairStatus::IN_BENCH_TEST
    && $bench->getBenchStartedAt() === '2026-11-02T09:15:00+01:00'
);

$shortcut = $received->resolveAsRefurbished(
    "Sustitución de correa dentada y prueba de 200 ciclos.\n\nSin atascos.",
    6.50,
    WorkshopRestockDestination::TECHNICIAN_VAN,
    1,
    2,
    '2026-11-02T11:40:00+01:00'
);
$assert(
    '2.5 El atajo RECEIVED → dictamen sella el banco en la fecha de cierre',
    $shortcut->getStatus() === WorkshopRepairStatus::REFURBISHED_RESTORED
    && $shortcut->getBenchStartedAt() === '2026-11-02T11:40:00+01:00'
    && $shortcut->getBenchDiagnosis() === 'Sustitución de correa dentada y prueba de 200 ciclos. Sin atascos.'
);

$resolved = $bench->resolveAsRefurbished(
    'Limpieza ultrasónica de sensores ópticos y prueba de ciclos en banco.',
    4.50,
    WorkshopRestockDestination::TECHNICIAN_VAN,
    1,
    2,
    '2026-11-02T12:00:00+01:00'
);
$assert(
    '2.6 El dictamen desde banco conserva bench_started_at y sella el ahorro',
    $resolved->getBenchStartedAt() === '2026-11-02T09:15:00+01:00'
    && $resolved->getNetSavingsEur() === 180.50
    && $resolved->getRestockedToTechnicianId() === 2
    && $resolved->getResolvedByUserId() === 1
);

$lost = $makeItem()->resolveAsScrapped(
    'No entregada por el técnico tras dos avisos de ruta; se declara extraviada.',
    1,
    '2026-11-02T18:00:00+01:00'
);
$assert(
    '2.7 La baja por extravío conserva recepción y banco nulos (C1)',
    $lost->getStatus() === WorkshopRepairStatus::SCRAPPED_IRREPARABLE
    && $lost->getReceivedAt() === null
    && $lost->getBenchStartedAt() === null
    && $lost->getRestockDestination() === null
    && $lost->getNetSavingsEur() === 0.0
);

$burnt = $received->resolveAsScrapped(
    'Placa corroída sin reparación posible; se envía a reciclaje certificado.',
    1,
    '2026-11-02T13:00:00+01:00'
);
$assert(
    '2.8 La baja tras recepción sella el banco y anula coste y ahorro',
    $burnt->getBenchStartedAt() === '2026-11-02T13:00:00+01:00'
    && $burnt->getRefurbishmentCostEur() === 0.0
    && $burnt->getNetSavingsEur() === 0.0
    && $burnt->getBenchDiagnosis() !== null
);

$expectCode(
    '2.9 Recibir una unidad ya recibida es una transición ilegal',
    static fn (): WorkshopRepairItem => $received->markReceivedInWorkshop(1, null, '2026-11-02T09:00:00+01:00'),
    WorkshopRepairValidationException::INVALID_WORKSHOP_STATUS_TRANSITION,
    409
);

$expectCode(
    '2.10 Iniciar banco sin recepción es una transición ilegal',
    static fn (): WorkshopRepairItem => $makeItem()->startBenchTest('2026-11-02T09:00:00+01:00'),
    WorkshopRepairValidationException::INVALID_WORKSHOP_STATUS_TRANSITION,
    409
);

$expectCode(
    '2.11 Dictaminar reacondicionada sin recepción se rechaza (422)',
    static fn (): WorkshopRepairItem => $makeItem()->resolveAsRefurbished(
        'Pieza revisada y calibrada en banco de pruebas.',
        1.00,
        WorkshopRestockDestination::CENTRAL_WAREHOUSE,
        1,
        null,
        '2026-11-02T12:00:00+01:00'
    ),
    WorkshopRepairValidationException::UNRECEIVED_PART_CANNOT_BE_REFURBISHED,
    422
);

// ─────────────────────────────────────────────────────────────────────
// 3. Inmutabilidad de los dictámenes terminales (C5)
// ─────────────────────────────────────────────────────────────────────
echo "\n--- 3. Dictamen terminal inmutable (C5) ---\n";

$expectCode(
    '3.1 Un terminal no admite inicio de banco',
    static fn (): WorkshopRepairItem => $resolved->startBenchTest('2026-11-03T09:00:00+01:00'),
    WorkshopRepairValidationException::WORKSHOP_ITEM_ALREADY_RESOLVED,
    409
);

$expectCode(
    '3.2 Un terminal no admite un segundo dictamen de reacondicionado',
    static fn (): WorkshopRepairItem => $resolved->resolveAsRefurbished(
        'Revisión posterior no permitida por el contrato.',
        1.00,
        WorkshopRestockDestination::CENTRAL_WAREHOUSE,
        1,
        null,
        '2026-11-03T09:00:00+01:00'
    ),
    WorkshopRepairValidationException::WORKSHOP_ITEM_ALREADY_RESOLVED,
    409
);

$expectCode(
    '3.3 Una baja terminal no puede mutar a reacondicionada',
    static fn (): WorkshopRepairItem => $lost->resolveAsRefurbished(
        'Intento de resucitar una unidad extraviada.',
        1.00,
        WorkshopRestockDestination::CENTRAL_WAREHOUSE,
        1,
        null,
        '2026-11-03T09:00:00+01:00'
    ),
    WorkshopRepairValidationException::WORKSHOP_ITEM_ALREADY_RESOLVED,
    409
);

// ─────────────────────────────────────────────────────────────────────
// 4. Ahorro neto y suelo cero (C2)
// ─────────────────────────────────────────────────────────────────────
echo "\n--- 4. Ahorro neto y aviso de coste (C2) ---\n";

$assert('4.1 El ahorro resta el coste al valor de reposición', WorkshopRepairItem::calculateNetSavings(185.0, 4.5) === 180.50);
$assert('4.2 El suelo cero impide ahorros negativos', WorkshopRepairItem::calculateNetSavings(10.0, 12.5) === 0.00);
$assert('4.3 El resultado se redondea a dos decimales', WorkshopRepairItem::calculateNetSavings(10.005, 0.0) === 10.01);
$assert('4.4 Sin coste el aviso no se activa', $resolved->hasZeroNetSavingsWarning() === false);

$zero = $received->resolveAsRefurbished(
    'Mecanismo reconstruido con piezas de otra unidad desguazada.',
    185.00,
    WorkshopRestockDestination::CENTRAL_WAREHOUSE,
    1,
    null,
    '2026-11-02T12:00:00+01:00'
);
$assert(
    '4.5 Coste igual al valor: ahorro cero y aviso activo',
    $zero->getNetSavingsEur() === 0.00 && $zero->hasZeroNetSavingsWarning() === true
);

// ─────────────────────────────────────────────────────────────────────
// 5. Tasa de recuperación (QA 1)
// ─────────────────────────────────────────────────────────────────────
echo "\n--- 5. Tasa de recuperación (QA 1) ---\n";

$assert('5.1 Tres de cuatro evaluadas son el 75.00 %', WorkshopRepairItem::calculateRecoveryRate(3, 1) === 75.00);
$assert('5.2 Sin unidades evaluadas la tasa es cero', WorkshopRepairItem::calculateRecoveryRate(0, 0) === 0.0);
$assert(
    '5.3 Las bajas sin recepción no entran en el denominador',
    WorkshopRepairItem::calculateRecoveryRate(3, 1) === 75.00
    && WorkshopRepairItem::calculateRecoveryRate(3, 1) !== WorkshopRepairItem::calculateRecoveryRate(3, 1 + 2)
);

// ─────────────────────────────────────────────────────────────────────
// 6. Contratos de entrada (D2, D3)
// ─────────────────────────────────────────────────────────────────────
echo "\n--- 6. DTOs de la bandeja y del dictamen ---\n";

$query = WorkshopTrayQueryDTO::fromQuery([
    'status' => 'in_bench_test',
    'category' => 'payment',
    'technician_id' => '2',
    'search' => '  selector  ',
    'page' => '3',
    'per_page' => '50',
]);
$assert(
    '6.1 El DTO de consulta normaliza estado, categoría y búsqueda',
    $query->status === 'IN_BENCH_TEST'
    && $query->category === 'PAYMENT'
    && $query->search === 'selector'
    && $query->statusFilter() === WorkshopRepairStatus::IN_BENCH_TEST
    && $query->categoryFilter() === 'PAYMENT'
);
$assert('6.2 El desplazamiento se calcula con página y tamaño', $query->offset() === 100);

$all = new WorkshopTrayQueryDTO();
$assert(
    '6.3 ALL se traduce a ausencia de filtro',
    $all->statusFilter() === null && $all->categoryFilter() === null && $all->perPage === 20
);

$assert(
    '6.5 Un estado inválido lanza InvalidArgumentException',
    (static function (): bool {
        try {
            new WorkshopTrayQueryDTO(status: 'RESOLVED');
        } catch (InvalidArgumentException) {
            return true;
        }

        return false;
    })()
);

$assert(
    '6.6 El tamaño de página está acotado a 1..100',
    (static function (): bool {
        foreach ([0, 101, -5] as $size) {
            try {
                new WorkshopTrayQueryDTO(perPage: $size);

                return false;
            } catch (InvalidArgumentException) {
                continue;
            }
        }

        return true;
    })()
);

$receive = ReceiveWorkshopItemRequestDTO::fromPayload(7, 1, ['reception_notes' => '  Entregada sin daños  ']);
$assert(
    '6.7 La recepción normaliza la nota y acepta el cuerpo del contrato',
    $receive->workshopItemId === 7 && $receive->effectiveReceptionNotes() === 'Entregada sin daños'
);

$expectCode(
    '6.8 Una nota de 501 caracteres se rechaza con 422',
    static fn (): ReceiveWorkshopItemRequestDTO => ReceiveWorkshopItemRequestDTO::fromPayload(
        7,
        1,
        ['reception_notes' => str_repeat('a', 501)]
    ),
    WorkshopRepairValidationException::RECEPTION_NOTES_TOO_LONG,
    422
);

$resolve = ResolveWorkshopItemRequestDTO::fromPayload(7, 1, [
    'outcome_status' => 'refurbished_restored',
    'bench_diagnosis' => 'Sustitución de correa dentada y prueba de 200 ciclos.',
    'refurbishment_cost_eur' => '6.50',
    'restock_destination' => 'technician_van',
    'restocked_to_technician_id' => '2',
]);
$assert(
    '6.9 El dictamen se tipa y admite numéricos como cadena',
    $resolve->isRefurbishment()
    && $resolve->restockDestination === WorkshopRestockDestination::TECHNICIAN_VAN
    && $resolve->restockedTechnicianId === 2
    && $resolve->refurbishmentCostEur === 6.50
);

$expectCode(
    '6.10 Un dictamen no terminal se rechaza',
    static fn (): ResolveWorkshopItemRequestDTO => ResolveWorkshopItemRequestDTO::fromPayload(
        7,
        1,
        ['outcome_status' => 'IN_BENCH_TEST']
    ),
    WorkshopRepairValidationException::INVALID_RESOLUTION_OUTCOME,
    422
);

$expectCode(
    '6.11 Un destino desconocido se rechaza',
    static fn (): ResolveWorkshopItemRequestDTO => ResolveWorkshopItemRequestDTO::fromPayload(
        7,
        1,
        [
            'outcome_status' => 'REFURBISHED_RESTORED',
            'bench_diagnosis' => 'Sustitución de correa dentada y prueba de 200 ciclos.',
            'restock_destination' => 'TALLER',
        ]
    ),
    WorkshopRepairValidationException::INVALID_RESTOCK_DESTINATION,
    422
);

$expectCode(
    '6.12 Un coste negativo se rechaza',
    static fn (): ResolveWorkshopItemRequestDTO => ResolveWorkshopItemRequestDTO::fromPayload(
        7,
        1,
        [
            'outcome_status' => 'REFURBISHED_RESTORED',
            'bench_diagnosis' => 'Sustitución de correa dentada y prueba de 200 ciclos.',
            'refurbishment_cost_eur' => -1,
        ]
    ),
    WorkshopRepairValidationException::INVALID_REFURBISHMENT_COST,
    422
);

echo "\n======================================================================\n";
echo " Total Aserciones: {$assertions} | Exitosas: " . ($assertions - $failures) . " | Fallidas: {$failures}\n";

if ($failures === 0) {
    echo " RESULT: 100% EN VERDE. T-WORKSHOP-05 (dominio y validador) CUMPLIDA.\n";
    echo "======================================================================\n";
    exit(0);
}

echo " RESULT: FALLOS DETECTADOS EN EL DOMINIO DEL TALLER.\n";
echo "======================================================================\n";
exit(1);
