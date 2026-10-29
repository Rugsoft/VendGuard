<?php

declare(strict_types=1);

namespace VendGuard\Core\Domain\Exception;

use DomainException;

/**
 * OfflineSyncValidationException
 *
 * Incumplimiento del contrato de sincronización sin conexión del técnico
 * (Módulo 24, contratos §6.2 a §7).
 *
 * El controlador no decide nada: lee `getErrorCode()` y `getHttpStatusCode()`.
 * Un 409 dice que la parada cambió de estado o de titular mientras el técnico
 * estaba sin señal (Art. V.3) o que la cuenta sigue activa; un 422 que la
 * petición existe pero el protocolo sanitario o el contrato de volcado no se
 * cumple; y un 403 que la cuenta fue desactivada por Coordinación (`QA 5`).
 *
 * Los cinco códigos son canónicos y estables: el cliente los compara por cadena
 * para decidir entre reintentar, marcar la acción en conflicto o ejecutar el
 * volcado por baja de cuenta.
 *
 * Dogma Vanilla: PHP 8.2+ puro, sin dependencias.
 * Dualismo Lingüístico: código en inglés, mensajes en castellano.
 */
final class OfflineSyncValidationException extends DomainException
{
    public const OFFLINE_MUTATION_CONFLICT = 'OFFLINE_MUTATION_CONFLICT';
    public const SANITARY_SEAL_CONFIRMATION_REQUIRED = 'SANITARY_SEAL_CONFIRMATION_REQUIRED';
    public const INVALID_OFFLINE_DUMP_PAYLOAD = 'INVALID_OFFLINE_DUMP_PAYLOAD';
    public const ACCOUNT_STILL_ACTIVE_USE_STANDARD_SYNC = 'ACCOUNT_STILL_ACTIVE_USE_STANDARD_SYNC';
    public const ACCOUNT_DEACTIVATED = 'ACCOUNT_DEACTIVATED';

    public const CONFLICT_HTTP_STATUS = 409;
    public const UNPROCESSABLE_HTTP_STATUS = 422;
    public const FORBIDDEN_HTTP_STATUS = 403;

    public function __construct(
        string $message,
        private readonly string $errorCode,
        private readonly int $httpStatusCode
    ) {
        parent::__construct($message, $httpStatusCode);
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    public function getHttpStatusCode(): int
    {
        return $this->httpStatusCode;
    }

    /**
     * La parada cambió de estado o de titular en el servidor mientras el
     * dispositivo estaba sin señal. El cliente marca la transición —y las
     * posteriores de esa parada— como `CONFLICT`, revierte el descuento
     * provisional y habilita el volcado en 1 toque (EARS 05.1, D2, Art. V.3).
     */
    public static function mutationConflict(string $detail = ''): self
    {
        $message = 'La parada cambió de estado o de titularidad mientras estabas sin conexión.';

        return new self(
            $detail !== '' ? $message . ' ' . $detail : $message,
            self::OFFLINE_MUTATION_CONFLICT,
            self::CONFLICT_HTTP_STATUS
        );
    }

    /**
     * Pausa térmica/crítica o inspección preventiva desfavorable sin conexión
     * sobre una máquina `FOOD_PERISHABLE` sin confirmar el precinto físico
     * in situ (EARS 03.4, QA 1, Art. II).
     */
    public static function sanitarySealConfirmationRequired(): self
    {
        return new self(
            'Debes confirmar que has precintado físicamente la máquina / inhabilitado la venta in situ '
            . 'antes de guardar la operación sin conexión.',
            self::SANITARY_SEAL_CONFIRMATION_REQUIRED,
            self::UNPROCESSABLE_HTTP_STATUS
        );
    }

    /**
     * Cuerpo de volcado incompleto o metadatos de sincronización malformados:
     * falta un campo obligatorio, el identificador no respeta el alfabeto o una
     * marca temporal no es ISO-8601 (contrato §6.3 y §7).
     */
    public static function invalidOfflineDumpPayload(string $detail): self
    {
        return new self(
            'La operación sin conexión no cumple el contrato: ' . $detail,
            self::INVALID_OFFLINE_DUMP_PAYLOAD,
            self::UNPROCESSABLE_HTTP_STATUS
        );
    }

    /**
     * Se invocó el volcado de cierre por baja para un técnico cuya cuenta sigue
     * activa: debe usar la sincronización normal (contrato §6.4).
     */
    public static function accountStillActive(): self
    {
        return new self(
            'La cuenta del técnico sigue activa: utiliza la sincronización normal para enviar la cola.',
            self::ACCOUNT_STILL_ACTIVE_USE_STANDARD_SYNC,
            self::CONFLICT_HTTP_STATUS
        );
    }

    /**
     * La cuenta fue desactivada por Coordinación con cola pendiente: el cliente
     * ejecuta el volcado automático de auditoría y libera el dispositivo
     * (EARS 06.4, QA 5).
     */
    public static function accountDeactivated(): self
    {
        return new self(
            'La cuenta del técnico ha sido desactivada. El trabajo pendiente se volcará como registro de auditoría.',
            self::ACCOUNT_DEACTIVATED,
            self::FORBIDDEN_HTTP_STATUS
        );
    }
}
