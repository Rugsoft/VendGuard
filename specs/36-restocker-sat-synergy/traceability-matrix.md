# MATRIZ DE TRAZABILIDAD · MÓDULO 36

**Especificación:** [`spec.md`](spec.md) · **Contratos:** [`../technical/restocker_sat_synergy_contracts.md`](../technical/restocker_sat_synergy_contracts.md) · **Plan:** [`plan.md`](plan.md) · **Tareas:** [`tasks.md`](tasks.md) · **ODD:** [`../../odd/tasks/restocker-sat-synergy.md`](../../odd/tasks/restocker-sat-synergy.md)

**Esquema de referencia canónico:** `RF-01` clasificación L1 y guardas · `RF-02` triaje, despacho y auto-escalados · `RF-03` operativa móvil del reponedor y escalado al SAT · `RF-04` Coincidencia en Sede y relevo de turno · `RF-05` cola Offline, superficies y métricas.

**Corrida de certificación:** 2026-11-19 · batería global **441 suites · 16.993 aserciones · 0 fallos** · evidencia focalizada de cierre FIX-36-01 a FIX-36-07: **11 suites propias · 561 aserciones** + layout L1/SAT **22/22** a 360 × 800 · canal `new_engine`: **71 evaluadas · 71 verdes · 2.342 aserciones · 0 fallos** (`PoolerLatencyMeasurementTest` 30/30: «crear» 210,5 ms y «ruta» 209,4 ms frente al presupuesto de 250 ms) · PostgreSQL 16.15 local con TLS (`vendguard_pg_test` y `vendguard_battery`).

**Estado:** Módulo 36 implementado con **H-36-01, H-36-02, H-36-03, H-36-04 y H-36-05 cerradas** · 4/4 DoD · 12/12 tareas (`T-RSS-01` a `T-RSS-12`) sin reservas · **11 suites propias con 579 aserciones verdes** + `RestockerSatLayoutBrowserTest` **22/22** · certificación global hermética **441/16.993 en verde** · canal `new_engine` **71/71 · 2.356 aserciones · 0 fallos** (certificación 2026-11-20) · H-36-10 cerrado con `location_id` en el DTO y composición de la vista · migración `032_restocker_sat_synergy.sql` convergida en `supabase_init.sql` y `supabase_seeds.sql` · matriz, tareas y ODD sincronizados.

**Actualización (2026-11-20):** `RestockerSatSynergyApiTest` pasa a **69/69** (+2) por la cobertura de H-36-10 (`location_id` persistido y coincidencia indexada por sede); la evidencia focalizada de las 11 suites suma **579 aserciones** tras H-36-09 y H-36-10. El canal `new_engine` se reejecutó completo: **71/71 · 2.356 aserciones · 0 fallos · `exit=0`**, incluida `PoolerLatencyMeasurementTest` **30/30**. La batería global **441/16.993** sigue siendo la certificación del 2026-11-19 y no se reejecutó.

**Nota de alcance:** la batería global **441/16.993** es la certificación hermética del 2026-11-19 y **no incluye** las 71 suites del canal `new_engine`; la evidencia focalizada del módulo (11 suites, 579 aserciones) sí incluye sus suites de integración y frontend, y H-36-04 añade **22/22** de layout real a 360 × 800. H-36-02 quedó cerrada con composición HTTP real sobre `/api/technician/my-route` (bloque 8.3): la tarjeta técnica recibe el rastro de escalado, la última Nota Interna de Taller y su foto. H-36-03 quedó cerrada al alinear los paths canónicos (`/api/qr/report` y `/api/incidents`) y componer en `CreateIncidentEndpointTest` caso 9 el alta de Portal elegible L1 y la exclusión por cuarentena. El canal `new_engine` queda en **71/71** con el presupuesto de latencia satisfecho: `PoolerLatencyMeasurementTest` **30/30** (medianas de la corrida del 2026-11-19: «crear» **210,5 ms**, «ruta» **209,4 ms** y «listar» 210,3 ms). La mejora no toca el esquema: el `SAVEPOINT` del alta viaja como prefijo de la misma sentencia preparada que la CTE (una ida y vuelta menos) y la última Nota Interna de Taller se lee en la consulta del tablero de ruta (otra menos). H-36-05 queda cerrada. El resto del canal, incluidas las 69 aserciones de `RestockerSatSynergyApiTest` y las 22 de `RestockerSatLayoutBrowserTest`, está en verde.

