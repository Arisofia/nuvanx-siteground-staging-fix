<?php
/**
 * Navigation and menu filters.
 *
 * Treatment links are resolved from published WordPress pages. Future or draft
 * routes must not be exposed in either the database-managed menu or the theme
 * fallback because that creates sitewide internal 404s.
 *
 * @package nuvanx-medical
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Canonical treatment definitions eligible for the primary menu.
 *
 * Each entry may include historical/alternate slugs. The first published page
 * found becomes the public URL; missing and draft pages are omitted.
 *
 * @return array<string, array{label:string, slugs:string[]}>
 */
function nvx_navigation_treatment_definitions(): array {
	return apply_filters(
		'nvx_navigation_treatment_definitions',
		array(
			'exilite'         => array(
				'label' => 'BTL EXILITE™ IPL',
				'slugs' => array( 'btl-exilite-ipl-madrid' ),
			),
			'exion-face'       => array(
				'label' => 'EXION® Face',
				'slugs' => array( 'exion-face' ),
			),
			'exion-body'       => array(
				'label' => 'EXION® Body',
				'slugs' => array( 'exion-body' ),
			),
			'exion-fractional' => array(
				'label' => 'EXION® Fractional',
				'slugs' => array( 'exion-fractional' ),
			),
			'emfusion'         => array(
				'label' => 'EMFUSION',
				'slugs' => array( 'emfusion' ),
			),
			'bioestimuladores' => array(
				'label' => 'Bioestimuladores de Colágeno',
				'slugs' => array( 'bioestimuladores-colageno-madrid' ),
			),
			'ojeras'           => array(
				'label' => 'Ojeras y Surco Lagrimal',
				'slugs' => array( 'ojeras-surco-lagrimal-madrid' ),
			),
			'rinomodelacion'   => array(
				'label' => 'Rinomodelación sin Cirugía',
				'slugs' => array( 'rinomodelacion-sin-cirugia-madrid' ),
			),
			'labios'           => array(
				'label' => 'Labios con Ácido Hialurónico',
				'slugs' => array( 'labios-acido-hialuronico-madrid' ),
			),
			'acido-hialuronico' => array(
				'label' => 'Ácido Hialurónico Facial',
				'slugs' => array( 'acido-hialuronico-relleno-madrid' ),
			),
		)
	);
}

/**
 * Resolve the first published page among candidate slugs.
 *
 * @param array<int,string> $slugs Candidate path slugs.
 * @return array{slug:string,url:string,page_id:int}|null
 */
function nvx_navigation_resolve_published_slug( array $slugs ): ?array {
	foreach ( $slugs as $candidate ) {
		$slug = trim( (string) $candidate, '/' );
		if ( '' === $slug ) {
			continue;
		}

		$page = get_page_by_path( $slug, OBJECT, 'page' );
		if ( ! $page instanceof WP_Post || 'publish' !== get_post_status( $page ) ) {
			// Fallback: search by post_name (basename) if get_page_by_path failed for nested pages.
			$posts = get_posts(
				array(
					'name'        => basename( $slug ),
					'post_type'   => 'page',
					'post_status' => 'publish',
					'numberposts' => 1,
				)
			);
			if ( ! empty( $posts[0] ) && $posts[0] instanceof WP_Post ) {
				$page = $posts[0];
			}
		}

		if ( ! $page instanceof WP_Post || 'publish' !== get_post_status( $page ) ) {
			continue;
		}

		$url = get_permalink( $page );
		if ( ! is_string( $url ) || '' === trim( $url ) ) {
			continue;
		}

		return array(
			'slug'    => $slug,
			'url'     => $url,
			'page_id' => (int) $page->ID,
		);
	}

	return null;
}

/**
 * Resolve the treatment catalogue to published WordPress pages only.
 *
 * @return array<string, array{label:string, slug:string, url:string, page_id:int}>
 */
function nvx_navigation_published_treatments(): array {
	static $catalogue = null;

	if ( is_array( $catalogue ) ) {
		return $catalogue;
	}

	$catalogue = array();

	foreach ( nvx_navigation_treatment_definitions() as $key => $definition ) {
		$label = isset( $definition['label'] ) ? trim( (string) $definition['label'] ) : '';
		$slugs = isset( $definition['slugs'] ) && is_array( $definition['slugs'] ) ? $definition['slugs'] : array();

		if ( '' === $label ) {
			continue;
		}

		$resolved = nvx_navigation_resolve_published_slug( $slugs );
		if ( null === $resolved ) {
			continue;
		}

		$catalogue[ (string) $key ] = array(
			'label'   => $label,
			'slug'    => $resolved['slug'],
			'url'     => $resolved['url'],
			'page_id' => $resolved['page_id'],
		);
	}

	return $catalogue;
}

