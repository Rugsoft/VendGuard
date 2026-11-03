<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Entity;

use InvalidArgumentException;
use VendGuard\Core\Domain\Exception\WorkshopRepairValidationException;
use VendGuard\Core\Domain\Service\BenchDiagnosisValidator;
use VendGuard\Core\Domain\ValueObject\WorkshopRepairStatus;
use VendGuard\Core\Domain\ValueObject\WorkshopRestockDestination;

/**
 * WorkshopRepairItem
 *
 * Expediente unitario de la bandeja de taller central (Módulo 27, Tabla 38): una
 * unidad física concreta, no una línea de recambio. Una sustitución con
 * `quantity = N` produce N expedientes sellados por `(incident_replaced_part_id,
 * unit_index)`, y cada uno viaja por el ciclo por su cuenta (`D1`, `C4`, `C6`).
 *
 * **Inmutabilidad.** La entidad no muta: cada transición devuelve una instancia
 * nueva con el cambio aplicado. El repositorio decide después si ese estado se
 * persiste, y el servicio de aplicación es quien abre la transacción y el
 * `SELECT ... FOR UPDATE`. La razón no es estética: un dictamen terminal no puede
 * deshacerse (`C5`) y los snapshots de la extracción no pueden reescribirse
 * (Art. III.3), así que no existe ningún método que los modifique.
 *
 * **Snapshots congelados.** `sparePartCodeSnapshot`, `sparePartNameSnapshot`,
 * `sparePartCategorySnapshot`, `machineCodeSnapshot`, `machineModelSnapshot`,
 * `locationNameSnapshot`, `technicianNameSnapshot`, `isOutOfCatalog` y
 * `unitCostSnapshot` se copian en el alta y aquí son de sólo lectura: si el
 * catálogo cambia de nombre o de precio mañana, el histórico sigue contando lo
 * que valía el día de la extracción.
 *
 * **Ahorro neto (`C2`).** `calculateNetSavings()` aplica suelo cero: una
 * reparación que cuesta más que la pieza nueva no genera «ahorro negativo», y la
 * bandera `hasZeroNetSavingsWarning()` avisa a Coordinación de que el
 * reacondicionamiento iguala o supera el valor de reposición.
 *
 * Dogma Vanilla: PHP 8.2+ puro, sin dependencias.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */
final class WorkshopRepairItem
{
    /** Importe máximo admitido por `chk_workshop_repair_costs_non_negative` y el contrato. */
    public const MAX_COST_EUR = 9999.99;

    private ?int $id;
    private int $incidentReplacedPartId;
    private int $unitIndex;
    private int $totalUnitsInLine;
    private string $interventionType;
    private ?int $incidentId;
    private ?int $preventiveOrderId;
    private int $machineId;
    private int $locationId;
    private int $extractedByTechnicianId;
    private ?int $sparePartId;
    private bool $isOutOfCatalog;
    private string $partCodeSnapshot;
    private string $partNameSnapshot;
    private string $partCategorySnapshot;
    private string $machineCodeSnapshot;
    private string $machineModelSnapshot;
    private string $locationNameSnapshot;
    private string $technicianNameSnapshot;
    private float $unitCostSnapshot;
    private WorkshopRepairStatus $status;
    private ?string $receivedAt;
    private ?int $receivedByUserId;
    private ?string $receptionNotes;
    private ?string $benchStartedAt;
    private ?string $benchDiagnosis;
    private float $refurbishmentCostEur;
    private float $netSavingsEur;
    private ?WorkshopRestockDestination $restockDestination;
    private ?int $restockedToTechnicianId;
    private ?string $resolvedAt;
    private ?int $resolvedByUserId;
    private ?string $createdAt;

