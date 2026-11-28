<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Model;

use InvalidArgumentException;
use JsonSerializable;
use VendGuard\Core\Domain\Enum\DexEventCategory;
use VendGuard\Core\Domain\Enum\DexEventSeverity;

/**
 * MachineDexEventLine
 *
 * Evento `EA1`/`EA2` de un volcado EVA-DTS `.dex` (Módulo 39, Tabla 54
 * `machine_dex_event_lines`, RF-01.1, RF-05.1, D4, QA-4, Art. II.2, Art. III.3
 * y Art. V.4).
 *
 * **Inmutable (Art. III.3).** El motor rechaza con `45000` cualquier `UPDATE`
 * de la línea (`prevent_dex_line_rewrite()`); la entidad devuelve copias y nunca
 * reescribe un evento ya parseado.
 *
 * **Invariantes en el borde.** Bloque `EA1`/`EA2`, código y descripción no
 * vacíos, `occurrence_count >= 1`, contadores `EA202`/`EA203` no negativos y la
 * coherencia que impone `chk_machine_dex_event_lines_thermal_coherence`: el
 * aviso térmico (`is_thermal_alert`) solo puede colgar de un evento
 * `THERMAL_REFRIGERATION`. Es la misma regla en dos capas (`RNF-02`).
 *
 * La proyección por defecto es la de las superficies móviles —unidades,
 * contadores y clasificación— sin importes; el Coordinador recibe la misma
 * información con sus identificadores. Los eventos no llevan euros en ninguna
 * variante: `EA1`/`EA2` describen fallos, no ventas (QA-1, Art. V.4).
 *
 * Dogma Vanilla: PHP 8.2+ puro, propiedades privadas y métodos puros.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */
final class MachineDexEventLine implements JsonSerializable
{
    public const MAX_EVENT_CODE_LENGTH = 32;
    public const MAX_DESCRIPTION_LENGTH = 255;
    public const MAX_COUNTER = 999999999;

    private ?int $id;
    private int $dexAuditId;
    private int $lineSequence;
    private string $sourceBlock;
    private string $eventCode;
    private DexEventCategory $eventCategory;
    private DexEventSeverity $severity;
    private string $descriptionEs;
    private int $occurrenceCount;
    private ?int $countSinceReset;
    private ?int $countSinceInit;
    private bool $isActiveFlag;
    private ?string $eventOccurredAtRaw;
    private bool $isThermalAlert;
    private ?string $createdAt;

