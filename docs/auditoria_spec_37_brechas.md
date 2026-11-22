# Auditoría de la Spec 37 · Brechas y fallos detectados

- **Módulo:** 37 · Planificador de Rutas Periódicas de Carga y Priorización Dinámica por Demanda (`37-restock-route-planner`)
- **Especificación auditada:** [`specs/37-restock-route-planner/spec.md`](../specs/37-restock-route-planner/spec.md) (v1.2 · `D1`–`D7`, `C1`–`C3`, `QA-1`–`QA-6`)
- **Espejo funcional:** [`specs/functional/restock_route_planner_spec.md`](../specs/functional/restock_route_planner_spec.md)
- **Contratos técnicos:** [`specs/technical/restock_route_planner_contracts.md`](../specs/technical/restock_route_planner_contracts.md)
- **Sistema de Diseño:** [`docs/design.md`](design.md)
- **Plan y tareas:** [`specs/37-restock-route-planner/plan.md`](../specs/37-restock-route-planner/plan.md) · [`specs/37-restock-route-planner/tasks.md`](../specs/37-restock-route-planner/tasks.md)
- **Matriz de trazabilidad:** [`specs/37-restock-route-planner/traceability-matrix.md`](../specs/37-restock-route-planner/traceability-matrix.md)
- **Fecha de auditoría:** 2026-11-22
- **Última actualización:** 2026-11-22 · AUD-37-01, AUD-37-02, AUD-37-03, AUD-37-04, AUD-37-05, AUD-37-06 y AUD-37-07 cerradas; batería global **448/17.375/0**, `new_engine` **76/76 · 2.592 aserciones** y **residuo 0** de esquemas temporales
- **Rama:** `feat/restock-route-planner`
- **Base auditada:** `32904325c6f8bcf064f1c37ff5269d4c1121fc2a` (auditoría original) · `b7094ca` (cierre de AUD-37-03) · cambios de AUD-37-04 en el árbol de trabajo · `83f64ed` (cierre de AUD-37-06) · cierre de AUD-37-07
- **Alcance:** revisión de `RF-01`–`RF-05` y sus criterios EARS, `RNF-01`–`RNF-04`, `D1`–`D7`, `C1`–`C3`, `QA-1`–`QA-6`, §6, los 5 criterios de Definition of Done y las 13 tareas `T-RRP-01`–`T-RRP-13`, con ejecución real de las suites en los dos canales.

> Este documento nació como acta de hallazgos. Las secciones marcadas como cerradas documentan las correcciones posteriores y su evidencia reproducible; las que siguen abiertas no se consideran cumplidas.

---

## 1. Veredicto ejecutivo

**La Spec 37 no está cumplida al 100 % en la fecha de la auditoría.** La implementación cubre la mayor parte del alcance y, tras cerrar AUD-37-01, AUD-37-02, AUD-37-03, AUD-37-04, AUD-37-06 y AUD-37-07, la certificación global vuelve a estar en verde, el layout M37 está medido en Chromium real, ambas bases quedan con **residuo 0** de esquemas temporales y el EARS 02.2.1 queda alineado y probado en HTTP; siguen abiertas las dos brechas P3 (`AUD-37-08` y `AUD-37-09`).

Resultado real de la batería global tras las correcciones de AUD-37-01, AUD-37-02, AUD-37-03, AUD-37-04 y AUD-37-06:

```text
source deploy/local/postgres/.env.battery && php tests/run_all.php

Total Suites Ejecutadas    : 448
Total Aserciones Evaluadas : 17.375
Fallos Detectados          : 0
Cifras documentadas        : COHERENTES
Base de datos restablecida : SÍ (Semillas intactas)
RESULTADO: 100% EN VERDE. (0 errors, 0 failures)
exit=0
```

Resultado real del canal `new_engine` tras cerrar AUD-37-07:

```text
source deploy/local/postgres/.env.docker-tls && php tests/run_new_engine.php

Suites evaluadas : 76
Suites en verde  : 76
No ejecutables   : 0
Aserciones       : 2.592
Fallos           : 0
RESULTADO: 100% EN VERDE. CANAL new_engine CERRADO.
exit=0
```

**Conclusión corta:** esquema, API, RBAC, opacidad, perímetro M32–M36, `TODAY_ROUTE` y las superficies M37 están sólidos; la batería global y `new_engine` están en verde y ambas bases quedan libres de esquemas temporales (`0` filas). Tras cerrar AUD-37-02 (C1), AUD-37-03 (orden de `uncovered_stops`), AUD-37-04 (layout real `360 × 800`), AUD-37-06 (limpieza `finally`) y AUD-37-07 (`403`/`409`/`422` en omisión), solo siguen abiertas las dos brechas P3 de cobertura y verificación (`AUD-37-08` y `AUD-37-09`).

---

## 2. Matriz de brechas

