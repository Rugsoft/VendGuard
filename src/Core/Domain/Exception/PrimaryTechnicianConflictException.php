<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Exception;

use DomainException;

/**
 * PrimaryTechnicianConflictException
 *
 * El técnico que el coordinador quiere marcar como **Titular Principal** ya lo es
 * de otra zona operativa viva. La regla de titularidad única (RF-02.1) no permite
 * dos titularidades simultáneas: el índice parcial `uq_technician_primary_zone`
 * las rechaza en la base, y este error es la versión que el coordinador puede
 * entender.
 *
 * No bloquea la operación de forma definitiva: la UI debe advertir de que el
 * técnico **perderá la titularidad de su zona anterior** y pedir confirmación
 * (`confirm_primary_override`); al confirmarla, la titularidad previa se degrada
 * con el evento `TECHNICIAN_ZONE_PRIMARY_DEMOTED` (RF-02.2, QA 9). Mapea a
 * HTTP 409 con el código `TECHNICIAN_ALREADY_PRIMARY_IN_OTHER_ZONE`.
 *
 * Dogma Vanilla: PHP 8.2+ puro, sin dependencias.
 * Dualismo Lingüístico: código en inglés, documentación y mensaje en castellano.
 */
class PrimaryTechnicianConflictException extends DomainException
{
    public const HTTP_STATUS = 409;
    public const ERROR_CODE = 'TECHNICIAN_ALREADY_PRIMARY_IN_OTHER_ZONE';
    public const DEFAULT_MESSAGE = 'El técnico ya es titular principal de otra zona activa. Si continúa, perderá esa titularidad y quedará registrada la degradación.';

    public function __construct(
        private readonly ?int $technicianId = null,
        private readonly ?int $currentZoneId = null,
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

    public function getTechnicianId(): ?int
    {
        return $this->technicianId;
    }

    public function getCurrentZoneId(): ?int
    {
        return $this->currentZoneId;
    }

    public function getCurrentZoneName(): ?string
    {
        return $this->currentZoneName;
    }

    /**
     * @return array<string, mixed>
     */
    public function getDetails(): array
    {
        return [
            'technician_id' => $this->technicianId,
            'current_zone_id' => $this->currentZoneId,
            'current_zone_name' => $this->currentZoneName,
        ];
    }
}
