# Propuesta de Enmienda de Especificación — Copy del Flujo de Consolidación Territorial

**Estado:** ✅ **Aprobada y aplicada el 2026-10-08** (protocolo `AGENTS.md` §2, Fase 2: Puerta de Aprobación superada).
Las tres decisiones de §5 quedaron resueltas por el responsable del proyecto y el contenido de esta propuesta se aplicó ese mismo día a las especificaciones y al código; el documento conserva su redacción original para trazabilidad.
**Fecha:** 2026-10-08 · **Módulo:** M4 (`specs/07-route-map/`) · **Rama:** `incident-comments`

**Requisitos afectados:** RF-MAP-09, RF-07.1, RF-07.3, EARS 5.5 (MVP) · **RNF afectados:** RNF-MAP-06 (consistencia con el sistema de diseño)
**Superficies afectadas:** `public/assets/js/views/CoordinatorDashboardView.js` (MODAL 1B, líneas 1615–1720) · `public/assets/js/api.js:551`

---

## 1. Hechos verificados (por qué hace falta la enmienda)

1. **El flujo es de consolidación, pero habla de primera asignación.** El botón del mapa territorial sólo aparece en sedes con trabajo sin asignar (`CoordinatorTerritorialMapTab.js:542-550`, predicado `isUnassignedSite`, alimentado por `has_unassigned` del backend en `CoordinatorRouteMapController.php:119-123`). Sin embargo, `handleTerritorialAssign` (`CoordinatorDashboardView.js:330-358`) incluye a propósito **todas** las incidencias no terminales de la sede —también las ya asignadas a otro técnico— para consolidar el edificio entero bajo un único responsable (comentario en líneas 342-344). Esa lista mixta es exactamente el caso que describe el EARS de multi-técnico de RF-MAP-09 (`specs/functional/route_map_spec.md:163`).
2. **El copy actual miente en ese caso.** El modal dice `Asignar Técnico a la Sede`, `<N> incidencia(s) pendiente(s)` y `Asignar <N> incidencia(s)`, cuando algunas de las incidencias listadas **no están pendientes de asignación**: ya tienen responsable activo y lo que ocurrirá es una **reasignación/consolidación**. El rótulo `pendiente(s)` también contradice el vocabulario del módulo: `pendiente` es el estado `REGISTERED`/`REOPENED`, no «cualquier incidencia activa».
3. **Precedente interno ya aprobado.** La pestaña de órdenes preventivas resolvió el mismo problema de vocabulario con un rótulo state-aware (`hasAssignedTechnician(order) ? '🔁 Reasignar' : '👤 Asignar'`, verificado por las aserciones 2.29–2.34 de `tests/unit/CoordinatorPreventiveComponentsTest.mjs`). Esta enmienda alinea el mapa territorial con esa convención.
4. **Conflicto normativo a resolver.** El EARS 5.5 del MVP (`specs/functional/mvp_functional_spec.md:88`) declara la reasignación «acción exclusiva de la ficha de detalle integral (RF-07.3)». El flujo del mapa reasigna por diseño. Hoy es una divergencia **no documentada** entre especificación e implementación; la enmienda debe dejar constancia explícita de la excepción.

### 1.b Defecto funcional detectado durante el análisis (no es un problema de copy)

El backend exige, para toda incidencia en `ASSIGNED`, `IN_PROGRESS` o `PENDING_PARTS`, un motivo de reasignación de ≥ 10 caracteres reales (`MIN_REASSIGNMENT_REASON_LENGTH = 10` en `CoordinatorController.php:50`; bloque de reasignación en `355-391`: `422 MISSING_REASSIGNMENT_REASON` / `REASSIGNMENT_REASON_TOO_SHORT`, y `TECHNICIAN_ALREADY_ASSIGNED` si el destino es el mismo técnico). El flujo masivo, en cambio, invoca la API con **cuatro argumentos** (`CoordinatorDashboardView.js:409`) y la firma exige cinco (`api.js:551`: `reassignmentReason`), por lo que **nunca envía `reassignment_reason`**. Consecuencia: en un lote mixto, las incidencias ya asignadas fallan una a una con 422 y el coordinador ve «Asignación parcial» en lugar de la consolidación.

