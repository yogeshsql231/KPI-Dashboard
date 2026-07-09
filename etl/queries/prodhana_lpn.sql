-- ===========================================================================
-- PRODHANA (SAP Business One on HANA) -> lpn_pallets source query.
--
-- LPN = License Plate Number (pallet "license plate"). Beas Manufacturing / WMS
-- stores pallet detail in "@BMM_PALLETMASTER" (and bin detail in
-- "@BMM_BINDETAIL"). Output column names match lpn_pallets exactly so the ETL
-- upserts straight in. READ-ONLY (SELECT) — never writes to SAP.
--
-- IMPORTANT: the @BMM_* / U_* column names below are BEST-GUESS Beas names and
-- MUST be confirmed against your schema. Run etl/queries/lpn_discover_hana.sql
-- first to list the real columns, then adjust the right-hand side of each "AS".
-- Replace "DAMASCUS_BAKERY" with your company schema if different.
-- ===========================================================================

SELECT
    TO_VARCHAR(P."U_PalletNo")                                 AS source_key,
    P."U_PalletNo"                                             AS lpn,
    P."U_Status"                                               AS status,
    COALESCE(WH."WhsName", P."U_WhsCode")                      AS warehouse,
    P."U_BinCode"                                              AS bin_location,
    P."U_ItemCode"                                             AS item_code,
    OI."ItemName"                                              AS item_description,
    P."U_BatchNo"                                              AS batch_number,
    P."U_Quantity"                                             AS quantity,
    P."U_UoM"                                                  AS unit_of_measure,
    P."U_RecvDate"                                             AS received_date,
    P."U_ExpDate"                                              AS expiry_date
FROM "DAMASCUS_BAKERY"."@BMM_PALLETMASTER" P
    LEFT JOIN "DAMASCUS_BAKERY"."OWHS" WH ON WH."WhsCode" = P."U_WhsCode"
    LEFT JOIN "DAMASCUS_BAKERY"."OITM" OI ON OI."ItemCode" = P."U_ItemCode"
WHERE (P."U_Status" IS NULL OR P."U_Status" <> 'Closed')
ORDER BY P."U_PalletNo";
