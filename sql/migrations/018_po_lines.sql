-- Migration 018: purchase-order line cache for Supplier OTIF (SCRUM-88).
--
-- One row per PURCHASE ORDER LINE, mirroring how delivery_lines caches sales
-- orders. Populated by etl/pull_po.php from SAP B1 (OPOR/POR1 with receipts
-- rolled up from the linked Goods Receipt POs, PDN1). READ cache only — the
-- source is never written. Safe to re-run (IF NOT EXISTS + idempotent view).

USE kpi_dashboard;

CREATE TABLE IF NOT EXISTS po_lines (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    source_system    VARCHAR(32)   NOT NULL DEFAULT 'PRODHANA',
    source_key       VARCHAR(120)  NOT NULL,          -- DocEntry-LineNum

    po_number        VARCHAR(64)   NULL,              -- OPOR.DocNum
    po_status        VARCHAR(32)   NULL,              -- Open / Closed / Cancelled
    posting_date     DATE          NULL,              -- OPOR.DocDate (PO entry)
    due_date         DATE          NULL,              -- promised delivery date
    receipt_date     DATE          NULL,              -- last linked goods receipt

    supplier_code    VARCHAR(64)   NULL,
    supplier_name    VARCHAR(255)  NULL,
    item_code        VARCHAR(64)   NULL,
    item_description VARCHAR(255)  NULL,
    warehouse        VARCHAR(100)  NULL,

    order_qty        DECIMAL(18,4) NULL,
    received_qty     DECIMAL(18,4) NULL,
    line_amount      DECIMAL(18,4) NULL,
    unit_of_measure  VARCHAR(32)   NULL,

    refreshed_at     TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP
                                   ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_po_source (source_system, source_key),
    KEY idx_po_number (po_number),
    KEY idx_po_supplier (supplier_code),
    KEY idx_po_posting (posting_date),
    KEY idx_po_warehouse (warehouse)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- Line-level OTIF flag, mirroring SCRUM-86's sales-side semantics:
--   NULL  = cancelled PO (excluded from the KPI entirely)
--   1     = received in full (received >= ordered) AND the last receipt landed
--           on/before the promised date + 1 day grace
--   0     = everything else (short, late, or not yet received)
-- Order-level Supplier OTIF takes MIN(otif_flag) per po_number, so one bad
-- line fails the whole PO.
CREATE OR REPLACE VIEW vw_po_lines AS
SELECT
    p.*,
    COALESCE(NULLIF(TRIM(p.supplier_name), ''), p.supplier_code, 'Unknown') AS std_supplier,
    CASE
        WHEN UPPER(COALESCE(p.po_status, '')) IN ('CANCELLED', 'CANCELED') THEN NULL
        WHEN COALESCE(p.received_qty, 0) >= COALESCE(p.order_qty, 0)
             AND COALESCE(p.order_qty, 0) > 0
             AND p.receipt_date IS NOT NULL
             AND p.due_date IS NOT NULL
             AND p.receipt_date <= DATE_ADD(p.due_date, INTERVAL 1 DAY)
            THEN 1
        ELSE 0
    END AS otif_flag
FROM po_lines p;
