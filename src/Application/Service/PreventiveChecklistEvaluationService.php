<?php

declare(strict_types=1);

namespace VendGuard\Application\Service;

use InvalidArgumentException;
use VendGuard\Core\Domain\Exception\ChecklistIncompleteException;
use VendGuard\Core\Domain\Exception\InvalidTemperatureRangeException;
use VendGuard\Core\Domain\Exception\PreventiveOrderNotInInspectionException;
use VendGuard\Core\Domain\Model\PreventiveOrderItem;
use VendGuard\Core\Domain\Repository\MachineRepositoryInterface;
use VendGuard\Core\Domain\Repository\PreventiveItemRepositoryInterface;
use VendGuard\Core\Domain\Repository\PreventiveOrderRepositoryInterface;
use VendGuard\Core\Domain\Repository\PreventiveSettingsRepositoryInterface;
use VendGuard\Infrastructure\Repository\PdoMachineRepository;

/**
 * PreventiveChecklistEvaluationService
 *
 * Motor de evaluación y dictamen del checklist normativo higiénico-sanitario.
 * Aplica:
 * - RF-PREV-03: Control de ítems estandarizados por tipología y severidades normativas.
 * - RNF-06: Blindaje térmico estricto en el rango físico [-5.0, 25.0] °C con 1 decimal.
 * - RF-PREV-04: Evaluación unívoca de dictámenes (CONFORME, CONFORME_CON_OBSERVACIONES, NO_CONFORME).
 * - Constitución Art. II: Activación inmediata de Cuarentena Sanitaria ante rotura de frío (> 4.0 °C) o fallos críticos.
 */
class PreventiveChecklistEvaluationService
{
    private PreventiveOrderRepositoryInterface $orderRepo;
    private PreventiveItemRepositoryInterface $itemRepo;
    private PreventiveSettingsRepositoryInterface $settingsRepo;
    private MachineRepositoryInterface $machineRepo;
    private AuditLogger $auditLogger;

    public function __construct(
        PreventiveOrderRepositoryInterface $orderRepo,
        PreventiveItemRepositoryInterface $itemRepo,
        PreventiveSettingsRepositoryInterface $settingsRepo,
        ?MachineRepositoryInterface $machineRepo = null,
        ?AuditLogger $auditLogger = null
    ) {
        $this->orderRepo = $orderRepo;
        $this->itemRepo = $itemRepo;
        $this->settingsRepo = $settingsRepo;
        $this->machineRepo = $machineRepo ?? new PdoMachineRepository();
        $this->auditLogger = $auditLogger ?? new AuditLogger();
    }

