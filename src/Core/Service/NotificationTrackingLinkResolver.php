<?php

declare(strict_types=1);

namespace VendGuard\Core\Service;

use VendGuard\Core\Domain\ValueObject\NotificationTemplate;

/**
 * NotificationTrackingLinkResolver — Destino del botón de seguimiento de cada
 * plantilla del Centro de Notificaciones.
 *
 * Es la **única** autoridad de la regla «a dónde lleva el enlace», compartida
 * por los dos consumidores que necesitan conocerla:
 *
 *   1. `NotificationMessageRenderer`, que la escribe en el asunto y en los
 *      cuerpos del correo (RF-05.2, RF-05.3).
 *   2. El panel de supervisión de Coordinación (`CoordinatorNotificationService`),
 *      que la publica en la bandeja para que el operador **revise la URL final
 *      antes de reintentar** un envío fallido (RF-06.3, RF-06.4). Copiar la regla
 *      allí habría creado dos verdades: la que se envía y la que se enseña.
 *
 * **Niveles de acceso.** La audiencia se deriva de la plantilla, no del rol del
 * destinatario, porque la plantilla ya declara a quién se dirige el aviso:
 *
 *   - **LOCATION (Portal de Sede):** `/?site={site_code}&ticket={ticket_code}`.
 *   - **CITIZEN_QR (vista pública acotada):** `/?qr={machine_code}`.
 *   - **TECHNICIAN (ruta del técnico, Módulo 15):** `/?tech=1&ticket={ticket_code}`.
 *     El enlace **no** autentica: al abrirlo se pide la sesión del técnico y sólo
 *     entonces se resuelve la parada. Un enlace reenviado no es una credencial.
 *   - **INTERNAL:** sin enlace; Coordinación entra al sistema autenticada y su
 *     aviso no lleva botón. La desasignación tampoco lo lleva: al técnico saliente
 *     no se le invita a abrir un expediente que ya no es suyo.
 *
 * **Ruta persistida, URL entregada.** El payload de la cola guarda la ruta
 * —estable e independiente del despliegue— y el host público se aplica al
 * resolver (`AppPublicUrl`). Así los envíos que ya están encolados en relativo
 * quedan reparados en su siguiente intento, sin migración de datos.
 *
 * **Degradación declarada.** Sin host, la ruta viaja relativa; ese caso lo
 * delata `usesRelativeTrackingLink()`, de modo que el resumen de la pasada y el
 * worker CLI lo publican en lugar de darlo por bueno (T-NOTIF-17).
 *
 * Dogma Vanilla: PHP 8.2+ puro, sin dependencias externas, sin I/O.
 * Dualismo Lingüístico: código e identificadores en inglés, documentación en castellano.
 */
final class NotificationTrackingLinkResolver
{
    public const AUDIENCE_LOCATION = 'LOCATION';
    public const AUDIENCE_CITIZEN_QR = 'CITIZEN_QR';
    public const AUDIENCE_TECHNICIAN = 'TECHNICIAN';
    public const AUDIENCE_INTERNAL = 'INTERNAL';

    /**
     * Remedio canónico de un enlace relativo: es una variable de entorno, no un
     * cambio de código. Lo publican los consumidores que lo detectan —el resumen
     * de la pasada y el worker CLI— para que el diagnóstico diga exactamente qué
     * declarar en lugar de dejar al operador buscándolo (T-NOTIF-17).
     */
    public const MISSING_PUBLIC_HOST_REMEDY = 'Declare APP_BASE_URL (o VENDGUARD_APP_URL) con el origen público del despliegue para que el botón del correo viaje absoluto.';

    private readonly AppPublicUrl $publicUrl;

    public function __construct(?AppPublicUrl $publicUrl = null)
    {
        $this->publicUrl = $publicUrl ?? new AppPublicUrl();
    }

    /**
     * Resolutor con el host declarado en el entorno del proceso.
     */
    public static function fromEnvironment(): self
    {
        return new self(AppPublicUrl::fromEnvironment());
    }

