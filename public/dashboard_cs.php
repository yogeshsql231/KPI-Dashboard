<?php

declare(strict_types=1);

/**
 * Main KPI Dashboard - Customer Service / Order Management.
 *
 * Renders the first set of Customer Service KPIs (OTIF, Item Fill Rate,
 * Shipped Short, Lead Time, Complaints, PO Revisions) straight from the
 * database views. Requires a signed-in network account (see src/Auth.php).
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../src/Auth.php';
require_once __DIR__ . '/../src/KpiRepository.php';
require_once __DIR__ . '/../src/Filters.php';
require_once __DIR__ . '/../src/SourceBadge.php';

Auth::requireDepartment('customer_service');
$canSeeFinancials = Auth::isCLevel();

/** HTML-escape helper. */
function e(mixed $v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}

/** Format a 0..1 ratio as a percentage. */
function pct(mixed $v, int $dp = 2): string
{
    if ($v === null || $v === '') {
        return '—';
    }
    return number_format(((float) $v) * 100, $dp) . '%';
}

/** Format an integer with thousands separators. */
function num(mixed $v): string
{
    if ($v === null || $v === '') {
        return '—';
    }
    return number_format((float) $v);
}

$error = null;
$summary = [];
$targets = [];
$byDate = [];
$topCustomers = [];
$topSkus = [];
$pareto = [];
$orderStatus = [];
$demographics = [];
$warehouseOptions = [];
$otifOrders = null;
$cycleOrders = null;
// Default to the last 7 days so the page loads with data immediately.
$q = $_GET;
if (($q['from_date'] ?? '') === '' && ($q['to_date'] ?? '') === '') {
    $q['from_date'] = date('Y-m-d', strtotime('-6 days'));
    $q['to_date'] = date('Y-m-d');
}
$filters = Filters::fromRequest($q);

try {
    $repo = new KpiRepository(Database::connection());
    $summary = $repo->summary($filters);
    $targets = $repo->targets();
    $byDate = $repo->byDate($filters);
    $topCustomers = $repo->topCustomers($filters, 10);
    $topSkus = $repo->topSkus($filters, 10);
    $pareto = $repo->complaintsPareto($filters);
    $orderStatus = $repo->orderStatusTracking($filters);
    $demographics = $repo->customerDemographics($filters);
    $warehouseOptions = $repo->warehouseOptions();
    try {
        $otifOrders = $repo->otifOrders($filters);
        $cycleOrders = $repo->cycleTimeOrders($filters);
    } catch (Throwable $ex) {
        $otifOrders = null; // migration 011 (so_docentry on the view) not applied yet
        $cycleOrders = null;
    }
} catch (Throwable $ex) {
    $error = 'Unable to load KPI data. Check the database connection in your .env file.';
}

/** Decide the status color for a "higher is better" ratio metric. */
function ratioClass(?float $value, ?float $target): string
{
    if ($value === null || $target === null) {
        return 'neutral';
    }
    if ($value >= $target) {
        return 'good';
    }
    return $value >= $target * 0.95 ? 'warn' : 'bad';
}

$otif = isset($summary['otif']) ? (float) $summary['otif'] : null;
$ifr = isset($summary['item_fill_rate']) ? (float) $summary['item_fill_rate'] : null;
$otifTarget = $targets['otif'] ?? 0.98;
$ifrTarget = $targets['item_fill_rate'] ?? 0.98;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <!-- Auto-refresh every 30 minutes (1800s); preserves the active filters. -->
    <meta http-equiv="refresh" content="1800">
    <title>KPI Dashboard · Customer Service / OMS</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
