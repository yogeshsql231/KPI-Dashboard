<?php

declare(strict_types=1);

/**
 * Read-only queries for the A/R side of the "Late Deliveries vs Late Payments"
 * report. Reads the ar_payments cache (see migration 006 / vw_ar_payments).
 *
 * "Received late" = a payment that was actually received (paid_date IS NOT NULL)
 * and landed MORE than $graceDays after the invoice due date. The grace period
 * is applied at query time so it can be tuned without re-running the ETL
 * (default 0 = any payment after the due date counts as late).
 */
final class PaymentRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * Period totals for the KPI cards.
     *
     * @return array<string, mixed>
     */
    public function summary(?string $from, ?string $to, int $graceDays = 0): array
    {
        // $graceDays is a validated int (safe to inline); named placeholders
        // can't be reused when emulated prepares are off, so we interpolate it.
        $g = (int) $graceDays;
        [$where, $params] = $this->range($from, $to);
        $stmt = $this->pdo->prepare(
            "SELECT
                COALESCE(SUM(invoice_amount), 0)                        AS invoiced,
                COALESCE(SUM(CASE WHEN paid_date IS NOT NULL
                                   AND DATEDIFF(paid_date, due_date) > $g
                                  THEN paid_amount ELSE 0 END), 0)      AS paid_late,
                SUM(CASE WHEN paid_date IS NOT NULL
                          AND DATEDIFF(paid_date, due_date) > $g
                         THEN 1 ELSE 0 END)                             AS late_invoices,
                COUNT(*)                                                AS invoices,
                AVG(CASE WHEN paid_date IS NOT NULL
                          AND DATEDIFF(paid_date, due_date) > $g
                         THEN DATEDIFF(paid_date, due_date) END)        AS avg_days_late
             FROM ar_payments
             WHERE $where"
        );
        $stmt->execute($params);
        return $stmt->fetch() ?: [];
    }

    /**
     * Late-payment $ grouped by invoice month (YYYY-MM).
     *
     * @return array<string, array<string, mixed>> keyed by month
     */
    public function byMonth(?string $from, ?string $to, int $graceDays = 0): array
    {
        $g = (int) $graceDays;
        [$where, $params] = $this->range($from, $to);
        $stmt = $this->pdo->prepare(
            "SELECT
                DATE_FORMAT(invoice_date, '%Y-%m')                      AS ym,
                COALESCE(SUM(invoice_amount), 0)                        AS invoiced,
                COALESCE(SUM(CASE WHEN paid_date IS NOT NULL
                                   AND DATEDIFF(paid_date, due_date) > $g
                                  THEN paid_amount ELSE 0 END), 0)      AS paid_late,
                SUM(CASE WHEN paid_date IS NOT NULL
                          AND DATEDIFF(paid_date, due_date) > $g
                         THEN 1 ELSE 0 END)                             AS late_invoices,
                AVG(CASE WHEN paid_date IS NOT NULL
                          AND DATEDIFF(paid_date, due_date) > $g
                         THEN DATEDIFF(paid_date, due_date) END)        AS avg_days_late
             FROM ar_payments
             WHERE $where AND invoice_date IS NOT NULL
             GROUP BY DATE_FORMAT(invoice_date, '%Y-%m')
             ORDER BY ym"
        );
        $stmt->execute($params);
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $out[(string) $r['ym']] = $r;
        }
        return $out;
    }

    /**
     * Customers driving the late-paid $.
     *
     * @return array<int, array<string, mixed>>
     */
    public function topLatePayers(?string $from, ?string $to, int $graceDays = 0, int $limit = 10): array
    {
        $g = (int) $graceDays;
        [$where, $params] = $this->range($from, $to);
        $limit = max(1, min(100, $limit));
        $stmt = $this->pdo->prepare(
            "SELECT
                COALESCE(NULLIF(customer_name, ''), customer_code, 'Unknown') AS customer,
                COALESCE(SUM(CASE WHEN paid_date IS NOT NULL
                                   AND DATEDIFF(paid_date, due_date) > $g
                                  THEN paid_amount ELSE 0 END), 0)      AS paid_late,
                AVG(CASE WHEN paid_date IS NOT NULL
                          AND DATEDIFF(paid_date, due_date) > $g
                         THEN DATEDIFF(paid_date, due_date) END)        AS avg_days_late,
                SUM(CASE WHEN paid_date IS NOT NULL
                          AND DATEDIFF(paid_date, due_date) > $g
                         THEN 1 ELSE 0 END)                             AS late_invoices
             FROM ar_payments
             WHERE $where
             GROUP BY COALESCE(NULLIF(customer_name, ''), customer_code, 'Unknown')
             HAVING paid_late > 0
             ORDER BY paid_late DESC
             LIMIT $limit"
        );
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /** True when the ar_payments cache has any rows (drives the empty-state UI). */
    public function hasData(): bool
    {
        try {
            return (int) $this->pdo->query('SELECT COUNT(*) FROM ar_payments')->fetchColumn() > 0;
        } catch (Throwable) {
            return false;
        }
    }

    /** Most recent cache refresh timestamp, or null. */
    public function lastRefreshed(): ?string
    {
        try {
            $v = $this->pdo->query('SELECT MAX(refreshed_at) FROM ar_payments')->fetchColumn();
            return $v !== false && $v !== null ? (string) $v : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Build the invoice_date range WHERE fragment + params.
     *
     * @return array{0:string,1:array<string,mixed>}
     */
    private function range(?string $from, ?string $to): array
    {
        $conds = ['1=1'];
        $params = [];
        if ($from !== null && $from !== '') {
            $conds[] = 'invoice_date >= :from';
            $params[':from'] = $from;
        }
        if ($to !== null && $to !== '') {
            $conds[] = 'invoice_date <= :to';
            $params[':to'] = $to;
        }
        return [implode(' AND ', $conds), $params];
    }
}
