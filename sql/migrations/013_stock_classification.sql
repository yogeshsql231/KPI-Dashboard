-- Migration 013: stock classification for the Warehouse split view (SCRUM-26).
--
-- Adds product_type (Fresh / Frozen / Dry) and category (SAP item group) to
-- the warehouse_stock cache so stock can be split by location, product type
-- and category. Both are populated by etl/pull_inventory.php --what=stock
-- from OITM/OITB; rows loaded before this migration show as "Unassigned".
--
-- Safe to re-run (IF NOT EXISTS + idempotent view).

USE kpi_dashboard;

ALTER TABLE warehouse_stock
    ADD COLUMN IF NOT EXISTS product_type VARCHAR(32)  NULL AFTER pallets,
    ADD COLUMN IF NOT EXISTS category     VARCHAR(100) NULL AFTER product_type;

CREATE INDEX IF NOT EXISTS idx_stock_product_type ON warehouse_stock (product_type);
CREATE INDEX IF NOT EXISTS idx_stock_category     ON warehouse_stock (category);

-- Normalised labels so the split view never groups on NULL/''.
CREATE OR REPLACE VIEW vw_warehouse_stock AS
SELECT
    s.*,
    COALESCE(NULLIF(TRIM(s.product_type), ''), 'Unassigned') AS std_product_type,
    COALESCE(NULLIF(TRIM(s.category), ''), 'Unassigned')     AS std_category
FROM warehouse_stock s;
