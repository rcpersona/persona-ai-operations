@echo off
powershell.exe -NoProfile -NonInteractive -File "%~dp0credentials.ps1" disconnect
exit /b %errorlevel%
