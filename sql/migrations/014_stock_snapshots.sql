-- Migration 014: daily on-hand inventory snapshots for Stockout Frequency
-- (SCRUM-93 — Procurement / Inventory).
--
-- The existing warehouse_stock cache (migration 010) holds only the CURRENT
-- on-hand per item x warehouse — each ETL run overwrites it, so it cannot tell
-- us whether a SKU ever hit zero on-hand during a period. Detecting stockouts
-- (zero-crossings) needs a periodic snapshot history, which this table
-- captures. It is append-only: one row per (snapshot_date, item, warehouse),
-- written by etl/pull_stock_snapshot.php on a daily schedule on the XAMPP box.
--
-- Unlike primsbm_stock.sql (which filters OnHand <> 0), the snapshot source
-- query intentionally KEEPS zero-on-hand rows for active SKUs so a zero can be
-- observed. is_active carries SAP's item validity (OITM validFor / frozenFor)
-- so discontinued/inactive SKUs can be excluded from the denominator.
--
-- Source: SAP B1 OITW (on-hand per item/warehouse) + OITM (name/validity),
-- same origin as warehouse_stock. No source data is modified.
-- Safe to re-run (IF NOT EXISTS + idempotent view).

USE kpi_dashboard;

CREATE TABLE IF NOT EXISTS inventory_stock_snapshots (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    source_system    VARCHAR(32)   NOT NULL DEFAULT 'PRIMSBM',
    snapshot_date    DATE          NOT NULL,           -- the day this on-hand was observed
    item_code        VARCHAR(64)   NOT NULL,
    item_description VARCHAR(255)  NULL,
    warehouse        VARCHAR(100)  NOT NULL,           -- matches warehouse_stock.warehouse
    on_hand          DECIMAL(18,4) NULL,               -- on-hand qty on snapshot_date (0 = stockout)
    is_active        TINYINT(1)    NOT NULL DEFAULT 1, -- 0 = discontinued/inactive SKU (excluded from denominator)
    product_type     VARCHAR(32)   NULL,               -- Fresh / Frozen / Dry (migration 013 mapping)
    category         VARCHAR(100)  NULL,               -- SAP item group
    refreshed_at     TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP
                                   ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_snap (source_system, snapshot_date, item_code, warehouse),
    KEY idx_snap_date (snapshot_date),
    KEY idx_snap_item (item_code),
    KEY idx_snap_warehouse (warehouse),
    KEY idx_snap_category (category)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- Normalised snapshot view: never group on NULL/'' warehouse or category, and
-- expose a per-row stockout flag (on-hand at or below zero).
CREATE OR REPLACE VIEW vw_stock_snapshots AS
SELECT
    s.*,
    COALESCE(NULLIF(TRIM(s.warehouse), ''), 'Unassigned')      AS std_warehouse,
    COALESCE(NULLIF(TRIM(s.category), ''), 'Unassigned')       AS std_category,
    COALESCE(NULLIF(TRIM(s.product_type), ''), 'Unassigned')   AS std_product_type,
    CASE WHEN s.on_hand IS NOT NULL AND s.on_hand <= 0 THEN 1 ELSE 0 END AS is_stockout
FROM inventory_stock_snapshots s;
