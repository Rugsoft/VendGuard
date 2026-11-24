# CONTRATOS TÉCNICOS: CUADRO DE MANDO DE REPOSICIÓN, ROTURAS DE STOCK Y MERMAS COMERCIALES (`RESTOCKING BI, STOCKOUT RATE & MERCHANDISING ANALYTICS`)

**Proyecto:** Gestor de Incidencias de Vending (*VendGuard*)  
**Módulo:** `38-restocking-bi-analytics` (Feature 28 de `docs/features_pendientes.md`)  
**Documento Canónico:** `specs/technical/restocking_bi_analytics_contracts.md`  
**Versión:** 1.1 · Incorpora las Resoluciones de Auditoría QA (`QA-1`–`QA-5`)  
**Estado:** Contratos Técnicos Aprobados por el Product Owner el 2026-11-24 · Puerta de Aprobación SDD superada (Fase 2)  
**Referencia Funcional:** [`specs/38-restocking-bi-analytics/spec.md`](../38-restocking-bi-analytics/spec.md) y [`specs/functional/restocking_bi_analytics_spec.md`](../functional/restocking_bi_analytics_spec.md) (v1.1 · `RF-01` a `RF-06`, `D1`–`D5`, `C1`–`C6`, `QA-1`–`QA-5`)  
**Motor de Persistencia:** PostgreSQL 16+ (Supabase / Motor Local de Certificación) · Motor Único · Cero Migraciones DDL  
**Protocolo:** HTTP/1.1 · JSON (`application/json; charset=utf-8`) y CSV (`text/csv; charset=UTF-8`)  
**Conformidad Constitucional:** Artículos I, II, III (3.1, 3.2, 3.3), IV, V (5.4) y VI

---

## 1. Visión General de Endpoints, Enrutado y Opacidad (`Art. V.4`)

| Método | Ruta | Middleware / Rol | Propósito Operativo |
| :---: | :--- | :--- | :--- |
| `GET` | `/api/coordinator/metrics/restocking/export` | `InternalAuthMiddleware(COORDINATOR)` | Descarga el informe CSV multi-sección (`UTF-8 BOM`, `;`, anti-fórmulas, cabecera confidencial Art. V.4) para los filtros vigentes y registra la auditoría `EXPORT_RESTOCKING_BI_CSV`. Invocado desde cliente únicamente vía `fetch` autenticado + `Blob` (`QA-CONST-03`). **Debe declararse en `AppRouter.php` antes que `/api/coordinator/metrics/restocking`** porque el router evalúa las rutas por orden de declaración (mismo patrón que `/root-causes-pareto/export` y `/work-hours/export`). |
| `GET` | `/api/coordinator/metrics/restocking` | `InternalAuthMiddleware(COORDINATOR)` | Calcula y devuelve en una única respuesta JSON los KPIs resumen, comparativa de mermas Comercial vs. Térmica, series pre-ordenadas para los 2 gráficos Chart.js, tablas por máquina/producto/reponedor y catálogos disponibles de filtros (`available_filters`). |
| **Opacidad** | `/api/restocker/*`, `/api/technician/*`, `/api/incidents/*`, `/api/qr/*`, Albaranes A4 | Diversos | **Opacidad total (`Art. V.4`):** ninguna de estas superficies expone agregados de stockout, costes unitarios de producto (`unit_cost_snapshot_eur`), importes de merma económica, tasas de sobredimensionamiento, exactitud de arqueos ni métricas comparativas de rendimiento. |

---

## 2. Contrato de Parámetros de Consulta y Validación (`400 INVALID_FILTER_PARAMS`)

Ambos endpoints aceptan exactamente los mismos parámetros `query`:

| Parámetro | Tipo | Obligatorio | Valor por Defecto | Reglas de Validación |
| :--- | :--- | :---: | :--- | :--- |
| `from` | `string` (`YYYY-MM-DD`) | Par con `to` | `today - 29 days` en `Europe/Madrid` | Debe declararse junto con `to`. Formato estricto `^\d{4}-\d{2}-\d{2}$` y fecha civil gregoriana válida (`checkdate`). |
| `to` | `string` (`YYYY-MM-DD`) | Par con `from` | `today` en `Europe/Madrid` | Debe declararse junto con `from`, fecha civil válida, `from <= to`, y rango inclusivo `(to - from) + 1 <= 366` días naturales. **Se admite `to > today` (`200 OK`, `QA-5`)** siempre que se respeten `from <= to` y el techo de `366` días. |
| `zone_id` | `int >= 1` | No | `null` | Cadena vacía o ausente se normaliza a `null`. Si viene informado, debe ser entero positivo (`ctype_digit` y `(int) > 0`). |
| `location_id` | `int >= 1` | No | `null` | Cadena vacía o ausente se normaliza a `null`. Si viene informado, debe ser entero positivo (`ctype_digit` y `(int) > 0`). |
| `restocker_id` | `int >= 1` | No | `null` | Cadena vacía o ausente se normaliza a `null`. Si viene informado, debe ser entero positivo (`ctype_digit` y `(int) > 0`). |

