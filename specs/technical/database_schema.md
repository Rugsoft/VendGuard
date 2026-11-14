# ESPECIFICACIÓN TÉCNICA · MODELO DE BASE DE DATOS (PostgreSQL / Supabase)
**Proyecto:** Gestor de Incidencias de Vending (*VendGuard*)  
**Documento:** `specs/technical/database_schema.md`  
**Motor Único:** PostgreSQL 16+ (Supabase)  
**Codificación:** `UTF8`  
**DDL Canónico:** [`database/supabase_init.sql`](../../database/supabase_init.sql)  
**Capa Incremental Canónica:** [`database/supabase_migrations/`](../../database/supabase_migrations/)  
**Metodología:** SDD (Specification-Driven Development)  

---

## 1. Resumen y Propósito

Este documento formaliza el esquema relacional de base de datos para **VendGuard** bajo el contrato de motor único PostgreSQL (Supabase), cumpliendo con:
1. **Artículo III de la Constitución:** Prohibición absoluta de borrado físico (`DELETE FROM`). Todas las 48 tablas del dominio cuentan con disparadores `BEFORE DELETE` vinculados a la función constitucional `prevent_hard_delete()`, que rechaza con `SQLSTATE 45000` (`ART_III_1_VIOLATION`) cualquier intento de borrado físico que no ocurra bajo la ventana de purga autorizada del arnés de pruebas. El borrado lógico se gestiona mediante `deleted_at TIMESTAMPTZ NULL` o, en el catálogo de taxonomía, mediante `is_active = FALSE`.
2. **Artículo V de la Constitución & EARS 2.1:** Restricción de unicidad estricta mediante el índice único parcial `uq_machine_active_ticket` para garantizar que **ninguna máquina pueda tener más de una incidencia activa de forma simultánea** (incluyendo el estado `RESOLVED` durante la ventana de garantía sanitaria de 48 horas).
3. **Artículo II de la Constitución:** Integridad en tipos de máquina (`machine_type`), categorías de fallo (`incident_category`) y estados sanitarios (`sanitary_status`) para la supervisión y control estricto de la cadena de frío.
4. **Artículo V.1 de la Constitución:** Longitudes mínimas exigidas por restricciones `CHECK` nativas en base de datos (`chk_resolution_diagnosis_len`, `chk_resolution_action_len`, etc.) garantizando explicaciones técnicas suficientes (mínimo 20 caracteres no vacíos).
5. **Nomenclatura (`AGENTS.md`):** Tablas, columnas, índices, enumerados y restricciones en **inglés técnico**, manteniendo la documentación explicativa en español.

---

## 2. Inventario de Tablas Canónicas del Sistema (48 tablas + Libro)

El sistema opera sobre **48 tablas de dominio** y **1 tabla de infraestructura de migraciones**:

