#!/usr/bin/env bash
set -Eeuo pipefail

MODE="${1:---check}"
FORM_ID="${HUBSPOT_FORM_ID:-5042522a-0bc5-4381-ac3e-5aee8649b69c}"
PORTAL_ID="${HUBSPOT_PORTAL:-147416356}"
API_BASE="https://api.hubapi.com"
FORM_MAX_FIELDS_PER_GROUP=3

case "$MODE" in
  --check|--apply) ;;
  *) echo "Usage: $0 [--check|--apply]" >&2; exit 2 ;;
esac

: "${HUBSPOT_ACCESS_TOKEN:?Missing HUBSPOT_ACCESS_TOKEN}"
if [[ "$MODE" == '--apply' && "${NUVANX_CONFIRM:-}" != 'yes' ]]; then
  echo 'Refusing HubSpot mutation without NUVANX_CONFIRM=yes' >&2
  exit 2
fi

work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT

request() {
  local method="$1" url="$2" output="$3" body="${4:-}"
  local args=(
    --silent --show-error --proto '=https' --proto-redir '=https' --connect-timeout 10 --max-time 45
    --request "$method"
    --output "$output"
    --write-out '%{http_code}'
    --header "Authorization: Bearer ${HUBSPOT_ACCESS_TOKEN}"
    --header 'Content-Type: application/json'
    "$url"
  )
  if [[ -n "$body" ]]; then
    args+=(--data-binary "@$body")
  fi
  curl "${args[@]}"
}

# Properties already present in the portal before Contract v2. Their absence is drift,
# not permission to recreate or silently redefine them.
required_existing_properties=(
  nvx_utm_source
  nvx_utm_medium
  nvx_utm_campaign
  nvx_utm_content
  nvx_utm_term
  nvx_landing_url
  nvx_attribution_captured_at
  nvx_attribution_expires_at
  nvx_google_click_id
  nvx_google_braid
  nvx_google_wbraid
  nvx_google_gclsrc
)

# Properties owned by this provisioner. Missing properties may be created only in
# --apply mode. All values are operational/attribution metadata; none stores clinical text.
managed_properties=(
  nvx_lead_id
  nvx_is_test_lead
  nvx_test_run_id
  nvx_first_channel
  nvx_first_source
  nvx_first_medium
  nvx_first_campaign_id
  nvx_first_referrer_domain
  nvx_first_landing_url
  nvx_first_timestamp
  nvx_conversion_channel
  nvx_conversion_source
  nvx_conversion_medium
  nvx_conversion_campaign_id
  nvx_conversion_landing_url
  nvx_conversion_timestamp
)

declare -A property_type property_field_type property_label property_description

set_string_spec() {
  local name="$1" label="$2" description="$3"
  property_type["$name"]='string'
  property_field_type["$name"]='text'
  property_label["$name"]="$label"
  property_description["$name"]="$description"
}

set_string_spec nvx_lead_id 'NUVANX Lead ID' 'Identificador first-party de lineage de captación NUVANX. No contiene PII ni información clínica.'
property_type[nvx_is_test_lead]='bool'
property_field_type[nvx_is_test_lead]='booleancheckbox'
property_label[nvx_is_test_lead]='NUVANX Test Lead'
property_description[nvx_is_test_lead]='Marca determinista para excluir leads QA de Deals, SLA, reporting y feedback publicitario.'
set_string_spec nvx_test_run_id 'NUVANX Test Run ID' 'Identificador acotado de la ejecución QA que originó el lead sintético.'
set_string_spec nvx_first_channel 'NUVANX First Channel' 'Canal de adquisición del primer toque conocido: paid_search, organic_search, paid_social, organic_social, referral, direct u other.'
set_string_spec nvx_first_source 'NUVANX First Source' 'Fuente normalizada del primer toque; no implica que existiera una UTM.'
set_string_spec nvx_first_medium 'NUVANX First Medium' 'Medio normalizado del primer toque; no implica que existiera una UTM.'
set_string_spec nvx_first_campaign_id 'NUVANX First Campaign ID' 'Identificador de campaña del primer toque cuando está disponible.'
set_string_spec nvx_first_referrer_domain 'NUVANX First Referrer Domain' 'Dominio del referrer del primer toque, sin path, query ni datos personales.'
set_string_spec nvx_first_landing_url 'NUVANX First Landing URL' 'URL canónica de landing del primer toque, sin query string.'
set_string_spec nvx_first_timestamp 'NUVANX First Timestamp' 'Timestamp ISO 8601 del primer toque capturado por NUVANX.'
set_string_spec nvx_conversion_channel 'NUVANX Conversion Channel' 'Canal normalizado del touch activo en el momento de la conversión.'
set_string_spec nvx_conversion_source 'NUVANX Conversion Source' 'Fuente normalizada del touch activo en el momento de la conversión.'
set_string_spec nvx_conversion_medium 'NUVANX Conversion Medium' 'Medio normalizado del touch activo en el momento de la conversión.'
set_string_spec nvx_conversion_campaign_id 'NUVANX Conversion Campaign ID' 'Identificador de campaña del touch activo en el momento de la conversión cuando está disponible.'
set_string_spec nvx_conversion_landing_url 'NUVANX Conversion Landing URL' 'URL canónica donde se produjo la conversión, sin query string.'
set_string_spec nvx_conversion_timestamp 'NUVANX Conversion Timestamp' 'Timestamp ISO 8601 del snapshot de conversión.'