### Regla de Ventana Horaria (`C1`)

El validador `RestockingBiFilterValidator` convierte el par `(from, to)` de días naturales en `Europe/Madrid` a dos marcas `DateTimeImmutable`:
- `from_at`: `{from} 00:00:00` en `Europe/Madrid`
- `to_exclusive_at`: `({to} + 1 día) 00:00:00` en `Europe/Madrid`

Todas las consultas PDO filtran con la condición semiabierta `timestamp_col >= :from_at AND timestamp_col < :to_exclusive_at`, evitando perder eventos en los últimos segundos del día `to` o sufrir desfases en cambios de horario DST.

### Respuesta `400 Bad Request` ante Filtro Inválido (`C5`)

```json
{
  "success": false,
  "error": {
    "code": "INVALID_FILTER_PARAMS",
    "message": "El rango temporal máximo permitido es de 366 días naturales.",
    "details": {
      "field": "to",
      "max_days": 366
    }
  }
}
```

---

## 3. Contrato JSON de `GET /api/coordinator/metrics/restocking`

### 3.1. Respuesta `200 OK` (Caso Territorial / Parque Completo: `restocker_id = null`)

```json
{
  "success": true,
  "data": {
    "filters": {
      "from": "2026-10-26",
      "to": "2026-11-24",
      "zone_id": null,
      "location_id": null,
      "restocker_id": null,
      "timezone": "Europe/Madrid"
    },
    "available_filters": {
      "zones": [
        { "id": 1, "code": "ZN-NORTE", "name": "Zona Norte", "is_active": true },
        { "id": 9, "code": "ZN-HIST", "name": "Zona Antigua", "is_active": false }
      ],
      "locations": [
        { "id": 3, "site_code": "HOSP-01", "name": "Hospital Universitario", "zone_id": 1, "is_active": true },
        { "id": 8, "site_code": "EXT-04", "name": "Sede Sin Zona", "zone_id": null, "is_active": true }
      ],
      "restockers": [
        { "id": 7, "operator_code": "REP-01", "name": "Lucía Navarro", "is_active": true },
        { "id": 12, "operator_code": null, "name": "Carlos Méndez", "is_active": false }
      ]
    },
    "summary": {
      "completed_visits_count": 20,
      "clean_in_stock_visits_count": 17,
      "stockout_visits_count": 3,
      "visit_in_stock_rate_pct": 85.0,
      "visit_stockout_rate_pct": 15.0,
      "thermal_aborted_visits_count": 1,
      "audited_slots_count": 64,
      "stockout_slots_count": 5,
      "slot_stockout_rate_pct": 7.81,
      "low_threshold_slots_count": 9,
      "oversized_machines_count": 1,
      "commercial_spoilage_eur": 42.3,
      "commercial_spoilage_units": 18,
      "thermal_spoilage_eur": 31.5,
      "thermal_spoilage_units": 9,
      "total_spoilage_eur": 73.8,
      "cash_reconciled_ok_count": 15,
      "cash_discrepancy_flagged_count": 1,
      "cash_reconciled_count": 16,
      "cash_pending_audit_count": 2,
      "cash_accuracy_rate_pct": 93.75,
      "l1_resolved_count": 4,
      "escalated_to_sat_count": 2
    },
    "spoilage_comparison": {
      "commercial_fefo_expired": {
        "units": 15,
        "loss_eur": 34.8
      },
      "commercial_damaged": {
        "units": 3,
        "loss_eur": 7.5
      },
      "commercial_total": {
        "units": 18,
        "loss_eur": 42.3
      },
      "thermal_haccp": {
        "segmentable": true,
        "non_segmentable_reason": null,
        "non_segmentable_message": null,
        "records_count": 2,
        "units": 9,
        "loss_eur": 31.5
      }
    },
    "charts": {
      "spoilage_bars": {
        "labels": ["Caducidad FEFO", "Dañado en ruta", "Avería Térmica APPCC"],
        "keys": ["commercial_fefo_expired", "commercial_damaged", "thermal_haccp"],
        "eur_values": [34.8, 7.5, 31.5],
        "unit_values": [15, 3, 9],
        "thermal_segmentable": true
      },
      "top_critical_stockout": {
        "by_machine": [
          {
            "id": 5,
            "code": "MAQ-HOSP-02",
            "label": "MAQ-HOSP-02 · Hospital Universitario",
            "is_active": true,
            "stockout_slots_count": 3,
            "audited_slots_count": 12,
            "slot_stockout_rate_pct": 25.0
          }
        ],
        "by_product": [
          {
            "id": 3,
            "code": "SAND-MIXTO-01",
            "label": "SAND-MIXTO-01 · Sándwich Mixto Fresco",
            "is_active": true,
            "stockout_slots_count": 3,
            "audited_slots_count": 10,
            "slot_stockout_rate_pct": 30.0
          }
        ]
      },
      "top_critical_expiry": {
        "by_machine": [
          {
            "id": 5,
            "code": "MAQ-HOSP-02",
            "label": "MAQ-HOSP-02 · Hospital Universitario",
            "is_active": true,
            "expired_units": 9,
            "added_units": 36,
            "expiry_rate_pct": 20.0,
            "is_oversized_planogram": true
          }
        ],
        "by_product": [
          {
            "id": 3,
            "code": "SAND-MIXTO-01",
            "label": "SAND-MIXTO-01 · Sándwich Mixto Fresco",
            "is_active": true,
            "expired_units": 9,
            "added_units": 36,
            "expiry_rate_pct": 20.0
          }
        ]
      }
    },
    "by_machine": [
      {
        "machine_id": 5,
        "machine_code": "MAQ-HOSP-02",
        "machine_type": "PERISHABLE_FOOD",
        "location_id": 3,
        "location_name": "Hospital Universitario",
        "zone_id": 1,
        "zone_name": "Zona Norte",
        "is_active": true,
        "completed_visits_count": 6,
        "clean_in_stock_visits_count": 4,
        "visit_in_stock_rate_pct": 66.67,
        "thermal_aborted_visits_count": 1,
        "audited_slots_count": 12,
        "stockout_slots_count": 3,
        "slot_stockout_rate_pct": 25.0,
        "low_threshold_slots_count": 2,
        "added_units": 36,
        "expired_units": 9,
        "damaged_units": 1,
        "expired_loss_eur": 22.5,
        "damaged_loss_eur": 2.5,
        "commercial_spoilage_loss_eur": 25.0,
        "expiry_rate_pct": 20.0,
        "is_oversized_planogram": true,
        "thermal_spoilage_units": 9,
        "thermal_spoilage_loss_eur": 31.5
      }
    ],
    "by_product": [
      {
        "product_id": 3,
        "sku": "SAND-MIXTO-01",
        "name": "Sándwich Mixto Fresco",
        "category": "PERISHABLE_FOOD",
        "is_active": true,
        "audited_slots_count": 10,
        "stockout_slots_count": 3,
        "slot_stockout_rate_pct": 30.0,
        "low_threshold_slots_count": 1,
        "added_units": 36,
        "expired_units": 9,
        "damaged_units": 0,
        "expired_loss_eur": 22.5,
        "damaged_loss_eur": 0.0,
        "commercial_spoilage_loss_eur": 22.5,
        "expiry_rate_pct": 20.0
      }
    ],
    "by_restocker": [
      {
        "restocker_id": 7,
        "operator_code": "REP-01",
        "name": "Lucía Navarro",
        "is_active": true,
        "completed_visits_count": 14,
        "thermal_aborted_visits_count": 1,
        "valid_shifts_count": 5,
        "machines_per_shift": 2.8,
        "machines_per_shift_na_reason": null,
        "avg_stop_duration_minutes": 18.5,
        "l1_resolved_count": 3,
        "escalated_to_sat_count": 1,
        "reconciled_ok_count": 11,
        "discrepancy_flagged_count": 1,
        "pending_audit_count": 1,
        "cash_accuracy_rate_pct": 91.67,
        "total_abs_discrepancy_eur": 2.4,
        "expired_units": 10,
        "damaged_units": 2,
        "commercial_spoilage_loss_eur": 27.8
      }
    ]
  }
}
```

