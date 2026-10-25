<?php

declare(strict_types=1);

use VendGuard\Infrastructure\Database\PostgresMigrationRunner;
use VendGuard\Infrastructure\Database\SqlScriptSplitter;

/**
 * VendGuard — Constructor de fixtures del motor nuevo (T-HYBRID-21)
 *
 * Las suites de la categoría `new_engine` necesitan lo mismo antes de certificar nada: una
 * conexión al motor nuevo por su contrato de entorno (`PG_TEST_DSN`, sin valor por defecto), un
 * esquema aislado con el DDL del repositorio aplicado y la capacidad de escribir **una fila
 * válida en cada una de las 27 tablas** respetando el grafo de claves foráneas.
 *
 * Lo último no se puede improvisar tabla por tabla: serían veinte fixtures escritos a mano que
 * se desincronizarían del DDL a la primera columna nueva, y el día que dejaran de insertar
 * pasarían a certificar menos sin decirlo. Aquí se derivan del **catálogo vivo** —
 * `information_schema` y `pg_constraint`—, que es la misma fuente que la suite de
 * correspondencia considera la verdad (contrato §2: el inventario no son los ficheros `.sql`).
 *
 * Contrato de fallo (RNF-04, plan §8): sin motor, la categoría se declara **no ejecutable** con
 * código de salida 2 y un mensaje explícito. Ninguna aserción se marca como superada sin motor
 * real y la suite no se omite en silencio.
 *
 * Dogma Vanilla: PHP 8.2+ puro, PDO nativo, sin dependencias externas. Es código de arnés: no
 * vive bajo `src/` y ningún camino de producción lo carga.
 *
 * Dualismo Lingüístico: código en inglés, documentación y mensajes en castellano.
 */
final class PostgresFixtureBuilder
{
    /** Nombre canónico del esquema aislado donde esta categoría construye su escenario. */
    public const ISOLATION_SCHEMA = 't_hybrid21_motor_nuevo';

    /**
     * Texto de relleno de las columnas textuales obligatorias.
     *
     * Se elige largo a propósito: las restricciones `CHECK` del Art. V.1 exigen 20 caracteres
     * como mínimo, y una cadena corta convertiría cada inserción en un rechazo legítimo que no
     * tendría nada que ver con lo que la suite certifica.
     */
    private const FILLER_TEXT = 'Fixture de certificación del motor nuevo (T-HYBRID-21).';

    /** Instante de relleno de las columnas temporales, en la forma que el motor acepta. */
    private const FILLER_TIMESTAMP = '2026-10-14 12:00:00+00';

    /**
     * Columnas opcionales que el alta mínima rellena de todos modos.
     *
     * Algunas tablas exigen una de dos ramas en un `CHECK`: dejar en `NULL` todas
     * las columnas de la rama convertiría el alta mínima en un rechazo legítimo
     * del motor, que no es lo que la certificación mide.
     *
     * @var array<string, list<string>>
     */
    private const REQUIRED_OPTIONAL_COLUMNS = [
        'incident_service_sheets' => ['absence_reason'],
    ];

    /**
     * Columnas que el `UPDATE` de certificación no toca.
     *
     * Participan en un `CHECK` de rama: cambiar una sola dejaría la fila en un
     * estado que el motor rechaza por diseño, y el arnés estaría midiendo su
     * propia torpeza en lugar del CRUD.
     *
     * @var array<string, list<string>>
     */
    private const CRUD_EXCLUDED_COLUMNS = [
        'incident_service_sheets' => ['signer_name', 'signer_document', 'signature_png'],
        'technician_absences' => ['starts_at', 'ends_at', 'cancelled_at', 'cancelled_by', 'cancellation_reason', 'reason'],
        'technician_shifts' => ['started_at', 'meal_break_started_at', 'meal_break_ended_at', 'closed_at', 'closed_by', 'status', 'shift_date'],
    ];

