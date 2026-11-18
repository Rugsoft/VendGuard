# MATRIZ DE TRAZABILIDAD · MÓDULO 36

**Especificación:** [`spec.md`](spec.md) · **Contratos:** [`../technical/restocker_sat_synergy_contracts.md`](../technical/restocker_sat_synergy_contracts.md) · **Plan:** [`plan.md`](plan.md) · **Tareas:** [`tasks.md`](tasks.md)

**Esquema de referencia canónico:** `RF-01` clasificación L1 y guardas · `RF-02` triaje, despacho y auto-escalados · `RF-03` operativa móvil del reponedor y escalado al SAT · `RF-04` Coincidencia en Sede y relevo de turno · `RF-05` cola Offline, superficies y métricas.

**Corrida de certificación:** 2026-11-18 · PostgreSQL 16.15 local con TLS (`vendguard_pg_test` para el canal `new_engine` y `vendguard_battery` para la batería hermética) · canal `new_engine` 70/70 suites · batería global en verde · `DocMetricsGuard` coherente.

**Estado:** ✅ Módulo 36 cerrado · 12/12 tareas (`T-RSS-01` a `T-RSS-12`) · 10 suites propias del módulo con 480 aserciones verdes · migración `032_restocker_sat_synergy.sql` convergida en `supabase_init.sql` y `supabase_seeds.sql`.

---

## 1. Trazabilidad de Requisitos Funcionales

| Requisito | Criterios EARS | Implementación | Evidencia verificada |
| :--- | :--- | :--- | :--- |
| **RF-01** · Clasificación de Nivel 1, guardas constitucionales y taxonomía | `01.1` tipos #43/#44, columnas y CHECK; `01.2` pre-clasificación QR/Sede/Coordinación; `01.3` 4 parejas `RC-L1-*` / `SS-L1-*` | `IncidentDispatchLevel`, `Level1IssueType`, `Incident`, `RestockerSatSynergyValidator`, migración `032`, `supabase_init.sql`, `supabase_seeds.sql`, `PdoIncidentRepository`, `QrReportService` | `PostgresRestockerSatSynergySchemaTest` 28/28 (tipos, 56 columnas, cinco rechazos `23514`, índice, catálogo Offline, semillas y 49 tablas); `RestockerSatSynergyDomainModelsTest` 34/34 (espejo de la guarda y opacidad); `RestockerSatSynergyValidatorTest` 46/46 (mapeo determinista y exclusiones); `RestockerSatSynergyConstitutionalTest` 17/17 |
| **RF-02** · Triaje, despacho y auto-escalados | `02.1` sugerencia de reponedor y conmutación L1/L2; `02.2` auto-escalado en absorción QR; `02.3` promoción en reapertura < 48 h | `RestockerSatSynergyService`, `PdoRestockerSatSynergyGateway`, `RestockerZoneCoverageService`, `TechnicianAssignmentSuggester`, `PdoIncidentRepository::reopenWithLifecycleNotifications()`, `CoordinatorController` | `RestockerSatSynergyServiceTest` 34/34; `PdoRestockerSatSynergyGatewayTest` 24/24 (`suggestRestockers`, `assignDispatchLevel`, reasignación y replay); `RestockerSatSynergyIntegrationsTest` 27/27 (absorción QR y reapertura); `RestockerSatSynergyApiTest` 18/18 (sugerencia y conmutación HTTP); `CloudDeploySchemaParityTest` 55/55 |
| **RF-03** · Operativa móvil L1 y «⚡ Escalar Avería al SAT» | `03.1` inicio y resolución L1 con guarda sanitaria y textos mínimos; `03.2` escalado con nota/foto y tres ramas deterministas | `RestockerSatSynergyService`, `PdoRestockerSatSynergyGateway`, `RestockerRouteController`, `RestockerRouteView.js`, `RestockerIncidentOfflineQueue.js` | `PdoRestockerSatSynergyGatewayTest` 24/24 (las tres ramas bajo `FOR UPDATE`, custodia y auditoría); `RestockerSatSynergyServiceTest` 34/34; `RestockerSatSynergyApiTest` 18/18 (401/403, replay y volcado); `restocker_route_view.test.mjs` 234/234 (bandeja L1, inicio, resolución, nota/foto y cola Offline) |
| **RF-04** · Coincidencia en Sede y relevo de turno | `04.1` bloque `site_coincidence` bidireccional; `04.2` bloqueo `IN_PROGRESS` y relevo al cerrar turno, ausencia o baja | `RestockerSatSynergyGateway`, `RestockerSatSynergyService`, `ShiftLifecycleService`, `TechnicianAbsenceService`, `TechnicianRouteView.js`, `RestockerRouteView.js` | `PdoRestockerSatSynergyGatewayTest` 24/24 (`technicianCoincidences`, `restockerCoincidences`, `handoverLevel1`); `RestockerSatSynergyIntegrationsTest` 27/27 (relevo por turno y ausencia); `RestockerSatSynergyConstitutionalTest` 17/17 (unicidad de custodia); `technician_and_coordinator_synergy.test.mjs` 18/18 (banner sin filtrar el nivel) |
| **RF-05** · Cola Offline, superficies y métricas | `05.1` `client_mutation_id`, replay y volcado; `05.2` `RestockerRouteView` y `TechnicianRouteView`; `05.3` horas del reponedor, filtro y triaje dual | `offline_sync_mutations`, `RestockerIncidentOfflineQueue.js`, `RestockerRouteView.js`, `TechnicianRouteView.js`, `CoordinatorDashboardView.js`, `TechnicianWorkHoursService`, `CoordinatorController` | `PostgresRestockerSatSynergySchemaTest` 28/28 (`MACHINE` + 3 operaciones y rechazos); `RestockerSatSynergyApiTest` 18/18 (replay y conflicto); `restocker_route_view.test.mjs` 234/234; `technician_and_coordinator_synergy.test.mjs` 18/18; `RestockerShiftAndWorkHoursTest` 38/38 (intervenciones y tiempo en ruta del reponedor) |

