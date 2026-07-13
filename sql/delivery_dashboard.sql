-- ===========================================================================
-- Delivery / OMS Dashboard schema (Damascus-KPI)
--
-- Local MySQL mirror of the SAP Business One delivery cache that feeds the
-- operations dashboard. Source of truth is SAP HANA (ORDR / RDR1 / DLN1),
-- pulled read-only by the ETL into this table. The dashboard reads ONLY from
-- here, so page loads never wait on the linked SAP server.
--
-- Column set mirrors dbo.KPI_DeliveryDashboardCache 1:1 so the numbers match
-- your existing report.
-- ===========================================================================

SET NAMES utf8mb4;
SET time_zone = '+00:00';

CREATE DATABASE IF NOT EXISTS kpi_dashboard
    CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE kpi_dashboard;

CREATE TABLE IF NOT EXISTS delivery_lines (
    id                INT UNSIGNED NOT NULL AUTO_INCREMENT,

    -- Upsert keys so the ETL is idempotent (one row per SAP order line).
    source_system     VARCHAR(32)  NOT NULL DEFAULT 'PRODHANA',
    source_key        VARCHAR(128) NOT NULL,

    sales_order       VARCHAR(50)  NULL,          -- SAP ORDR.DocNum
    so_status         VARCHAR(50)  NULL,          -- Open / Closed
    posting_date      DATE         NULL,          -- ORDR.DocDate
    ship_date         DATE         NULL,          -- ORDR.DocDueDate (promised ship)
    required_date     DATE         NULL,          -- ORDR.ReqDate
    so_created_date   DATE         NULL,          -- ORDR.CreateDate (order entry — SCRUM-87)
    shipment_date     DATE         NULL,          -- actual goods-out: MAX(DLN1.DocDate) for the line (SCRUM-87)
    customer_code     VARCHAR(50)  NULL,          -- ORDR.CardCode
    customer_name     VARCHAR(255) NULL,          -- ORDR.CardName
    customer_group    VARCHAR(100) NULL,          -- OCRD.GroupCode / OCRG.GroupName
    is_retail         TINYINT(1)   NOT NULL DEFAULT 0,  -- 1 = retail customer
    po_number         VARCHAR(100) NULL,          -- customer PO (always unique)
    item_code         VARCHAR(100) NULL,          -- RDR1.ItemCode
    item_description  VARCHAR(255) NULL,          -- RDR1.Dscription
    warehouse         VARCHAR(100) NULL,          -- RDR1.WhsCode / OWHS.WhsName

    order_qty         DECIMAL(18,4) NOT NULL DEFAULT 0,  -- RDR1.Quantity
    qty_pallet        DECIMAL(18,4) NULL,
    qty_per_pack      DECIMAL(18,4) NULL,
    qty_per_pallet    DECIMAL(18,4) NULL,               -- bags per pallet (e.g. 100)
    unit_of_measure   VARCHAR(50)  NULL,
    released_qty      DECIMAL(18,4) NOT NULL DEFAULT 0,  -- RDR1.ReleasQtty
    delivered_qty     DECIMAL(18,4) NOT NULL DEFAULT 0,  -- delivered (DLN1)
    line_amount       DECIMAL(18,4) NULL,               -- net line total $ (RDR1.LineTotal)
    delivered_amount  DECIMAL(18,4) NULL,               -- delivered value $ (DLN1.LineTotal)
    pick_qty          DECIMAL(18,4) NOT NULL DEFAULT 0,  -- RDR1.PickQty

    pick_status       VARCHAR(50)  NULL,          -- Delivered / Released / Picked / Not Picked
    approved          VARCHAR(20)  NULL,
    short_shipment    VARCHAR(20)  NULL,          -- 'Yes' / 'No'
    late_shipment     VARCHAR(20)  NULL,          -- 'Yes' / 'No'
    complete_shipment VARCHAR(20)  NULL,          -- 'Yes' / 'No'
    otif              VARCHAR(20)  NULL,          -- 'Yes' / 'No'
    fill_rate         DECIMAL(18,4) NULL,         -- line delivered/order
    manual_bol        VARCHAR(20)  NULL,
    carrier           VARCHAR(255) NULL,

    refreshed_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_delivery_source (source_system, source_key),
    KEY idx_delivery_dates (posting_date, ship_date),
    KEY idx_delivery_cycle (so_created_date, shipment_date),
    KEY idx_delivery_filter (warehouse, customer_code, item_code, carrier, so_status, pick_status),
    KEY idx_delivery_order (sales_order, po_number)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Normalised view: turns the Yes/No text flags into 1/0 so the dashboard math
-- (OTIF %, Late %, Short %) is a plain AVG(). Keeps a single source of truth.
-- ---------------------------------------------------------------------------
CREATE OR REPLACE VIEW vw_delivery_lines AS
SELECT
    dl.*,
    CASE WHEN UPPER(COALESCE(otif, ''))            IN ('YES','Y','1','TRUE') THEN 1 ELSE 0 END AS otif_flag,
    CASE WHEN UPPER(COALESCE(late_shipment, ''))   IN ('YES','Y','1','TRUE') THEN 1 ELSE 0 END AS late_flag,
    CASE WHEN UPPER(COALESCE(short_shipment, ''))  IN ('YES','Y','1','TRUE') THEN 1 ELSE 0 END AS short_flag,
    CASE WHEN UPPER(COALESCE(complete_shipment,'')) IN ('YES','Y','1','TRUE') THEN 1 ELSE 0 END AS complete_flag,
    CASE WHEN COALESCE(delivered_qty, 0) = 0 THEN 1 ELSE 0 END AS zero_delivery_flag,
    -- Fill-rate / IFR eligibility: canceled orders and lines never picked are
    -- excluded from the fill-rate denominator (they were never meant to ship,
    -- so they shouldn't drag the metric down).
    CASE WHEN UPPER(COALESCE(so_status, ''))   IN ('CANCELLED','CANCELED')
           OR UPPER(COALESCE(pick_status, '')) = 'NOT PICKED'
         THEN 0 ELSE 1 END AS ifr_eligible
FROM delivery_lines dl;

-- ---------------------------------------------------------------------------
-- Complaints: drives the v2 complaint charts (# of complaints + $ value lost,
-- and count by type/reason). Loaded from SAP service calls / credit memos or a
-- manual feed. Kept separate from delivery_lines (different grain).
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS complaints (
    id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    source_system  VARCHAR(32)  NOT NULL DEFAULT 'MANUAL',
    source_key     VARCHAR(128) NOT NULL,
    complaint_date DATE         NULL,
    customer_code  VARCHAR(50)  NULL,
    customer_name  VARCHAR(255) NULL,
    complaint_type VARCHAR(120) NULL,
    reason         VARCHAR(255) NULL,
    sales_order    VARCHAR(50)  NULL,
    lost_amount    DECIMAL(18,4) NOT NULL DEFAULT 0,
    status         VARCHAR(50)  NULL,
    refreshed_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_complaint_source (source_system, source_key),
    KEY idx_complaint_date (complaint_date),
    KEY idx_complaint_type (complaint_type)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
