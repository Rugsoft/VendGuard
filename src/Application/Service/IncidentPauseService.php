<?php

declare(strict_types=1);

namespace VendGuard\Application\Service;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use RuntimeException;
use VendGuard\Application\DTO\IncidentPauseRequestDto;
use VendGuard\Application\DTO\IncidentPauseResponseDto;
use VendGuard\Core\Domain\Exception\InvalidTransitionException;
use VendGuard\Core\Domain\Model\Incident;
use VendGuard\Core\Domain\Model\MachineType;
use VendGuard\Core\Domain\Model\RefundRequest;
use VendGuard\Core\Domain\Model\RefundStatus;
use VendGuard\Core\Domain\Model\UserRole;
use VendGuard\Core\Domain\Repository\IncidentRepositoryInterface;
use VendGuard\Core\Domain\Repository\MachineRepositoryInterface;
use VendGuard\Core\Domain\Repository\PreventiveSettingsRepositoryInterface;
use VendGuard\Core\Domain\Repository\RefundRequestRepositoryInterface;
use VendGuard\Core\Domain\Repository\TransactionManagerInterface;
use VendGuard\Core\Domain\ValueObject\IncidentPauseReasonCategory;
use VendGuard\Core\Domain\ValueObject\IncidentStatus;
use VendGuard\Infrastructure\Database\PdoTransactionManager;
use VendGuard\Infrastructure\Repository\PdoIncidentRepository;
use VendGuard\Infrastructure\Repository\PdoMachineRepository;
use VendGuard\Infrastructure\Repository\PdoPreventiveSettingsRepository;
use VendGuard\Infrastructure\Repository\PdoRefundRequestRepository;

/**
 * IncidentPauseService — Orquestación del estado operativo "Pendiente de
 * Información" (`PENDING_INFO`) con pausa de SLA (Módulo 11).
 *
 * Esta primera entrega (T-PAUSE-06) implementa el **Algoritmo 3: recálculo del
 * vencimiento contractual en ventana de horario hábil comercial** (RF-03.3),
 * aislado como función pura de calendario: sin persistencia, sin red y sin
 * dependencias externas, de modo que el cálculo sea verificable al segundo.
 * Las tareas siguientes (T-PAUSE-07 a T-PAUSE-09) añadirán a esta misma clase la
 * orquestación con repositorios (reloj sanitario, reactivación condicional y
 * cancelación por inactividad) sin que la firma de este método cambie.
 *
 * Regla de negocio que implementa:
 *
 * > Cuando una incidencia se reanuda, su fecha límite contractual
 * > (`sla_target_at`) se desplaza hacia adelante la duración exacta de la pausa,
 * > computada **únicamente** dentro de la ventana comercial de la sede
 * > (08:00 a 18:00, lunes a viernes). Nunca puede vencer de madrugada, en
 * > sábado ni en domingo: un cliente que cierra a las 18:00 no recibe un
 * > compromiso que expire un sábado a las 03:00 (RF-03.3).
 *
 * Decisiones de diseño anotadas para las tareas siguientes:
 *
 * 1. **Precisión de segundos (RNF-01):** la tarea fija la firma en segundos, así
 *    que el cómputo es aritmética de segundos y no de minutos. El pseudocódigo
 *    del `plan.md` §3.3 trabaja en minutos por legibilidad; convertirlo a
 *    segundos es lo que exige el requisito no funcional, no una desviación.
 * 2. **Zona horaria de la sede:** el calendario comercial se resuelve en
 *    `Europe/Madrid`, que es la zona operativa del sistema (la misma que usan
 *    `AuditEvent`, `MetricFilter` y `CoordinatorPreventiveDetailService`). La
 *    fecha devuelta viaja en esa zona, de modo que el `format('Y-m-d H:i:s')`
 *    que se persiste es siempre hora local de sede.
 * 3. **Ventana cerrada por arriba:** el intervalo legal es `[08:00, 18:00]`; un
 *    vencimiento exactamente a las 18:00 es el borde del último segundo hábil
 *    del día y se acepta. Cualquier instante posterior salta al siguiente día
 *    hábil a las 08:00.
 * 4. **La ventana es hoy uniforme para toda la red** (08:00-18:00, lunes a
 *    viernes), tal y como fija el requisito ("por defecto" en `plan.md` §3.3).
 *    El parámetro `$locationId` identifica la sede de forma explícita y se
 *    valida, para que un identificador inválido no aplique en silencio el
 *    calendario por defecto; es además el punto de extensión previsto para
 *    admitir calendarios por sede sin romper la firma.
 * 5. **Pausa de duración cero:** devuelve el vencimiento original intacto. Sin
 *    tiempo pausado no hay nada que desplazar y normalizar la fecha alteraría
 *    el contrato sin causa (caso límite §6.1 de la especificación funcional).
 * 6. **Dependencias perezosas:** el constructor acepta el repositorio de ajustes
 *    preventivos y el registrador de auditoría, pero si no se inyectan se crean
 *    en el primer uso, no al construir. El cálculo de calendario del Algoritmo 3
 *    queda así hermético (sin PDO ni red) incluso con `new IncidentPauseService()`,
 *    que es justo lo que exige la suite unitaria de T-PAUSE-06.
 * 7. **El reloj biológico es natural, no comercial:** el Algoritmo 4 cuenta
 *    segundos corridos 24/7 desde la apertura del expediente y no descuenta
 *    noches ni fines de semana, porque la pérdida de frío no descansa. Los dos
 *    relojes del módulo conviven aquí sin confundirse (RF-03.3 frente a RF-03.4).
 *
 * Dogma Vanilla: PHP 8.2+ puro, tipado estricto, cero dependencias externas.
 * Dualismo Lingüístico: código en inglés, documentación y errores en castellano.
 */
final class IncidentPauseService
{
    /**
     * Umbral del Reloj Sanitario Biológico: 4 horas naturales continuas sin frío
     * confirmado antes de la cuarentena automática (RF-03.5, Art. II).
     */
    public const SANITARY_BIOLOGICAL_CLOCK_SECONDS = 14400;

    /** Estado higiénico-sanitario que declara la máquina fuera de servicio por riesgo térmico. */
    public const SANITARY_STATUS_QUARANTINE = 'QUARANTINE';

    /**
     * Acción de auditoría inmutable del disparo automático del reloj biológico
     * (RF-03.5). Nombre canónico para los consumidores que tengan que reconocer el
     * evento; el punto de escritura usa el mismo valor como cadena literal porque
     * `tests/unit/AuditActionCatalogTest.php` sólo enumera las acciones declaradas
     * literalmente para contrastarlas con el catálogo del visor de auditoría. La
     * suite de este módulo certifica que ambos valores no se separan.
     */
    public const ACTION_SANITARY_QUARANTINE_AUTO_TRIGGERED = 'SANITARY_QUARANTINE_AUTO_TRIGGERED';

    /** Longitud mínima de un comentario de sede para que active la reanudación (RF-02.1). */
    public const MIN_SITE_COMMENT_LENGTH = 5;

    /**
     * Ventana de "respuesta en caliente": por debajo de una hora el técnico sigue
     * presumiblemente en la sede y la avería vuelve a `IN_PROGRESS` (RF-02.1).
     */
    public const HOT_REACTIVATION_WINDOW_SECONDS = 3600;

    /**
     * Longitud mínima del motivo de cancelación por inactividad (RF-04.3, Art. V.1).
     *
     * Veinte caracteres reales DESPUÉS de recortar los espacios de los extremos,
     * que es exactamente lo que mide `Algoritmo 5` en el plan técnico
     * (`LongitudReal(motivoCancelacion.trim())`).
     */
    public const MIN_CANCELLATION_REASON_LENGTH = 20;

