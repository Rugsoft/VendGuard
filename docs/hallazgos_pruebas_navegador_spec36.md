# Hallazgos · Pruebas de navegador de la Spec 36

- **Fecha:** 2026-11-20
- **Rama:** `feat/restocker-sat-synergy`
- **HEAD de la corrida original:** `9d72fea` · `fix(restocker): update the route empty state copy`
- **Árbol:** la corrida original no modificó código, esquema ni tests. La corrección posterior de `H-36-06` sí cambia el gateway, la suite de composición y esta acta; se registra en el commit de corrección, sin push.
- **Alcance:** recorrido manual en navegador de los flujos de la Spec 36 (`RF-01`–`RF-05`, `D1`–`D4`, `C1`–`C2`) con los cuatro perfiles: Responsable de Sede, Coordinación, Reponedor y Técnico SAT.
- **Entorno:** app local `http://127.0.0.1:8000` contra `vendguard_battery` (PostgreSQL local `127.0.0.1:54331`). Estado inicial: 0 tickets activos y 0 turnos.
- **Naturaleza:** acta de verificación funcional. La corrida original no aplicó correcciones y dejó los hallazgos abiertos; `H-36-06` se corrigió después y el resto sigue abierto.
- **Actualización (2026-11-20):** `H-36-06` corregido y cubierto por una prueba de composición HTTP/PostgreSQL. La evidencia original se conserva; los estados actualizados están en la tabla resumen y en la sección del hallazgo.
- **Relación con documentos previos:** complementa [`informe_verificacion_rama_restocker_sat_synergy.md`](informe_verificacion_rama_restocker_sat_synergy.md) y [`auditoria_spec_36_brechas.md`](auditoria_spec_36_brechas.md). Los cierres `V-01`–`V-03` y `FIX-36-01`–`FIX-36-08` siguen siendo válidos. La corrida de navegador abrió `H-36-06`–`H-36-10`; `H-36-06` ya está resuelto y `H-36-07`–`H-36-10` siguen abiertos.

> Los identificadores `H-36-06` a `H-36-10` son nuevos para no reutilizar los `H-36-01`–`H-36-05` ya cerrados en la auditoría. Las observaciones menores se numeran aparte como `O-36-01` y `O-36-02`.

---

## 1. Resumen ejecutivo

La mayor parte del recorrido funciona: la Sede crea avisos, Coordinación sugiere y despacha reponedores, el reponedor inicia y escala avisos L1, el técnico ve la nota interna y la foto, la coincidencia en sede funciona en la vista del técnico y los filtros por nivel de Coordinación responden.

El flujo **todavía no puede considerarse cerrado**: la corrida detectó un bloqueante, ya corregido, y quedan cuatro hallazgos P2:

1. **`H-36-06` (P1 · resuelto el 2026-11-20):** «Resolver en 1 toque» de Nivel 1 devolvía **500 siempre** porque los snapshots de taxonomía llegaban vacíos al `UPDATE`. El ticket quedaba `IN_PROGRESS`, no se sellaba `RESOLVED` y no se podía cerrar el turno por la vía normal. La corrección hidrata la pareja activa causa/solución dentro de la transacción y añade composición HTTP/PostgreSQL con aserciones sobre los cinco snapshots.
2. **`H-36-07` (P2):** la Sede no preclasifica el motivo L1. Un atasco leve nace en `L2_SAT` y, tras el despacho manual a `L1_RESTOCKER`, conserva `level1_issue_type = NULL`; la tarjeta del reponedor muestra el texto genérico «Aviso de Nivel 1».
3. **`H-36-08` (P2):** el modal de resolución preselecciona una causa incompatible con la categoría del ticket y no filtra el catálogo por categoría o motivo L1.
4. **`H-36-09` (P2):** el escalado que crea un ticket SAT nuevo desde una máquina sin aviso previo pierde el SLA de la sede: un centro `CRITICAL_INFRASTRUCTURE_24_7` recibe `STANDARD` / 24 h.
5. **`H-36-10` (P2):** la vista del Reponedor no pinta «🤝 Coincidencia en Sede» porque el payload de máquinas no incluye `location_id`, aunque la API sí devuelve las coincidencias por sede.

