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
filas**. Hoy hay siete tablas que referencian `incidents.id`, y cinco de ellas
con `ON DELETE RESTRICT`:

| Tabla hija | `ON DELETE` | Migración de origen |
|---|---|---|
| `incident_comments` | `CASCADE` | `schema.sql` |
| `incident_history` | `CASCADE` | `schema.sql` |
| `incident_replaced_parts` | **`RESTRICT`** | `006_spare_parts_catalog_and_traceability.sql` |
| `spare_part_requests` | **`RESTRICT`** | `006_spare_parts_catalog_and_traceability.sql` |
| `refund_requests` | **`RESTRICT`** | `008_refund_management.sql` (T-REF-01) |
| `unclaimed_cash_findings` | **`RESTRICT`** | `008_refund_management.sql` (T-REF-01) |
| `sanitary_sweep_contentions` | **`RESTRICT`** | `018_sanitary_sweep_visibility.sql` (T-PAUSE-41) |

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
`preventive_orders`, `sanitary_sweep_passes`) y lo topologiza con el algoritmo
de Kahn. Por tanto:

> **Una clave foránea nueva con `ON DELETE RESTRICT` queda cubierta
> automáticamente, sin modificar `TestDataCleaner`.**

`sanitary_sweep_passes` entra como **raíz propia** y no por el grafo: es el
latido del barrido (T-PAUSE-41), un libro operativo **sin claves foráneas** —no
cuelga de `incidents` a propósito, porque registra pasadas, no expedientes—, así
que ninguna otra raíz lo alcanza. Dejar sus filas entre suites haría que la
ventana de tendencia de una prueba contase las pasadas de otra.

Orden que produce hoy (14 tablas, `incidents` necesariamente la última):

```
 1. incident_comments          8. sanitary_sweep_contentions
 2. incident_history           9. sanitary_sweep_passes
 3. incident_replaced_parts   10. spare_part_compatibilities
 4. preventive_order_items    11. spare_part_requests
 5. refund_requests           12. spare_parts
 6. sanitary_certificates     13. unclaimed_cash_findings
 7. preventive_orders         14. incidents
```

---

## 3. API de `tests/Support/TestDataCleaner.php`

| Método | Uso |
|---|---|
| `purge(PDO): array` | Reinicio operacional completo. Vacía las 14 tablas transitorias en orden seguro y devuelve las filas borradas por tabla. |
| `purgeIncident(PDO, int $id): array` | Purga dirigida de un incidente y **todas** sus hijas por `incident_id`. |
| `purgeIncidentsByMachine(PDO, int $machineId): array` | Ídem para todos los incidentes de una máquina. |
| `purgeIncidentByTicket(PDO, string $ticketCode): array` | Ídem para un ticket concreto. |
| `purgeIncidentsMatchingTicket(PDO, string $like): array` | Ídem para tickets que casan con un patrón `LIKE`. |
| `countParkResidue(PDO): array` | **Detector de residuo.** Cuenta las filas de las maestras del parque (sedes, máquinas y cuentas) que no pertenecen al catálogo canónico del sembrador (§8), con la identidad de las muestras. Sólo lectura. |
| `restorePark(PDO): array` | **Restitución del parque.** Restituye el vínculo máquina→sede del catálogo y drena el subárbol completo de las filas fuera de catálogo (§8.2), sin tocar una sola fila del catálogo. Aborta sólo lo que el catálogo no puede situar (§8.5). |
| `restituteCatalogMachines(PDO): array` | **Restitución del vínculo.** Devuelve a su sede canónica las máquinas del parque que una suite reubicó (§8.5); la invoca `restorePark()`. Devuelve `code => site_code` de las devueltas. |
| `restituteCatalogUsers(PDO): array` | **Restitución del freno de intentos.** Reinicia `login_attempts` y `login_locked_until` de las cuentas del catálogo (§8.5); la invocan el gancho de apagado y el cierre de la batería. Devuelve las cuentas reiniciadas. |
| `registerShutdownRestitution(): void` | Gancho de apagado de la restitución (§8.3): lo invoca cada suite que toca maestras. Restituye el estado de las cuentas y drena el residuo. |
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

