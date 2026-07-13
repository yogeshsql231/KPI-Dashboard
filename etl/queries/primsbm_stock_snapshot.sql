-- ===========================================================================
-- PRIMSBM (SQL Server, SAP B1) -> inventory_stock_snapshots source query.
--
-- Daily on-hand snapshot per item x warehouse for the Stockout Frequency KPI
-- (SCRUM-93). Unlike primsbm_stock.sql this KEEPS zero-on-hand rows so a
-- stockout (on-hand = 0) can actually be observed, and it emits is_active from
-- SAP item validity so discontinued/inactive SKUs can be excluded from the
-- denominator. snapshot_date is set by the ETL (etl/pull_stock_snapshot.php),
-- not here. READ-ONLY (SELECT). Verify table/column names with
-- etl/queries/inventory_discover_sqlsrv.sql before first run.
-- ===========================================================================

SELECT
    W."ItemCode"                                        AS item_code,
    I."ItemName"                                        AS item_description,
    COALESCE(WH."WhsName", W."WhsCode")                 AS warehouse,
    W."OnHand"                                          AS on_hand,
    -- Active SKU = sellable and not frozen/discontinued. frozenFor = 'Y' means
    -- the item is frozen (discontinued); validFor = 'N' means inactive.
    CASE
        WHEN I."validFor" = 'N' OR I."frozenFor" = 'Y' THEN 0
        ELSE 1
    END                                                 AS is_active,
    CASE
        WHEN UPPER(G."ItmsGrpNam") LIKE '%FROZEN%' THEN 'Frozen'
        WHEN UPPER(G."ItmsGrpNam") LIKE '%FRESH%'  THEN 'Fresh'
        WHEN UPPER(G."ItmsGrpNam") LIKE '%DRY%'    THEN 'Dry'
        ELSE NULL
    END                                                 AS product_type,
    G."ItmsGrpNam"                                      AS category
FROM OITW W
    INNER JOIN OITM I ON I."ItemCode" = W."ItemCode"
    LEFT JOIN OITB G ON G."ItmsGrpCod" = I."ItmsGrpCod"
    LEFT JOIN OWHS WH ON WH."WhsCode" = W."WhsCode"
-- Inventory-managed items only; keep zero on-hand rows (do NOT filter OnHand).
WHERE I."InvntItem" = 'Y'
ORDER BY W."WhsCode", W."ItemCode";
