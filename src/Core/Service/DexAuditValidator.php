<?php

declare(strict_types=1);

namespace VendGuard\Core\Service;

use VendGuard\Core\Domain\Exception\DexAuditConflictException;
use VendGuard\Core\Domain\Exception\DexAuditForbiddenException;
use VendGuard\Core\Domain\Exception\DexAuditValidationException;
use VendGuard\Core\Domain\Model\UserRole;

/**
 * DexAuditValidator
 *
 * Validador puro de las guardas de importación, la cadena de deltas, la
 * anulación lógica y la exportación CSV del Módulo 39 (RF-03.1, RF-04.1,
 * RF-07.2, D3, C1, C2, C6, QA-1, QA-2, Art. II.2, Art. III.2 y Art. V.1).
 *
 * Reglas que muerde, en el orden en que las aplica el servicio:
 *
 *   1. **Máquina viva (422, RF-03.1.1).** `machines.is_active = TRUE` y
 *      `deleted_at IS NULL`; si no, la importación no existe.
 *   2. **Perímetro por rol (403, RF-03.1.2/3).** El `RESTOCKER` debe cubrir la
 *      máquina en sus zonas activas de hoy; el `TECHNICIAN` debe tener una
 *      intervención activa o cubrir la zona de la sede. Coordinación no tiene
 *      perímetro operativo que respetar aquí.
 *   3. **Primacía sanitaria (409, C2, QA-1, Art. II.2).** Al `RESTOCKER` se le
 *      bloquea en `QUARANTINE` y con una incidencia `TEMPERATURE_COLD` abierta;
 *      se le permite en `EXPIRED`, `OUT_OF_SERVICE` o `is_blocked_no_access` no
 *      sanitario para documentar su escalado. Técnico y Coordinación conservan
 *      el acceso forense en cualquier estado.
 *   4. **Cadena de deltas (RF-04.1, C1, QA-2).** Sin predecesor `ACTIVE` el
 *      volcado es `INITIAL_BASELINE`; sin retroceso, `PREVIOUS_AUDIT` con la
 *      resta de ambos contadores; con retroceso, `counter_reset_detected = TRUE`
 *      y deltas `RESET_FALLBACK` desde los parciales del `.dex`, o
 *      `UNAVAILABLE` con `NULL` cuando el fichero no los trae. Un volcado
 *      posterior ya existente marca `out_of_order_sync = TRUE` sin alterar la
 *      elección del predecesor.
 *   5. **Anulación lógica (422/409, C6, Art. V.1).** El motivo exige de 20 a 500
 *      caracteres reales y al menos tres bloques alfanuméricos; un volcado ya
 *      anulado es terminal. El texto se devuelve recortado para persistirlo tal
 *      cual se validó.
 *   6. **CSV seguro (C6, Art. III.2).** Toda celda que empiece por `=`, `+`,
 *      `-`, `@`, tabulador o retorno de carro se neutraliza con un apóstrofo
 *      inicial, sin tocar el resto del contenido.
 *
 * No consulta ni escribe la base: recibe hechos ya leídos (máquina, cobertura,
 * predecesor) y devuelve los valores normalizados o la excepción con el código
 * exacto del contrato. El bloqueo `FOR UPDATE`, la búsqueda del predecesor y el
 * `INSERT` pertenecen al repositorio y al servicio.
 *
 * Dogma Vanilla: PHP 8.2+ puro, sin dependencias.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */
final class DexAuditValidator
{
    /** Estatus sanitarios que bloquean al Reponedor (C2, QA-1). */
    public const BLOCKING_SANITARY_STATUSES = ['QUARANTINE', 'SANITARY_QUARANTINE'];

    /** Categoría de incidencia térmica abierta que bloquea al Reponedor (QA-1). */
    public const THERMAL_INCIDENT_CATEGORY = 'TEMPERATURE_COLD';

    /** Cotas del motivo de anulación (Art. V.1). */
    public const MIN_VOID_REASON_LENGTH = 20;
    public const MAX_VOID_REASON_LENGTH = 500;
    public const MIN_ALNUM_BLOCKS = 3;

    /** Prefijos de fórmula que Excel/LibreOffice interpretan (C6). */
    public const CSV_FORMULA_PREFIXES = ['=', '+', '-', '@', "\t", "\r"];

    /** Cotas de contadores (espejo de `chk_machine_dex_audits_counters_not_negative`). */
    public const MAX_MONEY_EUR = 9999999999.99;
    public const MAX_UNITS = 999999999;

    /**
     * Máquina activa y no borrada (RF-03.1.1).
     *
     * @param array{id?:int, is_active?:bool, deleted_at?:string|null} $machine
     *
     * @throws DexAuditValidationException
     */
    public function assertMachineImportable(array $machine): void
    {
        $machineId = (int)($machine['id'] ?? 0);
        if ($machineId <= 0) {
            throw new DexAuditValidationException(
                'La máquina destino no existe o no se ha identificado.',
                'MACHINE_NOT_FOUND',
                ['machine_id' => $machineId]
            );
        }

        $isActive = ($machine['is_active'] ?? false) === true;
        $deletedAt = $machine['deleted_at'] ?? null;
        if (!$isActive || ($deletedAt !== null && trim((string)$deletedAt) !== '')) {
            throw new DexAuditValidationException(
                'La máquina destino no está activa: no admite auditoría .dex.',
                'MACHINE_INACTIVE',
                ['machine_id' => $machineId, 'is_active' => $isActive, 'deleted_at' => $deletedAt]
            );
        }
    }

