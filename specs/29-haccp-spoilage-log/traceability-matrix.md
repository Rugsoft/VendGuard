# MATRIZ DE TRAZABILIDAD · MÓDULO 29

**Módulo:** 29 · Acta de Merma y Destrucción de Lotes por Cuarentena Sanitaria (*HACCP Spoilage Log*)  
**Especificación:** [`spec.md`](spec.md) (v3.0) · [`../functional/haccp_spoilage_log_spec.md`](../functional/haccp_spoilage_log_spec.md)  
**Contratos técnicos:** [`../technical/haccp_spoilage_log_contracts.md`](../technical/haccp_spoilage_log_contracts.md) · [`../technical/database_schema.md`](../technical/database_schema.md) (Tabla 40)  
**Plan:** [`plan.md`](plan.md) · **Tareas:** [`tasks.md`](tasks.md)  
**Fecha de certificación:** 6 de noviembre de 2026

**Esquema de referencia:** `RF-01` precondiciones operativas, autoría in situ y techo térmico · `RF-02` declaración de unidades, lotes, máquina vacía, observaciones y fotografía · `RF-03` levantamiento atómico, origen determinista, relación `1..N` y desbloqueo de QR · `RF-04` segregación financiera y ajuste coherente · `RF-05` Acta Oficial A4 y panel de supervisión con CSV seguro.

---

## 1. Historias de Usuario

| Historia | Necesidad verificable | Artefactos que la realizan | Pruebas y evidencia |
| :--- | :--- | :--- | :--- |
| **HU-01** Levantamiento en línea de la cuarentena con Acta de Merma | Un Técnico activo (o Coordinación indicando al ejecutor) emite en línea un acta numerada sobre una máquina activa en `QUARANTINE` sin averías abiertas, con techo térmico por tipología, unidades y lotes reales o máquina vacía confirmada, fotografía verificada y observaciones con aviso de privacidad; el QR queda desbloqueado y se expide un nuevo certificado higiénico | `src/Application/Service/SanitarySpoilageService.php::liftQuarantineWithSpoilageRecord`; `src/Core/Service/SanitarySpoilageValidator.php`; `src/Infrastructure/Repository/PdoSanitarySpoilageRecordRepository.php::lockAndInspectMachineQuarantineState` / `hasOpenCorrectiveIncident` / `nextRecordCode` / `completePendingReinspectionAndRestoreMachineOk`; `src/Presentation/Controller/SanitarySpoilageController.php::lift`; `src/Presentation/Routing/AppRouter.php`; `public/assets/js/components/SanitarySpoilageLiftModal.js`; `public/assets/js/components/TechnicianRouteView.js`; `public/assets/js/components/TechnicianSanitaryQuarantineListModal.js`; migración `025` | `tests/integration/SanitarySpoilageServiceIntegrationTest.php` (§1 y §2); `tests/integration/SanitarySpoilageApiIntegrationTest.php` (§3–§5); `tests/unit/SanitarySpoilageValidatorTest.php`; `tests/unit/SanitarySpoilageFrontendTest.mjs`; `tests/integration/PostgresSanitarySpoilageSchemaTest.php` |
| **HU-02** Acta Oficial A4 para inspecciones de Sanidad | Cualquier Técnico activo o Coordinación abre e imprime el acta A4 con numeración oficial, sede, máquina, técnico certificador in situ, origen, temperaturas, unidades o máquina vacía, lotes, método, observaciones, fotografía y certificado, sin ningún importe económico, dejando rastro de cada lectura | `SanitarySpoilageRecord::toOfficialActArray`; `SanitarySpoilageService::getOfficialAct`; `SanitarySpoilageController::getOfficialAct`; `public/assets/js/components/SanitarySpoilageOfficialActModal.js`; `SanitarySpoilageService` (`logSpoilageAudit` con `VIEW_SANITARY_SPOILAGE_ACT`) | `SanitarySpoilageServiceIntegrationTest.php` (§6); `SanitarySpoilageApiIntegrationTest.php` (§2 y §6); `SanitarySpoilageFrontendTest.mjs` (A4 sin importes); `AuditActionCatalogTest.php` (acción catalogada) |
| **HU-03** Panel de mermas, ajuste económico y CSV seguro | Coordinación consulta cuatro KPIs inmunes al filtro, el bloque de máquinas en cuarentena y la tabla histórica determinista; ajusta el coste con justificación real solo en actas con unidades; exporta un CSV UTF-8 BOM neutralizado frente a fórmulas | `PdoSanitarySpoilageRecordRepository::fetchKpiSummary` / `fetchActiveQuarantinedMachines` / `findFilteredForCoordinator`; `SanitarySpoilageService::listForCoordinator` / `adjustLossEstimate` / `exportCsvForCoordinator`; `SanitarySpoilageController::listForCoordinator` / `adjustLossEstimate` / `exportCsv`; `public/assets/js/components/CoordinatorSanitarySpoilageTab.js`; `public/assets/js/components/CoordinatorPreventiveView.js`; `public/assets/js/api.js` | `SanitarySpoilageServiceIntegrationTest.php` (§4 y §5); `SanitarySpoilageApiIntegrationTest.php` (§1, §4 y §7); `SanitarySpoilageFrontendTest.mjs`; `SanitarySpoilageValidatorTest.php` (`validateLossAdjustment`, `validateDateRange`); `CoordinatorButtonVariantsGuardTest.mjs` |

