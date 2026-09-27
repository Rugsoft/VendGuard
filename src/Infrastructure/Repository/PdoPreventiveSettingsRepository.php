<?php

declare(strict_types=1);

namespace VendGuard\Infrastructure\Repository;

use PDO;
use VendGuard\Core\Domain\Model\PreventiveSetting;
use VendGuard\Core\Domain\Repository\PreventiveSettingsRepositoryInterface;
use VendGuard\Core\Domain\Exception\PerishableFrequencyLimitException;
use VendGuard\Core\Domain\Exception\SeasonalPauseMissingReasonException;

/**
 * PdoPreventiveSettingsRepository
 * 
 * Implementación PDO del repositorio de configuraciones sanitarias y pausas estacionales.
 * Garantiza cumplimiento de reglas constitucionales (Art. II) y persistencia segura en MariaDB/MySQL.
 */
class PdoPreventiveSettingsRepository implements PreventiveSettingsRepositoryInterface
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * @return array<PreventiveSetting>
     */
    public function findAll(): array
    {
        $stmt = $this->pdo->query("
            SELECT `id`, `machine_type`, `default_frequency_days`, `max_allowed_days`, `advance_warning_days`, `created_at`, `updated_at`
            FROM `preventive_settings`
            ORDER BY `id` ASC
        ");

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $settings = [];

        foreach ($rows as $row) {
            $settings[] = $this->hydrateSetting($row);
        }

        return $settings;
    }

    public function findByMachineType(string $machineType): ?PreventiveSetting
    {
        $stmt = $this->pdo->prepare("
            SELECT `id`, `machine_type`, `default_frequency_days`, `max_allowed_days`, `advance_warning_days`, `created_at`, `updated_at`
            FROM `preventive_settings`
            WHERE `machine_type` = :machine_type
            LIMIT 1
        ");

        $stmt->execute([':machine_type' => $machineType]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return null;
        }

        return $this->hydrateSetting($row);
    }

    public function updateTypeSettings(
        string $machineType,
        int $defaultFrequencyDays,
        int $maxAllowedDays,
        int $advanceWarningDays = 5
    ): bool {
        // Blindaje constitucional del Artículo II (Seguridad Alimentaria)
        if ($machineType === 'PERISHABLE_FOOD') {
            if ($defaultFrequencyDays > 15 || $maxAllowedDays > 15) {
                throw new PerishableFrequencyLimitException(
                    'Por imperativo del Artículo II de la Constitución (Seguridad Alimentaria), las máquinas dispensadoras de alimentos perecederos no pueden superar los 15 días naturales entre inspecciones sanitarias.',
                    max($defaultFrequencyDays, $maxAllowedDays),
                    15,
                    'PERISHABLE_FOOD'
                );
            }
        }

        $stmt = $this->pdo->prepare("
            UPDATE `preventive_settings`
            SET 
                `default_frequency_days` = :default_freq,
                `max_allowed_days` = :max_days,
                `advance_warning_days` = :adv_warning,
                `updated_at` = CURRENT_TIMESTAMP
            WHERE `machine_type` = :machine_type
        ");

        return $stmt->execute([
            ':default_freq' => $defaultFrequencyDays,
            ':max_days' => $maxAllowedDays,
            ':adv_warning' => $advanceWarningDays,
            ':machine_type' => $machineType,
        ]);
    }

    public function updateMachineConfig(
        int $machineId,
        ?int $sanitaryFrequencyDays,
        ?string $nextSanitaryInspectionDue = null
    ): bool {
        // Consultar la tipología de la máquina para validar reglas de perecederos
        $stmtCheck = $this->pdo->prepare("
            SELECT `id`, `machine_type`
            FROM `machines`
            WHERE `id` = :id AND `deleted_at` IS NULL
            LIMIT 1
        ");
        $stmtCheck->execute([':id' => $machineId]);
        $machine = $stmtCheck->fetch(PDO::FETCH_ASSOC);

        if (!$machine) {
            return false;
        }

        // Blindaje Artículo II si se intenta superar 15 días en perecederos
        if ($sanitaryFrequencyDays !== null && $machine['machine_type'] === 'PERISHABLE_FOOD' && $sanitaryFrequencyDays > 15) {
            throw new PerishableFrequencyLimitException(
                'Por imperativo del Artículo II de la Constitución (Seguridad Alimentaria), las máquinas dispensadoras de alimentos perecederos no pueden superar los 15 días naturales entre inspecciones sanitarias.',
                $sanitaryFrequencyDays,
                15,
                'PERISHABLE_FOOD'
            );
        }

        $sql = "
            UPDATE `machines`
            SET 
                `sanitary_frequency_days` = :frequency,
                `next_sanitary_inspection_due` = COALESCE(:due, `next_sanitary_inspection_due`),
                `updated_at` = CURRENT_TIMESTAMP
            WHERE `id` = :id AND `deleted_at` IS NULL
        ";

        $stmt = $this->pdo->prepare($sql);
        return $stmt->execute([
            ':frequency' => $sanitaryFrequencyDays,
            ':due' => $nextSanitaryInspectionDue,
            ':id' => $machineId,
        ]);
    }

    public function setSeasonalPause(
        int $machineId,
        string $reason,
        ?string $pauseUntil = null
    ): bool {
        $trimmedReason = trim($reason);
        if ($trimmedReason === '') {
            throw new SeasonalPauseMissingReasonException(
                'Debe justificar documentalmente el motivo de la pausa estacional y la fecha estimada de reanudación.',
                $machineId
            );
        }

        $stmt = $this->pdo->prepare("
            UPDATE `machines`
            SET 
                `is_seasonal_pause` = 1,
                `seasonal_pause_reason` = :reason,
                `seasonal_pause_until` = :pause_until,
                `sanitary_status` = 'SEASONAL_PAUSE',
                `updated_at` = CURRENT_TIMESTAMP
            WHERE `id` = :id AND `deleted_at` IS NULL
        ");

        return $stmt->execute([
            ':reason' => $trimmedReason,
            ':pause_until' => $pauseUntil,
            ':id' => $machineId,
        ]);
    }

    public function resumeSeasonalPause(int $machineId): bool
    {
        // Al reactivarse el servicio, se transiciona a ATTENTION_REQUIRED con vencimiento hoy
        // exigiendo la revisión higiénica previa reglamentaria (EARS 1.5)
        $stmt = $this->pdo->prepare("
            UPDATE `machines`
            SET 
                `is_seasonal_pause` = 0,
                `seasonal_pause_reason` = NULL,
                `seasonal_pause_until` = NULL,
                `sanitary_status` = 'ATTENTION_REQUIRED',
                `next_sanitary_inspection_due` = CURRENT_DATE(),
                `updated_at` = CURRENT_TIMESTAMP
            WHERE `id` = :id AND `deleted_at` IS NULL
        ");

        return $stmt->execute([':id' => $machineId]);
    }

    public function getMachineSettings(int $machineId): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT 
                m.`id` AS `machine_id`,
                m.`code` AS `machine_code`,
                m.`machine_type`,
                m.`sanitary_status`,
                m.`sanitary_frequency_days`,
                m.`last_sanitary_inspection_at`,
                m.`next_sanitary_inspection_due`,
                m.`is_seasonal_pause`,
                m.`seasonal_pause_reason`,
                m.`seasonal_pause_until`,
                ps.`default_frequency_days`,
                ps.`max_allowed_days`,
                ps.`advance_warning_days`,
                COALESCE(m.`sanitary_frequency_days`, ps.`default_frequency_days`) AS `effective_frequency_days`
            FROM `machines` m
            LEFT JOIN `preventive_settings` ps ON m.`machine_type` = ps.`machine_type`
            WHERE m.`id` = :id AND m.`deleted_at` IS NULL
            LIMIT 1
        ");

        $stmt->execute([':id' => $machineId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return null;
        }

        return [
            'machine_id' => (int)$row['machine_id'],
            'machine_code' => (string)$row['machine_code'],
            'machine_type' => (string)$row['machine_type'],
            'sanitary_status' => (string)$row['sanitary_status'],
            'sanitary_frequency_days' => $row['sanitary_frequency_days'] !== null ? (int)$row['sanitary_frequency_days'] : null,
            'effective_frequency_days' => (int)$row['effective_frequency_days'],
            'default_frequency_days' => $row['default_frequency_days'] !== null ? (int)$row['default_frequency_days'] : 30,
            'max_allowed_days' => $row['max_allowed_days'] !== null ? (int)$row['max_allowed_days'] : 60,
            'advance_warning_days' => $row['advance_warning_days'] !== null ? (int)$row['advance_warning_days'] : 5,
            'last_sanitary_inspection_at' => $row['last_sanitary_inspection_at'],
            'next_sanitary_inspection_due' => $row['next_sanitary_inspection_due'],
            'is_seasonal_pause' => (bool)$row['is_seasonal_pause'],
            'seasonal_pause_reason' => $row['seasonal_pause_reason'],
            'seasonal_pause_until' => $row['seasonal_pause_until'],
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function updateSanitaryStatus(int $machineId, string $status): bool
    {
        $stmt = $this->pdo->prepare("
            UPDATE `machines`
            SET `sanitary_status` = :status,
                `updated_at` = CURRENT_TIMESTAMP
            WHERE `id` = :id AND `deleted_at` IS NULL
        ");

        return $stmt->execute([
            ':status' => $status,
            ':id' => $machineId,
        ]);
    }

    /**
     * @param array<string, mixed> $row
     * @return PreventiveSetting
     */
    private function hydrateSetting(array $row): PreventiveSetting
    {
        return new PreventiveSetting(
            (int)$row['id'],
            (string)$row['machine_type'],
            (int)$row['default_frequency_days'],
            (int)$row['max_allowed_days'],
            (int)($row['advance_warning_days'] ?? 5),
            (string)($row['created_at'] ?? ''),
            (string)($row['updated_at'] ?? '')
        );
    }
}