    // ─────────────────────────────────────────────────────────────────────
    // Puerta de la categoría y ciclo de vida del esquema
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Abre la conexión que declara el contrato de entorno, o declara la categoría no ejecutable.
     *
     * No hay valor por defecto y no se inventan credenciales: sin `PG_TEST_DSN` no se conecta a
     * ningún sitio «razonable», se sale con código 2 para que el canal de la categoría pueda
     * distinguir «no ejecutable» de «en rojo».
     */
    public static function gateOrExit(): PDO
    {
        $dsn = getenv('PG_TEST_DSN');

        if ($dsn === false || trim((string)$dsn) === '') {
            fwrite(STDERR, "\n[CATEGORÍA NO EJECUTABLE] Falta PG_TEST_DSN: el motor nuevo no está declarado.\n");
            fwrite(STDERR, "Aprovisione deploy/local/postgres/ y exporte su contrato de entorno; la suite no se omite en silencio.\n");
            exit(2);
        }

        try {
            $pdo = new PDO((string)$dsn, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => true,
            ]);
            $pdo->query('SELECT 1')->fetchColumn();
        } catch (Throwable $exception) {
            fwrite(STDERR, "\n[CATEGORÍA NO EJECUTABLE] El motor nuevo no responde: " . $exception->getMessage() . "\n");
            exit(2);
        }

