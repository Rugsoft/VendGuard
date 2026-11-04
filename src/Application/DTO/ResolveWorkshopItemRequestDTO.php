<?php

declare(strict_types=1);

namespace VendGuard\Application\DTO;

use InvalidArgumentException;
use VendGuard\Core\Domain\Entity\WorkshopRepairItem;
use VendGuard\Core\Domain\Exception\WorkshopRepairValidationException;
use VendGuard\Core\Domain\ValueObject\WorkshopRepairStatus;
use VendGuard\Core\Domain\ValueObject\WorkshopRestockDestination;

/**
 * ResolveWorkshopItemRequestDTO — cuerpo de `POST /api/coordinator/workshop-items/{id}/resolve`
 * (Módulo 27, RF-03).
 *
 * Traduce el JSON a los tipos del dominio antes de que el expediente se toque: el
 * dictamen sólo puede ser un estado terminal, el destino sólo uno de los dos
 * legalmente posibles y el coste un importe entre `0.00` y `9999.99`. Así el
 * servicio no repite conversiones ni acepta cadenas libres.
 *
 * El diagnóstico viaja **sin normalizar** a propósito: la normalización y la
 * medida de palabras son competencia de `BenchDiagnosisValidator`, que se aplica
 * dentro de la transición de la entidad y deja guardado exactamente el texto
 * medido.
 *
 * Dogma Vanilla: `final readonly`, tipado estricto PHP 8.2+, cero dependencias.
 * Dualismo Lingüístico: código en inglés, mensajes en castellano.
 */
final readonly class ResolveWorkshopItemRequestDTO
{
    public int $workshopItemId;
    public int $actorUserId;
    public WorkshopRepairStatus $outcomeStatus;
    public string $benchDiagnosis;
    public ?WorkshopRestockDestination $restockDestination;
    public ?int $restockedTechnicianId;
    public float $refurbishmentCostEur;

    public function __construct(
        int $workshopItemId,
        int $actorUserId,
        WorkshopRepairStatus $outcomeStatus,
        string $benchDiagnosis,
        ?WorkshopRestockDestination $restockDestination = null,
        ?int $restockedTechnicianId = null,
        float $refurbishmentCostEur = 0.0
    ) {
        if ($workshopItemId <= 0) {
            throw new InvalidArgumentException('El identificador del expediente de taller debe ser positivo.');
        }

        if ($actorUserId <= 0) {
            throw new InvalidArgumentException('El usuario actor del dictamen debe ser un identificador positivo.');
        }

        if (!$outcomeStatus->isTerminal()) {
            throw WorkshopRepairValidationException::invalidResolutionOutcome($outcomeStatus->value);
        }

        if (!is_finite($refurbishmentCostEur)
            || $refurbishmentCostEur < 0.0
            || $refurbishmentCostEur > WorkshopRepairItem::MAX_COST_EUR) {
            throw WorkshopRepairValidationException::invalidRefurbishmentCost($refurbishmentCostEur);
        }

        if ($restockedTechnicianId !== null && $restockedTechnicianId <= 0) {
            throw WorkshopRepairValidationException::restockTechnicianRequired();
        }

        $this->workshopItemId = $workshopItemId;
        $this->actorUserId = $actorUserId;
        $this->outcomeStatus = $outcomeStatus;
        $this->benchDiagnosis = $benchDiagnosis;
        $this->restockDestination = $restockDestination;
        $this->restockedTechnicianId = $restockedTechnicianId;
        $this->refurbishmentCostEur = round($refurbishmentCostEur, 2);
    }

    /**
     * @param array<string, mixed> $payload Cuerpo JSON ya decodificado.
     */
    public static function fromPayload(int $workshopItemId, int $actorUserId, array $payload): self
    {
        $outcomeRaw = $payload['outcome_status'] ?? '';
        if (!is_string($outcomeRaw)) {
            throw WorkshopRepairValidationException::invalidResolutionOutcome('');
        }

        $outcome = WorkshopRepairStatus::tryFrom(strtoupper(trim($outcomeRaw)));
        if ($outcome === null || !$outcome->isTerminal()) {
            throw WorkshopRepairValidationException::invalidResolutionOutcome($outcomeRaw);
        }

        $destinationRaw = $payload['restock_destination'] ?? null;
        $destination = null;
        if ($destinationRaw !== null && $destinationRaw !== '') {
            if (!is_string($destinationRaw)) {
                throw WorkshopRepairValidationException::invalidRestockDestination(null);
            }
            $destination = WorkshopRestockDestination::tryFrom(strtoupper(trim($destinationRaw)));
            if ($destination === null) {
                throw WorkshopRepairValidationException::invalidRestockDestination($destinationRaw);
            }
        }

        $costRaw = $payload['refurbishment_cost_eur'] ?? 0.0;
        if (!is_int($costRaw) && !is_float($costRaw) && !(is_string($costRaw) && is_numeric($costRaw))) {
            throw WorkshopRepairValidationException::invalidRefurbishmentCost($costRaw);
        }

        $technicianRaw = $payload['restocked_to_technician_id'] ?? null;
        if ($technicianRaw !== null && !is_int($technicianRaw) && !(is_string($technicianRaw) && ctype_digit($technicianRaw))) {
            throw WorkshopRepairValidationException::restockTechnicianRequired();
        }

        $diagnosis = $payload['bench_diagnosis'] ?? '';
        if (!is_string($diagnosis)) {
            $diagnosis = '';
        }

        return new self(
            workshopItemId: $workshopItemId,
            actorUserId: $actorUserId,
            outcomeStatus: $outcome,
            benchDiagnosis: $diagnosis,
            restockDestination: $destination,
            restockedTechnicianId: $technicianRaw !== null ? (int)$technicianRaw : null,
            refurbishmentCostEur: (float)$costRaw
        );
    }

    public function isRefurbishment(): bool
    {
        return $this->outcomeStatus === WorkshopRepairStatus::REFURBISHED_RESTORED;
    }

    public function isScrap(): bool
    {
        return $this->outcomeStatus === WorkshopRepairStatus::SCRAPPED_IRREPARABLE;
    }
}
