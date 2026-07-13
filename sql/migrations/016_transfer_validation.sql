-- Migration 016: Transfer-record data-quality rules (SCRUM-23).
--
-- Adds four audit-engine rules that continuously validate cached warehouse
-- transfer records (material_movements, movement_type='transfer') for
-- incomplete or implausible data. Findings surface on the Audit page and in
-- the email digest like every other alert.
--
-- Safe to re-run (INSERT IGNORE keeps any user edits).

USE kpi_dashboard;

INSERT IGNORE INTO alert_rules (rule_key, name, category, severity, threshold_num, description) VALUES
 ('transfer_missing_endpoint', 'Transfer missing from/to warehouse', 'data', 'warning', NULL,
    'Transfer records with no source or destination warehouse.'),
 ('transfer_same_warehouse',   'Transfer within the same warehouse', 'data', 'warning', NULL,
    'Transfer records where the from and to warehouse are identical.'),
 ('transfer_bad_quantity',     'Transfer with bad quantity',         'data', 'warning', NULL,
    'Transfer records with a missing, zero or negative quantity.'),
 ('transfer_missing_item',     'Transfer with no item code',         'data', 'warning', NULL,
    'Transfer records that do not identify the item being moved.');