---

## 2. Requisitos Funcionales y Criterios EARS

### RF-01 · Precondiciones operativas, autoría física y validación térmica

| Criterio | Artefacto de producción | Prueba automatizada | Resultado |
| :--- | :--- | :--- | :---: |
| **01.1** Máquina activa en cuarentena y sin averías correctivas abiertas (`D1`, `C1`, `QA 3`) | `SanitarySpoilageService::liftQuarantineWithSpoilageRecord` (`MACHINE_NOT_FOUND`, `MACHINE_INACTIVE`, `MACHINE_NOT_IN_QUARANTINE`, `ACTIVE_INCIDENT_BLOCKS_QUARANTINE_LIFT`); `PdoSanitarySpoilageRecordRepository::lockAndInspectMachineQuarantineState` (`FOR UPDATE`) y `hasOpenCorrectiveIncident` | `SanitarySpoilageServiceIntegrationTest.php` (§2: `ASSIGNED`/`IN_PROGRESS`/`PENDING_PARTS` bloquean y `RESOLVED` habilita); `SanitarySpoilageApiIntegrationTest.php` (§4: `404`, `409`); `PostgresSanitarySpoilageSchemaTest.php` (restricciones del estado) | ✅ |
| **01.2** Autoría del técnico in situ y operación estrictamente en línea (`C2`, `C3`, `QA 1`) | `SanitarySpoilageService` (resolución de `technician_id` frente a `registered_by`, `422 EXECUTING_TECHNICIAN_REQUIRED`); `PdoSanitarySpoilageRecordRepository::isActiveTechnician`; `SanitarySpoilageLiftModal.js` (selector visible para `COORDINATOR` y bloqueo con `!navigator.onLine`); `public/sw.js` (el acta no se encola en el paquete offline) | `SanitarySpoilageServiceIntegrationTest.php` (§1: técnico ejecutor y coordinador registrador, acta y `audit_log`); `SanitarySpoilageApiIntegrationTest.php` (§4: `422 EXECUTING_TECHNICIAN_REQUIRED`); `SanitarySpoilageFrontendTest.mjs` (bloqueo offline `C3`) | ✅ |
| **01.3** Rango físico plausible y techo de temperatura estabilizada por tipología (`Art. II`, `QA 3`) | `SanitarySpoilageValidator::validateCreation` (`INVALID_TEMPERATURE_RANGE`, `RESTORED_TEMPERATURE_EXCEEDS_SAFE_LIMIT`: 4,0 °C perecederos/mixtas · 8,0 °C bebidas frías · `[-5,0; +35,0]` no refrigeradas); restricción `chk_spoilage_records_temps_bounds` | `SanitarySpoilageValidatorTest.php` (4,0/4,1 · 8,0/8,1 · 22,0 y fuera de rango); `SanitarySpoilageApiIntegrationTest.php` (§4: `422 RESTORED_TEMPERATURE_EXCEEDS_SAFE_LIMIT`); `PostgresSanitarySpoilageSchemaTest.php` (`CHECK` de temperaturas) | ✅ |

