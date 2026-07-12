<?php

declare(strict_types=1);

/**
 * Read-only connectivity check for a configured source system.
 *
 *   php etl/source_check.php --source=PRIMSBM
 */

require_once __DIR__ . '/../config/source_db.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

$opts = getopt('', ['source:']);
$source = strtoupper(trim((string) ($opts['source'] ?? '')));

if ($source === '') {
    fwrite(STDERR, "Usage: php etl/source_check.php --source=<PREFIX>\n");
    exit(1);
}

if ($source === 'PRODHANA') {
    fwrite(
        STDERR,
        "Direct PRODHANA connections are not supported. Check PRIMSBM, then "
        . "run the PRODHANA query with --via=PRODHANA.\n"
    );
    exit(1);
}

$driver = strtolower((string) env($source . '_DB_DRIVER', 'sqlsrv'));
$probe = $driver === 'odbc' ? 'SELECT 1 AS ok FROM DUMMY' : 'SELECT 1 AS ok';
$startedAt = microtime(true);

echo "[check] source=$source driver=$driver\n";

try {
    $pdo = SourceDb::connection($source);
    $row = $pdo->query($probe)->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    fwrite(STDERR, "[check] failed: " . $e->getMessage() . "\n");
    exit(2);
}

if ($row === false) {
    fwrite(STDERR, "[check] failed: probe query returned no row\n");
    exit(3);
}

printf("[check] reachable in %.2fs\n", microtime(true) - $startedAt);
exit(0);
