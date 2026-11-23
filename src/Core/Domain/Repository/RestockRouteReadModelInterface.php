<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Repository;

use VendGuard\Core\Domain\Model\MachineRestockSchedule;
use VendGuard\Core\Domain\Model\RestockRouteDailyOverride;

/**
 * RestockRouteReadModelInterface
 *
 * Puerto de lectura del Planificador de Rutas (Módulo 37; RF-01–RF-03,
 * RF-05.1, D1–D7, C1–C3, QA-1–QA-6, Art. II, Art. V.4 y Art. VI).
 *
 * El puerto devuelve fotos ya resueltas por el motor —máquinas con sus hechos
 * de agenda, FEFO, carriles vacíos, avisos L1, visitas y excepción viva; y
 * reponedores con su demarcación, turno y ausencia— para que los servicios
 * compongan la ruta sin consultas N+1 y sin conocer el esquema. La decisión de
 * propiedad, la clasificación en bloques y la opacidad se resuelven en la capa
 * de aplicación.
 *
 * Se separa del puerto transaccional (`RestockRoutePlannerGatewayInterface`)
 * porque la composición diaria es una lectura masiva y acotada, mientras que
 * las escrituras exigen bloqueo pesimista y unidad de trabajo. Un servicio de
 * solo lectura no debe arrastrar el contrato de escritura completo.
 *
 * Dogma Vanilla: PHP 8.2+ puro, sin dependencias externas.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */
interface RestockRouteReadModelInterface
{
    /**
     * Foto completa del parque para una fecha civil.
     *
     * Solo incluye máquinas y sedes vivas; las máquinas inactivas se publican
     * con `is_active = false` para que Coordinación pueda rechazar su
     * configuración con el código exacto del contrato.
     *
     * @return array{
     *     machines: list<array<string, mixed>>,
     *     restockers: list<array<string, mixed>>,
     *     overrides: list<array<string, mixed>>
     * }
     */
    public function loadParkSnapshot(string $serviceDate): array;

    /**
     * Hechos de una máquina concreta, o `null` si no existe o está eliminada.
     *
     * @return array<string, mixed>|null
     */
    public function loadMachineFacts(int $machineId, string $serviceDate): ?array;

    /**
     * Reponedores activos con demarcación, turno y ausencia de la fecha.
     *
     * @return list<array<string, mixed>>
     */
    public function listRestockers(string $serviceDate): array;

    /**
     * Reponedor activo con rol `RESTOCKER`, o `null` si no procede.
     *
     * @return array<string, mixed>|null
     */
    public function findRestocker(int $restockerId): ?array;

    /**
     * Agenda por identificador, incluida una versión ya cerrada.
     */
    public function findScheduleById(int $scheduleId): ?MachineRestockSchedule;

    /**
     * Excepción diaria por identificador, en cualquier estado.
     */
    public function findOverrideById(int $overrideId): ?RestockRouteDailyOverride;
}
