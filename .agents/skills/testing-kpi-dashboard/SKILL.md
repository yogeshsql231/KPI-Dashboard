---
name: testing-kpi-dashboard
description: Test the KPI Dashboard PHP pages (Overview, Delivery, Warehouse, Customer Service, Audit) locally end-to-end. Use when verifying filter, panel, or ETL-related UI changes.
---

# Testing the KPI Dashboard locally

## Setup
1. Start MySQL: `sudo service mysql start`. Local DB: `kpi_dashboard`, user `kpi_app`, password `kpi_local_pw` (dev-only). If the DB is missing, apply `sql/*.sql` then `sql/migrations/*.sql` in order, and seed with the sample-data scripts used in prior sessions (see repo history / `/home/ubuntu/attachments/gen_*seed*.py` if present).
2. Serve: `php -S 127.0.0.1:8090 -t public` (run in background). Pages: `overview.php`, `dashboard.php` (Delivery), `warehouse.php`, `dashboard_cs.php` (Customer Service), `audit.php`.
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

## Good adversarial test pattern
1. Load page with no filters, note baseline totals (e.g. Delivered Qty card).
2. Apply one filter; assert totals change to a DB-verified value, not just "page loads".
3. Check cascading: other dropdowns' option lists should shrink.
4. Regression: remaining panels render without SQL errors.

## Devin Secrets Needed
None for local testing. SAP credentials exist only on the user's LAN box; never needed (or usable) from the VM.