| ID | Prioridad | Hallazgo | Requisito afectado | Estado |
| :--- | :--- | :--- | :--- | :--- |
| `H-36-06` | **P1 · Bloqueante** | Resolución L1 devolvía 500 por snapshots de taxonomía incompletos | `RF-02`, `D3`, `C1` | **Resuelto (2026-11-20)** |
| `H-36-07` | P2 · Alto | La Sede no preclasifica `level1_issue_type`; el despacho L1 lo deja nulo | `RF-01`, `D1`, `D2` | Abierto |
| `H-36-08` | P2 · Alto | Causa raíz preseleccionada incompatible con la categoría | `RF-02`, `D3` | Abierto |
| `H-36-09` | P2 · Alto | El escalado a SAT desde máquina sin aviso previo pierde el SLA de la sede | `RF-03.2`, `D3` | Abierto |
| `H-36-10` | P2 · Medio | «Coincidencia en Sede» no se renderiza en la vista del Reponedor | `RF-04.1`, `D4` | Abierto |
| `O-36-01` | P3 · Menor | El banner de coincidencia del Técnico no aparece hasta refrescar | `D4` | Observación |
| `O-36-02` | P3 · Menor | Error genérico de resolución L1, sin detalle de validación | `RF-02` | Observación |

**Lectura de prioridades:** P1 impide completar un flujo principal o certificar el módulo; P2 deja un requisito funcional sin cumplir o produce un dato operativo incorrecto; P3 es deuda de usabilidad o de actualización de datos, sin bloqueo.

---

## 2. Metodología

1. Se levantó la app local con la base de batería y se comprobó `/api/health` → **200**.
2. Se creó un aviso real desde el Portal de Sede en VEND-0101 (Hospital del Mar), categoría «Atasco de producto en espiral», descripción y teléfono. `POST /api/incidents` → **201**.
3. Se comprobó que Coordinación no podía despachar a L1 sin reponedor en turno: aviso correcto y submit deshabilitado.
4. Se inició el turno de Lucía y se despachó el ticket a `L1_RESTOCKER`.
5. Se recorrió la bandeja L1, el bloqueo de cierre de turno, el intento de resolución, el escalado al SAT, la vista del técnico, la coincidencia en sede, el cierre de turno y los filtros/detalle de Coordinación.
6. Cada paso se contrastó con la respuesta de red, el DOM renderizado, el log del servidor y el estado en PostgreSQL.
7. Al terminar se detuvo el servidor y se eliminó el log temporal. No se hizo commit ni push.

### Tickets utilizados

| Ticket | ID | Máquina | Origen | Estado final de la corrida |
| :--- | ---: | :--- | :--- | :--- |
| `INC-2026-QW8V` | 5780 | VEND-0101 · Hospital del Mar | Portal de Sede; despacho manual a L1; escalado al SAT | `ASSIGNED` · `L2_SAT` · Marta |
| `INC-2026-DRJC` | 5781 | VEND-0102 · Hospital del Mar | Escalado desde tarjeta de máquina | `ASSIGNED` · `L2_SAT` · Marta |

---

## 3. Hallazgos

### H-36-06 · P1 · RESUELTO · RESOLUCIÓN L1 DEVOLVÍA 500

**Flujo:** el reponedor inicia un aviso L1 y pulsa «✓ Resolver en 1 toque».

#### Pasos reproducidos

