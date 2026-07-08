-- ===========================================================================
-- PRODHANA (SAP Business One on HANA) -> ar_payments source query.
--
-- One row per A/R INVOICE (OINV), with the date the customer's payment was
-- actually received (latest incoming-payment date applied to the invoice via
-- RCT2 -> ORCT). Feeds the "Late Deliveries vs Late Payments" report. Output
-- column names match ar_payments exactly, so the ETL upserts straight in.
-- READ-ONLY (SELECT) -- never writes to SAP.
--
-- Adjust the schema name ("DAMASCUS_BAKERY") if yours differs.
--
-- Key SAP tables:
--   OINV  A/R invoice header      INV1  A/R invoice lines
--   ORCT  incoming payment header RCT2  payment-to-invoice links
--
-- NOTE: verify the RCT2 link fields against your DB. Standard SAP B1:
--   RCT2."DocNum"  = ORCT."DocEntry"  (the payment this row belongs to)
--   RCT2."DocEntry"= OINV."DocEntry"  (the invoice being paid)
--   RCT2."InvType" = 13               (13 = A/R invoice)
-- If your build differs, only the paid_date subquery join needs editing.
-- ===========================================================================

SELECT
    TO_VARCHAR(I."DocEntry")                                          AS source_key,
    TO_VARCHAR(I."DocNum")                                           AS invoice_num,
    I."CardCode"                                                     AS customer_code,
    I."CardName"                                                     AS customer_name,
    I."DocDate"                                                      AS invoice_date,
    I."DocDueDate"                                                   AS due_date,
    -- payment received date = latest non-canceled incoming payment applied to
    -- this invoice. NULL while the invoice is still open / unpaid.
    (SELECT MAX(P."DocDate")
       FROM "DAMASCUS_BAKERY"."RCT2" R
       JOIN "DAMASCUS_BAKERY"."ORCT" P ON P."DocEntry" = R."DocNum"
      WHERE R."InvType"  = 13
        AND R."DocEntry" = I."DocEntry"
        AND IFNULL(P."Canceled", 'N') <> 'Y')                       AS paid_date,
    COALESCE(I."DocTotal", 0)                                        AS invoice_amount,
    COALESCE(I."PaidToDate", 0)                                      AS paid_amount
FROM "DAMASCUS_BAKERY"."OINV" I
-- Same rolling window as the delivery ETL: first day of the month 12 months
-- ago, so every month in range is complete and ties out with the delivery side.
WHERE I."DocDate" >= ADD_MONTHS(
        TO_DATE(TO_VARCHAR(CURRENT_DATE, 'YYYY-MM') || '-01', 'YYYY-MM-DD'), -12)
  AND IFNULL(I."CANCELED", 'N') <> 'Y'
ORDER BY I."DocDate", I."DocNum";
