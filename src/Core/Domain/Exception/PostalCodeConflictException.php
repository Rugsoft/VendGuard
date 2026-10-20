<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Exception;

use DomainException;

/**
 * PostalCodeConflictException
 *
 * Uno o varios códigos postales del lote pertenecen ya a otra zona operativa. La
 * regla de unicidad territorial (RF-01.2) no permite que un CP viva en dos zonas,
 * así que el traslado exige **confirmación expresa** del coordinador: mover un CP
 * arrastra a todas las sedes que lo heredaban y cambia el técnico que las atiende.
 *
 * No es un error de formato ni un bloqueo definitivo: es la señal de que la
 * operación debe presentarse en un modal con el detalle de los CP, la zona de
 * origen y el número de sedes afectadas antes de aplicarse de forma atómica
 * (RF-01.3, Resolución QA 4). Mapea a HTTP 409 con el código
 * `POSTAL_CODES_REQUIRE_CONFIRMATION` del contrato.
 *
 * Dogma Vanilla: PHP 8.2+ puro, sin dependencias.
 * Dualismo Lingüístico: código en inglés, documentación y mensaje en castellano.
 */
class PostalCodeConflictException extends DomainException
{
    public const HTTP_STATUS = 409;
    public const ERROR_CODE = 'POSTAL_CODES_REQUIRE_CONFIRMATION';
    public const DEFAULT_MESSAGE = 'Uno o varios códigos postales pertenecen a otra zona activa y requieren confirmación de traslado.';

    /**
     * @param list<array{
     *     postal_code: string,
     *     current_zone_id: int,
     *     current_zone_name: string,
     *     affected_locations_count: int
     * }> $conflicts Detalle que el modal de confirmación necesita para decidir.
     */
    public function __construct(
        private readonly array $conflicts = [],
        string $message = self::DEFAULT_MESSAGE
    ) {
        parent::__construct($message, self::HTTP_STATUS);
    }

    public function getErrorCode(): string
    {
        return self::ERROR_CODE;
    }

    public function getHttpStatusCode(): int
    {
        return self::HTTP_STATUS;
    }

    /**
     * @return list<array{
     *     postal_code: string,
     *     current_zone_id: int,
     *     current_zone_name: string,
     *     affected_locations_count: int
     * }>
     */
    public function getConflicts(): array
    {
        return $this->conflicts;
    }

    /**
     * @return array<string, mixed>
     */
    public function getDetails(): array
    {
        return ['conflicts' => $this->conflicts];
    }
}
