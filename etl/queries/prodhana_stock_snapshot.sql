-- ===========================================================================
-- PRODHANA (SAP Business One on HANA) -> inventory_stock_snapshots source query.
--
-- HANA variant of primsbm_stock_snapshot.sql: daily on-hand snapshot per item
-- x warehouse for the Stockout Frequency KPI (SCRUM-93). KEEPS zero-on-hand
-- rows (so a stockout can be observed) and emits is_active from SAP item
-- validity. snapshot_date is set by the ETL, not here.
--
-- Run through the PRODHANA linked-server bridge (same as prodhana_lpn.sql):
--   php etl/pull_stock_snapshot.php --source=PRIMSBM \
--       --query=etl/queries/prodhana_stock_snapshot.sql --via=PRODHANA
--
-- Adjust the schema name ("DAMASCUS_BAKERY") to match your company database.
-- READ-ONLY (SELECT) — never writes to SAP.
-- ===========================================================================

SELECT
    W."ItemCode"                                        AS item_code,
    I."ItemName"                                        AS item_description,
    COALESCE(WH."WhsName", W."WhsCode")                 AS warehouse,
    W."OnHand"                                          AS on_hand,
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
FROM "DAMASCUS_BAKERY"."OITW" W
    INNER JOIN "DAMASCUS_BAKERY"."OITM" I ON I."ItemCode" = W."ItemCode"
    LEFT JOIN "DAMASCUS_BAKERY"."OITB" G ON G."ItmsGrpCod" = I."ItmsGrpCod"
    LEFT JOIN "DAMASCUS_BAKERY"."OWHS" WH ON WH."WhsCode" = W."WhsCode"
WHERE I."InvntItem" = 'Y'
ORDER BY W."WhsCode", W."ItemCode";
