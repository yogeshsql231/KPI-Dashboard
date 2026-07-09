-- ===========================================================================
-- PRIMSBM (Microsoft SQL Server) -> operational_readings source query.
--
-- Feeds the Audit dashboard + email alerts (SCRUM-15) with operational
-- readings: silo / tank levels, batch-master shelf-life, process readings, etc.
-- READ-ONLY (SELECT) — never writes.
--
-- IMPORTANT: the table/column names below are BEST-GUESS and MUST be confirmed
-- against your database — run etl/queries/readings_discover_sqlsrv.sql first to
-- find the real names, then adjust the right-hand side of each "AS" mapping.
-- The left side (source_key ... expiry_date) must stay as-is so pull_readings.php
-- can map the columns.
--
-- Two common sources are shown; keep whichever applies (or UNION both):
--   A) Silo / tank level readings (a Beas or custom UDT with a level + limits).
--   B) Batch-master shelf-life from B1 OBTN (batch numbers + expiry).
-- ===========================================================================

-- A) Silo / tank level readings -------------------------------------------
SELECT
    CAST(S."U_SiloCode" AS VARCHAR(120))          AS source_key,
    'silo'                                        AS reading_type,
    S."U_SiloCode"                                AS location_code,
    S."U_SiloName"                                AS location_name,
    S."U_ItemCode"                                AS item_code,
    OI."ItemName"                                 AS item_description,
    NULL                                          AS batch_number,
    S."U_Level"                                   AS reading_value,
    S."U_UoM"                                     AS unit_of_measure,
    S."U_MinLevel"                                AS min_threshold,
    S."U_MaxLevel"                                AS max_threshold,
    S."U_Status"                                  AS status,
    S."U_ReadAt"                                  AS reading_at,
    NULL                                          AS expiry_date
FROM "@SILO_READINGS" S
    LEFT JOIN OITM OI ON OI."ItemCode" = S."U_ItemCode"

-- UNION ALL
-- -- B) Batch-master shelf-life (B1 OBTN) ---------------------------------
-- SELECT
--     CAST(B."DistNumber" AS VARCHAR(120))          AS source_key,
--     'batch'                                       AS reading_type,
--     B."WhsCode"                                   AS location_code,
--     WH."WhsName"                                  AS location_name,
--     B."ItemCode"                                  AS item_code,
--     OI."ItemName"                                 AS item_description,
--     B."DistNumber"                                AS batch_number,
--     NULL                                          AS reading_value,
--     NULL                                          AS unit_of_measure,
--     NULL                                          AS min_threshold,
--     NULL                                          AS max_threshold,
--     B."Status"                                    AS status,
--     B."InDate"                                    AS reading_at,
--     B."ExpDate"                                   AS expiry_date
-- FROM OBTN B
--     LEFT JOIN OITM OI ON OI."ItemCode" = B."ItemCode"
--     LEFT JOIN OWHS WH ON WH."WhsCode" = B."WhsCode"
;