    /**
     * Nivel de acceso al que se dirige una plantilla del catálogo.
     */
    public function audienceFor(NotificationTemplate $template): string
    {
        return match ($template) {
            NotificationTemplate::SITE_SANITARY_QUARANTINE_NOTICE,
            NotificationTemplate::INCIDENT_OPENED_SITE,
            NotificationTemplate::PENDING_INFO_SITE_REQUEST,
            NotificationTemplate::INCIDENT_RESOLVED_SITE,
            NotificationTemplate::INCIDENT_CANCELLED_SITE => self::AUDIENCE_LOCATION,

            NotificationTemplate::INCIDENT_OPENED_CITIZEN_QR,
            NotificationTemplate::INCIDENT_RESOLVED_CITIZEN_QR,
            NotificationTemplate::INCIDENT_CANCELLED_CITIZEN_QR,
            NotificationTemplate::CITIZEN_QR_OBSERVATION_ACK => self::AUDIENCE_CITIZEN_QR,

            // El técnico entrante recibe su aviso con enlace a la parada concreta.
            // La desasignación se queda en INTERNAL a propósito (RF-TG-06).
            NotificationTemplate::TECHNICIAN_ASSIGNED => self::AUDIENCE_TECHNICIAN,

            default => self::AUDIENCE_INTERNAL,
        };
    }

    /**
     * URL final del botón de seguimiento, ya absoluta cuando hay host público.
     *
     * @param array<string, mixed> $payload Payload neutro del expediente.
     * @return string|null `null` cuando la plantilla no lleva enlace (internos).
     */
    public function resolve(NotificationTemplate $template, array $payload): ?string
    {
        return $this->publicUrl->absolute($this->route($template, $payload));
    }

    /**
     * ¿El enlace de esta notificación viaja como ruta relativa?
     *
     * Convierte una degradación silenciosa en un dato del resumen de la pasada
     * (T-NOTIF-17): un correo externo cuyo botón no pudo absolutizarse lleva una
     * ruta que el cliente de correo resolverá contra su propio dominio, de modo
     * que el destinatario recibe un botón inservible y nadie se entera. El envío
     * no se bloquea —la Sede sigue conociendo su ticket y su detalle por el
     * texto—, pero la pasada lo cuenta para que quien opera el planificador
     * pueda corregir la configuración antes de que se repita.
     *
     * @param array<string, mixed> $payload
     */
    public function usesRelativeTrackingLink(NotificationTemplate $template, array $payload): bool
    {
        if ($this->audienceFor($template) === self::AUDIENCE_INTERNAL) {
            return false;
        }

        $route = $this->route($template, $payload);
        if ($route === null) {
            return false;
        }

        $resolved = $this->publicUrl->absolute($route);
        if ($resolved === null) {
            return false;
        }

        // Relativa es la que no declara esquema: `/?site=…`, `?qr=…`, `/ruta`.
        return preg_match('#^[A-Za-z][A-Za-z0-9+.\-]*:#', $resolved) !== 1;
    }

    /**
     * Ruta del enlace tal y como se persiste en `payload_json`, sin host.
     *
     * Un `tracking_url` explícito en el payload tiene prioridad (es el que fija
     * `NotificationPayloadSanitizer`); si falta, se deriva de los códigos del
     * expediente.
     *
     * @param array<string, mixed> $payload
     */
    public function route(NotificationTemplate $template, array $payload): ?string
    {
        $explicit = $this->text($payload, 'tracking_url');
        if ($explicit !== null) {
            return $explicit;
        }

        $audience = $this->audienceFor($template);
        $machineCode = $this->text($payload, 'machine_code');
        $siteCode = $this->text($payload, 'site_code');
        $ticketCode = $this->text($payload, 'ticket_code');

        if ($audience === self::AUDIENCE_CITIZEN_QR && $machineCode !== null) {
            return '/?qr=' . rawurlencode($machineCode);
        }

        if ($audience === self::AUDIENCE_LOCATION && $siteCode !== null) {
            return '/?site=' . rawurlencode($siteCode)
                . ($ticketCode !== null ? '&ticket=' . rawurlencode($ticketCode) : '');
        }

        if ($audience === self::AUDIENCE_TECHNICIAN) {
            // Sin ticket no se inventa un destino: la vista del técnico se abre en
            // su ruta, que es lo único cierto que se puede prometer.
            return '/?tech=1'
                . ($ticketCode !== null ? '&ticket=' . rawurlencode($ticketCode) : '');
        }

        return null;
    }

    /**
     * Lectura de una clave textual del payload, tolerante a números y huecos.
     *
     * @param array<string, mixed> $payload
     */
    private function text(array $payload, string $key): ?string
    {
        $value = $payload[$key] ?? null;

        if (is_string($value)) {
            $trimmed = trim($value);

            return $trimmed === '' ? null : $trimmed;
        }

        if (is_int($value) || is_float($value)) {
            return (string)$value;
        }

        return null;
    }
}
