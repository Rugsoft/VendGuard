# Auditoría de la Spec 33 · Brechas concretas

**Módulo:** 33 · Órdenes de Carga de Furgoneta de Reposición y Picking con Trazabilidad de Frío  
**Especificación auditada:** [`specs/33-product-loading-cold-picking/spec.md`](../specs/33-product-loading-cold-picking/spec.md) (v2.0) y su espejo [`specs/functional/product_loading_cold_picking_spec.md`](../specs/functional/product_loading_cold_picking_spec.md)  
**Contratos:** [`specs/technical/product_loading_cold_picking_contracts.md`](../specs/technical/product_loading_cold_picking_contracts.md)  
**Sistema de Diseño:** [`docs/design.md`](design.md)  
**Fecha de auditoría:** 2026-11-13  
**Rama:** `feat/product-loading-cold-picking` · HEAD `8d83f6b`

**Alcance:** contraste requisito por requisito contra código y ejecución directa de suites; la única modificación de producción es la migración de los botones móviles de B-08 a la escalera del sistema de diseño.

**Actualización 2026-11-13:** B-01 cerrada tras implementar la propuesta y emisión individual del reponedor ausente, la alerta C2 en consola y su regresión de servicio, repositorio, API y frontend. B-02 cerrada al certificar las omisiones del lote por orden activa y demanda cero. B-03 cerrada al certificar la recepción y la liquidación con un producto desactivado en tránsito. B-04 cerrada al certificar la cancelación justificada desde `DISPATCHED`. B-05 cerrada al ejercer la ruta HTTP de liquidación de Coordinación. B-06 cerrada al certificar la colisión de caducidad al confirmar la recepción. B-07 cerrada al certificar el doble clic de confirmación y liquidación con conexiones reales. B-08 cerrada al migrar los 16 botones móviles a la escalera y ampliar el guard a esa superficie.

---

## Resultado general

La implementación está muy avanzada. La batería hermética y el canal `new_engine` **quedaron al 100 % en verde** en esta corrida:

- **24 suites ejecutadas directamente**, todas con `exit=0`.
- Batería global: **427/427 suites · 16.054 aserciones · 0 fallos**.
- Canal `new_engine`: **57 suites · 1.861 aserciones · 57/57 en verde**. La sonda `PoolerLatencyMeasurementTest` también quedó dentro de su presupuesto en esta corrida.
- Tareas del módulo: **13/13 completadas**, 0 pendientes.

Con B-01 a B-08 cerradas, **la Spec 33 queda certificada al 100 %**: no quedan brechas funcionales, de cobertura ni de diseño.

| ID | Brecha | Tipo | Severidad | Requisito afectado | Estado |
|---|---|---|---|---|---|
| **B-01** | La consola no permite emitir la orden individual de un reponedor ausente | Funcional | **Alta** | `C2`, `RF-02.3`, `RF-05.1` | ✅ **Cerrada** (2026-11-13) |
| **B-02** | Los omitidos del lote por orden activa o demanda cero no están probados | Cobertura | Media | `RF-02.3`, `C2` | ✅ **Cerrada** (2026-11-13) |
| **B-03** | No se prueba la recepción/liquidación con producto desactivado en tránsito | Cobertura | Media | `C3`, `RF-04.1`, `RF-04.2` | ✅ **Cerrada** (2026-11-13) |
| **B-04** | No se prueba la cancelación desde `DISPATCHED` | Cobertura | Media | `RF-03.4` | ✅ **Cerrada** (2026-11-13) |
| **B-05** | La ruta HTTP de liquidación de Coordinación no se ejerce | Cobertura | Media | `RF-04.2`, `RF-05.1` | ✅ **Cerrada** (2026-11-13) |
| **B-06** | No se prueba `BATCH_EXPIRATION_MISMATCH` al confirmar la recepción | Cobertura | Media | `QA 3`, `RF-04.1` | ✅ **Cerrada** (2026-11-13) |
| **B-07** | El doble clic no se prueba con concurrencia real | Cobertura | Media | §6.6, `D3`, `RF-04.1`, `RF-04.2` | ✅ **Cerrada** (2026-11-13) |
| **B-08** | Los botones móviles del Módulo 33 declaran geometría en línea y quedan fuera del guard de Coordinación | Diseño | Baja/Media | `RNF-03`, `docs/design.md` | ✅ **Cerrada** (2026-11-13) |

