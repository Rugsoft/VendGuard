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
1. **Artículo III de la Constitución:** Prohibición absoluta de borrado físico (`DELETE FROM`). Todas las 20 tablas del dominio cuentan con disparadores `BEFORE DELETE` vinculados a la función constitucional `prevent_hard_delete()`, que rechaza con `SQLSTATE 45000` (`ART_III_1_VIOLATION`) cualquier intento de borrado físico que no ocurra bajo la ventana de purga autorizada del arnés de pruebas. El borrado lógico se gestiona mediante `deleted_at TIMESTAMPTZ NULL`.
2. **Artículo V de la Constitución & EARS 2.1:** Restricción de unicidad estricta mediante el índice único parcial `uq_machine_active_ticket` para garantizar que **ninguna máquina pueda tener más de una incidencia activa de forma simultánea** (incluyendo el estado `RESOLVED` durante la ventana de garantía sanitaria de 48 horas).
3. **Artículo II de la Constitución:** Integridad en tipos de máquina (`machine_type`), categorías de fallo (`incident_category`) y estados sanitarios (`sanitary_status`) para la supervisión y control estricto de la cadena de frío.
4. **Artículo V.1 de la Constitución:** Longitudes mínimas exigidas por restricciones `CHECK` nativas en base de datos (`chk_resolution_diagnosis_len`, `chk_resolution_action_len`, etc.) garantizando explicaciones técnicas suficientes (mínimo 20 caracteres no vacíos).
5. **Nomenclatura (`AGENTS.md`):** Tablas, columnas, índices, enumerados y restricciones en **inglés técnico**, manteniendo la documentación explicativa en español.

---

## 2. Inventario de Tablas Canónicas del Sistema (20 tablas + Libro)

El sistema opera sobre **20 tablas de dominio** y **1 tabla de infraestructura de migraciones**:

| # | Tabla | Descripción / Responsabilidad |
|---|---|---|
| 1 | `locations` | Sedes y ubicaciones físicas de las máquinas |
| 2 | `machines` | Parque de máquinas expendedoras |
| 3 | `users` | Cuentas internas de personal (Coordinadores, Técnicos) |
| 4 | `incidents` | Registro central de averías e incidencias |
| 5 | `incident_history` | Trazabilidad inmutable de cambios de estado de incidencias |
| 6 | `incident_comments` | Hilo de evidencias y comentarios de incidencias |
| 7 | `audit_log` | Registro inmutable de auditoría del sistema (Art. VI) |
| 8 | `route_settings` | Parámetros y coordenadas base para cálculo de rutas |
| 9 | `preventive_settings` | Frecuencias y ventanas de mantenimiento preventivo |
| 10 | `preventive_orders` | Órdenes de trabajo preventivas planificadas |
| 11 | `preventive_order_items` | Checklist normativo de cada orden preventiva |
| 12 | `sanitary_certificates` | Certificaciones de higienización y calibración térmica |
| 13 | `spare_parts` | Catálogo de piezas de repuesto |
| 14 | `spare_part_compatibilities` | Compatibilidad entre repuestos y modelos de máquina |
| 15 | `spare_part_requests` | Peticiones de repuestos cursadas por técnicos |
| 16 | `incident_replaced_parts` | Repuestos consumidos y sustituidos en intervenciones |
| 17 | `refund_requests` | Solicitudes y liquidación de reintegros económicos |
| 18 | `unclaimed_cash_findings` | Hallazgos de efectivo sobrante no reclamado |
| 19 | `sanitary_sweep_passes` | Registro de pasadas del barrido automático del reloj biológico |
| 20 | `sanitary_sweep_contentions` | Seguimiento de contenciones de bloqueo durante el barrido |
| + | `schema_migrations` | Libro de control del ejecutor `PostgresMigrationRunner` |

---

## 3. Tipos Enumerados Nativos (ENUM)

El esquema implementa 24 tipos `ENUM` nativos en PostgreSQL:
- `user_role`: `'COORDINATOR'`, `'TECHNICIAN'`
- `machine_type`: `'HOT_DRINKS'`, `'COLD_DRINKS'`, `'SNACKS'`, `'PERISHABLE_FOOD'`, `'COMBO'`
- `preventive_machine_type`: `'PERISHABLE_FOOD'`, `'HOT_DRINKS'`, `'COLD_DRINKS'`, `'SNACKS'`, `'COMBO'`
- `urgency_level`: `'LOW'`, `'MEDIUM'`, `'HIGH'`, `'CRITICAL'`
- `incident_category`: `'TEMPERATURE_COLD'`, `'PAYMENT_SYSTEM'`, `'PRODUCT_JAM'`, `'ELECTRICAL_OFF'`, `'OTHER'`
- `incident_status`: `'REGISTERED'`, `'ASSIGNED'`, `'IN_PROGRESS'`, `'PENDING_PARTS'`, `'PENDING_INFO'`, `'RESOLVED'`, `'REOPENED'`, `'CLOSED'`, `'CANCELLED'`
- `sanitary_status`: `'OK'`, `'ATTENTION_REQUIRED'`, `'EXPIRED'`, `'QUARANTINE'`, `'SEASONAL_PAUSE'`
- `comment_author_type`: `'REPORTER'`, `'TECHNICIAN'`, `'COORDINATOR'`, `'SYSTEM'`
- `audit_entity_type`: `'TICKET'`, `'MACHINE'`, `'LOCATION'`, `'USER'`, `'PREVENTIVE_ORDER'`, `'REFUND_REQUEST'`, `'UNCLAIMED_CASH_FINDING'`
- *Otros enumerados de dominio:* `spare_part_category`, `inspection_result`, `approval_decision`, `compensation_method`, `technician_finding`, etc.

---

## 4. Disparadores y Funciones Constitucionales

- **`prevent_hard_delete()`**: Función disparadora `BEFORE DELETE` en las 20 tablas del dominio. Si `current_setting('vendguard.purge', true) = 'on'`, autoriza el borrado para tareas de mantenimiento del arnés. De lo contrario, interrumpe la transacción con excepción constitucional `45000`.
- **`touch_updated_at()`**: Función disparadora `BEFORE UPDATE` que actualiza automáticamente la columna `updated_at` con `statement_timestamp()` si no fue modificada explícitamente en la sentencia.

---

## 5. Convergencia Futura de Esquema

De acuerdo con **EARS-13.3** y **EARS-02.4**:
- `database/supabase_init.sql` contiene la definición DDL base canónica.
- Toda alteración o evolución futura de la base de datos se debe agregar de forma incremental en `database/supabase_migrations/NNN_<nombre>.sql` para ser aplicada por `PostgresMigrationRunner`.
