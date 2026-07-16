<?php

declare(strict_types=1);

require_once __DIR__ . '/DeliveryFilters.php';

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
        $base = DeliveryFilters::customerBaseExpr(
            "COALESCE(NULLIF(customer_name, ''), customer_code, 'Unknown')"
        );
        $stmt = $this->pdo->prepare(
            "SELECT
                MIN($base) AS customer,
                COALESCE(SUM(CASE WHEN paid_date IS NOT NULL
                                   AND DATEDIFF(paid_date, due_date) > $g
                                  THEN paid_amount ELSE 0 END), 0)      AS paid_late,
                AVG(CASE WHEN paid_date IS NOT NULL
                          AND DATEDIFF(paid_date, due_date) > $g
                         THEN DATEDIFF(paid_date, due_date) END)        AS avg_days_late,
                MAX(CASE WHEN paid_date IS NOT NULL
                          AND DATEDIFF(paid_date, due_date) > $g
                         THEN paid_date END)                            AS last_paid_date,
                SUM(CASE WHEN paid_date IS NOT NULL
                          AND DATEDIFF(paid_date, due_date) > $g
                         THEN 1 ELSE 0 END)                             AS late_invoices
             FROM ar_payments
             WHERE $where
             GROUP BY UPPER($base)
             HAVING paid_late > 0
             ORDER BY paid_late DESC
             LIMIT $limit"
        );
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /** Ordered aging bucket labels shared by arAging() and the Overview UI. */
    public const AGING_BUCKETS = ['Current', '1–30', '31–60', '61–90', '90+'];

    /**
     * AR aging snapshot of OPEN invoices (balance still owed), bucketed by
     * days past due as of today. Point-in-time — ignores the page date range.
     *
     * @return array<string, array{invoices:int, open_amount:float}> keyed by bucket
     */
    public function arAging(): array
    {
        $rows = $this->pdo->query(
            "SELECT
                CASE WHEN due_date IS NULL OR DATEDIFF(CURDATE(), due_date) <= 0 THEN 'Current'
                     WHEN DATEDIFF(CURDATE(), due_date) <= 30 THEN '1–30'
                     WHEN DATEDIFF(CURDATE(), due_date) <= 60 THEN '31–60'
                     WHEN DATEDIFF(CURDATE(), due_date) <= 90 THEN '61–90'
                     ELSE '90+' END                                          AS bucket,
                COUNT(*)                                                     AS invoices,
                COALESCE(SUM(invoice_amount - paid_amount), 0)               AS open_amount
             FROM ar_payments
             WHERE invoice_amount - paid_amount > 0.005
             GROUP BY bucket"
        )->fetchAll();
        $out = [];
        foreach (self::AGING_BUCKETS as $b) {
            $out[$b] = ['invoices' => 0, 'open_amount' => 0.0];
        }
        foreach ($rows as $r) {
            $out[(string) $r['bucket']] = [
                'invoices'    => (int) $r['invoices'],
                'open_amount' => (float) $r['open_amount'],
            ];
        }
        return $out;
    }

    /**
     * Customers with the largest open A/R balance, oldest past-due first on ties.
     *
     * @return array<int, array<string, mixed>>
     */
    public function topOpenAr(int $limit = 5): array
    {
        $limit = max(1, min(100, $limit));
        $base = DeliveryFilters::customerBaseExpr(
            "COALESCE(NULLIF(customer_name, ''), customer_code, 'Unknown')"
        );
        return $this->pdo->query(
            "SELECT
                MIN($base) AS customer,
                COUNT(*)                                                      AS invoices,
                COALESCE(SUM(invoice_amount - paid_amount), 0)                AS open_amount,
                MAX(CASE WHEN due_date IS NOT NULL AND due_date < CURDATE()
                         THEN DATEDIFF(CURDATE(), due_date) ELSE 0 END)       AS oldest_days_past_due
             FROM ar_payments
             WHERE invoice_amount - paid_amount > 0.005
             GROUP BY UPPER($base)
             ORDER BY open_amount DESC, oldest_days_past_due DESC
             LIMIT $limit"
        )->fetchAll();
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
