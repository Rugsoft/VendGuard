# ESPECIFICACIÓN FUNCIONAL: CUADRO DE MANDO DE REPOSICIÓN, ROTURAS DE STOCK Y MERMAS COMERCIALES (`RESTOCKING BI, STOCKOUT RATE & MERCHANDISING ANALYTICS`)

**Proyecto:** Gestor de Incidencias de Vending (*VendGuard*)  
**Módulo:** 38 · Cuadro de Mando de Reposición, Roturas de Stock y Mermas Comerciales  
**Documento Canónico:** `specs/38-restocking-bi-analytics/spec.md` (y su espejo `specs/functional/restocking_bi_analytics_spec.md`)  
**Versión:** 1.1 · Incorpora las 5 Decisiones de Diseño (`D1`–`D5`), los 6 Casos Límite (`C1`–`C6`) y las 5 Resoluciones de Auditoría QA (`QA-1`–`QA-5`)  
**Estado:** Especificación Funcional Aprobada por el Product Owner el 2026-11-24 · Puerta de Aprobación SDD superada (Fase 2)  
**Fecha:** Noviembre 2026  
**Origen:** Funcionalidad 28 del catálogo [`docs/features_pendientes.md`](../../docs/features_pendientes.md) (Bloque 4 · Operativa de Carga, Reposición de Producto y Gestión de Reponedores)  
**Alineación Constitucional:** Art. I (supremacía de la especificación SDD e inadmisibilidad de código simulado o *mocks* en flujos reales), Art. II (prioridad sanitaria absoluta: las visitas `ABORTED_THERMAL_BREACH` quedan fuera del denominador de disponibilidad de stock y se exponen en contador/alerta sanitaria separada; las mermas por avería térmica APPCC del Módulo 29 mantienen visibilidad independiente frente a las mermas comerciales y ordenación prioritaria en máquinas bajo Art. II.2), Art. III.1, III.2 y III.3 (explotación exclusiva de registros históricos inmutables; inclusión permanente de entidades con baja lógica `is_active = FALSE` o `deleted_at IS NOT NULL` que registren actividad en el periodo con insignia *«Baja»*; auditoría de exportaciones CSV bajo `EXPORT_RESTOCKING_BI_CSV`), Art. IV (minimalismo tecnológico: cero tablas nuevas y cero migraciones DDL manteniendo el esquema en 51 tablas y 50 tipos `ENUM` nativos; backend PHP 8.2+ POO puro con `declare(strict_types=1);` y PDO; frontend Vue 3 ESM nativo con `window.Chart` ya precacheado y tokens CSS computados sin dependencias externas), Art. V.4 (acceso exclusivo para `COORDINATOR`; opacidad total de costes unitarios, mermas económicas, exactitud de arqueos y rendimiento individual frente a `RESTOCKER`, `TECHNICIAN`, `LOCATION_RESPONSIBLE`, canal público QR e informes externos; descarga CSV autenticada exclusivamente vía `fetch` + `Blob` con aviso de confidencialidad), y Art. VI (delimitación sagrada del alcance excluyendo comparativas periodo-vs-periodo, acoplamientos con `CoordinatorPlanogramTab.js`, estimación de venta perdida IoT Bloque 5, Informe Ejecutivo A4 y métricas operativas de los Módulos 33 y 37).

---

## 1. Contexto y Objetivo

### 1.1. El Problema (Por qué)

Con los Módulos 31 a 37 implantados, la operativa diaria de reposición genera un volumen continuo de datos inmutables sobre visitas en máquina (Módulo 34), recaudaciones y arqueos ciegos (Módulo 35), incidencias de Nivel 1 resueltas o escaladas al SAT (Módulo 36), jornadas de ruta (Módulos 22 y 31) y actas sanitarias APPCC (Módulo 29). Sin embargo, el Coordinador de Operaciones carece de una consola analítica agregada que responda cuatro preguntas estratégicas de negocio:

1. **¿Qué disponibilidad real ofrece el surtido en ruta?** No existe visibilidad consolidada sobre qué porcentaje de visitas encuentra todos los carriles con producto disponible frente a cuántas máquinas y referencias sufren roturas de stock (`qty_before = 0`) o caídas bajo umbral mínimo.
2. **¿Por qué se pierde dinero en mermas?** Hoy no se comparan en una misma vista las mermas comerciales registradas en ruta —separando caducidad FEFO (`qty_withdrawn_expired`) frente a envases dañados (`qty_withdrawn_damaged`)— contra las destrucciones por fallo técnico de cadena de frío (Módulo 29), impidiendo distinguir problemas de rotación comercial de averías frigoríficas.
3. **¿Qué planogramas están sobredimensionados?** Aunque los carriles acumulan retiradas reiteradas por caducidad FEFO, Coordinación no dispone de un ranking determinista ni de alertas objetivas para reducir capacidades máximas o frecuencias de carga antes de que los alimentos frescos caduquen sin venderse.
4. **¿Cuál es el desempeño operativo y contable de la plantilla de reposición?** Falta cruzar en un único cuadro de mando las máquinas abastecidas por turno válido, el tiempo medio en parada, las resoluciones L1 efectivas, los escalados tempranos al SAT y la exactitud de los arqueos cerrados en caja central.

### 1.2. El Objetivo (Qué)

Construir el **Cuadro de Mando de Reposición, Roturas de Stock y Mermas Comerciales (Módulo 38)** como un motor BI de solo lectura, sin migraciones DDL, estructurado en cuatro pilares:

| Pilar | Capacidad Entregada |
| :--- | :--- |
| **1. Disponibilidad Dual y Segregación Sanitaria (`D1`, Art. II)** | Medición de *In-Stock / Stockout Rate* a nivel de visita `COMPLETED` como KPI principal (inmune al guardado parcial de líneas) y desglose por carriles auditados en `0` y bajo umbral en tablas por máquina y producto, manteniendo `ABORTED_THERMAL_BREACH` fuera del denominador de stock. |
| **2. Comparativa de Mermas Comercial vs. Térmica (`D2`, Art. II, Art. V.4)** | Desglose monetario y en unidades de la merma comercial (Caducidad FEFO frente a Dañado en ruta con snapshot `unit_cost_snapshot_eur`), confrontada con la merma térmica APPCC (Módulo 29) en vistas de parque, zona o sede, y bloqueo estadístico explícito cuando se filtra por un reponedor individual. |
| **3. Analítica de Merchandising y Sobredimensionamiento (`D3`)** | Ranking completo de máquinas y referencias por rotación y merma FEFO con insignia determinista **«Planograma sobredimensionado»** (`tasa_caducidad >= 15,00 %` con suelo anti-ruido de `>= 2` visitas completadas y `>= 3` unidades caducadas, excluyendo dañados). |
| **4. Rendimiento por Reponedor, Gráficos Chart.js y CSV (`D4`, `D5`, `C3`, `C4`)** | Tabla operativa por reponedor (máquinas por turno válido, tiempo medio real de parada, L1 resueltas efectivas, escaladas al SAT y exactitud de arqueos cerrados), 2 gráficos locales con `window.Chart`, sub-pestaña propia en `CoordinatorMetricsView.js` y exportación CSV multi-sección `UTF-8 BOM` anti-fórmulas. |

---

### 1.3. Resumen de Decisiones de Diseño (`D1`–`D5`), Casos Límite (`C1`–`C6`) y Resoluciones QA (`QA-1`–`QA-5`)

| Clave | Decisión Consensuada | Regla Operativa Concreta |
| :--- | :--- | :--- |
| **`D1`** | **In-Stock / Stockout Rate dual** | KPI principal sobre visitas `COMPLETED`: `visit_in_stock_rate_pct` (% de visitas sin ningún carril con `qty_before = 0`; las visitas `COMPLETED` con 0 líneas cuentan como *In-Stock*). Desglose por máquina y producto/SKU: `audited_slots_count`, `stockout_slots_count` (`qty_before = 0`), `slot_stockout_rate_pct` y `low_threshold_slots_count` (`0 < qty_before <= min_threshold` con producto coincidente). `ABORTED_THERMAL_BREACH` queda fuera del denominador y se contabiliza aparte. |
| **`D2`** | **Comparativa de Mermas (Comercial vs. Térmica)** | Comercial (M34, visitas `COMPLETED`): **Caducidad FEFO** (`qty_withdrawn_expired * unit_cost_snapshot_eur`) + **Dañado en ruta** (`qty_withdrawn_damaged * unit_cost_snapshot_eur`), igualando exactamente `commercial_spoilage_loss_eur`. Térmica APPCC (M29 `sanitary_spoilage_records`): comparada en fechas/zona/sede (`segmentable = true`); si `restocker_id != null`, la térmica pasa a `segmentable = false`, valores `null` y aviso explícito *«No segmentable por reponedor (ámbito máquina/SAT)»*. |
| **`D3`** | **Detección de Planograma Sobredimensionado** | Ranking completo + insignia `is_oversized_planogram = true` cuando `expiry_rate_pct = ROUND(expired_units / (added_units + expired_units) * 100, 2) >= 15.00`, `completed_visits_count >= 2` y `expired_units >= 3` en el periodo. Excluye `qty_withdrawn_damaged`. Si `added_units + expired_units = 0`, `expiry_rate_pct = null`. |
| **`D4`** | **Ubicación y alcance exacto en UI** | Sub-pestaña **«📦 Métricas de Carga y Reposición»** (`restocking-bi`) dentro de `CoordinatorMetricsView.js` (componente `CoordinatorRestockingBiTab.js`), con barra de filtros propia (`from`, `to`, `zone_id`, `location_id`, `restocker_id`), exclusiva de `COORDINATOR` y ocultando la barra superior global de SAT mientras esté activa. Intactos `ExecutiveReportModal.js`, `CoordinatorDashboardView.js`, `CoordinatorPlanogramTab.js`, M33 y M37. |
| **`D5`** | **Gráficos Chart.js y CSV multi-sección** | Tarjetas KPI + 2 gráficos `window.Chart` leyendo tokens CSS vía `getComputedStyle` (degradación limpia a aviso si `window.Chart` no está disponible): (1) Comparativa de Mermas (conmutador `€` / `Unidades`) y (2) Top 10 máquinas o productos críticos (selector Rotura de Stock / Caducidad FEFO), más 3 tablas y descarga CSV autenticada (`fetch` + `Blob`) `UTF-8 BOM` (`;`) anti-fórmulas con aviso de confidencialidad Art. V.4. |
| **`C1`** | **Fechas de corte (`Europe/Madrid`)** | Días naturales inclusivos `[from 00:00:00, to+1d 00:00:00)` en `Europe/Madrid` siempre por fecha del hecho operativo en campo: `completed_at` en visitas M34, `collected_at` en arqueos M35, `created_at` en actas M29, `resolved_at` / `escalated_to_sat_at` en L1 M36 y `started_at` en turnos M22/M31. |
| **`C2`** | **Históricos, bajas lógicas y cero DDL** | Entidades con baja lógica (`is_active = FALSE` o `deleted_at IS NOT NULL`) que tengan datos en el periodo entran siempre en cálculos, tablas y `available_filters` con etiqueta *«Baja»* (Art. III). `sede/zona` y `min_threshold` se leen desde su estado vigente en maestras (documentado). Cero tablas nuevas y cero migraciones DDL (51 tablas / 50 tipos `ENUM` intactos). Auditoría CSV usando `AuditEvent::ENTITY_MACHINE_RESTOCK_VISIT` (`entity_id = 0`, acción `EXPORT_RESTOCKING_BI_CSV`). |
| **`C3`** | **Rendimiento por reponedor (turnos, parada, arqueos)** | `machines_per_shift = ROUND(completed_visits_count / valid_shifts_count, 2)` en `technician_shifts` excluyendo anulados (`status = 'OFF_SHIFT' AND closed_at IS NOT NULL AND closed_at = started_at`); devuelve `null` (`—`) si `valid_shifts_count = 0` o si `zone_id` / `location_id` están activos. `avg_stop_duration_minutes`: promedio real de `completed_at - started_at` en visitas `COMPLETED` sin recorte de outliers. Exactitud M35 sobre bolsas cerradas (`RECONCILED_OK` vs. `DISCREPANCY_FLAGGED`, incluidas recaudaciones independientes `restock_visit_id IS NULL`), con `SEALED_IN_ROUTE` y `DELIVERED_TO_VAULT` en `pending_audit_count`. |
| **`C4`** | **Sinergia Nivel 1 (M36)** | Sobre estado vigente en `incidents` (`deleted_at IS NULL`): **L1 resueltas efectivas** (`dispatch_level = 'L1_RESTOCKER' AND status IN ('RESOLVED', 'CLOSED')` por personal `RESTOCKER`) + columna separada **Escaladas al SAT** (`escalated_from_restocker_id`). |
| **`C5`** | **Errores, validación y periodos vacíos** | RBAC: `401` sin sesión, `403` resto de roles. Validación fail-fast: `400 INVALID_FILTER_PARAMS` si falta una fecha del par `from`/`to`, formato inválido o fecha imposible, `from > to`, rango `> 366` días naturales inclusivos o IDs no enteros positivos (`to > today` permitido). Filtro válido sin datos: `200 OK` vacío coherente (`null`/`—` en tasas sin denominador, nunca `0 %` ni `100 %`) y CSV descargable con cabeceras. Error `500` sin filtrar trazas SQL. |
| **`C6`** | **Fronteras explícitas (*Non-Goals*)** | Fuera del alcance: comparativas `vs. periodo anterior`, saltos acoplados a `CoordinatorPlanogramTab.js`, estimación de venta perdida en `€` por stockout, edición de planogramas, Informe A4 (`ExecutiveReportModal.js`), métricas M33/M37, vistas fuera de `COORDINATOR` y cachés o vistas materializadas DDL. |

