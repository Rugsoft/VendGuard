<?php

declare(strict_types=1);

namespace VendGuard\Application\Service;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use InvalidArgumentException;
use PDOException;
use RuntimeException;
use Throwable;
use VendGuard\Application\DTO\SanitaryClockSweepResultDto;
use VendGuard\Core\Domain\Model\Incident;
use VendGuard\Core\Domain\Model\SanitarySweepPass;
use VendGuard\Core\Domain\Repository\LockWaitBudgetInterface;
use VendGuard\Core\Domain\Repository\SanitaryClockCandidateRepositoryInterface;
use VendGuard\Core\Domain\Repository\SanitarySweepVisibilityRepositoryInterface;
use VendGuard\Core\Domain\Repository\TransactionManagerInterface;
use VendGuard\Core\Service\SanitarySweepEscalationPolicy;
use VendGuard\Infrastructure\Database\PdoLockWaitBudget;
use VendGuard\Infrastructure\Database\PdoTransactionManager;
use VendGuard\Infrastructure\Repository\PdoIncidentRepository;
use VendGuard\Infrastructure\Repository\PdoSanitarySweepVisibilityRepository;

/**
 * SanitaryClockSweepService — Caso de uso del **barrido de fondo** del Reloj Sanitario
 * Biológico (T-PAUSE-38, RF-03.5.1, RF-03.4, Constitución Art. II y Art. III).
 *
 * Problema que resuelve: el reloj se consumía únicamente en la puerta sanitaria del
 * cierre técnico (T-PAUSE-22), de modo que la activación automática de RF-03.5.1 sólo
 * ocurría **cuando alguien intentaba cerrar** la avería. Entre las 4 h naturales y ese
 * primer intento la máquina se publicaba como operativa, y el escaneo ciudadano seguía
 * ofreciendo el canal de reporte sobre un recinto refrigerado sin frío confirmado. Este
 * servicio es el consumidor que cierra ese hueco sin cambiar una sola regla de negocio:
 * recorre los candidatos que publica el puerto segregado y entrega cada uno al MISMO
 * reloj, que sigue siendo la única autoridad sobre la decisión.
 *
 * Reglas que respeta este caso de uso:
 * 1. **No reimplementa nada.** No compara umbrales, no interpreta tipologías y no
 *    escribe estados: sólo selecciona candidatos y consume el reloj. El umbral viaja
 *    al puerto tomado de `IncidentPauseService::SANITARY_BIOLOGICAL_CLOCK_SECONDS`,
 *    para que no exista una segunda copia del límite de 4 h.
 * 2. **Una unidad de trabajo por candidato (Art. III).** La transición del estado
 *    sanitario y su evento inmutable caen juntos, y el fallo de una máquina no
 *    deshace ni arrastra a las demás: la evidencia de las ya procesadas queda firme.
 * 3. **Aislamiento con voz.** Un expediente indecidible (tipología o apertura
 *    ilegibles) o con la escritura caída se captura en `failures` con su motivo y se
 *    publica en el resumen. No se traga nunca —el CLI termina en rojo— pero tampoco
 *    aborta el lote: la salvaguarda sanitaria es de todas las máquinas, no de la
 *    primera que tropiece (Art. II).
 * 4. **La contención de fila no es un fallo (T-PAUSE-39).** Cuando otro consumidor
 *    —el cierre técnico, otro barrido o cualquier escritor del parque— sostiene el
 *    bloqueo exclusivo de la máquina, la transición muere esperando el lock
 *    (`55P03`: lo cancela la cota de la pasada, no el tenedor; `40001`/`40P01`: fallo de
 *    serialización e interbloqueo). Eso NO se cuenta como fallo: es la prueba de que
 *    alguien está actuando sobre esa fila ahora mismo, y el reloj ya garantiza que el
 *    rastro siga siendo uno. Viaja en `contended` y se resuelve en la pasada siguiente
 *    (el prefiltro vuelve a ofrecerlo). La clasificación es **estricta y fail-safe**:
 *    sólo se aplaza con un código estructurado de bloqueo; ante cualquier otro error de
 *    persistencia —o ante la duda— la pasada alerta como fallo real, porque un problema
 *    que se disfraza de contención es peor que un aviso de más.
 * 5. **Idempotencia de verdad.** Repetir la pasada no duplica cuarentenas ni rastros,
 *    porque la decisión y la exclusión mutua viven en el reloj y en su transición
 *    idempotente. Este servicio puede, por tanto, ejecutarse con la cadencia que fije
 *    el planificador sin ningún efecto acumulativo.
 * 6. **La pasada no se queda esperando a nadie (T-PAUSE-40).** Una fila que otro
 *    consumidor sostiene no acapara la pasada: se corre con una cota de espera de
 *    bloqueo corta (`LOCK_WAIT_BUDGET_SECONDS`, restituida al terminar), de modo que
 *    el tenedor legítimamente corto se resuelve esperando —medido: la transacción
 *    ganadora del cierre tarda milisegundos— y el que se alarga aparece como
 *    aplazamiento visible en lugar de bloquear el lote hasta el valor del servidor.
 * 7. **La pasada se publica para la operación (T-PAUSE-41).** Cada pasada deja su
 *    libro —apertura, cierre, recuentos y duración— y cada aplazamiento deja un
 *    seguimiento por expediente con sus pasadas consecutivas, para que la
 *    Coordinación pueda ver la contención persistente sin leer la respuesta del
 *    planificador (RF-07). El libro se escribe **después** de consumir el reloj:
 *    la salvaguarda sanitaria no puede depender de una tabla de observabilidad
 *    (Art. II). Si esa escritura falla, el fallo viaja en voz alta y las
 *    cuarentenas ya confirmadas quedan firmes e idempotentes. La pasada cierra los
 *    seguimientos que **ella misma** no aplazó —por identidad, no por marca de
 *    tiempo—, de modo que el cierre es exacto aunque dos pasadas caigan en el
 *    mismo segundo. Escribir el rastro de un aplazamiento tampoco puede
 *    convertirlo en un fallo: si esa escritura choca con el mismo bloqueo que
 *    provocó el aplazamiento, la pasada sigue, el aplazamiento queda publicado
 *    con su motivo y el prefiltro vuelve a ofrecer el candidato en la pasada
 *    siguiente (cualquier otro fallo de observabilidad sí viaja en voz alta).
 *
 * Dogma Vanilla: PHP 8.2+ puro, tipado estricto, cero dependencias externas.
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */
final class SanitaryClockSweepService
{
    /**
     * Cota por defecto del lote: acota el coste de una pasada sin dejar fuera del
     * barrido a un parque real (el prefiltro sólo publica expedientes vencidos y sin
     * cuarentena, que en operación normal son unos pocos).
     */
    public const DEFAULT_CANDIDATE_LIMIT = 500;

