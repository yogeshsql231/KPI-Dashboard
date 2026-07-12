# KPI Dashboard — Customer Service / Order Management

A secure PHP + MySQL foundation for the company KPI program. This first build
delivers the **Customer Service / Order Management** scorecard (OTIF, Item Fill
Rate, Shipped Short, Lead Time, Complaints, PO Revisions) plus a validated REST
API for loading data. Later phases will pull live data from **PRIMS** and
**PRODHANA (SAP HANA)**.

> No user login yet — intentionally out of scope for this foundation build.

## What's included

| File | Purpose |
|------|---------|
| `config/config.php` | Loads `.env`, sets timezone / error handling, `env()` helper |
| `config/database.php` | Secure **PDO** connection (singleton, exceptions, real prepared statements) |
| `sql/schema.sql` | Tables + KPI **views** + a stored **procedure** (`sp_kpi_summary`) |
| `sql/seed.sql` | Sample data derived from the reference workbook (KPIs match exactly) |
| `api/shipments.php` | **REST endpoint**: `POST` JSON → validate → prepared-statement `INSERT`; `GET` KPI summary |
| `src/Validator.php` | Dependency-free input validation |
| `src/Response.php` | JSON response helper |
| `src/KpiRepository.php` | Read-only KPI queries for the dashboard |
| `public/dashboard.php` | Main dashboard UI (reads from the views) |

## KPI definitions (match the reference workbook exactly)

Computed in the database views so every consumer gets identical numbers:

```
cases_short = qty_requested - qty_shipped            (raw; may be negative)
delay_days  = actual_date  - requested_date          (DATEDIFF)
otif_flag   = 1 when (cases_short + delay_days) <= 1  (1-unit tolerance)
ifr         = qty_shipped / qty_requested            (per line, capped at 1)
OTIF %      = AVG(otif_flag)      IFR % = AVG(ifr)    Shipped Short = SUM(cases_short > 0)
```

Verified against the reference sheet: **OTIF 95.98%**, **IFR 99.17%**,
**Shipped Short 228 cases**.

## Setup

Requires PHP 8.1+ (with `pdo_mysql`) and MySQL 8 / MariaDB 10.4+.

```bash
# 1. Configure
cp .env.example .env        # then edit DB_* credentials

# 2. Create database + user (adjust names to taste)
mysql -u root -p -e "CREATE DATABASE kpi_dashboard CHARACTER SET utf8mb4;"

# 3. Load schema (+ optional sample data)
mysql -u root -p kpi_dashboard < sql/schema.sql
mysql -u root -p kpi_dashboard < sql/seed.sql

# 4. Run (dev)
php -S localhost:8000
# Dashboard: http://localhost:8000/public/dashboard.php
# API:       http://localhost:8000/api/shipments.php
```

On XAMPP, drop the folder in `htdocs/` and browse to
`http://localhost/KPI-Dashboard/public/dashboard.php`.

## REST API

### `POST /api/shipments.php`
Insert one order-shipment line. If `API_KEY` is set in `.env`, send it as an
`X-API-Key` header.

```bash
curl -X POST http://localhost:8000/api/shipments.php \
  -H "Content-Type: application/json" \
  -d '{
        "ship_date": "2026-06-26",
        "po_number": "PO12345",
        "customer": "Acme Foods",
        "item_number": "100074",
        "qty_requested": 100,
        "qty_shipped": 98,
        "requested_date": "2026-06-26",
        "actual_date": "2026-06-26"
      }'
# -> {"status":"success","data":{"id":251}}
```

Required: `ship_date`, `po_number`, `customer`, `item_number`.
Invalid input returns `422` with a list of messages. All values are bound via
prepared statements — input can never alter the SQL.

### `GET /api/shipments.php`
Returns the KPI summary and the 20 most recent lines.

## Pulling data from PRIMSBM / PRODHANA (ETL)

The source databases live on the company LAN, so the ETL runs **on the local
server** (the XAMPP box), not from the cloud. It is **read-only** against the
source (SELECT only) and writes only into the local MySQL `order_shipments`
table — nothing is ever written back to PRIMSBM/PRODHANA.

