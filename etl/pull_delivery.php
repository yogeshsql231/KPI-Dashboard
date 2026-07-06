<?php

declare(strict_types=1);

/**
 * ETL: pull delivery / OMS order lines from a source system (PRIMSBM / PRODHANA)
 * and upsert them into the local MySQL `delivery_lines` cache that feeds the
 * delivery dashboard.
 *
 * Run on the local server (XAMPP box) where the source DBs are reachable:
 *
 *   php etl/pull_delivery.php --source=PRODHANA
 *   php etl/pull_delivery.php --source=PRIMSBM  --dry-run
 *   php etl/pull_delivery.php --source=PRIMSBM  --query=etl/queries/primsbm_delivery.sql
 *
 * --via=<LinkedServer>: run the (HANA) query THROUGH a SQL Server linked server
 * using OPENQUERY, so live HANA data is fetched via the SQL Server connection
 * (handy when a direct HANA login is unavailable but SQL Server has a linked
 * server to HANA). Example — pull live HANA delivery lines via the PRODHANA
 * linked server on the PRIMSBM SQL Server:
 *
 *   php etl/pull_delivery.php --source=PRIMSBM --query=etl/queries/prodhana_delivery.sql --via=PRODHANA
 *
 * The source query must return these output columns (alias them in the .sql
 * file to match your real schema):
 *   source_key, sales_order, so_status, posting_date, ship_date, required_date,
 *   customer_code, customer_name, customer_group, is_retail, po_number,
 *   item_code, item_description, warehouse, order_qty, qty_pallet, qty_per_pack,
 *   qty_per_pallet, unit_of_measure, released_qty, delivered_qty, line_amount,
 *   delivered_amount, pick_qty, pick_status, approved, short_shipment,
 *   late_shipment, complete_shipment, otif, fill_rate, manual_bol, carrier
 *
 * Idempotent: rows are upserted on (source_system, source_key), so re-running
 * updates existing rows instead of duplicating them. READ-ONLY on the source.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/source_db.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

$opts = getopt('', ['source:', 'query:', 'via:', 'dry-run', 'limit::']);
$source = strtoupper((string) ($opts['source'] ?? 'PRODHANA'));
$via    = isset($opts['via']) ? trim((string) $opts['via']) : '';
$dryRun = array_key_exists('dry-run', $opts);
$limit  = isset($opts['limit']) ? (int) $opts['limit'] : 0;

$queryFile = (string) ($opts['query'] ?? (__DIR__ . '/queries/' . strtolower($source) . '_delivery.sql'));
if (!is_readable($queryFile)) {
    fwrite(STDERR, "Query file not found: $queryFile\n");
    exit(1);
}
$sql = trim((string) file_get_contents($queryFile));
if ($sql === '' || (str_starts_with(ltrim($sql), '--') && !preg_match('/select/i', $sql))) {
    fwrite(STDERR, "Query file appears empty / not filled in: $queryFile\n");
    exit(1);
}

// --via: run the query through a SQL Server linked server via OPENQUERY. The
// inner query text is passed verbatim to the remote (HANA), so single quotes
// are doubled and any trailing ';' is dropped (OPENQUERY takes one statement).
if ($via !== '') {
    if (!preg_match('/^[A-Za-z0-9_.\\\\-]+$/', $via)) {
        fwrite(STDERR, "Invalid --via linked-server name: $via\n");
        exit(1);
    }
    // Drop full-line comments/blank lines to keep the OPENQUERY string literal
    // compact (it has an 8000-char limit) and free of '--' comment side effects.
    $lines = preg_split('/\r?\n/', $sql) ?: [];
    $kept = array_filter($lines, static function (string $l): bool {
        $t = ltrim($l);
        return $t !== '' && !str_starts_with($t, '--');
    });
    $inner = rtrim(implode("\n", $kept));
    $inner = rtrim($inner, ';');
    $inner = str_replace("'", "''", $inner);
    $sql = "SELECT * FROM OPENQUERY([$via], '$inner')";
    if (strlen($inner) > 7900) {
        fwrite(STDERR, "[etl] warning: OPENQUERY inner query is " . strlen($inner) . " chars (limit ~8000).\n");
    }
}

/** Text columns copied through verbatim (nullified when empty). */
$textCols = [
    'sales_order', 'so_status', 'customer_code', 'customer_name', 'customer_group',
    'po_number', 'item_code', 'item_description', 'warehouse', 'unit_of_measure',
    'pick_status', 'approved', 'short_shipment', 'late_shipment',
    'complete_shipment', 'otif', 'manual_bol', 'carrier',
];
/** Date columns normalised to YYYY-MM-DD. */
$dateCols = ['posting_date', 'ship_date', 'required_date'];
/** Numeric columns. */
$numCols = [
    'order_qty', 'qty_pallet', 'qty_per_pack', 'qty_per_pallet', 'released_qty',
    'delivered_qty', 'line_amount', 'delivered_amount', 'pick_qty', 'fill_rate',
    'is_retail',
];
$expected = array_merge(['source_key'], $textCols, $dateCols, $numCols);
$allCols  = array_merge($textCols, $dateCols, $numCols);

