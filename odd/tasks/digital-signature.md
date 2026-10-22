# Feature: Firma manuscrita digitalizada y albarán de servicio (Módulo 19)

**Rama:** `digital-signature` · **Entrega:** 5 WU + 1 PR final · **Batería:** `source deploy/local/postgres/.env.battery && php tests/run_all.php` (el motor `new_engine` se ejecuta aparte con `.env.local`).
**Fuente de verdad:** [`specs/19-digital-signature/spec.md`](../../specs/19-digital-signature/spec.md) v2 (Q1–Q8), [`plan.md`](../../specs/19-digital-signature/plan.md), [`tasks.md`](../../specs/19-digital-signature/tasks.md), [`contratos`](../../specs/technical/digital_signature_contracts.md).

## Specs

S1 — **Captura táctil (RF-01.1–01.3).** El modal de resolución de una incidencia `IN_PROGRESS` ofrece «nombre del firmante, documento y trazo sobre `<canvas>`»; el trazo se dibuja con seguimiento por `pointer` con captura, sin scroll de página; «borrar el trazo» limpia el lienzo y descarta el PNG emitido y **no** toca el nombre ni el documento.

S2 — **Validación del firmante (RF-01.4–01.5).** Con firma, sin nombre, sin documento o con trazo sin tinta, el sistema impide el envío y señala el campo. Nombre ≥ 3 caracteres tras recortar espacios; documento normalizado a mayúsculas y «sin ningún espacio» (también los internos), entre 5 y 20 caracteres de `[A-Z0-9-]`.

S3 — **Lienzo opaco y resistente (RF-01.6–01.8).** «menos de 150 píxeles con luminancia inferior a 200 significa «sin tinta»» y la comprobación es **solo de cliente**. Fondo blanco opaco, trazo `#111`. Si el lienzo cambia de tamaño, conserva el trazo; si no puede copiar el mapa de bits, lo limpia y «pide al firmante que vuelva a firmar».

S4 — **Ausencia de responsable (RF-02).** «No había responsable disponible en la sede» con motivo obligatorio ≥ 10 caracteres, texto libre. Las dos vías son mutuamente excluyentes: el sistema rechaza firma y motivo a la vez, o ninguno de los dos.

S5 — **Atomicidad del cierre (RF-03.1, RNF-01).** Una única transacción cubre la línea de cada pieza, el descuento de furgoneta, las solicitudes de repuestos, la transición a `RESOLVED`, el encolado de la notificación y la hoja. «Si cualquier paso falla, **nada** de lo anterior queda escrito».

S6 — **Inmutabilidad (RF-03.2).** La hoja «no admite `UPDATE` ni `DELETE`»; una corrección exige una resolución nueva.

S7 — **Vigencia e histórico (RF-03.3, 03.4, 03.6, RD-11).** Tras reapertura y nuevo cierre se añade una hoja nueva y se conservan las anteriores. El albarán vigente es el de la última resolución; sin hoja en esa resolución muestra «Sin firma del responsable» y **nunca** reutiliza la firma anterior. La hoja registra el técnico que resolvió y se ancla con `resolution_at`.

S8 — **Documento congelado (RF-03.5, RD-10).** `signed_document` congela ticket, fecha de resolución, sede, máquina, técnico, diagnóstico, acción y piezas con nombre y cantidad, a partir de las filas escritas en la misma transacción.

S9 — **Contenido del albarán (RF-04.1).** Ticket y fecha, sede, máquina, técnico, diagnóstico y solución, piezas por **nombre y cantidad** (o «Sin sustitución de componentes»), y bloque de firma con la etiqueta «Firma manuscrita digitalizada» **o** el motivo de ausencia.

S10 — **Privacidad del papel (RF-04.2, RD-04).** Sin costes unitarios ni totales, notas internas, SLA, saldos, otras averías de la sede ni identificadores internos salvo el código de ticket.

