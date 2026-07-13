---
name: testing-kpi-dashboard
description: Test the KPI Dashboard PHP pages (Overview, Delivery, Warehouse, Customer Service, Procurement, Audit) locally end-to-end. Use when verifying filter, panel, or ETL-related UI changes.
---

# Testing the KPI Dashboard locally

## Setup
1. Start MySQL: `sudo service mysql start`. Local DB: `kpi_dashboard`, user `kpi_app`, password `kpi_local_pw` (dev-only). If the DB is missing, apply `sql/*.sql` then `sql/migrations/*.sql` in order, and seed with the sample-data scripts used in prior sessions (see repo history / `/home/ubuntu/attachments/gen_*seed*.py` if present).
2. Serve: `php -S 127.0.0.1:8090 -t public` (run in background). Pages: `overview.php`, `dashboard.php` (Delivery), `warehouse.php`, `dashboard_cs.php` (Customer Service), `procurement.php`, `audit.php`.
3. Lint: `php -l <file>` on every changed PHP file (no composer lint/test suite exists).

## Key facts that make testing fast
- SAP sources (PRIMSBM SQL Server, PRODHANA HANA) are LAN-only — ETL cannot run from the Devin VM. Test ETL scripts at CLI level only (`--dry-run` / arg validation); real pulls happen on the user's XAMPP box.
- All pages read local MySQL cache tables (`delivery_lines`, `order_shipments`, `customer_complaints`, `warehouse_stock`, `inventory_batches`, `lpn_pallets`, ...). Verify UI numbers against direct `mysql` queries for hard evidence.
- Filter architecture: `src/DeliveryFilters.php` (Overview/Delivery/Warehouse) and `src/Filters.php` (Customer Service). Cascading option lists use `clauseExcept()` — selecting one filter should shrink the other dropdowns.
- Useful seeded values for assertions: carriers include "CPU", "FedEx Freight"; so_status Open/Closed; SO numbers 700000+; item 100074 = "11.5\" Flax Wrap" (appears in complaints with description).
- Warehouse page mixes delivery-based panels (respond to SO/carrier/status filters) and inventory-cache panels (warehouse/item only — they intentionally ignore SO/carrier). Don't flag that as a bug.
- AUTH may be off (`AUTH_ENABLED=false` in `.env`); if pages redirect to login, check that flag.

## Overview redesign (SCRUM-82) specifics
- Overview has client-side behaviors driven by `DATA` JSON in `public/overview.php` + `public/assets/overview.css`: date→warehouse filter lock (warehouse buttons `pointer-events:none` until both dates set), KPI tile hover trend, pallet Loaves/Bars toggle, correlation month drill-down, drag-to-reorder sections.
- Layout + pallet-view preferences persist in localStorage key `ovLayout:<user>`; "reset layout" clears them. When retesting, prior sessions' saved prefs may make the initial view differ from defaults (e.g. Bars instead of Loaves) — that's persistence working, not a bug.
- HTML5 drag-reorder works with a plain `left_click_drag` from a ⠿ section handle to above another section label; watch for the "saved" flash in the filter bar.
- Correlation drill-down $ values can be DB-verified with: SUM(paid_amount) on `ar_payments` grouped by customer WHERE invoice_date in the clicked month AND paid_date > due_date (matches `PaymentRepository::topLatePayers`). Note byMonth groups by invoice_date, not due_date.
- Seed data may cover only ~1 week of deliveries, so trend charts might show a single point/bar — document as a data limitation, not a failure.

## Overview KPI tiles (SCRUM-25) specifics
- The first section is "Pallets & Orders" (`.g4` grid): Pallets on hand (LPN cache via `LpnRepository::summary`), Pallets delivered (`SUM(qty_pallet)` from `DeliveryRepository::overview`), Total SO, Total PO.
- DB-verify tiles: on hand = `SELECT COUNT(*) FROM vw_lpn_pallets [WHERE std_warehouse=...]`; delivered = `ROUND(SUM(qty_pallet)) FROM vw_delivery_lines` with matching warehouse/posting_date filters.
- Chrome native date inputs: type segments as MMDDYYYY after clicking the month segment; typing with slashes can spill into the wrong segment — check the DOM value afterwards.
- Posting a PR comment with `gh` may fail with `Post ... EOF` on network-restricted VMs (gh bypasses the git auth proxy); use builtin git tools where possible or deliver evidence via message attachments instead.

