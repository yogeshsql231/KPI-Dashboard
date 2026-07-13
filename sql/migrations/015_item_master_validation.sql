-- Migration 015: Item-master data-quality rules (SCRUM-22).
--
-- Adds four audit-engine rules that continuously validate the cached item
-- master data (warehouse_stock / material_packaging) for outdated or
-- incomplete records. Findings surface on the Audit page and in the email
-- digest like every other alert.
--
-- Safe to re-run (INSERT IGNORE keeps any user edits).

USE kpi_dashboard;

INSERT IGNORE INTO alert_rules (rule_key, name, category, severity, threshold_num, description) VALUES
 ('item_missing_description', 'Item missing description',          'data', 'warning', NULL,
    'Stocked items whose item master has no description.'),
 ('item_unclassified',        'Item missing type/category',        'data', 'warning', NULL,
    'Stocked items with no product type or category classification (show as Unassigned).'),
 ('item_missing_packaging',   'Item missing pallet conversion',    'data', 'warning', NULL,
    'Stocked items with no packaging/capacity data on any pallet-conversion path — pallet KPIs show "—" for them.'),
 ('item_master_stale',        'Item master cache is stale',        'data', 'warning', 168,
    'The warehouse stock / item master cache has not refreshed for longer than the threshold (hours).');
