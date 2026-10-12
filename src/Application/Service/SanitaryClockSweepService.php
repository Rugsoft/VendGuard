<?php

declare(strict_types=1);

namespace VendGuard\Application\Service;

use DomainException;
use InvalidArgumentException;
use PDOException;
use RuntimeException;
use Throwable;
use VendGuard\Application\DTO\SanitaryClockSweepResultDto;
use VendGuard\Core\Domain\Repository\LockWaitBudgetInterface;
use VendGuard\Core\Domain\Repository\SanitaryClockCandidateRepositoryInterface;
use VendGuard\Core\Domain\Repository\TransactionManagerInterface;
use VendGuard\Infrastructure\Database\PdoLockWaitBudget;
use VendGuard\Infrastructure\Database\PdoTransactionManager;
use VendGuard\Infrastructure\Repository\PdoIncidentRepository;

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
 *    (`1205`/`1213`/`SQLSTATE 40001`). Eso NO se cuenta como fallo: es la prueba de que
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
     * Códigos de driver (MariaDB/MySQL) que significan «otro consumidor tiene la fila»:
     * `ER_LOCK_WAIT_TIMEOUT` (1205) y `ER_LOCK_DEADLOCK` (1213).
     */
    private const CONTENTION_DRIVER_CODES = [1205, 1213];

    /**
     * Estados SQL de bloqueo/serialización: `40001` (serialization failure, el que
     * MariaDB usa en el deadlock) y `40P01` (deadlock de PostgreSQL, por completitud
     * del estándar). Sólo se consultan como respaldo del código de driver.
     */
    private const CONTENTION_SQL_STATES = ['40001', '40P01'];

    /** Puerto de candidatos; se resuelve en el primer uso si no se inyecta. */
    private ?SanitaryClockCandidateRepositoryInterface $candidateRepo;

    /** Reloj sanitario; se resuelve en el primer uso si no se inyecta. */
    private ?IncidentPauseService $pauseService;

    /** Unidad de trabajo transaccional; se resuelve en el primer uso si no se inyecta. */
    private ?TransactionManagerInterface $transactionManager;

    /** Cota de espera de bloqueo de la pasada; se resuelve en el primer uso si no se inyecta. */
    private ?LockWaitBudgetInterface $lockWaitBudget;

    /**
     * Todos los colaboradores son opcionales y se resuelven de forma perezosa, igual
     * que en `IncidentPauseService`: el servicio es construible sin base de datos y el
     * cableado de producción toma los repositorios PDO por defecto.
     */
    public function __construct(
        ?SanitaryClockCandidateRepositoryInterface $candidateRepo = null,
        ?IncidentPauseService $pauseService = null,
        ?TransactionManagerInterface $transactionManager = null,
        ?LockWaitBudgetInterface $lockWaitBudget = null
    ) {
        $this->candidateRepo = $candidateRepo;
        $this->pauseService = $pauseService;
        $this->transactionManager = $transactionManager;
        $this->lockWaitBudget = $lockWaitBudget;
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
                } else {
                    $failures[] = $entry;
                }
            }
        }

        return new SanitaryClockSweepResultDto(
            candidates: count($candidates),
            evaluated: $evaluated,
            quarantined: $quarantined,
            skipped: $evaluated - count($quarantined),
            failures: $failures,
            failuresCount: count($failures),
            contended: $contended,
            contendedCount: count($contended),
            thresholdSeconds: $thresholdSeconds,
            durationMs: round((microtime(true) - $startedAt) * 1000, 2),
        );
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
     * ¿La excepción es contención por fila (otro consumidor tiene la máquina bloqueada)
     * o un fallo de verdad?
     *
     * La regla se lee del **código estructurado** del error, nunca del texto: son los
     * códigos que MariaDB emite cuando una sentencia espera un bloqueo exclusivo ajeno
     * (`1205`), cuando el gestor resuelve un deadlock matando una de las transacciones
     * (`1213`) o cuando el estado SQL es el de fallo de serialización (`40001`/`40P01`).
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
