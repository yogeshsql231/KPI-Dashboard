-- Sample A/R payment data for the "Late Deliveries vs Late Payments" report.
--
-- OPTIONAL / DEMO ONLY. This synthesises one invoice per sales order from the
-- delivery cache so the report can be previewed before the real A/R payment ETL
-- (etl/pull_payments.php) is wired to SAP. It invents payment dates -- do NOT
-- treat these figures as real. Rows are tagged source_system = 'SAMPLE' so a
-- real PRODHANA/PRIMSBM pull never collides with them.
--
-- To preview:  mysql -u root kpi_dashboard < sql/ar_payments_sample.sql
-- To remove:   DELETE FROM ar_payments WHERE source_system = 'SAMPLE';

USE kpi_dashboard;

INSERT INTO ar_payments
    (source_system, source_key, invoice_num, customer_code, customer_name,
     invoice_date, due_date, paid_date, invoice_amount, paid_amount)
SELECT
    'SAMPLE',
    sales_order,
    CONCAT('INV-', sales_order),
    MAX(customer_code),
    MAX(customer_name),
    MAX(posting_date)                                              AS invoice_date,
    -- Net-30 terms
    DATE_ADD(MAX(posting_date), INTERVAL 30 DAY)                   AS due_date,
    -- Deterministic pseudo-random settlement: -4 to +17 days vs due date, so a
    -- realistic mix of early/on-time and late payments across the months.
    DATE_ADD(DATE_ADD(MAX(posting_date), INTERVAL 30 DAY),
             INTERVAL (CAST(sales_order AS SIGNED) % 22 - 4) DAY) AS paid_date,
    ROUND(SUM(COALESCE(line_amount, 0)), 2)                        AS invoice_amount,
    ROUND(SUM(COALESCE(line_amount, 0)), 2)                        AS paid_amount
FROM delivery_lines
WHERE sales_order IS NOT NULL AND sales_order <> ''
GROUP BY sales_order
ON DUPLICATE KEY UPDATE
    invoice_date   = VALUES(invoice_date),
    due_date       = VALUES(due_date),
    paid_date      = VALUES(paid_date),
    invoice_amount = VALUES(invoice_amount),
    paid_amount    = VALUES(paid_amount);
