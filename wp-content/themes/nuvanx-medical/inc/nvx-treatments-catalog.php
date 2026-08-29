<?php
/**
 * Treatments index restructure — quiet-luxury catalog.
 *
 * Pattern-based (collaborators / catalog markup), not page-ID gated:
 * - Group treatments into medical categories.
 * - Editorial cards with short copy and contextual CTA.
 * - Collapse brand laundry-list into a discreet logo cloud.
 * - Remove redundant SEO summary blocks.
 * - Close with the canonical dual CTA.
 *
 * @package nuvanx-medical
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Detect the treatments index from stable structural markers. */
function nvx_content_is_treatments_index( string $content ): bool {
	if ( is_page() && 'tratamientos' === get_post_field( 'post_name', get_queried_object_id() ) ) {
		return true;
	}

	if ( false !== strpos( $content, 'nvx-catalog' ) ) {
		return false;
	}

	return (bool) preg_match(
		'/nvx-brand-collaborators|Selección profesional|Catálogo de tratamientos NUVANX|aria-label="Catálogo de tratamientos/iu',
		$content
	);
}

/**
 * Canonical treatment catalogue.
 *
 * Cards explain role, limits and selection criteria. Detailed recovery,
 * sessions, tariffs and comparative claims belong on approved detail pages.
 *
 * @return array<int, array{key:string,label:string,items:array<int,array{meta:string,title:string,body:string,url:string}>}>
 */
function nvx_treatments_catalog_data(): array {
	static $catalog = null;

	if ( is_array( $catalog ) ) {
		return $catalog;
	}

	require_once __DIR__ . '/nvx-catalog-json.php';
	$categories = nvx_catalog_filter_records(
		nvx_catalog_json_resolved( 'treatments-catalog.json' ),
		array( 'key', 'label', 'items' ),
		'treatments-catalog.json:categories'
	);

	$catalog = array();
	foreach ( $categories as $category ) {
		$category['items'] = nvx_catalog_filter_records(
			(array) $category['items'],
			array( 'meta', 'title', 'body', 'url' ),
			'treatments-catalog.json:items'
		);
		$catalog[]         = $category;
	}

	return $catalog;
}

/**
 * Authorized partner marks from the canonical asset registry.
 *
 * @return array<int, array{id:int,label:string,invert?:bool}>
 */
function nvx_treatments_partner_assets(): array {
	require_once __DIR__ . '/nvx-catalog-json.php';
	$registry = nvx_catalog_json_resolved( 'clinic-asset-registry.json' );
	$marks    = $registry['approved_editorial_overrides']['authorized_partner_marks'] ?? array();
	if ( ! is_array( $marks ) ) {
		return array();
	}

	$assets = array();
	foreach ( $marks as $mark ) {
		if ( ! is_array( $mark ) ) {
			continue;
		}
		$id    = (int) ( $mark['attachment_id'] ?? 0 );
		$label = trim( (string) ( $mark['label'] ?? '' ) );
		if ( $id < 1 || '' === $label ) {
			continue;
		}
		$asset = array(
			'id'    => $id,
			'label' => $label,
		);
		if ( true === ( $mark['invert'] ?? false ) ) {
			$asset['invert'] = true;
		}
		$assets[] = $asset;
	}

	return $assets;
}

/** Build the canonical treatment catalogue section. */
function nvx_treatments_catalog_markup(): string {
	$html  = '<section class="nvx-catalog" aria-label="Tratamientos de precisión médica NUVANX">';
	$html .= '<div class="nvx-catalog__inner">';
	$html .= '<header class="nvx-catalog__intro">';
	$html .= '<span class="nvx-catalog__kicker">' . esc_html__( 'NUVANX · Madrid', 'nuvanx-medical' ) . '</span>';
	$html .= '<h2 class="nvx-catalog__title">' . esc_html__( 'Tratamientos de precisión médica', 'nuvanx-medical' ) . '</h2>';
	$html .= '<p class="nvx-catalog__lead">' . esc_html__( 'Cada protocolo combina tecnología láser avanzada y aparatología certificada. La indicación definitiva se confirma exclusivamente tras una valoración médica personalizada en Chamberí o Salamanca–Goya.', 'nuvanx-medical' ) . '</p>';
	$html .= '</header>';

	foreach ( nvx_treatments_catalog_data() as $category ) {
		$html .= '<div class="nvx-catalog-category" data-category="' . esc_attr( $category['key'] ) . '">';
		$html .= '<h3 class="nvx-brand-title nvx-brand-title--compact">' . esc_html( $category['label'] ) . '</h3>';
		$html .= '<div class="nvx-brand-grid nvx-brand-grid--3">';

		foreach ( $category['items'] as $item ) {
			$html .= '<article class="nvx-brand-card">';
			$html .= '<span class="nvx-eyebrow">' . esc_html( $item['meta'] ) . '</span>';
			$html .= '<h4 class="nvx-brand-subtitle">' . esc_html( $item['title'] ) . '</h4>';
			$html .= '<p class="nvx-body">' . esc_html( $item['body'] ) . '</p>';
			$html .= '<a class="nvx-text-link" href="' . esc_url( $item['url'] ) . '">';
			$html .= esc_html__( 'Explorar protocolo', 'nuvanx-medical' );
			$html .= ' <span aria-hidden="true">→</span></a>';
			$html .= '</article>';
		}

		$html .= '</div></div>';
	}

	return $html . '</div></section>';
}

