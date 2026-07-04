<?php

declare(strict_types=1);

/**
 * Overview (v2) dashboard for Damascus-KPI.
 *
 * Executive summary of order fulfilment and complaints:
 *   - Header KPI tiles: Total SO, Total PO, Delivered Qty (+ pallet context),
 *     Total Pallets, Order $ value, Delivered $ value.
 *   - Charts (Chart.js, vendored locally): SO performance (# & $), Top
 *     customers (# & $), Retail customers (# & $), Delivery by warehouse,
 *     Complaints (# & lost $), Complaint reasons (#).
 *
 * Reads ONLY from the local caches (delivery_lines, complaints) that the SAP
 * ETL refreshes. Auto-refreshes every 30 min; manual Refresh button too.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../src/DeliveryRepository.php';
require_once __DIR__ . '/../src/ComplaintRepository.php';
require_once __DIR__ . '/../src/DeliveryFilters.php';

function e(mixed $v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}

function num(mixed $v): string
{
    if ($v === null || $v === '') {
        return '—';
    }
    return number_format((float) $v);
}

/** Format a number as USD (compact for the header tiles). */
function money(mixed $v): string
{
    if ($v === null || $v === '') {
        return '$0';
    }
    return '$' . number_format((float) $v);
}

function selectFilter(string $name, string $label, array $options, ?string $current, string $allLabel): void
{
    echo '<div class="filter"><label for="' . e($name) . '">' . e($label) . '</label>';
    echo '<select id="' . e($name) . '" name="' . e($name) . '">';
    echo '<option value="">' . e($allLabel) . '</option>';
    foreach ($options as $opt) {
        $sel = $current === $opt ? ' selected' : '';
        echo '<option value="' . e($opt) . '"' . $sel . '>' . e($opt) . '</option>';
    }
    echo '</select></div>';
}

$error = null;
$ov = [];
$soPerf = [];
$topCust = [];
$retailCust = [];
$byWarehouse = [];
$complaintSummary = ['complaints' => 0, 'lost_amount' => 0];
$complaintsByMonth = [];
$complaintsByReason = [];
$opts = ['warehouse' => [], 'carrier' => [], 'so_status' => [], 'pick_status' => []];
$lastRefreshed = null;
$filters = DeliveryFilters::fromRequest($_GET);

try {
    $pdo = Database::connection();
    $repo = new DeliveryRepository($pdo);
    $complaints = new ComplaintRepository($pdo);

    $ov = $repo->overview($filters);
    $soPerf = $repo->soPerformanceByDate($filters);
    $topCust = $repo->customersByOrders($filters, 10, false);
    $retailCust = $repo->customersByOrders($filters, 10, true);
    $byWarehouse = $repo->byWarehouse($filters);
    $complaintSummary = $complaints->summary($filters);
    $complaintsByMonth = $complaints->byMonth($filters);
    $complaintsByReason = $complaints->byReason($filters, 8);

    foreach (array_keys($opts) as $k) {
        $opts[$k] = $repo->options($k);
    }
    $lastRefreshed = $repo->lastRefreshed();
} catch (Throwable $ex) {
    $error = 'Unable to load overview data. Import sql/delivery_dashboard.sql (+ migrations) and check your .env database connection.';
}

// ---- Build chart datasets (arrays PHP -> JSON -> Chart.js) ----------------
$soLabels    = array_map(static fn ($r) => (string) $r['posting_date'], $soPerf);
$soOrders    = array_map(static fn ($r) => (int) $r['orders'], $soPerf);
$soAmount    = array_map(static fn ($r) => (float) $r['order_amount'], $soPerf);

$tcLabels    = array_map(static fn ($r) => (string) $r['customer_name'], $topCust);
$tcOrders    = array_map(static fn ($r) => (int) $r['orders'], $topCust);
$tcAmount    = array_map(static fn ($r) => (float) $r['order_amount'], $topCust);

$rcLabels    = array_map(static fn ($r) => (string) $r['customer_name'], $retailCust);
$rcOrders    = array_map(static fn ($r) => (int) $r['orders'], $retailCust);
$rcAmount    = array_map(static fn ($r) => (float) $r['order_amount'], $retailCust);

$whLabels    = array_map(static fn ($r) => (string) $r['warehouse'], $byWarehouse);
$whDelivered = array_map(static fn ($r) => (float) $r['delivered_qty'], $byWarehouse);

$cmLabels    = array_map(static fn ($r) => (string) $r['period'], $complaintsByMonth);
$cmCount     = array_map(static fn ($r) => (int) $r['complaints'], $complaintsByMonth);
$cmLost      = array_map(static fn ($r) => (float) $r['lost_amount'], $complaintsByMonth);

$crLabels    = array_map(static fn ($r) => (string) $r['reason'], $complaintsByReason);
$crCount     = array_map(static fn ($r) => (int) $r['complaints'], $complaintsByReason);

