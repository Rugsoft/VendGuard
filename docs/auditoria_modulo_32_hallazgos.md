# AUDITORÍA FUNCIONAL · MÓDULO 32

## Catálogo Maestro de Productos, Consumibles y Planograma por Máquina (*Machine Planogram & Slot Matrix*)

**Proyecto:** Gestor de Incidencias de Vending (*VendGuard*)\
**Módulo:** 32 · `specs/32-product-catalog-planogram`\
**Referencias:** [`spec.md`](../specs/32-product-catalog-planogram/spec.md) v2.0 · [`product_catalog_planogram_spec.md`](../specs/functional/product_catalog_planogram_spec.md) · [`product_catalog_planogram_contracts.md`](../specs/technical/product_catalog_planogram_contracts.md) · [`design.md`](design.md) · [`tasks.md`](../specs/32-product-catalog-planogram/tasks.md) · [`traceability-matrix.md`](../specs/32-product-catalog-planogram/traceability-matrix.md)\
**Fecha de la revisión:** 2026-11-12\
**Alcance:** revisión de solo lectura de `RF-01`–`RF-05`, criterios EARS, `RNF-01`–`RNF-03`, Sistema de Diseño, casos límite §6, decisiones `D1`–`D3`, invariantes `C1`–`C3`, resoluciones `QA 1`–`QA 4`, los 5 criterios de Definition of Done y las 14 tareas `T-PLN-01`–`T-PLN-14`.\
**Resultado:** **la Spec 32 no está cumplida al 100 %.** El núcleo funcional y la batería están en verde, pero se identificaron **7 brechas o desviaciones**: dos bloqueantes funcionales/contractuales y cinco desviaciones menores de copy, cobertura o documentación.

> Este documento es un acta de hallazgos. No modifica código, especificaciones ni tests.

---

## 1. Veredicto ejecutivo

La implementación del catálogo, la matriz de planogramas, el cerrojo sanitario bidireccional, los presets, la clonación y la segregación del reponedor están respaldados por **24 suites específicas y la batería global**, todas en verde:

```text
php tests/run_all.php
420 suites · 15.662 aserciones · 0 fallos
Cifras documentadas: COHERENTES
RESULTADO: 100% EN VERDE
exit=0
```

Las 14 tareas están marcadas como completadas en `tasks.md` y en el acta ODD. Sin embargo, **verde no equivale a Spec completa**: hay un requisito EARS de interfaz sin implementar y un campo de respuesta exigido por el contrato técnico que el servicio no devuelve. Ambos pasan desapercibidos porque sus tests no los comprueban.

**Veredicto:** **NO, la Spec 32 no está 100 % cumplida**.

---

## 2. Evidencia ejecutada

| Suite | Resultado real |
| :--- | ---: |
| `tests/unit/PlanogramCompatibilityValidatorTest.php` | **47/47**, exit 0 |
| `tests/unit/ModelPlanogramPresetCatalogTest.php` | **15/15**, exit 0 |
| `tests/unit/VendingProductServiceTest.php` | **32/32**, exit 0 |
| `tests/unit/MachinePlanogramServiceTest.php` | **33/33**, exit 0 |
| `tests/unit/AdminMachinePlanogramGuardTest.php` | **9/9**, exit 0 |
| `tests/integration/PdoVendingProductRepositoryTest.php` | **30/30**, exit 0 |
| `tests/integration/PdoMachinePlanogramRepositoryTest.php` | **32/32**, exit 0 |
| `tests/unit/FunctionalSpecMirrorParityTest.php` | **24/24**, exit 0 |
| `tests/integration/SchemaConvergenceParityTest.php` | **29/29**, exit 0 |
| `tests/integration/PostgresProductPlanogramSchemaTest.php` | **35/35**, exit 0 |
| `tests/integration/ProductCatalogPlanogramApiTest.php` | **28/28**, exit 0 |
| `tests/integration/PlanogramConstitutionalTest.php` | **31/31**, exit 0 |
| `tests/integration/PostgresSchemaTest.php` | **135/135**, exit 0 |
| `tests/integration/PostgresConstitutionalTest.php` | **80/80**, exit 0 |
| `tests/integration/OpportunisticSchemaCorrespondenceTest.php` | **32/32**, exit 0 |
| `tests/frontend/coordinator_planogram_tab.test.mjs` | **36/36**, exit 0 |
| `tests/frontend/restocker_route_view.test.mjs` | **79/79**, exit 0 |
| `tests/unit/CoordinatorButtonVariantsGuardTest.mjs` | **22/22**, exit 0 |
| `tests/unit/LegacyButtonPaletteRetirementTest.mjs` | **18/18**, exit 0 |
| `tests/unit/DesignTokenDebtRatchetTest.mjs` | **3/3**, exit 0 |
| `tests/unit/FrontendTemplateCompileTest.mjs` | **15/15**, exit 0 · 78 plantillas |
| `tests/unit/MobileShellLayoutGuardTest.mjs` | **7/7**, exit 0 |
| `tests/unit/OfflinePwaAssetsAndUiGuardTest.mjs` | **45/45**, exit 0 |
| `tests/unit/AuditEntityTypesTest.php` | **41/41**, exit 0 |
| Batería global `php tests/run_all.php` | **420 suites · 15.662 aserciones · 0 fallos**, exit 0 |

