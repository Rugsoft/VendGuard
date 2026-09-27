<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Model;

/**
 * Entidad de Dominio: PreventiveOrderItem
 *
 * Representa la respuesta y estado de un ítem normativo de inspección preventiva,
 * incluyendo su severidad (crítica o secundaria), observaciones del técnico de ruta
 * y la ruta de evidencia fotográfica vinculada.
 */
class PreventiveOrderItem
{
    public const STATUS_PASS = 'PASS';
    public const STATUS_WARN = 'WARN';
    public const STATUS_FAIL = 'FAIL';
    public const STATUS_NOT_APPLICABLE = 'NOT_APPLICABLE';

    public const VALID_STATUSES = [
        self::STATUS_PASS,
        self::STATUS_WARN,
        self::STATUS_FAIL,
        self::STATUS_NOT_APPLICABLE,
    ];

    private ?int $id;
    private int $preventiveOrderId;
    private string $itemCode;
    private string $itemDescription;
    private bool $isCritical;
    private string $status;
    private ?string $observations;
    private ?string $photoPath;
    private ?string $createdAt;

    public function __construct(
        int $preventiveOrderId,
        string $itemCode,
        string $itemDescription,
        bool $isCritical,
        string $status,
        ?string $observations = null,
        ?string $photoPath = null,
        ?int $id = null,
        ?string $createdAt = null
    ) {
        $this->preventiveOrderId = $preventiveOrderId;
        $this->itemCode = trim($itemCode);
        $this->itemDescription = trim($itemDescription);
        $this->isCritical = $isCritical;
        $this->status = strtoupper(trim($status));
        $this->observations = $observations !== null ? trim($observations) : null;
        $this->photoPath = $photoPath !== null ? trim($photoPath) : null;
        $this->id = $id;
        $this->createdAt = $createdAt;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getPreventiveOrderId(): int
    {
        return $this->preventiveOrderId;
    }

    public function getItemCode(): string
    {
        return $this->itemCode;
    }

    public function getItemDescription(): string
    {
        return $this->itemDescription;
    }

    public function isCritical(): bool
    {
        return $this->isCritical;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getObservations(): ?string
    {
        return $this->observations;
    }

    public function getPhotoPath(): ?string
    {
        return $this->photoPath;
    }

    public function getCreatedAt(): ?string
    {
        return $this->createdAt;
    }

    public function setPhotoPath(?string $photoPath): self
    {
        $this->photoPath = $photoPath !== null ? trim($photoPath) : null;
        return $this;
    }

    public function setObservations(?string $observations): self
    {
        $this->observations = $observations !== null ? trim($observations) : null;
        return $this;
    }

    public function setStatus(string $status): self
    {
        $this->status = strtoupper(trim($status));
        return $this;
    }

    public function isPass(): bool
    {
        return $this->status === self::STATUS_PASS;
    }

    public function isWarn(): bool
    {
        return $this->status === self::STATUS_WARN;
    }

    public function isFail(): bool
    {
        return $this->status === self::STATUS_FAIL;
    }

    public function isNotApplicable(): bool
    {
        return $this->status === self::STATUS_NOT_APPLICABLE;
    }

    /**
     * Determina si este ítem constituye un fallo crítico que imposibilita la conformidad
     * y exige la cuarentena inmediata de la máquina (Art. II de la Constitución).
     */
    public function isCriticalFailure(): bool
    {
        return $this->isCritical && $this->status === self::STATUS_FAIL;
    }

    /**
     * Serializa la entidad a array asociativo para respuestas JSON y contratos de API.
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'preventive_order_id' => $this->preventiveOrderId,
            'item_code' => $this->itemCode,
            'item_description' => $this->itemDescription,
            'is_critical' => $this->isCritical,
            'status' => $this->status,
            'observations' => $this->observations,
            'photo_path' => $this->photoPath,
            'created_at' => $this->createdAt,
        ];
    }
}