| # | Tabla | Descripción / Responsabilidad |
|---|---|---|
| 1 | `locations` | Sedes y ubicaciones físicas de las máquinas |
| 2 | `machines` | Parque de máquinas expendedoras |
| 3 | `users` | Cuentas internas de personal (Coordinadores, Técnicos) |
| 4 | `incidents` | Registro central de averías e incidencias (52 columnas) |
| 5 | `incident_history` | Trazabilidad inmutable de cambios de estado de incidencias |
| 6 | `incident_comments` | Hilo de evidencias y comentarios de incidencias |
| 7 | `incident_replaced_parts` | Repuestos consumidos y sustituidos en intervenciones |
| 8 | `refund_requests` | Solicitudes y liquidación de reintegros económicos |
| 9 | `unclaimed_cash_findings` | Hallazgos de efectivo sobrante no reclamado |
| 10 | `spare_parts` | Catálogo de piezas de repuesto |
| 11 | `spare_part_compatibilities` | Compatibilidad entre repuestos y modelos de máquina |
| 12 | `spare_part_requests` | Peticiones de repuestos cursadas por técnicos |
| 13 | `preventive_settings` | Frecuencias y ventanas de mantenimiento preventivo |
| 14 | `preventive_orders` | Órdenes de trabajo preventivas planificadas |
| 15 | `preventive_order_items` | Checklist normativo de cada orden preventiva |
| 16 | `sanitary_certificates` | Certificaciones de higienización y calibración térmica |
| 17 | `route_settings` | Parámetros y coordenadas base para cálculo de rutas |
| 18 | `sanitary_sweep_passes` | Registro de pasadas del barrido automático del reloj biológico |
| 19 | `sanitary_sweep_contentions` | Seguimiento de contenciones de bloqueo durante el barrido |
| 20 | `audit_log` | Registro inmutable de auditoría del sistema (Art. III.3) |
| 21 | `notification_outbox` | Cola transaccional de notificaciones |
| 22 | `notification_channel_settings` | Configuración de canales de notificación |
| 23 | `operational_zones` | Zonas operativas del parque |
| 24 | `operational_zone_postal_codes` | Códigos postales vivos de cada zona |
| 25 | `technician_zone_assignments` | Cuadrante técnico por zona |
| 26 | `technician_van_stocks` | Saldo declarado de repuestos en furgoneta |
| 27 | `incident_service_sheets` | Hoja de servicio firmada del cierre |
| 28 | `incident_satisfaction_surveys` | Encuesta de satisfacción por episodio |
| 29 | `technician_absences` | Ausencias planificadas y justificadas |
| 30 | `technician_shifts` | Jornada y pausas del técnico |
| 31 | `technician_shift_logs` | Bitácora append-only de la jornada |
| 32 | `van_restock_orders` | Órdenes de carga de furgoneta |
| 33 | `van_restock_order_items` | Líneas de la orden de carga |
| 34 | `offline_sync_mutations` | Libro de idempotencia de la sincronización offline |
| 35 | `root_causes` | Catálogo maestro de causas raíz (Módulo 25) |
| 36 | `standard_solutions` | Soluciones estandarizadas vinculadas a cada causa (Módulo 25) |
| 37 | `incident_assignments` | Tramos de custodia técnica con snapshots congelados de actores, causa de salida y sellado único (Módulo 26) |
| 38 | `workshop_repair_items` | Bandeja de taller central de piezas recuperadas: desglose unitario 1..N, snapshots congelados, dictamen terminal y reintegro (Módulo 27) |
| 39 | `machine_troubleshooting_guides` | Guías rápidas de diagnóstico por modelo y categoría: códigos de error, pasos y repuestos recomendados (Módulo 28) |
| 40 | `sanitary_spoilage_records` | Actas de merma y destrucción de lotes por cuarentena sanitaria: hechos sanitarios inmutables y coste segregado (Módulo 29) |
| 41 | `restocker_zone_assignments` | Cuadrante de carga por zona operativa: titular único y suplencias del reponedor de ruta, con baja lógica (Módulo 31) |
| 42 | `vending_products` | Catálogo maestro comercial y de consumibles: SKU inmutable, precio y coste, cadena de frío y baja lógica (Módulo 32) |
| 43 | `machine_planogram_slots` | Matriz de carriles por máquina: producto, capacidad, umbral, existencia estimada y lote vigente con su caducidad para el FEFO, con baja lógica (Módulos 32 y 34) |
| 44 | `restocker_van_product_stocks` | Stock de producto por lote en la furgoneta del reponedor, con caducidad y ordenación FEFO (Módulo 33) |
| 45 | `product_loading_orders` | Órdenes de carga de furgoneta: ciclo de vida, modo de demanda, temperatura de salida de cámara y orden activa única por reponedor (Módulo 33) |
| 46 | `product_loading_order_items` | Líneas de la orden de carga: lote y caducidad del despacho, baja lógica y cuadre de la liquidación al cierre (Módulo 33) |
| 47 | `machine_restock_visits` | Cabecera histórica de la visita de reposición in situ: estado, lectura térmica, protocolo sanitario, avería asociada e idempotencia del móvil (Módulo 34) |
| 48 | `machine_restock_visit_items` | Desglose carril a carril de la visita: retiradas FEFO de caducados y dañados, carga, lotes y snapshot contable de la merma (Módulo 34) |
| + | `schema_migrations` | Libro de control del ejecutor `PostgresMigrationRunner` |

