-- ===========================================================================
-- PRIMSBM (SQL Server, SAP B1) -> production_usage source query (SCRUM-65).
--
-- Estimated vs actual production stock usage: one row per production order
-- component line. planned_qty = what the order said it would consume
-- (WOR1.PlannedQty); actual_qty = what was actually issued (WOR1.IssuedQty).
-- Released + closed orders over the last 12 months. If Beas posts consumption
-- outside OWOR/WOR1, point this at the Beas tables instead. READ-ONLY.
-- ===========================================================================

SELECT
    CAST(W."DocEntry" AS VARCHAR(30)) + '|' + CAST(L."LineNum" AS VARCHAR(10)) AS source_key,
    CAST(W."DocNum" AS VARCHAR(30))                     AS production_order,
    W."PostDate"                                        AS doc_date,
    L."ItemCode"                                        AS item_code,
    I."ItemName"                                        AS item_description,
    L."wareHouse"                                       AS warehouse,
    L."PlannedQty"                                      AS planned_qty,
    L."IssuedQty"                                       AS actual_qty,
    I."InvntryUom"                                      AS unit_of_measure
FROM OWOR W
    INNER JOIN WOR1 L ON L."DocEntry" = W."DocEntry"
    LEFT JOIN OITM I ON I."ItemCode" = L."ItemCode"
WHERE W."PostDate" >= DATEADD(MONTH, -12, CAST(GETDATE() AS DATE))
  AND W."Status" IN ('R', 'L')          -- Released / Closed
ORDER BY W."PostDate", W."DocNum", L."LineNum";
