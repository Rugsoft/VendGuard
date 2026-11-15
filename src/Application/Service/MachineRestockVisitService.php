<?php

declare(strict_types=1);

namespace VendGuard\Application\Service;

use DateTimeImmutable;
use InvalidArgumentException;
use VendGuard\Core\Domain\Enum\RestockVisitStatus;
use VendGuard\Core\Domain\Exception\InvalidUploadException;
use VendGuard\Core\Domain\Exception\MachineRestockVisitValidationException;
use VendGuard\Core\Domain\Model\DailyHygieneChecklist;
use VendGuard\Core\Domain\Model\MachinePlanogramSlot;
use VendGuard\Core\Domain\Model\MachineRestockVisit;
use VendGuard\Core\Domain\Model\MachineRestockVisitItem;
use VendGuard\Core\Domain\Model\MachineType;
use VendGuard\Core\Domain\Model\RestockerVanProductStock;
use VendGuard\Core\Domain\Repository\InSituRestockVisitGatewayInterface;
use VendGuard\Core\Domain\Repository\MachinePlanogramRepositoryInterface;
use VendGuard\Core\Domain\Repository\MachineRestockVisitRepositoryInterface;
use VendGuard\Core\Domain\Repository\RestockerVanStockRepositoryInterface;
use VendGuard\Core\Domain\Repository\TransactionManagerInterface;
use VendGuard\Core\Service\MachineRestockVisitValidator;
use VendGuard\Infrastructure\Storage\LocalFileUploader;

/**
 * MachineRestockVisitService
 *
 * Caso de uso de la visita de reposición in situ (Módulo 34; RF-02.1–RF-02.3,
 * RF-03.1–RF-03.3, RF-04.2; D1–D4; C1–C3; QA 1–QA 4; Art. II, Art. III.3,
 * Art. V.1, Art. V.2, Art. V.4 y Art. V.5).
 *
 * El servicio es la única puerta de escritura de una visita. Orquesta, en una
 * sola transacción:
 *
 *   1. **Autorización y cerrojos.** Rol `RESTOCKER`, perímetro territorial de
 *      sus zonas vivas (`C2`) y estado sanitario de la máquina (`Art. II`).
 *   2. **Idempotencia (`D4`).** Un `client_mutation_id` repetido devuelve la
 *      visita ya registrada sin volver a descontar furgoneta ni carriles.
 *   3. **`COMPLETED`.** Checklist adaptativo, evidencia y nota, doble FEFO
 *      carril/furgoneta, descuento atómico del lote de furgoneta, movimiento del
 *      carril con `D1`, snapshot de coste (`QA 3`) y vínculo a la orden
 *      `CONFIRMED_BY_RESTOCKER`.
 *   4. **`ABORTED_THERMAL_BREACH`.** Precinto, foto, nota, cero carga, cuarentena
 *      sanitaria o fuera de servicio y ticket `CRITICAL` creado o escalado de
 *      forma idempotente (`QA 2`).
 *   5. **Subida de foto (`Art. V.5`)** validada por tamaño y firma binaria real, y
 *      **volcado de conflicto offline (`C3`)** en `audit_log`.
 *
 * Los mensajes y códigos de error son los del contrato; el controlador no
 * reinterpreta reglas. La persistencia se inyecta: el servicio no conoce PDO.
 */
final class MachineRestockVisitService
{
    public const ACTION_RESTOCK_VISIT_COMPLETED = 'RESTOCK_VISIT_COMPLETED';
    public const ACTION_RESTOCK_VISIT_ABORTED_THERMAL_BREACH = 'RESTOCK_VISIT_ABORTED_THERMAL_BREACH';
    public const ACTION_OFFLINE_RESTOCK_VISIT_CONFLICT_DUMP = 'OFFLINE_RESTOCK_VISIT_CONFLICT_DUMP';

    private MachineRestockVisitRepositoryInterface $visits;

    private MachinePlanogramRepositoryInterface $planograms;

    private RestockerVanStockRepositoryInterface $vanStock;

    private InSituRestockVisitGatewayInterface $gateway;

    private TransactionManagerInterface $transactions;

    private AuditLogger $audit;

    private LocalFileUploader $uploader;

