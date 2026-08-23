#!/usr/bin/env bash
set -Eeuo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
SONAR="$ROOT/sonar-project.properties"

fail() {
  echo "SONAR_PROJECT_CONTRACT=FAIL reason=$1" >&2
  exit 1
}

[[ -s "$SONAR" ]] || fail 'missing_sonar_project'
grep -Fxq 'sonar.qualitygate.wait=true' "$SONAR" || fail 'qualitygate_wait_missing'
grep -Fxq 'sonar.qualitygate.timeout=300' "$SONAR" || fail 'qualitygate_timeout_missing'
! grep -Eq '^sonar\.qualitygates=' "$SONAR" || fail 'unsupported_qualitygates_property'
! grep -Eq '^sonar\.php\.coverage\.reportPaths=' "$SONAR" || fail 'phantom_php_coverage_report'

echo 'SONAR_PROJECT_CONTRACT=PASS remote_gate_conditions=sonarcloud coverage_report=none'
