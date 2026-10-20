-- =============================================================================
-- VendGuard · DDL canónico del motor nuevo · COMPLETO (25 tablas)
-- PostgreSQL 15+ (certificado contra PostgreSQL 16.15 en deploy/local/postgres/)
-- Fichero: database/supabase_init.sql
-- Módulo: 12-hybrid-supabase-migration · T-HYBRID-01 (tablas 1-10) y T-HYBRID-02 (tablas 11-20)
-- =============================================================================
--
-- QUÉ ES ESTE FICHERO
--   La creación del esquema relacional del motor nuevo: extensiones, los tipos
--   `ENUM` del dominio con su tabla de correspondencia, las funciones canónicas
--   de blindaje y **las 25 tablas** con sus claves, restricciones, índices y
--   disparadores. La Parte 1 (§4) cubre `locations`, `machines`, `users`,
--   `incidents`, `incident_history`, `incident_comments`, `audit_log`,
--   `route_settings`, `preventive_settings` y `preventive_orders`; la Parte 2
--   (§5) cubre `preventive_order_items`, `sanitary_certificates`, `spare_parts`,
--   `spare_part_compatibilities`, `spare_part_requests`, `incident_replaced_parts`,
--   `refund_requests`, `unclaimed_cash_findings`, `sanitary_sweep_passes` y
--   `sanitary_sweep_contentions`. La Parte 3 (§6) declara las tres tablas
--   territoriales del módulo 17: `operational_zones`,
--   `operational_zone_postal_codes` y `technician_zone_assignments`.
--
-- CONTRATO QUE LO RIGE (no al revés)
--   specs/technical/schema_correspondence_contract.md. Este DDL se escribe
--   *conforme* a él: qué debe corresponder uno a uno con el esquema MariaDB
--   (§2), el mapeo de tipos autorizado (§3), las diferencias aceptadas por
--   diseño D-1 a D-10 (§4) y las adiciones declaradas A-1 a A-5 (§5). Ningún
--   elemento de este fichero queda fuera de ese contrato; si apareciera una
--   divergencia inevitable se enmienda primero el contrato, nunca el DDL en
--   silencio (AGENTS.md §5.5, Constitución Art. I).
--
--   La fuente del inventario local NO son los ficheros .sql sino el catálogo
--   vivo (`information_schema` del esquema MariaDB desplegado), que es lo que
--   la suite de correspondencia vuelve a leer para detectar deriva.
--
-- IDEMPOTENCIA Y NO DESTRUCTIVIDAD (deliberadas)
--   Todo se declara con `IF NOT EXISTS`, tipos encapsulados en un bloque que
--   tolera el objeto duplicado, y funciones y disparadores con
--   `CREATE OR REPLACE`. Dos consecuencias buscadas:
--     1. Aplicarlo dos veces no hace nada la segunda vez, así que el arranque
--        del contenedor (bin/init_cloud_db.php) puede invocarlo en cada pasada.
--     2. **No borra nada**: este fichero no contiene un solo `DROP`. La
--        variante MariaDB del arranque (`database/cloud_init.sql`) sí recrea
--        sus tablas, y precisamente por eso el esquema desplegado se quedó
--        congelado en su creación inicial y el incidente del 2026-10-03 pasó
--        inadvertido. La convergencia de un esquema ya existente no se hace
--        recreando: se hace aplicando `database/supabase_migrations/`
--        (T-HYBRID-23) sobre este DDL base.
--   Corolario operativo: `CREATE TABLE IF NOT EXISTS` no añade columnas ni
--   restricciones a una tabla que ya existe. La deriva se corrige con una
--   migración, no editando la línea de la tabla.
--
-- QUÉ NO VA AQUÍ
--   · Las semillas maestras → `database/supabase_seeds.sql` (T-HYBRID-03).
--   · Las migraciones incrementales → `database/supabase_migrations/` (T-HYBRID-23).
--   · `pg_cron` y `pg_net` → SOLO en el DDL de la variante de orquestador interno
--     admitida en T-HYBRID-04, y siempre con la credencial leída de un almacén de
--     secretos del proveedor. El orquestador canónico del barrido sanitario es el
--     reloj del contenedor (`deploy/cron/vendguard-sanitary-sweep`), el motor
--     local de la categoría de certificación no ofrece esas extensiones y el
--     reloj canónico no las necesita: escribirlas aquí de forma incondicional
--     haría fallar el DDL justo en el motor donde se certifica (plan §8).
--   · La señal de purga del arnés. La declara el limpiador compartido de pruebas
--     con un único ayudante consciente del driver —`set_config('vendguard.purge',
--     'on', false)`— y **jamás la aplicación** (T-HYBRID-12 y T-HYBRID-26).
--
-- =============================================================================


-- =============================================================================
-- SECCIÓN 1 · Extensiones
-- =============================================================================
-- `uuid-ossp` (uuid_generate_v4) y `pgcrypto` (digest y cifrado) son las dos que
-- el motor nuevo necesita y ambas son de confianza en el motor local, de modo
-- que el propietario de la base puede instalarlas sin privilegios de
-- superusuario (política S-5: la categoría corre con un rol de aplicación).
-- `IF NOT EXISTS` las hace idempotentes y evita que un reintento del arranque
-- falle por «extension already exists».

CREATE EXTENSION IF NOT EXISTS "uuid-ossp";
CREATE EXTENSION IF NOT EXISTS "pgcrypto";


-- =============================================================================
-- SECCIÓN 2 · Tipos `ENUM` del dominio
-- =============================================================================
-- Regla del contrato (§2.6): cada columna `ENUM` del esquema local tiene en el
-- motor nuevo el **mismo conjunto de miembros**, sin añadir, quitar ni alterar
-- la ortografía. No se usa `VARCHAR` + `CHECK`: el conjunto se conserva en el
-- motor. El orden se declara idéntico al local como buena práctica, aunque la
-- suite certifica el conjunto, no el ordinal.
--
-- Los conjuntos NO se unifican por parecido. `machines.machine_type` y
-- `preventive_settings.machine_type` tienen los mismos cinco miembros en
-- distinto orden y son dos tipos distintos a propósito: unificarlos alteraría
-- el ordinal, que en el motor local sí importa para las comparaciones.
--
-- -----------------------------------------------------------------------------
-- TABLA DE CORRESPONDENCIA DE LOS 24 CONJUNTOS (25 columnas locales)
-- -----------------------------------------------------------------------------
-- | # | Tipo nativo en PostgreSQL         | Conjunto local (mismo orden)                                  | Columna(s) local(es)                                                             | Declarado en  |
-- |--:|:----------------------------------|:--------------------------------------------------------------|:---------------------------------------------------------------------------------|:--------------|
-- |  1| approval_decision                 | ('APPROVED','REJECTED')                                       | refund_requests.coordinator_decision                                            | Parte 2       |
-- |  2| conformity_verdict                | ('CONFORME','CONFORME_CON_OBSERVACIONES')                     | sanitary_certificates.result                                                     | Parte 2       |
-- |  3| inspection_result                 | ('CONFORME','CONFORME_CON_OBSERVACIONES','NO_CONFORME',       | preventive_orders.result                                                         | Parte 1       |
-- |   |                                   |  'NO_EVALUABLE_POR_CAUSA_EXTERNA')                            |                                                                                  |               |
-- |  4| user_role                         | ('COORDINATOR','TECHNICIAN')                                  | users.role                                                                       | Parte 1       |
-- |  5| part_destination                  | ('DESGUACE','TALLER')                                         | incident_replaced_parts.old_part_destination                                     | Parte 2       |
-- |  6| compensation_method               | ('EN_MANO_SEDE','BIZUM','TRANSFERENCIA_BANCARIA')             | refund_requests.compensation_method                                              | Parte 2       |
-- |  7| technician_finding                | ('FOUND_PHYSICAL','CONFIRMED_NO_CASH','UNVERIFIED_NO_CASH')   | refund_requests.technician_finding                                               | Parte 2       |
-- |  8| machine_type                      | ('HOT_DRINKS','COLD_DRINKS','SNACKS','PERISHABLE_FOOD',       | machines.machine_type, incidents.machine_type_snapshot                           | Parte 1       |
-- |   |                                   |  'COMBO')                                                     |                                                                                  |               |
-- |  9| spare_part_category               | ('HYDRAULIC','THERMAL','ELECTRONIC','MECHANICAL',             | spare_parts.category                                                             | Parte 2       |
-- |   |                                   |  'PAYMENT_SYSTEM','CONSUMABLE','OTHER')                       |                                                                                  |               |
-- | 10| intervention_type                 | ('INCIDENT','PREVENTIVE')                                     | incident_replaced_parts.intervention_type                                        | Parte 2       |
-- | 11| cash_custody_action               | ('LEFT_AT_RECEPTION','HELD_FOR_CENTRAL')                      | refund_requests.cash_custody_action                                              | Parte 2       |
-- | 12| urgency_level                     | ('LOW','MEDIUM','HIGH','CRITICAL')                            | incidents.urgency                                                                | Parte 1       |
-- | 13| sanitary_status                   | ('OK','ATTENTION_REQUIRED','EXPIRED','QUARANTINE',            | machines.sanitary_status                                                         | Parte 1       |
-- |   |                                   |  'SEASONAL_PAUSE')                                            |                                                                                  |               |
-- | 14| item_check_status                 | ('PASS','WARN','FAIL','NOT_APPLICABLE')                       | preventive_order_items.status                                                    | Parte 2       |
-- | 15| request_status                    | ('PENDING','ATTENDED','CANCELLED')                            | spare_part_requests.status                                                       | Parte 2       |
-- | 16| preventive_order_status           | ('PENDING_ASSIGNMENT','SCHEDULED','IN_INSPECTION','COMPLETED',| preventive_orders.status                                                         | Parte 1       |
-- |   |                                   |  'EXPIRED','CANCELLED')                                       |                                                                                  |               |
-- | 17| refund_status                     | ('PENDING_INSPECTION','DEPOSITED_AT_RECEPTION',               | refund_requests.status                                                           | Parte 2       |
-- |   |                                   |  'VERIFIED_PENDING_PAYMENT','REQUIRES_COORDINATOR_APPROVAL',   |                                                                                  |               |
-- |   |                                   |  'PENDING_CONTACT','PAID_DIGITAL','REFUNDED_IN_HAND','REJECTED')|                                                                                |               |
-- | 18| preventive_machine_type           | ('PERISHABLE_FOOD','HOT_DRINKS','COLD_DRINKS','SNACKS','COMBO')| preventive_settings.machine_type                                                | Parte 1       |
-- | 19| incident_status                   | ('REGISTERED','ASSIGNED','IN_PROGRESS','PENDING_PARTS',       | incidents.status                                                                 | Parte 1       |
-- |   |                                   |  'PENDING_INFO','RESOLVED','REOPENED','CLOSED','CANCELLED')   |                                                                                  |               |
-- | 20| comment_author_type               | ('REPORTER','TECHNICIAN','COORDINATOR','SYSTEM')              | incident_comments.author_type                                                    | Parte 1       |
-- | 21| preventive_order_type             | ('ROUTINE','REINSPECTION','MANUAL_EXTRA')                     | preventive_orders.order_type                                                     | Parte 1       |
-- | 22| incident_category                 | ('TEMPERATURE_COLD','PAYMENT_SYSTEM','PRODUCT_JAM',           | incidents.category                                                               | Parte 1       |
-- |   |                                   |  'ELECTRICAL_OFF','OTHER')                                    |                                                                                  |               |
-- | 23| audit_entity_type                 | ('TICKET','MACHINE','LOCATION','USER','PREVENTIVE_ORDER',     | audit_log.entity_type                                                            | Parte 1       |
-- |   |                                   |  'SANITARY_CERTIFICATE','REFUND_REQUEST','UNCLAIMED_CASH_FINDING',|                                                                                  |               |
-- |   |                                   |  'NOTIFICATION_OUTBOX','NOTIFICATION_CHANNEL_SETTING',       |                                                                                  |               |
-- |   |                                   |  'OPERATIONAL_ZONE','TECHNICIAN_ZONE')                       |                                                                                  |               |
-- | 24| certificate_status                | ('VALID','SUSPENDED','REVOKED')                              | sanitary_certificates.status                                                     | Parte 2       |
--
-- Reparto del trabajo: 12 conjuntos (13 columnas) se declaran en la Parte 1 y los
-- 12 restantes en la Parte 2, y los 24 están materializados en este mismo fichero.
--
-- -----------------------------------------------------------------------------
-- MOTIVO DEL BLOQUE `DO`: PostgreSQL no tiene `CREATE TYPE IF NOT EXISTS`
-- -----------------------------------------------------------------------------
-- Se encapsula cada creación y se tolera `duplicate_object`, que es la forma de
-- que el fichero sea reejecutable sin `DROP TYPE` (borrar el tipo obligaría a
-- borrar antes las tablas que lo usan). El resto de errores se propaga: un
-- bloque que se lo tragara todo escondería una definición mal escrita.

