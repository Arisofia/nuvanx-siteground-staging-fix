import { chromium } from 'playwright';
import AxeBuilder from '@axe-core/playwright';
import fs from 'node:fs/promises';
import path from 'node:path';
import {
  SITEGROUND_CAPTCHA_PATH,
  EX_CONFIG,
  EX_TEMPFAIL,
  isSiteGroundCaptchaInterruption,
  isSiteGroundTransientResponse,
} from './siteground-transient-classifier.mjs';
import { createSiteGroundOriginVerifier } from './siteground-origin-verifier.mjs';

const baseUrl = (process.env.BASE_URL || 'https://staging2.nuvanx.com').replace(/\/$/, '');
const expectedSha = (process.env.EXPECTED_SHA || '').trim();
let baseParsed = null;
try {
  baseParsed = new URL(baseUrl);
} catch {
  console.error(`COMPLIANZ_FIRST_VISIT_MOBILE=FAIL_CONFIG reason=invalid_BASE_URL value=${baseUrl}`);
  process.exit(EX_CONFIG);
}
const expectedHost = baseParsed.hostname;
const viewport = { width: 390, height: 844 };
const maxAttempts = 3;
const minTouchTarget = 48;
const routes = ['/', '/contacto/', '/madrid/valoracion/'];
const outDir = path.resolve('scripts/staging2/complianz-first-visit-mobile-artifacts');
const consentCategoryNames = ['cmplz_functional', 'cmplz_preferences', 'cmplz_statistics', 'cmplz_marketing'];
const requiredDecisionNames = ['cmplz_functional', 'cmplz_statistics', 'cmplz_marketing'];
const durableSeconds = 60 * 60;

if (!/^[0-9a-f]{40}$/.test(expectedSha)) {
  console.error('COMPLIANZ_FIRST_VISIT_MOBILE=FAIL_CONFIG reason=EXPECTED_SHA_must_be_40_hex');
  process.exit(EX_CONFIG);
}
if (
  baseParsed.protocol !== 'https:'
  || expectedHost !== 'staging2.nuvanx.com'
  || baseParsed.port !== ''
  || baseParsed.username !== ''
  || baseParsed.password !== ''
  || baseParsed.pathname !== '/'
  || baseParsed.search !== ''
  || baseParsed.hash !== ''
) {
  console.error(`COMPLIANZ_FIRST_VISIT_MOBILE=FAIL_CONFIG reason=unexpected_BASE_URL value=${baseUrl}`);
  process.exit(EX_CONFIG);
}

await fs.rm(outDir, { recursive: true, force: true });
await fs.mkdir(outDir, { recursive: true });
let originVerifier = null;
try {
  originVerifier = createSiteGroundOriginVerifier({ expectedHost, expectedSha });
} catch (error) {
  console.error(`COMPLIANZ_FIRST_VISIT_MOBILE=FAIL_CONFIG reason=origin_verifier_configuration error=${error instanceof Error ? error.message : String(error)}`);
  process.exit(EX_CONFIG);
}

const bannerSelectors = [
  '.cmplz-cookiebanner',
  '#cmplz-cookiebanner-container .cmplz-cookiebanner',
  '#cmplz-cookiebanner-container',
];

const actionSelectors = {
  accept: [
    '.cmplz-cookiebanner .cmplz-accept',
    '#cmplz-cookiebanner-container .cmplz-accept',
    'button:has-text("Aceptar todo")',
    'button:has-text("Aceptar")',
    'button:has-text("Accept all")',
  ],
  deny: [
    '.cmplz-cookiebanner .cmplz-deny',
    '#cmplz-cookiebanner-container .cmplz-deny',
    'button:has-text("Denegar")',
    'button:has-text("Rechazar")',
    'button:has-text("Reject")',
  ],
};

function slugForRoute(route) {
  return route === '/' ? 'home' : route.replace(/^\/+|\/+$/g, '').replace(/[^a-z0-9-]+/gi, '-');
}

