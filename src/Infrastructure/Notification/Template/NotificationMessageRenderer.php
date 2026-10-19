<?php

declare(strict_types=1);

namespace VendGuard\Infrastructure\Notification\Template;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use VendGuard\Core\Domain\ValueObject\NotificationTemplate;
use VendGuard\Core\Service\AppPublicUrl;
use VendGuard\Core\Service\NotificationTrackingLinkResolver;

/**
 * NotificationMessageRenderer — Redacción de asuntos y cuerpos en castellano para las 21 plantillas (T-NOTIF-05).
 *
 * Cumple con RF-01.5, RF-03.2, RF-05.1 a RF-05.9, RF-06.5.2 y la Constitución
 * Art. V.4:
 *
 * 1. **Las 21 plantillas del catálogo** (`NotificationTemplate`) generan un
 *    asunto (≤ 255 caracteres, límite de `notification_outbox.subject`), un
 *    cuerpo en texto plano y un cuerpo HTML responsivo. Ambos cuerpos se
 *    construyen desde el MISMO borrador estructurado, de modo que no pueden
 *    divergir en contenido.
 * 2. **Aviso destacado de RF-03.2:** cuando una `COLD_CHAIN_IMMEDIATE_ALERT`
 *    llega sin técnico asignado y con `on_call_technician_count = 0` (o
 *    `no_on_call_technician = true`), el asunto y el encabezado del mensaje
 *    incluyen el literal `"⚠️ ALERTA SIN TÉCNICO DE GUARDIA DESIGNADO:
 *    Requiere asignación manual inmediata"`.
 * 3. **Blindaje en profundidad (Art. V.4):** el renderizador trabaja con una
 *    lista blanca de campos neutros por plantilla. Aunque un `payload_json`
 *    llegara sin sanitizar, NUNCA incorpora a la Sede ni al Ciudadano QR
 *    diagnósticos de cierre, acciones de resolución, costes, notas internas,
 *    nombres/teléfonos de técnicos, causas de pausa ni textos libres de
 *    terceros. Los avisos al Ciudadano QR sólo exponen ticket, máquina,
 *    categoría declarada y fecha de apertura, y enlazan a `/?qr={machine_code}`.
 * 4. **Enlaces por nivel de acceso:** la Sede recibe `/?site={site_code}&ticket={ticket_code}`;
 *    el Ciudadano QR, `/?qr={machine_code}`. El enlace llega como ruta en
 *    `payload_json.tracking_url` (lo fija `NotificationPayloadSanitizer`); si
 *    faltara, el renderizador lo deriva de los códigos del expediente. La regla
 *    «a dónde lleva el botón» vive en `NotificationTrackingLinkResolver`, que
 *    comparte con el panel de supervisión: lo que Coordinación revisa en la
 *    bandeja es exactamente lo que se envía.
 * 5. **Enlaces absolutos en el correo.** La ruta se prefija con el host público
 *    canónico (`AppPublicUrl`, `APP_BASE_URL`) antes de llegar al asunto o al
 *    cuerpo. Un cliente de correo resuelve las rutas relativas contra su propio
 *    dominio —el botón aterrizaba en la web del proveedor de correo— así que el
 *    enlace sólo es válido si viaja completo. La ruta persistida en la cola no
 *    cambia: el host se aplica en el momento de renderizar, lo que repara
 *    también los envíos ya encolados que se reintenten.
 *
 * ## Contrato de `$payload` que construirá T-NOTIF-06
 *
 * Claves neutras (todas opcionales; las ausentes se omiten de la ficha):
 * `ticket_code`, `machine_code`, `machine_label`, `site_code`, `site_name`,
 * `address`, `floor_area`, `category_label`, `urgency_label`, `description`,
 * `event_at`, `opened_at`, `resolved_at`, `reopened_at`, `tracking_url`,
 * `reason_category_label`, `reason_text`, `site_reply_text`,
 * `cancellation_reason_label`, `blocked_after_72h`, `observation_text`,
 * `reopened_by_label`, `technician_label` (o `technical_service_label`, la
 * clave que emite `NotificationPayloadSanitizer` para roles externos),
 * `on_call_technician_count`,
 * `no_on_call_technician`, `elapsed_hours`, `chronic_reopen_count`,
 * `contention_passes`, `deferred_count`, `episode_key`.
 *
 * Las claves prohibidas para roles externos (`resolution_diagnosis`,
 * `resolution_action`, `cost_snapshot`, `technician_name`, `is_internal`, …)
 * se ignoran por completo: no existe ninguna rama que las lea.
 *
 * Dogma Vanilla: PHP 8.2+ puro, sin dependencias externas, sin I/O ni reloj oculto.
 * Dualismo Lingüístico: código e identificadores en inglés, documentación en castellano.
 */
final class NotificationMessageRenderer
{
    /** Zona operativa del parque para formatear los instantes del expediente. */
    public const OPERATIONAL_TIMEZONE = 'Europe/Madrid';

    /** Advertencia destacada de RF-03.2 (asunto y encabezado). */
    public const NO_ON_CALL_TECHNICIAN_WARNING = '⚠️ ALERTA SIN TÉCNICO DE GUARDIA DESIGNADO: Requiere asignación manual inmediata';

    /** Prefijo obligatorio en el asunto cuando no hay técnico de guardia. */
    public const NO_ON_CALL_TECHNICIAN_SUBJECT_PREFIX = '⚠️ ALERTA SIN TÉCNICO DE GUARDIA DESIGNADO';