### RF-02 · Declaración del producto, rigor textual y prueba gráfica

| Criterio | Artefacto de producción | Prueba automatizada | Resultado |
| :--- | :--- | :--- | :---: |
| **02.1** Unidades con lotes alfanuméricos frente a máquina vacía confirmada (`D3`, `QA 4`) | `SanitarySpoilageValidator` (`1..500` unidades, lotes `3..500` con bloque alfanumérico `[\p{L}\p{N}]{2,}`, `SIN_STOCK_AFECTADO` y `NOT_APPLICABLE_EMPTY` de oficio, métodos autorizados, `EMPTY_MACHINE_CONFIRMATION_REQUIRED`); restricciones `chk_spoilage_records_units_bounds` / `_empty_coherence` / `_batch_codes_len`; `SanitarySpoilageLiftModal.js` (rama `0` frente a `> 0`) | `SanitarySpoilageValidatorTest.php` (§2 y §3: `"---"`, `"..."`, método no aplicable con unidades); `SanitarySpoilageServiceIntegrationTest.php`; `SanitarySpoilageApiIntegrationTest.php` (§4); `PostgresSanitarySpoilageSchemaTest.php` (coherencia máquina vacía) | ✅ |
| **02.2** Fotografía obligatoria en el 100 % de las actas (`D3`, `Art. V.5`) | `SanitarySpoilageController::lift` (`multipart/form-data` + JSON, `LocalFileUploader` con `finfo` y tope de 5 MB); `SanitarySpoilageValidator::validateCreation` (`EVIDENCE_PHOTO_REQUIRED`); `chk_spoilage_records_photo_not_empty`; `SanitarySpoilageLiftModal.js` (selector obligatorio incluso con `0` unidades) | `SanitarySpoilageApiIntegrationTest.php` (§3: sin foto con `0` unidades, archivo falso renombrado, exceso de tamaño y PNG/JPEG real `201`); `SanitarySpoilageFrontendTest.mjs`; `LocalFileUploaderTest.php` | ✅ |
| **02.3** Observaciones de higienización con aviso de privacidad (`Art. V.1`, `Art. V.4`, `QA 4`) | `SanitarySpoilageValidator` (`20..1000` caracteres, ≥ 3 bloques alfanuméricos, `INVALID_SANITARY_NOTES`); `chk_spoilage_records_notes_len`; `SanitarySpoilageLiftModal.js` (aviso expreso de impresión en el Acta A4) | `SanitarySpoilageValidatorTest.php` (longitud y tokens reales); `SanitarySpoilageFrontendTest.mjs` (aviso `Art. V.4`); `SanitarySpoilageApiIntegrationTest.php` (§4) | ✅ |

### RF-03 · Levantamiento atómico, origen determinista e inmutabilidad

| Criterio | Artefacto de producción | Prueba automatizada | Resultado |
| :--- | :--- | :--- | :---: |
| **03.1** Acta numerada, origen del episodio, certificado y restitución en un solo acto (`D1`, `C6`, `QA 2`) | `SanitarySpoilageService` (`transactional()`, `resolveLatestQuarantineEpisodeLinks`, `nextRecordCode` `ACTA-MERMA-AAAA-NNNN`, `completePendingReinspectionAndRestoreMachineOk`, coste `2,50 €/ud.`, QR desbloqueado, auditoría bajo `SANITARY_SPOILAGE_RECORD`); `SanitarySpoilageRecord::toCoordinatorArray` | `SanitarySpoilageServiceIntegrationTest.php` (§1 y §3: origen por prioridad, código correlativo, certificado y estado `OK`); `SanitarySpoilageApiIntegrationTest.php` (§5); `PostgresSanitarySpoilageSchemaTest.php` (índice único de `record_code`) | ✅ |
| **03.2** Actas previas intactas ante reaperturas en garantía < 48 h (`Art. III.3`, `Art. V.6`, `QA 2`) | Relación `1..N` sin unicidad por máquina; `enforce_sanitary_spoilage_records_immutability()`; `trg_no_delete_sanitary_spoilage_records`; `trg_immutable_sanitary_spoilage_records`; claves foráneas `ON DELETE RESTRICT` | `SanitarySpoilageServiceIntegrationTest.php` (§3: `0001` intacta y `0002` correlativa); `PostgresSanitarySpoilageSchemaTest.php` (`DELETE` bloqueado, `UPDATE` de hechos rechazado con `SQLSTATE 45000`) | ✅ |

