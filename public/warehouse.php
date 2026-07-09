<?php

declare(strict_types=1);

/**
 * Warehouse dashboard (Damascus-KPI).
 *
 * Dedicated view for warehouse operations: a warehouse KPI summary, fulfilment
 * by warehouse, and the capacity + case-to-pallet throughput table. Shares the
 * same delivery filters (date range, warehouse, item) and the local
 * delivery_lines cache as the Delivery dashboard.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../src/Auth.php';
require_once __DIR__ . '/../src/DeliveryRepository.php';
require_once __DIR__ . '/../src/LpnRepository.php';
require_once __DIR__ . '/../src/DeliveryFilters.php';

Auth::requireLogin();
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

/** Format a pallet count with one decimal (case-to-pallet conversion is fractional). */
function pallets(mixed $v): string
{
    if ($v === null || $v === '') {
        return '—';
    }
    return number_format((float) $v, 1);
}

$error = null;
$byWarehouse = [];
$warehouseCapacity = [];
$opts = ['warehouse' => []];
$lastRefreshed = null;
$lpnStatus = isset($_GET['lpn_status']) && is_string($_GET['lpn_status']) ? trim($_GET['lpn_status']) : '';
$hasLpn = false;
$lpnSummary = ['pallets' => 0, 'warehouses' => 0, 'items' => 0, 'total_qty' => 0, 'expired' => 0];
$lpnByWarehouse = [];
$lpnRows = [];
$lpnStatusOpts = [];
$filters = DeliveryFilters::fromRequest($_GET);

try {
    $repo = new DeliveryRepository(Database::connection());
    $byWarehouse = $repo->byWarehouse($filters);
    $warehouseCapacity = $repo->warehouseCapacity($filters);
    $opts['warehouse'] = $repo->options('warehouse');
    $lastRefreshed = $repo->lastRefreshed();

    // LPN (License Plate Number) pallet detail for the warehouse team. Only
    // shown once the WMS ETL has loaded lpn_pallets (migration 008 + pull_lpn.php).
    $lpn = new LpnRepository(Database::connection());
    $hasLpn = $lpn->hasData();
    if ($hasLpn) {
        $lpnSummary = $lpn->summary($filters);
        $lpnByWarehouse = $lpn->byWarehouse($filters);
        $lpnRows = $lpn->rows($filters, 200, $lpnStatus !== '' ? $lpnStatus : null);
        $lpnStatusOpts = $lpn->options('status');
    }
} catch (Throwable $ex) {
    $error = 'Unable to load warehouse data. Import sql/delivery_dashboard.sql + run migration 005_warehouse_capacity.sql and check your .env database connection.';
}

// Warehouse summary rolled up from the capacity view (period throughput).
$whCount = count($warehouseCapacity);
$totalDeliveredPallets = 0.0;
$totalDeliveredQty = 0.0;
$capacityRows = 0;
$utilSum = 0.0;
foreach ($warehouseCapacity as $r) {
    $totalDeliveredPallets += (float) $r['delivered_pallets'];
    $totalDeliveredQty += (float) $r['delivered_qty'];
    $cap = ($r['pallet_capacity'] === null || (float) $r['pallet_capacity'] <= 0)
        ? null : (float) $r['pallet_capacity'];
    if ($cap !== null) {
        $capacityRows++;
        $utilSum += ((float) $r['delivered_pallets']) / $cap;
    }
}
$avgUtil = $capacityRows > 0 ? $utilSum / $capacityRows : null;

