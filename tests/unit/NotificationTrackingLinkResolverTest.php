<?php

declare(strict_types=1);

/**
 * NotificationTrackingLinkResolverTest
 *
 * Suite unitaria de la autoridad única del destino del botón de seguimiento
 * (RF-05.2, RF-05.3, RF-06.3):
 *   1. Nivel de acceso por plantilla: Sede, Ciudadano QR e internos.
 *   2. Ruta derivada de los códigos del expediente y prioridad del
 *      `tracking_url` explícito del payload.
 *   3. URL absoluta con el host público; degradación honesta sin host.
 *   4. Ni la Sede ve el enlace del Ciudadano ni al revés, y los avisos internos
 *      no llevan botón.
 *   5. Detección de la degradación sin host público (T-NOTIF-17): el enlace que
 *      viaja relativo se declara, y el aviso interno o sin códigos no se declara.
 *
 * Dogma Vanilla: PHP 8.2+ puro, sin red, sin base de datos y sin reloj.
 */

require_once __DIR__ . '/../bootstrap.php';

use VendGuard\Core\Domain\ValueObject\NotificationTemplate;
use VendGuard\Core\Service\AppPublicUrl;
use VendGuard\Core\Service\NotificationTrackingLinkResolver;

echo "======================================================================\n";
echo " VendGuard: Suite Unitaria - NotificationTrackingLinkResolverTest\n";
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

$publicUrl = new AppPublicUrl('https://vendguard.onrender.com');
$resolver = new NotificationTrackingLinkResolver($publicUrl);
$envlessResolver = new NotificationTrackingLinkResolver(new AppPublicUrl(null));

$siteyPayload = [
    'ticket_code' => 'INC-2026-000123',
    'site_code' => 'SEDE-BCN-01',
    'machine_code' => 'VEND-0101',
];
$citizenPayload = [
    'ticket_code' => 'INC-2026-000123',
    'machine_code' => 'VEND-0101',
];

echo "--- 1. Nivel de acceso por plantilla ---\n";

$assert(
    '1.1 Los avisos de Sede se dirigen al Portal de Sede',
    $resolver->audienceFor(NotificationTemplate::INCIDENT_OPENED_SITE) === NotificationTrackingLinkResolver::AUDIENCE_LOCATION
    && $resolver->audienceFor(NotificationTemplate::PENDING_INFO_SITE_REQUEST) === NotificationTrackingLinkResolver::AUDIENCE_LOCATION
    && $resolver->audienceFor(NotificationTemplate::INCIDENT_RESOLVED_SITE) === NotificationTrackingLinkResolver::AUDIENCE_LOCATION
    && $resolver->audienceFor(NotificationTemplate::INCIDENT_CANCELLED_SITE) === NotificationTrackingLinkResolver::AUDIENCE_LOCATION
    && $resolver->audienceFor(NotificationTemplate::SITE_SANITARY_QUARANTINE_NOTICE) === NotificationTrackingLinkResolver::AUDIENCE_LOCATION
);
$assert(
    '1.2 Los avisos al Ciudadano QR se dirigen a la vista pública acotada',
    $resolver->audienceFor(NotificationTemplate::INCIDENT_OPENED_CITIZEN_QR) === NotificationTrackingLinkResolver::AUDIENCE_CITIZEN_QR
    && $resolver->audienceFor(NotificationTemplate::INCIDENT_RESOLVED_CITIZEN_QR) === NotificationTrackingLinkResolver::AUDIENCE_CITIZEN_QR
    && $resolver->audienceFor(NotificationTemplate::INCIDENT_CANCELLED_CITIZEN_QR) === NotificationTrackingLinkResolver::AUDIENCE_CITIZEN_QR
    && $resolver->audienceFor(NotificationTemplate::CITIZEN_QR_OBSERVATION_ACK) === NotificationTrackingLinkResolver::AUDIENCE_CITIZEN_QR
);
$assert(
    '1.3 Los avisos internos no tienen nivel de acceso externo',
    $resolver->audienceFor(NotificationTemplate::TECHNICIAN_UNASSIGNED) === NotificationTrackingLinkResolver::AUDIENCE_INTERNAL
    && $resolver->audienceFor(NotificationTemplate::COLD_CHAIN_IMMEDIATE_ALERT) === NotificationTrackingLinkResolver::AUDIENCE_INTERNAL
    && $resolver->audienceFor(NotificationTemplate::SLA_IMMINENCE_2H_UNASSIGNED) === NotificationTrackingLinkResolver::AUDIENCE_INTERNAL
);
$assert(
    '1.4 La asignación al técnico se dirige a su ruta (Módulo 15)',
    $resolver->audienceFor(NotificationTemplate::TECHNICIAN_ASSIGNED) === NotificationTrackingLinkResolver::AUDIENCE_TECHNICIAN
);

