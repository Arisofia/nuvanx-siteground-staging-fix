import fs from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { chromium } from 'playwright';
import AxeBuilder from '@axe-core/playwright';
import { VIEWPORTS } from './published-pages-contract.mjs';
import {
  EX_CONFIG,
  EX_TEMPFAIL,
  SITEGROUND_CAPTCHA_PATH,
  isSiteGroundCaptchaInterruption,
  isSiteGroundTransientResponse,
} from './siteground-transient-classifier.mjs';

const baseUrl = (process.env.BASE_URL || 'https://staging2.nuvanx.com').replace(/\/$/, '');
const expectedSha = String(process.env.EXPECTED_SHA || '').trim();
const expectedHost = new URL(baseUrl).hostname;
const artifactsDir = fileURLToPath(new URL('./block-c-artifacts/', import.meta.url));
const outputPath = path.join(artifactsDir, 'clinical-evidence-runtime.json');
const matrixPath = fileURLToPath(new URL('../../wp-content/themes/nuvanx-medical/inc/data/clinical-matrix.json', import.meta.url));
const DISCLAIMER = 'Los datos siguientes describen estudios publicados y su contexto. No equivalen a una promesa de resultado individual ni sustituyen la valoración médica.';
const ROUTES = Object.freeze([
  Object.freeze({ path: '/endolift-facial-papada-mandibula/', treatmentId: 'endolift_facial' }),
  Object.freeze({ path: '/laser-co2-fraccionado-madrid-textura-cicatrices-poro/', treatmentId: 'laser_co2' }),
  Object.freeze({ path: '/exion-face/', treatmentId: 'exion_face' }),
]);

const DEFAULT_RUNTIME_TIMEOUT_MS = 4 * 60 * 1000;
const runtimeBudgetMs = Number.parseInt(process.env.CLINICAL_EVIDENCE_TIMEOUT_MS || '', 10) || DEFAULT_RUNTIME_TIMEOUT_MS;
const runtimeDeadline = Date.now() + runtimeBudgetMs;
const MAX_NAVIGATION_ATTEMPTS = 3;

class RealFailure extends Error {
  constructor(message) {
    super(message);
    this.name = 'RealFailure';
  }
}

class TransientFailure extends Error {
  constructor(message) {
    super(message);
    this.name = 'TransientFailure';
  }
}

function normalizeText(value) {
  return String(value || '').replace(/\s+/g, ' ').trim();
}

function sleep(ms) {
  return new Promise((resolve) => setTimeout(resolve, ms));
}

function assertReal(condition, message) {
  if (!condition) throw new RealFailure(message);
}

function validateEvidenceRowMetadata(evidence, treatmentId) {
  for (const field of ['study_type', 'sample_size', 'title', 'summary', 'limitation', 'source_label', 'source_url', 'pmid']) {
    const value = String(evidence?.[field] || '').trim();
    assertReal(value !== '', `empty_field_${field}_${treatmentId}_${evidence?.pmid || 'unknown'}`);
  }

  const pmidStr = String(evidence.pmid).trim();
  assertReal(/^\d+$/.test(pmidStr), `invalid_pmid_format_${treatmentId}_${pmidStr}`);

  let parsedUrl;
  try {
    parsedUrl = new URL(evidence.source_url);
  } catch {
    throw new RealFailure(`malformed_source_url_${treatmentId}_${pmidStr}`);
  }
  assertReal(parsedUrl.protocol === 'https:', `non_https_source_url_${treatmentId}_${pmidStr}`);
  assertReal(parsedUrl.hostname === 'pubmed.ncbi.nlm.nih.gov', `non_pubmed_host_${treatmentId}_${parsedUrl.hostname}`);
  const urlPmid = parsedUrl.pathname.replace(/^\/+|\/+$/g, '').split('/')[0];
  assertReal(urlPmid === pmidStr, `url_pmid_mismatch_${treatmentId}_expected_${pmidStr}_got_${urlPmid}`);

  assertReal(/PubMed/i.test(evidence.source_label), `label_missing_pubmed_${treatmentId}_${pmidStr}`);
  const labelMatch = evidence.source_label.match(/\bPMID\s*:?\s*(\d+)\b/i);
  assertReal(Boolean(labelMatch) && labelMatch[1] === pmidStr, `label_pmid_mismatch_${treatmentId}_expected_${pmidStr}`);
}