### RF-04 · Segregación financiera y ajuste coherente

| Criterio | Artefacto de producción | Prueba automatizada | Resultado |
| :--- | :--- | :--- | :---: |
| **04.1** Opacidad absoluta fuera de Coordinación (`D2`, `C4`, `Art. V.4`) | `SanitarySpoilageRecord::toPublicArray` / `toOfficialActArray` (sin `estimated_merchandise_loss_eur`, `loss_adjusted_by`, `loss_adjusted_by_name` ni `loss_adjustment_reason`); `SanitarySpoilageController` (RBAC por rol en los 6 endpoints); `SanitarySpoilageOfficialActModal.js`; `SanitarySpoilageLiftModal.js` | `SanitarySpoilageApiIntegrationTest.php` (§1 `401`/`403`, §2 `forbiddenKeys` incluso en el acta pedida por Coordinación, §6); `SanitarySpoilageFrontendTest.mjs`; `SanitarySpoilageValidatorTest.php` (segregación de serializaciones) | ✅ |
| **04.2** Coherencia contable, ajustes sucesivos y rechazo de cambios nulos (`D2`, `C5`, `QA 4`, `Art. V.1`) | `SanitarySpoilageValidator::validateLossAdjustment` (`EMPTY_MACHINE_LOSS_CANNOT_BE_ADJUSTED`, `INVALID_LOSS_ESTIMATE_AMOUNT`, `IDENTICAL_LOSS_ESTIMATE`, `INVALID_LOSS_ADJUSTMENT_REASON`); `SanitarySpoilageService::adjustLossEstimate` (`FOR UPDATE` y auditoría antes/después); `chk_spoilage_records_loss_non_negative` / `_adjustment_coherence`; `CoordinatorSanitarySpoilageTab.js` (modal y botón solo con unidades `> 0`) | `SanitarySpoilageValidatorTest.php` (§4); `SanitarySpoilageServiceIntegrationTest.php` (§4: sucesivos, `0` unidades e importe idéntico); `SanitarySpoilageApiIntegrationTest.php` (§4: `422`); `PostgresSanitarySpoilageSchemaTest.php` (`CHECK` de coherencia) | ✅ |

### RF-05 · Acta Oficial A4 y panel de supervisión

| Criterio | Artefacto de producción | Prueba automatizada | Resultado |
| :--- | :--- | :--- | :---: |
| **05.1** Contenido e impresión del Acta Oficial A4 (`C4`, `QA 1`, `QA 3`, `Art. V.4`) | `SanitarySpoilageRecord::toOfficialActArray`; `SanitarySpoilageService::getOfficialAct`; `SanitarySpoilageController::getOfficialAct`; `SanitarySpoilageOfficialActModal.js` (`window.print()`); acción `VIEW_SANITARY_SPOILAGE_ACT` | `SanitarySpoilageServiceIntegrationTest.php` (§6: lectura auditada); `SanitarySpoilageApiIntegrationTest.php` (§6); `SanitarySpoilageFrontendTest.mjs` (A4 completo y sin importes); `AuditActionCatalogTest.php` | ✅ |
| **05.2** Cuatro KPIs inmunes al filtro, orden determinista y CSV seguro (`QA 3`, `QA 4`) | `PdoSanitarySpoilageRecordRepository::fetchKpiSummary` / `fetchActiveQuarantinedMachines` (`internal_code ASC`) / `findFilteredForCoordinator` (`created_at DESC, id DESC`, rangos de día completo); `SanitarySpoilageService::exportCsvForCoordinator` (BOM, `;`, dos secciones, `csvCell` neutraliza `= + - @`, cabeceras con cero filas); `CoordinatorSanitarySpoilageTab.js` | `SanitarySpoilageServiceIntegrationTest.php` (§4 y §5); `SanitarySpoilageApiIntegrationTest.php` (§7: `text/csv`, BOM, neutralización); `SanitarySpoilageValidatorTest.php` (`validateDateRange`); `SanitarySpoilageFrontendTest.mjs` | ✅ |

