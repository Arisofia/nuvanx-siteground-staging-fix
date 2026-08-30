#!/usr/bin/env node
/**
 * Blocking contract for NUVANX service parity across Doctoralia and GBP.
 *
 * Verifies that the canonical service projection derived from
 * treatment-hub-schema.json is identical across:
 * - Doctoralia Chamberí
 * - Doctoralia Goya
 * - GBP Chamberí
 * - GBP Goya
 *
 * Also verifies that forbidden legacy services are absent from the canonical
 * projection and that the mutation policy blocks are enforced.
 *
 * @package nuvanx-siteground
 */

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const themeData = path.join(root, 'wp-content/themes/nuvanx-medical/inc/data');

const fail = (reason) => {
  console.error(`DOCTORALIA_GBP_SERVICE_PARITY=FAIL ${reason}`);
  process.exit(1);
};

const loadJson = (filename) => {
  const raw = fs.readFileSync(path.join(themeData, filename), 'utf8');
  return JSON.parse(raw);
};

const treatmentHub = loadJson('treatment-hub-schema.json');
const doctoralia = loadJson('doctoralia-profiles.json');
const gbp = loadJson('gbp-profiles.json');

const canonicalServiceKeys = treatmentHub.map((t) => t.key).sort();
const clinicKeys = ['chamberi', 'goya'];

const failures = [];

for (const clinic of clinicKeys) {
  const docClinic = doctoralia.clinics?.[clinic];
  const gbpClinic = gbp.clinics?.[clinic];

  if (!docClinic) {
    failures.push(`doctoralia ${clinic} clinic record missing`);
    continue;
  }
  if (!gbpClinic) {
    failures.push(`gbp ${clinic} clinic record missing`);
    continue;
  }

  const docServices = [...(docClinic.services || [])].sort();
  const gbpServices = [...(gbpClinic.services || [])].sort();

  if (docServices.length === 0) {
    failures.push(`doctoralia ${clinic} has no service projection`);
  }
  if (gbpServices.length === 0) {
    failures.push(`gbp ${clinic} has no service projection`);
  }

  const canonicalJson = JSON.stringify(canonicalServiceKeys);
  if (JSON.stringify(docServices) !== canonicalJson) {
    failures.push(`doctoralia ${clinic} services != treatment-hub-schema`);
  }
  if (JSON.stringify(gbpServices) !== canonicalJson) {
    failures.push(`gbp ${clinic} services != treatment-hub-schema`);
  }
}

const chamberiDoc = [...(doctoralia.clinics?.chamberi?.services || [])].sort();
const goyaDoc = [...(doctoralia.clinics?.goya?.services || [])].sort();
if (JSON.stringify(chamberiDoc) !== JSON.stringify(goyaDoc)) {
  failures.push('doctoralia Chamberí != Doctoralia Goya service sets');
}

const chamberiGbp = [...(gbp.clinics?.chamberi?.services || [])].sort();
const goyaGbp = [...(gbp.clinics?.goya?.services || [])].sort();
if (JSON.stringify(chamberiGbp) !== JSON.stringify(goyaGbp)) {
  failures.push('gbp Chamberí != GBP Goya service sets');
}

const forbidden = doctoralia.forbidden_legacy_services || [];
const allProjected = new Set([
  ...chamberiDoc,
  ...goyaDoc,
  ...chamberiGbp,
  ...goyaGbp,
]);
for (const f of forbidden) {
  if (allProjected.has(f)) {
    failures.push(`forbidden legacy service in projection: ${f}`);
  }
}

const blocked = doctoralia.mutation_policy?.blocked || [];
const requiredBlocks = [
  'delete_merge_directions_53333_49168',
  'change_legal_healthcare_responsible',
  'remove_professionals',
  'change_chamberi_until_admin_export_complete',
];
for (const required of requiredBlocks) {
  if (!blocked.includes(required)) {
    failures.push(`mutation policy missing required block: ${required}`);
  }
}

if (doctoralia.governed_status !== 'external_public_parity_open') {
  failures.push(`governed_status must be external_public_parity_open, got ${doctoralia.governed_status}`);
}

if (doctoralia.service_projection_source !== 'treatment-hub-schema.json') {
  failures.push('service_projection_source must be treatment-hub-schema.json');
}

if (gbp.service_projection_source !== 'treatment-hub-schema.json') {
  failures.push('gbp service_projection_source must be treatment-hub-schema.json');
}

if (doctoralia.clinics?.chamberi?.mutation_blocked !== true) {
  failures.push('chamberi mutation_blocked must be true until admin export complete');
}

if (failures.length > 0) {
  for (const f of failures) console.error(`  - ${f}`);
  fail(`violations=${failures.length}`);
}

console.log(
  `DOCTORALIA_GBP_SERVICE_PARITY=PASS ` +
  `services=${canonicalServiceKeys.length} ` +
  `clinics=${clinicKeys.length} ` +
  `platforms=doctoralia+gbp ` +
  `mutation_blocks=${requiredBlocks.length} ` +
  `forbidden_legacy=${forbidden.length}`
);
