<?php

declare(strict_types=1);

namespace VendGuard\Application\Service;

use InvalidArgumentException;
use VendGuard\Core\Domain\Exception\PerishableFrequencyLimitException;
use VendGuard\Core\Domain\Model\PreventiveOrder;
use VendGuard\Core\Domain\Repository\MachineRepositoryInterface;
use VendGuard\Core\Domain\Repository\PreventiveOrderRepositoryInterface;
use VendGuard\Core\Domain\Repository\PreventiveSettingsRepositoryInterface;
use VendGuard\Infrastructure\Repository\PdoMachineRepository;

/**
 * PreventiveOrderSchedulerService
 *
 * Motor de planificación y programación automática de mantenimiento preventivo higiénico-sanitario.
 * Cumple con:
 * - RF-PREV-01: Inicialización automática del ciclo preventivo en el alta de máquinas.
 * - RF-PREV-02: Generación anticipada a <= 5 días del vencimiento (EARS 2.1).
 * - Constitución Art. II: Salvaguarda inquebrantable de frecuencias <= 15 días en perecederos.
 * - Constitución Art. III: Cancelación puramente lógica de órdenes en traslados de máquina (EARS 2.5).
 */
class PreventiveOrderSchedulerService
{
    private PreventiveOrderRepositoryInterface $orderRepo;
    private PreventiveSettingsRepositoryInterface $settingsRepo;
    private MachineRepositoryInterface $machineRepo;
    private AuditLogger $auditLogger;

    public function __construct(
        PreventiveOrderRepositoryInterface $orderRepo,
        PreventiveSettingsRepositoryInterface $settingsRepo,
        ?MachineRepositoryInterface $machineRepo = null,
        ?AuditLogger $auditLogger = null
    ) {
        $this->orderRepo = $orderRepo;
        $this->settingsRepo = $settingsRepo;
        $this->machineRepo = $machineRepo ?? new PdoMachineRepository();
        $this->auditLogger = $auditLogger ?? new AuditLogger();
    }

    /**
     * Genera órdenes preventivas automáticamente para todas las máquinas activas
     * cuya fecha límite de inspección se encuentre dentro del horizonte anticipado (<= 5 días).
     *
     * @param int $advanceDays Días de antelación para la ventana de generación (default 5)
     * @param array{id?: int|null, role?: string, name?: string} $actor
     * @return array{
     *     scanned_machines_count: int,
     *     generated_orders_count: int,
     *     skipped_seasonal_pause: int,
     *     skipped_already_active: int,
     *     expired_orders_count: int,
     *     generated_orders: array<PreventiveOrder>
     * }
     */
    public function generateDueOrders(int $advanceDays = 5, array $actor = []): array
    {
        // 1. Marcar como EXPIRED órdenes vencidas preexistentes (EARS 2.4)
        $expiredCount = $this->orderRepo->expireOverdueOrders();

        // 2. Obtener todas las máquinas activas
        $machines = $this->machineRepo->findAll(['status' => 'active']);
        $scannedCount = count($machines);
        $skippedSeasonal = 0;
        $skippedActive = 0;
        $generatedOrders = [];

        $horizonDate = date('Y-m-d', strtotime("+{$advanceDays} days"));

        foreach ($machines as $mach) {
            $machineId = (int)$mach['id'];
            $locationId = (int)$mach['location_id'];

            // Omitir máquinas en Pausa Estacional documentada (EARS 1.4 / EARS 2.1)
            $isPause = !empty($mach['is_seasonal_pause']) || (($mach['sanitary_status'] ?? '') === 'SEASONAL_PAUSE');
            if ($isPause) {
                $skippedSeasonal++;
                continue;
            }

            // Omitir si ya tiene una orden preventiva activa o pendiente en curso
            if ($this->orderRepo->hasActiveOrPendingOrder($machineId)) {
                $skippedActive++;
                continue;
            }

            // Obtener fecha de vencimiento sanitario
            $dueDate = $mach['next_sanitary_inspection_due'] ?? null;
            if (empty($dueDate)) {
                $dueDate = $this->initializeMachinePreventiveCycle(
                    $machineId,
                    (string)$mach['machine_type'],
                    isset($mach['sanitary_frequency_days']) && $mach['sanitary_frequency_days'] !== null ? (int)$mach['sanitary_frequency_days'] : null,
                    'today'
                );
            }

            // Si la fecha límite se encuentra en o antes de la fecha horizonte
            if ($dueDate <= $horizonDate) {
                $order = $this->orderRepo->create([
                    'machine_id' => $machineId,
                    'location_id' => $locationId,
                    'assigned_technician_id' => null,
                    'status' => 'PENDING_ASSIGNMENT',
                    'order_type' => 'ROUTINE',
                    'scheduled_date' => $dueDate,
                    'due_date' => $dueDate,
                    'notes' => 'Generación preventiva automática periódica anticipada (EARS 2.1).',
                ]);

                $generatedOrders[] = $order;
            }
        }

        return [
            'scanned_machines_count' => $scannedCount,
            'generated_orders_count' => count($generatedOrders),
            'skipped_seasonal_pause' => $skippedSeasonal,
            'skipped_already_active' => $skippedActive,
            'expired_orders_count' => $expiredCount,
            'generated_orders' => $generatedOrders,
        ];
    }

