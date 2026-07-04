<?php

declare(strict_types=1);

/**
 * Generic READ-ONLY query runner for a source system (PRIMSBM / PRODHANA).
 * Prints the result set as a simple table. Handy for inspecting what tables /
 * columns exist on a source before writing an ETL query.
 *
 *   php etl/query.php --source=PRIMSBM --query=etl/queries/list_tables_sqlsrv.sql
 *   php etl/query.php --source=PRIMSBM --sql="SELECT TOP 5 name FROM sys.tables"
 *   php etl/query.php --source=PRIMSBM --sql="SELECT COUNT(*) c FROM ORDR" --limit=50
 *
 * Only runs the SELECT you give it; it never writes anything.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/source_db.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

$opts   = getopt('', ['source:', 'query:', 'sql:', 'limit::']);
$source = strtoupper((string) ($opts['source'] ?? 'PRIMSBM'));
$limit  = isset($opts['limit']) ? (int) $opts['limit'] : 50;

if (isset($opts['sql']) && $opts['sql'] !== '') {
    $sql = (string) $opts['sql'];
} elseif (isset($opts['query']) && $opts['query'] !== '') {
    $queryFile = (string) $opts['query'];
    if (!is_readable($queryFile)) {
        fwrite(STDERR, "Query file not found: $queryFile\n");
        exit(1);
    }
    $sql = trim((string) file_get_contents($queryFile));
} else {
    fwrite(STDERR, "Provide --sql=\"...\" or --query=path.sql\n");
    exit(1);
}

echo "[query] source=$source\n";

try {
    $src  = SourceDb::connection($source);
    $stmt = $src->query($sql);
} catch (Throwable $e) {
    fwrite(STDERR, '[query] failed: ' . $e->getMessage() . "\n");
    exit(2);
}

$rows = 0;
$headerPrinted = false;
while (($row = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
    if (!$headerPrinted) {
        echo implode(' | ', array_keys($row)) . "\n";
        echo str_repeat('-', 60) . "\n";
        $headerPrinted = true;
    }
    echo implode(' | ', array_map(static fn($v): string => (string) $v, $row)) . "\n";
    if (++$rows >= $limit) {
        echo "... (stopped at --limit=$limit)\n";
        break;
    }
}

echo "[query] $rows row(s)\n";
exit(0);