    /** Garantía de reapertura tras la resolución (RF-05.5). */
    public const WARRANTY_HOURS = 48;

    /** Autoridad única del destino del botón (ver `NotificationTrackingLinkResolver`). */
    private readonly NotificationTrackingLinkResolver $trackingLinks;

    /**
     * @param AppPublicUrl|null $publicUrl Host canónico para absolutizar los
     *   enlaces de seguimiento. Sin él los enlaces conservan su ruta relativa,
     *   comportamiento heredado que usan las suites del renderizador puro.
     */
    public function __construct(?AppPublicUrl $publicUrl = null)
    {
        $this->trackingLinks = new NotificationTrackingLinkResolver($publicUrl);
    }

    /**
     * Autoridad del destino del botón que este renderizador aplica.
     *
     * La publica para que quien decide **si el enlace puede viajar** —el
     * despachador, que declara la degradación en el resumen de la pasada
     * (T-NOTIF-17)— consulte exactamente la misma instancia que escribe el
     * correo. Con un resolutor aparte, lo que sale en el mensaje y lo que se
     * declara en el resumen podrían decir cosas distintas sobre el mismo envío.
     */
    public function trackingLinks(): NotificationTrackingLinkResolver
    {
        return $this->trackingLinks;
    }

    private const CTA_LABELS = [
        NotificationTrackingLinkResolver::AUDIENCE_LOCATION => 'Abrir el Portal de Sede',
        NotificationTrackingLinkResolver::AUDIENCE_CITIZEN_QR => 'Consultar el estado del aviso',
        NotificationTrackingLinkResolver::AUDIENCE_TECHNICIAN => 'Abrir la avería',
        NotificationTrackingLinkResolver::AUDIENCE_INTERNAL => 'Abrir el expediente',
    ];

    /**
     * Renderiza una plantilla del catálogo con los datos del expediente.
     *
     * @param string|NotificationTemplate $template Plantilla canónica (enum o nombre).
     * @param array<string, mixed> $payload Datos neutros ya sanitizados en origen.
     * @return RenderedNotificationMessage Asunto y cuerpos listos para entrega.
     * @throws InvalidArgumentException si la plantilla no pertenece al catálogo cerrado.
     */
    public function render(string|NotificationTemplate $template, array $payload = []): RenderedNotificationMessage
    {
        $case = $template instanceof NotificationTemplate
            ? $template
            : NotificationTemplate::fromString($template);

        $draft = $this->compose($case, $payload);
        $subject = $this->buildSubject($draft);

        // El botón viaja como dato además de como HTML: Telegram lo necesita para
        // su teclado en línea y no puede leerlo del cuerpo del correo (RF-TG-04).
        return new RenderedNotificationMessage(
            template: $case,
            subject: $subject,
            textBody: $this->renderTextBody($draft),
            htmlBody: $this->renderHtmlBody($draft, $subject),
            ctaLabel: $draft['cta_label'],
            ctaUrl: $draft['tracking_url'],
        );
    }

