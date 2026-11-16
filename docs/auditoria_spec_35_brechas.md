# Auditoría de la Spec 35 · Brechas y Plan de Corrección Priorizado

- **Módulo:** 35 · Recaudación de Efectivo, Carga de Tubos de Cambio y Arqueo de Caja
- **Especificación auditada:** [`specs/35-cash-collection-vault-audit/spec.md`](../specs/35-cash-collection-vault-audit/spec.md) (v2.0)
- **Espejo funcional:** [`specs/functional/cash_collection_vault_audit_spec.md`](../specs/functional/cash_collection_vault_audit_spec.md) (idéntico a la especificación canónica)
- **Contratos:** [`specs/technical/cash_collection_vault_audit_contracts.md`](../specs/technical/cash_collection_vault_audit_contracts.md)
- **Sistema de Diseño:** [`docs/design.md`](design.md)
- **Fecha de auditoría:** 2026-11-16
- **Última actualización:** 2026-11-16 · `FIX-35-06` cerrado
- **Rama:** `feat/cash-collection-vault-audit` · Base auditada `000688b`
- **Alcance:** auditoría estática y ejecución real de suites. Este documento recoge el orden de corrección propuesto y se actualiza al cerrar cada FIX.

---

## Resultado general

La implementación está operativa y la batería ejecutada pasa en verde:

- Batería hermética global: **435/435 suites · 16.686 aserciones · 0 fallos · `exit=0`** (tras `FIX-35-03`).
- Suites focales del Módulo 35: todas en verde, entre ellas `PostgresCashCollectionVaultAuditSchemaTest` 46/46, `MachineCashCollectionValidatorTest` 114/114, `MachineCashCollectionServiceTest` 45/45, `CashCollectionDomainModelsTest` 74/74, `CashCollectionVaultAuditApiTest` 33/33, `CashCollectionVaultAuditConstitutionalTest` 25/25 y `CashCollectionConcurrencyTest` 6/6 (`FOR UPDATE OF m` real sobre PostgreSQL).
- Proyección móvil: `RestockerCashCollectionViewDTO` publica una lista blanca de 18 campos y omite las cinco claves financieras privadas del Arqueo Ciego.
- Frontend: `restocker_route_view.test.mjs` 209/209 y `coordinator_product_loading_tab.test.mjs` 80/80.
- Layout móvil: `CashCollectionLayoutBrowserTest` 32/32 a `360 × 800` en Chromium real, con `document.documentElement.scrollWidth = 360` en las cuatro superficies de recaudación y en Coordinación.
- Guardas de diseño: `CoordinatorButtonVariantsGuardTest.mjs` 23/23, `FrontendTemplateCompileTest.mjs` 15/15, `LegacyButtonPaletteRetirementTest.mjs` 18/18 y `DesignTokenDebtRatchetTest.mjs` 3/3.
- Tareas: **12/12** en [`tasks.md`](../specs/35-cash-collection-vault-audit/tasks.md) y **12/12** filas `✅ hecho` en [`odd/tasks/cash-collection-vault-audit.md`](../odd/tasks/cash-collection-vault-audit.md).
- `DocMetricsGuard` coherente, base restablecida con semillas intactas y **0 esquemas `t_*` residuales**.

Sin embargo, **la Spec 35 no puede declararse cumplida al 100 % en sentido literal**. El P0 contractual y el artefacto nominal `RestockerCashCollectionViewDTO` ya están corregidos; quedan varios huecos de certificación P1/P2. La implementación funcional es sólida; lo que falta es alinear el resto de la trazabilidad y demostrar lo que la especificación exige.

> **Conclusión corta:** el módulo funciona y las suites existentes pasan, pero la certificación literal aún no está cerrada. El P0 y `FIX-35-03` ya están corregidos; los puntos P1 restantes deben resolverse antes de volver a declarar la Spec 35 como cerrada.

---

## Lista ordenada por importancia

| Orden | ID | Prioridad | Brecha | Requisito afectado | Impacto | Estado |
|---:|---|---|---|---|---|---|
| **1** | **FIX-35-01** | **P0 · Bloqueante contractual** | El reenvío idempotente responde `201` con la clave `idempotent`; la spec exige `200` con `idempotent_replay = true` | `RF-01.2.3` | Contrato REST incumplido y test que certifica el comportamiento contrario; un cliente que implemente la spec no puede validar el reenvío | **Cerrada (2026-11-16)** |
| **2** | **FIX-35-02** | **P1 · Alto** | No hay prueba real de layout a `360 px` para los nuevos bloques/modales de recaudación; las guardas solo validan plantillas, tokens y geometría inline | `RNF-03`, DoD 3 | Riesgo de desbordamiento horizontal o contenido clipped en móvil; el requisito no está certificado | **Cerrada (2026-11-16)** |
| **3** | **FIX-35-03** | **P1 · Alto** | `RestockerCashCollectionViewDTO` no existe; la opacidad se resuelve con `MachineCashCollection::toRestockerArray()` | `RNF-02.3` | La conducta es correcta, pero el artefacto nombrado por la spec y por la matriz no existe; una auditoría literal no lo encuentra | **Cerrada (2026-11-16)** |
| **4** | **FIX-35-04** | **P1 · Alto** | El test de baja lógica comprueba snapshots de máquina y reponedor, pero no `vault_reconciled_by_name_snapshot` | `QA 1`, Art. III.3 | No está certificada la conservación del revisor de caja tras una baja lógica | **Cerrada (2026-11-16)** |
| **5** | **FIX-35-05** | **P1 · Alto** | El código registra `CASH_COLLECTION_DELIVERED_TO_VAULT`, pero ninguna suite lo asserta | `RF-04.1`, Art. III.3 | La trazabilidad de la recepción en cámara existe, pero no tiene regresión que la proteja | **Cerrada (2026-11-16)** |
| **6** | **FIX-35-06** | **P2 · Medio** | No hay aserción específica de usuario/máquina inactivos ni del bloqueo `FOR UPDATE OF m` en la captura | `RF-02.1` | La defensa está en el código, pero no está certificada por una prueba del módulo | **Cerrada (2026-11-16)** |
| **7** | **FIX-35-07** | **P2 · Medio** | La spec afirma haber cerrado 17 puntos QA, pero no los enumera; solo se documentan 4 resoluciones agregadas | `QA 1`–`QA 4`, §9 | No es posible verificar 17/17 ni auditar qué observación cerró cada cambio | **Abierta** |
| **8** | **FIX-35-08** | **P2 · Medio** | La matriz usa una subdivisión de `RF-05` distinta de la spec, cita el DTO inexistente y el ODD habla de 3 resoluciones frente a las 4 de la spec | Trazabilidad, DoD | La matriz no es un reflejo 1:1 de los EARS y puede inducir a error en la próxima auditoría | **Abierta** |
| **9** | **FIX-35-09** | **P3 · Verificación** | La certificación final debe repetirse tras los cambios y cerrar la diferencia del canal `new_engine` | DoD 5 | Las cifras actuales quedarán obsoletas al añadir pruebas; además el canal registra 1 suite no ejecutable por TLS local | **Abierta** |