## Warehouse pallet conversion (SCRUM-63) specifics
- Shared precedence everywhere: stored pallet count (`qty_pallet` / `pallets` column) → `material_packaging.units_per_pallet` → `units_per_case × cases_per_pallet` → `warehouse_capacity.cases_per_pallet`. Implemented in `DeliveryRepository::byWarehouse/warehouseCapacity` and `WarehouseInventoryRepository::palletExpr()/palletJoins()`.
- Adversarial fallback test: temporarily `UPDATE warehouse_stock SET pallets=NULL WHERE item_code='PITA-12'` — the Stock on Hand row should show the computed value (18,400/4,800 = 3.8, given seed packaging) instead of "—", and the On-hand Pallets card should recalculate. Restore the value afterwards.
- Seed data may have stored pallets that disagree with packaging math (46.0 vs 3.8) — that's a seed artifact useful for distinguishing precedence tiers, not a bug.
- Prefer builtin `git_comment_on_pr` for PR test-result comments (auto-uploads local screenshots); raw `gh` may be network-blocked.

## Department RBAC (SCRUM-53/54) specifics
- RBAC is opt-in: set `AUTH_ENABLED=true` plus at least one `DEV_<DEPT>_USERS` (dev driver) or `LDAP_GROUP_<DEPT>` key; with none set, all signed-in users keep full access (verify that as regression with curl).
- Fast dev-driver test users: `DEV_CLEVEL_USERS=ceo`, `DEV_DELIVERY_USERS=driver1`, etc., password from `DEV_PASSWORD`. C-level always sees all views; Overview becomes C-level-only once RBAC is on; Audit stays open unless explicitly mapped.
- Adversarial checks: (1) restricted user requesting a forbidden `?return=` page must land on their `landingPage()`, not the requested page; (2) direct URL to a forbidden page must render the 403 "Access restricted" card listing only allowed views (not a redirect); (3) nav links come from `Auth::allowedPages()` — count them per role.
- Browser sessions persist across .env changes — hit `logout.php` before switching users, or a stale session user (possibly missing the `departments` key) can confuse results.
- LDAP group mapping can't be tested from the VM (no AD reachable); report it as untested code-shared-path.
- If `php -S` reports "Address already in use" but nothing listens (check `ss -ltn | grep 8090`), just start it again — a dying process may leave a transient conflict.

## Shipments ETL / CS filters (SCRUM-83) specifics
- `etl/pull_shipments.php` supports optional columns (`so_docentry, so_status, pick_status, warehouse, carrier`) detected from the first result row; base-only queries still work. CS page filters break (empty Warehouse dropdown, filters match nothing) whenever those columns are NULL in `order_shipments` — check that before suspecting `Filters.php`.
- To test any ETL loader without LAN access, add a mock source to `.env` pointing at local MySQL (`MOCKSRC_DB_DRIVER=mysql`, host 127.0.0.1, kpi_dashboard/kpi_app) and write a query aliasing an existing seeded table (e.g. `delivery_lines`) to the loader's expected output columns. Run with `--source=MOCKSRC --query=/tmp/mock.sql --dry-run --limit=3` first.
- DB parity queries: avoid `lines` as a column alias — it's a MariaDB reserved word (use `cnt`).
- CS KPI strip parity: "N order lines / M cases shipped" = `SELECT COUNT(*), SUM(qty_shipped) FROM order_shipments WHERE is_sample=0 AND <filter>` (warehouse=`warehouse`, SO=`so_docentry LIKE '%x%'`, dates on `ship_date`).

## Stock Split (SCRUM-26) specifics
- Warehouse page "Stock Split" panel groups `vw_warehouse_stock` (migration 013) by `?split=location|type|category`; the view COALESCEs blank/NULL `product_type`/`category` to "Unassigned" via `std_*` columns.
- DB parity: `SELECT std_product_type, SUM(on_hand) FROM vw_warehouse_stock GROUP BY 1` (same for `warehouse` / `std_category`); shares should sum to ~100%.
- Toggle links must preserve active filters (check `item=` etc. stays in the URL after clicking a toggle).
- Unassigned fallback test: `UPDATE warehouse_stock SET product_type=NULL WHERE warehouse='<one>'`, refresh split=type, expect an "Unassigned" row with that warehouse's on_hand; restore afterwards.
- ETL optional columns for stock: `pull_inventory.php --what=stock` detects `product_type`/`category` from the first row (same pattern as pull_shipments). Test both mock queries WITH and WITHOUT the columns via MOCKSRC; note the real table columns are `unit_of_measure` (not `uom`) and `committed`/`on_order` are required numeric outputs.