* Alcance del defecto: sólo el lote mixto. Los lotes íntegramente sin asignar funcionan (asignación inicial no exige motivo).
* El modal de detalle sí pasa el quinto argumento (`CoordinatorIncidentDetailModal.js:771-777`).
* **Sin cobertura:** las fixtures de `CoordinatorDashboardViewTest.mjs` no contienen ningún lote mixto (SEDE-BCN-01 tiene 2 incidencias sin asignar; SEDE-BCN-03 ninguna), de modo que ni los grupos 6–7 de esa suite ni `CoordinatorRouteMapApiTest.php` podían detectarlo; además el doble de la API en la aserción 7.5 (línea 415) declara sólo cuatro parámetros y descarta el quinto, motivo por el que la suite no podía ver el motivo ausente. El 422 sí está cubierto a nivel HTTP en `tests/integration/AssignTechnicianEndpointTest.php`.
* **Naturaleza:** es incumplimiento de requisito **ya aprobado** (RF-MAP-09 «consolidar la visita en un único operario» + RF-07.3), no una funcionalidad nueva. Corregirlo no requiere enmienda de especificación; sí requiere que la enmienda fije **cómo** se pide el motivo en el modal masivo (decisión 2 de §5).

### 1.c Segundo hueco: la consolidación es inalcanzable en el caso que la motiva

Una sede con varios técnicos y **ninguna** incidencia sin asignar tiene `has_unassigned = false`, por lo que el botón «Asignar técnico» permanece oculto y el EARS de multi-técnico (línea 163) no se puede ejecutar desde la interfaz. Queda como **decisión 3** de §5 y no se incluye por defecto en esta enmienda para no mezclar copy con comportamiento.

---

## 2. Enmiendas propuestas (antes → después)

### 2.1 `specs/functional/route_map_spec.md` · RF-MAP-09 (§4.5)

**Después del EARS de multi-técnico (línea 163), insertar:**

> * **EARS Estado:** El flujo de asignación iniciado desde una sede del mapa DEBE operar en dos modos según la composición del lote: **asignación** cuando ninguna incidencia listada tenga responsable activo, y **consolidación/reasignación** cuando al menos una lo tenga (el coordinador unifica el edificio completo en un único técnico, EARS anterior). El modo activo DEBE ser visible en el título del diálogo, en el recuento de su entradilla, en el distintivo de cada fila ya asignada y en el rótulo del botón de confirmación; nunca DEBE rotularse «pendiente» una incidencia que ya tiene responsable activo.
> * **EARS Estado:** MIENTRAS el lote incluya incidencias ya asignadas, el sistema DEBE exigir un motivo de reasignación justificado (≥ 10 caracteres reales, RF-07.3) antes de confirmar, y DEBE impedir consolidar sobre el técnico que ya es responsable activo de una incidencia.
> * **EARS Excepción:** Si sólo algunas incidencias del lote pueden consolidarse (por estado obsoleto o rechazo del servidor), el sistema DEBE mantener el diálogo abierto, conservar las que fallaron con su motivo y reportar el resultado como consolidación parcial sin cerrar en falso.

> **Nota de diseño (refinamiento de UX):** la ampliación a lote mixto responde al EARS de multi-técnico ya aprobado. El rótulo base «Asignar» se conserva cuando el lote no contiene ninguna incidencia asignada, de modo que el coordinador percibe el cambio de naturaleza de la acción sin ruido adicional.

### 2.2 `specs/functional/mvp_functional_spec.md` · EARS 5.5

**Antes:**
> …quedando la reasignación como acción exclusiva de la ficha de detalle integral (RF-07.3).

**Después:**
> …quedando la reasignación como acción exclusiva de la ficha de detalle integral (RF-07.3) y del flujo de consolidación por sede del mapa territorial de Coordinación (RF-MAP-09), que exige motivo justificado y auditoría idénticos.

### 2.3 `specs/functional/incident_detail_modal_spec.md` · §7 Fuera de Alcance, punto 2

**Antes:**
> 2. **Acciones masivas o por lotes:** El modal gestiona exclusivamente el expediente seleccionado; las acciones sobre múltiples tickets se efectúan desde la tabla de triaje general.

**Después:**
> 2. **Acciones masivas o por lotes:** El modal gestiona exclusivamente el expediente seleccionado; las acciones sobre múltiples tickets se efectúan desde la tabla de triaje general y desde el flujo de consolidación por sede del mapa territorial (RF-MAP-09), nunca desde este modal.

### 2.4 `specs/07-route-map/plan.md`

* **§4.2 (línea 265), ampliar el bullet existente:** tras «…para facilitar la reasignación unificada en un solo clic», añadir: «Con lote mixto, el diálogo abierto desde la sede se rotula como reasignación/consolidación, exige motivo justificado (RF-07.3) y marca por fila las incidencias que ya tienen responsable activo.»
* **§7 (línea 364), fila `RF-MAP-09`:** añadir a la columna de interfaz `CoordinatorDashboardView.js` (MODAL 1B) y a la de pruebas `CoordinatorDashboardViewTest.mjs`.

### 2.5 `specs/07-route-map/tasks.md` · nueva Fase 7 (reapertura justificada)

