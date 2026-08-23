<?php
/**
 * Linter: Verify Clinical Matrix completeness and governance.
 */

$file = __DIR__ . '/../../wp-content/themes/nuvanx-medical/inc/data/clinical-matrix.json';
if ( ! file_exists( $file ) ) {
    echo "FAIL: clinical-matrix.json not found.\n";
    exit( 1 );
}

$data = json_decode( file_get_contents( $file ), true );
if ( JSON_ERROR_NONE !== json_last_error() ) {
    echo "FAIL: clinical-matrix.json is invalid JSON.\n";
    exit( 1 );
}

$treatments = $data['treatments'] ?? array();
if ( empty( $treatments ) ) {
    echo "FAIL: No treatments defined in clinical-matrix.json.\n";
    exit( 1 );
}

$required_fields = array(
    'name', 'indications', 'contraindications', 'mechanism',
    'applicators', 'published_parameters', 'anesthesia',
    'duration', 'recovery', 'sessions',
    'medical_responsible', 'scientific_review_date'
);

$errors = 0;
foreach ( $treatments as $id => $t ) {
    foreach ( $required_fields as $field ) {
        if ( ! array_key_exists( $field, $t ) ) {
            echo "ERROR: Treatment '{$id}' is missing required field '{$field}'.\n";
            $errors++;
        }
    }
}

if ( $errors > 0 ) {
    echo "\nFAIL: $errors E-E-A-T clinical governance violations found.\n";
    exit( 1 );
}

echo "OK: Clinical Matrix validation passed.\n";
exit( 0 );
