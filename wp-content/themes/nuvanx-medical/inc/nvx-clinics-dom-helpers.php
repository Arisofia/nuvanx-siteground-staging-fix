<?php
/**
 * DOM manipulation helpers and configuration arrays for the clinics hub template.
 *
 * Contains CSS class registries, DOM traversal helpers, section normalization,
 * unwrap/hoist logic, and inline-style scrubbing — all operating on DOMDocument.
 *
 * Extracted from nvx-clinics-hub.php.
 *
 * @package NUVANX
 */

defined( 'ABSPATH' ) || exit;

/*
-------------------------------------------------------------------------
 * Shared class / style lists (defined once; helpers return static caches)
 * ---------------------------------------------------------------------- */

/**
 * Section class tokens that must not be rewritten to brand-section shells.
 *
 * @return string[]
 */
function nvxClinicsSectionSkipClasses(): array {
	static $classes = null;
	if ( null === $classes ) {
		$classes = array(
			'nvx-brand-hero',
			'nvx-cta-banner',
			'nvx-clinics-nav',
			'nvx-hero-intro',
		);
	}
	return $classes;
}

/**
 * First-child div classes that already act as section inners / grids / shells.
 *
 * @return string[]
 */
function nvxClinicsSectionInnerReadyClasses(): array {
	static $classes = null;
	if ( null === $classes ) {
		$classes = array(
			'nvx-brand-section__inner',
			'nvx-brand-grid',
			'nvx-shell',
			'nvx-clinics-content-flow',
			'nvx-content-flow',
			'nvx-brand-readable',
			'wp-block-columns',
			'wp-block-group',
			'is-layout-flex',
			'is-layout-grid',
		);
	}
	return $classes;
}

/**
 * Div wrappers that must not be unwrapped (canonical structure).
 *
 * @return string[]
 */
function nvxClinicsUnwrapProtectedClasses(): array {
	static $classes = null;
	if ( null === $classes ) {
		$classes = array(
			'nvx-brand-section__inner',
			'nvx-brand-grid',
			'nvx-shell',
			'nvx-brand-hero',
			'nvx-brand-actions',
			'nvx-brand-card',
			'nvx-content-flow',
			'nvx-clinics-content-flow',
			'nvx-brand-readable',
			'nvx-brand-page',
		);
	}
	return $classes;
}

/**
 * Class tokens that identify multi-section flow containers (hoist targets).
 *
 * @return string[]
 */
function nvxClinicsFlowClasses(): array {
	static $classes = null;
	if ( null === $classes ) {
		$classes = array(
			'nvx-content-flow',
			'nvx-clinics-content-flow',
		);
	}
	return $classes;
}

/**
 * Classes that mark a measure-constrained wrapper (normalized into content-flow).
 *
 * @return string[]
 */
function nvxClinicsReadableMeasureClasses(): array {
	static $classes = null;
	if ( null === $classes ) {
		$classes = array(
			'nvx-brand-readable',
			'nvx-brand-readable--wide',
		);
	}
	return $classes;
}

/**
 * CMS wrapper classes where inline layout styles may be stripped on Sede pages.
 * Editors can keep custom styles on other elements; only these get cleaned.
 *
 * @return string[]
 */
function nvxSedeInlineStyleTargetClasses(): array {
	static $classes = null;
	if ( null === $classes ) {
		$classes = array(
			'nvx-brand-card',
			'nvx-brand-actions',
			'nvx-brand-body',
			'nvx-brand-section__inner',
			'nvx-brand-grid',
		);
	}
	return $classes;
}

/**
 * CSS properties stripped from targeted Sede wrappers only (spacing that fights tokens).
 * Intentionally narrow: keep color, font-size, text-align, width, background for editorial opt-in.
 *
 * @return string[]
 */
function nvxSedeBlockedInlineStyleProperties(): array {
	static $props = null;
	if ( null === $props ) {
		$props = array(
			'margin',
			'margin-top',
			'margin-right',
			'margin-bottom',
			'margin-left',
			'margin-block',
			'margin-inline',
			'padding',
			'padding-top',
			'padding-right',
			'padding-bottom',
			'padding-left',
			'padding-block',
			'padding-inline',
		);
	}
	return $props;
}

/**
 * Tags allowed when rewriting style attributes (no void/self-closing noise).
 *
 * @return string[]
 */
function nvxSedeInlineStyleAllowedTags(): array {
	static $tags = null;
	if ( null === $tags ) {
		$tags = array( 'div', 'section', 'article', 'p', 'span', 'a', 'li', 'h2', 'h3', 'h4' );
	}
	return $tags;
}

