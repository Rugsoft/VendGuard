# Auditoría de la Spec 39 · Brechas y fallos detectados

- **Módulo:** 39 · Auditoría Local mediante Importación de Ficheros Estándar EVA-DTS (`.dex`) en Ruta (`39-eva-dts-dex-audit`)
- **Especificación auditada:** [`specs/39-eva-dts-dex-audit/spec.md`](../specs/39-eva-dts-dex-audit/spec.md) (v1.1 · `D1`–`D6`, `C1`–`C6`, `QA-1`–`QA-5`)
- **Espejo funcional:** [`specs/functional/eva_dts_dex_audit_spec.md`](../specs/functional/eva_dts_dex_audit_spec.md) (byte a byte idéntico, 65.685 bytes)
- **Plan y tareas:** [`specs/39-eva-dts-dex-audit/plan.md`](../specs/39-eva-dts-dex-audit/plan.md) · [`specs/39-eva-dts-dex-audit/tasks.md`](../specs/39-eva-dts-dex-audit/tasks.md)
- **Matriz de trazabilidad:** [`specs/39-eva-dts-dex-audit/traceability-matrix.md`](../specs/39-eva-dts-dex-audit/traceability-matrix.md)
- **Constitución y diseño:** [`constitution.md`](../constitution.md) · [`docs/design.md`](design.md)
- **Fecha de auditoría:** 2026-11-26
- **Rama:** `feat/eva-dts-dex-audit`
- **Base auditada:** `ffeed2f3c4821837f21dcee46c700ac2f0d6293b` (árbol limpio, sin push)
- **Alcance:** revisión de `RF-01`–`RF-07` y sus criterios EARS, `RNF-01`–`RNF-04`, `D1`–`D6`, `C1`–`C6`, `QA-1`–`QA-5`, los 10 casos límite de §7, las 12 rutas de §6, el Fuera de Alcance de §8, los 4 criterios de Definition of Done y las 19 tareas `T-DEX-01`–`T-DEX-19`, con ejecución real de las suites en los dos canales.

> Este documento es un acta de hallazgos abiertos. La batería está en verde, las 19 tareas figuran cerradas y **`AUD-39-01`, `AUD-39-02`, `AUD-39-03` y `AUD-39-04` quedaron cerradas el 2026-11-26**, pero **la Spec 39 no puede certificarse al 100 %** hasta corregir la matriz (`AUD-39-05`).

---

## 1. Veredicto ejecutivo

**La Spec 39 no está cumplida al 100 %.** La implementación cubre el esquema, el parser, el dominio, la API, el Offline PWA y la consola de Coordinación, y toda la batería ejecutable cierra en verde. Sin embargo, la auditoría requisito por requisito mantiene **una cláusula no funcional sin medir** y una corrección documental pendiente (además de `AUD-39-01`–`AUD-39-03`, ya cerradas):

1. **Cerrada · Los cruces M34/M35 del detalle de Coordinación no se componían en producción** (`RF-04.3.3`, `QA-3`, `RF-07.1`, `D5`). Las consultas primitivas existían y estaban probadas, pero ningún servicio las invocaba; el detalle nunca devolvía `m34_cross_check`/`m35_cross_check`. `T-DEX-15` los compone en `MachineDexAuditService::findForRole()` y los certifica con `DexAuditApiTest`.
2. **Cerrada · El delta efectivo tras un predecesor `VOIDED` no se calculaba en lectura** (`RF-04.1.2`, `QA-2`). El método de dominio existía, pero solo lo llamaba un test unitario. `T-DEX-16` resuelve el último `ACTIVE` en lectura y expone `effective_previous_audit_id` y los deltas efectivos sin reescribir las filas.
3. **Cerrada · La auditoría `MACHINE_DEX_AUDIT` estaba incompleta** (`Art. III.3`, `RF-01.5`, `RF-07.2`, `C6`): el alta y la vinculación no escribían en `audit_log`, la anulación se registraba con entidad `MACHINE` y la exportación CSV no se auditaba. `T-DEX-17` firma los cuatro hechos bajo `MACHINE_DEX_AUDIT` y lo certifica con `DexAuditApiTest` 8.1–8.5; `T-DEX-18` cablea el enlazador en las rutas reales M34/M35/M36 y lo certifica con la sección 9.
4. **Cerrada · `RNF-01` no medía los 250 ms** de parseo/transacción; solo se verificaba el tope de 1 MB y la exclusión de `raw_payload`. `T-DEX-19` añade `DexAuditPerformanceTest` y optimiza la transacción de líneas con `COPY FROM STDIN`.

