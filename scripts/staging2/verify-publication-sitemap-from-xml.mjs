#!/usr/bin/env node
/**
 * Verifies that the Yoast sitemap exactly respects the canonical publication
 * manifest. Unlike verify-publication-sitemap.mjs, this script does NOT make
 * outbound HTTP requests. Instead it reads the combined XML content from the
 * SITEMAP_XML_CONTENT environment variable (all child sitemaps concatenated)
 * and the expected host from EXPECTED_HOST.
 *
 * This is used by the Staging2 CI pipeline to bypass SiteGround Antibot, which
 * blocks external HTTP requests from GitHub Actions runners. The XML is fetched
 * from inside the server via SSH and passed to this script.
 */

import { readFileSync } from 'node:fs';

const expectedHost = process.env.EXPECTED_HOST || 'staging2.nuvanx.com';
const xmlContent = process.env.SITEMAP_XML_CONTENT || '';

if (!xmlContent.trim()) {
  console.error('SITEMAP_XML_CONTENT environment variable is empty or missing');
  process.exit(1);
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

function decodeXml(value) {
  return value
    .replace(/&amp;/g, '&')
    .replace(/&lt;/g, '<')
    .replace(/&gt;/g, '>')
    .replace(/&quot;/g, '"')
    .replace(/&apos;/g, "'");
}

function normalizedPath(url) {
  try {
    const parsed = new URL(url);
    if (parsed.protocol !== 'https:' || parsed.hostname !== expectedHost) return null;
    const path = parsed.pathname || '/';
    return path === '/' || path.endsWith('/') || path.endsWith('.xml') ? path : `${path}/`;
  } catch {
    return null;
  }
}

const expectedIndexable = new Set();
const expectedNoindex = new Set();
for (const entry of manifest) {
  if (!entry || typeof entry.path !== 'string' || typeof entry.robots?.index !== 'boolean') {
    throw new Error('Canonical publication manifest contains an invalid robots record');
  }
  if (entry.robots.index) expectedIndexable.add(entry.path);
  else expectedNoindex.add(entry.path);
}
if (expectedIndexable.size + expectedNoindex.size !== manifest.length) {
  throw new Error('Canonical publication manifest contains duplicate paths');
}

const sitemapPaths = new Set();
const locMatches = [...xmlContent.matchAll(/<loc\b[^>]*>\s*([^<]+?)\s*<\/loc>/gi)];
for (const match of locMatches) {
  const url = decodeXml(match[1].trim());
  const path = normalizedPath(url);
  // Skip sitemap index entries (end with .xml)
  if (path && !path.endsWith('.xml')) {
    sitemapPaths.add(path);
  }
}

const missingIndexable = [...expectedIndexable].filter((path) => !sitemapPaths.has(path)).sort((a, b) => a.localeCompare(b));
const presentNoindex = [...expectedNoindex].filter((path) => sitemapPaths.has(path)).sort((a, b) => a.localeCompare(b));

console.log(`SITEMAP_MANIFEST_TOTAL=${manifest.length}`);
console.log(`SITEMAP_INDEXABLE_EXPECTED=${expectedIndexable.size}`);
console.log(`SITEMAP_NOINDEX_EXPECTED=${expectedNoindex.size}`);
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
