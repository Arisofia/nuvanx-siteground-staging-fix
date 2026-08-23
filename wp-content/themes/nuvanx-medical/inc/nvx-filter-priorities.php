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
		'nvx_contacto_resolve_legacy_template'         => 10, // page_template
		'nvx_contacto_seo_title'                       => 11, // wpseo_title
		'nvx_contacto_seo_metadesc'                    => 12, // wpseo_metadesc
		'nvx_contacto_migrate_legacy_template_meta'    => 13, // template_redirect
		'nvx_theme_disable_public_facebook_pixel'      => 14, // option_active_plugins

		// === 20-29: Structural Renderer ===
		'nvx_contact_append_maps'                      => 20, // the_content
		'nvx_content_presentation_enhance'             => 21, // the_content
		'nvx_content_inject_global_treatment_sections' => 22, // the_content
		'nvx_content_ensure_exion_investment'          => 23, // the_content
		'nvxSedeStripLayoutInlineStyles'               => 24, // the_content
		'nvx_clinics_hub_render_managed'               => 25, // the_content
		'nvxClinicsHubEnhance'                         => 26, // the_content
		'nvx_contacto_enhance_valoracion_page'         => 27, // the_content
		'nvx_bridal_inject_media'                      => 29, // the_content

		// === 30-39: Presentation ===
		'nvx_filter_valoracion_document_title'         => 30, // wpseo_title
		'nvx_filter_valoracion_metadesc'               => 31, // wpseo_metadesc
		'nvx_filter_contacto_document_title'           => 32, // wpseo_title
		'nvx_filter_contacto_metadesc'                 => 33, // wpseo_metadesc
		'nvx_filter_contacto_social_image'             => 34, // wpseo_opengraph_image
		'nvx_filter_contacto_social_title'             => 35, // wpseo_opengraph_title
		'nvx_filter_contacto_social_description'       => 36, // wpseo_opengraph_desc
		'nvx_contacto_add_yoast_opengraph_image'       => 37, // wpseo_add_opengraph_images
		'nvx_content_strip_page_closing_ctas_late'     => 38, // the_content

		// === 40-49: Business Rules ===
		'nvx_seo_nonproduction_x_robots_headers'       => 40, // wp_headers
		'nvx_theme_print_google_attribution_meta'      => 41, // wp_head

		// === 50-59: Schema/Content Normalization ===
		'nvx_treatment_hub_extend_yoast_graph'         => 50, // wpseo_schema_graph
		'nvx_aesthetic_treatment_extend_yoast_graph'   => 51, // wpseo_schema_graph
		'nvx_extend_yoast_schema_graph'                => 52, // wpseo_schema_graph
		'nvx_seo_production_readiness_schema_graph'    => 53, // wpseo_schema_graph
		'nvx_filter_contacto_schema_graph'             => 54, // wpseo_schema_graph
		'nvx_schema_semantic_normalize_graph'          => 55, // wpseo_schema_graph

		// === 60-69: Final Hygiene ===
		'nvx_schema_gate_faq_emission'                 => 60, // wpseo_schema_graph
		'nvx_schema_deduplicate_ids'                   => 61, // wpseo_schema_graph
		'nvx_schema_runtime_retire_legacy_emitters'    => 62, // wp_loaded
		'nvx_render_deploy_stamp_meta'                 => 63, // wp_head
		'nvx_render_deploy_stamp_jsonld'               => 64, // wp_head
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
