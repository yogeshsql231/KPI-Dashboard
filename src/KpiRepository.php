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
        try {
            $row = $this->pdo->query('SELECT * FROM vw_kpi_summary')->fetch();
            return $row ?: [];
        } catch (PDOException $e) {
            error_log('[KpiRepository::summary] ' . $e->getMessage());
            throw new RuntimeException('Failed to load KPI summary.', 0, $e);
        }
    }

    /** @return array<string, float> metric_key => target_value */
    public function targets(): array
    {
        try {
            $rows = $this->pdo->query('SELECT metric_key, target_value FROM kpi_targets')->fetchAll();
        } catch (PDOException $e) {
            error_log('[KpiRepository::targets] ' . $e->getMessage());
            throw new RuntimeException('Failed to load KPI targets.', 0, $e);
        }
        $out = [];
        foreach ($rows as $r) {
            $out[(string) $r['metric_key']] = (float) $r['target_value'];
        }
        return $out;
    }

    /** @return array<int, array<string, mixed>> */
    public function byDate(): array
    {
        try {
            return $this->pdo->query('SELECT * FROM vw_kpi_by_date')->fetchAll();
        } catch (PDOException $e) {
            error_log('[KpiRepository::byDate] ' . $e->getMessage());
            throw new RuntimeException('Failed to load KPI by-date data.', 0, $e);
        }
    }

    /** @return array<int, array<string, mixed>> */
    public function topCustomers(int $limit = 10): array
    {
        try {
            $stmt = $this->pdo->prepare('SELECT * FROM vw_customer_shipment LIMIT :lim');
            $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            error_log('[KpiRepository::topCustomers] ' . $e->getMessage());
            throw new RuntimeException('Failed to load top customers.', 0, $e);
        }
    }

    /** @return array<int, array<string, mixed>> */
    public function topSkus(int $limit = 10): array
    {
        try {
            $stmt = $this->pdo->prepare('SELECT * FROM vw_sku_shipment LIMIT :lim');
            $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
            $stmt->execute();
            return $stmt->fetchAll();
        } catch (PDOException $e) {
            error_log('[KpiRepository::topSkus] ' . $e->getMessage());
            throw new RuntimeException('Failed to load top SKUs.', 0, $e);
        }
    }

    /** @return array<int, array<string, mixed>> */
    public function complaintsPareto(): array
    {
        try {
            return $this->pdo->query('SELECT * FROM vw_complaints_pareto')->fetchAll();
        } catch (PDOException $e) {
            error_log('[KpiRepository::complaintsPareto] ' . $e->getMessage());
            throw new RuntimeException('Failed to load complaints pareto.', 0, $e);
        }
    }
}
