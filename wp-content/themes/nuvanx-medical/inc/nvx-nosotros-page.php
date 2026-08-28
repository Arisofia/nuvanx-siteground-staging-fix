<?php
/**
 * Sobre Nosotros — authority, platforms, NAP, cuadro médico corto, principios.
 *
 * Path: /nosotros/ only. Does not rewrite home, equipo (full bios) or treatment pages.
 * Technology copy is positioning-level; detail pages keep full clinical encyclopedia.
 * No AggregateRating hardcode. No videoconsulta CTA.
 *
 * @package nuvanx-medical
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const NVX_NOSOTROS_PATH       = '/nosotros/';
const NVX_SOBRE_NOSOTROS_PATH = '/sobre-nosotros/';

/**
 * Singular page context.
 */
function nvx_nosotros_is_singular_context(): bool {
	if ( is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
		return false;
	}

	return is_page();
}

/**
 * Whether a path matches either canonical Sobre Nosotros route.
 */
function nvx_nosotros_path_matches( string $path ): bool {
	return function_exists( 'nvx_schema_path_matches' )
		&& (
			nvx_schema_path_matches( $path, NVX_NOSOTROS_PATH )
			|| nvx_schema_path_matches( $path, NVX_SOBRE_NOSOTROS_PATH )
		);
}

/**
 * Detect Sobre Nosotros page only.
 */
function nvx_content_is_nosotros_page( string $content ): bool {
	if ( false !== strpos( $content, 'nvx-nosotros-editorial' ) ) {
		return false;
	}

	if ( ! nvx_nosotros_is_singular_context() || is_front_page() || is_home() ) {
		return false;
	}

	$path = function_exists( 'nvx_schema_current_path' )
		? nvx_schema_current_path( (int) get_queried_object_id() )
		: '';

	if ( is_string( $path ) && nvx_nosotros_path_matches( $path ) ) {
		return true;
	}

	$slug = (string) get_post_field( 'post_name', get_queried_object_id() );
	if ( in_array( $slug, array( 'nosotros', 'sobre-nosotros', 'about' ), true ) ) {
		return true;
	}

	return (bool) preg_match(
		'/class=["\'][^"\']*\bnvx-brand-page--nosotros\b|id=["\']nvx-nosotros-h1["\']|aria-label=["\']Sobre Nosotros NUVANX["\']/iu',
		$content
	);
}

/**
 * Public URL helper.
 */
function nvx_nosotros_url( string $path ): string {
	$path = trim( $path, '/' );
	if ( function_exists( 'nvx_laser_page_url' ) ) {
		return nvx_laser_page_url( $path );
	}
	return home_url( '/' . $path . '/' );
}

/**
 * Registry of Nosotros page content.
 *
 * @return array<string, mixed>
 */
function nvx_nosotros_registry(): array {
	require_once __DIR__ . '/nvx-catalog-json.php';
	return nvx_catalog_json_resolved( 'nosotros-page.json' );
}

/**
 * Builds the hero copy markup for the Nosotros page, including its heading, supporting text, metadata, and valuation call to action.
 *
 * @return string The escaped hero copy HTML.
 */
function nvx_nosotros_hero_copy_markup(): string {
	$data = nvx_nosotros_registry();
	$hero = $data['hero'] ?? array();

	$html  = '<div class="nvx-brand-hero__copy">';
	$html .= '<p class="nvx-brand-kicker">' . esc_html( $hero['kicker'] ?? '' ) . '</p>';
	$html .= '<h1 class="nvx-brand-hero__title" id="nvx-nosotros-h1">' . esc_html( $hero['h1'] ?? '' ) . '</h1>';
	$html .= '<p class="nvx-brand-hero__lead">' . esc_html( $hero['lead'] ?? '' ) . '</p>';

	if ( function_exists( 'nvx_cta_pair_markup' ) ) {
		$html .= nvx_cta_pair_markup( 'nvx-brand-actions' );
	} else {
		$html .= '<div class="nvx-brand-actions"><a class="nvx-brand-btn nvx-brand-btn--primary" href="' . esc_url( home_url( '/madrid/valoracion/' ) ) . '">' . esc_html__( 'Valoración gratuita — sin compromiso', 'nuvanx-medical' ) . '</a></div>';
	}

	$html .= '<p class="nvx-brand-meta">' . esc_html( $hero['meta'] ?? '' ) . '</p>';
	$html .= '</div>';

	return $html;
}

