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
require_once __DIR__ . '/../src/WarehouseInventoryRepository.php';
require_once __DIR__ . '/../src/DeliveryFilters.php';

Auth::requireDepartment('warehouse');
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
$opts = ['warehouse' => [], 'carrier' => [], 'so_status' => []];
$lastRefreshed = null;
$lpnStatus = isset($_GET['lpn_status']) && is_string($_GET['lpn_status']) ? trim($_GET['lpn_status']) : '';
$hasLpn = false;
$lpnSummary = ['pallets' => 0, 'warehouses' => 0, 'items' => 0, 'total_qty' => 0, 'expired' => 0];
$lpnByWarehouse = [];
$lpnRows = [];
$lpnStatusOpts = [];
// Expanded inventory view (migration 010). Each panel degrades to a
// "how to load" hint until its ETL has populated the cache.
$invSummary = ['warehouses' => 0, 'materials' => 0, 'on_hand_pallets' => 0.0, 'aged_90' => 0, 'expired' => 0, 'waste_pct' => null];
$hasStock = false;
$hasBatches = false;
$hasPackaging = false;
$hasMovements = false;
$stockRows = [];
$packagingRows = [];
$agedByWarehouse = [];
$agedOutRows = [];
$movementFlow = ['receipt' => 0.0, 'transfer' => 0.0, 'issue' => 0.0, 'waste' => 0.0];
$filters = DeliveryFilters::fromRequest($_GET);

