<?php

declare(strict_types=1);

/**
 * Procurement dashboard — SCRUM-88.
 *
 * Supplier OTIF %: purchase orders received from suppliers on-time AND in-full,
 * measured at the whole-PO level (one bad line fails the PO), mirroring the
 * sales-side order OTIF (SCRUM-86). Reads the local `po_lines` cache refreshed
 * by etl/pull_po.php — never queries SAP directly.
 *
 * Inventory Days of Supply (SCRUM-92): how long current on-hand lasts at the
 * trailing 30-day usage rate, per item x warehouse. Reads the local
 * `inventory_supply` cache refreshed by etl/pull_inventory_supply.php.
 *
 * Slow/Obsolete Inventory % (SCRUM-91): share of stocked item-warehouses with
 * no outbound movement in the trailing 90 days (provisional window). Reads
 * the same cache; shares the inv_* filters with the days-of-supply panel.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../src/Auth.php';
require_once __DIR__ . '/../src/PoRepository.php';
require_once __DIR__ . '/../src/InventorySupplyRepository.php';
require_once __DIR__ . '/../src/SourceBadge.php';

Auth::requireDepartment('procurement');
$canSeeFinancials = Auth::isCLevel();

/** HTML-escape helper. */
function e(mixed $v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}

/** Format an integer with thousands separators. */
function num(mixed $v): string
{
    if ($v === null || $v === '') {
        return '—';
    }
    return number_format((float) $v);
}

/** RAG class for a days-of-supply value (7 / 14-day thresholds). */
function dosClass(?float $days): string
{
    if ($days === null) {
        return 'neutral';
    }
    return $days >= 14 ? 'good' : ($days >= 7 ? 'warn' : 'bad');
}

/** RAG class for an OTIF %, matching the Overview thresholds. */
function otifClass(?float $rate): string
{
    if ($rate === null) {
        return 'neutral';
    }
    return $rate >= 95 ? 'good' : ($rate >= 85 ? 'warn' : 'bad');
}

/** Validate a YYYY-MM-DD date input, else null. */
function dateParam(string $key): ?string
{
    $v = trim((string) ($_GET[$key] ?? ''));
    if ($v === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
        return null;
    }
    return $v;
}

$supplier  = trim((string) ($_GET['supplier'] ?? '')) ?: null;
$warehouse = trim((string) ($_GET['warehouse'] ?? '')) ?: null;
$from      = dateParam('from_date');
$to        = dateParam('to_date');
// Default to the last 7 days so the page loads with data immediately.
if ($from === null && $to === null) {
    $from = date('Y-m-d', strtotime('-6 days'));
    $to = date('Y-m-d');
}

$invCategory  = trim((string) ($_GET['inv_category'] ?? '')) ?: null;
$invWarehouse = trim((string) ($_GET['inv_warehouse'] ?? '')) ?: null;
$invItem      = trim((string) ($_GET['inv_item'] ?? '')) ?: null;

$error = null;
$hasData = false;
$otif = ['total_pos' => 0, 'otif_pos' => 0, 'otif_rate' => null];
$suppliers = [];
$warehouses = [];
$rows = [];
$trend = [];
$lastRefreshed = null;

$invAvailable = false;
$invHasData = false;
$invSummary = ['critical' => 0, 'low' => 0, 'ok' => 0, 'healthy' => 0, 'stocked_out' => 0, 'no_usage' => 0, 'measured' => 0];
$invRows = [];
$slowSummary = ['slow' => 0, 'stocked' => 0, 'slow_pct' => null];
$slowRows = [];
$slowAvailable = false;
$invCategories = [];
$invWarehouses = [];
$invLastRefreshed = null;

try {
    $repo = new PoRepository(Database::connection());
    $hasData = $repo->hasData();
    $suppliers = $repo->options('supplier');
    $warehouses = $repo->options('warehouse');
    $otif = $repo->supplierOtif($supplier, $warehouse, $from, $to);
    $rows = $repo->bySupplier($supplier, $warehouse, $from, $to);
    $trend = $repo->weeklyTrend($supplier, $warehouse, 8);
    $lastRefreshed = $repo->lastRefreshed();
} catch (Throwable $ex) {
    $error = 'Purchase-order cache not available. Apply sql/migrations/018_po_lines.sql, then load with '
        . 'php etl/pull_po.php --source=PRIMSBM --query=etl/queries/prodhana_po.sql --via=PRODHANA';
}

