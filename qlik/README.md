# Qlik ("Click") live data connection — SCRUM-31

Moves the Qlik app off its proof-of-concept setup and onto a **live** data
connection. This folder is the **infrastructure/plumbing** for that connection;
the Qlik-side wiring (which sheets/visualizations bind to the feed) is pending
confirmation — see [Open question](#open-question).

## Data flow

```
PRIMS (SQL Server)  ─┐
                     ├─►  dbo.sp_qlik_prims_sap_diff  ──►  Excel sheet  ──►  Qlik app
SAP / PRODHANA (HANA)┘        (SQL Server)               (live ODC link)   (reads Excel)
   via linked server / OPENQUERY
```

1. **Data source** — the SQL Server stored procedure
   [`sql/sqlsrv/sp_qlik_prims_sap_diff.sql`](../sql/sqlsrv/sp_qlik_prims_sap_diff.sql)
   computes the **difference** between PRIMS and SAP/PRODHANA (item-per-warehouse
   on-hand qty & value; surfaces mismatches and items missing from either
   system). READ-ONLY against both systems.
2. **Live link** — Excel binds a sheet to the procedure through the Office Data
   Connection [`prims_sap_diff.odc`](prims_sap_diff.odc). This is a **live link,
   not a static export**: the sheet re-queries the procedure on every refresh.
3. **Qlik** — the Qlik app reads from that linked Excel sheet.

## Setup (on the LAN box — XAMPP/Office machine that can reach SQL Server)

1. **Deploy the stored procedure** on the PRIMSBM SQL Server:
   ```
   sqlcmd -S 192.168.100.5 -d PRIMSBM -i sql\sqlsrv\sp_qlik_prims_sap_diff.sql
   ```
   Then edit the two clearly-marked blocks in that file: the **PRIMS source
   table** and the **HANA linked-server name** (`@HanaLinkedServer`). Smoke-test
   from the CLI without Excel/Qlik:
   ```
   php etl\query.php --source=PRIMSBM --query=etl\queries\qlik_prims_sap_diff.sql
   ```
2. **Create the live Excel link**: double-click `prims_sap_diff.odc` (or Excel ▸
   Data ▸ Get Data ▸ From File ▸ From ODC) and enable the connection. Save the
   resulting workbook (e.g. `C:\Qlik\PRIMS_SAP_Diff.xlsx`).
3. **Point Qlik** at that workbook's sheet (see Open question for which
   sheets/visualizations).

## Refresh cadence — every 30 minutes + manual

* **Automatic (30 min):**
  * *Interactive:* in the workbook, Data ▸ Queries & Connections ▸ Properties ▸
    check **Refresh every 30 minutes** and **Refresh data when opening the file**.
  * *Unattended (server):* Excel's timed refresh only runs while the workbook is
    open, so for a headless box use [`refresh_qlik_diff.ps1`](refresh_qlik_diff.ps1)
    driven by the Task Scheduler task
    [`Refresh-QlikDiff-Every30Min.xml`](Refresh-QlikDiff-Every30Min.xml)
    (`Interval = PT30M`). Import it and edit the paths/account:
    ```
    schtasks /Create /TN "KPI\Refresh-QlikDiff-Every30Min" /XML qlik\Refresh-QlikDiff-Every30Min.xml
    ```
* **Manual (on demand):** Excel ▸ Data ▸ Refresh All, or run
  [`manual_refresh.cmd`](manual_refresh.cmd) (wraps the same PowerShell refresh).

## Edge cases (no automated fallback)

Per SCRUM-31, there is intentionally **no automatic fallback / retry**. If the
SQL Server, the stored procedure, or the linked source is unavailable, or the
data looks wrong:

* the refresh fails and the workbook keeps its **last good copy** (the scheduled
  task run is marked failed in Task Scheduler history);
* discrepancies are corrected **manually via paperwork**, and the process
  continues from there once corrected.

No silent substitution or synthetic data is introduced.

## Open question

**Which specific Qlik sheets/visualizations should bind to this live PRIMS vs
SAP/PRODHANA difference feed is NOT yet confirmed.** This PR sets up the
underlying live-connection mechanism (stored procedure + live Excel link +
30-minute and manual refresh). The visualization-specific wiring inside the
Qlik app is **pending Yogesh's confirmation** and is deliberately out of scope
here to avoid guessing.

## Files

| File | Purpose |
|------|---------|
| `../sql/sqlsrv/sp_qlik_prims_sap_diff.sql` | SQL Server stored procedure — the data source (PRIMS vs SAP diff) |
| `../etl/queries/qlik_prims_sap_diff.sql` | `EXEC` wrapper to smoke-test the procedure via `etl/query.php` |
| `prims_sap_diff.odc` | Office Data Connection — the live Excel ▸ SQL link |
| `refresh_qlik_diff.ps1` | Headless Excel refresh (for unattended 30-min schedule / manual run) |
| `Refresh-QlikDiff-Every30Min.xml` | Task Scheduler task — automatic 30-minute refresh |
| `manual_refresh.cmd` | On-demand manual refresh wrapper |
