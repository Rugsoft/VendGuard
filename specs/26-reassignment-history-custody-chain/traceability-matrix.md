# MATRIZ DE TRAZABILIDAD · MÓDULO 26

**Módulo:** 26 · Historial Completo de Reasignaciones y Cadena de Custodia
**Especificación:** [`spec.md`](spec.md) (v2.1) · **Contratos:** [`../technical/reassignment_history_custody_chain_contracts.md`](../technical/reassignment_history_custody_chain_contracts.md)
**Plan:** [`plan.md`](plan.md) · **Tareas:** [`tasks.md`](tasks.md)
**Fecha de certificación:** 2 de noviembre de 2026

**Esquema de referencia:** `RF-01` registro íntegro de tramos · `RF-02` reasignación justificada · `RF-03` proyección y retención · `RF-04` lectura en la ficha y refresco · `RF-05` cierre en todas las vías operativas.

---

## 1. Requisitos funcionales (EARS)

| Criterio | Artefacto de código | Test que lo cubre | Resultado |
|---|---|---|---|
| **01.1** Un único custodio activo por incidencia | `022_incident_assignments_custody_chain.sql` (`uq_incident_assignments_active_custody`); `PdoIncidentRepository::openCustodySegmentInTransaction` | `CustodyChainConstitutionalTest.php` 3.1–3.3; `PostgresSchemaTest` | ✅ |
| **01.2** Coherencia todo-o-nada del cierre y suelo temporal | `chk_incident_assignments_closure_coherence`; `closeActiveCustodySegmentInTransaction` (`GREATEST(assigned_at, …)`) | `CustodyChainApiTest.php` 5.1; `CustodyChainConstitutionalTest.php` 2.2 | ✅ |
| **01.3** Inmutabilidad de apertura, snapshots y tramos cerrados | `trg_immutable_incident_assignments`; `enforce_incident_assignments_immutability()` | `CustodyChainConstitutionalTest.php` 2.1–2.3 | ✅ |
| **01.4** Canal de entrada tipificado (9 valores) | `IncidentAssignmentSource`; `assignment_source` | `IncidentAssignmentTest.php`; `CustodyChainApiTest.php` 2.2, 4.2 | ✅ |
| **01.5** Snapshots congelados de técnico y autores | Columnas `*_snapshot`; apertura y cierre en `PdoIncidentRepository` | `CustodyChainConstitutionalTest.php` 2.1; `CustodyChainApiTest.php` 2.2 | ✅ |
| **02.1** Motivo ≥ 10 caracteres reales al reasignar | `ReassignmentReasonValidator::normalizeAndValidate`; `CoordinatorController::assignTechnician` | `ReassignmentReasonValidatorTest.php` (16/16); `CustodyChainApiTest.php` 3.1, 3.3, 4.3 | ✅ |
| **02.2** Independencia de la salvaguarda sanitaria de 20 | `ColdChainOverridePolicy`; `CoordinatorController` (dos puertas y dos códigos) | `CustodyChainApiTest.php` 3.5; `ColdChainOverrideJustificationTest.php` | ✅ |
| **02.3** Rechazo de auto-reasignación | `CoordinatorController` (`TECHNICIAN_ALREADY_ASSIGNED`); `PdoIncidentRepository::assign` | `CustodyChainApiTest.php` 3.4 | ✅ |
| **02.4** Conservación del borrador ante colisión | `CoordinatorIncidentDetailModal.js` (`fetchDetail({ preserveAssignDrafts })`) | `FrontendTemplateCompileTest.mjs`; revisión de plantilla | ✅ |
| **03.1** Retención continua por tramo | `CoordinatorIncidentDetailService::buildCustodyChain` | `CoordinatorIncidentDetailServiceTest.php` 9.2 (89/89) | ✅ |
| **03.2** Reasignaciones solo por cierres sin resolver | `IncidentUnassignmentReasonType::isUnresolvedTransferOrRelease` | `IncidentAssignmentTest.php`; `CoordinatorIncidentDetailServiceTest.php` 9.1, 5.2 | ✅ |
| **03.3** Huecos `UNASSIGNED_GAP` > 60 s | `buildUnassignedGaps` | `CoordinatorIncidentDetailServiceTest.php` 9.4–9.7 | ✅ |
| **03.4** Exclusión del intervalo `RESOLVED` previo a reapertura | `buildUnassignedGaps` (recorte por `reopened_at`) | `CoordinatorIncidentDetailServiceTest.php` 9.7 | ✅ |
| **03.5** Fallback determinista de expedientes históricos | `synthesizeFallbackAssignments` | `CoordinatorIncidentDetailServiceTest.php` 9.9–9.10; `CustodyChainApiTest.php` 6.1 | ✅ |
| **03.6** Cierre por cancelación sin técnico | `buildCustodyChain` (`is_empty`, `empty_message`) | `CoordinatorIncidentDetailServiceTest.php` 9.8; `CustodyChainApiTest.php` 1.1–1.2 | ✅ |
| **04.1** Sección permanente en la ficha de Coordinación | `CoordinatorIncidentDetailModal.js` (`incident-custody-chain-section`) | `DesignTokenDebtRatchetTest.mjs`; `FrontendTemplateCompileTest.mjs` | ✅ |
| **04.2** KPIs de resumen e hilo intercalado | `CoordinatorIncidentDetailModal.js` (`custody-kpi-*`, `custody-timeline`) | Revisión de plantilla; `DesignTokenDebtRatchetTest.mjs` | ✅ |
| **04.3** Etiquetas de auditoría de custodia | `AuditActionLabels.js` (`INCIDENT_CUSTODY_ASSIGNED`, `INCIDENT_CUSTODY_REASSIGNED`) | `AuditActionCatalogTest.php`; `DesignTokenDebtRatchetTest.mjs` | ✅ |
| **05.1** Cierre en resolución, cancelación y reapertura | `PdoIncidentRepository::resolve` / `cancel` / `reopen` | `CustodyChainApiTest.php` 5.1; `PdoIncidentRepositoryTest.php` | ✅ |
| **05.2** Relevo de turno con autoría humana | `ShiftHandoverService::executeClose` (`$closedBy`) | `ShiftHandoverSanitaryTest.php`; `ShiftHandoverConcurrencyTest.php` | ✅ |
| **05.3** Absorción oportunista y guardia sanitaria | `PdoIncidentRepository` (fuentes `OPPORTUNISTIC_*`, `SANITARY_FAILSAFE_*`) | `OpportunisticIncidentApiIntegrationTest.php`; `ShiftHandoverSanitaryTest.php` | ✅ |
| **05.4** Sellado defensivo al reabrir o retirar un crónico | `reopenWithLifecycleNotifications`; `replaceChronicOpportunisticIncident` | `OpportunisticIncidentApiIntegrationTest.php`; `PdoIncidentRepositoryTest.php` | ✅ |

