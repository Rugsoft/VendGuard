# AUDITORÍA FUNCIONAL · MÓDULO 32

## Catálogo Maestro de Productos, Consumibles y Planograma por Máquina (*Machine Planogram & Slot Matrix*)

**Proyecto:** Gestor de Incidencias de Vending (*VendGuard*)\
**Módulo:** 32 · `specs/32-product-catalog-planogram`\
**Referencias:** [`spec.md`](../specs/32-product-catalog-planogram/spec.md) v2.0 · [`product_catalog_planogram_spec.md`](../specs/functional/product_catalog_planogram_spec.md) · [`product_catalog_planogram_contracts.md`](../specs/technical/product_catalog_planogram_contracts.md) · [`design.md`](design.md) · [`tasks.md`](../specs/32-product-catalog-planogram/tasks.md) · [`traceability-matrix.md`](../specs/32-product-catalog-planogram/traceability-matrix.md)\
**Fecha de la revisión:** 2026-11-12\
**Alcance:** revisión de solo lectura de `RF-01`–`RF-05`, criterios EARS, `RNF-01`–`RNF-03`, Sistema de Diseño, casos límite §6, decisiones `D1`–`D3`, invariantes `C1`–`C3`, resoluciones `QA 1`–`QA 4`, los 5 criterios de Definition of Done y las 14 tareas `T-PLN-01`–`T-PLN-14`.\
**Resultado:** **la Spec 32 está cumplida al 100 %**: las **7 brechas** detectadas están resueltas y no quedan bloqueantes funcionales, contractuales ni documentales abiertos.

**Última actualización:** 2026-11-12 · H-01 a H-07 resueltos y certificados.

> Este documento es un acta viva de hallazgos: registra la evidencia y el estado de resolución, sin sustituir a la Spec ni a los tests.

---

## 1. Veredicto ejecutivo

La implementación del catálogo, la matriz de planogramas, el cerrojo sanitario bidireccional, los presets, la clonación y la segregación del reponedor están respaldados por **24 suites específicas y la batería global**, todas en verde:

```text
php tests/run_all.php
420 suites · 15.669 aserciones · 0 fallos
Cifras documentadas: COHERENTES
RESULTADO: 100% EN VERDE
exit=0
```

Las 14 tareas están marcadas como completadas en `tasks.md` y en el acta ODD. **H-01 a H-07 ya están corregidas y certificadas.**

**Veredicto:** **SÍ al 100 %**: no quedan desviaciones abiertas.

---

## 2. Evidencia ejecutada

| Suite | Resultado real |
| :--- | ---: |
| `tests/unit/PlanogramCompatibilityValidatorTest.php` | **47/47**, exit 0 |
| `tests/unit/ModelPlanogramPresetCatalogTest.php` | **15/15**, exit 0 |
| `tests/unit/VendingProductServiceTest.php` | **32/32**, exit 0 |
| `tests/unit/MachinePlanogramServiceTest.php` | **35/35**, exit 0 |
| `tests/unit/AdminMachinePlanogramGuardTest.php` | **9/9**, exit 0 |
| `tests/integration/PdoVendingProductRepositoryTest.php` | **30/30**, exit 0 |
| `tests/integration/PdoMachinePlanogramRepositoryTest.php` | **32/32**, exit 0 |
| `tests/unit/FunctionalSpecMirrorParityTest.php` | **24/24**, exit 0 |
| `tests/integration/SchemaConvergenceParityTest.php` | **30/30**, exit 0 |
| `tests/integration/PostgresProductPlanogramSchemaTest.php` | **35/35**, exit 0 |
| `tests/integration/ProductCatalogPlanogramApiTest.php` | **30/30**, exit 0 |
| `tests/integration/PlanogramConstitutionalTest.php` | **31/31**, exit 0 |
| `tests/integration/PostgresSchemaTest.php` | **135/135**, exit 0 |
| `tests/integration/PostgresConstitutionalTest.php` | **80/80**, exit 0 |
| `tests/integration/OpportunisticSchemaCorrespondenceTest.php` | **32/32**, exit 0 |
| `tests/frontend/coordinator_planogram_tab.test.mjs` | **38/38**, exit 0 |
| `tests/frontend/restocker_route_view.test.mjs` | **82/82**, exit 0 |
| `tests/unit/CoordinatorButtonVariantsGuardTest.mjs` | **22/22**, exit 0 |
| `tests/unit/LegacyButtonPaletteRetirementTest.mjs` | **18/18**, exit 0 |
| `tests/unit/DesignTokenDebtRatchetTest.mjs` | **3/3**, exit 0 |
| `tests/unit/FrontendTemplateCompileTest.mjs` | **15/15**, exit 0 · 78 plantillas |
| `tests/unit/MobileShellLayoutGuardTest.mjs` | **7/7**, exit 0 |
| `tests/unit/OfflinePwaAssetsAndUiGuardTest.mjs` | **45/45**, exit 0 |
| `tests/unit/AuditEntityTypesTest.php` | **41/41**, exit 0 |
| Batería global `php tests/run_all.php` | **420 suites · 15.669 aserciones · 0 fallos**, exit 0 |