---

## 1. Trazabilidad de Requisitos Funcionales

| Requisito | Criterios EARS | Implementación | Evidencia verificada |
| :--- | :--- | :--- | :--- |
| **RF-01** · Clasificación de Nivel 1, guardas constitucionales y taxonomía | `01.1` tipos #43/#44, columnas y CHECK; `01.2` pre-clasificación QR/Sede/Coordinación; `01.3` 4 parejas `RC-L1-*` / `SS-L1-*` | `IncidentDispatchLevel`, `Level1IssueType`, `Incident`, `RestockerSatSynergyValidator`, migración `032`, `supabase_init.sql`, `supabase_seeds.sql`, `PdoIncidentRepository`, `QrReportService` | `PostgresRestockerSatSynergySchemaTest` **30/30** (tipos, 56 columnas, guarda L1 con el mapeo `level1_issue_type` ↔ `category` de FIX-36-06, índice, catálogo Offline, semillas y 49 tablas); `RestockerSatSynergyDomainModelsTest` **36/36** (espejo de la guarda y opacidad); `RestockerSatSynergyValidatorTest` **46/46** (mapeo determinista y exclusiones); `RestockerSatSynergyApiTest` **69/69** (bloques 6 y 9: pre-clasificación QR y alta de Coordinación); `RestockerSatSynergyConstitutionalTest` **17/17**; `CreateIncidentEndpointTest` caso 9.1–9.2 (Portal elegible L1 y cuarentena), `exit=0` |
| **RF-02** · Triaje, despacho y auto-escalados | `02.1` sugerencia de reponedor y conmutación L1/L2; `02.2` auto-escalado en absorción QR; `02.3` promoción en reapertura < 48 h | `RestockerSatSynergyService`, `PdoRestockerSatSynergyGateway`, `RestockerZoneCoverageService`, `TechnicianAssignmentSuggester`, `PdoIncidentRepository::reopenWithLifecycleNotifications()`, `CoordinatorController` | `RestockerSatSynergyServiceTest` **34/34**; `PdoRestockerSatSynergyGatewayTest` **24/24** (`suggestRestockers`, `assignDispatchLevel`, reasignación y replay); `RestockerSatSynergyIntegrationsTest` **27/27** (absorción QR y reapertura); `RestockerSatSynergyApiTest` **69/69** (bloques 6 QR, 8 escalado y 9 alta de Coordinación); `RestockerSatSynergyConstitutionalTest` **17/17** (promoción real en repositorio); `CreateIncidentEndpointTest` caso 9, `exit=0`; `CloudDeploySchemaParityTest` **55/55**. `ReopenIncidentEndpointTest` `exit=0`, pero no certifica por sí solo la promoción `dispatch_level` |
| **RF-03** · Operativa móvil L1 y «⚡ Escalar Avería al SAT» | `03.1` inicio y resolución L1 con guarda sanitaria y textos mínimos; `03.2` escalado con nota/foto y tres ramas deterministas | `RestockerSatSynergyService`, `PdoRestockerSatSynergyGateway`, `RestockerRouteController`, `RestockerRouteView.js`, `RestockerIncidentOfflineQueue.js` | `PdoRestockerSatSynergyGatewayTest` **24/24** (las tres ramas bajo `FOR UPDATE`, custodia y auditoría); `RestockerSatSynergyServiceTest` **34/34**; `RestockerSatSynergyApiTest` **69/69** bloque 8 (asignación SAT, `REGISTERED` sin técnico y outbox); `restocker_route_view.test.mjs` **240/240** (bandeja L1, inicio, resolución, escalado desde tarjeta, bandeja y visita, nota/foto y cola Offline); `CoordinatorButtonVariantsGuardTest.mjs` **23/23** |
| **RF-04** · Coincidencia en Sede y relevo de turno | `04.1` bloque `site_coincidence` bidireccional; `04.2` bloqueo `IN_PROGRESS` y relevo al cerrar turno, ausencia o baja | `RestockerSatSynergyGateway`, `RestockerSatSynergyService`, `ShiftLifecycleService`, `TechnicianAbsenceService`, `TechnicianRouteView.js`, `RestockerRouteView.js` | `PdoRestockerSatSynergyGatewayTest` **24/24** (`technicianCoincidences`, `restockerCoincidences`, `handoverLevel1`); `RestockerSatSynergyApiTest` **69/69** (bloque 7: C1 en cierre, ausencia y baja; bloque 5: `location_id` y coincidencia indexada por sede); `RestockerSatSynergyIntegrationsTest` **27/27** (relevo por turno y ausencia); `RestockerSatSynergyConstitutionalTest` **17/17** (unicidad de custodia); `RestockerShiftAndWorkHoursTest` **38/38**; `restocker_route_view.test.mjs` **240/240** (bootstrap con `location_id` y banner; H-36-10); `technician_and_coordinator_synergy.test.mjs` **18/18** (banner con `operator_code` y `tel:`, sin filtrar el nivel; H-36-01) |
| **RF-05** · Cola Offline, superficies y métricas | `05.1` `client_mutation_id`, replay y volcado; `05.2` `RestockerRouteView` y `TechnicianRouteView`; `05.3` horas del reponedor, filtro y triaje dual | `offline_sync_mutations`, `RestockerIncidentOfflineQueue.js`, `RestockerRouteView.js`, `TechnicianRouteView.js`, `IncidentWorkshopNotesRepositoryInterface`, `TechnicianController::stopPayload()`, `CoordinatorDashboardView.js`, `TechnicianWorkHoursService`, `CoordinatorController` | `PostgresRestockerSatSynergySchemaTest` **30/30** (`MACHINE` + 3 operaciones y rechazos); `RestockerSatSynergyApiTest` **69/69** (bloques 6–7, replay y conflicto; bloque 8.3 con rastro de escalado, Nota Interna y foto en `/api/technician/my-route`); `restocker_route_view.test.mjs` **240/240**; `technician_and_coordinator_synergy.test.mjs` **18/18** (insignia técnica, nota/foto y banner con `operator_code`/`tel:`); `RestockerShiftAndWorkHoursTest` **38/38** (intervenciones y tiempo en ruta del reponedor); `RestockerSatLayoutBrowserTest` **22/22** a 360 × 800 (L1 y SAT) |

