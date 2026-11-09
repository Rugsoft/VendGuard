# Matriz de Trazabilidad · Módulo 30 · Perfiles de SLA Diferenciados por Tipo de Sede

**Módulo:** `30-tiered-sla-contracts`<br>
**Especificación de referencia:** [`spec.md`](spec.md) v2.0 (aprobada por el Product Owner el 2026-11-09)<br>
**Plan de referencia:** [`plan.md`](plan.md) v2.0<br>
**Contrato técnico:** [`specs/technical/tiered_sla_contracts_contracts.md`](../technical/tiered_sla_contracts_contracts.md) v1.0<br>
**Constitución:** [`constitution.md`](../../constitution.md) Arts. I–VII
**Estado:** ✅ Módulo 30 cerrado · batería global al 100 % en verde · 0 fallos · 0 desviaciones documentales

---

## 1. Nota de normalización de identificadores

La especificación funcional v2.0 consolida **cinco requisitos funcionales** (`RF-01` a `RF-05`) y **tres requisitos no funcionales** (`RNF-01` a `RNF-03`). El `plan.md` y el `tasks.md` conservan, por continuidad histórica, etiquetas de trabajo que llegan hasta `RF-07` y `RNF-04`.

Para que la trazabilidad sea verificable y no invente criterios que la especificación aprobada no contiene:

1. La **tabla principal** usa los identificadores canónicos de `spec.md` (`01.1`–`05.2`).
2. La **tabla de correspondencia** (§5) proyecta cada etiqueta heredada del plan (`01.1`–`07.5`) sobre los criterios canónicos que realmente la gobiernan.
3. `RNF-04` se conserva como etiqueta de plan para la coherencia visual y responsive; su contenido operativo se ancla a `docs/design.md` y a las guardas de diseño, no a un requisito adicional inexistente en `spec.md`.

> **Regla de interpretación:** cuando una etiqueta del plan y un criterio de la especificación difieren, prevalece `spec.md` (Art. I). La tabla de correspondencia deja el desvío documentado y auditado.

---

## 2. Trazabilidad funcional (criterios EARS de la especificación v2.0)

