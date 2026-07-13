-- ===========================================================================
-- PRODHANA (SAP B1 on HANA, schema DAMASCUS_BAKERY) -> material_packaging.
--
-- HANA variant of primsbm_packaging.sql for the PRODHANA linked-server bridge:
--   php etl/pull_inventory.php --what=packaging --source=PRIMSBM \
--       --query=etl/queries/prodhana_packaging.sql --via=PRODHANA
--
-- Case/bundle/bag per pallet conversion factors per item from OITM. If Beas
-- or a UDF holds the true cases-per-pallet, alias it in place of SalPackUn.
-- READ-ONLY (SELECT).
-- ===========================================================================

SELECT
    I."ItemCode"                                        AS item_code,
    I."ItemName"                                        AS item_description,
    I."InvntryUom"                                      AS base_uom,
    I."NumInSale"                                       AS units_per_case,
    I."SalPackUn"                                       AS cases_per_pallet,
    CASE
        WHEN I."NumInSale" IS NOT NULL AND I."SalPackUn" IS NOT NULL
        THEN I."NumInSale" * I."SalPackUn"
        ELSE NULL
    END                                                 AS units_per_pallet,
    I."SalUnitMsr"                                      AS pack_description
FROM "DAMASCUS_BAKERY"."OITM" I
WHERE I."InvntItem" = 'Y';
