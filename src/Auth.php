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

    /**
     * Department views and the page that fronts each one. The Overview is the
     * executive (C-level) view; the rest map to their teams. Departments are
     * granted through LDAP groups (LDAP_GROUP_<DEPT>) or, with the dev driver,
     * comma-separated user lists (DEV_<DEPT>_USERS). C-level always sees all.
     */
    public const DEPARTMENTS = [
        'overview'         => ['page' => 'overview.php',     'label' => 'Overview'],
        'delivery'         => ['page' => 'dashboard.php',    'label' => 'Delivery'],
        'warehouse'        => ['page' => 'warehouse.php',    'label' => 'Warehouse'],
        'customer_service' => ['page' => 'dashboard_cs.php', 'label' => 'Customer Service'],
        'audit'            => ['page' => 'audit.php',        'label' => 'Audit'],
    ];

    public static function boot(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }

    /**
     * Master switch. When AUTH_ENABLED=false the whole login layer is bypassed:
     * pages don't require sign-in and everyone sees all panels (the pre-auth
     * behaviour). Flip it back to true to re-enable LDAP/dev sign-in.
     */
    public static function enabled(): bool
    {
        return env('AUTH_ENABLED', true) !== false;
    }

    /** @return array{username:string,name:string,role:string}|null */
    public static function user(): ?array
    {
        if (!self::enabled()) {
            return null;
        }
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
        if (!self::enabled()) {
            return true; // login disabled -> full visibility (pre-auth behaviour)
        }
        $u = self::user();
        return $u !== null && $u['role'] === self::ROLE_CLEVEL;
    }

    /**
     * Department segregation is opt-in: it only kicks in once at least one
     * department mapping is configured. Until then every signed-in user keeps
     * full access, so existing installs are unaffected.
     */
    public static function rbacEnabled(): bool
    {
        if (!self::enabled()) {
            return false;
        }
        foreach (array_keys(self::DEPARTMENTS) as $dept) {
            if ($dept === 'overview') {
                continue; // overview is implicitly C-level
            }
            if ((string) env('LDAP_GROUP_' . strtoupper($dept), '') !== ''
                || (string) env('DEV_' . strtoupper($dept) . '_USERS', '') !== '') {
                return true;
            }
        }
        return false;
    }

    /** Can the current user open the given department view? */
    public static function canAccess(string $dept): bool
    {
        if (!self::enabled() || self::isCLevel() || !self::rbacEnabled()) {
            return true;
        }
        $u = self::user();
        if ($u === null) {
            return false;
        }
        if ($dept === 'overview') {
            return false; // executive view: C-level only once RBAC is on
        }
        if ($dept === 'audit'
            && (string) env('LDAP_GROUP_AUDIT', '') === ''
            && (string) env('DEV_AUDIT_USERS', '') === '') {
            return true; // operational alerts stay open unless explicitly mapped
        }
        return in_array($dept, $u['departments'] ?? [], true);
    }

    /**
     * Server-side gate for a department page: requires sign-in, then renders
     * a 403 page (never a redirect loop) when the user's departments don't
     * include this view — so direct URLs can't bypass the segregation.
     */
    public static function requireDepartment(string $dept): void
    {
        self::requireLogin();
        if (self::canAccess($dept)) {
            return;
        }
        http_response_code(403);
        $label = self::DEPARTMENTS[$dept]['label'] ?? $dept;
        $links = '';
        foreach (self::allowedPages() as $info) {
            $links .= '<a href="' . htmlspecialchars($info['page'], ENT_QUOTES) . '">'
                . htmlspecialchars($info['label'], ENT_QUOTES) . '</a> ';
        }
        echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">'
            . '<title>Access restricted · KPI Dashboard</title>'
            . '<link rel="stylesheet" href="assets/style.css"></head>'
            . '<body class="login-body"><main class="login-wrap">'
            . '<div class="login-card"><div class="login-brand">KPI Dashboard</div>'
            . '<p class="login-error">Your account does not have access to the '
            . htmlspecialchars($label, ENT_QUOTES) . ' view.</p>'
            . '<p>Available to you: ' . ($links !== '' ? $links : 'none') . '</p>'
            . '<p><a href="logout.php">Sign out</a></p>'
            . '</div></main></body></html>';
        exit;
    }

    /**
     * Department views the current user may open — drives the top nav.
     *
     * @return array<string, array{page:string,label:string}>
     */
    public static function allowedPages(): array
    {
        $out = [];
        foreach (self::DEPARTMENTS as $dept => $info) {
            if (self::canAccess($dept)) {
                $out[$dept] = $info;
            }
        }
        return $out;
    }

    /** First page the user is allowed to see — the post-login landing page. */
    public static function landingPage(): string
    {
        $pages = self::allowedPages();
        $first = reset($pages);
        return $first !== false ? $first['page'] : 'login.php';
    }

    /** Is the given page path one the current user may open? */
    public static function canOpenPage(string $page): bool
    {
        $file = basename(parse_url($page, PHP_URL_PATH) ?: $page);
        foreach (self::DEPARTMENTS as $dept => $info) {
            if ($info['page'] === $file) {
                return self::canAccess($dept);
            }
        }
        return true; // non-department pages (login/logout) are unrestricted
    }

    /**
     * Redirect to the login page unless the visitor is authenticated.
     * `$return` is round-tripped so we can bounce the user back afterwards.
     */
    public static function requireLogin(?string $return = null): void
    {
        if (!self::enabled() || self::check()) {
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
        $inList = static function (string $envKey) use ($username): bool {
            $users = array_filter(array_map('trim', explode(',', (string) env($envKey, ''))));
            return in_array(strtolower($username), array_map('strtolower', $users), true);
        };
        $role = $inList('DEV_CLEVEL_USERS') ? self::ROLE_CLEVEL : self::ROLE_STAFF;
        $departments = [];
        foreach (array_keys(self::DEPARTMENTS) as $dept) {
            if ($dept !== 'overview' && $inList('DEV_' . strtoupper($dept) . '_USERS')) {
                $departments[] = $dept;
            }
        }
        return ['username' => $username, 'name' => $username, 'role' => $role, 'departments' => $departments];
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
        $departments = [];

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
                    $memberOf = $entry['memberof'] ?? [];
                    if ($clevelGroup !== '' && self::inGroup($memberOf, $clevelGroup)) {
                        $role = self::ROLE_CLEVEL;
                    }
                    foreach (array_keys(self::DEPARTMENTS) as $dept) {
                        if ($dept === 'overview') {
                            continue;
                        }
                        $group = strtolower((string) env('LDAP_GROUP_' . strtoupper($dept), ''));
                        if ($group !== '' && self::inGroup($memberOf, $group)) {
                            $departments[] = $dept;
                        }
                    }
                }
            }
        }

        return ['username' => $username, 'name' => (string) $name, 'role' => $role, 'departments' => $departments];
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