    /**
     * @param int|null $id Identificador de fila; `null` antes de persistir.
     * @param int $dexAuditId Volcado al que pertenece el evento.
     * @param int $lineSequence Posición dentro del bloque `EA1`/`EA2` (>= 1).
     * @param string $sourceBlock Bloque de origen: `EA1` o `EA2`.
     * @param string $eventCode Código del evento (`EG01`, `EA202`, …).
     * @param DexEventCategory $eventCategory Familia funcional asignada por el catálogo.
     * @param DexEventSeverity $severity Gravedad del evento.
     * @param string $descriptionEs Descripción legible del evento.
     * @param int $occurrenceCount Ocurrencias agregadas (>= 1).
     * @param int|null $countSinceReset Contador `EA202` desde el último reseteo.
     * @param int|null $countSinceInit Contador `EA203` desde la puesta en marcha.
     * @param bool $isActiveFlag Bandera `EA205 = '1'`.
     * @param string|null $eventOccurredAtRaw Marca temporal tal y como llega en el `.dex`.
     * @param bool $isThermalAlert ¿Dispara el aviso sanitario del Art. II.2?
     * @param string|null $createdAt Sello de alta del motor.
     *
     * @throws InvalidArgumentException Si el bloque, el código, la descripción,
     *                                  los contadores o la coherencia térmica no
     *                                  cumplen el contrato.
     */
    public function __construct(
        ?int $id,
        int $dexAuditId,
        int $lineSequence,
        string $sourceBlock,
        string $eventCode,
        DexEventCategory $eventCategory,
        DexEventSeverity $severity,
        string $descriptionEs,
        int $occurrenceCount = 1,
        ?int $countSinceReset = null,
        ?int $countSinceInit = null,
        bool $isActiveFlag = false,
        ?string $eventOccurredAtRaw = null,
        bool $isThermalAlert = false,
        ?string $createdAt = null
    ) {
        if ($id !== null && $id <= 0) {
            throw new InvalidArgumentException('El identificador del evento debe ser un entero positivo.');
        }

        if ($dexAuditId <= 0) {
            throw new InvalidArgumentException('El evento debe pertenecer a un volcado existente.');
        }

        if ($lineSequence < 1) {
            throw new InvalidArgumentException('La secuencia del evento debe ser mayor o igual que 1.');
        }

        $cleanBlock = strtoupper(trim($sourceBlock));
        if (!in_array($cleanBlock, ['EA1', 'EA2'], true)) {
            throw new InvalidArgumentException(sprintf(
                "El bloque de origen «%s» no es válido: solo se admiten EA1 y EA2.",
                $sourceBlock
            ));
        }

        $cleanCode = strtoupper(trim($eventCode));
        if ($cleanCode === '' || mb_strlen($cleanCode, 'UTF-8') > self::MAX_EVENT_CODE_LENGTH) {
            throw new InvalidArgumentException(sprintf(
                'El código de evento debe tener entre 1 y %d caracteres.',
                self::MAX_EVENT_CODE_LENGTH
            ));
        }

        $cleanDescription = trim($descriptionEs);
        if ($cleanDescription === '' || mb_strlen($cleanDescription, 'UTF-8') > self::MAX_DESCRIPTION_LENGTH) {
            throw new InvalidArgumentException(sprintf(
                'La descripción del evento debe tener entre 1 y %d caracteres.',
                self::MAX_DESCRIPTION_LENGTH
            ));
        }

        if ($occurrenceCount < 1) {
            throw new InvalidArgumentException('El número de ocurrencias debe ser mayor o igual que 1.');
        }

        foreach (['count_since_reset' => $countSinceReset, 'count_since_init' => $countSinceInit] as $field => $value) {
            if ($value !== null && ($value < 0 || $value > self::MAX_COUNTER)) {
                throw new InvalidArgumentException(sprintf(
                    'El campo %s debe estar entre 0 y %d; se recibió %d.',
                    $field,
                    self::MAX_COUNTER,
                    $value
                ));
            }
        }

        if ($isThermalAlert && !$eventCategory->allowsThermalAlert()) {
            throw new InvalidArgumentException(sprintf(
                'El aviso térmico solo puede colgar de un evento THERMAL_REFRIGERATION; se recibió «%s» (Art. II, QA-4).',
                $eventCategory->value
            ));
        }

        $this->id = $id;
        $this->dexAuditId = $dexAuditId;
        $this->lineSequence = $lineSequence;
        $this->sourceBlock = $cleanBlock;
        $this->eventCode = $cleanCode;
        $this->eventCategory = $eventCategory;
        $this->severity = $severity;
        $this->descriptionEs = $cleanDescription;
        $this->occurrenceCount = $occurrenceCount;
        $this->countSinceReset = $countSinceReset;
        $this->countSinceInit = $countSinceInit;
        $this->isActiveFlag = $isActiveFlag;
        $this->eventOccurredAtRaw = $eventOccurredAtRaw === null ? null : trim($eventOccurredAtRaw);
        $this->isThermalAlert = $isThermalAlert;
        $this->createdAt = $createdAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDexAuditId(): int
    {
        return $this->dexAuditId;
    }

    public function getLineSequence(): int
    {
        return $this->lineSequence;
    }

    public function getSourceBlock(): string
    {
        return $this->sourceBlock;
    }

    public function getEventCode(): string
    {
        return $this->eventCode;
    }

    public function getEventCategory(): DexEventCategory
    {
        return $this->eventCategory;
    }

    public function getSeverity(): DexEventSeverity
    {
        return $this->severity;
    }

    public function getDescriptionEs(): string
    {
        return $this->descriptionEs;
    }

    public function getOccurrenceCount(): int
    {
        return $this->occurrenceCount;
    }

    public function getCountSinceReset(): ?int
    {
        return $this->countSinceReset;
    }

    public function getCountSinceInit(): ?int
    {
        return $this->countSinceInit;
    }

    public function isActiveFlag(): bool
    {
        return $this->isActiveFlag;
    }

    public function getEventOccurredAtRaw(): ?string
    {
        return $this->eventOccurredAtRaw;
    }

    public function isThermalAlert(): bool
    {
        return $this->isThermalAlert;
    }

    public function getCreatedAt(): ?string
    {
        return $this->createdAt;
    }

    public function withId(int $id): self
    {
        if ($id <= 0) {
            throw new InvalidArgumentException('El identificador del evento debe ser un entero positivo.');
        }

        $clone = clone $this;
        $clone->id = $id;

        return $clone;
    }

    /**
     * Proyección clasificada para el móvil y para Coordinación (sin euros).
     *
     * @return array<string, mixed>
     */
    public function toProjection(): array
    {
        return [
            'source_block' => $this->sourceBlock,
            'event_code' => $this->eventCode,
            'event_category' => $this->eventCategory->value,
            'event_category_label' => $this->eventCategory->label(),
            'severity' => $this->severity->value,
            'severity_label' => $this->severity->label(),
            'occurrence_count' => $this->occurrenceCount,
            'count_since_reset' => $this->countSinceReset,
            'count_since_init' => $this->countSinceInit,
            'is_active_flag' => $this->isActiveFlag,
            'event_occurred_at_raw' => $this->eventOccurredAtRaw,
            'description_es' => $this->descriptionEs,
            'is_thermal_alert' => $this->isThermalAlert,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toCoordinatorArray(): array
    {
        return [
            'id' => $this->id,
            'dex_audit_id' => $this->dexAuditId,
            'line_sequence' => $this->lineSequence,
        ] + $this->toProjection() + [
            'created_at' => $this->createdAt,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->toProjection();
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toProjection();
    }
}