| Orden | ID | Prioridad | Brecha | Requisito afectado | Estado |
|---:|---|---|---|---|---|
| **1** | **AUD-37-01** | **P1 · Bloqueante de certificación** | `coordinator_restock_route_planner.test.mjs` falla por fechas hardcodeadas; la batería global queda en rojo | `RF-05.3`, DoD 5 | **Cerrada (2026-11-22)** |
| **2** | **AUD-37-02** | **P1 · Funcional / Art. II** | La pausa estacional bloqueaba máquinas con aviso L1 y los contadores contaban máquinas bloqueadas como demanda inyectada | `RF-03.1.3`, `QA-6`, Art. II / Art. VI | **Cerrada (2026-11-22)** |
| **3** | **AUD-37-03** | **P1 · Funcional** | `uncovered_stops` no respeta el orden determinista del EARS 03.3.1 (FEFO → L1/`SlaTier` → franja) | `RF-03.3.1`, `C2`, `QA-4` | **Cerrada (2026-11-22)** |
| **4** | **AUD-37-04** | **P2 · Cobertura** | No existe prueba de navegador `360 × 800` de las superficies M37; la suite citada mide el M36 | `RF-05.2.2`, `RNF-04`, DoD 4 | **Cerrada (2026-11-22)** |
| **5** | **AUD-37-05** | **P2 · Documental** | La matriz de trazabilidad y el cierre de `T-RRP-13` declaraban cifras que no eran reproducibles | DoD 5, `tasks.md`, matriz | **Cerrada (2026-11-22)** |
| **6** | **AUD-37-06** | **P2 · Limpieza** | Quedan 3 esquemas temporales residuales tras corridas limpias | DoD 5 | **Cerrada (2026-11-22)** |
| **7** | **AUD-37-07** | **P3 · Contradicción normativa** | El EARS 02.2.1 pide `403` para máquina bloqueada; el contrato y el código devuelven `409` | `RF-02.2.1`, contrato §4 | **Cerrada (2026-11-22)** |
| **8** | **AUD-37-08** | **P3 · Cobertura** | Varios criterios EARS no tienen aserción directa | `RF-01.3`, `RF-02.3.1`, `RF-03.1.1`, `RF-03.2.4`, `RF-05.1.3` | **Abierta** |
| **9** | **AUD-37-09** | **P3 · Verificación** | La convergencia 1:1 de semillas y la ausencia de N+1 no se comprueban de forma automatizada | `RNF-01`, `RNF-02`, DoD 1 | **Abierta** |

---

## 3. Detalle de las brechas

### AUD-37-01 · Test de Coordinación dependiente de la fecha (cerrada)

**Estado:** **Cerrada el 2026-11-22.** El test deriva `TODAY` y `TOMORROW` de `Intl.DateTimeFormat('en-CA', { timeZone: 'Europe/Madrid' })`, igual que el componente, y sustituye todos los literales `2026-11-21`/`2026-11-22` por esas constantes. Evidencia tras la corrección: `node tests/frontend/coordinator_restock_route_planner.test.mjs` → **32/32, 0 fallos, `exit=0`**; batería global → **448/17.373/0, `DocMetricsGuard` coherente, `exit=0`**.

**Evidencia original:**

```text
node tests/frontend/coordinator_restock_route_planner.test.mjs

[PASS] 1.5 La fecha futura se marca como proyección
[FAIL] 1.6 Cambiar a mañana activa la proyección

Aserciones: 32 · Fallos: 1
RESULTADO: EN ROJO
exit=1
```

**Causa.** El test fija la fecha de servicio en `2026-11-21` y después en `2026-11-22`:

- [`tests/frontend/coordinator_restock_route_planner.test.mjs:293-294`](../tests/frontend/coordinator_restock_route_planner.test.mjs)
- El componente compara con el día real mediante `todayIso()`:
  [`public/assets/js/components/CoordinatorRestockRoutePlannerSubTab.js:160-161`](../public/assets/js/components/CoordinatorRestockRoutePlannerSubTab.js)

El 2026-11-22, `2026-11-22 > today` es falso; por eso `isFuture` vale `false` y la aserción 1.6 falla. Es una bomba de tiempo: el 2026-11-21 pasaba y cualquier fecha posterior vuelve a fallar.

**Impacto.** Tumba la batería global (DoD 5), descuadra el contador de aserciones y hace que `DocMetricsGuard` reporte 5 desviaciones en `README.md` y `docs/features_pendientes.md`.

**Corrección propuesta.** Calcular las fechas desde el reloj inyectado o desde `todayIso()`: `today`, `today + 1 día` y `today - 1 día`; no volver a fijar literales. Añadir una aserción de fecha pasada. Verificar con la suite individual y después con la batería global.

---

### AUD-37-02 · Pausa estacional y contadores de demanda (cerrada)

**Estado:** **Cerrada el 2026-11-22.** El Product Owner acordó que prevalece **C1**: la pausa estacional suprime `SCHEDULED`, `EMPTY_SLOT` y `L1_RESTOCKER`; solo una `FEFO_ALERT` viva mantiene la parada activa. Los avisos L1 suprimidos se exponen en `blocked_stops.suppressed_triggers` para que Coordinación pueda desviarlos, escalarlos o atenderlos al reabrir la temporada. La especificación se elevó a **v1.2** en sus dos copias y se alinearon C1, QA-6, §6.6 y D3/RF-03.1.4. La suite propia pasa de 88 a **103 aserciones** y las entonces 11 suites propias sumaban **502**.