    public function __construct(
        MachineRestockVisitRepositoryInterface $visits,
        MachinePlanogramRepositoryInterface $planograms,
        RestockerVanStockRepositoryInterface $vanStock,
        InSituRestockVisitGatewayInterface $gateway,
        TransactionManagerInterface $transactions,
        AuditLogger $audit,
        ?LocalFileUploader $uploader = null
    ) {
        $this->visits = $visits;
        $this->planograms = $planograms;
        $this->vanStock = $vanStock;
        $this->gateway = $gateway;
        $this->transactions = $transactions;
        $this->audit = $audit;
        $this->uploader = $uploader ?? new LocalFileUploader();
    }

    /**
     * Registra una visita completa o un aborto térmico.
     *
     * @param int $machineId Máquina del perímetro del reponedor.
     * @param array<string, mixed> $payload Cuerpo del asistente móvil.
     * @param array<string, mixed> $actor Usuario autenticado (`id`, `role`, `name`).
     *
     * @return array<string, mixed> Visita persistida, sin costes si es del reponedor.
     *
     * @throws MachineRestockVisitValidationException
     */
    public function registerVisit(int $machineId, array $payload, array $actor): array
    {
        $restockerId = $this->requireRestocker($actor);
        $mutationId = trim((string)($payload['client_mutation_id'] ?? ''));

        if ($mutationId !== '') {
            $existing = $this->visits->findByClientMutationId($restockerId, $mutationId, false);
            if ($existing !== null) {
                return $existing;
            }
        }

        return $this->transactions->runInTransaction(function () use ($machineId, $payload, $actor, $restockerId, $mutationId): array {
            $context = $this->gateway->lockMachineContext($machineId, $restockerId);
            if ($context === null) {
                throw MachineRestockVisitValidationException::machineNotFound($machineId);
            }

            if (($context['is_covered'] ?? false) !== true) {
                throw MachineRestockVisitValidationException::machineOutsideRestockerZones($machineId, $restockerId);
            }

            MachineRestockVisitValidator::assertMachineAvailable(
                isset($context['sanitary_status']) ? (string)$context['sanitary_status'] : null,
                (bool)($context['is_out_of_service'] ?? false),
                (bool)($context['has_open_thermal_breakdown'] ?? false),
                $machineId
            );

            $machineType = $context['machine_type'] ?? null;
            if (!$machineType instanceof MachineType) {
                throw new InvalidArgumentException('El contexto de la máquina no declara una tipología válida.');
            }

            $status = RestockVisitStatus::fromString((string)($payload['status'] ?? ''));
            $checklist = $this->buildChecklist($payload, $machineType);
            $slots = $this->indexSlots($this->gateway->lockActiveSlots($machineId));
            $lines = [];
            [$addedTotal, $withdrawnTotal] = $this->declaredMovementTotals($payload);
            $cabinTemperature = $this->nullableFloat($payload['cabin_temp_celsius'] ?? null);

            $cabinTemperature = MachineRestockVisitValidator::assertCabinTemperature(
                $machineType,
                $cabinTemperature,
                $status,
                $addedTotal
            );

            if ($status->isCompleted()) {
                $lines = $this->buildLines($payload, $slots);
                $addedTotal = array_sum(array_column($lines, 'qty_added'));
                $withdrawnTotal = array_sum(array_column($lines, 'qty_withdrawn_expired'))
                    + array_sum(array_column($lines, 'qty_withdrawn_damaged'));
            }

            MachineRestockVisitValidator::assertPrecautionarySeal(
                $status,
                $this->toBool($payload['precautionary_seal_applied'] ?? false)
            );

            $photoUrl = $this->normalizeNullableText($payload['photo_evidence_url'] ?? null);
            $internalNotes = $this->normalizeNullableText($payload['internal_notes'] ?? null);

            MachineRestockVisitValidator::assertEvidence(
                $status,
                $withdrawnTotal,
                $photoUrl,
                $internalNotes
            );

            if ($photoUrl !== null) {
                $this->assertStoredPhoto($photoUrl);
            }

            if ($status->isThermalAbort() && ($addedTotal > 0 || $withdrawnTotal > 0)) {
                throw MachineRestockVisitValidationException::slotCapacityOrWithdrawalInvalid(
                    'VISIT',
                    'un aborto térmico no admite movimientos de carril',
                    0,
                    0,
                    0
                );
            }

            if ($status->isCompleted()) {
                MachineRestockVisitValidator::assertHygieneChecklist($status, $machineType, $checklist);
                $this->assertVanFefo($restockerId, $lines);
            }

            $triggeredIncidentId = null;
            $machineCode = (string)($context['machine_code'] ?? ('MÁQUINA-' . $machineId));
            $locationId = (int)($context['location_id'] ?? 0);

            if ($status->isThermalAbort()) {
                $this->gateway->lockMachineSanitarily($machineId, $machineType, 'Rotura de cadena de frío en visita in situ');
                $triggeredIncidentId = $this->gateway->resolveThermalIncident(
                    $machineId,
                    $machineCode,
                    $locationId,
                    isset($context['active_incident_id']) && $context['active_incident_id'] !== null
                        ? (int)$context['active_incident_id']
                        : null,
                    (float)$cabinTemperature,
                    (string)$photoUrl,
                    (string)$internalNotes,
                    $actor
                );
            }

            $visit = new MachineRestockVisit(
                null,
                $this->nextVisitCode(),
                $machineId,
                $restockerId,
                $status,
                $mutationId,
                $checklist,
                $cabinTemperature,
                $this->toBool($payload['precautionary_seal_applied'] ?? false),
                $photoUrl,
                $internalNotes,
                isset($context['loading_order_id']) && $context['loading_order_id'] !== null
                    ? (int)$context['loading_order_id']
                    : null,
                null,
                $this->nullableText($payload['started_at'] ?? null),
                $this->nullableText($payload['completed_at'] ?? null),
                $triggeredIncidentId
            );

            $persistedVisit = $this->visits->insertVisit($visit);
            $visitId = $persistedVisit->getId();
            if ($visitId === null) {
                throw new \RuntimeException('La visita se insertó sin devolver una clave primaria.');
            }

            if ($status->isCompleted()) {
                foreach ($lines as $line) {
                    if (($line['persist'] ?? false) !== true) {
                        continue;
                    }

                    $this->applyCompletedLine($visitId, $restockerId, $line);
                }
            }

            $this->audit->logMachineRestockVisitEvent(
                $visitId,
                $status->isCompleted()
                    ? 'RESTOCK_VISIT_COMPLETED'
                    : 'RESTOCK_VISIT_ABORTED_THERMAL_BREACH',
                [
                    'id' => (int)($actor['id'] ?? 0) > 0 ? (int)$actor['id'] : null,
                    'role' => 'RESTOCKER',
                    'name' => (string)($actor['name'] ?? 'Reponedor'),
                ],
                null,
                [
                    'visit_code' => $persistedVisit->getVisitCode(),
                    'machine_id' => $machineId,
                    'status' => $status->value,
                    'cabin_temp_celsius' => $cabinTemperature,
                    'units_added' => $addedTotal,
                    'units_withdrawn' => $withdrawnTotal,
                    'triggered_incident_id' => $triggeredIncidentId,
                    'loading_order_id' => $persistedVisit->getLoadingOrderId(),
                ],
                ['client_mutation_id' => $mutationId]
            );

            return $this->visits->findById($visitId, false) ?? [
                'id' => $visitId,
                'visit_code' => $persistedVisit->getVisitCode(),
                'machine_id' => $machineId,
                'restocker_id' => $restockerId,
                'status' => $status->value,
                'triggered_incident_id' => $triggeredIncidentId,
            ];
        });
    }