    /**
     * Perímetro por rol y primacía sanitaria (RF-03.1.2..4, C2, QA-1).
     *
     * @param string $role Rol del actor (`user_role`).
     * @param bool $coversTerritory Si el actor cubre hoy la máquina (zonas del
     *                              Reponedor, intervención/orden o zona del Técnico).
     * @param string $sanitaryStatus `machines.sanitary_status`.
     * @param bool $hasOpenThermalIncident Incidencia `TEMPERATURE_COLD` no cerrada.
     *
     * @throws DexAuditForbiddenException Cuando el actor no cubre la máquina.
     * @throws DexAuditConflictException Cuando el Reponedor choca con la primacía sanitaria.
     * @throws DexAuditValidationException Cuando el rol no es válido.
     */
    public function assertImportAuthorized(
        string $role,
        bool $coversTerritory,
        string $sanitaryStatus = 'OPERATIONAL',
        bool $hasOpenThermalIncident = false
    ): void {
        $normalizedRole = strtoupper(trim($role));
        $userRole = UserRole::tryFrom($normalizedRole);

        if ($userRole === null) {
            throw new DexAuditValidationException(
                sprintf("El rol '%s' no puede importar auditorías .dex.", $role),
                'DEX_INVALID_IMPORTER_ROLE',
                ['role' => $role]
            );
        }

        if ($userRole === UserRole::COORDINATOR) {
            return;
        }

        if (!$coversTerritory) {
            throw new DexAuditForbiddenException(
                $userRole === UserRole::RESTOCKER
                    ? 'La máquina queda fuera de las zonas operativas activas del Reponedor.'
                    : 'El Técnico no tiene intervención activa ni cobertura de la zona de la sede.',
                $userRole === UserRole::RESTOCKER
                    ? 'MACHINE_OUTSIDE_RESTOCKER_ZONES'
                    : 'MACHINE_OUTSIDE_TECHNICIAN_SCOPE',
                ['role' => $normalizedRole]
            );
        }

        if ($userRole === UserRole::TECHNICIAN) {
            return;
        }

        $sanitary = strtoupper(trim($sanitaryStatus));
        if (in_array($sanitary, self::BLOCKING_SANITARY_STATUSES, true) || $hasOpenThermalIncident) {
            throw new DexAuditConflictException(
                'La máquina está en cuarentena sanitaria o tiene una avería térmica abierta: el Reponedor no puede importar el .dex.',
                'SANITARY_DEX_BLOCKED',
                [
                    'role' => $normalizedRole,
                    'sanitary_status' => $sanitary,
                    'has_open_thermal_incident' => $hasOpenThermalIncident,
                ]
            );
        }
    }