    public function __construct(
        ?int $id,
        int $incidentReplacedPartId,
        int $unitIndex,
        int $totalUnitsInLine,
        string $interventionType,
        ?int $incidentId,
        ?int $preventiveOrderId,
        int $machineId,
        int $locationId,
        int $extractedByTechnicianId,
        ?int $sparePartId,
        bool $isOutOfCatalog,
        string $partCodeSnapshot,
        string $partNameSnapshot,
        string $partCategorySnapshot,
        string $machineCodeSnapshot,
        string $machineModelSnapshot,
        string $locationNameSnapshot,
        string $technicianNameSnapshot,
        float $unitCostSnapshot,
        WorkshopRepairStatus $status = WorkshopRepairStatus::PENDING_DELIVERY,
        ?string $receivedAt = null,
        ?int $receivedByUserId = null,
        ?string $receptionNotes = null,
        ?string $benchStartedAt = null,
        ?string $benchDiagnosis = null,
        float $refurbishmentCostEur = 0.0,
        float $netSavingsEur = 0.0,
        ?WorkshopRestockDestination $restockDestination = null,
        ?int $restockedToTechnicianId = null,
        ?string $resolvedAt = null,
        ?int $resolvedByUserId = null,
        ?string $createdAt = null
    ) {
        $type = strtoupper(trim($interventionType));
        if (!in_array($type, ['INCIDENT', 'PREVENTIVE'], true)) {
            throw new InvalidArgumentException("Tipo de intervención inválido: {$interventionType}");
        }

        if ($type === 'INCIDENT' && ($incidentId === null || $incidentId <= 0)) {
            throw new InvalidArgumentException('El ID de incidencia es obligatorio para intervenciones de correctivo.');
        }

        if ($type === 'PREVENTIVE' && ($preventiveOrderId === null || $preventiveOrderId <= 0)) {
            throw new InvalidArgumentException('El ID de orden preventiva es obligatorio para intervenciones de preventivo.');
        }

        if ($incidentReplacedPartId <= 0 || $machineId <= 0 || $locationId <= 0 || $extractedByTechnicianId <= 0) {
            throw new InvalidArgumentException('Los identificadores de línea, máquina, sede y técnico extractor son obligatorios.');
        }

        if ($totalUnitsInLine < 1 || $unitIndex < 1 || $unitIndex > $totalUnitsInLine) {
            throw new InvalidArgumentException(
                'El índice de unidad debe estar comprendido entre 1 y el total de unidades de la línea (D1).'
            );
        }

        if ($unitCostSnapshot < 0.0 || $unitCostSnapshot > self::MAX_COST_EUR) {
            throw new InvalidArgumentException('El coste unitario congelado debe estar entre 0.00 y 9999.99.');
        }

        if ($refurbishmentCostEur < 0.0 || $refurbishmentCostEur > self::MAX_COST_EUR) {
            throw new InvalidArgumentException('El coste de reacondicionamiento debe estar entre 0.00 y 9999.99.');
        }

        if ($netSavingsEur < 0.0) {
            throw new InvalidArgumentException('El ahorro neto no puede ser negativo: el cálculo aplica suelo cero (C2).');
        }

        $this->id = $id;
        $this->incidentReplacedPartId = $incidentReplacedPartId;
        $this->unitIndex = $unitIndex;
        $this->totalUnitsInLine = $totalUnitsInLine;
        $this->interventionType = $type;
        $this->incidentId = $incidentId;
        $this->preventiveOrderId = $preventiveOrderId;
        $this->machineId = $machineId;
        $this->locationId = $locationId;
        $this->extractedByTechnicianId = $extractedByTechnicianId;
        $this->sparePartId = $sparePartId;
        $this->isOutOfCatalog = $isOutOfCatalog;
        $this->partCodeSnapshot = trim($partCodeSnapshot);
        $this->partNameSnapshot = trim($partNameSnapshot);
        $this->partCategorySnapshot = strtoupper(trim($partCategorySnapshot));
        $this->machineCodeSnapshot = trim($machineCodeSnapshot);
        $this->machineModelSnapshot = trim($machineModelSnapshot);
        $this->locationNameSnapshot = trim($locationNameSnapshot);
        $this->technicianNameSnapshot = trim($technicianNameSnapshot);
        $this->unitCostSnapshot = round($unitCostSnapshot, 2);
        $this->status = $status;
        $this->receivedAt = $receivedAt;
        $this->receivedByUserId = $receivedByUserId;
        $this->receptionNotes = $receptionNotes !== null ? trim($receptionNotes) : null;
        $this->benchStartedAt = $benchStartedAt;
        $this->benchDiagnosis = $benchDiagnosis;
        $this->refurbishmentCostEur = round($refurbishmentCostEur, 2);
        $this->netSavingsEur = round($netSavingsEur, 2);
        $this->restockDestination = $restockDestination;
        $this->restockedToTechnicianId = $restockedToTechnicianId;
        $this->resolvedAt = $resolvedAt;
        $this->resolvedByUserId = $resolvedByUserId;
        $this->createdAt = $createdAt;
    }

