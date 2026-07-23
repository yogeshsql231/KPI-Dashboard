<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/source_db.php';

/**
 * Runs the ETL source queries (etl/queries/*.sql) read-only against a
 * configured source and reports success (columns + sample rows + timing) or
 * the exact driver error, so a failing pull (delivery, inventory, LPN, ...)
 * can be pinpointed to its query without running the full ETL.
 */
final class EtlQueryTester
{
    /** Utility/discovery files that are not ETL pull queries. */
    private const EXCLUDE_PATTERNS = ['discover', 'list_tables', 'qlik_prims_sap_diff', 'validation'];

    private function __construct()
    {
    }

    /**
     * List the testable query files, keyed by short name (file name without
     * extension), e.g. 'prodhana_delivery' => '/abs/path/prodhana_delivery.sql'.
     *
     * @return array<string, string>
     */
    public static function catalog(): array
    {
        $dir = dirname(__DIR__) . '/etl/queries';
        $out = [];
        foreach (glob($dir . '/*.sql') ?: [] as $file) {
            $name = basename($file, '.sql');
            foreach (self::EXCLUDE_PATTERNS as $p) {
                if (str_contains($name, $p)) {
                    continue 2;
                }
            }
            $out[$name] = $file;
        }
        ksort($out);
        return $out;
    }

    /**
     * Prepare the SQL from a query file the same way the pull scripts do:
     * strip full-line comments and optionally wrap in OPENQUERY for a linked
     * server (--via).
     */
    public static function buildSql(string $file, string $via = ''): string
    {
        $sql = trim((string) file_get_contents($file));
        if ($via === '') {
            return $sql;
        }
        if (!preg_match('/^[A-Za-z0-9_.\\\\-]+$/', $via)) {
            throw new InvalidArgumentException("Invalid linked-server name: $via");
        }
        $lines = preg_split('/\r?\n/', $sql) ?: [];
        $kept = array_filter($lines, static function (string $l): bool {
            $t = ltrim($l);
            return $t !== '' && !str_starts_with($t, '--');
        });
        $inner = rtrim(rtrim(implode("\n", $kept)), ';');
        $inner = str_replace("'", "''", $inner);
        return "SELECT * FROM OPENQUERY([$via], '$inner')";
    }

    /**
     * Execute one query file against a source and return a result summary.
     * Read-only: rows are only fetched (up to $sampleRows), never written.
     *
     * @return array{name:string,ok:bool,error:?string,seconds:float,columns:array<int,string>,rows_fetched:int,truncated:bool,sample:array<int,array<string,mixed>>}
     */
    public static function run(string $name, string $file, string $source, string $via = '', int $sampleRows = 3, int $maxFetch = 500): array
    {
        $result = [
            'name' => $name,
            'ok' => false,
            'error' => null,
            'seconds' => 0.0,
            'columns' => [],
            'rows_fetched' => 0,
            'truncated' => false,
            'sample' => [],
        ];
        $started = microtime(true);
        try {
            $sql = self::buildSql($file, $via);
            $pdo = SourceDb::connection($source);
            $stmt = $pdo->query($sql);
            while (($row = $stmt->fetch(PDO::FETCH_ASSOC)) !== false) {
                $row = array_change_key_case($row, CASE_LOWER);
                if ($result['columns'] === []) {
                    $result['columns'] = array_keys($row);
                }
                if ($result['rows_fetched'] < $sampleRows) {
                    $result['sample'][] = $row;
                }
                $result['rows_fetched']++;
                if ($result['rows_fetched'] >= $maxFetch) {
                    $result['truncated'] = true;
                    $stmt->closeCursor();
                    break;
                }
            }
            $result['ok'] = true;
        } catch (Throwable $e) {
            $result['error'] = $e->getMessage();
        }
        $result['seconds'] = round(microtime(true) - $started, 2);
        return $result;
    }
}
