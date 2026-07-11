<#
    SCRUM-31 — unattended refresh of the Qlik live-diff Excel workbook.

    Excel's built-in "refresh every 30 minutes" only fires while the workbook
    is open interactively. This script drives the same refresh headlessly via
    Excel automation so the linked sheet (and therefore the Qlik app that reads
    it) stays current on a schedule. It:
        1. opens the workbook that carries the prims_sap_diff.odc connection,
        2. runs RefreshAll and waits for background queries to finish,
        3. saves and closes.

    Run on the XAMPP / Office box on the company LAN (the SQL Server is only
    reachable there). Schedule every 30 minutes with the companion task
    definition Refresh-QlikDiff-Every30Min.xml, or run it on demand for a
    manual refresh (see manual_refresh.cmd).

    Usage:
        powershell -ExecutionPolicy Bypass -File refresh_qlik_diff.ps1 `
            -WorkbookPath "C:\Qlik\PRIMS_SAP_Diff.xlsx"

    Edge cases (per SCRUM-31): there is no automated fallback. If the SQL
    Server / stored procedure / linked source is unavailable the refresh fails,
    the workbook keeps its last good copy, and this script exits non-zero so the
    Task Scheduler run is marked failed. Discrepancies are then corrected
    manually via paperwork and the schedule resumes on the next tick.
#>

[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)]
    [string] $WorkbookPath
)

$ErrorActionPreference = 'Stop'

if (-not (Test-Path -LiteralPath $WorkbookPath)) {
    Write-Error "Workbook not found: $WorkbookPath"
    exit 1
}

$excel = $null
$workbook = $null
try {
    $excel = New-Object -ComObject Excel.Application
    $excel.Visible = $false
    $excel.DisplayAlerts = $false
    # Let RefreshAll pull without a per-connection background thread so the
    # script can block until every query has completed before saving.
    $excel.EnableEvents = $false

    $workbook = $excel.Workbooks.Open($WorkbookPath)

    foreach ($conn in $workbook.Connections) {
        if ($conn.Type -eq 1) {          # xlConnectionTypeOLEDB
            $conn.OLEDBConnection.BackgroundQuery = $false
        } elseif ($conn.Type -eq 2) {    # xlConnectionTypeODBC
            $conn.ODBCConnection.BackgroundQuery = $false
        }
    }

    $workbook.RefreshAll()
    $excel.CalculateUntilAsyncQueriesDone()

    $workbook.Save()
    Write-Output ("[qlik-refresh] {0:yyyy-MM-dd HH:mm:ss} refreshed {1}" -f (Get-Date), $WorkbookPath)
}
catch {
    Write-Error ("[qlik-refresh] failed: " + $_.Exception.Message)
    exit 2
}
finally {
    if ($workbook) { $workbook.Close($false) | Out-Null }
    if ($excel)    { $excel.Quit() }
    foreach ($o in @($workbook, $excel)) {
        if ($o) { [void][System.Runtime.InteropServices.Marshal]::ReleaseComObject($o) }
    }
    [System.GC]::Collect()
    [System.GC]::WaitForPendingFinalizers()
}