/**
 * Render a primary-fallback menu item (optional children).
 *
 * @param array{url:string,label:string,children?:array<int,array{url:string,label:string}>} $item Menu item.
 */
function nvx_navigation_primary_fallback_item_html( array $item ): string {
	$children     = isset( $item['children'] ) && is_array( $item['children'] ) ? $item['children'] : array();
	$has_children = array() !== $children;
	$li_class     = 'nvx-nav__item' . ( $has_children ? ' menu-item-has-children' : '' );

	$html = sprintf(
		'<li class="%1$s"><a class="nvx-nav__link" href="%2$s">%3$s</a>',
		esc_attr( $li_class ),
		esc_url( (string) $item['url'] ),
		esc_html( (string) $item['label'] )
	);

	if ( $has_children ) {
		$html .= '<ul class="sub-menu" role="list">';
		foreach ( $children as $child ) {
			$html .= sprintf(
				'<li class="nvx-nav__item"><a class="nvx-nav__link" href="%1$s">%2$s</a></li>',
				esc_url( (string) $child['url'] ),
				esc_html( (string) $child['label'] )
			);
		}
		$html .= '</ul>';
	}

	$html .= '</li>';
	return $html;
}

/**
 * Published-route-aware primary menu fallback.
 *
 * Primary menu fallback when no assigned menu exists. Children are present only
 * when their WordPress page exists and is published.
 *
 * @param array<string, mixed> $args wp_nav_menu arguments.
 * @return string|null
 */
function nvx_navigation_primary_fallback( array $args = array() ) {
	$signature_children = array();
	if ( function_exists( 'nvx_signature_contour_nav_children' ) ) {
		foreach ( nvx_signature_contour_nav_children() as $child ) {
			$label = isset( $child['label'] ) ? trim( (string) $child['label'] ) : '';
			$slugs = isset( $child['slugs'] ) && is_array( $child['slugs'] ) ? $child['slugs'] : array();
			if ( '' === $label || array() === $slugs ) {
				continue;
			}
			$resolved = nvx_navigation_resolve_published_slug( $slugs );
			if ( null === $resolved ) {
				continue;
			}
			$signature_children[] = array(
				'url'   => $resolved['url'],
				'label' => $label,
			);
		}
	}

	$technology_children = array_values( nvx_navigation_published_treatments() );

	// Clinic location children for Clínicas menu item.
	$clinic_children = array();
	$locations_map   = array(
		'Chamberí'       => array( 'clinicas-de-medicina-estetica-nuvanx/medicina-estetica-chamberi', 'medicina-estetica-chamberi' ),
		'Salamanca–Goya' => array( 'clinicas-de-medicina-estetica-nuvanx/medicina-estetica-goya-barrio-salamanca', 'medicina-estetica-goya-barrio-salamanca' ),
	);

	foreach ( $locations_map as $label => $slugs ) {
		$resolved_page = nvx_navigation_resolve_published_slug( $slugs );
		if ( null !== $resolved_page ) {
			$clinic_children[] = array(
				'url'   => $resolved_page['url'],
				'label' => __( $label, 'nuvanx-medical' ),
			);
		}
	}

	// Include Casos clínicos only when the page exists and is not noindex-gated.
	$nvx_casos_id     = function_exists( 'nvx_page_id_by_slug' ) ? nvx_page_id_by_slug( 'casos-de-pacientes' ) : 0;
	$nvx_casos_public = $nvx_casos_id > 0
		&& ( ! function_exists( 'nvx_noindex_page_ids' )
			|| ! in_array( $nvx_casos_id, nvx_noindex_page_ids(), true ) );

	$items = array(
		array(
			'url'   => home_url( '/' ),
			'label' => __( 'Inicio', 'nuvanx-medical' ),
		),
		array(
			'url'   => home_url( '/soluciones-medicas/' ),
			'label' => __( 'Soluciones médicas', 'nuvanx-medical' ),
		),
		array(
			'url'   => home_url( '/protocolo-novias-madrid/' ),
			'label' => __( 'Protocolo Novias', 'nuvanx-medical' ),
		),
		array(
			'url'      => home_url( '/protocolos-signature/' ),
			'label'    => __( 'Protocolos Signature', 'nuvanx-medical' ),
			'children' => $signature_children,
		),
		array(
			'url'      => home_url( '/tratamientos/' ),
			'label'    => __( 'Tecnología', 'nuvanx-medical' ),
			'children' => $technology_children,
		),
		array(
			'url'   => home_url( '/equipo-medico/' ),
			'label' => __( 'Equipo médico', 'nuvanx-medical' ),
		),
		array(
			'url'      => home_url( '/clinicas-de-medicina-estetica-nuvanx/' ),
			'label'    => __( 'Clínicas', 'nuvanx-medical' ),
			'children' => $clinic_children,
		),
		array(
			'url'   => home_url( '/blog/' ),
			'label' => __( 'Journal', 'nuvanx-medical' ),
		),
		array(
			'url'   => home_url( '/contacto/' ),
			'label' => __( 'Contacto', 'nuvanx-medical' ),
		),
	);

	if ( $nvx_casos_public ) {
		// Splice after Tecnología (index 3) to maintain nav order.
		array_splice(
			$items,
			4,
			0,
			array(
				array(
					'url'   => home_url( '/casos-de-pacientes/' ),
					'label' => __( 'Casos clínicos', 'nuvanx-medical' ),
				),
			)
		);
	}

	$menu_class = isset( $args['menu_class'] ) && '' !== trim( (string) $args['menu_class'] )
		? trim( (string) $args['menu_class'] )
		: 'nvx-nav__list';
	$menu_id    = isset( $args['menu_id'] ) && '' !== trim( (string) $args['menu_id'] )
		? ' id="' . esc_attr( trim( (string) $args['menu_id'] ) ) . '"'
		: '';

	$html = '<ul' . $menu_id . ' class="' . esc_attr( $menu_class ) . '">';
	foreach ( $items as $item ) {
		$html .= nvx_navigation_primary_fallback_item_html( $item );
	}
	$html .= '</ul>';

	if ( ! array_key_exists( 'echo', $args ) || $args['echo'] ) {
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped during assembly.
		return null;
	}

	return $html;
}

