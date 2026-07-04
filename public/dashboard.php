<?php

declare(strict_types=1);

/**
 * Main KPI Dashboard - Customer Service / Order Management.
 *
 * Renders the first set of Customer Service KPIs (OTIF, Item Fill Rate,
 * Shipped Short, Lead Time, Complaints, PO Revisions) straight from the
 * database views. No user login yet (per current scope).
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../src/KpiRepository.php';
require_once __DIR__ . '/../src/ViewHelper.php';

$error = null;
$summary = [];
$targets = [];
$byDate = [];
$topCustomers = [];
$topSkus = [];
$pareto = [];

try {
    $repo = new KpiRepository(Database::connection());
    $summary = $repo->summary();
    $targets = $repo->targets();
    $byDate = $repo->byDate();
    $topCustomers = $repo->topCustomers(10);
    $topSkus = $repo->topSkus(10);
    $pareto = $repo->complaintsPareto();
} catch (Throwable $ex) {
    $error = 'Unable to load KPI data. Check the database connection in your .env file.';
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
    <title>KPI Dashboard · Customer Service / OMS</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
<header class="topbar">
    <div class="brand">KPI Dashboard</div>
    <div class="subtitle">Customer Service / Order Management</div>
</header>

<main class="container">
    <?php if ($error !== null): ?>
        <div class="alert"><?= ViewHelper::e($error) ?></div>
    <?php else: ?>

    <section class="cards">
        <?php $c = ViewHelper::ratioClass($otif, (float) $otifTarget); ?>
        <div class="card <?= $c ?>">
            <div class="card-label">OTIF (On-Time In-Full)</div>
            <div class="card-value"><?= ViewHelper::pct($otif) ?></div>
            <div class="card-target">Target <?= ViewHelper::pct((float) $otifTarget, 0) ?></div>
        </div>

        <?php $c = ViewHelper::ratioClass($ifr, (float) $ifrTarget); ?>
        <div class="card <?= $c ?>">
            <div class="card-label">Item Fill Rate</div>
            <div class="card-value"><?= ViewHelper::pct($ifr) ?></div>
            <div class="card-target">Target <?= ViewHelper::pct((float) $ifrTarget, 0) ?></div>
        </div>

        <div class="card <?= ($summary['shipped_short_cases'] ?? 0) > 0 ? 'warn' : 'good' ?>">
            <div class="card-label">Shipped Short</div>
            <div class="card-value"><?= ViewHelper::num($summary['shipped_short_cases'] ?? 0) ?></div>
            <div class="card-target">cases · target 0</div>
        </div>

        <div class="card neutral">
            <div class="card-label">Avg Lead Time</div>
            <div class="card-value"><?= $summary['avg_lead_time_days'] !== null ? number_format((float) $summary['avg_lead_time_days'], 1) : "\u{2014}" ?></div>
            <div class="card-target">days (order → ship)</div>
        </div>

        <div class="card neutral">
            <div class="card-label">Customer Complaints</div>
            <div class="card-value"><?= ViewHelper::num($summary['total_complaints'] ?? 0) ?></div>
            <div class="card-target">total logged</div>
        </div>

        <div class="card neutral">
            <div class="card-label">PO Revisions</div>
            <div class="card-value"><?= ViewHelper::num($summary['total_po_revisions'] ?? 0) ?></div>
            <div class="card-target">customer-requested</div>
        </div>
    </section>

    <section class="meta">
        <span><strong><?= ViewHelper::num($summary['total_lines'] ?? 0) ?></strong> order lines</span>
        <span><strong><?= ViewHelper::num($summary['total_pos'] ?? 0) ?></strong> POs</span>
        <span><strong><?= ViewHelper::num($summary['total_qty_shipped'] ?? 0) ?></strong> cases shipped</span>
    </section>

    <div class="grid">
        <section class="panel">
            <h2>OTIF &amp; Fill Rate by Date</h2>
            <table>
                <thead>
                    <tr><th>Date</th><th>Lines</th><th>OTIF</th><th>Fill Rate</th><th>Short</th></tr>
                </thead>
                <tbody>
                <?php foreach ($byDate as $r): ?>
                    <tr>
                        <td><?= ViewHelper::e($r['ship_date']) ?></td>
                        <td class="num"><?= ViewHelper::num($r['line_count']) ?></td>
                        <td class="num"><?= ViewHelper::pct($r['otif']) ?></td>
                        <td class="num"><?= ViewHelper::pct($r['item_fill_rate']) ?></td>
                        <td class="num"><?= ViewHelper::num($r['shipped_short_cases']) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($byDate === []): ?>
                    <tr><td colspan="5" class="empty">No data</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </section>

        <section class="panel">
            <h2>Complaints by Concern Type</h2>
            <table>
                <thead><tr><th>Concern Type</th><th>Count</th></tr></thead>
                <tbody>
                <?php foreach ($pareto as $r): ?>
                    <tr>
                        <td><?= ViewHelper::e($r['concern_type']) ?></td>
                        <td class="num"><?= ViewHelper::num($r['complaint_count']) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($pareto === []): ?>
                    <tr><td colspan="2" class="empty">No data</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </section>

        <section class="panel">
            <h2>Top Customers by Cases Shipped</h2>
            <table>
                <thead><tr><th>Customer</th><th>Cases</th></tr></thead>
                <tbody>
                <?php foreach ($topCustomers as $r): ?>
                    <tr>
                        <td><?= ViewHelper::e($r['customer']) ?></td>
                        <td class="num"><?= ViewHelper::num($r['qty_shipped']) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($topCustomers === []): ?>
                    <tr><td colspan="2" class="empty">No data</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </section>

        <section class="panel">
            <h2>Top SKUs by Cases Shipped</h2>
            <table>
                <thead><tr><th>Item #</th><th>Cases</th></tr></thead>
                <tbody>
                <?php foreach ($topSkus as $r): ?>
                    <tr>
                        <td><?= ViewHelper::e($r['item_number']) ?></td>
                        <td class="num"><?= ViewHelper::num($r['qty_shipped']) ?></td>
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
</body>
</html>