---

## 3. Tipos Enumerados Nativos (ENUM)

El esquema implementa 41 tipos `ENUM` nativos en PostgreSQL:
- `user_role`: `'COORDINATOR'`, `'TECHNICIAN'`, `'RESTOCKER'` (Módulo 31)
- `machine_type`: `'HOT_DRINKS'`, `'COLD_DRINKS'`, `'SNACKS'`, `'PERISHABLE_FOOD'`, `'COMBO'`
- `preventive_machine_type`: `'PERISHABLE_FOOD'`, `'HOT_DRINKS'`, `'COLD_DRINKS'`, `'SNACKS'`, `'COMBO'`
- `urgency_level`: `'LOW'`, `'MEDIUM'`, `'HIGH'`, `'CRITICAL'`
- `incident_category`: `'TEMPERATURE_COLD'`, `'PAYMENT_SYSTEM'`, `'PRODUCT_JAM'`, `'ELECTRICAL_OFF'`, `'OTHER'`
- `incident_status`: `'REGISTERED'`, `'ASSIGNED'`, `'IN_PROGRESS'`, `'PENDING_PARTS'`, `'PENDING_INFO'`, `'RESOLVED'`, `'REOPENED'`, `'CLOSED'`, `'CANCELLED'`
- `sanitary_status`: `'OK'`, `'ATTENTION_REQUIRED'`, `'EXPIRED'`, `'QUARANTINE'`, `'SEASONAL_PAUSE'`
- `comment_author_type`: `'REPORTER'`, `'TECHNICIAN'`, `'COORDINATOR'`, `'SYSTEM'`
- `audit_entity_type`: catálogo completo en el contrato de correspondencia (§2), ampliado con `'ROOT_CAUSE_TAXONOMY'` por la migración `021_root_cause_taxonomy.sql` (Módulo 25), con `'RESTOCKER_ZONE'` por `027_restocker_role_and_zone_assignments.sql` (Módulo 31) y con `'VENDING_PRODUCT'` y `'MACHINE_PLANOGRAM'` por `028_product_catalog_and_planogram_slots.sql` (Módulo 32). Los tipos `vending_product_category` (5 categorías) y `vending_product_unit_type` (`UNIT`, `GRAMS`, `SLEEVE`) llegan con esa misma migración, y con `'MACHINE_RESTOCK_VISIT'` por `030_in_situ_restock_visit_and_fefo.sql` (Módulo 34)
- `incident_assignment_source`: `'COORDINATOR_ASSIGN'`, `'COORDINATOR_REASSIGN'`, `'ABSENCE_COVERAGE_REASSIGN'`, `'OPPORTUNISTIC_NEW'`, `'OPPORTUNISTIC_ASSUMED'`, `'OPPORTUNISTIC_TAKEOVER'`, `'OPPORTUNISTIC_WARRANTY_REOPEN'`, `'SHIFT_HANDOVER'`, `'SANITARY_FAILSAFE_GUARD'` (Módulo 26)
- `incident_unassignment_reason_type`: las 10 causas de cierre de tramo, de `'REASSIGNED_BY_COORDINATOR'` a `'CANCELLED'` (Módulo 26)
- `sla_tier`: `'STANDARD'`, `'PRIORITY_CORPORATE'`, `'CRITICAL_INFRASTRUCTURE_24_7'` (Módulo 30)
- `product_loading_order_status`: `'PENDING_PICKING'`, `'DISPATCHED'`, `'CONFIRMED_BY_RESTOCKER'`, `'RECONCILED_AT_CLOSE'`, `'CANCELLED'` (Módulo 33)
- `product_loading_demand_mode`: `'ALERTS_ONLY'`, `'ALL_GAPS'` (Módulo 33)
- `product_loading_item_reason`: `'STOCKOUT_ALERT'`, `'LOW_STOCK_ALERT'`, `'ROUTE_GAP_FILL'`, `'MANUAL_ADDITION'` (Módulo 33)
- `restock_visit_status`: `'COMPLETED'`, `'ABORTED_THERMAL_BREACH'` (Módulo 34)
- *Otros enumerados de dominio:* `spare_part_category`, `inspection_result`, `approval_decision`, `compensation_method`, `technician_finding`, etc.

