<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Exception;

use DomainException;
use VendGuard\Core\Domain\ValueObject\IncidentStatus;

/**
 * AssignmentStateChangedException
 *
 * La incidencia cambió de estado o de titular entre la lectura con la que el
 * coordinador abrió el formulario y el instante en que se consolida la
 * asignación (Módulo 26, EARS 03.4 / R4).
 *
 * No es un estado inválido de origen —eso sigue siendo un 422— sino una colisión
 * concurrente: otro actor resolvió, canceló o reasignó la avería mientras el
 * formulario estaba abierto. Se responde 409 y se adjunta el custodio vigente
 * para que la interfaz refresque la ficha sin perder el texto redactado.
 */
class AssignmentStateChangedException extends DomainException
{
    public const HTTP_STATUS = 409;
    public const ERROR_CODE = 'ASSIGNMENT_STATE_CHANGED';

    /** @var array<string, mixed> */
    private array $details;

    public function __construct(
        public readonly IncidentStatus $expectedStatus,
        public readonly ?int $expectedTechnicianId,
        public readonly IncidentStatus $currentStatus,
        public readonly ?int $currentTechnicianId,
        public readonly ?string $currentTechnicianName = null
    ) {
        $message = sprintf(
            'La incidencia cambió mientras se enviaba la asignación: pasó de %s a %s%s. Refresque la ficha y vuelva a intentarlo.',
            $expectedStatus->value,
            $currentStatus->value,
            $currentTechnicianId !== null
                ? sprintf(' con el técnico ID %d como custodio vigente', $currentTechnicianId)
                : ' sin custodio activo'
        );

        parent::__construct($message, self::HTTP_STATUS);

        $this->details = [
            'expected_status' => $expectedStatus->value,
            'expected_technician_id' => $expectedTechnicianId,
            'current_status' => $currentStatus->value,
            'current_technician_id' => $currentTechnicianId,
            'current_technician_name' => $currentTechnicianName,
        ];
    }

    public function getErrorCode(): string
    {
        return self::ERROR_CODE;
    }

    public function getHttpStatusCode(): int
    {
        return self::HTTP_STATUS;
    }

    /** @return array<string, mixed> */
    public function getDetails(): array
    {
        return $this->details;
    }
}
