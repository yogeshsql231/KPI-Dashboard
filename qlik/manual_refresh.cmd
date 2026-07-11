@echo off
REM ===========================================================================
REM SCRUM-31 - on-demand MANUAL refresh of the Qlik live-diff workbook.
REM
REM Runs the same headless refresh as the 30-minute schedule. Double-click, or
REM run from a command prompt. Edit WORKBOOK below to match where the linked
REM workbook lives on this box.
REM
REM (You can also refresh from inside Excel directly: Data > Refresh All.)
REM ===========================================================================

setlocal
set "SCRIPT_DIR=%~dp0"
set "WORKBOOK=C:\Qlik\PRIMS_SAP_Diff.xlsx"

powershell -NoProfile -ExecutionPolicy Bypass -File "%SCRIPT_DIR%refresh_qlik_diff.ps1" -WorkbookPath "%WORKBOOK%"
set "RC=%ERRORLEVEL%"

if not "%RC%"=="0" (
    echo.
    echo Refresh FAILED with exit code %RC%. See message above.
    echo Per SCRUM-31 there is no automated fallback - correct the discrepancy
    echo manually via paperwork, then re-run this refresh.
)

endlocal & exit /b %RC%