Siguiendo el patrón de la Fase 6, con su bloque de justificación:

> ## Fase 7: Refinamiento de Copy del Flujo de Consolidación (Reapertura Justificada)
>
> > **Justificación de reapertura:** el flujo masivo se documentó como «asignación» pese a incluir por diseño incidencias ya asignadas; el EARS de multi-técnico de RF-MAP-09 (consolidar el edificio en un único operario) quedaba sin reflejo en la interfaz y, en lote mixto, sin poder completarse por falta del motivo de reasignación (RF-07.3). No es *feature creep*: es cumplimiento de requisitos ya aprobados.

* **T-MAP-23:** Predicado de composición del lote y copy state-aware del MODAL 1B · Requisitos RF-MAP-09, RNF-MAP-06 · Dependencias T-MAP-15 · *Hecho cuando:* el diálogo rotula asignación o consolidación según el lote, marca por fila las incidencias con responsable activo y `node tests/unit/CoordinatorDashboardViewTest.mjs` pasa al 100 % en verde incluyendo las aserciones nuevas del lote mixto.
* **T-MAP-24:** Motivo de reasignación obligatorio y paso del quinto argumento en el lote mixto · Requisitos RF-07.3, RF-MAP-09 · Dependencias T-MAP-23 · *Hecho cuando:* `submitBulkAssignment` envía `reassignment_reason` y bloquea la confirmación sin él cuando el lote es mixto, con `CoordinatorDashboardViewTest.mjs` y `AssignTechnicianEndpointTest.php` en verde.
* **T-MAP-25:** (opcional, sujeto a la decisión 3) Alcanzabilidad del flujo de consolidación en sedes multi-técnico sin trabajo sin asignar · Requisitos RF-MAP-09 · Dependencias T-MAP-23 · *Hecho cuando:* una sede multi-técnico ofrece el flujo de consolidación y `CoordinatorTerritorialMapTabTest.mjs` + `CoordinatorRouteMapApiTest.php` lo verifican.

### 2.6 `specs/technical/route_map_contracts.md` · §4.2.1 (línea 301)

Añadir una nota de interfaz: `has_unassigned` es la puerta del flujo y significa «existe al menos una tarea sin responsable», no «la sede está íntegramente sin asignar»; el cliente DEBE leerlo como tal al rotular el diálogo. **Sin cambios de contrato**: ni campos nuevos, ni endpoints nuevos, ni alteración de la respuesta.

### 2.7 `specs/technical/incident_status_permissions_contracts.md` · §3

Sólo si la decisión 1 opta por extraer el predicado a util compartido: documentar `hasAssignedIncident(incident)` (o el nombre que se acuerde) junto a `isPendingAssignment`, y añadir su consumidor `CoordinatorDashboardView.js`. Si el predicado se resuelve con `assigned_technician_id` inline, este apartado no se toca.

---

## 3. Copy propuesta (textos exactos)

| Elemento | Hoy | Lote sin asignaciones (sin cambio) | Lote mixto (propuesta) |
| :--- | :--- | :--- | :--- |
| Título del modal (1618) | `Asignar Técnico a la Sede` | `Asignar Técnico a la Sede` | `Reasignar / Consolidar Sede en un Único Técnico` |
| Subtítulo (1619) | `{sede} · N incidencia(s) pendiente(s)` | `{sede} · N incidencia(s) pendiente(s)` | `{sede} · N incidencia(s) · M ya asignada(s)` |
| Rótulo del lote (1627) | `Incidencias activas de {siteCode}` | (sin cambio) | `Incidencias activas de {siteCode}: se cambiará el responsable de las marcadas` |
| Fila de incidencia (1630) | solo nº, máquina y urgencia | (sin cambio) | chip `Ya asignada: {técnico}` en las filas con responsable activo |
| Nota del selector (1659) | `El mismo técnico quedará como único responsable activo de todas las incidencias listadas (Art. II…)` | (sin cambio) | Ídem + `Para las incidencias ya asignadas, el cambio de responsable exige motivo justificado (RF-07.3).` |
| Campo nuevo | — | — | `Motivo de la reasignación` (obligatorio, ≥ 10 caracteres reales) |
| Botón de confirmación (1709) | `Asignar N incidencia(s)` | (sin cambio) | `Reasignar N incidencia(s)` |
| Alerta de éxito (422) | `Sede X: N incidencia(s) asignada(s) correctamente.` | (sin cambio) | `Sede X: consolidada en {técnico} — N incidencia(s) reasignada(s) correctamente.` |
| Alerta parcial (426) | `Asignación parcial en X: N correcta(s), M con error.` | (sin cambio) | `Consolidación parcial en X: N correcta(s), M con error.` |

