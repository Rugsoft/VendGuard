<?php

declare(strict_types=1);

/**
 * RestockerZoneCoverageServiceTest · T-RES-08
 *
 * Certifica la Fase 4 del Módulo 31 en la capa de aplicación, sin motor:
 *
 *   1. **Cuadrante de carga en `OperationalZoneService` (RF-02, D2, D3).**
 *      Alta y baja de reponedores delegando en el puerto, traslado de
 *      titularidad confirmado, lectura del cuadrante con su insignia
 *      (`ACTIVO` / `AUSENTE` / `BAJA`), consulta inversa y separación estricta
 *      respecto del cuadrante SAT.
 *   2. **Liberación al desactivar la zona (RF-02.3).** Una zona con reponedores
 *      vivos exige `confirm_release`; al confirmarla, sus vinculaciones de carga
 *      se extinguen junto con el resto del territorio.
 *   3. **Cobertura en vivo (`RF-03.1`–`RF-03.3`, `C2`, `QA 3`).** El servicio
 *      compone cuadrante + turnos + ausencias y delega el escalafón en el
 *      calculador puro: titular en turno, suplente promovido, pausa de comida,
 *      ausencia vigente, cuenta inactiva, precedencia de presencia física y
 *      desempate por antigüedad.
 *   4. **Contrato de lectura.** Zona inexistente → `404 ZONE_NOT_FOUND`;
 *      determinismo entre dos lecturas y foto de entrada intacta.
 *
 * Los dobles guardan lo que el servicio les entrega y aplican sólo la mecánica
 * del puerto (filtrar, marcar `removed_at`, contar). Ninguna regla de negocio se
 * simula aquí: el escalafón es el real (`RestockerZoneCoverageRanker`).
 *
 * Uso: php tests/unit/RestockerZoneCoverageServiceTest.php
 *
 * Dogma Vanilla: PHP 8.2+ puro, cero dependencias externas.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */

require_once __DIR__ . '/../../src/autoload.php';

use VendGuard\Application\Service\OperationalZoneService;
use VendGuard\Application\Service\RestockerZoneCoverageService;
use VendGuard\Core\Domain\Exception\ZoneNotFoundException;
use VendGuard\Core\Domain\Exception\ZoneValidationException;
use VendGuard\Core\Domain\Model\OperationalZone;
use VendGuard\Core\Domain\Model\TechnicianAbsence;
use VendGuard\Core\Domain\Model\TechnicianShift;
use VendGuard\Core\Domain\Repository\LocationZoneRepositoryInterface;
use VendGuard\Core\Domain\Repository\OperationalZoneRepositoryInterface;
use VendGuard\Core\Domain\Repository\TechnicianAbsenceRepositoryInterface;
use VendGuard\Core\Domain\Repository\TechnicianShiftRepositoryInterface;
use VendGuard\Core\Domain\ValueObject\AbsenceReason;
use VendGuard\Core\Service\RestockerZoneCoverageRanker;

$assertions = 0;
$failures = 0;

$assert = static function (string $what, bool $ok, string $detail = '') use (&$assertions, &$failures): void {
    $assertions++;
    echo ($ok ? '  [OK]  ' : '  [FAIL]') . " {$what}" . ($detail !== '' ? " · {$detail}" : '') . "\n";
    if (!$ok) {
        $failures++;
    }
};

$throws = static function (callable $operation): ?Throwable {
    try {
        $operation();
    } catch (Throwable $exception) {
        return $exception;
    }

    return null;
};

echo "======================================================================\n";
echo " VENDGUARD · T-RES-08 · Cuadrante de carga y cobertura de zona (Módulo 31)\n";
echo "======================================================================\n";

/**
 * Puerto territorial en memoria: zonas, cuadrante SAT y cuadrante de carga.
 */
class CoverageZoneRepository implements OperationalZoneRepositoryInterface
{
    /** @var array<int, OperationalZone> */
    public array $zones = [];

    /** @var list<array<string, mixed>> */
    public array $restockerRows = [];

    public int $lastConfirmFlag = -1;
    private int $nextAssignmentId = 100;

    public function seedZone(int $id, string $code = 'ZONA-NORTE', string $name = 'Zona Norte', bool $active = true): OperationalZone
    {
        return $this->zones[$id] = $this->buildZone($id, $code, $name, $active);
    }

