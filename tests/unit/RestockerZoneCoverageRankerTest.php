<?php

declare(strict_types=1);

/**
 * RestockerZoneCoverageRankerTest · T-RES-03
 *
 * Certifica la Fase 2 de dominio del Módulo 31 sin base de datos ni reloj: el rol
 * `RESTOCKER`, la entidad del cuadrante de carga y el escalafón determinista de
 * cobertura (RF-01.1, RF-02.1, RF-03.1, RF-03.2, C2, QA 1, QA 3).
 *
 * QUÉ SE CERTIFICA
 *
 *   1. **Rol `RESTOCKER` (RF-01.1, D3).** Existe en `UserRole`, declara su
 *      etiqueta, se reconoce como operario de ruta y no como coordinador ni
 *      técnico; `fromString` lo admite y `values()` lo publica.
 *   2. **Entidad `RestockerZoneAssignment` (RF-02.1, QA 1).** Siete columnas,
 *      coherencia obligatoria entre `role_in_zone` e `is_primary`, baja lógica
 *      que conserva la fila, rechazo de una baja anterior al alta y reconstrucción
 *      desde una fila del motor con el booleano en sus dos formas.
 *   3. **Escalafón de 7 escalones (RF-03.1, QA 3).** Cada combinación de rol y
 *      turno cae en su nivel exacto, con su rango, su etiqueta, su marca de
 *      promoción y su disponibilidad.
 *   4. **Precedencia de presencia física (C2, EARS 03.3).** Un reponedor con
 *      ausencia vigente que está en turno ocupa su escalón y se marca
 *      `has_overridden_absence`; la ausencia solo lo aparta fuera de turno.
 *   5. **Desempate determinista (RF-03.2).** Antigüedad en la zona, después
 *      `operator_code` y por último identificador.
 *   6. **`#1 RECOMENDADO ⭐` y cobertura (D3).** La designación distingue titular
 *      de suplente promovido y el estado de la zona distingue titular, promoción y
 *      zona sin cobertura.
 *   7. **Determinismo y validación.** Dos llamadas con la misma foto devuelven lo
 *      mismo, la foto no se muta y una entrada imposible falla en voz alta.
 *
 * Uso:
 *   php tests/unit/RestockerZoneCoverageRankerTest.php
 *
 * Dogma Vanilla: PHP 8.2+ puro, cero dependencias externas.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */

require_once __DIR__ . '/../../src/autoload.php';

use VendGuard\Core\Domain\Exception\ZoneValidationException;
use VendGuard\Core\Domain\Model\RestockerZoneAssignment;
use VendGuard\Core\Domain\Model\UserRole;
use VendGuard\Core\Service\RestockerZoneCoverageRanker;

$assertions = 0;
$failures = 0;

$assert = static function (string $what, bool $ok, string $detail = '') use (&$assertions, &$failures): void {
    $assertions++;
    echo ($ok ? '  [PASS] ' : '  [FAIL] ') . $what . ($detail !== '' ? " · {$detail}" : '') . "\n";
    if (!$ok) {
        $failures++;
    }
};

$rejects = static function (callable $operation): bool {
    try {
        $operation();
    } catch (Throwable) {
        return true;
    }

    return false;
};

echo "======================================================================\n";
echo " VENDGUARD · T-RES-03 · Rol RESTOCKER, cuadrante de carga y escalafón (Módulo 31)\n";
echo "======================================================================\n";

// ─────────────────────────────────────────────────────────────────────────────
// 1. Rol RESTOCKER en el dominio
// ─────────────────────────────────────────────────────────────────────────────
echo "\n--- Rol RESTOCKER (RF-01.1) ---\n";

$restocker = UserRole::fromString('restocker');

$assert(
    'UserRole::fromString admite el rol RESTOCKER sin distinguir mayúsculas',
    $restocker === UserRole::RESTOCKER
);

$assert(
    'El rol declara su etiqueta en castellano',
    $restocker->label() === 'Cargador / Reponedor de Ruta',
    $restocker->label()
);

