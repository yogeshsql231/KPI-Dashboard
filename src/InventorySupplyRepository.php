<?php

declare(strict_types=1);

/**
 * Read-only access to the inventory days-of-supply cache (`inventory_supply` /
 * `vw_inventory_supply`) refreshed by etl/pull_inventory_supply.php. Powers
 * the Procurement dashboard's Inventory Days of Supply widget (SCRUM-92).
 *
 * Days of supply = on-hand / avg daily usage, where avg daily usage is the
 * trailing 30-day outbound quantity / 30 (PROVISIONAL — pending Raj sign-off).
 * Quantities are never summed across items (mixed UoMs); the KPI is reported
 * as item-warehouse counts per supply band instead. Inactive SKUs are
 * excluded. Every query is parameterised.
 */
final class InventorySupplyRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    /** True once the inventory-supply ETL has loaded at least one row. */
    public function hasData(): bool
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM inventory_supply')->fetchColumn() > 0;
    }

    /**
     * WHERE fragment + params for the category / warehouse / item filters.
     *
     * @return array{0:string,1:array<int,mixed>}
     */
    private function clause(?string $category, ?string $warehouse, ?string $item): array
    {
        $conds = ['is_active = 1'];
        $params = [];
        if ($category !== null && $category !== '') {
            $conds[] = 'std_category = ?';
            $params[] = $category;
        }
        if ($warehouse !== null && $warehouse !== '') {
            $conds[] = 'std_warehouse = ?';
            $params[] = $warehouse;
        }
        if ($item !== null && $item !== '') {
            $conds[] = '(item_code LIKE ? OR item_description LIKE ?)';
            $like = '%' . $item . '%';
            $params[] = $like;
            $params[] = $like;
        }
        return [implode(' AND ', $conds), $params];
    }

    /**
     * Headline bands: item-warehouse counts by days-of-supply range, plus the
     * moving SKUs that currently have zero on-hand and the no-usage bucket.
     *
     * @return array{critical:int,low:int,ok:int,healthy:int,stocked_out:int,no_usage:int,measured:int}
     */
    public function summary(?string $category, ?string $warehouse, ?string $item): array
    {
        [$where, $params] = $this->clause($category, $warehouse, $item);
        $stmt = $this->pdo->prepare(
            "SELECT
                COALESCE(SUM(CASE WHEN days_of_supply IS NOT NULL AND days_of_supply < 7  THEN 1 ELSE 0 END), 0) AS critical,
                COALESCE(SUM(CASE WHEN days_of_supply >= 7  AND days_of_supply < 14 THEN 1 ELSE 0 END), 0)       AS low,
                COALESCE(SUM(CASE WHEN days_of_supply >= 14 AND days_of_supply < 30 THEN 1 ELSE 0 END), 0)       AS ok,
                COALESCE(SUM(CASE WHEN days_of_supply >= 30 THEN 1 ELSE 0 END), 0)                               AS healthy,
                COALESCE(SUM(CASE WHEN days_of_supply IS NOT NULL AND COALESCE(on_hand, 0) <= 0 THEN 1 ELSE 0 END), 0) AS stocked_out,
                COALESCE(SUM(CASE WHEN days_of_supply IS NULL THEN 1 ELSE 0 END), 0)                             AS no_usage,
                COALESCE(SUM(CASE WHEN days_of_supply IS NOT NULL THEN 1 ELSE 0 END), 0)                         AS measured
             FROM vw_inventory_supply
             WHERE $where"
        );
        $stmt->execute($params);
        $row = $stmt->fetch() ?: [];
        return [
            'critical'    => (int) ($row['critical'] ?? 0),
            'low'         => (int) ($row['low'] ?? 0),
            'ok'          => (int) ($row['ok'] ?? 0),
            'healthy'     => (int) ($row['healthy'] ?? 0),
            'stocked_out' => (int) ($row['stocked_out'] ?? 0),
            'no_usage'    => (int) ($row['no_usage'] ?? 0),
            'measured'    => (int) ($row['measured'] ?? 0),
        ];
    }

    /**
     * Item-warehouse rows with the fewest days of supply first (moving items
     * only — no-usage items are counted separately in the summary).
     *
     * @return array<int, array<string, mixed>>
     */
    public function lowestSupply(?string $category, ?string $warehouse, ?string $item, int $limit = 50): array
    {
        [$where, $params] = $this->clause($category, $warehouse, $item);
        $stmt = $this->pdo->prepare(
            "SELECT item_code, item_description, std_category, std_warehouse,
                    on_hand, unit_of_measure, avg_daily_usage, days_of_supply, is_new_item
             FROM vw_inventory_supply
             WHERE $where AND days_of_supply IS NOT NULL
             ORDER BY days_of_supply ASC, avg_daily_usage DESC
             LIMIT " . (int) $limit
        );
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Slow/obsolete stock (SCRUM-91): stocked item-warehouses with no outbound
     * movement in the trailing 90 days (PROVISIONAL window — pending Raj
     * sign-off). Count-based share of stocked rows; a value-based ($) version
     * needs a confirmed cost source.
     *
     * @return array{slow:int,stocked:int,slow_pct:?float}
     */
    public function slowSummary(?string $category, ?string $warehouse, ?string $item): array
    {
        [$where, $params] = $this->clause($category, $warehouse, $item);
        $stmt = $this->pdo->prepare(
            "SELECT
                COALESCE(SUM(is_slow), 0)    AS slow,
                COALESCE(SUM(is_stocked), 0) AS stocked
             FROM vw_inventory_supply
             WHERE $where"
        );
        $stmt->execute($params);
        $row = $stmt->fetch() ?: [];
        $slow = (int) ($row['slow'] ?? 0);
        $stocked = (int) ($row['stocked'] ?? 0);
        return [
            'slow'     => $slow,
            'stocked'  => $stocked,
            'slow_pct' => $stocked > 0 ? round($slow / $stocked * 100, 2) : null,
        ];
    }

    /**
     * Slow item-warehouse rows, oldest movement first (never-moved rows first).
     *
     * @return array<int, array<string, mixed>>
     */
    public function slowRows(?string $category, ?string $warehouse, ?string $item, int $limit = 50): array
    {
        [$where, $params] = $this->clause($category, $warehouse, $item);
        $stmt = $this->pdo->prepare(
            "SELECT item_code, item_description, std_category, std_warehouse,
                    on_hand, unit_of_measure, last_movement, is_new_item
             FROM vw_inventory_supply
             WHERE $where AND is_slow = 1
             ORDER BY (last_movement IS NULL) DESC, last_movement ASC, on_hand DESC
             LIMIT " . (int) $limit
        );
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Distinct categories / warehouses for the filter dropdowns.
     *
     * @return array<int, string>
     */
    public function options(string $column): array
    {
        $allowed = ['category' => 'std_category', 'warehouse' => 'std_warehouse'];
        if (!isset($allowed[$column])) {
            return [];
        }
        $col = $allowed[$column];
        $rows = $this->pdo->query(
            "SELECT DISTINCT $col FROM vw_inventory_supply
             WHERE is_active = 1 ORDER BY $col"
        )->fetchAll(PDO::FETCH_COLUMN);
        return array_map('strval', $rows);
    }

    /** Timestamp of the most recent inventory-supply refresh, or null. */
    public function lastRefreshed(): ?string
    {
        $v = $this->pdo->query('SELECT MAX(refreshed_at) FROM inventory_supply')->fetchColumn();
        return $v ?: null;
    }
}