---

## B-01 · La consola no permite emitir la orden individual de un reponedor ausente · ✅ CERRADA

**Requisito.** `C2` establece que, si el reponedor está activo pero tiene una ausencia vigente hoy, se le excluye del lote pero **se permite emitirle una orden individual mostrando la alerta `⚠️ Destinatario actualmente ausente`**.

**Resolución implementada.**

- `ProductLoadingOrderService::buildProposal()` ya calcula la propuesta individual de un reponedor activo con ausencia vigente; `buildProposalFromInputs()` solo rechaza cuentas inactivas. `createOrder()` sin `items` usa esa propuesta y emite la orden.
- `PdoProductLoadingOrderRepository::coveredZonesCte()` conserva las zonas del reponedor ausente (la ausencia del titular sigue resolviendo las suplencias del resto de la plantilla), de modo que la demanda real no queda vacía.
- `CoordinatorProductLoadingTab.js` publica `proposalRecipientWarning` y lo renderiza en el modal de propuesta con `data-testid="product-loading-proposal-absent-warning"`; el botón de emisión sigue habilitado.
- `createBatchOrders()` mantiene la exclusión previa con `SKIP_ABSENT`: la orden individual no relaja la emisión masiva.

**Pruebas de regresión.**

- `ProductLoadingOrderServiceTest` **1.5** verifica propuesta con zonas, demanda y `is_currently_absent`; **1.5b** emite la orden individual sin `items`; **2.6** conserva la omisión del ausente en el lote.
- `PdoProductLoadingOrderRepositoryTest` **6.8** comprueba que una ausencia vigente conserva zonas y demanda en PostgreSQL.
- `ProductLoadingColdPickingApiTest` **3.1b/3.1c** cubren propuesta `200` con alerta y alta individual `201` para el ausente.
- `coordinator_product_loading_tab.test.mjs` **2.5b/2.5c** cubren la alerta C2 renderizada y la emisión desde la propuesta del ausente.
- `ProductLoadingConstitutionalTest` **30/30** sigue verde y mantiene las guardas de `C2`.

**Verificación.** `ProductLoadingOrderServiceTest` **45/45** · `PdoProductLoadingOrderRepositoryTest` **30/30** · `ProductLoadingColdPickingApiTest` **31/31** · `coordinator_product_loading_tab.test.mjs` **50/50** · `ProductLoadingConstitutionalTest` **30/30**, todas con `exit=0`.

**Estado.** ✅ **CERRADA** el 2026-11-13. La emisión individual del ausente es posible desde la consola, la alerta C2 se muestra antes de emitir y el lote continúa omitiéndolo.

---

## B-02 · Los omitidos del lote por orden activa o demanda cero no están probados · ✅ CERRADA

**Requisito.** `RF-02.3` exige que la emisión masiva omita a los reponedores ausentes, a los que ya tienen una orden activa y a los que tienen cero líneas sugeridas, devolviendo la causa exacta de cada omisión.

**Resolución implementada.**

- Se añadió al doble de pruebas un reponedor sin demanda y otro con una orden activa, sin tocar el servicio ni el repositorio de producción.
- `ProductLoadingOrderServiceTest` **2.7** ejecuta un lote con los cuatro casos simultáneos: crea la orden del reponedor disponible (7), omite al ausente (8) con `SKIP_ABSENT`, omite al reponedor sin demanda (10) con `SKIP_NO_DEMAND` y omite al que ya tiene orden (11) con `SKIP_ACTIVE_ORDER`.
- La aserción verifica el `created` y el `skipped` con la causa exacta, además del `order_id` activo que explica la omisión de 11.

