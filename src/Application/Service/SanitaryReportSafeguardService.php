<?php

declare(strict_types=1);

namespace VendGuard\Application\Service;

use VendGuard\Core\Domain\Model\AuditEvent;
use VendGuard\Core\Domain\Model\Incident;
use VendGuard\Core\Domain\Repository\AuditLogRepositoryInterface;
use VendGuard\Infrastructure\Repository\PdoAuditLogRepository;

/**
 * SanitaryReportSafeguardService — Borrador seguro del parte técnico ante una cuarentena
 * biológica **sobrevenida** (RF-10, EARS-10.1 y EARS-10.2, Art. II y Art. III; tarea
 * T-HYBRID-15).
 *
 * EL PROBLEMA QUE RESUELVE
 *
 * El técnico trabaja a pie de máquina, con frecuencia sin cobertura. Mientras redacta, el
 * barrido de fondo puede declarar la cuarentena de esa máquina a las 4 horas naturales
 * (RF-03.5.1). Cuando su intento de cierre llega, el sistema ya no puede aceptar la
 * resolución ordinaria —la máquina está fuera de servicio por riesgo térmico y el cierre
 * exige el protocolo de higienización— pero tampoco puede permitirse castigar el trabajo
 * administrativo ya hecho: pedirle que lo redacte otra vez es pedirle que lo pierda, y la
 * credibilidad de todo el protocolo sanitario depende de que su parte no se destruya.
 *
 * QUÉ HACE
 *
 *  1. **Salvaguarda (`safeguard`).** Guarda el informe redactado —diagnóstico, acción
 *     correctiva, declaración de repuestos y los bloques de saldo— como **evento inmutable
 *     de auditoría** sobre el expediente (`Art. III.1`). No hay almacén nuevo ni tabla
 *     nueva: el registro de auditoría es el único sitio del proyecto que es *append-only*
 *     por contrato, y un borrador que puede desaparecer no es un borrador seguro. La
 *     salvaguarda **no transiciona nada**: el expediente sigue `IN_PROGRESS` y el cierre
 *     sigue suspendido.
 *  2. **Idempotencia (`safeguard`).** Un segundo intento con el mismo informe no deja un
 *     segundo rastro: el parte se guarda **una vez** por contenido. Quien reintenta cinco
 *     veces con el mismo formulario no ensucia la auditoría con cinco eventos idénticos, y
 *     la suite puede afirmarlo contando eventos.
 *  3. **Recuperación (`completeReport`).** Recompone el cuerpo de la petición de cierre con
 *     lo que el borrador tenga y la petición no vuelva a traer, para que el técnico sólo
 *     tenga que firmar el protocolo sanitario (EARS-10.2). Lo que la petición **sí** trae
 *     gana siempre sobre el borrador: es la palabra más reciente de quien está delante de
 *     la máquina, y el borrador es, por definición, el parte de antes.
 *
 * QUÉ NO HACE
 *
 * No decide **cuándo** hay cuarentena sobrevenida (eso es de `IncidentPauseService`) ni
 * cuándo se cierra (eso es del cierre técnico), y **no evalúa** el protocolo sanitario: la
 * puerta sigue siendo `ResolutionValidator`. Aquí sólo se guarda y se recompone el parte.
 *
 * Dogma Vanilla: PHP 8.2+ puro; `AuditLogger` y su repositorio, inyectables y resueltos bajo
 * demanda, de modo que el servicio se construye en suites unitarias sin abrir conexión.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */
final class SanitaryReportSafeguardService
{
    /**
     * Acción de auditoría del parte salvaguardado.
     *
     * El nombre canónico vive en esta constante y el evento se escribe con el literal
     * equivalente: `tests/unit/AuditActionCatalogTest.php` enumera sólo los literales
     * pasados a `AuditLogger::log*Event()` y los contrasta con el catálogo que usa el visor
     * del coordinador, de modo que un parte salvaguardado sin etiqueta en la interfaz pone
     * la batería en rojo. La suite de este módulo certifica que ambos valores no se separan.
     */
    public const DRAFT_ACTION = 'SANITARY_QUARANTINE_REPORT_SAFEGUARDED';

