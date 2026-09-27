<?php

declare(strict_types=1);

/**
 * PreventiveSettingsServiceTest
 *
 * Suite de pruebas unitarias para PreventiveSettingsService (T-PREV-08).
 * Valida de forma exhaustiva:
 * 1. Blindaje Constitucional (Art. II): Bloqueo estricto de periodicidades > 15 días en alimentos perecederos.
 * 2. Validación de coherencia paramétrica (enteros positivos, periodicidad estándar <= tope máximo).
 * 3. Configuración por tipología y personalización por máquina.
 * 4. Gestión de Pausas Estacionales con exigencia de justificación documental (EARS 1.4).
 * 5. Reanudación de servicio post-pausa estacional.
 * 6. Registro auditable de eventos inmutables en audit_log (RF-05).
 *
 * Dogma Vanilla: PHP 8.2+ puro sin Composer ni dependencias externas.
 */

require_once __DIR__ . '/../../src/autoload.php';

use VendGuard\Application\Service\AuditLogger;
use VendGuard\Application\Service\PreventiveSettingsService;
use VendGuard\Core\Domain\Exception\PerishableFrequencyLimitException;
use VendGuard\Core\Domain\Exception\SeasonalPauseMissingReasonException;
use VendGuard\Core\Domain\Model\AuditEvent;
use VendGuard\Core\Domain\Model\PreventiveSetting;
use VendGuard\Core\Domain\Repository\AuditLogRepositoryInterface;
use VendGuard\Core\Domain\Repository\PreventiveSettingsRepositoryInterface;

echo "======================================================================\n";
echo " VendGuard: Pruebas Unitarias de PreventiveSettingsService (T-PREV-08)\n";
echo "======================================================================\n\n";

$assertions = 0;

function assertCondition(bool $cond, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$cond) {
        echo "  [FALLO] {$message}\n";
        exit(1);
    }
    echo "  [PASS] {$message}\n";
}

/**
 * Repositorio en memoria para pruebas unitarias aisladas de configuración preventiva.
 */
class InMemoryPreventiveSettingsRepository implements PreventiveSettingsRepositoryInterface
{
    /** @var array<string, PreventiveSetting> */
    public array $settings = [];

    /** @var array<int, array<string, mixed>> */
    public array $machines = [];

    public function __construct()
    {
        $this->settings['PERISHABLE_FOOD'] = new PreventiveSetting(1, 'PERISHABLE_FOOD', 15, 15, 5);
        $this->settings['HOT_DRINKS'] = new PreventiveSetting(2, 'HOT_DRINKS', 30, 60, 5);
        $this->settings['COLD_DRINKS'] = new PreventiveSetting(3, 'COLD_DRINKS', 45, 90, 5);
        $this->settings['SNACKS'] = new PreventiveSetting(4, 'SNACKS', 60, 90, 5);
        $this->settings['COMBO'] = new PreventiveSetting(5, 'COMBO', 15, 45, 5);

        // Máquina 1: Perecederos
        $this->machines[1] = [
            'id' => 1,
            'code' => 'VEND-0101',
            'machine_type' => 'PERISHABLE_FOOD',
            'sanitary_status' => 'OK',
            'custom_frequency_days' => null,
            'next_sanitary_inspection_due' => '2026-10-05',
            'is_seasonal_pause' => 0,
            'seasonal_pause_reason' => null,
            'seasonal_pause_until' => null,
        ];

        // Máquina 2: Bebidas calientes
        $this->machines[2] = [
            'id' => 2,
            'code' => 'VEND-0102',
            'machine_type' => 'HOT_DRINKS',
            'sanitary_status' => 'OK',
            'custom_frequency_days' => null,
            'next_sanitary_inspection_due' => '2026-10-20',
            'is_seasonal_pause' => 0,
            'seasonal_pause_reason' => null,
            'seasonal_pause_until' => null,
        ];
    }

    public function findAll(): array
    {
        return array_values($this->settings);
    }

    public function findByMachineType(string $machineType): ?PreventiveSetting
    {
        return $this->settings[$machineType] ?? null;
    }

    public function updateTypeSettings(
        string $machineType,
        int $defaultFrequencyDays,
        int $maxAllowedDays,
        int $advanceWarningDays = 5
    ): bool {
        if (!isset($this->settings[$machineType])) {
            return false;
        }

        $id = $this->settings[$machineType]->getId();
        $this->settings[$machineType] = new PreventiveSetting(
            $id,
            $machineType,
            $defaultFrequencyDays,
            $maxAllowedDays,
            $advanceWarningDays
        );

        return true;
    }

