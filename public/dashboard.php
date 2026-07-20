<?php

declare(strict_types=1);

/**
 * Main Delivery / OMS Dashboard (Damascus-KPI).
 *
 * Mirrors the operations delivery scorecard: order/fulfilment KPI cards, stat
 * tiles, and a filter bar (date range, warehouse, SO, PO, carrier, SO status,
 * pick status). Auto-refreshes every 30 min (plus a manual Refresh button).
 * Reads ONLY from the local delivery_lines cache, which the SAP ETL refreshes.
 * Requires a signed-in network account (see src/Auth.php).
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../src/Auth.php';
require_once __DIR__ . '/../src/DeliveryRepository.php';
require_once __DIR__ . '/../src/DeliveryFilters.php';
require_once __DIR__ . '/../src/WarehouseInventoryRepository.php';
require_once __DIR__ . '/../src/SourceBadge.php';

Auth::requireDepartment('delivery');
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
$byDate = [];
$byWarehouse = [];
$topCustomers = [];
$zeroDelivery = [];
$opts = ['warehouse' => [], 'carrier' => [], 'so_status' => [], 'pick_status' => []];
$stockStages = [];
$lastRefreshed = null;
// Default to the last 7 days so the page loads with data immediately.
$q = $_GET;
if (($q['from_date'] ?? '') === '' && ($q['to_date'] ?? '') === '') {
    $q['from_date'] = date('Y-m-d', strtotime('-6 days'));
    $q['to_date'] = date('Y-m-d');
}
$filters = DeliveryFilters::fromRequest($q);

try {
    $repo = new DeliveryRepository(Database::connection());
    $summary = $repo->summary($filters);
    $byDate = $repo->byDate($filters);
    $byWarehouse = $repo->byWarehouse($filters);
    $topCustomers = $repo->topCustomers($filters, 10);
    $zeroDelivery = $repo->zeroDelivery($filters, 15);
    foreach (array_keys($opts) as $k) {
        $opts[$k] = $repo->options($k, $filters);
    }
    $lastRefreshed = $repo->lastRefreshed();
    $inv = new WarehouseInventoryRepository(Database::connection());
    $stockStages = $inv->stockStages($filters);
} catch (Throwable $ex) {
    $error = 'Unable to load delivery data. Import sql/delivery_dashboard.sql + sql/delivery_seed.sql and check your .env database connection.';
}

$fillRate = isset($summary['fill_rate']) ? (float) $summary['fill_rate'] : null;
$otif = isset($summary['otif_rate']) ? (float) $summary['otif_rate'] : null;
$late = isset($summary['late_rate']) ? (float) $summary['late_rate'] : null;
$short = isset($summary['short_rate']) ? (float) $summary['short_rate'] : null;

/** Colour a "higher is better" ratio against a target. */
function goodHigh(?float $v, float $target): string
{
    if ($v === null) {
        return 'neutral';
    }
    return $v >= $target ? 'good' : ($v >= $target * 0.9 ? 'warn' : 'bad');
}

/** Colour a "lower is better" ratio against a ceiling. */
function goodLow(?float $v, float $ceiling): string
{
    if ($v === null) {
        return 'neutral';
    }
    return $v <= $ceiling ? 'good' : ($v <= $ceiling * 1.5 ? 'warn' : 'bad');
}

/**
 * Render a <select> filter with an "All" option.
 *
 * @param array<int, string> $options
 */
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <!-- Auto-refresh the dashboard every 30 minutes (1800s). Reloads the same
         URL, so the active filters are preserved. -->
    <meta http-equiv="refresh" content="1800">
    <title>KPI Dashboard · Delivery / OMS</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
