-- Migration 012: estimated vs actual production stock usage (SCRUM-65).
--
-- One row per production order line (component): the quantity the order
-- PLANNED to consume vs the quantity ACTUALLY issued to production. Read
-- cache refreshed on the XAMPP box by etl/pull_inventory.php --what=production
-- (source: SAP B1 OWOR/WOR1 — planned vs issued component quantities; Beas
-- may post consumption through the same documents). No source data modified.

USE kpi_dashboard;

CREATE TABLE IF NOT EXISTS production_usage (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    source_system    VARCHAR(32)   NOT NULL DEFAULT 'PRIMSBM',
    source_key       VARCHAR(200)  NOT NULL,          -- prod_order|line
    production_order VARCHAR(64)   NULL,              -- OWOR.DocNum
    doc_date         DATE          NULL,              -- order posting/start date
    item_code        VARCHAR(64)   NOT NULL,          -- component item
    item_description VARCHAR(255)  NULL,
    warehouse        VARCHAR(100)  NULL,              -- issue-from warehouse
    planned_qty      DECIMAL(18,4) NULL,              -- WOR1.PlannedQty
    actual_qty       DECIMAL(18,4) NULL,              -- WOR1.IssuedQty
    unit_of_measure  VARCHAR(32)   NULL,
    refreshed_at     TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP
                                   ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_produsage_source (source_system, source_key),
    KEY idx_produsage_date (doc_date),
    KEY idx_produsage_item (item_code),
    KEY idx_produsage_warehouse (warehouse)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
