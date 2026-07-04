-- ===========================================================================
-- PRIMSBM (Microsoft SQL Server) -> order_shipments source query.
--
-- FILL THIS IN: replace the placeholder table/columns below with your real
-- PRIMSBM shipment/delivery table (or view / stored-proc result). The ETL
-- (etl/pull_shipments.php) only cares about the OUTPUT column names, so alias
-- your columns to exactly these names:
--
--   source_key      -- a stable unique id per line (e.g. delivery line PK).
--                      Required & must be unique; used for idempotent upserts.
--   ship_date       -- date the line shipped
--   po_number       -- customer PO number
--   customer        -- customer name (ship-to)
--   ship_via        -- carrier / ship method (nullable)
--   item_number     -- SKU / item code
--   qty_requested   -- cases ordered
--   qty_shipped     -- cases actually shipped
--   order_date      -- order entry date (nullable; needed for lead time)
--   requested_date  -- requested pick-up / delivery date (nullable)
--   actual_date     -- actual pick-up / delivery date (nullable)
--
-- Keep it read-only (SELECT). Add a WHERE clause to limit the date window.
-- ===========================================================================

SELECT
    CAST(d.DeliveryLineId AS VARCHAR(128)) AS source_key,
    d.ShipDate                             AS ship_date,
    d.PoNumber                             AS po_number,
    c.CustomerName                         AS customer,
    d.ShipVia                              AS ship_via,
    d.ItemNumber                           AS item_number,
    d.QtyOrdered                           AS qty_requested,
    d.QtyShipped                           AS qty_shipped,
    d.OrderDate                            AS order_date,
    d.RequestedDate                        AS requested_date,
    d.ActualDate                           AS actual_date
FROM dbo.DeliveryDetail AS d
LEFT JOIN dbo.Customer  AS c ON c.CustomerId = d.CustomerId
WHERE d.ShipDate >= DATEADD(DAY, -30, CAST(GETDATE() AS DATE));