/**
 * PHP 7-compatible string prefix check (avoid str_starts_with for WP hosts on 7.x).
 */
function nvxStrStartsWith( string $haystack, string $needle ): bool {
	if ( '' === $needle ) {
		return true;
	}
	return 0 === strpos( $haystack, $needle );
}

/**
 * Whether a space-separated class attribute contains any of the given tokens.
 *
 * @param string   $class_attr Element class attribute.
 * @param string[] $tokens     Class tokens.
 */
function nvxClinicsClassHasAny( string $class_attr, array $tokens ): bool {
	if ( '' === trim( $class_attr ) || array() === $tokens ) {
		return false;
	}
	$classes = preg_split( NVX_REGEX_WHITESPACE, strtolower( trim( $class_attr ) ) ) ?: array();
	$lookup  = array_fill_keys( $classes, true );
	foreach ( $tokens as $token ) {
		if ( isset( $lookup[ strtolower( $token ) ] ) ) {
			return true;
		}
	}
	return false;
}

/**
 * Build a safe word-boundary class regex from a list of tokens (for rare string matches).
 *
 * @param string[] $tokens Class tokens.
 */
function nvxClinicsClassTokenRegex( array $tokens ): string {
	$escaped = array_map(
		static function ( string $token ): string {
			return preg_quote( $token, '/' );
		},
		$tokens
	);
	return '/\b(?:' . implode( '|', $escaped ) . ')\b/i';
}

/*
-------------------------------------------------------------------------
 * Page / map helpers
 * ---------------------------------------------------------------------- */

function nvxIsClinicsHub(): bool {
	if ( ! is_page() ) {
		return false;
	}

	return 'clinicas-de-medicina-estetica-nuvanx' === (string) get_post_field( 'post_name', get_queried_object_id() );
}

/**
 * Whether the current page uses the Sede Local template (hub + branch pages).
 */
function nvxIsSedeTemplate(): bool {
	if ( ! is_page() ) {
		return false;
	}

	$template = (string) get_page_template_slug();

	return in_array(
		$template,
		array(
			'templates/page-sede.php',
			'page-sede.php',
		),
		true
	);
}

function nvxClinicsMapUrl( string $clinic ): string {
	$query = 'goya' === $clinic
		? 'NUVANX Medicina Estética Láser Salamanca Goya Madrid'
		: 'NUVANX Medicina Estética Láser Chamberí Madrid';

	return 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode( $query );
}

function nvxClinicsNearestBlock( DOMNode $node ): ?DOMElement {
	$current = $node;
	while ( $current instanceof DOMNode && $current->parentNode ) {
		if ( $current instanceof DOMElement && in_array( strtolower( $current->tagName ), array( 'section', 'article' ), true ) ) {
			return $current;
		}
		$current = $current->parentNode;
	}
	return null;
}

/*
-------------------------------------------------------------------------
 * Layout pipeline steps
 * ---------------------------------------------------------------------- */

/** Promote first inner div to section inner. */
function nvxClinicsPromoteSectionInnerDiv( DOMElement $section, array $inner_ready ): void {
	foreach ( $section->childNodes as $child ) {
		if ( ! $child instanceof DOMElement || 'div' !== strtolower( $child->tagName ) ) {
			continue;
		}
		$child_class = trim( $child->getAttribute( 'class' ) );
		if ( nvxClinicsClassHasAny( $child_class, $inner_ready ) ) {
			break;
		}
		// Bare first div (no nvx-* class) → canonical section inner (global gutters).
		if ( '' === $child_class || ! preg_match( '/\bnvx-/', $child_class ) ) {
			$child->setAttribute( 'class', trim( $child_class . ' nvx-brand-section__inner' ) );
		}
		break;
	}
}

/**
 * Promote bare CMS <section>/<div> wrappers to global brand shells.
 */
function nvxClinicsPromoteBareSections( DOMXPath $xpath ): void {
	$sections = $xpath->query( '//section' );
	if ( false === $sections ) {
		return;
	}

	$skip_classes = nvxClinicsSectionSkipClasses();
	$inner_ready  = nvxClinicsSectionInnerReadyClasses();

	foreach ( $sections as $section ) {
		if ( ! $section instanceof DOMElement ) {
			continue;
		}

		$class = trim( $section->getAttribute( 'class' ) );
		if ( nvxClinicsClassHasAny( $class, $skip_classes ) ) {
			continue;
		}

		if ( '' === $class || ! nvxClinicsClassHasAny( $class, array( 'nvx-brand-section' ) ) ) {
			$section->setAttribute( 'class', trim( $class . ' nvx-brand-section' ) );
		}

		nvxClinicsPromoteSectionInnerDiv( $section, $inner_ready );
	}
}

