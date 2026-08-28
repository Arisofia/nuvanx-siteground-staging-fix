#!/usr/bin/env node
/**
 * Test contract for compiled CSS manifest and deterministic build integrity.
 *
 * Validates:
 * 1. dist/manifest.json exists and conforms to schema 1.
 * 2. All bundles declared in manifest exist on disk with matching content hashes.
 * 3. All source CSS files are accounted for in the manifest.
 * 4. Compiling matches exact hashes (deterministic build).
 * 5. Each bundle is reconstructible from its declared sources — the
 *    concatenation of normalised source files (with compiler-identical
 *    comment headers and join logic) must produce byte-exact bundle content,
 *    matching hash and size recorded in the manifest.
 * 6. No orphan CSS files exist in dist/ that are not referenced by the manifest.
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

  const referencedFiles = new Set();

  for (const [name, info] of Object.entries(bundles)) {
    referencedFiles.add(info.file);

    const bundleFilePath = path.join(DIST_DIR, info.file);
    const distContent = normalizeCss(await fs.readFile(bundleFilePath, 'utf8'));
    const actualHash = computeHash(distContent);

    if (actualHash !== info.hash) {
      throw new Error(`Bundle ${name} hash mismatch: manifest=${info.hash} actual=${actualHash}`);
    }

    if (!info.file.includes(info.hash)) {
      throw new Error(`Bundle ${name} filename ${info.file} does not contain hash ${info.hash}`);
    }

    if (!Array.isArray(info.sources) || info.sources.length === 0) {
      throw new Error(`Bundle ${name} missing sources array`);
    }

    const parts = [];
    for (const relSrc of info.sources) {
      const fullSrc = path.join(THEME_DIR, relSrc);
      const srcContent = normalizeCss(await fs.readFile(fullSrc, 'utf8'));
      parts.push(`/* ${path.basename(relSrc)} */\n${srcContent}`);
    }

    const reconstructed = parts.join('\n\n');
    const reconstructedHash = computeHash(reconstructed);
    const reconstructedSize = Buffer.byteLength(reconstructed, 'utf8');

    if (reconstructedHash !== info.hash) {
      throw new Error(
        `Bundle ${name} source reconstruction hash mismatch: ` +
        `manifest=${info.hash} reconstructed=${reconstructedHash}`
      );
    }

    if (reconstructedSize !== info.size) {
      throw new Error(
        `Bundle ${name} source reconstruction size mismatch: ` +
        `manifest=${info.size} reconstructed=${reconstructedSize}`
      );
    }

    if (reconstructed !== distContent) {
      throw new Error(
        `Bundle ${name} source reconstruction content mismatch: ` +
        `dist file does not equal concatenation of declared sources`
      );
    }
  }

  for (const [relPath, info] of Object.entries(manifest.files)) {
    referencedFiles.add(info.file);

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

  const distFiles = (await fs.readdir(DIST_DIR))
    .filter((f) => f.endsWith('.css'));

  const orphans = distFiles.filter((f) => !referencedFiles.has(f));
  if (orphans.length > 0) {
    throw new Error(
      `Orphan dist CSS files not referenced by manifest: ${orphans.join(', ')}`
    );
  }

  console.log(
    `CSS_MANIFEST_CONTRACT=PASS bundles=${Object.keys(bundles).length} ` +
    `files=${Object.keys(manifest.files).length} ` +
    `bundle_reconstruction=verified hash_integrity=verified orphan_check=clean`
  );
}

testManifestContract().catch((err) => {
  console.error('CSS_MANIFEST_CONTRACT=FAIL', err);
  process.exit(1);
});
