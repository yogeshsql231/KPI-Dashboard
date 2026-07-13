-- 017: actual delivery date on delivery_lines (SCRUM-87 Order Cycle Time).
--
-- delivery_date = the date the goods actually left the warehouse for this SO
-- line — the LAST linked delivery-note posting date (MAX DLN1.DocDate), so
-- orders split across partial shipments measure to the final shipment. NULL
-- while nothing has shipped (in-flight lines are excluded from cycle-time
-- averages until closed).
--
-- The view is re-created because MySQL expands dl.* at CREATE time, so the
-- new column would otherwise stay invisible to vw_delivery_lines.
--
-- Apply: mysql -u kpi_app -p kpi_dashboard < sql/migrations/017_delivery_date.sql
-- Then re-run the ETL to populate it: php etl/pull_delivery.php --source=PRIMSBM --query=etl/queries/prodhana_delivery.sql --via=PRODHANA

ALTER TABLE delivery_lines
    ADD COLUMN delivery_date DATE NULL AFTER required_date,
    ADD KEY idx_delivery_date (delivery_date);

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
