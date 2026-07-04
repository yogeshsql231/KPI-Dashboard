-- Sample complaint rows so the Overview complaint charts render before the
-- real complaint feed (SAP service calls / credit memos) is wired up.
-- SAMPLE / DEMO DATA ONLY — delete once the live feed is loaded:
--   DELETE FROM complaints WHERE source_system = 'SAMPLE';

USE kpi_dashboard;

INSERT INTO complaints
    (source_system, source_key, complaint_date, customer_code, customer_name, complaint_type, reason, sales_order, lost_amount, status)
VALUES
    ('SAMPLE', 'S-001', '2026-04-08', 'C0007', 'Metro Foods',        'Quality',   'Under-baked',        '10241',  420.00, 'Closed'),
    ('SAMPLE', 'S-002', '2026-04-15', 'C0012', 'Sunrise Grocers',    'Delivery',  'Late delivery',      '10255',  180.50, 'Closed'),
    ('SAMPLE', 'S-003', '2026-04-22', 'C0003', 'Gourmet Distributors','Packaging','Torn packaging',     '10262',   95.00, 'Open'),
    ('SAMPLE', 'S-004', '2026-05-02', 'C0007', 'Metro Foods',        'Quality',   'Stale product',      '10288',  610.00, 'Closed'),
    ('SAMPLE', 'S-005', '2026-05-09', 'C0021', 'Harbor Retail',      'Shortage',  'Short shipment',     '10301',  240.00, 'Closed'),
    ('SAMPLE', 'S-006', '2026-05-18', 'C0012', 'Sunrise Grocers',    'Delivery',  'Wrong item',         '10315',  130.00, 'Open'),
    ('SAMPLE', 'S-007', '2026-05-27', 'C0009', 'City Bakeshop',      'Quality',   'Under-baked',        '10329',  355.00, 'Closed'),
    ('SAMPLE', 'S-008', '2026-06-03', 'C0003', 'Gourmet Distributors','Packaging','Torn packaging',     '10344',  110.00, 'Closed'),
    ('SAMPLE', 'S-009', '2026-06-11', 'C0021', 'Harbor Retail',      'Shortage',  'Short shipment',     '10358',  275.00, 'Open'),
    ('SAMPLE', 'S-010', '2026-06-19', 'C0007', 'Metro Foods',        'Delivery',  'Late delivery',      '10371',  200.00, 'Closed'),
    ('SAMPLE', 'S-011', '2026-06-25', 'C0015', 'Prime Wholesale',    'Quality',   'Stale product',      '10388',  480.00, 'Closed'),
    ('SAMPLE', 'S-012', '2026-06-30', 'C0009', 'City Bakeshop',      'Packaging', 'Label error',        '10399',   60.00, 'Open')
ON DUPLICATE KEY UPDATE
    complaint_date = VALUES(complaint_date),
    lost_amount    = VALUES(lost_amount),
    status         = VALUES(status);