        return $pdo;
    }

    /**
     * Deja el esquema aislado vacío y lo pone al frente de la ruta de búsqueda.
     *
     * El borrado previo con `CASCADE` no es una comodidad: una corrida anterior interrumpida
     * dejaría su escenario a medias y esta pasada lo leería como si fuera el suyo.
     */
    public static function createIsolationSchema(PDO $pdo, string $schema = self::ISOLATION_SCHEMA): void
    {
        $pdo->exec("DROP SCHEMA IF EXISTS {$schema} CASCADE");
        $pdo->exec("CREATE SCHEMA {$schema}");
        $pdo->exec("SET search_path TO {$schema}, public");
    }

    /** Devuelve la ruta de búsqueda a `public` y borra el escenario entero, sin residuo. */
    public static function dropIsolationSchema(PDO $pdo, string $schema = self::ISOLATION_SCHEMA): void
    {
        $pdo->exec('SET search_path TO public');
        $pdo->exec("DROP SCHEMA IF EXISTS {$schema} CASCADE");
    }

    /**
     * Aplica el esquema del repositorio sobre el esquema aislado: DDL base y migraciones.
     *
     * Se aplican las **dos** piezas, y en ese orden, porque es lo que hace el arranque real
     * (`bin/init_cloud_db.php`, T-HYBRID-12/23): certificar sobre un esquema que solo tiene el
     * DDL base certificaría un punto de partida que no existe en producción.
     *
     * @return array{statements: int, migrations: list<string>}
     */
    public static function applyRepositorySchema(PDO $pdo, string $projectRoot): array
    {
        $statements = 0;
        $script = (string)file_get_contents($projectRoot . '/database/supabase_init.sql');

        foreach (SqlScriptSplitter::split($script) as $statement) {
            $result = $pdo->query($statement);
            if ($result instanceof PDOStatement) {
                $result->closeCursor();
            }
            $statements++;
        }

        $runner = new PostgresMigrationRunner($pdo);

        return ['statements' => $statements, 'migrations' => $runner->runAll()];
    }

    // ─────────────────────────────────────────────────────────────────────
    // Lectura del catálogo
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Tablas base del esquema aislado, sin el libro de migraciones.
     *
     * El libro (`schema_migrations`) es una adición declarada del motor nuevo (A-5) y no forma
     * parte del dominio: entra en el inventario de inventarios, no en el de las 27 tablas.
     *
     * @return list<string>
     */
    public static function tables(PDO $pdo): array
    {
        $stmt = $pdo->query(
            "SELECT table_name
               FROM information_schema.tables
              WHERE table_schema = current_schema() AND table_type = 'BASE TABLE'
              ORDER BY table_name"
        );

        return array_values(array_filter(
            array_map(static fn (array $row): string => (string)$row['table_name'], $stmt->fetchAll()),
            static fn (string $table): bool => $table !== PostgresMigrationRunner::LEDGER_TABLE
        ));
    }

    /**
     * Metadatos de las columnas de una tabla, en orden de declaración.
     *
     * @return array<string, array{data_type: string, udt_name: string, nullable: bool, default: string|null, identity: bool, generated: bool, max_length: int|null}>
     */
    public static function columns(PDO $pdo, string $table): array
    {
        $stmt = $pdo->prepare(
            'SELECT column_name, data_type, udt_name, is_nullable, column_default, is_identity, is_generated,
                    character_maximum_length
               FROM information_schema.columns
              WHERE table_schema = current_schema() AND table_name = :table
              ORDER BY ordinal_position'
        );
        $stmt->execute([':table' => $table]);

        $columns = [];
        foreach ($stmt->fetchAll() as $row) {
            $columns[(string)$row['column_name']] = [
                'data_type' => (string)$row['data_type'],
                'udt_name' => (string)$row['udt_name'],
                'nullable' => strtoupper((string)$row['is_nullable']) === 'YES',
                'default' => $row['column_default'] !== null ? (string)$row['column_default'] : null,
                'identity' => strtoupper((string)$row['is_identity']) === 'YES',
                'generated' => strtoupper((string)$row['is_generated']) === 'ALWAYS',
                'max_length' => $row['character_maximum_length'] !== null ? (int)$row['character_maximum_length'] : null,
            ];
        }

        if ($columns === []) {
            throw new RuntimeException("La tabla «{$table}» no existe en el esquema aislado del motor nuevo.");
        }

        return $columns;
    }

    /**
     * Columnas de la clave primaria, en el orden que declara la restricción.
     *
     * @return list<string>
     */
    public static function primaryKey(PDO $pdo, string $table): array
    {
        $stmt = $pdo->prepare(
            "SELECT a.attname AS column_name
               FROM pg_constraint con
               JOIN pg_class c ON c.oid = con.conrelid
               JOIN pg_namespace n ON n.oid = c.relnamespace
               JOIN generate_subscripts(con.conkey, 1) AS k ON TRUE
               JOIN pg_attribute a ON a.attrelid = con.conrelid AND a.attnum = con.conkey[k]
              WHERE con.contype = 'p' AND n.nspname = current_schema() AND c.relname = :table
              ORDER BY k"
        );
        $stmt->execute([':table' => $table]);

        return array_map(static fn (array $row): string => (string)$row['column_name'], $stmt->fetchAll());
    }

    /**
     * Columnas obligatorias sin valor por defecto: las que hay que escribir en un alta mínima.
     *
     * @return list<string>
     */
    public static function requiredColumns(PDO $pdo, string $table): array
    {
        $required = [];
        foreach (self::columns($pdo, $table) as $name => $meta) {
            if ($meta['identity'] || $meta['generated'] || $meta['default'] !== null) {
                continue;
            }
            if (!$meta['nullable']) {
                $required[] = $name;
            }
        }

        return $required;
    }

    /**
     * Grafo de claves foráneas del esquema aislado: hija => lista de padres.
     *
     * Las autorreferencias se descartan: una fila que se apunta a sí misma no obliga a insertar
     * nada antes, y dejarla en el grafo convertiría cualquier tabla con jerarquía en un ciclo.
     *
     * @return array<string, list<string>> tabla hija => tablas padre
     */
    public static function foreignKeyGraph(PDO $pdo): array
    {
        $graph = [];
        foreach (self::foreignKeyEdges($pdo) as $edge) {
            if ($edge['child_table'] === $edge['parent_table']) {
                continue;
            }
            $graph[$edge['child_table']][] = $edge['parent_table'];
        }

        foreach ($graph as &$parents) {
            $parents = array_values(array_unique($parents));
        }
        unset($parents);

        return $graph;
    }

    /**
     * Orden de inserción seguro: ningún padre antes que sus hijas.
     *
     * Un ciclo se declara en voz alta en lugar de resolverse a la brava (por ejemplo dejando
     * columnas a `NULL`): si el DDL llegara a tener uno, la fixture mínima dejaría de ser
     * construible y eso es un hallazgo, no un detalle del arnés.
     *
     * @param list<string> $tables
     * @return list<string>
     */
    public static function insertionOrder(PDO $pdo, array $tables): array
    {
        $graph = self::foreignKeyGraph($pdo);
        $pending = array_fill_keys($tables, true);
        $order = [];

        while ($pending !== []) {
            $progressed = false;

            foreach (array_keys($pending) as $table) {
                foreach ($graph[$table] ?? [] as $parent) {
                    if (isset($pending[$parent])) {
                        continue 2;
                    }
                }

                $order[] = $table;
                unset($pending[$table]);
                $progressed = true;
            }

            if (!$progressed) {
                throw new RuntimeException(
                    'El grafo de claves foráneas del motor nuevo contiene un ciclo entre: '
                    . implode(', ', array_keys($pending))
                    . '. El arnés no puede sembrar una fila por tabla sin resolverlo antes.'
                );
            }
        }

        return $order;
    }

    // ─────────────────────────────────────────────────────────────────────
    // Alta mínima de una fila, respetando el grafo de claves foráneas
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Escribe una fila válida en la tabla y devuelve la fila **tal como quedó**, con la clave
     * primaria y todos los valores que puso el motor.
     *
     * Se devuelve la fila completa (`RETURNING *`) y no el identificador a secas porque es lo
     * que necesitan las hijas: sus columnas de clave foránea toman el valor de la columna
     * referenciada, sin suponer que el padre tenga un `id` generado.
     *
     * @param array<string, array<string, mixed>> $parents filas ya insertadas, por tabla
     * @param string $suffix marca corta para que dos altas de la misma tabla no choquen con las
     *                       columnas únicas textuales
     * @return array<string, mixed>
     */
    public static function insertMinimumRow(PDO $pdo, string $table, array $parents, string $suffix = '01'): array
    {
        $columns = self::columns($pdo, $table);
        $foreignKeys = self::foreignKeyTargets($pdo, $table);
        $targetColumns = [];
        $valueExpressions = [];
        $params = [];
        $requiredOptional = self::REQUIRED_OPTIONAL_COLUMNS[$table] ?? [];

        foreach ($columns as $name => $meta) {
            if ($meta['identity'] || $meta['generated'] || $meta['default'] !== null) {
                continue;
            }

            if ($meta['nullable'] && !in_array($name, $requiredOptional, true)) {
                continue;
            }

            $placeholder = 'v' . count($targetColumns);
            $targetColumns[] = '"' . $name . '"';

            if (isset($foreignKeys[$name])) {
                $target = $foreignKeys[$name];
                $parentRow = $parents[$target['table']] ?? null;

                if ($parentRow === null) {
                    throw new RuntimeException(sprintf(
                        'La tabla «%s» referencia a «%s» y esa fila no se ha insertado todavía: el orden del arnés no respeta el grafo.',
                        $table,
                        $target['table']
                    ));
                }

                $valueExpressions[] = ':' . $placeholder;
                $params[':' . $placeholder] = $parentRow[$target['column']] ?? null;
                continue;
            }

            if ($meta['udt_name'] === 'bool') {
                // Literal y no parámetro: es la lección del adaptador (D-3), la forma que los dos
                // motores entienden sin traducción.
                $valueExpressions[] = 'TRUE';
                continue;
            }

            $valueExpressions[] = ':' . $placeholder;
            $params[':' . $placeholder] = self::valueFor($pdo, $table, $name, $suffix, 'insert');
        }

        // Una tabla cuyas columnas obligatorias todas traen valor por defecto (`route_settings`) se
        // siembra con `DEFAULT VALUES`: escribir una lista de columnas vacía no es SQL válido, y
        // saltarse la tabla dejaría el escenario sin cubrir en silencio.
        $sql = $targetColumns === []
            ? sprintf('INSERT INTO "%s" DEFAULT VALUES RETURNING *', $table)
            : sprintf(
                'INSERT INTO "%s" (%s) VALUES (%s) RETURNING *',
                $table,
                implode(', ', $targetColumns),
                implode(', ', $valueExpressions)
            );

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();

        if (!is_array($row)) {
            throw new RuntimeException("El motor nuevo no devolvió la fila insertada en «{$table}».");
        }

        return $row;
    }

    /**
     * Columna actualizable para certificar el `UPDATE`: la primera que el motor deja cambiar.
     *
     * Se evita la clave primaria (moverla es otra historia), las columnas de identidad, las
     * generadas y **las claves foráneas**: escribir ahí un valor sintetizado movería la fila a un
     * padre que no existe y el arnés estaría midiendo su propia torpeza en lugar del motor (el
     * rechazo por clave foránea es correcto y pertenece a otra certificación). El orden de
     * preferencia —texto antes que cualquier otra— es deliberado: una columna textual permite
     * comprobar que el valor **cambia** de verdad, no solo que la sentencia se ejecuta.
     */
    public static function updatableColumn(PDO $pdo, string $table, array $row): string
    {
        $primaryKey = self::primaryKey($pdo, $table);
        $foreignKeys = self::foreignKeyTargets($pdo, $table);
        $candidates = [];

        foreach (self::columns($pdo, $table) as $name => $meta) {
            if ($meta['identity'] || $meta['generated'] || in_array($name, $primaryKey, true)) {
                continue;
            }
            if (isset($foreignKeys[$name])) {
                continue;
            }
            if (in_array($name, self::CRUD_EXCLUDED_COLUMNS[$table] ?? [], true)) {
                continue;
            }
            if (!array_key_exists($name, $row)) {
                continue;
            }
            $candidates[$name] = $meta;
        }

        foreach (['text', 'varchar', 'bpchar'] as $preferred) {
            foreach ($candidates as $name => $meta) {
                if ($meta['udt_name'] === $preferred) {
                    return $name;
                }
            }
        }

        foreach ($candidates as $name => $meta) {
            if (in_array($meta['udt_name'], self::assignableTypes(), true)) {
                return $name;
            }
        }

        throw new RuntimeException("La tabla «{$table}» no tiene ninguna columna actualizable: el CRUD no puede certificarse a medias.");
    }

    /**
     * Fragmento `SET` y parámetros para escribir un valor nuevo en la columna indicada.
     *
     * @return array{sql: string, params: array<string, mixed>, expected: mixed}
     */
    public static function assignment(PDO $pdo, string $table, string $column, string $suffix): array
    {
        $columns = self::columns($pdo, $table);
        $meta = $columns[$column] ?? null;

        if ($meta === null) {
            throw new RuntimeException("La columna «{$column}» no existe en la tabla «{$table}».");
        }

        if ($meta['udt_name'] === 'bool') {
            return ['sql' => '"' . $column . '" = FALSE', 'params' => [], 'expected' => false];
        }

        $value = self::valueFor($pdo, $table, $column, $suffix, 'update');

        return [
            'sql' => '"' . $column . '" = :' . $column,
            'params' => [':' . $column => $value],
            'expected' => $value,
        ];
    }

    /**
     * ¿El valor leído es el que se escribió? Comparación por tipo nativo, no por cadena cruda.
     *
     * El motor devuelve cada familia en su forma —`NUMERIC` como cadena, `BOOLEAN` como verdadero
     * o falso, `TIMESTAMPTZ` normalizado a UTC—, así que compararlas como texto certificaría el
     * formato del motor en lugar del valor escrito.
     */
    public static function sameScalar(string $udtName, mixed $expected, mixed $actual): bool
    {
        if (in_array($udtName, ['bool'], true)) {
            return self::asBoolean($expected) === self::asBoolean($actual);
        }

        if (in_array($udtName, ['int2', 'int4', 'int8', 'numeric', 'float4', 'float8'], true)) {
            return abs((float)$expected - (float)$actual) < 0.000001;
        }

        if (in_array($udtName, ['timestamptz', 'timestamp', 'date', 'time'], true)) {
            $expectedTime = strtotime((string)$expected);
            $actualTime = strtotime((string)$actual);

            return $expectedTime !== false && $expectedTime === $actualTime;
        }

        return trim((string)$expected) === trim((string)$actual);
    }

    /**
     * `SQLSTATE` de una operación rechazada, o `null` si no hubo rechazo.
     *
     * @return array{state: string, message: string}|null
     */
    public static function expectRejection(callable $operation): ?array
    {
        try {
            $operation();
        } catch (Throwable $exception) {
            $state = $exception instanceof PDOException ? (string)($exception->getCode()) : '';

            return ['state' => $state !== '' ? $state : (string)($exception->getCode()), 'message' => $exception->getMessage()];
        }

        return null;
    }

    // ─────────────────────────────────────────────────────────────────────
    // Interior
    // ─────────────────────────────────────────────────────────────────────

    /** Familias de tipo que el arnés sabe escribir solo, sin inventar contenido de negocio. */
    private static function assignableTypes(): array
    {
        return ['int2', 'int4', 'int8', 'numeric', 'float4', 'float8', 'text', 'varchar', 'bpchar',
            'timestamptz', 'timestamp', 'date', 'time', 'json', 'jsonb', 'bool'];
    }

    /**
     * Valor sintetizado para una columna obligatoria, según el tipo nativo que declara el motor.
     *
     * `insert` y `update` producen valores **distintos** a propósito: es lo que permite afirmar
     * que el `UPDATE` cambió la fila en vez de reescribir lo que ya había.
     */
    private static function valueFor(PDO $pdo, string $table, string $column, string $suffix, string $variant): mixed
    {
        $meta = self::columns($pdo, $table)[$column] ?? null;

        if ($meta === null) {
            throw new RuntimeException("La columna «{$column}» no existe en la tabla «{$table}».");
        }

        $udt = $meta['udt_name'];
        $literal = 'FX' . $suffix . ' ' . self::FILLER_TEXT;

        return match (true) {
            // La nota de la encuesta CSAT está acotada por `chk_survey_rating` a 1–5:
            // el entero genérico (7) convertiría el alta mínima en un rechazo legítimo.
            $column === 'rating' && $udt === 'int2' => $variant === 'update' ? 4 : 5,
            $table === 'technician_absences' && $column === 'reason' => $variant === 'update' ? 'SICK_LEAVE' : 'VACATION',
            $table === 'technician_shifts' && $column === 'status' => $variant === 'update' ? 'OFF_SHIFT' : 'ON_SHIFT',
            in_array($udt, ['int2', 'int4', 'int8'], true) => $variant === 'update' ? 9 : 7,
            $udt === 'numeric', $udt === 'float4', $udt === 'float8' => $variant === 'update' ? '9.5000000' : '1.5000000',
            $udt === 'text' => $literal,
            // El código postal es la llave territorial del Módulo 17 y su formato
            // está restringido por `CHECK` a cinco dígitos: sintetizar texto libre
            // convertiría el alta en un rechazo legítimo del motor y el CRUD no se
            // certificaría. Alta y actualización usan valores distintos para que la
            // modificación se pueda comprobar.
            $column === 'postal_code' && in_array($udt, ['varchar', 'bpchar'], true)
                => $variant === 'update' ? '08999' : '08001',
            in_array($udt, ['varchar', 'bpchar'], true) => substr($literal, 0, $meta['max_length'] ?? 255),
            in_array($udt, ['timestamptz', 'timestamp'], true) => $variant === 'update' ? '2027-01-01 08:30:00+00' : self::FILLER_TIMESTAMP,
            $udt === 'date' => $variant === 'update' ? '2027-01-01' : '2026-10-14',
            $udt === 'time' => $variant === 'update' ? '08:30:00' : '12:00:00',
            // El documento congelado de la hoja de servicio declara su versión:
            // el `CHECK` del Módulo 19 rechaza un objeto vacío, y un `{}`
            // sintetizado convertiría el alta mínima en un rechazo legítimo.
            // El documento congelado de la hoja de servicio declara su versión:
            // el `CHECK` del Módulo 19 rechaza un objeto vacío, y un `{}`
            // sintetizado convertiría el alta mínima en un rechazo legítimo.
            $column === 'signed_document' && in_array($udt, ['json', 'jsonb'], true) => '{"version":1}',
            in_array($udt, ['json', 'jsonb'], true) => '{}',
            $meta['data_type'] === 'USER-DEFINED' => self::enumLabel($pdo, $udt, $variant),
            default => throw new RuntimeException(sprintf(
                'El arnés no sabe sintetizar un valor para «%s.%s» (tipo del motor «%s»): añade la familia o declara la columna como opcional antes de certificar el CRUD.',
                $table,
                $column,
                $udt
            )),
        };
    }

    /**
     * Etiqueta del tipo enumerado del dominio: la primera para el alta y la **última** para la
     * actualización, de modo que el `UPDATE` cambie el valor aunque la columna sea de tipo
     * enumerado.
     */
    private static function enumLabel(PDO $pdo, string $typeName, string $variant): string
    {
        $stmt = $pdo->prepare(
            'SELECT e.enumlabel
               FROM pg_enum e
               JOIN pg_type t ON t.oid = e.enumtypid
              WHERE t.typname = :type
              ORDER BY e.enumsortorder'
        );
        $stmt->execute([':type' => $typeName]);
        $labels = array_map(static fn (array $row): string => (string)$row['enumlabel'], $stmt->fetchAll());

        if ($labels === []) {
            throw new RuntimeException("El tipo enumerado «{$typeName}» no declara ninguna etiqueta en el motor nuevo.");
        }

        return $variant === 'update' ? $labels[count($labels) - 1] : $labels[0];
    }

    /**
     * Pares columna-hija / tabla-padre / columna-padre de las claves foráneas del esquema.
     *
     * @return list<array{child_table: string, child_column: string, parent_table: string, parent_column: string}>
     */
    private static function foreignKeyEdges(PDO $pdo): array
    {
        $stmt = $pdo->query(
            "SELECT child.relname  AS child_table,
                    child_col.attname AS child_column,
                    parent.relname AS parent_table,
                    parent_col.attname AS parent_column
               FROM pg_constraint con
               JOIN pg_class child ON child.oid = con.conrelid
               JOIN pg_namespace ns ON ns.oid = child.relnamespace
               JOIN pg_class parent ON parent.oid = con.confrelid
               JOIN pg_namespace pns ON pns.oid = parent.relnamespace
               JOIN generate_subscripts(con.conkey, 1) AS k ON TRUE
               JOIN pg_attribute child_col ON child_col.attrelid = con.conrelid AND child_col.attnum = con.conkey[k]
               JOIN pg_attribute parent_col ON parent_col.attrelid = con.confrelid AND parent_col.attnum = con.confkey[k]
              WHERE con.contype = 'f' AND ns.nspname = current_schema() AND pns.nspname = current_schema()"
        );

        return array_map(
            static fn (array $row): array => [
                'child_table' => (string)$row['child_table'],
                'child_column' => (string)$row['child_column'],
                'parent_table' => (string)$row['parent_table'],
                'parent_column' => (string)$row['parent_column'],
            ],
            $stmt->fetchAll()
        );
    }

    /**
     * Columna => tabla y columna referenciadas, para una tabla concreta.
     *
     * @return array<string, array{table: string, column: string}>
     */
    private static function foreignKeyTargets(PDO $pdo, string $table): array
    {
        $targets = [];
        foreach (self::foreignKeyEdges($pdo) as $edge) {
            if ($edge['child_table'] === $table) {
                $targets[$edge['child_column']] = ['table' => $edge['parent_table'], 'column' => $edge['parent_column']];
            }
        }

        return $targets;
    }

    private static function asBoolean(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        $normalized = strtolower(trim((string)$value));

        return in_array($normalized, ['1', 't', 'true', 'y', 'yes', 'on'], true);
    }
}
