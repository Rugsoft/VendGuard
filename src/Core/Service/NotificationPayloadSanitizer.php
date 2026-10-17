<?php

declare(strict_types=1);

namespace VendGuard\Core\Service;

use InvalidArgumentException;

/**
 * NotificationPayloadSanitizer — Blindaje y sanitización de datos confidenciales en origen (T-NOTIF-03).
 *
 * Cumple con los requisitos RF-05.6, RNF-04 y la Constitución Art. V.4:
 *
 * 1. Para destinatarios con recipient_role IN ('LOCATION', 'CITIZEN_QR'):
 *    - Prohibido incluir `resolution_diagnosis`, `resolution_action`, `is_internal`, notas internas,
 *      importes o costes de repuestos (`cost_snapshot`), ni el nombre, email o teléfono personal del técnico.
 *    - Si se referencia la intervención técnica, el único identificador permitido es:
 *      "Servicio Técnico Oficial · Operador #" . ($operatorCode ?: sprintf('%02d', $technicianId))
 *
 * 2. Adicionalmente, para destinatarios con recipient_role = 'CITIZEN_QR':
 *    - Prohibido generar notificaciones de tipo `PENDING_INFO_SITE_REQUEST` o `SITE_SANITARY_QUARANTINE_NOTICE`.
 *    - Prohibido incluir `pending_info_reason_category`, `pending_info_reason_text`, `cancellation_reason`,
 *      `description` libre escrita por terceros o `reporter_name`/`reporter_phone`.
 *    - El enlace de seguimiento apunta exclusivamente a la ruta pública acotada de QR: `/?qr={machine_code}`.
 *
 * 3. Para destinatarios con recipient_role = 'LOCATION':
 *    - El enlace de seguimiento apunta al Portal de Sede: `/?site={site_code}&ticket={ticket_code}`.
 *    - En `PENDING_INFO_SITE_REQUEST` sí se incluyen `reason_category_label` y `reason_text` (>= 20 caracteres)
 *      para que el responsable del edificio pueda desbloquear el acceso (Módulo 11 RF-05.1).
 *
 * Dogma Vanilla: PHP 8.2+ puro, sin dependencias externas.
 * Dualismo Lingüístico: código e identificadores en inglés, documentación en castellano.
 */
final class NotificationPayloadSanitizer
{
    /** Roles externos sujetos a blindaje de confidencialidad Art. V.4. */
    public const EXTERNAL_ROLES = ['LOCATION', 'CITIZEN_QR'];

    /** Plantillas terminantemente prohibidas para Ciudadano QR. */
    public const FORBIDDEN_TEMPLATES_FOR_CITIZEN_QR = [
        'PENDING_INFO_SITE_REQUEST',
        'SITE_SANITARY_QUARANTINE_NOTICE',
    ];

    /** Claves prohibidas incondicionalmente para cualquier rol externo (LOCATION y CITIZEN_QR). */
    public const FORBIDDEN_KEYS_FOR_EXTERNAL_ROLES = [
        'resolution_diagnosis',
        'resolution_action',
        'is_internal',
        'internal_notes',
        'technician_notes',
        'cost_snapshot',
        'cost_total',
        'part_costs',
        'replacement_costs',
        'technician_name',
        'technician_email',
        'technician_phone',
        'technician_personal_phone',
    ];

    /** Claves prohibidas adicionalmente para CITIZEN_QR (proyección pública mínima neutra). */
    public const FORBIDDEN_KEYS_FOR_CITIZEN_QR = [
        'pending_info_reason_category',
        'pending_info_reason_text',
        'cancellation_reason',
        'description',
        'incident_description',
        'reporter_name',
        'reporter_phone',
        'site_code',
        'site_name',
        'location_name',
    ];

