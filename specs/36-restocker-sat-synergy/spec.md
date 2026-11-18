# ESPECIFICACIÓN FUNCIONAL: SINERGIA OPERATIVA REPONEDOR ↔ TÉCNICO SAT (`LEVEL-1 RESTOCKER DISPATCH & IN-SITU SAT ESCALATION`)

**Proyecto:** Gestor de Incidencias de Vending (*VendGuard*)\
**Módulo:** 36 · Sinergia Operativa Reponedor ↔ Técnico SAT (*Resolución de Nivel 1 y Escalado Técnico In Situ*)\
**Documento Canónico:** `specs/36-restocker-sat-synergy/spec.md` (y su espejo `specs/functional/restocker_sat_synergy_spec.md`)\
**Versión:** 1.0 · Incorpora las 4 Decisiones de Diseño (`D1`–`D4`), las 2 Decisiones de Casos Límite e Integración (`C1`–`C2`) y las 9 Salvaguardas de Auditoría QA (`QA-AMB-01/02`, `QA-CON-01/02`, `QA-LIM-01..03`, `QA-CONST-01..03`) aprobadas por el Product Owner\
**Estado:** Especificación Funcional Aprobada por el Product Owner el 2026-11-18 · Puerta de Aprobación SDD superada (Fase 2)\
**Fecha:** Noviembre 2026\
**Origen:** Funcionalidad 26 del catálogo [`docs/features_pendientes.md`](../../docs/features_pendientes.md) (Bloque 4 · Operativa de Carga, Reposición de Producto y Gestión de Reponedores)\
**Alineación Constitucional:** Art. I (supremacía de la especificación SDD e inadmisibilidad de código simulado), Art. II (prioridad sanitaria innegociable: prohibición en dominio y motor SQL de clasificar o mantener en `L1_RESTOCKER` cualquier avería `TEMPERATURE_COLD` o `ELECTRICAL_OFF`, bloqueo de resolución `L1_RESTOCKER` en máquinas bajo `SANITARY_QUARANTINE` y auto-escalado inmediato a `L2_SAT` ante absorción QR térmica), Art. III.1, III.2 y III.3 (prohibición de borrado físico, trazabilidad inmutable de cada tramo de custodia en `incident_assignments` y auditoría en `audit_log`), Art. IV (minimalismo tecnológico: expediente único en `incidents` sin tablas redundantes, reutilización de `offline_sync_mutations` para idempotencia Offline y cero dependencias externas), Art. V.1 (diagnóstico y acción obligatorios de $\ge 20$ caracteres reales al resolver un Nivel 1 y nota interna de taller de $\ge 20$ caracteres reales al escalar al SAT), Art. V.2 y V.3 (unicidad de ticket activo por máquina `uq_machine_active_ticket`, custodio activo único en `assigned_technician_id` coherente con `dispatch_level`, y absorción idempotente si el reponedor escala sobre una máquina que ya tiene ticket `L2_SAT` abierto), Art. V.4 (opacidad estricta frente al Portal de Sede, QR y albaranes A4 respecto a `dispatch_level`, `level1_issue_type`, notas internas de escalado `is_internal = TRUE` y aviso de Coincidencia en Sede), Art. V.5 (validación de fotografía de evidencia $\le 5\text{ MB}$ por magic bytes reales), Art. V.6 (toda reapertura en garantía $< 48\text{ h}$ de un ticket resuelto en `L1_RESTOCKER` se promueve automáticamente a `L2_SAT`), Art. VI (delimitación sagrada del alcance sin adelantar planificador de rutas periódicas ni cuadro de mando de BI de reposición reservados a las Features 27 y 28).

---

## 1. Contexto y Objetivo

### 1.1. El Problema (Por qué)
Tras la incorporación de la plantilla de **Cargadores / Reponedores de Ruta (`RESTOCKER`)** y su cobertura territorial (Módulo 31), el catálogo de productos y planogramas (Módulo 32), las órdenes de carga frigorífica (Módulo 33), las visitas de reposición in situ (Módulo 34) y la recaudación con arqueo ciego (Módulo 35), las dos flotas de calle (**Reponedores** y **Técnicos SAT**) operan aún en compartimentos estancos frente a las incidencias del parque:

1. **Saturación de la agenda del Técnico SAT con avisos operativos leves (Nivel 1):** Reportes cotidianos de ciudadanos o sedes por *"Producto agotado / Carril vacío"*, *"Sin vasos / Sin azúcar / Sin paletinas"*, *"No devuelve cambio (tubos vacíos)"* o *"Atasco leve de producto en espiral"* ocupan hoy la cola de los Técnicos SAT especialistas, obligándoles a desplazarse por incidencias que el Reponedor de la zona puede resolver dentro de su ruta normal de abastecimiento.
2. **Falta de canal de escalado técnico in situ desde la parada de carga:** Cuando el Reponedor abre una máquina (para abastecerla o para atender un aviso de Nivel 1) y descubre una avería electromecánica real que requiere herramientas o repuestos del catálogo SAT (ej. motor de espiral quemado, electroválvula con fuga, monedero sin comunicación MDB, grupo de café bloqueado), carece de una acción ágil en un toque (**«⚡ Escalar Avería al SAT»**) que traspase o abra el expediente hacia la cola SAT con fotografía y Nota Interna de Taller (`is_internal = TRUE`).
3. **Aperturas duplicadas y desincronización en la misma sede:** Cuando un Técnico SAT y un Reponedor tienen paradas activas simultáneamente en el mismo edificio durante su jornada, ninguna de las dos vistas móviles les avisa de que su compañero también opera en esa sede, desaprovechando la oportunidad de coordinar la intervención a puerta abierta.

### 1.2. El Objetivo (Qué)
Construir el sistema de **Sinergia Operativa Reponedor ↔ Técnico SAT (Módulo 36)** sobre cuatro pilares arquitectónicos:

| Pilar | Resultado Operativo |
| :--- | :--- |
| **1. Expediente Único con Nivel de Despacho (`D1`, `D2`, `QA-AMB-01`)** | Ampliar `incidents` con `dispatch_level` (`L1_RESTOCKER` \| `L2_SAT`) y `level1_issue_type` (`STOCKOUT_EMPTY_SLOT`, `MISSING_CONSUMABLES`, `EMPTY_CHANGE_TUBES`, `MINOR_SPIRAL_JAM`), pre-clasificando avisos leves elegibles y permitiendo en Triaje despacharlos en 1 clic al Reponedor `#1 RECOMENDADO ⭐` de la zona (`RestockerZoneCoverageService`). |
| **2. Resolución de Nivel 1 In Situ y Escalado Técnico al SAT (`D3`, `QA-LIM-03`, `QA-CONST-02`)** | Permitir al `RESTOCKER` iniciar y resolver en ruta sus avisos `L1_RESTOCKER` (con taxonomía de Nivel 1 y diagnóstico/acción $\ge 20$ caracteres, sin repuestos SAT ni albarán A4), o pulsar **«⚡ Escalar Avería al SAT»** (desde un aviso L1 o desde cualquier máquina de su zona) adjuntando foto ($\le 5\text{ MB}$) y Nota Interna (`is_internal = TRUE`, $\ge 20$ caracteres) con asignación automática al mejor Técnico SAT disponible (`TechnicianAssignmentSuggester`). |
| **3. Indicador de Coincidencia en Sede (`D4`, `QA-CONST-03`)** | Mostrar en `TechnicianRouteView.js` y `RestockerRouteView.js` la alerta interna **«🤝 Coincidencia en Sede»** (con nombre, `operator_code` y enlace `tel:`) cuando ambos operarios están en turno activo (`ON_SHIFT` / `MEAL_BREAK`) y coinciden con actividad en la misma sede, oculta al Portal de Sede y QR (Art. V.4). |
| **4. Relevo Automático de Turno L1, Auto-Escalados de Seguridad y Cola Offline (`C1`, `C2`, `QA-LIM-01/02`)** | Bloquear el cierre de turno del reponedor si deja un L1 en `IN_PROGRESS` y relevar automáticamente los `ASSIGNED`; auto-escalar a `L2_SAT` si entra frío/corte eléctrico por absorción QR o si un ticket resuelto en L1 se reabre en $< 48\text{ h}$; y soportar encolado Offline PWA (`client_mutation_id`) y cómputo en horas/métricas. |

