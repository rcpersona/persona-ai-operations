@echo off
setlocal
if /I "%~1"=="--manual" (
    powershell.exe -NoProfile -File "%~dp0credentials.ps1" connect
    exit /b
)
call "%~dp0setup.bat"
if errorlevel 1 exit /b 1
node --use-system-ca "%~dp0cli.mjs" connect %*
exit /b %errorlevel%
