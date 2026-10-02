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
        'spare_part_compatibilities',
    ];

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
            $deleted = [];
            foreach ($order as $table) {
                $stmt = $pdo->exec('DELETE FROM `' . $table . '`');
                $deleted[$table] = ($stmt === false) ? 0 : (int)$stmt;
            }
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
            $deleted = [];
            foreach ($order as $table) {
                $stmt = $pdo->prepare('DELETE FROM `' . $table . '` WHERE `incident_id` = :id');
                $stmt->execute([':id' => $incidentId]);
                $deleted[$table] = $stmt->rowCount();
            }
            $stmt = $pdo->prepare('DELETE FROM `incidents` WHERE `id` = :id');
            $stmt->execute([':id' => $incidentId]);
            $deleted['incidents'] = $stmt->rowCount();
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