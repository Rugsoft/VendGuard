-- =============================================================================
-- VendGuard · DDL canónico del motor nuevo · PARTE 1 de 2
-- PostgreSQL 15+ (certificado contra PostgreSQL 16.15 en deploy/local/postgres/)
-- Fichero: database/supabase_init.sql
-- Módulo: 12-hybrid-supabase-migration · T-HYBRID-01 (Parte 2: T-HYBRID-02)
-- =============================================================================
--
-- QUÉ ES ESTE FICHERO
--   La creación del esquema relacional del motor nuevo: extensiones, los tipos
--   `ENUM` del dominio con su tabla de correspondencia, las funciones canónicas
--   de blindaje y las diez primeras tablas —`locations`, `machines`, `users`,
--   `incidents`, `incident_history`, `incident_comments`, `audit_log`,
--   `route_settings`, `preventive_settings` y `preventive_orders`— con sus
--   claves, restricciones, índices y disparadores.
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
--   · Las diez tablas restantes (`preventive_order_items`, `sanitary_certificates`,
--     `spare_parts`, `spare_part_compatibilities`, `spare_part_requests`,
--     `incident_replaced_parts`, `refund_requests`, `unclaimed_cash_findings`,
--     `sanitary_sweep_passes`, `sanitary_sweep_contentions`) → Parte 2, T-HYBRID-02.
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
-- | # | Tipo nativo en PostgreSQL         | Conjunto local (mismo orden)                                  | Columna(s) local(es)                                                             | Materializado |
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
-- |   |                                   |  'SANITARY_CERTIFICATE','REFUND_REQUEST','UNCLAIMED_CASH_FINDING')|                                                                            |               |
-- | 24| certificate_status                | ('VALID','SUSPENDED','REVOKED')                              | sanitary_certificates.status                                                     | Parte 2       |
--
-- Reparto del trabajo: 12 conjuntos (13 columnas) en esta Parte 1 y 12 conjuntos
-- en la Parte 2 (T-HYBRID-02), que añade sus filas a esta misma tabla.
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
        'SANITARY_CERTIFICATE', 'REFUND_REQUEST', 'UNCLAIMED_CASH_FINDING'
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
    access_code_hash        VARCHAR(255),
    access_code_issued_at   TIMESTAMPTZ,
    login_attempts          SMALLINT NOT NULL DEFAULT 0,
    login_locked_until      TIMESTAMPTZ,
    is_active               BOOLEAN NOT NULL DEFAULT TRUE,
    created_at              TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at              TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    deleted_at              TIMESTAMPTZ,
    CONSTRAINT pk_locations PRIMARY KEY (id),
    CONSTRAINT uq_locations_site_code UNIQUE (site_code),
    CONSTRAINT uq_locations_site_code_key UNIQUE (site_code_key)
);

CREATE INDEX IF NOT EXISTS idx_locations_active ON locations (is_active, deleted_at);
CREATE INDEX IF NOT EXISTS idx_locations_lat_lng ON locations (latitude, longitude, is_active);

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
    is_active          BOOLEAN NOT NULL DEFAULT TRUE,
    login_attempts     SMALLINT NOT NULL DEFAULT 0,
    login_locked_until TIMESTAMPTZ,
    created_at         TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at         TIMESTAMPTZ NOT NULL DEFAULT CURRENT_TIMESTAMP,
    deleted_at         TIMESTAMPTZ,
    CONSTRAINT pk_users PRIMARY KEY (id),
    CONSTRAINT uq_users_email UNIQUE (email),
    CONSTRAINT uq_users_email_key UNIQUE (email_key)
);

CREATE INDEX IF NOT EXISTS idx_users_role ON users (role, is_active);

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
    category                    incident_category NOT NULL,
    description                 TEXT NOT NULL,
    retained_money_amount       NUMERIC(10, 2),
    photo_path                  VARCHAR(255),
    urgency                     urgency_level NOT NULL,
    status                      incident_status NOT NULL DEFAULT 'REGISTERED',
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
        REFERENCES users (id) ON DELETE RESTRICT ON UPDATE CASCADE
);

CREATE UNIQUE INDEX IF NOT EXISTS uq_machine_active_ticket
    ON incidents (machine_id)
    WHERE status NOT IN ('CLOSED', 'CANCELLED');

CREATE INDEX IF NOT EXISTS idx_incidents_created_at ON incidents (created_at);
CREATE INDEX IF NOT EXISTS idx_incidents_location ON incidents (location_id);
CREATE INDEX IF NOT EXISTS idx_incidents_status_urgency ON incidents (status, urgency);
CREATE INDEX IF NOT EXISTS idx_incidents_technician ON incidents (assigned_technician_id);

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
-- RESULTADO DE ESTA PARTE
-- =============================================================================
-- 10 tablas · 12 tipos del dominio (13 columnas `ENUM`) · 2 funciones canónicas
-- 17 disparadores: 10 `BEFORE DELETE` (`trg_no_delete_*`) y 7 `BEFORE UPDATE` (`trg_touch_*`)
-- 18 restricciones nombradas (10 claves primarias y 8 únicas)
-- 4 restricciones `CHECK` de longitud mínima · 11 claves foráneas
-- 21 índices secundarios —uno por índice no único del esquema local y con las
-- mismas columnas (D-9)— más el índice único parcial `uq_machine_active_ticket`
-- 2 columnas canónicas (`site_code_key`, `email_key`) con índice único propio
--
-- La Parte 2 (T-HYBRID-02) completa las 20 tablas y sus disparadores. La
-- verificación de correspondencia entre motores y la convergencia del esquema
-- desplegado son T-HYBRID-23 y T-HYBRID-24.
