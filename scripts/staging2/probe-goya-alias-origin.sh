#!/usr/bin/env bash
#
# Probe Goya alias query preservation contract from origin with loopback/host IP fallback.
#
set -Eeuo pipefail

is_valid_ipv4() {
  local ip="$1" a b c d extra octet
  IFS=. read -r a b c d extra <<< "$ip"
  [[ -z "${extra:-}" && -n "$a" && -n "$b" && -n "$c" && -n "$d" ]] || return 1
  for octet in "$a" "$b" "$c" "$d"; do
    [[ "$octet" =~ ^[0-9]{1,3}$ ]] || return 1
    (( 10#$octet <= 255 )) || return 1
  done
}

fallback_ip=""
target_url="https://staging2.nuvanx.com/medicina-estetica-goya/?gclid=QA_REDIRECT_CI_GCLID_001&utm_source=google&utm_medium=cpc&utm_campaign=qa_redirect_contract"
curl_cmd=(curl -4 -kS -D - -o /dev/null --max-redirs 0 --connect-timeout 10 --max-time 30 --proto "=https" -H "Cache-Control: no-cache" -H "Pragma: no-cache" -b "wpSGCacheBypass=1" -A "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/151 Safari/537.36 NUVANX-Staging-QA" -H "Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8")

set +e
"${curl_cmd[@]}" --resolve "staging2.nuvanx.com:443:127.0.0.1" "$target_url"
rc=$?
if [[ "$rc" -eq 7 ]]; then
  for candidate in $(hostname -I 2>/dev/null || true); do
    if is_valid_ipv4 "$candidate" && [[ "$candidate" != 127.* ]]; then
      fallback_ip="$candidate"
      break
    fi
  done
  if [[ -n "$fallback_ip" ]]; then
    "${curl_cmd[@]}" --resolve "staging2.nuvanx.com:443:$fallback_ip" "$target_url"
    rc=$?
  fi
fi
exit "$rc"
