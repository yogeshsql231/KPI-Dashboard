<?php

declare(strict_types=1);

/**
 * Overview dashboard (SCRUM-82 redesign) for Damascus-KPI.
 *
 * Executive view with the v2 interaction model:
 *   - Filter hierarchy: date range first, then warehouse (locked until a
 *     range is chosen).
 *   - KPI tiles: pallets are the primary executive metric (on-hand and
 *     delivered), followed by SO/PO counts; 8-week trend revealed on hover.
 *   - Pallets by warehouse location: pictogram / bar toggle with per-status
 *     counts and a hover detail (total, aged >30d, 6-week trend).
 *   - Late delivery vs late payment: click a month to drill into the
 *     customers driving that month's late-paid $.
 *   - Draggable sections: hold a section label to reorder; the layout
 *     persists per user and can be reset.
 *
 * Reads ONLY from the local caches (delivery_lines, lpn_pallets, ar_payments,
 * complaints) that the SAP ETL refreshes. Auto-refreshes every 30 min.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../src/Auth.php';
require_once __DIR__ . '/../src/DeliveryRepository.php';
require_once __DIR__ . '/../src/ComplaintRepository.php';
require_once __DIR__ . '/../src/PaymentRepository.php';
require_once __DIR__ . '/../src/LpnRepository.php';
require_once __DIR__ . '/../src/DeliveryFilters.php';
require_once __DIR__ . '/../src/SourceBadge.php';

Auth::requireDepartment('overview');
$canSeeFinancials = Auth::isCLevel();

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

/** Compact USD for tiles/legends: $3.8M / $612K / $940. */
function moneyShort(float $v): string
{
    $abs = abs($v);
    if ($abs >= 1_000_000) {
        return '$' . number_format($v / 1_000_000, 1) . 'M';
    }
    if ($abs >= 1_000) {
        return '$' . number_format($v / 1_000) . 'K';
    }
    return '$' . number_format($v);
}

$error = null;
$ov = [];
$trend = [];
$monthly = [];
$topCust = [];
$latePayers = [];
$paySummary = [];
$lateDelMonths = [];
$latePayMonths = [];
$hasPayments = false;
$arAging = [];
$topOpenAr = [];
$complaintSummary = ['complaints' => 0, 'lost_amount' => 0];
$complaintsByReason = [];
$palletRows = [];
$palletTrend = [];
$divisionRows = [];
$lpnSummary = null;
$hasLpn = false;
$whOptions = [];
$lastRefreshed = null;
$otif = ['total_orders' => 0, 'otif_orders' => 0, 'otif_rate' => null];
$otifTrend = [];

// Default to the last 7 days so the page loads with data immediately.
$q = $_GET;
if (($q['from_date'] ?? '') === '' && ($q['to_date'] ?? '') === '') {
    $q['from_date'] = date('Y-m-d', strtotime('-6 days'));
    $q['to_date'] = date('Y-m-d');
}
$filters = DeliveryFilters::fromRequest($q);
// Filter hierarchy: warehouse only unlocks after a date range is chosen.
$dateChosen = $filters->fromDate !== null && $filters->toDate !== null;

try {
    $pdo = Database::connection();
    $repo = new DeliveryRepository($pdo);
    $complaints = new ComplaintRepository($pdo);
    $payments = new PaymentRepository($pdo);
    $lpn = new LpnRepository($pdo);

    $ov = $repo->overview($filters);
    $trend = $repo->weeklyTrend($filters, 8);
    $otif = $repo->otifOrders($filters);
    $otifTrend = $repo->otifWeeklyTrend($filters, 8);
    $monthly = $repo->monthlyPerformance($filters);
    $topCust = $repo->customersByOrders($filters, 5);
    $divisionRows = $repo->byDivisionCustomer($filters);
    $complaintSummary = $complaints->summary($filters);
    $complaintsByReason = $complaints->byReason($filters, 6);

    $hasPayments = $payments->hasData();
    $paySummary = $payments->summary($filters->fromDate, $filters->toDate, 0);
    $latePayers = $payments->topLatePayers($filters->fromDate, $filters->toDate, 0, 5);
    $lateDelMonths = $repo->lateByMonth($filters);
    $latePayMonths = $payments->byMonth($filters->fromDate, $filters->toDate, 0);
    $arAging = $payments->arAging();
    $topOpenAr = $payments->topOpenAr(5);

    try {
        $hasLpn = $lpn->hasData();
        if ($hasLpn) {
            $palletRows = $lpn->byWarehouseStatus($filters);
            $palletTrend = $lpn->weeklyByWarehouse($filters, 6);
            $lpnSummary = $lpn->summary($filters);
        }
    } catch (Throwable $ex) {
        $hasLpn = false; // LPN migration not applied yet — widget shows setup hint.
    }

    // Fixed warehouse buttons: site groups (Clifton merges every naming
    // variant; Others = everything not Newark/Clifton/Brooklyn).
    $whOptions = DeliveryFilters::WAREHOUSE_GROUPS;

    $lastRefreshed = $repo->lastRefreshed();
} catch (Throwable $ex) {
    $error = 'Unable to load overview data. Import sql/delivery_dashboard.sql (+ migrations) and check your .env database connection.';
}

// ---- KPI tiles (headline + delta + 8-week hover trend) --------------------
$hasAmount  = ($ov['order_amount'] ?? 0) > 0;
$showMoney  = $hasAmount && $canSeeFinancials;

$tOrders  = [];
$tPos     = [];
$tAmount  = [];
$tPct     = [];
$tWeeks   = [];
$tPallets = [];
foreach ($trend as $r) {
    $tWeeks[]   = date('M j', strtotime((string) $r['week_start']));
    $tOrders[]  = (int) $r['orders'];
    $tPos[]     = (int) $r['pos'];
    $tAmount[]  = (float) $r['order_amount'];
    $tPallets[] = round((float) $r['pallets'], 1);
    $oq = (float) $r['order_qty'];
    $tPct[]     = $oq > 0 ? round((float) $r['delivered_qty'] / $oq * 100, 1) : 0.0;
}

/** Last-vs-previous-week % change for a tile delta, or null. */
function deltaPct(array $series): ?float
{
    $n = count($series);
    if ($n < 2 || (float) $series[$n - 2] == 0.0) {
        return null;
    }
    return round(((float) $series[$n - 1] - (float) $series[$n - 2]) / (float) $series[$n - 2] * 100, 1);
}

$orderQty     = (float) ($ov['order_qty'] ?? 0);
$deliveredPct = $orderQty > 0 ? round((float) ($ov['delivered_qty'] ?? 0) / $orderQty * 100) : null;