S11 — **Proyección viva y errores (RF-04.4–04.6).** Sin hoja, el albarán se compone de datos vivos con «Sin firma del responsable». Incidencia no resuelta y sin hoja → `INCIDENT_NOT_RESOLVED`. Hoja de una resolución anterior → `current: false` con la fecha de esa intervención.

S12 — **Impresión A4 (RF-04.7, RF-05.1–05.2).** Compuesto en el navegador con `window.print()`, sin PDF de servidor; `service-sheet.css` oculta el resto de la interfaz en `@media print`, admite varias páginas y «nunca» corta el bloque de firma.

S13 — **Acceso (RF-05.3, RD-17).** El técnico lee si la avería está asignada a él **o si firmó** alguna de sus hojas; el coordinador, cualquiera. Cualquier otro acceso → `403 SERVICE_SHEET_FORBIDDEN`.

S14 — **Historial y hoja concreta (RF-05.5).** La respuesta incluye `history` con identificador, fecha de registro, fecha de resolución, si está firmada y si es la vigente; `?sheet_id=` imprime una hoja histórica y un identificador de otra avería responde `404 SERVICE_SHEET_NOT_FOUND`.

S15 — **Lectura auditada (RF-05.6, RD-18).** Cada lectura deja un evento `VIEW_SERVICE_SHEET` sobre el ticket con actor, hoja leída y vigencia.

S16 — **Auditoría del cierre (RF-06).** `RESOLVE_INCIDENT` registra si hubo firma, quién firmó y cuándo, o el motivo de ausencia; el trazo **no** viaja al registro. La hoja no genera transición ni notificación nueva.

S17 — **Validación de la imagen (RNF-02).** Solo PNG; prefijo `data:image/png;base64,`; firma binaria PNG; MIME real con `finfo`; máximo «300 KiB (307 200 bytes)» decodificados y «2 000 000» píxeles leídos del `IHDR`.

S18 — **Sin dependencias nuevas (RNF-03).** Cero paquetes en backend o frontend; canvas nativo y `window.print()`; nada de GD.

S19 — **Móvil (RNF-04).** `pointerdown/pointermove/pointerup` + `setPointerCapture`, escalado por `devicePixelRatio`, uso con guante.

S20 — **Datos personales y conservación (RNF-05, Q5).** Finalidad única de prueba de la ejecución contractual, base legal de interés legítimo, conservación indefinida, **sin** vía de supresión ni anonimización; nunca en notificaciones, portal de sede ni exportaciones.

S21 — **Options API (RNF-07, RD-13).** El componente nuevo usa Options API como el resto del repositorio; la desviación del Art. IV.2 se declara preexistente y ajena a este módulo.

S22 — **Fuera de alcance (Q7, §2.2).** Preventivas sin firma ni albarán; sin correo/Telegram, sin portal de sede, sin contrafirma, sin PDF de servidor, sin firma electrónica cualificada.

## Tasks

| ID | Specs | Ruta | Commit | Estado |
| :--- | :--- | :--- | :--- | :--- |
| **WU-0 / T-SIGN-00** | S1–S22 (puerta SDD) | inline | `docs(digital-signature): add module 19 specification and ODD tracking` | ✅ hecho (`7daed7d`) |
| **T-SIGN-01** | S5, S6, S8 | inline | `feat(digital-signature): model the service sheet and its frozen document` | ✅ hecho |
| **T-SIGN-02** | S2, S3, S4, S8, S17 | inline | (mismo commit que T-SIGN-01) | ✅ hecho |
| **T-SIGN-03** | S8, S9, S11, S14 | inline | `feat(digital-signature): persist parts, stock and the sheet in one transaction` | ✅ hecho (`461992d`) |
| **T-SIGN-04** | S5, S16 | inline | (mismo commit que T-SIGN-03) | ✅ hecho (`461992d`) |
| **T-SIGN-05** | S7, S9, S10, S11, S13, S14, S15 | inline | `feat(digital-signature): expose current and historical service sheets` | hecho |
| **T-SIGN-06** | S1, S2, S3, S4, S19 | inline¹ | `feat(digital-signature): capture the signature and print the service sheet` | pendiente |
| **T-SIGN-07** | S9, S12, S14 | inline¹ | (mismo commit que T-SIGN-06) | pendiente |
| **T-SIGN-08** | S17, S18 | inline | `docs(digital-signature): close the module and sync documented figures` | pendiente |
| **T-SIGN-09** | S1–S22 | inline | (cierre de la feature) | pendiente |

