<?php

declare(strict_types=1);

/**
 * Audit + alert engine (SCRUM-15).
 *
 * Evaluates a catalogue of data-safe checks (`alert_rules`) over the local
 * cache and records findings in `alert_events` (a de-duplicated audit log).
 * The Audit page and the email digest (etl/audit_alerts.php) both read from
 * here.
 *
 * Design notes:
 *  - READ-ONLY on every source; only alert_events is written (the audit trail).
 *  - Each check is gated on the relevant table/view existing, so a box that
 *    has not yet applied a migration (e.g. operational_readings, lpn_pallets)
 *    simply skips that check instead of erroring — same graceful-degradation
 *    principle as the rest of the dashboard.
 *  - A finding is de-duplicated by `rule_key|entity_ref`, so re-running the
 *    audit updates the existing open alert (bumps last_seen_at / occurrences)
 *    rather than creating duplicates; conditions that clear are auto-resolved.
 */
final class AlertRepository
{
    /** @var array<string, bool> memoised table/view existence checks. */
    private array $existsCache = [];

    public function __construct(private PDO $pdo)
    {
    }

    // -----------------------------------------------------------------------
    // Rule catalogue
    // -----------------------------------------------------------------------

    /**
     * The alert-rule catalogue keyed by rule_key.
     *
     * @return array<string, array<string, mixed>>
     */
    public function rules(): array
    {
        if (!$this->tableExists('alert_rules')) {
            return [];
        }
        $out = [];
        $rows = $this->pdo->query(
            'SELECT rule_key, name, category, severity, enabled, threshold_num, description
             FROM alert_rules'
        )->fetchAll();
        foreach ($rows as $r) {
            $out[(string) $r['rule_key']] = $r;
        }
        return $out;
    }

    // -----------------------------------------------------------------------
    // Evaluation
    // -----------------------------------------------------------------------

    /**
     * Run every enabled check and return the findings (not yet persisted).
     *
     * @return array<int, array<string, mixed>> each finding has:
     *   rule_key, severity, category, entity_type, entity_ref, message,
     *   metric_value (nullable), dedupe_key.
     */
    public function evaluate(): array
    {
        $rules = $this->rules();
        // If the catalogue is missing (migration 009 not applied) fall back to
        // treating all built-in checks as enabled so the engine still works.
        $enabled = static function (string $key) use ($rules): bool {
            return !isset($rules[$key]) || (int) $rules[$key]['enabled'] === 1;
        };
        $threshold = static function (string $key, float $default) use ($rules): float {
            $v = $rules[$key]['threshold_num'] ?? null;
            return $v === null ? $default : (float) $v;
        };
        $severity = static function (string $key, string $default) use ($rules): string {
            return isset($rules[$key]) ? (string) $rules[$key]['severity'] : $default;
        };

        $findings = [];

        if ($enabled('reading_out_of_range')) {
            $findings = array_merge($findings, $this->checkReadingsOutOfRange($severity('reading_out_of_range', 'critical')));
        }
        if ($enabled('reading_expired')) {
            $findings = array_merge($findings, $this->checkReadingsExpired($severity('reading_expired', 'critical')));
        }
        if ($enabled('reading_stale')) {
            $findings = array_merge($findings, $this->checkReadingsStale($threshold('reading_stale', 24), $severity('reading_stale', 'warning')));
        }
        if ($enabled('lpn_expired')) {
            $findings = array_merge($findings, $this->checkLpnExpired($severity('lpn_expired', 'warning')));
        }
        if ($enabled('delivery_etl_stale')) {
            $findings = array_merge($findings, $this->checkDeliveryEtlStale($threshold('delivery_etl_stale', 48), $severity('delivery_etl_stale', 'warning')));
        }
        if ($enabled('otif_below_target')) {
            $findings = array_merge($findings, $this->checkOtifBelowTarget($severity('otif_below_target', 'warning')));
        }

        return $findings;
    }

