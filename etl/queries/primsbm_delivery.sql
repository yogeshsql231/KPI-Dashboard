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
    -- Actual shipment date (last delivery-note date). Map to the cache column
    -- if your box has one (e.g. DeliveredDate); otherwise the prodhana query
    -- computes it from DLN1 and this feed leaves cycle time unavailable.
    CAST(NULL AS DATE) AS delivery_date,
    CustomerCode      AS customer_code,
    CustomerName      AS customer_name,
    -- v2 fields: map to the cache columns if they exist, else keep the NULL/0
    -- defaults (dashboard shows counts/qty live and $/pallet once mapped).
    CAST(NULL AS VARCHAR(100)) AS customer_group,
    CAST(0 AS INT)            AS is_retail,
    PONumber          AS po_number,
    ItemCode          AS item_code,
    ItemDescription   AS item_description,
    Warehouse         AS warehouse,
    OrderQty          AS order_qty,
    QtyPallet         AS qty_pallet,
    QtyPerPack        AS qty_per_pack,
    CAST(NULL AS DECIMAL(18,4)) AS qty_per_pallet,
    UnitOfMeasure     AS unit_of_measure,
    ReleasedQty       AS released_qty,
    DeliveredQty      AS delivered_qty,
    CAST(NULL AS DECIMAL(18,4)) AS line_amount,
    CAST(NULL AS DECIMAL(18,4)) AS delivered_amount,
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
-- SCRUM-45: pull a full, month-aligned 12-month window (from the 1st of the
-- month, 12 months back, through today) so every month in range is complete
-- and history before the current month loads on the dashboard. (Was a rolling
-- 60-day window, which left only ~2 months of data in the cache.) Matches the
-- window used by prodhana_payments.sql.
WHERE PostingDate >= DATEADD(MONTH, -12, DATEADD(DAY, 1 - DAY(CAST(GETDATE() AS DATE)), CAST(GETDATE() AS DATE)));