/**
 * Normalizes a wrapper containing multiple page sections into a full-width content flow.
 *
 * @param DOMXPath $xpath The XPath instance used to locate layout wrappers.
 * @return DOMElement|null The first normalized wrapper, or null if none qualifies.
 */
function nvxClinicsNormalizeLayout( DOMXPath $xpath ): ?DOMElement {
	$readable = nvxClinicsReadableMeasureClasses();
	$flow     = nvxClinicsFlowClasses();
	// Match either readable measure or alternate flow class.
	$parts = array();
	foreach ( array_merge( $readable, $flow ) as $token ) {
		$parts[] = 'contains(concat(" ", normalize-space(@class), " "), " ' . $token . ' ")';
	}
	$nodes = $xpath->query( '//*[' . implode( ' or ', $parts ) . ']' );

	if ( false === $nodes ) {
		return null;
	}

	$layout_root = null;
	foreach ( iterator_to_array( $nodes ) as $node ) {
		if ( ! $node instanceof DOMElement ) {
			continue;
		}

		$structural_children = $xpath->query( './section|./article|.//section|.//article', $node );
		if ( false === $structural_children || $structural_children->length < 2 ) {
			continue;
		}

		$classes = preg_split( NVX_REGEX_WHITESPACE, trim( $node->getAttribute( 'class' ) ) ) ?: array();
		$classes = array_values(
			array_filter(
				$classes,
				static function ( string $class_name ) use ( $readable ): bool {
					return ! in_array( $class_name, $readable, true );
				}
			)
		);
		// Marker only — no exclusive CSS. Full-width stack of global sections.
		$classes[] = 'nvx-content-flow';
		$node->setAttribute( 'class', implode( ' ', array_unique( $classes ) ) );
		$layout_root ??= $node;
	}

	return $layout_root;
}

/** Check if div group qualifies for unwrapping. */
function nvxClinicsShouldUnwrapDivGroup( DOMElement $div, array $protected ): bool {
	$class = trim( $div->getAttribute( 'class' ) );
	if ( nvxClinicsClassHasAny( $class, $protected ) ) {
		return false;
	}

	$section_children = array();
	$element_children = 0;
	foreach ( $div->childNodes as $child ) {
		if ( ! $child instanceof DOMElement ) {
			continue;
		}
		++$element_children;
		if ( 'section' === strtolower( $child->tagName ) ) {
			$section_children[] = $child;
		}
	}

	// Need multiple sections (or aria-labelledby grouping of sections).
	$has_aria_group = $div->hasAttribute( 'aria-labelledby' );
	if ( count( $section_children ) < 2 && ! ( $has_aria_group && count( $section_children ) >= 1 ) ) {
		return false;
	}
	if ( count( $section_children ) < $element_children && ( ! $has_aria_group || count( $section_children ) !== $element_children ) ) {
		return false;
	}

	return true;
}

/**
 * Unwrap anonymous divs that only group sections (CMS residue).
 */
function nvxClinicsUnwrapSectionGroups( DOMXPath $xpath ): void {
	$divs = $xpath->query( '//div' );
	if ( false === $divs ) {
		return;
	}

	$protected = nvxClinicsUnwrapProtectedClasses();

	foreach ( iterator_to_array( $divs ) as $div ) {
		if ( ! $div instanceof DOMElement || ! $div->parentNode ) {
			continue;
		}

		if ( ! nvxClinicsShouldUnwrapDivGroup( $div, $protected ) ) {
			continue;
		}

		$parent = $div->parentNode;
		while ( $div->firstChild ) {
			$parent->insertBefore( $div->firstChild, $div );
		}
		$parent->removeChild( $div );
	}
}

/** Locate nearest brand-section ancestor. */
function nvxClinicsFindBrandSectionAncestor( DOMElement $flow ): ?DOMElement {
	$brand_section = null;
	$current       = $flow->parentNode;
	while ( $current instanceof DOMElement ) {
		$class = $current->getAttribute( 'class' );
		if ( nvxClinicsClassHasAny( $class, array( 'nvx-brand-page' ) ) ) {
			break;
		}
		if (
			nvxClinicsClassHasAny( $class, array( 'nvx-brand-section' ) )
			&& ! nvxClinicsClassHasAny( $class, array( 'nvx-brand-hero' ) )
		) {
			$brand_section = $current;
		}
		$current = $current->parentNode;
	}
	return $brand_section;
}