/**
 * Ensure an enabled primary fallback cannot expose unpublished routes.
 *
 * A caller that explicitly sets `fallback_cb => false` (the mobile drawer) is
 * preserved. This avoids re-enabling markup that the mobile navigation contract
 * intentionally suppresses.
 *
 * @param array<string, mixed> $args wp_nav_menu arguments.
 * @return array<string, mixed>
 */
function nvx_navigation_filter_menu_args( array $args ): array {
	$primary          = 'primary' === ( $args['theme_location'] ?? '' );
	$fallback_enabled = ! array_key_exists( 'fallback_cb', $args ) || false !== $args['fallback_cb'];

	if ( $primary && $fallback_enabled ) {
		$args['fallback_cb'] = 'nvx_navigation_primary_fallback';
	}

	return $args;
}
add_filter( 'wp_nav_menu_args', 'nvx_navigation_filter_menu_args', 20 );

/**
 * Helper to construct stdClass menu item object.
 */
function nvx_create_custom_menu_item( int $id, string $title, string $url, int $menu_order, int $parent_id, int $object_id ): stdClass {
	$item                   = new stdClass();
	$item->ID               = $id;
	$item->db_id            = $id;
	$item->title            = $title;
	$item->url              = $url;
	$item->menu_order       = $menu_order;
	$item->menu_item_parent = $parent_id;
	$item->type             = 'custom';
	$item->object           = 'page';
	$item->object_id        = $object_id;
	$item->classes          = array( 'menu-item', 'menu-item-type-post_type', 'menu-item-object-page' );
	$item->target           = '';
	$item->attr_title       = '';
	$item->description      = '';
	$item->xfn              = '';
	$item->status           = 'publish';

	return $item;
}

/**
 * Locate the Tratamientos parent menu item id and the max menu item id.
 *
 * @param array<int, WP_Post|stdClass> $items Menu items.
 * @return array{parent_id:int,max_id:int}
 */
function nvx_navigation_find_tratamientos_parent( array $items ): array {
	$tratamientos_id = 0;
	$max_id          = 0;

	foreach ( $items as $item ) {
		$item_id = isset( $item->ID ) ? (int) $item->ID : 0;
		$max_id  = max( $max_id, $item_id );

		if (
			( isset( $item->url ) && false !== strpos( (string) $item->url, '/tratamientos/' ) )
			|| ( isset( $item->title ) && 'Tratamientos' === $item->title )
		) {
			$tratamientos_id = $item_id;
		}
	}

	return array(
		'parent_id' => $tratamientos_id,
		'max_id'    => $max_id,
	);
}