DO $do$
BEGIN
    CREATE TYPE user_role AS ENUM ('COORDINATOR', 'TECHNICIAN');
EXCEPTION WHEN duplicate_object THEN NULL;
END $do$;

DO $do$
BEGIN
    CREATE TYPE machine_type AS ENUM ('HOT_DRINKS', 'COLD_DRINKS', 'SNACKS', 'PERISHABLE_FOOD', 'COMBO');
EXCEPTION WHEN duplicate_object THEN NULL;
END $do$;

DO $do$
BEGIN
    CREATE TYPE preventive_machine_type AS ENUM ('PERISHABLE_FOOD', 'HOT_DRINKS', 'COLD_DRINKS', 'SNACKS', 'COMBO');
EXCEPTION WHEN duplicate_object THEN NULL;
END $do$;

DO $do$
BEGIN
    CREATE TYPE urgency_level AS ENUM ('LOW', 'MEDIUM', 'HIGH', 'CRITICAL');
EXCEPTION WHEN duplicate_object THEN NULL;
END $do$;

DO $do$
BEGIN
    CREATE TYPE incident_category AS ENUM ('TEMPERATURE_COLD', 'PAYMENT_SYSTEM', 'PRODUCT_JAM', 'ELECTRICAL_OFF', 'OTHER');
EXCEPTION WHEN duplicate_object THEN NULL;
END $do$;

DO $do$
BEGIN
    CREATE TYPE incident_status AS ENUM (
        'REGISTERED', 'ASSIGNED', 'IN_PROGRESS', 'PENDING_PARTS',
        'PENDING_INFO', 'RESOLVED', 'REOPENED', 'CLOSED', 'CANCELLED'
    );
EXCEPTION WHEN duplicate_object THEN NULL;
END $do$;

-- ---- Tipos de la avería oportunista in situ (Módulo 16) ---------------------
-- Una base preexistente los recibe por la migración 005; aquí se declaran para
-- que una base creada en limpio tenga exactamente la misma forma.

DO $do$
BEGIN
    CREATE TYPE opportunistic_mode AS ENUM ('NEW', 'ASSUMED');
EXCEPTION WHEN duplicate_object THEN NULL;
END $do$;

DO $do$
BEGIN
    CREATE TYPE opportunistic_origin_reason AS ENUM (
        'SITE_VERBAL_NOTICE', 'TECHNICIAN_DETECTION',
        'EXISTING_TICKET_CLAIM', 'WARRANTY_REOPEN_IN_SITU'
    );
EXCEPTION WHEN duplicate_object THEN NULL;
END $do$;

DO $do$
BEGIN
    CREATE TYPE sanitary_status AS ENUM ('OK', 'ATTENTION_REQUIRED', 'EXPIRED', 'QUARANTINE', 'SEASONAL_PAUSE');
EXCEPTION WHEN duplicate_object THEN NULL;
END $do$;

DO $do$
BEGIN
    CREATE TYPE comment_author_type AS ENUM ('REPORTER', 'TECHNICIAN', 'COORDINATOR', 'SYSTEM');
EXCEPTION WHEN duplicate_object THEN NULL;
END $do$;

DO $do$
BEGIN
    CREATE TYPE audit_entity_type AS ENUM (
        'TICKET', 'MACHINE', 'LOCATION', 'USER', 'PREVENTIVE_ORDER',
        'SANITARY_CERTIFICATE', 'REFUND_REQUEST', 'UNCLAIMED_CASH_FINDING',
        'NOTIFICATION_OUTBOX', 'NOTIFICATION_CHANNEL_SETTING',
        -- Entidades territoriales del Módulo 17 (Resolución QA 11): la zona
        -- operativa y su cuadrante técnico se auditan con tipo propio para que
        -- el rastro no se confunda con la sede ni con el usuario.
        'OPERATIONAL_ZONE', 'TECHNICIAN_ZONE'
    );
EXCEPTION WHEN duplicate_object THEN NULL;
END $do$;

DO $do$
BEGIN
    CREATE TYPE preventive_order_status AS ENUM (
        'PENDING_ASSIGNMENT', 'SCHEDULED', 'IN_INSPECTION', 'COMPLETED', 'EXPIRED', 'CANCELLED'
    );
EXCEPTION WHEN duplicate_object THEN NULL;
END $do$;

DO $do$
BEGIN
    CREATE TYPE preventive_order_type AS ENUM ('ROUTINE', 'REINSPECTION', 'MANUAL_EXTRA');
EXCEPTION WHEN duplicate_object THEN NULL;
END $do$;

DO $do$
BEGIN
    CREATE TYPE inspection_result AS ENUM (
        'CONFORME', 'CONFORME_CON_OBSERVACIONES', 'NO_CONFORME', 'NO_EVALUABLE_POR_CAUSA_EXTERNA'
    );
EXCEPTION WHEN duplicate_object THEN NULL;
END $do$;

-- ---- Tipos de Notificaciones y Outbox (Módulo 14) ---------------------------

DO $do$
BEGIN
    -- 'TELEGRAM' se incorpora en el Módulo 15 (aviso de asignación al técnico);
    -- una base preexistente lo recibe por la migración 004.
    CREATE TYPE notification_channel AS ENUM ('EMAIL', 'WEBHOOK', 'TELEGRAM');
EXCEPTION WHEN duplicate_object THEN NULL;
END $do$;

DO $do$
BEGIN
    CREATE TYPE notification_priority AS ENUM ('CRITICAL_SANITARY', 'STANDARD');
EXCEPTION WHEN duplicate_object THEN NULL;
END $do$;

DO $do$
BEGIN
    CREATE TYPE notification_status AS ENUM (
        'PENDING',
        'PROCESSING',
        'PENDING_RETRY',
        'SENT',
        'FAILED_PERMANENT',
        'CANCELLED_OBSOLETE'
    );
EXCEPTION WHEN duplicate_object THEN NULL;
END $do$;

DO $do$
BEGIN
    CREATE TYPE notification_template AS ENUM (
        'COLD_CHAIN_IMMEDIATE_ALERT',
        'SANITARY_QUARANTINE_TRIGGERED',
        'SITE_SANITARY_QUARANTINE_NOTICE',
        'SANITARY_SWEEP_ESCALATED',
        'SLA_IMMINENCE_2H_UNASSIGNED',
        'SLA_IMMINENCE_3H_UNSTARTED',
        'INCIDENT_OPENED_SITE',
        'INCIDENT_OPENED_CITIZEN_QR',
        'TECHNICIAN_ASSIGNED',
        'TECHNICIAN_UNASSIGNED',
        'PENDING_INFO_SITE_REQUEST',
        'PENDING_INFO_SITE_REPLY',
        'INCIDENT_RESOLVED_SITE',
        'INCIDENT_RESOLVED_CITIZEN_QR',
        'INCIDENT_REOPENED_COORDINATOR',
        'CHRONIC_BREAKDOWN_ALERT',
        'INCIDENT_CANCELLED_SITE',
        'INCIDENT_CANCELLED_CITIZEN_QR',
        'CITIZEN_QR_OBSERVATION_ACK',
        -- Módulo 16: alerta sanitaria al posponer una parada crítica en otra sede
        -- y aviso a la sede de la reapertura practicada in situ.
        'OPPORTUNISTIC_COLD_ROUTE_POSTPONED_ALERT',
        'INCIDENT_REOPENED_IN_SITU_SITE'
    );
EXCEPTION WHEN duplicate_object THEN NULL;
END $do$;

-- ---- Tipos que declaran las tablas de la Parte 2 (§5) ----------------------

DO $do$
BEGIN
    CREATE TYPE approval_decision AS ENUM ('APPROVED', 'REJECTED');
EXCEPTION WHEN duplicate_object THEN NULL;
END $do$;

DO $do$
BEGIN
    CREATE TYPE conformity_verdict AS ENUM ('CONFORME', 'CONFORME_CON_OBSERVACIONES');
EXCEPTION WHEN duplicate_object THEN NULL;
END $do$;

DO $do$
BEGIN
    CREATE TYPE part_destination AS ENUM ('DESGUACE', 'TALLER');
EXCEPTION WHEN duplicate_object THEN NULL;
END $do$;

DO $do$
BEGIN
    CREATE TYPE compensation_method AS ENUM ('EN_MANO_SEDE', 'BIZUM', 'TRANSFERENCIA_BANCARIA');
EXCEPTION WHEN duplicate_object THEN NULL;
END $do$;

DO $do$
BEGIN
    CREATE TYPE technician_finding AS ENUM ('FOUND_PHYSICAL', 'CONFIRMED_NO_CASH', 'UNVERIFIED_NO_CASH');
EXCEPTION WHEN duplicate_object THEN NULL;
END $do$;

DO $do$
BEGIN
    CREATE TYPE spare_part_category AS ENUM (
        'HYDRAULIC', 'THERMAL', 'ELECTRONIC', 'MECHANICAL', 'PAYMENT_SYSTEM', 'CONSUMABLE', 'OTHER'
    );
EXCEPTION WHEN duplicate_object THEN NULL;
END $do$;

DO $do$
BEGIN
    CREATE TYPE intervention_type AS ENUM ('INCIDENT', 'PREVENTIVE');
EXCEPTION WHEN duplicate_object THEN NULL;
END $do$;

DO $do$
BEGIN
    CREATE TYPE cash_custody_action AS ENUM ('LEFT_AT_RECEPTION', 'HELD_FOR_CENTRAL');
EXCEPTION WHEN duplicate_object THEN NULL;
END $do$;

DO $do$
BEGIN
    CREATE TYPE item_check_status AS ENUM ('PASS', 'WARN', 'FAIL', 'NOT_APPLICABLE');
EXCEPTION WHEN duplicate_object THEN NULL;
END $do$;

DO $do$
BEGIN
    CREATE TYPE request_status AS ENUM ('PENDING', 'ATTENDED', 'CANCELLED');
EXCEPTION WHEN duplicate_object THEN NULL;
END $do$;

DO $do$
BEGIN
    CREATE TYPE refund_status AS ENUM (
        'PENDING_INSPECTION', 'DEPOSITED_AT_RECEPTION', 'VERIFIED_PENDING_PAYMENT',
        'REQUIRES_COORDINATOR_APPROVAL', 'PENDING_CONTACT', 'PAID_DIGITAL',
        'REFUNDED_IN_HAND', 'REJECTED'
    );
EXCEPTION WHEN duplicate_object THEN NULL;
END $do$;

