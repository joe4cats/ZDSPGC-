@echo off
rem =============================================================================
rem  ZDSPGC Event QR Attendance System - one-command local launcher
rem
rem  Double-click this file (or run "run-local.bat" in a terminal) and it will:
rem    1. find PHP 8.1+ on this PC (PATH, XAMPP, Laragon or a portable copy)
rem    2. start MySQL/MariaDB when it is not already running
rem    3. create the database and install/seed the tables when needed
rem    4. start the PHP dev server and open the sign-in page in your browser
rem       (login.php?fresh=1 - always lands on the login form, never the dashboard)
rem
rem  Usage:            run-local.bat [port]        (default port: 8080)
rem  Stop the server:  stop.bat [port]             (or close the server window)
rem
rem  Optional environment variables (for non-standard installs):
rem    ZDSPGC_PHP          full path to php.exe
rem    ZDSPGC_MYSQLD       full path to mysqld.exe
rem    ZDSPGC_MYSQL_INI    full path to the matching my.ini
rem    ZDSPGC_NO_BROWSER   set to 1 to keep the browser closed
rem    ZDSPGC_NO_PAUSE     set to 1 to finish without waiting for a key (scripts/tests)
rem =============================================================================
setlocal EnableExtensions
cd /d "%~dp0"
set "ROOT=%~dp0"
if "%ROOT:~-1%"=="\" set "ROOT=%ROOT:~0,-1%"
set "WORK=%TEMP%\zdspgc-launcher"
if not exist "%WORK%" mkdir "%WORK%" >nul 2>nul

set "PORT=%~1"
if "%PORT%"=="" set "PORT=8080"

echo.
echo  ============================================================
echo   ZDSPGC Event QR Attendance System - local launcher
echo  ============================================================
echo.

rem ---- 1. Find PHP 8.1+ -----------------------------------------------------
set "PHPBIN="
if defined ZDSPGC_PHP if exist "%ZDSPGC_PHP%" set "PHPBIN=%ZDSPGC_PHP%"
if not defined PHPBIN for /f "delims=" %%P in ('where php 2^>nul') do if not defined PHPBIN set "PHPBIN=%%P"
if not defined PHPBIN for %%D in (C D E F) do if not defined PHPBIN if exist "%%D:\xampp\php\php.exe" set "PHPBIN=%%D:\xampp\php\php.exe"
if not defined PHPBIN for /d %%D in ("C:\laragon\bin\php\*") do if not defined PHPBIN if exist "%%~fD\php.exe" set "PHPBIN=%%~fD\php.exe"
if not defined PHPBIN if exist "%TEMP%\zdspgc-tools\php\php.exe" set "PHPBIN=%TEMP%\zdspgc-tools\php\php.exe"
if not defined PHPBIN goto :no_php

"%PHPBIN%" -r "echo PHP_VERSION_ID;" > "%WORK%\phpver.txt" 2>nul
set "PHPVID="
set /p PHPVID=<"%WORK%\phpver.txt"
if not defined PHPVID goto :no_php
echo %PHPVID%| findstr /r "^[0-9][0-9]*$" >nul
if errorlevel 1 goto :no_php
if %PHPVID% LSS 80100 goto :old_php
echo  [1/4] PHP          %PHPBIN%

rem ---- Read the database settings straight from includes\config.php ---------
"%PHPBIN%" -r "require 'includes/config.php'; echo DB_DRIVER, ';', DB_HOST, ';', DB_PORT, ';', DB_NAME, ';', DB_USER, ';', DEMO_MODE ? '1' : '0';" > "%WORK%\dbcfg.txt" 2>"%WORK%\dbcfg.err"
set "CFG="
set /p CFG=<"%WORK%\dbcfg.txt"
if not defined CFG goto :no_config
for /f "tokens=1-6 delims=;" %%a in ("%CFG%") do (
  set "DBDRIVER=%%a"
  set "DBHOST=%%b"
  set "DBPORT=%%c"
  set "DBNAME=%%d"
  set "DBUSER=%%e"
  set "DEMOMODE=%%f"
)