    private function buildZone(int $id, string $code, string $name, bool $active): OperationalZone
    {
        return OperationalZone::fromDatabaseRow([
            'id' => $id,
            'code' => $code,
            'name' => $name,
            'color_hex' => '#2560ff',
            'description' => null,
            'is_active' => $active,
            'created_at' => '2026-10-01T08:00:00+02:00',
            'updated_at' => '2026-10-01T08:00:00+02:00',
        ]);
    }

    public function seedRestocker(
        int $zoneId,
        int $restockerId,
        string $name,
        string $roleInZone,
        bool $active = true,
        string $assignedAt = '2026-11-01T08:00:00+01:00'
    ): void {
        $this->restockerRows[] = [
            'assignment_id' => $this->nextAssignmentId++,
            'zone_id' => $zoneId,
            'restocker_id' => $restockerId,
            'name' => $name,
            'operator_code' => 'REP-' . $restockerId,
            'role_in_zone' => $roleInZone,
            'role_label' => $roleInZone === 'PRIMARY' ? 'Titular' : 'Suplente',
            'is_primary' => $roleInZone === 'PRIMARY',
            'is_active' => $active,
            'assigned_at' => $assignedAt,
            'removed_at' => null,
        ];
    }

    public function findById(int $zoneId, bool $includeInactive = false): ?OperationalZone
    {
        $zone = $this->zones[$zoneId] ?? null;
        if ($zone === null) {
            return null;
        }

        return $zone->isActive() || $includeInactive ? $zone : null;
    }

    public function findByCode(string $code): ?OperationalZone
    {
        foreach ($this->zones as $zone) {
            if (strcasecmp($zone->getCode(), $code) === 0) {
                return $zone;
            }
        }

        return null;
    }

    public function findAllWithCounts(bool $includeInactive = true): array
    {
        $rows = [];
        foreach (array_keys($this->zones) as $zoneId) {
            if ($includeInactive || $this->zones[$zoneId]->isActive()) {
                $rows[] = $this->countsRow($zoneId);
            }
        }

        return $rows;
    }

    public function findWithCounts(int $zoneId): ?array
    {
        return isset($this->zones[$zoneId]) ? $this->countsRow($zoneId) : null;
    }

    private function countsRow(int $zoneId): array
    {
        $primary = 0;
        $backup = 0;
        foreach ($this->restockerRows as $row) {
            if ($row['zone_id'] !== $zoneId || $row['removed_at'] !== null) {
                continue;
            }
            $row['is_primary'] ? $primary++ : $backup++;
        }

        return [
            'zone' => $this->zones[$zoneId],
            'locations_count' => 0,
            'primary_technicians_count' => 0,
            'backup_technicians_count' => 0,
            'primary_restockers_count' => $primary,
            'backup_restockers_count' => $backup,
            'postal_codes_count' => 0,
        ];
    }

    public function countUnassignedLocations(): int
    {
        return 0;
    }

    public function findPostalCodes(int $zoneId): array
    {
        return [];
    }

    public function findPostalCodeConflicts(array $postalCodes, int $targetZoneId): array
    {
        return [];
    }

    public function assignPostalCodes(int $zoneId, array $postalCodes, int $userId): array
    {
        return $postalCodes;
    }

    public function inspectReleaseImpact(int $zoneId): array
    {
        $technicians = 0;
        $restockers = 0;
        foreach ($this->restockerRows as $row) {
            if ($row['zone_id'] === $zoneId && $row['removed_at'] === null) {
                $restockers++;
            }
        }

        return ['postal_codes' => 0, 'technicians' => $technicians, 'restockers' => $restockers, 'locations' => 0];
    }

    public function deactivateZone(int $zoneId, int $userId): array
    {
        $removed = 0;
        foreach ($this->restockerRows as $index => $row) {
            if ($row['zone_id'] === $zoneId && $row['removed_at'] === null) {
                $this->restockerRows[$index]['removed_at'] = '2026-11-11T10:00:00+01:00';
                $removed++;
            }
        }

        $zone = $this->zones[$zoneId];
        $this->zones[$zoneId] = $this->buildZone($zoneId, $zone->getCode(), $zone->getName(), false);

        return [
            'released_postal_codes' => 0,
            'unassigned_locations' => 0,
            'removed_technicians' => 0,
            'removed_restockers' => $removed,
        ];
    }

    public function releasePostalCodes(int $zoneId): int
    {
        return 0;
    }

