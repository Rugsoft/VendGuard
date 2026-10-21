# TAREAS DE IMPLEMENTACIÓN: STOCK DE REPUESTOS EN FURGONETA (VAN STOCK)

**Proyecto:** Gestor de Incidencias de Vending (*VendGuard*)  
**Módulo:** 18 · Stock de Repuestos en Furgoneta  
**Documento:** `specs/18-van-stock/tasks.md`  
**Spec:** [`spec.md`](spec.md) · **Plan:** [`plan.md`](plan.md) · **Contratos:** [`../../specs/technical/van_stock_contracts.md`](../technical/van_stock_contracts.md)  
**Política de pruebas:** por defecto *test-first*. Cada tarea declara su prueba en ROJO antes de implementar, y la batería hermética (`php tests/run_all.php`) más las suites JS (`tests/unit/*.mjs`) cierran la verificación.

**Rama de trabajo:** `18-van-stock` (una rama de módulo, precedente del repositorio). **Estrategia de entrega:** pendiente de la elección del Product Owner (ver plan §7).

---

## Work Units (rebanadas de commit)

| WU | Tareas | Contenido | Observación |
| :--- | :--- | :--- | :--- |
| **WU-1 · Esquema** | T-VAN-01 | Tabla 26, enum de auditoría, semillas, contrato de esquema y suites de esquema | Toca esquema persistente: rebanada aislada |
| **WU-2 · Dominio y aplicación** | T-VAN-02, T-VAN-03, T-VAN-04 | Entidad, puerto, repositorio PDO, servicio y DTO | Sin acoplamiento aún con el flujo existente |
| **WU-3 · Descuento en el flujo** | T-VAN-05 | `SparePartTraceabilityService` + stubs de pruebas | **Commit propio**: modifica código compartido por toda resolución de avería → `Risk: item 3` |
| **WU-4 · API** | T-VAN-06, T-VAN-07 | Controladores, rutas, saneador de sede y suite constitucional de privacidad | — |
| **WU-5 · Frontend** | T-VAN-08, T-VAN-09 | Panel del técnico y pestaña de coordinación (+ `api.js` del padre) | Dos unidades independientes con superficies disjuntas: candidatas a redacción en paralelo, con `api.js` reservado al padre |
| **WU-6 · Guardas y cierre** | T-VAN-10, T-VAN-11 | Enmienda de la guarda del Art. VI, cifras documentadas, cierre documental y batería final | Cierra el módulo |

---

## Tareas

### T-VAN-01 · Esquema de la tabla de stock y su contrato
* **Refs:** RF-01.1, RF-01.3, RF-05.2 · Contratos §2
* **Ruta:** inline
* **Prueba en rojo:** `tests/integration/PostgresVanStockSchemaTest.php` (canal `new_engine`) + ajuste de las guardas de esquema.
* **Alcance:** `database/supabase_migrations/009_technician_van_stocks.sql`; `database/supabase_init.sql` (sección 7, valor de enum, inventarios comentados y bloque final de recuentos); `database/supabase_seeds.sql`; `SeedRunner::seedVanStocks()`; `specs/technical/schema_correspondence_contract.md`; `CONTRACT_TABLES` de `SchemaConvergenceParityTest` y `PostgresSchemaTest`; `TestDataCleaner` + su suite de aislamiento.
* **Criterio de cierre:** la tabla existe con su unicidad por pareja, sus cotas `CHECK` y su disparador anti-borrado; las guardas de esquema quedan en verde.

### T-VAN-02 · Entidad de dominio `VanStockItem`
* **Refs:** RF-01.2, RF-03.1, RF-03.3
* **Ruta:** inline
* **Prueba en rojo:** `tests/unit/VanStockItemTest.php` (rangos, umbral, negatividad).
* **Alcance:** `src/Core/Domain/Model/VanStockItem.php`.

### T-VAN-03 · Puerto y repositorio PDO
* **Refs:** RF-01.1, RF-02.3, RF-03.2 · Contratos §6
* **Ruta:** inline
* **Prueba en rojo:** cobertura vía `VanStockServiceTest` con stub del puerto + `VanStockApiTest`.
* **Alcance:** `src/Core/Domain/Repository/VanStockRepositoryInterface.php`, `src/Infrastructure/Repository/PdoVanStockRepository.php` (upsert absoluto, descuento atómico, listados).

### T-VAN-04 · Servicio de aplicación y DTO
* **Refs:** RF-01.1, RF-03, RF-04, RF-05.1
* **Ruta:** inline
* **Prueba en rojo:** `tests/unit/VanStockServiceTest.php` (ajuste absoluto con auditoría del delta; alerta ordenada con negativos primero; listado por técnico).
* **Alcance:** `src/Application/Service/VanStockService.php`, `src/Application/DTO/VanStockAdjustmentDto.php`.

### T-VAN-05 · Descuento automático en los dos caminos de declaración
* **Refs:** RF-02.1, RF-02.2, RF-02.3, RF-02.4 (RD-01 a RD-04)
* **Ruta:** inline · **Riesgo:** `item 3` (código compartido por toda resolución) → commit propio
* **Prueba en rojo:** `tests/unit/SparePartTraceabilityVanStockTest.php`: descuento por cantidad declarada en correctivo; idéntico en preventivo; fuera de catálogo no descuenta ni altera el flujo; negativo permitido; aditivo en reapertura; **la resolución no falla por causa del inventario**.
* **Alcance:** `src/Application/Service/SparePartTraceabilityService.php` (invocación del método único en ambos bucles) + actualización de los stubs de repositorio que la interfaz o el constructor afecten.
* **Invariante:** un solo método de descuento; prohibido duplicar el decremento en el camino preventivo.

