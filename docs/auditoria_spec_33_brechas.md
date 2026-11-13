# Auditoría de la Spec 33 · Brechas concretas

**Módulo:** 33 · Órdenes de Carga de Furgoneta de Reposición y Picking con Trazabilidad de Frío  
**Especificación auditada:** [`specs/33-product-loading-cold-picking/spec.md`](../specs/33-product-loading-cold-picking/spec.md) (v2.0) y su espejo [`specs/functional/product_loading_cold_picking_spec.md`](../specs/functional/product_loading_cold_picking_spec.md)  
**Contratos:** [`specs/technical/product_loading_cold_picking_contracts.md`](../specs/technical/product_loading_cold_picking_contracts.md)  
**Sistema de Diseño:** [`docs/design.md`](design.md)  
**Fecha de auditoría:** 2026-11-13  
**Rama:** `feat/product-loading-cold-picking` · HEAD `c79c6da`  
**Alcance:** contraste requisito por requisito contra código y ejecución directa de suites; no se modificó código de producción.

**Actualización 2026-11-13:** B-01 cerrada tras implementar la propuesta y emisión individual del reponedor ausente, la alerta C2 en consola y su regresión de servicio, repositorio, API y frontend. B-02 cerrada al certificar las omisiones del lote por orden activa y demanda cero. Las demás brechas siguen abiertas.

---

## Resultado general

La implementación está muy avanzada. La batería hermética **no observó ningún test en rojo**; el canal `new_engine` mantuvo en rojo únicamente la sonda de latencia remota, ajena a B-01/B-02:

- **23 suites ejecutadas directamente**, todas con `exit=0`.
- Batería global: **427/427 suites · 16.048 aserciones · 0 fallos**.
- Canal `new_engine`: **56 suites · 1.846 aserciones · 55/56 en verde**. La sonda `PoolerLatencyMeasurementTest` falla por latencia remota (265,8 ms frente al presupuesto de 250 ms en «crear»), un límite de red ajeno a B-01/B-02; el resto de suites del canal pasa.
- Tareas del módulo: **13/13 completadas**, 0 pendientes.

Con B-01 y B-02 cerradas, **quedan seis brechas abiertas**: B-03 a B-07 son brechas de certificación y B-08 es una desviación de `RNF-03`. La Spec 33 sigue sin poder certificarse al 100 % hasta cerrarlas.

| ID | Brecha | Tipo | Severidad | Requisito afectado | Estado |
|---|---|---|---|---|---|
| **B-01** | La consola no permite emitir la orden individual de un reponedor ausente | Funcional | **Alta** | `C2`, `RF-02.3`, `RF-05.1` | ✅ **Cerrada** (2026-11-13) |
| **B-02** | Los omitidos del lote por orden activa o demanda cero no están probados | Cobertura | Media | `RF-02.3`, `C2` | ✅ **Cerrada** (2026-11-13) |
| **B-03** | No se prueba la recepción/liquidación con producto desactivado en tránsito | Cobertura | Media | `C3`, `RF-04.1`, `RF-04.2` | Abierta |
| **B-04** | No se prueba la cancelación desde `DISPATCHED` | Cobertura | Media | `RF-03.4` | Abierta |
| **B-05** | La ruta HTTP de liquidación de Coordinación no se ejerce | Cobertura | Media | `RF-04.2`, `RF-05.1` | Abierta |
| **B-06** | No se prueba `BATCH_EXPIRATION_MISMATCH` al confirmar la recepción | Cobertura | Media | `QA 3`, `RF-04.1` | Abierta |
| **B-07** | El doble clic no se prueba con concurrencia real | Cobertura | Media | §6.6, `D3`, `RF-04.1`, `RF-04.2` | Abierta |
| **B-08** | Los botones móviles del Módulo 33 declaran geometría en línea y quedan fuera del guard de Coordinación | Diseño | Baja/Media | `RNF-03`, `docs/design.md` | Abierta |

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

## B-03 · No se prueba la recepción/liquidación con producto desactivado en tránsito

**Requisito.** `C3` exige que, si un producto se desactiva después de emitir o despachar la orden, se permita completar la recepción y la liquidación para no bloquear la mercancía física en tránsito.

**Evidencia.**

- `ProductLoadingOrderServiceTest` **2.5** solo prueba que un producto inactivo no puede entrar en preparación.
- No hay prueba que desactive el producto después del despacho y ejecute `confirmOrder()` y `reconcileOrder()`.
- El código de `confirmOrder()` usa `requireProduct()` y no filtra por `is_active`; la intención está implementada, pero no certificada.