function normalizePathname(pathname) {
  const normalized = `/${String(pathname || '').replace(/^\/+|\/+$/g, '')}`;
  return normalized === '/' ? '/' : `${normalized}/`;
}

async function pollUntil(check, { timeoutMs = 8000, intervalMs = 150 } = {}) {
  const deadline = Date.now() + timeoutMs;
  let lastValue = null;
  while (Date.now() < deadline) {
    lastValue = await check();
    if (lastValue) return lastValue;
    await new Promise((resolve) => setTimeout(resolve, intervalMs));
  }
  return null;
}

async function waitForVisibleBanner(page, timeoutMs = 8000) {
  return pollUntil(async () => {
    for (const selector of bannerSelectors) {
      const locator = page.locator(selector).first();
      if (await locator.isVisible().catch(() => false)) return { locator, selector };
    }
    return null;
  }, { timeoutMs, intervalMs: 200 });
}

async function waitForBannerHidden(bannerLocator, timeoutMs = 8000) {
  return Boolean(await pollUntil(
    async () => !(await bannerLocator.isVisible().catch(() => false)),
    { timeoutMs, intervalMs: 150 },
  ));
}

async function findVisibleAction(page, action) {
  for (const selector of actionSelectors[action] || []) {
    const locator = page.locator(selector).first();
    if (await locator.isVisible().catch(() => false)) return { locator, selector };
  }
  return null;
}

async function waitForVisualStability(page) {
  await page.evaluate(async () => {
    if (document.fonts) await document.fonts.ready;
  }).catch(() => {});
  await page.waitForTimeout(350);
}

function classifyNavigationError(error, currentUrl) {
  const message = error instanceof Error ? error.message : String(error);
  if (isSiteGroundCaptchaInterruption(error, currentUrl)) {
    return { transient: true, reason: `SiteGround captcha navigation interruption: ${message}` };
  }
  if (
    /net::ERR_(?:CONNECTION|HTTP2|NETWORK|NAME_NOT_RESOLVED|SOCKET|PROXY|TUNNEL|ADDRESS|INTERNET_DISCONNECTED|TIMED_OUT)/i.test(message)
    || /Timeout \d+ms exceeded/i.test(message)
  ) {
    return { transient: true, reason: `Browser transport failure: ${message}` };
  }
  return { transient: false, reason: `Browser navigation failure: ${message}` };
}

async function pageIdentityFailure(page, route) {
  const currentUrl = page.url() || '';
  let finalUrl = null;
  try {
    finalUrl = new URL(currentUrl);
  } catch {
    return `Invalid final URL after navigation: ${currentUrl || 'missing'}`;
  }
  if (
    finalUrl.origin !== baseParsed.origin
    || finalUrl.username !== ''
    || finalUrl.password !== ''
    || finalUrl.port !== ''
    || normalizePathname(finalUrl.pathname) !== normalizePathname(route)
  ) {
    return `Unexpected final route: requested=${route} final=${finalUrl.href}`;
  }
  const metaSha = (await page.locator('meta[name="nvx-deploy-sha"]').getAttribute('content').catch(() => '')) || '';
  if (metaSha !== expectedSha) return `SHA mismatch: ${metaSha || 'missing'} != ${expectedSha}`;
  return '';
}

