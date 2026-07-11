-- ===========================================================================
-- SCRUM-31 — Qlik ("Click") live data connection.
--
-- SQL Server stored procedure that surfaces the DIFFERENCES between PRIMS and
-- SAP/PRODHANA. This is the single data source for the live Qlik feed: the
-- procedure runs in SQL Server, its result set is linked into an Excel sheet
-- (a LIVE link, not a static export — see qlik/prims_sap_diff.odc), and the
-- Qlik app reads from that linked Excel sheet.
--
-- Deploy on the PRIMSBM SQL Server (192.168.100.5):
--   sqlcmd -S 192.168.100.5 -d PRIMSBM -i sql/sqlsrv/sp_qlik_prims_sap_diff.sql
-- (or run the file in SSMS). READ-ONLY: it only SELECTs from both systems and
-- never writes back to PRIMS or SAP.
--
-- ---------------------------------------------------------------------------
-- HOW IT WORKS
--   * PRIMS side  : native PRIMS inventory-on-hand table on this SQL Server.
--   * SAP side    : SAP Business One item-per-warehouse stock (OITW) read from
--                   the PRODHANA HANA box through a SQL Server LINKED SERVER
--                   via OPENQUERY (same pattern the ETL uses for HANA).
--   * The two are FULL OUTER JOIN-ed on (item_code, warehouse) so items that
--     exist in only one system still appear, and the procedure returns the
--     quantity/value delta plus a human-readable diff_reason.
--
-- ADJUST FOR YOUR ENVIRONMENT (left column / output aliases must stay as-is):
--   * @HanaLinkedServer — the name of the linked server that points at
--     PRODHANA (SAP HANA). Confirm the real name in SSMS > Server Objects >
--     Linked Servers, or with:  EXEC sp_linkedservers;
--   * The PRIMS source block (marked "PRIMS SOURCE — ADJUST") — point it at the
--     real PRIMS on-hand table/columns. The placeholder below assumes a table
--     PRIMS_InventoryOnHand(ItemCode, Warehouse, OnHandQty, StockValue); rename
--     to match the actual PRIMS schema. Everything downstream keys off the
--     aliases item_code / warehouse / qty / value, so only this block changes.
--   * The HANA schema name "DAMASCUS_BAKERY" if your schema differs.
--
-- Parameters:
--   @OnlyDifferences BIT = 1  -> return only rows where PRIMS <> SAP (default,
--                                what Qlik shows). Pass 0 to return every item
--                                for full reconciliation / debugging.
--   @Tolerance       DECIMAL   -> abs qty delta at/below which a row is treated
--                                as "matched" (rounding noise). Default 0.
--
-- Edge cases (per SCRUM-31): there is NO automated fallback. If a source is
-- unavailable the procedure simply errors (OPENQUERY / linked server error)
-- and the Excel refresh keeps the last good copy; discrepancies are corrected
-- manually via paperwork and the process resumes once corrected. No retry or
-- silent-substitution logic is intended here.
-- ===========================================================================

SET ANSI_NULLS ON;
GO
SET QUOTED_IDENTIFIER ON;
GO

CREATE OR ALTER PROCEDURE dbo.sp_qlik_prims_sap_diff
    @OnlyDifferences BIT           = 1,
    @Tolerance       DECIMAL(18,4) = 0
AS
BEGIN
    SET NOCOUNT ON;

    -- Name of the linked server that points at PRODHANA (SAP HANA).
    -- ADJUST to the real linked-server name on this box.
    DECLARE @HanaLinkedServer SYSNAME = N'PRODHANA';

    -- OPENQUERY needs a string literal for the linked-server name, so the SAP
    -- pull is assembled and run through dynamic SQL. The inner HANA query is
    -- READ-ONLY. Snapshot both systems into temp tables, then diff them.
    DECLARE @sapSql NVARCHAR(MAX) =
        N'SELECT item_code, warehouse, sap_qty, sap_value
          INTO #sap
          FROM OPENQUERY(' + QUOTENAME(@HanaLinkedServer) + N', ''
              SELECT
                  W."ItemCode"                         AS item_code,
                  W."WhsCode"                          AS warehouse,
                  W."OnHand"                           AS sap_qty,
                  W."OnHand" * COALESCE(I."AvgPrice", 0) AS sap_value
              FROM "DAMASCUS_BAKERY"."OITW" W
              INNER JOIN "DAMASCUS_BAKERY"."OITM" I ON I."ItemCode" = W."ItemCode"
          '');';

    IF OBJECT_ID('tempdb..#sap')   IS NOT NULL DROP TABLE #sap;
    IF OBJECT_ID('tempdb..#prims') IS NOT NULL DROP TABLE #prims;

    EXEC sp_executesql @sapSql;

    -- =======================================================================
    -- PRIMS SOURCE — ADJUST to the real PRIMS inventory-on-hand table/columns.
    -- Output aliases (item_code, warehouse, prims_qty, prims_value) must stay.
    -- =======================================================================
    SELECT
        CAST(P.ItemCode  AS VARCHAR(50)) AS item_code,
        CAST(P.Warehouse AS VARCHAR(50)) AS warehouse,
        CAST(P.OnHandQty AS DECIMAL(18,4)) AS prims_qty,
        CAST(P.StockValue AS DECIMAL(18,4)) AS prims_value
    INTO #prims
    FROM dbo.PRIMS_InventoryOnHand P;

    -- =======================================================================
    -- DIFF: full outer join so items present in only one system still show up.
    -- =======================================================================
    SELECT
        COALESCE(p.item_code, s.item_code)  AS item_code,
        COALESCE(p.warehouse, s.warehouse)  AS warehouse,
        COALESCE(p.prims_qty, 0)            AS prims_qty,
        COALESCE(s.sap_qty,   0)            AS sap_qty,
        COALESCE(p.prims_qty, 0) - COALESCE(s.sap_qty, 0)   AS qty_diff,
        ABS(COALESCE(p.prims_qty, 0) - COALESCE(s.sap_qty, 0)) AS abs_qty_diff,
        COALESCE(p.prims_value, 0)          AS prims_value,
        COALESCE(s.sap_value,   0)          AS sap_value,
        COALESCE(p.prims_value, 0) - COALESCE(s.sap_value, 0) AS value_diff,
        CASE
            WHEN p.item_code IS NULL THEN 'Missing in PRIMS'
            WHEN s.item_code IS NULL THEN 'Missing in SAP'
            WHEN ABS(COALESCE(p.prims_qty, 0) - COALESCE(s.sap_qty, 0)) > @Tolerance
                 THEN 'Quantity mismatch'
            ELSE 'Match'
        END                                 AS diff_reason,
        CAST(SYSDATETIME() AS DATETIME2(0)) AS snapshot_at
    FROM #prims p
    FULL OUTER JOIN #sap s
        ON s.item_code = p.item_code
       AND s.warehouse = p.warehouse
    WHERE @OnlyDifferences = 0
       OR p.item_code IS NULL
       OR s.item_code IS NULL
       OR ABS(COALESCE(p.prims_qty, 0) - COALESCE(s.sap_qty, 0)) > @Tolerance
    ORDER BY abs_qty_diff DESC, item_code, warehouse;

    DROP TABLE #sap;
    DROP TABLE #prims;
END;
GO
