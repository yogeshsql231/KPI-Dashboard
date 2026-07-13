-- Sample daily on-hand snapshots for the Stockout Frequency widget (SCRUM-93).
--
-- OPTIONAL / DEMO ONLY. Synthesises 14 days of on-hand history for a handful of
-- SKUs across two warehouses so the Warehouse dashboard's Stockout Frequency
-- panel can be previewed before etl/pull_stock_snapshot.php is wired to SAP.
-- Some SKUs deliberately dip to zero on-hand (stockouts); ITEM-DISC is marked
-- inactive to show it is excluded from the denominator. The figures are
-- invented -- do NOT treat them as real. Rows are tagged source_system =
-- 'SAMPLE' so a real PRIMSBM/PRODHANA pull never collides with them.
--
-- To preview:  mysql -u root kpi_dashboard < sql/warehouse_stock_snapshots_sample.sql
-- To remove:   DELETE FROM inventory_stock_snapshots WHERE source_system = 'SAMPLE';

USE kpi_dashboard;

-- Re-runnable: clear any previous sample rows first (the real ETL never uses
-- source_system = 'SAMPLE', so this only ever removes demo data).
DELETE FROM inventory_stock_snapshots WHERE source_system = 'SAMPLE';

INSERT INTO inventory_stock_snapshots
    (source_system, snapshot_date, item_code, item_description, warehouse,
     on_hand, is_active, product_type, category)
WITH RECURSIVE days (d) AS (
    SELECT DATE_SUB(CURDATE(), INTERVAL 13 DAY)
    UNION ALL
    SELECT d + INTERVAL 1 DAY FROM days WHERE d < CURDATE()
),
skus (item_code, item_description, warehouse, product_type, category, is_active, base_qty) AS (
    SELECT 'ITEM-PITA-01', 'Pita Bread 12ct',      'Main WH',   'Fresh',  'Bread',      1, 120 UNION ALL
    SELECT 'ITEM-FLOUR-01','Bread Flour 25kg',     'Main WH',   'Dry',    'Ingredient', 1, 800 UNION ALL
    SELECT 'ITEM-WRAP-01', 'Wrap Frozen 10in',     'Frozen WH', 'Frozen', 'Bread',      1, 60  UNION ALL
    SELECT 'ITEM-YEAST-01','Active Yeast 500g',    'Main WH',   'Dry',    'Ingredient', 1, 40  UNION ALL
    SELECT 'ITEM-DISC-01', 'Seasonal Loaf (disc.)','Main WH',   'Fresh',  'Bread',      0, 0
)
SELECT
    'SAMPLE',
    days.d,
    skus.item_code,
    skus.item_description,
    skus.warehouse,
    -- Per-SKU on-hand curve: some SKUs cross zero on set days to create stockouts.
    CASE skus.item_code
        -- Pita: sells down and stocks out on 3 days of the window.
        WHEN 'ITEM-PITA-01'  THEN GREATEST(0, skus.base_qty - (DATEDIFF(days.d, DATE_SUB(CURDATE(), INTERVAL 13 DAY)) % 5) * 30)
        -- Flour: healthy, never zero.
        WHEN 'ITEM-FLOUR-01' THEN skus.base_qty - (DATEDIFF(days.d, DATE_SUB(CURDATE(), INTERVAL 13 DAY)) * 10)
        -- Frozen wrap: chronic stockouts (zero on ~half the days).
        WHEN 'ITEM-WRAP-01'  THEN CASE WHEN DATEDIFF(days.d, DATE_SUB(CURDATE(), INTERVAL 13 DAY)) % 2 = 0 THEN 0 ELSE 45 END
        -- Yeast: a single stockout day.
        WHEN 'ITEM-YEAST-01' THEN CASE WHEN days.d = DATE_SUB(CURDATE(), INTERVAL 4 DAY) THEN 0 ELSE 25 END
        -- Discontinued: at zero, but inactive so excluded from the denominator.
        ELSE 0
    END,
    skus.is_active,
    skus.product_type,
    skus.category
FROM days
CROSS JOIN skus;
