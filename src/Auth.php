<?php

declare(strict_types=1);

/**
 * Authentication + role gate for the KPI dashboards.
 *
 * Damascus Bakery runs an on-prem LDAP / Active Directory, so the production
 * driver binds to that server to verify credentials and reads the user's
 * group membership to decide whether they are "C-level" (allowed to see the
 * sensitive financial panels) or ordinary "staff".
 *
 * Everything is configured through `.env` (see AUTH_* / LDAP_* keys) — no
 * credentials live in code. A `dev` driver is provided so the login / guard /
 * panel-hiding flow can be exercised locally without an LDAP server.
 */
final class Auth
{
    public const ROLE_CLEVEL = 'c_level';
    public const ROLE_STAFF  = 'staff';

    public static function boot(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }

    /** @return array{username:string,name:string,role:string}|null */
    public static function user(): ?array
    {
        self::boot();
        $u = $_SESSION['auth_user'] ?? null;
        return is_array($u) ? $u : null;
    }

    public static function check(): bool
    {
        return self::user() !== null;
    }

    public static function isCLevel(): bool
    {
        $u = self::user();
        return $u !== null && $u['role'] === self::ROLE_CLEVEL;
    }

    /**
     * Redirect to the login page unless the visitor is authenticated.
     * `$return` is round-tripped so we can bounce the user back afterwards.
     */
    public static function requireLogin(?string $return = null): void
    {
        if (self::check()) {
            return;
        }
        $target = 'login.php';
        $return ??= ($_SERVER['REQUEST_URI'] ?? '');
        if ($return !== '') {
            $target .= '?return=' . rawurlencode($return);
        }
        header('Location: ' . $target);
        exit;
    }

    /**
     * Verify credentials and, on success, store the user in the session.
     */
    public static function attempt(string $username, string $password): bool
    {
        $username = trim($username);
        if ($username === '' || $password === '') {
            return false;
        }

        $driver = strtolower((string) env('AUTH_DRIVER', 'ldap'));
        $result = $driver === 'dev'
            ? self::attemptDev($username, $password)
            : self::attemptLdap($username, $password);

        if ($result === null) {
            return false;
        }

        self::boot();
        session_regenerate_id(true);
        $_SESSION['auth_user'] = $result;
        return true;
    }

    public static function logout(): void
    {
        self::boot();
        $_SESSION = [];
        session_destroy();
    }

    /**
     * Local development driver: accepts any username whose password equals
     * DEV_PASSWORD, granting C-level to the comma-separated DEV_CLEVEL_USERS.
     * Never enable this in production (keep AUTH_DRIVER=ldap).
     *
     * @return array{username:string,name:string,role:string}|null
     */
    private static function attemptDev(string $username, string $password): ?array
    {
        $expected = (string) env('DEV_PASSWORD', '');
        if ($expected === '' || !hash_equals($expected, $password)) {
            return null;
        }
        $clevel = array_filter(array_map('trim', explode(',', (string) env('DEV_CLEVEL_USERS', ''))));
        $role = in_array(strtolower($username), array_map('strtolower', $clevel), true)
            ? self::ROLE_CLEVEL
            : self::ROLE_STAFF;
        return ['username' => $username, 'name' => $username, 'role' => $role];
    }

    /**
     * LDAP / Active Directory driver. Binds as the user (proving the password),
     * then reads their group membership to assign a role.
     *
     * @return array{username:string,name:string,role:string}|null
     */
    private static function attemptLdap(string $username, string $password): ?array
    {
        if (!function_exists('ldap_connect')) {
            error_log('Auth: php-ldap extension is not installed.');
            return null;
        }

        $url = (string) env('LDAP_URL', '');
        if ($url === '') {
            error_log('Auth: LDAP_URL is not configured.');
            return null;
        }

        $conn = @ldap_connect($url);
        if ($conn === false) {
            error_log('Auth: ldap_connect failed for ' . $url);
            return null;
        }
        ldap_set_option($conn, LDAP_OPT_PROTOCOL_VERSION, 3);
        ldap_set_option($conn, LDAP_OPT_REFERRALS, 0);
        if ((bool) env('LDAP_START_TLS', false)) {
            if (!@ldap_start_tls($conn)) {
                error_log('Auth: ldap_start_tls failed.');
                return null;
            }
        }

        // Bind AS the user to verify the password. The bind DN is derived from
        // a configurable pattern, e.g. "%s@corp.example" or "CORP\\%s" or
        // "uid=%s,ou=people,dc=example,dc=com".
        $pattern = (string) env('LDAP_BIND_PATTERN', '%s');
        $bindDn = str_contains($pattern, '%s')
            ? sprintf($pattern, self::escapeDnValue($username))
            : $pattern;

        if (!@ldap_bind($conn, $bindDn, $password)) {
            return null; // wrong username/password
        }

        $name = $username;
        $role = self::ROLE_STAFF;

        // Look up the user entry to read display name + group membership.
        $baseDn = (string) env('LDAP_BASE_DN', '');
        $userFilter = (string) env('LDAP_USER_FILTER', '(sAMAccountName=%s)');
        $clevelGroup = strtolower((string) env('LDAP_CLEVEL_GROUP', ''));

        if ($baseDn !== '') {
            $filter = str_contains($userFilter, '%s')
                ? sprintf($userFilter, self::escapeFilterValue($username))
                : $userFilter;
            $search = @ldap_search($conn, $baseDn, $filter, ['displayname', 'cn', 'memberof']);
            if ($search !== false) {
                $entries = ldap_get_entries($conn, $search);
                if (($entries['count'] ?? 0) > 0) {
                    $entry = $entries[0];
                    $name = $entry['displayname'][0] ?? ($entry['cn'][0] ?? $username);
                    if ($clevelGroup !== '' && self::inGroup($entry['memberof'] ?? [], $clevelGroup)) {
                        $role = self::ROLE_CLEVEL;
                    }
                }
            }
        }

        return ['username' => $username, 'name' => (string) $name, 'role' => $role];
    }

    /**
     * True when any of the user's group DNs matches the configured C-level
     * group. Matches on the whole DN or just the CN so admins can configure
     * either the group name ("KPI C-Level") or its full DN.
     *
     * @param array<string|int, mixed> $memberOf raw ldap_get_entries memberof
     */
    private static function inGroup(array $memberOf, string $needle): bool
    {
        $count = is_int($memberOf['count'] ?? null) ? $memberOf['count'] : 0;
        for ($i = 0; $i < $count; $i++) {
            $dn = strtolower((string) $memberOf[$i]);
            if ($dn === $needle) {
                return true;
            }
            // Compare against the CN component, e.g. "cn=kpi c-level,ou=..."
            if (str_starts_with($dn, 'cn=' . $needle . ',') || str_contains($dn, '=' . $needle . ',')) {
                return true;
            }
            if (str_contains($dn, $needle)) {
                return true;
            }
        }
        return false;
    }

    private static function escapeDnValue(string $v): string
    {
        return function_exists('ldap_escape') ? ldap_escape($v, '', LDAP_ESCAPE_DN) : $v;
    }

    private static function escapeFilterValue(string $v): string
    {
        return function_exists('ldap_escape') ? ldap_escape($v, '', LDAP_ESCAPE_FILTER) : $v;
    }
}
