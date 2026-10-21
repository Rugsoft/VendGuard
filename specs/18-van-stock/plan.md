# PLAN TÉCNICO: STOCK DE REPUESTOS EN FURGONETA (VAN STOCK)

**Proyecto:** Gestor de Incidencias de Vending (*VendGuard*)  
**Módulo:** 18 · Stock de Repuestos en Furgoneta  
**Documento:** `specs/18-van-stock/plan.md`  
**Referencia Funcional:** [`spec.md`](spec.md) (aprobado 2026-10-21) · **Contratos:** [`../../specs/technical/van_stock_contracts.md`](../technical/van_stock_contracts.md)  
**Tareas:** [`tasks.md`](tasks.md)  
**Batería de referencia:** `php tests/run_all.php`

---

## 1. Resumen de la Solución

El módulo introduce un **saldo de inventario declarado por furgoneta** (`technician_van_stocks`) que se descuenta automáticamente cuando el técnico declara piezas sustituidas, y una **superficie de alerta** cuando ese saldo toca su umbral. No introduce ledger nuevo: el consumo real sigue viviendo donde siempre, en `incident_replaced_parts`.

Tres decisiones estructurales gobiernan el diseño (spec RD-01 a RD-05):

1. **Un solo método de descuento**, invocado desde los dos caminos de declaración existentes.
2. **Una sola sentencia atómica** por descuento (`INSERT ... ON CONFLICT ... DO UPDATE`), sin tocar la transacción de cierre.
3. **Sin coste en el stock**: el snapshot inmutable del Art. III queda intacto.

---

## 2. Arquitectura por Capas

### 2.1. Core / Domain

| Artefacto | Responsabilidad |
| :--- | :--- |
| `src/Core/Domain/Model/VanStockItem.php` **(nuevo)** | Entidad y validaciones de rango (RF-01.2), `isBelowThreshold()` (RF-03.1) e `isNegative()` (RF-03.3). |
| `src/Core/Domain/Repository/VanStockRepositoryInterface.php` **(nuevo)** | Puerto de persistencia: `upsertAbsolute()`, `consume()`, `findByTechnician()`, `findBelowThreshold()`. |
| `src/Core/Domain/Model/AuditEntityType.php` o equivalente **(modificado)** | Valor nuevo `TECHNICIAN_VAN_STOCK` para la auditoría (RF-05.1). Se localiza al implementar el patrón exacto usado por el Módulo 17. |

### 2.2. Application

| Artefacto | Responsabilidad |
| :--- | :--- |
| `src/Application/Service/VanStockService.php` **(nuevo)** | `setStock()` (ajuste absoluto + auditoría del delta), `consume()` (punto único de descuento), `listByTechnician()`, `listBelowThreshold()`. |
| `src/Application/DTO/VanStockAdjustmentDto.php` **(nuevo)** | DTO de entrada tipado con validación de rango y de enteros positivos. |
| `src/Application/Service/SparePartTraceabilityService.php` **(modificado)** | Invoca `consume()` en el bucle de piezas correctivas y en el de preventivas. **Único punto de acoplamiento con el flujo existente** (RD-02). |

### 2.3. Infrastructure

| Artefacto | Responsabilidad |
| :--- | :--- |
| `src/Infrastructure/Repository/PdoVanStockRepository.php` **(nuevo)** | PDO puro; reutiliza el adaptador de dialecto existente si el `ON CONFLICT` lo requiere, como ya hace `PdoSparePartRepository`. |
| `src/Infrastructure/Database/SeedRunner.php` **(modificado)** | `seedVanStocks()` junto a `seedSpareParts()`, con su recuento en `seedAll()`. |

### 2.4. Presentation

| Artefacto | Responsabilidad |
| :--- | :--- |
| `src/Presentation/Controller/TechnicianVanStockController.php` **(nuevo)** | `GET /api/technician/van-stock` (solo el propio técnico, sin parámetro de técnico). |
| `src/Presentation/Controller/CoordinatorVanStockController.php` **(nuevo)** | `GET /api/coordinator/van-stocks` (inventario y alerta) y `PUT /api/coordinator/van-stocks` (carga y ajuste). |
| `src/Presentation/Routing/AppRouter.php` **(modificado)** | Tres rutas tras `InternalAuthMiddleware` con el rol correcto. |
| `src/Presentation/Controller/LocationPortalController.php` **(modificado)** | Lista de claves vedadas del saneador de sede ampliada con las del módulo (RF-06.1). |

### 2.5. Frontend (Vue 3 ESM, sin bundler)

