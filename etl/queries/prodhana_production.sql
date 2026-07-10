-- ===========================================================================
-- PRODHANA (SAP HANA, schema DAMASCUS_BAKERY) -> production_usage (SCRUM-65).
--
-- Same shape as primsbm_production.sql but in HANA syntax, suitable for
-- --via=PRODHANA (OPENQUERY passthrough): planned vs actually-issued component
-- quantities per production order line, last 12 months. READ-ONLY.
-- ===========================================================================

SELECT
    TO_VARCHAR(W."DocEntry") || '|' || TO_VARCHAR(L."LineNum") AS "source_key",
    TO_VARCHAR(W."DocNum")                              AS "production_order",
    W."PostDate"                                        AS "doc_date",
    L."ItemCode"                                        AS "item_code",
    I."ItemName"                                        AS "item_description",
    L."wareHouse"                                       AS "warehouse",
    L."PlannedQty"                                      AS "planned_qty",
    L."IssuedQty"                                       AS "actual_qty",
    I."InvntryUom"                                      AS "unit_of_measure"
FROM "DAMASCUS_BAKERY"."OWOR" W
    INNER JOIN "DAMASCUS_BAKERY"."WOR1" L ON L."DocEntry" = W."DocEntry"
    LEFT JOIN "DAMASCUS_BAKERY"."OITM" I ON I."ItemCode" = L."ItemCode"
WHERE W."PostDate" >= ADD_MONTHS(CURRENT_DATE, -12)
  AND W."Status" IN ('R', 'L')
ORDER BY W."PostDate", W."DocNum", L."LineNum";