$assert(
    'RESTOCKER es operario de ruta y no es técnico ni coordinador',
    $restocker->isFieldOperator() === true
        && $restocker->isRestocker() === true
        && $restocker->isTechnician() === false
        && $restocker->isCoordinator() === false
);

$assert(
    'Los dos roles previos conservan su etiqueta y su condición de operarios',
    UserRole::TECHNICIAN->label() === 'Técnico de Ruta'
        && UserRole::TECHNICIAN->isFieldOperator() === true
        && UserRole::COORDINATOR->label() === 'Coordinador de Servicios'
        && UserRole::COORDINATOR->isFieldOperator() === false
);

$assert(
    'values() publica los tres roles y solo ellos',
    UserRole::values() === ['COORDINATOR', 'TECHNICIAN', 'RESTOCKER'],
    implode(', ', UserRole::values())
);

// ─────────────────────────────────────────────────────────────────────────────
// 2. Entidad RestockerZoneAssignment (RF-02.1, QA 1)
// ─────────────────────────────────────────────────────────────────────────────
echo "\n--- Entidad del cuadrante de carga ---\n";

$assignedAt = new DateTimeImmutable('2026-11-01T08:00:00+01:00');

$primaryAssignment = new RestockerZoneAssignment(5, 12, 1, 'primary', null, $assignedAt);

$assert(
    'El rol se normaliza y deriva is_primary sin exigir el booleano',
    $primaryAssignment->getRoleInZone() === RestockerZoneAssignment::ROLE_PRIMARY
        && $primaryAssignment->isPrimary() === true
        && $primaryAssignment->isBackup() === false
        && $primaryAssignment->roleLabel() === 'Titular'
);

$assert(
    'Una vinculación recién creada está viva y conserva su marca de alta',
    $primaryAssignment->isLive() === true
        && $primaryAssignment->getAssignedAt()->format('c') === $assignedAt->format('c')
);

$backupAssignment = new RestockerZoneAssignment(null, 12, 2, 'BACKUP', false, $assignedAt);

$assert(
    'El suplente se declara con is_primary = FALSE y su etiqueta es Suplente',
    $backupAssignment->isPrimary() === false
        && $backupAssignment->isBackup() === true
        && $backupAssignment->roleLabel() === 'Suplente'
        && $backupAssignment->getId() === null
);

$assert(
    'Un rol fuera del catálogo se rechaza al construir la entidad',
    $rejects(static fn () => new RestockerZoneAssignment(null, 12, 1, 'OWNER', null, $assignedAt))
);

$assert(
    "La pareja incoherente (PRIMARY con is_primary = FALSE) se rechaza con el campo culpable",
    (static function () use ($assignedAt): bool {
        try {
            new RestockerZoneAssignment(null, 12, 1, 'PRIMARY', false, $assignedAt);
        } catch (ZoneValidationException $exception) {
            return str_contains($exception->getMessage(), 'is_primary');
        }

        return false;
    })()
);

$assert(
    'La baja lógica devuelve una copia extinta y conserva la fila original viva',
    (static function () use ($primaryAssignment, $assignedAt): bool {
        $removed = $primaryAssignment->remove(new DateTimeImmutable('2026-11-10T18:00:00+01:00'));

        return $removed->isLive() === false
            && $removed->getRemovedAt() !== null
            && $primaryAssignment->isLive() === true
            && $removed->getRestockerId() === $primaryAssignment->getRestockerId()
            && $removed->getZoneId() === $primaryAssignment->getZoneId();
    })()
);

$assert(
    'Una baja anterior al alta se rechaza',
    $rejects(static fn () => new RestockerZoneAssignment(
        null,
        12,
        1,
        'BACKUP',
        false,
        new DateTimeImmutable('2026-11-11T10:00:00+01:00'),
        new DateTimeImmutable('2026-11-11T09:00:00+01:00')
    ))
);

$assert(
    'Repetir la baja de una vinculación ya extinta se rechaza',
    $rejects(static fn () => $primaryAssignment->remove($assignedAt)->remove($assignedAt))
);