1. Despachar `INC-2026-QW8V` a Lucía en `L1_RESTOCKER`. La API responde **200**, el ticket queda `ASSIGNED`.
2. Entrar como Lucía, pulsar «▶ Iniciar Nivel 1». La API responde **200**, el ticket queda `IN_PROGRESS`.
3. Pulsar «✓ Resolver en 1 toque».
4. Seleccionar la causa correcta para un `PRODUCT_JAM`:
   - Causa: «Envase mal posicionado o trabado leve en boca de espiral» (`RC-L1-SPIRAL-RELEASE`).
   - Solución: «Liberación manual de envase en espiral y reacomodo de carril» (`SS-L1-CLEAR-SPIRAL`).
5. Escribir diagnóstico y acción de más de 20 caracteres cada uno.
6. Confirmar la resolución.

#### Resultado observado

```text
POST /api/restocker/incidents/5780/resolve → 500 (Internal Server Error)
```

- El ticket permanece `IN_PROGRESS` en PostgreSQL.
- `root_cause_id`, `standard_solution_id` y `resolved_at` siguen en `NULL`.
- La interfaz muestra el mensaje genérico «⚠️ No se pudo completar la operación de Nivel 1.».
- El intento se repitió con la causa que viene preseleccionada por defecto y volvió a dar **500**: no es un problema de una causa concreta.

#### Evidencia del servidor

```text
[vendguard] Fallo en la operación de sinergia L1:
PDOException: SQLSTATE[23514]: Check violation:
new row for relation "incidents" violates check constraint "chk_incidents_taxonomy_coherence"
```

La restricción exige que, si se rellena `root_cause_id` y `standard_solution_id`, también se rellenen los cinco snapshots:

- `root_cause_code_snapshot`
- `root_cause_category_snapshot`
- `root_cause_name_snapshot`
- `standard_solution_code_snapshot`
- `standard_solution_name_snapshot`

#### Causa raíz

- [`src/Application/Service/RestockerSatSynergyService.php`](../src/Application/Service/RestockerSatSynergyService.php), método `resolveLevel1()`: valida textos y pasa al gateway solo `root_cause_id` y `standard_solution_id`, sin hidratar los snapshots de código, categoría y nombre.
- [`src/Infrastructure/Repository/PdoRestockerSatSynergyGateway.php`](../src/Infrastructure/Repository/PdoRestockerSatSynergyGateway.php), método `resolveLevel1()`: el `UPDATE` escribe los dos IDs y los cinco snapshots con `$resolution['root_cause_code'] ?? null`, `$resolution['root_cause_category'] ?? null`, etc. Al no venir en el array del servicio, todos quedan `NULL` y PostgreSQL rechaza la fila.
- La suite `PdoRestockerSatSynergyGatewayTest` sí pasa porque invoca el gateway directamente con los snapshots completos; la composición HTTP real no está cubierta por ese test.

#### Corrección aplicada

- [`PdoRestockerSatSynergyGateway::resolveLevel1()`](../src/Infrastructure/Repository/PdoRestockerSatSynergyGateway.php) exige `root_cause_id` y `standard_solution_id`, resuelve la pareja activa en `root_causes`/`standard_solutions` dentro de la misma transacción y escribe los cinco snapshots desde el catálogo. Los snapshots que pudiera enviar el cliente se ignoran.
- Una pareja ausente, inactiva o no vinculada devuelve **422** (`RESOLUTION_TAXONOMY_REQUIRED` o `INVALID_RESOLUTION_TAXONOMY`) antes de cerrar la custodia; ya no se delega el fallo a la restricción SQL.
- Se amplió [`RestockerSatSynergyApiTest`](../tests/integration/RestockerSatSynergyApiTest.php) con un escenario de composición HTTP/PostgreSQL que inicia y resuelve un L1 con taxonomía real, envía snapshots falseados por el cliente y verifica en PostgreSQL `status = RESOLVED`, los dos IDs y los cinco snapshots con los valores del catálogo. El mismo escenario comprueba que una pareja inexistente responde **422** sin cerrar la custodia.
- Se ajustó `PdoRestockerSatSynergyGatewayTest` para comprobar que el gateway sella el código real de la base y no el que recibe en el array.
- `RestockerRouteController::resolveLevel1()` traduce los errores del gateway a su estado HTTP real; antes cualquier rechazo del gateway se envolvía en un **200**.

