<?php

declare(strict_types=1);

/**
 * ETL: pull operational readings (silo levels, batch-master values, …) from a
 * source system (PRIMSBM / PRODHANA — SAP Beas / B1) and upsert them into the
 * local MySQL `operational_readings` cache that feeds the Audit dashboard and
 * the email alerts (SCRUM-15).
 *
 * Run on the local server (XAMPP box) where the source DBs are reachable:
 *
 *   php etl/pull_readings.php --source=PRIMSBM
 *   php etl/pull_readings.php --source=PRIMSBM  --dry-run
 *   php etl/pull_readings.php --source=PRIMSBM  --query=etl/queries/primsbm_readings.sql
 *
 * The source query must return these output columns (alias them in the .sql
 * file to match your real schema):
 *   source_key, reading_type, location_code, location_name, item_code,
 *   item_description, batch_number, reading_value, unit_of_measure,
 *   min_threshold, max_threshold, status, reading_at, expiry_date
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

$queryFile = (string) ($opts['query'] ?? (__DIR__ . '/queries/' . strtolower($source) . '_readings.sql'));
if (!is_readable($queryFile)) {
    fwrite(STDERR, "Query file not found: $queryFile\n");
    exit(1);
}
$sql = trim((string) file_get_contents($queryFile));
if ($sql === '' || (str_starts_with(ltrim($sql), '--') && !preg_match('/select/i', $sql))) {
    fwrite(STDERR, "Query file appears empty / not filled in: $queryFile\n");
    exit(1);
}

// --via: run the query through a SQL Server linked server via OPENQUERY (see
// pull_lpn.php for the rationale).
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
}

if ($printSql) {
    echo $sql . "\n";
    exit(0);
}

/** Text columns copied through verbatim (nullified when empty). */
$textCols = [
    'reading_type', 'location_code', 'location_name', 'item_code',
    'item_description', 'batch_number', 'unit_of_measure', 'status',
];
/** Date columns normalised to YYYY-MM-DD. */
$dateCols = ['expiry_date'];
/** Datetime columns normalised to YYYY-MM-DD HH:MM:SS. */
$dtCols = ['reading_at'];
/** Numeric columns. */
$numCols = ['reading_value', 'min_threshold', 'max_threshold'];
$expected = array_merge(['source_key'], $textCols, $dateCols, $dtCols, $numCols);
$allCols  = array_merge($textCols, $dateCols, $dtCols, $numCols);

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

$upsertSql = "INSERT INTO operational_readings ($colList)
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
        foreach ($dtCols as $c) {
            $params[':' . $c] = normDateTime($row[$c] ?? null);
        }
        foreach ($numCols as $c) {
            $v = $row[$c] ?? null;
            $params[':' . $c] = ($v === null || $v === '') ? null : (float) $v;
        }

        // reading_type is required (the audit groups on it); default sensibly.
        if ($params[':reading_type'] === null) {
            $params[':reading_type'] = 'reading';
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

/** Normalise a source datetime to YYYY-MM-DD HH:MM:SS or null. */
function normDateTime(mixed $v): ?string
{
    if ($v === null || $v === '') {
        return null;
    }
    try {
        return (new DateTime((string) $v))->format('Y-m-d H:i:s');
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
