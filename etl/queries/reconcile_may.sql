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
