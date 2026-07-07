-- Migration 004: exclude canceled / not-picked orders from fill-rate (IFR).
--
-- Canceled orders and lines that were never picked were never meant to ship,
-- so counting their ordered qty in the fill-rate denominator understated the
-- metric. This refreshes vw_delivery_lines to expose an `ifr_eligible` flag;
-- the dashboard's fill-rate math sums only eligible lines. View-only change --
-- no data is modified. Re-run etl/pull_delivery.php afterwards so canceled
-- orders land with so_status = 'Cancelled'.

USE kpi_dashboard;

CREATE OR REPLACE VIEW vw_delivery_lines AS
SELECT
    dl.*,
    CASE WHEN UPPER(COALESCE(otif, ''))            IN ('YES','Y','1','TRUE') THEN 1 ELSE 0 END AS otif_flag,
    CASE WHEN UPPER(COALESCE(late_shipment, ''))   IN ('YES','Y','1','TRUE') THEN 1 ELSE 0 END AS late_flag,
    CASE WHEN UPPER(COALESCE(short_shipment, ''))  IN ('YES','Y','1','TRUE') THEN 1 ELSE 0 END AS short_flag,
    CASE WHEN UPPER(COALESCE(complete_shipment,'')) IN ('YES','Y','1','TRUE') THEN 1 ELSE 0 END AS complete_flag,
    CASE WHEN COALESCE(delivered_qty, 0) = 0 THEN 1 ELSE 0 END AS zero_delivery_flag,
    -- Fill-rate / IFR eligibility: canceled orders and lines never picked are
    -- excluded from the fill-rate denominator.
    CASE WHEN UPPER(COALESCE(so_status, ''))   IN ('CANCELLED','CANCELED')
           OR UPPER(COALESCE(pick_status, '')) = 'NOT PICKED'
         THEN 0 ELSE 1 END AS ifr_eligible
FROM delivery_lines dl;
