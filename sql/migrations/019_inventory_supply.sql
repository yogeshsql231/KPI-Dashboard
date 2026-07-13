-- Migration 019: Inventory Days of Supply cache (SCRUM-92 — Procurement).
--
-- One row per item x warehouse: current on-hand plus trailing 30/90-day
-- outbound usage from the SAP B1 inventory journal (OINM OutQty). Refreshed on
-- the XAMPP box by etl/pull_inventory_supply.php via the PRODHANA bridge.
-- READ cache only — no source data is modified.
--
-- PROVISIONAL methodology (needs Raj sign-off, see SCRUM-92):
--   avg daily usage = trailing 30-day total outbound qty / 30
--   days of supply  = on-hand / avg daily usage (NULL when no usage)
-- New SKUs (< 30 days old) are flagged so their days-of-supply is not
-- presented as reliable. Safe to re-run (IF NOT EXISTS + idempotent view).

USE kpi_dashboard;

CREATE TABLE IF NOT EXISTS inventory_supply (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    source_system    VARCHAR(32)   NOT NULL DEFAULT 'PRODHANA',
    source_key       VARCHAR(160)  NOT NULL,           -- item_code|warehouse
    item_code        VARCHAR(64)   NOT NULL,
    item_description VARCHAR(255)  NULL,
    category         VARCHAR(100)  NULL,               -- SAP item group
    warehouse        VARCHAR(100)  NULL,
    on_hand          DECIMAL(18,4) NULL,
    usage_qty_30d    DECIMAL(18,4) NULL,               -- outbound qty, trailing 30 days
    usage_qty_90d    DECIMAL(18,4) NULL,               -- outbound qty, trailing 90 days
    is_active        TINYINT(1)    NOT NULL DEFAULT 1, -- 0 = discontinued/inactive SKU
    item_created     DATE          NULL,               -- OITM.CreateDate (new-SKU detection)
    unit_of_measure  VARCHAR(32)   NULL,
    refreshed_at     TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP
                                   ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_invsupply_source (source_system, source_key),
    KEY idx_invsupply_item (item_code),
    KEY idx_invsupply_warehouse (warehouse),
    KEY idx_invsupply_category (category)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- Days-of-supply view: avg daily usage over the trailing 30 days; NULL days
-- of supply when there is no usage (cannot divide) — the UI reports those
-- separately instead of showing a misleading number.
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
    END                                                    AS days_of_supply,
    CASE
        WHEN s.item_created IS NOT NULL
             AND s.item_created > DATE_SUB(CURDATE(), INTERVAL 30 DAY)
            THEN 1
        ELSE 0
    END                                                    AS is_new_item
FROM inventory_supply s;
