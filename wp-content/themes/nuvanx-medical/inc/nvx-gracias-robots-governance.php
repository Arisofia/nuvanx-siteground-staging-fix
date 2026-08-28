<?php
/**
 * Canonical robots governance for the managed /gracias/ route.
 *
 * The publication manifest requires /gracias/ to remain out of search results
 * while still allowing crawlers to follow its links (`noindex,follow`). The
 * legacy page-hygiene owner historically grouped the route into the stronger
 * `noindex,nofollow` class. These filters reconcile that runtime ownership
 * without weakening the route's noindex/sitemap exclusion.
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
 * Remove Gracias from the legacy nofollow class.
 *
 * @param int[] $ids Page IDs currently classified noindex,nofollow.
 * @return int[]
 */
function nvx_gracias_robots_remove_nofollow( array $ids ): array {
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
