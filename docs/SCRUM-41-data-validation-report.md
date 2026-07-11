# SCRUM-41 — Data Validation Sweep (pre-executive-presentation)

Date: 2026-07-04. Scope: page-by-page readiness of every dashboard page, plus the four
data-accuracy considerations from the ticket. Investigation + report; only low-risk
fixes were made in this PR (clearly listed at the end). Everything else is flagged as
an open question.

Validation environment: local PHP server + seeded MySQL cache (LAN sources PRIMSBM /
PRODHANA are not reachable from this environment). "Production data flowing" is based
on the ETL loads already confirmed on the XAMPP box; items that still need a run there
are called out per page.

---

## Part 1 — Page-by-page readiness

All 5 pages return HTTP 200 with zero PHP warnings/errors, both unfiltered and with
warehouse / date-range / SO filters applied. Cross-navigation (nav bar) links all 5
pages from every page. No page renders blank when a cache table is empty — panels that
depend on optional ETLs show explicit setup-hint / "no data" states instead.

| Page | Data connection | Real data flowing (production) | Nav | Filters/dropdowns |
|---|---|---|---|---|
| **overview.php** | MySQL cache: `delivery_lines`, `lpn_pallets`, `ar_payments`, `customer_complaints` | YES — delivery (12-mo window) and LPN (27,202 pallet rows) confirmed loaded; payments requires `pull_payments.php` run (verify on box) | OK | Warehouse buttons, date range, division/pallet toggles — OK |
| **dashboard.php** (Delivery) | MySQL cache: `delivery_lines`, `material_movements`, `production_usage` | Delivery YES. Stock-stage strip + Est-vs-Actual panel show setup hints until migration 012 + `pull_inventory.php --what=production` are run on the box | OK | Warehouse, SO, PO, item, date, carrier, status — OK |
| **warehouse.php** | MySQL cache: `warehouse_stock`, `inventory_batches`, `lpn_pallets`, `warehouse_capacity`, `material_packaging`, `delivery_lines` | LPN YES. Stock split needs migration 013 + `pull_inventory.php --what=stock`; capacity/packaging masters need their one-time loads | OK | Warehouse, item, split toggles (location/type/category) — OK |
| **dashboard_cs.php** (Customer Service) | MySQL cache: `order_shipments`, `customer_complaints`, `delivery_lines` | YES — shipments reload after PR #44 confirmed; complaints loaded | OK | Warehouse, SO, PO, date — OK (fixed in SCRUM-83) |
| **audit.php** | MySQL cache: `operational_readings`, audit engine tables (migration 009) | Requires `pull_readings.php` on the box — verify readings cache is populated | OK | Date range — OK |

**No "Coming soon" placeholders remain** — every page is wired to a live repository
class. The remaining production gaps are all one-time migration/ETL runs on the XAMPP
box (011–013 + inventory/production/readings pulls), previously communicated.

---

## Part 2 — Data-accuracy considerations

### 2.1 SO/PO relationship (one SO ↔ many POs)

How it works today: `po_number` is line-level `RDR1."PoNum"` with fallback to header
`ORDR."NumAtCard"` (etl/queries/prodhana_delivery.sql, prodhana_shipments.sql), and
every PO count in the code uses `COUNT(DISTINCT po_number)` (DeliveryRepository,
KpiRepository). So an SO carrying 3 POs correctly counts as 1 SO / 3 POs, and a PO
spanning multiple lines is not double-counted. **No double-counting found.**

Two edge cases to confirm with the business (open questions):

- **Q1 — PO uniqueness across customers.** `COUNT(DISTINCT po_number)` is global: if
  two different customers ever reuse the same PO string (e.g. "12345"), they collapse
  into one. The ticket says POs are always unique, so this should be fine — but if
  uniqueness is only per-customer, the counts should become
  `COUNT(DISTINCT CONCAT(customer_code, '|', po_number))`.
- **Q2 — Lines with no PO.** When both `RDR1.PoNum` and `NumAtCard` are empty,
  `po_number` is NULL and the line is excluded from PO counts (but still counted in SO
  counts and quantities). Confirm that's the intended treatment.

### 2.2 PRIMS vs SAP inventory gap (SCRUM-31)

**Finding: the known gap is NOT currently accounted for in inventory-based KPIs.**

- The Warehouse page's stock-on-hand / stock-split / pallet KPIs read
  `warehouse_stock`, populated by `etl/queries/primsbm_stock.sql` from **PRIMSBM's
  OITW** — one side of the very gap SCRUM-31 measures. If PRIMS and SAP disagree, the
  dashboard silently shows the PRIMS-side number with no indicator.
- The SCRUM-31 reconciliation (`sp_qlik_prims_sap_diff`, Excel/Qlik feed) exists but is
  a separate feed — nothing on the dashboard consumes it or warns when the diff is
  non-zero.
