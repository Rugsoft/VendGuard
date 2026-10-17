<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Exception;

use DomainException;

/**
 * WebhookConfigurationException
 *
 * Excepción de dominio lanzada cuando la configuración o la prueba del Canal
 * Corporativo de Emergencias no cumple las reglas de seguridad de RF-06.5:
 *
 *   - `WEBHOOK_URL_REQUIRED`: se activa el canal sin URL configurada.
 *   - `INVALID_WEBHOOK_URL_SCHEME`: la URL no comienza por `https://`.
 *   - `FORBIDDEN_WEBHOOK_INTERNAL_HOST`: el host es local, privado o reservado
 *     (bloqueo anti-SSRF).
 *   - `WEBHOOK_URL_NOT_CONFIGURED`: se intenta probar o reintentar un envío
 *     Webhook sin URL vigente que usar.
 *
 * Mapea directamente al código HTTP 422 Unprocessable Content del contrato de
 * la bandeja de salida.
 *
 * Dogma Vanilla: PHP 8.2+ puro, sin dependencias externas.
 * Dualismo Lingüístico: código e identificadores en inglés, documentación en castellano.
 */
class WebhookConfigurationException extends DomainException
{
    public const HTTP_STATUS = 422;
    public const CODE_URL_REQUIRED = 'WEBHOOK_URL_REQUIRED';
    public const CODE_INVALID_SCHEME = 'INVALID_WEBHOOK_URL_SCHEME';
    public const CODE_FORBIDDEN_HOST = 'FORBIDDEN_WEBHOOK_INTERNAL_HOST';
    public const CODE_NOT_CONFIGURED = 'WEBHOOK_URL_NOT_CONFIGURED';

    public function __construct(
        private readonly string $errorCode,
        string $message = ''
    ) {
        parent::__construct(
            $message !== '' ? $message : self::defaultMessage($errorCode),
            self::HTTP_STATUS
        );
    }

    public static function urlRequired(): self
    {
        return new self(
            self::CODE_URL_REQUIRED,
            'Para activar el canal corporativo de emergencias es obligatoria una URL de Webhook.'
        );
    }

    public static function invalidScheme(string $url): self
    {
        return new self(
            self::CODE_INVALID_SCHEME,
            sprintf('La URL del Webhook debe comenzar estrictamente por https:// («%s»).', $url)
        );
    }

    public static function forbiddenHost(string $url): self
    {
        return new self(
            self::CODE_FORBIDDEN_HOST,
            sprintf(
                'El destino del Webhook apunta a un host local, privado o reservado y ha sido rechazado por la política anti-SSRF («%s»).',
                $url
            )
        );
    }

    public static function notConfigured(): self
    {
        return new self(
            self::CODE_NOT_CONFIGURED,
            'El canal corporativo de emergencias no tiene una URL de Webhook vigente configurada.'
        );
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    public function getHttpStatusCode(): int
    {
        return self::HTTP_STATUS;
    }

    private static function defaultMessage(string $errorCode): string
    {
        return match ($errorCode) {
            self::CODE_URL_REQUIRED => 'Para activar el canal corporativo de emergencias es obligatoria una URL de Webhook.',
            self::CODE_INVALID_SCHEME => 'La URL del Webhook debe comenzar estrictamente por https://.',
            self::CODE_FORBIDDEN_HOST => 'El destino del Webhook apunta a un host local, privado o reservado (bloqueo anti-SSRF).',
            default => 'La configuración del canal corporativo de emergencias no es válida.',
        };
    }
}
