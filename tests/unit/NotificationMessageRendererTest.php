<?php

declare(strict_types=1);

/**
 * NotificationMessageRendererTest
 *
 * Suite unitaria del renderizador del Centro de Notificaciones (T-NOTIF-05):
 *   1. Las 22 plantillas del catálogo generan asunto (≤ 255), texto y HTML.
 *   2. El aviso destacado de RF-03.2 aparece en asunto y encabezado cuando no
 *      hay técnico de guardia ni técnico asignado, y sólo entonces.
 *   3. Blindaje Art. V.4: la Sede nunca recibe diagnósticos, acciones, costes,
 *      notas internas ni nombres/teléfonos de técnicos; el Ciudadano QR tampoco
 *      recibe sitios, descripciones, motivos de pausa ni textos de terceros.
 *   4. Enlaces por nivel de acceso (Portal de Sede vs vista pública QR).
 *   5. HTML escapado y con los tokens visuales del sistema (azul #2560ff).
 *
 * Dogma Vanilla: PHP 8.2+ puro, sin red, sin base de datos y sin reloj.
 */

require_once __DIR__ . '/../bootstrap.php';

use VendGuard\Core\Domain\ValueObject\NotificationTemplate;
use VendGuard\Core\Service\AppPublicUrl;
use VendGuard\Infrastructure\Notification\Template\NotificationMessageRenderer;
use VendGuard\Infrastructure\Notification\Template\RenderedNotificationMessage;

echo "======================================================================\n";
echo " VendGuard: Suite Unitaria - NotificationMessageRendererTest (T-NOTIF-05)\n";
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

$assertThrows = function (string $caseTitle, callable $operation) use ($assert): void {
    try {
        $operation();
        $assert($caseTitle, false, 'No se lanzó la excepción esperada.');
    } catch (InvalidArgumentException) {
        $assert($caseTitle, true);
    } catch (Throwable $exception) {
        $assert($caseTitle, false, 'Excepción inesperada: ' . $exception::class);
    }
};

$renderer = new NotificationMessageRenderer();

$payload = [
    'ticket_code' => 'INC-2026-000123',
    'machine_code' => 'M-0041',
    'machine_label' => 'M-0041 · Snacks',
    'site_code' => 'SEDE-CENTRAL',
    'site_name' => 'Edificio Central <Sede> & Cía',
    'address' => 'Calle Mayor 1',
    'floor_area' => 'Planta 2 · Ala Norte',
    'category_label' => 'Temperatura / Pérdida de frío',
    'urgency_label' => 'Crítica',
    'description' => 'No enfría desde anoche.',
    'opened_at' => '2026-10-17 09:42:00',
    'event_at' => '2026-10-17 09:42:00',
    'resolved_at' => '2026-10-17 12:05:00',
    'reopened_at' => '2026-10-18 08:00:00',
    'reason_category_label' => 'Edificio cerrado',
    'reason_text' => 'El conserje no se encontraba en recepción a la hora acordada.',
    'site_reply_text' => 'Abriremos a las 8:00; pregunte en conserjería.',
    'cancellation_reason_label' => 'Inactividad tras 72 horas hábiles',
    'blocked_after_72h' => true,
    'observation_text' => 'La máquina sigue sin luz.',
    'reopened_by_label' => 'Sede',
    'technician_label' => 'Servicio Técnico Oficial · Operador #07',
    'on_call_technician_count' => 0,
    'elapsed_hours' => 2.5,
    'chronic_reopen_count' => 3,
    'contention_passes' => 3,
    'deferred_count' => 7,
    'episode_key' => 'sweep-2026-10-17',
    // Claves prohibidas para roles externos: el renderizador jamás debe leerlas.
    'resolution_diagnosis' => 'SECRETO_DIAGNOSTICO',
    'resolution_action' => 'SECRETO_ACCION',
    'cost_snapshot' => 99.95,
    'technician_name' => 'SECRETO_TECNICO',
    'technician_phone' => '600999888',
    'technician_email' => 'secreto.tecnico@example.com',
    'is_internal' => true,
    'internal_notes' => 'SECRETO_NOTA_INTERNA',
    'reporter_name' => 'SECRETO_INFORMADOR',
    'reporter_phone' => '600111222',
];