## 2. Requisitos no funcionales

| Criterio | Artefacto | Verificación | Resultado |
|---|---|---|---|
| **RNF-01** Lectura agregada en una sola transacción | `PdoIncidentRepository::findEnrichedDetailById` (incluye `assignments`) | `CoordinatorIncidentDetailApiTest.php`; `CustodyChainApiTest.php` | ✅ |
| **RNF-02** Índices de línea de tiempo y cartera | `idx_incident_assignments_incident_timeline`, `idx_incident_assignments_technician` | `PostgresSchemaTest.php` | ✅ |
| **RNF-03** Fidelidad al Sistema de Diseño | `CoordinatorIncidentDetailModal.js` con tokens `var(--…)`, radios 4/8 px y tipografía dual | `DesignTokenDebtRatchetTest.mjs` (3/3, 2.268 hex, techo sin subir) | ✅ |
| **RNF-04** Dogma Vanilla y motor único | PHP 8.2 puro, PDO `pgsql`, Vue 3 ESM | `SqlDialectPortabilityTest.php`; `SchemaConvergenceParityTest.php`; `SingleEngine*` (censo de dialecto) | ✅ |

## 3. Decisiones y casos límite

| Decisión | Dónde se realiza | Test |
|---|---|---|
| **D1** Cadena en las cuatro vías y cierre al desasignar/finalizar | `PdoIncidentRepository`, `ShiftHandoverService`, servicios oportunistas, `SparePartTraceabilityService` | `CustodyChainApiTest.php`; `OpportunisticIncidentApiIntegrationTest.php` |
| **D2** Canal, causa e identidades congeladas | Columnas `*_snapshot` y enums del esquema 022 | `CustodyChainConstitutionalTest.php` 2.1 |
| **D3** Exclusividad para Coordinación y opacidad | `CoordinatorIncidentDetailDto` (único artefacto con `custody_chain`) | `CustodyChainConstitutionalTest.php` 4.1–4.4 |
| **D4** Retención continua, conectores y continuidad histórica | `buildCustodyChain`, `buildUnassignedGaps`, `synthesizeFallbackAssignments` | `CoordinatorIncidentDetailServiceTest.php` 9.1–9.10 |

