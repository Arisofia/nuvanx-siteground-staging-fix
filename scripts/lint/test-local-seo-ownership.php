<?php
/**
 * Blocking contract for local-intent landing ownership and governed clinic hours.
 *
 * clinics.json is the public clinic SSOT. GBP configuration provides the
 * external operational profile contract; both must remain aligned with SEO and
 * Schema without relying on dated audit-status fields.
 */

declare(strict_types=1);

$root = dirname( __DIR__, 2 );
$fail = static function ( string $message ): void {
	fwrite( STDERR, 'LOCAL_SEO_OWNERSHIP_TEST=FAIL ' . $message . PHP_EOL );
	exit( 1 );
};

$theme_root    = $root . '/wp-content/themes/nuvanx-medical';
$profiles_raw  = file_get_contents( $theme_root . '/inc/data/gbp-profiles.json' );
$clinics_raw   = file_get_contents( $theme_root . '/inc/data/clinics.json' );
$seo_raw       = file_get_contents( $theme_root . '/inc/data/seo-metadata.json' );
$config_source = file_get_contents( $theme_root . '/inc/nvx-business-config.php' );
$schema_source = file_get_contents( $theme_root . '/inc/nvx-schema-foundation.php' );
$landing       = file_get_contents( $theme_root . '/templates/page-sede.php' );

if ( false === $profiles_raw || false === $clinics_raw || false === $seo_raw || false === $config_source || false === $schema_source || false === $landing ) {
	$fail( 'unreadable local SEO contract source' );
}

$profiles = json_decode( $profiles_raw, true );
$clinics  = json_decode( $clinics_raw, true );
$seo      = json_decode( $seo_raw, true );
if ( ! is_array( $profiles ) || ! is_array( $clinics ) || ! is_array( $seo ) || ! is_array( $profiles['clinics'] ?? null ) || ! is_array( $clinics['clinics'] ?? null ) ) {
	$fail( 'governed local SEO JSON is invalid' );
}
if ( 1 !== (int) ( $profiles['schema'] ?? 0 ) ) {
	$fail( 'GBP productive configuration schema mismatch' );
}
if ( ! str_contains( $config_source, "__DIR__ . '/data/clinics.json'" ) ) {
	$fail( 'business config loader does not consume clinics.json' );
}
if ( ! str_contains( $schema_source, 'nvx_business_contact_email' ) ) {
	$fail( 'Schema does not use canonical business loader for contact email' );
}