    // ─────────────────────────────────────────────────────────────────────
    // Transiciones del ciclo
    // ─────────────────────────────────────────────────────────────────────

    /**
     * `PENDING_DELIVERY → RECEIVED_IN_WORKSHOP`: Coordinación confirma que la
     * unidad ha llegado físicamente al taller.
     *
     * @throws WorkshopRepairValidationException 409 si ya está resuelta o el estado no
     *                                           permite recibirla; 422 si la nota excede 500 caracteres.
     */
    public function markReceivedInWorkshop(int $receivedByUserId, ?string $receptionNotes, string $receivedAt): self
    {
        $this->assertMutable();

        if ($this->status !== WorkshopRepairStatus::PENDING_DELIVERY) {
            throw WorkshopRepairValidationException::invalidStatusTransition(
                $this->status,
                WorkshopRepairStatus::RECEIVED_IN_WORKSHOP
            );
        }

        $notes = $receptionNotes !== null ? trim($receptionNotes) : null;
        if ($notes === '') {
            $notes = null;
        }

        if ($notes !== null && mb_strlen($notes, 'UTF-8') > WorkshopRepairValidationException::RECEPTION_NOTES_MAX_LENGTH) {
            throw WorkshopRepairValidationException::receptionNotesTooLong(mb_strlen($notes, 'UTF-8'));
        }

        $copy = clone $this;
        $copy->status = WorkshopRepairStatus::RECEIVED_IN_WORKSHOP;
        $copy->receivedAt = $receivedAt;
        $copy->receivedByUserId = $receivedByUserId;
        $copy->receptionNotes = $notes;

        return $copy;
    }

    /**
     * `RECEIVED_IN_WORKSHOP → IN_BENCH_TEST`: la unidad entra en el banco de pruebas.
     *
     * @throws WorkshopRepairValidationException 409 si ya está resuelta o no está recibida.
     */
    public function startBenchTest(string $benchStartedAt): self
    {
        $this->assertMutable();

        if ($this->status !== WorkshopRepairStatus::RECEIVED_IN_WORKSHOP) {
            throw WorkshopRepairValidationException::invalidStatusTransition(
                $this->status,
                WorkshopRepairStatus::IN_BENCH_TEST
            );
        }

        $copy = clone $this;
        $copy->status = WorkshopRepairStatus::IN_BENCH_TEST;
        $copy->benchStartedAt = $this->benchStartedAt ?? $benchStartedAt;

        return $copy;
    }

