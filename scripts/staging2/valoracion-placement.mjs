import { spawn } from 'node:child_process';
import fs from 'node:fs/promises';
import { setTimeout as delay } from 'node:timers/promises';
import { fileURLToPath } from 'node:url';
import { EX_TEMPFAIL } from './siteground-transient-classifier.mjs';


const activeChildren = new Set();
['SIGTERM', 'SIGINT'].forEach((sig) => {
  process.once(sig, () => {
    console.error(`VALORACION_ORCHESTRATOR=TERMINATED signal=${sig} active_children=${activeChildren.size}`);
    for (const child of activeChildren) {
      if (child.exitCode === null && child.signalCode === null) {
        child.kill('SIGKILL');
      }
    }
    process.exit(sig === 'SIGINT' ? 130 : 143);
  });
});

function runProcess(moduleUrl) {
  return new Promise((resolve, reject) => {
    const child = spawn(process.execPath, [fileURLToPath(moduleUrl)], {
      env: process.env,
      stdio: 'inherit',
    });

    activeChildren.add(child);
    child.once('error', (err) => {
      activeChildren.delete(child);
      reject(err);
    });
    child.once('exit', (code, signal) => {
      activeChildren.delete(child);
      if (signal) {
        reject(new Error(`Terminated by signal ${signal}`));
        return;
      }
      resolve(Number.isInteger(code) ? code : 1);
    });
  });
}

const STAGE_EVIDENCE_MAP = {
  'meta-no-consent': {
    source: fileURLToPath(new URL('./meta-no-consent-artifacts/results.json', import.meta.url)),
    destinationDir: fileURLToPath(new URL('./valoracion-artifacts', import.meta.url)),
    destination: fileURLToPath(new URL('./valoracion-artifacts/meta-no-consent-results.json', import.meta.url)),
  },
  'complianz-first-visit-mobile': {
    source: fileURLToPath(new URL('./complianz-first-visit-mobile-artifacts/results.json', import.meta.url)),
    destinationDir: fileURLToPath(new URL('./valoracion-artifacts', import.meta.url)),
    destination: fileURLToPath(new URL('./valoracion-artifacts/complianz-first-visit-mobile-results.json', import.meta.url)),
  },
  'block-a11y': {
    source: fileURLToPath(new URL('./block-a11y-artifacts/results.json', import.meta.url)),
    destinationDir: fileURLToPath(new URL('./valoracion-artifacts', import.meta.url)),
    destination: fileURLToPath(new URL('./valoracion-artifacts/block-a11y-results.json', import.meta.url)),
  },
};

async function prepareAllStageEvidence() {
  for (const config of Object.values(STAGE_EVIDENCE_MAP)) {
    await fs.rm(config.source, { recursive: true, force: true }).catch((err) => {
      console.warn(`STAGING_ACCEPTANCE_EVIDENCE=CLEANUP_WARN path=${config.source} error=${err instanceof Error ? err.message : String(err)}`);
    });
    await fs.rm(config.destination, { recursive: true, force: true }).catch((err) => {
      console.warn(`STAGING_ACCEPTANCE_EVIDENCE=CLEANUP_WARN path=${config.destination} error=${err instanceof Error ? err.message : String(err)}`);
    });
  }
}

async function preserveStageEvidence(component) {
  const config = STAGE_EVIDENCE_MAP[component];
  if (!config) return true;

  try {
    await fs.mkdir(config.destinationDir, { recursive: true });
    await fs.copyFile(config.source, config.destination);
    console.log(`STAGING_ACCEPTANCE_EVIDENCE=PRESERVED component=${component} path=${config.destination}`);
    return true;
  } catch (error) {
    console.warn(
      `STAGING_ACCEPTANCE_EVIDENCE=UNAVAILABLE component=${component} error=${error instanceof Error ? error.message : String(error)}`
    );
    return false;
  }
}

