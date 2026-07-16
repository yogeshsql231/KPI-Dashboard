-- Migration 021: raw vs finished-goods classification + $ valuation on LPN pallets.
--
-- SCRUM-105 — the Overview's "Pallets by warehouse group" widget must show how
-- many raw-material vs finished-goods pallets each site holds and their $ value.
-- item_type is classified by the ETL (SAP item group / FG warehouse heuristics);
-- pallet_value = pallet quantity × SAP average item cost (OITM.AvgPrice).
--
-- Safe to re-run (IF NOT EXISTS + idempotent view).

USE kpi_dashboard;

ALTER TABLE lpn_pallets
    ADD COLUMN IF NOT EXISTS item_type    VARCHAR(20)   NULL AFTER item_description,
    ADD COLUMN IF NOT EXISTS pallet_value DECIMAL(18,2) NULL AFTER quantity;

-- Recreate the view so the new columns are exposed (views freeze p.* at
-- creation time).
CREATE OR REPLACE VIEW vw_lpn_pallets AS
SELECT
    p.*,
    COALESCE(NULLIF(TRIM(p.status), ''), 'Unknown')       AS std_status,
    COALESCE(NULLIF(TRIM(p.warehouse), ''), 'Unassigned') AS std_warehouse,
    CASE
        WHEN p.expiry_date IS NOT NULL AND p.expiry_date < CURDATE() THEN 1
        ELSE 0
    END                                                   AS is_expired
FROM lpn_pallets p;