    /**
     * Sanitiza y estructura el payload crudo antes de serializarlo en notification_outbox.
     *
     * @param string $recipientRole Rol del destinatario ('COORDINATOR', 'TECHNICIAN', 'LOCATION', 'CITIZEN_QR', 'WEBHOOK').
     * @param string $template Nombre de la plantilla canónica de notificación.
     * @param array<string, mixed> $rawPayload Payload de negocio original.
     * @return array<string, mixed> Payload sanitizado y blindado según el rol.
     * @throws InvalidArgumentException Si se intenta generar un payload ilegal (ej. plantilla de acceso de sede a ciudadano QR).
     */
    public static function sanitize(string $recipientRole, string $template, array $rawPayload): array
    {
        $role = strtoupper(trim($recipientRole));
        $template = trim($template);

        // Validación de plantillas prohibidas para Ciudadano QR
        if ($role === 'CITIZEN_QR' && in_array($template, self::FORBIDDEN_TEMPLATES_FOR_CITIZEN_QR, true)) {
            throw new InvalidArgumentException(sprintf(
                'Template "%s" is strictly forbidden for recipient role "%s" (Art. V.4).',
                $template,
                $role
            ));
        }

        $sanitized = $rawPayload;

        // Si es rol interno (COORDINATOR, TECHNICIAN, WEBHOOK corporativo), no se aplican los filtros de ofuscación de Art. V.4
        if (!in_array($role, self::EXTERNAL_ROLES, true)) {
            return $sanitized;
        }

        // 1. Eliminación de campos confidenciales internos para roles externos (Art. V.4)
        foreach (self::FORBIDDEN_KEYS_FOR_EXTERNAL_ROLES as $key) {
            unset($sanitized[$key]);
        }

        // Sanitizar referencias al técnico: transformar a identificador neutro oficial
        if (isset($sanitized['technician_id']) || isset($rawPayload['technician_name'])) {
            $techId = (int)($sanitized['technician_id'] ?? 0);
            $operatorCode = isset($sanitized['operator_code']) && is_string($sanitized['operator_code']) && trim($sanitized['operator_code']) !== ''
                ? trim($sanitized['operator_code'])
                : ($techId > 0 ? sprintf('%02d', $techId) : '01');

            $sanitized['technical_service_label'] = 'Servicio Técnico Oficial · Operador #' . $operatorCode;
            unset($sanitized['technician_id'], $sanitized['operator_code']);
        }

        // 2. Reglas específicas para CITIZEN_QR
        if ($role === 'CITIZEN_QR') {
            foreach (self::FORBIDDEN_KEYS_FOR_CITIZEN_QR as $key) {
                unset($sanitized[$key]);
            }

            // Fijar enlace público acotado
            $machineCode = (string)($sanitized['machine_code'] ?? '');
            $sanitized['tracking_url'] = '/?qr=' . urlencode($machineCode);
            unset($sanitized['site_portal_url'], $sanitized['portal_url']);

            return $sanitized;
        }

        // 3. Reglas específicas para LOCATION
        if ($role === 'LOCATION') {
            $siteCode = (string)($sanitized['site_code'] ?? '');
            $ticketCode = (string)($sanitized['ticket_code'] ?? '');
            $sanitized['tracking_url'] = '/?site=' . urlencode($siteCode) . '&ticket=' . urlencode($ticketCode);

            // En PENDING_INFO_SITE_REQUEST se preserva reason_category_label y reason_text (>= 20 caracteres)
            if ($template === 'PENDING_INFO_SITE_REQUEST') {
                if (isset($sanitized['reason_text']) && is_string($sanitized['reason_text'])) {
                    $sanitized['reason_text'] = trim($sanitized['reason_text']);
                }
            }
        }

        return $sanitized;
    }

    /**
     * Verifica de forma estricta que un payload ya procesado no contenga ninguna clave prohibida para el rol dado.
     * Retorna lista de violaciones detectadas (vacía si es 100% conforme).
     *
     * @param string $recipientRole
     * @param array<string, mixed> $payload
     * @return list<string>
     */
    public static function auditViolations(string $recipientRole, array $payload): array
    {
        $role = strtoupper(trim($recipientRole));
        if (!in_array($role, self::EXTERNAL_ROLES, true)) {
            return [];
        }

        $violations = [];
        foreach (self::FORBIDDEN_KEYS_FOR_EXTERNAL_ROLES as $key) {
            if (array_key_exists($key, $payload)) {
                $violations[] = sprintf('Confidential key "%s" found in external payload for role "%s"', $key, $role);
            }
        }

        if ($role === 'CITIZEN_QR') {
            foreach (self::FORBIDDEN_KEYS_FOR_CITIZEN_QR as $key) {
                if (array_key_exists($key, $payload)) {
                    $violations[] = sprintf('Forbidden key "%s" found in CITIZEN_QR payload', $key);
                }
            }
            if (isset($payload['tracking_url']) && is_string($payload['tracking_url']) && str_contains($payload['tracking_url'], 'site=')) {
                $violations[] = 'Site portal tracking_url leaked in CITIZEN_QR payload';
            }
        }

        return $violations;
    }
}
