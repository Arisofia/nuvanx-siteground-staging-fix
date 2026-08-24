#!/usr/bin/env node

import { readFile } from 'node:fs/promises';
import { fileURLToPath } from 'node:url';
import { dirname, resolve } from 'node:path';

const here = dirname(fileURLToPath(import.meta.url));
const root = resolve(here, '../..');
const matrixPath = resolve(root, 'wp-content/themes/nuvanx-medical/inc/data/clinical-matrix.json');
const governancePath = resolve(root, 'wp-content/themes/nuvanx-medical/inc/nvx-clinical-governance.php');
const constantsPath = resolve(root, 'wp-content/themes/nuvanx-medical/inc/nvx-constants.php');

const [matrixRaw, governance, constants] = await Promise.all([
  readFile(matrixPath, 'utf8'),
  readFile(governancePath, 'utf8'),
  readFile(constantsPath, 'utf8'),
]);

const matrix = JSON.parse(matrixRaw);
const treatments = matrix?.treatments ?? {};

function fail(reason) {
  console.error(`CLINICAL_EVIDENCE_CONTRACT=FAIL reason=${reason}`);
  process.exit(1);
}

const required = {
  endolift_facial: ['38886198', '35083532'],
  laser_co2: ['22766970', '42334669'],
  exion_face: ['40243133'],
};

for (const [treatmentId, pmids] of Object.entries(required)) {
  const evidence = treatments?.[treatmentId]?.evidence;
  if (!Array.isArray(evidence) || evidence.length !== pmids.length) {
    fail(`evidence_count:${treatmentId}`);
  }

  const found = new Set();
  for (const row of evidence) {
    for (const field of ['study_type', 'sample_size', 'title', 'summary', 'limitation', 'source_label', 'source_url', 'pmid']) {
      if (typeof row?.[field] !== 'string' || row[field].trim() === '') {
        fail(`missing_${field}:${treatmentId}`);
      }
    }
    if (!/^https:\/\/pubmed\.ncbi\.nlm\.nih\.gov\/\d+\/$/.test(row.source_url)) {
      fail(`non_pubmed_source:${treatmentId}:${row.pmid}`);
    }
    if (!row.source_url.includes(`/${row.pmid}/`) || !row.source_label.includes(row.pmid)) {
      fail(`pmid_source_mismatch:${treatmentId}:${row.pmid}`);
    }
    found.add(row.pmid);
  }

  for (const pmid of pmids) {
    if (!found.has(pmid)) fail(`required_pmid_missing:${treatmentId}:${pmid}`);
  }
}

const forbidden = [
  /Endolift[^\n]{0,120}20\s*[%–-]\s*40\s*%/iu,
  /EXION[^\n]{0,120}37\s*%[^\n]{0,80}col[aá]geno/iu,
  /94\s*%[^\n]{0,100}n\s*=\s*47/iu,
];
for (const pattern of forbidden) {
  if (pattern.test(matrixRaw) || pattern.test(governance)) {
    fail(`forbidden_unqualified_claim:${pattern.source}`);
  }
}

for (const marker of [
  'data-nvx-clinical-evidence=',
  "esc_html__( 'Límite de la evidencia:'",
  "esc_url( $source_url )",
  "NVX_HOOK_PRIO_CLINICAL_EVIDENCE",
]) {
  if (!governance.includes(marker)) fail(`render_contract_missing:${marker}`);
}

if (!constants.includes('const NVX_HOOK_PRIO_CLINICAL_EVIDENCE = 98;')) {
  fail('clinical_evidence_priority_missing');
}

const exion = treatments.exion_face.evidence[0];
if (!/n=7 total; RF\+TUS n=3/.test(exion.sample_size)) fail('exion_small_subgroup_not_explicit');
if (!/endpoint histol[oó]gico/iu.test(exion.limitation)) fail('exion_histology_limitation_missing');
if (!/financiad[oa] por BTL Industries/iu.test(exion.limitation)) fail('exion_funding_disclosure_missing');

const endoliftSmall = treatments.endolift_facial.evidence.find((row) => row.pmid === '35083532');
if (!/Muestra muy pequeña/iu.test(endoliftSmall?.limitation ?? '')) fail('endolift_small_sample_limitation_missing');

const co2Rct = treatments.laser_co2.evidence.find((row) => row.pmid === '22766970');
if (!/6,15 a 3,89/.test(co2Rct?.summary ?? '') || !/5,72 a 3,56/.test(co2Rct?.summary ?? '')) {
  fail('co2_rct_endpoint_values_missing');
}

console.log('CLINICAL_EVIDENCE_CONTRACT=PASS treatments=3 sources=5 forbidden_claims=absent');
