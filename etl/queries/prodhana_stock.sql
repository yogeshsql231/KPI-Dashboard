-- ===========================================================================
-- PRODHANA (SAP B1 on HANA, schema DAMASCUS_BAKERY) -> warehouse_stock.
--
-- HANA variant of primsbm_stock.sql for the PRODHANA linked-server bridge:
--   php etl/pull_inventory.php --what=stock --source=PRIMSBM \
--       --query=etl/queries/prodhana_stock.sql --via=PRODHANA
--
-- On-hand stock per item x warehouse from OITW joined to OITM/OITB/OWHS.
-- Pallets derived from SalPackUn when maintained (adjust to your real UDF
-- if Beas stores cases-per-pallet elsewhere). READ-ONLY (SELECT).
-- ===========================================================================

SELECT
    W."ItemCode" || '|' || W."WhsCode"                  AS source_key,
    W."ItemCode"                                        AS item_code,
    I."ItemName"                                        AS item_description,
    COALESCE(WH."WhsName", W."WhsCode")                 AS warehouse,
    W."OnHand"                                          AS on_hand,
    W."IsCommited"                                      AS committed,
    W."OnOrder"                                         AS on_order,
    I."InvntryUom"                                      AS unit_of_measure,
    CASE
        WHEN I."SalPackUn" IS NOT NULL AND I."SalPackUn" > 0
        THEN W."OnHand" / I."SalPackUn"
        ELSE NULL
    END                                                 AS pallets,
    CASE
        WHEN UPPER(G."ItmsGrpNam") LIKE '%FROZEN%' THEN 'Frozen'
        WHEN UPPER(G."ItmsGrpNam") LIKE '%FRESH%'  THEN 'Fresh'
        WHEN UPPER(G."ItmsGrpNam") LIKE '%DRY%'    THEN 'Dry'
        ELSE NULL
    END                                                 AS product_type,
    G."ItmsGrpNam"                                      AS category
FROM "DAMASCUS_BAKERY"."OITW" W
    INNER JOIN "DAMASCUS_BAKERY"."OITM" I ON I."ItemCode" = W."ItemCode"
    LEFT JOIN "DAMASCUS_BAKERY"."OITB" G ON G."ItmsGrpCod" = I."ItmsGrpCod"
    LEFT JOIN "DAMASCUS_BAKERY"."OWHS" WH ON WH."WhsCode" = W."WhsCode"
WHERE W."OnHand" <> 0;
