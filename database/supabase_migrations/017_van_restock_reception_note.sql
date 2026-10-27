-- ============================================================================
-- Módulo 23 · Nota opcional de recepción del técnico (RF-04.2 / EARS 04.2)
-- ----------------------------------------------------------------------------
-- Añade la columna reception_note a la cabecera van_restock_orders para registrar
-- la observación opcional del técnico en la confirmación de la orden de carga.
-- Cota: NULL o texto entre 1 y 500 caracteres reales tras btrim().
-- ============================================================================

ALTER TABLE van_restock_orders
    ADD COLUMN IF NOT EXISTS reception_note TEXT;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint
        WHERE conname = 'chk_van_restock_orders_reception_note'
    ) THEN
        ALTER TABLE van_restock_orders
            ADD CONSTRAINT chk_van_restock_orders_reception_note
            CHECK (reception_note IS NULL OR char_length(btrim(reception_note)) BETWEEN 1 AND 500);
    END IF;
END $$;
