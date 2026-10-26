-- ============================================================================
-- Módulo 23 · Snapshots inmutables de pieza en líneas de orden (Art. III)
-- ----------------------------------------------------------------------------
-- Congela part_code, name y category en van_restock_order_items para que un
-- renombrado o recategorización posterior en spare_parts no altere el histórico
-- del Albarán de Picking A4 ni la trazabilidad de la orden.
-- ============================================================================

ALTER TABLE van_restock_order_items
    ADD COLUMN IF NOT EXISTS part_code_snapshot VARCHAR(64),
    ADD COLUMN IF NOT EXISTS part_name_snapshot VARCHAR(150),
    ADD COLUMN IF NOT EXISTS category_snapshot VARCHAR(30);

-- Poblar filas existentes si las hubiera desde spare_parts
UPDATE van_restock_order_items i
   SET part_code_snapshot = COALESCE(i.part_code_snapshot, sp.part_code),
       part_name_snapshot = COALESCE(i.part_name_snapshot, sp.name),
       category_snapshot = COALESCE(i.category_snapshot, sp.category::text)
  FROM spare_parts sp
 WHERE sp.id = i.spare_part_id
   AND (i.part_code_snapshot IS NULL OR i.part_name_snapshot IS NULL OR i.category_snapshot IS NULL);