    public function updateMachineConfig(
        int $machineId,
        ?int $sanitaryFrequencyDays,
        ?string $nextSanitaryInspectionDue = null
    ): bool {
        if (!isset($this->machines[$machineId])) {
            return false;
        }

        $this->machines[$machineId]['custom_frequency_days'] = $sanitaryFrequencyDays;
        if ($nextSanitaryInspectionDue !== null) {
            $this->machines[$machineId]['next_sanitary_inspection_due'] = $nextSanitaryInspectionDue;
        }

        return true;
    }

    public function setSeasonalPause(int $machineId, string $reason, ?string $pauseUntil = null): bool
    {
        if (!isset($this->machines[$machineId])) {
            return false;
        }

        $this->machines[$machineId]['sanitary_status'] = 'SEASONAL_PAUSE';
        $this->machines[$machineId]['is_seasonal_pause'] = 1;
        $this->machines[$machineId]['seasonal_pause_reason'] = $reason;
        $this->machines[$machineId]['seasonal_pause_until'] = $pauseUntil;

        return true;
    }

    public function resumeSeasonalPause(int $machineId): bool
    {
        if (!isset($this->machines[$machineId])) {
            return false;
        }

        $this->machines[$machineId]['sanitary_status'] = 'OK';
        $this->machines[$machineId]['is_seasonal_pause'] = 0;
        $this->machines[$machineId]['seasonal_pause_reason'] = null;
        $this->machines[$machineId]['seasonal_pause_until'] = null;

        return true;
    }

    public function updateSanitaryStatus(int $machineId, string $status): bool
    {
        if (isset($this->machines[$machineId])) {
            $this->machines[$machineId]['sanitary_status'] = $status;
        }
        return true;
    }

    public function getMachineSettings(int $machineId): ?array
    {
        if (!isset($this->machines[$machineId])) {
            return null;
        }

        $m = $this->machines[$machineId];
        $type = $m['machine_type'];
        $typeSetting = $this->settings[$type] ?? null;

        return [
            'machine_id' => $m['id'],
            'machine_code' => $m['code'],
            'machine_type' => $type,
            'sanitary_status' => $m['sanitary_status'],
            'custom_frequency_days' => $m['custom_frequency_days'],
            'effective_frequency_days' => $m['custom_frequency_days'] ?? ($typeSetting?->getDefaultFrequencyDays() ?? 30),
            'max_allowed_days' => $typeSetting?->getMaxAllowedDays() ?? 60,
            'advance_warning_days' => $typeSetting?->getAdvanceWarningDays() ?? 5,
            'next_sanitary_inspection_due' => $m['next_sanitary_inspection_due'],
            'is_seasonal_pause' => (bool)$m['is_seasonal_pause'],
            'seasonal_pause_reason' => $m['seasonal_pause_reason'],
            'seasonal_pause_until' => $m['seasonal_pause_until'],
        ];
    }
}

/**
 * Repositorio en memoria para verificar la captura inmutable de eventos de auditoría.
 */
class InMemoryAuditLogRepository implements AuditLogRepositoryInterface
{
    /** @var list<AuditEvent> */
    public array $loggedEvents = [];

    public function log(AuditEvent $event): AuditEvent
    {
        $this->loggedEvents[] = $event;
        return $event;
    }

    public function findEvents(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        return $this->loggedEvents;
    }

    public function countEvents(array $filters = []): int
    {
        return count($this->loggedEvents);
    }

    public function findByEntity(string $entityType, int $entityId): array
    {
        return array_values(array_filter(
            $this->loggedEvents,
            fn(AuditEvent $e) => $e->getEntityType() === $entityType && $e->getEntityId() === $entityId
        ));
    }
}

// Inicialización de dependencias
$settingsRepo = new InMemoryPreventiveSettingsRepository();
$auditRepo = new InMemoryAuditLogRepository();
$auditLogger = new AuditLogger($auditRepo);
$service = new PreventiveSettingsService($settingsRepo, $auditLogger);

$actor = ['id' => 1, 'role' => 'COORDINATOR', 'name' => 'Coordinadora Laura'];

// --- 1. Catálogo General y Consulta por Tipología ---
echo "--- 1. Catálogo General y Consulta por Tipología ---\n";
$all = $service->getAllSettings();
assertCondition(count($all) === 5, "1.1 getAllSettings() retorna 5 tipologías normativas");