### Lectura de prioridades

- **P0:** incumple un contrato explícito o impide certificar el módulo. Debe resolverse antes de cualquier cierre.
- **P1:** no siempre rompe el runtime, pero impide una certificación literal y puede ocultar un riesgo de UI o de trazabilidad.
- **P2:** deuda de cobertura o de documentación; no bloquea el flujo actual, pero mantiene la certificación incompleta.
- **P3:** verificación final dependiente de los anteriores; incluye métricas, canales y limpieza.

---

## FIX-35-01 · Idempotencia contractual de la captura independiente · P0 · ✅ Cerrada

### Requisito

`RF-01.2.3` exige:

> «**SI** el reponedor reenvía una petición con el mismo `(restocker_id, client_mutation_id)`, **ENTONCES EL SISTEMA DEBERÁ** devolver `200 OK` (`idempotent_replay = true`) con el registro previamente creado sin duplicar filas ni efectos colaterales.»

### Evidencia previa (antes de la corrección)

- `src/Presentation/Controller/RestockerRouteController.php:442` devuelve siempre `201` en `registerCashCollection()`, tanto en alta nueva como en reenvío.
- `src/Application/Service/MachineCashCollectionService.php:131` devuelve la clave `idempotent` (`true`), no `idempotent_replay`.
- `tests/integration/CashCollectionVaultAuditApiTest.php:305` espera `201` en el reenvío y `:307` espera `idempotent`; el test certifica el comportamiento contrario a la spec.
- El contrato técnico describe la operación como idempotente, pero no explicita el `200` ni la clave `idempotent_replay`.
- Ya existe un precedente interno correcto: `OfflineSyncService` y `OfflinePwaSyncApiTest` usan `200 OK` + `idempotent_replay = true` para el reenvío de mutaciones offline.

### Impacto

Es la brecha más importante porque afecta al contrato público de la API y a la semántica de la cola Offline. Un cliente que implemente la especificación puede tratar el `201` como una creación nueva y no como un replay. Además, el test actual impide corregir el comportamiento sin actualizarlo, de modo que la batería seguiría en verde con una desviación real.

### Corrección propuesta

1. Hacer que el servicio distinga explícitamente entre alta nueva y replay, sin inferirlo solo por una clave del cuerpo.
2. En el controlador, responder `201` en el alta nueva y `200` cuando el servicio indique replay.
3. Publicar `idempotent_replay` con valor `true` en el replay y `false` en el alta nueva. Mantener o retirar `idempotent` solo después de comprobar que ningún consumidor dependa de ella; en el repositorio no hay consumidor frontend de esa clave.
4. Actualizar `CashCollectionVaultAuditApiTest` para que el reenvío exija `200`, `idempotent_replay = true`, mismo `id`, misma fila y ausencia de efectos colaterales.
5. Actualizar el contrato técnico para documentar ambos códigos y la forma exacta de la respuesta.

### Criterios de aceptación

- [x] El primer `POST` con un `client_mutation_id` nuevo responde `201` e `idempotent_replay = false`.
- [x] El segundo `POST` con el mismo `(restocker_id, client_mutation_id)` responde `200` e `idempotent_replay = true`.
- [x] El reenvío devuelve el mismo `id` y no crea una segunda fila ni un segundo evento de auditoría.
- [x] Un precinto ya usado con otra mutación sigue respondiendo `409 BAG_SEAL_CODE_ALREADY_USED`.
- [x] El test falla si el controlador vuelve a devolver `201` en el replay o si la clave vuelve a llamarse `idempotent`.

### Cierre

Implementado el 2026-11-16:

- `MachineCashCollectionService::registerCollection()` devuelve `idempotent_replay = false` en el alta y `true` en el replay; ambas rutas de replay devuelven la proyección del reponedor.
- `RestockerRouteController::registerCashCollection()` responde `200` cuando el servicio marca replay y conserva `201` en el alta nueva.
- `CashCollectionVaultAuditApiTest` exige `201/false` en el alta y `200/true` en el reenvío; el caso `3.1`/`3.2` pasó de rojo a verde.
- `MachineCashCollectionServiceTest` verifica además que el replay no duplica la auditoría del sellado.
- El contrato técnico documenta ambos códigos en la subsección 1.1 y prohíbe publicar la clave heredada `idempotent`.

Evidencia focal: `MachineCashCollectionServiceTest` 45/45, `CashCollectionVaultAuditApiTest` 30/30, `CashCollectionVaultAuditConstitutionalTest` 23/23 y `MachineRestockVisitServiceTest` 50/50, todas con `exit=0`. No se ha hecho push.

### Archivos previsibles

- `src/Presentation/Controller/RestockerRouteController.php`
- `src/Application/Service/MachineCashCollectionService.php`
- `tests/integration/CashCollectionVaultAuditApiTest.php`
- `tests/unit/MachineCashCollectionServiceTest.php`
- `specs/technical/cash_collection_vault_audit_contracts.md`

---

## FIX-35-02 · Certificación real de RNF-03 a 360 px · P1 · ✅ Cerrada

