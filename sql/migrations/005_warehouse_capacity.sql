-- Migration 005: warehouse capacity master + case-to-pallet conversion config.
--
-- Adds a small, hand-maintained reference table so the dashboard can show
-- pallet capacity per warehouse and a case-to-pallet conversion fallback.
--   * pallet_capacity  = max pallet positions for the warehouse. FILL IN with
--                        real numbers from Facilities / WMS. Left NULL on seed
--                        so we never display an invented capacity.
--   * cases_per_pallet = default cases->pallet factor, used ONLY when a line
--                        has no SAP pallet count (qty_pallet) and no per-line
--                        units/pallet (qty_per_pallet). Also optional.
--
-- No delivery data is modified. Safe to re-run (IF NOT EXISTS + idempotent seed).

USE kpi_dashboard;

CREATE TABLE IF NOT EXISTS warehouse_capacity (
    warehouse        VARCHAR(100)  NOT NULL,          -- matches delivery_lines.warehouse
    pallet_capacity  DECIMAL(18,2) NULL,              -- max pallet positions (fill in)
    cases_per_pallet DECIMAL(18,4) NULL,              -- fallback conversion factor (optional)
    notes            VARCHAR(255)  NULL,
    active           TINYINT(1)    NOT NULL DEFAULT 1,
    updated_at       TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP
                                   ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (warehouse)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- Auto-seed a row for every warehouse currently in the cache, with capacity
-- left NULL (to be filled in). Idempotent: existing rows keep their values.
INSERT INTO warehouse_capacity (warehouse, pallet_capacity, cases_per_pallet, notes)
SELECT DISTINCT warehouse, NULL, NULL, 'auto-seeded; set real pallet_capacity'
FROM delivery_lines
WHERE warehouse IS NOT NULL AND warehouse <> ''
ON DUPLICATE KEY UPDATE active = active;

-- To populate real capacities, e.g.:
--   UPDATE warehouse_capacity SET pallet_capacity = 4200 WHERE warehouse = 'FG-DALLAS';
--   UPDATE warehouse_capacity SET cases_per_pallet = 90 WHERE warehouse = 'DRY-02';