### 4.1 El puerto del servidor de pruebas es del ejecutor

Las suites de integración incluyen pruebas de HTTP real contra `127.0.0.1:8000`, y ese
servidor lo arranca el ejecutor durante **una** corrida: es un recurso suyo y tiene que
poder reclamarlo. Dos reglas, las dos nacidas de un defecto medido:

* **Se arranca en forma de vector de argumentos, no como cadena de comando.**
  `proc_open` con una cadena pasa por un intérprete intermedio —`cmd.exe /c` en
  Windows—, de modo que `proc_terminate()` mataba al intérprete y dejaba el servidor
  **huérfano** escuchando. Medido: tras una corrida quedaba vivo un
  `php -S 127.0.0.1:8000 -t <árbol>/public` de otro árbol, y la corrida siguiente
  —que encuentra el puerto ocupado y por tanto no arranca el suyo— medía todos sus
  casos HTTP contra ese servidor, con el contrato de credenciales de otro árbol y sin
  que nada en la salida lo dijera.
* **El cierre se comprueba, y un servidor ajeno se nombra.** El ejecutor verifica que
  el puerto quedó libre tras terminar; si no lo está, avisa. Y si al arrancar la Fase 3
  el puerto ya estaba ocupado, lo declara en voz alta: la batería puede seguir —el
  README documenta levantar un servidor para desarrollo—, pero no puede fingir que ese
  servidor es el suyo.

---

## 5. Prueba de mordida

`tests/integration/TestDataCleanerIsolationTest.php` (50 aserciones) es lo que
impide que este contrato se degrade de nuevo en un helper que "parece" funcionar.
Verifica:

1. Las cinco hijas `RESTRICT` admiten filas ligadas a un incidente vivo.
2. El plan derivado del grafo coloca siempre `incidents` la última, y cada hija
   antes que su padre, incluidos los padres dobles (`incident_replaced_parts`
   referencia `incidents`, `preventive_orders` **y** `spare_parts`).
3. **El orden ingenuo falla.** Reproduce el borrado histórico y afirma que lanza
   el error 1451 de MariaDB.
4. `purge()` drena las cinco hijas sin excepción y sin tocar las maestras.
5. `purgeIncident()` drena un incidente sin afectar a los demás.
6. Autoverificación de no-fuga: la suite no deja filas en las cinco hijas.

**Verificado por sabotaje:** invirtiendo el orden en `TestDataCleaner`, 15
aserciones pasan a rojo, incluida la que reproduce el 1451 original. Un helper
con el orden invertido sólo fallaría en producción, que es precisamente lo que
esta sección evita.

`tests/integration/HarnessResidueGuardTest.php` extiende esa misma mordida a las
sedes del banco de pruebas (§8): certifica que el detector ve el residuo que se
le planta, que la purga lo drena **con su subárbol** (máquina e incidencia
colgadas por FK `RESTRICT`, que hacen fracasar el `DELETE` ingenuo) y que las diez
sedes semilla `SEDE-BCN-*` siguen intactas y activas después.

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
   aserción 2.7 exige que el plan siga teniendo 14 tablas, así que una tabla
   nueva obliga a actualizarla. Eso es intencionado: es el aviso de que el
   contrato cambió.
4. **Excepción: tablas sin claves foráneas.** Una tabla suelta (como
   `sanitary_sweep_passes`, el latido del barrido) no la descubre el grafo: hay
   que declararla como raíz en `PURGE_ROOTS` o quedará fuera de `purge()`.
5. Si la tabla necesita limpieza dirigida, añadir un método `purgeXxxBy…`
   siguiendo el patrón de `purgeMatchingIncidents()`.

---

## 8. Las sedes del banco de pruebas (hallazgo F-4 del módulo 11)