| Criterio EARS | Regla verificable | Implementación | Pruebas automatizadas |
| :--- | :--- | :--- | :--- |
| **01.1** | Catálogo cerrado de tres perfiles, `STANDARD` por defecto, rechazo de valores inválidos y auditoría solo si el perfil cambia. | `src/Core/Domain/ValueObject/SlaTier.php`; `src/Core/Domain/Model/Location.php`; `src/Application/Service/AdminLocationService.php`; `src/Infrastructure/Repository/PdoLocationRepository.php`; `src/Presentation/Controller/CoordinatorAdminController.php`; `public/assets/js/components/AdminLocationsTab.js`; migración `database/supabase_migrations/026_tiered_sla_contracts.sql`. | `tests/unit/TieredSlaDomainTest.php`; `tests/integration/TieredSlaContractsApiTest.php`; `tests/unit/TieredSlaFrontendTest.mjs`. |
| **01.2** | Techo sanitario innegociable de `240` min para `PERISHABLE_FOOD` y para `COMBO` con `TEMPERATURE_COLD` o `CRITICAL`. | `src/Core/Service/SlaTargetCalculator.php` (`isColdChainRisk()`, `calculateTargetMinutes()`); backfill de la migración `026`; `src/Core/Domain/Model/Incident.php`. | `tests/unit/TieredSlaDomainTest.php`; `tests/integration/PostgresTieredSlaSchemaTest.php`; `tests/integration/TieredSlaContractsApiTest.php`. |
| **01.3** | Matriz exacta `SlaTier × UrgencyLevel` para avisos sin riesgo de cadena de frío. | `src/Core/Service/SlaTargetCalculator.php`; `src/Core/Domain/ValueObject/SlaTier.php` (`contractualTargetMinutes()`); migración `026`; semillas. | `tests/unit/TieredSlaDomainTest.php` (12 combinaciones); `tests/integration/PostgresTieredSlaSchemaTest.php`. |
| **02.1** | Sellado inmutable del perfil, los minutos objetivo y el vencimiento al crear el aviso; inmunidad a cambios posteriores de la sede. | Migración `026`; función y disparador `trg_fn_incidents_protect_sla_tier_snapshot` / `trg_incidents_protect_sla_tier_snapshot`; `src/Infrastructure/Repository/PdoIncidentRepository.php`; `src/Core/Domain/Model/Incident.php`; `QrReportService.php`; `OpportunisticIncidentService.php`; `CoordinatorController.php`; `LocationPortalController.php`. | `tests/integration/PostgresTieredSlaSchemaTest.php`; `tests/integration/TieredSlaContractsApiTest.php` (C1 y QA 4); `tests/integration/SchemaConvergenceParityTest.php`. |
| **02.2** | Recálculo desde `created_at` al cambiar la prioridad en triaje o al absorber un reporte QR más restrictivo. | `src/Infrastructure/Repository/PdoIncidentRepository.php` (`assign()`); `src/Application/Service/QrReportService.php` (`mergeIntoExistingIncident()`); `Incident::withRecalculatedSlaTarget()`. | `tests/integration/TieredSlaContractsApiTest.php` (C4 y QA 1). |
| **02.3** | Desplazamiento del vencimiento por pausas imputables a la sede y descuento del intervalo cerrado en reaperturas `< 48 h` sin reiniciar `created_at`. | `src/Application/Service/IncidentPauseService.php` (calendario hábil del Módulo 11); `src/Infrastructure/Repository/PdoIncidentRepository.php` (`reopenWithLifecycleNotifications()`); `SlaTargetCalculator::computeEffectiveTargetAt()`. | `tests/integration/PendingInfoApiTest.php`; `tests/integration/PendingInfoConcurrencyTest.php`; `tests/integration/PendingInfoTechnicianEndpointsTest.php`; `tests/integration/TieredSlaContractsApiTest.php` (QA 3). |
| **02.4** | Backfill determinista del histórico y de los avisos abiertos. | Migración `026`; `database/supabase_init.sql`; `database/supabase_seeds.sql`; `src/Infrastructure/Database/SeedRunner.php`. | `tests/integration/PostgresTieredSlaSchemaTest.php`; `tests/integration/SchemaConvergenceParityTest.php`; `tests/integration/CloudDeploySchemaParityTest.php`. |
| **03.1** | Promoción al bloque `CRITICAL` con ventana efectiva `<= 240` min, incluidos los avisos que nacen con 4 h y los ya vencidos. | `src/Application/Service/RouteOptimizationService.php` (`summarizeGroup()`, `resolveSlaDeadline()`); `src/Core/Domain/Model/RouteStop.php`; `TechnicianRouteMapController.php`. | `tests/unit/TieredSlaDomainTest.php`; `tests/integration/TechnicianRouteMapApiTest.php`; `tests/unit/TieredSlaFrontendTest.mjs`. |
| **03.2** | Prelación sanitaria dentro de `CRITICAL`: `COLD_CHAIN` siempre antes que `CONTRACTUAL_SLA`; desempate por vencimiento, antigüedad e identificador de sede. | `RouteOptimizationService::compareCriticalStops()`; `RouteStop` (`critical_reason`, `is_cold_chain_critical`, `is_contractual_critical`). | `tests/unit/TieredSlaDomainTest.php`; `tests/integration/TechnicianRouteMapApiTest.php`. |
| **03.3** | Gobierno de la tarea correctiva más restrictiva en paradas multi-tarea; los preventivos no degradan la urgencia. | `RouteOptimizationService::summarizeGroup()`; `TechnicianRouteMapController::governingSlaTargetMinutes()`. | `tests/unit/TieredSlaDomainTest.php`; `tests/integration/TechnicianRouteMapApiTest.php`. |
| **04.1** | Escalado Contractual 24/7 con bono `+60`, activación sin efectivos en turno o sin efectivo libre ante objetivo de 240 min, y subordinación al `+100` sanitario. | `src/Core/Service/TechnicianWorkloadScorer.php`; `src/Application/Service/TechnicianAssignmentSuggester.php`; `SuggestedTechniciansService.php`; `public/assets/js/components/AssignSuggestionPanel.js`. | `tests/unit/TieredSlaDomainTest.php`; `tests/integration/TieredSlaContractsApiTest.php`; `tests/unit/TieredSlaFrontendTest.mjs`. |
| **04.2** | Saturación de zona con `>= 3` paradas vivas en objetivos `<= 480` min: menor carga y, a igualdad, menor distancia. | `TechnicianAssignmentSuggester.php`; `SuggestedTechniciansService.php`; `TechnicianWorkloadScorer.php`. | `tests/unit/TieredSlaDomainTest.php`; `tests/integration/TieredSlaContractsApiTest.php`. |
| **05.1** | Visibilidad en Coordinación, Técnico online/offline y Portal de Sede propia; opacidad total en QR y documentos A4. | `CoordinatorIncidentDetailService.php`; `TechnicianRouteMapController.php`; `PdoOfflineSyncMutationRepository.php`; `LocationPortalController.php`; `QrScanController.php`; `TechnicianRouteView.js`; `TechnicianRouteMapModal.js`; `public/assets/js/utils/OfflineStorage.js`; `LocationPortalView.js`; `ServiceSheetModal.js`; `SanitarySpoilageOfficialActModal.js`; `QrReportView.js`. | `tests/integration/TieredSlaContractsApiTest.php`; `tests/integration/OpportunisticSurfaceContractsTest.php`; `tests/unit/TieredSlaFrontendTest.mjs`. |
| **05.2** | Cómputo ticket a ticket de incumplimientos y desglose por sede en Métricas y CSV (`sla_tier`, `sla_tier_label`, `sla_target_hours`, `sla_breaches`, `sla_status`). | `src/Infrastructure/Repository/PdoMetricsRepository.php`; `MetricsCalculationService.php`; `MetricsExportService.php`; `MetricBreakdownTable.js`; `CoordinatorMetricsView.js`. | `tests/integration/TieredSlaContractsApiTest.php`; `tests/unit/TieredSlaFrontendTest.mjs`. |