- Also noted while reviewing `primsbm_stock.sql`: the pallet estimate uses
  `OnHand / OITM.SalPackUn`. `SalPackUn` is SAP's *sales packaging units* (units per
  case), not units per pallet — pallet figures from this path are likely overstated.
  The Warehouse page's own conversion chain (material_packaging → warehouse_capacity)
  is correct; it's only the stored `pallets` column from this ETL that is suspect.
  (Open question **Q3**: confirm the right per-pallet factor for OITW-based stock.)

Recommendation (needs business sign-off, not fixed here): decide the source of truth
for inventory KPIs (SAP HANA OITW vs PRIMS), and/or surface the SCRUM-31 diff count as
a data-quality badge on the Warehouse page.

### 2.3 Delayed payments — payment date vs sale/booking date

**Finding: partially handled; two behaviors need a business decision.**

What's right already: "received late" logic compares actual `paid_date` to `due_date`
with a tunable grace period (`PaymentRepository`), so a May invoice paid late in July
*is* detected as late.

Flags:

- **Q4 — Month attribution.** `PaymentRepository::byMonth()` groups by
  `invoice_date` (booking month). A May sale paid late in July shows its late-paid $
  under **May**, not July — fine for "which sales month generated late payments", wrong
  if the executive reading is cash-flow by receipt month. If cash-in timing is wanted,
  a second view grouped by `paid_date` is needed.
- **Q5 — Open overdue invoices (same as SCRUM-85).** Invoices past due but *not yet
  paid* (`paid_date IS NULL`) are excluded from every late metric. Late-payment $ is
  therefore understated by the entire open-overdue AR balance. Section 5 of the audit
  pack (`sap_prims_accuracy_audit.sql`) quantifies this from SAP.
- Revenue tiles on Overview/Delivery use order/delivery amounts (booking), not cash
  receipts — consistent, but worth stating on the exec slide so nobody reads them as
  cash flow.

### 2.4 Warehouse-level inventory: PRIMS vs SAP full-matrix cross-check

This requires LAN access, so it could not be executed from this environment. Two
ready-to-run tools already exist — run either from SSMS on 192.168.100.5 (VPN):

1. Full item × warehouse matrix including matches:
   ```sql
   EXEC dbo.sp_qlik_prims_sap_diff @OnlyDifferences = 0;
   ```
   (After pointing the `PRIMS SOURCE — ADJUST` block at the real PRIMS on-hand table —
   still the open item from SCRUM-31.)
2. Aggregate totals per warehouse: section 7 of
   `sap_prims_accuracy_audit.sql` (delivered previously) vs the MySQL companion's
   `warehouse_stock` totals.

Paste the result grids back and the discrepancies will be triaged item-by-item.

---

## Additional defects found during this sweep (with low-risk fixes in this PR)

- **Cancelled orders inflate Late % and depress OTIF %.** Cancelled SOs keep their
  `late_shipment`/`otif` flags in the SAP extract and the MySQL view counted them in
  `AVG(late_flag)`/`AVG(otif_flag)` denominators. Fixed in two layers: the HANA
  delivery query now returns `late_shipment='No'` for `ORDR.CANCELED='Y'`, and
  migration 014 makes the view emit NULL flags for cancelled lines so they drop out of
  both numerator and denominator (verified locally: rates unchanged before/after a
  cancelled fixture row, denominator excludes it).
- **Customer ref leaked into the Carrier column.** `prodhana_delivery.sql` used
  `COALESCE(SHP.TrnspName, T0.NumAtCard)` — NumAtCard is the customer's reference/PO
  number, not a carrier, so orders without a shipping type showed a PO-like string as
  their carrier (and polluted the carrier filter dropdown). Now `TrnspName` only.
- `lateByMonth()` denominator switched to `COUNT(late_flag)` so it matches the new
  NULL-for-cancelled semantics.

Production note: after merge, run on the XAMPP box —
`mysql -u kpi_app -p kpi_dashboard < sql\migrations\014_exclude_cancelled_from_flags.sql`
and re-run `pull_delivery.php` to refresh the late/carrier values.

## Open questions requiring business input (summary)

| # | Question | Impacted KPI |
|---|---|---|
| Q1 | Is PO uniqueness global or per-customer? | Total PO counts |
| Q2 | Should no-PO lines be excluded from PO counts? | Total PO counts |
| Q3 | Correct per-pallet factor for OITW stock (SalPackUn is per-case)? | Warehouse pallet figures |
| Q4 | Late-payment months: booking month or receipt month? | Late Payments chart |
| Q5 | Count open overdue invoices as late (SCRUM-85)? | Late-payment $ |
| Q6 | Source of truth for inventory KPIs: SAP HANA or PRIMS (SCRUM-31 gap)? | All stock KPIs |