    /**
     * Sube la fotografía de evidencia y devuelve su ruta pública normalizada.
     *
     * `LocalFileUploader` verifica el límite de 5 MB y los magic bytes reales
     * (JPEG, PNG o WebP), no la extensión declarada (Art. V.5).
     *
     * @param array<string, mixed> $file Entrada de `$_FILES`.
     *
     * @throws InvalidUploadException
     */
    public function uploadPhoto(array $file): string
    {
        return $this->uploader->upload($file);
    }

    /**
     * Archiva en `audit_log` una mutación offline que ya no puede aplicarse (C3).
     *
     * No escribe visitas: la máquina o el saldo cambiaron y el cliente necesita
     * constancia del conflicto sin corromper los saldos del servidor.
     *
     * @param array<string, mixed> $payload Datos del conflicto (`machine_id`, `reason`, `client_mutation_id`).
     * @param array<string, mixed> $actor Usuario autenticado.
     *
     * @return array<string, string> Acuse con el estado `ARCHIVED`.
     */
    public function dumpOfflineConflict(array $payload, array $actor): array
    {
        $restockerId = $this->requireRestocker($actor);
        $machineId = (int)($payload['machine_id'] ?? 0);
        if ($machineId <= 0) {
            throw new InvalidArgumentException('El volcado de conflicto offline exige machine_id.');
        }

        $reason = trim((string)($payload['reason'] ?? 'La visita offline ya no puede aplicarse'));

        $this->audit->logMachineRestockVisitEvent(
            $machineId,
            'OFFLINE_RESTOCK_VISIT_CONFLICT_DUMP',
            [
                'id' => (int)($actor['id'] ?? 0) > 0 ? (int)$actor['id'] : null,
                'role' => 'RESTOCKER',
                'name' => (string)($actor['name'] ?? 'Reponedor'),
            ],
            null,
            [
                'restocker_id' => $restockerId,
                'machine_id' => $machineId,
                'client_mutation_id' => trim((string)($payload['client_mutation_id'] ?? '')),
                'reason' => $reason,
                'archived_at' => (new DateTimeImmutable('now'))->format(DATE_ATOM),
            ],
            ['origin' => 'OFFLINE_QUEUE']
        );

        return ['status' => 'ARCHIVED', 'reason' => $reason];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function buildChecklist(array $payload, MachineType $machineType): DailyHygieneChecklist
    {
        $raw = $payload['hygiene_checklist_json'] ?? $payload['hygiene_checklist'] ?? [];

        if (is_string($raw)) {
            return DailyHygieneChecklist::fromJson($raw, $machineType);
        }

        if (!is_array($raw)) {
            throw new InvalidArgumentException('El checklist de higiene debe ser un objeto JSON.');
        }

        /** @var array<string, mixed> $raw */
        return DailyHygieneChecklist::fromArray($raw, $machineType);
    }

    /**
     * @param list<MachinePlanogramSlot> $slots
     *
     * @return array<string, MachinePlanogramSlot>
     */
    private function indexSlots(array $slots): array
    {
        $indexed = [];
        foreach ($slots as $slot) {
            if (!$slot instanceof MachinePlanogramSlot) {
                throw new InvalidArgumentException('El gateway devolvió un carril que no es MachinePlanogramSlot.');
            }
            $indexed[$slot->getSlotCode()] = $slot;
        }

        return $indexed;
    }

    /**
     * Suma de movimientos declarados en un aborto térmico.
     *
     * El aborto no valida FEFO de carril porque no mueve existencias: solo
     * comprueba que el payload no intente cargar ni retirar nada.
     *
     * @param array<string, mixed> $payload
     *
     * @return array{0: int, 1: int}
     */
    private function declaredMovementTotals(array $payload): array
    {
        $rawItems = $payload['items'] ?? [];
        if (!is_array($rawItems)) {
            throw new InvalidArgumentException('items debe ser una lista de líneas de visita.');
        }

        $added = 0;
        $withdrawn = 0;
        foreach ($rawItems as $rawItem) {
            if (!is_array($rawItem)) {
                throw new InvalidArgumentException('Cada línea de visita debe ser un objeto.');
            }

            $withdrawnExpired = (int)($rawItem['qty_withdrawn_expired'] ?? 0);
            $withdrawnDamaged = (int)($rawItem['qty_withdrawn_damaged'] ?? 0);
            $qtyAdded = (int)($rawItem['qty_added'] ?? 0);

            if ($withdrawnExpired < 0 || $withdrawnDamaged < 0 || $qtyAdded < 0) {
                throw MachineRestockVisitValidationException::slotCapacityOrWithdrawalInvalid(
                    (string)($rawItem['slot_code'] ?? 'VISIT'),
                    'las cantidades de la línea no pueden ser negativas',
                    0,
                    0,
                    0
                );
            }

            $withdrawn += $withdrawnExpired + $withdrawnDamaged;
            $added += $qtyAdded;
        }

        return [$added, $withdrawn];
    }

    /**
     * Construye una línea por carril activo; las no declaradas cuentan como cero.
     *
     * Esto es lo que hace que un lote caducado en un carril no declarado siga
     * bloqueando la visita: el FEFO se comprueba sobre todo el planograma, no solo
     * sobre las líneas que el móvil envió.
     *
     * @param array<string, mixed> $payload
     * @param array<string, MachinePlanogramSlot> $slots
     *
     * @return list<array<string, mixed>>
     */
    private function buildLines(array $payload, array $slots): array
    {
        $rawItems = $payload['items'] ?? [];
        if (!is_array($rawItems)) {
            throw new InvalidArgumentException('items debe ser una lista de líneas de visita.');
        }

        $declared = [];
        foreach ($rawItems as $rawItem) {
            if (!is_array($rawItem)) {
                throw new InvalidArgumentException('Cada línea de visita debe ser un objeto.');
            }

            /** @var array<string, mixed> $rawItem */
            $slotCode = strtoupper(trim((string)($rawItem['slot_code'] ?? '')));
            if ($slotCode === '') {
                throw new InvalidArgumentException('Cada línea de visita exige slot_code.');
            }

            if (isset($declared[$slotCode])) {
                throw MachineRestockVisitValidationException::slotCapacityOrWithdrawalInvalid(
                    $slotCode,
                    'el carril está duplicado en la visita',
                    0,
                    0,
                    0
                );
            }

            if (!isset($slots[$slotCode])) {
                throw MachineRestockVisitValidationException::slotCapacityOrWithdrawalInvalid(
                    $slotCode,
                    'el carril no pertenece al planograma activo de la máquina',
                    0,
                    0,
                    0
                );
            }

            $declared[$slotCode] = $rawItem;
        }

        $lines = [];
        foreach ($slots as $slotCode => $slot) {
            $raw = $declared[$slotCode] ?? [];
            $withdrawnExpired = (int)($raw['qty_withdrawn_expired'] ?? 0);
            $withdrawnDamaged = (int)($raw['qty_withdrawn_damaged'] ?? 0);
            $added = (int)($raw['qty_added'] ?? 0);

            $qtyAfter = MachineRestockVisitValidator::assertSlotMovement(
                $slot,
                $withdrawnExpired,
                $withdrawnDamaged,
                $added
            );

            $loadedBatch = $this->normalizeNullableText($raw['loaded_batch_code'] ?? null);
            $lines[] = [
                'slot' => $slot,
                'slot_code' => $slotCode,
                'product_id' => $slot->getProductId(),
                'qty_before' => $slot->getCurrentEstimatedQty(),
                'qty_withdrawn_expired' => $withdrawnExpired,
                'qty_withdrawn_damaged' => $withdrawnDamaged,
                'qty_added' => $added,
                'qty_after' => $qtyAfter,
                'loaded_batch_code' => $added > 0 ? $loadedBatch : null,
                'persist' => isset($declared[$slotCode])
                    || $withdrawnExpired > 0
                    || $withdrawnDamaged > 0
                    || $added > 0,
            ];
        }

        return $lines;
    }

    /**
     * Doble FEFO de la visita completada sobre los lotes reales de la furgoneta.
     *
     * @param list<array<string, mixed>> $lines
     */
    private function assertVanFefo(int $restockerId, array $lines): void
    {
        $addedLines = array_values(array_filter(
            $lines,
            static fn (array $line): bool => (int)$line['qty_added'] > 0
        ));

        if ($addedLines === []) {
            return;
        }

        $lotsByProduct = [];
        foreach ($this->vanStock->findByRestocker($restockerId) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $lot = RestockerVanProductStock::fromDatabaseRow($row);
            $lotsByProduct[$lot->getProductId()][] = $lot;
        }

        /** @var array<int, array<string, int>> $consumed */
        $consumed = [];
        foreach ($addedLines as $line) {
            $productId = (int)$line['product_id'];
            $batchCode = strtoupper(trim((string)$line['loaded_batch_code']));
            $qtyAdded = (int)$line['qty_added'];
            $lots = $lotsByProduct[$productId] ?? [];

            MachineRestockVisitValidator::assertVanBatchSelection(
                $lots,
                $batchCode,
                $qtyAdded,
                $consumed[$productId] ?? []
            );

            $consumed[$productId][$batchCode] = (int)($consumed[$productId][$batchCode] ?? 0) + $qtyAdded;
        }

        foreach ($consumed as $productId => $batches) {
            foreach ($batches as $batchCode => $required) {
                $available = 0;
                foreach ($lotsByProduct[$productId] ?? [] as $lot) {
                    if ($lot->getBatchCode() === $batchCode) {
                        $available += $lot->getQuantityAvailable();
                    }
                }

                if ($available < $required) {
                    throw MachineRestockVisitValidationException::insufficientVanBatchStock(
                        (string)$batchCode,
                        $available,
                        $required
                    );
                }
            }
        }
    }

    /**
     * Aplica una línea completada: carril, furgoneta, snapshot y Tabla 48.
     *
     * El lote se bloquea y se descuenta antes de mover el carril para que la
     * regla `D1` reciba la caducidad real del lote cargado; un fallo en cualquier
     * punto deshace la visita completa.
     *
     * @param array<string, mixed> $line
     */
    private function applyCompletedLine(int $visitId, int $restockerId, array $line): void
    {
        /** @var MachinePlanogramSlot $slot */
        $slot = $line['slot'];
        $slotCode = (string)$line['slot_code'];
        $productId = (int)$line['product_id'];
        $qtyAdded = (int)$line['qty_added'];
        $loadedBatch = $line['loaded_batch_code'] !== null ? (string)$line['loaded_batch_code'] : null;
        $loadedExpiration = null;

        if ($qtyAdded > 0 && $loadedBatch !== null) {
            $lot = $this->vanStock->findLot($restockerId, $productId, $loadedBatch, true);
            if ($lot === null) {
                throw MachineRestockVisitValidationException::insufficientVanBatchStock($loadedBatch, 0, $qtyAdded);
            }

            if ($lot->getQuantityAvailable() < $qtyAdded) {
                throw MachineRestockVisitValidationException::insufficientVanBatchStock(
                    $loadedBatch,
                    $lot->getQuantityAvailable(),
                    $qtyAdded
                );
            }

            $loadedExpiration = $lot->getExpirationDate();
            $this->vanStock->upsertLot($lot->withQuantity($lot->getQuantityAvailable() - $qtyAdded));
        }

        $this->planograms->applyVisitSlotMovement(
            $slot->getMachineId(),
            $slotCode,
            (int)$line['qty_withdrawn_expired'],
            (int)$line['qty_withdrawn_damaged'],
            $qtyAdded,
            $loadedBatch,
            $loadedExpiration
        );

        $withdrawn = (int)$line['qty_withdrawn_expired'] + (int)$line['qty_withdrawn_damaged'];

        $this->visits->insertItem(new MachineRestockVisitItem(
            null,
            $visitId,
            $slot->getId(),
            $slotCode,
            $productId,
            (int)$line['qty_before'],
            (int)$line['qty_withdrawn_expired'],
            (int)$line['qty_withdrawn_damaged'],
            $qtyAdded,
            $this->gateway->findProductUnitCost($productId),
            $loadedBatch,
            $loadedExpiration,
            $withdrawn > 0 ? $slot->getCurrentBatchCode() : null,
            $withdrawn > 0 ? $slot->getCurrentExpirationDate() : null
        ));
    }

    private function assertStoredPhoto(string $photoUrl): void
    {
        try {
            $this->uploader->validateStoredImage($photoUrl);
        } catch (InvalidUploadException $exception) {
            throw MachineRestockVisitValidationException::photoEvidenceRequired(
                $exception->getMessage(),
                ['photo_evidence_url' => $photoUrl]
            );
        }
    }

    /**
     * @param array<string, mixed> $actor
     */
    private function requireRestocker(array $actor): int
    {
        $role = strtoupper(trim((string)($actor['role'] ?? '')));
        if ($role !== 'RESTOCKER') {
            throw MachineRestockVisitValidationException::restockerRoleRequired($role);
        }

        $actorId = (int)($actor['id'] ?? 0);
        if ($actorId <= 0) {
            throw new InvalidArgumentException('El actor RESTOCKER debe tener un identificador persistido.');
        }

        return $actorId;
    }

    private function nextVisitCode(): string
    {
        return sprintf('MRV-%s-%06d', (new DateTimeImmutable('now'))->format('Y'), random_int(1, 999999));
    }

    private function nullableFloat(mixed $value): ?float
    {
        return is_numeric($value) ? (float)$value : null;
    }

    private function nullableText(mixed $value): ?string
    {
        $text = trim((string)($value ?? ''));

        return $text === '' ? null : $text;
    }

    private function normalizeNullableText(mixed $value): ?string
    {
        return $this->nullableText($value);
    }

    private function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower((string)$value), ['1', 'true', 'on', 'yes', 'y'], true);
    }
}
