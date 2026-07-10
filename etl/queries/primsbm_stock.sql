-- ===========================================================================
-- PRIMSBM (SQL Server, SAP B1) -> warehouse_stock source query.
--
-- On-hand stock per item x warehouse from OITW (item-per-warehouse) joined to
-- OITM for the description/UoM. Pallets are derived from the case-per-pallet
-- factor when available (adjust to your real UDFs if Beas stores it).
-- READ-ONLY (SELECT). Verify table/column names with
-- etl/queries/inventory_discover_sqlsrv.sql before first run.
-- ===========================================================================

SELECT
    CAST(W."ItemCode" AS VARCHAR(100)) + '|' + CAST(W."WhsCode" AS VARCHAR(50)) AS source_key,
    W."ItemCode"                                        AS item_code,
    I."ItemName"                                        AS item_description,
    COALESCE(WH."WhsName", W."WhsCode")                 AS warehouse,
    W."OnHand"                                          AS on_hand,
    W."IsCommited"                                      AS committed,
    W."OnOrder"                                         AS on_order,
    I."InvntryUom"                                      AS unit_of_measure,
    -- Pallet estimate: on-hand / units-per-pallet when the sales packaging
    -- factors are maintained; NULL otherwise (panel shows qty only).
    CASE
        WHEN I."SalPackUn" IS NOT NULL AND I."SalPackUn" > 0
        THEN W."OnHand" / I."SalPackUn"
        ELSE NULL
    END                                                 AS pallets,
    -- Product type (Fresh / Frozen / Dry) from the SAP item-group name;
    -- unmatched groups stay NULL and show as "Unassigned" in the split view.
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
WHERE W."OnHand" <> 0
ORDER BY W."WhsCode", W."ItemCode";
