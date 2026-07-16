-- ===========================================================================
-- PRODHANA (SAP Business One on HANA) -> lpn_pallets source query.
--
-- LPN = License Plate Number (pallet "license plate"). Sourced from the Beas
-- WMS bin detail "@BMM_BINDETAIL" (the same source as Rajesh's
-- SP_2025BM_InventoryDetailsReport): one row per pallet (U_SCCNO) / item /
-- lot / bin with a non-zero quantity, joined to OBTN for batch create/expiry
-- dates and OITM/OITB for item description and group classification.
-- Output column names match lpn_pallets exactly so the ETL upserts straight
-- in. READ-ONLY (SELECT) — never writes to SAP.
--
-- Run through the SQL Server linked server (no direct HANA login needed):
--   php etl/pull_lpn.php --source=PRIMSBM --query=etl/queries/prodhana_lpn.sql --via=PRODHANA
-- ===========================================================================

SELECT
    B."U_SCCNO" || ':' || COALESCE(B."U_ITEMCODE", '') || ':' || COALESCE(B."U_LOTNO", '') || ':' || COALESCE(B."U_BINNO", '') AS source_key,
    TO_VARCHAR(B."U_SCCNO")                                    AS lpn,
    TO_VARCHAR(B."U_INVENTORYTYPE")                            AS status,
    TO_VARCHAR(COALESCE(WH."WhsName", B."U_WHSCODE"))          AS warehouse,
    TO_VARCHAR(B."U_BINNO")                                    AS bin_location,
    TO_VARCHAR(B."U_ITEMCODE")                                 AS item_code,
    TO_VARCHAR(OI."ItemName")                                  AS item_description,
    -- Classification by SAP item group code (101 Ingredients, 102 Package,
    -- 103 Finished Goods), falling back to the group name.
    TO_VARCHAR(CASE
        WHEN OI."ItmsGrpCod" = 101 THEN 'Raw'
        WHEN OI."ItmsGrpCod" = 102 THEN 'Packaging'
        WHEN OI."ItmsGrpCod" = 103 THEN 'Finished'
        WHEN LOWER(COALESCE(G."ItmsGrpNam", '')) LIKE '%raw%'
          OR LOWER(COALESCE(G."ItmsGrpNam", '')) LIKE '%ingredient%' THEN 'Raw'
        WHEN LOWER(COALESCE(G."ItmsGrpNam", '')) LIKE '%finish%' THEN 'Finished'
        ELSE 'Other'
    END)                                                       AS item_type,
    TO_VARCHAR(B."U_LOTNO")                                    AS batch_number,
    SUM(B."U_TOTALQTY")                                        AS quantity,
    SUM(B."U_TOTALQTY" * COALESCE(OI."AvgPrice", 0))           AS pallet_value,
    TO_VARCHAR(OI."InvntryUom")                                AS unit_of_measure,
    TO_DATE(BT."CreateDate")                                   AS received_date,
    TO_DATE(BT."ExpDate")                                      AS expiry_date
FROM "DAMASCUS_BAKERY"."@BMM_BINDETAIL" B
    LEFT JOIN "DAMASCUS_BAKERY"."OBTN" BT ON BT."ItemCode" = B."U_ITEMCODE" AND BT."DistNumber" = B."U_LOTNO"
    LEFT JOIN "DAMASCUS_BAKERY"."OWHS" WH ON WH."WhsCode" = B."U_WHSCODE"
    LEFT JOIN "DAMASCUS_BAKERY"."OITM" OI ON OI."ItemCode" = B."U_ITEMCODE"
    LEFT JOIN "DAMASCUS_BAKERY"."OITB" G ON G."ItmsGrpCod" = OI."ItmsGrpCod"
WHERE B."U_TOTALQTY" <> 0
GROUP BY
    B."U_SCCNO", B."U_INVENTORYTYPE",
    WH."WhsName", B."U_WHSCODE", B."U_BINNO",
    B."U_ITEMCODE", OI."ItemName", OI."ItmsGrpCod", G."ItmsGrpNam",
    B."U_LOTNO", OI."InvntryUom",
    BT."CreateDate", BT."ExpDate"
ORDER BY B."U_SCCNO"