**Evidencia:** `RestockerSatSynergyApiTest` **59/59**, `PdoRestockerSatSynergyGatewayTest` **24/24**, `RestockerSatSynergyConstitutionalTest` **17/17** y `RestockerSatSynergyServiceTest` **34/34**, todos con `exit=0`.

#### Impacto en la corrida original

- **Bloqueaba RF-02:** no se podía resolver un L1 ni cerrar la custodia por la vía normal.
- **Bloqueaba el cierre de turno:** el reponedor quedaba atrapado con un L1 `IN_PROGRESS`; el cierre respondía correctamente **409**, pero no había forma de resolverlo desde la UI.
- **Workaround usado en la corrida:** escalar el L1 al SAT desde la tarjeta. Eso desbloquea el cierre, pero no sustituye la resolución L1.

#### Recomendación original (atendida)

Hidratar la taxonomía completa antes de llamar al gateway (o hacer que el gateway la resuelva por ID dentro de la misma transacción) y añadir un test de composición HTTP/PostgreSQL que ejecute `resolve` con una causa y solución reales. El test debe fallar si algún snapshot queda en `NULL`.

---

### H-36-07 · P2 · LA SEDE NO PRECLASIFICA EL MOTIVO L1

**Flujo:** alta de aviso leve desde el Portal de Sede y posterior despacho a `L1_RESTOCKER`.

#### Pasos reproducidos

1. Crear un aviso en VEND-0101 con categoría «Atasco de producto en espiral» (`PRODUCT_JAM`), descripción y teléfono.
2. `POST /api/incidents` → **201**.
3. Consultar el ticket en PostgreSQL.
4. Despacharlo a `L1_RESTOCKER` desde Coordinación.
5. Consultar de nuevo el ticket y abrir la tarjeta del reponedor.

#### Resultado observado

Antes del despacho:

```json
{
  "ticket_code": "INC-2026-QW8V",
  "status": "REGISTERED",
  "dispatch_level": "L2_SAT",
  "category": "PRODUCT_JAM",
  "level1_issue_type": null
}
```

Después del despacho a L1:

```json
{
  "ticket_code": "INC-2026-QW8V",
  "status": "ASSIGNED",
  "dispatch_level": "L1_RESTOCKER",
  "level1_issue_type": null
}
```

La tarjeta del reponedor muestra:

```text
🛒 Aviso de Nivel 1 · VEND-0101 · Hospital del Mar - Edificio Central
```

No muestra el motivo concreto («Atasco leve en espiral») porque `level1_issue_type` es `NULL`.

#### Causa raíz

- El modal de alta de Sede ([`public/assets/js/components/IncidentReportModal.js`](../public/assets/js/components/IncidentReportModal.js)) no incluye ni envía `level1_issue_type`.
- El backend sí lo admite: [`src/Presentation/Controller/LocationPortalController.php`](../src/Presentation/Controller/LocationPortalController.php) lee `level1_issue_type` y llama a `classifyCreation()`, pero al no recibirlo no puede preclasificar.
- El despacho manual a `L1_RESTOCKER` en Coordinación tampoco fija el motivo L1; el `UPDATE` de `assignDispatchLevel()` cambia el nivel y el asignado, no `level1_issue_type`.

#### Impacto

- Se pierde la preclasificación determinista de avisos leves elegibles (`RF-01.1`/`RF-01.2`, `D1`/`D2`).
- Todo aviso creado desde Sede nace en `L2_SAT`; el despacho a L1 queda como una maniobra manual y el expediente llega al reponedor sin motivo.
- La resolución no puede preseleccionar la taxonomía correcta (ver `H-36-08`).
- La UI del reponedor pierde información útil para el 1-Tap.

#### Recomendación

