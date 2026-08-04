# Rebuild data/open-cookie-database.min.json from upstream Open Cookie Database CSV.
# Source: https://github.com/jkwakman/Open-Cookie-Database (no runtime phone-home).
# Usage: .\tools\build-ocd.ps1

$ErrorActionPreference = "Stop"
$Root = Split-Path -Parent (Split-Path -Parent $MyInvocation.MyCommand.Path)
$CsvUrl = "https://raw.githubusercontent.com/jkwakman/Open-Cookie-Database/master/open-cookie-database.csv"
$TmpCsv = Join-Path $Root "_tmp_ocd.csv"
$OutJson = Join-Path $Root "data\open-cookie-database.min.json"

New-Item -ItemType Directory -Force -Path (Join-Path $Root "data") | Out-Null

Write-Host "Downloading Open Cookie Database CSV..."
curl.exe -sL $CsvUrl -o $TmpCsv
if (-not (Test-Path $TmpCsv) -or (Get-Item $TmpCsv).Length -lt 1000) {
	throw "Failed to download OCD CSV"
}

$py = @"
import csv, json, os
root = r'''$Root'''
src = os.path.join(root, '_tmp_ocd.csv')
out = os.path.join(root, 'data', 'open-cookie-database.min.json')
rows = []
with open(src, encoding='utf-8', errors='replace', newline='') as f:
	for r in csv.DictReader(f):
		name = (r.get('Cookie / Data Key name') or '').strip()
		if not name:
			continue
		entry = {
			'n': name,
			'p': (r.get('Platform') or '').strip(),
			'c': (r.get('Category') or '').strip(),
			'd': (r.get('Description') or '').strip(),
			'r': (r.get('Retention period') or '').strip(),
			'o': (r.get('Data Controller') or '').strip(),
			'w': 1 if str(r.get('Wildcard match') or '').strip() in ('1', 'true', 'True', 'yes') else 0,
		}
		if not entry['d'] and not entry['o']:
			continue
		rows.append(entry)
by = {}
for e in rows:
	key = ('w' if e['w'] else 'e') + '|' + e['n'].lower()
	prev = by.get(key)
	if not prev or len(e['d']) > len(prev['d']):
		by[key] = e
out_rows = list(by.values())
out_rows.sort(key=lambda e: (e['w'], e['n'].lower()))
meta = {
	'source': 'https://github.com/jkwakman/Open-Cookie-Database',
	'license': 'Open Cookie Database contributors',
	'count': len(out_rows),
	'cookies': out_rows,
}
os.makedirs(os.path.dirname(out), exist_ok=True)
with open(out, 'w', encoding='utf-8', newline='\n') as f:
	json.dump(meta, f, ensure_ascii=False, separators=(',', ':'))
print('Wrote', out, 'cookies=', len(out_rows), 'bytes=', os.path.getsize(out))
"@

$pyFile = Join-Path $Root "_tmp_build_ocd.py"
Set-Content -Path $pyFile -Value $py -Encoding UTF8
python $pyFile
Remove-Item -Force $pyFile, $TmpCsv -ErrorAction SilentlyContinue
Write-Host "Done."
