-- Migration 007: standardize customer complaint categories (SCRUM-16).
--
-- Problem: complaints arrive with free-text `complaint_type` / `reason`, so the
-- same concern is spelled/categorized differently and won't roll up cleanly.
--
-- This adds a canonical taxonomy + a normalization layer WITHOUT losing the
-- original text:
--   * complaint_categories  - the allowed (concern_type, concern_reason) list
--                             (the "starter set"; edit here to extend).
--   * complaint_reason_map   - maps any raw type/reason text -> canonical
--                             concern_type + concern_reason (case-insensitive).
--   * complaints.concern_type - a standardized category column, backfilled from
--                             the map (raw text is kept in complaint_type/reason).
--   * vw_complaints          - view exposing standardized concern_type +
--                             concern_reason so every page reports consistently.
--
-- Idempotent: safe to re-run.

USE kpi_dashboard;

-- --- canonical taxonomy (starter set) --------------------------------------
CREATE TABLE IF NOT EXISTS complaint_categories (
    concern_type   VARCHAR(120) NOT NULL,
    concern_reason VARCHAR(120) NOT NULL,
    active         TINYINT(1)   NOT NULL DEFAULT 1,
    sort_order     INT          NOT NULL DEFAULT 0,
    PRIMARY KEY (concern_type, concern_reason)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

INSERT INTO complaint_categories (concern_type, concern_reason, sort_order) VALUES
    ('Food Quality',        'Under-baked',            10),
    ('Food Quality',        'Stale/expired',          11),
    ('Food Quality',        'Wrong texture',          12),
    ('Food Quality',        'Foreign material',       13),
    ('Food Safety',         'Contamination',          20),
    ('Food Safety',         'Allergen',               21),
    ('Food Safety',         'Mold',                   22),
    ('Logistics/Warehouse', 'Late delivery',          30),
    ('Logistics/Warehouse', 'Short shipment',         31),
    ('Logistics/Warehouse', 'Wrong item',             32),
    ('Logistics/Warehouse', 'Damaged/torn packaging', 33),
    ('Order/Billing',       'Wrong price',            40),
    ('Order/Billing',       'Label error',            41),
    ('Order/Billing',       'Overcharge',             42),
    ('Other',               'Unspecified',            90)
ON DUPLICATE KEY UPDATE sort_order = VALUES(sort_order), active = 1;

-- --- normalization map (raw free-text -> canonical) ------------------------
-- raw_value is stored lower-cased/trimmed and matched that way. Covers both the
-- Overview `complaints` sample vocabulary and the CS `customer_complaints`
-- concern_type vocabulary, plus common synonyms.
CREATE TABLE IF NOT EXISTS complaint_reason_map (
    raw_value      VARCHAR(255) NOT NULL,
    concern_type   VARCHAR(120) NOT NULL,
    concern_reason VARCHAR(120) NOT NULL,
    PRIMARY KEY (raw_value)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

INSERT INTO complaint_reason_map (raw_value, concern_type, concern_reason) VALUES
    -- specific reasons
    ('under-baked',        'Food Quality',        'Under-baked'),
    ('underbaked',         'Food Quality',        'Under-baked'),
    ('stale product',      'Food Quality',        'Stale/expired'),
    ('stale',              'Food Quality',        'Stale/expired'),
    ('expired',            'Food Quality',        'Stale/expired'),
    ('wrong texture',      'Food Quality',        'Wrong texture'),
    ('foreign material',   'Food Safety',         'Contamination'),
    ('contamination',      'Food Safety',         'Contamination'),
    ('allergen',           'Food Safety',         'Allergen'),
    ('mold',               'Food Safety',         'Mold'),
    ('late delivery',      'Logistics/Warehouse', 'Late delivery'),
    ('short shipment',     'Logistics/Warehouse', 'Short shipment'),
    ('short shipped',      'Logistics/Warehouse', 'Short shipment'),
    ('wrong item',         'Logistics/Warehouse', 'Wrong item'),
    ('torn packaging',     'Logistics/Warehouse', 'Damaged/torn packaging'),
    ('damaged packaging',  'Logistics/Warehouse', 'Damaged/torn packaging'),
    ('damaged',            'Logistics/Warehouse', 'Damaged/torn packaging'),
    ('wrong price',        'Order/Billing',       'Wrong price'),
    ('label error',        'Order/Billing',       'Label error'),
    ('overcharge',         'Order/Billing',       'Overcharge'),
    -- raw category names (fallback when the reason itself isn't mapped)
    ('quality',            'Food Quality',        'Unspecified'),
    ('food quality',       'Food Quality',        'Unspecified'),
    ('food safety',        'Food Safety',         'Unspecified'),
    ('delivery',           'Logistics/Warehouse', 'Unspecified'),
    ('logistics',          'Logistics/Warehouse', 'Unspecified'),
    ('logistics/whse',     'Logistics/Warehouse', 'Unspecified'),
    ('logistics/warehouse','Logistics/Warehouse', 'Unspecified'),
    ('warehouse',          'Logistics/Warehouse', 'Unspecified'),
    ('shortage',           'Logistics/Warehouse', 'Short shipment'),
    ('packaging',          'Logistics/Warehouse', 'Damaged/torn packaging'),
    ('billing',            'Order/Billing',       'Unspecified'),
    ('order',              'Order/Billing',       'Unspecified')
ON DUPLICATE KEY UPDATE concern_type = VALUES(concern_type), concern_reason = VALUES(concern_reason);

-- --- standardized category column on complaints ----------------------------
-- Add concern_type if it isn't there yet (re-run safe via the prepared guard).
SET @has_col := (SELECT COUNT(*) FROM information_schema.columns
                 WHERE table_schema = DATABASE()
                   AND table_name = 'complaints'
                   AND column_name = 'concern_type');
SET @ddl := IF(@has_col = 0,
    'ALTER TABLE complaints ADD COLUMN concern_type VARCHAR(120) NULL AFTER complaint_type',
    'SELECT 1');
PREPARE stmt FROM @ddl; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Backfill the standardized category: match on the specific reason first, then
-- fall back to the raw complaint_type, then 'Other'.
UPDATE complaints c
LEFT JOIN complaint_reason_map mr ON mr.raw_value = LOWER(TRIM(c.reason))
LEFT JOIN complaint_reason_map mt ON mt.raw_value = LOWER(TRIM(c.complaint_type))
SET c.concern_type = COALESCE(mr.concern_type, mt.concern_type, 'Other');

-- --- standardized view ------------------------------------------------------
CREATE OR REPLACE VIEW vw_complaints AS
SELECT
    c.*,
    COALESCE(c.concern_type, mr.concern_type, mt.concern_type, 'Other') AS std_concern_type,
    COALESCE(mr.concern_reason,
             NULLIF(TRIM(c.reason), ''),
             'Unspecified')                                             AS std_concern_reason
FROM complaints c
LEFT JOIN complaint_reason_map mr ON mr.raw_value = LOWER(TRIM(c.reason))
LEFT JOIN complaint_reason_map mt ON mt.raw_value = LOWER(TRIM(c.complaint_type));
