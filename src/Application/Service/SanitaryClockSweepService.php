<?php

declare(strict_types=1);

namespace VendGuard\Application\Service;

use DomainException;
use InvalidArgumentException;
use RuntimeException;
use VendGuard\Application\DTO\SanitaryClockSweepResultDto;
use VendGuard\Core\Domain\Repository\SanitaryClockCandidateRepositoryInterface;
use VendGuard\Core\Domain\Repository\TransactionManagerInterface;
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
 * 4. **Idempotencia de verdad.** Repetir la pasada no duplica cuarentenas ni rastros,
 *    porque la decisión y la exclusión mutua viven en el reloj y en su transición
 *    idempotente. Este servicio puede, por tanto, ejecutarse con la cadencia que fije
 *    el planificador sin ningún efecto acumulativo.
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

    /** Puerto de candidatos; se resuelve en el primer uso si no se inyecta. */
    private ?SanitaryClockCandidateRepositoryInterface $candidateRepo;

    /** Reloj sanitario; se resuelve en el primer uso si no se inyecta. */
    private ?IncidentPauseService $pauseService;

    /** Unidad de trabajo transaccional; se resuelve en el primer uso si no se inyecta. */
    private ?TransactionManagerInterface $transactionManager;

    /**
     * Todos los colaboradores son opcionales y se resuelven de forma perezosa, igual
     * que en `IncidentPauseService`: el servicio es construible sin base de datos y el
     * cableado de producción toma los repositorios PDO por defecto.
     */
    public function __construct(
        ?SanitaryClockCandidateRepositoryInterface $candidateRepo = null,
        ?IncidentPauseService $pauseService = null,
        ?TransactionManagerInterface $transactionManager = null
    ) {
        $this->candidateRepo = $candidateRepo;
        $this->pauseService = $pauseService;
        $this->transactionManager = $transactionManager;
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

        $startedAt = microtime(true);
        $thresholdSeconds = IncidentPauseService::SANITARY_BIOLOGICAL_CLOCK_SECONDS;

        $candidates = $this->candidates()->findSanitaryClockCandidates($thresholdSeconds, $limit);

        /** @var list<array{incident_id: int|null, ticket_code: string, machine_id: int}> $quarantined */
        $quarantined = [];

        /** @var list<array{incident_id: int|null, ticket_code: string, reason: string}> $failures */
        $failures = [];

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
                // Aislamiento con voz: el fallo de una máquina no detiene el barrido de
                // las demás, pero viaja en el resumen con su motivo y su expediente.
                $failures[] = [
                    'incident_id' => $incident->getId(),
                    'ticket_code' => (string)$incident->getTicketCode(),
                    'reason' => $exception->getMessage(),
                ];
            }
        }

        return new SanitaryClockSweepResultDto(
            candidates: count($candidates),
            evaluated: $evaluated,
            quarantined: $quarantined,
            skipped: $evaluated - count($quarantined),
            failures: $failures,
            failuresCount: count($failures),
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
}
