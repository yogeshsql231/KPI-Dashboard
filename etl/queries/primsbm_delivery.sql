-- ===========================================================================
-- PRIMSBM (Microsoft SQL Server) -> delivery_lines source query.
--
-- Your operations dashboard already materialises the delivery scorecard into
-- dbo.KPI_DeliveryDashboardCache (refreshed from SAP HANA). The simplest,
-- most accurate feed is to read that cache directly and alias its columns to
-- the delivery_lines names below. READ-ONLY (SELECT) — never writes.
--
-- If the cache column names differ on your box, adjust the right-hand side of
-- each "AS" mapping; the left side (source_key ... carrier) must stay as-is.
-- If you would rather build from raw SAP tables, use the ORDR/RDR1/DLN1 join
-- in prodhana_delivery.sql instead.
-- ===========================================================================

SELECT
    CAST(SalesOrder AS VARCHAR(50)) + '-' + CAST(ItemCode AS VARCHAR(50)) AS source_key,
    SalesOrder        AS sales_order,
    SO_Status         AS so_status,
    PostingDate       AS posting_date,
    ShipDate          AS ship_date,
    RequiredDate      AS required_date,
    CustomerCode      AS customer_code,
    CustomerName      AS customer_name,
    PONumber          AS po_number,
    ItemCode          AS item_code,
    ItemDescription   AS item_description,
    Warehouse         AS warehouse,
    OrderQty          AS order_qty,
    QtyPallet         AS qty_pallet,
    QtyPerPack        AS qty_per_pack,
    UnitOfMeasure     AS unit_of_measure,
    ReleasedQty       AS released_qty,
    DeliveredQty      AS delivered_qty,
    PickQty           AS pick_qty,
    PickStatus        AS pick_status,
    Approved          AS approved,
    ShortShipment     AS short_shipment,
    LateShipment      AS late_shipment,
    CompleteShipment  AS complete_shipment,
    OTIF              AS otif,
    FillRate          AS fill_rate,
    ManualBOL         AS manual_bol,
    Carrier           AS carrier
FROM dbo.KPI_DeliveryDashboardCache
WHERE PostingDate >= DATEADD(DAY, -60, CAST(GETDATE() AS DATE));
