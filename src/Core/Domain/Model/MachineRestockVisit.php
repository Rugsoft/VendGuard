<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Model;

use DateTimeImmutable;
use Exception;
use InvalidArgumentException;
use JsonSerializable;
use VendGuard\Core\Domain\Enum\RestockVisitStatus;

/**
 * MachineRestockVisit
 *
 * Cabecera histórica de una visita de reposición in situ (Módulo 34, Tabla 47
 * `machine_restock_visits`, RF-01.2, D1 a D4, QA 4, Art. II, Art. III.1 y III.3).
 *
 * Invariantes que muerden en el borde de la entidad, antes de que la fila llegue
 * al motor:
 *   1. `visit_code` se normaliza en mayúsculas y cumple `^MRV-[0-9]{4}-[0-9]{4,}$`.
 *   2. `client_mutation_id` es la clave de idempotencia del móvil (D4): se recorta
 *      y exige al menos 6 caracteres reales, la misma cota del `CHECK`.
 *   3. `cabin_temp_celsius` es `null` o está en el rango físico plausible
 *      `[-5,0, 35,0] °C` (QA 4) y se redondea a la décima del motor.
 *   4. `completed_at >= started_at` cuando ambas marcas existen.
 *   5. Un aborto térmico exige el protocolo completo —precinto, lectura, foto y
 *      nota interna de 20 caracteres reales— igual que el `CHECK` y el disparador
 *      del motor: es la misma regla en dos capas (`RNF-02`), no una segunda verdad.
 *
 * Lo que la entidad **no** decide es si la máquina admite la visita (cuarentena,
 * perímetro territorial) ni si el techo térmico se ha superado: eso depende de la
 * tipología y del estado del parque, y es competencia del validador del Triple
 * Cerrojo (`MachineRestockVisitValidator`, T-RVF-04).
 *
 * Es inmutable: cada ajuste devuelve una copia (`withId`, `withTriggeredIncident`,
 * `withLoadingOrder`) y nunca reescribe el histórico (Art. III.3).
 *
 * Dogma Vanilla: PHP 8.2+ puro, propiedades privadas y métodos puros.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */
final class MachineRestockVisit implements JsonSerializable
{
    /** Forma canónica del código de visita (RF-01.2). */
    public const VISIT_CODE_PATTERN = '/^MRV-[0-9]{4}-[0-9]{4,}$/';

    /** Longitud mínima del identificador de mutación del móvil (D4). */
    public const MIN_MUTATION_ID_LENGTH = 6;

    /** Rango físico plausible de la lectura de cabina (QA 4). */
    public const MIN_CABIN_TEMP_CELSIUS = -5.0;
    public const MAX_CABIN_TEMP_CELSIUS = 35.0;

    /** Caracteres reales mínimos de la nota interna en un aborto (Art. V.1). */
    public const MIN_INTERNAL_NOTES_LENGTH = 20;

    private ?int $id;
    private string $visitCode;
    private int $machineId;
    private int $restockerId;
    private RestockVisitStatus $status;
    private string $clientMutationId;
    private DailyHygieneChecklist $hygieneChecklist;
    private ?float $cabinTempCelsius;
    private bool $precautionarySealApplied;
    private ?string $photoEvidenceUrl;
    private ?string $internalNotes;
    private ?int $loadingOrderId;
    private ?int $routeScheduleId;
    private ?string $startedAt;
    private ?string $completedAt;
    private ?int $triggeredIncidentId;
    private ?string $createdAt;

