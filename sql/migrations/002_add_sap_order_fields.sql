-- Migration 002: extend order_shipments with SAP Business One order fields
-- needed for the reference-style dashboard (Total Orders, Released/Picked/
-- Delivered qty, Fill Rate, OTIF%, Late Order%, Short Shipment%, and the
-- Warehouse / Carrier / SO Status / Pick Status filters).
--
-- All columns are additive and nullable so existing rows/loads keep working.
-- Fresh installs get these via sql/schema.sql.

ALTER TABLE order_shipments
    ADD COLUMN so_docentry   VARCHAR(32)  NULL AFTER po_number,  -- SAP ORDR.DocEntry (unique SO id)
    ADD COLUMN so_status     VARCHAR(16)  NULL AFTER comments,   -- SAP DocStatus: O=Open, C=Closed
    ADD COLUMN pick_status   VARCHAR(16)  NULL,                  -- pick list status (OPKL.Status)
    ADD COLUMN warehouse     VARCHAR(64)  NULL,                  -- RDR1.WhsCode / OWHS
    ADD COLUMN carrier       VARCHAR(64)  NULL,                  -- ship carrier
    ADD COLUMN qty_released  INT          NULL,                  -- released (open) qty
    ADD COLUMN qty_picked    INT          NULL,                  -- picked qty
    ADD COLUMN qty_delivered INT          NULL,                  -- actual delivered qty
    ADD COLUMN due_date      DATE         NULL,                  -- SAP DocDueDate (promised)
    ADD KEY idx_shipments_warehouse (warehouse),
    ADD KEY idx_shipments_so (so_docentry);