echo "\n--- 2. Ruta derivada y prioridad del payload ---\n";

$assert(
    '2.1 La Sede recibe la ruta del portal con su ticket',
    $resolver->route(NotificationTemplate::INCIDENT_OPENED_SITE, $siteyPayload)
        === '/?site=SEDE-BCN-01&ticket=INC-2026-000123'
);
$assert(
    '2.2 El Ciudadano QR recibe la ruta pública de su máquina',
    $resolver->route(NotificationTemplate::INCIDENT_OPENED_CITIZEN_QR, $citizenPayload) === '/?qr=VEND-0101'
);
$assert(
    '2.3 El enlace explícito del payload tiene prioridad sobre la derivación',
    $resolver->route(
        NotificationTemplate::INCIDENT_OPENED_SITE,
        array_merge($siteyPayload, ['tracking_url' => '/?site=OTRA-SEDE&ticket=INC-9'])
    ) === '/?site=OTRA-SEDE&ticket=INC-9'
);
$assert(
    '2.4 Sin códigos no se inventa una ruta',
    $resolver->route(NotificationTemplate::INCIDENT_OPENED_SITE, ['ticket_code' => 'INC-1']) === null
    && $resolver->route(NotificationTemplate::INCIDENT_OPENED_CITIZEN_QR, ['ticket_code' => 'INC-1']) === null
);
$assert(
    '2.5 El aviso interno no lleva ruta alguna aunque tenga los códigos',
    $resolver->route(NotificationTemplate::TECHNICIAN_UNASSIGNED, $siteyPayload) === null
);
$assert(
    '2.5.1 La asignación al técnico apunta a su ruta con el ticket citado',
    $resolver->route(NotificationTemplate::TECHNICIAN_ASSIGNED, $siteyPayload)
        === '/?tech=1&ticket=INC-2026-000123'
);
$assert(
    '2.5.2 Sin ticket, el técnico aterriza en su ruta sin destino inventado',
    $resolver->route(NotificationTemplate::TECHNICIAN_ASSIGNED, []) === '/?tech=1'
);
$assert(
    '2.5.3 La desasignación no invita a abrir un expediente ajeno',
    $resolver->route(NotificationTemplate::TECHNICIAN_UNASSIGNED, $siteyPayload) === null
);
$assert(
    '2.6 Una Sede sin ticket conocido conserva su enlace de portal',
    $resolver->route(NotificationTemplate::INCIDENT_OPENED_SITE, ['site_code' => 'SEDE-BCN-01']) === '/?site=SEDE-BCN-01'
);

echo "\n--- 3. URL absoluta y degradación ---\n";

$assert(
    '3.1 Con host público el enlace de Sede sale absoluto',
    $resolver->resolve(NotificationTemplate::INCIDENT_OPENED_SITE, $siteyPayload)
        === 'https://vendguard.onrender.com/?site=SEDE-BCN-01&ticket=INC-2026-000123'
);
$assert(
    '3.2 Con host público el enlace del Ciudadano sale absoluto',
    $resolver->resolve(NotificationTemplate::INCIDENT_OPENED_CITIZEN_QR, $citizenPayload)
        === 'https://vendguard.onrender.com/?qr=VEND-0101'
);
$assert(
    '3.3 Sin host declarado se entrega la ruta, no un dominio inventado',
    $envlessResolver->resolve(NotificationTemplate::INCIDENT_OPENED_SITE, $siteyPayload)
        === '/?site=SEDE-BCN-01&ticket=INC-2026-000123'
);
$assert(
    '3.4 Un payload ya absoluto no se reescribe con el host',
    $resolver->resolve(
        NotificationTemplate::INCIDENT_OPENED_SITE,
        array_merge($siteyPayload, ['tracking_url' => 'https://otro.example/?site=X&ticket=Y'])
    ) === 'https://otro.example/?site=X&ticket=Y'
);