### Requisito

`RNF-03` exige:

> «Implementación íntegra en PHP 8.2+ estricto y Vue 3 ES Modules nativos sin librerías externas, respetando el layout móvil a `360px` sin desbordamiento horizontal y los tokens `vg-*`.»

### Evidencia previa (antes del cierre)

- `tests/frontend/restocker_route_view.test.mjs` 209/209 valida estado, plantillas, cola Offline, recibo y opacidad, pero no abre un viewport ni mide desbordamiento.
- `tests/unit/CoordinatorButtonVariantsGuardTest.mjs` 23/23 valida la plantilla móvil y la geometría inline de los botones, pero no el layout renderizado.
- `tests/unit/FrontendTemplateCompileTest.mjs` 15/15 compila 79 plantillas y 5.076 expresiones, no mide `scrollWidth` ni `clientWidth`.
- Existe una medición manual anterior en Engram (`#198`, 2026-11-14) de `RestockerRouteView` a 360 px, pero cubre el planograma, el asistente de visita in situ y «Mis Horas», anteriores a la superficie de recaudación del Módulo 35. No cubre el modal rápido de recaudación, el bloque de la visita, el historial de bolsas ni el recibo.
- La matriz de trazabilidad declara `RNF-03` en verde, pero no cita una prueba de viewport.

### Impacto

La UI de recaudación añade un modal con varios campos, un bloque dentro del asistente de visita, un historial y un recibo imprimible. Sin una comprobación a 360 px, un desbordamiento horizontal o un modal fuera de viewport puede pasar la batería actual. Es un riesgo de usabilidad y de fidelidad al Sistema de Diseño.

### Corrección propuesta

1. Ejecutar una comprobación en navegador real a `360 × 800` con datos sembrados y abrir, al menos:
   - el modal rápido de recaudación;
   - el bloque de recaudación del asistente de visita;
   - el historial «Mis Bolsas Precintadas y Cambios de Ruta»;
   - el recibo de conserjería;
   - la sub-vista de Coordinación si se quiere cubrir el layout estrecho.
2. Verificar `document.documentElement.scrollWidth <= 360` y ausencia de elementos con `scrollWidth > clientWidth` dentro de los contenedores principales.
3. Comprobar que los modales caben, que los botones son accesibles y que ningún campo queda clipped.
4. Si se automatiza, usar la herramienta de navegador ya disponible en el proyecto; **no añadir una dependencia nueva sin aprobación**.
5. Si se opta por una verificación manual, registrar fecha, ruta, viewport y resultado en este documento o en la matriz, con enlace a la evidencia.

### Criterios de aceptación

- [x] Las cuatro superficies de recaudación se abren a 360 px sin desbordamiento horizontal.
- [x] `scrollWidth` del documento no supera el ancho del viewport.
- [x] Los modales y el recibo son legibles y operables sin scroll horizontal.
- [x] La evidencia queda registrada y la matriz deja de declarar `RNF-03` como cubierto solo por guardas de plantilla.

### Cierre

Verificado el 2026-11-16 en Chromium real (`chromium-1228`), a `360 × 800`, con la barra de scroll oculta para emular el viewport móvil y sobre los componentes reales con los estilos de producción:

| Superficie | Comprobación | Resultado |
|---|---|---|
| Historial «Mis Bolsas Precintadas y Cambios de Ruta» | Documento, sección y tarjeta dentro de `0..360` | ✅ sin scroll horizontal |
| Modal rápido de recaudación / cambio | Diálogo `360 × 736`, `scrollWidth = 360`; acción principal alcanzable con scroll vertical | ✅ sin scroll horizontal |
| Bloque de recaudación de la visita | Bloque visible tras scroll vertical, dentro de `0..360`; sin controles fuera del viewport | ✅ sin scroll horizontal |
| Recibo de conserjería | Ocho filas visibles, `scrollWidth = 360`; botón de impresión dentro del viewport | ✅ sin scroll horizontal |
| Coordinación (sub-vista y detalle) | Documento `360`; tabla `762 px` dentro de un contenedor con `overflow-x: auto`; modal de detalle `328 px` | ✅ scroll interno, nunca del documento |

- La suite nueva es `tests/integration/CashCollectionLayoutBrowserTest.php` (`new_engine`) y su arnés `tests/Support/CashCollectionLayoutBrowserRunner.mjs`; monta `RestockerRouteView.js` y `CoordinatorProductLoadingTab.js` con `tests/Support/cash_collection_layout_restocker.html` y `tests/Support/cash_collection_layout_coordinator.html`.
- Resultado: **32/32 aserciones · 0 fallos · `exit=0`**. El canal `new_engine` queda en **65 suites · 64 en verde · 1 no ejecutable por el contrato TLS local · 2.121 aserciones · 0 fallos**.
- No se modificó código de producción: la medición no encontró ningún desbordamiento horizontal real que corregir. La evidencia queda protegida por una regresión de navegador, no solo por guardas de plantilla.

### Archivos previsibles

- `public/assets/js/components/RestockerRouteView.js`
- `public/assets/js/components/CoordinatorProductLoadingTab.js`
- `tests/frontend/restocker_route_view.test.mjs`
- `tests/unit/CoordinatorButtonVariantsGuardTest.mjs`
- `tests/unit/FrontendTemplateCompileTest.mjs`
- `tests/integration/CashCollectionLayoutBrowserTest.php`
- `tests/Support/CashCollectionLayoutBrowserRunner.mjs`
- `tests/Support/cash_collection_layout_restocker.html`
- `tests/Support/cash_collection_layout_coordinator.html`
- `specs/35-cash-collection-vault-audit/traceability-matrix.md`

---

## FIX-35-03 · Artefacto `RestockerCashCollectionViewDTO` · P1 · ✅ Cerrada

### Requisito

`RNF-02.3` exige:

> «`RestockerCashCollectionViewDTO` omitirá siempre `previous_sales_counter_reading`, `expected_cashbox_eur`, `vault_counted_eur`, `discrepancy_eur` y `discrepancy_justification`…»

### Evidencia previa (antes del cierre)

