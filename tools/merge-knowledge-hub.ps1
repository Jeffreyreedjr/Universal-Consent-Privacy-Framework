# Merge one or more UCPF knowledge / contribution packs into a Git hub registry.json.
# No hosted DB — agency fleets push scrubbed JSON here and sites opt-in pull the raw URL.
#
# Usage:
#   .\tools\merge-knowledge-hub.ps1 -Inputs .\exports\*.json -Out .\docs\examples\agency-registry\registry.json
#   .\tools\merge-knowledge-hub.ps1 -Inputs site-a.json,site-b.json -Base .\registry.json -Out .\registry.json
#   .\tools\merge-knowledge-hub.ps1 ... -Force   # allow overwriting core vendor-catalog keys
#
# Schemas accepted: ucpf-registry-catalog/1.0, ucpf-cookie-knowledge-contribution/1.0, bare { services, cookies }

param(
	[Parameter(Mandatory = $true)]
	[string[]]$Inputs,

	[string]$Base = "",

	[Parameter(Mandatory = $true)]
	[string]$Out,

	[switch]$Force
)

$ErrorActionPreference = "Stop"
$Root = Split-Path -Parent (Split-Path -Parent $MyInvocation.MyCommand.Path)

function Get-CoreCatalogKeys {
	$dir = Join-Path $Root "assets\vendor-catalog"
	$keys = New-Object 'System.Collections.Generic.HashSet[string]' ([StringComparer]::OrdinalIgnoreCase)
	if (-not (Test-Path $dir)) { return $keys }
	Get-ChildItem $dir -Filter "*.json" | ForEach-Object {
		if ($_.Name -eq "plugin-map.json") { return }
		try {
			$j = Get-Content $_.FullName -Raw | ConvertFrom-Json
			if ($j.services) {
				foreach ($s in $j.services) {
					if ($s.key) { [void]$keys.Add([string]$s.key) }
				}
			}
		} catch { }
	}
	return $keys
}

function Resolve-InputFiles([string[]]$patterns) {
	$files = @()
	foreach ($p in $patterns) {
		if ($p -match '[\*\?]') {
			$files += @(Get-Item $p -ErrorAction SilentlyContinue | ForEach-Object { $_.FullName })
		} elseif (Test-Path $p) {
			$files += (Resolve-Path $p).Path
		} else {
			Write-Warning "Missing input: $p"
		}
	}
	return $files | Select-Object -Unique
}

function Normalize-Cookie($c) {
	if (-not $c) { return $null }
	$name = ""
	if ($c.name) { $name = [string]$c.name }
	elseif ($c.pattern) { $name = [string]$c.pattern }
	$name = $name.Trim()
	if (-not $name) { return $null }
	$pattern = if ($c.pattern) { [string]$c.pattern } else { $name }
	$cat = if ($c.category) { [string]$c.category } else { "analytics" }
	if ($cat -eq "necessary") { $cat = "preferences" }
	$treatment = if ($c.treatment) { [string]$c.treatment } else { "consent" }
	if ($treatment -eq "necessary") { $treatment = "consent" }
	return [ordered]@{
		name       = $name
		pattern    = $pattern
		purpose    = if ($c.purpose) { [string]$c.purpose } else { "" }
		retention  = if ($c.retention) { [string]$c.retention } else { "" }
		category   = $cat
		treatment  = $treatment
		provider   = if ($c.provider) { [string]$c.provider } else { "" }
		source     = if ($c.source) { [string]$c.source } else { "knowledge" }
	}
}

function Cookie-DedupeKey($c) {
	$p = if ($c.pattern) { $c.pattern } else { $c.name }
	return ([string]$p).ToLowerInvariant()
}

function Merge-Service($into, $from, $coreKeys, [bool]$allowCore) {
	$key = [string]$from.key
	if (-not $key) { return $into }
	if ($coreKeys.Contains($key) -and -not $allowCore) {
		Write-Host "Skip core key (use -Force to overwrite): $key"
		return $into
	}
	if (-not $into.ContainsKey($key)) {
		$into[$key] = $from
		return $into
	}
	$cur = $into[$key]
	# Merge patterns
	foreach ($field in @("script_patterns", "cookie_patterns", "iframe_patterns")) {
		$a = @()
		if ($cur.$field) { $a += @($cur.$field) }
		if ($from.$field) { $a += @($from.$field) }
		$cur.$field = @($a | Where-Object { $_ } | Select-Object -Unique)
	}
	# Merge cookies by pattern/name
	$by = @{}
	if ($cur.cookies) {
		foreach ($c in $cur.cookies) {
			$nc = Normalize-Cookie $c
			if ($nc) { $by[(Cookie-DedupeKey $nc)] = $nc }
		}
	}
	if ($from.cookies) {
		foreach ($c in $from.cookies) {
			$nc = Normalize-Cookie $c
			if (-not $nc) { continue }
			$dk = Cookie-DedupeKey $nc
			if (-not $by.ContainsKey($dk)) {
				$by[$dk] = $nc
			} else {
				# Prefer longer purpose
				if (($nc.purpose.Length) -gt ($by[$dk].purpose.Length)) {
					$by[$dk] = $nc
				}
			}
		}
	}
	$cur.cookies = @($by.Values)
	# Refresh cookie_patterns from cookies if empty
	if (-not $cur.cookie_patterns -or $cur.cookie_patterns.Count -eq 0) {
		$cur.cookie_patterns = @($cur.cookies | ForEach-Object { $_.pattern } | Where-Object { $_ } | Select-Object -Unique)
	}
	if (-not $cur.description -and $from.description) { $cur.description = $from.description }
	if (-not $cur.provider -and $from.provider) { $cur.provider = $from.provider }
	$into[$key] = $cur
	return $into
}