Consecuencia directa: **el DoD 2 ya se cumple**, incluida la latencia de `RNF-01`; la matriz mantiene pendiente la separación documental de `AUD-39-05`.

### 1.1. Lo que sí está verificado y en verde

Ejecución real del 2026-11-26:

```text
php tests/run_all.php

Suites de pruebas PHP Unit : 209 / 209 pasadas
Suites de pruebas JS Unit  : 112 / 112 pasadas
Suites de Integración PHP  : 137 / 137 pasadas
Suites E2E Manuales (T-40): 3 / 3 pasadas
Total Suites Ejecutadas    : 461
Total Aserciones Evaluadas : 18126
Fallos Detectados          : 0
Cifras documentadas        : COHERENTES
Base de datos restablecida : SÍ (Semillas intactas)
RESULTADO: 100% EN VERDE. (0 errors, 0 failures)
exit=0
```

```text
source deploy/local/postgres/.env.docker-tls && php tests/run_new_engine.php

Suites: 85 · Fuera de la batería hermética (RNF-04)
Suites evaluadas    : 85
Suites en verde     : 85
Aserciones          : 2905
RESULTADO: 100% EN VERDE. CANAL new_engine CERRADO.
exit=0
```

- **14 suites propias · 429/429 aserciones verdes** (37 → 42 → 46 en `DexAuditApiTest` tras `T-DEX-17` y `T-DEX-18`; `DexAuditPerformanceTest` añade 3 y certifica p95 **202,7 ms**; cifra idéntica a la declarada en la matriz).
- **Residuo 0**: ninguna de las dos bases (`vendguard_battery`, `vendguard_pg_test`) conserva esquemas no estándar; las semillas DEX quedan intactas en **4 auditorías / 9 selecciones / 5 eventos**.
- **Esquema**: 54 tablas, 55 tipos `ENUM`, 54 disparadores de borrado, 415 sentencias DDL y 35 migraciones (`SchemaConvergenceParityTest` 42/42, `PostgresSchemaTest` 168/168, `PostgresConstitutionalTest` 91/91, `SeedRunnerSingleEngineTest` 26/26).
- **Diseño y PWA**: `DexAuditLayoutBrowserTest` 25/25 en Chromium real a `360 × 800` y `1440 × 900`, sin scroll horizontal del documento; `DesignTokensTest` 14/14; guardas de botones, paleta heredada y deuda de tokens en verde; `OfflinePwaAssetsAndUiGuardTest` 45/45.
- **Fuera de alcance (§8, Art. VI)**: sin Web Serial/Bluetooth/IoT, sin mantenimiento por desgaste, sin escandallos, sin CRUD del catálogo `EA1`/`EA2`, sin escrituras en `current_estimated_qty` y sin exposición en Sede/QR/A4 (`DexAuditConstitutionalTest` 1.1–1.2).
- **Las 12 rutas de §6** están registradas en `AppRouter.php` (176–181, 535–537, 627–629) y ejercitadas en `DexAuditApiTest` 46/46, incluida la vinculación M34/M35/M36 por HTTP.
- **Las 19 tareas** `T-DEX-01`–`T-DEX-19` están marcadas `[x]` en `tasks.md` y completadas en el ODD.

---

## 2. Matriz de brechas

