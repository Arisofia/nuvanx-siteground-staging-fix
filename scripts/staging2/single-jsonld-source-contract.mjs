import fs from 'node:fs/promises';
import path from 'node:path';
import { createHash } from 'node:crypto';
import { spawnSync } from 'node:child_process';
import { DEFAULT_ROUTES } from './shared-routes.mjs';

const SSH_BIN = '/usr/bin/ssh';
const ALLOWED_ALIASES = new Set(['nvx-staging2', 'nvx-staging2-pr']);

function assertConfig(host, sha, alias) {
  if (!/^[a-z0-9.-]+$/.test(host)) throw new Error('EXPECTED_HOST contains unsupported characters');
  if (!/^[0-9a-f]{40}$/.test(sha)) throw new Error('EXPECTED_SHA must be a full lowercase SHA');
  if (!ALLOWED_ALIASES.has(alias)) throw new Error(`Unsupported ORIGIN_SSH_ALIAS: ${alias}`);
}

function assertRoute(route) {
  if (typeof route !== 'string' || !route.startsWith('/')) throw new Error(`Invalid route: ${route}`);
  // Only allow alphanumeric, dash, dot, slash
  if (!/^[A-Za-z0-9_./-]+$/.test(route)) throw new Error(`Route contains unsupported characters: ${route}`);
}

async function fetchOriginHtml(route, host, alias) {
  assertRoute(route);
  const remoteScript = [
    'set -Eeuo pipefail',
    'url="https://${EXPECTED_HOST}${ROUTE}"',
    'set +e',
    'output="$(curl -kS -L --max-redirs 5 --max-time 45 -b "wpSGCacheBypass=1" -H \'Cache-Control: no-cache\' -H \'Pragma: no-cache\' -H \'Accept: text/html,application/xhtml+xml\' -A \'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 NUVANX-Single-JSONLD-Contract/1.0\' -w "\\nNVX_HTTP_STATUS:%{http_code}\\n" "$url" 2>/dev/null)"',
    'curl_rc=$?',
    'if [[ "$curl_rc" -ne 0 ]] || echo "$output" | grep -qE "NVX_HTTP_STATUS:(000|403|503)"; then',
    '  output="$(curl -kS -L --max-redirs 5 --max-time 45 --resolve "${EXPECTED_HOST}:443:127.0.0.1" -b "wpSGCacheBypass=1" -H \'Cache-Control: no-cache\' -H \'Pragma: no-cache\' -H \'Accept: text/html,application/xhtml+xml\' -A \'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 NUVANX-Single-JSONLD-Contract/1.0\' -w "\\nNVX_HTTP_STATUS:%{http_code}\\n" "$url" 2>/dev/null)"',
    'fi',
    'printf "%s" "$output"',
    '',
  ].join('\n');
  const remoteCommand = `EXPECTED_HOST='${host}' ROUTE='${route}' bash -se`;
  const result = spawnSync(
    SSH_BIN,
    ['-o', 'BatchMode=yes', '-o', 'ConnectTimeout=8', '-o', 'ConnectionAttempts=1', '--', alias, remoteCommand],
    { input: remoteScript, encoding: 'utf8', timeout: 60000, maxBuffer: 8 * 1024 * 1024 }
  );
  if (result.error) throw result.error;
  if (result.status !== 0) {
    const detail = String(result.stderr || '').trim();
    throw new Error(`Origin fetch command failed for ${route}: ${detail || `exit=${result.status}`}`);
  }
  const stdout = String(result.stdout || '');

  const httpStatusMatch = stdout.match(/NVX_HTTP_STATUS:(\d{3})/);
  const httpStatus = httpStatusMatch ? parseInt(httpStatusMatch[1], 10) : 0;
  if (httpStatus !== 200) {
    throw new Error(`Origin fetch failed for ${route}: expected HTTP 200, got ${httpStatus}`);
  }
  const markerIndex = stdout.lastIndexOf('NVX_HTTP_STATUS:');
  return markerIndex >= 0 ? stdout.slice(0, markerIndex).trim() : stdout;
}