¹ Candidato al trigger de escritura delegada que **no llegó a dispararse**: T-SIGN-05 y T-SIGN-06 no comparten superficie (controladores/router/repositorio frente a componentes `.js` y sus `.mjs`), pero cada una es una unidad de comportamiento único —sin dos unidades independientes que lanzar a la vez—, así que ambas se escribieron inline y el trigger de paralelismo nunca se cumplió.

## Log

**L1 (2026-10-22) — Pedido original, verbatim:**
> "revisa el archivo docs/features_pendientes, para empeza la implementacion a venguard de la feature 4. ✍️ Firma Digital en Móvil del Responsable de Sede y Albarán de Servicio"

**L2 (2026-10-22) — Puerta SDD.** Fase 1 escrita (§1–§11 de la spec, contratos, plan, tareas y espejo funcional). Revisión QA sobre la v1: 8 grupos de hallazgos. El PO aprobó **Q1–Q8** con las respuestas recomendadas; son normativas y cualquier cambio reabre la puerta. Sin código fuente hasta la aprobación explícita de la spec.

**L3 (2026-10-22) — Auditoría al activar el orquestador.** Hechos re-verificados: `audit_log.action` es `VARCHAR(64)` (`supabase_init.sql:895`); `VanStockService::consume` solo lanza con `quantity <= 0` (`:132`); el descuento es `INSERT … ON CONFLICT … DO UPDATE` (`PdoVanStockRepository:160-162`); el camino preventivo inserta y descuenta **por línea** sin transacción (`SparePartTraceabilityService:668-673`); `incident_replaced_parts` no guarda nombre de catálogo. Desvíos declarados: (a) no existía documento ODD — se crea aquí, antes de la primera escritura de código; (b) se hicieron ~10+ búsquedas secuenciales inline en lugar de delegar un explorador read-only (trigger de mapeo) — no se rehace, la evidencia ya está consolidada; (c) **scope añadido pendiente de confirmación del PO**: RD-18 audita **toda** lectura, no solo la reimpresión del coordinador (el default aprobado era «la reimpresión desde coordinación se audita»).

**L4 (2026-10-22) — WU-0.** Specs de 18 y 19, contratos y este documento commiteados juntos. `gh` CLI disponible (el PO lo instaló) para abrir la PR al final. `odd/` no está en `.gitignore`.

**L5 (2026-10-22) — WU-1 (T-SIGN-01 + T-SIGN-02).** `Risk: none` — tabla nueva (no modifica datos existentes), validación con tests y sin contrato público consumido. Evidencia observada:
* RED del validador antes de implementar (`Class "…ServiceSheetValidator" not found`); después 80 aserciones en verde.
* `PostgresServiceSheetSchemaTest` (new_engine) 19/19: convergencia con la migración 010, 10 columnas, rama excluyente, cotas de 64–307 200 bytes, documento con `version`, `resolution_at` obligatorio, 3 hojas para la misma avería, índice, FKs y `DELETE` rechazado con 45000.
* `SchemaConvergenceParityTest` 18/18, `PostgresSchemaTest` 86/86 (CRUD genérico de las 27 tablas), `PostgresConstitutionalTest` 64/64.
* Batería hermética: 313 suites · 12.188 aserciones, 0 fallos, cifras documentadas coherentes.
* Arnés ajustado: `PostgresFixtureBuilder` rellena `absence_reason` (CHECK de rama) y sintetiza `signed_document` con `version`; el CRUD no toca las columnas de la rama. `TestDataCleaner` purga la tabla nueva. Contrato de esquema actualizado a 27 tablas.
* Desvío declarado: `DocMetricsGuard` se adelantó desde T-SIGN-08 para no cerrar la unidad con la guarda roja; volverá a tocarse en cada WU que añada suites y en el cierre.

