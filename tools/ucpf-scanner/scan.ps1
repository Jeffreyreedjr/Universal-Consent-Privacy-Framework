# Easy local UCPF privacy scan (Windows).
# Usage:
#   .\scan.ps1 https://example.com
#       -> auto-discovers sitemap + homepage links (up to 100 pages by default)
#   .\scan.ps1 https://example.com /,/contact,/about
#       -> only those paths (no discovery)
#   .\scan.ps1 https://example.com -Interact
#   .\scan.ps1 https://example.com -MaxPages 200
#   .\scan.ps1 https://example.com -NoDiscover
#
# Note: PowerShell treats commas as array separators. This script accepts that
# and joins paths back into one CSV for the Node CLI.

param(
  [Parameter(Mandatory = $true, Position = 0)]
  [string]$Url,

  [Parameter(Position = 1)]
  [object]$Paths = "",

  [Parameter(Position = 2)]
  [string]$Out = "",

  [switch]$Interact,

  [switch]$NoDiscover,

  [int]$MaxPages = 0
)

$ErrorActionPreference = "Stop"
Set-Location $PSScriptRoot

# Normalize Paths: string, or PowerShell array from unquoted commas.
$pathArg = ""
$pathsExplicit = $false
if ($null -ne $Paths -and -not [string]::IsNullOrWhiteSpace([string]$Paths)) {
  $pathsExplicit = $true
  if ($Paths -is [System.Array]) {
    $pathArg = ($Paths | ForEach-Object { [string]$_ }) -join ","
  } else {
    $pathArg = [string]$Paths
  }
}

if (-not (Test-Path "node_modules\playwright")) {
  Write-Host "Installing dependencies (first run only)..." -ForegroundColor Yellow
  npm install
}

if (-not $Out) {
  $hostPart = ([Uri]$Url).Host -replace '[^a-zA-Z0-9.-]', '_'
  $stamp = Get-Date -Format "yyyyMMdd-HHmmss"
  $Out = "report-$hostPart-$stamp.json"
}

Write-Host "Scanning $Url ..." -ForegroundColor Cyan
if ($pathsExplicit) {
  Write-Host "Paths: $pathArg (explicit - no auto-discover)" -ForegroundColor DarkGray
} elseif ($NoDiscover) {
  Write-Host "Paths: / only (NoDiscover)" -ForegroundColor DarkGray
} else {
  Write-Host "Paths: auto-discover sitemap + homepage links" -ForegroundColor DarkGray
}
Write-Host "Output: $Out" -ForegroundColor DarkGray
if ($Interact) {
  Write-Host "Interact: on (maps/forms/video/a11y - time-capped)" -ForegroundColor DarkGray
}
if ($MaxPages -gt 0) {
  Write-Host "Max pages: $MaxPages" -ForegroundColor DarkGray
}
Write-Host ""

$cliArgs = @("src/cli.js", "--url", $Url, "--out", $Out)
if ($pathsExplicit) {
  $cliArgs += @("--paths", $pathArg)
  $cliArgs += "--no-discover"
} elseif ($NoDiscover) {
  $cliArgs += @("--paths", "/")
  $cliArgs += "--no-discover"
}
if ($Interact) {
  $cliArgs += "--interact"
}
if ($MaxPages -gt 0) {
  $cliArgs += @("--max-pages", "$MaxPages")
}

& node @cliArgs

if ($LASTEXITCODE -ne 0) {
  exit $LASTEXITCODE
}

$full = Resolve-Path $Out
Write-Host ""
Write-Host "Done: $full" -ForegroundColor Green
Write-Host "Import this JSON in WordPress: Cookie Scanner -> Import scan JSON" -ForegroundColor Green

if (Test-Path $full) {
  explorer.exe /select,$full
}