// Weekly received-pallet totals (all warehouses) for the on-hand tile spark.
$tOnHand = [];
foreach ($palletTrend as $r) {
    $tOnHand[(string) $r['yw']] = ($tOnHand[(string) $r['yw']] ?? 0) + (int) $r['pallets'];
}
ksort($tOnHand);
$tOnHand = array_values($tOnHand);

// Pallets-on-hand split by site group for the tile subtitle.
$onHandByGroup = [];
foreach ($palletRows as $r) {
    $g = DeliveryFilters::warehouseGroup((string) $r['warehouse']);
    $onHandByGroup[$g] = ($onHandByGroup[$g] ?? 0) + (int) $r['pallets'];
}
$onHandParts = [];
foreach (DeliveryFilters::WAREHOUSE_GROUPS as $g) {
    if (isset($onHandByGroup[$g])) {
        $onHandParts[] = $g . ' ' . num($onHandByGroup[$g]);
    }
}
$onHandSplit = implode(' · ', $onHandParts);

// OTIF tile: RAG status color (green >= 95%, gold >= 85%, red below).
$otifRate = $otif['otif_rate'];
$tOtif = [];
foreach ($otifTrend as $r) {
    $tOtif[] = round((float) $r['otif_rate'] * 100, 1);
}
$otifColor = 'var(--ov-dim)';
if ($otifRate !== null) {
    $otifColor = $otifRate >= 0.95 ? 'var(--ov-green)' : ($otifRate >= 0.85 ? 'var(--ov-gold)' : 'var(--ov-red)');
}

$tiles = [
    [
        'label' => 'OTIF',
        'value' => $otifRate === null ? '—' : number_format($otifRate * 100, 1) . '%',
        'sub'   => $otif['total_orders'] > 0
            ? num($otif['otif_orders']) . ' of ' . num($otif['total_orders']) . ' orders on-time in-full'
            : 'no eligible orders in range',
        'delta' => deltaPct($tOtif),
        'spark' => $tOtif,
        'color' => $otifColor,
    ],
    [
        'label' => 'Pallets on hand',
        'value' => $lpnSummary !== null ? num($lpnSummary['pallets'] ?? 0) : '—',
        'sub'   => $lpnSummary !== null
            ? ($onHandSplit !== '' ? $onHandSplit : 'run the LPN ETL to populate')
            : 'run the LPN ETL to populate',
        'delta' => deltaPct($tOnHand),
        'spark' => $tOnHand,
        'color' => 'var(--ov-gold)',
    ],
    [
        'label' => 'Pallets delivered',
        'value' => num(round((float) ($ov['total_pallets'] ?? 0))),
        'sub'   => $deliveredPct === null ? 'no ordered units in range' : $deliveredPct . '% of ordered units delivered',
        'delta' => deltaPct($tPallets),
        'spark' => $tPallets,
        'color' => 'var(--ov-green)',
    ],
    [
        'label' => $showMoney ? 'Total SO value' : 'Total SO',
        'value' => $showMoney ? moneyShort((float) $ov['order_amount']) : num($ov['total_so'] ?? 0),
        'sub'   => num($ov['total_so'] ?? 0) . ' sales orders',
        'delta' => deltaPct($showMoney ? $tAmount : $tOrders),
        'spark' => $showMoney ? $tAmount : $tOrders,
        'color' => 'var(--ov-gold)',
    ],
    [
        'label' => 'Total PO',
        'value' => num($ov['total_po'] ?? 0),
        'sub'   => 'customer purchase orders',
        'delta' => deltaPct($tPos),
        'spark' => $tPos,
        'color' => 'var(--ov-blue)',
    ],
];

// ---- Pallets by site group (Newark / Clifton / Brooklyn / Others) ----------
$pallets = [];
foreach ($palletRows as $r) {
    $w = DeliveryFilters::warehouseGroup((string) $r['warehouse']);
    $s = (string) $r['status'];
    $prev = $pallets[$w]['statuses'][$s] ?? ['c' => 0, 'qty' => 0.0];
    $pallets[$w]['statuses'][$s] = [
        'c'   => $prev['c'] + (int) $r['pallets'],
        'qty' => $prev['qty'] + (float) $r['total_qty'],
    ];
    $pallets[$w]['total'] = ($pallets[$w]['total'] ?? 0) + (int) $r['pallets'];
    $pallets[$w]['aged']  = ($pallets[$w]['aged'] ?? 0) + (int) $r['aged_30d'];
    $pallets[$w]['raw']      = ($pallets[$w]['raw'] ?? 0) + (int) ($r['raw_pallets'] ?? 0);
    $pallets[$w]['fg']       = ($pallets[$w]['fg'] ?? 0) + (int) ($r['fg_pallets'] ?? 0);
    $pallets[$w]['rawVal']   = ($pallets[$w]['rawVal'] ?? 0) + (float) ($r['raw_value'] ?? 0);
    $pallets[$w]['fgVal']    = ($pallets[$w]['fgVal'] ?? 0) + (float) ($r['fg_value'] ?? 0);
    $pallets[$w]['totalVal'] = ($pallets[$w]['totalVal'] ?? 0) + (float) ($r['total_value'] ?? 0);
}
$groupTrend = [];
foreach ($palletTrend as $r) {
    $g = DeliveryFilters::warehouseGroup((string) $r['warehouse']);
    $groupTrend[$g][(string) $r['yw']] = ($groupTrend[$g][(string) $r['yw']] ?? 0) + (int) $r['pallets'];
}
foreach ($groupTrend as $g => $byWeek) {
    ksort($byWeek);
    $pallets[$g]['trend'] = array_values($byWeek);
}
$ordered = [];
foreach (DeliveryFilters::WAREHOUSE_GROUPS as $g) {
    if (isset($pallets[$g])) {
        $ordered[$g] = $pallets[$g];
    }
}
$pallets = $ordered;
$palletStatuses = [];
foreach ($palletRows as $r) {
    if (!in_array((string) $r['status'], $palletStatuses, true)) {
        $palletStatuses[] = (string) $r['status'];
    }
}