    /**
     * @param int|null $id Identificador de fila; `null` antes de persistir.
     * @param string $visitCode Código legible `MRV-AAAA-NNNN`; se normaliza.
     * @param int $machineId Máquina intervenida.
     * @param int $restockerId Reponedor que firma la visita.
     * @param RestockVisitStatus $status Desenlace de la visita.
     * @param string $clientMutationId Clave de idempotencia del móvil (D4).
     * @param DailyHygieneChecklist $hygieneChecklist Checklist adaptativo ya normalizado.
     * @param float|null $cabinTempCelsius Lectura de cabina, obligatoria con frío por tipología.
     * @param bool $precautionarySealApplied Precinto preventivo colocado.
     * @param string|null $photoEvidenceUrl Evidencia fotográfica (Art. V.5).
     * @param string|null $internalNotes Nota interna de taller (Art. V.1 y V.4).
     * @param int|null $loadingOrderId Orden de carga que abasteció la visita (Módulo 33).
     * @param int|null $routeScheduleId Reservado para la agenda de ruta (Feature 27).
     * @param string|null $startedAt Apertura de puerta, tal y como la devuelve el motor.
     * @param string|null $completedAt Cierre de la visita.
     * @param int|null $triggeredIncidentId Ticket SAT creado o escalado (QA 2).
     * @param string|null $createdAt Sello de alta del motor.
     *
     * @throws InvalidArgumentException Si un identificador, un formato o la
     *                                  coherencia sanitaria no cumplen el contrato.
     */
    public function __construct(
        ?int $id,
        string $visitCode,
        int $machineId,
        int $restockerId,
        RestockVisitStatus $status,
        string $clientMutationId,
        DailyHygieneChecklist $hygieneChecklist,
        ?float $cabinTempCelsius = null,
        bool $precautionarySealApplied = false,
        ?string $photoEvidenceUrl = null,
        ?string $internalNotes = null,
        ?int $loadingOrderId = null,
        ?int $routeScheduleId = null,
        ?string $startedAt = null,
        ?string $completedAt = null,
        ?int $triggeredIncidentId = null,
        ?string $createdAt = null
    ) {
        if ($id !== null && $id <= 0) {
            throw new InvalidArgumentException('El identificador de la visita debe ser un entero positivo.');
        }

        if ($machineId <= 0) {
            throw new InvalidArgumentException('La visita debe referenciar una máquina existente.');
        }

        if ($restockerId <= 0) {
            throw new InvalidArgumentException('La visita debe referenciar un reponedor existente.');
        }

        foreach (['loading_order_id' => $loadingOrderId, 'route_schedule_id' => $routeScheduleId, 'triggered_incident_id' => $triggeredIncidentId] as $field => $value) {
            if ($value !== null && $value <= 0) {
                throw new InvalidArgumentException(sprintf(
                    'El campo %s debe ser un identificador positivo o null; se recibió %d.',
                    $field,
                    $value
                ));
            }
        }

        $cleanVisitCode = strtoupper(trim($visitCode));
        if (preg_match(self::VISIT_CODE_PATTERN, $cleanVisitCode) !== 1) {
            throw new InvalidArgumentException(sprintf(
                "El código de visita «%s» debe cumplir MRV-AAAA-NNNN y se normaliza en mayúsculas.",
                $visitCode
            ));
        }

        $cleanMutationId = trim($clientMutationId);
        if (mb_strlen($cleanMutationId, 'UTF-8') < self::MIN_MUTATION_ID_LENGTH) {
            throw new InvalidArgumentException(sprintf(
                'El client_mutation_id debe tener al menos %d caracteres reales; se recibieron %d.',
                self::MIN_MUTATION_ID_LENGTH,
                mb_strlen($cleanMutationId, 'UTF-8')
            ));
        }

        $cleanTemp = $cabinTempCelsius === null ? null : round($cabinTempCelsius, 1);
        if ($cleanTemp !== null
            && ($cleanTemp < self::MIN_CABIN_TEMP_CELSIUS || $cleanTemp > self::MAX_CABIN_TEMP_CELSIUS)) {
            throw new InvalidArgumentException(sprintf(
                'La temperatura de cabina debe estar entre %.1f y %.1f °C; se recibió %.1f °C.',
                self::MIN_CABIN_TEMP_CELSIUS,
                self::MAX_CABIN_TEMP_CELSIUS,
                $cleanTemp
            ));
        }

        self::assertChronology($startedAt, $completedAt);

        $cleanPhoto = self::normalizeText($photoEvidenceUrl);
        $cleanNotes = self::normalizeText($internalNotes);

        if ($status->requiresSanitaryProtocol()) {
            self::assertThermalAbortProtocol($cleanTemp, $precautionarySealApplied, $cleanPhoto, $cleanNotes);
        }

        $this->id = $id;
        $this->visitCode = $cleanVisitCode;
        $this->machineId = $machineId;
        $this->restockerId = $restockerId;
        $this->status = $status;
        $this->clientMutationId = $cleanMutationId;
        $this->hygieneChecklist = $hygieneChecklist;
        $this->cabinTempCelsius = $cleanTemp;
        $this->precautionarySealApplied = $precautionarySealApplied;
        $this->photoEvidenceUrl = $cleanPhoto;
        $this->internalNotes = $cleanNotes;
        $this->loadingOrderId = $loadingOrderId;
        $this->routeScheduleId = $routeScheduleId;
        $this->startedAt = $startedAt;
        $this->completedAt = $completedAt;
        $this->triggeredIncidentId = $triggeredIncidentId;
        $this->createdAt = $createdAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getVisitCode(): string
    {
        return $this->visitCode;
    }

    public function getMachineId(): int
    {
        return $this->machineId;
    }

    public function getRestockerId(): int
    {
        return $this->restockerId;
    }

    public function getStatus(): RestockVisitStatus
    {
        return $this->status;
    }

    public function getClientMutationId(): string
    {
        return $this->clientMutationId;
    }

    public function getHygieneChecklist(): DailyHygieneChecklist
    {
        return $this->hygieneChecklist;
    }

    public function getCabinTempCelsius(): ?float
    {
        return $this->cabinTempCelsius;
    }

    public function isPrecautionarySealApplied(): bool
    {
        return $this->precautionarySealApplied;
    }

    public function getPhotoEvidenceUrl(): ?string
    {
        return $this->photoEvidenceUrl;
    }

    public function getInternalNotes(): ?string
    {
        return $this->internalNotes;
    }

    public function getLoadingOrderId(): ?int
    {
        return $this->loadingOrderId;
    }

    public function getRouteScheduleId(): ?int
    {
        return $this->routeScheduleId;
    }

    public function getStartedAt(): ?string
    {
        return $this->startedAt;
    }

    public function getCompletedAt(): ?string
    {
        return $this->completedAt;
    }

    public function getTriggeredIncidentId(): ?int
    {
        return $this->triggeredIncidentId;
    }

    public function getCreatedAt(): ?string
    {
        return $this->createdAt;
    }

    public function isCompleted(): bool
    {
        return $this->status->isCompleted();
    }

    public function isThermalAbort(): bool
    {
        return $this->status->isThermalAbort();
    }

    /**
     * ¿La visita necesita evidencia fotográfica? Siempre en un aborto térmico, y en
     * una completada solo si hubo unidades retiradas (EARS 03.2).
     */
    public function requiresPhotoEvidence(bool $hasWithdrawnUnits): bool
    {
        return $this->status->isThermalAbort() || $hasWithdrawnUnits;
    }

    /**
     * ¿La visita necesita nota interna? Misma regla que la fotografía (EARS 03.2).
     */
    public function requiresInternalNotes(bool $hasWithdrawnUnits): bool
    {
        return $this->status->isThermalAbort() || $hasWithdrawnUnits;
    }

    public function withId(int $id): self
    {
        $clone = clone $this;
        $clone->id = $id;

        return $clone;
    }

    /**
     * Vincula el ticket SAT creado o escalado en un aborto térmico (QA 2).
     */
    public function withTriggeredIncident(?int $triggeredIncidentId): self
    {
        if ($triggeredIncidentId !== null && $triggeredIncidentId <= 0) {
            throw new InvalidArgumentException('El ticket disparado debe ser un identificador positivo o null.');
        }

        $clone = clone $this;
        $clone->triggeredIncidentId = $triggeredIncidentId;

        return $clone;
    }

    /**
     * Vincula la orden de carga activa del reponedor (EARS 03.3).
     */
    public function withLoadingOrder(?int $loadingOrderId): self
    {
        if ($loadingOrderId !== null && $loadingOrderId <= 0) {
            throw new InvalidArgumentException('La orden de carga debe ser un identificador positivo o null.');
        }

        $clone = clone $this;
        $clone->loadingOrderId = $loadingOrderId;

        return $clone;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'visit_code' => $this->visitCode,
            'machine_id' => $this->machineId,
            'restocker_id' => $this->restockerId,
            'loading_order_id' => $this->loadingOrderId,
            'route_schedule_id' => $this->routeScheduleId,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'started_at' => $this->startedAt,
            'completed_at' => $this->completedAt,
            'cabin_temp_celsius' => $this->cabinTempCelsius,
            'hygiene_checklist' => $this->hygieneChecklist->toArray(),
            'precautionary_seal_applied' => $this->precautionarySealApplied,
            'photo_evidence_url' => $this->photoEvidenceUrl,
            'internal_notes' => $this->internalNotes,
            'triggered_incident_id' => $this->triggeredIncidentId,
            'client_mutation_id' => $this->clientMutationId,
            'is_thermal_abort' => $this->isThermalAbort(),
            'created_at' => $this->createdAt,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * @throws InvalidArgumentException Si una marca temporal no es interpretable o el
     *                                  cierre precede a la apertura.
     */
    private static function assertChronology(?string $startedAt, ?string $completedAt): void
    {
        if ($startedAt === null || $completedAt === null) {
            return;
        }

        try {
            $started = new DateTimeImmutable($startedAt);
            $completed = new DateTimeImmutable($completedAt);
        } catch (Exception $exception) {
            throw new InvalidArgumentException(
                'Las marcas temporales de la visita no son fechas interpretables: ' . $exception->getMessage(),
                0,
                $exception
            );
        }

        if ($completed < $started) {
            throw new InvalidArgumentException(sprintf(
                'La visita no puede cerrarse (%s) antes de abrirse (%s); viola chk_machine_restock_visits_time_order.',
                $completed->format(DATE_ATOM),
                $started->format(DATE_ATOM)
            ));
        }
    }

    /**
     * Misma regla que `chk_machine_restock_visits_thermal_abort_coherence`: sin
     * precinto, lectura, foto o nota de 20 caracteres reales no es un aborto válido.
     *
     * @throws InvalidArgumentException
     */
    private static function assertThermalAbortProtocol(
        ?float $cabinTempCelsius,
        bool $precautionarySealApplied,
        ?string $photoEvidenceUrl,
        ?string $internalNotes
    ): void {
        $missing = [];

        if (!$precautionarySealApplied) {
            $missing[] = 'precautionary_seal_applied = TRUE';
        }
        if ($cabinTempCelsius === null) {
            $missing[] = 'cabin_temp_celsius';
        }
        if ($photoEvidenceUrl === null) {
            $missing[] = 'photo_evidence_url';
        }
        if ($internalNotes === null
            || mb_strlen($internalNotes, 'UTF-8') < self::MIN_INTERNAL_NOTES_LENGTH) {
            $missing[] = sprintf('internal_notes de %d caracteres reales', self::MIN_INTERNAL_NOTES_LENGTH);
        }

        if ($missing !== []) {
            throw new InvalidArgumentException(
                'ART_II_THERMAL_ABORT_COHERENCE: un aborto por rotura de cadena de frío exige el protocolo completo; falta '
                . implode(', ', $missing) . '.'
            );
        }
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