### 3.2. Reglas de Serialización, Nulabilidad, Inclusión y Ordenación (`D2`, `C3`, `C5`, `QA-2`, `QA-4`)

1. **Cierre exacto de disponibilidad a nivel de visita (`QA-C2`):**
   - Cuando `completed_visits_count > 0`: `visit_in_stock_rate_pct = ROUND(clean_in_stock_visits_count / completed_visits_count * 100, 2)` y `visit_stockout_rate_pct = ROUND(100.00 - visit_in_stock_rate_pct, 2)`, garantizando siempre `visit_in_stock_rate_pct + visit_stockout_rate_pct === 100.00`. Si `completed_visits_count === 0`, ambos son `null`.
2. **Campos `nullable` (`QA-A3`):**
   - En `available_filters.locations[]` y `by_machine[]`, `zone_id` (`int|null`) y `zone_name` (`string|null`) son anulables cuando `locations.zone_id IS NULL`; UI y CSV muestran `Sin zona`. Las máquinas en sedes sin zona entran en la consulta general (`zone_id = null`) y se excluyen únicamente cuando el Coordinador filtra una zona concreta (`zone_id >= 1`).
   - En `available_filters.restockers[]` y `by_restocker[]`, `operator_code` (`string|null`) admite `null` cuando `users.operator_code IS NULL`; UI y CSV muestran `—`.