    /**
     * Umbral de espera prolongada de cliente, en horas HÁBILES comerciales (RF-04.2).
     *
     * La alerta se enciende cuando la pausa acumula MÁS de 72 horas hábiles
     * (08:00-18:00, lunes a viernes). El cronómetro natural no sirve aquí: un
     * expediente pausado el viernes por la tarde no puede alcanzar el umbral
     * durante el fin de semana, porque la sede no está abierta para responder.
     */
    public const PROLONGED_INACTIVITY_BUSINESS_HOURS = 72;

    /**
     * Acción de auditoría del bloqueo de máquina por falta de acceso (RF-04.4).
     *
     * El nombre canónico vive en esta constante y el evento se escribe con el
     * literal equivalente: la guarda del catálogo de auditoría solo enumera
     * literales, y la suite del módulo certifica que ambos valores no se separan.
     */
    public const ACTION_MACHINE_BLOCKED_NO_ACCESS = 'MACHINE_BLOCKED_NO_ACCESS';

    /**
     * Acción de auditoría de la desvinculación del reintegro (RF-04.5).
     */
    public const ACTION_REFUND_DETACHED_BY_INACTIVITY = 'REFUND_DETACHED_BY_INACTIVITY';

    /** Repositorio de ajustes preventivos; se resuelve en el primer uso si no se inyecta. */
    private ?PreventiveSettingsRepositoryInterface $settingsRepo;

    /** Registrador de auditoría inmutable; se resuelve en el primer uso si no se inyecta. */
    private ?AuditLogger $auditLogger;

    /** Repositorio de incidencias; se resuelve en el primer uso si no se inyecta. */
    private ?IncidentRepositoryInterface $incidentRepo;

    /** Repositorio de máquinas; se resuelve en el primer uso si no se inyecta. */
    private ?MachineRepositoryInterface $machineRepo;

    /** Repositorio de reintegros; se resuelve en el primer uso si no se inyecta. */
    private ?RefundRequestRepositoryInterface $refundRepo;

    /** Unidad de trabajo transaccional; se resuelve en el primer uso si no se inyecta. */
    private ?TransactionManagerInterface $transactionManager;

    /**
     * El orden de los parámetros conserva la firma ya publicada por T-PAUSE-06/07
     * (ajustes, auditoría) y añade al final el repositorio de incidencias que exige
     * la reactivación condicional de T-PAUSE-08, para no romper a los consumidores
     * existentes. Todos los colaboradores son opcionales y se resuelven de forma
     * perezosa: un `new IncidentPauseService()` sigue siendo una calculadora de
     * calendario sin E/S.
     */
    public function __construct(
        ?PreventiveSettingsRepositoryInterface $settingsRepo = null,
        ?AuditLogger $auditLogger = null,
        ?IncidentRepositoryInterface $incidentRepo = null,
        ?MachineRepositoryInterface $machineRepo = null,
        ?RefundRequestRepositoryInterface $refundRepo = null,
        ?TransactionManagerInterface $transactionManager = null
    ) {
        $this->settingsRepo = $settingsRepo;
        $this->auditLogger = $auditLogger;
        $this->incidentRepo = $incidentRepo;
        $this->machineRepo = $machineRepo;
        $this->refundRepo = $refundRepo;
        $this->transactionManager = $transactionManager;
    }

    /** Apertura de la jornada comercial de la sede. */
    private const BUSINESS_OPEN_HOUR = 8;

    /** Cierre de la jornada comercial de la sede. */
    private const BUSINESS_CLOSE_HOUR = 18;

    /** Zona horaria operativa del calendario comercial. */
    private const OPERATIONAL_TIMEZONE = 'Europe/Madrid';

    /** Último día hábil de la semana en formato ISO-8601 (`1` = lunes, `5` = viernes). */
    private const LAST_BUSINESS_WEEKDAY = 5;

    /**
     * Desplaza el vencimiento contractual sumando exclusivamente segundos hábiles
     * comerciales (08:00 a 18:00, lunes a viernes), saltando noches, fines de
     * semana y festivos semanales (RF-03.3, RNF-01).
     *
     * Ejemplos con la ventana por defecto:
     * - Jueves 12:15 con 90 minutos de pausa -> Jueves 13:45 (mismo día hábil).
     * - Jueves 17:30 con 60 minutos de pausa -> Viernes 08:30 (salto nocturno).
     * - Viernes 17:00 con 120 minutos de pausa -> Lunes 09:00 (salto de fin de semana).
     *
     * @param DateTimeImmutable $currentDeadline Vencimiento contractual vigente.
     * @param int $pauseDurationSeconds Duración exacta de la pausa, en segundos.
     * @param int $locationId Sede cuyo calendario comercial rige el cómputo.
     * @return DateTimeImmutable Nuevo vencimiento, expresado en la zona horaria de la sede.
     *
     * @throws InvalidArgumentException Si la sede no es válida o la duración es negativa.
     */
    public function shiftSlaTargetInBusinessHours(
        DateTimeImmutable $currentDeadline,
        int $pauseDurationSeconds,
        int $locationId
    ): DateTimeImmutable {
        if ($locationId <= 0) {
            throw new InvalidArgumentException(sprintf(
                'El identificador de sede debe ser un entero positivo para resolver el calendario comercial; se recibió %d.',
                $locationId
            ));
        }

        if ($pauseDurationSeconds < 0) {
            throw new InvalidArgumentException(sprintf(
                'La duración de la pausa no puede ser negativa; se recibieron %d segundos.',
                $pauseDurationSeconds
            ));
        }

        if ($pauseDurationSeconds === 0) {
            return $currentDeadline;
        }

        $cursor = $currentDeadline->setTimezone($this->madridTimezone());
        $remainingSeconds = $pauseDurationSeconds;

        // Cada iteración consume, como máximo, una jornada comercial. El bucle
        // avanza un día hábil por vuelta como tope, así que su coste es
        // proporcional a los días hábiles abarcados por la pausa (para el umbral
        // de 72 horas hábiles, tres vueltas).
        while ($remainingSeconds > 0) {
            if (!$this->isBusinessDay($cursor)) {
                $cursor = $this->openingOfNextBusinessDay($cursor);
                continue;
            }

            $secondOfDay = $this->secondOfDay($cursor);
            $openingSecond = self::BUSINESS_OPEN_HOUR * 3600;
            $closingSecond = self::BUSINESS_CLOSE_HOUR * 3600;

            if ($secondOfDay < $openingSecond) {
                // Madrugada: el reloj arranca a la apertura del mismo día hábil.
                $cursor = $cursor->setTime(self::BUSINESS_OPEN_HOUR, 0, 0);
                continue;
            }

            if ($secondOfDay >= $closingSecond) {
                $cursor = $this->openingOfNextBusinessDay($cursor);
                continue;
            }

            $secondsUntilClosing = $closingSecond - $secondOfDay;

            if ($remainingSeconds <= $secondsUntilClosing) {
                return $cursor->modify(sprintf('+%d seconds', $remainingSeconds));
            }

            $remainingSeconds -= $secondsUntilClosing;
            $cursor = $this->openingOfNextBusinessDay($cursor);
        }

        return $cursor;
    }

