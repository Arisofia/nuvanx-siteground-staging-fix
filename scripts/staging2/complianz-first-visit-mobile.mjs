import { chromium } from 'playwright';
import AxeBuilder from '@axe-core/playwright';
import fs from 'node:fs/promises';
import path from 'node:path';
import {
  SITEGROUND_CAPTCHA_PATH,
  EX_TEMPFAIL,
  isSiteGroundTransientResponse,
} from './siteground-transient-classifier.mjs';
import { createSiteGroundOriginVerifier } from './siteground-origin-verifier.mjs';

const baseUrl = (process.env.BASE_URL || 'https://staging2.nuvanx.com').replace(/\/$/, '');
const expectedSha = (process.env.EXPECTED_SHA || '').trim();
const expectedHost = new URL(baseUrl).hostname;
const viewport = { width: 390, height: 844 };
const maxAttempts = 3;
const minTouchTarget = 48;
const routes = ['/', '/contacto/', '/madrid/valoracion/'];
const outDir = path.resolve('scripts/staging2/complianz-first-visit-mobile-artifacts');

if (!/^[0-9a-f]{40}$/.test(expectedSha)) {
  console.error('COMPLIANZ_FIRST_VISIT_MOBILE=FAIL_REAL reason=EXPECTED_SHA_must_be_40_hex');
  process.exit(1);
}
if (expectedHost !== 'staging2.nuvanx.com') {
  console.error(`COMPLIANZ_FIRST_VISIT_MOBILE=FAIL_REAL reason=unexpected_host host=${expectedHost}`);
  process.exit(1);
}

await fs.mkdir(outDir, { recursive: true });
const originVerifier = createSiteGroundOriginVerifier({ expectedHost, expectedSha });

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

async function waitForVisibleBanner(page, timeoutMs = 8000) {
  const deadline = Date.now() + timeoutMs;
  while (Date.now() < deadline) {
    for (const selector of bannerSelectors) {
      const locator = page.locator(selector).first();
      if (await locator.isVisible().catch(() => false)) {
        return { locator, selector };
      }
    }
    await page.waitForTimeout(200);
  }
  return null;
}

async function findVisibleAction(page, action) {
  for (const selector of actionSelectors[action] || []) {
    const locator = page.locator(selector).first();
    if (await locator.isVisible().catch(() => false)) {
      return { locator, selector };
    }
  }
  return null;
}

async function waitForVisualStability(page) {
  await page.evaluate(async () => {
    if (document.fonts) await document.fonts.ready;
  }).catch(() => {});
  await page.waitForTimeout(350);
}

async function installOriginDocumentFallback(page, route) {
  if (!originVerifier.isAvailable()) {
    return { pass: false, transient: true, reason: 'SiteGround origin SSH unavailable' };
  }

  const origin = originVerifier.fetchHtml(route);
  if (!origin.pass) {
    const details = origin.stderr || origin.error || `origin status ${origin.originStatus ?? 0}`;
    return {
      pass: false,
      transient: origin.transportFailure === true,
      reason: `Origin HTML verification failed: ${details}`,
    };
  }
  if (origin.originStatus !== 200 || origin.originDeploySha !== expectedSha || !origin.html) {
    return {
      pass: false,
      transient: false,
      reason: `Origin HTML contract mismatch: status=${origin.originStatus ?? 0} sha=${origin.originDeploySha || 'missing'}`,
    };
  }

  const targetUrl = `${baseUrl}${route}`;
  await page.route(targetUrl, async (routeHandle) => {
    if (!routeHandle.request().isNavigationRequest()) {
      await routeHandle.continue();
      return;
    }
    await routeHandle.fulfill({
      status: 200,
      contentType: 'text/html; charset=utf-8',
      headers: {
        'cache-control': 'no-store',
        'x-nvx-validation-transport': 'siteground-origin-document',
      },
      body: origin.html,
    });
  }, { times: 1 });

  return { pass: true, transient: false, origin };
}

