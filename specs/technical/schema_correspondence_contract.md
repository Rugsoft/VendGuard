# CONTRATO DE ESQUEMA DE BASE DE DATOS · POSTGRESQL (SUPABASE)

**Proyecto:** Gestor de Incidencias de Vending (*VendGuard*)  
**Documento:** `specs/technical/schema_correspondence_contract.md`  
**Ámbito:** Contrato de persistencia y esquema canónico del motor único PostgreSQL (Módulo 13, EARS-13.3)  
**Normativa Suprema:** [constitution.md](../../constitution.md) (Artículos I, II, III, V y VI)  
**Fuentes Canónicas:** [`database/supabase_init.sql`](../../database/supabase_init.sql) + [`database/supabase_migrations/`](../../database/supabase_migrations/)  

---

## 1. Declaración de Motor Único

A partir de la enmienda de motor único (Módulo 13), el sistema opera exclusivamente sobre **PostgreSQL (Supabase)** en todos los entornos:
- **Producción:** PostgreSQL administrado (Supabase) con agrupador transaccional Supavisor (puerto 6543) y TLS `verify-full`.
- **Certificación y pruebas locales:** PostgreSQL local hermético en contenedor TLS (puerto 55433).
- **Desarrollo:** Instancia local de PostgreSQL configurada a través de `ConnectionFactory`.

Queda completamente retirado el camino dual de persistencia (MariaDB/MySQL).

---

## 2. Inventario de las 34 Tablas del Dominio

Todas las tablas cuentan con clave primaria y disparador constitucional `trg_no_delete_*` vinculado a `prevent_hard_delete()` (Art. III.1). `incident_service_sheets` e `incident_satisfaction_surveys` son además **de solo inserción**: `trg_no_update_incident_service_sheets` (`prevent_service_sheet_rewrite()`) e `trg_no_update_incident_satisfaction_surveys` (`prevent_satisfaction_survey_rewrite()`) rechazan cualquier `UPDATE` con `45000`, porque la hoja firmada es prueba documental y la valoración de la sede no se rectifica (RD-01 y RD-06 del Módulo 20). La clave primaria es `id BIGINT GENERATED ALWAYS AS IDENTITY` salvo en `operational_zone_postal_codes`, donde la identidad de la fila es el propio código postal: es la forma de garantizar que un CP pertenezca a una sola zona en todo su ciclo de vida (RF-01.2, Resolución QA 7).

1. `locations` (20 columnas)
2. `machines` (19 columnas)
3. `users` (13 columnas)
4. `incidents` (43 columnas)
5. `incident_history` (7 columnas)
6. `incident_comments` (9 columnas)
7. `incident_replaced_parts` (17 columnas)
8. `refund_requests` (35 columnas)
9. `unclaimed_cash_findings` (7 columnas)
10. `spare_parts` (11 columnas)
11. `spare_part_compatibilities` (6 columnas)
12. `spare_part_requests` (10 columnas)
13. `preventive_settings` (7 columnas)
14. `preventive_orders` (20 columnas)
15. `preventive_order_items` (9 columnas)
16. `sanitary_certificates` (18 columnas)
17. `route_settings` (12 columnas)
18. `sanitary_sweep_passes` (9 columnas)
19. `sanitary_sweep_contentions` (9 columnas)
20. `audit_log` (11 columnas)
21. `notification_outbox` (23 columnas)
22. `notification_channel_settings` (11 columnas)
23: `operational_zones` (8 columnas)
24. `operational_zone_postal_codes` (3 columnas)
25. `technician_zone_assignments` (6 columnas)
26. `technician_van_stocks` (7 columnas)
27. `incident_service_sheets` (10 columnas)
28. `incident_satisfaction_surveys` (9 columnas)
29. `technician_absences` (11 columnas)
30. `technician_shifts` (21 columnas)
31. `technician_shift_logs` (10 columnas)
32. `van_restock_orders` (15 columnas)
33. `van_restock_order_items` (16 columnas)
34. `offline_sync_mutations` (16 columnas)

Adicionalmente, `schema_migrations` gestiona el registro de huellas SHA-256 de las migraciones incrementales.

---