---

## 3. Requisitos No Funcionales

| RNF | Compromiso | Artefacto | Evidencia |
| :--- | :--- | :--- | :--- |
| **RNF-01** Inmutabilidad y prohibición de borrado físico (`Art. III.1`, `Art. III.3`) | Ningún acta se elimina; los hechos sanitarios son perpetuos y solo el coste es ajustable con auditoría | Migración `025` (`trg_no_delete_sanitary_spoilage_records`, `enforce_sanitary_spoilage_records_immutability`); `supabase_init.sql`; `SanitarySpoilageService::adjustLossEstimate` (único `UPDATE`) | `PostgresSanitarySpoilageSchemaTest.php`; `SanitarySpoilageServiceIntegrationTest.php`; `SchemaConvergenceParityTest.php`; `ConstitutionalAuditTest.php` |
| **RNF-02** Seguridad de imágenes y exportación (`Art. V.5`, `QA 4`) | Tope de 5 MB, `finfo` sobre contenido real y neutralización de fórmulas en CSV | `LocalFileUploader`; `SanitarySpoilageController::lift`; `SanitarySpoilageService::csvCell` | `SanitarySpoilageApiIntegrationTest.php` (§3 y §7); `LocalFileUploaderTest.php`; `SanitarySpoilageServiceIntegrationTest.php` (§5) |
| **RNF-03** Privacidad por rol y sistema de diseño (`docs/design.md`, `Art. V.4`) | Cero importes al Técnico, a Sede y al QR/A4; botones `vg-btn`, radios 4/8 px, cero sombras en reposo y solo `var(--color-*)` | `CoordinatorSanitarySpoilageTab.js`; `SanitarySpoilageLiftModal.js`; `SanitarySpoilageOfficialActModal.js`; `public/assets/css/styles.css`; `AppRouter` | `SanitarySpoilageApiIntegrationTest.php` (§1–§2); `SanitarySpoilageFrontendTest.mjs`; `CoordinatorButtonVariantsGuardTest.mjs`; `LegacyButtonPaletteRetirementTest.mjs`; `DesignTokenDebtRatchetTest.mjs`; `OfflinePwaAppShellContractTest.mjs` |

---

## 4. Decisiones de Diseño (`D1`–`D3`)

| Decisión | Implementación | Pruebas |
| :--- | :--- | :--- |
| **D1** Acto explícito e independiente del cierre mecánico | `liftQuarantineWithSpoilageRecord` sobre `machines` + `machine_sanitary_settings` bajo `FOR UPDATE`; el cierre de la avería no toca `sanitary_status` | `SanitarySpoilageServiceIntegrationTest.php` (§2 y §3); `SanitarySpoilageApiIntegrationTest.php` (§4) |
| **D2** Coste automático por baremo y segregación financiera | `DEFAULT_UNIT_MERCHANDISE_COST_EUR = 2.50` en el validador; serializaciones por rol; ajuste exclusivo de Coordinación auditado | `SanitarySpoilageValidatorTest.php` (§3 y §4); `SanitarySpoilageServiceIntegrationTest.php` (§4); `SanitarySpoilageApiIntegrationTest.php` (§1, §2, §7) |
| **D3** Fotografía obligatoria incluso con `0` unidades | `EVIDENCE_PHOTO_REQUIRED` en el validador; selector obligatorio en el modal en ambas ramas; `chk_spoilage_records_photo_not_empty` | `SanitarySpoilageApiIntegrationTest.php` (§3); `SanitarySpoilageValidatorTest.php` (§2); `SanitarySpoilageFrontendTest.mjs` |

---

## 5. Casos Límite (`C1`–`C6`)