`purge()` protege `locations` porque son maestras: borrarlas rompería la
integridad del parque sembrado. Ese mismo blindaje era el agujero del otro lado:
las sedes que **crean las suites** no las retiraba nadie, y el banco acumulaba
filas en cada corrida (206 filas frente a las 10 semilla al abrir el hallazgo F-4).

### 8.1 El catálogo canónico

> Una fila de una maestra del parque —`locations`, `machines`, `users`— que no
> figura en el catálogo canónico del sembrador la creó una suite: es residuo del
> arnés, y se drena con su subárbol.

El catálogo lo declara el propio sembrador (`SeedRunner::canonicalIdentities()`,
derivado de los mismos literales que `seedAll()` escribe), así que **lo que se
siembra es lo que se protege** y no hay una segunda lista que mantener. La regla es
el criterio de pertenencia de `restorePark()` y también el de `countParkResidue()`,
de modo que la lectura y la escritura del residuo no puedan discrepar.

#### La regla que se retiró, y por qué

El primer cierre de F-4 midió el residuo con un **prefijo reservado**
(`SEDE-TEST-*`, `SEDE-MAP*` frente al seed `SEDE-BCN-*`). Era una lista paralela al
catálogo y, por serlo, se degradaba en silencio: una suite que nombrara su sede de
otra forma quedaba fuera —`AdminMachinesEndpointTest` usa `SEDE-TEMP-*`— y ninguna
regla de nombres podía ver lo que otra suite colgase de una **sede semilla** (una
máquina nueva en una sede del catálogo) ni una **cuenta** creada por una suite. El
banco real lo mostró: tenía la sede `SEDE-TEMP-97B8` y la cuenta
`tecnico.nuevo.b27c@…`, y el detector declaraba que no había residuo. La aserción
`1.6` de la guarda conserva el criterio retirado como testigo: mide cuántas raíces
del residuo alcanzaba.

### 8.2 Qué drena y qué no

`restorePark()` elimina **el subárbol completo** de esas filas, con el orden
derivado otra vez del grafo real de claves foráneas: las tablas que referencian
`locations`, `machines` o `users` y las que cuelgan de ellas lo hacen todas con
`ON DELETE RESTRICT` (`incidents`, `incident_replaced_parts`, `preventive_orders`,
`refund_requests`, `sanitary_certificates`, más `unclaimed_cash_findings` bajo
`machines`). La condición de borrado de cada tabla se encadena por el grafo
mediante subconsultas, así que **una FK nueva hacia una maestra o hacia sus hijas
entra sola en el plan**, sin tocar el limpiador.

Nunca toca una fila del catálogo: la condición raíz es una lista explícita de `id`
obtenida por identidad contra el catálogo, y una lista vacía detiene la operación
sin ejecutar una sola sentencia. Si una fila del catálogo quedase **dentro** del
plan —una máquina semilla que una suite reubicó en su sede de pruebas— el drenaje
la arrastraría como hija, así que la operación aborta nombrando la identidad
culpable **antes** de borrar nada (§8.5).

### 8.3 Quién lo invoca

| Punto | Motivo |
|---|---|
| `TestDataCleaner::registerShutdownRestitution()` en cada suite creadora | Declara el gancho de apagado en **un solo sitio** y se ejecuta **también tras un error fatal**, que es exactamente el camino en el que un `finally` no corre y por el que el residuo se acumulaba (F-4). Cubre la ejecución suelta de la suite (`php tests/integration/X.php`). Si no puede drenar deja subir el fallo: una suite no se declara en verde sobre un banco que acaba de quedar sucio. |
| Fase 5 de `tests/run_all.php` | Red de cierre: drena lo que una suite nueva (sin el gancho) o un proceso muerto hayan dejado y **declara la corrida en rojo** si encontró algo, porque un residuo drenado aquí significa que alguien no restituyó su estado. Es el único punto capaz de ver una fuga de una suite sin gancho: el drenaje borra la evidencia antes de que la guarda pueda volver a medirla. La base se restituye igualmente, para no envenenar la corrida siguiente. |

