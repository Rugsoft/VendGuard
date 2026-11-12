<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Exception;

use DomainException;

/**
 * PrimaryRestockerConflictException
 *
 * El reponedor que el coordinador quiere marcar como **Titular** de la zona ya lo
 * es de otra zona viva. La regla de titularidad única del cuadrante de carga
 * (RF-02.1, `D3`) no admite dos titularidades simultáneas: el índice parcial
 * `uq_restocker_primary_zone` las rechaza en la base y esta excepción es la
 * versión que el coordinador puede entender.
 *
 * No cierra la puerta: la UI advierte de que el reponedor **perderá la titularidad
 * de su zona anterior** y pide confirmación (`confirm_primary_transfer`). Al
 * confirmarla, la titularidad previa se degrada a `BACKUP` en la misma transacción
 * y se audita con `RESTOCKER_ZONE_PRIMARY_DEMOTED`. Mapea a HTTP 409 con el código
 * `PRIMARY_ZONE_ALREADY_ASSIGNED` del contrato del Módulo 31.
 *
 * Dogma Vanilla: PHP 8.2+ puro, sin dependencias.
 * Dualismo Lingüístico: código en inglés, documentación y mensaje en castellano.
 */
class PrimaryRestockerConflictException extends DomainException
{
    public const HTTP_STATUS = 409;
    public const ERROR_CODE = 'PRIMARY_ZONE_ALREADY_ASSIGNED';
    public const DEFAULT_MESSAGE = 'El reponedor ya es titular de otra zona operativa. Si continúa, perderá esa titularidad y quedará registrada la degradación.';

    public function __construct(
        private readonly ?int $restockerId = null,
        private readonly ?int $currentZoneId = null,
        private readonly ?string $currentZoneCode = null,
        private readonly ?string $currentZoneName = null,
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

    public function getRestockerId(): ?int
    {
        return $this->restockerId;
    }

    public function getCurrentZoneId(): ?int
    {
        return $this->currentZoneId;
    }

    public function getCurrentZoneCode(): ?string
    {
        return $this->currentZoneCode;
    }

    public function getCurrentZoneName(): ?string
    {
        return $this->currentZoneName;
    }

    /**
     * Bloque `current_primary_zone` del contrato de error (§4.1).
     *
     * @return array<string, mixed>
     */
    public function getDetails(): array
    {
        return [
            'restocker_id' => $this->restockerId,
            'current_primary_zone' => $this->currentZoneId !== null
                ? [
                    'id' => $this->currentZoneId,
                    'code' => $this->currentZoneCode,
                    'name' => $this->currentZoneName,
                ]
                : null,
        ];
    }
}
