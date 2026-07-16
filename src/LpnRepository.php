<?php

declare(strict_types=1);

/**
 * Read-only access to the LPN (License Plate Number) pallet cache
 * (`lpn_pallets` / `vw_lpn_pallets`) that the WMS ETL (etl/pull_lpn.php)
 * refreshes. Powers the Warehouse dashboard's LPN panel so the warehouse team
 * can see pallets, their contents, locations and status.
 *
 * Every query is parameterised. Filters reuse warehouse + item + free-text
 * search so the panel behaves like the rest of the dashboard.
 */
final class LpnRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    /** True once the WMS ETL has loaded at least one LPN row. */
    public function hasData(): bool
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM lpn_pallets')->fetchColumn() > 0;
    }

    /**
     * Build a WHERE fragment + params from the shared delivery filters. Only
     * the fields that make sense for LPNs are applied (warehouse, item, and a
     * free-text search that also matches the LPN / batch / bin).
     *
     * @return array{0:string,1:array<int,mixed>}
     */
    private function clause(DeliveryFilters $f, ?string $status = null): array
    {
        $conds = ['1 = 1'];
        $params = [];

        if ($f->warehouse !== null) {
            [$whSql, $whParams] = DeliveryFilters::warehouseCondition('std_warehouse', $f->warehouse);
            $conds[] = $whSql;
            foreach ($whParams as $p) {
                $params[] = $p;
            }
        }
        if ($f->item !== null) {
            $conds[] = '(item_code LIKE ? OR item_description LIKE ? OR lpn LIKE ? OR batch_number LIKE ? OR bin_location LIKE ?)';
            $like = '%' . $f->item . '%';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }
        if ($status !== null && $status !== '') {
            $conds[] = 'std_status = ?';
            $params[] = $status;
        }

        return [implode(' AND ', $conds), $params];
    }

    /** Headline counts across the (filtered) LPN set. */
    public function summary(DeliveryFilters $f): array
    {
        [$where, $params] = $this->clause($f);
        $stmt = $this->pdo->prepare(
            "SELECT
                 COUNT(*)                              AS pallets,
                 COUNT(DISTINCT std_warehouse)         AS warehouses,
                 COUNT(DISTINCT item_code)             AS items,
                 COALESCE(SUM(quantity), 0)            AS total_qty,
                 COALESCE(SUM(is_expired), 0)          AS expired
             FROM vw_lpn_pallets
             WHERE $where"
        );
        $stmt->execute($params);
        return $stmt->fetch() ?: [
            'pallets' => 0, 'warehouses' => 0, 'items' => 0, 'total_qty' => 0, 'expired' => 0,
        ];
    }

    /**
     * LPN counts + quantity grouped by warehouse (for the warehouse team's
     * at-a-glance view of where pallets are sitting).
     *
     * @return array<int, array<string, mixed>>
     */
    public function byWarehouse(DeliveryFilters $f): array
    {
        [$where, $params] = $this->clause($f);
        $stmt = $this->pdo->prepare(
            "SELECT std_warehouse AS warehouse,
                    COUNT(*)                     AS pallets,
                    COALESCE(SUM(quantity), 0)   AS total_qty,
                    COALESCE(SUM(is_expired), 0) AS expired
             FROM vw_lpn_pallets
             WHERE $where
             GROUP BY std_warehouse
             ORDER BY pallets DESC"
        );
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Pallet counts + quantity grouped by warehouse AND status, feeding the
     * Overview's pallets-by-location widget (pictogram / bar views).
     *
     * @return array<int, array<string, mixed>>
     */
    public function byWarehouseStatus(DeliveryFilters $f): array
    {
        [$where, $params] = $this->clause($f);
        $stmt = $this->pdo->prepare(
            "SELECT std_warehouse AS warehouse,
                    COALESCE(NULLIF(std_status, ''), 'Unknown') AS status,
                    COUNT(*)                     AS pallets,
                    COALESCE(SUM(quantity), 0)   AS total_qty,
                    COALESCE(SUM(is_expired), 0) AS expired,
                    SUM(CASE WHEN received_date IS NOT NULL
                              AND received_date < DATE_SUB(CURDATE(), INTERVAL 30 DAY)
                             THEN 1 ELSE 0 END)  AS aged_30d
             FROM vw_lpn_pallets
             WHERE $where
             GROUP BY std_warehouse, COALESCE(NULLIF(std_status, ''), 'Unknown')
             ORDER BY std_warehouse, status"
        );
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Weekly received-pallet counts per warehouse over the last N weeks, for
     * the Overview on-hand tile's week-over-week delta.
     *
     * @return array<int, array<string, mixed>>
     */
    public function weeklyByWarehouse(DeliveryFilters $f, int $weeks = 6): array
    {
        [$where, $params] = $this->clause($f);
        $days = max(1, (int) $weeks) * 7;
        $stmt = $this->pdo->prepare(
            "SELECT std_warehouse AS warehouse,
                    YEARWEEK(received_date, 3) AS yw,
                    COUNT(*)                   AS pallets
             FROM vw_lpn_pallets
             WHERE $where
               AND received_date IS NOT NULL
               AND received_date >= DATE_SUB(CURDATE(), INTERVAL $days DAY)
             GROUP BY std_warehouse, YEARWEEK(received_date, 3)
             ORDER BY warehouse, yw"
        );
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * The LPN detail rows themselves (most recent first), for the searchable
     * pallet table.
     *
     * @return array<int, array<string, mixed>>
     */
    public function rows(DeliveryFilters $f, int $limit = 200, ?string $status = null): array
    {
        [$where, $params] = $this->clause($f, $status);
        $stmt = $this->pdo->prepare(
            "SELECT lpn, std_status AS status, std_warehouse AS warehouse, bin_location,
                    item_code, item_description, batch_number, quantity, unit_of_measure,
                    received_date, expiry_date, is_expired
             FROM vw_lpn_pallets
             WHERE $where
             ORDER BY (received_date IS NULL), received_date DESC, lpn
             LIMIT " . (int) $limit
        );
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Distinct values for a filterable column (e.g. status), for dropdowns.
     *
     * @return array<int, string>
     */
    public function options(string $column): array
    {
        $allowed = [
            'status'    => 'std_status',
            'warehouse' => 'std_warehouse',
        ];
        if (!isset($allowed[$column])) {
            return [];
        }
        $col = $allowed[$column];
        $rows = $this->pdo->query(
            "SELECT DISTINCT $col FROM vw_lpn_pallets
             WHERE $col IS NOT NULL AND $col <> '' ORDER BY $col"
        )->fetchAll(PDO::FETCH_COLUMN);
        return array_map('strval', $rows);
    }

    /** Timestamp of the most recent WMS refresh, or null. */
    public function lastRefreshed(): ?string
    {
        $v = $this->pdo->query('SELECT MAX(refreshed_at) FROM lpn_pallets')->fetchColumn();
        return $v ?: null;
    }
}
