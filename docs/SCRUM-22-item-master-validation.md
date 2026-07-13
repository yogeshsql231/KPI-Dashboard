# SCRUM-22 — Item Master Data Validation

Item master data needs validation for outdated / incomplete records. This delivers
two layers, mirroring the existing audit-engine pattern (SCRUM-15):

## 1. Continuous checks on the dashboard (Audit page)

Migration `sql/migrations/015_item_master_validation.sql` adds four rules to the
audit engine (`alert_rules`); `AlertRepository` evaluates them on every audit run,
so findings appear as open alerts on the Audit page and in the email digest.
All rules can be tuned or disabled per-row in `alert_rules`.

| Rule | What it flags | Default |
|---|---|---|
| `item_missing_description` | Stocked items whose item master has no description | warning |
| `item_unclassified` | Stocked items with no product type / category (shown as "Unassigned" on the Warehouse stock split) | warning |
| `item_missing_packaging` | Stocked items with **no** pallet-conversion path (no stored pallets, no `material_packaging` conversion, no `warehouse_capacity` default) — pallet KPIs show "—" for them | warning |
| `item_master_stale` | `warehouse_stock` cache not refreshed within the threshold (hours) | warning, 168 h |

Each finding is a single de-duplicated alert with the item count and a sample of
item codes. Checks skip gracefully when a table/column (e.g. migration 013's
`product_type`) is missing, like every other rule.

To enable: apply migration 015 on the box, then load the Audit page (the audit
runs on load) or run `php etl/audit_alerts.php`.

## 2. SAP-side validation (source of truth)

The cache can only be as good as SAP. `etl/queries/prodhana_item_master_validation.sql`
audits OITM at the source (READ-ONLY) and returns one row per finding:

- `MISSING_DESCRIPTION` — active item with empty ItemName
- `MISSING_ITEM_GROUP` — active item with no item group
- `MISSING_UOM` — active inventory item with no inventory UoM
- `MISSING_PACK_UNITS` — active sales item with no `SalPackUn` (breaks case/pallet conversions)
- `FROZEN_WITH_STOCK` — frozen item that still has on-hand stock in OITW

Run on the XAMPP box:

```
php etl/query.php --source=PRODHANA --query=etl/queries/prodhana_item_master_validation.sql --via=PRODHANA
```

Fix the flagged records in SAP B1, then re-run `etl/pull_inventory.php --what=stock`
to refresh the cache — the corresponding dashboard alerts auto-resolve on the next
audit run.