    /**
     * Compone el borrador estructurado de cada una de las 21 plantillas.
     *
     * @param array<string, mixed> $payload
     * @return array{
     *     subject: string,
     *     eyebrow: string,
     *     title: string,
     *     intro: string,
     *     details: list<array{label: string, value: string}>,
     *     warning: ?string,
     *     warning_tone: string,
     *     warning_in_subject: bool,
     *     note: ?string,
     *     cta_label: ?string,
     *     tracking_url: ?string
     * }
     */
    private function compose(NotificationTemplate $template, array $payload): array
    {
        $ticket = $this->text($payload, 'ticket_code');
        $ticketSuffix = $ticket !== null ? ' · Ticket ' . $ticket : '';

        $draft = match ($template) {
            NotificationTemplate::COLD_CHAIN_IMMEDIATE_ALERT => $this->draft(
                subject: '🚨 Alerta inmediata de pérdida de frío' . $ticketSuffix,
                eyebrow: 'ALERTA SANITARIA CRÍTICA',
                title: 'Posible rotura de la cadena de frío',
                intro: 'Se ha detectado una posible rotura de la cadena de frío en una máquina bajo vigilancia sanitaria. '
                    . 'La alerta se ha encolado con prioridad máxima (Art. II).',
                details: $this->mergeRows(
                    $this->dossierRows($payload, true),
                    [
                        $this->row('Tipo de aviso', 'Alerta inmediata de frío'),
                        $this->row('Detectada', $this->dateText($payload, 'event_at')),
                        $this->row('Técnico asignado', $this->technicianLabel($payload)),
                        $this->row('Técnicos de guardia', $this->countText($payload, 'on_call_technician_count')),
                        $this->row('Descripción reportada', $this->text($payload, 'description')),
                    ]
                ),
                warning: $this->missingOnCallTechnician($payload) ? self::NO_ON_CALL_TECHNICIAN_WARNING : null,
                warningTone: 'danger',
                warningInSubject: $this->missingOnCallTechnician($payload),
                note: 'Prioridad máxima (Art. II): este aviso adelanta a cualquier notificación ordinaria en la cola de envío.',
            ),

            NotificationTemplate::SLA_IMMINENCE_2H_UNASSIGNED => $this->draft(
                subject: '⏰ Inminencia de SLA: 2 horas sin técnico asignado' . $ticketSuffix,
                eyebrow: 'VIGILANCIA DE SLA SANITARIO',
                title: 'Dos horas sin técnico asignado',
                intro: 'Una incidencia en máquina bajo vigilancia sanitaria supera las 2 horas naturales sin técnico asignado. '
                    . 'El reloj biológico no se detiene (Art. II).',
                details: $this->mergeRows(
                    $this->dossierRows($payload, true),
                    [
                        $this->row('Horas transcurridas', $this->hoursText($payload, 'elapsed_hours')),
                        $this->row('Técnicos de guardia', $this->countText($payload, 'on_call_technician_count')),
                    ]
                ),
                note: 'Umbral sanitario de 2 horas sin asignación, medido en tiempo natural continuo 24/7.',
            ),

            NotificationTemplate::SLA_IMMINENCE_3H_UNSTARTED => $this->draft(
                subject: '⏰ Inminencia de SLA: 3 horas sin inicio de intervención' . $ticketSuffix,
                eyebrow: 'VIGILANCIA DE SLA SANITARIO',
                title: 'Tres horas sin iniciar la intervención',
                intro: 'Una incidencia en máquina bajo vigilancia sanitaria supera las 3 horas naturales sin haber '
                    . 'iniciado la intervención presencial.',
                details: $this->mergeRows(
                    $this->dossierRows($payload, true),
                    [
                        $this->row('Horas transcurridas', $this->hoursText($payload, 'elapsed_hours')),
                        $this->row('Inicio de intervención', 'No registrado en el ciclo vigente'),
                        $this->row('Técnico asignado', $this->technicianLabel($payload)),
                    ]
                ),
                note: 'Si la intervención ya se hubiera iniciado y pausado, esta alerta no se emite; el reloj de 4 horas sigue corriendo.',
            ),

            NotificationTemplate::SANITARY_QUARANTINE_TRIGGERED => $this->draft(
                subject: '🧊 Cuarentena sanitaria activada (4 h sin refrigeración)' . $ticketSuffix,
                eyebrow: 'ALERTA SANITARIA CRÍTICA',
                title: 'Cuarentena sanitaria activada',
                intro: 'El reloj sanitario ha alcanzado las 4 horas naturales sin refrigeración confirmada. '
                    . 'La máquina ha entrado en Cuarentena Sanitaria y queda bloqueada hasta su higienización y verificación técnica.',
                details: $this->mergeRows(
                    $this->dossierRows($payload, true),
                    [
                        $this->row('Cuarentena activada', $this->dateText($payload, 'event_at')),
                        $this->row('Técnico asignado', $this->technicianLabel($payload)),
                        $this->row('Máquina bloqueada', 'Sí · fuera de servicio hasta verificación'),
                    ]
                ),
                warning: 'Cuarentena sanitaria activa: no se debe reanudar la venta hasta completar la higienización y la verificación técnica.',
                warningTone: 'danger',
            ),

            NotificationTemplate::SITE_SANITARY_QUARANTINE_NOTICE => $this->draft(
                subject: 'Aviso sanitario: máquina bloqueada por cuarentena' . $ticketSuffix,
                eyebrow: 'AVISO PREVENTIVO A LA SEDE',
                title: 'Máquina bloqueada por precaución sanitaria',
                intro: 'Por precaución sanitaria, una máquina de su centro ha quedado bloqueada hasta su higienización y verificación técnica. '
                    . 'No es necesario que realice ninguna acción en este momento; el servicio técnico coordinará la reapertura.',
                details: $this->mergeRows(
                    $this->dossierRows($payload, true),
                    [
                        $this->row('Motivo', 'Cuarentena sanitaria preventiva (4 horas sin refrigeración confirmada)'),
                    ]
                ),
                note: 'La máquina permanecerá bloqueada hasta su higienización y verificación técnica.',
            ),

            NotificationTemplate::SANITARY_SWEEP_ESCALATED => $this->draft(
                subject: '⚠️ Escalado del barrido sanitario: contención persistente',
                eyebrow: 'ALERTA SANITARIA CRÍTICA',
                title: 'El barrido sanitario entra en contención persistente',
                intro: 'El barrido periódico del reloj sanitario acumula contención persistente y la latencia de detección '
                    . 'sanitaria se está duplicando. Se requiere revisión inmediata de Coordinación.',
                details: $this->compact([
                    $this->row('Episodio', $this->text($payload, 'episode_key')),
                    $this->row('Pasadas en contención', $this->countText($payload, 'contention_passes')),
                    $this->row('Expedientes aplazados en la pasada', $this->countText($payload, 'deferred_count')),
                    $this->row('Detectado', $this->dateText($payload, 'event_at')),
                ]),
                warning: 'La latencia de detección sanitaria se está duplicando (Art. II).',
                warningTone: 'danger',
            ),

            NotificationTemplate::INCIDENT_OPENED_SITE => $this->draft(
                subject: $this->isOpportunisticOpening($payload)
                    ? '✅ Avería registrada y en atención in situ' . $ticketSuffix
                    : '✅ Hemos registrado su avería' . $ticketSuffix,
                eyebrow: 'CICLO DEL EXPEDIENTE',
                title: $this->isOpportunisticOpening($payload)
                    ? 'Avería registrada y ya en atención in situ'
                    : 'Avería registrada correctamente',
                intro: $this->isOpportunisticOpening($payload)
                    ? 'Hemos registrado la avería detectada durante la visita al centro y el servicio técnico '
                        . 'ya está interviniendo la máquina in situ. Puede seguir su evolución en el Portal de Sede.'
                    : 'Hemos registrado correctamente la avería comunicada desde su centro. '
                        . 'Puede seguir su evolución en el Portal de Sede.',
                details: $this->mergeRows(
                    $this->dossierRows($payload, true),
                    [
                        $this->row('Modalidad', $this->text($payload, 'opportunistic_mode_label')),
                        $this->row('Informante en sede', $this->text($payload, 'opportunistic_informant_name')),
                        $this->row('Servicio técnico', $this->technicianLabel($payload)),
                        $this->row('Dirección', $this->text($payload, 'address')),
                        $this->row('Fecha de apertura', $this->dateText($payload, 'opened_at') ?? $this->dateText($payload, 'event_at')),
                        $this->row('Descripción reportada', $this->text($payload, 'description')),
                    ]
                ),
                note: 'Conserve el código de ticket para cualquier consulta sobre el expediente.',
            ),

            NotificationTemplate::INCIDENT_OPENED_CITIZEN_QR => $this->draft(
                subject: '✅ Su aviso ha quedado registrado' . $ticketSuffix,
                eyebrow: 'CONFIRMACIÓN DE REGISTRO',
                title: 'Aviso registrado correctamente',
                intro: 'Hemos registrado correctamente el aviso comunicado mediante el código QR de la máquina. '
                    . 'Puede consultar su estado en la vista pública de seguimiento.',
                details: $this->compact([
                    $this->row('Ticket', $ticket),
                    $this->row('Máquina', $this->machineLabel($payload)),
                    $this->row('Categoría declarada', $this->text($payload, 'category_label')),
                    $this->row('Fecha de apertura', $this->dateText($payload, 'opened_at') ?? $this->dateText($payload, 'event_at')),
                ]),
                note: 'Este mensaje sólo incluye los datos públicos del aviso. No contiene información interna del servicio técnico.',
            ),

            NotificationTemplate::TECHNICIAN_ASSIGNED => $this->draft(
                subject: '🔧 Nueva asignación' . $ticketSuffix,
                eyebrow: 'ASIGNACIÓN DE SERVICIO',
                title: 'Se le ha asignado una nueva avería',
                intro: 'Se le ha asignado una nueva avería. Revise los datos de la parada antes de desplazarse.',
                details: $this->mergeRows(
                    $this->dossierRows($payload, true),
                    [
                        $this->row('Dirección', $this->text($payload, 'address')),
                        $this->row('Descripción del problema', $this->text($payload, 'description')),
                    ]
                ),
                note: 'Confirme el inicio de la intervención desde su hoja de ruta al llegar a la parada.',
            ),

            NotificationTemplate::TECHNICIAN_UNASSIGNED => $this->draft(
                subject: '🔄 Expediente reasignado a otro compañero' . $ticketSuffix,
                eyebrow: 'ASIGNACIÓN DE SERVICIO',
                title: 'El expediente ya no requiere su intervención',
                intro: 'El expediente que tenía asignado ha pasado a otro compañero y ya no requiere su intervención.',
                details: $this->mergeRows(
                    $this->dossierRows($payload, true),
                    [
                        $this->row('Nuevo responsable', $this->technicianLabel($payload)),
                        $this->row('Reasignado', $this->dateText($payload, 'event_at')),
                    ]
                ),
                note: 'No es necesario que se desplace a la parada. Su hoja de ruta se ha actualizado.',
            ),

            NotificationTemplate::PENDING_INFO_SITE_REQUEST => $this->draft(
                subject: '⏳ Acción requerida: falta acceso o información' . $ticketSuffix,
                eyebrow: 'SOLICITUD URGENTE A LA SEDE',
                title: 'El servicio técnico necesita acceso o información',
                intro: 'El servicio técnico no ha podido completar la intervención y necesita acceso o información adicional '
                    . 'para continuar. Por favor, responda desde su Portal de Sede lo antes posible.',
                details: $this->mergeRows(
                    $this->dossierRows($payload, true),
                    [
                        $this->row('Dirección', $this->text($payload, 'address')),
                        $this->row('Causa del bloqueo', $this->text($payload, 'reason_category_label')),
                        $this->row('Motivo indicado por el servicio técnico', $this->text($payload, 'reason_text')),
                    ]
                ),
                warning: 'El expediente permanece en pausa hasta que la Sede aporte el acceso o la información solicitada.',
                warningTone: 'warning',
                note: 'Su respuesta desde el Portal de Sede permite reanudar la intervención.',
            ),

            NotificationTemplate::PENDING_INFO_SITE_REPLY => $this->draft(
                subject: '💬 La Sede ha respondido' . $ticketSuffix,
                eyebrow: 'RESPUESTA DE LA SEDE',
                title: 'La Sede ha respondido a la solicitud del servicio técnico',
                intro: 'La Sede ha aportado nueva información sobre la pausa. Revise el mensaje y reanude la intervención '
                    . 'cuando confirme el acceso.',
                details: $this->mergeRows(
                    $this->dossierRows($payload, true),
                    [
                        $this->row('Respuesta de la Sede', $this->text($payload, 'site_reply_text')),
                        $this->row('Respondida', $this->dateText($payload, 'event_at')),
                    ]
                ),
                note: 'Si el acceso queda confirmado, reanude la intervención desde su hoja de ruta.',
            ),

            NotificationTemplate::INCIDENT_RESOLVED_SITE => $this->draft(
                subject: '✅ Servicio restablecido · Garantía de 48 horas' . $ticketSuffix,
                eyebrow: 'SEGUIMIENTO DEL EXPEDIENTE',
                title: 'Servicio restablecido',
                intro: 'El equipo vuelve a estar operativo. Dispone de un plazo de 48 horas de garantía para reabrir '
                    . 'el aviso si el fallo persistiera.',
                details: $this->mergeRows(
                    $this->dossierRows($payload, true),
                    [
                        $this->row('Resuelta', $this->dateText($payload, 'resolved_at') ?? $this->dateText($payload, 'event_at')),
                        $this->row('Garantía de reapertura', self::WARRANTY_HOURS . ' horas desde la resolución'),
                        $this->row('Servicio técnico', $this->technicianLabel($payload)),
                    ]
                ),
                note: 'Para reabrir el aviso dentro de la garantía, acceda al expediente desde su Portal de Sede.',
            ),

            NotificationTemplate::INCIDENT_RESOLVED_CITIZEN_QR => $this->draft(
                subject: '✅ Avería solucionada' . $ticketSuffix,
                eyebrow: 'SEGUIMIENTO DEL EXPEDIENTE',
                title: 'Avería solucionada',
                intro: 'El equipo vuelve a estar operativo. Si el problema persistiera, puede reabrir el aviso dentro '
                    . 'del plazo de 48 horas de garantía.',
                details: $this->compact([
                    $this->row('Ticket', $ticket),
                    $this->row('Máquina', $this->machineLabel($payload)),
                    $this->row('Resuelta', $this->dateText($payload, 'resolved_at') ?? $this->dateText($payload, 'event_at')),
                    $this->row('Garantía de reapertura', self::WARRANTY_HOURS . ' horas desde la resolución'),
                ]),
                note: 'Gracias por contribuir a mantener el servicio en funcionamiento.',
            ),

            NotificationTemplate::INCIDENT_REOPENED_COORDINATOR => $this->draft(
                subject: '🔁 Reapertura en garantía' . $ticketSuffix,
                eyebrow: 'TRIAJE · REAPERTURA EN GARANTÍA',
                title: 'Ticket reabierto en garantía',
                intro: 'Una incidencia resuelta se ha reabierto dentro del plazo de 48 horas de garantía y regresa '
                    . 'sin técnico asignado a la bandeja prioritaria de triaje.',
                details: $this->mergeRows(
                    $this->dossierRows($payload, true),
                    [
                        $this->row('Reabierta', $this->dateText($payload, 'reopened_at') ?? $this->dateText($payload, 'event_at')),
                        $this->row('Origen de la reapertura', $this->text($payload, 'reopened_by_label')),
                        $this->row('Descripción de la reapertura', $this->text($payload, 'description')),
                    ]
                ),
                note: 'El nuevo ciclo de atención reinicia los umbrales de vigilancia de 2 h y 3 h.',
            ),

            NotificationTemplate::CHRONIC_BREAKDOWN_ALERT => $this->draft(
                subject: '🚨 Avería crónica: tercera reincidencia' . $ticketSuffix,
                eyebrow: 'ALERTA SANITARIA CRÍTICA',
                title: 'Avería crónica detectada',
                intro: 'Una máquina acumula la tercera reincidencia consecutiva. La reapertura ha quedado bloqueada '
                    . 'y el expediente se marca como Avería Crónica para auditoría exhaustiva.',
                details: $this->mergeRows(
                    $this->dossierRows($payload, true),
                    [
                        $this->row('Reincidencias', $this->countText($payload, 'chronic_reopen_count') ?? '3'),
                        $this->row('Estado', 'Avería Crónica · reapertura bloqueada'),
                    ]
                ),
                warning: 'Requiere auditoría exhaustiva del historial de la máquina antes de una nueva intervención.',
                warningTone: 'danger',
            ),

            NotificationTemplate::INCIDENT_CANCELLED_SITE => $this->draft(
                subject: '📄 Expediente cerrado' . $ticketSuffix,
                eyebrow: 'SEGUIMIENTO DEL EXPEDIENTE',
                title: 'Expediente cerrado administrativamente',
                intro: 'El expediente ha quedado cerrado administrativamente.',
                details: $this->mergeRows(
                    $this->dossierRows($payload, true),
                    [
                        $this->row('Cerrado', $this->dateText($payload, 'event_at')),
                        $this->row('Motivo del cierre', $this->text($payload, 'cancellation_reason_label')),
                    ]
                ),
                warning: $this->isBlockedAfter72Hours($payload)
                    ? 'La máquina ha quedado FUERA DE SERVICIO / BLOQUEADA por falta de acceso hasta que la Sede confirme la apertura de las instalaciones.'
                    : null,
                warningTone: 'danger',
                note: 'Si el servicio debe reanudarse, contacte con Coordinación para abrir un nuevo expediente.',
            ),

            NotificationTemplate::INCIDENT_CANCELLED_CITIZEN_QR => $this->draft(
                subject: '📄 Su aviso ha sido cerrado' . $ticketSuffix,
                eyebrow: 'SEGUIMIENTO DEL EXPEDIENTE',
                title: 'Aviso cerrado',
                intro: 'El expediente asociado a su aviso ha quedado cerrado. Gracias por su colaboración.',
                details: $this->compact([
                    $this->row('Ticket', $ticket),
                    $this->row('Máquina', $this->machineLabel($payload)),
                    $this->row('Cerrado', $this->dateText($payload, 'event_at')),
                ]),
                note: 'Este mensaje no incluye motivos internos del inmueble ni datos de terceros.',
            ),

            NotificationTemplate::CITIZEN_QR_OBSERVATION_ACK => $this->draft(
                subject: '📝 Hemos recibido su observación' . $ticketSuffix,
                eyebrow: 'ACUSE DE RECIBO',
                title: 'Observación adicional registrada',
                intro: 'Hemos registrado su observación adicional sobre el aviso ya abierto. Gracias por su colaboración.',
                details: $this->compact([
                    $this->row('Ticket', $ticket),
                    $this->row('Máquina', $this->machineLabel($payload)),
                    $this->row('Observación registrada', $this->dateText($payload, 'event_at')),
                    $this->row('Su observación', $this->text($payload, 'observation_text')),
                ]),
                note: 'Su aportación se ha añadido al expediente sin modificar los datos del informador original.',
            ),

            // ── Módulo 16 · Avería oportunista in situ ──────────────────────
            NotificationTemplate::OPPORTUNISTIC_COLD_ROUTE_POSTPONED_ALERT => $this->draft(
                subject: '⚠️ Parada crítica pospuesta por una intervención oportunista' . $ticketSuffix,
                eyebrow: 'ALERTA SANITARIA · CADENA DE FRÍO',
                title: 'El técnico ha pospuesto una parada crítica en otra sede',
                intro: 'Un técnico ha confirmado una intervención oportunista mientras tenía pendiente una parada crítica '
                    . 'o con riesgo de cadena de frío en otra sede. La decisión ha quedado registrada con su confirmación expresa (Art. II).',
                details: $this->mergeRows(
                    $this->dossierRows($payload, true),
                    [
                        $this->row('Paradas postergadas', $this->countText($payload, 'postponed_stops_count')),
                        $this->row('Sede postergada', $this->text($payload, 'postponed_stop_location')),
                        $this->row('Expediente postergado', $this->text($payload, 'postponed_stop_ticket')),
                        $this->row('Técnico actuante', $this->technicianLabel($payload)),
                        $this->row('Origen del hallazgo', $this->text($payload, 'opportunistic_origin_label')),
                    ]
                ),
                warning: 'La parada crítica sigue pendiente en otra sede: verifique su cobertura inmediata (Art. II).',
                warningTone: 'danger',
                warningInSubject: true,
                note: 'La alerta se ha encolado con prioridad sanitaria por delante de cualquier aviso ordinario.',
            ),

            NotificationTemplate::INCIDENT_REOPENED_IN_SITU_SITE => $this->draft(
                subject: '🔁 Hemos reabierto su avería durante la visita' . $ticketSuffix,
                eyebrow: 'CICLO DEL EXPEDIENTE',
                title: 'Avería reabierta durante la visita técnica',
                intro: 'El servicio técnico ha reabierto la avería durante su presencia en el centro y ya está trabajando en ella. '
                    . 'Puede seguir su evolución en el Portal de Sede.',
                details: $this->mergeRows(
                    $this->dossierRows($payload, true),
                    [
                        $this->row('Reabierta', $this->dateText($payload, 'reopened_at') ?? $this->dateText($payload, 'event_at')),
                        $this->row('Motivo de la reapertura', $this->text($payload, 'reopen_reason')),
                        $this->row('Servicio técnico', $this->technicianLabel($payload)),
                    ]
                ),
                note: 'La garantía de reapertura de 48 horas vuelve a contar desde esta intervención.',
            ),
        };

        $audience = $this->trackingLinks->audienceFor($template);
        $trackingUrl = $draft['tracking_url'] !== null
            ? $this->trackingLinks->resolve($template, ['tracking_url' => $draft['tracking_url']])
            : $this->trackingLinks->resolve($template, $payload);

        return [
            ...$draft,
            'tracking_url' => $trackingUrl,
            'cta_label' => $draft['cta_label'] ?? ($trackingUrl !== null ? self::CTA_LABELS[$audience] : null),
        ];
    }

