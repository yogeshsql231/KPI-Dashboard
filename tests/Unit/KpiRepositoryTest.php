<?php

declare(strict_types=1);

namespace Tests\Unit;

use KpiRepository;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Exercises KpiRepository against an in-memory SQLite database. The repository
 * only issues plain `SELECT * FROM <view>` / `LIMIT :lim` statements, so SQLite
 * tables standing in for the MySQL views are enough to verify the mapping and
 * binding logic without a live MySQL server.
 */
final class KpiRepositoryTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    }

    private function repo(): KpiRepository
    {
        return new KpiRepository($this->pdo);
    }

    public function testSummaryReturnsSingleRow(): void
    {
        $this->pdo->exec('CREATE TABLE vw_kpi_summary (otif REAL, ifr REAL)');
        $this->pdo->exec('INSERT INTO vw_kpi_summary (otif, ifr) VALUES (0.95, 0.88)');

        $summary = $this->repo()->summary();

        $this->assertSame(['otif' => 0.95, 'ifr' => 0.88], $summary);
    }

    public function testSummaryReturnsEmptyArrayWhenNoRows(): void
    {
        $this->pdo->exec('CREATE TABLE vw_kpi_summary (otif REAL, ifr REAL)');

        $this->assertSame([], $this->repo()->summary());
    }

    public function testTargetsMapsMetricKeyToFloatValue(): void
    {
        $this->pdo->exec('CREATE TABLE kpi_targets (metric_key TEXT, target_value TEXT)');
        $this->pdo->exec("INSERT INTO kpi_targets (metric_key, target_value) VALUES ('otif', '0.98'), ('ifr', '0.95')");

        $targets = $this->repo()->targets();

        $this->assertSame(['otif' => 0.98, 'ifr' => 0.95], $targets);
        $this->assertIsFloat($targets['otif']);
    }

    public function testTargetsReturnsEmptyArrayWhenNoRows(): void
    {
        $this->pdo->exec('CREATE TABLE kpi_targets (metric_key TEXT, target_value TEXT)');

        $this->assertSame([], $this->repo()->targets());
    }

    public function testByDateReturnsAllRows(): void
    {
        $this->pdo->exec('CREATE TABLE vw_kpi_by_date (d TEXT, otif REAL)');
        $this->pdo->exec("INSERT INTO vw_kpi_by_date (d, otif) VALUES ('2026-07-01', 0.9), ('2026-07-02', 0.8)");

        $rows = $this->repo()->byDate();

        $this->assertCount(2, $rows);
        $this->assertSame('2026-07-01', $rows[0]['d']);
    }

    public function testTopCustomersRespectsLimit(): void
    {
        $this->pdo->exec('CREATE TABLE vw_customer_shipment (customer TEXT, lines INTEGER)');
        for ($i = 1; $i <= 5; $i++) {
            $this->pdo->exec("INSERT INTO vw_customer_shipment (customer, lines) VALUES ('C$i', $i)");
        }

        $this->assertCount(3, $this->repo()->topCustomers(3));
        $this->assertCount(5, $this->repo()->topCustomers(10));
    }

    public function testTopCustomersDefaultLimitIsTen(): void
    {
        $this->pdo->exec('CREATE TABLE vw_customer_shipment (customer TEXT)');
        for ($i = 1; $i <= 15; $i++) {
            $this->pdo->exec("INSERT INTO vw_customer_shipment (customer) VALUES ('C$i')");
        }

        $this->assertCount(10, $this->repo()->topCustomers());
    }

    public function testTopSkusRespectsLimit(): void
    {
        $this->pdo->exec('CREATE TABLE vw_sku_shipment (sku TEXT)');
        for ($i = 1; $i <= 4; $i++) {
            $this->pdo->exec("INSERT INTO vw_sku_shipment (sku) VALUES ('S$i')");
        }

        $this->assertCount(2, $this->repo()->topSkus(2));
    }

    public function testComplaintsParetoReturnsAllRows(): void
    {
        $this->pdo->exec('CREATE TABLE vw_complaints_pareto (reason TEXT, cnt INTEGER)');
        $this->pdo->exec("INSERT INTO vw_complaints_pareto (reason, cnt) VALUES ('late', 5), ('damaged', 2)");

        $rows = $this->repo()->complaintsPareto();

        $this->assertCount(2, $rows);
        $this->assertSame('late', $rows[0]['reason']);
    }
}
