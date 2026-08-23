<?php
/**
 * Clinical Governance & Medical Content Contract
 * 
 * Centralizes the retrieval of clinical treatments data (SSOT).
 * Extracted from inc/data/clinical-matrix.json to enforce consistency
 * and valid medical claims across the UI, pricing, and Schema JSON-LD.
 *
 * @package nuvanx-medical
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Load and validate the Clinical Matrix catalog.
 *
 * @return array<string,array>
 * @throws RuntimeException If the JSON cannot be parsed.
 */
function nvx_get_clinical_matrix(): array {
	static $matrix = null;

	if ( null !== $matrix ) {
		return $matrix;
	}

	$file = __DIR__ . '/data/clinical-matrix.json';
	if ( ! is_readable( $file ) ) {
		$matrix = array();
		return $matrix;
	}

	$data = json_decode( file_get_contents( $file ), true );
	if ( ! is_array( $data ) || empty( $data['treatments'] ) ) {
		$matrix = array();
		return $matrix;
	}

	$matrix = $data['treatments'];
	return $matrix;
}

/**
 * Retrieve a specific clinical treatment profile by its matrix ID.
 *
 * @param string $treatment_id The key in the clinical matrix.
 * @return array|null The treatment array, or null if missing.
 */
function nvx_get_clinical_treatment( string $treatment_id ): ?array {
	$matrix = nvx_get_clinical_matrix();
	return $matrix[ $treatment_id ] ?? null;
}

/**
 * Generate E-E-A-T MedicalProcedure schema for a given treatment ID.
 *
 * @param string $treatment_id Matrix identifier.
 * @param string $url Page canonical URL.
 * @return array|null
 */
function nvx_clinical_generate_schema( string $treatment_id, string $url ): ?array {
	$data = nvx_get_clinical_treatment( $treatment_id );
	if ( ! $data ) {
		return null;
	}

	$schema = array(
		'@type'       => array( 'MedicalProcedure', 'MedicalTherapy' ),
		'@id'         => trailingslashit( $url ) . '#medical-procedure',
		'name'        => $data['name'],
		'description' => $data['mechanism'],
		'url'         => $url,
	);

	if ( ! empty( $data['anesthesia'] ) ) {
		$schema['preparation'] = array(
			'@type' => 'MedicalEntity',
			'name'  => $data['anesthesia'],
		);
	}

	if ( ! empty( $data['risks'] ) ) {
		$schema['complication'] = array();
		foreach ( $data['risks'] as $risk ) {
			$schema['complication'][] = array(
				'@type' => 'MedicalEntity',
				'name'  => $risk,
			);
		}
	}
    
    if ( ! empty( $data['follow_up'] ) ) {
		$schema['followup'] = $data['follow_up'];
	}

	// Attach medical responsible credentials (E-E-A-T)
	if ( ! empty( $data['medical_responsible'] ) ) {
		$schema['provider'] = array(
			'@type' => 'Physician',
			'name'  => $data['medical_responsible'],
		);
	}

	return $schema;
}
