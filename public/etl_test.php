<?php

declare(strict_types=1);

/**
 * ETL query tester — runs the source SQL behind each pull (delivery,
 * inventory, LPN, payments, PO, shipments, stock snapshot, ...) read-only
 * against the configured source and shows OK (columns, sample rows, timing)
 * or the exact driver error, to pinpoint which query a failing ETL run is
 * choking on. Must run where the sources are reachable (the XAMPP box).
 * CLI twin: php etl/test_queries.php --source=PRIMSBM [--via=PRODHANA]
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

$catalog = EtlQueryTester::catalog();

$source = strtoupper(trim((string) ($_GET['source'] ?? 'PRIMSBM')));
if (!preg_match('/^[A-Z0-9_]+$/', $source)) {
    $source = 'PRIMSBM';
}
$via = trim((string) ($_GET['via'] ?? ''));
if ($via !== '' && !preg_match('/^[A-Za-z0-9_.\\\\-]+$/', $via)) {
    $via = '';
}
$pick = (string) ($_GET['q'] ?? '');
$run = isset($_GET['run']);

// With a linked server the prodhana_* set is what runs; otherwise the
// queries written for the source itself.
$prefix = $via !== '' ? 'prodhana_' : strtolower($source) . '_';
$testable = array_filter($catalog, static fn(string $n): bool => str_starts_with($n, $prefix), ARRAY_FILTER_USE_KEY);

$results = [];
if ($run) {
    $toRun = ($pick !== '' && isset($testable[$pick])) ? [$pick => $testable[$pick]] : $testable;
    foreach ($toRun as $name => $file) {
        $results[] = EtlQueryTester::run($name, $file, $source, $via);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>KPI Dashboard · ETL Query Tester</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
<header class="topbar">
    <div class="brand">KPI Dashboard</div>
    <div class="subtitle">ETL Query Tester</div>
    <nav class="topnav">
        <?php foreach (Auth::allowedPages() as $navInfo): ?>
        <a href="<?= e($navInfo['page']) ?>"><?= e($navInfo['label']) ?></a>
        <?php endforeach; ?>
    </nav>
</header>
<main>
    <section class="panel panel-wide">
        <h2>Test the ETL source queries</h2>
        <p class="panel-note">Runs the SQL behind each pull <strong>read-only</strong> against the source (nothing is written) and shows the columns, a few sample rows and timing — or the exact driver error — so a failing <code>pull_delivery</code> / <code>pull_inventory</code> / <code>pull_lpn</code> run can be pinpointed to its query. Run this on the box that can reach the source (XAMPP). CLI twin: <code>php etl/test_queries.php --source=PRIMSBM --via=PRODHANA</code>.</p>
        <form method="get" class="filters">
            <div class="filter">
                <label for="source">Source</label>
                <input id="source" name="source" type="text" value="<?= e($source) ?>">
            </div>
            <div class="filter">
                <label for="via">Linked server (--via)</label>
                <input id="via" name="via" type="text" value="<?= e($via) ?>" placeholder="e.g. PRODHANA">
            </div>
            <div class="filter">
                <label for="q">Query</label>
                <select id="q" name="q">
                    <option value="">All (<?= count($testable) ?>)</option>
                    <?php foreach (array_keys($testable) as $name): ?>
                    <option value="<?= e($name) ?>"<?= $pick === $name ? ' selected' : '' ?>><?= e($name) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button type="submit" name="run" value="1">Run tests</button>
        </form>
        <?php if ($run): ?>
        <table>
            <thead><tr><th>Query</th><th>Status</th><th class="num">Rows fetched</th><th class="num">Columns</th><th class="num">Time (s)</th><th>Error / sample</th></tr></thead>
            <tbody>
            <?php foreach ($results as $r): ?>
                <tr>
                    <td><code><?= e($r['name']) ?></code></td>
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
            <?php if ($results === []): ?>
                <tr><td colspan="6" class="empty">No queries matched (prefix <code><?= e($prefix) ?>*</code>).</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </section>
</main>
</body>
</html>