## Inventory Summary (SCRUM-29) specifics
- Warehouse page "Inventory Summary — Department × Location × Pallets" panel = `WarehouseInventoryRepository::inventorySummary()` grouping `vw_warehouse_stock` by `std_category × warehouse`, with bold department subtotal rows (locations/items/on-hand/pallets) above per-location rows ordered pallets DESC.
- Seed data may have only one warehouse per category — subtotals then look trivial. To prove subtotal math, temporarily INSERT a second `warehouse_stock` row sharing an existing category but a different warehouse (set `pallets` explicitly to avoid NULL from missing packaging/capacity fallbacks), then DELETE it afterwards.
- DB parity query: group `vw_warehouse_stock` by `std_category, warehouse` with the palletExpr COALESCE chain (stored pallets → units_per_pallet → units_per_case×cases_per_pallet → warehouse cases_per_pallet).
- Panel honors warehouse + item filters only (like other inventory panels); SO/carrier filters are intentionally ignored.

## Source badges (SCRUM-24) specifics
- `src/SourceBadge.php` is the single registry (metric key → system/dataset/definition); badges render inside `<h2>`/eyebrow headings on all 5 pages. Unknown keys render empty string — a missing badge may mean a typo'd key, not a CSS issue.
- Verify tooltips by hovering the badge and screenshotting (native `title` attr renders after ~1-2s; the annotated DOM also shows the title text — use both as evidence).
- Color/system distinction: `.src-sap` blue, `.src-beas` purple, `.src-api` yellow, `.src-manual` grey (public/assets/style.css). Assert at least one badge of each class appears on the Warehouse (sap/beas/manual) and CS (api) pages.
- The Beas WMS and Manual badges live in offscreen sections — scroll to the LPN / Capacity panels before screenshotting.

## Audit-engine rules (SCRUM-15/22) specifics
- `public/audit.php` re-runs `AlertRepository::evaluate()` + `record()` on every page load, so testing a new rule is just: seed a violating row → load `audit.php` → assert the alert row + message; fix the data → reload → assert the footer "N resolved" count and the alert gone (auto-resolve).
- New rules must be seeded into `alert_rules` first (apply the relevant `sql/migrations/0XX_*.sql`); confirm they appear in the "Alert Rules" catalogue table (may be offscreen — scroll).
- Item-master rules (migration 015): seed one `warehouse_stock` row with empty description/type/category, `pallets=NULL`, no packaging/capacity match; for `item_master_stale`, `UPDATE warehouse_stock SET refreshed_at = NOW() - INTERVAL 200 HOUR` (restore with `= NOW()` afterwards — the update hits ALL rows).
- Fast pre-check before browser testing: `php -r "...new AlertRepository(Database::connection()); foreach ($r->evaluate() as $f) ..."` prints findings without touching alert_events.
- Local `mysql -u root` may be denied (auth_socket); use `sudo mysql kpi_dashboard` instead.

## Order-level OTIF widget (SCRUM-86) specifics
- Order-level OTIF (Overview tile, CS "OTIF by Order" card) = `MIN(otif_flag) GROUP BY sales_order` (Delivery view) / `GROUP BY COALESCE(NULLIF(so_docentry,''), po_number)` (shipments view) — it will legitimately read LOWER than the line-level `AVG(otif_flag)` rate; don't flag the mismatch as a bug.
- DB parity: `SELECT COUNT(*), SUM(a) FROM (SELECT sales_order, MIN(otif_flag) a FROM vw_delivery_lines WHERE otif_flag IS NOT NULL [AND warehouse=...] GROUP BY sales_order) x`.
- The CS card hides itself (own try/catch) when migration 011 (`so_docentry` on `vw_order_shipment_kpi`) isn't applied — a missing card may mean a missing migration, not broken code.
- Overview RAG thresholds: green ≥95%, gold ≥85%, red below (tile spark `data-color`); seed data is typically all-red, so verify color logic from the value + `data-color` attr rather than expecting green.