### 2.1. Nombres de suite solicitados que no existen

- `tests/integration/SchemaCorrespondenceTest.php` → **no existe**. Los equivalentes reales son `SchemaConvergenceParityTest.php` (29/29) y `PostgresSchemaTest.php` (135/135). `OpportunisticSchemaCorrespondenceTest.php` (32/32) certifica el Módulo 16, no el 32.
- `tests/unit/LegacyButtonPaletteRetirementTest.php` → **no existe como `.php`**. El archivo real es `tests/unit/LegacyButtonPaletteRetirementTest.mjs` (18/18).

---

## 3. Matriz de hallazgos

| ID | Hallazgo | Severidad | Estado |
| :--- | :--- | :---: | :---: |
| **H-01** | Falta el estado sanitario en la tarjeta de máquina del reponedor | **Bloqueante** | Abierto |
| **H-02** | Falta `compatible_source_machines` en la respuesta del planograma de Coordinación | **Alta** | Abierto |
| **H-03** | Copy de la insignia de producto dado de baja no coincide con la Spec | **Baja** | Abierto |
| **H-04** | Cobertura de tests parcial en filtros y en la invariante de clonación fallida | **Media** | Abierto |
| **H-05** | Nombres de suite solicitados inexistentes o con extensión incorrecta | **Informativa** | Abierto |
| **H-06** | El contrato técnico declara 27 tipos ENUM y el motor certifica 37 | **Baja documental** | Abierto |
| **H-07** | Etiqueta «once columnas» para una tabla de diez columnas | **Informativa** | Abierto |

---

## 4. Detalle de los hallazgos

### H-01 · Falta el estado sanitario en la tarjeta del reponedor

**Severidad:** Bloqueante · **Requisito afectado:** `EARS 05.2.1`, `QA 2`, Art. II.

**Qué exige la Spec.** [`spec.md:211`](../specs/32-product-catalog-planogram/spec.md) obliga a que cada tarjeta de máquina del reponedor muestre «código, modelo, sede, zona, **estado sanitario** y el resumen de su planograma».

**Qué implementa el código.** [`RestockerRouteView.js:750-775`](../public/assets/js/components/RestockerRouteView.js) pinta código, modelo, tipología, sede, zona, contadores y llenado, pero **no referencia `machine.sanitary_status` en ninguna parte del componente**. El backend sí entrega el dato: [`PdoMachinePlanogramRepository.php:487`](../src/Infrastructure/Repository/PdoMachinePlanogramRepository.php) lo incluye en la proyección de la máquina.

**Por qué los tests no lo detectan.** El fixture de [`restocker_route_view.test.mjs`](../tests/frontend/restocker_route_view.test.mjs) declara `sanitary_status: 'OK'`, pero las 79 aserciones no comprueban que se renderice. `PdoMachinePlanogramRepositoryTest` certifica que el DTO lo contiene; la vista no lo consume.

**Impacto.** El reponedor no ve una cuarentena sanitaria en la tarjeta de la máquina. Es un dato operativo crítico del Art. II: puede abrir y reponer un equipo en cuarentena sin advertirlo.

**Corrección propuesta.** Renderizar una insignia de estado sanitario en la tarjeta con los tokens semánticos de error/advertencia, reutilizando `sanitary_status`, y añadir una aserción en `restocker_route_view.test.mjs` que verifique su presencia y su texto para `QUARANTINE`.

