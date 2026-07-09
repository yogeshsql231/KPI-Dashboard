<?php

declare(strict_types=1);

/**
 * Audit + email-alert runner (SCRUM-15).
 *
 * Evaluates the alert rules over the local cache, records findings in the
 * alert_events audit log, and emails a digest of the open alerts. Intended to
 * run on the XAMPP box (on the company LAN, where Exchange is reachable) — as a
 * scheduled task, or on demand:
 *
 *   php etl/audit_alerts.php                # evaluate, record, email the digest
 *   php etl/audit_alerts.php --dry-run      # evaluate + print the digest, send nothing
 *   php etl/audit_alerts.php --no-mail      # evaluate + record only, don't email
 *   php etl/audit_alerts.php --to=ops@x.com # override recipient(s), comma-separated
 *   php etl/audit_alerts.php --only-new     # only email if there are NEW open alerts
 *
 * READ-ONLY on all source/KPI data; only the alert_events audit log is written.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../src/AlertRepository.php';
require_once __DIR__ . '/../src/Mailer.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

$opts     = getopt('', ['dry-run', 'no-mail', 'only-new', 'to:', 'quiet']);
$dryRun   = array_key_exists('dry-run', $opts);
$noMail   = array_key_exists('no-mail', $opts);
$onlyNew  = array_key_exists('only-new', $opts);
$quiet    = array_key_exists('quiet', $opts);

$say = static function (string $msg) use ($quiet): void {
    if (!$quiet) {
        echo $msg . "\n";
    }
};

// Recipients: --to overrides; else ALERT_EMAIL_TO; else the configured From.
$toRaw = (string) ($opts['to'] ?? env('ALERT_EMAIL_TO', '') ?? '');
$recipients = array_values(array_filter(array_map('trim', explode(',', $toRaw))));

try {
    $repo = new AlertRepository(Database::connection());
} catch (Throwable $e) {
    fwrite(STDERR, 'DB connection failed: ' . $e->getMessage() . "\n");
    exit(1);
}

$findings = $repo->evaluate();
$result   = $repo->record($findings);
$say(sprintf(
    'Audit: %d firing · %d new, %d updated, %d resolved.',
    count($findings),
    $result['new'],
    $result['updated'],
    $result['resolved']
));

$open = $repo->openEvents();
if ($open === []) {
    $say('No open alerts — nothing to send.');
    exit(0);
}
if ($onlyNew && $result['new'] === 0) {
    $say('No new alerts since last run — skipping email (--only-new).');
    exit(0);
}

$summary = $repo->openSummary();
$subject = sprintf(
    'KPI Dashboard alerts: %d open (%d critical, %d warning)',
    $summary['total'],
    $summary['critical'],
    $summary['warning']
);
$body = buildDigest($open, $summary);

if ($noMail) {
    $say('Recorded only (--no-mail). Open alerts:');
    $say($body);
    exit(0);
}

$mailer = new Mailer();
if (!$dryRun && !$mailer->isConfigured()) {
    fwrite(STDERR, "SMTP not configured. Set SMTP_HOST/SMTP_USER/SMTP_PASSWORD in .env, or use --dry-run / --no-mail.\n");
    exit(1);
}
if (!$dryRun && $recipients === []) {
    fwrite(STDERR, "No recipients. Set ALERT_EMAIL_TO in .env or pass --to=addr.\n");
    exit(1);
}

$res = $mailer->send($recipients, $subject, $body, $dryRun);

if ($res['dry_run']) {
    $say('--- DRY RUN (nothing sent) ---');
    $say('To: ' . ($recipients === [] ? '(unset)' : implode(', ', $recipients)));
    $say('Subject: ' . $subject);
    $say('');
    $say($res['message']);
    exit(0);
}

if (!$res['ok']) {
    fwrite(STDERR, 'Send failed: ' . (string) $res['error'] . "\n");
    exit(1);
}

// Mark the alerts we just notified so --only-new works next time.
$repo->markNotified(array_map(static fn (array $e): int => (int) $e['id'], $open));
$say('Digest emailed to ' . implode(', ', $recipients) . '.');
exit(0);

/**
 * Build the plain-text digest body from the open alerts.
 *
 * @param array<int, array<string, mixed>> $open
 * @param array{critical:int, warning:int, info:int, total:int} $summary
 */
function buildDigest(array $open, array $summary): string
{
    $lines = [];
    $lines[] = 'KPI Dashboard - operational audit alerts';
    $lines[] = str_repeat('=', 44);
    $lines[] = sprintf(
        'Open alerts: %d  (critical %d, warning %d, info %d)',
        $summary['total'],
        $summary['critical'],
        $summary['warning'],
        $summary['info']
    );
    $lines[] = 'Generated: ' . date('Y-m-d H:i');
    $lines[] = '';

    // Group by severity for readability.
    foreach (['critical', 'warning', 'info'] as $sev) {
        $group = array_filter($open, static fn (array $e): bool => (string) $e['severity'] === $sev);
        if ($group === []) {
            continue;
        }
        $lines[] = strtoupper($sev) . ' (' . count($group) . ')';
        $lines[] = str_repeat('-', 44);
        foreach ($group as $e) {
            $ref = $e['entity_ref'] !== null && $e['entity_ref'] !== '' ? ' [' . $e['entity_ref'] . ']' : '';
            $lines[] = sprintf('* %s%s', (string) $e['message'], $ref);
            $lines[] = sprintf(
                '    rule=%s  since=%s  seen x%d',
                (string) $e['rule_key'],
                (string) $e['first_seen_at'],
                (int) $e['occurrences']
            );
        }
        $lines[] = '';
    }

    $lines[] = 'This is an automated audit from the KPI Dashboard.';
    return implode("\n", $lines);
}