    /**
     * Evalúa el Reloj Sanitario Biológico de una incidencia y dispara la cuarentena
     * automática de la máquina si procede (Algoritmo 4, RF-03.4, RF-03.5, Art. II).
     *
     * El reloj es **natural y continuo 24/7**: cuenta los segundos corridos desde la
     * apertura del expediente sin descontar noches ni fines de semana. Si la máquina
     * dispensa alimentos perecederos y el contador alcanza las 4 horas
     * (`SANITARY_BIOLOGICAL_CLOCK_SECONDS`), la máquina pasa a `QUARANTINE` y se
     * registra el evento inmutable `SANITARY_QUARANTINE_AUTO_TRIGGERED` con la espera
     * acumulada. La cuarentena es la que deja la máquina fuera de servicio para el
     * ciudadano (bloqueo del QR y del certificado sanitario) y la que obliga a superar
     * el checklist de reinspección del módulo 05 antes de devolverla al servicio; el
     * formulario de resolución no puede cerrar la cadena de frío como si estuviera
     * restablecida mientras la máquina siga en cuarentena.
     *
     * Reglas que muerde este método:
     * 1. **Sólo perecederos.** Cualquier otra tipología (bebidas, snacks, mixta) progresa
     *    únicamente con el reloj contractual: devuelve `false` sin tocar la máquina.
     * 2. **Sólo averías vivas.** Un ticket `RESOLVED`, `CLOSED` o `CANCELLED` ya no puede
     *    disparar la cuarentena: si la cadena de frío se restableció, cualquier bloqueo
     *    posterior pertenece al flujo preventivo de reinspección, no a este reloj.
     * 3. **Idempotencia.** Evaluar dos veces la misma máquina no duplica ni la escritura
     *    ni el rastro: si ya está en `QUARANTINE`, devuelve `true` sin volver a auditar.
     *
     * La tipología se resuelve primero contra la máquina persistida y sólo después
     * contra el campo enriquecido del expediente. Si ninguna de las dos la declara, el
     * método se detiene con excepción en lugar de asumir un tipo: la salvaguarda
     * sanitaria no puede depender de una suposición (Fail-Fast, Art. II).
     *
     * @param Incident $incident Incidencia viva cuya máquina se evalúa.
     * @return bool `true` si la máquina queda (o permanece) en cuarentena sanitaria.
     *
     * @throws \DomainException Si no es posible determinar la tipología de la máquina
     *                           o el expediente carece de marca de apertura válida.
     * @throws RuntimeException Si el estado sanitario no se puede persistir.
     */
    public function evaluateSanitaryBiologicalClock(Incident $incident): bool
    {
        $machineId = $incident->getMachineId();
        $settings = $this->settings()->getMachineSettings($machineId);

        $machineTypeRaw = $settings['machine_type'] ?? $incident->getMachineType();
        $machineType = is_string($machineTypeRaw) && $machineTypeRaw !== ''
            ? MachineType::tryFrom(strtoupper(trim($machineTypeRaw)))
            : null;

        if ($machineType === null) {
            throw new \DomainException(sprintf(
                'No se pudo determinar la tipología de la máquina %d para evaluar el reloj sanitario biológico (Art. II).',
                $machineId
            ));
        }

        if (!$machineType->isPerishable()) {
            return false;
        }

        if (in_array($incident->getStatus(), [
            IncidentStatus::RESOLVED,
            IncidentStatus::CLOSED,
            IncidentStatus::CANCELLED,
        ], true)) {
            return false;
        }

        $openedAt = $this->parseDateTime($incident->getCreatedAt());
        if ($openedAt === null) {
            throw new \DomainException(sprintf(
                'No se pudo evaluar el reloj sanitario biológico de la incidencia %s: carece de marca de apertura válida (Art. II).',
                (string)$incident->getTicketCode()
            ));
        }

        // Reloj natural: segundos corridos 24/7 desde la apertura del expediente, sin
        // descuento de jornada comercial (eso es el Algoritmo 3, no éste).
        $elapsedSeconds = (new DateTimeImmutable('now'))->getTimestamp() - $openedAt->getTimestamp();

        if ($elapsedSeconds < self::SANITARY_BIOLOGICAL_CLOCK_SECONDS) {
            return false;
        }

        $previousSanitaryStatus = (string)($settings['sanitary_status'] ?? '');

        if ($previousSanitaryStatus === self::SANITARY_STATUS_QUARANTINE) {
            return true;
        }

        if (!$this->settings()->updateSanitaryStatus($machineId, self::SANITARY_STATUS_QUARANTINE)) {
            throw new RuntimeException(sprintf(
                'No se pudo activar la cuarentena sanitaria de la máquina %d tras superar el umbral de 4 horas naturales (Art. II).',
                $machineId
            ));
        }

        $this->audit()->logMachineEvent(
            $machineId,
            // Literal deliberado: la guarda del catálogo de auditoría enumera las
            // acciones escritas como cadena literal, y es ese contraste el que
            // impide publicar un evento sin etiqueta en el visor del coordinador.
            'SANITARY_QUARANTINE_AUTO_TRIGGERED',
            [
                'id' => null,
                'role' => 'SYSTEM',
                'name' => 'Sistema (Reloj Sanitario Biológico Art. II)',
            ],
            ['sanitary_status' => $previousSanitaryStatus !== '' ? $previousSanitaryStatus : 'OK'],
            [
                'sanitary_status' => self::SANITARY_STATUS_QUARANTINE,
                'sanitary_checklist_required' => true,
            ],
            [
                'incident_id' => $incident->getId(),
                'ticket_code' => $incident->getTicketCode(),
                'machine_id' => $machineId,
                'elapsed_natural_seconds' => $elapsedSeconds,
                'threshold_seconds' => self::SANITARY_BIOLOGICAL_CLOCK_SECONDS,
                'reason' => 'Ruptura térmica prolongada: superadas las 4 horas naturales continuas sin frío confirmado (Art. II).',
            ]
        );

        return true;
    }

    /**
     * Reactivación condicional inteligente tras un comentario público de la sede
     * (Algoritmo 2, RF-02.1, RF-05.3, Art. V.1).
     *
     * El portal de sede persiste el comentario y llama aquí: este método decide a qué
     * estado vuelve la avería y lo deja grabado, sin volver a escribir el mensaje.
     *
     * Regla de destino, tal y como fija la especificación:
     * - **Vuelve a `IN_PROGRESS`** sólo si la respuesta llega en caliente (< 60 minutos),
     *   la avería sigue con técnico asignado, ese técnico no tiene otra intervención en
     *   curso y nadie la reasignó durante la pausa. Es la única combinación en la que el
     *   sistema puede afirmar sin mentir que el profesional sigue delante de la máquina.
     * - **Vuelve a `ASSIGNED`** en cualquier otro caso: respuesta desfasada, técnico
     *   ocupado en otra avería, avería reasignada durante la pausa o avería sin técnico
     *   responsable (caso límite §6.4). Devolverla a "En curso" sería una ficción
     *   operativa: obliga al técnico a pulsar "Iniciar" al personarse.
     *
     * Además, cierra el intervalo de pausa (acumulando sus segundos exactos en el
     * agregado), desplaza el vencimiento contractual en horario comercial de la sede
     * con el Algoritmo 3 y deja el rastro inmutable de la reanudación en
     * `incident_history` (Art. III).
     *
     * Idempotencia (RF-05.3): un comentario corto, un expediente que ya no está en
     * `PENDING_INFO` —porque el primer mensaje de la ráfaga ya reanudó— o un expediente
     * inexistente no provocan escrituras; los casos repetidos devuelven el estado real
     * del expediente en el mismo contrato de respuesta.
     *
     * @param int $incidentId Incidencia sobre la que se publica el comentario.
     * @param string $commentText Texto publicado por la sede.
     * @param int $siteUserId Referencia del actor de sede que publica (portal).
     * @return IncidentPauseResponseDto Estado resultante en el contrato canónico del módulo.
     *
     * @throws InvalidArgumentException Si los identificadores no son válidos.
     * @throws \DomainException Si la incidencia no existe.
     * @throws RuntimeException Si la reanudación no se puede persistir.
     */
    public function handleSiteCommentReactivation(
        int $incidentId,
        string $commentText,
        int $siteUserId
    ): IncidentPauseResponseDto {
        if ($incidentId < 1) {
            throw new InvalidArgumentException(
                'La reactivación condicional exige una incidencia persistida (id positivo).'
            );
        }

        if ($siteUserId < 1) {
            throw new InvalidArgumentException(
                'La reactivación condicional exige la referencia del actor de sede que publica el comentario.'
            );
        }

        $now = new DateTimeImmutable();
        $incident = $this->incidents()->findById($incidentId);

        if ($incident === null) {
            throw new \DomainException(sprintf(
                'No se encontró ninguna incidencia con ID %d para procesar el comentario de sede.',
                $incidentId
            ));
        }

        $normalizedComment = trim($commentText);

        // Sin reactivación: comentario sin sustancia descriptiva, expediente que no está
        // en pausa o que ya fue reanudado por el primer mensaje de una ráfaga. Nada se
        // escribe y el contrato devuelve la verdad del expediente.
        if (mb_strlen($normalizedComment) < self::MIN_SITE_COMMENT_LENGTH || !$incident->isPendingInfo()) {
            return $this->buildCurrentStateResponse($incident, $now);
        }

        $pauseDurationSeconds = $incident->currentPauseDurationSeconds($now);
        $assignedTechnicianId = $incident->getAssignedTechnicianId();
        $technicianIsBusy = $assignedTechnicianId !== null
            && $this->technicianHasAnotherActiveIntervention($assignedTechnicianId, $incidentId);
        $reassignedDuringPause = $this->wasReassignedDuringCurrentPause($incident);
        $isHotResponse = $pauseDurationSeconds < self::HOT_REACTIVATION_WINDOW_SECONDS;

        $targetStatus = ($isHotResponse
            && $assignedTechnicianId !== null
            && !$technicianIsBusy
            && !$reassignedDuringPause)
            ? IncidentStatus::IN_PROGRESS
            : IncidentStatus::ASSIGNED;

        $originalSlaTargetAt = $incident->getSlaTargetAt();

        // Rastro inmutable de solo adición. El actor va como `null` a propósito: el
        // responsable de sede no es un usuario interno y `incident_history.user_id` es
        // una clave foránea a `users`; su referencia viaja en la nota para no perder
        // trazabilidad ni violar la integridad referencial (Art. III).
        [$resumed, $shiftedSlaTargetAt] = $this->closePauseInterval(
            $incident,
            $targetStatus,
            $pauseDurationSeconds,
            null,
            fn (?string $shiftedDeadline): string => $this->reactivationNote(
                $targetStatus,
                $pauseDurationSeconds,
                $technicianIsBusy,
                $reassignedDuringPause,
                $assignedTechnicianId === null,
                $siteUserId,
                $originalSlaTargetAt,
                $shiftedDeadline
            ),
            $now,
            'tras el comentario de sede'
        );

        return new IncidentPauseResponseDto(
            incidentId: (int)$resumed->getId(),
            ticketCode: $resumed->getTicketCode(),
            status: $targetStatus,
            isSlaPaused: false,
            accumulatedPauseMinutes: (int)round($resumed->getTotalPendingInfoSeconds() / 60),
            slaTargetAtOriginal: $originalSlaTargetAt,
            slaTargetAtShifted: $shiftedSlaTargetAt
        );
    }