/**
 * Collect existing child URLs and max order under a parent menu item.
 *
 * @param array<int, WP_Post|stdClass> $items Menu items.
 * @return array{max_order:int,urls:array<string,true>}
 */
function nvx_navigation_collect_parent_children( array $items, int $parent_id ): array {
	$max_child_order = 0;
	$existing_urls   = array();

	foreach ( $items as $item ) {
		if ( ! isset( $item->menu_item_parent ) || (int) $item->menu_item_parent !== $parent_id ) {
			continue;
		}
		$max_child_order = max( $max_child_order, isset( $item->menu_order ) ? (int) $item->menu_order : 0 );
		if ( isset( $item->url ) && is_string( $item->url ) ) {
			$existing_urls[ untrailingslashit( $item->url ) ] = true;
		}
	}

	return array(
		'max_order' => $max_child_order,
		'urls'      => $existing_urls,
	);
}

/**
 * Ensure a menu item has the menu-item-has-children class.
 *
 * @param array<int, WP_Post|stdClass> $items Menu items.
 * @return array<int, WP_Post|stdClass>
 */
function nvx_navigation_mark_has_children( array $items, int $parent_id ): array {
	foreach ( $items as $item ) {
		if ( ! isset( $item->ID ) || (int) $item->ID !== $parent_id ) {
			continue;
		}
		$item->classes = isset( $item->classes ) && is_array( $item->classes ) ? $item->classes : array();
		if ( ! in_array( 'menu-item-has-children', $item->classes, true ) ) {
			$item->classes[] = 'menu-item-has-children';
		}
		break;
	}
	return $items;
}

/**
 * Append missing published treatment children under Tratamientos.
 *
 * @param array<int, WP_Post|stdClass>                                             $items Menu items.
 * @param array<string, array{label:string, slug:string, url:string, page_id:int}> $published Published treatments.
 * @return array{items:array<int, WP_Post|stdClass>,added:int}
 */
function nvx_navigation_append_treatment_children( array $items, array $published, int $parent_id, int $max_id ): array {
	$child_state     = nvx_navigation_collect_parent_children( $items, $parent_id );
	$max_child_order = $child_state['max_order'];
	$existing_urls   = $child_state['urls'];
	$added           = 0;

	foreach ( $published as $page ) {
		$normalized_url = untrailingslashit( $page['url'] );
		if ( isset( $existing_urls[ $normalized_url ] ) ) {
			continue;
		}

		++$max_id;
		++$max_child_order;
		$items[]                          = nvx_create_custom_menu_item( $max_id, $page['label'], $page['url'], $max_child_order, $parent_id, $page['page_id'] );
		$existing_urls[ $normalized_url ] = true;
		++$added;
	}

	return array(
		'items' => $items,
		'added' => $added,
	);
}

/**
 * Dynamically inject published EXION®/EMFUSION pages under Tratamientos.
 *
 * Database-managed menus receive only published routes. Existing children of
 * the Tratamientos submenu are preserved and deduplicated by normalized URL.
 * A matching URL elsewhere in the menu does not suppress the required child.
 *
 * @param array<int, WP_Post|stdClass> $items Menu items.
 * @param stdClass                     $args  Menu args.
 * @return array<int, WP_Post|stdClass>
 */
function nvx_add_exion_to_tratamientos_menu( $items, $args ) {
	if ( ! isset( $args->theme_location ) || 'primary' !== $args->theme_location ) {
		return $items;
	}

	$published = nvx_navigation_published_treatments();
	if ( empty( $published ) ) {
		return $items;
	}

	$parent = nvx_navigation_find_tratamientos_parent( $items );
	if ( ! $parent['parent_id'] ) {
		return $items;
	}

	$result = nvx_navigation_append_treatment_children(
		$items,
		$published,
		$parent['parent_id'],
		$parent['max_id']
	);
	$items  = $result['items'];

	if ( $result['added'] > 0 ) {
		$items = nvx_navigation_mark_has_children( $items, $parent['parent_id'] );
	}

	return $items;
}
add_filter( 'wp_nav_menu_objects', 'nvx_add_exion_to_tratamientos_menu', 10, 2 );