#### Resoluciones de Auditoría QA (`QA-1`–`QA-5`)

| Clave | Problema Auditado | Resolución Definitiva Acordada |
| :--- | :--- | :--- |
| **`QA-1`** | Tensión Art. III.3 vs. Art. IV en `audit_entity_type` para la exportación CSV. | Se reutiliza `AuditEvent::ENTITY_MACHINE_RESTOCK_VISIT` (`entity_id = 0`) con acción `EXPORT_RESTOCKING_BI_CSV` y etiqueta dedicada en `AuditActionLabels.js`, preservando **0 DDL** (51 tablas / 50 `ENUM`) y trazabilidad de descargas económicas. |
| **`QA-2`** | Prevalencia sanitaria en ordenación de `by_machine` (Art. II.2) y actas M29 vacías. | `by_machine` ordena primero por incidencia sanitaria activa (`(thermal_aborted_visits_count > 0 OR COALESCE(thermal_spoilage_units, 0) > 0) DESC`), luego `is_oversized_planogram DESC`, `commercial_spoilage_loss_eur DESC`, `stockout_slots_count DESC` y `machine_code ASC`. En M29, `records_count` cuenta **todas** las actas del periodo (incluidas `empty_machine_confirmed = TRUE` y los 3 valores de `quarantine_origin`). |
| **`QA-3`** | Doble barra de filtros y doble botón CSV en `CoordinatorMetricsView.js`. | La barra superior global de SAT se oculta automáticamente mientras `activeSubTab === 'restocking-bi'` (`v-if="activeSubTab !== 'restocking-bi'"`), evitando selectores de fecha y botones CSV duplicados, especialmente en `360 × 800 px`. |
| **`QA-4`** | Inclusión contable en `by_machine` / `by_restocker` y estabilidad de `available_filters`. | `by_machine` incluye toda máquina con $\ge 1$ visita (`COMPLETED` o `ABORTED_THERMAL_BREACH`) o $\ge 1$ acta M29 (si `restocker_id = null`). `by_restocker` sin filtro territorial incluye todo reponedor con visita, arqueo, L1/escalado o `valid_shifts_count > 0`; con filtro territorial, solo quienes tienen actividad territorial. `available_filters` devuelve activos + inactivos con actividad en `(from, to)` + la entidad actualmente seleccionada si existe en BD. |
| **`QA-5`** | Fechas futuras (`to > today`), coherencia de `l1_resolved_count` y cambio de producto en carril. | Se admite `to > today` (`200 OK`, manteniendo `from <= to` y `<= 366` días). `summary.l1_resolved_count` equivale exactamente a `SUM(by_restocker[].l1_resolved_count)` (solo usuarios `role = 'RESTOCKER'`). `low_threshold_slots_count` exige `mps.product_id = mvi.product_id` para evitar falsos positivos tras cambiar el producto del carril. |

---

## 2. Usuarios y Roles Implicados (Matriz de Privacidad Art. V.4)

| Usuario / Rol | Relación con el Módulo | Reglas de Privacidad y Permisos (Art. V.4) |
| :--- | :--- | :--- |
| **📊 Coordinador de Operaciones (`COORDINATOR`)** | Consulta la sub-pestaña **«📦 Métricas de Carga y Reposición»**, filtra por rango de fechas, zona operativa, sede y reponedor, inspecciona los KPIs, gráficos Chart.js y tablas, y exporta el informe CSV multi-sección. | Único rol autorizado para acceder a `GET /api/coordinator/metrics/restocking` y `GET /api/coordinator/metrics/restocking/export`. Toda exportación queda auditada en `audit_log` (Art. III.3). |
| **📦 Cargador / Reponedor de Ruta (`RESTOCKER`)** | Sin acceso al módulo BI. | **Bloqueo RBAC (`403 FORBIDDEN`):** no puede consultar comparativas entre compañeros, importes de merma ni ratios agregados de arqueo. |
| **🔧 Técnico de Campo SAT (`TECHNICIAN`)** | Sin acceso al módulo BI. | **Bloqueo RBAC (`403 FORBIDDEN`):** los endpoints de métricas de reposición permanecen inaccesibles para el rol técnico. |
| **🏢 Responsable de Sede (`LOCATION_RESPONSIBLE`) y Canal Público QR** | Ninguna interacción con el módulo. | **Opacidad total (Art. V.4):** ninguna vista o endpoint de Portal de Sede, QR público ni albarán A4 expone tasas de stockout, costes unitarios, mermas económicas, exactitud de arqueos ni métricas de plantilla. |