function deploySha(html) {
  const tags = html.match(/<meta\b[^>]*>/gi) || [];
  for (const tag of tags) {
    if (!/\bname\s*=\s*["']nvx-deploy-sha["']/i.test(tag)) continue;
    const match = tag.match(/\bcontent\s*=\s*["']([^"']+)["']/i);
    return match ? match[1].trim() : '';
  }
  return '';
}

function jsonLdBlocks(html) {
  const blocks = [];
  const regex = /<script\b([^>]*)>([\s\S]*?)<\/script>/gi;
  let match;
  while ((match = regex.exec(html)) !== null) {
    const attrs = match[1] || '';
    // Match type attribute with optional quotes to align with rendered-schema-contract.mjs and content-hygiene PCRE.
    if (/\btype\s*=\s*["']?application\/ld\+json["']?/i.test(attrs)) {
      const content = (match[2] || '').trim();
      if (content) blocks.push({ attrs: attrs.trim(), content });
    }
  }
  return blocks;
}

function schemaTypes(value) {
  const values = Array.isArray(value) ? value : value == null ? [] : [value];
  // Restrict to string-like values to avoid unhelpful entries like '[object Object]'
  return values.filter((item) => typeof item === 'string').filter(Boolean);
}

function summarizeJsonLdBlock(block, index) {
  const summary = {
    index,
    bytes: Buffer.byteLength(block.content, 'utf8'),
    sha256: createHash('sha256').update(block.content, 'utf8').digest('hex'),
    attributes: block.attrs.slice(0, 500),
    context: null,
    topLevelTypes: [],
    hasGraph: false,
    graphTypes: [],
    graphIds: [],
    parseError: null,
  };

  try {
    const parsed = JSON.parse(block.content);
    summary.context = parsed?.['@context'] ?? null;
    summary.topLevelTypes = schemaTypes(parsed?.['@type']);
    if (Array.isArray(parsed?.['@graph'])) {
      summary.hasGraph = true;
      const graphTypes = new Set();
      const graphIds = [];
      for (const node of parsed['@graph']) {
        if (!node || typeof node !== 'object' || Array.isArray(node)) continue;
        schemaTypes(node['@type']).forEach((type) => graphTypes.add(type));
        if (typeof node['@id'] === 'string' && graphIds.length < 30) graphIds.push(node['@id']);
      }
      summary.graphTypes = [...graphTypes].sort((a, b) => a.localeCompare(b));
      summary.graphIds = graphIds;
    }
  } catch (error) {
    summary.parseError = String(error?.message || error);
  }

  return summary;
}

export async function runSingleJsonLdSourceContract(options = {}) {
  const host = options.expectedHost || process.env.EXPECTED_HOST || 'staging2.nuvanx.com';
  const sha = String(options.expectedSha || process.env.EXPECTED_SHA || '').trim();
  const alias = options.originSshAlias || process.env.ORIGIN_SSH_ALIAS || 'nvx-staging2';
  const outputDir = path.resolve(options.outputDir || 'scripts/staging2/artifacts');
  const routes = options.routes || DEFAULT_ROUTES;
  assertConfig(host, sha, alias);
  routes.forEach(assertRoute);
  await fs.mkdir(outputDir, { recursive: true });

  const report = { schema: 2, checkedAt: new Date().toISOString(), host, sha, routes: [], issues: [] };
  for (const route of routes) {
    try {
      const html = await fetchOriginHtml(route, host, alias);
      const actualSha = deploySha(html);
      const blocks = jsonLdBlocks(html);
      const blockSummaries = blocks.map((block, index) => summarizeJsonLdBlock(block, index));
      const item = { route, deploySha: actualSha, jsonLdBlocks: blocks.length, blockSummaries };
      report.routes.push(item);
      if (actualSha !== sha) report.issues.push(`${route}: deploy SHA mismatch actual=${actualSha || '(missing)'} expected=${sha}`);
      if (blocks.length !== 1) {
        report.issues.push(`${route}: expected exactly one application/ld+json block, found ${blocks.length}`);
        // Summarize duplicate blocks to keep CI logs readable while providing enough detail
        const summary = blockSummaries.map((s, i) => ({
          i,
          bytes: s.bytes,
          types: s.topLevelTypes.slice(0, 3),
          parseError: s.parseError ? 'INVALID_JSON' : null,
        }));
        console.error(`SINGLE_JSONLD_SOURCE_DIAGNOSTIC route=${route} blocks=${JSON.stringify(summary)}`);
      }
      if (blocks.length === 1) {
        const summary = blockSummaries[0];
        if (summary.parseError) {
          report.issues.push(`${route}: canonical JSON-LD block is invalid JSON: ${summary.parseError}`);
        } else if (!(summary.context === 'https://schema.org' || summary.context === 'http://schema.org' || summary.hasGraph)) {
          report.issues.push(`${route}: canonical JSON-LD block does not look like governed Schema.org graph`);
        }
      }
    } catch (error) {
      report.routes.push({ route, deploySha: '', jsonLdBlocks: 0, blockSummaries: [], error: String(error?.message || error) });
      report.issues.push(`${route}: ${String(error?.message || error)}`);
    }
  }

  report.pass = report.issues.length === 0;
  await fs.writeFile(path.join(outputDir, 'single-jsonld-source-contract.json'), `${JSON.stringify(report, null, 2)}\n`, 'utf8');
  if (!report.pass) {
    console.error(`SINGLE_JSONLD_SOURCE_CONTRACT=FAIL issues=${report.issues.length}`);
    report.issues.forEach((issue) => console.error(`- ${issue}`));
    throw new Error(`Single JSON-LD source contract failed with ${report.issues.length} issue(s)`);
  }
  console.log(`SINGLE_JSONLD_SOURCE_CONTRACT=PASS routes=${report.routes.length} sha=${sha}`);
  return report;
}