---

## 3. Decisiones, casos límite y resoluciones QA

| Identificador | Decisión / caso / resolución | Implementación trazada | Evidencia |
| :--- | :--- | :--- | :--- |
| **D1** | Matriz `SlaTier × UrgencyLevel` con techo sanitario de 240 min. | `SlaTier`; `SlaTargetCalculator`; migración `026`; semillas. | `TieredSlaDomainTest`; `PostgresTieredSlaSchemaTest`. |
| **D2** | Sellado histórico inmutable en `incidents`. | `sla_tier_snapshot`; `sla_target_minutes`; `trg_incidents_protect_sla_tier_snapshot`. | `PostgresTieredSlaSchemaTest`; `TieredSlaContractsApiTest` (C1). |
| **D3** | Promoción `<= 4,0 h` con prelación sanitaria. | `RouteOptimizationService`; `RouteStop`; `TechnicianRouteMapController`. | `TieredSlaDomainTest`; `TechnicianRouteMapApiTest`; `TieredSlaFrontendTest`. |
| **D4** | Escalado contractual 24/7 (`+60`) y saturación `>= 3` paradas. | `TechnicianWorkloadScorer`; `TechnicianAssignmentSuggester`; `SuggestedTechniciansService`; `AssignSuggestionPanel`. | `TieredSlaDomainTest`; `TieredSlaContractsApiTest`; `TieredSlaFrontendTest`. |
| **D5** | Visibilidad por actor y opacidad QR/A4. | `CoordinatorIncidentDetailService`; `LocationPortalController`; `QrScanController`; `PdoOfflineSyncMutationRepository`; vistas y modales citados en 05.1. | `TieredSlaContractsApiTest`; `OpportunisticSurfaceContractsTest`; `TieredSlaFrontendTest`. |
| **D6** | Cumplimiento ticket a ticket en `by_location` y CSV. | `PdoMetricsRepository`; `MetricsCalculationService`; `MetricsExportService`; `MetricBreakdownTable`; `CoordinatorMetricsView`. | `TieredSlaContractsApiTest`; `TieredSlaFrontendTest`. |
| **C1** | Congelación de avisos abiertos e históricos ante cambios de contrato. | Snapshot + trigger + `AdminLocationService`; `PdoIncidentRepository`. | `TieredSlaContractsApiTest` (inmutabilidad y `QA 4`); `PostgresTieredSlaSchemaTest`. |
| **C2** | Efecto de pausas y reaperturas `< 48 h` sobre el vencimiento vivo y analítico. | `IncidentPauseService` (calendario hábil del Módulo 11); `PdoIncidentRepository::reopenWithLifecycleNotifications()`; `SlaTargetCalculator::computeEffectiveTargetAt()`. | `PendingInfoApiTest`; `PendingInfoConcurrencyTest`; `TieredSlaContractsApiTest` (QA 3). |
| **C3** | Parada multi-tarea gobernada por la correctiva más restrictiva; preventivos sin degradación. | `RouteOptimizationService::summarizeGroup()`; `TechnicianRouteMapController::governingSlaTargetMinutes()`. | `TieredSlaDomainTest`; `TechnicianRouteMapApiTest`. |
| **C4** | Recálculo desde `created_at` en triaje y absorción QR. | `PdoIncidentRepository::assign()`; `QrReportService::mergeIntoExistingIncident()`. | `TieredSlaContractsApiTest`. |
| **C5** | Default `STANDARD`, validación estricta y preservación del histórico. | Migración `026` (backfill); `AdminLocationService`; `SeedRunner`. | `PostgresTieredSlaSchemaTest`; `SchemaConvergenceParityTest`; `TieredSlaContractsApiTest`. |
| **C6** | Cómputo continuo de objetivos y exclusiones de calendarios laborales/penalizaciones. | `SlaTargetCalculator::computeEffectiveTargetAt()`; sellado y reapertura continuos; la pausa viva conserva el calendario comercial del Módulo 11 (compatibilidad aprobada). | `TieredSlaDomainTest`; `TieredSlaContractsApiTest`; `PendingInfoApiTest`. |
| **QA 1** | Blindaje del Art. II en `COMBO`, frontera inclusiva de 4 h y absorción QR. | `SlaTargetCalculator`; `RouteOptimizationService`; `QrReportService`. | `TieredSlaDomainTest`; `TieredSlaContractsApiTest`; `TechnicianRouteMapApiTest`. |
| **QA 2** | Reglas deterministas del escalado 24/7, bono `+60` y respaldo por carga/distancia. | `TechnicianWorkloadScorer`; `TechnicianAssignmentSuggester`; `SuggestedTechniciansService`. | `TieredSlaDomainTest`; `TieredSlaContractsApiTest`. |
| **QA 3** | Reapertura `< 48 h` sin reiniciar el reloj y objetivo nominal en el desglose por sede. | `PdoIncidentRepository::reopenWithLifecycleNotifications()`; `PdoMetricsRepository`; `MetricsCalculationService`; `MetricsExportService`. | `TieredSlaContractsApiTest`; `PendingInfoApiTest`. |
| **QA 4** | Auditoría idempotente al editar sede y opacidad en Albarán/Acta A4. | `AdminLocationService`; `CoordinatorIncidentDetailService`; `ServiceSheetModal`; `SanitarySpoilageOfficialActModal`; `QrScanController`. | `TieredSlaContractsApiTest`; `TieredSlaFrontendTest`; `OpportunisticSurfaceContractsTest`. |

