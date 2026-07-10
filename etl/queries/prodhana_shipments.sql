-- ===========================================================================
-- PRODHANA (SAP Business One on HANA) -> order_shipments source query.
--
-- One row per SALES ORDER LINE (RDR1), with shipped quantity rolled up from
-- the linked delivery lines (DLN1, BaseType 17). Output column names match
-- what etl/pull_shipments.php expects, including the optional SAP order
-- fields (so_docentry, so_status, pick_status, warehouse, carrier) that the
-- Customer Service dashboard filters on. READ-ONLY (SELECT).
--
-- Window: full month-aligned 12 months (1st of the month, 12 months back,
-- through today) so every month in range is complete — same window as
-- prodhana_delivery.sql (SCRUM-45).
--
-- Key SAP tables:
--   ORDR  sales-order header      RDR1  sales-order lines
--   ODLN  delivery header         DLN1  delivery lines (actual shipped qty)
--   OWHS  warehouses              OSHP  shipping types (carrier)
-- ===========================================================================

SELECT
    TO_VARCHAR(T1."DocEntry") || '-' || TO_VARCHAR(T1."LineNum")     AS source_key,
    T0."DocDueDate"                                                  AS ship_date,
    -- Customer PO is LINE-LEVEL (RDR1."PoNum"); fall back to the header
    -- "Customer Ref. No." only when the line field is empty.
    COALESCE(NULLIF(T1."PoNum", ''), T0."NumAtCard")                 AS po_number,
    T0."CardName"                                                    AS customer,
    SH."TrnspName"                                                   AS ship_via,
    T1."ItemCode"                                                    AS item_number,
    T1."Quantity"                                                    AS qty_requested,
    -- shipped qty summed from delivery lines linked back to this SO line
    COALESCE((
        SELECT SUM(D1."Quantity")
        FROM "DAMASCUS_BAKERY"."DLN1" D1
        WHERE D1."BaseType"  = 17               -- 17 = Sales Order
          AND D1."BaseEntry" = T1."DocEntry"
          AND D1."BaseLine"  = T1."LineNum"
    ), 0)                                                            AS qty_shipped,
    T0."DocDate"                                                     AS order_date,
    COALESCE(T1."ShipDate", T0."DocDueDate")                         AS requested_date,
    -- actual date = last delivery posting for this line (NULL if unshipped)
    (
        SELECT MAX(D1."DocDate")
        FROM "DAMASCUS_BAKERY"."DLN1" D1
        WHERE D1."BaseType"  = 17
          AND D1."BaseEntry" = T1."DocEntry"
          AND D1."BaseLine"  = T1."LineNum"
    )                                                                AS actual_date,

    -- Optional SAP order fields (Customer Service dashboard filters).
    -- so_docentry carries the user-visible SO number (ORDR.DocNum) — the SO
    -- search box matches against it, and users search by DocNum.
    TO_VARCHAR(T0."DocNum")                                          AS so_docentry,
    CASE WHEN T0."CANCELED" = 'Y' THEN 'Cancelled'
         WHEN T0."DocStatus" = 'O' THEN 'Open'
         WHEN T0."DocStatus" = 'C' THEN 'Closed'
         ELSE T0."DocStatus" END                                     AS so_status,
    CASE
        WHEN COALESCE((SELECT SUM(D1."Quantity") FROM "DAMASCUS_BAKERY"."DLN1" D1
              WHERE D1."BaseType" = 17 AND D1."BaseEntry" = T1."DocEntry"
                AND D1."BaseLine" = T1."LineNum"), 0) > 0 THEN 'Delivered'
        WHEN T1."PickStatus" = 'Y' THEN 'Picked'
        WHEN T1."PickStatus" = 'R' THEN 'Released'
        ELSE 'Not Picked'
    END                                                              AS pick_status,
    -- Prefer the warehouse NAME; fall back to the code when the name is blank.
    COALESCE(NULLIF(TRIM(WH."WhsName"), ''), T1."WhsCode")           AS warehouse,
    SH."TrnspName"                                                   AS carrier

FROM "DAMASCUS_BAKERY"."ORDR" T0
INNER JOIN "DAMASCUS_BAKERY"."RDR1" T1 ON T1."DocEntry" = T0."DocEntry"
LEFT JOIN "DAMASCUS_BAKERY"."OWHS" WH ON TRIM(WH."WhsCode") = TRIM(T1."WhsCode")
LEFT JOIN "DAMASCUS_BAKERY"."OSHP" SH ON SH."TrnspCode" = T0."TrnspCode"
WHERE T0."DocDueDate" >= ADD_MONTHS(ADD_DAYS(CURRENT_DATE, 1 - DAYOFMONTH(CURRENT_DATE)), -12)
ORDER BY T0."DocDueDate", T0."DocNum", T1."LineNum";
