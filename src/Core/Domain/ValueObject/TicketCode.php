<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\ValueObject;

use InvalidArgumentException;

/**
 * TicketCode
 * 
 * Objeto de Valor inmutable para el código identificador de ticket (ej: INC-2026-0001).
 * Garantiza formato coherente, inmutabilidad y comparación por valor.
 */
final class TicketCode
{
    private string $value;

    public function __construct(string $code)
    {
        $normalized = strtoupper(trim($code));

        if (!preg_match('/^INC-\d{4}-[A-Z0-9]{4,10}$/', $normalized)) {
            throw new InvalidArgumentException(
                "Código de ticket no válido: '{$code}'. Formato requerido: INC-YYYY-XXXX (ej: INC-2026-0001)"
            );
        }

        $this->value = $normalized;
    }

    /**
     * Obtiene el valor escalar del código de ticket.
     */
    public function value(): string
    {
        return $this->value;
    }

    /**
     * Genera un código de ticket aleatorio o secuencial para el año en curso.
     *
     * @param int|null $sequence Número secuencial opcional.
     * @param int|null $year Año de referencia (por defecto el actual).
     */
    public static function generate(?int $sequence = null, ?int $year = null): self
    {
        $yearStr = (string)($year ?? (int)date('Y'));

        if ($sequence !== null) {
            $suffix = str_pad((string)$sequence, 4, '0', STR_PAD_LEFT);
        } else {
            // Generar 4 caracteres alfanuméricos aleatorios sin caracteres ambiguos (0/O, 1/I)
            $chars = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';
            $suffix = '';
            for ($i = 0; $i < 4; $i++) {
                $suffix .= $chars[random_int(0, strlen($chars) - 1)];
            }
        }

        return new self("INC-{$yearStr}-{$suffix}");
    }

    /**
     * Compara igualdad estructural con otro TicketCode.
     */
    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
