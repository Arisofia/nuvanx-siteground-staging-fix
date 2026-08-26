import fs from 'node:fs/promises';
import path from 'node:path';
import { spawnSync } from 'node:child_process';
import { pathToFileURL } from 'node:url';
import { DEFAULT_ROUTES } from './shared-routes.mjs';

const SSH_BIN = '/usr/bin/ssh';
const ALLOWED_ORIGIN_SSH_ALIASES = new Set(['nvx-staging2', 'nvx-staging2-pr']);
const ALLOWED_PROCEDURE_TYPES = new Set([
  'https://schema.org/PercutaneousProcedure',
  'https://schema.org/NoninvasiveProcedure',
]);
const ALLOWED_MEDICAL_SPECIALTIES = new Set([
  'Anesthesia', 'Cardiovascular', 'CommunityHealth', 'Dentistry', 'Dermatology', 'DietNutrition',
  'Emergency', 'Endocrine', 'Gastroenterologic', 'Genetic', 'Geriatric', 'Gynecologic', 'Hematologic', 'Infectious',
  'LaboratoryScience', 'Midwifery', 'Musculoskeletal', 'Neurologic', 'Nursing', 'Obstetric', 'Oncologic', 'Optometric',
  'Otolaryngologic', 'Pathology', 'Pediatric', 'PharmacySpecialty', 'Physiotherapy', 'PlasticSurgery', 'Podiatric',
  'PrimaryCare', 'Psychiatric', 'PublicHealth', 'Pulmonary', 'Radiography', 'Renal', 'RespiratoryTherapy',
  'Rheumatologic', 'SpeechPathology', 'Surgical', 'Toxicologic', 'Urologic',
].map((member) => `https://schema.org/${member}`));

// Runtime validation is intentionally scoped to routes that are part of the
// published staging topology. Draft seed pages remain source-linted until they
// are promoted to publish and therefore enter Block C/public acceptance.

const TREATMENT_ROUTES = new Set([
  '/endolift-facial-papada-mandibula/',
  '/endolaser-corporal-grasa-localizada/',
  '/laser-co2-fraccionado-madrid-textura-cicatrices-poro/',
]);

function assertSafeConfig({ expectedHost, expectedSha, originSshAlias, routes }) {
  if (!/^[a-z0-9.-]+$/.test(expectedHost)) throw new Error('EXPECTED_HOST contains unsupported characters.');
  if (!/^[0-9a-f]{40}$/.test(expectedSha)) throw new Error('EXPECTED_SHA must be a full lowercase 40-character SHA.');
  if (!ALLOWED_ORIGIN_SSH_ALIASES.has(originSshAlias)) {
    throw new Error(`ORIGIN_SSH_ALIAS must be one of: ${[...ALLOWED_ORIGIN_SSH_ALIASES].join(', ')}.`);
  }
  for (const route of routes) {
    if (!/^\/[A-Za-z0-9_./%-]*$/.test(route)) throw new Error(`Unsupported route characters: ${route}`);
  }
}

function extractMetaContent(html, name) {
  const tags = html.match(/<meta\b[^>]*>/gi) || [];
  for (const tag of tags) {
    const nameMatch = tag.match(/\bname\s*=\s*["']([^"']+)["']/i);
    if (!nameMatch || nameMatch[1].toLowerCase() !== name.toLowerCase()) continue;
    const contentMatch = tag.match(/\bcontent\s*=\s*["']([^"']*)["']/i);
    return contentMatch ? contentMatch[1].trim() : '';
  }
  return '';
}