check_property() {
  local name="$1" expected_type="$2" expected_field_type="$3"
  local out="$work/property-${name}.json"
  local status
  status="$(request GET "$API_BASE/crm/v3/properties/contacts/$name" "$out")"
  if [[ "$status" == '200' ]]; then
    local name_ok type field_type hidden form_field options_ok
    name_ok="$(jq -r --arg name "$name" 'if .name == $name then "1" else "0" end' "$out" 2>/dev/null || echo 0)"
    type="$(jq -r '.type // empty' "$out" 2>/dev/null || true)"
    field_type="$(jq -r '.fieldType // empty' "$out" 2>/dev/null || true)"
    hidden="$(jq -r '(.hidden // false) | tostring' "$out" 2>/dev/null || echo unknown)"
    form_field="$(jq -r '(.formField // false) | tostring' "$out" 2>/dev/null || echo unknown)"
    options_ok='1'
    if [[ "$expected_type" == 'bool' ]]; then
      options_ok="$(jq -r 'if ([.options[]?.value] | sort) == ["false","true"] then "1" else "0" end' "$out" 2>/dev/null || echo 0)"
    fi
    if [[ "$name_ok" == '1' && "$type" == "$expected_type" && "$field_type" == "$expected_field_type" && "$hidden" == 'false' && "$form_field" == 'true' && "$options_ok" == '1' ]]; then
      return 0
    fi
    echo "HUBSPOT_PROPERTY_CONTRACT=FAIL property=\"$name\" name_match=\"$name_ok\" type=\"${type:-missing}\" field_type=\"${field_type:-missing}\" hidden=\"$hidden\" form_field=\"$form_field\" options_ok=\"$options_ok\" expected_type=\"$expected_type\" expected_field_type=\"$expected_field_type\"" >&2
    exit 1
  fi
  if [[ "$status" == '404' ]]; then
    return 1
  fi
  echo "HUBSPOT_PROPERTY_CHECK=ERROR property=\"$name\" status=\"$status\"" >&2
  jq '{status,category,message,correlationId}' "$out" 2>/dev/null || true
  exit 1
}

check_existing_string_property() {
  local name="$1"
  local out="$work/property-${name}.json"
  local status
  status="$(request GET "$API_BASE/crm/v3/properties/contacts/$name" "$out")"
  if [[ "$status" == '200' ]]; then
    local name_ok type hidden
    name_ok="$(jq -r --arg name "$name" 'if .name == $name then "1" else "0" end' "$out" 2>/dev/null || echo 0)"
    type="$(jq -r '.type // empty' "$out" 2>/dev/null || true)"
    hidden="$(jq -r '(.hidden // false) | tostring' "$out" 2>/dev/null || echo unknown)"
    if [[ "$name_ok" == '1' && "$type" == 'string' && "$hidden" == 'false' ]]; then
      return 0
    fi
    echo "HUBSPOT_PROPERTY_CONTRACT=FAIL property=\"$name\" name_match=\"$name_ok\" type=\"${type:-missing}\" hidden=\"$hidden\"" >&2
    exit 1
  fi
  if [[ "$status" == '404' ]]; then
    echo "HUBSPOT_PROPERTY_CONTRACT=FAIL missing_existing_property=\"$name\"" >&2
    exit 1
  fi
  echo "HUBSPOT_PROPERTY_CHECK=ERROR property=\"$name\" status=\"$status\"" >&2
  jq '{status,category,message,correlationId}' "$out" 2>/dev/null || true
  exit 1
}