Suites que crean maestras y declaran el gancho hoy: `AdminLocationsEndpointTest`,
`AdminMachinesEndpointTest`, `AdminUsersEndpointTest`, `CoordinatorRouteMapApiTest`,
`PdoLocationRepositoryTest`, `PdoUserRepositoryTest`, `PendingInfoConstitutionalTest`,
`ResolveIncidentEndpointTest`, `SeedRunnerSingleEngineTest`, `SiteAccessCodeTest`,
`SiteManagerRouteDataSegregationTest`, `TechnicianRouteEndpointTest`,
`TechnicianRouteMapApiTest` y la propia `HarnessResidueGuardTest`.

Las suites que limpian a mano en su camino feliz —`AdminMachinesEndpointTest` y
`AdminUsersEndpointTest` retiran su sede y su cuenta al final— declaran el gancho
precisamente para el camino que no llega al final: un error fatal a mitad dejaba la
sede y la cuenta en el banco, que es la fuga que se midió en vivo antes de este
cierre.

**Alcance del estado que se restituye.** El freno de intentos de las **cuentas** del
catálogo (`users.login_attempts`, `users.login_locked_until`) forma parte de la
restitución; el de las **sedes** (`locations.login_attempts`,
`locations.login_locked_until`) no: lo rearma el reencuentro del sembrador en la fase
de cierre. Una suite que bloquee una sede limpia su propio bloqueo al terminar
—`SiteAccessCodeTest` lo hace sobre `SEDE-BCN-02`, que es del catálogo— y no puede
confiar el de la sede al gancho.

### 8.4 La mitad que ya estaba cerrada

F-4 documentaba **dos** residuos. El de las máquinas («fuera de servicio» son dos
marcas, `is_blocked_no_access` **e** `is_active`) lo cerró la propia sonda del
módulo 11 al restituir ambas en su limpieza; esta sección cierra el de las sedes,
que era ajeno a la sonda.

### 8.5 El catálogo no se drena: se restituye

El criterio ensanchado hace posible por primera vez que una fila del catálogo quede
**dentro** del plan de drenaje, y el arnés también puede degradar el catálogo sin
dejar residuo alguno. Las dos caras se cierran **restituyendo** antes de borrar:

* **El vínculo máquina→sede.** Una suite que reubica una máquina del catálogo en su
  sede de pruebas y muere antes de devolverla deja la máquina dentro del plan como
  hija de esa sede, y el drenaje la arrastraría. `restituteCatalogMachines()` la
  devuelve a la sede que declara el catálogo (`SeedRunner::canonicalMachineSites()`,
  el mismo literal que siembra) antes de planificar. El síndrome está medido:
  `AdminMachinesEndpointTest` traslada `VEND-0101` y sólo lo devuelve al final de su
  camino feliz, y el sembrador no repara ese vínculo —su upsert no reescribe
  `location_id`—.
* **El freno de intentos de las cuentas.** Una suite que prueba el bloqueo por
  intentos fallidos y muere antes de reiniciarlo no deja ninguna fila de más, pero sí
  una cuenta semilla cerrada: la suite que sólo inicia sesión falla con
  `ACCOUNT_LOCKED` (423) y el defecto parece suyo. `restituteCatalogUsers()` reinicia
  `login_attempts` y `login_locked_until` de las cuentas del catálogo. El sembrador ya
  limpia ese freno en las **sedes**; ésta es la mitad que faltaba.

Ninguna de las dos adivina: el destino —la sede— y el valor —el freno a cero— salen
del catálogo y del estado canónico que el propio sembrador define.

Lo que el catálogo **no puede situar** —una máquina del catálogo cuya sede canónica no
existe en el banco— no se resuelve en silencio: `restorePark()` comprueba, antes de
ejecutar una sola sentencia, que ninguna identidad canónica siga dentro del plan, y
aborta nombrando la tabla y las identidades culpables.

