@echo off
rem =============================================================================
rem  ZDSPGC Event QR Attendance System — local one-click launcher
rem  Double-click this file to start the app, then open the URL it prints.
rem
rem  It looks for PHP in this order:
rem    1) php on your PATH (XAMPP / php.net install)
rem    2) a portable copy in %TEMP%\zdspgc-tools\php
rem =============================================================================
setlocal
set "BASE=%~dp0"
set "PORT=8080"

set "PHPBIN="
where php >nul 2>nul && set "PHPBIN=php"
if "%PHPBIN%"=="" if exist "%TEMP%\zdspgc-tools\php\php.exe" set "PHPBIN=%TEMP%\zdspgc-tools\php\php.exe"
if "%PHPBIN%"=="" (
  echo.
  echo  PHP was not found.
  echo  Install PHP 8.1+ ^(or XAMPP^) and try again, or put php.exe in %%PATH%%.
  echo.
  pause
  exit /b 1
)

echo.
echo  Starting the ZDSPGC QR Attendance System...
echo  Open this URL in your browser:  http://localhost:%PORT%/
echo  First run? Open:                http://localhost:%PORT%/install.php
echo  Press Ctrl+C in this window to stop the server.
echo.

rem First run: the browser will be sent to install.php automatically.
start "" "http://localhost:%PORT%/install.php"
"%PHPBIN%" -S localhost:%PORT% -t "%BASE%"

pause
