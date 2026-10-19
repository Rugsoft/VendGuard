<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\ValueObject;

use InvalidArgumentException;

/**
 * OpportunisticActionType
 *
 * Acción oportunista que el catálogo de una sede ofrece para cada máquina
 * (Módulo 16, RF-01.4 y RF-01.5). Es el resultado de cruzar el estado del
 * expediente activo (o de la ventana de garantía) con la titularidad del técnico
 * que consulta:
 *
 *  - `CREATE_NEW`: no hay expediente vivo; el técnico abre uno nuevo (Flujo A).
 *  - `ASSUME_DIRECT`: el expediente existe sin técnico; se asume en un toque.
 *  - `ASSUME_WITH_CONFIRMATION`: está a nombre de otro compañero; se asume con
 *    confirmación expresa y, opcionalmente, nota interna de traspaso.
 *  - `REOPEN_IN_SITU`: resuelto dentro de la garantía de 48 h; se reabre in situ.
 *  - `CURRENT_TECHNICIAN_IN_PROGRESS`: ya lo lleva el propio técnico.
 *  - `BLOCKED_OTHER_TECH_IN_PROGRESS`: lo lleva otro compañero en curso; el
 *    Art. V.3 impide arrebatárselo y la interfaz lo muestra deshabilitado.
 *
 * Dogma Vanilla: PHP 8.2+ puro, sin dependencias.
 * Dualismo Lingüístico: código en inglés, etiquetas y mensajes en castellano.
 */
enum OpportunisticActionType: string
{
    case CREATE_NEW = 'CREATE_NEW';
    case ASSUME_DIRECT = 'ASSUME_DIRECT';
    case ASSUME_WITH_CONFIRMATION = 'ASSUME_WITH_CONFIRMATION';
    case REOPEN_IN_SITU = 'REOPEN_IN_SITU';
    case CURRENT_TECHNICIAN_IN_PROGRESS = 'CURRENT_TECHNICIAN_IN_PROGRESS';
    case BLOCKED_OTHER_TECH_IN_PROGRESS = 'BLOCKED_OTHER_TECH_IN_PROGRESS';

    /**
     * Etiqueta canónica que se muestra en la tarjeta de la máquina.
     */
    public function label(): string
    {
        return match ($this) {
            self::CREATE_NEW => 'Oportunista In Situ · Nueva',
            self::ASSUME_DIRECT => 'Asumir ahora',
            self::ASSUME_WITH_CONFIRMATION => 'Asumir con confirmación',
            self::REOPEN_IN_SITU => 'Reabrir in situ',
            self::CURRENT_TECHNICIAN_IN_PROGRESS => 'En curso contigo',
            self::BLOCKED_OTHER_TECH_IN_PROGRESS => 'En curso por otro técnico',
        };
    }

    /**
     * ¿La acción está disponible para el técnico que consulta?
     *
     * Sólo el bloqueo por intervención ajena la deshabilita: las demás son
     * ejecutables, aunque alguna exija confirmación expresa antes de escribir.
     */
    public function isAvailable(): bool
    {
        return $this !== self::BLOCKED_OTHER_TECH_IN_PROGRESS;
    }

    /**
     * ¿La acción exige confirmación expresa del técnico antes de ejecutarse?
     */
    public function requiresConfirmation(): bool
    {
        return $this === self::ASSUME_WITH_CONFIRMATION;
    }

    /**
     * ¿La acción abre un expediente nuevo (Flujo A) en lugar de asumir uno?
     */
    public function isCreation(): bool
    {
        return $this === self::CREATE_NEW;
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
        $action = self::tryFrom($normalized);

        if ($action === null) {
            throw new InvalidArgumentException(sprintf('Acción oportunista no válida: «%s»', $value));
        }

        return $action;
    }
}