| Orden | ID | Prioridad | Brecha | Requisito afectado | Estado |
|---:|---|---|---|---|---|
| **1** | **AUD-39-01** | **P1 · Bloqueante funcional** | El detalle de Coordinación no componía los cruces M34/M35: `observed_consumption_units`, `cash_comparison`, `comparison_basis`, `diff_eur` y umbral ±1,00 € no se calculaban ni se devolvían | `RF-04.3.3`, `QA-3`, `RF-07.1`, `D5` | **Cerrada (2026-11-26 · `T-DEX-15`)** |
| **2** | **AUD-39-02** | **P1 · Bloqueante funcional / Art. III.3** | El delta efectivo en lectura cuando el predecesor pasó a `VOIDED` no se calculaba: `previous_audit_voided` y `effective_delta_*` no llegaban al cliente | `RF-04.1.2`, `QA-2`, `C6` | **Cerrada (2026-11-26 · `T-DEX-16`)** |
| **3** | **AUD-39-03** | **P2 · Constitucional / auditoría** | `audit_log` no registraba el alta ni la vinculación; la anulación se registraba como `MACHINE` y no como `MACHINE_DEX_AUDIT`; el CSV no se auditaba | `Art. III.3`, `RF-01.5`, `RF-07.2`, `C6` | **Cerrada (2026-11-26 · `T-DEX-17`/`T-DEX-18`)** |
| **4** | **AUD-39-04** | **P2 · Verificación RNF** | No existe medición de los 250 ms de parseo/transacción de `RNF-01`; solo se comprueba el tope de 1 MB | `RNF-01`, DoD 2 | **Cerrada (2026-11-26 · `T-DEX-19`)** |
| **5** | **AUD-39-05** | **P3 · Documental** | La matriz de trazabilidad atribuye a tests de primitivas una funcionalidad no cableada y declara `RF-04.3`/`QA-2`/`Art. III.3` cubiertos | DoD 4, matriz, ODD | **Abierta** |

---

## 3. Detalle de las brechas

### AUD-39-01 · Cruces M34/M35 no compuestos en el detalle de Coordinación

**Estado:** **Cerrada (2026-11-26)** · **Prioridad:** P1 · **Requisitos:** `RF-04.3.3`, `QA-3`, `RF-07.1`, `D5`.

**Qué faltaba.**

El detalle de Coordinación debe aplicar las fórmulas cerradas de `QA-3`:

- **M35:** tomar la recaudación enlazada por `cash_collection_id` o la más cercana de la misma máquina dentro del mismo día civil (`Europe/Madrid`) de `captured_at`. Si `ca3_cashbox_eur` y `vault_counted_eur` existen, `diff_eur = ROUND(vault_counted_eur - ca3_cashbox_eur, 2)` con `comparison_basis = 'CA3_VS_VAULT'`; en su defecto, `diff_eur = ROUND(expected_cashbox_eur - delta_sales_eur, 2)` con `comparison_basis = 'VA1_VS_EXPECTED'`. Desviación si `ABS(diff_eur) > 1.00`.
- **M34:** tomar la visita `COMPLETED` enlazada o la más cercana del mismo día civil y la inmediatamente anterior; calcular `observed_consumption_units = prev_item.qty_after - curr_item.qty_before` por carril y su diferencia con `delta_vend_units`.

**Evidencia.**

- `src/Core/Domain/Repository/MachineDexAuditGatewayInterface.php:56-79` declara `findNearestCashCollectionSameDay`, `findCompletedRestockVisitSameDay`, `findPreviousCompletedRestockVisit` y `findVisitSlotQuantities`.
- `src/Infrastructure/Repository/PdoMachineDexAuditGateway.php:248,283,336,372` los implementa y `PdoMachineDexAuditRepositoryTest` 2.1–2.5 los prueba en aislamiento.
- **Ningún código de producción los invoca.** `MachineDexAuditService` solo usa el gateway en `lockMachineContext` (línea 135) y `findActivePlanogramSlots` (línea 241).
- `MachineDexAuditService::findForRole` (457–465) lee `repository->findById` y llama a `projectByRole` (505+), que para `COORDINATOR` devuelve la fila sin añadir cruces.
- `PdoMachineDexAuditRepository::findById` (236–262) devuelve cabecera, selecciones, eventos y `has_thermal_alert`; **no** devuelve `m34_cross_check` ni `m35_cross_check`.
- `MachineDexAudit::withCrossChecks()` (870) solo se invoca desde `DexAuditDomainModelsTest` (573).
- La UI espera esos campos (`CoordinatorDexAuditsSubTab.js:681-689`) pero los oculta con `v-if` al no recibirlos; el test de consola 2.5 los inyecta como fixture (`coordinator_dex_audits_tab.test.mjs:107-108`), por lo que **no prueba la composición del servidor**.

