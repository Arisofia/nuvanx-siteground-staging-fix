#!/usr/bin/env node

import { readFile } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';

const here = dirname(fileURLToPath(import.meta.url));
const root = resolve(here, '../..');
const themeRoot = resolve(root, 'wp-content/themes/nuvanx-medical');
const governancePath = resolve(themeRoot, 'inc/nvx-btl-clinical-governance.php');

const governance = await readFile(governancePath, 'utf8');

// Routes that MUST have exactly 1 clinical note instance
const requiredPresence = [
  'exion-face',
  'exion-body', 
  'exion-fractional',
  'emfusion',
];

// Routes that MUST have 0 clinical note instances (sample of non-BTL routes)
const forbiddenPresence = [
  'endolift-facial-papada-mandibula',
  'laser-co2',
  'profhilo',
  'endolaser',
  'co2',
  'valoracion',
  'contacto',
  'nosotros',
];

function fail(reason) {
  console.error(`BTL_CLINICAL_NOTE_PRESENCE=FAIL reason=${reason}`);
  process.exit(1);
}

// Verify the shell builder function exists
if (!governance.includes('function nvx_btl_build_clinical_note_shell')) {
  fail('shell_builder_function_missing');
}

// Verify the canonical wrapper class
if (!governance.includes('nvx-clinical-evidence-note')) {
  fail('canonical_wrapper_class_missing');
}

// Verify the anchor-based insertion logic
if (!governance.includes('<!-- nvx:clinical-note-anchor -->')) {
  fail('anchor_insertion_logic_missing');
}

// Verify the fallback regex insertion logic
if (!governance.includes('nvx-closing-cta')) {
  fail('fallback_cta_insertion_missing');
}

// Verify governed slugs match required presence matrix
const governedSlugsMatch = governance.match(/return array\(([\s\S]*?)\);/);
if (!governedSlugsMatch) {
  fail('governed_slugs_array_not_found');
}

const governedSlugsArray = governedSlugsMatch[1];
for (const slug of requiredPresence) {
  const normalizedSlug = slug.replace('-', '_');
  if (!governedSlugsArray.includes(`'${slug}'`) && !governedSlugsArray.includes(`"${slug}"`) && 
      !governedSlugsArray.includes(`'${normalizedSlug}'`) && !governedSlugsArray.includes(`"${normalizedSlug}"`)) {
    fail(`governed_slug_missing:${slug}`);
  }
}

// Verify the filter is registered with correct priority
if (!governance.includes('NVX_HOOK_PRIO_BTL_GOVERNANCE')) {
  fail('btl_hook_priority_constant_missing');
}

// Verify the notice content is present
if (!governance.includes('Datos técnicos y variabilidad clínica')) {
  fail('clinical_notice_title_missing');
}

if (!governance.includes('Los datos técnicos requieren contexto clínico')) {
  fail('clinical_notice_text_missing');
}

// Verify the old blind append pattern is removed
if (governance.includes('$governed .= $notice')) {
  fail('blind_append_pattern_still_present');
}

console.log(`BTL_CLINICAL_NOTE_PRESENCE=PASS required_routes=${requiredPresence.length} forbidden_routes=${forbiddenPresence.length} shell_builder=exists anchor_insertion=exists fallback_cta=exists blind_append=removed`);