3. **Composición de `available_filters` (`QA-4`):**
   - Cada catálogo (`zones`, `locations`, `restockers`) contiene: (a) todas las entidades activas, (b) las entidades inactivas (`is_active = FALSE` o `deleted_at IS NOT NULL`) que registren actividad en `(from, to)`, y (c) la entidad correspondiente al `zone_id`, `location_id` o `restocker_id` actualmente seleccionado si existe en base de datos, evitando selectores huérfanos al cambiar el rango de fechas.
4. **Reglas de inclusión de filas en tablas (`QA-4`):**
   - `by_machine`: incluye toda máquina con al menos `1` visita (`COMPLETED` o `ABORTED_THERMAL_BREACH`) o al menos `1` acta M29 (cuando `restocker_id === null`) dentro de los filtros vigentes.
   - `by_restocker`: sin filtro territorial (`zone_id === null && location_id === null`), incluye todo `RESTOCKER` con al menos `1` visita (completada o abortada), `1` recaudación, `1` incidencia L1 resuelta/escalada **o** `valid_shifts_count > 0`; con filtro territorial activo, incluye únicamente a los `RESTOCKER` con actividad territorial (visita, recaudación o incidencia L1/escalado en esa zona/sede).
5. **Cuando `restocker_id` es distinto de `null`:**
   - `summary.thermal_spoilage_eur = null`, `summary.thermal_spoilage_units = null`, `summary.total_spoilage_eur = null`.
   - `spoilage_comparison.thermal_haccp` pasa a:
     ```json
     {
       "segmentable": false,
       "non_segmentable_reason": "NOT_SEGMENTABLE_BY_RESTOCKER",
       "non_segmentable_message": "No segmentable por reponedor (ámbito máquina/SAT)",
       "records_count": null,
       "units": null,
       "loss_eur": null
     }
     ```
   - En `charts.spoilage_bars`: `labels`, `keys`, `eur_values` y `unit_values` contienen únicamente las dos barras comerciales (`commercial_fefo_expired`, `commercial_damaged`) y `thermal_segmentable = false`.
   - En cada fila de `by_machine`: `thermal_spoilage_units = null` y `thermal_spoilage_loss_eur = null`.
6. **Cuando hay filtro territorial (`zone_id != null` o `location_id != null`):**
   - En cada fila de `by_restocker`: `machines_per_shift = null` y `machines_per_shift_na_reason = "NOT_SEGMENTABLE_BY_TERRITORY"`.
   - Si no hay filtro territorial pero `valid_shifts_count === 0`: `machines_per_shift = null` y `machines_per_shift_na_reason = "NO_VALID_SHIFTS"`.