- Añadir al alta de Sede un selector de motivo L1 solo para categorías elegibles, o derivar el motivo de forma determinista desde la categoría y la naturaleza del aviso.
- Persistir `level1_issue_type` al crear y, si el despacho manual a L1 no lo trae, exigirlo o calcularlo antes de cambiar el nivel.
- Cubrir con una prueba de composición que un alta de Sede `PRODUCT_JAM` elegible quede en `L1_RESTOCKER` con motivo no nulo.

---

### H-36-08 · P2 · CAUSA RAÍZ PRESELECCIONADA INCOMPATIBLE

**Flujo:** modal «✓ Resolver Nivel 1» para un ticket `PRODUCT_JAM`.

#### Resultado observado

El desplegable «Causa raíz» se abre con la primera causa del catálogo:

```text
Falta de vasos, azúcar o paletinas en dispensador
→ RC-L1-CONSUMABLES · categoría OTHER
```

Para un ticket `PRODUCT_JAM`, las causas coherentes son:

```text
RC-L1-STOCKOUT          → Agotamiento de producto en carril
RC-L1-SPIRAL-RELEASE    → Envase mal posicionado o trabado leve
```

El modal muestra las cuatro causas L1 sin filtrar por categoría ni por `level1_issue_type`. La solución estandarizada sí cambia al elegir una causa, pero la causa inicial no es válida para el ticket.

#### Causa raíz

- El modal construye el desplegable con el catálogo completo (`l1_taxonomy_catalog`) y no aplica ningún filtro por `category` o `level1_issue_type`.
- El backend no valida la coherencia de la pareja causa/categoría antes de escribir. `H-36-06` ya hidrata los snapshots, pero no añade esa validación: una pareja incoherente puede persistirse en lugar de recibir un **422**.

#### Impacto

- Un operario puede enviar la combinación por defecto sin advertirlo.
- La corrección de `H-36-06` evita el 500 de snapshots nulos, pero no corrige la preselección ni valida la categoría; el envío por defecto sigue siendo incoherente y debe rechazarse con un error de negocio claro.
- La corrección de `H-36-07` (motivo L1 no nulo) permitiría preseleccionar la causa correcta.

#### Recomendación

- Filtrar el catálogo por categoría/`level1_issue_type` y preseleccionar la causa por defecto coherente.
- Validar en aplicación la coherencia `root_cause` ↔ `category` y la pertenencia de la solución a la causa; devolver **422** con campo y mensaje, no un 500.
- Añadir un test que abra el modal con un ticket `PRODUCT_JAM` y compruebe que la causa inicial es `RC-L1-SPIRAL-RELEASE` o `RC-L1-STOCKOUT`, nunca `RC-L1-CONSUMABLES`.

---

### H-36-09 · P2 · EL ESCALADO A SAT DESDE MÁQUINA SIN AVISO PREVIO PIERDE EL SLA DE LA SEDE

**Flujo:** «⚡ Escalar Avería al SAT» desde la tarjeta de una máquina sin incidencia activa (rama que crea un ticket SAT nuevo).

#### Pasos reproducidos

1. Entrar como Lucía y abrir la tarjeta de VEND-0102 (Hospital del Mar, sin aviso activo).
2. Pulsar «⚡ Escalar Avería al SAT».
3. Categoría «Máquina apagada / Sin corriente» (`ELECTRICAL_OFF`).
4. Nota interna de más de 20 caracteres y foto PNG de prueba.
5. Confirmar. `POST /api/restocker/machines/2/escalate-to-sat` → **201**.

#### Resultado observado

Se creó `INC-2026-DRJC` (id 5781) con:

```json
{
  "status": "ASSIGNED",
  "dispatch_level": "L2_SAT",
  "category": "ELECTRICAL_OFF",
  "urgency": "CRITICAL",
  "sla_tier_snapshot": "STANDARD",
  "sla_target_minutes": 1440
}
```

El ticket hermano de la misma sede, `INC-2026-QW8V` (VEND-0101), conserva:

```json
{
  "sla_tier_snapshot": "CRITICAL_INFRASTRUCTURE_24_7",
  "sla_target_minutes": 240
}
```

