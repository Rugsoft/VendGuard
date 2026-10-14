<?php

declare(strict_types=1);

namespace VendGuard\Application\Service;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use PDOException;
use RuntimeException;
use Throwable;
use VendGuard\Application\DTO\HealthCheckResponseDto;
use VendGuard\Core\Domain\Model\SanitarySweepPass;
use VendGuard\Core\Domain\Repository\SanitarySweepVisibilityRepositoryInterface;
use VendGuard\Infrastructure\Database\ConnectionFactory;
use VendGuard\Infrastructure\Repository\PdoSanitarySweepVisibilityRepository;

/**
 * HealthCheckService — Caso de uso del diagnóstico técnico de enlace
 * (RF-07, EARS-07.1 a EARS-07.4; tarea T-HYBRID-13).
 *
 * Qué comprueba, y por qué así:
 * 1. **Una consulta sobre una tabla real del dominio** —`machines`, el maestro
 *    de parque—, no un `SELECT 1` sintético: el latido diario existe para que el
 *    proveedor registre actividad efectiva en la base (EARS-03.2), y una
 *    verificación que no toca ninguna tabla no lo consigue. La consulta es SQL
 *    estándar (`COUNT(*)`), de modo que no introduce dialecto privativo fuera
 *    del adaptador (T-HYBRID-25) y se ejecuta igual en los dos motores.
 * 2. **La latencia, cronometrada de verdad** con `hrtime()`, que es monótono y no
 *    depende del reloj de pared. El presupuesto declarado (EARS-07.1: responder en
 *    menos de 1,0 s) distingue `HEALTHY` de `DEGRADED`, y la latencia sólo viaja en
 *    la telemetría autenticada: publicarla sería filtrar arquitectura (EARS-07.2).
 * 3. **El latido del barrido sanitario** (`sanitary_clock.last_pass_at`), leído del
 *    libro de pasadas —la misma fuente que alimenta la superficie de Coordinación—,
 *    porque es la señal con la que el vigilante externo decide si la ventana del
 *    reloj biológico se perdió (EARS-06.5, T-HYBRID-04).
 * 4. **Nada que pueda filtrar**: del motor sólo se publica su nombre y si el
 *    tráfico va por el agrupador; el DSN, el host, el usuario y la contraseña no
 *    salen de la factoría (Art. V.4 y Art. VI.1). El motivo de un fallo viaja como
 *    etiqueta de conjunto cerrado deducida del TIPO de la excepción: el texto de un
 *    fallo de conexión puede llevar host y credenciales dentro, y EARS-07.4 prohíbe
 *    el volcado técnico.
 *
 * **Zona horaria del libro de pasadas.** `sanitary_sweep_passes.started_at` se
 * escribe con la zona operativa del sistema (`Europe/Madrid`) tal y como la
 * declara el reloj del barrido. En el motor local la columna es `DATETIME` y el
 * texto viaja sin zona, así que se interpreta en la zona operativa; en el motor
 * nuevo es `TIMESTAMPTZ` y su texto ya incluye el desplazamiento, que se respeta.
 * La telemetría publica siempre el instante en **UTC**, porque el vigilante
 * externo compara contra `date -u`: publicar la hora de pared sin convertir
 * desplazaría el latido dos horas y la ventana perdida no se detectaría.
 *
 * Dogma Vanilla: PHP 8.2+ puro, PDO, sin dependencias externas.
 * Dualismo Lingüístico: código en inglés, documentación en castellano.
 */
final class HealthCheckService
{
    /**
     * Presupuesto de respuesta declarado por EARS-07.1, en milisegundos.
     *
     * No es un objetivo de rendimiento inventado aquí: es el umbral que la
     * especificación exige para `GET /api/status`, y por eso una verificación que
     * lo supera se publica como degradada en lugar de sana.
     */
    public const RESPONSE_BUDGET_MS = 1000.0;

    /**
     * Verificación de conectividad: SQL estándar sobre una tabla real del dominio.
     *
     * Se declara como constante para que la suite pueda certificar sin ambigüedad
     * que la comprobación toca `machines` y que **no** es un `SELECT 1` sintético.
     */
    public const PROBE_SQL = 'SELECT COUNT(*) FROM machines';

    /** Etiqueta del camino directo, sin agrupador transaccional. */
    public const DIRECT_CONNECTION_LABEL = 'direct';

    /** Prefijo del agrupador transaccional (Supavisor) en la telemetría. */
    public const POOLER_LABEL_PREFIX = 'supavisor_';

    /** El motor no respondió: la conexión falló antes de poder verificar nada. */
    public const REASON_CONNECT_FAILED = 'CONNECT_FAILED';

    /** El motor respondió a la conexión pero la verificación no se completó. */
    public const REASON_QUERY_FAILED = 'QUERY_FAILED';