**Impacto (antes del cierre).** El KPI de desviación de efectivo sí existe en el listado (`PdoMachineDexAuditRepositoryTest` 3.13), pero el modal de detalle no ofrecía el contraste financiero ni el consumo observado que exige la spec. `RF-04.3.3`, `QA-3`, `RF-07.1` y `D5` quedaban parcialmente incumplidos.

**Criterio de cierre.**

1. `MachineDexAuditService` compone ambos cruces en la ruta de lectura de Coordinación usando el gateway, sin mutar ninguna tabla.
2. La respuesta de `GET /api/coordinator/dex-audits/{id}` incluye `m34_cross_check` y `m35_cross_check` con las claves y fórmulas de `QA-3` (o `null` cuando no hay visita/recaudación aplicable).
3. Nuevas aserciones de integración en `DexAuditApiTest` y/o `DexAuditConstitutionalTest` que **no inyecten fixtures**: deben crear visita M34/M35 reales, importar el `.dex` y verificar el cálculo, incluida la prioridad `CA3_VS_VAULT`, el fallback `VA1_VS_EXPECTED` y el umbral ±1,00 €.
4. `observed_consumption_units` se calcula con `prev_item.qty_after - curr_item.qty_before` y devuelve `null` si falta alguna visita o carril.

**Cierre (2026-11-26).** `T-DEX-15` añadió `attachCoordinatorCrossChecks()` a `MachineDexAuditService::findForRole()`: solo la proyección `COORDINATOR` recibe `m34_cross_check` y `m35_cross_check`, sin mutar ninguna tabla ni abrir rutas. El gateway acepta la recaudación vinculada por `cash_collection_id` (con fallback a la más cercana del mismo día civil) y devuelve `expected_cashbox_eur`. El test de integración se escribió primero y falló en rojo en 3.7–3.9 con ambos cruces a `null`; después quedó **28/28**. Certifica: `CA3_VS_VAULT` con `vault 10,50 − CA3 9,00 = 1,50` y `is_deviation = true`; `observed_consumption_units = 12 − 5 = 7` con `difference_units = null` por delta inicial; y segunda carga con `VA1_VS_EXPECTED` (`expected 9,00 − delta 7,50 = 1,50`), delta de 6 unidades y `difference_units = -1`. Regresiones: 13 suites DEX **408/408**; global **461 suites · 18.126 aserciones · 0 fallos**; `new_engine` **84/84 · 2.884 aserciones · 0 fallos**. Sin DDL ni rutas nuevas.

---

### AUD-39-02 · Delta efectivo tras `VOIDED` no calculado en lectura

**Estado:** **Cerrada (2026-11-26)** · **Prioridad:** P1 · **Requisitos:** `RF-04.1.2`, `QA-2`, `C6`.

**Qué faltaba.**

Cuando el `previous_audit_id` persistido apunta a un volcado que después pasó a `VOIDED`, el detalle de Coordinación debe:

- marcar `previous_audit_voided = TRUE`;
- exponer `effective_previous_audit_id`;
- calcular en lectura `effective_delta_sales_eur` y `effective_delta_vend_units` contra el último volcado `ACTIVE` anterior a `captured_at`;
- mantener intactas las filas persistidas (Art. III.3).

**Evidencia (estado previo al cierre).**

- `src/Core/Domain/Model/MachineDexAudit.php:849` definía `withPreviousAuditVoided(...)`.
- La única llamada en todo el repositorio era `tests/unit/DexAuditDomainModelsTest.php:557`; `grep -rn "withPreviousAuditVoided" src` solo devolvía la definición.
- `MachineDexAuditService::findForRole` y `PdoMachineDexAuditRepository::findById` no consultaban el estado del predecesor ni calculaban deltas efectivos.
- El repositorio sí excluía anulados al buscar predecesor para un **alta nueva** (`PdoMachineDexAuditRepositoryTest` 4.4), pero no reproyectaba las filas ya persistidas.

**Impacto (antes del cierre).** `QA-2` quedaba a medias: el orden Offline y `out_of_order_sync` estaban resueltos, pero la lectura tras anulación —la parte que evita corromper la serie histórica— no existía en producción.

**Criterio de cierre.**