**Evidencia medida (2026-10-15, motor único PostgreSQL).**

| Medición | Antes | Después |
|---|---|---|
| Parque del banco | 11 sedes · 7 cuentas (dos fugas invisibles al prefijo) | **10 sedes · 13 máquinas · 6 cuentas** |
| Guarda `HarnessResidueGuardTest` | 14/14, midiendo sedes por prefijo | **25/25**: identidad, las tres familias del residuo, restitución del vínculo y del freno |
| Red de cierre de la batería | anunciaba «Ninguna sede del banco de pruebas» con residuo vivo | «**Ninguna maestra quedó fuera del catálogo canónico**», con el parque restituido |

Las dos fugas vivas que el criterio retirado no veía —la sede `SEDE-TEMP-97B8` y la
cuenta `tecnico.nuevo.b27c@vendguard.internal`, dejadas por suites que murieron antes de
su limpieza manual— se drenaron en la primera pasada del criterio nuevo.

---

## 9. El reloj del arnés y los instantes que cruzan al motor

La limpieza deja el banco en un estado conocido; el **reloj** decide qué mide cada
suite sobre ese estado. Este apartado fija las dos reglas que lo hacen determinista.

### 9.1 El reloj se declara, no se hereda

`tests/bootstrap.php` declara el reloj del arnés en la **zona operativa del parque**
(`EngineClock::OPERATIONAL_TIMEZONE`) en lugar de aceptar el `date.timezone` del
`php.ini` de la máquina. Sin esa declaración, cada `new DateTimeImmutable()` y cada
`date()` desnudos de las suites tomaban el huso del desarrollador y **el veredicto de la
batería cambiaba de máquina en máquina**: medido con el mismo código y el mismo banco, la
suite del tablero de coordinación daba 6 fallos en `Europe/Berlin` y 2 en `UTC`. Una
batería cuya constante de color depende del `php.ini` de quien la ejecuta no es hermética
(EARS-13.5) y no puede certificar nada (EARS-13.6).

La zona declarada es la **operativa**, no la del motor, porque es la que el sistema
documenta para sus instantes locales (`IncidentPauseService` §2 de sus decisiones de
diseño, `AuditEvent`, `MetricFilter`) y porque es la única elección que **no cambia el
significado de los renders desnudos que ya existen**: `Europe/Berlin` y `Europe/Madrid`
comparten huso y reglas de horario de verano, de modo que la declaración no altera
ninguno de los 260 veredictos y sí elimina la dependencia de la máquina.

### 9.2 Un instante que cruza al motor se escribe en la zona del motor

Las marcas del esquema son `timestamptz` y la sesión del motor va en **UTC** (PostgreSQL
por defecto, y el contenedor desplegado, cuyo `php.ini` tampoco declara zona). Un
`'Y-m-d H:i:s'` sin zona no dice lo que parece: el motor lo interpreta en *su* zona, de
modo que el instante guardado queda el huso entero por delante del que la suite quería
sembrar. El idioma correcto vive en un único punto, `tests/Support/EngineClock.php`:

* `EngineClock::now()` — el reloj real con el huso del motor explícito;
* `EngineClock::stamp($instante)` — la marca `Y-m-d H:i:s` de un instante en la zona del motor;
* `EngineClock::shift($segundos)` — «hace N segundos», para sembrar antigüedades.

La dirección contraria no necesita regla: una lectura de `timestamptz` viaja con su
desfase (`2026-10-15 20:40:00+00`), así que `strtotime()` la interpreta en su instante
real con cualquier reloj local. La guarda lo comprueba para que no se dé por supuesto.

### 9.3 Lo que esto NO decide

El contrato del **vencimiento de sede** (`sla_target_at`) y las marcas del libro de
pausas siguen escribiéndose hoy como pared local de sede. Mientras esa reconciliación no
se decida —si el dominio sigue persistiendo pared de sede o pasa a persistir instantes,
y cómo publica la API cada una— esos sitios quedan **declarados** en el ratchet de la
guarda con su dueño, no reparados a medias: reparar la siembra de una suite sin decidir
la forma que publica el motor dejaría el caso en rojo por una razón distinta y ocultaría
la decisión (EARS-13.10).

