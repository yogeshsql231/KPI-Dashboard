<?php
declare(strict_types=1);
/**
 * Diagnostic for SCRUM-46 (Customer Service / "Customer management" page not
 * loading). Prints which underlying tables/views exist and the first error the
 * KpiRepository hits, so we know exactly what to fix. Read-only.
 *
 *   C:\xampp\php\php.exe etl\cs_check.php
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../src/KpiRepository.php';
require_once __DIR__ . '/../src/Filters.php';

echo "=== CS page (dashboard_cs.php) diagnostic ===\n";
try {
    $pdo = Database::connection();
    echo "[ok]   DB connection\n";
} catch (Throwable $e) {
    echo "[FAIL] DB connect: " . $e->getMessage() . "\n";
    exit(1);
}

$objects = ['vw_order_shipment_kpi', 'order_shipments', 'customer_complaints', 'po_revisions', 'kpi_targets'];
foreach ($objects as $o) {
    try {
        $n = $pdo->query("SELECT COUNT(*) FROM `$o`")->fetchColumn();
        echo "[ok]   $o exists — $n rows\n";
    } catch (Throwable $e) {
        echo "[MISSING] $o — " . $e->getMessage() . "\n";
    }
}

echo "--- KpiRepository calls ---\n";
$repo = new KpiRepository($pdo);
$f = Filters::fromRequest([]);
foreach (['summary', 'targets', 'byDate', 'topCustomers', 'topSkus', 'complaintsPareto', 'warehouseOptions'] as $m) {
    try {
        $args = in_array($m, ['byDate', 'topCustomers', 'topSkus', 'complaintsPareto'], true) ? [$f] : [];
        if ($m === 'topCustomers' || $m === 'topSkus') { $args = [$f, 10]; }
        $repo->$m(...$args);
        echo "[ok]   $m()\n";
    } catch (Throwable $e) {
        echo "[FAIL] $m(): " . $e->getMessage() . "\n";
    }
}
echo "Done.\n";
