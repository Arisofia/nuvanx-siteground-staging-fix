<?php
/** Cross-check the PHP deterministic google-click submission_id. */

declare(strict_types=1);

define( 'ABSPATH', __DIR__ );

function add_action( ...$args ): void {
	unset( $args );
}

function add_filter( ...$args ): void {
	unset( $args );
}

function wp_enqueue_script( ...$args ): void {
	unset( $args );
}

function wp_add_inline_script( ...$args ): void {
	unset( $args );
}

function get_template_directory_uri(): string {
	return 'https://nuvanx.com/wp-content/themes/nuvanx-medical';
}

function nvx_asset_version( string $path ): string {
	return $path;
}

function wp_json_encode( $value ) {
	return json_encode( $value );
}

function is_admin(): bool {
	return false;
}

require dirname( __DIR__, 2 ) . '/wp-content/themes/nuvanx-medical/inc/nvx-attribution-integration.php';

$lead = '11111111-1111-4111-8111-111111111111';
$id   = nvx_attribution_submission_id_from_lead( $lead );
$ok   = 1 === preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $id );
if ( ! $ok ) {
	fwrite( STDERR, "ASSERTION FAILED: submission_id is not UUID v4 shaped: {$id}\n" );
	exit( 1 );
}

$again = nvx_attribution_submission_id_from_lead( $lead );
if ( $again !== $id ) {
	fwrite( STDERR, "ASSERTION FAILED: submission_id must be deterministic\n" );
	exit( 1 );
}

$other = nvx_attribution_submission_id_from_lead( '22222222-2222-4222-8222-222222222222' );
if ( $other === $id ) {
	fwrite( STDERR, "ASSERTION FAILED: distinct leads must not share submission_id\n" );
	exit( 1 );
}

echo 'ATTRIBUTION_SUBMISSION_ID=PASS value=' . $id . PHP_EOL;
