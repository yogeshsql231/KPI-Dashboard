-- ===========================================================================
-- PRIMSBM (Microsoft SQL Server) -> order_shipments source query.
--
-- Reads the pre-built delivery scorecard cache (dbo.KPI_DeliveryDashboardCache,
-- refreshed from SAP HANA) and aliases its columns to the output names that
-- etl/pull_shipments.php expects, including the optional SAP order fields
-- (so_docentry, so_status, pick_status, warehouse, carrier) the Customer
-- Service dashboard filters on. READ-ONLY (SELECT).
--
-- If a cache column name differs on your box, adjust the right-hand side of
-- the "AS" mapping; the output aliases must stay as-is. To build from raw SAP
-- tables instead, use the ORDR/RDR1/DLN1 join in prodhana_shipments.sql.
--
-- Window: full month-aligned 12 months (1st of the month, 12 months back,
-- through today) — same window as primsbm_delivery.sql (SCRUM-45).
-- ===========================================================================

SELECT
    CAST(SalesOrder AS VARCHAR(50)) + '-' + CAST(ItemCode AS VARCHAR(50)) AS source_key,
    ShipDate          AS ship_date,
    PONumber          AS po_number,
    CustomerName      AS customer,
    Carrier           AS ship_via,
    ItemCode          AS item_number,
    OrderQty          AS qty_requested,
    DeliveredQty      AS qty_shipped,
    PostingDate       AS order_date,
    RequiredDate      AS requested_date,
    ShipDate          AS actual_date,

    -- Optional SAP order fields (Customer Service dashboard filters).
    -- so_docentry carries the user-visible SO number — the SO search box
    -- matches against it.
    CAST(SalesOrder AS VARCHAR(32)) AS so_docentry,
    SO_Status         AS so_status,
    PickStatus        AS pick_status,
    Warehouse         AS warehouse,
    Carrier           AS carrier
FROM dbo.KPI_DeliveryDashboardCache
WHERE PostingDate >= DATEADD(MONTH, -12, DATEADD(DAY, 1 - DAY(CAST(GETDATE() AS DATE)), CAST(GETDATE() AS DATE)));
