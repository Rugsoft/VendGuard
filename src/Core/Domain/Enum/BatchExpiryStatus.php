<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Enum;

use DateTimeImmutable;

/**
 * BatchExpiryStatus
 *
 * Semáforo sanitario de caducidad de un lote en la furgoneta del reponedor
 * (Módulo 33, EARS 01.2, D4, Art. II).
 *
 * El cálculo es determinista y no depende de la zona horaria del proceso: entra
 * la fecha de caducidad y la fecha de referencia en `Europe/Madrid`, y sale el
 * mismo estado en cualquier máquina. La ventana de aviso son dos días naturales
 * contados desde la referencia, tal y como la declara el contrato:
 *
 *   - `EXPIRED`       → `expiration_date <= hoy`.
 *   - `EXPIRING_SOON` → `hoy < expiration_date <= hoy + 2 días`.
 *   - `OK`            → sin caducidad, o `expiration_date > hoy + 2 días`.
 *
 * Un lote `EXPIRED` con existencias queda fuera del stock útil que descuenta la
 * propuesta matutina (`D1`) y dispara la alerta de retirada sanitaria; la regla
 * de negocio no se replica en cada servicio, se lee de aquí.
 *
 * Dogma Vanilla: PHP 8.2+ puro, backed enum sin dependencias.
 * Dualismo Lingüístico: código en inglés, etiquetas en castellano.
 */
enum BatchExpiryStatus: string
{
    case EXPIRED = 'EXPIRED';
    case EXPIRING_SOON = 'EXPIRING_SOON';
    case OK = 'OK';

    /** Días naturales que separan «vigente» de «próximo a caducar» (EARS 01.2). */
    public const SOON_WINDOW_DAYS = 2;

    /**
     * Etiqueta legible que viaja como `expiry_status_label` en las respuestas de
     * Coordinación y del móvil del reponedor.
     */
    public function label(): string
    {
        return match ($this) {
            self::EXPIRED => 'Caducado',
            self::EXPIRING_SOON => 'Próximo a caducar',
            self::OK => 'Vigente',
        };
    }

    /**
     * Icono del semáforo sanitario para las tablas de stock por lote.
     */
    public function icon(): string
    {
        return match ($this) {
            self::EXPIRED => '🔴',
            self::EXPIRING_SOON => '🟡',
            self::OK => '🟢',
        };
    }

    public function isExpired(): bool
    {
        return $this === self::EXPIRED;
    }

    public function isExpiringSoon(): bool
    {
        return $this === self::EXPIRING_SOON;
    }

    /**
     * Estado sanitario del lote a partir de su fecha de caducidad (EARS 01.2).
     *
     * Las dos fechas se comparan por día natural: una caducidad a las 23:59 de hoy
     * sigue siendo `EXPIRED`, porque el lote no puede servirse en la ruta de hoy.
     */
    public static function fromExpirationDate(
        ?DateTimeImmutable $expirationDate,
        ?DateTimeImmutable $today = null
    ): self {
        if ($expirationDate === null) {
            return self::OK;
        }

        $reference = ($today ?? new DateTimeImmutable('today'))->setTime(0, 0);
        $expiry = $expirationDate->setTime(0, 0);

        if ($expiry <= $reference) {
            return self::EXPIRED;
        }

        if ($expiry <= $reference->modify('+' . self::SOON_WINDOW_DAYS . ' days')) {
            return self::EXPIRING_SOON;
        }

        return self::OK;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
