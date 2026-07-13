-- ===========================================================================
-- PRODHANA (SAP Business One on HANA) -> item master validation (SCRUM-22).
--
-- Flags outdated / incomplete OITM item-master records at the SAP source so
-- they can be corrected in SAP B1 (the dashboard cache inherits whatever is
-- here). One row per finding. READ-ONLY (SELECT) — never writes to SAP.
--
-- Checks:
--   MISSING_DESCRIPTION  active item with an empty ItemName
--   MISSING_ITEM_GROUP   active item with no item group assigned
--   MISSING_UOM          active inventory item with no inventory UoM
--   MISSING_PACK_UNITS   active sales item with no SalPackUn (breaks
--                        case/pallet conversions downstream)
--   FROZEN_WITH_STOCK    frozen/inactive item that still has on-hand stock
--
-- Run through the SQL Server linked server:
--   php etl/query.php --source=PRODHANA --query=etl/queries/prodhana_item_master_validation.sql --via=PRODHANA
-- ===========================================================================

SELECT 'MISSING_DESCRIPTION' AS issue, I."ItemCode" AS item_code,
       I."ItemName" AS item_description, I."UpdateDate" AS last_updated
FROM "DAMASCUS_BAKERY"."OITM" I
WHERE I."frozenFor" = 'N' AND (I."ItemName" IS NULL OR TRIM(I."ItemName") = '')

UNION ALL

SELECT 'MISSING_ITEM_GROUP', I."ItemCode", I."ItemName", I."UpdateDate"
FROM "DAMASCUS_BAKERY"."OITM" I
WHERE I."frozenFor" = 'N' AND I."ItmsGrpCod" IS NULL

UNION ALL

SELECT 'MISSING_UOM', I."ItemCode", I."ItemName", I."UpdateDate"
FROM "DAMASCUS_BAKERY"."OITM" I
WHERE I."frozenFor" = 'N' AND I."InvntItem" = 'Y'
  AND (I."InvntryUom" IS NULL OR TRIM(I."InvntryUom") = '')

UNION ALL

SELECT 'MISSING_PACK_UNITS', I."ItemCode", I."ItemName", I."UpdateDate"
FROM "DAMASCUS_BAKERY"."OITM" I
WHERE I."frozenFor" = 'N' AND I."SellItem" = 'Y'
  AND (I."SalPackUn" IS NULL OR I."SalPackUn" <= 0)

UNION ALL

SELECT 'FROZEN_WITH_STOCK', I."ItemCode", I."ItemName", I."UpdateDate"
FROM "DAMASCUS_BAKERY"."OITM" I
WHERE I."frozenFor" = 'Y'
  AND EXISTS (SELECT 1 FROM "DAMASCUS_BAKERY"."OITW" W
              WHERE W."ItemCode" = I."ItemCode" AND W."OnHand" > 0)

ORDER BY 1, 2