function nvxClinicsShouldHoistFlow( DOMElement $flow, DOMXPath $xpath ): ?DOMElement {
	$brand_section = null;

	if ( $flow->parentNode ) {
		$candidate = nvxClinicsFindBrandSectionAncestor( $flow );
		if ( $candidate instanceof DOMElement && $candidate->parentNode ) {
			$nested = $xpath->query( './/section', $flow );
			if ( false !== $nested && $nested->length > 0 ) {
				$brand_section = $candidate;
			}
		}
	}

	return $brand_section;
}

/**
 * Hoist multi-section stacks out of a single outer brand-section shell so each
 * block gets the same pad-section rhythm as Goya / Chamberí.
 *
 * @return DOMElement|null First hoisted element (for nav insertion).
 */
function nvxClinicsHoistSectionStack( DOMXPath $xpath ): ?DOMElement {
	$flow_tokens = nvxClinicsFlowClasses();
	$parts       = array();
	foreach ( $flow_tokens as $token ) {
		$parts[] = 'contains(concat(" ", normalize-space(@class), " "), " ' . $token . ' ")';
	}
	$flows = $xpath->query( '//*[' . implode( ' or ', $parts ) . ']' );

	if ( false === $flows ) {
		return null;
	}

	$first = null;

	foreach ( iterator_to_array( $flows ) as $flow ) {
		if ( ! $flow instanceof DOMElement ) {
			continue;
		}

		$brand_section = nvxClinicsShouldHoistFlow( $flow, $xpath );
		if ( ! $brand_section ) {
			continue;
		}

		$parent = $brand_section->parentNode;
		while ( $flow->firstChild ) {
			$child = $flow->firstChild;
			$parent->insertBefore( $child, $brand_section );
			if ( null === $first && $child instanceof DOMElement ) {
				$first = $child;
			}
		}

		// Drop empty wrapper chain (flow → optional inners → brand-section).
		$parent->removeChild( $brand_section );
	}

	return $first;
}

/**
 * Ordered layout pipeline for clinics hub CMS HTML.
 *
 * Sequence (do not reorder without checking hoist/unwrap assumptions):
 * 1. promote bare sections → brand-section shells
 * 2. normalize readable multi-section wrappers → content-flow
 * 3. unwrap anonymous section groups
 * 4. hoist flow children out of a single outer brand-section
 * 5. unwrap again (groups revealed by hoist)
 * 6. promote again (new bare sections after hoist)
 *
 * @return array{layout_root: ?DOMElement, hoisted: ?DOMElement}
 */
function nvxClinicsRunLayoutPipeline( DOMXPath $xpath ): array {
	nvxClinicsPromoteBareSections( $xpath );
	$layout_root = nvxClinicsNormalizeLayout( $xpath );
	nvxClinicsUnwrapSectionGroups( $xpath );
	$hoisted = nvxClinicsHoistSectionStack( $xpath );
	nvxClinicsUnwrapSectionGroups( $xpath );
	nvxClinicsPromoteBareSections( $xpath );

	return array(
		'layout_root' => $layout_root instanceof DOMElement ? $layout_root : null,
		'hoisted'     => $hoisted instanceof DOMElement ? $hoisted : null,
	);
}

function nvxClinicsSetLinkAttributes( DOMElement $link, string $clinic ): void {
	$name = 'goya' === $clinic ? 'NUVANX Salamanca–Goya' : 'NUVANX Chamberí';
	$link->setAttribute( 'href', nvxClinicsMapUrl( $clinic ) );
	$link->setAttribute( 'target', '_blank' );
	$link->setAttribute( 'rel', 'noopener noreferrer' );
	$link->setAttribute( 'aria-label', 'Abrir ' . $name . ' en Google Maps' );
	$link->nodeValue = 'Abrir en Google Maps';

	// Map = secondary action (not competing primary); keep non-button utilities.
	nvxClinicsSetBrandButton( $link, 'secondary', array( 'nvx-clinic-map-cta' ) );
}

/**
 * Removes button-related class tokens from a class string.
 *
 * @param string $class The space-separated class string to process.
 * @return string The class string without button-related tokens.
 */
function nvxClinicsClassWithoutButtonChrome( string $class ): string {
	$classes = preg_split( NVX_REGEX_WHITESPACE, trim( $class ) ) ?: array();
	$classes = array_values(
		array_filter(
			$classes,
			static function ( string $c ): bool {
				if ( '' === $c ) {
					return false;
				}
				return ! preg_match( '/^(nvx-brand-btn|nvx-btn)(--[\w-]+)?$/i', $c )
					&& 'nvx-clinic-map-cta' !== $c;
			}
		)
	);
	return implode( ' ', array_unique( $classes ) );
}