---

Los dos enumerados del Módulo 27 nacen con la migración `023_workshop_repair_items.sql`:
`workshop_repair_status` (`PENDING_DELIVERY`, `RECEIVED_IN_WORKSHOP`, `IN_BENCH_TEST`,
`REFURBISHED_RESTORED`, `SCRAPPED_IRREPARABLE`) y `workshop_restock_destination`
(`CENTRAL_WAREHOUSE`, `TECHNICIAN_VAN`). El catálogo `audit_entity_type` incorpora el valor
`WORKSHOP_REPAIR_ITEM` mediante `ALTER TYPE ... ADD VALUE IF NOT EXISTS`.

## 4. Disparadores y Funciones Constitucionales

- **`prevent_hard_delete()`**: Función disparadora `BEFORE DELETE` en las 48 tablas del dominio. Si `current_setting('vendguard.purge', true) = 'on'`, autoriza el borrado para tareas de mantenimiento del arnés. De lo contrario, interrumpe la transacción con excepción constitucional `45000`.
- **`touch_updated_at()`**: Función disparadora `BEFORE UPDATE` que actualiza automáticamente la columna `updated_at` con `statement_timestamp()` si no fue modificada explícitamente en la sentencia.
- **`enforce_incident_assignments_immutability()` (Módulo 26):** Función disparadora `BEFORE UPDATE` que rechaza con `SQLSTATE 45000` cualquier cambio en los datos de apertura o en los snapshots de un tramo de custodia y cualquier modificación de un tramo ya cerrado (Art. III.3, EARS 01.3). La Tabla 37 `incident_assignments` (15 columnas) conserva los tramos con las identidades congeladas de técnico, asignador y cerrador, la coherencia todo-o-nada entre tramo abierto y cerrado, y el índice único parcial `uq_incident_assignments_active_custody (incident_id) WHERE unassigned_at IS NULL`, que garantiza un solo custodio activo por avería (Art. V.3). El backfill de la migración `022_incident_assignments_custody_chain.sql` reconstruye los expedientes anteriores con los valores de respaldo `SIN-CODIGO`, `Coordinación / Sistema` y `Registro histórico previo` (D4, QA 5).
- **Guías de diagnóstico por modelo (Módulo 28, RF-05.2):** `machine_troubleshooting_guides` (Tabla 39, 14 columnas) declara el modelo visible y su clave plegada a minúsculas (`chk_troubleshooting_guides_model_not_empty`), `breakdown_type` del tipo `incident_category`, el título de 5 a 150 caracteres y los tres documentos `JSONB`: `error_codes_json` (0 a 20 entradas), `steps_json` (2 a 10 pasos) y `recommended_spare_part_ids` (0 a 10 identificadores). La identidad funcional es la clave única `uq_troubleshooting_guides_model_category (machine_model_key, breakdown_type)`: una sola guía por modelo y categoría, activa o inactiva (C1). `suggested_root_cause_id` referencia `root_causes` con `ON DELETE RESTRICT ON UPDATE CASCADE`, y `created_by`/`updated_by` referencian `users`. Tres índices sirven el conmutador del técnico, el catálogo por categoría y la causa sugerida. Los disparadores `trg_no_delete_machine_troubleshooting_guides` (`prevent_hard_delete()`), `trg_touch_machine_troubleshooting_guides` (`touch_updated_at()`) y `trg_immutable_machine_troubleshooting_guides` rechazan el borrado físico y el cambio de modelo o categoría tras el alta (Art. III.1 y III.3, C1). El valor `MACHINE_TROUBLESHOOTING_GUIDE` amplía `audit_entity_type`; todo llega con la migración `024_machine_troubleshooting_guides.sql`.
- **Acta de merma y destrucción de lotes (Módulo 29, RF-01 a RF-05):** `sanitary_spoilage_records` (Tabla 40, 22 columnas) certifica cada levantamiento de cuarentena sanitaria con el código oficial `ACTA-MERMA-AAAA-NNNN`, la máquina, la avería u orden preventiva vinculada, el técnico certificador in situ y el usuario registrador, el origen de la cuarentena (`BIOLOGICAL_CLOCK_4H`, `PREVENTIVE_NON_CONFORMITY`, `MANUAL_SANITARY_LOCK`), las unidades y lotes destruidos —o la confirmación de máquina vacía—, las temperaturas de retirada y reapertura, el método de destrucción, la fotografía de evidencia, las observaciones de higienización, el coste estimado con su ajuste auditado y el certificado higiénico emitido. Seis claves foráneas `ON DELETE RESTRICT ON UPDATE CASCADE` y nueve restricciones `CHECK` impiden un acta incoherente: `chk_spoilage_records_empty_coherence` separa la rama de máquina vacía (unidades `0`, confirmación expresa y método `NOT_APPLICABLE_EMPTY`) de la de producto destruido (unidades `> 0`, método real y sin confirmación de vacía), y `chk_spoilage_records_loss_non_negative` obliga a coste `0,00 €` sin ajuste en la primera y a coste positivo acotado en la segunda (C5, D3). `trg_no_delete_sanitary_spoilage_records` (`prevent_hard_delete()`), `trg_touch_sanitary_spoilage_records` (`touch_updated_at()`) y `trg_immutable_sanitary_spoilage_records` (`enforce_sanitary_spoilage_records_immutability()`) prohíben el borrado físico y cualquier cambio de los hechos sanitarios o físicos tras la emisión, admitiendo solo `estimated_merchandise_loss_eur`, `loss_adjusted_by` y `loss_adjustment_reason` (Art. III.1 y III.3, RNF-01). El valor `SANITARY_SPOILAGE_RECORD` amplía `audit_entity_type`; todo llega con la migración `025_sanitary_spoilage_records.sql`.
- **`enforce_root_causes_immutability()` y `enforce_standard_solutions_immutability()` (Módulo 25):** Funciones disparadoras `BEFORE UPDATE` que rechazan con `SQLSTATE 45000` cualquier intento de cambiar `code`, `category` o `root_cause_id` tras el alta del catálogo (C4, QA 1).
- **Taxonomía de causas raíz (Módulo 25):** `root_causes` (8 columnas) y `standard_solutions` (8 columnas) son maestras de baja lógica, con `trg_no_delete_*` y la unicidad parcial `uq_standard_solutions_default_per_cause (root_cause_id) WHERE is_default = TRUE AND is_active = TRUE`. `incidents` pasa a 50 columnas con las dos referencias y los cinco snapshots congelados, y `chk_incidents_taxonomy_coherence` impone la coherencia todo-o-nada. El índice `idx_incidents_pareto_resolved` sirve la analítica de Pareto por `resolved_at` vigente.

