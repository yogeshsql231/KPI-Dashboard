<?php

declare(strict_types=1);

/**
 * ETL pull tester — runs the exact SQL each ETL pull fires in production
 * (pull_delivery, pull_inventory --what=..., pull_lpn, pull_po, pull_payments,
 * pull_shipments, pull_stock_snapshot, ...) read-only against the source and
 * shows OK (columns, sample rows, timing) or the exact driver error, so a
 * failing pull can be pinpointed to its query. Must run where the sources are
 * reachable (the XAMPP box). Nothing is ever written to the cache or SAP.
 * CLI twin: php etl/test_queries.php --source=PRIMSBM --via=PRODHANA
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../src/Auth.php';
require_once __DIR__ . '/../src/EtlQueryTester.php';

Auth::requireDepartment('audit');

/** HTML-escape helper. */
function e(mixed $v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}

$pulls = EtlQueryTester::pulls();

$pick = (string) ($_GET['pull'] ?? '');
$run = isset($_GET['run']);

$results = [];
if ($run) {
    $toRun = ($pick !== '' && isset($pulls[$pick])) ? [$pick => $pulls[$pick]] : $pulls;
    foreach ($toRun as $label => $p) {
        $r = EtlQueryTester::run($label, $p['query'], $p['source'], $p['via']);
        $r['command'] = $p['command'];
        $r['query_file'] = 'etl/queries/' . basename($p['query']);
        try {
            $r['sql'] = EtlQueryTester::buildSql($p['query'], $p['via']);
        } catch (Throwable) {
            $r['sql'] = '';
        }
        $results[] = $r;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>KPI Dashboard · ETL Pull Tester</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
<header class="topbar">
    <div class="brand">KPI Dashboard</div>
    <div class="subtitle">ETL Pull Tester</div>
    <nav class="topnav">
        <?php foreach (Auth::allowedPages() as $navInfo): ?>
        <a href="<?= e($navInfo['page']) ?>"><?= e($navInfo['label']) ?></a>
        <?php endforeach; ?>
    </nav>
</header>
<main>
    <section class="panel panel-wide">
        <h2>Test the ETL pulls</h2>
        <p class="panel-note">Runs the exact SQL each pull fires in production <strong>read-only</strong> (nothing is written — only a few sample rows are fetched) and shows the columns, sample rows and timing, or the exact driver error, so a failing <code>pull_delivery</code> / <code>pull_inventory</code> / <code>pull_lpn</code> run can be pinpointed to its query. Run this on the box that can reach the source (XAMPP).</p>
        <form method="get" class="filters">
            <div class="filter">
                <label for="pull">Pull</label>
                <select id="pull" name="pull">
                    <option value="">All (<?= count($pulls) ?>)</option>
                    <?php foreach (array_keys($pulls) as $label): ?>
                    <option value="<?= e($label) ?>"<?= $pick === $label ? ' selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" name="run" value="1">Run tests</button>
        </form>
        <table>
            <thead><tr><th>Pull</th><th>Command / query</th><?php if ($run): ?><th>Status</th><th class="num">Rows fetched</th><th class="num">Columns</th><th class="num">Time (s)</th><th>Error / sample</th><?php endif; ?></tr></thead>
            <tbody>
            <?php if ($run): ?>
                <?php foreach ($results as $r): ?>
                <tr>
                    <td><strong><?= e($r['name']) ?></strong></td>
                    <td>
                        <code><?= e($r['command']) ?></code>
                        <?php if ($r['sql'] !== ''): ?>
                        <details><summary>SQL fired</summary><pre style="white-space:pre-wrap"><?= e($r['sql']) ?></pre></details>
                        <?php endif; ?>
                    </td>
                    <td><?= $r['ok'] ? '<span class="good">OK</span>' : '<span class="bad">FAIL</span>' ?></td>
                    <td class="num"><?= number_format($r['rows_fetched']) ?><?= $r['truncated'] ? '+' : '' ?></td>
                    <td class="num"><?= count($r['columns']) ?></td>
                    <td class="num"><?= number_format($r['seconds'], 2) ?></td>
                    <td>
                        <?php if ($r['ok']): ?>
                            <?php if ($r['sample'] === []): ?>
                                <em>no rows returned</em>
                            <?php else: ?>
                                <details><summary>columns + sample rows</summary>
                                    <p><code><?= e(implode(', ', $r['columns'])) ?></code></p>
                                    <?php foreach ($r['sample'] as $row): ?>
                                    <p><code><?= e((string) json_encode($row, JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR)) ?></code></p>
                                    <?php endforeach; ?>
                                </details>
                            <?php endif; ?>
                        <?php else: ?>
                            <code class="bad"><?= e($r['error']) ?></code>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <?php foreach ($pulls as $label => $p): ?>
                <tr>
                    <td><strong><?= e($label) ?></strong></td>
                    <td><code><?= e($p['command']) ?></code></td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </section>
</main>
</body>
</html>