function fetchOriginHtml({ route, expectedHost, originSshAlias }) {
  const remoteScript = [
    'set -Eeuo pipefail',
    'url="https://${EXPECTED_HOST}${ROUTE}"',
    'set +e',
    'output="$(curl -ksS -L --max-redirs 5 --max-time 45 -b "wpSGCacheBypass=1" -H \'Cache-Control: no-cache\' -H \'Pragma: no-cache\' -H \'Accept: text/html,application/xhtml+xml\' -A \'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 NUVANX-Rendered-Schema-Contract/1.0\' -w \'\\nNVX_HTTP_STATUS:%{http_code}\\n\' "$url" 2>/dev/null)"',
    'curl_rc=$?',
    'if [[ "$curl_rc" -ne 0 ]] || echo "$output" | grep -qE "NVX_HTTP_STATUS:(000|403|503)"; then',
    '  output="$(curl -ksS -L --max-redirs 5 --max-time 45 --resolve "${EXPECTED_HOST}:443:127.0.0.1" -b "wpSGCacheBypass=1" -H \'Cache-Control: no-cache\' -H \'Pragma: no-cache\' -H \'Accept: text/html,application/xhtml+xml\' -A \'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 NUVANX-Rendered-Schema-Contract/1.0\' -w \'\\nNVX_HTTP_STATUS:%{http_code}\\n\' "$url" 2>/dev/null)"',
    'fi',
    'printf "%s" "$output"',
    '',
  ].join('\n');
  const remoteCommand = `EXPECTED_HOST=${expectedHost} ROUTE=${route} bash -se`;
  const result = spawnSync(
    SSH_BIN,
    ['-o', 'BatchMode=yes', '-o', 'ConnectTimeout=8', '-o', 'ConnectionAttempts=1', '--', originSshAlias, remoteCommand],
    { input: remoteScript, encoding: 'utf8', timeout: 60000, maxBuffer: 8 * 1024 * 1024 }
  );
  if (result.error || result.status !== 0) {
    const diagnostic = (result.stderr || result.error?.message || `exit ${result.status}`).trim();
    throw new Error(`Origin HTML fetch failed for ${route}: ${diagnostic}`);
  }

  const stdout = result.stdout || '';
  const marker = '\nNVX_HTTP_STATUS:';
  const markerIndex = stdout.lastIndexOf(marker);
  if (markerIndex < 0) throw new Error(`Origin HTML fetch for ${route} did not expose an HTTP status marker`);
  const httpStatus = Number(stdout.slice(markerIndex + marker.length).trim());
  if (!Number.isInteger(httpStatus) || httpStatus < 100 || httpStatus > 599) {
    throw new Error(`Origin HTML fetch for ${route} exposed invalid HTTP status: ${stdout.slice(markerIndex + marker.length).trim()}`);
  }
  return { html: stdout.slice(0, markerIndex), httpStatus };
}

