-- ===========================================================================
-- PRODHANA (SAP HANA) -> order_shipments source query.
--
-- FILL THIS IN with your real PRODHANA schema/table (or CDS view). Alias the
-- output columns to exactly these names (see primsbm_shipments.sql for the
-- full field descriptions):
--
--   source_key, ship_date, po_number, customer, ship_via, item_number,
--   qty_requested, qty_shipped, order_date, requested_date, actual_date
--
-- Note the HANA "SCHEMA"."TABLE" quoting. Keep it read-only (SELECT).
-- ===========================================================================

SELECT
    TO_VARCHAR("DELIVERY_LINE_ID")  AS source_key,
    "SHIP_DATE"                     AS ship_date,
    "PO_NUMBER"                     AS po_number,
    "CUSTOMER_NAME"                 AS customer,
    "SHIP_VIA"                      AS ship_via,
    "ITEM_NUMBER"                   AS item_number,
    "QTY_ORDERED"                   AS qty_requested,
    "QTY_SHIPPED"                   AS qty_shipped,
    "ORDER_DATE"                    AS order_date,
    "REQUESTED_DATE"                AS requested_date,
    "ACTUAL_DATE"                   AS actual_date
FROM "PRODHANA"."DELIVERY_DETAIL"
WHERE "SHIP_DATE" >= ADD_DAYS(CURRENT_DATE, -30);