    /**
     * Resuelve la cadena de deltas de un volcado nuevo (RF-04.1, C1, QA-2).
     *
     * @param float $currentSalesEur `va1_total_sales_eur` del volcado nuevo.
     * @param int $currentVendUnits `va1_total_vend_units` del volcado nuevo.
     * @param float|null $currentResetSalesEur `va1_reset_sales_eur` del volcado nuevo.
     * @param int|null $currentResetVendUnits `va1_reset_vend_units` del volcado nuevo.
     * @param array{id:int, va1_total_sales_eur:float|int, va1_total_vend_units:int}|null $previousAudit
     *        Último `ACTIVE` con `captured_at <= captured_at` del nuevo, o `null`.
     * @param bool $hasLaterActiveAudit Si ya existe un `ACTIVE` posterior en el tiempo.
     *
     * @return array{
     *     previous_audit_id: int|null,
     *     counter_reset_detected: bool,
     *     out_of_order_sync: bool,
     *     delta_source: string,
     *     delta_sales_eur: float|null,
     *     delta_vend_units: int|null
     * }
     *
     * @throws DexAuditValidationException
     */
    public function resolveDeltas(
        float $currentSalesEur,
        int $currentVendUnits,
        ?float $currentResetSalesEur,
        ?int $currentResetVendUnits,
        ?array $previousAudit = null,
        bool $hasLaterActiveAudit = false
    ): array {
        if ($currentSalesEur < 0.0 || $currentSalesEur > self::MAX_MONEY_EUR
            || $currentVendUnits < 0 || $currentVendUnits > self::MAX_UNITS
            || ($currentResetSalesEur !== null && ($currentResetSalesEur < 0.0 || $currentResetSalesEur > self::MAX_MONEY_EUR))
            || ($currentResetVendUnits !== null && ($currentResetVendUnits < 0 || $currentResetVendUnits > self::MAX_UNITS))) {
            throw new DexAuditValidationException(
                'Los contadores del volcado están fuera de las cotas del contrato.',
                'DEX_INVALID_NUMERIC_BOUNDS',
                [
                    'va1_total_sales_eur' => $currentSalesEur,
                    'va1_total_vend_units' => $currentVendUnits,
                ]
            );
        }

        if ($previousAudit === null) {
            return [
                'previous_audit_id' => null,
                'counter_reset_detected' => false,
                'out_of_order_sync' => $hasLaterActiveAudit,
                'delta_source' => 'INITIAL_BASELINE',
                'delta_sales_eur' => null,
                'delta_vend_units' => null,
            ];
        }

        $previousId = (int)($previousAudit['id'] ?? 0);
        $previousSales = (float)($previousAudit['va1_total_sales_eur'] ?? -1.0);
        $previousUnits = (int)($previousAudit['va1_total_vend_units'] ?? -1);
        if ($previousId <= 0 || $previousSales < 0.0 || $previousUnits < 0) {
            throw new DexAuditValidationException(
                'El volcado predecesor no trae identificador y contadores válidos.',
                'DEX_INVALID_PREVIOUS_AUDIT',
                ['previous_audit' => $previousAudit]
            );
        }

        $resetDetected = $currentSalesEur < $previousSales || $currentVendUnits < $previousUnits;
        if (!$resetDetected) {
            return [
                'previous_audit_id' => $previousId,
                'counter_reset_detected' => false,
                'out_of_order_sync' => $hasLaterActiveAudit,
                'delta_source' => 'PREVIOUS_AUDIT',
                'delta_sales_eur' => round($currentSalesEur - $previousSales, 2),
                'delta_vend_units' => $currentVendUnits - $previousUnits,
            ];
        }

        if ($currentResetSalesEur === null && $currentResetVendUnits === null) {
            return [
                'previous_audit_id' => $previousId,
                'counter_reset_detected' => true,
                'out_of_order_sync' => $hasLaterActiveAudit,
                'delta_source' => 'UNAVAILABLE',
                'delta_sales_eur' => null,
                'delta_vend_units' => null,
            ];
        }

        return [
            'previous_audit_id' => $previousId,
            'counter_reset_detected' => true,
            'out_of_order_sync' => $hasLaterActiveAudit,
            'delta_source' => 'RESET_FALLBACK',
            'delta_sales_eur' => $currentResetSalesEur === null ? null : round($currentResetSalesEur, 2),
            'delta_vend_units' => $currentResetVendUnits,
        ];
    }

    /**
     * Valida y normaliza el motivo de anulación lógica (Art. V.1, C6).
     *
     * @throws DexAuditValidationException Con `VOID_REASON_REQUIRED`.
     */
    public function validateVoidReason(?string $reason): string
    {
        $clean = trim((string)$reason);
        $length = mb_strlen($clean, 'UTF-8');

        if ($length < self::MIN_VOID_REASON_LENGTH || $length > self::MAX_VOID_REASON_LENGTH) {
            throw new DexAuditValidationException(
                sprintf(
                    'El motivo de anulación debe tener entre %d y %d caracteres reales.',
                    self::MIN_VOID_REASON_LENGTH,
                    self::MAX_VOID_REASON_LENGTH
                ),
                'VOID_REASON_REQUIRED',
                ['length' => $length]
            );
        }

        preg_match_all('/[\p{L}\p{N}]+/u', $clean, $blocks);
        if (count($blocks[0]) < self::MIN_ALNUM_BLOCKS) {
            throw new DexAuditValidationException(
                sprintf('El motivo de anulación debe contener al menos %d bloques alfanuméricos.', self::MIN_ALNUM_BLOCKS),
                'VOID_REASON_REQUIRED',
                ['length' => $length, 'alnum_blocks' => count($blocks[0])]
            );
        }

        return $clean;
    }

    /**
     * Un volcado anulado es terminal (Art. III.3).
     *
     * @throws DexAuditConflictException Si ya está anulado.
     * @throws DexAuditValidationException Si el estado no es del dominio.
     */
    public function assertVoidable(string $currentStatus): void
    {
        $status = strtoupper(trim($currentStatus));

        if ($status === 'VOIDED') {
            throw new DexAuditConflictException(
                'El volcado ya está anulado: la transición es terminal.',
                'DEX_AUDIT_ALREADY_VOIDED'
            );
        }

        if ($status !== 'ACTIVE') {
            throw new DexAuditValidationException(
                sprintf("El estado '%s' no es un estado válido del volcado .dex.", $currentStatus),
                'DEX_INVALID_AUDIT_STATUS',
                ['status' => $currentStatus]
            );
        }
    }

    /**
     * Neutraliza la inyección de fórmulas en una celda CSV (C6, Art. III.2).
     */
    public function sanitizeCsvCell(?string $value): string
    {
        $cell = (string)$value;
        if ($cell === '') {
            return '';
        }

        foreach (self::CSV_FORMULA_PREFIXES as $prefix) {
            if (str_starts_with($cell, $prefix)) {
                return "'" . $cell;
            }
        }

        return $cell;
    }
}
