import assert from 'node:assert/strict';
import fs from 'node:fs/promises';
import { createHash, randomUUID } from 'node:crypto';
import { chromium } from 'playwright';
import { EX_CONFIG, EX_TEMPFAIL, getGitHubEventPath, EXECUTION_PATHS } from './siteground-transient-classifier.mjs';

const BASE_URL = (process.env.BASE_URL || '').replace(/\/+$/, '');
const EXPECTED_BASE = 'https://staging2.nuvanx.com';
const ARTIFACT_PATH = new URL('./valoracion-artifacts/attribution-lineage-e2e.json', import.meta.url);
const EVENT_NAME = process.env.GITHUB_EVENT_NAME || '';
const REF_NAME = process.env.GITHUB_REF_NAME || '';
const EVENT_PATH = getGitHubEventPath(EVENT_NAME, REF_NAME);

if (EVENT_PATH === EXECUTION_PATHS.UNSUPPORTED_EVENT) {
  console.log(
    `ATTRIBUTION_LINEAGE_E2E=SKIP reason=unsupported_event event=${EVENT_NAME} ref=${REF_NAME}`
  );
  process.exit(0);
}

console.log(`ATTRIBUTION_LINEAGE_E2E=PATH path=${EVENT_PATH} event=${EVENT_NAME} ref=${REF_NAME}`);

if (!BASE_URL) {
  throw new Error('BASE_URL environment variable is required for lineage E2E');
}
assert.equal(
  BASE_URL,
  EXPECTED_BASE,
  'Real lineage E2E is allowed only against canonical Staging2'
);

const qaToken = randomUUID().replaceAll('-', '');
const email = `qa-attribution-${qaToken.slice(0, 16)}@example.org`;
const gclid = `NVXQA${qaToken}`;
const target = `${BASE_URL}/madrid/valoracion/?utm_source=google&utm_medium=cpc&utm_campaign=nvx_lineage_e2e&gclid=${encodeURIComponent(gclid)}`;

const SUPABASE_URL = (process.env.SUPABASE_URL || 'https://ssvvuuysgxyqvmovrlvk.supabase.co').replace(/\/+$/, '');
const SUPABASE_SERVICE_ROLE_KEY = (process.env.SUPABASE_SERVICE_ROLE_KEY || '').trim();

function delay(ms) {
  return new Promise((resolve) => setTimeout(resolve, ms));
}

class PersistenceConfigError extends Error {
  constructor(message) {
    super(message);
    this.name = 'PersistenceConfigError';
  }
}

async function supabaseSelect(table, query) {
  const url = `${SUPABASE_URL}/rest/v1/${table}?${query}`;
  const response = await fetch(url, {
    method: 'GET',
    headers: {
      apikey: SUPABASE_SERVICE_ROLE_KEY,
      Authorization: `Bearer ${SUPABASE_SERVICE_ROLE_KEY}`,
      Accept: 'application/json',
    },
  });
  const text = await response.text();
  if (response.status === 401 || response.status === 403) {
    throw new PersistenceConfigError(`Supabase ${table} rejected service_role status=${response.status}`);
  }
  if (!response.ok) {
    throw new Error(`Supabase ${table} query failed status=${response.status}`);
  }
  try {
    return JSON.parse(text);
  } catch {
    throw new Error(`Supabase ${table} returned non-JSON`);
  }
}

async function assertSupabasePersistence({ nvxLeadId, emailHash, gclid, testRunId }) {
  if (!SUPABASE_SERVICE_ROLE_KEY) {
    console.log('ATTRIBUTION_PERSISTENCE=SKIP reason=credentials_absent');
    return { status: 'skipped', reason: 'credentials_absent' };
  }

  const deadline = Date.now() + 20_000;
  let capture = null;
  let click = null;
  let lastError = null;

  while (Date.now() < deadline) {
    try {
      const captures = await supabaseSelect(
        'web_lead_captures',
        `nvx_lead_id=eq.${encodeURIComponent(nvxLeadId)}&select=nvx_lead_id,email_hash,is_test_lead,test_run_id,form_id`,
      );
      const clicks = await supabaseSelect(
        'google_click_attributions',
        `nvx_lead_id=eq.${encodeURIComponent(nvxLeadId)}&select=nvx_lead_id,email_hash,gclid,is_test_lead,test_run_id,form_id`,
      );
      capture = Array.isArray(captures) ? captures[0] : null;
      click = Array.isArray(clicks) ? clicks[0] : null;
      if (capture && click) break;
    } catch (error) {
      if (error instanceof PersistenceConfigError) throw error;
      lastError = error;
    }
    await delay(1000);
  }

  if (!capture) {
    throw lastError || new Error(`web_lead_captures row missing for nvx_lead_id=${nvxLeadId}`);
  }
  if (!click) {
    throw lastError || new Error(`google_click_attributions row missing for nvx_lead_id=${nvxLeadId}`);
  }

  assert.equal(String(capture.nvx_lead_id || '').toLowerCase(), nvxLeadId);
  assert.equal(String(capture.email_hash || ''), emailHash);
  assert.equal(Boolean(capture.is_test_lead), true);
  assert.equal(String(capture.test_run_id || ''), testRunId);
  assert.equal(String(click.nvx_lead_id || '').toLowerCase(), nvxLeadId);
  assert.equal(String(click.email_hash || ''), emailHash);
  assert.equal(String(click.gclid || ''), gclid);
  assert.equal(Boolean(click.is_test_lead), true);
  assert.match(String(click.test_run_id || ''), /^(staging2-sha-[A-Za-z0-9._:-]{4,80}|staging2-origin)$/);

  console.log(`ATTRIBUTION_PERSISTENCE=PASS nvx_lead_id=${nvxLeadId}`);
  return { status: 'pass', web_lead_captures: true, google_click_attributions: true };
}

