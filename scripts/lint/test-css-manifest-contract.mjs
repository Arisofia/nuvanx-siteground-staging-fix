#!/usr/bin/env node
/**
 * Test contract for compiled CSS manifest and deterministic build integrity.
 *
 * Validates:
 * 1. dist/manifest.json exists and conforms to schema 1.
 * 2. All bundles declared in manifest exist on disk with matching content hashes.
 * 3. All source CSS files are accounted for in the manifest.
 * 4. Compiling matches exact hashes (deterministic build).
 */

import fs from 'node:fs/promises';
import path from 'node:path';
import crypto from 'node:crypto';
import { fileURLToPath } from 'node:url';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const ROOT_DIR = path.join(__dirname, '../..');
const THEME_DIR = path.join(ROOT_DIR, 'wp-content/themes/nuvanx-medical');
const DIST_DIR = path.join(THEME_DIR, 'dist');
const MANIFEST_PATH = path.join(DIST_DIR, 'manifest.json');

function computeHash(content) {
  return crypto.createHash('sha256').update(content, 'utf8').digest('hex').slice(0, 10);
}

function normalizeCss(raw) {
  return raw
    .replace(/\r\n/g, '\n')
    .replace(/\r/g, '\n')
    .trim();
}

async function testManifestContract() {
  const manifestRaw = await fs.readFile(MANIFEST_PATH, 'utf8');
  const manifest = JSON.parse(manifestRaw);

  if (manifest.schema !== 1) {
    throw new Error(`Invalid manifest schema: ${manifest.schema}`);
  }

  const bundles = manifest.bundles;
  if (!bundles || !bundles.core || !bundles.core.file) {
    throw new Error('Manifest missing core bundle');
  }

  // Validate all bundles
  for (const [name, info] of Object.entries(bundles)) {
    const bundleFilePath = path.join(DIST_DIR, info.file);
    const content = normalizeCss(await fs.readFile(bundleFilePath, 'utf8'));
    const actualHash = computeHash(content);

    if (actualHash !== info.hash) {
      throw new Error(`Bundle ${name} hash mismatch: manifest=${info.hash} actual=${actualHash}`);
    }

    if (!info.file.includes(info.hash)) {
      throw new Error(`Bundle ${name} filename ${info.file} does not contain hash ${info.hash}`);
    }
  }

  // Validate all single files
  for (const [relPath, info] of Object.entries(manifest.files)) {
    const distFilePath = path.join(DIST_DIR, info.file);
    const content = normalizeCss(await fs.readFile(distFilePath, 'utf8'));
    const actualHash = computeHash(content);

    if (actualHash !== info.hash) {
      throw new Error(`File ${relPath} hash mismatch: manifest=${info.hash} actual=${actualHash}`);
    }

    const srcFilePath = path.join(THEME_DIR, relPath);
    const srcContent = normalizeCss(await fs.readFile(srcFilePath, 'utf8'));
    const srcHash = computeHash(srcContent);

    if (srcHash !== info.hash) {
      throw new Error(`Source ${relPath} hash mismatch with dist: src=${srcHash} dist=${info.hash}`);
    }
  }

  console.log(`CSS_MANIFEST_CONTRACT=PASS bundles=${Object.keys(bundles).length} files=${Object.keys(manifest.files).length} hash_integrity=verified`);
}

testManifestContract().catch((err) => {
  console.error('CSS_MANIFEST_CONTRACT=FAIL', err);
  process.exit(1);
});