### 1.1. Mapa de suites, rutas y recuento vigente

| Suite | Ruta | Recuento |
| :--- | :--- | ---: |
| `RestockerSatSynergyDomainModelsTest` | [`tests/unit/RestockerSatSynergyDomainModelsTest.php`](../../tests/unit/RestockerSatSynergyDomainModelsTest.php) | 36/36 |
| `RestockerSatSynergyValidatorTest` | [`tests/unit/RestockerSatSynergyValidatorTest.php`](../../tests/unit/RestockerSatSynergyValidatorTest.php) | 46/46 |
| `RestockerSatSynergyServiceTest` | [`tests/unit/RestockerSatSynergyServiceTest.php`](../../tests/unit/RestockerSatSynergyServiceTest.php) | 34/34 |
| `RestockerSatSynergyIntegrationsTest` | [`tests/unit/RestockerSatSynergyIntegrationsTest.php`](../../tests/unit/RestockerSatSynergyIntegrationsTest.php) | 27/27 |
| `RestockerShiftAndWorkHoursTest` | [`tests/unit/RestockerShiftAndWorkHoursTest.php`](../../tests/unit/RestockerShiftAndWorkHoursTest.php) | 38/38 |
| `PostgresRestockerSatSynergySchemaTest` | [`tests/integration/PostgresRestockerSatSynergySchemaTest.php`](../../tests/integration/PostgresRestockerSatSynergySchemaTest.php) | 30/30 |
| `PdoRestockerSatSynergyGatewayTest` | [`tests/integration/PdoRestockerSatSynergyGatewayTest.php`](../../tests/integration/PdoRestockerSatSynergyGatewayTest.php) | 24/24 |
| `RestockerSatSynergyApiTest` | [`tests/integration/RestockerSatSynergyApiTest.php`](../../tests/integration/RestockerSatSynergyApiTest.php) | 69/69 |
| `RestockerSatSynergyConstitutionalTest` | [`tests/integration/RestockerSatSynergyConstitutionalTest.php`](../../tests/integration/RestockerSatSynergyConstitutionalTest.php) | 17/17 |
| `restocker_route_view.test.mjs` | [`tests/frontend/restocker_route_view.test.mjs`](../../tests/frontend/restocker_route_view.test.mjs) | 240/240 |
| `technician_and_coordinator_synergy.test.mjs` | [`tests/frontend/technician_and_coordinator_synergy.test.mjs`](../../tests/frontend/technician_and_coordinator_synergy.test.mjs) | 18/18 |
| `RestockerSatLayoutBrowserTest` | [`tests/integration/RestockerSatLayoutBrowserTest.php`](../../tests/integration/RestockerSatLayoutBrowserTest.php) | 22/22 · 360 × 800 |
| `PostgresSchemaTest` | [`tests/integration/PostgresSchemaTest.php`](../../tests/integration/PostgresSchemaTest.php) | 153/153 |
| `PostgresConstitutionalTest` | [`tests/integration/PostgresConstitutionalTest.php`](../../tests/integration/PostgresConstitutionalTest.php) | 86/86 |
| `SchemaConvergenceParityTest` | [`tests/integration/SchemaConvergenceParityTest.php`](../../tests/integration/SchemaConvergenceParityTest.php) | 42/42 |
| `CloudDeploySchemaParityTest` | [`tests/integration/CloudDeploySchemaParityTest.php`](../../tests/integration/CloudDeploySchemaParityTest.php) | 55/55 · `exit=0` |
| `RestockerRbacConstitutionalTest` | [`tests/integration/RestockerRbacConstitutionalTest.php`](../../tests/integration/RestockerRbacConstitutionalTest.php) | 27/27 |
| `PdoWorkHoursRoleFilterTest` | [`tests/integration/PdoWorkHoursRoleFilterTest.php`](../../tests/integration/PdoWorkHoursRoleFilterTest.php) | 18/18 |
| `LocationPortalReopenAuditTest` | [`tests/unit/LocationPortalReopenAuditTest.php`](../../tests/unit/LocationPortalReopenAuditTest.php) | 18/18 · auditoría auxiliar; no certifica la promoción `dispatch_level` |
| `CreateIncidentEndpointTest` | [`tests/integration/CreateIncidentEndpointTest.php`](../../tests/integration/CreateIncidentEndpointTest.php) | caso 9.1–9.2 · `exit=0` |
| `ReopenIncidentEndpointTest` | [`tests/integration/ReopenIncidentEndpointTest.php`](../../tests/integration/ReopenIncidentEndpointTest.php) | `exit=0`; no verifica la promoción L1→L2 |
| `CoordinatorButtonVariantsGuardTest` | [`tests/unit/CoordinatorButtonVariantsGuardTest.mjs`](../../tests/unit/CoordinatorButtonVariantsGuardTest.mjs) | 23/23 |
| `FrontendTemplateCompileTest` | [`tests/unit/FrontendTemplateCompileTest.mjs`](../../tests/unit/FrontendTemplateCompileTest.mjs) | 15/15 |
| `DesignTokenDebtRatchetTest` | [`tests/unit/DesignTokenDebtRatchetTest.mjs`](../../tests/unit/DesignTokenDebtRatchetTest.mjs) | 3/3 |
| `OfflinePwaAssetsAndUiGuardTest` | [`tests/unit/OfflinePwaAssetsAndUiGuardTest.mjs`](../../tests/unit/OfflinePwaAssetsAndUiGuardTest.mjs) | 45/45 |
| `MobileShellLayoutGuardTest` | [`tests/unit/MobileShellLayoutGuardTest.mjs`](../../tests/unit/MobileShellLayoutGuardTest.mjs) | 7/7 |