    /**
     * Catálogo normativo de comprobaciones obligatorias según la tipología de máquina.
     *
     * @param string $machineType
     * @return array<int, array<string, mixed>>
     */
    public function getChecklistTemplate(string $machineType): array
    {
        $type = strtoupper(trim($machineType));

        return match ($type) {
            'PERISHABLE_FOOD' => [
                [
                    'item_code' => 'TEMP_PROBE',
                    'title' => 'Medición de Temperatura de Sonda Estabilizada',
                    'description' => 'Termómetro calibrado en bandeja central refrigerada (límite <= 4.0 °C)',
                    'is_critical' => true,
                    'input_type' => 'TEMPERATURE_DECIMAL',
                    'unit' => '°C',
                ],
                [
                    'item_code' => 'SEALS_GASKET',
                    'title' => 'Hermetismo y Estado de Gomas Magnéticas',
                    'description' => 'Comprobar ausencia de holguras o fisuras en cierre de puerta',
                    'is_critical' => false,
                    'input_type' => 'STATUS_CHOICE',
                ],
                [
                    'item_code' => 'EVAPORATOR_FROST',
                    'title' => 'Evaporador y Circulación de Aire',
                    'description' => 'Comprobar ausencia de escarcha o bloqueo de ventilación',
                    'is_critical' => true,
                    'input_type' => 'STATUS_CHOICE',
                ],
                [
                    'item_code' => 'DISINFECTION_TRAYS',
                    'title' => 'Desinfección de Bandejas y Cajón de Dispensación',
                    'description' => 'Limpieza bactericida con producto apto para contacto alimentario',
                    'is_critical' => true,
                    'input_type' => 'STATUS_CHOICE',
                ],
                [
                    'item_code' => 'EXPIRATION_DATES',
                    'title' => 'Control de Fechas de Caducidad',
                    'description' => 'Verificar ausencia de productos perecederos caducados en espirales',
                    'is_critical' => true,
                    'input_type' => 'STATUS_CHOICE',
                ],
                [
                    'item_code' => 'ELECTRICAL_SAFETY',
                    'title' => 'Seguridad Eléctrica y Toma de Tierra',
                    'description' => 'Comprobar cable de red, enchufe y continuidad de tierra',
                    'is_critical' => true,
                    'input_type' => 'STATUS_CHOICE',
                ],
            ],

            'HOT_DRINKS' => [
                [
                    'item_code' => 'BOILER_HYDRAULIC',
                    'title' => 'Caldera y Circuito Hidráulico',
                    'description' => 'Comprobar estanqueidad absoluta y ausencia de fugas o incrustaciones',
                    'is_critical' => true,
                    'input_type' => 'STATUS_CHOICE',
                ],
                [
                    'item_code' => 'DISINFECTION_WHIPPERS',
                    'title' => 'Desinfección de Batidores y Boquillas',
                    'description' => 'Higienización y descalcificación de conductos de infusión',
                    'is_critical' => true,
                    'input_type' => 'STATUS_CHOICE',
                ],
                [
                    'item_code' => 'WATER_FILTER',
                    'title' => 'Filtro de Purificación de Agua',
                    'description' => 'Verificar vigencia de cartucho filtrante y presión de red',
                    'is_critical' => false,
                    'input_type' => 'STATUS_CHOICE',
                ],
                [
                    'item_code' => 'WASTE_TRAY',
                    'title' => 'Bandeja y Boya de Residuos Líquidos',
                    'description' => 'Limpieza y funcionamiento correcto de detección de nivel',
                    'is_critical' => false,
                    'input_type' => 'STATUS_CHOICE',
                ],
                [
                    'item_code' => 'ELECTRICAL_SAFETY',
                    'title' => 'Seguridad Eléctrica y Toma de Tierra',
                    'description' => 'Comprobar cable de red, enchufe y continuidad de tierra',
                    'is_critical' => true,
                    'input_type' => 'STATUS_CHOICE',
                ],
            ],

            'COLD_DRINKS' => [
                [
                    'item_code' => 'TEMP_PROBE',
                    'title' => 'Medición de Temperatura de Refrigeración',
                    'description' => 'Medición en compartimento de latas/botellas (límite <= 8.0 °C)',
                    'is_critical' => true,
                    'input_type' => 'TEMPERATURE_DECIMAL',
                    'unit' => '°C',
                ],
                [
                    'item_code' => 'SEALS_GASKET',
                    'title' => 'Hermetismo y Estado de Gomas Magnéticas',
                    'description' => 'Comprobar ausencia de holguras en puerta',
                    'is_critical' => false,
                    'input_type' => 'STATUS_CHOICE',
                ],
                [
                    'item_code' => 'EVAPORATOR_FROST',
                    'title' => 'Evaporador y Circulación de Aire',
                    'description' => 'Comprobar ausencia de escarcha o bloqueo de ventilación',
                    'is_critical' => true,
                    'input_type' => 'STATUS_CHOICE',
                ],
                [
                    'item_code' => 'DISINFECTION_CHUTE',
                    'title' => 'Desinfección de Compartimento de Recogida',
                    'description' => 'Higienización bactericida del cajón dispensador',
                    'is_critical' => true,
                    'input_type' => 'STATUS_CHOICE',
                ],
                [
                    'item_code' => 'ELECTRICAL_SAFETY',
                    'title' => 'Seguridad Eléctrica y Toma de Tierra',
                    'description' => 'Comprobar cable de red y toma de tierra',
                    'is_critical' => true,
                    'input_type' => 'STATUS_CHOICE',
                ],
            ],

            'SNACKS' => [
                [
                    'item_code' => 'DISINFECTION_CHUTE',
                    'title' => 'Limpieza y Desinfección de Compartimento de Recogida',
                    'description' => 'Higienización bactericida del cajón de dispensación',
                    'is_critical' => true,
                    'input_type' => 'STATUS_CHOICE',
                ],
                [
                    'item_code' => 'SPIRALS_ALIGNMENT',
                    'title' => 'Alineación de Espirales y Motores',
                    'description' => 'Verificar caída fluida de productos y giro de espirales',
                    'is_critical' => false,
                    'input_type' => 'STATUS_CHOICE',
                ],
                [
                    'item_code' => 'CASING_CLEANLINESS',
                    'title' => 'Limpieza Exterior y Botonera',
                    'description' => 'Limpieza de paneles, cristal frontal y teclado selector',
                    'is_critical' => false,
                    'input_type' => 'STATUS_CHOICE',
                ],
                [
                    'item_code' => 'ELECTRICAL_SAFETY',
                    'title' => 'Seguridad Eléctrica y Toma de Tierra',
                    'description' => 'Comprobar cable de red y toma de tierra',
                    'is_critical' => true,
                    'input_type' => 'STATUS_CHOICE',
                ],
            ],

            'COMBO' => [
                [
                    'item_code' => 'TEMP_PROBE',
                    'title' => 'Medición de Temperatura de Sonda Estabilizada',
                    'description' => 'Termómetro calibrado en compartimento refrigerado (límite <= 4.0 °C)',
                    'is_critical' => true,
                    'input_type' => 'TEMPERATURE_DECIMAL',
                    'unit' => '°C',
                ],
                [
                    'item_code' => 'SEALS_GASKET',
                    'title' => 'Hermetismo y Estado de Gomas Magnéticas',
                    'description' => 'Comprobar ausencia de holguras o fisuras',
                    'is_critical' => false,
                    'input_type' => 'STATUS_CHOICE',
                ],
                [
                    'item_code' => 'EVAPORATOR_FROST',
                    'title' => 'Evaporador y Circulación de Aire',
                    'description' => 'Comprobar ausencia de escarcha o bloqueo de ventilador',
                    'is_critical' => true,
                    'input_type' => 'STATUS_CHOICE',
                ],
                [
                    'item_code' => 'DISINFECTION_TRAYS',
                    'title' => 'Desinfección de Bandejas y Cajón de Dispensación',
                    'description' => 'Limpieza bactericida con producto apto para contacto alimentario',
                    'is_critical' => true,
                    'input_type' => 'STATUS_CHOICE',
                ],
                [
                    'item_code' => 'EXPIRATION_DATES',
                    'title' => 'Control de Fechas de Caducidad',
                    'description' => 'Verificar fechas de caducidad en alimentos refrigerados',
                    'is_critical' => true,
                    'input_type' => 'STATUS_CHOICE',
                ],
                [
                    'item_code' => 'ELECTRICAL_SAFETY',
                    'title' => 'Seguridad Eléctrica y Toma de Tierra',
                    'description' => 'Comprobar cable de red, enchufe y continuidad de tierra',
                    'is_critical' => true,
                    'input_type' => 'STATUS_CHOICE',
                ],
            ],

            default => [
                [
                    'item_code' => 'GENERAL_CLEANLINESS',
                    'title' => 'Limpieza e Higienización General',
                    'description' => 'Inspección higiénica estándar',
                    'is_critical' => true,
                    'input_type' => 'STATUS_CHOICE',
                ],
                [
                    'item_code' => 'ELECTRICAL_SAFETY',
                    'title' => 'Seguridad Eléctrica y Toma de Tierra',
                    'description' => 'Comprobar cable de red y toma de tierra',
                    'is_critical' => true,
                    'input_type' => 'STATUS_CHOICE',
                ],
            ],
        };
    }

