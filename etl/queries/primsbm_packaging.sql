-- ===========================================================================
-- PRIMSBM (SQL Server, SAP B1) -> material_packaging source query.
--
-- Case/bundle/bag per pallet conversion factors per item, from OITM UoM /
-- packaging fields. If Beas or a UDF holds the true cases-per-pallet, alias
-- it in place of the best-guess columns below. READ-ONLY (SELECT).
-- Verify with etl/queries/inventory_discover_sqlsrv.sql first.
-- ===========================================================================

SELECT
    I."ItemCode"                                        AS item_code,
    I."ItemName"                                        AS item_description,
    I."InvntryUom"                                      AS base_uom,
    I."NumInSale"                                       AS units_per_case,     -- units per sales pack (case)
    I."SalPackUn"                                       AS cases_per_pallet,   -- CONFIRM: often a UDF like U_CasePerPallet
    CASE
        WHEN I."NumInSale" IS NOT NULL AND I."SalPackUn" IS NOT NULL
        THEN I."NumInSale" * I."SalPackUn"
        ELSE NULL
    END                                                 AS units_per_pallet,
    I."SalUnitMsr"                                      AS pack_description
FROM OITM I
WHERE I."InvntItem" = 'Y'
ORDER BY I."ItemCode";