    /**
     * @param list<array{label: string, value: string}> $details
     * @return array{
     *     subject: string,
     *     eyebrow: string,
     *     title: string,
     *     intro: string,
     *     details: list<array{label: string, value: string}>,
     *     warning: ?string,
     *     warning_tone: string,
     *     warning_in_subject: bool,
     *     note: ?string,
     *     cta_label: ?string,
     *     tracking_url: ?string
     * }
     */
    private function draft(
        string $subject,
        string $eyebrow,
        string $title,
        string $intro,
        array $details,
        ?string $warning = null,
        string $warningTone = 'warning',
        bool $warningInSubject = false,
        ?string $note = null,
        ?string $ctaLabel = null,
        ?string $trackingUrl = null,
    ): array {
        return [
            'subject' => $subject,
            'eyebrow' => $eyebrow,
            'title' => $title,
            'intro' => $intro,
            'details' => $details,
            'warning' => $warning,
            'warning_tone' => $warningTone,
            'warning_in_subject' => $warningInSubject,
            'note' => $note,
            'cta_label' => $ctaLabel,
            'tracking_url' => $trackingUrl,
        ];
    }

    /**
     * @param array{subject: string, warning: ?string, warning_in_subject: bool} $draft
     */
    private function buildSubject(array $draft): string
    {
        $subject = $draft['subject'];

        if ($draft['warning_in_subject'] && $draft['warning'] !== null && !str_contains($subject, self::NO_ON_CALL_TECHNICIAN_SUBJECT_PREFIX)) {
            $subject = self::NO_ON_CALL_TECHNICIAN_WARNING . ' — ' . $subject;
        }

        $subject = trim(preg_replace('/\s+/u', ' ', $subject) ?? $subject);

        return mb_substr($subject, 0, RenderedNotificationMessage::MAX_SUBJECT_LENGTH);
    }