$rowAssignment = RestockerZoneAssignment::fromDatabaseRow([
    'id' => '9',
    'restocker_id' => '14',
    'zone_id' => '3',
    'role_in_zone' => 'BACKUP',
    'is_primary' => 'f',
    'assigned_at' => '2026-11-02T07:30:00+01:00',
    'removed_at' => null,
]);

$assert(
    'fromDatabaseRow reconstruye la fila con el booleano en forma de motor y sin baja',
    $rowAssignment->getId() === 9
        && $rowAssignment->getRestockerId() === 14
        && $rowAssignment->getZoneId() === 3
        && $rowAssignment->isBackup() === true
        && $rowAssignment->isLive() === true
);

$assert(
    'fromDatabaseRow rechaza una fila cuya coherencia se contradice',
    $rejects(static fn () => RestockerZoneAssignment::fromDatabaseRow([
        'restocker_id' => 14,
        'zone_id' => 3,
        'role_in_zone' => 'BACKUP',
        'is_primary' => 't',
        'assigned_at' => '2026-11-02T07:30:00+01:00',
    ]))
);

$assert(
    'La proyección declara las claves del contrato del cuadrante',
    array_keys($primaryAssignment->toArray()) === [
        'id', 'restocker_id', 'zone_id', 'role_in_zone', 'role_label',
        'is_primary', 'assigned_at', 'removed_at', 'is_live',
    ],
    implode(', ', array_keys($primaryAssignment->toArray()))
);

// ─────────────────────────────────────────────────────────────────────────────
// 3. Foto base del cuadrante
// ─────────────────────────────────────────────────────────────────────────────

/** Candidato del cuadrante con los valores que no interesan al caso. */
$candidate = static function (array $overrides = []): array {
    return array_merge([
        'restocker_id' => 12,
        'name' => 'Lucía Gómez',
        'operator_code' => 'REP-01',
        'role_in_zone' => 'PRIMARY',
        'is_active' => true,
        'shift_status' => 'ON_SHIFT',
        'has_active_absence' => false,
        'absence_reason' => null,
        'assigned_at' => '2026-11-01T08:00:00+01:00',
    ], $overrides);
};

$tiers = [
    ['PRIMARY', 'ON_SHIFT', RestockerZoneCoverageRanker::TIER_PRIMARY_ON_SHIFT, 1, false, true],
    ['BACKUP', 'ON_SHIFT', RestockerZoneCoverageRanker::TIER_BACKUP_PROMOTED_ON_SHIFT, 2, true, true],
    ['PRIMARY', 'MEAL_BREAK', RestockerZoneCoverageRanker::TIER_PRIMARY_MEAL_BREAK, 3, false, true],
    ['BACKUP', 'MEAL_BREAK', RestockerZoneCoverageRanker::TIER_BACKUP_PROMOTED_MEAL_BREAK, 4, true, true],
    ['PRIMARY', 'OFF_SHIFT', RestockerZoneCoverageRanker::TIER_PRIMARY_PLANNED_OFF_SHIFT, 5, false, true],
    ['BACKUP', 'OFF_SHIFT', RestockerZoneCoverageRanker::TIER_BACKUP_PROMOTED_OFF_SHIFT, 6, true, true],
];

echo "\n--- Escalafón de 6 niveles activos + UNAVAILABLE (QA 3) ---\n";

foreach ($tiers as [$role, $shift, $tier, $rank, $promoted, $available]) {
    $report = RestockerZoneCoverageRanker::rank([$candidate([
        'role_in_zone' => $role,
        'shift_status' => $shift,
    ])]);
    $row = $report['candidates'][0];

    $assert(
        "{$role} en {$shift} cae en el escalón {$tier} (rango {$rank})",
        $row['coverage_tier'] === $tier
            && $row['tier_rank'] === $rank
            && $row['is_promoted_backup'] === $promoted
            && $row['is_available'] === $available
            && $row['unavailable_reason'] === null,
        sprintf('%s · rango %d', $row['coverage_tier'], $row['tier_rank'])
    );
}