$perishable = $service->getSettingByType('PERISHABLE_FOOD');
assertCondition($perishable !== null && $perishable->getDefaultFrequencyDays() === 15, "1.2 getSettingByType('PERISHABLE_FOOD') retorna 15 días por defecto");

// --- 2. Blindaje Constitucional Art. II: Límite 15 días en Perecederos ---
echo "\n--- 2. Blindaje Constitucional Art. II: Límite 15 días en Perecederos ---\n";
$caughtPerishableDefault = false;
try {
    $service->updateTypeSettings('PERISHABLE_FOOD', 20, 20, 5, $actor);
} catch (PerishableFrequencyLimitException $e) {
    $caughtPerishableDefault = true;
    assertCondition($e->getErrorCode() === 'PERISHABLE_FREQUENCY_LIMIT_EXCEEDED', "2.1 Lanza código PERISHABLE_FREQUENCY_LIMIT_EXCEEDED");
    assertCondition($e->getHttpStatusCode() === 422, "2.2 Retorna código HTTP 422");
    assertCondition($e->getAttemptedDays() === 20, "2.3 Captura attemptedDays = 20");
    assertCondition($e->getMaximumAllowedDays() === 15, "2.4 Fija maximumAllowedDays = 15");
}
assertCondition($caughtPerishableDefault, "2.5 Bloquea configuración estándar de perecederos > 15 días");

$caughtPerishableMax = false;
try {
    $service->updateTypeSettings('PERISHABLE_FOOD', 10, 25, 5, $actor);
} catch (PerishableFrequencyLimitException $e) {
    $caughtPerishableMax = true;
    assertCondition($e->getAttemptedDays() === 25, "2.6 Bloquea tope máximo de perecederos > 15 días (25)");
}
assertCondition($caughtPerishableMax, "2.7 Bloquea intento con default <= 15 pero max > 15");

// Modificación válida en perecederos (<= 15 días)
$updatedValidPerishable = $service->updateTypeSettings('PERISHABLE_FOOD', 10, 15, 3, $actor);
assertCondition($updatedValidPerishable, "2.8 Permite actualizar perecederos con valores válidos (10 y 15 días)");
$refreshedPerishable = $service->getSettingByType('PERISHABLE_FOOD');
assertCondition($refreshedPerishable->getDefaultFrequencyDays() === 10, "2.9 Refleja periodicidad estándar actualizada a 10 días");

// --- 3. Validaciones Numéricas de Entrada ---
echo "\n--- 3. Validaciones Numéricas de Entrada ---\n";
$caughtNegative = false;
try {
    $service->updateTypeSettings('HOT_DRINKS', -5, 30, 5, $actor);
} catch (InvalidArgumentException $e) {
    $caughtNegative = true;
}
assertCondition($caughtNegative, "3.1 Rechaza periodicidad negativa");

$caughtIncoherent = false;
try {
    $service->updateTypeSettings('HOT_DRINKS', 40, 20, 5, $actor);
} catch (InvalidArgumentException $e) {
    $caughtIncoherent = true;
}
assertCondition($caughtIncoherent, "3.2 Rechaza periodicidad estándar mayor que el tope máximo (40 > 20)");

// --- 4. Personalización de Máquina y Blindaje Individual ---
echo "\n--- 4. Personalización de Máquina y Blindaje Individual ---\n";
$mach1Settings = $service->getMachineSettings(1);
assertCondition($mach1Settings['machine_type'] === 'PERISHABLE_FOOD', "4.1 Máquina 1 identificada como PERISHABLE_FOOD");

$caughtIndividualLimit = false;
try {
    $service->updateMachineConfig(1, 20, '2026-10-25', $actor);
} catch (PerishableFrequencyLimitException $e) {
    $caughtIndividualLimit = true;
    assertCondition($e->getAttemptedDays() === 20, "4.2 Captura intento individual de 20 días en perecederos");
}
assertCondition($caughtIndividualLimit, "4.3 Bloquea personalización individual > 15 días en máquina de perecederos");

$okIndividual = $service->updateMachineConfig(1, 12, '2026-10-17', $actor);
assertCondition($okIndividual, "4.4 Permite personalizar máquina de perecederos a 12 días");
$mach1Updated = $service->getMachineSettings(1);
assertCondition($mach1Updated['custom_frequency_days'] === 12, "4.5 Máquina 1 persiste frecuencia personalizada de 12 días");

// En máquina de bebidas calientes sí permite > 15 días
$okHotDrinks = $service->updateMachineConfig(2, 45, '2026-11-05', $actor);
assertCondition($okHotDrinks, "4.6 Permite 45 días en máquina no perecedera (HOT_DRINKS)");

