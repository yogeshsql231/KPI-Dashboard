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