    /**
     * Declara la pausa por bloqueo imputable a la sede y deja el rastro inmutable
     * (RF-01.1 a RF-01.4, RNF-02, Art. III y Art. V.1).
     *
     * Es el camino del técnico asignado —y, con el mismo servicio, el del coordinador
     * en T-PAUSE-13—: la avería entra en `PENDING_INFO`, el reloj contractual queda
     * congelado y la máquina se desprioriza en la ruta. La unidad de trabajo es UNA:
     * la transición del expediente, el sellado del intervalo y su evento de auditoría
     * no pueden quedar a medias, porque un ticket pausado sin rastro es un agujero en
     * la trazabilidad (Art. III) y un rastro sin ticket pausado es una mentira.
     *
     * Reglas que muerden aquí:
     * 1. **Causa tipificada obligatoria** del catálogo cerrado `IncidentPauseReasonCategory`
     *    (RF-01.2): la pausa la provoca siempre un bloqueo de sede, nunca el taller.
     * 2. **Justificación de al menos 20 caracteres reales** (RF-01.3, Art. V.1), validada
     *    por `IncidentPauseRequestDto` —puerta única de la pausa— y de nuevo por el
     *    agregado, para que ni el servicio ni el repositorio puedan abrir esa puerta
     *    olvidando la validación.
     * 3. **Sólo orígenes legales** `ASSIGNED`, `IN_PROGRESS`, `PENDING_PARTS` o
     *    `REOPENED`; cualquier otro estado —incluida la doble pausa
     *    `PENDING_INFO -> PENDING_INFO`— se rechaza con `InvalidTransitionException`,
     *    que el controlador traduce a `422`.
     *
     * El vencimiento contractual no se toca al pausar: `sla_target_at` viaja intacto
     * como extremo "original" de la respuesta y el desplazamiento comercial de RF-03.3
     * se aplica al cerrar el intervalo, que es cuando ya se conoce su duración exacta.
     *
     * @param int $incidentId Incidencia que se pausa.
     * @param int $actorUserId Usuario interno que declara la pausa (auditoría, Art. III).
     * @param IncidentPauseReasonCategory $reasonCategory Causa tipificada del bloqueo.
     * @param string $reasonText Justificación real (>= 20 caracteres tras recortar).
     * @return IncidentPauseResponseDto Estado de pausa y reloj congelado.
     *
     * @throws InvalidArgumentException Si los identificadores son inválidos, la causa
     *   no pertenece al catálogo o la justificación no alcanza el mínimo legal.
     * @throws \DomainException Si la incidencia no existe.
     * @throws InvalidTransitionException Si el estado de origen no admite la pausa.
     * @throws RuntimeException Si la transición no se puede persistir.
     */
    public function declarePendingInfoPause(
        int $incidentId,
        int $actorUserId,
        IncidentPauseReasonCategory $reasonCategory,
        string $reasonText
    ): IncidentPauseResponseDto {
        if ($incidentId < 1) {
            throw new InvalidArgumentException(
                'La declaración de pausa exige una incidencia persistida (id positivo).'
            );
        }

        if ($actorUserId < 1) {
            throw new InvalidArgumentException(
                'La declaración de pausa exige el usuario interno que la firma para la auditoría (Art. III).'
            );
        }

        // El DTO es la puerta única de la pausa: mide el catálogo de causas y el umbral
        // legal de 20 caracteres reales antes de que nada toque la base de datos.
        $pauseRequest = new IncidentPauseRequestDto($reasonCategory, $reasonText);
        $reason = $pauseRequest->normalizedReasonText();
        $now = new DateTimeImmutable();

        $paused = $this->transactions()->runInTransaction(function () use (
            $incidentId,
            $actorUserId,
            $reasonCategory,
            $reason,
            $now
        ): Incident {
            $incident = $this->incidents()->findById($incidentId);
            if ($incident === null) {
                throw new \DomainException(sprintf(
                    'No se encontró ninguna incidencia con ID %d para declarar la pausa por bloqueo de sede.',
                    $incidentId
                ));
            }

            $fromStatus = $incident->getStatus();
            $pausedIncident = $incident->pausePendingInfo($reasonCategory, $reason, $now);

            if (!$this->incidents()->update($pausedIncident)) {
                throw new RuntimeException(sprintf(
                    'No se pudo persistir la pausa de la incidencia %d.',
                    $incidentId
                ));
            }

            $this->incidents()->recordPauseEvent(
                $incidentId,
                $actorUserId,
                $fromStatus,
                $reasonCategory,
                $reason,
                $now
            );

            return $pausedIncident;
        });

        return new IncidentPauseResponseDto(
            incidentId: (int)$paused->getId(),
            ticketCode: $paused->getTicketCode(),
            status: $paused->getStatus(),
            isSlaPaused: true,
            accumulatedPauseMinutes: (int)round($paused->getTotalPendingInfoSeconds() / 60),
            slaTargetAtOriginal: $paused->getSlaTargetAt(),
            slaTargetAtShifted: null,
            pausedAt: $paused->getPausedAt(),
            reasonCategory: $paused->getPendingInfoReasonCategory(),
            reasonText: $paused->getPendingInfoReasonText()
        );
    }