create_managed_property() {
  local name="$1"
  local body="$work/create-${name}.json"
  jq -n \
    --arg groupName 'contactinformation' \
    --arg label "${property_label[$name]}" \
    --arg name "$name" \
    --arg description "${property_description[$name]}" \
    --arg type "${property_type[$name]}" \
    --arg fieldType "${property_field_type[$name]}" \
    '{
      groupName: $groupName,
      label: $label,
      name: $name,
      description: $description,
      type: $type,
      fieldType: $fieldType,
      formField: true,
      hasUniqueValue: false,
      hidden: false
    }' > "$body"

  if [[ "${property_type[$name]}" == 'bool' ]]; then
    jq '.options = [
      {label:"Sí",value:"true",displayOrder:0,hidden:false},
      {label:"No",value:"false",displayOrder:1,hidden:false}
    ]' "$body" > "$work/create-${name}-with-options.json"
    mv "$work/create-${name}-with-options.json" "$body"
  fi

  local status
  status="$(request POST "$API_BASE/crm/v3/properties/contacts" "$work/create-${name}-response.json" "$body")"
  [[ "$status" == '201' || "$status" == '200' ]] || {
    echo "HUBSPOT_PROPERTY_CREATE=FAIL property=\"$name\" status=\"$status\"" >&2
    jq '{status,category,message,correlationId}' "$work/create-${name}-response.json" 2>/dev/null || true
    exit 1
  }
  echo "HUBSPOT_PROPERTY_CREATE=PASS property=\"$name\""
}

form_max_group_fields() {
  local file="$1"
  jq -r '[.fieldGroups[]? | ((.fields // []) | length)] | max // 0' "$file"
}

expected_form_field_type() {
  local property="$1"
  if [[ "$property" == 'nvx_is_test_lead' ]]; then
    printf 'single_checkbox\n'
  else
    printf 'single_line_text\n'
  fi
}

# Every attribution field must occur exactly once in the canonical published form.
# This prevents a stale duplicate with divergent semantics from being silently masked
# by the client-side V4 API. Required form fields remain hidden and optional so the
# user-facing baseline layout cannot change during schema reconciliation.
verify_required_hidden_form_fields() {
  local file="$1" property expected_type count hidden_count required_count types
  for property in "${required_form_fields[@]}"; do
    expected_type="$(expected_form_field_type "$property")"
    count="$(jq -r --arg name "$property" '[.fieldGroups[]?.fields[]? | select(.name == $name)] | length' "$file")"
    hidden_count="$(jq -r --arg name "$property" '[.fieldGroups[]?.fields[]? | select(.name == $name and (.hidden // false) == true)] | length' "$file")"
    required_count="$(jq -r --arg name "$property" '[.fieldGroups[]?.fields[]? | select(.name == $name and (.required // false) == true)] | length' "$file")"
    types="$(jq -r --arg name "$property" '[.fieldGroups[]?.fields[]? | select(.name == $name) | (.fieldType // "missing")] | unique | join(",")' "$file")"
    if [[ "$count" != '1' || "$hidden_count" != '1' || "$required_count" != '0' || "$types" != "$expected_type" ]]; then
      echo "HUBSPOT_FORM_HIDDEN_FIELD_CONTRACT=FAIL property=$property count=$count hidden_count=$hidden_count required_count=$required_count field_types=${types:-missing} expected_field_type=$expected_type" >&2
      exit 1
    fi
  done
  echo "HUBSPOT_FORM_HIDDEN_FIELD_CONTRACT=PASS fields=${#required_form_fields[@]} unique=1 hidden=1 optional=1"
}