---

## 2. Trazabilidad de Requisitos No Funcionales

| Requisito | Regla verificada | Evidencia |
| :--- | :--- | :--- |
| **RNF-01** · Integridad relacional y prohibición de borrado físico | 49 tablas (sin tablas nuevas), 44 tipos `ENUM`, disparadores `prevent_hard_delete()` e inmutabilidad de tramos cerrados | `PostgresRestockerSatSynergySchemaTest` **30/30**; `PostgresConstitutionalTest` **86/86**; `PostgresSchemaTest` **153/153** |
| **RNF-02** · Defensa en profundidad sanitaria y privacidad | CHECK L1/térmico-eléctrico en el motor, incluido el mapeo determinista de FIX-36-06; notas `is_internal = TRUE` ≥ 20 caracteres reales; fotos ≤ 5 MB por magic bytes; DTOs de Sede/QR sin metadatos L1 ni Coincidencia | `RestockerSatSynergyConstitutionalTest` **17/17**; `RestockerSatSynergyValidatorTest` **46/46**; `RestockerSatSynergyDomainModelsTest` **36/36**; `RestockerSatSynergyApiTest` **69/69** (opacidad QR); `technician_and_coordinator_synergy.test.mjs` **18/18** |
| **RNF-03** · Sistema de diseño y Dogma Vanilla | PHP 8.2+ tipado estricto y Vue 3 ES Modules sin bundlers; tokens `vg-*` y viewport móvil de 360 px sin scroll horizontal | `RestockerSatLayoutBrowserTest` **22/22** en Chromium real a 360 × 800 (L1 y SAT); `restocker_route_view.test.mjs` **240/240**; `DesignTokenDebtRatchetTest.mjs` **3/3** (techo rebajado a 2.242 literales); `CoordinatorButtonVariantsGuardTest.mjs` **23/23**; `MobileShellLayoutGuardTest.mjs` **7/7**; `FrontendTemplateCompileTest.mjs` **15/15** |