    /** Fallo de una clase que el diagnóstico no distingue (ni publica). */
    public const REASON_UNEXPECTED = 'UNEXPECTED';

    /** La telemetría del barrido no se pudo leer: la señal del vigilante no viaja. */
    public const REASON_SWEEP_BOOK_UNREADABLE = 'SWEEP_BOOK_UNREADABLE';

    /** La verificación respondió por encima del presupuesto de EARS-07.1. */
    public const REASON_SLOW_RESPONSE = 'SLOW_RESPONSE';

    /** Desenlace de la última pasada: terminó sin fallos. */
    public const PASS_RESULT_OK = 'OK';

    /** Desenlace de la última pasada: hubo candidatos con fallo real (lo único que alerta). */
    public const PASS_RESULT_FAILURES = 'FAILURES';

    /** Desenlace de la última pasada: murió a mitad, sin marca de cierre. */
    public const PASS_RESULT_INTERRUPTED = 'INTERRUPTED';

    /** Zona operativa del sistema: la misma que usan el reloj del barrido y la auditoría. */
    private const OPERATIONAL_TIMEZONE = 'Europe/Madrid';

    /** Zona de publicación de la telemetría: el vigilante externo compara contra UTC. */
    private const PUBLICATION_TIMEZONE_UTC = 'UTC';

    /** Conexión compartida; se resuelve en el primer uso si no se inyecta. */
    private ?PDO $pdo;

    /** Libro de pasadas del barrido; se resuelve en el primer uso si no se inyecta. */
    private ?SanitarySweepVisibilityRepositoryInterface $sweepBook;

    public function __construct(
        ?PDO $pdo = null,
        ?SanitarySweepVisibilityRepositoryInterface $sweepBook = null
    ) {
        $this->pdo = $pdo;
        $this->sweepBook = $sweepBook;
    }

    /**
     * Ejecuta el diagnóstico del enlace.
     *
     * @param bool $includeDetails Si es `true`, la respuesta lleva la telemetría detallada;
     *                             el controlador lo decide según la credencial presente
     *                             (EARS-07.3). La ausencia de credencial nunca es un fallo:
     *                             sólo reduce la respuesta.
     * @param DateTimeImmutable|null $now Instante de referencia (por defecto, el del sistema en
     *                                    la zona operativa), inyectable para certificar el contrato.
     * @return HealthCheckResponseDto Diagnóstico listo para serializar.
     */
    public function check(bool $includeDetails = false, ?DateTimeImmutable $now = null): HealthCheckResponseDto
    {
        $reference = $now ?? new DateTimeImmutable('now', new DateTimeZone(self::OPERATIONAL_TIMEZONE));
        $timestampUtc = self::toUtcInstant($reference);

        // La configuración se describe SIN abrir conexión: permite publicar el motor y el
        // camino (agrupador o directo) incluso cuando la conexión es lo que ha fallado.
        $configuration = ConnectionFactory::describeConnection();
        $driver = (string)($configuration['driver'] ?? ConnectionFactory::DRIVER_MYSQL);
        $pooler = self::connectionPoolerLabel($configuration);

        $startedAt = hrtime(true);
        $failureReason = null;

        try {
            $statement = $this->connection()->query(self::PROBE_SQL);

            if ($statement === false) {
                // Sin excepción pero sin resultado: el motor no confirmó la verificación.
                $failureReason = self::REASON_QUERY_FAILED;
            } else {
                // Consumir la fila cierra el ida y vuelta: la verificación se completa de verdad.
                $statement->fetchColumn();
            }
        } catch (Throwable $exception) {
            $failureReason = self::classifyFailure($exception);
        }

        $responseTimeMs = round((hrtime(true) - $startedAt) / 1_000_000, 2);
        $probeSucceeded = $failureReason === null;

        $lastPassAt = null;
        $lastResult = null;

        // La telemetría del barrido sólo se intenta sobre una verificación que ya respondió:
        // si el enlace está caído no hay libro que leer, y volver a intentar una conexión que
        // ya falló duplicaría la espera del diagnóstico sin añadir un solo dato.
        if ($includeDetails && $probeSucceeded) {
            try {
                $lastPass = $this->sweepBook()->findLatestPass();

                if ($lastPass !== null) {
                    $lastPassAt = self::sweepInstantToUtc($lastPass->startedAt, $driver);
                    $lastResult = self::passResultLabel($lastPass);
                }
            } catch (Throwable $exception) {
                // La señal del vigilante no viaja: una señal ausente es una degradación
                // declarada, nunca un silencio.
                $failureReason = self::REASON_SWEEP_BOOK_UNREADABLE;
            }
        }

        // Precedencia declarada del estado: el motor que no verifica es lo más grave; después,
        // la telemetría que no se pudo leer completa (una señal ausente); y por último, una
        // verificación que respondió tarde. Sólo el primer caso emite `503`.
        if (!$probeSucceeded) {
            $status = HealthCheckResponseDto::STATUS_UNHEALTHY;
        } elseif ($failureReason !== null) {
            $status = HealthCheckResponseDto::STATUS_DEGRADED;
        } elseif ($responseTimeMs > self::RESPONSE_BUDGET_MS) {
            $status = HealthCheckResponseDto::STATUS_DEGRADED;
            $failureReason = self::REASON_SLOW_RESPONSE;
        } else {
            $status = HealthCheckResponseDto::STATUS_HEALTHY;
        }

        return new HealthCheckResponseDto(
            status: $status,
            timestampUtc: $timestampUtc,
            databaseDriver: $driver,
            connectionPooler: $pooler,
            responseTimeMs: $responseTimeMs,
            sanitaryClockLastPassAt: $lastPassAt,
            sanitaryClockLastResult: $lastResult,
            failureReason: $failureReason,
        );
    }

