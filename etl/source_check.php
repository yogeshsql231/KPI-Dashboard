<?php

declare(strict_types=1);

/**
 * Connectivity check for a configured source system (READ-ONLY).
 *
 * Connects with the .env credentials for the given source prefix, runs a
 * one-row probe query, and reports what it finds — without pulling any data.
 * Use it to verify the PRODHANA / PRIMSBM wiring before running the ETLs:
 *
 *   php etl/source_check.php --source=prodhana
 *   php etl/source_check.php --source=primsbm
 *
 * Exit code 0 = connected and probe query returned; non-zero otherwise.
 */

require_once __DIR__ . '/../config/source_db.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

$opts   = getopt('', ['source:']);
$source = strtoupper((string) ($opts['source'] ?? ''));
if ($source === '') {
    fwrite(STDERR, "Usage: php etl/source_check.php --source=<PREFIX>  (e.g. prodhana, primsbm)\n");
    exit(1);
}

$driver = strtolower((string) env($source . '_DB_DRIVER', 'sqlsrv'));
echo "[check] source=$source driver=$driver user=" . (string) env($source . '_DB_USER', '(empty)') . "\n";

$t0 = microtime(true);
try {
    $pdo = SourceDb::connection($source);
} catch (Throwable $e) {
    fwrite(STDERR, "[check] CONNECT FAILED: " . $e->getMessage() . "\n");
    fwrite(STDERR, "[check] Common causes: VPN not connected, wrong host/port, ODBC driver not installed, bad credentials.\n");
    exit(2);
}
printf("[check] connected in %.2fs\n", microtime(true) - $t0);

// HANA has no FROM-less SELECT; SQL Server / MySQL do.
$probe = $driver === 'odbc' ? 'SELECT 1 AS ok FROM DUMMY' : 'SELECT 1 AS ok';
try {
    $row = $pdo->query($probe)->fetch();
} catch (Throwable $e) {
    fwrite(STDERR, "[check] PROBE QUERY FAILED: " . $e->getMessage() . "\n");
    exit(3);
}
echo "[check] probe query OK (" . $probe . ")\n";

// For HANA, also confirm the SAP schema is visible to the (read-only) user.
if ($driver === 'odbc') {
    try {
        $cnt = $pdo->query('SELECT COUNT(*) AS c FROM "DAMASCUS_BAKERY"."OWHS"')->fetch();
        echo "[check] DAMASCUS_BAKERY schema visible — OWHS warehouses: " . ($cnt['c'] ?? $cnt['C'] ?? '?') . "\n";
    } catch (Throwable $e) {
        fwrite(STDERR, "[check] WARNING: connected, but DAMASCUS_BAKERY schema not readable: " . $e->getMessage() . "\n");
        fwrite(STDERR, "[check] Grant SELECT on the DAMASCUS_BAKERY schema to the read-only user.\n");
        exit(4);
    }
}

echo "[check] all good — source '$source' is reachable and readable.\n";
exit(0);
