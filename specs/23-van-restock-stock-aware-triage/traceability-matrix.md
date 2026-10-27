# MATRIZ DE TRAZABILIDAD · MÓDULO 23

**Especificación:** [`spec.md`](spec.md) (v2.1) · **Espejo funcional:** [`../functional/van_restock_stock_aware_triage_spec.md`](../functional/van_restock_stock_aware_triage_spec.md) · **Contratos:** [`../technical/van_restock_stock_aware_triage_contracts.md`](../technical/van_restock_stock_aware_triage_contracts.md) · **Plan:** [`plan.md`](plan.md) · **Tareas:** [`tasks.md`](tasks.md)

**Esquema de referencia canónico:** `RF-01` ciclo de vida e integridad · `RF-02` propuesta y lote · `RF-03` A4, despacho y cancelación · `RF-04` recepción móvil e insignia en averías · `RF-05` cruce de stock en triaje · `RF-06` privacidad y roles.

**Corrida de certificación:** 2026-10-27 (PostgreSQL local `new_engine` bajo TLS).

---

## 1. Requisitos Funcionales y criterios EARS

### RF-01 · Ciclo de vida, baja lógica de líneas y reglas de integridad

| Criterio | Artefacto de código | Test que lo cubre | Resultado medido |
|---|---|---|---|
| **01.1** Unicidad de orden abierta (D3) | `VanRestockOrderService::createOrder`, `PdoVanRestockOrderRepository` | `VanRestockOrderServiceTest` 1.9 · `PostgresVanRestockSchemaTest` «segunda orden `PENDING_PICKING` → 23505» y «`DISPATCHED` también ocupa el hueco» · `VanRestockOrderApiTest` 5.1, 11.1, 11.5 | 74/74 · 19/19 · 59/59 ✅ |
| **01.2** Bloqueo de inactivos y aviso en ausentes (QA 4) | `VanRestockOrderService` (elegibilidad), `PdoUserRepository` | `VanRestockOrderServiceTest` 1.7, 1.8, 1.16 · `VanRestockOrderApiTest` 3.4 · `VanRestockUiComponentsTest.mjs` 2.4, 3.14 | 74/74 · 59/59 · 52/52 ✅ |
| **01.3** Validación de líneas y tope doble 9.999 | `VanRestockOrderService::assertLines`, `VanRestockOrder` | `VanRestockOrderServiceTest` 1.10–1.15 · `VanRestockOrderProposalTest` 1.6, 1.9, 4.5, 4.6, 6.9 · `VanRestockUiComponentsTest.mjs` 2.6 | 74/74 · 53/53 · 52/52 ✅ |
| **01.4** Prohibición de borrado físico + baja lógica y reactivación (Art. III.1, QA 1) | `PdoVanRestockOrderRepository` (`removed_at`/`removed_by`), migración `016` | `VanRestockOrderServiceTest` 3.3, 3.4, 3.6, 3.7 · `VanRestockOrderApiTest` 6.2, 6.3 · `PostgresVanRestockSchemaTest` («`DELETE` rechazado con 45000», «reactivar devuelve a `NULL`», `chk_van_restock_items_removed_consistency`) · `VanRestockUiComponentsTest.mjs` 2.10 | 74/74 · 59/59 · 19/19 · 52/52 ✅ |

### RF-02 · Propuesta de reposición, ordenación y emisión individual o en lote

| Criterio | Artefacto de código | Test que lo cubre | Resultado medido |
|---|---|---|---|
| **02.1** Fórmula aditiva y motivo de línea (D4, QA 1, QA 3) | `VanRestockOrderProposal`, `PdoVanRestockOrderRepository` | `VanRestockOrderProposalTest` 1.1–1.12, 2.1–2.7, 3.1–3.5 | 53/53 ✅ |
| **02.2** Ordenación determinista de líneas y órdenes (QA 5) | `VanRestockOrderProposal::sortLines`, `PdoVanRestockOrderRepository::findAllForCoordinator` | `VanRestockOrderProposalTest` 4.2, 5.1–5.6 · `VanRestockOrderApiTest` 11.5 | 53/53 · 59/59 ✅ |
| **02.3** Furgoneta sin déficit y alta manual (C4) | `VanRestockOrderProposal` (aviso), `CoordinatorVanStockTab` | `VanRestockOrderProposalTest` 4.3, 4.4 · `VanRestockOrderApiTest` 4.3 · `VanRestockUiComponentsTest.mjs` 2.12 | 53/53 · 59/59 · 52/52 ✅ |
| **02.4** Generación en lote con omisiones (D4, C4, QA 4) | `VanRestockOrderService::generateBatch` | `VanRestockOrderServiceTest` 2.1–2.12 · `VanRestockOrderApiTest` 4.1–4.4 · `VanRestockUiComponentsTest.mjs` 3.8 | 74/74 · 59/59 · 52/52 ✅ |