---

### H-02 · Falta `compatible_source_machines` en el contrato del planograma

**Severidad:** Alta · **Requisito afectado:** contrato técnico §3.2, `RF-04`, `D1`.

**Qué exige el contrato.** [`product_catalog_planogram_contracts.md:429`](../specs/technical/product_catalog_planogram_contracts.md) define que `GET /api/coordinator/machines/{id}/planogram` devuelve `machine`, `summary`, `slots` y `compatible_source_machines`.

**Qué implementa el código.** [`MachinePlanogramService.php:97-101`](../src/Application/Service/MachinePlanogramService.php) devuelve únicamente `machine`, `summary` y `slots`. El campo no existe en el servicio ni en la respuesta del controlador. [`CoordinatorPlanogramTab.js:224-237`](../public/assets/js/components/CoordinatorPlanogramTab.js) lo suple en cliente: si no llega del contrato, calcula máquinas del **mismo `machine_type`**.

**Por qué los tests no lo detectan.** `ProductCatalogPlanogramApiTest` 3.3 solo comprueba `machine`, `summary`, carriles y `unit_cost_eur`. El test de componente 4.5 prueba el fallback local, no la respuesta contractual.

**Impacto.** La UI funciona para el caso común, pero el contrato REST queda incumplido y cualquier otro consumidor de la API no recibe la lista de orígenes compatibles. Además, el fallback por tipo exacto puede ocultar orígenes compatibles de otra tipología.

**Corrección propuesta.** Implementar `compatible_source_machines` en `getCoordinatorPlanogram()` con las máquinas activas compatibles y su `active_slots_count`, y añadir una aserción de contrato en `ProductCatalogPlanogramApiTest`.

---

### H-03 · Copy de la insignia de producto inactivo

**Severidad:** Baja · **Requisito afectado:** `EARS 01.3.2`, `C1`, caso §6.5.

**Qué exige la Spec.** La insignia literal es **`⚠️ Producto dado de baja (Sustituir)`** ([`spec.md:146`](../specs/32-product-catalog-planogram/spec.md)).

**Qué implementa el código.** [`CoordinatorPlanogramTab.js:833`](../public/assets/js/components/CoordinatorPlanogramTab.js) pinta `⚠️ Producto dado de baja`; [`RestockerRouteView.js:882`](../public/assets/js/components/RestockerRouteView.js) pinta `⚠️ Sustituir`.

**Impacto.** El estado y la acción se comunican, pero no con el texto canónico. Es una desviación de copy, no de lógica.

**Corrección propuesta.** Unificar el texto exacto en ambas superficies y añadir una aserción de copy si se considera contrato visual.

---

### H-04 · Cobertura de tests parcial en filtros e invariantes

**Severidad:** Media · **Requisito afectado:** `RF-05.1`, contrato de filtros del reponedor, `EARS 04.2.2`.

**Qué falta cubrir:**

1. **Filtro por zona operativa en Coordinación.** La UI no tiene un selector dedicado: [`CoordinatorPlanogramTab.js:100`](../public/assets/js/components/CoordinatorPlanogramTab.js) declara `location_id` y `machine_type`, y la zona solo se puede buscar como texto libre. `coordinator_planogram_tab.test.mjs` cubre sede (3.5) y tipología (3.6), pero no zona.
2. **Filtros opcionales del reponedor.** `GET /api/restocker/machines` implementa `?zone_id=` y `?stock_alert_only=` en [`MachinePlanogramService.php:478-498`](../src/Application/Service/MachinePlanogramService.php), pero no hay aserción HTTP para `stock_alert_only`.
3. **Destino intacto tras clonación rechazada.** `clonePlanogram()` valida la compatibilidad antes de escribir, pero `MachinePlanogramServiceTest` 5.5 solo comprueba el código de error; no verifica que la máquina destino conserve sus carriles previos.

**Impacto.** No se ha demostrado una regresión funcional, pero estas invariantes pueden romperse sin que la batería lo detecte.

**Corrección propuesta.** Añadir las tres aserciones con el mismo arnés real ya existente.

---

### H-05 · Nombres de suite solicitados inexistentes

**Severidad:** Informativa · **Requisito afectado:** reproducibilidad de la auditoría.

