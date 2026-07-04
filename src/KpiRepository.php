<?php

declare(strict_types=1);

/**
 * Read-only KPI queries used by the dashboard.
 *
 * All queries run against the VIEWS defined in sql/schema.sql so the KPI math
 * lives in exactly one place (the database).
 */
final class KpiRepository
{
    public function __construct(private PDO $pdo)
    {
    }

    /** @return array<string, mixed> */
    public function summary(): array
    {
        $row = $this->pdo->query('SELECT * FROM vw_kpi_summary')->fetch();
        return $row ?: [];
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
    public function byDate(): array
    {
        return $this->pdo->query('SELECT * FROM vw_kpi_by_date')->fetchAll();
    }

    /** @return array<int, array<string, mixed>> */
    public function topCustomers(int $limit = 10): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM vw_customer_shipment LIMIT :lim');
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /** @return array<int, array<string, mixed>> */
    public function topSkus(int $limit = 10): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM vw_sku_shipment LIMIT :lim');
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /** @return array<int, array<string, mixed>> */
    public function complaintsPareto(): array
    {
        return $this->pdo->query('SELECT * FROM vw_complaints_pareto')->fetchAll();
    }
}
