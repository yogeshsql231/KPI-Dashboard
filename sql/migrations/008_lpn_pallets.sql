-- Migration 008: LPN (License Plate Number) pallet cache for the warehouse team.
--
-- SCRUM-18 — "Ensure LPN data is available (to support the warehouse team)."
--
-- An LPN is the pallet/container "license plate" the WMS assigns to a physical
-- unit as it is received, put away, picked and shipped. Damascus Bakery runs
-- Beas Manufacturing / WMS on SAP B1, so the LPN detail lives in the Beas
-- pallet-master tables (e.g. @BMM_PALLETMASTER, @BMM_BINDETAIL). Those tables
-- sit on the company LAN, so this local `lpn_pallets` table is a READ cache the
-- ETL (etl/pull_lpn.php) refreshes on the XAMPP box — exactly like delivery_lines.
--
-- No source data is modified. Safe to re-run (IF NOT EXISTS + idempotent view).

USE kpi_dashboard;

CREATE TABLE IF NOT EXISTS lpn_pallets (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    source_system    VARCHAR(32)   NOT NULL DEFAULT 'PRIMSBM',
    source_key       VARCHAR(120)  NOT NULL,          -- unique LPN/pallet key from WMS

    lpn              VARCHAR(120)  NOT NULL,           -- the license plate number
    status           VARCHAR(40)   NULL,              -- Open / Picked / Shipped / Closed …
    warehouse        VARCHAR(100)  NULL,              -- matches delivery_lines.warehouse
    bin_location     VARCHAR(100)  NULL,              -- bin / storage location code

    item_code        VARCHAR(64)   NULL,
    item_description VARCHAR(255)  NULL,
    batch_number     VARCHAR(120)  NULL,              -- batch / lot on the pallet
    quantity         DECIMAL(18,4) NULL,              -- qty of item on the pallet
    unit_of_measure  VARCHAR(32)   NULL,

    received_date    DATE          NULL,
    expiry_date      DATE          NULL,

    refreshed_at     TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP
                                   ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_lpn_source (source_system, source_key),
    KEY idx_lpn_warehouse (warehouse),
    KEY idx_lpn_lpn (lpn),
    KEY idx_lpn_item (item_code),
    KEY idx_lpn_batch (batch_number)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- Convenience view: normalises blanks and exposes a display status so the
-- dashboard reads consistently even before every WMS column is mapped.
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