7. **Ordenación determinista de tablas y series Top 10 (`QA-2`, Art. II.2):**
   - `by_machine`: `(thermal_aborted_visits_count > 0 OR COALESCE(thermal_spoilage_units, 0) > 0) DESC`, `is_oversized_planogram DESC`, `commercial_spoilage_loss_eur DESC`, `stockout_slots_count DESC`, `machine_code ASC`.
   - `by_product`: `commercial_spoilage_loss_eur DESC`, `stockout_slots_count DESC`, `sku ASC`.
   - `by_restocker`: `completed_visits_count DESC`, `name ASC`.
   - `charts.top_critical_stockout`: top `10` con `stockout_slots_count > 0`, ordenados por `slot_stockout_rate_pct DESC`, `stockout_slots_count DESC`, `code ASC`.
   - `charts.top_critical_expiry`: top `10` con `expired_units > 0`, ordenados por `expiry_rate_pct DESC`, `expired_units DESC`, `code ASC`.

---

## 4. Contrato de Persistencia PostgreSQL (Cero DDL y Consultas Desacopladas)

### 4.1. Inventario Canónico Intacto (`RNF-01`, Art. IV)

El módulo no añade ni altera tablas, columnas, índices ni enumerados. El esquema canónico permanece exactamente en:
- **51 tablas de dominio** (+ tabla de libro de migraciones)
- **50 tipos `ENUM` nativos**
- **56 columnas `ENUM`**
- **34 archivos de migración** (`001` a `034`)

### 4.2. Consultas Agregadas Independientes en `PdoRestockingBiAnalyticsRepository` (`RNF-02`)

Para impedir productos cartesianos entre tablas `1:N` (`machine_restock_visit_items`, `machine_cash_collections`, `technician_shifts`, `incidents`, `sanitary_spoilage_records`) y cumplir latencia acotada sobre Supavisor, el repositorio ejecuta **consultas agregadas independientes** y el calculador `RestockingBiCalculator` ensambla el informe en memoria PHP:

1. **Cabeceras de Visita (M34):**
   - Fuente: `machine_restock_visits mrv` + `JOIN machines m ON m.id = mrv.machine_id` + `JOIN locations l ON l.id = m.location_id`.
   - Ventana: `mrv.completed_at >= :from_at AND mrv.completed_at < :to_exclusive_at` más filtros opcionales `l.zone_id`, `l.id`, `mrv.restocker_id`.
   - Agrupación: `(mrv.machine_id, mrv.restocker_id)`.
   - Detección sin duplicar filas de rotura por visita:
     ```sql
     COUNT(*) FILTER (WHERE mrv.status = 'COMPLETED') AS completed_visits_count,
     COUNT(*) FILTER (
       WHERE mrv.status = 'COMPLETED'
         AND NOT EXISTS (
           SELECT 1
             FROM machine_restock_visit_items mvi
            WHERE mvi.visit_id = mrv.id
              AND mvi.qty_before = 0
         )
     ) AS clean_in_stock_visits_count,
     COUNT(*) FILTER (WHERE mrv.status = 'ABORTED_THERMAL_BREACH') AS thermal_aborted_visits_count,
     COALESCE(SUM(EXTRACT(EPOCH FROM (mrv.completed_at - mrv.started_at))) FILTER (WHERE mrv.status = 'COMPLETED'), 0) AS completed_duration_seconds_sum
     ```
2. **Ítems de Carril (M34 + M32, `QA-5`):**
   - Fuente: `machine_restock_visit_items mvi` + `JOIN machine_restock_visits mrv ON mrv.id = mvi.visit_id` + `JOIN machines m ON m.id = mrv.machine_id` + `JOIN locations l ON l.id = m.location_id` + `LEFT JOIN machine_planogram_slots mps ON mps.id = mvi.slot_id`.
   - Condición: `mrv.status = 'COMPLETED'` + ventana en `mrv.completed_at` + filtros opcionales.
   - Métricas base extraídas por `(mrv.machine_id, mvi.product_id)` y aparte por `mrv.restocker_id`:
     - `audited_slots_count = COUNT(*)`
     - `stockout_slots_count = COUNT(*) FILTER (WHERE mvi.qty_before = 0)`
     - `low_threshold_slots_count = COUNT(*) FILTER (WHERE mvi.qty_before > 0 AND mps.min_threshold IS NOT NULL AND mps.product_id = mvi.product_id AND mvi.qty_before <= mps.min_threshold)` (`QA-5`)
     - `added_units = COALESCE(SUM(mvi.qty_added), 0)`
     - `expired_units = COALESCE(SUM(mvi.qty_withdrawn_expired), 0)`
     - `damaged_units = COALESCE(SUM(mvi.qty_withdrawn_damaged), 0)`
     - `expired_loss_eur = COALESCE(SUM(ROUND(mvi.qty_withdrawn_expired * mvi.unit_cost_snapshot_eur, 2)), 0)`
     - `damaged_loss_eur = COALESCE(SUM(ROUND(mvi.qty_withdrawn_damaged * mvi.unit_cost_snapshot_eur, 2)), 0)`
     - `commercial_spoilage_loss_eur = COALESCE(SUM(mvi.commercial_spoilage_loss_eur), 0)`
     - Al ser `qty_withdrawn_expired` y `qty_withdrawn_damaged` enteros (`INTEGER`) y `unit_cost_snapshot_eur` `NUMERIC(10, 2)`, cada producto parcial es exacto a dos decimales y la suma `expired_loss_eur + damaged_loss_eur` coincide exactamente al céntimo con `commercial_spoilage_loss_eur` (`QA-A4`).
