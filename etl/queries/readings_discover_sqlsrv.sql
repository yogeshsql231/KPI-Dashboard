-- Discover the operational-reading tables (silos, batch master, process
-- readings) on SQL Server. Read-only. Run this FIRST to confirm the real
-- table + column names, then adjust etl/queries/primsbm_readings.sql to map
-- the reading feed to your schema.
--
--   php etl/query.php --source=PRIMSBM --query=etl/queries/readings_discover_sqlsrv.sql
--
-- 1) Candidate tables (Beas process/batch/silo + B1 batch master OBTN):
SELECT s.name AS schema_name, t.name AS table_name, p.rows AS row_count
FROM sys.tables t
JOIN sys.schemas s ON s.schema_id = t.schema_id
JOIN sys.partitions p ON p.object_id = t.object_id AND p.index_id IN (0, 1)
WHERE t.name LIKE '@BMM_%'
   OR t.name LIKE '%SILO%'
   OR t.name LIKE '%TANK%'
   OR t.name LIKE '%BATCH%'
   OR t.name LIKE 'OBTN'          -- B1 batch-number master
   OR t.name LIKE '%READING%'
   OR t.name LIKE '%LEVEL%'
ORDER BY p.rows DESC;

-- 2) Columns of a candidate table (change the name to match #1):
-- SELECT c.name AS column_name, ty.name AS data_type, c.max_length
-- FROM sys.columns c
-- JOIN sys.types ty ON ty.user_type_id = c.user_type_id
-- WHERE c.object_id = OBJECT_ID('[OBTN]')
-- ORDER BY c.column_id;
