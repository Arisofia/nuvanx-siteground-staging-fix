<?php
/**
 * Canonical helpers for clinic contact and medical-staff identity data.
 *
 * @package nuvanx-medical
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Normalize a telephone value to international digits only. */
function nvx_phone_digits( string $phone ): string {
	return preg_replace( '/\D+/', '', $phone ) ?? '';
}

/** Build one canonical wa.me URL from any supported phone representation. */
function nvx_whatsapp_url_from_phone( string $phone ): string {
	$number = nvx_phone_digits( $phone );
	return '' !== $number ? 'https://wa.me/' . $number : '';
}

/**
 * Get WhatsApp number for a specific clinic or the primary clinic.
 *
 * Clinic phone data is owned by nvx_get_clinics_config(); this helper only
 * resolves the requested clinic. Unknown clinic keys resolve to the primary
 * Chamberí contact.
 *
 * @param string $clinic Clinic identifier ('primary', 'chamberi', 'goya').
 */
function nvx_whatsapp_number( string $clinic = 'primary' ): string {
	if ( ! function_exists( 'nvx_get_clinics_config' ) ) {
		return '';
	}

	$primary_key = 'chamberi';
	$key         = 'primary' === $clinic ? $primary_key : $clinic;
	$clinics     = nvx_get_clinics_config();

	if ( ! isset( $clinics[ $key ]['phone_href'] ) ) {
		$key = $primary_key;
	}

	$phone = isset( $clinics[ $key ]['phone_href'] ) ? (string) $clinics[ $key ]['phone_href'] : '';
	return nvx_phone_digits( $phone );
}

/** Get full WhatsApp URL for a specific clinic. */
function nvx_whatsapp_url( string $clinic = 'primary' ): string {
	return nvx_whatsapp_url_from_phone( nvx_whatsapp_number( $clinic ) );
}

/**
 * Load the canonical medical-staff identity registry.
 *
 * The registry is deliberately narrow: it owns public clinician identity and
 * colegiado values only. Page copy remains in the page-specific catalogs.
 *
 * @return array<string,array{name:string,colegiado:string}>
 */
function nvx_medical_staff_registry(): array {
	static $staff = null;
	if ( is_array( $staff ) ) {
		return $staff;
	}

	$file = __DIR__ . '/data/medical-staff.json';
	if ( ! is_readable( $file ) ) {
		$staff = array();
		return $staff;
	}

	$json = file_get_contents( $file );
	$data = false !== $json ? json_decode( $json, true ) : null;
	if ( ! is_array( $data ) || 1 !== (int) ( $data['schema'] ?? 0 ) || ! is_array( $data['staff'] ?? null ) ) {
		$staff = array();
		return $staff;
	}

	$staff = array();
	foreach ( $data['staff'] as $id => $doctor ) {
		if ( ! is_string( $id ) || ! is_array( $doctor ) ) {
			continue;
		}
		$name      = trim( (string) ( $doctor['name'] ?? '' ) );
		$colegiado = preg_replace( '/\D+/', '', (string) ( $doctor['colegiado'] ?? '' ) ) ?? '';
		if ( '' === $name || '' === $colegiado ) {
			continue;
		}
		$staff[ $id ] = array(
			'name'      => $name,
			'colegiado' => $colegiado,
		);
	}

	return $staff;
}

/** Get a clinician's colegiado number from the canonical registry. */
function nvx_medical_colegiado( string $doctor_id ): string {
	$staff = nvx_medical_staff_registry();
	return isset( $staff[ $doctor_id ]['colegiado'] ) ? (string) $staff[ $doctor_id ]['colegiado'] : '';
}

/** Get a clinician's public name from the canonical registry. */
function nvx_medical_staff_name( string $doctor_id ): string {
	$staff = nvx_medical_staff_registry();
	return isset( $staff[ $doctor_id ]['name'] ) ? (string) $staff[ $doctor_id ]['name'] : '';
}

// Public medical identity constants are defined once from the canonical registry.
if ( ! defined( 'NVX_DIRECTOR_COLEGIADO' ) ) {
	define( 'NVX_DIRECTOR_COLEGIADO', nvx_medical_colegiado( 'director' ) );
}
if ( ! defined( 'NVX_IVON_COLEGIADO' ) ) {
	define( 'NVX_IVON_COLEGIADO', nvx_medical_colegiado( 'ivon' ) );
}
if ( ! defined( 'NVX_FABIO_COLEGIADO' ) ) {
	define( 'NVX_FABIO_COLEGIADO', nvx_medical_colegiado( 'fabio' ) );
}
if ( ! defined( 'NVX_CRISTINA_COLEGIADO' ) ) {
	define( 'NVX_CRISTINA_COLEGIADO', nvx_medical_colegiado( 'cristina' ) );
}
