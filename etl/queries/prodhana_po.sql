-- ===========================================================================
-- PRODHANA (SAP Business One on HANA) -> po_lines source query (SCRUM-88).
--
-- One row per PURCHASE ORDER LINE (POR1), with received quantity and last
-- receipt date rolled up from the linked Goods Receipt POs (PDN1, BaseType 22).
-- Output column names match po_lines exactly. READ-ONLY (SELECT).
--
-- Key SAP tables:
--   OPOR  purchase-order header   POR1  purchase-order lines
--   OPDN  goods receipt PO header PDN1  goods receipt PO lines (actual receipts)
--   OCRD  business partners       OWHS  warehouses
--
-- Run via the SQL Server linked-server bridge (same as the delivery pull):
--   php etl/pull_po.php --source=PRIMSBM --query=etl/queries/prodhana_po.sql --via=PRODHANA
-- ===========================================================================

SELECT
    TO_VARCHAR(T1."DocEntry") || '-' || TO_VARCHAR(T1."LineNum")      AS source_key,
    TO_VARCHAR(T0."DocNum")                                          AS po_number,
    CASE WHEN T0."CANCELED" = 'Y' THEN 'Cancelled'
         WHEN T0."DocStatus" = 'O' THEN 'Open'
         WHEN T0."DocStatus" = 'C' THEN 'Closed'
         ELSE T0."DocStatus" END                                     AS po_status,
    T0."DocDate"                                                     AS posting_date,
    -- Promised date: line ship date (POR1.ShipDate) falling back to the header
    -- due date — same precedence as the sales-side OTIF (SCRUM-86).
    COALESCE(T1."ShipDate", T0."DocDueDate")                         AS due_date,
    -- Actual receipt: last linked Goods Receipt PO posting date for this line.
    (SELECT MAX(D1."DocDate")
     FROM "DAMASCUS_BAKERY"."PDN1" D1
     WHERE D1."BaseType"  = 22               -- 22 = Purchase Order
       AND D1."BaseEntry" = T1."DocEntry"
       AND D1."BaseLine"  = T1."LineNum")                            AS receipt_date,
    T0."CardCode"                                                    AS supplier_code,
    T0."CardName"                                                    AS supplier_name,
    T1."ItemCode"                                                    AS item_code,
    T1."Dscription"                                                  AS item_description,
    COALESCE(NULLIF(TRIM(WH."WhsName"), ''), T1."WhsCode")           AS warehouse,
    T1."Quantity"                                                    AS order_qty,
    COALESCE((SELECT SUM(D1."Quantity")
     FROM "DAMASCUS_BAKERY"."PDN1" D1
     WHERE D1."BaseType"  = 22
       AND D1."BaseEntry" = T1."DocEntry"
       AND D1."BaseLine"  = T1."LineNum"), 0)                        AS received_qty,
    COALESCE(T1."LineTotal", 0)                                      AS line_amount,
    T1."unitMsr"                                                     AS unit_of_measure

FROM "DAMASCUS_BAKERY"."OPOR" T0
    INNER JOIN "DAMASCUS_BAKERY"."POR1" T1 ON T1."DocEntry" = T0."DocEntry"
    LEFT  JOIN "DAMASCUS_BAKERY"."OWHS" WH ON TRIM(WH."WhsCode") = TRIM(T1."WhsCode")
-- Full, month-aligned 12-month window (same as prodhana_delivery.sql).
WHERE T0."DocDate" >= ADD_MONTHS(ADD_DAYS(CURRENT_DATE, 1 - DAYOFMONTH(CURRENT_DATE)), -12)
ORDER BY T0."DocDate", T0."DocNum", T1."LineNum";
