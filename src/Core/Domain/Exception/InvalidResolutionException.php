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
    private ?string $field;

    /**
     * @param string $message
     * @param array<string> $errors
     * @param string|null $field
     */
    public function __construct(string $message, array $errors = [], ?string $field = null)
    {
        parent::__construct($message);
        $this->errors = $errors;
        $this->field = $field;
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

    /**
     * Devuelve el campo específico afectado (si aplica).
     */
    public function getField(): ?string
    {
        return $this->field;
    }
}