- No existe ninguna clase `RestockerCashCollectionViewDTO` en `src/` ni en `tests/`.
- La proyección móvil real es `MachineCashCollection::toRestockerArray()`, que ya omite las cinco claves privadas.
- El recibo se resuelve con `MachineCashCollection::toReceptionReceiptArray()`.
- Las suites `CashCollectionDomainModelsTest` 65/65, `MachineCashCollectionServiceTest` 45/45, `CashCollectionVaultAuditApiTest` 30/30, `CashCollectionVaultAuditConstitutionalTest` 23/23 y `restocker_route_view.test.mjs` 209/209 certifican la opacidad funcional.
- La matriz de trazabilidad cita `RestockerCashCollectionViewDTO` como si existiera, pero no hay archivo ni prueba que lo instancie.

### Impacto

La conducta de opacidad está bien resuelta, pero el artefacto nombrado por la especificación no existe. Una auditoría literal no puede enlazar `RNF-02.3` con una clase real; la matriz está citando un fantasma documental.

### Corrección propuesta

Elegir una de estas dos vías y dejarla aprobada antes de cerrar:

- **A. Implementar el DTO:** crear `src/Application/DTO/RestockerCashCollectionViewDTO.php` con una lista blanca de campos públicos, construirlo desde `MachineCashCollection` y usarlo en la respuesta móvil. Añadir una prueba que instancie el DTO y confirme la ausencia exacta de las cinco claves.
- **B. Enmienda documental:** si el Product Owner acepta que la proyección canónica sea `MachineCashCollection::toRestockerArray()`, actualizar `RNF-02.3`, la matriz y el ODD para nombrarla como artefacto normativo. No mantener dos nombres para el mismo contrato.

La opción A es la más conservadora si se quiere preservar el texto aprobado; la B evita crear una clase sin uso real. La decisión debe quedar registrada.

### Criterios de aceptación

- [x] Existe una decisión explícita: DTO implementado o enmienda aprobada.
- [x] La prueba de opacidad verifica las cinco claves privadas, no solo el estado de la respuesta.
- [x] La matriz ya no cita un artefacto inexistente.
- [x] El recibo sigue exponiendo exactamente nueve campos y no incorpora `bag_seal_code`, `pickup_pin` ni `expected_cashbox_eur`.

### Cierre

Se eligió la **opción A** y se implementó el 2026-11-16:

- Creado `src/Application/DTO/RestockerCashCollectionViewDTO.php`, una clase `final readonly` con lista blanca de 18 campos y fábrica `fromMachineCashCollection()`. Las cinco claves privadas del `RNF-02.3` no existen en el DTO, ni siquiera como propiedades privadas.
- `MachineCashCollectionService` construye la respuesta móvil del alta, el replay idempotente, el bootstrap de ruta, el contexto de ruta y el historial con el DTO. `MachineCashCollection::toRestockerArray()` queda como proyección interna del dominio, no como contrato del borde móvil.
- `CashCollectionDomainModelsTest` instancia el DTO y verifica la lista exacta de claves, la igualdad con la proyección pública y la ausencia de las cinco claves privadas (`74/74` aserciones).
- `CashCollectionVaultAuditApiTest` refuerza la captura y el historial para comprobar las cinco claves privadas en la respuesta HTTP real (`30/30`).
- El recibo de conserjería conserva exactamente nueve campos y sigue sin `bag_seal_code`, `pickup_pin` ni `expected_cashbox_eur`.
- Evidencia focal: `php tests/unit/CashCollectionDomainModelsTest.php` 74/74, `php tests/unit/MachineCashCollectionServiceTest.php` 45/45 y `php tests/integration/CashCollectionVaultAuditApiTest.php` 30/30, todas con `exit=0`.
- No se modificó la especificación: el DTO implementa el artefacto que `RNF-02.3` ya exigía.

### Archivos

- `src/Application/DTO/RestockerCashCollectionViewDTO.php`
- `src/Core/Domain/Model/MachineCashCollection.php`
- `src/Application/Service/MachineCashCollectionService.php`
- `tests/unit/CashCollectionDomainModelsTest.php`
- `tests/integration/CashCollectionVaultAuditApiTest.php`
- `specs/35-cash-collection-vault-audit/traceability-matrix.md`

---

## FIX-35-04 · Snapshot del revisor de caja tras baja lógica · P1 · ✅ Cerrada

### Requisito

La resolución `QA 1` exige que la Tabla 49 congele al arquear `vault_reconciled_by_name_snapshot`, «garantizando legibilidad histórica perpetua». El caso §6.5 exige que una bolsa en tránsito se pueda arquear aunque la máquina o el operario hayan causado baja después.

### Evidencia previa (antes del cierre)

- `tests/integration/CashCollectionVaultAuditConstitutionalTest.php:490-491` desactiva el reponedor y la máquina; `5.1` recepciona y `5.2` arquea correctamente.
- `5.3` selecciona únicamente `machine_code_snapshot`, `restocker_name_snapshot` y `restocker_code_snapshot`; **no selecciona ni asserta `vault_reconciled_by_name_snapshot`**.
- `MachineCashCollectionServiceTest` 45/45 verifica el snapshot del revisor en el flujo normal, pero no después de una baja lógica.
- La matriz declara `QA 1` en verde sin singularizar esta columna.

### Impacto

La trazabilidad histórica del revisor es una obligación de auditoría, no un detalle cosmético. Si el nombre se perdiera al desactivar la cuenta, el arqueo seguiría pasando las pruebas actuales pero el histórico quedaría incompleto.

### Corrección propuesta

1. Ampliar el escenario constitucional para seleccionar también `vault_reconciled_by_name_snapshot` después de arquear.
2. Desactivar también al coordinador/revisor después del arqueo y comprobar que el snapshot permanece.
3. Verificar que el valor coincide con el nombre del actor que ejecutó el arqueo y que no se recalcula desde `users` en la lectura histórica.
4. Actualizar la fila `QA 1` de la matriz con la nueva aserción.

### Criterios de aceptación