---

## 3. Historias de Usuario

### HU-01: Medición Fiable de Disponibilidad y Roturas de Stock
> **Como** Coordinador de Operaciones (`COORDINATOR`),  
> **quiero** conocer el porcentaje de visitas completadas sin carriles vacíos junto al desglose de carriles auditados en `0` y bajo umbral mínimo por máquina y producto, separando las visitas abortadas por rotura térmica,  
> **para** detectar con precisión qué máquinas y referencias requieren ajuste de frecuencia o dotación sin que el guardado parcial de líneas ni las incidencias sanitarias distorsionen el indicador.

### HU-02: Comparativa Rigurosa de Mermas Comerciales frente a Averías Térmicas
> **Como** Coordinador de Operaciones (`COORDINATOR`),  
> **quiero** comparar en euros y en unidades la merma comercial de ruta (diferenciando caducidad FEFO de producto dañado) frente a la merma sanitaria térmica registrada en el Módulo 29 al filtrar por parque, zona o sede, y visualizar únicamente la merma comercial cuando filtro por un reponedor concreto,  
> **para** atribuir correctamente las pérdidas a rotación comercial, manipulación o fallo técnico de frío sin mezclar denominadores incompatibles.

### HU-03: Identificación de Planogramas Sobredimensionados
> **Como** Coordinador de Operaciones (`COORDINATOR`),  
> **quiero** consultar un ranking de máquinas y productos con su tasa de caducidad FEFO (priorizando arriba las incidencias sanitarias activas conforme al Art. II.2) y una alerta automática clara cuando una máquina supere el `15,00 %` de caducidad con actividad mínima suficiente (`>= 2` visitas y `>= 3` unidades caducadas),  
> **para** redimensionar los carriles de alimentos frescos y frenar el desperdicio recurrente.

### HU-04: Evaluación Operativa, Nivel 1 y Fiabilidad de Arqueos por Reponedor
> **Como** Coordinador de Operaciones (`COORDINATOR`),  
> **quiero** analizar por cada reponedor sus visitas completadas, máquinas abastecidas por turno válido, tiempo medio real de parada, avisos L1 resueltos de forma efectiva, escalados al SAT y exactitud de arqueos sobre bolsas cerradas,  
> **para** supervisar el rendimiento en ruta, la capacidad de contención L1 y la fiabilidad de la custodia del efectivo.

### HU-05: Exportación CSV Auditable y Resiliencia en Periodos Vacíos
> **Como** Coordinador de Operaciones (`COORDINATOR`),  
> **quiero** descargar de forma autenticada un informe CSV completo compatible con Excel español (`UTF-8 BOM`, `;`, protegido contra inyección de fórmulas y marcado como confidencial) incluso cuando el filtro no arroje resultados, y contar con una interfaz responsiva usable en `360 px` sin controles duplicados ni colapsos si falta la librería gráfica,  
> **para** documentar auditorías de explotación y trabajar desde cualquier dispositivo con garantías.

---

## 4. Requisitos Funcionales (RF) y Criterios de Aceptación (EARS)

### RF-01 · Filtrado, Fechas de Corte, Validación e Históricos (`C1`, `C2`, `C5`, `QA-4`, `QA-5`, Art. III, Art. IV)

* **Criterio EARS 01.1 (Ventana temporal por defecto, zona horaria y fechas de corte):**
  1. **CUANDO** se consulte el informe sin parámetros `from` ni `to`, **EL SISTEMA DEBERÁ** aplicar por defecto la ventana de los últimos `30` días naturales en `Europe/Madrid` (desde `today - 29 days` hasta `today`, ambos inclusive).
  2. **EL SISTEMA DEBERÁ** convertir cada filtro `[from, to]` al intervalo semiabierto `[from 00:00:00, (to + 1 día) 00:00:00)` en `Europe/Madrid` y evaluar cada entidad origen exclusivamente por la marca temporal de su hecho operativo en campo:
     * Visitas e ítems de reposición (M34): `machine_restock_visits.completed_at`.
     * Arqueos de efectivo (M35): `machine_cash_collections.collected_at`.
     * Actas sanitarias térmicas (M29): `sanitary_spoilage_records.created_at`.
     * Incidencias L1 resueltas (M36): `incidents.resolved_at`; escalados al SAT (M36): `incidents.escalated_to_sat_at`.
     * Turnos válidos (M22/M31): `technician_shifts.started_at`.

* **Criterio EARS 01.2 (Validación fail-fast de filtros y admisión de `to > today` — `QA-5`):**
  1. **SI** el cliente envía solo uno de los parámetros `from` o `to`, **EL SISTEMA DEBERÁ** responder `400 INVALID_FILTER_PARAMS`.
  2. **SI** `from` o `to` no cumplen exactamente el formato `YYYY-MM-DD` o representan una fecha civil inexistente (ej. `2026-02-30`), o si `from > to`, o si el rango inclusivo supera `366` días naturales (`(to - from) + 1 > 366`), **EL SISTEMA DEBERÁ** rechazar la petición con `400 INVALID_FILTER_PARAMS`. Las fechas con `to > today` quedan expresamente admitidas (`200 OK`) siempre que cumplan las cotas anteriores (`QA-5`).
  3. **SI** cualquiera de los parámetros opcionales `zone_id`, `location_id` o `restocker_id` no es un entero estrictamente positivo (`>= 1`), **EL SISTEMA DEBERÁ** rechazar la petición con `400 INVALID_FILTER_PARAMS`.

