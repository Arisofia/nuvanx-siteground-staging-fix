<?php
/**
 * Canonical robots governance for the managed /gracias/ route.
 *
 * The publication manifest owns the route policy. When it explicitly declares
 * `/gracias/` as `noindex,follow`, this adapter removes the route from the
 * legacy nofollow bucket while retaining it in the general noindex bucket.
 * It deliberately does not use the "noindex but navigable" bucket because the
 * transactional confirmation route must remain excluded from navigation menus.
 * If the manifest is missing, unreadable, invalid, changes policy, or no longer
 * identifies the same published WordPress page, the adapter fails closed and
 * leaves the stronger legacy classification intact.
 *
 * @package NUVANX_Medical
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

/**
 * Resolve the canonical Gracias page ID once per request.
 */
function nvx_gracias_robots_page_id(): int {
	static $page_id = null;

	if ( is_int( $page_id ) ) {
		return $page_id;
	}

	$page_id = function_exists( 'nvx_page_id_by_slug' )
		? (int) nvx_page_id_by_slug( 'gracias' )
		: 0;

	return $page_id;
}

/**
 * Normalize a URL/path to the publication-manifest route form.
 */
function nvx_gracias_robots_normalize_path( string $value ): string {
	$path = (string) wp_parse_url( $value, PHP_URL_PATH );
	if ( '' === $path ) {
		return '/';
	}

	return '/' . trim( $path, '/' ) . '/';
}

/**
 * Whether the canonical publication manifest authorizes noindex,follow for
 * the exact published WordPress page that currently owns `/gracias/`.
 */
function nvx_gracias_manifest_declares_noindex_follow(): bool {
	static $authorized = null;

	if ( is_bool( $authorized ) ) {
		return $authorized;
	}

	$authorized = false;
	$path       = __DIR__ . '/data/publication-manifest.json';
	if ( ! is_readable( $path ) ) {
		return false;
	}

	$raw = file_get_contents( $path );
	if ( false === $raw ) {
		return false;
	}

	$manifest = json_decode( $raw, true );
	if (
		! is_array( $manifest )
		|| 'nuvanx-publication-manifest' !== (string) ( $manifest['schema'] ?? '' )
		|| ! is_array( $manifest['routes'] ?? null )
	) {
		return false;
	}

	$route_key = '/gracias/';
	$route     = $manifest['routes'][ $route_key ] ?? null;
	if (
		! is_array( $route )
		|| 'publish' !== (string) ( $route['status'] ?? '' )
		|| 'gracias' !== (string) ( $route['slug'] ?? '' )
		|| 'page' !== (string) ( $route['post_type'] ?? '' )
		|| ! is_array( $route['robots'] ?? null )
		|| false !== ( $route['robots']['index'] ?? null )
		|| true !== ( $route['robots']['follow'] ?? null )
	) {
		return false;
	}

	$page_id          = nvx_gracias_robots_page_id();
	$manifest_post_id = (int) ( $route['post_id'] ?? 0 );
	if ( $page_id <= 0 || $manifest_post_id <= 0 || $manifest_post_id !== $page_id ) {
		return false;
	}

	$post = get_post( $page_id );
	if (
		! ( $post instanceof WP_Post )
		|| 'publish' !== (string) $post->post_status
		|| 'page' !== (string) $post->post_type
		|| 'gracias' !== (string) $post->post_name
	) {
		return false;
	}

	$permalink = get_permalink( $page_id );
	if ( ! is_string( $permalink ) || $route_key !== nvx_gracias_robots_normalize_path( $permalink ) ) {
		return false;
	}

	$authorized = true;
	return true;
}

/**
 * Remove Gracias from the legacy nofollow class only when the manifest owns
 * `noindex,follow` for the exact current route identity.
 *
 * @param int[] $ids Page IDs currently classified noindex,nofollow.
 * @return int[]
 */
function nvx_gracias_robots_remove_nofollow( array $ids ): array {
	if ( ! nvx_gracias_manifest_declares_noindex_follow() ) {
		return $ids;
	}

	$page_id = nvx_gracias_robots_page_id();
	if ( $page_id <= 0 ) {
		return $ids;
	}

	return array_values(
		array_filter(
			$ids,
			static function ( $id ) use ( $page_id ): bool {
				return (int) $id !== $page_id;
			}
		)
	);
}
add_filter( 'nvx_nofollow_page_ids', 'nvx_gracias_robots_remove_nofollow', 20 );

/**
 * Keep Gracias explicitly in the general noindex set while preserving its
 * exclusion from navigation menus.
 *
 * @param int[] $ids Page/post IDs currently forced to noindex.
 * @return int[]
 */
function nvx_gracias_robots_keep_noindex( array $ids ): array {
	if ( ! nvx_gracias_manifest_declares_noindex_follow() ) {
		return $ids;
	}

	$page_id = nvx_gracias_robots_page_id();
	if ( $page_id <= 0 ) {
		return $ids;
	}

	$normalized = array_values( array_unique( array_map( 'intval', $ids ) ) );
	if ( ! in_array( $page_id, $normalized, true ) ) {
		$normalized[] = $page_id;
	}

	return $normalized;
}
add_filter( 'nvx_noindex_page_ids', 'nvx_gracias_robots_keep_noindex', 20 );
