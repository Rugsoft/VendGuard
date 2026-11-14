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
 * válida en cada una de las 38 tablas** respetando el grafo de claves foráneas.
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
        // El acta del Módulo 29 nace con coste 0,00 € por defecto, pero una fila
        // con unidades destruidas exige coste positivo
        // (`chk_spoilage_records_loss_non_negative`): el alta mínima debe
        // declararlo o mediría un rechazo legítimo del motor.
        'sanitary_spoilage_records' => ['estimated_merchandise_loss_eur'],
        // El catálogo comercial (Módulo 32) declara `sale_price_eur` con defecto
        // 0,00 €, válido solo para ingredientes y consumibles: una categoría de
        // venta directa con precio cero violaría `chk_vending_products_sale_price`
        // y el alta mínima mediría un rechazo legítimo del motor.
        'vending_products' => ['sale_price_eur'],
        // La visita in situ (Módulo 34) exige lectura térmica en toda máquina
        // refrigerada (`enforce_restock_visit_sanitary_guard`): el alta mínima
        // nace sobre una máquina `COMBO`, así que sin declararla el alta se
        // convertiría en un rechazo legítimo del Art. II y no en el CRUD.
        'machine_restock_visits' => ['cabin_temp_celsius'],
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
        // Órdenes de carga del Módulo 23: cada columna de estado pertenece a una
        // rama de `CHECK` (despacho, confirmación y cancelación). El `UPDATE`
        // genérico de certificación toca `notes` en la cabecera y una columna
        // numérica de la línea, de modo que no deja la fila en una rama imposible.
        'van_restock_orders' => ['status', 'dispatched_at', 'dispatched_by', 'confirmed_at', 'confirmed_by', 'cancelled_at', 'cancelled_by', 'cancellation_reason'],
        'van_restock_order_items' => ['reason', 'dispatched_qty', 'removed_at', 'removed_by'],
        // Libro de idempotencia del Módulo 24: el identificador del cliente tiene un
        // formato cerrado y los otros tres campos pertenecen a catálogos cerrados.
        // El `UPDATE` genérico escribiría texto sintético y el motor lo rechazaría por
        // diseño; la modificación se certifica sobre la marca temporal.
        'offline_sync_mutations' => ['client_mutation_id', 'stop_type', 'operation_type', 'outcome_status'],
        // El perfil sellado del Módulo 30 es inmutable por disparador: el
        // UPDATE genérico nunca debe elegirlo como columna de certificación.
        'incidents' => ['sla_tier_snapshot'],
        // Catálogo maestro del Módulo 25: `code` es inmutable por Constitución
        // (Art. III.3, C4) y su disparador rechaza la reescritura. El `UPDATE`
        // genérico certifica el CRUD sobre una columna descriptiva mutable.
        'root_causes' => ['code'],
        'standard_solutions' => ['code'],
        // Guías del Módulo 28: el modelo y la categoría son la identidad inmutable
        // de la guía (C1) y su disparador rechaza la reescritura; el `UPDATE`
        // genérico certifica el CRUD sobre el título, que sí es editable.
        // El SKU del Módulo 32 es inmutable por disparador (C1): el UPDATE
        // genérico no puede elegirlo o certificaría un rechazo legítimo del
        // motor en lugar del CRUD. La modificación se mide sobre `name`.
        'vending_products' => ['sku'],
        'machine_troubleshooting_guides' => ['machine_model', 'machine_model_key', 'breakdown_type'],
        // Tramos de custodia (Módulo 26): los campos de cierre forman una rama
        // todo-o-nada de `chk_incident_assignments_closure_coherence`, de modo
        // que el `UPDATE` genérico los dejaría en un estado que el motor rechaza
        // por diseño. La certificación de la transición legítima de cierre tiene
        // su propio ayudante (`closeCustodySegment`).
        'incident_assignments' => ['unassigned_at', 'unassignment_reason_type', 'unassigned_by_name_snapshot', 'reassignment_reason'],
        // Acta de merma del Módulo 29: todos los hechos sanitarios y físicos son
        // inmutables (Art. III.3); el `UPDATE` genérico certifica el único cambio
        // legítimo, el coste estimado que ajusta Coordinación.
        // Cuadrante de carga del Módulo 31: `role_in_zone` e `is_primary` son las
        // dos formas del mismo vínculo y `chk_restocker_zone_role_coherence` exige
        // que viajen de la mano. El `UPDATE` genérico toca el instante del alta, que
        // no participa en ningún `CHECK` de rama.
        'restocker_zone_assignments' => ['role_in_zone', 'is_primary'],
        'sanitary_spoilage_records' => [
            'record_code', 'machine_id', 'incident_id', 'maintenance_order_id',
            'technician_id', 'registered_by', 'quarantine_origin',
            'discarded_units_count', 'empty_machine_confirmed', 'affected_batch_codes',
            'cabin_temp_at_removal', 'restored_stabilized_temp_c', 'disposal_method',
            'evidence_photo_url', 'sanitary_notes', 'loss_adjustment_reason',
            'certificate_code', 'created_at',
        ],
        // Orden de carga del Módulo 33: `status`, `demand_mode`, la temperatura de
        // salida de cámara y los campos de cancelación y liquidación pertenecen a
        // ramas de `CHECK` o al cerrojo sanitario. El `UPDATE` genérico certifica
        // el CRUD sobre `notes`, que no participa en ninguna regla de estado.
        'product_loading_orders' => [
            'order_code', 'status', 'demand_mode', 'cold_chamber_exit_temp_celsius',
            'cancel_reason', 'cancelled_at', 'reconciliation_notes', 'reconciled_at',
            'reconciled_by', 'dispatched_at', 'dispatched_by', 'confirmed_at',
        ],
        // La línea del Módulo 33 declara el cuadre como identidad aritmética
        // (`chk_product_loading_order_items_reconcile_bounds`): tocar una sola de
        // sus columnas dejaría la fila en una rama imposible. La modificación se
        // certifica sobre `requested_qty`, acotada a 1..99999.
        'product_loading_order_items' => [
            'reason', 'batch_code', 'expiration_date', 'dispatched_qty',
            'van_stock_before_reconcile', 'restocked_in_machines_qty',
            'withdrawn_spoilage_qty', 'expected_return_qty', 'counted_return_qty',
            'discrepancy_qty', 'is_active',
        ],
        // Saldo por lote del Módulo 33: el lote y su caducidad los blinda el
        // cerrojo sanitario (Art. II) y son la identidad del saldo. El `UPDATE`
        // genérico certifica el CRUD sobre `quantity_available`.
        'restocker_van_product_stocks' => ['batch_code', 'expiration_date'],
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
            if ($meta['identity'] || $meta['generated']) {
                continue;
            }

            // Una columna con valor por defecto también puede participar en un
            // `CHECK` de rama: si el arnés no la escribe, el alta mínima nace con
            // un valor que el motor rechaza por diseño. Las columnas declaradas en
            // `REQUIRED_OPTIONAL_COLUMNS` se escriben aunque tengan defecto.
            if ($meta['default'] !== null && !in_array($name, $requiredOptional, true)) {
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

    /**
     * Abre un tramo de custodia en `incident_assignments` (Tabla 37, Módulo 26).
     *
     * Escribe siempre el tramo **abierto**: fecha y campos de cierre nulos, que es la
     * única rama que admite `chk_incident_assignments_closure_coherence`. El índice
     * único parcial rechaza un segundo tramo abierto sobre la misma avería, así que
     * una suite que encadene reasignaciones debe cerrar el anterior con
     * `closeCustodySegment()` antes de abrir el siguiente (Art. V.3).
     *
     * @return array<string, mixed> la fila tal como quedó en el motor
     */
    public static function openCustodySegment(
        PDO $pdo,
        int $incidentId,
        int $technicianId,
        string $source = 'COORDINATOR_ASSIGN',
        ?string $assignedAt = null
    ): array {
        $technician = $pdo->prepare('SELECT name, operator_code FROM users WHERE id = :id');
        $technician->execute([':id' => $technicianId]);
        $technicianRow = $technician->fetch();
        if (!is_array($technicianRow)) {
            throw new RuntimeException('El técnico del tramo de custodia no existe en el banco de pruebas.');
        }

        $code = trim((string)($technicianRow['operator_code'] ?? ''));

        $stmt = $pdo->prepare(
            'INSERT INTO incident_assignments ('
            . ' incident_id, technician_id, technician_name_snapshot, technician_code_snapshot,'
            . ' assignment_source, assigned_by_name_snapshot, assigned_at'
            . ') VALUES ('
            . ' :incident_id, :technician_id, :name, :code,'
            . ' :source, :assigned_by_name, COALESCE(:assigned_at::timestamptz, CURRENT_TIMESTAMP)'
            . ') RETURNING *'
        );
        $stmt->execute([
            ':incident_id' => $incidentId,
            ':technician_id' => $technicianId,
            ':name' => (string)$technicianRow['name'],
            ':code' => $code !== '' ? $code : 'SIN-CODIGO',
            ':source' => $source,
            ':assigned_by_name' => 'Coordinación / Sistema',
            ':assigned_at' => $assignedAt,
        ]);

        $row = $stmt->fetch();
        if (!is_array($row)) {
            throw new RuntimeException('El motor nuevo no devolvió el tramo de custodia insertado.');
        }

        return $row;
    }

    /**
     * Sella el tramo de custodia abierto con su causa tipificada (Módulo 26).
     *
     * Aplica el suelo temporal `GREATEST(assigned_at, unassigned_at)` que exige QA 4:
     * una resolución sincronizada desde la cola PWA puede llegar con una hora de
     * cliente anterior a la apertura, y el tramo nunca puede durar menos que cero.
     * Congela el nombre del actor de cierre tal como figura ahora (D2).
     *
     * @return array<string, mixed> la fila sellada
     */
    public static function closeCustodySegment(
        PDO $pdo,
        int $segmentId,
        string $reasonType,
        int $closedByUserId,
        string $reason,
        ?string $unassignedAt = null
    ): array {
        $actor = $pdo->prepare('SELECT name FROM users WHERE id = :id');
        $actor->execute([':id' => $closedByUserId]);
        $actorName = $actor->fetchColumn();
        if (!is_string($actorName) || trim($actorName) === '') {
            throw new RuntimeException('El actor de cierre del tramo no existe en el banco de pruebas.');
        }

        $stmt = $pdo->prepare(
            'UPDATE incident_assignments'
            . ' SET unassigned_at = GREATEST(assigned_at, COALESCE(:unassigned_at::timestamptz, CURRENT_TIMESTAMP)),'
            . '     unassigned_by = :closed_by,'
            . '     unassigned_by_name_snapshot = :closed_by_name,'
            . '     unassignment_reason_type = :reason_type,'
            . '     reassignment_reason = :reason'
            . ' WHERE id = :id AND unassigned_at IS NULL'
            . ' RETURNING *'
        );
        $stmt->execute([
            ':unassigned_at' => $unassignedAt,
            ':closed_by' => $closedByUserId,
            ':closed_by_name' => $actorName,
            ':reason_type' => $reasonType,
            ':reason' => $reason,
            ':id' => $segmentId,
        ]);

        $row = $stmt->fetch();
        if (!is_array($row)) {
            throw new RuntimeException('El tramo de custodia no estaba abierto o no existe: no se pudo sellar.');
        }

        return $row;
    }

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
            // La bitácora del Módulo 22 sólo admite los seis eventos canónicos de
            // `ShiftEventType`: el texto sintético violaría `chk_shift_logs_event_type`
            // y el escenario de las suites heredadas no llegaría a sembrarse.
            $table === 'technician_shift_logs' && $column === 'event_type'
                => $variant === 'update' ? 'RESUME_SHIFT' : 'START_SHIFT',
            // El alta mínima del Módulo 24 necesita valores que respeten el formato
            // del identificador y los dos catálogos cerrados; el texto sintético
            // genérico convertiría el alta en un rechazo legítimo del motor.
            $table === 'offline_sync_mutations' && $column === 'client_mutation_id'
                => $variant === 'update' ? 'fixture.update.m24.0001' : 'fixture.insert.m24.0001',
            $table === 'offline_sync_mutations' && $column === 'stop_type' => 'INCIDENT',
            $table === 'offline_sync_mutations' && $column === 'operation_type' => 'START_INCIDENT',
            // El catálogo de taxonomía (Módulo 25) tiene un formato de código
            // cerrado (`^[A-Z0-9_-]{3,32}$`): el texto sintético genérico, con
            // espacios y minúsculas, convertiría el alta mínima en un rechazo
            // legítimo del CHECK y el CRUD dejaría de certificarse.
            $table === 'root_causes' && $column === 'code'
                => $variant === 'update' ? 'RC-FIXTURE-UPDATE' : 'RC-FIXTURE-INSERT',
            $table === 'standard_solutions' && $column === 'code'
                => $variant === 'update' ? 'SOL-FIXTURE-UPDATE' : 'SOL-FIXTURE-INSERT',
            // El acta del Módulo 29 declara dos catálogos cerrados por `CHECK`:
            // el origen de la cuarentena y el método de destrucción. El texto
            // sintético genérico los rechazaría y el alta mínima no certificaría
            // la rama con producto destruido.
            $table === 'sanitary_spoilage_records' && $column === 'quarantine_origin'
                => $variant === 'update' ? 'PREVENTIVE_NON_CONFORMITY' : 'BIOLOGICAL_CLOCK_4H',
            $table === 'sanitary_spoilage_records' && $column === 'disposal_method'
                => $variant === 'update' ? 'RETURN_TO_CENTRAL_BIOHAZARD' : 'ON_SITE_ORGANIC_SEALED',
            // El objetivo de SLA (Módulo 30) sólo admite los cinco valores de
            // la matriz RF-01: el entero genérico (7/9) violaría
            // `chk_incidents_sla_target_minutes` y el alta mínima de incidents
            // dejaría de certificarse.
            // El catálogo del Módulo 32 declara formatos cerrados: el SKU y el
            // código de carril solo admiten mayúsculas, dígitos, guion bajo y
            // guion (el SKU); el texto sintético genérico los rechazaría.
            $table === 'vending_products' && $column === 'sku'
                => $variant === 'update' ? 'PROD_FIXTURE_UPDATE' : 'PROD_FIXTURE_INSERT',
            $table === 'machine_planogram_slots' && $column === 'slot_code'
                => $variant === 'update' ? 'SLOT_FIXTURE_UPDATE' : 'SLOT_FIXTURE_INSERT',
            // El alta mínima de una máquina nace en `COMBO`: es la única
            // tipología que admite todas las categorías del catálogo y permite
            // que el carril mínimo del Módulo 32 se siembre sin convertir la
            // matriz `MachineType × ProductCategory` en el objeto de la prueba.
            // La orden de carga del Módulo 33 exige el formato `PLO-AAAA-NNNN`: el
            // texto sintético genérico lo violaría y el alta mínima mediría un
            // rechazo legítimo del motor en lugar del CRUD.
            $table === 'product_loading_orders' && $column === 'order_code'
                => $variant === 'update' ? 'PLO-2026-9002' : 'PLO-2026-9001',
            $table === 'machines' && $column === 'machine_type' => 'COMBO',
            // La visita in situ del Módulo 34 declara dos formatos cerrados: el
            // código legible (`MRV-AAAA-NNNN`) y un identificador de mutación de
            // seis caracteres como mínimo. El texto sintético genérico violaría
            // ambos `CHECK` y el alta mínima mediría un rechazo del motor.
            $table === 'machine_restock_visits' && $column === 'visit_code'
                => $variant === 'update' ? 'MRV-2026-9002' : 'MRV-2026-9001',
            $table === 'machine_restock_visits' && $column === 'client_mutation_id'
                => $variant === 'update' ? 'fixture.update.m34.0001' : 'fixture.insert.m34.0001',
            // El carril de la línea repite el formato del planograma (mayúsculas,
            // dígitos, guion y guion bajo) y es parte de su identidad única.
            $table === 'machine_restock_visit_items' && $column === 'slot_code'
                => $variant === 'update' ? 'SLOT_FIXTURE_UPDATE' : 'SLOT_FIXTURE_INSERT',
            $table === 'incidents' && $column === 'sla_target_minutes'
                => $variant === 'update' ? 720 : 1440,
            in_array($udt, ['int2', 'int4', 'int8'], true) => $variant === 'update' ? 9 : 7,
            $udt === 'numeric', $udt === 'float4', $udt === 'float8' => $variant === 'update' ? '9.5000000' : '1.5000000',
            $udt === 'text' => $literal,
            // El código postal es la llave territorial del Módulo 17 y su formato
            // está restringido por `CHECK` a cinco dígitos: sintetizar texto libre
            // convertiría el alta en un rechazo legítimo del motor y el CRUD no se
            // certificaría. Alta y actualización usan valores distintos para que la
            // modificación se pueda comprobar.
            // La guía del Módulo 28 exige la clave de modelo plegada a minúsculas:
            // el texto sintético genérico, con mayúsculas y espacios, violaría
            // `chk_troubleshooting_guides_model_not_empty` en el alta mínima.
            $table === 'machine_troubleshooting_guides' && $column === 'machine_model_key'
                => $variant === 'update' ? 'fixture.model.update' : 'fixture.model.insert',
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
            // La guía del Módulo 28 exige entre dos y diez pasos y una clave de
            // modelo plegada a minúsculas: el objeto vacío y el texto sintético en
            // mayúsculas convertirían el alta mínima en un rechazo legítimo.
            $table === 'machine_troubleshooting_guides' && $column === 'steps_json'
                => $variant === 'update'
                    ? '[{"step_order":1,"title":"Paso sintético actualizado","instruction":"Volver a medir el componente tras la actualización del banco."},{"step_order":2,"title":"Paso sintético de cierre","instruction":"Confirmar que la medida quedó dentro del rango declarado."}]'
                    : '[{"step_order":1,"title":"Paso sintético de alta","instruction":"Comprobar el componente sintético del banco de pruebas."},{"step_order":2,"title":"Paso sintético de cierre","instruction":"Verificar el resultado del paso anterior antes de cerrar."}]',
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
    /**
     * Línea de sustitución con destino `TALLER` para las suites del Módulo 27.
     *
     * El alta genérica elegiría `DESGUACE` (primer valor del enumerado) y la bandeja
     * de taller se quedaría sin padre que certificar. Aquí se declara explícitamente
     * la extracción hacia taller, con la cantidad pedida, para que el backfill pueda
     * demostrar el desglose `1..N`.
     *
     * @param array<string, array<string, mixed>> $parents Filas padre por tabla.
     * @return array<string, mixed> La fila insertada.
     */
    public static function insertWorkshopReplacedPart(PDO $pdo, array $parents, string $suffix = '01', int $quantity = 1): array
    {
        $stmt = $pdo->prepare(
            "INSERT INTO incident_replaced_parts (
                intervention_type, incident_id, machine_id, location_id, technician_id,
                spare_part_id, quantity, unit_cost_snapshot, old_part_destination, notes, installed_at
             ) VALUES (
                'INCIDENT', :incident_id, :machine_id, :location_id, :technician_id,
                :spare_part_id, :quantity, 24.50, 'TALLER', :notes, '2026-10-02 09:00:00+00'
             ) RETURNING *"
        );
        $stmt->execute([
            ':incident_id' => $parents['incidents']['id'] ?? null,
            ':machine_id' => $parents['machines']['id'],
            ':location_id' => $parents['locations']['id'],
            ':technician_id' => $parents['users']['id'],
            ':spare_part_id' => $parents['spare_parts']['id'] ?? null,
            ':quantity' => $quantity,
            ':notes' => 'Sustitución de taller ' . $suffix . ' para certificación del Módulo 27.',
        ]);
        $row = $stmt->fetch();
        if (!is_array($row)) {
            throw new RuntimeException('El motor no devolvió la sustitución de taller insertada.');
        }

        return $row;
    }

    /**
     * Expediente unitario de la bandeja de taller con sus snapshots resueltos.
     *
     * Los snapshots se leen de la línea padre en el instante del alta, que es lo que
     * congela el contrato (Art. III.3): la suite no los inventa a mano. `$overrides`
     * permite declarar el estado y sus sellos para las pruebas de coherencia.
     *
     * @param array<string, mixed> $overrides Columnas a sobrescribir del valor por defecto.
     * @return array<string, mixed> La fila insertada.
     */
    public static function insertWorkshopUnit(
        PDO $pdo,
        int $replacedPartId,
        int $unitIndex,
        int $totalUnits,
        string $status = 'PENDING_DELIVERY',
        array $overrides = []
    ): array {
        $sourceStmt = $pdo->prepare(
            "SELECT p.id, p.intervention_type, p.incident_id, p.preventive_order_id,
                    p.machine_id, p.location_id, p.technician_id, p.spare_part_id,
                    p.is_out_of_catalog, p.unit_cost_snapshot,
                    m.code AS machine_code, m.model AS machine_model,
                    l.name AS location_name, u.name AS technician_name,
                    sp.part_code, sp.name AS part_name,
                    COALESCE(sp.category::text, 'OTHER') AS part_category
               FROM incident_replaced_parts p
               JOIN machines m ON m.id = p.machine_id
               JOIN locations l ON l.id = p.location_id
               JOIN users u ON u.id = p.technician_id
               LEFT JOIN spare_parts sp ON sp.id = p.spare_part_id
              WHERE p.id = :id"
        );
        $sourceStmt->execute([':id' => $replacedPartId]);
        $source = $sourceStmt->fetch();
        if (!is_array($source)) {
            throw new RuntimeException('La sustitución padre no existe: no hay snapshots que congelar.');
        }

        $data = array_merge([
            'status' => $status,
            'received_at' => null,
            'received_by_user_id' => null,
            'reception_notes' => null,
            'bench_started_at' => null,
            'bench_diagnosis' => null,
            'refurbishment_cost_eur' => '0.00',
            'net_savings_eur' => '0.00',
            'restock_destination' => null,
            'restocked_to_technician_id' => null,
            'resolved_at' => null,
            'resolved_by_user_id' => null,
        ], $overrides);

        $stmt = $pdo->prepare(
            "INSERT INTO workshop_repair_items (
                incident_replaced_part_id, unit_index, total_units_in_line,
                intervention_type, incident_id, preventive_order_id, machine_id, location_id,
                extracted_by_technician_id, spare_part_id, is_out_of_catalog,
                part_code_snapshot, part_name_snapshot, part_category_snapshot,
                machine_code_snapshot, machine_model_snapshot, location_name_snapshot,
                technician_name_snapshot, unit_cost_snapshot, status,
                received_at, received_by_user_id, reception_notes,
                bench_started_at, bench_diagnosis, refurbishment_cost_eur, net_savings_eur,
                restock_destination, restocked_to_technician_id,
                resolved_at, resolved_by_user_id
             ) VALUES (
                :part_id, :unit_index, :total_units,
                :intervention_type, :incident_id, :preventive_order_id, :machine_id, :location_id,
                :technician_id, :spare_part_id, :is_out_of_catalog,
                :part_code, :part_name, :part_category,
                :machine_code, :machine_model, :location_name,
                :technician_name, :unit_cost, :status,
                :received_at, :received_by_user_id, :reception_notes,
                :bench_started_at, :bench_diagnosis, :refurbishment_cost, :net_savings,
                :restock_destination, :restocked_to_technician_id,
                :resolved_at, :resolved_by_user_id
             ) RETURNING *"
        );
        $stmt->execute([
            ':part_id' => $replacedPartId,
            ':unit_index' => $unitIndex,
            ':total_units' => $totalUnits,
            ':intervention_type' => $source['intervention_type'],
            ':incident_id' => $source['incident_id'],
            ':preventive_order_id' => $source['preventive_order_id'],
            ':machine_id' => $source['machine_id'],
            ':location_id' => $source['location_id'],
            ':technician_id' => $source['technician_id'],
            ':spare_part_id' => $source['spare_part_id'],
            ':is_out_of_catalog' => $source['is_out_of_catalog'],
            ':part_code' => $source['part_code'],
            ':part_name' => $source['part_name'] ?? 'Pieza extraída sin catálogo',
            ':part_category' => $source['part_category'],
            ':machine_code' => $source['machine_code'],
            ':machine_model' => $source['machine_model'],
            ':location_name' => $source['location_name'],
            ':technician_name' => $source['technician_name'],
            ':unit_cost' => $source['unit_cost_snapshot'],
            ':status' => $data['status'],
            ':received_at' => $data['received_at'],
            ':received_by_user_id' => $data['received_by_user_id'],
            ':reception_notes' => $data['reception_notes'],
            ':bench_started_at' => $data['bench_started_at'],
            ':bench_diagnosis' => $data['bench_diagnosis'],
            ':refurbishment_cost' => $data['refurbishment_cost_eur'],
            ':net_savings' => $data['net_savings_eur'],
            ':restock_destination' => $data['restock_destination'],
            ':restocked_to_technician_id' => $data['restocked_to_technician_id'],
            ':resolved_at' => $data['resolved_at'],
            ':resolved_by_user_id' => $data['resolved_by_user_id'],
        ]);
        $row = $stmt->fetch();
        if (!is_array($row)) {
            throw new RuntimeException('El motor no devolvió el expediente de taller insertado.');
        }

        return $row;
    }

}