// --- 5. Gestión de Pausa Estacional (EARS 1.4 y 1.5) ---
echo "\n--- 5. Gestión de Pausa Estacional (EARS 1.4 y 1.5) ---\n";
$caughtMissingReason = false;
try {
    $service->setSeasonalPause(1, '', '2026-09-01', $actor);
} catch (SeasonalPauseMissingReasonException $e) {
    $caughtMissingReason = true;
    assertCondition($e->getErrorCode() === 'SEASONAL_PAUSE_REQUIRES_REASON', "5.1 Código SEASONAL_PAUSE_REQUIRES_REASON");
    assertCondition($e->getHttpStatusCode() === 422, "5.2 Código HTTP 422 al omitir motivo");
}
assertCondition($caughtMissingReason, "5.3 Bloquea pausa estacional con motivo vacío");

$caughtWhitespaceReason = false;
try {
    $service->setSeasonalPause(1, "   \t\n  ", '2026-09-01', $actor);
} catch (SeasonalPauseMissingReasonException $e) {
    $caughtWhitespaceReason = true;
}
assertCondition($caughtWhitespaceReason, "5.4 Bloquea pausa estacional con motivo consistente sólo en espacios en blanco");

$pauseReason = 'Cierre de instalaciones universitarias por periodo vacacional de verano.';
$pauseUntil = '2026-09-01';
$paused = $service->setSeasonalPause(1, $pauseReason, $pauseUntil, $actor);
assertCondition($paused, "5.5 Activa pausa estacional con justificación documental válida");

$mach1Paused = $service->getMachineSettings(1);
assertCondition($mach1Paused['is_seasonal_pause'] === true, "5.6 Estado is_seasonal_pause = true");
assertCondition($mach1Paused['sanitary_status'] === 'SEASONAL_PAUSE', "5.7 sanitary_status = SEASONAL_PAUSE");
assertCondition($mach1Paused['seasonal_pause_reason'] === $pauseReason, "5.8 Motivo documentado almacenado exactamente");

// Reanudar pausa estacional
$resumed = $service->resumeSeasonalPause(1, $actor);
assertCondition($resumed, "5.9 Reanuda satisfactoriamente el servicio de la máquina");
$mach1Resumed = $service->getMachineSettings(1);
assertCondition($mach1Resumed['is_seasonal_pause'] === false, "5.10 Estado is_seasonal_pause = false");
assertCondition($mach1Resumed['sanitary_status'] === 'OK', "5.11 sanitary_status restaurado a OK");
assertCondition($mach1Resumed['seasonal_pause_reason'] === null, "5.12 seasonal_pause_reason limpiado");

// --- 6. Registro Inmutable en Audit Log (RF-05) ---
echo "\n--- 6. Registro Inmutable en Audit Log (RF-05) ---\n";
$events = $auditRepo->loggedEvents;
assertCondition(count($events) >= 5, "6.1 Se registraron al menos 5 eventos de auditoría");

$actionsLogged = array_map(fn(AuditEvent $e) => $e->getAction(), $events);
assertCondition(in_array('UPDATE_PREVENTIVE_TYPE_SETTINGS', $actionsLogged, true), "6.2 Registrado UPDATE_PREVENTIVE_TYPE_SETTINGS");
assertCondition(in_array('UPDATE_MACHINE_PREVENTIVE_CONFIG', $actionsLogged, true), "6.3 Registrado UPDATE_MACHINE_PREVENTIVE_CONFIG");
assertCondition(in_array('SET_SEASONAL_PAUSE', $actionsLogged, true), "6.4 Registrado SET_SEASONAL_PAUSE");
assertCondition(in_array('RESUME_SEASONAL_PAUSE', $actionsLogged, true), "6.5 Registrado RESUME_SEASONAL_PAUSE");

// Verificar que el usuario auditor quedó registrado
$lastEvent = end($events);
assertCondition($lastEvent->getUserName() === 'Coordinadora Laura', "6.6 Nombre del actor auditado 'Coordinadora Laura'");
assertCondition($lastEvent->getUserRole() === 'COORDINATOR', "6.7 Rol del actor auditado 'COORDINATOR'");

echo "\n======================================================================\n";
echo " Total Aserciones: {$assertions}\n";
echo " RESULTADO: 100% EN VERDE. Todas las reglas de PreventiveSettingsService verificadas.\n";
echo " Condición T-PREV-08 CUMPLIDA SATISFACTORIAMENTE.\n";
echo "======================================================================\n";