/**
 * Applies brand button classes while preserving unrelated class tokens.
 *
 * @param string[] $extra Additional class tokens to include.
 */
function nvxClinicsSetBrandButton( DOMElement $link, string $variant, array $extra = array() ): void {
	$kept   = nvxClinicsClassWithoutButtonChrome( $link->getAttribute( 'class' ) );
	$tokens = preg_split( NVX_REGEX_WHITESPACE, trim( $kept ) ) ?: array();
	$tokens = array_merge(
		$tokens,
		array( 'nvx-brand-btn', 'nvx-brand-btn--' . $variant ),
		$extra
	);
	$tokens = array_values( array_unique( array_filter( $tokens ) ) );
	$link->setAttribute( 'class', implode( ' ', $tokens ) );
}

/**
 * Removes button styling classes from a link while preserving other classes.
 *
 * @param DOMElement $link The link whose classes should be updated.
 * @param string     $replace_with Optional class to add after removing button styling classes.
 */
function nvxClinicsStripButtonClasses( DOMElement $link, string $replace_with = 'nvx-brand-inline-link' ): void {
	$kept   = nvxClinicsClassWithoutButtonChrome( $link->getAttribute( 'class' ) );
	$tokens = preg_split( NVX_REGEX_WHITESPACE, trim( $kept ) ) ?: array();
	if ( '' !== $replace_with && ! in_array( $replace_with, $tokens, true ) ) {
		$tokens[] = $replace_with;
	}
	$link->setAttribute( 'class', implode( ' ', array_unique( array_filter( $tokens ) ) ) );
}

/**
 * Phone / WhatsApp links (hosts + common labels). Demoted to inline text.
 */
function nvxClinicsIsPhoneOrWhatsappLink( string $href, string $text ): bool {
	if ( preg_match( '/^tel:/i', $href ) ) {
		return true;
	}
	// wa.me deep links + official WhatsApp web/api/chat hosts.
	if ( preg_match( '/(?:wa\.me\/|api\.whatsapp\.com|web\.whatsapp\.com|chat\.whatsapp\.com)/i', $href ) ) {
		return true;
	}
	// Labels: WhatsApp, Whats App, WApp.
	if ( preg_match( '/whats\s*app|wapp\b/iu', $text ) ) {
		return true;
	}
	return false;
}

/**
 * Normalizes clinic hub call-to-action links into appropriate primary, secondary, or inline-link styles.
 *
 * @param DOMDocument $dom The document containing the clinic hub markup.
 * @param DOMXPath    $xpath XPath evaluator for locating links within the document.
 */
function nvxClinicsLinkIsMapAction( string $href, string $text ): bool {
	return (bool) preg_match( '/(?:google\.com\/maps|maps\.app|google maps|abrir .+ maps)/iu', $href . ' ' . $text );
}

function nvxClinicsLinkIsSecondaryAction( string $href, string $text ): bool {
	return (bool) preg_match( '/equipo|ver todos|explorar|catálogo|catalogo/iu', $text . ' ' . $href );
}

function nvxClinicsLinkIsPrimaryAction( string $text ): bool {
	return (bool) preg_match( '/valoraci[oó]n|ver sede|reservar/iu', $text );
}

function nvxClinicsCardLinkNeedsDemotion( string $text, DOMElement $link ): bool {
	if ( preg_match( '/^(ver|reservar|solicitar|abrir)\b/iu', $text ) ) {
		return false;
	}
	return (bool) preg_match( '/\bnvx-brand-card\b/i', nvxClinicsAncestorClassBlob( $link ) );
}

/** Determine the presentation treatment for one clinic CTA. */
function nvxClinicsCtaTreatment( DOMElement $link, string $href, string $text, bool $isBtn ): string {
	$treatment = '';
	$parent    = $link->parentNode;

	if ( preg_match( '/^Solicitar\s*$/iu', $text ) ) {
		$treatment = 'solicitar';
	} elseif ( $parent instanceof DOMElement && in_array( strtolower( $parent->tagName ), array( 'h1', 'h2', 'h3', 'h4' ), true ) ) {
		$treatment = 'inline';
	} elseif ( nvxClinicsIsPhoneOrWhatsappLink( $href, $text ) ) {
		$treatment = 'inline';
	} elseif ( nvxClinicsLinkIsMapAction( $href, $text ) ) {
		$treatment = 'map';
	} elseif ( nvxClinicsLinkIsSecondaryAction( $href, $text ) ) {
		$treatment = 'secondary';
	} elseif ( $isBtn && nvxClinicsLinkIsPrimaryAction( $text ) ) {
		$treatment = 'primary';
	} elseif ( $isBtn && nvxClinicsCardLinkNeedsDemotion( $text, $link ) ) {
		$treatment = 'inline';
	}

	return $treatment;
}