$siteTemplates = [
    NotificationTemplate::SITE_SANITARY_QUARANTINE_NOTICE,
    NotificationTemplate::INCIDENT_OPENED_SITE,
    NotificationTemplate::PENDING_INFO_SITE_REQUEST,
    NotificationTemplate::INCIDENT_RESOLVED_SITE,
    NotificationTemplate::INCIDENT_CANCELLED_SITE,
];

$citizenTemplates = [
    NotificationTemplate::INCIDENT_OPENED_CITIZEN_QR,
    NotificationTemplate::INCIDENT_RESOLVED_CITIZEN_QR,
    NotificationTemplate::INCIDENT_CANCELLED_CITIZEN_QR,
    NotificationTemplate::CITIZEN_QR_OBSERVATION_ACK,
];

$secretMarkers = [
    'SECRETO_DIAGNOSTICO',
    'SECRETO_ACCION',
    'SECRETO_TECNICO',
    'SECRETO_NOTA_INTERNA',
    'SECRETO_INFORMADOR',
    '600999888',
    '600111222',
    'secreto.tecnico@example.com',
    '99.95',
];

// ---------------------------------------------------------------------
// 1. Cobertura de las 22 plantillas
// ---------------------------------------------------------------------
echo "--- 1. Las 22 plantillas del catálogo ---\n";

$assert('El catálogo cerrado declara 22 plantillas', count(NotificationTemplate::cases()) === 22);

$subjects = [];
$allRendered = [];

foreach (NotificationTemplate::cases() as $template) {
    $rendered = $renderer->render($template, $payload);
    $allRendered[$template->value] = $rendered;

    $subjects[] = $rendered->subject;

    $assert(
        $template->value . ' · asunto no vacío y ≤ 255 caracteres',
        trim($rendered->subject) !== '' && mb_strlen($rendered->subject) <= 255,
        'longitud: ' . mb_strlen($rendered->subject)
    );
    $assert(
        $template->value . ' · cuerpo de texto con contenido y pie institucional',
        strlen($rendered->textBody) > 200 && str_contains($rendered->textBody, 'VendGuard · Centro de Operaciones')
    );
    $assert(
        $template->value . ' · cuerpo HTML con contenido y diseño',
        str_contains($rendered->htmlBody, '<!DOCTYPE html>')
        && str_contains($rendered->htmlBody, '#2560ff')
        && str_contains($rendered->htmlBody, '#f9fafb')
    );
}

$assert('Los 22 asuntos son distintos entre sí', count(array_unique($subjects)) === 22);
$assert('El renderizador acepta el nombre de plantilla como cadena', $renderer->render('INCIDENT_OPENED_SITE', $payload)->template === NotificationTemplate::INCIDENT_OPENED_SITE);

$handoverMessage = $allRendered['TELEGRAM_INCIDENT_ASSIGNMENT'];
$assert(
    'TELEGRAM_INCIDENT_ASSIGNMENT identifica el relevo y enlaza a la ruta del técnico',
    str_contains($handoverMessage->subject, 'relevo')
    && str_contains($handoverMessage->textBody, 'relevo')
    && str_contains((string)$handoverMessage->ctaUrl, 'tech=1')
);

$assertThrows('Rechaza una plantilla fuera del catálogo', static fn () => $renderer->render('NOT_A_TEMPLATE', $payload));

$renderedMessage = $allRendered['INCIDENT_OPENED_SITE'];
$assert('El asunto incluye el código de ticket', str_contains($renderedMessage->subject, 'INC-2026-000123'));
$assert(
    'La fecha se formatea en la zona operativa (d/m/Y H:i)',
    str_contains($renderedMessage->textBody, '17/10/2026 09:42'),
    $renderedMessage->textBody
);
$assert('textPreview colapsa el cuerpo a una línea', !str_contains($renderedMessage->textPreview(80), "\n"));

// ---------------------------------------------------------------------
// 2. Aviso destacado de RF-03.2
// ---------------------------------------------------------------------
echo "--- 2. Aviso destacado sin técnico de guardia (RF-03.2) ---\n";

$coldChainPayload = $payload;
unset($coldChainPayload['technician_label']);

$coldChain = $renderer->render(NotificationTemplate::COLD_CHAIN_IMMEDIATE_ALERT, $coldChainPayload);

