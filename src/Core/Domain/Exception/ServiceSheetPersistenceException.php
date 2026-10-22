<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Exception;

use RuntimeException;

/**
 * ServiceSheetPersistenceException
 *
 * La hoja de servicio no se pudo guardar (contratos §5, RD-13). Se lanza **dentro**
 * de la transacción del cierre y hacia fuera de ella: el gestor revierte las piezas,
 * el descuento de furgoneta y el cambio de estado, de modo que no existe el cierre
 * con la hoja a medias.
 *
 * Mapea al código HTTP 500 con el código técnico `SERVICE_SHEET_PERSISTENCE_FAILED`.
 * Es reintentable: nada quedó escrito y el técnico puede volver a cerrar.
 */
final class ServiceSheetPersistenceException extends RuntimeException
{
    public const HTTP_STATUS = 500;
    public const ERROR_CODE = 'SERVICE_SHEET_PERSISTENCE_FAILED';

    public function __construct(string $message = 'No se pudo guardar la hoja de servicio; el cierre se ha revertido.', ?\Throwable $previous = null)
    {
        parent::__construct($message, self::HTTP_STATUS, $previous);
    }

    public function getErrorCode(): string
    {
        return self::ERROR_CODE;
    }

    public function getHttpStatusCode(): int
    {
        return self::HTTP_STATUS;
    }
}