3. **Merma Térmica APPCC (M29, `QA-2`):**
   - Fuente: `sanitary_spoilage_records ssr` + `JOIN machines m ON m.id = ssr.machine_id` + `JOIN locations l ON l.id = m.location_id`.
   - **Solo se ejecuta cuando `restocker_id === null`**.
   - Ventana: `ssr.created_at >= :from_at AND ssr.created_at < :to_exclusive_at` + filtros opcionales `l.zone_id`, `l.id`.
   - Agrupación: `ssr.machine_id` (`records_count = COUNT(*)`, `discarded_units_count = COALESCE(SUM(ssr.discarded_units_count), 0)`, `estimated_merchandise_loss_eur = COALESCE(SUM(ssr.estimated_merchandise_loss_eur), 0)`), contabilizando **todas** las actas M29 del periodo (incluidas las actas válidas con `empty_machine_confirmed = TRUE` y los tres orígenes `BIOLOGICAL_CLOCK_4H`, `PREVENTIVE_NON_CONFORMITY`, `MANUAL_SANITARY_LOCK`).
4. **Turnos Válidos de Reponedores (M22/M31):**
   - Fuente: `technician_shifts ts` + `JOIN users u ON u.id = ts.technician_id`.
   - **Solo aplica al cálculo de `machines_per_shift` cuando `zone_id === null && location_id === null`**.
   - Condición: `u.role = 'RESTOCKER'`, `ts.started_at >= :from_at AND ts.started_at < :to_exclusive_at`, `NOT (ts.status = 'OFF_SHIFT' AND ts.closed_at IS NOT NULL AND ts.closed_at = ts.started_at)` y filtro opcional `ts.technician_id = :restocker_id`.
5. **Arqueos de Efectivo (M35):**
   - Fuente: `machine_cash_collections mcc` + `JOIN machines m ON m.id = mcc.machine_id` + `JOIN locations l ON l.id = m.location_id`.
   - Ventana: `mcc.collected_at >= :from_at AND mcc.collected_at < :to_exclusive_at` + filtros opcionales `l.zone_id`, `l.id`, `mcc.restocker_id`, incluyendo tanto recaudaciones asociadas a visita M34 como recaudaciones independientes (`mcc.restock_visit_id IS NULL`).
   - Agrupación por `mcc.restocker_id` contando `RECONCILED_OK`, `DISCREPANCY_FLAGGED`, `SEALED_IN_ROUTE`/`DELIVERED_TO_VAULT` y `SUM(ABS(mcc.discrepancy_eur)) FILTER (WHERE mcc.status IN ('RECONCILED_OK', 'DISCREPANCY_FLAGGED'))`.
6. **Sinergia Nivel 1 y Escalados al SAT (M36, `QA-5`):**
   - Fuente: `incidents i` + `JOIN machines m ON m.id = i.machine_id` + `JOIN locations l ON l.id = m.location_id`, con `i.deleted_at IS NULL`.
   - L1 resueltas efectivas por `i.assigned_technician_id` (exclusivamente usuarios con `u.role = 'RESTOCKER'`): `i.dispatch_level = 'L1_RESTOCKER' AND i.status IN ('RESOLVED', 'CLOSED') AND i.resolved_at >= :from_at AND i.resolved_at < :to_exclusive_at`. Así `summary.l1_resolved_count === SUM(by_restocker[].l1_resolved_count)`.
   - Escaladas al SAT por `i.escalated_from_restocker_id` (`JOIN users u ON u.id = i.escalated_from_restocker_id AND u.role = 'RESTOCKER'`): `i.escalated_from_restocker_id IS NOT NULL AND i.escalated_to_sat_at >= :from_at AND i.escalated_to_sat_at < :to_exclusive_at`.
