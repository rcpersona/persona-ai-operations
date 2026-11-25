@echo off
powershell.exe -NoProfile -File "%~dp0credentials.ps1" connect
exit /b %errorlevel%
