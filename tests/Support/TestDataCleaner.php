<?php

declare(strict_types=1);

/**
 * VendGuard - Limpiador de datos de pruebas compartido (dogma Vanilla)
 *
 * Proporciona un reinicio operacional seguro de la base de datos para las
 * suites de integración. Resuelve la fragilidad histórica en la que cada suite
 * repetía a mano un bloque `DELETE FROM incidents` que, en cuanto una tabla
 * hija con FK ON DELETE RESTRICT quedaba con filas (por un fatal previo que
 * saltó el `finally` de otra suite), reventaba con error 1451.
 *
 * Estrategia (causa raíz, no parche):*   - El orden de borrado NO está hardcodeado: se deriva del grafo real de
     *     claves foráneas leído de `information_schema`, y las tablas se topologizan
     *     para borrar siempre hijas antes que padres.
 *   - El conjunto de tablas a purgar se descubre transitivamente: se parte de
 *     unas raíces y se sigue el grafo de FKs hacia las hijas. Cualquier FK RESTRICT
 *     futura hacia una raíz (p. ej. `refund_requests` → `incidents`) queda
 *     cubierta automáticamente sin tocar este archivo.
 *   - Todo se ejecuta dentro de una transacción con rollback en `finally`, de
 *     modo que un fallo a media limpieza no deja la base a medias y no envenena
 *     las siguientes suites.
 *
 * Palabras prohibidas: no se usa `SET FOREIGN_KEY_CHECKS=0` (deja filas huérfanas
 * silenciosas, que es la ceguera que causó el problema) ni se tocan las tablas
 * maestras (users, machines, locations, settings).
 */

final class TestDataCleaner
{
    /**
     * Raíces cuyo subárbol transitorio de claves foráneas se purga por completo.
     * Cada raíz y todas sus descendientes (hijas) entran en el plan de borrado.
     */
    private const PURGE_ROOTS = [
        'incidents',
        'spare_parts',
        'preventive_orders',
        // Latido del barrido sanitario (T-PAUSE-41): es un libro operativo sin
        // claves foráneas —no cuelga de `incidents`, a propósito: registra pasadas,
        // no expedientes—, así que el grafo de FKs no puede alcanzarlo y entra como
        // raíz propia. Dejar sus filas entre suites haría que la ventana de
        // tendencia de una prueba contase pasadas de otra.
        'sanitary_sweep_passes',
    ];

    /**
     * Tablas maestras que nunca deben borrarse (se re-sieman con SeedRunner y su
     * borrado rompería la integridad referencial de otras entidades). Si por un
     * error de modelado alguna apareciera como hija en el grafo, se descarta.
     */
    private const PROTECTED_TABLES = [
        'users',
        'machines',
        'locations',
        'route_settings',
        'preventive_settings',
        'audit_log',
    ];

    /**
     * Tablas que contienen datos operativos de prueba.
     * Cualquier tabla hija descubierta que no esté en la lista se purga igual:
     * el grafo manda, la lista sólo es una salvaguarda.
     */
    private const KNOWN_OPERATIONAL = [
        'incident_comments',
        'incident_history',
        'incident_replaced_parts',
        'refund_requests',
        'spare_part_requests',
        'unclaimed_cash_findings',
        'preventive_order_items',
        'sanitary_certificates',
        'sanitary_sweep_contentions',
        'spare_part_compatibilities',
    ];

    /**
     * Prefijos reservados de las sedes del banco de pruebas (contrato §8.1).
     *
     * El seed canónico usa `SEDE-BCN-*` y sólo `SEDE-BCN-*`; cualquier sede que
     * empiece por estos prefijos la creó una suite y es del arnés. `SEDE-MAP`
     * cubre las tres familias territoriales (`SEDE-MAP-*`, `SEDE-MAP17-*`,
     * `SEDE-MAP18-*`) sin enumerarlas. Los prefijos NO se solapan entre sí, de
     * modo que el recuento por familia no cuenta dos veces la misma fila.
     */
    private const TEST_LOCATION_PREFIXES = ['SEDE-TEST-%', 'SEDE-MAP%'];

    /**
     * Tablas protegidas que SÍ entran en el drenaje de las sedes del banco de
     * pruebas. Son maestras para `purge()` —no deben vaciarse jamás—, pero aquí
     * el borrado es dirigido: sólo las filas que cuelgan de una sede de prueba.
     * El resto de `PROTECTED_TABLES` (users, route_settings,
     * preventive_settings, audit_log) queda excluido aunque el grafo lo
     * alcanzase, para que una FK nueva no pueda arrastrar una maestra.
     */
    private const TEST_LOCATION_PURGEABLE_MASTERS = ['locations', 'machines'];