$assert(
    'El asunto contiene el literal completo de RF-03.2',
    str_contains($coldChain->subject, NotificationMessageRenderer::NO_ON_CALL_TECHNICIAN_WARNING),
    $coldChain->subject
);
$assert(
    'El encabezado del cuerpo de texto contiene el aviso',
    str_contains($coldChain->textBody, NotificationMessageRenderer::NO_ON_CALL_TECHNICIAN_WARNING)
);
$assert(
    'El encabezado del cuerpo HTML contiene el aviso',
    str_contains($coldChain->htmlBody, htmlspecialchars(NotificationMessageRenderer::NO_ON_CALL_TECHNICIAN_WARNING, ENT_QUOTES, 'UTF-8'))
);
$assert('El asunto sigue dentro del límite de 255 caracteres', mb_strlen($coldChain->subject) <= 255);

$withOnCall = $renderer->render(
    NotificationTemplate::COLD_CHAIN_IMMEDIATE_ALERT,
    array_merge($coldChainPayload, ['on_call_technician_count' => 2])
);
$assert(
    'Con técnicos de guardia no se emite el aviso',
    !str_contains($withOnCall->subject, 'SIN TÉCNICO DE GUARDIA')
    && !str_contains($withOnCall->textBody, 'SIN TÉCNICO DE GUARDIA')
);

$withAssigned = $renderer->render(
    NotificationTemplate::COLD_CHAIN_IMMEDIATE_ALERT,
    array_merge($coldChainPayload, ['technician_label' => 'Servicio Técnico Oficial · Operador #07'])
);
$assert(
    'Con técnico asignado no se emite el aviso',
    !str_contains($withAssigned->subject, 'SIN TÉCNICO DE GUARDIA')
);

$explicitFlag = $renderer->render(
    NotificationTemplate::COLD_CHAIN_IMMEDIATE_ALERT,
    array_merge($payload, ['no_on_call_technician' => true])
);
$assert(
    'El flag explícito no_on_call_technician también dispara el aviso',
    str_contains($explicitFlag->subject, 'SIN TÉCNICO DE GUARDIA')
);

$quarantine = $allRendered['SANITARY_QUARANTINE_TRIGGERED'];
$assert(
    'Sólo la alerta de frío usa el literal de guardia',
    !str_contains($quarantine->subject, 'SIN TÉCNICO DE GUARDIA')
);

// ---------------------------------------------------------------------
// 3. Blindaje Art. V.4
// ---------------------------------------------------------------------
echo "--- 3. Blindaje de privacidad (Art. V.4) ---\n";

foreach ($siteTemplates as $template) {
    $rendered = $allRendered[$template->value];
    $haystack = $rendered->subject . "\n" . $rendered->textBody . "\n" . $rendered->htmlBody;

    $leaked = array_values(array_filter(
        $secretMarkers,
        static fn (string $marker): bool => str_contains($haystack, $marker)
    ));

    $assert(
        $template->value . ' · la Sede no recibe diagnósticos, costes ni identidades internas',
        $leaked === [],
        'fugas: ' . implode(', ', $leaked)
    );
}

foreach ($citizenTemplates as $template) {
    $rendered = $allRendered[$template->value];
    $haystack = $rendered->subject . "\n" . $rendered->textBody . "\n" . $rendered->htmlBody;

    $leaked = array_values(array_filter(
        array_merge($secretMarkers, [
            'Edificio Central',
            'No enfría desde anoche.',
            'Edificio cerrado',
            'El conserje no se encontraba',
            'Inactividad tras 72 horas',
            'Servicio Técnico Oficial',
        ]),
        static fn (string $marker): bool => str_contains($haystack, $marker)
    ));

    $assert(
        $template->value . ' · el Ciudadano QR sólo recibe datos públicos neutros',
        $leaked === [],
        'fugas: ' . implode(', ', $leaked)
    );
}

$siteOpened = $allRendered['INCIDENT_OPENED_SITE'];
$assert('La Sede sí recibe la descripción reportada en la apertura', str_contains($siteOpened->textBody, 'No enfría desde anoche.'));
$assert(
    'La Sede recibe el alias neutro del servicio técnico, nunca su nombre',
    str_contains($allRendered['INCIDENT_RESOLVED_SITE']->textBody, 'Operador #07')
);

$pendingInfo = $allRendered['PENDING_INFO_SITE_REQUEST'];
$assert('La solicitud de pausa incluye la causa tipificada', str_contains($pendingInfo->textBody, 'Edificio cerrado'));
$assert('La solicitud de pausa incluye el motivo explicativo', str_contains($pendingInfo->textBody, 'El conserje no se encontraba'));
$assert(
    'La solicitud de pausa jamás se dirige al Ciudadano QR',
    !in_array(NotificationTemplate::PENDING_INFO_SITE_REQUEST, $citizenTemplates, true)
);