- [x] El test falla si se elimina la escritura de `vault_reconciled_by_name_snapshot`.
- [x] El snapshot del revisor sigue siendo legible después de `is_active = FALSE` del coordinador.
- [x] Los snapshots de máquina, reponedor y revisor se comprueban en la misma prueba de baja lógica.
- [x] La matriz enlaza la aserción concreta.

### Cierre

Cerrada el 2026-11-16 sobre `tests/integration/CashCollectionVaultAuditConstitutionalTest.php`:

- La prueba de `QA 1` arquea la bolsa en tránsito con un coordinador revisor dedicado (`Revisor Caja T-CCV-09`), después de dar de baja lógica al reponedor y a la máquina (`is_active = FALSE`).
- Tras el arqueo, el revisor también pasa a `is_active = FALSE` y se renombra su fila en `users` (`Revisor Renombrado Tras Baja`) para demostrar que la lectura histórica no recalcula el nombre desde `users`.
- `5.3` selecciona en PostgreSQL `machine_code_snapshot`, `restocker_name_snapshot`, `restocker_code_snapshot` y `vault_reconciled_by_name_snapshot` y verifica los cuatro valores congelados.
- `5.4` consulta `GET /api/coordinator/cash-collections/{id}` con el coordinador titular y verifica por HTTP los cuatro snapshots históricos inmutables.
- Si se elimina la escritura de `vault_reconciled_by_name_snapshot`, el bloque falla (además de la restricción `chk_machine_cash_collections_vault_lifecycle` en PostgreSQL y la validación del repositorio).
- Evidencia focal: `php tests/integration/CashCollectionVaultAuditConstitutionalTest.php` → **24/24 aserciones · 0 fallos · `exit=0`**. Canal `new_engine`: **65 suites · 64 en verde · 1 no ejecutable por el contrato TLS local · 2.122 aserciones · 0 fallos**.

### Archivos

- `tests/integration/CashCollectionVaultAuditConstitutionalTest.php`
- `specs/35-cash-collection-vault-audit/traceability-matrix.md`
- `odd/tasks/cash-collection-vault-audit.md`

---

## FIX-35-05 · Auditoría de `CASH_COLLECTION_DELIVERED_TO_VAULT` · P1 · ✅ Cerrada

### Requisito

`RF-04.1` exige que la recepción en cámara transicione a `DELIVERED_TO_VAULT` y **audite la transición en `audit_log`**. El Art. III.3 exige trazabilidad de las operaciones críticas.

### Evidencia previa (antes del cierre)

- `src/Application/Service/MachineCashCollectionService.php:59` define `ACTION_CASH_COLLECTION_DELIVERED_TO_VAULT`.
- `:406` registra el evento al recepcionar.
- `grep -R "CASH_COLLECTION_DELIVERED_TO_VAULT" tests` devuelve **0 resultados**.
- `CashCollectionVaultAuditConstitutionalTest` `6.1` solo comprueba `CASH_COLLECTION_SEALED` y `CASH_COLLECTION_RECONCILED`; `6.2` solo comprueba el rol del arqueo.

### Impacto

La recepción física de la bolsa queda auditada en producción, pero una regresión podría eliminar el evento sin que la batería falle. La trazabilidad de la cadena de custodia quedaría incompleta sin señal.

### Corrección propuesta

1. Añadir una aserción en el bloque 6 del test constitucional para contar al menos un evento `CASH_COLLECTION_DELIVERED_TO_VAULT` con `entity_type = 'MACHINE_CASH_COLLECTION'` y el `entity_id` de la bolsa.
2. Verificar `user_role = 'COORDINATOR'` y el actor que ejecutó la recepción.
3. Si el test revela que el evento se registra fuera de la misma transacción, corregir la transacción; no basta con que exista la constante.

### Criterios de aceptación

- [x] La suite falla si se elimina el `logEvent` de la recepción.
- [x] Existe al menos un evento `CASH_COLLECTION_DELIVERED_TO_VAULT` vinculado a la bolsa correcta.
- [x] El evento conserva el rol y el actor de Coordinación.
- [x] La matriz y el ODD citan la aserción.

### Cierre

Cerrada el 2026-11-16 sobre `tests/integration/CashCollectionVaultAuditConstitutionalTest.php`:

- El bloque 6 ahora exige en `6.1` el conteo de `CASH_COLLECTION_DELIVERED_TO_VAULT` junto con `CASH_COLLECTION_SEALED` y `CASH_COLLECTION_RECONCILED` sobre `entity_type = 'MACHINE_CASH_COLLECTION'`.
- La nueva aserción `6.3` consulta `audit_log` para `entity_id = $transitId` y `action = 'CASH_COLLECTION_DELIVERED_TO_VAULT'`, comprobando una fila vinculada a la bolsa recepcionada con `user_id = $coordinatorId`, `user_role = 'COORDINATOR'` y `user_name = 'Coordinación T-CCV-09'`.
- La auditoría se ejecuta dentro de la misma transacción en `MachineCashCollectionService::deliverToVault()`, por lo que no fue necesario modificar código de producción ni especificaciones.
- Evidencia focal: `php tests/integration/CashCollectionVaultAuditConstitutionalTest.php` → **25/25 aserciones · 0 fallos · `exit=0`**. Canal `new_engine`: **65 suites · 64 en verde · 1 no ejecutable por el contrato TLS local · 2.123 aserciones · 0 fallos**.

### Archivos

- `tests/integration/CashCollectionVaultAuditConstitutionalTest.php`
- `specs/35-cash-collection-vault-audit/traceability-matrix.md`
- `odd/tasks/cash-collection-vault-audit.md`

---

## FIX-35-06 · Cobertura de actividad y bloqueo pesimista en RF-02.1 · P2 · ✅ Cerrada

### Requisito

`RF-02.1` exige que el actor sea un usuario activo con rol `RESTOCKER`, que la máquina exista y esté activa, que se bloquee la fila de la máquina con `SELECT ... FOR UPDATE` y que la máquina pertenezca a una zona activa cubierta por el reponedor.

### Evidencia previa (antes del cierre)