**Impacto.** La mitad «no permitir altas inactivas» está cubierta; la mitad «tolerar la baja en tránsito» no tiene evidencia. Una regresión que bloqueara la recepción pasaría desapercibida.

**Cierre propuesto.** Añadir un escenario que despache, desactive el producto en catálogo, confirme la recepción y liquide, verificando que el stock y los estados avanzan.

---

## B-04 · No se prueba la cancelación desde `DISPATCHED`

**Requisito.** `RF-03.4` permite cancelar una orden en `PENDING_PICKING` o `DISPATCHED`, con motivo de al menos 20 caracteres reales, sin alterar el stock; y bloquea la cancelación tras la recepción.

**Evidencia.**

- `ProductLoadingOrderStatus::canBeCancelled()` incluye `PENDING_PICKING` y `DISPATCHED`.
- `ProductLoadingOrderServiceTest` **9.3/9.4** cancela una orden en preparación.
- El test **9.5** comprueba el rechazo tras `CONFIRMED_BY_RESTOCKER`.
- No hay test que despache y después cancele una orden `DISPATCHED`.

**Impacto.** La rama permitida del ciclo de despacho no está certificada. Un cambio en `canBeCancelled()` o en el controlador podría romperla sin fallo de batería.

**Cierre propuesto.** Añadir un caso servicio y API: despachar, cancelar con motivo válido, verificar `CANCELLED`, auditoría y stock intacto.

---

## B-05 · La ruta HTTP de liquidación de Coordinación no se ejerce

**Requisito.** `RF-04.2` permite liquidar la orden tanto al reponedor desde el móvil como a Coordinación.

**Evidencia.**

- `CoordinatorProductLoadingController::reconcileOrder()` y la ruta `POST /api/coordinator/product-loading-orders/{id}/reconcile` existen.
- `ProductLoadingOrderServiceTest` **7.x** llama al servicio con actor `COORDINATOR`.
- `ProductLoadingColdPickingApiTest` **5.4** liquida por la ruta móvil `/api/restocker/product-loading-orders/{id}/reconcile`.
- No hay llamada HTTP a la ruta de Coordinación en ninguna suite.

**Impacto.** La lógica de servicio está cubierta con actor de Coordinación, pero el cableado HTTP, el RBAC y la respuesta de la ruta de Coordinación no se certifican de extremo a extremo.

**Cierre propuesto.** Añadir una prueba API que cree, despache y confirme una orden y la liquide por el endpoint de Coordinación, comprobando estado, stock y ausencia de costes.

---

## B-06 · No se prueba `BATCH_EXPIRATION_MISMATCH` al confirmar la recepción

**Requisito.** `QA 3` exige que un mismo `(restocker_id, product_id, batch_code)` no admita una `expiration_date` distinta al despachar, confirmar o ajustar.

**Evidencia.**

- El ajuste manual está cubierto por `RestockerVanStockServiceTest` **3.13** y **3.14**.
- El despacho está cubierto por `ProductLoadingOrderServiceTest` **5.3**.
- `ProductLoadingOrderService::confirmOrder()` invoca `assertLotCoherence()` antes del `upsertLot()`.
- No hay una prueba específica que confirme una orden con un lote preexistente y una fecha diferente; `ProductLoadingConstitutionalTest` **4.1** cubre la colisión desde el ajuste/API, no desde la confirmación.

**Impacto.** La defensa existe en código, pero la rama de confirmación de `QA 3` no tiene evidencia propia; una regresión en la llamada a `assertLotCoherence()` durante `confirmOrder()` no sería detectada por las aserciones actuales.

**Cierre propuesto.** Añadir un caso que siembre el lote con una fecha, despache con otra y confirme, esperando `409 BATCH_EXPIRATION_MISMATCH` y stock sin incrementar.

---

## B-07 · El doble clic no se prueba con concurrencia real

**Requisito.** §6.6 exige que, gracias al bloqueo pesimista `SELECT ... FOR UPDATE`, un segundo clic concurrente detecte el cambio de estado y responda `409 INVALID_ORDER_STATE_TRANSITION` sin duplicar el incremento ni la liquidación.

**Evidencia.**

- `ProductLoadingOrderServiceTest` **6.4** ejecuta una segunda confirmación de forma secuencial, después de que la primera haya terminado.
- `PdoProductLoadingOrderRepositoryTest` **4.4** verifica que `findById(forUpdate)` bloquea cabecera y líneas dentro de una transacción.
- No hay dos conexiones simultáneas compitiendo por la misma orden ni una prueba de carrera de liquidación.