    /**
     * Cota de espera de bloqueo de la pasada, en segundos (T-PAUSE-40).
     *
     * El valor sale de medir el tenedor legítimo, no de elegirlo a ojo: la unidad de
     * trabajo ganadora del cierre —la que sostiene la fila— tarda p50 1,68 ms y
     * p95 2,54 ms sobre 120 ejecuciones reales, así que 2 s son ~787× el p95, muy por
     * debajo del valor de servidor (50 s) y suficiente para que ninguna contención
     * legítima se convierta en aplazamiento. El detalle y las decisiones abiertas
     * están en `docs/propuesta_politica_reintento_barrido_sanitario.md`.
     */
    public const LOCK_WAIT_BUDGET_SECONDS = 2;

    /**
     * Zona operativa del sistema: la misma que usan el reloj y la auditoría, para
     * que las marcas del libro de pasadas sean comparables con el resto del dominio.
     */
    private const OPERATIONAL_TIMEZONE = 'Europe/Madrid';

    /**
     * Códigos de driver que significan «otro consumidor tiene la fila» en el motor retirado:
     * `ER_LOCK_WAIT_TIMEOUT` (1205) y `ER_LOCK_DEADLOCK` (1213).
     *
     * Se conservan como **tolerancia declarada** —ningún motor del sistema los emite ya, porque
     * el motor único declara la misma situación por su `SQLSTATE` (`CONTENTION_SQL_STATES`)— y
     * mueren con la rama heredada de la factoría (T-13-14), no con este servicio.
     */
    private const CONTENTION_DRIVER_CODES = [1205, 1213];

    /**
     * Estados SQL de bloqueo/serialización: `40001` (serialization failure), `40P01` (deadlock)
     * y **`55P03`** (`lock_not_available`: la espera por el bloqueo ajeno agotó su cota, que es
     * como el motor único manifiesta el `1205` del retirado —el `lock_timeout` de la pasada es
     * quien cancela la espera—). Sólo se consultan como respaldo del código de driver.
     */
    private const CONTENTION_SQL_STATES = ['40001', '40P01', '55P03'];

    /** Puerto de candidatos; se resuelve en el primer uso si no se inyecta. */
    private ?SanitaryClockCandidateRepositoryInterface $candidateRepo;