async function writeRollbackState(value, component, reason) {
  const envFile = (process.env.GITHUB_ENV || '').trim();
  if (!envFile) return;
  try {
    await fs.appendFile(envFile, `STAGING_MUTATION_ARMED=${value}\n`, 'utf8');
    console.log(`STAGING_ACCEPTANCE_ROLLBACK=${value === '1' ? 'REARMED' : 'DISARMED'} component=${component} reason=${reason}`);
  } catch (error) {
    console.warn(`STAGING_ACCEPTANCE_ROLLBACK=WRITE_FAILED component=${component} error=${error instanceof Error ? error.message : String(error)}`);
  }
}

async function disarmRollbackAfterTransientExhaustion(component) {
  const summary = (process.env.GITHUB_STEP_SUMMARY || '').trim();
  if (!summary) return;
  try {
    await fs.appendFile(
      summary,
      `\n### Staging acceptance transient exhaustion\n\nComponent \`${component}\` remained inconclusive after all bounded retry cycles. No deterministic defect was established; this run is not eligible for Production acceptance.\n`,
      'utf8'
    );
  } catch (error) {
    console.warn(`STAGING_ACCEPTANCE_SUMMARY=WRITE_FAILED component=${component} error=${error instanceof Error ? error.message : String(error)}`);
  }
}

async function recordMissingEvidence(component) {
  await writeRollbackState('0', component, 'required-evidence-unavailable');
  const summary = (process.env.GITHUB_STEP_SUMMARY || '').trim();
  if (summary) {
    try {
      await fs.appendFile(
        summary,
        `\n### Staging acceptance evidence unavailable\n\nComponent \`${component}\` passed, but its required evidence file could not be preserved. No deterministic site defect was established, so rollback was disarmed; this run is not eligible for Production acceptance and the same immutable SHA must be retried on a fresh runner.\n`,
        'utf8'
      );
    } catch (error) {
      console.warn(`STAGING_ACCEPTANCE_SUMMARY=WRITE_FAILED component=${component} error=${error instanceof Error ? error.message : String(error)}`);
    }
  }
  console.error(`STAGING_ACCEPTANCE_COMPONENT=FAIL_TRANSIENT component=${component} reason=required_evidence_unavailable exit=${EX_TEMPFAIL}`);
}

async function runStage(name, moduleUrl, maxCycles = 1, backoffMs = 3500) {
  let lastExitCode = 1;
  let sawTransient = false;

  for (let cycle = 1; cycle <= maxCycles; cycle += 1) {
    if (maxCycles > 1) {
      console.log(`STAGING_ACCEPTANCE_CYCLE component=${name} cycle=${cycle}/${maxCycles}`);
    }

    let processError = null;
    let evidencePreserved = true;
    try {
      lastExitCode = await runProcess(moduleUrl);
    } catch (err) {
      processError = err;
    } finally {
      evidencePreserved = await preserveStageEvidence(name);
    }

    if (processError) {
      console.error(`STAGING_ACCEPTANCE_COMPONENT=FAIL component=${name} reason=${processError instanceof Error ? processError.message : String(processError)}`);
      return 1;
    }

    if (lastExitCode === 0) {
      if (!evidencePreserved) {
        await recordMissingEvidence(name);
        return EX_TEMPFAIL;
      }
      if (sawTransient) {
        await writeRollbackState('1', name, 'transient-recovered');
        const envFile = (process.env.GITHUB_ENV || '').trim();
        if (envFile) {
          try {
            await fs.appendFile(envFile, 'STAGING_ACCEPTANCE_TRANSIENT=0\n', 'utf8');
            console.log(`STAGING_ACCEPTANCE_TRANSIENT=RESET component=${name} reason=transient-recovered`);
          } catch (err) {
            console.warn(`STAGING_ACCEPTANCE_TRANSIENT=RESET_FAILED component=${name} error=${err instanceof Error ? err.message : String(err)}`);
          }
        }
      }
      console.log(`STAGING_ACCEPTANCE_COMPONENT=PASS component=${name}${maxCycles > 1 ? ` cycle=${cycle}` : ''}`);
      return 0;
    }

    if (lastExitCode !== EX_TEMPFAIL) {
      console.error(`STAGING_ACCEPTANCE_COMPONENT=FAIL component=${name} exit=${lastExitCode}`);
      return lastExitCode;
    }

    sawTransient = true;
    if (cycle < maxCycles) {
      await writeRollbackState('1', name, 'outer-transient-retry');
      const delayMs = backoffMs * cycle;
      console.warn(`STAGING_ACCEPTANCE_COMPONENT=RETRY component=${name} cycle=${cycle} exit=${lastExitCode} delay_ms=${delayMs}`);
      await delay(delayMs);
    }
  }

  await disarmRollbackAfterTransientExhaustion(name);
  console.error(`STAGING_ACCEPTANCE_COMPONENT=FAIL component=${name} cycles=${maxCycles} exit=${lastExitCode}`);
  return lastExitCode || 1;
}

