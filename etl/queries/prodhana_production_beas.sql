-- ===========================================================================
-- PRODHANA (Beas Manufacturing on HANA, schema DAMASCUS_BAKERY)
--   -> production_usage (planned vs actual per batch line).
--
-- This site records production in the Beas add-on, not standard B1 OWOR
-- (which is empty). Batch headers live in "@BMM_PNMAST" (batch no, dates,
-- production warehouse) and lines in "@BMM_PNITEM" (U_STDQTY = planned,
-- U_ACTUALQTY = actual per item).
--
--   php etl/pull_inventory.php --what=production --source=PRIMSBM \
--       --query=etl/queries/prodhana_production_beas.sql --via=PRODHANA
--
-- Last 12 months by batch entry date; cancelled batches excluded.
-- String outputs are wrapped in TO_VARCHAR and timestamps in TO_DATE: the
-- MSDASQL bridge cannot convert some HANA COALESCE/NVARCHAR result types
-- (Msg 7341 "Requested conversion is not supported").
-- READ-ONLY (SELECT).
-- ===========================================================================

SELECT
    TO_VARCHAR(I."DocEntry") || '|' || TO_VARCHAR(I."LineId") AS source_key,
    TO_VARCHAR(COALESCE(M."U_BATCHNO", TO_VARCHAR(M."DocNum"))) AS production_order,
    TO_DATE(COALESCE(M."U_ACTUALSTARTDATE", M."U_ENTRYDATE")) AS doc_date,
    TO_VARCHAR(I."U_ITEMCODE")                          AS item_code,
    TO_VARCHAR(I."U_ITEMDESC")                          AS item_description,
    TO_VARCHAR(COALESCE(I."U_WHSCODE", M."U_PRODNWHSE")) AS warehouse,
    I."U_STDQTY"                                        AS planned_qty,
    I."U_ACTUALQTY"                                     AS actual_qty,
    TO_VARCHAR(I."U_STOCKUOM")                          AS unit_of_measure
FROM "DAMASCUS_BAKERY"."@BMM_PNITEM" I
    INNER JOIN "DAMASCUS_BAKERY"."@BMM_PNMAST" M
        ON M."U_RECORDID" = I."U_PNRECORDID"
WHERE COALESCE(M."Canceled", 'N') <> 'Y'
  AND COALESCE(M."U_ENTRYDATE", M."CreateDate") >= ADD_MONTHS(CURRENT_DATE, -12)
  AND I."U_ITEMCODE" IS NOT NULL
  AND (COALESCE(I."U_STDQTY", 0) <> 0 OR COALESCE(I."U_ACTUALQTY", 0) <> 0);
