<?php

declare(strict_types=1);

/**
 * ETL: pull shipment lines from a source system (PRIMSBM / PRODHANA) and
 * upsert them into the local MySQL `order_shipments` table.
 *
 * Run on the local server (XAMPP box) where the source DBs are reachable:
 *
 *   php etl/pull_shipments.php --source=PRIMSBM
 *   php etl/pull_shipments.php --source=PRIMSBM --query=etl/queries/primsbm_shipments.sql
 *   php etl/pull_shipments.php --source=PRIMSBM --dry-run
 *
 * The source query must return these output columns (alias them in the .sql
 * file to match your real schema):
 *   source_key, ship_date, po_number, customer, ship_via, item_number,
 *   qty_requested, qty_shipped, order_date, requested_date, actual_date
 *
 * Idempotent: rows are upserted on (source_system, source_key), so re-running
 * updates existing rows instead of duplicating them.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/source_db.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

$opts = getopt('', ['source:', 'query:', 'dry-run', 'limit::']);
$source = strtoupper((string) ($opts['source'] ?? 'PRIMSBM'));
$dryRun = array_key_exists('dry-run', $opts);
$limit  = isset($opts['limit']) ? (int) $opts['limit'] : 0;

$queryFile = (string) ($opts['query'] ?? (__DIR__ . '/queries/' . strtolower($source) . '_shipments.sql'));
if (!is_readable($queryFile)) {
    fwrite(STDERR, "Query file not found: $queryFile\n");
    exit(1);
}
$sql = trim((string) file_get_contents($queryFile));
if ($sql === '' || str_starts_with(ltrim($sql), '--') && !preg_match('/select/i', $sql)) {
    fwrite(STDERR, "Query file appears empty / not filled in: $queryFile\n");
    exit(1);
}

$expected = [
    'source_key', 'ship_date', 'po_number', 'customer', 'ship_via',
    'item_number', 'qty_requested', 'qty_shipped', 'order_date',
    'requested_date', 'actual_date',
];

echo "[etl] source=$source  query=$queryFile" . ($dryRun ? '  (dry-run)' : '') . "\n";

try {
    $src = SourceDb::connection($source);
} catch (Throwable $e) {
    fwrite(STDERR, '[etl] ' . $e->getMessage() . "\n");
    exit(2);
}

$target = $dryRun ? null : Database::connection();

$upsertSql = 'INSERT INTO order_shipments
    (ship_date, po_number, customer, ship_via, item_number,
     qty_requested, qty_shipped, order_date, requested_date, actual_date,
     source_system, source_key)
   VALUES
    (:ship_date, :po_number, :customer, :ship_via, :item_number,
     :qty_requested, :qty_shipped, :order_date, :requested_date, :actual_date,
     :source_system, :source_key)
   ON DUPLICATE KEY UPDATE
     ship_date = VALUES(ship_date),
     po_number = VALUES(po_number),
     customer = VALUES(customer),
     ship_via = VALUES(ship_via),
     item_number = VALUES(item_number),
     qty_requested = VALUES(qty_requested),
     qty_shipped = VALUES(qty_shipped),
     order_date = VALUES(order_date),
     requested_date = VALUES(requested_date),
     actual_date = VALUES(actual_date)';

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

        $params = [
            ':ship_date'      => normDate($row['ship_date'] ?? null),
            ':po_number'      => strval($row['po_number'] ?? ''),
            ':customer'       => strval($row['customer'] ?? ''),
            ':ship_via'       => nullify($row['ship_via'] ?? null),
            ':item_number'    => strval($row['item_number'] ?? ''),
            ':qty_requested'  => (int) ($row['qty_requested'] ?? 0),
            ':qty_shipped'    => (int) ($row['qty_shipped'] ?? 0),
            ':order_date'     => normDate($row['order_date'] ?? null),
            ':requested_date' => normDate($row['requested_date'] ?? null),
            ':actual_date'    => normDate($row['actual_date'] ?? null),
            ':source_system'  => $source,
            ':source_key'     => $key,
        ];

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
