<?php
/**
 * Theme-owned Google Ads click conversion catalog.
 *
 * Form measurement remains HubSpot -> GA4 generate_lead -> Ads import.
 * This module only exposes the separate phone/WhatsApp click send_to.
 *
 * @package nuvanx-medical
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Canonical send_to format owned by ads-conversion-catalog.json. */
function nvx_ads_is_send_to( string $value ): bool {
	return 1 === preg_match( '/^AW-[0-9]{8,12}\/[A-Za-z0-9_-]+$/', $value );
}

/**
 * Resolve the phone/WhatsApp Google Ads click conversion.
 *
 * @return string Empty when the catalog is missing or invalid.
 */
function nvx_ads_phone_whatsapp_send_to(): string {
	if ( ! function_exists( 'nvx_catalog_json_load' ) ) {
		return '';
	}

	$catalog = nvx_catalog_json_load( 'ads-conversion-catalog.json' );
	$google  = isset( $catalog['google_ads'] ) && is_array( $catalog['google_ads'] )
		? $catalog['google_ads']
		: array();
	$value   = isset( $google['phone_whatsapp_send_to'] )
		? trim( (string) $google['phone_whatsapp_send_to'] )
		: '';

	return nvx_ads_is_send_to( $value ) ? $value : '';
}

/**
 * Browser-facing Ads click conversions.
 *
 * @return array{phone_whatsapp_send_to:string}
 */
function nvx_ads_conversion_client_context(): array {
	return array(
		'phone_whatsapp_send_to' => nvx_ads_phone_whatsapp_send_to(),
	);
}