- **Perfiles de SLA diferenciados por sede (Módulo 30, RF-01 a RF-03):** `locations.sla_tier` declara el perfil contractual (`STANDARD`, `PRIORITY_CORPORATE` o `CRITICAL_INFRASTRUCTURE_24_7`) con defecto `STANDARD` e índice parcial `idx_locations_sla_tier` para el catálogo de sedes activas. `incidents` incorpora `sla_tier_snapshot` (perfil congelado en el alta, `NOT NULL DEFAULT 'STANDARD'`), `sla_target_minutes` (`NOT NULL DEFAULT 1440`) y `sla_target_at` (vencimiento). `chk_incidents_sla_target_minutes` acota los cinco objetivos de la matriz RF-01 (240, 360, 480, 720 y 1440 min) y `idx_incidents_sla_target_lookup` sirve la consulta por estado y vencimiento. `trg_fn_incidents_protect_sla_tier_snapshot` y `trg_incidents_protect_sla_tier_snapshot` rechazan con `SQLSTATE 23514` (`IMMUTABLE_SLA_TIER_SNAPSHOT`) cualquier cambio del perfil sellado, mientras que el objetivo y su vencimiento siguen siendo recalculables (C4). El backfill de `026_tiered_sla_contracts.sql` conserva el umbral histórico de los expedientes cerrados (240 min en riesgo de cadena de frío y 1440 min en el resto) y aplica la matriz vigente a los abiertos bajo el perfil de su sede (C5, Art. III.3).