$inactiveReport = RestockerZoneCoverageRanker::rank([$candidate(['is_active' => false, 'shift_status' => 'ON_SHIFT'])]);
$inactiveRow = $inactiveReport['candidates'][0];

$assert(
    'Una cuenta inactiva cae en UNAVAILABLE con el motivo «Cuenta inactiva» aunque esté en turno',
    $inactiveRow['coverage_tier'] === RestockerZoneCoverageRanker::TIER_UNAVAILABLE
        && $inactiveRow['tier_rank'] === 7
        && $inactiveRow['is_available'] === false
        && $inactiveRow['unavailable_reason'] === RestockerZoneCoverageRanker::UNAVAILABLE_INACTIVE
        && $inactiveReport['coverage_status'] === RestockerZoneCoverageRanker::COVERAGE_UNCOVERED
        && $inactiveReport['recommended_restocker'] === null,
    (string)$inactiveRow['unavailable_reason']
);

$absenceOffShift = RestockerZoneCoverageRanker::rank([$candidate([
    'shift_status' => 'OFF_SHIFT',
    'has_active_absence' => true,
    'absence_reason' => 'VACATION',
])])['candidates'][0];

$assert(
    'Fuera de turno con ausencia vigente es UNAVAILABLE con el motivo de la ausencia',
    $absenceOffShift['coverage_tier'] === RestockerZoneCoverageRanker::TIER_UNAVAILABLE
        && $absenceOffShift['is_available'] === false
        && $absenceOffShift['unavailable_reason'] === 'Ausencia activa: VACATION',
    (string)$absenceOffShift['unavailable_reason']
);

$absenceOnShift = RestockerZoneCoverageRanker::rank([$candidate([
    'shift_status' => 'ON_SHIFT',
    'has_active_absence' => true,
    'absence_reason' => 'VACATION',
])])['candidates'][0];

$assert(
    'La presencia física en turno prevalece sobre la ausencia (C2) y se declara',
    $absenceOnShift['coverage_tier'] === RestockerZoneCoverageRanker::TIER_PRIMARY_ON_SHIFT
        && $absenceOnShift['has_overridden_absence'] === true
        && $absenceOnShift['is_available'] === true
        && $absenceOnShift['unavailable_reason'] === null
);

// ─────────────────────────────────────────────────────────────────────────────
// 4. Desempate determinista (RF-03.2)
// ─────────────────────────────────────────────────────────────────────────────
echo "\n--- Desempate dentro del mismo escalón ---\n";

$tieReport = RestockerZoneCoverageRanker::rank([
    $candidate(['restocker_id' => 30, 'name' => 'Tercero', 'operator_code' => 'REP-03', 'assigned_at' => '2026-11-05T08:00:00+01:00']),
    $candidate(['restocker_id' => 20, 'name' => 'Segundo', 'operator_code' => 'REP-02', 'assigned_at' => '2026-11-01T08:00:00+01:00']),
    $candidate(['restocker_id' => 10, 'name' => 'Primero', 'operator_code' => 'REP-01', 'assigned_at' => '2026-11-01T08:00:00+01:00']),
]);

$assert(
    'Manda la antigüedad en la zona y, a igual antigüedad, el operator_code ascendente',
    array_column($tieReport['candidates'], 'restocker_id') === [10, 20, 30],
    implode(' > ', array_column($tieReport['candidates'], 'restocker_id'))
);

$idTieReport = RestockerZoneCoverageRanker::rank([
    $candidate(['restocker_id' => 22, 'operator_code' => 'REP-01']),
    $candidate(['restocker_id' => 21, 'operator_code' => 'REP-01']),
]);

$assert(
    'El identificador cierra el empate cuando antigüedad y código coinciden',
    array_column($idTieReport['candidates'], 'restocker_id') === [21, 22]
);

$escalatedReport = RestockerZoneCoverageRanker::rank([
    $candidate(['restocker_id' => 2, 'name' => 'Suplente', 'is_primary' => false, 'role_in_zone' => 'BACKUP', 'shift_status' => 'ON_SHIFT', 'assigned_at' => '2026-10-01T08:00:00+01:00']),
    $candidate(['restocker_id' => 1, 'name' => 'Titular', 'role_in_zone' => 'PRIMARY', 'shift_status' => 'OFF_SHIFT', 'assigned_at' => '2026-01-01T08:00:00+01:00']),
]);