/**
 * Classifies a CTA link and applies the corresponding brand styling and content.
 *
 * @param DOMElement $link The CTA link element to classify and update.
 * @param string     $href The link destination.
 * @param string     $text The visible link text.
 * @param string     $class The link's existing class attribute.
 */
function nvxClinicsClassifySingleCtaLink( DOMElement $link, string $href, string $text, string $class ): void {
	$isBtn     = (bool) preg_match( '/\b(nvx-brand-btn|nvx-btn)\b/i', $class );
	$treatment = nvxClinicsCtaTreatment( $link, $href, $text, $isBtn );

	switch ( $treatment ) {
		case 'solicitar':
			$link->nodeValue = 'Reservar valoración';
			nvxClinicsSetBrandButton( $link, 'primary' );
			if ( '' === $href || '#' === $href ) {
				$valoracion = function_exists( 'nvx_cta_valoracion_url' )
					? nvx_cta_valoracion_url()
					: home_url( '/madrid/valoracion/' );
				$link->setAttribute( 'href', $valoracion );
			}
			break;
		case 'inline':
			nvxClinicsStripButtonClasses( $link, 'nvx-brand-inline-link' );
			break;
		case 'map':
			nvxClinicsSetBrandButton( $link, 'secondary', array( 'nvx-clinic-map-cta' ) );
			break;
		case 'secondary':
			nvxClinicsSetBrandButton( $link, 'secondary' );
			break;
		case 'primary':
			nvxClinicsSetBrandButton( $link, 'primary' );
			break;
		default:
			// No treatment means the existing link presentation is already appropriate.
			break;
	}
}

/**
 * Normalizes clinic hub call-to-action links into appropriate primary, secondary, or inline-link styles.
 *
 * @param DOMDocument $dom The document containing the clinic hub markup.
 * @param DOMXPath    $xpath XPath evaluator for locating links within the document.
 */
function nvxClinicsNormalizeCtaHierarchy( DOMDocument $dom, DOMXPath $xpath ): void {
	$root = $dom->getElementById( 'nvx-clinics-document' );
	if ( ! $root ) {
		return;
	}

	foreach ( $xpath->query( './/a', $root ) ?: array() as $link ) {
		if ( ! $link instanceof DOMElement ) {
			continue;
		}

		$href  = $link->getAttribute( 'href' );
		$text  = trim( preg_replace( NVX_REGEX_WHITESPACE_U, ' ', $link->textContent ) ?? '' );
		$class = $link->getAttribute( 'class' );

		nvxClinicsClassifySingleCtaLink( $link, $href, $text, $class );
	}
}

/**
 * Concatenated class attributes of ancestors (for context checks).
 */
function nvxClinicsAncestorClassBlob( DOMNode $node ): string {
	$blob = '';
	$cur  = $node->parentNode;
	while ( $cur instanceof DOMElement ) {
		$blob .= ' ' . $cur->getAttribute( 'class' );
		$cur   = $cur->parentNode;
	}
	return $blob;
}

/*
-------------------------------------------------------------------------
 * Sede inline styles (narrow, class-guarded)
 * ---------------------------------------------------------------------- */

/** Filter out blocked inline style declarations. */
function nvxSedeFilterStyleDeclarations( string $style_v, array $blocked ): array {
	$decls = array_filter( array_map( 'trim', explode( ';', $style_v ) ) );
	$keep  = array();
	foreach ( $decls as $decl ) {
		if ( ! preg_match( '/^([a-z-]+)\s*:/i', $decl, $prop_m ) ) {
			$keep[] = $decl;
			continue;
		}
		$prop = strtolower( $prop_m[1] );
		if ( in_array( $prop, $blocked, true ) || nvxStrStartsWith( $prop, 'margin' ) || nvxStrStartsWith( $prop, 'padding' ) ) {
			continue;
		}
		$keep[] = $decl;
	}
	return $keep;
}

