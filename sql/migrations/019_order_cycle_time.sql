-- Migration 019: Order Cycle Time fields on delivery_lines (SCRUM-87).
--
-- Order Cycle Time = actual shipment date − SO creation date, averaged per
-- order. Two additive, nullable columns hold the raw dates so the KPI is a
-- plain DATEDIFF in the repository (see DeliveryRepository::orderCycleTime and
-- KpiRepository::orderCycleTime):
--
--   so_created_date  order-entry date  (SAP ORDR.CreateDate)
--   shipment_date    actual goods-out  (MAX(DLN1.DocDate) for the SO line)
--
-- Both are populated read-only by etl/pull_delivery.php from prodhana_delivery.sql.
-- Fresh installs get these via sql/delivery_dashboard.sql. Safe to re-run:
-- ADD COLUMN IF NOT EXISTS is MariaDB-native; on stock MySQL apply once.

USE kpi_dashboard;

ALTER TABLE delivery_lines
    ADD COLUMN IF NOT EXISTS so_created_date DATE NULL AFTER required_date,
    ADD COLUMN IF NOT EXISTS shipment_date   DATE NULL AFTER so_created_date;

-- Index the cycle-time date pair (ignore the error if it already exists).
ALTER TABLE delivery_lines
    ADD KEY idx_delivery_cycle (so_created_date, shipment_date);
