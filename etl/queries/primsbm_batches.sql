-- ===========================================================================
-- PRIMSBM (SQL Server, SAP B1) -> inventory_batches source query.
--
-- Batch-level on-hand per warehouse for aging: OIBT (batch qty per warehouse)
-- joined to OBTN (batch master: admission InDate, expiry ExpDate) and OITM.
-- Older B1 versions store batch data in OIBT alone (with InDate/ExpDate on
-- OIBT); newer ones split master data into OBTN/OBBQ — adjust the join to
-- whichever your version has. READ-ONLY (SELECT). Verify with
-- etl/queries/inventory_discover_sqlsrv.sql first.
-- ===========================================================================

SELECT
    CAST(B."ItemCode" AS VARCHAR(80)) + '|' + CAST(B."BatchNum" AS VARCHAR(80))
        + '|' + CAST(B."WhsCode" AS VARCHAR(40))        AS source_key,
    B."ItemCode"                                        AS item_code,
    I."ItemName"                                        AS item_description,
    B."BatchNum"                                        AS batch_number,
    COALESCE(WH."WhsName", B."WhsCode")                 AS warehouse,
    B."Quantity"                                        AS quantity,
    I."InvntryUom"                                      AS unit_of_measure,
    CASE
        WHEN I."SalPackUn" IS NOT NULL AND I."SalPackUn" > 0
        THEN B."Quantity" / I."SalPackUn"
        ELSE NULL
    END                                                 AS pallets,
    B."InDate"                                          AS admission_date,
    B."ExpDate"                                         AS expiry_date
FROM OIBT B
    INNER JOIN OITM I ON I."ItemCode" = B."ItemCode"
    LEFT JOIN OWHS WH ON WH."WhsCode" = B."WhsCode"
WHERE B."Quantity" > 0
ORDER BY B."WhsCode", B."ItemCode", B."BatchNum";
