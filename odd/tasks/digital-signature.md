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
| **T-SIGN-03** | S8, S9, S11, S14 | inline | `feat(digital-signature): persist parts, stock and the sheet in one transaction` | pendiente |
| **T-SIGN-04** | S5, S16 | inline | (mismo commit que T-SIGN-03) | pendiente |
| **T-SIGN-05** | S7, S9, S10, S11, S13, S14, S15 | inline | `feat(digital-signature): expose current and historical service sheets` | pendiente |
| **T-SIGN-06** | S1, S2, S3, S4, S19 | inline¹ | `feat(digital-signature): capture the signature and print the service sheet` | pendiente |
| **T-SIGN-07** | S9, S12, S14 | inline¹ | (mismo commit que T-SIGN-06) | pendiente |
| **T-SIGN-08** | S17, S18 | inline | `docs(digital-signature): close the module and sync documented figures` | pendiente |
| **T-SIGN-09** | S1–S22 | inline | (cierre de la feature) | pendiente |

¹ Único candidato al trigger de escritura delegada: T-SIGN-06 en paralelo con T-SIGN-05, con superficies disjuntas (`public/assets/js/components/` + su `.mjs` frente a controladores/router/repositorio). Se decide al llegar a WU-3; si no hay paralelismo real, se escribe inline.

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