---

## 4. Requisitos no funcionales

| RNF | Compromiso | Implementación | Pruebas |
| :--- | :--- | :--- | :--- |
| **RNF-01** | Determinismo y rendimiento del cálculo, la ruta, el ranking y las métricas. | `SlaTargetCalculator`; `RouteOptimizationService`; `TechnicianAssignmentSuggester`; `PdoMetricsRepository`. | `TieredSlaDomainTest`; `TieredSlaContractsApiTest`; `PendingInfoApiTest` (mediciones RNF-02 del Módulo 11). |
| **RNF-02** | Inmutabilidad histórica y prohibición de borrado físico. | `trg_incidents_protect_sla_tier_snapshot`; `prevent_hard_delete()` en las tablas del contrato. | `PostgresTieredSlaSchemaTest`; `ConstitutionalAuditTest`; `SchemaConvergenceParityTest`. |
| **RNF-03** | Coherencia visual: radios `4px`/`8px`, tokens CSS, alerta sanitaria diferenciada y responsive. | `public/assets/css/styles.css`; `AdminLocationsTab.js`; `AssignSuggestionPanel.js`; `MetricBreakdownTable.js`; `TechnicianRouteView.js`; `LocationPortalView.js`. | `TieredSlaFrontendTest`; `CoordinatorButtonVariantsGuardTest`; `LegacyButtonPaletteRetirementTest`; `DesignTokenDebtRatchetTest`; `OfflinePwaAssetsAndUiGuardTest`. |
| **RNF-04** *(etiqueta de plan)* | Compatibilidad responsive y sistema de diseño en las superficies del módulo. | Las mismas superficies de RNF-03; `docs/design.md` como fuente visual. | `TieredSlaFrontendTest`; guardas de diseño y PWA. |

