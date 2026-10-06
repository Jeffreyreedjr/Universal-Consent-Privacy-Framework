#Requires -Version 5.1
<#
.SYNOPSIS
  Probe Cloudflare cache headers for a UCPF WordPress site.
.EXAMPLE
  .\tools\probe-cache-headers.ps1 -BaseUrl https://mybabybigfoot.com
#>
[CmdletBinding()]
param(
    [Parameter(Mandatory = $true)]
    [string] $BaseUrl
)

$ErrorActionPreference = "Stop"
$BaseUrl = $BaseUrl.TrimEnd("/")

function Get-InterestingHeaders([string] $Url, [hashtable] $ExtraHeaders = @{}) {
    $curlArgs = [System.Collections.Generic.List[string]]::new()
    [void]$curlArgs.Add("-sI")
    [void]$curlArgs.Add($Url)
    foreach ($k in $ExtraHeaders.Keys) {
        $headerLine = "{0}: {1}" -f $k, $ExtraHeaders[$k]
        [void]$curlArgs.Add("-H")
        [void]$curlArgs.Add($headerLine)
    }
    $raw = & curl.exe @($curlArgs.ToArray()) 2>$null
    $pick = $raw | Select-String -Pattern "^(HTTP/|cf-cache-status|Cache-Control|Age:|Content-Type|x-cache|last-modified|CDN-Cache-Control|Cloudflare-CDN-Cache-Control)"
    return [pscustomobject]@{
        Url     = $Url
        Headers = ($pick | ForEach-Object { $_.Line.Trim() }) -join " | "
    }
}

# Discover live UCPF asset URLs from homepage HTML
$htmlPath = Join-Path $env:TEMP ("ucpf-probe-" + [guid]::NewGuid().ToString() + ".html")
curl.exe -sL $BaseUrl -o $htmlPath | Out-Null
$html = Get-Content -LiteralPath $htmlPath -Raw
Remove-Item -LiteralPath $htmlPath -Force -ErrorAction SilentlyContinue

$ucpfVer = $null
if ($html -match 'universal-consent-privacy-framework/public/js/consent\.js\?ver=([^''"\s>]+)') {
    $ucpfVer = $Matches[1]
}

$urls = @(
    "$BaseUrl/",
    "$BaseUrl/?_ucpf=1",
    "$BaseUrl/cookie-policy/"
)
if ($ucpfVer) {
    $urls += @(
        "$BaseUrl/wp-content/plugins/universal-consent-privacy-framework/public/css/banner.css?ver=$ucpfVer",
        "$BaseUrl/wp-content/plugins/universal-consent-privacy-framework/public/js/consent.js?ver=$ucpfVer",
        "$BaseUrl/wp-content/plugins/universal-consent-privacy-framework/public/js/network-gate.js?ver=$ucpfVer"
    )
} else {
    $urls += "$BaseUrl/wp-content/plugins/universal-consent-privacy-framework/public/css/banner.css"
}

$urls += @(
    "$BaseUrl/wp-content/themes/hello-elementor/style.css?ver=7.1",
    "$BaseUrl/wp-content/plugins/elementor/assets/css/frontend.min.css?ver=4.2.3",
    "$BaseUrl/wp-content/uploads/elementor/css/post-448.css",
    "$BaseUrl/wp-content/uploads/2024/03/BabyBigfoot_SEO.png",
    "$BaseUrl/wp-content/smush-webp/2024/05/BabyBigfoot_logo-horizontal.png.webp",
    "$BaseUrl/wp-content/plugins/universal-consent-privacy-framework/assets/fonts/plus-jakarta-sans-latin-400-normal.woff2"
)

Write-Host "UCPF ?ver= stamp: $(if ($ucpfVer) { $ucpfVer } else { '(not found on homepage)' })"
Write-Host ""

$rows = @()
foreach ($u in $urls) {
    $rows += Get-InterestingHeaders -Url $u
}
$rows += Get-InterestingHeaders -Url "$BaseUrl/" -ExtraHeaders @{ Cookie = "ucpf_consent=v1%7Cprobe" }

$rows | Format-Table -AutoSize -Wrap
