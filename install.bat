@echo off
setlocal EnableExtensions EnableDelayedExpansion
title Pia's Laundry Shop - Local Installation

REM ============================================================
REM  Pia's Laundry Shop - Local database installer
REM
REM  For a fresh XAMPP, Laragon, or WAMP installation.
REM  Imports database\laundry_pos.sql with delivery, driver, GCash,
REM  customer account, and offline-booking database support.
REM
REM  This affects only the LOCAL database on this laptop. It does not
REM  deploy to Railway or change the online Railway database.
REM ============================================================

set "DB_HOST=localhost"
set "DB_PORT=3306"
set "DB_USER=root"
set "DB_PASS="
set "DB_NAME=laundry_pos"
set "SQL_FILE=%~dp0database\laundry_pos.sql"
set "MYSQL_EXE="
set "FOUND_IN="

REM Optional override. Remove REM and edit if MySQL is installed elsewhere.
REM set "MYSQL_BIN=D:\xampp\mysql\bin"

REM ---------- Find MySQL or MariaDB ----------
if defined MYSQL_BIN if exist "!MYSQL_BIN!\mysql.exe" (
    set "MYSQL_EXE=!MYSQL_BIN!\mysql.exe"
    set "FOUND_IN=MYSQL_BIN override"
)
if not defined MYSQL_EXE if defined MYSQL_BIN if exist "!MYSQL_BIN!\mariadb.exe" (
    set "MYSQL_EXE=!MYSQL_BIN!\mariadb.exe"
    set "FOUND_IN=MYSQL_BIN override"
)
if not defined MYSQL_EXE if exist "C:\xampp\mysql\bin\mysql.exe" (
    set "MYSQL_EXE=C:\xampp\mysql\bin\mysql.exe"
    set "FOUND_IN=XAMPP"
)
if not defined MYSQL_EXE if exist "C:\xampp\mysql\bin\mariadb.exe" (
    set "MYSQL_EXE=C:\xampp\mysql\bin\mariadb.exe"
    set "FOUND_IN=XAMPP MariaDB"
)
if not defined MYSQL_EXE (
    for /d %%D in ("C:\laragon\bin\mysql\mysql-*") do (
        if not defined MYSQL_EXE if exist "%%~D\bin\mysql.exe" (
            set "MYSQL_EXE=%%~D\bin\mysql.exe"
            set "FOUND_IN=Laragon"
        )
    )
)
if not defined MYSQL_EXE (
    for /d %%D in ("C:\wamp64\bin\mysql\mysql*") do (
        if not defined MYSQL_EXE if exist "%%~D\bin\mysql.exe" (
            set "MYSQL_EXE=%%~D\bin\mysql.exe"
            set "FOUND_IN=WAMP64"
        )
    )
)
if not defined MYSQL_EXE (
    for /d %%D in ("C:\wamp\bin\mysql\mysql*") do (
        if not defined MYSQL_EXE if exist "%%~D\bin\mysql.exe" (
            set "MYSQL_EXE=%%~D\bin\mysql.exe"
            set "FOUND_IN=WAMP"
        )
    )
)
if not defined MYSQL_EXE (
    for /f "delims=" %%P in ('where mysql.exe 2^>nul') do (
        if not defined MYSQL_EXE (
            set "MYSQL_EXE=%%~fP"
            set "FOUND_IN=system PATH"
        )
    )
)
if not defined MYSQL_EXE (
    for /f "delims=" %%P in ('where mariadb.exe 2^>nul') do (
        if not defined MYSQL_EXE (
            set "MYSQL_EXE=%%~fP"
            set "FOUND_IN=system PATH"
        )
    )
)

echo.
echo ============================================================
echo          Pia's Laundry Shop - Local Installation
echo ============================================================
echo.
echo This installs a LOCAL copy for XAMPP, Laragon, or WAMP.
echo Your current Railway website and online database are not changed.
echo.

if not exist "%SQL_FILE%" (
    echo [ERROR] Database file was not found:
    echo %SQL_FILE%
    echo Keep install.bat in the main laundry-pias project folder.
    pause
    exit /b 1
)

if not defined MYSQL_EXE (
    echo [ERROR] mysql.exe or mariadb.exe was not found.
    echo.
    echo Install XAMPP, Laragon, or WAMP first. Then start MySQL and run this file again.
    echo If it is installed elsewhere, edit the MYSQL_BIN line near the top of this file.
    pause
    exit /b 1
)

echo MySQL client : !MYSQL_EXE!
echo Detected     : !FOUND_IN!
echo Host         : %DB_HOST%:%DB_PORT%
echo Database     : %DB_NAME%
echo SQL file     : %SQL_FILE%
echo.
echo Before continuing, start Apache and MySQL in XAMPP or Start All in Laragon.
echo.
echo WARNING: This deletes and recreates all tables in the LOCAL "%DB_NAME%" database.
echo It will remove existing local data but will not affect Railway.
set /p "CONFIRM=Type YES to continue: "
if /I not "%CONFIRM%"=="YES" (
    echo Installation cancelled. No database was changed.
    pause
    exit /b 0
)

set "PASSARG="
if not "%DB_PASS%"=="" set "PASSARG=-p%DB_PASS%"

echo.
echo Importing local database. Please wait...
"%MYSQL_EXE%" -h "%DB_HOST%" -P %DB_PORT% -u "%DB_USER%" %PASSARG% < "%SQL_FILE%"
if errorlevel 1 (
    echo.
    echo [ERROR] Import failed.
    echo.
    echo Check that MySQL is running, the port is correct, and DB_PASS at the top is correct.
    pause
    exit /b 1
)

echo.
echo Verifying imported database...
"%MYSQL_EXE%" -h "%DB_HOST%" -P %DB_PORT% -u "%DB_USER%" %PASSARG% -N -B -e "SELECT CONCAT('Tables created : ', COUNT(*)) FROM information_schema.tables WHERE table_schema='%DB_NAME%';" 2>nul
"%MYSQL_EXE%" -h "%DB_HOST%" -P %DB_PORT% -u "%DB_USER%" %PASSARG% -N -B -e "SELECT CONCAT('Services       : ', COUNT(*)) FROM %DB_NAME%.services;" 2>nul
"%MYSQL_EXE%" -h "%DB_HOST%" -P %DB_PORT% -u "%DB_USER%" %PASSARG% -N -B -e "SELECT CONCAT('Driver accounts: ', COUNT(*)) FROM %DB_NAME%.delivery_drivers;" 2>nul

echo.
echo ============================================================
echo [OK] Local database imported successfully.
echo ============================================================
echo.
echo Next steps for this laptop:
echo   1. Keep this folder inside your web root, for example:
echo      XAMPP   C:\xampp\htdocs\laundry-pias
echo      Laragon C:\laragon\www\laundry-pias
echo   2. Start Apache and MySQL.
echo   3. Open http://localhost/laundry-pias/
echo.
echo Online deployment:
echo   Railway hosts the deployed website, API, and online database.
echo   The online app keeps working when XAMPP is off. This installer is
 echo   only for a separate local copy or local development.
echo.
echo Expo mobile app:
echo   The latest Expo app uses the Railway HTTPS API. On a new laptop,
echo   install Node.js, open mobile_app\expo_app, then run npm.cmd install.
echo.
echo Change default admin and staff passwords after first local sign-in.
echo.
pause
endlocal