    /** Reloj sanitario; se resuelve en el primer uso si no se inyecta. */
    private ?IncidentPauseService $pauseService;

    /** Unidad de trabajo transaccional; se resuelve en el primer uso si no se inyecta. */
    private ?TransactionManagerInterface $transactionManager;

    /** Cota de espera de bloqueo de la pasada; se resuelve en el primer uso si no se inyecta. */
    private ?LockWaitBudgetInterface $lockWaitBudget;

    /** Libro de pasadas y seguimientos de contención (T-PAUSE-41); se resuelve en el primer uso. */
    private ?SanitarySweepVisibilityRepositoryInterface $visibilityRepo;

    /** Regla del escalado, resuelta en el primer uso si no se inyecta. */
    private ?SanitarySweepEscalationPolicy $escalationPolicy;

    /**
     * Todos los colaboradores son opcionales y se resuelven de forma perezosa, igual
     * que en `IncidentPauseService`: el servicio es construible sin base de datos y el
     * cableado de producción toma los repositorios PDO por defecto.
     */
    public function __construct(
        ?SanitaryClockCandidateRepositoryInterface $candidateRepo = null,
        ?IncidentPauseService $pauseService = null,
        ?TransactionManagerInterface $transactionManager = null,
        ?LockWaitBudgetInterface $lockWaitBudget = null,
        ?SanitarySweepVisibilityRepositoryInterface $visibilityRepo = null,
        ?SanitarySweepEscalationPolicy $escalationPolicy = null
    ) {
        $this->candidateRepo = $candidateRepo;
        $this->pauseService = $pauseService;
        $this->transactionManager = $transactionManager;
        $this->lockWaitBudget = $lockWaitBudget;
        $this->visibilityRepo = $visibilityRepo;
        $this->escalationPolicy = $escalationPolicy;
    }

    /**
     * Ejecuta una pasada del barrido y devuelve su resumen canónico.
     *
     * @param int $limit Cota superior de candidatos a evaluar en esta pasada.
     * @return SanitaryClockSweepResultDto Resumen con candidatos, disparos, saltos y fallos.
     *
     * @throws InvalidArgumentException Si la cota no es un valor positivo.
     * @throws \Throwable Cualquier fallo de infraestructura NO contemplado (la excepción
     *                    de dominio o de persistencia de un candidato se aísla en el
     *                    resumen, pero un error de programación no se disfraza de fallo
     *                    de máquina).
     */
    public function sweep(int $limit = self::DEFAULT_CANDIDATE_LIMIT): SanitaryClockSweepResultDto
    {
        if ($limit < 1) {
            throw new InvalidArgumentException(
                'La cota del barrido sanitario debe ser de al menos un expediente (Art. II).'
            );
        }

        // La pasada entera corre bajo una cota corta de espera de bloqueo (T-PAUSE-40):
        // un tenedor que se alarga se convierte en aplazamiento visible en lugar de
        // dejar el lote parado hasta el valor del servidor. La validación de arriba
        // ocurre ANTES de tocar la sesión (Fail-Fast): una cota imposible no llega a la
        // base de datos.
        return $this->lockBudget()->runWithLockWaitBudget(
            self::LOCK_WAIT_BUDGET_SECONDS,
            fn (): SanitaryClockSweepResultDto => $this->sweepCandidates($limit)
        );
    }

