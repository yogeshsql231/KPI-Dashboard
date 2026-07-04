-- Migration 003: fields + tables for the v2 "Overview" dashboard.
--
-- Adds the money / pallet / retail context the v2 header KPIs and charts need,
-- plus a complaints table for the complaint charts. All delivery_lines columns
-- are additive and nullable so existing rows/loads keep working. Fresh installs
-- get everything via sql/delivery_dashboard.sql.

USE kpi_dashboard;

-- --- delivery_lines: money, pallet and retail context ----------------------
ALTER TABLE delivery_lines
    ADD COLUMN line_amount     DECIMAL(18,4) NULL AFTER delivered_qty,   -- net line total $ (RDR1.LineTotal)
    ADD COLUMN delivered_amount DECIMAL(18,4) NULL AFTER line_amount,    -- delivered value $ (DLN1.LineTotal)
    ADD COLUMN qty_per_pallet  DECIMAL(18,4) NULL AFTER qty_per_pack,    -- bags per pallet (e.g. 100)
    ADD COLUMN customer_group  VARCHAR(100)  NULL AFTER customer_name,   -- OCRD.GroupCode / OCRG.GroupName
    ADD COLUMN is_retail       TINYINT(1)    NOT NULL DEFAULT 0 AFTER customer_group;

-- Refresh the view so the new columns are visible through it. MySQL expands
-- "dl.*" at view-creation time, so an existing view would otherwise still hide
-- line_amount / delivered_amount / is_retail from the dashboard queries.
CREATE OR REPLACE VIEW vw_delivery_lines AS
SELECT
    dl.*,
    CASE WHEN UPPER(COALESCE(otif, ''))            IN ('YES','Y','1','TRUE') THEN 1 ELSE 0 END AS otif_flag,
    CASE WHEN UPPER(COALESCE(late_shipment, ''))   IN ('YES','Y','1','TRUE') THEN 1 ELSE 0 END AS late_flag,
    CASE WHEN UPPER(COALESCE(short_shipment, ''))  IN ('YES','Y','1','TRUE') THEN 1 ELSE 0 END AS short_flag,
    CASE WHEN UPPER(COALESCE(complete_shipment,'')) IN ('YES','Y','1','TRUE') THEN 1 ELSE 0 END AS complete_flag,
    CASE WHEN COALESCE(delivered_qty, 0) = 0 THEN 1 ELSE 0 END AS zero_delivery_flag
FROM delivery_lines dl;

-- --- complaints (drives the two complaint charts) --------------------------
CREATE TABLE IF NOT EXISTS complaints (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,

    source_system  VARCHAR(32)  NOT NULL DEFAULT 'MANUAL',
    source_key     VARCHAR(128) NOT NULL,

    complaint_date DATE         NULL,
    customer_code  VARCHAR(50)  NULL,
    customer_name  VARCHAR(255) NULL,
    complaint_type VARCHAR(120) NULL,          -- category (e.g. Quality, Delivery, Packaging)
    reason         VARCHAR(255) NULL,          -- specific reason / detail
    sales_order    VARCHAR(50)  NULL,
    lost_amount    DECIMAL(18,4) NOT NULL DEFAULT 0,  -- $ value lost / credited
    status         VARCHAR(50)  NULL,

    refreshed_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_complaint_source (source_system, source_key),
    KEY idx_complaint_date (complaint_date),
    KEY idx_complaint_type (complaint_type)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