async function navigatePublicCandidate(page, route) {
  const url = `${baseUrl}${route}`;
  let response = null;
  try {
    response = await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 40000 });
  } catch (error) {
    const classification = classifyNavigationError(error, page.url() || '');
    return { pass: false, ...classification, transport: 'public-edge' };
  }

  const currentUrl = page.url() || '';
  const headers = response ? await response.allHeaders() : {};
  const status = response?.status() || 0;
  if (currentUrl.includes(SITEGROUND_CAPTCHA_PATH) || isSiteGroundTransientResponse(status, headers, currentUrl)) {
    return { pass: false, transient: true, reason: `SiteGround transient response: HTTP ${status}`, transport: 'public-edge' };
  }
  if (status !== 200) {
    return { pass: false, transient: false, reason: `Expected HTTP 200, got ${status}`, transport: 'public-edge' };
  }

  const identityFailure = await pageIdentityFailure(page, route);
  if (identityFailure) return { pass: false, transient: false, reason: identityFailure, transport: 'public-edge' };

  return { pass: true, transient: false, transport: 'public-edge', status, finalUrl: currentUrl };
}

function verifyOriginOnly(route) {
  if (!originVerifier.isAvailable()) {
    return { pass: false, transient: true, reason: 'SiteGround origin SSH unavailable' };
  }
  const origin = originVerifier.fetchHtml(route);
  if (!origin.pass) {
    const details = origin.stderr || origin.error || `origin status ${origin.originStatus ?? 0}`;
    const challengeFailure = /(?:http_code_(?:202|429|503)|captcha-(?:body|header))/i.test(details);
    return {
      pass: false,
      transient: origin.transportFailure === true || challengeFailure,
      reason: `Origin verification failed: ${details}`,
    };
  }
  if (origin.originStatus !== 200 || origin.originDeploySha !== expectedSha || !origin.html) {
    return {
      pass: false,
      transient: false,
      reason: `Origin contract mismatch: status=${origin.originStatus ?? 0} sha=${origin.originDeploySha || 'missing'}`,
    };
  }
  return { pass: true, transient: false, originStatus: origin.originStatus, originDeploySha: origin.originDeploySha };
}

async function navigateWithRecovery(page, route) {
  let lastTransient = null;
  for (let attempt = 1; attempt <= maxAttempts; attempt += 1) {
    const result = await navigatePublicCandidate(page, route);
    if (result.pass || !result.transient) return { ...result, attempt };
    lastTransient = result;
    console.warn(`COMPLIANZ_FIRST_VISIT_TRANSIENT route=${route} attempt=${attempt}/${maxAttempts} reason=${result.reason}`);
    if (attempt < maxAttempts) await page.waitForTimeout(2000 * attempt);
  }

  console.warn(`COMPLIANZ_FIRST_VISIT_ORIGIN_VERIFY=ATTEMPT route=${route}`);
  const origin = verifyOriginOnly(route);
  if (!origin.pass) return { ...origin, attempt: maxAttempts + 1, transport: 'origin-verification-only' };
  return {
    pass: false,
    transient: true,
    attempt: maxAttempts + 1,
    transport: 'origin-verification-only',
    originVerified: true,
    originStatus: origin.originStatus,
    originDeploySha: origin.originDeploySha,
    reason: `Public-edge first visit remained unavailable after ${maxAttempts} attempts; exact origin SHA verified`,
    publicReason: lastTransient?.reason || 'unknown',
  };
}

