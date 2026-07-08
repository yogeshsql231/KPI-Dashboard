-- ===========================================================================
-- SCRUM-6 diagnostic: reconcile Sales Orders vs Deliveries vs Invoices for May.
--
-- READ-ONLY. Run these in SSMS against the PRODHANA linked server (same as the
-- earlier pallet searches). Adjust the date range (@from / @to) to the month you
-- are reconciling; the examples below use May 2026.
--
-- Flow in SAP B1: Sales Order (ORDR/RDR1) -> Delivery (ODLN/DLN1, BaseType 17)
-- -> A/R Invoice (OINV/INV1, BaseType 15 = from delivery, or 17 = direct from SO).
-- ===========================================================================

-- ---------------------------------------------------------------------------
-- STEP 1 — period totals: three single numbers (net line amount + qty) for
-- documents DATED in the month. Fast gut-check of where the gap is.
-- ---------------------------------------------------------------------------
SELECT * FROM OPENQUERY(PRODHANA, '
SELECT
    (SELECT COALESCE(SUM(T1."LineTotal"),0)
       FROM "DAMASCUS_BAKERY"."ORDR" T0
       JOIN "DAMASCUS_BAKERY"."RDR1" T1 ON T1."DocEntry" = T0."DocEntry"
      WHERE T0."DocDate" BETWEEN ''2026-05-01'' AND ''2026-05-31''
        AND IFNULL(T0."CANCELED", ''N'') <> ''Y'')              AS so_amount,
    (SELECT COALESCE(SUM(L1."LineTotal"),0)
       FROM "DAMASCUS_BAKERY"."ODLN" L0
       JOIN "DAMASCUS_BAKERY"."DLN1" L1 ON L1."DocEntry" = L0."DocEntry"
      WHERE L0."DocDate" BETWEEN ''2026-05-01'' AND ''2026-05-31''
        AND IFNULL(L0."CANCELED", ''N'') <> ''Y'')              AS delivery_amount,
    (SELECT COALESCE(SUM(I1."LineTotal"),0)
       FROM "DAMASCUS_BAKERY"."OINV" I0
       JOIN "DAMASCUS_BAKERY"."INV1" I1 ON I1."DocEntry" = I0."DocEntry"
      WHERE I0."DocDate" BETWEEN ''2026-05-01'' AND ''2026-05-31''
        AND IFNULL(I0."CANCELED", ''N'') <> ''Y'')              AS invoice_amount
FROM DUMMY');


-- ---------------------------------------------------------------------------
-- STEP 2 — order-level three-way reconciliation for orders dated in the month.
-- One row per sales order: ordered vs delivered vs invoiced net amount, with the
-- gaps. Deliveries roll up via DLN1.BaseType=17 -> RDR1; invoices roll up via
-- INV1 (BaseType 15 -> its delivery -> the SO, or BaseType 17 -> direct to SO).
-- Only orders where the three do NOT tie out are returned (the discrepancies).
-- ---------------------------------------------------------------------------
SELECT TOP 200 * FROM OPENQUERY(PRODHANA, '
WITH SO AS (
    SELECT T0."DocEntry", T0."DocNum", T0."CardName",
           SUM(T1."LineTotal") AS so_amt
      FROM "DAMASCUS_BAKERY"."ORDR" T0
      JOIN "DAMASCUS_BAKERY"."RDR1" T1 ON T1."DocEntry" = T0."DocEntry"
     WHERE T0."DocDate" BETWEEN ''2026-05-01'' AND ''2026-05-31''
       AND IFNULL(T0."CANCELED", ''N'') <> ''Y''
     GROUP BY T0."DocEntry", T0."DocNum", T0."CardName"
),
DEL AS (
    SELECT D1."BaseEntry" AS so_docentry, SUM(D1."LineTotal") AS del_amt
      FROM "DAMASCUS_BAKERY"."DLN1" D1
     WHERE D1."BaseType" = 17
     GROUP BY D1."BaseEntry"
),
INV AS (
    -- invoice line -> its source SO DocEntry (via the delivery for BaseType 15,
    -- or directly for BaseType 17), then summed per SO
    SELECT SRC.so_docentry, SUM(SRC.inv_amt) AS inv_amt FROM (
        SELECT DL."BaseEntry" AS so_docentry, I1."LineTotal" AS inv_amt
          FROM "DAMASCUS_BAKERY"."INV1" I1
          JOIN "DAMASCUS_BAKERY"."DLN1" DL
            ON DL."DocEntry" = I1."BaseEntry" AND DL."LineNum" = I1."BaseLine"
         WHERE I1."BaseType" = 15 AND DL."BaseType" = 17
        UNION ALL
        SELECT I1."BaseEntry" AS so_docentry, I1."LineTotal" AS inv_amt
          FROM "DAMASCUS_BAKERY"."INV1" I1
         WHERE I1."BaseType" = 17
    ) SRC
    GROUP BY SRC.so_docentry
)
SELECT SO."DocNum", SO."CardName",
       SO.so_amt,
       IFNULL(DEL.del_amt,0) AS del_amt,
       IFNULL(INV.inv_amt,0) AS inv_amt,
       SO.so_amt - IFNULL(DEL.del_amt,0) AS ordered_not_delivered,
       IFNULL(DEL.del_amt,0) - IFNULL(INV.inv_amt,0) AS delivered_not_invoiced
  FROM SO
  LEFT JOIN DEL ON DEL.so_docentry = SO."DocEntry"
  LEFT JOIN INV ON INV.so_docentry = SO."DocEntry"
 WHERE ABS(SO.so_amt - IFNULL(DEL.del_amt,0)) > 0.01
    OR ABS(IFNULL(DEL.del_amt,0) - IFNULL(INV.inv_amt,0)) > 0.01
 ORDER BY ABS(SO.so_amt - IFNULL(INV.inv_amt,0)) DESC');


-- ---------------------------------------------------------------------------
-- STEP 3 — explain the Delivery-vs-Invoice gap for the month.
-- (Invoices dated in May exceeded deliveries dated in May by ~$588K.)
--
-- Decomposes May invoice value by origin, so the excess over May deliveries is
-- accounted for: invoices raised from a delivery (BaseType 15) split by whether
-- that delivery was dated in-month or before; invoices billed DIRECT from the
-- sales order (BaseType 17, no delivery doc); and manual/other invoices.
-- ---------------------------------------------------------------------------
SELECT * FROM OPENQUERY(PRODHANA, '
SELECT
    SUM(I1."LineTotal")                                                        AS invoice_total,
    SUM(CASE WHEN I1."BaseType" = 15 AND DL."DocDate" BETWEEN ''2026-05-01'' AND ''2026-05-31''
             THEN I1."LineTotal" ELSE 0 END)                                   AS from_may_deliveries,
    SUM(CASE WHEN I1."BaseType" = 15 AND DL."DocDate" < ''2026-05-01''
             THEN I1."LineTotal" ELSE 0 END)                                   AS from_pre_may_deliveries,
    SUM(CASE WHEN I1."BaseType" = 17 THEN I1."LineTotal" ELSE 0 END)           AS direct_from_so,
    SUM(CASE WHEN IFNULL(I1."BaseType", -1) NOT IN (15, 17)
             THEN I1."LineTotal" ELSE 0 END)                                   AS manual_or_other
  FROM "DAMASCUS_BAKERY"."OINV" I0
  JOIN "DAMASCUS_BAKERY"."INV1" I1 ON I1."DocEntry" = I0."DocEntry"
  LEFT JOIN "DAMASCUS_BAKERY"."DLN1" DL
         ON I1."BaseType" = 15 AND DL."DocEntry" = I1."BaseEntry" AND DL."LineNum" = I1."BaseLine"
 WHERE I0."DocDate" BETWEEN ''2026-05-01'' AND ''2026-05-31''
   AND IFNULL(I0."CANCELED", ''N'') <> ''Y''');


-- STEP 3b — the actual invoices making up the "excess" (direct-from-SO, manual,
-- or billing a pre-May delivery). These are what push May invoices above May
-- deliveries; hand this list to finance as the reconciliation of the gap.
SELECT TOP 100 * FROM OPENQUERY(PRODHANA, '
SELECT I0."DocNum" AS invoice_no, I0."DocDate" AS invoice_date, I0."CardName" AS customer,
       CASE WHEN I1."BaseType" = 17 THEN ''Direct from SO''
            WHEN I1."BaseType" = 15 THEN ''Bills pre-May delivery''
            ELSE ''Manual / other'' END                              AS reason,
       DL."DocNum" AS delivery_no, DL."DocDate" AS delivery_date,
       SUM(I1."LineTotal")                                           AS line_value
  FROM "DAMASCUS_BAKERY"."OINV" I0
  JOIN "DAMASCUS_BAKERY"."INV1" I1 ON I1."DocEntry" = I0."DocEntry"
  LEFT JOIN "DAMASCUS_BAKERY"."DLN1" DL
         ON I1."BaseType" = 15 AND DL."DocEntry" = I1."BaseEntry" AND DL."LineNum" = I1."BaseLine"
 WHERE I0."DocDate" BETWEEN ''2026-05-01'' AND ''2026-05-31''
   AND IFNULL(I0."CANCELED", ''N'') <> ''Y''
   AND ( I1."BaseType" = 17
      OR IFNULL(I1."BaseType", -1) NOT IN (15, 17)
      OR (I1."BaseType" = 15 AND DL."DocDate" < ''2026-05-01'') )
 GROUP BY I0."DocNum", I0."DocDate", I0."CardName",
          CASE WHEN I1."BaseType" = 17 THEN ''Direct from SO''
               WHEN I1."BaseType" = 15 THEN ''Bills pre-May delivery''
               ELSE ''Manual / other'' END,
          DL."DocNum", DL."DocDate"
 ORDER BY line_value DESC');
