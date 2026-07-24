@echo off
REM Easy local scan ??? pass a URL:
REM   scan.cmd https://example.com
REM   scan.cmd https://example.com /,/contact
REM   scan.cmd https://example.com /,/contact -Interact

setlocal
cd /d "%~dp0"

if "%~1"=="" (
  echo Usage: scan.cmd https://example.com [paths] [outfile.json] [-Interact]
  echo Example: scan.cmd https://example.com/ /,/contact
  exit /b 1
)

powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0scan.ps1" %*

