<?php

declare(strict_types=1);

/**
 * VendGuard - Limpiador de datos de pruebas compartido (dogma Vanilla)
 *
 * Proporciona un reinicio operacional seguro de la base de datos para las
 * suites de integración. Resuelve la fragilidad histórica en la que cada suite
 * repetía a mano un bloque `DELETE FROM incidents` que, en cuanto una tabla
 * hija con FK ON DELETE RESTRICT quedaba con filas (por un fatal previo que
 * saltó el `finally` de otra suite), reventaba con el error de clave foránea del
 * motor (23503).
 *
 * **Motor único (módulo 13):** este limpiador escribe el dialecto del motor que sirve
 * producción —identificadores desnudos, grafo de claves foráneas leído de `pg_constraint`—,
 * porque el arnés resuelve su persistencia con la misma ruta que la aplicación. No hay
 * segunda sintaxis que traducir ni segunda verdad que certificar.
 *
 * Estrategia (causa raíz, no parche):
 *   - El orden de borrado NO está hardcodeado: se deriva del grafo real de
     *     claves foráneas leído del catálogo del motor, y las tablas se topologizan
     *     para borrar siempre hijas antes que padres.
 *   - El conjunto de tablas a purgar se descubre transitivamente: se parte de
 *     unas raíces y se sigue el grafo de FKs hacia las hijas. Cualquier FK RESTRICT
 *     futura hacia una raíz (p. ej. `refund_requests` → `incidents`) queda
 *     cubierta automáticamente sin tocar este archivo.
 *   - Todo se ejecuta dentro de una transacción con rollback en `finally`, de
 *     modo que un fallo a media limpieza no deja la base a medias y no envenena
 *     las siguientes suites.
 *
 * Palabras prohibidas: no se desactivan los disparadores ni las restricciones del motor
 * (`session_replication_role`, `ALTER TABLE … DISABLE TRIGGER`), que dejarían filas
 * huérfanas silenciosas y saltarían el blindaje del Art. III.1, ni se **vacían** las
 * maestras (users, machines, locations, settings, audit_log): la restitución del parque
 * sólo drena sus filas fuera del catálogo canónico del sembrador (§8.5).
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
        // Catálogo territorial (Módulo 17, hallazgo H-2): `operational_zones` es
        // **padre** de `locations`, no hija de `incidents`, así que el grafo de la
        // purga no lo alcanzaba y cada corrida dejaba las zonas y los CP de una
        // suite en la base. Entra como raíz propia: sus hijas —códigos postales y
        // cuadrante— caen por el grafo y la sede queda en «Sin Zona Asignada» por
        // el `ON DELETE SET NULL` de la clave foránea. El sembrador restituye
        // después el catálogo canónico.
        'operational_zones',
        // Ausencias y turnos de técnicos (Módulo 21): cuelgan de `users`, que es
        // una tabla protegida, por lo que el grafo desde incidents no los alcanza.
        'technician_absences',
        'technician_shifts',
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
        'notification_outbox',
        'operational_zone_postal_codes',
        'technician_zone_assignments',
        'technician_van_stocks',
        'incident_service_sheets',
        'incident_satisfaction_surveys',
        'technician_absences',
        'technician_shifts',
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
            $deleted = self::withPurgeWindow($pdo, static function () use ($pdo, $order): array {
                $deleted = [];
                foreach ($order as $table) {
                    $stmt = $pdo->exec('DELETE FROM ' . $table);
                    $deleted[$table] = ($stmt === false) ? 0 : (int)$stmt;
                }

                // La guardia pasiva es estado mutable del catálogo: una suite que
                // falle a mitad puede dejar `is_on_call = TRUE` y la siguiente
                // elegiría como guardia al técnico equivocado (la lista se ordena
                // por nombre, no por id). `purge()` restituye el catálogo, así que
                // también apaga la marca antes de devolver el banco a su estado base.
                $pdo->exec('UPDATE users SET is_on_call = FALSE WHERE is_on_call = TRUE');

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
     * Abre o cierra la ventana de purga del arnés (Art. III.1) en su **única** forma: el
     * parámetro de sesión `vendguard.purge` del motor único.
     *
     * La forma es una sola —el motor también— porque una regla con dos sintaxis es una regla
     * con dos verdades: la que se certifica no es la que sirve producción. Nada bajo `src/`
     * declara este parámetro, de modo que un `DELETE` de la aplicación sigue fallando en voz
     * alta contra el disparador.
     */
    public static function setSessionPurgeSignal(PDO $pdo, bool $enabled): void
    {
        $stmt = $pdo->prepare("SELECT set_config('vendguard.purge', :val, false)");
        $stmt->execute([':val' => $enabled ? 'on' : 'off']);
        if ($stmt instanceof PDOStatement) {
            $stmt->closeCursor();
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
     * Punto de entrada PÚBLICO y único de la ventana (T-HYBRID-26): lo usan tanto
     * las purgas de este limpiador como las suites que emiten sus propios borrados
     * de filas maestras sin transacción explícita, de modo que la señal se declara
     * en un solo sitio y en la forma del motor activo siempre.
     *
     * @template T
     * @param callable(): T $work
     * @return T
     */
    public static function withPurgeWindow(PDO $pdo, callable $work): mixed
    {
        self::setSessionPurgeSignal($pdo, true);

        try {
            return $work();
        } finally {
            self::setSessionPurgeSignal($pdo, false);
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
        // La cadena de averías crónicas es una referencia AUTORREFERENTE de
        // `incidents`: el sucesor apunta a su predecesor mediante
        // `chronic_predecessor_incident_id` (Módulo 16). Borrar primero el
        // predecesor viola `fk_incidents_chronic_predecessor`, así que cada
        // sucesor se purga antes, recursivamente, y la cadena entera desaparece
        // en el orden que impone la clave. La guarda `id <> :id` corta una
        // autorreferencia accidental sin entrar en recursión infinita.
        $successorsStmt = $pdo->prepare(
            'SELECT id FROM incidents WHERE chronic_predecessor_incident_id = :id AND id <> :id'
        );
        $successorsStmt->execute([':id' => $incidentId]);
        foreach ($successorsStmt->fetchAll(PDO::FETCH_COLUMN) as $successorId) {
            self::purgeIncident($pdo, (int)$successorId);
        }

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
            $deleted = self::withPurgeWindow($pdo, static function () use ($pdo, $order, $incidentId): array {
                $deleted = [];
                foreach ($order as $table) {
                    $stmt = $pdo->prepare('DELETE FROM ' . $table . ' WHERE incident_id = :id');
                    $stmt->execute([':id' => $incidentId]);
                    $deleted[$table] = $stmt->rowCount();
                }
                $stmt = $pdo->prepare('DELETE FROM incidents WHERE id = :id');
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
        return self::purgeMatchingIncidents($pdo, 'machine_id = :target', [':target' => $machineId]);
    }

    /**
     * Purga dirigida de un incidente identificado por su código de ticket.
     *
     * @return array<string, int> tabla => filas borradas
     */
    public static function purgeIncidentByTicket(PDO $pdo, string $ticketCode): array
    {
        return self::purgeMatchingIncidents($pdo, 'ticket_code = :target', [':target' => $ticketCode]);
    }

    /**
     * Purga dirigida de los incidentes cuyo ticket casa con un patrón `LIKE`.
     *
     * @return array<string, int> tabla => filas borradas
     */
    public static function purgeIncidentsMatchingTicket(PDO $pdo, string $likePattern): array
    {
        return self::purgeMatchingIncidents($pdo, 'ticket_code LIKE :target', [':target' => $likePattern]);
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
        $stmt = $pdo->prepare('SELECT id FROM incidents WHERE ' . $condition);
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
     * Maestras del parque que el criterio de residuo vigila y la restitución drena.
     *
     * Se deriva del catálogo canónico —la tabla que el sembrador posee es, por
     * definición, la que el arnés puede restituir—, así que no hay lista paralela
     * que pueda desviarse.
     *
     * @return list<string>
     */
    private static function parkMasters(): array
    {
        return array_keys(self::catalogIdentities());
    }

    /**
     * Identidad canónica del parque, declarada por el propio sembrador.
     *
     * @return array<string, array<string, list<string>>>
     */
    private static function catalogIdentities(): array
    {
        return \VendGuard\Infrastructure\Database\SeedRunner::canonicalIdentities();
    }

    /**
     * Detector de residuo del parque (contrato §8.5): filas de las maestras del
     * catálogo que NO pertenecen al catálogo canónico del sembrador.
     *
     * Es el único lugar donde se lee el criterio de pertenencia, y `restorePark()` lo
     * usa como puerta, de modo que lo que el detector ve y lo que la restitución drena
     * son exactamente el mismo conjunto. Sólo lee.
     *
     * @return array{total:int, by_table:array<string,int>, samples:array<string,list<string>>}
     */
    public static function countParkResidue(PDO $pdo): array
    {
        $byTable = [];
        $samples = [];
        $total = 0;

        foreach (self::parkResidueSpecs($pdo) as $table => $spec) {
            $count = count($spec['residue_ids']);
            $byTable[$table] = $count;
            $total += $count;

            if ($count > 0) {
                $values = $spec['residue_values'];
                sort($values, SORT_STRING);
                $samples[$table] = array_slice($values, 0, 5);
            }
        }

        return ['total' => $total, 'by_table' => $byTable, 'samples' => $samples];
    }

    /**
     * Restitución del catálogo: devuelve a su sede canónica las máquinas del parque que una
     * suite haya reubicado y que, de otro modo, entrarían en el plan de drenaje como hijas
     * de una sede del arnés.
     *
     * La sede de destino **no se adivina**: la declara el mismo catálogo que siembra el
     * parque (`SeedRunner::canonicalMachineSites()`), de modo que la restitución devuelve la
     * fila al estado que el sembrador escribe. El síndrome que esto cierra no es teórico:
     * `AdminMachinesEndpointTest` traslada `VEND-0101` a su sede de pruebas y sólo lo devuelve
     * al final de su camino feliz, así que una muerte a mitad dejaba una máquina de negocio
     * apuntando a una sede del arnés —y el sembrador no repara ese vínculo, porque su upsert
     * no reescribe `location_id`—.
     *
     * Si la sede canónica no existiera, la fila no se toca —devolverla pondría `location_id`
     * en nulo, que es peor que el desvío— y el drenaje lo denuncia en su comprobación previa.
     * Con el parque en su sitio no ejecuta ninguna sentencia.
     *
     * @return array<string, string> código de máquina => sede canónica a la que se devolvió
     */
    public static function restituteCatalogMachines(PDO $pdo): array
    {
        $sites = \VendGuard\Infrastructure\Database\SeedRunner::canonicalMachineSites();
        if ($sites === []) {
            return [];
        }

        $update = $pdo->prepare(
            'UPDATE machines SET location_id = (SELECT id FROM locations WHERE site_code = :site)'
            . ' WHERE code = :code'
            . ' AND (SELECT id FROM locations WHERE site_code = :site) IS NOT NULL'
            . ' AND location_id IS DISTINCT FROM (SELECT id FROM locations WHERE site_code = :site)'
        );

        $moved = [];
        foreach ($sites as $code => $site) {
            $update->execute([':site' => $site, ':code' => $code]);
            if ($update->rowCount() > 0) {
                $moved[$code] = $site;
            }
        }

        return $moved;
    }

    /**
     * Restitución de las cuentas del catálogo: el freno de intentos fallidos y el bloqueo
     * temporal de las cuentas internas vuelven a cero.
     *
     * El sembrador limpia ese freno en las **sedes** —lo dice su propio docblock, para que la
     * batería parta siempre del mismo estado— pero no en las cuentas internas, así que una
     * suite que prueba el bloqueo por intentos fallidos y muere antes de reiniciarlo deja una
     * cuenta semilla cerrada para la corrida siguiente: la suite que se autentica con ella
     * falla con `ACCOUNT_LOCKED` (423) y el defecto parece suyo. Es el mismo síndrome que F-4
     * —estado del catálogo que nadie restituye—, medido en vivo: `UserLoginLockoutTest`
     * bloquea `jordi.ruta@vendguard.internal` y muere en su aserción 103, y `AuthControllerTest`
     * —que sólo inicia sesión— fallaba en la corrida siguiente con «cuenta temporalmente
     * bloqueada».
     *
     * Sólo escribe si hay algo que reiniciar.
     *
     * @return list<string> cuentas del catálogo cuyo freno se reinició
     */
    public static function restituteCatalogUsers(PDO $pdo): array
    {
        $catalog = self::catalogIdentities();
        $emails = array_map(
            static fn($email): string => (string)$email,
            $catalog['users']['email'] ?? []
        );
        if ($emails === []) {
            return [];
        }

        $placeholders = [];
        $params = [];
        foreach ($emails as $index => $email) {
            $key = ':catalogEmail' . $index;
            $placeholders[] = $key;
            $params[$key] = $email;
        }
        $inCatalog = 'email IN (' . implode(', ', $placeholders) . ')';

        $stmt = $pdo->prepare(
            'SELECT email FROM users WHERE ' . $inCatalog
            . ' AND (login_attempts <> 0 OR login_locked_until IS NOT NULL)'
        );
        $stmt->execute($params);
        $drifted = array_map(static fn($email): string => (string)$email, $stmt->fetchAll(PDO::FETCH_COLUMN));

        if ($drifted === []) {
            return [];
        }

        $pdo->prepare(
            'UPDATE users SET login_attempts = 0, login_locked_until = NULL WHERE ' . $inCatalog
        )->execute($params);

        return $drifted;
    }

    /**
     * Restitución del parque: drena el subárbol completo de todo lo que NO pertenece
     * al catálogo canónico, y **nunca** toca una fila del catálogo.
     *
     * El blindaje de `PROTECTED_TABLES` sobre las maestras dejaba el otro lado
     * descubierto: lo que crean las suites no lo retiraba nadie y el banco acumulaba
     * filas en cada corrida (hallazgo F-4 del módulo 11). El criterio del prefijo
     * reservado cerró parte de ese agujero y dejó el resto: una suite que nombrara su
     * sede de otra forma quedaba fuera, y nada veía lo que otra colgase de una **sede
     * semilla** (una máquina nueva en una sede del catálogo) ni una cuenta creada por
     * una suite. El criterio es ahora la identidad contra el catálogo del sembrador
     * —una sola verdad, la que el sembrador escribe— y por eso alcanza las tres
     * maestras del parque: sedes, máquinas y cuentas.
     *
     * La condición de borrado de cada tabla se encadena por el grafo real de claves
     * foráneas hacia las identidades fuera de catálogo, así que **una FK nueva hacia
     * cualquiera de las maestras o hacia sus hijas entra sola en el plan**.
     *
     * Antes de borrar, el catálogo se restituye y se comprueba. **Restituir:** una máquina del
     * catálogo que una suite reubicó en una sede del arnés vuelve a la sede que el catálogo
     * declara (`restituteCatalogMachines()`), porque el drenaje la arrastraría como hija y
     * destruiría parque de negocio en silencio. **Comprobar (§8.5):** si después de la
     * restitución alguna identidad canónica siguiese dentro del plan —sólo posible si el
     * catálogo no puede situarla—, se aborta nombrando al culpable antes de borrar nada.
     *
     * Sin residuo la operación no ejecuta una sola sentencia.
     *
     * @return array<string, int> tabla => filas borradas
     */
    public static function restorePark(PDO $pdo): array
    {
        $specs = self::parkResidueSpecs($pdo);

        $roots = [];
        foreach ($specs as $table => $spec) {
            if ($spec['residue_ids'] !== []) {
                $roots[$table] = 'id IN (' . implode(', ', array_map('intval', $spec['residue_ids'])) . ')';
            }
        }

        if ($roots === []) {
            return [];
        }

        // Antes de planificar el drenaje, el parque se restituye: una máquina del catálogo que
        // una suite reubicó en una sede del arnés volvería al plan como hija, y el drenaje la
        // arrastraría. Se la devuelve a la sede que declara el catálogo y sólo después se
        // planifica el borrado; el catálogo nunca se drena, se restituye.
        self::restituteCatalogMachines($pdo);

        $plan = self::parkSubtreePlan($pdo, $roots, self::parkMasters());
        self::assertCatalogOutOfPlan($pdo, $plan, $specs, $roots);

        $order = self::topoSortChildrenFirst($pdo, array_keys($plan));

        $pdo->beginTransaction();
        try {
            $deleted = self::withPurgeWindow($pdo, static function () use ($pdo, $order, $plan): array {
                $deleted = [];
                foreach ($order as $table) {
                    // La condición jamás puede degradarse a un vaciado: siempre nace de
                    // las identidades fuera de catálogo, encadenadas por el grafo.
                    if (!isset($plan[$table]) || $plan[$table] === '') {
                        throw new RuntimeException(
                            'El plan de restitución del parque no tiene condición para la tabla `'
                            . $table . '`; se aborta antes de borrar nada.'
                        );
                    }
                    $stmt = $pdo->exec('DELETE FROM ' . $table . ' WHERE ' . $plan[$table]);
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
     * Registra la restitución del parque al apagarse el proceso (contrato §8.3).
     *
     * Es el gancho que toda suite creadora necesita y el único sitio donde se declara:
     * corre **también tras un error fatal** —el camino por el que un `finally` no
     * llega y por el que el residuo se acumulaba (F-4)— y cubre por igual la ejecución
     * suelta de la suite (`php tests/integration/X.php`). Restituye las dos mitades del
     * parque: el estado de las cuentas del catálogo y el residuo fuera de catálogo.
     *
     * Un fallo del drenaje en el apagado no se silencia: se deja subir, de modo que la
     * suite termina con error visible en lugar de declararse en verde sobre un banco
     * que acaba de quedar sucio. La red de cierre de `run_all.php` vuelve a medirlo
     * pase lo que pase.
     */
    public static function registerShutdownRestitution(): void
    {
        static $registered = false;
        if ($registered) {
            return;
        }
        $registered = true;

        register_shutdown_function(static function (): void {
            $pdo = \VendGuard\Infrastructure\Database\ConnectionFactory::getConnection();

            // El freno de intentos de las cuentas del catálogo es estado, no residuo: una suite
            // que prueba el bloqueo y muere a mitad no deja ninguna fila fuera de catálogo, pero
            // sí una cuenta semilla cerrada para la corrida siguiente. Se restituye siempre.
            self::restituteCatalogUsers($pdo);
            self::restorePark($pdo);
        });
    }

    /**
     * Especificación del residuo por maestra: columna de identidad, valores canónicos
     * y las filas que quedan fuera del catálogo (con su `id`, que es la raíz del
     * drenaje).
     *
     * @return array<string, array{column:string, canonical:list<string>, residue_ids:list<int>, residue_values:list<string>, condition:string, params:array<string,string>}>
     */
    private static function parkResidueSpecs(PDO $pdo): array
    {
        $specs = [];

        foreach (self::catalogIdentities() as $table => $identity) {
            foreach ($identity as $column => $canonical) {
                $values = array_map(static fn($value): string => (string)$value, array_values($canonical));

                $placeholders = [];
                $params = [];
                foreach ($values as $index => $value) {
                    $key = ':catalog' . $index;
                    $placeholders[] = $key;
                    $params[$key] = $value;
                }

                // `IS NULL` viaja con el `NOT IN`: una fila sin identidad tampoco está
                // en el catálogo, y `NOT IN` sola no la vería (semántica de NULL).
                $condition = '(' . $column . ' NOT IN (' . implode(', ', $placeholders) . ') OR ' . $column . ' IS NULL)';

                $stmt = $pdo->prepare('SELECT id, ' . $column . ' FROM ' . $table . ' WHERE ' . $condition);
                $stmt->execute($params);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

                $specs[$table] = [
                    'column' => $column,
                    'canonical' => $values,
                    'residue_ids' => array_map(static fn(array $row): int => (int)$row['id'], $rows),
                    'residue_values' => array_map(static fn(array $row): string => (string)$row[$column], $rows),
                    'condition' => $condition,
                    'params' => $params,
                ];
            }
        }

        return $specs;
    }

    /**
     * Plan de borrado del subárbol del residuo: tabla => condición SQL.
     *
     * La condición de cada tabla se deriva del grafo real de claves foráneas
     * encadenando subconsultas hacia las condiciones raíz —las identidades fuera de
     * catálogo de cada maestra—. Las hijas profundas —las que no tienen columna propia
     * hacia una raíz— quedan cubiertas por esa cadena, que es lo que evita el error de
     * clave foránea del motor al retirar una sede con parque.
     *
     * @param array<string, string> $roots tabla raíz => condición
     * @param list<string> $purgeableMasters maestras que sí se pueden drenar
     * @return array<string, string>
     */
    private static function parkSubtreePlan(PDO $pdo, array $roots, array $purgeableMasters): array
    {
        $graph = self::loadFkGraph($pdo);

        // Recorrido descendente desde cada raíz: cada tabla alcanzada recuerda por qué
        // columnas cuelga de una tabla ya alcanzada.
        $affected = array_fill_keys(array_keys($roots), true);
        $links = [];
        $queue = array_keys($roots);
        while ($queue !== []) {
            $parent = array_shift($queue);
            foreach ($graph[$parent]['children'] ?? [] as $child => $column) {
                // La autorreferencia del Módulo 16 (`incidents.chronic_predecessor_incident_id`)
                // no es una vía hacia otra tabla: resolverla como enlace haría que el plan pidiera
                // el subárbol de la propia tabla que está resolviendo. La raíz ya la acota.
                if ($child === $parent) {
                    continue;
                }
                if (in_array($child, self::PROTECTED_TABLES, true)
                    && !in_array($child, $purgeableMasters, true)) {
                    continue;
                }
                $links[$child][$column] = $parent;
                if (!isset($affected[$child])) {
                    $affected[$child] = true;
                    $queue[] = $child;
                }
            }
        }

        $conditions = $roots;
        $resolving = [];
        $resolve = function (string $table) use (&$resolve, &$conditions, &$resolving, $links): string {
            if (isset($conditions[$table])) {
                return $conditions[$table];
            }
            if (isset($resolving[$table])) {
                throw new RuntimeException(
                    'Ciclo de claves foráneas al derivar el plan de restitución de la tabla `' . $table . '`.'
                );
            }
            $resolving[$table] = true;

            $parts = [];
            foreach ($links[$table] ?? [] as $column => $parent) {
                $parts[] = $column . ' IN (SELECT id FROM ' . $parent . ' WHERE ' . $resolve($parent) . ')';
            }
            unset($resolving[$table]);

            if ($parts === []) {
                throw new RuntimeException(
                    'La tabla `' . $table . '` entró en el plan de restitución sin vía hacia una raíz.'
                );
            }

            return $conditions[$table] = '(' . implode(' OR ', $parts) . ')';
        };

        foreach (array_keys($affected) as $table) {
            $resolve($table);
        }

        return $conditions;
    }

    /**
     * Red del catálogo (contrato §8.5), previa a cualquier borrado: después de restituir el
     * vínculo declarado, ninguna fila de identidad canónica puede quedar dentro del plan de
     * drenaje. Lo que aquí salta es lo que el catálogo **no** puede situar (una sede canónica
     * ausente en el banco), y por eso se denuncia en vez de resolverse en silencio.
     *
     * @param array<string, string> $plan
     * @param array<string, array{column:string, canonical:list<string>, residue_ids:list<int>, residue_values:list<string>, condition:string, params:array<string,string>}> $specs
     * @param array<string, string> $roots
     */
    private static function assertCatalogOutOfPlan(PDO $pdo, array $plan, array $specs, array $roots): void
    {
        foreach ($specs as $table => $spec) {
            if (isset($roots[$table]) || !isset($plan[$table])) {
                continue;
            }

            $placeholders = [];
            $params = [];
            foreach ($spec['canonical'] as $index => $value) {
                $key = ':canonical' . $index;
                $placeholders[] = $key;
                $params[$key] = $value;
            }

            $stmt = $pdo->prepare(
                'SELECT ' . $spec['column'] . ' FROM ' . $table
                . ' WHERE (' . $plan[$table] . ') AND ' . $spec['column'] . ' IN (' . implode(', ', $placeholders) . ')'
            );
            $stmt->execute($params);
            $trapped = array_map(static fn($value): string => (string)$value, $stmt->fetchAll(PDO::FETCH_COLUMN));

            if ($trapped !== []) {
                throw new RuntimeException(sprintf(
                    'Restitución del parque abortada: %d fila(s) del catálogo canónico de `%s` (%s) sigue(n) dentro del plan de drenaje y el borrado las arrastraría. '
                    . 'El catálogo no puede situarlas (¿falta su sede canónica en el banco?): repón el parque sembrado o retíralas del alcance de la suite que las movió (contrato de limpieza §8.5).',
                    count($trapped),
                    $table,
                    implode(', ', array_slice($trapped, 0, 5))
                ));
            }
        }
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
        $schema = (string)$pdo->query('SELECT current_schema()')->fetchColumn();
        $cacheKey = $schema;
        if (isset(self::$fkGraphCache[$cacheKey])) {
            return self::$fkGraphCache[$cacheKey];
        }

        // El grafo se lee del catálogo real de PostgreSQL (`pg_constraint`), no de
        // `information_schema.key_column_usage`: esa vista es de MySQL y en el motor único
        // no declara a qué tabla apunta cada clave foránea.
        $sql = 'SELECT rel.relname AS child, att.attname AS col, pref.relname AS parent
                FROM pg_constraint con
                JOIN pg_class rel ON rel.oid = con.conrelid
                JOIN pg_class pref ON pref.oid = con.confrelid
                JOIN pg_namespace ns ON ns.oid = con.connamespace
                JOIN LATERAL unnest(con.conkey) AS k(attnum) ON TRUE
                JOIN pg_attribute att ON att.attrelid = con.conrelid AND att.attnum = k.attnum
                WHERE con.contype = \'f\' AND ns.nspname = :schema';
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
                // Una clave foránea autorreferente (`incidents.chronic_predecessor_incident_id`,
                // Módulo 16) no impone un orden entre tablas: las dos filas viven en la misma
                // tabla y su `DELETE` conjunto resuelve la referencia. Contarla como arista
                // convierte el grafo en un ciclo imposible y bloquea toda purga del arnés.
                if ($parent === $t) {
                    continue;
                }

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