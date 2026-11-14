<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Repository;

use VendGuard\Core\Domain\Model\MachinePlanogramSlot;
use VendGuard\Core\Domain\Model\MachineType;

/**
 * InSituRestockVisitGatewayInterface
 *
 * Puerto de persistencia de los efectos que la visita de reposición in situ
 * necesita fuera de sus Tablas 47/48 (Módulo 34, RF-02.1–RF-02.3, RF-03.3,
 * RF-04.2, D1–D4, C1–C3, QA 1–QA 4, Art. II y Art. III.3).
 *
 * El servicio no conoce PDO ni el esquema: pide la fotografía bloqueada de la
 * máquina, los carriles bloqueados, el coste interno que debe congelar, el paso
 * a cuarentena/fuera de servicio y la creación o escalado idempotente del
 * ticket térmico. La implementación PDO concentra esas sentencias y mantiene
 * las visitas inmutables en su propio repositorio.
 *
 * El contexto devuelto usa estas claves:
 *   - `id`, `machine_code`, `machine_type` (MachineType), `location_id`,
 *     `sanitary_status`, `is_out_of_service`, `is_covered`,
 *     `has_open_thermal_breakdown`, `active_incident_id`, `loading_order_id`.
 */
interface InSituRestockVisitGatewayInterface
{
    /**
     * Bloquea la máquina del reponedor y devuelve sus hechos de cerrojo.
     *
     * `null` significa que la máquina no existe o está dada de baja; una máquina
     * existente pero fuera del perímetro devuelve `is_covered = false`.
     *
     * @return array<string, mixed>|null
     */
    public function lockMachineContext(int $machineId, int $restockerId): ?array;

    /**
     * Carriles activos de la máquina bloqueados con `SELECT ... FOR UPDATE`.
     *
     * @return list<MachinePlanogramSlot>
     */
    public function lockActiveSlots(int $machineId): array;

    /**
     * Coste unitario interno del producto, congelado en la línea de visita.
     *
     * Solo lo consume el servicio para el snapshot de `QA 3`; nunca se expone al
     * reponedor (Art. V.4).
     */
    public function findProductUnitCost(int $productId): float;

    /**
     * Deja la máquina fuera de servicio por rotura de cadena de frío.
     *
     * En `PERISHABLE_FOOD` y `COMBO` pasa `sanitary_status` a `QUARANTINE`; en
     * `COLD_DRINKS` materializa `OUT_OF_SERVICE` con `is_blocked_no_access`.
     */
    public function lockMachineSanitarily(int $machineId, MachineType $machineType, string $reason): void;

    /**
     * Crea o escala el ticket `CRITICAL` de la avería térmica y devuelve su ID.
     *
     * Si `existingIncidentId` no es nulo eleva su urgencia, anexa una nota
     * interna con temperatura/foto/notas y la vincula a la visita (`QA 2`). Si no
     * hay incidencia activa crea una nueva con categoría térmica, la deja en
     * `REGISTERED` —la asignación SAT sugerida puede completarla Coordinación— y
     * encola la alerta de frío. El alta es idempotente ante la carrera de un
     * ticket activo simultáneo.
     *
     * @param array<string, mixed> $actor Usuario autenticado (`id`, `role`, `name`).
     */
    public function resolveThermalIncident(
        int $machineId,
        string $machineCode,
        int $locationId,
        ?int $existingIncidentId,
        float $cabinTemp,
        string $photoUrl,
        string $notes,
        array $actor
    ): int;
}