---

### 1.3. Resumen de Decisiones de Diseño (`D1`–`D4`), Casos Límite (`C1`–`C2`) y Salvaguardas QA (`QA-*`) Aprobadas

* **`D1` · Expediente único en `incidents` con `dispatch_level` (`L1_RESTOCKER` | `L2_SAT`) y custodio unificado:**
  1. Se incorpora en `incidents` la columna `dispatch_level incident_dispatch_level NOT NULL DEFAULT 'L2_SAT'` (`'L1_RESTOCKER'`, `'L2_SAT'`).
  2. Se preserva intacto el índice único parcial `uq_machine_active_ticket` (a lo sumo un expediente activo por máquina, **Art. V.2**) y la columna de custodio único `assigned_technician_id` junto con la cadena de custodia en `incident_assignments` (**Art. V.3**, Módulo 26).
  3. Cuando `dispatch_level = 'L1_RESTOCKER'`, el custodio asignado (`assigned_technician_id`) debe ser un usuario activo con rol `'RESTOCKER'`; cuando `dispatch_level = 'L2_SAT'`, debe ser un usuario activo con rol `'TECHNICIAN'`.
* **`D2` · Pre-clasificación asistida con `level1_issue_type` y despacho en 1 clic desde Triaje de Coordinación:**
  1. Se incorpora en `incidents` la columna opcional `level1_issue_type level1_issue_type NULL` con los 4 motivos operativos de Nivel 1:
     * `'STOCKOUT_EMPTY_SLOT'` (*Producto agotado / Carril vacío*)
     * `'MISSING_CONSUMABLES'` (*Sin vasos / Sin azúcar / Sin paletinas*)
     * `'EMPTY_CHANGE_TUBES'` (*No devuelve cambio / Tubos vacíos*)
     * `'MINOR_SPIRAL_JAM'` (*Atasco leve de producto en espiral*)
  2. En la entrada de avisos (QR público, Portal de Sede y Alta de Coordinación), si se indica un `level1_issue_type` válido —y **no** se trata de una avería `TEMPERATURE_COLD` ni `ELECTRICAL_OFF`, la máquina **no** está en `sanitary_status = 'QUARANTINE'`, y **no** existe reclamación de dinero retenido superior a $10,00\,\text{€}$ ni con método bancario/Bizum—, el expediente nace en estado `'REGISTERED'` con `dispatch_level = 'L1_RESTOCKER'`.
  3. En el panel y modal de Triaje/Asignación de Coordinación, el coordinador puede despachar el aviso en `L1_RESTOCKER` (recibiendo como sugerencia `#1 RECOMENDADO ⭐` al reponedor efectivo de la zona mediante `RestockerZoneCoverageService` del Módulo 31) o conmutar su nivel entre `L1_RESTOCKER` y `L2_SAT` antes de asignarlo.
* **`D3` · Resolución In Situ de Nivel 1 y acción «⚡ Escalar Avería al SAT» con despacho automático asistido:**
  1. **Resolución de Nivel 1 (`L1_RESTOCKER`):** El reponedor asignado inicia la intervención (`ASSIGNED` / `REOPENED` $\rightarrow$ `IN_PROGRESS`) y la resuelve (`IN_PROGRESS` $\rightarrow$ `RESOLVED`) seleccionando una causa raíz y solución estandarizada del catálogo (Módulo 25) y aportando `resolution_diagnosis` ($\ge 20$ caracteres reales) y `resolution_action` ($\ge 20$ caracteres reales, **Art. V.1**), **sin** declarar repuestos de `spare_parts` ni generar hoja de firma técnica A4 (`incident_service_sheets`), que permanecen exclusivos de `L2_SAT`.
  2. **Acción «⚡ Escalar Avería al SAT»:** Disponible para el reponedor tanto dentro de un aviso `L1_RESTOCKER` activo como directamente desde la tarjeta de cualquier máquina de sus zonas cubiertas hoy (p. ej. al descubrir un fallo técnico durante la Visita de Reposición del Módulo 34 o la Recaudación del Módulo 35). Exige seleccionar la categoría técnica (`TEMPERATURE_COLD`, `PAYMENT_SYSTEM`, `PRODUCT_JAM`, `ELECTRICAL_OFF`, `OTHER`), redactar una **Nota Interna de Taller** (`is_internal = TRUE`, $\ge 20$ caracteres reales con $\ge 3$ bloques alfanuméricos, **Art. V.1 y V.4**) y adjuntar una fotografía de evidencia ($\le 5\text{ MB}$ validada por magic bytes reales, **Art. V.5**).
  3. Al ejecutarse el escalado, dentro de una única transacción con bloqueo `FOR UPDATE` sobre la máquina:
     * Si era un ticket `L1_RESTOCKER` (o `REGISTERED`), cierra el tramo de custodia del reponedor en `incident_assignments` con motivo `'ESCALATED_TO_SAT_BY_RESTOCKER'`, conmuta `dispatch_level = 'L2_SAT'`, recalcula/eleva la urgencia y el objetivo de SLA si la categoría técnica reportada es más severa (ej. `TEMPERATURE_COLD` $\rightarrow$ `CRITICAL` 240 min y cuarentena preventiva si supera techo térmico, **Art. II**), inserta el comentario interno (`author_type = 'RESTOCKER'`, `is_internal = TRUE`) con la foto, e invoca `TechnicianAssignmentSuggester`: si existe un Técnico SAT elegible disponible en la zona (o de guardia 24/7 si es frío), le asigna el ticket automáticamente (`status = 'ASSIGNED'`, fuente `'RESTOCKER_ESCALATION_TO_SAT'`) y encola la alerta en `notification_outbox`; si no hay candidato elegible, deja el ticket en `status = 'REGISTERED'` (`assigned_technician_id = NULL`) y encola la alerta a Coordinación.
* **`D4` · Indicador de «🤝 Coincidencia en Sede» en ambas vistas móviles:**
  1. Se activa para una sede (`location_id`) cuando coinciden simultáneamente en jornada abierta hoy (`ON_SHIFT` o `MEAL_BREAK` en `technician_shifts`):
     * Al menos un **Técnico SAT (`TECHNICIAN`)** con parada activa en esa sede (incidencia `L2_SAT` en `ASSIGNED`, `IN_PROGRESS` o `REOPENED`, u orden preventiva en `SCHEDULED` o `IN_INSPECTION`), **Y**
     * Al menos un **Reponedor (`RESTOCKER`)** que tenga un aviso `L1_RESTOCKER` activo (`ASSIGNED`, `IN_PROGRESS`, `REOPENED`) en esa sede, **o bien** esté en cobertura activa (`ON_SHIFT` / `MEAL_BREAK`) de la Zona Operativa de esa sede con máquinas activas en ella.
  2. Tanto `TechnicianRouteView.js` como `RestockerRouteView.js` muestran en la tarjeta de la parada/máquina el banner **«🤝 Coincidencia en Sede · [Nombre] ([operator_code]) en turno»** con enlace de llamada rápida `tel:` si dispone de teléfono corporativo.
* **`C1` · Cierre de turno, ausencias y baja de cuenta del Reponedor con avisos `L1_RESTOCKER` vivos:**
  1. **Bloqueo *fail-fast* en `IN_PROGRESS`:** Si el reponedor intenta cerrar su turno (`Cerrar Turno`) mientras tiene algún aviso `L1_RESTOCKER` en estado `'IN_PROGRESS'`, el sistema rechaza el cierre con **`409 Conflict` (`SHIFT_CLOSE_BLOCKED_IN_PROGRESS_L1`)** indicando los tickets que debe resolver o escalar antes de salir.
  2. **Relevo automático de `ASSIGNED` / `PENDING_INFO`:** Al cerrar el turno del reponedor (o al registrársele una ausencia vigente o desactivar su cuenta `is_active = FALSE`), todos sus avisos `L1_RESTOCKER` en `'ASSIGNED'` o `'PENDING_INFO'` cierran su tramo en `incident_assignments` (`'RESTOCKER_SHIFT_HANDOVER'` / `'ABSENCE_COVERAGE_REASSIGN'`) y se reasignan automáticamente al siguiente reponedor activo disponible de la zona según `RestockerZoneCoverageService` (Niveles 1 a 4 en turno hoy); si ningún otro reponedor de la zona tiene turno abierto, pasan a `'REGISTERED'` (`assigned_technician_id = NULL`, conservando `dispatch_level = 'L1_RESTOCKER'`) para la siguiente apertura o despacho.