async function inspectOpenBanner(page, route) {
  await waitForVisualStability(page);
  const bannerMatch = await waitForVisibleBanner(page);
  if (!bannerMatch) {
    return { pass: false, transient: false, failures: ['Complianz banner is not visible in a clean first-visit context'] };
  }

  const h1 = page.locator('h1').first();
  if (!(await h1.isVisible().catch(() => false))) {
    return { pass: false, transient: false, failures: ['Primary H1 is not visible while consent banner is open'] };
  }

  const bannerBox = await bannerMatch.locator.boundingBox();
  const h1Box = await h1.boundingBox();
  if (!bannerBox || !h1Box) {
    return { pass: false, transient: false, failures: ['Unable to resolve banner/H1 geometry'] };
  }

  const pageMetrics = await page.evaluate(() => ({
    innerWidth: window.innerWidth,
    innerHeight: window.innerHeight,
    documentScrollWidth: document.documentElement.scrollWidth,
    bodyScrollWidth: document.body?.scrollWidth || 0,
  }));

  const overlapWidth = Math.max(0, Math.min(bannerBox.x + bannerBox.width, h1Box.x + h1Box.width) - Math.max(bannerBox.x, h1Box.x));
  const overlapHeight = Math.max(0, Math.min(bannerBox.y + bannerBox.height, h1Box.y + h1Box.height) - Math.max(bannerBox.y, h1Box.y));
  const h1Area = Math.max(1, h1Box.width * h1Box.height);
  const overlapRatio = (overlapWidth * overlapHeight) / h1Area;

  const accept = await findVisibleAction(page, 'accept');
  const deny = await findVisibleAction(page, 'deny');
  const failures = [];
  if (!accept) failures.push('Visible accept action is missing');
  if (!deny) failures.push('Visible reject/deny action is missing');

  const controlMetrics = [];
  const visibleButtons = bannerMatch.locator('button, .cmplz-btn');
  const buttonCount = await visibleButtons.count();
  for (let index = 0; index < buttonCount; index += 1) {
    const button = visibleButtons.nth(index);
    if (!(await button.isVisible().catch(() => false))) continue;
    const box = await button.boundingBox();
    if (!box) continue;
    const text = ((await button.textContent().catch(() => '')) || '').trim().replace(/\s+/g, ' ');
    controlMetrics.push({ text: text.slice(0, 120), width: box.width, height: box.height });
    if (box.width < minTouchTarget || box.height < minTouchTarget) {
      failures.push(`Consent control below ${minTouchTarget}px touch target: ${text || '(unnamed)'} ${Math.round(box.width)}x${Math.round(box.height)}`);
    }
  }

  const maxScrollWidth = Math.max(pageMetrics.documentScrollWidth, pageMetrics.bodyScrollWidth);
  if (maxScrollWidth > pageMetrics.innerWidth + 1) {
    failures.push(`Horizontal overflow while banner is open: scrollWidth=${maxScrollWidth} viewport=${pageMetrics.innerWidth}`);
  }
  if (bannerBox.x < -1 || bannerBox.x + bannerBox.width > pageMetrics.innerWidth + 1) {
    failures.push(`Consent banner exceeds viewport horizontally: x=${bannerBox.x} width=${bannerBox.width}`);
  }
  if (bannerBox.y < -1 || bannerBox.y + bannerBox.height > pageMetrics.innerHeight + 1) {
    failures.push(`Consent banner exceeds viewport vertically: y=${bannerBox.y} height=${bannerBox.height}`);
  }
  if (h1Box.y >= pageMetrics.innerHeight || h1Box.y + h1Box.height <= 0) {
    failures.push('Primary H1 is outside the first viewport while banner is open');
  }
  if (overlapRatio > 0.05) {
    failures.push(`Consent banner obscures more than 5% of H1 area: overlap=${(overlapRatio * 100).toFixed(1)}%`);
  }

  const axeResults = await new AxeBuilder({ page })
    .withTags(['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa'])
    .disableRules(['skip-link', 'region'])
    .analyze();
  const blockingAxe = (axeResults.violations || []).filter((violation) => violation.impact === 'critical' || violation.impact === 'serious');
  for (const violation of blockingAxe) failures.push(`Axe ${violation.impact}: ${violation.id} — ${violation.help}`);

  const screenshotPath = path.join(outDir, `${slugForRoute(route)}-banner-open.png`);
  await page.screenshot({ path: screenshotPath, fullPage: false }).catch(() => {});

  return {
    pass: failures.length === 0,
    transient: false,
    failures,
    bannerSelector: bannerMatch.selector,
    screenshot: screenshotPath,
    bannerBox,
    h1Box,
    overlapRatio,
    pageMetrics,
    controlMetrics,
    blockingAxe: blockingAxe.map((violation) => ({
      id: violation.id,
      impact: violation.impact,
      help: violation.help,
      targets: violation.nodes.slice(0, 3).map((node) => node.target),
    })),
  };
}