    /**
     * Dictamen `REFURBISHED_RESTORED` con cálculo del ahorro neto (`C2`).
     *
     * Admitido desde `RECEIVED_IN_WORKSHOP` (atajo que sella `bench_started_at =
     * resolved_at`) y desde `IN_BENCH_TEST`. Nunca desde `PENDING_DELIVERY`: sin
     * recepción nadie ha comprobado la pieza.
     *
     * Los controles que dependen de la base —pieza de catálogo activa, técnico
     * activo y capacidad de la furgoneta— los aplica el servicio de aplicación
     * dentro de la transacción; aquí se validan la forma del dictamen y las
     * reglas que se deducen del propio expediente (`D3`).
     *
     * @throws WorkshopRepairValidationException 409 si ya está resuelta; 422 si el
     *                                           dictamen, el coste o el destino no son válidos.
     */
    public function resolveAsRefurbished(
        string $benchDiagnosis,
        float $refurbishmentCostEur,
        WorkshopRestockDestination $restockDestination,
        int $actorUserId,
        ?int $restockedToTechnicianId,
        string $resolvedAt
    ): self {
        $this->assertMutable();

        if ($this->status === WorkshopRepairStatus::PENDING_DELIVERY) {
            throw WorkshopRepairValidationException::unreceivedPartCannotBeRefurbished((int)($this->id ?? 0));
        }

        if (!is_finite($refurbishmentCostEur)
            || $refurbishmentCostEur < 0.0
            || $refurbishmentCostEur > self::MAX_COST_EUR) {
            throw WorkshopRepairValidationException::invalidRefurbishmentCost($refurbishmentCostEur);
        }

        $diagnosis = BenchDiagnosisValidator::normalizeAndValidate($benchDiagnosis);

        if ($this->isOutOfCatalog && $restockDestination === WorkshopRestockDestination::TECHNICIAN_VAN) {
            throw WorkshopRepairValidationException::outOfCatalogVanRestockForbidden((int)($this->id ?? 0));
        }

        if ($restockDestination->requiresTechnician() && ($restockedToTechnicianId === null || $restockedToTechnicianId <= 0)) {
            throw WorkshopRepairValidationException::restockTechnicianRequired();
        }

        $copy = clone $this;
        $copy->status = WorkshopRepairStatus::REFURBISHED_RESTORED;
        $copy->benchDiagnosis = $diagnosis;
        $copy->benchStartedAt = $this->benchStartedAt ?? $resolvedAt;
        $copy->refurbishmentCostEur = round($refurbishmentCostEur, 2);
        $copy->netSavingsEur = self::calculateNetSavings($this->unitCostSnapshot, $refurbishmentCostEur);
        $copy->restockDestination = $restockDestination;
        $copy->restockedToTechnicianId = $restockDestination->requiresTechnician() ? $restockedToTechnicianId : null;
        $copy->resolvedAt = $resolvedAt;
        $copy->resolvedByUserId = $actorUserId;

        return $copy;
    }

    /**
     * Dictamen `SCRAPPED_IRREPARABLE`.
     *
     * Admite la vía excepcional desde `PENDING_DELIVERY` (`C1`, extravío o no
     * entrega): en ese caso no hay recepción ni banco y la unidad conserva
     * `received_at = null` y `bench_started_at = null`. Desde `RECEIVED_IN_WORKSHOP`
     * o `IN_BENCH_TEST` el banco se sella con la fecha del dictamen si no constaba.
     *
     * Una baja no declara destino de reintegro, ni coste, ni ahorro (`C2`, `C5`).
     *
     * @throws WorkshopRepairValidationException 409 si ya está resuelta; 422 si el
     *                                           diagnóstico no acredita una revisión real.
     */
    public function resolveAsScrapped(string $benchDiagnosis, int $actorUserId, string $resolvedAt): self
    {
        $this->assertMutable();

        $diagnosis = BenchDiagnosisValidator::normalizeAndValidate($benchDiagnosis);
        $fromWorkshop = $this->status !== WorkshopRepairStatus::PENDING_DELIVERY;

        $copy = clone $this;
        $copy->status = WorkshopRepairStatus::SCRAPPED_IRREPARABLE;
        $copy->benchDiagnosis = $diagnosis;
        $copy->benchStartedAt = $fromWorkshop ? ($this->benchStartedAt ?? $resolvedAt) : null;
        $copy->refurbishmentCostEur = 0.0;
        $copy->netSavingsEur = 0.0;
        $copy->restockDestination = null;
        $copy->restockedToTechnicianId = null;
        $copy->resolvedAt = $resolvedAt;
        $copy->resolvedByUserId = $actorUserId;

        return $copy;
    }

    // ─────────────────────────────────────────────────────────────────────
    // Cálculos puros
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Ahorro neto de una unidad reacondicionada (`C2`).
     *
     * `max(0.00, valor_de_reposición − coste_de_reacondicionar)`: el suelo cero
     * evita que una reparación antieconómica aparezca como ahorro negativo en los
     * KPIs y en el CSV.
     */
    public static function calculateNetSavings(float $unitCostSnapshot, float $refurbishmentCostEur): float
    {
        return round(max(0.0, $unitCostSnapshot - $refurbishmentCostEur), 2);
    }

    /**
     * La unidad se reacondicionó sin ganancia: el coste iguala o supera el valor
     * de reposición y Coordinación debe verlo (`zero_net_savings_warning`).
     */
    public function hasZeroNetSavingsWarning(): bool
    {
        return $this->refurbishmentCostEur >= $this->unitCostSnapshot;
    }