---

## 3. Decisiones, Casos Límite y Artículos Constitucionales

| Referencia | Decisión o caso | Cobertura |
| :--- | :--- | :--- |
| **D1** | Nivel de despacho explícito en `incidents`, con `L2_SAT` como valor heredado | `PostgresRestockerSatSynergySchemaTest` **30/30**; `RestockerSatSynergyDomainModelsTest` **36/36** |
| **D2** | Pre-clasificación determinista y cuatro exclusiones que fuerzan SAT | `RestockerSatSynergyValidatorTest` **46/46**; `RestockerSatSynergyIntegrationsTest` **27/27**; `RestockerSatSynergyApiTest` **69/69** bloque 9; `CreateIncidentEndpointTest` caso 9 (elegible L1 y cuarentena) |
| **D3** | Resolución L1 sin repuestos ni albarán A4 y escalado atómico en tres ramas | `PdoRestockerSatSynergyGatewayTest` **24/24**; `RestockerSatSynergyServiceTest` **34/34**; `RestockerSatSynergyApiTest` **69/69** bloque 8; `restocker_route_view.test.mjs` **240/240** |
| **D4** | Coincidencia en Sede derivada de turno, zona y carga viva | `PdoRestockerSatSynergyGatewayTest` **24/24**; `RestockerSatSynergyApiTest` **69/69** (bloques 6–9; H-36-10 valida `location_id` y coincidencia indexada); `restocker_route_view.test.mjs` **240/240** (bootstrap con `location_id` y banner); `technician_and_coordinator_synergy.test.mjs` **18/18** |
| **C1** | Bloqueo `SHIFT_CLOSE_BLOCKED_IN_PROGRESS_L1` y relevo automático al suplente | `RestockerSatSynergyApiTest` **69/69** bloque 7 (composición HTTP/PostgreSQL real); `RestockerSatSynergyIntegrationsTest` **27/27**; `PdoRestockerSatSynergyGatewayTest` **24/24**; `RestockerShiftAndWorkHoursTest` **38/38** |
| **C2** | Idempotencia offline y atribución de horas L1 al reponedor | `RestockerSatSynergyApiTest` **69/69** (bloques 6–7); `RestockerIncidentOfflineQueue` cubierto por `restocker_route_view.test.mjs` **240/240**; `RestockerShiftAndWorkHoursTest` **38/38** |
| **QA-AMB-01** | Mapeo de los 4 motivos L1 y semillas de causas/soluciones | `RestockerSatSynergyValidatorTest` **46/46**; `PostgresRestockerSatSynergySchemaTest` **30/30**; `RestockerSatSynergyDomainModelsTest` **36/36** |
| **QA-AMB-02** | Ampliación de autoría, custodia y operaciones Offline | `PostgresRestockerSatSynergySchemaTest` **30/30** |
| **QA-CON-01** | Coherencia rol/nivel y segregación de rutas | `RestockerSatSynergyValidatorTest` **46/46**; `RestockerSatSynergyApiTest` **69/69** (bloques 7–8); `RestockerRbacConstitutionalTest` **27/27** |
| **QA-CON-02** | Las intervenciones L1 del reponedor suman a su jornada | `RestockerShiftAndWorkHoursTest` **38/38**; `RestockerSatSynergyIntegrationsTest` **27/27** |
| **QA-LIM-01** | Auto-escalado QR por frío, electricidad o reclamación grave | `RestockerSatSynergyApiTest` **69/69** bloque 6 (composición HTTP/PostgreSQL real, con asignación y outbox); `RestockerSatSynergyIntegrationsTest` **27/27**; `RestockerSatSynergyConstitutionalTest` **17/17** |
| **QA-LIM-02** | Promoción a SAT en reapertura < 48 h | `RestockerSatSynergyIntegrationsTest` **27/27** (regla); `RestockerSatSynergyConstitutionalTest` **17/17** (promoción real en repositorio); `LocationPortalReopenAuditTest` **18/18** es auditoría auxiliar y no certifica la promoción; `ReopenIncidentEndpointTest` `exit=0`, sin aserción de `dispatch_level` |
| **QA-LIM-03** | Prohibición de `PENDING_PARTS` y repuestos en L1 | `PostgresRestockerSatSynergySchemaTest` **30/30**; `RestockerSatSynergyValidatorTest` **46/46**; `RestockerSatSynergyDomainModelsTest` **36/36** |
| **QA-CONST-01** | Guarda Art. II en dominio y PostgreSQL; cuarentena bloquea la resolución | `RestockerSatSynergyConstitutionalTest` **17/17**; `PostgresRestockerSatSynergySchemaTest` **30/30**; `RestockerSatSynergyDomainModelsTest` **36/36** |
| **QA-CONST-02** | Absorción idempotente sobre un SAT preexistente sin segundo ticket | `PdoRestockerSatSynergyGatewayTest` **24/24**; `RestockerSatSynergyConstitutionalTest` **17/17** |
| **QA-CONST-03** | Opacidad de nivel, taxonomía L1, notas internas y Coincidencia | `RestockerSatSynergyConstitutionalTest` **17/17**; `RestockerSatSynergyDomainModelsTest` **36/36**; `RestockerSatSynergyApiTest` **69/69**; `technician_and_coordinator_synergy.test.mjs` **18/18** |
| **Art. II** | Primacía sanitaria: L1 jamás térmico/eléctrico y resolución bloqueada en cuarentena | CHECK `chk_incidents_l1_dispatch_coherence` y guardas de validador/servicio, certificados por `PostgresRestockerSatSynergySchemaTest` **30/30**, `RestockerSatSynergyDomainModelsTest` **36/36** y `RestockerSatSynergyConstitutionalTest` **17/17** |
| **Art. III.1 / III.3** | Sin borrado físico; historia y auditoría append-only; conflicto Offline auditado | `PostgresConstitutionalTest` **86/86**; `RestockerSatSynergyConstitutionalTest` **17/17** |
| **Art. IV** | Dogma Vanilla, 49 tablas, 44 tipos y cero dependencias de frontend | `PostgresSchemaTest` **153/153**; `FrontendTemplateCompileTest.mjs` **15/15** |
| **Art. V.1 / V.2 / V.3** | Textos reales mínimos, un único ticket activo por máquina y custodia del técnico respetada | `RestockerSatSynergyValidatorTest` **46/46**; `RestockerSatSynergyConstitutionalTest` **17/17** |
| **Art. V.4 / V.5 / V.6** | Opacidad de Sede/QR, evidencia validada y reapertura promovida | `RestockerSatSynergyConstitutionalTest` **17/17**; `RestockerSatSynergyIntegrationsTest` **27/27**; `RestockerSatSynergyApiTest` **69/69**; `LocationPortalReopenAuditTest` **18/18** (auxiliar) |

