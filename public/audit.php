<?php

declare(strict_types=1);

/**
 * Audit dashboard (Damascus-KPI) — SCRUM-15.
 *
 * Surfaces operational alerts: out-of-range silo / batch readings, expired
 * batches, expired LPN pallets, stale ETL, and OTIF below target. Visiting the
 * page re-evaluates the alert rules against the current cache and refreshes the
 * alert_events audit log, then shows the open alerts. The email digest of the
 * same alerts is sent by etl/audit_alerts.php (from the XAMPP box).
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../src/Auth.php';
require_once __DIR__ . '/../src/AlertRepository.php';

Auth::requireLogin();
$canSeeFinancials = Auth::isCLevel();

/** HTML-escape helper. */
function e(mixed $v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}

/** Format an integer with thousands separators. */
function num(mixed $v): string
{
    if ($v === null || $v === '') {
        return '—';
    }
    return number_format((float) $v);
}

/** Map a severity to a pill class. */
function sevPill(string $sev): string
{
    return match ($sev) {
        'critical' => 'bad',
        'warning'  => 'warn',
        'info'     => 'info',
        default    => 'neutral',
    };
}

$error = null;
$summary = ['critical' => 0, 'warning' => 0, 'info' => 0, 'total' => 0];
$open = [];
$rules = [];
$flagged = [];
$hasReadings = false;
$recorded = ['new' => 0, 'updated' => 0, 'resolved' => 0];
$lastNotified = null;