- **Cuadrante de carga del reponedor de ruta (Módulo 31, RF-01.1, RF-02.1, QA 1):** `restocker_zone_assignments` (Tabla 41, 7 columnas) es el cuadrante de carga independiente del técnico: `restocker_id` y `zone_id` (FK `fk_restocker_zone_restocker` y `fk_restocker_zone_zone`, `ON DELETE RESTRICT ON UPDATE CASCADE`), `role_in_zone` (`PRIMARY` | `BACKUP`) e `is_primary` sincronizados por `chk_restocker_zone_role_coherence`, más `assigned_at` y la baja lógica `removed_at` con `chk_restocker_zone_removed_order`. Los índices únicos parciales `uq_restocker_zone_live (restocker_id, zone_id) WHERE removed_at IS NULL` y `uq_restocker_primary_zone (restocker_id) WHERE is_primary = TRUE AND removed_at IS NULL` garantizan una vinculación viva por pareja y una única titularidad viva por reponedor. `trg_no_delete_restocker_zone_assignments` (`prevent_hard_delete()`) prohíbe el borrado físico (Art. III.1) y `chk_users_restocker_not_on_call` impide que un `RESTOCKER` quede marcado de guardia técnica (Art. II). El valor `RESTOCKER` amplía `user_role` y `RESTOCKER_ZONE` el enumerado de auditoría; todo llega con la migración `027_restocker_role_and_zone_assignments.sql`.

- **Catálogo comercial y planograma por máquina (Módulo 32, RF-01.1, RF-02.1, RF-03.1, RF-03.2, QA 1, Art. II y III.1):** `vending_products` (Tabla 42, 12 columnas) es el catálogo maestro: `sku VARCHAR(32)` único e inmutable tras el alta (`SKU_CHANGE_FORBIDDEN`), nombre no vacío, `category` del tipo `vending_product_category` (5 valores) y `unit_type` del tipo `vending_product_unit_type` (`UNIT`, `GRAMS`, `SLEEVE`), con `chk_vending_products_unit_type_coherence` atando la unidad a la categoría. `unit_cost_eur` y `sale_price_eur` son `NUMERIC(10,2)` acotados; el precio 0,00 € solo se admite en ingredientes y consumibles (`chk_vending_products_sale_price`, C2). `chk_vending_products_perishable_cold_chain` obliga a cadena de frío en `PERISHABLE_FOOD`, `chk_vending_products_cold_chain_allowed_categories` la limita a perecederos, bebidas frías y snacks, y `chk_vending_products_shelf_life` exige vida útil declarada cuando hay frío (1 a 3650 días, QA 1). La baja es lógica (`is_active = FALSE`) y `trg_no_delete_vending_products` (`prevent_hard_delete()`) cierra el borrado físico (Art. III.1); `trg_touch_vending_products` mantiene `updated_at` y `trg_validate_product_update_against_planograms` rechaza el cambio de categoría o de cadena de frío cuando ya hay carriles activos incompatibles (RF-03.2). `machine_planogram_slots` (Tabla 43, 10 columnas) declara la identidad única `uq_machine_planogram_slot (machine_id, slot_code)` y las FK `ON DELETE RESTRICT ON UPDATE CASCADE` a máquinas y productos, con capacidad (1 a 10 000), umbral estrictamente menor que la capacidad y existencia estimada dentro de la capacidad (`chk_machine_planogram_*`). `trg_no_delete_machine_planogram_slots` y `trg_touch_machine_planogram_slots` completan el ciclo, y `trg_enforce_planogram_cold_chain_and_category` impone la matriz `MachineType × ProductCategory` al sembrar o editar un carril activo (D3, Art. II). La matriz vive en una única función inmutable `is_planogram_product_compatible_with_machine()`, que también consultan los cerrojos inversos del producto y de la máquina (`trg_validate_machine_type_against_planogram`, QA 4). Todo llega con la migración `028_product_catalog_and_planogram_slots.sql`.

