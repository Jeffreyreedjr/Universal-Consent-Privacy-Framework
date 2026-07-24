# Builds an installable WordPress plugin zip with POSIX paths (required by WP).
# Output: dist/universal-consent-privacy-framework.zip

$ErrorActionPreference = "Stop"
Add-Type -AssemblyName System.IO.Compression
Add-Type -AssemblyName System.IO.Compression.FileSystem

$Root = Split-Path -Parent $MyInvocation.MyCommand.Path
$PluginSlug = "universal-consent-privacy-framework"
$DistDir = Join-Path $Root "dist"
$StageDir = Join-Path $DistDir $PluginSlug
$ZipPath = Join-Path $DistDir "$PluginSlug.zip"

$ExcludeDirs = @(
    ".git",
    ".cursor",
    "dist",
    "tests",
    "node_modules",
    "vendor",
    ".wp-env",
    "agent-tools",
    "docs",
    "tools",
    ".github",
    ".wordpress-org"
)

$ExcludeFiles = @(
    ".gitignore",
    ".gitattributes",
    ".distignore",
    ".wp-env.json",
    "AGENTS.md",
    "CONTRIBUTING.md",
    "CODE_OF_CONDUCT.md",
    "SECURITY.md",
    "CHANGELOG.md",
    "README.md",
    "package.ps1",
    "package.sh",
    "package.json",
    "package-lock.json",
    "webpack.config.js"
)

function Should-Exclude([string]$relativePath) {
    $parts = $relativePath -split '[\\/]'
    foreach ($part in $parts) {
        if ($ExcludeDirs -contains $part) { return $true }
        if ($ExcludeFiles -contains $part) { return $true }
        if ($part -like "c__Users_*") { return $true }
        if ($part -like "_tmp_*") { return $true }
    }
    return $false
}

Write-Host "Packaging $PluginSlug..."

# Build admin React app when Node is available.
$PkgJson = Join-Path $Root "package.json"
if ((Test-Path $PkgJson) -and (Get-Command npm -ErrorAction SilentlyContinue)) {
    Write-Host "Building admin UI (npm run build)..."
    Push-Location $Root
    try {
        npm run build
        if ($LASTEXITCODE -ne 0) { throw "npm run build failed" }
    } finally {
        Pop-Location
    }
} else {
    Write-Host "Skipping npm build (npm or package.json missing). Ensure admin/build exists."
}

if (Test-Path $DistDir) {
    Remove-Item -Recurse -Force $DistDir
}
New-Item -ItemType Directory -Path $StageDir | Out-Null

# Copy plugin files into staged folder
Get-ChildItem -Path $Root -Force | ForEach-Object {
    if (Should-Exclude $_.Name) { return }
    Copy-Item -Path $_.FullName -Destination (Join-Path $StageDir $_.Name) -Recurse -Force
}

# Remove nested junk after copy
Get-ChildItem -Path $StageDir -Recurse -Force -Directory -ErrorAction SilentlyContinue |
    Where-Object { $_.Name -like "c__Users_*" } |
    Remove-Item -Recurse -Force -ErrorAction SilentlyContinue

Get-ChildItem -Path $StageDir -Recurse -Force -Include ".DS_Store", "Thumbs.db" -ErrorAction SilentlyContinue |
    Remove-Item -Force -ErrorAction SilentlyContinue

# Strip UTF-8 BOM from PHP files (BOM before <?php fatals on namespace).
Get-ChildItem -Path $StageDir -Recurse -Filter "*.php" -File -ErrorAction SilentlyContinue | ForEach-Object {
    $bytes = [System.IO.File]::ReadAllBytes($_.FullName)
    if ($bytes.Length -ge 3 -and $bytes[0] -eq 0xEF -and $bytes[1] -eq 0xBB -and $bytes[2] -eq 0xBF) {
        $trimmed = New-Object byte[] ($bytes.Length - 3)
        [Array]::Copy($bytes, 3, $trimmed, 0, $trimmed.Length)
        [System.IO.File]::WriteAllBytes($_.FullName, $trimmed)
        Write-Host ("Stripped UTF-8 BOM: " + $_.FullName.Substring($StageDir.Length))
    }
}

$bootstrap = Join-Path $StageDir "$PluginSlug.php"
if (-not (Test-Path $bootstrap)) {
    throw "Missing bootstrap file: $bootstrap"
}

# Build zip with forward-slash entry names (WordPress / ZipArchive compatible)
if (Test-Path $ZipPath) {
    Remove-Item -Force $ZipPath
}

$zip = [System.IO.Compression.ZipFile]::Open($ZipPath, [System.IO.Compression.ZipArchiveMode]::Create)
try {
    $files = Get-ChildItem -Path $StageDir -Recurse -File
    foreach ($file in $files) {
        $rel = $file.FullName.Substring($StageDir.Length).TrimStart('\', '/')
        if (Should-Exclude $rel) { continue }

        $entryName = ($PluginSlug + "/" + ($rel -replace '\\', '/'))
        [void][System.IO.Compression.ZipFileExtensions]::CreateEntryFromFile(
            $zip,
            $file.FullName,
            $entryName,
            [System.IO.Compression.CompressionLevel]::Optimal
        )
    }
}
finally {
    $zip.Dispose()
}

# Verify
$check = [System.IO.Compression.ZipFile]::OpenRead($ZipPath)
try {
    $main = $check.Entries | Where-Object { $_.FullName -eq "$PluginSlug/$PluginSlug.php" }
    if (-not $main) {
        throw "Zip verification failed: $PluginSlug/$PluginSlug.php not found"
    }
    Write-Host "Verified entry: $($main.FullName)"
}
finally {
    $check.Dispose()
}

$zipInfo = Get-Item $ZipPath
Write-Host ""
Write-Host "Done: $($zipInfo.FullName)"
Write-Host ("Size: {0:N1} KB" -f ($zipInfo.Length / 1KB))
Write-Host ""
Write-Host "Before uploading: delete any broken copy under wp-content/plugins/universal-consent-privacy-framework"
Write-Host "Then: Plugins → Add New → Upload Plugin → this zip → Activate"
