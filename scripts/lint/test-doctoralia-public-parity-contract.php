<?php
/**
 * Blocking contract for Doctoralia external-profile reconciliation.
 *
 * Safe, non-destructive Goya profile/service-visibility writes are permitted.
 * Direction deletion/merge, agenda destruction, global Clinic Cloud service
 * deactivation and legal-responsible mutations remain fail-closed until their
 * dedicated ownership/evidence preconditions are satisfied.
 */

declare(strict_types=1);

$root = dirname( __DIR__, 2 );
$fail = static function ( string $message ): void {
	fwrite( STDERR, 'DOCTORALIA_PUBLIC_PARITY_TEST=FAIL ' . $message . PHP_EOL );
	exit( 1 );
};

$data_path     = $root . '/wp-content/themes/nuvanx-medical/inc/data/doctoralia-profiles.json';
$services_path = $root . '/wp-content/themes/nuvanx-medical/inc/data/treatment-hub-schema.json';
$schema_path   = $root . '/wp-content/themes/nuvanx-medical/inc/nvx-schema-foundation.php';

$data_raw     = file_get_contents( $data_path );
$services_raw = file_get_contents( $services_path );
$schema_raw   = file_get_contents( $schema_path );

if ( false === $data_raw || false === $services_raw || false === $schema_raw ) {
	$fail( 'unreadable Doctoralia parity contract source' );
}

$data     = json_decode( $data_raw, true );
$services = json_decode( $services_raw, true );
if ( ! is_array( $data ) || ! is_array( $services ) ) {
	$fail( 'invalid governed Doctoralia/service JSON' );
}

if ( 'external_public_parity_open' !== ( $data['status'] ?? null ) ) {
	$fail( 'external Doctoralia parity must remain open until public services reconcile' );
}

$canonical_keys = array();
foreach ( $services as $service ) {
	if ( ! is_array( $service ) || empty( $service['key'] ) ) {
		$fail( 'treatment-hub-schema contains an invalid service row' );
	}
	$canonical_keys[] = (string) $service['key'];
}
$projection_keys = $data['target_projection']['service_keys'] ?? null;
if ( ! is_array( $projection_keys ) || $canonical_keys !== array_values( $projection_keys ) ) {
	$fail( 'Doctoralia target projection drifted from treatment-hub-schema SSOT' );
}

$policy = $data['mutation_policy'] ?? null;
if ( ! is_array( $policy )
	|| true !== ( $policy['doctoralia_write_allowed'] ?? null )
	|| 'non_destructive_profile_and_service_mapping_only' !== ( $policy['doctoralia_write_mode'] ?? null ) ) {
	$fail( 'Doctoralia write policy must allow only bounded non-destructive Goya corrections' );
}

$required_allowed = array(
	'edit_goya_clinic_profile_fields',
	'edit_goya_public_service_visibility',
	'edit_goya_specialist_service_mapping_when_access_authorized',
	'add_missing_canonical_goya_services_when_doctoralia_type_verified',
);
$allowed = $policy['allowed_operations'] ?? array();
foreach ( $required_allowed as $operation ) {
	if ( ! in_array( $operation, $allowed, true ) ) {
		$fail( 'required safe Doctoralia operation is not authorized: ' . $operation );
	}
}

$required_blocked = array(
	'delete_or_merge_direction_53333_49168',
	'deactivate_or_delete_clinic_cloud_service_globally',
	'change_legal_healthcare_responsible',
	'change_agenda_hours_or_delete_agenda',
	'remove_professional',
	'change_chamberi_until_admin_export_complete',
);
$blocked = $policy['blocked_operations'] ?? array();
foreach ( $required_blocked as $operation ) {
	if ( ! in_array( $operation, $blocked, true ) ) {
		$fail( 'destructive Doctoralia operation lost its fail-closed guard: ' . $operation );
	}
}

$required_before_direction_write = array(
	'goya_synchronization_owner_confirmed',
	'goya_canonical_direction_confirmed',
	'future_appointments_and_unique_mappings_checked',
	'doctoralia_merge_delete_impact_confirmed',
);
if ( $required_before_direction_write !== ( $policy['required_before_direction_write'] ?? null ) ) {
	$fail( 'destructive direction-write preconditions changed without governance update' );
}

$required_before_non_destructive_write = array(
	'goya_synchronization_owner_confirmed',
	'goya_canonical_direction_confirmed',
	'chamberi_admin_export_complete',
	'website_chamberi_goya_exact_parity_diff_complete',
	'integrated_admin_access_verified',
	'profile_identity_verified',
	'before_snapshot_complete',
	'canonical_target_confirmed',
);
if ( $required_before_non_destructive_write !== ( $policy['required_before_non_destructive_write'] ?? null ) ) {
	$fail( 'Doctoralia non-destructive write preconditions changed without governance update' );
}

$chamberi = $data['clinics']['chamberi'] ?? null;
$goya     = $data['clinics']['goya'] ?? null;
if ( ! is_array( $chamberi ) || ! is_array( $goya ) ) {
	$fail( 'both Doctoralia clinic records are required' );
}
if ( '47595' !== ( $chamberi['facility_id'] ?? null ) || 'pending' !== ( $chamberi['admin_export_status'] ?? null ) ) {
	$fail( 'Chamberí admin export/facility state is not the observed checkpoint' );
}
if ( '54924' !== ( $goya['facility_id'] ?? null ) ) {
	$fail( 'Goya facility identity drift' );
}
if ( 'unverified' !== ( $goya['synchronization_owner_status'] ?? null )
	|| 'unverified' !== ( $goya['canonical_direction_status'] ?? null ) ) {
	$fail( 'Goya direction ownership cannot be promoted before authenticated sync evidence' );
}

