# Contrato de limpieza de datos de prueba

**Ámbito:** `tests/` (todas las suites de integración y el runner manual)
**Estado:** Aprobado
**Incidencia que lo origina:** T-REF-01 (migración `008_refund_management.sql`)
**Artículos de la Constitución:** Art. III (inviolabilidad de datos, cero borrado físico)

---

## 1. Por qué existe este contrato

Hasta la migración `008_refund_management.sql`, las 29 suites de integración
repetían a mano un bloque de limpieza al inicio de su fichero:

```php
$pdo->exec("DELETE FROM incident_comments");
$pdo->exec("DELETE FROM incident_history");
$pdo->exec("DELETE FROM incidents");
```

Ese orden sólo es válido **mientras ninguna tabla hija de `incidents` tenga
filas**. Hoy hay seis tablas que referencian `incidents.id`, y cuatro de ellas
con `ON DELETE RESTRICT`:

| Tabla hija | `ON DELETE` | Migración de origen |
|---|---|---|
| `incident_comments` | `CASCADE` | `schema.sql` |
| `incident_history` | `CASCADE` | `schema.sql` |
| `incident_replaced_parts` | **`RESTRICT`** | `006_spare_parts_catalog_and_traceability.sql` |
| `spare_part_requests` | **`RESTRICT`** | `006_spare_parts_catalog_and_traceability.sql` |
| `refund_requests` | **`RESTRICT`** | `008_refund_management.sql` (T-REF-01) |
| `unclaimed_cash_findings` | **`RESTRICT`** | `008_refund_management.sql` (T-REF-01) |

**El fallo no fue culpa de T-REF-01.** La primera grieta la introdujo
`006_spare_parts`: a partir de ahí, una única fila huérfana de
`spare_part_requests` o `incident_replaced_parts` ya era suficiente para reventar
las suites. T-REF-01 sólo añadió dos columnas más al mismo problema. Parchar
`refund_requests` habría sido tratar el síntoma.

**Consecuencia observada:** una fila huérfana (por ejemplo, creada por una
ejecución con error fatal que saltó el `finally` de otra suite) provocaba

```
SQLSTATE[HY000] [23000] Integrity constraint violation: 1451
Cannot delete or update a parent row: a foreign key constraint fails
(vendguard_db.refund_requests, CONSTRAINT fk_refund_incident ...)
```

en **22 suites simultáneamente**. Sobre una base limpia todo pasaba en verde,
que es exactamente por lo que el defecto era invisible en el día a día.

---

## 2. La invariante

> Ninguna suite borra `incidents` (ni ninguna tabla de su subárbol) sin pasar
> por `TestDataCleaner`, que garantiza el orden hijas → padres.

El orden de borrado **no está hardcodeado**. `TestDataCleaner` lee el grafo real
de claves foráneas desde `information_schema`, descubre transitivamente el
subárbol a purgar desde sus raíces (`incidents`, `spare_parts`,
`preventive_orders`) y lo topologiza con el algoritmo de Kahn. Por tanto:

> **Una clave foránea nueva con `ON DELETE RESTRICT` queda cubierta
> automáticamente, sin modificar `TestDataCleaner`.**

Orden que produce hoy (12 tablas, `incidents` necesariamente la última):

```
 1. incident_comments          7. preventive_orders
 2. incident_history           8. spare_part_compatibilities
 3. incident_replaced_parts    9. spare_part_requests
 4. preventive_order_items    10. spare_parts
 5. refund_requests           11. unclaimed_cash_findings
 6. sanitary_certificates     12. incidents
```

---

## 3. API de `tests/Support/TestDataCleaner.php`

| Método | Uso |
|---|---|
| `purge(PDO): array` | Reinicio operacional completo. Vacía las 12 tablas transitorias en orden seguro y devuelve las filas borradas por tabla. |
| `purgeIncident(PDO, int $id): array` | Purga dirigida de un incidente y **todas** sus hijas por `incident_id`. |
| `purgeIncidentsByMachine(PDO, int $machineId): array` | Ídem para todos los incidentes de una máquina. |
| `purgeIncidentByTicket(PDO, string $ticketCode): array` | Ídem para un ticket concreto. |
| `purgeIncidentsMatchingTicket(PDO, string $like): array` | Ídem para tickets que casan con un patrón `LIKE`. |
| `deleteOrder(PDO): array` | Expone el plan de borrado. Sólo lectura; lo usan las aserciones. |

Todas las operaciones de escritura van dentro de una transacción con
`rollBack` en caso de fallo, de modo que una limpieza interrumpida no deja la
base a medias.

### Patrón canónico de suite

```php
require_once __DIR__ . '/../bootstrap.php';   // ya carga TestDataCleaner

// Limpieza operacional segura (orden derivado del grafo de FKs).
TestDataCleaner::purge($pdo);

$seedRunner = new SeedRunner($pdo);
$seedRunner->seedAll();
```

