<?php

declare(strict_types=1);

namespace VendGuard\Application\Service;

/**
 * VanStockReadinessCalculator
 *
 * Calculador puro del indicador `van_stock_readiness` de las tarjetas de avería
 * activa (Módulo 23, RF-05.4, contrato §3.3 y §5.13).
 *
 * El cálculo es **informativo**: no escribe, no cambia el estado de la avería y
 * no reactiva solicitudes de repuesto (C5, Art. III.3). Sólo responde a una
 * pregunta: ¿el técnico asignado lleva hoy en su furgoneta lo que esta avería
 * necesita, y alcanza ese saldo para todas sus averías activas que piden la
 * misma pieza?
 *
 * Cinco estados, con la precedencia del contrato:
 *
 *   1. `HAS_OUT_OF_CATALOG` — la avería tiene alguna solicitud `PENDING` fuera de
 *      catálogo (`spare_part_id IS NULL`). Manda sobre cualquier cobertura
 *      catalogada: no se afirma disponibilidad completa si falta un componente
 *      que no está en el catálogo (QA 2).
 *   2. `NOT_READY` — el saldo no cubre alguna de las piezas catalogadas de esta
 *      avería.
 *   3. `SHARED_IN_VAN` — cubre esta avería, pero el saldo no alcanza para la
 *      demanda agregada de la misma pieza en todas las averías activas del
 *      técnico (QA 3).
 *   4. `READY_IN_VAN` — cubre esta avería y la demanda agregada.
 *   5. `NOT_APPLICABLE` — la avería no está activa, no tiene técnico asignado o
 *      no tiene solicitudes `PENDING`.
 *
 * Los saldos negativos por descuadre cuentan como `0` unidades disponibles: un
 * saldo negativo nunca cubre una solicitud. La agregación sólo considera las
 * averías activas que recibe el calculador, de modo que el llamador decide el
 * universo (el listado operativo completo) y aquí no hay consultas ocultas.
 *
 * Dogma Vanilla: PHP 8.2+ puro, funciones puras y sin dependencias.
 * Dualismo Lingüístico: código en inglés, documentación y etiquetas en castellano.
 */
final class VanStockReadinessCalculator
{
    public const STATUS_READY_IN_VAN = 'READY_IN_VAN';
    public const STATUS_SHARED_IN_VAN = 'SHARED_IN_VAN';
    public const STATUS_NOT_READY = 'NOT_READY';
    public const STATUS_HAS_OUT_OF_CATALOG = 'HAS_OUT_OF_CATALOG';
    public const STATUS_NOT_APPLICABLE = 'NOT_APPLICABLE';

    public const BADGE_READY_IN_VAN = '📦 Repuesto ya en furgoneta';
    public const BADGE_SHARED_IN_VAN = '📦 Repuesto en furgoneta (stock compartido)';

    /** @var list<string> Estados de avería que cuentan como activos. */
    public const ACTIVE_INCIDENT_STATUSES = [
        'ASSIGNED',
        'IN_PROGRESS',
        'PENDING_PARTS',
        'PENDING_INFO',
        'REOPENED',
    ];