    /**
     * Pasada propiamente dicha: publica candidatos y consume el reloj uno a uno.
     *
     * Vive separada del método público para que `sweep()` pueda envolverla en la cota
     * de espera de bloqueo sin que la regla de negocio cambie una coma.
     */
    private function sweepCandidates(int $limit): SanitaryClockSweepResultDto
    {
        $startedAt = microtime(true);
        // Instante de arranque de la pasada (T-PAUSE-41). Se captura ANTES de tocar la
        // base y es la marca de apertura del libro y el instante de cada detección; el
        // cierre de los seguimientos no depende de él —se cierra por identidad: los
        // expedientes que ESTA pasada no aplazó— precisamente porque las marcas tienen
        // resolución de segundo y dos pasadas pueden caer dentro del mismo.
        $passStartedAt = self::now();
        $thresholdSeconds = IncidentPauseService::SANITARY_BIOLOGICAL_CLOCK_SECONDS;

        $candidates = $this->candidates()->findSanitaryClockCandidates($thresholdSeconds, $limit);

        /** @var list<array{incident_id: int|null, ticket_code: string, machine_id: int}> $quarantined */
        $quarantined = [];

        /** @var list<array{incident_id: int|null, ticket_code: string, reason: string}> $failures */
        $failures = [];

        /** @var list<array{incident_id: int|null, ticket_code: string, reason: string}> $contended */
        $contended = [];

        $evaluated = 0;

        foreach ($candidates as $incident) {
            try {
                $isQuarantined = $this->transactions()->runInTransaction(
                    fn (): bool => $this->pauses()->evaluateSanitaryBiologicalClock($incident)
                );

                $evaluated++;

                if ($isQuarantined) {
                    $quarantined[] = [
                        'incident_id' => $incident->getId(),
                        'ticket_code' => (string)$incident->getTicketCode(),
                        'machine_id' => $incident->getMachineId(),
                    ];
                }
            } catch (DomainException | RuntimeException $exception) {
                // Aislamiento con voz: ni el fallo de una máquina ni la contención de su
                // fila detienen el barrido de las demás. Cada uno viaja en SU bucket del
                // resumen con su motivo y su expediente: el aplazamiento por contención es
                // operación normal (se reintenta en la pasada siguiente) y el fallo real es
                // lo único que enciende al planificador.
                $entry = [
                    'incident_id' => $incident->getId(),
                    'ticket_code' => (string)$incident->getTicketCode(),
                    'reason' => $exception->getMessage(),
                ];

                if (self::isRowContention($exception)) {
                    $contended[] = $entry;

                    // Seguimiento operativo del aplazamiento (T-PAUSE-41): el que pierde
                    // la carrera no deja estado ni auditoría —es el diseño de T-PAUSE-38/39—,
                    // así que su rastro operativo se escribe aquí, y sólo aquí. No decide
                    // nada: la política calcula el siguiente estado y el puerto lo guarda.
                    $this->recordContention($incident, $passStartedAt);
                } else {
                    $failures[] = $entry;
                }
            }
        }

        $durationMs = round((microtime(true) - $startedAt) * 1000, 2);

        $result = new SanitaryClockSweepResultDto(
            candidates: count($candidates),
            evaluated: $evaluated,
            quarantined: $quarantined,
            skipped: $evaluated - count($quarantined),
            failures: $failures,
            failuresCount: count($failures),
            contended: $contended,
            contendedCount: count($contended),
            thresholdSeconds: $thresholdSeconds,
            durationMs: $durationMs,
        );

        // Libro de la pasada (T-PAUSE-41), SIEMPRE al final y con el resumen ya en la
        // mano: primero se resuelven los seguimientos que esta pasada ya no aplazó —por
        // identidad de los expedientes que sí aplazó— y después se registra el latido con
        // los recuentos exactos del resumen. Si algo de esto falla, el fallo se propaga en
        // voz alta —el planificador alerta— pero ninguna cuarentena deja de escribirse por
        // culpa de la observabilidad (Art. II).
        $this->visibility()->resolveContentionsNotDetectedIn(
            array_map(
                static fn (array $entry): int => (int)$entry['incident_id'],
                $contended
            ),
            self::now()
        );
        $this->visibility()->recordPass(new SanitarySweepPass(
            id: null,
            startedAt: $passStartedAt->format('Y-m-d H:i:s'),
            finishedAt: self::now()->format('Y-m-d H:i:s'),
            candidates: $result->candidates,
            evaluated: $result->evaluated,
            quarantinedCount: count($result->quarantined),
            contendedCount: $result->contendedCount,
            failuresCount: $result->failuresCount,
            durationMs: $durationMs,
        ));

        return $result;
    }

    /**
     * Abre o continúa el seguimiento de contención del expediente aplazado (RF-07.2).
     *
     * El estado vigente se lee para distinguir una racha en curso de un episodio
     * nuevo tras una resolución; el siguiente estado lo calcula la política, que es la
     * única autoridad sobre el umbral de escalado.
     *
     * **El rastro del aplazamiento no puede convertir un aplazamiento en un fallo.**
     * Si la propia escritura de la observación muere esperando el bloqueo que retiene
     * otro consumidor —el mismo fenómeno que acabamos de clasificar como contención—,
     * el seguimiento se salta en ESTA pasada y nada más: el expediente sigue publicado
     * en `contended` con su motivo y su aplazamiento sigue contado en el libro de
     * pasadas, de modo que la pérdida es un contador que arranca de nuevo cuando el
     * prefiltro vuelva a ofrecer el candidato (la pasada siguiente, por construcción).
     * La alternativa —abortar la pasada— convertía un aplazamiento normal en una alerta
     * roja del planificador, que es exactamente el falso positivo que anula T-PAUSE-39.
     *
     * Sólo se tolera el bloqueo: **cualquier otro error de persistencia viaja en voz
     * alta** (tabla ausente, disco lleno, columna inexistente), igual que en el libro de
     * la pasada, porque una observabilidad rota es un problema real y no un aplazamiento.
     */
    private function recordContention(Incident $incident, DateTimeImmutable $passStartedAt): void
    {
        $incidentId = (int)$incident->getId();
        if ($incidentId < 1) {
            // Un candidato sin identificador no puede sostener un seguimiento y, además,
            // no puede venir del prefiltro real: no se inventa una fila para él.
            throw new RuntimeException(
                'El barrido no puede registrar la contención de un candidato sin identificador de expediente.'
            );
        }

        try {
            $current = $this->visibility()->findContentionByIncident($incidentId);

            $this->visibility()->saveContentionDetection(
                $this->escalation()->nextDetection(
                    $current,
                    $incidentId,
                    (int)$incident->getMachineId(),
                    $passStartedAt
                )
            );
        } catch (Throwable $exception) {
            if (!self::isRowContention($exception)) {
                throw $exception;
            }
        }
    }