    /** @var array<string, array<string, string>> tabla => (FK => tabla padre) caché por esquema */
    private static array $fkGraphCache = [];

    // ─────────────────────────────────────────────────────────────────────
    // API pública
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Reinicio operacional completo: purga, en orden seguro, todas las tablas
     * transitorias alcanzables desde PURGE_ROOTS. Las tablas maestras quedan intactas.
     *
     * @return array<string, int> tabla => filas borradas
     */
    public static function purge(PDO $pdo): array
    {
        $order = self::computeDeleteOrder($pdo);

        $pdo->beginTransaction();
        try {
            $deleted = self::withPurgeUnlocked($pdo, static function () use ($pdo, $order): array {
                $deleted = [];
                foreach ($order as $table) {
                    $stmt = $pdo->exec('DELETE FROM `' . $table . '`');
                    $deleted[$table] = ($stmt === false) ? 0 : (int)$stmt;
                }

                return $deleted;
            });
            $pdo->commit();
            return $deleted;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Runs a block with the Art. III.1 purge window open.
     *
     * `refund_requests` carries a BEFORE DELETE trigger that refuses physical
     * deletion (migration 010), so the harness has to say out loud that it is
     * resetting a test database. That single session variable is the ONLY thing
     * in the repository that unlocks the table, which is what keeps the
     * constitutional rule absolute for production code: nothing under `src/`
     * sets it, so a stray DELETE there still fails loudly.
     *
     * The window is always closed again in a `finally`, so one failing purge
     * cannot leave the guard disabled for whatever runs next in this process.
     *
     * @template T
     * @param callable(): T $work
     * @return T
     */
    private static function withPurgeUnlocked(PDO $pdo, callable $work): mixed
    {
        $pdo->exec('SET @vendguard_purge = 1');

        try {
            return $work();
        } finally {
            $pdo->exec('SET @vendguard_purge = 0');
        }
    }

    /**
     * Purga dirigida de un único incidente y de todas sus tablas hijas por
     * `incident_id`. Respeta el orden hijos→padre vía el grafo de FKs.
     *
     * @return array<string, int> tabla => filas borradas
     */
    public static function purgeIncident(PDO $pdo, int $incidentId): array
    {
        $graph = self::loadFkGraph($pdo);

        // Tablas hijas que referencian `incidents` a través de la columna
        // `incident_id`. Las seis coinciden en ese nombre, así que se indexa
        // por tabla hija para no perder ninguna.
        $children = [];
        foreach ($graph['incidents']['children'] ?? [] as $childTable => $column) {
            if ($column === 'incident_id') {
                $children[] = $childTable;
            }
        }

        $order = self::topoSortChildrenFirst($pdo, array_values(array_unique($children)));

        $pdo->beginTransaction();
        try {
            $deleted = self::withPurgeUnlocked($pdo, static function () use ($pdo, $order, $incidentId): array {
                $deleted = [];
                foreach ($order as $table) {
                    $stmt = $pdo->prepare('DELETE FROM `' . $table . '` WHERE `incident_id` = :id');
                    $stmt->execute([':id' => $incidentId]);
                    $deleted[$table] = $stmt->rowCount();
                }
                $stmt = $pdo->prepare('DELETE FROM `incidents` WHERE `id` = :id');
                $stmt->execute([':id' => $incidentId]);
                $deleted['incidents'] = $stmt->rowCount();

                return $deleted;
            });
            $pdo->commit();
            return $deleted;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Purga dirigida de todos los incidentes de una máquina, con sus hijas.
     * Sustituye al clásico par:
     *   DELETE FROM incident_history WHERE incident_id IN (SELECT ... machine_id = :mid);
     *   DELETE FROM incidents WHERE machine_id = :mid;
     * que fallaba en cuanto la incidencia tenía un reintegro colgado.
     *
     * @return array<string, int> tabla => filas borradas
     */
    public static function purgeIncidentsByMachine(PDO $pdo, int $machineId): array
    {
        return self::purgeMatchingIncidents($pdo, '`machine_id` = :target', [':target' => $machineId]);
    }

    /**
     * Purga dirigida de un incidente identificado por su código de ticket.
     *
     * @return array<string, int> tabla => filas borradas
     */
    public static function purgeIncidentByTicket(PDO $pdo, string $ticketCode): array
    {
        return self::purgeMatchingIncidents($pdo, '`ticket_code` = :target', [':target' => $ticketCode]);
    }

    /**
     * Purga dirigida de los incidentes cuyo ticket casa con un patrón `LIKE`.
     *
     * @return array<string, int> tabla => filas borradas
     */
    public static function purgeIncidentsMatchingTicket(PDO $pdo, string $likePattern): array
    {
        return self::purgeMatchingIncidents($pdo, '`ticket_code` LIKE :target', [':target' => $likePattern]);
    }

    /**
     * Resuelve los ids de incidente que casan con un filtro y purga cada uno
     * con su subárbol de hijas.
     *
     * @param array<string, mixed> $params
     * @return array<string, int> tabla => filas borradas
     */
    private static function purgeMatchingIncidents(PDO $pdo, string $condition, array $params): array
    {
        $stmt = $pdo->prepare('SELECT `id` FROM `incidents` WHERE ' . $condition);
        $stmt->execute($params);
        $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

        $totals = [];
        foreach ($ids as $id) {
            foreach (self::purgeIncident($pdo, $id) as $table => $count) {
                $totals[$table] = ($totals[$table] ?? 0) + $count;
            }
        }
        return $totals;
    }

    /**
     * Orden de borrado completo derivado del grafo (expuesto para las pruebas
     * de la autoverificación). No ejecuta SQL de escritura.
     *
     * @return list<string>
     */
    public static function deleteOrder(PDO $pdo): array
    {
        return self::computeDeleteOrder($pdo);
    }

    /**
     * Prefijos reservados de las sedes del banco de pruebas (contrato §8.1).
     *
     * @return list<string>
     */
    public static function testLocationPrefixes(): array
    {
        return self::TEST_LOCATION_PREFIXES;
    }

    /**
     * Detector de residuo: cuántas sedes del banco de pruebas quedan en la base.
     *
     * Es la única lectura del criterio de pertenencia —el prefijo reservado— y
     * `purgeTestLocations()` lo usa como puerta, de modo que lo que el detector
     * ve y lo que la purga drena son exactamente el mismo conjunto. Sólo lee.
     *
     * @return array{total:int, by_prefix:array<string,int>}
     */
    public static function countTestLocations(PDO $pdo): array
    {
        $byPrefix = [];
        $total = 0;
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM `locations` WHERE `site_code` LIKE :pattern');
        foreach (self::TEST_LOCATION_PREFIXES as $prefix) {
            $stmt->execute([':pattern' => $prefix]);
            $count = (int)$stmt->fetchColumn();
            $byPrefix[$prefix] = $count;
            $total += $count;
        }

        return ['total' => $total, 'by_prefix' => $byPrefix];
    }

    /**
     * Restitución del parque: drena el subárbol completo de las sedes del banco
     * de pruebas y **nunca** toca una sede semilla `SEDE-BCN-*`.
     *
     * El blindaje de `PROTECTED_TABLES` sobre `locations` deja el otro lado
     * descubierto: las sedes que crean las suites no las retiraba nadie y el
     * banco acumulaba filas en cada corrida (hallazgo F-4 del módulo 11). Aquí el
     * borrado es **dirigido por prefijo reservado**, no un vaciado de la maestra.
     *
     * Las seis tablas que referencian `locations` y las que cuelgan de ellas lo
     * hacen con `ON DELETE RESTRICT`, así que un `DELETE FROM locations` a secas
     * fracasa en cuanto la suite creó una máquina o una avería sobre su sede. El
     * plan se deriva otra vez del grafo real y la condición de cada tabla se
     * encadena con subconsultas hacia la condición raíz, de modo que una FK nueva
     * hacia `locations` o hacia sus hijas entra sola en el plan.
     *
     * Sin sedes de prueba la operación no ejecuta una sola sentencia.
     *
     * @return array<string, int> tabla => filas borradas
     */
    public static function purgeTestLocations(PDO $pdo): array
    {
        $locationIds = self::testLocationIds($pdo);
        if ($locationIds === []) {
            return [];
        }

        $plan = self::testLocationSubtreePlan($pdo, $locationIds);
        $order = self::topoSortChildrenFirst($pdo, array_keys($plan));

        $pdo->beginTransaction();
        try {
            $deleted = self::withPurgeUnlocked($pdo, static function () use ($pdo, $order, $plan): array {
                $deleted = [];
                foreach ($order as $table) {
                    // La condición jamás puede degradarse a un vaciado: siempre
                    // nace de una lista explícita de id de sede de prueba.
                    if (!isset($plan[$table]) || $plan[$table] === '') {
                        throw new RuntimeException(
                            'El plan de drenaje de sedes de prueba no tiene condición para la tabla `'
                            . $table . '`; se aborta antes de borrar nada.'
                        );
                    }
                    $stmt = $pdo->exec('DELETE FROM `' . $table . '` WHERE ' . $plan[$table]);
                    $deleted[$table] = ($stmt === false) ? 0 : (int)$stmt;
                }

                return $deleted;
            });
            $pdo->commit();
            return $deleted;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Identificadores de las sedes del banco de pruebas, o lista vacía.
     *
     * @return list<int>
     */
    private static function testLocationIds(PDO $pdo): array
    {
        $parts = [];
        $params = [];
        foreach (self::TEST_LOCATION_PREFIXES as $index => $prefix) {
            $parts[] = '`site_code` LIKE :pattern' . $index;
            $params[':pattern' . $index] = $prefix;
        }

        $stmt = $pdo->prepare('SELECT `id` FROM `locations` WHERE ' . implode(' OR ', $parts) . ' ORDER BY `id`');
        $stmt->execute($params);

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Plan de borrado del subárbol de las sedes de prueba: tabla => condición SQL.
     *
     * La condición de cada tabla se deriva del grafo real de claves foráneas
     * encadenando subconsultas hacia la raíz (`locations` restringida a los id
     * recibidos). Las hijas profundas —las que no tienen columna propia hacia la
     * raíz— quedan cubiertas por esa cadena, que es lo que evita el error 1451.
     *
     * @param list<int> $locationIds
     * @return array<string, string>
     */
    private static function testLocationSubtreePlan(PDO $pdo, array $locationIds): array
    {
        $graph = self::loadFkGraph($pdo);
        $idList = implode(',', array_map('intval', $locationIds));

        // Recorrido descendente desde la raíz: cada tabla alcanzada recuerda por
        // qué columnas cuelga de una tabla ya alcanzada.
        $affected = ['locations' => true];
        $links = [];
        $queue = ['locations'];
        while ($queue !== []) {
            $parent = array_shift($queue);
            foreach ($graph[$parent]['children'] ?? [] as $child => $column) {
                if (in_array($child, self::PROTECTED_TABLES, true)
                    && !in_array($child, self::TEST_LOCATION_PURGEABLE_MASTERS, true)) {
                    continue;
                }
                $links[$child][$column] = $parent;
                if (!isset($affected[$child])) {
                    $affected[$child] = true;
                    $queue[] = $child;
                }
            }
        }

        $conditions = ['locations' => '`id` IN (' . $idList . ')'];
        $resolving = [];
        $resolve = function (string $table) use (&$resolve, &$conditions, &$resolving, $links): string {
            if (isset($conditions[$table])) {
                return $conditions[$table];
            }
            if (isset($resolving[$table])) {
                throw new RuntimeException(
                    'Ciclo de claves foráneas al derivar el plan de drenaje de la tabla `' . $table . '`.'
                );
            }
            $resolving[$table] = true;

            $parts = [];
            foreach ($links[$table] ?? [] as $column => $parent) {
                $parts[] = '`' . $column . '` IN (SELECT `id` FROM `' . $parent . '` WHERE ' . $resolve($parent) . ')';
            }
            unset($resolving[$table]);

            if ($parts === []) {
                throw new RuntimeException(
                    'La tabla `' . $table . '` entró en el plan de drenaje de sedes sin vía hacia la raíz.'
                );
            }

            return $conditions[$table] = '(' . implode(' OR ', $parts) . ')';
        };

        foreach (array_keys($affected) as $table) {
            $resolve($table);
        }

        return $conditions;
    }

    // ─────────────────────────────────────────────────────────────────────
    // Construcción del grafo y orden topológico
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Carga (y cachea) el grafo de FKs del esquema como:
     *   tabla => ['parents' => [columna => tablaPadre], 'children' => [tablaHija => columna]]
     *
     * Ojo: `children` se indexa por TABLA HIJA, no por columna, porque las seis
     * hijas de `incidents` comparten todas la columna `incident_id`. Indexar por
     * columna haría que cada FK machacase a la anterior y el grafo perdería cinco
     * tablas, que es exactamente el tipo de defecto silencioso que se corrige aquí.
     *
     * @return array<string, array{parents: array<string,string>, children: array<string,string>}>
     */
    private static function loadFkGraph(PDO $pdo): array
    {
        $schema = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
        $cacheKey = $schema;
        if (isset(self::$fkGraphCache[$cacheKey])) {
            return self::$fkGraphCache[$cacheKey];
        }

        $sql = 'SELECT k.TABLE_NAME AS child, k.COLUMN_NAME AS col, k.REFERENCED_TABLE_NAME AS parent
                FROM information_schema.KEY_COLUMN_USAGE k
                WHERE k.REFERENCED_TABLE_SCHEMA = :schema AND k.REFERENCED_TABLE_NAME IS NOT NULL';
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':schema' => $schema]);

        $graph = [];
        $ensure = static function (array &$graph, string $table): void {
            if (!isset($graph[$table])) {
                $graph[$table] = ['parents' => [], 'children' => []];
            }
        };

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $child = (string)$row['child'];
            $col = (string)$row['col'];
            $parent = (string)$row['parent'];
            $ensure($graph, $child);
            $ensure($graph, $parent);
            $graph[$child]['parents'][$col] = $parent;
            $graph[$parent]['children'][$child] = $col;
        }

        self::$fkGraphCache[$cacheKey] = $graph;
        return $graph;
    }

    /**
     * Descubre transitivamente el conjunto de tablas a purgar desde PURGE_ROOTS
     * (raíces + todas sus descendientes), excluyendo PROTECTED_TABLES.
     *
     * @return list<string>
     */
    private static function discoverPurgeTables(PDO $pdo): array
    {
        $graph = self::loadFkGraph($pdo);

        $selected = [];
        $queue = self::PURGE_ROOTS;
        while ($queue !== []) {
            $table = array_shift($queue);
            if (isset($selected[$table]) || in_array($table, self::PROTECTED_TABLES, true)) {
                continue;
            }
            $selected[$table] = true;
            foreach (array_keys($graph[$table]['children'] ?? []) as $childTable) {
                if (!in_array($childTable, self::PROTECTED_TABLES, true)) {
                    $queue[] = $childTable;
                }
            }
        }

        $tables = array_keys($selected);
        sort($tables, SORT_STRING);
        return $tables;
    }

    /**
     * Ordena un conjunto de tablas para que cada hija se borre antes que su
     * padre (algoritmo de Kahn sobre el grafo inverso). Las tablas seleccionadas
     * sin relación entre sí se ordenan alfabéticamente para que el
     * resultado sea determinista.
     *
     * @param list<string> $tables
     * @return list<string>
     */
    private static function topoSortChildrenFirst(PDO $pdo, array $tables): array
    {
        $graph = self::loadFkGraph($pdo);
        $inSet = array_flip($tables);

        // childCount[t] = nº de hijas (dentro del conjunto) que deben borrarse antes que t.
        $childCount = [];
        $parents = [];
        foreach ($tables as $t) {
            $childCount[$t] = 0;
            $parents[$t] = [];
        }
        foreach ($tables as $t) {
            foreach ($graph[$t]['parents'] ?? [] as $parent) {
                if (isset($inSet[$parent]) && !in_array($parent, $parents[$t], true)) {
                    // t es hija de parent: parent necesita que t se borre antes.
                    $childCount[$parent]++;
                    $parents[$t][] = $parent;
                }
            }
        }

        $ready = [];
        foreach ($childCount as $t => $count) {
            if ($count === 0) {
                $ready[] = $t;
            }
        }
        sort($ready, SORT_STRING);

        $order = [];
        while ($ready !== []) {
            $t = array_shift($ready);
            $order[] = $t;
            foreach ($parents[$t] as $parent) {
                $childCount[$parent]--;
                if ($childCount[$parent] === 0) {
                    $ready[] = $parent;
                    sort($ready, SORT_STRING);
                }
            }
        }

        // Detección de ciclos: si no se pudo colocar todo el conjunto, hay un
        // ciclo de FKs que haría imposible un borrado secuencial seguro.
        if (count($order) !== count($tables)) {
            throw new RuntimeException(
                'Ciclo de claves foráneas detectado entre las tablas operacionales; '
                . 'el orden de borrado no puede calcularse de forma segura.'
            );
        }

        return $order;
    }

    /**
     * Orden de borrado completo: descubre el conjunto y lo topologiza.
     *
     * @return list<string>
     */
    private static function computeDeleteOrder(PDO $pdo): array
    {
        $tables = self::discoverPurgeTables($pdo);
        $order = self::topoSortChildrenFirst($pdo, $tables);
        return $order;
    }

    /**
     * @return list<string> constantes declaradas como operacionales conocidas
     */
    public static function knownOperationalTables(): array
    {
        return self::KNOWN_OPERATIONAL;
    }
}