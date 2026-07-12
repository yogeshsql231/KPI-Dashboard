<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';

/**
 * Read-only PDO connections to the upstream source systems (PRIMSBM, PRODHANA).
 *
 * These databases live on the company LAN, so this connector is meant to run
 * on the local server (XAMPP box), not from a cloud host. Credentials come
 * from the environment / .env — never hard-coded.
 *
 * Driver is selectable per source via <PREFIX>_DB_DRIVER:
 *   - sqlsrv : Microsoft SQL Server via Microsoft's pdo_sqlsrv (Windows/XAMPP)
 *   - dblib  : Microsoft SQL Server via FreeTDS pdo_dblib (Linux/macOS)
 *   - odbc   : any ODBC DSN (e.g. SAP HANA via its ODBC driver)
 *   - mysql  : plain MySQL (handy for local testing against a mock source)
 */
final class SourceDb
{
    /** @var array<string, PDO> */
    private static array $pool = [];

    private function __construct()
    {
    }

    /**
     * Get a read-only connection for a configured source prefix.
     *
     * @param string $prefix e.g. 'PRIMSBM' or 'PRODHANA'
     */
    public static function connection(string $prefix): PDO
    {
        $prefix = strtoupper($prefix);
        if (isset(self::$pool[$prefix])) {
            return self::$pool[$prefix];
        }

        $driver = strtolower((string) env($prefix . '_DB_DRIVER', 'sqlsrv'));
        $host   = (string) env($prefix . '_DB_HOST', '');
        $port   = (string) env($prefix . '_DB_PORT', '');
        $name   = (string) env($prefix . '_DB_NAME', '');
        $user   = (string) env($prefix . '_DB_USER', '');
        $pass   = (string) env($prefix . '_DB_PASS', '');

        // ODBC connects either through a named DSN (set <PREFIX>_DB_NAME to the
        // DSN name) or DSN-less via a full connection string in <PREFIX>_DB_DSN
        // (e.g. Driver={HDBODBC};ServerNode=192.168.100.3:30015). When _DB_DSN
        // is empty but _DB_HOST is set, a HANA-style DSN-less string is built
        // from _DB_HOST/_DB_PORT using the ODBC driver named in
        // <PREFIX>_DB_ODBC_DRIVER (default HDBODBC).
        if ($driver === 'odbc') {
            $dsnStr = (string) env($prefix . '_DB_DSN', '');
            if ($dsnStr === '' && $name === '' && $host === '') {
                throw new RuntimeException("Source '$prefix' is not configured (set {$prefix}_DB_DSN, {$prefix}_DB_NAME or {$prefix}_DB_HOST).");
            }
        } elseif ($host === '' || $name === '') {
            throw new RuntimeException("Source '$prefix' is not configured (missing host/name in .env).");
        }

        $dsn = self::buildDsn($driver, $host, $port, $name, $prefix);

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ];
        // Emulated prepares are fine here: this connector only issues read
        // queries, and some drivers (dblib/odbc) don't support native prepares.
        if (in_array($driver, ['mysql'], true)) {
            $options[PDO::ATTR_EMULATE_PREPARES] = false;
        }
        // pdo_sqlsrv prepares statements by default, which makes SQL Server ask
        // the remote provider for column metadata up front. For OPENQUERY into a
        // linked server (e.g. HANA) that metadata step fails with "An error
        // occurred while preparing the query". Direct query mode executes the
        // statement without a separate prepare and avoids that.
        if ($driver === 'sqlsrv' && defined('PDO::SQLSRV_ATTR_DIRECT_QUERY')) {
            $options[PDO::SQLSRV_ATTR_DIRECT_QUERY] = true;
        }

        try {
            $pdo = new PDO($dsn, $user, $pass, $options);
        } catch (PDOException $e) {
            error_log("[SourceDb:$prefix] connection failed: " . $e->getMessage());
            throw new RuntimeException("Source '$prefix' connection failed: " . $e->getMessage(), 0, $e);
        }

        self::$pool[$prefix] = $pdo;
        return $pdo;
    }

    private static function buildDsn(string $driver, string $host, string $port, string $name, string $prefix = ''): string
    {
        return match ($driver) {
            'sqlsrv' => sprintf(
                'sqlsrv:Server=%s%s;Database=%s;TrustServerCertificate=1',
                $host,
                $port !== '' ? ',' . $port : '',
                $name
            ),
            'dblib' => sprintf(
                'dblib:host=%s%s;dbname=%s;charset=UTF-8',
                $host,
                $port !== '' ? ':' . $port : '',
                $name
            ),
            'odbc' => 'odbc:' . self::odbcConnectString($host, $port, $name, $prefix),
            'mysql' => sprintf(
                'mysql:host=%s%s;dbname=%s;charset=utf8mb4',
                $host,
                $port !== '' ? ';port=' . $port : '',
                $name
            ),
            default => throw new RuntimeException("Unsupported source driver '$driver'."),
        };
    }

    /**
     * Resolve the ODBC connection target, in order of precedence:
     *   1. <PREFIX>_DB_DSN   — full DSN-less connection string, used verbatim
     *   2. <PREFIX>_DB_HOST  — DSN-less string built for the driver in
     *                          <PREFIX>_DB_ODBC_DRIVER (default HDBODBC for HANA)
     *   3. <PREFIX>_DB_NAME  — a named ODBC DSN configured in the OS
     */
    private static function odbcConnectString(string $host, string $port, string $name, string $prefix): string
    {
        $dsnStr = (string) env($prefix . '_DB_DSN', '');
        if ($dsnStr !== '') {
            return $dsnStr;
        }
        if ($host !== '') {
            $odbcDriver = (string) env($prefix . '_DB_ODBC_DRIVER', 'HDBODBC');
            return sprintf(
                'Driver={%s};ServerNode=%s:%s',
                $odbcDriver,
                $host,
                $port !== '' ? $port : '30015'
            );
        }
        return $name; // named ODBC DSN
    }
}
