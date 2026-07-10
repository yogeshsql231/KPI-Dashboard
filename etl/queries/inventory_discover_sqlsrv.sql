-- ===========================================================================
-- Discovery queries for the Warehouse/Inventory ETL (run on PRIMSBM SQL
-- Server, one block at a time). Confirms the real table/column names before
-- filling in primsbm_stock/packaging/batches/movements.sql. READ-ONLY.
-- ===========================================================================

-- 1) Which of the expected SAP B1 tables exist?
SELECT name FROM sys.tables
WHERE name IN ('OITW', 'OITM', 'OWHS', 'OIBT', 'OBTN', 'OBBQ', 'OINM', 'OWTR', 'WTR1', 'OIGE', 'IGE1')
ORDER BY name;

-- 2) Columns of the batch tables (older B1: OIBT has InDate/ExpDate; newer:
--    OBTN holds them and OBBQ holds per-warehouse qty).
SELECT c.name AS column_name, t.name AS table_name
FROM sys.columns c JOIN sys.tables t ON t.object_id = c.object_id
WHERE t.name IN ('OIBT', 'OBTN', 'OBBQ')
ORDER BY t.name, c.column_id;

-- 3) Packaging factors on OITM (look for case/pallet fields incl. UDFs U_%).
SELECT c.name AS column_name
FROM sys.columns c JOIN sys.tables t ON t.object_id = c.object_id
WHERE t.name = 'OITM'
  AND (c.name LIKE '%Pack%' OR c.name LIKE '%Pallet%' OR c.name LIKE 'U\_%' ESCAPE '\'
       OR c.name IN ('NumInSale', 'NumInBuy', 'SalPackUn', 'PurPackUn', 'InvntryUom', 'SalUnitMsr'))
ORDER BY c.name;

-- 4) Warehouse list — identify the STAGING and WASTE/SCRAP warehouse codes
--    used in primsbm_movements.sql.
SELECT "WhsCode", "WhsName" FROM OWHS ORDER BY "WhsCode";

-- 5) Which TransTypes actually occur in the journal (last 3 months)?
SELECT "TransType", COUNT(*) AS rows_
FROM OINM
WHERE "DocDate" >= DATEADD(MONTH, -3, CAST(GETDATE() AS DATE))
GROUP BY "TransType"
ORDER BY rows_ DESC;