rem ---- 2. MySQL / MariaDB ---------------------------------------------------
if /i not "%DBDRIVER%"=="mysql" goto :skip_mysql

echo  [2/4] MySQL        checking %DBHOST%:%DBPORT% ...
call :port_state %DBHOST% %DBPORT%
if /i "%PORTSTATE%"=="up" goto :mysql_up

set "MYSQLD="
set "MYSQLINI="
if defined ZDSPGC_MYSQLD if exist "%ZDSPGC_MYSQLD%" set "MYSQLD=%ZDSPGC_MYSQLD%"
if defined ZDSPGC_MYSQL_INI if exist "%ZDSPGC_MYSQL_INI%" set "MYSQLINI=%ZDSPGC_MYSQL_INI%"
if not defined MYSQLD for %%D in (C D E F) do if not defined MYSQLD if exist "%%D:\xampp\mysql\bin\mysqld.exe" set "MYSQLD=%%D:\xampp\mysql\bin\mysqld.exe"
if not defined MYSQLD for /f "delims=" %%M in ('where mysqld 2^>nul') do if not defined MYSQLD set "MYSQLD=%%M"
if not defined MYSQLD goto :no_mysqld
if not defined MYSQLINI for %%I in ("%MYSQLD%") do if exist "%%~dpImy.ini" set "MYSQLINI=%%~dpImy.ini"
if not defined MYSQLINI goto :no_mysql_ini

set "INIPORT="
for /f "tokens=2 delims==" %%A in ('findstr /r /i /c:"^port=" "%MYSQLINI%" 2^>nul') do if not defined INIPORT set "INIPORT=%%A"
set "INIPORT=%INIPORT: =%"
if defined INIPORT if not "%INIPORT%"=="%DBPORT%" goto :ini_port_mismatch

echo        starting "%MYSQLD%" ...
start "ZDSPGC MySQL (port %DBPORT%)" /min "%MYSQLD%" --defaults-file="%MYSQLINI%" --standalone

set /a TRIES=0
:wait_mysql
call :port_state %DBHOST% %DBPORT%
if /i "%PORTSTATE%"=="up" goto :mysql_up
set /a TRIES+=1
if %TRIES% GEQ 45 goto :mysql_timeout
>nul ping -n 2 127.0.0.1
goto :wait_mysql

:mysql_up
echo        MySQL is running on %DBHOST%:%DBPORT%
goto :db_setup

:skip_mysql
echo  [2/4] MySQL        not used (DB_DRIVER=%DBDRIVER%)

rem ---- 3. Database: create it + install/seed the tables when needed --------
:db_setup
echo  [3/4] Database     preparing "%DBNAME%" ...
"%PHPBIN%" includes\cli-setup.php
if errorlevel 1 goto :db_failed

rem ---- 4. Start the PHP dev server -----------------------------------------
echo  [4/4] Web server   http://localhost:%PORT%
call :port_state 127.0.0.1 %PORT%
if /i not "%PORTSTATE%"=="up" goto :start_server

rem Port already busy - make sure it is this system answering before reusing it.
call :app_responds
if /i not "%APPOK%"=="yes" goto :port_busy
echo        an existing server already answers on port %PORT% - reusing it
goto :server_ready

:start_server
start "ZDSPGC QR Attendance - dev server (port %PORT%)" "%PHPBIN%" -S 127.0.0.1:%PORT% -t "%ROOT%"

set /a TRIES=0
:wait_server
call :port_state 127.0.0.1 %PORT%
if /i "%PORTSTATE%"=="up" goto :server_ready
set /a TRIES+=1
if %TRIES% GEQ 20 goto :server_slow
>nul ping -n 2 127.0.0.1
goto :wait_server