7. **Metadatos y Catálogos (`is_active`, `QA-4`):**
   - Para tablas con columna `deleted_at` (`users`, `machines`, `locations`), el estado activo expuesto es `(bool)$row['is_active'] && ($row['deleted_at'] === null)`. Para `operational_zones` y `vending_products`, es `(bool)$row['is_active']`.
   - `available_filters` devuelve todos los registros activos, más los registros inactivos que tengan actividad en `(from, to)`, más el registro correspondiente a `zone_id` / `location_id` / `restocker_id` si fue solicitado explícitamente y existe en base de datos.

### 4.3. Auditoría de Exportación CSV (`QA-1`, Art. III.3)

Al ejecutarse `GET /api/coordinator/metrics/restocking/export`, `RestockingBiExportService` registra un `AuditEvent` inmutable con:
- `entity_type`: `AuditEvent::ENTITY_MACHINE_RESTOCK_VISIT` (`'MACHINE_RESTOCK_VISIT'`)
- `entity_id`: `0`
- `action`: `'EXPORT_RESTOCKING_BI_CSV'`
- `user_id`, `user_role`, `user_name`: actor `COORDINATOR` autenticado
- `previous_state`: `null`
- `new_state`: filtros normalizados (`from`, `to`, `zone_id`, `location_id`, `restocker_id`) + `exported_at` en `Europe/Madrid`

---

## 5. Contrato de Exportación CSV (`GET /api/coordinator/metrics/restocking/export`)

* **Cabeceras HTTP:**
  * `Content-Type: text/csv; charset=UTF-8`
  * `Content-Disposition: attachment; filename="vendguard_restocking_bi_{from}_{to}.csv"`
  * `Cache-Control: no-cache, no-store, must-revalidate`
* **Codificación y Seguridad:**
  * Primeros 3 bytes obligatorios: UTF-8 BOM `\xEF\xBB\xBF`.
  * Delimitador de columnas: punto y coma (`;`).
  * Aviso obligatorio de confidencialidad en Sección 1 (`QA-CONST-03`): `DOCUMENTO INTERNO DE COORDINACIÓN — CONFIDENCIAL ART. V.4: PROHIBIDA SU DISTRIBUCIÓN A SEDES CLIENTE`.
  * Neutralización anti-fórmulas (`neutralizeCsvCell`): toda celda que comience por `=`, `+`, `-`, `@`, `\t` o `\r` se prefija con `'` (ej. `'=SUM(A1)`) para impedir la inyección de fórmulas en hojas de cálculo.
  * Fallbacks para campos `nullable` (`QA-A3`): `Sin zona` cuando `zone_name` sea `null`; `—` cuando `operator_code` o un ratio/tasa sea `null`.
* **Estructura de 5 Secciones (siempre presente, incluso con 0 registros):**
  1. **Sección 1 · Resumen Ejecutivo, Confidencialidad y Filtros Aplicados:** línea de confidencialidad Art. V.4, periodo `from`/`to` (`Europe/Madrid`), zona/sede/reponedor filtrados, `exported_at`, y todos los KPIs de `summary`, incluidos `Arqueos_OK` (`cash_reconciled_ok_count`) y `Arqueos_Descuadre` (`cash_discrepancy_flagged_count`) (`QA-C4`).
  2. **Sección 2 · Comparativa de Mermas (Comercial FEFO / Dañado vs. Térmica APPCC):** filas para Caducidad FEFO, Dañado en ruta, Total Comercial y Avería Térmica APPCC (indicando `No segmentable por reponedor (ámbito máquina/SAT)` si `segmentable = false`).
  3. **Sección 3 · Rendimiento y Planograma por Máquina:** columnas `Maquina;Estado;Tipo;Sede;Zona;Visitas_Completadas;Visitas_Sin_Rotura;Disponibilidad_Visita_Pct;Abortos_Termicos;Carriles_Auditados;Carriles_Stockout;Stockout_Carril_Pct;Carriles_Bajo_Umbral;Uds_Repuestas;Uds_Caducadas_FEFO;Uds_Danadas;Merma_FEFO_EUR;Merma_Danado_EUR;Merma_Comercial_EUR;Tasa_Caducidad_Pct;Planograma_Sobredimensionado;Uds_Merma_Termica;Merma_Termica_EUR`.
  4. **Sección 4 · Rotación y Roturas por Producto / SKU:** columnas `SKU;Producto;Estado;Categoria;Carriles_Auditados;Carriles_Stockout;Stockout_Carril_Pct;Carriles_Bajo_Umbral;Uds_Repuestas;Uds_Caducadas_FEFO;Uds_Danadas;Merma_FEFO_EUR;Merma_Danado_EUR;Merma_Comercial_EUR;Tasa_Caducidad_Pct`.
  5. **Sección 5 · Rendimiento Operativo y Arqueos por Reponedor:** columnas `Codigo_Operador;Reponedor;Estado;Visitas_Completadas;Abortos_Termicos;Turnos_Validos;Maquinas_Por_Turno;Motivo_NA_Turno;Tiempo_Medio_Parada_Min;L1_Resueltas;Escaladas_SAT;Arqueos_OK;Arqueos_Descuadre;Arqueos_Pendientes;Exactitud_Arqueos_Pct;Descuadre_Absoluto_EUR;Uds_Caducadas_FEFO;Uds_Danadas;Merma_Comercial_EUR`.