function summarizeConsentCookies(cookies) {
  const map = Object.fromEntries(cookies.filter((cookie) => cookie.name.startsWith('cmplz_')).map((cookie) => [cookie.name, cookie]));
  return Object.fromEntries(Object.entries(map).map(([name, cookie]) => [name, {
    value: cookie.value,
    expires: cookie.expires,
    durable: Number(cookie.expires) > Math.floor(Date.now() / 1000) + durableSeconds,
  }]));
}

function consentSemanticsPass(action, cookies) {
  const map = Object.fromEntries(cookies.map((cookie) => [cookie.name, cookie]));
  const expectedNonFunctional = action === 'accept' ? 'allow' : 'deny';
  const failures = [];

  for (const name of requiredDecisionNames) {
    if (!map[name]) failures.push(`Required consent cookie missing: ${name}`);
  }
  if (map.cmplz_functional && map.cmplz_functional.value !== 'allow') {
    failures.push(`cmplz_functional must be allow for ${action}, got ${map.cmplz_functional.value}`);
  }
  for (const name of ['cmplz_preferences', 'cmplz_statistics', 'cmplz_marketing']) {
    if (map[name] && map[name].value !== expectedNonFunctional) {
      failures.push(`${name} must be ${expectedNonFunctional} for ${action}, got ${map[name].value}`);
    }
  }
  for (const name of consentCategoryNames) {
    if (map[name] && !(Number(map[name].expires) > Math.floor(Date.now() / 1000) + durableSeconds)) {
      failures.push(`${name} is not durable beyond ${durableSeconds}s (expires=${map[name].expires})`);
    }
  }

  return { pass: failures.length === 0, failures, expectedNonFunctional };
}

async function waitForConsentState(context, action, timeoutMs = 8000) {
  return pollUntil(async () => {
    const cookies = await context.cookies(baseUrl);
    const semantics = consentSemanticsPass(action, cookies);
    return semantics.pass ? { cookies, semantics } : null;
  }, { timeoutMs, intervalMs: 150 });
}

async function inspectRoute(browser, route) {
  const context = await browser.newContext({
    viewport,
    ignoreHTTPSErrors: true,
    userAgent: 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 Mobile/15E148 NUVANX-CONSENT-FIRST-VISIT/1.0',
  });
  const page = await context.newPage();
  try {
    const navigation = await navigateWithRecovery(page, route);
    if (!navigation.pass) return { route, ...navigation };
    const inspection = await inspectOpenBanner(page, route);
    return { route, navigation, ...inspection };
  } finally {
    await context.close().catch(() => {});
  }
}

async function reloadForPersistence(page) {
  try {
    const response = await page.reload({ waitUntil: 'domcontentloaded', timeout: 40000 });
    if (!response) return { pass: false, transient: false, reason: 'Reload completed without an HTTP response' };
    const headers = await response.allHeaders();
    const status = response.status();
    const currentUrl = page.url() || '';
    if (currentUrl.includes(SITEGROUND_CAPTCHA_PATH) || isSiteGroundTransientResponse(status, headers, currentUrl)) {
      return { pass: false, transient: true, reason: `SiteGround transient response during persistence reload: HTTP ${status}` };
    }
    if (status !== 200) return { pass: false, transient: false, reason: `Unexpected reload status ${status}` };
    const identityFailure = await pageIdentityFailure(page, '/');
    if (identityFailure) return { pass: false, transient: false, reason: `Persistence reload identity failure: ${identityFailure}` };
    return { pass: true, transient: false, status, finalUrl: currentUrl };
  } catch (error) {
    const classification = classifyNavigationError(error, page.url() || '');
    return { pass: false, ...classification };
  }
}

