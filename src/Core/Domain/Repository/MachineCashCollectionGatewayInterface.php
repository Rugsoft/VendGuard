<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Repository;

/**
 * MachineCashCollectionGatewayInterface
 *
 * Puerto de persistencia de los cerrojos que la recaudación necesita fuera de
 * la Tabla 49 (Módulo 35; RF-02.1, RF-03.1, D3, D4, C4, Art. II.2, Art. V.4).
 *
 * El servicio pide la fotografía bloqueada de la máquina (estado sanitario,
 * cobertura territorial, conserjería física y snapshots del reponedor), la
 * visita in situ vinculada y el expediente de reintegro que se va a liquidar en
 * conserjería. La implementación PDO concentra los `SELECT ... FOR UPDATE` y no
 * conoce ninguna regla de negocio: quien decide es el servicio.
 *
 * Claves del contexto de máquina:
 *   `id`, `machine_code`, `location_id`, `sanitary_status`,
 *   `is_out_of_service`, `is_covered`, `has_open_thermal_breakdown`,
 *   `has_physical_reception`, `restocker_name`, `restocker_code`.
 */
interface MachineCashCollectionGatewayInterface
{
    /**
     * Bloquea la máquina del reponedor y devuelve sus hechos de cerrojo.
     *
     * `null` significa que la máquina no existe, está inactiva o dada de baja.
     *
     * @return array<string, mixed>|null
     */
    public function lockMachineContext(int $machineId, int $restockerId): ?array;

    /**
     * Bloquea la visita in situ que se va a vincular al corte.
     *
     * `null` cuando la visita no existe o pertenece a otra máquina.
     *
     * @return array{id: int, machine_id: int, restocker_id: int, status: string}|null
     */
    public function lockRestockVisit(int $visitId, int $machineId): ?array;

    /**
     * Bloquea el expediente de reintegro antes de liquidarlo en conserjería.
     *
     * `null` cuando el expediente no existe.
     *
     * @return array{id: int, machine_id: int, status: string, compensation_method: string, claimed_amount: float, is_active: bool}|null
     */
    public function lockRefundForReception(int $refundRequestId): ?array;
}