**Verificación.** `ProductLoadingOrderServiceTest` **46/46**, `exit=0`. El caso 2.6 conserva la cobertura previa del alta y la ausencia; el caso 2.7 cubre las dos ramas que faltaban.

**Estado.** ✅ **CERRADA** el 2026-11-13. Las tres causas de omisión del lote quedan certificadas con sus códigos exactos y sin modificar el comportamiento productivo.

---

## B-03 · No se prueba la recepción/liquidación con producto desactivado en tránsito · ✅ CERRADA

**Requisito.** `C3` exige que, si un producto se desactiva después de emitir o despachar la orden, se permita completar la recepción y la liquidación para no bloquear la mercancía física en tránsito.

**Resolución implementada.**

- Se añadió al doble de catálogo la operación de baja lógica `deactivate()` y se conservó el escenario **2.5** para el alta con producto inactivo.
- `ProductLoadingOrderServiceTest` **10.1** despacha una orden con una línea de frío y otra de ambiente, desactiva el producto 101 en el catálogo y confirma la recepción: la orden pasa a `CONFIRMED_BY_RESTOCKER` y los lotes quedan en 8 y 7 unidades.
- `ProductLoadingOrderServiceTest` **10.2** liquida la misma orden con el producto aún inactivo: pasa a `RECONCILED_AT_CLOSE` y los saldos quedan en el retorno contado (5 y 3).
- No se tocó código de producción: `confirmOrder()` y `reconcileOrder()` ya usaban `requireProduct()` sin filtrar por `is_active`, que es la tolerancia exigida por `C3`.

**Verificación.** `ProductLoadingOrderServiceTest` **48/48**, `exit=0`; el caso 2.5 mantiene el bloqueo del alta inactiva y los casos 10.1–10.2 certifican la tolerancia en tránsito.

**Estado.** ✅ **CERRADA** el 2026-11-13. La recepción y la liquidación avanzan con el producto desactivado y dejan los estados y saldos esperados, sin modificar el comportamiento productivo.

---

## B-04 · No se prueba la cancelación desde `DISPATCHED` · ✅ CERRADA

**Requisito.** `RF-03.4` permite cancelar una orden en `PENDING_PICKING` o `DISPATCHED`, con motivo de al menos 20 caracteres reales, sin alterar el stock; y bloquea la cancelación tras la recepción.

**Resolución implementada.**

- `ProductLoadingOrderServiceTest` **11.1** despacha una orden, la cancela desde `DISPATCHED`, verifica `CANCELLED`, el motivo, el sello `cancelled_at`, la auditoría `PRODUCT_LOADING_ORDER_CANCELLED` y que el stock de furgoneta queda intacto.
- `ProductLoadingColdPickingApiTest` **6.4** crea una nueva orden de producto ambiente y la despacha sin temperatura de cámara; **6.5** la cancela desde el endpoint de Coordinación y comprueba `200`, `CANCELLED`, el motivo y el stock sin cambios.
- No se tocó código de producción: `ProductLoadingOrderStatus::canBeCancelled()` ya incluía `PENDING_PICKING` y `DISPATCHED`, y el controlador ya delegaba en `cancelOrder()`.

**Verificación.** `ProductLoadingOrderServiceTest` **49/49** · `ProductLoadingColdPickingApiTest` **33/33**, ambas con `exit=0`. Los casos 9.3–9.5 conservan la cancelación en preparación y el rechazo tras recepción.

**Estado.** ✅ **CERRADA** el 2026-11-13. La cancelación justificada desde `DISPATCHED` queda certificada en servicio y API, sin alterar el stock.

---

## B-05 · La ruta HTTP de liquidación de Coordinación no se ejerce · ✅ CERRADA