/**
 * Positioning intro.
 */
function nvx_nosotros_positioning_markup(): string {
	$data = nvx_nosotros_registry();
	$pos  = $data['positioning'] ?? array();

	$html  = '<section class="nvx-brand-section nvx-nosotros-positioning" aria-labelledby="nvx-nosotros-pos-title">';
	$html .= '<div class="nvx-container">';
	$html .= '<p class="nvx-brand-kicker" aria-hidden="true">' . esc_html( $pos['kicker'] ?? '' ) . '</p>';
	$html .= '<h2 id="nvx-nosotros-pos-title" class="nvx-heading">' . esc_html( $pos['title'] ?? '' ) . '</h2>';
	foreach ( (array) ( $pos['body'] ?? array() ) as $p ) {
		$html .= '<p class="nvx-body nvx-body--measure">' . esc_html( $p ) . '</p>';
	}
	$html .= '</div></section>';

	return $html;
}

/**
 * Platforms section markup.
 */
function nvx_nosotros_platforms_markup(): string {
	$data = nvx_nosotros_registry();
	$plat = $data['platforms'] ?? array();

	$html  = '<section class="nvx-brand-section nvx-nosotros-platforms" aria-labelledby="nvx-nosotros-tech-title">';
	$html .= '<div class="nvx-container">';
	$html .= '<p class="nvx-brand-kicker" aria-hidden="true">' . esc_html( $plat['kicker'] ?? '' ) . '</p>';
	$html .= '<h2 id="nvx-nosotros-tech-title" class="nvx-heading">' . esc_html( $plat['title'] ?? '' ) . '</h2>';
	$html .= '<p class="nvx-body nvx-body--measure">' . esc_html( $plat['lead'] ?? '' ) . '</p>';
	$html .= '<ul class="nvx-feature-zone-list nvx-nosotros-platform-list" role="list">';

	foreach ( (array) ( $plat['items'] ?? array() ) as $item ) {
		$html .= '<li class="nvx-feature-zone">';
		$html .= '<h3 class="nvx-feature-zone__title">' . esc_html( $item['title'] ?? '' ) . '</h3>';
		$html .= '<p class="nvx-body">' . esc_html( $item['body'] ?? '' ) . '</p>';
		if ( ! empty( $item['url'] ) ) {
			$html .= '<p class="nvx-nosotros-platform-link"><a class="nvx-brand-inline-link" href="' . esc_url( nvx_nosotros_url( $item['url'] ) ) . '">' . esc_html( $plat['learn_more'] ?? '' ) . '</a></p>';
		}
		$html .= '</li>';
	}

	$rel_url = nvx_nosotros_url( (string) ( $plat['related']['url'] ?? '' ) );
	$html   .= '<li class="nvx-feature-zone nvx-nosotros-platform-related">';
	$html   .= '<h3 class="nvx-feature-zone__title">' . esc_html( $plat['related']['title'] ?? '' ) . '</h3>';
	$html   .= '<p class="nvx-body">' . esc_html( $plat['related']['body'] ?? '' ) . '</p>';
	$html   .= '<p class="nvx-nosotros-platform-link"><a class="nvx-brand-inline-link" href="' . esc_url( $rel_url ) . '">' . esc_html( $plat['related']['label'] ?? '' ) . '</a></p>';
	$html   .= '</li>';

	$html .= '</ul></div></section>';

	return $html;
}

/**
 * Builds the clinic contact section for the Nosotros page.
 *
 * @return string The rendered clinic contact markup.
 */
