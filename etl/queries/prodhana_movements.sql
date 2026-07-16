-- ===========================================================================
-- PRODHANA (SAP B1 on HANA, schema DAMASCUS_BAKERY) -> material_movements.
--
-- HANA variant of primsbm_movements.sql for the PRODHANA linked-server bridge:
--   php etl/pull_inventory.php --what=movements --source=PRIMSBM \
--       --query=etl/queries/prodhana_movements.sql --via=PRODHANA
--
-- Stage flow reconstructed from the OINM inventory journal. movement_type:
--   receipt / transfer / issue / waste ('other' rows are skipped by the ETL).
-- IMPORTANT: confirm your waste warehouse code(s) and TransType usage.
-- SAP B1 TransType reference: 20=Goods Receipt PO, 59=Goods Receipt,
-- 60=Goods Issue, 67=Stock Transfer. READ-ONLY (SELECT).
-- ===========================================================================

SELECT
    TO_VARCHAR(N."TransType") || '|' || TO_VARCHAR(N."CreatedBy")
        || '|' || TO_VARCHAR(N."TransNum")              AS source_key,
    CASE
        WHEN N."Warehouse" IN ('WASTE', 'SCRAP')        THEN 'waste'
        WHEN N."TransType" IN (20, 59) AND N."InQty" > 0 THEN 'receipt'
        WHEN N."TransType" = 67                          THEN 'transfer'
        WHEN N."TransType" = 60 AND N."OutQty" > 0       THEN 'issue'
        ELSE 'other'
    END                                                 AS movement_type,
    N."DocDate"                                         AS doc_date,
    N."ItemCode"                                        AS item_code,
    I."ItemName"                                        AS item_description,
    CASE WHEN N."OutQty" > 0 THEN N."Warehouse" END     AS from_warehouse,
    CASE WHEN N."InQty"  > 0 THEN N."Warehouse" END     AS to_warehouse,
    CASE WHEN N."InQty" > 0 THEN N."InQty" ELSE N."OutQty" END AS quantity,
    I."InvntryUom"                                      AS unit_of_measure
FROM "DAMASCUS_BAKERY"."OINM" N
    INNER JOIN "DAMASCUS_BAKERY"."OITM" I ON I."ItemCode" = N."ItemCode"
WHERE N."DocDate" >= ADD_MONTHS(CURRENT_DATE, -12)
  AND N."TransType" IN (20, 59, 60, 67);