**Requisito.** `RF-04.2` permite liquidar la orden tanto al reponedor desde el móvil como a Coordinación.

**Resolución implementada.**

- `ProductLoadingColdPickingApiTest` **7.1** crea una orden para el reponedor, la despacha, la confirma por la ruta móvil y la liquida por `POST /api/coordinator/product-loading-orders/{id}/reconcile`.
- La aserción comprueba `200`, estado `RECONCILED_AT_CLOSE`, `reconciled_by` con el usuario de Coordinación, saldo del lote en el retorno contado y ausencia de `unit_cost_eur` en la respuesta.
- No se tocó código de producción: la ruta ya estaba registrada con el middleware de Coordinación y delegaba en `reconcileOrder()`.

**Verificación.** `ProductLoadingColdPickingApiTest` **34/34**, `exit=0`; el caso 5.4 conserva la liquidación móvil y el 7.1 certifica la ruta de Coordinación.

**Estado.** ✅ **CERRADA** el 2026-11-13. La ruta HTTP de Coordinación queda certificada de extremo a extremo, con actor, estado, stock y opacidad de costes.

---

## B-06 · No se prueba `BATCH_EXPIRATION_MISMATCH` al confirmar la recepción · ✅ CERRADA

**Requisito.** `QA 3` exige que un mismo `(restocker_id, product_id, batch_code)` no admita una `expiration_date` distinta al despachar, confirmar o ajustar.

**Resolución implementada.**

- `ProductLoadingOrderServiceTest` **6.5** despacha con el lote nuevo `LOTE-T8-B` y caducidad `+2` días; después siembra ese mismo lote en la furgoneta con `+4` días y confirma la recepción.
- La confirmación responde **`409 BATCH_EXPIRATION_MISMATCH`**, de modo que la colisión se ejerce en `confirmOrder()` y no en el despacho previo.
- `ProductLoadingOrderServiceTest` **6.6** comprueba que el rechazo no incrementa el lote (sigue en 1) ni confirma la orden (sigue en `DISPATCHED`).
- No se tocó código de producción: `confirmOrder()` ya llamaba a `assertLotCoherence()` antes de `upsertLot()`.

**Verificación.** `ProductLoadingOrderServiceTest` **51/51**, `exit=0`; el ajuste conserva 3.13/3.14, el despacho 5.3 y la confirmación queda cubierta por 6.5/6.6.

**Estado.** ✅ **CERRADA** el 2026-11-13. La colisión de fecha al confirmar la recepción queda certificada con rechazo 409, stock intacto y estado sin avanzar.

---

## B-07 · El doble clic no se prueba con concurrencia real · ✅ CERRADA

**Requisito.** §6.6 exige que, gracias al bloqueo pesimista `SELECT ... FOR UPDATE`, un segundo clic concurrente detecte el cambio de estado y responda `409 INVALID_ORDER_STATE_TRANSITION` sin duplicar el incremento ni la liquidación.

**Resolución implementada.**

- `ProductLoadingConcurrencyTest` crea un esquema aislado y una orden despachada, retiene su cabecera con `FOR UPDATE` y lanza **dos procesos actores** contra la misma orden.
- Los dos actores cruzan una barrera común y la suite comprueba en `pg_stat_activity` que ambos esperan el cerrojo antes de liberar la cabecera. No es una llegada secuencial.
- En la confirmación, uno gana y el otro recibe **`409 INVALID_ORDER_STATE_TRANSITION`**; el stock queda en **4** (un solo incremento de 4) y la auditoría registra una confirmación y una recepción de stock.
- La misma carrera se repite sobre la liquidación: un solo `RECONCILED_AT_CLOSE`, saldo final **3** y una sola liquidación de orden y de stock.
- No se tocó código de producción: el `FOR UPDATE` y la comprobación de estado ya existían.

