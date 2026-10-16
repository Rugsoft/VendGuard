# ACTA HISTÓRICA · PLAN DE MIGRACIÓN HÍBRIDA (MÓDULO 12 - CERRADA)

> [!NOTE]
> **Documento Histórico:** Este plan documenta la migración híbrida ejecutada en el Módulo 12. Con la aprobación de la enmienda de motor único (Módulo 13, EARS-13.1 a EARS-13.10), el camino dual ha sido retirado definitivamente y PostgreSQL (Supabase) es el único motor del sistema. El rollback a MariaDB queda derogado y se gestiona mediante PITR y copias lógicas en Supabase (§7).

**Proyecto:** Gestor de Incidencias de Vending (*VendGuard*)  
**Documento:** `docs/migracion_supabase_hibrido.md`  
**Fecha:** Octubre 2026  
**Estrategia:** Enfoque A (Arquitectura Híbrida - Hoy culminada en Motor Único)  
**Normativa Suprema:** [`constitution.md`](../constitution.md) (Artículos I al VII)  
**Directrices Operativas:** [`AGENTS.md`](../AGENTS.md) (Dogma Vanilla, Dualismo Lingüístico)  

---

## 1. Visión Ejecutiva y Objetivos de la Migración

El **Enfoque Híbrido** trasladó la persistencia de datos relacionales desde el motor previo a la infraestructura administrada de **PostgreSQL en Supabase (Plan Gratuito)**, **manteniendo al 100% el backend en PHP 8.2+ Clean Architecture y el frontend Vanilla Vue 3**.

```mermaid
flowchart LR
    subgraph Cliente ["Navegador Cliente"]
        UI["Frontend Vanilla Vue 3 ESM<br>(Assets estáticos / fetch nativo)"]
    end

    subgraph Aplicacion ["Servidor de Aplicación (Coste 0)"]
        PHP["PHP 8.2+ Clean Architecture<br>(AppRouter + Services + Repositories)"]
    end

    subgraph Supabase ["Supabase Free Tier (Coste 0 €/mes)"]
        POOL["Supavisor Pooler (Puerto 6543)<br>Modo: Transaction Pooling"]
        PG[("PostgreSQL 15+ Engine<br>(500 MB Storage / RLS / Triggers)")]
        CRON["pg_cron Scheduler<br>(Barrido Frío Art. II cada 15m)"]
        BUCKET["Supabase Storage (1 GB)<br>Bucket: incident-evidence"]
        
        POOL --> PG
        CRON --> PG
    end

    UI -->|HTTP REST /api/...| PHP
    PHP -->|PDO pgsql: SSL| POOL
    UI -.->|Uploads / Descargas| BUCKET
```

### ¿Por qué esta estrategia es óptima para VendGuard?
1. **Coste Operativo Cero (0 €/mes):** El plan gratuito de Supabase ofrece base de datos PostgreSQL de nivel empresarial sin coste recurrente.
2. **Preservación Total del Dominio:** Las entidades (`Incident`, `Machine`, etc.), DTOs y servicios de negocio (`IncidentPauseService`, `MetricsCalculationService`, `RefundWorkflowService`) permanecen intactos.
3. **Cero Dependencias Externas (Dogma Vanilla Art. IV):** La conexión se realiza mediante la extensión nativa `pdo_pgsql` de PHP, sin requerir SDKs ni clientes npm.
4. **Mayor Solidez Constitucional:** PostgreSQL permite blindar los Artículos II, III y V directamente en el motor mediante índices únicos parciales, restricciones `CHECK` y `pg_cron`.
5. **Riesgo y Tiempo Mínimos:** La migración se completa en días en lugar de semanas, con posibilidad de marcha atrás inmediata hacia MariaDB si fuera necesario.

---

## 2. Gestión de Restricciones del Plan Gratuito de Supabase

| Restricción Free Tier | Límite Oficial | Impacto en VendGuard | Mitigación Arquitectónica |
| :--- | :---: | :--- | :--- |
| **Límite de Conexiones** | Máx. 60 directas | PHP abre una conexión por petición HTTP, lo que agotaría el límite ante picos concurrentes. | **Conexión obligatoria a través de Supavisor (Puerto 6543)** en modo `pool_mode=transaction`. Soporta miles de peticiones simultáneas reutilizando un pool interno de 15 conexiones reales. |
| **Pausa por Inactividad** | Pausa tras 7 días sin peticiones | Si no hay averías en una semana, Supabase suspende la instancia de BD. | **Keep-Alive Cron:** Se programa una tarea desatendida (vía GitHub Actions o cron de servidor) que ejecuta cada 24 horas un `SELECT 1` hacia el endpoint `/rest/v1/locations?limit=1`. |
| **Espacio de Base de Datos** | 500 MB | 10.000 expedientes completos ocupan ~25 MB. | Las fotos de avería e inspección se almacenan en el bucket de **Supabase Storage** (1 GB gratis) o disco local, **nunca en columnas BYTEA**. |
| **Ancho de Banda (Egress)** | 2 GB / mes | Tráfico de consultas JSON: < 200 MB/mes. | Los assets estáticos (JS, CSS, SVGs) se sirven desde el servidor web propio o CDN, sin consumir transferencia saliente de Supabase. |

