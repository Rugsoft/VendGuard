<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Repository;

/**
 * RestockerSatSynergyGatewayInterface
 *
 * Contrato de persistencia transaccional de la sinergia reponedor ↔ SAT
 * (Módulo 36; RF-02.1, RF-03.1, RF-03.2, RF-04.1, RF-04.2, RF-05.1; D1–D4 y C1–C2).
 *
 * Todas las operaciones que cambian el expediente se ejecutan en una única
 * transacción con bloqueo pesimista (`SELECT ... FOR UPDATE`): dos reponedores,
 * o un reponedor y un técnico, no pueden decidir a la vez sobre la misma
 * máquina (Art. V.2 y V.3). Los métodos de lectura no bloquean.
 *
 * Los actores viajan como arrays con `id`, `name`, `operator_code` y `role`; las
 * mutaciones offline como arrays con `client_mutation_id` y
 * `client_occurred_at`. El gateway no compone mensajes de negocio ni decide el
 * estado HTTP final: devuelve `ok`, el error de contrato o el expediente, y la
 * capa de aplicación traduce.
 */
interface RestockerSatSynergyGatewayInterface
{
    /**
     * Inicia un aviso de Nivel 1 asignado al reponedor (EARS 03.1, D3).
     *
     * @param array<string, mixed> $actor
     * @param array<string, mixed>|null $mutation
     * @return array<string, mixed>
     */
    public function startLevel1(int $incidentId, array $actor, ?array $mutation = null): array;

    /**
     * Resuelve un aviso de Nivel 1 sin repuestos ni albarán (EARS 03.1, D3).
     *
     * @param array<string, mixed> $actor
     * @param array<string, mixed> $resolution
     * @param array<string, mixed>|null $mutation
     * @return array<string, mixed>
     */
    public function resolveLevel1(
        int $incidentId,
        array $actor,
        array $resolution,
        ?array $mutation = null
    ): array;

    /**
     * Escala al SAT en sus tres ramas deterministas (EARS 03.2, QA-CONST-02).
     *
     * @param array<string, mixed> $actor
     * @param array<string, mixed> $payload
     * @param array<string, mixed>|null $mutation
     * @return array<string, mixed>
     */
    public function escalateToSat(
        int $machineId,
        array $actor,
        array $payload,
        ?array $mutation = null
    ): array;

    /**
     * Reponedores recomendados para un expediente, con su carga L1 viva (RF-02.1).
     *
     * @return list<array<string, mixed>>
     */
    public function suggestRestockers(int $incidentId): array;

    /**
     * Coincidencia en Sede vista por el Técnico SAT: reponedores activos en la
     * misma sede (EARS 04.1, D4).
     *
     * @param list<int> $locationIds
     * @return array<int, list<array<string, mixed>>>
     */
    public function technicianCoincidences(int $technicianId, array $locationIds): array;

    /**
     * Coincidencia en Sede vista por el Reponedor: técnicos SAT activos en la
     * misma sede (EARS 04.1, D4).
     *
     * @param list<int> $locationIds
     * @return array<int, list<array<string, mixed>>>
     */
    public function restockerCoincidences(int $restockerId, array $locationIds): array;

    /**
     * Bloqueo fail-fast y relevo automático de los avisos L1 al cerrar turno,
     * iniciar una ausencia o dar de baja al reponedor (EARS 04.2, C1).
     *
     * @return array<string, mixed>
     */
    public function handoverLevel1(int $restockerId, string $cause, string $reason): array;
}