    /**
     * Instante operativo del sistema (zona de la sede), como el resto del dominio.
     */
    private static function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone(self::OPERATIONAL_TIMEZONE));
    }

    /**
     * Puerto de candidatos, resuelto de forma perezosa sobre el repositorio PDO.
     */
    private function candidates(): SanitaryClockCandidateRepositoryInterface
    {
        return $this->candidateRepo ??= new PdoIncidentRepository();
    }

    /**
     * Reloj sanitario, resuelto de forma perezosa: es la única autoridad de la decisión.
     */
    private function pauses(): IncidentPauseService
    {
        return $this->pauseService ??= new IncidentPauseService();
    }

    /**
     * Unidad de trabajo transaccional (Art. III): agrupa el cambio de estado sanitario
     * y su evidencia inmutable en una sola operación por máquina.
     */
    private function transactions(): TransactionManagerInterface
    {
        return $this->transactionManager ??= new PdoTransactionManager();
    }

    /**
     * Cota de espera de bloqueo de la pasada, resuelta de forma perezosa sobre la
     * sesión PDO compartida (T-PAUSE-40).
     */
    private function lockBudget(): LockWaitBudgetInterface
    {
        return $this->lockWaitBudget ??= new PdoLockWaitBudget();
    }

    /**
     * Libro de pasadas y seguimientos de contención, resuelto de forma perezosa
     * sobre el repositorio PDO (T-PAUSE-41).
     */
    private function visibility(): SanitarySweepVisibilityRepositoryInterface
    {
        return $this->visibilityRepo ??= new PdoSanitarySweepVisibilityRepository();
    }

    /**
     * Política del escalado, resuelta de forma perezosa: es pura y sin estado.
     */
    private function escalation(): SanitarySweepEscalationPolicy
    {
        return $this->escalationPolicy ??= new SanitarySweepEscalationPolicy();
    }

    /**
     * ¿La excepción es contención por fila (otro consumidor tiene la máquina bloqueada)
     * o un fallo de verdad?
     *
     * La regla se lee del **código estructurado** del error, nunca del texto: el motor único
     * declara la espera agotada por su `SQLSTATE` —**`55P03`**, `lock_not_available`, que lo
     * cancela la cota de la pasada y no el tenedor—, el fallo de serialización por `40001` y el
     * interbloqueo resuelto por `40P01`. Los códigos de driver del motor retirado siguen
     * declarados como tolerancia (`CONTENTION_DRIVER_CODES`).
     *
     * Es deliberadamente **conservadora (fail-safe)**: un `PDOException` con cualquier otro
     * código —tabla ausente, columna inexistente, clave duplicada, conexión caída— es un
     * fallo real y alerta, aunque su mensaje se parezca al de un bloqueo. Equivocarse por
     * ese lado cuesta un aviso de más; equivocarse por el otro lado escondería un problema
     * real detrás de una etiqueta de «aplazado» (Art. II).
     */
    private static function isRowContention(Throwable $exception): bool
    {
        if (!$exception instanceof PDOException) {
            return false;
        }

        $errorInfo = is_array($exception->errorInfo) ? $exception->errorInfo : [];
        $driverCode = $errorInfo[1] ?? null;
        $sqlState = (string)($errorInfo[0] ?? $exception->getCode());

        if (is_numeric($driverCode) && in_array((int)$driverCode, self::CONTENTION_DRIVER_CODES, true)) {
            return true;
        }

        return in_array($sqlState, self::CONTENTION_SQL_STATES, true);
    }
}