`SeedRunner` repone `locations`, `machines`, `users`, `preventive_orders`,
`preventive_settings`, `route_settings`, `sanitary_certificates`, `spare_parts`
y `spare_part_compatibilities`. `purge()` **nunca** borra las tablas maestras
(`users`, `machines`, `locations`, `route_settings`, `preventive_settings`,
`audit_log`): están declaradas en `PROTECTED_TABLES` y se descartan aunque
aparezcan como hijas en el grafo.

---

## 4. Guardia automática (Fase 0 de `tests/run_all.php`)

Antes de ejecutar una sola suite, `run_all.php` escanea
`tests/integration/*.php` y `tests/Manual/*.php` en busca de
`DELETE FROM incidents` **sin cláusula `WHERE`**. Si encuentra uno, aborta la
batería entera con código de salida `1` nombrando `fichero:línea`.

```
--- Fase 0: Guardia de limpieza de datos ---
  [BLOQUEO] 1 suite(s) borran `incidents` sin usar TestDataCleaner:
         - tests/integration/CancelIncidentEndpointTest.php:348

 BATERÍA ABORTADA. Usa TestDataCleaner::purge() o purgeIncident*()
 para que el orden de borrado respete las claves foráneas RESTRICT.
```

Las variantes **con `WHERE` sí se permiten**: son borrados dirigidos de la fila
propia del test. Lo que no se permite es que esos borrados dirigidos ignoren a
las hijas; de ahí que exista `purgeIncident*()` en lugar de un `DELETE` suelto.

Única excepción: `TestDataCleanerIsolationTest.php`, que reproduce el borrado
ingenuo a propósito para demostrar que falla (§5).

---

## 5. Prueba de mordida

`tests/integration/TestDataCleanerIsolationTest.php` (45 aserciones) es lo que
impide que este contrato se degrade de nuevo en un helper que "parece" funcionar.
Verifica:

1. Las cuatro hijas `RESTRICT` admiten filas ligadas a un incidente vivo.
2. El plan derivado del grafo coloca siempre `incidents` la última, y cada hija
   antes que su padre, incluidos los padres dobles (`incident_replaced_parts`
   referencia `incidents`, `preventive_orders` **y** `spare_parts`).
3. **El orden ingenuo falla.** Reproduce el borrado histórico y afirma que lanza
   el error 1451 de MariaDB.
4. `purge()` drena las cuatro hijas sin excepción y sin tocar las maestras.
5. `purgeIncident()` drena un incidente sin afectar a los demás.
6. Autoverificación de no-fuga: la suite no deja filas en las cuatro hijas.

**Verificado por sabotaje:** invirtiendo el orden en `TestDataCleaner`, 15
aserciones pasan a rojo, incluida la que reproduce el 1451 original. Un helper
con el orden invertido sólo fallaría en producción, que es precisamente lo que
esta sección evita.

---

## 6. Prohibiciones

### 6.1 `SET FOREIGN_KEY_CHECKS=0` — prohibido, también en tests

Desactivar la verificación referencial "arreglaría" el síntoma dejando filas
huérfanas **en silencio**. Es exactamente la ceguera que causó este defecto: un
verde sobre datos inconsistentes. Además choca con el Art. III.

### 6.2 `DELETE FROM incidents` en crudo — prohibido

Ver §4. La guardia lo aborta antes de ejecutar nada.

### 6.3 Transacción con rollback por suite — no viable como sustituto

10 de las 28 suites de `tests/integration/` hablan con el servidor HTTP real
mediante `cURL` (`QrEndpointsIntegrationTest`, `ReopenIncidentEndpointTest`,
`CreateIncidentEndpointTest`, `TechnicianRouteEndpointTest`…), es decir en otro
proceso y otra conexión. Un `beginTransaction`/`rollBack` no las cubre.

### 6.4 Cada suite borra únicamente lo suyo — no aplicado como regla general

Varias suites afirman sobre listados globales de incidencias; obligarlas a un
aislamiento por identificador exigiría reescribir su estrategia, no reparar una
deuda de infraestructura.

---

## 7. Checklist para quien añada una tabla con FK a `incidents`

1. Declarar la FK con `ON DELETE RESTRICT` (nunca `CASCADE` sobre datos de
   negocio: `CASCADE` borraría el rastro histórico sin pasar por auditoría).
2. **No hace falta tocar `TestDataCleaner`.** El grafo se lee en tiempo de
   ejecución y la tabla entra sola en el plan de purgado.
3. Ejecutar `php tests/integration/TestDataCleanerIsolationTest.php`: la
   aserción 2.7 exige que el plan siga teniendo 12 tablas, así que una tabla
   nueva obliga a actualizarla. Eso es intencionado: es el aviso de que el
   contrato cambió.
4. Si la tabla necesita limpieza dirigida, añadir un método `purgeXxxBy…`
   siguiendo el patrón de `purgeMatchingIncidents()`.