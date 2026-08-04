#!/usr/bin/env node
/**
 * CLI: ucpf-scan --url https://example.com --out report.json
 *
 * Exit codes (CI): 0 pass, 1 policy violation, 2 incomplete, 3 error
 * Profiles: --profile quick|standard|compliance
 */

import fs from 'node:fs';
import path from 'node:path';
import { runPrivacyScan } from './scanner.js';
import { discoverSitePaths } from './discover.js';
import { config } from './config.js';
import { EXIT, exitCodeForReport } from './exit-codes.js';

function usage() {
  console.log(`Usage:
  node src/cli.js --url <https://site> [--paths /,/contact] [--out report.json] [--profile standard] [--interact] [--no-discover] [--max-pages N]

  Windows-friendly (no flags):
  node src/cli.js <https://site> [paths] [out.json] [interact]

Examples:
  node src/cli.js --url https://example.com --out report.json
  node src/cli.js --url https://example.com --profile quick --out report.json
  node src/cli.js --url https://example.com --profile compliance --interact --recipe ./recipe.json

Options:
  --profile L     quick | standard (default) | compliance
  --interact      Heavy pass (maps/forms/video/a11y). Time-capped.
  --recipe FILE   Safe interaction recipe JSON (no purchase/submit).
  --baseline FILE Previous report JSON for drift comparison.
  --no-discover   Do not auto-crawl; only use --paths (default /).
  --max-pages N   Cap discovered/scanned pages (default env UCPF_SCANNER_MAX_PAGES or 100).
  --screenshots   Capture banner/reject/accept JPEG screenshots in the report.

Exit codes:
  0 pass · 1 policy violation (findings fail / consent leaks) · 2 incomplete · 3 error

Environment:
  UCPF_SCANNER_MAX_PAGES, UCPF_SCANNER_SETTLE_MS, UCPF_SCANNER_HEADED=1, UCPF_SCANNER_RECIPE
`);
}

function parseArgs(argv) {
  const out = {
    url: '',
    paths: ['/'],
    outFile: '',
    interact: false,
    help: false,
    discover: true,
    maxPages: 0,
    screenshots: false,
    pathsExplicit: false,
    profile: 'standard',
    recipeFile: '',
    baselineFile: '',
  };
  const positional = [];

  for (let i = 2; i < argv.length; i += 1) {
    const a = argv[i];
    if (a === '--url' || a === '-u') out.url = argv[++i] || '';
    else if (a === '--paths' || a === '-p') {
      out.pathsExplicit = true;
      out.paths = String(argv[++i] || '/')
        .split(',')
        .map((s) => s.trim())
        .filter(Boolean);
    } else if (a === '--out' || a === '-o') out.outFile = argv[++i] || '';
    else if (a === '--interact') out.interact = true;
    else if (a === '--no-discover') out.discover = false;
    else if (a === '--screenshots') out.screenshots = true;
    else if (a === '--max-pages') out.maxPages = Number(argv[++i] || 0) || 0;
    else if (a === '--profile') {
      const p = String(argv[++i] || 'standard').toLowerCase();
      out.profile = p === 'quick' || p === 'compliance' ? p : 'standard';
    } else if (a === '--recipe') out.recipeFile = argv[++i] || '';
    else if (a === '--baseline') out.baselineFile = argv[++i] || '';
    else if (a === '--help' || a === '-h') out.help = true;
    else if (a === '--') continue;
    else positional.push(a);
  }

  const pathBits = [];
  for (const p of positional) {
    if (p === 'interact' || p === '--interact') {
      out.interact = true;
      continue;
    }
    if (p === 'nodiscover' || p === '--no-discover') {
      out.discover = false;
      continue;
    }
    if (!out.url && /^https?:\/\//i.test(p)) {
      out.url = p;
      continue;
    }
    if (/\.json$/i.test(p) && !out.outFile) {
      out.outFile = p;
      continue;
    }
    if (p.startsWith('/') || p.includes(',')) {
      pathBits.push(...p.split(',').map((s) => s.trim()).filter(Boolean));
    }
  }
  if (pathBits.length) {
    out.pathsExplicit = true;
    out.paths = pathBits;
  }

  return out;
}

async function main() {
  const args = parseArgs(process.argv);
  if (args.help || !args.url) {
    usage();
    process.exit(args.help ? EXIT.PASS : EXIT.ERROR);
  }

  const maxPages = args.maxPages > 0 ? args.maxPages : Math.max(config.maxPagesPerScan, 100);
  let paths = args.paths;

  const onlyHome = !args.pathsExplicit || (paths.length === 1 && paths[0] === '/');
  if (args.discover && onlyHome) {
    console.error(`Discovering pages (sitemap + homepage links, max ${maxPages})…`);
    try {
      const found = await discoverSitePaths(args.url, { max: maxPages });
      if (found.length) {
        paths = found;
        console.error(
          `Discovered ${paths.length} path(s). First: ${paths.slice(0, 8).join(', ')}${paths.length > 8 ? '…' : ''}`
        );
      } else {
        console.error('Discovery found nothing beyond / — scanning homepage only.');
      }
    } catch (err) {
      console.error(`Discovery failed (${err.message || err}); falling back to /`);
      paths = ['/'];
    }
  } else if (onlyHome) {
    console.error('Tip: omit --no-discover (default) to crawl sitemap + links, or pass --paths /,/contact,/about');
  }

  let baseline = null;
  if (args.baselineFile && fs.existsSync(args.baselineFile)) {
    try {
      baseline = JSON.parse(fs.readFileSync(args.baselineFile, 'utf8'));
    } catch {
      console.error('Warning: could not parse --baseline file; continuing without drift.');
    }
  }

  console.error(
    `Scanning ${paths.length} page(s) [profile=${args.profile}]…` +
      (args.interact ? ' [interact]' : '')
  );
  const report = await runPrivacyScan({
    url: args.url,
    paths,
    options: {
      interact: args.interact,
      screenshots: args.screenshots,
      maxPages,
      profile: args.profile,
      recipeFile: args.recipeFile,
      baseline,
      onProgress: (p) => {
        if (p && p.message) {
          const pct = typeof p.percent === 'number' ? `${p.percent}%` : '';
          const step =
            p.step != null && p.total != null ? ` ${p.step}/${p.total}` : '';
          console.error(`[scan ${pct}${step}] ${p.message}`);
        }
      },
    },
  });
  const json = JSON.stringify(report, null, 2);

  const failCount = report.findings_summary?.fail || 0;
  const leakCount = Array.isArray(report.consent_leaks) ? report.consent_leaks.length : 0;
  if (failCount) {
    console.error(`Findings FAIL: ${failCount} (see findings[] — not a legal determination)`);
  }
  if (leakCount) {
    console.error(`Consent leaks flagged: ${leakCount} (legacy; prefer findings[])`);
  }
  if (report.compliance_score) {
    console.error(
      `Technical score: ${report.compliance_score.total}/100 (${report.compliance_score.grade}) — not a legal determination`
    );
  }
  console.error(`Pages in report: ${(report.pages || []).length}`);

  if (args.outFile) {
    const dest = path.resolve(args.outFile);
    fs.writeFileSync(dest, json, 'utf8');
    console.error(`Wrote ${dest}`);
  } else {
    process.stdout.write(json);
  }

  process.exit(exitCodeForReport(report));
}

main().catch((err) => {
  console.error(err.message || err);
  process.exit(EXIT.ERROR);
});
