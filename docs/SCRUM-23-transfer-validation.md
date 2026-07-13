# SCRUM-23 — Transfer Record Validation

Transfer records need validation for incomplete or implausible data. Same two-layer
approach as SCRUM-22 (item-master validation), built on the audit engine (SCRUM-15).

## 1. Continuous checks on the dashboard (Audit page)

Migration `sql/migrations/016_transfer_validation.sql` adds four rules to
`alert_rules`; `AlertRepository` evaluates them on every audit run against the
cached transfer records (`material_movements` rows with `movement_type='transfer'`,
loaded by `etl/pull_inventory.php --what=movements`). All rules can be tuned or
disabled per-row in `alert_rules`.

| Rule | What it flags | Default |
|---|---|---|
| `transfer_missing_endpoint` | Transfers with no from- or to-warehouse | warning |
| `transfer_same_warehouse` | Transfers where from and to warehouse are identical | warning |
| `transfer_bad_quantity` | Transfers with a missing, zero or negative quantity | warning |
| `transfer_missing_item` | Transfers that don't identify the item moved | warning |

Each finding is a single de-duplicated alert with the record count and a sample of
source keys (`doc_type|doc_entry|line`). Checks skip gracefully when
`material_movements` doesn't exist yet (migration 010 not applied).

To enable: apply migration 016 on the box, then load the Audit page (the audit
runs on load) or run `php etl/audit_alerts.php`.

## 2. SAP-side validation (source of truth)

`etl/queries/prodhana_transfer_validation.sql` audits stock transfers (OWTR/WTR1)
at the source (READ-ONLY) and returns one row per finding:

- `SAME_WAREHOUSE` — line whose from and to warehouse are identical
- `BAD_QUANTITY` — line with a zero or negative quantity
- `UNKNOWN_ITEM` — line whose item code is not in OITM
- `OPEN_OVER_7_DAYS` — transfer document still open >7 days after DocDate

Run on the XAMPP box:

```
php etl/query.php --source=PRODHANA --query=etl/queries/prodhana_transfer_validation.sql --via=PRODHANA
```

Fix the flagged documents in SAP B1, then re-run
`etl/pull_inventory.php --what=movements` — the corresponding dashboard alerts
auto-resolve on the next audit run.
