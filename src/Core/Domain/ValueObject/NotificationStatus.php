<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\ValueObject;

use InvalidArgumentException;

/**
 * NotificationStatus
 *
 * Los 6 estados canónicos de la bandeja de salida transaccional (RF-01.1).
 * Coincide exactamente con el enum `notification_status` de PostgreSQL.
 *
 * Estados:
 *   1. PENDING: Registrada y lista para su primer intento de entrega.
 *   2. PROCESSING: Bloqueada temporalmente por el despachador durante el intento activo.
 *   3. PENDING_RETRY: En espera de que venza su backoff progresivo tras fallo transitorio.
 *   4. SENT: Entregada y confirmada con éxito por el proveedor externo.
 *   5. FAILED_PERMANENT: Envío detenido tras 5 intentos o rechazo permanente irreversible.
 *   6. CANCELLED_OBSOLETE: Anulada antes de entrega por obsolescencia o acción de Coordinación.
 */
enum NotificationStatus: string
{
    case PENDING = 'PENDING';
    case PROCESSING = 'PROCESSING';
    case PENDING_RETRY = 'PENDING_RETRY';
    case SENT = 'SENT';
    case FAILED_PERMANENT = 'FAILED_PERMANENT';
    case CANCELLED_OBSOLETE = 'CANCELLED_OBSOLETE';

    /**
     * Etiqueta descriptiva en castellano para la interfaz y reportes.
     */
    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'Pendiente',
            self::PROCESSING => 'En proceso',
            self::PENDING_RETRY => 'Pendiente de reintento',
            self::SENT => 'Enviada',
            self::FAILED_PERMANENT => 'Fallo definitivo',
            self::CANCELLED_OBSOLETE => 'Cancelada por obsolescencia',
        };
    }

    /**
     * Indica si la notificación se encuentra activa en cola de despacho (no finalizada).
     */
    public function isPendingDispatch(): bool
    {
        return $this === self::PENDING || $this === self::PENDING_RETRY || $this === self::PROCESSING;
    }

    /**
     * Indica si la notificación ha alcanzado un estado final.
     * SENT y CANCELLED_OBSOLETE son terminales permanentes.
     * FAILED_PERMANENT es terminal automático, pero admite reintento manual interactivo (RF-06.4).
     */
    public function isTerminal(): bool
    {
        return $this === self::SENT || $this === self::CANCELLED_OBSOLETE;
    }

    /**
     * Indica si el estado permite cancelación manual por Coordinación (RF-06.4).
     */
    public function canBeCancelled(): bool
    {
        return $this === self::PENDING || $this === self::PENDING_RETRY || $this === self::FAILED_PERMANENT;
    }

    /**
     * Indica si el estado permite reintento manual por Coordinación (RF-06.4).
     */
    public function canBeRetried(): bool
    {
        return $this === self::FAILED_PERMANENT;
    }

    /**
     * Matriz estricta de transiciones legales de estado.
     */
    public function canTransitionTo(self $target): bool
    {
        return match ($this) {
            // PENDING puede pasar a PROCESSING (despacho), a CANCELLED_OBSOLETE (obsolescencia/manual) o mantenerse en PENDING si se actualiza payload
            self::PENDING => in_array($target, [self::PROCESSING, self::CANCELLED_OBSOLETE], true),

            // PROCESSING puede pasar a SENT (éxito), PENDING_RETRY (fallo transitorio), FAILED_PERMANENT (5º intento / rechazo) o CANCELLED_OBSOLETE (obsolescencia)
            self::PROCESSING => in_array($target, [self::SENT, self::PENDING_RETRY, self::FAILED_PERMANENT, self::CANCELLED_OBSOLETE], true),

            // PENDING_RETRY puede pasar a PROCESSING (intento de reintento), o CANCELLED_OBSOLETE (obsolescencia/manual)
            self::PENDING_RETRY => in_array($target, [self::PROCESSING, self::CANCELLED_OBSOLETE], true),

            // SENT es terminal absoluto: ninguna transición permitida
            self::SENT => false,

            // FAILED_PERMANENT permite reintento manual (volviendo a PENDING) o cancelación manual (CANCELLED_OBSOLETE)
            self::FAILED_PERMANENT => in_array($target, [self::PENDING, self::CANCELLED_OBSOLETE], true),

            // CANCELLED_OBSOLETE es terminal absoluto: ninguna transición permitida
            self::CANCELLED_OBSOLETE => false,
        };
    }

    /**
     * Devuelve los estados permitidos a los que se puede transicionar.
     *
     * @return array<self>
     */
    public function allowedTransitions(): array
    {
        return array_values(array_filter(
            self::cases(),
            fn(self $target) => $this->canTransitionTo($target)
        ));
    }

    public static function isValid(string $value): bool
    {
        return self::tryFrom(strtoupper(trim($value))) !== null;
    }

    public static function fromString(string $value): self
    {
        $normalized = strtoupper(trim($value));
        $instance = self::tryFrom($normalized);

        if ($instance === null) {
            throw new InvalidArgumentException(sprintf('Estado de notificación no válido: «%s»', $value));
        }

        return $instance;
    }
}