### T-VAN-06 · API de stock (técnico y coordinación)
* **Refs:** RF-03.2, RF-04.1, RF-04.2, RF-04.3 · Contratos §4 y §5
* **Ruta:** inline
* **Prueba en rojo:** `tests/unit/TechnicianVanStockControllerTest.php`, `tests/unit/CoordinatorVanStockControllerTest.php`, `tests/integration/VanStockApiTest.php`.
* **Alcance:** `TechnicianVanStockController`, `CoordinatorVanStockController`, rutas en `AppRouter.php` tras el middleware de rol.

### T-VAN-07 · Privacidad constitucional (Art. V.4)
* **Refs:** RF-06.1, RF-06.2, RF-06.3
* **Ruta:** inline
* **Prueba en rojo:** `tests/unit/VanStockPrivacyTest.php` (sede recibe `403`; proyecciones de sede sin claves de stock).
* **Alcance:** ampliación de la lista de claves vedadas del saneador de `LocationPortalController`.

### T-VAN-08 · Panel de stock del técnico (frontend)
* **Refs:** RF-04.1, RF-01 (lectura)
* **Ruta:** inline o escritor delegado (superficie disjunta de T-VAN-09; `api.js` lo edita el padre)
* **Prueba en rojo:** `tests/unit/TechnicianVanStockPanelTest.mjs`.
* **Alcance:** `public/assets/js/components/TechnicianVanStockPanel.js` + montaje en `public/assets/js/views/TechnicianRouteView.js`.

### T-VAN-09 · Pestaña de stock en coordinación (frontend)
* **Refs:** RF-03.2, RF-04.2
* **Ruta:** inline o escritor delegado (superficie disjunta de T-VAN-08; `api.js` lo edita el padre)
* **Prueba en rojo:** `tests/unit/CoordinatorVanStockTabTest.mjs`.
* **Alcance:** `public/assets/js/components/CoordinatorVanStockTab.js` + montaje de la pestaña en la vista de coordinación que aloja repuestos.

### T-VAN-10 · Guardas de alcance y cifras documentadas
* **Refs:** spec §8.2 · RNF-04
* **Ruta:** inline
* **Alcance:** `tests/unit/ConstitutionalAuditTest.php` §7.1 (retirada de `van_inventory` con la autorización citada); sincronización de cifras en `README.md`/`CHANGELOG.md` conforme a `DocMetricsGuard`; verificación de que el trinquete de tokens sigue en su techo.

### T-VAN-11 · Cierre documental y verificación final
* **Refs:** spec §9
* **Ruta:** inline
* **Alcance:** espejo `specs/functional/van_stock_spec.md`; estado real de la feature #5 en `docs/features_pendientes.md` (documento no versionado, `.gitignore:21`); ejecución de la batería completa y reporte honesto de toda prueba no ejecutada con su causa.

---

## Dependencias

```
T-VAN-01 ─┐
T-VAN-02 ─┼─> T-VAN-04 ─> T-VAN-05 ─> T-VAN-06 ─┬─> T-VAN-07
T-VAN-03 ─┘                                     ├─> T-VAN-08 ─┐
                                                └─> T-VAN-09 ─┴─> T-VAN-10 ─> T-VAN-11
```

---

## Bitácora de ejecución

### WU-1 · T-VAN-01 (esquema) — cerrado
* **Commit:** `feat(van-stock): add the technician van stock schema with its guards`
* **Rojo observado:** `PostgresVanStockSchemaTest` 3 fallos — relación `technician_van_stocks` inexistente (`42P01`).
* **Verde:** suite nueva 18/18 · `SchemaConvergenceParityTest` 18/18 · `PostgresSchemaTest` 83/83 · `PostgresConstitutionalTest` 63/63 · `TestDataCleanerIsolationTest` 50/50 · batería completa 307 suites / 11 985 aserciones / 0 fallos, cifras documentadas coherentes.
* **Canal `new_engine`:** el DDL base y la migración `009` se aplican en un esquema aislado y convergen; la base de la batería se reprovisionó con DDL base + `009` + semillas (`VAN_STOCK_ROWS=5`).
* **Desvío corregido dentro de la rebanada:** el enumerado `audit_entity_type` ganó `TECHNICIAN_VAN_STOCK`, así que `AuditEvent::ENTITY_TYPES` y la etiqueta del visor (`AuditActionLabels.js`) debían incorporarlo en el mismo commit: `AuditEntityTypesTest` y `AuditActionCatalogTest` exigen que el catálogo del dominio y el del visor no divergan del enumerado de la base.
* **Recuento de esquema:** 26 tablas · 39 disparadores · 40 restricciones nombradas · 14 `CHECK` · 41 claves foráneas · 63 índices secundarios.
* **Riesgo:** `none` — todo es aditivo: tabla nueva, valor de enumerado nuevo y semillas nuevas; ninguna fila ni columna existente cambia de forma o de significado.