    public function releasePostalCodesNotIn(int $zoneId, array $keepPostalCodes, int $userId): array
    {
        return ['released_postal_codes' => [], 'recalculated_locations' => 0];
    }

    public function transferPostalCodes(int $newZoneId, array $postalCodes, int $userId): int
    {
        return 0;
    }

    public function findTechnicianAssignments(int $zoneId): array
    {
        return [];
    }

    public function findQuadrantsByTechnician(): array
    {
        return [];
    }

    public function findRestockerAssignments(int $zoneId): array
    {
        return array_values(array_filter(
            $this->restockerRows,
            static fn (array $row): bool => $row['zone_id'] === $zoneId && $row['removed_at'] === null
        ));
    }

    public function findRestockerQuadrantsByTechnician(): array
    {
        $grouped = [];
        foreach ($this->restockerRows as $row) {
            if ($row['removed_at'] !== null) {
                continue;
            }
            $grouped[$row['restocker_id']]['restocker_id'] = $row['restocker_id'];
            $grouped[$row['restocker_id']]['name'] = $row['name'];
            $grouped[$row['restocker_id']]['operator_code'] = $row['operator_code'];
            $grouped[$row['restocker_id']]['is_active'] = $row['is_active'];
            $grouped[$row['restocker_id']]['zones'][] = [
                'zone_id' => $row['zone_id'],
                'code' => $this->zones[$row['zone_id']]->getCode(),
                'name' => $this->zones[$row['zone_id']]->getName(),
                'color_hex' => $this->zones[$row['zone_id']]->getColorHex(),
                'role_in_zone' => $row['role_in_zone'],
                'is_primary' => $row['is_primary'],
                'assigned_at' => $row['assigned_at'],
            ];
        }

        return array_values($grouped);
    }

    public function assignRestocker(
        int $restockerId,
        int $zoneId,
        bool $isPrimary,
        ?int $userId = null,
        bool $demotePreviousPrimary = false
    ): int {
        $this->lastConfirmFlag = $demotePreviousPrimary ? 1 : 0;

        foreach ($this->restockerRows as $index => $row) {
            if ($row['restocker_id'] === $restockerId && $row['zone_id'] === $zoneId && $row['removed_at'] === null) {
                $this->restockerRows[$index]['role_in_zone'] = $isPrimary ? 'PRIMARY' : 'BACKUP';
                $this->restockerRows[$index]['role_label'] = $isPrimary ? 'Titular' : 'Suplente';
                $this->restockerRows[$index]['is_primary'] = $isPrimary;

                return (int)$row['assignment_id'];
            }
        }

        $this->seedRestocker($zoneId, $restockerId, 'Reponedor ' . $restockerId, $isPrimary ? 'PRIMARY' : 'BACKUP');

        return (int)end($this->restockerRows)['assignment_id'];
    }

    public function removeRestocker(int $restockerId, int $zoneId, ?int $userId = null): bool
    {
        foreach ($this->restockerRows as $index => $row) {
            if ($row['restocker_id'] === $restockerId && $row['zone_id'] === $zoneId && $row['removed_at'] === null) {
                $this->restockerRows[$index]['removed_at'] = '2026-11-11T09:30:00+01:00';

                return true;
            }
        }

        return false;
    }

    public function create(array $data, ?int $userId = null): OperationalZone
    {
        $id = max(array_keys($this->zones) ?: [0]) + 1;

        return $this->seedZone($id, (string)$data['code'], (string)$data['name']);
    }

    public function update(int $zoneId, array $data, ?int $userId = null): bool
    {
        return isset($this->zones[$zoneId]);
    }

    public function setActive(int $zoneId, bool $isActive): bool
    {
        if (!isset($this->zones[$zoneId])) {
            return false;
        }
        $zone = $this->zones[$zoneId];
        $this->zones[$zoneId] = $this->buildZone($zoneId, $zone->getCode(), $zone->getName(), $isActive);

        return true;
    }

    public function assignTechnician(
        int $technicianId,
        int $zoneId,
        bool $isPrimary,
        ?int $userId = null,
        bool $demotePreviousPrimary = false
    ): int {
        return 0;
    }

    public function removeTechnician(int $technicianId, int $zoneId, ?int $userId = null): bool
    {
        return false;
    }
}

/**
 * Capacidad territorial de sede: no se ejerce en esta suite.
 */