1. Al leer un volcado cuyo predecesor está `VOIDED`, el servicio resuelve el último `ACTIVE` anterior y aplica `withPreviousAuditVoided`.
2. La respuesta incluye `previous_audit_voided`, `effective_previous_audit_id`, `effective_delta_sales_eur` y `effective_delta_vend_units`.
3. Test de integración: importar A, importar B, anular A, consultar B y verificar los deltas efectivos; las filas A y B permanecen sin cambios (comprobación en motor).
4. Sin predecesor `ACTIVE` válido, el comportamiento documentado (deltas `null`/`UNAVAILABLE`) queda explícito y probado.

**Cierre (2026-11-26).** `T-DEX-16` añadió `attachEffectiveDeltaAfterVoid()` a la lectura de Coordinación: si el `previous_audit_id` persistido está `VOIDED`, resuelve el último `ACTIVE` anterior con `findPreviousActive(machine, captured_at, currentId)`, expone `previous_audit_voided = true` y `effective_previous_audit_id`, y recalcula los deltas con `DexAuditValidator::resolveDeltas()` para conservar `RESET_FALLBACK`/`UNAVAILABLE`. El bloque efectivo se calcula antes de los cruces M34/M35, de modo que M35 usa el delta efectivo cuando aplica. El test de integración se escribió primero y falló en 7.1/7.4/7.6/7.9; después quedó **37/37**. Certifica la cadena A0 → A → B: al anular A, B conserva su delta persistido (7,00 € / 7 uds) y expone el efectivo contra A0 (12,00 € / 12 uds); las filas A y B siguen intactas en motor (Art. III.3). Al anular también A0, el bloque efectivo queda explícito en `null`. Regresiones: 13 suites DEX **417/417**; global **461 suites · 18.126 aserciones · 0 fallos**; `new_engine` **84/84 · 2.893 aserciones · 0 fallos**. Sin DDL ni rutas nuevas.

---

### AUD-39-03 · `audit_log` incompleto y con entidad incorrecta

**Estado:** **Cerrada (2026-11-26)** · **Prioridad:** P2 · **Requisitos:** `Art. III.3`, `RF-01.5`, `RF-07.2`, `C6`.

**Qué faltaba.**

La spec exige que **todo alta, vinculación posterior y anulación** quede registrada en `audit_log` bajo `audit_entity_type = 'MACHINE_DEX_AUDIT'`, y que la exportación CSV también se audite.

**Evidencia.**

- **Alta:** `RestockerRouteController::importDexAudit` (638–651) y `CoordinatorDexAuditController::import` (81–92) no escriben auditoría. El mensaje de respuesta de Coordinación dice «importado y auditado», pero no hay registro.
- **Vinculación:** `DexAuditLinker` no registra el enlace en `audit_log`.
- **Anulación:** `CoordinatorDexAuditController` (165) sí registra, pero con `AuditLogger::logMachineEvent`, que fija `entityType = AuditEvent::ENTITY_MACHINE` (`AuditLogger.php:184-205`), no `MACHINE_DEX_AUDIT`.
- **CSV:** `CoordinatorDexAuditController::exportCsv` (95–107) no registra auditoría.
- **Esquema:** la migración `035_eva_dts_dex_audit.sql` no contiene ningún trigger que escriba en `audit_log`.
- `DexAuditApiTest` y `DexAuditConstitutionalTest` no comprueban la tabla `audit_log` para estas acciones.

**Impacto.** `Art. III.3` y `RF-01.5` quedan incumplidos en la práctica: la etiqueta `MACHINE_DEX_AUDIT` existe en el catálogo y en `AuditActionLabels.js`, pero ninguna fila la usa. Además, el test que certifica la anulación no verifica la auditoría.

**Criterio de cierre.**

1. Alta, vinculación y anulación escriben una fila en `audit_log` con `entity_type = 'MACHINE_DEX_AUDIT'`, `entity_id` del volcado, actor, estado anterior/nuevo y metadatos (`audit_code`, `machine_id`, destino del enlace).
2. La exportación CSV registra su propia acción auditable con el perímetro aplicado.
3. El conflict-dump Offline mantiene la entidad `MACHINE` que exige `C5` (no se cambia).
4. Nuevas aserciones de integración consultan `audit_log` tras importar, enlazar, anular y exportar; deben fallar si la fila no existe o si la entidad no es la correcta.