**Datos disponibles sin tocar el backend:** la lista de triaje ya expone `assigned_technician_id` y `assigned_technician: { id, name }` (`CoordinatorController.php:251-259`), de modo que el predicado del lote se resuelve con el identificador (siempre presente) y la etiqueta del chip con el nombre, con reserva `otro técnico` si llegara nulo.

Cumplimiento de diseño (RNF-MAP-06): el chip reutiliza radio 4px, tipografía 12px Inter y tokens `--color-hairline` / `--color-ink-muted`; no se introducen colores ni radios nuevos. Se propone **sin emoji** en el título para no romper la línea sobria del dashboard; el precedente preventivo usa `🔁`/`👤` y puede adoptarse si se prefiere la simetría total.

---

## 4. Contratos de prueba (Fase 3, tras aprobación)

* `tests/unit/CoordinatorDashboardViewTest.mjs` — nueva fixture de lote mixto (incidencia `ASIGNADA` con `assigned_technician_id: 2` en `SEDE-BCN-01`) y aserciones 7.6–7.12: título/subtítulo/rótulo/botón en modo consolidación; chip por fila; motivo obligatorio bloqueando la confirmación; quinto argumento con el motivo enviado a la API; alerta de consolidación; y **regresión**: un lote íntegramente sin asignar conserva `Asignar` y no exige motivo. Deben actualizarse a la vez las aserciones que cuentan el lote (6.10, línea 388, y 7.1, línea 400: `bulkAssignIncidents.length === 2`) y la que cuenta llamadas (7.5, línea 421: `bulkCalls.length === 2`), y el doble de la API de esa aserción debe declarar el quinto parámetro para poder verificar el motivo.
* `tests/unit/CoordinatorTerritorialMapTabTest.mjs` — sin cambios (la copy no vive ahí); la guarda 6.11 se mantiene.
* `tests/integration/CoordinatorRouteMapApiTest.php` — verificar que una sede mixta sigue devolviendo `has_unassigned: true` con su `is_multi_technician`, como base de la decisión 3.
* `php tests/run_all.php` — batería global verde y sin regresiones (Art. I).

---

## 5. Decisiones resueltas (aprobadas el 2026-10-08)

1. **Predicado y rotulación.** ¿Se adopta el título `Reasignar / Consolidar Sede en un Único Técnico` (o una variante: `Consolidar Sede en un Único Técnico`, `Reasignar Técnico de la Sede`) y el chip por fila? Alternativas: mantener un único título neutro (`Asignar / Reasignar Técnico a la Sede`) siempre, o adoptar los emojis del precedente preventivo.
2. **Motivo de reasignación en lote mixto.** ¿Se exige en el propio diálogo masivo (recomendado: un único motivo para todo el lote, coherente con la reclasificación de urgencia ya existente) o se remite al coordinador a la ficha de detalle para las filas ya asignadas? La primera opción cierra el defecto 1.b; la segunda exige retirar esas filas del lote.
3. **Alcance.** ¿Entra la decisión 3/T-MAP-25 (permitir consolidar una sede multi-técnico aunque no tenga trabajo sin asignar) en esta enmienda o se tramita aparte, como cambio de comportamiento con su propio gate?

**Resolución aprobada:**

1. **Rotulación:** se adopta la propuesta literal **sin emoji** (título `Reasignar / Consolidar Sede en un Único Técnico`, entradilla con `M ya asignada(s)`, rótulo del lote, chip por fila, botón `Reasignar N incidencia(s)` y alertas de consolidación).
2. **Motivo en lote mixto:** se exige en el propio diálogo masivo, con un único motivo para todo el lote (implementado en `17acf02` como cumplimiento de RF-07.3).
3. **Alcance:** `T-MAP-25` **entra** en esta enmienda: la consolidación queda alcanzable en sedes multi-técnico (Fase 7 de `specs/07-route-map/tasks.md`).

---

## 6. Lo que NO cambia

* Contrato HTTP: cero endpoints, campos o parámetros nuevos; `has_unassigned` conserva su semántica.
* Backend: ninguna regla de negocio nueva; el defecto 1.b se corrige en el cliente enviando el motivo que RF-07.3 ya exige.
* Privacidad y constitución: el flujo sigue siendo exclusivo del Coordinador; no se añade rastreo ni dato personal alguno (Art. V.4).

## 7. Reparto aplicado

`docs(map): amend the territorial consolidation copy` · `fix(map): state-aware copy for the consolidated site batch` · `feat(map): reachable consolidation for multi-technician sites` · `docs(prev): amend the preventive reassignment reason` · `feat(prev): mandatory reason to reassign a scheduled order`. Los hashes constan en el historial de la rama `incident-comments`.