    /** Clave del parte dentro del estado del evento inmutable. */
    public const STATE_KEY = 'safeguarded_report';

    /**
     * Campos del parte que se guardan a resguardo, en el orden en que el cierre los consume.
     *
     * Es una lista blanca a propósito: el cuerpo HTTP de la resolución llega del cliente y
     * un almacén de auditoría no es sitio para volcar lo que venga. Aquí está exactamente
     * lo que el cierre técnico necesita para no volver a pedirse: los dos textos
     * reglamentarios (Art. V.1), la declaración de repuestos con su lista, el dictamen de
     * saldo y el hallazgo de efectivo de oficio.
     */
    public const DRAFT_FIELDS = [
        'diagnosis',
        'action_taken',
        'replaced_parts_declared',
        'replaced_parts',
        'refund_inspection',
        'unclaimed_cash_found',
    ];

    /** Escritor del registro inmutable; se resuelve en el primer uso si no se inyecta. */
    private ?AuditLogger $auditLogger;

    /** Lector del registro inmutable; se resuelve en el primer uso si no se inyecta. */
    private ?AuditLogRepositoryInterface $auditRepository;

    public function __construct(
        ?AuditLogger $auditLogger = null,
        ?AuditLogRepositoryInterface $auditRepository = null
    ) {
        $this->auditLogger = $auditLogger;
        $this->auditRepository = $auditRepository;
    }

    /**
     * Guarda a resguardo el informe redactado del técnico sobre un expediente vivo.
     *
     * Si el último parte salvaguardado del expediente ya es idéntico al que llega, no
     * escribe nada y devuelve el que ya estaba: un reintento del mismo formulario no es un
     * hecho nuevo que merezca un segundo evento inmutable (Art. III).
     *
     * @param Incident $incident Expediente cuyo parte se salvaguarda (permanece intacto).
     * @param array<string, mixed> $body Cuerpo de la petición de cierre.
     * @param array{id: int|null, role: string, name: string} $actor Técnico que lo redactó.
     * @return array<string, mixed> El parte salvaguardado, campo a campo.
     */
    public function safeguard(Incident $incident, array $body, array $actor = []): array
    {
        $draft = $this->extractDraft($body);
        $current = $this->latestDraft($incident);

        if ($current !== null && $this->isSameReport($current, $draft)) {
            return $current;
        }

        $this->auditLogger()->logTicketEvent(
            ticketId: (int)$incident->getId(),
            // Literal deliberado, como en el disparo del reloj biológico: la guarda del
            // catálogo de auditoría enumera las acciones escritas como cadena literal.
            action: 'SANITARY_QUARANTINE_REPORT_SAFEGUARDED',
            user: $actor,
            previousState: null,
            newState: [
                // El estado se registra para contexto y es el MISMO que ya tenía: la
                // salvaguarda no transiciona nada y por eso el cierre sigue suspendido.
                'status' => $incident->getStatus()->value,
                'sanitary_quarantine_overtaken' => true,
                self::STATE_KEY => $draft,
            ],
            metadata: [
                'ticket_code' => $incident->getTicketCode(),
                'machine_id' => $incident->getMachineId(),
                'safeguarded_fields' => array_keys($draft),
                'reason' => 'La máquina entró en cuarentena sanitaria automática (4 h naturales, Art. II) '
                    . 'mientras la intervención estaba en curso: el cierre se suspende y el parte se guarda a resguardo.',
            ]
        );

        return $draft;
    }

    /**
     * Último parte salvaguardado de un expediente, o `null` si nunca hubo uno.
     *
     * @return array<string, mixed>|null
     */
    public function latestDraft(Incident $incident): ?array
    {
        $events = $this->auditRepository()->findEvents(
            [
                'entity_type' => AuditEvent::ENTITY_TICKET,
                'entity_id' => (int)$incident->getId(),
                'action' => self::DRAFT_ACTION,
            ],
            1
        );

        $event = $events[0] ?? null;
        if (!$event instanceof AuditEvent) {
            return null;
        }

        $draft = $event->getNewState()[self::STATE_KEY] ?? null;

        return is_array($draft) && $draft !== [] ? $draft : null;
    }