**Verificación.** `ProductLoadingConcurrencyTest` **12/12**, `exit=0`; canal `new_engine` **57 suites · 1.861 aserciones · 57/57 en verde**. La batería global conserva **427 suites · 16.054 aserciones · 0 fallos**.

**Estado.** ✅ **CERRADA** el 2026-11-13. El doble clic queda certificado con conexiones reales, una sola transición, un solo incremento y una sola liquidación.

---

## B-08 · Los botones móviles del Módulo 33 declaran geometría en línea y quedan fuera del guard de Coordinación · ✅ CERRADA

**Requisito.** `RNF-03` exige fidelidad a [`docs/design.md`](design.md), incluida la escalera única de botones (`vg-btn`, `vg-btn-sm`, `vg-btn-lg`) y la regla de que ningún botón declare su propia caja.

**Evidencia (antes).**

- `CoordinatorButtonVariantsGuardTest.mjs` solo barre `public/assets/js/views` y `components` con prefijo `Coordinator*.js`; no incluye `RestockerRouteView.js`.
- `restocker_route_view.test.mjs` comprueba ausencia de hex, tipografía dual, radios 4/8 px y mobile-first, pero no la geometría de los botones.
- Los botones del banner 1-Tap y de la tarjeta de liquidación en `RestockerRouteView.js` usan `class="vg-btn vg-btn-primary"` pero además fijan altura, padding o tamaño en línea (por ejemplo `height: 42px`, `height: 34px`, `font-size: 12px`).
- `DesignTokenDebtRatchetTest.mjs` solo vigila un techo global de literales hex, no la geometría.

**Impacto.** La paleta, los radios y la impresión sí cumplen; la geometría de los botones móviles del módulo contradice la regla de `docs/design.md` y no hay guard que la detecte. Es una deuda de diseño, no un fallo funcional.

**Resolución implementada.**

- Los 16 botones de `RestockerRouteView.js` visten ahora la escalera canónica: login, recepción 1-Tap y apertura de liquidación usan `vg-btn-lg`; las acciones de jornada y los cierres de modal usan `vg-btn-sm`; los botones de los diálogos de liquidación usan `vg-btn-lg`.
- El ancho completo, el reparto de los botones del diálogo y la separación vertical se resuelven con las utilidades `w-100`, `flex-grow-1` y `mt-2`; ningún `<button>` conserva `height`, `width`, `padding`, `font-size`, `border-radius`, `margin`, `flex` ni `color` en línea.
- «Cerrar Turno» deja el color crítico inline y pasa al tono semántico `vg-btn-danger-soft`; el resto de tonos ya existían en la hoja.
- `CoordinatorButtonVariantsGuardTest.mjs` barre las 24 plantillas `Coordinator*.js` y la superficie móvil explícita `RestockerRouteView.js`: 25 plantillas y 361 botones. La aserción 2.8 certifica que la vista del Módulo 33 entra en el barrido con sus 16 botones y sin geometría, color ni clase base fuera del sistema.

**Verificación.** `CoordinatorButtonVariantsGuardTest.mjs` **23/23**, `exit=0`; `restocker_route_view.test.mjs` **108/108**, `exit=0`. La batería global queda en **427 suites · 16.054 aserciones · 0 fallos**, y el canal `new_engine` conserva **57 suites · 1.861 aserciones · 57/57 en verde**.

**Estado.** ✅ **CERRADA** el 2026-11-13. Los botones móviles del Módulo 33 y las plantillas de Coordinación comparten una sola escalera, y el guard impide que la geometría inline vuelva a la superficie móvil.

---

## Conclusión

**La Spec 33 está 100 % cumplida.** El módulo tiene las 13 tareas cerradas y todas las suites ejecutadas en verde:

