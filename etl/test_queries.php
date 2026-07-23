<?php

declare(strict_types=1);

/**
 * Test the ETL source queries one by one to pinpoint which pull is failing.
 * Read-only — nothing is written to the local cache or the source.
 *
 * Run on the XAMPP box where the sources are reachable:
 *
 *   php etl/test_queries.php --source=PRIMSBM                                  # all primsbm_* queries
 *   php etl/test_queries.php --source=PRIMSBM --via=PRODHANA                   # all prodhana_* queries through the linked server
 *   php etl/test_queries.php --source=PRIMSBM --via=PRODHANA --only=delivery   # just queries whose name contains 'delivery'
 *
 * Output per query: OK with columns / rows fetched / timing, or the exact
 * driver error message.
 */

require_once __DIR__ . '/../src/EtlQueryTester.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line (use public/etl_test.php in the browser).\n");
    exit(1);
}

$opts = getopt('', ['source:', 'via::', 'only::', 'rows::']);
$source = strtoupper(trim((string) ($opts['source'] ?? '')));
$via    = trim((string) ($opts['via'] ?? ''));
$only   = strtolower(trim((string) ($opts['only'] ?? '')));
$rows   = max(0, (int) ($opts['rows'] ?? 3));

if ($source === '') {
    fwrite(STDERR, "Usage: php etl/test_queries.php --source=PRIMSBM [--via=PRODHANA] [--only=delivery] [--rows=3]\n");
    exit(1);
}

// With --via the linked-server queries are the prodhana_* set; without it,
// test the queries written for the source itself.
$prefix = $via !== '' ? 'prodhana_' : strtolower($source) . '_';

$queries = [];
foreach (EtlQueryTester::catalog() as $name => $file) {
    if (!str_starts_with($name, $prefix)) {
        continue;
    }
    if ($only !== '' && !str_contains($name, $only)) {
        continue;
    }
    $queries[$name] = $file;
}

if ($queries === []) {
    fwrite(STDERR, "No queries matched (prefix=$prefix" . ($only !== '' ? ", only=$only" : '') . ").\n");
    exit(1);
}

echo '[test] source=' . $source . ($via !== '' ? " via=$via" : '') . '  queries=' . count($queries) . "\n\n";

$failed = 0;
foreach ($queries as $name => $file) {
    $r = EtlQueryTester::run($name, $file, $source, $via, $rows);
    if ($r['ok']) {
        printf(
            "OK    %-32s rows>=%d%s  cols=%d  %.2fs\n",
            $name,
            $r['rows_fetched'],
            $r['truncated'] ? '+' : '',
            count($r['columns']),
            $r['seconds']
        );
        foreach ($r['sample'] as $i => $row) {
            echo '      sample#' . ($i + 1) . ' ' . json_encode($row, JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR) . "\n";
        }
    } else {
        $failed++;
        printf("FAIL  %-32s %.2fs\n      %s\n", $name, $r['seconds'], $r['error']);
    }
}

echo "\n[test] done. " . (count($queries) - $failed) . ' ok, ' . $failed . " failed.\n";
exit($failed > 0 ? 2 : 0);