DO $do$
BEGIN
    CREATE TYPE certificate_status AS ENUM ('VALID', 'SUSPENDED', 'REVOKED');
EXCEPTION WHEN duplicate_object THEN NULL;
END $do$;


-- =============================================================================
-- SECCIÓN 3 · Funciones canónicas de blindaje
-- =============================================================================

-- -----------------------------------------------------------------------------
-- 3.1 · `prevent_hard_delete()` — Artículo III.1 de la Constitución
-- -----------------------------------------------------------------------------
-- Prohibición del borrado físico sobre entidades maestras y operativas. La
-- función no borra, no registra y no decide nada de negocio: solo levanta la
-- excepción `SQLSTATE '45000'` —el código que el arnés reconoce como rechazo
-- constitucional— salvo que el **arnés** haya abierto la puerta de purga.
--
-- Puerta de purga, ámbito de sesión: `set_config('vendguard.purge', 'on', false)`
-- deja el parámetro vivo para toda la sesión, no solo para la transacción
-- vigente, porque parte de las suites retiran sus propias filas maestras con
-- sentencias sueltas sin transacción explícita. El segundo argumento de
-- `current_setting` es `true` (missing_ok): sin él, leer un parámetro que nadie
-- ha declarado lanzaría error en lugar de devolver NULL, y cualquier `DELETE`
-- legítimo del arnés fallaría por el motivo equivocado.
--
-- Ninguna ruta de la aplicación activa esta señal: se certifica en T-HYBRID-26.

CREATE OR REPLACE FUNCTION prevent_hard_delete()
RETURNS TRIGGER AS $fn$
BEGIN
    IF current_setting('vendguard.purge', true) = 'on' THEN
        RETURN OLD;
    END IF;

    RAISE EXCEPTION 'ART_III_1_VIOLATION: Hard delete prohibido por la Constitución de VendGuard (Art. III.1).'
        USING ERRCODE = '45000';
END;
$fn$ LANGUAGE plpgsql;

-- -----------------------------------------------------------------------------
-- 3.2 · `touch_updated_at()` — forma PostgreSQL de la diferencia aceptada D-2
-- -----------------------------------------------------------------------------
-- El motor local declara `ON UPDATE CURRENT_TIMESTAMP` en siete columnas
-- `updated_at`; PostgreSQL no tiene esa cláusula en la definición de columna, así
-- que la equivalencia se materializa con un disparador `BEFORE UPDATE`. No es una
-- regla de negocio nueva: es la única forma de que la certificación de D-2 —«en un
-- `UPDATE` real, `updated_at` avanza»— sea verdad en este motor, incluso en las
-- sentencias que no nombran la columna.
--
-- `statement_timestamp()` y no `CURRENT_TIMESTAMP` a propósito: en PostgreSQL
-- `CURRENT_TIMESTAMP` es el instante en que **empezó la transacción** y no cambia
-- entre sentencias, de modo que dos `UPDATE` de la misma transacción dejarían el
-- mismo valor y la marca no avanzaría. El `ON UPDATE CURRENT_TIMESTAMP` del motor
-- local usa el instante de la **sentencia**, y su equivalente exacto aquí es
-- `statement_timestamp()`. El valor por defecto de la columna sigue siendo
-- `CURRENT_TIMESTAMP`, que es lo que declara el contrato (§3 y D-2).
--
-- `NEW` se devuelve siempre y la marca solo se toca si el `UPDATE` no la escribió
-- ya: una sentencia que fija `updated_at` a un instante explícito (por ejemplo la
-- que desplaza el vencimiento del reloj sanitario) conserva su valor.

CREATE OR REPLACE FUNCTION touch_updated_at()
RETURNS TRIGGER AS $fn$
BEGIN
    IF NEW.updated_at IS NOT DISTINCT FROM OLD.updated_at THEN
        NEW.updated_at := statement_timestamp();
    END IF;

    RETURN NEW;
END;
$fn$ LANGUAGE plpgsql;


-- =============================================================================
-- SECCIÓN 4 · Tablas 1 a 10
-- =============================================================================
-- Mapeo de tipos aplicado (contrato §3, único autorizado):
--   `INT UNSIGNED` AUTO_INCREMENT / `BIGINT UNSIGNED` AUTO_INCREMENT → BIGINT GENERATED ALWAYS AS IDENTITY
--   `INT UNSIGNED` (clave foránea o valor) → BIGINT
--   `TINYINT(3) UNSIGNED` (contador)       → SMALLINT          [fila añadida al contrato §3 en este mismo commit]
--   `TINYINT(1)` (booleano)                → BOOLEAN           [D-3]
--   `DATETIME` / `TIMESTAMP`               → TIMESTAMPTZ       [D-2]
--   `DATE` → DATE · `VARCHAR(n)` → VARCHAR(n) · `TEXT` → TEXT · `DECIMAL(p,s)` → NUMERIC(p,s)
--   `LONGTEXT` con `json_valid(...)`       → JSONB             [D-4]
--   `ENUM(...)`                            → tipo nativo de la Sección 2
--   `ENUM(...)` derivado (`incidents.is_active_ticket`) → se omite y su unicidad
--     compuesta se sustituye por el índice único parcial `uq_machine_active_ticket` [D-6]
--
-- Claves canónicas (EARS-08.5, D-5): la unicidad del motor local es insensible a
-- mayúsculas **y acentos**, así que `lower()` no la reproduce. Se materializa la
-- clave normalizada que calcula la aplicación —`site_code_key` y `email_key`— y
-- el índice único va sobre la clave, sin depender de ninguna colación del motor.


-- -----------------------------------------------------------------------------
-- Tabla 1 · locations — sedes de cliente (raíz del parque)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS locations (
    id                      BIGINT GENERATED ALWAYS AS IDENTITY,
    site_code               VARCHAR(32) NOT NULL,
    site_code_key           VARCHAR(32) NOT NULL,
    name                    VARCHAR(150) NOT NULL,
    address                 VARCHAR(255) NOT NULL,
    latitude                NUMERIC(10, 7) NOT NULL DEFAULT 41.3850640,
    longitude               NUMERIC(11, 7) NOT NULL DEFAULT 2.1734035,
    has_physical_reception  BOOLEAN NOT NULL DEFAULT TRUE,
    contact_name            VARCHAR(100),
    contact_phone           VARCHAR(30),
    contact_email           VARCHAR(150) NOT NULL DEFAULT 'recepcion@sede.vendguard.es',
    access_code_hash        VARCHAR(255),
    access_code_issued_at   TIMESTAMPTZ,
    login_attempts          SMALLINT NOT NULL DEFAULT 0,
    login_locked_until      TIMESTAMPTZ,
    -- Vinculación territorial (Módulo 17): la zona que atiende la sede, el CP
    -- que la resuelve por herencia y la marca de excepción manual del coordinador.
    -- Las tres llegan a una base preexistente por la migración `007`.
    zone_id                 BIGINT,
    is_manual_override      BOOLEAN NOT NULL DEFAULT FALSE,
    postal_code             VARCHAR(5),
    is_active               BOOLEAN NOT NULL DEFAULT TRUE,
    created_at              TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at              TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    deleted_at              TIMESTAMPTZ,
    CONSTRAINT pk_locations PRIMARY KEY (id),
    CONSTRAINT uq_locations_site_code UNIQUE (site_code),
    CONSTRAINT uq_locations_site_code_key UNIQUE (site_code_key),
    -- El CP admite NULL para no inventar datos en las sedes históricas; el alta
    -- y la edición lo exigen en la aplicación (Resolución QA 7).
    CONSTRAINT chk_locations_postal_code_format CHECK (
        postal_code IS NULL OR postal_code ~ '^[0-9]{5}$'
    )
);

CREATE INDEX IF NOT EXISTS idx_locations_active ON locations (is_active, deleted_at);
CREATE INDEX IF NOT EXISTS idx_locations_lat_lng ON locations (latitude, longitude, is_active);

-- Los índices territoriales se declaran en un bloque que tolera `undefined_column`:
-- en una base preexistente la columna llega con la migración `007`, que corre
-- después de este DDL base (misma lección que `idx_users_on_call`, EARS-02.5).
DO $do$
BEGIN
    CREATE INDEX IF NOT EXISTS idx_locations_postal_code ON locations (postal_code);
    CREATE INDEX IF NOT EXISTS idx_locations_zone_id ON locations (zone_id);
EXCEPTION WHEN duplicate_object OR undefined_column THEN NULL;
END $do$;

CREATE OR REPLACE TRIGGER trg_no_delete_locations
    BEFORE DELETE ON locations
    FOR EACH ROW EXECUTE FUNCTION prevent_hard_delete();

CREATE OR REPLACE TRIGGER trg_touch_locations
    BEFORE UPDATE ON locations
    FOR EACH ROW EXECUTE FUNCTION touch_updated_at();


-- -----------------------------------------------------------------------------
-- Tabla 2 · machines — parque de máquinas; pertenece a una sede
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS machines (
    id                       BIGINT GENERATED ALWAYS AS IDENTITY,
    location_id              BIGINT NOT NULL,
    code                     VARCHAR(32) NOT NULL,
    model                    VARCHAR(100) NOT NULL,
    machine_type             machine_type NOT NULL,
    floor_wing               VARCHAR(100) NOT NULL,
    notes                    TEXT,
    is_active                BOOLEAN NOT NULL DEFAULT TRUE,
    is_blocked_no_access     BOOLEAN NOT NULL DEFAULT FALSE,
    sanitary_status          sanitary_status NOT NULL DEFAULT 'OK',
    sanitary_frequency_days  BIGINT,
    last_sanitary_inspection_at TIMESTAMPTZ,
    next_sanitary_inspection_due DATE,
    is_seasonal_pause        BOOLEAN NOT NULL DEFAULT FALSE,
    seasonal_pause_reason    TEXT,
    seasonal_pause_until     DATE,
    created_at               TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at               TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    deleted_at               TIMESTAMPTZ,
    CONSTRAINT pk_machines PRIMARY KEY (id),
    CONSTRAINT uq_machines_code UNIQUE (code),
    CONSTRAINT fk_machines_location FOREIGN KEY (location_id)
        REFERENCES locations (id) ON DELETE RESTRICT ON UPDATE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_machines_location ON machines (location_id);
CREATE INDEX IF NOT EXISTS idx_machines_type ON machines (machine_type);

CREATE OR REPLACE TRIGGER trg_no_delete_machines
    BEFORE DELETE ON machines
    FOR EACH ROW EXECUTE FUNCTION prevent_hard_delete();

CREATE OR REPLACE TRIGGER trg_touch_machines
    BEFORE UPDATE ON machines
    FOR EACH ROW EXECUTE FUNCTION touch_updated_at();


-- -----------------------------------------------------------------------------
-- Tabla 3 · users — personal interno (coordinador, técnico)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
    id                 BIGINT GENERATED ALWAYS AS IDENTITY,
    name               VARCHAR(100) NOT NULL,
    email              VARCHAR(150) NOT NULL,
    email_key          VARCHAR(150) NOT NULL,
    password_hash      VARCHAR(255) NOT NULL,
    role               user_role NOT NULL,
    operator_code      VARCHAR(20),
    phone              VARCHAR(30),
    -- Identidad Telegram del técnico (Módulo 15): chat de usuario o de grupo.
    -- Una base preexistente la recibe por la migración 004.
    telegram_chat_id   VARCHAR(32),
    is_active          BOOLEAN NOT NULL DEFAULT TRUE,
    is_on_call         BOOLEAN NOT NULL DEFAULT FALSE,
    login_attempts     SMALLINT NOT NULL DEFAULT 0,
    login_locked_until TIMESTAMPTZ,
    created_at         TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at         TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    deleted_at         TIMESTAMPTZ,
    CONSTRAINT pk_users PRIMARY KEY (id),
    CONSTRAINT uq_users_email UNIQUE (email),
    CONSTRAINT uq_users_email_key UNIQUE (email_key),
    -- Enmienda 2026-10-14 (T-HYBRID-24): el catálogo vivo del motor local declara
    -- `uq_users_operator_code` y el contrato la declara ahora en su §2.3. Es una
    -- regla de negocio —un código de operador, un técnico— y por eso se materializa
    -- aquí en vez de quedar como diferencia: la misma regla en los dos motores.
    CONSTRAINT uq_users_operator_code UNIQUE (operator_code),
    CONSTRAINT chk_users_on_call_active_tech CHECK (
        is_on_call = FALSE OR (role = 'TECHNICIAN' AND is_active = TRUE)
    ),
    CONSTRAINT chk_users_telegram_chat_id CHECK (
        telegram_chat_id IS NULL OR telegram_chat_id ~ '^-?[0-9]{5,20}$'
    )
);