- `PdoMachineCashCollectionGateway` incluye `m.is_active = TRUE`, `oz.is_active = TRUE` y `FOR UPDATE OF m` en la consulta de contexto.
- `InternalAuthMiddleware` valida el usuario activo antes de llegar al controlador.
- Las suites prueban rol, zona (`403 MACHINE_OUTSIDE_RESTOCKER_ZONES`) y las tres guardas sanitarias.
- No hay una aserción específica del módulo para:
  - usuario `RESTOCKER` inactivo;
  - máquina `is_active = FALSE`;
  - dos capturas concurrentes sobre la misma máquina y el orden de espera del `FOR UPDATE`.
- La matriz reduce `RF-02.1` a rol, zona y guardas sanitarias.

### Impacto

La defensa está en el código, pero la certificación no demuestra que una máquina dada de baja no pueda recaudar ni que el bloqueo pesimista serialice dos capturas. Es un hueco de cobertura, no un fallo observado.

### Corrección propuesta

1. Añadir un caso HTTP con reponedor inactivo y comprobar `401`/`403` sin crear fila.
2. Añadir un caso con máquina inactiva y documentar el código de rechazo esperado; si el gateway la confunde con «fuera de zona», decidir si ese mapeo es aceptable o si necesita un código propio.
3. Añadir un caso con zona inactiva.
4. Si se quiere certificar el bloqueo, usar dos conexiones reales con una barrera común y comprobar que la segunda espera y luego ve el estado final, sin duplicar la captura. No simular el `FOR UPDATE` con un doble en memoria.
5. Actualizar la matriz con las aserciones.

### Criterios de aceptación

- [x] Un usuario inactivo no puede registrar una recaudación.
- [x] Una máquina inactiva no puede registrar una recaudación y el código de error queda documentado.
- [x] Una zona inactiva no puede registrar una recaudación.
- [x] La prueba de concurrencia, si se añade, demuestra serialización y ausencia de duplicados.
- [x] La matriz ya no declara `RF-02.1` cubierto solo por rol/zona.

### Cierre

Cerrada el 2026-11-16 con pruebas HTTP y de concurrencia real en PostgreSQL:

- **`tests/integration/CashCollectionVaultAuditApiTest.php`** (`3.4d`–`3.4f`, **33/33**):
  - `3.4d`: zona operativa con `operational_zones.is_active = FALSE` → `403 MACHINE_OUTSIDE_RESTOCKER_ZONES` sin insertar fila.
  - `3.4e`: máquina con `machines.is_active = FALSE` → `404 MACHINE_NOT_FOUND` sin insertar fila (documentado ya en `specs/technical/cash_collection_vault_audit_contracts.md` §3; no confunde máquina inactiva con «fuera de zona» porque `lockMachineContext()` filtra `m.is_active = TRUE` y el servicio mapea `null` a `404 MACHINE_NOT_FOUND`).
  - `3.4f`: usuario `RESTOCKER` con `users.is_active = FALSE` → `401 UNAUTHORIZED` (`InternalAuthMiddleware`) sin insertar fila.
- **`src/Application/Service/MachineCashCollectionService.php`** + **`tests/integration/CashCollectionConcurrencyTest.php`** + **`tests/Support/CashCollectionConcurrencyActor.php`** (**6/6**):
  - Al certificar la carrera real con barrera común sobre `FOR UPDATE OF m`, el segundo `findByClientMutationId()` estaba antes de `lockMachineContext()`, por lo que el actor que esperaba el cerrojo con la misma `(restocker_id, client_mutation_id)` caía luego en `409 BAG_SEAL_CODE_ALREADY_USED` en vez de leer la fila recién confirmada. Se movió la relectura idempotente inmediatamente **después** de `lockMachineContext()` para que, una vez liberado el `FOR UPDATE OF m`, cualquier mutación repetida vea la fila del ganador y devuelva `idempotent_replay = true`.
  - Bloque 1 (`1.1`–`1.3`): dos procesos compiten en barrera común tras un `FOR UPDATE`, ambos aparecen bloqueados en `pg_stat_activity.wait_event_type = 'Lock'` y convergen en el mismo `id` con una sola fila y un solo `CASH_COLLECTION_SEALED`.
  - Bloque 2 (`2.1`–`2.3`): un retenedor observa al servicio detenido en `pg_stat_activity`, registra `200,00 €` y hace `COMMIT`; al despertar, la petición del padre lee la nueva lectura previa y rechaza `150,00 €` con `422 SALES_COUNTER_REGRESSION`.
- Evidencia focal: `php tests/unit/MachineCashCollectionServiceTest.php` → **45/45**, `php tests/integration/CashCollectionVaultAuditApiTest.php` → **33/33**, `php tests/integration/CashCollectionConcurrencyTest.php` → **6/6**, todas con `exit=0`. Canal `new_engine`: **66 suites · 65 en verde · 1 no ejecutable por el contrato TLS local · 2.132 aserciones · 0 fallos**.

### Archivos

- `src/Application/Service/MachineCashCollectionService.php`
- `tests/integration/CashCollectionVaultAuditApiTest.php`
- `tests/integration/CashCollectionConcurrencyTest.php`
- `tests/Support/CashCollectionConcurrencyActor.php`
- `specs/35-cash-collection-vault-audit/traceability-matrix.md`
- `odd/tasks/cash-collection-vault-audit.md`

---

## FIX-35-07 · Enumerar o corregir los 17 puntos QA · P2

### Requisito

La spec v2.0 afirma que `QA 1`–`QA 4` cierran «los 17 puntos de la revisión QA profesional». El §9 repite la cifra.

### Evidencia actual

- La spec solo enumera cuatro resoluciones agregadas (`QA 1`–`QA 4`).
- Los 17 puntos no aparecen en `cash_collection_vault_audit_contracts.md`, `plan.md`, `tasks.md`, el ODD ni la matriz.
- La observación de Engram `#201` enumera **7 de los 17** puntos, no el listado completo; no puede sustituir a la especificación aprobada.
- `plan.md` y el ODD mencionan la cifra, pero no la desglosan.

### Impacto

No se puede verificar 17/17 ni saber qué observación concreta cerró cada cambio. La cifra funciona como afirmación de confianza, no como trazabilidad auditable.