La sede Hospital del Mar está sembrada con `sla_tier = CRITICAL_INFRASTRUCTURE_24_7`. El técnico ve el ticket como «Estándar · Objetivo 24 h» pese a que la urgencia es `CRITICAL` y la sede es crítica 24x7.

#### Causa raíz

- En [`PdoRestockerSatSynergyGateway::escalateBranchB()`](../src/Infrastructure/Repository/PdoRestockerSatSynergyGateway.php), el `INSERT` del ticket nuevo fija `sla_target_minutes` con `COALESCE(:sla_minutes, 1440)` y no copia `sla_tier_snapshot` de la sede.
- El resultado usa el valor por defecto del esquema para el perfil SLA (`STANDARD`) en lugar del perfil de la ubicación.
- La rama que escala un L1 existente conserva el SLA del ticket original; la inconsistencia aparece solo al crear un ticket SAT nuevo desde una máquina sin aviso.

#### Impacto

- El ticket se monitoriza con 24 h cuando la sede exige 4 h para urgencia crítica; puede incumplir el contrato SLA sin que el panel lo marque.
- Dos tickets de la misma sede muestran perfiles distintos: el nuevo, con urgencia `CRITICAL`, queda en `STANDARD` / 24 h, mientras el hermano conserva `CRITICAL_INFRASTRUCTURE_24_7` / 240 min; eso rompe la trazabilidad del contrato.
- Afecta a `RF-03.2` y al cálculo de SLA del Módulo 30.

#### Recomendación

- Heredar `sla_tier_snapshot` de la ubicación y calcular `sla_target_minutes` con la matriz de urgencia del perfil, igual que el alta ordinaria.
- Añadir una prueba de composición que escale desde una máquina de sede `CRITICAL_INFRASTRUCTURE_24_7` con urgencia `CRITICAL` y exija 240 minutos.

---

### H-36-10 · P2 · «COINCIDENCIA EN SEDE» NO SE RENDERIZA EN LA VISTA DEL REPONEDOR

**Flujo:** coincidencia entre un reponedor y un técnico en turno en la misma sede.

#### Condiciones reproducidas

- Lucía (`RESTOCKER`) en turno, con un aviso L1 activo en VEND-0101 (Hospital del Mar).
- Marta (`TECHNICIAN`) en turno, con un ticket SAT activo en VEND-0102 (misma sede).

#### Resultado observado

La API devuelve correctamente la coincidencia:

```json
{
  "site_coincidence": {
    "1": [
      {
        "name": "Marta Técnica Ruta BCN",
        "operator_code": "OP-02",
        "role": "TECHNICIAN",
        "shift_status": "ON_SHIFT",
        "activity_summary": "1 avería(s) SAT activa(s) en esta sede"
      }
    ]
  }
}
```

Pero las máquinas del mismo payload **no incluyen `location_id`**:

```json
{
  "machines": [
    {
      "code": "VEND-0101",
      "location_name": "Hospital del Mar - Edificio Central",
      "site_code": "SEDE-BCN-01",
      "zone_id": 1
    }
  ]
}
```

La plantilla del reponedor busca la coincidencia con:

```js
siteCoincidenceForLocation(machine.location_id)
```

Al recibir `undefined`, no encuentra la entrada y el bloque `data-testid="restocker-site-coincidence"` no se renderiza. En el DOM no apareció ningún banner en las tarjetas de VEND-0101/VEND-0102.

#### Causa raíz

- El payload de `GET /api/restocker/my-route` publica `site_coincidence` indexado por `location_id`, pero el DTO de cada máquina no publica `location_id`.
- El componente usa `machine.location_id` como clave; la información existe en el servidor, pero no llega a la vista.

#### Impacto

- `D4` funciona en la vista del Técnico, pero no en una de las dos vistas que la spec exige.
- El reponedor no ve que el técnico está en la misma sede, aunque la API ya haya calculado la coincidencia.

