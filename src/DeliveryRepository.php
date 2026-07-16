<?php

declare(strict_types=1);

require_once __DIR__ . '/DeliveryFilters.php';

/**
 * Read-only queries for the delivery / OMS dashboard. All metric definitions
 * mirror the SAP delivery cache (KPI_DeliveryDashboardCache): Fill Rate =
 * delivered / ordered; OTIF / Late / Short are Yes-flag rates; Total Orders and
 * Unique PO are distinct counts (one SO can carry several unique POs).
 */
final class DeliveryRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * The 8 KPI cards + 6 stat tiles for the current filter selection.
     *
     * @return array<string, mixed>
     */
    public function summary(DeliveryFilters $f): array
    {
        [$where, $params] = $f->clause();
        $stmt = $this->pdo->prepare(
            "SELECT
                COUNT(DISTINCT sales_order)                        AS total_orders,
                COUNT(DISTINCT po_number)                          AS unique_po,
                COUNT(*)                                           AS line_records,
                COUNT(DISTINCT item_code)                          AS items,
                COALESCE(SUM(order_qty), 0)                        AS total_qty,
                COALESCE(SUM(delivered_qty), 0)                    AS delivered_qty,
                COALESCE(SUM(released_qty), 0)                     AS released_qty,
                COALESCE(SUM(pick_qty), 0)                         AS pick_qty,
                -- Fill rate excludes canceled / not-picked lines (ifr_eligible = 0).
                CASE WHEN SUM(CASE WHEN ifr_eligible = 1 THEN order_qty ELSE 0 END) > 0
                     THEN SUM(CASE WHEN ifr_eligible = 1 THEN delivered_qty ELSE 0 END)
                        / SUM(CASE WHEN ifr_eligible = 1 THEN order_qty ELSE 0 END) END AS fill_rate,
                AVG(otif_flag)                                     AS otif_rate,
                AVG(late_flag)                                     AS late_rate,
                AVG(short_flag)                                    AS short_rate,
                COUNT(DISTINCT CASE WHEN zero_delivery_flag = 1 THEN po_number END) AS zero_delivery_pos,
                SUM(zero_delivery_flag)                            AS zero_delivery_lines
             FROM vw_delivery_lines
             WHERE $where"
        );
        $stmt->execute($params);
        return $stmt->fetch() ?: [];
    }

    /** Daily trend (posting date). @return array<int, array<string, mixed>> */
    public function byDate(DeliveryFilters $f): array
    {
        [$where, $params] = $f->clause();
        $stmt = $this->pdo->prepare(
            "SELECT
                posting_date,
                COUNT(DISTINCT sales_order)  AS orders,
                COUNT(*)                     AS line_count,
                COALESCE(SUM(order_qty), 0)  AS order_qty,
                COALESCE(SUM(delivered_qty), 0) AS delivered_qty,
                CASE WHEN SUM(CASE WHEN ifr_eligible = 1 THEN order_qty ELSE 0 END) > 0
                     THEN SUM(CASE WHEN ifr_eligible = 1 THEN delivered_qty ELSE 0 END)
                        / SUM(CASE WHEN ifr_eligible = 1 THEN order_qty ELSE 0 END) END AS fill_rate,
                AVG(otif_flag)               AS otif_rate
             FROM vw_delivery_lines
             WHERE $where
             GROUP BY posting_date
             ORDER BY posting_date"
        );
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Per-warehouse fulfilment, with delivered pallets using the shared
     * case-to-pallet conversion (see warehouseCapacity for the precedence).
     *
     * @return array<int, array<string, mixed>>
     */
    public function byWarehouse(DeliveryFilters $f): array
    {
        [$where, $params] = $f->clause();
        $stmt = $this->pdo->prepare(
            "SELECT
                t.warehouse,
                t.line_count,
                t.order_qty,
                t.delivered_qty,
                t.fill_rate,
                t.pallets_known
                    + CASE WHEN wc.cases_per_pallet IS NOT NULL AND wc.cases_per_pallet > 0
                           THEN t.qty_unconverted / wc.cases_per_pallet ELSE 0 END AS delivered_pallets
             FROM (
                SELECT
                    COALESCE(warehouse, 'Unassigned') AS warehouse,
                    COUNT(*)                     AS line_count,
                    COALESCE(SUM(order_qty), 0)  AS order_qty,
                    COALESCE(SUM(delivered_qty), 0) AS delivered_qty,
                    CASE WHEN SUM(CASE WHEN ifr_eligible = 1 THEN order_qty ELSE 0 END) > 0
                         THEN SUM(CASE WHEN ifr_eligible = 1 THEN delivered_qty ELSE 0 END)
                            / SUM(CASE WHEN ifr_eligible = 1 THEN order_qty ELSE 0 END) END AS fill_rate,
                    COALESCE(SUM(
                        CASE
                            WHEN qty_pallet IS NOT NULL AND qty_pallet > 0
                                THEN qty_pallet
                            WHEN qty_per_pallet IS NOT NULL AND qty_per_pallet > 0
                                THEN delivered_qty / qty_per_pallet
                            ELSE 0
                        END), 0)                      AS pallets_known,
                    COALESCE(SUM(
                        CASE WHEN (qty_pallet IS NULL OR qty_pallet <= 0)
                              AND (qty_per_pallet IS NULL OR qty_per_pallet <= 0)
                             THEN delivered_qty ELSE 0 END), 0) AS qty_unconverted
                FROM vw_delivery_lines
                WHERE $where
                GROUP BY COALESCE(warehouse, 'Unassigned')
             ) t
             LEFT JOIN warehouse_capacity wc ON wc.warehouse = t.warehouse
             ORDER BY t.order_qty DESC"
        );
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Per-warehouse pallet throughput vs configured capacity (case-to-pallet
     * conversion). Pallets per line are taken from the SAP pallet count
     * (qty_pallet); if that is missing, converted from delivered_qty using the
     * line's units/pallet (qty_per_pallet); if that is also missing, the
     * remaining qty is converted with the warehouse's cases_per_pallet default
     * (warehouse_capacity). pallet_capacity comes from the warehouse_capacity
     * config (NULL until a real number is filled in).
     *
     * @return array<int, array<string, mixed>>
     */
    public function warehouseCapacity(DeliveryFilters $f): array
    {
        [$where, $params] = $f->clause();
        $stmt = $this->pdo->prepare(
            "SELECT
                t.warehouse                       AS warehouse,
                wc.pallet_capacity                AS pallet_capacity,
                t.order_qty                       AS order_qty,
                t.delivered_qty                   AS delivered_qty,
                t.pallets_known
                    + CASE WHEN wc.cases_per_pallet IS NOT NULL AND wc.cases_per_pallet > 0
                           THEN t.qty_unconverted / wc.cases_per_pallet ELSE 0 END AS delivered_pallets
             FROM (
                SELECT
                    COALESCE(warehouse, 'Unassigned') AS warehouse,
                    COALESCE(SUM(order_qty), 0)       AS order_qty,
                    COALESCE(SUM(delivered_qty), 0)   AS delivered_qty,
                    COALESCE(SUM(
                        CASE
                            WHEN qty_pallet IS NOT NULL AND qty_pallet > 0
                                THEN qty_pallet
                            WHEN qty_per_pallet IS NOT NULL AND qty_per_pallet > 0
                                THEN delivered_qty / qty_per_pallet
                            ELSE 0
                        END), 0)                      AS pallets_known,
                    COALESCE(SUM(
                        CASE WHEN (qty_pallet IS NULL OR qty_pallet <= 0)
                              AND (qty_per_pallet IS NULL OR qty_per_pallet <= 0)
                             THEN delivered_qty ELSE 0 END), 0) AS qty_unconverted
                FROM vw_delivery_lines
                WHERE $where
                GROUP BY COALESCE(warehouse, 'Unassigned')
             ) t
             LEFT JOIN warehouse_capacity wc ON wc.warehouse = t.warehouse
             ORDER BY delivered_pallets DESC"
        );
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /** @return array<int, array<string, mixed>> */
    public function topCustomers(DeliveryFilters $f, int $limit = 10): array
    {
        [$where, $params] = $f->clause();
        $base = DeliveryFilters::customerBaseExpr('customer_name');
        $stmt = $this->pdo->prepare(
            "SELECT
                MIN($base) AS customer_name,
                COALESCE(SUM(delivered_qty), 0) AS delivered_qty,
                CASE WHEN SUM(CASE WHEN ifr_eligible = 1 THEN order_qty ELSE 0 END) > 0
                     THEN SUM(CASE WHEN ifr_eligible = 1 THEN delivered_qty ELSE 0 END)
                        / SUM(CASE WHEN ifr_eligible = 1 THEN order_qty ELSE 0 END) END AS fill_rate
             FROM vw_delivery_lines
             WHERE $where
             GROUP BY UPPER($base)
             ORDER BY delivered_qty DESC
             LIMIT " . (int) $limit
        );
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /** Lines where nothing has been delivered yet. @return array<int, array<string, mixed>> */
    public function zeroDelivery(DeliveryFilters $f, int $limit = 15): array
    {
        [$where, $params] = $f->clause();
        $stmt = $this->pdo->prepare(
            "SELECT sales_order, po_number, customer_name, item_code, item_description, warehouse,
                    order_qty, pick_status
             FROM vw_delivery_lines
             WHERE $where AND zero_delivery_flag = 1
             ORDER BY order_qty DESC
             LIMIT " . (int) $limit
        );
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Distinct non-empty values of a whitelisted column.
     *
     * When $f is given the list "cascades": it only returns values that still
     * exist under the other active filters (the column's own condition is
     * excluded so a chosen value is never hidden). The currently-selected
     * value is always kept in the list. Passing null returns every value.
     *
     * @return array<int, string>
     */
    public function options(string $column, ?DeliveryFilters $f = null): array
    {
        $allowed = [
            'warehouse'   => 'warehouse',
            'customer'    => 'customer_name',
            'item'        => 'item_code',
            'carrier'     => 'carrier',
            'so_status'   => 'so_status',
            'pick_status' => 'pick_status',
        ];
        if (!isset($allowed[$column])) {
            return [];
        }
        $col = $allowed[$column];

        if ($f === null) {
            $rows = $this->pdo->query(
                "SELECT DISTINCT $col FROM vw_delivery_lines
                 WHERE $col IS NOT NULL AND $col <> '' ORDER BY $col"
            )->fetchAll(PDO::FETCH_COLUMN);
            return array_map('strval', $rows);
        }

        // 'customer' has no filter dimension of its own, so nothing to exclude.
        [$where, $params] = $f->clauseExcept($column === 'customer' ? null : $column);
        $stmt = $this->pdo->prepare(
            "SELECT DISTINCT $col FROM vw_delivery_lines
             WHERE $col IS NOT NULL AND $col <> '' AND $where ORDER BY $col"
        );
        $stmt->execute($params);
        $out = array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));

        // Never drop the value the user currently has selected.
        $current = match ($column) {
            'warehouse'   => $f->warehouse,
            'carrier'     => $f->carrier,
            'so_status'   => $f->soStatus,
            'pick_status' => $f->pickStatus,
            default       => null,
        };
        if ($current !== null && $current !== '' && !in_array($current, $out, true)) {
            array_unshift($out, $current);
            sort($out);
        }
        return $out;
    }

    public function lastRefreshed(): ?string
    {
        $v = $this->pdo->query('SELECT MAX(refreshed_at) FROM delivery_lines')->fetchColumn();
        return $v ?: null;
    }

    /**
     * Late deliveries grouped by month (YYYY-MM of posting_date), for the
     * "Late Deliveries vs Late Payments" report. late_lines counts delivered
     * lines flagged late; total_lines is all non-cancelled lines in the month
     * (denominator for late %). Respects the current filter selection.
     *
     * @return array<string, array<string, mixed>> keyed by month
     */
    public function lateByMonth(DeliveryFilters $f): array
    {
        [$where, $params] = $f->clause();
        $stmt = $this->pdo->prepare(
            "SELECT
                DATE_FORMAT(posting_date, '%Y-%m') AS ym,
                COUNT(late_flag)                   AS total_lines,
                SUM(late_flag)                     AS late_lines
             FROM vw_delivery_lines
             WHERE $where AND posting_date IS NOT NULL
             GROUP BY DATE_FORMAT(posting_date, '%Y-%m')
             ORDER BY ym"
        );
        $stmt->execute($params);
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $out[(string) $r['ym']] = $r;
        }
        return $out;
    }

    // -----------------------------------------------------------------------
    // v2 "Overview" dashboard: header KPIs (# and $) and chart feeds.
    // -----------------------------------------------------------------------

    /**
     * Header KPIs for the Overview page: Total SO, Total PO, Delivery Qty,
     * Total Pallets, and their dollar values.
     *
     * @return array<string, mixed>
     */
    public function overview(DeliveryFilters $f): array
    {
        [$where, $params] = $f->clause();
        $stmt = $this->pdo->prepare(
            "SELECT
                COUNT(DISTINCT sales_order)                 AS total_so,
                COUNT(DISTINCT po_number)                   AS total_po,
                COALESCE(SUM(order_qty), 0)                 AS order_qty,
                COALESCE(SUM(delivered_qty), 0)             AS delivered_qty,
                COALESCE(SUM(line_amount), 0)               AS order_amount,
                COALESCE(SUM(delivered_amount), 0)          AS delivered_amount,
                -- Pallets: qty_pallet is the delivered pallet count per line
                -- (SAP U_QTYINPALLET, often fractional), so we simply sum it.
                COALESCE(SUM(qty_pallet), 0)                AS total_pallets
             FROM vw_delivery_lines
             WHERE $where"
        );
        $stmt->execute($params);
        return $stmt->fetch() ?: [];
    }

    /**
     * Sales-order performance over time: order count and dollar value per day.
     *
     * @return array<int, array<string, mixed>>
     */
    public function soPerformanceByDate(DeliveryFilters $f): array
    {
        [$where, $params] = $f->clause();
        $stmt = $this->pdo->prepare(
            "SELECT
                posting_date,
                COUNT(DISTINCT sales_order)      AS orders,
                COALESCE(SUM(line_amount), 0)    AS order_amount,
                COALESCE(SUM(delivered_amount), 0) AS delivered_amount
             FROM vw_delivery_lines
             WHERE $where
             GROUP BY posting_date
             ORDER BY posting_date"
        );
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Recent weekly trend feeding the Overview KPI tiles' hover charts:
     * order count, order $, and delivered-vs-ordered % per ISO week. The
     * date-range filter is intentionally dropped so the tiles always show a
     * rolling window, while warehouse/item/etc. still apply.
     *
     * @return array<int, array<string, mixed>>
     */
    public function weeklyTrend(DeliveryFilters $f, int $weeks = 8): array
    {
        [$where, $params] = $f->clauseExcept('date');
        $days = max(1, (int) $weeks) * 7;
        $stmt = $this->pdo->prepare(
            "SELECT
                YEARWEEK(posting_date, 3)                 AS yw,
                MIN(posting_date)                         AS week_start,
                COUNT(DISTINCT sales_order)               AS orders,
                COUNT(DISTINCT po_number)                 AS pos,
                COALESCE(SUM(order_qty), 0)               AS order_qty,
                COALESCE(SUM(delivered_qty), 0)           AS delivered_qty,
                COALESCE(SUM(line_amount), 0)             AS order_amount,
                COALESCE(SUM(qty_pallet), 0)              AS pallets
             FROM vw_delivery_lines
             WHERE $where
               AND posting_date IS NOT NULL
               AND posting_date >= DATE_SUB(CURDATE(), INTERVAL $days DAY)
             GROUP BY YEARWEEK(posting_date, 3)
             ORDER BY yw"
        );
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Order-level OTIF: a sales order counts as on-time-in-full only when
     * every one of its lines carries otif_flag = 1 (cancelled / flag-less
     * lines are excluded from the check via otif_flag IS NOT NULL).
     *
     * @return array{total_orders: int, otif_orders: int, otif_rate: ?float}
     */
    public function otifOrders(DeliveryFilters $f): array
    {
        [$where, $params] = $f->clause();
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) AS total_orders, COALESCE(SUM(all_otif), 0) AS otif_orders
             FROM (
                SELECT sales_order, MIN(otif_flag) AS all_otif
                FROM vw_delivery_lines
                WHERE $where AND otif_flag IS NOT NULL
                GROUP BY sales_order
             ) o"
        );
        $stmt->execute($params);
        $r = $stmt->fetch() ?: [];
        $total = (int) ($r['total_orders'] ?? 0);
        $otif = (int) ($r['otif_orders'] ?? 0);
        return [
            'total_orders' => $total,
            'otif_orders'  => $otif,
            'otif_rate'    => $total > 0 ? $otif / $total : null,
        ];
    }

    /**
     * Weekly order-level OTIF % over a rolling window. Like weeklyTrend, the
     * date-range filter is dropped so the sparkline always shows the recent
     * trend while the other filters still apply.
     *
     * @return array<int, array<string, mixed>>
     */
    public function otifWeeklyTrend(DeliveryFilters $f, int $weeks = 8): array
    {
        [$where, $params] = $f->clauseExcept('date');
        $days = max(1, (int) $weeks) * 7;
        $stmt = $this->pdo->prepare(
            "SELECT
                o.yw                                 AS yw,
                MIN(o.week_start)                    AS week_start,
                COUNT(*)                             AS orders,
                COALESCE(AVG(o.all_otif), 0)         AS otif_rate
             FROM (
                SELECT
                    sales_order,
                    YEARWEEK(posting_date, 3) AS yw,
                    MIN(posting_date)         AS week_start,
                    MIN(otif_flag)            AS all_otif
                FROM vw_delivery_lines
                WHERE $where
                  AND otif_flag IS NOT NULL
                  AND posting_date IS NOT NULL
                  AND posting_date >= DATE_SUB(CURDATE(), INTERVAL $days DAY)
                GROUP BY sales_order, YEARWEEK(posting_date, 3)
             ) o
             GROUP BY o.yw
             ORDER BY o.yw"
        );
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Order Cycle Time (SCRUM-87): average days from SO entry (posting_date =
     * ORDR.DocDate) to the actual shipment (delivery_date = last linked
     * delivery-note date), per order. Only fully-shipped measurement makes
     * sense per line, so lines with no delivery_date (in-flight) and
     * cancelled orders (otif_flag IS NULL) are excluded.
     *
     * @return array{orders: int, avg_days: ?float, min_days: ?int, max_days: ?int}
     */
    public function cycleTime(DeliveryFilters $f): array
    {
        [$where, $params] = $f->clause();
        $stmt = $this->pdo->prepare(
            "SELECT
                COUNT(*)            AS orders,
                AVG(o.cycle_days)   AS avg_days,
                MIN(o.cycle_days)   AS min_days,
                MAX(o.cycle_days)   AS max_days
             FROM (
                SELECT sales_order,
                       DATEDIFF(MAX(delivery_date), MIN(posting_date)) AS cycle_days
                FROM vw_delivery_lines
                WHERE $where
                  AND otif_flag IS NOT NULL
                  AND delivery_date IS NOT NULL
                  AND posting_date IS NOT NULL
                GROUP BY sales_order
             ) o"
        );
        $stmt->execute($params);
        $r = $stmt->fetch() ?: [];
        $orders = (int) ($r['orders'] ?? 0);
        return [
            'orders'   => $orders,
            'avg_days' => $orders > 0 && $r['avg_days'] !== null ? (float) $r['avg_days'] : null,
            'min_days' => $orders > 0 && $r['min_days'] !== null ? (int) $r['min_days'] : null,
            'max_days' => $orders > 0 && $r['max_days'] !== null ? (int) $r['max_days'] : null,
        ];
    }

    /**
     * Weekly average order cycle time (days) over a rolling window, for the
     * Overview tile sparkline. Weeks are keyed by SO entry date; the
     * date-range filter is dropped like the other tile trends.
     *
     * @return array<int, array<string, mixed>>
     */
    public function cycleTimeWeeklyTrend(DeliveryFilters $f, int $weeks = 8): array
    {
        [$where, $params] = $f->clauseExcept('date');
        $days = max(1, (int) $weeks) * 7;
        $stmt = $this->pdo->prepare(
            "SELECT
                o.yw                        AS yw,
                MIN(o.week_start)           AS week_start,
                COUNT(*)                    AS orders,
                AVG(o.cycle_days)           AS avg_days
             FROM (
                SELECT
                    sales_order,
                    YEARWEEK(posting_date, 3) AS yw,
                    MIN(posting_date)         AS week_start,
                    DATEDIFF(MAX(delivery_date), MIN(posting_date)) AS cycle_days
                FROM vw_delivery_lines
                WHERE $where
                  AND otif_flag IS NOT NULL
                  AND delivery_date IS NOT NULL
                  AND posting_date IS NOT NULL
                  AND posting_date >= DATE_SUB(CURDATE(), INTERVAL $days DAY)
                GROUP BY sales_order, YEARWEEK(posting_date, 3)
             ) o
             GROUP BY o.yw
             ORDER BY o.yw"
        );
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Monthly order performance (orders, $, delivered qty) for the Overview's
     * sales-performance and growth charts.
     *
     * @return array<int, array<string, mixed>>
     */
    public function monthlyPerformance(DeliveryFilters $f): array
    {
        // Monthly trend vs a 3-month moving average needs several months of
        // history, so it ignores the page date filter (the default range is
        // only one week) and always charts the trailing 12 months. All other
        // filters (warehouse, item, carrier...) still apply.
        [$where, $params] = $f->clauseExcept('date');
        $stmt = $this->pdo->prepare(
            "SELECT
                DATE_FORMAT(posting_date, '%Y-%m')  AS ym,
                COUNT(DISTINCT sales_order)         AS orders,
                COALESCE(SUM(line_amount), 0)       AS order_amount,
                COALESCE(SUM(delivered_amount), 0)  AS delivered_amount,
                COALESCE(SUM(order_qty), 0)         AS order_qty,
                COALESCE(SUM(delivered_qty), 0)     AS delivered_qty
             FROM vw_delivery_lines
             WHERE $where AND posting_date IS NOT NULL
               AND posting_date >= DATE_SUB(DATE_FORMAT(CURDATE(), '%Y-%m-01'), INTERVAL 11 MONTH)
             GROUP BY DATE_FORMAT(posting_date, '%Y-%m')
             ORDER BY ym"
        );
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Top customers by order count and dollar value. When $retailOnly is true,
     * only rows flagged is_retail = 1 are considered.
     *
     * @return array<int, array<string, mixed>>
     */
    public function customersByOrders(DeliveryFilters $f, int $limit = 10, bool $retailOnly = false): array
    {
        [$where, $params] = $f->clause();
        if ($retailOnly) {
            $where .= ' AND is_retail = 1';
        }
        $base = DeliveryFilters::customerBaseExpr('customer_name');
        $stmt = $this->pdo->prepare(
            "SELECT
                MIN($base)                       AS customer_name,
                COUNT(DISTINCT sales_order)      AS orders,
                COALESCE(SUM(line_amount), 0)    AS order_amount,
                COALESCE(SUM(delivered_qty), 0)  AS delivered_qty
             FROM vw_delivery_lines
             WHERE $where
             GROUP BY UPPER($base)
             ORDER BY order_amount DESC, orders DESC
             LIMIT " . (int) $limit
        );
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Per-division (SAP customer group) × customer rollup for the exec
     * Overview: orders, quantities and $ per customer, grouped by division.
     * Blank/NULL groups report as "Unassigned".
     *
     * @return array<int, array<string, mixed>>
     */
    public function byDivisionCustomer(DeliveryFilters $f): array
    {
        [$where, $params] = $f->clause();
        $base = DeliveryFilters::customerBaseExpr('customer_name');
        $stmt = $this->pdo->prepare(
            "SELECT
                COALESCE(NULLIF(TRIM(customer_group), ''), 'Unassigned') AS division,
                MIN($base)                       AS customer_name,
                COUNT(DISTINCT sales_order)      AS orders,
                COALESCE(SUM(order_qty), 0)      AS order_qty,
                COALESCE(SUM(delivered_qty), 0)  AS delivered_qty,
                COALESCE(SUM(line_amount), 0)    AS order_amount
             FROM vw_delivery_lines
             WHERE $where
             GROUP BY division, UPPER($base)
             ORDER BY division, order_amount DESC, orders DESC"
        );
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
}
