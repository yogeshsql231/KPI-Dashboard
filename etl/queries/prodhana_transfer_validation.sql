-- ===========================================================================
-- PRODHANA (SAP Business One on HANA) -> transfer-record validation (SCRUM-23).
--
-- Flags incomplete / implausible stock-transfer records (OWTR header,
-- WTR1 lines) at the SAP source so they can be reviewed in SAP B1.
-- One row per finding. READ-ONLY (SELECT) — never writes to SAP.
--
-- Checks:
--   SAME_WAREHOUSE     transfer line whose from and to warehouse are identical
--   BAD_QUANTITY       transfer line with a zero or negative quantity
--   UNKNOWN_ITEM       transfer line whose item code is not in OITM
--   OPEN_OVER_7_DAYS   transfer document still open more than 7 days after DocDate
--
-- Run through the SQL Server linked server:
--   php etl/query.php --source=PRODHANA --query=etl/queries/prodhana_transfer_validation.sql --via=PRODHANA
-- ===========================================================================

SELECT 'SAME_WAREHOUSE' AS issue, T."DocNum" AS doc_num, L."LineNum" AS line_num,
       L."ItemCode" AS item_code, L."FromWhsCod" AS from_whs, L."WhsCode" AS to_whs,
       L."Quantity" AS quantity, T."DocDate" AS doc_date
FROM "DAMASCUS_BAKERY"."OWTR" T
    INNER JOIN "DAMASCUS_BAKERY"."WTR1" L ON L."DocEntry" = T."DocEntry"
WHERE T."CANCELED" = 'N' AND L."FromWhsCod" = L."WhsCode"

UNION ALL

SELECT 'BAD_QUANTITY', T."DocNum", L."LineNum",
       L."ItemCode", L."FromWhsCod", L."WhsCode", L."Quantity", T."DocDate"
FROM "DAMASCUS_BAKERY"."OWTR" T
    INNER JOIN "DAMASCUS_BAKERY"."WTR1" L ON L."DocEntry" = T."DocEntry"
WHERE T."CANCELED" = 'N' AND (L."Quantity" IS NULL OR L."Quantity" <= 0)

UNION ALL

SELECT 'UNKNOWN_ITEM', T."DocNum", L."LineNum",
       L."ItemCode", L."FromWhsCod", L."WhsCode", L."Quantity", T."DocDate"
FROM "DAMASCUS_BAKERY"."OWTR" T
    INNER JOIN "DAMASCUS_BAKERY"."WTR1" L ON L."DocEntry" = T."DocEntry"
WHERE T."CANCELED" = 'N'
  AND NOT EXISTS (SELECT 1 FROM "DAMASCUS_BAKERY"."OITM" I
                  WHERE I."ItemCode" = L."ItemCode")

UNION ALL

SELECT 'OPEN_OVER_7_DAYS', T."DocNum", CAST(NULL AS INT),
       CAST(NULL AS NVARCHAR(64)), T."Filler", T."ToWhsCode",
       CAST(NULL AS DECIMAL(19,6)), T."DocDate"
FROM "DAMASCUS_BAKERY"."OWTR" T
WHERE T."CANCELED" = 'N' AND T."DocStatus" = 'O'
  AND DAYS_BETWEEN(T."DocDate", CURRENT_DATE) > 7

ORDER BY 1, 2, 3
