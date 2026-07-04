-- Migration 002: Add warehouse and sales_order columns to order_shipments.
-- These replace the Customer/Items filter panels on the dashboard with
-- Warehouse and Sales Order views.

ALTER TABLE order_shipments
    ADD COLUMN warehouse    VARCHAR(128) NULL AFTER ship_via,
    ADD COLUMN sales_order  VARCHAR(64)  NULL AFTER warehouse;

CREATE INDEX idx_shipments_warehouse ON order_shipments (warehouse);
CREATE INDEX idx_shipments_sales_order ON order_shipments (sales_order);
