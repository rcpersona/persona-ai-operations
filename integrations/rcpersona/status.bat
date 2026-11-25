@echo off
node --use-system-ca "%~dp0cli.mjs"
exit /b %errorlevel%