async function verifyConsentPersistence(browser, action) {
  const context = await browser.newContext({
    viewport,
    ignoreHTTPSErrors: true,
    userAgent: 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 Mobile/15E148 NUVANX-CONSENT-FIRST-VISIT/1.0',
  });
  const page = await context.newPage();

  try {
    const navigation = await navigateWithRecovery(page, '/');
    if (!navigation.pass) return { action, ...navigation };

    const bannerMatch = await waitForVisibleBanner(page);
    if (!bannerMatch) return { action, pass: false, transient: false, reason: 'Consent banner missing before interaction' };
    const actionMatch = await findVisibleAction(page, action);
    if (!actionMatch) return { action, pass: false, transient: false, reason: `${action} action is not visible` };

    const box = await actionMatch.locator.boundingBox();
    if (!box || box.width < minTouchTarget || box.height < minTouchTarget) {
      return { action, pass: false, transient: false, reason: `${action} action is below ${minTouchTarget}px touch target`, box };
    }

    await actionMatch.locator.click({ timeout: 3000 });
    const bannerClosed = await waitForBannerHidden(bannerMatch.locator);
    if (!bannerClosed) return { action, pass: false, transient: false, reason: 'Consent banner remains visible after interaction' };

    const state = await waitForConsentState(context, action);
    if (!state) {
      const cookies = await context.cookies(baseUrl);
      const semantics = consentSemanticsPass(action, cookies);
      return {
        action,
        pass: false,
        transient: false,
        reason: `Consent cookie semantics did not settle: ${semantics.failures.join(' | ') || 'unknown'}`,
        cookies: summarizeConsentCookies(cookies),
      };
    }

    const beforeReload = summarizeConsentCookies(state.cookies);
    const reload = await reloadForPersistence(page);
    if (!reload.pass) return { action, ...reload, beforeReload };

    const postReloadState = await waitForConsentState(context, action, 5000);
    if (!postReloadState) {
      const cookies = await context.cookies(baseUrl);
      const semantics = consentSemanticsPass(action, cookies);
      return {
        action,
        pass: false,
        transient: false,
        reason: `Consent semantics changed after reload: ${semantics.failures.join(' | ') || 'unknown'}`,
        beforeReload,
        afterReload: summarizeConsentCookies(cookies),
      };
    }

    const persistedBanner = await waitForVisibleBanner(page, 1500);
    if (persistedBanner) {
      return { action, pass: false, transient: false, reason: 'Consent banner reappeared after reload despite durable category decision', beforeReload };
    }

    return {
      action,
      pass: true,
      transient: false,
      selector: actionMatch.selector,
      box,
      expectedNonFunctional: state.semantics.expectedNonFunctional,
      beforeReload,
      afterReload: summarizeConsentCookies(postReloadState.cookies),
    };
  } finally {
    await context.close().catch(() => {});
  }
}

const browser = await chromium.launch({ headless: true, args: ['--no-sandbox'] });
const routeResults = [];
let persistenceResults = [];
let realFailure = false;
let transientFailure = false;

try {
  for (const route of routes) {
    console.log(`COMPLIANZ_FIRST_VISIT_ROUTE=START route=${route} viewport=${viewport.width}x${viewport.height}`);
    const result = await inspectRoute(browser, route);
    routeResults.push(result);
    if (result.transient) transientFailure = true;
    else if (!result.pass) realFailure = true;
  }

  persistenceResults = [
    await verifyConsentPersistence(browser, 'accept'),
    await verifyConsentPersistence(browser, 'deny'),
  ];
  for (const result of persistenceResults) {
    if (result.transient) transientFailure = true;
    else if (!result.pass) realFailure = true;
  }

  const acceptResult = persistenceResults.find((result) => result.action === 'accept' && result.pass);
  const denyResult = persistenceResults.find((result) => result.action === 'deny' && result.pass);
  if (acceptResult && denyResult) {
    const acceptMarketing = acceptResult.afterReload?.cmplz_marketing?.value;
    const denyMarketing = denyResult.afterReload?.cmplz_marketing?.value;
    const acceptStatistics = acceptResult.afterReload?.cmplz_statistics?.value;
    const denyStatistics = denyResult.afterReload?.cmplz_statistics?.value;
    if (acceptMarketing === denyMarketing || acceptStatistics === denyStatistics) {
      realFailure = true;
      persistenceResults.push({
        action: 'cross-check',
        pass: false,
        transient: false,
        reason: `Accept and deny resolved to identical category decisions marketing=${acceptMarketing} statistics=${acceptStatistics}`,
      });
    }
  }
} finally {
  await browser.close().catch(() => {});
}

