-- ===========================================================================
-- PRODHANA (SAP B1 on HANA, schema DAMASCUS_BAKERY) -> inventory_batches.
--
-- HANA variant of primsbm_batches.sql for the PRODHANA linked-server bridge:
--   php etl/pull_inventory.php --what=batches --source=PRIMSBM \
--       --query=etl/queries/prodhana_batches.sql --via=PRODHANA
--
-- Batch-level on-hand per warehouse for aging: OIBT (batch qty per warehouse,
-- with InDate/ExpDate) joined to OITM/OWHS. Newer B1 versions split batch
-- master data into OBTN/OBBQ — adjust the join if OIBT lacks the dates.
-- READ-ONLY (SELECT).
-- ===========================================================================

SELECT
    B."ItemCode" || '|' || B."BatchNum" || '|' || B."WhsCode" AS source_key,
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
FROM "DAMASCUS_BAKERY"."OIBT" B
    INNER JOIN "DAMASCUS_BAKERY"."OITM" I ON I."ItemCode" = B."ItemCode"
    LEFT JOIN "DAMASCUS_BAKERY"."OWHS" WH ON WH."WhsCode" = B."WhsCode"
WHERE B."Quantity" > 0;
