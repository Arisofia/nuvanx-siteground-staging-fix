<?php
/**
 * Regression contract for the first-party valoración success-token TTL.
 *
 * Reproduces the PR #880 review scenario: an expired transient may still have
 * database rows until get_transient() lazily evaluates its timeout. The
 * production consumer must read/validate the transient before deleting it and
 * must never arm the conversion signal for an expired token.
 *
 * @package nuvanx-medical
 */

declare(strict_types=1);

$root   = dirname( __DIR__, 2 );
$module = $root . '/wp-content/themes/nuvanx-medical/inc/nvx-valoracion-direct-form.php';
$source = file_get_contents( $module );

if ( ! is_string( $source ) || '' === $source ) {
	fwrite( STDERR, "VALORACION_SUCCESS_TOKEN_EXPIRY=FAIL unreadable_source\n" );
	exit( 1 );
}

$function_start = strpos( $source, 'function nvx_valoracion_prepare_direct_success(): void' );
if ( false === $function_start ) {
	fwrite( STDERR, "VALORACION_SUCCESS_TOKEN_EXPIRY=FAIL consumer_missing\n" );
	exit( 1 );
}

$function_end = strpos( $source, "add_action( 'template_redirect', 'nvx_valoracion_prepare_direct_success', 1 );", $function_start );
if ( false === $function_end ) {
	fwrite( STDERR, "VALORACION_SUCCESS_TOKEN_EXPIRY=FAIL consumer_boundary_missing\n" );
	exit( 1 );
}

$body = substr( $source, $function_start, $function_end - $function_start );
$get  = strpos( $body, 'get_transient( $key )' );
$del  = strpos( $body, 'delete_transient( $key )' );
$arm  = strpos( $body, "$GLOBALS['nvx_valoracion_direct_success_ready'] = true;");

if ( false === $get || false === $del || false === $arm ) {
	fwrite( STDERR, "VALORACION_SUCCESS_TOKEN_EXPIRY=FAIL required_operations_missing\n" );
	exit( 1 );
}

if ( ! ( $get < $del && $del < $arm ) ) {
	fwrite( STDERR, "VALORACION_SUCCESS_TOKEN_EXPIRY=FAIL unsafe_operation_order\n" );
	exit( 1 );
}

if ( 1 !== preg_match( '/false\s*===\s*get_transient\(\s*\$key\s*\)\s*\|\|\s*!\s*delete_transient\(\s*\$key\s*\)/', $body ) ) {
	fwrite( STDERR, "VALORACION_SUCCESS_TOKEN_EXPIRY=FAIL expiry_guard_missing\n" );
	exit( 1 );
}

if ( false === strpos( $source, 'set_transient( $key, 1, 10 * MINUTE_IN_SECONDS )' ) ) {
	fwrite( STDERR, "VALORACION_SUCCESS_TOKEN_EXPIRY=FAIL ttl_contract_missing\n" );
	exit( 1 );
}

echo "VALORACION_SUCCESS_TOKEN_EXPIRY=PASS ttl_seconds=600 get_before_delete=1 expired_token_rejected=1\n";
