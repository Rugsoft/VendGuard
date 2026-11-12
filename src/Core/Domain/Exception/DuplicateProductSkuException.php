<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Exception;

use DomainException;

/**
 * DuplicateProductSkuException
 *
 * El catálogo ya contiene una referencia con el mismo `sku` (RF-01.2,
 * `DUPLICATE_PRODUCT_SKU`, HTTP 409). La lanza el repositorio al traducir la
 * violación de unicidad del motor, incluida la carrera de dos altas simultáneas
 * que la comprobación previa del servicio no puede ver.
 */
final class DuplicateProductSkuException extends DomainException
{
    public const HTTP_STATUS = 409;
    public const ERROR_CODE = 'DUPLICATE_PRODUCT_SKU';

    private string $sku;

    public function __construct(string $sku, string $message = '')
    {
        $this->sku = $sku;
        parent::__construct(
            $message !== ''
                ? $message
                : "Ya existe un producto en el catálogo con el SKU '{$sku}'.",
            self::HTTP_STATUS
        );
    }

    public static function forSku(string $sku): self
    {
        return new self($sku);
    }

    public function getErrorCode(): string
    {
        return self::ERROR_CODE;
    }

    public function getHttpStatusCode(): int
    {
        return self::HTTP_STATUS;
    }

    public function getSku(): string
    {
        return $this->sku;
    }
}