**L6 (2026-10-22) — Revisión nativa de WU-1.** `assess` dictaminó `risk: high` / `review_due_reason: high_risk`. El primer candidato (rango completo: WU-0 + WU-1, 28 ficheros / 3.180 líneas) fue rechazado con `lens_context_budget_exceeded`: **no se creó autoridad**, nada que abandonar. Se redujo el alcance a WU-1 solo (21 ficheros / 1.564 líneas) y el PO dio consentimiento sobre ese candidato (`gentle-ai.review-integration.consent/v3`). Lineage `review-21972840fe64b02a`, revisión `sha256:ae97c0…`, lentes `review-risk` + `review-reliability`.

Resultado: **approved**, 0 hallazgos bloqueantes, 8 informativos. Autoridad quemada con `review-acknowledged/v1` (`consumed_revision sha256:4784754a…`, `authority: burned`). Desvío declarado: WU-0 (specs y documento ODD) nunca pasa por revisión nativa porque el candidato completo excede el presupuesto; es documentación pasiva y el arnés no la cuenta como cambio de comportamiento.

Hallazgos informativos y destino acordado (trabajo posterior, no reabren este candidato):

| ID | Hallazgo | Destino |
| :--- | :--- | :--- |
| `R3-4` | El `CHECK` de rama solo prueba `NULL`: `''` en nombre/documento y un blob de 64–307 200 bytes sin ser PNG pasan. | WU-2 — endurecer el DDL con `btrim(...) <> ''` y firma PNG en el `CHECK`, mientras la migración 010 no esté desplegada. |
| `R1-SHEET-UPDATE-NOT-ENFORCED` | Solo existe guarda `BEFORE DELETE`; el comentario promete inmutabilidad total. | WU-2 — alinear el comentario con lo que el motor impone o añadir guarda de `UPDATE`, y decidir con RD-01. |
| `R1-SHEET-READ-SCOPE` | El contrato de lectura no lleva actor ni exige que `sheet_id` pertenezca a la avería. | WU-2 (implementación del repositorio) + WU-3 (autorización de endpoints). |
| `R3-3` | `resolution_at` sin unicidad ni orden por el ancla: «vigente vs histórico» queda indefinido en el escenario de RD-11. | WU-2 — consulta de hoja vigente y prueba de orden por ancla. |
| `R3-2` | Cotas máximas sin casos límite (500, 120, 20, 307 200 exactos). | WU-2 — casos de borde en la suite del validador. |
| `R3-1` | `ServiceSheetDto::$recordedAt` no admite `null` mientras el modelo sí. | WU-2 — al cablear la respuesta del cierre. |
| `R1-SHEET-DECODE-BEFORE-SIZE` | Se decodifica el base64 completo antes de acotar el tamaño. | WU-2 — acotar la cadena antes de `base64_decode`. |
| `R3-5` | La aserción de historial (`>= 3`) es tautológica y no comprueba orden. | WU-2 — al añadir la lectura ordenada. |

**L7 (2026-10-22) — WU-2 (T-SIGN-03 + T-SIGN-04).** `Risk: item 4 (frontera transaccional: el cierre pasa a ser una unidad de escritura única)`. Evidencia observada:

* RED del repositorio antes de implementarlo: `tests/integration/ServiceSheetAtomicityTest.php` falló con `Class "…PdoIncidentServiceSheetRepository" not found`.
* `ServiceSheetAtomicityTest` (new_engine, esquema aislado `t_sign04_atomic`) **39/39**: rollback completo de piezas, stock y estado cuando la hoja falla; reintento sin duplicados; documento congelado inmune a renombrar sede y máquina; PNG de 12 465 bytes byte a byte; albarán histórico tras reapertura (`current` por ancla de resolución, la anterior servida por `?sheet_id`); hoja ajena excluida por el `JOIN`; proyección viva sin hoja; el repositorio no expone `update`/`delete`; el motor rechaza `UPDATE` (45000), firmante en blanco (23514) y bytes que no son PNG (23514).
* `SparePartTraceabilityServiceTest` **55/55**: S6 reescrito (una declaración con una línea inválida ya no escribe nada: se valida y construye antes de abrir la transacción), S11 reescrito (un fallo dentro del cierre aborta la transacción y no deja la avería resuelta), S12–S17 nuevos (hoja dentro de la misma transacción, firmante/documento normalizados, respuesta sin trazo ni documento, sin bloque → `service_sheet: null`, bloque inválido → 422 antes de escribir, fallo de la hoja → aborto, auditoría con `service_sheet` y sin trazo).
* `ServiceSheetValidatorTest` **100/100**: cotas exactas de R3-2 (nombre 120/121, documento 20/21, motivo 500/501, PNG 307 200/307 201) y pre-cota del base64 antes de `base64_decode` (R1-SHEET-DECODE-BEFORE-SIZE).
* `PostgresServiceSheetSchemaTest` 19/19; `PostgresSchemaTest` 86/86 con `APPEND_ONLY_TABLES`: la tabla nueva se certifica por rechazo (`45000`) en vez de exigir un `UPDATE` que el motor prohíbe.
* Batería hermética: **313 suites · 12.215 aserciones, 0 fallos**; cifras documentadas sincronizadas en README y cuatro documentos (`DocMetricsGuard` en verde).
* Arnés: nuevo `tests/Support/NullServiceSheetRepository.php` para las suites que construyen el servicio sin ejercitar el Módulo 19; los tres tests de controladores requieren el entorno de batería (ya era así antes de esta unidad).

Desvíos declarados de esta unidad:

* (a) T-SIGN-04 se certifica **extendiendo** `tests/unit/SparePartTraceabilityServiceTest.php` (sección 7.10) en vez de crear `ServiceSheetResolutionTest.php`: el contrato de `tasks.md` nombraba esa suite, y un fichero nuevo habría duplicado ~400 líneas de dobles para los mismos escenarios. `tasks.md` se actualizó para nombrar la suite real.
* (b) Cambio de comportamiento aceptado y documentado: antes, un cierre con una línea válida seguida de una inválida dejaba escrita la primera; ahora no escribe ninguna. Es exactamente S5 y el test S6 se reescribió para fijarlo.
* (c) Se añadió el disparador `BEFORE UPDATE` porque el contrato aprobado lo exige literalmente («el trigger lo impide en base», invariante 2) y porque la inmutabilidad es el valor del módulo; la migración 010 aún no estaba desplegada.
* (d) Los hallazgos informativos R3-4, R1-SHEET-UPDATE-NOT-ENFORCED, R3-3, R3-2, R3-1, R1-SHEET-DECODE-BEFORE-SIZE y R3-5 quedan cerrados en esta unidad; R1-SHEET-READ-SCOPE se cierra con la autorización de endpoints en WU-3.

**L8 (2026-10-22) — Revisión nativa de WU-2.** `assess --base-ref 8e3754e --committed-only` dictaminó `high_risk`. Consentimiento del PO sobre el candidato (25 ficheros / 1.505 líneas) en `gentle-ai.review-integration.consent/v3`: **granted**. Lineage `review-1d7431e21bb5c3d2`, revisión `sha256:99e19082…`, lentes `review-risk`, `review-resilience`, `review-reliability` (documentos inmutables con guarda de motor, cierre transaccional bajo fallo y lógica fijada por pruebas nuevas).

