-- ============================================================================
-- Módulo 23 · Índice parcial para solicitudes pendientes de repuesto (RNF-01)
-- ----------------------------------------------------------------------------
-- Optimiza la lectura por lote de solicitudes de repuesto con estado 'PENDING'
-- para el cálculo de van_stock_readiness en bandejas de Coordinación y Técnico.
-- ============================================================================

CREATE INDEX IF NOT EXISTS idx_requests_pending_incident
    ON spare_part_requests (incident_id)
    WHERE status = 'PENDING';
