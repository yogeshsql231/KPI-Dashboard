-- ===========================================================================
-- PRODHANA (SAP Business One on HANA) -> lpn_pallets source query.
--
-- LPN = License Plate Number (pallet "license plate"). Beas WMS stores the
-- pallet header in "@BMM_PALLETMASTER" and the pallet contents (item / lot /
-- quantity per bin) in "@BMM_BINDETAIL", linked on
-- BINDETAIL."U_SCCNO" = PALLETMASTER."U_BMPALLETID" (verified against the
-- live DAMASCUS_BAKERY schema). Output column names match lpn_pallets exactly
-- so the ETL upserts straight in. READ-ONLY (SELECT) — never writes to SAP.
--
-- Run through the SQL Server linked server (no direct HANA login needed):
--   php etl/pull_lpn.php --source=PRIMSBM --query=etl/queries/prodhana_lpn.sql --via=PRODHANA
-- ===========================================================================

SELECT
    P."U_BMPALLETID" || ':' || COALESCE(B."U_ITEMCODE", '') || ':' || COALESCE(B."U_LOTNO", '') || ':' || COALESCE(B."U_BINNO", '') AS source_key,
    P."U_BMPALLETID"                                           AS lpn,
    P."U_BMSTATUS"                                             AS status,
    COALESCE(WH."WhsName", B."U_WHSCODE", P."U_BMLOCATION")    AS warehouse,
    COALESCE(B."U_BINNO", P."U_BMBINNO")                       AS bin_location,
    B."U_ITEMCODE"                                             AS item_code,
    OI."ItemName"                                              AS item_description,
    COALESCE(B."U_LOTNO", P."U_BATCHNO")                       AS batch_number,
    SUM(B."U_TOTALQTY")                                        AS quantity,
    OI."InvntryUom"                                            AS unit_of_measure,
    P."U_INDATE"                                               AS received_date,
    CAST(NULL AS DATE)                                         AS expiry_date
FROM "DAMASCUS_BAKERY"."@BMM_PALLETMASTER" P
    LEFT JOIN "DAMASCUS_BAKERY"."@BMM_BINDETAIL" B ON B."U_SCCNO" = P."U_BMPALLETID"
    LEFT JOIN "DAMASCUS_BAKERY"."OWHS" WH ON WH."WhsCode" = COALESCE(B."U_WHSCODE", P."U_BMLOCATION")
    LEFT JOIN "DAMASCUS_BAKERY"."OITM" OI ON OI."ItemCode" = B."U_ITEMCODE"
WHERE COALESCE(P."Canceled", 'N') <> 'Y'
GROUP BY
    P."U_BMPALLETID", P."U_BMSTATUS",
    COALESCE(WH."WhsName", B."U_WHSCODE", P."U_BMLOCATION"),
    COALESCE(B."U_BINNO", P."U_BMBINNO"),
    B."U_ITEMCODE", OI."ItemName",
    COALESCE(B."U_LOTNO", P."U_BATCHNO"),
    OI."InvntryUom", P."U_INDATE"
ORDER BY P."U_BMPALLETID"