// ---- Correlation: late deliveries vs late payments, click-to-drill --------
$lpMonths = array_values(array_unique(array_merge(array_keys($lateDelMonths), array_keys($latePayMonths))));
sort($lpMonths);
$corr = [];
if (isset($payments)) {
    foreach ($lpMonths as $m) {
        $invoiced = isset($latePayMonths[$m]) ? (float) $latePayMonths[$m]['invoiced'] : 0.0;
        $paidLate = isset($latePayMonths[$m]) ? (float) $latePayMonths[$m]['paid_late'] : 0.0;
        $monthEnd = date('Y-m-t', strtotime($m . '-01'));
        $corr[] = [
            'ym'       => $m,
            'label'    => date('M y', strtotime($m . '-01')),
            'late'     => isset($lateDelMonths[$m]) ? (int) $lateDelMonths[$m]['late_lines'] : 0,
            'total'    => isset($lateDelMonths[$m]) ? (int) $lateDelMonths[$m]['total_lines'] : 0,
            'paidLate' => $paidLate,
            'pct'      => $invoiced > 0 ? round($paidLate / $invoiced * 100, 1) : null,
            'drill'    => array_map(static fn ($r) => [
                'customer' => (string) $r['customer'],
                'days'     => $r['avg_days_late'] !== null ? (int) round((float) $r['avg_days_late']) : null,
                'value'    => (float) $r['paid_late'],
            ], $payments->topLatePayers($m . '-01', $monthEnd, 0, 6)),
        ];
    }
}

// ---- Monthly performance (sales chart + growth vs late orders) ------------
$perf = [];
foreach ($monthly as $r) {
    $m = (string) $r['ym'];
    $perf[] = [
        'label'  => date('M y', strtotime($m . '-01')),
        'amount' => (float) $r['order_amount'],
        'orders' => (int) $r['orders'],
        'late'   => isset($lateDelMonths[$m]) ? (int) $lateDelMonths[$m]['late_lines'] : 0,
    ];
}

// ---- Complaints donut ------------------------------------------------------
$reasonTotal = array_sum(array_map(static fn ($r) => (int) $r['complaints'], $complaintsByReason));
$reasons = array_map(static fn ($r) => [
    'reason' => (string) $r['reason'],
    'count'  => (int) $r['complaints'],
], $complaintsByReason);

// ---- Customer / division mix (SCRUM-28) ------------------------------------
// Roll customer rows up into divisions (SAP customer group); the pie shares
// use $ when financials are visible, otherwise order counts.
$divisions = [];
foreach ($divisionRows as $r) {
    $d = (string) $r['division'];
    if (!isset($divisions[$d])) {
        $divisions[$d] = ['orders' => 0, 'order_qty' => 0.0, 'delivered_qty' => 0.0, 'amount' => 0.0, 'customers' => []];
    }
    $divisions[$d]['orders']        += (int) $r['orders'];
    $divisions[$d]['order_qty']     += (float) $r['order_qty'];
    $divisions[$d]['delivered_qty'] += (float) $r['delivered_qty'];
    $divisions[$d]['amount']        += (float) $r['order_amount'];
    $divisions[$d]['customers'][]    = [
        'name'          => (string) $r['customer_name'],
        'orders'        => (int) $r['orders'],
        'delivered_qty' => (float) $r['delivered_qty'],
        'amount'        => (float) $r['order_amount'],
    ];
}
uasort($divisions, static fn (array $a, array $b): int => $showMoney
    ? ($b['amount'] <=> $a['amount'])
    : ($b['orders'] <=> $a['orders']));
$divisionTotal = 0.0;
foreach ($divisions as $d) {
    $divisionTotal += $showMoney ? $d['amount'] : $d['orders'];
}
$divisionPie = [];
foreach ($divisions as $name => $d) {
    $divisionPie[] = ['label' => $name, 'value' => $showMoney ? $d['amount'] : (float) $d['orders']];
}

$authUser  = Auth::user();
$layoutKey = 'ovLayout:' . ($authUser !== null ? (string) $authUser['name'] : 'anon');