### Corrección propuesta

Elegir una de estas dos vías:

- **A. Recuperar el informe QA original** del Product Owner y añadir una tabla `17 puntos → requisito → cambio → test` en la matriz o en un anexo de la spec.
- **B. Enmienda aprobada:** si el informe ya no está disponible, sustituir la afirmación por «4 resoluciones QA agregadas» y eliminar la cifra 17 de la spec, el plan, el ODD y la matriz. No inventar el listado.

No modificar la spec sin consenso; la decisión debe quedar registrada.

### Criterios de aceptación

- [ ] Existe una tabla con 17 filas verificables o se ha eliminado la afirmación de 17.
- [ ] Cada fila enlaza un requisito, un cambio y una prueba.
- [ ] La spec, el espejo, el plan, el ODD, las tareas y la matriz usan la misma cifra.
- [ ] La matriz no declara `QA 1`–`QA 4` como cierre de 17 puntos sin evidencia.

### Archivos previsibles

- `specs/35-cash-collection-vault-audit/spec.md` y espejo (solo con aprobación)
- `specs/technical/cash_collection_vault_audit_contracts.md`
- `specs/35-cash-collection-vault-audit/plan.md`
- `specs/35-cash-collection-vault-audit/tasks.md`
- `odd/tasks/cash-collection-vault-audit.md`
- `specs/35-cash-collection-vault-audit/traceability-matrix.md`

---

## FIX-35-08 · Alineación de la matriz de trazabilidad · P2

### Evidencia actual

- La matriz agrupa en `RF-05.1` captura integrada/independiente, conserjería, recibo, historial y cola Offline; la spec separa `RF-05.1` (cola Offline y conflictos), `RF-05.2` (superficie móvil) y `RF-05.3` (sub-vista de Coordinación). La numeración no es 1:1.
- La fila `RNF-02` ya cita el `RestockerCashCollectionViewDTO` real creado en `FIX-35-03`; la deuda restante es la numeración de `RF-05`.
- El ODD (`L2`) habla de «3 resoluciones de auditoría QA»; la spec define 4 (`QA 1`–`QA 4`).
- La matriz ya enlaza `RNF-03` con la verificación real a 360 px de `FIX-35-02`.

### Impacto

La matriz es el artefacto que se usa para certificar el módulo. Si su numeración no coincide con los EARS, una auditoría futura puede dar por cubierto un criterio que en realidad se está midiendo con otro. No es un fallo de runtime, pero sí de trazabilidad.

### Corrección propuesta

1. Renumerar las filas de `RF-05` para que coincidan exactamente con los EARS de la spec.
2. Mantener la referencia al DTO real; `FIX-35-03` ya la resolvió.
3. Corregir el ODD para hablar de 4 resoluciones, salvo que FIX-35-07 recupere el listado completo.
4. Mantener la evidencia de 360 px enlazada; `FIX-35-02` ya la resolvió.
5. Revisar que cada fila tenga un test concreto y un resultado reproducible; no usar `✅` sin suite o evidencia manual enlazada.

### Criterios de aceptación

- [ ] Cada fila de `RF-05` coincide con un EARS de la spec.
- [ ] No se cita ningún artefacto inexistente.
- [ ] El ODD y la spec usan la misma cifra de resoluciones QA.
- [ ] `RNF-03` enlaza la verificación real a 360 px.
- [ ] La matriz no duplica la cifra viva de la batería; la referencia al README/`features_pendientes` se mantiene.

### Archivos previsibles

- `specs/35-cash-collection-vault-audit/traceability-matrix.md`
- `odd/tasks/cash-collection-vault-audit.md`
- `specs/35-cash-collection-vault-audit/tasks.md` (si se añade una tarea de corrección)
- `docs/features_pendientes.md` (solo si cambia el estado de cierre)

---

## FIX-35-09 · Verificación final, `new_engine` y métricas · P3

### Requisito

El DoD 5 exige que toda la batería de pruebas unitarias, de integración PostgreSQL/HTTP, de frontend y de blindaje constitucional pase al 100 % en verde.

### Evidencia actual

- Batería global: **435/435 suites · 16.686 aserciones · 0 fallos · `exit=0`**; `DocMetricsGuard` coherente.
- Las suites del Módulo 35 se ejecutaron focalmente y están en verde.
- La matriz y el ODD documentan para `new_engine` **66 suites, 65 en verde y 1 no ejecutable por el contrato TLS local**, 2.132 aserciones, 0 fallos, `exit=0`.
- Tras `FIX-35-06` se ejecutó el canal `new_engine` completo y la única no ejecutable sigue siendo `PostgresTieredSlaMetricsPerformanceTest` por el contrato TLS local.

### Impacto

Cada nueva prueba o cambio de contrato modifica los recuentos. Si no se reejecuta la certificación, `DocMetricsGuard` puede quedar desincronizado y la matriz volverá a citar cifras obsoletas. Además, para certificar el DoD 5 literalmente hay que resolver o documentar la suite no ejecutable del canal TLS.

### Corrección propuesta

1. Ejecutar las suites focales afectadas por cada FIX.
2. Ejecutar `php tests/run_all.php` con el entorno de batería.
3. Ejecutar `php tests/run_new_engine.php` con el contrato TLS que permita completar las 65 suites; si no está disponible, dejar constancia explícita de la suite no ejecutable y no presentarla como verde.
4. Verificar `DocMetricsGuard`, semillas restauradas y ausencia de esquemas `t_*`.
5. Actualizar matriz, ODD, tareas, README y `docs/features_pendientes.md` solo con las cifras de la corrida final.
6. Marcar este documento como cerrado y enlazar la corrida final.

### Criterios de aceptación

- [ ] Todas las suites focales de los FIX anteriores están en verde.
- [ ] La batería global queda en verde y con `DocMetricsGuard` coherente.
- [ ] El canal `new_engine` queda en 65 suites (64 verdes) con una excepción documentada y aceptada; no se oculta una suite no ejecutable.
- [ ] No quedan esquemas `t_*` ni datos de prueba residuales.
- [ ] Las métricas de README, `features_pendientes.md`, matriz y ODD coinciden con la última corrida.
- [ ] El estado de la Spec 35 se actualiza solo después de cerrar P0/P1.