function screenshotName(routePath, viewportKey) {
  const routeKey = routePath.replace(/^\/+|\/+$/g, '').replace(/[^a-z0-9]+/gi, '-').toLowerCase();
  return `clinical-evidence-${routeKey}-${viewportKey}.png`;
}

async function navigatePublic(page, url) {
  let lastTransient = 'unknown';
  for (let attempt = 1; attempt <= MAX_NAVIGATION_ATTEMPTS; attempt += 1) {
    const remainingMs = runtimeDeadline - Date.now();
    if (remainingMs <= 2000) {
      throw new TransientFailure(`clinical_runtime_budget_exhausted deadline=${runtimeBudgetMs}ms`);
    }
    const attemptTimeoutMs = Math.min(20_000, Math.max(5000, remainingMs - 2000));
    try {
      const response = await page.goto(url, { waitUntil: 'domcontentloaded', timeout: attemptTimeoutMs });
      const currentUrl = page.url();
      const status = response?.status() || 0;
      const headers = response ? await response.allHeaders() : {};
      if (
        !response
        || isSiteGroundTransientResponse(status, headers, currentUrl)
        || currentUrl.includes(SITEGROUND_CAPTCHA_PATH)
      ) {
        lastTransient = `attempt=${attempt} status=${status || 0} url=${currentUrl}`;
        await sleep(1000 * attempt);
        continue;
      }
      return response;
    } catch (error) {
      const currentUrl = page.url();
      const message = error instanceof Error ? error.message : String(error);
      const networkLike = /timeout|timed out|net::ERR_|navigation failed|target page.*closed/i.test(message);
      if (isSiteGroundCaptchaInterruption(error, currentUrl) || networkLike || currentUrl.includes(SITEGROUND_CAPTCHA_PATH)) {
        lastTransient = `attempt=${attempt} error=${normalizeText(message)} url=${currentUrl}`;
        await sleep(1000 * attempt);
        continue;
      }
      throw error;
    }
  }
  throw new TransientFailure(`public_navigation_exhausted ${lastTransient}`);
}