- **B-01 está cerrada**: `C2` ya permite propuesta y emisión individual del ausente, muestra la alerta en consola y mantiene la exclusión del lote.
- **B-02 está cerrada**: el lote certifica `SKIP_ACTIVE_ORDER`, `SKIP_NO_DEMAND` y `SKIP_ABSENT` con sus causas exactas.
- **B-03 está cerrada**: la recepción y la liquidación toleran un producto desactivado después del despacho, con estados y saldos verificados.
- **B-04 está cerrada**: la cancelación desde `DISPATCHED` deja `CANCELLED`, audita el motivo y no altera el stock, tanto en servicio como en API.
- **B-05 está cerrada**: la ruta de liquidación de Coordinación liquida de extremo a extremo, sella el actor y deja el stock contado sin costes.
- **B-06 está cerrada**: la confirmación de recepción rechaza un lote preexistente con otra caducidad mediante `409 BATCH_EXPIRATION_MISMATCH`, sin tocar stock ni estado.
- **B-07 está cerrada**: dos conexiones reales compiten por la cabecera; una gana y la otra recibe 409, sin duplicar stock ni auditoría en confirmación y liquidación.
- **B-08 está cerrada**: los 16 botones móviles usan la escalera `vg-btn-sm`/`vg-btn-lg`, ya no declaran su caja en línea y el guard de variantes cubre la superficie móvil del Módulo 33.

La matriz de trazabilidad se actualizó para `C2`, `C3`, `QA 3`, `RF-02.3`, `RF-03.4`, `RF-04.1`, `RF-04.2`, `RF-05.1` y `RNF-03` con las pruebas nuevas. No quedan brechas abiertas.

---

## Referencias de evidencia

- [`specs/33-product-loading-cold-picking/tasks.md`](../specs/33-product-loading-cold-picking/tasks.md) · 13/13 tareas.
- [`specs/33-product-loading-cold-picking/traceability-matrix.md`](../specs/33-product-loading-cold-picking/traceability-matrix.md) · matriz actualizada para `C2`, `C3`, `QA 3`, `RF-02.3`, `RF-03.4`, `RF-04.1`, `RF-04.2`, `RF-05.1` y `RNF-03`; B-08 cerrada.
- `src/Application/Service/ProductLoadingOrderService.php` · propuesta, emisión individual/lote, confirmación y liquidación.
- `src/Infrastructure/Repository/PdoProductLoadingOrderRepository.php` · `FOR UPDATE` y consultas de propuesta.
- `public/assets/js/components/CoordinatorProductLoadingTab.js` · consola de Coordinación.
- `public/assets/js/components/RestockerRouteView.js` · móvil del reponedor.
- `tests/unit/ProductLoadingOrderServiceTest.php` · casos 1.5, 1.5b, 2.5, 2.6, 2.7, 5.3, 6.4–6.6, 7.x, 9.3–9.5, 10.1–10.2 y 11.1.
- `tests/integration/PdoProductLoadingOrderRepositoryTest.php` · caso 6.8 (ausencia con zonas y demanda conservadas).
- `tests/integration/ProductLoadingColdPickingApiTest.php` · flujo HTTP de propuesta y emisión individual del ausente (3.1b/3.1c), flujo móvil, cancelación desde `DISPATCHED` (6.4–6.5) y liquidación de Coordinación (7.1).
- `tests/integration/ProductLoadingConcurrencyTest.php` · doble clic concurrente de confirmación y liquidación con dos procesos actores, stock y auditoría únicos (B-07).
- `tests/Support/ProductLoadingConcurrencyActor.php` · actor externo con conexión propia y barrera común para la carrera de la cabecera.
- `tests/frontend/coordinator_product_loading_tab.test.mjs` · alerta C2 y emisión desde la propuesta del ausente (2.5b/2.5c).
- `tests/unit/CoordinatorButtonVariantsGuardTest.mjs` · guard de botones del panel y de la vista móvil del Módulo 33 (23/23).
- `tests/frontend/restocker_route_view.test.mjs` · contrato móvil del reponedor, incluida la recepción y la liquidación (108/108).
