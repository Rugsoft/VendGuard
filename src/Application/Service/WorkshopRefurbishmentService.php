<?php

declare(strict_types=1);

namespace VendGuard\Application\Service;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use VendGuard\Application\DTO\ReceiveWorkshopItemRequestDTO;
use VendGuard\Application\DTO\ResolveWorkshopItemRequestDTO;
use VendGuard\Application\DTO\WorkshopTrayQueryDTO;
use VendGuard\Core\Domain\Entity\WorkshopRepairItem;
use VendGuard\Core\Domain\Exception\WorkshopRepairValidationException;
use VendGuard\Core\Domain\Model\AuditEvent;
use VendGuard\Core\Domain\Repository\SparePartRepositoryInterface;
use VendGuard\Core\Domain\Repository\TransactionManagerInterface;
use VendGuard\Core\Domain\Repository\UserRepositoryInterface;
use VendGuard\Core\Domain\Repository\WorkshopRepairItemRepositoryInterface;
use VendGuard\Core\Domain\Service\BenchDiagnosisValidator;
use VendGuard\Core\Domain\ValueObject\WorkshopRepairStatus;
use VendGuard\Core\Domain\ValueObject\WorkshopRestockDestination;
use VendGuard\Infrastructure\Database\ConnectionFactory;

/**
 * WorkshopRefurbishmentService — casos de uso de la bandeja de taller central
 * (Módulo 27).
 *
 * Cada mutación es una unidad de trabajo: se abre transacción, se bloquea el
 * expediente con `SELECT ... FOR UPDATE`, se decide sobre el estado **leído bajo
 * bloqueo** y se escribe la transición con su evento de auditoría en la misma
 * transacción. Nada de lo que decide este servicio depende de una lectura previa
 * sin bloqueo, que es la única forma de que dos Coordinadores no dicten dos
 * veredictos sobre la misma unidad ni pierdan un incremento de stock (`C5`,
 * `QA 3`).
 *
 * **Reintegro atómico a furgoneta (`D3`, `QA 3`).** Antes de escribir el dictamen
 * se verifica que la pieza siga en catálogo activo y que el técnico destinatario
 * exista, tenga rol `TECHNICIAN` y esté activo. Después se bloquea la fila de
 * `technician_van_stocks` de la pareja técnico/pieza, se comprueba el tope de
 * 9999 y se incrementa en uno; si la fila no existía se crea con
 * `min_threshold = 1`. Todo dentro de la transacción del dictamen: o queda la
 * pieza reacondicionada **y** el stock, o no queda ninguno de los dos.
 *
 * **Baja sin recepción (`C1`).** Una unidad extraviada se dictamina desde
 * `PENDING_DELIVERY` conservando `received_at` y `bench_started_at` nulos; no
 * pasa por el banco ni suma al denominador de la tasa de recuperación (`QA 1`).
 *
 * **CSV (`QA 5`).** Dos secciones con BOM UTF-8 y `;`: primero los once
 * indicadores, después las 24 columnas de detalle. Las celdas que empiezan por
 * `=`, `+`, `-` o `@` se neutralizan para que una hoja de cálculo no interprete
 * un diagnóstico como una fórmula (CSV Injection).
 *
 * Dogma Vanilla: PHP 8.2+ puro, PDO nativo, sin dependencias.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */
final class WorkshopRefurbishmentService
{
    private const VAN_STOCK_MAX = 9999;

    private const CSV_DELIMITER = ';';

    private ?PDO $pdo;

    public function __construct(
        private readonly WorkshopRepairItemRepositoryInterface $workshopItems,
        private readonly TransactionManagerInterface $transactions,
        private readonly SparePartRepositoryInterface $spareParts,
        private readonly UserRepositoryInterface $users,
        private readonly AuditLogger $auditLogger,
        ?PDO $pdo = null
    ) {
        $this->pdo = $pdo;
    }

