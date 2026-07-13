<?php

declare(strict_types=1);

/**
 * Procurement dashboard — SCRUM-88.
 *
 * Supplier OTIF %: purchase orders received from suppliers on-time AND in-full,
 * measured at the whole-PO level (one bad line fails the PO), mirroring the
 * sales-side order OTIF (SCRUM-86). Reads the local `po_lines` cache refreshed
 * by etl/pull_po.php — never queries SAP directly.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../src/Auth.php';
require_once __DIR__ . '/../src/PoRepository.php';
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

$error = null;
$hasData = false;
$otif = ['total_pos' => 0, 'otif_pos' => 0, 'otif_rate' => null];
$suppliers = [];
$warehouses = [];
$rows = [];
$trend = [];
$lastRefreshed = null;

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
        <div class="filter">
            <label for="warehouse">Warehouse</label>
            <select id="warehouse" name="warehouse">
                <option value="">All warehouses</option>
                <?php foreach ($warehouses as $w): ?>
                <option value="<?= e($w) ?>"<?= $w === $warehouse ? ' selected' : '' ?>><?= e($w) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
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

    <?php endif; ?>
</main>

<footer class="footer">
    KPI Dashboard · Procurement · source: SAP Business One via local cache<?= $lastRefreshed ? ' · data refreshed ' . e($lastRefreshed) : '' ?>
</footer>
</body>
</html>