class CoverageLocationRepository implements LocationZoneRepositoryInterface
{
    public function findById(int $id): ?VendGuard\Core\Domain\Model\Location
    {
        return null;
    }

    public function findInheritedZoneByPostalCode(string $postalCode): ?int
    {
        return null;
    }

    public function setManualOverride(int $locationId, ?int $zoneId, ?int $userId = null): bool
    {
        return false;
    }

    public function clearManualOverride(int $locationId, ?int $userId = null): ?int
    {
        return null;
    }
}

/**
 * Turnos: sólo importa el mapa de estados abiertos que consume la cobertura.
 */
class CoverageShiftRepository implements TechnicianShiftRepositoryInterface
{
    /** @param array<int, string> $map technicianId => shift_status */
    public function __construct(public array $map = [])
    {
    }

    public function findActiveShiftByTechnician(int $technicianId): ?TechnicianShift
    {
        return null;
    }

    public function findById(int $shiftId): ?TechnicianShift
    {
        return null;
    }

    public function startShift(int $technicianId, ?DateTimeImmutable $startedAt = null): TechnicianShift
    {
        throw new LogicException('No utilizado por esta suite.');
    }

    public function pauseMeal(int $shiftId, ?DateTimeImmutable $pausedAt = null): TechnicianShift
    {
        throw new LogicException('No utilizado por esta suite.');
    }

    public function resumeShift(int $shiftId, ?DateTimeImmutable $resumedAt = null): TechnicianShift
    {
        throw new LogicException('No utilizado por esta suite.');
    }

    public function closeShift(
        int $shiftId,
        int $closedBy,
        ?string $closeReason = null,
        ?DateTimeImmutable $closedAt = null,
        bool $isForceClosed = false,
        ?DateTimeImmutable $executionTime = null
    ): TechnicianShift {
        throw new LogicException('No utilizado por esta suite.');
    }

    public function saveAdjustment(TechnicianShift $adjustedShift): TechnicianShift
    {
        return $adjustedShift;
    }

    public function findOverlappingShiftId(
        int $technicianId,
        DateTimeImmutable $startedAt,
        DateTimeImmutable $closedAt,
        ?int $excludeShiftId = null
    ): ?int {
        return null;
    }

    public function findShiftsByDateRange(string $fromDate, string $toDate, ?int $technicianId = null, ?string $role = null): array
    {
        return [];
    }

    public function findOrphanOpenShiftsBefore(string $beforeDate, ?int $technicianId = null, ?string $role = null): array
    {
        return [];
    }

    public function getClosedInterventionMetricsByTechnician(
        DateTimeImmutable $from,
        DateTimeImmutable $to,
        ?int $technicianId = null,
        ?string $role = null
    ): array {
        return [];
    }

    public function getActiveShiftsMap(?string $role = null): array
    {
        return $this->map;
    }
}

/**
 * Ausencias: sólo importa el mapa de vigentes en el instante evaluado.
 */
class CoverageAbsenceRepository implements TechnicianAbsenceRepositoryInterface
{
    /** @param array<int, true> $active technicianId => true */
    public function __construct(public array $active = [])
    {
    }

    public function create(
        int $technicianId,
        AbsenceReason $reason,
        DateTimeImmutable $startsAt,
        DateTimeImmutable $endsAt,
        ?string $notes,
        int $createdBy
    ): TechnicianAbsence {
        throw new LogicException('No utilizado por esta suite.');
    }

    public function findById(int $id): ?TechnicianAbsence
    {
        return null;
    }

    public function findAll(?int $technicianId = null, ?string $status = null, ?string $role = null): array
    {
        return [];
    }

    public function findActiveAbsenceForTechnician(int $technicianId, ?DateTimeImmutable $referenceTime = null): ?TechnicianAbsence
    {
        return null;
    }

    public function findActiveAbsencesMap(DateTimeImmutable $at): array
    {
        return $this->active;
    }

    public function hasOverlappingAbsence(
        int $technicianId,
        DateTimeImmutable $startsAt,
        DateTimeImmutable $endsAt,
        ?int $excludeAbsenceId = null
    ): bool {
        return false;
    }

    public function cancel(int $id, int $cancelledBy, string $cancellationReason): bool
    {
        return false;
    }
}

// ─────────────────────────────────────────────────────────────────────────
// Escenario
// ─────────────────────────────────────────────────────────────────────────