* **Criterio EARS 01.3 (Históricos de bajas lógicas, `available_filters` y nulabilidad — `QA-4`, Art. III, Art. IV):**
  1. **EL SISTEMA DEBERÁ** incluir en todas las agregaciones y tablas a cualquier máquina, sede, zona operativa, producto o reponedor dado de baja lógica (`is_active = FALSE` o `deleted_at IS NOT NULL`) que registre actividad en el periodo consultado, exponiendo `is_active = false` para mostrar la insignia **«Baja»** en la interfaz y en el CSV.
  2. **EL SISTEMA DEBERÁ** poblar `available_filters` (`zones`, `locations`, `restockers`) con todas las entidades activas del sistema, más las entidades con baja lógica que registren actividad en la ventana `(from, to)`, más la entidad correspondiente al `zone_id`, `location_id` o `restocker_id` actualmente seleccionado en la consulta si existe en base de datos (`QA-4`).
  3. **EL SISTEMA DEBERÁ** resolver la adscripción territorial (`location_id`, `zone_id`) desde el vínculo vigente en `machines` y `locations`, y el umbral de alerta (`min_threshold`) desde el estado vigente de `machine_planogram_slots`, mientras preserva intactos los importes históricos mediante `unit_cost_snapshot_eur` y `commercial_spoilage_loss_eur`, sin crear tablas nuevas ni migraciones DDL (`C2`).
  4. **EL SISTEMA DEBERÁ** tratar `locations.zone_id` (y `zone_name` derivado) y `users.operator_code` como campos anulables (`nullable`), presentando `Sin zona` para sedes sin demarcación asignada (visibles cuando `zone_id = null` y excluidas únicamente al filtrar una zona concreta) y `—` cuando `operator_code` sea nulo.

* **Criterio EARS 01.4 (Filtros válidos sin actividad):**
  1. **CUANDO** los parámetros sean válidos pero no existan registros coincidentes, **EL SISTEMA DEBERÁ** responder `200 OK` con colecciones vacías, contadores enteros en `0`, importes monetarios en `0.00` y todas las tasas o promedios sin denominador en `null` (mostrados como `—` en la interfaz, jamás inventando `0 %` ni `100 %`).

---

### RF-02 · Disponibilidad en Máquina (*In-Stock / Stockout Rate*) (`D1`, `QA-5`, Art. II)

* **Criterio EARS 02.1 (KPI principal a nivel de visita `COMPLETED` con cierre exacto al `100,00 %`):**
  1. **EL SISTEMA DEBERÁ** calcular los indicadores principales de disponibilidad exclusivamente sobre visitas con `status = 'COMPLETED'`:
     * Visita sin rotura (*In-Stock*): aquella que no tiene ninguna fila asociada en `machine_restock_visit_items` con `qty_before = 0` (las visitas `COMPLETED` con `0` líneas registradas —solo higiene o verificación— cuentan como visita sin rotura).
     * Visita con rotura (*Stockout*): aquella con al menos una fila en `machine_restock_visit_items` donde `qty_before = 0`.
     * `visit_in_stock_rate_pct = ROUND(clean_in_stock_visits_count / completed_visits_count * 100, 2)` y `visit_stockout_rate_pct = ROUND(100.00 - visit_in_stock_rate_pct, 2)` para garantizar suma exacta al `100,00 %` sin desfase de redondeo (`null` en ambos si `completed_visits_count = 0`).

* **Criterio EARS 02.2 (Desglose por carriles auditados y bajo umbral con guarda de producto):**
  1. **EL SISTEMA DEBERÁ** calcular por parque, por máquina y por producto/SKU sobre las líneas de `machine_restock_visit_items` pertenecientes a visitas `COMPLETED`:
     * `audited_slots_count`: número total de líneas registradas.
     * `stockout_slots_count`: número de líneas con `qty_before = 0`.
     * `slot_stockout_rate_pct = ROUND(stockout_slots_count / audited_slots_count * 100, 2)` (`null` si `audited_slots_count = 0`).
     * `low_threshold_slots_count`: número de líneas con `qty_before > 0` y `qty_before <= machine_planogram_slots.min_threshold` vigente, exigiendo que `machine_planogram_slots.product_id = machine_restock_visit_items.product_id` (`QA-5`).

* **Criterio EARS 02.3 (Segregación sanitaria de abortos térmicos — Art. II):**
  1. **EL SISTEMA DEBERÁ** excluir toda visita con `status = 'ABORTED_THERMAL_BREACH'` del denominador de `completed_visits_count`, de `visit_in_stock_rate_pct` y de `slot_stockout_rate_pct`, exponiéndola en el contador dedicado `thermal_aborted_visits_count` (global, por máquina y por reponedor) y en una tarjeta/alerta diferenciada.

---

### RF-03 · Análisis Comparativo de Mermas (Comercial FEFO / Dañado vs. Térmica APPCC) (`D2`, `QA-2`, `QA-4`, Art. II, Art. V.4)

* **Criterio EARS 03.1 (Desglose exacto de merma comercial en ruta — M34):**
  1. **EL SISTEMA DEBERÁ** desagregar toda línea de visita `COMPLETED` en:
     * **Caducidad FEFO:** `expired_units = SUM(qty_withdrawn_expired)` y `expired_loss_eur = ROUND(SUM(qty_withdrawn_expired * unit_cost_snapshot_eur), 2)`.
     * **Dañado en ruta:** `damaged_units = SUM(qty_withdrawn_damaged)` y `damaged_loss_eur = ROUND(SUM(qty_withdrawn_damaged * unit_cost_snapshot_eur), 2)`.
     * **Total comercial:** `commercial_spoilage_units = expired_units + damaged_units` y `commercial_spoilage_eur = ROUND(SUM(commercial_spoilage_loss_eur), 2)`, manteniendo equivalencia exacta con `expired_loss_eur + damaged_loss_eur`.