$resolvedSite = $allRendered['INCIDENT_RESOLVED_SITE'];
$resolvedCitizen = $allRendered['INCIDENT_RESOLVED_CITIZEN_QR'];
$assert('La resolución a Sede recuerda la garantía de 48 horas', str_contains($resolvedSite->textBody, '48 horas'));
$assert('La resolución al Ciudadano recuerda la garantía de 48 horas', str_contains($resolvedCitizen->textBody, '48 horas'));

$cancelledSite = $allRendered['INCIDENT_CANCELLED_SITE'];
$assert('La cancelación con bloqueo tras 72 h destaca la máquina fuera de servicio', str_contains($cancelledSite->textBody, 'FUERA DE SERVICIO'));
$assert('La cancelación de la Sede conserva el motivo del cierre', str_contains($cancelledSite->textBody, 'Inactividad tras 72 horas'));

$cancelledWithoutBlock = $renderer->render(
    NotificationTemplate::INCIDENT_CANCELLED_SITE,
    array_merge($payload, ['blocked_after_72h' => false])
);
$assert('Sin bloqueo tras 72 h no se añade la advertencia', !str_contains($cancelledWithoutBlock->textBody, 'FUERA DE SERVICIO'));

$citizenCancelled = $allRendered['INCIDENT_CANCELLED_CITIZEN_QR'];
$assert('La cancelación al Ciudadano QR no expone el motivo interno', !str_contains($citizenCancelled->textBody, 'Inactividad'));
$assert('El acuse al segundo ciudadano no altera al informador original', str_contains($allRendered['CITIZEN_QR_OBSERVATION_ACK']->textBody, 'sin modificar los datos del informador original'));

// ---------------------------------------------------------------------
// 4. Enlaces por nivel de acceso
// ---------------------------------------------------------------------
echo "--- 4. Enlaces por nivel de acceso ---\n";

$assert(
    'La Sede recibe el enlace al Portal de Sede con ticket',
    str_contains($siteOpened->textBody, '/?site=SEDE-CENTRAL&ticket=INC-2026-000123')
);
$assert(
    'El Ciudadano QR recibe el enlace público acotado a la máquina',
    str_contains($allRendered['INCIDENT_OPENED_CITIZEN_QR']->textBody, '/?qr=M-0041')
);
$assert(
    'El Ciudadano QR nunca recibe el enlace del Portal de Sede',
    !str_contains($allRendered['INCIDENT_OPENED_CITIZEN_QR']->textBody, 'site=')
);

$explicitTracking = $renderer->render(
    NotificationTemplate::INCIDENT_OPENED_SITE,
    array_merge($payload, ['tracking_url' => 'https://vendguard.example.com/?site=X&ticket=Y'])
);
$assert(
    'El tracking_url explícito del payload tiene prioridad',
    str_contains($explicitTracking->textBody, 'https://vendguard.example.com/?site=X&ticket=Y')
);

// ---------------------------------------------------------------------
// 4.1 Host público canónico: el correo nunca lleva rutas relativas
// ---------------------------------------------------------------------
echo "--- 4.1 Host público canónico de los enlaces del correo ---\n";

// Un cliente de correo resuelve `/?qr=…` contra su propio dominio: el botón
// aterrizaba en la web del proveedor. Con el host declarado el enlace viaja
// absoluto y el destinatario llega al expediente.
$publicRenderer = new NotificationMessageRenderer(new AppPublicUrl('https://vendguard.onrender.com'));

$absoluteSite = $publicRenderer->render(NotificationTemplate::INCIDENT_OPENED_SITE, $payload);
$assert(
    'El botón de la Sede apunta al host público declarado',
    str_contains($absoluteSite->textBody, 'Abrir el Portal de Sede:')
    && str_contains($absoluteSite->textBody, 'https://vendguard.onrender.com/?site=SEDE-CENTRAL&ticket=INC-2026-000123')
);
$assert(
    'El href del HTML es absoluto y escapa los separadores de la consulta',
    str_contains(
        $absoluteSite->htmlBody,
        'href="https://vendguard.onrender.com/?site=SEDE-CENTRAL&amp;ticket=INC-2026-000123"'
    )
);