function nvx_nosotros_clinics_markup(): string {
	$data = nvx_nosotros_registry();
	$c    = $data['clinics'] ?? array();

	$clinics = function_exists( 'nvx_contact_clinics_nap' )
		? nvx_contact_clinics_nap()
		: ( $c['fallback_clinics'] ?? array() );

	$html  = '<section class="nvx-brand-section nvx-nosotros-clinics" aria-labelledby="nvx-nosotros-clinics-title">';
	$html .= '<div class="nvx-container">';
	$html .= '<p class="nvx-brand-kicker" aria-hidden="true">' . esc_html( $c['kicker'] ?? '' ) . '</p>';
	$html .= '<h2 id="nvx-nosotros-clinics-title" class="nvx-heading">' . esc_html( $c['title'] ?? '' ) . '</h2>';
	$html .= '<p class="nvx-body nvx-body--measure">' . esc_html( $c['lead'] ?? '' ) . '</p>';
	$html .= '<ul class="nvx-contact-clinics" role="list">';

	foreach ( $clinics as $clinic ) {
		$clinic_id = 'nvx-clinic-' . sanitize_title( $clinic['name'] ?? '' );
		$html     .= '<li>';
		$html     .= '<article class="nvx-contact-clinic" aria-labelledby="' . esc_attr( $clinic_id ) . '">';
		$html     .= '<h3 id="' . esc_attr( $clinic_id ) . '" class="nvx-contact-clinic__name">' . esc_html( $clinic['name'] ?? '' ) . '</h3>';
		$html     .= '<p class="nvx-contact-clinic__reg"><strong>' . esc_html( $c['reg_label'] ?? '' ) . '</strong> — ' . esc_html( $clinic['reg'] ?? '' ) . '</p>';
		$html     .= '<p class="nvx-contact-clinic__addr">' . esc_html( $clinic['address'] ?? '' ) . '</p>';
		if ( ! empty( $clinic['phone'] ) && ! empty( $clinic['phone_href'] ) ) {
			$html .= '<p class="nvx-contact-clinic__phone"><a class="nvx-brand-inline-link" href="tel:' . esc_attr( $clinic['phone_href'] ) . '">' . esc_html( $clinic['phone'] ) . '</a></p>';
		} elseif ( ! empty( $clinic['phone'] ) ) {
			$html .= '<p class="nvx-contact-clinic__phone">' . esc_html( $clinic['phone'] ) . '</p>';
		}
		if ( ! empty( $clinic['days'] ) ) {
			$html .= '<p class="nvx-contact-clinic__days">' . esc_html( $clinic['days'] ) . '</p>';
		}
		$html .= '</article>';
		$html .= '</li>';
	}

	$html    .= '</ul>';
	$clinicas = home_url( '/' . ( $c['hub_url'] ?? '' ) . '/' );
	$html    .= '<p class="nvx-body"><a class="nvx-brand-inline-link" href="' . esc_url( $clinicas ) . '">' . esc_html( $c['hub_link_label'] ?? '' ) . '</a></p>';
	$html    .= '</div></section>';

	return $html;
}

/**
 * Renders a concise medical team section with registration details, biographies, and related links.
 *
 * @return string The generated team section HTML.
 */