### Archivos previsibles

- `specs/35-cash-collection-vault-audit/traceability-matrix.md`
- `odd/tasks/cash-collection-vault-audit.md`
- `specs/35-cash-collection-vault-audit/tasks.md`
- `README.md`
- `docs/features_pendientes.md`
- `docs/auditoria_spec_35_brechas.md` (este documento)

---

## Orden de ejecución recomendado

```text
1. FIX-35-01  Contrato de idempotencia 200 / idempotent_replay
2. FIX-35-02  Verificación real de RNF-03 a 360 px
3. FIX-35-03  RestockerCashCollectionViewDTO o enmienda aprobada
4. FIX-35-04  Snapshot del revisor tras baja lógica
5. FIX-35-05  Aserción de CASH_COLLECTION_DELIVERED_TO_VAULT
6. FIX-35-06  Cobertura de usuario/máquina inactivos y bloqueo
7. FIX-35-07  Enumerar los 17 puntos QA o corregir la cifra
8. FIX-35-08  Alinear la matriz de trazabilidad
9. FIX-35-09  Batería global, new_engine y métricas finales
```

Los FIX 01–05 son los que bloquean la certificación literal. Los FIX 06–08 son necesarios para que la matriz no vuelva a certificar de más. El FIX 09 es la puerta final y no debe ejecutarse antes de los anteriores.

---

## Aclaraciones que no son defectos

- **`SchemaCorrespondenceTest.php`:** no existe con ese nombre. El fichero real es `tests/integration/OpportunisticSchemaCorrespondenceTest.php` y pasa **32/32**. No crear un duplicado solo para igualar el nombre solicitado; si se quiere una nomenclatura distinta, debe ser una decisión de mantenimiento.
- **`LegacyButtonPaletteRetirementTest.php`:** el test real es `tests/unit/LegacyButtonPaletteRetirementTest.mjs` y pasa **18/18**. No es un fallo que sea `.mjs`; el proyecto usa suites frontend en Node ESM.
- **`DesignTokenDebtRatchetTest.php`:** el test real es `tests/unit/DesignTokenDebtRatchetTest.mjs` y pasa **3/3**.
- **`CoordinatorButtonVariantsGuardTest.mjs`:** sí existe y pasa **23/23**.
- La cobertura funcional de las suites existentes es amplia; las brechas de este documento son de contrato, de evidencia o de trazabilidad, no un fallo masivo de implementación.

---

## Criterio de cierre de este backlog

La Spec 35 podrá volver a declararse cerrada cuando:

- [x] FIX-35-01 está implementado y el test de reenvío exige `200` + `idempotent_replay`.
- [x] FIX-35-02 tenga evidencia real de 360 px para las superficies de recaudación.
- [x] FIX-35-03 esté resuelto por DTO o por enmienda aprobada.
- [x] FIX-35-04 y FIX-35-05 tengan aserciones que fallen si se elimina la persistencia/auditoría.
- [x] FIX-35-06 cubra los casos de actividad y, si se acepta, el bloqueo pesimista.
- [ ] FIX-35-07 y FIX-35-08 hayan alineado spec, matriz, ODD y tareas.
- [ ] FIX-35-09 haya reejecutado la batería y actualizado las métricas.
- [ ] La matriz no cite artefactos inexistentes ni declare `RNF-03` sin evidencia de viewport.

Mientras cualquiera de los P0/P1 siga abierto, **la Spec 35 no está al 100 %**.

---

## Referencias

- [`specs/35-cash-collection-vault-audit/spec.md`](../specs/35-cash-collection-vault-audit/spec.md) · `RF-01.2.3`, `RF-02.1`, `RF-04.1`, `RNF-02.3`, `RNF-03`, `QA 1`, §6 y §8.
- [`specs/functional/cash_collection_vault_audit_spec.md`](../specs/functional/cash_collection_vault_audit_spec.md) · espejo idéntico.
- [`specs/technical/cash_collection_vault_audit_contracts.md`](../specs/technical/cash_collection_vault_audit_contracts.md) · contrato de la captura y la persistencia.
- [`specs/35-cash-collection-vault-audit/traceability-matrix.md`](../specs/35-cash-collection-vault-audit/traceability-matrix.md) · matriz actual.
- [`specs/35-cash-collection-vault-audit/tasks.md`](../specs/35-cash-collection-vault-audit/tasks.md) · 12/12 tareas.
- [`odd/tasks/cash-collection-vault-audit.md`](../odd/tasks/cash-collection-vault-audit.md) · ODD y logs.
- `src/Presentation/Controller/RestockerRouteController.php` · respuesta de la captura.
- `src/Application/Service/MachineCashCollectionService.php` · idempotencia, auditoría de entrega y arqueo.
- `src/Core/Domain/Model/MachineCashCollection.php` · proyecciones `toRestockerArray()`, `toCoordinatorArray()` y `toReceptionReceiptArray()`.
- `tests/integration/CashCollectionVaultAuditApiTest.php` · 30/30; contiene las aserciones de `201`/`false` y `200`/`true` con `idempotent_replay`.
- `tests/integration/CashCollectionVaultAuditConstitutionalTest.php` · 23/23; snapshots, inmutabilidad y auditoría parcial.
- `tests/frontend/restocker_route_view.test.mjs` · 209/209; superficie móvil, cola Offline y recibo.
- `tests/frontend/coordinator_product_loading_tab.test.mjs` · 80/80; 6 KPIs, filtros, arqueo y CSV.
- `tests/unit/CoordinatorButtonVariantsGuardTest.mjs` · 23/23; geometría y plantillas.
- `tests/unit/FrontendTemplateCompileTest.mjs` · 15/15; compilación de plantillas.
- `tests/unit/DesignTokenDebtRatchetTest.mjs` · 3/3; deuda de tokens.
- `docs/auditoria_spec_34_brechas.md` · precedente de formato de auditoría y priorización.
