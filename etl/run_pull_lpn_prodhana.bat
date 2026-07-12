@echo off
setlocal EnableExtensions

set "SCRIPT_DIR=%~dp0"
set "PROJECT_DIR=%SCRIPT_DIR%.."
set "PHP_EXE=C:\xampp\php\php.exe"
set "LOG_DIR=%SCRIPT_DIR%logs"

if not exist "%PHP_EXE%" (
    echo PHP executable not found: %PHP_EXE%
    exit /b 1
)

if not exist "%LOG_DIR%" mkdir "%LOG_DIR%"
if errorlevel 1 (
    echo Could not create log directory: %LOG_DIR%
    exit /b 1
)

for /f %%I in ('powershell.exe -NoProfile -Command "Get-Date -Format yyyyMMdd"') do set "RUN_DATE=%%I"
if not defined RUN_DATE (
    echo Could not determine the log date.
    exit /b 1
)

set "LOG_FILE=%LOG_DIR%\pull_lpn_prodhana_%RUN_DATE%.log"

pushd "%PROJECT_DIR%"
if errorlevel 1 (
    echo Could not enter project directory: %PROJECT_DIR%>>"%LOG_FILE%"
    exit /b 1
)

echo [%date% %time%] Starting PRODHANA LPN pull.>>"%LOG_FILE%"

"%PHP_EXE%" "etl\source_check.php" --source=PRIMSBM >>"%LOG_FILE%" 2>&1
if errorlevel 1 (
    echo [%date% %time%] PRIMSBM check failed; pull aborted.>>"%LOG_FILE%"
    popd
    exit /b 2
)

"%PHP_EXE%" "etl\pull_lpn.php" --source=PRIMSBM --query=etl/queries/prodhana_lpn.sql --via=PRODHANA >>"%LOG_FILE%" 2>&1
set "PULL_EXIT=%ERRORLEVEL%"

if not "%PULL_EXIT%"=="0" (
    echo [%date% %time%] PRODHANA LPN pull failed with exit code %PULL_EXIT%.>>"%LOG_FILE%"
) else (
    echo [%date% %time%] PRODHANA LPN pull completed.>>"%LOG_FILE%"
)

popd
exit /b %PULL_EXIT%