- **Órdenes de carga de furgoneta y picking con trazabilidad de frío (Módulo 33, RF-01.1, RF-03.1, RF-03.3, D2, D3, QA 4, Art. II y III.1):** `restocker_van_product_stocks` (Tabla 44, 8 columnas) declara la identidad `uq_restocker_van_product_batch (restocker_id, product_id, batch_code)`: dos lotes del mismo producto conviven en la furgoneta porque caducan distinto, `batch_code` se normaliza a `'SIN-LOTE'` en los no perecederos y las FK hacia `users` y `vending_products` son `ON DELETE RESTRICT ON UPDATE CASCADE`. `chk_restocker_van_product_stocks_batch_format` acota el lote a `^[A-Z0-9_-]{2,40}$` y `chk_restocker_van_product_stocks_qty_range` la existencia a `0..99999`. `product_loading_orders` (Tabla 45, 19 columnas) declara `order_code` único con formato `PLO-AAAA-NNNN`, el ciclo `PENDING_PICKING` → `DISPATCHED` → `CONFIRMED_BY_RESTOCKER` → `RECONCILED_AT_CLOSE` con `CANCELLED` como salida justificada, el buffer `0..50 %`, la temperatura de salida de cámara en `[-5,0, 4,0] °C` y las coherencias de cancelación (fecha, motivo y 20 caracteres reales) y de liquidación (fecha y responsable). El índice único parcial `uq_product_loading_orders_active_restocker (restocker_id) WHERE status IN ('PENDING_PICKING', 'DISPATCHED', 'CONFIRMED_BY_RESTOCKER')` impide dos órdenes activas del mismo reponedor (D3). `product_loading_order_items` (Tabla 46, 17 columnas) declara la identidad `uq_product_loading_order_item_product (order_id, product_id)`, la baja lógica `is_active` (C1) y el cuadre como identidad aritmética: `chk_product_loading_order_items_reconcile_bounds` exige, o los seis campos vacíos, o `expected_return_qty = van_stock_before_reconcile - restocked_in_machines_qty - withdrawn_spoilage_qty` y `discrepancy_qty = counted_return_qty - expected_return_qty` con no negatividad (D3, QA 4). Las tres tablas declaran `trg_no_delete_*` (`prevent_hard_delete()`, Art. III.1) y `trg_touch_*`, y el cerrojo sanitario vive en el motor: `enforce_van_stock_cold_chain_batch()` exige lote real y caducidad al producto refrigerado y `enforce_loading_order_cold_chain_dispatch()` exige temperatura `[-5,0, 4,0] °C` y lote real en cada línea refrigerada servida al pasar a `DISPATCHED`, `CONFIRMED_BY_RESTOCKER` o `RECONCILED_AT_CLOSE`, ambos con `SQLSTATE 45000` (Art. II, D2, QA 2). Los valores `RESTOCKER_VAN_STOCK` y `PRODUCT_LOADING_ORDER` amplían `audit_entity_type`; todo llega con la migración `029_product_loading_orders_and_cold_picking.sql`.

