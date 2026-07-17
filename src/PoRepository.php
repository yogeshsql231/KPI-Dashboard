<?php

declare(strict_types=1);

require_once __DIR__ . '/DeliveryFilters.php';

/**
 * Read-only access to the purchase-order cache (`po_lines` / `vw_po_lines`)
 * refreshed by etl/pull_po.php. Powers the Procurement dashboard's Supplier
 * OTIF widget (SCRUM-88).
 *
 * Supplier OTIF is measured at the WHOLE-PO level, mirroring the sales-side
 * order OTIF (SCRUM-86): a PO counts as on-time-in-full only when EVERY line
 * passes (MIN(otif_flag) per po_number). Cancelled POs (otif_flag IS NULL)
 * are excluded. Every query is parameterised.
 */
final class PoRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    /** True once the PO ETL has loaded at least one row. */
    public function hasData(): bool
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM po_lines')->fetchColumn() > 0;
    }

    /**
     * WHERE fragment + params for the supplier / warehouse / date filters.
     *
     * @return array{0:string,1:array<int,mixed>}
     */
    private function clause(?string $supplier, ?string $warehouse, ?string $from, ?string $to): array
    {
        $conds = ['1 = 1'];
        $params = [];
        if ($supplier !== null && $supplier !== '') {
            $conds[] = 'std_supplier = ?';
            $params[] = $supplier;
        }
        if ($warehouse !== null && $warehouse !== '') {
            [$whSql, $whParams] = DeliveryFilters::warehouseCondition('warehouse', $warehouse);
            $conds[] = $whSql;
            foreach ($whParams as $p) {
                $params[] = $p;
            }
        }
        if ($from !== null && $from !== '') {
            $conds[] = 'posting_date >= ?';
            $params[] = $from;
        }
        if ($to !== null && $to !== '') {
            $conds[] = 'posting_date <= ?';
            $params[] = $to;
        }
        return [implode(' AND ', $conds), $params];
    }

    /**
     * Headline Supplier OTIF: % of POs received on-time AND in-full, at the
     * whole-PO level. Cancelled POs excluded.
     *
     * @return array{total_pos:int,otif_pos:int,otif_rate:float|null}
     */
    public function supplierOtif(?string $supplier, ?string $warehouse, ?string $from, ?string $to): array
    {
        [$where, $params] = $this->clause($supplier, $warehouse, $from, $to);
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) AS total_pos, COALESCE(SUM(o.po_otif), 0) AS otif_pos
             FROM (
                 SELECT po_number, MIN(otif_flag) AS po_otif
                 FROM vw_po_lines
                 WHERE $where AND otif_flag IS NOT NULL
                 GROUP BY po_number
             ) o"
        );
        $stmt->execute($params);
        $row = $stmt->fetch() ?: ['total_pos' => 0, 'otif_pos' => 0];
        $total = (int) $row['total_pos'];
        $otif  = (int) $row['otif_pos'];
        return [
            'total_pos' => $total,
            'otif_pos'  => $otif,
            'otif_rate' => $total > 0 ? round($otif / $total * 100, 2) : null,
        ];
    }

    /**
     * Supplier OTIF broken down per supplier (worst first), for the table.
     *
     * @return array<int, array<string, mixed>>
     */
    public function bySupplier(?string $supplier, ?string $warehouse, ?string $from, ?string $to, int $limit = 50): array
    {
        [$where, $params] = $this->clause($supplier, $warehouse, $from, $to);
        $stmt = $this->pdo->prepare(
            "SELECT o.std_supplier AS supplier,
                    COUNT(*)                         AS total_pos,
                    COALESCE(SUM(o.po_otif), 0)      AS otif_pos,
                    ROUND(COALESCE(SUM(o.po_otif), 0) / COUNT(*) * 100, 2) AS otif_rate,
                    SUM(o.po_value)                  AS po_value
             FROM (
                 SELECT std_supplier, po_number,
                        MIN(otif_flag)                 AS po_otif,
                        COALESCE(SUM(line_amount), 0)  AS po_value
                 FROM vw_po_lines
                 WHERE $where AND otif_flag IS NOT NULL
                 GROUP BY std_supplier, po_number
             ) o
             GROUP BY o.std_supplier
             ORDER BY otif_rate ASC, total_pos DESC
             LIMIT " . (int) $limit
        );
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Rolling weekly Supplier OTIF % (by PO entry week) for the trend strip.
     * The posting-date range filter is replaced by the rolling window.
     *
     * @return array<int, array<string, mixed>>
     */
    public function weeklyTrend(?string $supplier, ?string $warehouse, int $weeks = 8): array
    {
        [$where, $params] = $this->clause($supplier, $warehouse, null, null);
        $days = max(1, $weeks) * 7;
        $stmt = $this->pdo->prepare(
            "SELECT o.yw,
                    MIN(o.week_start) AS week_start,
                    COUNT(*) AS total_pos,
                    ROUND(COALESCE(SUM(o.po_otif), 0) / COUNT(*) * 100, 2) AS otif_rate
             FROM (
                 SELECT YEARWEEK(posting_date, 3) AS yw,
                        MIN(posting_date)         AS week_start,
                        po_number,
                        MIN(otif_flag)            AS po_otif
                 FROM vw_po_lines
                 WHERE $where AND otif_flag IS NOT NULL
                   AND posting_date IS NOT NULL
                   AND posting_date >= DATE_SUB(CURDATE(), INTERVAL $days DAY)
                 GROUP BY YEARWEEK(posting_date, 3), po_number
             ) o
             GROUP BY o.yw
             ORDER BY o.yw"
        );
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Distinct suppliers / warehouses for the filter dropdowns.
     *
     * @return array<int, string>
     */
    public function options(string $column): array
    {
        $allowed = ['supplier' => 'std_supplier', 'warehouse' => 'warehouse'];
        if (!isset($allowed[$column])) {
            return [];
        }
        $col = $allowed[$column];
        $rows = $this->pdo->query(
            "SELECT DISTINCT $col FROM vw_po_lines
             WHERE $col IS NOT NULL AND $col <> '' ORDER BY $col"
        )->fetchAll(PDO::FETCH_COLUMN);
        return array_map('strval', $rows);
    }

    /** Timestamp of the most recent PO refresh, or null. */
    public function lastRefreshed(): ?string
    {
        $v = $this->pdo->query('SELECT MAX(refreshed_at) FROM po_lines')->fetchColumn();
        return $v ?: null;
    }
}