function Ingest-Pack($pack, $servicesMap, $coreKeys, [bool]$allowCore) {
	if ($pack.services) {
		foreach ($svc in $pack.services) {
			if (-not $svc.key) { continue }
			$copy = [ordered]@{
				key              = [string]$svc.key
				name             = if ($svc.name) { [string]$svc.name } else { [string]$svc.key }
				provider         = if ($svc.provider) { [string]$svc.provider } else { "" }
				category         = if ($svc.category) { [string]$svc.category } else { "analytics" }
				treatment        = if ($svc.treatment) { [string]$svc.treatment } else { "consent" }
				description      = if ($svc.description) { [string]$svc.description } else { "" }
				script_patterns  = @($(if ($svc.script_patterns) { $svc.script_patterns } else { @() }))
				cookie_patterns  = @($(if ($svc.cookie_patterns) { $svc.cookie_patterns } else { @() }))
				cookies          = @($(if ($svc.cookies) { $svc.cookies } else { @() }))
				iframe_patterns  = @($(if ($svc.iframe_patterns) { $svc.iframe_patterns } else { @() }))
				default_blocking = if ($null -ne $svc.default_blocking) { [bool]$svc.default_blocking } else { $true }
			}
			if ($copy.category -eq "necessary") {
				$copy.category = "preferences"
				$copy.treatment = "consent"
			}
			$servicesMap = Merge-Service $servicesMap $copy $coreKeys $allowCore
		}
	}
	# Contribution packs / flat cookies → bucket by provider
	$flat = @()
	if ($pack.cookies) { $flat += @($pack.cookies) }
	if ($pack.cookie_patterns -and -not $pack.cookies) {
		foreach ($p in $pack.cookie_patterns) {
			$flat += @{ name = [string]$p; pattern = [string]$p; category = "analytics"; treatment = "consent" }
		}
	}
	foreach ($c in $flat) {
		$nc = Normalize-Cookie $c
		if (-not $nc) { continue }
		$prov = if ($nc.provider) { $nc.provider } else { "Site knowledge" }
		$svcKey = "knowledge_" + ($prov.ToLowerInvariant() -replace '[^a-z0-9]+', '_').Trim('_')
		if (-not $svcKey -or $svcKey -eq "knowledge_") { $svcKey = "ucpf_site_knowledge_cookies" }
		$bucket = [ordered]@{
			key              = $svcKey
			name             = $prov
			provider         = $prov
			category         = $nc.category
			treatment        = "consent"
			description      = "Merged from site knowledge exports (metadata only)."
			script_patterns  = @()
			cookie_patterns  = @($nc.pattern)
			cookies          = @($nc)
			iframe_patterns  = @()
			default_blocking = $true
		}
		$servicesMap = Merge-Service $servicesMap $bucket $coreKeys $allowCore
	}
	return $servicesMap
}

$coreKeys = Get-CoreCatalogKeys
Write-Host ("Core catalog keys protected: {0}" -f $coreKeys.Count)

$servicesMap = @{}
if ($Base -and (Test-Path $Base)) {
	Write-Host "Loading base: $Base"
	$basePack = Get-Content $Base -Raw | ConvertFrom-Json
	$servicesMap = Ingest-Pack $basePack $servicesMap $coreKeys $Force.IsPresent
}

$files = Resolve-InputFiles $Inputs
if (-not $files -or $files.Count -eq 0) {
	throw "No input files matched."
}
foreach ($f in $files) {
	Write-Host "Merging: $f"
	$pack = Get-Content $f -Raw | ConvertFrom-Json
	$servicesMap = Ingest-Pack $pack $servicesMap $coreKeys $Force.IsPresent
}

$outServices = @($servicesMap.Values | Sort-Object { $_.key })
$outObj = [ordered]@{
	schema     = "ucpf-registry-catalog/1.0"
	updated_at = (Get-Date).ToUniversalTime().ToString("o")
	note       = "Agency cookie knowledge hub - metadata only; never executable code; remote entries are never auto-necessary. Not a legal determination."
	services   = $outServices
}

$fullOut = $ExecutionContext.SessionState.Path.GetUnresolvedProviderPathFromPSPath($Out)
$outDir = Split-Path -Parent $fullOut
if ($outDir -and -not (Test-Path $outDir)) {
	New-Item -ItemType Directory -Force -Path $outDir | Out-Null
}
$json = $outObj | ConvertTo-Json -Depth 12
[System.IO.File]::WriteAllText($fullOut, $json)
Write-Host ("Wrote {0} services -> {1}" -f $outServices.Count, $fullOut)