    /**
     * Inicializa el ciclo preventivo en el momento del alta de una máquina (RF-PREV-01, EARS 1.1).
     *
     * @param int $machineId
     * @param string $machineType
     * @param int|null $customFrequencyDays
     * @param string $startDate Fecha base para la suma de días (default 'today')
     * @return string Fecha calculada de próxima inspección (Y-m-d)
     * @throws PerishableFrequencyLimitException Si es perecedera y supera los 15 días.
     */
    public function initializeMachinePreventiveCycle(
        int $machineId,
        string $machineType,
        ?int $customFrequencyDays = null,
        string $startDate = 'today'
    ): string {
        $type = strtoupper(trim($machineType));

        if ($type === 'PERISHABLE_FOOD' && $customFrequencyDays !== null && $customFrequencyDays > 15) {
            throw new PerishableFrequencyLimitException(
                attemptedDays: $customFrequencyDays,
                maximumAllowedDays: 15,
                machineType: $type
            );
        }

        $typeSetting = $this->settingsRepo->findByMachineType($type);
        $defaultDays = $typeSetting ? $typeSetting->getDefaultFrequencyDays() : 15;

        $frequency = $customFrequencyDays ?? $defaultDays;
        if ($type === 'PERISHABLE_FOOD') {
            $frequency = min($frequency, 15);
        }

        $nextDue = date('Y-m-d', strtotime("{$startDate} + {$frequency} days"));

        $this->settingsRepo->updateMachineConfig($machineId, $customFrequencyDays, $nextDue);

        return $nextDue;
    }

    /**
     * Recalcula y fija la nueva fecha de vencimiento sanitario tras una inspección completada (RF-PREV-04).
     *
     * @param int $machineId
     * @param string $completionDate Fecha de realización (Y-m-d o 'today')
     * @return string Nueva fecha de vencimiento (Y-m-d)
     */
    public function recalculateNextDueDate(int $machineId, string $completionDate = 'today'): string
    {
        $settings = $this->settingsRepo->getMachineSettings($machineId);
        if (!$settings) {
            throw new InvalidArgumentException("Máquina con ID {$machineId} no encontrada.");
        }

        $type = (string)($settings['machine_type'] ?? 'PERISHABLE_FOOD');
        $effectiveDays = (int)($settings['effective_frequency_days'] ?? 15);

        // Blindaje Art. II
        if ($type === 'PERISHABLE_FOOD') {
            $effectiveDays = min($effectiveDays, 15);
        }

        $nextDue = date('Y-m-d', strtotime("{$completionDate} + {$effectiveDays} days"));

        $this->settingsRepo->updateMachineConfig(
            $machineId,
            $settings['custom_frequency_days'],
            $nextDue
        );

        return $nextDue;
    }

    /**
     * Expira órdenes preventivas vencidas.
     *
     * @return int
     */
    public function expireOverdueOrders(): int
    {
        return $this->orderRepo->expireOverdueOrders();
    }

    /**
     * Gestiona el traslado físico de una máquina de sede en estricto cumplimiento del Artículo III (EARS 2.5):
     * 1. Cancela lógicamente la orden preventiva previa (`status = 'CANCELLED'`) sin borrado físico.
     * 2. Genera una nueva orden preventiva inicial en la sede de destino.
     *
     * @param int $machineId Identificador de la máquina
     * @param int $newLocationId Nueva sede de destino
     * @param array{id?: int|null, role?: string, name?: string} $actor
     * @return PreventiveOrder|null Nueva orden preventiva generada en la sede destino
     */
    public function handleMachineTransfer(int $machineId, int $newLocationId, array $actor = []): ?PreventiveOrder
    {
        // Cancelar lógicamente órdenes activas previas de la máquina (Art. III)
        $existingOrders = $this->orderRepo->findForCoordinatorList(['machine_id' => $machineId]);
        foreach ($existingOrders as $prevOrder) {
            if (in_array($prevOrder->getStatus(), ['PENDING_ASSIGNMENT', 'SCHEDULED'], true)) {
                $this->orderRepo->softCancel(
                    $prevOrder->getId(),
                    'Cancelación lógica por traslado físico de máquina a nueva sede (Art. III).'
                );
            }
        }

        // Generar nueva orden preventiva inicial en la nueva ubicación
        $settings = $this->settingsRepo->getMachineSettings($machineId);
        $dueDate = date('Y-m-d', strtotime('+5 days')); // Planificación inicial inmediata en nueva sede

        $newOrder = $this->orderRepo->create([
            'machine_id' => $machineId,
            'location_id' => $newLocationId,
            'assigned_technician_id' => null,
            'status' => 'PENDING_ASSIGNMENT',
            'order_type' => 'ROUTINE',
            'scheduled_date' => $dueDate,
            'due_date' => $dueDate,
            'notes' => 'Inspección preventiva inicial por reubicación en nueva sede.',
        ]);

        return $newOrder;
    }
}
