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

## 2. Inventario de las 20 Tablas del Dominio

Todas las tablas cuentan con clave primaria `id BIGINT GENERATED ALWAYS AS IDENTITY` y disparador constitucional `trg_no_delete_*` vinculado a `prevent_hard_delete()` (Art. III.1).

1. `locations` (17 columnas)
2. `machines` (19 columnas)
3. `users` (13 columnas)
4. `incidents` (35 columnas)
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
- **Tipado estricto:** 24 enumerados nativos `ENUM` que encapsulan los estados y categorías del dominio.
