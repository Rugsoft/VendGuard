# Revisión de la PR #25 · Auditoría EVA-DTS `.dex` (Módulo 39)

| Campo | Valor |
| :--- | :--- |
| **Proyecto** | Gestor de Incidencias de Vending (*VendGuard*) |
| **PR** | [#25 · feat/eva-dts-dex-audit](https://github.com/Rugsoft/VendGuard/pull/25) |
| **Rama origen** | `feat/eva-dts-dex-audit` |
| **Rama destino** | `main` |
| **HEAD revisado** | `305d7ac5` (`docs(eva-dts-dex-audit): sync certification metrics after the UI audit`) |
| **Base** | `efd18fb8` (`main`) |
| **Fecha de la revisión** | 2026-11-27 |
| **Estado** | PR abierta, **no fusionada**. `PR25-01`, `PR25-02`, `PR25-03`, `PR25-04` y `PR25-05` cerrados en local el 2026-11-27; los cuatro hallazgos restantes siguen abiertos. |
| **Alcance** | Diff `origin/main...HEAD` del Módulo 39: migración `035` y convergencia con `supabase_init.sql`, parser/validador, servicio, repositorio y gateway, RBAC/perímetro, controladores, consola de Coordinación, vistas móviles y cola Offline. Contraste con `specs/39-eva-dts-dex-audit/spec.md` y las suites del módulo. |
| **Resultado** | **9 hallazgos**: 5 cerrados (`PR25-01`, `PR25-02`, `PR25-03`, `PR25-04` y `PR25-05`) y 4 abiertos (2 P2 y 2 P3). Las correcciones y sus regresiones están incluidas en esta rama local. |

> **Acta de cierre · `PR25-01` (2026-11-27):** cerrado en local. El historial móvil exige actor, resuelve la cobertura con `findMachineContext()` —lectura sin `FOR UPDATE`— y responde `404 MACHINE_NOT_FOUND` o `403 MACHINE_OUTSIDE_RESTOCKER_ZONES`/`MACHINE_OUTSIDE_TECHNICIAN_SCOPE`. La regresión `DexAuditApiTest` 1.5–1.8 cierra en verde (50/50 aserciones). La PR no se ha fusionado.

> **Acta de cierre · `PR25-02` (2026-11-27):** cerrado en local. `MachineDexAuditService::importDex()` ejecuta el caso de uso completo dentro de `TransactionManagerInterface`; `PdoMachineDexAuditRepository::create()` participa en esa unidad de trabajo mediante `SAVEPOINT` y solo abre/confirma transacción propia cuando es el dueño. La carrera de dos DEX válidos distintos queda certificada en `DexAuditConcurrencyTest` 4.1–4.3, y el canal `DexAudit` cierra 7/7 suites · 179 aserciones. Además, el replay de una misma `client_mutation_id` exige la misma huella SHA-256; si el contenido cambia, responde `409 CLIENT_MUTATION_ID_ALREADY_USED`. La PR no se ha fusionado.

> **Acta de cierre · `PR25-03` (2026-11-27):** cerrado en local. La importación contrasta siempre la identidad `ID1` contra `machines.code` de la máquina resuelta; el `expected_machine_code` del cliente se ignora y los controladores ya no lo reenvían. La regresión unitaria 1.11 y la HTTP 2.6 demuestran que un código esperado falsificado no suprime `identity_mismatch_warning`. `DexAuditApiTest` cierra 51/51 y el canal `DexAudit` 7/7 suites · 180 aserciones. La PR no se ha fusionado.

> **Acta de cierre · `PR25-04` (2026-11-27):** cerrado en local. `MachineDexAuditService::normalizeCapturedAt()` valida una sola vez `captured_at` antes de la transacción: acepta ISO 8601 con offset/Z y `datetime-local` sin zona, interpreta este último como `Europe/Madrid`, normaliza a UTC y rechaza fechas futuras con una tolerancia de 300 s. Los errores de formato y de futuro responden `422` con código y campo. Las puertas móviles añaden un `catch (Throwable)` defensivo. La regresión cubre formato inválido, fecha futura, `Z` con milisegundos y una captura de Coordinación a las 23:30 que se persiste como 22:30 UTC. Unitarias 36/36, API 54/54 y canal `DexAudit` 7/7 suites · 183 aserciones. La PR no se ha fusionado.

> **Acta de cierre · `PR25-05` (2026-11-27):** cerrado en local. `MachineDexAuditService::resolveLinkTargets()` valida y bloquea `restock_visit_id`, `cash_collection_id` e `incident_id` dentro de la transacción del alta: la visita debe existir, ser de la máquina y estar `COMPLETED`; en la puerta del Reponedor, visita y recaudación deben ser del propio actor. Los rechazos son `422 DEX_LINK_TARGET_NOT_FOUND`/`DEX_LINK_ID_INVALID` y `409 DEX_LINK_TARGET_NOT_ELIGIBLE`. El repositorio traduce `23503` de los tres vínculos y los índices únicos de visita/recaudación a `422`/`409 DEX_AUDIT_ALREADY_LINKED`, de modo que la cola Offline aísla el conflicto en lugar de reintentar un 500. Unitarias 45/45, API 58/58 y canal `DexAudit` 7/7 suites · 187 aserciones. La PR no se ha fusionado.

> **Nota:** la batería global de la rama estaba en verde antes de esta revisión (461 suites · 18.140 aserciones · 0 fallos · `DocMetricsGuard` coherente; canal `new_engine` 85/85 · 2.905 aserciones). Ese verde no cubre los huecos descritos: no hay pruebas de perímetro en el listado móvil ni de vínculos operativos, entre otros. Tras las correcciones, `DexAuditApiTest`, `DexAuditConcurrencyTest` y el canal `DexAudit` cierran en verde.

---

## 1. Veredicto

El núcleo del Módulo 39 está bien construido: el parser es determinista, la migración declara las Tablas 52–54 con sus índices únicos parciales y disparadores, las proyecciones móviles eliminan los importes monetarios, la anulación es terminal y las pruebas existentes cubren RBAC de las tres puertas, importación, descarga `/raw`, CSV y concurrencia por misma huella/mutación.

Los defectos se concentran en los bordes del contrato y en la confianza en datos del cliente:

- **P1:** fuga de perímetro en los historiales `.dex` móviles, **cerrada** (`PR25-01`), y ausencia de una transacción real que haga efectivo el `FOR UPDATE` durante el cálculo de deltas, **cerrada** (`PR25-02`).
- **P2:** vínculos M34/M35/M16 sin comprobar, **cerrada** (`PR25-05`); `conflict-dump` sin perímetro y cola `.dex` que sobrevive al cierre de sesión siguen abiertos.
- **P3:** correlativo anual que se bloquea al superar 9.999 y consola de Coordinación sin paginación ni filtros completos.

**Recomendación:** no fusionar hasta corregir `PR25-06` a `PR25-09`. Esos hallazgos deberían entrar en la misma corrección por afectar a integridad, contrato HTTP y datos operativos. `PR25-01`, `PR25-02`, `PR25-03`, `PR25-04` y `PR25-05` ya están corregidos y verificados en local.

---

## 2. Resumen ordenado por importancia

| # | ID | Prioridad | Hallazgo | Impacto | Estado |
|---:|:---:|:---:|---|---|---|
| 1 | `PR25-01` | **P1 · Alta** | El historial `.dex` móvil no validaba el perímetro de la máquina | Un Reponedor o Técnico autenticado podía leer historiales de máquinas fuera de su zona | ✅ Cerrado |
| 2 | `PR25-02` | **P1 · Alta** | El `FOR UPDATE` no serializaba la importación completa | Dos altas concurrentes podían calcular deltas desde el mismo predecesor | ✅ Cerrado |
| 3 | `PR25-03` | **P2 · Media-alta** | `expected_machine_code` permitía ocultar un DEX de otra máquina | Se suprimía el aviso de identidad y se contaminaban los contadores de la máquina destino | ✅ Cerrado |
| 4 | `PR25-04` | **P2 · Media-alta** | `captured_at` no se valida: 500, fechas futuras y desfase horario | Error interno ante una fecha malformada y alteración del orden/deltas | ✅ Cerrado |
| 5 | `PR25-05` | **P2 · Media-alta** | Vínculos M34/M35/M16 sin validar y violaciones únicas no traducidas | Vínculos cruzados, cruces nulos y 500 que la cola reintenta | ✅ Cerrado |
| 6 | `PR25-06` | **P2 · Media** | `conflict-dump` no comprueba existencia ni perímetro de la máquina | Cualquier operador puede escribir en `audit_log` para una máquina ajena o inexistente | Abierto |
| 7 | `PR25-07` | **P2 · Media** | La cola `.dex` no se limpia al cerrar sesión | Los `.dex` crudos quedan en IndexedDB en un dispositivo compartido | Abierto |
| 8 | `PR25-08` | **P3 · Baja** | El correlativo anual se bloquea al pasar de 9.999 | Las importaciones fallan hasta el siguiente año al superar 10.000 volcados | Abierto |
| 9 | `PR25-09` | **P3 · Baja** | La consola de Coordinación no pagina y no ofrece todos los filtros | Los volcados antiguos quedan ocultos tras las primeras 100 filas | Abierto |

---

## 3. `PR25-01` · Fuga de perímetro en el historial `.dex` móvil

**Prioridad:** P1 · **Requisitos afectados:** RF-03/EARS 03.1, contrato de `GET /api/{role}/machines/{machineId}/dex-audits` (`403`/`404`), Art. V.4.

**Estado:** ✅ **Cerrado (2026-11-27)** · corregido en el servicio, el gateway y ambas puertas móviles; regresión 1.5–1.8 en `DexAuditApiTest`.

### Evidencia original

- `src/Presentation/Controller/RestockerRouteController.php:663-671`: `listDexAudits()` obtenía el `machineId` de la URL y llamaba a `listForRole($machineId, 'RESTOCKER')`.
- `src/Presentation/Controller/TechnicianController.php:287-295`: el Técnico hacía lo mismo con `'TECHNICIAN'`.
- `src/Application/Service/MachineDexAuditService.php:492-505`: `listForRole()` consultaba `listForCoordinator(['machine_id' => $machineId, 'status' => 'ACTIVE'])` sin recibir al actor ni comprobar cobertura.
- Las rutas solo llevan el middleware de rol (`restockerAuth`/`technicianAuth`); no había guarda de zona en el controlador.
- La comprobación de perímetro solo existía en la importación, mediante `lockMachineContext()` en `importDex()` (`MachineDexAuditService.php:137`).

### Reproducción original

Antes de la corrección, con un token de Reponedor de la zona A:

```http
GET /api/restocker/machines/<id_de_una_máquina_de_la_zona_B>/dex-audits
Authorization: Bearer <token>
```

La respuesta era `200` con `audit_code`, `va1_total_vend_units`, `selections` y `events` de la máquina ajena. Con un identificador inexistente devolvía `200 []` en lugar de `404`. El mismo comportamiento se reproducía en la ruta del Técnico.

### Impacto

Lectura horizontal de historiales fuera del perímetro. La proyección de rol elimina los euros, pero expone actividad, unidades, selecciones y eventos de cualquier máquina. Contradecía la restricción de que el Reponedor solo opera sobre máquinas cubiertas por sus zonas (y el Técnico sobre su intervención/zona) y el contrato de errores `403`/`404` del endpoint.

### Corrección aplicada

1. `src/Presentation/Controller/RestockerRouteController.php:663-673`: `listDexAudits()` obtiene el actor con `actor($request)` y pasa su `id` a `listForRole()`.
2. `src/Presentation/Controller/TechnicianController.php:287-297`: el Técnico hace lo mismo con `dexActor($request)`.
3. `src/Application/Service/MachineDexAuditService.php:497-565`: `listForRole()` exige `actorId`, valida el rol móvil, resuelve la máquina con `findMachineContext()` y aplica la cobertura antes de consultar el historial. Devuelve `404 MACHINE_NOT_FOUND` para máquina inexistente/inactiva, `403 DEX_ACTOR_INACTIVE` para actor inactivo y `403 MACHINE_OUTSIDE_RESTOCKER_ZONES`/`MACHINE_OUTSIDE_TECHNICIAN_SCOPE` fuera del perímetro.
4. `src/Core/Domain/Repository/MachineDexAuditGatewayInterface.php:36-44` y `src/Infrastructure/Repository/PdoMachineDexAuditGateway.php:44-64`: nuevo `findMachineContext()` sin `FOR UPDATE`; `lockMachineContext()` conserva el bloqueo exclusivo para la importación. Ambos comparten la misma fotografía de cobertura.
5. `tests/integration/DexAuditApiTest.php:154` crea una zona, sede y máquina vivas fuera del perímetro; `:326-347` certifica `403` para Reponedor y Técnico y `404` para una máquina inexistente en ambas puertas.

### Verificación

```text
php tests/unit/MachineDexAuditServiceTest.php       → 30/30 aserciones · exit 0
php tests/integration/DexAuditApiTest.php          → 50/50 aserciones · exit 0
php tests/run_new_engine.php DexAudit              → 7/7 suites · 176 aserciones · exit 0
```

---

## 4. `PR25-02` · El `FOR UPDATE` no serializaba el cálculo de deltas

**Prioridad:** P1 · **Requisitos afectados:** RF-04.1, EARS 03.1.1 (`SELECT ... FOR UPDATE` para serializar concurrencia e historial de deltas).

**Estado:** ✅ **Cerrado (2026-11-27)** · transacción de caso de uso completa, `SAVEPOINT` en el alta y carrera real de dos DEX válidos en `DexAuditConcurrencyTest` 4.1–4.3.

### Evidencia original

- `src/Application/Service/MachineDexAuditService.php:137`: `importDex()` llama a `lockMachineContext()`.
- `src/Application/Service/MachineDexAuditService.php:224-226`: después lee `findPreviousActive()` y `hasLaterActive()`.
- `src/Application/Service/MachineDexAuditService.php:252`: reserva el `audit_code` fuera de cualquier transacción explícita.
- `src/Infrastructure/Repository/PdoMachineDexAuditRepository.php:133-138`: `create()` abre su propia transacción, ya tarde para el bloqueo y las lecturas.
- `src/Infrastructure/Repository/PdoMachineDexAuditGateway.php:117`: el `FOR UPDATE OF m` solo es efectivo mientras viva la transacción que lo ejecuta.

PDO trabaja en autocommit: la transacción implícita del `SELECT ... FOR UPDATE` termina al finalizar la sentencia y el bloqueo se libera. No existe un `TransactionManager` que envuelva `lockMachineContext → lecturas → reserva → insert`; el controlador no abre transacción.

### Reproducción original

Dos `POST` simultáneos sobre la misma máquina con payloads válidos distintos —hashes SHA-256 distintos y `client_mutation_id` distintos— pueden leer el mismo `previous_audit_id` antes de que cualquiera inserte. Ambos crean un volcado `ACTIVE` con el mismo predecesor; no hay restricción SQL que impida dos “hermanos” activos.

La suite existente `tests/integration/DexAuditConcurrencyTest.php` competía por el mismo hash y por la misma mutación, pero no por dos volcados válidos diferentes; por eso no detectaba la carrera de deltas.

### Impacto

La cadena de deltas puede duplicar ventas o unidades y uno de los dos volcados queda como rama huérfana. El comentario de la spec sobre serialización y el propio DocBlock del gateway no se cumplen. Es un fallo de integridad difícil de detectar después, porque ambos volcados son válidos individualmente.

### Corrección aplicada

1. `src/Application/Service/MachineDexAuditService.php`: el constructor recibe `TransactionManagerInterface` y `importDex()` delega el caso de uso completo en `importDexAtomically()` dentro de `runInTransaction()`. La transacción cubre el bloqueo `FOR UPDATE` de la máquina, las lecturas de predecesor/posterior, la reserva del correlativo, el `INSERT`, las líneas, la auditoría y el outbox.
2. `src/Infrastructure/Repository/PdoMachineDexAuditRepository.php`: `create()` detecta si ya existe una transacción exterior. Si es el dueño, abre y confirma la suya; si no, crea un `SAVEPOINT`, libera el savepoint al terminar o hace `ROLLBACK TO SAVEPOINT` ante error. Así el reintento de `DEX_DUPLICATE_AUDIT_CODE` no invalida la transacción de PostgreSQL ni pierde el `FOR UPDATE`.
3. Los tres controladores de importación y los actores de prueba pasan `new PdoTransactionManager($pdo)` con el mismo PDO que usan el repositorio, el gateway, el logger y el outbox. No se cambió el DDL ni el contrato de las rutas.
4. El replay de `client_mutation_id` comprueba ahora que la huella SHA-256 coincide. Un reintento idéntico sigue devolviendo `200 idempotent_replay = true`; reutilizar la mutación con otro contenido responde `409 CLIENT_MUTATION_ID_ALREADY_USED`.
5. `tests/integration/DexAuditConcurrencyTest.php` añade la sección 4: dos procesos con DEX válidos distintos, mismo instante de captura y mutaciones distintas. Ambos se persisten y el segundo enlaza al primero; sus deltas se calculan sobre esa cadena, no sobre un predecesor compartido.
6. `tests/unit/MachineDexAuditServiceTest.php` incorpora un doble de `TransactionManagerInterface`, verifica que el alta se delega a la unidad de trabajo y cubre el replay exacto y el conflicto por contenido distinto.

### Verificación

```text
php tests/unit/MachineDexAuditServiceTest.php             → 32/32 aserciones · exit 0
php tests/integration/DexAuditApiTest.php                → 50/50 aserciones · exit 0
php tests/integration/DexAuditConcurrencyTest.php        → 9/9 aserciones · exit 0
php tests/integration/DexAuditPerformanceTest.php        → 3/3 aserciones · exit 0 · p95 193,3 ms
php tests/run_new_engine.php DexAudit                    → 7/7 suites · 179 aserciones · exit 0
```

---

## 5. `PR25-03` · `expected_machine_code` permitía ocultar un DEX de otra máquina

**Prioridad:** P2 · **Requisitos afectados:** D3 (identidad `ID1`), RF-02.

**Estado:** ✅ **Cerrado (2026-11-27)** · la identidad se contrasta siempre contra `machines.code`; el código esperado del cliente se ignora.

### Evidencia original

- `src/Application/Service/MachineDexAuditService.php:176`:

```php
$parsed = $this->parser->parse(
    $rawPayload,
    (string)($request['expected_machine_code'] ?? ($context['machine_code'] ?? ''))
);
```

- La spec D3 (`specs/39-eva-dts-dex-audit/spec.md:151`) exige comparar el identificador `ID1` contra `machines.code` normalizado.
- Los controladores reenvían `expected_machine_code` desde el cuerpo de la petición (`RestockerRouteController.php:1490`, `TechnicianController.php:376`, `CoordinatorDexAuditController.php:196`) sin contrastarlo con la máquina resuelta.

### Reproducción original

Enviar a la máquina A un DEX cuyo `ID1` corresponde a la máquina B, incluyendo `expected_machine_code` con el código de B. El parser considera que la identidad coincide y persiste `identity_mismatch_warning = false`, aunque el DEX pertenezca a otra máquina.

### Impacto

Se suprime la única señal de identidad del volcado y se permite atribuir contadores de ventas de una máquina a otra. El histórico de A queda contaminado y la discrepancia no es visible en la consola de Coordinación. Aunque el aviso no bloquea por diseño, debe calcularse siempre contra la máquina real.

### Corrección aplicada

1. `src/Application/Service/MachineDexAuditService.php`: `importDexAtomically()` pasa a `EvaDtsParser::parse()` el `machine_code` de la máquina resuelta por `lockMachineContext()`, no el campo del cliente. La comparación normalizada de `ID1` queda así anclada al dato de servidor.
2. Los tres controladores dejan de reenviar `expected_machine_code` en el payload de importación. Si un cliente lo envía, se ignora; no puede desactivar el aviso de identidad.
3. `tests/unit/MachineDexAuditServiceTest.php` 1.11 importa un DEX con `ID1` ajeno y un `expected_machine_code` falsificado; exige `identity_mismatch_warning = true` y que el volcado quede en la máquina resuelta.
4. `tests/integration/DexAuditApiTest.php` 2.6 reproduce la suplantación por HTTP contra una tercera máquina de la misma sede y exige `201`, `machine_id` correcto y `identity_mismatch_warning = true`.

### Verificación

```text
php tests/unit/MachineDexAuditServiceTest.php             → 33/33 aserciones · exit 0
php tests/integration/DexAuditApiTest.php                → 51/51 aserciones · exit 0
php tests/run_new_engine.php DexAudit                    → 7/7 suites · 180 aserciones · exit 0
```

---

## 6. `PR25-04` · `captured_at` sin validar: 500, fechas futuras y desfase horario

**Prioridad:** P2 · **Requisitos afectados:** RNF-04, RF-04.1, contrato de errores de importación.

**Estado:** ✅ **Cerrado (2026-11-27)** · validación única en el servicio, normalización a UTC, tolerancia futura de 300 s, `422` tipificado y defensa `Throwable` en las puertas móviles.

### Evidencia

- Los tres controladores aceptaban `captured_at` del cuerpo sin validar formato ni ventana temporal.
- `MachineDexAuditService::importDexAtomically()` pasaba el valor crudo a `findPreviousActive()`/`hasLaterActive()`, que lo castean a `timestamptz` en SQL (`PdoMachineDexAuditRepository.php`). Una cadena no interpretable lanzaba `PDOException`.
- `new DateTimeImmutable($capturedAt)` tampoco estaba protegido.
- `runDexOperation()` de las puertas móviles solo capturaba excepciones de dominio e `InvalidArgumentException`; no había `catch (Throwable)`. El 500 resultante se servía como error interno.
- `public/assets/js/components/CoordinatorDexAuditsSubTab.js` usa `datetime-local`, que produce `YYYY-MM-DDTHH:mm` sin zona. PostgreSQL lo interpretaba en la zona de sesión (normalmente UTC en Supabase), no en `Europe/Madrid`.

### Reproducción

1. `POST /api/restocker/machines/{id}/dex-audits` con `"captured_at": "no-es-una-fecha"` → 500 en lugar de 422.
2. La misma petición con `"captured_at": "2099-01-01T00:00:00+00:00"` se aceptaba; el volcado quedaba como el más reciente y alteraba el orden y los deltas posteriores.
3. En Coordinación, una captura local de las 23:30 se almacenaba como 23:30 UTC (o la zona que tuviera la sesión de PostgreSQL), desplazando 1–2 horas el día civil usado por M34/M35 y los filtros.

### Impacto

Un cliente defectuoso o malicioso podía provocar un 500, insertar un volcado con fecha futura y desordenar la cadena de deltas, o desplazar el día operativo de una carga manual. La spec RNF-04 exige fechas operativas en `Europe/Madrid`; el runtime no fija `date_default_timezone_set()` en producción y la conexión no configura la zona de sesión.

### Corrección aplicada

1. `MachineDexAuditService::normalizeCapturedAt()` se ejecuta una sola vez, antes de abrir la transacción y de cualquier SQL. Acepta ISO 8601 con offset/Z (`Y-m-d\TH:i:sP` y variantes con milisegundos, `O`, espacio) y formatos locales sin zona (`Y-m-d\TH:i`, `Y-m-d H:i:s`, etc.). Los formatos se validan con `DateTimeImmutable::createFromFormat()` y se rechazan fechas imposibles como `2026-02-30`.
2. Los formatos sin offset se interpretan en `Europe/Madrid`, que es el contrato del `datetime-local` de Coordinación. Los formatos con offset/Z conservan el instante recibido. El resultado se normaliza siempre a `Y-m-d\TH:i:sP` en UTC antes de `findPreviousActive()`, `hasLaterActive()`, `reserveAuditCode()` y la entidad.
3. `CAPTURED_AT_MAX_FUTURE_SECONDS = 300` limita el desfase de reloj móvil y rechaza cualquier captura posterior a ese margen. El error es `422 DEX_CAPTURED_AT_IN_FUTURE` con `field`, `value` y `max_future_seconds`.
4. Un formato no interpretable responde `422 DEX_INVALID_CAPTURED_AT` con `field`, `value` y `timezone = Europe/Madrid`, sin llegar a PostgreSQL.
5. `runDexOperation()` de Reponedor y Técnico añade un `catch (Throwable)` que registra el fallo y devuelve `INTERNAL_SERVER_ERROR` controlado; Coordinación ya lo tenía.
6. La regresión añade las unitarias 1.12–1.14 y las HTTP 2.7–2.9: formato inválido, fecha futura, ISO 8601 móvil con `Z` y milisegundos, y una captura de Coordinación a las 23:30 que debe persistirse como las 22:30 UTC.

### Verificación

```text
php tests/unit/MachineDexAuditServiceTest.php             → 36/36 aserciones · exit 0
php tests/integration/DexAuditApiTest.php                → 54/54 aserciones · exit 0
php tests/run_new_engine.php DexAudit                    → 7/7 suites · 183 aserciones · exit 0
```

La prueba HTTP 2.9 compara la época Unix persistida con la de `2026-11-24T23:30:00` en `Europe/Madrid`; no depende de la zona de sesión de PostgreSQL. `php -l` y `git diff --check` también cierran sin errores.

---

## 7. `PR25-05` · Vínculos M34/M35/M16 sin validar y violaciones únicas no traducidas

**Prioridad:** P2 · **Requisitos afectados:** D1, RF-04.3, QA-5, contrato de vínculos.

**Estado:** ✅ **Cerrado (2026-11-27)** · cerrojos de vínculo dentro de la transacción, propiedad/estado validados, `23503` y unicidad de visita/recaudación traducidos a errores de negocio.

### Evidencia

- `src/Application/Service/MachineDexAuditService.php:289-291`: los vínculos se aceptan con un simple cast:

```php
restockVisitId: isset($request['restock_visit_id']) ? (int)$request['restock_visit_id'] : null,
cashCollectionId: isset($request['cash_collection_id']) ? (int)$request['cash_collection_id'] : null,
incidentId: isset($request['incident_id']) ? (int)$request['incident_id'] : null
```

- `src/Infrastructure/Repository/PdoMachineDexAuditGateway.php:155`, `:185` y `:217`: existen `lockRestockVisit()`, `lockCashCollection()` y `lockIncident()`, que comprueban la máquina, pero la importación no los invoca; solo se usan en pruebas del gateway.
- `database/supabase_migrations/035_eva_dts_dex_audit.sql:283-290`: los índices únicos parciales de visita y recaudación permiten un solo volcado `ACTIVE` por vínculo.
- `src/Infrastructure/Repository/PdoMachineDexAuditRepository.php:975-1012`: `translateUniqueViolation()` no contempla `uq_machine_dex_audits_visit` ni `uq_machine_dex_audits_cash_collection`; tampoco traduce las violaciones de clave foránea (`23503`).

### Reproducción

1. `POST` a la máquina A con `restock_visit_id` de una visita de la máquina B → se acepta (201). El cruce M34 de Coordinación filtra por `machine_id`, así que devuelve `null`; además, el índice único puede impedir que el DEX legítimo de B se enlace a esa visita.
2. `POST` con `restock_visit_id: 999999` → violación de FK no traducida → 500.
3. Un segundo DEX que intente enlazar la misma visita/recaudación → `23505` sobre el índice único no contemplado → 500; la cola móvil lo trata como fallo transitorio y reintenta sin resolverlo.

### Impacto

Se pueden crear vínculos cruzados entre máquinas y operaciones, dejar cruces M34/M35 en `null` y bloquear el vínculo legítimo de otra máquina. Los 500 se confunden con fallos de red/servidor y atascan la cola offline, en lugar de aislarse como conflicto de negocio.

### Corrección aplicada

1. `MachineDexAuditService::resolveLinkTargets()` se ejecuta dentro de la transacción del alta, tras el perímetro y la primacía sanitaria y antes de parsear el fichero. Lee los tres vínculos con `optionalLinkId()` —ausente o vacío es `null`; cualquier otro valor debe ser un entero positivo (`422 DEX_LINK_ID_INVALID`)— y los bloquea con `lockRestockVisit()`, `lockCashCollection()` e `lockIncident()`. El `FOR UPDATE` del gateway impide que el destino desaparezca o cambie de máquina entre la comprobación y el `INSERT`.
2. La visita debe existir y pertenecer a la máquina (`422 DEX_LINK_TARGET_NOT_FOUND`) y estar `COMPLETED`; si no, `409 DEX_LINK_TARGET_NOT_ELIGIBLE`. En la puerta del Reponedor, la visita y la recaudación deben ser del propio actor; Técnico y Coordinación conservan el acceso forense. Los tres vínculos resueltos se pasan a la entidad, de modo que el alta persiste exactamente lo validado.
3. La incidencia debe existir, pertenecer a la máquina y no estar borrada: `lockIncident()` añade `deleted_at IS NULL`. Una incidencia ajena o inexistente responde `422 DEX_LINK_TARGET_NOT_FOUND`.
4. `PdoMachineDexAuditRepository::translateConstraintViolation()` —antes `translateUniqueViolation()`— traduce las violaciones `23503` de `fk_machine_dex_audits_restock_visit`, `fk_machine_dex_audits_cash_collection` y `fk_machine_dex_audits_incident` a `422 DEX_LINK_TARGET_NOT_FOUND`, y las claves únicas `uq_machine_dex_audits_visit`/`uq_machine_dex_audits_cash_collection` a `409 DEX_AUDIT_ALREADY_LINKED` con el campo culpable. La cola Offline recibe un conflicto de negocio que aísla, no un 500 que reintenta.
5. La regresión añade las unitarias 6.1–6.9 (vínculo inexistente, de otra máquina, visita no completada, visita/recaudación de otro Reponedor, identificador no numérico, persistencia de los tres vínculos y acceso de Coordinación) y las HTTP 2.10–2.13 (vínculo inexistente, de otra máquina, visita ya vinculada y recaudación ya vinculada).

### Verificación

```text
php tests/unit/MachineDexAuditServiceTest.php             → 45/45 aserciones · exit 0
php tests/integration/DexAuditApiTest.php                → 58/58 aserciones · exit 0
php tests/run_new_engine.php DexAudit                    → 7/7 suites · 187 aserciones · exit 0
```

Las pruebas HTTP 2.12 y 2.13 llegan al `INSERT` real y certifican la traducción de `23505`; 2.11 usa una visita `COMPLETED` de otra máquina de la misma sede. `php -l` y `git diff --check` también cierran sin errores.

---

## 8. `PR25-06` · `conflict-dump` no comprueba existencia ni perímetro de la máquina

**Prioridad:** P2 · **Requisitos afectados:** contrato de `POST /api/{role}/dex-audits/conflict-dump` (`403`, `404`, `422`), Art. III.3.

### Evidencia

- `src/Presentation/Controller/RestockerRouteController.php:676-698`: solo valida `machine_id > 0` y `reason` no vacío. No resuelve la máquina ni comprueba cobertura.
- `src/Presentation/Controller/TechnicianController.php:300-321`: mismo patrón.
- La tabla de endpoints de la spec (`specs/39-eva-dts-dex-audit/spec.md:275-276`) declara `403`, `404` y `422` para estas rutas y exige que el evento quede bajo la entidad `MACHINE`.
- `src/Presentation/Controller/RestockerRouteController.php:687` usa `(new AuditLogger())` en lugar del logger inyectado.

### Reproducción

Con un token de Reponedor, enviar `machine_id = 999999` o una máquina de otra zona:

```http
POST /api/restocker/dex-audits/conflict-dump
{
  "machine_id": 999999,
  "reason": "Conflicto de prueba",
  "client_mutation_id": "dex-conflict-0001"
}
```

Responde `200 CONFLICT_DUMPED` y escribe en `audit_log` para una máquina inexistente o ajena. No se devuelve `404`/`403`.

### Impacto

Cualquier operador autenticado puede generar ruido o entradas falsas en el visor de auditoría de una máquina que no le corresponde. Además, el uso de `new AuditLogger()` rompe la inyección: en pruebas o con un `$pdo`/transacción distinta, el evento se escribe por otra conexión.

### Corrección propuesta

Reutilizar la resolución de máquina y el guard de perímetro de la importación; devolver `404 MACHINE_NOT_FOUND` o `403 MACHINE_OUTSIDE_*`; validar el motivo con las cotas del contrato y usar el `AuditLogger` inyectado. Añadir pruebas de máquina inexistente y fuera de zona.

---

## 9. `PR25-07` · La cola `.dex` no se limpia al cerrar sesión

**Prioridad:** P2 · **Requisitos afectados:** C5/D6, privacidad y retención local, contrato declarado de `DexAuditOfflineQueue`.

### Evidencia

- `public/assets/js/utils/DexAuditOfflineQueue.js:541`: existe `clear(actorId)` y su DocBlock lo define para “logout o cambio de cuenta”.
- `public/assets/js/components/RestockerRouteView.js:3781-3804`: `handleLogout()` vacía la cola de overrides de ruta, pero nunca llama a `ensureDexQueue().clear()`.
- `public/assets/js/views/TechnicianRouteView.js:2729-2758`: el logout bloquea si hay operaciones en la cola general y limpia `offlineStorage`, pero no la cola `.dex`; una importación `.dex` pendiente ni siquiera entra en ese bloqueo.
- `public/assets/js/store.js:157-170`: `clearSession()` solo limpia el estado y `localStorage`; no purga IndexedDB.

### Impacto

El `.dex` crudo, la captura y los vínculos locales permanecen en `vendguard-dex-audits` después de cerrar sesión. En un dispositivo compartido, el siguiente operador o cualquier script del mismo origen puede leer datos operativos del anterior. Incumple la intención declarada de la cola y añade retención no controlada.

### Corrección propuesta

En ambos logout, limpiar `ensureDexQueue().clear(actorId)` después de la política de volcado/aislamiento de conflictos; si se quiere evitar pérdida, bloquear el cierre mientras haya pendientes, como ya se hace con la cola general del Técnico. Añadir una prueba con IndexedDB simulada que compruebe que no quedan registros del actor.

---

## 10. `PR25-08` · El correlativo anual se bloquea al pasar de 9.999

**Prioridad:** P3 · **Requisitos afectados:** D3, formato `DEX-AAAA-NNNN`, generación de `audit_code`.

### Evidencia

- `src/Infrastructure/Repository/PdoMachineDexAuditRepository.php:109-130`: `reserveAuditCode()` usa `SELECT MAX(audit_code) ... LIKE 'DEX-2026-%'` y calcula el siguiente con `substr(...) + 1`.
- `DEX-2026-9999` es textualmente mayor que `DEX-2026-10000`, porque `'9' > '1'`. Una vez existe `9999`, el `MAX` sigue devolviendo `9999` aunque ya exista `10000`.
- La restricción `chk_machine_dex_audits_code_format` admite cuatro o más dígitos, por lo que `10000` es válido; el problema es solo la selección del máximo.
- `DexAuditApiTest`/`PdoMachineDexAuditRepositoryTest` prueban el primer código y `DEX-2026-9999`, pero no la transición `9999 → 10000 → 10001`.

### Impacto

La importación 10.000 puede insertar `10000`; a partir de la 10.001, todas las reservas vuelven a proponer `10000`, el índice único lo rechaza, el reintento de cinco intentos repite el mismo código y la operación termina en `409 DEX_DUPLICATE_AUDIT_CODE`. Las altas quedan bloqueadas hasta el 1 de enero siguiente. Con varias centenas de máquinas y volcados semanales es un escenario realista.

### Corrección propuesta

Calcular el máximo numérico del sufijo (`MAX(CAST(SUBSTRING(audit_code FROM ...) AS INTEGER))`), usar una secuencia por año o un contador persistido. Añadir una prueba que inserte `9999`, `10000` y `10001` y verifique la secuencia.

---

## 11. `PR25-09` · La consola de Coordinación no pagina y no ofrece todos los filtros

**Prioridad:** P3 · **Requisitos afectados:** RF-07.1, contrato de `GET /api/coordinator/dex-audits`.

### Evidencia

- `public/assets/js/components/CoordinatorDexAuditsSubTab.js:245-263`: `loadAudits()` llama a `getDexAudits(this.buildFilterParams())` sin `limit` ni `offset`; no hay paginador ni total.
- El repositorio devuelve como máximo 100 filas por defecto (`listForCoordinator`, `PdoMachineDexAuditRepository.php:454-497`) y la vista no indica que existan más.
- La spec RF-07.1 exige filtros por zona (`zone_id`) y sede (`location_id`); la vista solo ofrece fecha, estado, puerta, máquina y “solo con alertas” (`CoordinatorDexAuditsSubTab.js:456-510`).
- El CSV corta silenciosamente en `MAX_CSV_ROWS = 500` (`MachineDexAuditService.php:73` y `:435`), sin aviso al usuario.

### Impacto

Con más de 100 volcados, los antiguos solo aparecen si el usuario acierta a filtrar por máquina o fecha. La consola no cumple el contrato de listado paginado ni permite el perímetro por zona/sede; el CSV puede quedar incompleto sin que el usuario lo sepa.

### Corrección propuesta

Añadir paginación real o “cargar más” con total, exponer los filtros `zone_id`/`location_id` y devolver/avisar cuándo el CSV alcanza el tope (o exportar sin `LIMIT` con una consulta dedicada). Añadir pruebas de más de 100 filas y de los filtros de zona/sede.

---

## 12. Cobertura de pruebas y límites de esta revisión

### Lo que sí está cubierto

- RBAC de las tres puertas (401/403 cruzados) en `DexAuditApiTest`.
- Perímetro del historial móvil: `403 MACHINE_OUTSIDE_RESTOCKER_ZONES` y `MACHINE_OUTSIDE_TECHNICIAN_SCOPE`, y `404 MACHINE_NOT_FOUND` en las pruebas 1.5–1.8 (`PR25-01`).
- Importación de Reponedor/Técnico/Coordinación, `prefill` inmediato solo en el `POST` del Reponedor y ausencia de euros en el `GET` histórico.
- `/raw` con `text/plain`, `nosniff` y nombre `DEX-AAAA-NNNN.dex`.
- Anulación terminal, CSV con BOM/neutralización y firma en `audit_log`.
- Cruces M34/M35 del detalle de Coordinación y delta efectivo tras `VOIDED`.
- Concurrencia por misma huella, misma `client_mutation_id` y dos DEX válidos distintos con cadena de deltas serializada (`PR25-02`).
- Replay exacto idempotente y rechazo `409` de una mutación reutilizada con contenido distinto.
- Identidad `ID1` contrastada contra la máquina resuelta aunque el cliente falsifique `expected_machine_code` (`PR25-03`, unitaria 1.11 y HTTP 2.6).
- Validación y normalización de `captured_at` (`PR25-04`, unitarias 1.12–1.14 y HTTP 2.7–2.9): formato inválido, fecha futura, ISO 8601 móvil con `Z` y milisegundos, y captura local de Coordinación interpretada en `Europe/Madrid`.
- Vínculos M34/M35/M16 (`PR25-05`, unitarias 6.1–6.9 y HTTP 2.10–2.13): inexistente, de otra máquina, visita no completada, visita/recaudación de otro Reponedor, identificador no numérico, persistencia de los tres vínculos y ocupación única de visita/recaudación traducida a `409`.
- Parser, esquema, convergencia y rendimiento del canal `new_engine`.

### Lo que no cubre la suite existente

- `conflict-dump` con máquina inexistente/fuera de zona (`PR25-06`).
- Limpieza de IndexedDB al cerrar sesión (`PR25-07`).
- Transición del correlativo `9999 → 10000 → 10001` (`PR25-08`).
- Paginación y filtros de zona/sede de la consola (`PR25-09`).

### Límites

La revisión original fue **estática** sobre el diff y el código de producción del Módulo 39. `PR25-01` se verificó con `DexAuditApiTest`, `PR25-02` con la carrera real de `DexAuditConcurrencyTest`, `PR25-03` con la regresión unitaria 1.11 y la HTTP 2.6, `PR25-04` con las unitarias 1.12–1.14 y las HTTP 2.7–2.9, y `PR25-05` con las unitarias 6.1–6.9 y las HTTP 2.10–2.13, todos contra PostgreSQL aislado; los cuatro hallazgos restantes siguen siendo diagnóstico estático y no se explotaron de nuevo en esta pasada. No se revisaron línea a línea los 101 archivos del diff (tests, documentación y capturas). La PR no fue fusionada.
