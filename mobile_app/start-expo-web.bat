@echo off
setlocal
where npm.cmd >nul 2>nul
if errorlevel 1 (
  echo Node.js and npm are required. Install Node.js LTS, then reopen this file.
  pause
  exit /b 1
)
cd /d "%~dp0expo_app" || exit /b 1
if not exist node_modules\ (
  echo Installing Expo app dependencies...
  call npm.cmd ci
  if errorlevel 1 exit /b 1
)
call npm.cmd run web -- --clear