CREATE INDEX IF NOT EXISTS idx_users_role ON users (role, is_active);

-- Un chat pertenece a un solo usuario activo (Módulo 15, regla 1 de la spec).
-- En bases preexistentes, `CREATE TABLE IF NOT EXISTS users` no añade
-- `telegram_chat_id` (eso lo hace la migración `004_telegram_technician_channel.sql`
-- en el paso 4/6 de `bin/init_cloud_db.php`). Se tolera `undefined_column` aquí
-- para no abortar el paso 3/6 antes de llegar al ejecutor de migraciones: sin
-- esta guarda el despliegue moría con SQLSTATE 42703 y reintentaba en vano.
DO $do$
BEGIN
    CREATE UNIQUE INDEX IF NOT EXISTS uq_users_telegram_chat_id
        ON users (telegram_chat_id)
        WHERE telegram_chat_id IS NOT NULL AND deleted_at IS NULL;
EXCEPTION WHEN duplicate_object OR undefined_column THEN NULL;
END $do$;

-- En bases preexistentes anteriores al Módulo 14, `CREATE TABLE IF NOT EXISTS users`
-- no añade `is_on_call` (eso lo hace la migración `002_notification_center_outbox.sql`
-- en el paso 4/6 de `bin/init_cloud_db.php`). Se tolera `undefined_column` aquí para
-- no abortar el paso 3/6 antes de llegar al ejecutor de migraciones.
DO $do$
BEGIN
    CREATE INDEX IF NOT EXISTS idx_users_on_call ON users (role, is_active, is_on_call)
        WHERE is_on_call = TRUE;
EXCEPTION WHEN duplicate_object OR undefined_column THEN NULL;
END $do$;

CREATE OR REPLACE TRIGGER trg_no_delete_users
    BEFORE DELETE ON users
    FOR EACH ROW EXECUTE FUNCTION prevent_hard_delete();

CREATE OR REPLACE TRIGGER trg_touch_users
    BEFORE UPDATE ON users
    FOR EACH ROW EXECUTE FUNCTION touch_updated_at();


-- -----------------------------------------------------------------------------
-- Tabla 4 · incidents — núcleo operativo; concentra el blindaje constitucional
-- -----------------------------------------------------------------------------
-- · `uq_machine_active_ticket` es el índice único **parcial** que sustituye a la
--   columna derivada del motor local (D-6): una sola incidencia activa por
--   máquina. `RESOLVED` sigue bloqueando la ventana de 48 h de reapertura y solo
--   `CLOSED` o `CANCELLED` liberan la máquina (Art. V.2 y V.6).
-- · Las cuatro restricciones `CHECK` de longitud mínima (adición declarada A-3,
--   Art. V.1) reproducen en la base el umbral que el dominio ya exige —20
--   caracteres reales tras recortar, `ResolutionValidator::MIN_LENGTH` e
--   `Incident::MIN_PAUSE_REASON_LENGTH`—, de modo que una justificación por
--   debajo del mínimo sea rechazada aunque llegara por un camino que se saltara
--   el servicio. `IS NULL` se admite porque el campo solo se llena al cerrar,
--   pausar o cancelar.
-- · `preventive_order_id` se queda sin clave foránea igual que en el motor local:
--   el vínculo con la orden preventiva lo gobierna el servicio de coexistencia y
--   la correspondencia declarada (§2.4) no incluye esa relación.

