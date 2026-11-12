<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Exception;

use DomainException;

/**
 * ProductNotFoundException
 *
 * La referencia solicitada no existe en `vending_products` (HTTP 404,
 * `PRODUCT_NOT_FOUND`). La usan las operaciones de edición y cambio de estado,
 * que no pueden inventar una fila para continuar.
 */
final class ProductNotFoundException extends DomainException
{
    public const HTTP_STATUS = 404;
    public const ERROR_CODE = 'PRODUCT_NOT_FOUND';

    private int $productId;

    public function __construct(int $productId, string $message = '')
    {
        $this->productId = $productId;
        parent::__construct(
            $message !== ''
                ? $message
                : "No existe el producto {$productId} en el catálogo maestro.",
            self::HTTP_STATUS
        );
    }

    public static function forId(int $productId): self
    {
        return new self($productId);
    }

    public function getErrorCode(): string
    {
        return self::ERROR_CODE;
    }

    public function getHttpStatusCode(): int
    {
        return self::HTTP_STATUS;
    }

    public function getProductId(): int
    {
        return $this->productId;
    }
}