try {
    $repo = new DeliveryRepository(Database::connection());
    $byWarehouse = $repo->byWarehouse($filters);
    $warehouseCapacity = $repo->warehouseCapacity($filters);
    $opts['warehouse'] = $repo->options('warehouse');
    $opts['carrier'] = $repo->options('carrier', $filters);
    $opts['so_status'] = $repo->options('so_status', $filters);
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

    // Expanded warehouse inventory: stock, packaging, batch aging, movement.
    $inv = new WarehouseInventoryRepository(Database::connection());
    $hasStock = $inv->hasStock();
    $hasBatches = $inv->hasBatches();
    $hasPackaging = $inv->hasPackaging();
    $hasMovements = $inv->hasMovements();
    $invSummary = $inv->summary($filters);
    $stockRows = $inv->stockRows($filters);
    $packagingRows = $inv->packagingRows($filters);
    $agedByWarehouse = $inv->agedByWarehouse($filters);
    $agedOutRows = $inv->agedOutRows($filters);
    $movementFlow = $inv->movementFlow($filters);
    // Filter buttons cover every known warehouse: delivery history plus the
    // inventory caches (stock/batches), so RM/staging warehouses are selectable.
    $opts['warehouse'] = array_values(array_unique(array_merge($opts['warehouse'], $inv->warehouseOptions())));
    sort($opts['warehouse']);
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

/** @param array<int, string> $options */
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
        <?php foreach (Auth::allowedPages() as $navInfo): ?>
        <a href="<?= e($navInfo['page']) ?>"<?= $navInfo['page'] === 'warehouse.php' ? ' class="active"' : '' ?>><?= e($navInfo['label']) ?></a>
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
            <label for="so">SO Number</label>
            <input type="text" id="so" name="so" placeholder="contains…" value="<?= e($filters->salesOrder) ?>">
        </div>
        <div class="filter">
            <label for="item">Item / LPN / Batch</label>
            <input type="text" id="item" name="item" placeholder="code, desc, LPN, batch…" value="<?= e($filters->item) ?>">
        </div>
        <?php
        selectFilter('carrier', 'Carrier', $opts['carrier'], $filters->carrier, 'All Carriers');
        selectFilter('so_status', 'SO Status', $opts['so_status'], $filters->soStatus, 'All');
        ?>
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
            <p class="panel-note">Pallets are case-to-pallet converted with the same precedence as the capacity table (SAP pallet count, else units/pallet, else the warehouse default).</p>
            <table>
                <thead><tr><th>Warehouse</th><th class="num">Lines</th><th class="num">Ordered</th><th class="num">Delivered</th><th class="num">Pallets</th><th class="num">Fill Rate</th></tr></thead>
                <tbody>
                <?php foreach ($byWarehouse as $r): ?>
                    <tr>
                        <td><?= e($r['warehouse']) ?></td>
                        <td class="num"><?= num($r['line_count']) ?></td>
                        <td class="num"><?= num($r['order_qty']) ?></td>
                        <td class="num"><?= num($r['delivered_qty']) ?></td>
                        <td class="num"><?= pallets($r['delivered_pallets']) ?></td>
                        <td class="num"><?= pct($r['fill_rate']) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($byWarehouse === []): ?>
                    <tr><td colspan="6" class="empty">No data</td></tr>
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

    <?php
        $invHasAny = $hasStock || $hasBatches || $hasPackaging || $hasMovements;
        $waste = $movementFlow['issue'] + $movementFlow['waste'];
    ?>
    <section class="cards">
        <div class="card brand">
            <div class="card-label">Materials on hand</div>
            <div class="card-value"><?= num($invSummary['materials']) ?></div>
            <div class="card-target">SKUs with stock</div>
        </div>
        <div class="card neutral">
            <div class="card-label">On-hand Pallets</div>
            <div class="card-value"><?= pallets($invSummary['on_hand_pallets']) ?></div>
            <div class="card-target">across warehouses</div>
        </div>
        <div class="card warn">
            <div class="card-label">Aged &gt; 90 days</div>
            <div class="card-value"><?= num($invSummary['aged_90']) ?></div>
            <div class="card-target">batches at risk</div>
        </div>
        <div class="card bad">
            <div class="card-label">Expired</div>
            <div class="card-value"><?= num($invSummary['expired']) ?></div>
            <div class="card-target">batches &mdash; dispose</div>
        </div>
        <div class="card neutral">
            <div class="card-label">Waste (period)</div>
            <div class="card-value"><?= $invSummary['waste_pct'] !== null ? pct($invSummary['waste_pct']) : '—' ?></div>
            <div class="card-target">of issued qty</div>
        </div>
    </section>

    <section class="panel panel-wide">
        <h2>Material Flow &mdash; Warehouse &rarr; Staging &rarr; Production &rarr; Waste</h2>
        <p class="panel-note">Period totals in base UoM, reconstructed from SAP inventory documents (stock transfers, goods issue to production, and scrap issues). Source: <code>material_movements</code> (migration <code>010</code> + <code>etl/pull_inventory.php --what=movements</code>).</p>
        <?php if (!$hasMovements): ?>
            <p class="empty">No movement data loaded yet. Run migration <code>010_warehouse_inventory.sql</code>, map the columns with <code>etl/queries/inventory_discover_sqlsrv.sql</code>, then load with <code>php etl/pull_inventory.php --what=movements --source=PRIMSBM</code>.</p>
        <?php else: ?>
            <div class="flow">
                <div class="flow-step whs"><div class="fs-k">Warehouse (raw)</div><div class="fs-v"><?= num($movementFlow['receipt']) ?></div><div class="fs-sub">received</div></div>
                <div class="flow-arrow">&rarr;</div>
                <div class="flow-step stg"><div class="fs-k">Staging</div><div class="fs-v"><?= num($movementFlow['transfer']) ?></div><div class="fs-sub">transferred</div></div>
                <div class="flow-arrow">&rarr;</div>
                <div class="flow-step prd"><div class="fs-k">Production</div><div class="fs-v"><?= num($movementFlow['issue']) ?></div><div class="fs-sub">consumed</div></div>
                <div class="flow-arrow">&rarr;</div>
                <div class="flow-step wst"><div class="fs-k">Waste / Scrap</div><div class="fs-v"><?= num($movementFlow['waste']) ?></div><div class="fs-sub"><?= $waste > 0 ? pct($movementFlow['waste'] / $waste) : '—' ?></div></div>
            </div>
        <?php endif; ?>
    </section>

    <div class="grid">
        <section class="panel">
            <h2>Stock on Hand &mdash; Material &times; Warehouse</h2>
            <p class="panel-note">On-hand quantity per item per warehouse. Source: <code>warehouse_stock</code> (OITW + OITM) via <code>etl/pull_inventory.php --what=stock</code>.</p>
            <?php if (!$hasStock): ?>
                <p class="empty">No stock loaded yet. Run migration <code>010</code>, then <code>php etl/pull_inventory.php --what=stock --source=PRIMSBM</code>.</p>
            <?php else: ?>
            <div class="lpn-scroll">
            <table>
                <thead><tr><th>Material</th><th>Warehouse</th><th class="num">On Hand</th><th>UoM</th><th class="num">Pallets</th></tr></thead>
                <tbody>
                <?php foreach ($stockRows as $r): ?>
                    <tr>
                        <td><?= e($r['item_code']) ?><?php if ($r['item_description']): ?><span class="muted"> · <?= e($r['item_description']) ?></span><?php endif; ?></td>
                        <td><?= e($r['warehouse']) ?></td>
                        <td class="num"><?= num($r['on_hand']) ?></td>
                        <td><?= e($r['unit_of_measure']) ?: '<span class="muted">—</span>' ?></td>
                        <td class="num"><?= $r['pallets'] !== null ? pallets($r['pallets']) : '—' ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($stockRows === []): ?>
                    <tr><td colspan="5" class="empty">No stock matches the current filters.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
            </div>
            <?php endif; ?>
        </section>

        <section class="panel">
            <h2>Packaging &mdash; Case / Bundle / Bag per Pallet</h2>
            <p class="panel-note">UoM conversion per material. Source: <code>material_packaging</code> (OITM UoM + Beas pallet master).</p>
            <?php if (!$hasPackaging): ?>
                <p class="empty">No packaging data loaded yet. Run migration <code>010</code>, then <code>php etl/pull_inventory.php --what=packaging --source=PRIMSBM</code>.</p>
            <?php else: ?>
            <div class="lpn-scroll">
            <table>
                <thead><tr><th>Material</th><th class="num">Per Case</th><th class="num">Cases / Pallet</th><th class="num">Units / Pallet</th><th>Base UoM</th></tr></thead>
                <tbody>
                <?php foreach ($packagingRows as $r): ?>
                    <tr>
                        <td><?= e($r['item_code']) ?><?php if ($r['item_description']): ?><span class="muted"> · <?= e($r['item_description']) ?></span><?php endif; ?></td>
                        <td class="num"><?= $r['units_per_case'] !== null ? num($r['units_per_case']) : ($r['pack_description'] ? e($r['pack_description']) : '—') ?></td>
                        <td class="num"><?= $r['cases_per_pallet'] !== null ? num($r['cases_per_pallet']) : '—' ?></td>
                        <td class="num"><?= $r['units_per_pallet'] !== null ? num($r['units_per_pallet']) : '—' ?></td>
                        <td><?= e($r['base_uom']) ?: '<span class="muted">—</span>' ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($packagingRows === []): ?>
                    <tr><td colspan="5" class="empty">No packaging matches the current filters.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
            </div>
            <?php endif; ?>
        </section>
    </div>

    <section class="panel panel-wide">
        <h2>Aged Material by Warehouse <span class="pill info">SCRUM-14</span></h2>
        <p class="panel-note">Age buckets from batch admission/expiry. <strong>% Aged</strong> = batches over 90 days &divide; total. Source: <code>inventory_batches</code> (OBTN + OIBT) via <code>etl/pull_inventory.php --what=batches</code>.</p>
        <?php if (!$hasBatches): ?>
            <p class="empty">No batch data loaded yet. Run migration <code>010</code>, map columns with <code>etl/queries/inventory_discover_sqlsrv.sql</code>, then <code>php etl/pull_inventory.php --what=batches --source=PRIMSBM</code>.</p>
        <?php else: ?>
            <div class="legend">
                <span><i class="a0"></i>0&ndash;30d</span><span><i class="a30"></i>30&ndash;60d</span><span><i class="a60"></i>60&ndash;90d</span><span><i class="a90"></i>90d+ / expired</span>
            </div>
            <table>
                <thead><tr><th>Warehouse</th><th class="num">Batches</th><th class="num">Pallets</th><th>Age Distribution</th><th class="num">Aged &gt; 90d</th><th class="num">Expired</th><th class="num">% Aged</th></tr></thead>
                <tbody>
                <?php foreach ($agedByWarehouse as $r): ?>
                    <?php
                        $tot = (int) $r['batches'];
                        $w = static fn(int $n): string => $tot > 0 ? number_format($n / $tot * 100, 1) : '0';
                        $pctAged = $tot > 0 ? (int) $r['b90'] / $tot : 0.0;
                        $pill = $pctAged >= 0.10 ? 'bad' : ($pctAged >= 0.05 ? 'warn' : 'good');
                    ?>
                    <tr>
                        <td><?= e($r['warehouse']) ?></td>
                        <td class="num"><?= num($tot) ?></td>
                        <td class="num"><?= pallets($r['total_pallets']) ?></td>
                        <td>
                            <div class="age-bar">
                                <span class="a0" style="width:<?= $w((int) $r['b0_30']) ?>%"></span>
                                <span class="a30" style="width:<?= $w((int) $r['b30_60']) ?>%"></span>
                                <span class="a60" style="width:<?= $w((int) $r['b60_90']) ?>%"></span>
                                <span class="a90" style="width:<?= $w((int) $r['b90']) ?>%"></span>
                            </div>
                        </td>
                        <td class="num"><?= num($r['b90']) ?></td>
                        <td class="num"><?= num($r['expired']) ?></td>
                        <td class="num"><span class="pill <?= $pill ?>"><?= pct($pctAged, 1) ?></span></td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($agedByWarehouse === []): ?>
                    <tr><td colspan="7" class="empty">No batches match the current filters.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </section>

    <?php if ($hasBatches): ?>
    <section class="panel panel-wide">
        <h2>Aged-Out Material List</h2>
        <p class="panel-note">Batches past the 90-day / expiry threshold, oldest first &mdash; the disposal / rotation worklist.</p>
        <div class="lpn-scroll">
        <table>
            <thead><tr><th>Material</th><th>Batch</th><th>Warehouse</th><th class="num">Qty</th><th class="num">Pallets</th><th>Admission</th><th>Expiry</th><th class="num">Age (days)</th><th>Status</th></tr></thead>
            <tbody>
            <?php foreach ($agedOutRows as $r): ?>
                <tr<?= (int) $r['is_expired'] === 1 ? ' class="lpn-expired"' : '' ?>>
                    <td><?= e($r['item_code']) ?><?php if ($r['item_description']): ?><span class="muted"> · <?= e($r['item_description']) ?></span><?php endif; ?></td>
                    <td><?= e($r['batch_number']) ?: '<span class="muted">—</span>' ?></td>
                    <td><?= e($r['warehouse']) ?></td>
                    <td class="num"><?= $r['quantity'] !== null ? num($r['quantity']) . ' ' . e($r['unit_of_measure']) : '—' ?></td>
                    <td class="num"><?= $r['pallets'] !== null ? pallets($r['pallets']) : '—' ?></td>
                    <td><?= e($r['admission_date']) ?: '<span class="muted">—</span>' ?></td>
                    <td><?= e($r['expiry_date']) ?: '<span class="muted">—</span>' ?></td>
                    <td class="num"><?= $r['age_days'] !== null ? num($r['age_days']) : '—' ?></td>
                    <td><?php if ((int) $r['is_expired'] === 1): ?><span class="pill bad">Expired</span><?php else: ?><span class="pill warn">Aged 90d+</span><?php endif; ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if ($agedOutRows === []): ?>
                <tr><td colspan="9" class="empty">Nothing aged out &mdash; all batches within threshold.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
        </div>
    </section>
    <?php endif; ?>

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
