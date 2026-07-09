-- Migration 009: Audit + email-alert feature.
--
-- SCRUM-15 — "Build audit + email alert features (for operational readings,
-- e.g. silos, batch master)."
--
-- Three pieces:
--   1. operational_readings — a READ cache of operational readings (silo
--      levels, batch-master values, temperatures, …) the ETL refreshes on the
--      XAMPP box from SAP Beas / PRIMSBM, exactly like lpn_pallets. Each row
--      carries the reading plus its allowed min/max so the audit engine can
--      flag out-of-range values without hard-coding thresholds in code.
--   2. alert_rules — the catalogue of checks the audit engine runs. Editable
--      (enable/disable, tune thresholds) instead of hard-coded.
--   3. alert_events — the audit log: one row per fired alert, de-duplicated so
--      re-running the audit updates an existing open alert rather than
--      spamming duplicates. The email digest and Audit page read from here.
--
-- No source data is modified. Safe to re-run (IF NOT EXISTS + idempotent view
-- + INSERT IGNORE seed).

USE kpi_dashboard;

-- ---------------------------------------------------------------------------
-- 1. Operational readings cache (silo / batch-master / etc.)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS operational_readings (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    source_system    VARCHAR(32)   NOT NULL DEFAULT 'PRIMSBM',
    source_key       VARCHAR(120)  NOT NULL,           -- unique reading key from source

    reading_type     VARCHAR(40)   NOT NULL,           -- silo | batch | temperature | …
    location_code    VARCHAR(100)  NULL,               -- silo / tank / bin / line code
    location_name    VARCHAR(255)  NULL,
    item_code        VARCHAR(64)   NULL,
    item_description VARCHAR(255)  NULL,
    batch_number     VARCHAR(120)  NULL,

    reading_value    DECIMAL(18,4) NULL,               -- the measured value
    unit_of_measure  VARCHAR(32)   NULL,               -- kg, %, °F, …
    min_threshold    DECIMAL(18,4) NULL,               -- allowed low bound (NULL = no low check)
    max_threshold    DECIMAL(18,4) NULL,               -- allowed high bound (NULL = no high check)
    status           VARCHAR(40)   NULL,               -- source-provided status, if any

    reading_at       DATETIME      NULL,               -- when the reading was taken
    expiry_date      DATE          NULL,               -- for batch shelf-life checks

    refreshed_at     TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP
                                   ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    UNIQUE KEY uq_reading_source (source_system, source_key),
    KEY idx_reading_type (reading_type),
    KEY idx_reading_location (location_code),
    KEY idx_reading_item (item_code)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- Normalising view: exposes display fields plus the derived audit flags so the
-- dashboard and the audit engine read consistent, pre-computed booleans.
CREATE OR REPLACE VIEW vw_operational_readings AS
SELECT
    r.*,
    COALESCE(NULLIF(TRIM(r.reading_type), ''), 'unknown')       AS std_type,
    COALESCE(NULLIF(TRIM(r.location_name), ''),
             NULLIF(TRIM(r.location_code), ''), 'Unassigned')   AS std_location,
    CASE
        WHEN r.reading_value IS NULL THEN 0
        WHEN r.min_threshold IS NOT NULL AND r.reading_value < r.min_threshold THEN 1
        WHEN r.max_threshold IS NOT NULL AND r.reading_value > r.max_threshold THEN 1
        ELSE 0
    END                                                          AS out_of_range,
    CASE
        WHEN r.expiry_date IS NOT NULL AND r.expiry_date < CURDATE() THEN 1
        ELSE 0
    END                                                          AS is_expired
FROM operational_readings r;

-- ---------------------------------------------------------------------------
-- 2. Alert-rule catalogue (editable; drives the audit engine)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS alert_rules (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    rule_key      VARCHAR(60)  NOT NULL,               -- stable code the engine dispatches on
    name          VARCHAR(160) NOT NULL,
    category      VARCHAR(40)  NOT NULL,               -- operational | warehouse | delivery | data
    severity      VARCHAR(20)  NOT NULL DEFAULT 'warning', -- info | warning | critical
    enabled       TINYINT(1)   NOT NULL DEFAULT 1,
    threshold_num DECIMAL(18,4) NULL,                  -- generic numeric knob (hours, %, …)
    description   VARCHAR(500) NULL,
    updated_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_rule_key (rule_key)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- Built-in rules. INSERT IGNORE so re-running the migration keeps any edits
-- the user has made (enable/disable, threshold tuning) instead of overwriting.
INSERT IGNORE INTO alert_rules (rule_key, name, category, severity, threshold_num, description) VALUES
 ('reading_out_of_range', 'Operational reading out of range', 'operational', 'critical', NULL,
    'A silo / batch / operational reading is below its min or above its max threshold.'),
 ('reading_expired',      'Batch reading past expiry',        'operational', 'critical', NULL,
    'An operational reading (batch) has passed its expiry date.'),
 ('reading_stale',        'Operational reading is stale',     'operational', 'warning', 24,
    'No operational reading has been recorded for longer than the threshold (hours).'),
 ('lpn_expired',          'Expired LPN pallets on hand',      'warehouse',   'warning', NULL,
    'One or more LPN pallets are past their expiry date.'),
 ('delivery_etl_stale',   'Delivery data is stale',           'data',        'warning', 48,
    'The delivery cache has not refreshed for longer than the threshold (hours).'),
 ('otif_below_target',    'OTIF below target',                'delivery',    'warning', NULL,
    'On-Time-In-Full for the trailing window is below the configured OTIF target.');

-- ---------------------------------------------------------------------------
-- 3. Alert event log (audit trail; de-duplicated)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS alert_events (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    rule_key      VARCHAR(60)   NOT NULL,
    severity      VARCHAR(20)   NOT NULL DEFAULT 'warning',
    category      VARCHAR(40)   NOT NULL DEFAULT 'operational',
    entity_type   VARCHAR(40)   NULL,                  -- reading | pallet | kpi | etl
    entity_ref    VARCHAR(160)  NULL,                  -- silo code, LPN, metric name, …
    message       VARCHAR(500)  NOT NULL,
    metric_value  DECIMAL(18,4) NULL,

    -- De-dupe key so re-evaluating the same condition updates the open alert
    -- rather than inserting a duplicate. Convention: rule_key|entity_ref|day.
    dedupe_key    VARCHAR(200)  NOT NULL,
    status        VARCHAR(20)   NOT NULL DEFAULT 'open', -- open | resolved
    occurrences   INT UNSIGNED  NOT NULL DEFAULT 1,

    first_seen_at TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_seen_at  TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    notified_at   DATETIME      NULL,                  -- set when included in a sent digest
    resolved_at   DATETIME      NULL,

    PRIMARY KEY (id),
    UNIQUE KEY uq_alert_dedupe (dedupe_key),
    KEY idx_alert_status (status),
    KEY idx_alert_severity (severity),
    KEY idx_alert_rule (rule_key)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