- `tests/integration/SchemaCorrespondenceTest.php`: **no existe**. Equivalentes reales: `SchemaConvergenceParityTest.php` (29/29), `PostgresSchemaTest.php` (135/135) y `OpportunisticSchemaCorrespondenceTest.php` (32/32, Módulo 16).
- `tests/unit/LegacyButtonPaletteRetirementTest.php`: **no existe como `.php`**. El real es `LegacyButtonPaletteRetirementTest.mjs` (18/18).

**Impacto.** Un comando de auditoría copiado literalmente fallaría por archivo inexistente, aunque la cobertura real sí existe.

**Corrección propuesta.** Corregir las referencias en los documentos de auditoría o tareas que citen esos nombres.

---

### H-06 · Recuento de tipos ENUM desactualizado en el contrato

**Severidad:** Baja documental · **Requisito afectado:** contrato de esquema §2.

**Qué declara el contrato.** [`product_catalog_planogram_contracts.md:40`](../specs/technical/product_catalog_planogram_contracts.md) afirma que el esquema pasa de «25 a 27 tipos `ENUM` nativos».

**Qué certifica el motor.** `SchemaConvergenceParityTest` 29/29 declara **43 conjuntos ENUM / 37 tipos**; el ODD ya lo había registrado como desviación declarada en `T-PLN-02`.

**Impacto.** No afecta al comportamiento, pero contradice la fuente técnica y puede confundir auditorías futuras.

**Corrección propuesta.** Actualizar la frase del contrato a los 37 tipos reales o eliminar el total absoluto y referenciar la certificación de convergencia.

---

### H-07 · Etiqueta «once columnas» para una tabla de diez

**Severidad:** Informativa · **Requisito afectado:** mensaje del test de esquema.

**Qué ocurre.** [`PostgresProductPlanogramSchemaTest.php:316`](../tests/integration/PostgresProductPlanogramSchemaTest.php) titula «La tabla declara sus once columnas canónicas», pero el array comparado contiene **diez** columnas y el DDL de la Tabla 43 también declara diez.

**Impacto.** El test pasa porque compara el array correcto; solo el mensaje está mal. Puede inducir a error al leer el reporte.

**Corrección propuesta.** Cambiar «once» por «diez» en el título de la aserción.

---

## 5. Estado de los criterios de finalización

| DoD | Evidencia | Estado |
| :--- | :--- | :---: |
| **1.** Tablas 42/43, tipos, `CHECK`, anti-borrado y entidades auditables | Schema 35/35 · Convergencia 29/29 · AuditEntityTypes 41/41 | ✅ |
| **2.** CRUD con SKU inmutable, cotas, cerrojo inverso y contador | VendingProductService 32/32 · PdoVendingProduct 30/30 · API 28/28 | ✅ |
| **3.** `UPSERT`, orden físico, semáforo, presets, clonación y QA 4 | MachinePlanogramService 33/33 · PdoMachinePlanogram 32/32 · AdminGuard 9/9 | ✅ |
| **4.** Consola de Coordinación y consulta móvil sin costes | UI 36/36 + 79/79; el DoD literal se cumple, pero **EARS 05.2.1 sigue abierto por H-01** | ⚠️ |
| **5.** Batería global al 100 % | 420 suites · 15.662 aserciones · 0 fallos · exit 0 | ✅ |

**Las 14 tareas** `T-PLN-01`–`T-PLN-14` están completadas en `tasks.md` (14/14 `[x]`) y en `odd/tasks/product-catalog-planogram.md` (14/14 `✅ hecho`). La existencia de H-01 y H-02 demuestra que el cierre de tareas no equivale al cierre funcional de la Spec.

---

## 6. Conclusión

El módulo tiene una base sólida: esquema convergente, cerrojo sanitario en dominio y PostgreSQL, `UPSERT` sin borrado físico, presets y clonación, segregación de costes y una batería global de 420 suites en verde.

**Pero la Spec 32 no puede declararse 100 % cumplida** mientras H-01 y H-02 sigan abiertos. La prioridad de corrección es:

1. **H-01** · estado sanitario en la tarjeta del reponedor.
2. **H-02** · `compatible_source_machines` en la respuesta contractual.
3. **H-04** · aserciones de filtros e invariante de clonación.
4. **H-03, H-05, H-06 y H-07** · copy, referencias y documentación.

Cuando H-01 y H-02 estén implementados y cubiertos por tests, deberá repetirse la auditoría y la batería global antes de cambiar el veredicto.
