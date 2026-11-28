<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Model;

use InvalidArgumentException;
use JsonSerializable;
use VendGuard\Core\Domain\Enum\DexSelectionMatchStatus;

/**
 * MachineDexSelectionLine
 *
 * Línea de selección `PA1`/`PA2` de un volcado EVA-DTS `.dex` (Módulo 39,
 * Tabla 53 `machine_dex_selection_lines`, RF-01.1, C4, QA-1, QA-3, QA-4,
 * Art. III.3 y Art. V.4).
 *
 * **Inmutable (Art. III.3).** El motor rechaza con `45000` cualquier `UPDATE`
 * de la línea (`prevent_dex_line_rewrite()`), y la entidad replica esa regla:
 * cada ajuste devuelve una copia y el histórico nunca se reescribe.
 *
 * **Invariantes en el borde.** El constructor replica las identidades del motor
 * que no pueden esperar a la base: secuencia positiva, códigos no vacíos
 * (crudo 1..32, normalizado 1..24 en mayúsculas), coherencia del emparejamiento
 * —solo `EXACT_MATCH` y `LEADING_ZERO_MATCH` llevan carril y snapshot; los
 * estados ambiguos, ausentes y el café informativo no inventan uno— y cotas no
 * negativas de unidades e importes.
 *
 * **Proyecciones segregadas (QA-1, Art. V.4).** La vista por defecto es la del
 * móvil: unidades y código de carril, sin un solo importe en euros. La vista
 * financiera completa (`toCoordinatorArray()`) exige una llamada explícita.
 *
 * `slot_current_estimated_qty` no forma parte del fichero ni de la tabla: es la
 * existencia estimada del carril en el instante de la lectura, que el servicio
 * enriquece desde el planograma para que el móvil muestre el consumo. La entidad
 * la guarda como dato de lectura (`withSlotCurrentEstimatedQty()`), nunca como
 * un hecho persistido del volcado.
 *
 * Dogma Vanilla: PHP 8.2+ puro, propiedades privadas y métodos puros.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */
final class MachineDexSelectionLine implements JsonSerializable
{
    /** Cotas del contrato de esquema. */
    public const MAX_SELECTION_CODE_LENGTH = 32;
    public const MAX_NORMALIZED_CODE_LENGTH = 24;
    public const MAX_SLOT_CODE_LENGTH = 24;
    public const MAX_TOTAL_SALES_EUR = 9999999999.99;
    public const MAX_UNIT_PRICE_EUR = 999999.99;
    public const MAX_UNITS = 999999999;

    private ?int $id;
    private int $dexAuditId;
    private int $lineSequence;
    private string $rawSelectionCode;
    private string $normalizedSelectionCode;
    private ?int $matchedSlotId;
    private ?string $matchedSlotCodeSnapshot;
    private DexSelectionMatchStatus $matchStatus;
    private ?float $unitPriceEur;
    private int $totalVendUnits;
    private float $totalSalesEur;
    private ?int $resetVendUnits;
    private ?float $resetSalesEur;
    private ?int $deltaVendUnits;
    private ?float $deltaSalesEur;
    private ?string $createdAt;
    private ?int $slotCurrentEstimatedQty;