echo "\n--- 4. Separación de niveles ---\n";

$assert(
    '4.1 La Sede nunca recibe el enlace del Ciudadano QR',
    !str_contains((string)$resolver->resolve(NotificationTemplate::INCIDENT_OPENED_SITE, $siteyPayload), 'qr=')
);
$assert(
    '4.2 El Ciudadano QR nunca recibe el enlace del Portal de Sede',
    !str_contains((string)$resolver->resolve(NotificationTemplate::INCIDENT_OPENED_CITIZEN_QR, $citizenPayload), 'site=')
);
$assert(
    '4.3 El enlace del Ciudadano acota la máquina, sin datos de la sede',
    !str_contains((string)$resolver->resolve(NotificationTemplate::INCIDENT_OPENED_CITIZEN_QR, $citizenPayload), 'SEDE-')
);

echo "\n--- 5. Detección de la degradación sin host público (T-NOTIF-17) ---\n";

$assert(
    '5.1 Sin host público, el aviso de Sede se declara con enlace relativo',
    $envlessResolver->usesRelativeTrackingLink(NotificationTemplate::INCIDENT_OPENED_SITE, $siteyPayload)
);
$assert(
    '5.2 Con host público, el mismo aviso deja de declararse degradado',
    !$resolver->usesRelativeTrackingLink(NotificationTemplate::INCIDENT_OPENED_SITE, $siteyPayload)
);
$assert(
    '5.3 El aviso del Ciudadano QR se declara igual: su botón también es externo',
    $envlessResolver->usesRelativeTrackingLink(NotificationTemplate::INCIDENT_OPENED_CITIZEN_QR, $citizenPayload)
    && !$resolver->usesRelativeTrackingLink(NotificationTemplate::INCIDENT_OPENED_CITIZEN_QR, $citizenPayload)
);
$assert(
    '5.4 Los avisos internos no se declaran: no llevan botón que resolver',
    !$envlessResolver->usesRelativeTrackingLink(NotificationTemplate::TECHNICIAN_UNASSIGNED, $siteyPayload)
    && !$envlessResolver->usesRelativeTrackingLink(NotificationTemplate::COLD_CHAIN_IMMEDIATE_ALERT, $siteyPayload)
);
$assert(
    '5.4.1 El enlace del técnico también se declara cuando no hay host público',
    $envlessResolver->usesRelativeTrackingLink(NotificationTemplate::TECHNICIAN_ASSIGNED, $siteyPayload)
    && !$resolver->usesRelativeTrackingLink(NotificationTemplate::TECHNICIAN_ASSIGNED, $siteyPayload)
);
$assert(
    '5.4.2 Con host público el botón del técnico sale absoluto',
    $resolver->resolve(NotificationTemplate::TECHNICIAN_ASSIGNED, $siteyPayload)
        === 'https://vendguard.onrender.com/?tech=1&ticket=INC-2026-000123'
);
$assert(
    '5.5 Un aviso sin códigos no se declara aunque falte el host',
    !$envlessResolver->usesRelativeTrackingLink(NotificationTemplate::INCIDENT_OPENED_SITE, ['ticket_code' => 'INC-1'])
);
$assert(
    '5.6 Un payload ya absoluto no se declara degradado',
    !$envlessResolver->usesRelativeTrackingLink(
        NotificationTemplate::INCIDENT_OPENED_SITE,
        array_merge($siteyPayload, ['tracking_url' => 'https://otro.example/?site=X&ticket=Y'])
    )
);
$assert(
    '5.7 El remedio publicado nombra las dos variables que el código lee',
    str_contains(NotificationTrackingLinkResolver::MISSING_PUBLIC_HOST_REMEDY, 'APP_BASE_URL')
    && str_contains(NotificationTrackingLinkResolver::MISSING_PUBLIC_HOST_REMEDY, 'VENDGUARD_APP_URL')
);

echo "\n======================================================================\n";
echo " Total Aserciones: {$assertions} | Pasadas: " . ($assertions - $failures) . " | Fallidas: {$failures}\n";

if ($failures === 0) {
    echo " RESULTADO: VERDE. El destino del botón tiene una sola verdad.\n";
    echo "======================================================================\n";
    exit(0);
}

echo " RESULTADO: FALLIDO EN {$failures} ASERCIONES.\n";
echo "======================================================================\n";
exit(1);