    /**
     * Calcula el indicador de todas las averías recibidas.
     *
     * @param list<array{incident_id: int, status: string, assigned_technician_id: int|null}> $activeIncidents
     *        Averías del listado operativo, con su estado y técnico asignado.
     * @param array<int, array{catalog: array<int, int>, out_of_catalog: int}> $pendingRequestsByIncident
     *        Solicitudes `PENDING` por avería: cantidades catalogadas indexadas por
     *        `spare_part_id` y número de solicitudes fuera de catálogo.
     * @param array<int, array<int, int>> $vanStockByTechnicianAndPart
     *        Saldos de `technician_van_stocks`: técnico => pieza => unidades.
     * @return array<int, array{
     *   status: string,
     *   badge_label: string|null,
     *   covered_references: int,
     *   total_catalog_references: int,
     *   has_out_of_catalog_requests: bool
     * }> Indicador indexado por `incident_id`, en el orden de entrada.
     */
    public function calculate(
        array $activeIncidents,
        array $pendingRequestsByIncident,
        array $vanStockByTechnicianAndPart
    ): array {
        $aggregateDemand = $this->aggregateCatalogDemandByTechnician(
            $activeIncidents,
            $pendingRequestsByIncident
        );

        $readiness = [];
        foreach ($activeIncidents as $incident) {
            $incidentId = (int)$incident['incident_id'];
            $technicianId = $incident['assigned_technician_id'] !== null
                ? (int)$incident['assigned_technician_id']
                : null;

            $catalog = $pendingRequestsByIncident[$incidentId]['catalog'] ?? [];
            $outOfCatalog = (int)($pendingRequestsByIncident[$incidentId]['out_of_catalog'] ?? 0);
            $totalCatalogReferences = count($catalog);

            if (
                !in_array((string)$incident['status'], self::ACTIVE_INCIDENT_STATUSES, true)
                || $technicianId === null
                || ($totalCatalogReferences === 0 && $outOfCatalog === 0)
            ) {
                $readiness[$incidentId] = $this->entry(
                    self::STATUS_NOT_APPLICABLE,
                    0,
                    0,
                    false
                );
                continue;
            }

            $technicianStock = $vanStockByTechnicianAndPart[$technicianId] ?? [];

            $coveredReferences = 0;
            $coversThisIncident = true;
            foreach ($catalog as $sparePartId => $requestedQty) {
                $available = $this->availableFor($technicianStock, (int)$sparePartId);
                if ($available >= (int)$requestedQty) {
                    $coveredReferences++;
                } else {
                    $coversThisIncident = false;
                }
            }

            // QA 2: una solicitud fuera de catálogo impide afirmar cobertura
            // completa, aunque las referencias catalogadas estén cubiertas.
            if ($outOfCatalog > 0) {
                $readiness[$incidentId] = $this->entry(
                    self::STATUS_HAS_OUT_OF_CATALOG,
                    $coveredReferences,
                    $totalCatalogReferences,
                    true
                );
                continue;
            }

            if (!$coversThisIncident) {
                $readiness[$incidentId] = $this->entry(
                    self::STATUS_NOT_READY,
                    $coveredReferences,
                    $totalCatalogReferences,
                    false
                );
                continue;
            }

            $shared = false;
            foreach ($catalog as $sparePartId => $requestedQty) {
                $sparePartId = (int)$sparePartId;
                $available = $this->availableFor($technicianStock, $sparePartId);
                $totalDemand = $aggregateDemand[$technicianId][$sparePartId] ?? (int)$requestedQty;
                if ($available < $totalDemand) {
                    $shared = true;
                    break;
                }
            }

            $readiness[$incidentId] = $this->entry(
                $shared ? self::STATUS_SHARED_IN_VAN : self::STATUS_READY_IN_VAN,
                $coveredReferences,
                $totalCatalogReferences,
                false
            );
        }

        return $readiness;
    }

    /**
     * Demanda agregada de cada pieza por técnico: suma las cantidades `PENDING`
     * catalogadas de todas las averías activas del mismo técnico.
     *
     * @param list<array{incident_id: int, status: string, assigned_technician_id: int|null}> $activeIncidents
     * @param array<int, array{catalog: array<int, int>, out_of_catalog: int}> $pendingRequestsByIncident
     * @return array<int, array<int, int>>
     */
    private function aggregateCatalogDemandByTechnician(
        array $activeIncidents,
        array $pendingRequestsByIncident
    ): array {
        $demand = [];
        foreach ($activeIncidents as $incident) {
            if (
                $incident['assigned_technician_id'] === null
                || !in_array((string)$incident['status'], self::ACTIVE_INCIDENT_STATUSES, true)
            ) {
                continue;
            }

            $technicianId = (int)$incident['assigned_technician_id'];
            $incidentId = (int)$incident['incident_id'];
            foreach ($pendingRequestsByIncident[$incidentId]['catalog'] ?? [] as $sparePartId => $requestedQty) {
                $sparePartId = (int)$sparePartId;
                $demand[$technicianId][$sparePartId] = ($demand[$technicianId][$sparePartId] ?? 0)
                    + (int)$requestedQty;
            }
        }

        return $demand;
    }

    /**
     * Saldo disponible de una pieza. Un descuadre negativo no dispone de nada.
     *
     * @param array<int, int> $technicianStock
     */
    private function availableFor(array $technicianStock, int $sparePartId): int
    {
        return max(0, (int)($technicianStock[$sparePartId] ?? 0));
    }

    /**
     * @return array{
     *   status: string,
     *   badge_label: string|null,
     *   covered_references: int,
     *   total_catalog_references: int,
     *   has_out_of_catalog_requests: bool
     * }
     */
    private function entry(
        string $status,
        int $coveredReferences,
        int $totalCatalogReferences,
        bool $hasOutOfCatalogRequests
    ): array {
        return [
            'status' => $status,
            'badge_label' => self::badgeFor($status),
            'covered_references' => $coveredReferences,
            'total_catalog_references' => $totalCatalogReferences,
            'has_out_of_catalog_requests' => $hasOutOfCatalogRequests,
        ];
    }

    /**
     * Etiqueta de lectura del estado, o `null` si el indicador no debe pintar
     * insignia (Art. V.4: sólo se afirma lo que la furgoneta cubre de verdad).
     */
    public static function badgeFor(string $status): ?string
    {
        return match ($status) {
            self::STATUS_READY_IN_VAN => self::BADGE_READY_IN_VAN,
            self::STATUS_SHARED_IN_VAN => self::BADGE_SHARED_IN_VAN,
            default => null,
        };
    }
}