#### Recomendación

- Incluir `location_id` en el DTO de máquina o indexar la coincidencia por `machine_id` para que el componente no dependa de un campo ausente.
- Añadir una prueba de componente que monte una máquina con `location_id` y una respuesta `site_coincidence` con una entrada, y exija el banner; y otra que falle si el DTO no trae la clave.

---

## 4. Observaciones menores

### O-36-01 · P3 · El banner de coincidencia del Técnico no aparece hasta refrescar

Con Marta ya en turno, la ruta cargada antes de iniciar el turno no mostraba «🤝 Coincidencia en Sede». Tras refrescar la página, el banner apareció correctamente con Lucía, su código `REP-01`, el resumen de actividad y el enlace `tel:`.

No se trata de un fallo de datos: la API devuelve la coincidencia al recargar. Es un problema de frescura de la vista tras `▶ Iniciar Turno`; si la ruta no se recarga ni se refresca periódicamente, el banner queda oculto hasta la siguiente carga.

**Recomendación:** recargar la ruta al iniciar turno o refrescar la coincidencia en el mismo ciclo que actualiza el estado del turno.

### O-36-02 · P3 · Error genérico al fallar la resolución L1

La UI muestra «⚠️ No se pudo completar la operación de Nivel 1.» sin indicar el campo, la causa ni el código HTTP. En el caso de `H-36-06`, el problema real es una violación de coherencia de taxonomía; el operario no tiene forma de saberlo.

**Recomendación:** mapear los errores de validación a mensajes de campo y conservar el detalle seguro del código de error; no exponer el mensaje SQL.

---

## 5. Flujos verificados correctamente

- Creación de aviso desde el Portal de Sede: **201**, ticket en `REGISTERED`.
- Bloqueo de despacho L1 sin reponedor en turno: aviso correcto y submit deshabilitado.
- Sugerencia y despacho L1 al `#1 RECOMENDADO` (Lucía): **200**, ticket `ASSIGNED` en `L1_RESTOCKER`.
- Inicio de intervención L1: **200**, ticket `IN_PROGRESS`.
- Resolución L1 con taxonomía real: **200**, ticket `RESOLVED`, custodia cerrada y cinco snapshots sellados desde el catálogo. Verificado en la prueba de composición HTTP/PostgreSQL, no en una nueva corrida de navegador.
- Cierre de turno con un L1 `IN_PROGRESS`: **409** con mensaje claro y ticket bloqueante.
- Validación cliente de nota interna corta: rechazo con mensaje de mínimo 20 caracteres.
- Validación cliente de foto obligatoria: rechazo si no se adjunta imagen.
- Escalado desde tarjeta de máquina: **201**, ticket nuevo, asignación automática a la mejor técnica disponible, nota interna y foto persistidas.
- Vista del Técnico: insignia «⚡ Escalado por Reponedor In Situ», nota interna confidencial, foto y banner de coincidencia tras refrescar.
- Escalado de un L1 existente al SAT: **201**, ticket pasa a `L2_SAT`, se cierra la custodia del reponedor y se desbloquea el cierre de turno (**200**, `OFF_SHIFT`).
- Coordinación: filtros `Todos / L1_RESTOCKER / L2_SAT` con opciones correctas; L1 vacío y L2 con dos filas; etiqueta de nivel `🔧 SAT N2`; cadena de custodia con tramos de Lucía y Marta, cierre de custodia y nota de escalado.

---

## 6. Estado de la rama y datos de prueba

- **Código:** la corrida original no cambió código. La corrección posterior de `H-36-06` modifica el gateway, la suite de composición y esta acta; se registra en el commit de corrección, sin push.
- **Servidor:** detenido al terminar la corrida; `.tmp_server.log` eliminado.
- **Base:** `vendguard_battery` conserva los datos de prueba de la corrida:
  - `INC-2026-QW8V` (id 5780), VEND-0101, `ASSIGNED`, `L2_SAT`, Marta.
  - `INC-2026-DRJC` (id 5781), VEND-0102, `ASSIGNED`, `L2_SAT`, Marta, nota interna y foto.
  - Turnos del día de Lucía (`OFF_SHIFT`) y Marta (`ON_SHIFT`).