| Artefacto | Responsabilidad |
| :--- | :--- |
| `public/assets/js/components/TechnicianVanStockPanel.js` **(nuevo)** | Listado de solo lectura del propio stock con insignia de bajo umbral y estado vacío explicativo (RF-04.1). |
| `public/assets/js/components/CoordinatorVanStockTab.js` **(nuevo)** | Inventario por técnico, alta/ajuste y alerta ordenada por severidad (RF-03.2, RF-04.2). |
| `public/assets/js/views/TechnicianRouteView.js` **(modificado)** | Monta el panel de stock del técnico. |
| Vista de administración/coordinación que aloja las pestañas de repuestos **(modificada)** | Añade la pestaña de stock; el archivo exacto se identifica al implementar (`AdminSparePartsTab.js` como candidato). |
| `public/assets/js/api.js` **(modificado)** | `technician.getVanStock()`, `coordinator.getVanStocks()`, `coordinator.setVanStock()`. |

---

## 3. Matriz de Trazabilidad RF → Artefactos → Suites

| RF | Artefactos principales | Suites que lo demuestran |
| :--- | :--- | :--- |
| **RF-01** (inventario por técnico/pieza) | `VanStockItem`, `VanStockService::setStock`, `PdoVanStockRepository`, `CoordinatorVanStockController::setStock` | `VanStockItemTest`, `VanStockServiceTest`, `CoordinatorVanStockControllerTest`, `VanStockApiTest` |
| **RF-02** (descuento automático) | `VanStockService::consume`, `SparePartTraceabilityService` (ambos caminos), SQL §6.1 de contratos | `SparePartTraceabilityVanStockTest`, `VanStockApiTest` |
| **RF-03** (alerta de bajo umbral) | `VanStockService::listBelowThreshold`, SQL §6.3, `CoordinatorVanStockTab` | `VanStockServiceTest`, `CoordinatorVanStockControllerTest`, `CoordinatorVanStockTabTest.mjs` |
| **RF-04** (superficies técnico/coordinador) | `TechnicianVanStockController`, `TechnicianVanStockPanel`, `api.js` | `TechnicianVanStockControllerTest`, `TechnicianVanStockPanelTest.mjs` |
| **RF-05** (auditoría y trazabilidad) | `VanStockService::setStock`, enum `audit_entity_type`, `trg_no_delete_*` | `VanStockServiceTest`, `PostgresVanStockSchemaTest` |
| **RF-06** (privacidad Art. V.4) | `AppRouter`, `LocationPortalController` (saneador) | `VanStockPrivacyTest`, `VanStockApiTest` |
| **RNF-02/04** (dogma vanilla y guardas) | Todo el módulo | `DesignTokenDebtRatchetTest`, `ConstitutionalAuditTest`, guardas de esquema |

---

## 4. Plan de Pruebas

### 4.1. Suites nuevas (batería hermética)
* `tests/unit/VanStockItemTest.php` — rangos, `isBelowThreshold`, `isNegative`.
* `tests/unit/VanStockServiceTest.php` — ajuste absoluto + auditoría del delta; alerta ordenada por severidad (negativos primero); listado por técnico.
* `tests/unit/SparePartTraceabilityVanStockTest.php` — **la suite central del módulo**: descuento por cantidad declarada en correctivo; idéntico en preventivo; pieza fuera de catálogo no descuenta ni altera el flujo; stock negativo permitido; descuento aditivo en reapertura; la resolución **no** falla por causa del inventario.
* `tests/unit/TechnicianVanStockControllerTest.php` — solo el propio técnico; sin parámetro de técnico; **no existe mutación** para técnico.
* `tests/unit/CoordinatorVanStockControllerTest.php` — inventario, alerta, ajuste, catálogo de errores (`INVALID_VAN_STOCK_*`, `VAN_STOCK_TARGET_NOT_FOUND`, `VAN_STOCK_ROLE_NOT_TECHNICIAN`).
* `tests/unit/VanStockPrivacyTest.php` — constitucional: sede recibe `403`; proyecciones de sede sin claves de stock (Art. V.4).
* `tests/unit/TechnicianVanStockPanelTest.mjs` y `tests/unit/CoordinatorVanStockTabTest.mjs` — guardas de plantilla: `data-testid`, insignias, estado vacío, sin literales de color nuevos.
* `tests/integration/VanStockApiTest.php` — flujo HTTP real: ajuste → resolución con piezas → alerta.
* `tests/integration/PostgresVanStockSchemaTest.php` — canal `new_engine`: tabla, unicidad por pareja, cotas `CHECK`, disparador anti-borrado, valor nuevo del enum de auditoría.

