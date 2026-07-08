<?php

declare(strict_types=1);

/**
 * LDAP / Active Directory sign-in diagnostic.
 *
 * Runs the SAME steps as src/Auth.php's ldap driver, but prints exactly which
 * step fails so we can pin down a "Sign-in failed" error. Reads settings from
 * .env (AUTH_DRIVER is ignored here — this always exercises the LDAP path).
 *
 * Usage (from the project root, on the XAMPP box):
 *   C:\xampp\php\php.exe etl\ldap_check.php <username> <password>
 *
 * The password is only used to attempt a bind; it is never printed or stored.
 */

require_once __DIR__ . '/../config/config.php';

function line(string $s): void
{
    fwrite(STDOUT, $s . PHP_EOL);
}

$username = $argv[1] ?? '';
$password = $argv[2] ?? '';
if ($username === '' || $password === '') {
    line('Usage: php etl\\ldap_check.php <username> <password>');
    exit(2);
}

line('=== LDAP diagnostic ===');

// 1. Extension present?
if (!function_exists('ldap_connect')) {
    line('[FAIL] php-ldap extension is NOT loaded.');
    line('       Enable "extension=ldap" in php.ini and restart Apache/PHP.');
    line('       Check with:  C:\\xampp\\php\\php.exe -m | findstr ldap');
    exit(1);
}
line('[ok]   php-ldap extension loaded.');

// 2. Read config.
$url        = (string) env('LDAP_URL', '');
$startTls   = (bool) env('LDAP_START_TLS', false);
$reqcert    = strtolower((string) env('LDAP_TLS_REQCERT', 'never'));
$pattern    = (string) env('LDAP_BIND_PATTERN', '%s');
$baseDn     = (string) env('LDAP_BASE_DN', '');
$userFilter = (string) env('LDAP_USER_FILTER', '(sAMAccountName=%s)');
$clevel     = strtolower((string) env('LDAP_CLEVEL_GROUP', ''));

line('LDAP_URL           = ' . ($url !== '' ? $url : '(empty!)'));
line('LDAP_START_TLS     = ' . ($startTls ? 'true' : 'false'));
line('LDAP_TLS_REQCERT   = ' . $reqcert);
line('LDAP_BIND_PATTERN  = ' . $pattern);
line('LDAP_BASE_DN       = ' . ($baseDn !== '' ? $baseDn : '(empty)'));
line('LDAP_USER_FILTER   = ' . $userFilter);
line('LDAP_CLEVEL_GROUP  = ' . ($clevel !== '' ? $clevel : '(empty)'));

if ($url === '') {
    line('[FAIL] LDAP_URL is empty in .env.');
    exit(1);
}

// Relax TLS cert verification for ldaps:// / StartTLS against an on-prem DC
// with a self-signed cert. Must be set on the global handle before connect.
if (defined('LDAP_OPT_X_TLS_REQUIRE_CERT')) {
    $map = [
        'never'  => LDAP_OPT_X_TLS_NEVER,  'allow' => LDAP_OPT_X_TLS_ALLOW,
        'try'    => LDAP_OPT_X_TLS_TRY,     'demand' => LDAP_OPT_X_TLS_DEMAND,
        'hard'   => LDAP_OPT_X_TLS_HARD,
    ];
    if (isset($map[$reqcert])) {
        @ldap_set_option(null, LDAP_OPT_X_TLS_REQUIRE_CERT, $map[$reqcert]);
    }
}

// 3. Connect.
$conn = @ldap_connect($url);
if ($conn === false) {
    line('[FAIL] ldap_connect() returned false for ' . $url);
    exit(1);
}
ldap_set_option($conn, LDAP_OPT_PROTOCOL_VERSION, 3);
ldap_set_option($conn, LDAP_OPT_REFERRALS, 0);
ldap_set_option($conn, LDAP_OPT_NETWORK_TIMEOUT, 8);
line('[ok]   ldap_connect() succeeded (note: connect is lazy; bind is the real test).');

if ($startTls && !@ldap_start_tls($conn)) {
    line('[FAIL] ldap_start_tls() failed: ' . ldap_error($conn));
    exit(1);
}

// 4. Bind as the user.
$bindDn = str_contains($pattern, '%s') ? sprintf($pattern, $username) : $pattern;
line('Binding as        = ' . $bindDn);
if (!@ldap_bind($conn, $bindDn, $password)) {
    line('[FAIL] Bind failed: ' . ldap_error($conn));
    line('       -> Usually the bind pattern or the username/password is wrong.');
    line('       AD commonly wants  %s@yourdomain.com  or  DOMAIN\\%s .');
    exit(1);
}
line('[ok]   Bind succeeded — credentials + bind pattern are correct.');

// 5. Search for the user entry + group membership.
if ($baseDn === '') {
    line('[warn] LDAP_BASE_DN is empty; cannot look up groups (everyone = staff).');
    exit(0);
}
$filter = str_contains($userFilter, '%s')
    ? sprintf($userFilter, function_exists('ldap_escape') ? ldap_escape($username, '', LDAP_ESCAPE_FILTER) : $username)
    : $userFilter;
line('Search filter     = ' . $filter);
$search = @ldap_search($conn, $baseDn, $filter, ['displayname', 'cn', 'memberof']);
if ($search === false) {
    line('[FAIL] ldap_search() failed: ' . ldap_error($conn));
    line('       -> Check LDAP_BASE_DN is a valid search base.');
    exit(1);
}
$entries = ldap_get_entries($conn, $search);
$count = (int) ($entries['count'] ?? 0);
line('[ok]   Search returned ' . $count . ' entry(ies).');
if ($count === 0) {
    line('[warn] No entry matched — check LDAP_USER_FILTER / LDAP_BASE_DN.');
    exit(0);
}

$entry = $entries[0];
line('User DN           = ' . ($entry['dn'] ?? '(n/a)'));
line('displayName       = ' . ($entry['displayname'][0] ?? ($entry['cn'][0] ?? '(none)')));

$groups = $entry['memberof'] ?? ['count' => 0];
$gc = (int) ($groups['count'] ?? 0);
line('memberOf (' . $gc . '):');
$matched = false;
for ($i = 0; $i < $gc; $i++) {
    $dn = (string) $groups[$i];
    $hit = $clevel !== '' && str_contains(strtolower($dn), $clevel);
    if ($hit) {
        $matched = true;
    }
    line('   ' . ($hit ? '>> ' : '   ') . $dn);
}

line('');
if ($clevel === '') {
    line('[warn] LDAP_CLEVEL_GROUP is empty — nobody will get C-level.');
} elseif ($matched) {
    line('[ok]   RESULT: this user IS C-level (matched "' . $clevel . '").');
} else {
    line('[info] RESULT: this user is STAFF (no group contains "' . $clevel . '").');
    line('       If they should be C-level, set LDAP_CLEVEL_GROUP to one of the CNs above.');
}
