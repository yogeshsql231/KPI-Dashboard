<?php

declare(strict_types=1);

require_once __DIR__ . '/DeliveryFilters.php';

/**
 * Read-only queries for the customer-complaint charts on the Overview page:
 * count of complaints + dollar value lost (over time) and count by
 * type/reason. Only the date range from DeliveryFilters applies here (the
 * delivery-specific filters — warehouse, PO, pick status — do not map to a
 * complaint), so we build a small dedicated WHERE fragment.
 */
final class ComplaintRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * Date-range WHERE fragment + params over complaint_date.
     *
     * @return array{0:string,1:array<int,mixed>}
     */
    private function dateClause(DeliveryFilters $f): array
    {
        $conds = ['1 = 1'];
        $params = [];
        if ($f->fromDate !== null) {
            $conds[] = 'complaint_date >= ?';
            $params[] = $f->fromDate;
        }
        if ($f->toDate !== null) {
            $conds[] = 'complaint_date <= ?';
            $params[] = $f->toDate;
        }
        return [implode(' AND ', $conds), $params];
    }

    /** Totals: number of complaints + dollar value lost. @return array<string, mixed> */
    public function summary(DeliveryFilters $f): array
    {
        [$where, $params] = $this->dateClause($f);
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) AS complaints, COALESCE(SUM(lost_amount), 0) AS lost_amount
             FROM complaints WHERE $where"
        );
        $stmt->execute($params);
        return $stmt->fetch() ?: ['complaints' => 0, 'lost_amount' => 0];
    }

    /** Monthly trend: complaints count + lost $. @return array<int, array<string, mixed>> */
    public function byMonth(DeliveryFilters $f): array
    {
        [$where, $params] = $this->dateClause($f);
        $stmt = $this->pdo->prepare(
            "SELECT DATE_FORMAT(complaint_date, '%Y-%m') AS period,
                    COUNT(*) AS complaints,
                    COALESCE(SUM(lost_amount), 0) AS lost_amount
             FROM complaints
             WHERE $where AND complaint_date IS NOT NULL
             GROUP BY DATE_FORMAT(complaint_date, '%Y-%m')
             ORDER BY period"
        );
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /** Count by complaint type/reason. @return array<int, array<string, mixed>> */
    public function byReason(DeliveryFilters $f, int $limit = 10): array
    {
        [$where, $params] = $this->dateClause($f);
        $stmt = $this->pdo->prepare(
            "SELECT COALESCE(NULLIF(reason, ''), complaint_type, 'Unspecified') AS reason,
                    COUNT(*) AS complaints,
                    COALESCE(SUM(lost_amount), 0) AS lost_amount
             FROM complaints
             WHERE $where
             GROUP BY COALESCE(NULLIF(reason, ''), complaint_type, 'Unspecified')
             ORDER BY complaints DESC
             LIMIT " . (int) $limit
        );
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
}
