<?php

declare(strict_types=1);

/**
 * Application bootstrap / configuration loader.
 *
 * Loads variables from the project `.env` file (if present) into a small
 * in-process cache and exposes a typed `env()` helper. Values already present
 * in the real environment (e.g. set by Apache/PHP-FPM or the OS) take
 * precedence over the `.env` file, which is the safer production pattern.
 */

if (!function_exists('env')) {
    /**
     * Read a configuration value.
     *
     * @param string     $key     Variable name.
     * @param mixed|null $default Returned when the key is not defined.
     * @return mixed
     */
    function env(string $key, mixed $default = null): mixed
    {
        static $loaded = false;
        static $cache = [];

        if (!$loaded) {
            $loaded = true;
            $path = dirname(__DIR__) . '/.env';
            if (is_readable($path)) {
                $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                foreach ($lines as $line) {
                    $line = trim($line);
                    if ($line === '' || str_starts_with($line, '#')) {
                        continue;
                    }
                    if (!str_contains($line, '=')) {
                        continue;
                    }
                    [$name, $value] = explode('=', $line, 2);
                    $name = trim($name);
                    $value = trim($value);

                    // Strip a trailing inline comment that is not quoted.
                    if ($value !== '' && $value[0] !== '"' && $value[0] !== "'") {
                        $hash = strpos($value, ' #');
                        if ($hash !== false) {
                            $value = rtrim(substr($value, 0, $hash));
                        }
                    }

                    // Strip surrounding quotes.
                    if (strlen($value) >= 2) {
                        $first = $value[0];
                        $last = $value[strlen($value) - 1];
                        if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                            $value = substr($value, 1, -1);
                        }
                    }

                    $cache[$name] = $value;
                }
            }
        }

        // Real environment wins over the .env file.
        $fromEnv = getenv($key);
        if ($fromEnv !== false) {
            $value = $fromEnv;
        } elseif (array_key_exists($key, $cache)) {
            $value = $cache[$key];
        } else {
            return $default;
        }

        // Normalise common boolean / null literals.
        return match (strtolower((string) $value)) {
            'true'  => true,
            'false' => false,
            'null'  => null,
            ''      => $default,
            default => $value,
        };
    }
}

// Baseline runtime configuration.
date_default_timezone_set((string) env('APP_TIMEZONE', 'America/New_York'));

$appDebug = (bool) env('APP_DEBUG', false);
error_reporting(E_ALL);
// Never echo raw errors to the client in production; log them instead.
ini_set('display_errors', $appDebug ? '1' : '0');
ini_set('log_errors', '1');
