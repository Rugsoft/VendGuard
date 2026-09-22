<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Exception;

use InvalidArgumentException;

/**
 * InvalidResolutionException
 * 
 * Excepción lanzada cuando los datos de justificación técnica para el cierre
 * de una avería no cumplen los requisitos mínimos de extensión y detalle (RF-08).
 */
class InvalidResolutionException extends InvalidArgumentException
{
    /** @var array<string> */
    private array $errors;

    /**
     * @param string $message
     * @param array<string> $errors
     */
    public function __construct(string $message, array $errors = [])
    {
        parent::__construct($message);
        $this->errors = $errors;
    }

    /**
     * Devuelve la lista detallada de errores de validación.
     *
     * @return array<string>
     */
    public function getErrors(): array
    {
        return $this->errors;
    }
}