    /** @return array<int, array<string, mixed>> */
    private function checkReadingsOutOfRange(string $severity): array
    {
        if (!$this->tableExists('vw_operational_readings')) {
            return [];
        }
        $rows = $this->pdo->query(
            "SELECT std_type, std_location, location_code, reading_value, unit_of_measure,
                    min_threshold, max_threshold, item_code, batch_number
             FROM vw_operational_readings
             WHERE out_of_range = 1
             ORDER BY std_type, std_location"
        )->fetchAll();

        $out = [];
        foreach ($rows as $r) {
            $ref = trim((string) $r['std_type'] . ' · ' . $r['std_location']);
            $bound = ($r['min_threshold'] !== null && $r['reading_value'] < $r['min_threshold'])
                ? 'below min ' . $this->trimNum($r['min_threshold'])
                : 'above max ' . $this->trimNum($r['max_threshold']);
            $unit = $r['unit_of_measure'] ? ' ' . $r['unit_of_measure'] : '';
            $msg = sprintf(
                '%s reading %s%s is %s%s.',
                ucfirst((string) $r['std_type']),
                $this->trimNum($r['reading_value']),
                $unit,
                $bound,
                $unit
            );
            $out[] = $this->finding('reading_out_of_range', $severity, 'operational', 'reading', $ref, $msg, (float) $r['reading_value']);
        }
        return $out;
    }

    /** @return array<int, array<string, mixed>> */
    private function checkReadingsExpired(string $severity): array
    {
        if (!$this->tableExists('vw_operational_readings')) {
            return [];
        }
        $rows = $this->pdo->query(
            "SELECT std_type, std_location, item_code, batch_number, expiry_date
             FROM vw_operational_readings
             WHERE is_expired = 1
             ORDER BY expiry_date"
        )->fetchAll();

        $out = [];
        foreach ($rows as $r) {
            $ref = trim((string) $r['std_location'] . ($r['batch_number'] ? ' · batch ' . $r['batch_number'] : ''));
            $msg = sprintf(
                'Batch %s at %s expired on %s.',
                $r['batch_number'] !== null && $r['batch_number'] !== '' ? (string) $r['batch_number'] : ((string) $r['item_code'] ?: 'n/a'),
                (string) $r['std_location'],
                (string) $r['expiry_date']
            );
            $out[] = $this->finding('reading_expired', $severity, 'operational', 'reading', $ref, $msg, null);
        }
        return $out;
    }

    /** @return array<int, array<string, mixed>> */
    private function checkReadingsStale(float $hours, string $severity): array
    {
        if (!$this->tableExists('operational_readings')) {
            return [];
        }
        // Only alert if there is at least one reading on record at all.
        $count = (int) $this->pdo->query('SELECT COUNT(*) FROM operational_readings')->fetchColumn();
        if ($count === 0) {
            return [];
        }
        $latest = $this->pdo->query('SELECT MAX(COALESCE(reading_at, refreshed_at)) FROM operational_readings')->fetchColumn();
        if (!$latest) {
            return [];
        }
        $ageHours = (time() - strtotime((string) $latest)) / 3600;
        if ($ageHours <= $hours) {
            return [];
        }
        $msg = sprintf(
            'No operational reading in %.1f h (threshold %s h). Latest: %s.',
            $ageHours,
            $this->trimNum($hours),
            (string) $latest
        );
        return [$this->finding('reading_stale', $severity, 'operational', 'etl', 'operational_readings', $msg, round($ageHours, 1))];
    }

    /** @return array<int, array<string, mixed>> */
    private function checkLpnExpired(string $severity): array
    {
        if (!$this->tableExists('vw_lpn_pallets')) {
            return [];
        }
        $expired = (int) $this->pdo->query('SELECT COALESCE(SUM(is_expired), 0) FROM vw_lpn_pallets')->fetchColumn();
        if ($expired <= 0) {
            return [];
        }
        $msg = sprintf('%d LPN pallet%s past expiry date on hand.', $expired, $expired === 1 ? '' : 's');
        return [$this->finding('lpn_expired', $severity, 'warehouse', 'pallet', 'lpn_expired', $msg, $expired)];
    }

    /** @return array<int, array<string, mixed>> */
    private function checkDeliveryEtlStale(float $hours, string $severity): array
    {
        if (!$this->tableExists('delivery_lines')) {
            return [];
        }
        $count = (int) $this->pdo->query('SELECT COUNT(*) FROM delivery_lines')->fetchColumn();
        if ($count === 0) {
            return [];
        }
        $latest = $this->pdo->query('SELECT MAX(refreshed_at) FROM delivery_lines')->fetchColumn();
        if (!$latest) {
            return [];
        }
        $ageHours = (time() - strtotime((string) $latest)) / 3600;
        if ($ageHours <= $hours) {
            return [];
        }
        $msg = sprintf(
            'Delivery cache last refreshed %.1f h ago (threshold %s h). Re-run etl/pull_delivery.php.',
            $ageHours,
            $this->trimNum($hours)
        );
        return [$this->finding('delivery_etl_stale', $severity, 'data', 'etl', 'delivery_lines', $msg, round($ageHours, 1))];
    }