### 2.1. Nombres de suite normalizados

- La correspondencia de esquema se certifica con `SchemaConvergenceParityTest.php` (30/30) y `PostgresSchemaTest.php` (135/135); `OpportunisticSchemaCorrespondenceTest.php` (32/32) pertenece al Módulo 16.
- La guarda de botones se ejecuta como `tests/unit/LegacyButtonPaletteRetirementTest.mjs` (18/18).

---

## 3. Matriz de hallazgos

| ID | Hallazgo | Severidad | Estado |
| :--- | :--- | :---: | :---: |
| **H-01** | Falta el estado sanitario en la tarjeta de máquina del reponedor | **Bloqueante** | Resuelto |
| **H-02** | Falta `compatible_source_machines` en la respuesta del planograma de Coordinación | **Alta** | Resuelto |
| **H-03** | Copy de la insignia de producto dado de baja no coincide con la Spec | **Baja** | Resuelto |
| **H-04** | Cobertura de tests parcial en filtros y en la invariante de clonación fallida | **Media** | Resuelto |
| **H-05** | Nombres de suite solicitados inexistentes o con extensión incorrecta | **Informativa** | Resuelto |
| **H-06** | El contrato técnico fijaba 27 tipos ENUM y el motor certifica 37 | **Baja documental** | Resuelto |
| **H-07** | Etiqueta «once columnas» para una tabla de diez columnas | **Informativa** | Resuelto |

---

## 4. Detalle de los hallazgos

### H-01 · Estado sanitario en la tarjeta del reponedor

**Severidad:** Bloqueante · **Estado:** Resuelto (`b382ca1`) · **Requisito afectado:** `EARS 05.2.1`, `QA 2`, Art. II.

**Qué exige la Spec.** [`spec.md:211`](../specs/32-product-catalog-planogram/spec.md) obliga a que cada tarjeta de máquina del reponedor muestre «código, modelo, sede, zona, **estado sanitario** y el resumen de su planograma».

**Qué implementaba el código antes de la corrección.** [`RestockerRouteView.js`](../public/assets/js/components/RestockerRouteView.js) pintaba código, modelo, tipología, sede, zona, contadores y llenado, pero no referenciaba `machine.sanitary_status`. El backend sí entregaba el dato: [`PdoMachinePlanogramRepository.php`](../src/Infrastructure/Repository/PdoMachinePlanogramRepository.php) ya lo incluía en la proyección de la máquina.

**Qué faltaba certificar.** El fixture de [`restocker_route_view.test.mjs`](../tests/frontend/restocker_route_view.test.mjs) declaraba `sanitary_status: 'OK'`, pero las 79 aserciones no comprobaban que se renderizase. `PdoMachinePlanogramRepositoryTest` certificaba el DTO; la vista no lo consumía.

**Impacto original.** El reponedor no veía una cuarentena sanitaria en la tarjeta de la máquina. Es un dato operativo crítico del Art. II: podía abrir y reponer un equipo en cuarentena sin advertirlo.

**Corrección aplicada.** La tarjeta muestra una insignia `Sanidad · …` con etiquetas para `OK`, `ATTENTION_REQUIRED`, `EXPIRED`, `QUARANTINE` y `SEASONAL_PAUSE`, estilos basados exclusivamente en tokens semánticos (`success`, `warning`, `error` y superficie neutra) y un fallback neutro. `restocker_route_view.test.mjs` certifica el `data-testid`, las etiquetas y los tokens; la suite suma las aserciones de H-01 y H-03 y queda en **82/82**.

---

### H-02 · `compatible_source_machines` en el contrato del planograma

**Severidad:** Alta · **Estado:** Resuelto (`b382ca1`) · **Requisito afectado:** contrato técnico §3.2, `RF-04`, `D1`.

**Qué exige el contrato.** [`product_catalog_planogram_contracts.md:429`](../specs/technical/product_catalog_planogram_contracts.md) define que `GET /api/coordinator/machines/{id}/planogram` devuelve `machine`, `summary`, `slots` y `compatible_source_machines`.