try {
    $repo = new AlertRepository(Database::connection());
    // Re-evaluate on load so the page always reflects the current cache; this
    // also refreshes the audit log (alert_events).
    $recorded = $repo->record($repo->evaluate());
    $summary = $repo->openSummary();
    $open = $repo->openEvents();
    $rules = $repo->rules();
    $flagged = $repo->flaggedReadings();
    $hasReadings = $repo->hasReadings();
    $lastNotified = $repo->lastNotifiedAt();
} catch (Throwable $ex) {
    $error = 'Unable to run the audit. Apply sql/migrations/009_audit_alerts.sql and check your .env database connection.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta http-equiv="refresh" content="1800">
    <title>KPI Dashboard · Audit</title>
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
<header class="topbar">
    <div class="brand">KPI Dashboard</div>
    <div class="subtitle">Audit &amp; Alerts</div>
    <nav class="topnav">
        <a href="overview.php">Overview</a>
        <a href="dashboard.php">Delivery</a>
        <a href="warehouse.php">Warehouse</a>
        <a href="dashboard_cs.php">Customer Service</a>
        <a href="audit.php" class="active">Audit</a>
        <?php $authUser = Auth::user(); if ($authUser !== null): ?>
        <span class="user-chip">
            <span class="user-name"><?= e($authUser['name']) ?></span>
            <?php if ($canSeeFinancials): ?><span class="user-role">C-level</span><?php endif; ?>
            <a href="logout.php" class="user-logout">Sign out</a>
        </span>
        <?php endif; ?>
    </nav>
</header>

<main class="container">
    <?php if ($error !== null): ?>
        <div class="alert"><?= e($error) ?></div>
    <?php else: ?>

    <section class="cards">
        <div class="card <?= $summary['total'] > 0 ? 'warn' : 'good' ?>">
            <div class="card-label">Open Alerts</div>
            <div class="card-value"><?= num($summary['total']) ?></div>
            <div class="card-target"><?= $summary['total'] > 0 ? 'need attention' : 'all clear' ?></div>
        </div>
        <div class="card <?= $summary['critical'] > 0 ? 'bad' : 'neutral' ?>">
            <div class="card-label">Critical</div>
            <div class="card-value"><?= num($summary['critical']) ?></div>
            <div class="card-target">out-of-range / expired</div>
        </div>
        <div class="card <?= $summary['warning'] > 0 ? 'warn' : 'neutral' ?>">
            <div class="card-label">Warning</div>
            <div class="card-value"><?= num($summary['warning']) ?></div>
            <div class="card-target">below target / stale</div>
        </div>
        <div class="card neutral">
            <div class="card-label">Last Emailed</div>
            <div class="card-value" style="font-size:18px;"><?= $lastNotified ? e($lastNotified) : '—' ?></div>
            <div class="card-target">via etl/audit_alerts.php</div>
        </div>
    </section>

    <p class="audit-meta">
        Last check just now — <?= num($recorded['new']) ?> new, <?= num($recorded['updated']) ?> ongoing,
        <?= num($recorded['resolved']) ?> resolved. Alerts refresh on load; the email digest is sent from the
        XAMPP box with <code>php etl/audit_alerts.php</code>.
    </p>

    <section class="panel panel-wide">
        <h2>Open Alerts</h2>
        <table>
            <thead><tr><th>Severity</th><th>Alert</th><th>Category</th><th>Reference</th><th class="num">Seen</th><th>Since</th></tr></thead>
            <tbody>
            <?php foreach ($open as $a): ?>
                <tr class="sev-<?= e($a['severity']) ?>">
                    <td><span class="pill <?= sevPill((string) $a['severity']) ?>"><?= e(ucfirst((string) $a['severity'])) ?></span></td>
                    <td><?= e($a['message']) ?></td>
                    <td><?= e($a['category']) ?></td>
                    <td><?= $a['entity_ref'] ? e($a['entity_ref']) : '<span class="muted">—</span>' ?></td>
                    <td class="num"><?= num($a['occurrences']) ?></td>
                    <td><?= e($a['first_seen_at']) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if ($open === []): ?>
                <tr><td colspan="6" class="empty">No open alerts — all monitored readings are within range.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </section>

    <div class="grid">
        <section class="panel">
            <h2>Operational Readings &mdash; Flagged</h2>
            <p class="panel-note">Silo / batch-master readings out of range or past expiry. Source: SAP Beas / PRIMSBM via <code>operational_readings</code> (migration <code>009</code> + <code>etl/pull_readings.php</code>).</p>
            <?php if (!$hasReadings): ?>
                <p class="empty">No operational readings loaded yet. Confirm the source columns with <code>etl/queries/readings_discover_sqlsrv.sql</code>, then load with <code>php etl/pull_readings.php --source=PRIMSBM</code>.</p>
            <?php else: ?>
            <table>
                <thead><tr><th>Type</th><th>Location</th><th>Item / Batch</th><th class="num">Reading</th><th class="num">Min</th><th class="num">Max</th><th>Flag</th></tr></thead>
                <tbody>
                <?php foreach ($flagged as $r): ?>
                    <tr>
                        <td><?= e($r['std_type']) ?></td>
                        <td><?= e($r['std_location']) ?></td>
                        <td><?= e($r['item_code']) ?><?php if ($r['batch_number']): ?><span class="muted"> · <?= e($r['batch_number']) ?></span><?php endif; ?></td>
                        <td class="num"><?= $r['reading_value'] !== null ? e($r['reading_value']) . ' ' . e($r['unit_of_measure']) : '—' ?></td>
                        <td class="num"><?= $r['min_threshold'] !== null ? e($r['min_threshold']) : '—' ?></td>
                        <td class="num"><?= $r['max_threshold'] !== null ? e($r['max_threshold']) : '—' ?></td>
                        <td>
                            <?php if ((int) $r['out_of_range'] === 1): ?><span class="pill bad">Out of range</span><?php endif; ?>
                            <?php if ((int) $r['is_expired'] === 1): ?><span class="pill warn">Expired</span><?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($flagged === []): ?>
                    <tr><td colspan="7" class="empty">All readings within range.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
            <?php endif; ?>
        </section>

        <section class="panel">
            <h2>Alert Rules</h2>
            <p class="panel-note">The checks the audit runs. Enable/disable or tune thresholds in the <code>alert_rules</code> table.</p>
            <table>
                <thead><tr><th>Rule</th><th>Severity</th><th class="num">Threshold</th><th>Status</th></tr></thead>
                <tbody>
                <?php foreach ($rules as $key => $rule): ?>
                    <tr>
                        <td><?= e($rule['name']) ?><br><span class="muted"><?= e($rule['description']) ?></span></td>
                        <td><span class="pill <?= sevPill((string) $rule['severity']) ?>"><?= e(ucfirst((string) $rule['severity'])) ?></span></td>
                        <td class="num"><?= $rule['threshold_num'] !== null ? e($rule['threshold_num']) : '—' ?></td>
                        <td><?= (int) $rule['enabled'] === 1 ? '<span class="pill good">On</span>' : '<span class="pill neutral">Off</span>' ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($rules === []): ?>
                    <tr><td colspan="4" class="empty">No rules — apply migration 009.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </section>
    </div>

    <?php endif; ?>
</main>

<footer class="footer">
    KPI Dashboard · Audit &amp; Alerts · operational readings audited from the local cache
</footer>
</body>
</html>