    /**
     * Reanudación manual del intervalo de pausa por un usuario interno (RF-02.2,
     * RF-02.3, RF-03.3, Art. III).
     *
     * Cierra el intervalo vivo, acumula sus segundos exactos en el agregado, desplaza
     * el vencimiento contractual en horario comercial de la sede (Algoritmo 3) y sella
     * el rastro inmutable. A diferencia de la reactivación por comentario de sede
     * (RF-02.1), aquí no hay heurística de "calor": el destino lo decide quien reanuda
     * y es su declaración la que queda auditada.
     *
     * **Destino legal acotado:** sólo `IN_PROGRESS` (el técnico está delante de la
     * máquina) o `ASSIGNED` (debe desplazarse de nuevo). `RESOLVED` queda expresamente
     * prohibido: cerrar en falso sin intervención es la violación que persigue el
     * Art. V.1, y exige invertir la pausa con trabajo real documentado.
     *
     * @param int $incidentId Incidencia pausada que se reanuda.
     * @param int $actorUserId Usuario interno que reanuda (auditoría, Art. III).
     * @param IncidentStatus $targetStatus Estado operativo de destino (`IN_PROGRESS`/`ASSIGNED`).
     * @param string|null $resumeNote Nota opcional de la reanudación.
     * @return IncidentPauseResponseDto Estado resultante y vencimiento desplazado.
     *
     * @throws InvalidArgumentException Si los identificadores o el destino no son válidos.
     * @throws \DomainException Si la incidencia no existe.
     * @throws InvalidTransitionException Si el expediente no tiene pausa abierta que cerrar.
     * @throws RuntimeException Si la reanudación no se puede persistir.
     */
    public function resumePendingInfoManually(
        int $incidentId,
        int $actorUserId,
        IncidentStatus $targetStatus = IncidentStatus::IN_PROGRESS,
        ?string $resumeNote = null
    ): IncidentPauseResponseDto {
        if ($incidentId < 1) {
            throw new InvalidArgumentException(
                'La reanudación manual exige una incidencia persistida (id positivo).'
            );
        }

        if ($actorUserId < 1) {
            throw new InvalidArgumentException(
                'La reanudación manual exige el usuario interno que la ejecuta para la auditoría (Art. III).'
            );
        }

        if (!in_array($targetStatus, [IncidentStatus::IN_PROGRESS, IncidentStatus::ASSIGNED], true)) {
            throw new InvalidArgumentException(sprintf(
                'La reanudación de una pausa sólo puede devolver el expediente a "En curso" o "Asignada"; se recibió %s.',
                $targetStatus->value
            ));
        }

        $now = new DateTimeImmutable();

        [$resumed, $shiftedSlaTargetAt, $originalSlaTargetAt] = $this->transactions()->runInTransaction(function () use (
            $incidentId,
            $actorUserId,
            $targetStatus,
            $resumeNote,
            $now
        ): array {
            $incident = $this->incidents()->findById($incidentId);
            if ($incident === null) {
                throw new \DomainException(sprintf(
                    'No se encontró ninguna incidencia con ID %d para reanudar su pausa.',
                    $incidentId
                ));
            }

            $originalSlaTargetAt = $incident->getSlaTargetAt();
            $pauseDurationSeconds = $incident->currentPauseDurationSeconds($now);

            $note = sprintf(
                'Reanudación manual de la intervención por el usuario interno #%d. Duración del intervalo de pausa: %d s.%s',
                $actorUserId,
                $pauseDurationSeconds,
                $resumeNote !== null && trim($resumeNote) !== '' ? ' Nota: ' . trim($resumeNote) : ''
            );

            [$resumed, $shiftedSlaTargetAt] = $this->closePauseInterval(
                $incident,
                $targetStatus,
                $pauseDurationSeconds,
                $actorUserId,
                fn (?string $shiftedDeadline): string => $shiftedDeadline === null
                    ? $note
                    : $note . ' Nuevo vencimiento contractual: ' . $shiftedDeadline . '.',
                $now,
                'al reanudar manualmente la pausa'
            );

            return [$resumed, $shiftedSlaTargetAt, $originalSlaTargetAt];
        });

        return new IncidentPauseResponseDto(
            incidentId: (int)$resumed->getId(),
            ticketCode: $resumed->getTicketCode(),
            status: $targetStatus,
            isSlaPaused: false,
            accumulatedPauseMinutes: (int)round($resumed->getTotalPendingInfoSeconds() / 60),
            slaTargetAtOriginal: $originalSlaTargetAt,
            slaTargetAtShifted: $shiftedSlaTargetAt,
            pausedAt: null,
            reasonCategory: $resumed->getPendingInfoReasonCategory(),
            reasonText: $resumed->getPendingInfoReasonText()
        );
    }

    /**
     * Espera prolongada de cliente: alerta de inactividad de 72 horas HÁBILES
     * (Algoritmo 5, RF-04.2, Art. V.1).
     *
     * Mide el intervalo de pausa VIVO en horas hábiles comerciales (08:00-18:00,
     * lunes a viernes) y devuelve verdadero solo cuando supera las 72 horas. Es la
     * señal que enciende la insignia *"En espera prolongada de cliente"* y la
     * alerta prioritaria de la bandeja de triaje; la cancelación formal es una
     * decisión humana del coordinador (RF-04.3) y no se dispara sola.
     *
     * **Frontera del umbral:** el requisito ratificado habla de "más de 72 horas
     * hábiles" (RF-04.2 y RF-04.3), así que el umbral es estricto (`> 72 h`). El
     * pseudocódigo del `plan.md` §3.5 escribe `>= 72.0`; ante la discrepancia manda
     * la especificación, que es la única fuente de verdad (Art. I).
     *
     * @param Incident $incident Incidencia a evaluar.
     * @param DateTimeImmutable|null $now Instante de referencia (inyectable en pruebas).
     * @return bool True si el expediente acumula más de 72 horas hábiles en pausa.
     */
    public function isProlongedInactivity(Incident $incident, ?DateTimeImmutable $now = null): bool
    {
        return $this->isProlongedInactivitySince(
            $incident->getPausedAt(),
            $incident->isPendingInfo(),
            $now
        );
    }

    /**
     * Misma regla de inactividad prolongada sobre las columnas crudas del expediente.
     *
     * Existe porque las superficies de **lectura** (bandeja de triaje y ficha de detalle)
     * trabajan con filas asociativas de MariaDB y no hidratan un agregado `Incident` sólo
     * para pintar una insignia. La regla de negocio no se duplica: este método es la
     * única implementación del umbral y `isProlongedInactivity()` delega en él. La
     * condición `isSlaPaused` es el `isPendingInfo()` del agregado: sin intervalo de
     * pausa vivo (o fuera de `PENDING_INFO`) no hay espera de sede que medir.
     *
     * @param string|null $pausedAt Marca del intervalo de pausa vivo, en formato de base de datos.
     * @param bool $isSlaPaused True solo si el expediente está en `PENDING_INFO` con pausa abierta.
     * @param DateTimeImmutable|null $now Instante de referencia (inyectable en pruebas).
     * @return bool True si el expediente acumula más de 72 horas hábiles en pausa.
     */
    public function isProlongedInactivitySince(
        ?string $pausedAt,
        bool $isSlaPaused,
        ?DateTimeImmutable $now = null
    ): bool {
        $pauseStartedAt = $this->parseDateTime($pausedAt);
        if ($pauseStartedAt === null || !$isSlaPaused) {
            return false;
        }

        $businessSeconds = $this->countBusinessSecondsBetween(
            $pauseStartedAt,
            $now ?? new DateTimeImmutable()
        );

        return $businessSeconds > self::PROLONGED_INACTIVITY_BUSINESS_HOURS * 3600;
    }

