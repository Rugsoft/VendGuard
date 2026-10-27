<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Exception;

use DomainException;

/**
 * VanRestockOrderValidationException
 *
 * Incumplimiento del contrato de órdenes de carga de furgoneta
 * (Módulo 23, contratos §4.2).
 *
 * El controlador no decide nada: lee `getErrorCode()` y `getHttpStatusCode()`.
 * Un 422 dice que la petición existe pero la regla de negocio no la admite; un
 * 409 que colisiona con el estado persistido (orden abierta o transición
 * inválida); un 404 que el recurso no existe; y un 403 que la orden pertenece a
 * otro técnico (Art. V.4).
 *
 * Los siete códigos son canónicos y estables: el frontend y las suites los
 * comparan por cadena, no por el texto del mensaje.
 *
 * Dogma Vanilla: PHP 8.2+ puro, sin dependencias.
 * Dualismo Lingüístico: código en inglés, mensajes en castellano.
 */
final class VanRestockOrderValidationException extends DomainException
{
    public const INVALID_RESTOCK_ORDER_PAYLOAD = 'INVALID_RESTOCK_ORDER_PAYLOAD';
    public const TECHNICIAN_NOT_ELIGIBLE = 'TECHNICIAN_NOT_ELIGIBLE';
    public const SPARE_PART_NOT_ELIGIBLE = 'SPARE_PART_NOT_ELIGIBLE';
    public const RESTOCK_ORDER_NOT_FOUND = 'RESTOCK_ORDER_NOT_FOUND';
    public const OPEN_RESTOCK_ORDER_ALREADY_EXISTS = 'OPEN_RESTOCK_ORDER_ALREADY_EXISTS';
    public const INVALID_RESTOCK_ORDER_TRANSITION = 'INVALID_RESTOCK_ORDER_TRANSITION';
    public const RESTOCK_ORDER_OWNERSHIP_MISMATCH = 'RESTOCK_ORDER_OWNERSHIP_MISMATCH';

    public const UNPROCESSABLE_HTTP_STATUS = 422;
    public const CONFLICT_HTTP_STATUS = 409;
    public const NOT_FOUND_HTTP_STATUS = 404;
    public const FORBIDDEN_HTTP_STATUS = 403;

    public function __construct(
        string $message,
        private readonly string $errorCode,
        private readonly int $httpStatusCode
    ) {
        parent::__construct($message, $httpStatusCode);
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    public function getHttpStatusCode(): int
    {
        return $this->httpStatusCode;
    }

    /**
     * Contrato incumplido: líneas vacías, cantidades fuera de rango, duplicados,
     * tope de `9999` superado o suma despachada nula (EARS 01.3, EARS 03.2, QA 1).
     */
    public static function invalidPayload(string $detail): self
    {
        return new self(
            'La orden de carga no cumple el contrato: ' . $detail,
            self::INVALID_RESTOCK_ORDER_PAYLOAD,
            self::UNPROCESSABLE_HTTP_STATUS
        );
    }

    /**
     * Justificación de cancelación por debajo del mínimo real del Art. V.1.
     */
    public static function cancellationReasonTooShort(int $minimumLength): self
    {
        return new self(
            "La justificación de la cancelación debe tener al menos {$minimumLength} caracteres reales.",
            self::INVALID_RESTOCK_ORDER_PAYLOAD,
            self::UNPROCESSABLE_HTTP_STATUS
        );
    }

    /**
     * El destinatario no existe, no es técnico o su cuenta está inactiva (QA 4).
     */
    public static function technicianNotEligible(string $detail): self
    {
        return new self(
            'El técnico destinatario no es elegible: ' . $detail,
            self::TECHNICIAN_NOT_ELIGIBLE,
            self::UNPROCESSABLE_HTTP_STATUS
        );
    }

    /**
     * La pieza no existe o está inactiva en el catálogo maestro (EARS 01.3).
     */
    public static function sparePartNotEligible(int $sparePartId, string $detail): self
    {
        return new self(
            "La pieza {$sparePartId} no es elegible: " . $detail,
            self::SPARE_PART_NOT_ELIGIBLE,
            self::UNPROCESSABLE_HTTP_STATUS
        );
    }

    public static function orderNotFound(int $orderId): self
    {
        return new self(
            "No existe la orden de carga {$orderId}.",
            self::RESTOCK_ORDER_NOT_FOUND,
            self::NOT_FOUND_HTTP_STATUS
        );
    }

    /**
     * El técnico ya tiene una orden en `PENDING_PICKING` o `DISPATCHED` (D3).
     *
     * El mensaje nombra la orden vigente para que Coordinación pueda editarla o
     * cancelarla antes de emitir otra (EARS 01.1).
     */
    public static function openOrderAlreadyExists(string $openOrderCode): self
    {
        return new self(
            "El técnico ya tiene la orden de carga {$openOrderCode} abierta; edítela o cancélela antes de emitir otra.",
            self::OPEN_RESTOCK_ORDER_ALREADY_EXISTS,
            self::CONFLICT_HTTP_STATUS
        );
    }

    /**
     * La acción no se admite desde el estado actual de la orden (D1).
     */
    public static function invalidTransition(string $currentStatus, string $action): self
    {
        return new self(
            "No se puede {$action} una orden de carga en estado {$currentStatus}.",
            self::INVALID_RESTOCK_ORDER_TRANSITION,
            self::CONFLICT_HTTP_STATUS
        );
    }

    /**
     * Aislamiento horizontal del técnico (Art. V.4): sólo el titular consulta o
     * confirma su propia orden.
     */
    public static function ownershipMismatch(): self
    {
        return new self(
            'La orden de carga pertenece a otro técnico.',
            self::RESTOCK_ORDER_OWNERSHIP_MISMATCH,
            self::FORBIDDEN_HTTP_STATUS
        );
    }
}
