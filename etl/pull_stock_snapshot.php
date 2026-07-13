<?php

declare(strict_types=1);

/**
 * ETL: capture a daily on-hand inventory SNAPSHOT into the local MySQL
 * `inventory_stock_snapshots` cache (migration 014) that feeds the Warehouse
 * dashboard's Stockout Frequency widget (SCRUM-93).
 *
 * The warehouse_stock cache holds only the CURRENT on-hand and is overwritten
 * on every refresh, so it cannot show whether a SKU hit zero during a period.
 * This script appends one dated row per item x warehouse, building the history
 * needed to detect stockouts (zero-crossings). Run it once per day on a
 * schedule on the local server (XAMPP box) where the source DBs are reachable:
 *
 *   php etl/pull_stock_snapshot.php --source=PRIMSBM
 *   php etl/pull_stock_snapshot.php --source=PRIMSBM --date=2026-07-13
 *   php etl/pull_stock_snapshot.php --source=PRIMSBM --dry-run
 *   php etl/pull_stock_snapshot.php --source=PRIMSBM \
 *       --query=etl/queries/prodhana_stock_snapshot.sql --via=PRODHANA
 *
 * The source query must return these output columns (alias them in the .sql
 * file to match your real SAP schema):
 *   item_code, item_description, warehouse, on_hand, is_active,
 *   product_type, category
 *
 * Idempotent: rows are upserted on (source_system, snapshot_date, item_code,
 * warehouse), so re-running for the same day corrects that day's snapshot
 * instead of duplicating it. READ-ONLY on the source.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/source_db.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

$opts = getopt('', ['source:', 'query:', 'via:', 'date:', 'dry-run', 'print-sql', 'limit::']);
$source = strtoupper((string) ($opts['source'] ?? 'PRIMSBM'));
$via    = isset($opts['via']) ? trim((string) $opts['via']) : '';
$dryRun = array_key_exists('dry-run', $opts);
$printSql = array_key_exists('print-sql', $opts);
$limit  = isset($opts['limit']) ? (int) $opts['limit'] : 0;

// Snapshot date: --date=YYYY-MM-DD, defaulting to today. Validated strictly so
// a typo can never write an out-of-range row.
$dateArg = isset($opts['date']) ? trim((string) $opts['date']) : '';
if ($dateArg === '') {
    $snapshotDate = (new DateTime('today'))->format('Y-m-d');
} else {
    $dt = DateTime::createFromFormat('Y-m-d', $dateArg);
    if (!$dt || $dt->format('Y-m-d') !== $dateArg) {
        fwrite(STDERR, "Invalid --date '$dateArg' (expected YYYY-MM-DD).\n");
        exit(1);
    }
    $snapshotDate = $dateArg;
}

// Direct PRODHANA (HANA) connections are not supported from this environment;
// route HANA through the PRIMSBM linked-server bridge, same as pull_lpn.php.
if ($source === 'PRODHANA') {
    fwrite(
        STDERR,
        "Direct PRODHANA connections are not supported. Use --source=PRIMSBM "
        . "--query=etl/queries/prodhana_stock_snapshot.sql --via=PRODHANA\n"
    );
    exit(1);
}

$queryFile = (string) ($opts['query'] ?? (__DIR__ . '/queries/' . strtolower($source) . '_stock_snapshot.sql'));
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
// inner query text is passed verbatim to the remote (HANA), so comment lines
// are stripped, single quotes doubled and any trailing ';' dropped.
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

$textCols = ['item_code', 'item_description', 'warehouse', 'product_type', 'category'];
$required = ['item_code', 'warehouse'];

echo "[etl] stock snapshot date=$snapshotDate source=$source query=$queryFile" . ($dryRun ? '  (dry-run)' : '') . "\n";

try {
    $src = SourceDb::connection($source);
} catch (Throwable $e) {
    fwrite(STDERR, '[etl] ' . $e->getMessage() . "\n");
    exit(2);
}

$target = $dryRun ? null : Database::connection();

$upsertSql =
    "INSERT INTO inventory_stock_snapshots
        (source_system, snapshot_date, item_code, item_description, warehouse,
         on_hand, is_active, product_type, category)
     VALUES
        (:source_system, :snapshot_date, :item_code, :item_description, :warehouse,
         :on_hand, :is_active, :product_type, :category)
     ON DUPLICATE KEY UPDATE
        item_description = VALUES(item_description),
        on_hand          = VALUES(on_hand),
        is_active        = VALUES(is_active),
        product_type     = VALUES(product_type),
        category         = VALUES(category),
        refreshed_at     = CURRENT_TIMESTAMP";

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
            $missing = array_diff(['item_code', 'warehouse', 'on_hand'], array_keys($row));
            if ($missing !== []) {
                throw new RuntimeException(
                    'Source query is missing required output columns: ' . implode(', ', $missing)
                );
            }
            $checkedColumns = true;
        }

        $params = [
            ':source_system' => $source,
            ':snapshot_date' => $snapshotDate,
        ];
        foreach ($textCols as $c) {
            $params[':' . $c] = nullify($row[$c] ?? null);
        }
        $oh = $row['on_hand'] ?? null;
        $params[':on_hand'] = ($oh === null || $oh === '') ? null : (float) $oh;
        // Default to active unless the source explicitly flags otherwise.
        $active = $row['is_active'] ?? 1;
        $params[':is_active'] = ((int) $active === 0) ? 0 : 1;

        $bad = false;
        foreach ($required as $c) {
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

/** Empty string -> null, else trimmed string. */
function nullify(mixed $v): ?string
{
    if ($v === null) {
        return null;
    }
    $s = trim((string) $v);
    return $s === '' ? null : $s;
}
