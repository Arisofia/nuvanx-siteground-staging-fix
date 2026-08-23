<?php
/**
 * NUVANX Filter Priority Configuration
 *
 * Deterministic filter pipeline with explicit and unique priorities.
 * Eliminates dependencies based on require_once order.
 *
 * @package NUVANX
 */

defined( 'ABSPATH' ) || exit;

/**
 * Get filter priority by name.
 *
 * @param mixed $filter_name Filter callback name or array.
 * @return int Explicit priority or 10 as default.
 */
function nvx_get_filter_priority( $filter_name ): int {
	if ( is_array( $filter_name ) && isset( $filter_name[1] ) ) {
		$filter_name = (string) $filter_name[1];
	} elseif ( ! is_string( $filter_name ) ) {
		return 10; // Fallback for closures or unknown types
	}

		$priorities = array(
		// === 10-19: Managed Page ===
		'nvx_contacto_resolve_legacy_template'         => 10,
		'nvx_contacto_seo_title'                       => 11,
		'nvx_contacto_seo_metadesc'                    => 12,
		'nvx_contacto_migrate_legacy_template_meta'    => 13,
		'nvx_theme_disable_public_facebook_pixel'      => 14,

		// === 20-29: Structural Renderer ===
		'nvx_contact_append_maps'                      => 20,
		'nvx_content_presentation_enhance'             => 21,
		'nvx_content_inject_global_treatment_sections' => 22,
		'nvx_content_ensure_exion_investment'          => 23,
		'nvxSedeStripLayoutInlineStyles'               => 24,
		'nvx_clinics_hub_render_managed'               => 25,
		'nvxClinicsHubEnhance'                         => 26,
		'nvx_contacto_enhance_valoracion_page'         => 27,
		'nvx_bridal_inject_media'                      => 28,

		// === 30-39: Presentation ===
		'nvx_filter_valoracion_document_title'         => 30,
		'nvx_filter_valoracion_metadesc'               => 31,
		'nvx_filter_contacto_document_title'           => 32,
		'nvx_filter_contacto_metadesc'                 => 33,
		'nvx_filter_contacto_social_image'             => 34,
		'nvx_filter_contacto_social_title'             => 35,
		'nvx_filter_contacto_social_description'       => 36,
		'nvx_contacto_add_yoast_opengraph_image'       => 37,
		'nvx_content_strip_page_closing_ctas_late'     => 38,

		// === 40-49: Business Rules ===
		'nvx_seo_nonproduction_x_robots_headers'       => 40,
		'nvx_theme_print_google_attribution_meta'      => 41,
		'nvx_add_security_headers'                     => 42,

		// === 50-69: Schema/Content Normalization ===
		'nvx_schema_merge_canonical_website_nodes'     => 50,
		'nvx_extend_yoast_schema_graph'                => 51,
		'nvx_papada_hub_schema_graph'                  => 52,
		'nvx_valoracion_schema_graph'                  => 53,
		'nvx_treatment_hub_extend_yoast_graph'         => 54,
		'nvx_aesthetic_treatment_extend_yoast_graph'   => 55,
		'nvx_filter_contacto_schema_graph'             => 56,
		'nvx_medical_review_schema_graph'              => 57,
		'nvx_seo_production_readiness_schema_graph'    => 58,
		'nvx_schema_semantic_normalize_graph'          => 59,

		// === 70-79: Final Hygiene ===
		'nvx_schema_gate_faq_emission'                 => 70,
		'nvx_schema_deduplicate_ids'                   => 71,
		'nvx_schema_runtime_retire_legacy_emitters'    => 72,
		'nvx_render_deploy_stamp_meta'                 => 73,
		'nvx_render_deploy_stamp_jsonld'               => 74,
	);

	return $priorities[ $filter_name ] ?? 10;
}

/**
 * Register filter with explicit priority.
 *
 * @param string   $hook          Filter hook name.
 * @param callable $callback      Callback function.
 * @param int      $accepted_args Number of arguments.
 * @return bool True on success.
 */
function nvx_add_filter_with_priority( string $hook, callable $callback, int $accepted_args = 1 ): bool {
	$priority = nvx_get_filter_priority( $callback );
	return add_filter( $hook, $callback, $priority, $accepted_args );
}

/**
 * Register action with explicit priority.
 *
 * @param string   $hook          Action hook name.
 * @param callable $callback      Callback function.
 * @param int      $accepted_args Number of arguments.
 * @return bool True on success.
 */
function nvx_add_action_with_priority( string $hook, callable $callback, int $accepted_args = 1 ): bool {
	$priority = nvx_get_filter_priority( $callback );
	return add_action( $hook, $callback, $priority, $accepted_args );
}