**Evidencia final.** `php tests/unit/RestockRoutePlannerServicesTest.php` → **103/103 · 0 fallos · `exit=0`**; batería global → **448/17.373/0 · `DocMetricsGuard` coherente · `exit=0`**; canal `new_engine` → **76/76 · 2.590 aserciones · 0 fallos · `exit=0`**.

**Evidencia original (antes de la decisión).**

- [`spec.md:174`](../specs/37-restock-route-planner/spec.md) (EARS 03.1.3): una máquina en pausa estacional **no** evalúa `SCHEDULED` ni `EMPTY_SLOT`; **entra como parada activa si tiene `FEFO_ALERT` o un aviso `L1_RESTOCKER` abierto**. Si no, y **si tenía agenda activa**, se lista informativamente en `blocked_stops`.
- [`spec.md:50`](../specs/37-restock-route-planner/spec.md) (C1): las máquinas en pausa estacional **salen de Haversine y de `TODAY_ROUTE`** hacia la sección segregada, **salvo alerta FEFO viva**.

Las dos reglas no dicen lo mismo sobre el aviso L1. La implementación sigue C1, no el EARS 03.1.3.

**Evidencia en código.**

- [`src/Application/Service/RestockRoutePlannerService.php:483-485`](../src/Application/Service/RestockRoutePlannerService.php): añade `SEASONAL_PAUSE` a los motivos de bloqueo en cuanto la pausa está vigente y no hay FEFO, sin mirar si hay L1.
- [`src/Application/Service/RestockRoutePlannerService.php:574-576`](../src/Application/Service/RestockRoutePlannerService.php): añade `EMPTY_SLOT` a los disparadores aunque la máquina esté pausada.
- [`src/Application/Service/RestockRoutePlannerService.php:608`](../src/Application/Service/RestockRoutePlannerService.php): `has_demand` es `L1 || empty`, sin excluir las bloqueadas.
- [`src/Application/Service/RestockRoutePlannerService.php:262-264`](../src/Application/Service/RestockRoutePlannerService.php): incrementa `injected_demand_count` antes de resolver el estado.

**Evidencia en ejecución.** Sondeo aislado con dos máquinas en pausa estacional (una con L1 y otra con 3 huecos vacíos), sin agenda y sin FEFO:

```text
paused+L1 pending_ids=[]
blocked_stops=[{"machine_id":1,"reasons":["SEASONAL_PAUSE"]},{"machine_id":2,"reasons":["SEASONAL_PAUSE"]}]
kpis={"scheduled_machines_count":0,"injected_fefo_count":0,"injected_demand_count":2,"completed_count":0,"skipped_count":0,"blocked_count":2,"uncovered_count":0}
```

**Impacto.**

1. Una parada con aviso L1 puede quedar fuera de la ruta si la máquina está en pausa estacional, en contra del EARS 03.1.3.
2. Una máquina sin agenda y sin trabajo aparece en `blocked_stops`, cuando el EARS 03.1.3 solo lo permite si tenía agenda activa.
3. `injected_demand_count` cuenta máquinas bloqueadas; el tablero de Coordinación publica una demanda que no existe en la ruta. El mismo doble conteo afecta a cualquier máquina en cuarentena con huecos vacíos.

**Cobertura original.** Ninguna suite M37 usaba `is_seasonal_pause = true` en la composición. `RestockCadenceAndValidatorTest` 60/60 solo prueba `isSeasonalPauseActive()` de forma pura; no prueba la clasificación diaria.

**Corrección propuesta.** Primero, cerrar la contradicción con el Product Owner: decidir si el L1 mantiene activa la parada en pausa (EARS 03.1.3) o no (C1). Después:

- Si prevalece el EARS: no añadir `SEASONAL_PAUSE` cuando hay L1; dejar de añadir `EMPTY_SLOT` en pausa; limitar `blocked_stops` a máquinas con agenda activa.
- Si prevalece C1: modificar el EARS 03.1.3 y el caso §6.6 para que digan lo mismo.
- En ambos casos: calcular `has_demand` después de resolver el estado y no incrementar `injected_demand_count` con máquinas bloqueadas.
- Añadir tests de composición para pausa + FEFO, pausa + L1, pausa + huecos, pausa sin agenda y cuarentena con huecos.

---

### AUD-37-03 · Orden de `uncovered_stops` no conforme al EARS 03.3.1 (cerrada)

**Estado:** **Cerrada el 2026-11-22.** La bandeja se ordena con `UncoveredStopComparator`, un comparador puro que aplica la jerarquía del EARS 03.3.1 y el mismo criterio que la ruta asignada: bloque FEFO por `earliest_fefo_expiration_date` ascendente; bloque Demanda por `SlaTier` y, dentro del mismo nivel, `L1_INCIDENT` antes que `EMPTY_SLOT`; bloque Calendario por `preferred_time_window`; desempate final `machine_id ASC`. `buildUncoveredStop()` publica `preferred_time_window` para que el orden sea verificable en el payload. No se tocaron `locations` ni `CoordinatorTerritorialMapTab.js`.