**Qué implementaba el código antes de la corrección.** [`MachinePlanogramService.php`](../src/Application/Service/MachinePlanogramService.php) devolvía únicamente `machine`, `summary` y `slots`. El campo no existía en el servicio ni en la respuesta del controlador. [`CoordinatorPlanogramTab.js`](../public/assets/js/components/CoordinatorPlanogramTab.js) lo suplía en cliente calculando máquinas del mismo `machine_type`.

**Qué faltaba certificar.** `ProductCatalogPlanogramApiTest` 3.3 solo comprobaba `machine`, `summary`, carriles y `unit_cost_eur`. El test de componente 4.5 probaba el fallback local, no la respuesta contractual.

**Impacto original.** La UI funcionaba para el caso común, pero el contrato REST quedaba incumplido y cualquier otro consumidor de la API no recibía la lista de orígenes compatibles.

**Corrección aplicada.** `MachinePlanogramRepositoryInterface` y `PdoMachinePlanogramRepository` incorporan `findCompatibleSourceMachines()`, que devuelve máquinas activas de la misma tipología, distintas de la actual, ordenadas por código y con `active_slots_count`. `getCoordinatorPlanogram()` incluye el campo y `ProductCatalogPlanogramApiTest` 3.3b certifica el contrato; la suite pasó de 28 a **30/30**.

---

### H-03 · Copy canónico de la insignia de producto inactivo

**Severidad:** Baja · **Estado:** Resuelto · **Requisito afectado:** `EARS 01.3.2`, `C1`, caso §6.5.

**Qué exige la Spec.** La insignia literal es **`⚠️ Producto dado de baja (Sustituir)`** ([`spec.md:146`](../specs/32-product-catalog-planogram/spec.md)).

**Qué implementaba el código antes de la corrección.** [`CoordinatorPlanogramTab.js`](../public/assets/js/components/CoordinatorPlanogramTab.js) pintaba `⚠️ Producto dado de baja`; [`RestockerRouteView.js`](../public/assets/js/components/RestockerRouteView.js) pintaba `⚠️ Sustituir`.

**Qué faltaba certificar.** Las suites comprobaban el estado `product_is_active`, pero no el literal canónico en ninguna de las dos superficies.

**Impacto original.** El estado y la acción se comunicaban, pero no con el texto canónico. Era una desviación de copy, no de lógica.

**Corrección aplicada.** Ambas superficies muestran el literal **`⚠️ Producto dado de baja (Sustituir)`**. `coordinator_planogram_tab.test.mjs` lo certifica en 4.7 y `restocker_route_view.test.mjs` en su aserción de copy; las suites quedan en **38/38** y **82/82**.

---

### H-04 · Cobertura de tests parcial en filtros e invariantes

**Severidad:** Media · **Estado:** Resuelto (`957d8dc`) · **Requisito afectado:** `RF-05.1`, contrato de filtros del reponedor, `EARS 04.2.2`.

**Qué faltaba cubrir:**

1. **Filtro por zona operativa en Coordinación.** La UI no tiene un selector dedicado: [`CoordinatorPlanogramTab.js`](../public/assets/js/components/CoordinatorPlanogramTab.js) declara `location_id` y `machine_type`, y la zona solo se puede buscar como texto libre. `coordinator_planogram_tab.test.mjs` cubría sede (3.5) y tipología (3.6), pero no zona.
2. **Filtros opcionales del reponedor.** `GET /api/restocker/machines` implementa `?zone_id=` y `?stock_alert_only=` en [`MachinePlanogramService.php`](../src/Application/Service/MachinePlanogramService.php), pero no había aserción HTTP para `stock_alert_only`.
3. **Destino intacto tras clonación rechazada.** `clonePlanogram()` valida la compatibilidad antes de escribir, pero `MachinePlanogramServiceTest` 5.5 solo comprobaba el código de error; no verificaba que la máquina destino conservase sus carriles previos.

**Impacto original.** No se había demostrado una regresión funcional, pero estas invariantes podían romperse sin que la batería lo detectase.

**Corrección aplicada.** La suite de Coordinación añade 3.7 (búsqueda por `Zona Norte`), la suite API añade 4.1b (`stock_alert_only=true` con comparación exacta de ids y mezcla de máquinas con y sin alerta) y el test unitario añade 5.5b (snapshot del destino antes/después del clon rechazado). Resultados actuales: **38/38**, **30/30** y **35/35**.

---

### H-05 · Nombres de suite normalizados

**Severidad:** Informativa · **Estado:** Resuelto · **Requisito afectado:** reproducibilidad de la auditoría.

**Qué ocurría.** Varias tareas y actas citaban suites con extensión `.php` o nombres que no existen en el repositorio, de modo que un comando copiado literalmente fallaba aunque la cobertura real estuviera disponible.

**Impacto original.** La auditoría no era reproducible sin traducir previamente los nombres de las suites.