async function inspectCase(browser, route, viewport, treatment) {
  const context = await browser.newContext({
    viewport: { width: viewport.width, height: viewport.height },
    deviceScaleFactor: 1,
  });
  const page = await context.newPage();
  const url = `${baseUrl}${route.path}`;
  const result = {
    route: route.path,
    treatmentId: route.treatmentId,
    viewport: { key: viewport.key, label: viewport.label, width: viewport.width, height: viewport.height },
    status: 'FAIL_REAL',
    httpStatus: 0,
    deploySha: '',
    sourceCount: treatment.evidence.length,
    pmids: treatment.evidence.map((item) => String(item.pmid)),
    axeSeriousCritical: null,
    section: null,
    error: '',
  };

  try {
    const response = await navigatePublic(page, url);
    result.httpStatus = response.status();
    assertReal(response.status() === 200, `http_status_${response.status()}`);

    const finalUrl = new URL(page.url());
    assertReal(finalUrl.hostname === expectedHost, `host_mismatch_${finalUrl.hostname}`);
    assertReal(finalUrl.pathname === route.path, `path_mismatch_${finalUrl.pathname}`);

    const deploySha = normalizeText(await page.locator('meta[name="nvx-deploy-sha"]').first().getAttribute('content'));
    result.deploySha = deploySha;
    assertReal(deploySha === expectedSha, `deploy_sha_mismatch_${deploySha || 'missing'}`);

    const selector = `section[data-nvx-clinical-evidence="${route.treatmentId}"]`;
    const section = page.locator(selector);
    const sectionCount = await section.count();
    assertReal(sectionCount === 1, `clinical_section_count_${sectionCount}`);
    assertReal(await section.isVisible(), 'clinical_section_not_visible');

    const sectionData = await section.evaluate((node) => {
      const rect = node.getBoundingClientRect();
      const style = getComputedStyle(node);
      const labelledBy = node.getAttribute('aria-labelledby') || '';
      const label = labelledBy ? document.getElementById(labelledBy) : null;
      const descendants = Array.from(node.querySelectorAll('*'));
      const overflow = descendants
        .map((el) => {
          const box = el.getBoundingClientRect();
          const elStyle = getComputedStyle(el);
          if (elStyle.display === 'none' || elStyle.visibility === 'hidden' || Number(elStyle.opacity) === 0) return null;
          if (box.width <= 0 || box.height <= 0) return null;
          const leftOverflow = Math.max(0, -box.left);
          const rightOverflow = Math.max(0, box.right - window.innerWidth);
          return leftOverflow > 2 || rightOverflow > 2
            ? { tag: el.tagName, className: String(el.className || ''), left: box.left, right: box.right }
            : null;
        })
        .filter(Boolean);
      return {
        rect: { top: rect.top, left: rect.left, right: rect.right, width: rect.width, height: rect.height },
        display: style.display,
        visibility: style.visibility,
        opacity: style.opacity,
        labelledBy,
        labelText: label ? (label.textContent || '').replace(/\s+/g, ' ').trim() : '',
        documentScrollWidth: document.documentElement.scrollWidth,
        innerWidth: window.innerWidth,
        overflow,
      };
    });
    result.section = sectionData;
    assertReal(sectionData.rect.width > 0 && sectionData.rect.height > 0, 'clinical_section_zero_geometry');
    assertReal(sectionData.rect.left >= -2 && sectionData.rect.right <= viewport.width + 2, 'clinical_section_horizontal_overflow');
    assertReal(sectionData.documentScrollWidth <= sectionData.innerWidth + 2, `document_horizontal_overflow_${sectionData.documentScrollWidth}_${sectionData.innerWidth}`);
    assertReal(sectionData.overflow.length === 0, `clinical_descendant_overflow_${sectionData.overflow.length}`);
    assertReal(Boolean(sectionData.labelledBy) && Boolean(sectionData.labelText), 'clinical_section_aria_label_missing');

    const sectionText = normalizeText(await section.innerText());
    assertReal(sectionText.includes(normalizeText(DISCLAIMER)), 'clinical_disclaimer_missing');

    const articles = section.locator('article.nvx-clinical-note');
    const articleCount = await articles.count();
    assertReal(articleCount === treatment.evidence.length, `clinical_item_count_${articleCount}_expected_${treatment.evidence.length}`);

    for (const evidence of treatment.evidence) {
      validateEvidenceRowMetadata(evidence, route.treatmentId);

      const sourceLink = section.locator(`a[href="${evidence.source_url}"]`);
      const sourceLinkCount = await sourceLink.count();
      assertReal(sourceLinkCount === 1, `source_link_count_${evidence.pmid}_${sourceLinkCount}`);
      assertReal(await sourceLink.isVisible(), `source_link_not_visible_${evidence.pmid}`);
      assertReal(normalizeText(await sourceLink.innerText()) === normalizeText(evidence.source_label), `source_label_mismatch_${evidence.pmid}`);

      const article = sourceLink.locator('xpath=ancestor::article[contains(concat(" ", normalize-space(@class), " "), " nvx-clinical-note ")]');
      const sourceArticleCount = await article.count();
      assertReal(sourceArticleCount === 1, `source_article_missing_${evidence.pmid}`);

      // 1) Exact title validation
      const titleLoc = article.locator('h3.nvx-clinical-note__title');
      assertReal(await titleLoc.count() === 1, `source_title_missing_${evidence.pmid}`);
      assertReal(normalizeText(await titleLoc.innerText()) === normalizeText(evidence.title), `source_title_mismatch_${evidence.pmid}`);

      // 2) Exact meta validation (study_type · sample_size)
      const metaLoc = article.locator('p.nvx-brand-meta');
      assertReal(await metaLoc.count() === 1, `source_meta_missing_${evidence.pmid}`);
      const expectedMeta = [evidence.study_type, evidence.sample_size].filter(Boolean).join(' · ');
      assertReal(normalizeText(await metaLoc.innerText()) === normalizeText(expectedMeta), `source_meta_mismatch_${evidence.pmid}`);

      // 3) Exact summary validation
      const summaryLoc = article.locator('p.nvx-clinical-note__text');
      assertReal(await summaryLoc.count() === 1, `source_summary_missing_${evidence.pmid}`);
      assertReal(normalizeText(await summaryLoc.innerText()) === normalizeText(evidence.summary), `source_summary_mismatch_${evidence.pmid}`);

      // 4) Exact limitation validation
      const limitationLoc = article.locator('p.nvx-body:has(strong)');
      assertReal(await limitationLoc.count() === 1, `source_limitation_missing_${evidence.pmid}`);
      const limitationText = normalizeText(await limitationLoc.innerText());
      const expectedLimitation = normalizeText(`Límite de la evidencia: ${evidence.limitation}`);
      assertReal(limitationText === expectedLimitation, `source_limitation_mismatch_${evidence.pmid}`);
    }

    const axe = await new AxeBuilder({ page }).include(selector).analyze();
    const severe = axe.violations.filter((violation) => violation.impact === 'serious' || violation.impact === 'critical');
    result.axeSeriousCritical = severe.length;
    assertReal(severe.length === 0, `clinical_a11y_serious_critical_${severe.map((item) => item.id).join(',')}`);

    await fs.mkdir(artifactsDir, { recursive: true });
    await section.screenshot({ path: path.join(artifactsDir, screenshotName(route.path, viewport.key)) });

    result.status = 'PASS';
    result.error = '';
    console.log(`CLINICAL_EVIDENCE_CASE=PASS route=${route.path} treatment=${route.treatmentId} viewport=${viewport.key} sources=${treatment.evidence.length} sha=${expectedSha}`);
    return result;
  } catch (error) {
    if (error instanceof TransientFailure) {
      result.status = 'TRANSIENT_INFRASTRUCTURE';
      result.error = normalizeText(error.message);
      console.error(`CLINICAL_EVIDENCE_CASE=TRANSIENT_INFRASTRUCTURE route=${route.path} viewport=${viewport.key} reason=${result.error.replace(/\s+/g, '_')} classification=transient_infrastructure candidate_defect=not_established`);
      return result;
    }
    result.status = 'FAIL_REAL';
    result.error = normalizeText(error instanceof Error ? error.message : String(error));
    console.error(`CLINICAL_EVIDENCE_CASE=FAIL_REAL route=${route.path} viewport=${viewport.key} reason=${result.error.replace(/\s+/g, '_')}`);
    return result;
  } finally {
    await context.close().catch(() => {});
  }
}

