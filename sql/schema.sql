-- ===========================================================================
-- KPI Dashboard - schema for the Customer Service / Order Management domain.
--
-- Scope: the first, simplest set of Customer Service KPIs described in the
-- requirement doc and reference workbooks:
--   * OTIF  (On-Time In-Full delivery %)
--   * IFR   (Item Fill Rate %)
--   * Shipped Short (# of cases)
--   * Customer Complaints (count + pareto by concern type)
--   * PO Revisions (# of times a customer was contacted to revise a PO)
--   * Lead time (order entry -> shipment, in days)
--
-- KPI math is intentionally implemented in VIEWS / a stored PROCEDURE so the
-- database is the single source of truth and every consumer (PHP dashboard,
-- REST API, ad-hoc SQL) gets identical, accurate numbers.
--
-- The per-line OTIF / IFR definitions mirror the reference workbook exactly:
--   cases_short = qty_requested - qty_shipped            (raw; may be negative)
--   delay_days  = actual_date  - requested_date          (DATEDIFF)
--   otif_test   = cases_short + COALESCE(delay_days, 0)
--   otif_flag   = 1 when otif_test <= 1 else 0           (1-unit tolerance)
--   ifr         = qty_shipped / qty_requested            (per line, capped 1)
-- Engine: MySQL 8 / MariaDB 10.4+.
-- ===========================================================================

SET NAMES utf8mb4;
SET time_zone = '+00:00';

-- ---------------------------------------------------------------------------
-- Reference: customers (optional lookup; shipments also store the raw name so
-- data loads never fail on an unknown customer).
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS customers (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    customer_code VARCHAR(32)  NULL,
    customer_name VARCHAR(255) NOT NULL,
    created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_customers_name (customer_name)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Fact table: one row per PO line shipped. Drives OTIF, IFR, Short & Lead time.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS order_shipments (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    ship_date      DATE           NOT NULL,
    po_number      VARCHAR(64)    NOT NULL,
    customer       VARCHAR(255)   NOT NULL,
    ship_via       VARCHAR(32)    NULL,
    item_number    VARCHAR(64)    NOT NULL,
    qty_requested  INT            NOT NULL DEFAULT 0,
    qty_shipped    INT            NOT NULL DEFAULT 0,
    order_date     DATE           NULL,
    requested_date DATE           NULL,   -- requested pick-up / delivery date
    actual_date    DATE           NULL,   -- actual pick-up / delivery date
    is_sample      TINYINT(1)     NOT NULL DEFAULT 0,
    comments       VARCHAR(255)   NULL,
    source_system  VARCHAR(32)    NULL,   -- e.g. PRIMSBM, PRODHANA, API
    source_key     VARCHAR(128)   NULL,   -- natural key from the source row (for idempotent ETL upserts)
    created_at     TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP
                                  ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_shipments_source (source_system, source_key),
    KEY idx_shipments_ship_date (ship_date),
    KEY idx_shipments_customer  (customer),
    KEY idx_shipments_po        (po_number),
    KEY idx_shipments_item      (item_number)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Customer complaints (feeds monthly count + pareto by concern type).
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS customer_complaints (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    complaint_date   DATE           NOT NULL,
    customer         VARCHAR(255)   NULL,
    item_number      VARCHAR(64)    NULL,
    item_description VARCHAR(255)   NULL,
    concern          VARCHAR(128)   NULL,   -- e.g. Damaged Product, Short shipment
    concern_type     VARCHAR(128)   NULL,   -- Logistics / Whse, Food Quality, Food Safety
    dollar_value     DECIMAL(12, 2) NULL,   -- credit / billback value (negative = credit)
    created_at       TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_complaints_date (complaint_date),
    KEY idx_complaints_type (concern_type)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- PO revisions (# of times a customer was contacted to revise a PO).
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS po_revisions (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    revision_date DATE          NOT NULL,
    po_number     VARCHAR(64)   NOT NULL,
    customer      VARCHAR(255)  NULL,
    revise_count  INT           NOT NULL DEFAULT 1,
    created_at    TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_po_revisions_date (revision_date)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- KPI targets (one row per metric; used to color-code the dashboard).
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS kpi_targets (
    metric_key  VARCHAR(64)   NOT NULL,
    target_value DECIMAL(12, 4) NOT NULL,
    PRIMARY KEY (metric_key)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

INSERT INTO kpi_targets (metric_key, target_value) VALUES
    ('otif', 0.9800),
    ('item_fill_rate', 0.9800),
    ('shipped_short', 0.0000)
ON DUPLICATE KEY UPDATE target_value = VALUES(target_value);

-- ===========================================================================
-- VIEWS
-- ===========================================================================

-- Per-line KPI enrichment: cases short, delay, OTIF flag and IFR.
CREATE OR REPLACE VIEW vw_order_shipment_kpi AS
SELECT
    os.id,
    os.ship_date,
    os.po_number,
    os.customer,
    os.ship_via,
    os.item_number,
    os.qty_requested,
    os.qty_shipped,
    os.order_date,
    os.requested_date,
    os.actual_date,
    os.is_sample,
    (os.qty_requested - os.qty_shipped)                         AS cases_short,
    GREATEST(os.qty_requested - os.qty_shipped, 0)              AS cases_short_pos,
    DATEDIFF(os.actual_date, os.requested_date)                 AS delay_days,
    DATEDIFF(os.actual_date, os.order_date)                     AS lead_time_days,
    CASE
        WHEN ((os.qty_requested - os.qty_shipped)
              + COALESCE(DATEDIFF(os.actual_date, os.requested_date), 0)) <= 1
        THEN 1 ELSE 0
    END                                                         AS otif_flag,
    CASE
        WHEN os.qty_requested > 0
        THEN LEAST(os.qty_shipped / os.qty_requested, 1.0)
        ELSE NULL
    END                                                         AS ifr
FROM order_shipments os;

-- Overall Customer Service scorecard (single row).
CREATE OR REPLACE VIEW vw_kpi_summary AS
SELECT
    (SELECT AVG(otif_flag) FROM vw_order_shipment_kpi WHERE is_sample = 0)      AS otif,
    (SELECT AVG(ifr)       FROM vw_order_shipment_kpi WHERE is_sample = 0)      AS item_fill_rate,
    (SELECT COALESCE(SUM(cases_short_pos), 0)
       FROM vw_order_shipment_kpi WHERE is_sample = 0)                          AS shipped_short_cases,
    (SELECT AVG(lead_time_days)
       FROM vw_order_shipment_kpi WHERE is_sample = 0)                          AS avg_lead_time_days,
    (SELECT COUNT(*) FROM order_shipments WHERE is_sample = 0)                  AS total_lines,
    (SELECT COUNT(DISTINCT po_number) FROM order_shipments WHERE is_sample = 0) AS total_pos,
    (SELECT COALESCE(SUM(qty_shipped), 0) FROM order_shipments WHERE is_sample = 0) AS total_qty_shipped,
    (SELECT COUNT(*) FROM customer_complaints)                                  AS total_complaints,
    (SELECT COALESCE(SUM(revise_count), 0) FROM po_revisions)                   AS total_po_revisions;

-- OTIF & IFR trended by ship date.
CREATE OR REPLACE VIEW vw_kpi_by_date AS
SELECT
    ship_date,
    COUNT(*)          AS line_count,
    AVG(otif_flag)    AS otif,
    AVG(ifr)          AS item_fill_rate,
    SUM(cases_short_pos) AS shipped_short_cases
FROM vw_order_shipment_kpi
WHERE is_sample = 0
GROUP BY ship_date
ORDER BY ship_date;

-- Qty shipped by customer (top movers).
CREATE OR REPLACE VIEW vw_customer_shipment AS
SELECT customer, SUM(qty_shipped) AS qty_shipped
FROM order_shipments
WHERE is_sample = 0
GROUP BY customer
ORDER BY qty_shipped DESC;

-- Qty shipped by SKU (top movers).
CREATE OR REPLACE VIEW vw_sku_shipment AS
SELECT item_number, SUM(qty_shipped) AS qty_shipped
FROM order_shipments
WHERE is_sample = 0
GROUP BY item_number
ORDER BY qty_shipped DESC;

-- Complaints pareto by concern type.
CREATE OR REPLACE VIEW vw_complaints_pareto AS
SELECT
    COALESCE(concern_type, 'Unclassified') AS concern_type,
    COUNT(*)                               AS complaint_count,
    COALESCE(SUM(dollar_value), 0)         AS dollar_value
FROM customer_complaints
GROUP BY COALESCE(concern_type, 'Unclassified')
ORDER BY complaint_count DESC;

-- ===========================================================================
-- STORED PROCEDURE: KPI summary for an arbitrary date range.
-- ===========================================================================
DROP PROCEDURE IF EXISTS sp_kpi_summary;
DELIMITER //
CREATE PROCEDURE sp_kpi_summary(IN p_from DATE, IN p_to DATE)
BEGIN
    SELECT
        AVG(otif_flag)                 AS otif,
        AVG(ifr)                       AS item_fill_rate,
        COALESCE(SUM(cases_short_pos), 0) AS shipped_short_cases,
        AVG(lead_time_days)            AS avg_lead_time_days,
        COUNT(*)                       AS total_lines,
        COUNT(DISTINCT po_number)      AS total_pos
    FROM vw_order_shipment_kpi
    WHERE is_sample = 0
      AND (p_from IS NULL OR ship_date >= p_from)
      AND (p_to   IS NULL OR ship_date <= p_to);
END //
DELIMITER ;
