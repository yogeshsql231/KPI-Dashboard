-- ===========================================================================
-- PRIMSBM (Microsoft SQL Server) -> lpn_pallets source query.
--
-- LPN = License Plate Number: the pallet/container "license plate" the WMS
-- assigns to a physical unit. Damascus Bakery runs Beas Manufacturing / WMS on
-- SAP B1, so the LPN detail lives in the Beas pallet-master tables. The most
-- common source is @BMM_PALLETMASTER (pallet header) joined to its bin/stock
-- detail. READ-ONLY (SELECT) — never writes.
--
-- IMPORTANT: the @BMM_* column names below are the BEST-GUESS Beas names and
-- MUST be confirmed against your database — run etl/queries/lpn_discover_sqlsrv.sql
-- first to see the real table/column names, then adjust the right-hand side of
-- each "AS" mapping. The left side (source_key ... expiry_date) must stay as-is.
--
-- If your box exposes a ready-made pallet/LPN cache or view, point this query
-- at that instead (simplest + most accurate).
-- ===========================================================================

SELECT
    -- Unique, stable key per LPN row. Prefer the pallet code; combine with the
    -- item/batch when one physical LPN can hold multiple item lines.
    CAST(P."U_PalletNo" AS VARCHAR(120))                       AS source_key,
    P."U_PalletNo"                                             AS lpn,
    P."U_Status"                                               AS status,
    COALESCE(WH."WhsName", P."U_WhsCode")                      AS warehouse,
    P."U_BinCode"                                              AS bin_location,
    P."U_ItemCode"                                             AS item_code,
    OI."ItemName"                                              AS item_description,
    CASE
        WHEN LOWER(COALESCE(G."ItmsGrpNam", '')) LIKE '%raw%'
          OR LOWER(COALESCE(G."ItmsGrpNam", '')) LIKE '%ingredient%' THEN 'Raw'
        WHEN LOWER(COALESCE(G."ItmsGrpNam", '')) LIKE '%finish%'
          OR LOWER(COALESCE(G."ItmsGrpNam", '')) LIKE '%fg%'
          OR UPPER(COALESCE(WH."WhsName", P."U_WhsCode", '')) LIKE 'FG%' THEN 'Finished'
        ELSE 'Other'
    END                                                        AS item_type,
    P."U_BatchNo"                                              AS batch_number,
    P."U_Quantity"                                             AS quantity,
    P."U_Quantity" * COALESCE(OI."AvgPrice", 0)                AS pallet_value,
    P."U_UoM"                                                  AS unit_of_measure,
    P."U_RecvDate"                                             AS received_date,
    P."U_ExpDate"                                              AS expiry_date
FROM "@BMM_PALLETMASTER" P
    LEFT JOIN OWHS WH ON WH."WhsCode" = P."U_WhsCode"
    LEFT JOIN OITM OI ON OI."ItemCode" = P."U_ItemCode"
    LEFT JOIN OITB G ON G."ItmsGrpCod" = OI."ItmsGrpCod"
-- Only pallets that still exist / are relevant to operations; adjust as needed.
WHERE (P."U_Status" IS NULL OR P."U_Status" <> 'Closed')
ORDER BY P."U_PalletNo";