    /**
     * Cancelación formal por inactividad de sede, con bloqueo de máquina y
     * protección del reintegro (Algoritmo 5, RF-04.3 a RF-04.5, Art. V.1 y V.2).
     *
     * El coordinador cierra a mano un expediente que lleva más de 72 horas hábiles
     * esperando a un cliente que no responde. El método ejecuta el protocolo
     * completo como UNA sola unidad de trabajo, porque cada escritura por separado
     * deja un estado mentiroso:
     *
     * 1. **Motivo justificado de 20 caracteres reales como mínimo** (Art. V.1). Un
     *    descarte sin causa es un agujero en la auditoría, no una cancelación.
     * 2. **El ticket pasa a `CANCELLED`** con su rastro inmutable en
     *    `incident_history` (Art. III), que ya escribe el repositorio.
     * 3. **La máquina NO vuelve a "Operativa":** queda bloqueada por falta de
     *    acceso (`BLOCKED_NO_ACCESS`) e inactiva. Si nunca se pudo acceder a
     *    reparar el fallo, pintarla en verde engañaría a los usuarios del inmueble
     *    (RF-04.4). La vuelta al parque exige confirmación de acceso y aviso nuevo
     *    (RF-04.6).
     * 4. **El reintegro económico no se cancela:** se desvincula de la avería
     *    (`incident_id = NULL`) y queda en la bandeja de Coordinación para
     *    liquidación central, de modo que el consumidor final cobra su dinero
     *    (RF-04.5).
     *
     * La cancelación exige que el expediente esté realmente en `PENDING_INFO`: es
     * la única situación en la que "cancelar por falta de acceso" describe lo que
     * pasó. Cualquier otro estado se rechaza con `InvalidTransitionException` en
     * lugar de bloquear una máquina por un motivo que no ocurrió.
     *
     * @param int $incidentId Incidencia a cancelar.
     * @param int $coordinatorUserId Coordinador que ejecuta la cancelación (auditoría).
     * @param string $cancellationReason Motivo justificado (>= 20 caracteres reales).
     * @return void
     *
     * @throws InvalidArgumentException Si los identificadores o el motivo no son válidos.
     * @throws \DomainException Si la incidencia o su máquina no existen.
     * @throws InvalidTransitionException Si el expediente no está en `PENDING_INFO`.
     * @throws RuntimeException Si alguna de las escrituras del protocolo no se puede persistir.
     */
    public function cancelByInactivity(
        int $incidentId,
        int $coordinatorUserId,
        string $cancellationReason
    ): void {
        if ($incidentId < 1) {
            throw new InvalidArgumentException(
                'La cancelación por inactividad exige una incidencia persistida (id positivo).'
            );
        }

        if ($coordinatorUserId < 1) {
            throw new InvalidArgumentException(
                'La cancelación por inactividad exige el coordinador que la ejecuta para la auditoría (Art. III).'
            );
        }

        $reason = trim($cancellationReason);
        if (mb_strlen($reason) < self::MIN_CANCELLATION_REASON_LENGTH) {
            throw new InvalidArgumentException(sprintf(
                'El motivo de cancelación por inactividad debe contener al menos %d caracteres reales (Art. V.1); se recibieron %d.',
                self::MIN_CANCELLATION_REASON_LENGTH,
                mb_strlen($reason)
            ));
        }

        $this->transactions()->runInTransaction(function () use ($incidentId, $coordinatorUserId, $reason): void {
            $incident = $this->incidents()->findById($incidentId);
            if ($incident === null) {
                throw new \DomainException(sprintf(
                    'No se encontró ninguna incidencia con ID %d para cancelar por inactividad de sede.',
                    $incidentId
                ));
            }

            if (!$incident->isPendingInfo()) {
                throw new InvalidTransitionException(
                    sprintf(
                        'Solo se cancela por inactividad un expediente en pausa a la espera de la sede; la incidencia %s está en estado %s.',
                        (string)$incident->getTicketCode(),
                        $incident->getStatus()->value
                    ),
                    $incident->getStatus(),
                    IncidentStatus::CANCELLED
                );
            }

            $ticketCode = (string)$incident->getTicketCode();
            $machineId = $incident->getMachineId();
            $pausedBusinessSeconds = $this->countBusinessSecondsBetween(
                $this->parseDateTime($incident->getPausedAt()) ?? new DateTimeImmutable(),
                new DateTimeImmutable()
            );

            // 1. Cancelación formal del ticket (con su rastro en `incident_history`).
            $this->incidents()->cancel($incidentId, $reason, $coordinatorUserId);

            // 2. Protección del parque: la máquina no vuelve a "Operativa" (RF-04.4).
            if (!$this->machines()->blockForNoAccess($machineId, $ticketCode)) {
                throw new RuntimeException(sprintf(
                    'No se pudo bloquear por falta de acceso la máquina %d tras cancelar la incidencia %s (RF-04.4).',
                    $machineId,
                    $ticketCode
                ));
            }

            $this->audit()->logMachineEvent(
                $machineId,
                // Literal deliberado: la guarda del catálogo de auditoría enumera
                // las acciones escritas como cadena literal (ver T-PAUSE-07).
                'MACHINE_BLOCKED_NO_ACCESS',
                $this->coordinatorActor($coordinatorUserId),
                ['operational_status' => 'ACTIVE_INCIDENT', 'is_active' => true, 'is_blocked_no_access' => false],
                ['operational_status' => 'BLOCKED_NO_ACCESS', 'is_active' => false, 'is_blocked_no_access' => true],
                [
                    'incident_id' => $incidentId,
                    'ticket_code' => $ticketCode,
                    'machine_id' => $machineId,
                    'paused_business_seconds' => $pausedBusinessSeconds,
                    'threshold_business_seconds' => self::PROLONGED_INACTIVITY_BUSINESS_HOURS * 3600,
                    'reason' => 'Máquina fuera de servicio por falta de acceso tras cancelación por inactividad de sede (Art. V.1).',
                ]
            );

            // 3. El consumidor final no pierde su dinero (RF-04.5).
            $this->detachRefundsForInactivity($incidentId, $coordinatorUserId, $ticketCode);
        });
    }

    /**
     * Zona horaria operativa de la sede.
     */
    private function madridTimezone(): DateTimeZone
    {
        return new DateTimeZone(self::OPERATIONAL_TIMEZONE);
    }

    /**
     * Indica si la fecha cae en día hábil (lunes a viernes).
     */
    private function isBusinessDay(DateTimeImmutable $moment): bool
    {
        return (int)$moment->format('N') <= self::LAST_BUSINESS_WEEKDAY;
    }

    /**
     * Segundos transcurridos desde la medianoche del día de la fecha dada.
     */
    private function secondOfDay(DateTimeImmutable $moment): int
    {
        return ((int)$moment->format('G')) * 3600
            + ((int)$moment->format('i')) * 60
            + (int)$moment->format('s');
    }

    /**
     * Apertura (08:00) del siguiente día hábil, saltando el fin de semana.
     *
     * Se construye siempre a partir de una fecha con la hora fijada, y los saltos
     * son de días completos a la misma hora de pared, de modo que el cambio de
     * horario estacional (madrugada de domingo) no altera la apertura comercial.
     */
    private function openingOfNextBusinessDay(DateTimeImmutable $moment): DateTimeImmutable
    {
        $opening = $moment->modify('+1 day')->setTime(self::BUSINESS_OPEN_HOUR, 0, 0);

        while (!$this->isBusinessDay($opening)) {
            $opening = $opening->modify('+1 day');
        }

        return $opening;
    }

    /**
     * Repositorio de ajustes preventivos, resuelto de forma perezosa.
     *
     * La instanciación diferida mantiene hermética la aritmética de calendario: un
     * `new IncidentPauseService()` no abre ninguna conexión PDO hasta que el reloj
     * sanitario la necesita de verdad.
     */
    private function settings(): PreventiveSettingsRepositoryInterface
    {
        return $this->settingsRepo ??= new PdoPreventiveSettingsRepository();
    }

    /**
     * Registrador de auditoría inmutable, resuelto de forma perezosa (misma razón
     * que `settings()`).
     */
    private function audit(): AuditLogger
    {
        return $this->auditLogger ??= new AuditLogger();
    }