---

## 5. Correspondencia con las etiquetas heredadas del plan (`01.1`–`07.5`)

| Etiqueta del plan / tareas | Criterios canónicos de `spec.md` | Cobertura real |
| :--- | :--- | :--- |
| **RF-01 · 01.1–01.3** | RF-01 · 01.1–01.3 | Catálogo, default, validación, auditoría, techo sanitario y matriz. |
| **RF-02 · 02.1–02.5** | RF-02 · 02.1–02.4 + RF-01.1 | Sellado, recálculo, pausas/reapertura y backfill; el subtramo 02.5 del plan se resuelve con la validación/auditoría de 01.1. |
| **RF-03 · 03.1–03.5** | RF-02 · 02.1–02.4 | Ciclo de vida del snapshot, absorción y recálculo; la etiqueta 03.x del plan no crea requisitos nuevos. |
| **RF-04 · 04.1–04.4** | RF-03 · 03.1–03.3 (+ RF-02.2 para absorción) | Ruta, prelación sanitaria, multi-tarea y promoción desde el segundo cero. |
| **RF-05 · 05.1–05.4** | RF-04 · 04.1–04.2 | Recomendador, escalado 24/7, bono `+60` y saturación `>= 3`. |
| **RF-06 · 06.1–06.4** | RF-05 · 05.2 | Métricas, desglose por sede, estado de cumplimiento y CSV. |
| **RF-07 · 07.1–07.5** | RF-05 · 05.1 (+ 05.2 en el Portal) | Visibilidad por actor, soporte offline, Portal de Sede y opacidad QR/A4. |

> **Resultado:** todos los criterios que el plan enumera como `01.1`–`07.5` quedan cubiertos por una regla de `spec.md` y por al menos una suite automatizada. Los identificadores `06.x` y `07.x` son etiquetas de plan, no secciones adicionales de la especificación v2.0.

---

## 6. Evidencia de pruebas

| Suite | Qué certifica |
| :--- | :--- |
| `tests/unit/TieredSlaDomainTest.php` | Matriz de 12 combinaciones, techo Art. II, `computeEffectiveTargetAt()`, scorer `+60`, ruta y recomendador. |
| `tests/integration/PostgresTieredSlaSchemaTest.php` | 25.º ENUM `sla_tier`, columnas, defaults, restricción, índices, trigger de inmutabilidad y backfill histórico. |
| `tests/integration/TieredSlaContractsApiTest.php` | CRUD de sedes, `422 INVALID_SLA_TIER`, C1, QA 4, recálculo C4, absorción QR, reapertura QA 3, métricas/CSV y opacidad QR. |
| `tests/unit/TieredSlaFrontendTest.mjs` | Superficies de Coordinación, Ruta, Offline y Portal; opacidad en QR y A4; guardas de diseño. |
| `tests/integration/TechnicianRouteMapApiTest.php` | Orden de ruta, prelación, consolidación multi-tarea, exclusión `PENDING_PARTS` y resumen. |
| `tests/integration/PendingInfoApiTest.php` | Pausa/reanudación con calendario hábil del Módulo 11, sin vencimientos inventados y con latencia acotada. |
| `tests/integration/OpportunisticSurfaceContractsTest.php` | Contrato saneado del Portal de Sede, incluidos los campos SLA permitidos y la opacidad de datos internos. |
| `tests/integration/CloudDeploySchemaParityTest.php` | Convergencia del DDL base y las 26 migraciones, incluida la guarda idempotente del ENUM y de la restricción. |
| `tests/unit/CoordinatorButtonVariantsGuardTest.mjs`, `LegacyButtonPaletteRetirementTest.mjs`, `DesignTokenDebtRatchetTest.mjs`, `OfflinePwaAssetsAndUiGuardTest.mjs` | Botones `vg-btn`, retirada de paletas heredadas, deuda de tokens y activos PWA. |

