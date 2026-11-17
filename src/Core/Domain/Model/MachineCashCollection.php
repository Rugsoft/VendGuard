<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Model;

use DateTimeImmutable;
use Exception;
use InvalidArgumentException;
use JsonSerializable;
use DomainException;
use VendGuard\Core\Domain\Enum\CashCollectionStatus;

/**
 * MachineCashCollection
 *
 * Corte formal de bolsa precintada y su arqueo en Caja Central (Módulo 35,
 * Tabla 49 `machine_cash_collections`, RF-01.1, RF-03.2, D1 a D4, C1 a C3,
 * QA 1, QA 2, Art. II.2, Art. III.1, Art. III.3 y Art. V.4).
 *
 * **Inmutabilidad (Art. III.3).** La entidad es inmutable: cada ajuste devuelve
 * una copia (`withId`) y jamás reescribe la captura en ruta. El motor impone la
 * misma regla con `enforce_machine_cash_collections_immutability()`: los datos
 * declarados en ruta se congelan desde el `INSERT` y una fila arqueada es
 * completamente inmutable. La corrección de una recaudación es otra recaudación.
 *
 * **Invariantes en el borde.** El constructor replica las identidades del motor
 * que no pueden esperar a la base: formato `MCC-AAAA-NNNN`, precinto normalizado
 * y global (`^[A-Z0-9_-]{6,40}$` con un bloque de tres dígitos, C1), snapshots no
 * vacíos, cotas económicas (`D2`, C3), aritmética del contador con y sin reseteo
 * (`D2`, C3), coherencia de la entrega en conserjería (`D3`) y ciclo de vida del
 * arqueo con la tolerancia de ±1,00 € y la justificación real del Art. V.1.
 *
 * **Proyecciones segregadas (C2, QA 2, Art. V.4).** La entidad nunca se expone a
 * pelo: cada superficie recibe exactamente su vista.
 *
 *   - `toRestockerArray()` — Arqueo Ciego estricto. Incluye lo que el reponedor
 *     declaró y el estado público de custodia; omite siempre
 *     `previous_sales_counter_reading`, `initial_baseline_reading_eur`,
 *     `expected_cashbox_eur`, `vault_counted_eur`, `discrepancy_eur` y
 *     `discrepancy_justification`.
 *   - `toCoordinatorArray()` — vista financiera completa para Caja Central.
 *   - `toReceptionReceiptArray()` — comprobante de conserjería con los nueve
 *     campos del contrato y nada más: sin datos de la bolsa, de recaudación ni
 *     PII del reclamante (`QA 2`, Art. V.4).
 *
 * `toArray()` y `jsonSerialize()` devuelven la proyección del reponedor a
 * propósito: el camino por defecto es el de mínimo privilegio y la vista
 * financiera exige una llamada explícita.
 *
 * Qué **no** decide la entidad: si la máquina está en cuarentena, bloqueada o
 * con avería térmica; si el perímetro territorial del reponedor cubre la
 * máquina; y si el precinto ya existe en la historia del sistema. Eso depende
 * del parque y es competencia del validador (`T-CCV-04`), del repositorio
 * (`T-CCV-05`) y de los disparadores del motor (`RNF-02`).
 *
 * Dogma Vanilla: PHP 8.2+ puro, propiedades privadas y métodos puros.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */
final class MachineCashCollection implements JsonSerializable
{
    /** Forma canónica del código de recaudación (RF-01.1). */
    public const COLLECTION_CODE_PATTERN = '/^MCC-[0-9]{4}-[0-9]{4,}$/';

    /** Forma canónica del precinto (C1). */
    public const BAG_SEAL_CODE_PATTERN = '/^[A-Z0-9_-]{6,40}$/';

    /** El precinto debe contener un bloque numérico de al menos tres dígitos (C1). */
    public const BAG_SEAL_CODE_DIGITS_PATTERN = '/[0-9]{3,}/';

    /** Longitud mínima del identificador de mutación del móvil (D4). */
    public const MIN_MUTATION_ID_LENGTH = 6;

    /** Cotas económicas del contrato (D2, C3). */
    public const MAX_CHANGE_TUBES_LOADED_EUR = 500.00;
    public const MAX_SALES_COUNTER_READING = 9999999.99;
    public const MAX_EXPECTED_CASHBOX_EUR = 10000.00;
    public const MAX_RECEPTION_CASH_DELIVERED_EUR = 10.00;

    /** Tolerancia del arqueo ciego (D2, Art. V.1). */
    public const RECONCILIATION_TOLERANCE_EUR = 1.00;