/**
 * Render the warehouse filter as a row of one-click buttons; the active
 * warehouse is carried in a hidden field so it survives when other filters
 * are applied.
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
    <meta http-equiv="refresh" content="1800">
    <title>KPI Dashboard · Warehouse</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
<header class="topbar">
    <div class="brand">KPI Dashboard</div>
    <div class="subtitle">Warehouse</div>
    <nav class="topnav">
        <a href="overview.php">Overview</a>
        <a href="dashboard.php">Delivery</a>
        <a href="warehouse.php" class="active">Warehouse</a>
        <a href="dashboard_cs.php">Customer Service</a>
        <a href="audit.php">Audit</a>
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
    <form class="filters" method="get" action="warehouse.php">
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
            <label for="item">Item / LPN / Batch</label>
            <input type="text" id="item" name="item" placeholder="code, desc, LPN, batch…" value="<?= e($filters->item) ?>">
        </div>
        <div class="filter-actions">
            <button type="submit" class="btn btn-primary">Apply</button>
            <a class="btn btn-reset" href="warehouse.php">Reset</a>
            <button type="button" class="btn btn-refresh" onclick="window.location.reload()" title="Reload the latest data now (auto-refreshes every 30 min)">Refresh Data</button>
        </div>
    </form>

    <?php if ($error !== null): ?>
        <div class="alert"><?= e($error) ?></div>
    <?php else: ?>

    <section class="cards">
        <div class="card neutral">
            <div class="card-label">Warehouses</div>
            <div class="card-value"><?= num($whCount) ?></div>
            <div class="card-target">in selected period</div>
        </div>
        <div class="card good">
            <div class="card-label">Delivered Qty</div>
            <div class="card-value"><?= num($totalDeliveredQty) ?></div>
            <div class="card-target">mixed units</div>
        </div>
        <div class="card good">
            <div class="card-label">Delivered Pallets</div>
            <div class="card-value"><?= pallets($totalDeliveredPallets) ?></div>
            <div class="card-target">case-to-pallet converted</div>
        </div>
        <div class="card neutral">
            <div class="card-label">Avg % of Capacity</div>
            <div class="card-value"><?= $avgUtil !== null ? pct($avgUtil) : '—' ?></div>
            <div class="card-target"><?= $capacityRows > 0 ? num($capacityRows) . ' of ' . num($whCount) . ' set' : 'set capacities' ?></div>
        </div>
    </section>

    <div class="grid">
        <section class="panel">
            <h2>Fulfilment by Warehouse</h2>
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
            <h2>Warehouse Capacity &amp; Pallets</h2>
            <p class="panel-note">Pallets are case-to-pallet converted (SAP pallet count, else units/pallet, else the warehouse default). <strong>% of Capacity</strong> is pallets shipped in the selected period vs configured capacity &mdash; not live on-hand inventory. Set real capacities in the <code>warehouse_capacity</code> table.</p>
            <table>
                <thead><tr><th>Warehouse</th><th class="num">Capacity (pallets)</th><th class="num">Ordered Qty</th><th class="num">Delivered Qty</th><th class="num">Delivered Pallets</th><th class="num">% of Capacity</th></tr></thead>
                <tbody>
                <?php foreach ($warehouseCapacity as $r): ?>
                    <?php
                        $cap = ($r['pallet_capacity'] === null || (float) $r['pallet_capacity'] <= 0)
                            ? null : (float) $r['pallet_capacity'];
                        $util = $cap !== null ? ((float) $r['delivered_pallets']) / $cap : null;
                    ?>
                    <tr>
                        <td><?= e($r['warehouse']) ?></td>
                        <td class="num"><?= $cap !== null ? num($cap) : '<span class="muted">set capacity</span>' ?></td>
                        <td class="num"><?= num($r['order_qty']) ?></td>
                        <td class="num"><?= num($r['delivered_qty']) ?></td>
                        <td class="num"><?= pallets($r['delivered_pallets']) ?></td>
                        <td class="num"><?= $util !== null ? pct($util) : '—' ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($warehouseCapacity === []): ?>
                    <tr><td colspan="6" class="empty">No data</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </section>
    </div>

    <section class="panel panel-wide">
        <h2>LPN &mdash; Pallet License Plates</h2>
        <p class="panel-note">Live pallet detail (License Plate Numbers) from the WMS &mdash; contents, batch, bin location and status &mdash; to support the warehouse team. Filter by warehouse/item above; use the search box for an LPN, batch or bin. Source: Beas WMS pallet master via <code>lpn_pallets</code> (migration <code>008</code> + <code>etl/pull_lpn.php</code>).</p>

        <?php if (!$hasLpn): ?>
            <p class="empty">No LPN data loaded yet. Run migration <code>008_lpn_pallets.sql</code>, confirm the WMS column mapping with <code>etl/queries/lpn_discover_sqlsrv.sql</code>, then load it with <code>php etl/pull_lpn.php --source=PRIMSBM</code>.</p>
        <?php else: ?>
            <div class="lpn-stats">
                <span class="lpn-stat"><span class="lpn-stat-v"><?= num($lpnSummary['pallets']) ?></span><span class="lpn-stat-k">Pallets</span></span>
                <span class="lpn-stat"><span class="lpn-stat-v"><?= num($lpnSummary['warehouses']) ?></span><span class="lpn-stat-k">Warehouses</span></span>
                <span class="lpn-stat"><span class="lpn-stat-v"><?= num($lpnSummary['items']) ?></span><span class="lpn-stat-k">Items</span></span>
                <span class="lpn-stat"><span class="lpn-stat-v"><?= num($lpnSummary['total_qty']) ?></span><span class="lpn-stat-k">Total Qty</span></span>
                <span class="lpn-stat<?= (int) $lpnSummary['expired'] > 0 ? ' lpn-stat-warn' : '' ?>"><span class="lpn-stat-v"><?= num($lpnSummary['expired']) ?></span><span class="lpn-stat-k">Expired</span></span>
            </div>

            <div class="lpn-split">
                <table class="lpn-wh">
                    <thead><tr><th>Warehouse</th><th class="num">Pallets</th><th class="num">Qty</th><th class="num">Expired</th></tr></thead>
                    <tbody>
                    <?php foreach ($lpnByWarehouse as $r): ?>
                        <tr>
                            <td><?= e($r['warehouse']) ?></td>
                            <td class="num"><?= num($r['pallets']) ?></td>
                            <td class="num"><?= num($r['total_qty']) ?></td>
                            <td class="num"><?= num($r['expired']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>

                <div class="lpn-detail">
                    <?php if ($lpnStatusOpts !== []): ?>
                    <form class="lpn-statusbar" method="get" action="warehouse.php">
                        <?php foreach (['from_date','to_date','warehouse','item'] as $carry): ?>
                            <?php $cv = $_GET[$carry] ?? ''; if (is_string($cv) && $cv !== ''): ?>
                                <input type="hidden" name="<?= e($carry) ?>" value="<?= e($cv) ?>">
                            <?php endif; ?>
                        <?php endforeach; ?>
                        <label for="lpn_status">Status</label>
                        <select id="lpn_status" name="lpn_status" onchange="this.form.submit()">
                            <option value="">All</option>
                            <?php foreach ($lpnStatusOpts as $st): ?>
                                <option value="<?= e($st) ?>"<?= $lpnStatus === $st ? ' selected' : '' ?>><?= e($st) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <span class="muted">showing up to 200 pallets</span>
                    </form>
                    <?php endif; ?>
                    <div class="lpn-scroll">
                    <table>
                        <thead><tr><th>LPN</th><th>Status</th><th>Warehouse</th><th>Bin</th><th>Item</th><th>Batch</th><th class="num">Qty</th><th>Received</th><th>Expiry</th></tr></thead>
                        <tbody>
                        <?php foreach ($lpnRows as $r): ?>
                            <tr<?= (int) $r['is_expired'] === 1 ? ' class="lpn-expired"' : '' ?>>
                                <td><?= e($r['lpn']) ?></td>
                                <td><?= e($r['status']) ?></td>
                                <td><?= e($r['warehouse']) ?></td>
                                <td><?= e($r['bin_location']) ?: '<span class="muted">—</span>' ?></td>
                                <td><?= e($r['item_code']) ?><?php if ($r['item_description']): ?><span class="muted"> · <?= e($r['item_description']) ?></span><?php endif; ?></td>
                                <td><?= e($r['batch_number']) ?: '<span class="muted">—</span>' ?></td>
                                <td class="num"><?= $r['quantity'] !== null ? num($r['quantity']) . ' ' . e($r['unit_of_measure']) : '—' ?></td>
                                <td><?= e($r['received_date']) ?: '<span class="muted">—</span>' ?></td>
                                <td><?= e($r['expiry_date']) ?: '<span class="muted">—</span>' ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if ($lpnRows === []): ?>
                            <tr><td colspan="9" class="empty">No pallets match the current filters.</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </section>

    <?php endif; ?>
</main>

<footer class="footer">
    KPI Dashboard · Warehouse · source: SAP Business One (PRODHANA) via local cache<?php if ($lastRefreshed): ?> · data refreshed <?= e($lastRefreshed) ?><?php endif; ?>
</footer>
<script>
function pickWarehouse(btn, val) {
    var form = btn.form;
    form.elements['warehouse'].value = val;
    form.submit();
}
</script>
</body>
</html>