* **Criterio EARS 03.2 (Cruce con merma sanitaria térmica APPCC — M29, regla por reponedor y prioridad sanitaria en `by_machine`):**
  1. **CUANDO** el filtro `restocker_id` sea `null` (consulta global, por zona o por sede), **EL SISTEMA DEBERÁ** calcular desde `sanitary_spoilage_records` (Módulo 29) todas las actas del periodo (incluidas las actas válidas de máquina vacía `empty_machine_confirmed = TRUE` con `0` unidades y `0.00 €`, y cualquiera de los tres valores `quarantine_origin`: `'BIOLOGICAL_CLOCK_4H'`, `'PREVENTIVE_NON_CONFORMITY'`, `'MANUAL_SANITARY_LOCK'`, `QA-2`), exponiendo `thermal_spoilage_units = SUM(discarded_units_count)`, `thermal_spoilage_eur = ROUND(SUM(estimated_merchandise_loss_eur), 2)` y `records_count`, marcando `thermal_haccp.segmentable = true` y sumando `total_spoilage_eur = ROUND(commercial_spoilage_eur + thermal_spoilage_eur, 2)`.
  2. **CUANDO** `restocker_id` esté seleccionado (`restocker_id != null`), **EL SISTEMA DEBERÁ** marcar `thermal_haccp.segmentable = false` con motivo `NOT_SEGMENTABLE_BY_RESTOCKER`, devolver `null` en todos los campos numéricos térmicos globales y por máquina (`thermal_spoilage_units`, `thermal_spoilage_eur`, `records_count`, `total_spoilage_eur`), mostrar en el bloque y gráfico comparativo únicamente la merma comercial del reponedor (Caducidad FEFO y Dañado en ruta) y presentar el aviso explícito **«No segmentable por reponedor (ámbito máquina/SAT)»**.
  3. **EL SISTEMA DEBERÁ** incluir en `by_machine` toda máquina que registre al menos una visita (`COMPLETED` o `ABORTED_THERMAL_BREACH`) o al menos un acta M29 en el periodo (cuando `restocker_id = null`), y ordenar `by_machine` dando precedencia sanitaria absoluta (Art. II.2, `QA-2`): primero máquinas con `thermal_aborted_visits_count > 0 OR COALESCE(thermal_spoilage_units, 0) > 0`, después `is_oversized_planogram DESC`, `commercial_spoilage_loss_eur DESC`, `stockout_slots_count DESC` y `machine_code ASC`.

---

### RF-04 · Merchandising y Detección de Planograma Sobredimensionado (`D3`)

* **Criterio EARS 04.1 (Cálculo de tasa de caducidad comercial excluyendo dañados):**
  1. **EL SISTEMA DEBERÁ** calcular para cada máquina y cada producto/SKU la tasa de caducidad FEFO como:
     $$\text{expiry\_rate\_pct} = \text{ROUND}\left(\frac{\text{expired\_units}}{\text{added\_units} + \text{expired\_units}} \times 100,\; 2\right)$$
     donde `added_units = SUM(qty_added)` y `expired_units = SUM(qty_withdrawn_expired)`, excluyendo expresamente `qty_withdrawn_damaged`.
  2. **CUANDO** `added_units + expired_units = 0`, **EL SISTEMA DEBERÁ** devolver `expiry_rate_pct = null` (`—` en la UI).

* **Criterio EARS 04.2 (Insignia determinista «Planograma sobredimensionado»):**
  1. **EL SISTEMA DEBERÁ** mantener visible el ranking completo de máquinas y asignar `is_oversized_planogram = true` única y exclusivamente cuando se cumplan las tres condiciones simultáneas en el periodo filtrado:
     * `expiry_rate_pct >= 15.00`
     * `completed_visits_count >= 2`
     * `expired_units >= 3`
  2. **EL SISTEMA DEBERÁ** contabilizar en `summary.oversized_machines_count` el número exacto de máquinas en `by_machine` que cumplan los tres criterios.

---

### RF-05 · Rendimiento Operativo por Reponedor, Sinergia L1 y Exactitud de Arqueos (`C3`, `C4`, `QA-4`, `QA-5`, Art. V.4)

* **Criterio EARS 05.1 (Inclusión en `by_restocker`, máquinas por turno válido y tiempo medio en parada):**
  1. **EL SISTEMA DEBERÁ** incluir en `by_restocker`: sin filtro territorial (`zone_id === null && location_id === null`), a todo reponedor con al menos una visita (completada o abortada), recaudación, incidencia L1/escalado **o** turno válido en el periodo; con filtro territorial activo, únicamente a los reponedores con actividad territorial (visita, recaudación o incidencia L1/escalado) en esa zona o sede (`QA-4`).
  2. **EL SISTEMA DEBERÁ** calcular `valid_shifts_count` sobre `technician_shifts` del reponedor en el periodo (`started_at` en ventana) excluyendo turnos anulados (`NOT (status = 'OFF_SHIFT' AND closed_at IS NOT NULL AND closed_at = started_at)`).
  3. **EL SISTEMA DEBERÁ** calcular `machines_per_shift = ROUND(completed_visits_count / valid_shifts_count, 2)` únicamente cuando `valid_shifts_count > 0` y no haya filtro territorial (`zone_id === null && location_id === null`). En caso contrario, devolverá `machines_per_shift = null` y `machines_per_shift_na_reason` con `'NOT_SEGMENTABLE_BY_TERRITORY'` (si hay filtro de zona o sede) o `'NO_VALID_SHIFTS'`.
  4. **EL SISTEMA DEBERÁ** calcular `avg_stop_duration_minutes = ROUND(AVG(EXTRACT(EPOCH FROM (completed_at - started_at)) / 60.0), 1)` sobre las visitas `COMPLETED` del reponedor sin recorte estadístico de valores atípicos (`null` si `completed_visits_count = 0`).

* **Criterio EARS 05.2 (Sinergia Nivel 1 y escalados al SAT — M36, `QA-5`):**
  1. **EL SISTEMA DEBERÁ** leer sobre estado vigente de `incidents` (`deleted_at IS NULL`) dos contadores separados vinculados a usuarios con `role = 'RESTOCKER'`:
     * `l1_resolved_count`: tickets con `dispatch_level = 'L1_RESTOCKER'`, `status IN ('RESOLVED', 'CLOSED')`, `assigned_technician_id = restocker_id` y `resolved_at` dentro del rango (las L1 reabiertas en garantía y escaladas al SAT dejan de figurar aquí).
     * `escalated_to_sat_count`: tickets con `escalated_from_restocker_id = restocker_id` y `escalated_to_sat_at` dentro del rango.
  2. **EL SISTEMA DEBERÁ** garantizar en todo filtro que `summary.l1_resolved_count === SUM(by_restocker[].l1_resolved_count)` y `summary.escalated_to_sat_count === SUM(by_restocker[].escalated_to_sat_count)` (`QA-5`).

