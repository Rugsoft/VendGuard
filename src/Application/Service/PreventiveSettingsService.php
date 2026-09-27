<?php

declare(strict_types=1);

namespace VendGuard\Application\Service;

use InvalidArgumentException;
use VendGuard\Core\Domain\Exception\PerishableFrequencyLimitException;
use VendGuard\Core\Domain\Exception\SeasonalPauseMissingReasonException;
use VendGuard\Core\Domain\Model\PreventiveSetting;
use VendGuard\Core\Domain\Repository\PreventiveSettingsRepositoryInterface;

/**
 * PreventiveSettingsService
 *
 * Servicio de Aplicación responsable de orquestar la configuración de periodicidades sanitarias,
 * el blindaje constitucional del tope de 15 días en perecederos (Art. II) y la gestión
 * auditable de pausas estacionales (RF-PREV-01).
 */
class PreventiveSettingsService
{
    private PreventiveSettingsRepositoryInterface $settingsRepo;
    private AuditLogger $auditLogger;

    public function __construct(
        PreventiveSettingsRepositoryInterface $settingsRepo,
        ?AuditLogger $auditLogger = null
    ) {
        $this->settingsRepo = $settingsRepo;
        $this->auditLogger = $auditLogger ?? new AuditLogger();
    }

    /**
     * Obtiene el catálogo completo de frecuencias y topes normativos por tipología.
     *
     * @return array<PreventiveSetting>
     */
    public function getAllSettings(): array
    {
        return $this->settingsRepo->findAll();
    }

    /**
     * Obtiene la configuración de una tipología de máquina.
     *
     * @param string $machineType
     * @return PreventiveSetting|null
     */
    public function getSettingByType(string $machineType): ?PreventiveSetting
    {
        return $this->settingsRepo->findByMachineType(strtoupper(trim($machineType)));
    }

    /**
     * Actualiza las frecuencias globales de una tipología de máquina, aplicando
     * el blindaje infranqueable del Artículo II de la Constitución en perecederos.
     *
     * @param string $machineType
     * @param int $defaultFrequencyDays
     * @param int $maxAllowedDays
     * @param int $advanceWarningDays
     * @param array{id?: int|null, role?: string, name?: string} $actor
     * @return bool
     * @throws PerishableFrequencyLimitException Si se intentan superar los 15 días en perecederos.
     * @throws InvalidArgumentException Si los valores numéricos son inconsistentes.
     */
    public function updateTypeSettings(
        string $machineType,
        int $defaultFrequencyDays,
        int $maxAllowedDays,
        int $advanceWarningDays = 5,
        array $actor = []
    ): bool {
        $type = strtoupper(trim($machineType));

        if ($defaultFrequencyDays < 1 || $maxAllowedDays < 1 || $advanceWarningDays < 1) {
            throw new InvalidArgumentException('Las frecuencias y días de preaviso deben ser enteros positivos mayores que cero.');
        }

        if ($defaultFrequencyDays > $maxAllowedDays) {
            throw new InvalidArgumentException('La periodicidad estándar no puede ser superior al tope máximo reglamentario.');
        }

        // Blindaje Constitucional Art. II: Máximo 15 días en perecederos
        if ($type === 'PERISHABLE_FOOD' && ($defaultFrequencyDays > 15 || $maxAllowedDays > 15)) {
            $attempted = max($defaultFrequencyDays, $maxAllowedDays);
            throw new PerishableFrequencyLimitException(
                attemptedDays: $attempted,
                maximumAllowedDays: 15,
                machineType: $type
            );
        }

        $previous = $this->settingsRepo->findByMachineType($type);
        $previousState = $previous ? $previous->toArray() : null;

        $updated = $this->settingsRepo->updateTypeSettings(
            $type,
            $defaultFrequencyDays,
            $maxAllowedDays,
            $advanceWarningDays
        );

        if ($updated) {
            $newState = [
                'machine_type' => $type,
                'default_frequency_days' => $defaultFrequencyDays,
                'max_allowed_days' => $maxAllowedDays,
                'advance_warning_days' => $advanceWarningDays,
            ];

            $user = [
                'id' => $actor['id'] ?? null,
                'role' => $actor['role'] ?? 'COORDINATOR',
                'name' => $actor['name'] ?? 'Coordinación',
            ];

            $settingId = $previous ? $previous->getId() : 1;

            $this->auditLogger->logMachineEvent(
                $settingId,
                'UPDATE_PREVENTIVE_TYPE_SETTINGS',
                $user,
                $previousState,
                $newState,
                ['machine_type' => $type]
            );
        }

        return $updated;
    }

    /**
     * Obtiene la configuración preventiva efectiva de una máquina.
     *
     * @param int $machineId
     * @return array<string, mixed>|null
     */
    public function getMachineSettings(int $machineId): ?array
    {
        return $this->settingsRepo->getMachineSettings($machineId);
    }