function nvx_nosotros_team_markup(): string {
	$data = nvx_nosotros_registry();
	$t    = $data['team'] ?? array();

	$equipo = home_url( '/' . ( $t['hub_url'] ?? '' ) . '/' );

	$html  = '<section class="nvx-brand-section nvx-nosotros-team" aria-labelledby="nvx-nosotros-team-title">';
	$html .= '<div class="nvx-container">';
	$html .= '<p class="nvx-brand-kicker" aria-hidden="true">' . esc_html( $t['kicker'] ?? '' ) . '</p>';
	$html .= '<h2 id="nvx-nosotros-team-title" class="nvx-heading">' . esc_html( $t['title'] ?? '' ) . '</h2>';
	$html .= '<p class="nvx-body nvx-body--measure">' . esc_html( $t['lead'] ?? '' ) . '</p>';
	$html .= '<ul class="nvx-nosotros-team-grid" role="list">';

	$index = 0;
	foreach ( (array) ( $t['members'] ?? array() ) as $m ) {
		$col = $m['colegiado'] ?? '';
		if ( 0 === $index && defined( 'NVX_DIRECTOR_COLEGIADO' ) ) {
			$col = NVX_DIRECTOR_COLEGIADO;
		} elseif ( 1 === $index && defined( 'NVX_IVON_COLEGIADO' ) ) {
			$col = NVX_IVON_COLEGIADO;
		} elseif ( 2 === $index && defined( 'NVX_FABIO_COLEGIADO' ) ) {
			$col = NVX_FABIO_COLEGIADO;
		}

		$member_id = 'nvx-team-card-' . $index;
		$bio_label = esc_attr( ( $t['bio_link_label'] ?? '' ) . ': ' . ( $m['name'] ?? '' ) );
		$doc_label = esc_attr( ( $t['doctoralia_link_label'] ?? '' ) . ': ' . ( $m['name'] ?? '' ) );
		$html     .= '<li>';
		$html     .= '<article class="nvx-nosotros-team-card" aria-labelledby="' . esc_attr( $member_id ) . '">';
		$html     .= '<p class="nvx-brand-kicker" aria-hidden="true">' . esc_html( $m['role'] ?? '' ) . '</p>';
		$html     .= '<h3 id="' . esc_attr( $member_id ) . '" class="nvx-feature-zone__title">' . esc_html( $m['name'] ?? '' ) . '</h3>';
		$html     .= '<p class="nvx-body"><strong>' . esc_html( $t['icomem_label'] ?? '' ) . '</strong> ' . esc_html( $col ) . '</p>';
		$html     .= '<p class="nvx-body">' . esc_html( $m['body'] ?? '' ) . '</p>';
		$html     .= '<p class="nvx-nosotros-platform-link"><a class="nvx-brand-inline-link" href="' . esc_url( $equipo . '#' . ( $m['anchor'] ?? '' ) ) . '" aria-label="' . $bio_label . '">' . esc_html( $t['bio_link_label'] ?? '' ) . '</a></p>';
		if ( ! empty( $m['doctoralia'] ) ) {
			$html .= '<p class="nvx-nosotros-platform-link"><a class="nvx-brand-inline-link" href="' . esc_url( $m['doctoralia'] ) . '" target="_blank" rel="noopener noreferrer" aria-label="' . $doc_label . '">' . esc_html( $t['doctoralia_link_label'] ?? '' ) . '</a></p>';
		}
		$html .= '</article>';
		$html .= '</li>';
		++$index;
	}

	$html .= '</ul>';
	$html .= '<p class="nvx-body"><a class="nvx-brand-inline-link" href="' . esc_url( $equipo ) . '">' . esc_html( $t['hub_link_label'] ?? '' ) . '</a></p>';
	$html .= '</div></section>';

	return $html;
}

/**
 * Builds the medical principles section markup.
 *
 * @return string The escaped HTML markup for the medical principles section.
 */
function nvx_nosotros_principles_markup(): string {
	$data = nvx_nosotros_registry();
	$p    = $data['principles'] ?? array();

	$html  = '<section class="nvx-brand-section nvx-nosotros-principles" aria-labelledby="nvx-nosotros-principles-title">';
	$html .= '<div class="nvx-container">';
	$html .= '<p class="nvx-brand-kicker" aria-hidden="true">' . esc_html( $p['kicker'] ?? '' ) . '</p>';
	$html .= '<h2 id="nvx-nosotros-principles-title" class="nvx-heading">' . esc_html( $p['title'] ?? '' ) . '</h2>';
	$html .= '<ul class="nvx-feature-zone-list" role="list">';
	foreach ( (array) ( $p['items'] ?? array() ) as $item ) {
		$html .= '<li class="nvx-feature-zone">';
		$html .= '<h3 class="nvx-feature-zone__title">' . esc_html( $item['title'] ?? '' ) . '</h3>';
		$html .= '<p class="nvx-body">' . esc_html( $item['body'] ?? '' ) . '</p>';
		$html .= '</li>';
	}
	$html .= '</ul></div></section>';

	return $html;
}