$chartData = [
    'showMoney'      => $showMoney,
    'pallets'        => $pallets === [] ? new stdClass() : $pallets,
    'palletStatuses' => $palletStatuses,
    'corr'           => $corr,
    'perf'           => $perf,
    'reasons'        => $reasons,
    'sparkWeeks'     => $tWeeks,
    'divisions'      => $divisionPie,
    'layoutKey'      => $layoutKey,
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
    <link rel="stylesheet" href="assets/overview.css">
</head>
<body>
<header class="topbar">
    <div class="brand">KPI Dashboard</div>
    <div class="subtitle">Overview</div>
    <nav class="topnav">
        <?php foreach (Auth::allowedPages() as $navInfo): ?>
        <a href="<?= e($navInfo['page']) ?>"<?= $navInfo['page'] === 'overview.php' ? ' class="active"' : '' ?>><?= e($navInfo['label']) ?></a>
        <?php endforeach; ?>
        <?php if ($authUser !== null): ?>
        <span class="user-chip">
            <span class="user-name"><?= e($authUser['name']) ?></span>
            <?php if ($canSeeFinancials): ?><span class="user-role">C-level</span><?php endif; ?>
            <a href="logout.php" class="user-logout">Sign out</a>
        </span>
        <?php endif; ?>
    </nav>
</header>

<main class="container ov-wrap">
    <div class="ov-hd">
        <h1>Overview</h1>
        <span class="r"><?= $canSeeFinancials ? 'C-Level · ' : '' ?><?= $filters->warehouse !== null ? e($filters->warehouse) : 'Company-wide' ?></span>
    </div>
    <div class="ov-sub">Damascus Bakeries<?php if ($dateChosen): ?> — <?= e(date('M j, Y', strtotime($filters->fromDate))) ?> to <?= e(date('M j, Y', strtotime($filters->toDate))) ?><?php endif; ?></div>

    <form class="fbar" method="get" action="overview.php" id="filterForm">
        <div class="fdate" title="Pick the date range first — it unlocks the warehouse filter">
            <span class="cal">📅</span>
            <input type="date" name="from_date" value="<?= e($filters->fromDate) ?>" onchange="dateChanged()">
            <span class="to">to</span>
            <input type="date" name="to_date" value="<?= e($filters->toDate) ?>" onchange="dateChanged()">
        </div>
        <span class="chev">›</span>
        <input type="hidden" name="warehouse" value="<?= e($filters->warehouse) ?>">
        <div class="whwrap<?= $dateChosen ? '' : ' locked' ?>" id="whWrap">
            <button type="button" class="whbtn<?= $filters->warehouse === null ? ' on' : '' ?>" onclick="pickWarehouse(this, '')">All</button>
            <?php foreach ($whOptions as $w): ?>
            <button type="button" class="whbtn<?= $filters->warehouse === $w ? ' on' : '' ?>" onclick="pickWarehouse(this, <?= htmlspecialchars((string) json_encode($w), ENT_QUOTES) ?>)"><?= e($w) ?></button>
            <?php endforeach; ?>
        </div>
        <?php if (!$dateChosen): ?><span class="whlock" id="whLock">pick a date range to unlock</span><?php endif; ?>
        <button type="submit" class="apply">Apply</button>
        <a class="ghost" href="overview.php">Reset</a>
        <div class="right">
            <span class="savenote" id="saveNote"></span>
            drag a section label to reorder
            <button type="button" class="reset" onclick="resetLayout()">reset layout</button>
        </div>
    </form>
    <details class="morefilters">
        <summary>More filters (SO, item, carrier, status)</summary>
        <div class="mf">
            <div><label for="so">SO Number</label><input form="filterForm" type="text" id="so" name="so" placeholder="contains…" value="<?= e($filters->salesOrder) ?>"></div>
            <div><label for="item">Item</label><input form="filterForm" type="text" id="item" name="item" placeholder="code or description…" value="<?= e($filters->item) ?>"></div>
            <div><label for="carrier">Carrier</label><input form="filterForm" type="text" id="carrier" name="carrier" placeholder="exact…" value="<?= e($filters->carrier) ?>"></div>
            <div><label for="so_status">SO Status</label><input form="filterForm" type="text" id="so_status" name="so_status" placeholder="exact…" value="<?= e($filters->soStatus) ?>"></div>
        </div>
    </details>

    <?php if ($error !== null): ?>
        <div class="alert"><?= e($error) ?></div>
    <?php else: ?>

    <?php if (!$hasAmount && $canSeeFinancials): ?>
        <div class="note">Dollar values populate once the SAP ETL loads <code>line_amount</code>/<code>delivered_amount</code>. Counts, quantities and pallets are live from the current cache.</div>
    <?php endif; ?>

    <div id="sections">

    <div class="sec" data-id="orders">
        <div class="handle" draggable="true"><span class="grip">⠿</span><span>Pallets &amp; Orders</span></div>
        <div class="g5">
            <?php foreach ($tiles as $t): ?>
            <div class="ovcard tile">
                <div class="top">
                    <div>
                        <div class="lbl"><?= e($t['label']) ?></div>
                        <div class="val"><?= e($t['value']) ?></div>
                    </div>
                    <?php if ($t['delta'] !== null): ?>
                    <?php $up = $t['delta'] >= 0; $good = ($t['invert'] ?? false) ? !$up : $up; ?>
                    <span class="delta <?= $good ? 'pos' : 'neg' ?>"><?= $up ? '▲ +' : '▼ ' ?><?= e($t['delta']) ?>%</span>
                    <?php endif; ?>
                </div>
                <div class="reveal"><div class="spark" data-series="<?= e((string) json_encode(array_map('floatval', $t['spark']))) ?>" data-color="<?= e($t['color']) ?>"></div></div>
                <div class="foot"><?= e($t['sub']) ?> · <span class="hoverfoot">hover for 8-week trend</span></div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="sec" data-id="inventory">
        <div class="handle" draggable="true"><span class="grip">⠿</span><span>Inventory &amp; Sales</span></div>
        <div class="g2">
            <div class="ovcard">
                <div class="ptop">
                    <div class="eyebrow" style="margin:0">🏭 Pallets by warehouse group <?= SourceBadge::render('lpn') ?></div>
                </div>
                <?php if ($hasLpn && $pallets !== []): ?>
                    <div class="plegend" id="plegend"></div>
                    <div id="pbody"></div>
                    <div class="pdetail" id="pdetail"><div class="in">
                        <div><div class="k" id="pdLoc"></div><div class="bignum" id="pdTot"></div><div id="pdSplit"></div></div>
                        <div><div class="k">Aged &gt;30 days</div><div class="bignum" id="pdAged"></div></div>
                        <div style="flex:1"><div class="k" style="margin-bottom:4px">6-week volume trend</div><div class="wtrend" id="pdTrend"></div></div>
                    </div></div>
                    <div class="phint" id="phint">hover a row to inspect · pallet $ values populate once pallet valuation data is loaded</div>
                <?php else: ?>
                    <p class="pempty">No LPN pallet data loaded yet. Run migration <code>008_lpn_pallets.sql</code> and <code>php etl/pull_lpn.php</code> to populate this widget.</p>
                <?php endif; ?>
            </div>
            <div class="ovcard">
                <div class="eyebrow">📈 Sales performance (monthly<?= $showMoney ? ' $' : ' orders' ?> vs 3-mo avg) <?= SourceBadge::render('ordered') ?></div>
                <div id="salesChart"></div>
            </div>
        </div>
    </div>

    <div class="sec" data-id="customers">
        <div class="handle" draggable="true"><span class="grip">⠿</span><span>Customers &amp; Payments</span></div>
        <div class="g2">
            <div class="ovcard bl">
                <div class="eyebrow">👥 Top customers<?= $showMoney ? ' (SO value)' : ' (orders)' ?> <?= SourceBadge::render('ordered') ?></div>
                <?php
                $maxCust = 0.0;
                foreach ($topCust as $c) {
                    $maxCust = max($maxCust, $showMoney ? (float) $c['order_amount'] : (float) $c['orders']);
                }
                foreach ($topCust as $c):
                    $v = $showMoney ? (float) $c['order_amount'] : (float) $c['orders'];
                    $w = $maxCust > 0 ? round($v / $maxCust * 100) : 0;
                ?>
                <div class="row">
                    <span class="nm" title="<?= e($c['customer_name']) ?>"><?= e($c['customer_name']) ?></span>
                    <div class="track"><div class="fill" style="width:<?= $w ?>%"></div></div>
                    <span class="amt"><?= $showMoney ? e(moneyShort($v)) : num($v) ?></span>
                </div>
                <?php endforeach; ?>
                <?php if ($topCust === []): ?><p class="ovempty">No orders in the selected range.</p><?php endif; ?>
            </div>
            <div class="ovcard lp">
                <div class="eyebrow">⚠️ Late payment tracker <?= SourceBadge::render('invoiced') ?></div>
                <?php if ($canSeeFinancials): ?>
                    <?php foreach ($latePayers as $p): ?>
                    <div class="row">
                        <span><?= e($p['customer']) ?></span>
                        <span>
                            <span class="d"><?= $p['avg_days_late'] !== null ? (int) round((float) $p['avg_days_late']) . 'd late' : '—' ?></span>
                            <span class="a"><?= e(moneyShort((float) $p['paid_late'])) ?></span>
                        </span>
                    </div>
                    <?php endforeach; ?>
                    <?php if ($latePayers === []): ?>
                        <p class="ovempty"><?= $hasPayments ? 'No late payments in the selected range.' : 'Late-payment $ populate once the A/R payment ETL loads ar_payments (migration 006 + etl/pull_payments.php).' ?></p>
                    <?php endif; ?>
                <?php else: ?>
                    <p class="ovempty">Restricted to C-level users.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="sec" data-id="araging">
        <div class="handle" draggable="true"><span class="grip">⚠</span><span>A/R Aging (open invoices)</span></div>
        <div class="g2">
            <div class="ovcard">
                <div class="eyebrow">⏳ A/R aging — open invoices by days past due (as of today) <?= SourceBadge::render('invoiced') ?></div>
                <?php
                $agingTotalInv = 0;
                $agingTotalAmt = 0.0;
                $agingMax = 0.0;
                foreach ($arAging as $b) {
                    $agingTotalInv += (int) $b['invoices'];
                    $agingTotalAmt += (float) $b['open_amount'];
                    $agingMax = max($agingMax, $canSeeFinancials ? (float) $b['open_amount'] : (float) $b['invoices']);
                }
                ?>
                <?php if ($agingTotalInv > 0): ?>
                    <?php foreach ($arAging as $label => $b):
                        $v = $canSeeFinancials ? (float) $b['open_amount'] : (float) $b['invoices'];
                        $w = $agingMax > 0 ? round($v / $agingMax * 100) : 0;
                    ?>
                    <div class="row">
                        <span class="nm"><?= e($label) ?><?= $label === 'Current' ? ' (not yet due)' : 'd past due' ?></span>
                        <div class="track"><div class="fill" style="width:<?= $w ?>%"></div></div>
                        <span class="amt"><?= (int) $b['invoices'] ?> inv<?= $canSeeFinancials ? ' · ' . e(moneyShort((float) $b['open_amount'])) : '' ?></span>
                    </div>
                    <?php endforeach; ?>
                    <p class="phint">Total open A/R: <?= $agingTotalInv ?> invoices<?= $canSeeFinancials ? ' · ' . e(moneyShort($agingTotalAmt)) : '' ?> · snapshot as of today, independent of the date filter</p>
                <?php else: ?>
                    <p class="ovempty"><?= $hasPayments ? 'No open (unpaid) invoices in the A/R cache.' : 'Populates once the A/R payment ETL loads ar_payments (migration 006 + etl/pull_payments.php).' ?></p>
                <?php endif; ?>
            </div>
            <div class="ovcard lp">
                <div class="eyebrow">🏦 Top open A/R balances <?= SourceBadge::render('invoiced') ?></div>
                <?php if ($canSeeFinancials): ?>
                    <?php foreach ($topOpenAr as $p): ?>
                    <div class="row">
                        <span><?= e($p['customer']) ?></span>
                        <span>
                            <span class="d"><?= (int) $p['oldest_days_past_due'] > 0 ? (int) $p['oldest_days_past_due'] . 'd past due' : 'current' ?></span>
                            <span class="a"><?= e(moneyShort((float) $p['open_amount'])) ?></span>
                        </span>
                    </div>
                    <?php endforeach; ?>
                    <?php if ($topOpenAr === []): ?>
                        <p class="ovempty">No open invoices — nothing owed.</p>
                    <?php endif; ?>
                <?php else: ?>
                    <p class="ovempty">Restricted to C-level users.</p>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="sec" data-id="divisions">
        <div class="handle" draggable="true"><span class="grip">⠿</span><span>Customer &amp; Division Mix</span></div>
        <div class="g2">
            <div class="ovcard">
                <div class="eyebrow">🥧 Division share<?= $showMoney ? ' (SO value)' : ' (orders)' ?></div>
                <?php if ($divisionPie !== [] && $divisionTotal > 0): ?>
                    <div class="donutwrap">
                        <svg width="110" height="110" viewBox="0 0 110 110" id="divPie"></svg>
                        <div class="dlegend" id="divLegend"></div>
                    </div>
                <?php else: ?>
                    <p class="ovempty">No orders in the selected range.</p>
                <?php endif; ?>
            </div>
            <div class="ovcard divtbl">
                <div class="eyebrow">📋 Consolidated by division &amp; customer</div>
                <?php if ($divisions === []): ?>
                    <p class="ovempty">No orders in the selected range.</p>
                <?php else: ?>
                <div class="divscroll">
                <table class="divtable">
                    <thead><tr><th>Division / Customer</th><th class="r">Orders</th><th class="r">Delivered</th><th class="r"><?= $showMoney ? 'SO value' : 'Ordered' ?></th><th class="r">Share</th></tr></thead>
                    <tbody>
                    <?php foreach ($divisions as $dName => $d):
                        $dv = $showMoney ? $d['amount'] : (float) $d['orders'];
                        $share = $divisionTotal > 0 ? round($dv / $divisionTotal * 100, 1) : 0.0;
                    ?>
                    <tr class="divrow">
                        <td><?= e($dName) ?> <span class="dcount">(<?= count($d['customers']) ?> customers)</span></td>
                        <td class="r"><?= num($d['orders']) ?></td>
                        <td class="r"><?= num(round($d['delivered_qty'])) ?></td>
                        <td class="r"><?= $showMoney ? e(moneyShort($d['amount'])) : num(round($d['order_qty'])) ?></td>
                        <td class="r"><?= e($share) ?>%</td>
                    </tr>
                        <?php foreach ($d['customers'] as $c): ?>
                        <tr class="custrow">
                            <td class="cn"><?= e($c['name']) ?></td>
                            <td class="r"><?= num($c['orders']) ?></td>
                            <td class="r"><?= num(round($c['delivered_qty'])) ?></td>
                            <td class="r"><?= $showMoney ? e(moneyShort($c['amount'])) : '' ?></td>
                            <td class="r"></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <?php if ($canSeeFinancials): ?>
    <div class="sec" data-id="financial">
        <div class="handle" draggable="true"><span class="grip">⠿</span><span>Financial Impact Analytics</span></div>
        <div class="g2">
            <div class="ovcard">
                <div class="ptop"><div class="eyebrow" style="margin:0">📉 Late delivery vs late payment <?= SourceBadge::render('delivered') ?> <?= SourceBadge::render('invoiced') ?></div>
                <span style="font-size:10px;text-transform:uppercase;letter-spacing:.2em;color:var(--ov-dim)">click a month to inspect</span></div>
                <?php if ($corr === []): ?>
                    <p class="ovempty">No data in the selected range.</p>
                <?php else: ?>
                    <div class="corrchart" id="corr"></div>
                    <div class="drillinfo">
                        <div class="t" id="corrT"></div>
                        <div class="chips" id="corrChips"></div>
                    </div>
                <?php endif; ?>
            </div>
            <div class="ovcard">
                <div class="eyebrow">📈 Growth vs late orders</div>
                <div id="growthChart"></div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <div class="sec" data-id="insights">
        <div class="handle" draggable="true"><span class="grip">⠿</span><span>Customer Insights</span></div>
        <div class="g2">
            <div class="ovcard">
                <div class="eyebrow">💬 Complaints by category <?= SourceBadge::render('complaints') ?></div>
                <?php if ($reasonTotal > 0): ?>
                    <div class="donutwrap">
                        <svg width="110" height="110" viewBox="0 0 110 110" id="donut"></svg>
                        <div class="dlegend" id="donutLegend"></div>
                    </div>
                <?php else: ?>
                    <p class="ovempty">No complaints loaded for the selected range.</p>
                <?php endif; ?>
            </div>
            <div class="ovcard impact">
                <div>
                    <div class="k">Overall impact</div>
                    <?php if ($canSeeFinancials): ?>
                        <div class="n"><?= e(moneyShort((float) ($paySummary['paid_late'] ?? 0) + (float) ($complaintSummary['lost_amount'] ?? 0))) ?></div>
                        <div class="s">late-paid $ + complaint lost $ in the selected range</div>
                    <?php else: ?>
                        <div class="n"><?= num($complaintSummary['complaints'] ?? 0) ?></div>
                        <div class="s">complaints in the selected range</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    </div><!-- /sections -->

    <footer class="footer">
        KPI Dashboard · Overview · source: SAP Business One via local cache<?php if ($lastRefreshed): ?> · data refreshed <?= e(substr((string) $lastRefreshed, 0, 10)) ?><?php endif; ?>
    </footer>

    <script>
    const DATA = <?= json_encode($chartData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    const GOLD = '#1d4ed8', GREEN = '#16a34a', RED = '#dc2626', BLUE = '#2563eb', PURPLE = '#7c3aed', DIM = '#9098a3', FAINT = '#d1d5db';
    const STATUS_COLORS = { 'Ready': GOLD, 'Waiting': '#8B8D98', 'Delivered': GREEN, 'In Stock': GOLD, 'Picked': BLUE, 'Shipped': GREEN, 'Expired': RED };
    const EXTRA_COLORS = [GOLD, BLUE, GREEN, PURPLE, RED, DIM];
    const usd = (v) => '$' + Number(v).toLocaleString(undefined, { maximumFractionDigits: 0 });
    const usdShort = (v) => Math.abs(v) >= 1e6 ? '$' + (v / 1e6).toFixed(1) + 'M' : (Math.abs(v) >= 1e3 ? '$' + Math.round(v / 1e3) + 'K' : usd(v));

    // ---- KPI tile sparklines (revealed on hover) --------------------------
    document.querySelectorAll('.spark[data-series]').forEach((el) => {
        const s = JSON.parse(el.dataset.series || '[]');
        const color = el.dataset.color || GOLD;
        const mx = Math.max(...s, 1);
        el.innerHTML = s.length
            ? s.map((v, i) => '<i style="height:' + Math.max(3, v / mx * 100) + '%;background:' + color + ';opacity:' + (0.5 + (i / s.length) * 0.5) + '" title="' + (DATA.sparkWeeks[i] || '') + ': ' + Number(v).toLocaleString() + '"></i>').join('')
            : '<span class="ovempty">no recent activity</span>';
    });

    // ---- Pallets by warehouse location (stacked horizontal bars) ----------
    const palletData = DATA.pallets || {};
    const locations = Object.keys(palletData);
    const statuses = DATA.palletStatuses || [];
    const statusColor = (s, i) => STATUS_COLORS[s] || EXTRA_COLORS[i % EXTRA_COLORS.length];
    let hoveredLoc = null;

    function statusCount(loc, s) {
        const st = (palletData[loc] && palletData[loc].statuses) || {};
        return st[s] ? st[s].c : 0;
    }
    function renderLegend() {
        const el = document.getElementById('plegend');
        if (!el) return;
        el.innerHTML = statuses.map((s, i) => {
            const t = locations.reduce((a, l) => a + statusCount(l, s), 0);
            const q = locations.reduce((a, l) => a + ((palletData[l].statuses[s] || { qty: 0 }).qty || 0), 0);
            return '<span class="li"><b style="background:' + statusColor(s, i) + '"></b>' + s + ' <span class="n">' + t + '</span> <span class="v">· ' + Number(q).toLocaleString() + ' units</span></span>';
        }).join('');
    }
    function renderPallets() {
        const el = document.getElementById('pbody');
        if (!el) return;
        const max = Math.max(...locations.map((l) => palletData[l].total || 0), 1);
        el.innerHTML = locations.map((loc) => {
            const d = palletData[loc];
            const tot = d.total || 0;
            return '<div class="locrow" data-loc="' + loc + '" style="opacity:' + (hoveredLoc && hoveredLoc !== loc ? 0.5 : 1) + ';transition:opacity 150ms"><div class="lr"><span class="lname">' + loc + '</span><span class="ltot">' + tot + ' pallets</span></div>' +
                '<div class="pbar">' +
                statuses.map((s, i) => {
                    const c = statusCount(loc, s);
                    return c ? '<div style="background:' + statusColor(s, i) + ';width:' + (c / max * 100) + '%" title="' + s + ': ' + c + ' pallets"></div>' : '';
                }).join('') +
                '</div>' + typeSplitLine(d) + '</div>';
        }).join('');
        el.querySelectorAll('.locrow').forEach((r) => {
            r.onmouseenter = () => { hoveredLoc = r.dataset.loc; showDetail(); };
            r.onmouseleave = () => { hoveredLoc = null; showDetail(); };
        });
    }
    // "X raw · Y finished goods" (+ $ values when loaded & visible) per group.
    function typeSplitLine(d) {
        if (!d || ((d.raw || 0) === 0 && (d.fg || 0) === 0)) return '';
        const money = DATA.showMoney && (d.totalVal || 0) > 0;
        const part = (n, v, label) => n + ' ' + label + (money ? ' (' + usdShort(v || 0) + ')' : '');
        return '<div class="ptypes">' + part(d.raw || 0, d.rawVal, 'raw') + ' · ' +
            part(d.fg || 0, d.fgVal, 'finished goods') +
            (money ? ' · ' + usdShort(d.totalVal || 0) + ' total' : '') + '</div>';
    }
    function showDetail() {
        const detail = document.getElementById('pdetail');
        if (!detail) return;
        const d = hoveredLoc ? palletData[hoveredLoc] : null;
        detail.classList.toggle('show', !!d);
        if (d) {
            const agedPct = d.total > 0 ? Math.round((d.aged || 0) / d.total * 100) : 0;
            document.getElementById('pdLoc').textContent = hoveredLoc + ' total';
            document.getElementById('pdTot').textContent = (d.total || 0) + ' pallets';
            document.getElementById('pdSplit').innerHTML = typeSplitLine(d) || '<span style="font-size:10px;color:#5C5F6A">no raw/FG classification — rerun the LPN ETL</span>';
            const ag = document.getElementById('pdAged');
            ag.textContent = agedPct + '%';
            ag.classList.toggle('warn', agedPct > 15);
            const tr = d.trend || [];
            const mx = Math.max(...tr, 1);
            document.getElementById('pdTrend').innerHTML = tr.length
                ? tr.map((v, i) => '<i style="height:' + Math.max(4, v / mx * 100) + '%;opacity:' + (0.7 + i / tr.length * 0.3) + '"></i>').join('')
                : '<span style="font-size:10px;color:#5C5F6A">no received-date data</span>';
        }
        renderPallets();
    }
    renderLegend();
    renderPallets();

    // ---- SVG line/bar charts (sales performance, growth vs late) ----------
    function lineChart(elId, labels, series, movingAvg) {
        const el = document.getElementById(elId);
        if (!el) return;
        if (!labels.length) { el.innerHTML = '<p class="ovempty">No data in the selected range.</p>'; return; }
        const W = 400, H = 150, PAD = 24, BOT = 26;
        const mx = Math.max(...series, ...movingAvg.filter((v) => v !== null), 1);
        const x = (i) => labels.length === 1 ? W / 2 : PAD + i * ((W - PAD * 2) / (labels.length - 1));
        const y = (v) => H - BOT - (v / mx) * (H - BOT - 14);
        const pts = series.map((v, i) => x(i) + ',' + y(v)).join(' ');
        const avgPts = movingAvg.map((v, i) => v === null ? null : x(i) + ',' + y(v)).filter(Boolean).join(' ');
        el.innerHTML = '<svg class="linechart" viewBox="0 0 400 150" width="100%" height="150">' +
            [0.25, 0.6, 0.95].map((f) => '<line x1="0" y1="' + (H - BOT - f * (H - BOT - 14)) + '" x2="400" y2="' + (H - BOT - f * (H - BOT - 14)) + '" stroke="#E2E4EA"/>').join('') +
            (avgPts ? '<polyline points="' + avgPts + '" fill="none" stroke="#5C5F6A" stroke-width="1.5" stroke-dasharray="5 4"/>' : '') +
            '<polyline points="' + pts + '" fill="none" stroke="' + GOLD + '" stroke-width="2.5"/>' +
            '<g fill="' + GOLD + '">' + series.map((v, i) => '<circle cx="' + x(i) + '" cy="' + y(v) + '" r="3"><title>' + labels[i] + ': ' + (DATA.showMoney ? usdShort(v) : Number(v).toLocaleString()) + '</title></circle>').join('') + '</g>' +
            '<g fill="#8B8D98" font-size="11" text-anchor="middle">' + labels.map((l, i) => '<text x="' + x(i) + '" y="' + (H - 6) + '">' + l + '</text>').join('') + '</g>' +
            '</svg>';
    }
    function growthChart(elId, labels, bars, line) {
        const el = document.getElementById(elId);
        if (!el) return;
        if (!labels.length) { el.innerHTML = '<p class="ovempty">No data in the selected range.</p>'; return; }
        const W = 400, H = 150, PAD = 24, BOT = 26, BW = 22;
        const bmx = Math.max(...bars, 1), lmx = Math.max(...line, 1);
        const x = (i) => labels.length === 1 ? W / 2 : PAD + i * ((W - PAD * 2) / (labels.length - 1));
        const by = (v) => (v / bmx) * (H - BOT - 14);
        const ly = (v) => H - BOT - (v / lmx) * (H - BOT - 14);
        el.innerHTML = '<svg class="linechart" viewBox="0 0 400 150" width="100%" height="150">' +
            [0.25, 0.6, 0.95].map((f) => '<line x1="0" y1="' + (H - BOT - f * (H - BOT - 14)) + '" x2="400" y2="' + (H - BOT - f * (H - BOT - 14)) + '" stroke="#E2E4EA"/>').join('') +
            '<g fill="#2E3440">' + bars.map((v, i) => '<rect x="' + (x(i) - BW / 2) + '" y="' + (H - BOT - by(v)) + '" width="' + BW + '" height="' + by(v) + '" rx="3"><title>' + labels[i] + ': ' + (DATA.showMoney ? usdShort(v) : Number(v).toLocaleString() + ' orders') + '</title></rect>').join('') + '</g>' +
            '<polyline points="' + line.map((v, i) => x(i) + ',' + ly(v)).join(' ') + '" fill="none" stroke="#D5766A" stroke-width="2"/>' +
            '<g fill="#D5766A">' + line.map((v, i) => '<circle cx="' + x(i) + '" cy="' + ly(v) + '" r="3"><title>' + labels[i] + ': ' + v + ' late lines</title></circle>').join('') + '</g>' +
            '<g fill="#8B8D98" font-size="11" text-anchor="middle">' + labels.map((l, i) => '<text x="' + x(i) + '" y="' + (H - 6) + '">' + l + '</text>').join('') + '</g>' +
            '</svg>';
    }
    const perf = DATA.perf || [];
    const pLabels = perf.map((r) => r.label);
    const pSeries = perf.map((r) => DATA.showMoney ? r.amount : r.orders);
    const pAvg = pSeries.map((_, i) => i < 2 ? null : (pSeries[i] + pSeries[i - 1] + pSeries[i - 2]) / 3);
    lineChart('salesChart', pLabels, pSeries, pAvg);
    growthChart('growthChart', pLabels, pSeries, perf.map((r) => r.late));

    // ---- Correlation: click a month to drill into driving customers -------
    const corr = DATA.corr || [];
    let sel = corr.length ? corr[corr.length - 1].ym : null;
    function renderCorr() {
        const el = document.getElementById('corr');
        if (!el || !corr.length) return;
        const mx = Math.max(...corr.map((r) => r.late), 1);
        el.innerHTML = corr.map((r) =>
            '<div class="cmon' + (r.ym === sel ? ' sel' : '') + '" data-m="' + r.ym + '">' +
            '<span class="pct">' + (r.pct === null ? '' : r.pct + '%') + '</span>' +
            '<div class="bar" style="height:' + Math.max(2, r.late / mx * 100) + 'px" title="' + r.late + ' late deliveries"></div>' +
            '<span class="lbl">' + r.label + '</span></div>').join('');
        el.querySelectorAll('.cmon').forEach((c) => c.onclick = () => { sel = c.dataset.m; renderCorr(); });
        const row = corr.find((r) => r.ym === sel);
        if (!row) return;
        document.getElementById('corrT').innerHTML = '<b>' + row.label + '</b> — ' + row.late + ' of ' + row.total + ' lines delivered late' +
            (row.pct === null ? '' : ', ' + row.pct + '% of invoiced $ received after due date');
        document.getElementById('corrChips').innerHTML = row.drill.length
            ? row.drill.map((c) => '<span class="chip">' + c.customer + ' <span>· ' + usdShort(c.value) + (c.days !== null ? ' · ' + c.days + 'd' : '') + '</span></span>').join('')
            : '<span class="chip"><span>no late payments recorded this month</span></span>';
    }
    renderCorr();

    // ---- Complaints donut --------------------------------------------------
    (function () {
        const svg = document.getElementById('donut');
        const legend = document.getElementById('donutLegend');
        if (!svg || !legend) return;
        const rs = DATA.reasons || [];
        const total = rs.reduce((a, r) => a + r.count, 0);
        if (!total) return;
        const C = 2 * Math.PI * 44;
        let off = 0;
        const colors = [RED, GOLD, BLUE, PURPLE, GREEN, DIM];
        svg.innerHTML = '<g transform="translate(55,55)">' + rs.map((r, i) => {
            const len = r.count / total * C;
            const seg = '<circle r="44" fill="none" stroke="' + colors[i % colors.length] + '" stroke-width="20" stroke-dasharray="' + len + ' ' + (C - len) + '" stroke-dashoffset="' + (-off) + '" transform="rotate(-90)"><title>' + r.reason + ': ' + r.count + '</title></circle>';
            off += len;
            return seg;
        }).join('') + '</g>';
        legend.innerHTML = rs.map((r, i) =>
            '<div class="row"><span class="l"><b style="background:' + colors[i % colors.length] + '"></b>' + r.reason + '</span><span class="p">' + Math.round(r.count / total * 100) + '%</span></div>').join('');
    })();

    // ---- Division share pie (SCRUM-28) -------------------------------------
    (function () {
        const svg = document.getElementById('divPie');
        const legend = document.getElementById('divLegend');
        if (!svg || !legend) return;
        const ds = DATA.divisions || [];
        const total = ds.reduce((a, r) => a + r.value, 0);
        if (!total) return;
        const C = 2 * Math.PI * 44;
        let off = 0;
        const colors = [GOLD, BLUE, GREEN, PURPLE, RED, DIM];
        svg.innerHTML = '<g transform="translate(55,55)">' + ds.map((r, i) => {
            const len = r.value / total * C;
            const seg = '<circle r="44" fill="none" stroke="' + colors[i % colors.length] + '" stroke-width="20" stroke-dasharray="' + len + ' ' + (C - len) + '" stroke-dashoffset="' + (-off) + '" transform="rotate(-90)"><title>' + r.label + ': ' + (DATA.showMoney ? usdShort(r.value) : Number(r.value).toLocaleString() + ' orders') + '</title></circle>';
            off += len;
            return seg;
        }).join('') + '</g>';
        legend.innerHTML = ds.map((r, i) =>
            '<div class="row"><span class="l"><b style="background:' + colors[i % colors.length] + '"></b>' + r.label + '</span><span class="p">' + Math.round(r.value / total * 100) + '%</span></div>').join('');
    })();

    // ---- Filter hierarchy: date unlocks warehouse --------------------------
    function dateChanged() {
        const f = document.getElementById('filterForm');
        const ok = f.elements['from_date'].value && f.elements['to_date'].value;
        document.getElementById('whWrap').classList.toggle('locked', !ok);
        const lock = document.getElementById('whLock');
        if (lock) lock.style.display = ok ? 'none' : '';
        if (ok) f.submit(); // reload data as soon as both dates are set
    }
    function pickWarehouse(btn, val) {
        const form = btn.form;
        form.elements['warehouse'].value = val;
        form.submit();
    }

    // ---- Drag-to-reorder sections with per-user persistence ----------------
    const cont = document.getElementById('sections');
    let dragEl = null;
    cont.querySelectorAll('.sec').forEach((s) => {
        const handle = s.querySelector('.handle');
        handle.addEventListener('dragstart', (e) => {
            dragEl = s;
            s.classList.add('dragging');
            e.dataTransfer.effectAllowed = 'move';
        });
        handle.addEventListener('dragend', () => {
            s.classList.remove('dragging');
            dragEl = null;
            savePrefs();
        });
    });
    cont.addEventListener('dragover', (e) => {
        e.preventDefault();
        if (!dragEl) return;
        const after = [...cont.querySelectorAll('.sec:not(.dragging)')].find((c) => e.clientY <= c.getBoundingClientRect().top + c.offsetHeight / 2);
        after ? cont.insertBefore(dragEl, after) : cont.appendChild(dragEl);
    });

    function savePrefs() {
        try {
            localStorage.setItem(DATA.layoutKey, JSON.stringify({
                order: [...cont.children].map((c) => c.dataset.id)
            }));
        } catch (e) { /* storage unavailable */ }
        const n = document.getElementById('saveNote');
        n.textContent = 'saved';
        setTimeout(() => { n.textContent = ''; }, 1200);
    }
    function resetLayout() {
        ['orders', 'inventory', 'customers', 'divisions', 'financial', 'insights'].forEach((id) => {
            const el = cont.querySelector('[data-id="' + id + '"]');
            if (el) cont.appendChild(el);
        });
        try { localStorage.removeItem(DATA.layoutKey); } catch (e) { /* ignore */ }
    }
    (function restorePrefs() {
        let saved = null;
        try { saved = localStorage.getItem(DATA.layoutKey); } catch (e) { return; }
        if (!saved) return;
        try {
            const s = JSON.parse(saved);
            if (s.order) s.order.forEach((id) => {
                const el = cont.querySelector('[data-id="' + id + '"]');
                if (el) cont.appendChild(el);
            });
        } catch (e) { /* corrupt state — ignore */ }
    })();
    </script>

    <?php endif; ?>
</main>
</body>
</html>