    /**
     * Bandeja paginada con su cabecera de indicadores (`QA 2`).
     *
     * @return array<string, mixed>
     */
    public function listWorkshopTray(WorkshopTrayQueryDTO $query): array
    {
        $page = $this->workshopItems->paginateWorkshopTray($query);

        return [
            'summary' => $this->workshopItems->computeTrayKpis($query),
            'items' => $page['items'],
            'total' => $page['total'],
            'page' => $page['page'],
            'per_page' => $page['per_page'],
            'total_pages' => $page['total_pages'],
        ];
    }

    /**
     * `PENDING_DELIVERY → RECEIVED_IN_WORKSHOP` (`RF-02.1`).
     *
     * @return array<string, mixed>
     */
    public function receiveInWorkshop(ReceiveWorkshopItemRequestDTO $dto): array
    {
        return $this->transactions->runInTransaction(function () use ($dto): array {
            $item = $this->lockItem($dto->workshopItemId);
            $status = WorkshopRepairStatus::from((string)$item['status']);

            if ($status->isTerminal()) {
                throw WorkshopRepairValidationException::alreadyResolved($dto->workshopItemId, $status);
            }

            if ($status !== WorkshopRepairStatus::PENDING_DELIVERY) {
                throw WorkshopRepairValidationException::invalidStatusTransition(
                    $status,
                    WorkshopRepairStatus::RECEIVED_IN_WORKSHOP
                );
            }

            $updated = $this->workshopItems->updateReception(
                $dto->workshopItemId,
                $dto->actorUserId,
                $dto->effectiveReceptionNotes(),
                $this->now()
            );

            if ($updated === []) {
                throw WorkshopRepairValidationException::invalidStatusTransition(
                    $status,
                    WorkshopRepairStatus::RECEIVED_IN_WORKSHOP
                );
            }

            $actor = $this->actorContext($dto->actorUserId);
            $this->auditLogger->logEvent(new AuditEvent(
                id: null,
                entityType: AuditEvent::ENTITY_WORKSHOP_REPAIR_ITEM,
                entityId: (int)$updated['id'],
                action: 'WORKSHOP_ITEM_RECEIVED',
                userId: $dto->actorUserId,
                userRole: $actor['role'],
                userName: $actor['name'],
                previousState: ['status' => $status->value],
                newState: [
                    'status' => WorkshopRepairStatus::RECEIVED_IN_WORKSHOP->value,
                    'received_at' => $updated['received_at'] ?? null,
                    'received_by_user_id' => $dto->actorUserId,
                ],
                metadata: [
                    'unit_index' => (int)($updated['unit_index'] ?? 0),
                    'total_units_in_line' => (int)($updated['total_units_in_line'] ?? 0),
                ],
            ));

            return $updated;
        });
    }

    /**
     * `RECEIVED_IN_WORKSHOP → IN_BENCH_TEST` (`RF-02.2`).
     *
     * @return array<string, mixed>
     */
    public function startBenchTest(int $workshopItemId, int $actorUserId): array
    {
        return $this->transactions->runInTransaction(function () use ($workshopItemId, $actorUserId): array {
            $item = $this->lockItem($workshopItemId);
            $status = WorkshopRepairStatus::from((string)$item['status']);

            if ($status->isTerminal()) {
                throw WorkshopRepairValidationException::alreadyResolved($workshopItemId, $status);
            }

            if ($status !== WorkshopRepairStatus::RECEIVED_IN_WORKSHOP) {
                throw WorkshopRepairValidationException::invalidStatusTransition(
                    $status,
                    WorkshopRepairStatus::IN_BENCH_TEST
                );
            }

            $updated = $this->workshopItems->updateStartBench($workshopItemId, $actorUserId, $this->now());

            if ($updated === []) {
                throw WorkshopRepairValidationException::invalidStatusTransition(
                    $status,
                    WorkshopRepairStatus::IN_BENCH_TEST
                );
            }

            $actor = $this->actorContext($actorUserId);
            $this->auditLogger->logEvent(new AuditEvent(
                id: null,
                entityType: AuditEvent::ENTITY_WORKSHOP_REPAIR_ITEM,
                entityId: (int)$updated['id'],
                action: 'WORKSHOP_ITEM_BENCH_STARTED',
                userId: $actorUserId,
                userRole: $actor['role'],
                userName: $actor['name'],
                previousState: ['status' => $status->value],
                newState: [
                    'status' => WorkshopRepairStatus::IN_BENCH_TEST->value,
                    'bench_started_at' => $updated['bench_started_at'] ?? null,
                ],
                metadata: [
                    'unit_index' => (int)($updated['unit_index'] ?? 0),
                    'total_units_in_line' => (int)($updated['total_units_in_line'] ?? 0),
                ],
            ));

            return $updated;
        });
    }