    /**
     * Determina si una tipología de máquina exige medición térmica obligatoria.
     */
    public function isRefrigeratedMachineType(string $machineType): bool
    {
        return in_array(strtoupper(trim($machineType)), ['PERISHABLE_FOOD', 'COLD_DRINKS', 'COMBO'], true);
    }

    /**
     * Inicia formalmente la inspección preventiva in situ (EARS 3.1).
     *
     * @param int $orderId
     * @param int $technicianId
     * @return bool
     * @throws InvalidArgumentException Si la orden no existe.
     * @throws PreventiveOrderNotInInspectionException Si la orden no está en SCHEDULED o EXPIRED.
     */
    public function startInspection(int $orderId, int $technicianId): bool
    {
        $order = $this->orderRepo->findById($orderId);
        if (!$order) {
            throw new InvalidArgumentException("Orden preventiva con ID {$orderId} no encontrada.");
        }

        if (!in_array($order->getStatus(), ['SCHEDULED', 'EXPIRED'], true)) {
            throw new PreventiveOrderNotInInspectionException(
                "La orden no puede iniciarse porque se encuentra en estado '{$order->getStatus()}'.",
                $orderId,
                $order->getStatus()
            );
        }

        return $this->orderRepo->startInspection($orderId, $technicianId);
    }