---

## 2. Trazabilidad de Requisitos No Funcionales

| Requisito | Regla verificada | Evidencia |
| :--- | :--- | :--- |
| **RNF-01** · Integridad relacional y prohibición de borrado físico | 49 tablas (sin tablas nuevas), 44 tipos `ENUM`, disparadores `prevent_hard_delete()` e inmutabilidad de tramos cerrados | `PostgresRestockerSatSynergySchemaTest` 28/28; `PostgresConstitutionalTest` 86/86; `PostgresSchemaTest` 153/153 |
| **RNF-02** · Defensa en profundidad sanitaria y privacidad | CHECK L1/térmico-eléctrico en el motor; notas `is_internal = TRUE` ≥ 20 caracteres reales; fotos ≤ 5 MB por magic bytes; DTOs de Sede/QR sin metadatos L1 ni Coincidencia | `RestockerSatSynergyConstitutionalTest` 17/17; `RestockerSatSynergyValidatorTest` 46/46; `RestockerSatSynergyDomainModelsTest` 34/34; `technician_and_coordinator_synergy.test.mjs` 18/18 |
| **RNF-03** · Sistema de diseño y Dogma Vanilla | PHP 8.2+ tipado estricto y Vue 3 ES Modules sin bundlers; tokens `vg-*` y viewport móvil de 360 px sin scroll horizontal | `restocker_route_view.test.mjs` 234/234; `DesignTokenDebtRatchetTest.mjs` 3/3 (techo rebajado a 2.242 literales); `CoordinatorButtonVariantsGuardTest.mjs` 23/23; `MobileShellLayoutGuardTest.mjs` 7/7 |

---

## 3. Decisiones, Casos Límite y Artículos Constitucionales