try {
    $invRepo = new InventorySupplyRepository(Database::connection());
    $invHasData = $invRepo->hasData();
    $invCategories = $invRepo->options('category');
    $invWarehouses = DeliveryFilters::WAREHOUSE_GROUPS;
    $invSummary = $invRepo->summary($invCategory, $invWarehouse, $invItem);
    $invRows = $invRepo->lowestSupply($invCategory, $invWarehouse, $invItem, 30);
    $invLastRefreshed = $invRepo->lastRefreshed();
    $invAvailable = true;
} catch (Throwable $ex) {
    $invAvailable = false;
}

try {
    if ($invAvailable) {
        $slowSummary = $invRepo->slowSummary($invCategory, $invWarehouse, $invItem);
        $slowRows = $invRepo->slowRows($invCategory, $invWarehouse, $invItem, 30);
        $slowAvailable = true;
    }
} catch (Throwable $ex) {
    $slowAvailable = false;
}

$rate = $otif['otif_rate'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta http-equiv="refresh" content="1800">
    <title>KPI Dashboard · Procurement</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
<header class="topbar">
    <div class="brand">KPI Dashboard</div>
    <div class="subtitle">Procurement</div>
    <nav class="topnav">
        <?php foreach (Auth::allowedPages() as $navInfo): ?>
        <a href="<?= e($navInfo['page']) ?>"<?= $navInfo['page'] === 'procurement.php' ? ' class="active"' : '' ?>><?= e($navInfo['label']) ?></a>
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
    <?php if ($error !== null): ?>
        <div class="alert"><?= e($error) ?></div>
    <?php else: ?>

    <form class="filters" method="get" action="procurement.php">
        <div class="filter">
            <label for="from_date">From (PO date)</label>
            <input type="date" id="from_date" name="from_date" value="<?= e($from ?? '') ?>">
        </div>
        <div class="filter">
            <label for="to_date">To</label>
            <input type="date" id="to_date" name="to_date" value="<?= e($to ?? '') ?>">
        </div>
        <div class="filter">
            <label for="supplier">Supplier</label>
            <select id="supplier" name="supplier">
                <option value="">All suppliers</option>
                <?php foreach ($suppliers as $s): ?>
                <option value="<?= e($s) ?>"<?= $s === $supplier ? ' selected' : '' ?>><?= e($s) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="filter filter-wh">
            <label>Warehouse</label>
            <input type="hidden" name="warehouse" value="<?= e($warehouse ?? '') ?>">
            <div class="wh-buttons">
                <button type="button" class="wh-btn<?= $warehouse === null ? ' active' : '' ?>" onclick="pickWarehouse(this, '')">All</button>
                <?php foreach (DeliveryFilters::WAREHOUSE_GROUPS as $w): ?>
                <button type="button" class="wh-btn<?= $w === $warehouse ? ' active' : '' ?>" onclick="pickWarehouse(this, <?= htmlspecialchars((string) json_encode($w), ENT_QUOTES) ?>)"><?= e($w) ?></button>
                <?php endforeach; ?>
            </div>
        </div>
        <?php foreach (['inv_category' => $invCategory, 'inv_warehouse' => $invWarehouse, 'inv_item' => $invItem] as $hk => $hv): if ($hv !== null): ?>
        <input type="hidden" name="<?= e($hk) ?>" value="<?= e($hv) ?>">
        <?php endif; endforeach; ?>
        <div class="filter-actions">
            <button type="submit" class="btn btn-primary">Apply</button>
            <a class="btn btn-reset" href="procurement.php">Reset</a>
        </div>
    </form>

    <?php if (!$hasData): ?>
        <div class="alert">
            No purchase orders loaded yet. On the XAMPP box run:
            <code>php etl/pull_po.php --source=PRIMSBM --query=etl/queries/prodhana_po.sql --via=PRODHANA</code>
        </div>
    <?php endif; ?>

    <section class="cards">
        <div class="card <?= otifClass($rate !== null ? (float) $rate : null) ?>">
            <div class="card-label">Supplier OTIF</div>
            <div class="card-value"><?= $rate !== null ? e(number_format((float) $rate, 2)) . '%' : '—' ?></div>
            <div class="card-target"><?= num($otif['otif_pos']) ?> of <?= num($otif['total_pos']) ?> POs on-time in-full · target 95%</div>
        </div>
        <div class="card neutral">
            <div class="card-label">Purchase Orders</div>
            <div class="card-value"><?= num($otif['total_pos']) ?></div>
            <div class="card-target">received POs in range (cancelled excluded)</div>
        </div>
        <div class="card neutral">
            <div class="card-label">Last Refreshed</div>
            <div class="card-value" style="font-size:18px;"><?= $lastRefreshed ? e($lastRefreshed) : '—' ?></div>
            <div class="card-target">via etl/pull_po.php</div>
        </div>
    </section>

    <div class="grid">
        <section class="panel">
            <h2>Weekly Supplier OTIF trend <?= SourceBadge::render('supplier_otif') ?></h2>
            <p class="panel-note">Whole-PO OTIF % by PO entry week, rolling 8 weeks (supplier/warehouse filters apply; the date range is replaced by the window).</p>
            <table>
                <thead><tr><th>Week of</th><th class="num">POs</th><th class="num">OTIF %</th><th></th></tr></thead>
                <tbody>
                <?php foreach ($trend as $t): ?>
                    <tr>
                        <td><?= e($t['week_start']) ?></td>
                        <td class="num"><?= num($t['total_pos']) ?></td>
                        <td class="num"><?= e(number_format((float) $t['otif_rate'], 1)) ?>%</td>
                        <td><span class="pill <?= otifClass((float) $t['otif_rate']) ?>"><?= (float) $t['otif_rate'] >= 95 ? 'On target' : ((float) $t['otif_rate'] >= 85 ? 'Watch' : 'Below' ) ?></span></td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($trend === []): ?>
                    <tr><td colspan="4" class="empty">No POs entered in the last 8 weeks.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </section>

        <section class="panel">
            <h2>OTIF by supplier <?= SourceBadge::render('supplier_otif') ?></h2>
            <p class="panel-note">Worst performers first. A PO counts as OTIF only when every line was received in full on/before its promised date (+1 day grace).</p>
            <table>
                <thead><tr><th>Supplier</th><th class="num">POs</th><th class="num">OTIF POs</th><th class="num">OTIF %</th><?php if ($canSeeFinancials): ?><th class="num">PO value</th><?php endif; ?></tr></thead>
                <tbody>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td><?= e($r['supplier']) ?></td>
                        <td class="num"><?= num($r['total_pos']) ?></td>
                        <td class="num"><?= num($r['otif_pos']) ?></td>
                        <td class="num"><span class="pill <?= otifClass((float) $r['otif_rate']) ?>"><?= e(number_format((float) $r['otif_rate'], 1)) ?>%</span></td>
                        <?php if ($canSeeFinancials): ?><td class="num">$<?= num($r['po_value']) ?></td><?php endif; ?>
                    </tr>
                <?php endforeach; ?>
                <?php if ($rows === []): ?>
                    <tr><td colspan="<?= $canSeeFinancials ? 5 : 4 ?>" class="empty">No POs match the current filters.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </section>
    </div>

    <?php if ($invAvailable): ?>
    <section class="panel" style="margin-top:24px;">
        <h2>Inventory Days of Supply <?= SourceBadge::render('days_of_supply') ?></h2>
        <p class="panel-note">How long current on-hand lasts at the trailing 30-day usage rate, per item &times; warehouse (active SKUs only). Provisional method pending sign-off: usage = 30-day outbound qty &divide; 30.<?= $invLastRefreshed ? ' Data refreshed ' . e($invLastRefreshed) . '.' : '' ?></p>

        <?php if (!$invHasData): ?>
        <div class="alert">
            No inventory usage loaded yet. Apply sql/migrations/019_inventory_supply.sql, then on the XAMPP box run:
            <code>php etl/pull_inventory_supply.php --source=PRIMSBM --query=etl/queries/prodhana_inventory_supply.sql --via=PRODHANA</code>
        </div>
        <?php else: ?>

        <form class="filters" method="get" action="procurement.php">
            <div class="filter">
                <label for="inv_item">Item (code or name)</label>
                <input type="text" id="inv_item" name="inv_item" value="<?= e($invItem ?? '') ?>" placeholder="Search SKU…">
            </div>
            <div class="filter">
                <label for="inv_category">Category</label>
                <select id="inv_category" name="inv_category">
                    <option value="">All categories</option>
                    <?php foreach ($invCategories as $c): ?>
                    <option value="<?= e($c) ?>"<?= $c === $invCategory ? ' selected' : '' ?>><?= e($c) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="filter">
                <label for="inv_warehouse">Warehouse</label>
                <select id="inv_warehouse" name="inv_warehouse">
                    <option value="">All warehouses</option>
                    <?php foreach ($invWarehouses as $w): ?>
                    <option value="<?= e($w) ?>"<?= $w === $invWarehouse ? ' selected' : '' ?>><?= e($w) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php foreach (['supplier' => $supplier, 'warehouse' => $warehouse, 'from_date' => $from, 'to_date' => $to] as $hk => $hv): if ($hv !== null): ?>
            <input type="hidden" name="<?= e($hk) ?>" value="<?= e($hv) ?>">
            <?php endif; endforeach; ?>
            <div class="filter-actions">
                <button type="submit" class="btn btn-primary">Apply</button>
            </div>
        </form>

        <section class="cards">
            <div class="card <?= $invSummary['critical'] > 0 ? 'bad' : 'good' ?>">
                <div class="card-label">Below 7 days</div>
                <div class="card-value"><?= num($invSummary['critical']) ?></div>
                <div class="card-target">item-warehouses at critical supply (<?= num($invSummary['stocked_out']) ?> already at zero)</div>
            </div>
            <div class="card <?= $invSummary['low'] > 0 ? 'warn' : 'good' ?>">
                <div class="card-label">7–14 days</div>
                <div class="card-value"><?= num($invSummary['low']) ?></div>
                <div class="card-target">item-warehouses running low</div>
            </div>
            <div class="card neutral">
                <div class="card-label">14+ days</div>
                <div class="card-value"><?= num($invSummary['ok'] + $invSummary['healthy']) ?></div>
                <div class="card-target">item-warehouses adequately stocked</div>
            </div>
            <div class="card neutral">
                <div class="card-label">No recent usage</div>
                <div class="card-value"><?= num($invSummary['no_usage']) ?></div>
                <div class="card-target">no outbound movement in 30 days — days of supply not measurable</div>
            </div>
        </section>

        <p class="panel-note">Lowest supply first · <?= num($invSummary['measured']) ?> measurable item-warehouses match the filters.</p>
        <table>
            <thead><tr><th>Item</th><th>Category</th><th>Warehouse</th><th class="num">On hand</th><th class="num">Avg daily usage</th><th class="num">Days of supply</th></tr></thead>
            <tbody>
            <?php foreach ($invRows as $ir): ?>
                <tr>
                    <td><?= e($ir['item_code']) ?><?= $ir['item_description'] ? ' · ' . e($ir['item_description']) : '' ?><?= (int) $ir['is_new_item'] === 1 ? ' <span class="pill neutral">new SKU</span>' : '' ?></td>
                    <td><?= e($ir['std_category']) ?></td>
                    <td><?= e($ir['std_warehouse']) ?></td>
                    <td class="num"><?= num($ir['on_hand']) ?><?= $ir['unit_of_measure'] ? ' ' . e($ir['unit_of_measure']) : '' ?></td>
                    <td class="num"><?= e(number_format((float) $ir['avg_daily_usage'], 1)) ?></td>
                    <td class="num"><span class="pill <?= dosClass((float) $ir['days_of_supply']) ?>"><?= e(number_format((float) $ir['days_of_supply'], 1)) ?>d</span></td>
                </tr>
            <?php endforeach; ?>
            <?php if ($invRows === []): ?>
                <tr><td colspan="6" class="empty">No items with measurable usage match the current filters.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>

        <?php endif; ?>
    </section>

    <?php if ($slowAvailable): ?>
    <section class="panel" style="margin-top:24px;">
        <h2>Slow / Obsolete Inventory <?= SourceBadge::render('slow_inventory') ?></h2>
        <p class="panel-note">Stocked item &times; warehouse combinations with no outbound movement in the trailing 90 days (active SKUs only). Provisional 90-day window pending sign-off; perishables may need a shorter cutoff. Count-based &mdash; a value-based ($) view needs a confirmed cost source. Uses the same filters as Days of Supply above.<?= $invLastRefreshed ? ' Data refreshed ' . e($invLastRefreshed) . '.' : '' ?></p>

        <?php if (!$invHasData): ?>
        <div class="alert">
            No inventory usage loaded yet. Apply sql/migrations/020_slow_inventory.sql, then on the XAMPP box run:
            <code>php etl/pull_inventory_supply.php --source=PRIMSBM --query=etl/queries/prodhana_inventory_supply.sql --via=PRODHANA</code>
        </div>
        <?php else: ?>

        <section class="cards">
            <div class="card <?= $slowSummary['slow_pct'] === null ? 'neutral' : ($slowSummary['slow_pct'] > 10 ? 'bad' : ($slowSummary['slow_pct'] > 5 ? 'warn' : 'good')) ?>">
                <div class="card-label">Slow / obsolete %</div>
                <div class="card-value"><?= $slowSummary['slow_pct'] === null ? '—' : e(number_format($slowSummary['slow_pct'], 1)) . '%' ?></div>
                <div class="card-target">of stocked item-warehouses had no movement in 90 days</div>
            </div>
            <div class="card <?= $slowSummary['slow'] > 0 ? 'warn' : 'good' ?>">
                <div class="card-label">Slow item-warehouses</div>
                <div class="card-value"><?= num($slowSummary['slow']) ?></div>
                <div class="card-target">stocked with zero outbound movement in 90 days</div>
            </div>
            <div class="card neutral">
                <div class="card-label">Stocked item-warehouses</div>
                <div class="card-value"><?= num($slowSummary['stocked']) ?></div>
                <div class="card-target">active SKUs with on-hand &gt; 0 matching the filters</div>
            </div>
        </section>

        <p class="panel-note">Oldest movement first &middot; <?= num(count($slowRows)) ?> slow item-warehouses shown.</p>
        <table>
            <thead><tr><th>Item</th><th>Category</th><th>Warehouse</th><th class="num">On hand</th><th>Last movement</th></tr></thead>
            <tbody>
            <?php foreach ($slowRows as $sr): ?>
                <tr>
                    <td><?= e($sr['item_code']) ?><?= $sr['item_description'] ? ' &middot; ' . e($sr['item_description']) : '' ?><?= (int) $sr['is_new_item'] === 1 ? ' <span class="pill neutral">new SKU</span>' : '' ?></td>
                    <td><?= e($sr['std_category']) ?></td>
                    <td><?= e($sr['std_warehouse']) ?></td>
                    <td class="num"><?= num($sr['on_hand']) ?><?= $sr['unit_of_measure'] ? ' ' . e($sr['unit_of_measure']) : '' ?></td>
                    <td><?= $sr['last_movement'] ? e($sr['last_movement']) : '<span class="pill neutral">never moved</span>' ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if ($slowRows === []): ?>
                <tr><td colspan="5" class="empty">No slow stock matches the current filters.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>

        <?php endif; ?>
    </section>
    <?php endif; ?>
    <?php endif; ?>

    <?php endif; ?>
</main>

<footer class="footer">
    KPI Dashboard · Procurement · source: SAP Business One via local cache<?= $lastRefreshed ? ' · data refreshed ' . e($lastRefreshed) : '' ?>
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
</body>
</html>