* **Criterio EARS 05.3 (Exactitud de arqueos de efectivo sobre bolsas cerradas — M35):**
  1. **EL SISTEMA DEBERÁ** evaluar todas las recaudaciones en `machine_cash_collections` por `collected_at` dentro del rango (tanto las vinculadas a una visita M34 como las recaudaciones independientes con `restock_visit_id IS NULL`), separando:
     * `reconciled_ok_count` (`status = 'RECONCILED_OK'`, expuesto también globalmente en `summary.cash_reconciled_ok_count`).
     * `discrepancy_flagged_count` (`status = 'DISCREPANCY_FLAGGED'`, expuesto también globalmente en `summary.cash_discrepancy_flagged_count`).
     * `pending_audit_count` (`status IN ('SEALED_IN_ROUTE', 'DELIVERED_TO_VAULT')`).
     * `total_abs_discrepancy_eur = ROUND(SUM(ABS(discrepancy_eur)), 2)` sobre las filas cerradas (`RECONCILED_OK` o `DISCREPANCY_FLAGGED`).
  2. **EL SISTEMA DEBERÁ** calcular `cash_accuracy_rate_pct = ROUND(reconciled_ok_count / (reconciled_ok_count + discrepancy_flagged_count) * 100, 2)`, devolviendo `null` (`—`) cuando no existan bolsas cerradas (`reconciled_ok_count + discrepancy_flagged_count = 0`).

---

### RF-06 · Interfaz Vue 3 ESM (`CoordinatorMetricsView.js`), Gráficos Chart.js y Exportación CSV (`D4`, `D5`, `C2`, `C5`, `QA-1`, `QA-3`)

* **Criterio EARS 06.1 (Sub-pestaña propia, barra única y filtros independientes en Coordinación):**
  1. **EL SISTEMA DEBERÁ** añadir la sub-pestaña **«📦 Métricas de Carga y Reposición»** (`activeSubTab === 'restocking-bi'`, `data-testid="tab-restocking-bi"`) en `CoordinatorMetricsView.js`, ocultando la barra superior global de SAT (`v-if="activeSubTab !== 'restocking-bi'"`) mientras esa pestaña permanezca activa (`QA-3`), y renderizando el componente autónomo `CoordinatorRestockingBiTab.js` (registrado en `public/sw.js`).
  2. **EL SISTEMA DEBERÁ** ofrecer en `CoordinatorRestockingBiTab.js` una barra de filtros propia (`from`, `to`, `zone_id`, `location_id` filtrado en cascada y limpiando `location_id` automáticamente cuando `zone_id` cambie y la sede quede fuera de la nueva zona, `restocker_id`), botón de descarga CSV y estado de carga/error contenido dentro de la sub-pestaña sin tumbar el resto del cuadro de mandos.
  3. **EL SISTEMA DEBERÁ** respetar `docs/design.md` (paleta por tokens CSS vía `getComputedStyle`, tipografía `DM Sans` en cifras y `Inter` en cuerpo, radios de `4 px` / `8 px`, objetivos táctiles de `44 px` y cero desbordamiento horizontal en `360 × 800 px`).

* **Criterio EARS 06.2 (Dos gráficos `window.Chart` con degradación elegante y tres tablas):**
  1. **EL SISTEMA DEBERÁ** renderizar 2 gráficos mediante la instancia local `window.Chart`:
     * **Gráfico 1 — Comparativa de Mermas:** barras comparando *Caducidad FEFO* vs. *Dañado en ruta* vs. *Térmica APPCC (M29)* con conmutador entre `€` y `Unidades` (mostrando solo las dos series comerciales + el aviso térmico cuando exista filtro `restocker_id`).
     * **Gráfico 2 — Top 10 Críticos:** barras horizontales con selector entre *Rotura de stock (%)* y *Caducidad FEFO (%)* sobre máquinas o productos.
  2. **SI** `window.Chart` no está disponible en el entorno del navegador, **EL SISTEMA DEBERÁ** mostrar un aviso informativo accesible en los contenedores gráficos sin lanzar excepciones no capturadas ni ocultar las tarjetas KPI o las 3 tablas (**Máquinas y Planograma**, **Productos/SKU** y **Rendimiento por Reponedor**).

* **Criterio EARS 06.3 (Exportación CSV multi-sección autenticada, confidencial, anti-fórmulas y auditada):**
  1. **CUANDO** Coordinación solicite `GET /api/coordinator/metrics/restocking/export` (invocado desde el frontend exclusivamente mediante `fetch` autenticado + `Blob`, nunca por URL con token ni `window.open`), **EL SISTEMA DEBERÁ** devolver un archivo `text/csv; charset=UTF-8` iniciado por BOM `\xEF\xBB\xBF`, usando `;` como separador de columnas, incluyendo aviso de confidencialidad interna Art. V.4 y las 5 secciones estructurales (**Resumen y Metadatos**, **Comparativa de Mermas**, **Máquinas y Planograma**, **Productos/SKU** y **Rendimiento por Reponedor**), incluso si el periodo filtrado no contiene filas operativas.
  2. **EL SISTEMA DEBERÁ** neutralizar de forma estricta toda celda que comience por `=`, `+`, `-`, `@`, `\t` o `\r` anteponiendo un apóstrofo `'` para impedir la inyección de fórmulas en hojas de cálculo.
  3. **EL SISTEMA DEBERÁ** registrar un evento inmutable en `audit_log` con `entity_type = AuditEvent::ENTITY_MACHINE_RESTOCK_VISIT`, `entity_id = 0` y `action = 'EXPORT_RESTOCKING_BI_CSV'` incluyendo los filtros aplicados y marca horaria `Europe/Madrid` (`QA-1`).