Resultado: **approved**, 0 hallazgos bloqueantes, 9 informativos. Autoridad quemada (`review-acknowledged/v1`, `consumed_revision sha256:bbd34a1c…`, `authority: burned`). Commit del candidato: `461992d`.

Hallazgos informativos y destino acordado (trabajo posterior, no reabren este candidato):

| ID | Hallazgo | Destino |
| :--- | :--- | :--- |
| `R1-MIGRATION-CHECK-UPGRADE`, `R3-MIGRATION-DRIFT`, `R4-MIGRATION-IN-PLACE` | Los `CHECK` endurecidos y el disparador `BEFORE UPDATE` se editaron **dentro** de la migración 010 (`CREATE TABLE IF NOT EXISTS`): una base que ya hubiera aplicado la versión anterior conserva la tabla laxa. Hoy no hay ninguna base así (010 nunca se desplegó), pero la deriva no la detectan las suites, que reconstruyen desde `supabase_init.sql`. | WU-5 (cierre documental): nota de despliegue que exige 010 sin aplicar, o migración `011` con `ALTER TABLE … ADD CONSTRAINT` + `CREATE TRIGGER` si el entorno ya la aplicó. |
| `R3-PERSISTENCE-EXC`, `R4-SHEET-ERROR-TYPE` | `ServiceSheetPersistenceException` solo se lanza cuando `INSERT … RETURNING` no devuelve fila; un fallo real del motor (CHECK, FK, conexión) viaja como `PDOException` sin código técnico, y ninguna prueba referencia la excepción. | WU-3: envolver el fallo del motor al escribir la hoja y probar el camino de error con un CHECK forzado. |
| `R4-READ-SILENT-FALLBACK` | Un `sheet_id` inexistente o de otra avería no se distingue de «sin hoja»: el modelo devuelve `sheet: null` y la proyección viva. | WU-3: la ruta responde `404 SERVICE_SHEET_NOT_FOUND` (S14) y distingue el caso por el `sheet_id` pedido. |
| `R3-ATOMICITY-GATE` | `ServiceSheetAtomicityTest` es `new_engine`: `run_all.php` sigue en 313 suites y esas conductas pueden regresar sin motor. | Aceptado como límite declarado del arnés; la alternativa (duplicar la suite contra dobles) certificaría menos. Se anota en el cierre de WU-5. |
| `R3-PREDECODE-ASSERT` | La aserción de la pre-cota base64 no distingue el guardián de su ausencia (el mismo cuerpo lo rechazan también las cotas posteriores). | WU-5: o se fija con un espía del decodificador, o se declara la pre-cota como optimización no observable. |
| `R1-TEST-SQL-CONCAT` | La suite de integración concatena en SQL valores constantes definidos en la propia prueba. | Aceptado: no hay valor atacante; se anota para no copiarlo a código de producción. |

**L9 (2026-10-22) — WU-3 (T-SIGN-05).** `Risk: item 2 (seguridad: autorización de lectura por rol sobre datos personales de un tercero)`.

Evidencia observada:

* RED antes de implementar: `ServiceSheetDocumentTest` 26 fallos (`ROUTE_NOT_FOUND` en ambas rutas, 0 eventos `VIEW_SERVICE_SHEET`, sin 403 ni 422) y `TestDataCleanerIsolationTest` 1 fallo (el plan de purga pasó de 19 a 20 tablas transitorias al entrar `incident_service_sheets`). Después: **27/27** y **50/50**.
* `ServiceSheetPrivacyTest` **7/7 ya en verde en el primer intento**: el modelo de lectura existente no filtraba coste ni nota interna. Se conserva como guarda de RD-04/S10 —no como comportamiento nuevo— sobre el `json_encode()` completo y con una pieza de coste real (12,34) y una nota marcada.
* `ServiceSheetAtomicityTest` (new_engine) **40/40** con la aserción nueva de `R3-PERSISTENCE-EXC`: un `CHECK` forzado en el motor sale como `ServiceSheetPersistenceException` con el `PDOException` original en `getPrevious()`, código `SERVICE_SHEET_PERSISTENCE_FAILED` y HTTP 500.
* Batería hermética: **315 suites · 12.249 aserciones, 0 fallos**; cifras sincronizadas en README y los cuatro documentos auditados.