    /**
     * Recompone el informe técnico con lo que falte en la petición (EARS-10.2).
     *
     * Devuelve el cuerpo completado —el mismo array si no había nada que recuperar o si no
     * hay borrador—, de modo que el llamante no distingue «no había borrador» de «el
     * borrador no aportaba nada»: en los dos casos la petición sigue su curso normal.
     *
     * @param array<string, mixed> $body Cuerpo de la petición de cierre.
     * @return array<string, mixed> Cuerpo con los campos del parte que el borrador sí tiene.
     */
    public function completeReport(Incident $incident, array $body): array
    {
        $draft = $this->latestDraft($incident);
        if ($draft === null) {
            return $body;
        }

        foreach (self::DRAFT_FIELDS as $field) {
            if (!array_key_exists($field, $draft) || $this->isDeclared($body[$field] ?? null)) {
                continue;
            }

            $body[$field] = $draft[$field];
        }

        return $body;
    }

    /**
     * ¿Los dos partes son el mismo informe?
     *
     * La comparación es **por contenido**, nunca por la forma del array. El parte se guarda
     * dentro de `new_state`, que en el motor único es una columna `jsonb`: el motor
     * **normaliza el orden de las claves** (primero las cortas) y un parte idéntico vuelve
     * del registro con las claves en otro orden. Comparar los arrays con `===` —lo que hacía
     * esta puerta— daba «distinto» a lo que es el mismo parte, y el reintento escribía un
     * segundo evento inmutable: justo el rastro duplicado que esta clase promete no dejar.
     * El orden de las claves de un JSON no es información del parte; el contenido sí.
     *
     * Las **listas** conservan su orden —el motor no lo toca y el orden de los repuestos es
     * dato del parte—, así que sólo se ordenan las claves de los mapas.
     *
     * @param array<string, mixed> $current Parte que ya estaba en el registro.
     * @param array<string, mixed> $draft Parte que acaba de llegar.
     */
    private function isSameReport(array $current, array $draft): bool
    {
        return $this->canonicalReport($current) === $this->canonicalReport($draft);
    }

    /**
     * Forma canónica de un parte: las claves de cada mapa en orden, los valores intactos.
     *
     * @param mixed $value
     * @return mixed
     */
    private function canonicalReport(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        $normalized = array_map(fn (mixed $item): mixed => $this->canonicalReport($item), $value);

        if (!array_is_list($normalized)) {
            ksort($normalized, SORT_STRING);
        }

        return $normalized;
    }

    /**
     * ¿El campo llegó declarado en la petición?
     *
     * La pregunta es «¿el técnico habló de esto ahora?», no «¿el valor es verdadero?»: un
     * `replaced_parts_declared = false` explícito es una declaración —«no sustituí nada»— y
     * el borrador no puede pisarla. Sólo una ausencia real (o una lista vacía de los campos
     * que son listas) deja entrar al parte guardado.
     */
    private function isDeclared(mixed $value): bool
    {
        if ($value === null) {
            return false;
        }

        if (is_string($value)) {
            return trim($value) !== '';
        }

        if (is_array($value)) {
            return $value !== [];
        }

        return true;
    }

    /**
     * Proyección del cuerpo a la lista blanca del parte, sin los campos ausentes.
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function extractDraft(array $body): array
    {
        $draft = [];

        foreach (self::DRAFT_FIELDS as $field) {
            $value = $body[$field] ?? null;
            if ($this->isDeclared($value)) {
                $draft[$field] = $value;
            }
        }

        return $draft;
    }

    private function auditLogger(): AuditLogger
    {
        return $this->auditLogger ??= new AuditLogger($this->auditRepository());
    }

    private function auditRepository(): AuditLogRepositoryInterface
    {
        return $this->auditRepository ??= new PdoAuditLogRepository();
    }
}
