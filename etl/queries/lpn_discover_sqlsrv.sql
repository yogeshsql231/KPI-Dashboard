-- Discover the Beas WMS / pallet (LPN) tables and their columns on SQL Server.
-- Read-only. Run this FIRST to confirm the real table + column names, then
-- adjust etl/queries/primsbm_lpn.sql so the LPN feed maps to your schema.
--
--   php etl/query.php --source=PRIMSBM --query=etl/queries/lpn_discover_sqlsrv.sql
--
-- 1) Beas WMS tables that likely hold pallet / LPN / bin data:
SELECT s.name AS schema_name, t.name AS table_name, p.rows AS row_count
FROM sys.tables t
JOIN sys.schemas s ON s.schema_id = t.schema_id
JOIN sys.partitions p ON p.object_id = t.object_id AND p.index_id IN (0, 1)
WHERE t.name LIKE '@BMM_%'
   OR t.name LIKE '%PALLET%'
   OR t.name LIKE '%LPN%'
   OR t.name LIKE '%BIN%'
ORDER BY p.rows DESC;

-- 2) Columns of the pallet-master table (change the table name to match #1):
-- SELECT c.name AS column_name, ty.name AS data_type, c.max_length
-- FROM sys.columns c
-- JOIN sys.types ty ON ty.user_type_id = c.user_type_id
-- WHERE c.object_id = OBJECT_ID('[@BMM_PALLETMASTER]')
-- ORDER BY c.column_id;