async function writeResults(payload) {
  await fs.mkdir(artifactsDir, { recursive: true });
  await fs.writeFile(outputPath, `${JSON.stringify(payload, null, 2)}\n`, 'utf8');
}

async function main() {
  if (!/^[0-9a-f]{40}$/.test(expectedSha)) {
    console.error(`CLINICAL_EVIDENCE_RUNTIME=FAIL_CONFIG reason=invalid_expected_sha value=${expectedSha || 'missing'}`);
    process.exitCode = EX_CONFIG;
    return;
  }
  if (expectedHost !== 'staging2.nuvanx.com') {
    console.error(`CLINICAL_EVIDENCE_RUNTIME=FAIL_CONFIG reason=unexpected_host host=${expectedHost}`);
    process.exitCode = EX_CONFIG;
    return;
  }

  const matrix = JSON.parse(await fs.readFile(matrixPath, 'utf8'));
  const treatments = matrix?.treatments || {};
  for (const route of ROUTES) {
    assertReal(treatments[route.treatmentId] && Array.isArray(treatments[route.treatmentId].evidence), `ssot_missing_${route.treatmentId}`);
    for (const evidence of treatments[route.treatmentId].evidence) {
      validateEvidenceRowMetadata(evidence, route.treatmentId);
    }
  }

  const governedSources = ROUTES.reduce((sum, route) => sum + treatments[route.treatmentId].evidence.length, 0);
  assertReal(governedSources === 6, `ssot_source_count_${governedSources}_expected_6`);
  assertReal(VIEWPORTS.length === 3, `viewport_count_${VIEWPORTS.length}_expected_3`);

  const browser = await chromium.launch({ headless: true });
  const results = [];
  try {
    for (const route of ROUTES) {
      for (const viewport of VIEWPORTS) {
        if (Date.now() >= runtimeDeadline) {
          throw new TransientFailure(`clinical_runtime_budget_exhausted deadline=${runtimeBudgetMs}ms`);
        }
        results.push(await inspectCase(browser, route, viewport, treatments[route.treatmentId]));
      }
    }
  } catch (error) {
    if (error instanceof TransientFailure) {
      console.error(`CLINICAL_EVIDENCE_RUNTIME=TRANSIENT_INFRASTRUCTURE reason=${normalizeText(error.message).replace(/\s+/g, '_')}`);
    } else {
      throw error;
    }
  } finally {
    await browser.close().catch(() => {});
  }

  const payload = {
    schema: 1,
    generatedAt: new Date().toISOString(),
    baseUrl,
    expectedSha,
    treatments: ROUTES.length,
    sources: governedSources,
    viewports: VIEWPORTS.length,
    cases: results.length,
    pass: results.filter((item) => item.status === 'PASS').length,
    transient: results.filter((item) => item.status === 'TRANSIENT_INFRASTRUCTURE').length,
    failReal: results.filter((item) => item.status === 'FAIL_REAL').length,
    results,
  };
  await writeResults(payload);

  if (payload.failReal > 0) {
    console.error(`CLINICAL_EVIDENCE_RUNTIME=FAIL_REAL cases=${payload.cases} pass=${payload.pass} fail_real=${payload.failReal} transient=${payload.transient} sha=${expectedSha}`);
    process.exitCode = 1;
    return;
  }
  if (payload.transient > 0 || results.length < 9) {
    console.error(`CLINICAL_EVIDENCE_RUNTIME=TRANSIENT_INFRASTRUCTURE cases=${payload.cases} pass=${payload.pass} transient=${payload.transient} sha=${expectedSha} classification=transient_infrastructure candidate_defect=not_established`);
    process.exitCode = EX_TEMPFAIL;
    return;
  }

  assertReal(payload.cases === 9 && payload.pass === 9, `runtime_case_count_${payload.pass}_of_${payload.cases}`);
  console.log(`CLINICAL_EVIDENCE_RUNTIME=PASS treatments=3 cases=9 sources=6 viewports=3 sha=${expectedSha}`);
}

try {
  await main();
} catch (error) {
  if (error instanceof RealFailure) {
    console.error(`CLINICAL_EVIDENCE_RUNTIME=FAIL_REAL reason=${normalizeText(error.message).replace(/\s+/g, '_')}`);
    process.exitCode = 1;
  } else {
    console.error(`CLINICAL_EVIDENCE_RUNTIME=FAIL_REAL reason=unexpected_error_${normalizeText(error instanceof Error ? error.message : String(error)).replace(/\s+/g, '_')}`);
    process.exitCode = 1;
  }
}
