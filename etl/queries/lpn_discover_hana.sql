-- Discover the Beas WMS / pallet (LPN) tables and their columns on SAP HANA.
-- Read-only. Run this FIRST to confirm the real table + column names, then
-- adjust etl/queries/prodhana_lpn.sql so the LPN feed maps to your schema.
-- Replace 'DAMASCUS_BAKERY' with your company schema if different.
--
--   php etl/query.php --source=PRODHANA --query=etl/queries/lpn_discover_hana.sql
--
-- 1) Beas WMS tables that likely hold pallet / LPN / bin data:
SELECT TABLE_NAME, RECORD_COUNT
FROM "SYS"."M_TABLES"
WHERE SCHEMA_NAME = 'DAMASCUS_BAKERY'
  AND (TABLE_NAME LIKE '@BMM_%'
    OR TABLE_NAME LIKE '%PALLET%'
    OR TABLE_NAME LIKE '%LPN%'
    OR TABLE_NAME LIKE '%BIN%')
ORDER BY RECORD_COUNT DESC;

-- 2) Columns of the pallet-master table (change the table name to match #1):
-- SELECT COLUMN_NAME, DATA_TYPE_NAME, LENGTH
-- FROM "SYS"."TABLE_COLUMNS"
-- WHERE SCHEMA_NAME = 'DAMASCUS_BAKERY'
--   AND TABLE_NAME = '@BMM_PALLETMASTER'
-- ORDER BY POSITION;