**Evidencia final.** `php tests/unit/RestockRoutePlannerServicesTest.php` → **105/105 · 0 fallos · `exit=0`**, con la sección `[11/11]` que ordena nueve máquinas desordenadas y exige `[20, 10, 32, 31, 30, 41, 42, 40, 43]`. Batería global → **448/17.375/0 · `DocMetricsGuard` coherente · `exit=0`**; canal `new_engine` → **76/76 · 2.590 aserciones · 0 fallos · `exit=0`**.

**Evidencia original (antes de la corrección).** `spec.md:197` exigía ordenar por FEFO, luego demanda (`L1` antes que `EMPTY_SLOT`, por `SlaTier`) y luego franja. El código anterior solo ordenaba por rango de bloque y `machine_id`:

```php
return ($rank[$a['priority_block']] ?? 9) <=> ($rank[$b['priority_block']] ?? 9)
    ?: $a['machine_id'] <=> $b['machine_id'];
```

No usaba la fecha FEFO, ni el tipo de demanda, ni el `SlaTier`, ni la franja. La suite 103/103 solo comprobaba el recuento y el `coverage_status`; la consola podía mostrar primero un FEFO lejano o un `EMPTY_SLOT` antes que un `L1`. La corrección propuesta era extraer el comparador y añadir un test con máquinas desordenadas.

---

### AUD-37-04 · Sin prueba real `360 × 800` de las superficies M37 (cerrada)

**Estado:** **Cerrada el 2026-11-22.** La suite [`RestockRoutePlannerLayoutBrowserTest.php`](../tests/integration/RestockRoutePlannerLayoutBrowserTest.php) (categoría `new_engine`) y su runner [`RestockRoutePlannerLayoutBrowserRunner.mjs`](../tests/Support/RestockRoutePlannerLayoutBrowserRunner.mjs) montan los componentes reales con los estilos de producción en Chromium real, a `360 × 800` y con la barra de scroll oculta. Los arneses `restock_route_planner_layout_restocker.html` y `restock_route_planner_layout_coordinator.html` usan fixtures de `route_plan`, paradas, excepciones y agendas; no se modificaron `locations` ni `CoordinatorTerritorialMapTab.js`.

**Evidencia final.** `php tests/integration/RestockRoutePlannerLayoutBrowserTest.php` → **38/38 aserciones · 0 fallos · `exit=0`**, con `document.documentElement.scrollWidth <= 360` y `body.scrollWidth <= 360` en cada superficie. Se verifican: la hoja móvil con los tres bloques, badges, cuatro máquinas, acciones Visitar/Omitir y tres acordeones; el modal de mapa con origen, polilínea, dos pines y tres tarjetas; el mapa sin GPS válido (cero pines y paradas textuales «Sin GPS») y el estado vacío sin lienzo; el modal de omisión con un motivo de más de 200 caracteres y la confirmación alcanzable; y la consola de Coordinación con siete contadores y las tablas de cobertura, excepciones y agendas contenidas en contenedores `overflow-x: auto`, sin arrastrar el scroll al documento. No hubo desbordamiento real que corregir en producción.

**Evidencia original.** [`traceability-matrix.md`](../specs/37-restock-route-planner/traceability-matrix.md) citaba `RestockerSatLayoutBrowserTest` **22/22** como evidencia de `RF-05.2` y del layout móvil. Esa suite monta las superficies **L1/SAT del Módulo 36** (`RestockerRouteView` y `TechnicianRouteView`); sus 22 aserciones cubren la bandeja de avisos L1, la nota interna de taller, la foto del escalado, la coincidencia en sede y la conversación, pero **no monta `route_plan`, ni las paradas del planificador, ni el modal `RestockerRouteMapModal`, ni el modal de omisión.** [`restocker_route_planner_view.test.mjs`](../tests/frontend/restocker_route_planner_view.test.mjs) 5.11 solo comprobaba CSS estático, sin viewport ni `scrollWidth`.

**Impacto original.** El DoD 4 exigía `360 × 800` sin scroll horizontal para `RestockerRouteView` y el modal Web Mercator; esa parte no estaba verificada y la matriz atribuía a M37 una cobertura que pertenece a M36.

---

### AUD-37-05 · Cifras documentales no reproducibles (cerrada)

**Estado:** **Cerrada el 2026-11-22.** La causa era AUD-37-01: al fallar una aserción, la batería evaluaba 17.357 y `DocMetricsGuard` detectaba 5 desviaciones. Con el test corregido, la corrida real da **448/17.373/0**, `DocMetricsGuard` coherente y `exit=0`; las cifras de `README.md`, `docs/features_pendientes.md`, `tasks.md` y la matriz vuelven a coincidir. La atribución de `RestockerSatLayoutBrowserTest` como prueba `360 × 800` de M37 quedó corregida en **AUD-37-04**, que añade la suite propia de las superficies M37.

**Evidencia original (antes de AUD-37-01).** La matriz y el cierre de `T-RRP-13` declaraban:

| Declarado | Real en la auditoría original |
|---|---|
| 448 suites · 17.358 aserciones · 0 fallos | 448 suites · 17.357 aserciones · 1 fallo |
| `coordinator_restock_route_planner.test.mjs` 32/32 | 31/32 |
| 11 suites propias · 487 aserciones verdes | 486 verdes · 1 roja |
| `RestockerSatLayoutBrowserTest` 22/22 como cobertura `360 × 800` de RF-05.2 | 22/22, pero cubre M36, no M37 |

`DocMetricsGuard` reportó 5 desviaciones: la frase canónica de `README.md`, la de `docs/features_pendientes.md`, la insignia de tests, la fase «Suites de pruebas JS Unit» (108/108 declaradas, 107/108 reales) y el total de aserciones.

**Impacto histórico.** La matriz dejó de ser una fuente de verdad y el cierre del módulo no era reproducible. El propio `DocMetricsGuard` lo detectó y la corrección de AUD-37-01 devolvió la coherencia sin tocar las cifras documentadas.

---

### AUD-37-06 · Esquemas temporales residuales (cerrada)

**Estado:** **Cerrada el 2026-11-22.** Causas raíz resueltas en las tres suites responsables:

1. [`tests/integration/WorkshopRefurbishmentServiceIntegrationTest.php`](../tests/integration/WorkshopRefurbishmentServiceIntegrationTest.php) (`t_workshop27_service` en `vendguard_battery`): creaba el esquema aislado dentro de `try { ... } catch (...)` sin bloque `finally` que invocara `PostgresFixtureBuilder::dropIsolationSchema()`. Se añadió `finally` con rollback preventivo y `dropIsolationSchema()`.
2. [`tests/integration/OperationalZoneRepositoryTest.php`](../tests/integration/OperationalZoneRepositoryTest.php) (`t_zone05_repository` en `vendguard_pg_test`) y [`tests/integration/ZonePostalCodeTransferTest.php`](../tests/integration/ZonePostalCodeTransferTest.php) (`t_zone06_transfer` en `vendguard_pg_test`): ambos scripts ejecutaban `exit(0)` / `exit(1)` **dentro** del bloque `try` justo antes de `finally`, de modo que PHP terminaba el proceso sin ejecutar el bloque `finally` ni `dropIsolationSchema()`. Se movieron el resumen y `exit()` fuera del bloque `try/finally`, añadiendo `catch`, rollback preventivo y liberación de `$countingPdo` en `OperationalZoneRepositoryTest`.

**Evidencia final.** Suites focalizadas en verde sin modificar aserciones: `WorkshopRefurbishmentServiceIntegrationTest` **46/46 (`exit=0`)**, `OperationalZoneRepositoryTest` **45/45 (`exit=0`)** y `ZonePostalCodeTransferTest` **18/18 (`exit=0`)**. Reejecución completa de ambos canales: `php tests/run_all.php` → **448 suites · 17.375 aserciones · 0 fallos · `DocMetricsGuard` coherente · `exit=0`**; `php tests/run_new_engine.php` → **76/76 suites · 2.590 aserciones · 0 fallos · `exit=0`**. Consulta SQL de comprobación posterior en ambas bases:

```sql
SELECT nspname FROM pg_namespace
 WHERE nspname LIKE 't\_%'
    OR nspname LIKE 'contract\_convergence\_check%';
-- vendguard_battery: 0 filas | vendguard_pg_test: 0 filas
```

**Evidencia histórica original (antes de la corrección).** Tras terminar la batería global y el canal `new_engine`:

```text
vendguard_battery:  t_workshop27_service
vendguard_pg_test:  t_zone05_repository, t_zone06_transfer
```

Total: **3 esquemas**. La batería declaraba “Base de datos restablecida: SÍ” y `HarnessResidueGuardTest` pasaba, pero los esquemas `t_*` de esas suites no se eliminaban en la ejecución ordinaria y podían contaminar corridas posteriores o contradecir el «residuo 0» del cierre de `T-RRP-13`.

---

### AUD-37-07 · Contradicción `403` / `409` para máquina bloqueada (cerrada)

**Estado:** **Cerrada el 2026-11-22.** Se confirma el criterio del contrato técnico §4 y del dominio (`RestockRouteOverrideService::skip()` + `RestockRoutePlannerValidationException`): la parada ajena al perímetro es un error de autorización (`403 FORBIDDEN`), la máquina segregada en `blocked_stops` es un conflicto de estado operativo/sanitario (`409 Conflict`) y la máquina o sede inactiva o eliminada es una entidad no procesable (`422 Unprocessable Entity`).

1. **Especificación y espejo 1:1:** actualizados [`specs/37-restock-route-planner/spec.md:6,150`](../specs/37-restock-route-planner/spec.md) y su espejo idéntico byte a byte [`specs/functional/restock_route_planner_spec.md:6,150`](../specs/functional/restock_route_planner_spec.md) (SHA-256 coincidente). El criterio EARS 02.2.1 exige ahora:
   - **`403 FORBIDDEN` (`RESTOCK_STOP_NOT_IN_RESTOCKER_ROUTE`)** si el `RESTOCKER` intenta omitir una parada fuera de su ruta efectiva de hoy;
   - **`409 Conflict` (`RESTOCK_STOP_BLOCKED_BY_SANITARY_OR_SAT`)** si la máquina está segregada en `blocked_stops` (`QUARANTINE`, `is_blocked_no_access = TRUE`, `TEMPERATURE_COLD` abierta, visita `'ABORTED_THERMAL_BREACH'` hoy o pausa estacional vigente sin inclusión activa);
   - **`422 Unprocessable Entity` (`MACHINE_INACTIVE_OR_DELETED`)** si la máquina está inactiva o dada de baja (`is_active = FALSE` / `deleted_at IS NOT NULL`) o pertenece a una sede inactiva.
