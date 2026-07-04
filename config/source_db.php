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

        if ($host === '' || $name === '') {
            throw new RuntimeException("Source '$prefix' is not configured (missing host/name in .env).");
        }

        $dsn = self::buildDsn($driver, $host, $port, $name);

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ];
        // Emulated prepares are fine here: this connector only issues read
        // queries, and some drivers (dblib/odbc) don't support native prepares.
        if (in_array($driver, ['mysql'], true)) {
            $options[PDO::ATTR_EMULATE_PREPARES] = false;
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

    private static function buildDsn(string $driver, string $host, string $port, string $name): string
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
            'odbc' => sprintf('odbc:%s', $name), // $name = an ODBC DSN name
            'mysql' => sprintf(
                'mysql:host=%s%s;dbname=%s;charset=utf8mb4',
                $host,
                $port !== '' ? ';port=' . $port : '',
                $name
            ),
            default => throw new RuntimeException("Unsupported source driver '$driver'."),
        };
    }
}
