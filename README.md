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

## Security notes
- Credentials only via environment / `.env` (never hard-coded; `.env` is gitignored).
- PDO with `ERRMODE_EXCEPTION` and `EMULATE_PREPARES = false` (true server-side prepared statements).
- Every write uses bound parameters; every dashboard output is HTML-escaped.
- Optional `X-API-Key` shared-secret guard on writes.

## Roadmap
- Connect PRIMS (`RajDB_CLF` / `SP_2025SAP_DeliveryDetailReport`) and PRODHANA feeds (ETL or REST).
- Additional dashboards from the requirements doc (Production, Quality, Warehouse, Procurement…).
- Authentication / RBAC when required.