$zones = new CoverageZoneRepository();
$zones->seedZone(1, 'ZONA-NORTE', 'Zona Norte');
$zones->seedRestocker(1, 12, 'Lucía Gómez', 'PRIMARY', true, '2026-10-15T08:00:00+01:00');
$zones->seedRestocker(1, 14, 'Carlos Ruiz', 'BACKUP', true, '2026-11-01T08:00:00+01:00');

$shifts = new CoverageShiftRepository(['12' => 'ON_SHIFT']);
$absences = new CoverageAbsenceRepository();

$coverage = new RestockerZoneCoverageService($zones, $shifts, $absences);
$zoneService = new OperationalZoneService($zones, new CoverageLocationRepository(), $absences);
$at = new DateTimeImmutable('2026-11-11T09:00:00+01:00');

// ─────────────────────────────────────────────────────────────────────────
// 1. Cuadrante de carga en OperationalZoneService (RF-02)
// ─────────────────────────────────────────────────────────────────────────

echo "\n--- 1. Cuadrante de carga en la zona (RF-02, D2) ---\n";

$assigned = $zoneService->assignRestocker(16, 1, true, 99);

$assert(
    '1.1 El alta devuelve la vinculación viva con su rol y su etiqueta',
    $assigned['assignment']['restocker_id'] === 16
        && $assigned['assignment']['role_in_zone'] === 'PRIMARY'
        && $assigned['assignment']['role_label'] === 'Titular'
        && $assigned['assignment']['is_primary'] === true,
    json_encode($assigned['assignment'])
);

$zoneService->assignRestocker(17, 1, true, 99, true);

$assert(
    '1.2 El traslado confirmado llega al puerto como degradación de la titularidad previa',
    $zones->lastConfirmFlag === 1
);

$zoneService->assignRestocker(18, 1, false, 99, true);

$assert(
    '1.3 La confirmación no se aplica a una suplencia: no hay titularidad que trasladar',
    $zones->lastConfirmFlag === 0
);

$removedFirst = $zoneService->removeRestocker(17, 1, 99);
$removedAgain = $zoneService->removeRestocker(17, 1, 99);

$assert(
    '1.4 La baja del cuadrante es idempotente',
    $removedFirst === true && $removedAgain === false
);

$assert(
    '1.5 Retirar de una zona inexistente responde 404',
    $throws(static fn () => $zoneService->removeRestocker(17, 404, 99)) instanceof ZoneNotFoundException
);

// Zona 3: las tres insignias del cuadrante.
$zones->seedZone(3, 'ZONA-SUR', 'Zona Sur');
$zones->seedRestocker(3, 20, 'Reponedor Activo', 'PRIMARY');
$zones->seedRestocker(3, 21, 'Reponedor Ausente', 'BACKUP');
$zones->seedRestocker(3, 22, 'Reponedor de Baja', 'BACKUP', false);
$absences->active = [21 => true];

$quadrant = $zoneService->listZoneRestockers(3, $at);
$badges = array_column($quadrant['restockers'], 'availability_badge', 'restocker_id');

$assert(
    '1.6 La insignia distingue activo, ausente y baja sin ocultar a nadie',
    $badges[20] === 'ACTIVO' && $badges[21] === 'AUSENTE' && $badges[22] === 'BAJA',
    json_encode($badges)
);

$fullQuadrant = $zoneService->listZoneQuadrant(3);

$assert(
    '1.7 La ficha de zona separa el cuadrante SAT del Cuadrante de Carga',
    array_key_exists('technicians', $fullQuadrant)
        && array_key_exists('restockers', $fullQuadrant)
        && $fullQuadrant['technicians'] === []
        && count($fullQuadrant['restockers']) === 3
);

$inverse = $zoneService->listRestockerQuadrants();

$assert(
    '1.8 La consulta inversa agrupa las zonas vivas de cada reponedor',
    $inverse !== [] && isset($inverse[0]['restocker_id'], $inverse[0]['zones'])
);

// ─────────────────────────────────────────────────────────────────────────
// 2. Liberación al desactivar la zona (RF-02.3)
// ─────────────────────────────────────────────────────────────────────────

echo "\n--- 2. Liberación del cuadrante al desactivar la zona (RF-02.3) ---\n";

$zones->seedZone(4, 'ZONA-ESTE', 'Zona Este');
$zones->seedRestocker(4, 30, 'Reponedor Este', 'PRIMARY');

$blocked = $throws(static fn () => $zoneService->deactivateZone(4, 99));