    /** Longitudes de las notas justificadas (Art. V.1). */
    public const MIN_COUNTER_RESET_NOTES_LENGTH = 20;
    public const MAX_COUNTER_RESET_NOTES_LENGTH = 500;
    public const MIN_DISCREPANCY_JUSTIFICATION_LENGTH = 20;
    public const MAX_DISCREPANCY_JUSTIFICATION_LENGTH = 1000;
    public const MIN_OPTIONAL_DISCREPANCY_NOTE_LENGTH = 3;
    public const MIN_ALNUM_BLOCKS = 3;

    private ?int $id;
    private string $collectionCode;
    private ?int $restockVisitId;
    private int $machineId;
    private string $machineCodeSnapshot;
    private int $restockerId;
    private string $restockerNameSnapshot;
    private string $restockerCodeSnapshot;
    private string $bagSealCode;
    private float $changeTubesLoadedEur;
    private float $salesCounterReading;
    private ?float $previousSalesCounterReading;
    private float $initialBaselineReadingEur;
    private bool $isCounterReset;
    private ?string $counterResetNotes;
    private float $expectedCashboxEur;
    private float $receptionCashDeliveredEur;
    private ?string $receptionistName;
    private ?int $linkedRefundRequestId;
    private ?string $routeNotes;
    private CashCollectionStatus $status;
    private string $collectedAt;
    private ?string $deliveredToVaultAt;
    private ?int $deliveredToVaultBy;
    private ?float $vaultCountedEur;
    private ?float $discrepancyEur;
    private ?int $vaultReconciledBy;
    private ?string $vaultReconciledByNameSnapshot;
    private ?string $vaultReconciledAt;
    private ?string $discrepancyJustification;
    private string $clientMutationId;
    private ?string $createdAt;
    private ?string $updatedAt;
    private ?string $locationName;
    private ?string $locationCode;

