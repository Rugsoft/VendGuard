<?php

declare(strict_types=1);

namespace VendGuard\Application\Service;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use RuntimeException;
use VendGuard\Core\Domain\Model\Incident;
use VendGuard\Core\Domain\Model\MachineType;
use VendGuard\Core\Domain\Repository\PreventiveSettingsRepositoryInterface;
use VendGuard\Core\Domain\ValueObject\IncidentStatus;
use VendGuard\Infrastructure\Repository\PdoPreventiveSettingsRepository;

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

    /** Repositorio de ajustes preventivos; se resuelve en el primer uso si no se inyecta. */
    private ?PreventiveSettingsRepositoryInterface $settingsRepo;

    /** Registrador de auditoría inmutable; se resuelve en el primer uso si no se inyecta. */
    private ?AuditLogger $auditLogger;

    public function __construct(
        ?PreventiveSettingsRepositoryInterface $settingsRepo = null,
        ?AuditLogger $auditLogger = null
    ) {
        $this->settingsRepo = $settingsRepo;
        $this->auditLogger = $auditLogger;
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
}