$assert(
    '2.1 Una zona con reponedores vivos exige confirm_release',
    $blocked instanceof ZoneValidationException
        && str_contains($blocked->getMessage(), 'reponedores')
);

$deactivated = $zoneService->deactivateZone(4, 99, true);

$assert(
    '2.2 Confirmada la baja, la zona queda inactiva y su cuadrante de carga extinguido',
    $deactivated->isActive === false
        && $zoneService->listZoneRestockers(4)['restockers'] === []
        && $deactivated->primaryRestockersCount === 0
        && $deactivated->backupRestockersCount === 0
);

$assert(
    '2.3 Los contadores de carga viven en la ficha de la zona (QA 2)',
    array_key_exists('primary_restockers_count', $zones->findWithCounts(3) ?? [])
        && ($zones->findWithCounts(3)['primary_restockers_count'] ?? 0) === 1
        && ($zones->findWithCounts(3)['backup_restockers_count'] ?? 0) === 2
);

// ─────────────────────────────────────────────────────────────────────────
// 3. Cobertura en vivo (RF-03, C2, QA 3)
// ─────────────────────────────────────────────────────────────────────────

echo "\n--- 3. Cobertura de carga y promoción del suplente (RF-03) ---\n";

// Zona 7 con el cuadrante mínimo de cobertura: un titular y un suplente.
$zones->seedZone(7, 'ZONA-COBERTURA', 'Zona Cobertura');
$zones->seedRestocker(7, 50, 'Lucía Gómez', 'PRIMARY', true, '2026-10-15T08:00:00+01:00');
$zones->seedRestocker(7, 51, 'Carlos Ruiz', 'BACKUP', true, '2026-11-01T08:00:00+01:00');

$setRestockerActive = static function (int $restockerId, bool $active) use ($zones): void {
    foreach ($zones->restockerRows as $index => $row) {
        if ($row['restocker_id'] === $restockerId) {
            $zones->restockerRows[$index]['is_active'] = $active;
        }
    }
};

$candidateById = static function (array $result, int $restockerId): array {
    foreach ($result['candidates'] as $candidate) {
        if ($candidate['restocker_id'] === $restockerId) {
            return $candidate;
        }
    }

    return [];
};

$shifts->map = ['50' => 'ON_SHIFT'];
$primaryOnShift = $coverage->getZoneCoverage(7, $at);

$assert(
    '3.1 El titular en turno encabeza la cobertura como #1 RECOMENDADO',
    $primaryOnShift['coverage_status'] === RestockerZoneCoverageRanker::COVERAGE_COVERED_BY_PRIMARY
        && $primaryOnShift['recommended_restocker']['restocker_id'] === 50
        && $primaryOnShift['recommended_restocker']['tier_rank'] === 1
        && !$primaryOnShift['recommended_restocker']['is_promoted_backup'],
    (string)$primaryOnShift['coverage_status']
);

$assert(
    '3.2 La recomendación declara su etiqueta de titularidad',
    str_contains(
        (string)$primaryOnShift['recommended_restocker']['recommendation_label'],
        RestockerZoneCoverageRanker::PRIMARY_COVERAGE_LABEL
    )
);

$shifts->map = ['50' => 'OFF_SHIFT', '51' => 'ON_SHIFT'];
$backupPromoted = $coverage->getZoneCoverage(7, $at);

$assert(
    '3.3 El suplente en turno se promueve cuando el titular está fuera de turno',
    $backupPromoted['coverage_status'] === RestockerZoneCoverageRanker::COVERAGE_COVERED_BY_PROMOTED_BACKUP
        && $backupPromoted['recommended_restocker']['restocker_id'] === 51
        && $backupPromoted['recommended_restocker']['is_promoted_backup'] === true
        && $backupPromoted['recommended_restocker']['tier_rank'] === 2
);

$assert(
    '3.4 La etiqueta explica la promoción automática',
    str_contains(
        (string)$backupPromoted['recommended_restocker']['recommendation_label'],
        RestockerZoneCoverageRanker::PROMOTION_LABEL
    )
);

$shifts->map = ['50' => 'OFF_SHIFT', '51' => 'MEAL_BREAK'];
$meal = $coverage->getZoneCoverage(7, $at);

