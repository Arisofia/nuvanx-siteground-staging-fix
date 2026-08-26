<?php
/**
 * Dump the canonical publication XML sitemap from the WordPress runtime
 * via WP-CLI. This provides a deterministic origin sitemap stream directly
 * to verify-publication-sitemap-from-xml.mjs in environments where local
 * HTTP loopback (localhost:80 / 127.0.0.1:443) is not routed to the webserver.
 *
 * @package nuvanx-medical
 */

if ( ! defined( 'ABSPATH' ) || ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	fwrite( STDERR, "DUMP_SITEMAP_XML=FAIL reason=wp_cli_required\n" );
	exit( 1 );
}

$theme_dir     = get_template_directory();
$manifest_path = $theme_dir . '/inc/data/publication-manifest.json';
$hygiene_path  = $theme_dir . '/inc/nvx-page-hygiene.php';

if ( ! is_readable( $manifest_path ) || ! is_readable( $hygiene_path ) ) {
	fwrite( STDERR, "DUMP_SITEMAP_XML=FAIL reason=manifest_unreadable\n" );
	exit( 1 );
}

require_once $hygiene_path;

$manifest = json_decode( (string) file_get_contents( $manifest_path ), true );
if ( ! is_array( $manifest ) || ! is_array( $manifest['routes'] ?? null ) ) {
	fwrite( STDERR, "DUMP_SITEMAP_XML=FAIL reason=manifest_invalid\n" );
	exit( 1 );
}

$front_page = (int) get_option( 'page_on_front' );
$posts_page = (int) get_option( 'page_for_posts' );

$excluded_ids = array();
if ( $front_page > 0 ) {
	$excluded_ids[] = $front_page;
}
if ( function_exists( 'apply_filters' ) ) {
	$excluded_ids = apply_filters( 'wpseo_exclude_from_sitemap_by_post_ids', $excluded_ids );
}
$excluded_ids = is_array( $excluded_ids ) ? array_map( 'intval', $excluded_ids ) : array();
if ( $posts_page > 0 ) {
	$excluded_ids[] = $posts_page;
}
$excluded_ids = array_values( array_unique( $excluded_ids ) );

$urls = array();

foreach ( $manifest['routes'] as $route => $config ) {
	if ( ! is_array( $config ) || 'publish' !== (string) ( $config['status'] ?? '' ) || true !== ( $config['robots']['index'] ?? null ) ) {
		continue;
	}

	$post_id = (int) ( $config['post_id'] ?? 0 );
	if ( in_array( $post_id, $excluded_ids, true ) && ! in_array( $post_id, array_filter( array( $front_page, $posts_page ) ), true ) ) {
		continue;
	}

	$post = $post_id > 0 ? get_post( $post_id ) : null;
	if ( $post instanceof WP_Post ) {
		$permalink = get_permalink( $post );
		if ( is_string( $permalink ) && '' !== $permalink ) {
			$urls[] = $permalink;
		}
	}
}

echo "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
echo "<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n";
foreach ( array_unique( $urls ) as $url ) {
	echo "\t<url><loc>" . esc_url( $url ) . "</loc></url>\n";
}
echo "</urlset>\n";