    /**
     * Tasa de recuperación del taller (`QA 1`).
     *
     * El denominador son las unidades evaluadas **en banco**: reacondicionadas más
     * desguazadas tras recepción. Las bajas sin recepción (`scrapped_unreceived`,
     * extravíos) nunca entran en la cuenta porque no hubo dictamen técnico que
     * evaluar.
     */
    public static function calculateRecoveryRate(int $refurbishedCount, int $scrappedInBenchCount): float
    {
        $evaluated = $refurbishedCount + $scrappedInBenchCount;
        if ($evaluated <= 0) {
            return 0.0;
        }

        return round(($refurbishedCount * 100) / $evaluated, 2);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Guardas internas
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Un dictamen terminal cierra el expediente para siempre (`C5`, `409`).
     */
    private function assertMutable(): void
    {
        if ($this->status->isTerminal()) {
            throw WorkshopRepairValidationException::alreadyResolved((int)($this->id ?? 0), $this->status);
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // Lectura (snapshots incluidos: nadie los reescribe)
    // ─────────────────────────────────────────────────────────────────────

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getIncidentReplacedPartId(): int
    {
        return $this->incidentReplacedPartId;
    }

    public function getUnitIndex(): int
    {
        return $this->unitIndex;
    }

    public function getTotalUnitsInLine(): int
    {
        return $this->totalUnitsInLine;
    }

    public function getUnitBadge(): string
    {
        return $this->unitIndex . '/' . $this->totalUnitsInLine;
    }

    public function getInterventionType(): string
    {
        return $this->interventionType;
    }

    public function getIncidentId(): ?int
    {
        return $this->incidentId;
    }

    public function getPreventiveOrderId(): ?int
    {
        return $this->preventiveOrderId;
    }

    public function getMachineId(): int
    {
        return $this->machineId;
    }

    public function getLocationId(): int
    {
        return $this->locationId;
    }

    public function getExtractedByTechnicianId(): int
    {
        return $this->extractedByTechnicianId;
    }

    public function getSparePartId(): ?int
    {
        return $this->sparePartId;
    }

    public function isOutOfCatalog(): bool
    {
        return $this->isOutOfCatalog;
    }

    public function getPartCodeSnapshot(): string
    {
        return $this->partCodeSnapshot;
    }

    public function getPartNameSnapshot(): string
    {
        return $this->partNameSnapshot;
    }

    public function getPartCategorySnapshot(): string
    {
        return $this->partCategorySnapshot;
    }

    public function getMachineCodeSnapshot(): string
    {
        return $this->machineCodeSnapshot;
    }

    public function getMachineModelSnapshot(): string
    {
        return $this->machineModelSnapshot;
    }

    public function getLocationNameSnapshot(): string
    {
        return $this->locationNameSnapshot;
    }

    public function getTechnicianNameSnapshot(): string
    {
        return $this->technicianNameSnapshot;
    }

    public function getUnitCostSnapshot(): float
    {
        return $this->unitCostSnapshot;
    }

    public function getStatus(): WorkshopRepairStatus
    {
        return $this->status;
    }

    public function isTerminal(): bool
    {
        return $this->status->isTerminal();
    }

    public function getReceivedAt(): ?string
    {
        return $this->receivedAt;
    }

    public function getReceivedByUserId(): ?int
    {
        return $this->receivedByUserId;
    }

    public function getReceptionNotes(): ?string
    {
        return $this->receptionNotes;
    }

    public function getBenchStartedAt(): ?string
    {
        return $this->benchStartedAt;
    }

    public function getBenchDiagnosis(): ?string
    {
        return $this->benchDiagnosis;
    }

    public function getRefurbishmentCostEur(): float
    {
        return $this->refurbishmentCostEur;
    }

    public function getNetSavingsEur(): float
    {
        return $this->netSavingsEur;
    }

    public function getRestockDestination(): ?WorkshopRestockDestination
    {
        return $this->restockDestination;
    }

    public function getRestockedToTechnicianId(): ?int
    {
        return $this->restockedToTechnicianId;
    }

    public function getResolvedAt(): ?string
    {
        return $this->resolvedAt;
    }

    public function getResolvedByUserId(): ?int
    {
        return $this->resolvedByUserId;
    }

    public function getCreatedAt(): ?string
    {
        return $this->createdAt;
    }
}
