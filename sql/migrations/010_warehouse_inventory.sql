-- Migration 010: expanded Warehouse view — stock on hand, packaging, batch
-- aging and material movement (Warehouse -> Staging -> Production -> Waste).
--
-- SCRUM-14 (aged inventory % by warehouse) + the approved Warehouse redesign.
--
-- Damascus Bakery runs SAP Business One (+ Beas). These four tables are READ
-- caches the ETL refreshes on the XAMPP box (like delivery_lines / lpn_pallets),
-- because the source DBs sit on the company LAN. Sources per table:
--   * warehouse_stock    <- OITW (on-hand per item/warehouse) + OITM (names)
--   * material_packaging <- OITM UoM/packaging + Beas @BMM_PALLETMASTER
--   * inventory_batches  <- OIBT (batch qty per warehouse) + OBTN (in/expiry)
--   * material_movements <- OINM (inventory journal) / OWTR / OIGE (issues)
--
-- No source data is modified. Safe to re-run (IF NOT EXISTS + idempotent views).

USE kpi_dashboard;

-- 1) Stock on hand: one row per item x warehouse.
CREATE TABLE IF NOT EXISTS warehouse_stock (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    source_system    VARCHAR(32)   NOT NULL DEFAULT 'PRIMSBM',
    source_key       VARCHAR(160)  NOT NULL,          -- e.g. item_code|warehouse
    item_code        VARCHAR(64)   NOT NULL,
    item_description VARCHAR(255)  NULL,
    warehouse        VARCHAR(100)  NOT NULL,           -- matches delivery_lines.warehouse
    on_hand          DECIMAL(18,4) NULL,
    committed        DECIMAL(18,4) NULL,
    on_order         DECIMAL(18,4) NULL,
    unit_of_measure  VARCHAR(32)   NULL,
    pallets          DECIMAL(18,4) NULL,               -- optional pre-computed pallet count
    refreshed_at     TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP
                                   ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_stock_source (source_system, source_key),
    KEY idx_stock_warehouse (warehouse),
    KEY idx_stock_item (item_code)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- 2) Packaging / UoM conversion per item.
CREATE TABLE IF NOT EXISTS material_packaging (
    item_code        VARCHAR(64)   NOT NULL,
    item_description VARCHAR(255)  NULL,
    base_uom         VARCHAR(32)   NULL,               -- kg / cs / ea …
    units_per_case   DECIMAL(18,4) NULL,               -- e.g. 12 ea per case
    cases_per_pallet DECIMAL(18,4) NULL,
    units_per_pallet DECIMAL(18,4) NULL,               -- bags/units per pallet (raw material)
    pack_description VARCHAR(120)  NULL,               -- free text e.g. "20 x 25kg"
    source_system    VARCHAR(32)   NOT NULL DEFAULT 'PRIMSBM',
    refreshed_at     TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP
                                   ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (item_code)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- 3) Batch-level inventory with admission/expiry, for aging by warehouse.
CREATE TABLE IF NOT EXISTS inventory_batches (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    source_system    VARCHAR(32)   NOT NULL DEFAULT 'PRIMSBM',
    source_key       VARCHAR(200)  NOT NULL,          -- e.g. item|batch|warehouse
    item_code        VARCHAR(64)   NOT NULL,
    item_description VARCHAR(255)  NULL,
    batch_number     VARCHAR(120)  NULL,
    warehouse        VARCHAR(100)  NOT NULL,
    quantity         DECIMAL(18,4) NULL,
    unit_of_measure  VARCHAR(32)   NULL,
    pallets          DECIMAL(18,4) NULL,
    admission_date   DATE          NULL,               -- OBTN.InDate
    expiry_date      DATE          NULL,               -- OBTN.ExpDate
    refreshed_at     TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP
                                   ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_batch_source (source_system, source_key),
    KEY idx_batch_warehouse (warehouse),
    KEY idx_batch_item (item_code),
    KEY idx_batch_expiry (expiry_date)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- Batch aging view: age (days) from admission and standard buckets.
CREATE OR REPLACE VIEW vw_inventory_batches AS
SELECT
    b.*,
    COALESCE(NULLIF(TRIM(b.warehouse), ''), 'Unassigned') AS std_warehouse,
    DATEDIFF(CURDATE(), b.admission_date)                 AS age_days,
    CASE
        WHEN b.expiry_date IS NOT NULL AND b.expiry_date < CURDATE() THEN 1 ELSE 0
    END                                                   AS is_expired,
    CASE
        WHEN b.admission_date IS NULL THEN 'unknown'
        WHEN DATEDIFF(CURDATE(), b.admission_date) <= 30 THEN '0-30'
        WHEN DATEDIFF(CURDATE(), b.admission_date) <= 60 THEN '30-60'
        WHEN DATEDIFF(CURDATE(), b.admission_date) <= 90 THEN '60-90'
        ELSE '90+'
    END                                                   AS age_bucket
FROM inventory_batches b;

-- 4) Material movements: raw stage flow. movement_type is normalised to one of
-- receipt / transfer / issue / waste so the flow strip can sum per stage.
CREATE TABLE IF NOT EXISTS material_movements (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    source_system    VARCHAR(32)   NOT NULL DEFAULT 'PRIMSBM',
    source_key       VARCHAR(200)  NOT NULL,          -- doc_type|doc_entry|line
    movement_type    VARCHAR(20)   NOT NULL,          -- receipt|transfer|issue|waste
    doc_date         DATE          NULL,
    item_code        VARCHAR(64)   NULL,
    item_description VARCHAR(255)  NULL,
    from_warehouse   VARCHAR(100)  NULL,
    to_warehouse     VARCHAR(100)  NULL,
    quantity         DECIMAL(18,4) NULL,
    unit_of_measure  VARCHAR(32)   NULL,
    refreshed_at     TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP
                                   ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_move_source (source_system, source_key),
    KEY idx_move_type (movement_type),
    KEY idx_move_date (doc_date),
    KEY idx_move_item (item_code)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
