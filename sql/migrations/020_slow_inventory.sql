-- Migration 020: Slow/Obsolete Inventory % (SCRUM-91 — Procurement).
--
-- Extends the SCRUM-92 inventory_supply cache with the last outbound movement
-- date per item x warehouse and flags "slow" stock in the view.
--
-- PROVISIONAL methodology (needs Raj sign-off, see SCRUM-91):
--   slow = active SKU with on-hand > 0 and NO outbound movement in the
--   trailing 90 days (usage_qty_90d = 0)
--   Slow % = slow item-warehouses / stocked item-warehouses x 100
-- Counts are item x warehouse rows — quantities are never summed across items
-- (mixed UoMs). A value-based ($) version needs a confirmed cost source.
-- Perishables likely need a shorter window than packaging/supplies.
-- Safe to re-run (IF NOT EXISTS + idempotent view).

USE kpi_dashboard;

ALTER TABLE inventory_supply
    ADD COLUMN IF NOT EXISTS last_movement DATE NULL AFTER usage_qty_90d;

CREATE OR REPLACE VIEW vw_inventory_supply AS
SELECT
    s.*,
    COALESCE(NULLIF(TRIM(s.warehouse), ''), 'Unassigned')  AS std_warehouse,
    COALESCE(NULLIF(TRIM(s.category), ''), 'Unassigned')   AS std_category,
    ROUND(COALESCE(s.usage_qty_30d, 0) / 30, 4)            AS avg_daily_usage,
    CASE
        WHEN COALESCE(s.usage_qty_30d, 0) > 0 AND s.on_hand IS NOT NULL
            THEN ROUND(GREATEST(s.on_hand, 0) / (s.usage_qty_30d / 30), 1)
        ELSE NULL
    END AS days_of_supply,
    CASE
        WHEN s.item_created IS NOT NULL
             AND s.item_created > DATE_SUB(CURDATE(), INTERVAL 30 DAY)
            THEN 1
        ELSE 0
    END AS is_new_item,
    CASE WHEN COALESCE(s.on_hand, 0) > 0 THEN 1 ELSE 0 END AS is_stocked,
    CASE
        WHEN COALESCE(s.on_hand, 0) > 0 AND COALESCE(s.usage_qty_90d, 0) = 0
            THEN 1
        ELSE 0
    END AS is_slow
FROM inventory_supply s;