    /**
     * @param array{
     *     title: string,
     *     intro: string,
     *     details: list<array{label: string, value: string}>,
     *     warning: ?string,
     *     note: ?string,
     *     cta_label: ?string,
     *     tracking_url: ?string
     * } $draft
     */
    private function renderTextBody(array $draft): string
    {
        $lines = ['Hola,', '', $draft['intro']];

        if ($draft['warning'] !== null) {
            $lines[] = '';
            $lines[] = $draft['warning'];
        }

        if ($draft['details'] !== []) {
            $lines[] = '';
            foreach ($draft['details'] as $detail) {
                $lines[] = $detail['label'] . ': ' . $detail['value'];
            }
        }

        if ($draft['note'] !== null) {
            $lines[] = '';
            $lines[] = $draft['note'];
        }

        if ($draft['cta_label'] !== null && $draft['tracking_url'] !== null) {
            $lines[] = '';
            $lines[] = $draft['cta_label'] . ':';
            $lines[] = $draft['tracking_url'];
        }

        $lines[] = '';
        $lines[] = '—';
        $lines[] = 'VendGuard · Centro de Operaciones';
        $lines[] = 'Este mensaje se ha generado automáticamente; no responda a este correo.';

        return implode("\n", $lines) . "\n";
    }

    /**
     * @param array{
     *     eyebrow: string,
     *     title: string,
     *     intro: string,
     *     details: list<array{label: string, value: string}>,
     *     warning: ?string,
     *     warning_tone: string,
     *     note: ?string,
     *     cta_label: ?string,
     *     tracking_url: ?string
     * } $draft
     */
    private function renderHtmlBody(array $draft, string $subject): string
    {
        $warningBox = '';
        if ($draft['warning'] !== null) {
            [$background, $border, $ink] = $draft['warning_tone'] === 'danger'
                ? ['#fee2e2', '#dc2626', '#b91c1c']
                : ['#fef8e7', '#f8b60f', '#92400e'];

            $warningBox = sprintf(
                '<table role="presentation" width="100%%" cellpadding="0" cellspacing="0" style="margin:0 0 18px;">'
                . '<tr><td style="background-color:%s;border-left:4px solid %s;border-radius:4px;padding:12px 16px;'
                . 'color:%s;font-size:14px;font-weight:600;line-height:1.5;">%s</td></tr></table>',
                $background,
                $border,
                $ink,
                $this->escape($draft['warning'])
            );
        }

        $detailRows = '';
        foreach ($draft['details'] as $detail) {
            $detailRows .= sprintf(
                '<tr><td style="padding:6px 0;width:38%%;vertical-align:top;color:#6c7e9d;font-size:12px;'
                . 'line-height:1.5;">%s</td>'
                . '<td style="padding:6px 0;vertical-align:top;color:#2c333f;font-size:14px;line-height:1.5;">%s</td></tr>',
                $this->escape($detail['label']),
                $this->escape($detail['value'])
            );
        }

        $detailsBlock = $detailRows === '' ? '' : sprintf(
            '<table role="presentation" width="100%%" cellpadding="0" cellspacing="0" '
            . 'style="margin:0 0 18px;border-top:1px solid #c8cfda;border-bottom:1px solid #c8cfda;">%s</table>',
            $detailRows
        );

        $noteBlock = $draft['note'] === null ? '' : sprintf(
            '<p style="margin:0 0 18px;font-size:13px;line-height:1.6;color:#434c5f;">%s</p>',
            $this->escape($draft['note'])
        );

        $ctaBlock = '';
        if ($draft['cta_label'] !== null && $draft['tracking_url'] !== null) {
            $ctaBlock = sprintf(
                '<p style="margin:0 0 20px;"><a href="%s" style="display:inline-block;background-color:#2560ff;'
                . 'color:#ffffff;text-decoration:none;border-radius:4px;padding:10px 18px;font-size:14px;'
                . 'font-weight:600;">%s</a></p>'
                . '<p style="margin:0 0 4px;font-size:12px;line-height:1.5;color:#6c7e9d;">Si el botón no funciona, '
                . 'copie este enlace en su navegador:</p>'
                . '<p style="margin:0 0 18px;font-size:12px;line-height:1.5;color:#003db5;word-break:break-all;">%s</p>',
                $this->escape($draft['tracking_url']),
                $this->escape($draft['cta_label']),
                $this->escape($draft['tracking_url'])
            );
        }

        return sprintf(
            '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>%s</title></head>'
            . '<body style="margin:0;padding:24px;background-color:#f9fafb;'
            . 'font-family:Inter,Helvetica,Arial,sans-serif;color:#2c333f;">'
            . '<table role="presentation" width="100%%" cellpadding="0" cellspacing="0" style="background-color:#f9fafb;">'
            . '<tr><td align="center">'
            . '<table role="presentation" width="640" cellpadding="0" cellspacing="0" '
            . 'style="max-width:640px;width:100%%;background-color:#ffffff;border:1px solid #c8cfda;border-radius:8px;">'
            . '<tr><td style="background-color:#2560ff;padding:14px 24px;color:#ffffff;font-size:12px;'
            . 'font-weight:600;letter-spacing:0.06em;">VENDGUARD · CENTRO DE OPERACIONES</td></tr>'
            . '<tr><td style="padding:24px;">'
            . '<p style="margin:0 0 8px;font-size:11px;font-weight:600;letter-spacing:0.08em;color:#6c7e9d;">%s</p>'
            . '<h1 style="margin:0 0 12px;font-family:\'DM Sans\',Helvetica,Arial,sans-serif;font-size:24px;'
            . 'line-height:1.25;color:#2c333f;">%s</h1>'
            . '<p style="margin:0 0 18px;font-size:15px;line-height:1.6;">%s</p>'
            . '%s%s%s%s'
            . '</td></tr>'
            . '<tr><td style="padding:16px 24px;border-top:1px solid #c8cfda;background-color:#f9fafb;'
            . 'font-size:12px;line-height:1.6;color:#6c7e9d;">VendGuard · Centro de Operaciones<br>'
            . 'Este mensaje se ha generado automáticamente; no responda a este correo.</td></tr>'
            . '</table></td></tr></table></body></html>',
            $this->escape($subject),
            $this->escape($draft['eyebrow']),
            $this->escape($draft['title']),
            $this->escape($draft['intro']),
            $warningBox,
            $detailsBlock,
            $noteBlock,
            $ctaBlock
        );
    }

