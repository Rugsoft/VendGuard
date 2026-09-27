<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Exception;

use DomainException;

/**
 * CannotIssueNonConformCertificateException
 * 
 * Excepción de Dominio lanzada cuando se intenta generar un Certificado Sanitario Oficial
 * de Aptitud para una máquina en cuarentena sanitaria o con inspección vencida (EARS 7.3).
 * 
 * Mapea directamente al código HTTP 400 Bad Request.
 */
class CannotIssueNonConformCertificateException extends DomainException
{
    public const HTTP_STATUS = 400;
    public const ERROR_CODE = 'CANNOT_GENERATE_CERTIFICATE_NOT_CONFORM';

    private string $errorCode;
    private int $httpStatusCode;
    private ?string $machineCode;
    private ?string $sanitaryStatus;

    public function __construct(
        string $message = 'No se puede emitir el Certificado Sanitario de Aptitud para una máquina en estado de cuarentena sanitaria o con inspección vencida.',
        ?string $machineCode = null,
        ?string $sanitaryStatus = null,
        string $errorCode = self::ERROR_CODE,
        int $httpStatusCode = self::HTTP_STATUS
    ) {
        parent::__construct($message, $httpStatusCode);
        $this->machineCode = $machineCode;
        $this->sanitaryStatus = $sanitaryStatus;
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

    public function getMachineCode(): ?string
    {
        return $this->machineCode;
    }

    public function getSanitaryStatus(): ?string
    {
        return $this->sanitaryStatus;
    }

    /**
     * @return array<string, mixed>
     */
    public function getDetails(): array
    {
        return [
            'machine_code' => $this->machineCode,
            'sanitary_status' => $this->sanitaryStatus,
        ];
    }
}