* **`C2` · Cola Offline PWA del Reponedor y trazabilidad en Horas y Métricas:**
  1. Las operaciones móviles del reponedor sobre avisos (**Iniciar Nivel 1**, **Resolver Nivel 1** y **⚡ Escalar Avería al SAT**) se integran en la cola `IndexedDB` del reponedor con `client_mutation_id` idempotente (respaldado en servidor por `offline_sync_mutations`) y volcado de conflictos (`409`) en `audit_log`.
  2. Los avisos `L1_RESTOCKER` resueltos por un reponedor computan en su *Tiempo en Intervención In Situ* e *Intervenciones Resueltas* dentro de `TechnicianWorkHoursService` (Módulo 22), alimentan el diagrama de Pareto (Módulo 25) y el filtro por nivel operativo (`ALL` | `L1_RESTOCKER` | `L2_SAT`) en Coordinación.
* **9 Salvaguardas de Auditoría QA Consensuadas (`QA-*`):**
  1. **`QA-AMB-01` (Mapeo determinista `level1_issue_type` $\rightarrow$ `incident_category` y semillas de taxonomía):**
     * `'STOCKOUT_EMPTY_SLOT'` $\rightarrow$ `'PRODUCT_JAM'` (Causa semilla: `RC-L1-STOCKOUT` / Solución: `SS-L1-RESTOCK-SLOT`).
     * `'MINOR_SPIRAL_JAM'` $\rightarrow$ `'PRODUCT_JAM'` (Causa semilla: `RC-L1-SPIRAL-RELEASE` / Solución: `SS-L1-CLEAR-SPIRAL`).
     * `'EMPTY_CHANGE_TUBES'` $\rightarrow$ `'PAYMENT_SYSTEM'` (Causa semilla: `RC-L1-EMPTY-TUBES` / Solución: `SS-L1-REFILL-TUBES`).
     * `'MISSING_CONSUMABLES'` $\rightarrow$ `'OTHER'` (Causa semilla: `RC-L1-CONSUMABLES` / Solución: `SS-L1-REFILL-CUPS-SUGAR`).
  2. **`QA-AMB-02` (Ampliación de tipos `ENUM` nativos de comentarios y custodia):**
     * `comment_author_type`: añade `'RESTOCKER'`.
     * `incident_assignment_source`: añade `'RESTOCKER_ESCALATION_TO_SAT'`, `'RESTOCKER_SHIFT_HANDOVER'`, `'QR_AUTO_ESCALATION_TO_SAT'`, `'WARRANTY_REOPEN_ESCALATION_TO_SAT'`.
     * `incident_unassignment_reason_type`: añade `'ESCALATED_TO_SAT_BY_RESTOCKER'`, `'QR_AUTO_ESCALATED_TO_SAT'`, `'RESTOCKER_SHIFT_HANDOVER'`.
  3. **`QA-CON-01` (Coherencia RBAC por `dispatch_level` y rutas segregadas):** Los endpoints `/api/technician/*` continúan devolviendo `403 Forbidden` a `'RESTOCKER'`. El reponedor opera exclusivamente en `/api/restocker/*` sobre máquinas de sus zonas vivas, y el invariante de dominio/aplicación garantiza que `assigned_technician_id` apunte a un `'RESTOCKER'` solo si `dispatch_level = 'L1_RESTOCKER'` y a un `'TECHNICIAN'` solo si `dispatch_level = 'L2_SAT'`.
  4. **`QA-CON-02` (Atribución limpia en `TechnicianWorkHoursService`):** En el informe horario del Módulo 22, las intervenciones cerradas (`RESOLVED` / `CLOSED`) suman `started_at → resolved_at` al operario que ejecutó la resolución final (`assigned_technician_id` vigente en el cierre) dentro de su rol respectivo (`TECHNICIAN` o `RESTOCKER`).
  5. **`QA-LIM-01` (Auto-escalado inmediato a `L2_SAT` ante absorción QR de avería térmica, eléctrica o reclamación $> 10\,\text{€}$ / digital):** Si en `QrReportService::mergeIntoExistingIncident()` (o en edición de Coordinación) un ticket activo que estaba en `dispatch_level = 'L1_RESTOCKER'` recibe un reporte con `incomingCategory IN ('TEMPERATURE_COLD', 'ELECTRICAL_OFF')` o una reclamación de reintegro que exceda el alcance de conserjería ($> 10,00\,\text{€}$ o `BIZUM` / `TRANSFERENCIA_BANCARIA`), el sistema **auto-escala en la misma transacción el expediente a `dispatch_level = 'L2_SAT'`**, cierra el tramo de custodia del reponedor (`'QR_AUTO_ESCALATED_TO_SAT'`), si estaba en `'IN_PROGRESS'` lo devuelve a `'ASSIGNED'` o `'REGISTERED'`, invoca `TechnicianAssignmentSuggester` para asignar al mejor Técnico SAT disponible (fuente `'QR_AUTO_ESCALATION_TO_SAT'`) y encola la alerta en `notification_outbox`.
  6. **`QA-LIM-02` (Promoción automática a `L2_SAT` en reapertura en garantía $< 48\text{ h}$ — Art. V.6):** Cuando un expediente resuelto en `dispatch_level = 'L1_RESTOCKER'` es reabierto dentro de las 48 horas posteriores a su resolución (desde Sede, Coordinación o en ruta), el sistema conmuta obligatoriamente `dispatch_level = 'L2_SAT'` y despacha la reapertura hacia el equipo de Técnicos SAT (evitando bucles de reincidencia superficial).
  7. **`QA-LIM-03` (Prohibición de `PENDING_PARTS` en `L1_RESTOCKER`):** Mientras un expediente tenga `dispatch_level = 'L1_RESTOCKER'`, cualquier intento de transicionarlo a `'PENDING_PARTS'` o asociarle solicitudes/consumos de `spare_parts` es rechazado con **`409 Conflict` (`L1_PENDING_PARTS_FORBIDDEN`)**.
  8. **`QA-CONST-01` (Guarda Constitucional Art. II en Dominio y PostgreSQL):** Restricción `CHECK` en `incidents` (`chk_incidents_l1_dispatch_coherence`) y validación de dominio que impiden en todo momento `dispatch_level = 'L1_RESTOCKER'` cuando `category IN ('TEMPERATURE_COLD', 'ELECTRICAL_OFF')`, y bloquean la resolución de un `L1_RESTOCKER` si la máquina se encuentra en `machines.sanitary_status = 'QUARANTINE'` (`409 SANITARY_L1_RESOLUTION_BLOCKED`).
  9. **`QA-CONST-02` y `QA-CONST-03` (Absorción idempotente al escalar sobre `L2_SAT` preexistente y Opacidad Art. V.4):** Si el reponedor pulsa «⚡ Escalar Avería al SAT» en una máquina que ya tiene un ticket activo en `dispatch_level = 'L2_SAT'`, el sistema no crea un segundo ticket (Art. V.2): anexa la foto y la Nota Interna (`is_internal = TRUE`) al expediente `L2_SAT` existente, eleva su urgencia/SLA si la categoría reportada es más grave y conserva la custodia del Técnico SAT que ya lo tuviera asignado (o lo asigna vía `TechnicianAssignmentSuggester` si estaba en `REGISTERED`). En Portal de Sede, QR y albaranes A4 jamás se exponen `dispatch_level`, `level1_issue_type`, notas internas de escalado ni datos de `Coincidencia en Sede` (Art. V.4).

---

## 2. Usuarios y Roles Implicados