**Cierre (2026-11-26).** `T-DEX-17` añadió `AuditLogger::logMachineDexAuditEvent()` y `logMachineDexAuditExport()`, y conectó los cuatro caminos: el alta firma `DEX_AUDIT_IMPORTED`, el enlace `DEX_AUDIT_LINKED` con `audit_code`, máquina, tipo y destino, la anulación `DEX_AUDIT_VOIDED` con `ACTIVE → VOIDED` y motivo —ya sin `logMachineEvent`— y el CSV `EXPORT_DEX_AUDITS_CSV` como exportación global `entity_id = 0` con el perímetro aplicado. `MachineDexAuditService` recibe el logger y `DexAuditLinker` propaga el actor desde M34/M35/M36; `findLinkTargetForUpdate()` devuelve `audit_code` sin cambiar DDL. El test de integración se escribió primero y falló en 8.1–8.4; 8.5 ya exigía `MACHINE` para el conflict-dump y se mantuvo. Después quedó **42/42**: alta del Reponedor, enlace de una auditoría de Coordinación a una visita M34, anulación con actor y estados, CSV con perímetro y conflict-dump bajo `MACHINE` (C5). `T-DEX-18` detectó que `RestockerRouteController` construía M34/M35/M36 sin `DexAuditLinker`, por lo que cualquier `dex_audit_id` devolvía 500 antes de crear la visita, la bolsa o el ticket. El cableado mínimo inyecta una instancia única del enlazador con `PdoMachineDexAuditRepository` y `AuditLogger` en los tres servicios. La sección 9 se escribió primero y falló en 9.1–9.3 con 500; después certifica **46/46**, los tres vínculos persistidos y `DEX_AUDIT_LINKED` por HTTP bajo `MACHINE_DEX_AUDIT` con actor y destino. Regresiones: 13 suites DEX **426/426**; global **461 suites · 18.126 aserciones · 0 fallos**; `new_engine` **84/84 · 2.902 aserciones · 0 fallos**. Sin DDL ni rutas nuevas.

---

### AUD-39-04 · `RNF-01` sin medición de latencia

**Estado:** **Cerrada (2026-11-26)** · **Prioridad:** P2 · **Requisitos:** `RNF-01`, DoD 2.

**Qué falta.**

`RNF-01` exige que el parseo y la transacción queden **por debajo de 250 ms** sobre Supavisor/PostgreSQL, además del tope de 1 MB. La auditoría confirma el tope y la exclusión de `raw_payload`, pero **no existe ninguna suite que mida la latencia**.

**Evidencia.**

- `grep -rln "DexAuditPerformance|DEX.*Benchmark" tests` no devuelve ningún fichero.
- Las únicas llamadas a `microtime()` en suites DEX están en `DexAuditConcurrencyTest` (líneas 204–229) y sirven para esperar a los procesos, no para medir latencia.
- `PdoMachineDexAuditRepositoryTest` y `DexAuditApiTest` verifican corrección, no tiempos.

**Impacto.** La cláusula de rendimiento de `RNF-01` no puede certificarse; el DoD 2 la incluye implícitamente al exigir el módulo «al 100 % bajo pruebas».

**Criterio de cierre (una de dos).**

- **Opción A (medición):** nueva suite `DexAuditPerformanceTest` que importe un `.dex` de tamaño representativo y mida el percentil 95 de parseo + transacción contra PostgreSQL local, con un presupuesto explícito y repetible (por ejemplo, 20 iteraciones, `p95 < 250 ms`), documentando que el motor remoto/Supavisor se mide en su canal propio.
- **Opción B (enmienda):** si el Product Owner decide que los 250 ms son un objetivo y no un criterio certificable en local, modificar `spec.md`/`plan.md` con aprobación expresa y reflejarlo en la matriz. No se puede dejar la cláusula sin evidencia ni enmienda.

