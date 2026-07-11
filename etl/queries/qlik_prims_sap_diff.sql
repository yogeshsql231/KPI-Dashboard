-- ===========================================================================
-- SCRUM-31 — smoke-test / manual run of the Qlik live-diff stored procedure.
--
-- The live feed is produced by dbo.sp_qlik_prims_sap_diff (see
-- sql/sqlsrv/sp_qlik_prims_sap_diff.sql). This file just EXECs it so you can
-- inspect the result set from the CLI without opening Excel/Qlik:
--
--   php etl/query.php --source=PRIMSBM --query=etl/queries/qlik_prims_sap_diff.sql
--
-- READ-ONLY: the procedure only SELECTs from PRIMS and SAP/PRODHANA.
-- Pass @OnlyDifferences = 0 to dump every item (full reconciliation) instead
-- of only the rows that differ.
-- ===========================================================================

EXEC dbo.sp_qlik_prims_sap_diff @OnlyDifferences = 1;