### RF-03 · Hoja de Carga A4, despacho y cancelación auditada

| Criterio | Artefacto de código | Test que lo cubre | Resultado medido |
|---|---|---|---|
| **03.1** A4 con las diez columnas y sin costes (D4, Art. V.4) | `CoordinatorVanStockTab::renderPickingSlip`, `styles.css` (`@media print`) | `VanRestockUiComponentsTest.mjs` 3.9–3.13, 3.12 · `VanRestockOrderApiTest` 5.3, 5.4, 12.3 | 52/52 · 59/59 ✅ |
| **03.2** Despacho con cantidades reales (D1, QA 1) | `VanRestockOrderService::dispatchOrder` | `VanRestockOrderServiceTest` 4.1–4.5 · `VanRestockOrderApiTest` 7.1, 7.2 · `PostgresVanRestockSchemaTest` «`DISPATCHED` con su actor e instante» · `VanRestockUiComponentsTest.mjs` 3.1–3.3 | 74/74 · 59/59 · 19/19 · 52/52 ✅ |
| **03.3** Alerta de destinatario ausente o inactivo en órdenes abiertas (C2, QA 4) | `VanRestockOrder::hasRecipientAlert`, `CoordinatorVanStockTab` | `VanRestockUiComponentsTest.mjs` 3.14, 2.4 · `VanRestockOrderApiTest` 3.4 · `VanRestockOrderServiceTest` 1.16 | 52/52 · 59/59 · 74/74 ✅ |
| **03.4** Cancelación con ≥ 20 caracteres reales (Art. V.1) | `VanRestockOrderService::cancelOrder`, `chk_van_restock_orders_cancel_consistency` | `VanRestockOrderServiceTest` 4.6–4.10 · `VanRestockOrderApiTest` 11.2–11.6 · `PostgresVanRestockSchemaTest` («19 caracteres → 23514», «20 caracteres se acepta») · `VanRestockUiComponentsTest.mjs` 3.4–3.7 | 74/74 · 59/59 · 19/19 · 52/52 ✅ |

### RF-04 · Recepción móvil en 1 toque, comprobante e insignia en averías

| Criterio | Artefacto de código | Test que lo cubre | Resultado medido |
|---|---|---|---|
| **04.1** Visibilidad móvil propia y comprobante de última carga (QA 5) | `TechnicianVanStockController::getInventory`, `TechnicianVanStockPanel` | `VanRestockOrderApiTest` 8.1, 8.3, 8.4, 8.8 · `VanRestockUiComponentsTest.mjs` 4.1, 4.2 | 59/59 · 52/52 ✅ |
| **04.2** Confirmación atómica sin restricción de turno (D1, D3, C3, QA 4) | `VanRestockOrderService::confirmReception` | `VanRestockOrderServiceTest` 5.1–5.11 · `VanRestockOrderApiTest` 8.6–8.13, 13.3 · `VanRestockUiComponentsTest.mjs` 4.3, 4.6 · `PostgresVanRestockSchemaTest` «cuatro marcas de rama» | 74/74 · 59/59 · 52/52 · 19/19 ✅ |
| **04.3** Insignia de disponibilidad en averías activas (C5, QA 2, QA 3) | `VanStockReadinessCalculator` | `VanStockReadinessCalculatorTest` 1.1–6.6 · `VanRestockOrderApiTest` 9.1–9.4 · `VanRestockUiComponentsTest.mjs` 5.1, 5.2 | 29/29 · 59/59 · 52/52 ✅ |

### RF-05 · Cruce informativo de stock en el recomendador de triaje

| Criterio | Artefacto de código | Test que lo cubre | Resultado medido |
|---|---|---|---|
| **05.1** Inmutabilidad de la puntuación, Art. II y regla de justificación (D2, QA 2) | `TechnicianAssignmentSuggester` | `TechnicianAssignmentSuggesterVanStockTest` 3.1–3.6 · `VanRestockOrderApiTest` 10.1–10.4 · `SuggestedTechniciansApiTest` 2.5, 2.6 | 36/36 · 59/59 · 24/24 ✅ |
| **05.2** Nivel 1 — piezas solicitadas (QA 2) | `TechnicianAssignmentSuggester::buildVanStockMatch` | `TechnicianAssignmentSuggesterVanStockTest` 1.1–1.11 · `VanRestockOrderApiTest` 2.1 · `VanRestockUiComponentsTest.mjs` 5.1, 5.2 | 36/36 · 59/59 · 52/52 ✅ |
| **05.3** Nivel 2 — compatibilidad por modelo y familia | `TechnicianAssignmentSuggester` (mapa de categorías) | `TechnicianAssignmentSuggesterVanStockTest` 2.1–2.4 · `VanRestockOrderApiTest` 2.2 | 36/36 · 59/59 ✅ |