$hasComplaints = ($complaintSummary['complaints'] ?? 0) > 0;
$hasRetail     = $retailCust !== [];
$hasAmount     = ($ov['order_amount'] ?? 0) > 0;

$chartData = [
    'so'       => ['labels' => $soLabels, 'orders' => $soOrders, 'amount' => $soAmount],
    'top'      => ['labels' => $tcLabels, 'orders' => $tcOrders, 'amount' => $tcAmount],
    'retail'   => ['labels' => $rcLabels, 'orders' => $rcOrders, 'amount' => $rcAmount],
    'wh'       => ['labels' => $whLabels, 'delivered' => $whDelivered],
    'comMonth' => ['labels' => $cmLabels, 'count' => $cmCount, 'lost' => $cmLost],
    'comReason' => ['labels' => $crLabels, 'count' => $crCount],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <!-- Auto-refresh every 30 minutes (1800s); preserves the active filters. -->
    <meta http-equiv="refresh" content="1800">
    <title>KPI Dashboard · Overview</title>
    <link rel="stylesheet" href="assets/style.css">
    <script src="assets/vendor/chart.umd.min.js"></script>
</head>
<body>
<header class="topbar">
    <div class="brand">KPI Dashboard</div>
    <div class="subtitle">Overview</div>
    <nav class="topnav">
        <a href="overview.php" class="active">Overview</a>
        <a href="dashboard.php">Delivery</a>
        <a href="dashboard_cs.php">Customer Service</a>
    </nav>
</header>

<main class="container">
    <form class="filters" method="get" action="overview.php">
        <div class="filter">
            <label for="from_date">From Date</label>
            <input type="date" id="from_date" name="from_date" value="<?= e($filters->fromDate) ?>">
        </div>
        <div class="filter">
            <label for="to_date">To Date</label>
            <input type="date" id="to_date" name="to_date" value="<?= e($filters->toDate) ?>">
        </div>
        <?php selectFilter('warehouse', 'Warehouse', $opts['warehouse'], $filters->warehouse, 'All Warehouses'); ?>
        <div class="filter">
            <label for="so">SO Number</label>
            <input type="text" id="so" name="so" placeholder="contains…" value="<?= e($filters->salesOrder) ?>">
        </div>
        <?php
        selectFilter('carrier', 'Carrier', $opts['carrier'], $filters->carrier, 'All Carriers');
        selectFilter('so_status', 'SO Status', $opts['so_status'], $filters->soStatus, 'All');
        ?>
        <div class="filter-actions">
            <button type="submit" class="btn btn-primary">Apply</button>
            <a class="btn btn-reset" href="overview.php">Reset</a>
            <button type="button" class="btn btn-refresh" onclick="window.location.reload()" title="Reload the latest data now (auto-refreshes every 30 min)">Refresh Data</button>
        </div>
    </form>

    <?php if ($error !== null): ?>
        <div class="alert"><?= e($error) ?></div>
    <?php else: ?>

    <section class="cards">
        <div class="card neutral"><div class="card-label">Total SO</div><div class="card-value"><?= num($ov['total_so'] ?? 0) ?></div><div class="card-target">sales orders</div></div>
        <div class="card neutral"><div class="card-label">Total PO</div><div class="card-value"><?= num($ov['total_po'] ?? 0) ?></div><div class="card-target">customer POs</div></div>
        <div class="card good"><div class="card-label">Delivered Qty</div><div class="card-value"><?= num($ov['delivered_qty'] ?? 0) ?></div><div class="card-target">of <?= num($ov['order_qty'] ?? 0) ?> ordered</div></div>
        <div class="card neutral"><div class="card-label">Total Pallets</div><div class="card-value"><?= num($ov['total_pallets'] ?? 0) ?></div><div class="card-target">delivered ÷ bags-per-pallet</div></div>
        <div class="card good"><div class="card-label">Order Value</div><div class="card-value"><?= money($ov['order_amount'] ?? 0) ?></div><div class="card-target">net line total</div></div>
        <div class="card good"><div class="card-label">Delivered Value</div><div class="card-value"><?= money($ov['delivered_amount'] ?? 0) ?></div><div class="card-target">shipped $</div></div>
    </section>

    <?php if (!$hasAmount): ?>
        <div class="note">Dollar values and pallet counts populate once the SAP ETL loads <code>line_amount</code>/<code>delivered_amount</code>/<code>qty_per_pallet</code> (added to the ETL query). Counts and quantities are live from the current cache.</div>
    <?php endif; ?>

    <div class="chart-grid">
        <section class="panel">
            <h2>Sales-Order Performance (# &amp; $)</h2>
            <canvas id="chartSo" height="220"></canvas>
        </section>
        <section class="panel">
            <h2>Top Customers (orders &amp; $)</h2>
            <canvas id="chartTop" height="220"></canvas>
        </section>
        <section class="panel">
            <h2>Retail Customers (orders &amp; $)</h2>
            <?php if ($hasRetail): ?>
                <canvas id="chartRetail" height="220"></canvas>
            <?php else: ?>
                <p class="empty">No customers flagged retail yet. Set <code>is_retail</code> (SAP customer group) in the ETL to populate this chart.</p>
            <?php endif; ?>
        </section>
        <section class="panel">
            <h2>Delivery by Warehouse (qty)</h2>
            <canvas id="chartWh" height="220"></canvas>
        </section>
        <section class="panel">
            <h2>Customer Complaints (# &amp; lost $)</h2>
            <?php if ($hasComplaints): ?>
                <canvas id="chartComplaints" height="220"></canvas>
            <?php else: ?>
                <p class="empty">No complaints loaded yet. Populate the <code>complaints</code> table (SAP service calls / credit memos or a manual feed) to activate this chart.</p>
            <?php endif; ?>
        </section>
        <section class="panel">
            <h2>Complaint Types / Reasons (#)</h2>
            <?php if ($hasComplaints): ?>
                <canvas id="chartReasons" height="220"></canvas>
            <?php else: ?>
                <p class="empty">Awaiting complaint data.</p>
            <?php endif; ?>
        </section>
    </div>

    <footer class="footer">
        KPI Dashboard · Overview · source: SAP Business One (PRODHANA) via local cache<?php if ($lastRefreshed): ?> · data refreshed <?= e($lastRefreshed) ?><?php endif; ?>
    </footer>

    <script>
        const DATA = <?= json_encode($chartData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
        const BLUE = '#2563eb', GREEN = '#0f766e', AMBER = '#d97706', SLATE = '#64748b';
        const usd = (v) => '$' + Number(v).toLocaleString();

        function comboBarLine(canvasId, labels, barLabel, barData, lineLabel, lineData) {
            const el = document.getElementById(canvasId);
            if (!el) return;
            new Chart(el, {
                data: {
                    labels,
                    datasets: [
                        { type: 'bar', label: barLabel, data: barData, backgroundColor: BLUE, yAxisID: 'y' },
                        { type: 'line', label: lineLabel, data: lineData, borderColor: GREEN, backgroundColor: GREEN, yAxisID: 'y1', tension: 0.3 }
                    ]
                },
                options: {
                    responsive: true, maintainAspectRatio: false,
                    interaction: { mode: 'index', intersect: false },
                    scales: {
                        y:  { position: 'left',  beginAtZero: true, title: { display: true, text: barLabel } },
                        y1: { position: 'right', beginAtZero: true, grid: { drawOnChartArea: false }, title: { display: true, text: lineLabel },
                              ticks: { callback: (v) => usd(v) } }
                    }
                }
            });
        }

        function groupedBar(canvasId, labels, ordersData, amountData) {
            const el = document.getElementById(canvasId);
            if (!el) return;
            new Chart(el, {
                type: 'bar',
                data: {
                    labels,
                    datasets: [
                        { label: 'Orders', data: ordersData, backgroundColor: BLUE, yAxisID: 'y' },
                        { label: 'Value ($)', data: amountData, backgroundColor: AMBER, yAxisID: 'y1' }
                    ]
                },
                options: {
                    indexAxis: 'y', responsive: true, maintainAspectRatio: false,
                    scales: {
                        y:  {},
                        x:  { beginAtZero: true }
                    }
                }
            });
        }

        // 1. SO performance (# bars + $ line)
        comboBarLine('chartSo', DATA.so.labels, 'Orders', DATA.so.orders, 'Order $', DATA.so.amount);
        // 2. Top customers (orders + $)
        groupedBar('chartTop', DATA.top.labels, DATA.top.orders, DATA.top.amount);
        // 3. Retail customers (orders + $)
        groupedBar('chartRetail', DATA.retail.labels, DATA.retail.orders, DATA.retail.amount);
        // 4. Delivery by warehouse (qty)
        (function () {
            const el = document.getElementById('chartWh');
            if (!el) return;
            new Chart(el, {
                type: 'bar',
                data: { labels: DATA.wh.labels, datasets: [{ label: 'Delivered Qty', data: DATA.wh.delivered, backgroundColor: GREEN }] },
                options: { responsive: true, maintainAspectRatio: false, scales: { y: { beginAtZero: true } } }
            });
        })();
        // 5. Complaints (# bars + lost $ line)
        comboBarLine('chartComplaints', DATA.comMonth.labels, 'Complaints', DATA.comMonth.count, 'Lost $', DATA.comMonth.lost);
        // 6. Complaint reasons (doughnut)
        (function () {
            const el = document.getElementById('chartReasons');
            if (!el) return;
            new Chart(el, {
                type: 'doughnut',
                data: {
                    labels: DATA.comReason.labels,
                    datasets: [{ data: DATA.comReason.count,
                        backgroundColor: [BLUE, GREEN, AMBER, SLATE, '#9333ea', '#dc2626', '#0891b2', '#65a30d'] }]
                },
                options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'right' } } }
            });
        })();
    </script>

    <?php endif; ?>
</main>
</body>
</html>
