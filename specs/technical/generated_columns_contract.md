# Contrato técnico · Columnas generadas y el motor desplegado (TiDB)

**Ámbito:** las dos únicas columnas generadas del modelo (`incidents.is_active_ticket` e `incident_replaced_parts.total_cost_snapshot`), las operaciones de migración que las afectan y las reglas del motor cloud —TiDB— que el arranque desplegado no comparte con MariaDB.
**Origen:** el arranque de `pending-info` en Render se detuvo el 2026-10-11 en la migración 016 con el error 8200 (`oldCol is a dependent column 'status' for generated column`).

---

## 1. El defecto que dio origen a este contrato

La migración [`016_pending_info_sla_pause.sql`](../../database/migrations/016_pending_info_sla_pause.sql) amplía el enum `incidents.status` para darle cabida a `PENDING_INFO`. En MariaDB y MySQL eso es legal incluso con la guardia puesta; en TiDB, no:

```text
SQLSTATE[HY000]: General error: 8200 Unsupported modify column:
oldCol is a dependent column 'status' for generated column
```

`is_active_ticket` —la columna virtual que materializa la regla de un solo ticket activo por máquina (Art. V.2)— se calcula desde `status`, y TiDB prohíbe modificar cualquier columna de la que dependa una columna generada.

La batería local (239 suites) no podía verlo por construcción: **todas sus corridas usan MariaDB**, que acepta la operación que TiDB rechaza.

**Reparación aplicada:** [`database/cloud_init.sql`](../../database/cloud_init.sql) —el DDL que reconstruye las tablas núcleo en cada arranque desplegado— declara `PENDING_INFO` en su propio enum; la migración 016 conserva la ampliación como vía de convergencia para bases MySQL/MariaDB migradas en caliente, y su check de cierre acepta cualquiera de los dos indicadores de columna generada (`EXTRA` o `GENERATION_EXPRESSION`). La guardia de regresión vive en `CloudDeploySchemaParityTest` (**Caso 4**): el DDL base debe declarar **todos** los valores de `IncidentStatus`.

---

## 2. Inventario: las dos columnas generadas del modelo

| Columna | Tabla | Tipo | Expresión | Depende de | Se declara en | Quién la crea |
| :--- | :--- | :--- | :--- | :--- | :--- | :--- |
| `is_active_ticket` | `incidents` | VIRTUAL | `IF(status IN ('CLOSED', 'CANCELLED'), NULL, 1)` | `status` | `database/schema.sql` y `database/cloud_init.sql` | Solo el DDL base; **ninguna migración la añade ni la retoca** |
| `total_cost_snapshot` | `incident_replaced_parts` | STORED | `quantity * unit_cost_snapshot` | `quantity`, `unit_cost_snapshot` | `database/cloud_init.sql` y migración `006_spare_parts_catalog_and_traceability.sql` | El DDL base o la migración 006; siempre por `CREATE TABLE` |

Índice asociado que conviene tener presente:

- `uq_machine_active_ticket (machine_id, is_active_ticket)` — índice único condicional de `incidents` y guardia de duplicados. Es además **el índice que sostiene la clave foránea `fk_incidents_machine`** (su columna izquierda es `machine_id`).

---

## 3. Auditoría de las migraciones existentes

Barrido sobre `database/migrations/*.sql`, `database/schema.sql` y `database/cloud_init.sql`:

| Patrón | Resultado |
| :--- | :--- |
| `GENERATED` | 2 columnas (inventario §2); ninguna añadida por `ALTER TABLE` |
| `MODIFY COLUMN` / `CHANGE COLUMN` | 9 sentencias (004, 005, 009, 011 ×4, 016, 017); **solo la 016 apunta a una dependencia de columna generada** (`status`), y está reparada |
| `DROP COLUMN` / `DROP INDEX` | 0 ocurrencias |
| `ADD COLUMN ... GENERATED ...` | 0 ocurrencias |
| `PARTITION` | 0 tablas particionadas |

Las columnas que el resto de migraciones modifican (`audit_log.entity_type`, los importes de reintegros, `refund_requests.incident_id`) **no son dependencias de ninguna columna generada**, así que su tránsito por TiDB no tropieza con la regla 8200. Y `quantity` y `unit_cost_snapshot` —dependencias de `total_cost_snapshot`— no se tocan en ninguna migración.

**Conclusión de la auditoría: no queda ningún otro choque vivo.** El único conflicto habido (016 / `status`) está reparado, y el resto del historial no vuelve a acercarse a la regla.

---

## 4. Reglas del motor desplegado, con la evidencia de cada una