**Corrección aplicada.** Todas las referencias se sustituyeron por las suites reales: `tests/integration/SchemaConvergenceParityTest.php`, `tests/integration/PostgresSchemaTest.php`, `tests/integration/OpportunisticSchemaCorrespondenceTest.php` (Módulo 16) y `tests/unit/LegacyButtonPaletteRetirementTest.mjs`. Los comandos de aceptación usan `php tests/...` o `node tests/...`.

---

### H-06 · Recuento de tipos ENUM alineado

**Severidad:** Baja documental · **Estado:** Resuelto · **Requisito afectado:** contrato de esquema §2.

**Qué ocurría.** [`product_catalog_planogram_contracts.md:40`](../specs/technical/product_catalog_planogram_contracts.md) afirmaba que el esquema pasaba de «25 a 27 tipos `ENUM` nativos».

**Qué certifica el motor.** `SchemaConvergenceParityTest` 30/30 declara **43 conjuntos ENUM y 37 tipos distintos**; el ODD ya lo había registrado como desviación declarada en `T-PLN-02`.

**Impacto original.** No afectaba al comportamiento, pero contradecía la fuente técnica y podía confundir auditorías futuras.

**Corrección aplicada.** El contrato declara ahora **37 tipos `ENUM` nativos en total** y atribuye a la migración 028 solo `vending_product_category` y `vending_product_unit_type`; se retiraron los ordinales históricos que fijaban un total superado. `database_schema.md` §3 ya declaraba 37 y no requirió cambios.

**Verificación.** `SchemaConvergenceParityTest` **30/30** —incluida la aserción que cuenta los tipos ENUM distintos— y `PostgresSchemaTest` **135/135**, ambos con `exit=0`, 43 tablas, 43 conjuntos ENUM y 37 tipos distintos.

---

### H-07 · Etiqueta de columnas corregida

**Severidad:** Informativa · **Estado:** Resuelto · **Requisito afectado:** mensaje del test de esquema.

**Qué ocurría.** [`PostgresProductPlanogramSchemaTest.php:316`](../tests/integration/PostgresProductPlanogramSchemaTest.php) titulaba «La tabla declara sus once columnas canónicas», pero el array comparado contenía **diez** columnas y el DDL de la Tabla 43 también declara diez.

**Impacto original.** El test pasaba porque comparaba el array correcto; solo el mensaje estaba mal y podía inducir a error al leer el reporte.

**Corrección aplicada.** El título de la aserción declara ahora «sus diez columnas canónicas en el orden del contrato».

**Verificación.** `PostgresProductPlanogramSchemaTest` **35/35**, con `exit=0`, y la salida enumera las diez columnas de la Tabla 43.

---

## 5. Estado de los criterios de finalización

| DoD | Evidencia | Estado |
| :--- | :--- | :---: |
| **1.** Tablas 42/43, tipos, `CHECK`, anti-borrado y entidades auditables | Schema 35/35 · Convergencia 30/30 · AuditEntityTypes 41/41 | ✅ |
| **2.** CRUD con SKU inmutable, cotas, cerrojo inverso y contador | VendingProductService 32/32 · PdoVendingProduct 30/30 · API 30/30 | ✅ |
| **3.** `UPSERT`, orden físico, semáforo, presets, clonación y QA 4 | MachinePlanogramService 35/35 · PdoMachinePlanogram 32/32 · AdminGuard 9/9 | ✅ |
| **4.** Consola de Coordinación y consulta móvil sin costes | UI 38/38 + 82/82; **H-01 y H-03 resueltos** y EARS 05.2.1 certificado | ✅ |
| **5.** Batería global al 100 % | 420 suites · 15.669 aserciones · 0 fallos · exit 0 | ✅ |

**Las 14 tareas** `T-PLN-01`–`T-PLN-14` están completadas en `tasks.md` (14/14 `[x]`) y en `odd/tasks/product-catalog-planogram.md` (14/14 `✅ hecho`). Las siete brechas H-01–H-07 se detectaron después de marcar las tareas y ya están corregidas; el episodio confirma que el cierre de tareas no equivale por sí solo al cierre funcional de la Spec.

---

## 6. Conclusión

El módulo tiene una base sólida: esquema convergente, cerrojo sanitario en dominio y PostgreSQL, `UPSERT` sin borrado físico, presets y clonación, segregación de costes y una batería global de 420 suites en verde.

**H-01 a H-07 están cerrados y certificados**, y la batería global permanece en verde. La Spec 32 queda **cumplida al 100 %**, sin bloqueantes funcionales ni desviaciones documentales abiertas.

El último ajuste corrigió la etiqueta de la Tabla 43 y `PostgresProductPlanogramSchemaTest` certifica **35/35** aserciones.