Decisiones de diseño de la unidad:

* **Autorización en el puerto, no en el controlador.** `isReadableByTechnician()` ejecuta la consulta `EXISTS` de §6.4 (asignado **o** firmante). Reutilizar `resolveReadableIncident()` habría atado el acceso al historial de estados (`REOPENED` + antecedentes) y habría dejado fuera justo al firmante reasignado, que es el caso que RD-17 protege.
* **Anclaje de la hoja servida.** `READ_SQL` sirve la hoja anclada a la resolución vigente (`resolution_at = resolved_at`). Cuando la incidencia no está en `RESOLVED`/`CLOSED` (reapertura en curso) sirve la última como histórica con `current: false`; tras un nuevo cierre sin hoja vuelve a `sheet: null`. Sin ese anclaje, el segundo cierre sin firma reutilizaba la firma del primero (S7, invariante 8). La versión anterior del `SELECT` sí la reutilizaba: era un defecto real, no una preferencia.
* **`422 INCIDENT_NOT_RESOLVED` no incluye `REOPENED`.** La reapertura en garantía demuestra un cierre anterior y la proyección viva conserva diagnóstico y acción; responder 422 diría «nunca se cerró», que es falso. Se fija en la suite (3.1) y se documenta en §4.4.
* **Duplicación deliberada de ~30 líneas entre los dos controladores** en vez de un servicio de lectura nuevo: el mapeo de errores es presentación pura, la autorización difiere entre perfiles (RD-17 solo aplica al técnico) y el modelo ya lo compone el repositorio. Un servicio habría añadido una capa y dos excepciones para ahorrar treinta líneas.
* **Sin servicio de lectura nuevo y sin caché**: cada `GET` recompone el modelo y escribe su evento. La lectura es barata frente al riesgo de servir una vigencia obsoleta.

Desvíos declarados de esta unidad:

* (a) Se añadió `INVALID_SHEET_ID` (400) al catálogo de errores y a §4.4 de los contratos: `?sheet_id=` no entero positivo no estaba cubierto y la alternativa —ignorarlo— habría servido el albarán vigente fingiendo haber atendido una petición explícita. Es un hueco de contrato, no un cambio de semántica aprobada.
* (b) `ServiceSheetDocumentTest` y `ServiceSheetPrivacyTest` viven en la batería hermética (base `vendguard_battery`) y no en `new_engine`: la primera necesita router y middlewares reales, y la segunda solo consulta el modelo. La batería local se migró a mano con `010_incident_service_sheets.sql` porque su base se aprovisionó antes de WU-1; un `provision.sh` limpio ya la incluye vía `supabase_init.sql`.
* (c) `TestDataCleanerIsolationTest` 2.7 se actualizó de 19 a 20 tablas: es una guarda de contrato, y la tabla nueva es transitoria por `ON DELETE RESTRICT`.
* (d) **Hueco del contrato cerrado aquí, no en WU-2.** `ServiceSheetPersistenceException` extiende `RuntimeException` y la cadena de `catch` de `TechnicianController::resolveIncident` no la capturaba: el `500 SERVICE_SHEET_PERSISTENCE_FAILED` de §5 era inalcanzable por HTTP y la excepción escapaba al manejador fatal de PHP. Se añadió el `catch` que la mapea con su `getErrorCode()`/`getHttpStatusCode()` (3 líneas, junto al resto de excepciones de la transacción). Queda fuera de la suite HTTP por coste: verificarla exigiría doblar el servicio de trazabilidad entero en un controlador que el proyecto nunca construye con dobles; la evidencia es el test de servicio (la excepción sale con su código) más la inspección de la cadena.