/** Rebuild a single opening tag after filtering its inline style declarations. */
function nvxSedeRebuildTagWithFilteredStyles( array $match, string $class_re, array $blocked ): string {
	$tag_original = $match[1];
	$open_mid     = $match[2];
	if ( ! preg_match( '/\bclass\s*=\s*(["\'])([^"\']*)\1/iu', $open_mid, $class_m ) || ! preg_match( $class_re, $class_m[2] ) ) {
		return $match[0];
	}

	$style_q = $match[3];
	$style_v = $match[4];
	$keep    = nvxSedeFilterStyleDeclarations( $style_v, $blocked );

	if ( array() === $keep ) {
		$new_mid = preg_replace( '/\sstyle=(["\'])([^"\']*)\1/iu', '', $open_mid, 1 ) ?? $open_mid;
		return '<' . $tag_original . $new_mid . '>';
	}

	$new_style = implode( '; ', $keep );
	$new_mid   = preg_replace( '/\sstyle=(["\'])([^"\']*)\1/iu', ' style=' . $style_q . $new_style . $style_q, $open_mid, 1 ) ?? $open_mid;

	return '<' . $tag_original . $new_mid . '>';
}

/**
 * Strip only spacing-related inline styles on known Sede wrapper classes.
 * Other properties (color, width, text-align, etc.) are left for editors.
 *
 * Only rewrites a fixed allow-list of non-void tags so self-closing markup
 * and unrelated elements are never rebuilt.
 */
function nvxSedeStripLayoutInlineStyles( string $content ): string {
	if ( is_admin() || ! nvxIsSedeTemplate() || '' === trim( $content ) ) {
		return $content;
	}

	$targets  = nvxSedeInlineStyleTargetClasses();
	$blocked  = nvxSedeBlockedInlineStyleProperties();
	$allowed  = nvxSedeInlineStyleAllowedTags();
	$class_re = nvxClinicsClassTokenRegex( $targets );
	$tag_alt  = implode(
		'|',
		array_map(
			static function ( string $tag ): string {
				return preg_quote( $tag, '/' );
			},
			$allowed
		)
	);
	// Opening tags only (no trailing /), allow-listed names, style + class required.
	$pattern = '/<(' . $tag_alt . ')\b(?![^>]*\/\s*>)([^>]*?\sstyle=(["\'])([^"\']*)\3[^>]*)>/iu';

	return preg_replace_callback(
		$pattern,
		static function ( array $match ) use ( $class_re, $blocked ): string {
			return nvxSedeRebuildTagWithFilteredStyles( $match, $class_re, $blocked );
		},
		$content
	) ?? $content;
}
add_filter( 'the_content', 'nvxSedeStripLayoutInlineStyles', NVX_HOOK_PRIO_SEDE_INLINE_STYLES );

function nvxClinicsBindLocationBlock( DOMXPath $xpath, DOMElement $heading, array $config ): ?DOMElement {
	$block = nvxClinicsNearestBlock( $heading );
	if ( ! $block ) {
		return null;
	}

	$article = $xpath->query( 'ancestor::article[contains(concat(" ", normalize-space(@class), " "), " nvx-brand-card ")][1]', $heading );
	if ( $article && $article->length && $article->item( 0 ) instanceof DOMElement ) {
		$block = $article->item( 0 );
	}

	$block->setAttribute( 'id', $config['id'] );
	$block->setAttribute( 'class', trim( $block->getAttribute( 'class' ) . ' nvx-clinic-location' ) );
	return $block;
}

/** Identify location block elements for clinics. */
function nvxClinicsIdentifyLocationBlocks( DOMXPath $xpath, array $clinics ): array {
	$blocks = array();
	foreach ( $xpath->query( '//h2|//h3|//h4' ) ?: array() as $heading ) {
		$text = trim( preg_replace( NVX_REGEX_WHITESPACE_U, ' ', $heading->textContent ) ?? $heading->textContent );
		foreach ( $clinics as $key => $config ) {
			if ( isset( $blocks[ $key ] ) || ! preg_match( $config['match'], $text ) ) {
				continue;
			}
			$block = nvxClinicsBindLocationBlock( $xpath, $heading, $config );
			if ( $block ) {
				$blocks[ $key ] = $block;
			}
		}
	}
	return $blocks;
}

