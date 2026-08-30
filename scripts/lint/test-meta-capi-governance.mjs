#!/usr/bin/env node
/**
 * Blocking contract for Meta CAPI governance and no-consent boundary.
 *
 * Verifies that:
 * - Browser Meta Pixel owner remains 'none'
 * - Server-side CAPI configuration is governed in a data contract
 * - The Supabase 402 gateway restriction is classified as TRANSIENT_INFRASTRUCTURE
 *   (not a code defect / FAIL_REAL)
 * - Guardrails against redeploy/credential-rotation/data-purge on 402 are enforced
 * - The browser governance PHP module retains source-scoped retirement
 * - The staging no-consent contract script exists
 * - Production no-consent acceptance status is PASS
 *
 * @package nuvanx-siteground
 */

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');
const themeData = path.join(root, 'wp-content/themes/nuvanx-medical/inc/data');
const themeInc = path.join(root, 'wp-content/themes/nuvanx-medical/inc');
const stagingDir = path.join(root, 'scripts/staging2');

const fail = (reason) => {
  console.error(`META_CAPI_GOVERNANCE=FAIL ${reason}`);
  process.exit(1);
};

const configPath = path.join(themeData, 'meta-capi-config.json');
if (!fs.existsSync(configPath)) {
  fail('meta-capi-config.json missing');
}

const config = JSON.parse(fs.readFileSync(configPath, 'utf8'));
const failures = [];

if (config.browser_pixel_owner !== 'none') {
  failures.push(`browser_pixel_owner must be 'none', got '${config.browser_pixel_owner}'`);
}

if (!config.server_side_capi_owner || config.server_side_capi_owner !== 'Nuvanx-System/Supabase') {
  failures.push('server_side_capi_owner must be Nuvanx-System/Supabase');
}

const ef = config.edge_function || {};
if (!ef.name || ef.name !== 'web-events') {
  failures.push('edge_function.name must be web-events');
}
if (!ef.version || ef.version !== 'v13') {
  failures.push('edge_function.version must be v13');
}
if (!ef.status || ef.status !== 'ACTIVE') {
  failures.push('edge_function.status must be ACTIVE');
}
if (!ef.supabase_project || ef.supabase_project !== 'ssvvuuysgxyqvmovrlvk') {
  failures.push('edge_function.supabase_project must be ssvvuuysgxyqvmovrlvk');
}

const gs = config.gateway_status || {};
if (gs.http_status !== 402) {
  failures.push(`gateway_status.http_status must be 402, got ${gs.http_status}`);
}
if (gs.violation !== 'exceed_db_size_quota') {
  failures.push(`gateway_status.violation must be exceed_db_size_quota, got '${gs.violation}'`);
}
if (gs.classification !== 'TRANSIENT_INFRASTRUCTURE') {
  failures.push(`gateway_status.classification must be TRANSIENT_INFRASTRUCTURE, got '${gs.classification}'`);
}

const gr = config.guardrails || {};
if (!gr.no_redeploy_on_402) {
  failures.push('guardrails.no_redeploy_on_402 must be true');
}
if (!gr.no_credential_rotation_on_402) {
  failures.push('guardrails.no_credential_rotation_on_402 must be true');
}
if (!gr.no_data_purge_from_quota_label) {
  failures.push('guardrails.no_data_purge_from_quota_label must be true');
}

const legacy = config.legacy_browser_owner || {};
if (legacy.mu_plugin !== 'nuvanx-meta-dedupe-event-id.php') {
  failures.push('legacy_browser_owner.mu_plugin must be nuvanx-meta-dedupe-event-id.php');
}
if (legacy.status !== 'retired') {
  failures.push('legacy_browser_owner.status must be retired');
}
if (legacy.pixel_id !== '1497940655079106') {
  failures.push('legacy_browser_owner.pixel_id must be 1497940655079106');
}

const acceptance = config.production_no_consent_acceptance || {};
if (acceptance.status !== 'PASS') {
  failures.push(`production_no_consent_acceptance.status must be PASS, got '${acceptance.status}'`);
}

const governancePath = path.join(themeInc, 'nvx-meta-browser-governance.php');
if (!fs.existsSync(governancePath)) {
  failures.push('nvx-meta-browser-governance.php missing');
} else {
  const governance = fs.readFileSync(governancePath, 'utf8');
  const requiredPatterns = [
    "return 'nuvanx-meta-dedupe-event-id.php';",
    'ReflectionFunction',
    'ReflectionMethod',
    "add_action( 'init', 'nvx_retire_legacy_meta_browser_owner_callbacks', PHP_INT_MIN );",
    "add_action( 'send_headers', 'nvx_meta_browser_strip_legacy_response_cookies', PHP_INT_MAX );",
    '/^(?:_fbp|_fbc)=/i',
  ];
  for (const pattern of requiredPatterns) {
    if (!governance.includes(pattern)) {
      failures.push(`governance module missing pattern: ${pattern}`);
    }
  }

  const forbiddenPatterns = [
    'connect.facebook.net',
    'fbevents.js',
    "fbq('init'",
    '1497940655079106',
  ];
  for (const pattern of forbiddenPatterns) {
    if (governance.includes(pattern)) {
      failures.push(`governance module contains forbidden browser Meta pattern: ${pattern}`);
    }
  }
}

const noConsentScript = path.join(stagingDir, 'meta-no-consent-contract.mjs');
if (!fs.existsSync(noConsentScript)) {
  failures.push('staging meta-no-consent-contract.mjs missing');
}

if (failures.length > 0) {
  for (const f of failures) console.error(`  - ${f}`);
  fail(`violations=${failures.length}`);
}

console.log(
  `META_CAPI_GOVERNANCE=PASS ` +
  `browser_owner=none ` +
  `capi_owner=${config.server_side_capi_owner} ` +
  `edge_function=${ef.name}/${ef.version}/${ef.status} ` +
  `gateway=${gs.http_status}/${gs.classification} ` +
  `guardrails=${Object.keys(gr).length} ` +
  `legacy=retired ` +
  `production_acceptance=${acceptance.status}`
);
