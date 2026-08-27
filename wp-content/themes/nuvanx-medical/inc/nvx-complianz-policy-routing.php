<?php
/**
 * Canonical front-end routing for Complianz policy links.
 *
 * Complianz can render already-translated anchors that still carry href="#"
 * plus data-relative_url. Routing must therefore not depend on unreplaced
 * {title}/{url} template tokens being present in the banner HTML.
 *
 * @package nuvanx-medical
 */

defined( 'ABSPATH' ) || exit;

/**
 * Resolve the canonical destination for a Complianz policy label.
 *
 * Consent-management controls intentionally return an empty destination so
 * their JavaScript-managed hash behavior remains untouched.
 */
function nvx_complianz_policy_destination( string $label ): string {
	$label = trim( wp_strip_all_tags( html_entity_decode( $label, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) ) );
	$lower = function_exists( 'mb_strtolower' ) ? mb_strtolower( $label, 'UTF-8' ) : strtolower( $label );

	if ( false !== strpos( $lower, 'privacidad' ) || false !== strpos( $lower, 'privacy' ) ) {
		return home_url( '/politica-privacidad/' );
	}
	if ( false !== strpos( $lower, 'cookie' ) ) {
		return home_url( '/politica-de-cookies-ue/' );
	}
	if ( false !== strpos( $lower, 'aviso legal' ) || false !== strpos( $lower, 'legal notice' ) ) {
		return home_url( '/aviso-legal/' );
	}

	return '';
}

/**
 * Rewrite Complianz policy anchors while preserving consent-dialog controls.
 */
function nvx_rewrite_complianz_policy_links( string $html ): string {
	$has_template_token = false !== strpos( $html, '{title}' ) || false !== strpos( $html, '{url}' );
	$has_relative_link  = false !== strpos( $html, 'data-relative_url' );

	if ( ! $has_template_token && ! $has_relative_link ) {
		return $html;
	}

	$filtered = preg_replace_callback(
		'/<a\s+([^>]*?)href=([\'\"])(.*?)\2([^>]*)>(.*?)<\/a>/is',
		static function ( array $matches ): string {
			$attr_before = $matches[1];
			$quote       = $matches[2];
			$href        = $matches[3];
			$attr_after  = $matches[4];
			$inner_html  = $matches[5];
			$attributes  = $attr_before . ' ' . $attr_after;
			$label       = $inner_html;

			if ( false !== strpos( $label, '{title}' ) ) {
				$fallback = 'Política de cookies';
				if ( false !== strpos( $href, 'privacidad' ) || false !== strpos( $href, 'privacy' ) ) {
					$fallback = 'Política de privacidad';
				} elseif ( false !== strpos( $href, 'aviso-legal' ) || false !== strpos( $href, 'legal' ) ) {
					$fallback = 'Aviso legal';
				}
				$label      = str_replace( '{title}', $fallback, $label );
				$inner_html = $label;
			}

			if ( '#' === $href && false !== strpos( $attributes, 'data-relative_url' ) ) {
				// First, try to resolve from the metadata destination (data-relative_url)
				$relative_url_match = [];
				if ( preg_match( '/data-relative_url=([\'\"])(.*?)\1/', $attributes, $relative_url_match ) ) {
					$relative_url = $relative_url_match[2];
					// Classify the non-hash relative_url first
					if ( '#' !== $relative_url && '' !== $relative_url ) {
						$destination = home_url( $relative_url );
						$href = $destination;
					}
				}
				
				// Only use label-based resolution when metadata does not identify a policy destination
				if ( '#' === $href ) {
					$destination = nvx_complianz_policy_destination( $label );
					if ( '' !== $destination ) {
						$href = $destination;
					}
				}
			}

			return '<a ' . $attr_before . 'href=' . $quote . esc_url( $href ) . $quote . $attr_after . '>' . $inner_html . '</a>';
		},
		$html
	);

	if ( ! is_string( $filtered ) ) {
		return $html;
	}

	return str_replace( '{title}', 'Política de cookies', $filtered );
}

// Retire the earlier token-dependent sanitizer as the effective Complianz
// routing owner, then register one canonical finalizer for both plugin hooks.
remove_filter( 'cmplz_banner_html', 'nvx_sanitize_complianz_banner_html', 20 );
remove_filter( 'cmplz_template', 'nvx_sanitize_complianz_banner_html', 20 );
add_filter( 'cmplz_banner_html', 'nvx_rewrite_complianz_policy_links', 20 );
add_filter( 'cmplz_template', 'nvx_rewrite_complianz_policy_links', 20 );