/**
 * Full editorial body.
 * Closing valoración CTA: site-wide nvx-cta-banner in footer.php.
 */
function nvx_nosotros_editorial_body_markup(): string {
	$html  = '<div class="nvx-brand-section-wrap">';
	$html .= nvx_nosotros_positioning_markup();
	$html .= nvx_nosotros_platforms_markup();
	$html .= nvx_nosotros_clinics_markup();
	$html .= nvx_nosotros_team_markup();
	$html .= nvx_nosotros_principles_markup();
	$html .= '</div>';

	return $html;
}

/**
 * Rebuild nosotros page content once.
 */
add_filter(
	'nvx_page_owner',
	function ( $owner ) {
		if ( ! empty( $owner ) ) {
			return $owner;
		}
		global $post;
		$content = $post ? $post->post_content : '';
		if ( function_exists( 'nvx_content_is_nosotros_page' ) && nvx_content_is_nosotros_page( $content ) ) {
			return 'nvx_nosotros_page';
		}
		return $owner;
	}
);

function nvx_content_restructure_nosotros_page( string $content ): string {
	$owner = function_exists( 'nvx_get_page_owner' ) ? nvx_get_page_owner() : null;
	if ( $owner !== 'nvx_nosotros_page' ) {
		return $content;
	}

	$media = '';
	if ( preg_match( '/<figure class="nvx-brand-hero__media"[\s\S]*?<\/figure>/iu', $content, $m ) ) {
		$media = $m[0];
	} elseif ( preg_match( '/<div class="nvx-brand-hero__media"[\s\S]*?<\/div>/iu', $content, $m ) ) {
		$media = $m[0];
	}
	// Drop logo-as-hero if helper exists.
	if ( '' !== $media && function_exists( 'nvx_equipo_media_is_logo' ) && nvx_equipo_media_is_logo( $media ) ) {
		$media = '';
	}

	// aria-labelledby is sufficient; aria-label is redundant and creates conflict for screen readers.
	$hero  = '<section class="nvx-brand-hero" aria-labelledby="nvx-nosotros-h1">';
	$hero .= '<div class="nvx-brand-hero__inner">';
	$hero .= nvx_nosotros_hero_copy_markup();
	$hero .= $media;
	$hero .= '</div></section>';

	$body = nvx_nosotros_editorial_body_markup();

	// Use standard wrapper like soluciones-medicas for consistent margins
	$standard_wrapper = '<div class="entry-content nvx-page__content">';
	return $standard_wrapper . $hero . $body . '</div>';
}
add_filter( 'the_content', 'nvx_content_restructure_nosotros_page', NVX_HOOK_PRIO_NOSOTROS );

/**
 * Document title for nosotros.
 *
 * @param string $title Title.
 * @return string
 */
function nvx_filter_nosotros_document_title( $title ) {
	if ( ! function_exists( 'nvx_schema_path_matches' ) || ! function_exists( 'nvx_schema_current_path' ) ) {
		return $title;
	}
	$path = nvx_schema_current_path( (int) get_queried_object_id() );
	if ( ! nvx_nosotros_path_matches( $path ) ) {
		return $title;
	}
	return nvx_nosotros_registry()['yoast_title'] ?? $title;
}
add_filter( 'wpseo_title', 'nvx_filter_nosotros_document_title', 21 );

/**
 * Meta description for nosotros.
 *
 * @param string $desc Description.
 * @return string
 */
function nvx_filter_nosotros_metadesc( $desc ) {
	if ( ! function_exists( 'nvx_schema_path_matches' ) || ! function_exists( 'nvx_schema_current_path' ) ) {
		return $desc;
	}
	$path = nvx_schema_current_path( (int) get_queried_object_id() );
	if ( ! nvx_nosotros_path_matches( $path ) ) {
		return $desc;
	}
	return nvx_nosotros_registry()['yoast_desc'] ?? $desc;
}
add_filter( 'wpseo_metadesc', 'nvx_filter_nosotros_metadesc', 21 );