**Cierre (2026-11-26).** `T-DEX-19` implementó la Opción A: `DexAuditPerformanceTest` descarta un calentamiento y mide **20 importaciones** de un `.dex` de ~1 MB con 500 selecciones `PA1`/`PA2`, incluyendo parseo, transacción y relectura del detalle. La suite exige **p95 < 250 ms** por rango más cercano. El test se ejecutó primero contra el repositorio original y falló con **p95 = 814,6 ms**; el cuello estaba en insertar cada línea con un `execute()`. `PdoMachineDexAuditRepository` pasó a persistir selecciones y eventos con `COPY FROM STDIN` en lotes de 2.000 filas dentro de la misma transacción, y el p95 quedó en **202,7 ms**. El motor remoto/Supavisor no se sustituye por el local: su medición queda declarada en el canal propio. `DexAuditPerformanceTest` **3/3**; 14 suites DEX **429/429**; global **461/18.126/0** y `new_engine` **85/2.905/0**. Sin DDL ni rutas nuevas.

---

### AUD-39-05 · La matriz de trazabilidad sobreestima la cobertura

**Estado:** **Abierta** · **Prioridad:** P3 · **Requisitos:** DoD 4, `traceability-matrix.md`, ODD.

**Qué falta.**

La matriz ya refleja los flujos de `RF-04`, `QA-2` y `Art. III.3` con `DexAuditApiTest` 46/46, incluida la vinculación M34/M35/M36 por HTTP de la sección 9; el resto de la cobertura sigue sin distinguir entre «primitiva probada» y «flujo cableado y probado de extremo a extremo». La matriz debe separar ambas categorías.

**Evidencia.**

- `specs/39-eva-dts-dex-audit/traceability-matrix.md`, filas `Art. III.1 / III.2 / III.3`; `RF-04` y `QA-2` ya están corregidas.
- `PdoMachineDexAuditRepositoryTest` 2.1–2.5 sigue probando el gateway en aislamiento; el flujo de detalle se cubre en `DexAuditApiTest` 3.7–3.9 (cruces) y 7.1–7.9 (delta efectivo tras `VOIDED`).
- La cobertura de `audit_log` para alta, enlace, anulación y CSV ya existe en `DexAuditApiTest` 8.1–8.5 y 9.1–9.3 tras `T-DEX-17`/`T-DEX-18`; el enlace ya se ejecuta por HTTP. Queda separar en la matriz «primitiva» de «flujo de producción».

**Criterio de cierre.**

1. Actualizar la matriz con una columna o nota que separe «primitiva» de «flujo de producción».
2. Enlazar cada criterio EARS a la aserción de integración que lo recorre de extremo a extremo.
3. Mantener el recuento de suites propias en **429/429** tras `T-DEX-15`–`T-DEX-19`, y recalcularlo si se añaden nuevas pruebas.

---

## 4. Impacto en los Criterios de Finalización (§9)

| DoD | Estado | Motivo |
|---|---|---|
| **DoD 1** · Migración, convergencia, tipos, tablas, índices y triggers sin alterar DDL previo | ✅ Cumplido | 415 sentencias, 35 migraciones, 54 tablas, 55 ENUM, 8 disparadores DEX, ningún `ALTER TABLE` sobre tablas previas |
| **DoD 2** · Parser, servicio, deltas, anti-spam, cruces M34-M35, anulación, CSV y proyecciones al 100 % | ✅ **Cumplido** | `T-DEX-15`–`T-DEX-19` cubren cruces, delta efectivo, auditoría y latencia: `DexAuditPerformanceTest` **3/3** con p95 **202,7 ms** |
| **DoD 3** · Integración móvil, consola de Coordinación y cola Offline PWA | ✅ Cumplido | 262/262 + 31/31 + 46/46 + 25/25 + 45/45 |
| **DoD 4** · Batería global, `new_engine`, navegador real y matriz | ⚠️ **Parcial** | Batería y navegador en verde (461/18.126 y 85/2.905), pero la matriz mantiene pendiente la separación «primitiva/flujo» (`AUD-39-05`) |

---

## 5. Plan de corrección propuesto

Orden recomendado, respetando el ciclo SDD (primero la prueba que falla, después la implementación):