<header class="topbar">
    <div class="brand">KPI Dashboard</div>
    <div class="subtitle">Delivery / Order Management</div>
    <nav class="topnav">
        <?php foreach (Auth::allowedPages() as $navInfo): ?>
        <a href="<?= e($navInfo['page']) ?>"<?= $navInfo['page'] === 'dashboard.php' ? ' class="active"' : '' ?>><?= e($navInfo['label']) ?></a>
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
    <form class="filters" method="get" action="dashboard.php">
        <div class="filter">
            <label for="from_date">From Date</label>
            <input type="date" id="from_date" name="from_date" value="<?= e($filters->fromDate) ?>">
        </div>
        <div class="filter">
            <label for="to_date">To Date</label>
            <input type="date" id="to_date" name="to_date" value="<?= e($filters->toDate) ?>">
        </div>
        <?php
        warehouseButtons('warehouse', 'Warehouse', DeliveryFilters::WAREHOUSE_GROUPS, $filters->warehouse);
        ?>
        <div class="filter">
            <label for="so">SO Number</label>
            <input type="text" id="so" name="so" placeholder="contains…" value="<?= e($filters->salesOrder) ?>">
        </div>
        <div class="filter">
            <label for="po">PO Number</label>
            <input type="text" id="po" name="po" placeholder="contains…" value="<?= e($filters->po) ?>">
        </div>
        <div class="filter">
            <label for="item">Item</label>
            <input type="text" id="item" name="item" placeholder="code or description…" value="<?= e($filters->item) ?>">
        </div>
        <?php
        selectFilter('carrier', 'Carrier', $opts['carrier'], $filters->carrier, 'All Carriers');
        selectFilter('so_status', 'SO Status', $opts['so_status'], $filters->soStatus, 'All');
        selectFilter('pick_status', 'Pick Status', $opts['pick_status'], $filters->pickStatus, 'All');
        ?>
        <div class="filter-actions">
            <button type="submit" class="btn btn-primary">Apply</button>
            <a class="btn btn-reset" href="dashboard.php">Reset</a>
            <button type="button" class="btn btn-refresh" onclick="window.location.reload()" title="Reload the latest data now (auto-refreshes every 30 min)">Refresh Data</button>
        </div>
    </form>

    <?php if ($error !== null): ?>
        <div class="alert"><?= e($error) ?></div>
    <?php else: ?>

    <p class="panel-note">Order &amp; fulfilment KPIs <?= SourceBadge::render('fulfilment') ?> &mdash; "Delivered" is the SAP delivery-note quantity, not the invoiced quantity.</p>
    <section class="cards">
        <div class="card neutral">
            <div class="card-label">Total Orders</div>
            <div class="card-value"><?= num($summary['total_orders'] ?? 0) ?></div>
            <div class="card-target">unique sales orders</div>
        </div>
        <div class="card good">
            <div class="card-label">Delivered Qty</div>
            <div class="card-value"><?= num($summary['delivered_qty'] ?? 0) ?></div>
            <div class="card-target">mixed units</div>
        </div>
        <div class="card warn">
            <div class="card-label">Released Qty</div>
            <div class="card-value"><?= num($summary['released_qty'] ?? 0) ?></div>
            <div class="card-target">mixed units</div>
        </div>
        <?php $c = goodHigh($fillRate, 0.95); ?>
        <div class="card <?= $c ?>">
            <div class="card-label">Fill Rate</div>
            <div class="card-value"><?= pct($fillRate) ?></div>
            <div class="card-target">delivered ÷ ordered</div>
        </div>
        <div class="card neutral">
            <div class="card-label">Picked Qty</div>
            <div class="card-value"><?= num($summary['pick_qty'] ?? 0) ?></div>
            <div class="card-target">mixed units</div>
        </div>
        <?php $c = goodHigh($otif, 0.95); ?>
        <div class="card <?= $c ?>">
            <div class="card-label">OTIF %</div>
            <div class="card-value"><?= pct($otif) ?></div>
            <div class="card-target">on-time in-full</div>
        </div>
        <?php $c = goodLow($late, 0.05); ?>
        <div class="card <?= $c ?>">
            <div class="card-label">Late Order %</div>
            <div class="card-value"><?= pct($late) ?></div>
            <div class="card-target">delivered late</div>
        </div>
        <?php $c = goodLow($short, 0.02); ?>
        <div class="card <?= $c ?>">
            <div class="card-label">Short Shipment %</div>
            <div class="card-value"><?= pct($short) ?></div>
            <div class="card-target">shipped short</div>
        </div>
    </section>

    <section class="stats">
        <div class="stat"><div class="stat-label">Unique PO</div><div class="stat-value"><?= num($summary['unique_po'] ?? 0) ?></div><div class="stat-note">customer POs (always unique)</div></div>
        <div class="stat"><div class="stat-label">Line Records</div><div class="stat-value"><?= num($summary['line_records'] ?? 0) ?></div><div class="stat-note">a PO can have many items</div></div>
        <div class="stat"><div class="stat-label">Items</div><div class="stat-value"><?= num($summary['items'] ?? 0) ?></div><div class="stat-note">distinct item codes</div></div>
        <div class="stat"><div class="stat-label">Total Qty</div><div class="stat-value"><?= num($summary['total_qty'] ?? 0) ?></div><div class="stat-note">ordered quantity</div></div>
        <div class="stat"><div class="stat-label">Delivered</div><div class="stat-value"><?= num($summary['delivered_qty'] ?? 0) ?></div><div class="stat-note">actual delivered qty</div></div>
        <div class="stat"><div class="stat-label">Zero Delivery</div><div class="stat-value"><?= num($summary['zero_delivery_pos'] ?? 0) ?> PO</div><div class="stat-note"><?= num($summary['zero_delivery_lines'] ?? 0) ?> item lines</div></div>
    </section>

    <section class="panel">
        <h2>Stock Stage Tracking <?= SourceBadge::render('movements') ?></h2>
        <p class="panel-note">Where stock sits across the lifecycle — on-hand raw stock and finished goods from the inventory cache (<code>etl/pull_inventory.php</code>); staging, production and waste movements from <code>material_movements</code> over the selected date range.</p>
        <div class="flow">
            <div class="flow-step whs"><div class="fs-k">On-Hand Stock</div><div class="fs-v"><?= num($stockStages['on_hand'] ?? null) ?></div><div class="fs-sub">all warehouses</div></div>
            <div class="flow-arrow">&rarr;</div>
            <div class="flow-step stg"><div class="fs-k">To Staging</div><div class="fs-v"><?= num($stockStages['to_staging'] ?? null) ?></div><div class="fs-sub">transferred</div></div>
            <div class="flow-arrow">&rarr;</div>
            <div class="flow-step prd"><div class="fs-k">To Production</div><div class="fs-v"><?= num($stockStages['to_production'] ?? null) ?></div><div class="fs-sub">issued</div></div>
            <div class="flow-arrow">&rarr;</div>
            <div class="flow-step fgd"><div class="fs-k">Finished Goods</div><div class="fs-v"><?= num($stockStages['finished_goods'] ?? null) ?></div><div class="fs-sub">FG warehouses</div></div>
            <div class="flow-arrow">&rarr;</div>
            <div class="flow-step wst"><div class="fs-k">Returned / Wasted</div><div class="fs-v"><?= num($stockStages['waste'] ?? null) ?></div><div class="fs-sub">waste movements</div></div>
        </div>
    </section>

    <div class="grid">
        <section class="panel">
            <h2>Fulfilment by Date <?= SourceBadge::render('fulfilment') ?></h2>
            <table>
                <thead>
                    <tr><th>Date</th><th class="num">Orders</th><th class="num">Ordered</th><th class="num">Delivered</th><th class="num">Fill Rate</th><th class="num">OTIF</th></tr>
                </thead>
                <tbody>
                <?php foreach ($byDate as $r): ?>
                    <tr>
                        <td><?= e($r['posting_date']) ?></td>
                        <td class="num"><?= num($r['orders']) ?></td>
                        <td class="num"><?= num($r['order_qty']) ?></td>
                        <td class="num"><?= num($r['delivered_qty']) ?></td>
                        <td class="num"><?= pct($r['fill_rate']) ?></td>
                        <td class="num"><?= pct($r['otif_rate']) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($byDate === []): ?>
                    <tr><td colspan="6" class="empty">No data</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </section>

        <section class="panel">
            <h2>Fulfilment by Warehouse <?= SourceBadge::render('fulfilment') ?></h2>
            <table>
                <thead><tr><th>Warehouse</th><th class="num">Lines</th><th class="num">Ordered</th><th class="num">Delivered</th><th class="num">Fill Rate</th></tr></thead>
                <tbody>
                <?php foreach ($byWarehouse as $r): ?>
                    <tr>
                        <td><?= e($r['warehouse']) ?></td>
                        <td class="num"><?= num($r['line_count']) ?></td>
                        <td class="num"><?= num($r['order_qty']) ?></td>
                        <td class="num"><?= num($r['delivered_qty']) ?></td>
                        <td class="num"><?= pct($r['fill_rate']) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($byWarehouse === []): ?>
                    <tr><td colspan="5" class="empty">No data</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </section>

        <section class="panel">
            <h2>Top Customers by Delivered Qty <?= SourceBadge::render('delivered') ?></h2>
            <table>
                <thead><tr><th>Customer</th><th class="num">Delivered</th><th class="num">Fill Rate</th></tr></thead>
                <tbody>
                <?php foreach ($topCustomers as $r): ?>
                    <tr>
                        <td><?= e($r['customer_name']) ?></td>
                        <td class="num"><?= num($r['delivered_qty']) ?></td>
                        <td class="num"><?= pct($r['fill_rate']) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($topCustomers === []): ?>
                    <tr><td colspan="3" class="empty">No data</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </section>

        <section class="panel">
            <h2>Zero-Delivery Lines <?= SourceBadge::render('delivered') ?></h2>
            <table>
                <thead><tr><th>SO</th><th>PO</th><th>Customer</th><th>Item</th><th>Description</th><th class="num">Ordered</th><th>Pick Status</th></tr></thead>
                <tbody>
                <?php foreach ($zeroDelivery as $r): ?>
                    <tr>
                        <td><?= e($r['sales_order']) ?></td>
                        <td><?= e($r['po_number']) ?></td>
                        <td><?= e($r['customer_name']) ?></td>
                        <td><?= e($r['item_code']) ?></td>
                        <td><?= e($r['item_description']) ?></td>
                        <td class="num"><?= num($r['order_qty']) ?></td>
                        <td><?= e($r['pick_status']) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($zeroDelivery === []): ?>
                    <tr><td colspan="7" class="empty">No zero-delivery lines</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </section>
    </div>

    <?php endif; ?>
</main>

<footer class="footer">
    KPI Dashboard · Delivery / OMS · source: SAP Business One (PRODHANA) via local cache<?php if ($lastRefreshed): ?> · data refreshed <?= e($lastRefreshed) ?><?php endif; ?>
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