    /**
     * @param int|null $id Identificador de fila; `null` antes de persistir.
     * @param string $collectionCode Código legible `MCC-AAAA-NNNN`; se normaliza.
     * @param int|null $restockVisitId Visita in situ vinculada (D1) o `null`.
     * @param int $machineId Máquina del corte.
     * @param string $machineCodeSnapshot Código de máquina congelado.
     * @param int $restockerId Reponedor que firma el corte.
     * @param string $restockerNameSnapshot Nombre del reponedor congelado.
     * @param string $restockerCodeSnapshot Código del reponedor congelado.
     * @param string $bagSealCode Precinto global; se normaliza a mayúsculas.
     * @param float $changeTubesLoadedEur Cambio cargado en tubos (D2).
     * @param float $salesCounterReading Lectura acumulada del contador (D2).
     * @param float|null $previousSalesCounterReading Lectura previa privada (C2).
     * @param float $initialBaselineReadingEur Base de la primera recaudación (D2).
     * @param bool $isCounterReset Reseteo o sustitución de monedero (C3).
     * @param string|null $counterResetNotes Nota real del reseteo (Art. V.1).
     * @param float $expectedCashboxEur Efectivo esperado; debe cumplir la aritmética.
     * @param float $receptionCashDeliveredEur Efectivo entregado en conserjería (D3).
     * @param string|null $receptionistName Conserje que recibe el efectivo.
     * @param int|null $linkedRefundRequestId Reintegro liquidado (D3).
     * @param string|null $routeNotes Nota de ruta.
     * @param CashCollectionStatus $status Estado de custodia.
     * @param string $collectedAt Instante del corte.
     * @param string $clientMutationId Clave de idempotencia del móvil (D4).
     * @param string|null $deliveredToVaultAt Entrada en Caja Central.
     * @param int|null $deliveredToVaultBy Coordinador que recepciona.
     * @param float|null $vaultCountedEur Efectivo contado en el arqueo.
     * @param float|null $discrepancyEur Descuadre calculado.
     * @param int|null $vaultReconciledBy Coordinador que arquea.
     * @param string|null $vaultReconciledByNameSnapshot Nombre del coordinador.
     * @param string|null $vaultReconciledAt Instante del arqueo.
     * @param string|null $discrepancyJustification Justificación del descuadre (Art. V.1).
     * @param string|null $createdAt Sello de alta del motor.
     * @param string|null $updatedAt Sello de actualización del motor.
     * @param string|null $locationName Nombre de la sede resuelto en lectura (QA 2).
     * @param string|null $locationCode Código de la sede resuelto en lectura (QA 2).
     *
     * @throws InvalidArgumentException Si una identidad, un formato, una cota o
     *                                  una coherencia del contrato no se cumple.
     */
    public function __construct(
        ?int $id,
        string $collectionCode,
        ?int $restockVisitId,
        int $machineId,
        string $machineCodeSnapshot,
        int $restockerId,
        string $restockerNameSnapshot,
        string $restockerCodeSnapshot,
        string $bagSealCode,
        float $changeTubesLoadedEur,
        float $salesCounterReading,
        ?float $previousSalesCounterReading,
        float $initialBaselineReadingEur,
        bool $isCounterReset,
        ?string $counterResetNotes,
        float $expectedCashboxEur,
        float $receptionCashDeliveredEur,
        ?string $receptionistName,
        ?int $linkedRefundRequestId,
        ?string $routeNotes,
        CashCollectionStatus $status,
        string $collectedAt,
        string $clientMutationId,
        ?string $deliveredToVaultAt = null,
        ?int $deliveredToVaultBy = null,
        ?float $vaultCountedEur = null,
        ?float $discrepancyEur = null,
        ?int $vaultReconciledBy = null,
        ?string $vaultReconciledByNameSnapshot = null,
        ?string $vaultReconciledAt = null,
        ?string $discrepancyJustification = null,
        ?string $createdAt = null,
        ?string $updatedAt = null,
        ?string $locationName = null,
        ?string $locationCode = null
    ) {
        if ($id !== null && $id <= 0) {
            throw new InvalidArgumentException('El identificador de la recaudación debe ser un entero positivo.');
        }

        foreach ([
            'restock_visit_id' => $restockVisitId,
            'machine_id' => $machineId,
            'restocker_id' => $restockerId,
            'linked_refund_request_id' => $linkedRefundRequestId,
            'delivered_to_vault_by' => $deliveredToVaultBy,
            'vault_reconciled_by' => $vaultReconciledBy,
        ] as $field => $value) {
            if ($value === null) {
                continue;
            }
            if ($value <= 0) {
                throw new InvalidArgumentException(sprintf(
                    'El campo %s debe ser un identificador positivo o null; se recibió %d.',
                    $field,
                    $value
                ));
            }
        }

        $cleanCode = strtoupper(trim($collectionCode));
        if (preg_match(self::COLLECTION_CODE_PATTERN, $cleanCode) !== 1) {
            throw new InvalidArgumentException(sprintf(
                "El código de recaudación «%s» debe cumplir MCC-AAAA-NNNN y se normaliza en mayúsculas.",
                $collectionCode
            ));
        }

        $cleanSeal = strtoupper(trim($bagSealCode));
        if (preg_match(self::BAG_SEAL_CODE_PATTERN, $cleanSeal) !== 1
            || preg_match(self::BAG_SEAL_CODE_DIGITS_PATTERN, $cleanSeal) !== 1) {
            throw new InvalidArgumentException(sprintf(
                'El precinto «%s» debe cumplir ^[A-Z0-9_-]{6,40}$ con al menos tres dígitos consecutivos (C1).',
                $bagSealCode
            ));
        }

        foreach ([
            'machine_code_snapshot' => [$machineCodeSnapshot, 32],
            'restocker_name_snapshot' => [$restockerNameSnapshot, 150],
            'restocker_code_snapshot' => [$restockerCodeSnapshot, 32],
        ] as $field => [$value, $maxLength]) {
            $clean = trim((string)$value);
            $length = mb_strlen($clean, 'UTF-8');
            if ($length < 1 || $length > $maxLength) {
                throw new InvalidArgumentException(sprintf(
                    'El snapshot %s debe contener entre 1 y %d caracteres reales; se recibieron %d.',
                    $field,
                    $maxLength,
                    $length
                ));
            }
        }

        $cleanMutationId = trim($clientMutationId);
        if (mb_strlen($cleanMutationId, 'UTF-8') < self::MIN_MUTATION_ID_LENGTH) {
            throw new InvalidArgumentException(sprintf(
                'El client_mutation_id debe tener al menos %d caracteres reales; se recibieron %d.',
                self::MIN_MUTATION_ID_LENGTH,
                mb_strlen($cleanMutationId, 'UTF-8')
            ));
        }

        $cleanCollectedAt = trim($collectedAt);
        if ($cleanCollectedAt === '') {
            throw new InvalidArgumentException('La recaudación debe declarar el instante del corte (collected_at).');
        }

        self::parseTimestamp($cleanCollectedAt, 'collected_at');

        $change = round($changeTubesLoadedEur, 2);
        $sales = round($salesCounterReading, 2);
        $previous = $previousSalesCounterReading === null ? null : round($previousSalesCounterReading, 2);
        $baseline = round($initialBaselineReadingEur, 2);
        $expected = round($expectedCashboxEur, 2);
        $reception = round($receptionCashDeliveredEur, 2);

        self::assertAmountBounds($change, $sales, $previous, $baseline, $expected, $reception, $vaultCountedEur);

        $cleanResetNotes = self::normalizeText($counterResetNotes);
        $cleanReceptionist = self::normalizeText($receptionistName);

        self::assertCounterCoherence(
            $isCounterReset,
            $cleanResetNotes,
            $sales,
            $previous,
            $baseline,
            $expected
        );

        self::assertReceptionCoherence($reception, $cleanReceptionist, $linkedRefundRequestId);

        $cleanDeliveredAt = self::normalizeTimestamp($deliveredToVaultAt, 'delivered_to_vault_at');
        $cleanReconciledAt = self::normalizeTimestamp($vaultReconciledAt, 'vault_reconciled_at');
        $cleanJustification = self::normalizeText($discrepancyJustification);
        $cleanReconcilerName = self::normalizeText($vaultReconciledByNameSnapshot);

        $vaultCounted = $vaultCountedEur === null ? null : round($vaultCountedEur, 2);
        $discrepancy = $discrepancyEur === null ? null : round($discrepancyEur, 2);

        self::assertLifecycle(
            $status,
            $cleanCollectedAt,
            $cleanDeliveredAt,
            $deliveredToVaultBy,
            $vaultCounted,
            $discrepancy,
            $vaultReconciledBy,
            $cleanReconcilerName,
            $cleanReconciledAt,
            $cleanJustification,
            $expected
        );

        $this->id = $id;
        $this->collectionCode = $cleanCode;
        $this->restockVisitId = $restockVisitId;
        $this->machineId = $machineId;
        $this->machineCodeSnapshot = trim($machineCodeSnapshot);
        $this->restockerId = $restockerId;
        $this->restockerNameSnapshot = trim($restockerNameSnapshot);
        $this->restockerCodeSnapshot = trim($restockerCodeSnapshot);
        $this->bagSealCode = $cleanSeal;
        $this->changeTubesLoadedEur = $change;
        $this->salesCounterReading = $sales;
        $this->previousSalesCounterReading = $previous;
        $this->initialBaselineReadingEur = $baseline;
        $this->isCounterReset = $isCounterReset;
        $this->counterResetNotes = $cleanResetNotes;
        $this->expectedCashboxEur = $expected;
        $this->receptionCashDeliveredEur = $reception;
        $this->receptionistName = $cleanReceptionist;
        $this->linkedRefundRequestId = $linkedRefundRequestId;
        $this->routeNotes = self::normalizeText($routeNotes);
        $this->status = $status;
        $this->collectedAt = $cleanCollectedAt;
        $this->deliveredToVaultAt = $cleanDeliveredAt;
        $this->deliveredToVaultBy = $deliveredToVaultBy;
        $this->vaultCountedEur = $vaultCounted;
        $this->discrepancyEur = $discrepancy;
        $this->vaultReconciledBy = $vaultReconciledBy;
        $this->vaultReconciledByNameSnapshot = $cleanReconcilerName;
        $this->vaultReconciledAt = $cleanReconciledAt;
        $this->discrepancyJustification = $cleanJustification;
        $this->clientMutationId = $cleanMutationId;
        $this->createdAt = self::normalizeTimestamp($createdAt, 'created_at');
        $this->updatedAt = self::normalizeTimestamp($updatedAt, 'updated_at');
        $this->locationName = self::normalizeText($locationName);
        $this->locationCode = self::normalizeText($locationCode);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCollectionCode(): string
    {
        return $this->collectionCode;
    }

    public function getRestockVisitId(): ?int
    {
        return $this->restockVisitId;
    }

    public function getMachineId(): int
    {
        return $this->machineId;
    }

    public function getMachineCodeSnapshot(): string
    {
        return $this->machineCodeSnapshot;
    }

    public function getRestockerId(): int
    {
        return $this->restockerId;
    }

    public function getRestockerNameSnapshot(): string
    {
        return $this->restockerNameSnapshot;
    }

    public function getRestockerCodeSnapshot(): string
    {
        return $this->restockerCodeSnapshot;
    }

    public function getBagSealCode(): string
    {
        return $this->bagSealCode;
    }

    public function getChangeTubesLoadedEur(): float
    {
        return $this->changeTubesLoadedEur;
    }

    public function getSalesCounterReading(): float
    {
        return $this->salesCounterReading;
    }

    public function getPreviousSalesCounterReading(): ?float
    {
        return $this->previousSalesCounterReading;
    }

    public function getInitialBaselineReadingEur(): float
    {
        return $this->initialBaselineReadingEur;
    }

    public function isCounterReset(): bool
    {
        return $this->isCounterReset;
    }

    public function getCounterResetNotes(): ?string
    {
        return $this->counterResetNotes;
    }

    public function getExpectedCashboxEur(): float
    {
        return $this->expectedCashboxEur;
    }

    public function getReceptionCashDeliveredEur(): float
    {
        return $this->receptionCashDeliveredEur;
    }

    public function getReceptionistName(): ?string
    {
        return $this->receptionistName;
    }

    public function getLinkedRefundRequestId(): ?int
    {
        return $this->linkedRefundRequestId;
    }

    public function getRouteNotes(): ?string
    {
        return $this->routeNotes;
    }

    public function getStatus(): CashCollectionStatus
    {
        return $this->status;
    }

    public function getCollectedAt(): string
    {
        return $this->collectedAt;
    }

    public function getDeliveredToVaultAt(): ?string
    {
        return $this->deliveredToVaultAt;
    }

    public function getDeliveredToVaultBy(): ?int
    {
        return $this->deliveredToVaultBy;
    }

    public function getVaultCountedEur(): ?float
    {
        return $this->vaultCountedEur;
    }

    public function getDiscrepancyEur(): ?float
    {
        return $this->discrepancyEur;
    }

    public function getVaultReconciledBy(): ?int
    {
        return $this->vaultReconciledBy;
    }

    public function getVaultReconciledByNameSnapshot(): ?string
    {
        return $this->vaultReconciledByNameSnapshot;
    }

    public function getVaultReconciledAt(): ?string
    {
        return $this->vaultReconciledAt;
    }

    public function getDiscrepancyJustification(): ?string
    {
        return $this->discrepancyJustification;
    }

    public function getClientMutationId(): string
    {
        return $this->clientMutationId;
    }

    public function getCreatedAt(): ?string
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?string
    {
        return $this->updatedAt;
    }

    public function getLocationName(): ?string
    {
        return $this->locationName;
    }

    public function getLocationCode(): ?string
    {
        return $this->locationCode;
    }

    public function isTerminal(): bool
    {
        return $this->status->isTerminal();
    }

    public function hasReceptionDelivery(): bool
    {
        return $this->receptionCashDeliveredEur > 0.00;
    }

    /**
     * Copia con la identidad que devuelve el motor tras el alta (D4).
     */
    public function withId(int $id): self
    {
        if ($id <= 0) {
            throw new InvalidArgumentException('El identificador de la recaudación debe ser un entero positivo.');
        }

        $clone = clone $this;
        $clone->id = $id;

        return $clone;
    }

    /**
     * Proyección del reponedor bajo Arqueo Ciego estricto (C2, Art. V.4).
     *
     * Incluye exclusivamente lo que el reponedor declaró y el estado público de
     * custodia. Las cinco claves financieras privadas no existen en esta vista.
     *
     * @return array<string, mixed>
     */
    public function toRestockerArray(): array
    {
        return [
            'id' => $this->id,
            'collection_code' => $this->collectionCode,
            'restock_visit_id' => $this->restockVisitId,
            'machine_id' => $this->machineId,
            'machine_code' => $this->machineCodeSnapshot,
            'bag_seal_code' => $this->bagSealCode,
            'sales_counter_reading' => $this->salesCounterReading,
            'change_tubes_loaded_eur' => $this->changeTubesLoadedEur,
            'is_counter_reset' => $this->isCounterReset,
            'counter_reset_notes' => $this->counterResetNotes,
            'reception_cash_delivered_eur' => $this->receptionCashDeliveredEur,
            'receptionist_name' => $this->receptionistName,
            'linked_refund_request_id' => $this->linkedRefundRequestId,
            'has_reception_receipt' => $this->hasReceptionDelivery(),
            'status' => $this->status->publicRestockerStatus(),
            'status_label' => $this->status->publicRestockerLabel(),
            'collected_at' => $this->collectedAt,
            'created_at' => $this->createdAt,
        ];
    }

    /**
     * Vista financiera completa de Caja Central (D2, D4, QA 1).
     *
     * @return array<string, mixed>
     */
    public function toCoordinatorArray(): array
    {
        return [
            'id' => $this->id,
            'collection_code' => $this->collectionCode,
            'restock_visit_id' => $this->restockVisitId,
            'machine_id' => $this->machineId,
            'machine_code_snapshot' => $this->machineCodeSnapshot,
            'restocker_id' => $this->restockerId,
            'restocker_name_snapshot' => $this->restockerNameSnapshot,
            'restocker_code_snapshot' => $this->restockerCodeSnapshot,
            'bag_seal_code' => $this->bagSealCode,
            'change_tubes_loaded_eur' => $this->changeTubesLoadedEur,
            'sales_counter_reading' => $this->salesCounterReading,
            'previous_sales_counter_reading' => $this->previousSalesCounterReading,
            'initial_baseline_reading_eur' => $this->initialBaselineReadingEur,
            'is_counter_reset' => $this->isCounterReset,
            'counter_reset_notes' => $this->counterResetNotes,
            'expected_cashbox_eur' => $this->expectedCashboxEur,
            'reception_cash_delivered_eur' => $this->receptionCashDeliveredEur,
            'receptionist_name' => $this->receptionistName,
            'linked_refund_request_id' => $this->linkedRefundRequestId,
            'route_notes' => $this->routeNotes,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'collected_at' => $this->collectedAt,
            'delivered_to_vault_at' => $this->deliveredToVaultAt,
            'delivered_to_vault_by' => $this->deliveredToVaultBy,
            'vault_counted_eur' => $this->vaultCountedEur,
            'discrepancy_eur' => $this->discrepancyEur,
            'vault_reconciled_by' => $this->vaultReconciledBy,
            'vault_reconciled_by_name_snapshot' => $this->vaultReconciledByNameSnapshot,
            'vault_reconciled_at' => $this->vaultReconciledAt,
            'discrepancy_justification' => $this->discrepancyJustification,
            'client_mutation_id' => $this->clientMutationId,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
            'location_code' => $this->locationCode,
            'location_name' => $this->locationName,
            'is_terminal' => $this->isTerminal(),
        ];
    }

    /**
     * Comprobante imprimible de entrega en conserjería (QA 2, Art. V.4).
     *
     * Los nueve campos del contrato y nada más: sin precinto, sin lecturas de
     * contador, sin cambio cargado, sin arqueo y sin PII del reclamante.
     *
     * @return array<string, mixed>
     *
     * @throws DomainException Si la recaudación no tiene entrega que justificar.
     */
    public function toReceptionReceiptArray(): array
    {
        if (!$this->hasReceptionDelivery()) {
            throw new DomainException(
                'La recaudación no tiene entrega en conserjería: no existe comprobante que emitir (QA 2).'
            );
        }

        return [
            'collection_code' => $this->collectionCode,
            'collected_at' => $this->collectedAt,
            'location_code' => $this->locationCode,
            'location_name' => $this->locationName,
            'machine_code' => $this->machineCodeSnapshot,
            'restocker_name' => $this->restockerNameSnapshot,
            'receptionist_name' => $this->receptionistName,
            'reception_cash_delivered_eur' => $this->receptionCashDeliveredEur,
            'linked_refund_request_id' => $this->linkedRefundRequestId,
        ];
    }

    /**
     * Camino por defecto de mínimo privilegio: la vista del reponedor.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->toRestockerArray();
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    private static function assertAmountBounds(
        float $change,
        float $sales,
        ?float $previous,
        float $baseline,
        float $expected,
        float $reception,
        ?float $vaultCounted
    ): void {
        $checks = [
            'change_tubes_loaded_eur' => [$change, 0.00, self::MAX_CHANGE_TUBES_LOADED_EUR],
            'sales_counter_reading' => [$sales, 0.00, self::MAX_SALES_COUNTER_READING],
            'initial_baseline_reading_eur' => [$baseline, 0.00, self::MAX_SALES_COUNTER_READING],
            'expected_cashbox_eur' => [$expected, 0.00, self::MAX_EXPECTED_CASHBOX_EUR],
            'reception_cash_delivered_eur' => [$reception, 0.00, self::MAX_RECEPTION_CASH_DELIVERED_EUR],
        ];

        if ($previous !== null) {
            $checks['previous_sales_counter_reading'] = [$previous, 0.00, self::MAX_SALES_COUNTER_READING];
        }
        if ($vaultCounted !== null) {
            $checks['vault_counted_eur'] = [round($vaultCounted, 2), 0.00, self::MAX_EXPECTED_CASHBOX_EUR];
        }

        foreach ($checks as $field => [$value, $min, $max]) {
            if ($value < $min || $value > $max) {
                throw new InvalidArgumentException(sprintf(
                    'El campo %s debe estar entre %.2f y %.2f €; se recibió %.2f €.',
                    $field,
                    $min,
                    $max,
                    $value
                ));
            }
        }
    }

    /**
     * Identidad aritmética del arqueo ciego, la misma que impone el motor en
     * `chk_machine_cash_collections_counter_coherence` (D2, C3).
     *
     * @throws InvalidArgumentException
     */
    private static function assertCounterCoherence(
        bool $isCounterReset,
        ?string $counterResetNotes,
        float $sales,
        ?float $previous,
        float $baseline,
        float $expected
    ): void {
        if ($isCounterReset) {
            if ($baseline !== 0.00) {
                throw new InvalidArgumentException(
                    'Con reseteo de contador, initial_baseline_reading_eur debe ser 0.00 (D2, C3).'
                );
            }
            self::assertJustifiedText(
                $counterResetNotes,
                'counter_reset_notes',
                self::MIN_COUNTER_RESET_NOTES_LENGTH,
                self::MAX_COUNTER_RESET_NOTES_LENGTH
            );

            $computed = round($sales, 2);
            if ($computed !== $expected) {
                throw new InvalidArgumentException(sprintf(
                    'Con reseteo, expected_cashbox_eur debe ser la lectura completa (%.2f €); se recibió %.2f €.',
                    $computed,
                    $expected
                ));
            }

            return;
        }

        if ($counterResetNotes !== null) {
            throw new InvalidArgumentException(
                'Sin reseteo de contador, counter_reset_notes debe ser NULL (chk_machine_cash_collections_counter_coherence).'
            );
        }

        if ($previous !== null) {
            if ($baseline !== 0.00) {
                throw new InvalidArgumentException(
                    'Con lectura previa, initial_baseline_reading_eur debe ser 0.00 (D2, C3).'
                );
            }
            if ($sales < $previous) {
                throw new InvalidArgumentException(sprintf(
                    'La lectura del contador no puede retroceder de %.2f € a %.2f € sin declarar reseteo (C3).',
                    $previous,
                    $sales
                ));
            }
            $computed = round($sales - $previous, 2);
        } else {
            if ($baseline > $sales) {
                throw new InvalidArgumentException(sprintf(
                    'La base inicial (%.2f €) no puede superar la lectura del contador (%.2f €).',
                    $baseline,
                    $sales
                ));
            }
            $computed = round($sales - $baseline, 2);
        }

        if ($computed !== $expected) {
            throw new InvalidArgumentException(sprintf(
                'expected_cashbox_eur debe ser la diferencia real del contador (%.2f €); se recibió %.2f €.',
                $computed,
                $expected
            ));
        }
    }

    /**
     * Coherencia de la entrega en conserjería, la misma que impone el motor en
     * `chk_machine_cash_collections_reception_coherence` (D3).
     *
     * @throws InvalidArgumentException
     */
    private static function assertReceptionCoherence(
        float $reception,
        ?string $receptionistName,
        ?int $linkedRefundRequestId
    ): void {
        if ($reception === 0.00) {
            if ($receptionistName !== null || $linkedRefundRequestId !== null) {
                throw new InvalidArgumentException(
                    'Sin entrega en conserjería, receptionist_name y linked_refund_request_id deben ser NULL (D3).'
                );
            }

            return;
        }

        if ($receptionistName === null) {
            throw new InvalidArgumentException(
                'La entrega en conserjería exige receptionist_name (D3).'
            );
        }

        $length = mb_strlen($receptionistName, 'UTF-8');
        if ($length < 3 || $length > 120) {
            throw new InvalidArgumentException(sprintf(
                'receptionist_name debe contener entre 3 y 120 caracteres reales; se recibieron %d.',
                $length
            ));
        }
    }

    /**
     * Ciclo de vida por estados, el mismo que impone
     * `chk_machine_cash_collections_vault_lifecycle` (D2, D4, QA 1).
     *
     * @throws InvalidArgumentException
     */
    private static function assertLifecycle(
        CashCollectionStatus $status,
        string $collectedAt,
        ?string $deliveredAt,
        ?int $deliveredBy,
        ?float $vaultCounted,
        ?float $discrepancy,
        ?int $reconciledBy,
        ?string $reconciledByName,
        ?string $reconciledAt,
        ?string $justification,
        float $expected
    ): void {
        $collected = self::parseTimestamp($collectedAt, 'collected_at');

        if ($status->isSealedInRoute()) {
            if ($deliveredAt !== null || $deliveredBy !== null || $vaultCounted !== null
                || $discrepancy !== null || $reconciledBy !== null || $reconciledByName !== null
                || $reconciledAt !== null || $justification !== null) {
                throw new InvalidArgumentException(
                    'Una recaudación SEALED_IN_ROUTE no puede declarar marcas de recepción ni de arqueo (D4).'
                );
            }

            return;
        }

        if ($deliveredAt === null || $deliveredBy === null) {
            throw new InvalidArgumentException(
                'Toda recaudación recepcionada o arqueada exige delivered_to_vault_at y delivered_to_vault_by (D4).'
            );
        }

        $delivered = self::parseTimestamp($deliveredAt, 'delivered_to_vault_at');
        if ($delivered < $collected) {
            throw new InvalidArgumentException(
                'delivered_to_vault_at no puede ser anterior a collected_at (chk_machine_cash_collections_vault_lifecycle).'
            );
        }

        if ($status->isDeliveredToVault()) {
            if ($vaultCounted !== null || $discrepancy !== null || $reconciledBy !== null
                || $reconciledByName !== null || $reconciledAt !== null || $justification !== null) {
                throw new InvalidArgumentException(
                    'Una recaudación DELIVERED_TO_VAULT aún no puede declarar el arqueo de Caja Central (D4).'
                );
            }

            return;
        }

        if ($vaultCounted === null || $reconciledBy === null || $reconciledByName === null
            || $reconciledAt === null) {
            throw new InvalidArgumentException(
                'El arqueo terminal exige vault_counted_eur, vault_reconciled_by, su snapshot y vault_reconciled_at (D4).'
            );
        }

        $reconciled = self::parseTimestamp($reconciledAt, 'vault_reconciled_at');
        if ($reconciled < $delivered) {
            throw new InvalidArgumentException(
                'vault_reconciled_at no puede ser anterior a delivered_to_vault_at (D4).'
            );
        }

        $computed = round($vaultCounted - $expected, 2);
        if ($discrepancy === null || $computed !== $discrepancy) {
            throw new InvalidArgumentException(sprintf(
                'discrepancy_eur debe ser vault_counted_eur - expected_cashbox_eur (%.2f €); se recibió %s.',
                $computed,
                $discrepancy === null ? 'NULL' : sprintf('%.2f €', $discrepancy)
            ));
        }

        if ($status->isReconciledOk()) {
            if (abs($discrepancy) > self::RECONCILIATION_TOLERANCE_EUR) {
                throw new InvalidArgumentException(sprintf(
                    'RECONCILED_OK exige un descuadre dentro de ±%.2f €; se recibió %.2f € (D2).',
                    self::RECONCILIATION_TOLERANCE_EUR,
                    $discrepancy
                ));
            }

            if ($justification !== null) {
                $length = mb_strlen($justification, 'UTF-8');
                if ($length < self::MIN_OPTIONAL_DISCREPANCY_NOTE_LENGTH
                    || $length > self::MAX_DISCREPANCY_JUSTIFICATION_LENGTH) {
                    throw new InvalidArgumentException(
                        'La observación opcional del arqueo cuadrado debe tener entre 3 y 1000 caracteres reales.'
                    );
                }
            }

            return;
        }

        if (abs($discrepancy) <= self::RECONCILIATION_TOLERANCE_EUR) {
            throw new InvalidArgumentException(sprintf(
                'DISCREPANCY_FLAGGED exige un descuadre superior a ±%.2f €; se recibió %.2f € (D2).',
                self::RECONCILIATION_TOLERANCE_EUR,
                $discrepancy
            ));
        }

        self::assertJustifiedText(
            $justification,
            'discrepancy_justification',
            self::MIN_DISCREPANCY_JUSTIFICATION_LENGTH,
            self::MAX_DISCREPANCY_JUSTIFICATION_LENGTH
        );
    }

    /**
     * Nota justificada del Art. V.1: longitud real y al menos tres bloques
     * alfanuméricos, la misma regla que los `CHECK` del motor.
     *
     * @throws InvalidArgumentException
     */
    private static function assertJustifiedText(
        ?string $text,
        string $field,
        int $minLength,
        int $maxLength
    ): void {
        $clean = self::normalizeText($text);
        if ($clean === null) {
            throw new InvalidArgumentException(sprintf('%s es obligatorio (Art. V.1).', $field));
        }

        $length = mb_strlen($clean, 'UTF-8');
        if ($length < $minLength || $length > $maxLength) {
            throw new InvalidArgumentException(sprintf(
                '%s debe contener entre %d y %d caracteres reales; se recibieron %d (Art. V.1).',
                $field,
                $minLength,
                $maxLength,
                $length
            ));
        }

        preg_match_all('/[\p{L}\p{N}]+/u', $clean, $blocks);
        if (count($blocks[0]) < self::MIN_ALNUM_BLOCKS) {
            throw new InvalidArgumentException(sprintf(
                '%s debe contener al menos %d bloques alfanuméricos reales (Art. V.1).',
                $field,
                self::MIN_ALNUM_BLOCKS
            ));
        }
    }

    private static function parseTimestamp(string $value, string $field): DateTimeImmutable
    {
        try {
            return new DateTimeImmutable($value);
        } catch (Exception $exception) {
            throw new InvalidArgumentException(sprintf(
                'El campo %s no es una marca temporal interpretable: %s.',
                $field,
                $exception->getMessage()
            ), 0, $exception);
        }
    }

    private static function normalizeTimestamp(?string $value, string $field): ?string
    {
        $clean = self::normalizeText($value);
        if ($clean === null) {
            return null;
        }

        self::parseTimestamp($clean, $field);

        return $clean;
    }

    private static function normalizeText(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $clean = trim($value);

        return $clean === '' ? null : $clean;
    }
}
