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
    CASE T0."DocStatus" WHEN 'O' THEN 'Open'
                        WHEN 'C' THEN 'Closed' ELSE T0."DocStatus" END AS so_status,
    T0."DocDate"                                                     AS posting_date,
    T0."DocDueDate"                                                  AS ship_date,
    T1."ShipDate"                                                    AS required_date,
    T0."CardCode"                                                    AS customer_code,
    T0."CardName"                                                    AS customer_name,
    CG."GroupName"                                                   AS customer_group,
    -- Retail flag: adjust the match to your customer group naming. Defaults to
    -- 1 when the business-partner group name contains 'Retail'.
    CASE WHEN UPPER(COALESCE(CG."GroupName", '')) LIKE '%RETAIL%'
         THEN 1 ELSE 0 END                                           AS is_retail,
    -- Customer PO. One SO can carry several unique POs, so the PO is taken from
    -- the ORDER LINE (RDR1."PoNum" = "Customer's Purchase Order Number") and
    -- only falls back to the header "Customer Ref. No." (ORDR.NumAtCard) when
    -- the line field is empty. (RDR1."PoItmNum" holds the customer's PO line #.)
    COALESCE(NULLIF(T1."PoNum", ''), T0."NumAtCard")                 AS po_number,
    T1."ItemCode"                                                    AS item_code,
    T1."Dscription"                                                  AS item_description,
    COALESCE(WH."WhsName", T1."WhsCode")                             AS warehouse,

    T1."Quantity"                                                    AS order_qty,
    NULL                                                             AS qty_pallet,
    T1."NumPerMsr"                                                   AS qty_per_pack,
    -- Bags per pallet. If you keep this on the item master as a UDF (e.g.
    -- OITM."U_BagsPerPallet"), map it here; otherwise leave NULL and pallets
    -- stay uncounted rather than guessed.
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
    -- via T1."PickStatus" below. Leave pick_qty NULL (not needed for the KPIs).
    NULL                                                             AS pick_qty,

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

    -- late: promised ship date has passed and line not fully delivered
    CASE WHEN T0."DocDueDate" < CURRENT_DATE
              AND COALESCE((SELECT SUM(D1."Quantity") FROM "DAMASCUS_BAKERY"."DLN1" D1
              WHERE D1."BaseType" = 17 AND D1."BaseEntry" = T1."DocEntry"
                AND D1."BaseLine" = T1."LineNum"), 0) < T1."Quantity"
         THEN 'Yes' ELSE 'No' END                                   AS late_shipment,

    CASE WHEN COALESCE((SELECT SUM(D1."Quantity") FROM "DAMASCUS_BAKERY"."DLN1" D1
              WHERE D1."BaseType" = 17 AND D1."BaseEntry" = T1."DocEntry"
                AND D1."BaseLine" = T1."LineNum"), 0) >= T1."Quantity"
         THEN 'Yes' ELSE 'No' END                                   AS complete_shipment,

    -- OTIF: complete AND on/before promised date
    CASE WHEN COALESCE((SELECT SUM(D1."Quantity") FROM "DAMASCUS_BAKERY"."DLN1" D1
              WHERE D1."BaseType" = 17 AND D1."BaseEntry" = T1."DocEntry"
                AND D1."BaseLine" = T1."LineNum"), 0) >= T1."Quantity"
              AND T0."DocDueDate" >= CURRENT_DATE
         THEN 'Yes' ELSE 'No' END                                   AS otif,

    CASE WHEN T1."Quantity" > 0
         THEN COALESCE((SELECT SUM(D1."Quantity") FROM "DAMASCUS_BAKERY"."DLN1" D1
              WHERE D1."BaseType" = 17 AND D1."BaseEntry" = T1."DocEntry"
                AND D1."BaseLine" = T1."LineNum"), 0) / T1."Quantity"
         ELSE 0 END                                                 AS fill_rate,
    'No'                                                            AS manual_bol,
    COALESCE(SHP."TrnspName", T0."NumAtCard")                       AS carrier

FROM "DAMASCUS_BAKERY"."ORDR" T0
    INNER JOIN "DAMASCUS_BAKERY"."RDR1" T1 ON T1."DocEntry" = T0."DocEntry"
    LEFT  JOIN "DAMASCUS_BAKERY"."OWHS" WH  ON WH."WhsCode" = T1."WhsCode"
    LEFT  JOIN "DAMASCUS_BAKERY"."OSHP" SHP ON SHP."TrnspCode" = T0."TrnspCode"
    LEFT  JOIN "DAMASCUS_BAKERY"."OCRD" BP  ON BP."CardCode" = T0."CardCode"
    LEFT  JOIN "DAMASCUS_BAKERY"."OCRG" CG  ON CG."GroupCode" = BP."GroupCode"
WHERE T0."DocDate" >= ADD_DAYS(CURRENT_DATE, -60)
ORDER BY T0."DocDate", T0."DocNum", T1."LineNum";