:server_ready
rem Always land on the sign-in form - never straight on the admin dashboard.
rem fresh=1 signs out any session left over from a previous run (see login.php).
if not "%ZDSPGC_NO_BROWSER%"=="1" start "" "http://localhost:%PORT%/login.php?fresh=1"
echo.
echo  ============================================================
echo   The system is running:  http://localhost:%PORT%/login.php
echo   Server window:          "ZDSPGC QR Attendance - dev server"
echo   Stop it with:           stop.bat %PORT%
if "%DEMOMODE%"=="1" (
  echo.
  echo   Demo sign-ins (DEMO_MODE is on^):
  echo     admin    Admin@2026
  echo     officer  Officer@2026
  echo     faculty  Faculty@2026
)
echo  ============================================================
echo.
if not "%ZDSPGC_NO_PAUSE%"=="1" (
  echo  Press any key to close this launcher window...
  pause >nul
)
exit /b 0
rem ---- error messages -------------------------------------------------------
:no_php
echo  [X] PHP was not found on this PC.
echo      Install PHP 8.1+ or XAMPP, then run this file again.
echo      Advanced: set ZDSPGC_PHP to the full path of php.exe.
echo.
pause
exit /b 1

:old_php
echo  [X] This PHP is older than 8.1: %PHPBIN%
echo      The system needs PHP 8.1 or newer.
echo.
pause
exit /b 1

:no_config
echo  [X] Could not read includes\config.php with PHP:
type "%WORK%\dbcfg.err" 2>nul
echo.
pause
exit /b 1

:no_mysqld
echo  [X] MySQL/MariaDB is not running on %DBHOST%:%DBPORT% and mysqld.exe was
echo      not found in the usual places: XAMPP folders, then PATH.
echo      Start MySQL from the XAMPP Control Panel and run this file again, or
echo      set ZDSPGC_MYSQLD and ZDSPGC_MYSQL_INI to your server and its my.ini.
echo.
pause
exit /b 1

:no_mysql_ini
echo  [X] Found %MYSQLD% but no my.ini next to it.
echo      Set ZDSPGC_MYSQL_INI to the my.ini that belongs to this server.
echo.
pause
exit /b 1

:ini_port_mismatch
echo  [!] That my.ini listens on port %INIPORT%, but includes\config.php uses %DBPORT%.
echo      Align the two - or start the correct MySQL yourself - then run this file again.
echo.
pause
exit /b 1

:mysql_timeout
echo  [X] MySQL did not answer on %DBHOST%:%DBPORT% after 45 seconds.
echo      Check the minimized "ZDSPGC MySQL" window for the error message.
echo.
pause
exit /b 1

:db_failed
echo  [X] Database setup failed - see the message above.
echo.
pause
exit /b 1

:server_slow
echo  [!] The dev server did not answer on port %PORT% within 20 seconds.
echo      Open the "dev server" window to see the PHP error, then reload the page.
goto :server_ready

:port_busy
echo  [!] Port %PORT% is used by a different program, not this system.
echo      Run this file with another port, for example:  run-local.bat 8081
echo.
pause
exit /b 1

rem ---- helper ----------------------------------------------------------------
:port_state
rem  %1 = host, %2 = port   ->   sets PORTSTATE to up/down
"%PHPBIN%" -r "echo @fsockopen('%1', (int) '%2', $e, $s, 1) ? 'up' : 'down';" > "%WORK%\port.txt" 2>nul
set "PORTSTATE=down"
set /p PORTSTATE=<"%WORK%\port.txt"
exit /b 0

:app_responds
rem  sets APPOK to yes/no: does the server on this port look like this system?
"%PHPBIN%" -r "$c = @file_get_contents('http://127.0.0.1:%PORT%/login.php', false, stream_context_create(['http' => ['timeout' => 3]])); echo ($c !== false && strpos($c, 'ZDSPGC') !== false) ? 'yes' : 'no';" > "%WORK%\app.txt" 2>nul
set "APPOK=no"
set /p APPOK=<"%WORK%\app.txt"
exit /b 0