    /** @return array<int, array<string, mixed>> */
    private function checkOtifBelowTarget(string $severity): array
    {
        if (!$this->tableExists('vw_delivery_lines') || !$this->tableExists('kpi_targets')) {
            return [];
        }
        $target = $this->pdo->query("SELECT target_value FROM kpi_targets WHERE metric_key = 'otif'")->fetchColumn();
        if ($target === false || $target === null) {
            return [];
        }
        $target = (float) $target;

        // Trailing 30-day OTIF over the cached delivery lines.
        $row = $this->pdo->query(
            "SELECT AVG(otif_flag) AS otif_rate, COUNT(*) AS n
             FROM vw_delivery_lines
             WHERE posting_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)"
        )->fetch();
        if (!$row || (int) $row['n'] === 0 || $row['otif_rate'] === null) {
            return [];
        }
        $otif = (float) $row['otif_rate'];
        if ($otif >= $target) {
            return [];
        }
        $msg = sprintf(
            'Trailing-30-day OTIF %.1f%% is below the %.1f%% target (%d lines).',
            $otif * 100,
            $target * 100,
            (int) $row['n']
        );
        return [$this->finding('otif_below_target', $severity, 'delivery', 'kpi', 'otif_30d', $msg, round($otif, 4))];
    }

    // -----------------------------------------------------------------------
    // Persistence (audit log)
    // -----------------------------------------------------------------------

    /**
     * Upsert findings into alert_events (de-duped by dedupe_key), then resolve
     * any previously-open event that is no longer firing.
     *
     * @param array<int, array<string, mixed>> $findings
     * @return array{new:int, updated:int, resolved:int}
     */
    public function record(array $findings): array
    {
        if (!$this->tableExists('alert_events')) {
            return ['new' => 0, 'updated' => 0, 'resolved' => 0];
        }

        $new = 0;
        $updated = 0;
        $seen = [];

        $ins = $this->pdo->prepare(
            "INSERT INTO alert_events
                (rule_key, severity, category, entity_type, entity_ref, message, metric_value, dedupe_key, status, occurrences, first_seen_at, last_seen_at)
             VALUES (:rule_key, :severity, :category, :entity_type, :entity_ref, :message, :metric_value, :dedupe_key, 'open', 1, NOW(), NOW())
             ON DUPLICATE KEY UPDATE
                severity = VALUES(severity),
                category = VALUES(category),
                entity_type = VALUES(entity_type),
                message = VALUES(message),
                metric_value = VALUES(metric_value),
                occurrences = occurrences + 1,
                last_seen_at = NOW(),
                status = 'open',
                resolved_at = NULL"
        );

        foreach ($findings as $f) {
            $seen[] = $f['dedupe_key'];
            $ins->execute([
                ':rule_key'     => $f['rule_key'],
                ':severity'     => $f['severity'],
                ':category'     => $f['category'],
                ':entity_type'  => $f['entity_type'],
                ':entity_ref'   => $f['entity_ref'],
                ':message'      => $f['message'],
                ':metric_value' => $f['metric_value'],
                ':dedupe_key'   => $f['dedupe_key'],
            ]);
            // rowCount(): 1 = insert, 2 = update (MySQL convention).
            if ($ins->rowCount() === 1) {
                $new++;
            } else {
                $updated++;
            }
        }

        $resolved = $this->resolveMissing($seen);

        return ['new' => $new, 'updated' => $updated, 'resolved' => $resolved];
    }

    /**
     * Resolve open events whose dedupe_key is not in the currently-firing set.
     *
     * @param array<int, string> $currentKeys
     */
    private function resolveMissing(array $currentKeys): int
    {
        if (!$this->tableExists('alert_events')) {
            return 0;
        }
        if ($currentKeys === []) {
            $stmt = $this->pdo->query("UPDATE alert_events SET status = 'resolved', resolved_at = NOW() WHERE status = 'open'");
            return $stmt->rowCount();
        }
        $placeholders = implode(',', array_fill(0, count($currentKeys), '?'));
        $stmt = $this->pdo->prepare(
            "UPDATE alert_events SET status = 'resolved', resolved_at = NOW()
             WHERE status = 'open' AND dedupe_key NOT IN ($placeholders)"
        );
        $stmt->execute($currentKeys);
        return $stmt->rowCount();
    }

    /** Mark the given event ids as notified (called after a digest is sent). */
    public function markNotified(array $ids): void
    {
        if ($ids === [] || !$this->tableExists('alert_events')) {
            return;
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo->prepare(
            "UPDATE alert_events SET notified_at = NOW() WHERE id IN ($placeholders)"
        );
        $stmt->execute(array_map('intval', $ids));
    }

    // -----------------------------------------------------------------------
    // Read helpers for the UI / digest
    // -----------------------------------------------------------------------

    /**
     * Open alerts, most severe + most recent first.
     *
     * @return array<int, array<string, mixed>>
     */
    public function openEvents(int $limit = 200): array
    {
        if (!$this->tableExists('alert_events')) {
            return [];
        }
        $stmt = $this->pdo->prepare(
            "SELECT id, rule_key, severity, category, entity_type, entity_ref, message,
                    metric_value, occurrences, first_seen_at, last_seen_at, notified_at
             FROM alert_events
             WHERE status = 'open'
             ORDER BY FIELD(severity, 'critical', 'warning', 'info'), last_seen_at DESC
             LIMIT " . (int) $limit
        );
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Count of open alerts by severity (for the summary cards).
     *
     * @return array{critical:int, warning:int, info:int, total:int}
     */
    public function openSummary(): array
    {
        $out = ['critical' => 0, 'warning' => 0, 'info' => 0, 'total' => 0];
        if (!$this->tableExists('alert_events')) {
            return $out;
        }
        $rows = $this->pdo->query(
            "SELECT severity, COUNT(*) AS n FROM alert_events WHERE status = 'open' GROUP BY severity"
        )->fetchAll();
        foreach ($rows as $r) {
            $sev = (string) $r['severity'];
            if (isset($out[$sev])) {
                $out[$sev] = (int) $r['n'];
            }
            $out['total'] += (int) $r['n'];
        }
        return $out;
    }

    /** True once the ETL has loaded at least one operational reading. */
    public function hasReadings(): bool
    {
        if (!$this->tableExists('operational_readings')) {
            return false;
        }
        return (int) $this->pdo->query('SELECT COUNT(*) FROM operational_readings')->fetchColumn() > 0;
    }

    /**
     * Operational readings currently out of range or expired, for the Audit
     * page readings panel.
     *
     * @return array<int, array<string, mixed>>
     */
    public function flaggedReadings(int $limit = 100): array
    {
        if (!$this->tableExists('vw_operational_readings')) {
            return [];
        }
        $stmt = $this->pdo->prepare(
            "SELECT std_type, std_location, item_code, batch_number, reading_value,
                    unit_of_measure, min_threshold, max_threshold, out_of_range,
                    is_expired, expiry_date, reading_at
             FROM vw_operational_readings
             WHERE out_of_range = 1 OR is_expired = 1
             ORDER BY (out_of_range + is_expired) DESC, std_type, std_location
             LIMIT " . (int) $limit
        );
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /** Timestamp of the most recent notified digest, or null. */
    public function lastNotifiedAt(): ?string
    {
        if (!$this->tableExists('alert_events')) {
            return null;
        }
        $v = $this->pdo->query('SELECT MAX(notified_at) FROM alert_events')->fetchColumn();
        return $v ?: null;
    }

    // -----------------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function finding(
        string $ruleKey,
        string $severity,
        string $category,
        string $entityType,
        string $entityRef,
        string $message,
        ?float $metricValue
    ): array {
        return [
            'rule_key'     => $ruleKey,
            'severity'     => $severity,
            'category'     => $category,
            'entity_type'  => $entityType,
            'entity_ref'   => $entityRef,
            'message'      => $message,
            'metric_value' => $metricValue,
            'dedupe_key'   => substr($ruleKey . '|' . $entityRef, 0, 200),
        ];
    }

    /** Format a numeric threshold/value without trailing zeros. */
    private function trimNum(mixed $v): string
    {
        if ($v === null || $v === '') {
            return 'n/a';
        }
        $f = (float) $v;
        $s = rtrim(rtrim(number_format($f, 4, '.', ''), '0'), '.');
        return $s === '' ? '0' : $s;
    }

    /** Whether a base table or view exists in the current schema (memoised). */
    private function tableExists(string $name): bool
    {
        if (array_key_exists($name, $this->existsCache)) {
            return $this->existsCache[$name];
        }
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = ?'
        );
        $stmt->execute([$name]);
        return $this->existsCache[$name] = ((int) $stmt->fetchColumn() > 0);
    }
}