    /**
     * Modifica la periodicidad individual o fecha de próxima inspección de una máquina concreta,
     * garantizando que no se rebase el límite legal de 15 días si es perecedera (Art. II).
     *
     * @param int $machineId
     * @param int|null $frequencyDays
     * @param string|null $nextSanitaryInspectionDue
     * @param array{id?: int|null, role?: string, name?: string} $actor
     * @return bool
     * @throws PerishableFrequencyLimitException Si se superan 15 días en máquina de perecederos.
     */
    public function updateMachineConfig(
        int $machineId,
        ?int $frequencyDays,
        ?string $nextSanitaryInspectionDue = null,
        array $actor = []
    ): bool {
        $effective = $this->settingsRepo->getMachineSettings($machineId);
        if (!$effective) {
            throw new InvalidArgumentException("Máquina con ID {$machineId} no encontrada.");
        }

        $type = (string)($effective['machine_type'] ?? '');

        // Blindaje Art. II en configuración individual
        if ($type === 'PERISHABLE_FOOD' && $frequencyDays !== null && $frequencyDays > 15) {
            throw new PerishableFrequencyLimitException(
                attemptedDays: $frequencyDays,
                maximumAllowedDays: 15,
                machineType: $type
            );
        }

        $previousState = [
            'sanitary_frequency_days' => $effective['custom_frequency_days'],
            'next_sanitary_inspection_due' => $effective['next_sanitary_inspection_due'],
        ];

        $updated = $this->settingsRepo->updateMachineConfig(
            $machineId,
            $frequencyDays,
            $nextSanitaryInspectionDue
        );

        if ($updated) {
            $newState = [
                'sanitary_frequency_days' => $frequencyDays,
                'next_sanitary_inspection_due' => $nextSanitaryInspectionDue,
            ];

            $user = [
                'id' => $actor['id'] ?? null,
                'role' => $actor['role'] ?? 'COORDINATOR',
                'name' => $actor['name'] ?? 'Coordinación',
            ];

            $this->auditLogger->logMachineEvent(
                $machineId,
                'UPDATE_MACHINE_PREVENTIVE_CONFIG',
                $user,
                $previousState,
                $newState
            );
        }

        return $updated;
    }

    /**
     * Activa el estado de Pausa Estacional exigiendo obligatoriamente un motivo documentado (EARS 1.4).
     *
     * @param int $machineId
     * @param string $reason
     * @param string|null $pauseUntil
     * @param array{id?: int|null, role?: string, name?: string} $actor
     * @return bool
     * @throws SeasonalPauseMissingReasonException Si el motivo está vacío o ausente.
     */
    public function setSeasonalPause(
        int $machineId,
        string $reason,
        ?string $pauseUntil = null,
        array $actor = []
    ): bool {
        $trimmedReason = trim($reason);
        if ($trimmedReason === '') {
            throw new SeasonalPauseMissingReasonException(machineId: $machineId);
        }

        $effective = $this->settingsRepo->getMachineSettings($machineId);
        if (!$effective) {
            throw new InvalidArgumentException("Máquina con ID {$machineId} no encontrada.");
        }

        $previousState = [
            'sanitary_status' => $effective['sanitary_status'],
            'is_seasonal_pause' => $effective['is_seasonal_pause'],
            'seasonal_pause_reason' => $effective['seasonal_pause_reason'],
            'seasonal_pause_until' => $effective['seasonal_pause_until'],
        ];

        $updated = $this->settingsRepo->setSeasonalPause($machineId, $trimmedReason, $pauseUntil);

        if ($updated) {
            $newState = [
                'sanitary_status' => 'SEASONAL_PAUSE',
                'is_seasonal_pause' => 1,
                'seasonal_pause_reason' => $trimmedReason,
                'seasonal_pause_until' => $pauseUntil,
            ];

            $user = [
                'id' => $actor['id'] ?? null,
                'role' => $actor['role'] ?? 'COORDINATOR',
                'name' => $actor['name'] ?? 'Coordinación',
            ];

            $this->auditLogger->logMachineEvent(
                $machineId,
                'SET_SEASONAL_PAUSE',
                $user,
                $previousState,
                $newState
            );
        }

        return $updated;
    }

    /**
     * Reanuda el servicio tras una Pausa Estacional, restaurando el semáforo y registrando el evento.
     *
     * @param int $machineId
     * @param array{id?: int|null, role?: string, name?: string} $actor
     * @return bool
     */
    public function resumeSeasonalPause(int $machineId, array $actor = []): bool
    {
        $effective = $this->settingsRepo->getMachineSettings($machineId);
        if (!$effective) {
            throw new InvalidArgumentException("Máquina con ID {$machineId} no encontrada.");
        }

        $previousState = [
            'sanitary_status' => $effective['sanitary_status'],
            'is_seasonal_pause' => $effective['is_seasonal_pause'],
            'seasonal_pause_reason' => $effective['seasonal_pause_reason'],
            'seasonal_pause_until' => $effective['seasonal_pause_until'],
        ];

        $resumed = $this->settingsRepo->resumeSeasonalPause($machineId);

        if ($resumed) {
            $newState = [
                'sanitary_status' => 'OK',
                'is_seasonal_pause' => 0,
                'seasonal_pause_reason' => null,
                'seasonal_pause_until' => null,
            ];

            $user = [
                'id' => $actor['id'] ?? null,
                'role' => $actor['role'] ?? 'COORDINATOR',
                'name' => $actor['name'] ?? 'Coordinación',
            ];

            $this->auditLogger->logMachineEvent(
                $machineId,
                'RESUME_SEASONAL_PAUSE',
                $user,
                $previousState,
                $newState
            );
        }

        return $resumed;
    }
}