$assert(
    '3.5 La pausa de comida conserva el orden del escalafón (nivel 4 para el suplente)',
    $meal['recommended_restocker']['restocker_id'] === 51
        && $meal['recommended_restocker']['tier_rank'] === 4
        && $meal['recommended_restocker']['coverage_tier'] === 'BACKUP_PROMOTED_MEAL_BREAK'
);

$shifts->map = ['50' => 'OFF_SHIFT', '51' => 'OFF_SHIFT'];
$absences->active = [50 => true];
$absent = $coverage->getZoneCoverage(7, $at);
$absentPrimary = $candidateById($absent, 50);

$assert(
    '3.6 La ausencia vigente del titular promueve al suplente y marca al titular como no disponible',
    $absent['recommended_restocker']['restocker_id'] === 51
        && $absent['recommended_restocker']['tier_rank'] === 6
        && $absentPrimary['coverage_tier'] === 'UNAVAILABLE'
        && str_starts_with(
            (string)$absentPrimary['unavailable_reason'],
            RestockerZoneCoverageRanker::UNAVAILABLE_ABSENCE_PREFIX
        )
);

$shifts->map = ['50' => 'ON_SHIFT'];
$precedence = $coverage->getZoneCoverage(7, $at);

$assert(
    '3.7 La presencia física en turno prevalece sobre la ausencia planificada (C2)',
    $precedence['recommended_restocker']['restocker_id'] === 50
        && $precedence['recommended_restocker']['tier_rank'] === 1
        && $precedence['recommended_restocker']['has_overridden_absence'] === true
);

$setRestockerActive(50, false);
$absences->active = [];
$shifts->map = ['51' => 'ON_SHIFT'];
$inactive = $coverage->getZoneCoverage(7, $at);
$inactivePrimary = $candidateById($inactive, 50);

$assert(
    '3.8 Una cuenta desactivada queda NO_DISPONIBLE con su motivo y no cubre la zona',
    $inactivePrimary['is_active'] === false
        && $inactivePrimary['unavailable_reason'] === RestockerZoneCoverageRanker::UNAVAILABLE_INACTIVE
        && $inactive['recommended_restocker']['restocker_id'] === 51
);

$setRestockerActive(50, true);

$zones->seedZone(5, 'ZONA-OESTE', 'Zona Oeste');
$zones->seedRestocker(5, 40, 'Reponedor Antiguo', 'PRIMARY', true, '2026-09-01T08:00:00+02:00');
$zones->seedRestocker(5, 41, 'Reponedor Nuevo', 'PRIMARY', true, '2026-10-20T08:00:00+02:00');
$shifts->map = [];
$absences->active = [];
$tieBreak = $coverage->getZoneCoverage(5, $at);

$assert(
    '3.9 El empate en el mismo escalón lo resuelve la antigüedad en la zona',
    $tieBreak['recommended_restocker']['restocker_id'] === 40
        && array_column($tieBreak['candidates'], 'restocker_id') === [40, 41]
);

$before = $zones->restockerRows;
$first = $coverage->getZoneCoverage(5, $at);
$second = $coverage->getZoneCoverage(5, $at);

$assert(
    '3.10 Dos lecturas del mismo instante devuelven lo mismo y no mutan la foto',
    $first === $second && $zones->restockerRows === $before
);

$zones->seedZone(6, 'ZONA-VACIA', 'Zona Vacía');
$uncovered = $coverage->getZoneCoverage(6, $at);

$assert(
    '3.11 Una zona sin cuadrante queda UNCOVERED sin recomendación inventada',
    $uncovered['coverage_status'] === RestockerZoneCoverageRanker::COVERAGE_UNCOVERED
        && $uncovered['recommended_restocker'] === null
        && $uncovered['candidates'] === []
);

$assert(
    '3.12 Consultar una zona inexistente responde 404 ZONE_NOT_FOUND',
    $throws(static fn () => $coverage->getZoneCoverage(999, $at)) instanceof ZoneNotFoundException
);

echo "\n======================================================================\n";
echo " Total Aserciones: {$assertions} | Exitosas: " . ($assertions - $failures) . " | Fallidas: {$failures}\n";

if ($failures === 0) {
    echo " RESULTADO: 100% EN VERDE. CUADRANTE DE CARGA Y COBERTURA CERTIFICADOS.\n";
    echo "======================================================================\n";
    exit(0);
}

echo " RESULTADO: FALLOS DETECTADOS EN EL CUADRANTE DE CARGA O EN LA COBERTURA.\n";
echo "======================================================================\n";
exit(1);