    /**
     * Dictamen terminal (`RF-03`): reacondicionado con reintegro o baja
     * irreparable, incluida la baja por extravío (`C1`, `C2`, `C3`, `C5`).
     *
     * @return array<string, mixed>
     */
    public function resolveWorkshopItem(ResolveWorkshopItemRequestDTO $dto): array
    {
        return $this->transactions->runInTransaction(function () use ($dto): array {
            $item = $this->lockItem($dto->workshopItemId);
            $current = WorkshopRepairStatus::from((string)$item['status']);

            if ($current->isTerminal()) {
                throw WorkshopRepairValidationException::alreadyResolved($dto->workshopItemId, $current);
            }

            $resolvedAt = $this->now();
            $diagnosis = BenchDiagnosisValidator::normalizeAndValidate($dto->benchDiagnosis);

            if ($dto->isRefurbishment()) {
                return $this->resolveAsRefurbished($dto, $item, $current, $diagnosis, $resolvedAt);
            }

            return $this->resolveAsScrapped($dto, $item, $current, $diagnosis, $resolvedAt);
        });
    }

    /**
     * CSV de la vista actual (`QA 5`): once indicadores y 24 columnas de detalle.
     */
    public function exportWorkshopCsv(WorkshopTrayQueryDTO $query): string
    {
        $kpis = $this->workshopItems->computeTrayKpis($query);
        $rows = $this->workshopItems->findAllForExport($query);

        $lines = [];
        $lines[] = self::csvRow(['Indicador', 'Valor']);
        foreach ($this->csvIndicatorRows($kpis) as [$indicator, $value]) {
            $lines[] = self::csvRow([$indicator, $value]);
        }

        $lines[] = '';
        $lines[] = self::csvRow([
            'ID Ficha',
            'Unidad',
            'Estado',
            'Origen',
            'Código Aviso / Orden',
            'Código Máquina',
            'Modelo Máquina',
            'Sede',
            'Técnico Extractor',
            'Código Pieza',
            'Nombre Pieza',
            'Categoría',
            'Fuera de Catálogo',
            'Valor Pieza Nueva (EUR)',
            'Fecha Retirada',
            'Fecha Recepción Taller',
            'Nota Recepción',
            'Fecha Inicio Banco',
            'Diagnóstico de Banco',
            'Coste Reparación (EUR)',
            'Ahorro Neto (EUR)',
            'Destino Reincorporación',
            'Técnico Destinatario',
            'Fecha Dictamen',
        ]);

        foreach ($rows as $row) {
            $lines[] = self::csvRow($this->csvDetailRow($row));
        }

        return "\xEF\xBB\xBF" . implode("\n", $lines) . "\n";
    }