    /**
     * Evalúa el checklist remitido por el técnico, aplicando la validación física de temperatura (RNF-06),
     * dictaminando el resultado y aplicando Cuarentena Sanitaria si procede (Art. II).
     *
     * @param int $orderId
     * @param int $technicianId
     * @param array<string, mixed> $inputData
     * @param array{id?: int|null, role?: string, name?: string} $actor
     * @return array<string, mixed>
     * @throws PreventiveOrderNotInInspectionException Si la orden no está en IN_INSPECTION.
     * @throws ChecklistIncompleteException Si faltan respuestas de comprobaciones obligatorias.
     * @throws InvalidTemperatureRangeException Si la temperatura no es decimal o excede [-5.0, 25.0] °C.
     */
    public function evaluateChecklist(
        int $orderId,
        int $technicianId,
        array $inputData,
        array $actor = []
    ): array {
        // 1. Validar que la orden existe y está en estado IN_INSPECTION (EARS 3.1)
        $order = $this->orderRepo->findById($orderId);
        if (!$order) {
            throw new InvalidArgumentException("Orden preventiva con ID {$orderId} no encontrada.");
        }

        if ($order->getStatus() !== 'IN_INSPECTION') {
            throw new PreventiveOrderNotInInspectionException(
                "La orden debe estar en estado 'IN_INSPECTION' para poder evaluar el checklist (estado actual: '{$order->getStatus()}').",
                $orderId,
                $order->getStatus()
            );
        }

        $machineId = $order->getMachineId();
        $machineSettings = $this->settingsRepo->getMachineSettings($machineId);
        $machineType = strtoupper(trim((string)($machineSettings['machine_type'] ?? 'PERISHABLE_FOOD')));
        $isRefrigerated = $this->isRefrigeratedMachineType($machineType);

        // 2. Validación de Temperatura Física y Formato (RNF-06 y EARS 3.2)
        $tempInput = $inputData['temperature_measured'] ?? null;
        $temperature = null;

        if ($isRefrigerated) {
            if ($tempInput === null || $tempInput === '') {
                throw new ChecklistIncompleteException(
                    'La medición de temperatura de sonda estabilizada es obligatoria para máquinas de refrigeración.',
                    ['TEMP_PROBE']
                );
            }

            if (!is_numeric($tempInput)) {
                throw new InvalidTemperatureRangeException(
                    'Formato de temperatura no numérico.',
                    (float)$tempInput,
                    -5.0,
                    25.0
                );
            }

            $temperature = round((float)$tempInput, 1);

            // Blindaje de rango físico estricto [-5.0, +25.0] °C
            if ($temperature < -5.0 || $temperature > 25.0) {
                throw new InvalidTemperatureRangeException(
                    "Temperatura registrada ({$temperature} °C) fuera del rango físico admisible [-5.0 °C, +25.0 °C].",
                    $temperature,
                    -5.0,
                    25.0
                );
            }
        } elseif ($tempInput !== null && $tempInput !== '') {
            $temperature = round((float)$tempInput, 1);
            if ($temperature < -5.0 || $temperature > 25.0) {
                throw new InvalidTemperatureRangeException(
                    "Temperatura registrada ({$temperature} °C) fuera de límites físicos.",
                    $temperature,
                    -5.0,
                    25.0
                );
            }
        }

        // 3. Validación de Completitud de Ítems del Checklist (EARS 3.4)
        $template = $this->getChecklistTemplate($machineType);
        $templateMap = [];
        $requiredCodes = [];

        foreach ($template as $tItem) {
            $code = (string)$tItem['item_code'];
            $templateMap[$code] = $tItem;
            $requiredCodes[] = $code;
        }

        $providedItems = $inputData['items'] ?? [];
        $providedCodes = [];
        $providedMap = [];

        foreach ($providedItems as $pItem) {
            $pCode = trim((string)$pItem['item_code']);
            $providedCodes[] = $pCode;
            $providedMap[$pCode] = $pItem;
        }

        $missingItems = array_diff($requiredCodes, $providedCodes);
        if (!empty($missingItems)) {
            throw new ChecklistIncompleteException(
                'El checklist no puede completarse porque faltan comprobaciones normativas obligatorias por responder.',
                array_values($missingItems)
            );
        }

        // 4. Procesamiento de Ítems y Dictamen de Conformidad (EARS 4.1 y Art. II)
        $hasCriticalFailure = false;
        $hasSecondaryWarning = false;
        $itemsToPersist = [];

        foreach ($template as $tItem) {
            $code = (string)$tItem['item_code'];
            $pItem = $providedMap[$code];

            $status = strtoupper(trim((string)$pItem['status']));
            $isCritical = (bool)$tItem['is_critical'];
            $observations = isset($pItem['observations']) && trim((string)$pItem['observations']) !== ''
                ? trim((string)$pItem['observations'])
                : null;
            $photoPath = isset($pItem['photo_path']) && trim((string)$pItem['photo_path']) !== ''
                ? trim((string)$pItem['photo_path'])
                : null;

            // Regla de frío estricta para alimentos perecederos (Artículo II):
            // Si la temperatura supera los 4.0 °C, el ítem TEMP_PROBE es forzosamente FAIL y crítico
            if ($code === 'TEMP_PROBE') {
                if (in_array($machineType, ['PERISHABLE_FOOD', 'COMBO'], true) && $temperature !== null && $temperature > 4.0) {
                    $status = 'FAIL';
                    $observations = ($observations ? $observations . ' | ' : '') .
                        "ALERTA ART. II: Temperatura medida {$temperature} °C excede el límite legal de 4.0 °C.";
                } elseif ($machineType === 'COLD_DRINKS' && $temperature !== null && $temperature > 8.0) {
                    $status = 'FAIL';
                    $observations = ($observations ? $observations . ' | ' : '') .
                        "Temperatura medida {$temperature} °C excede el límite de bebidas frías (8.0 °C).";
                }
            }

            if ($status === 'FAIL') {
                if ($isCritical) {
                    $hasCriticalFailure = true;
                } else {
                    $hasSecondaryWarning = true;
                }
            } elseif ($status === 'WARN') {
                $hasSecondaryWarning = true;
            }

            $itemsToPersist[] = new PreventiveOrderItem(
                $orderId,
                $code,
                (string)$tItem['title'],
                $isCritical,
                $status,
                $observations,
                $photoPath
            );
        }

        // Si la temperatura de perecederos excedió 4.0 °C, garantizar siempre fallo crítico
        if (in_array($machineType, ['PERISHABLE_FOOD', 'COMBO'], true) && $temperature !== null && $temperature > 4.0) {
            $hasCriticalFailure = true;
        }

        // 5. Determinación del Dictamen Final y Consecuencias Sanitarias (EARS 4.1 y 4.2)
        if ($hasCriticalFailure) {
            $verdict = 'NO_CONFORME';
            $isQuarantineTriggered = true;
            $machineSanitaryStatus = 'QUARANTINE';
        } elseif ($hasSecondaryWarning) {
            $verdict = 'CONFORME_CON_OBSERVACIONES';
            $isQuarantineTriggered = false;
            $machineSanitaryStatus = 'OK';
        } else {
            $verdict = 'CONFORME';
            $isQuarantineTriggered = false;
            $machineSanitaryStatus = 'OK';
        }

        // 6. Persistencia de Ítems en Lote (T-PREV-06)
        $this->itemRepo->saveOrderItems($orderId, $itemsToPersist);

        // 7. Cierre de la Orden Preventiva (T-PREV-05)
        $generalNotes = isset($inputData['general_notes']) && trim((string)$inputData['general_notes']) !== ''
            ? trim((string)$inputData['general_notes'])
            : ($inputData['notes'] ?? null);

        $this->orderRepo->completeOrder(
            $orderId,
            $verdict,
            $temperature,
            $isQuarantineTriggered,
            null,
            $generalNotes
        );

        // 8. Actualización del Estado Sanitario de la Máquina
        $this->settingsRepo->updateSanitaryStatus($machineId, $machineSanitaryStatus);

        // 9. Registro en Bitácora de Auditoría Inmutable (RF-05)
        $user = [
            'id' => $actor['id'] ?? $technicianId,
            'role' => $actor['role'] ?? 'TECHNICIAN',
            'name' => $actor['name'] ?? 'Técnico de Campo',
        ];

        $this->auditLogger->logMachineEvent(
            $machineId,
            'EVALUATE_PREVENTIVE_CHECKLIST',
            $user,
            ['sanitary_status' => $machineSettings['sanitary_status'] ?? 'OK'],
            [
                'sanitary_status' => $machineSanitaryStatus,
                'verdict' => $verdict,
                'order_id' => $orderId,
                'temperature' => $temperature,
                'is_quarantine' => $isQuarantineTriggered,
            ]
        );

        return [
            'order_id' => $orderId,
            'order_code' => $order->getOrderCode(),
            'result' => $verdict,
            'status' => 'COMPLETED',
            'is_quarantine_triggered' => $isQuarantineTriggered,
            'temperature_measured' => $temperature,
            'machine_sanitary_status' => $machineSanitaryStatus,
            'items_count' => count($itemsToPersist),
            'has_critical_failure' => $hasCriticalFailure,
            'has_warning' => $hasSecondaryWarning,
        ];
    }
}