    /**
     * Interpreta una marca temporal persistida (`Y-m-d H:i:s`) como hora local de la
     * sede, que es la zona en la que el sistema escribe las fechas.
     */
    private function parseDateTime(?string $value): ?DateTimeImmutable
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($value, $this->madridTimezone());
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * Repositorio de incidencias, resuelto de forma perezosa (misma razón que los
     * colaboradores sanitarios: la aritmética de calendario no debe abrir conexiones).
     */
    private function incidents(): IncidentRepositoryInterface
    {
        return $this->incidentRepo ??= new PdoIncidentRepository();
    }

    /**
     * Repositorio de máquinas, resuelto de forma perezosa: el bloqueo por falta de
     * acceso se escribe aquí (RF-04.4) y la calculadora de calendario sigue sin E/S.
     */
    private function machines(): MachineRepositoryInterface
    {
        return $this->machineRepo ??= new PdoMachineRepository();
    }

    /**
     * Repositorio de reintegros, resuelto de forma perezosa (RF-04.5).
     */
    private function refunds(): RefundRequestRepositoryInterface
    {
        return $this->refundRepo ??= new PdoRefundRequestRepository();
    }

    /**
     * Unidad de trabajo transaccional (Art. III): el protocolo de cancelación toca
     * tres agregados y no puede quedar a medias.
     */
    private function transactions(): TransactionManagerInterface
    {
        return $this->transactionManager ??= new PdoTransactionManager();
    }

    /**
     * ¿Tiene el técnico asignado otra avería en intervención activa en este momento?
     *
     * Sólo cuenta `IN_PROGRESS`: es lo único que prueba que el profesional está
     * físicamente trabajando en otra máquina. `PENDING_PARTS` significa esperando un
     * repuesto, no delante de un equipo, así que no impide reanudar en curso.
     */
    private function technicianHasAnotherActiveIntervention(int $technicianId, int $currentIncidentId): bool
    {
        foreach ($this->incidents()->findAssignedToTechnician(
            $technicianId,
            [IncidentStatus::IN_PROGRESS->value]
        ) as $incident) {
            if ($incident->getId() !== null && $incident->getId() !== $currentIncidentId) {
                return true;
            }
        }

        return false;
    }

    /**
     * ¿Se reasignó la avería a otro técnico mientras estaba en pausa? (RF-02.1)
     *
     * Marcador estructural: la reasignación conserva el estado operativo (no lo
     * retrocede ni reescribe `assigned_at`), de modo que deja en `incident_history` una
     * fila con origen y destino `PENDING_INFO` posterior al inicio de la pausa. Ninguna
     * otra transición del expediente produce esa fila.
     *
     * **Alcance real hoy:** `PdoIncidentRepository::assign()` todavía rechaza reasignar
     * un ticket en `PENDING_INFO` (RF-06.3 es alcance de T-PAUSE-13/19), así que esta
     * comprobación siempre devuelve `false` en producción mientras esa puerta no se
     * abra. Se implementa ya para que la regla muerda en cuanto exista la reasignación
     * en pausa y para no dejar el criterio a medias.
     */
    private function wasReassignedDuringCurrentPause(Incident $incident): bool
    {
        $pausedAt = $this->parseDateTime($incident->getPausedAt());
        if ($pausedAt === null || $incident->getId() === null) {
            return false;
        }

        foreach ($this->incidents()->getHistory($incident->getId()) as $event) {
            if ($event->getFromStatus() !== IncidentStatus::PENDING_INFO->value
                || $event->getToStatus() !== IncidentStatus::PENDING_INFO->value
            ) {
                continue;
            }

            $occurredAt = $this->parseDateTime($event->getCreatedAt());
            if ($occurredAt !== null && $occurredAt >= $pausedAt) {
                return true;
            }
        }

        return false;
    }

    /**
     * Cierra el intervalo de pausa vivo: transición, acumulación de segundos, desplazamiento
     * comercial del vencimiento y rastro inmutable en `incident_history` (RF-02.4, RF-03.3, Art. III).
     *
     * Los dos caminos que reanudan una pausa —la reactivación automática por comentario de
     * sede y la reanudación manual de técnico o coordinador— comparten este cierre para que
     * la duración auditada, el acumulador descontable por MTTR (RF-04.1) y el vencimiento
     * desplazado no puedan divergir entre caminos. La única diferencia es quién queda
     * anotado como actor y qué nota viaja al historial.
     *
     * El desplazamiento se calcula DESPUÉS de cerrar el intervalo porque necesita la
     * duración ya consolidada y el calendario de la sede; si el expediente no tenía
     * compromiso de SLA, ambas fechas viajan nulas sin fingir un vencimiento.
     *
     * @param Incident $incident Expediente pausado que se reanuda.
     * @param IncidentStatus $targetStatus Estado operativo de destino.
     * @param int $pauseDurationSeconds Segundos exactos del intervalo que se cierra.
     * @param int|null $actorUserId Usuario interno que reanuda, o `null` si es la sede.
     * @param callable(string|null): string $note Constructor de la nota inmutable; recibe el
     *   vencimiento ya desplazado (o `null` sin compromiso de SLA) para poder documentarlo.
     * @param DateTimeImmutable $now Instante de la reanudación.
     * @param string $failureContext Contexto del error de persistencia para el diagnóstico.
     * @return array{0: Incident, 1: string|null} Expediente reanudado y nuevo vencimiento (nulo sin SLA).
     *
     * @throws InvalidTransitionException Si no hay pausa abierta o el destino no es legal.
     * @throws RuntimeException Si la transición no se puede persistir.
     */
    private function closePauseInterval(
        Incident $incident,
        IncidentStatus $targetStatus,
        int $pauseDurationSeconds,
        ?int $actorUserId,
        callable $note,
        DateTimeImmutable $now,
        string $failureContext
    ): array {
        $originalSlaTargetAt = $incident->getSlaTargetAt();
        $shiftedSlaTargetAt = null;

        // El agregado cierra el intervalo y acumula sus segundos; la fecha límite se
        // graba aparte porque el desplazamiento comercial depende del calendario de sede.
        $resumed = $incident->resumePendingInfo($targetStatus, $now);

        if ($originalSlaTargetAt !== null) {
            $originalDeadline = $this->parseDateTime($originalSlaTargetAt);
            if ($originalDeadline !== null) {
                $shiftedDeadline = $this->shiftSlaTargetInBusinessHours(
                    $originalDeadline,
                    $pauseDurationSeconds,
                    $resumed->getLocationId()
                );
                $shiftedSlaTargetAt = $shiftedDeadline->format('Y-m-d H:i:s');
                $resumed = $resumed->shiftSlaTarget($shiftedDeadline);
            }
        }

        if (!$this->incidents()->update($resumed)) {
            throw new RuntimeException(sprintf(
                'No se pudo persistir la reanudación de la incidencia %d %s.',
                (int)$resumed->getId(),
                $failureContext
            ));
        }

        $this->incidents()->recordResumeEvent(
            (int)$resumed->getId(),
            $actorUserId,
            $targetStatus,
            $pauseDurationSeconds,
            $note($shiftedSlaTargetAt),
            $now
        );

        return [$resumed, $shiftedSlaTargetAt];
    }

    /**
     * Proyección del estado real del expediente cuando no procede reactivar nada: un
     * comentario sin sustancia, un expediente que no está en pausa o la segunda llamada
     * de una ráfaga. La cifra acumulada incluye el intervalo vivo para que el reloj
     * congelado que pinta la interfaz no pierda la espera en curso.
     */
    private function buildCurrentStateResponse(Incident $incident, DateTimeImmutable $now): IncidentPauseResponseDto
    {
        $liveSeconds = $incident->isPaused() ? $incident->currentPauseDurationSeconds($now) : 0;

        return new IncidentPauseResponseDto(
            incidentId: (int)$incident->getId(),
            ticketCode: $incident->getTicketCode(),
            status: $incident->getStatus(),
            isSlaPaused: $incident->isPendingInfo(),
            accumulatedPauseMinutes: (int)round(($incident->getTotalPendingInfoSeconds() + $liveSeconds) / 60),
            slaTargetAtOriginal: $incident->getSlaTargetAt(),
            slaTargetAtShifted: $incident->getSlaTargetAt(),
            pausedAt: $incident->getPausedAt(),
            reasonCategory: $incident->getPendingInfoReasonCategory(),
            reasonText: $incident->getPendingInfoReasonText()
        );
    }