<header class="topbar">
    <div class="brand">KPI Dashboard</div>
    <div class="subtitle">Customer Service / Order Management</div>
    <nav class="topnav">
        <?php foreach (Auth::allowedPages() as $navInfo): ?>
        <a href="<?= e($navInfo['page']) ?>"<?= $navInfo['page'] === 'dashboard_cs.php' ? ' class="active"' : '' ?>><?= e($navInfo['label']) ?></a>
        <?php endforeach; ?>
        <?php $authUser = Auth::user(); if ($authUser !== null): ?>
        <span class="user-chip">
            <span class="user-name"><?= e($authUser['name']) ?></span>
            <?php if ($canSeeFinancials): ?><span class="user-role">C-level</span><?php endif; ?>
            <a href="logout.php" class="user-logout">Sign out</a>
        </span>
        <?php endif; ?>
    </nav>
</header>

<main class="container">
    <form class="filters" method="get" action="dashboard_cs.php">
        <div class="filter">
            <label for="from_date">From date</label>
            <input type="date" id="from_date" name="from_date" value="<?= e($filters->fromDate) ?>">
        </div>
        <div class="filter">
            <label for="to_date">To date</label>
            <input type="date" id="to_date" name="to_date" value="<?= e($filters->toDate) ?>">
        </div>
        <div class="filter filter-wh">
            <label>Warehouse</label>
            <input type="hidden" name="warehouse" value="<?= e($filters->warehouse ?? '') ?>">
            <div class="wh-buttons">
                <button type="button" class="wh-btn<?= $filters->warehouse === null ? ' active' : '' ?>" onclick="pickWarehouse(this, '')">All</button>
                <?php foreach (DeliveryFilters::WAREHOUSE_GROUPS as $opt): ?>
                <button type="button" class="wh-btn<?= $filters->warehouse === $opt ? ' active' : '' ?>" onclick="pickWarehouse(this, <?= htmlspecialchars((string) json_encode($opt), ENT_QUOTES) ?>)"><?= e($opt) ?></button>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="filter">
            <label for="so">SO number</label>
            <input type="text" id="so" name="so" placeholder="contains…" value="<?= e($filters->salesOrder) ?>">
        </div>
        <div class="filter">
            <label for="item">Item</label>
            <input type="text" id="item" name="item" placeholder="contains…" value="<?= e($filters->item) ?>">
        </div>
        <div class="filter">
            <label for="po">PO number</label>
            <input type="text" id="po" name="po" placeholder="contains…" value="<?= e($filters->po) ?>">
        </div>
        <div class="filter-actions">
            <button type="submit" class="btn btn-primary">Apply</button>
            <a class="btn btn-reset" href="dashboard_cs.php">Reset</a>
            <button type="button" class="btn btn-refresh" onclick="window.location.reload()" title="Reload the latest data now (auto-refreshes every 30 min)">Refresh Data</button>
        </div>
    </form>

    <?php if ($error !== null): ?>
        <div class="alert"><?= e($error) ?></div>
    <?php else: ?>

    <section class="cards">
        <?php $c = ratioClass($otif, (float) $otifTarget); ?>
        <div class="card <?= $c ?>">
            <div class="card-label">OTIF (On-Time In-Full)</div>
            <div class="card-value"><?= pct($otif) ?></div>
            <div class="card-target">Target <?= pct((float) $otifTarget, 0) ?></div>
        </div>

        <?php if ($otifOrders !== null && $otifOrders['total_orders'] > 0): ?>
        <?php $c = ratioClass($otifOrders['otif_rate'], (float) $otifTarget); ?>
        <div class="card <?= $c ?>">
            <div class="card-label">OTIF by Order</div>
            <div class="card-value"><?= pct($otifOrders['otif_rate']) ?></div>
            <div class="card-target"><?= num($otifOrders['otif_orders']) ?> of <?= num($otifOrders['total_orders']) ?> orders · target <?= pct((float) $otifTarget, 0) ?></div>
        </div>
        <?php endif; ?>

        <?php $c = ratioClass($ifr, (float) $ifrTarget); ?>
        <div class="card <?= $c ?>">
            <div class="card-label">Item Fill Rate</div>
            <div class="card-value"><?= pct($ifr) ?></div>
            <div class="card-target">Target <?= pct((float) $ifrTarget, 0) ?></div>
        </div>

        <div class="card <?= ($summary['shipped_short_cases'] ?? 0) > 0 ? 'warn' : 'good' ?>">
            <div class="card-label">Shipped Short</div>
            <div class="card-value"><?= num($summary['shipped_short_cases'] ?? 0) ?></div>
            <div class="card-target">cases · target 0</div>
        </div>

        <div class="card neutral">
            <div class="card-label">Avg Lead Time</div>
            <div class="card-value"><?= $summary['avg_lead_time_days'] !== null ? number_format((float) $summary['avg_lead_time_days'], 1) : '—' ?></div>
            <div class="card-target">days (order → ship)</div>
        </div>

        <?php if ($cycleOrders !== null && $cycleOrders['orders'] > 0): ?>
        <div class="card neutral">
            <div class="card-label">Order Cycle Time</div>
            <div class="card-value"><?= $cycleOrders['avg_days'] !== null ? number_format((float) $cycleOrders['avg_days'], 1) : '—' ?></div>
            <div class="card-target">days · <?= num($cycleOrders['orders']) ?> shipped orders</div>
        </div>
        <?php endif; ?>

        <div class="card neutral">
            <div class="card-label">Customer Complaints</div>
            <div class="card-value"><?= num($summary['total_complaints'] ?? 0) ?></div>
            <div class="card-target">total logged</div>
        </div>

        <div class="card neutral">
            <div class="card-label">PO Revisions</div>
            <div class="card-value"><?= num($summary['total_po_revisions'] ?? 0) ?></div>
            <div class="card-target">customer-requested</div>
        </div>
    </section>

    <section class="meta">
        <span><strong><?= num($summary['total_lines'] ?? 0) ?></strong> order lines</span>
        <span><strong><?= num($summary['total_pos'] ?? 0) ?></strong> POs</span>
        <span><strong><?= num($summary['total_qty_shipped'] ?? 0) ?></strong> cases shipped</span>
    </section>

    <div class="grid">
        <section class="panel">
            <h2>Order Status Tracking <?= SourceBadge::render('ordered') ?></h2>
            <p class="panel-note">Sales orders from the SAP delivery cache grouped by status — orders, customer POs and how much of each status is released and shipped. Source: <code>delivery_lines</code> via <code>etl/pull_delivery.php</code>.</p>
            <table>
                <thead>
                    <tr><th>SO Status</th><th class="num">Orders</th><th class="num">POs</th><th class="num">Lines</th><th class="num">Ordered</th><th class="num">Released</th><th class="num">Shipped</th><th class="num">% Shipped</th></tr>
                </thead>
                <tbody>
                <?php foreach ($orderStatus as $r): ?>
                    <tr>
                        <td><?= e($r['so_status']) ?></td>
                        <td class="num"><?= num($r['orders']) ?></td>
                        <td class="num"><?= num($r['pos']) ?></td>
                        <td class="num"><?= num($r['line_count']) ?></td>
                        <td class="num"><?= num($r['order_qty']) ?></td>
                        <td class="num"><?= num($r['released_qty']) ?></td>
                        <td class="num"><?= num($r['delivered_qty']) ?></td>
                        <td class="num"><?= pct($r['shipped_pct']) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($orderStatus === []): ?>
                    <tr><td colspan="8" class="empty">No data — load the SAP delivery cache with <code>etl/pull_delivery.php</code></td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </section>

        <section class="panel">
            <h2>Customer Demographics <?= SourceBadge::render('customers') ?></h2>
            <p class="panel-note">Retail vs the other SAP customer groups (OCRG) — how many customers, orders and shipped cases each type accounts for. Source: <code>delivery_lines</code> via <code>etl/pull_delivery.php</code>.</p>
            <table>
                <thead>
                    <tr><th>Customer Type</th><th class="num">Customers</th><th class="num">Orders</th><th class="num">POs</th><th class="num">Cases Shipped</th><th class="num">% of Customers</th></tr>
                </thead>
                <tbody>
                <?php $demoTotal = array_sum(array_map(static fn ($r) => (int) $r['customers'], $demographics)); ?>
                <?php foreach ($demographics as $r): ?>
                    <tr>
                        <td><?= e($r['customer_type']) ?></td>
                        <td class="num"><?= num($r['customers']) ?></td>
                        <td class="num"><?= num($r['orders']) ?></td>
                        <td class="num"><?= num($r['pos']) ?></td>
                        <td class="num"><?= num($r['delivered_qty']) ?></td>
                        <td class="num"><?= pct($demoTotal > 0 ? $r['customers'] / $demoTotal : null) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($demographics === []): ?>
                    <tr><td colspan="6" class="empty">No data — load the SAP delivery cache with <code>etl/pull_delivery.php</code></td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </section>

        <section class="panel">
            <h2>OTIF &amp; Fill Rate by Date <?= SourceBadge::render('fulfilment') ?></h2>
            <table>
                <thead>
                    <tr><th>Date</th><th>Lines</th><th>OTIF</th><th>Fill Rate</th><th>Short</th></tr>
                </thead>
                <tbody>
                <?php foreach ($byDate as $r): ?>
                    <tr>
                        <td><?= e($r['ship_date']) ?></td>
                        <td class="num"><?= num($r['line_count']) ?></td>
                        <td class="num"><?= pct($r['otif']) ?></td>
                        <td class="num"><?= pct($r['item_fill_rate']) ?></td>
                        <td class="num"><?= num($r['shipped_short_cases']) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($byDate === []): ?>
                    <tr><td colspan="5" class="empty">No data</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </section>

        <section class="panel">
            <h2>Complaints by Concern Type <?= SourceBadge::render('complaints') ?></h2>
            <table>
                <thead><tr><th>Concern Type</th><th>Count</th></tr></thead>
                <tbody>
                <?php foreach ($pareto as $r): ?>
                    <tr>
                        <td><?= e($r['concern_type']) ?></td>
                        <td class="num"><?= num($r['complaint_count']) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($pareto === []): ?>
                    <tr><td colspan="2" class="empty">No data</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </section>

        <section class="panel">
            <h2>Top Customers by Cases Shipped <?= SourceBadge::render('shipments') ?></h2>
            <table>
                <thead><tr><th>Customer</th><th>Cases</th></tr></thead>
                <tbody>
                <?php foreach ($topCustomers as $r): ?>
                    <tr>
                        <td><?= e($r['customer']) ?></td>
                        <td class="num"><?= num($r['qty_shipped']) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($topCustomers === []): ?>
                    <tr><td colspan="2" class="empty">No data</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </section>

        <section class="panel">
            <h2>Top SKUs by Cases Shipped <?= SourceBadge::render('shipments') ?></h2>
            <table>
                <thead><tr><th>Item #</th><th>Cases</th></tr></thead>
                <tbody>
                <?php foreach ($topSkus as $r): ?>
                    <tr>
                        <td><?= e($r['item_number']) ?></td>
                        <td class="num"><?= num($r['qty_shipped']) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($topSkus === []): ?>
                    <tr><td colspan="2" class="empty">No data</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </section>
    </div>

    <?php endif; ?>
</main>

<footer class="footer">
    KPI Dashboard · foundation build · data source: reference workbook (to be replaced by PRIMS / PRODHANA feeds)
</footer>
<script>
function pickWarehouse(btn, val) {
    var form = btn.form;
    form.elements['warehouse'].value = val;
    form.submit();
}
// Auto-apply: changing a date or dropdown filter reloads the data immediately.
document.querySelectorAll('form.filters').forEach(function (f) {
    f.querySelectorAll('input[type="date"], select').forEach(function (el) {
        el.addEventListener('change', function () {
            var from = f.elements['from_date'], to = f.elements['to_date'];
            // ignore incomplete/mid-typing dates (e.g. year still being typed)
            if (el.type === 'date' && from && to && !(from.value >= '1900-01-01' && to.value >= '1900-01-01')) return;
            f.submit();
        });
    });
});
</script>
<script src="assets/views.js"></script>
</body>
</html>