### 4.2. Suites y guardas modificadas (con su razón)
| Artefacto | Cambio | Razón |
| :--- | :--- | :--- |
| `tests/unit/ConstitutionalAuditTest.php` §7.1 | Retirar `van_inventory` de la lista de términos prohibidos, citando la autorización del Product Owner en el propio mensaje de la aserción | La guarda del Art. VI.1 refleja una prohibición ya levantada; se conservan `telemetry`, `mdb_protocol`, `dex_protocol`, `payment_gateway`, `stripe`, `paypal` |
| `tests/integration/SchemaConvergenceParityTest.php` | `CONTRACT_TABLES` (25 → 26) y recuento de disparadores `BEFORE DELETE` | Lista escrita a mano que el contrato exige mantener exacta |
| `tests/integration/PostgresSchemaTest.php` | `CONTRACT_TABLES` (25 → 26) | Segunda lista escrita a mano |
| `tests/Support/TestDataCleaner.php` + `TestDataCleanerIsolationTest.php` | Orden de purgado de la tabla hija nueva, con su espejo | FK `RESTRICT` obliga a purgar antes que `users`/`spare_parts` |
| Stubs de repositorio en suites unitarias del módulo M2 | Actualizar los que implementan las interfaces afectadas | `SparePartTraceabilityService` recibe una dependencia nueva |
| `tests/Support/DocMetricsGuard.php` + `README.md` / `CHANGELOG.md` | Sincronizar cifras de suites y aserciones | La guarda audita las cifras documentadas |
| `specs/technical/schema_correspondence_contract.md` | Inventario 25 → 26 tablas, columnas de la tabla nueva, blindaje y enumerado | Contrato de esquema canónico |
| `database/supabase_init.sql` | Sección 7 con la tabla 26, valor nuevo del enum, inventarios comentados y **bloque final de recuentos** (tablas, disparadores, restricciones, índices) | El propio fichero declara un recuento verificable al final |

---

## 5. Invariantes que la Implementación Debe Respetar

1. **Ningún camino de cierre depende del inventario:** si el descuento falla de forma recuperable, la línea de pieza y el cierre siguen su curso (Art. V.1, Art. II).
2. **Un solo método de descuento:** prohibido reimplementar el decremento en el camino preventivo.
3. **Sin coste en el stock:** cero columnas monetarias en `technician_van_stocks`.
4. **Sin borrado físico:** la baja es cantidad `0`; el disparador constitucional queda declarado.
5. **Sin claves nuevas de color en JavaScript:** el techo del trinquete de tokens está congelado.
6. **Contrato de resolución intacto:** los payloads de `resolve` y `preventive/complete` no cambian; el descuento es un efecto interno (RNF-05).

---

## 6. Riesgos y Huecos Declarados

| # | Riesgo | Mitigación |
| :--- | :--- | :--- |
| R-1 | Las suites del canal `new_engine` (esquema PostgreSQL real, puerto local 55433) pueden no ser ejecutables en esta sesión | Se intenta; si no hay motor local, se reporta **no ejecutada** con su causa y no se declara verde por omisión |
| R-2 | Las cifras documentadas (`DocMetricsGuard`) y el trinquete de tokens se rompen con facilidad al añadir suites y componentes | Se sincronizan en el mismo work unit que añade la suite, nunca después |
| R-3 | El bucle de piezas correctivas hoy corre con autocommit fuera de la transacción de cierre | Decisión explícita RD-02: el descuento usa una sentencia atómica y no amplía la transacción existente |
| R-4 | La tabla de stock puede quedar con filas de piezas dadas de baja lógica en el catálogo | Se admiten a propósito (una furgoneta conserva piezas descatalogadas) y se documenta en los contratos |
| R-5 | El archivo que aloja la pestaña de repuestos del coordinador no está identificado con certeza | Se confirma al implementar; si la pestaña vive en otro contenedor, la tarea sigue la estructura hallada |

---

## 7. Estrategia de Entrega

* **Rama:** una rama de módulo (precedente del repositorio: `16-…`, `17-operational-zones`) integrada a `main` al cierre.
* **Work units:** cada unidad es una rebanada revisable con su código, sus pruebas y su documentación; las unidades que tocan el flujo de resolución existente (`SparePartTraceabilityService`) llevan **commit propio** por ser cambio de código compartido.
* **Estimación de líneas autorales (código + tests, sin contar specs):** ~1.400 líneas, muy por encima del umbral de ~400, por lo que aplica la estrategia de entrega elegida por el Product Owner antes del primer commit.