CREATE TABLE IF NOT EXISTS incidents (
    id                          BIGINT GENERATED ALWAYS AS IDENTITY,
    ticket_code                 VARCHAR(32) NOT NULL,
    machine_id                  BIGINT NOT NULL,
    machine_type_snapshot       machine_type,
    preventive_order_id         BIGINT,
    location_id                 BIGINT NOT NULL,
    assigned_technician_id      BIGINT,
    reporter_name               VARCHAR(100),
    reporter_phone              VARCHAR(30),
    reporter_email              VARCHAR(150),
    category                    incident_category NOT NULL,
    description                 TEXT NOT NULL,
    retained_money_amount       NUMERIC(10, 2),
    photo_path                  VARCHAR(255),
    urgency                     urgency_level NOT NULL,
    status                      incident_status NOT NULL DEFAULT 'REGISTERED',
    cycle_started_at            TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    cold_alert_sent_at          TIMESTAMPTZ,
    sla_2h_alert_sent_at        TIMESTAMPTZ,
    sla_3h_alert_sent_at        TIMESTAMPTZ,
    assigned_at                 TIMESTAMPTZ,
    started_at                  TIMESTAMPTZ,
    pending_parts_reason        TEXT,
    resolution_diagnosis        TEXT,
    resolution_action           TEXT,
    resolved_at                 TIMESTAMPTZ,
    reopen_reason               TEXT,
    reopened_at                 TIMESTAMPTZ,
    closed_at                   TIMESTAMPTZ,
    cancellation_reason         TEXT,
    cancelled_at                TIMESTAMPTZ,
    paused_at                   TIMESTAMPTZ,
    pending_info_reason_category VARCHAR(64),
    pending_info_reason_text    TEXT,
    total_pending_info_seconds  BIGINT NOT NULL DEFAULT 0,
    sla_target_at               TIMESTAMPTZ,
    -- Módulo 16 · metadatos de la avería oportunista in situ. Opcionales a
    -- propósito: ningún expediente anterior al módulo inventa una modalidad.
    opportunistic_mode              opportunistic_mode,
    opportunistic_origin_reason     opportunistic_origin_reason,
    opportunistic_informant_name    VARCHAR(100),
    chronic_predecessor_incident_id BIGINT,
    created_at                  TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at                  TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    deleted_at                  TIMESTAMPTZ,
    CONSTRAINT pk_incidents PRIMARY KEY (id),
    CONSTRAINT uq_incidents_ticket_code UNIQUE (ticket_code),
    CONSTRAINT chk_resolution_diagnosis_len CHECK (
        resolution_diagnosis IS NULL OR char_length(trim(resolution_diagnosis)) >= 20
    ),
    CONSTRAINT chk_resolution_action_len CHECK (
        resolution_action IS NULL OR char_length(trim(resolution_action)) >= 20
    ),
    CONSTRAINT chk_pending_info_reason_text_len CHECK (
        pending_info_reason_text IS NULL OR char_length(trim(pending_info_reason_text)) >= 20
    ),
    CONSTRAINT chk_cancellation_reason_len CHECK (
        cancellation_reason IS NULL OR char_length(trim(cancellation_reason)) >= 20
    ),
    CONSTRAINT fk_incidents_location FOREIGN KEY (location_id)
        REFERENCES locations (id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_incidents_machine FOREIGN KEY (machine_id)
        REFERENCES machines (id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_incidents_technician FOREIGN KEY (assigned_technician_id)
        REFERENCES users (id) ON DELETE RESTRICT ON UPDATE CASCADE,
    -- Sin modalidad no hay submotivo, y con modalidad el submotivo es obligatorio: los
    -- dos `IS NOT NULL` convierten en falso el desconocido que produce comparar con NULL
    -- (una base migrada recibe esta misma definición por la reparación 006).
    CONSTRAINT chk_incidents_opportunistic_coherence CHECK (
        (opportunistic_mode IS NULL AND opportunistic_origin_reason IS NULL)
        OR (
            opportunistic_mode IS NOT NULL
            AND opportunistic_origin_reason IS NOT NULL
            AND (
                (
                    opportunistic_mode = 'NEW'
                    AND opportunistic_origin_reason IN ('SITE_VERBAL_NOTICE', 'TECHNICIAN_DETECTION', 'WARRANTY_REOPEN_IN_SITU')
                )
                OR (
                    opportunistic_mode = 'ASSUMED'
                    AND opportunistic_origin_reason IN ('EXISTING_TICKET_CLAIM', 'WARRANTY_REOPEN_IN_SITU')
                )
            )
        )
    ),
    CONSTRAINT fk_incidents_chronic_predecessor FOREIGN KEY (chronic_predecessor_incident_id)
        REFERENCES incidents (id) ON DELETE RESTRICT ON UPDATE CASCADE
);

CREATE UNIQUE INDEX IF NOT EXISTS uq_machine_active_ticket
    ON incidents (machine_id)
    WHERE status NOT IN ('CLOSED', 'CANCELLED');

CREATE INDEX IF NOT EXISTS idx_incidents_created_at ON incidents (created_at);
CREATE INDEX IF NOT EXISTS idx_incidents_location ON incidents (location_id);
CREATE INDEX IF NOT EXISTS idx_incidents_status_urgency ON incidents (status, urgency);
CREATE INDEX IF NOT EXISTS idx_incidents_technician ON incidents (assigned_technician_id);

-- Índice parcial de las intervenciones oportunistas vigentes (RF-06.4): el
-- indicador de desplazamientos evitados sólo cuenta lo no borrado lógicamente.
-- En bases preexistentes anteriores al Módulo 16, `CREATE TABLE IF NOT EXISTS
-- incidents` no añade `opportunistic_mode` (eso lo hace la migración
-- `005_opportunistic_incident.sql` en el paso 4/6 de `bin/init_cloud_db.php`). Se
-- tolera `undefined_column` para no abortar el paso 3/6 antes de llegar al ejecutor:
-- sin esta guarda el arranque moría con SQLSTATE 42703 y reintentaba en vano.
DO $do$
BEGIN
    CREATE INDEX IF NOT EXISTS idx_incidents_opportunistic_mode
        ON incidents (opportunistic_mode, created_at)
        WHERE opportunistic_mode IS NOT NULL AND deleted_at IS NULL;
EXCEPTION WHEN duplicate_object OR undefined_column THEN NULL;
END $do$;

CREATE OR REPLACE TRIGGER trg_no_delete_incidents
    BEFORE DELETE ON incidents
    FOR EACH ROW EXECUTE FUNCTION prevent_hard_delete();

CREATE OR REPLACE TRIGGER trg_touch_incidents
    BEFORE UPDATE ON incidents
    FOR EACH ROW EXECUTE FUNCTION touch_updated_at();


-- -----------------------------------------------------------------------------
-- Tabla 5 · incident_history — trazabilidad inmutable de estados
-- -----------------------------------------------------------------------------
-- Sin `updated_at`: una traza de estados no se corrige, se amplía. `CASCADE` en
-- la incidencia y `SET NULL` en el usuario son las reglas declaradas (§2.4), y
-- el disparador impide que la cascada se lleve por delante la traza histórica.

CREATE TABLE IF NOT EXISTS incident_history (
    id          BIGINT GENERATED ALWAYS AS IDENTITY,
    incident_id BIGINT NOT NULL,
    user_id     BIGINT,
    from_status  VARCHAR(32),
    to_status    VARCHAR(32) NOT NULL,
    action_note TEXT,
    created_at  TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT pk_incident_history PRIMARY KEY (id),
    CONSTRAINT fk_history_incident FOREIGN KEY (incident_id)
        REFERENCES incidents (id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_history_user FOREIGN KEY (user_id)
        REFERENCES users (id) ON DELETE SET NULL ON UPDATE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_history_incident ON incident_history (incident_id, created_at);
CREATE INDEX IF NOT EXISTS idx_history_user ON incident_history (user_id);

CREATE OR REPLACE TRIGGER trg_no_delete_incident_history
    BEFORE DELETE ON incident_history
    FOR EACH ROW EXECUTE FUNCTION prevent_hard_delete();


-- -----------------------------------------------------------------------------
-- Tabla 6 · incident_comments — bitácora de evidencias y mensajes (módulo 10)
-- -----------------------------------------------------------------------------
-- `is_internal` es la marca de la que depende la segregación de datos del
-- Art. V.4: las notas internas de taller no salen nunca hacia el responsable de
-- ubicación.

CREATE TABLE IF NOT EXISTS incident_comments (
    id           BIGINT GENERATED ALWAYS AS IDENTITY,
    incident_id  BIGINT NOT NULL,
    author_type  comment_author_type NOT NULL,
    user_id      BIGINT,
    author_name  VARCHAR(100) NOT NULL,
    comment_text TEXT NOT NULL,
    photo_path   VARCHAR(255),
    is_internal  BOOLEAN NOT NULL DEFAULT FALSE,
    created_at   TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT pk_incident_comments PRIMARY KEY (id),
    CONSTRAINT fk_comments_incident FOREIGN KEY (incident_id)
        REFERENCES incidents (id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_comments_user FOREIGN KEY (user_id)
        REFERENCES users (id) ON DELETE SET NULL ON UPDATE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_comments_incident ON incident_comments (incident_id, created_at);
CREATE INDEX IF NOT EXISTS idx_comments_user ON incident_comments (user_id);

CREATE OR REPLACE TRIGGER trg_no_delete_incident_comments
    BEFORE DELETE ON incident_comments
    FOR EACH ROW EXECUTE FUNCTION prevent_hard_delete();


-- -----------------------------------------------------------------------------
-- Tabla 7 · audit_log — auditoría inmutable de sistema (Art. III.3)
-- -----------------------------------------------------------------------------
-- Los tres documentos JSON pasan de `LONGTEXT` + `CHECK json_valid(...)` a
-- `JSONB` (D-4): la validez deja de ser una restricción comprobable para ser
-- estructural. Por eso esta tabla no lleva ninguna restricción `CHECK`, y las
-- tres que el motor local declara figuran en el contrato como diferencia
-- aceptada, no como ausencia.
-- Sin clave foránea hacia `users`: la auditoría sobrevive al usuario y guarda su
-- identidad textual (`user_role`, `user_name`), igual que en el motor local.

CREATE TABLE IF NOT EXISTS audit_log (
    id             BIGINT GENERATED ALWAYS AS IDENTITY,
    entity_type    audit_entity_type NOT NULL,
    entity_id      BIGINT NOT NULL,
    action         VARCHAR(64) NOT NULL,
    user_id        BIGINT,
    user_role      VARCHAR(32) NOT NULL,
    user_name      VARCHAR(100) NOT NULL,
    previous_state JSONB,
    new_state      JSONB NOT NULL,
    metadata       JSONB,
    created_at     TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT pk_audit_log PRIMARY KEY (id)
);

CREATE INDEX IF NOT EXISTS idx_audit_action ON audit_log (action);
CREATE INDEX IF NOT EXISTS idx_audit_created ON audit_log (created_at);
CREATE INDEX IF NOT EXISTS idx_audit_entity ON audit_log (entity_type, entity_id);
CREATE INDEX IF NOT EXISTS idx_audit_user ON audit_log (user_id);

CREATE OR REPLACE TRIGGER trg_no_delete_audit_log
    BEFORE DELETE ON audit_log
    FOR EACH ROW EXECUTE FUNCTION prevent_hard_delete();


-- -----------------------------------------------------------------------------
-- Tabla 8 · route_settings — configuración de la ruta del técnico
-- -----------------------------------------------------------------------------
-- Fila única de configuración: sin claves foráneas y sin índices secundarios más
-- allá de su clave primaria, igual que en el motor local.

CREATE TABLE IF NOT EXISTS route_settings (
    id                     BIGINT GENERATED ALWAYS AS IDENTITY,
    base_name              VARCHAR(100) NOT NULL DEFAULT 'Base Central VendGuard Barcelona',
    base_address           VARCHAR(255) NOT NULL DEFAULT 'Carrer de la Marina 100, 08018 Barcelona',
    base_latitude          NUMERIC(10, 7) NOT NULL DEFAULT 41.3935000,
    base_longitude         NUMERIC(11, 7) NOT NULL DEFAULT 2.1890000,
    operational_radius_km  BIGINT NOT NULL DEFAULT 100,
    min_latitude           NUMERIC(10, 7) NOT NULL DEFAULT 27.0000000,
    max_latitude           NUMERIC(10, 7) NOT NULL DEFAULT 44.5000000,
    min_longitude          NUMERIC(11, 7) NOT NULL DEFAULT -18.5000000,
    max_longitude          NUMERIC(11, 7) NOT NULL DEFAULT 5.0000000,
    created_at             TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at             TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT pk_route_settings PRIMARY KEY (id)
);

CREATE OR REPLACE TRIGGER trg_no_delete_route_settings
    BEFORE DELETE ON route_settings
    FOR EACH ROW EXECUTE FUNCTION prevent_hard_delete();

CREATE OR REPLACE TRIGGER trg_touch_route_settings
    BEFORE UPDATE ON route_settings
    FOR EACH ROW EXECUTE FUNCTION touch_updated_at();


-- -----------------------------------------------------------------------------
-- Tabla 9 · preventive_settings — frecuencia preventiva por tipo de máquina
-- -----------------------------------------------------------------------------
-- `machine_type` usa el tipo `preventive_machine_type`, cuyo conjunto tiene los
-- mismos miembros que `machine_type` en otro orden: son dos conjuntos distintos
-- del inventario local y se conservan como dos tipos (contrato §2.6).

CREATE TABLE IF NOT EXISTS preventive_settings (
    id                    BIGINT GENERATED ALWAYS AS IDENTITY,
    machine_type          preventive_machine_type NOT NULL,
    default_frequency_days BIGINT NOT NULL,
    max_allowed_days      BIGINT NOT NULL,
    advance_warning_days  BIGINT NOT NULL DEFAULT 5,
    created_at            TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at            TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT pk_preventive_settings PRIMARY KEY (id),
    CONSTRAINT uq_prev_settings_type UNIQUE (machine_type)
);

CREATE OR REPLACE TRIGGER trg_no_delete_preventive_settings
    BEFORE DELETE ON preventive_settings
    FOR EACH ROW EXECUTE FUNCTION prevent_hard_delete();

CREATE OR REPLACE TRIGGER trg_touch_preventive_settings
    BEFORE UPDATE ON preventive_settings
    FOR EACH ROW EXECUTE FUNCTION touch_updated_at();


-- -----------------------------------------------------------------------------
-- Tabla 10 · preventive_orders — órdenes de mantenimiento preventivo
-- -----------------------------------------------------------------------------
-- Las tres claves foráneas usan `ON DELETE RESTRICT` / `ON UPDATE RESTRICT`: una
-- orden preventiva no se lleva por delante su sede, su máquina ni su técnico.
-- `linked_incident_id` queda sin clave foránea igual que `preventive_order_id` en
-- `incidents`: el vínculo existe en el modelo y lo gobierna el servicio.

CREATE TABLE IF NOT EXISTS preventive_orders (
    id                      BIGINT GENERATED ALWAYS AS IDENTITY,
    order_code              VARCHAR(30) NOT NULL,
    machine_id              BIGINT NOT NULL,
    location_id             BIGINT NOT NULL,
    assigned_technician_id  BIGINT,
    status                  preventive_order_status NOT NULL DEFAULT 'PENDING_ASSIGNMENT',
    order_type              preventive_order_type NOT NULL DEFAULT 'ROUTINE',
    scheduled_date          DATE NOT NULL,
    due_date                DATE NOT NULL,
    started_at              TIMESTAMPTZ,
    completed_at            TIMESTAMPTZ,
    temperature_measured    NUMERIC(3, 1),
    result                  inspection_result,
    linked_incident_id      BIGINT,
    is_quarantine_triggered BOOLEAN NOT NULL DEFAULT FALSE,
    notes                   TEXT,
    cancellation_reason     TEXT,
    created_at              TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at              TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    deleted_at              TIMESTAMPTZ,
    CONSTRAINT pk_preventive_orders PRIMARY KEY (id),
    CONSTRAINT uq_prev_order_code UNIQUE (order_code),
    CONSTRAINT fk_prev_orders_location FOREIGN KEY (location_id)
        REFERENCES locations (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_prev_orders_machine FOREIGN KEY (machine_id)
        REFERENCES machines (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_prev_orders_technician FOREIGN KEY (assigned_technician_id)
        REFERENCES users (id) ON DELETE RESTRICT ON UPDATE RESTRICT
);

CREATE INDEX IF NOT EXISTS idx_prev_orders_location ON preventive_orders (location_id);
CREATE INDEX IF NOT EXISTS idx_prev_orders_machine ON preventive_orders (machine_id);
CREATE INDEX IF NOT EXISTS idx_prev_orders_status_due ON preventive_orders (status, due_date);
CREATE INDEX IF NOT EXISTS idx_prev_orders_technician ON preventive_orders (assigned_technician_id);

CREATE OR REPLACE TRIGGER trg_no_delete_preventive_orders
    BEFORE DELETE ON preventive_orders
    FOR EACH ROW EXECUTE FUNCTION prevent_hard_delete();

CREATE OR REPLACE TRIGGER trg_touch_preventive_orders
    BEFORE UPDATE ON preventive_orders
    FOR EACH ROW EXECUTE FUNCTION touch_updated_at();


-- =============================================================================
-- SECCIÓN 5 · Tablas 11 a 20
-- =============================================================================
-- Mismo mapeo de tipos y mismas reglas de blindaje que la Sección 4. Dos puntos
-- propios de esta mitad, ambos declarados en el contrato:
--   · **D-7 · importe derivado.** `incident_replaced_parts.total_cost_snapshot` es
--     en el motor local una columna `STORED GENERATED` (`quantity * unit_cost_snapshot`);
--     aquí se declara con la misma función y la misma sintaxis nativa
--     (`GENERATED ALWAYS AS (...) STORED`). No se escribe a mano en ninguno de los
--     dos motores: el importe lo calcula el motor y el histórico conserva el precio
--     del día de la intervención.
--   · **A-3 · longitud mínima en los campos de justificación.** Tres columnas de
--     esta mitad reciben el mismo umbral de 20 caracteres reales que el dominio ya
--     exige: `refund_requests.coordinator_justification` y
--     `refund_requests.technician_justification` (`JustificationTooShortException::MINIMUM_LENGTH`)
--     y `spare_part_requests.custom_part_description`
--     (`SparePartTraceabilityService`, repuesto fuera de catálogo).
--     `incident_replaced_parts.custom_part_name` **no** lo recibe: su regla de dominio
--     es un mínimo de 3 caracteres y describe un nombre de pieza, no una justificación.


-- -----------------------------------------------------------------------------
-- Tabla 11 · preventive_order_items — checklist de la orden preventiva
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS preventive_order_items (
    id                  BIGINT GENERATED ALWAYS AS IDENTITY,
    preventive_order_id BIGINT NOT NULL,
    item_code           VARCHAR(50) NOT NULL,
    item_description    VARCHAR(255) NOT NULL,
    is_critical         BOOLEAN NOT NULL DEFAULT FALSE,
    status              item_check_status NOT NULL,
    observations        TEXT,
    photo_path          VARCHAR(255),
    created_at          TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT pk_preventive_order_items PRIMARY KEY (id),
    CONSTRAINT fk_order_items_order FOREIGN KEY (preventive_order_id)
        REFERENCES preventive_orders (id) ON DELETE RESTRICT ON UPDATE RESTRICT
);

CREATE INDEX IF NOT EXISTS idx_order_items_order_critical
    ON preventive_order_items (preventive_order_id, is_critical);

CREATE OR REPLACE TRIGGER trg_no_delete_preventive_order_items
    BEFORE DELETE ON preventive_order_items
    FOR EACH ROW EXECUTE FUNCTION prevent_hard_delete();


-- -----------------------------------------------------------------------------
-- Tabla 12 · sanitary_certificates — certificados sanitarios de inspección
-- -----------------------------------------------------------------------------
-- `technician_name` y `technician_operator_code` son copias textuales del
-- profesional que firmó: el certificado es un documento y debe poder leerse
-- aunque el usuario cambie de nombre o cause baja (Art. III.3).

CREATE TABLE IF NOT EXISTS sanitary_certificates (
    id                       BIGINT GENERATED ALWAYS AS IDENTITY,
    certificate_code         VARCHAR(40) NOT NULL,
    preventive_order_id      BIGINT NOT NULL,
    machine_id               BIGINT NOT NULL,
    location_id              BIGINT NOT NULL,
    technician_id            BIGINT NOT NULL,
    technician_name          VARCHAR(150) NOT NULL,
    technician_operator_code VARCHAR(30) NOT NULL,
    inspection_date          TIMESTAMPTZ NOT NULL,
    valid_until              DATE NOT NULL,
    temperature_measured     NUMERIC(3, 1),
    result                   conformity_verdict NOT NULL,
    status                   certificate_status NOT NULL DEFAULT 'VALID',
    suspended_reason         TEXT,
    suspended_at             TIMESTAMPTZ,
    created_at               TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at               TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    deleted_at               TIMESTAMPTZ,
    CONSTRAINT pk_sanitary_certificates PRIMARY KEY (id),
    CONSTRAINT uq_certificates_code UNIQUE (certificate_code),
    CONSTRAINT fk_certificates_location FOREIGN KEY (location_id)
        REFERENCES locations (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_certificates_machine FOREIGN KEY (machine_id)
        REFERENCES machines (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_certificates_prev_order FOREIGN KEY (preventive_order_id)
        REFERENCES preventive_orders (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_certificates_technician FOREIGN KEY (technician_id)
        REFERENCES users (id) ON DELETE RESTRICT ON UPDATE RESTRICT
);

CREATE INDEX IF NOT EXISTS idx_certificates_location
    ON sanitary_certificates (location_id);
CREATE INDEX IF NOT EXISTS idx_certificates_machine_status
    ON sanitary_certificates (machine_id, status);
-- Las dos columnas foráneas restantes necesitan índice explícito: el motor local
-- lo crea al declarar la clave foránea y PostgreSQL no, de modo que sin estas dos
-- líneas la correspondencia de columnas indexadas de D-9 no se cumpliría.
CREATE INDEX IF NOT EXISTS idx_certificates_prev_order
    ON sanitary_certificates (preventive_order_id);
CREATE INDEX IF NOT EXISTS idx_certificates_technician
    ON sanitary_certificates (technician_id);

CREATE OR REPLACE TRIGGER trg_no_delete_sanitary_certificates
    BEFORE DELETE ON sanitary_certificates
    FOR EACH ROW EXECUTE FUNCTION prevent_hard_delete();

CREATE OR REPLACE TRIGGER trg_touch_sanitary_certificates
    BEFORE UPDATE ON sanitary_certificates
    FOR EACH ROW EXECUTE FUNCTION touch_updated_at();


-- -----------------------------------------------------------------------------
-- Tabla 13 · spare_parts — catálogo maestro de repuestos
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS spare_parts (
    id             BIGINT GENERATED ALWAYS AS IDENTITY,
    part_code      VARCHAR(50) NOT NULL,
    name           VARCHAR(150) NOT NULL,
    category       spare_part_category NOT NULL DEFAULT 'OTHER',
    manufacturer   VARCHAR(100) NOT NULL,
    reference_cost NUMERIC(10, 2) NOT NULL DEFAULT 0.00,
    is_active      BOOLEAN NOT NULL DEFAULT TRUE,
    notes          TEXT,
    created_at     TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    deleted_at     TIMESTAMPTZ,
    CONSTRAINT pk_spare_parts PRIMARY KEY (id),
    CONSTRAINT uq_spare_parts_part_code UNIQUE (part_code)
);

CREATE INDEX IF NOT EXISTS idx_spare_parts_active_cat ON spare_parts (is_active, category);
CREATE INDEX IF NOT EXISTS idx_spare_parts_name ON spare_parts (name);

CREATE OR REPLACE TRIGGER trg_no_delete_spare_parts
    BEFORE DELETE ON spare_parts
    FOR EACH ROW EXECUTE FUNCTION prevent_hard_delete();

CREATE OR REPLACE TRIGGER trg_touch_spare_parts
    BEFORE UPDATE ON spare_parts
    FOR EACH ROW EXECUTE FUNCTION touch_updated_at();


-- -----------------------------------------------------------------------------
-- Tabla 14 · spare_part_compatibilities — compatibilidad repuesto ↔ modelo
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS spare_part_compatibilities (
    id            BIGINT GENERATED ALWAYS AS IDENTITY,
    spare_part_id BIGINT NOT NULL,
    machine_model VARCHAR(100) NOT NULL,
    is_active     BOOLEAN NOT NULL DEFAULT TRUE,
    created_at    TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    deleted_at    TIMESTAMPTZ,
    CONSTRAINT pk_spare_part_compatibilities PRIMARY KEY (id),
    CONSTRAINT uq_part_model UNIQUE (spare_part_id, machine_model),
    CONSTRAINT fk_compat_spare_part FOREIGN KEY (spare_part_id)
        REFERENCES spare_parts (id) ON DELETE CASCADE ON UPDATE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_part_compat_active
    ON spare_part_compatibilities (spare_part_id, is_active);
CREATE INDEX IF NOT EXISTS idx_part_compat_model
    ON spare_part_compatibilities (machine_model);

CREATE OR REPLACE TRIGGER trg_no_delete_spare_part_compatibilities
    BEFORE DELETE ON spare_part_compatibilities
    FOR EACH ROW EXECUTE FUNCTION prevent_hard_delete();


-- -----------------------------------------------------------------------------
-- Tabla 15 · spare_part_requests — solicitudes de repuesto de una incidencia
-- -----------------------------------------------------------------------------
-- El `CASCADE` de la clave foránea del repuesto es el único del esquema que borra
-- arrastrando: retirar un repuesto del catálogo elimina sus compatibilidades, no
-- sus solicitudes ni su histórico de sustituciones (esas van con `RESTRICT`).

CREATE TABLE IF NOT EXISTS spare_part_requests (
    id                      BIGINT GENERATED ALWAYS AS IDENTITY,
    incident_id             BIGINT NOT NULL,
    spare_part_id           BIGINT,
    is_out_of_catalog       BOOLEAN NOT NULL DEFAULT FALSE,
    custom_part_description TEXT,
    quantity                BIGINT NOT NULL DEFAULT 1,
    status                  request_status NOT NULL DEFAULT 'PENDING',
    requested_by_user_id    BIGINT NOT NULL,
    created_at              TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at              TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT pk_spare_part_requests PRIMARY KEY (id),
    CONSTRAINT chk_custom_part_description_len CHECK (
        custom_part_description IS NULL OR char_length(trim(custom_part_description)) >= 20
    ),
    CONSTRAINT fk_requests_incident FOREIGN KEY (incident_id)
        REFERENCES incidents (id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_requests_spare_part FOREIGN KEY (spare_part_id)
        REFERENCES spare_parts (id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_requests_user FOREIGN KEY (requested_by_user_id)
        REFERENCES users (id) ON DELETE RESTRICT ON UPDATE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_requests_incident ON spare_part_requests (incident_id);
CREATE INDEX IF NOT EXISTS idx_requests_part ON spare_part_requests (spare_part_id);
CREATE INDEX IF NOT EXISTS idx_requests_status ON spare_part_requests (status);
CREATE INDEX IF NOT EXISTS idx_requests_user ON spare_part_requests (requested_by_user_id);

CREATE OR REPLACE TRIGGER trg_no_delete_spare_part_requests
    BEFORE DELETE ON spare_part_requests
    FOR EACH ROW EXECUTE FUNCTION prevent_hard_delete();

CREATE OR REPLACE TRIGGER trg_touch_spare_part_requests
    BEFORE UPDATE ON spare_part_requests
    FOR EACH ROW EXECUTE FUNCTION touch_updated_at();


-- -----------------------------------------------------------------------------
-- Tabla 16 · incident_replaced_parts — piezas sustituidas y su importe derivado
-- -----------------------------------------------------------------------------
-- El importe total no se escribe: lo calcula el motor (D-7) a partir de la
-- cantidad y del coste congelado en el momento de la intervención. Esa copia del
-- coste es lo que permite que un cambio de tarifa del catálogo no reescriba lo
-- que ya se facturó.

CREATE TABLE IF NOT EXISTS incident_replaced_parts (
    id                  BIGINT GENERATED ALWAYS AS IDENTITY,
    intervention_type   intervention_type NOT NULL,
    incident_id         BIGINT,
    preventive_order_id BIGINT,
    machine_id          BIGINT NOT NULL,
    location_id         BIGINT NOT NULL,
    technician_id       BIGINT NOT NULL,
    spare_part_id       BIGINT,
    is_out_of_catalog   BOOLEAN NOT NULL DEFAULT FALSE,
    custom_part_name    VARCHAR(255),
    quantity            BIGINT NOT NULL DEFAULT 1,
    unit_cost_snapshot  NUMERIC(10, 2) NOT NULL DEFAULT 0.00,
    total_cost_snapshot NUMERIC(10, 2) GENERATED ALWAYS AS (quantity * unit_cost_snapshot) STORED,
    old_part_destination part_destination NOT NULL,
    notes               TEXT,
    installed_at        TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at          TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT pk_incident_replaced_parts PRIMARY KEY (id),
    CONSTRAINT fk_replaced_incident FOREIGN KEY (incident_id)
        REFERENCES incidents (id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_replaced_location FOREIGN KEY (location_id)
        REFERENCES locations (id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_replaced_machine FOREIGN KEY (machine_id)
        REFERENCES machines (id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_replaced_preventive FOREIGN KEY (preventive_order_id)
        REFERENCES preventive_orders (id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_replaced_spare_part FOREIGN KEY (spare_part_id)
        REFERENCES spare_parts (id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_replaced_technician FOREIGN KEY (technician_id)
        REFERENCES users (id) ON DELETE RESTRICT ON UPDATE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_replaced_incident ON incident_replaced_parts (incident_id);
CREATE INDEX IF NOT EXISTS idx_replaced_location_date
    ON incident_replaced_parts (location_id, installed_at);
CREATE INDEX IF NOT EXISTS idx_replaced_machine_part_date
    ON incident_replaced_parts (machine_id, spare_part_id, installed_at);
CREATE INDEX IF NOT EXISTS idx_replaced_preventive
    ON incident_replaced_parts (preventive_order_id);
CREATE INDEX IF NOT EXISTS idx_replaced_technician
    ON incident_replaced_parts (technician_id);
CREATE INDEX IF NOT EXISTS idx_replaced_spare_part
    ON incident_replaced_parts (spare_part_id);

CREATE OR REPLACE TRIGGER trg_no_delete_incident_replaced_parts
    BEFORE DELETE ON incident_replaced_parts
    FOR EACH ROW EXECUTE FUNCTION prevent_hard_delete();


-- -----------------------------------------------------------------------------
-- Tabla 17 · refund_requests — reintegros (módulo 8)
-- -----------------------------------------------------------------------------
-- Es la única tabla que ya tenía un guardián anti-borrado en el motor local
-- (`trg_refund_requests_no_hard_delete`, D-8). Aquí no necesita ninguna forma
-- especial: es la misma función `prevent_hard_delete()` que el resto.
--
-- `idx_refund_tracking_token` es un índice redundante que el esquema local
-- declara **además** del único sobre la misma columna. Se refleja a propósito:
-- el contrato §2.3 declara 56 índices secundarios y la correspondencia se
-- certifica sobre columnas indexadas, así que omitirlo dejaría el recuento
-- dependiendo de una interpretación. Es la única redundancia copiada del motor
-- local, y desaparecería el día que el local la retire.

CREATE TABLE IF NOT EXISTS refund_requests (
    id                      BIGINT GENERATED ALWAYS AS IDENTITY,
    incident_id             BIGINT,
    machine_id              BIGINT NOT NULL,
    location_id             BIGINT NOT NULL,
    claimant_name           VARCHAR(100) NOT NULL,
    claimant_contact        VARCHAR(100) NOT NULL,
    claimed_amount          NUMERIC(10, 2) NOT NULL,
    product_attempted       VARCHAR(100),
    compensation_method     compensation_method NOT NULL,
    bizum_phone             VARCHAR(15),
    iban                    VARCHAR(34),
    pickup_pin              VARCHAR(4),
    pickup_attempts         SMALLINT NOT NULL DEFAULT 0,
    pickup_locked_until     TIMESTAMPTZ,
    tracking_token          VARCHAR(64) NOT NULL,
    status                  refund_status NOT NULL DEFAULT 'PENDING_INSPECTION',
    technician_finding      technician_finding,
    recovered_amount        NUMERIC(10, 2),
    cash_custody_action     cash_custody_action,
    receptionist_name       VARCHAR(100),
    technician_justification TEXT,
    technician_inspected_at TIMESTAMPTZ,
    technician_id           BIGINT,
    coordinator_decision    approval_decision,
    approved_amount         NUMERIC(10, 2),
    coordinator_justification TEXT,
    coordinator_id          BIGINT,
    payment_reference       VARCHAR(100),
    paid_amount             NUMERIC(10, 2),
    paid_at                 TIMESTAMPTZ,
    hand_delivered_at       TIMESTAMPTZ,
    is_active               BOOLEAN NOT NULL DEFAULT TRUE,
    created_at              TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at              TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    deleted_at              TIMESTAMPTZ,
    CONSTRAINT pk_refund_requests PRIMARY KEY (id),
    CONSTRAINT tracking_token UNIQUE (tracking_token),
    CONSTRAINT chk_coordinator_justification_len CHECK (
        coordinator_justification IS NULL OR char_length(trim(coordinator_justification)) >= 20
    ),
    CONSTRAINT chk_technician_justification_len CHECK (
        technician_justification IS NULL OR char_length(trim(technician_justification)) >= 20
    ),
    CONSTRAINT fk_refund_coordinator FOREIGN KEY (coordinator_id)
        REFERENCES users (id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_refund_incident FOREIGN KEY (incident_id)
        REFERENCES incidents (id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_refund_location FOREIGN KEY (location_id)
        REFERENCES locations (id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_refund_machine FOREIGN KEY (machine_id)
        REFERENCES machines (id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_refund_technician FOREIGN KEY (technician_id)
        REFERENCES users (id) ON DELETE SET NULL ON UPDATE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_refund_created_at ON refund_requests (created_at);
CREATE INDEX IF NOT EXISTS idx_refund_incident ON refund_requests (incident_id);
CREATE INDEX IF NOT EXISTS idx_refund_status_location ON refund_requests (status, location_id);
CREATE INDEX IF NOT EXISTS idx_refund_tracking_token ON refund_requests (tracking_token);
CREATE INDEX IF NOT EXISTS idx_refund_coordinator ON refund_requests (coordinator_id);
CREATE INDEX IF NOT EXISTS idx_refund_location ON refund_requests (location_id);
CREATE INDEX IF NOT EXISTS idx_refund_machine ON refund_requests (machine_id);
CREATE INDEX IF NOT EXISTS idx_refund_technician ON refund_requests (technician_id);

CREATE OR REPLACE TRIGGER trg_no_delete_refund_requests
    BEFORE DELETE ON refund_requests
    FOR EACH ROW EXECUTE FUNCTION prevent_hard_delete();

CREATE OR REPLACE TRIGGER trg_touch_refund_requests
    BEFORE UPDATE ON refund_requests
    FOR EACH ROW EXECUTE FUNCTION touch_updated_at();


-- -----------------------------------------------------------------------------
-- Tabla 18 · unclaimed_cash_findings — hallazgos de efectivo no reclamado
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS unclaimed_cash_findings (
    id            BIGINT GENERATED ALWAYS AS IDENTITY,
    incident_id   BIGINT NOT NULL,
    machine_id    BIGINT NOT NULL,
    technician_id BIGINT NOT NULL,
    amount        NUMERIC(10, 2) NOT NULL,
    notes         TEXT,
    created_at    TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT pk_unclaimed_cash_findings PRIMARY KEY (id),
    CONSTRAINT fk_unclaimed_incident FOREIGN KEY (incident_id)
        REFERENCES incidents (id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_unclaimed_machine FOREIGN KEY (machine_id)
        REFERENCES machines (id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_unclaimed_technician FOREIGN KEY (technician_id)
        REFERENCES users (id) ON DELETE RESTRICT ON UPDATE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_unclaimed_created_at ON unclaimed_cash_findings (created_at);
CREATE INDEX IF NOT EXISTS idx_unclaimed_incident ON unclaimed_cash_findings (incident_id);
CREATE INDEX IF NOT EXISTS idx_unclaimed_machine ON unclaimed_cash_findings (machine_id);
CREATE INDEX IF NOT EXISTS idx_unclaimed_technician ON unclaimed_cash_findings (technician_id);

CREATE OR REPLACE TRIGGER trg_no_delete_unclaimed_cash_findings
    BEFORE DELETE ON unclaimed_cash_findings
    FOR EACH ROW EXECUTE FUNCTION prevent_hard_delete();


-- -----------------------------------------------------------------------------
-- Tabla 19 · sanitary_sweep_passes — registro de pasadas del barrido (módulo 11)
-- -----------------------------------------------------------------------------
-- Es la traza de la que el vigilante externo lee `last_pass_at`: una pasada sin
-- fila aquí es una ventana que no se ejecutó, y eso genera alerta (Art. II).
-- Por eso no tiene clave foránea ni actualizaciones: se escribe una vez.

CREATE TABLE IF NOT EXISTS sanitary_sweep_passes (
    id                BIGINT GENERATED ALWAYS AS IDENTITY,
    started_at        TIMESTAMPTZ NOT NULL,
    finished_at       TIMESTAMPTZ,
    candidates        BIGINT NOT NULL DEFAULT 0,
    evaluated         BIGINT NOT NULL DEFAULT 0,
    quarantined_count BIGINT NOT NULL DEFAULT 0,
    contended_count   BIGINT NOT NULL DEFAULT 0,
    failures_count    BIGINT NOT NULL DEFAULT 0,
    duration_ms       NUMERIC(12, 2) NOT NULL DEFAULT 0.00,
    CONSTRAINT pk_sanitary_sweep_passes PRIMARY KEY (id)
);

CREATE INDEX IF NOT EXISTS idx_sanitary_sweep_passes_contended
    ON sanitary_sweep_passes (contended_count, started_at);
CREATE INDEX IF NOT EXISTS idx_sanitary_sweep_passes_started
    ON sanitary_sweep_passes (started_at);

CREATE OR REPLACE TRIGGER trg_no_delete_sanitary_sweep_passes
    BEFORE DELETE ON sanitary_sweep_passes
    FOR EACH ROW EXECUTE FUNCTION prevent_hard_delete();


-- -----------------------------------------------------------------------------
-- Tabla 20 · sanitary_sweep_contentions — contención leída como aplazamiento
-- -----------------------------------------------------------------------------
-- Una fila por incidencia: el contador de pasadas consecutivas es lo que decide
-- cuándo un bloqueo de fila sostenido deja de ser un aplazamiento técnico y pasa
-- a ser una anomalía que se escala.

CREATE TABLE IF NOT EXISTS sanitary_sweep_contentions (
    id                  BIGINT GENERATED ALWAYS AS IDENTITY,
    incident_id         BIGINT NOT NULL,
    machine_id          BIGINT NOT NULL,
    first_detected_at   TIMESTAMPTZ NOT NULL,
    last_detected_at    TIMESTAMPTZ NOT NULL,
    consecutive_passes  BIGINT NOT NULL DEFAULT 1,
    total_passes        BIGINT NOT NULL DEFAULT 1,
    escalated_at        TIMESTAMPTZ,
    resolved_at         TIMESTAMPTZ,
    CONSTRAINT pk_sanitary_sweep_contentions PRIMARY KEY (id),
    CONSTRAINT uq_sanitary_sweep_contention_incident UNIQUE (incident_id),
    CONSTRAINT fk_sanitary_sweep_contention_incident FOREIGN KEY (incident_id)
        REFERENCES incidents (id) ON DELETE RESTRICT ON UPDATE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_sanitary_sweep_contentions_machine
    ON sanitary_sweep_contentions (machine_id);
CREATE INDEX IF NOT EXISTS idx_sanitary_sweep_contentions_open
    ON sanitary_sweep_contentions (resolved_at, escalated_at);

CREATE OR REPLACE TRIGGER trg_no_delete_sanitary_sweep_contentions
    BEFORE DELETE ON sanitary_sweep_contentions
    FOR EACH ROW EXECUTE FUNCTION prevent_hard_delete();


-- -----------------------------------------------------------------------------
-- Tabla 21 · notification_outbox — bandeja de salida transaccional (Módulo 14)
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS notification_outbox (
    id                      BIGINT GENERATED ALWAYS AS IDENTITY,
    incident_id             BIGINT,
    channel                 notification_channel NOT NULL,
    priority                notification_priority NOT NULL DEFAULT 'STANDARD',
    template                notification_template NOT NULL,
    recipient               VARCHAR(255) NOT NULL,
    recipient_role          VARCHAR(32) NOT NULL,
    recipient_user_id       BIGINT,
    recipient_location_id   BIGINT,
    subject                 VARCHAR(255) NOT NULL,
    payload_json            JSONB NOT NULL,
    dedup_key               VARCHAR(160),
    status                  notification_status NOT NULL DEFAULT 'PENDING',
    attempts                SMALLINT NOT NULL DEFAULT 0,
    max_attempts            SMALLINT NOT NULL DEFAULT 5,
    next_attempt_at         TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    processing_started_at   TIMESTAMPTZ,
    processed_at            TIMESTAMPTZ,
    last_error              TEXT,
    obsolete_reason         TEXT,
    created_at              TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at              TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    deleted_at              TIMESTAMPTZ,
    CONSTRAINT pk_notification_outbox PRIMARY KEY (id),
    CONSTRAINT uq_notification_outbox_dedup_key UNIQUE (dedup_key),
    CONSTRAINT fk_outbox_incident FOREIGN KEY (incident_id)
        REFERENCES incidents (id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_outbox_recipient_user FOREIGN KEY (recipient_user_id)
        REFERENCES users (id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_outbox_recipient_location FOREIGN KEY (recipient_location_id)
        REFERENCES locations (id) ON DELETE RESTRICT ON UPDATE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_outbox_dispatch_queue
    ON notification_outbox (status, priority, next_attempt_at, id)
    WHERE status IN ('PENDING', 'PENDING_RETRY', 'PROCESSING');

CREATE INDEX IF NOT EXISTS idx_outbox_incident ON notification_outbox (incident_id, status);
CREATE INDEX IF NOT EXISTS idx_outbox_status_created ON notification_outbox (status, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_outbox_channel_status ON notification_outbox (channel, status);

CREATE OR REPLACE TRIGGER trg_no_delete_notification_outbox
    BEFORE DELETE ON notification_outbox
    FOR EACH ROW EXECUTE FUNCTION prevent_hard_delete();

CREATE OR REPLACE TRIGGER trg_touch_notification_outbox
    BEFORE UPDATE ON notification_outbox
    FOR EACH ROW EXECUTE FUNCTION touch_updated_at();


-- -----------------------------------------------------------------------------
-- Tabla 22 · notification_channel_settings — canal corporativo de emergencias
-- -----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS notification_channel_settings (
    id                  BIGINT GENERATED ALWAYS AS IDENTITY,
    webhook_name        VARCHAR(100) NOT NULL DEFAULT 'Canal Central de Emergencias',
    webhook_url         VARCHAR(500),
    is_webhook_enabled  BOOLEAN NOT NULL DEFAULT FALSE,
    last_tested_at      TIMESTAMPTZ,
    last_test_status    VARCHAR(32),
    last_test_error     TEXT,
    updated_by          BIGINT,
    created_at          TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    deleted_at          TIMESTAMPTZ,
    CONSTRAINT pk_notification_channel_settings PRIMARY KEY (id),
    CONSTRAINT chk_webhook_url_https CHECK (
        webhook_url IS NULL OR webhook_url ~* '^https://[a-z0-9.-]+\.[a-z]{2,}(:[0-9]+)?(/.*)?$'
    ),
    CONSTRAINT fk_notif_settings_updated_by FOREIGN KEY (updated_by)
        REFERENCES users (id) ON DELETE SET NULL ON UPDATE CASCADE
);

CREATE OR REPLACE TRIGGER trg_no_delete_notification_channel_settings
    BEFORE DELETE ON notification_channel_settings
    FOR EACH ROW EXECUTE FUNCTION prevent_hard_delete();

CREATE OR REPLACE TRIGGER trg_touch_notification_channel_settings
    BEFORE UPDATE ON notification_channel_settings
    FOR EACH ROW EXECUTE FUNCTION touch_updated_at();


-- =============================================================================
-- SECCIÓN 6 · Tablas 23 a 25 · Zonificación operativa (Módulo 17)
-- =============================================================================
-- Las tablas territoriales se declaran al final por una razón de dependencia y no
-- de importancia: `locations` (Tabla 1) referencia `operational_zones`, de modo que
-- la clave foránea de la sede se añade aquí, cuando el catálogo ya existe. Las
-- Tablas 21 y 22 son las de notificaciones y viven en la Sección 5.
--
-- La declaración es idéntica a `database/supabase_migrations/007_operational_zones.sql`,
-- que es la vía por la que una base ya creada recibe este mismo esquema.

-- -----------------------------------------------------------------------------
-- Tabla 23 · operational_zones — catálogo maestro de zonas operativas (RF-01.1)
-- -----------------------------------------------------------------------------
-- `color_hex` se valida contra `#RRGGBB`: es el token con el que el mapa
-- territorial pinta la zona, y un color inválido dejaría la demarcación sin
-- representación cartográfica.
CREATE TABLE IF NOT EXISTS operational_zones (
    id          BIGINT GENERATED ALWAYS AS IDENTITY,
    code        VARCHAR(50) NOT NULL,
    name        VARCHAR(100) NOT NULL,
    color_hex   VARCHAR(7) NOT NULL DEFAULT '#2560ff',
    description TEXT,
    is_active   BOOLEAN NOT NULL DEFAULT TRUE,
    created_at  TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT pk_operational_zones PRIMARY KEY (id),
    CONSTRAINT uq_operational_zones_code UNIQUE (code),
    CONSTRAINT uq_operational_zones_name UNIQUE (name),
    CONSTRAINT chk_operational_zones_code_len CHECK (length(btrim(code)) >= 3),
    CONSTRAINT chk_operational_zones_name_len CHECK (length(btrim(name)) >= 3),
    CONSTRAINT chk_operational_zones_color_format CHECK (color_hex ~* '^#[0-9A-Fa-f]{6}$')
);

CREATE OR REPLACE TRIGGER trg_no_delete_operational_zones
    BEFORE DELETE ON operational_zones
    FOR EACH ROW EXECUTE FUNCTION prevent_hard_delete();

CREATE OR REPLACE TRIGGER trg_touch_operational_zones
    BEFORE UPDATE ON operational_zones
    FOR EACH ROW EXECUTE FUNCTION touch_updated_at();


-- -----------------------------------------------------------------------------
-- Tabla 24 · operational_zone_postal_codes — mapeo de CPs (RF-01.2, QA 13)
-- -----------------------------------------------------------------------------
-- La unicidad territorial es global **entre filas vivas**: si el CP fuera único
-- por zona, el mismo barrio podría pertenecer a dos demarcaciones y el
-- recomendador tendría dos titulares para una sola sede. Desactivar una zona
-- libera sus CPs con `released_at` (UPDATE), porque el borrado físico está
-- prohibido por el Art. III.1 y el disparador constitucional lo rechaza; por eso
-- la clave primaria es un identificador sustituto y la unicidad viaja en un
-- índice único **parcial** sobre las filas sin liberar.
CREATE TABLE IF NOT EXISTS operational_zone_postal_codes (
    id          BIGINT GENERATED ALWAYS AS IDENTITY,
    postal_code VARCHAR(5) NOT NULL,
    zone_id     BIGINT NOT NULL,
    released_at TIMESTAMPTZ,
    created_at  TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT pk_operational_zone_postal_codes PRIMARY KEY (id),
    CONSTRAINT chk_postal_code_format CHECK (postal_code ~ '^[0-9]{5}$'),
    CONSTRAINT fk_postal_codes_zone FOREIGN KEY (zone_id)
        REFERENCES operational_zones (id) ON DELETE RESTRICT ON UPDATE CASCADE
);

CREATE UNIQUE INDEX IF NOT EXISTS uq_zone_postal_codes_live
    ON operational_zone_postal_codes (postal_code)
    WHERE released_at IS NULL;

CREATE INDEX IF NOT EXISTS idx_zone_postal_codes_zone_id
    ON operational_zone_postal_codes (zone_id);

CREATE OR REPLACE TRIGGER trg_no_delete_operational_zone_postal_codes
    BEFORE DELETE ON operational_zone_postal_codes
    FOR EACH ROW EXECUTE FUNCTION prevent_hard_delete();


-- -----------------------------------------------------------------------------
-- Herencia territorial de la sede (RF-03.1, RF-03.2, Resolución QA 7)
-- -----------------------------------------------------------------------------
-- Las columnas se declaran en la Tabla 1; la clave foránea se materializa aquí
-- porque exige que `operational_zones` exista. `ON DELETE SET NULL` deja la sede
-- en «Sin Zona Asignada» conservando su historial si la zona desapareciera.
DO $do$
BEGIN
    ALTER TABLE locations
        ADD CONSTRAINT fk_locations_zone FOREIGN KEY (zone_id)
        REFERENCES operational_zones (id) ON DELETE SET NULL ON UPDATE CASCADE;
EXCEPTION WHEN duplicate_object THEN NULL;
END $do$;


-- -----------------------------------------------------------------------------
-- Tabla 25 · technician_zone_assignments — cuadrante técnico (RF-02.1)
-- -----------------------------------------------------------------------------
-- La baja es lógica (`removed_at`): el histórico del cuadrante no se borra
-- (Art. III.1) y el reingreso del técnico estrena una vinculación nueva y
-- auditada (Resolución QA 10).
CREATE TABLE IF NOT EXISTS technician_zone_assignments (
    id            BIGINT GENERATED ALWAYS AS IDENTITY,
    technician_id BIGINT NOT NULL,
    zone_id       BIGINT NOT NULL,
    is_primary    BOOLEAN NOT NULL DEFAULT FALSE,
    assigned_at   TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    removed_at    TIMESTAMPTZ,
    CONSTRAINT pk_technician_zone_assignments PRIMARY KEY (id),
    CONSTRAINT fk_technician_zone_technician FOREIGN KEY (technician_id)
        REFERENCES users (id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_technician_zone_zone FOREIGN KEY (zone_id)
        REFERENCES operational_zones (id) ON DELETE RESTRICT ON UPDATE CASCADE
);

-- Unicidad solo sobre vinculaciones vivas: permite reingresos con histórico.
CREATE UNIQUE INDEX IF NOT EXISTS uq_technician_zone_live
    ON technician_zone_assignments (technician_id, zone_id)
    WHERE removed_at IS NULL;

-- Un técnico solo puede ser Titular Principal de una zona viva a la vez (RF-02.1).
CREATE UNIQUE INDEX IF NOT EXISTS uq_technician_primary_zone
    ON technician_zone_assignments (technician_id)
    WHERE is_primary = TRUE AND removed_at IS NULL;

CREATE INDEX IF NOT EXISTS idx_tech_zone_assignments_zone_id
    ON technician_zone_assignments (zone_id);

CREATE INDEX IF NOT EXISTS idx_tech_zone_assignments_tech_id
    ON technician_zone_assignments (technician_id);

CREATE OR REPLACE TRIGGER trg_no_delete_technician_zone_assignments
    BEFORE DELETE ON technician_zone_assignments
    FOR EACH ROW EXECUTE FUNCTION prevent_hard_delete();


-- =============================================================================
-- RESULTADO DEL FICHERO COMPLETO
-- =============================================================================
-- 25 tablas (las 25 del contrato de correspondencia) · 24 tipos del dominio en sus
-- 25 columnas `ENUM` · 2 funciones canónicas de blindaje
-- 37 disparadores: 25 `BEFORE DELETE` (`trg_no_delete_*`, Art. III.1) y 12
-- `BEFORE UPDATE` (`trg_touch_*`, forma PostgreSQL de D-2)
-- 38 restricciones nombradas: 23 claves primarias (la del mapeo de códigos postales
-- es el propio CP, no un identificador sintético) y 15 únicas
-- 12 restricciones `CHECK`: las 7 de longitud mínima del Art. V.1 (A-3: 4 en
-- `incidents`, 2 en `refund_requests`, 1 en `spare_part_requests`) más las 5 del
-- Módulo 17 (longitud de código y nombre de la zona, formato `#RRGGBB` de su color,
-- formato del CP del catálogo y formato del CP de la sede) · 39 claves foráneas con
-- las reglas declaradas en el contrato §2.4
-- 61 índices secundarios —uno por índice no único del esquema local y con las mismas
-- columnas (D-9), más los cinco del Módulo 17— y tres índices únicos parciales:
-- `uq_machine_active_ticket` (D-6), `uq_technician_zone_live` y
-- `uq_technician_primary_zone` (Resolución QA 10)
-- 2 columnas canónicas (`site_code_key`, `email_key`) con índice único propio (A-1/A-2)
-- 1 columna derivada (`incident_replaced_parts.total_cost_snapshot`, D-7)
-- 3 columnas `JSONB` en `audit_log` (D-4)
--
-- La verificación de correspondencia entre motores y la convergencia del esquema
-- desplegado son T-HYBRID-23 y T-HYBRID-24; las semillas maestras, T-HYBRID-03.