| Usuario / Rol | Relación con el Módulo | Reglas de Privacidad y Permisos (Art. V.4) |
| :--- | :--- | :--- |
| **📦 Cargador / Reponedor de Ruta (`RESTOCKER`)** | Visualiza en `RestockerRouteView.js` los avisos `L1_RESTOCKER` asignados en sus zonas; los inicia y resuelve in situ; pulsa **«⚡ Escalar Avería al SAT»** con foto y nota interna cuando detecta una avería electromecánica; y visualiza el indicador **«🤝 Coincidencia en Sede»**. | **Segregación RBAC (`QA-CON-01`, Art. V.4):** Solo puede operar en `/api/restocker/*` sobre máquinas de sus zonas vivas y tickets `L1_RESTOCKER` bajo su custodia. Tiene bloqueado (`403`) el acceso a `/api/technician/*`, a tickets `L2_SAT` (salvo para inyectar un escalado a puerta abierta), a repuestos SAT y a albaranes de firma A4. |
| **🔧 Técnico de Campo SAT (`TECHNICIAN`)** | Recibe en `TechnicianRouteView.js` (y por Telegram/Outbox) los tickets escalados por los reponedores con su foto y Nota Interna de Taller (`is_internal = TRUE`); y visualiza el indicador **«🤝 Coincidencia en Sede»** cuando coincide con un reponedor en turno en el mismo edificio. | Sus sugerencias en `TechnicianAssignmentSuggester` y su cola operativa solo contienen tickets `L2_SAT`. Ve la trazabilidad completa del escalado en el hilo interno del ticket. |
| **📊 Coordinador de Operaciones (`COORDINATOR`)** | En Triaje/Asignación ve la pre-clasificación `L1_RESTOCKER` + `level1_issue_type`, despacha en 1 clic al Reponedor `#1 RECOMENDADO ⭐` de la zona o conmuta el ticket entre `L1_RESTOCKER` y `L2_SAT`; y filtra el panel y métricas por nivel operativo (`ALL` \| `L1_RESTOCKER` \| `L2_SAT`). | Visibilidad completa de ambos niveles, cadena de custodia mixta (tramo Reponedor $\rightarrow$ tramo Técnico SAT), notas internas de escalado y métricas desglosadas. |
| **🏢 Responsable de Sede (`LOCATION_RESPONSIBLE`) y Canal Público QR** | Pueden seleccionar opcionalmente uno de los 4 motivos rápidos (`level1_issue_type`) al reportar un aviso de producto/cambio/consumibles. | **Opacidad total (`QA-CONST-03`, Art. V.4):** Jamás ven si el ticket se gestiona en `L1_RESTOCKER` o `L2_SAT`, ni la identidad/teléfono del operario en `Coincidencia en Sede`, ni la Nota Interna de Taller generada al escalar al SAT. |

---

## 3. Historias de Usuario

### HU-01: Pre-clasificación y Despacho de Avisos de Nivel 1 a la Cola del Reponedor de Zona
> **Como** Coordinador de Operaciones (`COORDINATOR`),\
> **quiero** que los avisos por carril vacío, falta de vasos/azúcar, falta de cambio en tubos o atasco leve en espiral nazcan pre-clasificados como **Nivel 1 (`L1_RESTOCKER`)** y el modal de Triaje me sugiera en 1 clic al **Reponedor `#1 RECOMENDADO ⭐`** en cobertura activa de la zona (pudiendo conmutarlo a `L2_SAT` si observo que requiere taller),\
> **para** despachar las incidencias operativas leves a la ruta de reposición sin saturar la agenda de los Técnicos SAT especialistas.

### HU-02: Resolución In Situ de Aviso de Nivel 1 por el Reponedor (con Soporte Offline PWA)
> **Como** Cargador / Reponedor de Ruta (`RESTOCKER`),\
> **quiero** ver en mi hoja de ruta móvil los avisos `L1_RESTOCKER` asignados, iniciar la intervención y resolverla registrando la causa raíz/solución de Nivel 1 y mi diagnóstico y acción ejecutada ($\ge 20$ caracteres reales), incluso en sótanos sin cobertura móvil,\
> **para** dejar la máquina operativa durante mi paso de ruta y cerrar el ticket con trazabilidad completa sin burocracia de repuestos técnicos.

### HU-03: Escalado Técnico In Situ («⚡ Escalar Avería al SAT») a Puerta Abierta
> **Como** Cargador / Reponedor de Ruta (`RESTOCKER`),\
> **quiero** disponer del botón **«⚡ Escalar Avería al SAT»** (tanto dentro de un aviso de Nivel 1 como en cualquier máquina de mi ruta) para reportar o traspasar un fallo electromecánico adjuntando fotografía ($\le 5\text{ MB}$) y una **Nota Interna de Taller** (`is_internal = TRUE`, $\ge 20$ caracteres) que asigne automáticamente al mejor Técnico SAT disponible de la zona,\
> **para** que el especialista SAT reciba al instante el contexto exacto visto con la máquina abierta sin perder tiempo diagnosticando desde cero.

### HU-04: Indicador de Coincidencia en Sede entre Técnico SAT y Reponedor
> **Como** Técnico SAT (`TECHNICIAN`) o Reponedor de Ruta (`RESTOCKER`) en turno activo,\
> **quiero** ver en la tarjeta de mi parada un aviso **«🤝 Coincidencia en Sede»** con el nombre, código y acceso telefónico interno de mi compañero cuando ambos coincidamos con paradas activas en la misma sede,\
> **para** coordinarnos en el edificio y aprovechar una única apertura de máquina.

---

## 4. Requisitos Funcionales (RF) y Criterios de Aceptación (EARS)

### RF-01 · Clasificación de Nivel 1 (`dispatch_level`, `level1_issue_type`), Guardas Constitucionales y Semillas de Taxonomía (`D1`, `D2`, `QA-AMB-01/02`, `QA-CONST-01`, Art. II, Art. V.2, Art. V.3)