2. **Aserciones HTTP añadidas:** en [`tests/integration/RestockRoutePlannerApiTest.php:611-640`](../tests/integration/RestockRoutePlannerApiTest.php) se añaden la aserción **5.6** (omisión por `RESTOCKER` sobre máquina en `QUARANTINE` → `409 RESTOCK_STOP_BLOCKED_BY_SANITARY_OR_SAT`) y la aserción **5.7** (omisión sobre máquina con `is_active = FALSE` → `422 MACHINE_INACTIVE_OR_DELETED`), pasando el archivo de 29 a **31 aserciones** (renumerando el volcado offline previo a 5.8).

**Evidencia final.** `php tests/unit/FunctionalSpecMirrorParityTest.php` → **24/24 (`exit=0`)**; `RestockRoutePlannerApiTest.php` → **31/31 (`exit=0`)**; `php tests/run_new_engine.php` (`.env.docker-tls`) → **76/76 suites · 2.592 aserciones · 0 fallos · `exit=0`**; residuo **0** de esquemas `t_*` ni `contract_convergence_check*` en `vendguard_pg_test` y `vendguard_battery`.

**Hallazgo original (antes de la corrección).** [`spec.md:150`](../specs/37-restock-route-planner/spec.md) agrupaba perímetro, máquina dada de baja y máquina en `blocked_stops` bajo `403 FORBIDDEN`, en contradicción con [`restock_route_planner_contracts.md:676-694`](../specs/technical/restock_route_planner_contracts.md) y [`RestockRoutePlannerValidationException.php:42-68`](../src/Core/Domain/Exception/RestockRoutePlannerValidationException.php), y `RestockRoutePlannerApiTest.php` (entonces 29/29) no probaba por HTTP ni la máquina en cuarentena ni la inactiva.

---

### AUD-37-08 · Criterios sin aserción directa

| Criterio | Qué falta | Evidencia actual |
|---|---|---|
| `RF-01.3.1` | `404 MACHINE_NOT_FOUND` y `422 MACHINE_INACTIVE_OR_DELETED` al configurar agenda | API 29/29 solo prueba `422` de cadencia y el camino feliz |
| `RF-01.3.2` | Rollback atómico del alta masiva con `machine_ids` inexistentes/inactivos | Services 105/105 prueba `overwrite` y `failed_count = 0`, no el fallo |
| `RF-01.3.3` | Baja lógica a mitad de jornada: deja de generar `SCHEDULED`, conserva inyecciones/excepciones y enlaza visitas offline | Solo se prueba que `deactivate()` responde y que la segunda baja falla |
| `RF-02.3.1` | Revocación tras visita completada y no alteración de la custodia L1 en `incident_assignments` | No hay aserción de ninguno de los dos |
| `RF-03.1.1` | Exclusión de máquina o sede inactiva de la ruta y de las bandejas | `isOperable()` existe, pero ninguna suite M37 monta una máquina inactiva en la foto |
| `RF-03.2.4` | `site_coincidence` dentro del `route_plan` de M37, incluyendo receptores de `REASSIGNED` | M36 lo cubre en su propia ruta; M37 no lo afirma |
| `RF-05.1.3` | Orden de drenaje “visitas M34 antes que omisiones” y volcado automático por baja de cuenta | El código lo hace (`RestockerRouteView.js:662`), pero el test 3.7 solo vacía la cola de omisiones |

**Impacto.** Son criterios implementados o parcialmente implementados, pero no certificados. La matriz los presenta como cubiertos por suites que no los ejercitan.

**Corrección propuesta.** Añadir una aserción por fila, preferiblemente en las suites ya existentes:

- API: `404`/`422` de agenda y rollback de lote.
- Services: baja en jornada y exclusión de inactivas.
- Integrations/API: `site_coincidence` en `route_plan` con desvío entrante.
- Frontend: orden de drenaje con una visita y una omisión pendientes en la misma máquina; volcado al desactivar la cuenta.

---

### AUD-37-09 · Semillas 1:1 y N+1 sin verificación automatizada

**Semillas.** El DoD 1 exige que la migración `033` converja 1:1 en `supabase_init.sql` y `supabase_seeds.sql`. `SchemaConvergenceParityTest` 42/42 y `PostgresRestockRoutePlannerSchemaTest` 41/41 cubren el DDL y el libro de migraciones, pero **ninguna aserción compara el contenido de `supabase_seeds.sql`** con las agendas y la excepción de demostración. `CloudDeploySchemaParityTest` 55/55 solo comprueba que el arranque referencia el archivo de semillas.

**N+1.** El RNF-02 prohíbe consultas por parada. `PdoRestockRouteReadModel` carga una foto única, pero no hay una aserción que cuente consultas o que detecte un N+1 en la composición.