### RF-06 · Privacidad y segregación de roles (Art. V.4)

| Criterio | Artefacto de código | Test que lo cubre | Resultado medido |
|---|---|---|---|
| **06.1** Control de acceso por rol | `InternalAuthMiddleware`, `AppRouter`, `TechnicianVanStockController` | `VanRestockOrderApiTest` 1.1–1.5, 12.1, 8.4 · `VanStockPrivacyTest` · `SuggestedTechniciansApiTest` 1.1, 1.2 | 59/59 · 9/9 · 24/24 ✅ |
| **06.2** Opacidad total hacia la sede cliente | `LocationPortalController` (claves vedadas) | `VanRestockOrderApiTest` 12.2, 12.3 · `VanStockPrivacyTest` 1.x–3.x · `SiteManagerPartsDataSegregationTest` | 59/59 · 9/9 ✅ |

---

## 2. Requisitos No Funcionales

| RNF | Artefacto | Test | Resultado medido |
|---|---|---|---|
| **RNF-01** < 200 ms triaje y < 250 ms móvil | `PdoSparePartRequestRepository::findPendingSummaryByIncidentIds`, `PdoVanStockRepository::findQuantityMapByTechnicianIds` | `SuggestedTechniciansApiTest` 5.2 (triaje, mediana < 100 ms) · `VanStockReadinessPerformanceTest` 4.1 y 4.2 (mediana móvil) | ✅ 24/24 · 7/7 — rango de medianas en 5 corridas: triaje **15,7–16,6 ms** (límite 200 ms), consulta móvil **3,7–4,5 ms** y confirmación móvil **4,2–6,8 ms** (límite 250 ms) |
| **RNF-01** presupuesto de consultas (sin N+1) | Índices y consultas agregadas | `VanStockReadinessPerformanceTest` 1.1, 1.2, 2.1, 2.2, 3.1 | ✅ 7/7 — 1 sentencia para 3 y 20 averías; 1 sentencia por mapa de técnicos; índice parcial presente |
| **RNF-02** atomicidad y huella de auditoría | `confirmReception` bajo transacción, `audit_log` | `VanRestockOrderServiceTest` 5.5, 5.9, 5.10 · `VanRestockOrderApiTest` 8.9, 8.10, 8.12, 8.13 · `PostgresVanRestockSchemaTest` | ✅ 74/74 · 59/59 · 19/19 |
| **RNF-03** Sistema de Diseño (`docs/design.md`) | `design-tokens.css`, `CoordinatorVanStockTab.js`, `TechnicianVanStockPanel.js`, `styles.css` | `CoordinatorButtonVariantsGuardTest.mjs` · `LegacyButtonPaletteRetirementTest.mjs` · `DesignTokenDebtRatchetTest.mjs` · `CoordinatorVanStockTabTest.mjs` 5.4–5.6 · `VanRestockUiComponentsTest.mjs` 4.6, 5.2 | ✅ 22/0 · 18/0 · 3/3 · 30/30 · 52/52 — 225 botones y 17 plantillas auditadas; 25 tokens retirados vigilados |
| **RNF-04** Dualidad lingüística (castellano neutro) | Todos los literales de UI y validación | `VanRestockUiComponentsTest.mjs` 1.5, 3.15 · `VanRestockOrderServiceTest` 2.8, 2.12 | ✅ 52/52 · 74/74 |

---

## 3. Casos Límite (§6) y Resoluciones QA

