<?php
/**
 * Canonical robots governance for the managed /gracias/ route.
 *
 * The publication manifest owns the route policy. When it explicitly declares
 * `/gracias/` as `noindex,follow`, this adapter removes the route from the
 * legacy nofollow bucket while retaining it in the noindex/navigable bucket.
 * If the manifest is missing, unreadable, invalid or changes policy, the
 * adapter fails closed and leaves the stronger legacy classification intact.
 *
 * @package NUVANX_Medical
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

/**
 * Whether the canonical publication manifest authorizes noindex,follow for
 * `/gracias/`.
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

	$route = $manifest['routes']['/gracias/'] ?? null;
	if (
		! is_array( $route )
		|| 'publish' !== (string) ( $route['status'] ?? '' )
		|| 'gracias' !== (string) ( $route['slug'] ?? '' )
		|| ! is_array( $route['robots'] ?? null )
	) {
		return false;
	}

	$authorized = false === ( $route['robots']['index'] ?? null )
		&& true === ( $route['robots']['follow'] ?? null );

	return $authorized;
}

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
 * Remove Gracias from the legacy nofollow class only when the manifest owns
 * `noindex,follow` for the route.
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
 * Keep Gracias explicitly noindex while restoring crawler link following.
 *
 * @param int[] $ids Page IDs currently classified noindex,follow.
 * @return int[]
 */
function nvx_gracias_robots_add_noindex_follow( array $ids ): array {
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
add_filter( 'nvx_noindex_but_navigable_page_ids', 'nvx_gracias_robots_add_noindex_follow', 20 );