const VALORACION_PLACEMENT_CYCLES = Number.parseInt(process.env.VALORACION_PLACEMENT_CYCLES || '3', 10) || 3;
const HUBSPOT_A11Y_CYCLES = Number.parseInt(process.env.HUBSPOT_A11Y_CYCLES || '3', 10) || 3;

const stages = [
  { name: 'siteground-transient-classifier', url: new URL('./test-siteground-transient-classifier.mjs', import.meta.url), maxCycles: 1 },
  { name: 'hubspot-submission-classifier', url: new URL('./test-hubspot-submission-classifier.mjs', import.meta.url), maxCycles: 1 },
  { name: 'hubspot-a11y-safe-unit', url: new URL('./test-hubspot-a11y-safe.mjs', import.meta.url), maxCycles: 1 },
  { name: 'governed-blog-head-contract', url: new URL('./governed-blog-head-resilient.mjs', import.meta.url), maxCycles: 1 },
  { name: 'governed-blog-runtime-identity', url: new URL('./governed-blog-runtime-contract.mjs', import.meta.url), maxCycles: 3 },
  { name: 'meta-no-consent', url: new URL('./meta-no-consent-contract.mjs', import.meta.url), maxCycles: 3, backoffMs: 5000 },
  { name: 'valoracion-placement', url: new URL('./valoracion-placement-resilient.mjs', import.meta.url), maxCycles: VALORACION_PLACEMENT_CYCLES },
  { name: 'hubspot-a11y', url: new URL('./h1-hubspot-a11y-safe.mjs', import.meta.url), maxCycles: HUBSPOT_A11Y_CYCLES, backoffMs: 7000 },
];

// P0 staging acceptance proves public delivery, render integrity, form placement
// and accessibility. Attribution lineage is a separate integration contract and
// must not roll back a render-valid release; run attribution-lineage-e2e.mjs in
// its dedicated attribution phase instead.
console.log('STAGING_ACCEPTANCE_SCOPE=P0 attribution_lineage=deferred');

// Prove the real first-visit mobile state before the existing a11y gate accepts
// consent. Every route/action uses a fresh browser context with no persisted
// Complianz cookies, so an inaccessible or viewport-blocking banner cannot be
// hidden by test setup.
stages.push({ name: 'complianz-first-visit-mobile', url: new URL('./complianz-first-visit-mobile.mjs', import.meta.url), maxCycles: 1 });
stages.push({ name: 'block-a11y', url: new URL('./block-a11y.mjs', import.meta.url), maxCycles: 1 });

await prepareAllStageEvidence();
for (const stage of stages) {
  const exitCode = await runStage(stage.name, stage.url, stage.maxCycles, stage.backoffMs);
  if (exitCode !== 0) {
    process.exit(exitCode);
  }
}