**Impacto.** Se certifica el mecanismo de bloqueo, no la ausencia de doble incremento bajo concurrencia real. La garantía funcional del doble clic queda sin evidencia empírica.

**Cierre propuesto.** Añadir una prueba de integración con dos conexiones y sincronización de hilos/procesos que confirme una sola transición y un solo incremento de stock; repetir para liquidación.

---

## B-08 · Los botones móviles del Módulo 33 declaran geometría en línea y quedan fuera del guard de Coordinación

**Requisito.** `RNF-03` exige fidelidad a [`docs/design.md`](design.md), incluida la escalera única de botones (`vg-btn`, `vg-btn-sm`, `vg-btn-lg`) y la regla de que ningún botón declare su propia caja.

**Evidencia.**

- `CoordinatorButtonVariantsGuardTest.mjs` solo barre `public/assets/js/views` y `components` con prefijo `Coordinator*.js`; no incluye `RestockerRouteView.js`.
- `restocker_route_view.test.mjs` comprueba ausencia de hex, tipografía dual, radios 4/8 px y mobile-first, pero no la geometría de los botones.
- Los botones del banner 1-Tap y de la tarjeta de liquidación en `RestockerRouteView.js` usan `class="vg-btn vg-btn-primary"` pero además fijan altura, padding o tamaño en línea (por ejemplo `height: 42px`, `height: 34px`, `font-size: 12px`).
- `DesignTokenDebtRatchetTest.mjs` solo vigila un techo global de literales hex, no la geometría.

**Impacto.** La paleta, los radios y la impresión sí cumplen; la geometría de los botones móviles del módulo contradice la regla de `docs/design.md` y no hay guard que la detecte. Es una deuda de diseño, no un fallo funcional.

**Cierre propuesto.** Migrar los botones de `RestockerRouteView.js` a los peldaños `vg-btn-sm`/`vg-btn`/`vg-btn-lg` y ampliar el guard de variantes para incluir la vista móvil o una lista explícita de superficies del Módulo 33.

---

## Conclusión

**La Spec 33 no está 100 % cumplida.** El módulo tiene las 13 tareas cerradas y todas las suites ejecutadas en verde, pero:

- **B-01 está cerrada**: `C2` ya permite propuesta y emisión individual del ausente, muestra la alerta en consola y mantiene la exclusión del lote.
- **B-02 está cerrada**: el lote certifica `SKIP_ACTIVE_ORDER`, `SKIP_NO_DEMAND` y `SKIP_ABSENT` con sus causas exactas.
- **B-03 a B-07 son brechas de certificación** sobre ramas exigidas por el contrato.
- **B-08 es una desviación de diseño** respecto a `docs/design.md`.

La matriz de trazabilidad se actualizó para `C2`, `RF-02.3` y `RF-05.1` con las pruebas nuevas. Las brechas B-03 a B-08 siguen requiriendo corrección o certificación antes de declarar la Spec 33 completa.

---

## Referencias de evidencia

- [`specs/33-product-loading-cold-picking/tasks.md`](../specs/33-product-loading-cold-picking/tasks.md) · 13/13 tareas.
- [`specs/33-product-loading-cold-picking/traceability-matrix.md`](../specs/33-product-loading-cold-picking/traceability-matrix.md) · matriz actualizada para `C2`, `RF-02.3` y `RF-05.1`; B-03 a B-08 siguen documentadas como brechas.
- `src/Application/Service/ProductLoadingOrderService.php` · propuesta, emisión individual/lote, confirmación y liquidación.
- `src/Infrastructure/Repository/PdoProductLoadingOrderRepository.php` · `FOR UPDATE` y consultas de propuesta.
- `public/assets/js/components/CoordinatorProductLoadingTab.js` · consola de Coordinación.
- `public/assets/js/components/RestockerRouteView.js` · móvil del reponedor.
- `tests/unit/ProductLoadingOrderServiceTest.php` · casos 1.5, 1.5b, 2.5, 2.6, 2.7, 5.3, 6.4, 7.x, 9.3–9.5.
- `tests/integration/PdoProductLoadingOrderRepositoryTest.php` · caso 6.8 (ausencia con zonas y demanda conservadas).
- `tests/integration/ProductLoadingColdPickingApiTest.php` · flujo HTTP de propuesta y emisión individual del ausente (3.1b/3.1c) y flujo móvil.
- `tests/frontend/coordinator_product_loading_tab.test.mjs` · alerta C2 y emisión desde la propuesta del ausente (2.5b/2.5c).
- `tests/unit/CoordinatorButtonVariantsGuardTest.mjs` · alcance real del guard de botones.