$expected = array(
	'chamberi' => array( 'display' => 'lunes a sábado, 10:00–20:00', 'opens' => '10:00', 'closes' => '20:00' ),
	'goya'     => array( 'display' => 'lunes a sábado, 11:00–20:00', 'opens' => '11:00', 'closes' => '20:00' ),
);
$weekdays = array( 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday' );

foreach ( $expected as $clinic_key => $hours ) {
	$profile = $profiles['clinics'][ $clinic_key ] ?? null;
	$clinic  = $clinics['clinics'][ $clinic_key ] ?? null;
	if ( ! is_array( $profile ) || ! is_array( $clinic ) ) {
		$fail( 'missing clinic record: ' . $clinic_key );
	}
	if ( '' === trim( (string) ( $profile['place_id'] ?? '' ) ) || '' === trim( (string) ( $profile['maps_query'] ?? '' ) ) ) {
		$fail( 'GBP operational identifiers missing for ' . $clinic_key );
	}
	if ( '' === trim( (string) ( $clinic['reg'] ?? '' ) ) ) {
		$fail( 'sanitary registration missing for ' . $clinic_key );
	}
	foreach ( $weekdays as $day ) {
		$expected_value = $hours['opens'] . '-' . $hours['closes'];
		if ( $expected_value !== ( $profile['regular_hours'][ $day ] ?? null ) ) {
			$fail( sprintf( 'GBP hours mismatch clinic=%s day=%s', $clinic_key, $day ) );
		}
	}
	if ( 'closed' !== ( $profile['regular_hours']['sunday'] ?? null ) ) {
		$fail( 'Sunday must remain closed for ' . $clinic_key );
	}
	if ( $hours['display'] !== ( $clinic['hours'] ?? null ) ) {
		$fail( 'public display hours drift for ' . $clinic_key );
	}
	$opening = $clinic['opening_hours'][0] ?? null;
	if ( ! is_array( $opening )
		|| array( 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday' ) !== ( $opening['days'] ?? null )
		|| $hours['opens'] !== ( $opening['opens'] ?? null )
		|| $hours['closes'] !== ( $opening['closes'] ?? null ) ) {
		$fail( 'OpeningHoursSpecification registry drift for ' . $clinic_key );
	}
}

$schema_start = strpos( $schema_source, 'function nvx_schema_clinics()' );
$schema_end   = strpos( $schema_source, 'function nvx_schema_organization_id()', false === $schema_start ? 0 : $schema_start );
if ( false === $schema_start || false === $schema_end || $schema_end <= $schema_start ) {
	$fail( 'unable to isolate nvx_schema_clinics fallbacks' );
}
$schema_clinics = substr( $schema_source, $schema_start, $schema_end - $schema_start );
$days_line      = "array( 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday' )";
if ( str_contains( $schema_clinics, "'opens'     => '12:00'" ) || str_contains( $schema_clinics, "'closes'    => '18:00'" ) ) {
	$fail( 'legacy clinic hours remain reachable through Schema fallback' );
}
if ( substr_count( $schema_clinics, $days_line ) < 2
	|| ! str_contains( $schema_clinics, "'opens'     => '10:00'" )
	|| ! str_contains( $schema_clinics, "'opens'     => '11:00'" )
	|| substr_count( $schema_clinics, "'closes'    => '20:00'" ) < 2 ) {
	$fail( 'Schema fallback hours drift from governed clinic truth' );
}

$chamberi_meta = $seo['chamberi']['description'] ?? '';
if ( ! is_string( $chamberi_meta ) || ! str_contains( $chamberi_meta, 'Lunes a sábado 10:00–20:00' ) || str_contains( $chamberi_meta, '12:00–20:00' ) || str_contains( $chamberi_meta, '10:00–18:00' ) ) {
	$fail( 'Chamberí SEO metadata hours drift from governed truth' );
}

$forbidden_runtime_state = array( 'pending_reconciliation', 'unverified', 'goya_discarded', 'missing_photo_capture', 'legacy_default_superseded' );
foreach ( $forbidden_runtime_state as $needle ) {
	if ( str_contains( $profiles_raw, $needle ) ) {
		$fail( 'non-productive GBP state remains: ' . $needle );
	}
}
if ( str_contains( $clinics_raw, 'lunes a viernes, 12:00–20:00; sábados, 10:00–18:00' ) || str_contains( $clinics_raw, 'lunes a viernes, 11:00–20:00' ) ) {
	$fail( 'legacy clinic hours reintroduced' );
}

if ( ! str_contains( $landing, 'Medicina estética en Chamberí, Madrid — clínica NUVANX' ) ) {
	$fail( 'Chamberí landing lost explicit local-intent H1' );
}
if ( ! str_contains( $landing, 'Medicina estética en Goya y Barrio de Salamanca — clínica NUVANX' ) ) {
	$fail( 'Goya landing must explicitly own Goya + Barrio de Salamanca intent' );
}
if ( ! str_contains( $landing, 'Clínica de medicina estética láser en Goya, Barrio de Salamanca:' ) ) {
	$fail( 'Goya hero lead must state local medical-aesthetic intent' );
}
if ( ! str_contains( $landing, 'nvx_get_clinics_config' )
	|| ! str_contains( $landing, '$clinic_config[\'reg\']' )
	|| ! str_contains( $landing, 'Centro sanitario %3$s.' ) ) {
	$fail( 'Goya landing must render sanitary-registration context from clinics registry' );
}

if ( str_contains( $landing, 'Centro sanitario CS20073.' ) || str_contains( $landing, 'Registro sanitario: CS20073' ) ) {
	$fail( 'Goya landing duplicates governed sanitary registration literal' );
}

echo 'LOCAL_SEO_OWNERSHIP_TEST=PASS clinics=2 hours=clinics-json+gbp metadata=aligned schema_fallbacks=aligned goya_intent=explicit sanitary_context=registry productive_state=clean schema_email=canonical_loader' . PHP_EOL;