await fs.mkdir(new URL('./valoracion-artifacts/', import.meta.url), { recursive: true });
await fs.rm(ARTIFACT_PATH, { force: true });

const browser = await chromium.launch({ headless: true });
const context = await browser.newContext();
const page = await context.newPage();

function isTransient(error) {
  const message = error instanceof Error ? error.message : String(error);
  return /ERR_(?:CONNECTION|NAME|TIMED_OUT)|Timeout|net::|502|503|504|temporar/i.test(message);
}

try {
  const response = await page.goto(target, { waitUntil: 'domcontentloaded', timeout: 30_000 });
  if (!response || response.status() >= 500) {
    throw new Error(`Staging2 navigation unavailable: status=${response ? response.status() : 'none'}`);
  }

  await page.waitForSelector('[data-nvx-direct-form]', { timeout: 15_000 });
  await page.waitForFunction(() => Boolean(window.NUVANXAttributionContract?.getLeadId), null, { timeout: 15_000 });

  const consentState = await page.evaluate(() => {
    window.wp_has_consent = (type) => type === 'marketing' || type === 'statistics';
    window.cmplz_has_consent = (type) => type === 'marketing' || type === 'statistics';
    document.dispatchEvent(new Event('wp_listen_for_consent_change'));
    document.dispatchEvent(new Event('cmplz_enable_category'));
    window.NUVANXAttributionContract?.getFirstTouch?.();
    document.dispatchEvent(new Event('wp_listen_for_consent_change'));
    window.NUVANXHubSpotAttributionSync?.syncExistingForms?.();
    return {
      env: window.nvxConversionEvents?.env || '',
      qa: window.nvxConversionEvents?.qa || {},
      formId: String(window.nvxConversionEvents?.forms?.valoracion || '').toLowerCase(),
    };
  });

  assert.equal(consentState.env, 'staging2', 'E2E must execute with server-owned staging2 context');
  assert.match(consentState.formId, /^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/,
    'Browser form identity must be a server-provided canonical UUID');
  assert.equal(consentState.qa?.is_test_lead, true, 'Staging2 QA identity must be server-owned and enabled');
  assert.match(String(consentState.qa?.test_run_id || ''), /^staging2-sha-[0-9a-f]{12}$/,
    'Staging2 QA run id must be deterministic and SHA-scoped');

  await page.waitForFunction((formId) => {
    const api = window.HubSpotFormsV4;
    if (!api || typeof api.getForms !== 'function') return false;
    return api.getForms().some((candidate) => String(candidate?.getFormId?.() || '').toLowerCase() === formId);
  }, consentState.formId, { timeout: 20_000 });

  await page.evaluate(async () => {
    window.NUVANXHubSpotAttributionSync?.syncExistingForms?.();
    await new Promise((resolve) => setTimeout(resolve, 250));
  });

  const readNativeFields = () => page.evaluate(async (formId) => {
    const forms = window.HubSpotFormsV4?.getForms?.() || [];
    const form = forms.find((candidate) => String(candidate?.getFormId?.() || '').toLowerCase() === formId);
    if (!form) return null;
    const syncChanged = await window.NUVANXHubSpotAttributionSync?.syncForm?.(form);
    const values = {};
    for (const field of await form.getFormFieldValues() || []) {
      const actual = String(field?.name || '');
      const canonical = actual.replace(/^\d+-\d+\//, '');
      if (canonical) values[canonical] = field?.value;
    }
    return { values, syncChanged: Boolean(syncChanged) };
  }, consentState.formId);

  let nativeFields = null;
  let nativeSyncChanged = false;
  const lineageDeadline = Date.now() + 7_000;
  while (Date.now() < lineageDeadline) {
    const nativeSnapshot = await readNativeFields();
    nativeFields = nativeSnapshot?.values || null;
    nativeSyncChanged = Boolean(nativeSnapshot?.syncChanged);
    if (/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i.test(String(nativeFields?.nvx_lead_id || ''))) {
      break;
    }
    await delay(250);
  }

  assert.ok(nativeFields, 'Canonical HubSpot V4 form must be discoverable after marketing consent');
  const browserLeadId = String(nativeFields.nvx_lead_id || '').toLowerCase();
  const fieldNames = Object.keys(nativeFields).sort((a, b) => a.localeCompare(b));
  console.log(
    `ATTRIBUTION_LINEAGE_DIAGNOSTIC sync_changed=${nativeSyncChanged ? 'true' : 'false'} lead_field_present=${fieldNames.includes('nvx_lead_id') ? 'true' : 'false'} lead_value_type=${Array.isArray(nativeFields.nvx_lead_id) ? 'array' : typeof nativeFields.nvx_lead_id} managed_fields_present=${['nvx_is_test_lead', 'nvx_test_run_id', 'nvx_utm_source', 'nvx_google_click_id'].filter((name) => fieldNames.includes(name)).join(',') || 'none'}`
  );
  assert.match(browserLeadId, /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/,
    'Live HubSpot V4 form must contain the browser session UUID v4');
  assert.ok(nativeFields.nvx_is_test_lead === true || String(nativeFields.nvx_is_test_lead).toLowerCase() === 'true',
    'Live HubSpot V4 form must contain server-owned QA=true');
  assert.equal(String(nativeFields.nvx_test_run_id || ''), String(consentState.qa.test_run_id));
  assert.equal(String(nativeFields.nvx_utm_source || ''), 'google');
  assert.equal(String(nativeFields.nvx_google_click_id || ''), gclid);

  const directState = await page.evaluate(async ({ email, gclid }) => {
    const form = document.querySelector('[data-nvx-direct-form]');
    if (!form) return null;
    const set = (name, value) => {
      const input = form.querySelector(`[name="${name}"]`);
      if (input) input.value = value;
    };
    set('firstname', 'QA');
    set('lastname', 'Attribution');
    set('phone', '+34910000000');
    set('email', email);
    set('message', 'Automated Staging2 attribution lineage QA. No clinical request.');
    set('nvx_marketing_consent', '1');
    set('gclid', gclid);
    set('utm_source', 'google');
    set('utm_medium', 'cpc');
    set('utm_campaign', 'nvx_lineage_e2e');
    const privacy = form.querySelector('[name="privacy"]');
    if (privacy) privacy.checked = true;
    document.dispatchEvent(new Event('wp_listen_for_consent_change'));
    await new Promise((resolve) => setTimeout(resolve, 100));
    set('nvx_marketing_consent', '1');
    set('gclid', gclid);
    return {
      leadId: String(form.querySelector('[name="nvx_lead_id"]')?.value || '').toLowerCase(),
      marketing: String(form.querySelector('[name="nvx_marketing_consent"]')?.value || ''),
    };
  }, { email, gclid });

  assert.ok(directState, 'First-party direct form must remain present');
  assert.equal(directState.leadId, browserLeadId,
    'Direct form and native HubSpot V4 must share exactly one browser lineage UUID');
  assert.equal(directState.marketing, '1');

  await Promise.all([
    page.waitForURL((url) => url.origin === BASE_URL && url.pathname === '/gracias/', { timeout: 25_000 }),
    page.evaluate(() => document.querySelector('[data-nvx-direct-form]')?.requestSubmit()),
  ]);

  const emailHash = createHash('sha256').update(email.toLowerCase()).digest('hex');
  const persistence = await assertSupabasePersistence({
    nvxLeadId: browserLeadId,
    emailHash,
    gclid,
    testRunId: String(consentState.qa.test_run_id),
  });

  const evidence = {
    schema: 1,
    environment: 'staging2',
    source: EVENT_NAME,
    form_id: consentState.formId,
    nvx_lead_id: browserLeadId,
    email,
    email_hash: emailHash,
    gclid,
    test_run_id: String(consentState.qa.test_run_id),
    success_url: page.url(),
    submitted_at: new Date().toISOString(),
    persistence,
  };
  await fs.writeFile(ARTIFACT_PATH, `${JSON.stringify(evidence, null, 2)}\n`, 'utf8');
  console.log(`ATTRIBUTION_LINEAGE_E2E=PASS nvx_lead_id=${browserLeadId} test_run_id=${evidence.test_run_id} persistence=${persistence.status}`);
} catch (error) {
  const message = error instanceof Error ? error.message : String(error);
  if (error instanceof PersistenceConfigError) {
    console.error(`ATTRIBUTION_LINEAGE_E2E=FAIL_CONFIG reason=${message}`);
    process.exitCode = EX_CONFIG;
  } else {
    console.error(`ATTRIBUTION_LINEAGE_E2E=${isTransient(error) ? 'TRANSIENT' : 'FAIL'} reason=${message}`);
    process.exitCode = isTransient(error) ? EX_TEMPFAIL : 1;
  }
} finally {
  await context.close().catch(() => {});
  await browser.close().catch(() => {});
}