```bash
php etl/pull_shipments.php --source=PRIMSBM            # pull + upsert
php etl/pull_shipments.php --source=PRIMSBM --dry-run  # preview, writes nothing
```

1. Set the PRIMSBM source connection vars in `.env` (`PRIMSBM_DB_*`).
   Use a dedicated login with only the permissions needed by the ETL.
2. Fill in the source query so its output columns match the KPI fields:
   `etl/queries/primsbm_shipments.sql` and `etl/queries/prodhana_shipments.sql`
   (required aliases: `source_key, ship_date, po_number, customer, ship_via,
   item_number, qty_requested, qty_shipped, order_date, requested_date,
   actual_date`). `source_key` must be a stable unique id per line — the ETL
   upserts on `(source_system, source_key)` so re-runs never duplicate rows.
3. First time on an existing DB, apply `sql/migrations/001_add_source_columns.sql`.

### Enabling the SQL Server driver on XAMPP (Windows)
1. Download Microsoft's [`pdo_sqlsrv`](https://learn.microsoft.com/sql/connect/php/download-drivers-php-sql-server)
   DLL matching your PHP version/thread-safety, drop it in `xampp\php\ext\`.
2. Add `extension=php_pdo_sqlsrv_XX.dll` to `xampp\php\php.ini`, restart Apache.
3. Install the [ODBC Driver for SQL Server](https://learn.microsoft.com/sql/connect/odbc/download-odbc-driver-for-sql-server).
4. Verify: `php -m | findstr sqlsrv`.

### PRODHANA through the SQL Server linked server

PRODHANA is accessed only through the `PRODHANA` linked server on PRIMSBM.
Direct HANA ODBC (`--source=PRODHANA`) is not supported because there is no
standalone HANA-native account provisioned for the dashboard.

```bash
php etl/source_check.php --source=PRIMSBM
php etl/pull_lpn.php --source=PRIMSBM --query=etl/queries/prodhana_lpn.sql --via=PRODHANA
```

The LPN pull is read-only on both upstream systems. It wraps the HANA query in
`OPENQUERY([PRODHANA], '...')` and writes the returned rows only to local
MySQL. On Windows, `etl\run_pull_lpn_prodhana.bat` runs the connection check,
aborts on failure, executes the bridge pull, and logs to
`etl\logs\pull_lpn_prodhana_YYYYMMDD.log`. Configure Task Scheduler to run it
hourly and to avoid starting a new instance while a previous run is active.

The SQL Server login must not be `sysadmin`. Give it an explicit linked-server
login mapping to an approved read-only HANA security context and only the local
database permissions required to execute the ETL query.

## Qlik live data connection (SCRUM-31)

The Qlik ("Click") app runs off a SQL Server stored procedure that surfaces the
**differences between PRIMS and SAP/PRODHANA**. The procedure's output is linked
into an Excel sheet (a **live** link, not a static export) that Qlik reads,
refreshed every 30 minutes plus on-demand. The connection mechanism —
stored procedure, live Excel `.odc` link, and refresh scaffolding — lives in
[`qlik/`](qlik/README.md). Which specific Qlik sheets/visualizations bind to
this feed is still pending confirmation (flagged in `qlik/README.md`).

## Security notes
- Credentials only via environment / `.env` (never hard-coded; `.env` is gitignored).
- PDO with `ERRMODE_EXCEPTION` and `EMULATE_PREPARES = false` (true server-side prepared statements).
- ETL is read-only on source systems; use a SELECT-only DB login.
- Every write uses bound parameters; every dashboard output is HTML-escaped.
- Optional `X-API-Key` shared-secret guard on writes.

## Roadmap
- Fill in the PRIMSBM / PRODHANA source queries and schedule the ETL (cron / Task Scheduler).
- Confirm which Qlik sheets/visualizations bind to the PRIMS vs SAP/PRODHANA live diff feed (see `qlik/`).
- Additional dashboards from the requirements doc (Production, Quality, Warehouse, Procurement…).
- Authentication / RBAC when required.