    /**
     * @param array<string, mixed> $payload
     * @return list<array{label: string, value: string}>
     */
    private function dossierRows(array $payload, bool $includeSite): array
    {
        return $this->compact([
            $this->row('Ticket', $this->text($payload, 'ticket_code')),
            $this->row('Máquina', $this->machineLabel($payload)),
            $this->row('Sede', $includeSite ? $this->text($payload, 'site_name') : null),
            $this->row('Ubicación', $includeSite ? $this->text($payload, 'floor_area') : null),
            $this->row('Categoría', $this->text($payload, 'category_label')),
            $this->row('Urgencia', $this->text($payload, 'urgency_label')),
        ]);
    }

    /**
     * @param list<?array{label: string, value: string}> ...$groups
     * @return list<array{label: string, value: string}>
     */
    private function mergeRows(array ...$groups): array
    {
        $rows = [];
        foreach ($groups as $group) {
            foreach ($group as $row) {
                if (is_array($row)) {
                    $rows[] = $row;
                }
            }
        }

        return $rows;
    }

    /**
     * @param list<?array{label: string, value: string}> $rows
     * @return list<array{label: string, value: string}>
     */
    private function compact(array $rows): array
    {
        return array_values(array_filter($rows, static fn (?array $row): bool => $row !== null));
    }