---

## 3. Script DDL Completo de PostgreSQL (database/supabase_init.sql)

Este script reemplaza a `database/cloud_init.sql` adaptando tipos de datos, identificadores, enums y garantías constitucionales a la sintaxis nativa de PostgreSQL 15+.

```sql
-- =============================================================================
-- VENDGUARD DDL: POSTGRESQL 15+ (SUPABASE FREE TIER)
-- Adaptación arquitectónica para Enfoque Híbrido (PHP 8.2 + Supabase)
-- =============================================================================

-- Extensiones necesarias
CREATE EXTENSION IF NOT EXISTS "uuid-ossp";
CREATE EXTENSION IF NOT EXISTS "pgcrypto";
CREATE EXTENSION IF NOT EXISTS "pg_cron";

-- -----------------------------------------------------------------------------
-- ENUMS DEL DOMINIO
-- -----------------------------------------------------------------------------
CREATE TYPE machine_type_enum AS ENUM (
  'HOT_DRINKS', 'COLD_DRINKS', 'SNACKS', 'PERISHABLE_FOOD', 'COMBO'
);

CREATE TYPE user_role_enum AS ENUM (
  'COORDINATOR', 'TECHNICIAN', 'SITE_MANAGER'
);

CREATE TYPE incident_urgency_enum AS ENUM (
  'LOW', 'MEDIUM', 'HIGH', 'CRITICAL'
);

CREATE TYPE incident_status_enum AS ENUM (
  'REGISTERED', 'ASSIGNED', 'IN_PROGRESS', 'PENDING_PARTS', 
  'PENDING_INFO', 'RESOLVED', 'REOPENED', 'CLOSED', 'CANCELLED'
);

CREATE TYPE sanitary_status_enum AS ENUM (
  'OK', 'WARNING', 'QUARANTINE'
);

CREATE TYPE refund_status_enum AS ENUM (
  'PENDING_CODE_ENTRY', 'PENDING_COORDINATOR', 'PENDING_SITE_VALIDATION', 
  'DELIVERED_AT_SITE', 'LIQUIDATED_CENTRAL', 'REJECTED'
);

-- -----------------------------------------------------------------------------
-- 1. locations
-- -----------------------------------------------------------------------------
CREATE TABLE locations (
  id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  site_code VARCHAR(32) NOT NULL UNIQUE,
  name VARCHAR(150) NOT NULL,
  address VARCHAR(255) NOT NULL,
  contact_name VARCHAR(100),
  contact_phone VARCHAR(30),
  is_active BOOLEAN NOT NULL DEFAULT TRUE,
  created_at TIMESTAMPTZ NOT NULL DEFAULT timezone('utc', now()),
  updated_at TIMESTAMPTZ NOT NULL DEFAULT timezone('utc', now()),
  deleted_at TIMESTAMPTZ
);
CREATE INDEX idx_locations_active ON locations (is_active, deleted_at);

-- -----------------------------------------------------------------------------
-- 2. machines
-- -----------------------------------------------------------------------------
CREATE TABLE machines (
  id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  location_id BIGINT NOT NULL REFERENCES locations(id) ON UPDATE CASCADE,
  code VARCHAR(32) NOT NULL UNIQUE,
  model VARCHAR(100) NOT NULL,
  machine_type machine_type_enum NOT NULL,
  floor_wing VARCHAR(100) NOT NULL,
  notes TEXT,
  is_active BOOLEAN NOT NULL DEFAULT TRUE,
  created_at TIMESTAMPTZ NOT NULL DEFAULT timezone('utc', now()),
  updated_at TIMESTAMPTZ NOT NULL DEFAULT timezone('utc', now()),
  deleted_at TIMESTAMPTZ
);
CREATE INDEX idx_machines_location ON machines (location_id);
CREATE INDEX idx_machines_type ON machines (machine_type);

-- -----------------------------------------------------------------------------
-- 3. users
-- -----------------------------------------------------------------------------
CREATE TABLE users (
  id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  operator_code VARCHAR(32) UNIQUE,
  name VARCHAR(100) NOT NULL,
  email VARCHAR(150) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  role user_role_enum NOT NULL,
  location_id BIGINT REFERENCES locations(id) ON UPDATE CASCADE,
  is_active BOOLEAN NOT NULL DEFAULT TRUE,
  created_at TIMESTAMPTZ NOT NULL DEFAULT timezone('utc', now()),
  updated_at TIMESTAMPTZ NOT NULL DEFAULT timezone('utc', now()),
  deleted_at TIMESTAMPTZ
);
CREATE INDEX idx_users_role ON users (role);
CREATE INDEX idx_users_location ON users (location_id);

-- -----------------------------------------------------------------------------
-- 4. incidents
-- -----------------------------------------------------------------------------
CREATE TABLE incidents (
  id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  ticket_code VARCHAR(32) NOT NULL UNIQUE,
  machine_id BIGINT NOT NULL REFERENCES machines(id) ON UPDATE CASCADE,
  location_id BIGINT NOT NULL REFERENCES locations(id) ON UPDATE CASCADE,
  assigned_technician_id BIGINT REFERENCES users(id) ON UPDATE CASCADE,
  reporter_name VARCHAR(100),
  reporter_phone VARCHAR(30),
  category VARCHAR(64) NOT NULL,
  description TEXT NOT NULL,
  retained_money_amount NUMERIC(10, 2),
  photo_path VARCHAR(255),
  urgency incident_urgency_enum NOT NULL,
  status incident_status_enum NOT NULL DEFAULT 'REGISTERED',
  assigned_at TIMESTAMPTZ,
  started_at TIMESTAMPTZ,
  pending_parts_reason TEXT,
  pending_info_reason_category VARCHAR(64),
  pending_info_reason_text TEXT,
  paused_at TIMESTAMPTZ,
  total_pending_info_seconds INTEGER NOT NULL DEFAULT 0,
  sla_target_at TIMESTAMPTZ,
  resolution_diagnosis TEXT,
  resolution_action TEXT,
  resolved_at TIMESTAMPTZ,
  reopen_reason TEXT,
  reopened_at TIMESTAMPTZ,
  closed_at TIMESTAMPTZ,
  cancellation_reason TEXT,
  cancelled_at TIMESTAMPTZ,
  created_at TIMESTAMPTZ NOT NULL DEFAULT timezone('utc', now()),
  updated_at TIMESTAMPTZ NOT NULL DEFAULT timezone('utc', now()),
  deleted_at TIMESTAMPTZ,

  -- Blindaje Art. V.1: Cierre obligatoriamente justificado con >= 20 caracteres
  CONSTRAINT chk_incidents_resolution_len CHECK (
    resolution_diagnosis IS NULL OR char_length(trim(resolution_diagnosis)) >= 20
  ),
  -- Blindaje de pausa PENDING_INFO con motivo >= 20 caracteres
  CONSTRAINT chk_incidents_pause_reason_len CHECK (
    pending_info_reason_text IS NULL OR char_length(trim(pending_info_reason_text)) >= 20
  )
);

-- BLINDAJE ART. V.2: MÁXIMO 1 TICKET ACTIVO POR MÁQUINA A LA VEZ
-- En PostgreSQL se implementa con un Índice Único Parcial nativo (mucho más limpio que la columna virtual de MariaDB)
CREATE UNIQUE INDEX uq_machine_active_ticket 
ON incidents (machine_id) 
WHERE status NOT IN ('CLOSED', 'CANCELLED');

CREATE INDEX idx_incidents_location ON incidents (location_id);
CREATE INDEX idx_incidents_technician ON incidents (assigned_technician_id);
CREATE INDEX idx_incidents_status_urgency ON incidents (status, urgency);
CREATE INDEX idx_incidents_created_at ON incidents (created_at);

-- -----------------------------------------------------------------------------
-- 5. incident_history (Auditoría Inmutable - Art. III)
-- -----------------------------------------------------------------------------
CREATE TABLE incident_history (
  id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  incident_id BIGINT NOT NULL REFERENCES incidents(id) ON DELETE CASCADE ON UPDATE CASCADE,
  user_id BIGINT REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE,
  from_status VARCHAR(32),
  to_status VARCHAR(32) NOT NULL,
  action_note TEXT,
  created_at TIMESTAMPTZ NOT NULL DEFAULT timezone('utc', now())
);
CREATE INDEX idx_history_incident ON incident_history (incident_id, created_at);

-- BLINDAJE ART. III: REGLA ANTI-DELETE EN EL HISTORIAL
CREATE RULE no_delete_incident_history AS
  ON DELETE TO incident_history DO INSTEAD NOTHING;

-- -----------------------------------------------------------------------------
-- 6. incident_comments (Hilo Bidireccional de Comentarios - Módulo 10)
-- -----------------------------------------------------------------------------
CREATE TABLE incident_comments (
  id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  incident_id BIGINT NOT NULL REFERENCES incidents(id) ON DELETE CASCADE ON UPDATE CASCADE,
  user_id BIGINT REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE,
  author_name VARCHAR(100) NOT NULL,
  author_role VARCHAR(32) NOT NULL,
  message TEXT NOT NULL,
  is_internal BOOLEAN NOT NULL DEFAULT FALSE,
  photo_path VARCHAR(255),
  created_at TIMESTAMPTZ NOT NULL DEFAULT timezone('utc', now())
);
CREATE INDEX idx_comments_incident ON incident_comments (incident_id, is_internal, created_at);

-- -----------------------------------------------------------------------------
-- 7. audit_log (Pista Forense Append-Only - Art. III)
-- -----------------------------------------------------------------------------
CREATE TABLE audit_log (
  id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  entity_type VARCHAR(64) NOT NULL,
  entity_id BIGINT NOT NULL,
  event_type VARCHAR(64) NOT NULL,
  actor_user_id BIGINT REFERENCES users(id) ON DELETE SET NULL,
  actor_role VARCHAR(32) NOT NULL,
  actor_name VARCHAR(100) NOT NULL,
  ip_address VARCHAR(45) NOT NULL,
  user_agent VARCHAR(255) NOT NULL,
  payload_json JSONB,
  created_at TIMESTAMPTZ NOT NULL DEFAULT timezone('utc', now())
);
CREATE INDEX idx_audit_entity ON audit_log (entity_type, entity_id);
CREATE INDEX idx_audit_event ON audit_log (event_type);
CREATE INDEX idx_audit_created ON audit_log (created_at);

CREATE RULE no_delete_audit_log AS
  ON DELETE TO audit_log DO INSTEAD NOTHING;

-- -----------------------------------------------------------------------------
-- 8. route_settings (Configuración de Rutas de Técnicos)
-- -----------------------------------------------------------------------------
CREATE TABLE route_settings (
  technician_id BIGINT PRIMARY KEY REFERENCES users(id) ON UPDATE CASCADE,
  start_address VARCHAR(255) NOT NULL DEFAULT 'Base Central VendGuard',
  start_lat NUMERIC(10, 7) NOT NULL DEFAULT 41.3850639,
  start_lng NUMERIC(10, 7) NOT NULL DEFAULT 2.1734035,
  end_address VARCHAR(255) NOT NULL DEFAULT 'Base Central VendGuard',
  end_lat NUMERIC(10, 7) NOT NULL DEFAULT 41.3850639,
  end_lng NUMERIC(10, 7) NOT NULL DEFAULT 2.1734035,
  optimize_urgency BOOLEAN NOT NULL DEFAULT TRUE,
  optimize_distance BOOLEAN NOT NULL DEFAULT TRUE,
  updated_at TIMESTAMPTZ NOT NULL DEFAULT timezone('utc', now())
);

-- -----------------------------------------------------------------------------
-- 9. preventive_settings & 10. preventive_orders
-- -----------------------------------------------------------------------------
CREATE TABLE preventive_settings (
  machine_id BIGINT PRIMARY KEY REFERENCES machines(id) ON UPDATE CASCADE,
  frequency_months INTEGER NOT NULL DEFAULT 3,
  sanitary_status sanitary_status_enum NOT NULL DEFAULT 'OK',
  last_maintenance_at TIMESTAMPTZ,
  next_maintenance_due TIMESTAMPTZ NOT NULL,
  sanitary_quarantine_reason TEXT,
  quarantine_started_at TIMESTAMPTZ,
  updated_at TIMESTAMPTZ NOT NULL DEFAULT timezone('utc', now())
);

CREATE TABLE preventive_orders (
  id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  machine_id BIGINT NOT NULL REFERENCES machines(id) ON UPDATE CASCADE,
  assigned_technician_id BIGINT REFERENCES users(id) ON UPDATE CASCADE,
  scheduled_date DATE NOT NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'SCHEDULED',
  checklist_completed BOOLEAN NOT NULL DEFAULT FALSE,
  sanitary_status_declared sanitary_status_enum,
  technician_notes TEXT,
  completed_at TIMESTAMPTZ,
  created_at TIMESTAMPTZ NOT NULL DEFAULT timezone('utc', now()),
  updated_at TIMESTAMPTZ NOT NULL DEFAULT timezone('utc', now())
);
CREATE INDEX idx_prev_orders_machine ON preventive_orders (machine_id);
CREATE INDEX idx_prev_orders_technician ON preventive_orders (assigned_technician_id, scheduled_date);

CREATE TABLE preventive_order_items (
  id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  order_id BIGINT NOT NULL REFERENCES preventive_orders(id) ON DELETE CASCADE,
  item_code VARCHAR(64) NOT NULL,
  item_label VARCHAR(150) NOT NULL,
  is_checked BOOLEAN NOT NULL DEFAULT FALSE,
  notes TEXT
);

CREATE TABLE sanitary_certificates (
  id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  machine_id BIGINT NOT NULL REFERENCES machines(id) ON UPDATE CASCADE,
  order_id BIGINT REFERENCES preventive_orders(id) ON UPDATE CASCADE,
  technician_id BIGINT NOT NULL REFERENCES users(id) ON UPDATE CASCADE,
  certificate_number VARCHAR(64) NOT NULL UNIQUE,
  status_result sanitary_status_enum NOT NULL,
  notes TEXT,
  issued_at TIMESTAMPTZ NOT NULL DEFAULT timezone('utc', now())
);

-- -----------------------------------------------------------------------------
-- 11. Repuestos (spare_parts, compatibilities, requests, replaced_parts)
-- -----------------------------------------------------------------------------
CREATE TABLE spare_parts (
  id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  code VARCHAR(64) NOT NULL UNIQUE,
  name VARCHAR(150) NOT NULL,
  category VARCHAR(64) NOT NULL,
  unit_price NUMERIC(10, 2) NOT NULL DEFAULT 0.00,
  stock_central INTEGER NOT NULL DEFAULT 0,
  is_active BOOLEAN NOT NULL DEFAULT TRUE,
  created_at TIMESTAMPTZ NOT NULL DEFAULT timezone('utc', now())
);

CREATE TABLE spare_part_compatibilities (
  id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  spare_part_id BIGINT NOT NULL REFERENCES spare_parts(id) ON DELETE CASCADE,
  machine_model VARCHAR(100) NOT NULL,
  CONSTRAINT uq_part_model UNIQUE (spare_part_id, machine_model)
);

CREATE TABLE spare_part_requests (
  id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  incident_id BIGINT NOT NULL REFERENCES incidents(id) ON UPDATE CASCADE,
  technician_id BIGINT NOT NULL REFERENCES users(id) ON UPDATE CASCADE,
  spare_part_id BIGINT NOT NULL REFERENCES spare_parts(id) ON UPDATE CASCADE,
  quantity INTEGER NOT NULL DEFAULT 1,
  status VARCHAR(32) NOT NULL DEFAULT 'REQUESTED',
  created_at TIMESTAMPTZ NOT NULL DEFAULT timezone('utc', now()),
  updated_at TIMESTAMPTZ NOT NULL DEFAULT timezone('utc', now())
);

CREATE TABLE incident_replaced_parts (
  id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  incident_id BIGINT NOT NULL REFERENCES incidents(id) ON UPDATE CASCADE,
  technician_id BIGINT NOT NULL REFERENCES users(id) ON UPDATE CASCADE,
  spare_part_id BIGINT NOT NULL REFERENCES spare_parts(id) ON UPDATE CASCADE,
  quantity INTEGER NOT NULL DEFAULT 1,
  unit_price_snapshot NUMERIC(10, 2) NOT NULL,
  disposition VARCHAR(32) NOT NULL DEFAULT 'DESGUACE',
  replaced_at TIMESTAMPTZ NOT NULL DEFAULT timezone('utc', now())
);

-- -----------------------------------------------------------------------------
-- 12. Reintegros (refund_requests, unclaimed_cash_findings - Módulo 08)
-- -----------------------------------------------------------------------------
CREATE TABLE refund_requests (
  id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  incident_id BIGINT REFERENCES incidents(id) ON DELETE SET NULL ON UPDATE CASCADE,
  machine_id BIGINT NOT NULL REFERENCES machines(id) ON UPDATE CASCADE,
  location_id BIGINT NOT NULL REFERENCES locations(id) ON UPDATE CASCADE,
  requested_amount NUMERIC(10, 2) NOT NULL,
  customer_name VARCHAR(100) NOT NULL,
  customer_phone VARCHAR(30) NOT NULL,
  customer_iban VARCHAR(34),
  customer_bizum_phone VARCHAR(30),
  pin_hash VARCHAR(255) NOT NULL,
  status refund_status_enum NOT NULL DEFAULT 'PENDING_COORDINATOR',
  resolution_channel VARCHAR(32),
  validated_by_user_id BIGINT REFERENCES users(id) ON DELETE SET NULL,
  created_at TIMESTAMPTZ NOT NULL DEFAULT timezone('utc', now()),
  updated_at TIMESTAMPTZ NOT NULL DEFAULT timezone('utc', now())
);
CREATE INDEX idx_refunds_machine ON refund_requests (machine_id);
CREATE INDEX idx_refunds_location ON refund_requests (location_id);
CREATE INDEX idx_refunds_status ON refund_requests (status);

CREATE TABLE unclaimed_cash_findings (
  id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  incident_id BIGINT REFERENCES incidents(id) ON DELETE SET NULL,
  machine_id BIGINT NOT NULL REFERENCES machines(id),
  technician_id BIGINT NOT NULL REFERENCES users(id),
  found_amount NUMERIC(10, 2) NOT NULL,
  notes TEXT,
  found_at TIMESTAMPTZ NOT NULL DEFAULT timezone('utc', now())
);

-- -----------------------------------------------------------------------------
-- 13. Barrido Sanitario (sanitary_sweep_passes, sanitary_sweep_contentions)
-- -----------------------------------------------------------------------------
CREATE TABLE sanitary_sweep_passes (
  id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  started_at TIMESTAMPTZ NOT NULL,
  completed_at TIMESTAMPTZ NOT NULL,
  candidates_count INTEGER NOT NULL DEFAULT 0,
  quarantined_count INTEGER NOT NULL DEFAULT 0,
  contended_count INTEGER NOT NULL DEFAULT 0,
  failed_count INTEGER NOT NULL DEFAULT 0,
  execution_source VARCHAR(32) NOT NULL DEFAULT 'CRON'
);

CREATE TABLE sanitary_sweep_contentions (
  id BIGINT GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
  pass_id BIGINT NOT NULL REFERENCES sanitary_sweep_passes(id) ON DELETE CASCADE,
  machine_id BIGINT NOT NULL REFERENCES machines(id),
  incident_id BIGINT REFERENCES incidents(id),
  reason VARCHAR(255) NOT NULL,
  recorded_at TIMESTAMPTZ NOT NULL DEFAULT timezone('utc', now())
);
```