$directions = $goya['directions'] ?? null;
if ( ! is_array( $directions )
	|| 16 !== ( $directions['53333']['editable_service_rows'] ?? null )
	|| 7 !== ( $directions['49168']['editable_service_rows'] ?? null )
	|| 'more_complete_candidate' !== ( $directions['53333']['relationship'] ?? null )
	|| 'exact_first_seven_row_subset_of_53333' !== ( $directions['49168']['relationship'] ?? null ) ) {
	$fail( 'Goya direction reconciliation evidence drift' );
}
if ( 'candidate_unconfirmed' !== ( $directions['53333']['canonical_status'] ?? null )
	|| 'unconfirmed_do_not_mutate' !== ( $directions['49168']['canonical_status'] ?? null ) ) {
	$fail( 'a Goya direction was promoted without ownership proof' );
}

$public = $goya['public_primary_profile'] ?? null;
if ( ! is_array( $public ) || 'profile_fields_propagated_service_aggregation_stale' !== ( $public['status'] ?? null ) ) {
	$fail( 'primary Goya public profile state no longer matches the observed checkpoint' );
}
if ( 2 !== ( $public['public_direction_count'] ?? null )
	|| 'same_physical_address_exposed_twice' !== ( $public['public_direction_duplication'] ?? null ) ) {
	$fail( 'public duplicate Goya direction evidence is missing' );
}
if ( 'Javier Rivera Tejeda' !== ( $public['responsable_sanitario'] ?? null ) ) {
	$fail( 'current public Doctoralia responsible-person observation drifted' );
}
$legacy_services = $public['legacy_services_observed'] ?? array();
foreach ( array( 'Coolsculpting', 'Tratamiento con dermapen', 'HIFU (Facial)', 'HIFU (Corporal)' ) as $legacy ) {
	if ( ! in_array( $legacy, $legacy_services, true ) ) {
		$fail( 'observed legacy public service disappeared from governed checkpoint: ' . $legacy );
	}
}
if ( 'service_aggregation_and_professional_mappings_still_stale' !== ( $goya['public_secondary_surfaces']['status'] ?? null ) ) {
	$fail( 'Doctoralia secondary service surfaces must remain marked stale until cleaned' );
}

$agenda = $goya['clinic_cloud_agenda_evidence']['legacy_service_agenda'] ?? null;
if ( ! is_array( $agenda ) || '200346' !== ( $agenda['agenda_id'] ?? null ) || 'GOSIA' !== ( $agenda['user'] ?? null ) ) {
	$fail( 'Clinic Cloud legacy-service agenda evidence is missing' );
}
foreach ( array( 'HIFU CORPORAL', 'HIFU FACIAL', 'LÁSER IPL', 'MADEROTERAPIA', 'MICRO PIGMENTACIÓN CEJAS' ) as $legacy_internal ) {
	if ( ! in_array( $legacy_internal, $agenda['services'] ?? array(), true ) ) {
		$fail( 'Clinic Cloud legacy agenda evidence drift: ' . $legacy_internal );
	}
}

$legal = $goya['legal_healthcare_responsible'] ?? null;
if ( ! is_array( $legal )
	|| 'unverified' !== ( $legal['status'] ?? null )
	|| false !== ( $legal['mutation_allowed'] ?? null ) ) {
	$fail( 'CS20073 healthcare-responsible role must remain fail-closed' );
}
if ( 'observed_admin_not_official_register' !== ( $goya['admin_legal_surface']['classification'] ?? null )
	|| 'observed_public_not_official_register' !== ( $public['responsable_classification'] ?? null ) ) {
	$fail( 'Doctoralia responsible-person observation was promoted beyond evidence' );
}

$goya_url = (string) ( $goya['public_url'] ?? '' );
$canonical_goya_url = 'https://www.doctoralia.es/clinicas/nuvanx-medicina-estetica-laser-sede-goya';
if ( $canonical_goya_url !== $goya_url || ! str_contains( $schema_raw, "'{$canonical_goya_url}'" ) ) {
	$fail( 'Goya MedicalClinic sameAs lost the canonical public Doctoralia profile' );
}

$schema_files = array(
	$root . '/wp-content/themes/nuvanx-medical/inc/nvx-schema-foundation.php',
);
$prohibited_names = array( 'yolanda piñero', 'Javier Rivera Tejeda' );
foreach ( $schema_files as $schema_file ) {
	if ( ! is_file( $schema_file ) ) {
		continue;
	}
	$file_raw = file_get_contents( $schema_file );
	if ( false === $file_raw ) {
		continue;
	}
	$file_lower = strtolower( $file_raw );
	foreach ( $prohibited_names as $name ) {
		if ( str_contains( $file_lower, strtolower( $name ) ) ) {
			$fail( 'unverified Doctoralia responsible-person data leaked into website Schema' );
		}
	}
}

foreach ( $data['target_projection']['legacy_not_canonical'] ?? array() as $legacy ) {
	$legacy_lower = strtolower( (string) $legacy );
	foreach ( $services as $service ) {
		$name_lower = strtolower( (string) ( $service['name'] ?? '' ) );
		if ( '' !== $name_lower && $name_lower === $legacy_lower ) {
			$fail( 'legacy Doctoralia service was promoted into canonical treatment SSOT: ' . $legacy );
		}
	}
}

echo 'DOCTORALIA_PUBLIC_PARITY_TEST=PASS status=external_public_parity_open goya_facility=54924 directions=2 profile_writes=allowed destructive_writes=blocked chamberi_export=pending legal_role=unverified' . PHP_EOL;
