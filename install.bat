@echo off
REM ============================================================
REM  Pia's Laundry Shop - One-click database setup
REM
REM  Works with XAMPP, Laragon and WAMP.
REM  Auto-detects mysql.exe / mariadb.exe and imports
REM  database\laundry_pos.sql
REM
REM  Before running: make sure Apache and MySQL are STARTED
REM  (XAMPP Control Panel, or Laragon "Start All").
REM ============================================================

setlocal EnableDelayedExpansion

REM ---------- Connection settings (XAMPP + Laragon defaults) ----------
set DB_HOST=localhost
set DB_USER=root
set DB_PASS=
set DB_NAME=laundry_pos

set SQL_FILE=%~dp0database\laundry_pos.sql
set MYSQL_EXE=
set FOUND_IN=

REM ---------- 0) Manual override ----------
REM Auto-detection below covers XAMPP, Laragon and WAMP. If your MySQL is
REM somewhere else, remove the "REM" from the next line and edit the path:
REM set "MYSQL_BIN=D:\xampp\mysql\bin"

if defined MYSQL_BIN if exist "!MYSQL_BIN!\mysql.exe" (
    set "MYSQL_EXE=!MYSQL_BIN!\mysql.exe"
    set "FOUND_IN=MYSQL_BIN override"
)
if defined MYSQL_BIN if exist "!MYSQL_BIN!\mariadb.exe" (
    set "MYSQL_EXE=!MYSQL_BIN!\mariadb.exe"
    set "FOUND_IN=MYSQL_BIN override"
)

REM ---------- 1) XAMPP ----------
if not defined MYSQL_EXE if exist "C:\xampp\mysql\bin\mysql.exe" (
    set "MYSQL_EXE=C:\xampp\mysql\bin\mysql.exe"
    set "FOUND_IN=XAMPP"
)
if not defined MYSQL_EXE if exist "C:\xampp\mysql\bin\mariadb.exe" (
    set "MYSQL_EXE=C:\xampp\mysql\bin\mariadb.exe"
    set "FOUND_IN=XAMPP MariaDB"
)

REM ---------- 2) Laragon (versioned folder) ----------
if not defined MYSQL_EXE (
    for /d %%D in ("C:\laragon\bin\mysql\mysql-*") do (
        if exist "%%~D\bin\mysql.exe" (
            set "MYSQL_EXE=%%~D\bin\mysql.exe"
            set "FOUND_IN=Laragon"
        )
    )
)

REM ---------- 3) WAMP ----------
if not defined MYSQL_EXE (
    for /d %%D in ("C:\wamp64\bin\mysql\mysql*") do (
        if exist "%%~D\bin\mysql.exe" (
            set "MYSQL_EXE=%%~D\bin\mysql.exe"
            set "FOUND_IN=WAMP64"
        )
    )
)
if not defined MYSQL_EXE (
    for /d %%D in ("C:\wamp\bin\mysql\mysql*") do (
        if exist "%%~D\bin\mysql.exe" (
            set "MYSQL_EXE=%%~D\bin\mysql.exe"
            set "FOUND_IN=WAMP"
        )
    )
)

REM ---------- 4) Anywhere on PATH ----------
if not defined MYSQL_EXE (
    for /f "delims=" %%P in ('where mysql.exe 2^>nul') do (
        if not defined MYSQL_EXE (
            set "MYSQL_EXE=%%~fP"
            set "FOUND_IN=system PATH"
        )
    )
)

REM ---------- Report ----------
echo.
echo ============================================================
echo   Pia's Laundry Shop - Database Setup
echo ============================================================
echo.

if not defined MYSQL_EXE (
    echo [ERROR] Could not find mysql.exe in any of these locations:
    echo.
    echo     C:\xampp\mysql\bin
    echo     C:\laragon\bin\mysql\mysql-*\bin
    echo     C:\wamp64\bin\mysql\mysql*\bin
    echo     system PATH
    echo.
    echo Fix it by editing this file and changing the MYSQL_BIN line
    echo near the top to your real folder, for example:
    echo.
    echo     set MYSQL_BIN=D:\xampp\mysql\bin
    echo.
    echo Alternatively import manually via phpMyAdmin:
    echo     http://localhost/phpmyadmin  ^>  Import  ^>  database\laundry_pos.sql
    echo.
    pause
    exit /b 1
)

echo   MySQL client : !MYSQL_EXE!
echo   Detected     : !FOUND_IN!
echo   Host         : %DB_HOST%
echo   User         : %DB_USER%
echo   Database     : %DB_NAME%
echo   SQL file     : %SQL_FILE%
echo.

if not exist "%SQL_FILE%" (
    echo [ERROR] SQL file not found: %SQL_FILE%
    echo Make sure this .bat file is still inside the project folder.
    pause
    exit /b 1
)

REM ---------- Build the password argument only when needed ----------
set "PASSARG="
if not "%DB_PASS%"=="" set "PASSARG=-p%DB_PASS%"

echo Importing... (this drops and recreates all tables)
echo.

"%MYSQL_EXE%" -h %DB_HOST% -u %DB_USER% %PASSARG% < "%SQL_FILE%"

if not "%errorlevel%"=="0" (
    echo.
    echo [ERROR] Import failed.
    echo.
    echo Most common causes:
    echo   1. MySQL is not running - start it in the XAMPP Control Panel
    echo      or click "Start All" in Laragon.
    echo   2. Wrong password - if your root user has a password, edit the
    echo      DB_PASS line near the top of this file.
    echo   3. Port 3306 is already used by another MySQL service.
    echo.
    pause
    exit /b 1
)

REM ---------- Verify ----------
echo.
"%MYSQL_EXE%" -h %DB_HOST% -u %DB_USER% %PASSARG% -N -B -e "SELECT CONCAT('Tables created : ', COUNT(*)) FROM information_schema.tables WHERE table_schema='%DB_NAME%';" 2>nul
"%MYSQL_EXE%" -h %DB_HOST% -u %DB_USER% %PASSARG% -N -B -e "SELECT CONCAT('Sample orders  : ', COUNT(*)) FROM %DB_NAME%.orders;" 2>nul
"%MYSQL_EXE%" -h %DB_HOST% -u %DB_USER% %PASSARG% -N -B -e "SELECT CONCAT('Shop name      : ', `value) FROM %DB_NAME%.settings WHERE `key='shop_name';" 2>nul

echo.
echo ============================================================
echo   [OK] Database imported successfully.
echo ============================================================
echo.
echo   Copy the project folder to your web root if you have not:
echo       XAMPP    :  C:\xampp\htdocs\laundry-pos
echo       Laragon  :  C:\laragon\www\laundry-pos
echo.
echo   Then open:   http://localhost/laundry-pos/
echo.
echo   Log in with:
echo       admin / admin123    (Administrator)
echo       staff / staff123    (Cashier)
echo.
pause
endlocal
