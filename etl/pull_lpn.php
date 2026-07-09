<?php

declare(strict_types=1);

/**
 * ETL: pull LPN (License Plate Number) pallet detail from a source system
 * (PRIMSBM / PRODHANA WMS — Beas @BMM_* tables) and upsert it into the local
 * MySQL `lpn_pallets` cache that feeds the Warehouse dashboard's LPN panel.
 *
 * Run on the local server (XAMPP box) where the source DBs are reachable:
 *
 *   php etl/pull_lpn.php --source=PRIMSBM
 *   php etl/pull_lpn.php --source=PRIMSBM  --dry-run
 *   php etl/pull_lpn.php --source=PRIMSBM  --query=etl/queries/primsbm_lpn.sql
 *   php etl/pull_lpn.php --source=PRIMSBM  --query=etl/queries/prodhana_lpn.sql --via=PRODHANA
 *
 * The source query must return these output columns (alias them in the .sql
 * file to match your real WMS schema):
 *   source_key, lpn, status, warehouse, bin_location, item_code,
 *   item_description, batch_number, quantity, unit_of_measure,
 *   received_date, expiry_date
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

$opts = getopt('', ['source:', 'query:', 'via:', 'dry-run', 'print-sql', 'limit::']);
$source = strtoupper((string) ($opts['source'] ?? 'PRIMSBM'));
$via    = isset($opts['via']) ? trim((string) $opts['via']) : '';
$dryRun = array_key_exists('dry-run', $opts);
$printSql = array_key_exists('print-sql', $opts);
$limit  = isset($opts['limit']) ? (int) $opts['limit'] : 0;

$queryFile = (string) ($opts['query'] ?? (__DIR__ . '/queries/' . strtolower($source) . '_lpn.sql'));
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

// --print-sql: dump the exact SQL that would be sent to the source and exit.
if ($printSql) {
    echo $sql . "\n";
    exit(0);
}

/** Text columns copied through verbatim (nullified when empty). */
$textCols = [
    'lpn', 'status', 'warehouse', 'bin_location', 'item_code',
    'item_description', 'batch_number', 'unit_of_measure',
];
/** Date columns normalised to YYYY-MM-DD. */
$dateCols = ['received_date', 'expiry_date'];
/** Numeric columns. */
$numCols = ['quantity'];
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

$upsertSql = "INSERT INTO lpn_pallets ($colList)
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

        // HANA folds unquoted aliases to UPPERCASE; lowercasing keys makes the
        // matching robust across all sources.
        $row = array_change_key_case($row, CASE_LOWER);

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

        // lpn must not be empty (it is the human-facing identifier).
        if ($params[':lpn'] === null) {
            $params[':lpn'] = $key;
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
