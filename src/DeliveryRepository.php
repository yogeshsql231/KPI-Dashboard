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
                CASE WHEN SUM(order_qty) > 0
                     THEN SUM(delivered_qty) / SUM(order_qty) END  AS fill_rate,
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
                CASE WHEN SUM(order_qty) > 0
                     THEN SUM(delivered_qty) / SUM(order_qty) END AS fill_rate,
                AVG(otif_flag)               AS otif_rate
             FROM vw_delivery_lines
             WHERE $where
             GROUP BY posting_date
             ORDER BY posting_date"
        );
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /** @return array<int, array<string, mixed>> */
    public function byWarehouse(DeliveryFilters $f): array
    {
        [$where, $params] = $f->clause();
        $stmt = $this->pdo->prepare(
            "SELECT
                COALESCE(warehouse, 'Unassigned') AS warehouse,
                COUNT(*)                     AS line_count,
                COALESCE(SUM(order_qty), 0)  AS order_qty,
                COALESCE(SUM(delivered_qty), 0) AS delivered_qty,
                CASE WHEN SUM(order_qty) > 0
                     THEN SUM(delivered_qty) / SUM(order_qty) END AS fill_rate
             FROM vw_delivery_lines
             WHERE $where
             GROUP BY COALESCE(warehouse, 'Unassigned')
             ORDER BY order_qty DESC"
        );
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /** @return array<int, array<string, mixed>> */
    public function topCustomers(DeliveryFilters $f, int $limit = 10): array
    {
        [$where, $params] = $f->clause();
        $stmt = $this->pdo->prepare(
            "SELECT
                customer_name,
                COALESCE(SUM(delivered_qty), 0) AS delivered_qty,
                CASE WHEN SUM(order_qty) > 0
                     THEN SUM(delivered_qty) / SUM(order_qty) END AS fill_rate
             FROM vw_delivery_lines
             WHERE $where
             GROUP BY customer_name
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
            "SELECT sales_order, po_number, customer_name, item_code, warehouse,
                    order_qty, pick_status
             FROM vw_delivery_lines
             WHERE $where AND zero_delivery_flag = 1
             ORDER BY order_qty DESC
             LIMIT " . (int) $limit
        );
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /** @return array<int, string> distinct non-empty values of a whitelisted column */
    public function options(string $column): array
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
        $rows = $this->pdo->query(
            "SELECT DISTINCT $col FROM delivery_lines
             WHERE $col IS NOT NULL AND $col <> '' ORDER BY $col"
        )->fetchAll(PDO::FETCH_COLUMN);
        return array_map('strval', $rows);
    }

    public function lastRefreshed(): ?string
    {
        $v = $this->pdo->query('SELECT MAX(refreshed_at) FROM delivery_lines')->fetchColumn();
        return $v ?: null;
    }
}
