<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Exception;

use DomainException;

/**
 * ColdChainRouteWarningConfirmationException
 *
 * El técnico pretende abrir o asumir una avería oportunista mientras tiene en
 * otra sede una parada pendiente **crítica** o con riesgo de cadena de frío
 * (máquina de perecederos o combo con fallo térmico o corte eléctrico, o máquina
 * en cuarentena sanitaria). La salvaguarda del Art. II exige que confirme de
 * forma expresa que decide posponerla antes de dejarle continuar (Módulo 16,
 * RF-03.1 y RF-03.2).
 *
 * Mapea al código HTTP 409 y transporta la lista de paradas postergadas para que
 * la interfaz pinte el modal destacado con datos reales.
 */
class ColdChainRouteWarningConfirmationException extends DomainException
{
    public const HTTP_STATUS = 409;
    public const ERROR_CODE = 'COLD_CHAIN_ROUTE_WARNING_CONFIRMATION_REQUIRED';

    private string $errorCode;
    private int $httpStatusCode;

    /** @var list<array<string, mixed>> */
    private array $pendingCriticalStops;

    /**
     * @param list<array<string, mixed>> $pendingCriticalStops Paradas críticas o sanitarias
     *                                                          pendientes en otras sedes.
     */
    public function __construct(
        array $pendingCriticalStops = [],
        string $message = '',
        string $errorCode = self::ERROR_CODE,
        int $httpStatusCode = self::HTTP_STATUS
    ) {
        if ($message === '') {
            $count = count($pendingCriticalStops);
            $message = $count === 0
                ? 'Hay paradas críticas o con riesgo de cadena de frío pendientes en otra sede. Confirme expresamente que decide posponerlas (Art. II).'
                : sprintf(
                    'Hay %d parada(s) crítica(s) o con riesgo de cadena de frío pendientes en otra sede. Confirme expresamente que decide posponerlas (Art. II).',
                    $count
                );
        }

        parent::__construct($message, $httpStatusCode);
        $this->pendingCriticalStops = $pendingCriticalStops;
        $this->errorCode = $errorCode;
        $this->httpStatusCode = $httpStatusCode;
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    public function getHttpStatusCode(): int
    {
        return $this->httpStatusCode;
    }

    /** @return list<array<string, mixed>> */
    public function getPendingCriticalStops(): array
    {
        return $this->pendingCriticalStops;
    }
}