- No se borraron estos datos para no alterar la base sin una petición explícita.

---

## 7. Limitaciones de la verificación

- **La corrida original no pudo validar la resolución exitosa de L1:** `H-36-06` lo impedía. La corrección posterior lo cubre con `RestockerSatSynergyApiTest` sobre router y PostgreSQL reales (estado `RESOLVED`, snapshots y custodia), pero no se repitió el recorrido manual de navegador.
- **El diálogo nativo de archivos no se abrió** en la automatización. La imagen PNG de prueba se adjuntó directamente al input del navegador; sí se verificaron el handler `change`, la subida (`201`), el nombre del archivo, la nota interna y la persistencia.
- No se probó el límite real de 5 MB con un archivo grande; solo la validación de foto obligatoria y la nota mínima.
- No se hizo una auditoría de accesibilidad ni una revisión visual con capturas; la verificación fue funcional sobre DOM, red, log y base de datos.

---

## 8. Reproducción

```bash
# Servidor local con la base de batería
set -a
source deploy/local/postgres/.env.battery
set +a
php -S 127.0.0.1:8000 -t public
```

Recorrido mínimo para reproducir `H-36-06`:

1. Crear un aviso `PRODUCT_JAM` desde Sede en una máquina de la zona de Lucía.
2. Iniciar turno como Lucía.
3. Despachar el aviso a `L1_RESTOCKER` desde Coordinación.
4. Iniciar la intervención L1.
5. Abrir «✓ Resolver en 1 toque», elegir `RC-L1-SPIRAL-RELEASE` y `SS-L1-CLEAR-SPIRAL`, escribir diagnóstico/acción de 20+ caracteres y confirmar.
6. Antes de la corrección se observaba `POST /api/restocker/incidents/{id}/resolve` → **500** y el ticket en `IN_PROGRESS`. Con la corrección, la misma petición responde **200**, el ticket queda `RESOLVED` y los cinco snapshots se hidratan desde el catálogo.

Comprobación de estado:

```sql
SELECT ticket_code, status, dispatch_level, level1_issue_type,
       root_cause_id, standard_solution_id, resolved_at
  FROM incidents
 WHERE ticket_code = 'INC-2026-QW8V';
```

---

## 9. Criterio de cierre de esta acta

| Hallazgo | ¿Bloquea el cierre de la Spec 36? | Acción mínima |
| :--- | :--- | :--- |
| `H-36-06` | **No · resuelto el 2026-11-20** | Hidratación y composición HTTP/PostgreSQL cubiertas; queda la validación de categoría en `H-36-08` |
| `H-36-07` | Sí, para RF-01 | Preclasificar el motivo L1 y persistirlo en alta/despacho |
| `H-36-08` | No por separado, pero agrava `H-36-06` | Filtrar catálogo y validar coherencia con 422 |
| `H-36-09` | Sí, para el contrato SLA | Heredar el perfil SLA de la sede en la rama de ticket nuevo |
| `H-36-10` | Sí, para D4 | Publicar `location_id` o indexar por máquina |
| `O-36-01` | No | Refrescar coincidencia al iniciar turno |
| `O-36-02` | No | Mensajes de error de validación útiles |

**Conclusión:** la Spec 36 no puede declararse cerrada con esta corrida. `H-36-06` ya no bloquea el flujo principal de resolución L1: la hidratación y la composición HTTP/PostgreSQL están cubiertas. Siguen abiertos `H-36-07`, `H-36-08`, `H-36-09` y `H-36-10`, que dejan requisitos funcionales, de coherencia de taxonomía y de SLA sin cumplir. Las correcciones deben acompañarse de pruebas de composición reales, no solo de dobles del gateway.
