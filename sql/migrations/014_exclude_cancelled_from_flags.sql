-- 014: exclude Cancelled orders from OTIF / Late / Short / zero-delivery rates.
--
-- A cancelled order was never meant to ship, so its lines must not count as
-- late, not-OTIF, short, or zero-delivery — they were inflating late % and
-- depressing OTIF %. The flags become NULL on cancelled lines so AVG()/SUM()
-- skip them entirely (numerator AND denominator). ifr_eligible already
-- excluded cancelled lines from the fill-rate math (migration 004).
-- View-only change — no data is modified.
--
-- Apply: mysql -u kpi_app -p kpi_dashboard < sql/migrations/014_exclude_cancelled_from_flags.sql

CREATE OR REPLACE VIEW vw_delivery_lines AS
SELECT
    dl.*,
    CASE WHEN UPPER(COALESCE(so_status, '')) IN ('CANCELLED','CANCELED') THEN NULL
         WHEN UPPER(COALESCE(otif, ''))            IN ('YES','Y','1','TRUE') THEN 1 ELSE 0 END AS otif_flag,
    CASE WHEN UPPER(COALESCE(so_status, '')) IN ('CANCELLED','CANCELED') THEN NULL
         WHEN UPPER(COALESCE(late_shipment, ''))   IN ('YES','Y','1','TRUE') THEN 1 ELSE 0 END AS late_flag,
    CASE WHEN UPPER(COALESCE(so_status, '')) IN ('CANCELLED','CANCELED') THEN NULL
         WHEN UPPER(COALESCE(short_shipment, ''))  IN ('YES','Y','1','TRUE') THEN 1 ELSE 0 END AS short_flag,
    CASE WHEN UPPER(COALESCE(complete_shipment,'')) IN ('YES','Y','1','TRUE') THEN 1 ELSE 0 END AS complete_flag,
    CASE WHEN UPPER(COALESCE(so_status, '')) IN ('CANCELLED','CANCELED') THEN NULL
         WHEN COALESCE(delivered_qty, 0) = 0 THEN 1 ELSE 0 END AS zero_delivery_flag,
    -- Fill-rate / IFR eligibility: canceled orders and lines never picked are
    -- excluded from the fill-rate denominator.
    CASE WHEN UPPER(COALESCE(so_status, ''))   IN ('CANCELLED','CANCELED')
           OR UPPER(COALESCE(pick_status, '')) = 'NOT PICKED'
         THEN 0 ELSE 1 END AS ifr_eligible
FROM delivery_lines dl;
