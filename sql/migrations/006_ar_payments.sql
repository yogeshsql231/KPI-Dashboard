-- Migration 006: A/R payment cache for the "Late Deliveries vs Late Payments"
-- report.
--
-- One row per A/R invoice (SAP OINV), with the date the customer's payment was
-- actually received (latest incoming payment applied via RCT2/ORCT). The report
-- pairs, per month, how many deliveries went out late against how much invoiced
-- money was received AFTER the due date.
--
--   invoice_date   = OINV.DocDate     (used as the month dimension)
--   due_date       = OINV.DocDueDate  (payment is "late" when received after this)
--   paid_date      = MAX applied ORCT.DocDate (NULL while still open/unpaid)
--   invoice_amount = OINV.DocTotal
--   paid_amount    = OINV.PaidToDate  (amount settled so far)
--
-- "Days late" and the late-paid $ are derived at query time (DATEDIFF against a
-- configurable grace period) in vw_ar_payments / PaymentRepository, so the grace
-- window can change without re-running the ETL. No SAP data is written.
-- Safe to re-run (IF NOT EXISTS + idempotent upsert on source key).

USE kpi_dashboard;

CREATE TABLE IF NOT EXISTS ar_payments (
    id              INT UNSIGNED  NOT NULL AUTO_INCREMENT,
    source_system   VARCHAR(32)   NOT NULL DEFAULT 'PRODHANA',
    source_key      VARCHAR(128)  NOT NULL,          -- SAP OINV.DocEntry
    invoice_num     VARCHAR(50)   NULL,              -- OINV.DocNum
    customer_code   VARCHAR(50)   NULL,
    customer_name   VARCHAR(255)  NULL,
    invoice_date    DATE          NULL,              -- OINV.DocDate
    due_date        DATE          NULL,              -- OINV.DocDueDate
    paid_date       DATE          NULL,              -- latest applied payment date (NULL = open)
    invoice_amount  DECIMAL(18,4) NOT NULL DEFAULT 0,
    paid_amount     DECIMAL(18,4) NOT NULL DEFAULT 0,
    refreshed_at    TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_arp_source (source_system, source_key),
    KEY idx_arp_dates (invoice_date, due_date, paid_date)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- Normalised view: flags a received-late payment and exposes days_late so the
-- dashboard math is a plain SUM/AVG. A payment counts as "received late" only
-- when it was actually received (paid_date IS NOT NULL) AND landed after the
-- due date. days_late is NULL for still-open invoices.
CREATE OR REPLACE VIEW vw_ar_payments AS
SELECT
    ap.*,
    CASE WHEN ap.paid_date IS NOT NULL
         THEN DATEDIFF(ap.paid_date, ap.due_date) END AS days_late,
    CASE WHEN ap.paid_date IS NOT NULL AND ap.paid_date > ap.due_date
         THEN 1 ELSE 0 END                            AS paid_late_flag
FROM ar_payments ap;
