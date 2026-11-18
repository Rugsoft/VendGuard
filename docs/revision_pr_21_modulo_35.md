# Revisión de la PR #21 · Hallazgos del Módulo 35

- **PR:** [#21](https://github.com/Rugsoft/VendGuard/pull/21) · `feat(cash-collection): recaudación de efectivo, tubos de cambio y arqueo en cámara (Módulo 35)`
- **Rama:** `feat/cash-collection-vault-audit` → `main`
- **HEAD revisado:** `09d0f3f` · **Base:** `47ba599` (sin commits nuevos en `main` desde la bifurcación)
- **Fecha de la revisión:** 2026-11-17
- **Alcance:** revisión estática del diff completo (76 archivos, +20.345/−155): migración 031 y convergencia con `supabase_init.sql`, dominio, servicio y repositorios, controladores y RBAC, proyección móvil, cola offline, exportación CSV y coherencia documental. Contraste con la spec v2.1, los contratos técnicos y las suites del módulo.
- **Estado:** PR `MERGEABLE`/`CLEAN`. **PR-01 a PR-10 corregidos** (PR-01 a PR-04 el 2026-11-17; PR-05 a PR-10 el 2026-11-18) con sus pruebas de regresión cuando aplican; queda **1 hallazgo abierto** (de prioridad baja).

---

## Resultado general

El núcleo de custodia está bien construido: la migración declara la Tabla 49 con sus ocho `CHECK`, los índices únicos parciales y los tres disparadores; el servicio reserva el código correlativo dentro de la transacción; el DTO del reponedor omite estructuralmente los cinco campos del Arqueo Ciego; y las transiciones de cámara se resuelven con `UPDATE` condicionado y bloqueo de fila, sin carreras en el arqueo concurrente.

Los defectos se concentran en los bordes del contrato: la exportación financiera, la vía de alta independiente, la validación de la marca temporal del corte y el tratamiento de errores de unicidad y de la cola offline. Las dos primeras deberían resolverse antes de fusionar.

### Resumen ordenado por importancia

| # | ID | Prioridad | Hallazgo | Impacto | Estado |
|---:|:---:|:---:|---|---|---|
| 1 | `PR-01` | **Alta** | La exportación CSV se corta en 100 filas sin avisar | Pérdida silenciosa de datos en el libro contable exportado | Corregido (2026-11-17) |
| 2 | `PR-02` | **Alta** | La vía independiente acepta `restock_visit_id` de cualquier visita y de cualquier reponedor | Vínculo cruzado fuera de spec, bloqueo del índice único y 500 al dueño legítimo | Corregido (2026-11-17) |
| 3 | `PR-03` | Media | `collected_at` viaja del cliente sin validar formato ni ventana temporal | 500 ante un valor malformado y alteración de la cadena de contador y del esperado | Corregido (2026-11-17) |
| 4 | `PR-04` | Media | Las carreras de unicidad (precinto o mutación) acaban en 500 | Un conflicto de negocio se presenta como fallo interno y no se reintenta como replay | Corregido (2026-11-17) |
| 5 | `PR-05` | Media | La cola offline marca como conflicto irreversible cualquier 401, 403 o 5xx | Capturas válidas quedan bloqueadas tras caducar la sesión o un fallo transitorio | Corregido (2026-11-18) |
| 6 | `PR-06` | Baja | El replay idempotente no está acotado por máquina | Un identificador reutilizado devuelve otra bolsa con 200 y pierde la captura | Corregido (2026-11-18) |
| 7 | `PR-07` | Baja | El KPI de bolsas pendientes del reponedor ignora los filtros activos | La tarjeta discrepa del listado filtrado | Corregido (2026-11-18) |
| 8 | `PR-08` | Baja | `dumpConflict` admite cualquier `machine_id` y texto libre sin comprobar pertenencia | Ruido y posible spoofing en `audit_log` | Corregido (2026-11-18) |
| 9 | `PR-09` | Baja | Dos comentarios de la migración 031 describen el código como `MCC-AAAA-NNNNNN` | Deriva documental frente al `CHECK`, el modelo y la spec (`NNNN`) | Corregido (2026-11-18) |
| 10 | `PR-10` | Baja | La entrada del CHANGELOG cita un estado y un índice incorrectos | Documentación de release inexacta | Corregido (2026-11-18) |
| 11 | `PR-11` | Baja | El año del correlativo depende de la zona horaria del proceso PHP | En un servidor en UTC, el 1 de enero puede emitir el año anterior | Abierto |

---

## 1. `PR-01` · La exportación CSV se corta en 100 filas

**Prioridad:** Alta · **Requisito afectado:** `QA 4`, contrato de `GET /api/coordinator/cash-collections/export-csv`.

**Evidencia**

- `src/Application/Service/MachineCashCollectionService.php:648-652`: `exportCsv()` llama a `listForCoordinator($filters)` sin paginar ni sobrescribir el límite.
- `src/Infrastructure/Repository/PdoMachineCashCollectionRepository.php:48` y `:343`: `DEFAULT_LIMIT = 100` y `LIMIT :limit OFFSET :offset` en la consulta del libro.
- `specs/technical/cash_collection_vault_audit_contracts.md:26`: el contrato promete «el listado filtrado», sin tope declarado.
- Ninguna prueba del módulo inserta más de 100 filas ni cubre el caso.

**Impacto**

Con más de 100 bolsas en el filtro (un mes normal de un parque mediano), el CSV sale incompleto y la consola anuncia «Exportación CSV generada con los filtros activos». El usuario no tiene forma de detectar el truncamiento. Los KPIs de la misma llamada se calculan sobre todo el filtro, así que pueden no cuadrar con las filas del fichero.

**Corrección propuesta**

Exportar con una consulta dedicada sin `LIMIT` (o un bucle de páginas con `MAX_LIMIT` y `OFFSET`) y mantener el `LIMIT` solo para la consola. Añadir una prueba de integración con 101+ filas que cuente las líneas del CSV y verifique el BOM y la cabecera.

**Corrección aplicada (2026-11-17)**

`MachineCashCollectionRepositoryInterface::listAllForCoordinator()` entrega el libro filtrado sin `LIMIT` ni `OFFSET`; `MachineCashCollectionService::exportCsv()` lo consume y la consola mantiene su tope de página. La prueba `5.17` de `CashCollectionVaultAuditApiTest` inserta 105 filas y comprueba que el CSV devuelve las 105, incluidas `MCC-2026-8001` y `MCC-2026-8105`.

---

## 2. `PR-02` · La vía independiente acepta visitas ajenas y no completadas

**Prioridad:** Alta · **Requisito afectado:** `D1`, `RF-01.1`, contrato del `POST` de recaudación independiente.

**Evidencia**

- `specs/35-cash-collection-vault-audit/spec.md:36`: la vía integrada solo existe «cuando `status = 'COMPLETED'`»; la vía independiente se define con `restock_visit_id = NULL` (`spec.md:37` y `contracts:20`).
- `src/Application/Service/MachineCashCollectionService.php:157-170`: el servicio toma `restock_visit_id` del payload, bloquea la visita y solo valida que pertenezca a la máquina.
- `src/Infrastructure/Repository/PdoMachineCashCollectionGateway.php:111-139`: `lockRestockVisit()` devuelve `restocker_id` y `status`, pero el servicio no los compara con el actor.
- `src/Core/Service/MachineCashCollectionValidator.php:115-123`: `assertVisitAllowsCashCollection()` solo rechaza `ABORTED_THERMAL_BREACH`; admite cualquier otro estado.
- `database/supabase_migrations/031_cash_collection_vault_audit.sql`: la guarda sanitaria tampoco exige dueño ni `COMPLETED`.

**Impacto**

Un `RESTOCKER` puede enlazar su captura a la visita de otro reponedor de la misma máquina, o a una visita no completada. El histórico queda cruzado y el índice único parcial `uq_machine_cash_collections_visit` impide después que el dueño legítimo vincule la suya; esa colisión, además, se sirve como 500 (ver `PR-04`).

**Corrección propuesta**

Rechazar `restock_visit_id` en el endpoint independiente (400/422) y, en la vía integrada, validar que `visit.restocker_id === actor.id` y que `status === 'COMPLETED'` antes de insertar. Añadir dos pruebas de regresión: visita de otro reponedor y visita en estado distinto de `COMPLETED`.

**Corrección aplicada (2026-11-17)**

La vía independiente responde `422 RESTOCK_VISIT_NOT_ALLOWED` ante cualquier `restock_visit_id`, sin insertar fila (`3.4g`). La vía integrada valida, tras bloquear la visita, que `restocker_id` coincide con el actor (`403 RESTOCK_VISIT_NOT_OWNED`) y que el estado es `COMPLETED` (`422 RESTOCK_VISIT_NOT_COMPLETED`); ambas guardas se cubren en `MachineCashCollectionServiceTest`, que pasa a 56/56.

---

## 3. `PR-03` · `collected_at` sin validar formato ni ventana temporal

**Prioridad:** Media · **Requisito afectado:** `D2`, `C3`, `Art. V.1`.

**Evidencia**

- `src/Core/Domain/Model/MachineCashCollection.php:271-273`: el constructor solo aplica `trim()` a `collected_at`; el parseo existe para `delivered_to_vault_at` y `vault_reconciled_at`, no para el corte.
- `src/Application/Service/MachineCashCollectionService.php:247-249`: el valor del payload se usa tal cual, o se sustituye por la hora actual solo si viene vacío.
- `src/Infrastructure/Repository/PdoMachineCashCollectionRepository.php:177`: `CAST(:collected_at AS timestamptz)`; un valor no interpretable produce `SQLSTATE 22007`, que el controlador convierte en 500.

**Impacto**

Un cliente con una fecha malformada recibe un 500 en lugar de un 422. Una fecha futura o retrocedida altera `findLastForMachine()` (`ORDER BY collected_at DESC, id DESC`) y, con ello, la lectura previa y el `expected_cashbox_eur` de las capturas siguientes, además de desordenar el libro y el CSV.

**Corrección propuesta**

Parsear con `DateTimeImmutable` en el modelo, devolver `422 VALIDATION_ERROR` con el campo y acotar la ventana (no posterior a «ahora + desfase» y no anterior a la última captura de la máquina o al inicio de la visita). Reflejar la cota en la spec si se adopta.

**Corrección aplicada (2026-11-17)**

`MachineCashCollection` valida el formato con `DateTimeImmutable` y `MachineCashCollectionService::resolveCollectedAt()` responde `422 VALIDATION_ERROR` con `field = collected_at` cuando la marca es ilegible, supera la hora del servidor en más de 5 minutos o retrocede por detrás de la última captura de la máquina. Las regresiones `3.4h`–`3.4j` de `CashCollectionVaultAuditApiTest` (46/46) y `MachineCashCollectionServiceTest` (56/56) comprueban el estado, el campo y que no se inserta fila. La cota queda documentada en el contrato técnico; los criterios de aceptación de la spec no cambian.

---

## 4. `PR-04` · Las carreras de unicidad acaban en 500

**Prioridad:** Media · **Requisito afectado:** `C1`, `D4`, `RF-01.2`.

**Evidencia**

- `src/Application/Service/MachineCashCollectionService.php:131`, `:141` y `:180`: el precinto y el `client_mutation_id` se comprueban antes de insertar.
- `src/Infrastructure/Repository/PdoMachineCashCollectionRepository.php:130-218`: el `INSERT` no traduce `SQLSTATE 23505`.
- `src/Presentation/Controller/RestockerRouteController.php:655-676`: `executeCashCollection()` convierte cualquier `Throwable` en `INTERNAL_SERVER_ERROR`.

**Impacto**

Dos dispositivos con el mismo precinto, o el mismo `client_mutation_id` en máquinas distintas, superan la comprobación previa y compiten en el índice único. El segundo recibe un 500 en vez de un 409 de negocio o de un replay idempotente, y la cola offline lo archiva como conflicto. La suite de concurrencia solo cubre capturas en máquinas y precintos distintos.

**Corrección propuesta**

Capturar `SQLSTATE 23505` en el servicio: mutación duplicada → devolver la fila existente con `idempotent_replay = true` (200); precinto duplicado → `409` con `INVALID_BAG_SEAL_CODE`. Añadir una prueba de dos procesos con el mismo precinto y otra con la misma mutación.

**Corrección aplicada (2026-11-17)**

El `INSERT` usa `ON CONFLICT DO NOTHING` para no abortar la transacción; si no inserta, el repositorio localiza la fila ganadora y lanza `MachineCashCollectionUniqueViolationException`. El servicio devuelve esa fila con `idempotent_replay = true` cuando la mutación ya existía y `409 BAG_SEAL_CODE_ALREADY_USED` cuando el precinto estaba consumido. La suite de concurrencia añade dos carreras reales entre máquinas (`4.1` mutación reutilizada → 409 y `4.2` precinto → 409; 10/10), la unitaria simula ambas pérdidas (56/56) y el repositorio comprueba la traducción.

---

## 5. `PR-05` · La cola offline convierte 401, 403 y 5xx en conflicto irreversible

**Prioridad:** Media · **Requisito afectado:** `RF-05`, `C4`, `D4`.

**Evidencia**

- `public/assets/js/components/RestockerRouteView.js:2094-2125`: en `syncPendingCashCollections()`, todo error que no sea de red ejecuta `queue.markConflict(...)`.
- `public/assets/js/components/RestockerRouteView.js:1112-1117`: `isNetworkFailure()` solo reconoce `navigatorOnline === false`, `status === 0`, `NETWORK_ERROR` y `REQUEST_TIMEOUT`.

**Impacto**

Si el token caducó durante el periodo offline, el primer 401 marca todas las capturas pendientes como conflicto; lo mismo ocurre con un 500 transitorio. El reponedor solo puede archivarlas o volver a introducirlas, aunque el dato seguía siendo válido.

**Corrección propuesta**

Mantener el elemento en `PENDING` ante 401/403 de sesión y 5xx, renovar sesión o reintentar más tarde, y reservar `CONFLICT` para 409/422 de negocio. Añadir pruebas de la cola con 401 y 500 sin consumir la entrada.

**Corrección aplicada (2026-11-18)**

`RestockerRouteView::isRetryableSyncFailure()` clasifica como reintentable la caída de red, el `401`, el `403` de la puerta de sesión (`FORBIDDEN`/`FORBIDDEN_ROLE`) y cualquier `5xx`; ambas colas (visitas y recaudaciones) conservan esas entradas en `PENDING`, detienen la pasada y muestran un motivo que pide renovar la sesión o reintentar más tarde. Los `403` de negocio —máquina fuera de zona, rol sobre la operación o visita ajena— y los `409`/`422` siguen pasando a `CONFLICT` para su volcado a auditoría. La suite de la vista fija el comportamiento con un `401` y un `500` en recaudaciones (dos entradas conservadas y una sola llamada por pasada), un `403` de sesión en visitas y un `403` de negocio que sí se archiva (213/213).

---

## 6. `PR-06` · El replay idempotente no está acotado por máquina

**Prioridad:** Baja · **Requisito afectado:** `D4`.

**Evidencia**

- `src/Application/Service/MachineCashCollectionService.php:131-143`: `findByClientMutationId($restockerId, $mutationId)` se consulta antes y después del bloqueo de máquina, pero la máquina del payload no se compara con la de la fila existente.
- `database/supabase_migrations/031_cash_collection_vault_audit.sql`: la unicidad es `(restocker_id, client_mutation_id)`.

**Impacto**

Si un cliente reutiliza el identificador en otra máquina, recibe un 200 con la recaudación anterior y la captura nueva se pierde sin error. Es un fallo de cliente, pero el servidor lo silencia.

**Corrección propuesta**

Comparar `machine_id` en el replay: si difiere, `409` con un código explícito de mutación reutilizada.

**Corrección aplicada (2026-11-18)**

El replay idempotente queda acotado a la máquina: `MachineCashCollectionService::replayForMachine()` compara `machine_id` y responde `409 CLIENT_MUTATION_ID_ALREADY_USED` si la mutación ya se grabó en otra máquina, tanto en la comprobación previa como tras el bloqueo y en la resolución de la carrera del índice único. Se añadió la frontera a la suite del servicio (60/60, incluido el camino de carrera simulado), a la de API (47/47, con una segunda máquina real) y a la carrera entre máquinas (10/10, `4.1` ahora exige `409` y una sola fila).

---

## 7. `PR-07` · El KPI de bolsas pendientes ignora los filtros

**Prioridad:** Baja · **Requisito afectado:** `QA 3`.

**Evidencia**

- `src/Infrastructure/Repository/PdoMachineCashCollectionRepository.php:393-441`: los totales usan `buildFilterClause($filters)`, pero la consulta de `pending_vault_bags_count` solo filtra por `restocker_id` y `status = 'SEALED_IN_ROUTE'`.

**Impacto**

Con un filtro de máquina, fechas o estado, la tarjeta de pendientes muestra un valor distinto del listado que acompaña.

**Corrección propuesta**

Aplicar el mismo `WHERE` de filtros a la consulta del contador, salvo el estado si el KPI debe contar solo `SEALED_IN_ROUTE`.

**Corrección aplicada (2026-11-18)**

`summarizeForRestocker()` reutiliza el `WHERE` de los totales en el contador de pendientes: aplica máquina, precinto, fechas y estado, y fuerza `SEALED_IN_ROUTE` sobre ese conjunto. La suite del repositorio añade dos aserciones (47/47): una comprueba que el KPI no arrastra bolsas de otra máquina ni de fechas fuera del rango, y otra fija que el filtro de estado acota el KPI (con `RECONCILED_OK` vale 0 y con `SEALED_IN_ROUTE` cuenta la bolsa pendiente de esa máquina y fecha).

---

## 8. `PR-08` · `dumpConflict` admite cualquier máquina y texto libre

**Prioridad:** Baja · **Requisito afectado:** `Art. III.3`, `C4`.

**Evidencia**

- `src/Application/Service/MachineCashCollectionService.php:609-646`: valida que `machine_id` sea positivo, pero no que exista, esté activo ni pertenezca a las zonas del reponedor; `reason`, `bag_seal_code`, `client_mutation_id` y `offline_created_at` se copian sin acotar longitud ni formato.
- El evento se registra con `AuditEvent::ENTITY_MACHINE_CASH_COLLECTION` y el `machine_id` como identificador de entidad.

**Impacto**

Cualquier reponedor puede generar eventos de auditoría con identificadores de máquinas ajenas y texto arbitrario, ensuciando el visor y dificultando la investigación de conflictos reales.

**Corrección propuesta**

Comprobar que la máquina existe y está cubierta por sus zonas; acotar los textos a las longitudes del contrato y validar `offline_created_at` como marca temporal. Si el evento no representa una recaudación concreta, usar la entidad máquina.

**Corrección aplicada (2026-11-18)**

`dumpConflict()` comprueba la máquina con `lockMachineContext()` —`404 MACHINE_NOT_FOUND` si no existe o está inactiva, `403 MACHINE_OUTSIDE_RESTOCKER_ZONES` si no está cubierta—, normaliza el payload en `MachineCashCollectionValidator::normalizeConflictDumpPayload()` (`client_mutation_id` de 6 a 64 caracteres con alfabeto seguro, precinto opcional con el patrón `C1`, `reason` de hasta 500 caracteres y `offline_created_at` interpretable) y audita el hecho bajo `AuditEvent::ENTITY_MACHINE` con el `machine_code`. La suite del servicio añade siete aserciones (67/67) y la de API tres (50/50), cubriendo 404, 403, precinto inválido y los campos `reason`, `client_mutation_id` y `offline_created_at`.

---

## 9. `PR-09` · Comentarios obsoletos del correlativo en la migración

**Prioridad:** Baja · **Requisito afectado:** `RF-01.1`.

**Evidencia**

- `database/supabase_migrations/031_cash_collection_vault_audit.sql:25`: «`collection_code` (`MCC-AAAA-NNNNNN`)».
- `database/supabase_migrations/031_cash_collection_vault_audit.sql:107`: «identidad legible e inmutable (`MCC-AAAA-NNNNNN`)».

**Impacto**

El `CHECK` real es `^MCC-[0-9]{4}-[0-9]{4,}$`, el modelo usa `COLLECTION_CODE_PATTERN` con cuatro o más dígitos y el spec se corrigió a `MCC-AAAA-NNNN`. Los dos comentarios describen un formato de seis dígitos que ya no existe.

**Corrección propuesta**

Sustituir `NNNNNN` por `NNNN` en ambos comentarios, sin tocar SQL.

**Corrección aplicada (2026-11-18)**

Los dos comentarios de `031_cash_collection_vault_audit.sql` describen ahora `MCC-AAAA-NNNN`, sin cambios en el SQL ejecutable. La suite `PostgresCashCollectionVaultAuditSchemaTest` incorpora una guarda de regresión que exige el formato de cuatro dígitos y rechaza la reintroducción de `MCC-AAAA-NNNNNN` (47/47).

---

## 10. `PR-10` · Dos inexactitudes en la entrada del CHANGELOG

**Prioridad:** Baja · **Requisito afectado:** documentación de release.

**Evidencia**

- `CHANGELOG.md:13`: enumera `DEPOSITED_AT_RECEPTION` como valor de `cash_collection_status`; ese estado pertenece a `refund_requests` (`refund_status`). El enum de recaudación tiene cuatro valores: `SEALED_IN_ROUTE`, `DELIVERED_TO_VAULT`, `RECONCILED_OK` y `DISCREPANCY_FLAGGED`.
- La misma línea cita el índice `uq_machine_cash_collections_seal`; su nombre real es `uq_machine_cash_collections_bag_seal`.

**Impacto**

La entrada de release describe un estado y un objeto que no existen con ese nombre. La transición a `DEPOSITED_AT_RECEPTION` sigue siendo correcta en la viñeta siguiente, referida al expediente de reintegro.

**Corrección propuesta**

Quitar `DEPOSITED_AT_RECEPTION` de la lista de valores del enum y corregir el nombre del índice.

**Corrección aplicada (2026-11-18)**

La entrada de release cita ahora el índice real `uq_machine_cash_collections_bag_seal` y enumera solo los cuatro valores de `cash_collection_status`: `SEALED_IN_ROUTE`, `DELIVERED_TO_VAULT`, `RECONCILED_OK` y `DISCREPANCY_FLAGGED`. `DEPOSITED_AT_RECEPTION` se conserva únicamente en la viñeta siguiente, donde describe la transición del expediente de reintegro. No hay cambios de esquema, código ni cifras de suites.

---

## 11. `PR-11` · El año del correlativo depende de la zona horaria del proceso

**Prioridad:** Baja · **Requisito afectado:** `RF-01.1`.

**Evidencia**

- `src/Infrastructure/Repository/PdoMachineCashCollectionRepository.php:107`: `$year = (int)date('Y')`.
- El runtime no fija `date_default_timezone_set()`: solo lo hace `tests/bootstrap.php:118`. En este equipo el proceso está en `Europe/Berlin` (mismo desfase que Madrid), pero la aplicación no lo garantiza.

**Impacto**

En un servidor configurado en UTC, las capturas del 1 de enero entre las 00:00 y las 01:00 o 02:00 de Madrid (según el horario de verano) obtendrían el año anterior en el código. El correlativo seguiría siendo único, pero el prefijo de año no coincidiría con la fecha operativa.

**Corrección propuesta**

Derivar el año con `DateTimeImmutable('now', new DateTimeZone('Europe/Madrid'))` dentro del repositorio, o fijar la zona operativa en el arranque de la aplicación.

---

## Verificado y correcto

- **Integración con `main`:** `MERGEABLE`/`CLEAN`, sin commits nuevos en la base desde la bifurcación y sin archivos de solape; la migración 031 es la siguiente libre tras la 030.
- **Cifras:** batería hermética **436 suites · 16.807 aserciones · 0 fallos** con `DocMetricsGuard` coherente; canal `new_engine` **66/66 · 2.190 aserciones · 0 fallos**. Suites del módulo: API 50/50, constitucional 25/25, concurrencia 10/10, layout 32/32, esquema 47/47, repositorio 47/47, dominio 123/123, validador 114/114 y servicio 67/67.
- **Opacidad:** el DTO del reponedor publica 18 campos y omite las cinco claves del Arqueo Ciego; el recibo de conserjería expone exactamente nueve campos, sin precinto, PIN ni importes calculados.
- **Integridad:** ocho `CHECK`, inmutabilidad por etapas con `45000`, guarda sanitaria en aplicación y motor, reserva del código con cerrojo transaccional y arqueo concurrente protegido por `UPDATE` condicionado.
- **Seguridad:** rutas con `COORDINATOR`/`RESTOCKER`, CSV neutralizado contra fórmulas, sin secretos ni archivos de entorno en el diff.
- **Documentación:** la matriz de trazabilidad actualizó las filas de la suite de API (50/50 y 10/10), del servicio (67/67), del esquema (47/47), del repositorio (47/47) y de la vista móvil (213/213); el `39/39` de `SchemaConvergenceParityTest` quedó intacto.
- **Cota inferior:** se usa la última captura de la máquina; el puerto `lockRestockVisit()` no expone la hora de inicio de la visita, así que no se emplea como cota adicional.

## Límites de esta revisión

- No hay CI configurado en la PR (`statusCheckRollup` vacío): toda la evidencia de pruebas procede de ejecuciones locales.
- La revisión original es estática sobre `09d0f3f`; cada corrección se validó con pruebas reales y la batería se reejecutó con las correcciones aplicadas.
- Las correcciones de `PR-01` a `PR-10` se validaron con pruebas: el CSV exporta 105 filas reales en `CashCollectionVaultAuditApiTest` `5.17`, la vía independiente rechaza la visita en `3.4g`, las fechas y las carreras de unicidad tienen casos dedicados en servicio y concurrencia, la cola offline conserva las capturas ante red, sesión caducada y 5xx (213/213 en la suite de la vista), el replay acotado por máquina responde `409` sin devolver la bolsa ajena, el KPI de pendientes del reponedor respeta máquina, precinto, fechas y estado, contando solo las bolsas `SEALED_IN_ROUTE`, y el volcado de conflicto exige máquina cubierta, textos acotados y una marca temporal interpretable, auditando bajo `MACHINE`, los comentarios de la migración 031 quedan alineados con el correlativo real `MCC-AAAA-NNNN`, y la entrada del CHANGELOG identifica el índice y el enum reales de la Tabla 49.