---

- **Visita de reposición in situ y trazabilidad FEFO (Módulo 34, RF-01.1, RF-01.2, D1 a D4, QA 1 a QA 4, Art. II y III):** `machine_planogram_slots` (Tabla 43) pasa a 12 columnas con `current_batch_code VARCHAR(40)` y `current_expiration_date DATE`, ambos nulos, y `chk_machine_planogram_current_batch_format` acota el lote a `^[A-Z0-9_-]{2,40}$`. `machine_restock_visits` (Tabla 47, 17 columnas) es la cabecera histórica de la visita: `visit_code` único con formato `MRV-AAAA-NNNN`, `status` del tipo `restock_visit_status` (`COMPLETED`, `ABORTED_THERMAL_BREACH`), la lectura `cabin_temp_celsius` en `[-5,0, 35,0] °C`, el protocolo sanitario (`hygiene_checklist_json`, `precautionary_seal_applied`, `photo_evidence_url`, `internal_notes`), el vínculo nullable a la orden de carga del Módulo 33 y a la avería abierta, y `client_mutation_id`, cuya unicidad `uq_machine_restock_visits_mutation (restocker_id, client_mutation_id)` es la idempotencia del móvil (D4). Cuatro claves foráneas `ON DELETE RESTRICT ON UPDATE CASCADE` y cinco `CHECK` cierran la cabecera: el formato de código y de mutación, el orden temporal `completed_at >= started_at`, el rango térmico y `chk_machine_restock_visits_thermal_abort_coherence`, que sólo admite el aborto con precinto, lectura, foto y nota de 20 caracteres reales (Art. V.1). `machine_restock_visit_items` (Tabla 48, 17 columnas) declara la identidad `uq_machine_restock_visit_item_slot (visit_id, slot_code)`, tres claves foráneas `RESTRICT` a la visita, al carril y al producto, y las identidades aritméticas del C1 en `chk_machine_restock_visit_items_qty_bounds` (`0 <= retiradas <= qty_before`, `qty_after = qty_before - retiradas + añadidas`), el formato de los lotes en `chk_machine_restock_visit_items_batch_formats` y el snapshot contable congelado en `chk_machine_restock_visit_items_cost_coherence` (`commercial_spoilage_loss_eur = retiradas × unit_cost_snapshot_eur`, QA 3, Art. V.4). El cerrojo sanitario vive en el motor: `enforce_restock_visit_sanitary_guard()` exige lectura térmica en `PERISHABLE_FOOD`, `COMBO` y `COLD_DRINKS` (`ART_II_CABIN_TEMP_REQUIRED`) y rechaza una visita `COMPLETED` por encima del techo de 4,0 °C o 8,0 °C (`ART_II_CABIN_TEMP_EXCEEDED`); `enforce_restock_visit_item_guard()` impide cargar una sola unidad en una visita abortada (`ART_II_THERMAL_ABORT_ADD_FORBIDDEN`). Las dos tablas declaran `trg_no_delete_*` (`prevent_hard_delete()`, Art. III.1) y `trg_immutable_*` sobre `prevent_restock_visit_mutation()`, que rechaza toda reescritura con `45000` (`ART_III_3_IMMUTABLE_VISIT_VIOLATION`, Art. III.3): una corrección se registra como visita nueva. El tipo `restock_visit_status` y el valor `MACHINE_RESTOCK_VISIT` de `audit_entity_type` llegan con la migración `030_in_situ_restock_visit_and_fefo.sql`.

---

## 5. Convergencia Futura de Esquema

De acuerdo con **EARS-13.3** y **EARS-02.4**:
- `database/supabase_init.sql` contiene la definición DDL base canónica.
- Toda alteración o evolución futura de la base de datos se debe agregar de forma incremental en `database/supabase_migrations/NNN_<nombre>.sql` para ser aplicada por `PostgresMigrationRunner`.