**Impacto.** Dos afirmaciones del cierre no son verificables de forma automática.

**Corrección propuesta.** Añadir una aserción de paridad de semillas (por ejemplo, contar las 5 agendas y la excepción `SKIPPED`, y comprobar que una segunda pasada no inserta nada) y, si el arnés lo permite, un contador de consultas en el test de composición para exigir un número constante.

---

## 4. Definition of Done (§8)

| DoD | Evidencia real | Veredicto |
|---|---|---|
| **1.** Migración `033`/`034`, 51 tablas, 50 enums, índices, FK, auditoría y guardas | Schema M37 **41/41**; `PostgresSchemaTest` **159/159**; `PostgresConstitutionalTest` **88/88**; `SchemaConvergenceParityTest` **42/42**; `CloudDeploySchemaParityTest` **55/55** | **Cumplido en esquema**; semillas 1:1 sin aserción (AUD-37-09) |
| **2.** Dominio, cadencias, optimizador, servicios, excepciones y perímetro | Dominio **67/67**; Cadence **60/60**; Optimizer **30/30**; Services **105/105**; Integrations **21/21**; Gateway **34/34** | **Cumplido** |
| **3.** API REST, RBAC, idempotencia y opacidad | API **31/31**; Constitucional **45/45** | **Cumplido** |
| **4.** Superficies móvil y de Coordinación, mapa, Offline y `TODAY_ROUTE` | Móvil **40/40**; vista **245/245**; Coordinación **32/32**; M33 **80/80**; layout M37 **38/38** en Chromium real | **Cumplido** |
| **5.** Batería global y canal `new_engine` al 100 % | Global **448/17.375/0/`DocMetricsGuard` coherente/exit=0**; `new_engine` **76/76/2.592/0/exit=0** | **Cumplido** |

---

## 5. Tareas

[`specs/37-restock-route-planner/tasks.md`](../specs/37-restock-route-planner/tasks.md): **13 marcadas `[x]`, 0 pendientes**.

Las 13 tareas existen y están cerradas administrativamente. El cierre de **T-RRP-13** declara “448 suites · 17.375 aserciones · 0 fallos”; tras corregir AUD-37-01, AUD-37-02, AUD-37-03, AUD-37-04, AUD-37-06 y AUD-37-07, esa evidencia vuelve a ser reproducible (`448/17.375/0`, `exit=0`), y el canal `new_engine` queda en **76/76 suites · 2.592 aserciones · 0 fallos**.

---

## 6. Evidencia verde que no es brecha

Estas suites se ejecutaron y pasaron; se conservan aquí para no perder el contexto de lo que sí está certificado.

| Suite | Resultado | Canal |
|---|---|---|
| `RestockRoutePlannerDomainModelsTest` | 67/67 | local |
| `RestockCadenceAndValidatorTest` | 60/60 | local |
| `RestockRouteOptimizerTest` | 30/30 | local |
| `RestockRoutePlannerServicesTest` | 105/105 | local |
| `RestockRoutePlannerIntegrationsTest` | 21/21 | local |
| `PostgresRestockRoutePlannerSchemaTest` | 41/41 | `new_engine` |
| `PdoRestockRoutePlannerGatewayTest` | 34/34 | `new_engine` |
| `RestockRoutePlannerApiTest` | 31/31 | `new_engine` |
| `RestockRoutePlannerConstitutionalTest` | 45/45 | local |
| `coordinator_restock_route_planner.test.mjs` | 32/32 | local |
| `restocker_route_planner_view.test.mjs` | 40/40 | local |
| `restocker_route_view.test.mjs` | 245/245 | local |
| `coordinator_product_loading_tab.test.mjs` | 80/80 | local |
| `RestockRoutePlannerLayoutBrowserTest` | 38/38 · Chromium real `360 × 800` | `new_engine` |
| `ProductLoadingOrderServiceTest` | 53/53 | local |
| `MachineRestockVisitServiceTest` | 50/50 | local |
| `PostgresSchemaTest` | 159/159 | `new_engine` |
| `PostgresConstitutionalTest` | 88/88 | `new_engine` |
| `SchemaConvergenceParityTest` | 42/42 | `new_engine` |
| `CloudDeploySchemaParityTest` | 55/55 | local |
| `TestDataCleanerIsolationTest` | 50/50 | local |
| `RestockerSatLayoutBrowserTest` (M36) | 22/22 | `new_engine` |
| `PdoMachinePlanogramRepositoryTest` (M32) | 46/46 | local |
| `PdoMachineRestockVisitRepositoryTest` (M34) | 29/29 | local |
| `InSituRestockVisitApiTest` (M34) | 22/22 | `new_engine` |
| `PdoMachineCashCollectionRepositoryTest` (M35) | 48/48 | `new_engine` |
| `CashCollectionVaultAuditApiTest` (M35) | 50/50 | `new_engine` |
| `PdoRestockerSatSynergyGatewayTest` (M36) | 31/31 | `new_engine` |
| `RestockerSatSynergyApiTest` (M36) | 98/98 | `new_engine` |
| `CoordinatorButtonVariantsGuardTest.mjs` | 23/23 · 26 plantillas · 438 botones | local |
| `LegacyButtonPaletteRetirementTest.mjs` | 18/18 | local |
| `DesignTokenDebtRatchetTest.mjs` | 3/3 | local |
| `DesignTokensTest.php` | 14/14 | local |
| `MobileShellLayoutGuardTest.mjs` | 7/7 | local |
| `OfflinePwaAssetsAndUiGuardTest.mjs` | 45/45 | local |
| `FunctionalSpecMirrorParityTest.php` | 24/24 | local |