* **Criterio EARS 01.1 (Nuevos tipos nativos #43 y #44, columnas en `incidents` y restricción SQL de coherencia — D1, D2, QA-AMB-01, QA-CONST-01):**
  1. **EL SISTEMA DEBERÁ** declarar en PostgreSQL el tipo nativo #43 `incident_dispatch_level` (`'L1_RESTOCKER'`, `'L2_SAT'`) y el tipo nativo #44 `level1_issue_type` (`'STOCKOUT_EMPTY_SLOT'`, `'MISSING_CONSUMABLES'`, `'EMPTY_CHANGE_TUBES'`, `'MINOR_SPIRAL_JAM'`), y añadir en `incidents` las columnas `dispatch_level incident_dispatch_level NOT NULL DEFAULT 'L2_SAT'`, `level1_issue_type level1_issue_type NULL`, `escalated_from_restocker_id BIGINT NULL` (FK `ON DELETE RESTRICT` a `users.id`) y `escalated_to_sat_at TIMESTAMPTZ NULL`.
  2. **EL SISTEMA DEBERÁ** imponer tanto en el dominio como mediante la restricción SQL `chk_incidents_l1_dispatch_coherence` en `incidents` que:
     * **JAMÁS** pueda existir una fila con `dispatch_level = 'L1_RESTOCKER'` cuando `category IN ('TEMPERATURE_COLD', 'ELECTRICAL_OFF')` (**Art. II**).
     * Si `dispatch_level = 'L1_RESTOCKER'`, entonces `pending_parts_reason IS NULL` (**`QA-LIM-03`**) y `status <> 'PENDING_PARTS'`.
     * Si `level1_issue_type IS NOT NULL`, su categoría compatible en la creación inicial sea determinista (`'STOCKOUT_EMPTY_SLOT'` $\rightarrow$ `'PRODUCT_JAM'`, `'MINOR_SPIRAL_JAM'` $\rightarrow$ `'PRODUCT_JAM'`, `'EMPTY_CHANGE_TUBES'` $\rightarrow$ `'PAYMENT_SYSTEM'`, `'MISSING_CONSUMABLES'` $\rightarrow$ `'OTHER'`; tras un escalado posterior a `'L2_SAT'`, `level1_issue_type` puede conservarse como rastro histórico de origen aunque la categoría pase a `'TEMPERATURE_COLD'` o `'ELECTRICAL_OFF'`).
* **Criterio EARS 01.2 (Pre-clasificación asistida en QR, Portal de Sede y Alta de Coordinación — D2 y QA-LIM-01):**
  1. **CUANDO** se cree una incidencia desde el canal QR (`POST /api/public/qr/{code}/report`), el Portal de Sede (`POST /api/portal/incidents`) o el panel de Coordinación (`POST /api/coordinator/incidents`) informando un `level1_issue_type` válido:
     * **SI** la categoría no es `'TEMPERATURE_COLD'` ni `'ELECTRICAL_OFF'`, la máquina no está en `sanitary_status = 'QUARANTINE'`, y no se reclama un importe retenido $> 10,00\,\text{€}$ ni con método `'BIZUM'` / `'TRANSFERENCIA_BANCARIA'`, **ENTONCES EL SISTEMA DEBERÁ** fijar automáticamente `category` según el mapeo `QA-AMB-01` (si no se envió otra compatible) y sellar `dispatch_level = 'L1_RESTOCKER'`.
     * **SI** concurre una condición excluyente (avería térmica/eléctrica, máquina en cuarentena o reclamación monetaria $> 10,00\,\text{€}$ / digital), **ENTONCES EL SISTEMA DEBERÁ** forzar `dispatch_level = 'L2_SAT'` (o rechazar con `422 L1_DISPATCH_FORBIDDEN_FOR_CATEGORY` si Coordinación intentó forzar explícitamente `dispatch_level = 'L1_RESTOCKER'` sobre `TEMPERATURE_COLD` o `ELECTRICAL_OFF`).
* **Criterio EARS 01.3 (Semillas maestras de Causas Raíz y Soluciones Estandarizadas de Nivel 1 — QA-AMB-01 y Módulo 25):**
  1. **EL SISTEMA DEBERÁ** sembrar de forma idempotente en `root_causes` y `standard_solutions` las 4 parejas oficiales de resolución de Nivel 1:
     * `RC-L1-STOCKOUT` (`PRODUCT_JAM`, *"Agotamiento de producto en carril de selección"*) $\rightarrow$ Solución predeterminada `SS-L1-RESTOCK-SLOT` (*"Reposición de producto en carril y verificación de entrega"*).
     * `RC-L1-SPIRAL-RELEASE` (`PRODUCT_JAM`, *"Envase mal posicionado o trabado leve en boca de espiral"*) $\rightarrow$ Solución predeterminada `SS-L1-CLEAR-SPIRAL` (*"Liberación manual de envase en espiral y reacomodo de carril"*).
     * `RC-L1-EMPTY-TUBES` (`PAYMENT_SYSTEM`, *"Tubos de cambio del monedero sin suficiente moneda fraccionaria"*) $\rightarrow$ Solución predeterminada `SS-L1-REFILL-TUBES` (*"Recarga de tubos de cambio y comprobación de devolución"*).
     * `RC-L1-CONSUMABLES` (`OTHER`, *"Falta de vasos, azúcar o paletinas en dispensador"*) $\rightarrow$ Solución predeterminada `SS-L1-REFILL-CUPS-SUGAR` (*"Reposición de consumibles (vasos/azúcar/paletinas) y limpieza de tolva"*).

---

### RF-02 · Triaje y Despacho de Nivel 1 en Coordinación y Auto-Escalados por Absorción QR o Reapertura (`D1`, `D2`, `QA-CON-01`, `QA-LIM-01`, `QA-LIM-02`, Art. II, Art. V.3, Art. V.6)

* **Criterio EARS 02.1 (Sugerencia de Reponedor por Zona y despacho/conmutación en Coordinación — D1, D2, QA-CON-01):**
  1. **CUANDO** Coordinación consulte los candidatos sugeridos para un ticket (`GET /api/coordinator/incidents/{id}/suggested-assignees` o `GET /api/coordinator/incidents/{id}/suggested-restockers`), **EL SISTEMA DEBERÁ** devolver la cobertura de carga de la Zona Operativa de la sede calculada por `RestockerZoneCoverageService` (Módulo 31, escalafón de 6 niveles activos con promoción automática del `BACKUP` a `#1 RECOMENDADO ⭐`), enriquecida con el contador de avisos `L1_RESTOCKER` activos de cada reponedor.
  2. **CUANDO** Coordinación asigne o reasigne un ticket (`PUT /api/coordinator/incidents/{id}/assign` o `POST /api/coordinator/incidents/{id}/dispatch-level`):
     * **SI** el destino es `dispatch_level = 'L1_RESTOCKER'`, **EL SISTEMA DEBERÁ** exigir que el usuario asignado tenga rol `'RESTOCKER'` y esté activo (`422 INVALID_ASSIGNEE_ROLE_FOR_DISPATCH_LEVEL` si es `'TECHNICIAN'`), y que el ticket no sea `'TEMPERATURE_COLD'` ni `'ELECTRICAL_OFF'` (`422 L1_DISPATCH_FORBIDDEN_FOR_CATEGORY`).
     * **SI** el destino es `dispatch_level = 'L2_SAT'`, **EL SISTEMA DEBERÁ** exigir que el usuario asignado tenga rol `'TECHNICIAN'` y esté activo (`422 INVALID_ASSIGNEE_ROLE_FOR_DISPATCH_LEVEL` si es `'RESTOCKER'`).
     * En ambos casos, **EL SISTEMA DEBERÁ** abrir/cerrar el tramo correspondiente en `incident_assignments` y auditar la operación en `audit_log`.
* **Criterio EARS 02.2 (Auto-escalado inmediato a `L2_SAT` en absorción QR concurrente de avería grave — QA-LIM-01 y Art. II):**
  1. **CUANDO** `QrReportService::mergeIntoExistingIncident()` absorba un reporte ciudadano sobre un expediente activo cuyo `dispatch_level` sea `'L1_RESTOCKER'`, **Y** el reporte entrante declare `category IN ('TEMPERATURE_COLD', 'ELECTRICAL_OFF')` **O** una reclamación de dinero retenido $> 10,00\,\text{€}$ (o con método `'BIZUM'` / `'TRANSFERENCIA_BANCARIA'`), **EL SISTEMA DEBERÁ** dentro de la misma transacción:
     * Conmutar `dispatch_level = 'L2_SAT'` y actualizar `category` a la categoría grave entrante (si era `'TEMPERATURE_COLD'` o `'ELECTRICAL_OFF'`), endureciendo `urgency` y `sla_target_minutes`.
     * Si tenía un reponedor asignado, cerrar su tramo en `incident_assignments` con `unassignment_reason_type = 'QR_AUTO_ESCALATED_TO_SAT'`.
     * Invocar `TechnicianAssignmentSuggester` para asignar automáticamente al mejor Técnico SAT disponible (o de guardia 24/7 si es `'TEMPERATURE_COLD'`), abriendo su tramo en `incident_assignments` con `assignment_source = 'QR_AUTO_ESCALATION_TO_SAT'` y estado `'ASSIGNED'` (o dejándolo en `'REGISTERED'` con `assigned_technician_id = NULL` si no hay candidato disponible), y encolar la alerta en `notification_outbox`.
* **Criterio EARS 02.3 (Promoción automática a `L2_SAT` en toda reapertura dentro de las 48 h — QA-LIM-02 y Art. V.6):**
  1. **CUANDO** un expediente que fue resuelto (`status = 'RESOLVED'`) bajo `dispatch_level = 'L1_RESTOCKER'` sea reabierto dentro de la ventana de garantía de 48 horas (por el Portal de Sede, por Coordinación o in situ), **EL SISTEMA DEBERÁ** promover automáticamente el expediente a `dispatch_level = 'L2_SAT'`, desvincular al reponedor previo (`assigned_technician_id = NULL` en estado `'REOPENED'` o asignarlo al Técnico SAT mediante `TechnicianAssignmentSuggester` / asunción SAT) y registrar en `incident_history` la nota `"Reapertura en garantía (< 48 h) promovida automáticamente de Nivel 1 (Reponedor) a Nivel 2 (SAT Técnico)"`.

---

### RF-03 · Operativa Móvil del Reponedor: Resolución de Nivel 1 In Situ y Acción «⚡ Escalar Avería al SAT» (`D3`, `C2`, `QA-AMB-02`, `QA-LIM-03`, `QA-CONST-01`, `QA-CONST-02`, Art. II, Art. III.3, Art. V.1, Art. V.4, Art. V.5)

* **Criterio EARS 03.1 (Inicio y Resolución de un aviso `L1_RESTOCKER` por el Reponedor — D3, QA-LIM-03, QA-CONST-01, Art. V.1):**
  1. **CUANDO** un usuario autenticado con rol `'RESTOCKER'` inicie un aviso `L1_RESTOCKER` asignado a él (`POST /api/restocker/incidents/{id}/start`), **EL SISTEMA DEBERÁ** verificar que el ticket esté en `dispatch_level = 'L1_RESTOCKER'`, que `assigned_technician_id = restocker.id` (`403 INCIDENT_NOT_ASSIGNED_TO_RESTOCKER` en caso contrario), transicionarlo de `'ASSIGNED'` a `'IN_PROGRESS'`, sellar `started_at = COALESCE(started_at, effective_occurred_at)` y registrar el cambio en `incident_history` y `audit_log`.
  2. **CUANDO** el reponedor resuelva el aviso (`POST /api/restocker/incidents/{id}/resolve`), **EL SISTEMA DEBERÁ**:
     * Rechazar con **`409 Conflict` (`SANITARY_L1_RESOLUTION_BLOCKED`)** si la máquina se encuentra en `machines.sanitary_status = 'QUARANTINE'` (**`QA-CONST-01`**, **Art. II**).
     * Validar `root_cause_id` y `standard_solution_id` activos y compatibles con la `category` del ticket, así como `resolution_diagnosis` y `resolution_action` con al menos **20 caracteres reales** cada uno (`422 RESOLUTION_TEXT_TOO_SHORT`, **Art. V.1**).
     * Sellar `status = 'RESOLVED'`, `resolved_at`, los 5 snapshots de taxonomía (Módulo 25), cerrar el tramo de custodia en `incident_assignments` (`unassignment_reason_type = 'RESOLVED'`) y registrar la auditoría, sin consumir `technician_van_stocks` ni crear fila en `incident_service_sheets`.
* **Criterio EARS 03.2 (Acción «⚡ Escalar Avería al SAT» desde aviso L1 o desde máquina en ruta — D3, QA-AMB-02, QA-CONST-02, Art. II, Art. V.1, Art. V.2, Art. V.3, Art. V.4, Art. V.5):**
  1. **CUANDO** un `'RESTOCKER'` ejecute **«⚡ Escalar Avería al SAT»** sobre una máquina activa de sus zonas cubiertas hoy (`POST /api/restocker/machines/{machineId}/escalate-to-sat` o `POST /api/restocker/incidents/{id}/escalate-to-sat`), **EL SISTEMA DEBERÁ** validar:
     * Que la máquina pertenezca a una Zona Operativa viva cubierta por el reponedor (`403 MACHINE_OUTSIDE_RESTOCKER_ZONES`).
     * `category` en `('TEMPERATURE_COLD', 'PAYMENT_SYSTEM', 'PRODUCT_JAM', 'ELECTRICAL_OFF', 'OTHER')`.
     * `workshop_internal_note` de entre **20 y 1000 caracteres reales** con al menos **3 bloques alfanuméricos** (`422 ESCALATION_INTERNAL_NOTE_REQUIRED`, **Art. V.1**).
     * Fotografía de evidencia obligatoria (`photo_path` o archivo subido $\le 5\text{ MB}$ validado por magic bytes reales JPG/PNG/WebP, `422 ESCALATION_PHOTO_REQUIRED` / `INVALID_EVIDENCE_IMAGE`, **Art. V.5**).
  2. **EL SISTEMA DEBERÁ** tomar bloqueo pesimista `SELECT ... FROM machines WHERE id = :machineId FOR UPDATE` y aplicar una de las tres ramas deterministas:
     * **Rama A (Existe un ticket activo en `dispatch_level = 'L1_RESTOCKER'` en esa máquina):**
       1. Cierra el tramo de custodia abierto del reponedor en `incident_assignments` con `unassignment_reason_type = 'ESCALATED_TO_SAT_BY_RESTOCKER'` y `reassignment_reason = workshop_internal_note`.
       2. Actualiza el ticket a `dispatch_level = 'L2_SAT'`, `category = payload.category`, `escalated_from_restocker_id = restocker.id` y `escalated_to_sat_at = effective_occurred_at`; si la nueva categoría implica mayor urgencia o menor objetivo de SLA (ej. `'TEMPERATURE_COLD'` $\rightarrow$ `'CRITICAL'` 240 min), eleva `urgency` y recalcula `sla_target_minutes` / `sla_target_at`.
       3. Inserta en `incident_comments` la Nota Interna de Taller con `author_type = 'RESTOCKER'`, `is_internal = TRUE` (**Art. V.4**), `comment_text = workshop_internal_note` y `photo_path`.
       4. Invoca `TechnicianAssignmentSuggester`: si hay un Técnico SAT elegible disponible, fija `status = 'ASSIGNED'`, `assigned_technician_id = bestTech.id`, `started_at = NULL`, abre tramo en `incident_assignments` (`assignment_source = 'RESTOCKER_ESCALATION_TO_SAT'`) y encola la alerta de asignación/escalado en `notification_outbox`; si no hay candidato elegible, fija `status = 'REGISTERED'`, `assigned_technician_id = NULL`, `started_at = NULL` y encola alerta urgente a Coordinación.
     * **Rama B (No existe ningún ticket activo en esa máquina — Detección a puerta abierta):**
       1. Crea un nuevo expediente en `incidents` con `dispatch_level = 'L2_SAT'`, `category = payload.category`, `description = workshop_internal_note`, `photo_path`, `escalated_from_restocker_id = restocker.id`, `escalated_to_sat_at = effective_occurred_at`, `urgency` y `sla_target_minutes` calculados según `SlaTier × MachineType × Category`.
       2. Inserta en `incident_comments` la Nota Interna de Taller (`author_type = 'RESTOCKER'`, `is_internal = TRUE`, `photo_path`).
       3. Invoca `TechnicianAssignmentSuggester` para asignarlo automáticamente al mejor Técnico SAT disponible (`status = 'ASSIGNED'`, fuente `'RESTOCKER_ESCALATION_TO_SAT'`) o dejarlo en `'REGISTERED'` si no hay candidato, encolando la alerta en `notification_outbox`.
     * **Rama C (Ya existía un ticket activo en `dispatch_level = 'L2_SAT'` en esa máquina — Absorción idempotente `QA-CONST-02`, Art. V.2 y V.3):**
       1. No crea un nuevo ticket (`merged_into_existing_sat_ticket = true`).
       2. Inserta en `incident_comments` del ticket `L2_SAT` existente la Nota Interna de Taller del reponedor (`author_type = 'RESTOCKER'`, `is_internal = TRUE`, `photo_path`), sella `escalated_from_restocker_id = COALESCE(escalated_from_restocker_id, restocker.id)` y `escalated_to_sat_at = COALESCE(escalated_to_sat_at, effective_occurred_at)`, y eleva la urgencia/SLA si la categoría detectada por el reponedor es más grave.
       3. Si el ticket `L2_SAT` estaba en `'REGISTERED'` sin técnico asignado, intenta asignarlo vía `TechnicianAssignmentSuggester`; si ya tenía un Técnico SAT asignado, **respeta intacta su custodia activa** (**Art. V.3**).

---

### RF-04 · Indicador de «🤝 Coincidencia en Sede» y Relevo de Fin de Turno / Ausencias L1 (`D4`, `C1`, `QA-CONST-03`, Art. V.4)

* **Criterio EARS 04.1 (Cálculo y exposición de «🤝 Coincidencia en Sede» en vistas móviles — D4 y QA-CONST-03):**
  1. **CUANDO** un Técnico SAT consulte su hoja de ruta (`GET /api/technician/my-route` o paradas activas) o un Reponedor consulte la suya (`GET /api/restocker/my-route`) **Y** el operario consultante tenga turno abierto hoy (`status IN ('ON_SHIFT', 'MEAL_BREAK')` en `technician_shifts`), **EL SISTEMA DEBERÁ** calcular para cada sede (`location_id`) de su ruta el bloque `site_coincidence`:
     * Para el **Técnico SAT**: lista los usuarios `'RESTOCKER'` activos con turno abierto hoy (`ON_SHIFT` / `MEAL_BREAK`) que tengan en esa `location_id` un ticket `L1_RESTOCKER` activo (`ASSIGNED`, `IN_PROGRESS`, `REOPENED`) o que cubran la `zone_id` de esa sede (vínculo vivo en `restocker_zone_assignments` y máquinas activas en la sede), exponiendo `user_id`, `name`, `operator_code`, `shift_status` (`ON_SHIFT` | `MEAL_BREAK`), `phone` y `activity_summary`.
     * Para el **Reponedor**: lista los usuarios `'TECHNICIAN'` activos con turno abierto hoy (`ON_SHIFT` / `MEAL_BREAK`) que tengan en esa `location_id` un ticket `L2_SAT` activo (`ASSIGNED`, `IN_PROGRESS`, `REOPENED`) o una orden preventiva activa (`SCHEDULED`, `IN_INSPECTION`), exponiendo `user_id`, `name`, `operator_code`, `shift_status`, `phone` y `activity_summary`.
  2. **EL SISTEMA DEBERÁ** omitir por completo `site_coincidence` en todos los endpoints del Portal de Sede, canal público QR y albaranes A4 (**`QA-CONST-03`**, **Art. V.4**).
* **Criterio EARS 04.2 (Bloqueo *fail-fast* en `IN_PROGRESS` y relevo automático de avisos `L1_RESTOCKER` al cerrar turno, iniciar ausencia o dar de baja — C1):**
  1. **SI** un usuario `'RESTOCKER'` intenta cerrar su turno (`POST /api/restocker/shifts/close` o endpoint unificado de cierre de turno) teniendo uno o más tickets con `dispatch_level = 'L1_RESTOCKER'`, `assigned_technician_id = restocker.id` y `status = 'IN_PROGRESS'`, **ENTONCES EL SISTEMA DEBERÁ** rechazar la operación con **`409 Conflict` (`SHIFT_CLOSE_BLOCKED_IN_PROGRESS_L1`)** devolviendo la lista de códigos de ticket bloqueantes.
  2. **CUANDO** un usuario `'RESTOCKER'` cierre su turno válidamente, se le registre una ausencia vigente en `technician_absences` o se desactive su cuenta (`is_active = FALSE`), **EL SISTEMA DEBERÁ** recorrer en la misma transacción todos sus tickets vivos en `dispatch_level = 'L1_RESTOCKER'` y `status IN ('ASSIGNED', 'PENDING_INFO')`:
     * Cerrar su tramo en `incident_assignments` (`unassignment_reason_type = 'RESTOCKER_SHIFT_HANDOVER'` en cierre de turno o `'ABSENCE_COVERAGE_REASSIGN'` en ausencia/baja).
     * Evaluar `RestockerZoneCoverageService` para la zona de la sede excluyendo al reponedor saliente: si existe otro reponedor activo con turno abierto hoy (Niveles 1 a 4, `ON_SHIFT` o `MEAL_BREAK`), reasignarle el ticket (`assigned_technician_id = nextRestocker.id`, tramo abierto con `assignment_source = 'RESTOCKER_SHIFT_HANDOVER'` o `'ABSENCE_COVERAGE_REASSIGN'`); si no hay ningún otro reponedor en turno activo en la zona, devolver el ticket a `status = 'REGISTERED'` con `assigned_technician_id = NULL` (manteniendo `dispatch_level = 'L1_RESTOCKER'`).

---

### RF-05 · Cola Offline PWA del Reponedor, Superficies de Interfaz y Métricas (`C2`, `QA-CON-02`, Art. III.3, Art. IV, Art. V.4)

* **Criterio EARS 05.1 (Idempotencia `client_mutation_id` en `offline_sync_mutations` y Cola Offline en `IndexedDB` — C2 y Art. III.3):**
  1. **EL SISTEMA DEBERÁ** ampliar `chk_offline_sync_operation_type` en la Tabla 34 `offline_sync_mutations` con las operaciones `'START_L1_INCIDENT'`, `'RESOLVE_L1_INCIDENT'` y `'ESCALATE_INCIDENT_TO_SAT'`, registrando cada mutación sincronizada con `(technician_id = restocker.id, client_mutation_id)` único y devolviendo `idempotent_replay = true` (`200 OK`) ante cualquier reenvío del mismo `client_mutation_id`.
  2. **SI** al sincronizar en diferido desde `IndexedDB` una mutación de Nivel 1 o escalado resulta rechazada con `409 Conflict` (ej. el ticket ya fue resuelto/cancelado o auto-escalado por QR mientras el reponedor estaba sin red, o la máquina entró en cuarentena), **EL SISTEMA DEBERÁ** marcar el ítem en conflicto en la cola móvil y permitir su volcado auditado en `audit_log` (`POST /api/restocker/incidents/conflict-dump`, **Art. III.3**).
* **Criterio EARS 05.2 (Superficie móvil en `RestockerRouteView.js` y `TechnicianRouteView.js` — D3, D4, C2):**
  1. **En `RestockerRouteView.js`:**
     * Sección **«📦 Avisos Operativos de Nivel 1 Asignados»** (y badge en la tarjeta de cada máquina que tenga un ticket `L1_RESTOCKER`), con botones para **«▶️ Iniciar Intervención L1»**, **«✅ Resolver Nivel 1»** (selector 1-Tap de las 4 causas/soluciones de Nivel 1 + diagnóstico y acción $\ge 20$ chars) y **«⚡ Escalar Avería al SAT»**.
     * Botón directo **«⚡ Escalar Avería al SAT»** en cada tarjeta de máquina de su ruta (y dentro del asistente de Visita de Reposición del Módulo 34) para reportar averías descubiertas a puerta abierta con selector de categoría, foto y Nota Interna de Taller (`is_internal = TRUE`).
     * Banner **«🤝 Coincidencia en Sede»** cuando `site_coincidence` contiene técnicos SAT activos en la misma sede.
  2. **En `TechnicianRouteView.js`:**
     * Insignia **«⚡ Escalado por Reponedor In Situ»** en las tarjetas de parada cuyo ticket tenga `escalated_from_restocker_id IS NOT NULL`, destacando en la vista rápida la Nota Interna y foto dejadas por el reponedor.
     * Banner **«🤝 Coincidencia en Sede»** cuando `site_coincidence` contiene reponedores activos en la misma sede.
* **Criterio EARS 05.3 (Trazabilidad en Horas `TechnicianWorkHoursService`, Panel de Coordinación y Pareto — D2, C2, QA-CON-02):**
  1. **EL SISTEMA DEBERÁ** contabilizar en `TechnicianWorkHoursService` (Módulo 22) las incidencias `L1_RESTOCKER` resueltas por cada `RESTOCKER` (`resolved_interventions_count` e `intervention_seconds = resolved_at - started_at`), sumándose a su actividad de ruta sin colisionar con las métricas de `TECHNICIAN` (`QA-CON-02`).
  2. **EL SISTEMA DEBERÁ** exponer en el listado de incidencias de Coordinación (`GET /api/coordinator/incidents`) el filtro `?dispatch_level=` (`ALL` | `L1_RESTOCKER` | `L2_SAT`), las insignias de nivel operativo (`📦 Nivel 1 · Reponedor` vs. `🔧 Nivel 2 · SAT`) y de escalado (`⚡ Escalado por Reponedor`), y permitir en el modal de Triaje alternar entre sugerencia de Reponedor (`RestockerZoneCoverageService`) y sugerencia de Técnico SAT (`TechnicianAssignmentSuggester`).

---

## 5. Requisitos No Funcionales (RNF)

* **RNF-01 · Integridad Relacional y Prohibición de Borrado Físico (Art. III.1 y III.3):** La evolución de `incidents`, `incident_assignments`, `incident_comments` y `offline_sync_mutations` mantiene todos los disparadores `prevent_hard_delete()` e inmutabilidad de tramos cerrados en `incident_assignments`. Ninguna tabla adicional innecesaria se añade al esquema (permaneciendo en **49 tablas** y pasando de **42 a 44 tipos `ENUM` nativos**, **Art. IV**).
* **RNF-02 · Defensa en Profundidad Sanitaria y de Privacidad (Art. II, Art. V.1, Art. V.4 y Art. V.5):**
  1. La restricción `chk_incidents_l1_dispatch_coherence` en PostgreSQL impide en el motor que un ticket `'TEMPERATURE_COLD'` o `'ELECTRICAL_OFF'` tenga `dispatch_level = 'L1_RESTOCKER'` (**Art. II**).
  2. Las notas de escalado del reponedor se persisten siempre con `is_internal = TRUE` y longitud mínima validada ($\ge 20$ caracteres reales con $\ge 3$ bloques alfanuméricos, **Art. V.1 y V.4**), y las fotos se validan en servidor por magic bytes reales y límite de $5\text{ MB}$ (**Art. V.5**).
  3. Los DTOs públicos y de sede jamás serializan `dispatch_level`, `level1_issue_type`, `escalated_from_restocker_id`, notas internas ni `site_coincidence` (**Art. V.4**).
* **RNF-03 · Fidelidad al Sistema de Diseño (`docs/design.md`) y Dogma Vanilla (Art. IV):** Implementación 100 % en PHP 8.2+ con tipado estricto y Vue 3 ES Modules nativos sin librerías externas, respetando la botonera y tokens `vg-*` y el viewport móvil de `360px` sin scroll horizontal.

---

## 6. Casos Límite

1. **Absorción QR de fallo térmico sobre un ticket `L1_RESTOCKER` en curso (`QA-LIM-01`, Art. II):** Una máquina `COMBO` tiene un aviso `L1_RESTOCKER` por *"Sin vasos"* (`MISSING_CONSUMABLES`) asignado al reponedor `REP-01`. Minutos después, un usuario escanea el QR y reporta `TEMPERATURE_COLD` (*"Los sándwiches están calientes"*). `QrReportService::mergeIntoExistingIncident()` absorbe el reporte, detecta que la categoría entrante es `'TEMPERATURE_COLD'` y auto-escala el ticket en el acto a `dispatch_level = 'L2_SAT'` y urgencia `'CRITICAL'` (240 min), cierra la custodia de `REP-01` (`QR_AUTO_ESCALATED_TO_SAT`), asigna automáticamente al Técnico SAT de guardia/zona (`QR_AUTO_ESCALATION_TO_SAT`) y encola la alerta de frío en `notification_outbox`.
2. **Escalado a puerta abierta cuando la máquina ya tenía un ticket `L2_SAT` abierto en paralelo (`QA-CONST-02`, Art. V.2 y V.3):** El reponedor abre una máquina de snacks para reponer y ve el motor de la espiral 24 suelto; pulsa «⚡ Escalar Avería al SAT» con foto y nota interna. Dos minutos antes, la sede ya había abierto un ticket `L2_SAT` en esa máquina que fue asignado al técnico `TEC-01`. El servicio bloquea la máquina con `FOR UPDATE`, detecta el ticket `L2_SAT` activo, no lanza error de duplicado ni le quita la custodia a `TEC-01`: anexa la foto y la Nota Interna (`is_internal = TRUE`) al expediente de `TEC-01` (`merged_into_existing_sat_ticket = true`) y le notifica la nueva evidencia a puerta abierta.
3. **Reapertura en garantía ($< 48\text{ h}$) de un aviso resuelto por el Reponedor (`QA-LIM-02`, Art. V.6):** El reponedor resuelve un `MINOR_SPIRAL_JAM` en `L1_RESTOCKER`. A las 5 horas, el responsable de sede reabre la incidencia porque el mismo carril volvió a atascarse. El sistema detecta que el ticket procedía de `L1_RESTOCKER` y lo promueve automáticamente a `dispatch_level = 'L2_SAT'` para que intervenga un Técnico SAT especialista.
4. **Intento de cerrar turno del Reponedor con un aviso `L1_RESTOCKER` en `IN_PROGRESS` vs. `ASSIGNED` (`C1`):** Si `REP-01` tiene el ticket `INC-2026-0090` en `'IN_PROGRESS'` y pulsa «Cerrar Turno», recibe `409 SHIFT_CLOSE_BLOCKED_IN_PROGRESS_L1`. Una vez que lo resuelve (o lo escala al SAT), vuelve a pulsar «Cerrar Turno»: sus otros tickets `L1_RESTOCKER` que seguían en `'ASSIGNED'` se traspasan automáticamente al reponedor suplente `BACKUP` en turno de la zona (o pasan a `'REGISTERED'` en `L1_RESTOCKER` si no hay otro reponedor en turno) y el turno se cierra limpiamente.

---

## 7. Fuera de Alcance

Quedan explícitamente excluidos del alcance de este Módulo 36 (reservados a las Features 27, 28 y Bloque 5 según el Art. VI):
1. **Planificador de frecuencias periódicas de carga (`machine_restock_schedules` L-M-V / M-J / diaria) y ordenación cartográfica automática de paradas de carga:** Reservado a la **Feature 27**.
2. **Cuadro de mando dedicado de BI de reposición (*Stockout Rate %*, comparativa de mermas comerciales vs. térmicas):** Reservado a la **Feature 28**.
3. **Detección telemática desatendida de falta de cambio o atascos por bus MDB/EVA-DTS (DEX):** Reservado al **Bloque 5 (Feature 18 · IoT)**.

---

## 8. Criterios de Finalización (Definition of Done)

1. Existe la migración `032_restocker_sat_synergy.sql` y su convergencia 1:1 en `database/supabase_init.sql` y `database/supabase_seeds.sql` con los tipos #43 (`incident_dispatch_level`) y #44 (`level1_issue_type`), la ampliación de `comment_author_type`, `incident_assignment_source`, `incident_unassignment_reason_type` y `chk_offline_sync_operation_type`, las columnas en `incidents` con `chk_incidents_l1_dispatch_coherence` (`Art. II`) y las 4 causas raíz + soluciones semilla de Nivel 1 (`QA-AMB-01`).
2. Los servicios de aplicación imponen la pre-clasificación `L1_RESTOCKER` (`D2`), la sugerencia de reponedor por zona (`RestockerZoneCoverageService`), la resolución de Nivel 1 sin repuestos ni albarán A4 (`D3`), el escalado atómico «⚡ Escalar Avería al SAT» en sus 3 ramas con `TechnicianAssignmentSuggester` y `notification_outbox` (`D3`, `QA-CONST-02`), el auto-escalado en absorción QR grave (`QA-LIM-01`) y en reapertura $< 48\text{ h}$ (`QA-LIM-02`), el cálculo de `site_coincidence` (`D4`), el relevo automático de fin de turno/ausencia L1 (`C1`), el cómputo en `TechnicianWorkHoursService` (`C2`, `QA-CON-02`) y la opacidad frente a Sede/QR (`Art. V.4`).
3. `RestockerRouteView.js`, `TechnicianRouteView.js` y las vistas de Coordinación implementan la operativa completa de Nivel 1, el botón «⚡ Escalar Avería al SAT», el banner «🤝 Coincidencia en Sede», la cola Offline `IndexedDB` con `client_mutation_id` y el filtro/triaje dual `L1_RESTOCKER` / `L2_SAT`.
4. Toda la batería de pruebas unitarias, de integración PostgreSQL/HTTP, de frontend y de blindaje constitucional pasa al **100 % en verde**.

---

## 9. Dudas Abiertas

* Ninguna. Las decisiones `D1`–`D4`, `C1`–`C2` y las 9 salvaguardas de auditoría QA han sido consensuadas y aprobadas por el Product Owner.