    /**
     * @return ?array{label: string, value: string}
     */
    private function row(string $label, ?string $value): ?array
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        return ['label' => $label, 'value' => trim($value)];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function machineLabel(array $payload): ?string
    {
        return $this->text($payload, 'machine_label') ?? $this->text($payload, 'machine_code');
    }

    /**
     * ¿La confirmación de apertura corresponde a un Alta Oportunista In Situ?
     *
     * El productor añade `opportunistic_mode` al payload neutro; con la modalidad
     * `NEW` (o su etiqueta) el correo a la Sede debe decir que la máquina ya está
     * siendo atendida en el acto (RF-06.3.1). La modalidad `ASSUMED` no genera
     * correo de apertura: asumir un expediente ya abierto nunca avisa a la Sede.
     *
     * @param array<string, mixed> $payload
     */
    private function isOpportunisticOpening(array $payload): bool
    {
        return $this->text($payload, 'opportunistic_mode') === 'NEW'
            || $this->text($payload, 'opportunistic_mode_label') === 'Oportunista In Situ · Nueva';
    }

    /**
     * Alias neutro del servicio técnico. `NotificationPayloadSanitizer` publica
     * `technical_service_label` para la Sede y el Ciudadano QR; el resto de
     * productores internos usan `technician_label`.
     *
     * @param array<string, mixed> $payload
     */
    private function technicianLabel(array $payload): ?string
    {
        return $this->text($payload, 'technician_label')
            ?? $this->text($payload, 'technical_service_label');
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function missingOnCallTechnician(array $payload): bool
    {
        if (($payload['no_on_call_technician'] ?? false) === true) {
            return true;
        }

        if (!array_key_exists('on_call_technician_count', $payload)) {
            return false;
        }

        $hasAssignedTechnician = $this->technicianLabel($payload) !== null
            || $this->text($payload, 'assigned_technician_name') !== null
            || (int)($payload['technician_id'] ?? 0) > 0;

        return (int)$payload['on_call_technician_count'] === 0 && !$hasAssignedTechnician;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function isBlockedAfter72Hours(array $payload): bool
    {
        $flag = $payload['blocked_after_72h'] ?? false;

        return $flag === true || $flag === 1 || $flag === '1' || $flag === 'true';
    }

    /**
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

    /**
     * @param array<string, mixed> $payload
     */
    private function dateText(array $payload, string $key): ?string
    {
        $raw = $this->text($payload, $key);
        if ($raw === null) {
            return null;
        }

        try {
            $instant = new DateTimeImmutable($raw);
        } catch (\Exception) {
            return $raw;
        }

        return $instant
            ->setTimezone(new DateTimeZone(self::OPERATIONAL_TIMEZONE))
            ->format('d/m/Y H:i');
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function countText(array $payload, string $key): ?string
    {
        if (!array_key_exists($key, $payload) || !is_numeric($payload[$key])) {
            return null;
        }

        return (string)(int)$payload[$key];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function hoursText(array $payload, string $key): ?string
    {
        if (!array_key_exists($key, $payload) || !is_numeric($payload[$key])) {
            return null;
        }

        $hours = (float)$payload[$key];
        $formatted = floor($hours) === $hours ? (string)(int)$hours : number_format($hours, 1, ',', '.');

        return $formatted . ' h';
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