$absoluteCitizen = $publicRenderer->render(NotificationTemplate::INCIDENT_OPENED_CITIZEN_QR, $payload);
$assert(
    'El botón del Ciudadano QR también sale absoluto',
    str_contains($absoluteCitizen->textBody, 'https://vendguard.onrender.com/?qr=M-0041')
);

$queuedRelative = $publicRenderer->render(
    NotificationTemplate::INCIDENT_OPENED_SITE,
    array_merge($payload, ['tracking_url' => '/?site=SEDE-CENTRAL&ticket=INC-2026-000123'])
);
$assert(
    'La ruta relativa ya persistida en la cola se absolutiza al renderizar',
    str_contains($queuedRelative->textBody, 'https://vendguard.onrender.com/?site=SEDE-CENTRAL&ticket=INC-2026-000123')
);

$explicitAbsolute = $publicRenderer->render(
    NotificationTemplate::INCIDENT_OPENED_SITE,
    array_merge($payload, ['tracking_url' => 'https://vendguard.example.com/?site=X&ticket=Y'])
);
$assert(
    'Un enlace absoluto del payload no se reescribe con el host declarado',
    str_contains($explicitAbsolute->textBody, 'https://vendguard.example.com/?site=X&ticket=Y')
);

$withoutCodes = $renderer->render(
    NotificationTemplate::INCIDENT_OPENED_SITE,
    ['ticket_code' => 'INC-9', 'description' => 'Sin sede ni códigos']
);
$assert(
    'Sin enlace disponible el cuerpo no inventa una URL',
    !str_contains($withoutCodes->textBody, 'Abrir el Portal de Sede:')
);

// ---------------------------------------------------------------------
// 5. HTML escapado y tokens visuales
// ---------------------------------------------------------------------
echo "--- 5. HTML escapado y sistema de diseño ---\n";

$escapedSite = $renderer->render(NotificationTemplate::INCIDENT_OPENED_SITE, $payload);
$assert(
    'El HTML escapa los datos del expediente',
    str_contains($escapedSite->htmlBody, 'Edificio Central &lt;Sede&gt; &amp; Cía')
    && !str_contains($escapedSite->htmlBody, 'Edificio Central <Sede>')
);
$assert(
    'El HTML usa el radio conservador de 8px y el borde hairline',
    str_contains($escapedSite->htmlBody, 'border-radius:8px')
    && str_contains($escapedSite->htmlBody, '#c8cfda')
);
$assert(
    'La alerta crítica se pinta con el rojo del sistema',
    str_contains($coldChain->htmlBody, '#dc2626')
);
$assert(
    'La advertencia de pausa se pinta con el ámbar del sistema',
    str_contains($pendingInfo->htmlBody, '#f8b60f')
);
$assert(
    'El panel del mensaje no incluye scripts ni formularios',
    !str_contains($escapedSite->htmlBody, '<script')
    && !str_contains($escapedSite->htmlBody, '<form')
);

// ---------------------------------------------------------------------
// 6. Contrato del mensaje renderizado
// ---------------------------------------------------------------------
echo "--- 6. Contrato de RenderedNotificationMessage ---\n";

$assertThrows(
    'Rechaza un asunto vacío',
    static fn () => new RenderedNotificationMessage(NotificationTemplate::INCIDENT_OPENED_SITE, '  ', 'texto', '<p>html</p>')
);
$assertThrows(
    'Rechaza un asunto superior a 255 caracteres',
    static fn () => new RenderedNotificationMessage(NotificationTemplate::INCIDENT_OPENED_SITE, str_repeat('a', 256), 'texto', '<p>html</p>')
);
$assertThrows(
    'Rechaza un cuerpo vacío',
    static fn () => new RenderedNotificationMessage(NotificationTemplate::INCIDENT_OPENED_SITE, 'Asunto', '', '<p>html</p>')
);

$manual = new RenderedNotificationMessage(
    NotificationTemplate::INCIDENT_OPENED_SITE,
    'Asunto',
    "Primera línea\nSegunda línea",
    '<p>HTML</p>'
);
$assert('textPreview respeta la longitud solicitada', mb_strlen($manual->textPreview(10)) === 10);
$assert('textPreview no conserva saltos de línea', !str_contains($manual->textPreview(80), "\n"));

echo "\n======================================================================\n";
echo " Total Aserciones: {$assertions} | Fallos: {$failures}\n";
echo "======================================================================\n";

if ($failures > 0) {
    exit(1);
}