async function navigateCandidate(page, route, { useOriginDocument = false } = {}) {
  const url = `${baseUrl}${route}`;
  let originFallback = null;

  if (useOriginDocument) {
    originFallback = await installOriginDocumentFallback(page, route);
    if (!originFallback.pass) {
      return {
        pass: false,
        transient: originFallback.transient,
        reason: originFallback.reason,
        transport: 'siteground-origin-document',
      };
    }
  }

  let response = null;
  try {
    response = await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 40000 });
  } catch (error) {
    const currentUrl = page.url() || '';
    const message = error instanceof Error ? error.message : String(error);
    if (!useOriginDocument && currentUrl.includes(SITEGROUND_CAPTCHA_PATH)) {
      return { pass: false, transient: true, reason: `SiteGround captcha challenge: ${currentUrl}`, transport: 'public-edge' };
    }
    return {
      pass: false,
      transient: !useOriginDocument,
      reason: `Navigation failed: ${message}`,
      transport: useOriginDocument ? 'siteground-origin-document' : 'public-edge',
    };
  }

  const currentUrl = page.url() || '';
  const headers = response ? await response.allHeaders() : {};
  const status = response?.status() || 0;
  if (!useOriginDocument && (currentUrl.includes(SITEGROUND_CAPTCHA_PATH) || isSiteGroundTransientResponse(status, headers, currentUrl))) {
    return { pass: false, transient: true, reason: `SiteGround transient response: HTTP ${status}`, transport: 'public-edge' };
  }
  if (status !== 200) {
    return {
      pass: false,
      transient: false,
      reason: `Expected HTTP 200, got ${status}`,
      transport: useOriginDocument ? 'siteground-origin-document' : 'public-edge',
    };
  }

  const metaSha = (await page.locator('meta[name="nvx-deploy-sha"]').getAttribute('content').catch(() => '')) || '';
  if (metaSha !== expectedSha) {
    return {
      pass: false,
      transient: false,
      reason: `SHA mismatch: ${metaSha || 'missing'} != ${expectedSha}`,
      transport: useOriginDocument ? 'siteground-origin-document' : 'public-edge',
    };
  }

  return {
    pass: true,
    transient: false,
    transport: useOriginDocument ? 'siteground-origin-document' : 'public-edge',
    originStatus: useOriginDocument ? originFallback?.origin?.originStatus : undefined,
    originDeploySha: useOriginDocument ? originFallback?.origin?.originDeploySha : undefined,
  };
}

async function navigateWithRecovery(page, route) {
  for (let attempt = 1; attempt <= maxAttempts; attempt += 1) {
    const result = await navigateCandidate(page, route);
    if (result.pass || !result.transient) return { ...result, attempt };
    console.warn(`COMPLIANZ_FIRST_VISIT_TRANSIENT route=${route} attempt=${attempt}/${maxAttempts} reason=${result.reason}`);
    if (attempt < maxAttempts) await page.waitForTimeout(2000 * attempt);
  }

  console.warn(`COMPLIANZ_FIRST_VISIT_ORIGIN_FALLBACK=ATTEMPT route=${route}`);
  const originResult = await navigateCandidate(page, route, { useOriginDocument: true });
  return { ...originResult, attempt: maxAttempts + 1 };
}

