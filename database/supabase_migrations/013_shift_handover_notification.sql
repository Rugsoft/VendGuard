-- ============================================================================
-- Módulo 21 · Aviso propio y prioritario de relevo de fin de turno
-- ----------------------------------------------------------------------------
-- POR QUÉ EXISTE ESTE FICHERO
-- El cierre de jornada reutilizaba `TECHNICIAN_ASSIGNED`, una plantilla pensada
-- para la asignación ordinaria de una avería. Eso hacía que el receptor de un
-- relevo recibiera el aviso con la prioridad estándar y sin una identidad propia
-- en la bandeja de salida. El contrato técnico del Módulo 21 exige la plantilla
-- `TELEGRAM_INCIDENT_ASSIGNMENT` dirigida al receptor.
--
-- QUÉ DECLARA
--   1. `TELEGRAM_INCIDENT_ASSIGNMENT` en el catálogo `notification_template`.
--   2. `PRIORITY` en `notification_priority`: avisos operativos que deben ir por
--      delante del lote ordinario, sin confundirlos con la emergencia sanitaria
--      del Art. II. El despachador conserva `CRITICAL_SANITARY` en primer lugar,
--      después `PRIORITY` y finalmente `STANDARD`.
--
-- NOTA SOBRE LA IDEMPOTENCIA:
-- Ambas declaraciones usan `IF NOT EXISTS`, de modo que el ejecutor puede
-- reaplicar la migración en un motor ya convergido sin duplicar valores.
-- ============================================================================

ALTER TYPE notification_template
    ADD VALUE IF NOT EXISTS 'TELEGRAM_INCIDENT_ASSIGNMENT';

ALTER TYPE notification_priority
    ADD VALUE IF NOT EXISTS 'PRIORITY' BEFORE 'STANDARD';
