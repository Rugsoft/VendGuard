# Hallazgos de la revisión de la PR #25 · Auditoría EVA-DTS `.dex`

| Campo | Valor |
| :--- | :--- |
| **Proyecto** | Gestor de Incidencias de Vending (*VendGuard*) |
| **PR** | [#25 · feat/eva-dts-dex-audit](https://github.com/Rugsoft/VendGuard/pull/25) |
| **Rama origen** | `feat/eva-dts-dex-audit` |
| **Rama destino** | `main` |
| **HEAD revisado** | `37e48081` (`docs(eva-dts-dex-audit): record PR25-09 layout verification`) |
| **Base** | `efd18fb8` (`origin/main`) |
| **Fecha de la revisión** | 2026-11-27 |
| **Estado de la PR** | `OPEN`, `MERGEABLE`, `mergeStateStatus = CLEAN`; **no fusionada** |
| **Alcance** | Segunda pasada estática sobre `git diff origin/main...HEAD`: importación `.dex`, parser, proyecciones, vínculos M16/M34/M35/M36, consola de Coordinación, descarga `/raw`, CSV, cola Offline y pruebas del módulo |
| **Resultado** | **12 hallazgos**: 1 de prioridad alta, 5 de prioridad media, 4 de prioridad baja y 2 detalles menores · **8 cerrados** (`H25-01`, `H25-02`, `H25-03`, `H25-04`, `H25-05`, `H25-06`, `H25-07`, `H25-11`) y 4 abiertos |

> **Documento de trabajo.** Esta es una segunda revisión posterior al acta cerrada [`revision_pr_25_eva_dts_dex_audit.md`](revision_pr_25_eva_dts_dex_audit.md), que declaraba 9/9 hallazgos cerrados. El acta histórica no se modifica; este documento registra los huecos de la nueva pasada y el cierre de los que ya se han corregido.

> **Acta de cierre · `H25-01` (2026-11-27):** cerrado en local. Los DTO de alta y asunción aceptan `dex_audit_id` con `DexAuditLinker::parseOptionalId()`; `OpportunisticIncidentService` enlaza el volcado con el expediente resultante dentro de la misma transacción del caso de uso, de modo que un vínculo inválido deshace el alta o la asunción completa; y `TechnicianOpportunisticController` inyecta el enlazador, traduce `DexAuditValidationException` a su código de contrato y conserva el campo en `form_data`. Regresión: `DexAuditApiTest` 10.1–10.6 (73/73), con alta, asunción y reapertura en garantía enlazadas, y las suites M16 `T-OPP-08` y `T-OPP-09` en verde. La PR no se ha fusionado.

> **Acta de cierre · `H25-02` (2026-11-27):** cerrado en local. Con `dex_audit_id`, `RestockerSatSynergyService::escalateToSat()` ejecuta la validación, el gateway y el enlace dentro de una única unidad de trabajo (`TransactionManagerInterface`); el `FOR UPDATE` del volcado se retiene hasta el commit y el `begin()/commit()` interno del gateway pasa a ser reentrante, de modo que un enlace rechazado deshace el ticket SAT completo. `RestockerRouteController` inyecta `PdoTransactionManager` y traduce `DexAuditValidationException` a su código de contrato. Regresión: `DexAuditApiTest` 11.1 (74/74), `DexAuditServiceIntegrationsTest` 19/19, `RestockerSatSynergyServiceTest` 40/40 y `RestockerSatSynergyApiTest` 98/98. La PR no se ha fusionado.

> **Acta de cierre · `H25-03` (2026-11-28):** cerrado en local. `MachineDexAuditRepositoryInterface::findEffectivePredecessorsForAudits()` resuelve en una sola consulta, para toda la página, si el predecesor persistido está `VOIDED` y cuál es el último `ACTIVE` anterior; `MachineDexAuditService` proyecta ese bloque en el listado y en el CSV con el mismo cálculo que el detalle, y `CoordinatorDexAuditsSubTab` usa el valor efectivo aunque sea `null` cuando la bandera está activa, en vez de caer al delta persistido. Regresión: `DexAuditApiTest` 7.7, 7.8, 7.12 y 7.13 (78/78), frontend 1.11 (38/38), `MachineDexAuditServiceTest` 52/52 y `DexAuditServiceIntegrationsTest` 19/19. La PR no se ha fusionado.

> **Acta de cierre · `H25-11` (2026-11-28):** cerrado en local. `exportCsv()` deja de pasar `delta_vend_units` por `decimal()` y lo emite con `unitCell()`, que devuelve el entero sin decimales y la celda vacía cuando no hay valor; la rama efectiva de `H25-03` usa el mismo formateador. Regresión: `DexAuditApiTest` 5.5 (las columnas de unidades de `VA1` y delta solo tienen dígitos), 7.8 (delta efectivo `12`, no `12.00`) y 7.13 (nulo vacío), 79/79; `MachineDexAuditServiceTest` 53/53. La PR no se ha fusionado.

> **Acta de cierre · `H25-04` (2026-11-28):** cerrado en local. `EvaDtsParser` expone `raw_payload_received` —el texto recibido, ya en UTF-8, sin normalizar— y `MachineDexAuditService` persiste ese texto en `raw_payload`; `sha256_hash` sigue calculándose sobre el canónico, así que la deduplicación y `QA-2` no cambian. `/raw` devuelve ahora el `.dex` tal como llegó, con CRLF, espacios finales y salto final, y `file_size_bytes` mantiene el tamaño recibido. Regresión: `DexAuditApiTest` 4.4 (80/80) y `EvaDtsParserAndCatalogTest` 1.3 bis (46/46), `DexAuditConstitutionalTest` en verde. La PR no se ha fusionado.

> **Acta de cierre · `H25-05` (2026-11-28):** cerrado en local. `importDexAtomically()` resuelve la mutación `(actor_id, client_mutation_id)` antes de bloquear la máquina, aplicar el perímetro o resolver vínculos; compara la huella canónica, mantiene los `409` de máquina distinta y contenido distinto, y devuelve el replay con el rol del snapshot. Regresión: `MachineDexAuditServiceTest` 1.10 bis (54/54) e `DexAuditApiTest` 2.5 bis (81/81). La PR no se ha fusionado.

> **Acta de cierre · `H25-06` (2026-11-28):** cerrado en local. Las tres rutas de importación responden `idempotent_replay ? 200 : 201`; el cuerpo con `idempotent_replay = true` y el expediente devuelto no cambian. Regresión: `DexAuditApiTest` 2.5 bis (Reponedor, con vínculos caducados), 3.6 bis (Técnico) y 3.6 ter (Coordinación), 83/83. La PR no se ha fusionado.

> **Acta de cierre · `H25-07` (2026-11-28):** cerrado en local. `EvaDtsClientPreview.js` exporta `readDexFileText()`, que lee `arrayBuffer()` y decodifica UTF-8 estricto con fallback `windows-1252`; los cuatro caminos de cliente —Coordinación, Reponedor, Técnico y pre-filtro Offline— dejan de usar `File.text()`. Regresión: `eva_dts_offline_queue` 1.10 bis (26/26), Coordinación 3.6 (39/39), Reponedor 269/269 y Técnico 25/25. La PR no se ha fusionado.

---

## 1. Veredicto ejecutivo

El núcleo del Módulo 39 sigue siendo sólido: el parser es determinista, la migración protege la inmutabilidad, las proyecciones móviles eliminan importes y la anulación es terminal. Los hallazgos de esta pasada se concentran en **integración entre módulos**, **idempotencia de los reintentos**, **lectura financiera después de una anulación** e **integridad del fichero descargado**.

Los ocho hallazgos cerrados hasta ahora son:

1. **P1 · M16 no persiste el vínculo `.dex`** —**cerrado** (`H25-01`)—: el modal del Técnico enviaba `dex_audit_id` en el alta, la asunción y la reapertura, pero el backend lo ignoraba por completo.
2. **P2 · El ticket SAT podía quedar huérfano** —**cerrado** (`H25-02`)—: el enlace del `.dex` se ejecutaba después de que el gateway hubiera confirmado el ticket, fuera de su transacción.
3. **P2 · El listado y el CSV ignoraban el delta efectivo tras `VOIDED`** —**cerrado** (`H25-03`)—: la consola y la exportación mostraban el delta persistido contra un volcado anulado.
4. **P4 · El CSV formateaba las unidades como decimales** —**cerrado** (`H25-11`)—: la exportación publicaba `delta_vend_units` como `5.00`.
5. **P2 · `/raw` servía la versión normalizada** —**cerrado** (`H25-04`)—: el `.dex` descargado no era el que entró por la API y su huella canónica no coincidía con el fichero.
6. **P2 · El replay idempotente se resolvía demasiado tarde** —**cerrado** (`H25-05`)—: un reintento de una importación ya confirmada podía fallar si la máquina había cambiado de estado o un vínculo había caducado.
7. **P3 · El replay de importación respondía `201`** —**cerrado** (`H25-06`)—: contradecía el contrato documentado y la convención `200/201` de M35.
8. **P2 · Los `.dex` Windows-1252 se corrompían en el cliente** —**cerrado** (`H25-07`)—: `File.text()` sustituía los bytes no UTF-8 por U+FFFD antes de enviar el payload.

Ninguno estaba cubierto por las suites previas: la prueba frontend de M16 solo comprobaba el payload, la API solo validaba M34/M35/M36, el detalle era el único que recalculaba el delta, el CSV aplicaba formato monetario a los contadores, `/raw` solo se probaba con un payload ya canónico, el replay se probaba sin cambios de perímetro ni de vínculos, el estado HTTP del replay no se comprobaba y el cliente leía siempre como UTF-8. Cada uno tiene ahora su regresión propia.

---

## 2. Resumen ordenado por importancia

| # | ID | Prioridad | Hallazgo | Impacto | Estado |
|---:|:---:|:---:|---|---|---|
| 1 | `H25-01` | **P1 · Alta** | M16 no persiste `machine_dex_audits.incident_id` | El alta/asunción/reabertura desde el aviso térmico queda sin vínculo `.dex` | ✅ Cerrado |
| 2 | `H25-02` | **P2 · Media-alta** | Ticket SAT creado antes de enlazar el `.dex` | Un fallo de enlace deja un ticket huérfano y permite duplicados | ✅ Cerrado |
| 3 | `H25-03` | **P2 · Media-alta** | El listado y el CSV no calculan el delta efectivo tras `VOIDED` | La consola financiera muestra el delta persistido, no el vigente | ✅ Cerrado |
| 4 | `H25-04` | **P2 · Media** | `/raw` devuelve el payload normalizado, no el fichero original | La descarga no es byte a byte el `.dex` subido y el hash no coincide | ✅ Cerrado |
| 5 | `H25-05` | **P2 · Media** | El replay idempotente se comprueba después del perímetro y los vínculos | Un reintento puede fallar aunque la importación ya estuviera confirmada | ✅ Cerrado |
| 6 | `H25-06` | **P3 · Media-baja** | El replay de importación responde `201` en vez de `200` | Contradice el contrato documentado y la convención de M35 | ✅ Cerrado |
| 7 | `H25-07` | **P2 · Media** | Los `.dex` Windows-1252 se corrompen en el cliente | `File.text()` pierde los bytes no UTF-8 antes de enviar el payload | ✅ Cerrado |
| 8 | `H25-08` | **P3 · Baja** | La carga manual conserva el `.dex` válido anterior tras un error | Se puede importar el fichero antiguo después de seleccionar uno inválido | Abierto |
| 9 | `H25-09` | **P3 · Baja** | `expected_machine_code` es un control muerto en la consola | La UI sugiere una validación que el servidor ignora por diseño | Abierto |
| 10 | `H25-10` | **P3 · Baja** | Carrera al abrir el detalle de una fila | Una respuesta antigua puede sobrescribir el detalle seleccionado | Abierto |
| 11 | `H25-11` | **P4 · Menor** | El CSV formatea las unidades como decimales | `delta_vend_units` sale como `5.00` en vez de `5` | ✅ Cerrado |
| 12 | `H25-12` | **P4 · Menor** | Código de error incorrecto para longitudes de texto | Un código inválido se reporta como `DEX_INVALID_NUMERIC_BOUNDS` | Abierto |

---

## 3. Hallazgos detallados

### H25-01 · P1 · M16 no persiste el vínculo `.dex`

**Estado:** ✅ **Cerrado (2026-11-27)** · DTO, servicio y controlador cableados; el enlace vive dentro de la transacción del caso de uso y tiene regresión HTTP propia.

**Evidencia**

- El modal del Técnico envía el campo en los tres flujos: alta, asunción y reapertura.
  - `public/assets/js/components/TechnicianOpportunisticModal.js:106`
  - `public/assets/js/components/TechnicianOpportunisticModal.js:463-464`
  - `public/assets/js/components/TechnicianOpportunisticModal.js:486-487`
  - `public/assets/js/components/TechnicianOpportunisticModal.js:500-501`
- El DTO de alta no declara `dexAuditId`:
  - `src/Application/DTO/CreateOpportunisticIncidentDto.php:42-62`
  - `src/Application/DTO/CreateOpportunisticIncidentDto.php:96-157`
- El DTO de asunción tampoco lo declara:
  - `src/Application/DTO/AssumeOpportunisticIncidentDto.php:40-70`
  - `src/Application/DTO/AssumeOpportunisticIncidentDto.php:82-...`
- El servicio crea y asume la incidencia sin recibir ni usar `DexAuditLinker`:
  - `src/Application/Service/OpportunisticIncidentService.php:224-232`
  - `src/Application/Service/OpportunisticIncidentService.php:446-453`
- El controlador pasa el cuerpo crudo, pero no extrae el campo:
  - `src/Presentation/Controller/TechnicianOpportunisticController.php:184-209`
  - `src/Presentation/Controller/TechnicianOpportunisticController.php:260-...`
- El único `linkToIncident()` de producción es el de M36:
  - `src/Application/Service/DexAuditLinker.php:190-223`
  - `src/Application/Service/RestockerSatSynergyService.php:281`

**Escenario**

1. El Técnico abre una incidencia desde el banner térmico del `.dex`, o asume una incidencia existente.
2. El modal envía `machine_id`, categoría, descripción y `dex_audit_id`.
3. `CreateOpportunisticIncidentDto::fromPayload()` o `AssumeOpportunisticIncidentDto::fromPayload()` descarta el campo desconocido.
4. La incidencia se crea o se asume correctamente, pero la fila de `machine_dex_audits` conserva `incident_id = NULL`.

**Impacto**

El flujo «un toque» de M16 no deja trazabilidad hacia el volcado que lo motivó. Coordinación no puede ver desde el `.dex` qué incidencia se abrió, y el requisito de vínculo M16 queda funcionalmente incompleto aunque la UI lo anuncie.

**Cobertura actual**

- El test frontend solo comprueba que el payload incluye `dex_audit_id`:
  - `tests/frontend/technician_route_view.test.mjs:288-290`
- La prueba HTTP solo cubre M34/M35/M36:
  - `tests/integration/DexAuditApiTest.php:1104`
  - `tests/integration/DexAuditApiTest.php:1127`
  - `tests/integration/DexAuditApiTest.php:1150`
- No hay una prueba de que M16 escriba `machine_dex_audits.incident_id`.

**Corrección propuesta**

1. Añadir `?int $dexAuditId` a los DTO de alta y asunción, con `DexAuditLinker::parseOptionalId()`.
2. Inyectar `DexAuditLinker` en `OpportunisticIncidentService` y validar el volcado antes de crear o asumir la incidencia.
3. Enlazar dentro de la misma transacción que crea/asume/reabre la incidencia, usando el `id` resultante.
4. Añadir pruebas HTTP para M16 create, assume y reopen, incluyendo auditoría anulada, de otra máquina y ya vinculada.

**Corrección aplicada**

1. `CreateOpportunisticIncidentDto` y `AssumeOpportunisticIncidentDto` declaran `dexAuditId` y lo resuelven en `fromPayload()` con `DexAuditLinker::parseOptionalId()`: ausente o vacío es `null`; cualquier otro valor debe ser un entero positivo y se rechaza con `422 DEX_AUDIT_ID_INVALID`.
2. `OpportunisticIncidentService` recibe un `?DexAuditLinker` opcional y, tras crear o asumir el expediente, llama a `linkToIncident()` con el `id` resultante —también el del sucesor crónico— usando el actor del técnico. La llamada ocurre dentro de `runInTransaction()`, de modo que `DEX_AUDIT_NOT_FOUND`, `DEX_AUDIT_MACHINE_MISMATCH`, `DEX_AUDIT_VOIDED` o `DEX_AUDIT_ALREADY_LINKED` revierten la incidencia completa en lugar de dejarla escrita sin trazabilidad.
3. `TechnicianOpportunisticController` inyecta el enlazador con `PdoMachineDexAuditRepository` y el `AuditLogger` del controlador, traduce `DexAuditValidationException` a su código y estado de contrato y conserva `dex_audit_id` en `form_data` cuando falla la fotografía. La respuesta de alta y asunción publica `dex_audit_id`.
4. La regresión HTTP 10.1–10.6 de `DexAuditApiTest` certifica el alta con vínculo, la asunción con vínculo, la reapertura en garantía con vínculo, el rechazo de un volcado anulado y el de un volcado de otra máquina, incluida la ausencia de expediente tras el rechazo. La reapertura 10.6 parte de un expediente `RESOLVED` dentro de las 48 h y exige que el `incident_id` enlazado sea el del propio expediente reabierto, con su `DEX_AUDIT_LINKED` firmado por el técnico.

**Verificación**

```text
php tests/integration/DexAuditApiTest.php                          → 73/73 aserciones · exit 0
php tests/integration/OpportunisticIncidentServiceTest.php         → 100% en verde · exit 0
php tests/integration/OpportunisticIncidentApiIntegrationTest.php  → 56/56 aserciones · exit 0
```

La regresión 10.6 cubre la reapertura en garantía; la sustitución por Avería Crónica en la 3.ª reincidencia comparte el mismo enlace al expediente sucesor, pero no se reproduce en esta suite.

---

### H25-02 · P2 · Ticket SAT huérfano si falla el enlace posterior

**Estado:** ✅ **Cerrado (2026-11-27)** · validación, ticket y enlace en una sola unidad de trabajo; el rechazo del vínculo revierte el expediente SAT íntegro.

**Evidencia**

- La validación del `.dex` se ejecuta antes de crear el ticket:
  - `src/Application/Service/RestockerSatSynergyService.php:229-236`
- El gateway confirma el ticket:
  - `src/Application/Service/RestockerSatSynergyService.php:277`
- El enlace se ejecuta después, fuera de esa transacción:
  - `src/Application/Service/RestockerSatSynergyService.php:281-282`

**Escenario**

1. El Reponedor escala A al SAT con `dex_audit_id = A`.
2. `assertCompatible()` confirma que A existe, es de la máquina y está `ACTIVE`.
3. El gateway crea y confirma el ticket SAT.
4. Antes del enlace, otra operación anula A o la vincula a otra incidencia.
5. `linkToIncident()` lanza `409 DEX_AUDIT_VOIDED` o `409 DEX_AUDIT_ALREADY_LINKED`.
6. El endpoint devuelve error, pero el ticket SAT ya existe sin vínculo.

**Impacto**

Se crea un ticket SAT sin trazabilidad `.dex`. Un reintento del usuario puede generar un segundo ticket, y la notificación al técnico puede quedar asociada a un expediente que el cliente cree fallido.

**Cobertura actual**

El test unitario 4.3 solo comprueba que una auditoría inexistente se rechaza **antes** de crear el ticket:

- `tests/unit/DexAuditServiceIntegrationsTest.php:533-535`

No hay prueba de fallo de enlace después del commit ni de concurrencia entre la validación y el enlace.

**Corrección propuesta**

Enlazar dentro de la misma transacción del gateway o compensar el ticket si el enlace falla. Añadir una prueba con una auditoría que se anula o se vincula concurrentemente entre `assertCompatible()` y `linkToIncident()`.

**Corrección aplicada**

1. `RestockerSatSynergyService` recibe un `?TransactionManagerInterface` y, cuando el cuerpo declara `dex_audit_id`, ejecuta la revalidación del volcado, la llamada al gateway y el enlace dentro de la misma unidad de trabajo. La revalidación interna adquiere el `FOR UPDATE` de `machine_dex_audits` y lo retiene hasta el commit, de modo que una anulación concurrente ya no puede colarse en la ventana entre `assertCompatible()` y `linkToIncident()`.
2. El gateway ya era reentrante (`begin()` solo abre transacción si no existe y `commit()` solo confirma cuando es su dueño); con la unidad de trabajo exterior, su `commit()` deja de confirmar y el ticket solo existe si el enlace también se confirma. Un `DEX_AUDIT_VOIDED`, `DEX_AUDIT_ALREADY_LINKED` o cualquier otro rechazo revierte el expediente y el registro de la mutación offline.
3. `RestockerRouteController::buildSynergyService()` inyecta `PdoTransactionManager($pdo)` y `executeSynergy()` traduce `DexAuditValidationException` a su código y estado de contrato en lugar de devolver un `500` genérico.
4. La regresión 11.1 de `DexAuditApiTest` instala un disparador de prueba que anula el volcado justo durante el `INSERT` del expediente —la ventana exacta del hallazgo— y exige `409 DEX_AUDIT_VOIDED`, cero expedientes en la máquina y el volcado de nuevo `ACTIVE` sin vínculo tras el rollback. La unitaria 4.1 certifica que el escalado con vínculo pasa por la unidad de trabajo.

**Verificación**

```text
php tests/integration/DexAuditApiTest.php                    → 74/74 aserciones · exit 0
php tests/unit/DexAuditServiceIntegrationsTest.php           → 19/19 aserciones · exit 0
php tests/unit/RestockerSatSynergyServiceTest.php            → 40/40 aserciones · exit 0
php tests/integration/RestockerSatSynergyApiTest.php         → 98/98 aserciones · exit 0
```

`PdoRestockerSatSynergyGatewayTest` no se ejecutó en esta pasada porque exige su propio contrato `PG_TEST_DSN`, no disponible en el entorno usado; el gateway no se modificó.

---

### H25-03 · P2 · El listado y el CSV no muestran el delta efectivo tras `VOIDED`

**Estado:** ✅ **Cerrado (2026-11-28)** · El listado y el CSV resuelven en lote el delta vigente tras `VOIDED`; la UI respeta el valor efectivo nulo y hay regresión HTTP y frontend.

**Evidencia**

- `listForCoordinator()` devuelve las filas del repositorio sin aplicar `attachEffectiveDeltaAfterVoid()`:
  - `src/Application/Service/MachineDexAuditService.php:872-883`
- El cálculo efectivo solo se ejecuta en el detalle:
  - `src/Application/Service/MachineDexAuditService.php:899-900`
  - `src/Application/Service/MachineDexAuditService.php:923-986`
- El CSV usa `delta_sales_eur` persistido:
  - `src/Application/Service/MachineDexAuditService.php:646-670`
- La tabla de Coordinación intenta usar el campo efectivo, pero el listado no lo envía:
  - `public/assets/js/components/CoordinatorDexAuditsSubTab.js:227-230`
  - `public/assets/js/components/CoordinatorDexAuditsSubTab.js:678`
- El detalle sí lo recibe y lo muestra:
  - `public/assets/js/components/CoordinatorDexAuditsSubTab.js:748-749`

**Escenario**

1. Existen los volcados A → B.
2. Coordinación anula A.
3. El detalle de B recalcula el delta efectivo contra el último `ACTIVE` anterior, A0.
4. El listado y el CSV siguen mostrando el delta persistido de B contra A.
5. Si también se anula A0, la API devuelve `effective_delta_* = null` de forma explícita, pero la UI usa `??` y vuelve a mostrar el delta persistido obsoleto.

**Impacto**

La consola financiera y la exportación contradicen el detalle justo después de una anulación. El caso de uso principal de QA-2 —anular un volcado erróneo sin reescribir el histórico— produce una lectura incorrecta en el listado.

**Cobertura actual**

Las pruebas 7.1–7.9 de `DexAuditApiTest` validan el detalle, no el listado ni el CSV. La prueba frontend de `deltaUnits()` cubre el fallback, pero no la ausencia del campo efectivo en la respuesta del listado.

**Corrección propuesta**

1. Calcular el bloque efectivo para cada fila del listado, preferiblemente en lote para evitar N+1.
2. Exponer `previous_audit_voided` en el listado.
3. Usar el valor efectivo en CSV y en la tabla cuando la bandera indique que el predecesor fue anulado, incluso si el valor es `null`.
4. Añadir pruebas de listado y CSV después de anular el predecesor y después de anular toda la cadena.

**Corrección aplicada**

1. `MachineDexAuditRepositoryInterface` incorpora `findEffectivePredecessorsForAudits()`: en una sola consulta —`LEFT JOIN LATERAL`, con el orden `captured_at DESC, id DESC` y exclusión del propio volcado— devuelve, para toda la página, si el `previous_audit_id` persistido está `VOIDED` y cuál es el último `ACTIVE` anterior con sus totales `VA1`. Se elimina el N+1 que habría supuesto invocar `findPreviousActive()` fila a fila.
2. `MachineDexAuditService` aplica ese bloque en `listForCoordinator()` y en `exportCsv()` con el mismo `DexAuditValidator::resolveDeltas()` que el detalle, así que `previous_audit_voided`, `effective_previous_audit_id`, `effective_delta_sales_eur` y `effective_delta_vend_units` llegan a la tabla y al fichero. El CSV publica el delta efectivo y deja la celda vacía cuando ya no queda predecesor activo, en lugar de repetir el valor obsoleto. El detalle reutiliza la misma proyección (`applyEffectiveDelta()`), sin duplicar la semántica de `QA-2`.
3. `CoordinatorDexAuditsSubTab` decide con la bandera: si `previous_audit_voided` es `true` usa el valor efectivo aunque sea `null` (muestra `—`) y solo cae al persistido cuando el predecesor sigue vigente. La tabla añade la nota «predecesor anulado» bajo el delta para explicar el recálculo, y el modal de detalle aplica la misma regla.
4. Regresión: `DexAuditApiTest` 7.7 y 7.8 certifican listado y CSV con B recalculado contra A0 (12,00 € / 12 uds) tras anular A; 7.12 y 7.13 certifican que, anulada toda la cadena, el listado expone el bloque efectivo nulo y el CSV deja las celdas de delta vacías. La prueba frontend 1.11 cubre la precedencia de la bandera sobre el `??` en tabla y modal. La firma 8.4 del `audit_log` pasa a localizar la exportación del perímetro original, porque el escenario ahora exporta tres veces.

**Verificación**

```text
php tests/integration/DexAuditApiTest.php                    → 78/78 aserciones · exit 0
node tests/frontend/coordinator_dex_audits_tab.test.mjs      → 38/38 aserciones · exit 0
php tests/unit/MachineDexAuditServiceTest.php                → 52/52 aserciones · exit 0
php tests/unit/DexAuditServiceIntegrationsTest.php           → 19/19 aserciones · exit 0
```

El escenario de integración usa el contenedor PostgreSQL TLS habitual y deja limpio su esquema aislado; no hay DDL ni rutas nuevas.

---

### H25-04 · P2 · `/raw` devuelve la versión normalizada, no el original byte a byte

**Estado:** ✅ **Cerrado (2026-11-28)** · `raw_payload` conserva el texto recibido sin normalizar; `/raw` entrega el `.dex` tal como llegó y la huella canónica sigue separada.

**Evidencia**

- El parser devuelve el texto normalizado como `raw_payload_normalized`:
  - `src/Core/Service/EvaDtsParser.php:286-290`
- El servicio persiste ese texto en `raw_payload` y guarda el tamaño original por separado:
  - `src/Application/Service/MachineDexAuditService.php:354-356`
- El endpoint `/raw` devuelve el valor almacenado:
  - `src/Presentation/Controller/CoordinatorDexAuditController.php:130-139`
- El test 4.2 solo compara con un payload que ya está en formato canónico:
  - `tests/integration/DexAuditApiTest.php:697`
- El contrato funcional llama «crudo» al `raw_payload` y «original» a la descarga:
  - `specs/functional/eva_dts_dex_audit_spec.md:56`
  - `specs/functional/eva_dts_dex_audit_spec.md:249`

**Escenario**

Se sube un `.dex` de Windows con CRLF, espacios finales o caracteres Windows-1252. El parser normaliza saltos, recorta espacios y convierte a UTF-8. La descarga posterior devuelve esa versión canónica, no los bytes recibidos.

**Impacto**

La descarga no es byte a byte el fichero original y su SHA-256 no coincide con el del fichero fuente. La promesa de auditoría forense y de «conservar el original» queda debilitada. Si la normalización es deliberada para el hash canónico, el contrato y la UI deben dejar de llamar «original» a la descarga.

**Corrección propuesta**

Conservar los bytes originales —o una copia comprimida— para `/raw`, manteniendo el hash canónico separado, o documentar y etiquetar explícitamente que la descarga es la versión normalizada. Añadir una prueba con CRLF, espacios finales y Windows-1252.

**Corrección aplicada**

1. `EvaDtsParser` devuelve `raw_payload_received`: el texto ya decodificado a UTF-8 —la única conversión ineludible para una columna `TEXT`— pero sin normalizar saltos ni recortar espacios. `raw_payload_normalized` sigue siendo la base de `sha256_hash`, de modo que la deduplicación por `(machine_id, sha256_hash)` y la semántica de `QA-2` no cambian.
2. `MachineDexAuditService` persiste `raw_payload_received` en `machine_dex_audits.raw_payload` y mantiene `file_size_bytes` con el tamaño recibido. La descarga devuelve ahora el contenido íntegro que entró por la API —CRLF, espacios finales y salto final incluidos—, que es lo que prometen `C3` y el botón «Descargar `.dex` original».
3. Regresión: `DexAuditApiTest` 4.4 importa un `.dex` con CRLF, espacios finales y salto final, y exige que `/raw` devuelva exactamente ese texto y que `file_size_bytes` sea su longitud; la unitaria 1.3 bis certifica que `raw_payload_received` conserva el original mientras `raw_payload_normalized` sigue siendo el canónico.

La conversión de Windows-1252 a UTF-8 sigue siendo necesaria para almacenar en `TEXT`: la descarga reproduce el texto recibido ya en UTF-8, no los bytes de una codificación distinta. La pérdida de bytes en el cliente queda cubierta por `H25-07`.

**Verificación**

```text
php tests/integration/DexAuditApiTest.php                    → 80/80 aserciones · exit 0
php tests/unit/EvaDtsParserAndCatalogTest.php                → 46/46 aserciones · exit 0
php tests/integration/DexAuditConstitutionalTest.php         → 14/14 aserciones · exit 0
```

---

### H25-05 · P2 · El replay idempotente se comprueba demasiado tarde

**Estado:** ✅ **Cerrado (2026-11-28)** · El replay se resuelve antes de bloquear la máquina, aplicar el perímetro o tocar vínculos; la huella sigue verificándose.

**Evidencia**

En `importDexAtomically()` el orden actual es:

1. bloquear y validar máquina y actor: `src/Application/Service/MachineDexAuditService.php:213-243`
2. resolver y bloquear vínculos: `:252`
3. parsear el `.dex`: `:254`
4. buscar `client_mutation_id`: `:261`

**Escenario**

1. Una importación se confirma correctamente, pero la respuesta se pierde.
2. La cola Offline reintenta con la misma `client_mutation_id`.
3. Antes del reintento, la máquina se desactiva, el actor pierde cobertura o un vínculo M34/M35/M16 deja de ser elegible.
4. El servicio devuelve `404 MACHINE_NOT_FOUND`, `403 MACHINE_OUTSIDE_*` o `409 DEX_LINK_TARGET_NOT_ELIGIBLE` antes de llegar al replay.

**Impacto**

Un reintento legítimo de una operación ya persistida puede acabar como conflicto en la cola Offline, obligando a archivar un `.dex` que en realidad estaba importado. La idempotencia no cubre los cambios de perímetro ni de estado de los vínculos.

**Corrección propuesta**

Tras validar la identidad básica actor/máquina, buscar primero la mutación existente y comparar la huella. Solo si no existe, aplicar perímetro, resolver vínculos y parsear. Mantener la comprobación de que la mutación pertenece a la misma máquina.

**Corrección aplicada**

1. `MachineDexAuditService::importDexAtomically()` busca `(actor_id, client_mutation_id)` inmediatamente después de validar la solicitud —máquina, actor, fichero, captura y origen— y antes de `lockMachineContext()`, del perímetro, de `resolveLinkTargets()` y del parseo con el código de la máquina. Si la mutación existe, compara la huella canónica del payload recibido con la persistida y devuelve el replay con la proyección del volcado original.
2. Se conservan las guardas: una mutación de otra máquina sigue siendo `409 CLIENT_MUTATION_ID_ALREADY_USED`, y el mismo identificador con otro contenido también. El replay toma el rol del snapshot (`importer_role_snapshot`) para decidir el `prefill`, porque ya no depende del perímetro vigente.
3. Solo si la mutación no existe se bloquea la máquina, se aplican rol, perímetro y primacía sanitaria, se resuelven y bloquean los vínculos M34/M35/M16, se parsea con el código de la máquina y se comprueba la huella vigente (`DEX_DUPLICATE_HASH`).
4. Regresión: la unitaria 1.10 bis pone la máquina en cuarentena y envía un vínculo inexistente en el reintento, y exige `idempotent_replay`, el mismo `id`, un único alta y el `prefill` original; la HTTP 2.5 bis repite la mutación con visita y recaudación caducadas y exige el mismo expediente con `idempotent_replay = true`.

**Verificación**

```text
php tests/unit/MachineDexAuditServiceTest.php                → 54/54 aserciones · exit 0
php tests/integration/DexAuditApiTest.php                    → 81/81 aserciones · exit 0
```

El replay respondía `201` con `idempotent_replay = true` al cerrar este hallazgo; `H25-06` lo pasa después a `200`.

---

### H25-06 · P3 · El replay de importación responde `201` en vez de `200`

**Estado:** ✅ **Cerrado (2026-11-28)** · Las tres puertas de importación devuelven `200` en un replay idempotente y `201` en un alta nueva.

**Evidencia**

Los tres endpoints de importación responden siempre `201`:

- `src/Presentation/Controller/RestockerRouteController.php:659-662`
- `src/Presentation/Controller/TechnicianController.php:280-283`
- `src/Presentation/Controller/CoordinatorDexAuditController.php:88-93`

El acta de la PR documenta `200 idempotent_replay = true` para un reintento idéntico:

- `docs/revision_pr_25_eva_dts_dex_audit.md:146`

La ruta de recaudación M35 ya distingue correctamente `200/201`:

- `src/Presentation/Controller/RestockerRouteController.php:592`

**Impacto**

El cliente recibe un `201` con `idempotent_replay = true`, lo que contradice el contrato y la convención de M35. No rompe la importación, pero dificulta distinguir creación de replay y puede afectar a consumidores que usen el código HTTP como señal.

**Corrección propuesta**

Usar `idempotent_replay ? 200 : 201` en las tres rutas y añadir una prueba HTTP de replay exacto.

**Corrección aplicada**

1. `RestockerRouteController::importDexAudit()`, `TechnicianController::importDexAudit()` y `CoordinatorDexAuditController::import()` construyen primero el resultado del servicio y responden `($result['idempotent_replay'] ?? false) === true ? 200 : 201`, la misma convención que M35. El cuerpo, las cabeceras y la proyección por rol no cambian.
2. Regresión: `DexAuditApiTest` 2.5 bis pasa a exigir `200` en el replay del Reponedor con vínculos caducados; 3.6 bis y 3.6 ter cubren Técnico y Coordinación con un alta `201` seguida de un replay `200` del mismo expediente.

**Verificación**

```text
php tests/integration/DexAuditApiTest.php                    → 83/83 aserciones · exit 0
```

Sin cambios en el `audit_log`, en la base de datos ni en las rutas.

---

### H25-07 · P2 · Los `.dex` Windows-1252 se corrompen en el cliente

**Estado:** ✅ **Cerrado (2026-11-28)** · Los cuatro caminos de cliente leen los bytes y decodifican UTF-8 estricto con fallback `windows-1252`.

**Evidencia**

El parser servidor soporta Windows-1252:

- `src/Core/Service/EvaDtsParser.php:303-316`

Pero los caminos de cliente leen con `File.text()`, que decodifica siempre como UTF-8:

- `public/assets/js/components/CoordinatorDexAuditsSubTab.js:344`
- `public/assets/js/components/RestockerRouteView.js:3391`
- `public/assets/js/views/TechnicianRouteView.js:2272`
- `public/assets/js/utils/EvaDtsClientPreview.js:354`

**Escenario**

Un `.dex` con `ñ`, `€` o acentos codificados en Windows-1252 se lee como UTF-8. Los bytes no válidos se sustituyen por U+FFFD y el `raw_payload` enviado ya es UTF-8 válido. El servidor no puede detectar la codificación original ni recuperar los bytes perdidos.

**Impacto**

El parser servidor pierde la información que precisamente decía soportar. Afecta a la consola de Coordinación, al alta móvil y a la cola Offline; además, el hash y el tamaño calculados por el cliente pueden no corresponder al fichero real.

**Corrección propuesta**

Leer el fichero como `ArrayBuffer` y decodificar primero como UTF-8 estricto, con fallback explícito a `TextDecoder('windows-1252')`. Alternativamente, enviar el fichero como binario/multipart en lugar de texto JSON. Añadir una prueba con un `.dex` CP1252 que contenga acentos.

**Corrección aplicada**

1. `EvaDtsClientPreview.js` exporta `readDexFileText(file)`: lee `arrayBuffer()`, intenta `TextDecoder('utf-8', { fatal: true })` y, si el contenido no es UTF-8 válido, decodifica con `TextDecoder('windows-1252')`, la misma conversión que aplica el parser del servidor. No se sustituye ningún byte por U+FFFD antes de enviar.
2. Se reemplaza `File.text()` en los cuatro caminos: la carga manual de Coordinación, el alta móvil del Reponedor, la del Técnico y `previewDexFile()` (pre-filtro y cola Offline). El payload, el `original_filename` y el tamaño no cambian; solo cambia la lectura del fichero.
3. Regresión: la unitaria 1.10 bis decodifica un Blob CP1252 con `Café` y exige el texto exacto sin U+FFFD; la frontend 3.6 adjunta un `.dex` CP1252 en la consola de Coordinación y exige `Café` en `manualForm.raw_payload`. Las suites del Reponedor y del Técnico adaptan sus fixtures a `arrayBuffer()` y siguen en verde.

**Verificación**

```text
node tests/unit/eva_dts_offline_queue.test.mjs                 → 26/26 aserciones · exit 0
node tests/frontend/coordinator_dex_audits_tab.test.mjs        → 39/39 aserciones · exit 0
node tests/frontend/restocker_route_view.test.mjs              → 269/269 aserciones · exit 0
node tests/frontend/technician_route_view.test.mjs             → 25/25 aserciones · exit 0
```

Sin cambios en rutas, base de datos ni contrato HTTP; el Service Worker sigue precacheando el mismo módulo.

---

### H25-08 · P3 · La carga manual conserva el `.dex` válido anterior tras un error

**Evidencia**

- `handleManualFile()` limpia el error, pero no limpia `raw_payload` ni `original_filename` cuando la extensión, el tamaño o la lectura fallan:
  - `public/assets/js/components/CoordinatorDexAuditsSubTab.js:327-353`
- `submitManualImport()` solo comprueba que `raw_payload` no esté vacío:
  - `public/assets/js/components/CoordinatorDexAuditsSubTab.js:355-363`

**Escenario**

1. Se selecciona un `.dex` válido A.
2. Se selecciona un fichero inválido B.
3. La UI muestra el error de B, pero `manualForm.raw_payload` sigue conteniendo A.
4. Al pulsar «Importar», se envía y persiste A aunque el usuario crea que ha corregido o cambiado el fichero.

**Impacto**

Se importa un volcado distinto del que la pantalla parece estar validando. Es un error de confianza en una operación financiera irreversible salvo anulación lógica.

**Corrección propuesta**

Vaciar `raw_payload` y `original_filename` en cualquier error, resetear el input y bloquear el submit mientras `manualFileError` no esté vacío.

---

### H25-09 · P3 · `expected_machine_code` es un control muerto en la consola

**Evidencia**

- La UI muestra y envía el campo:
  - `public/assets/js/components/CoordinatorDexAuditsSubTab.js:375-377`
  - `public/assets/js/components/CoordinatorDexAuditsSubTab.js:630`
- `CoordinatorDexAuditController::importPayload()` solo copia `client_mutation_id`, `restock_visit_id`, `cash_collection_id` e `incident_id`:
  - `src/Presentation/Controller/CoordinatorDexAuditController.php:195-201`
- El servicio ignora deliberadamente el código esperado del cliente y usa `machines.code`:
  - `src/Application/Service/MachineDexAuditService.php:153`

**Impacto**

No es una regresión de seguridad: el servidor sigue comparando `ID1` contra la máquina resuelta. Pero la consola sugiere una validación que no existe y el usuario puede creer que un código esperado incorrecto bloqueará la importación.

**Corrección propuesta**

Retirar el campo o usarlo solo como comprobación local contra la máquina seleccionada, sin enviarlo como autoridad al servidor.

---

### H25-10 · P3 · Carrera al abrir el detalle de una fila

**Evidencia**

- `openDetail()` no usa secuencia, `AbortController` ni comprobación de identidad antes de asignar la respuesta:
  - `public/assets/js/components/CoordinatorDexAuditsSubTab.js:391-402`
- El listado sí usa `requestSequence` para descartar respuestas obsoletas:
  - `public/assets/js/components/CoordinatorDexAuditsSubTab.js:289-321`

**Escenario**

Se pulsa «Detalle» en la fila A y, antes de que responda, en la fila B. La respuesta de A llega después y sobrescribe `detail`, mientras `selectedAudit` apunta a B.

**Impacto**

El modal puede mostrar la cabecera de B con las líneas, eventos o cruces de A. Es un riesgo de lectura y de decisiones sobre el volcado equivocado.

**Corrección propuesta**

Usar una secuencia de petición o comparar `selectedAudit.id` con el `id` solicitado antes de asignar `detail`; opcionalmente, abortar la petición anterior.

---

### H25-11 · P4 · El CSV formatea las unidades como decimales

**Estado:** ✅ **Cerrado (2026-11-28)** · `exportCsv()` publica los contadores como enteros; el delta efectivo usa el mismo formateador y la celda nula queda vacía.

**Evidencia**

- `exportCsv()` pasa `delta_vend_units` por `decimal()`:
  - `src/Application/Service/MachineDexAuditService.php:670`
- `decimal()` siempre aplica dos decimales:
  - `src/Application/Service/MachineDexAuditService.php:1527-1530`

**Impacto**

La columna de unidades sale como `5.00` en vez de `5`. No altera el valor, pero dificulta el análisis y contradice la presentación entera del resto de la consola.

**Corrección propuesta**

Formatear `delta_vend_units` y cualquier contador de unidades como entero.

**Corrección aplicada**

1. `MachineDexAuditService::unitCell()` formatea contadores sin decimales ni separador de miles, y devuelve `''` cuando no hay valor.
2. `exportCsv()` usa `unitCell()` para `delta_vend_units` —también en la rama efectiva de `H25-03`—; `va1_total_vend_units` ya se emitía como entero y los importes mantienen `decimal()` con dos decimales.
3. Regresión: `DexAuditApiTest` 5.5 comprueba que las columnas de unidades de `VA1` y del delta solo contienen dígitos; 7.8 exige `12` (no `12.00`) en el delta efectivo y 7.13 exige la celda vacía cuando no hay predecesor activo. La unitaria 5.7 fija la fila completa con `;5.00;5;`.

**Verificación**

```text
php tests/integration/DexAuditApiTest.php                    → 79/79 aserciones · exit 0
php tests/unit/MachineDexAuditServiceTest.php                → 53/53 aserciones · exit 0
```

No cambian las cabeceras del CSV ni hay DDL nuevo.

---

### H25-12 · P4 · Código de error incorrecto para longitudes de texto

**Evidencia**

- `assertCodeLength()` lanza `DEX_INVALID_NUMERIC_BOUNDS` cuando un código de texto está vacío o supera la longitud:
  - `src/Core/Service/EvaDtsParser.php:559-567`

**Impacto**

El cliente recibe un código de error numérico para un problema de texto. No cambia la decisión del parser, pero complica el diagnóstico y las pruebas de contrato.

**Corrección propuesta**

Usar un código específico, por ejemplo `DEX_INVALID_TEXT_FIELD` o `DEX_INVALID_CODE`, manteniendo el campo y la línea en `details`.

---

## 4. Cobertura y límites de esta revisión

- **Revisión estática:** se ha contrastado el código de producción, la migración `035`, las proyecciones, las rutas, el frontend y las pruebas existentes contra `origin/main`.
- **No se ha vuelto a ejecutar la suite completa ni pruebas de navegador en esta pasada.** Los hallazgos son de lectura de código y de análisis de cobertura; no se presentan como fallos reproducidos en ejecución.
- **Cobertura engañosa detectada:** M16 solo está probado a nivel de payload frontend; el replay HTTP no está probado; el listado/CSV no tienen regresión de delta efectivo; y el endpoint `/raw` solo se prueba con un payload ya canónico.
- **No se ha modificado código, migraciones, pruebas ni el acta cerrada.** El árbol de trabajo solo incorpora este documento.
- **La PR sigue abierta y no fusionada.**

## 5. Referencias

- [Acta de la primera revisión](revision_pr_25_eva_dts_dex_audit.md)
- [Auditoría de brechas de la Spec 39](auditoria_spec_39_brechas.md)
- [Especificación funcional EVA-DTS](../specs/functional/eva_dts_dex_audit_spec.md)
- [Constitución del proyecto](../constitution.md)
- [PR #25](https://github.com/Rugsoft/VendGuard/pull/25)