| Resolución | Dónde se cumple | Test |
|---|---|---|
| **R1** 10 caracteres reales y borrador preservado | `ReassignmentReasonValidator`; modal (`fetchDetail` con opciones) | `CustodyChainApiTest.php` 3.1–3.3; `ReassignmentReasonValidatorTest.php` |
| **R2** Contador sin doble cómputo | `isUnresolvedTransferOrRelease` | `CoordinatorIncidentDetailServiceTest.php` 9.1, 5.2 |
| **R3** Tramo único durante pausas | `IncidentPauseService` no escribe en `incident_assignments` | `CoordinatorIncidentDetailServiceTest.php`; `IncidentPauseServiceTest.php` |
| **R4** Auto-reasignación bloqueada y colisión sin pérdida de texto | Controlador + modal | `CustodyChainApiTest.php` 3.4; `FrontendTemplateCompileTest.mjs` |
| **R5** Explicación garantizada en todo cierre | `closeActiveCustodySegmentInTransaction` (motivo por defecto) | `CustodyChainConstitutionalTest.php` 2.2; `CoordinatorIncidentDetailServiceTest.php` |
| **R6** Sección siempre visible e inmutabilidad | Modal (`custody-empty-state`) y disparadores | `CoordinatorIncidentDetailServiceTest.php` 9.8; `CustodyChainConstitutionalTest.php` 1.1–1.2 |

## 4. Resoluciones QA

| Resolución | Evidencia | Resultado |
|---|---|---|
| **QA 1** Validación real, independencia sanitaria y auditoría antes/después | `ReassignmentReasonValidatorTest` 16/16; `CustodyChainApiTest` 2.3, 4.5; `CustodyChainConstitutionalTest` 2.1 | ✅ |
| **QA 2** Fórmula única de reasignaciones y extremo de huecos | `CoordinatorIncidentDetailServiceTest` 9.1, 9.4, 9.7–9.8 | ✅ |
| **QA 3** Autoría nominal, diagnóstico y cancelación por inactividad | `ShiftHandoverService` (`$closedBy` + motivo administrativo); `PdoIncidentRepository::resolve` / `cancel` | ✅ |
| **QA 4** Suelo temporal y sellado defensivo | `GREATEST` en cierre de tramo; `CustodyChainApiTest` 5.1; disparador de coherencia | ✅ |
| **QA 5** Opacidad y fallback histórico | `CustodyChainConstitutionalTest` 4.1–4.4; `CustodyChainApiTest` 6.1 | ✅ |

## 5. Blindajes constitucionales

| Artículo | Garantía | Evidencia |
|---|---|---|
| **Art. II** | Guardia sanitaria y salvaguarda de 20 caracteres independiente del motivo de custodia | `ColdChainOverrideJustificationTest.php`; `CustodyChainApiTest.php` 3.5 |
| **Art. III.1** | Cero borrado físico de tramos | `trg_no_delete_incident_assignments`; `CustodyChainConstitutionalTest.php` 1.1–1.2 |
| **Art. III.3** | Tramos cerrados y snapshots inmutables; auditoría con estado previo y nuevo | `trg_immutable_incident_assignments`; `CustodyChainConstitutionalTest.php` 2.1–2.3; `CustodyChainApiTest.php` 2.3, 4.5 |
| **Art. V.1** | Justificaciones reales con contenido alfanumérico | `ReassignmentReasonValidatorTest.php`; `CustodyChainApiTest.php` 3.1, 3.3 |
| **Art. V.3** | Un único custodio activo y suelo temporal | Índice parcial único; `CustodyChainConstitutionalTest.php` 3.1–3.3 |
| **Art. V.4** | `custody_chain` exclusivo de Coordinación | `CustodyChainConstitutionalTest.php` 4.1–4.4 |
| **Art. VI** | Sin funcionalidad fuera del MVP | Alcance revisado tarea a tarea |

---

**Conclusión:** los cinco requisitos funcionales, los cuatro no funcionales, las cuatro decisiones, las seis resoluciones de casos límite, las cinco resoluciones QA y los artículos constitucionales aplicables quedan cubiertos por código y por una prueba verificable. La batería global se ejecuta como cierre en `tasks.md` (T-CUSTODY-14).
