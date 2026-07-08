<?php

declare(strict_types=1);

/**
 * Login page. Authenticates against LDAP / Active Directory (see src/Auth.php)
 * and, on success, redirects back to the page the user was trying to reach.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../src/Auth.php';

function e(mixed $v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}

/** Only allow same-site relative return targets (avoid open redirects). */
function safeReturn(?string $r): string
{
    if ($r === null || $r === '' || $r[0] !== '/' && !str_ends_with($r, '.php')) {
        return 'overview.php';
    }
    if (str_starts_with($r, '//') || str_contains($r, '://')) {
        return 'overview.php';
    }
    return $r;
}

Auth::boot();
$return = safeReturn($_GET['return'] ?? $_POST['return'] ?? null);

if (!Auth::enabled() || Auth::check()) {
    header('Location: ' . $return);
    exit;
}

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = (string) ($_POST['username'] ?? '');
    $password = (string) ($_POST['password'] ?? '');
    if (Auth::attempt($username, $password)) {
        header('Location: ' . $return);
        exit;
    }
    $error = 'Sign-in failed. Check your username and password.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sign in · KPI Dashboard</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body class="login-body">
<main class="login-wrap">
    <form class="login-card" method="post" action="login.php?return=<?= e(rawurlencode($return)) ?>">
        <div class="login-brand">KPI Dashboard</div>
        <div class="login-sub">Sign in with your network account</div>
        <?php if ($error !== null): ?>
            <div class="alert login-alert"><?= e($error) ?></div>
        <?php endif; ?>
        <label class="login-field">
            <span>Username</span>
            <input type="text" name="username" autocomplete="username" autofocus required>
        </label>
        <label class="login-field">
            <span>Password</span>
            <input type="password" name="password" autocomplete="current-password" required>
        </label>
        <button type="submit" class="btn btn-primary login-btn">Sign in</button>
    </form>
</main>
</body>
</html>