    // ─────────────────────────────────────────────────────────────────────
    // Dictámenes
    // ─────────────────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    private function resolveAsRefurbished(
        ResolveWorkshopItemRequestDTO $dto,
        array $item,
        WorkshopRepairStatus $current,
        string $diagnosis,
        string $resolvedAt
    ): array {
        if ($current === WorkshopRepairStatus::PENDING_DELIVERY) {
            throw WorkshopRepairValidationException::unreceivedPartCannotBeRefurbished($dto->workshopItemId);
        }

        if ($dto->refurbishmentCostEur < 0.0 || $dto->refurbishmentCostEur > WorkshopRepairItem::MAX_COST_EUR) {
            throw WorkshopRepairValidationException::invalidRefurbishmentCost($dto->refurbishmentCostEur);
        }

        $destination = $dto->restockDestination
            ?? throw WorkshopRepairValidationException::invalidRestockDestination(null);

        $unitCost = (float)($item['unit_cost_snapshot'] ?? 0.0);
        $netSavings = WorkshopRepairItem::calculateNetSavings($unitCost, $dto->refurbishmentCostEur);

        $restockedTechnicianId = null;
        if ($destination->requiresTechnician()) {
            $restockedTechnicianId = $this->assertVanRestockAllowed($item, $dto);
            $this->incrementVanStock(
                (int)$restockedTechnicianId,
                (int)($item['spare_part_id'] ?? 0),
                (string)($item['part_code'] ?? '')
            );
        }

        $this->sealBenchIfNeeded($item, $current, $dto, $resolvedAt);

        $updated = $this->workshopItems->updateResolution($dto->workshopItemId, [
            'status' => WorkshopRepairStatus::REFURBISHED_RESTORED->value,
            'bench_diagnosis' => $diagnosis,
            'refurbishment_cost_eur' => $dto->refurbishmentCostEur,
            'net_savings_eur' => $netSavings,
            'restock_destination' => $destination->value,
            'restocked_to_technician_id' => $restockedTechnicianId,
            'resolved_at' => $resolvedAt,
            'resolved_by_user_id' => $dto->actorUserId,
        ]);

        if ($updated === []) {
            throw WorkshopRepairValidationException::alreadyResolved($dto->workshopItemId, $current);
        }

        $actor = $this->actorContext($dto->actorUserId);
        $refurbishedState = [
            'status' => WorkshopRepairStatus::REFURBISHED_RESTORED->value,
            'net_savings_eur' => $netSavings,
            'restock_destination' => $destination->value,
            'restocked_to_technician_id' => $restockedTechnicianId,
        ];
        $refurbishedMetadata = [
            'unit_index' => (int)($updated['unit_index'] ?? 0),
            'total_units_in_line' => (int)($updated['total_units_in_line'] ?? 0),
        ];

        if ($destination === WorkshopRestockDestination::TECHNICIAN_VAN) {
            $this->auditLogger->logEvent(new AuditEvent(
                id: null,
                entityType: AuditEvent::ENTITY_WORKSHOP_REPAIR_ITEM,
                entityId: (int)$updated['id'],
                action: 'WORKSHOP_ITEM_REFURBISHED_TO_VAN',
                userId: $dto->actorUserId,
                userRole: $actor['role'],
                userName: $actor['name'],
                previousState: ['status' => $current->value],
                newState: $refurbishedState,
                metadata: $refurbishedMetadata,
            ));
        } else {
            $this->auditLogger->logEvent(new AuditEvent(
                id: null,
                entityType: AuditEvent::ENTITY_WORKSHOP_REPAIR_ITEM,
                entityId: (int)$updated['id'],
                action: 'WORKSHOP_ITEM_REFURBISHED_TO_CENTRAL',
                userId: $dto->actorUserId,
                userRole: $actor['role'],
                userName: $actor['name'],
                previousState: ['status' => $current->value],
                newState: $refurbishedState,
                metadata: $refurbishedMetadata,
            ));
        }

        return $updated;
    }

    /**
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    private function resolveAsScrapped(
        ResolveWorkshopItemRequestDTO $dto,
        array $item,
        WorkshopRepairStatus $current,
        string $diagnosis,
        string $resolvedAt
    ): array {
        $this->sealBenchIfNeeded($item, $current, $dto, $resolvedAt);

        $updated = $this->workshopItems->updateResolution($dto->workshopItemId, [
            'status' => WorkshopRepairStatus::SCRAPPED_IRREPARABLE->value,
            'bench_diagnosis' => $diagnosis,
            'refurbishment_cost_eur' => 0.0,
            'net_savings_eur' => 0.0,
            'restock_destination' => null,
            'restocked_to_technician_id' => null,
            'resolved_at' => $resolvedAt,
            'resolved_by_user_id' => $dto->actorUserId,
        ]);

        if ($updated === []) {
            throw WorkshopRepairValidationException::alreadyResolved($dto->workshopItemId, $current);
        }

        $actor = $this->actorContext($dto->actorUserId);
        $scrappedState = [
            'status' => WorkshopRepairStatus::SCRAPPED_IRREPARABLE->value,
            'received_at' => $updated['received_at'] ?? null,
            'bench_started_at' => $updated['bench_started_at'] ?? null,
        ];
        $scrappedMetadata = [
            'unit_index' => (int)($updated['unit_index'] ?? 0),
            'total_units_in_line' => (int)($updated['total_units_in_line'] ?? 0),
        ];

        if ($current === WorkshopRepairStatus::PENDING_DELIVERY) {
            $this->auditLogger->logEvent(new AuditEvent(
                id: null,
                entityType: AuditEvent::ENTITY_WORKSHOP_REPAIR_ITEM,
                entityId: (int)$updated['id'],
                action: 'WORKSHOP_ITEM_SCRAPPED_UNRECEIVED',
                userId: $dto->actorUserId,
                userRole: $actor['role'],
                userName: $actor['name'],
                previousState: ['status' => $current->value],
                newState: $scrappedState,
                metadata: $scrappedMetadata,
            ));
        } else {
            $this->auditLogger->logEvent(new AuditEvent(
                id: null,
                entityType: AuditEvent::ENTITY_WORKSHOP_REPAIR_ITEM,
                entityId: (int)$updated['id'],
                action: 'WORKSHOP_ITEM_SCRAPPED_IN_BENCH',
                userId: $dto->actorUserId,
                userRole: $actor['role'],
                userName: $actor['name'],
                previousState: ['status' => $current->value],
                newState: $scrappedState,
                metadata: $scrappedMetadata,
            ));
        }

        return $updated;
    }

    /**
     * Sella `bench_started_at = resolved_at` cuando el dictamen llega desde
     * recepción sin paso previo por banco (`D2`).
     *
     * El repositorio de resolución no escribe esa columna —no debe, porque el
     * inicio de banco es una transición propia—, así que se registra aquí con la
     * misma marca del dictamen, dentro de la misma transacción.
     *
     * @param array<string, mixed> $item
     */
    private function sealBenchIfNeeded(
        array $item,
        WorkshopRepairStatus $current,
        ResolveWorkshopItemRequestDTO $dto,
        string $resolvedAt
    ): void {
        if ($current !== WorkshopRepairStatus::RECEIVED_IN_WORKSHOP) {
            return;
        }

        $this->workshopItems->updateStartBench($dto->workshopItemId, $dto->actorUserId, $resolvedAt);
    }

    /**
     * Comprueba las tres condiciones del reintegro a furgoneta (`C3`).
     *
     * @param array<string, mixed> $item
     */
    private function assertVanRestockAllowed(array $item, ResolveWorkshopItemRequestDTO $dto): int
    {
        if (!empty($item['is_out_of_catalog'])) {
            throw WorkshopRepairValidationException::outOfCatalogVanRestockForbidden($dto->workshopItemId);
        }

        $sparePartId = (int)($item['spare_part_id'] ?? 0);
        $sparePart = $sparePartId > 0 ? $this->spareParts->findById($sparePartId) : null;
        if ($sparePart === null || !$sparePart->isActive()) {
            throw WorkshopRepairValidationException::inactiveSparePartVanRestockForbidden($sparePartId);
        }

        $technicianId = $dto->restockedTechnicianId;
        if ($technicianId === null) {
            throw WorkshopRepairValidationException::restockTechnicianRequired();
        }

        $technician = $this->users->findById($technicianId, false);
        if ($technician === null) {
            throw WorkshopRepairValidationException::technicianNotFound($technicianId);
        }

        if (!$technician->isActive() || !$technician->isTechnician()) {
            throw WorkshopRepairValidationException::restockTechnicianInactive($technicianId);
        }

        return $technicianId;
    }

    /**
     * Incremento atómico `+1` del saldo de la furgoneta (`QA 3`).
     *
     * Bloquea la fila de la pareja técnico/pieza; si no existe la crea con
     * `min_threshold = 1` tolerando la carrera de dos dictámenes simultáneos
     * (`ON CONFLICT DO NOTHING` + relock). El tope se comprueba después de
     * bloquear, nunca sobre una lectura suelta.
     */
    private function incrementVanStock(int $technicianId, int $sparePartId, string $partCode): void
    {
        if ($sparePartId <= 0) {
            throw WorkshopRepairValidationException::inactiveSparePartVanRestockForbidden($sparePartId);
        }

        $pdo = $this->pdo ??= ConnectionFactory::getConnection();

        $select = $pdo->prepare(
            'SELECT id, quantity_available FROM technician_van_stocks
              WHERE technician_id = :technician_id AND spare_part_id = :spare_part_id
              FOR UPDATE'
        );
        $select->execute([':technician_id' => $technicianId, ':spare_part_id' => $sparePartId]);
        $row = $select->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            $insert = $pdo->prepare(
                'INSERT INTO technician_van_stocks (technician_id, spare_part_id, quantity_available, min_threshold)
                 VALUES (:technician_id, :spare_part_id, 1, 1)
                 ON CONFLICT (technician_id, spare_part_id) DO NOTHING
                 RETURNING id'
            );
            $insert->execute([':technician_id' => $technicianId, ':spare_part_id' => $sparePartId]);

            if ($insert->fetchColumn() !== false) {
                return;
            }

            // Otro dictamen creó la fila entre el SELECT y el INSERT: se relee
            // bajo bloqueo y se incrementa como cualquier fila existente.
            $select->execute([':technician_id' => $technicianId, ':spare_part_id' => $sparePartId]);
            $row = $select->fetch(PDO::FETCH_ASSOC);
            if ($row === false) {
                throw WorkshopRepairValidationException::vanStockMaxCapacityReached($technicianId, $partCode);
            }
        }

        $next = (int)$row['quantity_available'] + 1;
        if ($next > self::VAN_STOCK_MAX) {
            throw WorkshopRepairValidationException::vanStockMaxCapacityReached($technicianId, $partCode);
        }

        $update = $pdo->prepare(
            'UPDATE technician_van_stocks SET quantity_available = :quantity WHERE id = :id'
        );
        $update->execute([':quantity' => $next, ':id' => (int)$row['id']]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Soporte
    // ─────────────────────────────────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    private function lockItem(int $workshopItemId): array
    {
        $item = $this->workshopItems->findByIdForUpdate($workshopItemId);
        if ($item === null) {
            throw WorkshopRepairValidationException::workshopItemNotFound($workshopItemId);
        }

        return $item;
    }

    /**
     * Rol y nombre del actor para el rastro de auditoría.
     *
     * @return array{role: string, name: string}
     */
    private function actorContext(int $actorUserId): array
    {
        $actor = $this->users->findById($actorUserId, false);

        return [
            'role' => $actor?->getRole()->value ?? 'COORDINATOR',
            'name' => $actor?->getName() ?? 'Coordinación',
        ];
    }

    private function now(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('Europe/Madrid')))->format('Y-m-d\TH:i:sP');
    }

    // ─────────────────────────────────────────────────────────────────────
    // CSV
    // ─────────────────────────────────────────────────────────────────────

    /**
     * @param array<string, int|float> $kpis
     * @return array<int, array{0: string, 1: string}>
     */
    private function csvIndicatorRows(array $kpis): array
    {
        return [
            ['Pendientes en Furgoneta', (string)($kpis['pending_delivery_count'] ?? 0)],
            ['Recibidas en Taller', (string)($kpis['received_in_workshop_count'] ?? 0)],
            ['En Banco de Pruebas', (string)($kpis['in_bench_test_count'] ?? 0)],
            ['Total en Taller / Banco', (string)($kpis['in_workshop_count'] ?? 0)],
            ['Reacondicionadas y Restauradas', (string)($kpis['refurbished_count'] ?? 0)],
            ['Desguazadas en Banco', (string)($kpis['scrapped_in_bench_count'] ?? 0)],
            ['Bajas sin Recepción (Extraviadas)', (string)($kpis['scrapped_unreceived_count'] ?? 0)],
            ['Tasa de Recuperación (%)', self::money($kpis['recovery_rate_pct'] ?? 0.0)],
            ['Valor de Reposición a Nuevo Acumulado (EUR)', self::money($kpis['total_reference_value_eur'] ?? 0.0)],
            ['Coste Total de Reparación (EUR)', self::money($kpis['total_refurbishment_cost_eur'] ?? 0.0)],
            ['Ahorro Neto Acumulado (EUR)', self::money($kpis['total_net_savings_eur'] ?? 0.0)],
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<int, string>
     */
    private function csvDetailRow(array $row): array
    {
        $origin = ($row['intervention_type'] ?? '') === 'PREVENTIVE' ? 'Preventivo' : 'Avería';

        return [
            (string)($row['id'] ?? ''),
            (string)($row['unit_badge'] ?? ''),
            (string)($row['status_label'] ?? ''),
            $origin,
            (string)($row['ticket_code'] ?? $row['preventive_order_code'] ?? ''),
            (string)($row['machine_code'] ?? ''),
            (string)($row['machine_model'] ?? ''),
            (string)($row['location_name'] ?? ''),
            (string)($row['technician_name'] ?? ''),
            (string)($row['part_code'] ?? ''),
            (string)($row['part_name'] ?? ''),
            (string)($row['part_category'] ?? ''),
            !empty($row['is_out_of_catalog']) ? 'Sí' : 'No',
            self::money($row['unit_cost_snapshot'] ?? 0.0),
            (string)($row['created_at'] ?? ''),
            (string)($row['received_at'] ?? ''),
            (string)($row['reception_notes'] ?? ''),
            (string)($row['bench_started_at'] ?? ''),
            (string)($row['bench_diagnosis'] ?? ''),
            self::money($row['refurbishment_cost_eur'] ?? 0.0),
            self::money($row['net_savings_eur'] ?? 0.0),
            (string)($row['restock_destination_label'] ?? ''),
            (string)($row['restocked_to_technician_name'] ?? ''),
            (string)($row['resolved_at'] ?? ''),
        ];
    }

    /**
     * @param array<int, string> $cells
     */
    private static function csvRow(array $cells): string
    {
        return implode(self::CSV_DELIMITER, array_map(self::csvCell(...), $cells));
    }

    /**
     * Neutraliza fórmulas y entrecomilla lo que rompería el CSV (CSV Injection).
     */
    private static function csvCell(string $value): string
    {
        if ($value !== '' && strpbrk($value[0], '=+-@') !== false && !is_numeric($value)) {
            $value = "'" . $value;
        }

        if (strpbrk($value, ';"' . "\n" . "\r") !== false) {
            return '"' . str_replace('"', '""', $value) . '"';
        }

        return $value;
    }

    private static function money(mixed $value): string
    {
        $number = is_numeric($value) ? (float)$value : 0.0;

        return number_format($number, 2, '.', '');
    }
}
