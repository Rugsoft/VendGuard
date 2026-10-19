<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\ValueObject;

use InvalidArgumentException;

/**
 * OpportunisticOriginReason
 *
 * Submotivo del hallazgo que origina una intervención oportunista in situ
 * (Módulo 16). Coincide exactamente con el enum `opportunistic_origin_reason`
 * de PostgreSQL y con la regla de coherencia `chk_incidents_opportunistic_coherence`.
 *
 * El submotivo es **obligatorio** cuando hay modalidad (RF-04.1): es el dato con
 * el que la Sede y Coordinación entienden si el aviso llegó por el pasillo o si
 * lo detectó el propio técnico. `WARRANTY_REOPEN_IN_SITU` es el único motivo que
 * convive con las dos modalidades, porque una reapertura en garantía puede nacer
 * de un expediente abierto en el acto o de uno preexistente.
 *
 * Dogma Vanilla: PHP 8.2+ puro, sin dependencias.
 * Dualismo Lingüístico: código en inglés, etiquetas y mensajes en castellano.
 */
enum OpportunisticOriginReason: string
{
    case SITE_VERBAL_NOTICE = 'SITE_VERBAL_NOTICE';
    case TECHNICIAN_DETECTION = 'TECHNICIAN_DETECTION';
    case EXISTING_TICKET_CLAIM = 'EXISTING_TICKET_CLAIM';
    case WARRANTY_REOPEN_IN_SITU = 'WARRANTY_REOPEN_IN_SITU';

    /**
     * Etiqueta canónica en castellano del submotivo.
     */
    public function label(): string
    {
        return match ($this) {
            self::SITE_VERBAL_NOTICE => 'Aviso verbal en sede',
            self::TECHNICIAN_DETECTION => 'Detección propia del técnico',
            self::EXISTING_TICKET_CLAIM => 'Asunción de ticket abierto in situ',
            self::WARRANTY_REOPEN_IN_SITU => 'Reapertura en garantía in situ',
        };
    }

    /**
     * ¿El submotivo es coherente con la modalidad declarada?
     *
     * Es la misma regla que impone la base de datos, escrita una sola vez en el
     * dominio para que el servicio pueda rechazar antes de persistir (fail-fast).
     */
    public function isAllowedFor(OpportunisticMode $mode): bool
    {
        return match ($this) {
            self::SITE_VERBAL_NOTICE,
            self::TECHNICIAN_DETECTION => $mode === OpportunisticMode::NEW,
            self::EXISTING_TICKET_CLAIM => $mode === OpportunisticMode::ASSUMED,
            self::WARRANTY_REOPEN_IN_SITU => true,
        };
    }

    /**
     * Submotivos que puede declarar una modalidad, en el orden de la especificación.
     *
     * @return list<self>
     */
    public static function allowedFor(OpportunisticMode $mode): array
    {
        return array_values(array_filter(
            self::cases(),
            static fn (self $reason): bool => $reason->isAllowedFor($mode)
        ));
    }

    public static function isValid(string $value): bool
    {
        return self::tryFrom(strtoupper(trim($value))) !== null;
    }

    /**
     * @throws InvalidArgumentException Si el valor no pertenece al catálogo cerrado.
     */
    public static function fromString(string $value): self
    {
        $normalized = strtoupper(trim($value));
        $reason = self::tryFrom($normalized);

        if ($reason === null) {
            throw new InvalidArgumentException(sprintf('Submotivo oportunista no válido: «%s»', $value));
        }

        return $reason;
    }
}
