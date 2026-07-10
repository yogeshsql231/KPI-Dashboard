-- Migration 011: expose the SAP order fields (so_docentry, so_status,
-- warehouse) on vw_order_shipment_kpi.
--
-- The Customer Service dashboard filters on so_docentry and warehouse, but the
-- view's fixed SELECT list (defined before migration 002 added those columns)
-- never exposed them, so applying the SO-number filter took the whole page
-- down with "Unknown column 'so_docentry'". View-only change; no data touched.
-- Requires migration 002.

USE kpi_dashboard;

CREATE OR REPLACE VIEW vw_order_shipment_kpi AS
SELECT
    os.id,
    os.ship_date,
    os.po_number,
    os.so_docentry,
    os.so_status,
    os.warehouse,
    os.customer,
    os.ship_via,
    os.item_number,
    os.qty_requested,
    os.qty_shipped,
    os.order_date,
    os.requested_date,
    os.actual_date,
    os.is_sample,
    (os.qty_requested - os.qty_shipped)                         AS cases_short,
    GREATEST(os.qty_requested - os.qty_shipped, 0)              AS cases_short_pos,
    DATEDIFF(os.actual_date, os.requested_date)                 AS delay_days,
    DATEDIFF(os.actual_date, os.order_date)                     AS lead_time_days,
    CASE
        WHEN ((os.qty_requested - os.qty_shipped)
              + COALESCE(DATEDIFF(os.actual_date, os.requested_date), 0)) <= 1
        THEN 1 ELSE 0
    END                                                         AS otif_flag,
    CASE
        WHEN os.qty_requested > 0
        THEN LEAST(os.qty_shipped / os.qty_requested, 1.0)
        ELSE NULL
    END                                                         AS ifr
FROM order_shipments os;