$assert(
    'El escalón manda sobre la antigüedad: el suplente en turno va delante del titular fuera de turno',
    array_column($escalatedReport['candidates'], 'restocker_id') === [2, 1]
        && $escalatedReport['candidates'][0]['tier_rank'] === 2
        && $escalatedReport['candidates'][1]['tier_rank'] === 5
);

// ─────────────────────────────────────────────────────────────────────────────
// 5. #1 RECOMENDADO ⭐ y estado de cobertura (D3, QA 3)
// ─────────────────────────────────────────────────────────────────────────────
echo "\n--- Designación del #1 RECOMENDADO y cobertura de la zona ---\n";

$primaryCoverage = RestockerZoneCoverageRanker::rank([
    $candidate(['role_in_zone' => 'PRIMARY', 'shift_status' => 'ON_SHIFT']),
    $candidate(['restocker_id' => 14, 'name' => 'Óscar Ruiz', 'operator_code' => 'REP-02', 'role_in_zone' => 'BACKUP', 'shift_status' => 'ON_SHIFT']),
]);

$assert(
    'Con un titular en turno la zona está COVERED_BY_PRIMARY y él encabeza',
    $primaryCoverage['coverage_status'] === RestockerZoneCoverageRanker::COVERAGE_COVERED_BY_PRIMARY
        && $primaryCoverage['recommended_restocker']['restocker_id'] === 12
        && $primaryCoverage['recommended_restocker']['is_promoted_backup'] === false
        && $primaryCoverage['recommended_restocker']['recommendation_label'] === '#1 RECOMENDADO ⭐ · Titular de Zona en Cobertura Activa',
    (string)$primaryCoverage['recommended_restocker']['recommendation_label']
);

$promotedCoverage = RestockerZoneCoverageRanker::rank([
    $candidate(['role_in_zone' => 'PRIMARY', 'shift_status' => 'OFF_SHIFT', 'has_active_absence' => true, 'absence_reason' => 'VACATION']),
    $candidate(['restocker_id' => 14, 'name' => 'Óscar Ruiz', 'operator_code' => 'REP-02', 'role_in_zone' => 'BACKUP', 'shift_status' => 'OFF_SHIFT']),
]);

$assert(
    'Con el titular ausente, el suplente fuera de turno es el #1 promovido y la zona lo declara',
    $promotedCoverage['coverage_status'] === RestockerZoneCoverageRanker::COVERAGE_COVERED_BY_PROMOTED_BACKUP
        && $promotedCoverage['recommended_restocker']['restocker_id'] === 14
        && $promotedCoverage['recommended_restocker']['is_promoted_backup'] === true
        && $promotedCoverage['recommended_restocker']['recommendation_label'] === '#1 RECOMENDADO ⭐ · Suplente en Cobertura Activa (Promoción automática)',
    (string)$promotedCoverage['recommended_restocker']['recommendation_label']
);

$assert(
    'Solo el primero queda marcado como recomendado y el titular ausente queda NO disponible',
    $promotedCoverage['candidates'][0]['is_recommended'] === true
        && $promotedCoverage['candidates'][1]['is_recommended'] === false
        && $promotedCoverage['candidates'][1]['is_available'] === false
        && $promotedCoverage['candidates'][1]['unavailable_reason'] === 'Ausencia activa: VACATION'
);

$assert(
    'Un candidato en UNAVAILABLE nunca encabeza una zona sin cobertura',
    (static function () use ($candidate): bool {
        $report = RestockerZoneCoverageRanker::rank([
            $candidate(['restocker_id' => 40, 'is_active' => false]),
            $candidate(['restocker_id' => 41, 'shift_status' => 'OFF_SHIFT', 'has_active_absence' => true]),
        ]);

        return $report['coverage_status'] === RestockerZoneCoverageRanker::COVERAGE_UNCOVERED
            && $report['recommended_restocker'] === null
            && array_column($report['candidates'], 'is_recommended') === [false, false];
    })()
);

