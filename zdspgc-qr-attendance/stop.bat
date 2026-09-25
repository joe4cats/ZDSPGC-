@echo off
rem =============================================================================
rem  ZDSPGC Event QR Attendance System - stop the local dev server
rem
rem  Usage:  stop.bat [port]        (default port: 8080)
rem
rem  Stops the PHP dev server started by run-local.bat. MySQL/MariaDB is left
rem  running on purpose - other projects and tools share the same instance.
rem =============================================================================
setlocal EnableExtensions
set "PORT=%~1"
if "%PORT%"=="" set "PORT=8080"

set "APPPID="
for /f "tokens=5" %%P in ('netstat -ano -p tcp ^| findstr /r /c:":%PORT% .*LISTENING"') do if not defined APPPID set "APPPID=%%P"

if not defined APPPID (
  echo  Nothing is listening on port %PORT% - the dev server is not running.
  echo.
  pause
  exit /b 0
)

tasklist /FI "PID eq %APPPID%" | findstr /i "php.exe" >nul
if errorlevel 1 (
  echo  Port %PORT% is used by another program ^(PID %APPPID%^), not the ZDSPGC dev server.
  echo  Nothing was stopped.
  echo.
  pause
  exit /b 1
)

echo  Stopping the dev server ^(PID %APPPID%, port %PORT%^) ...
taskkill /PID %APPPID% /T /F >nul 2>nul
if errorlevel 1 (
  echo  Could not stop it - try running this file as Administrator.
  echo.
  pause
  exit /b 1
)

echo  Dev server stopped. MySQL is still running.
echo.
pause
exit /b 0