| Referencia | Decisión o caso | Cobertura |
| :--- | :--- | :--- |
| **D1** | Nivel de despacho explícito en `incidents`, con `L2_SAT` como valor heredado | `PostgresRestockerSatSynergySchemaTest` 28/28; `RestockerSatSynergyDomainModelsTest` 34/34 |
| **D2** | Pre-clasificación determinista y cuatro exclusiones que fuerzan SAT | `RestockerSatSynergyValidatorTest` 46/46; `RestockerSatSynergyIntegrationsTest` 27/27; `RestockerSatSynergyApiTest` 18/18 |
| **D3** | Resolución L1 sin repuestos ni albarán A4 y escalado atómico en tres ramas | `PdoRestockerSatSynergyGatewayTest` 24/24; `RestockerSatSynergyServiceTest` 34/34; `restocker_route_view.test.mjs` 234/234 |
| **D4** | Coincidencia en Sede derivada de turno, zona y carga viva | `PdoRestockerSatSynergyGatewayTest` 24/24; `technician_and_coordinator_synergy.test.mjs` 18/18 |
| **C1** | Bloqueo `SHIFT_CLOSE_BLOCKED_IN_PROGRESS_L1` y relevo automático al suplente | `RestockerSatSynergyIntegrationsTest` 27/27; `RestockerSatSynergyGateway` 24/24 |
| **C2** | Idempotencia offline y atribución de horas L1 al reponedor | `RestockerSatSynergyApiTest` 18/18; `RestockerIncidentOfflineQueue` cubierto por `restocker_route_view.test.mjs` 234/234; `RestockerShiftAndWorkHoursTest` 38/38 |
| **QA-AMB-01** | Mapeo de los 4 motivos L1 y semillas de causas/soluciones | `RestockerSatSynergyValidatorTest` 46/46; `PostgresRestockerSatSynergySchemaTest` 28/28 |
| **QA-AMB-02** | Ampliación de autoría, custodia y operaciones Offline | `PostgresRestockerSatSynergySchemaTest` 28/28 |
| **QA-CON-01** | Coherencia rol/nivel y segregación de rutas | `RestockerSatSynergyValidatorTest` 46/46; `RestockerSatSynergyApiTest` 18/18; `RestockerRbacConstitutionalTest` 27/27 |
| **QA-CON-02** | Las intervenciones L1 del reponedor suman a su jornada | `RestockerShiftAndWorkHoursTest` 38/38; `RestockerSatSynergyIntegrationsTest` 27/27 |
| **QA-LIM-01** | Auto-escalado QR por frío, electricidad o reclamación grave | `RestockerSatSynergyIntegrationsTest` 27/27; `RestockerSatSynergyConstitutionalTest` 17/17 |
| **QA-LIM-02** | Promoción a SAT en reapertura < 48 h | `RestockerSatSynergyIntegrationsTest` 27/27; `LocationPortalReopenAuditTest` 9/9 |
| **QA-LIM-03** | Prohibición de `PENDING_PARTS` y repuestos en L1 | `PostgresRestockerSatSynergySchemaTest` 28/28; `RestockerSatSynergyValidatorTest` 46/46 |
| **QA-CONST-01** | Guarda Art. II en dominio y PostgreSQL; cuarentena bloquea la resolución | `RestockerSatSynergyConstitutionalTest` 17/17; `PostgresRestockerSatSynergySchemaTest` 28/28 |
| **QA-CONST-02** | Absorción idempotente sobre un SAT preexistente sin segundo ticket | `PdoRestockerSatSynergyGatewayTest` 24/24; `RestockerSatSynergyConstitutionalTest` 17/17 |
| **QA-CONST-03** | Opacidad de nivel, taxonomía L1, notas internas y Coincidencia | `RestockerSatSynergyConstitutionalTest` 17/17; `RestockerSatSynergyDomainModelsTest` 34/34; `technician_and_coordinator_synergy.test.mjs` 18/18 |
| **Art. II** | Primacía sanitaria: L1 jamás térmico/eléctrico y resolución bloqueada en cuarentena | CHECK `chk_incidents_l1_dispatch_coherence` y guardas de válidator/servicio, certificados por las suites de esquema y constitucional |
| **Art. III.1 / III.3** | Sin borrado físico; historia y auditoría append-only; conflicto Offline auditado | `PostgresConstitutionalTest` 86/86; `RestockerSatSynergyConstitutionalTest` 17/17 |
| **Art. IV** | Dogma Vanilla, 49 tablas, 44 tipos y cero dependencias de frontend | `PostgresSchemaTest` 153/153; `FrontendTemplateCompileTest.mjs` 15/15 |
| **Art. V.1 / V.2 / V.3** | Textos reales mínimos, un único ticket activo por máquina y custodia del técnico respetada | `RestockerSatSynergyValidatorTest` 46/46; `RestockerSatSynergyConstitutionalTest` 17/17 |
| **Art. V.4 / V.5 / V.6** | Opacidad de Sede/QR, evidencia validada y reapertura promovida | `RestockerSatSynergyConstitutionalTest` 17/17; `RestockerSatSynergyIntegrationsTest` 27/27 |

---

## 4. Evidencia de Certificación

| Puerta | Comando | Resultado |
| :--- | :--- | :--- |
| Batería global | `php tests/run_all.php` | **441 suites · 16.987 aserciones · 0 fallos** · `DocMetricsGuard` coherente · `exit=0` |
| Canal `new_engine` | `php tests/run_new_engine.php` | **70/70 suites · 2.281 aserciones · 0 no ejecutables · 0 fallos** · `exit=0` |
| Paridad de despliegue | `php tests/integration/CloudDeploySchemaParityTest.php` | 55/55 aserciones · `exit=0` |
| Residuo de esquemas temporales | Consulta a `pg_namespace` tras las suites | 0 esquemas `t_*` del Módulo 36 |

**Nota de cierre:** el desfase preexistente del libro de la batería (huella de la migración 031 anterior a `c8fc88d`) quedó resuelto al reconstruir `vendguard_battery` desde el DDL base y el libro actual; la ruta de despliegue y el esquema probado declaran ya los mismos 44 tipos `ENUM`, las mismas 49 tablas y las mismas 56 columnas de `incidents`. El techo de deuda cromática baja de 2.268 a 2.242 literales porque las superficies del módulo consumen tokens sin duplicar sus valores de respaldo.