## Order Cycle Time (SCRUM-87) specifics
- Requires migration 017 (`delivery_date` on `delivery_lines` + re-created view). Tile degrades to "apply migration 017 + re-run delivery ETL" when missing — that hint is the graceful-degradation path, not a bug.
- Seed data likely has NULL `delivery_date` (only the PRODHANA ETL populates it); seed it locally, e.g. `UPDATE delivery_lines SET delivery_date = DATE_ADD(posting_date, INTERVAL 1+FLOOR(RAND()*7) DAY) WHERE delivered_qty > 0` before testing.
- DB parity (Overview): `SELECT COUNT(*), AVG(d) FROM (SELECT sales_order, DATEDIFF(MAX(delivery_date), MIN(posting_date)) d FROM vw_delivery_lines WHERE otif_flag IS NOT NULL AND delivery_date IS NOT NULL AND posting_date IS NOT NULL [AND warehouse=...] GROUP BY sales_order) x`.
- CS card uses `vw_order_shipment_kpi` (`DATEDIFF(MAX(actual_date), MIN(order_date))` per order key) — a different source than the Overview tile, so its avg will differ; not a mismatch bug.
- The tile has `invert=true`: a RISING cycle-time delta renders red (▲ in red is correct here).

## Supplier OTIF / Procurement page (SCRUM-88) specifics
- Requires migration 018 (`po_lines` + `vw_po_lines`). The page shows a deployment hint when the cache is empty — that's graceful degradation, not a bug.
- Whole-PO semantics: `MIN(otif_flag) GROUP BY po_number`; cancelled POs have `otif_flag NULL` and are excluded from both numerator and denominator. Adversarial seed set: include one multi-line PO (must count once), one cancelled PO (denominator must shrink), one late, one short-received, one unreceived.
- DB parity: `SELECT COUNT(*), SUM(a) FROM (SELECT po_number, MIN(otif_flag) a FROM vw_po_lines WHERE otif_flag IS NOT NULL [AND std_supplier=... AND posting_date>=...] GROUP BY po_number) x`.
- On-time rule is `receipt_date <= due_date + 1 day` (last receipt = `MAX(PDN1.DocDate)`); both the grace day and last-vs-first receipt are provisional pending Raj's confirmation — if numbers look off vs. business expectation, check those rules first.
- Source is SAP B1 `OPOR/POR1 + OPDN/PDN1` (goods receipts linked via `BaseType=22`), NOT ECC `EKKO/EKPO` even if a ticket mentions them.
- Seeded local test rows stay in the VM's MySQL only; the user's box starts empty until `etl/pull_po.php --via=PRODHANA` runs there.

## Inventory Days of Supply (SCRUM-92) specifics
- Requires migration 019 (`inventory_supply` + `vw_inventory_supply`). Panel shows a deployment hint when empty; page still renders without the table (invAvailable catch).
- Days of supply = `on_hand / (usage_qty_30d / 30)`, NULL when 30-day usage is 0 (reported as "No recent usage", never divided). Bands: <7 critical, 7–14 low, 14+ adequate. Counts are item×warehouse rows — never sum quantities across items (mixed UoMs).
- Inactive SKUs (`is_active=0`) are excluded everywhere; items created <30 days ago get a "new SKU" pill. Adversarial seed: critical, low, healthy, zero-usage, zero-on-hand-with-usage (DoS 0.0, counted stocked-out AND critical), inactive (must not appear), new SKU.
- Inv filters use `inv_category` / `inv_warehouse` / `inv_item` GET params; the two filter forms carry each other's values as hidden inputs — applying one must not reset the other.
- Source is SAP B1 `OITW` on-hand + `OINM.OutQty` trailing usage via `etl/pull_inventory_supply.php --via=PRODHANA`; the 30-day usage window is provisional pending Raj's sign-off.

## Slow/Obsolete Inventory % (SCRUM-91) specifics
- Requires migration 020 (adds `last_movement` to `inventory_supply`, view gains `is_stocked`/`is_slow`). ETL now requires the `last_movement` output column — old query files fail the column check.
- Slow = active SKU, `on_hand > 0` AND `usage_qty_90d = 0` (provisional 90-day window, pending Raj; perishables may need shorter). Slow % = slow ÷ stocked item-warehouses; count-based only — value ($) version blocked on a cost source.
- Shares the SCRUM-92 `inv_*` filters; zero on-hand rows (e.g. SESAME-10) are excluded from BOTH numerator and denominator. Never-moved rows (`last_movement NULL`) sort first with a "never moved" pill.
- Adversarial seed: one old-movement slow row, one never-moved slow row, moving rows, an inactive slow candidate (must not appear), and a zero-on-hand row (must not inflate denominator).

## Good adversarial test pattern
1. Load page with no filters, note baseline totals (e.g. Delivered Qty card).
2. Apply one filter; assert totals change to a DB-verified value, not just "page loads".
3. Check cascading: other dropdowns' option lists should shrink.
4. Regression: remaining panels render without SQL errors.

## Devin Secrets Needed
None for local testing. SAP credentials exist only on the user's LAN box; never needed (or usable) from the VM.