/** Process map action links in location blocks. */
function nvxClinicsProcessMapActions( DOMDocument $dom, DOMXPath $xpath, array $blocks ): void {
	foreach ( $blocks as $key => $block ) {
		$links           = $xpath->query( './/a', $block );
		$map_action_seen = false;
		foreach ( $links ?: array() as $link ) {
			if ( ! $link instanceof DOMElement ) {
				continue;
			}
			$text          = trim( preg_replace( NVX_REGEX_WHITESPACE_U, ' ', $link->textContent ) ?? $link->textContent );
			$href          = $link->getAttribute( 'href' );
			$is_map_action = preg_match( '/(?:cómo llegar|como llegar|google maps|maps\.app|google\.[^\/]+\/maps)/iu', $text . ' ' . $href );
			if ( $is_map_action && ! $map_action_seen ) {
				nvxClinicsSetLinkAttributes( $link, $key );
				$map_action_seen = true;
			} elseif ( $is_map_action ) {
				$link->parentNode?->removeChild( $link );
			}
		}

		if ( ! $map_action_seen ) {
			$link = $dom->createElement( 'a', 'Abrir en Google Maps' );
			nvxClinicsSetLinkAttributes( $link, $key );
			$actions = $dom->createElement( 'div' );
			$actions->setAttribute( 'class', 'nvx-brand-actions nvx-clinic-location__actions' );
			$actions->appendChild( $link );
			$block->appendChild( $actions );
		}
	}
}

function nvxClinicsFindNavAnchorInPage( DOMElement $page ): ?DOMElement {
	foreach ( $page->childNodes as $child ) {
		if ( ! $child instanceof DOMElement ) {
			continue;
		}
		$c = $child->getAttribute( 'class' );
		if ( nvxClinicsClassHasAny( $c, array( 'nvx-brand-hero' ) ) ) {
			continue;
		}
		if (
			nvxClinicsClassHasAny( $c, array( 'nvx-brand-section', 'nvx-content-flow' ) )
			|| in_array( strtolower( $child->tagName ), array( 'section', 'nav' ), true )
		) {
			return $child;
		}
	}
	return null;
}

/** Resolve parent+before insertion point for clinic nav. */
function nvxClinicsNavInsertionPoint( DOMXPath $xpath, ?DOMElement $hoisted, ?DOMElement $layout_root ): array {
	$insert_parent = null;
	$insert_before = null;

	$page = $xpath->query( '//*[contains(concat(" ", normalize-space(@class), " "), " nvx-brand-page ")]' )->item( 0 );
	if ( $page instanceof DOMElement ) {
		$insert_parent = $page;
		$insert_before = nvxClinicsFindNavAnchorInPage( $page );
	} elseif ( $hoisted instanceof DOMElement && $hoisted->parentNode ) {
		$insert_parent = $hoisted->parentNode;
		$insert_before = $hoisted;
	} elseif ( $layout_root instanceof DOMElement ) {
		$insert_parent = $layout_root;
		$insert_before = $layout_root->firstChild instanceof DOMElement ? $layout_root->firstChild : null;
	}

	return array(
		'parent' => $insert_parent,
		'before' => $insert_before,
	);
}

/** Insert clinic nav element into document. */
function nvxClinicsInsertNavElement( DOMDocument $dom, DOMXPath $xpath, array $clinics, array $blocks, ?DOMElement $hoisted, ?DOMElement $layout_root ): void {
	if ( ! isset( $blocks['chamberi'], $blocks['goya'] ) || $dom->getElementById( 'nvx-clinics-nav' ) ) {
		return;
	}

	$nav = $dom->createElement( 'nav' );
	$nav->setAttribute( 'id', 'nvx-clinics-nav' );
	$nav->setAttribute( 'class', 'nvx-clinics-nav' );
	$nav->setAttribute( 'aria-label', 'Navegación entre las clínicas NUVANX en Madrid' );
	$inner = $dom->createElement( 'div' );
	$inner->setAttribute( 'class', 'nvx-shell nvx-clinics-nav__inner' );
	foreach ( $clinics as $config ) {
		$link = $dom->createElement( 'a', $config['label'] );
		$link->setAttribute( 'href', '#' . $config['id'] );
		$link->setAttribute( 'class', 'nvx-clinics-nav__link' );
		$inner->appendChild( $link );
	}
	$nav->appendChild( $inner );

	$point         = nvxClinicsNavInsertionPoint( $xpath, $hoisted, $layout_root );
	$insert_parent = $point['parent'];
	$insert_before = $point['before'];

	if ( $insert_parent instanceof DOMElement ) {
		if ( $insert_before instanceof DOMElement ) {
			$insert_parent->insertBefore( $nav, $insert_before );
		} else {
			$insert_parent->appendChild( $nav );
		}
	} else {
		$blocks['chamberi']->parentNode?->insertBefore( $nav, $blocks['chamberi'] );
	}
}

