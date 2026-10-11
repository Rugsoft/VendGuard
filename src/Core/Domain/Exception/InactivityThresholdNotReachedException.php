<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Exception;

use DomainException;

/**
 * InactivityThresholdNotReachedException
 *
 * Excepción de dominio lanzada cuando se intenta ejecutar la cancelación
 * supervisada por inactividad de sede (Algoritmo 5) sin que el expediente haya
 * superado el umbral de **más de 72 horas hábiles** en `PENDING_INFO`
 * (RF-04.3, Art. V.1).
 *
 * Por qué existe: el umbral es la **precondición** que da nombre al protocolo.
 * El cierre administrativo bloquea la máquina para el ciudadano (RF-04.4),
 * desvincula el reintegro del consumidor (RF-04.5) y se firma en la bitácora
 * inmutable como un silencio de tres jornadas laborales del cliente; ejecutarlo
 * antes de tiempo dejaría el rastro certificando una espera que nunca ocurrió
 * (Art. III). El rechazo no escribe absolutamente nada.
 *
 * Regla de negocio medida: el umbral es **estricto** (`> 72 h`) y se cuenta en
 * horario comercial de la sede (08:00-18:00, lunes a viernes), con la misma
 * función que enciende la insignia de espera prolongada de RF-04.2, de modo que
 * la lectura del triaje y la admisión de la escritura no puedan discrepar.
 *
 * Mapea directamente al código HTTP 422 Unprocessable Entity.
 *
 * Dualismo Lingüístico: código en inglés, documentación en castellano.
 */
class InactivityThresholdNotReachedException extends DomainException
{
    public const ERROR_CODE = 'INACTIVITY_THRESHOLD_NOT_REACHED';
    public const HTTP_STATUS = 422;

    private string $errorCode;
    private int $httpStatusCode;
    private string $ticketCode;
    private int $pausedBusinessSeconds;
    private int $thresholdBusinessHours;

    public function __construct(
        string $ticketCode,
        int $pausedBusinessSeconds,
        int $thresholdBusinessHours,
        string $errorCode = self::ERROR_CODE,
        int $httpStatusCode = self::HTTP_STATUS
    ) {
        parent::__construct(
            sprintf(
                'La cancelación por inactividad exige superar las %d horas hábiles de espera; la incidencia %s acumula %s horas hábiles en pausa (RF-04.3).',
                $thresholdBusinessHours,
                $ticketCode,
                self::formatHours($pausedBusinessSeconds)
            ),
            $httpStatusCode
        );

        $this->errorCode = $errorCode;
        $this->httpStatusCode = $httpStatusCode;
        $this->ticketCode = $ticketCode;
        $this->pausedBusinessSeconds = $pausedBusinessSeconds;
        $this->thresholdBusinessHours = $thresholdBusinessHours;
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    public function getHttpStatusCode(): int
    {
        return $this->httpStatusCode;
    }

    public function getTicketCode(): string
    {
        return $this->ticketCode;
    }

    public function getPausedBusinessSeconds(): int
    {
        return $this->pausedBusinessSeconds;
    }

    public function getThresholdBusinessHours(): int
    {
        return $this->thresholdBusinessHours;
    }

    /**
     * Detalles del rechazo para que el cliente de la API pueda explicar la espera
     * real sin volver a calcularla ni adivinarla.
     *
     * @return array<string, mixed>
     */
    public function getDetails(): array
    {
        return [
            'ticket_code' => $this->ticketCode,
            'paused_business_hours' => round($this->pausedBusinessSeconds / 3600, 2),
            'threshold_business_hours' => $this->thresholdBusinessHours,
        ];
    }

    /**
     * Formatea la espera medida en horas hábiles con dos decimales, sin notación
     * científica y sin redondear a la baja una espera de minutos.
     */
    private static function formatHours(int $pausedBusinessSeconds): string
    {
        return number_format($pausedBusinessSeconds / 3600, 2, '.', '');
    }
}
