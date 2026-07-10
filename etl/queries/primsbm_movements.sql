-- ===========================================================================
-- PRIMSBM (SQL Server, SAP B1) -> material_movements source query.
--
-- Stage flow (Warehouse -> Staging -> Production -> Waste) reconstructed from
-- the OINM inventory journal. movement_type must come out as one of:
--   receipt  - goods receipt into a warehouse (PO receipt / production output)
--   transfer - stock transfer (e.g. raw warehouse -> STAGING)
--   issue    - goods issue to production (consumption)
--   waste    - issue into the scrap/waste warehouse
--
-- IMPORTANT: confirm your waste warehouse code(s) and which TransType values
-- your processes use (Beas may post consumption differently). Verify columns
-- with etl/queries/inventory_discover_sqlsrv.sql. READ-ONLY (SELECT).
-- SAP B1 TransType reference: 20=Goods Receipt PO, 59=Goods Receipt,
-- 60=Goods Issue, 67=Stock Transfer.
-- ===========================================================================

SELECT
    CAST(N."TransType" AS VARCHAR(20)) + '|' + CAST(N."CreatedBy" AS VARCHAR(30))
        + '|' + CAST(N."TransNum" AS VARCHAR(30))       AS source_key,
    CASE
        WHEN N."Warehouse" IN ('WASTE', 'SCRAP')        THEN 'waste'   -- CONFIRM waste whs codes
        WHEN N."TransType" IN (20, 59) AND N."InQty" > 0  THEN 'receipt'
        WHEN N."TransType" = 67                          THEN 'transfer'
        WHEN N."TransType" = 60 AND N."OutQty" > 0       THEN 'issue'
        ELSE 'other'                                     -- skipped by the ETL
    END                                                 AS movement_type,
    N."DocDate"                                         AS doc_date,
    N."ItemCode"                                        AS item_code,
    I."ItemName"                                        AS item_description,
    CASE WHEN N."OutQty" > 0 THEN N."Warehouse" END     AS from_warehouse,
    CASE WHEN N."InQty"  > 0 THEN N."Warehouse" END     AS to_warehouse,
    CASE WHEN N."InQty" > 0 THEN N."InQty" ELSE N."OutQty" END AS quantity,
    I."InvntryUom"                                      AS unit_of_measure
FROM OINM N
    INNER JOIN OITM I ON I."ItemCode" = N."ItemCode"
WHERE N."DocDate" >= DATEADD(MONTH, -12, CAST(GETDATE() AS DATE))
  AND N."TransType" IN (20, 59, 60, 67)
ORDER BY N."DocDate", N."TransNum";