echo "[etl] source=$source  query=$queryFile" . ($dryRun ? '  (dry-run)' : '') . "\n";

try {
    $src = SourceDb::connection($source);
} catch (Throwable $e) {
    fwrite(STDERR, '[etl] ' . $e->getMessage() . "\n");
    exit(2);
}

$target = $dryRun ? null : Database::connection();

$colList  = implode(', ', array_merge($allCols, ['source_system', 'source_key']));
$placeholders = implode(', ', array_map(static fn(string $c): string => ':' . $c, array_merge($allCols, ['source_system', 'source_key'])));
$updates = implode(",\n     ", array_map(static fn(string $c): string => "$c = VALUES($c)", $allCols));

$upsertSql = "INSERT INTO delivery_lines ($colList)
   VALUES ($placeholders)
   ON DUPLICATE KEY UPDATE
     $updates,
     refreshed_at = CURRENT_TIMESTAMP";

$upsert = $target?->prepare($upsertSql);

$read = 0;
$written = 0;
$errors = 0;
$checkedColumns = false;

try {
    $stmt = $src->query($sql);
} catch (Throwable $e) {
    fwrite(STDERR, '[etl] source query failed: ' . $e->getMessage() . "\n");
    exit(2);
}

$target?->beginTransaction();
try {
    while (($row = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
        $read++;

        if (!$checkedColumns) {
            $missing = array_diff($expected, array_keys($row));
            if ($missing !== []) {
                throw new RuntimeException(
                    'Source query is missing required output columns: ' . implode(', ', $missing)
                );
            }
            $checkedColumns = true;
        }

        $key = trim((string) ($row['source_key'] ?? ''));
        if ($key === '') {
            $errors++;
            fwrite(STDERR, "[etl] skipping row with empty source_key\n");
            continue;
        }

        $params = [':source_system' => $source, ':source_key' => $key];
        foreach ($textCols as $c) {
            $params[':' . $c] = nullify($row[$c] ?? null);
        }
        foreach ($dateCols as $c) {
            $params[':' . $c] = normDate($row[$c] ?? null);
        }
        foreach ($numCols as $c) {
            $v = $row[$c] ?? null;
            $params[':' . $c] = ($v === null || $v === '') ? null : (float) $v;
        }

        if ($dryRun) {
            if ($read <= 5) {
                echo '[etl] would upsert: ' . json_encode($params, JSON_UNESCAPED_SLASHES) . "\n";
            }
        } else {
            $upsert->execute($params);
            $written++;
        }

        if ($limit > 0 && $read >= $limit) {
            break;
        }
    }

    $target?->commit();
} catch (Throwable $e) {
    if ($target?->inTransaction()) {
        $target->rollBack();
    }
    fwrite(STDERR, '[etl] failed: ' . $e->getMessage() . "\n");
    exit(3);
}

echo "[etl] done. read=$read written=$written skipped=$errors" . ($dryRun ? ' (dry-run: nothing written)' : '') . "\n";
exit(0);

/** Normalise a source date/datetime to YYYY-MM-DD or null. */
function normDate(mixed $v): ?string
{
    if ($v === null || $v === '') {
        return null;
    }
    try {
        return (new DateTime((string) $v))->format('Y-m-d');
    } catch (Throwable) {
        return null;
    }
}

/** Empty string -> null, else trimmed string. */
function nullify(mixed $v): ?string
{
    if ($v === null) {
        return null;
    }
    $s = trim((string) $v);
    return $s === '' ? null : $s;
}
