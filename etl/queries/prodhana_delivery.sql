-- ===========================================================================
-- PRODHANA (SAP Business One on HANA) -> delivery_lines source query.
--
-- One row per SALES ORDER LINE (RDR1), with delivered quantity rolled up from
-- the linked deliveries (DLN1). Output column names match delivery_lines
-- exactly, so the ETL upserts straight in. READ-ONLY (SELECT) — never writes
-- to SAP.
--
-- Adjust the schema name ("DAMASCUS_BAKERY") and any user-defined field names
-- (U_...) to match your company database. Carrier/warehouse joins are LEFT so
-- lines never drop out if a lookup is missing.
--
-- Key SAP tables:
--   ORDR  sales-order header      RDR1  sales-order lines
--   ODLN  delivery header         DLN1  delivery lines (actual delivered qty)
--   OCRD  business partners       OITM  items
--   OWHS  warehouses              OSHP  shipping types (carrier)
-- ===========================================================================

SELECT
    TO_VARCHAR(T1."DocEntry") || '-' || TO_VARCHAR(T1."LineNum")      AS source_key,
    TO_VARCHAR(T0."DocNum")                                          AS sales_order,
    -- Canceled orders are surfaced as their own status so they can be excluded
    -- from fill-rate/IFR (they never shipped by design).
    CASE WHEN T0."CANCELED" = 'Y' THEN 'Cancelled'
         WHEN T0."DocStatus" = 'O' THEN 'Open'
         WHEN T0."DocStatus" = 'C' THEN 'Closed'
         ELSE T0."DocStatus" END                                     AS so_status,
    T0."DocDate"                                                     AS posting_date,
    T0."DocDueDate"                                                  AS ship_date,
    T1."ShipDate"                                                    AS required_date,
    -- actual shipment date: LAST linked delivery-note posting date, so orders
    -- split across partial shipments measure to the final shipment. NULL until
    -- something ships (in-flight lines stay out of cycle-time averages).
    (SELECT MAX(D1."DocDate") FROM "DAMASCUS_BAKERY"."DLN1" D1
     WHERE D1."BaseType" = 17 AND D1."BaseEntry" = T1."DocEntry"
       AND D1."BaseLine" = T1."LineNum")                             AS delivery_date,
    T0."CardCode"                                                    AS customer_code,
    T0."CardName"                                                    AS customer_name,
    CG."GroupName"                                                   AS customer_group,
    -- Retail flag: exact match on the SAP customer group 'Retail'
    -- (OCRG.GroupName). Case/whitespace-insensitive.
    CASE WHEN UPPER(TRIM(COALESCE(CG."GroupName", ''))) = 'RETAIL'
         THEN 1 ELSE 0 END                                           AS is_retail,
    -- Customer PO. One SO can carry several unique POs, so the PO is taken from
    -- the ORDER LINE (RDR1."PoNum" = "Customer's Purchase Order Number") and
    -- only falls back to the header "Customer Ref. No." (ORDR.NumAtCard) when
    -- the line field is empty. (RDR1."PoItmNum" holds the customer's PO line #.)
    COALESCE(NULLIF(T1."PoNum", ''), T0."NumAtCard")                 AS po_number,
    T1."ItemCode"                                                    AS item_code,
    T1."Dscription"                                                  AS item_description,
    -- Prefer the warehouse NAME; fall back to the code only when the name is
    -- genuinely absent (NULL or blank/whitespace). TRIM avoids join misses from
    -- trailing spaces on the code.
    COALESCE(NULLIF(TRIM(WH."WhsName"), ''), T1."WhsCode")           AS warehouse,

    T1."Quantity"                                                    AS order_qty,
    -- Pallet count for this line. RDR1/DLN1."U_QTYINPALLET" holds the (often
    -- fractional) NUMBER OF PALLETS the quantity occupies -- NOT units-per-pallet
    -- -- so it is summed, never divided into. We take the DELIVERED pallet count
    -- from the linked delivery lines (DLN1) so partial deliveries are reflected.
    COALESCE((
        SELECT SUM(D1."U_QTYINPALLET")
        FROM "DAMASCUS_BAKERY"."DLN1" D1
        WHERE D1."BaseType"  = 17
          AND D1."BaseEntry" = T1."DocEntry"
          AND D1."BaseLine"  = T1."LineNum"
    ), 0)                                                            AS qty_pallet,
    T1."NumPerMsr"                                                   AS qty_per_pack,
    -- Units-per-pallet is not stored as such on this schema; pallet counts come
    -- from qty_pallet above. Left NULL to avoid a wrong delivered_qty/x division.
    NULL                                                             AS qty_per_pallet,
    T1."unitMsr"                                                     AS unit_of_measure,
    T1."ReleasQtty"                                                  AS released_qty,

    -- delivered qty summed from delivery lines linked back to this SO line
    COALESCE((
        SELECT SUM(D1."Quantity")
        FROM "DAMASCUS_BAKERY"."DLN1" D1
        WHERE D1."BaseType"  = 17               -- 17 = Sales Order
          AND D1."BaseEntry" = T1."DocEntry"
          AND D1."BaseLine"  = T1."LineNum"
    ), 0)                                                           AS delivered_qty,
    -- net order value $ for this line (before tax)
    COALESCE(T1."LineTotal", 0)                                      AS line_amount,
    -- delivered value $: net line total summed from the linked delivery lines
    COALESCE((
        SELECT SUM(D1."LineTotal")
        FROM "DAMASCUS_BAKERY"."DLN1" D1
        WHERE D1."BaseType"  = 17
          AND D1."BaseEntry" = T1."DocEntry"
          AND D1."BaseLine"  = T1."LineNum"
    ), 0)                                                           AS delivered_amount,
    -- RDR1 has no picked-qty column on this HANA schema; pick status is tracked
    -- via T1."PickStatus" below. Default pick_qty to 0 (not needed for the KPIs).
    0                                                                AS pick_qty,

    CASE
        WHEN COALESCE((SELECT SUM(D1."Quantity") FROM "DAMASCUS_BAKERY"."DLN1" D1
              WHERE D1."BaseType" = 17 AND D1."BaseEntry" = T1."DocEntry"
                AND D1."BaseLine" = T1."LineNum"), 0) > 0 THEN 'Delivered'
        WHEN T1."PickStatus" = 'Y' THEN 'Picked'
        WHEN T1."PickStatus" = 'R' THEN 'Released'
        ELSE 'Not Picked'
    END                                                             AS pick_status,
    'Yes'                                                           AS approved,

    -- short shipment: delivered less than ordered on a delivered/closed line
    CASE WHEN COALESCE((SELECT SUM(D1."Quantity") FROM "DAMASCUS_BAKERY"."DLN1" D1
              WHERE D1."BaseType" = 17 AND D1."BaseEntry" = T1."DocEntry"
                AND D1."BaseLine" = T1."LineNum"), 0) > 0
              AND COALESCE((SELECT SUM(D1."Quantity") FROM "DAMASCUS_BAKERY"."DLN1" D1
              WHERE D1."BaseType" = 17 AND D1."BaseEntry" = T1."DocEntry"
                AND D1."BaseLine" = T1."LineNum"), 0) < T1."Quantity"
         THEN 'Yes' ELSE 'No' END                                   AS short_shipment,

    -- late: the actual delivery happened MORE THAN ONE DAY after the promised
    -- date, or the promise (+1 day grace) has passed and the line is still not
    -- fully delivered. Grace period = 1 day. Promised date = line required date
    -- (RDR1.ShipDate), falling back to header DocDueDate. Cancelled orders are
    -- never late — they were never meant to ship.
    CASE
        WHEN T0."CANCELED" = 'Y' THEN 'No'
        WHEN (SELECT MAX(D1."DocDate") FROM "DAMASCUS_BAKERY"."DLN1" D1
              WHERE D1."BaseType" = 17 AND D1."BaseEntry" = T1."DocEntry"
                AND D1."BaseLine" = T1."LineNum")
             > ADD_DAYS(COALESCE(T1."ShipDate", T0."DocDueDate"), 1)
            THEN 'Yes'
        WHEN COALESCE((SELECT SUM(D1."Quantity") FROM "DAMASCUS_BAKERY"."DLN1" D1
              WHERE D1."BaseType" = 17 AND D1."BaseEntry" = T1."DocEntry"
                AND D1."BaseLine" = T1."LineNum"), 0) < T1."Quantity"
             AND ADD_DAYS(COALESCE(T1."ShipDate", T0."DocDueDate"), 1) < CURRENT_DATE
            THEN 'Yes'
        ELSE 'No'
    END                                                             AS late_shipment,

    -- complete / in-full is measured at the WHOLE-ORDER level: 'Yes' only when
    -- NO line on the order is short-delivered (delivered < ordered).
    CASE WHEN NOT EXISTS (
             SELECT 1 FROM "DAMASCUS_BAKERY"."RDR1" LC
             WHERE LC."DocEntry" = T1."DocEntry"
               AND COALESCE((SELECT SUM(D1."Quantity") FROM "DAMASCUS_BAKERY"."DLN1" D1
                     WHERE D1."BaseType" = 17 AND D1."BaseEntry" = LC."DocEntry"
                       AND D1."BaseLine" = LC."LineNum"), 0) < LC."Quantity")
         THEN 'Yes' ELSE 'No' END                                   AS complete_shipment,

    -- OTIF: order fully delivered (in-full, WHOLE-ORDER) AND this line's last
    -- delivery landed on/before the promised date + 1 day grace (on-time).
    -- Promised date = line required date (RDR1.ShipDate, confirmed by Raj),
    -- fallback header DocDueDate. Customer-pickup orders use the same actual
    -- pickup/delivery date as delivered orders (per user) -- no special-casing.
    CASE WHEN NOT EXISTS (
             SELECT 1 FROM "DAMASCUS_BAKERY"."RDR1" LO
             WHERE LO."DocEntry" = T1."DocEntry"
               AND COALESCE((SELECT SUM(D1."Quantity") FROM "DAMASCUS_BAKERY"."DLN1" D1
                     WHERE D1."BaseType" = 17 AND D1."BaseEntry" = LO."DocEntry"
                       AND D1."BaseLine" = LO."LineNum"), 0) < LO."Quantity")
              AND (SELECT MAX(D1."DocDate") FROM "DAMASCUS_BAKERY"."DLN1" D1
              WHERE D1."BaseType" = 17 AND D1."BaseEntry" = T1."DocEntry"
                AND D1."BaseLine" = T1."LineNum") IS NOT NULL
              AND (SELECT MAX(D1."DocDate") FROM "DAMASCUS_BAKERY"."DLN1" D1
              WHERE D1."BaseType" = 17 AND D1."BaseEntry" = T1."DocEntry"
                AND D1."BaseLine" = T1."LineNum")
             <= ADD_DAYS(COALESCE(T1."ShipDate", T0."DocDueDate"), 1)
         THEN 'Yes' ELSE 'No' END                                   AS otif,

    CASE WHEN T1."Quantity" > 0
         THEN COALESCE((SELECT SUM(D1."Quantity") FROM "DAMASCUS_BAKERY"."DLN1" D1
              WHERE D1."BaseType" = 17 AND D1."BaseEntry" = T1."DocEntry"
                AND D1."BaseLine" = T1."LineNum"), 0) / T1."Quantity"
         ELSE 0 END                                                 AS fill_rate,
    'No'                                                            AS manual_bol,
    -- Carrier = shipping type name only. (NumAtCard is the customer's ref /
    -- PO number, not a carrier — it must not leak into the Carrier column.)
    SHP."TrnspName"                                                  AS carrier

FROM "DAMASCUS_BAKERY"."ORDR" T0
    INNER JOIN "DAMASCUS_BAKERY"."RDR1" T1 ON T1."DocEntry" = T0."DocEntry"
    LEFT  JOIN "DAMASCUS_BAKERY"."OWHS" WH  ON TRIM(WH."WhsCode") = TRIM(T1."WhsCode")
    LEFT  JOIN "DAMASCUS_BAKERY"."OSHP" SHP ON SHP."TrnspCode" = T0."TrnspCode"
    LEFT  JOIN "DAMASCUS_BAKERY"."OCRD" BP  ON BP."CardCode" = T0."CardCode"
    LEFT  JOIN "DAMASCUS_BAKERY"."OCRG" CG  ON CG."GroupCode" = BP."GroupCode"
-- SCRUM-45: full, month-aligned 12-month window (1st of the month, 12 months
-- back, through today) so every month in range is complete and history before
-- the current month loads. (Was a rolling 60-day window.) Matches the window
-- used by prodhana_payments.sql.
WHERE T0."DocDate" >= ADD_MONTHS(ADD_DAYS(CURRENT_DATE, 1 - DAYOFMONTH(CURRENT_DATE)), -12)
ORDER BY T0."DocDate", T0."DocNum", T1."LineNum";