async function inspectOpenBanner(page, route, transport) {
  await waitForVisualStability(page);
  const bannerMatch = await waitForVisibleBanner(page);
  if (!bannerMatch) {
    return {
      pass: false,
      transient: transport === 'siteground-origin-document',
      failures: ['Complianz banner is not visible in a clean first-visit context'],
    };
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
  for (const violation of blockingAxe) {
    failures.push(`Axe ${violation.impact}: ${violation.id} — ${violation.help}`);
  }

  await page.screenshot({
    path: path.join(outDir, `${slugForRoute(route)}-banner-open.png`),
    fullPage: false,
  }).catch(() => {});

  return {
    pass: failures.length === 0,
    transient: false,
    failures,
    bannerSelector: bannerMatch.selector,
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

async function inspectRoute(browser, route) {
  const context = await browser.newContext({
    viewport,
    ignoreHTTPSErrors: true,
    userAgent: 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 Mobile/15E148 NUVANX-CONSENT-FIRST-VISIT/1.0',
  });
  const page = await context.newPage();

  try {
    const navigation = await navigateWithRecovery(page, route);
    if (!navigation.pass) {
      return { route, ...navigation };
    }
    const inspection = await inspectOpenBanner(page, route, navigation.transport);
    return { route, navigation, ...inspection };
  } finally {
    await context.close().catch(() => {});
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
    if (navigation.transport !== 'public-edge') {
      return {
        action,
        pass: false,
        transient: true,
        reason: 'Consent persistence requires a public-edge navigation after origin fallback was needed',
      };
    }

    const bannerMatch = await waitForVisibleBanner(page);
    if (!bannerMatch) {
      return { action, pass: false, transient: false, reason: 'Consent banner missing before interaction' };
    }
    const actionMatch = await findVisibleAction(page, action);
    if (!actionMatch) {
      return { action, pass: false, transient: false, reason: `${action} action is not visible` };
    }

    const box = await actionMatch.locator.boundingBox();
    if (!box || box.width < minTouchTarget || box.height < minTouchTarget) {
      return {
        action,
        pass: false,
        transient: false,
        reason: `${action} action is below ${minTouchTarget}px touch target`,
        box,
      };
    }

    await actionMatch.locator.click({ timeout: 3000 });
    await page.waitForTimeout(500);
    if (await bannerMatch.locator.isVisible().catch(() => false)) {
      return { action, pass: false, transient: false, reason: 'Consent banner remains visible after interaction' };
    }

    const consentCookies = (await context.cookies(baseUrl)).filter((cookie) => cookie.name.startsWith('cmplz_'));
    if (consentCookies.length === 0) {
      return { action, pass: false, transient: false, reason: 'No Complianz persistence cookie was written after interaction' };
    }

    const reloadResponse = await page.reload({ waitUntil: 'domcontentloaded', timeout: 40000 }).catch(() => null);
    if (!reloadResponse) {
      return { action, pass: false, transient: true, reason: 'Reload failed while verifying persisted consent' };
    }
    const headers = await reloadResponse.allHeaders();
    const status = reloadResponse.status();
    const currentUrl = page.url() || '';
    if (currentUrl.includes(SITEGROUND_CAPTCHA_PATH) || isSiteGroundTransientResponse(status, headers, currentUrl)) {
      return { action, pass: false, transient: true, reason: `SiteGround transient response during persistence reload: HTTP ${status}` };
    }
    if (status !== 200) {
      return { action, pass: false, transient: false, reason: `Unexpected reload status ${status}` };
    }

    await page.waitForTimeout(800);
    const persistedBanner = await waitForVisibleBanner(page, 1200);
    if (persistedBanner) {
      return { action, pass: false, transient: false, reason: 'Consent banner reappeared after reload despite stored Complianz cookies' };
    }

    return {
      action,
      pass: true,
      transient: false,
      cookieNames: consentCookies.map((cookie) => cookie.name).sort(),
      selector: actionMatch.selector,
      box,
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
    console.warn(`COMPLIANZ_FIRST_VISIT_ROUTE=TRANSIENT route=${result.route} reason=${result.reason || result.failures?.join(' | ') || 'unknown'}`);
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
    console.log(`COMPLIANZ_FIRST_VISIT_PERSISTENCE=PASS action=${result.action} cookies=${result.cookieNames.length}`);
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
  console.error(`COMPLIANZ_FIRST_VISIT_MOBILE=FAIL_TRANSIENT exit=${EX_TEMPFAIL}`);
  process.exit(EX_TEMPFAIL);
}

console.log(`COMPLIANZ_FIRST_VISIT_MOBILE=PASS routes=${routeResults.length} persistence=${persistenceResults.length} viewport=${viewport.width}x${viewport.height}`);