1. ✅ **`AUD-39-01` · Cruces M34/M35 — cerrada el 2026-11-26 por `T-DEX-15`**
   - Escribir la aserción de integración que importa un `.dex` con visita M34 y recaudación M35 reales y exige `m34_cross_check`/`m35_cross_check` en el detalle.
   - Implementar la composición en `MachineDexAuditService` usando el gateway existente.
   - Verificar prioridad `CA3_VS_VAULT`, fallback `VA1_VS_EXPECTED`, `observed_consumption_units` y umbral ±1,00 €.
   - **Resultado:** `DexAuditApiTest` 3.7–3.9 en verde (**28/28**); 13 suites DEX **408/408**; global **461/18.126/0**; `new_engine` **84/2.884/0**.
2. ✅ **`AUD-39-02` · Delta efectivo tras `VOIDED` — cerrada el 2026-11-26 por `T-DEX-16`**
   - Escribir el escenario A0→A→B→anular A→leer B, y después anular A0 para el caso sin predecesor.
   - Resolver el predecesor `ACTIVE` en lectura y aplicar `withPreviousAuditVoided`.
   - **Resultado:** `DexAuditApiTest` 7.1–7.9 en verde (**37/37**); 13 suites DEX **417/417**; global **461/18.126/0**; `new_engine` **84/2.893/0**.
3. ✅ **`AUD-39-03` · Auditoría `MACHINE_DEX_AUDIT` — cerrada el 2026-11-26 por `T-DEX-17` y `T-DEX-18`**
   - Añadir la escritura de `audit_log` en alta, enlace, anulación y CSV; corregir la entidad de la anulación.
   - Añadir aserciones que consulten `audit_log`.
   - Cablear `DexAuditLinker` en las rutas M34/M35/M36 y certificarlo por HTTP.
   - **Resultado:** `DexAuditApiTest` 8.1–8.5 y 9.1–9.3 en verde (**46/46**); 13 suites DEX **426/426**; global **461/18.126/0**; `new_engine` **84/2.902/0**.
4. ✅ **`AUD-39-04` · Latencia `RNF-01` — cerrada el 2026-11-26 por `T-DEX-19`**
   - Añadir `DexAuditPerformanceTest` con 20 muestras, `.dex` de ~1 MB y p95 < 250 ms.
   - Optimizar la transacción de líneas con `COPY FROM STDIN` en lotes; sin DDL ni rutas nuevas.
   - **Resultado:** rojo **p95 814,6 ms** y verde **p95 202,7 ms**; 14 suites DEX **429/429**; global **461/18.126/0**; `new_engine` **85/2.905/0**.
5. **`AUD-39-05` · Matriz y ODD**
   - Actualizar la matriz, `tasks.md` y el ODD con la tarea correctiva y las nuevas cifras.
   - Reejecutar `php tests/run_all.php` y `php tests/run_new_engine.php`, y confirmar `DocMetricsGuard` coherente y residuo 0.

**Comandos de verificación al cierre de cada paso:**

```bash
php tests/unit/DexAuditDomainModelsTest.php
php tests/unit/MachineDexAuditServiceTest.php
php tests/unit/DexAuditServiceIntegrationsTest.php
php tests/integration/PdoMachineDexAuditRepositoryTest.php
php tests/integration/DexAuditApiTest.php
php tests/integration/DexAuditConstitutionalTest.php
php tests/integration/DexAuditConcurrencyTest.php
node tests/frontend/coordinator_dex_audits_tab.test.mjs
php tests/run_all.php
source deploy/local/postgres/.env.docker-tls && php tests/run_new_engine.php
```

---

## 6. Referencias

- Especificación: [`specs/39-eva-dts-dex-audit/spec.md`](../specs/39-eva-dts-dex-audit/spec.md)
- Plan: [`specs/39-eva-dts-dex-audit/plan.md`](../specs/39-eva-dts-dex-audit/plan.md)
- Tareas: [`specs/39-eva-dts-dex-audit/tasks.md`](../specs/39-eva-dts-dex-audit/tasks.md)
- Matriz: [`specs/39-eva-dts-dex-audit/traceability-matrix.md`](../specs/39-eva-dts-dex-audit/traceability-matrix.md)
- ODD: [`odd/tasks/eva-dts-dex-audit.md`](../odd/tasks/eva-dts-dex-audit.md)
- Constitución: [`constitution.md`](../constitution.md)
- Diseño: [`docs/design.md`](design.md)
