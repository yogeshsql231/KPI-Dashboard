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
require_once __DIR__ . '/../src/PaymentRepository.php';
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

/**
 * Render the warehouse filter as a row of one-click buttons (executive simplicity).
 * The active warehouse is carried in a hidden field so the choice survives when
 * other filters are applied; each button sets it and submits the form.
 *
 * @param array<int, string> $options
 */
function warehouseButtons(string $name, string $label, array $options, ?string $current): void
{
    $current = $current ?? '';
    echo '<div class="filter filter-wh"><label>' . e($label) . '</label>';
    echo '<input type="hidden" name="' . e($name) . '" value="' . e($current) . '">';
    echo '<div class="wh-buttons">';
    $allActive = $current === '' ? ' active' : '';
    echo '<button type="button" class="wh-btn' . $allActive . '" onclick="pickWarehouse(this, \'\')">All</button>';
    foreach ($options as $opt) {
        $active = $current === $opt ? ' active' : '';
        $arg = htmlspecialchars(json_encode($opt), ENT_QUOTES);
        echo '<button type="button" class="wh-btn' . $active . '" onclick="pickWarehouse(this, ' . $arg . ')">' . e($opt) . '</button>';
    }
    echo '</div></div>';
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
$lateDelMonths = [];
$latePayMonths = [];
$hasPayments = false;
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

    // Late deliveries (#) vs late-received payments ($) by month.
    $payments = new PaymentRepository($pdo);
    $lateDelMonths = $repo->lateByMonth($filters);
    $latePayMonths = $payments->byMonth($filters->fromDate, $filters->toDate, 0);
    $hasPayments = $payments->hasData();

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

// Late-delivery vs late-payment: merge the two month sets into one sorted axis.
$lpMonths  = array_values(array_unique(array_merge(array_keys($lateDelMonths), array_keys($latePayMonths))));
sort($lpMonths);
$lpLate     = array_map(static fn ($m) => isset($lateDelMonths[$m]) ? (int) $lateDelMonths[$m]['late_lines'] : 0, $lpMonths);
$lpPaidLate = array_map(static fn ($m) => isset($latePayMonths[$m]) ? (float) $latePayMonths[$m]['paid_late'] : 0.0, $lpMonths);

$chartData = [
    'so'       => ['labels' => $soLabels, 'orders' => $soOrders, 'amount' => $soAmount],
    'top'      => ['labels' => $tcLabels, 'orders' => $tcOrders, 'amount' => $tcAmount],
    'retail'   => ['labels' => $rcLabels, 'orders' => $rcOrders, 'amount' => $rcAmount],
    'wh'       => ['labels' => $whLabels, 'delivered' => $whDelivered],
    'comMonth' => ['labels' => $cmLabels, 'count' => $cmCount, 'lost' => $cmLost],
    'comReason' => ['labels' => $crLabels, 'count' => $crCount],
    'latePay'  => ['labels' => $lpMonths, 'late' => $lpLate, 'paidLate' => $lpPaidLate],
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
        <?php warehouseButtons('warehouse', 'Warehouse', $opts['warehouse'], $filters->warehouse); ?>
        <div class="filter">
            <label for="so">SO Number</label>
            <input type="text" id="so" name="so" placeholder="contains…" value="<?= e($filters->salesOrder) ?>">
        </div>
        <div class="filter">
            <label for="item">Item</label>
            <input type="text" id="item" name="item" placeholder="code or description…" value="<?= e($filters->item) ?>">
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
        <div class="card neutral"><div class="card-label">Total Pallets</div><div class="card-value"><?= num($ov['total_pallets'] ?? 0) ?></div><div class="card-target">delivered pallets</div></div>
        <div class="card good"><div class="card-label">Order Value</div><div class="card-value"><?= money($ov['order_amount'] ?? 0) ?></div><div class="card-target">net line total</div></div>
        <div class="card good"><div class="card-label">Delivered Value</div><div class="card-value"><?= money($ov['delivered_amount'] ?? 0) ?></div><div class="card-target">shipped $</div></div>
    </section>

    <?php if (!$hasAmount): ?>
        <div class="note">Dollar values populate once the SAP ETL loads <code>line_amount</code>/<code>delivered_amount</code>. Counts, quantities and pallets are live from the current cache.</div>
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
        <section class="panel panel-wide">
            <h2>Late Deliveries vs Late Payments (# &amp; $)</h2>
            <p class="panel-note">Per month: deliveries shipped late (bars) alongside how much of that month's invoiced $ was received after the customer's due date (line) — to see whether worse-delivery months also pay later.</p>
            <?php if ($lpMonths === []): ?>
                <p class="empty">No data in the selected range.</p>
            <?php else: ?>
                <canvas id="chartLatePay" height="200"></canvas>
                <?php if (!$hasPayments): ?>
                    <p class="panel-note">Late-payment $ populate once the A/R payment ETL loads <code>ar_payments</code> (migration <code>006</code> + <code>etl/pull_payments.php</code>). The late-delivery bars are live from the current cache.</p>
                <?php endif; ?>
            <?php endif; ?>
        </section>
    </div>

    <footer class="footer">
        KPI Dashboard · Overview · source: SAP Business One (PRODHANA) via local cache<?php if ($lastRefreshed): ?> · data refreshed <?= e($lastRefreshed) ?><?php endif; ?>
    </footer>

    <script>
        const DATA = <?= json_encode($chartData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
        const HAS_AMOUNT = <?= $hasAmount ? 'true' : 'false' ?>;
        const HAS_PAYMENTS = <?= $hasPayments ? 'true' : 'false' ?>;

        // --- shared palette + global look ------------------------------------
        const BLUE = '#3b82f6', TEAL = '#0d9488', AMBER = '#f59e0b', SLATE = '#64748b';
        const GRID = 'rgba(148,163,184,.18)';
        const PIE = ['#3b82f6', '#0d9488', '#f59e0b', '#8b5cf6', '#ef4444', '#06b6d4', '#84cc16', '#ec4899'];

        Chart.defaults.font.family = "system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif";
        Chart.defaults.font.size = 12;
        Chart.defaults.color = '#475569';
        Chart.defaults.plugins.legend.labels.usePointStyle = true;
        Chart.defaults.plugins.legend.labels.boxWidth = 8;
        Chart.defaults.plugins.legend.labels.boxHeight = 8;
        Chart.defaults.plugins.legend.labels.padding = 14;
        Chart.defaults.plugins.tooltip.backgroundColor = '#0f172a';
        Chart.defaults.plugins.tooltip.padding = 10;
        Chart.defaults.plugins.tooltip.cornerRadius = 6;
        Chart.defaults.plugins.tooltip.boxPadding = 6;
        Chart.defaults.maintainAspectRatio = false;
        Chart.defaults.responsive = true;

        const usd = (v) => '$' + Number(v).toLocaleString(undefined, { maximumFractionDigits: 0 });
        const clip = (s, n = 20) => (s && s.length > n ? s.slice(0, n - 1) + '…' : s);

        // Rounded, evenly-spaced bars.
        const bar = (label, data, color, extra = {}) => Object.assign({
            type: 'bar', label, data, backgroundColor: color,
            borderRadius: 6, borderSkipped: false, maxBarThickness: 34, categoryPercentage: 0.7, barPercentage: 0.8
        }, extra);

        // count bars + $ line on a second axis — the $ series only appears when
        // real dollar data is loaded, so an all-zero flat line never shows.
        function countAndMoney(canvasId, labels, countLabel, countData, moneyLabel, moneyData, color) {
            const el = document.getElementById(canvasId);
            if (!el) return;
            const datasets = [bar(countLabel, countData, color, { yAxisID: 'y' })];
            if (HAS_AMOUNT) {
                datasets.push({
                    type: 'line', label: moneyLabel, data: moneyData, yAxisID: 'y1',
                    borderColor: AMBER, backgroundColor: AMBER, borderWidth: 2,
                    pointRadius: 3, pointHoverRadius: 5, tension: 0.35, fill: false
                });
            }
            new Chart(el, {
                data: { labels, datasets },
                options: {
                    interaction: { mode: 'index', intersect: false },
                    plugins: {
                        legend: { display: HAS_AMOUNT },
                        tooltip: { callbacks: { label: (c) => c.dataset.yAxisID === 'y1'
                            ? ' ' + c.dataset.label + ': ' + usd(c.parsed.y)
                            : ' ' + c.dataset.label + ': ' + Number(c.parsed.y).toLocaleString() } }
                    },
                    scales: {
                        x:  { grid: { display: false }, ticks: { maxRotation: 0, autoSkipPadding: 12 } },
                        y:  { position: 'left', beginAtZero: true, border: { display: false },
                              grid: { color: GRID }, ticks: { precision: 0 } },
                        y1: { position: 'right', display: HAS_AMOUNT, beginAtZero: true,
                              border: { display: false }, grid: { drawOnChartArea: false },
                              ticks: { callback: (v) => usd(v) } }
                    }
                }
            });
        }

        // Horizontal ranked bars (customers). Ranked by orders; $ shown in the
        // tooltip so one clean bar series stays readable even with long names.
        function rankedCustomers(canvasId, labels, ordersData, amountData, color) {
            const el = document.getElementById(canvasId);
            if (!el) return;
            new Chart(el, {
                data: { labels, datasets: [bar('Orders', ordersData, color, { maxBarThickness: 22 })] },
                options: {
                    indexAxis: 'y',
                    plugins: {
                        legend: { display: false },
                        tooltip: { callbacks: {
                            title: (items) => labels[items[0].dataIndex],
                            label: (c) => {
                                const rows = [' Orders: ' + Number(c.parsed.x).toLocaleString()];
                                if (HAS_AMOUNT && amountData[c.dataIndex]) rows.push(' Value: ' + usd(amountData[c.dataIndex]));
                                return rows;
                            }
                        } }
                    },
                    scales: {
                        x: { beginAtZero: true, border: { display: false }, grid: { color: GRID }, ticks: { precision: 0 } },
                        y: { grid: { display: false }, ticks: { callback: function (v) { return clip(this.getLabelForValue(v)); } } }
                    }
                }
            });
        }

        // 1. SO performance (# bars + $ line)
        countAndMoney('chartSo', DATA.so.labels, 'Orders', DATA.so.orders, 'Order $', DATA.so.amount, BLUE);
        // 2. Top customers
        rankedCustomers('chartTop', DATA.top.labels, DATA.top.orders, DATA.top.amount, BLUE);
        // 3. Retail customers
        rankedCustomers('chartRetail', DATA.retail.labels, DATA.retail.orders, DATA.retail.amount, TEAL);
        // 4. Delivery by warehouse (qty)
        (function () {
            const el = document.getElementById('chartWh');
            if (!el) return;
            new Chart(el, {
                data: { labels: DATA.wh.labels, datasets: [bar('Delivered Qty', DATA.wh.delivered, TEAL)] },
                options: {
                    plugins: { legend: { display: false },
                        tooltip: { callbacks: { label: (c) => ' ' + Number(c.parsed.y).toLocaleString() + ' units' } } },
                    scales: {
                        x: { grid: { display: false } },
                        y: { beginAtZero: true, border: { display: false }, grid: { color: GRID },
                             ticks: { callback: (v) => Number(v).toLocaleString() } }
                    }
                }
            });
        })();
        // 5. Complaints (# bars + lost $ line)
        countAndMoney('chartComplaints', DATA.comMonth.labels, 'Complaints', DATA.comMonth.count, 'Lost $', DATA.comMonth.lost, '#ef4444');
        // 6. Complaint reasons (doughnut)
        (function () {
            const el = document.getElementById('chartReasons');
            if (!el) return;
            new Chart(el, {
                type: 'doughnut',
                data: { labels: DATA.comReason.labels,
                    datasets: [{ data: DATA.comReason.count, backgroundColor: PIE, borderWidth: 2, borderColor: '#fff', hoverOffset: 6 }] },
                options: { cutout: '58%', plugins: { legend: { position: 'right' } } }
            });
        })();
        // 7. Late deliveries (# bars) vs late-received payments ($ line). The $
        // line only appears once the A/R payment cache is loaded (HAS_PAYMENTS).
        (function () {
            const el = document.getElementById('chartLatePay');
            if (!el) return;
            const d = DATA.latePay;
            const datasets = [bar('Late Deliveries', d.late, '#ef4444', { yAxisID: 'y' })];
            if (HAS_PAYMENTS) {
                datasets.push({
                    type: 'line', label: 'Paid Late $', data: d.paidLate, yAxisID: 'y1',
                    borderColor: AMBER, backgroundColor: AMBER, borderWidth: 2,
                    pointRadius: 3, pointHoverRadius: 5, tension: 0.35, fill: false
                });
            }
            new Chart(el, {
                data: { labels: d.labels, datasets },
                options: {
                    interaction: { mode: 'index', intersect: false },
                    plugins: {
                        legend: { display: HAS_PAYMENTS },
                        tooltip: { callbacks: { label: (c) => c.dataset.yAxisID === 'y1'
                            ? ' ' + c.dataset.label + ': ' + usd(c.parsed.y)
                            : ' ' + c.dataset.label + ': ' + Number(c.parsed.y).toLocaleString() } }
                    },
                    scales: {
                        x:  { grid: { display: false }, ticks: { maxRotation: 0, autoSkipPadding: 12 } },
                        y:  { position: 'left', beginAtZero: true, border: { display: false },
                              grid: { color: GRID }, ticks: { precision: 0 } },
                        y1: { position: 'right', display: HAS_PAYMENTS, beginAtZero: true,
                              border: { display: false }, grid: { drawOnChartArea: false },
                              ticks: { callback: (v) => usd(v) } }
                    }
                }
            });
        })();
    </script>

    <?php endif; ?>
</main>
<script>
function pickWarehouse(btn, val) {
    var form = btn.form;
    form.elements['warehouse'].value = val;
    form.submit();
}
</script>
</body>
</html>
