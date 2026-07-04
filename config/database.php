<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';

/**
 * Secure PDO database access.
 *
 * Security choices:
 *  - Credentials are read from the environment / .env, never hard-coded.
 *  - ERRMODE_EXCEPTION so failures throw instead of silently returning false.
 *  - EMULATE_PREPARES = false so real server-side prepared statements are used
 *    (true parameter binding, strongest protection against SQL injection).
 *  - DEFAULT_FETCH_MODE = FETCH_ASSOC for predictable array results.
 *  - Connection is created lazily and reused (singleton) per request.
 */
final class Database
{
    private static ?PDO $connection = null;

    private function __construct()
    {
    }

    /**
     * Return a shared PDO connection to the primary KPI database.
     */
    public static function connection(): PDO
    {
        if (self::$connection instanceof PDO) {
            return self::$connection;
        }

        $host    = (string) env('DB_HOST', '127.0.0.1');
        $port    = (string) env('DB_PORT', '3306');
        $name    = (string) env('DB_NAME', 'kpi_dashboard');
        $charset = (string) env('DB_CHARSET', 'utf8mb4');
        $user    = (string) env('DB_USER', '');
        $pass    = (string) env('DB_PASS', '');

        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=%s',
            $host,
            $port,
            $name,
            $charset
        );

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_STRINGIFY_FETCHES  => false,
        ];

        try {
            self::$connection = new PDO($dsn, $user, $pass, $options);
        } catch (PDOException $e) {
            // Log the real reason, expose a generic message to callers.
            error_log('[Database] connection failed: ' . $e->getMessage());
            throw new RuntimeException('Database connection failed.', 0, $e);
        }

        return self::$connection;
    }
}