// ─────────────────────────────────────────────────────────────────────────────
// 6. Determinismo, forma y validación
// ─────────────────────────────────────────────────────────────────────────────
echo "\n--- Determinismo y validación de la foto ---\n";

$photo = [
    $candidate(['restocker_id' => 12, 'role_in_zone' => 'PRIMARY', 'shift_status' => 'MEAL_BREAK']),
    $candidate(['restocker_id' => 14, 'operator_code' => 'REP-02', 'role_in_zone' => 'BACKUP', 'shift_status' => 'ON_SHIFT']),
];

$snapshot = $photo;
$firstReport = RestockerZoneCoverageRanker::rank($photo);
$secondReport = RestockerZoneCoverageRanker::rank($photo);

$assert(
    'Dos llamadas con la misma foto devuelven exactamente el mismo informe',
    json_encode($firstReport) === json_encode($secondReport)
);

$assert(
    'La foto de entrada no se muta',
    json_encode($photo) === json_encode($snapshot)
);

$assert(
    'El informe declara las tres claves del contrato',
    array_keys($firstReport) === ['coverage_status', 'recommended_restocker', 'candidates']
        && array_keys($firstReport['candidates'][0]) === [
            'restocker_id', 'name', 'operator_code', 'role_in_zone', 'role_label',
            'is_primary', 'is_active', 'shift_status', 'coverage_tier', 'coverage_tier_label',
            'tier_rank', 'is_available', 'is_recommended', 'is_promoted_backup',
            'has_overridden_absence', 'unavailable_reason', 'assigned_at',
        ],
    implode(', ', array_keys($firstReport['candidates'][0]))
);

$emptyReport = RestockerZoneCoverageRanker::rank([]);

$assert(
    'Un cuadrante vacío se declara sin cobertura y sin recomendación',
    $emptyReport['coverage_status'] === RestockerZoneCoverageRanker::COVERAGE_UNCOVERED
        && $emptyReport['recommended_restocker'] === null
        && $emptyReport['candidates'] === []
);

$assert(
    'Un candidato que no es foto se rechaza',
    $rejects(static fn () => RestockerZoneCoverageRanker::rank(['REP-01']))
);

$assert(
    'Un role_in_zone desconocido se rechaza',
    $rejects(static fn () => RestockerZoneCoverageRanker::rank([$candidate(['role_in_zone' => 'OWNER'])]) )
);

$assert(
    'Un turno desconocido se rechaza',
    $rejects(static fn () => RestockerZoneCoverageRanker::rank([$candidate(['shift_status' => 'HOLIDAY'])]))
);

$assert(
    'Una pareja rol/is_primary contradictoria en la foto se rechaza',
    $rejects(static fn () => RestockerZoneCoverageRanker::rank([$candidate(['role_in_zone' => 'BACKUP', 'is_primary' => true])]))
);

$assert(
    'Un candidato sin assigned_at legible se rechaza en lugar de ordenarse al azar',
    $rejects(static fn () => RestockerZoneCoverageRanker::rank([$candidate(['assigned_at' => null])]))
        && $rejects(static fn () => RestockerZoneCoverageRanker::rank([$candidate(['assigned_at' => 'ayer por la tarde'])]))
);

$assert(
    'Un identificador no positivo se rechaza',
    $rejects(static fn () => RestockerZoneCoverageRanker::rank([$candidate(['restocker_id' => 0])]))
);

echo "\n======================================================================\n";
echo " Total Aserciones: {$assertions} | Exitosas: " . ($assertions - $failures) . " | Fallidas: {$failures}\n";

if ($failures === 0) {
    echo " RESULTADO: 100% EN VERDE. DOMINIO DEL MÓDULO 31 CERTIFICADO.\n";
    echo "======================================================================\n";
    exit(0);
}

echo " RESULTADO: FALLOS DETECTADOS EN EL DOMINIO DEL MÓDULO 31.\n";
echo "======================================================================\n";
exit(1);