    /**
     * Etiqueta del camino de conexión para la telemetría: `supavisor_6543` cuando el
     * tráfico va por el agrupador transaccional y `direct` cuando va al motor.
     *
     * Se deriva de la configuración resuelta por la factoría —la misma que gobierna la
     * conexión real— y no del DSN, que nunca se publica.
     *
     * @param array<string, mixed> $configuration Configuración resuelta (`describeConnection`).
     */
    public static function connectionPoolerLabel(array $configuration): string
    {
        $usesPooler = !empty($configuration['uses_pooler']);

        return $usesPooler
            ? self::POOLER_LABEL_PREFIX . ConnectionFactory::POOLER_PORT
            : self::DIRECT_CONNECTION_LABEL;
    }

    /**
     * Convierte una marca del libro de pasadas a instante UTC ISO-8601.
     *
     * @param string $storedInstant Marca tal y como la devuelve el motor.
     * @param string $driver Driver activo, que decide la zona por omisión cuando el
     *                       texto no la trae: el motor local guarda hora de pared en la
     *                       zona operativa y el motor nuevo entrega el desplazamiento
     *                       explícito, que PHP respeta por encima de esta omisión.
     */
    public static function sweepInstantToUtc(string $storedInstant, string $driver): string
    {
        $defaultZone = $driver === ConnectionFactory::DRIVER_PGSQL
            ? self::PUBLICATION_TIMEZONE_UTC
            : self::OPERATIONAL_TIMEZONE;

        $instant = new DateTimeImmutable($storedInstant, new DateTimeZone($defaultZone));

        return self::toUtcInstant($instant);
    }

    /**
     * Desenlace de una pasada del barrido, proyectado desde sus propios hechos.
     *
     * No añade ninguna regla: una pasada sin cierre es una pasada interrumpida (el
     * propio registro lo declara con `isInterrupted()`), un `failures_count` positivo es
     * el único contador que alerta, y el aplazamiento por contención no cambia el
     * desenlace a propósito —es visible en la superficie del barrido, no un fallo—.
     */
    public static function passResultLabel(?SanitarySweepPass $pass): ?string
    {
        if ($pass === null) {
            return null;
        }

        if ($pass->isInterrupted()) {
            return self::PASS_RESULT_INTERRUPTED;
        }

        return $pass->failuresCount > 0 ? self::PASS_RESULT_FAILURES : self::PASS_RESULT_OK;
    }

    /**
     * Instante ISO-8601 en UTC, la forma que entiende el vigilante externo
     * (`date -u -d`) sin ambigüedad de zona.
     */
    private static function toUtcInstant(DateTimeImmutable $instant): string
    {
        return gmdate('Y-m-d\TH:i:s\Z', $instant->getTimestamp());
    }

    /**
     * Motivo de degradación del conjunto cerrado, deducido del TIPO del fallo.
     *
     * El texto de la excepción se descarta a propósito: la de un fallo de conexión es un
     * `RuntimeException` de la factoría que envuelve el mensaje de PDO, y ese mensaje puede
     * llevar host, usuario y la pista de la credencial (EARS-07.4, Art. V.4).
     */
    private static function classifyFailure(Throwable $exception): string
    {
        return match (true) {
            $exception instanceof PDOException => self::REASON_QUERY_FAILED,
            $exception instanceof RuntimeException => self::REASON_CONNECT_FAILED,
            default => self::REASON_UNEXPECTED,
        };
    }

    private function connection(): PDO
    {
        return $this->pdo ??= ConnectionFactory::getConnection();
    }

    private function sweepBook(): SanitarySweepVisibilityRepositoryInterface
    {
        // La misma conexión que acaba de verificar el enlace: el libro de pasadas no abre
        // una segunda sesión ni busca credenciales por su cuenta.
        return $this->sweepBook ??= new PdoSanitarySweepVisibilityRepository($this->connection());
    }
}