| # | Regla | Evidencia |
| :--- | :--- | :--- |
| **R1** | No se puede `MODIFY`/`CHANGE` una columna de la que dependa una generada (error 8200). | Producción 2026-10-11 (migración 016). Documentación oficial de TiDB (`ALTER TABLE ... MODIFY COLUMN`). |
| **R2** | No se puede `MODIFY` la propia columna generada (tipo o expresión): error 8200 «column is generated». | Documentación oficial de TiDB. |
| **R3** | `ADD COLUMN ... GENERATED ... VIRTUAL` por `ALTER TABLE` sí está soportado; `STORED`, no: una columna generada almacenada solo puede nacer en un `CREATE TABLE`. | Documentación oficial de TiDB (`ADD COLUMN`, columnas generadas). Nuestro `total_cost_snapshot` nace siempre por `CREATE TABLE`. |
| **R4** | Eliminar una columna cubierta por un índice compuesto exige eliminar antes el índice; y si ese índice sostiene una clave foránea, el motor puede impedir el `DROP INDEX`. | Comprobado en local al intentar liberar `uq_machine_active_ticket`: MariaDB responde `1553 Cannot drop index ... needed in a foreign key constraint` porque `fk_incidents_machine` lo necesita. Por eso el camino de «liberar la guardia y reconstruirla» **no es viable** para `is_active_ticket`, y el DDL base es la única ruta (R1). |
| **R5** | Las conversiones entre familias de tipos (desde ENUM, SET, JSON, BIT o algunos TIME hacia otros tipos) no están soportadas; **ENUM→ENUM sí**. | La migración 004 reescribe el enum de `audit_log` en cada arranque desplegado y las 005/009 lo vuelven a ampliar: ENUM→ENUM, en ambos sentidos, se aplica en TiDB con normalidad. Documentación oficial de TiDB. |
| **R6** | TiDB no implementa triggers; el arranque los ignora con aviso. | `bin/init_cloud_db.php` (migración 010) y el log del despliegue. |

---

## 5. Regla operativa para migraciones futuras

1. **El DDL base es la única ruta para cambiar el estado que ve TiDB.** `database/cloud_init.sql` reconstruye las tablas núcleo en cada arranque desplegado; si una migración necesita modificar una dependencia de una columna generada (R1), el estado final se declara en `cloud_init.sql` y la migración queda como convergencia para MySQL/MariaDB.
2. **Nunca un `ALTER` sobre una columna generada** (R2): cambiar su expresión es un cambio de DDL base.
3. **Columnas generadas nuevas:** nacen en el DDL base; por `ALTER`, solo `VIRTUAL` (R3). Si se indexan, revisar R4 antes de plantear cualquier borrado.
4. **Borrados:** ninguna migración debería necesitar `DROP COLUMN`/`DROP INDEX`; si hiciera falta, plan explícito de índice y foránea (R4) y validación contra el motor cloud.
5. **Conversiones de tipo:** ENUM→ENUM adelante; el resto de familias, a la lista de «no» (R5).

### 5.1. Corolario: las tablas que no se reconstruyen

`cloud_init.sql` solo reconstruye las once tablas de su lista `DROP TABLE` (`locations`, `machines`, `users`, `incidents`, `incident_history`, `incident_comments`, `audit_log`, `preventive_settings`, `preventive_orders`, `preventive_order_items`, `sanitary_certificates`). El resto —`incident_replaced_parts`, `refund_requests`, `spare_parts`, `spare_part_compatibilities`, `spare_part_requests`, `route_settings`, `unclaimed_cash_findings`— se crea con `CREATE TABLE IF NOT EXISTS` y **persiste** entre arranques: una columna nueva en ellas llega únicamente por migración. Para una columna generada `STORED` en una de esas tablas, `ALTER TABLE ... ADD` no está soportado en TiDB (R3): la vía sería recrear la tabla (con traslado de datos y filas hijas) o declararla `VIRTUAL`. Es el caso que más conviene pensar antes de tocar `incident_replaced_parts`.

---

## 6. Checklist para quien toque el esquema (antes de mergear)

1. ¿La migración modifica alguna columna del inventario §2 o alguna de sus dependencias? → ruta del DDL base (§5.1).
2. ¿Añade una columna generada? → debe nacer en el DDL base; por `ALTER`, solo `VIRTUAL` (R3).
3. ¿Elimina o cambia el tipo de una columna cubierta por un índice, o que sostiene una foránea? → resolver índice y foránea primero (R4).
4. ¿Amplía un enum? → comprobar que el DDL base del despliegue ya declara el estado final (guardia: `CloudDeploySchemaParityTest` Caso 4).
5. Ejecutar `php tests/run_all.php` y, para el camino desplegado, el arranque real sobre una base desechable: `php bin/init_cloud_db.php --name=<base_de_sonda>`.

---

## 7. Cómo se verifica hoy

- `CloudDeploySchemaParityTest` **Caso 4**: exige que el DDL base declare todos los valores de `IncidentStatus` y que el comprobador muerda con un enum incompleto.
- El arranque desplegado se reproduce en local contra una base desechable: DDL base + las 15 migraciones + semillas, con dos arranques consecutivos (idempotencia) y paridad total de esquema contra `vendguard_db` (tipo, nulabilidad, default, generadas y **posición de columnas**).
- **Límite declarado:** no hay TiDB en local; las reglas R1–R6 se sostienen sobre la evidencia de producción y la documentación oficial de TiDB, no sobre una corrida local.

---

## 8. Fuentes

- TiDB · `ALTER TABLE ... MODIFY COLUMN` y `ADD COLUMN` (documentación oficial de PingCAP): restricciones sobre columnas generadas y conversiones de tipo.
- Incidente de producción del 2026-10-11 (Render · TiDB Cloud) y reproducción en MariaDB 10.4 del intento de liberar `uq_machine_active_ticket` (error 1553).
