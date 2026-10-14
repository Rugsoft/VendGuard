<?php

declare(strict_types=1);

namespace VendGuard\Infrastructure\Database;

use PDO;
use RuntimeException;

/**
 * PostgresMigrationRunner
 *
 * Convergencia del esquema desplegado del motor nuevo (EARS-02.4, T-HYBRID-23).
 *
 * ## Por qué existe
 * `database/supabase_init.sql` es una **foto fija de creación**: sus `CREATE TABLE IF NOT EXISTS`
 * sólo definen el esquema de una base que todavía no existe, y una columna añadida después nunca
 * llega a una base ya creada. Esa asimetría —esquema local migrado frente a esquema de producción
 * creado— es exactamente la que provocó el incidente del 2026-10-03 en el motor local. Este
 * ejecutor cierra la mitad que faltaba: aplica, de forma idempotente y en orden, los ficheros de
 * `database/supabase_migrations/`, de modo que una base ya creada **recibe** cada migración
 * posterior y converge al esquema del repositorio.
 *
 * ## El libro de migraciones
 * El ejecutor lleva su propio libro —la tabla `schema_migrations`, declarada como adición `A-5`
 * del contrato de correspondencia— con el nombre del fichero, su huella y el instante de
 * aplicación. La huella detecta la deriva silenciosa más peligrosa: una migración **ya aplicada**
 * cuyo contenido cambia después. En ese caso el ejecutor falla en voz alta en lugar de dejar que
 * dos bases del mismo sistema tengan formas distintas de la misma migración.
 *
 * ## Qué NO hace
 * No crea el esquema base (eso es `database/supabase_init.sql`), no siembra datos (eso es
 * `SeedRunner` y `database/supabase_seeds.sql`) y no conoce el motor local: es el ejecutor del
 * motor nuevo, y opera sobre una conexión `pdo_pgsql` ya abierta.
 *
 * Dogma Vanilla: PHP 8.2+ puro, PDO nativo, sin dependencias externas. Dualismo Lingüístico:
 * código en inglés, documentación y mensajes en castellano.
 */
final class PostgresMigrationRunner
{
    /** Libro de migraciones aplicadas (adición `A-5` del contrato de correspondencia). */
    public const LEDGER_TABLE = 'schema_migrations';

    /** Dónde viven las migraciones del motor nuevo, relativo a la raíz del proyecto. */
    public const MIGRATIONS_DIRECTORY = 'database/supabase_migrations';

    private PDO $pdo;

    private string $migrationsDirectory;

    public function __construct(PDO $pdo, ?string $migrationsDirectory = null)
    {
        $this->pdo = $pdo;
        $this->migrationsDirectory = $migrationsDirectory ?? dirname(__DIR__, 3) . '/' . self::MIGRATIONS_DIRECTORY;
    }

    /**
     * Asegura el libro y aplica, en orden alfabético, todas las migraciones pendientes.
     *
     * @return list<string> Nombres de los ficheros aplicados en esta pasada (vacío si ya convergía).
     *
     * @throws RuntimeException Si una migración ya aplicada cambió de contenido o si una sentencia falla.
     */
    public function runAll(): array
    {
        $this->ensureLedger();

        $applied = $this->appliedMigrations();
        $appliedNow = [];

        foreach ($this->migrationFiles() as $filename => $path) {
            $checksum = $this->checksum($path);

            if (isset($applied[$filename])) {
                if ($applied[$filename] !== $checksum) {
                    throw new RuntimeException(sprintf(
                        'La migración «%s» ya estaba aplicada y su contenido ha cambiado (huella %s ≠ %s): '
                        . 'una migración aplicada no se reescribe, se corrige con una migración posterior.',
                        $filename,
                        $applied[$filename],
                        $checksum
                    ));
                }

                continue;
            }

            $this->applyMigration($filename, $path, $checksum);
            $appliedNow[] = $filename;
        }

        return $appliedNow;
    }

    /**
     * ¿El esquema ya convergió? Lo está cuando el libro existe y no queda ninguna migración del
     * repositorio pendiente.
     *
     * @return array{converged: bool, pending: list<string>, skipped: list<string>}
     */
    public function convergenceReport(): array
    {
        $files = $this->migrationFiles();

        if (!$this->ledgerExists()) {
            return [
                'converged' => false,
                'pending' => array_keys($files),
                'skipped' => [],
            ];
        }

        $applied = $this->appliedMigrations();
        $pending = [];
        $skipped = [];

        foreach ($files as $filename => $path) {
            if (!isset($applied[$filename])) {
                $pending[] = $filename;
                continue;
            }

            if ($applied[$filename] !== $this->checksum($path)) {
                $skipped[] = $filename;
            }
        }

        return [
            'converged' => $pending === [] && $skipped === [],
            'pending' => $pending,
            'skipped' => $skipped,
        ];
    }