---

## 6. Contrato Frontend Vue 3 ESM (`CoordinatorRestockingBiTab.js`)

1. **Integración en `CoordinatorMetricsView.js` (`QA-3`):**
   - Nueva pestaña en la barra de navegación interna con `activeSubTab === 'restocking-bi'`, etiqueta **«📦 Métricas de Carga y Reposición»** y `data-testid="tab-restocking-bi"`.
   - Ocultación automática de la barra superior global de SAT (`v-if="activeSubTab !== 'restocking-bi'"`) para impedir doble selector de fechas y doble botón CSV.
   - Renderiza `<CoordinatorRestockingBiTab />` dentro de `<div v-else-if="activeSubTab === 'restocking-bi'" data-testid="restocking-bi-block">`.
   - Permanecen intactos `ExecutiveReportModal.js`, `CoordinatorDashboardView.js` y `CoordinatorPlanogramTab.js`.
2. **Métodos en `public/assets/js/api.js` (`api.metrics`, `QA-CONST-03`):**
   - `getRestockingBi(params = {})` -> `GET /api/coordinator/metrics/restocking?...`
   - `downloadRestockingBiCsv(params = {})` -> `GET /api/coordinator/metrics/restocking/export?...` exclusivamente mediante `fetch` autenticado con cabecera `Authorization` y descarga vía `Blob` (sin exponer método de URL abierta ni `window.open`).
3. **Filtros en cascada (`QA-L5`), Tokens CSS, Gráficos Chart.js y Degradación (`docs/design.md`, Art. IV):**
   - Al cambiar `zone_id` en el selector territorial, el componente limpia automáticamente `location_id` a `''` si la sede seleccionada no pertenece a la nueva zona antes de recargar el informe.
   - `CoordinatorRestockingBiTab.js` resuelve todos los colores y tipografía de ambos `<canvas>` mediante `window.getComputedStyle(document.documentElement)` (`--color-primary`, `--color-warning`, `--color-error`, `--color-slate`, `--color-hairline-soft`, `--font-body`). Cero literales hexadecimales en JavaScript.
   - Destruye y recrea ambas instancias Chart.js en cada recarga o cambio de métrica/conmutador, y captura la ausencia de `window.Chart` mostrando `data-testid="restocking-bi-chart-unavailable"` sin afectar a las tarjetas ni a las 3 tablas.
   - Marcadores `data-testid` estables para pruebas unitarias y E2E: `restocking-bi-tab`, `restocking-bi-filter-from`, `restocking-bi-filter-to`, `restocking-bi-filter-zone`, `restocking-bi-filter-location`, `restocking-bi-filter-restocker`, `restocking-bi-apply-filters`, `restocking-bi-export-csv`, `restocking-bi-summary-cards`, `restocking-bi-spoilage-chart`, `restocking-bi-top-chart`, `restocking-bi-thermal-notice`, `restocking-bi-machines-table`, `restocking-bi-products-table`, `restocking-bi-restockers-table`.
4. **Caché PWA y Visor de Auditoría:**
   - Añadir `/assets/js/components/CoordinatorRestockingBiTab.js` al array `APP_SHELL_ASSETS` de `public/sw.js` e incrementar `CACHE_NAME` (`vendguard-app-shell-v33`).
   - Registrar `{ code: 'EXPORT_RESTOCKING_BI_CSV', label: 'Exportación CSV de Métricas de Carga y Reposición', tone: 'neutral', group: 'restock' }` en `public/assets/js/utils/AuditActionLabels.js`.