### 9.4 La guarda

`tests/unit/HarnessClockContractTest.php` certifica cinco cosas: la declaración del
reloj; que la zona que el arnés declara para el motor es la que informa la sesión viva
(`SHOW TimeZone`); que un render en hora local se mide el huso entero por delante, es
decir **que el defecto es real y de qué magnitud**; que las lecturas viajan con desfase; y
el **censo** de renders locales en ficheros con conexión viva al motor, contrastado contra
un ratchet declarado con motivo y dueño por entrada. La guarda exige que el censo real
coincida **exactamente** con el saldo: un sitio nuevo la pone en rojo y reparar un sitio
sin retirar su entrada también. El detector se prueba contra un espécimen con el defecto y
contra el mismo espécimen curado, de modo que una guarda que no distingue no certifique.

**Evidencia medida (2026-10-15, motor único PostgreSQL).**

| Medición | Antes | Después |
|---|---|---|
| Reloj de la máquina frente al del arnés | `php.ini` (`Europe/Berlin`) decidía cada render desnudo | declarado en `Europe/Madrid`; la corrida imprime `reloj: Europe/Madrid (motor en UTC)` |
| Veredicto de `CoordinatorIncidentsEndpointTest` | 6 fallos en `Europe/Berlin` · 2 en `UTC`: **dependía de la máquina** | **verde, 36 aserciones**, con el mismo veredicto bajo la zona de la máquina, `UTC`, `America/New_York` y `Pacific/Auckland` |
| Minutos de espera y `sla_breached` (casos 3.8, 3.9, 3.12 y 4.1) | instantes sembrados 2 h en el futuro: 0 minutos medidos y alerta apagada | minutos reales (75/90/15) y `sla_breached` encendido donde el caso lo exige |
| Sitios de la clase en ficheros con conexión viva | **51 sitios en 21 ficheros** | **36 reparados** (escritos por `EngineClock`) y **15 declarados** en 7 ficheros, todos con dueño y motivo; censo contrastado por la guarda |
| Suites sanadas, por diferencia de dos corridas completas | línea base: 260 suites · 8.503 aserciones · **59 rojas** | 261 · 8.525 · **57 rojas**, con **las mismas rojas menos dos** (`CoordinatorIncidentsEndpointTest` y `CronAutoCloseEndpointTest`) y **cero regresiones** |
| Mordida de la guarda | — | con el reloj sin declarar: 1.1 en rojo nombrando `Europe/Berlin`; con una suite degradada: 4.1 enumerando sus 5 sitios---

## 10. El dialecto del SQL de la batería

### 10.1 La regla

El SQL que las suites escriben **se escribe en la sintaxis del motor único**, incluido el de
las fixtures: una suite que redacta sentencias en el dialecto retirado no certifica el
sistema que sirve producción, y mientras el porte no esté hecho mide el dialecto en lugar
de la conducta. La regla es de forma, no de fondo: la sentencia puede seguir siendo la
misma consulta, escrita como la entiende el motor.

**La unidad del porte es la sentencia.** Un literal que se reescribe se deja **entero** en
la sintaxis del motor, incluidas las partes que no son el motivo de la reescritura —el
literal booleano (D-3), la clave canónica (A-1)— porque una sentencia a medias no se puede
medir: el caso quedaría en rojo por una razón distinta de la que se estaba reparando. Las
sentencias que no portan dialecto **no se tocan**: su clase pertenece a otro lote y
mezclarla aquí ocultaría los dos defectos.

### 10.2 Lo que este contrato NO toca (declarado, no omitido)