    /**
     * Segundos hábiles comerciales transcurridos entre dos instantes (RF-04.2).
     *
     * Suma exclusivamente el tiempo que cae dentro de la ventana de la sede
     * (08:00-18:00, lunes a viernes) en la zona horaria operativa, recorriendo el
     * calendario día a día. Es el gemelo de `shiftSlaTargetInBusinessHours()`:
     * aquel desplaza un vencimiento sumando segundos hábiles y éste los cuenta.
     *
     * @param DateTimeImmutable $from Inicio del intervalo.
     * @param DateTimeImmutable $to Fin del intervalo.
     * @return int Segundos hábiles; cero si el intervalo está vacío o invertido.
     */
    private function countBusinessSecondsBetween(DateTimeImmutable $from, DateTimeImmutable $to): int
    {
        $cursor = $from->setTimezone($this->madridTimezone());
        $end = $to->setTimezone($this->madridTimezone());

        if ($end <= $cursor) {
            return 0;
        }

        $total = 0;

        while ($cursor < $end) {
            if ($this->isBusinessDay($cursor)) {
                $dayOpen = $cursor->setTime(self::BUSINESS_OPEN_HOUR, 0, 0);
                $dayClose = $cursor->setTime(self::BUSINESS_CLOSE_HOUR, 0, 0);

                // Ventana recortada por los extremos reales del intervalo: ni el
                // primer día se cuenta desde las 08:00 si la pausa empezó a las
                // 10:00, ni el último se cuenta hasta las 18:00 si aún es mediodía.
                $windowStart = $cursor > $dayOpen ? $cursor : $dayOpen;
                $windowEnd = $end < $dayClose ? $end : $dayClose;

                if ($windowEnd > $windowStart) {
                    $total += $windowEnd->getTimestamp() - $windowStart->getTimestamp();
                }
            }

            // Normalizar al arranque del día siguiente es lo que evita perder la
            // primera hora de cada jornada: un cursor a las 09:00 saltaría, si no,
            // a las 09:00 del día siguiente y se comería las 08:00.
            $cursor = $cursor->modify('+1 day')->setTime(0, 0, 0);
        }

        return $total;
    }

    /**
     * Actor de auditoría del coordinador que ejecuta la cancelación (Art. III).
     *
     * La operación llega con el identificador del usuario y no con su nombre, así
     * que se sella con el rol y su etiqueta: la auditoría queda anónima a nivel de
     * persona pero siempre trazable a la cuenta responsable.
     *
     * @param int $coordinatorUserId Usuario que ejecuta.
     * @return array{id: int, role: string, name: string}
     */
    private function coordinatorActor(int $coordinatorUserId): array
    {
        return [
            'id' => $coordinatorUserId,
            'role' => UserRole::COORDINATOR->value,
            'name' => UserRole::COORDINATOR->label(),
        ];
    }

    /**
     * Desvincula los expedientes de reintegro de la avería cancelada (RF-04.5).
     *
     * El dinero retenido por el consumidor NO se pierde porque la avería técnica
     * se cierre: la reclamación se desvincula del ticket (`incident_id = NULL`) y
     * el expediente que aún esperaba la inspección técnica —la que nunca podrá
     * ocurrir, porque la máquina queda bloqueada— pasa a la bandeja de
     * Coordinación para su liquidación central. Los expedientes que ya tenían su
     * propio camino (efectivo depositado con PIN, contacto pendiente, pago en
     * curso o estados terminales) conservan su estado: reiniciarlos abriría de
     * nuevo una reclamación ya cerrada o borraría un PIN ya entregado.
     *
     * @param int $incidentId Avería cancelada.
     * @param int $coordinatorUserId Coordinador que ejecuta.
     * @param string $ticketCode Código visible del expediente, para la auditoría.
     * @return void
     * @throws RuntimeException Si un expediente no se puede desvincular.
     */
    private function detachRefundsForInactivity(int $incidentId, int $coordinatorUserId, string $ticketCode): void
    {
        $cases = $this->refunds()->findRestrictedByIncident($incidentId);
        if ($cases === []) {
            return;
        }

        $actor = $this->coordinatorActor($coordinatorUserId);

        /** @var RefundRequest $case */
        foreach ($cases as $case) {
            $caseId = $case->getId();
            if ($caseId === null || !$case->isActive()) {
                continue;
            }

            $previousStatus = $case->getStatus();
            $targetStatus = $previousStatus === RefundStatus::PENDING_INSPECTION
                ? RefundStatus::REQUIRES_COORDINATOR_APPROVAL
                : $previousStatus;

            if (!$this->refunds()->detachFromIncident($caseId, $targetStatus)) {
                throw new RuntimeException(sprintf(
                    'No se pudo desvincular el expediente de reintegro %d de la avería %s cancelada por inactividad (RF-04.5).',
                    $caseId,
                    $ticketCode
                ));
            }

            $this->audit()->logRefundEvent(
                $caseId,
                // Literal deliberado: la guarda del catálogo de auditoría solo
                // enumera acciones escritas como cadena literal (ver T-PAUSE-07).
                'REFUND_DETACHED_BY_INACTIVITY',
                $actor,
                ['status' => $previousStatus->value, 'incident_id' => $incidentId],
                ['status' => $targetStatus->value, 'incident_id' => null],
                [
                    'ticket_code' => $ticketCode,
                    'machine_id' => $case->getMachineId(),
                    'reason' => 'Reintegro desvinculado de una avería cancelada por inactividad de sede; se mantiene para custodia y liquidación central (RF-04.5).',
                ]
            );
        }
    }

    /**
     * Nota inmutable que acompaña a la reanudación automática.
     *
     * Se evita a propósito el marcador canónico ` Motivo: ` que
     * `CoordinatorIncidentDetailService` lee como motivo de reasignación: reutilizarlo
     * contaminaría el bloque de técnico del modal de detalle.
     */
    private function reactivationNote(
        IncidentStatus $targetStatus,
        int $pauseDurationSeconds,
        bool $technicianIsBusy,
        bool $reassignedDuringPause,
        bool $withoutTechnician,
        int $siteUserId,
        ?string $originalSlaTargetAt,
        ?string $shiftedSlaTargetAt
    ): string {
        $note = sprintf(
            'Reactivación automática por comentario público de sede (referencia del actor de sede #%d) tras %d min en pausa. ',
            $siteUserId,
            intdiv($pauseDurationSeconds, 60)
        );

        if ($targetStatus === IncidentStatus::IN_PROGRESS) {
            $note .= 'Respuesta en caliente con el técnico asignado libre: la intervención se reanuda en curso.';
        } else {
            $causes = [];
            if ($pauseDurationSeconds >= self::HOT_REACTIVATION_WINDOW_SECONDS) {
                $causes[] = 'la respuesta llegó fuera de la ventana de 60 minutos';
            }
            if ($technicianIsBusy) {
                $causes[] = 'el técnico asignado ya tenía otra intervención en curso';
            }
            if ($reassignedDuringPause) {
                $causes[] = 'la avería fue reasignada durante la pausa';
            }
            if ($withoutTechnician) {
                $causes[] = 'la avería quedó sin técnico responsable';
            }

            $note .= 'La avería se devuelve a asignada para un inicio presencial';
            $note .= $causes !== [] ? ': ' . implode('; ', $causes) . '.' : '.';
        }

        if ($originalSlaTargetAt !== null && $shiftedSlaTargetAt !== null) {
            $note .= sprintf(
                ' SLA contractual desplazado de %s a %s en horario comercial de la sede.',
                $originalSlaTargetAt,
                $shiftedSlaTargetAt
            );
        }

        return $note;
    }
}
