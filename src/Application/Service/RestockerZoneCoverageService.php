<?php

declare(strict_types=1);

namespace VendGuard\Application\Service;

use DateTimeImmutable;
use DateTimeZone;
use VendGuard\Core\Domain\Exception\ZoneNotFoundException;
use VendGuard\Core\Domain\Repository\OperationalZoneRepositoryInterface;
use VendGuard\Core\Domain\Repository\TechnicianAbsenceRepositoryInterface;
use VendGuard\Core\Domain\Repository\TechnicianShiftRepositoryInterface;
use VendGuard\Core\Service\RestockerZoneCoverageRanker;

/**
 * RestockerZoneCoverageService
 *
 * Caso de uso de la cobertura de carga de una zona operativa (Módulo 31,
 * RF-03.1 a RF-03.3, `C2`, `D3`, `QA 3`).
 *
 * El servicio **no decide el orden**: compone la foto del cuadrante vivo con
 * tres lecturas —las vinculaciones de la zona, el estado de turno de cada
 * operario y las ausencias vigentes en el instante de evaluación— y delega en
 * `RestockerZoneCoverageRanker`, el calculador puro de dominio. Así el
 * escalafón tiene una sola implementación y sigue siendo determinista sin base
 * de datos (RNF-01, RNF-02).
 *
 * REGLAS QUE VIVEN AQUÍ
 *
 *   - La zona debe existir; una zona desactivada sigue publicando su cobertura
 *     real, porque el coordinador necesita ver qué quedó tras la baja. Sólo la
 *     inexistente responde `404 ZONE_NOT_FOUND`.
 *   - El reloj por defecto es `Europe/Madrid`, la zona operativa del proyecto;
 *     el parámetro explícito existe para que las pruebas y la consola puedan
 *     evaluar un instante concreto sin tocar el reloj del sistema.
 *   - Un operario sin turno abierto cuenta como `OFF_SHIFT`, y sin fila en el
 *     mapa de ausencias como disponible. La ausencia sólo lo aparta si además
 *     está fuera de turno: la presencia física manda (`C2`, EARS 03.3).
 *
 * Dogma Vanilla: PHP 8.2+ puro, cero dependencias externas.
 * Dualismo Lingüístico: código en inglés, documentación en castellano.
 */
final class RestockerZoneCoverageService
{
    public function __construct(
        private readonly OperationalZoneRepositoryInterface $zoneRepo,
        private readonly TechnicianShiftRepositoryInterface $shiftRepo,
        private readonly TechnicianAbsenceRepositoryInterface $absenceRepo
    ) {
    }

    /**
     * Estado de cobertura en vivo de una zona y reponedor recomendado.
     *
     * @param int $zoneId Identificador de la zona operativa.
     * @param DateTimeImmutable|null $now Instante de evaluación; por defecto, ahora en Madrid.
     * @return array{
     *     zone_id: int,
     *     zone_code: string,
     *     zone_name: string,
     *     coverage_status: string,
     *     recommended_restocker: array<string, mixed>|null,
     *     candidates: list<array<string, mixed>>
     * }
     *
     * @throws ZoneNotFoundException Si la zona no existe.
     */
    public function getZoneCoverage(int $zoneId, ?DateTimeImmutable $now = null): array
    {
        $zone = $this->zoneRepo->findById($zoneId, true);
        if ($zone === null) {
            throw new ZoneNotFoundException($zoneId);
        }

        $at = $now ?? new DateTimeImmutable('now', new DateTimeZone('Europe/Madrid'));

        // Dos lecturas globales y acotadas, no una por candidato: el panel paga
        // tres consultas por zona como máximo, independientemente del tamaño
        // del cuadrante (RNF-02).
        $shiftStatuses = $this->shiftRepo->getActiveShiftsMap();
        $activeAbsences = $this->absenceRepo->findActiveAbsencesMap($at);

        $candidates = [];
        foreach ($this->zoneRepo->findRestockerAssignments($zoneId) as $row) {
            $restockerId = (int)$row['restocker_id'];

            $candidates[] = [
                'restocker_id' => $restockerId,
                'name' => (string)$row['name'],
                'operator_code' => $row['operator_code'],
                'role_in_zone' => (string)$row['role_in_zone'],
                'is_primary' => (bool)$row['is_primary'],
                'is_active' => (bool)$row['is_active'],
                'shift_status' => $shiftStatuses[$restockerId] ?? 'OFF_SHIFT',
                'has_active_absence' => isset($activeAbsences[$restockerId]),
                'assigned_at' => $row['assigned_at'],
            ];
        }

        $ranking = RestockerZoneCoverageRanker::rank($candidates);

        return [
            'zone_id' => (int)$zone->getId(),
            'zone_code' => $zone->getCode(),
            'zone_name' => $zone->getName(),
            'coverage_status' => $ranking['coverage_status'],
            'recommended_restocker' => $ranking['recommended_restocker'],
            'candidates' => $ranking['candidates'],
        ];
    }
}