    /**
     * Igual que {@see convergenceReport()} pero fallando en voz alta: es la puerta que el arranque
     * del contenedor usa antes de declarar el esquema verificado (EARS-02.4/EARS-02.5).
     *
     * @throws RuntimeException Si queda alguna migración pendiente o alguna dejó de corresponder.
     */
    public function assertConverged(): void
    {
        $report = $this->convergenceReport();

        if ($report['converged']) {
            return;
        }

        $problems = [];
        if ($report['pending'] !== []) {
            $problems[] = 'pendientes: ' . implode(', ', $report['pending']);
        }
        if ($report['skipped'] !== []) {
            $problems[] = 'con contenido cambiado: ' . implode(', ', $report['skipped']);
        }

        throw new RuntimeException(
            'El esquema del motor nuevo no ha convergido (' . implode(' · ', $problems) . '). '
            . 'Aplique database/supabase_migrations/ antes de servir tráfico (EARS-02.4).'
        );
    }

    /** Migraciones del repositorio, ordenadas por nombre (el prefijo numérico fija el orden). */
    public function migrationFiles(): array
    {
        $files = glob($this->migrationsDirectory . '/*.sql') ?: [];
        sort($files, SORT_STRING);

        $indexed = [];
        foreach ($files as $path) {
            $indexed[basename($path)] = $path;
        }

        return $indexed;
    }

    /** Ficheros ya registrados en el libro, con su huella. @return array<string, string> */
    public function appliedMigrations(): array
    {
        if (!$this->ledgerExists()) {
            return [];
        }

        $stmt = $this->pdo->query('SELECT filename, checksum FROM ' . self::LEDGER_TABLE . ' ORDER BY filename');
        if ($stmt === false) {
            return [];
        }

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $stmt->closeCursor();

        $applied = [];
        foreach ($rows as $row) {
            $applied[(string)$row['filename']] = (string)$row['checksum'];
        }

        return $applied;
    }

    public function ledgerExists(): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = current_schema() AND table_name = :name'
        );
        $stmt->execute([':name' => self::LEDGER_TABLE]);
        $exists = (int)$stmt->fetchColumn() > 0;
        $stmt->closeCursor();

        return $exists;
    }

    /**
     * Huella de una migración, calculada sobre su contenido con los finales de línea
     * normalizados: el mismo fichero en un equipo Windows (CRLF) y en el contenedor (LF) tiene
     * que producir la misma huella, o el libro señalaría una deriva que no existe.
     */
    public function checksum(string $path): string
    {
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException("No se pudo leer la migración: {$path}");
        }

        return hash('sha256', str_replace("\r\n", "\n", $contents));
    }

    private function ensureLedger(): void
    {
        // El libro es contabilidad del ejecutor, no una tabla del dominio: se crea aquí, antes de
        // aplicar nada, porque sin él no habría forma de saber qué migración ya está aplicada.
        $this->pdo->exec(sprintf(
            'CREATE TABLE IF NOT EXISTS %s (
                id         BIGINT GENERATED ALWAYS AS IDENTITY,
                filename   VARCHAR(255) NOT NULL,
                checksum   CHAR(64) NOT NULL,
                applied_at TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
                CONSTRAINT pk_%1$s PRIMARY KEY (id),
                CONSTRAINT uq_%1$s_filename UNIQUE (filename)
            )',
            self::LEDGER_TABLE
        ));
    }

    /**
     * Aplica un fichero entero dentro de una transacción: o entran todas sus sentencias con su
     * registro en el libro, o no entra ninguna. Una migración a medias es peor que una migración
     * no aplicada, porque el libro diría que sí.
     */
    private function applyMigration(string $filename, string $path, string $checksum): void
    {
        $sql = file_get_contents($path);
        if ($sql === false) {
            throw new RuntimeException("No se pudo leer la migración: {$path}");
        }

        $this->pdo->beginTransaction();

        try {
            foreach (SqlScriptSplitter::split($sql) as $statement) {
                $result = $this->pdo->query($statement);
                if ($result instanceof \PDOStatement) {
                    $result->closeCursor();
                }
            }

            $insert = $this->pdo->prepare(
                'INSERT INTO ' . self::LEDGER_TABLE . ' (filename, checksum) VALUES (:filename, :checksum)'
            );
            $insert->execute([':filename' => $filename, ':checksum' => $checksum]);

            $this->pdo->commit();
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }

            throw new RuntimeException(
                "Error aplicando la migración {$filename}: " . $exception->getMessage(),
                0,
                $exception
            );
        }
    }
}