---

## 4. Adaptación del Código Backend PHP (Dogma Vanilla)

### 4.1. Refactorización de `ConnectionFactory.php`

En la actualidad, [`src/Infrastructure/Database/ConnectionFactory.php`](file:///C:/Users/Friki/.gemini/antigravity/scratch/gestor-incidencias-vending/src/Infrastructure/Database/ConnectionFactory.php) está acoplado al driver MySQL. Con el Enfoque Híbrido se añade soporte nativo para URLs `postgres://` o `pgsql:` conmutando automáticamente según la variable `DATABASE_URL`.

```php
<?php

declare(strict_types=1);

namespace VendGuard\Infrastructure\Database;

use PDO;
use PDOException;
use RuntimeException;

class ConnectionFactory
{
    private static ?PDO $instance = null;

    public static function getConnection(array $customConfig = [], bool $fresh = false): PDO
    {
        if (self::$instance !== null && !$fresh && empty($customConfig)) {
            return self::$instance;
        }

        $dbUrl = getenv('DATABASE_URL') ?: (getenv('SUPABASE_DB_URL') ?: null);

        if (!empty($dbUrl)) {
            $parsed = parse_url($dbUrl);
            $scheme = $parsed['scheme'] ?? 'mysql';

            if ($scheme === 'postgres' || $scheme === 'pgsql') {
                return self::createPostgresConnection($parsed, $fresh);
            }
        }

        return self::createMysqlConnection($customConfig, $fresh);
    }

    private static function createPostgresConnection(array $urlParts, bool $fresh): PDO
    {
        $host = $urlParts['host'] ?? '127.0.0.1';
        $port = (int)($urlParts['port'] ?? 6543); // 6543 = Supavisor Pooler
        $dbname = ltrim($urlParts['path'] ?? 'postgres', '/');
        $user = $urlParts['user'] ?? 'postgres';
        $pass = $urlParts['pass'] ?? '';

        // DSN optimizado para Supabase PostgreSQL a través de Supavisor
        $dsn = "pgsql:host={$host};port={$port};dbname={$dbname};sslmode=require";

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false, // Sentencias preparadas reales
            PDO::ATTR_TIMEOUT            => 5,     // 5s timeout estricto
        ];

        try {
            $pdo = new PDO($dsn, $user, $pass, $options);
            
            // Garantizar zona horaria UTC homogénea para cálculos de SLA
            $pdo->exec("SET timezone TO 'UTC'");

            if (!$fresh) {
                self::$instance = $pdo;
            }

            return $pdo;
        } catch (PDOException $e) {
            throw new RuntimeException("Error al conectar con Supabase PostgreSQL: " . $e->getMessage(), (int)$e->getCode(), $e);
        }
    }
}
```

### 4.2. Portabilidad de Dialecto SQL en los Repositorios PDO

Para que las sentencias SQL se ejecuten transparentemente en PostgreSQL sin romper la compatibilidad, se aplican 4 reglas de portabilidad:

1. **Eliminar Backticks (\`):**  
   * *MySQL/MariaDB:* `INSERT INTO `incidents` (`ticket_code`) VALUES (...)`  
   * *Estándar ANSI:* `INSERT INTO incidents (ticket_code) VALUES (...)`  
   * *Beneficio:* La sintaxis ANSI sin backticks es válida al 100% tanto en MySQL como en PostgreSQL.
2. **Funciones de Fecha y Tiempo:**  
   * Cambiar `NOW()` por `CURRENT_TIMESTAMP` (estándar SQL).  
   * Cambiar `DATE_SUB(NOW(), INTERVAL 4 HOUR)` por `(CURRENT_TIMESTAMP - INTERVAL '4 hours')`.
3. **Manejo de Booleanos:**  
   * En PostgreSQL, las columnas `is_active` son `BOOLEAN` (`true`/`false`), no enteros `TINYINT(1)` (`1`/`0`). PDO acepta `:is_active => true` perfectamente si se vincula con `PDO::PARAM_BOOL`.
4. **Sentencias de Inserción Concurrente:**  
   * Sustituir `ON DUPLICATE KEY UPDATE` por la cláusula estándar `ON CONFLICT (code) DO UPDATE SET ...`.

---

## 5. Automatización del Barrido Sanitario con `pg_cron` (Artículo II)

En la actualidad, el barrido de frío de 4 horas en alimentos perecederos depende de invocar externamente `bin/sanitary_clock_sweep.php` o el endpoint `/api/cron/sanitary-clock-sweep`.

Con Supabase, este proceso se automatiza **directamente en el motor de base de datos** utilizando la extensión oficial **`pg_cron`**:

```sql
-- 1. Procedimiento almacenado del barrido sanitario (Art. II)
CREATE OR REPLACE PROCEDURE perform_sanitary_clock_sweep()
LANGUAGE plpgsql
AS $$
DECLARE
  v_candidates_count INT := 0;
  v_quarantined_count INT := 0;
  v_pass_id BIGINT;
  r RECORD;
BEGIN
  -- Crear cabecera del pase de barrido
  INSERT INTO sanitary_sweep_passes (started_at, completed_at, execution_source)
  VALUES (now(), now(), 'PG_CRON')
  RETURNING id INTO v_pass_id;

  -- Localizar máquinas de perecederos con incidencias activas > 4 horas (14.400 segundos)
  FOR r IN (
    SELECT DISTINCT m.id AS machine_id, i.id AS incident_id
    FROM machines m
    JOIN incidents i ON i.machine_id = m.id
    JOIN preventive_settings ps ON ps.machine_id = m.id
    WHERE m.machine_type = 'PERISHABLE_FOOD'
      AND i.status NOT IN ('CLOSED', 'CANCELLED', 'RESOLVED')
      AND ps.sanitary_status != 'QUARANTINE'
      AND EXTRACT(EPOCH FROM (now() - i.created_at)) >= 14400
  ) LOOP
    v_candidates_count := v_candidates_count + 1;

    -- Poner máquina en cuarentena sanitaria automática
    UPDATE preventive_settings
    SET sanitary_status = 'QUARANTINE',
        sanitary_quarantine_reason = 'Activación automática de cuarentena por rotura de cadena de frío (> 4 horas sin resolución - Art. II)',
        quarantine_started_at = now()
    WHERE machine_id = r.machine_id;

    -- Registrar evento inmutable en el historial
    INSERT INTO incident_history (incident_id, from_status, to_status, action_note)
    VALUES (r.incident_id, 'IN_PROGRESS', 'IN_PROGRESS', '[ALERTA SANITARIA] Máquina puesta en CUARENTENA automática a las 4h (Art. II)');

    v_quarantined_count := v_quarantined_count + 1;
  END LOOP;

  -- Actualizar estadísticas del pase
  UPDATE sanitary_sweep_passes
  SET completed_at = now(),
      candidates_count = v_candidates_count,
      quarantined_count = v_quarantined_count
  WHERE id = v_pass_id;
END;
$$;

-- 2. Programar el cron cada 15 minutos en Supabase
SELECT cron.schedule(
  'sanitary-biological-clock-sweep',
  '*/15 * * * *',
  'CALL perform_sanitary_clock_sweep();'
);
```

---

## 6. Plan de Ejecución Paso a Paso

```mermaid
gantt
    title Cronograma de Migración Híbrida a Supabase
    dateFormat  YYYY-MM-DD
    section Fase 1: Setup Supabase
    Crear proyecto en Supabase (eu-central-1)      :a1, 2026-10-13, 1d
    Ejecutar database/supabase_init.sql           :a2, after a1, 1d
    Configurar pg_cron y Pooler Supavisor         :a3, after a2, 1d
    section Fase 2: Adaptación Código
    Actualizar ConnectionFactory.php (pgsql)      :b1, after a2, 1d
    Eliminar backticks en queries de repositorios :b2, after b1, 2d
    section Fase 3: Certificación y Datos
    Ejecutar suite de pruebas php tests/run_all.php:c1, after b2, 1d
    Exportar datos actuales e importar en Postgres :c2, after c1, 1d
    Despliegue en producción y monitorización      :c3, after c2, 1d
```

### Paso 1: Creación del Proyecto en Supabase
1. Registrar cuenta gratuita en [supabase.com](https://supabase.com).
2. Crear organización y proyecto:
   * **Nombre:** `vendguard-prod`
   * **Región:** `Central EU (Frankfurt)` o `West EU (London)` (óptimo para España).
   * **Contraseña de BD:** Generar clave segura y anotarla.
3. Copiar la URI de conexión de **Connection Pooling (Transaction Mode, Puerto 6543)**:
   ```text
   postgres://postgres.[PROJECT-REF]:[PASSWORD]@aws-0-[REGION].pooler.supabase.com:6543/postgres
   ```

### Paso 2: Creación del Esquema y Semillas
1. En el panel de Supabase $\rightarrow$ **SQL Editor**, pegar y ejecutar el script completo `database/supabase_init.sql`.
2. Verificar que se han creado las 20 tablas y los enums.
3. Cargar los datos maestros iniciales adaptando `database/seeds.sql` con sintaxis `ON CONFLICT DO UPDATE`.

### Paso 3: Configuración del Entorno PHP
1. En el archivo `.env` del servidor de aplicaciones, actualizar la variable de conexión:
   ```ini
   DATABASE_URL="postgres://postgres.[PROJECT_REF]:[PASSWORD]@aws-0-[REGION].pooler.supabase.com:6543/postgres?sslmode=require"
   DB_DRIVER="pgsql"
   ```
2. Verificar que la extensión `pdo_pgsql` está activa en PHP (`php -m | grep pdo_pgsql`).

### Paso 4: Certificación con la Suite de Pruebas
1. Ejecutar la batería de pruebas completa apuntando a Supabase:
   ```powershell
   php tests/run_all.php
   ```
2. Validar que las 249 suites pasen al 100% en verde. Si alguna consulta falla por uso residual de comillas o funciones MySQL, corregir puntualmente la sentencia en el repositorio correspondiente.

### Paso 5: Script Keep-Alive Anti-Pausa (GitHub Actions)
Crear el flujo `.github/workflows/supabase-keepalive.yml` para evitar la suspensión tras 7 días de inactividad:

```yaml
name: Supabase Free Tier Keep-Alive
on:
  schedule:
    - cron: '0 6 * * *' # Todos los días a las 06:00 UTC
  workflow_dispatch:

jobs:
  ping:
    runs-on: ubuntu-latest
    steps:
      - name: Ping Supabase REST API
        run: |
          curl -s -f -H "apikey: ${{ secrets.SUPABASE_ANON_KEY }}" \
               "${{ secrets.SUPABASE_URL }}/rest/v1/locations?select=id&limit=1" > /dev/null
          echo "Supabase keep-alive ping exitoso."
```

---

## 7. Plan de Rollback y Recuperación (PostgreSQL / Supabase)

Con la unificación en motor único (Módulo 13, EARS-13.9), el camino heredado hacia MariaDB ha sido retirado. La estrategia de rollback y contingencia se gestiona íntegramente sobre PostgreSQL / Supabase:

1. **Point-In-Time Recovery (PITR) de Supabase:** En entornos de producción gestionados, restaurar el estado de la base de datos al instante exacto previo a la incidencia mediante el panel de Supabase o la CLI (`supabase db restore --timestamp ...`).
2. **Restauración de copias lógicas (`pg_dump` / `psql`):**
   * Volcado de contingencia: `pg_dump -Fc --no-owner --no-acl $DATABASE_URL > backup_contingency.dump`
   * Restauración limpia: `pg_restore --clean --if-exists --no-owner --no-acl -d $DATABASE_URL backup_contingency.dump`
3. **Autonomía y portabilidad de motor:** Al basarse exclusivamente en PostgreSQL estándar vanilla con extensiones de catálogo abierto (`uuid-ossp`, `pgcrypto`) y sin librerías privativas, la persistencia puede reubicarse inmediatamente a cualquier proveedor PostgreSQL estándar o contenedor local ante caída de servicio.

---

## 8. Conclusión

El **Enfoque Híbrido** representa la mejor relación entre **esfuerzo, coste y robustez**:
* **Coste mensual:** **0 €**.
* **Código PHP conservado:** **> 98%** (solo ajuste de DSN y limpieza de backticks SQL).
* **Mantenimiento de infraestructura:** Reducido a cero para la capa de base de datos.
* **Seguridad y normativas:** Reforzadas al aprovechar los mecanismos nativos de PostgreSQL (índices parciales, `pg_cron` y reglas anti-borrado).