# HubSpot can still return legacy forms whose historical default_group contains
# more fields than the current Forms v3 write contract accepts. Preserve every
# field object verbatim, but split only oversized legacy groups into one-field
# groups. This is deliberately conservative for visible fields: it avoids turning
# a previously stacked form into a new multi-column layout during schema work.
normalize_form_groups_for_write() {
  local input="$1" output="$2"
  jq --argjson max "$FORM_MAX_FIELDS_PER_GROUP" '
    .fieldGroups = [
      .fieldGroups[]? as $group |
      (($group.fields // []) | length) as $count |
      if $count <= $max then
        $group
      else
        $group.fields[]? as $field |
        ($group + {fields: [$field]})
      end
    ]
  ' "$input" > "$output"
}

verify_visible_form_baseline() {
  local file="$1"
  jq -e '
    def matches($name): [.fieldGroups[]?.fields[]? | select(.name == $name)];
    (matches("firstname") | length == 1 and .[0].fieldType == "single_line_text" and (.[0].hidden // false) == false and (.[0].required // false) == true) and
    (matches("lastname")  | length == 1 and .[0].fieldType == "single_line_text" and (.[0].hidden // false) == false and (.[0].required // false) == true) and
    (matches("email")     | length == 1 and .[0].fieldType == "email"            and (.[0].hidden // false) == false and (.[0].required // false) == true) and
    (matches("phone")     | length == 1 and .[0].fieldType == "phone"            and (.[0].hidden // false) == false and (.[0].required // false) == true)
  ' "$file" >/dev/null || {
    echo 'HUBSPOT_FORM_VISIBLE_BASELINE=FAIL expected=firstname,lastname,email,phone visible_required_once' >&2
    exit 1
  }
}

for property in "${required_existing_properties[@]}"; do
  check_existing_string_property "$property"
done

missing_managed=()
for property in "${managed_properties[@]}"; do
  if ! check_property "$property" "${property_type[$property]}" "${property_field_type[$property]}"; then
    missing_managed+=("$property")
  fi
done

if (( ${#missing_managed[@]} > 0 )); then
  if [[ "$MODE" == '--check' ]]; then
    printf 'HUBSPOT_MANAGED_PROPERTY_CONTRACT=FAIL missing=%s\n' "${missing_managed[*]}" >&2
  else
    for property in "${missing_managed[@]}"; do
      create_managed_property "$property"
      check_property "$property" "${property_type[$property]}" "${property_field_type[$property]}" || {
        echo "HUBSPOT_PROPERTY_VERIFY=FAIL property=$property" >&2
        exit 1
      }
    done
  fi
fi

required_form_fields=("${required_existing_properties[@]}" "${managed_properties[@]}")

form="$work/form.json"
status="$(request GET "$API_BASE/marketing/v3/forms/$FORM_ID" "$form")"
[[ "$status" == '200' ]] || {
  echo "HUBSPOT_FORM_CONTRACT=FAIL status=\"$status\" form_id=\"$FORM_ID\"" >&2
  jq '{status,category,message,correlationId}' "$form" 2>/dev/null || true
  exit 1
}

if ! jq -e --arg portal "$PORTAL_ID" '((.portalId // "") == "" or (.portalId|tostring) == $portal) and (.archived // false) == false' "$form" >/dev/null; then
  echo "HUBSPOT_FORM_IDENTITY=FAIL form_id=\"$FORM_ID\" portal=\"$PORTAL_ID\"" >&2
  exit 1
fi

verify_visible_form_baseline "$form"
mapfile -t existing_form_fields < <(jq -r '.fieldGroups[]?.fields[]?.name // empty' "$form" | sort -u)
missing_form_fields=()
for property in "${required_form_fields[@]}"; do
  if ! printf '%s\n' "${existing_form_fields[@]}" | grep -Fxq "$property"; then
    missing_form_fields+=("$property")
  fi
done

current_max_group_fields="$(form_max_group_fields "$form")"
[[ "$current_max_group_fields" =~ ^[0-9]+$ ]] || { echo "HUBSPOT_FORM_GROUP_CONTRACT=FAIL invalid_max=$current_max_group_fields" >&2; exit 1; }
needs_group_normalization=0
if (( current_max_group_fields > FORM_MAX_FIELDS_PER_GROUP )); then
  needs_group_normalization=1
fi

if [[ "$MODE" == '--check' && "$needs_group_normalization" == '1' ]]; then
  echo "HUBSPOT_FORM_GROUP_CONTRACT=FAIL max_fields=$current_max_group_fields allowed=\"$FORM_MAX_FIELDS_PER_GROUP\"" >&2
fi
if [[ "$MODE" == '--check' && ${#missing_form_fields[@]} -gt 0 ]]; then
  printf 'HUBSPOT_FORM_FIELD_CONTRACT=FAIL missing=%s\n' "${missing_form_fields[*]}" >&2
fi

if (( ${#missing_form_fields[@]} > 0 || needs_group_normalization == 1 )); then
  if [[ "$MODE" == '--apply' ]]; then
    normalize_form_groups_for_write "$form" "$work/form-working.json"

    normalized_max="$(form_max_group_fields "$work/form-working.json")"
    [[ "$normalized_max" =~ ^[0-9]+$ && "$normalized_max" -le "$FORM_MAX_FIELDS_PER_GROUP" ]] || {
      echo "HUBSPOT_FORM_NORMALIZE=FAIL max_fields=\"${normalized_max:-invalid}\"" >&2
      exit 1
    }
    verify_visible_form_baseline "$work/form-working.json"

    printf '[]\n' > "$work/new-fields.json"
    for property in "${missing_form_fields[@]}"; do
      form_field_type='single_line_text'
      if [[ "$property" == 'nvx_is_test_lead' ]]; then
        form_field_type='single_checkbox'
      fi
      jq --arg name "$property" --arg fieldType "$form_field_type" '. += [{
        objectTypeId: "0-1",
        name: $name,
        label: $name,
        fieldType: $fieldType,
        hidden: true,
        required: false
      }]' "$work/new-fields.json" > "$work/new-fields-next.json"
      mv "$work/new-fields-next.json" "$work/new-fields.json"
    done

    jq --slurpfile new "$work/new-fields.json" --argjson max "$FORM_MAX_FIELDS_PER_GROUP" '
      .fieldGroups += [
        ($new[0]) as $fields |
        range(0; ($fields | length); $max) as $i |
        {
          groupType: "default_group",
          richTextType: "text",
          fields: $fields[$i:($i + $max)]
        }
      ]
    ' "$work/form-working.json" > "$work/form-next.json"
    mv "$work/form-next.json" "$work/form-working.json"

    patch_max="$(form_max_group_fields "$work/form-working.json")"
    [[ "$patch_max" =~ ^[0-9]+$ && "$patch_max" -le "$FORM_MAX_FIELDS_PER_GROUP" ]] || {
      echo "HUBSPOT_FORM_PATCH_CONTRACT=FAIL max_fields=\"${patch_max:-invalid}\"" >&2
      exit 1
    }
    verify_visible_form_baseline "$work/form-working.json"

    jq '{fieldGroups}' "$work/form-working.json" > "$work/form-patch.json"
    patch_status="$(request PATCH "$API_BASE/marketing/v3/forms/$FORM_ID" "$work/form-patch-response.json" "$work/form-patch.json")"
    [[ "$patch_status" == '200' ]] || {
      echo "HUBSPOT_FORM_PATCH=FAIL status=\"$patch_status\" form_id=\"$FORM_ID\"" >&2
      jq '{status,category,message,correlationId}' "$work/form-patch-response.json" 2>/dev/null || true
      exit 1
    }
    echo "HUBSPOT_FORM_PATCH=PASS added=\"${#missing_form_fields[@]}\" normalized_legacy_groups=\"$needs_group_normalization\" max_fields_per_group=\"$FORM_MAX_FIELDS_PER_GROUP\""
  fi
fi

if [[ "$MODE" == '--check' && ( ${#missing_managed[@]} -gt 0 || ${#missing_form_fields[@]} -gt 0 || "$needs_group_normalization" == '1' ) ]]; then
  exit 1
fi

verify="$work/form-verify.json"
verify_status="$(request GET "$API_BASE/marketing/v3/forms/$FORM_ID" "$verify")"
[[ "$verify_status" == '200' ]] || { echo "HUBSPOT_FORM_VERIFY=FAIL status=$verify_status" >&2; exit 1; }

verify_max="$(form_max_group_fields "$verify")"
[[ "$verify_max" =~ ^[0-9]+$ && "$verify_max" -le "$FORM_MAX_FIELDS_PER_GROUP" ]] || {
  echo "HUBSPOT_FORM_VERIFY=FAIL max_fields=\"${verify_max:-invalid}\" allowed=\"$FORM_MAX_FIELDS_PER_GROUP\"" >&2
  exit 1
}
verify_visible_form_baseline "$verify"

for original_field in "${existing_form_fields[@]}"; do
  jq -e --arg name "$original_field" '[.fieldGroups[]?.fields[]? | select(.name == $name)] | length >= 1' "$verify" >/dev/null || {
    echo "HUBSPOT_FORM_VERIFY=FAIL lost_existing_field=\"$original_field\"" >&2
    exit 1
  }
done

verify_required_hidden_form_fields "$verify"

for property in "${managed_properties[@]}"; do
  check_property "$property" "${property_type[$property]}" "${property_field_type[$property]}" || {
    echo "HUBSPOT_PROPERTY_VERIFY=FAIL property=$property" >&2
    exit 1
  }
done

echo "HUBSPOT_FORM_GROUP_CONTRACT=PASS max_fields=$verify_max allowed=\"$FORM_MAX_FIELDS_PER_GROUP\""
echo "HUBSPOT_ATTRIBUTION_CONTRACT=PASS mode=${MODE#--} form_id=\"$FORM_ID\" managed=${#managed_properties[@]} existing=${#required_existing_properties[@]} fields=${#required_form_fields[@]} schema=v2"