---

## 5. Requisitos No Funcionales (RNF)

* **RNF-01 · Cero Evolución DDL (Art. IV):** El módulo **no crea ninguna tabla, vista materializada, columna ni migración SQL**. El inventario canónico del sistema permanece inalterado en **51 tablas**, **50 tipos `ENUM` nativos**, **56 columnas `ENUM`** y **34 migraciones**.
* **RNF-02 · Eficiencia SQL sobre Supavisor y Cero Multiplicación Cartesiana:** `PdoRestockingBiAnalyticsRepository` debe resolver las agregaciones mediante consultas independientes por dominio fuente (visitas, ítems, mermas térmicas, turnos, incidencias L1 y arqueos) cruzadas en memoria PHP por su clave primaria, prohibiendo consultas *N+1* y evitando el producto cartesiano de unir simultáneamente tablas `1:N` distintas.
* **RNF-03 · Seguridad RBAC y Opacidad Económica (Art. V.4):** Todo endpoint del módulo exige `InternalAuthMiddleware(COORDINATOR)`. Las trazas de error `500` se registran únicamente en `error_log` del servidor y jamás viajan en el JSON HTTP. Ninguna vista ajena a `COORDINATOR` accede a cifras económicas o de rendimiento.
* **RNF-04 · Dogma Vanilla y Conformidad Visual (`docs/design.md`):** Backend en PHP 8.2+ con `declare(strict_types=1);` y PDO puro; frontend en Vue 3 Options API vía ES Modules sin *bundlers* ni librerías externas nuevas (`window.Chart` reutilizado de M25), cero literales hex en JavaScript (lectura por `getComputedStyle`) y diseño completamente operable en `360 × 800 px`.
* **RNF-05 · Trazabilidad y Cobertura de Certificación:** Cada criterio EARS, decisión (`D1`–`D5`, `C1`–`C6`) y resolución QA (`QA-1`–`QA-5`) queda respaldado por pruebas unitarias PHP, pruebas de integración PDO contra PostgreSQL real, pruebas HTTP API, pruebas de blindaje constitucional, pruebas unitarias Node ESM de componente Vue, guardas vigentes del Service Worker y prueba visual Chromium en `360 × 800 px`.

---

## 6. Fronteras Explícitas Fuera de Alcance (*Non-Goals*, `C6`)

Quedan expresamente excluidos del Módulo 38 (Feature 28):
1. **Comparativas entre periodos** (`vs. periodo anterior`, variaciones porcentuales MoM/YoY).
2. **Navegación o edición acoplada a planogramas**, incluyendo enlaces profundos hacia `CoordinatorPlanogramTab.js`, cambios en `CoordinatorDashboardView.js` o formularios de ajuste de `max_capacity`/`min_threshold` dentro de BI.
3. **Estimación de facturación o venta perdida en euros por stockout**, dependiente de lectura telemática de ventas MDB/DEX (Bloque 5 · Feature 18).
4. **Integración en el Informe Ejecutivo A4 (`ExecutiveReportModal.js`)**, que se mantiene exclusivo para la analítica SAT e incidencias.
5. **Métricas operativas de órdenes de carga (Módulo 33) o adherencia a cadencias/omisiones `SKIPPED` (Módulo 37)**, que ya residen en sus consolas propias.
6. **Vistas o endpoints propios para `RESTOCKER`, `TECHNICIAN` o `LOCATION_RESPONSIBLE`** (Art. V.4).
7. **Nuevas tablas, vistas materializadas o cachés persistentes en base de datos** (Art. IV).

---

## 7. Catálogo de Códigos HTTP y Respuestas de Error

| Código HTTP | `error.code` | Condición de Disparo | Criterio |
| :---: | :--- | :--- | :--- |
| `400` | `INVALID_FILTER_PARAMS` | Parámetro `from` sin `to` (o viceversa), fecha distinta de `YYYY-MM-DD` o civilmente inválida, `from > to`, rango `> 366` días naturales inclusivos, o `zone_id` / `location_id` / `restocker_id` no entero positivo (`to > today` admitido). | `RF-01.2`, `C5`, `QA-5` |
| `401` | `UNAUTHORIZED` | Petición sin sesión autenticada o token JWT inválido/expirado. | `RNF-03`, `C5` |
| `403` | `FORBIDDEN` | Usuario autenticado con rol distinto de `COORDINATOR` (`RESTOCKER`, `TECHNICIAN` o `LOCATION_RESPONSIBLE`). | `Art. V.4`, `RNF-03` |
| `500` | `INTERNAL_SERVER_ERROR` | Fallo inesperado de base de datos o sistema (mensaje genérico al cliente, sin detalles SQL). | `C5`, `RNF-03` |

---

## 8. Criterios de Finalización (Definition of Done)

1. Servicios puros de dominio y aplicación (`RestockingBiFilterValidator`, `RestockingBiCalculator`, `RestockingBiAnalyticsService`, `RestockingBiExportService`) operando con cobertura unitaria al 100 % sobre reglas `D1`–`D5`, `C1`–`C5` y `QA-1`–`QA-5`.
2. Repositorio `PdoRestockingBiAnalyticsRepository` ejecutando consultas agregadas limpias sobre PostgreSQL sin DDL adicional (51 tablas / 50 tipos `ENUM` intactos).
3. Rutas `GET /api/coordinator/metrics/restocking/export` y `GET /api/coordinator/metrics/restocking` operativas con RBAC `COORDINATOR` y auditoría `EXPORT_RESTOCKING_BI_CSV`.
4. Sub-pestaña `CoordinatorRestockingBiTab.js` integrada en `CoordinatorMetricsView.js` (ocultando la barra superior global mientras está activa), precacheada en `public/sw.js` (`v33`), verificada con `window.Chart` (y degradación limpia sin él) y sin desbordamiento en `360 × 800 px`.
5. Batería global (`php tests/run_all.php`), canal `new_engine`, guardas del Service Worker, matriz de trazabilidad y cierre documental en `docs/features_pendientes.md` ejecutados y al 100 % en verde.