## 3. Integridad y Blindaje en Base de Datos

- **Unicidad de ticket activo:** Se materializa mediante el índice único parcial:
  ```sql
  CREATE UNIQUE INDEX uq_machine_active_ticket ON incidents (machine_id)
  WHERE status NOT IN ('CLOSED', 'CANCELLED');
  ```
- **Claves canónicas plegadas:**
  - `users.email_key` con índice único `uq_users_email_key` para unicidad estricta insensible a acentos y mayúsculas.
  - `locations.site_code_key` con índice único `uq_locations_site_code_key`.
- **Restricciones CHECK de longitud mínima (Art. V.1):** 7 restricciones de longitud en sentencias que validan un mínimo de 20 caracteres significativos sin espacios en blanco superfluos.
- **Tipado estricto:** 30 enumerados nativos `ENUM` que encapsulan los estados y categorías del dominio.
- **Coherencia de la avería oportunista in situ (Módulo 16):** `chk_incidents_opportunistic_coherence` exige el acompañamiento mutuo (ni modalidad sin submotivo ni submotivo sin modalidad, RF-04.1) y limita los motivos admitidos por cada modalidad (`NEW`: aviso verbal en sede, detección propia o reapertura en garantía; `ASSUMED`: asunción de ticket abierto o reapertura en garantía).
- **Cadena de reincidencias (Art. III.1):** `fk_incidents_chronic_predecessor` enlaza el expediente sucesor con el crónico que cierra, con `ON DELETE RESTRICT` para no romper la traza histórica y `ON UPDATE CASCADE`.
- **Índice parcial oportunista:** `idx_incidents_opportunistic_mode` sobre `(opportunistic_mode, created_at)` y sólo las filas con modalidad no borradas lógicamente, que es el universo del indicador de desplazamientos evitados (RF-06.4).
- **Arranque idempotente sobre bases anteriores:** el DDL base se ejecuta en cada arranque, no sólo en el primero. Un índice declarado en él sobre una columna que introduce una migración (como `opportunistic_mode`, añadida por `005_opportunistic_incident.sql`) se crea dentro de un bloque `DO` que tolera `duplicate_object` y `undefined_column`: la base preexistente continúa hasta el ejecutor de migraciones y es éste quien añade la columna y el índice. Sin esa guarda el arranque abortaba con SQLSTATE 42703 antes de migrar (EARS-02.5).
- **Plantillas del Módulo 16:** `OPPORTUNISTIC_COLD_ROUTE_POSTPONED_ALERT` e `INCIDENT_REOPENED_IN_SITU_SITE` amplían el catálogo cerrado de `notification_template`, que pasa a 21 valores.
- **Unicidad territorial del código postal (Módulo 17, RF-01.2 / QA 13):** `operational_zone_postal_codes` tiene clave primaria sustituta `id` y `released_at TIMESTAMPTZ`; el formato del CP se blinda con `chk_postal_code_format CHECK (postal_code ~ '^[0-9]{5}$')`. Un CP pertenece a una única zona **viva**, garantizado por el índice único parcial `uq_zone_postal_codes_live (postal_code) WHERE released_at IS NULL`. La desactivación de la zona libera sus filas en la misma transacción marcándolas con `released_at` (UPDATE, nunca DELETE): el CP queda disponible para otra zona y el historial se conserva (Art. III.1).
- **Titularidad única por técnico (Módulo 17, RF-02.1):** el índice único parcial `uq_technician_primary_zone` sobre `(technician_id) WHERE is_primary = TRUE AND removed_at IS NULL` impide que un técnico sea titular principal de dos zonas vivas a la vez; `uq_technician_zone_live` sobre `(technician_id, zone_id) WHERE removed_at IS NULL` permite reingresos sin borrar el histórico (Resolución QA 10).
- **Herencia territorial de la sede (Módulo 17, RF-03):** `locations` declara `zone_id` (FK `fk_locations_zone`, `ON DELETE SET NULL`), `is_manual_override` y `postal_code VARCHAR(5)` con `chk_locations_postal_code_format CHECK (postal_code IS NULL OR postal_code ~ '^[0-9]{5}$')`. El CP admite `NULL` para no inventar datos en sedes históricas; el alta y la edición lo exigen en la aplicación. Índices `idx_locations_zone_id` e `idx_locations_postal_code`.
- **Formato de la zona (Módulo 17, RF-01.1):** `chk_operational_zones_code_len`, `chk_operational_zones_name_len` y `chk_operational_zones_color_format CHECK (color_hex ~* '^#[0-9A-Fa-f]{6}$')`, además de las claves únicas de código y nombre.
- **Auditoría territorial (Módulo 17, RNF-03 / QA 11):** el enumerado `audit_entity_type` añade `OPERATIONAL_ZONE` y `TECHNICIAN_ZONE` mediante la migración `007_operational_zones.sql` con `ALTER TYPE ... ADD VALUE IF NOT EXISTS`, y el traslado masivo de códigos postales emite un evento matriz `ZONE_POSTAL_CODE_TRANSFERRED` más un evento `LOCATION_ZONE_REASSIGNED` por sede afectada en la misma transacción (QA 6).
- **Encuesta de satisfacción por episodio de resolución (Módulo 20, RF-01 a RF-04):** `incident_satisfaction_surveys` declara `incident_id` (FK `fk_survey_incident`), `location_id` (FK `fk_survey_location`) y `technician_id` (FK `fk_survey_technician`, nullable), todas con `ON DELETE RESTRICT` y `ON UPDATE CASCADE`. La identidad funcional es la pareja `uq_survey_episode (incident_id, resolution_at)`: `resolution_at` es copia exacta de `incidents.resolved_at` y distingue la valoración vigente de las históricas tras una reapertura (RD-01). La nota se acota con `chk_survey_rating CHECK (rating BETWEEN 1 AND 5)` y el comentario opcional con `chk_survey_comment CHECK (comment IS NULL OR char_length(btrim(comment)) BETWEEN 1 AND 500)`. `technician_name_snapshot VARCHAR(100)` congela el nombre del técnico para que un renombrado no reescriba la métrica (R8). Tres índices cubren listado y agregados: `idx_surveys_resolution_at`, `idx_surveys_location` e `idx_surveys_technician`. No añade valores al enumerado de auditoría: el evento `SUBMIT_SATISFACTION_SURVEY` reutiliza la entidad `TICKET` y `action` es `VARCHAR(64)`.
- **Saldo declarado de la furgoneta (Módulo 18, RF-01 a RF-05):** `technician_van_stocks` declara `technician_id` y `spare_part_id` (FK `fk_van_stock_technician` y `fk_van_stock_spare_part`, ambas con `ON DELETE RESTRICT` y `ON UPDATE CASCADE`), con la unicidad `uq_van_stock_technician_part (technician_id, spare_part_id)`: el saldo es un valor vigente, no un libro de movimientos. Las cotas `chk_van_stock_quantity_range CHECK (quantity_available BETWEEN -999 AND 9999)` y `chk_van_stock_threshold_range CHECK (min_threshold BETWEEN 0 AND 9999)` acotan la erratura de captura **sin prohibir el negativo**: una cantidad negativa es un descuadre declarado y no puede bloquear el cierre de la avería (RD-04). No hay columna de coste ni de valoración (RD-05): el coste del repuesto se congela inmutablemente en `incident_replaced_parts.unit_cost_snapshot`. El enumerado `audit_entity_type` añade `TECHNICIAN_VAN_STOCK` mediante `009_technician_van_stocks.sql`.
- **Hoja de servicio firmada (Módulo 19, RF-03):** `incident_service_sheets` declara `incident_id` y `technician_id` (FK `fk_service_sheet_incident` y `fk_service_sheet_technician`, ambas con `ON DELETE RESTRICT` y `ON UPDATE CASCADE`), el ancla de vigencia `resolution_at TIMESTAMPTZ NOT NULL` y el documento congelado `signed_document JSONB NOT NULL`. Tres `CHECK` blindan la fila: `chk_service_sheet_branch` exige exactamente una de las dos ramas (firma con nombre de al menos 3 caracteres tras `btrim` y documento de al menos 5, o ausencia con motivo de al menos 10), `chk_service_sheet_png_size` acota el trazo entre 64 y 307 200 bytes y exige la firma mágica de PNG, y `chk_service_sheet_document` exige un objeto con clave `version`. El índice `idx_service_sheet_incident (incident_id, recorded_at DESC, id DESC)` sirve la hoja vigente sin ordenación en memoria; no hay `UNIQUE (incident_id)` porque una reapertura añade otra hoja (RD-01). El enumerado `audit_entity_type` **no** cambia: la lectura del albarán reutiliza `TICKET` con la acción `VIEW_SERVICE_SHEET` (RD-09).
- **Ausencias y turnos de técnicos (Módulo 21, RF-01 y RF-03):** `technician_absences` declara `technician_id`, `created_by` y `cancelled_by` con FK hacia `users(id)` (`ON DELETE RESTRICT ON UPDATE CASCADE`). Las ausencias se acotan con `chk_tech_absences_dates CHECK (ends_at >= starts_at)`, `chk_tech_absences_reason` (catálogo cerrado de motivos) y `chk_tech_absences_cancellation` (coherencia en cancelación auditada con motivo de al menos 5 caracteres). La integridad temporal se garantiza con la constraint de exclusión `ex_technician_absences_no_overlap` sobre `tstzrange(starts_at, ends_at, '[)')` con predicado parcial `cancelled_at IS NULL`, declarada en el DDL base y en la migración `014_technician_absence_exclusion.sql`; requiere la extensión contrib `btree_gist`. `technician_shifts` modela el ciclo de jornada (`ON_SHIFT`, `MEAL_BREAK`, `OFF_SHIFT`) con `chk_tech_shifts_status`, `chk_tech_shifts_meal_order` y `chk_tech_shifts_close_order`. El soporte de turno partido y múltiples sesiones diarias se garantiza mediante el índice único parcial `uq_tech_shifts_single_active (technician_id) WHERE closed_at IS NULL` (Resolución R3). Ambas tablas cuentan con disparador constitucional `BEFORE DELETE` (`prevent_hard_delete()`, Art. III.1) y `technician_shifts` con `trg_touch_technician_shifts` (`touch_updated_at()`).
- **Jornada y cómputo de horas (Módulo 22, RF-01, RF-02 y RF-03):** `technician_shifts` amplía su contrato con `meal_break_seconds` (acumulado de todas las pausas), `original_started_at`, `original_closed_at` y `original_meal_break_seconds` (marcas inmutables del primer fichaje), `is_force_closed`, `is_adjusted`, `adjusted_at`, `adjusted_by` y `adjustment_reason`. La FK `fk_tech_shifts_adjuster` enlaza al coordinador que rectificó. `chk_tech_shifts_meal_seconds` exige `0 <= meal_break_seconds <= gross_seconds` para turnos cerrados; `chk_tech_shifts_adjustment_consistency` exige que una fila marcada como ajustada tenga instante, actor y motivo de al menos 20 caracteres reales, y que una fila no ajustada no conserve ninguno de esos tres campos. La Tabla 31 `technician_shift_logs` es la bitácora append-only de los seis eventos canónicos (`START_SHIFT`, `PAUSE_MEAL`, `RESUME_SHIFT`, `CLOSE_SHIFT`, `FORCE_CLOSE_SHIFT`, `ADJUST_SHIFT`), con FK a turno, técnico y actor, `chk_shift_logs_event_type`, `chk_shift_logs_adjust_reason` (motivo de al menos 20 caracteres reales sólo para `ADJUST_SHIFT`), índices `idx_tech_shift_logs_shift` e `idx_tech_shift_logs_tech`, y los disparadores `trg_no_delete_technician_shift_logs` (`prevent_hard_delete()`) y `trg_no_update_technician_shift_logs` (`prevent_service_sheet_update()`), que rechazan `DELETE` y `UPDATE` con SQLSTATE `45000` (Art. III.1 y III.3).
- **Órdenes de carga de furgoneta (Módulo 23, RF-01 a RF-05):** `van_restock_orders` declara `technician_id`, `created_by`, `dispatched_by`, `confirmed_by` y `cancelled_by` con FK hacia `users(id)` (`ON DELETE RESTRICT ON UPDATE CASCADE`), el ciclo `PENDING_PICKING` → `DISPATCHED` → `CONFIRMED_BY_TECH` / `CANCELLED` con `chk_van_restock_orders_status`, y las restricciones `chk_van_restock_orders_dispatch_consistency`, `chk_van_restock_orders_confirm_consistency` y `chk_van_restock_orders_cancel_consistency` (esta última exige justificación de al menos 20 caracteres reales tras `btrim`, Art. V.1) y `chk_van_restock_orders_reception_note` (NULL o entre 1 y 500 caracteres para la nota opcional de recepción del técnico). El índice único parcial `uq_van_restock_open_per_tech (technician_id) WHERE status IN ('PENDING_PICKING', 'DISPATCHED')` garantiza una sola orden abierta por técnico (D3). `van_restock_order_items` declara `order_id` y `spare_part_id` (FK `fk_van_restock_items_order` y `fk_van_restock_items_part`, `ON DELETE RESTRICT ON UPDATE CASCADE`), la unicidad `uq_van_restock_order_item_part (order_id, spare_part_id)`, los snapshots `current_quantity_snapshot`, `min_threshold_snapshot`, `pending_request_qty_snapshot`, `part_code_snapshot`, `part_name_snapshot` y `category_snapshot`, la doble cantidad (`chk_van_restock_items_requested_qty` en `[1, 9999]`, `chk_van_restock_items_dispatched_qty` en `[0, 9999]` o `NULL`), el catálogo cerrado de motivos (`LOW_STOCK`, `PENDING_REQUEST`, `BOTH`, `MANUAL`) y la baja lógica `removed_at`/`removed_by` con `chk_van_restock_items_removed_consistency` (QA 1, Art. III.1). Ambas tablas cuentan con `trg_no_delete_*` (`prevent_hard_delete()`) y `trg_touch_*` (`touch_updated_at()`). El enumerado `audit_entity_type` añade `VAN_RESTOCK_ORDER` mediante `016_van_restock_orders.sql`.
- **Libro de idempotencia de la sincronización diferida (Módulo 24, RF-04.2 y RF-04.3):** `offline_sync_mutations` declara `technician_id` (FK `fk_offline_sync_mutations_technician` hacia `users(id)`, `ON DELETE RESTRICT ON UPDATE CASCADE`) y la unicidad `uq_offline_sync_tech_mutation (technician_id, client_mutation_id)`, que es la clave de idempotencia del reenvío: la misma operación del mismo técnico no puede aplicarse dos veces aunque la señal caiga a mitad de subida. `client_mutation_id` se acota con `chk_offline_sync_mutation_id_format` (entre 8 y 64 caracteres tras `btrim`, `^[A-Za-z0-9_.:-]+$`); `chk_offline_sync_stop_type` cierra la parada a `INCIDENT` o `PREVENTIVE_ORDER`; `chk_offline_sync_operation_type` enumera las 9 operaciones que la Decisión D1 autoriza sin conexión; y `chk_offline_sync_outcome_status` acota el resultado a `APPLIED`, `CONFLICT_DUMPED` o `DEACTIVATED_ACCOUNT_DUMP`. `stop_id` es polimórfico y por eso **no** lleva clave foránea: el discriminante `stop_type` identifica la tabla destino y la aplicación escribe la fila dentro de la transacción del cambio de estado, nunca suelta. Las tres marcas temporales (`client_occurred_at`, `effective_occurred_at` y `synced_at`) conservan la hora declarada, la aplicada tras resolver el desfase de reloj y la de recepción (QA 4), con `clock_skew_adjusted` como testigo del ajuste y `response_payload JSONB` replicando la respuesta que el reintento debe recibir. Dos índices sirven las lecturas del expediente y de la bandeja: `idx_offline_sync_mutations_stop (stop_type, stop_id, synced_at DESC)` e `idx_offline_sync_mutations_tech_synced (technician_id, synced_at DESC)`. La tabla porta `trg_no_delete_offline_sync_mutations` (`prevent_hard_delete()`, Art. III.1) y **no** añade valores al enumerado `audit_entity_type`: los volcados se auditan con la entidad de la parada (`TICKET` o `PREVENTIVE_ORDER`).
