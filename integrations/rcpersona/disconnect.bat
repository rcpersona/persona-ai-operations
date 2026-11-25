@echo off
node --use-system-ca "%~dp0cli.mjs" disconnect
exit /b %errorlevel%
