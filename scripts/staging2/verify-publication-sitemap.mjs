#!/usr/bin/env node
/**
 * Verifies that the public Yoast sitemap exactly respects the canonical
 * publication manifest. This runs after cache purge in Staging2 so a green
 * deployment cannot be marked eligible while indexable routes are absent.
 */

import { readFileSync } from 'node:fs';

const baseUrl = process.env.BASE_URL || 'https://staging2.nuvanx.com';
const expectedHost = process.env.EXPECTED_HOST || 'staging2.nuvanx.com';
const retries = Number.parseInt(process.env.SITEMAP_FETCH_RETRIES || '4', 10);
const retryDelayMs = Number.parseInt(process.env.SITEMAP_FETCH_RETRY_DELAY_MS || '1500', 10);

const base = new URL(baseUrl);
if (base.protocol !== 'https:' || base.hostname !== expectedHost) {
  throw new Error(`Invalid sitemap verification boundary: ${baseUrl} (expected HTTPS host ${expectedHost})`);
}
if (!Number.isInteger(retries) || retries < 1 || !Number.isInteger(retryDelayMs) || retryDelayMs < 0) {
  throw new Error('Invalid sitemap retry configuration');
}

// Publication governance has one SSOT: the theme manifest consumed by the
// WordPress robots/indexable reconciliation. Do not validate sitemap coverage
// against the legacy Block C snapshot, which can lag new governed noindex routes.
const manifestUrl = new URL('../../wp-content/themes/nuvanx-medical/inc/data/publication-manifest.json', import.meta.url);
const manifestPayload = JSON.parse(readFileSync(manifestUrl, 'utf8'));
if (!manifestPayload || manifestPayload.schema !== 'nuvanx-publication-manifest' || !manifestPayload.routes || Array.isArray(manifestPayload.routes)) {
  throw new Error('Canonical publication manifest is empty or invalid');
}
const manifest = Object.entries(manifestPayload.routes).map(([path, entry]) => ({
  path,
  robots: entry?.robots,
}));
if (manifest.length === 0) {
  throw new Error('Canonical publication manifest contains no routes');
}

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

function decodeXml(value) {
  return value
    .replace(/&amp;/g, '&')
    .replace(/&lt;/g, '<')
    .replace(/&gt;/g, '>')
    .replace(/&quot;/g, '"')
    .replace(/&apos;/g, "'");
}

function extractLocs(xml, label) {
  const matches = [...xml.matchAll(/<loc\b[^>]*>\s*([^<]+?)\s*<\/loc>/gi)]
    .map((match) => decodeXml(match[1].trim()))
    .filter(Boolean);
  if (matches.length === 0) throw new Error(`${label} contains no <loc> values`);
  return matches;
}

function normalizedPath(url, label) {
  const parsed = new URL(url);
  if (parsed.protocol !== 'https:' || parsed.hostname !== expectedHost) {
    throw new Error(`${label} escaped the expected host: ${url}`);
  }
  const path = parsed.pathname || '/';
  return path === '/' || path.endsWith('/') || path.endsWith('.xml') ? path : `${path}/`;
}

async function fetchXml(url, label) {
  let lastError = null;
  for (let attempt = 1; attempt <= retries; attempt += 1) {
    try {
      const response = await fetch(url, {
        headers: {
          Accept: 'application/xml,text/xml,*/*',
          'Cache-Control': 'no-cache',
          Pragma: 'no-cache',
          'User-Agent': 'NUVANX-Staging-Sitemap-Contract/1.0',
        },
      });
      const body = await response.text();
      if (!response.ok) throw new Error(`HTTP ${response.status}`);
      if (!/^\s*<\?xml\b|^\s*<sitemapindex\b|^\s*<urlset\b/i.test(body)) {
        throw new Error('response is not XML');
      }
      return body;
    } catch (error) {
      lastError = error instanceof Error ? error.message : String(error);
      if (attempt < retries) await sleep(retryDelayMs);
    }
  }
  throw new Error(`Unable to read ${label} after ${retries} attempt(s): ${lastError}`);
}

const expectedIndexable = new Set();
const expectedNoindex = new Set();
for (const entry of manifest) {
  if (!entry || typeof entry.path !== 'string' || typeof entry.robots?.index !== 'boolean') {
    throw new Error('Canonical publication manifest contains an invalid robots record');
  }
  const path = entry.path;
  if (entry.robots.index) expectedIndexable.add(path);
  else expectedNoindex.add(path);
}
if (expectedIndexable.size + expectedNoindex.size !== manifest.length) {
  throw new Error('Canonical publication manifest contains duplicate paths');
}

const sitemapIndexUrl = new URL('/sitemap_index.xml', base).toString();
const sitemapIndex = await fetchXml(sitemapIndexUrl, 'sitemap index');
const sitemapDocuments = extractLocs(sitemapIndex, 'sitemap index');
const relevantSitemapDocuments = sitemapDocuments.filter((url) => {
  try {
    return normalizedPath(url, 'sitemap document').endsWith('.xml');
  } catch {
    return false;
  }
});
if (relevantSitemapDocuments.length === 0) {
  throw new Error('Sitemap index has no same-origin XML documents');
}

const sitemapPaths = new Set();
for (const documentUrl of relevantSitemapDocuments) {
  const xml = await fetchXml(documentUrl, `sitemap document ${documentUrl}`);
  for (const url of extractLocs(xml, `sitemap document ${documentUrl}`)) {
    sitemapPaths.add(normalizedPath(url, `sitemap URL from ${documentUrl}`));
  }
}

const missingIndexable = [...expectedIndexable].filter((path) => !sitemapPaths.has(path)).sort((a, b) => a.localeCompare(b));
const presentNoindex = [...expectedNoindex].filter((path) => sitemapPaths.has(path)).sort((a, b) => a.localeCompare(b));

console.log(`SITEMAP_MANIFEST_TOTAL=${manifest.length}`);
console.log(`SITEMAP_INDEXABLE_EXPECTED=${expectedIndexable.size}`);
console.log(`SITEMAP_NOINDEX_EXPECTED=${expectedNoindex.size}`);
console.log(`SITEMAP_DOCUMENTS=${relevantSitemapDocuments.length}`);
console.log(`SITEMAP_URLS=${sitemapPaths.size}`);
console.log(`SITEMAP_INDEXABLE_MISSING=${missingIndexable.length}`);
console.log(`SITEMAP_NOINDEX_PRESENT=${presentNoindex.length}`);
if (missingIndexable.length > 0) console.error(`SITEMAP_MISSING_INDEXABLE_PATHS=${missingIndexable.join(',')}`);
if (presentNoindex.length > 0) console.error(`SITEMAP_NOINDEX_PRESENT_PATHS=${presentNoindex.join(',')}`);

if (missingIndexable.length > 0 || presentNoindex.length > 0) {
  process.exitCode = 1;
} else {
  console.log('SITEMAP_MANIFEST_COVERAGE=PASS');
}
