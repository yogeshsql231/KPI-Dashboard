-- ===========================================================================
-- PRODHANA (SAP Business One on HANA) -> operational_readings source query.
--
-- Feeds the Audit dashboard + email alerts (SCRUM-15) with operational
-- readings: silo / tank levels, batch-master shelf-life, process readings.
-- Output column names match operational_readings exactly so the ETL upserts
-- straight in. READ-ONLY (SELECT) — never writes to SAP.
--
-- IMPORTANT: the table/column names below are BEST-GUESS and MUST be confirmed
-- against your schema. Run a discovery query first (see readings_discover_sqlsrv.sql
-- for the SQL Server equivalent) then adjust the right-hand side of each "AS".
-- Replace "DAMASCUS_BAKERY" with your company schema if different.
--
-- Shown: batch-master shelf-life from B1 OBTN (batch numbers + expiry). Add a
-- UNION ALL for silo/tank level readings if you keep them in a UDT.
-- ===========================================================================

SELECT
    TO_VARCHAR(B."DistNumber")                                 AS source_key,
    'batch'                                                    AS reading_type,
    B."WhsCode"                                                AS location_code,
    WH."WhsName"                                               AS location_name,
    B."ItemCode"                                               AS item_code,
    OI."ItemName"                                              AS item_description,
    B."DistNumber"                                             AS batch_number,
    NULL                                                       AS reading_value,
    NULL                                                       AS unit_of_measure,
    NULL                                                       AS min_threshold,
    NULL                                                       AS max_threshold,
    B."Status"                                                 AS status,
    B."InDate"                                                 AS reading_at,
    B."ExpDate"                                                AS expiry_date
FROM "DAMASCUS_BAKERY"."OBTN" B
    LEFT JOIN "DAMASCUS_BAKERY"."OITM" OI ON OI."ItemCode" = B."ItemCode"
    LEFT JOIN "DAMASCUS_BAKERY"."OWHS" WH ON WH."WhsCode" = B."WhsCode"
ORDER BY B."ExpDate";
