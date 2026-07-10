<?php

declare(strict_types=1);

/**
 * ETL: pull the expanded Warehouse view caches (migration 010) from a source
 * system into local MySQL. One script covers the four datasets:
 *
 *   php etl/pull_inventory.php --what=stock      --source=PRIMSBM
 *   php etl/pull_inventory.php --what=packaging  --source=PRIMSBM
 *   php etl/pull_inventory.php --what=batches    --source=PRIMSBM
 *   php etl/pull_inventory.php --what=movements  --source=PRIMSBM
 *   php etl/pull_inventory.php --what=production --source=PRIMSBM
 *
 * Common flags (same behaviour as pull_lpn.php): --dry-run, --print-sql,
 * --limit=N, --query=path.sql, --via=LINKEDSERVER (OPENQUERY passthrough).
 *
 * Default query file: etl/queries/{source}_{what}.sql — the .sql templates
 * document the expected output columns; alias your real SAP B1 / Beas columns
 * to them. READ-ONLY on the source; idempotent upserts on the target.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/source_db.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

/**
 * Per-dataset spec: target table, key columns and column types.
 * 'key' lists the column(s) forming the upsert identity (besides
 * source_system, which is added automatically except for packaging).
 */
$specs = [
    'stock' => [
        'table' => 'warehouse_stock',
        'withSourceKey' => true,
        'text' => ['item_code', 'item_description', 'warehouse', 'unit_of_measure'],
        'date' => [],
        'num'  => ['on_hand', 'committed', 'on_order', 'pallets'],
        'required' => ['item_code', 'warehouse'],
    ],
    'packaging' => [
        'table' => 'material_packaging',
        'withSourceKey' => false,          // PK is item_code
        'text' => ['item_code', 'item_description', 'base_uom', 'pack_description'],
        'date' => [],
        'num'  => ['units_per_case', 'cases_per_pallet', 'units_per_pallet'],
        'required' => ['item_code'],
    ],
    'batches' => [
        'table' => 'inventory_batches',
        'withSourceKey' => true,
        'text' => ['item_code', 'item_description', 'batch_number', 'warehouse', 'unit_of_measure'],
        'date' => ['admission_date', 'expiry_date'],
        'num'  => ['quantity', 'pallets'],
        'required' => ['item_code', 'warehouse'],
    ],
    'movements' => [
        'table' => 'material_movements',
        'withSourceKey' => true,
        'text' => ['movement_type', 'item_code', 'item_description', 'from_warehouse', 'to_warehouse', 'unit_of_measure'],
        'date' => ['doc_date'],
        'num'  => ['quantity'],
        'required' => ['movement_type'],
    ],
    'production' => [
        'table' => 'production_usage',
        'withSourceKey' => true,
        'text' => ['production_order', 'item_code', 'item_description', 'warehouse', 'unit_of_measure'],
        'date' => ['doc_date'],
        'num'  => ['planned_qty', 'actual_qty'],
        'required' => ['item_code'],
    ],
];

$opts = getopt('', ['what:', 'source:', 'query:', 'via:', 'dry-run', 'print-sql', 'limit::']);
$what = strtolower((string) ($opts['what'] ?? ''));
if (!isset($specs[$what])) {
    fwrite(STDERR, "Usage: php etl/pull_inventory.php --what=stock|packaging|batches|movements|production [--source=PRIMSBM] [--dry-run]\n");
    exit(1);
}
$spec   = $specs[$what];
$source = strtoupper((string) ($opts['source'] ?? 'PRIMSBM'));
$via    = isset($opts['via']) ? trim((string) $opts['via']) : '';
$dryRun = array_key_exists('dry-run', $opts);
$printSql = array_key_exists('print-sql', $opts);
$limit  = isset($opts['limit']) ? (int) $opts['limit'] : 0;

$queryFile = (string) ($opts['query'] ?? (__DIR__ . '/queries/' . strtolower($source) . '_' . $what . '.sql'));
if (!is_readable($queryFile)) {
    fwrite(STDERR, "Query file not found: $queryFile\n");
    exit(1);
}
$sql = trim((string) file_get_contents($queryFile));
if ($sql === '' || (str_starts_with(ltrim($sql), '--') && !preg_match('/select/i', $sql))) {
    fwrite(STDERR, "Query file appears empty / not filled in: $queryFile\n");
    exit(1);
}

// --via: run the query through a SQL Server linked server via OPENQUERY.
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

if ($printSql) {
    echo $sql . "\n";
    exit(0);
}

$textCols = $spec['text'];
$dateCols = $spec['date'];
$numCols  = $spec['num'];
$allCols  = array_merge($textCols, $dateCols, $numCols);
$expected = $spec['withSourceKey'] ? array_merge(['source_key'], $allCols) : $allCols;

echo "[etl] what=$what source=$source query=$queryFile" . ($dryRun ? '  (dry-run)' : '') . "\n";

try {
    $src = SourceDb::connection($source);
} catch (Throwable $e) {
    fwrite(STDERR, '[etl] ' . $e->getMessage() . "\n");
    exit(2);
}

$target = $dryRun ? null : Database::connection();

$insertCols = array_merge($allCols, ['source_system']);
if ($spec['withSourceKey']) {
    $insertCols[] = 'source_key';
}
$colList = implode(', ', $insertCols);
$placeholders = implode(', ', array_map(static fn(string $c): string => ':' . $c, $insertCols));
$updateCols = array_merge($allCols, $spec['withSourceKey'] ? [] : ['source_system']);
$updates = implode(",\n     ", array_map(static fn(string $c): string => "$c = VALUES($c)", $updateCols));

$upsertSql = "INSERT INTO {$spec['table']} ($colList)
   VALUES ($placeholders)
   ON DUPLICATE KEY UPDATE
     $updates,
     refreshed_at = CURRENT_TIMESTAMP";

$upsert = $target?->prepare($upsertSql);

$read = 0;
$written = 0;
$skipped = 0;
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

        $params = [':source_system' => $source];
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

        if ($spec['withSourceKey']) {
            $key = trim((string) ($row['source_key'] ?? ''));
            if ($key === '') {
                $skipped++;
                fwrite(STDERR, "[etl] skipping row with empty source_key\n");
                continue;
            }
            $params[':source_key'] = $key;
        }

        $bad = false;
        foreach ($spec['required'] as $c) {
            if ($params[':' . $c] === null) {
                $skipped++;
                fwrite(STDERR, "[etl] skipping row with empty required column '$c'\n");
                $bad = true;
                break;
            }
        }
        if ($bad) {
            continue;
        }

        // movement_type must be one of the four normalised stages.
        if ($what === 'movements') {
            $t = strtolower((string) $params[':movement_type']);
            if (!in_array($t, ['receipt', 'transfer', 'issue', 'waste'], true)) {
                $skipped++;
                fwrite(STDERR, "[etl] skipping row with unknown movement_type '$t'\n");
                continue;
            }
            $params[':movement_type'] = $t;
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

echo "[etl] done. read=$read written=$written skipped=$skipped" . ($dryRun ? ' (dry-run: nothing written)' : '') . "\n";
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