    /**
     * @param int|null $id Identificador de fila; `null` antes de persistir.
     * @param int $dexAuditId Volcado al que pertenece la línea.
     * @param int $lineSequence Posición dentro del bloque `PA1`/`PA2` (>= 1).
     * @param string $rawSelectionCode Código tal y como llega en el `.dex`.
     * @param string $normalizedSelectionCode Código normalizado; se pliega a mayúsculas.
     * @param DexSelectionMatchStatus $matchStatus Resultado del emparejamiento (C4).
     * @param int|null $matchedSlotId Carril emparejado, solo en los dos estados reales.
     * @param string|null $matchedSlotCodeSnapshot Snapshot del código de carril.
     * @param float|null $unitPriceEur Precio unitario congelado (vista financiera).
     * @param int $totalVendUnits Unidades vendidas del contador `PA1`.
     * @param float $totalSalesEur Ventas acumuladas del contador `PA1`.
     * @param int|null $resetVendUnits Unidades del contador de reseteo (`PA2`).
     * @param float|null $resetSalesEur Importe del contador de reseteo (`PA2`).
     * @param int|null $deltaVendUnits Delta de unidades frente al volcado previo.
     * @param float|null $deltaSalesEur Delta monetario frente al volcado previo.
     * @param string|null $createdAt Sello de alta del motor.
     * @param int|null $slotCurrentEstimatedQty Existencia estimada del carril, dato de lectura.
     *
     * @throws InvalidArgumentException Si un código, una secuencia, el
     *                                  emparejamiento o una cota no cumplen el contrato.
     */
    public function __construct(
        ?int $id,
        int $dexAuditId,
        int $lineSequence,
        string $rawSelectionCode,
        string $normalizedSelectionCode,
        DexSelectionMatchStatus $matchStatus,
        ?int $matchedSlotId = null,
        ?string $matchedSlotCodeSnapshot = null,
        ?float $unitPriceEur = null,
        int $totalVendUnits = 0,
        float $totalSalesEur = 0.0,
        ?int $resetVendUnits = null,
        ?float $resetSalesEur = null,
        ?int $deltaVendUnits = null,
        ?float $deltaSalesEur = null,
        ?string $createdAt = null,
        ?int $slotCurrentEstimatedQty = null
    ) {
        if ($id !== null && $id <= 0) {
            throw new InvalidArgumentException('El identificador de la línea debe ser un entero positivo.');
        }

        if ($dexAuditId <= 0) {
            throw new InvalidArgumentException('La línea debe pertenecer a un volcado existente.');
        }

        if ($lineSequence < 1) {
            throw new InvalidArgumentException('La secuencia de la línea debe ser mayor o igual que 1.');
        }

        $cleanRawCode = trim($rawSelectionCode);
        if ($cleanRawCode === '' || mb_strlen($cleanRawCode, 'UTF-8') > self::MAX_SELECTION_CODE_LENGTH) {
            throw new InvalidArgumentException(sprintf(
                'El código de selección crudo debe tener entre 1 y %d caracteres.',
                self::MAX_SELECTION_CODE_LENGTH
            ));
        }

        $cleanNormalizedCode = strtoupper(trim($normalizedSelectionCode));
        if ($cleanNormalizedCode === '' || mb_strlen($cleanNormalizedCode, 'UTF-8') > self::MAX_NORMALIZED_CODE_LENGTH) {
            throw new InvalidArgumentException(sprintf(
                'El código de selección normalizado debe tener entre 1 y %d caracteres.',
                self::MAX_NORMALIZED_CODE_LENGTH
            ));
        }

        $cleanSlotId = $matchedSlotId;
        $cleanSlotCode = $matchedSlotCodeSnapshot === null ? null : trim($matchedSlotCodeSnapshot);

        if ($cleanSlotCode === '') {
            $cleanSlotCode = null;
        }

        if ($cleanSlotCode !== null && mb_strlen($cleanSlotCode, 'UTF-8') > self::MAX_SLOT_CODE_LENGTH) {
            throw new InvalidArgumentException(sprintf(
                'El snapshot del código de carril no puede superar los %d caracteres.',
                self::MAX_SLOT_CODE_LENGTH
            ));
        }

        if ($matchStatus->requiresSlotMatch()) {
            if ($cleanSlotId === null || $cleanSlotId <= 0 || $cleanSlotCode === null) {
                throw new InvalidArgumentException(sprintf(
                    'El estado de emparejamiento «%s» exige carril y snapshot de código; sin ellos la coincidencia no existe (C4).',
                    $matchStatus->value
                ));
            }
        } elseif ($cleanSlotId !== null || $cleanSlotCode !== null) {
            throw new InvalidArgumentException(sprintf(
                'El estado de emparejamiento «%s» no puede inventar un carril ni un snapshot de código (C4).',
                $matchStatus->value
            ));
        }

        self::assertUnits($totalVendUnits, 'total_vend_units');
        self::assertUnits($resetVendUnits, 'reset_vend_units');
        self::assertMoney($totalSalesEur, self::MAX_TOTAL_SALES_EUR, 'total_sales_eur');
        self::assertMoney($resetSalesEur, self::MAX_TOTAL_SALES_EUR, 'reset_sales_eur');
        self::assertMoney($unitPriceEur, self::MAX_UNIT_PRICE_EUR, 'unit_price_eur');

        if ($slotCurrentEstimatedQty !== null && $slotCurrentEstimatedQty < 0) {
            throw new InvalidArgumentException('La existencia estimada del carril no puede ser negativa.');
        }

        $this->id = $id;
        $this->dexAuditId = $dexAuditId;
        $this->lineSequence = $lineSequence;
        $this->rawSelectionCode = $cleanRawCode;
        $this->normalizedSelectionCode = $cleanNormalizedCode;
        $this->matchedSlotId = $matchStatus->requiresSlotMatch() ? $cleanSlotId : null;
        $this->matchedSlotCodeSnapshot = $matchStatus->requiresSlotMatch() ? $cleanSlotCode : null;
        $this->matchStatus = $matchStatus;
        $this->unitPriceEur = self::roundMoney($unitPriceEur);
        $this->totalVendUnits = $totalVendUnits;
        $this->totalSalesEur = self::roundMoney($totalSalesEur) ?? 0.0;
        $this->resetVendUnits = $resetVendUnits;
        $this->resetSalesEur = self::roundMoney($resetSalesEur);
        $this->deltaVendUnits = $deltaVendUnits;
        $this->deltaSalesEur = self::roundMoney($deltaSalesEur);
        $this->createdAt = $createdAt;
        $this->slotCurrentEstimatedQty = $slotCurrentEstimatedQty;
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

    public function getRawSelectionCode(): string
    {
        return $this->rawSelectionCode;
    }

    public function getNormalizedSelectionCode(): string
    {
        return $this->normalizedSelectionCode;
    }

    public function getMatchedSlotId(): ?int
    {
        return $this->matchedSlotId;
    }

    public function getMatchedSlotCodeSnapshot(): ?string
    {
        return $this->matchedSlotCodeSnapshot;
    }

    public function getMatchStatus(): DexSelectionMatchStatus
    {
        return $this->matchStatus;
    }

    public function getUnitPriceEur(): ?float
    {
        return $this->unitPriceEur;
    }

    public function getTotalVendUnits(): int
    {
        return $this->totalVendUnits;
    }

    public function getTotalSalesEur(): float
    {
        return $this->totalSalesEur;
    }

    public function getResetVendUnits(): ?int
    {
        return $this->resetVendUnits;
    }

    public function getResetSalesEur(): ?float
    {
        return $this->resetSalesEur;
    }

    public function getDeltaVendUnits(): ?int
    {
        return $this->deltaVendUnits;
    }

    public function getDeltaSalesEur(): ?float
    {
        return $this->deltaSalesEur;
    }

    public function getCreatedAt(): ?string
    {
        return $this->createdAt;
    }

    public function getSlotCurrentEstimatedQty(): ?int
    {
        return $this->slotCurrentEstimatedQty;
    }

    public function withId(int $id): self
    {
        if ($id <= 0) {
            throw new InvalidArgumentException('El identificador de la línea debe ser un entero positivo.');
        }

        $clone = clone $this;
        $clone->id = $id;

        return $clone;
    }

    /**
     * Enriquecimiento de lectura desde el planograma (no es un hecho del volcado).
     */
    public function withSlotCurrentEstimatedQty(?int $slotCurrentEstimatedQty): self
    {
        if ($slotCurrentEstimatedQty !== null && $slotCurrentEstimatedQty < 0) {
            throw new InvalidArgumentException('La existencia estimada del carril no puede ser negativa.');
        }

        $clone = clone $this;
        $clone->slotCurrentEstimatedQty = $slotCurrentEstimatedQty;

        return $clone;
    }

    /**
     * Proyección del móvil: unidades y carril, cero euros (QA-1, Art. V.4).
     *
     * @return array<string, mixed>
     */
    public function toUnitProjection(): array
    {
        return [
            'normalized_selection_code' => $this->normalizedSelectionCode,
            'matched_slot_code' => $this->matchedSlotCodeSnapshot,
            'match_status' => $this->matchStatus->value,
            'slot_current_estimated_qty' => $this->slotCurrentEstimatedQty,
            'total_vend_units' => $this->totalVendUnits,
            'delta_vend_units' => $this->deltaVendUnits,
        ];
    }

    /**
     * Vista financiera completa, reservada a Coordinación (Art. V.4).
     *
     * @return array<string, mixed>
     */
    public function toCoordinatorArray(): array
    {
        return [
            'id' => $this->id,
            'dex_audit_id' => $this->dexAuditId,
            'line_sequence' => $this->lineSequence,
            'raw_selection_code' => $this->rawSelectionCode,
            'normalized_selection_code' => $this->normalizedSelectionCode,
            'matched_slot_id' => $this->matchedSlotId,
            'matched_slot_code_snapshot' => $this->matchedSlotCodeSnapshot,
            'match_status' => $this->matchStatus->value,
            'match_status_label' => $this->matchStatus->label(),
            'unit_price_eur' => $this->unitPriceEur,
            'total_vend_units' => $this->totalVendUnits,
            'total_sales_eur' => $this->totalSalesEur,
            'reset_vend_units' => $this->resetVendUnits,
            'reset_sales_eur' => $this->resetSalesEur,
            'delta_vend_units' => $this->deltaVendUnits,
            'delta_sales_eur' => $this->deltaSalesEur,
            'slot_current_estimated_qty' => $this->slotCurrentEstimatedQty,
            'created_at' => $this->createdAt,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->toUnitProjection();
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toUnitProjection();
    }

    private static function assertUnits(?int $units, string $field): void
    {
        if ($units !== null && ($units < 0 || $units > self::MAX_UNITS)) {
            throw new InvalidArgumentException(sprintf(
                'El campo %s debe estar entre 0 y %d; se recibió %d.',
                $field,
                self::MAX_UNITS,
                $units
            ));
        }
    }

    private static function assertMoney(?float $amount, float $ceiling, string $field): void
    {
        if ($amount !== null && ($amount < 0.0 || $amount > $ceiling)) {
            throw new InvalidArgumentException(sprintf(
                'El campo %s debe estar entre 0,00 y %.2f €; se recibió %.2f €.',
                $field,
                $ceiling,
                $amount
            ));
        }
    }

    private static function roundMoney(?float $amount): ?float
    {
        return $amount === null ? null : round($amount, 2);
    }
}