| Elemento | Escenario | Test | Resultado |
|---|---|---|---|
| **§6.1** Doble pulsación / pérdida de red | Segunda confirmación → 409 sin sumar | `VanRestockOrderServiceTest` 5.9, 5.10 · `VanRestockOrderApiTest` 8.9, 8.10 | ✅ |
| **§6.2** Rotura parcial en almacén | `0` en una línea y `2` en otra | `VanRestockOrderServiceTest` 4.3, 4.4 · `VanRestockOrderApiTest` 7.1 | ✅ |
| **§6.3** **C3** Pieza desactivada en tránsito | Recepción permitida sobre pieza inactiva | `VanRestockOrderApiTest` 13.3 (nueva) · `VanRestockOrderServiceTest` 4.4 | ✅ |
| **§6.4** **C2** Baja médica con orden emitida | Alerta visible, sin cancelación silenciosa | `VanRestockUiComponentsTest.mjs` 3.14 · `VanRestockOrderApiTest` 3.4 | ✅ |
| **§6.5** **QA 3** Stock compartido entre averías | Etiqueta `stock compartido` | `VanStockReadinessCalculatorTest` 2.2–2.5 · `VanRestockOrderApiTest` 9.2 | ✅ |
| **§6.6** **QA 2** Solicitud mixta catalogada + fuera de catálogo | Sufijo y degradación a parcial | `TechnicianAssignmentSuggesterVanStockTest` 1.7–1.11 · `VanStockReadinessCalculatorTest` 4.1–4.4 | ✅ |
| **QA 1** Baja lógica y reactivación | Sin `DELETE`, misma fila al volver | `VanRestockOrderServiceTest` 3.3–3.7 · `PostgresVanRestockSchemaTest` | ✅ |
| **QA 4** Aviso de ausencia y toque sin turno | Creación permitida con alerta; sin consulta de turno | `VanRestockOrderServiceTest` 1.16, 5.8, 5.11 · `VanRestockOrderApiTest` 3.4 | ✅ |
| **QA 5** Orden de propuesta y comprobante | Prelación determinista y recibo | `VanRestockOrderProposalTest` 4.2, 5.1–5.6 · `VanRestockOrderApiTest` 8.8 | ✅ |
| **D1** Despacho con cantidades reales | Parcial permitido, suma ≥ 1 | `VanRestockOrderServiceTest` 4.1–4.5 | ✅ |
| **D2** Puntuación intacta en triaje | `total_score` y desglose idénticos | `TechnicianAssignmentSuggesterVanStockTest` 3.2 · `VanRestockOrderApiTest` 10.1–10.4 | ✅ |
| **D3** Una sola orden abierta | Índice parcial único | `PostgresVanRestockSchemaTest` · `VanRestockOrderApiTest` 5.1 | ✅ |
| **D4** Fórmula aditiva y lote | «Ambos» + omisiones | `VanRestockOrderProposalTest` 3.1–3.5 · `VanRestockOrderServiceTest` 2.1–2.12 | ✅ |
| **C1** Universo de compatibilidad vacío | Etiqueta neutra sin alerta | `TechnicianAssignmentSuggesterVanStockTest` 2.3 | ✅ |
| **C4** Furgoneta cubierta | Aviso y alta manual | `VanRestockOrderProposalTest` 4.3, 4.4 · `VanRestockUiComponentsTest.mjs` 2.12 | ✅ |
| **C5** Avería sin reactivación al confirmar | Estado intacto | `VanRestockOrderApiTest` 8.11 | ✅ |

---

## 4. Definición de Hecho (§8)

| DoD | Evidencia |
|---|---|
| **1.** Propuesta y emisión individual o en lote | `VanRestockOrderProposalTest` 53/53 · `VanRestockOrderApiTest` 3.1–4.4 |
| **2.** Una sola orden abierta y baja lógica | `PostgresVanRestockSchemaTest` 19/19 · `VanRestockOrderApiTest` 5.1, 6.2, 6.3 |
| **3.** A4, despacho, alertas y cancelación justificada | `VanRestockUiComponentsTest.mjs` 3.1–3.15 · `VanRestockOrderApiTest` 7.1, 11.2–11.4 |
| **4.** Recepción móvil en un toque y comprobante | `VanRestockOrderServiceTest` 5.1–5.11 · `VanRestockOrderApiTest` 8.1–8.13 |
| **5.** Cruce informativo en dos niveles sin alterar puntuación | `TechnicianAssignmentSuggesterVanStockTest` 36/36 · `VanRestockOrderApiTest` 10.1–10.4 |
| **6.** Opacidad del Portal de Sede y canal QR | `VanRestockOrderApiTest` 12.2, 12.3 · `VanStockPrivacyTest` 9/9 |
| **7.** Batería global al 100 % | `php tests/run_all.php` (ver [`README.md`](../../README.md#resumen-de-ejecución-global-octubre-2026)) |

---

## 5. Mantenimiento

Esta matriz es un artefacto vivo: cualquier cambio en un `RF`, criterio EARS, `RNF` o resolución `QA` debe actualizar **la fila correspondiente y el resultado medido** en la misma entrega. La cifra viva de suites y aserciones se declara **solo** en el README; el resto de documentos la referencia sin duplicarla.
