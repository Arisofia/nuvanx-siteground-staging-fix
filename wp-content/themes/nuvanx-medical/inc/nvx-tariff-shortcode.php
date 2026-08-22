<?php
/**
 * Shortcode for rendering prices from the tariff catalog SSOT.
 *
 * Usage: [nvx_tariff key="laser_co2.facial"]
 * Usage: [nvx_tariff key="exion.exion_face_sesion"]
 * Usage: [nvx_tariff key="Endolift®.papada"]
 *
 * @package nuvanx-medical
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register the tariff shortcode.
 */
add_shortcode( 'nvx_tariff', 'nvx_tariff_shortcode_render' );

/**
 * Render a tariff price from the SSOT catalog.
 *
 * @param array<string,string> $atts Shortcode attributes.
 * @return string Rendered price or empty string on error.
 */
function nvx_tariff_shortcode_render( array $atts ): string {
	$atts = shortcode_atts(
		array(
			'key' => '',
		),
		$atts,
		'nvx_tariff'
	);

	$key = trim( $atts['key'] );
	if ( '' === $key ) {
		return '';
	}

	// Parse key format: "group.subkey" (e.g., "laser_co2.facial")
	$parts = explode( '.', $key, 2 );
	if ( 2 !== count( $parts ) ) {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( sprintf( '[nvx_tariff] Invalid key format: %s (expected "group.subkey")', $key ) );
		}
		return '';
	}

	list( $group, $subkey ) = $parts;

	$tariffs = nvx_catalog_json_load( 'tariff-catalog.json' );
	if ( ! empty( $tariffs['_error'] ) || ! isset( $tariffs[ $group ] ) || ! is_array( $tariffs[ $group ] ) ) {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( sprintf( '[nvx_tariff] Unable to load tariff group "%s" from tariff-catalog.json', $group ) );
		}
		return '';
	}

	$price = nvx_catalog_tariff_display_price( $tariffs, $group, $subkey );
	if ( '' === $price ) {
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( sprintf( '[nvx_tariff] Price not found for key: %s', $key ) );
		}
		return '';
	}

	return $price;
}