function extractJsonLd(html, route) {
  const blocks = [];
  const scriptRe = /<script\b([^>]*)>([\s\S]*?)<\/script>/gi;
  let match;
  while ((match = scriptRe.exec(html)) !== null) {
    const attrs = match[1] || '';
    // Match type attribute with optional quotes to align with single-jsonld-source-contract.mjs
    if (!/\btype\s*=\s*["']?application\/ld\+json["']?/i.test(attrs)) continue;
    const raw = (match[2] || '').trim();
    if (!raw) continue;
    try {
      blocks.push(JSON.parse(raw));
    } catch (error) {
      // Record malformed JSON-LD as a block-level issue instead of aborting route validation
      blocks.push({
        _parseError: `Invalid JSON-LD: ${error.message}`,
        _rawContent: raw.substring(0, 200),
      });
    }
  }
  if (blocks.length === 0) throw new Error(`No application/ld+json blocks rendered on ${route}`);
  return blocks;
}

function asTypes(value) {
  if (Array.isArray(value)) return value.map(String);
  return value == null ? [] : [String(value)];
}

function isWebPageType(types) {
  return types.some((type) => type === 'WebPage' || type.endsWith('Page'));
}

function isEventType(types) {
  return types.some((type) => type === 'Event' || type.endsWith('Event'));
}

function isMedicalProcedureType(types) {
  const medicalProcedureTypes = new Set([
    'MedicalProcedure',
    'DiagnosticProcedure',
    'PalliativeProcedure',
    'PhysicalExam',
    'PhysicalTherapy',
    'PsychologicalTreatment',
    'RadiationTherapy',
    'SurgicalProcedure',
    'TherapeuticProcedure',
  ]);
  return types.some((type) => medicalProcedureTypes.has(type));
}

function normalizeSchemaValue(value) {
  if (typeof value === 'string') return value;
  if (value && typeof value === 'object' && typeof value['@id'] === 'string') return value['@id'];
  return '';
}

function walkObjects(value, visitor, context = { path: '$', parent: null, key: null }) {
  if (!value || typeof value !== 'object') return;
  visitor(value, context);
  if (Array.isArray(value)) {
    value.forEach((item, index) => walkObjects(item, visitor, { path: `${context.path}[${index}]`, parent: value, key: index }));
    return;
  }
  for (const [key, child] of Object.entries(value)) {
    if (child && typeof child === 'object') {
      walkObjects(child, visitor, { path: `${context.path}.${key}`, parent: value, key });
    }
  }
}

function topLevelNodes(block) {
  if (Array.isArray(block)) return block;
  if (block && Array.isArray(block['@graph'])) return block['@graph'];
  return block && typeof block === 'object' ? [block] : [];
}

function doctoraliaUrls(value) {
  const values = Array.isArray(value) ? value : value == null ? [] : [value];
  return values.map((item) => normalizeSchemaValue(item) || String(item || '')).filter((item) => /doctoralia\.es/i.test(item));
}

function validateRouteGraph({ route, html, blocks, expectedHost, httpStatus }) {
  const issues = [];
  const warnings = [];
  const canonicalOrgId = `https://${expectedHost}/#organization`;
  const canonicalTreatmentId = `https://${expectedHost}${route}#medical-procedure`;
  const allTopNodes = blocks.flatMap(topLevelNodes);
  const substantiveDefinitions = new Map();
  let canonicalOrgSeen = false;
  let treatmentDefinitionCount = 0;

  if (httpStatus !== 200) issues.push(`expected public HTTP 200, got ${httpStatus}`);

  blocks.forEach((block, blockIndex) => {
    walkObjects(block, (obj, context) => {
      if (Array.isArray(obj)) return;
      const types = asTypes(obj['@type']);
      const id = typeof obj['@id'] === 'string' ? obj['@id'] : '';
      const location = `block[${blockIndex}]${context.path}`;

      if (id === canonicalOrgId) canonicalOrgSeen = true;
      if (id.includes('/#/schema/organization/') || /#\/schema\/organization\//.test(id)) {
        issues.push(`${location}: legacy Organization @id ${id}`);
      }

      if (id) {
        const substantiveKeys = Object.keys(obj).filter((key) => !['@id', '@type'].includes(key));
        if (substantiveKeys.length > 0) {
          if (!substantiveDefinitions.has(id)) substantiveDefinitions.set(id, []);
          substantiveDefinitions.get(id).push(location);
        }
      }

      if (Object.hasOwn(obj, 'reviewedBy') && !isWebPageType(types)) {
        issues.push(`${location}: reviewedBy on non-WebPage type ${types.join('|') || '(missing @type)'}`);
      }
      if (Object.hasOwn(obj, 'performer') && !isEventType(types)) {
        issues.push(`${location}: performer on non-Event type ${types.join('|') || '(missing @type)'}`);
      }
      if (Object.hasOwn(obj, 'priceRange') && !types.some((type) => type === 'LocalBusiness' || type === 'MedicalClinic')) {
        issues.push(`${location}: priceRange on disallowed type ${types.join('|') || '(missing @type)'}`);
      }
      if (Object.hasOwn(obj, 'procedureType')) {
        if (!isMedicalProcedureType(types)) {
          issues.push(`${location}: procedureType on non-MedicalProcedure type ${types.join('|') || '(missing @type)'}`);
        }
        const values = Array.isArray(obj.procedureType) ? obj.procedureType : [obj.procedureType];
        for (const rawValue of values) {
          const value = normalizeSchemaValue(rawValue);
          if (!ALLOWED_PROCEDURE_TYPES.has(value)) {
            issues.push(`${location}: invalid procedureType ${value || JSON.stringify(rawValue)}`);
          }
        }
      }
      if (Object.hasOwn(obj, 'medicalSpecialty')) {
        const values = Array.isArray(obj.medicalSpecialty) ? obj.medicalSpecialty : [obj.medicalSpecialty];
        for (const rawValue of values) {
          const value = normalizeSchemaValue(rawValue);
          if (!ALLOWED_MEDICAL_SPECIALTIES.has(value)) {
            issues.push(`${location}: invalid MedicalSpecialty enum ${value || JSON.stringify(rawValue)}`);
          }
        }
      }
      if (Object.hasOwn(obj, 'recognizingAuthority')) {
        const serialized = JSON.stringify(obj.recognizingAuthority);
        if (/SEME|Sociedad Española de Medicina Estética|seme\.org/i.test(serialized)) {
          issues.push(`${location}: ungoverned SEME recognizingAuthority claim`);
        }
      }

      if (id === canonicalOrgId) {
        const localDoctoralia = doctoraliaUrls(obj.sameAs).filter((url) => /\/clinicas\//i.test(url));
        if (localDoctoralia.length > 0) {
          issues.push(`${location}: corporate Organization sameAs contains clinic Doctoralia URL(s): ${localDoctoralia.join(', ')}`);
        }
      }

      if (types.includes('ListItem') && obj.item && typeof obj.item === 'object' && !Array.isArray(obj.item)) {
        const itemId = typeof obj.item['@id'] === 'string' ? obj.item['@id'] : '';
        if (itemId.endsWith('#medical-procedure')) {
          const keys = Object.keys(obj.item);
          if (keys.length !== 1 || keys[0] !== '@id') {
            issues.push(`${location}.item: treatment hub item must be reference-only @id; keys=${keys.join(',')}`);
          }
        }
      }

      if (id === canonicalTreatmentId && types.some((type) => type === 'MedicalProcedure' || type === 'Service')) {
        const substantiveKeys = Object.keys(obj).filter((key) => !['@id', '@type'].includes(key));
        if (substantiveKeys.length > 0) treatmentDefinitionCount += 1;
      }
    });
  });

  for (const [id, paths] of substantiveDefinitions.entries()) {
    if (paths.length > 1 && (id === canonicalOrgId || id.endsWith('#medical-procedure'))) {
      issues.push(`duplicate substantive definition for ${id}: ${paths.join(', ')}`);
    }
  }

  if (!canonicalOrgSeen) issues.push(`canonical Organization @id missing: ${canonicalOrgId}`);
  if (TREATMENT_ROUTES.has(route) && treatmentDefinitionCount !== 1) {
    issues.push(`expected exactly one canonical treatment definition ${canonicalTreatmentId}; found ${treatmentDefinitionCount}`);
  }

  if (route === '/tratamientos/') {
    const hubRefs = [];
    for (const node of allTopNodes) {
      walkObjects(node, (obj) => {
        if (Array.isArray(obj) || !asTypes(obj['@type']).includes('ListItem')) return;
        const itemId = typeof obj.item?.['@id'] === 'string' ? obj.item['@id'] : '';
        if (itemId.endsWith('#medical-procedure')) hubRefs.push(itemId);
      });
    }
    if (hubRefs.length === 0) issues.push('treatment hub rendered no #medical-procedure references');
  }

  if (route.includes('goya-barrio-salamanca')) {
    const clinicNodes = allTopNodes.filter((node) => asTypes(node?.['@type']).includes('MedicalClinic'));
    const urls = clinicNodes.flatMap((node) => doctoraliaUrls(node.sameAs));
    if (!urls.some((url) => /sede-goya/i.test(url))) issues.push('Goya MedicalClinic missing its sede-goya Doctoralia sameAs');
    if (urls.some((url) => /\/clinicas\/nuvanx-medicina-estetica-laser(?:[/?#]|$)/i.test(url) && !/sede-goya/i.test(url))) {
      issues.push('Goya MedicalClinic contains Chamberí Doctoralia sameAs');
    }
  }

  const deploySha = extractMetaContent(html, 'nvx-deploy-sha');
  return { route, httpStatus, blocks: blocks.length, topLevelNodes: allTopNodes.length, deploySha, issues, warnings };
}

export async function runRenderedSchemaContract(options = {}) {
  const expectedHost = options.expectedHost || process.env.EXPECTED_HOST || 'staging2.nuvanx.com';
  const expectedSha = (options.expectedSha || process.env.EXPECTED_SHA || '').trim();
  const originSshAlias = options.originSshAlias || process.env.ORIGIN_SSH_ALIAS || 'nvx-staging2';
  const routes = options.routes || DEFAULT_ROUTES;
  const outputDir = options.outputDir || path.resolve('scripts/staging2/artifacts');

  assertSafeConfig({ expectedHost, expectedSha, originSshAlias, routes });
  await fs.mkdir(outputDir, { recursive: true });

  const report = {
    schema: 2,
    checkedAt: new Date().toISOString(),
    expectedHost,
    expectedSha,
    originSshAlias,
    routes: [],
    issues: [],
  };

  for (const route of routes) {
    try {
      const { html, httpStatus } = fetchOriginHtml({ route, expectedHost, originSshAlias });
      const deploySha = extractMetaContent(html, 'nvx-deploy-sha');
      if (deploySha !== expectedSha) {
        report.issues.push(`${route}: deploy SHA mismatch meta=${deploySha || '(missing)'} expected=${expectedSha}`);
        report.routes.push({ route, httpStatus, deploySha, blocks: 0, topLevelNodes: 0, issues: ['deploy SHA mismatch'], warnings: [] });
        continue;
      }
      const blocks = extractJsonLd(html, route);
      const routeResult = validateRouteGraph({ route, html, blocks, expectedHost, httpStatus });
      if (routeResult.issues.length > 0) routeResult.diagnosticJsonLdBlocks = blocks;
      report.routes.push(routeResult);
      report.issues.push(...routeResult.issues.map((issue) => `${route}: ${issue}`));
    } catch (error) {
      const message = error instanceof Error ? error.message : String(error);
      report.routes.push({ route, httpStatus: 0, deploySha: '', blocks: 0, topLevelNodes: 0, issues: [message], warnings: [] });
      report.issues.push(`${route}: ${message}`);
    }
  }

  report.pass = report.issues.length === 0;
  const artifactPath = path.join(outputDir, 'rendered-schema-contract.json');
  await fs.writeFile(artifactPath, `${JSON.stringify(report, null, 2)}\n`, 'utf8');

  if (!report.pass) {
    console.error(`RENDERED_SCHEMA_CONTRACT=FAIL issues=${report.issues.length}`);
    for (const issue of report.issues) console.error(`- ${issue}`);
    throw new Error(`Rendered JSON-LD semantic contract failed with ${report.issues.length} issue(s).`);
  }

  console.log(`RENDERED_SCHEMA_CONTRACT=PASS routes=${report.routes.length} sha=${expectedSha} source=siteground-origin-rendered-html`);
  return report;
}

const invokedDirectly = process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href;
if (invokedDirectly) {
  runRenderedSchemaContract().catch((error) => {
    console.error(error instanceof Error ? error.message : String(error));
    process.exit(1);
  });
}
