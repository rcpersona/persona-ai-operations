@echo off
setlocal
node --use-system-ca -e "if (+process.versions.node.split('.')[0] < 22) process.exit(1)"
if errorlevel 1 (
    echo Install Node.js 22.19 or later and run setup again.
    exit /b 1
)
where codex >nul 2>&1
if errorlevel 1 (
    echo Install the Codex CLI and add it to PATH.
    exit /b 1
)
call npm --prefix "%~dp0." ci --ignore-scripts
if errorlevel 1 exit /b 1
call codex mcp add rcpersona -- node --use-system-ca "%~dp0mcp.mjs"
exit /b %errorlevel%