const evidence = {
  expectedSha,
  baseUrl,
  viewport,
  minTouchTarget,
  routes: routeResults,
  persistence: persistenceResults,
};
await fs.writeFile(path.join(outDir, 'results.json'), `${JSON.stringify(evidence, null, 2)}\n`, 'utf8');

for (const result of routeResults) {
  if (result.transient) {
    console.warn(`COMPLIANZ_FIRST_VISIT_ROUTE=TRANSIENT route=${result.route} reason=${result.reason || 'unknown'}`);
  } else if (!result.pass) {
    console.error(`COMPLIANZ_FIRST_VISIT_ROUTE=FAIL_REAL route=${result.route} failures=${(result.failures || [result.reason || 'unknown']).join(' | ')}`);
  } else {
    console.log(`COMPLIANZ_FIRST_VISIT_ROUTE=PASS route=${result.route} transport=${result.navigation?.transport || 'unknown'} overlap_pct=${((result.overlapRatio || 0) * 100).toFixed(1)} controls=${result.controlMetrics?.length || 0}`);
  }
}
for (const result of persistenceResults) {
  if (result.transient) {
    console.warn(`COMPLIANZ_FIRST_VISIT_PERSISTENCE=TRANSIENT action=${result.action} reason=${result.reason || 'unknown'}`);
  } else if (!result.pass) {
    console.error(`COMPLIANZ_FIRST_VISIT_PERSISTENCE=FAIL_REAL action=${result.action} reason=${result.reason || 'unknown'}`);
  } else {
    console.log(`COMPLIANZ_FIRST_VISIT_PERSISTENCE=PASS action=${result.action} expected_nonfunctional=${result.expectedNonFunctional}`);
  }
}

if (process.env.GITHUB_STEP_SUMMARY) {
  const summary = [
    '',
    '### Complianz first-visit mobile contract',
    `- Viewport: \`${viewport.width}x${viewport.height}\``,
    `- Minimum touch target: \`${minTouchTarget}px\``,
    ...routeResults.map((result) => `- \`${result.route}\`: ${result.transient ? 'TRANSIENT' : result.pass ? 'PASS' : 'FAIL_REAL'}`),
    ...persistenceResults.map((result) => `- persistence \`${result.action}\`: ${result.transient ? 'TRANSIENT' : result.pass ? 'PASS' : 'FAIL_REAL'}`),
    '',
  ];
  await fs.appendFile(process.env.GITHUB_STEP_SUMMARY, `${summary.join('\n')}\n`, 'utf8').catch(() => {});
}

if (realFailure) {
  console.error('COMPLIANZ_FIRST_VISIT_MOBILE=FAIL_REAL');
  process.exit(1);
}
if (transientFailure) {
  console.error(`COMPLIANZ_FIRST_VISIT_MOBILE=FAIL_TRANSIENT exit=${EX_TEMPFAIL} classification=transient_infrastructure candidate_defect=not_established`);
  process.exit(EX_TEMPFAIL);
}

console.log(`COMPLIANZ_FIRST_VISIT_MOBILE=PASS routes=${routeResults.length} persistence=${persistenceResults.length} viewport=${viewport.width}x${viewport.height}`);