---

## 4. Evidencia de Certificación

| Puerta | Comando | Resultado |
| :--- | :--- | :--- |
| Batería global (2026-11-19) | `php tests/run_all.php` | **441 suites · 16.993 aserciones · 0 fallos** · `DocMetricsGuard` corregido · `exit=0` |
| Canal `new_engine` (2026-11-19) | `source deploy/local/postgres/.env.docker-tls && php tests/run_new_engine.php` | **71 evaluadas · 71 verdes · 2.342 aserciones · 0 fallos** · `PoolerLatencyMeasurementTest` **30/30** (crear 210,5 ms y ruta 209,4 ms frente a 250 ms); 0 no ejecutables |
| Canal `new_engine` (2026-11-20) | `source deploy/local/postgres/.env.docker-tls && php tests/run_new_engine.php` | **71 evaluadas · 71 verdes · 2.356 aserciones · 0 fallos · `exit=0`** · `PoolerLatencyMeasurementTest` **30/30**; 0 no ejecutables |
| Evidencia focalizada FIX-36-01..07 (2026-11-19) | Las 11 suites propias del mapa 1.1 | **561 aserciones · 0 fallos · `exit=0`** |
| Esquema y constitución | `PostgresSchemaTest` + `PostgresConstitutionalTest` + `SchemaConvergenceParityTest` | **153/153 · 86/86 · 42/42** · `exit=0` |
| Paridad de despliegue | `php tests/integration/CloudDeploySchemaParityTest.php` | **55/55** · `exit=0` |
| Composiciones HTTP/PostgreSQL | `RestockerSatSynergyApiTest` + `CreateIncidentEndpointTest` | **69/69** (bloques 6–9, incluido el bloque 8.3 con nota/foto en `/api/technician/my-route`) · caso 9.1–9.2 (Portal elegible L1 y cuarentena) `exit=0`; `ReopenIncidentEndpointTest` `exit=0` sin promoción certificada |
| Guardas de interfaz | Botones + plantillas + tokens + PWA + móvil | **23/23 · 15/15 · 3/3 · 45/45 · 7/7** |
| Residuo de esquemas temporales | Consulta a `pg_namespace` tras las suites | 0 esquemas `t_*` del Módulo 36 |

**Nota de cierre:** el desfase preexistente del libro de la batería (huella de la migración 031 anterior a `c8fc88d`) quedó resuelto al reconstruir `vendguard_battery` desde el DDL base y el libro actual; la ruta de despliegue y el esquema probado declaran los mismos 44 tipos `ENUM`, las mismas 49 tablas y las mismas 56 columnas de `incidents`. La cifra global L14 (441/16.987) se conserva solo como referencia histórica; la certificación vigente es 441/16.993. `PoolerLatencyMeasurementTest` completa sus 30 aserciones y el canal queda al 100 %; H-36-05 se cierra con dos recortes de ida y vuelta —savepoint prefijado en la CTE y nota interna leída en el tablero— sin cambiar el esquema. El techo de deuda cromática baja de 2.268 a 2.242 literales porque las superficies del módulo consumen tokens sin duplicar sus valores de respaldo.