### Corrida de certificación

```text
Comando: php tests/run_all.php
Resultado: 403 suites · 15.125 aserciones · 0 fallos · 0 desviaciones documentales · exit 0
Fases: PHP Unit 174/174 · JS Unit 97/97 · Integración 130/130 · E2E 2/2
```

---

## 7. Cierre constitucional

| Artículo | Cómo lo respeta el Módulo 30 | Evidencia |
| :--- | :--- | :--- |
| **Art. I** | Sin mocks ni atajos: matriz, sellado, ruta, recomendador y métricas con lógica real y especificación aprobada. | Suites de dominio, integración y frontend. |
| **Art. II** | Techo de 240 min y prelación sanitaria en ruta; el escalado contractual nunca usa lenguaje ni prioridad de cadena de frío. | `TieredSlaDomainTest`; `TechnicianRouteMapApiTest`; `TieredSlaFrontendTest`. |
| **Art. III** | Snapshot inmutable, auditoría solo ante cambio efectivo y sin borrado físico. | `PostgresTieredSlaSchemaTest`; `TieredSlaContractsApiTest`. |
| **Art. IV** | PHP 8.2+ puro, PDO, Vue 3 ESM e IndexedDB; sin dependencias nuevas. | Inspección de `composer.json`/`package.json` y suites del proyecto. |
| **Art. V.4** | SLA visible solo para Coordinación, Técnico y la sede propietaria; opaco en QR y A4. | `TieredSlaContractsApiTest`; `OpportunisticSurfaceContractsTest`; `TieredSlaFrontendTest`. |
| **Art. V.6** | Reapertura `< 48 h` sin reiniciar `created_at`, descontando el intervalo cerrado. | `TieredSlaContractsApiTest` (QA 3). |
| **Art. VI** | Alcance del MVP: sin telemetría, inventario de furgonetas ni predicción. | Revisión de archivos del módulo. |
| **Art. VII** | Verificación global reproducible y cifras documentadas coherentes. | `php tests/run_all.php`; `DocMetricsGuard`. |

---

## 8. Observaciones de compatibilidad

1. **Pausas del Módulo 11:** el desplazamiento de `PENDING_INFO` conserva el calendario comercial `08:00–18:00, lunes–viernes` de `specs/11-pending-info-sla-pause/spec.md` (RF-03.3). El acumulador `total_pending_info_seconds` y la auditoría del intervalo siguen en segundos de pared, que es lo que descuenta el MTTR; el Módulo 30 mantiene el techo sanitario y el sellado sin romper la especificación previa ni sus 44 aserciones.
2. **Ruta de almacenamiento offline:** la tarea T-SLA-13 citaba `services/OfflineStorageService.js`; el adaptador canónico del repositorio es `public/assets/js/utils/OfflineStorage.js`, que es el archivo implementado y probado.
3. **Servicio oportunista:** la tarea T-SLA-08 citaba `OpportunisticInterventionService.php`; la clase real es `src/Application/Service/OpportunisticIncidentService.php`.
4. **Etiquetas del plan:** `RF-06`, `RF-07` y `RNF-04` se conservan en `plan.md`/`tasks.md` por continuidad, pero no sustituyen los criterios de `spec.md`; la correspondencia está en §5.
