<?php

declare(strict_types=1);

/**
 * NotificationPayloadSanitizerTest
 *
 * Suite de pruebas unitarias para el blindaje de confidencialidad Art. V.4 en origen (T-NOTIF-03, RF-05.6, RNF-04).
 * Dogma Vanilla: Ejecutable nativamente sin librerías externas.
 */

require_once __DIR__ . '/../bootstrap.php';

use VendGuard\Core\Service\NotificationPayloadSanitizer;

echo "======================================================================\n";
echo " VendGuard: Suite de Pruebas Unitarias - NotificationPayloadSanitizerTest\n";
echo "======================================================================\n\n";

$assertions = 0;
$failures = 0;

$assert = function (string $caseTitle, bool $condition, string $message = '') use (&$assertions, &$failures): void {
    $assertions++;
    if ($condition) {
        echo "  [PASS] {$caseTitle}\n";
    } else {
        echo "  [FAIL] {$caseTitle}\n";
        if ($message !== '') {
            echo "         Motivo: {$message}\n";
        }
        $failures++;
    }
};

$rawPayloadComplete = [
    'incident_id' => 142,
    'ticket_code' => 'TICK-2026-00142',
    'machine_code' => 'VEN-BCN-001',
    'site_code' => 'BCN-01',
    'site_name' => 'Campus Poblenou',
    'location_name' => 'Campus Poblenou - Edificio A',
    'reporter_name' => 'Laura Directora',
    'reporter_phone' => '+34600112233',
    'reporter_email' => 'laura@sede.com',
    'description' => 'La máquina expulsó humo y perdió el frío bruscamente',
    'technician_id' => 7,
    'technician_name' => 'Carlos Técnico',
    'technician_email' => 'carlos@vendguard.internal',
    'technician_phone' => '+34699887766',
    'technician_personal_phone' => '+34699887766',
    'resolution_diagnosis' => 'Compresor quemado por sobretensión en relé de arranque',
    'resolution_action' => 'Sustitución de compresor Danfoss y termostato digital',
    'is_internal' => true,
    'internal_notes' => 'El cliente no limpia la rejilla de ventilación, posible negligencia',
    'cost_snapshot' => ['parts_total' => 142.50, 'labor_total' => 80.00],
    'pending_info_reason_category' => 'NEED_ACCESS',
    'pending_info_reason_text' => 'Conserjería cerrada. Se requiere llave de acceso a sala técnica.',
];

// 1. Roles internos (COORDINATOR, TECHNICIAN, WEBHOOK): conservan todos los campos
echo "--- Preservación íntegra para roles internos (COORDINATOR, TECHNICIAN) ---\n";

$coordPayload = NotificationPayloadSanitizer::sanitize('COORDINATOR', 'INCIDENT_OPENED_SITE', $rawPayloadComplete);
$assert('COORDINATOR conserva resolution_diagnosis', isset($coordPayload['resolution_diagnosis']));
$assert('COORDINATOR conserva internal_notes', isset($coordPayload['internal_notes']));
$assert('COORDINATOR conserva technician_name', $coordPayload['technician_name'] === 'Carlos Técnico');
$assert('COORDINATOR auditoría no arroja violaciones', NotificationPayloadSanitizer::auditViolations('COORDINATOR', $coordPayload) === []);

// 2. Rol LOCATION (Sede): exclusión de diagnósticos, costes, notas y datos personales del técnico
echo "--- Blindaje Art. V.4 para rol LOCATION (Sede) ---\n";

$locationPayload = NotificationPayloadSanitizer::sanitize('LOCATION', 'INCIDENT_RESOLVED_SITE', $rawPayloadComplete);
$assert('LOCATION no contiene resolution_diagnosis', !isset($locationPayload['resolution_diagnosis']));
$assert('LOCATION no contiene resolution_action', !isset($locationPayload['resolution_action']));
$assert('LOCATION no contiene is_internal', !isset($locationPayload['is_internal']));
$assert('LOCATION no contiene internal_notes', !isset($locationPayload['internal_notes']));
$assert('LOCATION no contiene cost_snapshot', !isset($locationPayload['cost_snapshot']));
$assert('LOCATION no contiene technician_name', !isset($locationPayload['technician_name']));
$assert('LOCATION no contiene technician_email', !isset($locationPayload['technician_email']));
$assert('LOCATION no contiene technician_phone', !isset($locationPayload['technician_phone']));
$assert(
    'LOCATION reemplaza técnico por etiqueta técnica oficial neutra',
    $locationPayload['technical_service_label'] === 'Servicio Técnico Oficial · Operador #07'
);
$assert(
    'LOCATION genera enlace hacia el Portal de Sede',
    $locationPayload['tracking_url'] === '/?site=BCN-01&ticket=TICK-2026-00142'
);
$assert('LOCATION pasa auditoría de violaciones Art. V.4 a cero', NotificationPayloadSanitizer::auditViolations('LOCATION', $locationPayload) === []);

// 3. Rol CITIZEN_QR: proyección neutra mínima y bloqueo de campos libres de terceros
echo "--- Blindaje Art. V.4 para rol CITIZEN_QR (Consumidor) ---\n";

$qrPayload = NotificationPayloadSanitizer::sanitize('CITIZEN_QR', 'INCIDENT_OPENED_CITIZEN_QR', $rawPayloadComplete);
$assert('CITIZEN_QR no contiene datos internos de técnicos o costes', !isset($qrPayload['cost_snapshot']) && !isset($qrPayload['resolution_diagnosis']));
$assert('CITIZEN_QR no contiene reporter_name', !isset($qrPayload['reporter_name']));
$assert('CITIZEN_QR no contiene reporter_phone', !isset($qrPayload['reporter_phone']));
$assert('CITIZEN_QR no contiene description libre de terceros', !isset($qrPayload['description']));
$assert('CITIZEN_QR no contiene site_name ni location_name', !isset($qrPayload['site_name']) && !isset($qrPayload['location_name']));
$assert('CITIZEN_QR no contiene pending_info_reason_text', !isset($qrPayload['pending_info_reason_text']));
$assert(
    'CITIZEN_QR genera tracking_url apuntando exclusivamente a la vista acotada QR',
    $qrPayload['tracking_url'] === '/?qr=VEN-BCN-001'
);
$assert('CITIZEN_QR pasa auditoría de violaciones Art. V.4 a cero', NotificationPayloadSanitizer::auditViolations('CITIZEN_QR', $qrPayload) === []);

// 4. Prohibición estricta de plantillas internas hacia Ciudadano QR
echo "--- Bloqueo de plantillas ilegales hacia CITIZEN_QR ---\n";

$threwPauseForCitizen = false;
try {
    NotificationPayloadSanitizer::sanitize('CITIZEN_QR', 'PENDING_INFO_SITE_REQUEST', $rawPayloadComplete);
} catch (InvalidArgumentException) {
    $threwPauseForCitizen = true;
}
$assert('Bloqueo de PENDING_INFO_SITE_REQUEST para CITIZEN_QR', $threwPauseForCitizen);

$threwQuarantineForCitizen = false;
try {
    NotificationPayloadSanitizer::sanitize('CITIZEN_QR', 'SITE_SANITARY_QUARANTINE_NOTICE', $rawPayloadComplete);
} catch (InvalidArgumentException) {
    $threwQuarantineForCitizen = true;
}
$assert('Bloqueo de SITE_SANITARY_QUARANTINE_NOTICE para CITIZEN_QR', $threwQuarantineForCitizen);

echo "\nResultado: {$assertions} evaluadas, {$failures} fallidas.\n";
if ($failures > 0) {
    exit(1);
}