/** Build a discreet, authorized partner-mark band beneath the treatment catalogue. */
function nvx_treatments_logo_cloud_markup(): string {
	$html  = '<section class="nvx-logo-cloud" aria-labelledby="nvx-partner-marks-title">';
	$html .= '<div class="nvx-logo-cloud__inner">';
	$html .= '<header class="nvx-logo-cloud__header">';
	$html .= '<p class="nvx-logo-cloud__eyebrow">' . esc_html__( 'Tecnología y laboratorios autorizados', 'nuvanx-medical' ) . '</p>';
	$html .= '<h2 class="nvx-logo-cloud__title" id="nvx-partner-marks-title">' . esc_html__( 'Partners clínicos y tecnológicos', 'nuvanx-medical' ) . '</h2>';
	$html .= '</header>';
	$html .= '<ul class="nvx-logo-cloud__list" role="list">';

	foreach ( nvx_treatments_partner_assets() as $partner ) {
		$attachment_id = (int) $partner['id'];
		$source_path   = get_attached_file( $attachment_id );
		if ( ! is_string( $source_path ) || '' === $source_path || ! is_readable( $source_path ) ) {
			continue;
		}
		$url = wp_get_attachment_url( $attachment_id );
		if ( ! is_string( $url ) || '' === $url ) {
			continue;
		}
		$probe = '<img src="' . esc_url( $url ) . '" alt="' . esc_attr( (string) $partner['label'] ) . '">';
		if ( function_exists( 'nvx_public_html_is_vendor_image' ) && nvx_public_html_is_vendor_image( $probe ) ) {
			continue;
		}

		$class = 'nvx-logo-cloud__item';
		if ( ! empty( $partner['invert'] ) ) {
			$class .= ' nvx-logo-cloud__item--invert';
		}

		$html .= '<li class="' . esc_attr( $class ) . '">';
		$html .= '<img class="nvx-logo-cloud__image" src="' . esc_url( $url ) . '" alt="' . esc_attr( $partner['label'] ) . '" loading="lazy" decoding="async">';
		$html .= '</li>';
	}

	return $html . '</ul></div></section>';
}

/** Replace prior catalogue, collaborator, summary and CTA blocks. */
function nvx_content_restructure_treatments_index( string $content ): string {
	if ( ! nvx_content_is_treatments_index( $content ) ) {
		return $content;
	}

	$catalog = nvx_treatments_catalog_markup();
	$cloud   = nvx_treatments_logo_cloud_markup();

	$links  = '<section class="nvx-brand-section nvx-brand-section--soft" aria-label="Enlaces de interés">';
	$links .= '<div class="nvx-shell nvx-brand-section__inner">';
	$links .= '<p class="nvx-brand-body">' . esc_html__( 'Explora el ', 'nuvanx-medical' );
	$links .= '<a class="nvx-brand-inline-link" href="' . esc_url( home_url( '/equipo-medico/' ) ) . '">' . esc_html__( 'equipo médico', 'nuvanx-medical' ) . '</a>';
	$links .= esc_html__( ', las ', 'nuvanx-medical' );
	$links .= '<a class="nvx-brand-inline-link" href="' . esc_url( home_url( '/clinicas-de-medicina-estetica-nuvanx/' ) ) . '">' . esc_html__( 'clínicas', 'nuvanx-medical' ) . '</a>';
	$links .= esc_html__( ' o el área de ', 'nuvanx-medical' );
	$links .= '<a class="nvx-brand-inline-link" href="' . esc_url( home_url( '/estetica-avanzada/' ) ) . '">' . esc_html__( 'estética avanzada', 'nuvanx-medical' ) . '</a>.';
	$links .= '</p></div></section>';

	if ( empty( trim( wp_strip_all_tags( $content ) ) ) ) {
		return $catalog . $cloud . $links;
	}

	$content = preg_replace(
		'/<section\b[^>]*aria-label="Catálogo de tratamientos NUVANX"[^>]*>[\s\S]*?<\/section>/iu',
		$catalog,
		$content,
		1,
		$count_catalog
	) ?? $content;
	if ( ! $count_catalog ) {
		$content = preg_replace(
			'/<section\b[^>]*class="[^"]*\bnvx-brand-section\b(?![^"]*collaborators)(?![^"]*cta)[^"]*"[^>]*>[\s\S]*?(?:Áreas de tratamiento|Facial, corporal, láser)[\s\S]*?<\/section>/iu',
			$catalog,
			$content,
			1
		) ?? $content;
	}

	$content = preg_replace(
		'/<section\b[^>]*class="[^"]*nvx-brand-collaborators[^"]*"[^>]*>[\s\S]*?<\/section>/iu',
		$cloud,
		$content,
		1,
		$count_collab
	) ?? $content;
	if ( ! $count_collab ) {
		$content = preg_replace(
			'/<section\b[^>]*aria-label="[^"]*Marcas colaboradoras[^"]*"[^>]*>[\s\S]*?<\/section>/iu',
			$cloud,
			$content,
			1
		) ?? $content;
	}

	$content = preg_replace(
		'/<section\b[^>]*aria-label="Resumen de tratamientos NUVANX"[^>]*>[\s\S]*?<\/section>/iu',
		'',
		$content,
		1
	) ?? $content;
	$content = preg_replace(
		'/<section\b[^>]*class="[^"]*\bnvx-brand-section\b[^"]*"[^>]*>[\s\S]*?¿Qué tratamientos realizamos en NUVANX\?[\s\S]*?<\/section>/iu',
		'',
		$content,
		1
	) ?? $content;

	$content = preg_replace(
		'/<section\b[^>]*aria-label="Enlaces de interés"[^>]*>[\s\S]*?<\/section>/iu',
		$links,
		$content,
		1
	) ?? $content;

	return is_string( $content ) ? $content : '';
}
add_filter( 'the_content', 'nvx_content_restructure_treatments_index', NVX_HOOK_PRIO_TREATMENTS_INDEX );