| Caso | Regla preservada | Implementación | Pruebas |
| :--- | :--- | :--- | :--- |
| **C1** Avería correctiva abierta | Bloqueo hasta resolver la avería mecánica | `hasOpenCorrectiveIncident`; `ACTIVE_INCIDENT_BLOCKS_QUARANTINE_LIFT`; chip de bloqueo en `CoordinatorSanitarySpoilageTab.js` y `TechnicianSanitaryQuarantineListModal.js` | `SanitarySpoilageServiceIntegrationTest.php` (§2); `SanitarySpoilageApiIntegrationTest.php`; `SanitarySpoilageFrontendTest.mjs` |
| **C2** Autorización transversal de Técnicos y Coordinación | Cualquier Técnico activo o Coordinador; Coordinación debe declarar ejecutor | `isActiveTechnician`; `technician_id` vs `registered_by`; `INTERNAL_ROLES` en el controlador; `GET /api/sanitary-quarantines/active` y `TechnicianSanitaryQuarantineListModal.js` para localizar cuarentenas ajenas a la ruta | `SanitarySpoilageServiceIntegrationTest.php` (§1); `SanitarySpoilageApiIntegrationTest.php` (§1, §1-bis y §4); `TechnicianRouteViewTest.mjs` |
| **C3** Operación estrictamente en línea | Sin envío diferido del levantamiento | `SanitarySpoilageLiftModal.js` (bloqueo con `!navigator.onLine`); el acta no forma parte del paquete offline | `SanitarySpoilageFrontendTest.mjs`; `OfflinePwaAssetsAndUiGuardTest.mjs` |
| **C4** Alcance del A4 y opacidad en Sede/QR | A4 para Técnico y Coordinación; Sede y QR solo ven el estado y el certificado | `toOfficialActArray`; `AppRouter` (roles); ausencia de actas en `LocationPortalView`, `QrReportView` y `SiteSanitaryStatusTab` | `SanitarySpoilageApiIntegrationTest.php` (§1–§2); `SanitarySpoilageFrontendTest.mjs`; `SiteSanitaryApiTest.php` |
| **C5** Coherencia entre unidades y coste | `0` unidades ⇒ `0,00 €` sin ajuste; `> 0` ⇒ importe `> 0` y justificado | `validateLossAdjustment`; `chk_spoilage_records_loss_non_negative`; `chk_spoilage_records_adjustment_coherence` | `SanitarySpoilageValidatorTest.php` (§4); `SanitarySpoilageServiceIntegrationTest.php` (§4); `PostgresSanitarySpoilageSchemaTest.php` |
| **C6** Restitución sin nuevas alertas | QR desbloqueado y certificado en el acto, sin correo ni mensajería | `completePendingReinspectionAndRestoreMachineOk`; `SanitarySpoilageService` (sin llamadas al despachador) | `SanitarySpoilageServiceIntegrationTest.php` (§3); `NotificationConstitutionalTest.php` |

---

## 6. Resoluciones de Auditoría (`QA 1`–`QA 4`)

| Resolución | Implementación | Pruebas |
| :--- | :--- | :--- |
| **QA 1** Autoría física in situ frente a registro de Coordinación (`Art. III.3`) | `technician_id` certificador y `registered_by` registrador; `audit_log.user_id` refleja al registrador con el técnico en `new_state`; `AuditEvent::ENTITY_SANITARY_SPOILAGE_RECORD` y constantes `ACTION_*` del servicio | `SanitarySpoilageServiceIntegrationTest.php` (§1); `AuditActionCatalogTest.php` (entidad y cuatro acciones catalogadas) |
| **QA 2** Origen determinista y relación `1..N` | `resolveLatestQuarantineEpisodeLinks` con la jerarquía reloj biológico → preventivo no conforme → bloqueo cautelar; ausencia de índice único por máquina | `SanitarySpoilageServiceIntegrationTest.php` (§3); `PostgresSanitarySpoilageSchemaTest.php` |
| **QA 3** Acceso técnico al A4, exclusión de bajas y techo por tipología | `getOfficialAct` para ambos roles internos; KPIs sobre máquinas activas y totales históricos; techos del validador | `SanitarySpoilageServiceIntegrationTest.php` (§4 y §6); `SanitarySpoilageApiIntegrationTest.php` (§6); `SanitarySpoilageValidatorTest.php` (§1) |
| **QA 4** Rigor alfanumérico, CSV neutro, orden y fechas deterministas, ajustes no nulos, aviso `Art. V.4` | `SanitarySpoilageValidator` (lotes, fechas); `csvCell`; `findFilteredForCoordinator`; `validateLossAdjustment`; aviso en el modal | `SanitarySpoilageValidatorTest.php`; `SanitarySpoilageServiceIntegrationTest.php` (§5); `SanitarySpoilageApiIntegrationTest.php` (§7); `SanitarySpoilageFrontendTest.mjs` |

