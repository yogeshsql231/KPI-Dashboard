<?php

declare(strict_types=1);

require_once __DIR__ . '/Filters.php';

/**
 * Read-only KPI queries used by the dashboard.
 *
 * The KPI math lives in the VIEWS defined in sql/schema.sql; this class layers
 * the dashboard filters on top as parameterised WHERE clauses (positional
 * placeholders only — user input is never concatenated into SQL).
 */
final class KpiRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * Overall scorecard for the current filter selection.
     *
     * @return array<string, mixed>
     */
    public function summary(Filters $f): array
    {
        [$where, $params] = $f->shipmentClause();
        $ship = $this->pdo->prepare(
            "SELECT
                AVG(otif_flag)                    AS otif,
                AVG(ifr)                          AS item_fill_rate,
                COALESCE(SUM(cases_short_pos), 0) AS shipped_short_cases,
                AVG(lead_time_days)               AS avg_lead_time_days,
                COUNT(*)                          AS total_lines,
                COUNT(DISTINCT po_number)         AS total_pos,
                COALESCE(SUM(qty_shipped), 0)     AS total_qty_shipped
             FROM vw_order_shipment_kpi
             WHERE $where"
        );
        $ship->execute($params);
        $row = $ship->fetch() ?: [];

        [$cWhere, $cParams] = $f->complaintClause();
        $comp = $this->pdo->prepare(
            "SELECT COUNT(*) AS total_complaints FROM customer_complaints WHERE $cWhere"
        );
        $comp->execute($cParams);
        $row['total_complaints'] = (int) ($comp->fetchColumn() ?: 0);

        // PO revisions: filter by date + customer when those columns exist.
        [$rWhere, $rParams] = $this->revisionClause($f);
        $rev = $this->pdo->prepare(
            "SELECT COALESCE(SUM(revise_count), 0) AS total_po_revisions
             FROM po_revisions WHERE $rWhere"
        );
        $rev->execute($rParams);
        $row['total_po_revisions'] = (int) ($rev->fetchColumn() ?: 0);

        return $row;
    }

    /**
     * Order-level OTIF: an order counts as on-time-in-full only when every
     * one of its shipment lines is flagged OTIF. Orders are keyed by the SAP
     * SO id when present, falling back to the PO number.
     *
     * @return array{total_orders: int, otif_orders: int, otif_rate: ?float}
     */
    public function otifOrders(Filters $f): array
    {
        [$where, $params] = $f->shipmentClause();
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) AS total_orders, COALESCE(SUM(all_otif), 0) AS otif_orders
             FROM (
                SELECT
                    COALESCE(NULLIF(so_docentry, ''), po_number) AS order_key,
                    MIN(otif_flag)                               AS all_otif
                FROM vw_order_shipment_kpi
                WHERE $where
                GROUP BY COALESCE(NULLIF(so_docentry, ''), po_number)
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
     * Order Cycle Time (SCRUM-87) for the Customer Service dashboard, read from
     * the SAP delivery cache (vw_delivery_lines) so the number matches the
     * Overview tile exactly. Average elapsed days from order entry (SO creation)
     * to the order's last shipment, per sales order. Cancelled and not-yet-
     * shipped (in-flight) orders are excluded. Returns null when the delivery
     * cache / SCRUM-87 columns aren't loaded yet (older installs).
     *
     * @return array{orders: int, avg_days: ?float}|null
     */
    public function orderCycleTime(Filters $f): ?array
    {
        $ready = $this->pdo->query(
            "SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema = DATABASE()
               AND table_name = 'delivery_lines'
               AND column_name IN ('so_created_date', 'shipment_date')"
        )->fetchColumn();
        if ((int) $ready < 2) {
            return null;
        }

        [$where, $params] = $f->deliveryClause();
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) AS orders, AVG(o.cycle_days) AS avg_days
             FROM (
                SELECT
                    sales_order,
                    DATEDIFF(MAX(shipment_date), MIN(COALESCE(so_created_date, posting_date))) AS cycle_days
                FROM vw_delivery_lines
                WHERE $where
                  AND UPPER(COALESCE(so_status, '')) NOT IN ('CANCELLED', 'CANCELED')
                GROUP BY sales_order
                HAVING MAX(shipment_date) IS NOT NULL
                   AND cycle_days IS NOT NULL
                   AND cycle_days >= 0
             ) o"
        );
        $stmt->execute($params);
        $r = $stmt->fetch() ?: [];
        $orders = (int) ($r['orders'] ?? 0);
        return [
            'orders'   => $orders,
            'avg_days' => $orders > 0 && $r['avg_days'] !== null ? (float) $r['avg_days'] : null,
        ];
    }

    /** @return array<string, float> metric_key => target_value */
    public function targets(): array
    {
        $rows = $this->pdo->query('SELECT metric_key, target_value FROM kpi_targets')->fetchAll();
        $out = [];
        foreach ($rows as $r) {
            $out[(string) $r['metric_key']] = (float) $r['target_value'];
        }
        return $out;
    }

    /** @return array<int, array<string, mixed>> */
    public function byDate(Filters $f): array
    {
        [$where, $params] = $f->shipmentClause();
        $stmt = $this->pdo->prepare(
            "SELECT
                ship_date,
                COUNT(*)             AS line_count,
                AVG(otif_flag)       AS otif,
                AVG(ifr)             AS item_fill_rate,
                SUM(cases_short_pos) AS shipped_short_cases
             FROM vw_order_shipment_kpi
             WHERE $where
             GROUP BY ship_date
             ORDER BY ship_date"
        );
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /** @return array<int, array<string, mixed>> */
    public function topCustomers(Filters $f, int $limit = 10): array
    {
        [$where, $params] = $f->shipmentClause();
        $stmt = $this->pdo->prepare(
            "SELECT customer, SUM(qty_shipped) AS qty_shipped
             FROM vw_order_shipment_kpi
             WHERE $where
             GROUP BY customer
             ORDER BY qty_shipped DESC
             LIMIT " . (int) $limit
        );
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /** @return array<int, array<string, mixed>> */
    public function topSkus(Filters $f, int $limit = 10): array
    {
        [$where, $params] = $f->shipmentClause();
        $stmt = $this->pdo->prepare(
            "SELECT item_number, SUM(qty_shipped) AS qty_shipped
             FROM vw_order_shipment_kpi
             WHERE $where
             GROUP BY item_number
             ORDER BY qty_shipped DESC
             LIMIT " . (int) $limit
        );
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /** @return array<int, array<string, mixed>> */
    public function complaintsPareto(Filters $f): array
    {
        [$where, $params] = $f->complaintClause();
        $stmt = $this->pdo->prepare(
            "SELECT
                COALESCE(concern_type, 'Unclassified') AS concern_type,
                COUNT(*)                               AS complaint_count,
                COALESCE(SUM(dollar_value), 0)         AS dollar_value
             FROM customer_complaints
             WHERE $where
             GROUP BY COALESCE(concern_type, 'Unclassified')
             ORDER BY complaint_count DESC"
        );
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Order status tracking from the SAP delivery cache: per SO status, how
     * many orders / POs / lines sit in it and how much is ordered, released
     * and shipped. Returns [] when the delivery cache isn't loaded yet.
     *
     * @return array<int, array<string, mixed>>
     */
    public function orderStatusTracking(Filters $f): array
    {
        $exists = $this->pdo->query(
            "SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = 'delivery_lines'"
        )->fetchColumn();
        if (!$exists) {
            return [];
        }

        [$where, $params] = $f->deliveryClause();
        $stmt = $this->pdo->prepare(
            "SELECT
                COALESCE(NULLIF(so_status, ''), 'Unknown') AS so_status,
                COUNT(DISTINCT sales_order)  AS orders,
                COUNT(DISTINCT po_number)    AS pos,
                COUNT(*)                     AS line_count,
                COALESCE(SUM(order_qty), 0)     AS order_qty,
                COALESCE(SUM(released_qty), 0)  AS released_qty,
                COALESCE(SUM(delivered_qty), 0) AS delivered_qty,
                CASE WHEN SUM(order_qty) > 0
                     THEN SUM(delivered_qty) / SUM(order_qty) END AS shipped_pct
             FROM vw_delivery_lines
             WHERE $where
             GROUP BY COALESCE(NULLIF(so_status, ''), 'Unknown')
             ORDER BY order_qty DESC"
        );
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /**
     * Customer demographics from the SAP delivery cache: Retail vs the other
     * SAP customer groups — distinct customers, orders and shipped cases per
     * type. Returns [] when the delivery cache isn't loaded yet.
     *
     * @return array<int, array<string, mixed>>
     */
    public function customerDemographics(Filters $f): array
    {
        $exists = $this->pdo->query(
            "SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = 'delivery_lines'"
        )->fetchColumn();
        if (!$exists) {
            return [];
        }

        [$where, $params] = $f->deliveryClause();
        $stmt = $this->pdo->prepare(
            "SELECT
                CASE WHEN is_retail = 1 THEN 'Retail'
                     ELSE COALESCE(NULLIF(customer_group, ''), 'Unassigned') END AS customer_type,
                MAX(is_retail)                  AS is_retail,
                COUNT(DISTINCT customer_code)   AS customers,
                COUNT(DISTINCT sales_order)     AS orders,
                COUNT(DISTINCT po_number)       AS pos,
                COALESCE(SUM(delivered_qty), 0) AS delivered_qty
             FROM vw_delivery_lines
             WHERE $where
             GROUP BY CASE WHEN is_retail = 1 THEN 'Retail'
                           ELSE COALESCE(NULLIF(customer_group, ''), 'Unassigned') END
             ORDER BY customers DESC, customer_type"
        );
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /** @return array<int, string> distinct customer names for the filter dropdown */
    public function customerOptions(): array
    {
        $rows = $this->pdo->query(
            'SELECT DISTINCT customer FROM order_shipments WHERE is_sample = 0 ORDER BY customer'
        )->fetchAll(PDO::FETCH_COLUMN);
        return array_map('strval', $rows);
    }

    /** @return array<int, string> distinct item numbers for the filter dropdown */
    public function itemOptions(): array
    {
        $rows = $this->pdo->query(
            'SELECT DISTINCT item_number FROM order_shipments WHERE is_sample = 0 ORDER BY item_number'
        )->fetchAll(PDO::FETCH_COLUMN);
        return array_map('strval', $rows);
    }

    /**
     * @return array<int, string> distinct warehouses for the filter dropdown.
     *
     * The `warehouse` column is added by migration 002. On databases where that
     * migration has not been applied yet the column is absent, so we degrade to
     * an empty list (the dropdown just shows "All warehouses") instead of
     * letting a missing optional filter take down the whole page.
     */
    public function warehouseOptions(): array
    {
        try {
            $rows = $this->pdo->query(
                "SELECT DISTINCT warehouse FROM order_shipments
                 WHERE is_sample = 0 AND warehouse IS NOT NULL AND warehouse <> '' ORDER BY warehouse"
            )->fetchAll(PDO::FETCH_COLUMN);
        } catch (PDOException $e) {
            // 42S22 = unknown column (migration 002 not applied). Not fatal for
            // an optional dropdown — surface no warehouses rather than erroring.
            if (($e->getCode() === '42S22')) {
                return [];
            }
            throw $e;
        }
        return array_map('strval', $rows);
    }

    /**
     * PO-revisions filter: date range + customer (this table has no item).
     *
     * @return array{0:string,1:array<int,mixed>}
     */
    private function revisionClause(Filters $f): array
    {
        $conds = ['1 = 1'];
        $params = [];
        if ($f->fromDate !== null) {
            $conds[] = 'revision_date >= ?';
            $params[] = $f->fromDate;
        }
        if ($f->toDate !== null) {
            $conds[] = 'revision_date <= ?';
            $params[] = $f->toDate;
        }
        if ($f->customer !== null) {
            $conds[] = 'customer = ?';
            $params[] = $f->customer;
        }
        return [implode(' AND ', $conds), $params];
    }
}