| Sitio | Por qué no se toca aquí | Dueño |
|---|---|---|
| `tests/unit/SqlDialectPortabilityTest.php` | Es la **guarda del dialecto**: renderiza las dos formas a propósito y rastrea el saldo de `src`, `database` y `bin`. Su sentido se invierte con la tarea que retira el motor heredado | T-13-06 |
| `tests/unit/RouteMapMigrationContractTest.php`, `RefundsModuleClosureTest` (§4.3) | Comparan contra el **contenido de los `.sql` heredados**, que siguen existiendo y no han cambiado: invertirlas ahora certificaría un fichero que aún no se ha retirado | T-13-16 |
| `SchemaConvergenceParityTest`, `PostgresConstitutionalTest`, `PostgresSchemaTest`, `DemoHistorySeederPostgresTest`, `PoolerLatencyMeasurementTest` | Categoría propia del **motor nuevo** y el **contrato de correspondencia entre motores**: se retiran o se reescriben con la retirada, no antes | T-13-13, T-13-16 |
| `CloudDeploySchemaParityTest` | Guarda del **DDL de despliegue heredado** (`cloud_init.sql`), con su propio esquema de sonda | T-13-13, T-13-16 |
| `ConnectionFactoryTest` (rama del driver heredado) | Rama **inalcanzable** hoy: la suya es la factoría de una sola rama | T-13-14 |
| `PostgresFixtureBuilder` **no registra hallazgo**: lee `information_schema` **en minúsculas**, que es la forma que el motor entiende | Por eso no cuenta como dialecto, y la regla afinada del censo no lo marca | — |
| Literal booleano (D-3) y clave canónica (A-1) en sentencias que no portan dialecto | Su clase no es la de este contrato: es la semántica de esquema que drena cada lote de porte | T-13-07 a T-13-12 |

El **censo de la clase** —literales de cadena tokenizados, comentarios y docblocks fuera— es
lo que hace comprobable la regla: se cuenta por familia, en literales y no en líneas, y los
ficheros con hallazgos tienen que estar todos en la tabla de arriba.

**Evidencia medida (2026-10-16, motor único PostgreSQL).**

| Familia del dialecto heredado | Antes | Después |
|---|---|---|
| Identificador entre acentos graves | 43 literales | **12**, en las guardas y expectativas declaradas |
| Upsert heredado (`ON DUPLICATE KEY`) | 18 | **8**, en las mismas |
| `VALUES(columna)` heredado | 7 | **5**, en las mismas |
| Aritmética temporal (`DATE_ADD`, `DATE_SUB`, `TIMESTAMPDIFF`, `CURDATE`) | 23 | **0** |
| Extracción JSON (`JSON_EXTRACT`, `JSON_UNQUOTE`) | 0 | **0** |
| Funciones de texto (`DATABASE()`, `GROUP_CONCAT`) | 15 | **5**, en las suites declaradas |
| Catálogo de metadatos en su grafía heredada | 11 | **9**, en el contrato de correspondencia y la guarda del despliegue |
| Ventana de purga (`SET @vendguard_purge`) | 4 | **0** (la única forma es `set_config('vendguard.purge', …, false)`, §3) |
| `SHOW COLUMNS` | 1 | **0** (se lee `pg_enum`) |
| **Total** | **122 literales en 30 ficheros** | **39 literales en 7 ficheros**, los 7 declarados con dueño en esta tabla |

La corrida completa antes y después de este porte fija el efecto: **261 suites · 8.802
aserciones · 47 rojas** frente a la línea base de **261 · 8.525 · 57 rojas**, con **diez
suites sanadas y cero regresiones**. Las diez: `CoordinatorIncidentDetailConstitutionalTest`,
`CoordinatorRouteMapApiTest`, `IncidentCommentsPagedRepositoryTest`,
`PdoLocationRepositoryTest`, `ResolveIncidentEndpointTest`, `TechnicianRouteEndpointTest`,
`UserLoginLockoutTest`, `AuditEntityTypesTest`, `IncidentPauseInactivityCancellationTest` y
`RefundsModuleClosureTest`.
