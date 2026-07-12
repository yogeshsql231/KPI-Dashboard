# SCRUM-94 — PRODHANA direct connection: verification guide

Goal: confirm the dashboard's direct ODBC connection to PRODHANA
(SAP Business One on HANA, `192.168.100.3`) works end-to-end. All access is
READ-ONLY — the ETL only ever `SELECT`s from the source and upserts into the
local MySQL cache.

## 1. Configure `.env` (on the XAMPP box)

```ini
PRODHANA_DB_DRIVER=odbc
PRODHANA_DB_HOST=192.168.100.3
PRODHANA_DB_PORT=30015
PRODHANA_DB_ODBC_DRIVER=HDBODBC
PRODHANA_DB_USER=<readonly user>
PRODHANA_DB_PASS=<password>
```

Alternatives (in order of precedence, see `config/source_db.php`):

- `PRODHANA_DB_DSN=Driver={HDBODBC};ServerNode=192.168.100.3:30015` — full
  DSN-less connection string, used verbatim.
- Leave `PRODHANA_DB_HOST` empty and set `PRODHANA_DB_NAME=<DSN name>` to use a
  named ODBC DSN configured in Windows (ODBC Data Sources, 64-bit).

Prerequisite: the SAP HANA client (which provides the `HDBODBC` ODBC driver)
must be installed on the machine, and PHP's `pdo_odbc` extension enabled in
`php.ini` (`extension=pdo_odbc`).

`.env` is git-ignored (see `.gitignore`) and must never be committed — it
contains live credentials. Verify with: `git check-ignore -v .env`.

## 2. Connectivity check (no data pulled)

```
C:\xampp\php\php.exe etl\source_check.php --source=prodhana
```

Expected output ends with `all good — source 'PRODHANA' is reachable and
readable.` It verifies, in order: connection, a probe `SELECT 1 FROM DUMMY`,
and that the read-only user can read the `DAMASCUS_BAKERY` schema.

## 3. Dry-run the LPN pull directly against HANA

```
C:\xampp\php\php.exe etl\pull_lpn.php --source=prodhana --dry-run --limit=5
```

Compare the 5 sample rows against known pallets (LPN, warehouse, bin, item,
lot). Then run the real pull (writes only to the local MySQL cache):

```
C:\xampp\php\php.exe etl\pull_lpn.php --source=prodhana
```

Expected: `done. read=~27000 written=... skipped=0` (AVAILABLE pallets only).

## 4. Verify the local cache updated

```sql
-- MySQL (kpi_dashboard)
SELECT source_system, COUNT(*) AS rows_, MAX(refreshed_at) AS last_refresh
FROM lpn_pallets GROUP BY source_system;
```

The PRODHANA row count should match the ETL's `written=` figure and
`last_refresh` should be the run time.

Important: rows are keyed on `(source_system, source_key)`, so a direct pull
(`source_system = 'PRODHANA'`) does NOT overwrite rows loaded earlier through
the linked-server path (`source_system = 'PRIMSBM'`). Pick ONE path for
production. If switching to the direct pull, clear the old rows first so
pallet counts are not doubled:

```sql
DELETE FROM lpn_pallets WHERE source_system = 'PRIMSBM';
```

The Overview/Warehouse pallet widgets should then reflect the refreshed data.

## 5. Troubleshooting

| Symptom | Likely cause / fix |
| --- | --- |
| `CONNECT FAILED ... timeout` / hangs | VPN not connected, or 192.168.100.3:30015 blocked. Test with `Test-NetConnection 192.168.100.3 -Port 30015`. |
| `Data source name not found and no default driver specified` | HANA client / `HDBODBC` driver not installed, or 32/64-bit mismatch with PHP. |
| `could not find driver` (PDO) | `pdo_odbc` extension not enabled in `php.ini`. |
| `authentication failed` | Wrong PRODHANA_DB_USER/PASS; confirm the read-only login in HANA Studio/DBX. |
| `insufficient privilege` on DAMASCUS_BAKERY | Grant `SELECT` on the `DAMASCUS_BAKERY` schema to the read-only user (no other privileges needed). |
| Probe OK but pull very slow | Prefer the linked-server path (`--via=PRODHANA` through PRIMSBM) or run off-hours; the query is already filtered to AVAILABLE pallets. |