**Invariantes verificados.** El bloque DDL de `locations` es idéntico byte a byte (36 líneas) entre la base de la rama y `HEAD`; `CoordinatorTerritorialMapTab.js` no tiene diff y la aserción 7.6 del constitucional fija su hash `167016d6…`.

---

## 7. Orden de corrección propuesto

1. **AUD-37-02 (cerrada el 2026-11-22).** El Product Owner decidió que prevalece C1; EARS 03.1.3, C1, QA-6, §6.6 y D3/RF-03.1.4 quedaron alineados en la spec v1.2.
2. **AUD-37-02 (código, cerrado el 2026-11-22).** `blockedReasons`, disparadores y `has_demand` ajustados; tests de pausa + L1, pausa + FEFO, pausa + agenda, pausa + huecos y cuarentena + huecos añadidos.
3. **AUD-37-03 (cerrada el 2026-11-22).** Comparador puro `UncoveredStopComparator` y test `[11/11]` con nueve máquinas desordenadas; suite 105/105.
4. **AUD-37-01 (cerrada el 2026-11-22).** Test de fechas convertido en dinámico y batería global revalidada.
5. **AUD-37-04 (cerrada el 2026-11-22).** Suite de navegador `RestockRoutePlannerLayoutBrowserTest` **38/38** en Chromium real a `360 × 800`; hoja móvil, mapa con y sin GPS, estado vacío, modal de omisión y consola de Coordinación.
6. **AUD-37-08 y AUD-37-09.** Añadir las aserciones que faltan.
7. **AUD-37-06 (cerrada el 2026-11-22).** Limpieza garantizada en `finally` (sin `exit()` dentro de `try`) en las tres suites (`WorkshopRefurbishmentServiceIntegrationTest`, `OperationalZoneRepositoryTest`, `ZonePostalCodeTransferTest`); **0** esquemas residuales en `vendguard_battery` y `vendguard_pg_test`.
8. **AUD-37-05 (cerrada el 2026-11-22).** Ambos canales reejecutados; cifras y `DocMetricsGuard` coherentes. Queda la atribución `360 × 800` de AUD-37-04.
9. **AUD-37-07 (cerrada el 2026-11-22).** Spec y espejo 1:1 alineados con el catálogo §4 (`403` perímetro, `409` bloqueada en `blocked_stops`, `422` inactiva/eliminada); añadidas las aserciones HTTP 5.6 (`409` cuarentena) y 5.7 (`422` inactiva) en `RestockRoutePlannerApiTest.php` (**31/31**; `new_engine` **76/76 · 2.592 aserciones**).

---

## 8. Criterio de cierre de esta acta

La Spec 37 podrá declararse al 100 % cuando se cumplan, con evidencia reproducible, todas estas condiciones:

- [x] `php tests/run_all.php` termina en **0 fallos** y `DocMetricsGuard` coherente.
- [x] `php tests/run_new_engine.php` sigue en **76/76** con 0 fallos.
- [x] La pausa estacional cumple C1 (solo FEFO la reactiva) y los contadores no cuentan bloqueadas como demanda.
- [x] `uncovered_stops` respeta el orden FEFO → L1/`SlaTier` → franja.
- [x] Existe una prueba de navegador real `360 × 800` de la hoja móvil, el mapa y la consola de Coordinación.
- [ ] Los criterios de AUD-37-08 tienen aserción directa.
- [x] No quedan esquemas `t_*` ni `contract_convergence_check*` en ninguna de las dos bases.
- [x] La matriz de trazabilidad, `tasks.md`, `README.md` y `docs/features_pendientes.md` declaran las cifras de la última corrida verde.
- [ ] `locations` y `CoordinatorTerritorialMapTab.js` siguen intactos.

---

## 9. Nota de mantenimiento

Esta acta se actualizará al cerrar cada brecha, con la misma disciplina que las auditorías de los módulos 33–36: el estado de cada fila pasará de **Abierta** a **Cerrada (fecha)** y se añadirá la evidencia focalizada que lo demuestre. No se declarará el cierre del módulo mientras quede una fila P1 abierta o la batería global no esté en verde.

**Estado del árbol:** HEAD de partida `83f64ed` (más los cambios locales de AUD-37-04). El cierre de AUD-37-07 modifica `specs/37-restock-route-planner/spec.md`, `specs/functional/restock_route_planner_spec.md`, `tests/integration/RestockRoutePlannerApiTest.php` y esta acta (`docs/auditoria_spec_37_brechas.md`); `locations` y `CoordinatorTerritorialMapTab.js` permanecen intactos. `git diff --check` no reporta errores.
