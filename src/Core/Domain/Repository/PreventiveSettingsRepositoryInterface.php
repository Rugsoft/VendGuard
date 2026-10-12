<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Repository;

use VendGuard\Core\Domain\Model\PreventiveSetting;

/**
 * PreventiveSettingsRepositoryInterface
 * 
 * Contrato de persistencia para la configuración de frecuencias periódicas sanitarias,
 * topes normativos por tipología y control de pausas estacionales (RF-PREV-01).
 */
interface PreventiveSettingsRepositoryInterface
{
    /**
     * Obtiene el catálogo de todas las configuraciones de frecuencia por tipología.
     *
     * @return array<PreventiveSetting>
     */
    public function findAll(): array;

    /**
     * Obtiene la configuración específica para una tipología de dispensación.
     *
     * @param string $machineType
     * @return PreventiveSetting|null
     */
    public function findByMachineType(string $machineType): ?PreventiveSetting;

    /**
     * Actualiza la periodicidad estándar y el tope máximo de una tipología de máquina.
     * Si $machineType es 'PERISHABLE_FOOD' y se superan los 15 días, debe lanzar PerishableFrequencyLimitException.
     *
     * @param string $machineType
     * @param int $defaultFrequencyDays
     * @param int $maxAllowedDays
     * @param int $advanceWarningDays
     * @return bool
     */
    public function updateTypeSettings(
        string $machineType,
        int $defaultFrequencyDays,
        int $maxAllowedDays,
        int $advanceWarningDays = 5
    ): bool;

    /**
     * Actualiza la configuración individual de frecuencia periódica o fecha límite de una máquina.
     * Si la máquina es de perecederos y $sanitaryFrequencyDays > 15, debe lanzar PerishableFrequencyLimitException.
     *
     * @param int $machineId
     * @param int|null $sanitaryFrequencyDays
     * @param string|null $nextSanitaryInspectionDue
     * @return bool
     */
    public function updateMachineConfig(
        int $machineId,
        ?int $sanitaryFrequencyDays,
        ?string $nextSanitaryInspectionDue = null
    ): bool;

    /**
     * Activa el estado de Pausa Estacional / Vaciado Sanitario sobre una máquina.
     * Requiere obligatoriamente un motivo justificado (lanza SeasonalPauseMissingReasonException si está vacío).
     *
     * @param int $machineId
     * @param string $reason
     * @param string|null $pauseUntil
     * @return bool
     */
    public function setSeasonalPause(
        int $machineId,
        string $reason,
        ?string $pauseUntil = null
    ): bool;

    /**
     * Reanuda el servicio de una máquina en Pausa Estacional, reactivando su semáforo
     * y exigiendo revisión previa para perecederos.
     *
     * @param int $machineId
     * @return bool
     */
    public function resumeSeasonalPause(int $machineId): bool;

    /**
     * Recupera la información de configuración preventiva efectiva de una máquina concreta,
     * combinando sus valores propios con los valores por defecto de su tipología.
     *
     * @param int $machineId
     * @return array<string, mixed>|null
     */
    public function getMachineSettings(int $machineId): ?array;

    /**
     * Actualiza el estado higiénico-sanitario de una máquina ('OK', 'ATTENTION_REQUIRED', 'EXPIRED', 'QUARANTINE', 'SEASONAL_PAUSE').
     *
     * La transición es **idempotente (compare-and-set)**: sólo escribe cuando el valor
     * cambia y devuelve `true` únicamente cuando esta llamada es la que lo cambió. Un
     * `false` significa, por tanto, «el valor ya estaba aplicado» o «la fila no existe
     * o no es modificable» — nunca «la sentencia falló», porque los errores de PDO se
     * propagan como excepción.
     *
     * Esa promesa fuerte es la que permite al Reloj Sanitario Biológico arbitrar la
     * carrera entre sus dos consumidores —la puerta sanitaria del cierre técnico
     * (RF-03.5.2) y el barrido de fondo (RF-03.5.1, T-PAUSE-38)— y garantizar un único
     * rastro inmutable por cuarentena (Art. III).
     *
     * @param int $machineId
     * @param string $status
     * @return bool `true` si esta llamada cambió el estado; `false` si ya estaba aplicado.
     */
    public function updateSanitaryStatus(int $machineId, string $status): bool;
}
