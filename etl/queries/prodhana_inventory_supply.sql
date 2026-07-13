-- ===========================================================================
-- PRODHANA (SAP Business One on HANA) -> inventory_supply source query.
--
-- Inventory Days of Supply (SCRUM-92) + Slow/Obsolete Inventory (SCRUM-91):
-- one row per active inventory item x warehouse with current on-hand (OITW),
-- trailing 30/90-day outbound usage and last outbound movement date from the
-- inventory journal (OINM.OutQty — covers deliveries, issues to production,
-- transfers out, etc.).
--
-- Run through the PRODHANA linked-server bridge (same as prodhana_po.sql):
--   php etl/pull_inventory_supply.php --source=PRIMSBM \
--       --query=etl/queries/prodhana_inventory_supply.sql --via=PRODHANA
--
-- Adjust the schema name ("DAMASCUS_BAKERY") to match your company database.
-- READ-ONLY (SELECT) — never writes to SAP.
-- ===========================================================================

SELECT
    W."ItemCode" || '|' || W."WhsCode"                  AS source_key,
    W."ItemCode"                                        AS item_code,
    I."ItemName"                                        AS item_description,
    G."ItmsGrpNam"                                      AS category,
    COALESCE(NULLIF(TRIM(WH."WhsName"), ''), W."WhsCode") AS warehouse,
    W."OnHand"                                          AS on_hand,
    COALESCE((SELECT SUM(M."OutQty")
     FROM "DAMASCUS_BAKERY"."OINM" M
     WHERE M."ItemCode" = W."ItemCode"
       AND M."Warehouse" = W."WhsCode"
       AND M."OutQty" > 0
       AND M."DocDate" >= ADD_DAYS(CURRENT_DATE, -30)), 0) AS usage_qty_30d,
    COALESCE((SELECT SUM(M."OutQty")
     FROM "DAMASCUS_BAKERY"."OINM" M
     WHERE M."ItemCode" = W."ItemCode"
       AND M."Warehouse" = W."WhsCode"
       AND M."OutQty" > 0
       AND M."DocDate" >= ADD_DAYS(CURRENT_DATE, -90)), 0) AS usage_qty_90d,
    (SELECT MAX(M."DocDate")
     FROM "DAMASCUS_BAKERY"."OINM" M
     WHERE M."ItemCode" = W."ItemCode"
       AND M."Warehouse" = W."WhsCode"
       AND M."OutQty" > 0)                              AS last_movement,
    CASE
        WHEN I."validFor" = 'N' OR I."frozenFor" = 'Y' THEN 0
        ELSE 1
    END                                                 AS is_active,
    I."CreateDate"                                      AS item_created,
    I."InvntryUom"                                      AS unit_of_measure
FROM "DAMASCUS_BAKERY"."OITW" W
    INNER JOIN "DAMASCUS_BAKERY"."OITM" I ON I."ItemCode" = W."ItemCode"
    LEFT JOIN "DAMASCUS_BAKERY"."OITB" G ON G."ItmsGrpCod" = I."ItmsGrpCod"
    LEFT JOIN "DAMASCUS_BAKERY"."OWHS" WH ON WH."WhsCode" = W."WhsCode"
WHERE I."InvntItem" = 'Y'
ORDER BY W."WhsCode", W."ItemCode";