---

## 7. Inventario de Pruebas del Módulo

| Suite | Canal | Cobertura |
| :--- | :--- | :--- |
| `tests/unit/SanitarySpoilageValidatorTest.php` (PHP Unit) | Batería global | Techos térmicos, unidades y lotes, máquina vacía, observaciones, ajuste y fechas |
| `tests/unit/SanitarySpoilageFrontendTest.mjs` (JS Unit) | Batería global | Estructura de los cuatro componentes, aviso `Art. V.4`, bloqueo `C3`, precaché en `sw.js` y guardas de diseño |
| `tests/integration/SanitarySpoilageServiceIntegrationTest.php` | `new_engine` (PostgreSQL real) | Levantamiento por Técnico y Coordinador, bloqueo `C1`, reapertura `1..N`, KPIs, ajustes y CSV |
| `tests/integration/SanitarySpoilageApiIntegrationTest.php` | `new_engine` (PostgreSQL real) | RBAC, opacidad, subida de fotografía, códigos de estado, A4 y exportación |
| `tests/integration/PostgresSanitarySpoilageSchemaTest.php` | `new_engine` (PostgreSQL real) | Tabla 40, índices, claves foráneas, `CHECK` y disparadores de inmutabilidad |
| `tests/integration/SchemaConvergenceParityTest.php` | `new_engine` (PostgreSQL real) | Convergencia de la migración `025` con `supabase_init.sql` y contrato de 40 tablas |
| `tests/unit/AuditActionCatalogTest.php` | Batería global | Entidad `SANITARY_SPOILAGE_RECORD` y las cuatro acciones del acta en el visor |

```bash
php tests/run_all.php                                   # batería hermética (unitaria, JS e integración)
source deploy/local/postgres/.env.battery               # contrato del motor del arnés
php tests/run_new_engine.php Spoilage                    # las tres suites PostgreSQL del módulo
```

---

## 8. Corrida de Certificación

**Corrida de certificación:** `php tests/run_all.php` ejecutó **400 suites · 14.977 aserciones · 0 fallos · 0 errores** el 6 de noviembre de 2026, con la cifra documentada coherente y sin desviaciones. Las suites herméticas del módulo (`SanitarySpoilageValidatorTest.php`, 67 aserciones, y `SanitarySpoilageFrontendTest.mjs`, 68 aserciones) forman parte de esa cifra global. Las tres suites PostgreSQL del módulo viajan en el canal `new_engine` (**3 suites · 135 aserciones · 0 fallos** con `php tests/run_new_engine.php Spoilage`), que pasó de **37 a 40 suites**.

Las cuatro acciones del acta (`CREATE_SPOILAGE_RECORD_AND_LIFT_QUARANTINE`, `VIEW_SANITARY_SPOILAGE_ACT`, `ADJUST_SPOILAGE_MERCHANDISE_LOSS`, `EXPORT_SPOILAGE_RECORDS_CSV`) se declaran como constantes del servicio y la entidad `SANITARY_SPOILAGE_RECORD` forma parte de `AuditEvent::ENTITY_TYPES`, de modo que el vocabulario persistido, el catálogo del visor y el enum `audit_entity_type` son el mismo conjunto.

> **Nota de alcance:** la migración `025` elevó el esquema a **40 tablas de dominio** (41 contando `schema_migrations`) y el canal `new_engine` pasó a **40 suites**. El Acta Oficial A4 se imprime desde el diálogo nativo del navegador; no se generan PDF binarios en servidor, conforme al apartado 7 de la especificación.
